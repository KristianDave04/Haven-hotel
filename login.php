<?php
// login.php
session_start();

require_once 'classes/Database.php';
require_once 'classes/User.php';

$error = "";
$suspendedContext = null; // NEW: populated when login() reports Suspended, drives the customer-service chat below

// Surface a one-time message after email verification (see verify.php)
if (isset($_GET['verified']) && $_GET['verified'] === '1') {
    $verifiedNotice = "Your email has been verified. You can now sign in.";
}

// NEW: SCHEMA SELF-HEALING — same self-healing pattern admin_dashboard.php
// already uses for cancellation_requests, applied here so login.php works
// standalone on a fresh deployment that hasn't loaded admin_dashboard.php
// NEW: SCHEMA SELF-HEALING — same self-healing pattern admin_dashboard.php
// already uses for cancellation_requests, applied here so a POST login
// attempt works standalone on a fresh deployment that hasn't loaded
// admin_dashboard.php yet (User::login() SELECTs suspension_reason, which
// needs to exist first). The forgot-password and support-chat modals talk
// to auth_ajax.php directly via fetch(), which carries its own identical
// self-heal block - so those paths are covered independently of this one,
// this block only needs to guard the login-form POST path itself.
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $schemaDb = new Database();
    $schemaConn = $schemaDb->getConnection();

    $susp_col_check = $schemaConn->query("SHOW COLUMNS FROM users LIKE 'suspension_reason'");
    if ($susp_col_check && $susp_col_check->num_rows === 0) {
        $schemaConn->query("ALTER TABLE users ADD COLUMN suspension_reason VARCHAR(255) NULL DEFAULT NULL AFTER status");
    }

    $schemaConn->query("
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

    // NEW: PASSWORD RESETS — token-based, since no outbound-mail
    // infrastructure exists anywhere in this codebase (no PHPMailer, no
    // mail() calls). See the forgot-password flow further down: the reset
    // link is surfaced directly in the UI rather than emailed.
    $schemaConn->query("
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

    $schemaDb->closeConnection();
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_POST['support_action']) && !isset($_POST['forgot_action'])) {
    $email = trim(filter_var($_POST['email'], FILTER_SANITIZE_EMAIL));
    $password = $_POST['password'];

    if (empty($email) || empty($password)) {
        $error = "Please enter both email and password.";
    } else {
        try {
            // Instantiate Database and establish PDO connection
            $database = new Database();
            $dbConn = $database->getConnection();

            // Set PDO to throw exceptions on error (essential for try-catch)
            if ($dbConn instanceof PDO) {
                $dbConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            }

            $userEngine = new User($dbConn);

            // Authenticate with user object layer method loop context
            $loginResult = $userEngine->login($email, $password);

            if ($loginResult !== false && $loginResult['status'] === true) {
                // Evaluates role parameter output values to guide view dashboard endpoints
                if ($loginResult['role'] === 'Admin' || $loginResult['role'] === 'admin') {
                    header("Location: admin/admin_dashboard.php");
                } else {
                    header("Location: dashboard.php");
                }
                exit();
            } elseif (is_array($loginResult) && isset($loginResult['error'])) {
                // Suspended-account / unverified-account message surfaced from User::login()
                $error = $loginResult['error'];
                // NEW: when the specific cause is a suspension (rather than
                // some other future ['error'] case), stash the account
                // details so the page can offer the customer-service chat
                // instead of just a dead-end error line.
                if (stripos($loginResult['error'], 'Suspended') !== false && isset($loginResult['user_id'])) {
                    $suspendedContext = [
                        'user_id' => $loginResult['user_id'],
                        'email' => $email,
                        'reason' => $loginResult['reason'] ?? null,
                    ];
                }
            } else {
                $error = "Access Denied: Invalid email or password.";
            }

        } catch (PDOException $e) {
            // Log the actual error silently and display a user-friendly message
            error_log("Database Error: " . $e->getMessage());
            $error = "A database connection error occurred. Please try again later.";
        } catch (Exception $e) {
            error_log("General Error: " . $e->getMessage());
            $error = "An unexpected error occurred. Please try again later.";
        } finally {
            // Explicitly close the connection if your Database class supports it
            if (isset($database) && method_exists($database, 'closeConnection')) {
                $database->closeConnection();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Haven Hotel - Sign In</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:ital,wght@0,500;0,600;1,500&family=Fraunces:opsz,wght@9..144,400;9..144,500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="ui/login.css">
    <style>
/* ============================================================
   Haven Hotel — Sign In
   Palette:  #0f1210 ink · #fffdf8 paper · #c8a25c gold
             #7a8b7a sage · #e8ded0 hairline · #b5482f rust (error)
   Type:     Playfair Display (headline) · Fraunces (numeral/signature)
             Inter (body / form)
   Signature: live front-desk time strip + underline-only inputs
   ============================================================ */

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

:root {
    --ink: #0f1210;
    --paper: #fffdf8;
    --gold: #c8a25c;
    --gold-deep: #a9813f;
    --sage: #7a8b7a;
    --hairline: #e8ded0;
    --rust: #b5482f;
    --rust-bg: #fbeee9;
    --moss-bg: #eef1ea;
}

body {
    font-family: 'Inter', sans-serif;
    background-color: var(--paper);
    color: var(--ink);
    min-height: 100vh;
    overflow-x: hidden;
}

/* Split layout container wrapper */
.split-container {
    display: flex;
    min-height: 100vh;
    width: 100%;
}

/* ---- Page slide transition ---- */
body.page-enter .split-container {
    animation: slideIn 0.42s ease forwards;
}

body.page-exit .split-container {
    animation: slideOut 0.38s ease forwards;
}

@keyframes slideIn {
    from { transform: translateX(4%); opacity: 0; }
    to   { transform: translateX(0); opacity: 1; }
}

@keyframes slideOut {
    from { transform: translateX(0); opacity: 1; }
    to   { transform: translateX(-4%); opacity: 0; }
}

/* LEFT SIDE: HERO WITH SLIDESHOW */
.hero-side {
    flex: 1.15;
    position: relative;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    padding: 48px 56px;
    overflow: hidden;
    background-color: var(--ink);
}

.slideshow {
    position: absolute;
    inset: 0;
    z-index: 0;
}

.slide {
    position: absolute;
    inset: 0;
    background-size: cover;
    background-position: center;
    background-repeat: no-repeat;
    opacity: 0;
    transition: opacity 1.1s ease;
    filter: saturate(0.92) contrast(1.03);
}

.slide.active {
    opacity: 1;
}

.hero-overlay {
    position: absolute;
    inset: 0;
    background: linear-gradient(to top, rgba(15, 18, 16, 0.82) 0%, rgba(15, 18, 16, 0.28) 50%, rgba(15, 18, 16, 0.05) 100%);
    z-index: 1;
}

/* Signature element: live front-desk status strip, replaces the generic
   'Member Access' pill badge. Grounds the page in the present moment. */
.desk-status {
    position: relative;
    z-index: 10;
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 20px;
    color: rgba(255, 253, 248, 0.92);
}

.desk-time {
    font-family: 'Fraunces', serif;
    font-size: 46px;
    font-weight: 400;
    font-variant-numeric: tabular-nums;
    letter-spacing: -0.01em;
    line-height: 1;
}

.desk-meta {
    text-align: right;
}

.desk-status-line {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 7px;
    font-size: 11.5px;
    font-weight: 600;
    letter-spacing: 0.6px;
    text-transform: uppercase;
    color: #cfe0cf;
    margin-bottom: 4px;
}

.desk-status-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #93c893;
    box-shadow: 0 0 0 3px rgba(147, 200, 147, 0.22);
}

.desk-date {
    font-size: 12px;
    color: rgba(255, 253, 248, 0.55);
}

/* Quote wrapper */
.quote-wrapper {
    position: relative;
    z-index: 10;
    max-width: 88%;
}

.quote-text {
    font-family: 'Playfair Display', serif;
    color: var(--paper);
    font-size: 30px;
    font-weight: 500;
    line-height: 1.32;
    margin-bottom: 10px;
    letter-spacing: -0.01em;
}

.quote-text em {
    font-style: italic;
    font-weight: 400;
    color: var(--gold);
}

.quote-author {
    color: rgba(255, 253, 248, 0.55);
    font-size: 13px;
    font-weight: 400;
    letter-spacing: 0.4px;
    margin-bottom: 26px;
}

/* Slideshow dot navigation */
.slide-dots {
    position: relative;
    z-index: 10;
    display: flex;
    gap: 9px;
}

.dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    border: 1px solid rgba(255, 253, 248, 0.5);
    background: transparent;
    cursor: pointer;
    padding: 0;
    transition: background-color 0.2s ease, transform 0.2s ease, border-color 0.2s ease;
}

.dot:hover {
    border-color: var(--gold);
}

.dot.active {
    background: var(--gold);
    border-color: var(--gold);
    transform: scale(1.2);
}


/* RIGHT SIDE: FORM */
.form-side {
    flex: 1;
    display: flex;
    justify-content: center;
    align-items: center;
    background-color: var(--paper);
    padding: 40px;
}

.form-container {
    width: 100%;
    max-width: 380px;
}

.brand-marks {
    font-family: 'Playfair Display', serif;
    font-size: 14px;
    font-weight: 700;
    letter-spacing: 0.6px;
    color: var(--ink);
    margin-bottom: 30px;
}

.brand-marks span {
    color: var(--gold);
}

.form-header {
    margin-bottom: 38px;
}

.form-header h2 {
    font-family: 'Playfair Display', serif;
    font-size: 34px;
    font-weight: 600;
    color: var(--ink);
    margin-bottom: 9px;
    letter-spacing: -0.01em;
}

.form-header p {
    font-size: 13.5px;
    color: #7d786d;
    line-height: 1.55;
    max-width: 34ch;
}

/* Input Controls — underline-only signature style.
   Quieter than boxed icon-inputs; label floats up on focus/fill,
   like a line in a guestbook rather than a SaaS form field. */
.input-group {
    position: relative;
    margin-bottom: 30px;
}

.input-group label {
    position: absolute;
    left: 0;
    top: 12px;
    font-size: 14.5px;
    color: #96917f;
    letter-spacing: 0.1px;
    pointer-events: none;
    transition: top 0.16s ease, font-size 0.16s ease, color 0.16s ease;
}

.input-group input {
    width: 100%;
    padding: 11px 34px 11px 0;
    border: none;
    border-bottom: 1.5px solid var(--hairline);
    background: transparent;
    font-size: 15px;
    font-family: inherit;
    color: var(--ink);
    outline: none;
    transition: border-color 0.15s ease;
}

.input-group input:focus {
    border-bottom-color: var(--gold);
}

.input-group input:focus ~ label,
.input-group input:not(:placeholder-shown) ~ label {
    top: -14px;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.6px;
    text-transform: uppercase;
    color: var(--gold-deep);
}

/* placeholder must exist (even empty) for :not(:placeholder-shown) to work,
   but we don't want visible placeholder text under the floating label */
.input-group input::placeholder {
    color: transparent;
}

.toggle-visibility {
    position: absolute;
    right: 0;
    top: 8px;
    background: none;
    border: none;
    color: #b3ac99;
    cursor: pointer;
    font-size: 14px;
    padding: 4px;
    display: flex;
}

.toggle-visibility:hover {
    color: var(--gold-deep);
}

/* System Action Alert Notifications */
.alert-error {
    color: var(--rust);
    background: var(--rust-bg);
    border: 1px solid #f3d9cf;
    padding: 12px 14px;
    border-radius: 3px;
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 26px;
    font-weight: 500;
}

.alert-success {
    color: #47624a;
    background: var(--moss-bg);
    border: 1px solid #d7e0cf;
    padding: 12px 14px;
    border-radius: 3px;
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 26px;
    font-weight: 500;
}

/* Brand Action Button */
.btn-gold {
    width: 100%;
    padding: 15px;
    background: var(--ink);
    border: none;
    color: var(--paper);
    border-radius: 3px;
    font-size: 13.5px;
    font-weight: 600;
    letter-spacing: 0.3px;
    font-family: inherit;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: background-color 0.18s ease, transform 0.1s ease;
    margin-top: 6px;
}

.btn-gold:hover {
    background-color: #262a24;
}

.btn-gold:active {
    transform: scale(0.99);
}

.btn-gold:disabled {
    background: #b9b3a4;
    cursor: not-allowed;
}

/* Terms & Conditions inline trigger */
.terms-line {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    flex-wrap: wrap;
    margin-top: 22px;
    font-size: 12px;
    color: #96917f;
    text-align: center;
}

.link-btn {
    background: none;
    border: none;
    padding: 0;
    font: inherit;
    font-weight: 600;
    color: var(--gold-deep);
    cursor: pointer;
}

.link-btn:hover {
    text-decoration: underline;
}

/* Link Navigation Helpers */
.footer-note {
    text-align: center;
    margin-top: 22px;
    font-size: 13px;
    color: #7d786d;
}

.footer-note a {
    color: var(--gold-deep);
    text-decoration: none;
    font-weight: 600;
}

.footer-note a:hover {
    text-decoration: underline;
}

/* ---- Terms & Conditions Modal ---- */
.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 18, 16, 0.6);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 100;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.2s ease;
}

.modal-overlay.open {
    opacity: 1;
    pointer-events: auto;
}

.modal-box {
    background: var(--paper);
    width: min(600px, 92vw);
    max-height: 80vh;
    border-radius: 6px;
    display: flex;
    flex-direction: column;
    box-shadow: 0 30px 70px rgba(15, 18, 16, 0.28);
    transform: translateY(10px);
    transition: transform 0.2s ease;
}

.modal-overlay.open .modal-box {
    transform: translateY(0);
}

.modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 22px 26px;
    border-bottom: 1px solid var(--hairline);
}

.modal-header h3 {
    font-family: 'Playfair Display', serif;
    font-size: 18px;
    color: var(--ink);
}

.modal-close {
    background: none;
    border: none;
    color: #96917f;
    font-size: 18px;
    cursor: pointer;
    padding: 4px 8px;
}

.modal-close:hover {
    color: var(--gold-deep);
}

.modal-body {
    padding: 22px 26px 30px;
    overflow-y: auto;
    font-size: 13px;
    line-height: 1.65;
    color: #3a382f;
}

.modal-body h4 {
    font-family: 'Fraunces', serif;
    font-size: 14px;
    font-weight: 500;
    color: var(--ink);
    margin: 20px 0 6px;
}

.modal-body h4:first-child {
    margin-top: 0;
}

.modal-body p {
    margin-bottom: 4px;
}

.modal-updated {
    margin-top: 18px;
    font-size: 11.5px;
    color: #96917f;
    font-style: italic;
}

/* ============================================================
   NEW: Suspended-account customer-service prompt (shown inline
   above the form when login() reports Suspended)
   ============================================================ */
.suspended-cs-prompt {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    background: var(--rust-bg);
    border: 1px solid #f3d9cf;
    border-radius: 6px;
    padding: 16px;
    margin-bottom: 26px;
}

.suspended-cs-prompt i {
    color: var(--rust);
    font-size: 18px;
    margin-top: 2px;
}

.suspended-cs-prompt strong {
    display: block;
    font-size: 13.5px;
    color: var(--ink);
    margin-bottom: 3px;
}

.suspended-cs-prompt p {
    font-size: 12.5px;
    color: #5c584d;
    line-height: 1.5;
    margin-bottom: 10px;
}

.btn-outline-gold {
    background: transparent;
    border: 1.5px solid var(--gold-deep);
    color: var(--gold-deep);
    padding: 9px 16px;
    border-radius: 3px;
    font-size: 12.5px;
    font-weight: 600;
    font-family: inherit;
    cursor: pointer;
    transition: background-color 0.15s ease, color 0.15s ease;
}

.btn-outline-gold:hover {
    background: var(--gold-deep);
    color: var(--paper);
}

/* ---- Forgot password link row ---- */
.forgot-pw-row {
    display: flex;
    justify-content: flex-end;
    margin-top: -18px;
    margin-bottom: 22px;
}

.forgot-pw-row .link-btn {
    font-size: 12px;
}

/* ============================================================
   NEW: Support chat modal
   ============================================================ */
.chat-modal-box {
    width: min(420px, 92vw);
    height: min(560px, 82vh);
}

.chat-modal-box .modal-header h3 {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 16px;
}

.chat-modal-box .modal-header h3 i {
    color: var(--gold-deep);
    font-size: 14px;
}

.chat-transcript {
    flex: 1;
    overflow-y: auto;
    padding: 20px 20px 8px;
    display: flex;
    flex-direction: column;
    gap: 12px;
    background: #fbfaf6;
}

.chat-bubble {
    max-width: 82%;
    padding: 10px 14px;
    border-radius: 14px;
    font-size: 13px;
    line-height: 1.5;
    word-wrap: break-word;
}

.chat-bubble--agent {
    align-self: flex-start;
    background: #f0ece1;
    color: var(--ink);
    border-bottom-left-radius: 3px;
}

.chat-bubble--guest {
    align-self: flex-end;
    background: var(--ink);
    color: var(--paper);
    border-bottom-right-radius: 3px;
}

.chat-bubble--typing {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 13px 16px;
}

.chat-bubble--typing span {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #a39c8a;
    animation: typingDot 1.1s infinite ease-in-out;
}

.chat-bubble--typing span:nth-child(2) { animation-delay: 0.15s; }
.chat-bubble--typing span:nth-child(3) { animation-delay: 0.3s; }

@keyframes typingDot {
    0%, 60%, 100% { transform: translateY(0); opacity: 0.5; }
    30% { transform: translateY(-4px); opacity: 1; }
}

.chat-confirm-wrap {
    align-self: flex-start;
    max-width: 82%;
}

.chat-input-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 14px 18px;
    border-top: 1px solid var(--hairline);
    background: var(--paper);
}

.chat-input-row input {
    flex: 1;
    border: 1px solid var(--hairline);
    border-radius: 20px;
    padding: 10px 16px;
    font-size: 13px;
    font-family: inherit;
    outline: none;
    transition: border-color 0.15s ease;
}

.chat-input-row input:focus {
    border-color: var(--gold);
}

.chat-input-row button {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    border: none;
    background: var(--ink);
    color: var(--paper);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    flex-shrink: 0;
    transition: background-color 0.15s ease;
}

.chat-input-row button:hover {
    background: #262a24;
}

/* ============================================================
   NEW: Forgot password reset-link demo-mode notice
   ============================================================ */
.reset-link-box {
    background: #fff8e8;
    border: 1px solid #eeddb0;
    border-radius: 4px;
    padding: 12px 14px;
    font-size: 12px;
    line-height: 1.55;
    color: #6b5a2e;
    display: flex;
    align-items: flex-start;
    gap: 8px;
}

.reset-link-box i {
    color: #b8912e;
    margin-top: 2px;
    flex-shrink: 0;
}

.reset-link-box a {
    color: var(--gold-deep);
    font-weight: 600;
    word-break: break-all;
}

/* Responsive Breakpoints */
@media (max-width: 900px) {
    .hero-side {
        display: none;
    }
    .form-side {
        padding: 30px 20px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .slide,
    body.page-enter .split-container,
    body.page-exit .split-container {
        animation: none !important;
        transition: none !important;
    }
    .slide.active {
        opacity: 1;
    }
    .chat-bubble--typing span {
        animation: none;
        opacity: 0.7;
    }
}
    </style>
</head>
<body class="page-enter">

    <div class="split-container">

        <div class="hero-side">
            <!-- Slideshow layer: each .slide is a full-cover background image, cross-faded via JS -->
            <div class="slideshow" id="heroSlideshow">
                <div class="slide active" style="background-image: url('https://lirp.cdn-website.com/5f5d0298/dms3rep/multi/opt/Swimming+Pool+%286%29-640w.jpg')"></div>
                <div class="slide" style="background-image: url('https://images.unsplash.com/photo-1611892440504-42a792e24d32?q=80&w=1600&auto=format&fit=crop')"></div>
                <div class="slide" style="background-image: url('https://images.unsplash.com/photo-1582719508461-905c673771fd?q=80&w=1600&auto=format&fit=crop')"></div>
                <div class="slide" style="background-image: url('https://images.unsplash.com/photo-1590490360182-c33d57733427?q=80&w=1600&auto=format&fit=crop')"></div>
            </div>
            <div class="hero-overlay"></div>

            <div class="desk-status">
                <div class="desk-time" id="deskTime">--:--</div>
                <div class="desk-meta">
                    <div class="desk-status-line"><span class="desk-status-dot"></span> Front Desk Open</div>
                    <div class="desk-date" id="deskDate">&nbsp;</div>
                </div>
            </div>

            <div class="quote-wrapper">
                <p class="quote-text">
                    "A stay that feels like <em>coming home</em>."
                </p>
                <p class="quote-author">— Haven Hotel Guest, March 2026</p>
            </div>

            <div class="slide-dots" id="slideDots">
                <button class="dot active" data-slide="0" aria-label="Show slide 1"></button>
                <button class="dot" data-slide="1" aria-label="Show slide 2"></button>
                <button class="dot" data-slide="2" aria-label="Show slide 3"></button>
                <button class="dot" data-slide="3" aria-label="Show slide 4"></button>
            </div>
        </div>

        <div class="form-side">
            <div class="form-container">

                <div class="form-header">
                    <div class="brand-marks">Haven<span>Hotel</span></div>
                    <h2>Welcome Back</h2>
                    <p>Sign in to manage your reservations and access exclusive member benefits.</p>
                </div>

                <?php if (!empty($verifiedNotice)): ?>
                    <div class="alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($verifiedNotice); ?></div>
                <?php endif; ?>

                <?php if (!empty($error)): ?>
                    <div class="alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <?php if ($suspendedContext): ?>
                    <!-- NEW: Suspended-account customer-service prompt. Shown instead of
                         leaving the guest at a dead-end error line - offers the chat
                         widget so they can find out why and request a review. -->
                    <div class="suspended-cs-prompt">
                        <i class="fa-solid fa-headset"></i>
                        <div>
                            <strong>Need help with your account?</strong>
                            <p>Chat with support to find out what happened and request a review.</p>
                        </div>
                        <button type="button" class="btn-outline-gold" id="openSupportChat">Chat with Support</button>
                    </div>
                <?php endif; ?>

                <form action="login.php" method="POST" id="loginForm">

                    <div class="input-group">
                        <input type="email" id="email" name="email" required placeholder=" " value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>">
                        <label for="email">Email Address</label>
                    </div>

                    <div class="input-group">
                        <input type="password" id="password" name="password" required placeholder=" ">
                        <label for="password">Password</label>
                        <button type="button" class="toggle-visibility" onclick="togglePasswordField()" tabindex="-1" aria-label="Show password">
                            <i class="fa-regular fa-eye" id="toggleIcon"></i>
                        </button>
                    </div>

                    <div class="forgot-pw-row">
                        <button type="button" class="link-btn" id="openForgotPassword">Forgot password?</button>
                    </div>

                    <button type="submit" class="btn-gold" id="submitBtn">
                        <span id="btnLabel">Login to HavenHotel</span>
                        <i class="fa-solid fa-spinner fa-spin" id="btnSpinner" style="display:none;"></i>
                    </button>
                </form>

                <label class="terms-line">
                    <span>By continuing you agree to our</span>
                    <button type="button" class="link-btn" id="openTerms">Terms &amp; Conditions</button>
                </label>

                <div class="footer-note">
                    Don't have an account? <a href="sign-up.php" class="page-link" data-target="sign-up.php">Create Account</a>
                </div>

            </div>
        </div>

    </div>

    <!-- Terms & Conditions Modal -->
    <div class="modal-overlay" id="termsModal" aria-hidden="true">
        <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="termsTitle">
            <div class="modal-header">
                <h3 id="termsTitle">Haven Hotel — Terms &amp; Conditions</h3>
                <button type="button" class="modal-close" id="closeTerms" aria-label="Close">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="modal-body">
                <h4>1. Account Registration</h4>
                <p>You must provide accurate and complete information when creating a Haven Hotel account. You are responsible for keeping your password safe and for all activities under your account. Notify us immediately at support@havenhotel.example if you suspect unauthorized access.</p>

                <h4>2. Booking &amp; Payment (48-Hour Grace Period)</h4>
                <ul class="modal-list">
                    <li><strong style="text-indent: 40px;">50% Downpayment:</strong> To secure your room, a 50% downpayment is required immediately upon booking. Your slot will be locked under <i>[status label not available in source document]</em> status.</li>
                    <li><strong style="text-indent: 40px;">48-Hour Deadline:</strong> You have exactly 48 hours from the time of booking to settle the remaining 50% balance to change your status to <i>[status label not available in source document]</em>.</li>
                    <li><strong style="text-indent: 40px;">Automatic Cancellation:</strong> If the balance is not paid within 48 hours, the system will automatically cancel your reservation.</li>
                    <li><strong>Missed Deadline Refund:</strong> If the system auto-cancels your room due to non-payment, 70% of your downpayment will be refunded back to your System Wallet Balance.</li>
                </ul>

                <h4>3. Cancellation &amp; Refund Policy (Sliding Scale)</h4>
                <p>All processed refunds are automatically credited back to your System Wallet Balance for future use. The refund amount depends on when you cancel:</p>
                <ul class="modal-list">
                    <li><strong style="text-indent: 40px;">Before Check-In Date:</strong> If you cancel before your stay begins, you will receive a 70% refund of the total amount you have paid.</li>
                    <li><strong style="text-indent: 40px;">Mid-Stay Cancellation (Partial Stay):</strong> If you decide to cut your stay short and leave early after checking in:
                        <ol class="modal-sublist">
                            <li style="text-indent: 60px;">The nights you have already stayed are non-refundable and will be fully charged.</li>
                            <li style="text-indent: 60px;">For the remaining unused nights, you will receive a refund ranging from 70% down to 50%. The sooner you cancel after checking in, the closer you get to a 70% refund for the unused nights. The longer you wait to cancel during your stay, the refund drops closer to the 50% minimum floor.</li>
                        </ol>
                    </li>
                </ul>

                <h4>4. Check-In &amp; Check-Out</h4>
                <p>Standard check-in begins at 3:00 PM and check-out is by 11:00 AM local hotel time. Early check-in or late check-out may be requested via your dashboard and are subject to room availability and additional fees.</p>

                <h4>5. Member Conduct</h4>
                <p>Accounts used for fraudulent bookings, payment abuse, excessive cancellations, or harassment of staff or other guests will be suspended or terminated immediately without prior notice.</p>

                <h4>6. Privacy Policy</h4>
                <p>We use your data strictly to manage bookings, send updates, and improve our services. We never sell your personal information to third parties. Please see our full Privacy Policy for data retention details.</p>

                <h4>7. Safety, Accidents, and Limitation of Liability</h4>
                <ul class="modal-list">
                    <li><strong style="text-indent: 40px;">Use Facilities at Your Own Risk:</strong> By using our rooms, swimming pools, gym, and common areas, guests acknowledge the inherent risks of recreational activities and agree to use them at their own risk.</li>
                    <li><strong style="text-indent: 40px;">Compliance with Safety Rules:</strong> Guests must follow all posted warning signs (e.g., "Wet Floor" signs, pool safety rules). Parents or guardians are solely responsible for supervising minors at all times.</li>
                    <li><strong style="text-indent: 40px;">Limitation of Injury Liability:</strong> Haven Hotel and its staff are not financially responsible for any personal injury, medical expenses, or health emergencies occurring on the premises, unless directly caused by gross negligence or willful fault of the hotel management.</li>
                    <li><strong style="text-indent: 40px;">Personal Responsibility:</strong> The hotel assumes no liability for accidents caused by reckless behavior, ignoring safety signs, personal health issues, or incidents occurring under the influence of alcohol.</li>
                    <li><strong style="text-indent: 40px;">Mandatory Incident Reporting:</strong> Any injury or accident must be reported immediately to the Front Desk or Security Team so we can assist with first aid or medical transport. Management reserves the right to review CCTV footage for incident verification.</li>
                </ul>

                <h4>8. Damage to Property &amp; Lost Items</h4>
                <ul class="modal-list">
                    <li><strong style="text-indent: 40px;">Property Damage:</strong> Guests will be held financially responsible for any damage caused to hotel property, furniture, or equipment during their stay (beyond normal wear and tear). The cost of repairs or replacements will be charged to the guest's account or deducted from their system wallet.</li>
                    <li><strong style="text-indent: 40px;">Lost Items:</strong> Haven Hotel is not liable for the loss, theft, or damage of any personal belongings, money, or valuables left inside the guest rooms or public areas. Please use the in-room safes where available.</li>
                </ul>

                <h4>9. Force Majeure (Unforeseen Events)</h4>
                <p>Haven Hotel shall not be held liable or responsible for failure to provide services, cancellations, or delays caused by events beyond our reasonable control. This includes, but is not limited to: natural disasters (earthquakes, typhoons), fires, government-mandated lockdowns, power grid failures, or acts of God. In such cases, refunds or re-bookings will be handled at the sole discretion of hotel management.</p>

                <h4>10. Changes to These Terms</h4>
                <p>We may update these terms occasionally. Continued use of your account means you accept the updated rules. Major changes will be emailed to your address on file.</p>

                <p class="modal-updated">Last updated: June 2026</p>
            </div>
        </div>
    </div>

    <!-- NEW: Suspended-account customer-service chat modal. Same
         .modal-overlay/.modal-box shell as the Terms modal above for visual
         consistency, with a chat transcript in place of static copy. -->
    <div class="modal-overlay" id="supportChatModal" aria-hidden="true">
        <div class="modal-box chat-modal-box" role="dialog" aria-modal="true" aria-labelledby="supportChatTitle">
            <div class="modal-header">
                <h3 id="supportChatTitle"><i class="fa-solid fa-headset"></i> Haven Hotel Support</h3>
                <button type="button" class="modal-close" id="closeSupportChat" aria-label="Close">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="chat-transcript" id="chatTranscript" aria-live="polite"></div>
            <div class="chat-input-row">
                <input type="text" id="chatInput" placeholder="Type a message…" autocomplete="off">
                <button type="button" id="chatSendBtn" aria-label="Send"><i class="fa-solid fa-paper-plane"></i></button>
            </div>
        </div>
    </div>

    <!-- NEW: Forgot Password modal. No outbound-mail infrastructure exists
         in this codebase, so rather than claiming to "email" a link, the
         reset link is generated and shown directly here - the copy makes
         that explicit instead of implying an email was sent. -->
    <div class="modal-overlay" id="forgotPasswordModal" aria-hidden="true">
        <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="forgotPwTitle">
            <div class="modal-header">
                <h3 id="forgotPwTitle">Reset Your Password</h3>
                <button type="button" class="modal-close" id="closeForgotPassword" aria-label="Close">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="modal-body">
                <p style="margin-bottom:16px;">Enter the email address on your account. We'll generate a one-time reset link valid for 1 hour.</p>

                <div class="input-group" style="margin-bottom:18px;">
                    <input type="email" id="forgotEmailInput" placeholder=" ">
                    <label for="forgotEmailInput">Email Address</label>
                </div>

                <button type="button" class="btn-gold" id="forgotSubmitBtn" style="margin-top:0;">
                    <span id="forgotBtnLabel">Generate Reset Link</span>
                    <i class="fa-solid fa-spinner fa-spin" id="forgotBtnSpinner" style="display:none;"></i>
                </button>

                <div id="forgotResultArea" style="margin-top:16px;"></div>
            </div>
        </div>
    </div>

    <script>
        // NEW: server-populated context for the suspended-account chat.
        // Null when the last login attempt wasn't a suspension, in which
        // case the chat modal is simply never opened.
        const suspendedContext = <?= $suspendedContext ? json_encode($suspendedContext) : 'null' ?>;
    </script>

    <script>
        function togglePasswordField() {
            const pwd = document.getElementById('password');
            const icon = document.getElementById('toggleIcon');
            const isHidden = pwd.type === 'password';
            pwd.type = isHidden ? 'text' : 'password';
            icon.classList.toggle('fa-eye', !isHidden);
            icon.classList.toggle('fa-eye-slash', isHidden);
        }

        document.getElementById('loginForm').addEventListener('submit', function() {
            document.getElementById('btnLabel').style.display = 'none';
            document.getElementById('btnSpinner').style.display = 'inline-block';
            document.getElementById('submitBtn').setAttribute('disabled', 'disabled');
        });

        // ---- Terms modal ----
        const termsModal = document.getElementById('termsModal');
        document.getElementById('openTerms').addEventListener('click', () => {
            termsModal.classList.add('open');
            termsModal.setAttribute('aria-hidden', 'false');
        });
        document.getElementById('closeTerms').addEventListener('click', closeTermsModal);
        termsModal.addEventListener('click', (e) => {
            if (e.target === termsModal) closeTermsModal();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeTermsModal();
        });
        function closeTermsModal() {
            termsModal.classList.remove('open');
            termsModal.setAttribute('aria-hidden', 'true');
        }

        // ---- Front desk live time/date ----
        (function () {
            const timeEl = document.getElementById('deskTime');
            const dateEl = document.getElementById('deskDate');
            function tick() {
                const now = new Date();
                timeEl.textContent = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                dateEl.textContent = now.toLocaleDateString([], { weekday: 'long', month: 'long', day: 'numeric' });
            }
            tick();
            setInterval(tick, 15000);
        })();

        // ---- Hero slideshow: auto-fade + manual dot navigation ----
        (function () {
            const slides = document.querySelectorAll('#heroSlideshow .slide');
            const dots = document.querySelectorAll('#slideDots .dot');
            let current = 0;
            let timer = null;

            function goTo(index) {
                slides[current].classList.remove('active');
                dots[current].classList.remove('active');
                current = index;
                slides[current].classList.add('active');
                dots[current].classList.add('active');
            }

            function next() {
                goTo((current + 1) % slides.length);
            }

            function startAutoplay() {
                clearInterval(timer);
                timer = setInterval(next, 5000);
            }

            dots.forEach((dot) => {
                dot.addEventListener('click', () => {
                    const idx = parseInt(dot.dataset.slide, 10);
                    if (idx !== current) goTo(idx);
                    startAutoplay(); // reset the timer after manual interaction
                });
            });

            startAutoplay();
        })();

        // ---- Slide transition to sign-up.php ----
        document.querySelectorAll('.page-link').forEach((link) => {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                const target = this.dataset.target;
                document.body.classList.add('page-exit');
                setTimeout(() => { window.location.href = target; }, 380);
            });
        });

        // ============================================================
        // NEW: Suspended-account customer-service chat
        // ============================================================
        (function () {
            const chatModal = document.getElementById('supportChatModal');
            const transcript = document.getElementById('chatTranscript');
            const chatInput = document.getElementById('chatInput');
            const chatSendBtn = document.getElementById('chatSendBtn');
            const openBtn = document.getElementById('openSupportChat');
            const closeBtn = document.getElementById('closeSupportChat');

            if (!chatModal) return; // no chat markup on this render (shouldn't happen, but guard anyway)

            let statusPollTimer = null;
            let awaitingConfirm = false; // true once the bot has offered "Send Request to Admin"
            // NEW: tracks what the guest actually typed (not the bot's
            // scripted replies), so confirm_request can send admin
            // something more useful than a static placeholder sentence -
            // see sendConfirmRequest() below, which joins this into a
            // short summary for the guest_message column.
            const guestMessageLog = [];

            function escapeHtml(str) {
                const div = document.createElement('div');
                div.textContent = str;
                return div.innerHTML;
            }

            function addBubble(text, sender) {
                const bubble = document.createElement('div');
                bubble.className = 'chat-bubble chat-bubble--' + sender;
                bubble.innerHTML = escapeHtml(text);
                transcript.appendChild(bubble);
                transcript.scrollTop = transcript.scrollHeight;
                return bubble;
            }

            function addTypingIndicator() {
                const bubble = document.createElement('div');
                bubble.className = 'chat-bubble chat-bubble--agent chat-bubble--typing';
                bubble.id = 'typingIndicator';
                bubble.innerHTML = '<span></span><span></span><span></span>';
                transcript.appendChild(bubble);
                transcript.scrollTop = transcript.scrollHeight;
            }

            function removeTypingIndicator() {
                const el = document.getElementById('typingIndicator');
                if (el) el.remove();
            }

            function addConfirmButton() {
                const wrap = document.createElement('div');
                wrap.className = 'chat-confirm-wrap';
                wrap.innerHTML = '<button type="button" class="btn-outline-gold" id="chatConfirmBtn">Send Request to Admin</button>';
                transcript.appendChild(wrap);
                transcript.scrollTop = transcript.scrollHeight;
                document.getElementById('chatConfirmBtn').addEventListener('click', function () {
                    wrap.remove();
                    sendConfirmRequest();
                });
            }

            function openChat() {
                chatModal.classList.add('open');
                chatModal.setAttribute('aria-hidden', 'false');
                if (transcript.childElementCount === 0 && suspendedContext) {
                    addBubble('Hi, this is HavenHotel agents, how can I help you?', 'agent');
                    // Immediately follow the greeting with the accurate,
                    // account-specific reason - matches the brief's "tell
                    // the user what they did" requirement without waiting
                    // for the guest to ask first.
                    fetchChatReply('');
                }
                startStatusPoll();
            }

            function closeChat() {
                chatModal.classList.remove('open');
                chatModal.setAttribute('aria-hidden', 'true');
                stopStatusPoll();
            }

            function fetchChatReply(message) {
                addTypingIndicator();
                const body = new URLSearchParams({
                    action: 'chat_reply',
                    user_id: suspendedContext.user_id,
                    message: message,
                });
                fetch('auth_ajax.php', { method: 'POST', body })
                    .then((r) => r.json())
                    .then((data) => {
                        removeTypingIndicator();
                        if (!data.ok) {
                            addBubble('Sorry, something went wrong on our end. Please try again in a moment.', 'agent');
                            return;
                        }
                        addBubble(data.reply, 'agent');
                        awaitingConfirm = !!data.offer_confirm;
                        if (data.offer_confirm) {
                            addConfirmButton();
                        }
                        if (data.resolved) {
                            stopStatusPoll();
                            showLoginPrompt();
                        }
                    })
                    .catch(() => {
                        removeTypingIndicator();
                        addBubble('Sorry, something went wrong on our end. Please try again in a moment.', 'agent');
                    });
            }

            function sendConfirmRequest() {
                addTypingIndicator();
                // Cap at the most recent 3 messages, ~400 chars total - enough
                // context for an admin to skim without the request card
                // growing unbounded if a guest typed a long back-and-forth.
                const contextSnippet = guestMessageLog.slice(-3).join(' | ').slice(0, 400);
                const body = new URLSearchParams({
                    action: 'confirm_request',
                    user_id: suspendedContext.user_id,
                    context: contextSnippet,
                });
                fetch('auth_ajax.php', { method: 'POST', body })
                    .then((r) => r.json())
                    .then((data) => {
                        removeTypingIndicator();
                        if (!data.ok) {
                            addBubble('Sorry, something went wrong sending that request. Please try again.', 'agent');
                            return;
                        }
                        addBubble(data.reply, 'agent');
                        if (data.resolved) {
                            stopStatusPoll();
                            showLoginPrompt();
                        }
                    })
                    .catch(() => {
                        removeTypingIndicator();
                        addBubble('Sorry, something went wrong sending that request. Please try again.', 'agent');
                    });
            }

            function showLoginPrompt() {
                const wrap = document.createElement('div');
                wrap.className = 'chat-confirm-wrap';
                wrap.innerHTML = '<button type="button" class="btn-outline-gold" id="chatLoginNowBtn">Log In Now</button>';
                transcript.appendChild(wrap);
                transcript.scrollTop = transcript.scrollHeight;
                document.getElementById('chatLoginNowBtn').addEventListener('click', function () {
                    closeChat();
                    document.getElementById('email').value = suspendedContext.email;
                    document.getElementById('password').focus();
                });
            }

            // NEW: polls auth_ajax.php every 20s while the chat is open so a
            // guest whose account gets approved mid-session sees the "good
            // to go" notice without needing to retype anything. Same
            // honest short-interval-polling approach index.php already
            // uses for testimonials_ajax.php - no fake real-time claims.
            function startStatusPoll() {
                stopStatusPoll();
                if (!suspendedContext) return;
                statusPollTimer = setInterval(() => {
                    const body = new URLSearchParams({
                        action: 'chat_status_check',
                        user_id: suspendedContext.user_id,
                    });
                    fetch('auth_ajax.php', { method: 'POST', body })
                        .then((r) => r.json())
                        .then((data) => {
                            if (data.ok && data.resolved) {
                                stopStatusPoll();
                                addBubble("Good news — your account has been reactivated. You're good to go and can log in now.", 'agent');
                                showLoginPrompt();
                            }
                        })
                        .catch(() => { /* silent - next poll will retry */ });
                }, 20000);
            }

            function stopStatusPoll() {
                if (statusPollTimer) {
                    clearInterval(statusPollTimer);
                    statusPollTimer = null;
                }
            }

            function handleSend() {
                const text = chatInput.value.trim();
                if (!text) return;
                addBubble(text, 'guest');
                guestMessageLog.push(text);
                chatInput.value = '';
                fetchChatReply(text);
            }

            if (openBtn) openBtn.addEventListener('click', openChat);
            closeBtn.addEventListener('click', closeChat);
            chatModal.addEventListener('click', (e) => {
                if (e.target === chatModal) closeChat();
            });
            chatSendBtn.addEventListener('click', handleSend);
            chatInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    handleSend();
                }
            });

            // Auto-open the chat the moment a suspended-login attempt
            // renders, so the guest isn't left staring at a bare error
            // line and a button they have to notice and click first.
            if (suspendedContext) {
                openChat();
            }
        })();

        // ============================================================
        // NEW: Forgot Password
        // ============================================================
        (function () {
            const modal = document.getElementById('forgotPasswordModal');
            const openBtn = document.getElementById('openForgotPassword');
            const closeBtn = document.getElementById('closeForgotPassword');
            const emailInput = document.getElementById('forgotEmailInput');
            const submitBtn = document.getElementById('forgotSubmitBtn');
            const btnLabel = document.getElementById('forgotBtnLabel');
            const btnSpinner = document.getElementById('forgotBtnSpinner');
            const resultArea = document.getElementById('forgotResultArea');

            function openModal() {
                modal.classList.add('open');
                modal.setAttribute('aria-hidden', 'false');
                emailInput.value = document.getElementById('email').value || '';
                resultArea.innerHTML = '';
            }

            function closeModal() {
                modal.classList.remove('open');
                modal.setAttribute('aria-hidden', 'true');
            }

            function setLoading(isLoading) {
                submitBtn.disabled = isLoading;
                btnLabel.style.display = isLoading ? 'none' : 'inline';
                btnSpinner.style.display = isLoading ? 'inline-block' : 'none';
            }

            function escapeHtml(str) {
                const div = document.createElement('div');
                div.textContent = str;
                return div.innerHTML;
            }

            openBtn.addEventListener('click', openModal);
            closeBtn.addEventListener('click', closeModal);
            modal.addEventListener('click', (e) => {
                if (e.target === modal) closeModal();
            });

            submitBtn.addEventListener('click', function () {
                const email = emailInput.value.trim();
                if (!email) {
                    resultArea.innerHTML = '<div class="alert-error"><i class="fa-solid fa-circle-exclamation"></i> Please enter your email address.</div>';
                    return;
                }
                setLoading(true);
                resultArea.innerHTML = '';

                const body = new URLSearchParams({ action: 'forgot_password', email });
                fetch('auth_ajax.php', { method: 'POST', body })
                    .then((r) => r.json())
                    .then((data) => {
                        setLoading(false);
                        if (!data.ok) {
                            resultArea.innerHTML = '<div class="alert-error"><i class="fa-solid fa-circle-exclamation"></i> ' + escapeHtml(data.error || 'Something went wrong.') + '</div>';
                            return;
                        }
                        let html = '<div class="alert-success"><i class="fa-solid fa-circle-check"></i> ' + escapeHtml(data.reply) + '</div>';
                        if (data.link) {
                            // Demo-mode notice: no outbound-mail infrastructure exists
                            // in this codebase, so the link is surfaced here directly
                            // rather than implying it was emailed.
                            html += '<div class="reset-link-box"><i class="fa-solid fa-triangle-exclamation"></i> No email system is connected yet, so here is your link directly: <a href="' + escapeHtml(data.link) + '">' + escapeHtml(data.link) + '</a> (valid 1 hour)</div>';
                        }
                        resultArea.innerHTML = html;
                    })
                    .catch(() => {
                        setLoading(false);
                        resultArea.innerHTML = '<div class="alert-error"><i class="fa-solid fa-circle-exclamation"></i> A network error occurred. Please try again.</div>';
                    });
            });
        })();
    </script>

</body>
</html>