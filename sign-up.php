<?php
// sign-up.php
session_start();

require_once 'classes/Database.php';
require_once 'classes/User.php';

$error = "";
$success = "";

// Instantiate once up-front so the allow-list can be rendered as a hint,
// even before the form is submitted.
$allowedDomainsForHint = ['gmail.com', 'yahoo.com', 'outlook.com', 'hotmail.com', 'icloud.com'];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Instantiate OOP engine layers
    $database = new Database();
    $dbConn = $database->getConnection();
    $userEngine = new User($dbConn);
    $allowedDomainsForHint = $userEngine->getAllowedEmailDomains();

    // Sanitize user inputs safely
    $first_name = trim(filter_var($_POST['first_name'], FILTER_SANITIZE_SPECIAL_CHARS));
    $last_name  = trim(filter_var($_POST['last_name'], FILTER_SANITIZE_SPECIAL_CHARS));
    $email      = trim(filter_var($_POST['email'], FILTER_SANITIZE_EMAIL));
    $phone      = trim(filter_var($_POST['phone'], FILTER_SANITIZE_SPECIAL_CHARS));
    $phone_national = trim(filter_var($_POST['phone_national'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS));
    $password   = $_POST['password'];
    $admin_key  = trim($_POST['admin_key'] ?? '');

    if (empty($first_name) || empty($last_name) || empty($email) || empty($password)) {
        $error = "Please fill in all mandatory fields marked with an asterisk (*).";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address configuration.";
    } elseif (!$userEngine->isAllowedEmailDomain($email)) {
        // Enforce the accepted-provider allow-list before it ever reaches register().
        $error = "Please sign up using a real email provider (" . implode(', ', $allowedDomainsForHint) . ").";
    } elseif ($phone_national !== '' && !preg_match('/^\d{11,12}$/', $phone_national)) {
        // NEW: phone number stays optional overall (matches the previous
        // behavior - no asterisk on this field), but when the guest DOES
        // type something, it must be exactly 11-12 digits, numbers only.
        // Checked against phone_national (the raw national-number input)
        // rather than the combined $phone string, since $phone also
        // contains the dial code prefix and would never match this pattern.
        $error = "Phone number must be 11-12 digits, numbers only (no letters or symbols).";
    } elseif (strlen($password) < 8) {
        $error = "Password security constraint failure: Must be at least 8 characters.";
    } else {
        // register() now creates the account already verified and returns:
        //   - true   => success, log in right away, no email step
        //   - string => an error message to show the user
        //   - false  => generic failure
        $registrationResult = $userEngine->register($first_name, $last_name, $email, $phone, $password, $admin_key);

        if ($registrationResult === true) {
            $success = "Your Haven Hotel profile account has been registered successfully! Redirecting...";
            echo "<script>setTimeout(function(){ window.location.href = 'login.php'; }, 2000);</script>";
        } else {
            $error = is_string($registrationResult) ? $registrationResult : "Registration failed. Please try again.";
        }
    }

    $database->closeConnection();
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Haven Hotel - Create Account</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="ui/sign-up.css">
    <style>
        * {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Poppins', sans-serif;
    background: #f7f4ef;
    color: #1e1e1e;
    min-height: 100vh;
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 20px;
    overflow-x: hidden;
}

/* ---- Page slide transition (matches login.php) ---- */
body.page-enter .signup-card {
    animation: slideIn 0.42s ease forwards;
}

body.page-exit .signup-card {
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

/* Card container matching the premium layout aesthetic of workspace cards */
.signup-card {
    background-color: #ffffff;
    border: 1px solid #f2eade;
    border-radius: 16px;
    width: 100%;
    max-width: 560px;
    padding: 44px 50px;
    box-shadow: 0 20px 50px rgba(15, 23, 42, 0.06);
}

.brand-mark {
    font-family: 'Playfair Display', serif;
    font-size: 15px;
    font-weight: 700;
    letter-spacing: 0.5px;
    color: #111111;
    margin-bottom: 14px;
}

.brand-mark span {
    color: #c69c4f;
}

.signup-header {
    text-align: center;
    margin-bottom: 32px;
}

.signup-header h1 {
    font-family: "Playfair Display", serif;
    font-size: 32px;
    font-weight: 600;
    color: #111111;
    margin-bottom: 8px;
}

.signup-header p {
    font-size: 13px;
    color: #888888;
}

/* Structural Field Mesh Layout Framework Grid */
.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
}

.full-width {
    grid-column: span 2;
}

.input-wrapper {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.input-wrapper label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: #7a7a7a;
}

.input-wrapper label span {
    color: #c69c4f;
    margin-left: 2px;
}

.input-wrapper input {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid #e6ded2;
    background-color: #fff;
    border-radius: 8px;
    font-size: 14px;
    font-family: inherit;
    color: #111;
    outline: none;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.input-wrapper input:focus {
    border-color: #c69c4f;
    box-shadow: 0 0 0 3px rgba(198, 156, 79, 0.12);
}

.field-hint {
    font-size: 11.5px;
    color: #9a9488;
    margin-top: 2px;
    display: flex;
    align-items: center;
    gap: 5px;
}

.field-hint i {
    color: #c69c4f;
    font-size: 11px;
}

.password-field-wrap {
    position: relative;
    display: flex;
    align-items: center;
}

.password-field-wrap input {
    padding-right: 42px;
}

.toggle-visibility {
    position: absolute;
    right: 14px;
    background: none;
    border: none;
    color: #999;
    cursor: pointer;
    font-size: 14px;
    padding: 4px;
    display: flex;
}

.toggle-visibility:hover {
    color: #c69c4f;
}

/* ============================================================
   NEW: Phone number field - country code picker + restricted
   national-number input
   ============================================================ */
.phone-field-group {
    display: flex;
    gap: 8px;
}

.phone-field-group input#phone_national {
    flex: 1;
    min-width: 0; /* allow the flex item to shrink below its content width */
}

.field-hint--error {
    color: #cb4335;
    display: none;
}

.field-hint--error i {
    color: #cb4335;
}

.input-wrapper.has-phone-error input#phone_national,
.input-wrapper.has-phone-error .country-code-trigger {
    border-color: #cb4335;
}

.country-code-picker {
    position: relative;
    flex-shrink: 0;
}

.country-code-trigger {
    display: flex;
    align-items: center;
    gap: 7px;
    height: 100%;
    padding: 12px 12px;
    border: 1px solid #e6ded2;
    background-color: #fff;
    border-radius: 8px;
    font-size: 14px;
    font-family: inherit;
    color: #111;
    cursor: pointer;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
    white-space: nowrap;
}

.country-code-trigger:hover {
    border-color: #d8cbb0;
}

.country-code-trigger:focus,
.country-code-picker.open .country-code-trigger {
    outline: none;
    border-color: #c69c4f;
    box-shadow: 0 0 0 3px rgba(198, 156, 79, 0.12);
}

.country-code-trigger i {
    font-size: 10px;
    color: #9a9488;
    transition: transform 0.15s ease;
}

.country-code-picker.open .country-code-trigger i {
    transform: rotate(180deg);
}

.country-code-dropdown {
    position: absolute;
    top: calc(100% + 6px);
    left: 0;
    width: 280px;
    max-width: 80vw;
    background: #fff;
    border: 1px solid #e6ded2;
    border-radius: 10px;
    box-shadow: 0 16px 40px rgba(15, 23, 42, 0.14);
    z-index: 20;
    display: none;
    flex-direction: column;
    overflow: hidden;
}

.country-code-picker.open .country-code-dropdown {
    display: flex;
}

.country-search-wrap {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 12px;
    border-bottom: 1px solid #f0e9dc;
}

.country-search-wrap i {
    color: #9a9488;
    font-size: 12px;
    flex-shrink: 0;
}

.country-search-wrap input {
    border: none;
    outline: none;
    font-size: 13px;
    font-family: inherit;
    width: 100%;
    color: #111;
}

.country-options-list {
    max-height: 240px;
    overflow-y: auto;
}

.country-option {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 12px;
    cursor: pointer;
    font-size: 13px;
    transition: background-color 0.1s ease;
}

.country-option:hover,
.country-option.highlighted {
    background: #f7f2e6;
}

.country-option .country-option-name {
    flex: 1;
    color: #1e1e1e;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.country-option .country-option-dial {
    color: #9a9488;
    font-size: 12px;
    flex-shrink: 0;
}

.country-options-empty {
    padding: 16px 12px;
    text-align: center;
    font-size: 12.5px;
    color: #9a9488;
}

/* Terms & Conditions checkbox line */
.terms-line {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    font-size: 12px;
    color: #7a7a7a;
    line-height: 1.5;
    cursor: pointer;
}

.terms-line input[type="checkbox"] {
    margin-top: 2px;
    accent-color: #c69c4f;
    cursor: pointer;
}

.link-btn {
    background: none;
    border: none;
    padding: 0;
    font: inherit;
    font-weight: 600;
    color: #c69c4f;
    cursor: pointer;
}

.link-btn:hover {
    text-decoration: underline;
}

/* Action Feedback System Messaging Alerts CSS */
.alert {
    grid-column: span 2;
    padding: 12px 16px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 8px;
}

.alert-error {
    background-color: #fdedec;
    color: #cb4335;
    border: 1px solid #fadbd8;
}

.alert-success {
    background-color: #e8f8f5;
    color: #1e8449;
    border: 1px solid #d1f2eb;
}

/* Active Core Form Action Trigger Button Layout Style */
.submit-btn {
    width: 100%;
    background-color: #c69c4f;
    color: #fff;
    border: none;
    padding: 14px;
    border-radius: 25px;
    font-size: 14px;
    font-weight: 600;
    font-family: inherit;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: background-color 0.15s ease, transform 0.1s ease;
    margin-top: 10px;
}

.submit-btn:hover {
    background-color: #b58c42;
}

.submit-btn:active {
    transform: scale(0.98);
}

.submit-btn:disabled {
    background: #d8c39a;
    cursor: not-allowed;
}

.footer-note {
    text-align: center;
    margin-top: 25px;
    font-size: 13px;
    color: #666;
}

.footer-note a {
    color: #c69c4f;
    text-decoration: none;
    font-weight: 600;
}

.footer-note a:hover {
    text-decoration: underline;
}

/* ---- Terms & Conditions Modal (shared styling with login.css) ---- */
.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(17, 17, 17, 0.55);
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
    background: #fffefb;
    width: min(600px, 92vw);
    max-height: 80vh;
    border-radius: 14px;
    display: flex;
    flex-direction: column;
    box-shadow: 0 30px 70px rgba(0, 0, 0, 0.25);
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
    padding: 20px 24px;
    border-bottom: 1px solid #efe7d8;
}

.modal-header h3 {
    font-family: 'Playfair Display', serif;
    font-size: 18px;
    color: #111111;
}

.modal-close {
    background: none;
    border: none;
    color: #888888;
    font-size: 18px;
    cursor: pointer;
    padding: 4px 8px;
}

.modal-close:hover {
    color: #c69c4f;
}

.modal-body {
    padding: 20px 24px 28px;
    overflow-y: auto;
    font-size: 13px;
    line-height: 1.65;
    color: #3a3a3a;
}

.modal-body h4 {
    font-size: 13.5px;
    color: #111111;
    margin: 18px 0 6px;
}

.modal-body h4:first-child {
    margin-top: 0;
}

.modal-body p {
    margin-bottom: 4px;
}

/* NEW: bullet/numbered lists for the Booking & Payment, Cancellation, and
   Safety sections of the updated Terms content, which use nested
   sub-points the previous plain-<p> Terms text didn't have. */
.modal-body .modal-list {
    list-style: none;
    margin: 0 0 4px;
    padding: 0;
}

.modal-body .modal-list > li {
    position: relative;
    padding-left: 16px;
    margin-bottom: 10px;
}

.modal-body .modal-list > li::before {
    content: "•";
    position: absolute;
    left: 0;
    color: #c69c4f;
    font-weight: 700;
}

.modal-body .modal-sublist {
    margin: 8px 0 0 16px;
    padding-left: 18px;
    list-style: decimal;
}

.modal-body .modal-sublist li {
    margin-bottom: 6px;
}

/* Marks a gap in the source Terms document that couldn't be transcribed
   (see the "status" fields in section 2) - styled distinctly so it reads
   as a flagged placeholder, not as if it were part of the actual policy
   wording. */
.modal-body em {
    font-style: italic;
    color: #b5482f;
    background: #fbeee9;
    padding: 1px 4px;
    border-radius: 3px;
}

.modal-updated {
    margin-top: 18px;
    font-size: 11.5px;
    color: #9a9488;
    font-style: italic;
}

/* Screen Responsive Handling Breakpoints */
@media (max-width: 600px) {
    .signup-card {
        padding: 30px 24px;
    }
    .form-grid {
        grid-template-columns: 1fr;
        gap: 16px;
    }
    .full-width {
        grid-column: span 1;
    }
}
    </style>
</head>
<body class="page-enter">

    <div class="signup-card">
        <div class="signup-header">
            <div class="brand-mark">Haven<span>Hotel</span></div>
            <h1>Join Haven Hotel</h1>
            <p>Create an account to unlock fine booking tools and tracking metrics.</p>
        </div>

        <form action="sign-up.php" method="POST" class="form-grid" id="signupForm">

            <?php if (!empty($error)): ?>
                <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <div class="input-wrapper">
                <label for="first_name">First Name<span>*</span></label>
                <input type="text" id="first_name" name="first_name" required placeholder="Evelyn" value="<?= isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : '' ?>">
            </div>

            <div class="input-wrapper">
                <label for="last_name">Last Name<span>*</span></label>
                <input type="text" id="last_name" name="last_name" required placeholder="Sterling" value="<?= isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : '' ?>">
            </div>

            <div class="input-wrapper full-width">
                <label for="email">Email Address<span>*</span></label>
                <input type="email" id="email" name="email" required placeholder="evelyn@gmail.com" value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>">
                <span class="field-hint"><i class="fa-solid fa-circle-info"></i> Accepted providers: <?= implode(', ', array_map('htmlspecialchars', $allowedDomainsForHint)) ?></span>
            </div>

            <div class="input-wrapper full-width">
                <label for="phone_national">Phone Number</label>
                <div class="phone-field-group">
                    <div class="country-code-picker" id="countryCodePicker">
                        <button type="button" class="country-code-trigger" id="countryCodeTrigger" aria-haspopup="listbox" aria-expanded="false">
                            <span id="selectedFlag">🇵🇭</span>
                            <span id="selectedDialCode">+63</span>
                            <i class="fa-solid fa-chevron-down"></i>
                        </button>
                        <div class="country-code-dropdown" id="countryCodeDropdown">
                            <div class="country-search-wrap">
                                <i class="fa-solid fa-magnifying-glass"></i>
                                <input type="text" id="countrySearchInput" placeholder="Search country or code..." autocomplete="off">
                            </div>
                            <div class="country-options-list" id="countryOptionsList"></div>
                        </div>
                    </div>
                    <input type="tel" id="phone_national" name="phone_national" placeholder="9171234567" inputmode="numeric" maxlength="20" value="<?= isset($_POST['phone_national']) ? htmlspecialchars($_POST['phone_national']) : '' ?>">
                </div>
                <input type="hidden" id="phone" name="phone" value="<?= isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : '' ?>">
                <input type="hidden" id="phone_dial_code" name="phone_dial_code" value="<?= isset($_POST['phone_dial_code']) ? htmlspecialchars($_POST['phone_dial_code']) : '+63' ?>">
                <span class="field-hint"><i class="fa-solid fa-circle-info"></i> Numbers only, 11-12 digits, no country code (e.g. 9171234567)</span>
                <span class="field-hint field-hint--error" id="phoneErrorHint" style="display:none;"><i class="fa-solid fa-triangle-exclamation"></i> Phone number must be 11-12 digits, numbers only.</span>
            </div>

            <div class="input-wrapper full-width">
                <label for="password">Password<span>*</span></label>
                <div class="password-field-wrap">
                    <input type="password" id="password" name="password" required minlength="8" placeholder="••••••••">
                    <button type="button" class="toggle-visibility" onclick="togglePasswordField()" tabindex="-1" aria-label="Show password">
                        <i class="fa-regular fa-eye" id="toggleIcon"></i>
                    </button>
                </div>
                <span class="field-hint">Minimum 8 characters</span>
            </div>

            <div class="full-width">
                <label class="terms-line">
                    <input type="checkbox" id="agreeTerms" required>
                    <span>I agree to the <button type="button" class="link-btn" id="openTerms">Terms &amp; Conditions</button></span>
                </label>
            </div>

            <div class="full-width">
                <button type="submit" class="submit-btn" id="submitBtn">
                    <span id="btnLabel">Register Account</span>
                    <i class="fa-solid fa-spinner fa-spin" id="btnSpinner" style="display:none;"></i>
                </button>
            </div>
        </form>

        <div class="footer-note">
            Already have an account? <a href="login.php" class="page-link" data-target="login.php">Sign In Here</a>
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
                    <li><strong>50% Downpayment:</strong> To secure your room, a 50% downpayment is required immediately upon booking. Your slot will be locked under <i>[status label not available in source document]</em> status.</li>
                    <li><strong>48-Hour Deadline:</strong> You have exactly 48 hours from the time of booking to settle the remaining 50% balance to change your status to <i>[status label not available in source document]</em>.</li>
                    <li><strong>Automatic Cancellation:</strong> If the balance is not paid within 48 hours, the system will automatically cancel your reservation.</li>
                    <li><strong>Missed Deadline Refund:</strong> If the system auto-cancels your room due to non-payment, 70% of your downpayment will be refunded back to your System Wallet Balance.</li>
                </ul>

                <h4>3. Cancellation &amp; Refund Policy (Sliding Scale)</h4>
                <p>All processed refunds are automatically credited back to your System Wallet Balance for future use. The refund amount depends on when you cancel:</p>
                <ul class="modal-list">
                    <li><strong>Before Check-In Date:</strong> If you cancel before your stay begins, you will receive a 70% refund of the total amount you have paid.</li>
                    <li><strong>Mid-Stay Cancellation (Partial Stay):</strong> If you decide to cut your stay short and leave early after checking in:
                        <ol class="modal-sublist">
                            <li>The nights you have already stayed are non-refundable and will be fully charged.</li>
                            <li>For the remaining unused nights, you will receive a refund ranging from 70% down to 50%. The sooner you cancel after checking in, the closer you get to a 70% refund for the unused nights. The longer you wait to cancel during your stay, the refund drops closer to the 50% minimum floor.</li>
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
                    <li><strong>Use Facilities at Your Own Risk:</strong> By using our rooms, swimming pools, gym, and common areas, guests acknowledge the inherent risks of recreational activities and agree to use them at their own risk.</li>
                    <li><strong>Compliance with Safety Rules:</strong> Guests must follow all posted warning signs (e.g., "Wet Floor" signs, pool safety rules). Parents or guardians are solely responsible for supervising minors at all times.</li>
                    <li><strong>Limitation of Injury Liability:</strong> Haven Hotel and its staff are not financially responsible for any personal injury, medical expenses, or health emergencies occurring on the premises, unless directly caused by gross negligence or willful fault of the hotel management.</li>
                    <li><strong>Personal Responsibility:</strong> The hotel assumes no liability for accidents caused by reckless behavior, ignoring safety signs, personal health issues, or incidents occurring under the influence of alcohol.</li>
                    <li><strong>Mandatory Incident Reporting:</strong> Any injury or accident must be reported immediately to the Front Desk or Security Team so we can assist with first aid or medical transport. Management reserves the right to review CCTV footage for incident verification.</li>
                </ul>

                <h4>8. Damage to Property &amp; Lost Items</h4>
                <ul class="modal-list">
                    <li><strong>Property Damage:</strong> Guests will be held financially responsible for any damage caused to hotel property, furniture, or equipment during their stay (beyond normal wear and tear). The cost of repairs or replacements will be charged to the guest's account or deducted from their system wallet.</li>
                    <li><strong>Lost Items:</strong> Haven Hotel is not liable for the loss, theft, or damage of any personal belongings, money, or valuables left inside the guest rooms or public areas. Please use the in-room safes where available.</li>
                </ul>

                <h4>9. Force Majeure (Unforeseen Events)</h4>
                <p>Haven Hotel shall not be held liable or responsible for failure to provide services, cancellations, or delays caused by events beyond our reasonable control. This includes, but is not limited to: natural disasters (earthquakes, typhoons), fires, government-mandated lockdowns, power grid failures, or acts of God. In such cases, refunds or re-bookings will be handled at the sole discretion of hotel management.</p>

                <h4>10. Changes to These Terms</h4>
                <p>We may update these terms occasionally. Continued use of your account means you accept the updated rules. Major changes will be emailed to your address on file.</p>

                <p class="modal-updated">Last updated: June 2026</p>
            </div>
        </div>
    </div>

    <script>
        // NEW: compact dial-code dataset. 62 countries/territories covering
        // all major regions - not the full ITU list of 200+, but broad
        // enough for a real international guest base while staying easy to
        // scan/maintain by hand. Philippines listed first to match this
        // page's +63 default.
        const COUNTRY_DIAL_CODES = [
            { iso: 'PH', name: 'Philippines', dial: '+63', flag: '🇵🇭' },
            { iso: 'US', name: 'United States', dial: '+1', flag: '🇺🇸' },
            { iso: 'GB', name: 'United Kingdom', dial: '+44', flag: '🇬🇧' },
            { iso: 'CA', name: 'Canada', dial: '+1', flag: '🇨🇦' },
            { iso: 'AU', name: 'Australia', dial: '+61', flag: '🇦🇺' },
            { iso: 'NZ', name: 'New Zealand', dial: '+64', flag: '🇳🇿' },
            { iso: 'IE', name: 'Ireland', dial: '+353', flag: '🇮🇪' },
            { iso: 'SG', name: 'Singapore', dial: '+65', flag: '🇸🇬' },
            { iso: 'MY', name: 'Malaysia', dial: '+60', flag: '🇲🇾' },
            { iso: 'ID', name: 'Indonesia', dial: '+62', flag: '🇮🇩' },
            { iso: 'TH', name: 'Thailand', dial: '+66', flag: '🇹🇭' },
            { iso: 'VN', name: 'Vietnam', dial: '+84', flag: '🇻🇳' },
            { iso: 'JP', name: 'Japan', dial: '+81', flag: '🇯🇵' },
            { iso: 'KR', name: 'South Korea', dial: '+82', flag: '🇰🇷' },
            { iso: 'CN', name: 'China', dial: '+86', flag: '🇨🇳' },
            { iso: 'HK', name: 'Hong Kong', dial: '+852', flag: '🇭🇰' },
            { iso: 'TW', name: 'Taiwan', dial: '+886', flag: '🇹🇼' },
            { iso: 'IN', name: 'India', dial: '+91', flag: '🇮🇳' },
            { iso: 'PK', name: 'Pakistan', dial: '+92', flag: '🇵🇰' },
            { iso: 'BD', name: 'Bangladesh', dial: '+880', flag: '🇧🇩' },
            { iso: 'LK', name: 'Sri Lanka', dial: '+94', flag: '🇱🇰' },
            { iso: 'NP', name: 'Nepal', dial: '+977', flag: '🇳🇵' },
            { iso: 'AE', name: 'United Arab Emirates', dial: '+971', flag: '🇦🇪' },
            { iso: 'SA', name: 'Saudi Arabia', dial: '+966', flag: '🇸🇦' },
            { iso: 'QA', name: 'Qatar', dial: '+974', flag: '🇶🇦' },
            { iso: 'KW', name: 'Kuwait', dial: '+965', flag: '🇰🇼' },
            { iso: 'BH', name: 'Bahrain', dial: '+973', flag: '🇧🇭' },
            { iso: 'OM', name: 'Oman', dial: '+968', flag: '🇴🇲' },
            { iso: 'IL', name: 'Israel', dial: '+972', flag: '🇮🇱' },
            { iso: 'TR', name: 'Turkey', dial: '+90', flag: '🇹🇷' },
            { iso: 'EG', name: 'Egypt', dial: '+20', flag: '🇪🇬' },
            { iso: 'ZA', name: 'South Africa', dial: '+27', flag: '🇿🇦' },
            { iso: 'NG', name: 'Nigeria', dial: '+234', flag: '🇳🇬' },
            { iso: 'KE', name: 'Kenya', dial: '+254', flag: '🇰🇪' },
            { iso: 'GH', name: 'Ghana', dial: '+233', flag: '🇬🇭' },
            { iso: 'DE', name: 'Germany', dial: '+49', flag: '🇩🇪' },
            { iso: 'FR', name: 'France', dial: '+33', flag: '🇫🇷' },
            { iso: 'IT', name: 'Italy', dial: '+39', flag: '🇮🇹' },
            { iso: 'ES', name: 'Spain', dial: '+34', flag: '🇪🇸' },
            { iso: 'PT', name: 'Portugal', dial: '+351', flag: '🇵🇹' },
            { iso: 'NL', name: 'Netherlands', dial: '+31', flag: '🇳🇱' },
            { iso: 'BE', name: 'Belgium', dial: '+32', flag: '🇧🇪' },
            { iso: 'CH', name: 'Switzerland', dial: '+41', flag: '🇨🇭' },
            { iso: 'AT', name: 'Austria', dial: '+43', flag: '🇦🇹' },
            { iso: 'SE', name: 'Sweden', dial: '+46', flag: '🇸🇪' },
            { iso: 'NO', name: 'Norway', dial: '+47', flag: '🇳🇴' },
            { iso: 'DK', name: 'Denmark', dial: '+45', flag: '🇩🇰' },
            { iso: 'FI', name: 'Finland', dial: '+358', flag: '🇫🇮' },
            { iso: 'PL', name: 'Poland', dial: '+48', flag: '🇵🇱' },
            { iso: 'GR', name: 'Greece', dial: '+30', flag: '🇬🇷' },
            { iso: 'CZ', name: 'Czech Republic', dial: '+420', flag: '🇨🇿' },
            { iso: 'RO', name: 'Romania', dial: '+40', flag: '🇷🇴' },
            { iso: 'HU', name: 'Hungary', dial: '+36', flag: '🇭🇺' },
            { iso: 'UA', name: 'Ukraine', dial: '+380', flag: '🇺🇦' },
            { iso: 'RU', name: 'Russia', dial: '+7', flag: '🇷🇺' },
            { iso: 'MX', name: 'Mexico', dial: '+52', flag: '🇲🇽' },
            { iso: 'BR', name: 'Brazil', dial: '+55', flag: '🇧🇷' },
            { iso: 'AR', name: 'Argentina', dial: '+54', flag: '🇦🇷' },
            { iso: 'CL', name: 'Chile', dial: '+56', flag: '🇨🇱' },
            { iso: 'CO', name: 'Colombia', dial: '+57', flag: '🇨🇴' },
            { iso: 'PE', name: 'Peru', dial: '+51', flag: '🇵🇪' },
            { iso: 'VE', name: 'Venezuela', dial: '+58', flag: '🇻🇪' },
        ];

        function togglePasswordField() {
            const pwd = document.getElementById('password');
            const icon = document.getElementById('toggleIcon');
            const isHidden = pwd.type === 'password';
            pwd.type = isHidden ? 'text' : 'password';
            icon.classList.toggle('fa-eye', !isHidden);
            icon.classList.toggle('fa-eye-slash', isHidden);
        }

        // ============================================================
        // NEW: Country code picker (searchable dropdown)
        // ============================================================
        (function () {
            const picker = document.getElementById('countryCodePicker');
            const trigger = document.getElementById('countryCodeTrigger');
            const dropdown = document.getElementById('countryCodeDropdown');
            const searchInput = document.getElementById('countrySearchInput');
            const optionsList = document.getElementById('countryOptionsList');
            const selectedFlag = document.getElementById('selectedFlag');
            const selectedDialCode = document.getElementById('selectedDialCode');
            const dialCodeHidden = document.getElementById('phone_dial_code');

            let selectedIso = 'PH';

            function renderOptions(filterText) {
                const q = (filterText || '').trim().toLowerCase();
                const matches = COUNTRY_DIAL_CODES.filter((c) => {
                    if (!q) return true;
                    return c.name.toLowerCase().includes(q) || c.dial.includes(q) || c.iso.toLowerCase() === q;
                });

                if (matches.length === 0) {
                    optionsList.innerHTML = '<div class="country-options-empty">No matching country found.</div>';
                    return;
                }

                optionsList.innerHTML = matches.map((c) => (
                    '<div class="country-option" data-iso="' + c.iso + '">' +
                        '<span>' + c.flag + '</span>' +
                        '<span class="country-option-name">' + c.name + '</span>' +
                        '<span class="country-option-dial">' + c.dial + '</span>' +
                    '</div>'
                )).join('');

                optionsList.querySelectorAll('.country-option').forEach((el) => {
                    el.addEventListener('click', () => {
                        selectCountry(el.dataset.iso);
                        closeDropdown();
                    });
                });
            }

            function selectCountry(iso) {
                const country = COUNTRY_DIAL_CODES.find((c) => c.iso === iso);
                if (!country) return;
                selectedIso = iso;
                selectedFlag.textContent = country.flag;
                selectedDialCode.textContent = country.dial;
                dialCodeHidden.value = country.dial;
            }

            function openDropdown() {
                picker.classList.add('open');
                trigger.setAttribute('aria-expanded', 'true');
                searchInput.value = '';
                renderOptions('');
                setTimeout(() => searchInput.focus(), 0);
            }

            function closeDropdown() {
                picker.classList.remove('open');
                trigger.setAttribute('aria-expanded', 'false');
            }

            trigger.addEventListener('click', (e) => {
                e.stopPropagation();
                if (picker.classList.contains('open')) {
                    closeDropdown();
                } else {
                    openDropdown();
                }
            });

            searchInput.addEventListener('input', () => renderOptions(searchInput.value));
            searchInput.addEventListener('click', (e) => e.stopPropagation());

            document.addEventListener('click', (e) => {
                if (!picker.contains(e.target)) closeDropdown();
            });
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') closeDropdown();
            });

            // Restore the previously-selected dial code on validation-error
            // reload (the hidden phone_dial_code field is repopulated
            // server-side from $_POST - see the value="" attribute in the
            // markup above), so a guest who picked, say, +44 doesn't see
            // the picker silently reset to the +63 default after an error.
            const repopulatedDial = dialCodeHidden.value;
            if (repopulatedDial && repopulatedDial !== '+63') {
                const match = COUNTRY_DIAL_CODES.find((c) => c.dial === repopulatedDial);
                if (match) selectCountry(match.iso);
            } else {
                selectCountry('PH');
            }
        })();

        // ============================================================
        // NEW: Phone number restriction - digits only, 11-12 chars,
        // blocked at the keystroke level (not just on submit)
        // ============================================================
        (function () {
            const phoneNationalInput = document.getElementById('phone_national');
            const phoneWrapper = phoneNationalInput.closest('.input-wrapper');
            const errorHint = document.getElementById('phoneErrorHint');

            phoneNationalInput.addEventListener('input', function () {
                // Strip anything that isn't a digit as the guest types, but
                // do NOT silently cap the length here - a guest who pastes
                // a too-long number should see all of what they pasted and
                // get a visible "must be 11-12 digits" error (from
                // __validatePhoneField below) so they know something's
                // wrong, rather than having it invisibly chopped down to a
                // shorter number that then looks valid but is actually
                // wrong. maxlength="20" on the input itself is just a
                // sanity ceiling against pasting something absurd, well
                // above the real 11-12 digit limit this enforces.
                const digitsOnly = this.value.replace(/\D/g, '');
                if (this.value !== digitsOnly) {
                    this.value = digitsOnly;
                }
                clearPhoneError();
            });

            function showPhoneError() {
                phoneWrapper.classList.add('has-phone-error');
                errorHint.style.display = 'flex';
            }

            function clearPhoneError() {
                phoneWrapper.classList.remove('has-phone-error');
                errorHint.style.display = 'none';
            }

            // Exposed so the submit handler below can validate + surface
            // the same error state on submit, not just on blur.
            window.__validatePhoneField = function () {
                const val = phoneNationalInput.value;
                if (val === '') return true; // phone stays optional overall
                if (!/^\d{11,12}$/.test(val)) {
                    showPhoneError();
                    return false;
                }
                clearPhoneError();
                return true;
            };

            phoneNationalInput.addEventListener('blur', function () {
                window.__validatePhoneField();
            });
        })();

        document.getElementById('signupForm').addEventListener('submit', function(e) {
            // NEW: block submission client-side on an invalid phone number,
            // same as the server-side check in sign-up.php - client-side
            // catches it instantly without a round trip, server-side is the
            // actual enforcement (JS can always be bypassed).
            if (!window.__validatePhoneField()) {
                e.preventDefault();
                document.getElementById('phone_national').focus();
                return;
            }

            // NEW: combine the selected dial code + national number into
            // the single hidden `phone` field that PHP/register() actually
            // reads, so the backend logic didn't need to change shape at
            // all - it still receives one coherent phone string.
            const dialCode = document.getElementById('phone_dial_code').value || '+63';
            const nationalNumber = document.getElementById('phone_national').value;
            document.getElementById('phone').value = nationalNumber ? (dialCode + nationalNumber) : '';

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

        // ---- Slide transition to login.php ----
        document.querySelectorAll('.page-link').forEach((link) => {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                const target = this.dataset.target;
                document.body.classList.add('page-exit');
                setTimeout(() => { window.location.href = target; }, 380);
            });
        });
    </script>

</body>
</html>