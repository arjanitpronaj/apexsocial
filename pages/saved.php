<?php
require_once '../includes/config.php';
requireLogin();
$me = getCurrentUser($pdo);
if (!$me) {
    session_unset();
    session_destroy();
    redirect(BASE_URL . '/pages/login.php');
}

$nav_active = 'saved';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Saved — ApexSocial</title>
    <link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css?v=loop202605">
</head>
<body>
<?php require APEX_ROOT . '/includes/navbar.php'; ?>

<div class="feed-wrap page-shell shell-animate">
    <h1 class="page-title">Saved</h1>
    <div class="card" style="padding:24px">
        <p class="meta-line" style="margin-bottom:12px">Bookmarked posts are not stored in this build yet. Your feed and profile still hold all your published content.</p>
        <a href="<?= BASE_URL ?>/index.php" class="btn btn-primary btn-sm">Back to Feed</a>
        <a href="<?= BASE_URL ?>/pages/profile.php" class="btn btn-ghost btn-sm" style="margin-left:8px">View my profile</a>
    </div>
</div>

<script src="../assets/js/app.js?v=loop202605"></script>
</body>
</html>
