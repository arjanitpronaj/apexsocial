<?php
require_once '../includes/config.php';
if (isLoggedIn())    redirect(BASE_URL.'/index.php');
if (isAdminLogged()) redirect(BASE_URL.'/admin/index.php');

$error = '';
$kicked = isset($_GET['kicked']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';
    if ($u && $p) {
        $s = $pdo->prepare("SELECT * FROM users WHERE (username=? OR email=?) AND is_admin=0");
        $s->execute([$u, $u]);
        $user = $s->fetch();
        if ($user && $user['password'] === $p) {
            if ($user['is_blocked']) {
                redirect(BASE_URL.'/pages/banned.php?r='.urlencode(base64_encode($user['ban_reason'])));
            }
            $_SESSION['user_id']    = $user['id'];
            $_SESSION['role']       = 'user';
            $_SESSION['login_time'] = time();
            redirect(BASE_URL.'/index.php');
        } else {
            $error = 'Invalid username or password.';
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
    <title>Sign In — ApexSocial</title>
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
        <div class="auth-title">ApexSocial</div>
        <div class="auth-sub">Sign in to continue to your dashboard</div>

        <?php if ($kicked): ?>
            <div class="alert alert-warning">Signed out by administrator.</div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label class="form-label">Username or Email</label>
                <input type="text" name="username" class="form-input"
                       placeholder="Enter your username" required autofocus
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Password</label>
                <div class="pw-wrap">
                    <input type="password" name="password" id="pw" class="form-input has-eye"
                           placeholder="••••••••" required>
                    <button type="button" class="pw-eye" onclick="togglePw()" title="Show/hide password">
                        <svg id="eye-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-full btn-block-pad">
                Sign In
            </button>
        </form>

        <div class="auth-alt">
            Don't have an account? <a href="register.php">Sign up</a>
        </div>
    </div>
</div>

<script>
function togglePw() {
    const i = document.getElementById('pw');
    i.type = i.type === 'password' ? 'text' : 'password';
}
</script>
</body>
</html>
