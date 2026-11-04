<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';
session_start();

$error = '';
$message = '';
$usernameValue = '';
$emailValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    $usernameValue = $username;
    $emailValue = $email;

    if ($username === '' || $email === '' || $password === '' || $confirm === '') {
        $error = 'Please fill in all fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif (strlen($username) > 100 || strlen($email) > 254) {
        $error = 'Your username or email address is too long.';
    } elseif (strlen($password) < 8) {
        $error = 'Use a password with at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $stmt = $pdo->prepare('SELECT username, email FROM users WHERE username = ? OR email = ? LIMIT 1');
        $stmt->execute([$username, $email]);
        $existing = $stmt->fetch();

        if ($existing && $existing['username'] === $username) {
            $error = 'That username is already taken.';
        } elseif ($existing) {
            $error = 'An account already uses that email address.';
        } else {
            $verificationCode = (string)random_int(100000, 999999);
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO users (username, email, password, role, email_verified_at, email_verification_code_hash, email_verification_attempts, email_verification_expires_at) VALUES (?, ?, ?, 'user', NULL, ?, 0, DATE_ADD(NOW(), INTERVAL 10 MINUTE))");
                $stmt->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), password_hash($verificationCode, PASSWORD_DEFAULT)]);
                createAdminNotification($pdo, 'account', 'New account created: ' . $username, 'admin.php');
                $pdo->commit();

                try {
                    sendEmailVerificationCode($email, $username, $verificationCode);
                    $_SESSION['pending_verification_email'] = $email;
                    header('Location: verify_email.php?email=' . rawurlencode($email));
                    exit;
                } catch (RuntimeException $mailError) {
                    $error = 'Your account was created, but the verification email could not be sent. Check the Gmail settings, then request another code.';
                    error_log($mailError->getMessage());
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Account registration failed: ' . $e->getMessage());
                $error = 'We could not create your account right now. Please try again.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Register | AJN Printing</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="auth-body">
    <main class="card-wrapper">
        <section class="main-card">
            <div class="left-hero">
                <div class="brand">
                    <a class="brand-home" href="index.php" aria-label="Back to AJN T-Shirts and Prints home"><img class="brand-logo" src="images/ajn-logo.jpg" alt="AJN Printing logo"></a>
                    <span class="auth-brand-name">AJN PRINTING</span>
                </div>
                <p class="hero-copy">New to the shop? Set your plate once, pull fresh prints anytime after.</p>
            </div>
            <section class="right-form-panel">
                <header class="form-header">
                    <p class="form-kicker">Join the Press</p>
                    <h1>Register</h1>
                    <p>Create your account to get started</p>
                </header>
                <?php if ($error): ?><div class="alert warning"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                <?php if ($message): ?><div class="alert success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
                <form method="post" autocomplete="on">
                    <label class="input-pill"><input name="username" placeholder="Username" autocomplete="username" maxlength="100" value="<?= htmlspecialchars($usernameValue, ENT_QUOTES) ?>" required></label>
                    <label class="input-pill"><input type="email" name="email" placeholder="Email" autocomplete="email" maxlength="254" value="<?= htmlspecialchars($emailValue, ENT_QUOTES) ?>" required></label>
                    <label class="input-pill"><input type="password" name="password" placeholder="Password" autocomplete="new-password" minlength="8" required></label>
                    <label class="input-pill"><input type="password" name="confirm_password" placeholder="Confirm Password" autocomplete="new-password" minlength="8" required></label>
                    <button class="submit-btn" type="submit">Create Account</button>
                </form>
                <?php if ($error && str_contains($error, 'verification email')): ?>
                    <div class="mobile-switch"><a href="verify_email.php">Request a verification code</a></div>
                <?php endif; ?>
                <div class="mobile-switch">Already registered? <a href="login.php">Sign in</a></div>
            </section>
        </section>
    </main>
    <script src="ui.js"></script>
</body>
</html>
