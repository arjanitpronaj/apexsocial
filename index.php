<?php
require_once 'includes/config.php';
requireLogin();
$me = getCurrentUser($pdo);
if (!$me) {
    session_unset();
    session_destroy();
    redirect(BASE_URL.'/pages/login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['content'])) {
        $content = trim($_POST['content'] ?? '');
        $xssErr = $content ? apexValidateUserText($content, 1000) : null;
        if ($xssErr) {
            $_SESSION['post_notice'] = ['type'=>'forbidden','reason'=>$xssErr];
            redirect(BASE_URL.'/index.php');
        }
        if ($content) {
        $image = null;
        if (!empty($_FILES['post_image']['name']) && $_FILES['post_image']['error'] === 0) {
            $ext = strtolower(pathinfo($_FILES['post_image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','gif','webp','pdf'])) {
                $fname = uniqid().'.'.$ext;
                move_uploaded_file($_FILES['post_image']['tmp_name'], UPLOAD_PATH.'posts/'.$fname);
                $image = $fname;
            }
        }
        $moderation = moderateContent($content, $me['id'], 'post');
        $mlVerdict  = $moderation['verdict']      ?? 'FORBIDDEN';   // default deny
        $mlReason   = $moderation['reason']       ?? '';
        $mlProb     = $moderation['harmful_prob'] ?? 0;
        $mlCat      = $moderation['category']     ?? 'safe';
        $mlMethod   = $moderation['method']       ?? 'sklearn';
        $mlLabel    = mlVerdictBlocks($mlVerdict) ? 1 : 0;

        if (!empty($moderation['offline'])) {
            $_SESSION['post_notice'] = ['type'=>'offline','reason'=>'Detection system is currently inactive. Posting is temporarily unavailable. Please try again later.'];
            redirect(BASE_URL.'/index.php');
        }
        if ($mlVerdict === 'FORBIDDEN') {
            $_SESSION['post_notice'] = ['type'=>'forbidden','reason'=>$mlReason ?: 'Content flagged as harmful.'];
            redirect(BASE_URL.'/index.php');
        }

        $postStatus = 'approved';
        $pdo->prepare("INSERT INTO posts (user_id,content,image,status,ml_label,ml_prob,ml_category,ml_method) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$me['id'], $content, $image, $postStatus, $mlLabel, $mlProb, $mlCat, $mlMethod]);
        $pid = (int)$pdo->lastInsertId();
        logAnalysis($pdo, $me['id'], 'post', $pid, $content, ['label'=>$mlLabel,'harmful_prob'=>$mlProb,'confidence'=>100-$mlProb,'category'=>$mlCat,'method'=>$mlMethod]);

        $_SESSION['post_notice'] = ['type'=>'allowed'];
    }
    redirect(BASE_URL.'/index.php');
}

$postNotice = $_SESSION['post_notice'] ?? null;
unset($_SESSION['post_notice']);

$filter = $_GET['filter'] ?? 'all';
$baseSelect = "SELECT p.*, u.username, u.full_name, u.avatar_color, u.avatar, u.bio, u.location,
    COUNT(DISTINCT l.id) AS like_count, COUNT(DISTINCT c.id) AS comment_count,
    EXISTS(SELECT 1 FROM likes WHERE post_id=p.id AND user_id=?) AS user_liked,
    (SELECT COUNT(*) FROM posts rp WHERE rp.repost_of=p.id) AS repost_count,
    EXISTS(SELECT 1 FROM posts rp2 WHERE rp2.repost_of=p.id AND rp2.user_id=?) AS reposted_by_me,
    op.content AS orig_content, op.image AS orig_image,
    ou.username AS orig_username, ou.full_name AS orig_full_name, ou.avatar_color AS orig_avatar_color
    FROM posts p JOIN users u ON p.user_id=u.id
    LEFT JOIN likes l ON l.post_id=p.id
    LEFT JOIN comments c ON c.post_id=p.id AND c.status='approved'
    LEFT JOIN posts op ON p.repost_of=op.id
    LEFT JOIN users ou ON op.user_id=ou.id";

if ($filter === 'friends') {
    $stmt = $pdo->prepare("$baseSelect WHERE p.status='approved' AND u.is_blocked=0 AND (p.user_id=? OR p.user_id IN (SELECT CASE WHEN sender_id=? THEN receiver_id ELSE sender_id END FROM friendships WHERE (sender_id=? OR receiver_id=?) AND status='accepted')) GROUP BY p.id ORDER BY p.created_at DESC LIMIT 30");
    $stmt->execute([$me['id'],$me['id'],$me['id'],$me['id'],$me['id'],$me['id']]);
} else {
    $stmt = $pdo->prepare("$baseSelect WHERE p.status='approved' AND u.is_blocked=0 GROUP BY p.id ORDER BY p.created_at DESC LIMIT 30");
    $stmt->execute([$me['id'],$me['id']]);
}
$posts = $stmt->fetchAll();

$notifCount = getUnreadNotifCount($pdo, $me['id']);
$fc=$pdo->prepare("SELECT COUNT(*) FROM friendships WHERE (sender_id=? OR receiver_id=?) AND status='accepted'"); $fc->execute([$me['id'],$me['id']]); $friendCount=$fc->fetchColumn();
$pc=$pdo->prepare("SELECT COUNT(*) FROM posts WHERE user_id=? AND status='approved'"); $pc->execute([$me['id']]); $postCount=$pc->fetchColumn();
$suggest=$pdo->prepare("SELECT id,username,full_name,avatar_color,avatar FROM users WHERE id!=? AND is_admin=0 AND is_blocked=0 AND id NOT IN (SELECT CASE WHEN sender_id=? THEN receiver_id ELSE sender_id END FROM friendships WHERE sender_id=? OR receiver_id=?) LIMIT 5");
$suggest->execute([$me['id'],$me['id'],$me['id'],$me['id']]); $suggested=$suggest->fetchAll();
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ApexSocial — Feed</title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css?v=loop202605">
<style>
.repost-orig{background:var(--bg-muted);border:1px solid var(--border);border-left:3px solid var(--accent);border-radius:10px;padding:12px 14px;margin-bottom:14px;font-size:13px;color:var(--text2);max-height:120px;overflow:hidden}
.repost-orig .ro-author{font-weight:500;color:var(--text);font-size:12px;margin-bottom:4px}
.repost-banner{padding:10px 18px;font-size:12px;color:var(--text2);display:flex;align-items:center;gap:6px;border-bottom:1px solid var(--border)}
.repost-banner svg{width:14px;height:14px}
</style>
</head><body>
<?php $nav_active = 'feed'; require __DIR__ . '/includes/navbar.php'; ?>

<div class="feed-wrap shell-animate"><div class="feed-grid feed-grid--profile-right">
<div class="col-main">
<?php if($postNotice && $postNotice['type']==='forbidden'):?><div class="alert alert-error"><strong>Post Blocked</strong> — <?=htmlspecialchars($postNotice['reason']??'Content flagged as harmful.')?></div>
<?php elseif($postNotice && $postNotice['type']==='allowed'):?><div class="alert alert-success"><strong>Post published!</strong> Your content is now live.</div>
<?php elseif($postNotice && $postNotice['type']==='offline'):?><div class="alert alert-error"><strong>Detection System Inactive</strong> — <?=htmlspecialchars($postNotice['reason']??'Detection system is currently inactive. Posting is temporarily unavailable. Please try again later.')?></div><?php endif;?>

<div class="composer"><form method="POST" enctype="multipart/form-data" id="post-form"><div class="c-row"><?=avatarHtml($me,44,14)?><div class="list-grow">
<textarea name="content" class="c-ta" id="post-content" placeholder="What's on your mind, <?=htmlspecialchars($me['full_name']?:$me['username'])?>?" rows="6" maxlength="1000"></textarea>
<div id="ml-alert" class="ml-alert idle show"><div class="ml-alert-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg></div><div class="ml-alert-text"><div class="ml-alert-title" id="ml-title">Start typing to enable Post</div><div class="ml-alert-reason" id="ml-reason">AI check runs 10s after you stop typing (WebSocket).</div></div></div></div>
</div></div>
<div id="img-preview-wrap" style="display:none;margin-top:12px;position:relative;max-width:340px"></div>
<div class="c-footer"><div class="c-tools"><label class="c-tool" title="Add photo or PDF"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg><input type="file" name="post_image" id="img-input" accept="image/*,.pdf" style="display:none" onchange="previewImg(this)"></label></div>
<div class="top-actions"><span id="cc" class="char-count">0 / 1000</span><button type="submit" id="post-btn" class="btn btn-primary" disabled>Post</button></div></div></form></div>

<div class="feed-filters"><a href="?filter=all" class="feed-tab <?=$filter==='all'?'active':''?>">All Posts</a><a href="?filter=friends" class="feed-tab <?=$filter==='friends'?'active':''?>">Friends Only</a></div>

<?php if(empty($posts)):?><div class="empty"><div class="empty-icon">📭</div><h3>No posts yet</h3><p>Be the first to share something!</p></div>
<?php else: foreach($posts as $post):?>
<div class="post-card" data-post-id="<?=$post['id']?>" id="post-<?=$post['id']?>">
<?php if($post['repost_of'] && !empty($post['orig_username'])):?><div class="repost-banner"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg> <strong>@<?=htmlspecialchars($post['username'])?></strong> reposted</div><?php endif;?>
<div class="post-body">
<div class="post-head"><?php $pav=['username'=>$post['username'],'full_name'=>$post['full_name'],'avatar'=>$post['avatar'],'avatar_color'=>$post['avatar_color']]; echo avatarHtml($pav,42,14);?><div class="post-meta-block"><div class="post-titleline"><a href="<?=BASE_URL?>/pages/profile.php?user=<?=urlencode($post['username'])?>" class="post-aname"><?=htmlspecialchars($post['full_name']?:$post['username'])?></a><?php $roleLine = trim((string)($post['bio'] ?? '')); if ($roleLine === '') { $roleLine = trim((string)($post['location'] ?? '')); } if ($roleLine === '') { $roleLine = 'Member'; } if (function_exists('mb_strlen') && mb_strlen($roleLine) > 64) { $roleLine = mb_substr($roleLine, 0, 61) . '…'; } elseif (!function_exists('mb_strlen') && strlen($roleLine) > 64) { $roleLine = substr($roleLine, 0, 61) . '…'; } ?><span class="post-role-sep">·</span><span class="post-role"><?=htmlspecialchars($roleLine)?></span></div><div class="post-asub">@<?=htmlspecialchars($post['username'])?> · <?=timeAgo($post['created_at'])?></div></div><?php if($post['user_id']==$me['id']):?><button class="action-btn delete-btn" onclick="deletePost(<?=$post['id']?>)" title="Delete"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M9 6V4h6v2"/></svg></button><?php endif;?></div>

<?php if($post['repost_of'] && !empty($post['orig_username'])):?>
<?php if($post['content']):?><div class="post-text" style="font-size:13px;color:var(--text2);margin-bottom:10px"><?=nl2br(htmlspecialchars($post['content']))?></div><?php endif;?>
<div class="repost-orig"><div class="ro-author">@<?=htmlspecialchars($post['orig_username'])?></div><?=htmlspecialchars(mb_substr($post['orig_content'],0,300))?></div>
<?php else:?>
<div class="post-text"><?=nl2br(htmlspecialchars($post['content']))?></div>
<?php if($post['image']):?>
<?php $ext=strtolower(pathinfo($post['image'],PATHINFO_EXTENSION)); if($ext==='pdf'):?>
<div class="pdf-embed-wrap">
  <embed src="uploads/posts/<?=htmlspecialchars($post['image'])?>#view=FitH" type="application/pdf" class="pdf-embed">
  <div class="pdf-embed-bar">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
    <span>PDF Document</span>
    <a href="uploads/posts/<?=htmlspecialchars($post['image'])?>" download class="pdf-download-btn" target="_blank">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Download
    </a>
  </div>
</div>
<?php else:?>
<img class="post-img" src="uploads/posts/<?=htmlspecialchars($post['image'])?>" alt="">
<?php endif;?>
<?php endif;?>
<?php endif;?>

<hr class="post-divider">
<div class="post-foot">
<button class="action-btn like-btn <?=$post['user_liked']?'liked':''?>" onclick="toggleLike(<?=$post['id']?>,this)"><svg viewBox="0 0 24 24" fill="<?=$post['user_liked']?'currentColor':'none'?>" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg><span class="like-count"><?=$post['like_count']?></span></button>
<button class="action-btn" onclick="toggleComments(<?=$post['id']?>)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg><span><?=$post['comment_count']?></span></button>
<?php if(!$post['repost_of']):?><button class="action-btn <?=$post['reposted_by_me']?'liked':''?>" onclick="openRepost(<?=$post['id']?>)" title="Repost"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg><span><?=$post['repost_count']?></span></button><?php endif;?>
<?php if($post['user_id'] != $me['id']): ?><button class="action-btn" onclick="openReport('post', <?=$post['id']?>)" title="Report"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg></button><?php endif; ?>
</div></div>
<div class="comments-section" id="comments-<?=$post['id']?>"><div class="comments-list" id="comments-list-<?=$post['id']?>"></div>
<div class="comment-form"><?=avatarHtml($me,30,11)?><input type="text" class="comment-input" id="comment-input-<?=$post['id']?>" placeholder="Write a comment..." maxlength="500" onkeydown="if(event.key==='Enter'){event.preventDefault();addComment(<?=$post['id']?>)}"><button class="btn btn-primary btn-sm" onclick="addComment(<?=$post['id']?>)">Send</button></div></div>
</div>
<?php endforeach; endif;?>
</div>

<div class="col-right">
<div class="panel-card trends-card">
<div class="panel-title">Trending</div>
<div class="trend-item"><div><div class="trend-tag">#apexlaunch</div><div class="trend-count">1.2k posts</div></div></div>
<div class="trend-item"><div><div class="trend-tag">#moderation</div><div class="trend-count">Safety</div></div></div>
<div class="trend-item"><div><div class="trend-tag">#community</div><div class="trend-count">Growing</div></div></div>
</div>
<div class="suggest-card"><div class="suggest-hd">Suggested People</div>
<?php if(empty($suggested)):?><div class="list-empty">No suggestions</div>
<?php else: foreach($suggested as $u):?><div class="suggest-row"><?=avatarHtml($u,36,12)?><div class="list-grow"><div class="suggest-name"><?=htmlspecialchars($u['full_name']?:$u['username'])?></div><div class="suggest-handle">@<?=htmlspecialchars($u['username'])?></div></div><button class="add-btn" onclick="addFriend(<?=$u['id']?>,this)">Add</button></div><?php endforeach; endif;?></div></div>

<div class="col-left">
<div class="pw"><div class="pw-cover"></div><div class="pw-body"><div class="pw-av"><a href="<?=BASE_URL?>/pages/profile.php"><?=avatarHtml($me,58,18)?></a></div><div class="pw-name"><?=htmlspecialchars($me['full_name']?:$me['username'])?></div><div class="pw-handle">@<?=htmlspecialchars($me['username'])?></div><div class="pw-stats"><div class="pw-stat"><div class="pw-stat-v"><?=$postCount?></div><div class="pw-stat-l">Posts</div></div><div class="pw-stat"><div class="pw-stat-v"><?=$friendCount?></div><div class="pw-stat-l">Friends</div></div></div></div></div>
<div class="s-label">Communities</div>
<div class="communities-list">
<a href="<?=BASE_URL?>/pages/explore.php" class="community-row"><span class="community-dot" style="background:#7c3aed"></span><span>Discover people &amp; topics</span></a>
</div>
</div>

</div></div>

<div class="modal-overlay" id="repost-modal"><div class="modal-box"><div class="modal-title">Repost<button class="modal-close" onclick="closeRepost()">✕</button></div>
<div class="repost-orig" id="repost-orig-preview"></div>
<textarea class="form-input" id="repost-comment" rows="3" maxlength="500" placeholder="Add your thoughts (optional)..." style="margin-bottom:14px"></textarea>
<input type="hidden" id="repost-post-id">
<div class="top-actions"><button class="btn btn-ghost" onclick="closeRepost()">Cancel</button><button class="btn btn-primary" onclick="submitRepost()">Repost</button></div></div></div>

<div class="modal-overlay" id="report-modal"><div class="modal-box"><div class="modal-title">Report Content<button class="modal-close" onclick="closeReport()">✕</button></div>
<p class="meta-line" style="margin-bottom:12px">Why are you reporting this?</p>
<div class="report-reasons"><label class="report-opt"><input type="checkbox" name="rr" value="hate_speech"> Hate speech</label><label class="report-opt"><input type="checkbox" name="rr" value="harassment"> Harassment</label><label class="report-opt"><input type="checkbox" name="rr" value="spam"> Spam</label><label class="report-opt"><input type="checkbox" name="rr" value="inappropriate"> Inappropriate</label></div>
<textarea class="form-input" id="report-desc" rows="2" maxlength="500" placeholder="Details (optional)..." style="margin-bottom:14px"></textarea>
<input type="hidden" id="report-type"><input type="hidden" id="report-id">
<div class="top-actions"><button class="btn btn-ghost" onclick="closeReport()">Cancel</button><button class="btn btn-primary" onclick="submitReport()">Submit</button></div></div></div>

<nav class="mobile-nav">
<a href="<?=BASE_URL?>/index.php" class="mobile-nav-item active">
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
<span>Feed</span>
</a>
<a href="<?=BASE_URL?>/pages/explore.php" class="mobile-nav-item">
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
<span>Explore</span>
</a>
<a href="<?=BASE_URL?>/pages/friends.php" class="mobile-nav-item">
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
<span>Friends</span>
</a>
<a href="<?=BASE_URL?>/pages/notifications.php" class="mobile-nav-item" style="position:relative">
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
<?php if($notifCount>0):?><span class="mobile-nav-notif"><?=$notifCount?></span><?php endif;?>
<span>Messages</span>
</a>
<a href="<?=BASE_URL?>/pages/profile.php" class="mobile-nav-item">
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
<span>Profile</span>
</a>
</nav>

<script src="assets/js/app.js?v=rt8"></script>
</body></html>
