<?php
require_once '../includes/config.php';
requireAdmin();
$PAGE = 'posts';
require_once 'inc_sidebar.php';

$status = $_GET['status'] ?? 'all';
$where  = $status === 'all' ? '' : "WHERE p.status = " . $pdo->quote($status);

$totalPosts     = (int)$pdo->query("SELECT COUNT(*) FROM posts")->fetchColumn();
$approvedPosts  = (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE status='approved'")->fetchColumn();
$pendingPosts   = (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE status='pending'")->fetchColumn();
$rejectedPosts  = (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE status='rejected'")->fetchColumn();

$posts = $pdo->query("
    SELECT p.*, u.username, u.full_name, u.avatar_color, u.is_blocked AS user_blocked
    FROM posts p
    JOIN users u ON p.user_id = u.id
    $where
    ORDER BY p.created_at DESC
    LIMIT 100
")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $pid = (int)$_POST['delete_id'];
    $pdo->prepare("DELETE FROM posts WHERE id=?")->execute([$pid]);
    redirect('all_posts.php?status='.$status);
}

echo adminHead('All Posts', false);
echo adminSidebar();
?>
<main class="main">

<div class="ph">
    <div class="ph-left">
        <h1>All Posts</h1>
        <p>Manage all content on the platform · <?= $totalPosts ?> total</p>
    </div>
</div>

<div class="sg stat-4">
    <div class="sc">
        <div class="sc-accent sc-violet"></div>
        <div class="sc-icon sc-violet">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg>
        </div>
        <div class="sc-val"><?= $totalPosts ?></div>
        <div class="sc-lbl">Total Posts</div>
    </div>
    <div class="sc">
        <div class="sc-accent sc-green"></div>
        <div class="sc-icon sc-green">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <div class="sc-val"><?= $approvedPosts ?></div>
        <div class="sc-lbl">Approved</div>
    </div>
    <div class="sc">
        <div class="sc-accent sc-amber"></div>
        <div class="sc-icon sc-amber">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        </div>
        <div class="sc-val"><?= $pendingPosts ?></div>
        <div class="sc-lbl">Pending</div>
    </div>
    <div class="sc">
        <div class="sc-accent sc-red"></div>
        <div class="sc-icon sc-red">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </div>
        <div class="sc-val"><?= $rejectedPosts ?></div>
        <div class="sc-lbl">Rejected</div>
    </div>
</div>

<div class="row-wrap mb-16" style="gap:6px">
    <a href="?status=all"      class="btn <?= $status === 'all'      ? 'btn-primary' : 'btn-ghost' ?> btn-sm">All</a>
    <a href="?status=approved" class="btn <?= $status === 'approved' ? 'btn-primary' : 'btn-ghost' ?> btn-sm">Approved</a>
    <a href="?status=pending"  class="btn <?= $status === 'pending'  ? 'btn-primary' : 'btn-ghost' ?> btn-sm">Pending</a>
    <a href="?status=rejected" class="btn <?= $status === 'rejected' ? 'btn-primary' : 'btn-ghost' ?> btn-sm">Rejected</a>
</div>

<div class="card card-clean">
    <?php if (empty($posts)): ?>
        <div class="empty-copy">No posts found.</div>
    <?php else: ?>
    <table class="tbl">
        <thead>
            <tr>
                <th>ID</th>
                <th>Author</th>
                <th>Content</th>
                <th>Status</th>
                <th>ML Score</th>
                <th>Date</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($posts as $p): ?>
        <tr>
            <td><strong>#<?= $p['id'] ?></strong></td>
            <td>
                <div class="row-start" style="gap:8px">
                    <div class="av av-28" style="background:<?= $p['avatar_color'] ?>"><?= strtoupper(substr($p['username'], 0, 1)) ?></div>
                    <div>
                        <div style="font-weight:600;font-size:13px">@<?= htmlspecialchars($p['username']) ?></div>
                        <?php if ($p['user_blocked']): ?>
                            <span class="badge badge-r" style="font-size:9px">BANNED</span>
                        <?php endif; ?>
                    </div>
                </div>
            </td>
            <td style="max-width:320px">
                <div style="font-size:13px;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                    <?= htmlspecialchars(mb_substr($p['content'], 0, 80)) ?><?= mb_strlen($p['content']) > 80 ? '…' : '' ?>
                </div>
            </td>
            <td><?= statusBadge($p['status']) ?></td>
            <td>
                <?php if ($p['ml_prob'] !== null): ?>
                    <span class="<?= $p['ml_label'] == 1 ? 'risk-high' : 'risk-safe' ?>" style="font-size:12px">
                        <?= round($p['ml_prob'], 1) ?>%
                    </span>
                    <?php if ($p['ml_category']): ?>
                        <div class="muted-xs" style="margin-top:2px"><?= htmlspecialchars($p['ml_category']) ?></div>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="muted-xs">—</span>
                <?php endif; ?>
            </td>
            <td class="muted-sm"><?= timeAgo($p['created_at']) ?></td>
            <td>
                <form method="POST" onsubmit="return confirm('Delete this post?')">
                    <input type="hidden" name="delete_id" value="<?= $p['id'] ?>">
                    <button type="submit" class="btn btn-danger btn-sm" style="padding:5px 10px">Delete</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

</main>
<?php echo adminFoot(); ?>
