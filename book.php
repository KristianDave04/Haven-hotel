<?php
session_start();

require_once __DIR__ . '/includes/payment_wallet_engine.php';
require_once __DIR__ . '/includes/room_inventory_engine.php';

// Database Configuration Parameters
$host     = "localhost";
$db_user  = "root";
$db_pass  = "";
$db_name  = "haven_hotel";
$conn = new mysqli($host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    die("Database Connection Error: " . $conn->connect_error);
}

// ============================================================
// SCHEMA SELF-HEALING: keeps book.php and admin_dashboard.php
// in sync on the floor column, no matter which page runs first.
// ============================================================
$floor_col_check = $conn->query("SHOW COLUMNS FROM rooms LIKE 'floor'");
if ($floor_col_check && $floor_col_check->num_rows === 0) {
    $conn->query("ALTER TABLE rooms ADD COLUMN floor VARCHAR(20) NOT NULL DEFAULT '1st Floor' AFTER room_number");
}

// NEW: payment + wallet schema self-healing (bookings payment columns,
// user_wallets, wallet_transactions) - see includes/payment_wallet_engine.php
ensure_payment_wallet_schema($conn);

// NEW: room-unit schema self-healing (room_units table, bookings.room_unit_id,
// plus first-run seeding of the 5 fixed floor/type rows and their physical
// units) - see includes/room_inventory_engine.php.
ensure_room_inventory_schema($conn);

// Redirect if the guest session profile is not established yet
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// NEW: sweep for any bookings that missed their 48h full-payment
// deadline and auto-cancel + refund them before rendering anything.
// There's no cron in this stack, so a load-time sweep on every guest-
// facing page (this one, dashboard.php) plus admin_dashboard.php is
// the only realistic trigger - see run_payment_deadline_sweep() for
// why this is cheap enough to run unconditionally on every load.
run_payment_deadline_sweep($conn);

// LIVE NOTIFICATIONS ENGINE PIPELINE
// Counts this guest's personal unread rows PLUS broadcast rows (user_id IS NULL,
// e.g. "new room added" alerts from admin_dashboard.php) that this guest's
// session hasn't marked as seen yet. Kept in sync with notifications_ajax.php.
$unread_notifications_count = 0;
if (isset($_SESSION['user_id'])) {
    if (!isset($_SESSION['seen_broadcast_ids']) || !is_array($_SESSION['seen_broadcast_ids'])) {
        $_SESSION['seen_broadcast_ids'] = [];
    }

    $u_id_notif = $_SESSION['user_id'];

    // Personal unread count - unchanged behavior.
    $notif_stmt = $conn->prepare("SELECT COUNT(*) as unread_count FROM notifications WHERE user_id = ? AND is_read = 0");
    $notif_stmt->bind_param("i", $u_id_notif);
    $notif_stmt->execute();
    $notif_res = $notif_stmt->get_result()->fetch_assoc();
    $personal_unread = (int)($notif_res['unread_count'] ?? 0);
    $notif_stmt->close();

    // Broadcast unread count - rows this session hasn't seen yet.
    $broadcast_unread = 0;
    $bc_stmt = $conn->prepare("SELECT id FROM notifications WHERE user_id IS NULL");
    $bc_stmt->execute();
    $bc_res = $bc_stmt->get_result();
    while ($bc_row = $bc_res->fetch_assoc()) {
        if (!in_array((int)$bc_row['id'], $_SESSION['seen_broadcast_ids'], true)) {
            $broadcast_unread++;
        }
    }
    $bc_stmt->close();

    $unread_notifications_count = $personal_unread + $broadcast_unread;
}

$u_id  = $_SESSION['user_id'];
$step  = isset($_GET['step']) ? (int)$_GET['step'] : 1;
if ($step < 1 || $step > 5) $step = 1;

// REDESIGNED 5-STEP FLOW GUARD: a guest can jump straight to a step's
// URL by hand (?step=4), so render-time guards fall back to the
// earliest step this session actually has the data for, rather than
// rendering a step with undefined session keys. This runs on every
// request (GET or POST) BEFORE anything below reads session state.
//
// FIX: these guards only apply to steps 2-4. Step 5 is a TERMINAL state
// reached right after a successful booking, at which point all
// booking_* session keys are deliberately unset (see the
// action_confirm_booking handler below) since Step 5 reads the
// confirmed booking back from the DATABASE via ?ref=... instead of
// session state. Without this upper bound, arriving at Step 5 looked
// exactly like "session data missing" to these guards and silently
// bounced every successful booking back to Step 1.
if ($step >= 2 && $step <= 4 && !isset($_SESSION['booking_room_id'])) $step = 1;
if ($step >= 3 && $step <= 4 && !isset($_SESSION['booking_unit_id'])) $step = 2;
if ($step >= 4 && $step <= 4 && (!isset($_SESSION['booking_check_in']) || !isset($_SESSION['booking_check_out']))) $step = 3;

$error_msg = "";

// ============================================================
// REDESIGNED ACCOMMODATION CATALOG: the 5 fixed floor/type rows,
// each carrying real physical-unit counts (via room_inventory_engine.php)
// instead of the old flat total_inventory-minus-active-bookings estimate.
// $rooms stays keyed by room_id for the same convenient lookup pattern
// the rest of this file already used ($rooms[$id]['name'], etc.).
// ============================================================
$floor_types_list = get_all_floor_types($conn);
$rooms = [];
foreach ($floor_types_list as $ft) {
    $rooms[$ft['room_id']] = [
        'id'          => $ft['room_id'],
        'name'        => $ft['room_type'],
        'floor'       => $ft['floor'],
        'floor_number'=> $ft['floor_number'],
        'price'       => (float)$ft['price_per_night'],
        'status'      => $ft['status'],
        'image'       => $ft['image_url'],
        'description' => $ft['description'],
        'unit_total'  => (int)$ft['unit_total'],
    ];
}

// Physical units under whichever accommodation the guest has chosen -
// this is what Step 2's cinema-style grid renders. Only needed once
// booking_room_id exists, so this stays empty (and cheap) on Step 1.
$units_for_selected_room = [];
if (isset($_SESSION['booking_room_id'])) {
    $units_for_selected_room = get_units_for_room($conn, $_SESSION['booking_room_id']);
}

// POST PROCESSING PIPELINE WORKFLOW MATRIX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Step 1: Select Accommodation
    if ($step === 1 && isset($_POST['room_id'])) {
        $selected_room_id = (int)$_POST['room_id'];
        if (isset($rooms[$selected_room_id]) && $rooms[$selected_room_id]['unit_total'] > 0 && $rooms[$selected_room_id]['status'] !== 'Not Available') {
            // If the guest picked a DIFFERENT accommodation type than
            // whatever was previously stored, any previously selected
            // physical unit (and any dates picked against it) belong to
            // the old type and are no longer valid - clear them so Step
            // 2 starts fresh on the new type's own unit grid.
            if (!isset($_SESSION['booking_room_id']) || $_SESSION['booking_room_id'] != $selected_room_id) {
                unset($_SESSION['booking_unit_id'], $_SESSION['booking_unit_number'], $_SESSION['booking_check_in'], $_SESSION['booking_check_out'], $_SESSION['booking_requests']);
            }
            $_SESSION['booking_room_id'] = $selected_room_id;
            header("Location: book.php?step=2");
            exit();
        } else {
            $error_msg = "We're sorry, but the selected suite category is currently sold out or unavailable. Please choose another accommodation.";
        }
    }

    // Step 2 (NEW): Select Rooms - cinema-style physical unit picker.
    // Occupied-today or Maintenance units are rejected server-side too,
    // not just disabled in the UI, since a guest could otherwise replay
    // an old form post for a unit that's since become occupied.
    if ($step === 2 && isset($_POST['unit_id'])) {
        $selected_unit_id = (int)$_POST['unit_id'];
        $matchedUnit = null;
        foreach ($units_for_selected_room as $u) {
            if ((int)$u['unit_id'] === $selected_unit_id) {
                $matchedUnit = $u;
                break;
            }
        }

        if ($matchedUnit && $matchedUnit['unit_status'] === 'Active' && !$matchedUnit['is_booked_today']) {
            $_SESSION['booking_unit_id'] = $matchedUnit['unit_id'];
            $_SESSION['booking_unit_number'] = $matchedUnit['unit_number'];
            header("Location: book.php?step=3");
            exit();
        } else {
            $error_msg = "That room is no longer available to select - it may have just been booked or placed under maintenance. Please pick another room below.";
            // Refresh the grid so the just-taken unit shows as occupied
            // immediately instead of the guest seeing stale free/green data.
            $units_for_selected_room = get_units_for_room($conn, $_SESSION['booking_room_id']);
        }
    }

    // Step 3 (was Stay Dates step): now validates against the SPECIFIC
    // physical unit chosen in Step 2, via is_unit_available_for_dates()
    // (room_inventory_engine.php) - the authoritative date-overlap check,
    // reusing the exact same overlap condition the old room_id-level
    // guard used, just keyed on room_unit_id. This is also what makes
    // "check-in and check-out on the same day, different rooms" work
    // automatically: the overlap check only ever compares bookings on
    // the SAME unit, so two guests choosing two different room numbers
    // never conflict regardless of how their date ranges overlap.
    if ($step === 3) {
        $check_in  = trim($_POST['check_in_date'] ?? '');
        $check_out = trim($_POST['check_out_date'] ?? '');
        $requests  = strip_tags(trim($_POST['special_requests'] ?? ''));

        if (!empty($check_in) && !empty($check_out) && ($check_out > $check_in)) {
            if ($check_in >= date('Y-m-d')) {
                $unit_id_to_check = $_SESSION['booking_unit_id'];

                if (is_unit_available_for_dates($conn, $unit_id_to_check, $check_in, $check_out)) {
                    $_SESSION['booking_check_in']   = $check_in;
                    $_SESSION['booking_check_out']  = $check_out;
                    $_SESSION['booking_requests']   = $requests;
                    header("Location: book.php?step=4");
                    exit();
                } else {
                    $error_msg = "Room " . htmlspecialchars($_SESSION['booking_unit_number']) . " is already reserved for part of these dates. Try different dates, or choose a different room.";
                }
            } else {
                $error_msg = "Invalid check-in date. Your arrival date cannot be set in the past.";
            }
        } else {
            $error_msg = "Please ensure your checkout date comes after your chosen check-in arrival date.";
        }
    }

    // Step 4 (was Review/Confirm step): Authorize Final Reservation
    if ($step === 4 && isset($_POST['action_confirm_booking'])) {
        if (!isset($_POST['accept_terms_checkbox'])) {
            $error_msg = "Please acknowledge and accept the terms of the Hotel Stay Agreement to finish your booking.";
        } elseif (!isset($_POST['accept_downpayment_terms_checkbox'])) {
            // NEW: separate acknowledgement specifically for the 50%
            // downpayment policy - distinct from the general Stay
            // Agreement checkbox above, since agreeing to house rules
            // isn't the same as agreeing to a non-refundable-by-default
            // payment schedule. Both must be checked independently.
            $error_msg = "Please acknowledge and accept the Downpayment & Payment Terms to finish your booking.";
        } else {
            $r_id      = $_SESSION['booking_room_id'] ?? 0;
            $unit_id   = $_SESSION['booking_unit_id'] ?? 0;
            $unit_num  = $_SESSION['booking_unit_number'] ?? '';
            $c_in      = $_SESSION['booking_check_in'] ?? '';
            $c_out     = $_SESSION['booking_check_out'] ?? '';
            $requests  = $_SESSION['booking_requests'] ?? '';

            // Re-check the specific unit right before writing the booking -
            // closes the gap between Step 3's check and this final submit
            // (e.g. two tabs, or someone else grabbing the same room in
            // between) rather than trusting a check that may now be stale.
            if ($r_id && $unit_id && !empty($c_in) && !empty($c_out) && is_unit_available_for_dates($conn, $unit_id, $c_in, $c_out)) {
                $days = (strtotime($c_out) - strtotime($c_in)) / 86400;
                $total_cost = $days * $rooms[$r_id]['price'];
                $downpayment_amount = round($total_cost * DOWNPAYMENT_PERCENT, 2);

                // NEW: GATE - the downpayment must be fully covered by
                // existing wallet balance before this booking is allowed
                // to proceed at all. A brand-new guest with ₱0 balance
                // must top up first (see the QR top-up flow on
                // dashboard.php) - there is no partial-cover/pay-the-rest-
                // later path for the downpayment itself.
                $current_wallet_balance = get_wallet_balance($conn, $u_id);

                if ($current_wallet_balance < $downpayment_amount) {
                    $shortfall = round($downpayment_amount - $current_wallet_balance, 2);
                    $error_msg = "Your wallet balance (₱" . number_format($current_wallet_balance, 2) . ") isn't enough to cover the ₱" . number_format($downpayment_amount, 2) . " downpayment for this stay. Top up at least ₱" . number_format($shortfall, 2) . " more from your Dashboard, then come back and confirm this booking.";
                } else {
                $ref_code   = "HVN-" . strtoupper(bin2hex(random_bytes(4)));
                $initial_status = "Pending";

                // NEW: 50% downpayment, collected immediately as part of
                // confirming this booking. payment_status starts
                // 'Downpayment Paid' right away (not a separate unpaid
                // state) since this system has no payment gateway to wait
                // on - "paying" the downpayment IS this booking step.
                $initial_payment_status = "Downpayment Paid";

                $stmt = $conn->prepare("INSERT INTO bookings (user_id, room_id, room_unit_id, room_type, check_in_date, check_out_date, total_price, booking_status, booking_reference, special_requests, downpayment_amount, downpayment_paid_at, payment_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
                $room_type_with_unit = $rooms[$r_id]['name'] . ' (Room ' . $unit_num . ')';
                $stmt->bind_param("iiisssdsssds", $u_id, $r_id, $unit_id, $room_type_with_unit, $c_in, $c_out, $total_cost, $initial_status, $ref_code, $requests, $downpayment_amount, $initial_payment_status);

                if ($stmt->execute()) {
                    $new_booking_id = $conn->insert_id;

                    // NEW: actually DEBIT the downpayment from wallet
                    // balance via apply_wallet_balance_to_amount() (the
                    // existing shared function for "spend wallet balance
                    // toward a payment"). Since the gate above already
                    // guaranteed current_wallet_balance >= downpayment_amount,
                    // this always applies the FULL amount here - remaining_due
                    // will be 0 - it's reused rather than re-implemented so
                    // the debit goes through the same choke point as every
                    // other wallet spend in this app.
                    apply_wallet_balance_to_amount(
                        $conn,
                        $u_id,
                        $downpayment_amount,
                        $new_booking_id,
                        $ref_code,
                        '50% downpayment for ' . $room_type_with_unit . ' (' . $days . ' nights)'
                    );

                    $notif_msg = "Your stay application with Reference Code " . $ref_code . " has been received successfully. Your 50% downpayment of ₱" . number_format($downpayment_amount, 2) . " is recorded - you have " . PAYMENT_GRACE_HOURS . " hours to pay the remaining balance from your Guest Dashboard.";
                    $notif_insert = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
                    $notif_insert->bind_param("is", $u_id, $notif_msg);
                    $notif_insert->execute();
                    $notif_insert->close();

                    unset($_SESSION['booking_room_id'], $_SESSION['booking_unit_id'], $_SESSION['booking_unit_number'], $_SESSION['booking_check_in'], $_SESSION['booking_check_out'], $_SESSION['booking_requests']);

                    header("Location: book.php?step=5&ref=" . $ref_code);
                    exit();
                } else {
                    $error_msg = "An unexpected error occurred while generating your invoice. Please try submitting again.";
                }
                $stmt->close();
                } // closes the "else" branch of the NEW wallet-balance gate added above
            } else {
                $error_msg = "That room was just booked for these dates by someone else. Please go back and choose a different room or different dates.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Book Your Luxury Stay | Haven Hotel</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <!-- Three.js: renders the 360 room viewer's equirectangular photos onto
         a sphere (see .viewer360-* below). Pinned to r128 rather than
         @latest so a future Three.js release can't silently change or
         break the sphere-mapping API this page's script relies on. -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js" defer></script>
    <link rel="stylesheet" href="ui/book.css">
    <style>
        /* Modernized UI Enhancements directly injected into view pipeline */
        body { background: #fafafa; font-family: 'Plus Jakarta Sans', sans-serif; color: #1e293b; }
        .nav-bar-frame { background: #0f172a; padding: 18px 45px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .flow-stepper-track { border-radius: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.02); background: #ffffff; border: 1px solid #f1f5f9; padding: 24px 40px; flex-wrap: wrap; row-gap: 14px; }

        .step-node { font-weight: 600; color: #94a3b8; display: flex; align-items: center; gap: 10px; transition: color 0.3s ease; font-size: 13.5px; }
        .step-node.node-active { color: #c69c4f; }
        .step-node.node-complete { color: #10b981; }
        .node-index { width: 26px; height: 26px; border-radius: 50%; background: #f1f5f9; display: inline-flex; justify-content: center; align-items: center; font-size: 12px; font-weight: 700; color: #64748b; transition: all 0.25s ease; flex-shrink: 0; }
        .node-active .node-index { background: #c69c4f; color: #ffffff; }
        .node-complete .node-index { background: #10b981; color: #ffffff; font-size: 10px; }
        .step-connector { flex: 1; height: 2px; background: #e2e8f0; margin: 0 4px; transition: background 0.3s ease; min-width: 16px; }
        .step-connector.connector-complete { background: #10b981; }

        .room-unit-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; overflow: hidden; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); cursor: pointer; position: relative; }
        .room-unit-card:hover { transform: translateY(-4px); box-shadow: 0 12px 20px -5px rgba(0,0,0,0.08); border-color: #cbd5e1; }
        .room-unit-card.card-selected { border: 2px solid #c69c4f !important; background: #fffdf9; }
        .room-card-image-box { position: relative; }
        .room-card-image-box img { transition: transform 0.5s ease; width: 100%; height: 220px; object-fit: cover; }
        .room-unit-card:hover .room-card-image-box img { transform: scale(1.03); }
        .room-card-floating-badge { position: absolute; top: 12px; left: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.15); }
        .room-card-select-check { position: absolute; top: 12px; right: 12px; width: 26px; height: 26px; border-radius: 50%; background: #c69c4f; color: white; display: flex; align-items: center; justify-content: center; font-size: 12px; opacity: 0; transform: scale(0.6); transition: all 0.2s ease; }
        .room-unit-card.card-selected .room-card-select-check { opacity: 1; transform: scale(1); }

        /* NEW: 360-view trigger button on each Step 1 card - separate
           click target from the card body so viewing the tour and
           selecting the room are two distinct actions on the same card. */
        .room-card-360-btn {
            position: absolute; bottom: 12px; right: 12px; z-index: 2;
            background: rgba(15,23,42,0.75); color: #fff; border: none;
            padding: 7px 13px; border-radius: 999px; font-size: 11px; font-weight: 700;
            display: flex; align-items: center; gap: 6px; cursor: pointer;
            backdrop-filter: blur(4px); transition: background 0.2s ease;
        }
        .room-card-360-btn:hover { background: rgba(198,156,79,0.95); }

        .badge-pill { padding: 6px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .pill-ok { background: #e6f4ea; color: #137333; }
        .pill-alert { background: #fef7e0; color: #b06000; }
        .pill-soldout { background: #fce8e6; color: #c5221f; }

        .disabled-inventory { opacity: 0.65; cursor: not-allowed; pointer-events: none; }
        .btn-gold { background: #c69c4f; color: #ffffff; font-weight: 600; border-radius: 8px; box-shadow: 0 4px 6px -1px rgba(198, 156, 79, 0.2); }
        .btn-gold:hover:not([disabled]) { background: #b58b40; }
        .btn-gold:disabled { background: #cbd5e1; color: #94a3b8; box-shadow: none; cursor: not-allowed; }

        .form-layout-panel { background: #ffffff; border-radius: 16px; border: 1px solid #e2e8f0; padding: 30px; }
        .error-banner-alert { animation: slideIn 0.3s ease-out; }
        @keyframes slideIn { from { transform: translateY(-10px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

        /* Floor filter chips - Step 1 room selection */
        .floor-filter-chip {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 18px; border-radius: 999px; border: 1px solid #e2e8f0;
            background: #ffffff; color: #64748b; font-size: 13px; font-weight: 600;
            cursor: pointer; transition: all 0.2s ease;
        }
        .floor-filter-chip:hover { border-color: #c69c4f; color: #0f172a; }
        .floor-filter-chip.active-floor-chip { background: #0f172a; border-color: #0f172a; color: #ffffff; }
        .floor-filter-chip.active-floor-chip i { color: #c69c4f; }

        /* ============================================================
           NEW: Step 2 - cinema-style physical room picker. "Seats" are
           room-number tiles in a screen-facing grid, color-coded free/
           occupied/maintenance exactly like a cinema seating chart.
           ============================================================ */
        .cinema-screen-bar {
            background: linear-gradient(180deg, #1e293b, #0f172a);
            color: #cbd5e1; text-align: center; padding: 10px;
            border-radius: 6px 6px 40px 40px / 6px 6px 14px 14px;
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 1.5px; margin-bottom: 34px; box-shadow: 0 8px 20px -8px rgba(15,23,42,0.4);
        }
        .cinema-seat-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(78px, 1fr));
            gap: 14px; margin-bottom: 28px;
        }
        .cinema-seat {
            aspect-ratio: 1; border-radius: 10px; border: 2px solid transparent;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: 4px; cursor: pointer; transition: all 0.18s ease; background: #f0fdf4;
            color: #15803d; font-weight: 700; position: relative;
        }
        .cinema-seat i { font-size: 15px; }
        .cinema-seat .seat-num { font-size: 13px; }
        .cinema-seat:hover:not(.seat-occupied):not(.seat-maintenance) { transform: translateY(-3px); box-shadow: 0 8px 14px -6px rgba(21,128,61,0.35); border-color: #bbf7d0; }
        .cinema-seat.seat-selected { background: #c69c4f; color: #fff; border-color: #b58b40; transform: translateY(-3px); box-shadow: 0 10px 16px -6px rgba(198,156,79,0.45); }
        .cinema-seat.seat-occupied { background: #fef2f2; color: #dc2626; cursor: not-allowed; opacity: 0.85; }
        .cinema-seat.seat-maintenance { background: #f1f5f9; color: #94a3b8; cursor: not-allowed; opacity: 0.7; }
        .cinema-legend { display: flex; gap: 22px; flex-wrap: wrap; margin-bottom: 30px; font-size: 12.5px; color: #64748b; }
        .cinema-legend span { display: flex; align-items: center; gap: 7px; }
        .cinema-legend i { font-size: 10px; }
        .cinema-legend .legend-free i { color: #15803d; }
        .cinema-legend .legend-occupied i { color: #dc2626; }
        .cinema-legend .legend-maintenance i { color: #94a3b8; }
        .cinema-legend .legend-selected i { color: #c69c4f; }

        /* ============================================================
           360 pan-viewer modal (Step 1). Renders a true equirectangular
           (2:1) room photo onto the inside of a sphere via Three.js, with
           the camera at the sphere's center - dragging orbits the camera,
           so guests can look in every direction (floor, ceiling, full
           360deg turn), not just slide left-right across a flat photo.
           Requires a genuine equirectangular capture per room; see the
           'panorama not ready' fallback in the JS below for rooms that
           only have a normal flat photo so far.
           ============================================================ */
        .viewer360-backdrop {
            display: none; position: fixed; inset: 0; background: rgba(9,13,23,0.88);
            z-index: 500; align-items: center; justify-content: center; padding: 20px;
        }
        .viewer360-backdrop.viewer-open { display: flex; }
        .viewer360-frame { width: 100%; max-width: 900px; }
        .viewer360-topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; color: #e2e8f0; }
        .viewer360-title { font-family: 'Playfair Display', serif; font-size: 18px; }
        .viewer360-title span { color: #c69c4f; font-size: 11px; font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; display: block; margin-bottom: 3px; }
        .viewer360-close { background: rgba(255,255,255,0.1); border: none; color: #fff; width: 34px; height: 34px; border-radius: 50%; cursor: pointer; font-size: 14px; }
        .viewer360-close:hover { background: rgba(255,255,255,0.2); }
        .viewer360-viewport {
            width: 100%; height: 460px; border-radius: 16px; overflow: hidden;
            position: relative; cursor: grab; background: #000; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.6);
            user-select: none;
        }
        .viewer360-viewport.is-dragging { cursor: grabbing; }
        .viewer360-viewport canvas { display: block; width: 100%; height: 100%; touch-action: none; }
        .viewer360-status {
            position: absolute; inset: 0; display: flex; flex-direction: column; gap: 10px;
            align-items: center; justify-content: center; color: #cbd5e1; font-size: 13px;
            font-weight: 600; text-align: center; padding: 0 30px; pointer-events: none;
        }
        .viewer360-status i { font-size: 22px; color: #c69c4f; }
        .viewer360-status.status-hidden { display: none; }
        .viewer360-hint {
            position: absolute; bottom: 16px; left: 50%; transform: translateX(-50%);
            background: rgba(15,23,42,0.7); color: #fff; padding: 8px 16px; border-radius: 999px;
            font-size: 11.5px; font-weight: 600; display: flex; align-items: center; gap: 8px;
            pointer-events: none; transition: opacity 0.3s ease;
        }
        .viewer360-hint i { color: #c69c4f; }


        /* Notification bell + dropdown panel */
        .notif-bell-wrap { position: relative; }
        .notif-bell-btn {
            background: none; border: none; color: #94a3b8; font-size: 16px;
            cursor: pointer; padding: 6px; position: relative; display: flex;
            align-items: center; transition: color 0.2s ease;
        }
        .notif-bell-btn:hover { color: #ffffff; }
        .notif-bell-badge {
            position: absolute; top: -2px; right: -4px; background: #ef4444; color: white;
            font-size: 10px; font-weight: 700; padding: 1px 5px; border-radius: 10px;
            line-height: 1.4; min-width: 16px; text-align: center;
        }

        .notif-dropdown-panel {
            position: absolute; top: calc(100% + 14px); right: -10px; width: 340px;
            max-height: 420px; background: #ffffff; border-radius: 12px;
            border: 1px solid #e2e8f0; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.15);
            display: flex; flex-direction: column; overflow: hidden;
            opacity: 0; visibility: hidden; transform: translateY(-8px);
            transition: opacity 0.18s ease, transform 0.18s ease, visibility 0.18s;
            z-index: 200;
        }
        .notif-dropdown-panel.panel-open {
            opacity: 1; visibility: visible; transform: translateY(0);
        }
        .notif-dropdown-header {
            padding: 14px 18px; font-size: 13px; font-weight: 700; color: #0f172a;
            border-bottom: 1px solid #f1f5f9; background: #f8fafc;
        }
        .notif-dropdown-list { overflow-y: auto; max-height: 360px; }
        .notif-dropdown-loading, .notif-dropdown-empty {
            padding: 30px 18px; text-align: center; color: #94a3b8; font-size: 13px;
        }
        .notif-item {
            padding: 14px 18px; border-bottom: 1px solid #f1f5f9; font-size: 13px;
            color: #334155; line-height: 1.5; display: flex; gap: 10px;
        }
        .notif-item:last-child { border-bottom: none; }
        .notif-item.notif-unread { background: #fffbeb; }
        .notif-item i { color: #c69c4f; margin-top: 2px; flex-shrink: 0; }
        .notif-item i.fa-triangle-exclamation { color: #d97706; }
        .notif-item i.fa-circle-plus { color: #10b981; }
        .notif-item-body { flex: 1; }
        .notif-item-time { font-size: 11px; color: #94a3b8; margin-top: 4px; display: block; }
    </style>
</head>
<body>

    <header class="nav-bar-frame">
        <a href="dashboard.php" class="nav-brand">Haven<span>Hotel</span></a>
        <div style="display:flex; align-items:center; gap:25px;">
            <div class="notif-bell-wrap">
                <button type="button" class="notif-bell-btn" id="notifBellBtn" aria-haspopup="true" aria-expanded="false" aria-label="Notifications">
                    <i class="fa-solid fa-bell"></i>
                    <?php if ($unread_notifications_count > 0): ?>
                        <span class="notif-bell-badge" id="notifBellBadge"><?= $unread_notifications_count ?></span>
                    <?php endif; ?>
                </button>

                <div class="notif-dropdown-panel" id="notifDropdownPanel" aria-hidden="true">
                    <div class="notif-dropdown-header">
                        <span>Notifications</span>
                    </div>
                    <div class="notif-dropdown-list" id="notifDropdownList">
                        <div class="notif-dropdown-loading">
                            <i class="fa-solid fa-spinner fa-spin"></i> Loading...
                        </div>
                    </div>
                </div>
            </div>
            <a href="dashboard.php" class="nav-exit-link"><i class="fa-solid fa-arrow-left-long"></i> Exit Booking</a>
        </div>
    </header>

    <main class="booking-flow-container">
        <div class="flow-stepper-track">
            <?php
                $stepLabels = [1 => 'Select Accommodation', 2 => 'Select Rooms', 3 => 'Stay Dates', 4 => 'Review Invoice', 5 => 'Confirmed'];
                foreach ($stepLabels as $sNum => $sLabel):
                    $nodeClass = '';
                    if ($step === $sNum) $nodeClass = 'node-active';
                    elseif ($step > $sNum) $nodeClass = 'node-complete';
            ?>
                <div class="step-node <?= $nodeClass ?>">
                    <span class="node-index"><?= $step > $sNum ? '<i class="fa-solid fa-check"></i>' : $sNum ?></span> <?= $sLabel ?>
                </div>
                <?php if ($sNum < 5): ?><div class="step-connector <?= $step > $sNum ? 'connector-complete' : '' ?>"></div><?php endif; ?>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($error_msg)): ?>
            <div class="error-banner-alert" style="background:#fef2f2; border-left:4px solid #ef4444; color:#991b1b; padding:16px; border-radius:12px; margin-bottom:30px; font-size:14px; display:flex; align-items:center; gap:12px; font-weight:500;">
                <i class="fa-solid fa-circle-exclamation" style="font-size:16px;"></i> <?= htmlspecialchars($error_msg) ?>
            </div>
        <?php endif; ?>

        <?php if ($step === 1): ?>
            <div style="margin-bottom:30px;">
                <h1 style="font-size:26px; font-weight:700; color:#0f172a; margin-bottom:6px; font-family:'Playfair Display', serif;">Choose Your Accommodation</h1>
                <p style="color:#64748b; font-size:14px;">Select from our premium selection of masterfully tailored luxury suites - click a card's 360° button for a virtual walkthrough.</p>
            </div>

            <form method="POST" id="step_one_form">
                <input type="hidden" name="room_id" id="selected_room_id_input" value="<?= $_SESSION['booking_room_id'] ?? '' ?>">

                <div class="floor-filter-row" style="display:flex; gap:10px; margin-bottom:22px; flex-wrap:wrap;">
                    <button type="button" class="floor-filter-chip active-floor-chip" data-floor="all" onclick="filterRoomsByFloor('all', this)"><i class="fa-solid fa-layer-group"></i> All Floors</button>
                    <?php foreach ($floor_types_list as $ft): ?>
                        <button type="button" class="floor-filter-chip" data-floor="<?= htmlspecialchars($ft['floor']) ?>" onclick="filterRoomsByFloor('<?= htmlspecialchars($ft['floor'], ENT_QUOTES) ?>', this)"><i class="fa-solid fa-building"></i> <?= htmlspecialchars($ft['floor']) ?></button>
                    <?php endforeach; ?>
                </div>

                <div class="room-grid-mesh" id="room_grid_mesh_target">
                    <?php foreach ($rooms as $id => $rm):
                        $isSel = (isset($_SESSION['booking_room_id']) && $_SESSION['booking_room_id'] == $id) ? 'card-selected' : '';
                        $isSoldOut = ($rm['unit_total'] <= 0);
                        $isDisabled = ($rm['status'] === 'Not Available' || $isSoldOut) ? 'disabled-inventory' : '';
                    ?>
                        <div class="room-unit-card <?= $isSel ?> <?= $isDisabled ?>" data-id="<?= $id ?>" data-floor="<?= htmlspecialchars($rm['floor']) ?>" onclick="selectRoomCard(this, '<?= $rm['status'] ?>', <?= $isSoldOut ? 'true' : 'false' ?>)">
                            <div class="room-card-image-box">
                                <img src="<?= htmlspecialchars($rm['image']) ?>" alt="<?= htmlspecialchars($rm['name']) ?>">
                                <span class="badge-pill room-card-floating-badge <?= $isSoldOut ? 'pill-soldout' : (($rm['status'] === 'Available') ? 'pill-ok' : 'pill-alert') ?>">
                                    <?= $isSoldOut ? 'Fully Booked' : htmlspecialchars($rm['status']) ?>
                                </span>
                                <div class="room-card-select-check"><i class="fa-solid fa-check"></i></div>
                                <button type="button" class="room-card-360-btn" onclick="event.stopPropagation(); open360Viewer('<?= htmlspecialchars($rm['image'], ENT_QUOTES) ?>', '<?= htmlspecialchars($rm['name'], ENT_QUOTES) ?>')">
                                    <i class="fa-solid fa-rotate"></i> 360° View
                                </button>
                            </div>
                            <div class="room-card-details-box" style="padding: 20px;">
                                <div style="margin-bottom:10px;">
                                    <h3 class="room-meta-title" style="font-size:18px; font-weight:700; color:#0f172a;"><?= htmlspecialchars($rm['name']) ?></h3>
                                </div>
                                <p style="color:#64748b; font-size:13px; margin-bottom:6px;"><i class="fa-solid fa-door-closed" style="color:#c6a973; width:14px;"></i> <?= (int)$rm['unit_total'] ?> Room<?= $rm['unit_total'] == 1 ? '' : 's' ?> on this floor</p>
                                <p style="color:#94a3b8; font-size:12px; margin-bottom:20px;"><i class="fa-solid fa-building" style="color:#c69c4f; width:14px;"></i> <?= htmlspecialchars($rm['floor']) ?></p>

                                <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid #f1f5f9; padding-top:15px;">
                                    <div>
                                        <span style="font-size:11px; color:#94a3b8; display:block; text-transform:uppercase; font-weight:600; letter-spacing:0.3px;">Nightly Rate</span>
                                        <strong style="font-size:20px; color:#0f172a; font-family:'Playfair Display', serif;">₱<?= number_format($rm['price'], 2) ?><span style="font-size:11px; color:#94a3b8; font-weight:500; font-family:'Plus Jakarta Sans', sans-serif;">/night</span></strong>
                                    </div>
                                    <span style="font-size:12px; color:#475569; background:#f8fafc; padding:6px 12px; border-radius:8px; border:1px solid #e2e8f0; font-weight:600; white-space:nowrap;">
                                        <?= $isSoldOut ? 'Sold Out' : 'View Rooms →' ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div id="no_rooms_on_floor_msg" style="display:none; text-align:center; padding:40px; background:white; border:1px solid #e2e8f0; border-radius:12px; color:#94a3b8; font-size:14px;">
                    <i class="fa-solid fa-door-closed" style="font-size:22px; display:block; margin-bottom:10px;"></i>
                    No accommodations are currently configured on this floor.
                </div>

                <div class="control-action-row" style="background: white; padding: 20px 30px; border-radius: 12px; border: 1px solid #e2e8f0; margin-top: 30px;">
                    <span style="color:#64748b; font-size:13px; font-weight: 500;"><i class="fa-solid fa-circle-info" style="color: #c69c4f;"></i> Select a suite card option above to unlock room selection.</span>
                    <button type="submit" class="btn-action btn-gold" id="step_one_submit_btn" disabled style="padding: 12px 28px;">Select Rooms <i class="fa-solid fa-arrow-right"></i></button>
                </div>
            </form>

        <?php elseif ($step === 2):
            $acc_name = $rooms[$_SESSION['booking_room_id']]['name'] ?? 'Selected Accommodation';
            $acc_floor = $rooms[$_SESSION['booking_room_id']]['floor'] ?? '';
        ?>
            <div style="margin-bottom:24px;">
                <h1 style="font-size:26px; font-weight:700; color:#0f172a; margin-bottom:6px; font-family:'Playfair Display', serif;">Select Your Room</h1>
                <p style="color:#64748b; font-size:14px;"><?= htmlspecialchars($acc_name) ?> · <?= htmlspecialchars($acc_floor) ?> - pick a specific room, just like choosing a seat.</p>
            </div>

            <form method="POST" id="step_two_form">
                <input type="hidden" name="unit_id" id="selected_unit_id_input" value="<?= $_SESSION['booking_unit_id'] ?? '' ?>">

                <div class="form-layout-panel">
                    <div class="cinema-screen-bar"><i class="fa-solid fa-door-open"></i> Corridor / Entrance This Side</div>

                    <div class="cinema-seat-grid">
                        <?php foreach ($units_for_selected_room as $unit):
                            $isUnitSel = (isset($_SESSION['booking_unit_id']) && $_SESSION['booking_unit_id'] == $unit['unit_id']);
                            $seatClass = 'seat-free';
                            if ($isUnitSel) $seatClass = 'seat-selected';
                            elseif ($unit['unit_status'] !== 'Active') $seatClass = 'seat-maintenance';
                            elseif ($unit['is_booked_today']) $seatClass = 'seat-occupied';
                            $isClickable = ($unit['unit_status'] === 'Active' && !$unit['is_booked_today']);
                        ?>
                            <div class="cinema-seat <?= $seatClass ?>"
                                 data-unit-id="<?= $unit['unit_id'] ?>"
                                 title="Room <?= htmlspecialchars($unit['unit_number']) ?><?= $unit['is_booked_today'] ? ' - occupied today' : ($unit['unit_status'] !== 'Active' ? ' - under maintenance' : ' - available') ?>"
                                 onclick="<?= $isClickable ? "selectUnitSeat(this)" : "" ?>">
                                <i class="fa-solid <?= $isUnitSel ? 'fa-check' : ($unit['is_booked_today'] ? 'fa-user' : ($unit['unit_status'] !== 'Active' ? 'fa-wrench' : 'fa-door-closed')) ?>"></i>
                                <span class="seat-num"><?= htmlspecialchars($unit['unit_number']) ?></span>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($units_for_selected_room)): ?>
                            <div style="grid-column:1/-1; text-align:center; padding:30px; color:#94a3b8; font-size:13.5px;">No physical rooms have been added to this floor yet. Please check back soon or choose another accommodation.</div>
                        <?php endif; ?>
                    </div>

                    <div class="cinema-legend">
                        <span class="legend-free"><i class="fa-solid fa-square"></i> Available</span>
                        <span class="legend-selected"><i class="fa-solid fa-square"></i> Your Selection</span>
                        <span class="legend-occupied"><i class="fa-solid fa-square"></i> Occupied Today</span>
                        <span class="legend-maintenance"><i class="fa-solid fa-square"></i> Under Maintenance</span>
                    </div>
                    <p style="font-size:11.5px; color:#94a3b8; margin-top:-14px;"><i class="fa-solid fa-circle-info"></i> "Occupied today" reflects right now - if your stay dates are further out, we'll do one more availability check on the next step.</p>
                </div>

                <div class="control-action-row" style="background: white; padding: 20px 30px; border-radius: 12px; border: 1px solid #e2e8f0; margin-top: 30px;">
                    <a href="book.php?step=1" class="btn-action btn-muted" style="border-radius:8px; padding:12px 24px;"><i class="fa-solid fa-arrow-left"></i> Change Accommodation</a>
                    <button type="submit" class="btn-action btn-gold" id="step_two_submit_btn" <?= empty($_SESSION['booking_unit_id']) ? 'disabled' : '' ?> style="padding: 12px 28px;">Choose Stay Dates <i class="fa-solid fa-arrow-right"></i></button>
                </div>
            </form>

        <?php elseif ($step === 3):
            $sel_room_id = $_SESSION['booking_room_id'] ?? null;
            $sel_room_price = ($sel_room_id && isset($rooms[$sel_room_id])) ? $rooms[$sel_room_id]['price'] : 0;
            $sel_room_name = ($sel_room_id && isset($rooms[$sel_room_id])) ? $rooms[$sel_room_id]['name'] : 'Selected Suite';
            $sel_unit_number = $_SESSION['booking_unit_number'] ?? '';
        ?>
            <div style="margin-bottom:30px;">
                <h1 style="font-size:26px; font-weight:700; color:#0f172a; margin-bottom:6px; font-family:'Playfair Display', serif;">Schedule Your Visit</h1>
                <p style="color:#64748b; font-size:14px;">Define arrival and departure arrangements for Room <?= htmlspecialchars($sel_unit_number) ?>.</p>
            </div>

            <form method="POST">
                <div style="display:grid; grid-template-columns:1.4fr 1fr; gap:30px; align-items:start;">
                    <div class="form-layout-panel">
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:24px; margin-bottom:24px;">
                            <div class="form-input-block">
                                <label style="font-weight:600; color:#475569; display:block; margin-bottom:8px;"><i class="fa-solid fa-calendar-check" style="color:#c69c4f;"></i> Check-In Date *</label>
                                <input type="date" id="check_in_date_input" name="check_in_date" value="<?= htmlspecialchars($_SESSION['booking_check_in'] ?? '') ?>" min="<?= date('Y-m-d') ?>" required style="border-radius:8px; padding:12px;" oninput="recalculateStayEstimate()">
                            </div>
                            <div class="form-input-block">
                                <label style="font-weight:600; color:#475569; display:block; margin-bottom:8px;"><i class="fa-solid fa-calendar-xmark" style="color:#c69c4f;"></i> Check-Out Date *</label>
                                <input type="date" id="check_out_date_input" name="check_out_date" value="<?= htmlspecialchars($_SESSION['booking_check_out'] ?? '') ?>" min="<?= date('Y-m-d', strtotime('+1 day')) ?>" required style="border-radius:8px; padding:12px;" oninput="recalculateStayEstimate()">
                            </div>
                        </div>
                        <p style="font-size:12px; color:#94a3b8; margin-top:-10px; margin-bottom:24px;"><i class="fa-solid fa-circle-info" style="color:#c69c4f;"></i> Checking in or out the same day another guest does is fine, as long as you're in different rooms.</p>
                        <div class="form-input-block">
                            <label style="font-weight:600; color:#475569; display:block; margin-bottom:8px;"><i class="fa-solid fa-signature" style="color:#c69c4f;"></i> Optional Special Requests & Preferences</label>
                            <textarea name="special_requests" rows="4" placeholder="Let us know about room adjustments, custom configurations, or dietary considerations..." style="border-radius:8px; padding:12px; font-family:inherit;"><?= htmlspecialchars($_SESSION['booking_requests'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <div class="cost-breakdown-card" style="background:#0f172a; color:white; padding:28px; border-radius:16px; box-shadow:0 20px 25px -5px rgba(15,23,42,0.15); position:sticky; top:20px;">
                        <h4 style="color:#c69c4f; font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:0.8px; margin-bottom:18px;">Live Stay Estimate</h4>
                        <p style="font-size:13px; color:#94a3b8; margin-bottom:16px;"><?= htmlspecialchars($sel_room_name) ?> · Room <?= htmlspecialchars($sel_unit_number) ?></p>
                        <div style="display:flex; justify-content:space-between; font-size:14px; color:#94a3b8; margin-bottom:10px;">
                            <span>Nightly Rate</span>
                            <span style="color:#f8fafc; font-weight:500;">₱<?= number_format($sel_room_price, 2) ?></span>
                        </div>
                        <div style="display:flex; justify-content:space-between; font-size:14px; color:#94a3b8; margin-bottom:20px; padding-bottom:20px; border-bottom:1px dashed #334155;">
                            <span>Nights Selected</span>
                            <span id="est_nights_display" style="color:#f8fafc; font-weight:500;">—</span>
                        </div>
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <span style="font-weight:500; font-size:14px; color:#94a3b8;">Est. Total</span>
                            <strong id="est_total_display" style="color:white; font-size:22px; font-family:'Playfair Display', serif;">₱0.00</strong>
                        </div>
                        <p id="est_hint_text" style="font-size:11.5px; color:#64748b; margin-top:14px; line-height:1.5;">Select both dates to see your estimated total.</p>
                    </div>
                </div>

                <div class="control-action-row" style="margin-top: 30px;">
                    <a href="book.php?step=2" class="btn-action btn-muted" style="border-radius:8px; padding:12px 24px;"><i class="fa-solid fa-arrow-left"></i> Choose A Different Room</a>
                    <button type="submit" class="btn-action btn-gold" style="padding:12px 28px;">Review Invoice <i class="fa-solid fa-arrow-right"></i></button>
                </div>
            </form>

            <script>
                const stayEstimateNightlyRate = <?= json_encode($sel_room_price) ?>;
                function recalculateStayEstimate() {
                    const inVal = document.getElementById('check_in_date_input').value;
                    const outVal = document.getElementById('check_out_date_input').value;
                    const nightsDisplay = document.getElementById('est_nights_display');
                    const totalDisplay = document.getElementById('est_total_display');
                    const hintText = document.getElementById('est_hint_text');

                    if (!inVal || !outVal) {
                        nightsDisplay.innerText = '—';
                        totalDisplay.innerText = '₱0.00';
                        hintText.style.display = 'block';
                        return;
                    }
                    const inDate = new Date(inVal + 'T00:00:00');
                    const outDate = new Date(outVal + 'T00:00:00');
                    const nights = Math.round((outDate - inDate) / 86400000);

                    if (nights <= 0) {
                        nightsDisplay.innerText = '—';
                        totalDisplay.innerText = '₱0.00';
                        hintText.innerText = 'Check-out must be after check-in.';
                        hintText.style.display = 'block';
                        return;
                    }
                    nightsDisplay.innerText = nights + (nights === 1 ? ' Night' : ' Nights');
                    totalDisplay.innerText = '₱' + (nights * stayEstimateNightlyRate).toLocaleString(undefined, {minimumFractionDigits: 2});
                    hintText.style.display = 'none';
                }
                document.addEventListener('DOMContentLoaded', recalculateStayEstimate);
            </script>

        <?php elseif ($step === 4):
            $target_id = $_SESSION['booking_room_id'];
            $target_unit_number = $_SESSION['booking_unit_number'] ?? '';
            $days = (strtotime($_SESSION['booking_check_out']) - strtotime($_SESSION['booking_check_in'])) / 86400;
            $gross_total = $days * $rooms[$target_id]['price'];
            $downpayment_preview = round($gross_total * DOWNPAYMENT_PERCENT, 2);
            $remaining_preview = round($gross_total - $downpayment_preview, 2);

            // NEW: wallet-balance-vs-downpayment check, shown proactively
            // here so the guest sees a shortfall BEFORE trying to submit,
            // rather than only finding out from the server-side gate in
            // the POST handler above after clicking Confirm.
            $current_wallet_balance_preview = get_wallet_balance($conn, $u_id);
            $wallet_covers_downpayment = ($current_wallet_balance_preview >= $downpayment_preview);
            $wallet_shortfall_preview = round($downpayment_preview - $current_wallet_balance_preview, 2);
        ?>
            <div style="margin-bottom:30px;">
                <h1 style="font-size:26px; font-weight:700; color:#0f172a; margin-bottom:6px; font-family:'Playfair Display', serif;">Verify Booking Summary</h1>
                <p style="color:#64748b; font-size:14px;">Carefully confirm your itinerary requirements prior to processing registration.</p>
            </div>

            <form method="POST">
                <input type="hidden" name="action_confirm_booking" value="1">
                <div class="invoice-summary-grid" style="display:grid; grid-template-columns:1.4fr 1fr; gap:30px; align-items:start;">
                    <div class="form-layout-panel">
                        <h4 style="font-size:16px; font-weight:700; color:#0f172a; margin-bottom:20px; padding-bottom:12px; border-bottom:1px solid #f1f5f9;">Reservation Information</h4>
                        <table style="width:100%; font-size:14px; border-collapse:collapse; color:#475569;">
                            <tr style="height:40px;"><td style="font-weight:500; color:#64748b;">Selected Suite:</td><td style="text-align:right; color:#0f172a; font-weight:700;"><?= htmlspecialchars($rooms[$target_id]['name']) ?> (Room #<?= htmlspecialchars($target_unit_number) ?>)</td></tr>
                            <tr style="height:40px;"><td style="font-weight:500; color:#64748b;">Arrival Date:</td><td style="text-align:right; color:#0f172a; font-weight:600;"><?= htmlspecialchars($_SESSION['booking_check_in']) ?></td></tr>
                            <tr style="height:40px;"><td style="font-weight:500; color:#64748b;">Departure Date:</td><td style="text-align:right; color:#0f172a; font-weight:600;"><?= htmlspecialchars($_SESSION['booking_check_out']) ?></td></tr>
                            <tr style="height:40px;"><td style="font-weight:500; color:#64748b;">Calculated Stay Duration:</td><td style="text-align:right; color:#c69c4f; font-weight:700;"><?= $days ?> Nights</td></tr>
                            <?php if(!empty($_SESSION['booking_requests'])): ?>
                                <tr><td colspan="2" style="padding-top:20px;"><span style="display:block; font-weight:600; margin-bottom:8px; color:#0f172a;">Appended Special Requests:</span><p style="background:#f8fafc; border:1px solid #e2e8f0; padding:14px; border-radius:8px; font-size:13px; color:#475569; font-style:italic; line-height:1.5;">"<?= htmlspecialchars($_SESSION['booking_requests']) ?>"</p></td></tr>
                            <?php endif; ?>
                        </table>

                        <!-- NEW: Downpayment & Payment Terms - separate acknowledgement
                             from the general Stay Agreement checkbox, since this covers
                             the payment schedule specifically (50% now, 50% within the
                             grace window, and the cancellation refund policy). -->
                        <div style="margin-top:24px; background:#fffbeb; border:1px solid #fde68a; border-radius:10px; padding:18px 20px;">
                            <h5 style="font-size:13px; font-weight:700; color:#92400e; margin-bottom:10px; display:flex; align-items:center; gap:8px;"><i class="fa-solid fa-circle-info"></i> Downpayment & Payment Terms</h5>
                            <ul style="font-size:12.5px; color:#78350f; line-height:1.7; padding-left:18px; margin-bottom:14px;">
                                <li>A <strong>50% downpayment</strong> (₱<?= number_format($downpayment_preview, 2) ?>) is deducted directly from your <strong>wallet balance</strong> to confirm this reservation - your balance must fully cover it, or the booking can't be confirmed yet.</li>
                                <li>The remaining <strong>50% balance</strong> (₱<?= number_format($remaining_preview, 2) ?>) must be paid within <strong><?= PAYMENT_GRACE_HOURS ?> hours</strong> of your downpayment, from your Guest Dashboard.</li>
                                <li>If the remaining balance isn't received within that window, this reservation will be <strong>automatically cancelled</strong>, and your downpayment refunded to your account wallet per our cancellation policy.</li>
                                <li>Cancelling this booking yourself refunds a percentage of the unused stay value (50-70%, depending on timing) to your account wallet, rather than the full amount.</li>
                            </ul>
                            <label style="display:flex; align-items:flex-start; gap:10px; font-size:12.5px; color:#78350f; cursor:pointer; font-weight:600;">
                                <input type="checkbox" id="accept_downpayment_terms_checkbox" name="accept_downpayment_terms_checkbox" style="margin-top:2px;" required>
                                <span>I understand and agree to the 50% downpayment schedule and cancellation refund policy described above.</span>
                            </label>
                        </div>
                    </div>

                    <div class="cost-breakdown-card" style="background:#0f172a; color:white; padding:30px; border-radius:16px; box-shadow:0 20px 25px -5px rgba(15,23,42,0.15);">
                        <h4 style="color:#c69c4f; font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:0.8px; margin-bottom:20px;">Billing Summary</h4>
                        <div style="display:flex; justify-content:space-between; font-size:14px; color:#94a3b8; margin-bottom:10px;">
                            <span>₱<?= number_format($rooms[$target_id]['price'], 2) ?> × <?= $days ?> Nights</span>
                            <span style="color:#f8fafc; font-weight:500;">₱<?= number_format($gross_total, 2) ?></span>
                        </div>
                        <div style="display:flex; justify-content:space-between; font-size:14px; color:#94a3b8; margin-bottom:20px; padding-bottom:20px; border-bottom:1px dashed #334155;">
                            <span>Surcharges & Local Tax Value</span>
                            <span style="color:#10b981; font-weight:600;">Complimentary</span>
                        </div>
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; padding-bottom:20px; border-bottom:1px dashed #334155;">
                            <span style="font-weight:500; font-size:14px; color:#94a3b8;">Est. Total Price</span>
                            <strong style="color:white; font-size:24px; font-family:'Playfair Display', serif;">₱<?= number_format($gross_total, 2) ?></strong>
                        </div>

                        <!-- NEW: 50/50 payment split breakdown -->
                        <div style="background:rgba(198,156,79,0.12); border:1px solid rgba(198,156,79,0.35); border-radius:10px; padding:16px; margin-bottom:20px;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                <span style="font-size:12.5px; color:#e9d5a1; font-weight:700;"><i class="fa-solid fa-wallet"></i> Due Now (50% Downpayment)</span>
                                <strong style="color:#c69c4f; font-size:19px; font-family:'Playfair Display', serif;">₱<?= number_format($downpayment_preview, 2) ?></strong>
                            </div>
                            <div style="display:flex; justify-content:space-between; align-items:center;">
                                <span style="font-size:11.5px; color:#94a3b8;">Remaining balance (due within <?= PAYMENT_GRACE_HOURS ?>h)</span>
                                <span style="font-size:13px; color:#cbd5e1; font-weight:600;">₱<?= number_format($remaining_preview, 2) ?></span>
                            </div>
                        </div>

                        <!-- NEW: wallet balance vs. downpayment gate. The
                             downpayment is paid FROM wallet balance, so this
                             makes the requirement visible before the guest
                             even tries to submit, rather than only after a
                             rejected POST. -->
                        <div style="background:<?= $wallet_covers_downpayment ? 'rgba(16,185,129,0.12)' : 'rgba(239,68,68,0.12)' ?>; border:1px solid <?= $wallet_covers_downpayment ? 'rgba(16,185,129,0.35)' : 'rgba(239,68,68,0.4)' ?>; border-radius:10px; padding:16px; margin-bottom:24px;">
                            <div style="display:flex; justify-content:space-between; align-items:center;">
                                <span style="font-size:12.5px; color:<?= $wallet_covers_downpayment ? '#6ee7b7' : '#fca5a5' ?>; font-weight:700;">
                                    <i class="fa-solid <?= $wallet_covers_downpayment ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>"></i>
                                    Your Wallet Balance
                                </span>
                                <strong style="color:#f8fafc; font-size:16px;">₱<?= number_format($current_wallet_balance_preview, 2) ?></strong>
                            </div>
                            <?php if (!$wallet_covers_downpayment): ?>
                                <p style="font-size:11.5px; color:#fca5a5; margin-top:8px; line-height:1.5;">
                                    You need <strong>₱<?= number_format($wallet_shortfall_preview, 2) ?> more</strong> to cover this downpayment. Top up your wallet from the Dashboard, then come back here to confirm.
                                </p>
                            <?php endif; ?>
                        </div>

                        <div style="background:#1e293b; padding:16px; border-radius:10px; border:1px solid #334155; margin-bottom:24px;">
                            <label style="display:flex; align-items:flex-start; gap:12px; font-size:12px; color:#94a3b8; cursor:pointer; line-height:1.5;">
                                <input type="checkbox" id="accept_terms_checkbox" name="accept_terms_checkbox" style="margin-top:3px;" required>
                                <span>I verify the selection variables are true and agree to follow all Haven Hotel stay conditions.</span>
                            </label>
                        </div>

                        <?php if ($wallet_covers_downpayment): ?>
                            <button type="submit" style="width:100%; justify-content:center; background:#c69c4f; color:#ffffff; padding:14px; border-radius:10px;" class="btn-action btn-gold">Confirm & Pay ₱<?= number_format($downpayment_preview, 2) ?> Downpayment</button>
                        <?php else: ?>
                            <button type="submit" disabled style="width:100%; justify-content:center; background:#475569; color:#94a3b8; padding:14px; border-radius:10px; cursor:not-allowed;" class="btn-action">
                                <i class="fa-solid fa-lock"></i> Top Up ₱<?= number_format($wallet_shortfall_preview, 2) ?> To Continue
                            </button>
                            <a href="dashboard.php" class="btn-action btn-muted" style="width:100%; justify-content:center; border-radius:8px; padding:12px 24px; margin-top:10px;"><i class="fa-solid fa-qrcode"></i> Go to Dashboard to Top Up</a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="control-action-row" style="margin-top:30px;">
                    <a href="book.php?step=3" class="btn-action btn-muted" style="border-radius:8px; padding:12px 24px;"><i class="fa-solid fa-arrow-left"></i> Change Schedule Dates</a>
                </div>
            </form>

        <?php elseif ($step === 5):
            // NEW: fetch the actual booking record (session data for this
            // booking was already unset once Step 4 succeeded) so this
            // screen can show REAL downpayment/remaining figures instead
            // of just echoing the reference code with no payment context.
            // Joins room_units for the specific room number too.
            $confirmed_ref = $_GET['ref'] ?? '';
            $confirmed_booking = null;
            $confirmed_unit_number = '';
            if (!empty($confirmed_ref)) {
                $cb_stmt = $conn->prepare("SELECT b.*, ru.unit_number FROM bookings b LEFT JOIN room_units ru ON b.room_unit_id = ru.unit_id WHERE b.booking_reference = ? AND b.user_id = ?");
                $cb_stmt->bind_param("si", $confirmed_ref, $u_id);
                $cb_stmt->execute();
                $confirmed_booking = $cb_stmt->get_result()->fetch_assoc();
                $cb_stmt->close();
                $confirmed_unit_number = $confirmed_booking['unit_number'] ?? '';
            }
            $confirmed_remaining = $confirmed_booking ? round((float)$confirmed_booking['total_price'] - (float)$confirmed_booking['downpayment_amount'], 2) : 0;
        ?>
            <div style="text-align:center; padding:40px 0; background:white; border:1px solid #e2e8f0; border-radius:16px; max-width:650px; margin: 0 auto; box-shadow:0 4px 6px -1px rgba(0,0,0,0.02);">
                <div style="width:75px; height:75px; background:#dcfce7; color:#10b981; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:36px; margin:0 auto 24px auto;">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <h2 style="font-size:26px; font-weight:700; color:#0f172a; margin-bottom:12px; font-family:'Playfair Display', serif;">Stay Application Registered!</h2>
                <p style="color:#64748b; font-size:15px; max-width:480px; margin:0 auto 20px auto; line-height:1.6;">
                    Your accommodation booking Reference Key code is <strong style="color:#0f172a; font-family:monospace; background:#f1f5f9; padding:3px 8px; border-radius:6px; font-size:15px; border:1px solid #e2e8f0;"><?= htmlspecialchars($confirmed_ref ?: 'N/A') ?></strong><?= $confirmed_unit_number ? ' for Room ' . htmlspecialchars($confirmed_unit_number) : '' ?>. The desk service team will process your confirmation schedule details shortly.
                </p>

                <?php if ($confirmed_booking): ?>
                <!-- NEW: downpayment paid / remaining balance summary -->
                <div style="max-width:420px; margin:0 auto 30px auto; background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px 24px; text-align:left;">
                    <div style="display:flex; justify-content:space-between; align-items:center; padding-bottom:12px; margin-bottom:12px; border-bottom:1px dashed #e2e8f0;">
                        <span style="font-size:12.5px; color:#16a34a; font-weight:700;"><i class="fa-solid fa-circle-check"></i> Downpayment Paid</span>
                        <strong style="color:#0f172a; font-size:17px;">₱<?= number_format((float)$confirmed_booking['downpayment_amount'], 2) ?></strong>
                    </div>
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <span style="font-size:12.5px; color:#d97706; font-weight:700;"><i class="fa-regular fa-clock"></i> Remaining Balance (due in <?= PAYMENT_GRACE_HOURS ?>h)</span>
                        <strong style="color:#0f172a; font-size:17px;">₱<?= number_format($confirmed_remaining, 2) ?></strong>
                    </div>
                </div>
                <?php endif; ?>

                <div style="display:flex; justify-content:center; gap:15px;">
                    <a href="dashboard.php" class="btn-action btn-muted" style="border-radius:8px; padding:12px 24px; font-weight:600;"><i class="fa-solid fa-house"></i> Return to Guest Dashboard</a>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <!-- 360 pan-viewer modal: true equirectangular sphere viewer, see
         .viewer360-* CSS above. -->
    <div class="viewer360-backdrop" id="viewer360Backdrop" onclick="if(event.target===this) close360Viewer()">
        <div class="viewer360-frame">
            <div class="viewer360-topbar">
                <div class="viewer360-title"><span>360° Virtual Tour</span><span id="viewer360RoomName" style="font-size:18px; text-transform:none; letter-spacing:normal; font-weight:600; color:#fff; display:block; font-family:'Playfair Display', serif;"></span></div>
                <button type="button" class="viewer360-close" onclick="close360Viewer()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="viewer360-viewport" id="viewer360Viewport">
                <div class="viewer360-status" id="viewer360Status"><i class="fa-solid fa-circle-notch fa-spin"></i><span id="viewer360StatusText">Loading panorama…</span></div>
                <div class="viewer360-hint" id="viewer360Hint"><i class="fa-solid fa-arrows-left-right"></i> Drag to look around</div>
            </div>
        </div>
    </div>


    <script>
    function selectRoomCard(cardElement, operationalStatus, isSoldOutFlag) {
        if (operationalStatus === 'Not Available' || isSoldOutFlag === true) {
            return;
        }
        document.querySelectorAll('.room-unit-card').forEach(el => el.classList.remove('card-selected'));
        cardElement.classList.add('card-selected');

        const targetId = cardElement.getAttribute('data-id');
        document.getElementById('selected_room_id_input').value = targetId;

        const nextButton = document.getElementById('step_one_submit_btn');
        if (nextButton) {
            nextButton.removeAttribute('disabled');
        }
    }

    function filterRoomsByFloor(floor, chipEl) {
        document.querySelectorAll('.floor-filter-chip').forEach(c => c.classList.remove('active-floor-chip'));
        chipEl.classList.add('active-floor-chip');

        const cards = document.querySelectorAll('#room_grid_mesh_target .room-unit-card');
        let visibleCount = 0;
        cards.forEach(card => {
            const cardFloor = card.getAttribute('data-floor');
            const matches = (floor === 'all' || cardFloor === floor);
            card.style.display = matches ? '' : 'none';
            if (matches) visibleCount++;
        });

        const emptyMsg = document.getElementById('no_rooms_on_floor_msg');
        const gridTarget = document.getElementById('room_grid_mesh_target');
        if (emptyMsg && gridTarget) {
            emptyMsg.style.display = (visibleCount === 0) ? 'block' : 'none';
            gridTarget.style.display = (visibleCount === 0) ? 'none' : '';
        }
    }

    document.addEventListener("DOMContentLoaded", function() {
        const selectedInput = document.getElementById('selected_room_id_input');
        if (selectedInput && selectedInput.value !== "") {
            const activeNode = document.querySelector(`.room-unit-card[data-id='${selectedInput.value}']`);
            if (activeNode && !activeNode.classList.contains('disabled-inventory')) {
                activeNode.classList.add('card-selected');
                const nextBtn = document.getElementById('step_one_submit_btn');
                if (nextBtn) nextBtn.removeAttribute('disabled');
            }
        }
    });

    // ---- NEW: Step 2 cinema-style seat selection ----
    function selectUnitSeat(seatEl) {
        document.querySelectorAll('.cinema-seat').forEach(s => {
            if (!s.classList.contains('seat-occupied') && !s.classList.contains('seat-maintenance')) {
                s.classList.remove('seat-selected');
                s.querySelector('i').className = 'fa-solid fa-door-closed';
            }
        });
        seatEl.classList.add('seat-selected');
        seatEl.querySelector('i').className = 'fa-solid fa-check';

        document.getElementById('selected_unit_id_input').value = seatEl.getAttribute('data-unit-id');
        const nextBtn = document.getElementById('step_two_submit_btn');
        if (nextBtn) nextBtn.removeAttribute('disabled');
    }

    // ---- 360 pan-viewer: true equirectangular sphere (Three.js) ----
    // Maps a 2:1 equirectangular room photo onto the inside of a sphere,
    // with the camera at the sphere's center, so dragging orbits the
    // camera through a full 360deg turn plus up/down (floor/ceiling) -
    // not just a left-right slide across a flat photo.
    (function () {
        const backdrop = document.getElementById('viewer360Backdrop');
        const viewport = document.getElementById('viewer360Viewport');
        const hint = document.getElementById('viewer360Hint');
        const roomNameEl = document.getElementById('viewer360RoomName');
        const statusEl = document.getElementById('viewer360Status');
        const statusTextEl = document.getElementById('viewer360StatusText');

        // Bumped each time open360Viewer() runs, so a slow-loading texture
        // from a room the guest already navigated away from can't finish
        // loading late and hijack the now-current viewer.
        let openToken = 0;

        // Three.js scene/camera/renderer/sphere are built ONCE (see
        // ensureSceneReady below, called lazily on first open) and reused
        // across every room - only the texture on the sphere's material
        // changes per open. Recreating the whole WebGL context per open
        // would be wasteful and risks leaking GPU resources.
        let scene, camera, renderer, sphereMesh, canvasEl;
        let sceneReady = false;

        let currentTexture = null; // disposed before loading the next, to free GPU memory
        let lon = 0, lat = 0;      // current look direction, degrees
        let isDragging = false;
        let dragStartX = 0, dragStartY = 0, dragStartLon = 0, dragStartLat = 0;
        let renderLoopRunning = false;

        function ensureSceneReady() {
            if (sceneReady) return true;
            if (typeof THREE === 'undefined') return false; // CDN script hasn't finished loading yet

            scene = new THREE.Scene();
            camera = new THREE.PerspectiveCamera(75, 1, 1, 1100);
            camera.position.set(0, 0, 0.1); // center of the sphere

            renderer = new THREE.WebGLRenderer({ antialias: true });
            canvasEl = renderer.domElement;
            viewport.insertBefore(canvasEl, statusEl); // canvas behind the status/hint overlays

            // Sphere the guest stands inside of. scale(-1,1,1) flips face
            // winding so the texture is visible from INSIDE the sphere
            // (a default sphere's faces point outward, invisible from a
            // camera placed at its own center).
            const geometry = new THREE.SphereGeometry(500, 60, 40);
            geometry.scale(-1, 1, 1);
            const material = new THREE.MeshBasicMaterial();
            sphereMesh = new THREE.Mesh(geometry, material);
            scene.add(sphereMesh);

            sceneReady = true;
            return true;
        }

        function resizeRendererToViewport() {
            if (!sceneReady) return;
            const w = viewport.clientWidth, h = viewport.clientHeight;
            if (w === 0 || h === 0) return;
            renderer.setSize(w, h);
            camera.aspect = w / h;
            camera.updateProjectionMatrix();
        }

        function startRenderLoop() {
            if (renderLoopRunning) return;
            renderLoopRunning = true;
            function frame() {
                if (!renderLoopRunning) return;
                const phi = THREE.MathUtils.degToRad(90 - lat);
                const theta = THREE.MathUtils.degToRad(lon);
                const target = new THREE.Vector3(
                    500 * Math.sin(phi) * Math.cos(theta),
                    500 * Math.cos(phi),
                    500 * Math.sin(phi) * Math.sin(theta)
                );
                camera.lookAt(target);
                renderer.render(scene, camera);
                requestAnimationFrame(frame);
            }
            requestAnimationFrame(frame);
        }

        function stopRenderLoop() {
            renderLoopRunning = false;
        }

        function showStatus(message, isSpinner) {
            statusTextEl.innerText = message;
            statusEl.querySelector('i').className = isSpinner ? 'fa-solid fa-circle-notch fa-spin' : 'fa-solid fa-triangle-exclamation';
            statusEl.classList.remove('status-hidden');
        }
        function hideStatus() {
            statusEl.classList.add('status-hidden');
        }

        window.open360Viewer = function (imageUrl, roomName) {
            const myToken = ++openToken;

            roomNameEl.innerText = roomName;
            backdrop.classList.add('viewer-open');
            hint.style.opacity = '1';
            hint.innerText = 'Drag to look around';
            lon = 0;
            lat = 0;

            if (!ensureSceneReady()) {
                // Three.js CDN script hasn't finished loading yet (defer
                // means it can still be in flight on a slow connection).
                // Poll briefly rather than failing outright - this only
                // matters in the first second or two after page load.
                showStatus('Preparing viewer…', true);
                let attempts = 0;
                const retry = setInterval(function () {
                    if (myToken !== openToken) { clearInterval(retry); return; }
                    attempts++;
                    if (ensureSceneReady()) {
                        clearInterval(retry);
                        loadPanorama(imageUrl, roomName, myToken);
                    } else if (attempts > 40) { // ~10s at 250ms
                        clearInterval(retry);
                        showStatus("Couldn't load the viewer. Please refresh and try again.", false);
                    }
                }, 250);
                return;
            }

            loadPanorama(imageUrl, roomName, myToken);
        };

        function loadPanorama(imageUrl, roomName, myToken) {
            showStatus('Loading panorama…', true);
            resizeRendererToViewport();

            const loader = new THREE.TextureLoader();
            loader.load(
                imageUrl,
                function (texture) {
                    if (myToken !== openToken) {
                        // Superseded by a newer open360Viewer() call while this
                        // was loading - discard rather than apply it.
                        texture.dispose();
                        return;
                    }

                    // A true equirectangular photo is 2:1 (width:height). A
                    // normal room photo run through this viewer would render
                    // warped rather than as a clean panorama, so check the
                    // actual decoded image dimensions before showing it -
                    // rooms that only have a flat photo so far get an honest
                    // message instead of a broken-looking sphere.
                    const w = texture.image.width, h = texture.image.height;
                    const ratio = w / h;
                    if (Math.abs(ratio - 2) > 0.15) {
                        texture.dispose();
                        // Diagnostic detail (actual pixel dimensions/ratio)
                        // goes to the console, not the guest-facing message -
                        // a guest booking a room doesn't need "equirectangular"
                        // jargon, but this makes it a 5-second DevTools check
                        // to tell "this room has no panorama yet" (default
                        // Unsplash seed image, e.g. 600x400) apart from "someone
                        // uploaded a real panorama but it's the wrong ratio"
                        // (e.g. 4000x1800 - close, but still outside tolerance).
                        console.warn(
                            `[360 viewer] "${roomName}" image is ${w}\u00d7${h} (ratio ${ratio.toFixed(2)}:1). ` +
                            `Equirectangular panoramas need ~2:1 (\u00b115%). URL: ${imageUrl}`
                        );
                        showStatus("This room's 360° panorama isn't ready yet.", false);
                        return;
                    }

                    if (currentTexture) currentTexture.dispose(); // free the previous room's GPU memory
                    currentTexture = texture;
                    texture.colorSpace = THREE.SRGBColorSpace || THREE.sRGBEncoding;
                    sphereMesh.material.map = texture;
                    sphereMesh.material.needsUpdate = true;

                    hideStatus();
                    resizeRendererToViewport();
                    startRenderLoop();
                },
                undefined,
                function () {
                    if (myToken !== openToken) return;
                    showStatus("Couldn't load this room's photo.", false);
                }
            );
        }

        window.close360Viewer = function () {
            openToken++; // invalidate any in-flight texture load for this viewer
            backdrop.classList.remove('viewer-open');
            stopRenderLoop();
        };

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && backdrop.classList.contains('viewer-open')) close360Viewer();
        });

        window.addEventListener('resize', resizeRendererToViewport);

        // ---- Drag-to-look-around: converts drag delta into yaw/pitch ----
        function dragStart(clientX, clientY) {
            isDragging = true;
            dragStartX = clientX;
            dragStartY = clientY;
            dragStartLon = lon;
            dragStartLat = lat;
            viewport.classList.add('is-dragging');
            hint.style.opacity = '0';
        }
        function dragMove(clientX, clientY) {
            if (!isDragging) return;
            lon = dragStartLon - (clientX - dragStartX) * 0.15;
            // Clamp pitch just short of the poles: looking exactly
            // straight up/down makes yaw numerically unstable right at
            // that point (same reason Street View-style viewers clamp).
            lat = Math.max(-85, Math.min(85, dragStartLat + (clientY - dragStartY) * 0.15));
        }
        function dragEnd() {
            isDragging = false;
            viewport.classList.remove('is-dragging');
        }

        viewport.addEventListener('mousedown', (e) => dragStart(e.clientX, e.clientY));
        window.addEventListener('mousemove', (e) => dragMove(e.clientX, e.clientY));
        window.addEventListener('mouseup', dragEnd);

        viewport.addEventListener('touchstart', (e) => dragStart(e.touches[0].clientX, e.touches[0].clientY), { passive: true });
        window.addEventListener('touchmove', (e) => { if (isDragging) dragMove(e.touches[0].clientX, e.touches[0].clientY); }, { passive: true });
        window.addEventListener('touchend', dragEnd);
    })();

    // ---- Notification bell dropdown ----
    (function () {
        const bellBtn = document.getElementById('notifBellBtn');
        const panel = document.getElementById('notifDropdownPanel');
        const list = document.getElementById('notifDropdownList');
        const badge = document.getElementById('notifBellBadge');
        if (!bellBtn || !panel || !list) return;

        let loaded = false;

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        function timeAgo(isoString) {
            if (!isoString) return '';
            const then = new Date(isoString.replace(' ', 'T'));
            if (isNaN(then.getTime())) return '';
            const diffMs = Date.now() - then.getTime();
            const mins = Math.floor(diffMs / 60000);
            if (mins < 1) return 'Just now';
            if (mins < 60) return mins + (mins === 1 ? ' minute ago' : ' minutes ago');
            const hours = Math.floor(mins / 60);
            if (hours < 24) return hours + (hours === 1 ? ' hour ago' : ' hours ago');
            const days = Math.floor(hours / 24);
            return days + (days === 1 ? ' day ago' : ' days ago');
        }

        function notificationIcon(n) {
            // Broadcasts are tagged is_broadcast=true by the server; the specific
            // icon within that category is inferred from the message wording set
            // in admin_dashboard.php's INSERT statements (add_room_unit_action /
            // edit_room_type_settings). If you change that wording, update this
            // too - it's a soft coupling, not a real "type" column.
            if (n.is_broadcast) {
                if (n.message.indexOf('now available') !== -1) return 'fa-circle-plus';
                if (n.message.indexOf('no longer available') !== -1 || n.message.indexOf('limited availability') !== -1) return 'fa-triangle-exclamation';
                return 'fa-bullhorn';
            }
            return 'fa-circle-info';
        }

        function renderNotifications(items) {
            if (!items || items.length === 0) {
                list.innerHTML = '<div class="notif-dropdown-empty"><i class="fa-regular fa-bell-slash" style="display:block; font-size:20px; margin-bottom:8px;"></i>No notifications yet.</div>';
                return;
            }
            list.innerHTML = items.map(function (n) {
                const unreadClass = n.is_read ? '' : ' notif-unread';
                const timeLabel = n.created_at ? '<span class="notif-item-time">' + escapeHtml(timeAgo(n.created_at)) + '</span>' : '';
                const icon = notificationIcon(n);
                return '<div class="notif-item' + unreadClass + '">' +
                    '<i class="fa-solid ' + icon + '"></i>' +
                    '<div class="notif-item-body">' + escapeHtml(n.message) + timeLabel + '</div>' +
                    '</div>';
            }).join('');
        }

        function openPanel() {
            panel.classList.add('panel-open');
            panel.setAttribute('aria-hidden', 'false');
            bellBtn.setAttribute('aria-expanded', 'true');

            // Clear the badge visually as soon as the guest opens the panel;
            // the server marks them read in the same request that fetches them.
            if (badge) badge.remove();

            if (!loaded) {
                fetch('notifications_ajax.php?action=fetch')
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (data.success) {
                            renderNotifications(data.notifications);
                            loaded = true;
                        } else {
                            list.innerHTML = '<div class="notif-dropdown-empty">Couldn\'t load notifications right now.</div>';
                        }
                    })
                    .catch(function () {
                        list.innerHTML = '<div class="notif-dropdown-empty">Couldn\'t load notifications right now.</div>';
                    });
            }
        }

        function closePanel() {
            panel.classList.remove('panel-open');
            panel.setAttribute('aria-hidden', 'true');
            bellBtn.setAttribute('aria-expanded', 'false');
        }

        bellBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            const isOpen = panel.classList.contains('panel-open');
            if (isOpen) {
                closePanel();
            } else {
                openPanel();
            }
        });

        document.addEventListener('click', function (e) {
            if (!panel.contains(e.target) && e.target !== bellBtn) {
                closePanel();
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closePanel();
        });
    })();
    </script>
</body>
</html>
<?php $conn->close(); ?>