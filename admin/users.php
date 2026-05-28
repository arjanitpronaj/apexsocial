<?php
require_once '../includes/config.php';
requireAdmin();
$PAGE = 'users';
require_once 'inc_sidebar.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uid    = (int)($_POST['user_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($action === 'ban') {
        $reason = trim($_POST['reason'] ?? '') ?: 'Violation of community guidelines';
        $pdo->prepare("UPDATE users SET is_blocked=1, ban_reason=?, banned_at=NOW(), session_invalidated_at=NOW() WHERE id=? AND is_admin=0")
            ->execute([$reason, $uid]);
        apexUserBanned($uid, $reason);
    } elseif ($action === 'unban') {
        $pdo->prepare("UPDATE users SET is_blocked=0, ban_reason=NULL, banned_at=NULL WHERE id=?")->execute([$uid]);
    } elseif ($action === 'kick') {
        // Kick = invalidate session (force logout) without banning
        $pdo->prepare("UPDATE users SET session_invalidated_at=NOW() WHERE id=? AND is_admin=0")->execute([$uid]);
    }
    redirect('users.php');
}

$users = $pdo->query("
    SELECT u.*,
        (SELECT COUNT(*) FROM posts WHERE user_id=u.id AND status='approved') AS post_count,
        (SELECT COUNT(*) FROM content_analysis WHERE user_id=u.id AND label=1) AS flag_count
    FROM users u
    WHERE u.is_admin=0
    ORDER BY u.created_at DESC
")->fetchAll();

echo adminHead('Users', false);
echo adminSidebar();
?>
<main class="main">

<div class="ph">
    <div class="ph-left">
        <h1>Users</h1>
        <p><?= count($users) ?> registered users</p>
    </div>
</div>

<div class="card card-clean">
    <table class="tbl">
        <thead>
            <tr>
                <th>User</th>
                <th>Email</th>
                <th>Posts</th>
                <th>Flagged</th>
                <th>Status</th>
                <th>Joined</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
            <td>
                <div class="row-start">
                    <div class="av av-34" style="background:<?= $u['avatar_color'] ?>"><?= strtoupper(substr($u['username'], 0, 1)) ?></div>
                    <div>
                        <div style="font-weight:600;font-size:13px"><?= htmlspecialchars($u['full_name'] ?: $u['username']) ?></div>
                        <div class="muted-xs">@<?= htmlspecialchars($u['username']) ?></div>
                    </div>
                </div>
            </td>
            <td class="muted-sm"><?= htmlspecialchars($u['email']) ?></td>
            <td><strong><?= $u['post_count'] ?></strong></td>
            <td>
                <?php if ($u['flag_count'] > 0): ?>
                    <span class="risk-high"><?= $u['flag_count'] ?></span>
                <?php else: ?>
                    <span class="muted-sm">0</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($u['is_blocked']): ?>
                    <span class="badge badge-r">Banned</span>
                <?php else: ?>
                    <span class="badge badge-g">Active</span>
                <?php endif; ?>
            </td>
            <td class="muted-sm"><?= timeAgo($u['created_at']) ?></td>
            <td>
                <?php if ($u['is_blocked']): ?>
                <form method="POST">
                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                    <input type="hidden" name="action" value="unban">
                    <button type="submit" class="btn btn-success btn-sm">Unban</button>
                </form>
                <?php else: ?>
                <div class="row-wrap" style="gap:4px">
                    <form method="POST">
                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                        <input type="hidden" name="action" value="kick">
                        <button type="submit" class="btn btn-ghost btn-sm" title="Force logout this user" onclick="return confirm('Kick this user? They will be logged out.')">Kick</button>
                    </form>
                    <form method="POST" onsubmit="return confirm('Ban this user?')">
                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                        <input type="hidden" name="action" value="ban">
                        <button type="submit" class="btn btn-danger btn-sm">Ban</button>
                    </form>
                </div>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

</main>
<?php echo adminFoot(); ?>
