<?php
require_once '../includes/config.php';
requireAdmin();
$PAGE = 'dataset';
require_once 'inc_sidebar.php';

$datasetDir = __DIR__ . '/../ml_api/models/datasets';
$files = [];
if (is_dir($datasetDir)) {
    foreach (scandir($datasetDir) as $f) {
        if ($f === '.' || $f === '..' || strpos($f, 'PUT_') === 0) continue;
        $path = $datasetDir . '/' . $f;
        if (is_file($path)) {
            $files[] = [
                'name'     => $f,
                'size_mb'  => round(filesize($path) / 1048576, 2),
                'modified' => date('Y-m-d H:i', filemtime($path)),
                'ext'      => strtolower(pathinfo($f, PATHINFO_EXTENSION)),
            ];
        }
    }
}

echo adminHead('Dataset Viewer', false);
echo adminSidebar();
?>
<main class="main">

<div class="ph">
    <div class="ph-left">
        <h1>Dataset Viewer</h1>
        <p>External training datasets loaded by the ML API</p>
    </div>
</div>

<div class="alert alert-info">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
    <div>
        <strong>Dataset location:</strong> <span class="tag">ml_api/models/datasets/</span><br>
        Place your CSV/XLSX dataset files here manually. The ML API will load them on startup.
    </div>
</div>

<div class="card card-clean">
    <div class="card-hd">
        <h3>Loaded Datasets</h3>
        <span class="muted-sm"><?= count($files) ?> file(s)</span>
    </div>
    <?php if (empty($files)): ?>
        <div class="empty-copy">
            <div style="font-size:36px;opacity:0.4;margin-bottom:10px">📂</div>
            <p>No datasets loaded yet.</p>
            <p class="muted-sm" style="margin-top:6px">Add your files to <span class="tag">ml_api/models/datasets/</span></p>
        </div>
    <?php else: ?>
    <table class="tbl">
        <thead><tr><th>File</th><th>Type</th><th>Size</th><th>Modified</th></tr></thead>
        <tbody>
        <?php foreach ($files as $f): ?>
        <tr>
            <td><strong><?= htmlspecialchars($f['name']) ?></strong></td>
            <td><span class="badge badge-b"><?= strtoupper($f['ext']) ?></span></td>
            <td><?= $f['size_mb'] ?> MB</td>
            <td class="muted-sm"><?= $f['modified'] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

</main>
<?php echo adminFoot(); ?>
