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

// ── Create Track from Arrangement ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_track') {
    $track_name = trim($_POST['track_name'] ?? '');
    $track_type = trim($_POST['track_type'] ?? 'other');
    $allowed    = ['vocals','drums','bass','guitar','piano','other'];
    if (!in_array($track_type, $allowed)) $track_type = 'other';

    if (!empty($track_name) && strlen($track_name) <= 100) {
        $stmt = $pdo->prepare("INSERT INTO `Track` (project_id, track_name, track_type) VALUES (?, ?, ?)");
        $stmt->execute([$project_id, $track_name, $track_type]);
        $success = 'Track added!';
    } else {
        $error = 'Track name required (max 100 characters).';
    }
}

// ── Delete Track ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_track') {
    $track_id = (int)($_POST['track_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT track_id FROM `Track` WHERE track_id = ? AND project_id = ?");
    $stmt->execute([$track_id, $project_id]);
    if ($stmt->fetch()) {
        $pdo->prepare("DELETE FROM `Track` WHERE track_id = ?")->execute([$track_id]);
        $success = 'Track removed.';
    }
}

// ── Fetch all tracks + clips ──────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM `Track` WHERE project_id = ? ORDER BY track_id ASC");
$stmt->execute([$project_id]);
$tracks = $stmt->fetchAll();

// Build clips map: track_id → [clips]
$clips_map = [];
if (!empty($tracks)) {
    $track_ids   = array_column($tracks, 'track_id');
    $placeholders = implode(',', array_fill(0, count($track_ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM `AudioClip` WHERE track_id IN ($placeholders) ORDER BY start_time ASC");
    $stmt->execute($track_ids);
    foreach ($stmt->fetchAll() as $clip) {
        $clips_map[$clip['track_id']][] = $clip;
    }
}

// Compute total timeline length across all clips
$max_time = 60.0; // minimum 60 s visible
foreach ($clips_map as $tclips) {
    foreach ($tclips as $c) {
        $end = $c['start_time'] + $c['duration'];
        if ($end > $max_time) $max_time = ceil($end) + 8;
    }
}

// Build full JSON for JS
$arr_data = [];
foreach ($tracks as $t) {
    $tclips = $clips_map[$t['track_id']] ?? [];
    $arr_data[] = [
        'track_id'   => $t['track_id'],
        'track_name' => $t['track_name'],
        'track_type' => $t['track_type'],
        'clips'      => array_map(fn($c) => [
            'clip_id'    => $c['clip_id'],
            'start_time' => (float)$c['start_time'],
            'duration'   => (float)$c['duration'],
            'file_path'  => $c['file_path'],
        ], $tclips),
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Arrangement — <?= h($project['project_name']) ?> — CatarataDAW</title>
    <link rel="stylesheet" href="css/style.css?v=3">
</head>
<body class="arrangement-body">

<nav>
    <a href="dashboard.php" class="nav-logo">CatarataDAW</a>
    <ul class="nav-links">
        <li><a href="dashboard.php">📁 Projects</a></li>
        <li><a href="project.php?id=<?= $project_id ?>">📂 Tracks</a></li>
        <li><span style="color:var(--accent-light);font-size:.82rem;font-weight:700;">🎛 Arrangement</span></li>
        <li><a href="logout.php">Sign Out</a></li>
    </ul>
</nav>

<div class="arr-layout">

    <!-- ── Left Panel: Track Headers ─────────────────────────── -->
    <div class="arr-sidebar">
        <div class="arr-sidebar-top">
            <div class="arr-project-name"><?= h($project['project_name']) ?></div>
            <button class="btn btn-primary btn-sm"
                    onclick="document.getElementById('addTrackModal').classList.add('active')">
                + Track
            </button>
        </div>

        <div class="arr-track-headers" id="arr-track-headers">
            <?php if (empty($tracks)): ?>
                <div class="arr-no-tracks">No tracks yet.<br>Click "+ Track" to begin.</div>
            <?php else: ?>
            <?php foreach ($tracks as $t):
                $type  = strtolower($t['track_type']);
                $count = count($clips_map[$t['track_id']] ?? []);
            ?>
            <div class="arr-track-header" data-track="<?= $t['track_id'] ?>">
                <div class="arr-track-icon arr-icon-<?= h($type) ?>"><?= getTrackIcon($type) ?></div>
                <div class="arr-track-info">
                    <div class="arr-track-name"><?= h($t['track_name']) ?></div>
                    <span class="track-type-badge badge-<?= h($type) ?>"><?= h($t['track_type']) ?></span>
                </div>
                <div class="arr-track-actions">
                    <button class="arr-mute-btn" title="Mute track"
                            onclick="arrToggleMute(<?= $t['track_id'] ?>, this)">M</button>
                    <form method="POST" style="display:inline;"
                          onsubmit="return confirm('Delete track and all its clips?')">
                        <input type="hidden" name="action"   value="delete_track">
                        <input type="hidden" name="track_id" value="<?= $t['track_id'] ?>">
                        <button type="submit" class="arr-del-btn" title="Delete track">✕</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Right Panel: Timeline & Arrangement Grid ──────────── -->
    <div class="arr-main" id="arr-main">

        <!-- Transport -->
        <div class="arr-transport">
            <div class="arr-transport-left">
                <button class="transport-btn" id="arr-play"  title="Play">▶</button>
                <button class="transport-btn" id="arr-pause" title="Pause" disabled>⏸</button>
                <button class="transport-btn" id="arr-stop"  title="Stop"  disabled>⏹</button>
                <div class="transport-time" id="arr-time">00:00.00</div>
            </div>
            <div class="arr-transport-right">
                <span id="arr-load-status" class="transport-load-text">Loading samples...</span>
                <div class="transport-load-bar" style="width:160px;">
                    <div class="transport-load-progress" id="arr-progress"></div>
                </div>
            </div>
        </div>

        <!-- Ruler -->
        <div class="arr-ruler-wrap" id="arr-ruler-wrap">
            <div class="arr-ruler" id="arr-ruler">
                <?php for ($i = 0; $i <= $max_time; $i += 4): ?>
                <div class="arr-ruler-mark" style="left:<?= round($i / $max_time * 100, 4) ?>%">
                    <?= sprintf('%d:%02d', floor($i/60), $i%60) ?>
                </div>
                <?php endfor; ?>
            </div>
        </div>

        <!-- Grid -->
        <div class="arr-grid-wrap" id="arr-grid-wrap">
            <div class="arr-grid" id="arr-grid">

                <!-- Playhead -->
                <div class="arr-playhead" id="arr-playhead"></div>

                <!-- Beat grid lines -->
                <?php for ($i = 0; $i <= $max_time; $i += 4): ?>
                <div class="arr-gridline" style="left:<?= round($i / $max_time * 100, 4) ?>%"></div>
                <?php endfor; ?>

                <?php if (empty($tracks)): ?>
                <div style="color:var(--text-muted);padding:3rem;text-align:center;width:100%;">
                    Add a track to start arranging!
                </div>
                <?php else: ?>
                <?php foreach ($tracks as $t):
                    $tclips = $clips_map[$t['track_id']] ?? [];
                ?>
                <!-- Track row -->
                <div class="arr-row" id="arr-row-<?= $t['track_id'] ?>"
                     data-track="<?= $t['track_id'] ?>"
                     ondragover="event.preventDefault()"
                     ondrop="arrOnDrop(event, <?= $t['track_id'] ?>)">

                    <?php foreach ($tclips as $c):
                        $l = round($c['start_time'] / $max_time * 100, 4);
                        $w = max(round($c['duration']  / $max_time * 100, 4), 0.3);
                        $type = strtolower($t['track_type']);
                    ?>
                    <div class="arr-clip arr-clip-<?= h($type) ?>"
                         id="arr-clip-<?= $c['clip_id'] ?>"
                         style="left:<?= $l ?>%;width:<?= $w ?>%"
                         draggable="true"
                         data-clip="<?= $c['clip_id'] ?>"
                         data-start="<?= $c['start_time'] ?>"
                         data-dur="<?= $c['duration'] ?>"
                         data-track="<?= $t['track_id'] ?>"
                         data-file="<?= h($c['file_path'] ?? '') ?>"
                         title="<?= h(basename($c['file_path'] ?? 'No file')) ?> | <?= formatTime($c['start_time']) ?> – <?= formatTime($c['start_time'] + $c['duration']) ?>"
                         ondragstart="arrOnDragStart(event)"
                         ondblclick="arrDeleteClip(<?= $c['clip_id'] ?>, this)">
                        <span class="arr-clip-label"><?= h(basename($c['file_path'] ?? 'Clip #' . $c['clip_id'])) ?></span>
                        <div class="arr-clip-wave">
                            <?php for ($b=0;$b<12;$b++): ?>
                            <div class="arr-wave-bar" style="height:<?= rand(20,100) ?>%"></div>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <!-- Drop hint for empty rows -->
                    <?php if (empty($tclips)): ?>
                    <div class="arr-row-hint">Drop clip here or right-click to add sample</div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>

            </div><!-- /arr-grid -->
        </div><!-- /arr-grid-wrap -->

    </div><!-- /arr-main -->

</div><!-- /arr-layout -->

<?php if ($error):   ?><div class="alert alert-error"  style="position:fixed;bottom:1rem;right:1rem;z-index:999;">⚠️ <?= h($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success" style="position:fixed;bottom:1rem;right:1rem;z-index:999;">✅ <?= $success ?></div><?php endif; ?>

<!-- Add Track Modal -->
<div class="modal-overlay" id="addTrackModal"
     onclick="if(event.target===this)this.classList.remove('active')">
    <div class="modal">
        <div class="modal-header">
            <h2>🎼 Add Track</h2>
            <button class="modal-close"
                    onclick="document.getElementById('addTrackModal').classList.remove('active')">✕</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create_track">
            <div class="form-group">
                <label>Track Name</label>
                <input type="text" name="track_name" placeholder="e.g. Kick, Lead Vocals, Bass" required maxlength="100">
            </div>
            <div class="form-group">
                <label>Track Type</label>
                <select name="track_type">
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
                        onclick="document.getElementById('addTrackModal').classList.remove('active')">Cancel</button>
                <button type="submit" class="btn btn-primary">Add Track →</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Sample Modal (right-click on row) -->
<div class="modal-overlay" id="addSampleModal"
     onclick="if(event.target===this)this.classList.remove('active')">
    <div class="modal">
        <div class="modal-header">
            <h2>🎵 Add Sample to Track</h2>
            <button class="modal-close"
                    onclick="document.getElementById('addSampleModal').classList.remove('active')">✕</button>
        </div>
        <form id="addSampleForm" enctype="multipart/form-data">
            <div class="form-group">
                <label>Start Time (seconds)</label>
                <input type="number" id="sample-start" name="start_time" min="0" step="0.01" value="0" required>
            </div>
            <div class="form-group">
                <label>Duration (seconds)</label>
                <input type="number" id="sample-dur" name="duration" min="0.01" step="0.01" value="4" required>
            </div>
            <div class="form-group">
                <label>Audio File <span style="color:var(--text-muted);font-weight:400;">(optional · max 10 MB)</span></label>
                <div class="file-input-wrapper">
                    <div class="file-input-display" id="sampleFileDisplay">📎 Click to select audio file</div>
                    <input type="file" name="audio_file" id="sampleFile"
                           accept=".mp3,.wav,.ogg,.m4a,.aac,.flac"
                           onchange="document.getElementById('sampleFileDisplay').textContent =
                               this.files[0] ? '📎 ' + this.files[0].name : '📎 Click to select audio file'">
                </div>
            </div>
            <div style="display:flex;gap:.8rem;justify-content:flex-end;margin-top:1.5rem;">
                <button type="button" class="btn btn-secondary"
                        onclick="document.getElementById('addSampleModal').classList.remove('active')">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="arrSubmitSample()">Add to Track →</button>
            </div>
        </form>
    </div>
</div>

<!-- Data for JS -->
<script>
const arrProjectId = <?= $project_id ?>;
const arrMaxTime   = <?= (float)$max_time ?>;
const arrData      = <?= json_encode($arr_data) ?>;
</script>
<script src="js/main.js?v=3"></script>
<script src="js/arrangement.js?v=3"></script>
</body>
</html>
