<?php
/**
 * wallet_topup_verify.php
 *
 * Read-only AJAX endpoint called by wallet_topup.php the moment it
 * loads, to auto-detect which Haven Hotel account is topping up (the
 * "It have automatic detect which account" requirement) - now done via
 * the account ALREADY authenticated in $_SESSION['user_id'], not a raw
 * id read off the URL.
 *
 * SECURITY FIX: the previous version trusted a plain ?uid=N query
 * parameter and credited/verified whatever account number was in the
 * link - a guest could edit that number in the address bar and act on
 * a completely different account. This endpoint (and
 * wallet_topup_process.php) now pull user_id from the PHP session that
 * dashboard.php already establishes, so there is nothing left in the
 * URL for a guest to tamper with. wallet_topup.php is opened via
 * window.open() from the SAME origin, so it shares that session
 * cookie automatically - no token or extra handshake needed.
 *
 * GET /wallet_topup_verify.php
 * -> { success: true, masked_name: "J*** D***", needs_mpin_setup: bool }
 * -> { success: false, error: "..." }
 */

session_start();

require_once __DIR__ . '/includes/payment_wallet_engine.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    // Matches dashboard.php's own guard: no session, no wallet access.
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Your session has expired. Please log in to Haven Hotel again and reopen Add Money from your dashboard.']);
    exit();
}

$host     = "localhost";
$db_user  = "root";
$db_pass  = "";
$db_name  = "haven_hotel";
$conn = new mysqli($host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Could not reach the hotel system right now.']);
    exit();
}

ensure_payment_wallet_schema($conn);

$user_id = (int)$_SESSION['user_id'];

$masked_name = get_masked_account_name($conn, $user_id);

if ($masked_name === null) {
    // Session pointed at an account that no longer exists - extremely
    // unlikely, but fail closed rather than showing a broken form.
    echo json_encode(['success' => false, 'error' => 'We could not find your account. Please log in again.']);
} else {
    echo json_encode([
        'success' => true,
        'masked_name' => $masked_name,
        'needs_mpin_setup' => !user_has_mpin($conn, $user_id),
    ]);
}

$conn->close();