<?php
require_once '../includes/config.php';
if (isLoggedIn()) redirect(BASE_URL.'/index.php');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username  = trim($_POST['username'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $password  = $_POST['password'] ?? '';
    $full_name = trim($_POST['full_name'] ?? '');

    if (!$username || !$email || !$password || !$full_name) {
        $error = 'Please fill in all fields.';
    } elseif (strlen($username) < 3) {
        $error = 'Username must be at least 3 characters.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } else {
        $check = $pdo->prepare("SELECT id FROM users WHERE username=? OR email=?");
        $check->execute([$username, $email]);
        if ($check->fetch()) {
            $error = 'Username or email already exists.';
        } else {
            $colors = ['#4f46e5','#8b5cf6','#ec4899','#10b981','#f59e0b','#3b82f6'];
            $color  = $colors[array_rand($colors)];
            // Plain text password per requirement
            $pdo->prepare("INSERT INTO users (username,email,password,full_name,avatar_color) VALUES (?,?,?,?,?)")
                ->execute([$username, $email, $password, $full_name, $color]);
            $uid = $pdo->lastInsertId();
            $_SESSION['user_id']    = $uid;
            $_SESSION['role']       = 'user';
            $_SESSION['login_time'] = time();
            redirect(BASE_URL.'/index.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Create Account — ApexSocial</title>
    <link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css?v=loop202605">
</head>
<body>
<div class="auth-wrap">
    <div class="auth-card">
        <div class="auth-logo-icon">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                <circle cx="9" cy="10" r="1" fill="currentColor" stroke="none"/>
                <circle cx="12" cy="10" r="1" fill="currentColor" stroke="none"/>
                <circle cx="15" cy="10" r="1" fill="currentColor" stroke="none"/>
            </svg>
        </div>
        <div class="auth-title">Create your account</div>
        <div class="auth-sub">Join ApexSocial and start sharing</div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label class="form-label">Full Name</label>
                <input type="text" name="full_name" class="form-input" placeholder="John Doe" required
                       value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Username</label>
                <input type="text" name="username" class="form-input" placeholder="johndoe" required
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-input" placeholder="you@example.com" required
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-input" placeholder="••••••••" required minlength="6">
            </div>
            <button type="submit" class="btn btn-primary btn-full btn-block-pad">
                Create Account
            </button>
        </form>

        <div class="auth-alt">
            Already have an account? <a href="login.php">Sign in</a>
        </div>
    </div>
</div>
</body>
</html>
