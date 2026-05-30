<?php
require_once '../includes/config.php';
requireLogin();
$me = getCurrentUser($pdo);
if (!$me) {
    session_unset();
    session_destroy();
    redirect(BASE_URL . '/pages/login.php');
}
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $fullName = trim($_POST['full_name'] ?? '');
        $bio      = trim($_POST['bio'] ?? '');
        $location = trim($_POST['location'] ?? '');

        if ($fullName && strlen($fullName) >= 2) {
            $pdo->prepare("UPDATE users SET full_name=?, bio=?, location=? WHERE id=?")
                ->execute([$fullName, $bio, $location, $me['id']]);
            $success = 'Profile updated successfully.';
            $me = getCurrentUser($pdo);
        } else {
            $error = 'Full name must be at least 2 characters.';
        }
    }

    if ($action === 'upload_avatar' && !empty($_FILES['avatar']['name'])) {
        $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
            if ($_FILES['avatar']['size'] <= 5 * 1048576) { // 5MB max
                // Delete old avatar
                if ($me['avatar'] && file_exists(UPLOAD_PATH.'avatars/'.$me['avatar'])) {
                    @unlink(UPLOAD_PATH.'avatars/'.$me['avatar']);
                }
                $fname = $me['id'] . '_' . uniqid() . '.' . $ext;
                move_uploaded_file($_FILES['avatar']['tmp_name'], UPLOAD_PATH.'avatars/'.$fname);
                $pdo->prepare("UPDATE users SET avatar=? WHERE id=?")->execute([$fname, $me['id']]);
                $success = 'Avatar updated.';
                $me = getCurrentUser($pdo);
            } else {
                $error = 'Image must be under 5MB.';
            }
        } else {
            $error = 'Invalid image format. Allowed: JPG, PNG, GIF, WebP.';
        }
    }

    if ($action === 'remove_avatar') {
        if ($me['avatar'] && file_exists(UPLOAD_PATH.'avatars/'.$me['avatar'])) {
            @unlink(UPLOAD_PATH.'avatars/'.$me['avatar']);
        }
        $pdo->prepare("UPDATE users SET avatar=NULL WHERE id=?")->execute([$me['id']]);
        $success = 'Avatar removed.';
        $me = getCurrentUser($pdo);
    }

    if ($action === 'change_email') {
        $newEmail = trim($_POST['new_email'] ?? '');
        $password = $_POST['confirm_password'] ?? '';

        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email address.';
        } elseif ($me['password'] !== $password) {
            $error = 'Incorrect password.';
        } else {
            $exists = $pdo->prepare("SELECT id FROM users WHERE email=? AND id!=?");
            $exists->execute([$newEmail, $me['id']]);
            if ($exists->fetch()) {
                $error = 'Email already in use.';
            } else {
                $pdo->prepare("UPDATE users SET email=? WHERE id=?")->execute([$newEmail, $me['id']]);
                $_SESSION['email'] = $newEmail;
                $success = 'Email updated.';
                $me = getCurrentUser($pdo);
            }
        }
    }

    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $newPass = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_new_password'] ?? '';

        if ($me['password'] !== $current) {
            $error = 'Current password is incorrect.';
        } elseif (strlen($newPass) < 6) {
            $error = 'New password must be at least 6 characters.';
        } elseif ($newPass !== $confirm) {
            $error = 'New passwords do not match.';
        } else {
            // Plain text as required
            $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([$newPass, $me['id']]);
            $success = 'Password updated.';
        }
    }

    if ($action === 'change_username') {
        $newUsername = trim($_POST['new_username'] ?? '');
        $password   = $_POST['confirm_password_uname'] ?? '';

        if (strlen($newUsername) < 3) {
            $error = 'Username must be at least 3 characters.';
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $newUsername)) {
            $error = 'Username can only contain letters, numbers, and underscores.';
        } elseif ($me['password'] !== $password) {
            $error = 'Incorrect password.';
        } else {
            $exists = $pdo->prepare("SELECT id FROM users WHERE username=? AND id!=?");
            $exists->execute([$newUsername, $me['id']]);
            if ($exists->fetch()) {
                $error = 'Username already taken.';
            } else {
                $pdo->prepare("UPDATE users SET username=? WHERE id=?")->execute([$newUsername, $me['id']]);
                $_SESSION['username'] = $newUsername;
                $success = 'Username updated.';
                $me = getCurrentUser($pdo);
            }
        }
    }
}

$tab = $_GET['tab'] ?? 'profile';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Account Settings — ApexSocial</title>
    <link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css?v=loop202605">
</head>
<body>
<?php $nav_active = 'settings'; require APEX_ROOT . '/includes/navbar.php'; ?>

<div class="feed-wrap shell-animate" style="max-width:960px">
    <h1 class="page-title">Account Settings</h1>

    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="settings-layout">
                <div class="settings-nav">
            <a href="?tab=profile" class="<?= $tab === 'profile' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                Profile
            </a>
            <a href="?tab=avatar" class="<?= $tab === 'avatar' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                Avatar
            </a>
            <a href="?tab=account" class="<?= $tab === 'account' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 1v2m0 18v2M4.22 4.22l1.42 1.42m12.72 12.72l1.42 1.42M1 12h2m18 0h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
                Account
            </a>
            <a href="?tab=security" class="<?= $tab === 'security' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                Security
            </a>
        </div>

                <div>
            <?php if ($tab === 'profile'): ?>
            <div class="settings-card">
                <div class="settings-header">
                    <h2>Profile Information</h2>
                    <p>Update your name, bio, and location</p>
                </div>
                <div class="settings-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_profile">
                        <div class="settings-row">
                            <div class="form-group">
                                <label class="form-label">Full Name</label>
                                <input type="text" name="full_name" class="form-input" value="<?= htmlspecialchars($me['full_name']) ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Location</label>
                                <input type="text" name="location" class="form-input" value="<?= htmlspecialchars($me['location'] ?? '') ?>" placeholder="City, Country">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Bio</label>
                            <textarea name="bio" class="form-input" rows="3" placeholder="Tell us about yourself..."><?= htmlspecialchars($me['bio'] ?? '') ?></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </form>
                </div>
            </div>

            <?php elseif ($tab === 'avatar'): ?>
            <div class="settings-card">
                <div class="settings-header">
                    <h2>Profile Picture</h2>
                    <p>Upload or change your avatar</p>
                </div>
                <div class="settings-body">
                    <div class="avatar-section">
                        <?php if ($me['avatar'] && file_exists(UPLOAD_PATH.'avatars/'.$me['avatar'])): ?>
                            <img src="../uploads/avatars/<?= htmlspecialchars($me['avatar']) ?>" class="avatar-big" alt="Avatar">
                        <?php else: ?>
                            <div class="avatar-big-letter" style="background:<?= $me['avatar_color'] ?>">
                                <?= strtoupper(substr($me['username'], 0, 1)) ?>
                            </div>
                        <?php endif; ?>
                        <div>
                            <div style="font-weight:500;font-size:15px"><?= htmlspecialchars($me['full_name'] ?: $me['username']) ?></div>
                            <div class="meta-sub">@<?= htmlspecialchars($me['username']) ?></div>
                        </div>
                    </div>
                    <form method="POST" enctype="multipart/form-data" style="margin-bottom:12px">
                        <input type="hidden" name="action" value="upload_avatar">
                        <div class="form-group">
                            <label class="form-label">Upload New Avatar</label>
                            <input type="file" name="avatar" accept="image/*" class="form-input" required>
                            <div class="meta-sub" style="margin-top:6px">Max 5MB. JPG, PNG, GIF, or WebP.</div>
                        </div>
                        <button type="submit" class="btn btn-primary">Upload</button>
                    </form>
                    <?php if ($me['avatar']): ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="remove_avatar">
                        <button type="submit" class="btn btn-ghost" onclick="return confirm('Remove your avatar?')">Remove Avatar</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <?php elseif ($tab === 'account'): ?>
            <div class="settings-card">
                <div class="settings-header">
                    <h2>Change Username</h2>
                    <p>Your current username: <strong>@<?= htmlspecialchars($me['username']) ?></strong></p>
                </div>
                <div class="settings-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="change_username">
                        <div class="form-group">
                            <label class="form-label">New Username</label>
                            <input type="text" name="new_username" class="form-input" required minlength="3" pattern="[a-zA-Z0-9_]+" placeholder="new_username">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Confirm Password</label>
                            <input type="password" name="confirm_password_uname" class="form-input" required placeholder="Your current password">
                        </div>
                        <button type="submit" class="btn btn-primary">Update Username</button>
                    </form>
                </div>
            </div>
            <div class="settings-card">
                <div class="settings-header">
                    <h2>Change Email</h2>
                    <p>Your current email: <strong><?= htmlspecialchars($me['email']) ?></strong></p>
                </div>
                <div class="settings-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="change_email">
                        <div class="form-group">
                            <label class="form-label">New Email</label>
                            <input type="email" name="new_email" class="form-input" required placeholder="new@email.com">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Confirm Password</label>
                            <input type="password" name="confirm_password" class="form-input" required placeholder="Your current password">
                        </div>
                        <button type="submit" class="btn btn-primary">Update Email</button>
                    </form>
                </div>
            </div>

            <?php elseif ($tab === 'security'): ?>
            <div class="settings-card">
                <div class="settings-header">
                    <h2>Change Password</h2>
                    <p>Ensure your account stays secure</p>
                </div>
                <div class="settings-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="change_password">
                        <div class="form-group">
                            <label class="form-label">Current Password</label>
                            <input type="password" name="current_password" class="form-input" required>
                        </div>
                        <div class="settings-row">
                            <div class="form-group">
                                <label class="form-label">New Password</label>
                                <input type="password" name="new_password" class="form-input" required minlength="6">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Confirm New Password</label>
                                <input type="password" name="confirm_new_password" class="form-input" required minlength="6">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">Update Password</button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="../assets/js/app.js?v=loop202605"></script>
</body>
</html>
