<?php
require_once 'config.php';
requireLogin();

$user_id    = $_SESSION['user_id'];
$project_id = (int)($_GET['id'] ?? 0);

if (!$project_id) { header('Location: dashboard.php'); exit; }

// Verify ownership
$stmt = $pdo->prepare("SELECT * FROM `Project` WHERE project_id = ? AND user_id = ?");
$stmt->execute([$project_id, $user_id]);
$project = $stmt->fetch();
if (!$project) { header('Location: dashboard.php'); exit; }

$error   = '';
$success = '';

// ── Create Track ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_track') {
    $track_name = trim($_POST['track_name'] ?? '');
    $track_type = trim($_POST['track_type'] ?? 'other');
    $allowed    = ['vocals','drums','bass','guitar','piano','other'];
    if (!in_array($track_type, $allowed)) $track_type = 'other';

    if (empty($track_name)) {
        $error = 'Track name cannot be empty.';
    } elseif (strlen($track_name) > 100) {
        $error = 'Track name too long (max 100 characters).';
    } else {
        $stmt = $pdo->prepare("INSERT INTO `Track` (project_id, track_name, track_type) VALUES (?, ?, ?)");
        $stmt->execute([$project_id, $track_name, $track_type]);
        $success = 'Track "' . h($track_name) . '" added!';
    }
}

// ── Delete Track ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_track') {
    $track_id = (int)($_POST['track_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT track_id FROM `Track` WHERE track_id = ? AND project_id = ?");
    $stmt->execute([$track_id, $project_id]);
    if ($stmt->fetch()) {
        $pdo->prepare("DELETE FROM `Track` WHERE track_id = ?")->execute([$track_id]);
        $success = 'Track deleted.';
    }
}

// ── Fetch Tracks ──────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT t.*, COUNT(a.clip_id) AS clip_count
    FROM `Track` t
    LEFT JOIN `AudioClip` a ON t.track_id = a.track_id
    WHERE t.project_id = ?
    GROUP BY t.track_id
    ORDER BY t.track_id ASC
");
$stmt->execute([$project_id]);
$tracks = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($project['project_name']) ?> — CatarataDAW</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<nav>
    <a href="dashboard.php" class="nav-logo">CatarataDAW</a>
    <ul class="nav-links">
        <li><a href="dashboard.php">📁 Projects</a></li>
        <li><a href="logout.php">Sign Out</a></li>
    </ul>
</nav>

<div class="container">

    <div class="breadcrumb">
        <a href="dashboard.php">Dashboard</a>
        <span>›</span>
        <span><?= h($project['project_name']) ?></span>
    </div>

    <div class="page-header">
        <div>
            <h1>🎶 <?= h($project['project_name']) ?></h1>
            <div style="color:var(--text-muted);font-size:.82rem;margin-top:.3rem;">
                Created <?= h($project['created_date']) ?>
                &nbsp;·&nbsp;
                <?= count($tracks) ?> track<?= count($tracks) != 1 ? 's' : '' ?>
            </div>
        </div>
        <button class="btn btn-primary"
                onclick="document.getElementById('createTrackModal').classList.add('active')">
            + Add Track
        </button>
    </div>

    <?php if ($error):   ?><div class="alert alert-error">⚠️ <?= h($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success">✅ <?= $success ?></div><?php endif; ?>

    <div class="action-bar">
        <div class="section-title">
            🎼 Tracks
            <span class="count-badge"><?= count($tracks) ?></span>
        </div>
    </div>

    <?php if (empty($tracks)): ?>
        <div class="empty-state">
            <span class="empty-state-icon">🎼</span>
            <h3>No tracks yet</h3>
            <p>Add your first track to start building this project.</p>
            <button class="btn btn-cyan" style="margin-top:1rem;"
                    onclick="document.getElementById('createTrackModal').classList.add('active')">
                + Add First Track
            </button>
        </div>
    <?php else: ?>
        <div class="tracks-list">
            <?php foreach ($tracks as $t): ?>
            <?php $type = strtolower($t['track_type']); ?>
            <div class="track-item">
                <div class="track-info">
                    <div class="track-icon <?= h($type) ?>"><?= getTrackIcon($type) ?></div>
                    <div>
                        <a href="track.php?id=<?= $t['track_id'] ?>" class="track-name">
                            <?= h($t['track_name']) ?>
                        </a>
                        <div style="margin-top:.25rem;display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
                            <span class="track-type-badge badge-<?= h($type) ?>"><?= h($t['track_type']) ?></span>
                            <span style="font-size:.75rem;color:var(--text-muted);">
                                🎵 <?= $t['clip_count'] ?> clip<?= $t['clip_count'] != 1 ? 's' : '' ?>
                            </span>
                        </div>
                    </div>
                </div>
                <div style="display:flex;gap:.5rem;align-items:center;">
                    <a href="track.php?id=<?= $t['track_id'] ?>" class="btn btn-secondary btn-sm">Edit →</a>
                    <form method="POST" style="display:inline;"
                          onsubmit="return confirm('Delete track &quot;<?= h(addslashes($t['track_name'])) ?>&quot; and all its clips?')">
                        <input type="hidden" name="action"   value="delete_track">
                        <input type="hidden" name="track_id" value="<?= $t['track_id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">🗑</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div><!-- /container -->

<!-- Add Track Modal -->
<div class="modal-overlay" id="createTrackModal"
     onclick="if(event.target===this)this.classList.remove('active')">
    <div class="modal">
        <div class="modal-header">
            <h2>🎼 Add New Track</h2>
            <button class="modal-close"
                    onclick="document.getElementById('createTrackModal').classList.remove('active')">✕</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create_track">
            <div class="form-group">
                <label for="track_name">Track Name</label>
                <input type="text" id="track_name" name="track_name"
                       placeholder="e.g. Lead Vocals, Kick Drum, Bass Line"
                       required maxlength="100">
            </div>
            <div class="form-group">
                <label for="track_type">Track Type</label>
                <select id="track_type" name="track_type">
                    <option value="vocals">🎤 Vocals</option>
                    <option value="drums">🥁  Drums</option>
                    <option value="bass">🎸  Bass</option>
                    <option value="guitar">🎵 Guitar</option>
                    <option value="piano">🎹  Piano</option>
                    <option value="other">🎼  Other</option>
                </select>
            </div>
            <div style="display:flex;gap:.8rem;justify-content:flex-end;margin-top:1.5rem;">
                <button type="button" class="btn btn-secondary"
                        onclick="document.getElementById('createTrackModal').classList.remove('active')">
                    Cancel
                </button>
                <button type="submit" class="btn btn-cyan">Add Track →</button>
            </div>
        </form>
    </div>
</div>

<script src="js/main.js"></script>
</body>
</html>
