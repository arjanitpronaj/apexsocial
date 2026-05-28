<?php
require_once '../includes/config.php';
requireAdmin();
$PAGE = 'harmful';
require_once 'inc_sidebar.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_reviewed'])) {
    $pdo->prepare("UPDATE content_analysis SET reviewed_by_admin=1 WHERE id=?")->execute([(int)$_POST['mark_reviewed']]);
    redirect('harmful.php');
}

$items = $pdo->query("
    SELECT ca.*, u.username, u.avatar_color
    FROM content_analysis ca
    JOIN users u ON ca.user_id = u.id
    WHERE ca.label = 1
    ORDER BY ca.reviewed_by_admin ASC, ca.created_at DESC
    LIMIT 100
")->fetchAll();

echo adminHead('Harmful Detected', false);
echo adminSidebar();
?>
<main class="main">

<div class="ph">
    <div class="ph-left">
        <h1>Harmful Content Detected</h1>
        <p>All content classified as harmful by the ML system</p>
    </div>
</div>

<?php if (empty($items)): ?>
<div class="empty-state">
    <div class="icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
    </div>
    <h3>No harmful content</h3>
    <p>The ML system hasn't flagged any harmful content yet.</p>
</div>
<?php else: ?>
<div class="card card-clean">
    <table class="tbl">
        <thead>
            <tr>
                <th>User</th>
                <th>Content</th>
                <th>Category</th>
                <th>Risk</th>
                <th>Time</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $h): ?>
        <tr style="<?= $h['reviewed_by_admin'] ? 'opacity:0.5' : '' ?>">
            <td>
                <div class="row-start" style="gap:8px">
                    <div class="av av-28" style="background:<?= $h['avatar_color'] ?>"><?= strtoupper(substr($h['username'], 0, 1)) ?></div>
                    <strong>@<?= htmlspecialchars($h['username']) ?></strong>
                </div>
            </td>
            <td style="max-width:320px">
                <div class="tbl-truncate"><?= htmlspecialchars(mb_substr($h['text_snapshot'], 0, 100)) ?></div>
            </td>
            <td><span class="badge badge-r"><?= htmlspecialchars($h['category']) ?></span></td>
            <td><span class="risk-high"><?= round($h['harmful_prob'], 1) ?>%</span></td>
            <td class="muted-sm"><?= timeAgo($h['created_at']) ?></td>
            <td>
                <?php if (!$h['reviewed_by_admin']): ?>
                <form method="POST">
                    <input type="hidden" name="mark_reviewed" value="<?= $h['id'] ?>">
                    <button type="submit" class="btn btn-ghost btn-sm">Mark Reviewed</button>
                </form>
                <?php else: ?>
                <span class="badge badge-g">Reviewed</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

</main>
<?php echo adminFoot(); ?>
