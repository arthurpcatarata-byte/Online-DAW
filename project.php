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
    <link rel="stylesheet" href="css/style.css?v=3">
</head>
<body>

<nav>
    <a href="dashboard.php" class="nav-logo">CatarataDAW</a>
    <ul class="nav-links">
        <li><a href="dashboard.php">📁 Projects</a></li>
        <li><a href="samples.php">🎵 Samples</a></li>
        <li><a href="profile.php">👤 Profile</a></li>
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
                &nbsp;·&nbsp;
                ♩ <?= $project['bpm'] ?? 120 ?> BPM
                &nbsp;·&nbsp;
                <?= $project['time_sig_num'] ?? 4 ?>/<?= $project['time_sig_den'] ?? 4 ?>
                <?php if (!empty($project['musical_key'])): ?>
                    &nbsp;·&nbsp; 🎵 <?= h($project['musical_key']) ?>
                <?php endif; ?>
            </div>
        </div>
        <div style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center;">
            <a href="arrangement.php?id=<?= $project_id ?>" class="btn btn-cyan">🎛 Arrangement →</a>
            <button class="btn btn-secondary" onclick="document.getElementById('collabModal').classList.add('active')">👥 Share</button>
            <button class="btn btn-secondary" onclick="document.getElementById('snapshotModal').classList.add('active')">📸 Snapshots</button>
            <button class="btn btn-secondary" onclick="document.getElementById('markerModal').classList.add('active')">🏷 Markers</button>
            <button class="btn btn-primary"
                    onclick="document.getElementById('createTrackModal').classList.add('active')">
                + Add Track
            </button>
        </div>
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

<script src="js/main.js?v=4"></script>

<!-- Collaboration Modal -->
<div class="modal-overlay" id="collabModal" onclick="if(event.target===this)this.classList.remove('active')">
    <div class="modal">
        <div class="modal-header">
            <h2>👥 Collaborators</h2>
            <button class="modal-close" onclick="document.getElementById('collabModal').classList.remove('active')">✕</button>
        </div>
        <div id="collab-list" style="margin-bottom:1rem;color:var(--text-secondary);font-size:.85rem;">Loading...</div>
        <form onsubmit="addCollaborator(event)">
            <div class="form-group">
                <label>Add by Username or Email</label>
                <input type="text" id="collab-identifier" placeholder="username or email" required>
            </div>
            <div class="form-group">
                <label>Role</label>
                <select id="collab-role">
                    <option value="viewer">Viewer</option>
                    <option value="editor">Editor</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Add →</button>
        </form>
    </div>
</div>

<!-- Snapshot Modal -->
<div class="modal-overlay" id="snapshotModal" onclick="if(event.target===this)this.classList.remove('active')">
    <div class="modal">
        <div class="modal-header">
            <h2>📸 Version Snapshots</h2>
            <button class="modal-close" onclick="document.getElementById('snapshotModal').classList.remove('active')">✕</button>
        </div>
        <div id="snapshot-list" style="margin-bottom:1rem;color:var(--text-secondary);font-size:.85rem;">Loading...</div>
        <form onsubmit="createSnapshot(event)">
            <div class="form-group">
                <label>Snapshot Name</label>
                <input type="text" id="snapshot-name" placeholder="e.g. Before remix" maxlength="100">
            </div>
            <button type="submit" class="btn btn-primary btn-sm">💾 Save Snapshot →</button>
        </form>
    </div>
</div>

<!-- Marker Modal -->
<div class="modal-overlay" id="markerModal" onclick="if(event.target===this)this.classList.remove('active')">
    <div class="modal">
        <div class="modal-header">
            <h2>🏷 Timeline Markers</h2>
            <button class="modal-close" onclick="document.getElementById('markerModal').classList.remove('active')">✕</button>
        </div>
        <div id="marker-list" style="margin-bottom:1rem;color:var(--text-secondary);font-size:.85rem;">Loading...</div>
        <form onsubmit="addMarker(event)">
            <div style="display:flex;gap:.6rem;flex-wrap:wrap;">
                <div class="form-group" style="flex:2;min-width:120px;">
                    <label>Label</label>
                    <input type="text" id="marker-label" placeholder="e.g. Chorus" required maxlength="100">
                </div>
                <div class="form-group" style="flex:1;min-width:80px;">
                    <label>Time (s)</label>
                    <input type="number" id="marker-time" min="0" step="0.25" value="0">
                </div>
                <div class="form-group" style="flex:0;min-width:50px;">
                    <label>Color</label>
                    <input type="color" id="marker-color" value="#f59e0b" style="height:38px;">
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Add Marker →</button>
        </form>
    </div>
</div>

<script>
const projectId = <?= $project_id ?>;

// -- Collaborators
function loadCollaborators() {
    fetch('api/collaborators.php?project_id=' + projectId)
        .then(r => r.json())
        .then(d => {
            if (!d.ok) { document.getElementById('collab-list').textContent = 'Error loading'; return; }
            const el = document.getElementById('collab-list');
            if (!d.collaborators.length) { el.textContent = 'No collaborators yet.'; return; }
            el.innerHTML = d.collaborators.map(c =>
                `<div style="display:flex;justify-content:space-between;align-items:center;padding:.4rem 0;border-bottom:1px solid var(--border);">
                    <span>👤 ${c.username} <span style="opacity:.6;">(${c.role})</span></span>
                    <button class="btn btn-danger btn-sm" onclick="removeCollab(${c.collab_id})">✕</button>
                </div>`
            ).join('');
        });
}
function addCollaborator(e) {
    e.preventDefault();
    const fd = new FormData();
    fd.append('action', 'add');
    fd.append('project_id', projectId);
    fd.append('identifier', document.getElementById('collab-identifier').value);
    fd.append('role', document.getElementById('collab-role').value);
    fetch('api/collaborators.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => { if (d.ok) { document.getElementById('collab-identifier').value = ''; loadCollaborators(); } else alert(d.error); });
}
function removeCollab(id) {
    if (!confirm('Remove collaborator?')) return;
    const fd = new FormData();
    fd.append('action', 'remove'); fd.append('project_id', projectId); fd.append('collab_id', id);
    fetch('api/collaborators.php', { method: 'POST', body: fd }).then(() => loadCollaborators());
}
document.getElementById('collabModal').addEventListener('transitionend', loadCollaborators);
document.getElementById('collabModal').addEventListener('click', function(e) { if (e.target === this) return; loadCollaborators(); }, { once: true });

// -- Snapshots
function loadSnapshots() {
    fetch('api/snapshots.php?project_id=' + projectId)
        .then(r => r.json())
        .then(d => {
            const el = document.getElementById('snapshot-list');
            if (!d.ok || !d.snapshots.length) { el.textContent = 'No snapshots yet.'; return; }
            el.innerHTML = d.snapshots.map(s =>
                `<div style="display:flex;justify-content:space-between;align-items:center;padding:.4rem 0;border-bottom:1px solid var(--border);">
                    <span>📸 ${s.snapshot_name} <span style="opacity:.6;font-size:.75rem;">(${s.clip_count} clips · ${s.created_at})</span></span>
                    <div style="display:flex;gap:.3rem;">
                        <button class="btn btn-secondary btn-sm" onclick="restoreSnapshot(${s.snapshot_id})">Restore</button>
                        <button class="btn btn-danger btn-sm" onclick="deleteSnapshot(${s.snapshot_id})">✕</button>
                    </div>
                </div>`
            ).join('');
        });
}
function createSnapshot(e) {
    e.preventDefault();
    const fd = new FormData();
    fd.append('action', 'create'); fd.append('project_id', projectId);
    fd.append('snapshot_name', document.getElementById('snapshot-name').value || 'Snapshot');
    fetch('api/snapshots.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => { if (d.ok) { document.getElementById('snapshot-name').value = ''; loadSnapshots(); } else alert(d.error); });
}
function restoreSnapshot(id) {
    if (!confirm('Restore this snapshot? Current clips will be replaced.')) return;
    const fd = new FormData();
    fd.append('action', 'restore'); fd.append('project_id', projectId); fd.append('snapshot_id', id);
    fetch('api/snapshots.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => { if (d.ok) location.reload(); else alert(d.error); });
}
function deleteSnapshot(id) {
    if (!confirm('Delete this snapshot?')) return;
    const fd = new FormData();
    fd.append('action', 'delete'); fd.append('project_id', projectId); fd.append('snapshot_id', id);
    fetch('api/snapshots.php', { method: 'POST', body: fd }).then(() => loadSnapshots());
}

// -- Markers
function loadMarkers() {
    fetch('api/markers.php?project_id=' + projectId)
        .then(r => r.json())
        .then(d => {
            const el = document.getElementById('marker-list');
            if (!d.ok || !d.markers.length) { el.textContent = 'No markers yet.'; return; }
            el.innerHTML = d.markers.map(m =>
                `<div style="display:flex;justify-content:space-between;align-items:center;padding:.4rem 0;border-bottom:1px solid var(--border);">
                    <span><span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:${m.color};vertical-align:middle;"></span> ${m.label} <span style="opacity:.6;font-size:.75rem;">@ ${Number(m.time).toFixed(2)}s</span></span>
                    <button class="btn btn-danger btn-sm" onclick="deleteMarker(${m.marker_id})">✕</button>
                </div>`
            ).join('');
        });
}
function addMarker(e) {
    e.preventDefault();
    const fd = new FormData();
    fd.append('action', 'add'); fd.append('project_id', projectId);
    fd.append('label', document.getElementById('marker-label').value);
    fd.append('time', document.getElementById('marker-time').value);
    fd.append('color', document.getElementById('marker-color').value);
    fetch('api/markers.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => { if (d.ok) { document.getElementById('marker-label').value = ''; loadMarkers(); } else alert(d.error); });
}
function deleteMarker(id) {
    if (!confirm('Delete this marker?')) return;
    const fd = new FormData();
    fd.append('action', 'delete'); fd.append('project_id', projectId); fd.append('marker_id', id);
    fetch('api/markers.php', { method: 'POST', body: fd }).then(() => loadMarkers());
}

// Auto-load when modals first open
['snapshotModal','markerModal','collabModal'].forEach(id => {
    const el = document.getElementById(id);
    const observer = new MutationObserver(() => {
        if (el.classList.contains('active')) {
            if (id === 'collabModal')  loadCollaborators();
            if (id === 'snapshotModal') loadSnapshots();
            if (id === 'markerModal')   loadMarkers();
        }
    });
    observer.observe(el, { attributes: true, attributeFilter: ['class'] });
});
</script>
</body>
</html>
