<?php
require_once '../includes/config.php';
requireAdmin();
$PAGE = 'reports';
require_once 'inc_sidebar.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($id && in_array($action, ['ok','removed'])) {
        $newStatus = $action === 'ok' ? 'reviewed_ok' : 'reviewed_removed';
        $pdo->prepare("UPDATE reports SET status=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?")
            ->execute([$newStatus, $_SESSION['user_id'], $id]);

        // If removed, also delete the content
        if ($action === 'removed') {
            $r = $pdo->prepare("SELECT content_type, content_id FROM reports WHERE id=?");
            $r->execute([$id]); $rpt = $r->fetch();
            if ($rpt) {
                if ($rpt['content_type'] === 'post') {
                    $pdo->prepare("UPDATE posts SET status='rejected' WHERE id=?")->execute([$rpt['content_id']]);
                } elseif ($rpt['content_type'] === 'comment') {
                    $pdo->prepare("UPDATE comments SET status='rejected' WHERE id=?")->execute([$rpt['content_id']]);
                }
            }
        }
    }
    redirect('reports.php');
}

$reports = $pdo->query("
    SELECT r.*, u.username AS reporter_username, u.avatar_color
    FROM reports r
    JOIN users u ON r.reporter_id = u.id
    WHERE r.status = 'pending'
    ORDER BY r.created_at ASC
")->fetchAll();

echo adminHead('Reports', false);
echo adminSidebar();
?>
<main class="main">

<div class="ph">
    <div class="ph-left">
        <h1>User Reports</h1>
        <p><?= count($reports) ?> pending reports</p>
    </div>
</div>

<?php if (empty($reports)): ?>
<div class="empty-state">
    <div class="icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
    </div>
    <h3>No pending reports</h3>
    <p>All user reports have been reviewed.</p>
</div>
<?php else: foreach ($reports as $r): ?>
<div class="card mb-12">
    <div class="card-body">
        <div class="row-start mb-12" style="gap:12px">
            <div class="av av-34" style="background:<?= $r['avatar_color'] ?>"><?= strtoupper(substr($r['reporter_username'], 0, 1)) ?></div>
            <div class="grow">
                <div style="font-weight:700;font-size:13px">@<?= htmlspecialchars($r['reporter_username']) ?></div>
                <div class="muted-xs">reported a <?= $r['content_type'] ?> · <?= timeAgo($r['created_at']) ?></div>
            </div>
            <span class="badge badge-r"><?= htmlspecialchars($r['reason']) ?></span>
        </div>
        <?php if ($r['description']): ?>
        <div class="soft-panel mb-12"><?= htmlspecialchars($r['description']) ?></div>
        <?php endif; ?>
        <div class="split-actions">
            <form method="POST">
                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                <input type="hidden" name="action" value="ok">
                <button type="submit" class="btn btn-ghost btn-sm">Dismiss (Content OK)</button>
            </form>
            <form method="POST" onsubmit="return confirm('Remove this content?')">
                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                <input type="hidden" name="action" value="removed">
                <button type="submit" class="btn btn-danger btn-sm">Remove Content</button>
            </form>
        </div>
    </div>
</div>
<?php endforeach; endif; ?>

</main>
<?php echo adminFoot(); ?>
