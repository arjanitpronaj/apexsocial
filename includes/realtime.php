<?php
/** WebSocket push from PHP to ws_server (port 8081). */

if (!defined('REALTIME_PUSH_KEY')) {
    define('REALTIME_PUSH_KEY', getenv('APEX_WS_KEY') ?: 'apex-ws-key-2025');
}

if (!defined('REALTIME_PUSH_PORT')) {
    define('REALTIME_PUSH_PORT', 8081);
}

function apexResolveBackendUrl(): string
{
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }

    $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
    $host = preg_replace('/:\d+$/', '', explode('/', $host)[0]);
    if ($host === '') {
        $host = '127.0.0.1';
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $resolved = $scheme . '://' . $host . ':' . REALTIME_PUSH_PORT;

    return $resolved;
}

function apexRealtimePush(
    string $event,
    array $payload,
    ?int $userId = null,
    bool $toAdmins = false
): void {
    if ($event === '' || (!$userId && !$toAdmins)) {
        return;
    }

    $url = rtrim(apexResolveBackendUrl(), '/') . '/api/push';
    $body = json_encode([
        'event' => $event,
        'payload' => $payload,
        'user_id' => $userId,
        'to_admins' => $toAdmins,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    if ($ch === false) {
        return;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Api-Key: ' . REALTIME_PUSH_KEY,
        ],
        CURLOPT_TIMEOUT_MS => 1500,
        CURLOPT_CONNECTTIMEOUT_MS => 800,
    ]);
    @curl_exec($ch);
    curl_close($ch);
}

function apexNotifyUser(int $userId, string $type, int $refId, string $message, int $fromUser = 0): void
{
    apexRealtimePush('Notification', [
        'type' => $type,
        'refId' => $refId,
        'msg' => $message,
        'message' => $message,
        'fromUser' => $fromUser,
    ], $userId, false);
}

function apexModerationResult(
    int $authorId,
    string $action,
    string $contentType,
    int $refId,
    string $reason = '',
    string $msg = ''
): void {
    apexRealtimePush('ModerationResult', [
        'action' => $action,
        'type' => $contentType,
        'postId' => $contentType === 'post' ? $refId : null,
        'commentId' => $contentType === 'comment' ? $refId : null,
        'reason' => $reason,
        'msg' => $msg,
    ], $authorId, false);
}

function apexQueueUpdate(): void
{
    apexRealtimePush('QueueUpdate', ['ts' => date('c')], null, true);
}

function apexUserBanned(int $userId, string $reason): void
{
    apexRealtimePush('Banned', ['reason' => $reason], $userId, false);
}
