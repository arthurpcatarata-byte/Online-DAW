<?php
require_once 'config.php';
if (isLoggedIn()) { header('Location: dashboard.php'); exit; }

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email']    ?? '');
    $password = $_POST['password']         ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (empty($username) || empty($email) || empty($password)) {
        $error = 'Please fill in all fields.';
    } elseif (strlen($username) < 3 || strlen($username) > 50) {
        $error = 'Username must be 3–50 characters.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $stmt = $pdo->prepare("SELECT user_id FROM `User` WHERE username = ? OR email = ? LIMIT 1");
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            $error = 'Username or email is already taken.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO `User` (username, email, password) VALUES (?, ?, ?)");
            $stmt->execute([$username, $email, $hash]);
            $success = 'Account created! You can now sign in.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register — CatarataDAW</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="auth-wrapper">
    <div class="auth-card">
        <div class="auth-header">
            <div class="auth-logo">🎵</div>
            <h1>CatarataDAW</h1>
            <p>Create your free account</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error">⚠️ <?= h($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success">✅ <?= h($success) ?> — <a href="login.php" style="color:inherit;font-weight:700;">Sign in</a></div>
        <?php endif; ?>

        <form method="POST" novalidate>
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username"
                       placeholder="Choose a username (3–50 chars)"
                       value="<?= h($_POST['username'] ?? '') ?>"
                       required minlength="3" maxlength="50">
            </div>
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email"
                       placeholder="your@email.com"
                       value="<?= h($_POST['email'] ?? '') ?>"
                       required>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password"
                       placeholder="At least 6 characters"
                       required minlength="6">
            </div>
            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <input type="password" id="confirm_password" name="confirm_password"
                       placeholder="Repeat your password"
                       required>
            </div>
            <button type="submit" class="btn btn-primary btn-full">Create Account →</button>
        </form>

        <div class="auth-link">
            Already have an account? <a href="login.php">Sign in</a>
        </div>
    </div>
</div>
</body>
</html>
