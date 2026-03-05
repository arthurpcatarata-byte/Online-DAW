<?php
require_once 'config.php';
requireLogin();

$user_id  = $_SESSION['user_id'];
$track_id = (int)($_GET['id'] ?? 0);

if (!$track_id) { header('Location: dashboard.php'); exit; }

// Verify via join: track → project → user
$stmt = $pdo->prepare("
    SELECT t.*, p.project_name, p.project_id
    FROM `Track` t
    JOIN `Project` p ON t.project_id = p.project_id
    WHERE t.track_id = ? AND p.user_id = ?
");
$stmt->execute([$track_id, $user_id]);
$track = $stmt->fetch();
if (!$track) { header('Location: dashboard.php'); exit; }

$error   = '';
$success = '';

// ── Add Clip ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_clip') {
    $start_time = max(0.0, (float)($_POST['start_time'] ?? 0));
    $duration   = (float)($_POST['duration'] ?? 0);
    $file_path  = null;

    if ($duration <= 0) {
        $error = 'Duration must be greater than 0.';
    } else {
        // Handle file upload (optional)
        if (isset($_FILES['audio_file']) && $_FILES['audio_file']['error'] === UPLOAD_ERR_OK) {
            $allowed_ext = ['mp3','wav','ogg','m4a','aac','flac'];
            $ext         = strtolower(pathinfo($_FILES['audio_file']['name'], PATHINFO_EXTENSION));
            $file_size   = $_FILES['audio_file']['size'];
            $max_size    = 10 * 1024 * 1024; // 10 MB

            if (!in_array($ext, $allowed_ext)) {
                $error = 'Only audio files allowed: mp3, wav, ogg, m4a, aac, flac.';
            } elseif ($file_size > $max_size) {
                $error = 'File too large. Maximum size is 10 MB.';
            } else {
                $upload_dir = __DIR__ . '/uploads/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                $filename = uniqid('clip_', true) . '.' . $ext;
                if (move_uploaded_file($_FILES['audio_file']['tmp_name'], $upload_dir . $filename)) {
                    $file_path = 'uploads/' . $filename;
                } else {
                    $error = 'File upload failed. Check server permissions.';
                }
            }
        }

        if (empty($error)) {
            $stmt = $pdo->prepare("INSERT INTO `AudioClip` (track_id, start_time, duration, file_path) VALUES (?, ?, ?, ?)");
            $stmt->execute([$track_id, $start_time, $duration, $file_path]);
            $success = 'Clip added at ' . formatTime($start_time) . '!';
        }
    }
}

// ── Delete Clip ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_clip') {
    $clip_id = (int)($_POST['clip_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT clip_id, file_path FROM `AudioClip` WHERE clip_id = ? AND track_id = ?");
    $stmt->execute([$clip_id, $track_id]);
    $clip_row = $stmt->fetch();
    if ($clip_row) {
        // Remove uploaded file if it exists
        if ($clip_row['file_path'] && file_exists(__DIR__ . '/' . $clip_row['file_path'])) {
            unlink(__DIR__ . '/' . $clip_row['file_path']);
        }
        $pdo->prepare("DELETE FROM `AudioClip` WHERE clip_id = ?")->execute([$clip_id]);
        $success = 'Clip deleted.';
    }
}

// ── Fetch Clips ───────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM `AudioClip` WHERE track_id = ? ORDER BY start_time ASC");
$stmt->execute([$track_id]);
$clips = $stmt->fetchAll();

// Timeline range
$max_time = 30.0;
foreach ($clips as $c) {
    $end = $c['start_time'] + $c['duration'];
    if ($end > $max_time) $max_time = ceil($end) + 5;
}
$type = strtolower($track['track_type']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($track['track_name']) ?> — CatarataDAW</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<nav>
    <a href="dashboard.php" class="nav-logo">CatarataDAW</a>
    <ul class="nav-links">
        <li><a href="dashboard.php">📁 Projects</a></li>
        <li><a href="project.php?id=<?= $track['project_id'] ?>">📂 <?= h($track['project_name']) ?></a></li>
        <li><a href="logout.php">Sign Out</a></li>
    </ul>
</nav>

<div class="container">

    <div class="breadcrumb">
        <a href="dashboard.php">Dashboard</a>
        <span>›</span>
        <a href="project.php?id=<?= $track['project_id'] ?>"><?= h($track['project_name']) ?></a>
        <span>›</span>
        <span><?= h($track['track_name']) ?></span>
    </div>

    <div class="page-header">
        <div>
            <h1><?= getTrackIcon($type) ?> <?= h($track['track_name']) ?></h1>
            <div style="margin-top:.35rem;display:flex;gap:.6rem;align-items:center;flex-wrap:wrap;">
                <span class="track-type-badge badge-<?= h($type) ?>"><?= h($track['track_type']) ?></span>
                <span style="color:var(--text-muted);font-size:.8rem;">
                    <?= count($clips) ?> clip<?= count($clips) != 1 ? 's' : '' ?>
                </span>
                <!-- Waveform decoration -->
                <div class="waveform">
                    <?php for ($i = 0; $i < 7; $i++): ?><div class="wave-bar"></div><?php endfor; ?>
                </div>
            </div>
        </div>
        <button class="btn btn-primary"
                onclick="document.getElementById('addClipModal').classList.add('active')">
            + Add Clip
        </button>
    </div>

    <?php if ($error):   ?><div class="alert alert-error">⚠️ <?= h($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success">✅ <?= $success ?></div><?php endif; ?>

    <!-- ── Transport Controls ───────────────────────────────── -->
    <?php if (!empty($clips)): ?>
    <div class="transport-bar">
        <div class="transport-controls">
            <button class="transport-btn" id="daw-play"  title="Play">▶</button>
            <button class="transport-btn" id="daw-pause" title="Pause" disabled>⏸</button>
            <button class="transport-btn" id="daw-stop"  title="Stop"  disabled>⏹</button>
        </div>
        <div class="transport-time" id="daw-time">00:00.00 / <?= formatTime($max_time) ?></div>
        <div class="transport-load">
            <div class="transport-load-bar">
                <div class="transport-load-progress" id="daw-progress"></div>
            </div>
            <span class="transport-load-text" id="daw-load-status">Initializing...</span>
        </div>
    </div>

    <!-- ── Timeline ─────────────────────────────────────────── -->
    <div class="timeline-container">
        <div class="timeline-title">📊 Timeline View — total length: <?= formatTime($max_time) ?> &nbsp;·&nbsp; Click timeline to seek</div>
        <div style="overflow-x:auto;">
            <div style="min-width:800px;padding-bottom:.5rem;">
                <!-- Ruler marks every 5 s -->
                <div class="timeline-ruler">
                    <?php for ($i = 0; $i <= $max_time; $i += 5): ?>
                        <div class="timeline-ruler-mark"
                             style="left:<?= round($i / $max_time * 100, 2) ?>%">
                            <?= sprintf('%d:%02d', floor($i/60), $i%60) ?>
                        </div>
                    <?php endfor; ?>
                </div>
                <!-- Clip bar -->
                <div class="timeline-track" id="daw-timeline-track">
                    <div class="playhead" id="daw-playhead"></div>
                    <?php foreach ($clips as $c):
                        $l = round($c['start_time'] / $max_time * 100, 2);
                        $w = max(round($c['duration']  / $max_time * 100, 2), 0.5);
                    ?>
                    <div class="timeline-clip"
                         style="left:<?= $l ?>%;width:<?= $w ?>%"
                         data-start="<?= $c['start_time'] ?>"
                         data-end="<?= $c['start_time'] + $c['duration'] ?>"
                         title="Start: <?= formatTime($c['start_time']) ?> | Duration: <?= formatTime($c['duration']) ?>">
                        #<?= $c['clip_id'] ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div style="font-size:.7rem;color:var(--text-muted);text-align:right;margin-top:.3rem;">
                    ← scroll to see full timeline
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Clips List ────────────────────────────────────────── -->
    <div class="action-bar">
        <div class="section-title">
            🎵 Audio Clips
            <span class="count-badge"><?= count($clips) ?></span>
        </div>
    </div>

    <?php if (empty($clips)): ?>
        <div class="empty-state">
            <span class="empty-state-icon">🎵</span>
            <h3>No clips yet</h3>
            <p>Add audio clips to place them on the timeline of this track.</p>
            <button class="btn btn-primary" style="margin-top:1rem;"
                    onclick="document.getElementById('addClipModal').classList.add('active')">
                + Add First Clip
            </button>
        </div>
    <?php else: ?>
        <div class="clips-list">
            <?php foreach ($clips as $c): ?>
            <div class="clip-item">
                <div class="clip-info">
                    <div class="clip-icon">🎵</div>
                    <div class="clip-details">
                        <strong>Clip #<?= $c['clip_id'] ?></strong>
                        <?php if ($c['file_path']): ?>
                            <span style="color:var(--success);">📎 <?= h(basename($c['file_path'])) ?></span>
                        <?php else: ?>
                            <span>No audio file attached</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="clip-meta">
                    <div class="clip-stat">
                        <div class="clip-stat-label">Start</div>
                        <div class="clip-stat-value"><?= formatTime($c['start_time']) ?></div>
                    </div>
                    <div class="clip-stat">
                        <div class="clip-stat-label">Duration</div>
                        <div class="clip-stat-value"><?= formatTime($c['duration']) ?></div>
                    </div>
                    <div class="clip-stat">
                        <div class="clip-stat-label">End</div>
                        <div class="clip-stat-value"><?= formatTime($c['start_time'] + $c['duration']) ?></div>
                    </div>
                </div>

                <div style="display:flex;flex-direction:column;gap:.4rem;align-items:flex-end;">
                    <?php if ($c['file_path'] && file_exists(__DIR__ . '/' . $c['file_path'])): ?>
                        <audio controls class="audio-player">
                            <source src="<?= h($c['file_path']) ?>">
                            Your browser does not support audio.
                        </audio>
                    <?php endif; ?>
                    <form method="POST" onsubmit="return confirm('Delete Clip #<?= $c['clip_id'] ?>?')">
                        <input type="hidden" name="action"  value="delete_clip">
                        <input type="hidden" name="clip_id" value="<?= $c['clip_id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">🗑 Delete</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div><!-- /container -->

<!-- Add Clip Modal -->
<div class="modal-overlay" id="addClipModal"
     onclick="if(event.target===this)this.classList.remove('active')">
    <div class="modal">
        <div class="modal-header">
            <h2>🎵 Add New Clip</h2>
            <button class="modal-close"
                    onclick="document.getElementById('addClipModal').classList.remove('active')">✕</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add_clip">
            <div class="form-group">
                <label for="start_time">Start Time (seconds)</label>
                <input type="number" id="start_time" name="start_time"
                       placeholder="e.g. 0, 4.5, 30"
                       min="0" step="0.01" value="0" required>
            </div>
            <div class="form-group">
                <label for="duration">Duration (seconds)</label>
                <input type="number" id="duration" name="duration"
                       placeholder="e.g. 4, 8, 16.5"
                       min="0.01" step="0.01" value="4" required>
            </div>
            <div class="form-group">
                <label>Audio File <span style="color:var(--text-muted);font-weight:400;">(optional · max 10 MB)</span></label>
                <div class="file-input-wrapper">
                    <div class="file-input-display" id="fileDisplay">
                        📎 Click to select an audio file (mp3, wav, ogg …)
                    </div>
                    <input type="file" name="audio_file" id="audio_file"
                           accept=".mp3,.wav,.ogg,.m4a,.aac,.flac"
                           onchange="document.getElementById('fileDisplay').textContent =
                               this.files[0] ? '📎 ' + this.files[0].name : '📎 Click to select an audio file'">
                </div>
            </div>
            <div style="display:flex;gap:.8rem;justify-content:flex-end;margin-top:1.5rem;">
                <button type="button" class="btn btn-secondary"
                        onclick="document.getElementById('addClipModal').classList.remove('active')">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">Add Clip →</button>
            </div>
        </form>
    </div>
</div>

<!-- Clip data for the DAW player -->
<script>
const dawClipData = <?= json_encode(array_map(function($c) {
    return [
        'clip_id'    => $c['clip_id'],
        'start_time' => (float)$c['start_time'],
        'duration'   => (float)$c['duration'],
        'file_path'  => $c['file_path'],
    ];
}, $clips)) ?>;
const dawMaxTime = <?= json_encode((float)$max_time) ?>;
</script>
<script src="js/main.js"></script>
<script src="js/player.js"></script>
</body>
</html>
