<?php
require_once '../includes/config.php';
$reason = '';
if (isset($_GET['r'])) {
    $reason = base64_decode($_GET['r']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Account Banned — ApexSocial</title>
    <link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css?v=loop202605">
</head>
<body>
<div class="auth-wrap">
    <div class="auth-card auth-center">
        <div class="auth-logo-icon" style="background:var(--redd);border-color:#f3b9b9;color:var(--red)">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"/>
                <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>
            </svg>
        </div>
        <div class="auth-title">Account Banned</div>
        <div class="auth-sub">Your account has been suspended</div>

        <?php if ($reason): ?>
        <div class="alert alert-error" style="text-align:left">
            <strong>Reason:</strong><br>
            <?= htmlspecialchars($reason) ?>
        </div>
        <?php endif; ?>

        <p class="auth-copy">
            If you believe this is a mistake, please contact support.
        </p>

        <a href="login.php" class="btn btn-ghost btn-full">Back to Login</a>
    </div>
</div>
</body>
</html>
