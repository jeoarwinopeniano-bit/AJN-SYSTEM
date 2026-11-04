<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';
session_start();

$error = '';
$notice = '';

if (isset($_SESSION['user_id'])) {
    $sessionStmt = $pdo->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
    $sessionStmt->execute([(int)$_SESSION['user_id']]);
    $sessionAccount = $sessionStmt->fetch();
    if ($sessionAccount) {
        header(($sessionAccount['role'] ?? '') === 'admin' ? 'Location: admin.php' : 'Location: printing.php');
        exit;
    }
    unset($_SESSION['user_id'], $_SESSION['role']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $stmt = $pdo->prepare('SELECT id, username, password, role, email FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, (string)$user['password'])) {
        $error = 'Invalid username or password.';
    } elseif (empty($user['email']) || !filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'This account needs a valid email address before it can sign in. Contact the site administrator.';
    } else {
        $code = (string)random_int(100000, 999999);
        $save = $pdo->prepare('UPDATE users SET email_verification_code_hash = ?, email_verification_attempts = 0, email_verification_expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE id = ?');
        $save->execute([password_hash($code, PASSWORD_DEFAULT), (int)$user['id']]);

        session_regenerate_id(true);
        unset($_SESSION['user_id'], $_SESSION['role']);
        $_SESSION['pending_login_user_id'] = (int)$user['id'];
        $_SESSION['pending_login_email'] = (string)$user['email'];
        $_SESSION['pending_login_role'] = (string)$user['role'];
        $_SESSION['pending_login_username'] = (string)$user['username'];

        try {
            sendEmailVerificationCode((string)$user['email'], (string)$user['username'], $code);
            header('Location: verify_email.php?email=' . rawurlencode((string)$user['email']));
            exit;
        } catch (RuntimeException $mailError) {
            error_log($mailError->getMessage());
            $error = 'We could not send your sign-in code. Check the Gmail SMTP settings, then use the link below to try again.';
        }
    }
}
?>

<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Sign In | AJN Printing</title>
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
                    <p class="hero-copy">Every great print starts with the right plate. Sign in to run yours.</p>
                    <nav class="mode-switch-buttons">
                        <a class="toggle-btn active" href="login.php">Login</a>
                        <a class="toggle-btn" href="register.php">Register</a>
                    </nav>
                </div>
                <section class="right-form-panel">
                    <header class="form-header">
                        <p class="form-kicker">Welcome Back</p>
                        <h1>Sign In</h1>
                        <p>Sign in to your account.</p>
                    </header>
                    <?php if ($notice): ?><div class="alert success"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
                    <?php if ($error): ?><div class="alert warning"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                    <?php if (isset($_SESSION['pending_login_user_id'])): ?>
                        <div class="mobile-switch"><a href="verify_email.php?email=<?= rawurlencode((string)$_SESSION['pending_login_email']) ?>">Continue to email code / resend code</a></div>
                    <?php endif; ?>
                    <form method="post">
                        <label class="input-pill">
                            <input name="username" placeholder="Username" autocomplete="username" required>
                        </label>
                        <label class="input-pill">
                            <input type="password" name="password" placeholder="Password" autocomplete="current-password" required>
                        </label>
                        <div class="forgot-pass">
                            <a href="forgot_password.php" data-confirm="Are you sure you want to go to forgot password?">Forgot Password?</a>
                        </div>
                        <button class="submit-btn" type="submit">Submit</button>
                    </form>
                    <div class="mobile-switch">
                        New to the shop?
                        <a href="register.php">Register</a>
                    </div>
                </section>
            </section>
        </main>
        <script src="ui.js"></script>
    </body>
</html>
