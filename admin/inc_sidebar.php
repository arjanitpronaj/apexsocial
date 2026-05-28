<?php
$_sb_pending = 0;
$_sb_harmful = (int)$pdo->query("SELECT COUNT(*) FROM content_analysis WHERE label=1 AND reviewed_by_admin=0")->fetchColumn();
$_sb_reports = (int)$pdo->query("SELECT COUNT(*) FROM reports WHERE status='pending'")->fetchColumn();

$_sb_backend = false;
$_hch = curl_init(BACKEND_URL . '/health');
curl_setopt_array($_hch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 1, CURLOPT_CONNECTTIMEOUT => 1]);
$_hr = curl_exec($_hch);
$_herr = curl_error($_hch);
curl_close($_hch);
if (!$_herr && $_hr) $_sb_backend = true;
unset($_hch, $_hr, $_herr);

$_sb_admin = null;
if (isset($_SESSION['user_id'])) {
    $s = $pdo->prepare("SELECT username,full_name,avatar_color FROM users WHERE id=?");
    $s->execute([$_SESSION['user_id']]);
    $_sb_admin = $s->fetch();
}

function adminHead(string $title, bool $chartjs = false): string {
    $cj = $chartjs ? '<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>' : '';
    $apexScripts = '';
    if (isAdminLogged() && isset($_SESSION['user_id'])) {
        $uid = (int) $_SESSION['user_id'];
        $base = htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8');
        $wsHost = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '127.0.0.1');
        $wsProto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'wss' : 'ws';
        $wsUrl = htmlspecialchars($wsProto . '://' . $wsHost . ':8080', ENT_QUOTES, 'UTF-8');
        $apexScripts = <<<SCRIPT
<script>
window.APEX_BASE = "{$base}";
window.APEX_USER = { userId: {$uid}, isAdmin: true };
window.APEX_WS_URL = "{$wsUrl}";
</script>
<script src="../assets/js/realtime.js?v=rt8"></script>
SCRIPT;
    }
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$title} — ApexAdmin</title>
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%234f46e5'/%3E%3Cpath d='M22 8H10a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h3l3 3 3-3h3a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2z' fill='white'/%3E%3Ccircle cx='12' cy='14' r='1.2' fill='%234f46e5'/%3E%3Ccircle cx='16' cy='14' r='1.2' fill='%234f46e5'/%3E%3Ccircle cx='20' cy='14' r='1.2' fill='%234f46e5'/%3E%3C/svg%3E">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/admin.css?v=20260503c">
{$cj}
{$apexScripts}
</head>
<body>
<button class="sb-hamburger" onclick="openSidebar()" aria-label="Open menu">
  <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
</button>
<div class="al">
HTML;
}

function adminSidebar(): string {
    global $PAGE, $_sb_pending, $_sb_harmful, $_sb_reports, $_sb_admin, $_sb_backend;
    $p  = $PAGE ?? '';

    // SVG icons
    $icons = [
        'dashboard' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>',
        'queue'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
        'harmful'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        'reports'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>',
        'users'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'posts'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>',
        'mlstats'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M7 12h2l2-4 3 8 2-4h2"/></svg>',
        'dataset'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>',
        'activity'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>',
        'logout'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>',
    ];

    $pb  = $_sb_pending > 0 ? "<span class='sb-badge'>{$_sb_pending}</span>"  : '';
    $hb  = $_sb_harmful > 0 ? "<span class='sb-badge'>{$_sb_harmful}</span>"  : '';
    $rp  = $_sb_reports > 0 ? "<span class='sb-badgey'>{$_sb_reports}</span>" : '';

    $nav = [
        'Monitor' => [
            ['index.php',    'dashboard', 'dashboard', 'Overview',          ''],
            ['queue.php',    'queue',     'queue',     'Blocked Content',  $pb],
            ['harmful.php',  'harmful',   'harmful',   'Harmful Detected',  $hb],
            ['reports.php',  'reports',   'reports',   'Reports',           $rp],
        ],
        'Manage' => [
            ['users.php',    'users',    'users',    'Users',     ''],
            ['all_posts.php','posts',    'posts',    'All Posts', ''],
        ],
        'System' => [
            ['ml_stats.php', 'mlstats',  'mlstats',  'ML Statistics', ''],
            ['dataset.php',  'dataset',  'dataset',  'Dataset',       ''],
            ['activity.php', 'activity', 'activity', 'Activity Log',  ''],
        ],
    ];

    $adminName = $_sb_admin ? htmlspecialchars($_sb_admin['full_name'] ?: $_sb_admin['username']) : 'Admin';
    $adminUser = $_sb_admin ? htmlspecialchars($_sb_admin['username']) : 'admin';

    $backendDot  = $_sb_backend ? 'on' : '';
    $backendText = $_sb_backend ? 'C# Core Online' : 'C# Core Offline';

    $html = '<aside class="sb" id="admin-sb">';
    $html .= <<<HTML
<div class="sb-brand">
  <div class="sb-brand-logo">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
      <circle cx="9" cy="10" r="1" fill="currentColor" stroke="none"/>
      <circle cx="12" cy="10" r="1" fill="currentColor" stroke="none"/>
      <circle cx="15" cy="10" r="1" fill="currentColor" stroke="none"/>
    </svg>
  </div>
  <div>
    <div class="sb-brand-title">APEX<span>SOCIAL</span></div>
    <div class="sb-brand-sub">Admin Panel</div>
  </div>
</div>
<div class="sb-scroll">
HTML;

    foreach ($nav as $section => $items) {
        $html .= "<div class='sb-section'>{$section}</div>";
        foreach ($items as [$href, $key, $iconKey, $label, $badge]) {
            $on   = $p === $key ? ' class="on"' : '';
            $icon = $icons[$iconKey] ?? '';
            $html .= "<a href='{$href}'{$on}>{$icon}{$label}{$badge}</a>";
        }
    }

    $html .= '</div>';
    $html .= <<<HTML
<div class="sb-foot">
  <div class="sb-mlbar">
    <span class="sb-mldot {$backendDot}"></span>
    <span class="grow">{$backendText}</span>
  </div>
  <div class="sb-user">
    <div class="av av-34" style="background:{$_sb_admin['avatar_color']}">A</div>
    <div class="sb-user-name">
      <strong>{$adminName}</strong>
      <small>{$adminUser}</small>
    </div>
    <a href="logout.php" class="sb-logout">Logout</a>
  </div>
</div>
</aside>
<div class="sb-overlay" id="sb-overlay" onclick="closeSidebar()"></div>
HTML;

    return $html;
}

function adminFoot(): string {
    return <<<'HTML'
</div>
<script>
function openSidebar()  { document.getElementById('admin-sb').classList.add('open'); document.getElementById('sb-overlay').classList.add('show'); }
function closeSidebar() { document.getElementById('admin-sb').classList.remove('open'); document.getElementById('sb-overlay').classList.remove('show'); }
</script>
<script src="../assets/js/app.js?v=loop202605"></script>
</body>
</html>
HTML;
}
