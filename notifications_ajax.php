<?php
// notifications_ajax.php
// Backs the notification bell dropdown on book.php, dashboard.php, and any
// other guest-facing page that includes the same bell markup/JS.
// Returns JSON only - no HTML shell.
//
// Two kinds of notification live in the same table:
//   - PERSONAL  (user_id = <this guest's id>)  e.g. booking confirmations.
//     Read-state is the real is_read column, same as before.
//   - BROADCAST (user_id IS NULL)  e.g. "new room added", "room sold out",
//     sent by admin_dashboard.php to every guest at once.
//     A broadcast row's is_read column is NEVER flipped by a guest viewing
//     it - if it were, the first guest to open their bell would mark it
//     read for every other guest too. Instead, each guest's session tracks
//     which broadcast IDs THEY have already seen.
//
// Actions:
//   GET  ?action=fetch   -> returns the most recent personal + broadcast
//                           notifications for the logged-in guest, marks
//                           personal ones read in the DB, marks broadcast
//                           ones seen in this guest's session only.

session_start();
header('Content-Type: application/json');

// Database Configuration Parameters (mirrors book.php)
$host     = "localhost";
$db_user  = "root";
$db_pass  = "";
$db_name  = "haven_hotel";
$conn = new mysqli($host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed.']);
    exit();
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated.']);
    exit();
}

$u_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';

if (!isset($_SESSION['seen_broadcast_ids'])) {
    $_SESSION['seen_broadcast_ids'] = [];
}

if ($action === 'fetch') {

    // user_id = ? picks up this guest's personal notifications;
    // user_id IS NULL picks up broadcasts sent to every guest.
    $stmt = $conn->prepare(
        "SELECT id, user_id, message, is_read, created_at
         FROM notifications
         WHERE user_id = ? OR user_id IS NULL
         ORDER BY created_at DESC LIMIT 20"
    );
    $stmt->bind_param("i", $u_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $notifications = [];
    $unread_personal_ids = [];
    $newly_seen_broadcast_ids = [];
    $already_seen = $_SESSION['seen_broadcast_ids'];

    while ($row = $result->fetch_assoc()) {
        $id = (int)$row['id'];
        $is_broadcast = ($row['user_id'] === null);

        if ($is_broadcast) {
            $is_read_for_this_guest = in_array($id, $already_seen, true);
            if (!$is_read_for_this_guest) {
                $newly_seen_broadcast_ids[] = $id;
            }
        } else {
            $is_read_for_this_guest = (bool)$row['is_read'];
            if (!$is_read_for_this_guest) {
                $unread_personal_ids[] = $id;
            }
        }

        $notifications[] = [
            'id'          => $id,
            'message'     => $row['message'],
            'is_read'     => $is_read_for_this_guest,
            'is_broadcast'=> $is_broadcast,
            'created_at'  => $row['created_at'],
        ];
    }
    $stmt->close();

    // Mark personal notifications read in the DB, exactly as before.
    if (!empty($unread_personal_ids)) {
        $placeholders = implode(',', array_fill(0, count($unread_personal_ids), '?'));
        $types = str_repeat('i', count($unread_personal_ids));
        $update_stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id IN ($placeholders) AND user_id = ?");
        $bind_values = $unread_personal_ids;
        $bind_values[] = $u_id;
        $types .= 'i';
        $update_stmt->bind_param($types, ...$bind_values);
        $update_stmt->execute();
        $update_stmt->close();
    }

    // Mark broadcasts seen in THIS guest's session only - never touches the DB row.
    if (!empty($newly_seen_broadcast_ids)) {
        $_SESSION['seen_broadcast_ids'] = array_values(array_unique(array_merge($already_seen, $newly_seen_broadcast_ids)));
    }

    echo json_encode([
        'success'       => true,
        'notifications' => $notifications,
        'marked_read'   => count($unread_personal_ids) + count($newly_seen_broadcast_ids),
    ]);
    exit();
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Unknown action.']);
$conn->close();