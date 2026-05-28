<?php
require_once '../includes/config.php';
if (isAdminLogged()) redirect(BASE_URL.'/admin/index.php');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';
    if ($u && $p) {
        $s = $pdo->prepare("SELECT * FROM users WHERE username=? AND is_admin=1");
        $s->execute([$u]);
        $user = $s->fetch();
        if ($user && $user['password'] === $p) {
            // Destroy any existing session (user or stale admin) before creating admin session
            session_unset();
            session_destroy();
            session_start();
            session_regenerate_id(true);
            $_SESSION['user_id']    = $user['id'];
            $_SESSION['role']       = 'admin';
            $_SESSION['login_time'] = time();
            redirect(BASE_URL.'/admin/index.php');
        } else {
            $error = 'Invalid admin credentials.';
        }
    } else {
        $error = 'Please fill in all fields.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Admin Login — ApexSocial</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css?v=20260503c">
</head>
<body>
<div class="auth-wrap">
    <div class="auth-card">
        <div class="auth-logo-icon">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
            </svg>
        </div>
        <div class="auth-title">ApexSocial Admin</div>
        <div class="auth-sub">Sign in to access the dashboard</div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label class="form-label">Username</label>
                <input type="text" name="username" class="form-input"
                       placeholder="admin" required autofocus>
            </div>
            <div class="form-group">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-input"
                       placeholder="••••••••" required>
            </div>
            <button type="submit" class="btn btn-primary btn-full" style="padding:13px;margin-top:6px">
                Enter Dashboard
            </button>
        </form>

        <div class="auth-alt">
            Regular user? <a href="../pages/login.php">User Login</a>
        </div>
    </div>
</div>
</body>
</html>
