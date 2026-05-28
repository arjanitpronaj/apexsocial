<?php
require_once '../includes/config.php';
requireAdmin();
$PAGE = 'activity';
require_once 'inc_sidebar.php';

$tab = $_GET['tab'] ?? 'posts';

if ($tab === 'posts') {
    $items = $pdo->query("
        SELECT p.*, u.username, u.full_name, u.avatar_color
        FROM posts p
        JOIN users u ON p.user_id = u.id
        WHERE p.status = 'approved' AND u.is_blocked = 0
        ORDER BY p.created_at DESC
        LIMIT 50
    ")->fetchAll();
} elseif ($tab === 'comments') {
    $items = $pdo->query("
        SELECT c.*, u.username, u.avatar_color, p.content AS post_content, p.id AS parent_post_id
        FROM comments c
        JOIN users u ON c.user_id = u.id
        JOIN posts p ON c.post_id = p.id
        WHERE c.status = 'approved' AND u.is_blocked = 0
        ORDER BY c.created_at DESC
        LIMIT 50
    ")->fetchAll();
} elseif ($tab === 'users') {
    $items = $pdo->query("
        SELECT id, username, full_name, avatar_color, is_blocked, is_admin, created_at
        FROM users
        ORDER BY created_at DESC
        LIMIT 50
    ")->fetchAll();
} else { // ml
    $items = $pdo->query("
        SELECT ca.*, u.username, u.avatar_color
        FROM content_analysis ca
        JOIN users u ON ca.user_id = u.id
        ORDER BY ca.created_at DESC
        LIMIT 50
    ")->fetchAll();
}

echo adminHead('Activity Log', false);
echo adminSidebar();
?>
<main class="main">

<div class="ph">
    <div class="ph-left">
        <h1>Activity Log</h1>
        <p>Recent platform activity across all content types</p>
    </div>
</div>

<div class="row-wrap mb-16" style="gap:6px">
    <a href="?tab=posts"    class="btn <?= $tab === 'posts'    ? 'btn-primary' : 'btn-ghost' ?> btn-sm">Posts</a>
    <a href="?tab=comments" class="btn <?= $tab === 'comments' ? 'btn-primary' : 'btn-ghost' ?> btn-sm">Comments</a>
    <a href="?tab=users"    class="btn <?= $tab === 'users'    ? 'btn-primary' : 'btn-ghost' ?> btn-sm">New Users</a>
    <a href="?tab=ml"       class="btn <?= $tab === 'ml'       ? 'btn-primary' : 'btn-ghost' ?> btn-sm">ML Analyses</a>
</div>

<?php if (empty($items)): ?>

<div class="empty-state">
    <div class="icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
    </div>
    <h3>No activity yet</h3>
    <p>Activity will appear here as users interact with the platform.</p>
</div>

<?php elseif ($tab === 'posts'): ?>

<?php foreach ($items as $p): ?>
<div class="card mb-12">
    <div class="card-body">
        <div class="row-start mb-12" style="gap:12px">
            <div class="av av-38" style="background:<?= $p['avatar_color'] ?>"><?= strtoupper(substr($p['username'], 0, 1)) ?></div>
            <div class="grow">
                <div style="font-weight:700;font-size:13px"><?= htmlspecialchars($p['full_name'] ?: $p['username']) ?></div>
                <div class="muted-xs">@<?= htmlspecialchars($p['username']) ?> · <?= timeAgo($p['created_at']) ?></div>
            </div>
            <?= statusBadge($p['status']) ?>
        </div>
        <div style="font-size:14px;color:var(--text);line-height:1.6"><?= nl2br(htmlspecialchars($p['content'])) ?></div>
    </div>
</div>
<?php endforeach; ?>

<?php elseif ($tab === 'comments'): ?>

<div class="card card-clean">
    <table class="tbl">
        <thead><tr><th>User</th><th>Comment</th><th>On Post</th><th>Time</th></tr></thead>
        <tbody>
        <?php foreach ($items as $c): ?>
        <tr>
            <td>
                <div class="row-start" style="gap:8px">
                    <div class="av av-28" style="background:<?= $c['avatar_color'] ?>"><?= strtoupper(substr($c['username'], 0, 1)) ?></div>
                    <strong>@<?= htmlspecialchars($c['username']) ?></strong>
                </div>
            </td>
            <td style="max-width:280px">
                <div class="tbl-truncate" style="font-size:13px;color:var(--text)"><?= htmlspecialchars(mb_substr($c['content'], 0, 80)) ?></div>
            </td>
            <td style="max-width:200px">
                <div class="tbl-truncate">#<?= $c['parent_post_id'] ?>: <?= htmlspecialchars(mb_substr($c['post_content'], 0, 40)) ?></div>
            </td>
            <td class="muted-sm"><?= timeAgo($c['created_at']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php elseif ($tab === 'users'): ?>

<div class="card card-clean">
    <table class="tbl">
        <thead><tr><th>User</th><th>Role</th><th>Status</th><th>Joined</th></tr></thead>
        <tbody>
        <?php foreach ($items as $u): ?>
        <tr>
            <td>
                <div class="row-start">
                    <div class="av av-32" style="background:<?= $u['avatar_color'] ?>"><?= strtoupper(substr($u['username'], 0, 1)) ?></div>
                    <div>
                        <div style="font-weight:600;font-size:13px"><?= htmlspecialchars($u['full_name'] ?: $u['username']) ?></div>
                        <div class="muted-xs">@<?= htmlspecialchars($u['username']) ?></div>
                    </div>
                </div>
            </td>
            <td><?php if ($u['is_admin']): ?><span class="badge badge-p">Admin</span><?php else: ?><span class="badge badge-b">User</span><?php endif; ?></td>
            <td><?php if ($u['is_blocked']): ?><span class="badge badge-r">Banned</span><?php else: ?><span class="badge badge-g">Active</span><?php endif; ?></td>
            <td class="muted-sm"><?= timeAgo($u['created_at']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php else: // ml ?>

<div class="card card-clean">
    <table class="tbl">
        <thead><tr><th>User</th><th>Type</th><th>Text</th><th>Result</th><th>Confidence</th><th>Time</th></tr></thead>
        <tbody>
        <?php foreach ($items as $a): ?>
        <tr>
            <td><strong>@<?= htmlspecialchars($a['username']) ?></strong></td>
            <td><span class="badge badge-b"><?= $a['content_type'] ?></span></td>
            <td style="max-width:280px">
                <div class="tbl-truncate"><?= htmlspecialchars(mb_substr($a['text_snapshot'], 0, 80)) ?></div>
            </td>
            <td>
                <?php if ($a['label'] == 1): ?>
                    <span class="badge badge-r"><?= htmlspecialchars($a['category']) ?></span>
                <?php else: ?>
                    <span class="badge badge-g">safe</span>
                <?php endif; ?>
            </td>
            <td><span class="mono-sm"><?= round($a['confidence'], 1) ?>%</span></td>
            <td class="muted-sm"><?= timeAgo($a['created_at']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php endif; ?>

</main>
<?php echo adminFoot(); ?>
