<?php
/**
 * wallet_topup_process.php
 *
 * Called by wallet_topup.php AFTER the guest has typed an amount,
 * confirmed their MPIN, and clicked "Yes" on the "Are you sure this
 * amount will be added on your account" confirmation. Credits the
 * wallet directly via post_wallet_transaction().
 *
 * SECURITY FIX: this used to accept a plain $_POST['uid'] and trust it
 * completely - anyone could POST a different uid and credit someone
 * else's wallet. It now takes user_id from $_SESSION (the same session
 * dashboard.php requires to reach this flow at all) and additionally
 * re-verifies the guest's MPIN server-side on every top-up, not just
 * once at page load - the MPIN check in wallet_topup.php's UI is a
 * convenience gate, this is the actual enforcement, so a request
 * crafted to skip the UI still can't move money without the MPIN.
 *
 * POST /wallet_topup_process.php  (amount, mpin)
 * -> { success: true, new_balance: 1234.56 }
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

$user_id = (int)$_SESSION['user_id'];
$amount  = $_POST['amount'] ?? null;
$mpin    = $_POST['mpin'] ?? '';

if (get_masked_account_name($conn, $user_id) === null) {
    echo json_encode(['success' => false, 'error' => 'We could not find your account. Please log in again.']);
    exit();
}

if (!user_has_mpin($conn, $user_id)) {
    // Shouldn't be reachable through the normal UI (it forces MPIN
    // setup first), but fail closed if it's ever hit directly.
    echo json_encode(['success' => false, 'error' => 'Please set up your MPIN before adding money.']);
    exit();
}

if (!verify_user_mpin($conn, $user_id, $mpin)) {
    echo json_encode(['success' => false, 'error' => 'Incorrect MPIN. Please try again.']);
    exit();
}

// verify_only lets wallet_topup.php's "confirm it's you" MPIN gate check
// the MPIN by itself, before the guest has entered an amount, WITHOUT
// touching the wallet - explicit flag rather than overloading amount=0,
// so this endpoint's two jobs (verify identity / move money) stay
// clearly separate for whoever reads this next.
if (!empty($_POST['verify_only'])) {
    echo json_encode(['success' => true, 'verified' => true]);
    exit();
}

if (!is_numeric($amount) || (float)$amount <= 0) {
    echo json_encode(['success' => false, 'error' => 'Enter a valid amount greater than zero.']);
    exit();
}

$new_balance = post_wallet_transaction(
    $conn,
    $user_id,
    (float)$amount,
    'Top-up',
    null,
    null,
    'Wallet top-up via G-Cosh (simulated) - ₱' . number_format((float)$amount, 2)
);

echo json_encode(['success' => true, 'new_balance' => $new_balance]);

$conn->close();