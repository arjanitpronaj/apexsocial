<?php
require_once '../includes/config.php';
requireLogin();
$me = getCurrentUser($pdo);
if (!$me) {
    session_unset();
    session_destroy();
    redirect(BASE_URL . '/pages/login.php');
}

$pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$me['id']]);

$notifs = $pdo->prepare("
    SELECT n.*, u.username, u.full_name, u.avatar_color, u.avatar
    FROM notifications n
    LEFT JOIN users u ON n.from_user_id = u.id
    WHERE n.user_id = ?
    ORDER BY n.created_at DESC
    LIMIT 50
");
$notifs->execute([$me['id']]);
$notifs = $notifs->fetchAll();

function notifText(array $n): string {
    $username = htmlspecialchars($n['username'] ?? 'system', ENT_QUOTES, 'UTF-8');
    if (($n['message'] ?? '') === 'repost' && ($n['type'] ?? '') === 'comment') {
        return '@' . $username . ' reposted your post';
    }
    $type = $n['type'] ?? '';
    return match ($type) {
        'friend_request'    => '@' . $username . ' sent you a friend request',
        'friend_accepted'   => '@' . $username . ' accepted your friend request',
        'like'              => '@' . $username . ' liked your post',
        'comment'           => '@' . $username . ' commented on your post',
        'post_approved'     => 'Your post was approved',
        'post_rejected'     => 'Your post was rejected',
        'comment_approved'  => 'Your comment was approved',
        'comment_rejected'  => 'Your comment was rejected',
        default             => 'New notification',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Notifications — ApexSocial</title>
    <link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css?v=loop202605">
</head>
<body>
<?php $nav_active = 'messages'; require APEX_ROOT . '/includes/navbar.php'; ?>

<div class="feed-wrap page-shell shell-animate">
    <h1 class="page-title">Notifications</h1>

    <div class="card">
        <?php if (empty($notifs)): ?>
            <div class="list-empty">
                <div class="list-empty-icon">🔔</div>
                <p>No notifications yet</p>
            </div>
        <?php else: foreach ($notifs as $n): ?>
        <div class="notif-row">
            <?= avatarHtml($n, 38, 13) ?>
            <div class="list-grow">
                <div class="notif-text"><?= notifText($n) ?></div>
                <div class="notif-time"><?= timeAgo($n['created_at']) ?></div>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>
</div>
<script src="../assets/js/app.js?v=loop202605"></script>
</body>
</html>
