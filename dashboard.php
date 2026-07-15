<?php
session_start();

require_once __DIR__ . '/includes/payment_wallet_engine.php';

// 1. STRICT USER AUTHENTICATION CONSTRAINT CHECK
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// 2. DATABASE CONFIGURATION CONNECTIONS
$host     = "localhost";
$db_user  = "root";
$db_pass  = "";
$db_name  = "haven_hotel";

$conn = new mysqli($host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    die("Direct Database Connection to User Dashboard Failed: " . $conn->connect_error);
}

$user_id   = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];

// ============================================================
// SCHEMA SELF-HEALING: cancellation_requests table.
// Holds admin-review cancellation requests as a queue distinct
// from the existing instant self-service cancel path below -
// this is "Request Cancellation Review", not "Cancel Now".
// Safe to run every request - only creates the table once.
// ============================================================
$conn->query("
    CREATE TABLE IF NOT EXISTS cancellation_requests (
        request_id INT AUTO_INCREMENT PRIMARY KEY,
        booking_id INT NOT NULL,
        user_id INT NOT NULL,
        booking_reference VARCHAR(64) NOT NULL,
        reason TEXT NULL,
        request_status VARCHAR(20) NOT NULL DEFAULT 'Pending',
        admin_note TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        resolved_at TIMESTAMP NULL DEFAULT NULL
    )
");

// NEW: payment + wallet schema self-healing (bookings payment columns,
// user_wallets, wallet_transactions) - see includes/payment_wallet_engine.php
ensure_payment_wallet_schema($conn);

// NEW: sweep for any bookings that missed their 48h full-payment
// deadline and auto-cancel + refund them before rendering anything -
// this guest may be looking straight at the booking that's about to
// get swept, so it must run before any of this page's own queries.
run_payment_deadline_sweep($conn);

// LIVE NOTIFICATIONS ENGINE PIPELINE
// Counts this guest's personal unread rows PLUS broadcast rows (user_id IS NULL,
// e.g. "new room added" / "room unavailable" alerts from admin_dashboard.php)
// that this guest's session hasn't marked as seen yet. Kept in sync with
// notifications_ajax.php and book.php.
$unread_notifications_count = 0;
if (!isset($_SESSION['seen_broadcast_ids']) || !is_array($_SESSION['seen_broadcast_ids'])) {
    $_SESSION['seen_broadcast_ids'] = [];
}
$notif_stmt = $conn->prepare("SELECT COUNT(*) as unread_count FROM notifications WHERE user_id = ? AND is_read = 0");
$notif_stmt->bind_param("i", $user_id);
$notif_stmt->execute();
$notif_res = $notif_stmt->get_result()->fetch_assoc();
$personal_unread = (int)($notif_res['unread_count'] ?? 0);
$notif_stmt->close();

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

// Cancellation policy: guests get a grace window that starts at booking
// and extends until 48 hours AFTER check-in has passed. This covers
// three cases in one window: cancelling well before check-in, cancelling
// close to check-in, and cancelling early/mid-stay (e.g. a guest who
// checked in but has an emergency on day 1 or 2). Only once the stay is
// more than CANCELLATION_LOCK_HOURS past check-in does self-service
// Cancel Now lock out - past that point, only "Request Cancellation
// Review" (human-approved, see further below) or the admin can act.
//
// NOTE: this replaces an earlier version of this guard that only
// checked hours BEFORE check-in and locked unconditionally the moment
// check-in passed - that didn't allow the post-check-in grace period
// this policy actually calls for, so guests cancelling shortly after
// arriving would have been incorrectly locked out immediately.
define('CANCELLATION_LOCK_HOURS', 48);

// ========================================================
// NATIVE BACKEND EXTENSION: PROCESS GUEST CANCELLATION REQUEST (INSTANT)
// This is the original "Cancel Now" self-service path. Left fully intact,
// with the refund calculation + wallet crediting now wired in below.
// ========================================================
$cancel_error = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_cancel_booking'])) {
    $target_reference = $_POST['target_booking_reference'];

    // Security Safeguard: Ensure this booking actually belongs to the logged-in session user
    $verify_stmt = $conn->prepare("SELECT booking_id, booking_status, room_id, check_in_date, check_out_date, total_price, created_at FROM bookings WHERE booking_reference = ? AND user_id = ?");
    $verify_stmt->bind_param("si", $target_reference, $user_id);
    $verify_stmt->execute();
    $verify_res = $verify_stmt->get_result();

    if ($verify_res && $verify_res->num_rows > 0) {
        $booking_data = $verify_res->fetch_assoc();
        $current_status = $booking_data['booking_status'];

        // Only allow cancellation if it's Pending or Confirmed
        if ($current_status === 'Pending' || $current_status === 'Confirmed') {

            // NEW: SAME-DAY GUARD - if this reservation was MADE on the
            // same calendar day as its check-in date, self-service
            // cancellation is blocked outright, regardless of how many
            // hours remain before/after check-in. This is a distinct
            // rule from the CANCELLATION_LOCK_HOURS window below: a
            // booking made today for a check-in three weeks from now is
            // NOT a same-day booking and is unaffected by this guard -
            // it only fires when created_at's calendar date and
            // check_in_date are literally the same day.
            $is_same_day_booking = (date('Y-m-d', strtotime($booking_data['created_at'])) === $booking_data['check_in_date']);

            if ($is_same_day_booking) {
                $cancel_error = "This reservation can't be cancelled online because it was booked for the same day as check-in. Please contact the front desk directly, or submit a Request Cancellation Review below for our team to review.";
            } else {

            // CUTOFF GUARD: block self-service cancellation once MORE THAN
            // CANCELLATION_LOCK_HOURS (48h) have elapsed since check-in.
            // Before check-in, or up to 48h into the stay, self-cancel
            // stays available - this is a single continuous window, not
            // a "before check-in only" cutoff.
            $hours_since_checkin = (time() - strtotime($booking_data['check_in_date'])) / 3600;

            if ($hours_since_checkin > CANCELLATION_LOCK_HOURS) {
                $cancel_error = "This reservation can no longer be cancelled online because it's been more than 48 hours since check-in. Please contact the front desk directly for assistance, or submit a Request Cancellation Review below for our team to review.";
            } else {
                // NEW: calculate the refund BEFORE flipping status, since
                // nights_used_as_of_now() needs the still-live check-in/
                // check-out dates to work out how much of the stay (if
                // any) has already been consumed.
                $nights_total = max(1, round((strtotime($booking_data['check_out_date']) - strtotime($booking_data['check_in_date'])) / 86400));
                $nights_used = nights_used_as_of_now($booking_data['check_in_date'], $booking_data['check_out_date']);
                $price_per_night = (float)$booking_data['total_price'] / $nights_total;
                $refund_calc = calculate_refund_amount($nights_total, $nights_used, $price_per_night);
                $refund_amount = $refund_calc['refund_amount'];

                // Step A: Update the booking status matrix to 'Cancelled',
                // recording the refund figures alongside it in one write.
                $cancel_stmt = $conn->prepare("UPDATE bookings SET booking_status = 'Cancelled', refund_amount = ?, refunded_at = NOW() WHERE booking_reference = ? AND user_id = ?");
                $cancel_stmt->bind_param("dsi", $refund_amount, $target_reference, $user_id);

                if ($cancel_stmt->execute()) {
                    // NOTE: room availability is computed LIVE in book.php via a
                    // subquery counting active (Pending/Confirmed) bookings against
                    // rooms.total_inventory. There is no stored "available_rooms"
                    // counter to increment - marking this row Cancelled above is
                    // the only state change needed for the room to reappear as
                    // available on the booking page.

                    // NEW: credit the refund to the guest's wallet as REAL
                    // spendable balance (this is genuine money coming back
                    // to them, unlike Downpayment/Full Payment which are
                    // history-only - see BALANCE-SAFETY RULE in
                    // payment_wallet_engine.php).
                    if ($refund_amount > 0) {
                        post_wallet_transaction(
                            $conn,
                            $user_id,
                            $refund_amount,
                            'Refund',
                            $booking_data['booking_id'],
                            $target_reference,
                            "Guest-cancelled: {$refund_calc['nights_unused']} unused night(s) at {$refund_calc['refund_percent']}%"
                        );
                    }

                    // Step B: Inject a historical log entry directly into the administration notifications queue
                    $system_alert_msg = "Guest System Alert: User ID #$user_id ($user_name) has cancelled reservation reference [ $target_reference ]. Refund of ₱" . number_format($refund_amount, 2) . " credited to guest wallet.";
                    $log_stmt = $conn->prepare("INSERT INTO notifications (message) VALUES (?)");
                    $log_stmt->bind_param("s", $system_alert_msg);
                    $log_stmt->execute();
                    $log_stmt->close();

                    $_SESSION['cancel_success'] = "Reservation $target_reference has been successfully cancelled. ₱" . number_format($refund_amount, 2) . " has been credited to your account wallet.";
                }
                $cancel_stmt->close();
            }
            } // closes the "else" branch of the NEW same-day guard added above
        }
    }
    $verify_stmt->close();

    if (!empty($cancel_error)) {
        $_SESSION['cancel_error'] = $cancel_error;
    }

    // Redirect cleanly to avoid form re-submission loops
    header("Location: dashboard.php");
    exit();
}

// ========================================================
// NEW: PROCESS "REQUEST CANCELLATION REVIEW" (ADMIN-APPROVAL PATH)
// Distinct from the instant Cancel Now above - this does NOT change
// booking_status. It files a request row for admin_dashboard.php's new
// "Cancellation Requests" panel to approve or deny. Available regardless
// of the CANCELLATION_LOCK_HOURS cutoff, since it's asking a human rather
// than acting unilaterally.
// ========================================================
$request_review_error = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_request_cancellation_review'])) {
    $target_reference = trim($_POST['target_booking_reference'] ?? '');
    $reason = strip_tags(trim($_POST['cancellation_reason'] ?? ''));

    $verify_stmt = $conn->prepare("SELECT booking_id, booking_status FROM bookings WHERE booking_reference = ? AND user_id = ?");
    $verify_stmt->bind_param("si", $target_reference, $user_id);
    $verify_stmt->execute();
    $verify_res = $verify_stmt->get_result();

    if ($verify_res && $verify_res->num_rows > 0) {
        $booking_data = $verify_res->fetch_assoc();

        if ($booking_data['booking_status'] === 'Confirmed' || $booking_data['booking_status'] === 'Pending') {

            // Prevent duplicate pending requests for the same booking
            $dupe_stmt = $conn->prepare("SELECT request_id FROM cancellation_requests WHERE booking_id = ? AND request_status = 'Pending'");
            $dupe_stmt->bind_param("i", $booking_data['booking_id']);
            $dupe_stmt->execute();
            $has_dupe = $dupe_stmt->get_result()->num_rows > 0;
            $dupe_stmt->close();

            if ($has_dupe) {
                $request_review_error = "A cancellation review request for this reservation is already pending admin response.";
            } else {
                $ins_stmt = $conn->prepare("INSERT INTO cancellation_requests (booking_id, user_id, booking_reference, reason) VALUES (?, ?, ?, ?)");
                $ins_stmt->bind_param("iiss", $booking_data['booking_id'], $user_id, $target_reference, $reason);
                if ($ins_stmt->execute()) {
                    $admin_alert_msg = "Guest $user_name has requested cancellation review for reservation [ $target_reference ].";
                    $log_stmt = $conn->prepare("INSERT INTO notifications (message) VALUES (?)");
                    $log_stmt->bind_param("s", $admin_alert_msg);
                    $log_stmt->execute();
                    $log_stmt->close();

                    $_SESSION['request_success'] = "Your cancellation review request for $target_reference has been submitted. Our team will respond shortly.";
                } else {
                    $request_review_error = "We couldn't submit your request right now. Please try again.";
                }
                $ins_stmt->close();
            }
        } else {
            $request_review_error = "This reservation is no longer eligible for a cancellation request.";
        }
    } else {
        $request_review_error = "Reservation not found.";
    }
    $verify_stmt->close();

    if (!empty($request_review_error)) {
        $_SESSION['request_error'] = $request_review_error;
    }

    header("Location: dashboard.php");
    exit();
}

// ========================================================
// NEW: PROCESS "RENAME ACCOUNT" (update first_name / last_name)
// Updates the REAL users table columns (not just a session string) so
// this stays consistent with how admin_dashboard.php already reads
// guest names via CONCAT(u.first_name, ' ', u.last_name) everywhere.
// Session's user_name is refreshed too, so this page's own greeting
// picks up the change immediately without requiring a re-login.
// ========================================================
$rename_error = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_rename_account'])) {
    $new_first = trim($_POST['new_first_name'] ?? '');
    $new_last  = trim($_POST['new_last_name'] ?? '');

    if ($new_first === '' || $new_last === '') {
        $rename_error = "Please provide both a first and last name.";
    } elseif (mb_strlen($new_first) > 100 || mb_strlen($new_last) > 100) {
        $rename_error = "Names must be under 100 characters.";
    } else {
        $rn_stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ? WHERE id = ?");
        $rn_stmt->bind_param("ssi", $new_first, $new_last, $user_id);
        if ($rn_stmt->execute()) {
            $_SESSION['user_name'] = $new_first . ' ' . $new_last;
            $_SESSION['rename_success'] = "Your name has been updated successfully.";
        } else {
            $rename_error = "We couldn't update your name right now. Please try again.";
        }
        $rn_stmt->close();
    }

    if (!empty($rename_error)) {
        $_SESSION['rename_error'] = $rename_error;
    }

    header("Location: dashboard.php");
    exit();
}

// ========================================================
// NEW: PROCESS "PAY REMAINING BALANCE" (full payment after downpayment)
// Only valid while payment_status = 'Downpayment Paid' - once this
// succeeds the booking is Fully Paid and drops out of the 48h auto-
// cancel sweep's target set entirely (see run_payment_deadline_sweep()
// in payment_wallet_engine.php, which only ever touches bookings still
// in 'Downpayment Paid').
// ========================================================
$pay_balance_error = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_pay_remaining_balance'])) {
    $target_reference = trim($_POST['target_booking_reference'] ?? '');

    $verify_stmt = $conn->prepare("SELECT booking_id, payment_status, total_price, downpayment_amount FROM bookings WHERE booking_reference = ? AND user_id = ?");
    $verify_stmt->bind_param("si", $target_reference, $user_id);
    $verify_stmt->execute();
    $verify_res = $verify_stmt->get_result();

    if ($verify_res && $verify_res->num_rows > 0) {
        $booking_data = $verify_res->fetch_assoc();

        if ($booking_data['payment_status'] === 'Downpayment Paid') {
            $remaining_due = round((float)$booking_data['total_price'] - (float)$booking_data['downpayment_amount'], 2);

            // NEW: let the guest apply any existing wallet balance (from
            // a prior refund) toward this remaining balance first, only
            // recording the leftover as the "Full Payment" history entry.
            $wallet_result = apply_wallet_balance_to_amount($conn, $user_id, $remaining_due, $booking_data['booking_id'], $target_reference, 'remaining balance on ' . $target_reference);

            $pay_stmt = $conn->prepare("UPDATE bookings SET payment_status = 'Fully Paid', full_payment_paid_at = NOW() WHERE booking_reference = ? AND user_id = ?");
            $pay_stmt->bind_param("si", $target_reference, $user_id);
            if ($pay_stmt->execute()) {
                // History-only entry for the portion NOT covered by wallet
                // (see BALANCE-SAFETY RULE - Full Payment never itself
                // moves spendable balance; apply_wallet_balance_to_amount()
                // above already handled the real balance movement, if any).
                if ($wallet_result['remaining_due'] > 0) {
                    post_wallet_transaction(
                        $conn, $user_id, $wallet_result['remaining_due'], 'Full Payment',
                        $booking_data['booking_id'], $target_reference,
                        'Remaining balance payment' . ($wallet_result['applied'] > 0 ? ' (after ₱' . number_format($wallet_result['applied'], 2) . ' wallet credit applied)' : '')
                    );
                }

                $walletNote = $wallet_result['applied'] > 0 ? " (₱" . number_format($wallet_result['applied'], 2) . " covered by your wallet balance)" : "";
                $_SESSION['cancel_success'] = "Remaining balance for $target_reference has been paid in full.$walletNote Your reservation is now fully confirmed.";
            } else {
                $pay_balance_error = "We couldn't process this payment right now. Please try again.";
            }
            $pay_stmt->close();
        } else {
            $pay_balance_error = "This reservation isn't awaiting a remaining balance payment.";
        }
    } else {
        $pay_balance_error = "Reservation not found.";
    }
    $verify_stmt->close();

    if (!empty($pay_balance_error)) {
        $_SESSION['cancel_error'] = $pay_balance_error;
    }

    header("Location: dashboard.php");
    exit();
}

// Pull fresh user profiling metrics (Note: membership_tier column handled safely)
$user_stmt = $conn->prepare("SELECT created_at, first_name, last_name FROM users WHERE id = ?");
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_profile = $user_stmt->get_result()->fetch_assoc();
$user_stmt->close();

// NEW: wallet balance + recent transaction history for the Account
// Wallet card. Kept to the most recent 15 entries so the card doesn't
// grow unbounded for guests with a long booking history.
$wallet_balance = get_wallet_balance($conn, $user_id);
$wallet_history = [];
$wh_stmt = $conn->prepare("SELECT * FROM wallet_transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 15");
$wh_stmt->bind_param("i", $user_id);
$wh_stmt->execute();
$wh_res = $wh_stmt->get_result();
while ($wh_row = $wh_res->fetch_assoc()) {
    $wallet_history[] = $wh_row;
}
$wh_stmt->close();

// Wallet top-up link.
//
// SECURITY FIX: this used to append ?uid=<this account's id> directly
// to the URL, and wallet_topup.html trusted that number completely - a
// guest could edit it in the address bar and top up (or manipulate) a
// different account's wallet entirely. wallet_topup.php now identifies
// the account from the PHP session instead (the same $_SESSION['user_id']
// this very page requires to render at all), so there is nothing left
// in the URL for a guest to tamper with, and no uid parameter is needed.
//
// Still detects http vs https so the "Proceed to Top-Up" button works
// on a plain local dev server as well as a real deployment.
$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
$topup_protocol = $is_https ? "https://" : "http://";
$topup_page_url = $topup_protocol . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . "/wallet_topup.php";
// NOTE: the modal no longer shows a QR code (replaced with a rules/
// disclaimer + checkbox gate below), so $topup_page_url now only feeds
// the "Proceed to Top-Up" button's window.open() call directly.

// FETCH ALL LOGGED RESERVATIONS FOR THIS PARTICULAR USER BOUND
$bookings_list = [];
$booking_stmt = $conn->prepare("
    SELECT b.*, r.image_url 
    FROM bookings b 
    LEFT JOIN rooms r ON b.room_id = r.room_id 
    WHERE b.user_id = ? 
    ORDER BY b.created_at DESC
");
$booking_stmt->bind_param("i", $user_id);
$booking_stmt->execute();
$booking_result = $booking_stmt->get_result();

// Pull this user's cancellation review requests, keyed by booking_id, so the
// ledger can show "Review Requested" / "Approved" / "Denied" per booking
// instead of just re-showing the Cancel Now / Request Review buttons forever.
$my_cancel_requests_by_booking = [];
$creq_stmt = $conn->prepare("SELECT booking_id, request_status, admin_note, created_at FROM cancellation_requests WHERE user_id = ? ORDER BY created_at DESC");
$creq_stmt->bind_param("i", $user_id);
$creq_stmt->execute();
$creq_res = $creq_stmt->get_result();
while ($cr = $creq_res->fetch_assoc()) {
    // Keep only the most recent request per booking (query is already DESC)
    if (!isset($my_cancel_requests_by_booking[$cr['booking_id']])) {
        $my_cancel_requests_by_booking[$cr['booking_id']] = $cr;
    }
}
$creq_stmt->close();

$count_pending   = 0;
$count_confirmed = 0;
$count_cancelled = 0;

while ($row = $booking_result->fetch_assoc()) {
    // Precompute, server-side, whether this booking is still within the
    // self-service cancellation window so the front-end doesn't need to
    // duplicate date-math logic (and can't be tricked by a stale clock).
    // Mirrors the backend POST guard above EXACTLY: unlocked any time
    // before check-in, AND for up to CANCELLATION_LOCK_HOURS (48h) after
    // check-in has passed (covers early/mid-stay emergency cancellations)
    // - only locks once more than 48h has elapsed since check-in.
    //
    // NEW: also mirrors the SAME-DAY GUARD above - a reservation booked
    // on the same calendar day as its own check-in date is locked
    // regardless of the 48h window, since that's a separate rule keyed
    // on created_at vs. check_in_date, not on hours-since-checkin.
    $hrs_since_checkin = (time() - strtotime($row['check_in_date'])) / 3600;
    $is_same_day_booking_row = (date('Y-m-d', strtotime($row['created_at'])) === $row['check_in_date']);
    $row['is_same_day_booking'] = $is_same_day_booking_row;
    $row['can_self_cancel'] = (!$is_same_day_booking_row) && ($hrs_since_checkin <= CANCELLATION_LOCK_HOURS);

    // Attach this booking's active/most-recent cancellation review request, if any.
    $row['cancel_request'] = $my_cancel_requests_by_booking[$row['booking_id']] ?? null;

    $bookings_list[] = $row;
    if ($row['booking_status'] === 'Pending')   $count_pending++;
    if ($row['booking_status'] === 'Confirmed') $count_confirmed++;
    if ($row['booking_status'] === 'Cancelled') $count_cancelled++;
}
$booking_stmt->close();
$conn->close();

// NEW: use the REAL first_name/last_name from the users table (already
// fetched into $user_profile above) instead of splitting the combined
// session string - this is what the rename feature actually updates,
// so the greeting must read from the same source of truth.
$first_name = $user_profile['first_name'] ?? $user_name;
$last_name  = $user_profile['last_name'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Haven Hotel - Guest Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="ui/dashboard.css">
</head>
<body>

    <header class="navbar">
        <div class="logo">Haven<span>Hotel</span></div>
    <nav>
        <ul class="nav-links">
            <li><a href="index.php">Home</a></li>
            <li><a href="about.php" class="active">About</a></li>
            <li><a href="index.php #rooms">Accommodations</a></li>
            <li><a href="index.php #booking">Booking</a></li>
            <li><a href="index.php #overview">Overview</a></li>
            <li><a href="index.php #contact">Contact</a></li>
        </ul>
    </nav>
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
        <a href="login.php" class="logout-btn"><i class="fa-solid fa-right-from-bracket" style="margin-right:6px;"></i> Logout</a>
    </header>

    <section class="dashboard-hero">
        <div class="hero-welcome">
            <h1 id="heroGreetingDisplay">
                Hello, <?= htmlspecialchars(strtoupper($first_name)); ?>
                <button type="button" id="openRenameBtn" class="hero-rename-trigger" title="Edit your name" aria-label="Edit your name">
                    <i class="fa-solid fa-pen"></i>
                </button>
            </h1>
            <p>Manage your luxury stay requests, track reservations, and view confirmation slips.</p>

            <!-- NEW: inline rename form, hidden by default, toggled by the pencil icon above -->
            <form method="POST" id="renameForm" class="hero-rename-form" style="display:none;">
                <input type="hidden" name="action_rename_account" value="1">
                <input type="text" name="new_first_name" placeholder="First name" value="<?= htmlspecialchars($first_name) ?>" maxlength="100" required>
                <input type="text" name="new_last_name" placeholder="Last name" value="<?= htmlspecialchars($last_name) ?>" maxlength="100" required>
                <button type="submit" class="hero-rename-save"><i class="fa-solid fa-check"></i> Save</button>
                <button type="button" id="cancelRenameBtn" class="hero-rename-cancel"><i class="fa-solid fa-xmark"></i></button>
            </form>
        </div>
        <div class="user-tier-badge">
            <span>Account Profile Status</span>
            <strong>Active Guest</strong>
        </div>
    </section>

    <main class="main-container">
        
        <?php if (isset($_SESSION['rename_success'])): ?>
            <div class="dash-alert dash-alert-success">
                <i class="fa-solid fa-circle-check"></i>
                <span><?= htmlspecialchars($_SESSION['rename_success']); unset($_SESSION['rename_success']); ?></span>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['rename_error'])): ?>
            <div class="dash-alert dash-alert-warning">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <span><?= htmlspecialchars($_SESSION['rename_error']); unset($_SESSION['rename_error']); ?></span>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['cancel_success'])): ?>
            <div class="dash-alert dash-alert-success">
                <i class="fa-solid fa-circle-check"></i>
                <span><?= htmlspecialchars($_SESSION['cancel_success']); unset($_SESSION['cancel_success']); ?></span>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['cancel_error'])): ?>
            <div class="dash-alert dash-alert-warning">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <span><?= htmlspecialchars($_SESSION['cancel_error']); unset($_SESSION['cancel_error']); ?></span>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['request_success'])): ?>
            <div class="dash-alert dash-alert-success">
                <i class="fa-solid fa-paper-plane"></i>
                <span><?= htmlspecialchars($_SESSION['request_success']); unset($_SESSION['request_success']); ?></span>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['request_error'])): ?>
            <div class="dash-alert dash-alert-warning">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <span><?= htmlspecialchars($_SESSION['request_error']); unset($_SESSION['request_error']); ?></span>
            </div>
        <?php endif; ?>
        
        <div class="metrics-grid">
            <button type="button" class="metric-card metric-card--primary" onclick="filterLedgerTarget('All')">
                <div class="metric-icon icon-all"><i class="fa-solid fa-list-check"></i></div>
                <div class="metric-data">
                    <span>Total Requests</span>
                    <h2><?= count($bookings_list) ?></h2>
                </div>
            </button>
            <button type="button" class="metric-card" onclick="filterLedgerTarget('Pending')">
                <div class="metric-icon icon-pending"><i class="fa-regular fa-clock"></i></div>
                <div class="metric-data">
                    <span>Pending Approval</span>
                    <h2><?= $count_pending ?></h2>
                </div>
            </button>
            <button type="button" class="metric-card" onclick="filterLedgerTarget('Confirmed')">
                <div class="metric-icon icon-confirmed"><i class="fa-regular fa-calendar-check"></i></div>
                <div class="metric-data">
                    <span>Confirmed Stays</span>
                    <h2><?= $count_confirmed ?></h2>
                </div>
            </button>
            <button type="button" class="metric-card" onclick="filterLedgerTarget('Cancelled')">
                <div class="metric-icon icon-cancelled"><i class="fa-regular fa-circle-xmark"></i></div>
                <div class="metric-data">
                    <span>Cancelled Requests</span>
                    <h2><?= $count_cancelled ?></h2>
                </div>
            </button>
        </div>

        <!-- NEW: Account Wallet section -->
        <div class="wallet-section">
            <div class="wallet-balance-card">
                <div class="wallet-balance-header">
                    <div class="wallet-balance-icon"><i class="fa-solid fa-wallet"></i></div>
                    <div>
                        <span class="wallet-balance-label">Account Wallet Balance</span>
                        <h2 class="wallet-balance-amount">₱<?= number_format($wallet_balance, 2) ?></h2>
                    </div>
                    <button type="button" class="btn-add-money" onclick="openTopupModal()"><i class="fa-solid fa-qrcode"></i> Add Money</button>
                </div>
                <p class="wallet-balance-note">Credited from cancellation refunds. Automatically applied toward your next downpayment or remaining balance payment.</p>
            </div>

            <?php
                // Bookings currently awaiting their remaining balance -
                // surfaced here so the guest doesn't have to hunt through
                // the full ledger below to find what needs action.
                $awaiting_balance = array_filter($bookings_list, fn($b) => $b['payment_status'] === 'Downpayment Paid' && $b['booking_status'] !== 'Cancelled');
            ?>
            <?php if (!empty($awaiting_balance)): ?>
            <div class="wallet-pending-payments">
                <h3><i class="fa-regular fa-clock"></i> Awaiting Remaining Balance</h3>
                <?php foreach ($awaiting_balance as $ab): 
                    $ab_remaining = round((float)$ab['total_price'] - (float)$ab['downpayment_amount'], 2);
                    $ab_hours_left = hours_until_payment_deadline($ab['downpayment_paid_at']);
                    $ab_urgent = $ab_hours_left !== null && $ab_hours_left <= 12;
                ?>
                <div class="wallet-pending-row <?= $ab_urgent ? 'wallet-pending-row--urgent' : '' ?>">
                    <div class="wallet-pending-info">
                        <strong><?= htmlspecialchars($ab['booking_reference']) ?></strong>
                        <span><?= htmlspecialchars($ab['room_type']) ?> — ₱<?= number_format($ab_remaining, 2) ?> due</span>
                        <?php if ($ab_hours_left !== null): ?>
                            <span class="wallet-pending-countdown"><i class="fa-regular fa-clock"></i> <?= $ab_hours_left > 0 ? round($ab_hours_left, 1) . 'h remaining' : 'Past due — will auto-cancel shortly' ?></span>
                        <?php endif; ?>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action_pay_remaining_balance" value="1">
                        <input type="hidden" name="target_booking_reference" value="<?= htmlspecialchars($ab['booking_reference']) ?>">
                        <button type="submit" class="btn-pay-balance">Pay ₱<?= number_format($ab_remaining, 2) ?></button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($wallet_history)): ?>
            <div class="wallet-history">
                <h3><i class="fa-solid fa-clock-rotate-left"></i> Recent Wallet Activity</h3>
                <div class="wallet-history-list">
                    <?php foreach ($wallet_history as $wh): 
                        $wh_positive = (float)$wh['amount'] > 0;
                    ?>
                    <div class="wallet-history-row">
                        <div class="wallet-history-type">
                            <span class="wallet-history-badge wallet-history-badge--<?= strtolower(str_replace(' ', '-', $wh['transaction_type'])) ?>"><?= htmlspecialchars($wh['transaction_type']) ?></span>
                            <span class="wallet-history-note"><?= htmlspecialchars($wh['note'] ?? '') ?></span>
                        </div>
                        <div class="wallet-history-amount <?= $wh_positive ? 'wallet-amount-positive' : 'wallet-amount-negative' ?>">
                            <?= $wh_positive ? '+' : '' ?>₱<?= number_format($wh['amount'], 2) ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="section-header">
            <h2 id="ledger_view_title">Your Reservation Ledger (All)</h2>
            <a href="book.php" class="new-booking-trigger"><i class="fa-solid fa-plus"></i> Reserve Another Room</a>
        </div>

        <div class="bookings-stack" id="ledger_interactive_stack_target"></div>


        <div class="section-header">
            <h2><i class="fa-solid fa-plane-arrival" style="color: #16a34a; margin-right: 8px;"></i> Confirmed Upcoming Stays Portfolio</h2>
        </div>

        <div class="confirmed-grid-showcase" id="confirmed_cards_showroom_target"></div>
    </main>

    <!-- Original instant "Cancel Now" hidden form - unchanged behavior -->
    <form id="global_cancel_post_form" method="POST" action="dashboard.php" style="display: none;">
        <input type="hidden" name="action_cancel_booking" value="1">
        <input type="hidden" name="target_booking_reference" id="cancel_form_target_ref">
    </form>

    <!-- NEW: "Request Cancellation Review" hidden form target for the modal below -->
    <form id="global_request_review_post_form" method="POST" action="dashboard.php" style="display: none;">
        <input type="hidden" name="action_request_cancellation_review" value="1">
        <input type="hidden" name="target_booking_reference" id="request_form_target_ref">
        <input type="hidden" name="cancellation_reason" id="request_form_reason_hidden">
    </form>

    <div class="modal-overlay-backdrop" id="user_booking_inspect_modal">
        <div class="modal-box-frame">
            <h2 id="m_ref_id" class="modal-title"></h2>

            <div class="modal-date-grid">
                <div><span>Check-In Date</span><strong id="m_in"></strong></div>
                <div><span>Check-Out Date</span><strong id="m_out"></strong></div>
            </div>

            <div id="m_cutoff_notice" class="modal-cutoff-notice">
                <i class="fa-solid fa-clock"></i> <span id="m_cutoff_notice_text">Too close to check-in to cancel online. You can still submit a Request Cancellation Review below.</span>
            </div>

            <div>
                <span class="modal-requests-label">Special Requirements Logs</span>
                <p id="m_requests" class="modal-requests-body"></p>
            </div>

            <div class="modal-footer">
                <div><span>Total Cost Volume</span><strong id="m_total"></strong></div>
                <button type="button" class="modal-dismiss-btn" onclick="closeModal('user_booking_inspect_modal')">Dismiss Window</button>
            </div>
        </div>
    </div>

    <!-- NEW: Request Cancellation Review modal - collects an optional reason,
         then posts to the hidden form above. Distinct UX from the instant
         Cancel Now confirm() popup, since this is a request, not an action. -->
    <div class="modal-overlay-backdrop" id="request_review_modal">
        <div class="modal-box-frame">
            <h2 class="modal-title"><i class="fa-solid fa-file-circle-question" style="color:var(--gold); margin-right:8px;"></i>Request Cancellation Review</h2>
            <p class="request-modal-intro">
                Reservation <strong id="review_modal_ref_display"></strong> will be sent to our team for manual review.
                This does not cancel your booking immediately &mdash; you'll be notified once it's approved or denied.
            </p>
            <div class="form-input-block" style="margin-bottom: 20px;">
                <label for="review_reason_textarea">Reason for cancellation <span style="color:var(--slate-light); font-weight:500;">(optional)</span></label>
                <textarea id="review_reason_textarea" rows="3" placeholder="Let us know why you'd like to cancel this reservation..."></textarea>
            </div>
            <div class="modal-footer" style="border-top:none; padding-top:0;">
                <button type="button" class="modal-dismiss-btn" style="background:transparent; color:var(--slate); border:1px solid var(--line);" onclick="closeModal('request_review_modal')">Never Mind</button>
                <button type="button" class="modal-submit-btn" id="review_modal_submit_btn" onclick="submitCancellationReviewRequest()"><i class="fa-solid fa-paper-plane"></i> Submit Request</button>
            </div>
        </div>
    </div>

    <!-- NEW: wallet top-up modal - rules & disclaimer, gated by checkbox -->
    <div class="modal-overlay-backdrop" id="wallet_topup_modal">
        <div class="modal-box-frame" style="max-width:420px; text-align:center;">
            <h2 class="modal-title" style="justify-content:center;"><i class="fa-solid fa-circle-info" style="color:var(--gold); margin-right:8px;"></i>Add Money to Wallet</h2>
            <p class="request-modal-intro" style="margin-bottom:18px;">
                Please read the notice and rules below before proceeding to Haven Hotel's wallet top-up page for this account.
            </p>
            <div style="background:#FFF7E0; border:1px solid #F0D896; border-radius:14px; padding:16px 18px; margin-bottom:14px; text-align:left;">
                <p style="font-size:12.5px; color:#7A5B10; line-height:1.6; margin:0; font-weight:600;">
                    Haven Hotel Wallet ("G-Cosh") is an independent, hotel-operated feature for managing your in-stay balance. It is not GCash and is not affiliated with, endorsed by, sponsored by, or connected in any way to Globe Fintech Innovations, Inc. ("GCash") or any other e-wallet or payment provider.
                </p>
            </div>
            <div style="background:var(--bg-soft, #f7f7fb); border:1px solid var(--line); border-radius:14px; padding:18px; margin-bottom:18px; text-align:left; max-height:200px; overflow-y:auto;">
                <p style="font-size:12px; color:var(--slate-light); line-height:1.6; margin:0;">
                    © 2026 All Rights Reserved. This application is the original work of its owner. Any unauthorized cloning, copying, reverse engineering, distribution, or reproduction of this application is strictly prohibited and may result in legal action.
                </p>
            </div>
            <label for="topup_rules_agree" style="display:flex; align-items:flex-start; gap:10px; text-align:left; font-size:13px; color:var(--slate); margin-bottom:22px; cursor:pointer;">
                <input type="checkbox" id="topup_rules_agree" onchange="updateTopupProceedState()" style="margin-top:2px; flex-shrink:0;">
                <span>I have read and agree to the rules and regulations above.</span>
            </label>
            <div class="modal-footer" style="border-top:none; padding-top:0; justify-content:center; gap:10px;">
                <button type="button" class="modal-dismiss-btn" style="background:transparent; color:var(--slate); border:1px solid var(--line);" onclick="closeModal('wallet_topup_modal')">Close</button>
                <button type="button" id="topup_proceed_btn" class="btn-add-money" disabled onclick="proceedToTopup()" style="opacity:0.5; cursor:not-allowed;">Proceed to Top-Up</button>
            </div>
        </div>
    </div>

    <script>
    const TOPUP_PAGE_URL = <?= json_encode($topup_page_url) ?>;

    function updateTopupProceedState() {
        const agreed = document.getElementById('topup_rules_agree').checked;
        const btn = document.getElementById('topup_proceed_btn');
        btn.disabled = !agreed;
        btn.style.opacity = agreed ? '1' : '0.5';
        btn.style.cursor = agreed ? 'pointer' : 'not-allowed';
    }

    function proceedToTopup() {
        if (!document.getElementById('topup_rules_agree').checked) return;
        // NOTE: no 'noopener' here - wallet_topup.php checks window.opener
        // to decide whether it's allowed to auto-close itself after a
        // successful top-up (see startAutoCloseCountdown() there).
        // 'noopener' would sever that reference and silently disable
        // auto-close. Safe to omit since this only ever opens our own
        // wallet_topup.php (same-origin, so the session cookie carries
        // over too), not an external/untrusted site.
        window.open(TOPUP_PAGE_URL, '_blank');
    }
    </script>

    <script>
    const globalBookingsDataset = <?= json_encode($bookings_list); ?>;
    const CANCELLATION_LOCK_HOURS = <?= json_encode(CANCELLATION_LOCK_HOURS); ?>;

    function openTopupModal() {
        // Reset agreement state on every open, so checking the box once
        // doesn't silently carry over to a later visit to this modal.
        const checkbox = document.getElementById('topup_rules_agree');
        if (checkbox) {
            checkbox.checked = false;
            updateTopupProceedState();
        }
        document.getElementById('wallet_topup_modal').classList.add('active-modal');
    }

    function formatClientDate(dateStr) {
        if (!dateStr) return "N/A";
        const dateObj = new Date(dateStr);
        return dateObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }

    // Renders the small status pill for an existing cancellation review
    // request, or null if there's nothing to show (so callers fall back to
    // offering the action buttons instead).
    function renderCancelRequestPill(req) {
        if (!req) return null;
        const map = {
            'Pending':  { cls: 'creq-pill-pending',  icon: 'fa-hourglass-half', label: 'Review Requested' },
            'Approved': { cls: 'creq-pill-approved', icon: 'fa-circle-check',   label: 'Cancellation Approved' },
            'Denied':   { cls: 'creq-pill-denied',   icon: 'fa-circle-xmark',   label: 'Request Denied' }
        };
        const meta = map[req.request_status] || map['Pending'];
        let extra = '';
        if (req.request_status === 'Denied' && req.admin_note) {
            extra = `<span class="creq-pill-note" title="${req.admin_note.replace(/"/g, '&quot;')}"><i class="fa-solid fa-circle-info"></i></span>`;
        }
        return `<span class="creq-pill ${meta.cls}"><i class="fa-solid ${meta.icon}"></i> ${meta.label}</span>${extra}`;
    }

    function renderActiveWorkspaceLedger(filteredArray) {
        const showcaseContainer = document.getElementById('ledger_interactive_stack_target');
        showcaseContainer.innerHTML = '';

        if(filteredArray.length === 0) {
            showcaseContainer.innerHTML = `
                <div class="ledger-empty-state">
                    <i class="fa-solid fa-folder-open"></i>
                    <p>No bookings found matching this status filter.</p>
                </div>`;
            return;
        }

        filteredArray.forEach(b => {
            const card = document.createElement('div');
            card.className = 'booking-node-card';

            const statusClass = b.booking_status === 'Confirmed' ? 'status-confirmed' : (b.booking_status === 'Cancelled' ? 'status-cancelled' : 'status-pending');

            let actionsLayout = '';
            const activeReq = b.cancel_request;
            const hasOpenRequest = activeReq && activeReq.request_status === 'Pending';

            if (b.booking_status === 'Pending' || b.booking_status === 'Confirmed') {
                if (hasOpenRequest) {
                    // A review request is already in flight - show its status instead of action buttons.
                    actionsLayout = renderCancelRequestPill(activeReq);
                } else {
                    const pieces = [];
                    if (b.can_self_cancel) {
                        pieces.push(`<button type="button" class="node-btn node-btn-cancel" onclick="requestBookingCancellation('${b.booking_reference}')">Cancel Now</button>`);
                    } else if (b.is_same_day_booking) {
                        // NEW: distinct tooltip for the same-day reservation+check-in
                        // lock, separate from the generic 48h-post-checkin message below.
                        pieces.push(`<span class="node-btn-locked" title="the cancel is no longer active due to same day you reserve. Contact or request to admin if you want to cancel"><i class="fa-solid fa-lock"></i> Locked</span>`);
                    } else {
                        pieces.push(`<span class="node-btn-locked" title="It's been more than ${CANCELLATION_LOCK_HOURS} hours since check-in - contact the front desk, or submit a Request Cancellation Review"><i class="fa-solid fa-lock"></i> Locked</span>`);
                    }
                    pieces.push(`<button type="button" class="node-btn node-btn-review" onclick='openRequestReviewModal(${JSON.stringify(b.booking_reference)})'><i class="fa-solid fa-file-circle-question"></i> Request Review</button>`);
                    actionsLayout = pieces.join('');
                }
            } else if (activeReq) {
                // Booking already Cancelled but keep the resolved pill visible for context.
                actionsLayout = renderCancelRequestPill(activeReq);
            }

            card.innerHTML = `
                <div class="booking-node-left">
                    <div class="booking-node-icon"><i class="fa-solid fa-bed"></i></div>
                    <div>
                        <span class="booking-node-ref">${b.booking_reference}</span>
                        <h4 class="booking-node-title">${b.room_type ?? 'Stay Reservation'}</h4>
                        <p class="booking-node-dates"><i class="fa-solid fa-calendar-days"></i> ${formatClientDate(b.check_in_date)} &mdash; ${formatClientDate(b.check_out_date)}</p>
                    </div>
                </div>
                <div class="booking-node-right">
                    <div class="booking-node-price">₱${parseFloat(b.total_price).toLocaleString(undefined, {minimumFractionDigits: 2})}</div>
                    <span class="status-badge ${statusClass}">${b.booking_status}</span>
                    <div class="booking-node-actions">
                         ${actionsLayout}
                         <button type="button" class="node-btn node-btn-view" onclick='displayInspectDetailsModal(${JSON.stringify(b)})'>View</button>
                    </div>
                </div>
            `;
            showcaseContainer.appendChild(card);
        });
    }

    function renderConfirmedStaysShowroom() {
        const showroomTarget = document.getElementById('confirmed_cards_showroom_target');
        showroomTarget.innerHTML = '';
        
        const confirmedStays = globalBookingsDataset.filter(b => b.booking_status === 'Confirmed');

        if(confirmedStays.length === 0) {
            showroomTarget.innerHTML = `
                <div class="confirmed-empty-state">
                    <p>No confirmed upcoming stays yet.</p>
                </div>`;
            return;
        }

        confirmedStays.forEach(b => {
            const fallbackImg = b.image_url ? b.image_url : 'https://images.unsplash.com/photo-1566073771259-6a8506099945?auto=format&fit=crop&w=600&q=80';
            const card = document.createElement('div');
            card.className = 'confirmed-stay-card';
            card.innerHTML = `
                <img src="${fallbackImg}" alt="Confirmed Stay">
                <div class="confirmed-card-info-box">
                    <span class="confirmed-card-ref">${b.booking_reference}</span>
                    <h3>${b.room_type ?? 'Luxury Accommodation Suite'}</h3>
                    <div class="confirmed-card-timeline-node">
                        <div><span>Check-In</span><strong>${formatClientDate(b.check_in_date)}</strong></div>
                        <div><span>Check-Out</span><strong>${formatClientDate(b.check_out_date)}</strong></div>
                    </div>
                    <div class="confirmed-card-footer">
                        <div><span>Total Settled</span><strong>₱${parseFloat(b.total_price).toLocaleString(undefined, {minimumFractionDigits: 2})}</strong></div>
                        <button type="button" class="inspect-btn" onclick='displayInspectDetailsModal(${JSON.stringify(b)})'>Inspect Slip</button>
                    </div>
                </div>
            `;
            showroomTarget.appendChild(card);
        });
    }

    function filterLedgerTarget(statusStr) {
        document.getElementById('ledger_view_title').innerText = `Your Reservation Ledger (${statusStr})`;
        document.querySelectorAll('.metric-card').forEach(c => c.classList.remove('metric-card--primary'));
        event.currentTarget.classList.add('metric-card--primary');
        if(statusStr === 'All') {
            renderActiveWorkspaceLedger(globalBookingsDataset);
        } else {
            const subset = globalBookingsDataset.filter(b => b.booking_status === statusStr);
            renderActiveWorkspaceLedger(subset);
        }
    }

    function requestBookingCancellation(refKeyStr) {
        if (!confirm("Are you sure you want to cancel reservation " + refKeyStr + " immediately? This cannot be undone.")) return;
        document.getElementById('cancel_form_target_ref').value = refKeyStr;
        document.getElementById('global_cancel_post_form').submit();
    }

    // NEW: opens the "Request Cancellation Review" modal for a given booking
    // reference (instead of an instant confirm() popup - this is a request
    // for a human to review, not a same-second action).
    let _pendingReviewRef = null;
    function openRequestReviewModal(refKeyStr) {
        _pendingReviewRef = refKeyStr;
        document.getElementById('review_modal_ref_display').innerText = refKeyStr;
        document.getElementById('review_reason_textarea').value = '';
        document.getElementById('request_review_modal').classList.add('active-modal');
    }

    function submitCancellationReviewRequest() {
        if (!_pendingReviewRef) return;
        const btn = document.getElementById('review_modal_submit_btn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...';
        document.getElementById('request_form_target_ref').value = _pendingReviewRef;
        document.getElementById('request_form_reason_hidden').value = document.getElementById('review_reason_textarea').value;
        document.getElementById('global_request_review_post_form').submit();
    }

    function displayInspectDetailsModal(data) {
        document.getElementById('m_ref_id').innerText = "Reservation: " + data.booking_reference;
        document.getElementById('m_in').innerText = formatClientDate(data.check_in_date);
        document.getElementById('m_out').innerText = formatClientDate(data.check_out_date);
        document.getElementById('m_requests').innerText = data.special_requests ? data.special_requests : "No custom requirements provided.";
        document.getElementById('m_total').innerText = "₱" + parseFloat(data.total_price).toLocaleString(undefined, {minimumFractionDigits: 2});

        const cutoffNotice = document.getElementById('m_cutoff_notice');
        const cutoffNoticeText = document.getElementById('m_cutoff_notice_text');
        const isCancellable = (data.booking_status === 'Pending' || data.booking_status === 'Confirmed');
        cutoffNotice.classList.toggle('is-visible', isCancellable && !data.can_self_cancel);
        if (cutoffNoticeText) {
            // NEW: same-day reservation+check-in gets its own message,
            // distinct from the generic 48h-post-checkin one.
            cutoffNoticeText.innerText = data.is_same_day_booking
                ? "the cancel is no longer active due to same day you reserve. Contact or request to admin if you want to cancel"
                : "Too close to check-in to cancel online. You can still submit a Request Cancellation Review below.";
        }

        document.getElementById('user_booking_inspect_modal').classList.add('active-modal');
    }

    function closeModal(id) { 
        document.getElementById(id).classList.remove('active-modal');
    }

    // Run layout render updates automatically upon parsing completed sequence bounds
    document.addEventListener("DOMContentLoaded", () => {
        renderActiveWorkspaceLedger(globalBookingsDataset);
        renderConfirmedStaysShowroom();
    });

    // ---- Inline rename form toggle ----
    (function () {
        const openBtn = document.getElementById('openRenameBtn');
        const cancelBtn = document.getElementById('cancelRenameBtn');
        const display = document.getElementById('heroGreetingDisplay');
        const form = document.getElementById('renameForm');
        if (!openBtn || !form || !display) return;

        openBtn.addEventListener('click', () => {
            display.style.display = 'none';
            form.style.display = 'flex';
            const firstInput = form.querySelector('input[name="new_first_name"]');
            if (firstInput) firstInput.focus();
        });

        if (cancelBtn) {
            cancelBtn.addEventListener('click', () => {
                form.style.display = 'none';
                display.style.display = 'block';
            });
        }
    })();

    // ---- Notification bell dropdown ----
    // Mirrors the implementation in book.php so both guest-facing pages behave
    // identically; if you fix a bug in one, fix it in the other too.
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
</html><?php
session_start();

require_once __DIR__ . '/includes/payment_wallet_engine.php';

// 1. STRICT USER AUTHENTICATION CONSTRAINT CHECK
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// 2. DATABASE CONFIGURATION CONNECTIONS
$host     = "localhost";
$db_user  = "root";
$db_pass  = "";
$db_name  = "haven_hotel";

$conn = new mysqli($host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    die("Direct Database Connection to User Dashboard Failed: " . $conn->connect_error);
}

$user_id   = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];

// ============================================================
// SCHEMA SELF-HEALING: cancellation_requests table.
// Holds admin-review cancellation requests as a queue distinct
// from the existing instant self-service cancel path below -
// this is "Request Cancellation Review", not "Cancel Now".
// Safe to run every request - only creates the table once.
// ============================================================
$conn->query("
    CREATE TABLE IF NOT EXISTS cancellation_requests (
        request_id INT AUTO_INCREMENT PRIMARY KEY,
        booking_id INT NOT NULL,
        user_id INT NOT NULL,
        booking_reference VARCHAR(64) NOT NULL,
        reason TEXT NULL,
        request_status VARCHAR(20) NOT NULL DEFAULT 'Pending',
        admin_note TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        resolved_at TIMESTAMP NULL DEFAULT NULL
    )
");

// NEW: payment + wallet schema self-healing (bookings payment columns,
// user_wallets, wallet_transactions) - see includes/payment_wallet_engine.php
ensure_payment_wallet_schema($conn);

// NEW: sweep for any bookings that missed their 48h full-payment
// deadline and auto-cancel + refund them before rendering anything -
// this guest may be looking straight at the booking that's about to
// get swept, so it must run before any of this page's own queries.
run_payment_deadline_sweep($conn);

// LIVE NOTIFICATIONS ENGINE PIPELINE
// Counts this guest's personal unread rows PLUS broadcast rows (user_id IS NULL,
// e.g. "new room added" / "room unavailable" alerts from admin_dashboard.php)
// that this guest's session hasn't marked as seen yet. Kept in sync with
// notifications_ajax.php and book.php.
$unread_notifications_count = 0;
if (!isset($_SESSION['seen_broadcast_ids']) || !is_array($_SESSION['seen_broadcast_ids'])) {
    $_SESSION['seen_broadcast_ids'] = [];
}
$notif_stmt = $conn->prepare("SELECT COUNT(*) as unread_count FROM notifications WHERE user_id = ? AND is_read = 0");
$notif_stmt->bind_param("i", $user_id);
$notif_stmt->execute();
$notif_res = $notif_stmt->get_result()->fetch_assoc();
$personal_unread = (int)($notif_res['unread_count'] ?? 0);
$notif_stmt->close();

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

// Cancellation policy: guests get a grace window that starts at booking
// and extends until 48 hours AFTER check-in has passed. This covers
// three cases in one window: cancelling well before check-in, cancelling
// close to check-in, and cancelling early/mid-stay (e.g. a guest who
// checked in but has an emergency on day 1 or 2). Only once the stay is
// more than CANCELLATION_LOCK_HOURS past check-in does self-service
// Cancel Now lock out - past that point, only "Request Cancellation
// Review" (human-approved, see further below) or the admin can act.
//
// NOTE: this replaces an earlier version of this guard that only
// checked hours BEFORE check-in and locked unconditionally the moment
// check-in passed - that didn't allow the post-check-in grace period
// this policy actually calls for, so guests cancelling shortly after
// arriving would have been incorrectly locked out immediately.
define('CANCELLATION_LOCK_HOURS', 48);

// ========================================================
// NATIVE BACKEND EXTENSION: PROCESS GUEST CANCELLATION REQUEST (INSTANT)
// This is the original "Cancel Now" self-service path. Left fully intact,
// with the refund calculation + wallet crediting now wired in below.
// ========================================================
$cancel_error = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_cancel_booking'])) {
    $target_reference = $_POST['target_booking_reference'];

    // Security Safeguard: Ensure this booking actually belongs to the logged-in session user
    $verify_stmt = $conn->prepare("SELECT booking_id, booking_status, room_id, check_in_date, check_out_date, total_price, created_at FROM bookings WHERE booking_reference = ? AND user_id = ?");
    $verify_stmt->bind_param("si", $target_reference, $user_id);
    $verify_stmt->execute();
    $verify_res = $verify_stmt->get_result();

    if ($verify_res && $verify_res->num_rows > 0) {
        $booking_data = $verify_res->fetch_assoc();
        $current_status = $booking_data['booking_status'];

        // Only allow cancellation if it's Pending or Confirmed
        if ($current_status === 'Pending' || $current_status === 'Confirmed') {

            // NEW: SAME-DAY GUARD - if this reservation was MADE on the
            // same calendar day as its check-in date, self-service
            // cancellation is blocked outright, regardless of how many
            // hours remain before/after check-in. This is a distinct
            // rule from the CANCELLATION_LOCK_HOURS window below: a
            // booking made today for a check-in three weeks from now is
            // NOT a same-day booking and is unaffected by this guard -
            // it only fires when created_at's calendar date and
            // check_in_date are literally the same day.
            $is_same_day_booking = (date('Y-m-d', strtotime($booking_data['created_at'])) === $booking_data['check_in_date']);

            if ($is_same_day_booking) {
                $cancel_error = "This reservation can't be cancelled online because it was booked for the same day as check-in. Please contact the front desk directly, or submit a Request Cancellation Review below for our team to review.";
            } else {

            // CUTOFF GUARD: block self-service cancellation once MORE THAN
            // CANCELLATION_LOCK_HOURS (48h) have elapsed since check-in.
            // Before check-in, or up to 48h into the stay, self-cancel
            // stays available - this is a single continuous window, not
            // a "before check-in only" cutoff.
            $hours_since_checkin = (time() - strtotime($booking_data['check_in_date'])) / 3600;

            if ($hours_since_checkin > CANCELLATION_LOCK_HOURS) {
                $cancel_error = "This reservation can no longer be cancelled online because it's been more than 48 hours since check-in. Please contact the front desk directly for assistance, or submit a Request Cancellation Review below for our team to review.";
            } else {
                // NEW: calculate the refund BEFORE flipping status, since
                // nights_used_as_of_now() needs the still-live check-in/
                // check-out dates to work out how much of the stay (if
                // any) has already been consumed.
                $nights_total = max(1, round((strtotime($booking_data['check_out_date']) - strtotime($booking_data['check_in_date'])) / 86400));
                $nights_used = nights_used_as_of_now($booking_data['check_in_date'], $booking_data['check_out_date']);
                $price_per_night = (float)$booking_data['total_price'] / $nights_total;
                $refund_calc = calculate_refund_amount($nights_total, $nights_used, $price_per_night);
                $refund_amount = $refund_calc['refund_amount'];

                // Step A: Update the booking status matrix to 'Cancelled',
                // recording the refund figures alongside it in one write.
                $cancel_stmt = $conn->prepare("UPDATE bookings SET booking_status = 'Cancelled', refund_amount = ?, refunded_at = NOW() WHERE booking_reference = ? AND user_id = ?");
                $cancel_stmt->bind_param("dsi", $refund_amount, $target_reference, $user_id);

                if ($cancel_stmt->execute()) {
                    // NOTE: room availability is computed LIVE in book.php via a
                    // subquery counting active (Pending/Confirmed) bookings against
                    // rooms.total_inventory. There is no stored "available_rooms"
                    // counter to increment - marking this row Cancelled above is
                    // the only state change needed for the room to reappear as
                    // available on the booking page.

                    // NEW: credit the refund to the guest's wallet as REAL
                    // spendable balance (this is genuine money coming back
                    // to them, unlike Downpayment/Full Payment which are
                    // history-only - see BALANCE-SAFETY RULE in
                    // payment_wallet_engine.php).
                    if ($refund_amount > 0) {
                        post_wallet_transaction(
                            $conn,
                            $user_id,
                            $refund_amount,
                            'Refund',
                            $booking_data['booking_id'],
                            $target_reference,
                            "Guest-cancelled: {$refund_calc['nights_unused']} unused night(s) at {$refund_calc['refund_percent']}%"
                        );
                    }

                    // Step B: Inject a historical log entry directly into the administration notifications queue
                    $system_alert_msg = "Guest System Alert: User ID #$user_id ($user_name) has cancelled reservation reference [ $target_reference ]. Refund of ₱" . number_format($refund_amount, 2) . " credited to guest wallet.";
                    $log_stmt = $conn->prepare("INSERT INTO notifications (message) VALUES (?)");
                    $log_stmt->bind_param("s", $system_alert_msg);
                    $log_stmt->execute();
                    $log_stmt->close();

                    $_SESSION['cancel_success'] = "Reservation $target_reference has been successfully cancelled. ₱" . number_format($refund_amount, 2) . " has been credited to your account wallet.";
                }
                $cancel_stmt->close();
            }
            } // closes the "else" branch of the NEW same-day guard added above
        }
    }
    $verify_stmt->close();

    if (!empty($cancel_error)) {
        $_SESSION['cancel_error'] = $cancel_error;
    }

    // Redirect cleanly to avoid form re-submission loops
    header("Location: dashboard.php");
    exit();
}

// ========================================================
// NEW: PROCESS "REQUEST CANCELLATION REVIEW" (ADMIN-APPROVAL PATH)
// Distinct from the instant Cancel Now above - this does NOT change
// booking_status. It files a request row for admin_dashboard.php's new
// "Cancellation Requests" panel to approve or deny. Available regardless
// of the CANCELLATION_LOCK_HOURS cutoff, since it's asking a human rather
// than acting unilaterally.
// ========================================================
$request_review_error = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_request_cancellation_review'])) {
    $target_reference = trim($_POST['target_booking_reference'] ?? '');
    $reason = strip_tags(trim($_POST['cancellation_reason'] ?? ''));

    $verify_stmt = $conn->prepare("SELECT booking_id, booking_status FROM bookings WHERE booking_reference = ? AND user_id = ?");
    $verify_stmt->bind_param("si", $target_reference, $user_id);
    $verify_stmt->execute();
    $verify_res = $verify_stmt->get_result();

    if ($verify_res && $verify_res->num_rows > 0) {
        $booking_data = $verify_res->fetch_assoc();

        if ($booking_data['booking_status'] === 'Confirmed' || $booking_data['booking_status'] === 'Pending') {

            // Prevent duplicate pending requests for the same booking
            $dupe_stmt = $conn->prepare("SELECT request_id FROM cancellation_requests WHERE booking_id = ? AND request_status = 'Pending'");
            $dupe_stmt->bind_param("i", $booking_data['booking_id']);
            $dupe_stmt->execute();
            $has_dupe = $dupe_stmt->get_result()->num_rows > 0;
            $dupe_stmt->close();

            if ($has_dupe) {
                $request_review_error = "A cancellation review request for this reservation is already pending admin response.";
            } else {
                $ins_stmt = $conn->prepare("INSERT INTO cancellation_requests (booking_id, user_id, booking_reference, reason) VALUES (?, ?, ?, ?)");
                $ins_stmt->bind_param("iiss", $booking_data['booking_id'], $user_id, $target_reference, $reason);
                if ($ins_stmt->execute()) {
                    $admin_alert_msg = "Guest $user_name has requested cancellation review for reservation [ $target_reference ].";
                    $log_stmt = $conn->prepare("INSERT INTO notifications (message) VALUES (?)");
                    $log_stmt->bind_param("s", $admin_alert_msg);
                    $log_stmt->execute();
                    $log_stmt->close();

                    $_SESSION['request_success'] = "Your cancellation review request for $target_reference has been submitted. Our team will respond shortly.";
                } else {
                    $request_review_error = "We couldn't submit your request right now. Please try again.";
                }
                $ins_stmt->close();
            }
        } else {
            $request_review_error = "This reservation is no longer eligible for a cancellation request.";
        }
    } else {
        $request_review_error = "Reservation not found.";
    }
    $verify_stmt->close();

    if (!empty($request_review_error)) {
        $_SESSION['request_error'] = $request_review_error;
    }

    header("Location: dashboard.php");
    exit();
}

// ========================================================
// NEW: PROCESS "RENAME ACCOUNT" (update first_name / last_name)
// Updates the REAL users table columns (not just a session string) so
// this stays consistent with how admin_dashboard.php already reads
// guest names via CONCAT(u.first_name, ' ', u.last_name) everywhere.
// Session's user_name is refreshed too, so this page's own greeting
// picks up the change immediately without requiring a re-login.
// ========================================================
$rename_error = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_rename_account'])) {
    $new_first = trim($_POST['new_first_name'] ?? '');
    $new_last  = trim($_POST['new_last_name'] ?? '');

    if ($new_first === '' || $new_last === '') {
        $rename_error = "Please provide both a first and last name.";
    } elseif (mb_strlen($new_first) > 100 || mb_strlen($new_last) > 100) {
        $rename_error = "Names must be under 100 characters.";
    } else {
        $rn_stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ? WHERE id = ?");
        $rn_stmt->bind_param("ssi", $new_first, $new_last, $user_id);
        if ($rn_stmt->execute()) {
            $_SESSION['user_name'] = $new_first . ' ' . $new_last;
            $_SESSION['rename_success'] = "Your name has been updated successfully.";
        } else {
            $rename_error = "We couldn't update your name right now. Please try again.";
        }
        $rn_stmt->close();
    }

    if (!empty($rename_error)) {
        $_SESSION['rename_error'] = $rename_error;
    }

    header("Location: dashboard.php");
    exit();
}

// ========================================================
// NEW: PROCESS "PAY REMAINING BALANCE" (full payment after downpayment)
// Only valid while payment_status = 'Downpayment Paid' - once this
// succeeds the booking is Fully Paid and drops out of the 48h auto-
// cancel sweep's target set entirely (see run_payment_deadline_sweep()
// in payment_wallet_engine.php, which only ever touches bookings still
// in 'Downpayment Paid').
// ========================================================
$pay_balance_error = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_pay_remaining_balance'])) {
    $target_reference = trim($_POST['target_booking_reference'] ?? '');

    $verify_stmt = $conn->prepare("SELECT booking_id, payment_status, total_price, downpayment_amount FROM bookings WHERE booking_reference = ? AND user_id = ?");
    $verify_stmt->bind_param("si", $target_reference, $user_id);
    $verify_stmt->execute();
    $verify_res = $verify_stmt->get_result();

    if ($verify_res && $verify_res->num_rows > 0) {
        $booking_data = $verify_res->fetch_assoc();

        if ($booking_data['payment_status'] === 'Downpayment Paid') {
            $remaining_due = round((float)$booking_data['total_price'] - (float)$booking_data['downpayment_amount'], 2);

            // NEW: let the guest apply any existing wallet balance (from
            // a prior refund) toward this remaining balance first, only
            // recording the leftover as the "Full Payment" history entry.
            $wallet_result = apply_wallet_balance_to_amount($conn, $user_id, $remaining_due, $booking_data['booking_id'], $target_reference, 'remaining balance on ' . $target_reference);

            $pay_stmt = $conn->prepare("UPDATE bookings SET payment_status = 'Fully Paid', full_payment_paid_at = NOW() WHERE booking_reference = ? AND user_id = ?");
            $pay_stmt->bind_param("si", $target_reference, $user_id);
            if ($pay_stmt->execute()) {
                // History-only entry for the portion NOT covered by wallet
                // (see BALANCE-SAFETY RULE - Full Payment never itself
                // moves spendable balance; apply_wallet_balance_to_amount()
                // above already handled the real balance movement, if any).
                if ($wallet_result['remaining_due'] > 0) {
                    post_wallet_transaction(
                        $conn, $user_id, $wallet_result['remaining_due'], 'Full Payment',
                        $booking_data['booking_id'], $target_reference,
                        'Remaining balance payment' . ($wallet_result['applied'] > 0 ? ' (after ₱' . number_format($wallet_result['applied'], 2) . ' wallet credit applied)' : '')
                    );
                }

                $walletNote = $wallet_result['applied'] > 0 ? " (₱" . number_format($wallet_result['applied'], 2) . " covered by your wallet balance)" : "";
                $_SESSION['cancel_success'] = "Remaining balance for $target_reference has been paid in full.$walletNote Your reservation is now fully confirmed.";
            } else {
                $pay_balance_error = "We couldn't process this payment right now. Please try again.";
            }
            $pay_stmt->close();
        } else {
            $pay_balance_error = "This reservation isn't awaiting a remaining balance payment.";
        }
    } else {
        $pay_balance_error = "Reservation not found.";
    }
    $verify_stmt->close();

    if (!empty($pay_balance_error)) {
        $_SESSION['cancel_error'] = $pay_balance_error;
    }

    header("Location: dashboard.php");
    exit();
}

// Pull fresh user profiling metrics (Note: membership_tier column handled safely)
$user_stmt = $conn->prepare("SELECT created_at, first_name, last_name FROM users WHERE id = ?");
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_profile = $user_stmt->get_result()->fetch_assoc();
$user_stmt->close();

// NEW: wallet balance + recent transaction history for the Account
// Wallet card. Kept to the most recent 15 entries so the card doesn't
// grow unbounded for guests with a long booking history.
$wallet_balance = get_wallet_balance($conn, $user_id);
$wallet_history = [];
$wh_stmt = $conn->prepare("SELECT * FROM wallet_transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 15");
$wh_stmt->bind_param("i", $user_id);
$wh_stmt->execute();
$wh_res = $wh_stmt->get_result();
while ($wh_row = $wh_res->fetch_assoc()) {
    $wallet_history[] = $wh_row;
}
$wh_stmt->close();

// Wallet top-up link.
//
// SECURITY FIX: this used to append ?uid=<this account's id> directly
// to the URL, and wallet_topup.html trusted that number completely - a
// guest could edit it in the address bar and top up (or manipulate) a
// different account's wallet entirely. wallet_topup.php now identifies
// the account from the PHP session instead (the same $_SESSION['user_id']
// this very page requires to render at all), so there is nothing left
// in the URL for a guest to tamper with, and no uid parameter is needed.
//
// Still detects http vs https so the "Proceed to Top-Up" button works
// on a plain local dev server as well as a real deployment.
$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
$topup_protocol = $is_https ? "https://" : "http://";
$topup_page_url = $topup_protocol . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . "/wallet_topup.php";
// NOTE: the modal no longer shows a QR code (replaced with a rules/
// disclaimer + checkbox gate below), so $topup_page_url now only feeds
// the "Proceed to Top-Up" button's window.open() call directly.

// FETCH ALL LOGGED RESERVATIONS FOR THIS PARTICULAR USER BOUND
$bookings_list = [];
$booking_stmt = $conn->prepare("
    SELECT b.*, r.image_url 
    FROM bookings b 
    LEFT JOIN rooms r ON b.room_id = r.room_id 
    WHERE b.user_id = ? 
    ORDER BY b.created_at DESC
");
$booking_stmt->bind_param("i", $user_id);
$booking_stmt->execute();
$booking_result = $booking_stmt->get_result();

// Pull this user's cancellation review requests, keyed by booking_id, so the
// ledger can show "Review Requested" / "Approved" / "Denied" per booking
// instead of just re-showing the Cancel Now / Request Review buttons forever.
$my_cancel_requests_by_booking = [];
$creq_stmt = $conn->prepare("SELECT booking_id, request_status, admin_note, created_at FROM cancellation_requests WHERE user_id = ? ORDER BY created_at DESC");
$creq_stmt->bind_param("i", $user_id);
$creq_stmt->execute();
$creq_res = $creq_stmt->get_result();
while ($cr = $creq_res->fetch_assoc()) {
    // Keep only the most recent request per booking (query is already DESC)
    if (!isset($my_cancel_requests_by_booking[$cr['booking_id']])) {
        $my_cancel_requests_by_booking[$cr['booking_id']] = $cr;
    }
}
$creq_stmt->close();

$count_pending   = 0;
$count_confirmed = 0;
$count_cancelled = 0;

while ($row = $booking_result->fetch_assoc()) {
    // Precompute, server-side, whether this booking is still within the
    // self-service cancellation window so the front-end doesn't need to
    // duplicate date-math logic (and can't be tricked by a stale clock).
    // Mirrors the backend POST guard above EXACTLY: unlocked any time
    // before check-in, AND for up to CANCELLATION_LOCK_HOURS (48h) after
    // check-in has passed (covers early/mid-stay emergency cancellations)
    // - only locks once more than 48h has elapsed since check-in.
    //
    // NEW: also mirrors the SAME-DAY GUARD above - a reservation booked
    // on the same calendar day as its own check-in date is locked
    // regardless of the 48h window, since that's a separate rule keyed
    // on created_at vs. check_in_date, not on hours-since-checkin.
    $hrs_since_checkin = (time() - strtotime($row['check_in_date'])) / 3600;
    $is_same_day_booking_row = (date('Y-m-d', strtotime($row['created_at'])) === $row['check_in_date']);
    $row['is_same_day_booking'] = $is_same_day_booking_row;
    $row['can_self_cancel'] = (!$is_same_day_booking_row) && ($hrs_since_checkin <= CANCELLATION_LOCK_HOURS);

    // Attach this booking's active/most-recent cancellation review request, if any.
    $row['cancel_request'] = $my_cancel_requests_by_booking[$row['booking_id']] ?? null;

    $bookings_list[] = $row;
    if ($row['booking_status'] === 'Pending')   $count_pending++;
    if ($row['booking_status'] === 'Confirmed') $count_confirmed++;
    if ($row['booking_status'] === 'Cancelled') $count_cancelled++;
}
$booking_stmt->close();
$conn->close();

// NEW: use the REAL first_name/last_name from the users table (already
// fetched into $user_profile above) instead of splitting the combined
// session string - this is what the rename feature actually updates,
// so the greeting must read from the same source of truth.
$first_name = $user_profile['first_name'] ?? $user_name;
$last_name  = $user_profile['last_name'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Haven Hotel - Guest Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="ui/dashboard.css">
</head>
<body>

    <header class="navbar">
        <div class="logo">Haven<span>Hotel</span></div>
    <nav>
        <ul class="nav-links">
            <li><a href="index.php">Home</a></li>
            <li><a href="about.php" class="active">About</a></li>
            <li><a href="index.php #rooms">Accommodations</a></li>
            <li><a href="index.php #booking">Booking</a></li>
            <li><a href="index.php #overview">Overview</a></li>
            <li><a href="index.php #contact">Contact</a></li>
        </ul>
    </nav>
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
        <a href="login.php" class="logout-btn"><i class="fa-solid fa-right-from-bracket" style="margin-right:6px;"></i> Logout</a>
    </header>

    <section class="dashboard-hero">
        <div class="hero-welcome">
            <h1 id="heroGreetingDisplay">
                Hello, <?= htmlspecialchars(strtoupper($first_name)); ?>
                <button type="button" id="openRenameBtn" class="hero-rename-trigger" title="Edit your name" aria-label="Edit your name">
                    <i class="fa-solid fa-pen"></i>
                </button>
            </h1>
            <p>Manage your luxury stay requests, track reservations, and view confirmation slips.</p>

            <!-- NEW: inline rename form, hidden by default, toggled by the pencil icon above -->
            <form method="POST" id="renameForm" class="hero-rename-form" style="display:none;">
                <input type="hidden" name="action_rename_account" value="1">
                <input type="text" name="new_first_name" placeholder="First name" value="<?= htmlspecialchars($first_name) ?>" maxlength="100" required>
                <input type="text" name="new_last_name" placeholder="Last name" value="<?= htmlspecialchars($last_name) ?>" maxlength="100" required>
                <button type="submit" class="hero-rename-save"><i class="fa-solid fa-check"></i> Save</button>
                <button type="button" id="cancelRenameBtn" class="hero-rename-cancel"><i class="fa-solid fa-xmark"></i></button>
            </form>
        </div>
        <div class="user-tier-badge">
            <span>Account Profile Status</span>
            <strong>Active Guest</strong>
        </div>
    </section>

    <main class="main-container">
        
        <?php if (isset($_SESSION['rename_success'])): ?>
            <div class="dash-alert dash-alert-success">
                <i class="fa-solid fa-circle-check"></i>
                <span><?= htmlspecialchars($_SESSION['rename_success']); unset($_SESSION['rename_success']); ?></span>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['rename_error'])): ?>
            <div class="dash-alert dash-alert-warning">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <span><?= htmlspecialchars($_SESSION['rename_error']); unset($_SESSION['rename_error']); ?></span>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['cancel_success'])): ?>
            <div class="dash-alert dash-alert-success">
                <i class="fa-solid fa-circle-check"></i>
                <span><?= htmlspecialchars($_SESSION['cancel_success']); unset($_SESSION['cancel_success']); ?></span>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['cancel_error'])): ?>
            <div class="dash-alert dash-alert-warning">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <span><?= htmlspecialchars($_SESSION['cancel_error']); unset($_SESSION['cancel_error']); ?></span>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['request_success'])): ?>
            <div class="dash-alert dash-alert-success">
                <i class="fa-solid fa-paper-plane"></i>
                <span><?= htmlspecialchars($_SESSION['request_success']); unset($_SESSION['request_success']); ?></span>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['request_error'])): ?>
            <div class="dash-alert dash-alert-warning">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <span><?= htmlspecialchars($_SESSION['request_error']); unset($_SESSION['request_error']); ?></span>
            </div>
        <?php endif; ?>
        
        <div class="metrics-grid">
            <button type="button" class="metric-card metric-card--primary" onclick="filterLedgerTarget('All')">
                <div class="metric-icon icon-all"><i class="fa-solid fa-list-check"></i></div>
                <div class="metric-data">
                    <span>Total Requests</span>
                    <h2><?= count($bookings_list) ?></h2>
                </div>
            </button>
            <button type="button" class="metric-card" onclick="filterLedgerTarget('Pending')">
                <div class="metric-icon icon-pending"><i class="fa-regular fa-clock"></i></div>
                <div class="metric-data">
                    <span>Pending Approval</span>
                    <h2><?= $count_pending ?></h2>
                </div>
            </button>
            <button type="button" class="metric-card" onclick="filterLedgerTarget('Confirmed')">
                <div class="metric-icon icon-confirmed"><i class="fa-regular fa-calendar-check"></i></div>
                <div class="metric-data">
                    <span>Confirmed Stays</span>
                    <h2><?= $count_confirmed ?></h2>
                </div>
            </button>
            <button type="button" class="metric-card" onclick="filterLedgerTarget('Cancelled')">
                <div class="metric-icon icon-cancelled"><i class="fa-regular fa-circle-xmark"></i></div>
                <div class="metric-data">
                    <span>Cancelled Requests</span>
                    <h2><?= $count_cancelled ?></h2>
                </div>
            </button>
        </div>

        <!-- NEW: Account Wallet section -->
        <div class="wallet-section">
            <div class="wallet-balance-card">
                <div class="wallet-balance-header">
                    <div class="wallet-balance-icon"><i class="fa-solid fa-wallet"></i></div>
                    <div>
                        <span class="wallet-balance-label">Account Wallet Balance</span>
                        <h2 class="wallet-balance-amount">₱<?= number_format($wallet_balance, 2) ?></h2>
                    </div>
                    <button type="button" class="btn-add-money" onclick="openTopupModal()"><i class="fa-solid fa-qrcode"></i> Add Money</button>
                </div>
                <p class="wallet-balance-note">Credited from cancellation refunds. Automatically applied toward your next downpayment or remaining balance payment.</p>
            </div>

            <?php
                // Bookings currently awaiting their remaining balance -
                // surfaced here so the guest doesn't have to hunt through
                // the full ledger below to find what needs action.
                $awaiting_balance = array_filter($bookings_list, fn($b) => $b['payment_status'] === 'Downpayment Paid' && $b['booking_status'] !== 'Cancelled');
            ?>
            <?php if (!empty($awaiting_balance)): ?>
            <div class="wallet-pending-payments">
                <h3><i class="fa-regular fa-clock"></i> Awaiting Remaining Balance</h3>
                <?php foreach ($awaiting_balance as $ab): 
                    $ab_remaining = round((float)$ab['total_price'] - (float)$ab['downpayment_amount'], 2);
                    $ab_hours_left = hours_until_payment_deadline($ab['downpayment_paid_at']);
                    $ab_urgent = $ab_hours_left !== null && $ab_hours_left <= 12;
                ?>
                <div class="wallet-pending-row <?= $ab_urgent ? 'wallet-pending-row--urgent' : '' ?>">
                    <div class="wallet-pending-info">
                        <strong><?= htmlspecialchars($ab['booking_reference']) ?></strong>
                        <span><?= htmlspecialchars($ab['room_type']) ?> — ₱<?= number_format($ab_remaining, 2) ?> due</span>
                        <?php if ($ab_hours_left !== null): ?>
                            <span class="wallet-pending-countdown"><i class="fa-regular fa-clock"></i> <?= $ab_hours_left > 0 ? round($ab_hours_left, 1) . 'h remaining' : 'Past due — will auto-cancel shortly' ?></span>
                        <?php endif; ?>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action_pay_remaining_balance" value="1">
                        <input type="hidden" name="target_booking_reference" value="<?= htmlspecialchars($ab['booking_reference']) ?>">
                        <button type="submit" class="btn-pay-balance">Pay ₱<?= number_format($ab_remaining, 2) ?></button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($wallet_history)): ?>
            <div class="wallet-history">
                <h3><i class="fa-solid fa-clock-rotate-left"></i> Recent Wallet Activity</h3>
                <div class="wallet-history-list">
                    <?php foreach ($wallet_history as $wh): 
                        $wh_positive = (float)$wh['amount'] > 0;
                    ?>
                    <div class="wallet-history-row">
                        <div class="wallet-history-type">
                            <span class="wallet-history-badge wallet-history-badge--<?= strtolower(str_replace(' ', '-', $wh['transaction_type'])) ?>"><?= htmlspecialchars($wh['transaction_type']) ?></span>
                            <span class="wallet-history-note"><?= htmlspecialchars($wh['note'] ?? '') ?></span>
                        </div>
                        <div class="wallet-history-amount <?= $wh_positive ? 'wallet-amount-positive' : 'wallet-amount-negative' ?>">
                            <?= $wh_positive ? '+' : '' ?>₱<?= number_format($wh['amount'], 2) ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="section-header">
            <h2 id="ledger_view_title">Your Reservation Ledger (All)</h2>
            <a href="book.php" class="new-booking-trigger"><i class="fa-solid fa-plus"></i> Reserve Another Room</a>
        </div>

        <div class="bookings-stack" id="ledger_interactive_stack_target"></div>


        <div class="section-header">
            <h2><i class="fa-solid fa-plane-arrival" style="color: #16a34a; margin-right: 8px;"></i> Confirmed Upcoming Stays Portfolio</h2>
        </div>

        <div class="confirmed-grid-showcase" id="confirmed_cards_showroom_target"></div>
    </main>

    <!-- Original instant "Cancel Now" hidden form - unchanged behavior -->
    <form id="global_cancel_post_form" method="POST" action="dashboard.php" style="display: none;">
        <input type="hidden" name="action_cancel_booking" value="1">
        <input type="hidden" name="target_booking_reference" id="cancel_form_target_ref">
    </form>

    <!-- NEW: "Request Cancellation Review" hidden form target for the modal below -->
    <form id="global_request_review_post_form" method="POST" action="dashboard.php" style="display: none;">
        <input type="hidden" name="action_request_cancellation_review" value="1">
        <input type="hidden" name="target_booking_reference" id="request_form_target_ref">
        <input type="hidden" name="cancellation_reason" id="request_form_reason_hidden">
    </form>

    <div class="modal-overlay-backdrop" id="user_booking_inspect_modal">
        <div class="modal-box-frame">
            <h2 id="m_ref_id" class="modal-title"></h2>

            <div class="modal-date-grid">
                <div><span>Check-In Date</span><strong id="m_in"></strong></div>
                <div><span>Check-Out Date</span><strong id="m_out"></strong></div>
            </div>

            <div id="m_cutoff_notice" class="modal-cutoff-notice">
                <i class="fa-solid fa-clock"></i> <span id="m_cutoff_notice_text">Too close to check-in to cancel online. You can still submit a Request Cancellation Review below.</span>
            </div>

            <div>
                <span class="modal-requests-label">Special Requirements Logs</span>
                <p id="m_requests" class="modal-requests-body"></p>
            </div>

            <div class="modal-footer">
                <div><span>Total Cost Volume</span><strong id="m_total"></strong></div>
                <button type="button" class="modal-dismiss-btn" onclick="closeModal('user_booking_inspect_modal')">Dismiss Window</button>
            </div>
        </div>
    </div>

    <!-- NEW: Request Cancellation Review modal - collects an optional reason,
         then posts to the hidden form above. Distinct UX from the instant
         Cancel Now confirm() popup, since this is a request, not an action. -->
    <div class="modal-overlay-backdrop" id="request_review_modal">
        <div class="modal-box-frame">
            <h2 class="modal-title"><i class="fa-solid fa-file-circle-question" style="color:var(--gold); margin-right:8px;"></i>Request Cancellation Review</h2>
            <p class="request-modal-intro">
                Reservation <strong id="review_modal_ref_display"></strong> will be sent to our team for manual review.
                This does not cancel your booking immediately &mdash; you'll be notified once it's approved or denied.
            </p>
            <div class="form-input-block" style="margin-bottom: 20px;">
                <label for="review_reason_textarea">Reason for cancellation <span style="color:var(--slate-light); font-weight:500;">(optional)</span></label>
                <textarea id="review_reason_textarea" rows="3" placeholder="Let us know why you'd like to cancel this reservation..."></textarea>
            </div>
            <div class="modal-footer" style="border-top:none; padding-top:0;">
                <button type="button" class="modal-dismiss-btn" style="background:transparent; color:var(--slate); border:1px solid var(--line);" onclick="closeModal('request_review_modal')">Never Mind</button>
                <button type="button" class="modal-submit-btn" id="review_modal_submit_btn" onclick="submitCancellationReviewRequest()"><i class="fa-solid fa-paper-plane"></i> Submit Request</button>
            </div>
        </div>
    </div>

    <!-- NEW: wallet top-up modal - rules & disclaimer, gated by checkbox -->
    <div class="modal-overlay-backdrop" id="wallet_topup_modal">
        <div class="modal-box-frame" style="max-width:420px; text-align:center;">
            <h2 class="modal-title" style="justify-content:center;"><i class="fa-solid fa-circle-info" style="color:var(--gold); margin-right:8px;"></i>Add Money to Wallet</h2>
            <p class="request-modal-intro" style="margin-bottom:18px;">
                Please read the notice and rules below before proceeding to Haven Hotel's wallet top-up page for this account.
            </p>
            <div style="background:#FFF7E0; border:1px solid #F0D896; border-radius:14px; padding:16px 18px; margin-bottom:14px; text-align:left;">
                <p style="font-size:12.5px; color:#7A5B10; line-height:1.6; margin:0; font-weight:600;">
                    Haven Hotel Wallet ("G-Cosh") is an independent, hotel-operated feature for managing your in-stay balance. It is not GCash and is not affiliated with, endorsed by, sponsored by, or connected in any way to Globe Fintech Innovations, Inc. ("GCash") or any other e-wallet or payment provider.
                </p>
            </div>
            <div style="background:var(--bg-soft, #f7f7fb); border:1px solid var(--line); border-radius:14px; padding:18px; margin-bottom:18px; text-align:left; max-height:200px; overflow-y:auto;">
                <p style="font-size:12px; color:var(--slate-light); line-height:1.6; margin:0;">
                    © 2026 All Rights Reserved. This application is the original work of its owner. Any unauthorized cloning, copying, reverse engineering, distribution, or reproduction of this application is strictly prohibited and may result in legal action.
                </p>
            </div>
            <label for="topup_rules_agree" style="display:flex; align-items:flex-start; gap:10px; text-align:left; font-size:13px; color:var(--slate); margin-bottom:22px; cursor:pointer;">
                <input type="checkbox" id="topup_rules_agree" onchange="updateTopupProceedState()" style="margin-top:2px; flex-shrink:0;">
                <span>I have read and agree to the rules and regulations above.</span>
            </label>
            <div class="modal-footer" style="border-top:none; padding-top:0; justify-content:center; gap:10px;">
                <button type="button" class="modal-dismiss-btn" style="background:transparent; color:var(--slate); border:1px solid var(--line);" onclick="closeModal('wallet_topup_modal')">Close</button>
                <button type="button" id="topup_proceed_btn" class="btn-add-money" disabled onclick="proceedToTopup()" style="opacity:0.5; cursor:not-allowed;">Proceed to Top-Up</button>
            </div>
        </div>
    </div>

    <script>
    const TOPUP_PAGE_URL = <?= json_encode($topup_page_url) ?>;

    function updateTopupProceedState() {
        const agreed = document.getElementById('topup_rules_agree').checked;
        const btn = document.getElementById('topup_proceed_btn');
        btn.disabled = !agreed;
        btn.style.opacity = agreed ? '1' : '0.5';
        btn.style.cursor = agreed ? 'pointer' : 'not-allowed';
    }

    function proceedToTopup() {
        if (!document.getElementById('topup_rules_agree').checked) return;
        // NOTE: no 'noopener' here - wallet_topup.php checks window.opener
        // to decide whether it's allowed to auto-close itself after a
        // successful top-up (see startAutoCloseCountdown() there).
        // 'noopener' would sever that reference and silently disable
        // auto-close. Safe to omit since this only ever opens our own
        // wallet_topup.php (same-origin, so the session cookie carries
        // over too), not an external/untrusted site.
        window.open(TOPUP_PAGE_URL, '_blank');
    }
    </script>

    <script>
    const globalBookingsDataset = <?= json_encode($bookings_list); ?>;
    const CANCELLATION_LOCK_HOURS = <?= json_encode(CANCELLATION_LOCK_HOURS); ?>;

    function openTopupModal() {
        // Reset agreement state on every open, so checking the box once
        // doesn't silently carry over to a later visit to this modal.
        const checkbox = document.getElementById('topup_rules_agree');
        if (checkbox) {
            checkbox.checked = false;
            updateTopupProceedState();
        }
        document.getElementById('wallet_topup_modal').classList.add('active-modal');
    }

    function formatClientDate(dateStr) {
        if (!dateStr) return "N/A";
        const dateObj = new Date(dateStr);
        return dateObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }

    // Renders the small status pill for an existing cancellation review
    // request, or null if there's nothing to show (so callers fall back to
    // offering the action buttons instead).
    function renderCancelRequestPill(req) {
        if (!req) return null;
        const map = {
            'Pending':  { cls: 'creq-pill-pending',  icon: 'fa-hourglass-half', label: 'Review Requested' },
            'Approved': { cls: 'creq-pill-approved', icon: 'fa-circle-check',   label: 'Cancellation Approved' },
            'Denied':   { cls: 'creq-pill-denied',   icon: 'fa-circle-xmark',   label: 'Request Denied' }
        };
        const meta = map[req.request_status] || map['Pending'];
        let extra = '';
        if (req.request_status === 'Denied' && req.admin_note) {
            extra = `<span class="creq-pill-note" title="${req.admin_note.replace(/"/g, '&quot;')}"><i class="fa-solid fa-circle-info"></i></span>`;
        }
        return `<span class="creq-pill ${meta.cls}"><i class="fa-solid ${meta.icon}"></i> ${meta.label}</span>${extra}`;
    }

    function renderActiveWorkspaceLedger(filteredArray) {
        const showcaseContainer = document.getElementById('ledger_interactive_stack_target');
        showcaseContainer.innerHTML = '';

        if(filteredArray.length === 0) {
            showcaseContainer.innerHTML = `
                <div class="ledger-empty-state">
                    <i class="fa-solid fa-folder-open"></i>
                    <p>No bookings found matching this status filter.</p>
                </div>`;
            return;
        }

        filteredArray.forEach(b => {
            const card = document.createElement('div');
            card.className = 'booking-node-card';

            const statusClass = b.booking_status === 'Confirmed' ? 'status-confirmed' : (b.booking_status === 'Cancelled' ? 'status-cancelled' : 'status-pending');

            let actionsLayout = '';
            const activeReq = b.cancel_request;
            const hasOpenRequest = activeReq && activeReq.request_status === 'Pending';

            if (b.booking_status === 'Pending' || b.booking_status === 'Confirmed') {
                if (hasOpenRequest) {
                    // A review request is already in flight - show its status instead of action buttons.
                    actionsLayout = renderCancelRequestPill(activeReq);
                } else {
                    const pieces = [];
                    if (b.can_self_cancel) {
                        pieces.push(`<button type="button" class="node-btn node-btn-cancel" onclick="requestBookingCancellation('${b.booking_reference}')">Cancel Now</button>`);
                    } else if (b.is_same_day_booking) {
                        // NEW: distinct tooltip for the same-day reservation+check-in
                        // lock, separate from the generic 48h-post-checkin message below.
                        pieces.push(`<span class="node-btn-locked" title="the cancel is no longer active due to same day you reserve. Contact or request to admin if you want to cancel"><i class="fa-solid fa-lock"></i> Locked</span>`);
                    } else {
                        pieces.push(`<span class="node-btn-locked" title="It's been more than ${CANCELLATION_LOCK_HOURS} hours since check-in - contact the front desk, or submit a Request Cancellation Review"><i class="fa-solid fa-lock"></i> Locked</span>`);
                    }
                    pieces.push(`<button type="button" class="node-btn node-btn-review" onclick='openRequestReviewModal(${JSON.stringify(b.booking_reference)})'><i class="fa-solid fa-file-circle-question"></i> Request Review</button>`);
                    actionsLayout = pieces.join('');
                }
            } else if (activeReq) {
                // Booking already Cancelled but keep the resolved pill visible for context.
                actionsLayout = renderCancelRequestPill(activeReq);
            }

            card.innerHTML = `
                <div class="booking-node-left">
                    <div class="booking-node-icon"><i class="fa-solid fa-bed"></i></div>
                    <div>
                        <span class="booking-node-ref">${b.booking_reference}</span>
                        <h4 class="booking-node-title">${b.room_type ?? 'Stay Reservation'}</h4>
                        <p class="booking-node-dates"><i class="fa-solid fa-calendar-days"></i> ${formatClientDate(b.check_in_date)} &mdash; ${formatClientDate(b.check_out_date)}</p>
                    </div>
                </div>
                <div class="booking-node-right">
                    <div class="booking-node-price">₱${parseFloat(b.total_price).toLocaleString(undefined, {minimumFractionDigits: 2})}</div>
                    <span class="status-badge ${statusClass}">${b.booking_status}</span>
                    <div class="booking-node-actions">
                         ${actionsLayout}
                         <button type="button" class="node-btn node-btn-view" onclick='displayInspectDetailsModal(${JSON.stringify(b)})'>View</button>
                    </div>
                </div>
            `;
            showcaseContainer.appendChild(card);
        });
    }

    function renderConfirmedStaysShowroom() {
        const showroomTarget = document.getElementById('confirmed_cards_showroom_target');
        showroomTarget.innerHTML = '';
        
        const confirmedStays = globalBookingsDataset.filter(b => b.booking_status === 'Confirmed');

        if(confirmedStays.length === 0) {
            showroomTarget.innerHTML = `
                <div class="confirmed-empty-state">
                    <p>No confirmed upcoming stays yet.</p>
                </div>`;
            return;
        }

        confirmedStays.forEach(b => {
            const fallbackImg = b.image_url ? b.image_url : 'https://images.unsplash.com/photo-1566073771259-6a8506099945?auto=format&fit=crop&w=600&q=80';
            const card = document.createElement('div');
            card.className = 'confirmed-stay-card';
            card.innerHTML = `
                <img src="${fallbackImg}" alt="Confirmed Stay">
                <div class="confirmed-card-info-box">
                    <span class="confirmed-card-ref">${b.booking_reference}</span>
                    <h3>${b.room_type ?? 'Luxury Accommodation Suite'}</h3>
                    <div class="confirmed-card-timeline-node">
                        <div><span>Check-In</span><strong>${formatClientDate(b.check_in_date)}</strong></div>
                        <div><span>Check-Out</span><strong>${formatClientDate(b.check_out_date)}</strong></div>
                    </div>
                    <div class="confirmed-card-footer">
                        <div><span>Total Settled</span><strong>₱${parseFloat(b.total_price).toLocaleString(undefined, {minimumFractionDigits: 2})}</strong></div>
                        <button type="button" class="inspect-btn" onclick='displayInspectDetailsModal(${JSON.stringify(b)})'>Inspect Slip</button>
                    </div>
                </div>
            `;
            showroomTarget.appendChild(card);
        });
    }

    function filterLedgerTarget(statusStr) {
        document.getElementById('ledger_view_title').innerText = `Your Reservation Ledger (${statusStr})`;
        document.querySelectorAll('.metric-card').forEach(c => c.classList.remove('metric-card--primary'));
        event.currentTarget.classList.add('metric-card--primary');
        if(statusStr === 'All') {
            renderActiveWorkspaceLedger(globalBookingsDataset);
        } else {
            const subset = globalBookingsDataset.filter(b => b.booking_status === statusStr);
            renderActiveWorkspaceLedger(subset);
        }
    }

    function requestBookingCancellation(refKeyStr) {
        if (!confirm("Are you sure you want to cancel reservation " + refKeyStr + " immediately? This cannot be undone.")) return;
        document.getElementById('cancel_form_target_ref').value = refKeyStr;
        document.getElementById('global_cancel_post_form').submit();
    }

    // NEW: opens the "Request Cancellation Review" modal for a given booking
    // reference (instead of an instant confirm() popup - this is a request
    // for a human to review, not a same-second action).
    let _pendingReviewRef = null;
    function openRequestReviewModal(refKeyStr) {
        _pendingReviewRef = refKeyStr;
        document.getElementById('review_modal_ref_display').innerText = refKeyStr;
        document.getElementById('review_reason_textarea').value = '';
        document.getElementById('request_review_modal').classList.add('active-modal');
    }

    function submitCancellationReviewRequest() {
        if (!_pendingReviewRef) return;
        const btn = document.getElementById('review_modal_submit_btn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...';
        document.getElementById('request_form_target_ref').value = _pendingReviewRef;
        document.getElementById('request_form_reason_hidden').value = document.getElementById('review_reason_textarea').value;
        document.getElementById('global_request_review_post_form').submit();
    }

    function displayInspectDetailsModal(data) {
        document.getElementById('m_ref_id').innerText = "Reservation: " + data.booking_reference;
        document.getElementById('m_in').innerText = formatClientDate(data.check_in_date);
        document.getElementById('m_out').innerText = formatClientDate(data.check_out_date);
        document.getElementById('m_requests').innerText = data.special_requests ? data.special_requests : "No custom requirements provided.";
        document.getElementById('m_total').innerText = "₱" + parseFloat(data.total_price).toLocaleString(undefined, {minimumFractionDigits: 2});

        const cutoffNotice = document.getElementById('m_cutoff_notice');
        const cutoffNoticeText = document.getElementById('m_cutoff_notice_text');
        const isCancellable = (data.booking_status === 'Pending' || data.booking_status === 'Confirmed');
        cutoffNotice.classList.toggle('is-visible', isCancellable && !data.can_self_cancel);
        if (cutoffNoticeText) {
            // NEW: same-day reservation+check-in gets its own message,
            // distinct from the generic 48h-post-checkin one.
            cutoffNoticeText.innerText = data.is_same_day_booking
                ? "the cancel is no longer active due to same day you reserve. Contact or request to admin if you want to cancel"
                : "Too close to check-in to cancel online. You can still submit a Request Cancellation Review below.";
        }

        document.getElementById('user_booking_inspect_modal').classList.add('active-modal');
    }

    function closeModal(id) { 
        document.getElementById(id).classList.remove('active-modal');
    }

    // Run layout render updates automatically upon parsing completed sequence bounds
    document.addEventListener("DOMContentLoaded", () => {
        renderActiveWorkspaceLedger(globalBookingsDataset);
        renderConfirmedStaysShowroom();
    });

    // ---- Inline rename form toggle ----
    (function () {
        const openBtn = document.getElementById('openRenameBtn');
        const cancelBtn = document.getElementById('cancelRenameBtn');
        const display = document.getElementById('heroGreetingDisplay');
        const form = document.getElementById('renameForm');
        if (!openBtn || !form || !display) return;

        openBtn.addEventListener('click', () => {
            display.style.display = 'none';
            form.style.display = 'flex';
            const firstInput = form.querySelector('input[name="new_first_name"]');
            if (firstInput) firstInput.focus();
        });

        if (cancelBtn) {
            cancelBtn.addEventListener('click', () => {
                form.style.display = 'none';
                display.style.display = 'block';
            });
        }
    })();

    // ---- Notification bell dropdown ----
    // Mirrors the implementation in book.php so both guest-facing pages behave
    // identically; if you fix a bug in one, fix it in the other too.
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