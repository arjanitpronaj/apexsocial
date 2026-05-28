<?php
require_once '../includes/config.php';
requireLogin();
$me = getCurrentUser($pdo);
if (!$me) {
    session_unset();
    session_destroy();
    redirect(BASE_URL . '/pages/login.php');
}

$nav_active = 'explore';
$nav_show_search = false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Explore — ApexSocial</title>
    <link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css?v=loop202605">
</head>
<body>
<?php require APEX_ROOT . '/includes/navbar.php'; ?>

<div class="feed-wrap explore-page shell-animate">
    <div class="explore-layout">
        <div class="explore-main">
            <h1 class="page-title">Explore</h1>
            <p class="meta-line" style="margin-bottom:16px">Search people on ApexSocial.</p>
            <div class="panel-card explore-search-panel">
                <div class="panel-title">Search</div>
                <div class="nav-search nav-search--block">
                    <input id="search-input" type="text" placeholder="Search by name or @username..." autocomplete="off">
                    <div id="search-results" class="nav-search-results nav-search-results--block" style="display:none"></div>
                </div>
            </div>
        </div>
        <aside class="explore-aside">
            <div class="panel-card">
                <div class="panel-title">Trending</div>
                <div class="trend-item"><div><div class="trend-tag">#apexlaunch</div><div class="trend-count">Community</div></div></div>
                <div class="trend-item"><div><div class="trend-tag">#design</div><div class="trend-count">Design Talk</div></div></div>
                <div class="trend-item"><div><div class="trend-tag">#buildinpublic</div><div class="trend-count">Today</div></div></div>
            </div>
        </aside>
    </div>
</div>

<script src="../assets/js/app.js?v=loop202605"></script>
</body>
</html>
