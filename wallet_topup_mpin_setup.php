<?php
/**
 * wallet_topup_mpin_setup.php
 *
 * One-time MPIN creation, forced by wallet_topup.php before a guest
 * who has never set one can reach the top-up form (the "if detected
 * the user doesn't have MPIN, it will force to add MPIN before login"
 * requirement). Runs against $_SESSION['user_id'] only - same reasoning
 * as wallet_topup_verify.php / wallet_topup_process.php.
 *
 * POST /wallet_topup_mpin_setup.php  (mpin, mpin_confirm)
 * -> { success: true }
 * -> { success: false, error: "..." }
 */

session_start();

require_once __DIR__ . '/includes/payment_wallet_engine.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit();
}

if (!isset($_SESSION['user_id'])) {
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

$user_id      = (int)$_SESSION['user_id'];
$mpin         = (string)($_POST['mpin'] ?? '');
$mpin_confirm = (string)($_POST['mpin_confirm'] ?? '');

if (get_masked_account_name($conn, $user_id) === null) {
    echo json_encode(['success' => false, 'error' => 'We could not find your account. Please log in again.']);
    exit();
}

if (user_has_mpin($conn, $user_id)) {
    // Already set (e.g. two tabs racing this endpoint) - nothing to do,
    // and we never let this endpoint silently overwrite an existing
    // MPIN, since that would let someone lock the real owner out.
    echo json_encode(['success' => true]);
    exit();
}

if (!ctype_digit($mpin) || strlen($mpin) !== 6) {
    echo json_encode(['success' => false, 'error' => 'MPIN must be exactly 6 digits.']);
    exit();
}

if ($mpin !== $mpin_confirm) {
    echo json_encode(['success' => false, 'error' => 'MPINs do not match. Please try again.']);
    exit();
}

set_user_mpin($conn, $user_id, $mpin);

echo json_encode(['success' => true]);

$conn->close();