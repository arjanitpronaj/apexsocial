<?php
require_once '../includes/config.php';
header('Content-Type: application/json');

if (!isLoggedIn()) { echo json_encode(['error' => 'Not authenticated']); exit; }
$me = $_SESSION['user_id'];

$_chk = $pdo->prepare("SELECT is_blocked, ban_reason FROM users WHERE id=?");
$_chk->execute([$me]);
$_usr = $_chk->fetch();
if (!$_usr) {
    session_unset();
    session_destroy();
    echo json_encode(['error' => 'session_invalid', 'redirect' => BASE_URL.'/pages/login.php']);
    exit;
}
if ($_usr['is_blocked']) {
    session_unset();
    session_destroy();
    $reason = (string) ($_usr['ban_reason'] ?? 'Violation.');
    echo json_encode([
        'error'    => 'banned',
        'redirect' => BASE_URL.'/pages/banned.php?r='.urlencode(base64_encode($reason)),
    ]);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'moderate_content') {
    $text = trim($_POST['text'] ?? '');
    if (!$text || strlen($text) < 3) {
        echo json_encode(['status' => 'ALLOWED']); exit;
    }
    $xssErr = apexValidateUserText($text, 1000);
    if ($xssErr) {
        echo json_encode(['status' => 'FORBIDDEN', 'reason' => $xssErr]); exit;
    }

    $result = moderateContent($text, $me, 'post');

    if (!empty($result['offline'])) {
        echo json_encode([
            'status' => 'OFFLINE',
            'reason' => $result['reason'],
        ]); exit;
    }

    $verdict = strtoupper((string) ($result['verdict'] ?? 'ALLOWED'));
    if ($verdict === 'REVIEW') {
        $verdict = 'FORBIDDEN';
    }
    echo json_encode([
        'status'       => $verdict,
        'reason'       => $result['reason']       ?? '',
        'harmful_prob' => $result['harmful_prob'] ?? 0,
        'category'     => $result['category']     ?? 'safe',
        'method'       => $result['method']       ?? 'sklearn',
        'integrity'    => $result['integrity']    ?? null,
        'language_hint'=> $result['language_hint']?? null,
    ]); exit;
}

if ($action === 'toggle_like') {
    $pid = (int)($_POST['post_id'] ?? 0);
    $postOk = $pdo->prepare("SELECT id FROM posts WHERE id=? AND status='approved'");
    $postOk->execute([$pid]);
    if (!$postOk->fetch()) {
        echo json_encode(['error' => 'Post not found.']);
        exit;
    }
    $s = $pdo->prepare("SELECT id FROM likes WHERE post_id=? AND user_id=?");
    $s->execute([$pid, $me]);
    if ($s->fetch()) {
        $pdo->prepare("DELETE FROM likes WHERE post_id=? AND user_id=?")->execute([$pid, $me]);
        $liked = false;
    } else {
        $pdo->prepare("INSERT INTO likes (post_id,user_id) VALUES (?,?)")->execute([$pid, $me]);
        $liked = true;
        $p = $pdo->prepare("SELECT user_id FROM posts WHERE id=?");
        $p->execute([$pid]); $po = $p->fetch();
        if ($po && $po['user_id'] != $me) {
            $pdo->prepare("INSERT INTO notifications (user_id,from_user_id,type,reference_id) VALUES (?,?,'like',?)")
                ->execute([$po['user_id'], $me, $pid]);
            apexNotifyUser((int)$po['user_id'], 'like', $pid, 'Someone liked your post', (int)$me);
        }
    }
    $c = $pdo->prepare("SELECT COUNT(*) FROM likes WHERE post_id=?");
    $c->execute([$pid]);
    echo json_encode(['liked' => $liked, 'count' => (int)$c->fetchColumn()]); exit;
}

if ($action === 'add_comment') {
    $pid     = (int)($_POST['post_id'] ?? 0);
    $content = trim($_POST['content']  ?? '');
    if (!$content || strlen($content) > 500) {
        echo json_encode(['error' => 'Invalid comment.']); exit;
    }
    $xssErr = apexValidateUserText($content, 500);
    if ($xssErr) {
        echo json_encode(['error' => 'forbidden', 'reason' => $xssErr]); exit;
    }

    $postOk = $pdo->prepare("SELECT id FROM posts WHERE id=? AND status='approved'");
    $postOk->execute([$pid]);
    if (!$postOk->fetch()) {
        echo json_encode(['error' => 'Post not found or not available.']);
        exit;
    }

    $analysis = analyzeContent($content, $me, 'comment');

    if (!empty($analysis['offline'])) {
        echo json_encode([
            'error'  => 'offline',
            'reason' => 'Detection system is currently inactive. Posting is temporarily unavailable. Please try again later.',
        ]); exit;
    }
    $verdict = strtoupper((string)($analysis['verdict'] ?? 'ALLOWED'));
    $commentStatus = mlVerdictBlocks($verdict) ? 'rejected' : 'approved';
    $pdo->prepare("INSERT INTO comments (post_id,user_id,content,status,ml_label,ml_prob,ml_category,ml_method)
                   VALUES (?,?,?,?,?,?,?,?)")
        ->execute([
            $pid, $me, $content, $commentStatus,
            $analysis['label']        ?? 0,
            $analysis['harmful_prob'] ?? 0,
            $analysis['category']     ?? 'safe',
            $analysis['method']       ?? 'sklearn',
        ]);
    $cid = (int)$pdo->lastInsertId();
    logAnalysis($pdo, $me, 'comment', $cid, $content, $analysis);
    if ($commentStatus === 'rejected') {
        echo json_encode([
            'error'  => 'forbidden',
            'reason' => $analysis['reason'] ?? 'Content flagged as harmful.',
        ]); exit;
    }
    echo json_encode(['success' => true, 'message' => 'Comment posted!', 'pending' => false]); exit;
}

if ($action === 'load_comments') {
    $pid = (int)($_GET['post_id'] ?? 0);
    $s = $pdo->prepare("SELECT c.*, u.username, u.avatar_color, u.avatar
                        FROM comments c
                        JOIN users u ON c.user_id=u.id
                        WHERE c.post_id=? AND c.status='approved'
                        ORDER BY c.created_at ASC");
    $s->execute([$pid]); $comments = $s->fetchAll();
    foreach ($comments as &$c) {
        $c['initials'] = getInitials($c['username']);
        $c['time']     = timeAgo($c['created_at']);
        $c['content']  = htmlspecialchars($c['content']);
        $c['is_mine']  = ($c['user_id'] == $me) ? 1 : 0;
    }
    echo json_encode(['comments' => $comments]); exit;
}

if ($action === 'delete_comment') {
    $cid = (int)($_POST['comment_id'] ?? 0);
    $s = $pdo->prepare("SELECT * FROM comments WHERE id=?");
    $s->execute([$cid]); $cm = $s->fetch();
    if ($cm && $cm['user_id'] == $me) {
        $pdo->prepare("DELETE FROM comments WHERE id=?")->execute([$cid]);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => 'Not allowed']);
    }
    exit;
}

if ($action === 'delete_post') {
    $pid = (int)($_POST['post_id'] ?? 0);
    $s = $pdo->prepare("SELECT * FROM posts WHERE id=?");
    $s->execute([$pid]); $post = $s->fetch();
    if ($post && $post['user_id'] == $me) {
        if ($post['image'] && file_exists(UPLOAD_PATH.'posts/'.$post['image'])) {
            @unlink(UPLOAD_PATH.'posts/'.$post['image']);
        }
        $pdo->prepare("DELETE FROM posts WHERE id=?")->execute([$pid]);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => 'Not allowed.']);
    }
    exit;
}

if ($action === 'friend_request') {
    $target = (int)($_POST['target_id'] ?? 0);
    if ($target == $me) { echo json_encode(['error' => 'Cannot add yourself.']); exit; }
    $status = getFriendshipStatus($pdo, $me, $target);
    if ($status === 'none') {
        $pdo->prepare("INSERT INTO friendships (sender_id,receiver_id) VALUES (?,?)")->execute([$me, $target]);
        $pdo->prepare("INSERT INTO notifications (user_id,from_user_id,type) VALUES (?,?,'friend_request')")->execute([$target, $me]);
        apexNotifyUser($target, 'friend_request', 0, 'New friend request', (int)$me);
        echo json_encode(['success' => true, 'new_status' => 'sent']);
    } elseif ($status === 'sent') {
        $pdo->prepare("DELETE FROM friendships WHERE sender_id=? AND receiver_id=?")->execute([$me, $target]);
        echo json_encode(['success' => true, 'new_status' => 'none']);
    } else {
        echo json_encode(['error' => 'Already friends.']);
    }
    exit;
}

if ($action === 'respond_friend') {
    $sender = (int)($_POST['sender_id'] ?? 0);
    $resp   = $_POST['response'] ?? '';
    if (!in_array($resp, ['accept', 'reject'], true)) {
        echo json_encode(['error' => 'Invalid response.']);
        exit;
    }
    $s = $pdo->prepare("SELECT * FROM friendships WHERE sender_id=? AND receiver_id=? AND status='pending'");
    $s->execute([$sender, $me]);
    if ($s->fetch()) {
        if ($resp === 'accept') {
            $pdo->prepare("UPDATE friendships SET status='accepted' WHERE sender_id=? AND receiver_id=?")->execute([$sender, $me]);
            $pdo->prepare("INSERT INTO notifications (user_id,from_user_id,type) VALUES (?,?,'friend_accepted')")->execute([$sender, $me]);
            apexNotifyUser((int)$sender, 'friend_accepted', 0, 'Friend request accepted', (int)$me);
        } else {
            $pdo->prepare("DELETE FROM friendships WHERE sender_id=? AND receiver_id=?")->execute([$sender, $me]);
        }
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => 'Not found.']);
    }
    exit;
}

if ($action === 'search') {
    $q = trim($_POST['q'] ?? '');
    if (!$q) { echo json_encode(['users' => []]); exit; }
    // Strip LIKE wildcards so user input cannot broaden the pattern
    $qSafe = preg_replace('/[%_\\\\]/', '', $q);
    if ($qSafe === '') { echo json_encode(['users' => []]); exit; }
    $s = $pdo->prepare("SELECT id,username,full_name,avatar_color,avatar
                        FROM users
                        WHERE (username LIKE ? OR full_name LIKE ?) AND is_blocked=0 AND is_admin=0
                        LIMIT 8");
    $like = '%'.$qSafe.'%';
    $s->execute([$like, $like]); $users = $s->fetchAll();
    foreach ($users as &$u) $u['initials'] = getInitials($u['username']);
    echo json_encode(['users' => $users]); exit;
}

if ($action === 'repost') {
    $origId  = (int)($_POST['post_id'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');

    $op = $pdo->prepare("SELECT * FROM posts WHERE id=? AND status='approved'");
    $op->execute([$origId]); $orig = $op->fetch();
    if (!$orig) { echo json_encode(['error' => 'Post not found']); exit; }

    $dup = $pdo->prepare("SELECT id FROM posts WHERE user_id=? AND repost_of=?");
    $dup->execute([$me, $origId]);
    if ($dup->fetch()) { echo json_encode(['error' => 'already_reposted', 'message' => 'You already reposted this.']); exit; }

    $textToCheck = $comment ?: $orig['content'];
    $analysis    = analyzeContent($textToCheck, $me, 'post');

    if (!empty($analysis['offline'])) {
        echo json_encode([
            'error'  => 'offline',
            'reason' => 'Detection system is currently inactive. Posting is temporarily unavailable. Please try again later.',
        ]); exit;
    }
    if (mlVerdictBlocks($analysis['verdict'] ?? '')) {
        echo json_encode(['error' => 'forbidden', 'reason' => $analysis['reason'] ?? 'Content flagged.']); exit;
    }

    $pdo->prepare("INSERT INTO posts (user_id,content,repost_of,status,ml_label,ml_prob,ml_category,ml_method) VALUES (?,?,?,'approved',?,?,?,?)")
        ->execute([$me, $comment, $origId, $analysis['label'] ?? 0, $analysis['harmful_prob'] ?? 0,
                   $analysis['category'] ?? 'safe', $analysis['method'] ?? 'sklearn']);
    $rid = (int)$pdo->lastInsertId();
    logAnalysis($pdo, $me, 'post', $rid, $textToCheck, $analysis);

    if ($orig['user_id'] != $me) {
        $pdo->prepare("INSERT INTO notifications (user_id,from_user_id,type,reference_id,message) VALUES (?,?,'comment',?,?)")
            ->execute([$orig['user_id'], $me, $rid, 'repost']);
    }

    echo json_encode(['success' => true, 'message' => 'Repost published!']); exit;
}

if ($action === 'report_content') {
    $ctype   = $_POST['content_type'] ?? '';
    $cid     = (int)($_POST['content_id'] ?? 0);
    $reasons = trim($_POST['reasons'] ?? $_POST['reason'] ?? 'other');
    $desc    = trim($_POST['description'] ?? '');

    if (!in_array($ctype, ['post','comment','profile']) || !$cid) {
        echo json_encode(['error' => 'Invalid data.']); exit;
    }

    $check = $pdo->prepare("SELECT id FROM reports WHERE reporter_id=? AND content_type=? AND content_id=?");
    $check->execute([$me, $ctype, $cid]);
    if ($check->fetch()) { echo json_encode(['error' => 'already_reported', 'message' => 'Already reported.']); exit; }

    $pdo->prepare("INSERT INTO reports (reporter_id,content_type,content_id,reason,description) VALUES (?,?,?,?,?)")
        ->execute([$me, $ctype, $cid, $reasons, $desc]);

    echo json_encode(['success' => true, 'message' => 'Report submitted.']); exit;
}

echo json_encode(['error' => 'Unknown action.']);
