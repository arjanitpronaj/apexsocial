<?php
require_once '../includes/config.php';
requireLogin();
$me = getCurrentUser($pdo);
if (!$me) {
    session_unset();
    session_destroy();
    redirect(BASE_URL . '/pages/login.php');
}

$friends = $pdo->prepare("
    SELECT u.* FROM friendships f
    JOIN users u ON u.id = CASE WHEN f.sender_id=? THEN f.receiver_id ELSE f.sender_id END
    WHERE (f.sender_id=? OR f.receiver_id=?) AND f.status='accepted' AND u.is_blocked=0
");
$friends->execute([$me['id'], $me['id'], $me['id']]);
$friends = $friends->fetchAll();

$pending = $pdo->prepare("
    SELECT u.*, f.id AS friendship_id FROM friendships f
    JOIN users u ON u.id = f.sender_id
    WHERE f.receiver_id=? AND f.status='pending'
");
$pending->execute([$me['id']]);
$pending = $pending->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Friends — ApexSocial</title>
    <link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css?v=loop202605">
</head>
<body>
<?php $nav_active = 'friends'; require APEX_ROOT . '/includes/navbar.php'; ?>

<div class="feed-wrap page-shell shell-animate">
    <h1 class="page-title">Friends</h1>

    <?php if (!empty($pending)): ?>
    <div class="card" style="margin-bottom:20px">
        <div class="list-row" style="font-weight:500">
            Pending Requests (<?= count($pending) ?>)
        </div>
        <?php foreach ($pending as $p): ?>
        <div class="list-row">
            <?= avatarHtml($p, 42, 14) ?>
            <div class="list-grow">
                <div style="font-weight:500;font-size:14px"><?= htmlspecialchars($p['full_name'] ?: $p['username']) ?></div>
                <div class="meta-sub">@<?= htmlspecialchars($p['username']) ?></div>
            </div>
            <button class="btn btn-primary btn-sm" onclick="respondFriend(<?= $p['id'] ?>, 'accept', this)">Accept</button>
            <button class="btn btn-ghost btn-sm" onclick="respondFriend(<?= $p['id'] ?>, 'reject', this)">Decline</button>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="list-row" style="font-weight:500">
            My Friends (<?= count($friends) ?>)
        </div>
        <?php if (empty($friends)): ?>
            <div class="list-empty">
                <div class="list-empty-icon">👥</div>
                <p>No friends yet. Go say hi to someone!</p>
            </div>
        <?php else: foreach ($friends as $f): ?>
        <a href="profile.php?user=<?= urlencode($f['username']) ?>" class="friend-row-link">
            <?= avatarHtml($f, 42, 14) ?>
            <div class="list-grow">
                <div style="font-weight:500;font-size:14px"><?= htmlspecialchars($f['full_name'] ?: $f['username']) ?></div>
                <div class="meta-sub">@<?= htmlspecialchars($f['username']) ?></div>
            </div>
        </a>
        <?php endforeach; endif; ?>
    </div>
</div>

<script>
async function respondFriend(senderId, response, btn) {
    const fd = new FormData();
    fd.append('action','respond_friend');
    fd.append('sender_id', senderId);
    fd.append('response', response);
    const r = await fetch('../includes/ajax.php', {method:'POST', body:fd});
    const d = await r.json();
    if (d.success) location.reload();
}
</script>
<script src="../assets/js/app.js?v=loop202605"></script>
</body>
</html>
