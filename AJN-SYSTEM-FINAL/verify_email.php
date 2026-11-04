<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';
session_start();

$error = '';
$message = '';
$isLoginChallenge = isset($_SESSION['pending_login_user_id']);
$pendingLoginId = $isLoginChallenge ? (int)$_SESSION['pending_login_user_id'] : 0;
$pendingLoginEmail = $isLoginChallenge ? strtolower((string)($_SESSION['pending_login_email'] ?? '')) : '';
$email = $isLoginChallenge
    ? $pendingLoginEmail
    : strtolower(trim((string)($_GET['email'] ?? $_SESSION['pending_verification_email'] ?? '')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $isLoginChallenge
        ? $pendingLoginEmail
        : strtolower(trim((string)($_POST['email'] ?? '')));
    $action = (string)($_POST['action'] ?? 'verify');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter the email address used for this account.';
    } elseif ($action === 'resend') {
        if ($isLoginChallenge) {
            $stmt = $pdo->prepare('SELECT id, username, email, email_verified_at FROM users WHERE id = ? AND email = ? LIMIT 1');
            $stmt->execute([$pendingLoginId, $email]);
        } else {
            $stmt = $pdo->prepare('SELECT id, username, email, email_verified_at FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
        }
        $account = $stmt->fetch();

        if (!$account || (!empty($account['email_verified_at']) && !$isLoginChallenge)) {
            $message = 'If this email belongs to an account that still needs verification, a new code has been sent.';
        } else {
            $code = (string)random_int(100000, 999999);
            $stmt = $pdo->prepare('UPDATE users SET email_verification_code_hash = ?, email_verification_attempts = 0, email_verification_token_hash = NULL, email_verification_expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE id = ?');
            $stmt->execute([password_hash($code, PASSWORD_DEFAULT), (int)$account['id']]);
            try {
                sendEmailVerificationCode($email, (string)$account['username'], $code);
                $message = 'A new 6-digit verification code has been sent. It expires in 10 minutes.';
            } catch (RuntimeException $mailError) {
                error_log($mailError->getMessage());
                $error = 'The verification email could not be sent. Check the Gmail SMTP settings and try again.';
            }
        }
    } else {
        $code = trim((string)($_POST['code'] ?? ''));
        if (!preg_match('/^\d{6}$/', $code)) {
            $error = 'Enter the 6-digit code from your email.';
        } else {
            if ($isLoginChallenge) {
                $stmt = $pdo->prepare('SELECT id, username, role, email, email_verified_at, email_verification_code_hash, email_verification_attempts, (email_verification_expires_at > NOW()) AS code_is_valid FROM users WHERE id = ? AND email = ? LIMIT 1');
                $stmt->execute([$pendingLoginId, $email]);
            } else {
                $stmt = $pdo->prepare('SELECT id, username, role, email, email_verified_at, email_verification_code_hash, email_verification_attempts, (email_verification_expires_at > NOW()) AS code_is_valid FROM users WHERE email = ? LIMIT 1');
                $stmt->execute([$email]);
            }
            $account = $stmt->fetch();

            if (!$account) {
                $error = 'We could not verify this account. Check the email address or request a new code.';
            } elseif (!empty($account['email_verified_at']) && !$isLoginChallenge) {
                $error = 'This email is already verified. Sign in to request a fresh sign-in code.';
            } elseif ((int)$account['email_verification_attempts'] >= 5) {
                $error = 'Too many incorrect attempts. Request a new code to continue.';
            } elseif (empty($account['code_is_valid']) || empty($account['email_verification_code_hash'])) {
                $error = 'Your code has expired. Request a new code to continue.';
            } elseif (!password_verify($code, $account['email_verification_code_hash'])) {
                $pdo->prepare('UPDATE users SET email_verification_attempts = email_verification_attempts + 1 WHERE id = ?')->execute([(int)$account['id']]);
                $error = 'That code is incorrect. Check the email and try again.';
            } else {
                if (empty($account['email_verified_at'])) {
                    $stmt = $pdo->prepare('UPDATE users SET email_verified_at = NOW(), email_verification_code_hash = NULL, email_verification_attempts = 0, email_verification_token_hash = NULL, email_verification_expires_at = NULL WHERE id = ? AND email_verified_at IS NULL');
                    $stmt->execute([(int)$account['id']]);
                } else {
                    $stmt = $pdo->prepare('UPDATE users SET email_verification_code_hash = NULL, email_verification_attempts = 0, email_verification_token_hash = NULL, email_verification_expires_at = NULL WHERE id = ?');
                    $stmt->execute([(int)$account['id']]);
                }
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$account['id'];
                $_SESSION['role'] = $account['role'];
                unset($_SESSION['pending_verification_email'], $_SESSION['pending_login_user_id'], $_SESSION['pending_login_email'], $_SESSION['pending_login_role'], $_SESSION['pending_login_username']);
                header(($account['role'] ?? '') === 'admin' ? 'Location: admin.php' : 'Location: printing.php');
                exit;
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
    <title>Verify Email | AJN Printing</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="auth-body">
    <main class="card-wrapper solo-card">
        <section class="right-form-panel">
            <div class="brand">
                <a class="brand-home" href="index.php" aria-label="Back to AJN T-Shirts and Prints home"><img class="brand-logo" src="images/ajn-logo.jpg" alt="AJN Printing logo"></a>
            </div>
            <header class="form-header">
                <p class="form-kicker">Account Security</p>
                <h1>Verify Email</h1>
                <p><?= $isLoginChallenge ? 'Confirm your sign-in with the 6-digit code sent to your email.' : 'Enter the 6-digit code sent to your email.' ?> The code expires in 10 minutes.</p>
            </header>
            <?php if ($message): ?><div class="alert success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert warning"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <form method="post" autocomplete="on">
                <input type="hidden" name="action" value="verify">
                <?php if ($isLoginChallenge): ?>
                    <p class="verification-email-address"><?= htmlspecialchars($email, ENT_QUOTES) ?></p>
                    <input type="hidden" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES) ?>">
                <?php else: ?>
                    <label class="input-pill"><input type="email" name="email" placeholder="Email address" autocomplete="email" maxlength="254" value="<?= htmlspecialchars($email, ENT_QUOTES) ?>" required></label>
                <?php endif; ?>
                <label class="input-pill"><input type="text" name="code" placeholder="6-digit verification code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></label>
                <button class="submit-btn" type="submit">Verify and continue</button>
            </form>
            <form method="post" class="verification-resend-form">
                <input type="hidden" name="action" value="resend">
                <input type="hidden" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES) ?>">
                <button class="auth-home-link" type="submit">Send a new code</button>
            </form>
            <div class="mobile-switch" style="display:block"><a href="login.php">Back to Sign In</a></div>
        </section>
    </main>
</body>
</html>
