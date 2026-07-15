<?php
// testimonials_ajax.php
// Polling endpoint backing the "real-time" Guest Feedback feed on index.php.
// PHP + MySQL has no native push channel, so "real time" here means the
// front-end polls this endpoint on an interval and only appends rows newer
// than the last one it already has - honest short-polling, not a fake
// websocket. Mirrors the existing notifications_ajax.php pattern used by
// book.php / dashboard.php for the notification bell.

session_start();
header('Content-Type: application/json; charset=utf-8');

$host     = "localhost";
$db_user  = "root";
$db_pass  = "";
$db_name  = "haven_hotel";
$conn = new mysqli($host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit();
}

$action = $_GET['action'] ?? 'fetch_latest';

if ($action === 'fetch_latest') {
    // since_id: the highest testimonial id the client already has rendered.
    // 0 means "give me everything" (first load).
    $since_id = isset($_GET['since_id']) ? (int)$_GET['since_id'] : 0;

    $stmt = $conn->prepare("
        SELECT t.id, t.rating, t.review_text, t.created_at,
               CONCAT(u.first_name, ' ', u.last_name) AS guest_name
        FROM testimonials t
        LEFT JOIN users u ON t.user_id = u.id
        WHERE t.id > ?
        ORDER BY t.created_at DESC
    ");
    $stmt->bind_param("i", $since_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    $max_id_seen = $since_id;
    while ($row = $res->fetch_assoc()) {
        $rows[] = [
            'id'          => (int)$row['id'],
            'rating'      => (int)$row['rating'],
            'review_text' => htmlspecialchars($row['review_text']),
            'guest_name'  => htmlspecialchars($row['guest_name'] ?? 'Anonymous Guest'),
            'date'        => date('M d, Y', strtotime($row['created_at'])),
        ];
        if ((int)$row['id'] > $max_id_seen) {
            $max_id_seen = (int)$row['id'];
        }
    }
    $stmt->close();

    echo json_encode([
        'success'    => true,
        'reviews'    => $rows,
        'latest_id'  => $max_id_seen,
    ]);
    exit();
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);
$conn->close();