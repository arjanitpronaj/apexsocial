<?php
require_once '../includes/config.php';
requireLogin();
$me = getCurrentUser($pdo);
if (!$me) {
    session_unset();
    session_destroy();
    redirect(BASE_URL . '/pages/login.php');
}

$username = $_GET['user'] ?? $me['username'];
$s = $pdo->prepare("SELECT * FROM users WHERE username=? AND is_admin=0");
$s->execute([$username]);
$user = $s->fetch();
if (!$user) { redirect(BASE_URL.'/index.php'); }

$isOwnProfile = ($user['id'] == $me['id']);

$postCount = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE user_id=? AND status='approved'");
$postCount->execute([$user['id']]);
$postCount = $postCount->fetchColumn();

$friendCount = $pdo->prepare("SELECT COUNT(*) FROM friendships WHERE (sender_id=? OR receiver_id=?) AND status='accepted'");
$friendCount->execute([$user['id'], $user['id']]);
$friendCount = $friendCount->fetchColumn();

$posts = $pdo->prepare("
    SELECT p.*,
        COUNT(DISTINCT l.id) AS like_count,
        COUNT(DISTINCT c.id) AS comment_count,
        EXISTS(SELECT 1 FROM likes WHERE post_id=p.id AND user_id=?) AS user_liked
    FROM posts p
    LEFT JOIN likes l ON l.post_id=p.id
    LEFT JOIN comments c ON c.post_id=p.id AND c.status='approved'
    WHERE p.user_id=? AND p.status='approved'
    GROUP BY p.id
    ORDER BY p.created_at DESC
");
$posts->execute([$me['id'], $user['id']]);
$posts = $posts->fetchAll();

$friendStatus = getFriendshipStatus($pdo, $me['id'], $user['id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= htmlspecialchars($user['full_name'] ?: $user['username']) ?> — ApexSocial</title>
    <link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css?v=loop202605">
</head>
<body>
<?php $nav_active = 'profile'; require APEX_ROOT . '/includes/navbar.php'; ?>

<div class="feed-wrap page-shell shell-animate">

        <div class="card profile-hero">
        <div class="profile-cover"></div>
        <div class="profile-body">
            <div class="profile-header">
                <div class="profile-avatar-wrap">
                    <?= avatarHtml($user, 96, 30) ?>
                </div>
                <div class="profile-meta">
                    <h1 class="profile-name"><?= htmlspecialchars($user['full_name'] ?: $user['username']) ?></h1>
                    <div class="profile-handle">@<?= htmlspecialchars($user['username']) ?></div>
                </div>
                <?php if (!$isOwnProfile): ?>
                <div class="profile-actions">
                    <?php if ($friendStatus === 'friends'): ?>
                        <button class="btn btn-ghost btn-sm">✓ Friends</button>
                    <?php elseif ($friendStatus === 'sent'): ?>
                        <button class="btn btn-ghost btn-sm">Request Sent</button>
                    <?php else: ?>
                        <button class="btn btn-primary btn-sm" onclick="addFriend(<?= $user['id'] ?>, this)">Add Friend</button>
                    <?php endif; ?>
                    <button class="btn btn-ghost btn-sm" onclick="openReportUser(<?= $user['id'] ?>)">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>
                        Report
                    </button>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($user['bio']): ?>
            <p class="profile-bio"><?= htmlspecialchars($user['bio']) ?></p>
            <?php endif; ?>

            <div class="profile-stats">
                <div class="profile-stat"><strong><?= $postCount ?></strong> <span>posts</span></div>
                <div class="profile-stat"><strong><?= $friendCount ?></strong> <span>friends</span></div>
                <?php if ($user['location']): ?>
                <div class="profile-location">📍 <?= htmlspecialchars($user['location']) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

        <h2 class="section-title">Posts</h2>
    <?php if (empty($posts)): ?>
        <div class="empty">
            <div class="empty-icon">📝</div>
            <h3>No posts yet</h3>
            <p><?= $isOwnProfile ? 'Share your first post!' : 'Nothing to see yet.' ?></p>
        </div>
    <?php else: foreach ($posts as $post): ?>
    <div class="post-card" data-post-id="<?= $post['id'] ?>">
        <div class="post-body">
            <div class="post-head">
                <div class="post-author">
                    <?= avatarHtml($user, 40, 13) ?>
                    <div>
                        <div class="post-aname"><?= htmlspecialchars($user['full_name'] ?: $user['username']) ?></div>
                        <div class="post-asub">@<?= htmlspecialchars($user['username']) ?> · <?= timeAgo($post['created_at']) ?></div>
                    </div>
                </div>
            </div>
            <div class="post-text"><?= nl2br(htmlspecialchars($post['content'])) ?></div>
            <?php if ($post['image']): ?>
            <?php $pext = strtolower(pathinfo($post['image'], PATHINFO_EXTENSION)); if ($pext === 'pdf'): ?>
            <div class="pdf-embed-wrap">
                <embed src="../uploads/posts/<?= htmlspecialchars($post['image']) ?>#view=FitH" type="application/pdf" class="pdf-embed">
                <div class="pdf-embed-bar">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    <span>PDF Document</span>
                    <a href="../uploads/posts/<?= htmlspecialchars($post['image']) ?>" download class="pdf-download-btn" target="_blank">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        Download
                    </a>
                </div>
            </div>
            <?php else: ?>
                <img class="post-img" src="../uploads/posts/<?= htmlspecialchars($post['image']) ?>" alt="">
            <?php endif; ?>
            <?php endif; ?>
            <div class="post-foot">
                <button type="button" class="action-btn like-btn <?= $post['user_liked'] ? 'liked' : '' ?>" onclick="toggleLike(<?= (int) $post['id'] ?>, this)">
                    <svg viewBox="0 0 24 24" fill="<?= $post['user_liked'] ? 'currentColor' : 'none' ?>" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                    <span class="like-count"><?= (int) $post['like_count'] ?></span>
                </button>
                <button type="button" class="action-btn" onclick="toggleComments(<?= (int) $post['id'] ?>)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    <span><?= (int) $post['comment_count'] ?></span>
                </button>
            </div>
        </div>
        <div class="comments-section" id="comments-<?= (int) $post['id'] ?>">
            <div class="comments-list" id="comments-list-<?= (int) $post['id'] ?>"></div>
            <div class="comment-form"><?= avatarHtml($me, 30, 11) ?>
                <input type="text" class="comment-input" id="comment-input-<?= (int) $post['id'] ?>" placeholder="Write a comment..." maxlength="500" onkeydown="if(event.key==='Enter'){event.preventDefault();addComment(<?= (int) $post['id'] ?>)}">
                <button type="button" class="btn btn-primary btn-sm" onclick="addComment(<?= (int) $post['id'] ?>)">Send</button>
            </div>
        </div>
    </div>
    <?php endforeach; endif; ?>
</div>

<div class="modal-overlay" id="report-user-modal">
<div class="modal-box">
<div class="modal-title">Report Account<button class="modal-close" onclick="closeReportUser()">✕</button></div>
<p class="meta-line" style="margin-bottom:14px">Why are you reporting this account?</p>
<div class="report-reasons" id="report-user-reasons">
<label class="report-opt"><input type="checkbox" name="urr" value="spam"> Spam or fake account</label>
<label class="report-opt"><input type="checkbox" name="urr" value="harassment"> Harassment or bullying</label>
<label class="report-opt"><input type="checkbox" name="urr" value="hate_speech"> Hate speech</label>
<label class="report-opt"><input type="checkbox" name="urr" value="impersonation"> Impersonation</label>
<label class="report-opt"><input type="checkbox" name="urr" value="other"> Other</label>
</div>
<textarea id="report-user-desc" class="form-input" style="min-height:72px;margin-bottom:14px" placeholder="Additional details (optional)..." maxlength="500"></textarea>
<input type="hidden" id="report-user-id">
<div class="top-actions">
<button class="btn btn-ghost" onclick="closeReportUser()">Cancel</button>
<button class="btn btn-primary" onclick="submitReportUser()">Submit Report</button>
</div>
</div>
</div>

<script src="../assets/js/app.js?v=loop202605"></script>
<script>
const API = typeof window.APEX_API !== 'undefined' ? window.APEX_API : '../includes/ajax.php';

async function handleAjaxJson(r) {
    const raw = await r.text();
    let d;
    try {
        d = JSON.parse(raw);
    } catch (e) {
        return { _parseError: true };
    }
    if (d.redirect && (d.error === 'banned' || d.error === 'session_invalid')) {
        window.location.href = d.redirect;
        return { _redirect: true };
    }
    return d;
}

function openReportUser(uid) {
    document.getElementById('report-user-id').value = uid;
    document.querySelectorAll('[name="urr"]').forEach(cb => cb.checked = false);
    document.getElementById('report-user-desc').value = '';
    const m = document.getElementById('report-user-modal');
    m.classList.add('open');
}
function closeReportUser() {
    document.getElementById('report-user-modal').classList.remove('open');
}
async function submitReportUser() {
    const uid = document.getElementById('report-user-id').value;
    const reasons = Array.from(document.querySelectorAll('[name="urr"]:checked')).map(cb => cb.value);
    const desc = document.getElementById('report-user-desc').value.trim();
    if (!reasons.length) { alert('Please select at least one reason.'); return; }
    const fd = new FormData();
    fd.append('action','report_content');
    fd.append('content_type','profile');
    fd.append('content_id', uid);
    fd.append('reasons', reasons.join(','));
    fd.append('description', desc);
    try {
        const r = await fetch(API, { method: 'POST', body: fd });
        const d = await handleAjaxJson(r);
        if (d._redirect || d._parseError) { closeReportUser(); return; }
        closeReportUser();
        if (d.error === 'already_reported') { alert('You already reported this account.'); return; }
        alert(d.message || 'Report submitted. Thank you.');
    } catch (e) { closeReportUser(); }
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeReportUser(); });
</script>
</body>
</html>
