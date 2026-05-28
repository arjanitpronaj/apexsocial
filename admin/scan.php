<?php
require_once '../includes/config.php';
requireAdmin();
$PAGE = 'scan'; // not in sidebar anymore but keeping for compatibility
require_once 'inc_sidebar.php';

$results = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['scan'])) {
    $type = $_POST['scan_type'] ?? 'posts';
    $limit = min(200, max(10, (int)($_POST['limit'] ?? 50)));

    if ($type === 'posts') {
        $rows = $pdo->prepare("SELECT id, content, ml_label, ml_prob FROM posts WHERE status='approved' ORDER BY created_at DESC LIMIT ?");
        $rows->bindValue(1, $limit, PDO::PARAM_INT);
    } else {
        $rows = $pdo->prepare("SELECT id, content, ml_label, ml_prob FROM comments WHERE status='approved' ORDER BY created_at DESC LIMIT ?");
        $rows->bindValue(1, $limit, PDO::PARAM_INT);
    }
    $rows->execute();
    $items = $rows->fetchAll();

    // Batch analyze via ML
    $batch = array_map(fn($r) => ['id' => $r['id'], 'text' => $r['content'], 'type' => $type], $items);

    $ch = curl_init(ML_API_URL . '/analyze_batch');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['items' => $batch]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    if ($resp) {
        $data = json_decode($resp, true);
        $results = $data['results'] ?? [];
    }
}

echo adminHead('Scan Content', false);
echo adminSidebar();
?>
<main class="main">

<div class="ph">
    <div class="ph-left">
        <h1>Scan Content</h1>
        <p>Re-analyze approved content with the current ML model</p>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <form method="POST" class="row-wrap" style="align-items:flex-end;gap:12px">
            <div class="form-group" style="margin-bottom:0">
                <label class="form-label">Content Type</label>
                <select name="scan_type" class="form-input" style="max-width:200px">
                    <option value="posts">Posts</option>
                    <option value="comments">Comments</option>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:0">
                <label class="form-label">Limit</label>
                <input type="number" name="limit" class="form-input" value="50" min="10" max="200" style="max-width:120px">
            </div>
            <button type="submit" name="scan" value="1" class="btn btn-primary">Run Scan</button>
        </form>
    </div>
</div>

<?php if ($results !== null): ?>
<div class="card card-clean mt-16">
    <div class="card-hd">
        <h3>Scan Results (<?= count($results) ?> items)</h3>
    </div>
    <table class="tbl">
        <thead><tr><th>ID</th><th>Text</th><th>Result</th><th>Risk</th><th>Category</th></tr></thead>
        <tbody>
        <?php foreach ($results as $r): ?>
        <?php $isForbidden = (($r['verdict'] ?? '') === 'FORBIDDEN'); ?>
        <tr style="<?= $isForbidden ? 'background:#fdeceb' : '' ?>">
            <td>#<?= $r['id'] ?? '?' ?></td>
            <td class="tbl-truncate"><?= htmlspecialchars(mb_substr($r['input'] ?? $r['text'] ?? '', 0, 80)) ?></td>
            <td>
                <?php if ($isForbidden): ?>
                    <span class="badge badge-r">Harmful</span>
                <?php else: ?>
                    <span class="badge badge-g">Safe</span>
                <?php endif; ?>
            </td>
            <td><span class="mono-sm"><?= round($r['harmful_prob'] ?? 0, 1) ?>%</span></td>
            <td class="muted-sm"><?= htmlspecialchars($r['category'] ?? 'safe') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

</main>
<?php echo adminFoot(); ?>
