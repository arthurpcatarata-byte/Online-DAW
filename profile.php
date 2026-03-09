<?php
require_once 'config.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$error   = '';
$success = '';

// Fetch current user data
$stmt = $pdo->prepare("SELECT * FROM `User` WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $display_name = trim($_POST['display_name'] ?? '');
        $bio          = trim($_POST['bio']          ?? '');

        if (strlen($display_name) > 100) {
            $error = 'Display name too long (max 100 characters).';
        } elseif (strlen($bio) > 500) {
            $error = 'Bio too long (max 500 characters).';
        } else {
            $avatar_path = $user['avatar_path'];

            // Handle avatar upload
            if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                $allowed = ['jpg','jpeg','png','gif','webp'];
                $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed)) {
                    $error = 'Only image files allowed: jpg, png, gif, webp.';
                } elseif ($_FILES['avatar']['size'] > 2 * 1024 * 1024) {
                    $error = 'Avatar too large (max 2 MB).';
                } else {
                    $upload_dir = __DIR__ . '/uploads/';
                    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                    $filename = 'avatar_' . $user_id . '_' . uniqid() . '.' . $ext;
                    if (move_uploaded_file($_FILES['avatar']['tmp_name'], $upload_dir . $filename)) {
                        // Delete old avatar
                        if ($avatar_path && file_exists(__DIR__ . '/' . $avatar_path)) {
                            unlink(__DIR__ . '/' . $avatar_path);
                        }
                        $avatar_path = 'uploads/' . $filename;
                    }
                }
            }

            if (empty($error)) {
                $pdo->prepare("UPDATE `User` SET display_name=?, bio=?, avatar_path=? WHERE user_id=?")
                    ->execute([$display_name ?: null, $bio ?: null, $avatar_path, $user_id]);
                $success = 'Profile updated!';
                // Refresh
                $stmt = $pdo->prepare("SELECT * FROM `User` WHERE user_id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch();
            }
        }
    }
}

// Stats
$stmt = $pdo->prepare("SELECT COUNT(*) FROM `Project` WHERE user_id = ?");
$stmt->execute([$user_id]);
$project_count = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM `Track` t JOIN `Project` p ON t.project_id=p.project_id WHERE p.user_id = ?");
$stmt->execute([$user_id]);
$track_count = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM `Sample` WHERE user_id = ?");
$stmt->execute([$user_id]);
$sample_count = $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile — CatarataDAW</title>
    <link rel="stylesheet" href="css/style.css?v=4">
</head>
<body>

<nav>
    <a href="dashboard.php" class="nav-logo">CatarataDAW</a>
    <ul class="nav-links">
        <li><a href="dashboard.php">📁 Projects</a></li>
        <li><a href="samples.php">🎵 Samples</a></li>
        <li><a href="logout.php">Sign Out</a></li>
    </ul>
</nav>

<div class="container">

    <?php if ($error):   ?><div class="alert alert-error">⚠️ <?= h($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success">✅ <?= h($success) ?></div><?php endif; ?>

    <div class="profile-card">
        <div class="profile-header">
            <div class="profile-avatar">
                <?php if ($user['avatar_path'] && file_exists(__DIR__ . '/' . $user['avatar_path'])): ?>
                    <img src="<?= h($user['avatar_path']) ?>" alt="Avatar">
                <?php else: ?>
                    <div class="profile-avatar-placeholder">👤</div>
                <?php endif; ?>
            </div>
            <div class="profile-info">
                <h1><?= h($user['display_name'] ?: $user['username']) ?></h1>
                <div class="profile-username">@<?= h($user['username']) ?></div>
                <?php if ($user['bio']): ?>
                    <p class="profile-bio"><?= h($user['bio']) ?></p>
                <?php endif; ?>
                <div class="profile-joined">Joined <?= h($user['created_at']) ?></div>
            </div>
        </div>

        <div class="stats-bar" style="margin-top:1.5rem;">
            <div class="stat-card"><div class="stat-number"><?= $project_count ?></div><div class="stat-label">Projects</div></div>
            <div class="stat-card"><div class="stat-number"><?= $track_count ?></div><div class="stat-label">Tracks</div></div>
            <div class="stat-card"><div class="stat-number"><?= $sample_count ?></div><div class="stat-label">Samples</div></div>
        </div>
    </div>

    <div class="section-title" style="margin-top:2rem;">✏️ Edit Profile</div>

    <form method="POST" enctype="multipart/form-data" class="profile-form">
        <input type="hidden" name="action" value="update_profile">

        <div class="form-group">
            <label>Display Name</label>
            <input type="text" name="display_name" placeholder="Your display name"
                   value="<?= h($user['display_name'] ?? '') ?>" maxlength="100">
        </div>
        <div class="form-group">
            <label>Bio</label>
            <textarea name="bio" rows="3" placeholder="Tell us about yourself (max 500 chars)"
                      maxlength="500"><?= h($user['bio'] ?? '') ?></textarea>
        </div>
        <div class="form-group">
            <label>Avatar <span style="color:var(--text-muted);font-weight:400;">(max 2 MB · jpg, png, gif, webp)</span></label>
            <input type="file" name="avatar" accept=".jpg,.jpeg,.png,.gif,.webp">
        </div>

        <button type="submit" class="btn btn-primary">Save Profile →</button>
    </form>

</div>

<script src="js/main.js?v=4"></script>
</body>
</html>
