<?php
require_once 'config.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$error   = '';
$success = '';

// Upload sample
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    $sample_name = trim($_POST['sample_name'] ?? '');
    $tag_ids     = array_filter(array_map('intval', (array)($_POST['tag_ids'] ?? [])));

    if (empty($sample_name) || strlen($sample_name) > 100) {
        $error = 'Sample name required (max 100 characters).';
    } elseif (!isset($_FILES['audio_file']) || $_FILES['audio_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Please select an audio file.';
    } else {
        $allowed_ext = ['mp3','wav','ogg','m4a','aac','flac'];
        $ext = strtolower(pathinfo($_FILES['audio_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_ext)) {
            $error = 'Only audio files allowed: mp3, wav, ogg, m4a, aac, flac.';
        } elseif ($_FILES['audio_file']['size'] > 10 * 1024 * 1024) {
            $error = 'File too large (max 10 MB).';
        } else {
            $upload_dir = __DIR__ . '/uploads/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $filename = uniqid('sample_', true) . '.' . $ext;
            if (move_uploaded_file($_FILES['audio_file']['tmp_name'], $upload_dir . $filename)) {
                $file_path = 'uploads/' . $filename;
                $duration  = max(0.0, (float)($_POST['duration'] ?? 0));
                $pdo->beginTransaction();
                $pdo->prepare("INSERT INTO `Sample` (user_id, sample_name, file_path, duration) VALUES (?,?,?,?)")
                    ->execute([$user_id, $sample_name, $file_path, $duration]);
                $sample_id = (int)$pdo->lastInsertId();
                foreach ($tag_ids as $tid) {
                    $pdo->prepare("INSERT IGNORE INTO `SampleTag` (sample_id, tag_id) VALUES (?,?)")->execute([$sample_id, $tid]);
                }
                $pdo->commit();
                $success = 'Sample "' . h($sample_name) . '" uploaded!';
            } else {
                $error = 'Upload failed.';
            }
        }
    }
}

// Delete sample
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $sample_id = (int)($_POST['sample_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT file_path FROM `Sample` WHERE sample_id=? AND user_id=?");
    $stmt->execute([$sample_id, $user_id]);
    $row = $stmt->fetch();
    if ($row) {
        if ($row['file_path'] && file_exists(__DIR__ . '/' . $row['file_path'])) {
            unlink(__DIR__ . '/' . $row['file_path']);
        }
        $pdo->prepare("DELETE FROM `Sample` WHERE sample_id=?")->execute([$sample_id]);
        $success = 'Sample deleted.';
    }
}

// Fetch samples
$tag_filter = trim($_GET['tag'] ?? '');
$search     = trim($_GET['search'] ?? '');

$sql = "SELECT s.*, GROUP_CONCAT(t.tag_name ORDER BY t.tag_name SEPARATOR ',') AS tags
        FROM `Sample` s
        LEFT JOIN `SampleTag` st ON s.sample_id = st.sample_id
        LEFT JOIN `Tag` t ON st.tag_id = t.tag_id
        WHERE s.user_id = ?";
$params = [$user_id];

if ($tag_filter) {
    $sql .= " AND s.sample_id IN (SELECT st2.sample_id FROM `SampleTag` st2 JOIN `Tag` t2 ON st2.tag_id=t2.tag_id WHERE t2.tag_name=?)";
    $params[] = $tag_filter;
}
if ($search) {
    $sql .= " AND s.sample_name LIKE ?";
    $params[] = '%' . $search . '%';
}
$sql .= " GROUP BY s.sample_id ORDER BY s.uploaded_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$samples = $stmt->fetchAll();

// All tags for filter/upload
$all_tags = $pdo->query("SELECT * FROM `Tag` ORDER BY tag_name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sample Library — CatarataDAW</title>
    <link rel="stylesheet" href="css/style.css?v=4">
</head>
<body>

<nav>
    <a href="dashboard.php" class="nav-logo">CatarataDAW</a>
    <ul class="nav-links">
        <li><a href="dashboard.php">📁 Projects</a></li>
        <li><span style="color:var(--accent-light);font-size:.82rem;font-weight:700;">🎵 Samples</span></li>
        <li><a href="profile.php">👤 Profile</a></li>
        <li><a href="logout.php">Sign Out</a></li>
    </ul>
</nav>

<div class="container">

    <?php if ($error):   ?><div class="alert alert-error">⚠️ <?= h($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success">✅ <?= h($success) ?></div><?php endif; ?>

    <div class="page-header">
        <div>
            <h1>🎵 Sample Library</h1>
            <p style="color:var(--text-muted);font-size:.85rem;">Upload and manage reusable audio samples</p>
        </div>
        <button class="btn btn-primary" onclick="document.getElementById('uploadModal').classList.add('active')">+ Upload Sample</button>
    </div>

    <!-- Search & Filter -->
    <form method="GET" class="sample-filter-bar">
        <input type="text" name="search" placeholder="Search samples..."
               value="<?= h($search) ?>" class="sample-search-input">
        <select name="tag" onchange="this.form.submit()">
            <option value="">All Tags</option>
            <?php foreach ($all_tags as $t): ?>
                <option value="<?= h($t['tag_name']) ?>" <?= $tag_filter === $t['tag_name'] ? 'selected' : '' ?>><?= h($t['tag_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
        <?php if ($tag_filter || $search): ?>
            <a href="samples.php" class="btn btn-secondary btn-sm">Clear</a>
        <?php endif; ?>
    </form>

    <!-- Sample Count -->
    <div class="action-bar">
        <div class="section-title">
            🎚 My Samples
            <span class="count-badge"><?= count($samples) ?></span>
        </div>
    </div>

    <?php if (empty($samples)): ?>
        <div class="empty-state">
            <span class="empty-state-icon">🎵</span>
            <h3>No samples yet</h3>
            <p>Upload audio samples to build your library.</p>
        </div>
    <?php else: ?>
        <div class="samples-grid">
            <?php foreach ($samples as $s):
                $tags = $s['tags'] ? explode(',', $s['tags']) : [];
            ?>
            <div class="sample-card">
                <div class="sample-card-header">
                    <strong><?= h($s['sample_name']) ?></strong>
                    <span class="sample-date"><?= h($s['uploaded_at']) ?></span>
                </div>
                <?php if ($s['file_path'] && file_exists(__DIR__ . '/' . $s['file_path'])): ?>
                    <audio controls class="audio-player" style="width:100%;margin:.5rem 0;">
                        <source src="<?= h($s['file_path']) ?>">
                    </audio>
                <?php endif; ?>
                <?php if ($tags): ?>
                    <div class="sample-tags">
                        <?php foreach ($tags as $tag): ?>
                            <a href="?tag=<?= urlencode($tag) ?>" class="sample-tag"><?= h($tag) ?></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div style="display:flex;justify-content:flex-end;margin-top:.5rem;">
                    <form method="POST" onsubmit="return confirm('Delete this sample?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="sample_id" value="<?= $s['sample_id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">🗑 Delete</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<!-- Upload Sample Modal -->
<div class="modal-overlay" id="uploadModal"
     onclick="if(event.target===this)this.classList.remove('active')">
    <div class="modal">
        <div class="modal-header">
            <h2>🎵 Upload Sample</h2>
            <button class="modal-close" onclick="document.getElementById('uploadModal').classList.remove('active')">✕</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload">
            <div class="form-group">
                <label>Sample Name</label>
                <input type="text" name="sample_name" placeholder="e.g. Deep Kick, Vocal Chop" required maxlength="100">
            </div>
            <div class="form-group">
                <label>Duration (seconds) <span style="color:var(--text-muted);font-weight:400;">(approximate)</span></label>
                <input type="number" name="duration" min="0" step="0.01" value="0" placeholder="Auto-detect later">
            </div>
            <div class="form-group">
                <label>Audio File <span style="color:var(--text-muted);font-weight:400;">(max 10 MB)</span></label>
                <input type="file" name="audio_file" accept=".mp3,.wav,.ogg,.m4a,.aac,.flac" required>
            </div>
            <div class="form-group">
                <label>Tags</label>
                <div class="tag-checkbox-grid">
                    <?php foreach ($all_tags as $t): ?>
                    <label class="tag-checkbox-label">
                        <input type="checkbox" name="tag_ids[]" value="<?= $t['tag_id'] ?>">
                        <?= h($t['tag_name']) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div style="display:flex;gap:.8rem;justify-content:flex-end;margin-top:1.5rem;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('uploadModal').classList.remove('active')">Cancel</button>
                <button type="submit" class="btn btn-primary">Upload →</button>
            </div>
        </form>
    </div>
</div>

<script src="js/main.js?v=4"></script>
</body>
</html>
