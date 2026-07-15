<?php
// reset-password.php
// Landing page for the token links auth_ajax.php's action=forgot_password
// generates. Uses the same card-style layout as sign-up.php (a focused
// single-action page doesn't need the split hero/slideshow treatment
// login.php and sign-up.php use for their primary entry points).
session_start();

require_once 'classes/Database.php';

$error = "";
$success = "";
$tokenValid = false;
$token = trim($_GET['token'] ?? $_POST['token'] ?? '');

$database = new Database();
$conn = $database->getConnection();

// NEW: same self-heal as auth_ajax.php/login.php. In the normal flow this
// table already exists by the time anyone has a real token (auth_ajax.php
// creates it when issuing the token in the first place), but a direct
// visit to this URL as literally the first request the app ever receives
// would otherwise hit prepare() returning false and fatal on the next
// line - this guards that edge case cheaply.
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

if (empty($token)) {
    $error = "This reset link is missing its token. Please request a new one from the sign-in page.";
} else {
    $stmt = $conn->prepare("SELECT reset_id, user_id, user_email, used, expires_at FROM password_resets WHERE token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    $resetRow = $result->fetch_assoc();
    $stmt->close();

    if (!$resetRow) {
        $error = "This reset link is invalid. Please request a new one from the sign-in page.";
    } elseif ((int)$resetRow['used'] === 1) {
        $error = "This reset link has already been used. Please request a new one if you still need to reset your password.";
    } elseif (strtotime($resetRow['expires_at']) < time()) {
        $error = "This reset link has expired (links are valid for 1 hour). Please request a new one from the sign-in page.";
    } else {
        $tokenValid = true;
    }
}

if ($tokenValid && $_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['password'])) {
    $password = $_POST['password'];
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 8) {
        $error = "Password security constraint failure: Must be at least 8 characters.";
    } elseif ($password !== $confirmPassword) {
        $error = "Passwords do not match. Please re-enter them.";
    } else {
        $hashed = password_hash($password, PASSWORD_BCRYPT);

        $update = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $update->bind_param("si", $hashed, $resetRow['user_id']);
        $update->execute();
        $update->close();

        $markUsed = $conn->prepare("UPDATE password_resets SET used = 1 WHERE reset_id = ?");
        $markUsed->bind_param("i", $resetRow['reset_id']);
        $markUsed->execute();
        $markUsed->close();

        $success = "Your password has been reset successfully. Redirecting to sign in...";
        echo "<script>setTimeout(function(){ window.location.href = 'login.php'; }, 2000);</script>";
        $tokenValid = false; // hide the form now that it's been used, show the success card instead
    }
}

$database->closeConnection();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Haven Hotel - Reset Password</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="ui/sign-up.css">
</head>
<body>

    <div class="signup-card" style="max-width: 440px;">
        <div class="signup-header">
            <div class="brand-mark">Haven<span>Hotel</span></div>
            <h1>Reset Password</h1>
            <p>Choose a new password for your account.</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error" style="grid-column: unset; margin-bottom: 20px;"><i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success" style="grid-column: unset; margin-bottom: 20px;"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <?php if ($tokenValid): ?>
            <form action="reset-password.php" method="POST" class="form-grid" id="resetForm" style="grid-template-columns: 1fr;">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

                <div class="input-wrapper full-width">
                    <label for="password">New Password<span>*</span></label>
                    <div class="password-field-wrap">
                        <input type="password" id="password" name="password" required minlength="8" placeholder="••••••••">
                        <button type="button" class="toggle-visibility" onclick="toggleField('password','toggleIcon1')" tabindex="-1" aria-label="Show password">
                            <i class="fa-regular fa-eye" id="toggleIcon1"></i>
                        </button>
                    </div>
                    <span class="field-hint">Minimum 8 characters</span>
                </div>

                <div class="input-wrapper full-width">
                    <label for="confirm_password">Confirm New Password<span>*</span></label>
                    <div class="password-field-wrap">
                        <input type="password" id="confirm_password" name="confirm_password" required minlength="8" placeholder="••••••••">
                        <button type="button" class="toggle-visibility" onclick="toggleField('confirm_password','toggleIcon2')" tabindex="-1" aria-label="Show password">
                            <i class="fa-regular fa-eye" id="toggleIcon2"></i>
                        </button>
                    </div>
                </div>

                <div class="full-width">
                    <button type="submit" class="submit-btn" id="submitBtn">
                        <span id="btnLabel">Reset Password</span>
                        <i class="fa-solid fa-spinner fa-spin" id="btnSpinner" style="display:none;"></i>
                    </button>
                </div>
            </form>
        <?php else: ?>
            <div class="footer-note" style="margin-top: 0;">
                <a href="login.php">Back to Sign In</a>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function toggleField(inputId, iconId) {
            const field = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            const isHidden = field.type === 'password';
            field.type = isHidden ? 'text' : 'password';
            icon.classList.toggle('fa-eye', !isHidden);
            icon.classList.toggle('fa-eye-slash', isHidden);
        }

        const resetForm = document.getElementById('resetForm');
        if (resetForm) {
            resetForm.addEventListener('submit', function () {
                document.getElementById('btnLabel').style.display = 'none';
                document.getElementById('btnSpinner').style.display = 'inline-block';
                document.getElementById('submitBtn').setAttribute('disabled', 'disabled');
            });
        }
    </script>

</body>
</html>