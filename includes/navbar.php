<?php
if (!isset($notifCount) && isset($pdo, $me['id'])) {
    $notifCount = getUnreadNotifCount($pdo, (int) $me['id']);
} elseif (!isset($notifCount)) {
    $notifCount = 0;
}
$nav_active = $nav_active ?? '';
$nav_show_search = !empty($nav_show_search);
$na = function ($key) use ($nav_active) {
    return $nav_active === $key ? ' active' : '';
};
?>
<?php
$wsHost = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '127.0.0.1');
$wsProto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'wss' : 'ws';
$apexWsUrl = $wsProto . '://' . $wsHost . ':8080';
$wsSecret = getenv('WS_SECRET') ?: 'apex-ws-secret';
$wsBucket = (string) floor(time() / 300);
$wsTokenPayload = ((string) ((int) ($me['id'] ?? 0))) . ':' . $wsBucket;
$wsToken = hash_hmac('sha256', $wsTokenPayload, $wsSecret);
?>
<script>
window.APEX_API="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/includes/ajax.php";
window.APEX_BASE="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>";
<?php if (isset($me['id'])): ?>
window.APEX_USER = { userId: <?= (int) $me['id'] ?>, isAdmin: <?= isAdminLogged() ? 'true' : 'false' ?>, wsToken: <?= json_encode($wsToken) ?> };
window.APEX_WS_URL = <?= json_encode($apexWsUrl) ?>;
<?php endif; ?>
</script>
<script src="<?= BASE_URL ?>/assets/js/realtime.js?v=rt8"></script>
<nav class="navbar">
    <div class="navbar-inner">
        <button type="button" class="nav-burger" id="nav-burger" aria-label="Open menu" aria-expanded="false">
            <span></span><span></span><span></span>
        </button>
        <a href="<?= BASE_URL ?>/index.php" class="nav-brand">
            <span class="nav-brand-icon" aria-hidden="true"></span>
            ApexSocial
        </a>
        <div class="navbar-center">
            <a href="<?= BASE_URL ?>/index.php" class="nav-top-link<?= $na('feed') ?>">Feed</a>
            <a href="<?= BASE_URL ?>/pages/explore.php" class="nav-top-link<?= $na('explore') ?>">Explore</a>
            <a href="<?= BASE_URL ?>/pages/friends.php" class="nav-top-link<?= $na('friends') ?>">Friends</a>
            <a href="<?= BASE_URL ?>/pages/notifications.php" class="nav-top-link<?= $na('messages') ?>">Messages<?php if ($notifCount > 0): ?><span id="notif-count" class="nav-top-badge notif-badge"><?= (int) $notifCount ?></span><?php endif; ?></a>
            <a href="<?= BASE_URL ?>/pages/saved.php" class="nav-top-link<?= $na('saved') ?>">Saved</a>
        </div>
        <?php if ($nav_show_search): ?>
        <div class="nav-search nav-search--toolbar">
            <input id="search-input" type="text" placeholder="Search people..." autocomplete="off">
            <div id="search-results" class="nav-search-results" hidden></div>
        </div>
        <?php endif; ?>
        <div class="navbar-right">
            <span id="signalr-status-dot" class="signalr-status-dot" title="Real-time connection" aria-hidden="true"></span>
            <button type="button" class="theme-toggle" id="theme-toggle" title="Toggle theme" aria-label="Toggle dark mode">
                <span class="theme-ico theme-ico-sun" aria-hidden="true">☀</span>
                <span class="theme-ico theme-ico-moon" aria-hidden="true">☽</span>
            </button>
            <a href="<?= BASE_URL ?>/pages/settings.php" class="nav-icon-btn" title="Settings">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 1v2m0 18v2M4.22 4.22l1.42 1.42m12.72 12.72l1.42 1.42M1 12h2m18 0h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
            </a>
            <a href="<?= BASE_URL ?>/pages/profile.php" class="nav-avatar-link" title="Profile"><?= avatarHtml($me, 34, 11) ?></a>
            <a href="<?= BASE_URL ?>/pages/logout.php" class="btn btn-ghost btn-sm nav-logout-mobile">Logout</a>
        </div>
    </div>
    <div class="nav-drawer-overlay" id="nav-drawer-overlay" aria-hidden="true"></div>
    <div class="nav-drawer" id="nav-drawer" aria-hidden="true">
        <div class="nav-drawer-head">
            <span class="nav-drawer-title">Menu</span>
            <button type="button" class="nav-drawer-close" id="nav-drawer-close" aria-label="Close menu">✕</button>
        </div>
        <a href="<?= BASE_URL ?>/index.php" class="nav-drawer-link<?= $na('feed') ?>">Feed</a>
        <a href="<?= BASE_URL ?>/pages/explore.php" class="nav-drawer-link<?= $na('explore') ?>">Explore</a>
        <a href="<?= BASE_URL ?>/pages/notifications.php" class="nav-drawer-link<?= $na('messages') ?>">Messages<?php if ($notifCount > 0): ?><span class="nav-top-badge notif-badge"><?= (int) $notifCount ?></span><?php endif; ?></a>
        <a href="<?= BASE_URL ?>/pages/saved.php" class="nav-drawer-link<?= $na('saved') ?>">Saved</a>
        <a href="<?= BASE_URL ?>/pages/friends.php" class="nav-drawer-link<?= $na('friends') ?>">Friends</a>
        <a href="<?= BASE_URL ?>/pages/logout.php" class="nav-drawer-link nav-drawer-link-muted">Logout</a>
    </div>
</nav>
