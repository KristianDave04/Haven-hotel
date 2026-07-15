<?php
session_start();

function send_json($payload) {
    if (ob_get_length() !== false) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit();
}

// Include separated database configuration logic layout
include 'config/db.php';

$message = "";

// Check if a secure user tracking session profile matrix is active
$is_logged_in = isset($_SESSION['user_id']);
$user_name = $is_logged_in ? $_SESSION['user_name'] : "Guest";

$host     = "localhost";
$db_user  = "root";
$db_pass  = "";
$db_name  = "haven_hotel";
$conn = new mysqli($host, $db_user, $db_pass, $db_name);

// ============================================================
// SCHEMA SELF-HEALING: cancellation_requests table.
// Same table dashboard.php's "Request Cancellation Review" writes
// to, so a guest can file a cancellation request for a confirmed
// room right from the Contact section without needing to visit
// the dashboard first. Safe to run every request.
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

// Handle Asynchronous AJAX Review Submission
if (isset($_POST['action']) && $_POST['action'] === 'submit_review') {
    if (!isset($_SESSION['user_id'])) {
        send_json(['status' => 'error', 'message' => 'Please log in to submit a review.']);
    }
    
    $user_id = (int)$_SESSION['user_id'];
    $rating = (int)$_POST['rating'];
    $review_text = strip_tags(trim($_POST['review_text']));
    
    // Read the access key input from the form, but don't throw an error if it's empty/ignored for now
    $master_key = isset($_POST['master_key']) ? trim($_POST['master_key']) : ''; 
    
    if (empty($review_text)) {
        send_json(['status' => 'error', 'message' => 'Review content cannot be left empty.']);
    }
    
    // Standard insert without master key constraint
    $stmt = $conn->prepare("INSERT INTO testimonials (user_id, rating, review_text) VALUES (?, ?, ?)");
    $stmt->bind_param("iis", $user_id, $rating, $review_text);
    
    if ($stmt->execute()) {
        $fullName = $_SESSION['user_name'] ?? 'Anonymous Guest';
        send_json([
            'status' => 'success',
            'id' => $conn->insert_id,
            'user_name' => htmlspecialchars($fullName),
            'rating' => $rating,
            'review_text' => htmlspecialchars($review_text),
            'date' => date('M d, Y')
        ]);
    } else {
        // Send back the direct SQL error message to your console log instead of masking it
        send_json(['status' => 'error', 'message' => 'SQL Error: ' . $stmt->error]);
    }
}

// ============================================================
// NEW: PROCESS "REQUEST CANCELLATION REVIEW" FROM THE CONTACT SECTION
// Same admin-review queue dashboard.php's Request Review button feeds -
// this just gives guests a second, equally valid entry point to reach it
// without leaving the homepage. Does NOT touch booking_status directly;
// admin_dashboard.php's Cancellation Requests panel resolves it.
// ============================================================
if (isset($_POST['action']) && $_POST['action'] === 'submit_cancellation_request') {
    if (!isset($_SESSION['user_id'])) {
        send_json(['status' => 'error', 'message' => 'Please log in to request a cancellation.']);
    }

    $c_user_id = (int)$_SESSION['user_id'];
    $target_reference = trim($_POST['booking_reference'] ?? '');
    $reason = strip_tags(trim($_POST['reason'] ?? ''));

    if (empty($target_reference)) {
        send_json(['status' => 'error', 'message' => 'Please select a reservation to cancel.']);
    }

    $verify_stmt = $conn->prepare("SELECT booking_id, booking_status FROM bookings WHERE booking_reference = ? AND user_id = ?");
    $verify_stmt->bind_param("si", $target_reference, $c_user_id);
    $verify_stmt->execute();
    $verify_res = $verify_stmt->get_result();

    if (!$verify_res || $verify_res->num_rows === 0) {
        send_json(['status' => 'error', 'message' => 'That reservation could not be found on your account.']);
    }

    $booking_data = $verify_res->fetch_assoc();
    $verify_stmt->close();

    if ($booking_data['booking_status'] !== 'Confirmed' && $booking_data['booking_status'] !== 'Pending') {
        send_json(['status' => 'error', 'message' => 'Only Pending or Confirmed reservations are eligible for a cancellation request.']);
    }

    $dupe_stmt = $conn->prepare("SELECT request_id FROM cancellation_requests WHERE booking_id = ? AND request_status = 'Pending'");
    $dupe_stmt->bind_param("i", $booking_data['booking_id']);
    $dupe_stmt->execute();
    $has_dupe = $dupe_stmt->get_result()->num_rows > 0;
    $dupe_stmt->close();

    if ($has_dupe) {
        send_json(['status' => 'error', 'message' => 'A cancellation review request for this reservation is already pending.']);
    }

    $ins_stmt = $conn->prepare("INSERT INTO cancellation_requests (booking_id, user_id, booking_reference, reason) VALUES (?, ?, ?, ?)");
    $ins_stmt->bind_param("iiss", $booking_data['booking_id'], $c_user_id, $target_reference, $reason);

    if ($ins_stmt->execute()) {
        $admin_alert_msg = "Guest " . ($_SESSION['user_name'] ?? 'A guest') . " has requested cancellation review for reservation [ $target_reference ] via the Contact form.";
        $log_stmt = $conn->prepare("INSERT INTO notifications (message) VALUES (?)");
        $log_stmt->bind_param("s", $admin_alert_msg);
        $log_stmt->execute();
        $log_stmt->close();

        $ins_stmt->close();
        send_json(['status' => 'success', 'message' => "Your cancellation review request for $target_reference has been submitted. Our team will respond shortly."]);
    } else {
        $ins_stmt->close();
        send_json(['status' => 'error', 'message' => 'We could not submit your request right now. Please try again.']);
    }
}

// --------------------------------------------------------
// DATA PIPELINE: ASSEMBLE ACTIVE REAL-TIME ROOM INVENTORY
// Rebuilt to match book.php's exact fields (floor, live availability
// computed the same way, per-room image pool) so the homepage
// Accommodations section is a true mirror of the booking grid rather
// than a separately-drifting summary of it.
// --------------------------------------------------------
$room_image_pool = [
    "https://images.unsplash.com/photo-1611892440504-42a792e24d32?q=80&w=600&auto=format&fit=crop",
    "https://images.unsplash.com/photo-1590490360182-c33d57733427?q=80&w=600&auto=format&fit=crop",
    "https://images.unsplash.com/photo-1566665797739-1674de7a421a?q=80&w=600&auto=format&fit=crop",
    "https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?q=80&w=600&auto=format&fit=crop",
    "https://images.unsplash.com/photo-1578683010236-d716f9a3f461?q=80&w=600&auto=format&fit=crop",
    "https://images.unsplash.com/photo-1542314831-068cd1dbfeeb?q=80&w=600&auto=format&fit=crop",
    "https://images.unsplash.com/photo-1631049307264-da0ec9d70304?q=80&w=600&auto=format&fit=crop",
    "https://images.unsplash.com/photo-1512918728675-ed5a9ecdebfd?q=80&w=600&auto=format&fit=crop"
];

$display_rooms = [];
$rooms_query = $conn->query("
    SELECT r.*,
    (SELECT COUNT(*) FROM bookings b WHERE b.room_id = r.room_id AND b.booking_status IN ('Pending', 'Confirmed')) as active_bookings
    FROM rooms r ORDER BY r.price_per_night ASC
");

if ($rooms_query && $rooms_query->num_rows > 0) {
    $idx = 0;
    while ($r = $rooms_query->fetch_assoc()) {
        $r_img = !empty($r['image_url']) ? $r['image_url'] : ($room_image_pool[$idx % count($room_image_pool)]);
        $r_floor = $r['floor'] ?? '1st Floor';

        // Same live-availability math as book.php / admin_dashboard.php:
        // total_inventory minus currently active (Pending/Confirmed) bookings.
        $max_capacity = isset($r['total_inventory']) ? (int)$r['total_inventory'] : 5;
        $current_available = max(0, $max_capacity - (int)$r['active_bookings']);

        $display_rooms[] = [
            "id"        => $r['room_id'],
            "number"    => $r['room_number'],
            "floor"     => $r_floor,
            "type"      => $r['room_type'],
            "price"     => (float)$r['price_per_night'],
            "status"    => ($current_available > 0) ? ($r['status'] ?? 'Available') : 'Fully Booked',
            "available" => $current_available,
            "desc"      => $r['description'] ?? "Experience baseline premium living with standard amenities.",
            "image"     => $r_img
        ];
        $idx++;
    }
}

// Fetch all approved testimonials from database
$reviews_query = "SELECT t.*, CONCAT(u.first_name, ' ', u.last_name) AS guest_name FROM testimonials t LEFT JOIN users u ON t.user_id = u.id ORDER BY t.created_at DESC";
$reviews_result = $conn->query($reviews_query);
$latest_review_id = 0;
$reviews_for_json = [];
if ($reviews_result) {
    while ($rv = $reviews_result->fetch_assoc()) {
        $reviews_for_json[] = $rv;
        if ((int)$rv['id'] > $latest_review_id) $latest_review_id = (int)$rv['id'];
    }
}
// Re-seek the result set (already consumed above) so the PHP render loop
// further down the page can still walk it as before.
$reviews_result = $conn->query($reviews_query);

// This guest's own Pending/Confirmed bookings, for the Contact section's
// cancellation-request dropdown. Guests only ever see and can act on their
// own reservations - verified again server-side on submit regardless.
$my_cancelable_bookings = [];
if ($is_logged_in) {
    $mb_stmt = $conn->prepare("SELECT booking_reference, room_type, check_in_date, check_out_date, booking_status FROM bookings WHERE user_id = ? AND booking_status IN ('Pending','Confirmed') ORDER BY check_in_date ASC");
    $mb_stmt->bind_param("i", $_SESSION['user_id']);
    $mb_stmt->execute();
    $mb_res = $mb_stmt->get_result();
    while ($mb = $mb_res->fetch_assoc()) {
        $my_cancelable_bookings[] = $mb;
    }
    $mb_stmt->close();
}

if(isset($_POST['book_now'])){

    $fullname = mysqli_real_escape_string($conn, $_POST['fullname']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $room_type = mysqli_real_escape_string($conn, $_POST['room_type']);
    $checkin = $_POST['checkin'];
    $checkout = $_POST['checkout'];
    $guests = $_POST['guests'];

    // If logged in, grab user id to prevent structural isolation anomalies
    $user_id_val = $is_logged_in ? (int)$_SESSION['user_id'] : "NULL";

    // Standard structural schema format map query layout context sequence
    $insert = "INSERT INTO bookings (
        user_id,
        booking_reference,
        room_type,
        check_in_date,
        check_out_date,
        guests,
        total_price,
        booking_status
    ) VALUES (
        " . ($is_logged_in ? $user_id_val : "0") . ",
        'BKG-" . rand(10000000, 99999999) . "',
        '$room_type',
        '$checkin',
        '$checkout',
        '$guests',
        5000.00,
        'Pending'
    )";

    if(mysqli_query($conn, $insert)){
        $message = "Reservation Successfully Submitted!";
    }else{
        $message = "Booking Failed: " . mysqli_error($conn);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Haven Hotel | Luxury & Comfort</title>

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/aos@2.3.4/dist/aos.css"/>
    <link rel="stylesheet" href="ui/style.css">
        <style>
        /* =========================================================================
           PROFILE ACTION MATRIX NAVIGATION DROPDOWN CSS (CLICK INITIALIZED)
        ========================================================================= */
        .nav-actions-group {
            display: flex;
            align-items: center;
            gap: 20px;
            position: relative;
        }

        .profile-dropdown-wrapper {
            position: relative;
            display: inline-block;
        }

        .profile-avatar-trigger {
            background: transparent;
            border: 1px solid rgba(255, 255, 255, 0.25);
            color: white;
            width: 42px;
            height: 42px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
            outline: none;
        }

        /* Active styling applied when menu is open */
        .profile-avatar-trigger:focus,
        .profile-avatar-trigger.active {
            border-color: #d4af37;
            color: #d4af37;
            background: rgba(198, 156, 79, 0.05);
        }

        .profile-dropdown-menu {
            position: absolute;
            top: 130%;
            right: 0;
            width: 200px;
            background: #ffffff;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            border: 1px solid #f2eade;
            padding: 12px 0;
            
            /* Hidden state defaults */
            display: none; 
            opacity: 0;
            transform: translateY(10px);
            transition: opacity 0.2s ease, transform 0.2s ease;
            z-index: 1100;
            text-align: left;
        }

        /* The JavaScript toggles this class to show the menu */
        .profile-dropdown-menu.show {
            display: block;
            opacity: 1;
            transform: translateY(0);
        }

        .dropdown-user-meta {
            padding: 8px 20px 12px 20px;
        }

        .dropdown-user-meta .user-greeting {
            font-size: 11px;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0;
        }

        .dropdown-user-meta .user-profile-name {
            font-size: 15px;
            font-weight: 600;
            color: #111;
            margin-top: 2px;
            font-family: 'Poppins', sans-serif;
        }

        .dropdown-divider {
            border: 0;
            height: 1px;
            background: #f2eade;
            margin: 4px 0 8px 0;
        }

        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 20px;
            color: #555;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .dropdown-item i {
            font-size: 14px;
            color: #888;
            width: 16px;
            text-align: center;
        }

        .dropdown-item:hover {
            background: #FAF8F5;
            color: #c69c4f;
        }

        .dropdown-item:hover i {
            color: #c69c4f;
        }

        .dropdown-item.logout-action:hover {
            background: #fff5f5;
            color: #ef4444;
        }

        .dropdown-item.logout-action:hover i {
            color: #ef4444;
        }

        .guest-login-link {
            color: #ffffff;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: color 0.3s ease;
        }
        
        .guest-login-link:hover {
            color: #c69c4f;
        }

        /* style.css */

*{
    margin:0;
    padding:0;
    box-sizing:border-box;
    scroll-behavior:smooth;
}

body{
    font-family:'Poppins',sans-serif;
    background:#f8f5ef;
    color:#111;
}

/* NAVBAR */

.navbar{
    position:fixed;
    top:0;
    width:100%;
    padding:20px 8%;
    display:flex;
    justify-content:space-between;
    align-items:center;
    z-index:1000;
    background:rgba(0,0,0,0.3);
    backdrop-filter:blur(12px);
}

.logo{
    color:white;
    font-size:30px;
    font-weight:700;
    font-family:'Playfair Display',serif;
}

.logo span{
    color:#d4af37;
}

.nav-links{
    display:flex;
    gap:35px;
    list-style:none;
}

.nav-links a{
    color:white;
    text-decoration:none;
    font-weight:500;
    transition:.3s;
}

.nav-links a:hover{
    color:#d4af37;
}

.nav-btn{
    background:#d4af37;
    color:white;
    padding:12px 24px;
    border-radius:30px;
    text-decoration:none;
    transition:.3s;
}

.nav-btn:hover{
    transform:translateY(-3px);
}



/* HERO */

.hero{
    height:100vh;
    background:url('https://images.unsplash.com/photo-1566073771259-6a8506099945?q=80&w=1600&auto=format&fit=crop') center/cover;
    position:relative;
    display:flex;
    align-items:center;
    justify-content:center;
    text-align:center;
}

.hero-overlay{
    position:absolute;
    inset:0;
    background:rgba(0,0,0,0.5);
}

.hero-content{
    position:relative;
    z-index:2;
    color:white;
    max-width:800px;
    padding:20px;
}

.hero-content h1{
    font-size:70px;
    margin-bottom:20px;
    font-family:'Playfair Display',serif;
}

.hero-content p{
    font-size:18px;
    margin-bottom:35px;
    line-height:1.8;
}

.hero-buttons{
    display:flex;
    justify-content:center;
    gap:20px;
}

.btn-primary,
.btn-secondary{
    padding:15px 32px;
    border-radius:40px;
    text-decoration:none;
    font-weight:600;
    transition:.3s;
}

.btn-primary{
    background:#d4af37;
    color:white;
}

.btn-secondary{
    border:2px solid white;
    color:white;
}

.btn-primary:hover,
.btn-secondary:hover{
    transform:translateY(-5px);
}

/* FEATURES */

.features{
    padding:100px 8%;
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(250px,1fr));
    gap:30px;
}

.feature-card{
    background:white;
    padding:40px;
    border-radius:25px;
    text-align:center;
    transition:.3s;
    box-shadow:0 10px 30px rgba(0,0,0,0.08);
}

.feature-card:hover{
    transform:translateY(-10px);
}

.feature-card i{
    font-size:45px;
    color:#d4af37;
    margin-bottom:20px;
}

/* ABOUT */

.about{
    padding:100px 8%;
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:60px;
    align-items:center;
}

.about-image img{
    width:100%;
    border-radius:30px;
}

.about-content span,
.section-title span{
    color:#d4af37;
    letter-spacing:2px;
    font-weight:600;
}

.about-content h2,
.section-title h2{
    font-size:50px;
    margin:20px 0;
    font-family:'Playfair Display',serif;
}

.about-content p{
    line-height:1.9;
    color:#555;
}

.about-stats{
    display:flex;
    gap:40px;
    margin-top:35px;
}

.about-stats h3{
    font-size:40px;
    color:#d4af37;
}

/* ROOMS SECTION SHELL */

.rooms{
    padding:100px 8%;
}

.section-title{
    text-align:center;
    margin-bottom:60px;
}

/* ================================================================
   ACCOMMODATIONS GRID — mirrors book.php's room-selection grid
   (same .room-unit-card / .room-card-image-box / .badge-pill /
   .floor-filter-chip vocabulary) so the homepage Accommodations
   section and the booking page Step 1 grid are visually identical,
   not two independently-drifting layouts.
   ================================================================ */

.floor-filter-row{
    display:flex;
    gap:10px;
    margin-bottom:30px;
    flex-wrap:wrap;
    justify-content:center;
}

.floor-filter-chip{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:10px 20px;
    border-radius:999px;
    border:1px solid #e2e2e2;
    background:#ffffff;
    color:#64748b;
    font-size:13px;
    font-weight:600;
    cursor:pointer;
    transition:all .2s ease;
    font-family:'Poppins',sans-serif;
}
.floor-filter-chip:hover{ border-color:#d4af37; color:#111827; }
.floor-filter-chip.active-floor-chip{ background:#111827; border-color:#111827; color:#ffffff; }
.floor-filter-chip.active-floor-chip i{ color:#d4af37; }

.room-grid-mesh{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(320px,1fr));
    gap:32px;
}

.room-unit-card{
    background:#ffffff;
    border:1px solid #e5e0d5;
    border-radius:24px;
    overflow:hidden;
    box-shadow:0 10px 30px rgba(0,0,0,0.06);
    transition:transform .3s cubic-bezier(0.4,0,0.2,1), box-shadow .3s ease;
    display:flex;
    flex-direction:column;
}
.room-unit-card:hover{
    transform:translateY(-8px);
    box-shadow:0 20px 40px rgba(0,0,0,0.1);
}

.room-card-image-box{ position:relative; }
.room-card-image-box img{
    width:100%;
    height:230px;
    object-fit:cover;
    display:block;
    transition:transform .5s ease;
}
.room-unit-card:hover .room-card-image-box img{ transform:scale(1.04); }

.room-card-floating-badge{
    position:absolute;
    top:14px;
    left:14px;
    box-shadow:0 2px 8px rgba(0,0,0,0.15);
}

.badge-pill{
    padding:6px 13px;
    border-radius:20px;
    font-size:11px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:0.5px;
}
.pill-ok{ background:#e6f4ea; color:#137333; }
.pill-alert{ background:#fef7e0; color:#b06000; }
.pill-soldout{ background:#fce8e6; color:#c5221f; }

.room-card-details-box{ padding:24px; display:flex; flex-direction:column; flex-grow:1; }

.room-meta-title{
    font-size:20px;
    font-weight:700;
    color:#111827;
    font-family:'Playfair Display',serif;
}
.room-meta-line{
    color:#64748b;
    font-size:13px;
    margin-bottom:6px;
}
.room-meta-line i{ color:#c6a973; width:14px; }
.room-meta-floor i{ color:#d4af37; }
.room-meta-desc{
    color:#666;
    font-size:13.5px;
    line-height:1.6;
    margin:12px 0 18px;
}

.room-card-bottom-row{
    display:flex;
    justify-content:space-between;
    align-items:center;
    border-top:1px solid #f1f1f1;
    padding-top:16px;
    margin-top:auto;
}
.room-card-rate-label{
    display:block;
    font-size:10.5px;
    color:#94a3b8;
    text-transform:uppercase;
    font-weight:700;
    letter-spacing:0.4px;
}
.room-card-rate-value{
    font-size:21px;
    color:#111827;
    font-family:'Playfair Display',serif;
}
.room-card-rate-value span{
    font-size:11px;
    color:#94a3b8;
    font-weight:500;
    font-family:'Poppins',sans-serif;
}
.room-card-avail-chip{
    font-size:12px;
    color:#475569;
    background:#f8fafc;
    padding:6px 12px;
    border-radius:8px;
    border:1px solid #e2e8f0;
    font-weight:600;
    white-space:nowrap;
}

.room-card-book-btn{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    margin-top:16px;
    padding:13px;
    border-radius:14px;
    text-decoration:none;
    font-weight:600;
    font-size:14px;
    transition:.25s;
}
.btn-gold{ background:#d4af37; color:#ffffff; }
.btn-gold:hover{ background:#b58b40; }

.no-rooms-floor-msg{
    display:none;
    align-items:center;
    justify-content:center;
    flex-direction:column;
    gap:10px;
    text-align:center;
    padding:50px;
    background:white;
    border:1px dashed #e2e8f0;
    border-radius:20px;
    color:#94a3b8;
    font-size:14px;
}
.no-rooms-floor-msg i{ font-size:26px; }

/* ================================================================
   TESTIMONIALS — polished + real-time polling indicator
   ================================================================ */

.testimonials-section{
    padding:100px 8%;
    background:#fafafa;
}
.testimonials-inner{
    max-width:1100px;
    margin:0 auto;
    text-align:center;
}
.testimonials-eyebrow{
    color:#c69c4f;
    font-weight:600;
    text-transform:uppercase;
    letter-spacing:2px;
    font-size:13px;
}
.testimonials-heading{
    font-family:'Playfair Display',serif;
    font-size:38px;
    margin:10px 0 12px;
    color:#111;
}
.testimonials-live-indicator{
    display:inline-flex;
    align-items:center;
    gap:8px;
    font-size:12.5px;
    color:#94a3b8;
    font-weight:600;
    margin-bottom:40px;
}
.live-dot{
    width:8px;
    height:8px;
    border-radius:50%;
    background:#16a34a;
    display:inline-block;
    animation:liveDotPulse 1.8s ease-in-out infinite;
}
@keyframes liveDotPulse{
    0%,100%{ opacity:1; transform:scale(1); box-shadow:0 0 0 0 rgba(22,163,74,0.4); }
    50%{ opacity:0.7; transform:scale(1.15); box-shadow:0 0 0 5px rgba(22,163,74,0); }
}

.reviews-feed-grid{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(300px,1fr));
    gap:24px;
    margin-bottom:50px;
}

.review-card{
    background:white;
    padding:30px;
    border-radius:16px;
    box-shadow:0 4px 20px rgba(0,0,0,0.03);
    border:1px solid #f0e6d6;
    text-align:left;
    display:flex;
    flex-direction:column;
    justify-content:space-between;
    transition:background-color 1s ease, border-color .4s ease;
}
.review-card--incoming{
    background:#fdf8ee;
    border-color:#d4af37;
    animation:reviewSlideIn .5s ease;
}
@keyframes reviewSlideIn{
    from{ opacity:0; transform:translateY(-14px); }
    to{ opacity:1; transform:translateY(0); }
}
.review-card-stars{ color:#c69c4f; margin-bottom:15px; }
.review-card-text{ color:#555; font-size:14px; line-height:1.6; font-style:italic; }
.review-card-footer{
    margin-top:20px;
    border-top:1px solid #f2f2f2;
    padding-top:15px;
    display:flex;
    justify-content:space-between;
    align-items:center;
}
.review-card-footer strong{ color:#222; font-size:15px; }
.review-card-footer span{ font-size:12px; color:#999; }
.no-reviews-msg{
    grid-column:1/-1;
    color:#888;
    font-style:italic;
}

.review-form-card{
    max-width:600px;
    margin:0 auto;
    background:white;
    padding:40px;
    border-radius:20px;
    box-shadow:0 10px 40px rgba(0,0,0,0.04);
    border:1px solid #eadecc;
    text-align:left;
}
.review-form-title{
    font-family:'Playfair Display',serif;
    font-size:22px;
    margin-bottom:20px;
    color:#222;
}
.review-form-field{ margin-bottom:20px; }
.review-form-field label{
    display:block;
    font-size:13px;
    font-weight:600;
    text-transform:uppercase;
    margin-bottom:8px;
    color:#555;
}
.review-star-selector{
    display:flex;
    gap:8px;
    color:#cbd5e1;
    font-size:22px;
    cursor:pointer;
}
.review-star-selector .selector-star{ color:#cbd5e1; transition:color .15s ease; }
.review-star-selector .selector-star.is-active{ color:#c69c4f; }
.review-form-field textarea,
.review-form-field select{
    width:100%;
    box-sizing:border-box;
    padding:14px;
    border:1px solid #cbd5e1;
    border-radius:8px;
    outline:none;
    font-family:inherit;
    font-size:14px;
    resize:vertical;
}
.review-form-field select{ cursor:pointer; background:white; }
.review-form-submit{
    background:#c69c4f;
    color:white;
    border:none;
    padding:14px 28px;
    font-size:14px;
    font-weight:600;
    border-radius:8px;
    cursor:pointer;
    transition:background .2s;
    width:100%;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:8px;
}
.review-form-submit:hover{ background:#b58b40; }
.review-form-submit:disabled{ background:#cbd5e1; cursor:not-allowed; }
.review-form-guest-prompt{ text-align:center; padding:20px 0; }
.review-form-guest-prompt p{ color:#666; font-size:15px; margin-bottom:20px; }
.review-form-guest-prompt a{
    display:inline-block;
    background:#111;
    color:white;
    text-decoration:none;
    padding:12px 24px;
    font-size:14px;
    font-weight:600;
    border-radius:6px;
}

/* ================================================================
   CONTACT — general info + new Cancellation Request form
   ================================================================ */

.contact-section{
    padding:100px 8%;
    background:#f8f5ef;
}
.contact-grid{
    display:grid;
    grid-template-columns:1fr 1.3fr;
    gap:32px;
    max-width:1100px;
    margin:0 auto;
    align-items:start;
}

.contact-info-card,
.contact-cancel-card{
    background:white;
    border-radius:20px;
    padding:36px;
    box-shadow:0 10px 30px rgba(0,0,0,0.06);
    border:1px solid #eee2cf;
}
.contact-info-card h3,
.contact-cancel-card h3{
    font-family:'Playfair Display',serif;
    font-size:21px;
    margin-bottom:20px;
    color:#111;
    display:flex;
    align-items:center;
    gap:10px;
}
.contact-info-row{
    display:flex;
    align-items:center;
    gap:12px;
    color:#555;
    font-size:14.5px;
    margin-bottom:16px;
}
.contact-info-row i{ color:#d4af37; width:18px; }
.contact-socials{
    display:flex;
    gap:14px;
    font-size:18px;
    color:#111;
    margin-top:20px;
}

.contact-cancel-intro{
    color:#666;
    font-size:13.5px;
    line-height:1.7;
    margin-bottom:24px;
}
.contact-cancel-guest-prompt{
    text-align:center;
    padding:24px 10px;
    background:#faf8f5;
    border-radius:14px;
    border:1px dashed #e6ded2;
}
.contact-cancel-guest-prompt i{ font-size:24px; color:#d4af37; display:block; margin-bottom:12px; }
.contact-cancel-guest-prompt p{ color:#666; font-size:14px; margin-bottom:16px; }
.contact-cancel-guest-prompt a{
    display:inline-block;
    background:#111;
    color:white;
    text-decoration:none;
    padding:11px 22px;
    font-size:13.5px;
    font-weight:600;
    border-radius:6px;
}
.contact-cancel-feedback{
    margin-top:14px;
    padding:12px 14px;
    border-radius:10px;
    font-size:13px;
    font-weight:500;
    display:none;
}
.contact-cancel-feedback.is-success{ display:block; background:#f0fdf4; color:#15803d; border:1px solid #bbf7d0; }
.contact-cancel-feedback.is-error{ display:block; background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; }

/* MAP / 3D OVERVIEW */

.location{
    padding:100px 8%;
}

.overview-subtitle{
    margin-top:14px;
    color:#64748b;
    font-size:15px;
    font-weight:500;
}

.map-container iframe{
    width:100%;
    height:500px;
    border:none;
    border-radius:30px;
}

/* ================================================================
   3D OVERVIEW VIEWER — replaces the flat map iframe with a modern,
   multi-scene interactive Three.js walkthrough of the hotel
   (Overview / Entrance / Reception / Hallway / Suite / Restaurant /
   Pool & Terrace / Spa), with scene tabs, a per-scene info panel,
   and a fullscreen toggle.
   ================================================================ */
.map-container{
    position:relative;
}

/* Scene switcher tabs — same pill/chip language as .floor-filter-chip
   used elsewhere on the page, so the new control feels native to the
   rest of the site rather than bolted on. Centered with a max-width
   so 8 chips read as an organized grid rather than a scattered wrap
   on very wide screens. */
.scene-tab-row{
    display:flex;
    gap:8px;
    margin:0 auto 18px;
    max-width:920px;
    flex-wrap:wrap;
    justify-content:center;
}

.scene-tab-chip{
    display:inline-flex;
    align-items:center;
    gap:7px;
    padding:9px 18px;
    border-radius:999px;
    border:1px solid #e2e2e2;
    background:#ffffff;
    color:#64748b;
    font-size:12.5px;
    font-weight:600;
    cursor:pointer;
    transition:all .2s ease;
    font-family:'Poppins',sans-serif;
    white-space:nowrap;
}
.scene-tab-chip:hover{ border-color:#d4af37; color:#111827; transform:translateY(-1px); }
.scene-tab-chip.active-scene-chip{ background:#111827; border-color:#111827; color:#ffffff; }
.scene-tab-chip.active-scene-chip i{ color:#d4af37; }

.hotel-3d-viewer{
    width:100%;
    height:560px;
    border-radius:30px;
    overflow:hidden;
    background:linear-gradient(to bottom, #bfe3f2, #e8f3f7);
    box-shadow:0 15px 50px rgba(0,0,0,0.16);
    position:relative;
    transition:border-radius .25s ease;
}

.hotel-3d-viewer canvas{
    display:block;
    width:100% !important;
    height:100% !important;
    touch-action:none;
}

.hotel-3d-loading{
    position:absolute;
    inset:0;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    gap:14px;
    color:#64748b;
    font-size:14px;
    font-weight:600;
    background:linear-gradient(to bottom, #bfe3f2, #e8f3f7);
    z-index:2;
}

.hotel-3d-loading i{
    font-size:32px;
    color:#d4af37;
}

.hotel-3d-controls-hint{
    position:absolute;
    bottom:18px;
    left:50%;
    transform:translateX(-50%);
    background:rgba(17,17,17,0.65);
    color:#fff;
    padding:8px 18px;
    border-radius:30px;
    font-size:12.5px;
    font-weight:500;
    backdrop-filter:blur(6px);
    pointer-events:none;
    white-space:nowrap;
}

.hotel-3d-controls-hint i{
    color:#d4af37;
}

/* Scene label badge — top-left overlay identifying the active view */
.hotel-3d-scene-label{
    position:absolute;
    top:18px;
    left:18px;
    display:flex;
    align-items:center;
    gap:10px;
    background:rgba(17,17,17,0.6);
    color:#fff;
    padding:9px 16px;
    border-radius:30px;
    font-size:13px;
    font-weight:600;
    backdrop-filter:blur(6px);
    z-index:3;
    pointer-events:none;
    max-width:calc(100% - 140px);
}
.hotel-3d-scene-label i{ color:#d4af37; font-size:14px; flex-shrink:0; }
.hotel-3d-scene-label span{ overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

/* Top-right overlay button cluster — fullscreen + info toggle sit
   side by side, same circular chip treatment for visual consistency. */
.hotel-3d-btn-cluster{
    position:absolute;
    top:18px;
    right:18px;
    display:flex;
    gap:10px;
    z-index:4;
}

.hotel-3d-fullscreen-btn,
.hotel-3d-info-btn{
    width:42px;
    height:42px;
    border-radius:50%;
    border:1px solid rgba(255,255,255,0.35);
    background:rgba(17,17,17,0.55);
    color:#ffffff;
    font-size:15px;
    display:flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
    backdrop-filter:blur(6px);
    transition:all .2s ease;
}
.hotel-3d-fullscreen-btn:hover,
.hotel-3d-info-btn:hover{
    background:#d4af37;
    border-color:#d4af37;
    color:#111;
    transform:scale(1.06);
}
.hotel-3d-info-btn.is-active{
    background:#d4af37;
    border-color:#d4af37;
    color:#111;
}

/* Per-scene info panel — slides in from the right edge of the
   viewer without covering the whole canvas, so guests can keep
   orbiting while reading. Closed by default; opened via the info
   toggle button, and re-rendered fresh on every scene switch. */
.hotel-3d-info-panel{
    position:absolute;
    top:0;
    right:0;
    bottom:0;
    width:280px;
    max-width:78%;
    background:rgba(17,15,12,0.82);
    backdrop-filter:blur(10px);
    color:#f5f1e8;
    padding:76px 22px 22px;
    box-sizing:border-box;
    transform:translateX(100%);
    transition:transform .32s cubic-bezier(.4,0,.2,1);
    z-index:3;
    overflow-y:auto;
}
.hotel-3d-info-panel.is-open{
    transform:translateX(0);
}

.hotel-3d-info-header{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:10px;
    margin-bottom:6px;
}

.hotel-3d-info-panel h4{
    font-family:'Playfair Display',serif;
    font-size:22px;
    font-weight:700;
    color:#ffffff;
    margin:0;
    line-height:1.25;
}

.hotel-3d-info-close{
    width:28px;
    height:28px;
    flex-shrink:0;
    border-radius:50%;
    border:1px solid rgba(255,255,255,0.25);
    background:transparent;
    color:#f5f1e8;
    display:flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
    font-size:12px;
    transition:all .2s ease;
}
.hotel-3d-info-close:hover{
    background:#d4af37;
    border-color:#d4af37;
    color:#111;
}

.hotel-3d-info-tagline{
    font-size:13.5px;
    line-height:1.5;
    color:#d4c9b0;
    margin:0 0 20px;
    font-weight:500;
}

.hotel-3d-info-list{
    list-style:none;
    margin:0;
    padding:0;
    display:flex;
    flex-direction:column;
    gap:14px;
}
.hotel-3d-info-list li{
    display:flex;
    align-items:flex-start;
    gap:12px;
    font-size:13px;
    line-height:1.45;
    color:#f0ebe0;
}
.hotel-3d-info-list li i{
    color:#d4af37;
    font-size:15px;
    margin-top:2px;
    flex-shrink:0;
    width:16px;
    text-align:center;
}

/* Fullscreen mode — the browser Fullscreen API expands .map-container
   itself, so square off the corners and let the viewer fill the
   available space edge-to-edge while keeping tabs/hint usable. */
.map-container.is-fullscreen-3d{
    display:flex;
    flex-direction:column;
    justify-content:center;
    background:#0b0b0c;
    padding:24px;
}
.map-container.is-fullscreen-3d .hotel-3d-viewer{
    height:100%;
    flex:1;
    border-radius:16px;
}
.map-container.is-fullscreen-3d .scene-tab-row{
    margin-bottom:16px;
}

@media(max-width:600px){
    .hotel-3d-viewer{
        height:420px;
    }
    .hotel-3d-controls-hint{
        font-size:11px;
        padding:7px 14px;
        white-space:normal;
        text-align:center;
        width:88%;
    }
    .hotel-3d-scene-label{
        font-size:11.5px;
        padding:7px 12px;
        top:12px;
        left:12px;
        max-width:calc(100% - 108px);
    }
    .hotel-3d-btn-cluster{
        top:12px;
        right:12px;
        gap:8px;
    }
    .hotel-3d-fullscreen-btn,
    .hotel-3d-info-btn{
        width:36px;
        height:36px;
        font-size:13px;
    }
    .scene-tab-chip{
        padding:7px 14px;
        font-size:11.5px;
    }
    .hotel-3d-info-panel{
        width:100%;
        max-width:100%;
        padding:64px 18px 18px;
    }
    .hotel-3d-info-panel h4{
        font-size:19px;
    }
}
/* FOOTER */

.footer{
    background:#0f172a;
    color:white;
    padding:80px 8% 30px;
}

.footer-grid{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(250px,1fr));
    gap:40px;
}

.footer h3{
    margin-bottom:20px;
}

.footer p,
.footer a{
    color:#ccc;
    text-decoration:none;
    line-height:2;
}

.socials{
    display:flex;
    gap:15px;
    font-size:20px;
    margin-top:20px;
}

.footer-bottom{
    text-align:center;
    margin-top:50px;
    padding-top:20px;
    border-top:1px solid rgba(255,255,255,0.1);
}

/* RESPONSIVE */

@media(max-width:1100px){

    .about,
    .booking-container{
        grid-template-columns:1fr;
    }

    .contact-grid{
        grid-template-columns:1fr;
    }

}

@media(max-width:900px){

    .nav-links{
        display:none;
    }

    .hero-content h1{
        font-size:50px;
    }

    .booking-form{
        grid-template-columns:1fr;
    }

}

@media(max-width:600px){

    .hero-content h1{
        font-size:40px;
    }

    .hero-buttons{
        flex-direction:column;
    }

    .about-content h2,
    .section-title h2{
        font-size:36px;
    }

    .testimonials-heading{
        font-size:30px;
    }

}

.success-msg{
    grid-column:1/-1;
    background:#16a34a;
    color:white;
    padding:15px;
    border-radius:12px;
    text-align:center;
    font-weight:600;
}

/* ================================================================
   PROFILE ACCOUNT DROPDOWN (navbar)
   ================================================================ */
.nav-actions-group {
    display: flex;
    align-items: center;
    gap: 20px;
    position: relative;
}

.profile-dropdown-wrapper {
    position: relative;
    display: inline-block;
}

.profile-avatar-trigger {
    background: transparent;
    border: 1px solid rgba(255, 255, 255, 0.25);
    color: white;
    width: 42px;
    height: 42px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    cursor: pointer;
    transition: all 0.3s ease;
    outline: none;
}

.profile-avatar-trigger:focus,
.profile-avatar-trigger.active {
    border-color: #c69c4f;
    color: #c69c4f;
    background: rgba(198, 156, 79, 0.05);
}

.profile-dropdown-menu {
    position: absolute;
    top: 130%;
    right: 0;
    width: 200px;
    background: #ffffff;
    border-radius: 10px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
    border: 1px solid #f2eade;
    padding: 12px 0;
    display: none;
    opacity: 0;
    transform: translateY(10px);
    transition: opacity 0.2s ease, transform 0.2s ease;
    z-index: 1100;
    text-align: left;
}

.profile-dropdown-menu.show {
    display: block;
    opacity: 1;
    transform: translateY(0);
}

.dropdown-user-meta {
    padding: 8px 20px 12px 20px;
}

.dropdown-user-meta .user-greeting {
    font-size: 11px;
    color: #999;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin: 0;
}

.dropdown-user-meta .user-profile-name {
    font-size: 15px;
    font-weight: 600;
    color: #111;
    margin-top: 2px;
    font-family: 'Poppins', sans-serif;
}

.dropdown-divider {
    border: 0;
    height: 1px;
    background: #f2eade;
    margin: 4px 0 8px 0;
}

.dropdown-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 20px;
    color: #555;
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    transition: all 0.2s ease;
}

.dropdown-item i {
    font-size: 14px;
    color: #888;
    width: 16px;
    text-align: center;
}

.dropdown-item:hover {
    background: #FAF8F5;
    color: #c69c4f;
}

.dropdown-item:hover i {
    color: #c69c4f;
}

.dropdown-item.logout-action:hover {
    background: #fff5f5;
    color: #ef4444;
}

.dropdown-item.logout-action:hover i {
    color: #ef4444;
}

.guest-login-link {
    color: #ffffff;
    text-decoration: none;
    font-size: 14px;
    font-weight: 500;
    transition: color 0.3s ease;
}

.guest-login-link:hover {
    color: #c69c4f;
}

/* Status badge for room cards (legacy inline system retained for
   compatibility; the main Accommodations grid uses .badge-pill instead) */
.status-badge {
    position: absolute;
    top: 15px;
    right: 15px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    z-index: 10;
}
.status-badge.available { background: #dcfce7; color: #15803d; }
.status-badge.limited { background: #fef3c7; color: #d97706; }
.status-badge.not_available { background: #fee2e2; color: #b91c1c; }

</style>
    <!-- 3D OVERVIEW VIEWER: replaces the flat Google Maps embed with an
         interactive, drag-to-orbit 3D walkthrough of the Haven Hotel. -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
    
</head>
<body>

<header class="navbar">
    <div class="logo">Haven<span>Hotel</span></div>
    <nav>
        <ul class="nav-links">
            <li><a href="index.php">Home</a></li>
            <li><a href="about.php">About</a></li>
            <li><a href="#rooms">Accommodations</a></li>
            <li><a href="book.php">Booking</a></li>
            <li><a href="#overview">Overview</a></li>
            <li><a href="#contact">Contact</a></li>
        </ul>
    </nav>

    <div class="nav-actions-group">
        <a href="book.php" class="nav-btn">Book Now</a>

        <?php if ($is_logged_in): ?>
            <div class="profile-dropdown-wrapper">
                <button class="profile-avatar-trigger" id="profileDropdownBtn" aria-label="User Account Menu">
                    <i class="fa-regular fa-user"></i>
                </button>
                <div class="profile-dropdown-menu" id="profileDropdownMenu">
                    <div class="dropdown-user-meta">
                        <p class="user-greeting">Welcome,</p>
                        <p class="user-profile-name"><?= htmlspecialchars(explode(' ', $user_name)[0]); ?></p>
                    </div>
                    <hr class="dropdown-divider">
                    <a href="dashboard.php" class="dropdown-item">
                        <i class="fa-solid fa-gauge-high"></i> Dashboard
                    </a>
                    <a href="login.php" class="dropdown-item logout-action">
                        <i class="fa-solid fa-right-from-bracket"></i> Logout
                    </a>
                </div>
            </div>
        <?php else: ?>
            <a href="login.php" class="guest-login-link"><i class="fa-solid fa-arrow-right-to-bracket"></i> Login</a>
        <?php endif; ?>
    </div>
</header>

<section class="hero" id="home">
    <div class="hero-overlay"></div>
    <div class="hero-content" data-aos="fade-up">
        <h1>Luxury Redefined at Haven Hotel</h1>
        <p>Experience world-class hospitality, elegant suites, and unforgettable comfort in the heart of paradise.</p>
        <div class="hero-buttons">
            <a href="book.php" class="btn-primary">Book Your Stay</a>
            <a href="#rooms" class="btn-secondary">Explore Rooms</a>
        </div>
    </div>
</section>

<section class="features">
    <div class="feature-card" data-aos="fade-up">
        <i class="fa-solid fa-wifi"></i>
        <h3>Free WiFi</h3>
        <p>Fast and reliable internet access throughout the hotel.</p>
    </div>
    <div class="feature-card" data-aos="fade-up" data-aos-delay="100">
        <i class="fa-solid fa-spa"></i>
        <h3>Spa & Wellness</h3>
        <p>Relax and rejuvenate with premium spa treatments.</p>
    </div>
    <div class="feature-card" data-aos="fade-up" data-aos-delay="200">
        <i class="fa-solid fa-utensils"></i>
        <h3>Fine Dining</h3>
        <p>Enjoy gourmet dishes crafted by world-class chefs.</p>
    </div>
    <div class="feature-card" data-aos="fade-up" data-aos-delay="300">
        <i class="fa-solid fa-water-ladder"></i>
        <h3>Infinity Pool</h3>
        <p>Luxury poolside relaxation with breathtaking views.</p>
    </div>
</section>

<section class="about" id="about">
    <div class="about-image" data-aos="fade-right">
        <img src="https://images.unsplash.com/photo-1566073771259-6a8506099945?q=80&w=1600&auto=format&fit=crop" alt="About Haven Hotel Image Grid View">
    </div>
    <div class="about-content" data-aos="fade-left">
        <span>ABOUT HAVEN HOTEL</span>
        <h2>Experience Luxury Like Never Before</h2>
        <p>Haven Hotel is a modern luxury destination designed to provide comfort, elegance, and unforgettable hospitality. From premium suites to world-class services, every detail is crafted for your perfect stay.</p>
    </div>
</section>

<!-- ================================================================
     ACCOMMODATIONS — rebuilt to mirror book.php's Step 1 grid exactly:
     same floor filter chips, same .room-unit-card visual language,
     same live-availability math. "See all floor" = the All Floors chip
     plus per-floor chips below it, same behavior as booking page.
     ================================================================ -->
<section class="rooms" id="rooms">
    <div class="section-title" data-aos="fade-up">
        <span>OUR ACCOMMODATIONS</span>
        <h2>Luxury Rooms & Suites</h2>
    </div>

    <div class="floor-filter-row" data-aos="fade-up">
        <button type="button" class="floor-filter-chip active-floor-chip" data-floor="all" onclick="filterHomeRoomsByFloor('all', this)"><i class="fa-solid fa-layer-group"></i> All Floors</button>
        <button type="button" class="floor-filter-chip" data-floor="1st Floor" onclick="filterHomeRoomsByFloor('1st Floor', this)"><i class="fa-solid fa-building"></i> 1st Floor</button>
        <button type="button" class="floor-filter-chip" data-floor="2nd Floor" onclick="filterHomeRoomsByFloor('2nd Floor', this)"><i class="fa-solid fa-building"></i> 2nd Floor</button>
        <button type="button" class="floor-filter-chip" data-floor="3rd Floor" onclick="filterHomeRoomsByFloor('3rd Floor', this)"><i class="fa-solid fa-building"></i> 3rd Floor</button>
    </div>

    <div class="room-grid-mesh" id="home_room_grid_target">
        <?php if (!empty($display_rooms)): ?>
            <?php foreach ($display_rooms as $room): ?>
                <?php $isSoldOut = ($room['available'] <= 0); ?>
                <div class="room-unit-card" data-floor="<?= htmlspecialchars($room['floor']) ?>" data-aos="zoom-in">
                    <div class="room-card-image-box">
                        <img src="<?= htmlspecialchars($room['image']) ?>" alt="<?= htmlspecialchars($room['type']) ?> Room Thumbnail">
                        <span class="badge-pill room-card-floating-badge <?= $isSoldOut ? 'pill-soldout' : (($room['status'] === 'Available') ? 'pill-ok' : 'pill-alert') ?>">
                            <?= $isSoldOut ? 'Fully Booked' : htmlspecialchars($room['status']) ?>
                        </span>
                    </div>
                    <div class="room-card-details-box">
                        <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:10px;">
                            <h3 class="room-meta-title"><?= htmlspecialchars($room['type']) ?></h3>
                        </div>
                        <p class="room-meta-line"><i class="fa-solid fa-door-closed"></i> Room <strong><?= htmlspecialchars($room['number']) ?></strong></p>
                        <p class="room-meta-line room-meta-floor"><i class="fa-solid fa-building"></i> <?= htmlspecialchars($room['floor']) ?></p>
                        <p class="room-meta-desc"><?= htmlspecialchars($room['desc']) ?></p>

                        <div class="room-card-bottom-row">
                            <div>
                                <span class="room-card-rate-label">Nightly Rate</span>
                                <strong class="room-card-rate-value">₱<?= number_format($room['price'], 2) ?><span>/night</span></strong>
                            </div>
                            <span class="room-card-avail-chip">
                                <?= $isSoldOut ? 'Sold Out' : $room['available'] . ' Vacant' ?>
                            </span>
                        </div>

                        <a href="book.php?step=1" class="btn-action btn-gold room-card-book-btn" style="<?= ($room['status'] === 'Not Available' || $isSoldOut) ? 'opacity:0.5; pointer-events:none; cursor:not-allowed;' : '' ?>">
                            <?= ($room['status'] === 'Not Available' || $isSoldOut) ? 'Unavailable' : 'Book Now' ?> <i class="fa-solid fa-arrow-right"></i>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p style="grid-column: 1/-1; text-align: center; color: #888; font-style: italic; padding: 40px 0;">No room listings found in the active inventory pipeline matrix.</p>
        <?php endif; ?>
    </div>

    <div id="home_no_rooms_on_floor_msg" style="display:none;" class="no-rooms-floor-msg">
        <i class="fa-solid fa-door-closed"></i>
        No accommodations are currently configured on this floor.
    </div>
</section>

<section id="testimonials" class="testimonials-section">
    <div class="testimonials-inner">
        <span class="testimonials-eyebrow">GUEST EXPERIENCES</span>
        <h2 class="testimonials-heading">What Our Guests Say</h2>
        <p class="testimonials-live-indicator"><span class="live-dot"></span> Updating in real time</p>
        
        <div id="reviews_feed_grid" class="reviews-feed-grid">
            <?php if ($reviews_result && $reviews_result->num_rows > 0): ?>
                <?php while ($rev = $reviews_result->fetch_assoc()): ?>
                    <div class="review-card" data-review-id="<?= (int)$rev['id'] ?>">
                        <div>
                            <div class="review-card-stars">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <i class="<?= $i <= $rev['rating'] ? 'fa-solid' : 'fa-regular' ?> fa-star"></i>
                                <?php endfor; ?>
                            </div>
                            <p class="review-card-text">"<?= htmlspecialchars($rev['review_text']) ?>"</p>
                        </div>
                        <div class="review-card-footer">
                            <strong><?= htmlspecialchars($rev['guest_name'] ?? 'Anonymous Guest') ?></strong>
                            <span><?= date('M d, Y', strtotime($rev['created_at'])) ?></span>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <p id="no_reviews_msg" class="no-reviews-msg">Be the very first guest to leave an accommodation review trace below.</p>
            <?php endif; ?>
        </div>

        <div class="review-form-card">
            <h3 class="review-form-title">Share Your Experience</h3>
            
            <?php if (isset($_SESSION['user_id'])): ?>
                <form id="ajaxReviewSubmissionForm">
                    <input type="hidden" name="action" value="submit_review">
                    
                    <div class="review-form-field">
                        <label>Your Rating</label>
                        <div class="review-star-selector" id="star_rating_selector">
                            <i class="fa-solid fa-star selector-star" data-score="1"></i>
                            <i class="fa-solid fa-star selector-star" data-score="2"></i>
                            <i class="fa-solid fa-star selector-star" data-score="3"></i>
                            <i class="fa-solid fa-star selector-star" data-score="4"></i>
                            <i class="fa-solid fa-star selector-star" data-score="5"></i>
                        </div>
                        <input type="hidden" name="rating" id="hidden_rating_score" value="5">
                    </div>

                    <div class="review-form-field">
                        <label>Review Details</label>
                        <textarea name="review_text" rows="4" placeholder="How was your stay at Haven Hotel? Describe your experience..." required></textarea>
                    </div>

                    <button type="submit" class="review-form-submit">Post Review</button>
                </form>
            <?php else: ?>
                <div class="review-form-guest-prompt">
                    <p>You must be signed into your reservation account to write a testimonial ledger entry.</p>
                    <a href="login.php">Log In to Account</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="location" id="overview">
    <div class="section-title" data-aos="fade-up">
        <span>OVERVIEW</span>
        <h2>Explore Haven Hotel</h2>
        <p class="overview-subtitle">Step inside &mdash; drag to orbit, scroll to zoom, and switch between spaces below.</p>
    </div>

    <!-- ================================================================
         3D OVERVIEW VIEWER — interactive Three.js walkthrough of the
         Haven Hotel exterior and interior spaces. Guests can drag to
         orbit, scroll to zoom, switch between scenes with the tabs,
         open the info panel for highlights of each space, and expand
         to fullscreen. This is a stylized architectural render
         (procedurally built in-browser), not a photogrammetric scan of
         the real building, since no 3D scan asset exists for the hotel.
         ================================================================ -->
    <div class="map-container" data-aos="zoom-in">

        <div class="scene-tab-row" id="sceneTabRow" role="tablist" aria-label="Hotel 3D scenes">
            <button type="button" class="scene-tab-chip active-scene-chip" data-scene="overview" role="tab" aria-selected="true">
                <i class="fa-solid fa-building-columns"></i> Overview
            </button>
            <button type="button" class="scene-tab-chip" data-scene="entrance" role="tab" aria-selected="false">
                <i class="fa-solid fa-door-open"></i> Entrance
            </button>
            <button type="button" class="scene-tab-chip" data-scene="reception" role="tab" aria-selected="false">
                <i class="fa-solid fa-bell-concierge"></i> Reception
            </button>
            <button type="button" class="scene-tab-chip" data-scene="hallway" role="tab" aria-selected="false">
                <i class="fa-solid fa-door-closed"></i> Hallway
            </button>
            <button type="button" class="scene-tab-chip" data-scene="suite" role="tab" aria-selected="false">
                <i class="fa-solid fa-bed"></i> Suite
            </button>
            <button type="button" class="scene-tab-chip" data-scene="restaurant" role="tab" aria-selected="false">
                <i class="fa-solid fa-utensils"></i> Restaurant
            </button>
            <button type="button" class="scene-tab-chip" data-scene="pool" role="tab" aria-selected="false">
                <i class="fa-solid fa-umbrella-beach"></i> Pool &amp; Terrace
            </button>
            <button type="button" class="scene-tab-chip" data-scene="spa" role="tab" aria-selected="false">
                <i class="fa-solid fa-spa"></i> Spa
            </button>
        </div>

        <div id="hotel3DViewer" class="hotel-3d-viewer">
            <div class="hotel-3d-loading" id="hotel3DLoading">
                <i class="fa-solid fa-cube fa-spin"></i>
                <span>Loading 3D view&hellip;</span>
            </div>

            <div class="hotel-3d-btn-cluster">
                <button type="button" class="hotel-3d-info-btn" id="hotel3DInfoBtn" aria-label="Toggle scene info">
                    <i class="fa-solid fa-circle-info"></i>
                </button>
                <button type="button" class="hotel-3d-fullscreen-btn" id="hotel3DFullscreenBtn" aria-label="Toggle fullscreen">
                    <i class="fa-solid fa-expand"></i>
                </button>
            </div>

            <div class="hotel-3d-scene-label" id="hotel3DSceneLabel">
                <i class="fa-solid fa-building-columns"></i>
                <span>Overview &mdash; Exterior</span>
            </div>

            <div class="hotel-3d-info-panel" id="hotel3DInfoPanel"></div>
        </div>

        <div class="hotel-3d-controls-hint">
            <i class="fa-solid fa-arrows-up-down-left-right"></i> Drag to orbit &nbsp;&bull;&nbsp; <i class="fa-solid fa-magnifying-glass"></i> Scroll to zoom &nbsp;&bull;&nbsp; <i class="fa-solid fa-circle-info"></i> Tap for info
        </div>
    </div>
</section>

<!-- ================================================================
     CONTACT — general info retained, plus new "Request Cancellation
     of a Confirmed Room" form. Submits via AJAX to the same
     cancellation_requests queue admin_dashboard.php's new panel and
     dashboard.php's Request Review button both feed.
     ================================================================ -->
<section class="contact-section" id="contact">
    <div class="section-title" data-aos="fade-up">
        <span>GET IN TOUCH</span>
        <h2>Contact Haven Hotel</h2>
    </div>

    <div class="contact-grid" data-aos="fade-up">
        <div class="contact-info-card">
            <h3>Reach Us Directly</h3>
            <div class="contact-info-row"><i class="fa-solid fa-envelope"></i> havenhotel@gmail.com</div>
            <div class="contact-info-row"><i class="fa-solid fa-phone"></i> +63 912 345 6789</div>
            <div class="contact-info-row"><i class="fa-solid fa-location-dot"></i> Manila, Philippines</div>
            <div class="contact-socials">
                <i class="fa-brands fa-facebook-f"></i>
                <i class="fa-brands fa-instagram"></i>
                <i class="fa-brands fa-twitter"></i>
                <i class="fa-brands fa-youtube"></i>
            </div>
        </div>

        <div class="contact-cancel-card">
            <h3><i class="fa-solid fa-file-circle-question" style="color:#c69c4f;"></i> Request Cancellation of a Confirmed Room</h3>
            <p class="contact-cancel-intro">
                Need to cancel a reservation that's already Confirmed (or still Pending)? Submit a request below and
                our team will review it &mdash; this does not cancel the booking immediately.
            </p>

            <?php if (!$is_logged_in): ?>
                <div class="contact-cancel-guest-prompt">
                    <i class="fa-solid fa-lock"></i>
                    <p>Please log in to request a cancellation for one of your reservations.</p>
                    <a href="login.php">Log In to Account</a>
                </div>
            <?php elseif (empty($my_cancelable_bookings)): ?>
                <div class="contact-cancel-guest-prompt">
                    <i class="fa-solid fa-circle-check" style="color:#94a3b8;"></i>
                    <p>You don't have any Pending or Confirmed reservations eligible for cancellation right now.</p>
                    <a href="dashboard.php">View My Dashboard</a>
                </div>
            <?php else: ?>
                <form id="ajaxCancellationRequestForm">
                    <input type="hidden" name="action" value="submit_cancellation_request">

                    <div class="review-form-field">
                        <label>Select Reservation</label>
                        <select name="booking_reference" id="cancel_request_booking_select" required>
                            <option value="" disabled selected>Choose a reservation...</option>
                            <?php foreach ($my_cancelable_bookings as $mb): ?>
                                <option value="<?= htmlspecialchars($mb['booking_reference']) ?>">
                                    <?= htmlspecialchars($mb['booking_reference']) ?> &mdash; <?= htmlspecialchars($mb['room_type']) ?> (<?= htmlspecialchars($mb['booking_status']) ?>, check-in <?= date('M d, Y', strtotime($mb['check_in_date'])) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="review-form-field">
                        <label>Reason for Cancellation <span style="font-weight:500; color:#94a3b8;">(optional)</span></label>
                        <textarea name="reason" rows="3" placeholder="Let us know why you'd like to cancel..."></textarea>
                    </div>

                    <button type="submit" class="review-form-submit" id="cancel_request_submit_btn"><i class="fa-solid fa-paper-plane"></i> Submit Cancellation Request</button>
                    <div id="cancel_request_feedback" class="contact-cancel-feedback"></div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</section>

<footer class="footer">
    <div class="footer-bottom"> 
        &copy; 2026 Haven Hotel. All Rights Reserved.
    </div>
</footer>

<script src="https://unpkg.com/aos@2.3.4/dist/aos.js"></script>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        const dropdownBtn = document.getElementById("profileDropdownBtn");
        const dropdownMenu = document.getElementById("profileDropdownMenu");

        if (dropdownBtn && dropdownMenu) {
            dropdownBtn.addEventListener("click", function(event) {
                event.stopPropagation(); 
                dropdownMenu.classList.toggle("show");
                dropdownBtn.classList.toggle("active");
            });

            window.addEventListener("click", function(event) {
                if (!dropdownBtn.contains(event.target) && !dropdownMenu.contains(event.target)) {
                    dropdownMenu.classList.remove("show");
                    dropdownBtn.classList.remove("active");
                }
            });
        }
    });

    AOS.init({
        duration:1000,
        once:true
    });

    // ================================================================
    // 3D OVERVIEW VIEWER — procedurally-built Haven Hotel walkthrough.
    // Four switchable scenes (Overview / Entrance / Reception / Hallway),
    // each its own THREE.Group built once and toggled visible/hidden so
    // switching tabs is instant with no rebuild cost. Built directly with
    // the raw Three.js r128 API (no OrbitControls addon, which isn't
    // available as a standalone script for r128) via a small hand-rolled,
    // inertia-damped drag-to-orbit + scroll-to-zoom controller, plus a
    // native Fullscreen API toggle on the viewer container.
    // ================================================================
    (function initHotel3DViewer() {
        const mount = document.getElementById('hotel3DViewer');
        if (!mount) return;

        const loadingEl = document.getElementById('hotel3DLoading');

        function showViewerError(message) {
            if (!loadingEl) return;
            loadingEl.innerHTML = '<i class="fa-solid fa-triangle-exclamation" style="color:#d4af37;"></i><span>' + message + '</span>';
            loadingEl.style.display = 'flex';
        }

        if (typeof THREE === 'undefined') {
            // The three.js CDN script (loaded in <head>) never defined the
            // THREE global — most commonly because the CDN request was
            // blocked (ad-blocker, restrictive network/CSP, offline) or
            // hadn't finished loading yet. Surface this instead of leaving
            // guests staring at a spinner with no explanation.
            showViewerError('3D view unavailable &mdash; the 3D library failed to load. Please check your connection and refresh.');
            return;
        }

        try {

        const sceneLabelEl = document.getElementById('hotel3DSceneLabel');
        const fullscreenBtn = document.getElementById('hotel3DFullscreenBtn');
        const viewerShell = mount.closest('.map-container');

        // Some browser/GPU-driver combinations refuse to create an
        // antialiased WebGL context at all ("Error creating WebGL
        // context", seen even though WebGL itself is available) while a
        // plain context succeeds immediately. Try progressively safer
        // options rather than giving up on the first failure.
        function createRendererWithFallback() {
            const attempts = [
                { antialias: true, alpha: false },
                { antialias: false, alpha: false },
                { antialias: false, alpha: false, powerPreference: 'low-power', failIfMajorPerformanceCaveat: false }
            ];
            let lastError = null;
            for (let i = 0; i < attempts.length; i++) {
                try {
                    return new THREE.WebGLRenderer(attempts[i]);
                } catch (err) {
                    lastError = err;
                }
            }
            throw lastError || new Error('Unable to create a WebGL renderer.');
        }

        const renderer = createRendererWithFallback();
        renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
        renderer.setSize(mount.clientWidth, mount.clientHeight || 520);
        renderer.shadowMap.enabled = true;
        renderer.shadowMap.type = THREE.PCFSoftShadowMap;
        renderer.outputEncoding = THREE.sRGBEncoding;
        renderer.physicallyCorrectLights = false;
        renderer.toneMapping = THREE.ACESFilmicToneMapping;
        renderer.toneMappingExposure = 1.05;
        mount.appendChild(renderer.domElement);

        const camera = new THREE.PerspectiveCamera(45, mount.clientWidth / (mount.clientHeight || 520), 0.1, 500);

        // ---- Shared, reusable materials (kept high-end but lightweight),
        // plus reusable prop-builder helpers and all 8 scene constructors ----

        // ================================================================
        // SHARED MATERIALS — upgraded with clearcoat-style glass, physical
        // roughness/metalness pairings tuned per surface, and a couple of
        // new materials the added scenes need (poolwater, spa stone, linen).
        // ================================================================
        const MAT = {
            gold:        new THREE.MeshStandardMaterial({ color: 0xd4af37, roughness: 0.28, metalness: 0.75 }),
            goldDim:     new THREE.MeshStandardMaterial({ color: 0xb58b40, roughness: 0.42, metalness: 0.55 }),
            goldBrushed: new THREE.MeshStandardMaterial({ color: 0xc9a544, roughness: 0.55, metalness: 0.6 }),
            facade:      new THREE.MeshStandardMaterial({ color: 0xf5f1e8, roughness: 0.7, metalness: 0.04 }),
            facadeSoft:  new THREE.MeshStandardMaterial({ color: 0xece5d6, roughness: 0.82 }),
            glass:       new THREE.MeshPhysicalMaterial({ color: 0x8fc4e0, roughness: 0.06, metalness: 0.15, emissive: 0x1a3d4d, emissiveIntensity: 0.16, clearcoat: 1, clearcoatRoughness: 0.15, transparent: true, opacity: 0.88 }),
            glassWarm:   new THREE.MeshPhysicalMaterial({ color: 0xffe3ad, roughness: 0.1, metalness: 0.08, emissive: 0xffb347, emissiveIntensity: 0.6, clearcoat: 1, clearcoatRoughness: 0.1, transparent: true, opacity: 0.92 }),
            roof:        new THREE.MeshStandardMaterial({ color: 0x2b2f36, roughness: 0.88 }),
            door:        new THREE.MeshStandardMaterial({ color: 0x3b2a20, roughness: 0.5, metalness: 0.12 }),
            marbleLight: new THREE.MeshPhysicalMaterial({ color: 0xefe9dc, roughness: 0.22, metalness: 0.05, clearcoat: 0.5, clearcoatRoughness: 0.3 }),
            marbleDark:  new THREE.MeshPhysicalMaterial({ color: 0x2a2620, roughness: 0.2, metalness: 0.08, clearcoat: 0.5, clearcoatRoughness: 0.3 }),
            wallInterior:new THREE.MeshStandardMaterial({ color: 0xf3ece0, roughness: 0.88 }),
            wallAccent:  new THREE.MeshStandardMaterial({ color: 0x8a6a3d, roughness: 0.65 }),
            carpetRed:   new THREE.MeshStandardMaterial({ color: 0x6b1f2a, roughness: 0.95 }),
            wood:        new THREE.MeshStandardMaterial({ color: 0x4a3423, roughness: 0.55, metalness: 0.06 }),
            woodLight:   new THREE.MeshStandardMaterial({ color: 0x8a6947, roughness: 0.5, metalness: 0.04 }),
            trunk:       new THREE.MeshStandardMaterial({ color: 0x6b4a2f, roughness: 0.9 }),
            leaf:        new THREE.MeshStandardMaterial({ color: 0x3d7a3a, roughness: 0.8 }),
            leafDark:    new THREE.MeshStandardMaterial({ color: 0x2f5c2e, roughness: 0.85 }),
            fabricGold:  new THREE.MeshStandardMaterial({ color: 0xcda54a, roughness: 0.8 }),
            fabricCream: new THREE.MeshStandardMaterial({ color: 0xefe6d3, roughness: 0.85 }),
            chrome:      new THREE.MeshStandardMaterial({ color: 0xd8dde3, roughness: 0.15, metalness: 0.95 }),
            asphalt:     new THREE.MeshStandardMaterial({ color: 0x555a63, roughness: 0.95 }),
            plaza:       new THREE.MeshStandardMaterial({ color: 0xd8cdb8, roughness: 1 }),
            ceilingInterior: new THREE.MeshStandardMaterial({ color: 0xfaf7f0, roughness: 0.95 }),
            poolWater:   new THREE.MeshPhysicalMaterial({ color: 0x2fa9c9, roughness: 0.05, metalness: 0.1, transparent: true, opacity: 0.85, clearcoat: 1, clearcoatRoughness: 0.05 }),
            poolTile:    new THREE.MeshStandardMaterial({ color: 0x1c6d84, roughness: 0.3, metalness: 0.1 }),
            stoneWarm:   new THREE.MeshStandardMaterial({ color: 0xdcd0b8, roughness: 0.85 }),
            linen:       new THREE.MeshStandardMaterial({ color: 0xf7f3ea, roughness: 0.92 }),
            slate:       new THREE.MeshStandardMaterial({ color: 0x3a3d42, roughness: 0.6, metalness: 0.15 }),
            bronze:      new THREE.MeshStandardMaterial({ color: 0x7a5230, roughness: 0.4, metalness: 0.7 })
        };

        // ---- Reusable props ----

        function lamp(x, z, h) {
            const g = new THREE.Group();
            const pole = new THREE.Mesh(new THREE.CylinderGeometry(0.06, 0.08, h, 10), MAT.roof);
            pole.position.y = h / 2;
            pole.castShadow = true;
            g.add(pole);
            const head = new THREE.Mesh(new THREE.SphereGeometry(0.22, 14, 14), MAT.glassWarm);
            head.position.y = h;
            g.add(head);
            const glow = new THREE.PointLight(0xffcf87, 0.5, 6, 2);
            glow.position.y = h;
            g.add(glow);
            g.position.set(x, 0, z);
            return g;
        }

        function palmTree(x, z) {
            const group = new THREE.Group();
            const trunk = new THREE.Mesh(new THREE.CylinderGeometry(0.15, 0.22, 3.4, 8), MAT.trunk);
            trunk.position.y = 1.7;
            trunk.castShadow = true;
            group.add(trunk);
            for (let i = 0; i < 6; i++) {
                const leaf = new THREE.Mesh(new THREE.ConeGeometry(0.35, 2.2, 6), MAT.leaf);
                leaf.position.y = 3.4;
                leaf.rotation.z = (Math.PI / 3.4);
                leaf.rotation.y = (Math.PI * 2 / 6) * i;
                leaf.position.x = Math.cos((Math.PI * 2 / 6) * i) * 0.5;
                leaf.position.z = Math.sin((Math.PI * 2 / 6) * i) * 0.5;
                leaf.castShadow = true;
                group.add(leaf);
            }
            group.position.set(x, 0, z);
            return group;
        }

        function potPlant(x, z, scale) {
            scale = scale || 1;
            const g = new THREE.Group();
            const pot = new THREE.Mesh(new THREE.CylinderGeometry(0.32 * scale, 0.26 * scale, 0.42 * scale, 12), MAT.goldDim);
            pot.position.y = 0.21 * scale;
            pot.castShadow = true;
            g.add(pot);
            for (let i = 0; i < 5; i++) {
                const leaf = new THREE.Mesh(new THREE.ConeGeometry(0.16 * scale, 0.9 * scale, 6), MAT.leafDark);
                leaf.position.y = 0.75 * scale;
                leaf.rotation.z = (Math.random() - 0.5) * 0.5;
                leaf.rotation.y = (Math.PI * 2 / 5) * i;
                leaf.position.x = Math.cos((Math.PI * 2 / 5) * i) * 0.12 * scale;
                leaf.position.z = Math.sin((Math.PI * 2 / 5) * i) * 0.12 * scale;
                leaf.castShadow = true;
                g.add(leaf);
            }
            g.position.set(x, 0, z);
            return g;
        }

        function ceilingSpot(x, z, y) {
            const g = new THREE.Group();
            const fix = new THREE.Mesh(new THREE.CylinderGeometry(0.16, 0.16, 0.08, 16), MAT.chrome);
            fix.position.set(x, y, z);
            g.add(fix);
            const bulb = new THREE.Mesh(new THREE.SphereGeometry(0.09, 10, 10), MAT.glassWarm);
            bulb.position.set(x, y - 0.06, z);
            g.add(bulb);
            const light = new THREE.PointLight(0xffe3b0, 0.75, 9, 2);
            light.position.set(x, y - 0.2, z);
            g.add(light);
            return g;
        }

        // A generic upholstered lounge chair, reused across Reception, Suite,
        // Restaurant and Pool scenes with different material/scale calls.
        function chair(x, z, rotY, mat) {
            mat = mat || MAT.fabricGold;
            const c = new THREE.Group();
            const seat = new THREE.Mesh(new THREE.BoxGeometry(0.75, 0.12, 0.75), mat);
            seat.position.y = 0.45;
            seat.castShadow = true;
            c.add(seat);
            const back = new THREE.Mesh(new THREE.BoxGeometry(0.75, 0.7, 0.1), mat);
            back.position.set(0, 0.82, -0.32);
            back.castShadow = true;
            c.add(back);
            const legGeo = new THREE.CylinderGeometry(0.03, 0.03, 0.45, 8);
            [[-0.32, -0.32], [0.32, -0.32], [-0.32, 0.32], [0.32, 0.32]].forEach(([lx, lz]) => {
                const leg = new THREE.Mesh(legGeo, MAT.wood);
                leg.position.set(lx, 0.22, lz);
                c.add(leg);
            });
            c.position.set(x, 0, z);
            c.rotation.y = rotY;
            return c;
        }

        // A round marble-top side/cocktail table on a chrome pedestal.
        function roundTable(x, z, radius) {
            radius = radius || 0.55;
            const g = new THREE.Group();
            const table = new THREE.Mesh(new THREE.CylinderGeometry(radius, radius - 0.05, 0.06, 24), MAT.marbleDark);
            table.position.y = 0.45;
            table.castShadow = true;
            g.add(table);
            const leg = new THREE.Mesh(new THREE.CylinderGeometry(0.08, 0.08, 0.42, 12), MAT.chrome);
            leg.position.y = 0.24;
            g.add(leg);
            g.position.set(x, 0, z);
            return g;
        }

        // A slim ceiling-hung sphere-drop chandelier, reused for Reception
        // and Restaurant with different radii.
        function chandelier(radius, dropCount, mat) {
            mat = mat || MAT.glassWarm;
            const group = new THREE.Group();
            const ring = new THREE.Mesh(new THREE.TorusGeometry(radius, 0.05, 8, 32), MAT.gold);
            group.add(ring);
            for (let i = 0; i < dropCount; i++) {
                const a = (Math.PI * 2 / dropCount) * i;
                const drop = new THREE.Mesh(new THREE.SphereGeometry(0.07, 8, 8), mat);
                drop.position.set(Math.cos(a) * radius, -0.15, Math.sin(a) * radius);
                group.add(drop);
            }
            const core = new THREE.Mesh(new THREE.SphereGeometry(0.22, 12, 12), mat);
            group.add(core);
            const light = new THREE.PointLight(0xffdca0, 1.0, 14, 2);
            group.add(light);
            return group;
        }

        // ================================================================
        // SCENE 1 — OVERVIEW (hotel exterior)
        // Bright midday daylight: strong warm key from upper-right, cool sky
        // fill from the opposite side, faint rim to separate the tower's
        // silhouette from the sky background.
        // ================================================================
        function buildOverviewScene() {
            const group = new THREE.Group();

            const ground = new THREE.Mesh(new THREE.CircleGeometry(60, 64), MAT.plaza);
            ground.rotation.x = -Math.PI / 2;
            ground.receiveShadow = true;
            group.add(ground);

            const drive = new THREE.Mesh(new THREE.RingGeometry(11, 13.5, 64), MAT.asphalt);
            drive.rotation.x = -Math.PI / 2;
            drive.position.y = 0.01;
            drive.receiveShadow = true;
            group.add(drive);

            const seamMat = new THREE.MeshStandardMaterial({ color: 0xc7bca4, roughness: 1 });
            for (let i = 0; i < 24; i++) {
                const a = (Math.PI * 2 / 24) * i;
                const seam = new THREE.Mesh(new THREE.RingGeometry(14, 58, 1, 1, a, 0.01), seamMat);
                seam.rotation.x = -Math.PI / 2;
                seam.position.y = 0.005;
                group.add(seam);
            }

            const hotelGroup = new THREE.Group();
            group.add(hotelGroup);

            const towerWidth = 12, towerDepth = 9, towerHeight = 22;

            // Tapered upper massing instead of a flat extrusion: two stacked
            // boxes of decreasing footprint read as a real tower silhouette
            // rather than a single rectangular block.
            const towerLower = new THREE.Mesh(new THREE.BoxGeometry(towerWidth, towerHeight * 0.72, towerDepth), MAT.facade);
            towerLower.position.y = (towerHeight * 0.72) / 2;
            towerLower.castShadow = true;
            towerLower.receiveShadow = true;
            hotelGroup.add(towerLower);

            const towerUpper = new THREE.Mesh(new THREE.BoxGeometry(towerWidth * 0.78, towerHeight * 0.28, towerDepth * 0.82), MAT.facade);
            towerUpper.position.y = towerHeight * 0.72 + (towerHeight * 0.28) / 2;
            towerUpper.castShadow = true;
            towerUpper.receiveShadow = true;
            hotelGroup.add(towerUpper);

            // Setback ledge marking the transition, a small but real detail
            // that keeps the massing from reading as two boxes glued together.
            const ledge = new THREE.Mesh(new THREE.BoxGeometry(towerWidth + 0.3, 0.3, towerDepth + 0.3), MAT.goldDim);
            ledge.position.y = towerHeight * 0.72 + 0.15;
            ledge.castShadow = true;
            hotelGroup.add(ledge);

            const roofCap = new THREE.Mesh(new THREE.BoxGeometry(towerWidth * 0.78 + 0.4, 0.5, towerDepth * 0.82 + 0.4), MAT.roof);
            roofCap.position.y = towerHeight + 0.25;
            roofCap.castShadow = true;
            hotelGroup.add(roofCap);

            const mech = new THREE.Mesh(new THREE.BoxGeometry(2.6, 1.0, 2.0), MAT.facadeSoft);
            mech.position.set(2.2, towerHeight + 1.05, 0);
            mech.castShadow = true;
            hotelGroup.add(mech);
            const antenna = new THREE.Mesh(new THREE.CylinderGeometry(0.03, 0.03, 2.4, 6), MAT.chrome);
            antenna.position.set(-2.6, towerHeight + 1.8, -0.8);
            hotelGroup.add(antenna);
            // Small beacon light atop the antenna for a night-silhouette detail
            const beacon = new THREE.Mesh(new THREE.SphereGeometry(0.08, 8, 8), MAT.glassWarm);
            beacon.position.set(-2.6, towerHeight + 3, -0.8);
            hotelGroup.add(beacon);

            const floors = 8, colsPerFloor = 5;
            const winGeo = new THREE.BoxGeometry(1.15, 1.5, 0.15);
            const floorSpacing = (towerHeight * 0.72 - 2) / floors;
            const colSpacing = towerWidth / (colsPerFloor + 1);

            for (let f = 0; f < floors; f++) {
                const y = 2 + f * floorSpacing + floorSpacing / 2;
                for (let c = 0; c < colsPerFloor; c++) {
                    const x = -towerWidth / 2 + colSpacing * (c + 1);
                    const mat = Math.random() > 0.72 ? MAT.glassWarm : MAT.glass;
                    const winFront = new THREE.Mesh(winGeo, mat);
                    winFront.position.set(x, y, towerDepth / 2 + 0.08);
                    hotelGroup.add(winFront);
                    const winBack = new THREE.Mesh(winGeo, MAT.glass);
                    winBack.position.set(x, y, -towerDepth / 2 - 0.08);
                    hotelGroup.add(winBack);

                    const sill = new THREE.Mesh(new THREE.BoxGeometry(1.3, 0.06, 0.18), MAT.goldDim);
                    sill.position.set(x, y - 0.82, towerDepth / 2 + 0.08);
                    hotelGroup.add(sill);
                }
            }

            const sideWinGeo = new THREE.BoxGeometry(0.15, 1.4, 1.9);
            for (let f = 0; f < floors; f++) {
                const y = 2 + f * floorSpacing + floorSpacing / 2;
                [towerDepth / 2 - 0.9, -(towerDepth / 2 - 0.9)].forEach((z) => {
                    const winL = new THREE.Mesh(sideWinGeo, MAT.glass);
                    winL.position.set(towerWidth / 2 + 0.08, y, z);
                    hotelGroup.add(winL);
                    const winR = new THREE.Mesh(sideWinGeo, MAT.glass);
                    winR.position.set(-towerWidth / 2 - 0.08, y, z);
                    hotelGroup.add(winR);
                });
            }

            const lobbyWidth = towerWidth + 4, lobbyDepth = towerDepth + 4, lobbyHeight = 3.2;
            const lobby = new THREE.Mesh(new THREE.BoxGeometry(lobbyWidth, lobbyHeight, lobbyDepth), MAT.facade);
            lobby.position.y = lobbyHeight / 2;
            lobby.castShadow = true;
            lobby.receiveShadow = true;
            hotelGroup.add(lobby);

            const lobbyGlass = new THREE.Mesh(new THREE.BoxGeometry(lobbyWidth - 2, lobbyHeight - 1, 0.15), MAT.glassWarm);
            lobbyGlass.position.set(0, lobbyHeight / 2, lobbyDepth / 2 + 0.05);
            hotelGroup.add(lobbyGlass);
            // Vertical mullions across the lobby glass wall
            for (let i = -3; i <= 3; i++) {
                const mullion = new THREE.Mesh(new THREE.BoxGeometry(0.06, lobbyHeight - 1, 0.18), MAT.goldDim);
                mullion.position.set(i * ((lobbyWidth - 2) / 7), lobbyHeight / 2, lobbyDepth / 2 + 0.08);
                hotelGroup.add(mullion);
            }

            const door = new THREE.Mesh(new THREE.BoxGeometry(2.2, 2.4, 0.2), MAT.door);
            door.position.set(0, 1.2, lobbyDepth / 2 + 0.12);
            hotelGroup.add(door);

            const canopy = new THREE.Mesh(new THREE.BoxGeometry(6, 0.25, 3), MAT.gold);
            canopy.position.set(0, lobbyHeight + 0.5, lobbyDepth / 2 + 1.6);
            canopy.castShadow = true;
            hotelGroup.add(canopy);

            const canopyPoleGeo = new THREE.CylinderGeometry(0.08, 0.08, lobbyHeight + 0.35, 12);
            [[-2.6, lobbyDepth / 2 + 2.9], [2.6, lobbyDepth / 2 + 2.9]].forEach(([px, pz]) => {
                const pole = new THREE.Mesh(canopyPoleGeo, MAT.gold);
                pole.position.set(px, (lobbyHeight + 0.35) / 2, pz);
                pole.castShadow = true;
                hotelGroup.add(pole);
            });

            const signBand = new THREE.Mesh(new THREE.BoxGeometry(lobbyWidth - 1, 0.5, 0.12), MAT.gold);
            signBand.position.set(0, lobbyHeight - 0.4, lobbyDepth / 2 + 0.1);
            hotelGroup.add(signBand);

            hotelGroup.add(palmTree(-lobbyWidth / 2 - 2.5, lobbyDepth / 2 + 1));
            hotelGroup.add(palmTree(lobbyWidth / 2 + 2.5, lobbyDepth / 2 + 1));

            for (let i = 0; i < 8; i++) {
                const a = (Math.PI * 2 / 8) * i;
                const lx = Math.cos(a) * 12.25, lz = Math.sin(a) * 12.25;
                if (Math.abs(lz) > 8) group.add(lamp(lx, lz, 3.4));
            }
            const hedgeMat = new THREE.MeshStandardMaterial({ color: 0x3d6b3a, roughness: 0.85 });
            const hedgeRing = new THREE.Mesh(new THREE.TorusGeometry(15.5, 0.35, 8, 48), hedgeMat);
            hedgeRing.rotation.x = Math.PI / 2;
            hedgeRing.position.y = 0.35;
            hedgeRing.receiveShadow = true;
            group.add(hedgeRing);

            group.userData.camera = {
                radius: 48, theta: Math.PI / 3.4, phi: Math.PI / 2.35,
                target: new THREE.Vector3(0, 9, 0),
                minRadius: 22, maxRadius: 78, minPhi: 0.35, maxPhi: Math.PI / 2.05
            };
            group.userData.bg = 0xbfe3f2;
            group.userData.fog = [0xbfe3f2, 42, 135];
            group.userData.label = { icon: 'fa-building-columns', text: 'Overview &mdash; Exterior' };
            group.userData.lighting = {
                hemi: 0.6,
                keyColor: 0xfff3d6, keyIntensity: 1.35, keyPos: [30, 42, 20],
                fillColor: 0xcfe0f2, fillIntensity: 0.4, fillPos: [-28, 18, -22],
                rimColor: 0xffe9c2, rimIntensity: 0.55, rimPos: [-10, 14, -30],
                target: [0, 9, 0], ambientBoost: 0
            };
            group.userData.info = {
                title: 'Grand Exterior',
                tagline: 'A modern silhouette that welcomes you from the moment you arrive.',
                points: [
                    { icon: 'fa-building-columns', text: '8-storey tapered tower with a warm stone facade' },
                    { icon: 'fa-car', text: 'Circular driveway with dedicated guest drop-off' },
                    { icon: 'fa-tree', text: 'Landscaped hedge ring and palm-lined entrance' },
                    { icon: 'fa-lightbulb', text: 'Lamp-lit plaza for a striking arrival at night' }
                ]
            };
            return group;
        }

        // ================================================================
        // SCENE 2 — ENTRANCE (porte-cochère close-up)
        // Late-afternoon warmth: golden-hour key light low and to the side,
        // plus the canopy underglow and sign backlight doing double duty as
        // practical light sources the rig complements rather than replaces.
        // ================================================================
        function buildEntranceScene() {
            const group = new THREE.Group();

            const ground = new THREE.Mesh(new THREE.CircleGeometry(40, 64), MAT.plaza);
            ground.rotation.x = -Math.PI / 2;
            ground.receiveShadow = true;
            group.add(ground);

            const drive = new THREE.Mesh(new THREE.BoxGeometry(14, 0.05, 30), MAT.asphalt);
            drive.position.set(0, 0.02, 8);
            drive.receiveShadow = true;
            group.add(drive);
            for (let i = -1; i <= 1; i += 2) {
                const stripe = new THREE.Mesh(new THREE.BoxGeometry(0.15, 0.06, 30), new THREE.MeshStandardMaterial({ color: 0xf2e9d0 }));
                stripe.position.set(i * 3, 0.04, 8);
                group.add(stripe);
            }

            const lobbyWidth = 16, lobbyDepth = 13, lobbyHeight = 3.4;
            const lobby = new THREE.Mesh(new THREE.BoxGeometry(lobbyWidth, lobbyHeight, lobbyDepth), MAT.facade);
            lobby.position.y = lobbyHeight / 2;
            lobby.castShadow = true;
            lobby.receiveShadow = true;
            group.add(lobby);

            // Stone base plinth along the front — a small grounding detail
            // that keeps the facade from looking like it floats on the plaza.
            const plinth = new THREE.Mesh(new THREE.BoxGeometry(lobbyWidth + 0.3, 0.4, lobbyDepth + 0.3), MAT.stoneWarm);
            plinth.position.y = 0.2;
            plinth.receiveShadow = true;
            group.add(plinth);

            const lobbyGlass = new THREE.Mesh(new THREE.BoxGeometry(lobbyWidth - 2.5, lobbyHeight - 0.8, 0.2), MAT.glassWarm);
            lobbyGlass.position.set(0, lobbyHeight / 2, lobbyDepth / 2 + 0.08);
            group.add(lobbyGlass);

            const frame = new THREE.Mesh(new THREE.TorusGeometry(1.7, 0.08, 8, 24), MAT.gold);
            frame.position.set(0, 1.85, lobbyDepth / 2 + 0.15);
            group.add(frame);
            [-0.9, 0.9].forEach((dx) => {
                const leafDoor = new THREE.Mesh(new THREE.BoxGeometry(1.5, 2.5, 0.08), MAT.glass);
                leafDoor.position.set(dx, 1.25, lobbyDepth / 2 + 0.16);
                group.add(leafDoor);
            });

            const canopy = new THREE.Mesh(new THREE.BoxGeometry(9, 0.3, 4.5), MAT.gold);
            canopy.position.set(0, lobbyHeight + 0.55, lobbyDepth / 2 + 2.4);
            canopy.castShadow = true;
            group.add(canopy);
            // Underside trim so the canopy reads as a solid volume from below
            const canopyUnderside = new THREE.Mesh(new THREE.BoxGeometry(8.8, 0.06, 4.3), MAT.goldDim);
            canopyUnderside.position.set(0, lobbyHeight + 0.4, lobbyDepth / 2 + 2.4);
            group.add(canopyUnderside);
            const canopyUnderglow = new THREE.PointLight(0xffdca0, 0.65, 8, 2);
            canopyUnderglow.position.set(0, lobbyHeight + 0.35, lobbyDepth / 2 + 2.4);
            group.add(canopyUnderglow);

            const poleGeo = new THREE.CylinderGeometry(0.1, 0.1, lobbyHeight + 0.4, 12);
            [[-3.6, lobbyDepth / 2 + 4.5], [3.6, lobbyDepth / 2 + 4.5], [-3.6, lobbyDepth / 2 + 0.4], [3.6, lobbyDepth / 2 + 0.4]].forEach(([px, pz]) => {
                const pole = new THREE.Mesh(poleGeo, MAT.gold);
                pole.position.set(px, (lobbyHeight + 0.4) / 2, pz);
                pole.castShadow = true;
                group.add(pole);
            });

            const signBand = new THREE.Mesh(new THREE.BoxGeometry(lobbyWidth - 3, 0.6, 0.14), MAT.gold);
            signBand.position.set(0, lobbyHeight - 0.35, lobbyDepth / 2 + 0.12);
            group.add(signBand);
            const signGlow = new THREE.PointLight(0xffe3ad, 1.15, 10, 2);
            signGlow.position.set(0, lobbyHeight - 0.2, lobbyDepth / 2 + 0.6);
            group.add(signGlow);

            const carpet = new THREE.Mesh(new THREE.BoxGeometry(3, 0.03, 6), MAT.carpetRed);
            carpet.position.set(0, 0.02, lobbyDepth / 2 + 5.2);
            carpet.receiveShadow = true;
            group.add(carpet);
            // Gold edge trim along the carpet runner
            [-1.55, 1.55].forEach((ex) => {
                const trim = new THREE.Mesh(new THREE.BoxGeometry(0.1, 0.035, 6), MAT.goldDim);
                trim.position.set(ex, 0.02, lobbyDepth / 2 + 5.2);
                group.add(trim);
            });

            const cart = new THREE.Group();
            const cartBase = new THREE.Mesh(new THREE.BoxGeometry(1, 0.5, 0.6), MAT.gold);
            cartBase.position.y = 0.5;
            cart.add(cartBase);
            const cartHandle = new THREE.Mesh(new THREE.CylinderGeometry(0.03, 0.03, 1.1, 8), MAT.chrome);
            cartHandle.rotation.x = Math.PI / 2.6;
            cartHandle.position.set(0, 1.1, -0.35);
            cart.add(cartHandle);
            [[-0.4, -0.25], [0.4, -0.25], [-0.4, 0.25], [0.4, 0.25]].forEach(([wx, wz]) => {
                const wheel = new THREE.Mesh(new THREE.CylinderGeometry(0.12, 0.12, 0.06, 12), MAT.roof);
                wheel.rotation.z = Math.PI / 2;
                wheel.position.set(wx, 0.12, wz);
                cart.add(wheel);
            });
            cart.position.set(5.5, 0, lobbyDepth / 2 + 1.6);
            cart.rotation.y = -0.4;
            group.add(cart);

            group.add(potPlant(-6.5, lobbyDepth / 2 + 2, 1.3));
            group.add(potPlant(6.5, lobbyDepth / 2 + 2, 1.3));
            group.add(lamp(-9, lobbyDepth / 2 - 2, 3.2));
            group.add(lamp(9, lobbyDepth / 2 - 2, 3.2));

            group.userData.camera = {
                radius: 22, theta: 0, phi: Math.PI / 2.3,
                target: new THREE.Vector3(0, 3, lobbyDepth / 2),
                minRadius: 10, maxRadius: 34, minPhi: 0.4, maxPhi: Math.PI / 2.05
            };
            group.userData.bg = 0xe8cfa0;
            group.userData.fog = [0xe8cfa0, 24, 70];
            group.userData.label = { icon: 'fa-door-open', text: 'Entrance &mdash; Porte-coch&egrave;re' };
            group.userData.lighting = {
                hemi: 0.45,
                keyColor: 0xffca8a, keyIntensity: 1.1, keyPos: [22, 14, 26],
                fillColor: 0xb9cfe0, fillIntensity: 0.3, fillPos: [-18, 10, -12],
                rimColor: 0xffdca0, rimIntensity: 0.6, rimPos: [-6, 8, -18],
                target: [0, 3, lobbyDepth / 2], ambientBoost: 0.08
            };
            group.userData.info = {
                title: 'Porte-Coch&egrave;re Entrance',
                tagline: 'Golden-hour arrival under a grand illuminated canopy.',
                points: [
                    { icon: 'fa-champagne-glasses', text: 'Red-carpet walkway from the driveway to the doors' },
                    { icon: 'fa-bell-concierge', text: 'Bellhop cart standing by for luggage assistance' },
                    { icon: 'fa-signs-post', text: 'Backlit signage band visible from the road' },
                    { icon: 'fa-door-open', text: 'Full-height glass doors framed in brushed gold' }
                ]
            };
            return group;
        }

        // ================================================================
        // SCENE 3 — RECEPTION (interior lobby with front desk)
        // Warm, low-key interior mood: key light dropped to a modest interior
        // level and warmed up, chandelier + sconces carry most of the actual
        // illumination, rim light lifts the desk's edge from the dark
        // background so it doesn't flatten into a silhouette.
        // ================================================================
        function buildReceptionScene() {
            const group = new THREE.Group();

            const roomWidth = 20, roomDepth = 16, roomHeight = 6.5;

            const floor = new THREE.Mesh(new THREE.PlaneGeometry(roomWidth, roomDepth), MAT.marbleLight);
            floor.rotation.x = -Math.PI / 2;
            floor.receiveShadow = true;
            group.add(floor);

            for (let ix = -3; ix <= 3; ix++) {
                for (let iz = -3; iz <= 3; iz++) {
                    if ((ix + iz) % 2 === 0) continue;
                    const tile = new THREE.Mesh(new THREE.PlaneGeometry(1.8, 1.8), MAT.marbleDark);
                    tile.rotation.x = -Math.PI / 2;
                    tile.position.set(ix * 2, 0.001, iz * 2);
                    group.add(tile);
                }
            }

            const ceiling = new THREE.Mesh(new THREE.PlaneGeometry(roomWidth, roomDepth), MAT.ceilingInterior);
            ceiling.rotation.x = Math.PI / 2;
            ceiling.position.y = roomHeight;
            group.add(ceiling);
            // Recessed ceiling coffer ring — small architectural detail that
            // reads clearly under the chandelier's downlight.
            const cofferMat = new THREE.MeshStandardMaterial({ color: 0xefe7d6, roughness: 0.9 });
            const coffer = new THREE.Mesh(new THREE.RingGeometry(2.6, 3.0, 32), cofferMat);
            coffer.rotation.x = Math.PI / 2;
            coffer.position.y = roomHeight - 0.02;
            group.add(coffer);

            const backWall = new THREE.Mesh(new THREE.PlaneGeometry(roomWidth, roomHeight), MAT.wallInterior);
            backWall.position.set(0, roomHeight / 2, -roomDepth / 2);
            backWall.receiveShadow = true;
            group.add(backWall);

            const leftWall = new THREE.Mesh(new THREE.PlaneGeometry(roomDepth, roomHeight), MAT.wallInterior);
            leftWall.rotation.y = Math.PI / 2;
            leftWall.position.set(-roomWidth / 2, roomHeight / 2, 0);
            group.add(leftWall);

            const rightWall = new THREE.Mesh(new THREE.PlaneGeometry(roomDepth, roomHeight), MAT.wallInterior);
            rightWall.rotation.y = -Math.PI / 2;
            rightWall.position.set(roomWidth / 2, roomHeight / 2, 0);
            group.add(rightWall);

            const accentBand = new THREE.Mesh(new THREE.BoxGeometry(roomWidth, 0.5, 0.05), MAT.goldDim);
            accentBand.position.set(0, 1.1, -roomDepth / 2 + 0.03);
            group.add(accentBand);

            const signPlate = new THREE.Mesh(new THREE.BoxGeometry(6, 1.1, 0.1), MAT.gold);
            signPlate.position.set(0, 4.1, -roomDepth / 2 + 0.1);
            group.add(signPlate);
            const signBacklight = new THREE.PointLight(0xffe3ad, 0.95, 8, 2);
            signBacklight.position.set(0, 4.1, -roomDepth / 2 + 0.6);
            group.add(signBacklight);

            // ---- Reception desk ----
            const desk = new THREE.Group();
            const deskBody = new THREE.Mesh(new THREE.BoxGeometry(7, 1.15, 1.5), MAT.wood);
            deskBody.position.y = 0.575;
            deskBody.castShadow = true;
            deskBody.receiveShadow = true;
            desk.add(deskBody);
            const deskTop = new THREE.Mesh(new THREE.BoxGeometry(7.2, 0.08, 1.7), MAT.marbleDark);
            deskTop.position.y = 1.19;
            desk.add(deskTop);
            const deskFace = new THREE.Mesh(new THREE.BoxGeometry(7.02, 1.0, 0.06), MAT.goldDim);
            deskFace.position.set(0, 0.575, 0.76);
            desk.add(deskFace);
            // Subtle fluted vertical grooves on the desk face for texture
            for (let i = -6; i <= 6; i++) {
                const groove = new THREE.Mesh(new THREE.BoxGeometry(0.03, 0.9, 0.01), MAT.gold);
                groove.position.set(i * 0.5, 0.575, 0.79);
                desk.add(groove);
            }
            desk.position.set(0, 0, -roomDepth / 2 + 3.2);
            group.add(desk);

            const deskLampBase = new THREE.Mesh(new THREE.CylinderGeometry(0.1, 0.12, 0.05, 12), MAT.chrome);
            deskLampBase.position.set(2.6, 1.24, -roomDepth / 2 + 3.0);
            group.add(deskLampBase);
            const deskLampShade = new THREE.Mesh(new THREE.ConeGeometry(0.16, 0.22, 12, 1, true), MAT.glassWarm);
            deskLampShade.position.set(2.6, 1.5, -roomDepth / 2 + 3.0);
            group.add(deskLampShade);
            group.add((function () {
                const l = new THREE.PointLight(0xffdca0, 0.42, 4, 2);
                l.position.set(2.6, 1.55, -roomDepth / 2 + 3.0);
                return l;
            })());

            // ---- Lounge seating area ----
            group.add(roundTable(5.5, 3.5, 0.55));
            group.add(chair(4.4, 2.6, 0.6));
            group.add(chair(6.6, 2.6, -0.6));
            group.add(chair(4.4, 4.4, 2.4));
            group.add(chair(6.6, 4.4, -2.4));

            group.add(roundTable(-5.5, 3.5, 0.55));
            group.add(chair(-4.4, 2.6, -0.6));
            group.add(chair(-6.6, 2.6, 0.6));

            // ---- Chandelier ----
            const mainChandelier = chandelier(1.1, 10, MAT.glassWarm);
            mainChandelier.position.set(0, roomHeight - 1.1, 0);
            group.add(mainChandelier);

            [[-6, -4], [6, -4], [-6, 4], [6, 4]].forEach(([sx, sz]) => {
                group.add(ceilingSpot(sx, sz, roomHeight - 0.05));
            });

            group.add(potPlant(-roomWidth / 2 + 1.4, -roomDepth / 2 + 1.4, 1.6));
            group.add(potPlant(roomWidth / 2 - 1.4, -roomDepth / 2 + 1.4, 1.6));

            group.userData.camera = {
                radius: 15, theta: 0, phi: Math.PI / 2.5,
                target: new THREE.Vector3(0, 2, -2),
                minRadius: 6, maxRadius: 22, minPhi: 0.5, maxPhi: Math.PI / 2.02
            };
            group.userData.bg = 0x1c1712;
            group.userData.fog = [0x1c1712, 22, 46];
            group.userData.label = { icon: 'fa-bell-concierge', text: 'Reception &mdash; Front Desk' };
            group.userData.lighting = {
                hemi: 0.22,
                keyColor: 0xffe3b8, keyIntensity: 0.45, keyPos: [8, 10, 10],
                fillColor: 0xd8c8e8, fillIntensity: 0.18, fillPos: [-10, 6, -6],
                rimColor: 0xffcf87, rimIntensity: 0.5, rimPos: [0, 7, -9],
                target: [0, 2, -2], ambientBoost: 0.5
            };
            group.userData.info = {
                title: 'Reception &amp; Front Desk',
                tagline: 'Marble, brass and warm light greet every arriving guest.',
                points: [
                    { icon: 'fa-gem', text: 'Diamond-inlay marble flooring beneath a crystal chandelier' },
                    { icon: 'fa-bell-concierge', text: 'Concierge desk staffed around the clock' },
                    { icon: 'fa-couch', text: 'Twin lounge clusters for waiting guests' },
                    { icon: 'fa-signature', text: 'Backlit signature wall behind the desk' }
                ]
            };
            return group;
        }

        // ================================================================
        // SCENE 4 — HALLWAY (guest room corridor)
        // Deep, moody corridor: key light almost off (there's no window down
        // here), sconces + ceiling downlights are the real sources, a narrow
        // warm rim from the far end gives the corridor a sense of depth
        // receding into darkness rather than a flat-lit tunnel.
        // ================================================================
        function buildHallwayScene() {
            const group = new THREE.Group();

            const corridorWidth = 4.4, corridorHeight = 3.2, corridorLength = 26;

            const floor = new THREE.Mesh(new THREE.PlaneGeometry(corridorWidth, corridorLength), MAT.marbleDark);
            floor.rotation.x = -Math.PI / 2;
            floor.receiveShadow = true;
            group.add(floor);

            const carpet = new THREE.Mesh(new THREE.PlaneGeometry(1.6, corridorLength), MAT.carpetRed);
            carpet.rotation.x = -Math.PI / 2;
            carpet.position.y = 0.005;
            group.add(carpet);
            [-0.85, 0.85].forEach((ex) => {
                const trim = new THREE.Mesh(new THREE.PlaneGeometry(0.06, corridorLength), MAT.goldDim);
                trim.rotation.x = -Math.PI / 2;
                trim.position.set(ex, 0.006, 0);
                group.add(trim);
            });

            const ceiling = new THREE.Mesh(new THREE.PlaneGeometry(corridorWidth, corridorLength), MAT.ceilingInterior);
            ceiling.rotation.x = Math.PI / 2;
            ceiling.position.y = corridorHeight;
            group.add(ceiling);

            const endWall = new THREE.Mesh(new THREE.PlaneGeometry(corridorWidth, corridorHeight), MAT.wallInterior);
            endWall.position.set(0, corridorHeight / 2, -corridorLength / 2);
            group.add(endWall);
            const endArt = new THREE.Mesh(new THREE.PlaneGeometry(1.1, 1.6), MAT.wallAccent);
            endArt.position.set(0, 1.7, -corridorLength / 2 + 0.02);
            group.add(endArt);
            // Small picture light above the end-of-corridor artwork
            const artLight = new THREE.SpotLight(0xffe9c8, 0.6, 6, Math.PI / 6, 0.5);
            artLight.position.set(0, 2.9, -corridorLength / 2 + 0.6);
            artLight.target = endArt;
            group.add(artLight);

            const doorSpacing = 3.4;
            const numBaysPerSide = 6;
            for (let i = 0; i < numBaysPerSide; i++) {
                const z = -corridorLength / 2 + 3 + i * doorSpacing;

                [-1, 1].forEach((side) => {
                    const wallSeg = new THREE.Mesh(new THREE.PlaneGeometry(doorSpacing - 0.3, corridorHeight), MAT.wallInterior);
                    wallSeg.rotation.y = side * Math.PI / 2;
                    wallSeg.position.set(side * corridorWidth / 2, corridorHeight / 2, z);
                    group.add(wallSeg);

                    const doorFrame = new THREE.Mesh(new THREE.BoxGeometry(0.08, 2.35, 1.15), MAT.goldDim);
                    doorFrame.position.set(side * (corridorWidth / 2 - 0.02), 1.2, z);
                    group.add(doorFrame);

                    const doorMesh = new THREE.Mesh(new THREE.BoxGeometry(0.06, 2.2, 1.0), MAT.door);
                    doorMesh.position.set(side * (corridorWidth / 2 - 0.05), 1.15, z);
                    group.add(doorMesh);

                    const handle = new THREE.Mesh(new THREE.SphereGeometry(0.04, 8, 8), MAT.gold);
                    handle.position.set(side * (corridorWidth / 2 - 0.1), 1.15, z + side * 0.38);
                    group.add(handle);

                    const plaque = new THREE.Mesh(new THREE.PlaneGeometry(0.22, 0.14), MAT.gold);
                    plaque.rotation.y = -side * Math.PI / 2;
                    plaque.position.set(side * (corridorWidth / 2 - 0.04), 2.0, z + side * 0.5);
                    group.add(plaque);

                    const sconceBack = new THREE.Mesh(new THREE.CylinderGeometry(0.09, 0.09, 0.22, 10), MAT.gold);
                    sconceBack.rotation.z = Math.PI / 2;
                    sconceBack.position.set(side * (corridorWidth / 2 - 0.06), 1.9, z + doorSpacing / 2 - 0.1);
                    group.add(sconceBack);
                    const sconceGlow = new THREE.Mesh(new THREE.SphereGeometry(0.1, 10, 10), MAT.glassWarm);
                    sconceGlow.position.copy(sconceBack.position);
                    group.add(sconceGlow);
                    const sconceLight = new THREE.PointLight(0xffdca0, 0.55, 6, 2);
                    sconceLight.position.copy(sconceBack.position);
                    group.add(sconceLight);
                });

                group.add(ceilingSpot(0, z, corridorHeight - 0.03));
            }

            const cart = new THREE.Group();
            const cartBody = new THREE.Mesh(new THREE.BoxGeometry(0.9, 1.0, 0.5), MAT.facadeSoft);
            cartBody.position.y = 0.5;
            cartBody.castShadow = true;
            cart.add(cartBody);
            const cartTowels = new THREE.Mesh(new THREE.BoxGeometry(0.8, 0.2, 0.4), MAT.linen);
            cartTowels.position.y = 1.1;
            cart.add(cartTowels);
            cart.position.set(0.9, 0, 4);
            group.add(cart);

            group.userData.camera = {
                radius: 12, theta: 0, phi: Math.PI / 2.15,
                target: new THREE.Vector3(0, 1.7, -3),
                minRadius: 5, maxRadius: 20, minPhi: 0.6, maxPhi: Math.PI / 2.02
            };
            group.userData.bg = 0x14100c;
            group.userData.fog = [0x14100c, 10, 34];
            group.userData.label = { icon: 'fa-door-closed', text: 'Hallway &mdash; Guest Corridor' };
            group.userData.lighting = {
                hemi: 0.12,
                keyColor: 0xffe9c8, keyIntensity: 0.15, keyPos: [4, 6, 8],
                fillColor: 0xc8d6e8, fillIntensity: 0.08, fillPos: [-4, 4, -4],
                rimColor: 0xffcf87, rimIntensity: 0.4, rimPos: [0, 3, -12],
                target: [0, 1.7, -3], ambientBoost: 0.42
            };
            group.userData.info = {
                title: 'Guest Room Corridor',
                tagline: 'A quiet, carpeted approach to every suite.',
                points: [
                    { icon: 'fa-rug', text: 'Plush red carpet runner with gold edge trim' },
                    { icon: 'fa-door-closed', text: 'Twelve room entries framed in brushed gold' },
                    { icon: 'fa-lightbulb', text: 'Wall sconces alternate with recessed ceiling spots' },
                    { icon: 'fa-broom', text: 'Housekeeping carts kept discreetly out of the way' }
                ]
            };
            return group;
        }

        // ================================================================
        // SCENE 5 — SUITE ROOM (new)
        // Soft morning light through a floor-to-ceiling window: key light
        // simulates daylight streaming in low and warm through the window
        // wall, gentle fill keeps the far side of the room from going flat
        // black, faint cool rim off the glass to suggest the sky outside.
        // ================================================================
        function buildSuiteScene() {
            const group = new THREE.Group();

            const roomWidth = 12, roomDepth = 14, roomHeight = 3.4;

            const floor = new THREE.Mesh(new THREE.PlaneGeometry(roomWidth, roomDepth), MAT.woodLight);
            floor.rotation.x = -Math.PI / 2;
            floor.receiveShadow = true;
            group.add(floor);

            // A large area rug under the bed for a softer, more furnished feel
            const rug = new THREE.Mesh(new THREE.PlaneGeometry(6, 5), MAT.fabricCream);
            rug.rotation.x = -Math.PI / 2;
            rug.position.set(0, 0.003, 1);
            group.add(rug);

            const ceiling = new THREE.Mesh(new THREE.PlaneGeometry(roomWidth, roomDepth), MAT.ceilingInterior);
            ceiling.rotation.x = Math.PI / 2;
            ceiling.position.y = roomHeight;
            group.add(ceiling);

            const backWall = new THREE.Mesh(new THREE.PlaneGeometry(roomWidth, roomHeight), MAT.wallInterior);
            backWall.position.set(0, roomHeight / 2, -roomDepth / 2);
            backWall.receiveShadow = true;
            group.add(backWall);

            const leftWall = new THREE.Mesh(new THREE.PlaneGeometry(roomDepth, roomHeight), MAT.wallInterior);
            leftWall.rotation.y = Math.PI / 2;
            leftWall.position.set(-roomWidth / 2, roomHeight / 2, 0);
            group.add(leftWall);

            // Right wall is mostly glazing — floor-to-ceiling window wall,
            // the defining feature of the room and the scene's main light
            // source narratively (even though the actual illumination comes
            // from the rig).
            const rightWallFrame = new THREE.Mesh(new THREE.PlaneGeometry(roomDepth, roomHeight), MAT.wallInterior);
            rightWallFrame.rotation.y = -Math.PI / 2;
            rightWallFrame.position.set(roomWidth / 2, roomHeight / 2, 0);
            group.add(rightWallFrame);
            const windowGlass = new THREE.Mesh(new THREE.PlaneGeometry(roomDepth - 1.2, roomHeight - 0.6), MAT.glass);
            windowGlass.rotation.y = -Math.PI / 2;
            windowGlass.position.set(roomWidth / 2 - 0.02, roomHeight / 2, 0);
            group.add(windowGlass);
            // Mullions across the window wall
            for (let i = -2; i <= 2; i++) {
                const mullion = new THREE.Mesh(new THREE.BoxGeometry(0.05, roomHeight - 0.6, 0.06), MAT.goldDim);
                mullion.position.set(roomWidth / 2 - 0.03, roomHeight / 2, i * ((roomDepth - 1.2) / 5));
                group.add(mullion);
            }
            // Sheer curtain panel pulled to one side
            const curtain = new THREE.Mesh(new THREE.PlaneGeometry(1.4, roomHeight - 0.5), MAT.linen);
            curtain.rotation.y = -Math.PI / 2;
            curtain.position.set(roomWidth / 2 - 0.1, roomHeight / 2, -roomDepth / 2 + 1.1);
            group.add(curtain);

            // ---- Bed ----
            const bed = new THREE.Group();
            const bedFrame = new THREE.Mesh(new THREE.BoxGeometry(4.2, 0.5, 5.6), MAT.wood);
            bedFrame.position.y = 0.25;
            bedFrame.castShadow = true;
            bedFrame.receiveShadow = true;
            bed.add(bedFrame);
            const mattress = new THREE.Mesh(new THREE.BoxGeometry(4.0, 0.4, 5.4), MAT.linen);
            mattress.position.y = 0.7;
            mattress.castShadow = true;
            bed.add(mattress);
            const duvet = new THREE.Mesh(new THREE.BoxGeometry(4.05, 0.18, 3.4), MAT.fabricGold);
            duvet.position.set(0, 0.98, 0.9);
            bed.add(duvet);
            // Two pillows side by side at the headboard end
            [-0.95, 0.95].forEach((px) => {
                const pillow = new THREE.Mesh(new THREE.BoxGeometry(1.5, 0.28, 0.9), MAT.linen);
                pillow.position.set(px, 1.02, -2.1);
                pillow.castShadow = true;
                bed.add(pillow);
            });
            const headboard = new THREE.Mesh(new THREE.BoxGeometry(4.3, 1.6, 0.15), MAT.fabricGold);
            headboard.position.set(0, 1.4, -2.75);
            headboard.castShadow = true;
            bed.add(headboard);
            bed.position.set(-1, 0, 1);
            group.add(bed);

            // Nightstands + lamps flanking the bed
            [-3.4, 1.4].forEach((nx) => {
                const stand = new THREE.Mesh(new THREE.BoxGeometry(0.7, 0.6, 0.6), MAT.wood);
                stand.position.set(nx, 0.3, -2.2);
                stand.castShadow = true;
                group.add(stand);
                const lampBase = new THREE.Mesh(new THREE.CylinderGeometry(0.08, 0.1, 0.06, 12), MAT.chrome);
                lampBase.position.set(nx, 0.63, -2.2);
                group.add(lampBase);
                const lampShade = new THREE.Mesh(new THREE.ConeGeometry(0.16, 0.24, 12, 1, true), MAT.glassWarm);
                lampShade.position.set(nx, 0.9, -2.2);
                group.add(lampShade);
                const lampLight = new THREE.PointLight(0xffdca0, 0.4, 4, 2);
                lampLight.position.set(nx, 0.95, -2.2);
                group.add(lampLight);
            });

            // ---- Seating nook near the window ----
            const loveseat = new THREE.Group();
            const loveseatSeat = new THREE.Mesh(new THREE.BoxGeometry(1.8, 0.4, 0.8), MAT.fabricCream);
            loveseatSeat.position.y = 0.35;
            loveseatSeat.castShadow = true;
            loveseat.add(loveseatSeat);
            const loveseatBack = new THREE.Mesh(new THREE.BoxGeometry(1.8, 0.7, 0.15), MAT.fabricCream);
            loveseatBack.position.set(0, 0.7, -0.32);
            loveseatBack.castShadow = true;
            loveseat.add(loveseatBack);
            loveseat.position.set(3.2, 0, 4.5);
            loveseat.rotation.y = -Math.PI / 2;
            group.add(loveseat);
            group.add(roundTable(3.2, 2.8, 0.4));

            // Writing desk against the left wall
            const writingDesk = new THREE.Mesh(new THREE.BoxGeometry(1.6, 0.75, 0.65), MAT.woodLight);
            writingDesk.position.set(-roomWidth / 2 + 1.1, 0.375, 5.4);
            writingDesk.castShadow = true;
            group.add(writingDesk);
            const deskChair = chair(-roomWidth / 2 + 1.1, 6.3, Math.PI, MAT.fabricGold);
            group.add(deskChair);

            // Wardrobe near the entry
            const wardrobe = new THREE.Mesh(new THREE.BoxGeometry(1.4, 2.2, 0.65), MAT.wood);
            wardrobe.position.set(-roomWidth / 2 + 0.9, 1.1, -5.8);
            wardrobe.castShadow = true;
            group.add(wardrobe);
            const wardrobeHandle = new THREE.Mesh(new THREE.CylinderGeometry(0.02, 0.02, 0.3, 8), MAT.gold);
            wardrobeHandle.rotation.z = Math.PI / 2;
            wardrobeHandle.position.set(-roomWidth / 2 + 1.5, 1.1, -5.5);
            group.add(wardrobeHandle);

            group.add(ceilingSpot(-2.5, -1, roomHeight - 0.03));
            group.add(ceilingSpot(2.5, 4, roomHeight - 0.03));

            // Framed art above the headboard
            const art = new THREE.Mesh(new THREE.PlaneGeometry(1.6, 1.0), MAT.wallAccent);
            art.position.set(0, 2.5, -roomDepth / 2 + 0.02);
            group.add(art);

            group.userData.camera = {
                radius: 11, theta: 0.5, phi: Math.PI / 2.4,
                target: new THREE.Vector3(-0.5, 1.3, 0.5),
                minRadius: 5, maxRadius: 17, minPhi: 0.55, maxPhi: Math.PI / 2.05
            };
            group.userData.bg = 0xd9ecf5;
            group.userData.fog = [0xd9ecf5, 16, 40];
            group.userData.label = { icon: 'fa-bed', text: 'Suite &mdash; Deluxe Room' };
            group.userData.lighting = {
                hemi: 0.5,
                keyColor: 0xfff0d2, keyIntensity: 0.85, keyPos: [14, 8, 2],
                fillColor: 0xd0e2f0, fillIntensity: 0.32, fillPos: [-10, 6, 6],
                rimColor: 0xcfe8f5, rimIntensity: 0.4, rimPos: [10, 5, -8],
                target: [-0.5, 1.3, 0.5], ambientBoost: 0.22
            };
            group.userData.info = {
                title: 'Deluxe Suite',
                tagline: 'Floor-to-ceiling views and a bed dressed for rest.',
                points: [
                    { icon: 'fa-window-maximize', text: 'Floor-to-ceiling window wall with sheer curtains' },
                    { icon: 'fa-bed', text: 'King bed with a tailored gold-tone headboard' },
                    { icon: 'fa-chair', text: 'Private seating nook for morning coffee' },
                    { icon: 'fa-plug', text: 'Writing desk and wardrobe for extended stays' }
                ]
            };
            return group;
        }

        // ================================================================
        // SCENE 6 — RESTAURANT (new fine-dining scene)
        // Intimate evening dining mood: key light dropped very low (candlelit
        // feel), chandeliers over each table cluster do the visible work,
        // a cool blue rim from the far wall-of-glass suggests the evening sky
        // outside, separating tables from the dark room behind them.
        // ================================================================
        function buildRestaurantScene() {
            const group = new THREE.Group();

            const roomWidth = 22, roomDepth = 18, roomHeight = 4.6;

            const floor = new THREE.Mesh(new THREE.PlaneGeometry(roomWidth, roomDepth), MAT.marbleDark);
            floor.rotation.x = -Math.PI / 2;
            floor.receiveShadow = true;
            group.add(floor);

            const ceiling = new THREE.Mesh(new THREE.PlaneGeometry(roomWidth, roomDepth), MAT.ceilingInterior);
            ceiling.rotation.x = Math.PI / 2;
            ceiling.position.y = roomHeight;
            group.add(ceiling);

            const backWall = new THREE.Mesh(new THREE.PlaneGeometry(roomWidth, roomHeight), MAT.wallAccent);
            backWall.position.set(0, roomHeight / 2, -roomDepth / 2);
            backWall.receiveShadow = true;
            group.add(backWall);

            const leftWall = new THREE.Mesh(new THREE.PlaneGeometry(roomDepth, roomHeight), MAT.wallInterior);
            leftWall.rotation.y = Math.PI / 2;
            leftWall.position.set(-roomWidth / 2, roomHeight / 2, 0);
            group.add(leftWall);

            // Right wall is a full glass wall overlooking the (implied) city
            // or gardens at night — the scene's signature feature.
            const glassWall = new THREE.Mesh(new THREE.PlaneGeometry(roomDepth, roomHeight - 0.4), MAT.glass);
            glassWall.rotation.y = -Math.PI / 2;
            glassWall.position.set(roomWidth / 2 - 0.02, roomHeight / 2, 0);
            group.add(glassWall);
            for (let i = -3; i <= 3; i++) {
                const mullion = new THREE.Mesh(new THREE.BoxGeometry(0.05, roomHeight - 0.4, 0.06), MAT.bronze);
                mullion.position.set(roomWidth / 2 - 0.03, roomHeight / 2, i * (roomDepth / 7));
                group.add(mullion);
            }

            // Open show-kitchen pass along the back wall
            const passCounter = new THREE.Mesh(new THREE.BoxGeometry(7, 1.1, 1.0), MAT.marbleDark);
            passCounter.position.set(0, 0.55, -roomDepth / 2 + 1.2);
            passCounter.castShadow = true;
            group.add(passCounter);
            const passBack = new THREE.Mesh(new THREE.BoxGeometry(7, 2.0, 0.15), MAT.slate);
            passBack.position.set(0, 1.55, -roomDepth / 2 + 0.6);
            group.add(passBack);
            const passLight = new THREE.PointLight(0xfff0c8, 0.7, 8, 2);
            passLight.position.set(0, 2.2, -roomDepth / 2 + 1.4);
            group.add(passLight);

            // Wine display wall — a grid of small dark bottle-slots
            const wineRackMat = new THREE.MeshStandardMaterial({ color: 0x1c1712, roughness: 0.6 });
            const wineRack = new THREE.Mesh(new THREE.BoxGeometry(3.5, 2.2, 0.25), wineRackMat);
            wineRack.position.set(-roomWidth / 2 + 0.3, 2.3, -roomDepth / 2 + 2.5);
            group.add(wineRack);

            // ---- Dining tables — clustered rounds, each with its own
            // pendant/candle light for that classic fine-dining glow. ----
            function diningTable(x, z, seatCount) {
                const t = new THREE.Group();
                t.add(roundTable(0, 0, 0.7));
                const cloth = new THREE.Mesh(new THREE.CylinderGeometry(0.72, 0.72, 0.02, 32), MAT.linen);
                cloth.position.y = 0.75;
                t.add(cloth);
                const candle = new THREE.Mesh(new THREE.CylinderGeometry(0.03, 0.03, 0.14, 8), MAT.linen);
                candle.position.y = 0.83;
                t.add(candle);
                const candleFlame = new THREE.PointLight(0xffb066, 0.5, 3, 2);
                candleFlame.position.y = 0.95;
                t.add(candleFlame);
                for (let i = 0; i < seatCount; i++) {
                    const a = (Math.PI * 2 / seatCount) * i;
                    const seatChair = chair(Math.cos(a) * 1.05, Math.sin(a) * 1.05, -a + Math.PI, MAT.fabricGold);
                    t.add(seatChair);
                }
                t.position.set(x, 0, z);
                return t;
            }

            const tablePositions = [
                [-6, -3, 4], [0, -3, 4], [6, -3, 4],
                [-6, 3, 4], [0, 3, 4], [6, 3, 4],
                [-3, -3, -8], [3, -3, -8]
            ];
            tablePositions.forEach(([x, z]) => group.add(diningTable(x, z, 4)));

            // A pendant chandelier over each row of tables
            [-3.5, 3.5].forEach((cz) => {
                const pend = chandelier(0.9, 8, MAT.glassWarm);
                pend.position.set(0, roomHeight - 1.0, cz);
                group.add(pend);
            });

            // Sommelier station near the wine wall
            const station = new THREE.Mesh(new THREE.BoxGeometry(1.2, 1.0, 0.6), MAT.wood);
            station.position.set(-roomWidth / 2 + 2.2, 0.5, -roomDepth / 2 + 3.5);
            station.castShadow = true;
            group.add(station);

            group.add(potPlant(roomWidth / 2 - 1.5, roomDepth / 2 - 1.5, 1.5));
            group.add(potPlant(-roomWidth / 2 + 1.5, roomDepth / 2 - 1.5, 1.5));

            [[-roomWidth / 2 + 3, -2], [roomWidth / 2 - 3, -2], [-roomWidth / 2 + 3, 6], [roomWidth / 2 - 3, 6]].forEach(([sx, sz]) => {
                group.add(ceilingSpot(sx, sz, roomHeight - 0.05));
            });

            group.userData.camera = {
                radius: 17, theta: 0.3, phi: Math.PI / 2.45,
                target: new THREE.Vector3(0, 1.6, 0),
                minRadius: 8, maxRadius: 26, minPhi: 0.55, maxPhi: Math.PI / 2.05
            };
            group.userData.bg = 0x0e1420;
            group.userData.fog = [0x0e1420, 20, 48];
            group.userData.label = { icon: 'fa-utensils', text: 'Restaurant &mdash; Fine Dining' };
            group.userData.lighting = {
                hemi: 0.15,
                keyColor: 0xffd9a8, keyIntensity: 0.28, keyPos: [10, 7, 6],
                fillColor: 0x9fc7e8, fillIntensity: 0.22, fillPos: [16, 5, 0],
                rimColor: 0x7fb8e0, rimIntensity: 0.5, rimPos: [14, 4, -4],
                target: [0, 1.6, 0], ambientBoost: 0.4
            };
            group.userData.info = {
                title: 'Signature Restaurant',
                tagline: 'Candlelit tables beside a glass wall overlooking the night.',
                points: [
                    { icon: 'fa-wine-glass', text: 'Curated wine wall and dedicated sommelier station' },
                    { icon: 'fa-kitchen-set', text: 'Open show-kitchen pass for tableside theatre' },
                    { icon: 'fa-users', text: 'Seating for eight tables of four beneath pendant lighting' },
                    { icon: 'fa-city', text: 'Floor-to-ceiling glass wall facing the evening skyline' }
                ]
            };
            return group;
        }

        // ================================================================
        // SCENE 7 — POOL & TERRACE (new outdoor scene)
        // Bright midday poolside light: the brightest key of any scene (full
        // sun, minimal fog), a cool sky-blue fill bouncing off the water
        // itself, warm rim to catch the edges of umbrellas/loungers against
        // the pool's blue. Deliberately the "postcard" scene of the set.
        // ================================================================
        function buildPoolScene() {
            const group = new THREE.Group();

            const deckWidth = 34, deckDepth = 24;
            const deck = new THREE.Mesh(new THREE.PlaneGeometry(deckWidth, deckDepth), MAT.stoneWarm);
            deck.rotation.x = -Math.PI / 2;
            deck.receiveShadow = true;
            group.add(deck);

            // Deck plank seams for texture
            for (let i = -8; i <= 8; i++) {
                const seam = new THREE.Mesh(new THREE.PlaneGeometry(deckWidth, 0.03), new THREE.MeshStandardMaterial({ color: 0xc7b998, roughness: 1 }));
                seam.rotation.x = -Math.PI / 2;
                seam.position.set(0, 0.002, i * 1.4);
                group.add(seam);
            }

            // ---- Main pool ----
            const poolWidth = 16, poolDepth = 8;
            const poolRim = new THREE.Mesh(new THREE.BoxGeometry(poolWidth + 0.6, 0.15, poolDepth + 0.6), MAT.poolTile);
            poolRim.position.set(0, -0.02, 0);
            group.add(poolRim);
            const poolWater = new THREE.Mesh(new THREE.PlaneGeometry(poolWidth, poolDepth), MAT.poolWater);
            poolWater.rotation.x = -Math.PI / 2;
            poolWater.position.set(0, 0.02, 0);
            group.add(poolWater);
            // Submerged pool floor visible through the transparent water for depth
            const poolFloor = new THREE.Mesh(new THREE.PlaneGeometry(poolWidth - 0.4, poolDepth - 0.4), MAT.poolTile);
            poolFloor.rotation.x = -Math.PI / 2;
            poolFloor.position.set(0, -0.9, 0);
            group.add(poolFloor);
            // Lane-marker style stripe on the pool floor
            const laneMat = new THREE.MeshStandardMaterial({ color: 0x0f4d5c, roughness: 0.4 });
            const lane = new THREE.Mesh(new THREE.PlaneGeometry(poolWidth - 1, 0.2), laneMat);
            lane.rotation.x = -Math.PI / 2;
            lane.position.set(0, -0.895, 0);
            group.add(lane);

            // Small infinity-edge step down at one end
            const infinityEdge = new THREE.Mesh(new THREE.BoxGeometry(poolWidth + 0.6, 0.3, 0.5), MAT.poolTile);
            infinityEdge.position.set(0, -0.15, poolDepth / 2 + 0.3);
            group.add(infinityEdge);

            // ---- Loungers with umbrellas, arranged along both long sides ----
            function lounger(x, z, rotY) {
                const g = new THREE.Group();
                const base = new THREE.Mesh(new THREE.BoxGeometry(0.7, 0.35, 2.0), MAT.woodLight);
                base.position.y = 0.18;
                base.castShadow = true;
                g.add(base);
                const cushion = new THREE.Mesh(new THREE.BoxGeometry(0.66, 0.1, 1.9), MAT.linen);
                cushion.position.y = 0.4;
                cushion.castShadow = true;
                g.add(cushion);
                const backrest = new THREE.Mesh(new THREE.BoxGeometry(0.66, 0.55, 0.08), MAT.linen);
                backrest.position.set(0, 0.62, -0.85);
                backrest.rotation.x = -0.35;
                g.add(backrest);
                g.position.set(x, 0, z);
                g.rotation.y = rotY;
                return g;
            }
            function umbrella(x, z) {
                const g = new THREE.Group();
                const pole = new THREE.Mesh(new THREE.CylinderGeometry(0.05, 0.05, 2.4, 10), MAT.chrome);
                pole.position.y = 1.2;
                g.add(pole);
                const canopyTop = new THREE.Mesh(new THREE.ConeGeometry(1.4, 0.5, 10), MAT.fabricCream);
                canopyTop.position.y = 2.5;
                canopyTop.castShadow = true;
                g.add(canopyTop);
                g.position.set(x, 0, z);
                return g;
            }

            const loungerRowZ = poolDepth / 2 + 2.6;
            for (let i = -2; i <= 2; i++) {
                group.add(lounger(i * 2.6, loungerRowZ, Math.PI));
                if (i % 2 === 0) group.add(umbrella(i * 2.6 + 1.1, loungerRowZ));
            }
            for (let i = -2; i <= 2; i++) {
                group.add(lounger(i * 2.6, -loungerRowZ, 0));
                if (i % 2 === 0) group.add(umbrella(i * 2.6 + 1.1, -loungerRowZ));
            }

            // ---- Poolside bar ----
            const bar = new THREE.Group();
            const barCounter = new THREE.Mesh(new THREE.BoxGeometry(4.5, 1.1, 1.2), MAT.wood);
            barCounter.position.y = 0.55;
            barCounter.castShadow = true;
            bar.add(barCounter);
            const barTop = new THREE.Mesh(new THREE.BoxGeometry(4.7, 0.08, 1.35), MAT.marbleDark);
            barTop.position.y = 1.14;
            bar.add(barTop);
            const barRoofPoleGeo = new THREE.CylinderGeometry(0.06, 0.06, 2.6, 10);
            [[-2.1, -0.5], [2.1, -0.5], [-2.1, 0.5], [2.1, 0.5]].forEach(([px, pz]) => {
                const pole = new THREE.Mesh(barRoofPoleGeo, MAT.trunk);
                pole.position.set(px, 1.3, pz);
                bar.add(pole);
            });
            const barRoof = new THREE.Mesh(new THREE.CylinderGeometry(3.2, 3.4, 0.4, 8), MAT.leaf);
            barRoof.position.y = 2.65;
            barRoof.castShadow = true;
            bar.add(barRoof);
            bar.position.set(poolWidth / 2 + 4, 0, -poolDepth / 2 - 2);
            group.add(bar);

            group.add(palmTree(-poolWidth / 2 - 3, poolDepth / 2 + 5));
            group.add(palmTree(poolWidth / 2 + 3, poolDepth / 2 + 5));
            group.add(palmTree(-poolWidth / 2 - 5, -poolDepth / 2 - 5));
            group.add(palmTree(poolWidth / 2 + 5, -poolDepth / 2 - 5));

            // Low glass balustrade along the terrace edge, suggesting an
            // elevated infinity view
            for (let i = -6; i <= 6; i++) {
                const panel = new THREE.Mesh(new THREE.BoxGeometry(1.4, 0.8, 0.05), MAT.glass);
                panel.position.set(i * 1.5, 0.4, deckDepth / 2 - 0.3);
                group.add(panel);
            }
            const railTop = new THREE.Mesh(new THREE.BoxGeometry(deckWidth - 1, 0.06, 0.1), MAT.chrome);
            railTop.position.set(0, 0.8, deckDepth / 2 - 0.3);
            group.add(railTop);

            group.userData.camera = {
                radius: 24, theta: Math.PI / 5, phi: Math.PI / 2.6,
                target: new THREE.Vector3(0, 1, 0),
                minRadius: 12, maxRadius: 40, minPhi: 0.4, maxPhi: Math.PI / 2.1
            };
            group.userData.bg = 0x7ec8e3;
            group.userData.fog = [0x9fd8ec, 34, 110];
            group.userData.label = { icon: 'fa-umbrella-beach', text: 'Pool &amp; Terrace &mdash; Poolside' };
            group.userData.lighting = {
                hemi: 0.7,
                keyColor: 0xfff6d8, keyIntensity: 1.5, keyPos: [24, 34, 16],
                fillColor: 0xb8e2ec, fillIntensity: 0.5, fillPos: [-20, 14, -10],
                rimColor: 0xffe9b0, rimIntensity: 0.45, rimPos: [-12, 10, 18],
                target: [0, 1, 0], ambientBoost: 0
            };
            group.userData.info = {
                title: 'Pool &amp; Terrace',
                tagline: 'An infinity-edge escape framed by palms and open sky.',
                points: [
                    { icon: 'fa-water-ladder', text: 'Infinity-edge pool with a shallow lounging shelf' },
                    { icon: 'fa-umbrella-beach', text: 'Shaded loungers along both sides of the deck' },
                    { icon: 'fa-martini-glass', text: 'Thatched-roof poolside bar for drinks and light bites' },
                    { icon: 'fa-tree', text: 'Palm-lined terrace with a glass safety balustrade' }
                ]
            };
            return group;
        }

        // ================================================================
        // SCENE 8 — SPA & WELLNESS (new)
        // The darkest, most intimate scene in the set: key light nearly off,
        // warm candle-style point lights and underwater pool glow are almost
        // the only sources, a cool blue-green rim from the plunge pool keeps
        // the far wall from vanishing completely into black.
        // ================================================================
        function buildSpaScene() {
            const group = new THREE.Group();

            const roomWidth = 16, roomDepth = 14, roomHeight = 3.6;

            const floor = new THREE.Mesh(new THREE.PlaneGeometry(roomWidth, roomDepth), MAT.stoneWarm);
            floor.rotation.x = -Math.PI / 2;
            floor.receiveShadow = true;
            group.add(floor);

            const ceiling = new THREE.Mesh(new THREE.PlaneGeometry(roomWidth, roomDepth), MAT.ceilingInterior);
            ceiling.rotation.x = Math.PI / 2;
            ceiling.position.y = roomHeight;
            group.add(ceiling);

            const backWall = new THREE.Mesh(new THREE.PlaneGeometry(roomWidth, roomHeight), new THREE.MeshStandardMaterial({ color: 0x2e3b3a, roughness: 0.85 }));
            backWall.position.set(0, roomHeight / 2, -roomDepth / 2);
            backWall.receiveShadow = true;
            group.add(backWall);

            const leftWall = new THREE.Mesh(new THREE.PlaneGeometry(roomDepth, roomHeight), new THREE.MeshStandardMaterial({ color: 0x2e3b3a, roughness: 0.85 }));
            leftWall.rotation.y = Math.PI / 2;
            leftWall.position.set(-roomWidth / 2, roomHeight / 2, 0);
            group.add(leftWall);

            const rightWall = new THREE.Mesh(new THREE.PlaneGeometry(roomDepth, roomHeight), new THREE.MeshStandardMaterial({ color: 0x2e3b3a, roughness: 0.85 }));
            rightWall.rotation.y = -Math.PI / 2;
            rightWall.position.set(roomWidth / 2, roomHeight / 2, 0);
            group.add(rightWall);

            // ---- Central plunge pool, the room's glowing focal point ----
            const plungeRim = new THREE.Mesh(new THREE.CylinderGeometry(2.6, 2.6, 0.15, 32), MAT.poolTile);
            plungeRim.position.y = 0.02;
            group.add(plungeRim);
            const plungeWater = new THREE.Mesh(new THREE.CircleGeometry(2.4, 32), MAT.poolWater);
            plungeWater.rotation.x = -Math.PI / 2;
            plungeWater.position.y = 0.05;
            group.add(plungeWater);
            const underwaterGlow = new THREE.PointLight(0x4fd6e8, 1.2, 9, 2);
            underwaterGlow.position.y = -0.3;
            group.add(underwaterGlow);
            const underwaterGlow2 = new THREE.PointLight(0x4fd6e8, 0.6, 6, 2);
            underwaterGlow2.position.set(1.2, -0.2, 1.2);
            group.add(underwaterGlow2);

            // Stone steps down into the plunge pool
            [1, 2, 3].forEach((step) => {
                const stepMesh = new THREE.Mesh(new THREE.BoxGeometry(1.4, 0.1, 0.35), MAT.stoneWarm);
                stepMesh.position.set(0, 0.05 - step * 0.08, 2.2 + step * 0.3);
                group.add(stepMesh);
            });

            // ---- Massage / treatment table off to one side ----
            const table = new THREE.Group();
            const tableTop = new THREE.Mesh(new THREE.BoxGeometry(0.8, 0.1, 2.0), MAT.linen);
            tableTop.position.y = 0.75;
            tableTop.castShadow = true;
            table.add(tableTop);
            const tableLegGeo = new THREE.CylinderGeometry(0.04, 0.04, 0.7, 8);
            [[-0.35, -0.85], [0.35, -0.85], [-0.35, 0.85], [0.35, 0.85]].forEach(([lx, lz]) => {
                const leg = new THREE.Mesh(tableLegGeo, MAT.woodLight);
                leg.position.set(lx, 0.35, lz);
                table.add(leg);
            });
            const rolledTowel = new THREE.Mesh(new THREE.CylinderGeometry(0.1, 0.1, 0.4, 12), MAT.linen);
            rolledTowel.rotation.z = Math.PI / 2;
            rolledTowel.position.set(0, 0.85, -0.7);
            table.add(rolledTowel);
            table.position.set(-5.5, 0, -4);
            table.rotation.y = 0.3;
            group.add(table);

            // A small votive/candle cluster beside the treatment table
            for (let i = 0; i < 3; i++) {
                const candle = new THREE.Mesh(new THREE.CylinderGeometry(0.06, 0.07, 0.12 + i * 0.05, 10), MAT.linen);
                candle.position.set(-6.8 + i * 0.25, 0.06 + i * 0.025, -5.6);
                group.add(candle);
                const flame = new THREE.PointLight(0xffb066, 0.35, 3, 2);
                flame.position.set(-6.8 + i * 0.25, 0.2 + i * 0.05, -5.6);
                group.add(flame);
            }

            // Stacked hot-stone tray prop
            const stoneTrayBase = new THREE.Mesh(new THREE.CylinderGeometry(0.22, 0.24, 0.04, 16), MAT.wood);
            stoneTrayBase.position.set(5.5, 0.4, -5.5);
            group.add(stoneTrayBase);
            for (let i = 0; i < 4; i++) {
                const stone = new THREE.Mesh(new THREE.SphereGeometry(0.14 - i * 0.015, 10, 10), MAT.slate);
                stone.scale.y = 0.4;
                stone.position.set(5.5, 0.43 + i * 0.05, -5.5);
                group.add(stone);
            }

            // Bamboo/reed room divider screen for a spa-specific texture note
            const screenMat = new THREE.MeshStandardMaterial({ color: 0x8a7550, roughness: 0.75 });
            for (let i = -4; i <= 4; i++) {
                const reed = new THREE.Mesh(new THREE.CylinderGeometry(0.04, 0.04, 2.2, 8), screenMat);
                reed.position.set(6.2, 1.1, -1 + i * 0.22);
                group.add(reed);
            }

            group.add(potPlant(-roomWidth / 2 + 1.3, roomDepth / 2 - 1.3, 1.7));
            group.add(potPlant(roomWidth / 2 - 1.3, roomDepth / 2 - 1.3, 1.7));

            // Recessed ceiling spots kept dim and few — this room reads mostly
            // by the pool glow and candlelight, not overhead lighting.
            group.add(ceilingSpot(0, -3, roomHeight - 0.05));
            group.add(ceilingSpot(-4, 4, roomHeight - 0.05));

            group.userData.camera = {
                radius: 13, theta: 0.4, phi: Math.PI / 2.5,
                target: new THREE.Vector3(0, 1, -1),
                minRadius: 6, maxRadius: 20, minPhi: 0.55, maxPhi: Math.PI / 2.05
            };
            group.userData.bg = 0x0a1414;
            group.userData.fog = [0x0a1414, 14, 34];
            group.userData.label = { icon: 'fa-spa', text: 'Spa &amp; Wellness &mdash; Private Retreat' };
            group.userData.lighting = {
                hemi: 0.1,
                keyColor: 0xffd9b0, keyIntensity: 0.18, keyPos: [4, 5, 6],
                fillColor: 0x4fd6c8, fillIntensity: 0.15, fillPos: [-6, 3, -3],
                rimColor: 0x4fd6e8, rimIntensity: 0.55, rimPos: [0, 2.5, 4],
                target: [0, 1, -1], ambientBoost: 0.3
            };
            group.userData.info = {
                title: 'Spa &amp; Wellness',
                tagline: 'A candlelit plunge pool at the heart of a private retreat.',
                points: [
                    { icon: 'fa-water', text: 'Glowing plunge pool with stone entry steps' },
                    { icon: 'fa-spa', text: 'Private massage and treatment table' },
                    { icon: 'fa-fire', text: 'Hot-stone therapy tray and votive candles' },
                    { icon: 'fa-leaf', text: 'Reed room divider and potted greenery for calm' }
                ]
            };
            return group;
        }

        const scene = new THREE.Scene();

        // ================================================================
        // LIGHTING RIG — proper 3-point setup (key / fill / rim) instead of
        // a single directional "sun" + flat hemisphere. Each scene supplies
        // a lightingProfile in userData describing how to angle/color/tune
        // the rig for that space (exterior daylight vs. warm interior vs.
        // dusk poolside vs. spa candlelight), so the same three lights do
        // very different jobs across scenes without duplicating setup code.
        // ================================================================

        // Soft sky/ground bounce — always on, intensity is the one thing
        // scenes vary (higher outdoors, lower in enclosed interiors).
        const hemiLight = new THREE.HemisphereLight(0xffffff, 0x8d8d8d, 0.55);
        scene.add(hemiLight);

        // KEY — the dominant directional light, casts the shadows.
        const keyLight = new THREE.DirectionalLight(0xfff3d6, 1.3);
        keyLight.castShadow = true;
        keyLight.shadow.mapSize.width = 2048;
        keyLight.shadow.mapSize.height = 2048;
        keyLight.shadow.camera.left = -40;
        keyLight.shadow.camera.right = 40;
        keyLight.shadow.camera.top = 40;
        keyLight.shadow.camera.bottom = -40;
        keyLight.shadow.camera.far = 130;
        keyLight.shadow.bias = -0.0003;
        scene.add(keyLight);
        scene.add(keyLight.target);

        // FILL — softer, cooler, opposite side from key, lifts shadow density
        // without casting its own (keeps shadow cost to one map).
        const fillLight = new THREE.DirectionalLight(0xcfe0f2, 0.35);
        scene.add(fillLight);
        scene.add(fillLight.target);

        // RIM — a subtle backlight that separates subject edges from the
        // background, the detail flat single-light setups always miss.
        const rimLight = new THREE.DirectionalLight(0xffe9c2, 0.5);
        scene.add(rimLight);
        scene.add(rimLight.target);

        // Ambient top-up used only by enclosed interior scenes so they don't
        // read as underlit once the key light is dialed down from its
        // bright-exterior value.
        const interiorFill = new THREE.AmbientLight(0xfff1da, 0);
        scene.add(interiorFill);

        // Apply a scene's lightingProfile to the shared rig. Called from
        // activateScene() on every switch.
        function applyLightingProfile(profile) {
            hemiLight.intensity = profile.hemi;

            keyLight.color.setHex(profile.keyColor);
            keyLight.intensity = profile.keyIntensity;
            keyLight.position.set(profile.keyPos[0], profile.keyPos[1], profile.keyPos[2]);
            keyLight.target.position.set(profile.target[0], profile.target[1], profile.target[2]);
            keyLight.shadow.camera.updateProjectionMatrix();

            fillLight.color.setHex(profile.fillColor);
            fillLight.intensity = profile.fillIntensity;
            fillLight.position.set(profile.fillPos[0], profile.fillPos[1], profile.fillPos[2]);
            fillLight.target.position.set(profile.target[0], profile.target[1], profile.target[2]);

            rimLight.color.setHex(profile.rimColor);
            rimLight.intensity = profile.rimIntensity;
            rimLight.position.set(profile.rimPos[0], profile.rimPos[1], profile.rimPos[2]);
            rimLight.target.position.set(profile.target[0], profile.target[1], profile.target[2]);

            interiorFill.intensity = profile.ambientBoost || 0;
        }

                // ---- Assemble all scenes once, toggle visibility on switch ----
                const SCENES = {
                    overview:   buildOverviewScene(),
                    entrance:   buildEntranceScene(),
                    reception:  buildReceptionScene(),
                    hallway:    buildHallwayScene(),
                    suite:      buildSuiteScene(),
                    restaurant: buildRestaurantScene(),
                    pool:       buildPoolScene(),
                    spa:        buildSpaScene()
                };
                Object.keys(SCENES).forEach((key) => {
                    SCENES[key].visible = false;
                    scene.add(SCENES[key]);
                });

                let activeKey = 'overview';
                let cam = null; // active scene's live camera-rig state (radius/theta/phi/target)

                const infoPanelEl = document.getElementById('hotel3DInfoPanel');
                const infoToggleBtn = document.getElementById('hotel3DInfoBtn');
                let infoPanelOpen = false;

                function renderInfoPanel(key) {
                    if (!infoPanelEl) return;
                    const info = SCENES[key].userData.info;
                    if (!info) { infoPanelEl.innerHTML = ''; return; }
                    const pointsHtml = info.points.map((p) =>
                        `<li><i class="fa-solid ${p.icon}"></i><span>${p.text}</span></li>`
                    ).join('');
                    infoPanelEl.innerHTML = `
                        <div class="hotel-3d-info-header">
                            <h4>${info.title}</h4>
                            <button type="button" class="hotel-3d-info-close" id="hotel3DInfoCloseBtn" aria-label="Close info panel">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </div>
                        <p class="hotel-3d-info-tagline">${info.tagline}</p>
                        <ul class="hotel-3d-info-list">${pointsHtml}</ul>
                    `;
                    const closeBtn = document.getElementById('hotel3DInfoCloseBtn');
                    if (closeBtn) closeBtn.addEventListener('click', () => setInfoPanelOpen(false));
                }

                function setInfoPanelOpen(open) {
                    infoPanelOpen = open;
                    if (infoPanelEl) infoPanelEl.classList.toggle('is-open', open);
                    if (infoToggleBtn) infoToggleBtn.classList.toggle('is-active', open);
                }

                if (infoToggleBtn) {
                    infoToggleBtn.addEventListener('click', () => setInfoPanelOpen(!infoPanelOpen));
                }

                function activateScene(key) {
                    if (!SCENES[key]) return;
                    Object.keys(SCENES).forEach((k) => { SCENES[k].visible = (k === key); });
                    activeKey = key;

                    const cfg = SCENES[key].userData;
                    scene.background = new THREE.Color(cfg.bg);
                    scene.fog = new THREE.Fog(cfg.fog[0], cfg.fog[1], cfg.fog[2]);
                    applyLightingProfile(cfg.lighting);

                    cam = {
                        radius: cfg.camera.radius,
                        theta: cfg.camera.theta,
                        phi: cfg.camera.phi,
                        target: cfg.camera.target.clone(),
                        minRadius: cfg.camera.minRadius,
                        maxRadius: cfg.camera.maxRadius,
                        minPhi: cfg.camera.minPhi,
                        maxPhi: cfg.camera.maxPhi
                    };
                    updateCameraPosition();

                    if (sceneLabelEl) {
                        sceneLabelEl.innerHTML = `<i class="fa-solid ${cfg.label.icon}"></i><span>${cfg.label.text}</span>`;
                    }
                    renderInfoPanel(key);
                }

                function updateCameraPosition() {
                    if (!cam) return;
                    camera.position.x = cam.target.x + cam.radius * Math.sin(cam.phi) * Math.sin(cam.theta);
                    camera.position.y = cam.target.y + cam.radius * Math.cos(cam.phi);
                    camera.position.z = cam.target.z + cam.radius * Math.sin(cam.phi) * Math.cos(cam.theta);
                    camera.lookAt(cam.target);
                }

                activateScene('overview');

                // ---- Scene tab switcher ----
                const tabRow = document.getElementById('sceneTabRow');
                if (tabRow) {
                    tabRow.querySelectorAll('.scene-tab-chip').forEach((chip) => {
                        chip.addEventListener('click', () => {
                            tabRow.querySelectorAll('.scene-tab-chip').forEach((c) => {
                                c.classList.remove('active-scene-chip');
                                c.setAttribute('aria-selected', 'false');
                            });
                            chip.classList.add('active-scene-chip');
                            chip.setAttribute('aria-selected', 'true');
                            autoRotate = false;
                            activateScene(chip.dataset.scene);
                        });
                    });
                }

                // ---- Manual orbit (drag) + zoom (wheel) controls, with inertia damping ----
                let isDragging = false;
                let lastX = 0, lastY = 0;
                let velTheta = 0, velPhi = 0;
                let autoRotate = true;

                renderer.domElement.style.cursor = 'grab';

                renderer.domElement.addEventListener('pointerdown', (e) => {
                    isDragging = true;
                    autoRotate = false;
                    lastX = e.clientX;
                    lastY = e.clientY;
                    velTheta = 0; velPhi = 0;
                    renderer.domElement.style.cursor = 'grabbing';
                });
                window.addEventListener('pointerup', () => {
                    isDragging = false;
                    renderer.domElement.style.cursor = 'grab';
                });
                window.addEventListener('pointermove', (e) => {
                    if (!isDragging || !cam) return;
                    const dx = e.clientX - lastX;
                    const dy = e.clientY - lastY;
                    lastX = e.clientX;
                    lastY = e.clientY;
                    velTheta = -dx * 0.007;
                    velPhi = -dy * 0.007;
                    cam.theta += velTheta;
                    cam.phi += velPhi;
                    cam.phi = Math.max(cam.minPhi, Math.min(cam.maxPhi, cam.phi));
                    updateCameraPosition();
                });
                renderer.domElement.addEventListener('wheel', (e) => {
                    if (!cam) return;
                    e.preventDefault();
                    cam.radius += e.deltaY * 0.03;
                    cam.radius = Math.max(cam.minRadius, Math.min(cam.maxRadius, cam.radius));
                    updateCameraPosition();
                }, { passive: false });

                // Touch support (single-finger drag to orbit, two-finger pinch to zoom)
                let pinchStartDist = null;
                renderer.domElement.addEventListener('touchstart', (e) => {
                    if (e.touches.length === 1) {
                        isDragging = true;
                        autoRotate = false;
                        lastX = e.touches[0].clientX;
                        lastY = e.touches[0].clientY;
                        velTheta = 0; velPhi = 0;
                    } else if (e.touches.length === 2) {
                        isDragging = false;
                        const dx = e.touches[0].clientX - e.touches[1].clientX;
                        const dy = e.touches[0].clientY - e.touches[1].clientY;
                        pinchStartDist = Math.hypot(dx, dy);
                    }
                }, { passive: true });
                renderer.domElement.addEventListener('touchmove', (e) => {
                    if (!cam) return;
                    if (e.touches.length === 1 && isDragging) {
                        const dx = e.touches[0].clientX - lastX;
                        const dy = e.touches[0].clientY - lastY;
                        lastX = e.touches[0].clientX;
                        lastY = e.touches[0].clientY;
                        velTheta = -dx * 0.007;
                        velPhi = -dy * 0.007;
                        cam.theta += velTheta;
                        cam.phi += velPhi;
                        cam.phi = Math.max(cam.minPhi, Math.min(cam.maxPhi, cam.phi));
                        updateCameraPosition();
                    } else if (e.touches.length === 2 && pinchStartDist !== null) {
                        const dx = e.touches[0].clientX - e.touches[1].clientX;
                        const dy = e.touches[0].clientY - e.touches[1].clientY;
                        const dist = Math.hypot(dx, dy);
                        cam.radius += (pinchStartDist - dist) * 0.05;
                        cam.radius = Math.max(cam.minRadius, Math.min(cam.maxRadius, cam.radius));
                        pinchStartDist = dist;
                        updateCameraPosition();
                    }
                }, { passive: true });
                renderer.domElement.addEventListener('touchend', () => {
                    isDragging = false;
                    pinchStartDist = null;
                });

                // ---- Fullscreen toggle ----
                function isFullscreenActive() {
                    return !!(document.fullscreenElement || document.webkitFullscreenElement);
                }
                function refreshFullscreenIcon() {
                    if (!fullscreenBtn) return;
                    const icon = fullscreenBtn.querySelector('i');
                    if (icon) icon.className = isFullscreenActive() ? 'fa-solid fa-compress' : 'fa-solid fa-expand';
                    fullscreenBtn.setAttribute('aria-label', isFullscreenActive() ? 'Exit fullscreen' : 'Toggle fullscreen');
                }
                if (fullscreenBtn && viewerShell) {
                    fullscreenBtn.addEventListener('click', () => {
                        if (!isFullscreenActive()) {
                            const req = viewerShell.requestFullscreen || viewerShell.webkitRequestFullscreen;
                            if (req) req.call(viewerShell);
                        } else {
                            const exit = document.exitFullscreen || document.webkitExitFullscreen;
                            if (exit) exit.call(document);
                        }
                    });
                    document.addEventListener('fullscreenchange', () => {
                        viewerShell.classList.toggle('is-fullscreen-3d', isFullscreenActive());
                        refreshFullscreenIcon();
                        handleResize();
                    });
                    document.addEventListener('webkitfullscreenchange', () => {
                        viewerShell.classList.toggle('is-fullscreen-3d', isFullscreenActive());
                        refreshFullscreenIcon();
                        handleResize();
                    });
                }

                // ---- Resize handling ----
                function handleResize() {
                    const w = mount.clientWidth;
                    const h = mount.clientHeight || 520;
                    camera.aspect = w / h;
                    camera.updateProjectionMatrix();
                    renderer.setSize(w, h);
                }
                window.addEventListener('resize', handleResize);

                // ---- Render loop ----
                function animate() {
                    requestAnimationFrame(animate);
                    if (autoRotate && !isDragging && cam) {
                        cam.theta += 0.0016;
                        updateCameraPosition();
                    } else if (!isDragging && cam && (Math.abs(velTheta) > 0.0001 || Math.abs(velPhi) > 0.0001)) {
                        // Inertia: let the drag glide to a stop instead of snapping still
                        velTheta *= 0.92;
                        velPhi *= 0.92;
                        cam.theta += velTheta;
                        cam.phi += velPhi;
                        cam.phi = Math.max(cam.minPhi, Math.min(cam.maxPhi, cam.phi));
                        updateCameraPosition();
                    }
                    renderer.render(scene, camera);
                }

                if (loadingEl) loadingEl.style.display = 'none';
                animate();

        } catch (err) {
            // Any failure during setup (WebGL unavailable, GPU/driver
            // issue, an unexpected DOM state, etc.) lands here instead of
            // leaving the loading spinner running forever with no
            // explanation. The real error is still logged to the console
            // for debugging.
            console.error('Hotel 3D viewer failed to initialize:', err);
            showViewerError('3D view unavailable on this device or browser. You can still explore the hotel using the photos above.');
        }
    })();


    // ---- Accommodations: floor filter (mirrors book.php's filterRoomsByFloor) ----
    function filterHomeRoomsByFloor(floor, chipEl) {
        document.querySelectorAll('#rooms .floor-filter-chip').forEach(c => c.classList.remove('active-floor-chip'));
        chipEl.classList.add('active-floor-chip');

        const cards = document.querySelectorAll('#home_room_grid_target .room-unit-card');
        let visibleCount = 0;
        cards.forEach(card => {
            const cardFloor = card.getAttribute('data-floor');
            const matches = (floor === 'all' || cardFloor === floor);
            card.style.display = matches ? '' : 'none';
            if (matches) visibleCount++;
        });

        const emptyMsg = document.getElementById('home_no_rooms_on_floor_msg');
        const gridTarget = document.getElementById('home_room_grid_target');
        if (emptyMsg && gridTarget) {
            emptyMsg.style.display = (visibleCount === 0) ? 'flex' : 'none';
            gridTarget.style.display = (visibleCount === 0) ? 'none' : '';
        }
    }

    // ---- Guest Feedback: star selector + AJAX submit (existing behavior) ----
    document.addEventListener("DOMContentLoaded", function() {
        const stars = document.querySelectorAll(".selector-star");
        const scoreInput = document.getElementById("hidden_rating_score");

        function paintStars(score) {
            stars.forEach((s, idx) => {
                s.classList.toggle('is-active', idx < score);
            });
        }
        paintStars(5);

        stars.forEach(star => {
            star.addEventListener("click", function() {
                const score = parseInt(this.dataset.score);
                scoreInput.value = score;
                paintStars(score);
            });
        });

        const form = document.getElementById("ajaxReviewSubmissionForm");
        if (form) {
            const submitBtn = form.querySelector(".review-form-submit");

            form.addEventListener("submit", function(e) {
                e.preventDefault();
                const payload = new FormData(this);
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.dataset.originalText = submitBtn.textContent;
                    submitBtn.textContent = "Posting...";
                }

                fetch("index.php", { method: "POST", body: payload })
                .then(res => res.json())
                .then(data => {
                    if (data.status === "success") {
                        // Review is saved - post it automatically, no popup needed.
                        window.__latestReviewId = Math.max(window.__latestReviewId || 0, data.id || 0);
                        prependReviewCard(data);
                        form.reset();
                        scoreInput.value = 5;
                        paintStars(5);
                    } else {
                        // A real, expected failure the server told us about
                        // (e.g. empty review) - show its specific message.
                        alert(data.message);
                    }
                })
                .catch(() => {
                    // Only reached on a genuine network/connectivity failure
                    // now that the server always returns clean JSON.
                    alert("We couldn't reach the server to post your review. Please check your connection and try again.");
                })
                .finally(() => {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = submitBtn.dataset.originalText || "Post Review";
                    }
                });
            });
        }

        // ---- Contact section: cancellation request AJAX submit ----
        const cancelForm = document.getElementById("ajaxCancellationRequestForm");
        if (cancelForm) {
            cancelForm.addEventListener("submit", function(e) {
                e.preventDefault();
                const btn = document.getElementById("cancel_request_submit_btn");
                const feedback = document.getElementById("cancel_request_feedback");
                const originalBtnHtml = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...';
                feedback.className = "contact-cancel-feedback";
                feedback.textContent = "";

                const payload = new FormData(this);
                fetch("index.php", { method: "POST", body: payload })
                .then(res => res.json())
                .then(data => {
                    feedback.textContent = data.message;
                    feedback.classList.add(data.status === "success" ? "is-success" : "is-error");
                    if (data.status === "success") {
                        cancelForm.reset();
                    }
                })
                .catch(() => {
                    feedback.textContent = "We couldn't reach the server to submit your request. Please check your connection and try again.";
                    feedback.classList.add("is-error");
                })
                .finally(() => {
                    btn.disabled = false;
                    btn.innerHTML = originalBtnHtml;
                });
            });
        }
    });

    // ---- Guest Feedback: prepend a freshly-submitted or freshly-polled review ----
    function prependReviewCard(data) {
        const noRevMsg = document.getElementById("no_reviews_msg");
        if (noRevMsg) noRevMsg.remove();

        // Avoid double-rendering a review this tab itself just submitted,
        // once the next poll cycle also picks it up from the server.
        if (data.id && document.querySelector(`.review-card[data-review-id="${data.id}"]`)) return;

        let starMarkup = "";
        for (let i = 1; i <= 5; i++) {
            starMarkup += `<i class="${i <= data.rating ? 'fa-solid' : 'fa-regular'} fa-star"></i>`;
        }

        const nextCard = document.createElement("div");
        nextCard.className = "review-card review-card--incoming";
        if (data.id) nextCard.setAttribute('data-review-id', data.id);
        nextCard.innerHTML = `
            <div>
                <div class="review-card-stars">${starMarkup}</div>
                <p class="review-card-text">"${data.review_text}"</p>
            </div>
            <div class="review-card-footer">
                <strong>${data.user_name}</strong>
                <span>${data.date}</span>
            </div>`;

        const feed = document.getElementById("reviews_feed_grid");
        feed.insertBefore(nextCard, feed.firstChild);
        // Drop the "incoming" highlight class after the entrance animation runs once.
        requestAnimationFrame(() => setTimeout(() => nextCard.classList.remove('review-card--incoming'), 1200));
    }

    // ---- Guest Feedback: real-time polling ----
    // No native push channel in this PHP/MySQL stack, so "real time" here
    // means short-interval polling of testimonials_ajax.php, only ever
    // appending reviews newer than the highest id this tab has already
    // rendered. This is an honest, simple approach rather than a fake
    // websocket claim.
    (function() {
        window.__latestReviewId = <?= (int)$latest_review_id ?>;
        const POLL_INTERVAL_MS = 8000;

        function pollForNewReviews() {
            fetch(`testimonials_ajax.php?action=fetch_latest&since_id=${window.__latestReviewId}`)
                .then(res => res.json())
                .then(data => {
                    if (!data.success || !data.reviews || data.reviews.length === 0) return;
                    // Server returns newest-first; insert oldest-of-the-batch first
                    // so the very newest ends up on top after all inserts.
                    [...data.reviews].reverse().forEach(rv => {
                        prependReviewCard({
                            id: rv.id,
                            rating: rv.rating,
                            review_text: rv.review_text,
                            user_name: rv.guest_name,
                            date: rv.date
                        });
                    });
                    window.__latestReviewId = data.latest_id;
                })
                .catch(() => { /* silent - next interval will retry */ });
        }

        setInterval(pollForNewReviews, POLL_INTERVAL_MS);
    })();
</script>

</body>
</html>
<?php
$conn->close();
// Normal page render (not one of the AJAX branches above, which each
// exit via send_json() before reaching here): flush the buffered HTML
// out to the browser now that we know nothing needs to intercept it.
if (ob_get_length() !== false) {
    ob_end_flush();
}
?>