<?php
require_once '../includes/config.php';
requireAdmin();
$PAGE = 'mlstats';
require_once 'inc_sidebar.php';

$mlHealth = [];
$ch = curl_init(ML_API_URL.'/health');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>2]);
$r = curl_exec($ch);
curl_close($ch);
if ($r) $mlHealth = json_decode($r, true) ?? [];

$total    = (int)$pdo->query("SELECT COUNT(*) FROM content_analysis")->fetchColumn();
$harmful  = (int)$pdo->query("SELECT COUNT(*) FROM content_analysis WHERE label=1")->fetchColumn();
$safe     = $total - $harmful;
$todayAnalysis = (int)$pdo->query("SELECT COUNT(*) FROM content_analysis WHERE DATE(created_at)=CURDATE()")->fetchColumn();

$categories = $pdo->query("
    SELECT category, COUNT(*) AS cnt
    FROM content_analysis
    WHERE label=1
    GROUP BY category
    ORDER BY cnt DESC
")->fetchAll();

echo adminHead('ML Statistics', true);
echo adminSidebar();
?>
<main class="main">

<div class="ph">
    <div class="ph-left">
        <h1>ML Statistics</h1>
        <p>Machine learning moderation performance</p>
    </div>
    <div class="hp <?= !empty($mlHealth) ? 'hp-on' : 'hp-off' ?>">
        <span class="hp-dot"></span>ML API <?= !empty($mlHealth) ? 'Online' : 'Offline' ?>
    </div>
</div>

<div class="sg stat-4">
    <div class="sc">
        <div class="sc-accent sc-violet"></div>
        <div class="sc-icon sc-violet">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/></svg>
        </div>
        <div class="sc-val"><?= $total ?></div>
        <div class="sc-lbl">Total Analyses</div>
    </div>
    <div class="sc">
        <div class="sc-accent sc-green"></div>
        <div class="sc-icon sc-green">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <div class="sc-val"><?= $safe ?></div>
        <div class="sc-lbl">Safe</div>
    </div>
    <div class="sc">
        <div class="sc-accent sc-red"></div>
        <div class="sc-icon sc-red">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        </div>
        <div class="sc-val"><?= $harmful ?></div>
        <div class="sc-lbl">Harmful</div>
    </div>
    <div class="sc">
        <div class="sc-accent sc-amber"></div>
        <div class="sc-icon sc-amber">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
        </div>
        <div class="sc-val"><?= $todayAnalysis ?></div>
        <div class="sc-lbl">Today</div>
    </div>
</div>

<div class="cg22">
    <div class="card">
        <div class="card-hd"><h3>Model Information</h3></div>
        <div class="card-body">
            <div class="kv-grid">
                <div><div class="kv-k">Version</div><div class="kv-v"><?= htmlspecialchars($mlHealth['version'] ?? 'unknown') ?></div></div>
                <div><div class="kv-k">Accuracy</div><div class="kv-v risk-safe"><?= htmlspecialchars($mlHealth['accuracy'] ?? '—') ?></div></div>
                <div><div class="kv-k">Framework</div><div class="kv-v">Scikit-learn</div></div>
                <div><div class="kv-k">Threshold</div><div class="kv-v"><?= htmlspecialchars($mlHealth['threshold'] ?? '0.52') ?></div></div>
                <div><div class="kv-k">Hate Keywords</div><div class="kv-v"><?= $mlHealth['hate_keywords'] ?? 0 ?></div></div>
                <div><div class="kv-k">Scam Keywords</div><div class="kv-v"><?= $mlHealth['scam_keywords'] ?? 0 ?></div></div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-hd"><h3>Detection Breakdown</h3></div>
        <div class="card-body">
            <?php if (empty($categories)): ?>
                <div class="empty-copy" style="padding:20px">No categories yet</div>
            <?php else: foreach ($categories as $cat):
                $pct = $harmful > 0 ? round(($cat['cnt'] / $harmful) * 100) : 0;
            ?>
            <div class="mb-12">
                <div class="row-between muted-sm" style="margin-bottom:5px">
                    <strong><?= htmlspecialchars($cat['category']) ?></strong>
                    <span class="muted-sm"><?= $cat['cnt'] ?> (<?= $pct ?>%)</span>
                </div>
                <div class="progress-line">
                    <span style="width:<?= $pct ?>%"></span>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>

</main>
<?php echo adminFoot(); ?>
