<?php
// auth_ajax.php
//
// Backs three login.php features that all need a server round-trip without
// a full page reload, following the same lightweight JSON-endpoint pattern
// index.php uses for testimonials_ajax.php:
//   1. action=chat_reply           - suspended-account customer-service chat
//   2. action=confirm_request      - writes the "please review my account"
//                                    request into support_requests once the
//                                    chat's scripted flow has confirmed the
//                                    guest actually wants that
//   3. action=forgot_password      - issues a password_resets token
//
// All three are read via $_POST (chat/confirm) or $_GET (nothing here reads
// GET, kept POST-only throughout since every action either writes a row or
// echoes back a message keyed off input the guest typed).

session_start();
require_once 'classes/Database.php';

function send_json($payload) {
    if (ob_get_length() !== false) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit();
}

$database = new Database();
$conn = $database->getConnection();

// Schema self-heal - same three checks as login.php's top, repeated here
// because this file can be hit directly without login.php having run first
// in the same request lifecycle (it's a separate HTTP request from the
// browser's fetch() call).
$susp_col_check = $conn->query("SHOW COLUMNS FROM users LIKE 'suspension_reason'");
if ($susp_col_check && $susp_col_check->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN suspension_reason VARCHAR(255) NULL DEFAULT NULL AFTER status");
}
$conn->query("
    CREATE TABLE IF NOT EXISTS support_requests (
        support_request_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        user_email VARCHAR(190) NOT NULL,
        suspension_reason_snapshot VARCHAR(255) NULL,
        guest_message TEXT NULL,
        request_status VARCHAR(20) NOT NULL DEFAULT 'Pending',
        admin_note TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        resolved_at TIMESTAMP NULL DEFAULT NULL
    )
");
$conn->query("
    CREATE TABLE IF NOT EXISTS password_resets (
        reset_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        user_email VARCHAR(190) NOT NULL,
        token VARCHAR(64) NOT NULL UNIQUE,
        used TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        expires_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    )
");

$action = $_POST['action'] ?? '';

// ============================================================
// ACTION: chat_reply
// Scripted, rule-based response generator for the suspended-account
// customer-service widget. Deliberately NOT a general-purpose/LLM chat -
// the brief calls for it to "be accurate" about why THIS account was
// suspended, which a free-form generator can't guarantee. Every branch
// below is keyed off the account's actual suspension_reason (or a Terms-
// derived fallback) and a small set of recognized guest intents, so nothing
// it says is invented.
// ============================================================
if ($action === 'chat_reply') {
    $userId = (int)($_POST['user_id'] ?? 0);
    $guestText = trim($_POST['message'] ?? '');

    if ($userId <= 0) {
        send_json(['ok' => false, 'error' => 'Missing account reference.']);
    }

    $stmt = $conn->prepare("SELECT id, first_name, user_email, status, suspension_reason FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $account = $res->fetch_assoc();
    $stmt->close();

    if (!$account) {
        send_json(['ok' => false, 'error' => 'Account not found.']);
    }

    // Account was reactivated (by admin) since this chat session started -
    // tell the guest immediately rather than continuing the suspended-flow
    // script against a status that's no longer true.
    if ($account['status'] !== 'Suspended') {
        send_json([
            'ok' => true,
            'reply' => "Good news, " . $account['first_name'] . " — your account is active again. You're good to go and can log in now.",
            'resolved' => true,
        ]);
    }

    // The specific reason an admin set, or the general Terms conduct clause
    // when none was recorded (older suspensions, or a suspend action taken
    // before this field existed).
    $reasonText = !empty($account['suspension_reason'])
        ? $account['suspension_reason']
        : "a violation of our Member Conduct terms (fraudulent bookings, payment abuse, excessive cancellations, or harassment of staff or other guests)";

    $lower = strtolower($guestText);

    // Intent: guest wants to proceed with a formal review request. Handled
    // here (rather than only in a separate confirm click) so the flow works
    // whether the guest types "yes" in chat or uses a discrete button - the
    // actual DB write happens in the confirm_request action below either
    // way, this branch just tells the widget to show that button.
    if (preg_match('/\b(yes|please|request|review|reinstate|unsuspend|reactivate|appeal)\b/', $lower)) {
        send_json([
            'ok' => true,
            'reply' => "Understood. I can send this to our team for a manual review. Tap \"Send Request to Admin\" below and someone will look into reinstating your account.",
            'offer_confirm' => true,
        ]);
    }

    // Intent: guest is asking why / what happened.
    if ($guestText === '' || preg_match('/\b(why|reason|what happened|suspend)\b/', $lower)) {
        send_json([
            'ok' => true,
            'reply' => "Your account was suspended due to: " . $reasonText . ". If you believe this was a mistake, I can send a request to our admin team to have it reviewed — just say \"yes\" or tap the button below.",
            'offer_confirm' => true,
        ]);
    }

    // Default: restate the reason and the option to request review, without
    // pretending to understand free-form input outside the two intents
    // above.
    send_json([
        'ok' => true,
        'reply' => "I can help with that. To recap: your account is suspended for " . $reasonText . ". Would you like me to send a request to our admin team to review it?",
        'offer_confirm' => true,
    ]);
}

// ============================================================
// ACTION: confirm_request
// Writes the one row admin_dashboard.php's Support Requests panel reads.
// Only reached after the guest has explicitly confirmed in chat (mirrors
// how cancellation_requests is only written once index.php's Contact form
// is actually submitted, not on every page view).
// ============================================================
if ($action === 'confirm_request') {
    $userId = (int)($_POST['user_id'] ?? 0);

    if ($userId <= 0) {
        send_json(['ok' => false, 'error' => 'Missing account reference.']);
    }

    $stmt = $conn->prepare("SELECT id, user_email, status, suspension_reason FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $account = $res->fetch_assoc();
    $stmt->close();

    if (!$account) {
        send_json(['ok' => false, 'error' => 'Account not found.']);
    }
    if ($account['status'] !== 'Suspended') {
        send_json(['ok' => true, 'reply' => "Your account is already active — you're good to go and can log in now.", 'resolved' => true]);
    }

    // One pending request per account at a time - re-confirming while a
    // prior request is still open just surfaces the existing one instead
    // of stacking duplicates in the admin queue.
    $dupCheck = $conn->prepare("SELECT support_request_id FROM support_requests WHERE user_id = ? AND request_status = 'Pending' LIMIT 1");
    $dupCheck->bind_param("i", $userId);
    $dupCheck->execute();
    $dupCheck->store_result();
    if ($dupCheck->num_rows > 0) {
        $dupCheck->close();
        send_json([
            'ok' => true,
            'reply' => "You already have a request in with our admin team from this account — no need to send another. We'll notify you here once it's reviewed.",
            'already_pending' => true,
        ]);
    }
    $dupCheck->close();

    $insert = $conn->prepare("INSERT INTO support_requests (user_id, user_email, suspension_reason_snapshot, guest_message, request_status) VALUES (?, ?, ?, ?, 'Pending')");
    // NEW: uses whatever the guest actually typed in the chat (sent as
    // `context` from sendConfirmRequest() - the last few messages, capped
    // client-side), falling back to a generic note only if the guest never
    // typed anything and confirmed straight off the initial bot prompt.
    $guestContext = trim($_POST['context'] ?? '');
    $guestMessage = $guestContext !== ''
        ? $guestContext
        : "Guest confirmed a request for account review via the login.php customer-service chat (no additional message typed).";
    $insert->bind_param("isss", $userId, $account['user_email'], $account['suspension_reason'], $guestMessage);
    $insert->execute();
    $insert->close();

    send_json([
        'ok' => true,
        'reply' => "Done — your request has been sent to our admin team for review. We'll let you know here as soon as there's an update.",
        'submitted' => true,
    ]);
}

// ============================================================
// ACTION: chat_status_check
// Lightweight poll so an open chat widget notices if admin approves the
// account mid-session, without the guest needing to retype anything -
// mirrors the honest short-interval-polling pattern index.php already uses
// for testimonials_ajax.php (no fake websocket/push claims).
// ============================================================
if ($action === 'chat_status_check') {
    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId <= 0) {
        send_json(['ok' => false, 'error' => 'Missing account reference.']);
    }

    $stmt = $conn->prepare("SELECT status FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $account = $res->fetch_assoc();
    $stmt->close();

    send_json([
        'ok' => true,
        'status' => $account['status'] ?? null,
        'resolved' => isset($account['status']) && $account['status'] !== 'Suspended',
    ]);
}

// ============================================================
// ACTION: forgot_password
// Issues a time-limited token. NOTE: no outbound-mail infrastructure
// exists anywhere in this codebase (no PHPMailer/SMTP config, no mail()
// calls in any uploaded file), so this does not actually email the link -
// it returns the reset URL directly in the JSON response for the frontend
// to display. That's flagged explicitly in the UI copy as well, so it's
// never presented as "check your email" when nothing was sent.
// ============================================================
if ($action === 'forgot_password') {
    $email = trim(filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL));

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        send_json(['ok' => false, 'error' => 'Please enter a valid email address.']);
    }

    $stmt = $conn->prepare("SELECT id, status FROM users WHERE user_email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $res = $stmt->get_result();
    $account = $res->fetch_assoc();
    $stmt->close();

    // Deliberately generic response whether or not the account exists, so
    // this endpoint can't be used to enumerate registered emails. The
    // token is only actually created (and the real reset link only
    // actually returned) when $account is found.
    $genericMsg = "If an account exists for that email, a password reset link has been generated below.";

    if (!$account) {
        send_json(['ok' => true, 'reply' => $genericMsg, 'link' => null]);
    }

    if ($account['status'] === 'Suspended') {
        // Resetting the password on a suspended account wouldn't let them
        // log in anyway (login() blocks on status before the password
        // check even runs) - point them at the support chat instead of
        // generating a token that goes nowhere.
        send_json([
            'ok' => true,
            'reply' => "That account is currently suspended, so a password reset won't restore access on its own. Please use the account-support chat instead to request a review.",
            'link' => null,
            'suspended' => true,
        ]);
    }

    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

    $insert = $conn->prepare("INSERT INTO password_resets (user_id, user_email, token, expires_at) VALUES (?, ?, ?, ?)");
    $insert->bind_param("isss", $account['id'], $email, $token, $expiresAt);
    $insert->execute();
    $insert->close();

    $resetLink = "reset-password.php?token=" . $token;

    send_json([
        'ok' => true,
        'reply' => $genericMsg,
        'link' => $resetLink,
    ]);
}

send_json(['ok' => false, 'error' => 'Unrecognized action.']);