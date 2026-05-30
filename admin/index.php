<?php
require_once '../includes/config.php';
requireAdmin();
$PAGE = 'dashboard';
require_once 'inc_sidebar.php';

$backendOnline = false;
$bch = curl_init(BACKEND_URL.'/health');
curl_setopt_array($bch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>2, CURLOPT_CONNECTTIMEOUT=>1]);
$br = curl_exec($bch);
$berr = curl_error($bch);
curl_close($bch);
if (!$berr && $br) $backendOnline = true;

$mlOnline = false;
$ch = curl_init(ML_API_URL.'/health');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>2, CURLOPT_CONNECTTIMEOUT=>1]);
$r = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);
if (!$err && $r) $mlOnline = true;

$totalUsers   = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_admin=0")->fetchColumn();
$activeUsers  = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_admin=0 AND is_blocked=0")->fetchColumn();
$bannedUsers  = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_blocked=1")->fetchColumn();
$blockedCount = (int)$pdo->query("SELECT COUNT(*) FROM content_analysis WHERE label=1")->fetchColumn();
$approvedPosts= (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE status='approved'")->fetchColumn();
$rejectedPosts= (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE status='rejected'")->fetchColumn();
$blockedPosts = (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE status IN ('rejected','pending')")->fetchColumn();
$blockedCmts  = (int)$pdo->query("SELECT COUNT(*) FROM comments WHERE status IN ('rejected','pending')")->fetchColumn();

$harmfulCount = (int)$pdo->query("SELECT COUNT(*) FROM content_analysis WHERE label=1")->fetchColumn();
$safeCount    = (int)$pdo->query("SELECT COUNT(*) FROM content_analysis WHERE label=0")->fetchColumn();
$todayPosts   = (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE DATE(created_at)=CURDATE()")->fetchColumn();
$blockedRep   = (int)$pdo->query("SELECT COUNT(*) FROM reports WHERE status='blocked'")->fetchColumn();

// 7-day chart data
$days = []; $daySafe = []; $dayHarm = []; $dayApproved = [];
$d7 = date('Y-m-d', strtotime('-6 days'));
$caMap = []; $pMap = [];
$s = $pdo->prepare("SELECT DATE(created_at) dt, SUM(label=0) s, SUM(label=1) h FROM content_analysis WHERE DATE(created_at) >= ? GROUP BY dt");
$s->execute([$d7]);
foreach ($s->fetchAll() as $row) $caMap[$row['dt']] = $row;
$s2 = $pdo->prepare("SELECT DATE(created_at) dt, COUNT(*) n FROM posts WHERE status='approved' AND DATE(created_at) >= ? GROUP BY dt");
$s2->execute([$d7]);
foreach ($s2->fetchAll() as $row) $pMap[$row['dt']] = (int)$row['n'];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $days[] = date('d M', strtotime($d));
    $daySafe[] = (int)($caMap[$d]['s'] ?? 0);
    $dayHarm[] = (int)($caMap[$d]['h'] ?? 0);
    $dayApproved[] = $pMap[$d] ?? 0;
}

$recentHarmful = $pdo->query("SELECT ca.*, u.username, u.avatar_color FROM content_analysis ca JOIN users u ON ca.user_id=u.id WHERE ca.label=1 ORDER BY ca.created_at DESC LIMIT 6")->fetchAll();
$topFlagged    = $pdo->query("SELECT u.username, u.avatar_color, u.full_name, COUNT(*) cnt FROM content_analysis ca JOIN users u ON ca.user_id=u.id WHERE ca.label=1 GROUP BY ca.user_id ORDER BY cnt DESC LIMIT 5")->fetchAll();

echo adminHead('Dashboard', true);
echo adminSidebar();
?>
<main class="main">

<div class="ph">
    <div class="ph-left">
        <h1>Overview</h1>
        <p><?= date('l, d F Y') ?> · <?= $todayPosts ?> submissions today</p>
    </div>
    <div class="row-wrap">
        <div class="hp <?= $backendOnline ? 'hp-on' : 'hp-off' ?>">
            <span class="hp-dot"></span>C# Core <?= $backendOnline ? 'Online' : 'Offline' ?>
        </div>
        <div class="hp <?= $mlOnline ? 'hp-on' : 'hp-off' ?>">
            <span class="hp-dot"></span>Python ML <?= $mlOnline ? 'Online' : 'Offline' ?>
        </div>
        <span class="ph-right-badge">Admin</span>
    </div>
</div>

<?php if (!$backendOnline): ?>
<div class="alert alert-error">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <div>
        <strong>C# Singular Core Offline.</strong> Start with: <span class="tag">cd Backend && dotnet run</span>
    </div>
</div>
<?php endif; ?>

<?php if (!$mlOnline): ?>
<div class="alert alert-warning">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    <div>
        <strong>Python ML API Offline.</strong> Start with: <span class="tag">cd ml_api && python api.py</span>
    </div>
</div>
<?php endif; ?>

<div class="sg">
    <div class="sc">
        <div class="sc-accent sc-violet"></div>
        <div class="sc-icon sc-violet">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        </div>
        <div class="sc-val"><?= $totalUsers ?></div>
        <div class="sc-lbl">Total Users</div>
    </div>
    <div class="sc">
        <div class="sc-accent sc-green"></div>
        <div class="sc-icon sc-green">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        </div>
        <div class="sc-val"><?= $approvedPosts ?></div>
        <div class="sc-lbl">Approved Posts</div>
    </div>
    <div class="sc">
        <div class="sc-accent sc-amber"></div>
        <div class="sc-icon sc-amber">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        </div>
        <div class="sc-val"><?= $blockedPosts + $blockedCmts ?></div>
        <div class="sc-lbl">Blocked Content</div>
    </div>
    <div class="sc">
        <div class="sc-accent sc-red"></div>
        <div class="sc-icon sc-red">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        </div>
        <div class="sc-val"><?= $harmfulCount ?></div>
        <div class="sc-lbl">Harmful Detected</div>
    </div>
    <div class="sc">
        <div class="sc-accent sc-purple"></div>
        <div class="sc-icon sc-purple">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>
        </div>
        <div class="sc-val"><?= $blockedRep ?></div>
        <div class="sc-lbl">Blocked Reports</div>
    </div>
</div>

<?php if ($blockedPosts + $blockedCmts > 0): ?>
<a href="queue.php" class="queue-banner">
    <div class="row-wrap" style="gap:16px">
        <div class="icon-chip">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        </div>
        <div class="grow">
            <div class="queue-banner-title"><?= $blockedPosts + $blockedCmts ?> item<?= ($blockedPosts + $blockedCmts) != 1 ? 's' : '' ?> blocked by moderation</div>
            <div class="queue-banner-sub"><?= $blockedPosts ?> posts · <?= $blockedCmts ?> comments</div>
        </div>
        <span class="btn btn-primary btn-sm">View blocked →</span>
    </div>
</a>
<?php endif; ?>

<div class="card">
    <div class="card-hd">
        <h3>Content Moderation Funnel</h3>
        <a href="queue.php">View Blocked →</a>
    </div>
    <div class="funnel">
        <div class="funnel-col funnel-amber">
            <div class="funnel-num"><?= $blockedPosts + $blockedCmts ?></div>
            <div class="funnel-lbl">Blocked</div>
            <div class="funnel-bar"></div>
        </div>
        <div class="funnel-col funnel-green">
            <div class="funnel-num"><?= $approvedPosts ?></div>
            <div class="funnel-lbl">Approved</div>
            <div class="funnel-bar"></div>
        </div>
        <div class="funnel-col funnel-red">
            <div class="funnel-num"><?= $rejectedPosts ?></div>
            <div class="funnel-lbl">Rejected</div>
            <div class="funnel-bar"></div>
        </div>
    </div>
</div>

<div class="cg2">
    <div class="cc">
        <div class="cc-title">ML Analyses — Last 7 Days</div>
        <canvas id="c1" height="110"></canvas>
    </div>
    <div class="cc" style="display:flex;flex-direction:column;align-items:center">
        <div class="cc-title" style="width:100%">Safe vs Harmful</div>
        <canvas id="c2" style="max-height:170px"></canvas>
        <div class="row-wrap mt-16 muted-sm">
            <span class="row-start"><span style="width:8px;height:8px;border-radius:50%;background:#10b981"></span>Safe (<?= $safeCount ?>)</span>
            <span class="row-start"><span style="width:8px;height:8px;border-radius:50%;background:#ef4444"></span>Harmful (<?= $harmfulCount ?>)</span>
        </div>
    </div>
</div>

<div class="cg22 mt-20">
    <div class="card">
        <div class="card-hd">
            <h3>Recent Harmful Content</h3>
            <a href="harmful.php">View all →</a>
        </div>
        <?php if (empty($recentHarmful)): ?>
            <div class="empty-copy">No harmful content detected yet.</div>
        <?php else: ?>
        <table class="tbl">
            <thead><tr><th>User</th><th>Type</th><th>Risk</th><th>Time</th></tr></thead>
            <tbody>
            <?php foreach ($recentHarmful as $h): ?>
            <tr>
                <td><strong>@<?= htmlspecialchars($h['username']) ?></strong></td>
                <td><span class="badge badge-b"><?= $h['content_type'] ?></span></td>
                <td><span class="risk-high"><?= round($h['harmful_prob'], 1) ?>%</span></td>
                <td class="muted-xs"><?= timeAgo($h['created_at']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <div class="card">
        <div class="card-hd">
            <h3>Most Flagged Users</h3>
            <a href="users.php">Manage →</a>
        </div>
        <?php if (empty($topFlagged)): ?>
            <div class="empty-copy">No flagged users.</div>
        <?php else: foreach ($topFlagged as $i => $u): ?>
        <div class="row-start" style="padding:14px 22px;border-bottom:1px solid var(--border);gap:12px">
            <div class="muted-sm" style="font-weight:800;width:14px"><?= $i + 1 ?></div>
            <div class="av av-32" style="background:<?= $u['avatar_color'] ?>"><?= strtoupper(substr($u['username'], 0, 1)) ?></div>
            <div class="grow">
                <div style="font-size:13px;font-weight:600;color:var(--text)">@<?= htmlspecialchars($u['username']) ?></div>
                <div style="height:4px;background:var(--border);border-radius:2px;margin-top:5px">
                    <div style="height:100%;border-radius:2px;background:#ef4444;width:<?= min($u['cnt'] * 18, 100) ?>%"></div>
                </div>
            </div>
            <span class="risk-high"><?= $u['cnt'] ?></span>
        </div>
        <?php endforeach; endif; ?>
    </div>
</div>

</main>

<script>
Chart.defaults.color = '#5b6178';
Chart.defaults.borderColor = '#e6e9f0';
Chart.defaults.font.family = "'Inter', sans-serif";
Chart.defaults.font.size = 11;

const gridOpts = { color: '#f1f3f8' };
const axisOpts = { y: { beginAtZero: true, grid: gridOpts }, x: { grid: gridOpts } };

new Chart('c1', {
    type: 'bar',
    data: {
        labels: <?= json_encode($days) ?>,
        datasets: [
            { label: 'Safe',    data: <?= json_encode($daySafe) ?>, backgroundColor: 'rgba(16,185,129,0.7)',  borderColor: '#10b981', borderWidth: 0, borderRadius: 6 },
            { label: 'Harmful', data: <?= json_encode($dayHarm) ?>, backgroundColor: 'rgba(239,68,68,0.7)',   borderColor: '#ef4444', borderWidth: 0, borderRadius: 6 }
        ]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'top', labels: { boxWidth: 10, padding: 14 } } },
        scales: axisOpts
    }
});

new Chart('c2', {
    type: 'doughnut',
    data: {
        labels: ['Safe','Harmful'],
        datasets: [{ data: [<?= $safeCount ?>, <?= $harmfulCount ?>], backgroundColor: ['#10b981','#ef4444'], borderWidth: 0 }]
    },
    options: { responsive: true, plugins: { legend: { display: false } }, cutout: '72%' }
});
</script>

<?php echo adminFoot(); ?>
