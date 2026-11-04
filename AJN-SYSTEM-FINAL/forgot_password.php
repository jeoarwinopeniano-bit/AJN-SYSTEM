<?php
require_once 'db.php';
$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    if ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 6) {
        $error = 'Use a password with at least 6 characters.';
    } else {
        $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE username = ?');
        $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $username]);
        if ($stmt->rowCount()) $message = 'Password updated successfully.';
        else $error = 'We could not find that username.';
    }
}
?>
<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Forgot Password | AJN Printing</title>
        <link rel="stylesheet" href="css/style.css">
    </head>
    <body class="auth-body">
        <main class="card-wrapper solo-card">
            <section class="right-form-panel">
                <div class="brand">
                    <a class="brand-home" href="login.php"><img class="brand-logo" src="images/ajn-logo.jpg" alt="AJN Printing logo"></a>
                </div>
                <header class="form-header">
                    <h1>Forgot Password</h1>
                    <p>Reset your account credentials</p>
                </header>
                <?php if ($message): ?>
                    <div class="alert success">
                        <?= htmlspecialchars($message) ?>
                    </div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert warning">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
                <form method="post">
                    <label class="input-pill">
                        <input name="username" placeholder="Username" autocomplete="username" required>
                    </label>
                    <label class="input-pill">
                        <input type="password" name="new_password" placeholder="New Password" autocomplete="new-password" required>
                    </label>
                    <label class="input-pill">
                        <input type="password" name="confirm_password" placeholder="Confirm New Password" autocomplete="new-password" required>
                    </label>
                    <button class="submit-btn" type="submit">Reset Password</button>
                </form>
                <div class="mobile-switch" style="display:block">
                    <a href="login.php">Back to Login</a>
                </div>
            </section>
        </main>
        <script src="ui.js"></script>
    </body>
</html>
