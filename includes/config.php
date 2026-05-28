<?php

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

define('DB_HOST', '127.0.0.1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'apexsocial');

define('BASE_URL', (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/apexsocial');
define('ML_API_URL', 'http://127.0.0.1:5000');

require_once __DIR__ . '/realtime.php';
require_once __DIR__ . '/sanitize.php';
define('BACKEND_URL', apexResolveBackendUrl());

define('APEX_ROOT', dirname(__DIR__));
define('UPLOAD_PATH', __DIR__ . '/../uploads/');
define('ALLOWED_UPLOAD_EXT', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf']);
define('MAX_UPLOAD_MB', 20);

$pdoOpts = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
if (defined('PDO::MYSQL_ATTR_CONNECT_TIMEOUT')) {
    $pdoOpts[PDO::MYSQL_ATTR_CONNECT_TIMEOUT] = 5;
}
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        $pdoOpts
    );
} catch (PDOException $e) {
    die('Database connection failed. Please import database.sql in phpMyAdmin and check your credentials.');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!headers_sent()) {
    header(
        "Content-Security-Policy: default-src 'self'; " .
        "script-src 'self' 'unsafe-inline' 'unsafe-eval'; " .
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
        "img-src 'self' data: blob:; " .
        "connect-src 'self' ws://127.0.0.1:8080 ws://localhost:8080 wss://127.0.0.1:8080 wss://localhost:8080; " .
        "font-src 'self' https://fonts.gstatic.com; " .
        "object-src 'none';"
    );
}

function isLoggedIn()
{
    return isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'user';
}

function isAdminLogged()
{
    return isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'admin';
}

function checkSessionValid($pdo)
{
    if (!isLoggedIn()) {
        return true;
    }
    $uid = $_SESSION['user_id'];
    $s = $pdo->prepare('SELECT is_blocked,ban_reason FROM users WHERE id=?');
    $s->execute([$uid]);
    $u = $s->fetch();
    if (!$u) {
        session_destroy();
        return false;
    }
    if ($u['is_blocked']) {
        session_unset();
        session_destroy();
        setcookie(session_name(), '', time() - 3600, '/');
        header('Location: ' . BASE_URL . '/pages/banned.php?r=' . urlencode(base64_encode($u['ban_reason'])));
        exit;
    }
    return true;
}

function requireLogin()
{
    global $pdo;
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/pages/login.php');
        exit;
    }
    checkSessionValid($pdo);
}

function requireAdmin()
{
    if (!isAdminLogged()) {
        header('Location: ' . BASE_URL . '/admin/login.php');
        exit;
    }
}

function getCurrentUser($pdo)
{
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    $s = $pdo->prepare('SELECT * FROM users WHERE id=?');
    $s->execute([$_SESSION['user_id']]);
    return $s->fetch();
}

function redirect($url)
{
    header("Location: $url");
    exit;
}

function mlAnalyze(string $text, int $userId = 0, string $type = 'post'): array
{
    $ch = curl_init(ML_API_URL . '/analyze');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'text' => $text,
            'user_id' => $userId,
            'type' => $type,
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 4,
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err || !$resp) {
        return ['offline' => true, 'verdict' => 'OFFLINE'];
    }
    $d = json_decode($resp, true);
    if (!is_array($d) || !isset($d['verdict'])) {
        return ['offline' => true, 'verdict' => 'OFFLINE'];
    }
    $d['verdict'] = strtoupper($d['verdict']);
    return $d;
}

function mlVerdictBlocks(string $verdict): bool
{
    return strtoupper($verdict) === 'FORBIDDEN';
}

function moderateContent(string $text, int $userId = 0, string $type = 'post'): array
{
    if (!$text || strlen(trim($text)) < 3) {
        return [
            'verdict' => 'ALLOWED',
            'category' => 'safe',
            'harmful_prob' => 0,
            'method' => 'trivial',
            'reason' => '',
            'offline' => false,
        ];
    }
    $result = mlAnalyze($text, $userId, $type);
    if (!empty($result['offline'])) {
        return [
            'verdict' => 'FORBIDDEN',
            'offline' => true,
            'category' => 'offline',
            'harmful_prob' => 0,
            'method' => 'offline',
            'reason' => 'Detection system is currently inactive. Posting is temporarily unavailable. Please try again later.',
        ];
    }
    return $result;
}

function analyzeContent(string $text, int $userId = 0, string $type = 'post'): array
{
    $r = moderateContent($text, $userId, $type);
    $blocked = mlVerdictBlocks($r['verdict'] ?? '');
    return [
        'verdict' => $r['verdict'],
        'label' => $blocked ? 1 : 0,
        'safe' => !$blocked,
        'harmful_prob' => $r['harmful_prob'] ?? 0,
        'confidence' => $blocked ? ($r['harmful_prob'] ?? 0) : 100.0,
        'category' => $r['category'] ?? 'safe',
        'method' => $r['method'] ?? 'sklearn',
        'reason' => $r['reason'] ?? '',
        'offline' => !empty($r['offline']),
    ];
}

function mlIsOnline(): bool
{
    $ch = curl_init(ML_API_URL . '/health');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!$resp || $code !== 200) {
        return false;
    }
    $d = json_decode($resp, true);
    return is_array($d) && ($d['status'] ?? '') === 'ok';
}

function logAnalysis($pdo, $userId, $type, $contentId, $text, $analysis)
{
    try {
        $pdo->prepare(
            'INSERT INTO content_analysis
            (user_id,content_type,content_id,text_snapshot,label,harmful_prob,confidence,category,method)
            VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([
            $userId,
            $type,
            $contentId,
            substr($text, 0, 500),
            $analysis['label'] ?? 0,
            $analysis['harmful_prob'] ?? 0,
            $analysis['confidence'] ?? 100,
            $analysis['category'] ?? 'safe',
            $analysis['method'] ?? 'sklearn',
        ]);
    } catch (Exception $e) {
    }
}

function timeAgo($dt)
{
    $diff = (new DateTime())->diff(new DateTime($dt));
    if ($diff->y) {
        return $diff->y . 'y ago';
    }
    if ($diff->m) {
        return $diff->m . 'mo ago';
    }
    if ($diff->d) {
        return $diff->d . 'd ago';
    }
    if ($diff->h) {
        return $diff->h . 'h ago';
    }
    if ($diff->i) {
        return $diff->i . 'm ago';
    }
    return 'just now';
}

function getInitials($name)
{
    $parts = preg_split('/[\s_]+/', trim($name));
    if (count($parts) >= 2) {
        return strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
    }
    return strtoupper(substr($name, 0, 2));
}

function avatarHtml($user, $size = 40, $fontSize = 13)
{
    $style = "width:{$size}px;height:{$size}px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:{$fontSize}px;font-weight:700;color:white;flex-shrink:0;";
    if (!empty($user['avatar']) && file_exists(UPLOAD_PATH . 'avatars/' . $user['avatar'])) {
        return "<img src='" . BASE_URL . "/uploads/avatars/{$user['avatar']}' style='{$style}object-fit:cover;' alt=''>";
    }
    return '<div style="background:' . ($user['avatar_color'] ?? '#4f46e5') . ';' . $style . '">' . getInitials($user['username']) . '</div>';
}

function getFriendshipStatus($pdo, $userId, $targetId)
{
    if ($userId == $targetId) {
        return 'self';
    }
    $s = $pdo->prepare(
        'SELECT * FROM friendships WHERE (sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?)'
    );
    $s->execute([$userId, $targetId, $targetId, $userId]);
    $f = $s->fetch();
    if (!$f) {
        return 'none';
    }
    if ($f['status'] === 'accepted') {
        return 'friends';
    }
    if ($f['status'] === 'pending' && $f['sender_id'] == $userId) {
        return 'sent';
    }
    if ($f['status'] === 'pending' && $f['receiver_id'] == $userId) {
        return 'received';
    }
    return 'none';
}

function getUnreadNotifCount($pdo, $userId)
{
    $s = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0');
    $s->execute([$userId]);
    return $s->fetchColumn();
}

function statusBadge($status): string
{
    return match ($status) {
        'approved' => "<span class='badge badge-g'>Approved</span>",
        'rejected' => "<span class='badge badge-r'>Rejected</span>",
        default => "<span class='badge badge-y'>Pending</span>",
    };
}
