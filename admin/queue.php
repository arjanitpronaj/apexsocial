<?php
require_once '../includes/config.php';
requireAdmin();
$PAGE = 'queue';
require_once 'inc_sidebar.php';

$adminId = $_SESSION['user_id'];

function apexMlFeedback(string $text, string $adminAction, string $mlCategory): void
{
    $url = 'http://localhost:5000/feedback';
    $payload = json_encode([
        'text' => $text,
        'admin_action' => $adminAction,
        'category' => $mlCategory ?: 'safe',
    ], JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        return;
    }
    $ch = curl_init($url);
    if ($ch === false) {
        return;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT_MS => 1000,
        CURLOPT_CONNECTTIMEOUT_MS => 1000,
    ]);
    @curl_exec($ch);
    curl_close($ch);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = (int)($_POST['id'] ?? 0);
    $type   = $_POST['type']   ?? '';
    $action = $_POST['action'] ?? '';
    $reason = trim($_POST['reason'] ?? '');

    if ($id && in_array($type, ['post','comment']) && in_array($action, ['approve','reject'])) {
        $newStatus = $action === 'approve' ? 'approved' : 'rejected';
        $itemText = '';
        $itemCategory = 'safe';

        if ($type === 'post') {
            $pre = $pdo->prepare("SELECT content, ml_category FROM posts WHERE id=?");
            $pre->execute([$id]);
            if ($row = $pre->fetch()) {
                $itemText = (string)($row['content'] ?? '');
                $itemCategory = (string)($row['ml_category'] ?? 'safe');
            }
            $pdo->prepare("UPDATE posts SET status=?, reviewed_by=?, reviewed_at=NOW(), reject_reason=? WHERE id=?")
                ->execute([$newStatus, $adminId, $reason ?: null, $id]);

            // Notify user
            $p = $pdo->prepare("SELECT user_id FROM posts WHERE id=?");
            $p->execute([$id]);
            if ($po = $p->fetch()) {
                $notifType = $action === 'approve' ? 'post_approved' : 'post_rejected';
                $msg = $action === 'approve' ? 'Your post was approved.' : ('Your post was rejected.' . ($reason ? ' ' . $reason : ''));
                $pdo->prepare("INSERT INTO notifications (user_id,from_user_id,type,reference_id,message) VALUES (?,?,?,?,?)")
                    ->execute([$po['user_id'], $adminId, $notifType, $id, $reason ?: null]);
                apexNotifyUser((int)$po['user_id'], $notifType, $id, $msg, (int)$adminId);
                apexModerationResult((int)$po['user_id'], $action, 'post', $id, $reason, $msg);
            }
        } else {
            $pre = $pdo->prepare("SELECT content, ml_category FROM comments WHERE id=?");
            $pre->execute([$id]);
            if ($row = $pre->fetch()) {
                $itemText = (string)($row['content'] ?? '');
                $itemCategory = (string)($row['ml_category'] ?? 'safe');
            }
            $pdo->prepare("UPDATE comments SET status=?, reviewed_by=?, reviewed_at=NOW(), reject_reason=? WHERE id=?")
                ->execute([$newStatus, $adminId, $reason ?: null, $id]);

            $c = $pdo->prepare("SELECT user_id FROM comments WHERE id=?");
            $c->execute([$id]);
            if ($co = $c->fetch()) {
                $notifType = $action === 'approve' ? 'comment_approved' : 'comment_rejected';
                $msg = $action === 'approve' ? 'Your comment was approved.' : ('Your comment was rejected.' . ($reason ? ' ' . $reason : ''));
                $pdo->prepare("INSERT INTO notifications (user_id,from_user_id,type,reference_id,message) VALUES (?,?,?,?,?)")
                    ->execute([$co['user_id'], $adminId, $notifType, $id, $reason ?: null]);
                apexNotifyUser((int)$co['user_id'], $notifType, $id, $msg, (int)$adminId);
                apexModerationResult((int)$co['user_id'], $action, 'comment', $id, $reason, $msg);
            }
        }
        apexMlFeedback($itemText, $action, $itemCategory);
        apexQueueUpdate();
    }
    redirect('queue.php');
}

$posts = $pdo->query("
    SELECT p.*, u.username, u.full_name, u.avatar_color
    FROM posts p JOIN users u ON p.user_id = u.id
    WHERE p.status='pending'
    ORDER BY p.ml_prob DESC, p.created_at ASC
")->fetchAll();

$comments = $pdo->query("
    SELECT c.*, u.username, u.full_name, u.avatar_color
    FROM comments c JOIN users u ON c.user_id = u.id
    WHERE c.status='pending'
    ORDER BY c.ml_prob DESC, c.created_at ASC
")->fetchAll();

$total = count($posts) + count($comments);

$stats = [
    'pending_posts'    => count($posts),
    'approved_posts'   => (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE status='approved'")->fetchColumn(),
    'rejected_posts'   => (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE status='rejected'")->fetchColumn(),
    'pending_comments' => count($comments),
];

echo adminHead('Moderation Queue', false);
echo adminSidebar();
?>
<main class="main">

<div class="ph">
    <div class="ph-left">
        <h1>Moderation Queue</h1>
        <p><?= $total ?> item<?= $total != 1 ? 's' : '' ?> awaiting review · sorted by ML risk</p>
    </div>
    <button onclick="location.reload()" class="btn btn-ghost btn-sm">↻ Refresh</button>
</div>

<div class="sg stat-4">
    <div class="sc">
        <div class="sc-accent sc-amber"></div>
        <div class="sc-icon sc-amber">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        </div>
        <div class="sc-val"><?= $stats['pending_posts'] ?></div>
        <div class="sc-lbl">Pending Posts</div>
    </div>
    <div class="sc">
        <div class="sc-accent sc-green"></div>
        <div class="sc-icon sc-green">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <div class="sc-val"><?= $stats['approved_posts'] ?></div>
        <div class="sc-lbl">Approved Posts</div>
    </div>
    <div class="sc">
        <div class="sc-accent sc-red"></div>
        <div class="sc-icon sc-red">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </div>
        <div class="sc-val"><?= $stats['rejected_posts'] ?></div>
        <div class="sc-lbl">Rejected Posts</div>
    </div>
    <div class="sc">
        <div class="sc-accent sc-amber"></div>
        <div class="sc-icon sc-amber">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        </div>
        <div class="sc-val"><?= $stats['pending_comments'] ?></div>
        <div class="sc-lbl">Pending Comments</div>
    </div>
</div>

<?php if ($total === 0): ?>

<div class="empty-state">
    <div class="icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
    </div>
    <h3>All clear!</h3>
    <p>The moderation queue is empty. Great work.</p>
</div>

<?php else: ?>

<?php if (!empty($posts)): ?>
<div class="card">
    <div class="card-hd">
        <h3>Pending Posts (<?= count($posts) ?>)</h3>
        <span class="muted-sm">Sorted by ML risk ↓</span>
    </div>
    <?php foreach ($posts as $p):
        $prob = round((float)($p['ml_prob'] ?? 0), 1);
        $cat  = $p['ml_category'] ?? 'safe';
        $isHarmful = $p['ml_label'] == 1;
    ?>
    <div class="queue-item">
        <div class="queue-head">
            <div class="av av-40" style="background:<?= $p['avatar_color'] ?>">
                <?= strtoupper(substr($p['username'], 0, 1)) ?>
            </div>
            <div class="queue-meta">
                <div class="queue-username">@<?= htmlspecialchars($p['username']) ?></div>
                <div class="queue-time"><?= timeAgo($p['created_at']) ?> · Post #<?= $p['id'] ?></div>
            </div>
            <div class="ml-meta">
                <?php if ($isHarmful): ?>
                    <span class="risk-high">ML Risk: <?= $prob ?>%</span>
                    <span class="badge badge-r"><?= htmlspecialchars($cat) ?></span>
                <?php else: ?>
                    <span class="risk-safe">Safe (<?= $prob ?>%)</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="queue-text"><?= htmlspecialchars($p['content']) ?></div>

        <?php if ($p['image']): ?>
            <img src="../uploads/posts/<?= htmlspecialchars($p['image']) ?>" style="max-width:280px;border-radius:10px;margin-bottom:12px;border:1px solid var(--border)">
        <?php endif; ?>

        <div class="queue-actions">
            <form method="POST">
                <input type="hidden" name="id"     value="<?= $p['id'] ?>">
                <input type="hidden" name="type"   value="post">
                <input type="hidden" name="action" value="approve">
                <button type="submit" class="btn btn-success btn-sm">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    Approve
                </button>
            </form>
            <form method="POST" class="inline-form">
                <input type="hidden" name="id"     value="<?= $p['id'] ?>">
                <input type="hidden" name="type"   value="post">
                <input type="hidden" name="action" value="reject">
                <input type="text" name="reason" class="form-input input-sm" placeholder="Rejection reason (optional)">
                <button type="submit" class="btn btn-danger btn-sm">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    Reject
                </button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!empty($comments)): ?>
<div class="card">
    <div class="card-hd">
        <h3>Pending Comments (<?= count($comments) ?>)</h3>
        <span class="muted-sm">Sorted by ML risk ↓</span>
    </div>
    <?php foreach ($comments as $c):
        $prob = round((float)($c['ml_prob'] ?? 0), 1);
        $isHarmful = $c['ml_label'] == 1;
    ?>
    <div class="queue-item">
        <div class="queue-head">
            <div class="av av-34" style="background:<?= $c['avatar_color'] ?>">
                <?= strtoupper(substr($c['username'], 0, 1)) ?>
            </div>
            <div class="queue-meta">
                <div class="queue-username">@<?= htmlspecialchars($c['username']) ?></div>
                <div class="queue-time"><?= timeAgo($c['created_at']) ?> · Comment #<?= $c['id'] ?> · on Post #<?= $c['post_id'] ?></div>
            </div>
            <div class="ml-meta">
                <?php if ($isHarmful): ?>
                    <span class="risk-high">ML Risk: <?= $prob ?>%</span>
                <?php else: ?>
                    <span class="risk-safe">Safe (<?= $prob ?>%)</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="queue-text"><?= htmlspecialchars($c['content']) ?></div>

        <div class="queue-actions">
            <form method="POST">
                <input type="hidden" name="id"     value="<?= $c['id'] ?>">
                <input type="hidden" name="type"   value="comment">
                <input type="hidden" name="action" value="approve">
                <button type="submit" class="btn btn-success btn-sm">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    Approve
                </button>
            </form>
            <form method="POST">
                <input type="hidden" name="id"     value="<?= $c['id'] ?>">
                <input type="hidden" name="type"   value="comment">
                <input type="hidden" name="action" value="reject">
                <button type="submit" class="btn btn-danger btn-sm">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    Reject
                </button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php endif; ?>

</main>
<?php echo adminFoot(); ?>
