<?php
require_once 'config.php';
requireLogin();

$user_id  = $_SESSION['user_id'];
$username = $_SESSION['username'];
$error    = '';
$success  = '';

// ── Create Project ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_project') {
    $project_name = trim($_POST['project_name'] ?? '');
    if (empty($project_name)) {
        $error = 'Project name cannot be empty.';
    } elseif (strlen($project_name) > 100) {
        $error = 'Project name too long (max 100 characters).';
    } else {
        $stmt = $pdo->prepare("INSERT INTO `Project` (user_id, project_name, created_date) VALUES (?, ?, CURDATE())");
        $stmt->execute([$user_id, $project_name]);
        $success = 'Project "' . h($project_name) . '" created!';
    }
}

// ── Delete Project ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_project') {
    $project_id = (int)($_POST['project_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT project_id FROM `Project` WHERE project_id = ? AND user_id = ?");
    $stmt->execute([$project_id, $user_id]);
    if ($stmt->fetch()) {
        $pdo->prepare("DELETE FROM `Project` WHERE project_id = ?")->execute([$project_id]);
        $success = 'Project deleted.';
    }
}

// ── Fetch Projects with track count ──────────────────────────
$stmt = $pdo->prepare("
    SELECT p.*, COUNT(t.track_id) AS track_count
    FROM `Project` p
    LEFT JOIN `Track` t ON p.project_id = t.project_id
    WHERE p.user_id = ?
    GROUP BY p.project_id
    ORDER BY p.created_date DESC, p.project_id DESC
");
$stmt->execute([$user_id]);
$projects = $stmt->fetchAll();

$total_projects = count($projects);
$total_tracks   = array_sum(array_column($projects, 'track_count'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — CatarataDAW</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<nav>
    <a href="dashboard.php" class="nav-logo">CatarataDAW</a>
    <ul class="nav-links">
        <li><span style="color:var(--text-secondary);font-size:.85rem;">👤 <?= h($username) ?></span></li>
        <li><a href="logout.php">Sign Out</a></li>
    </ul>
</nav>

<div class="container">

    <?php if ($error):   ?><div class="alert alert-error">⚠️ <?= h($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success">✅ <?= $success ?></div><?php endif; ?>

    <div class="user-greeting">
        <h2>Welcome back, <span><?= h($username) ?></span> 🎵</h2>
        <p>Manage your music projects and tracks below.</p>
    </div>

    <!-- Stats -->
    <div class="stats-bar">
        <div class="stat-card">
            <div class="stat-number"><?= $total_projects ?></div>
            <div class="stat-label">Projects</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $total_tracks ?></div>
            <div class="stat-label">Total Tracks</div>
        </div>
    </div>

    <!-- Projects Section -->
    <div class="action-bar">
        <div class="section-title">
            🎶 My Projects
            <span class="count-badge"><?= $total_projects ?></span>
        </div>
        <button class="btn btn-primary"
                onclick="document.getElementById('createModal').classList.add('active')">
            + New Project
        </button>
    </div>

    <?php if (empty($projects)): ?>
        <div class="empty-state">
            <span class="empty-state-icon">🎵</span>
            <h3>No projects yet</h3>
            <p>Create your first music project to get started!</p>
            <button class="btn btn-primary" style="margin-top:1rem;"
                    onclick="document.getElementById('createModal').classList.add('active')">
                + Create First Project
            </button>
        </div>
    <?php else: ?>
        <div class="projects-grid">
            <?php foreach ($projects as $p): ?>
            <div class="project-card">
                <div class="project-card-header">
                    <div>
                        <a href="project.php?id=<?= $p['project_id'] ?>" class="project-name">
                            <?= h($p['project_name']) ?>
                        </a>
                        <div class="project-date">📅 <?= h($p['created_date']) ?></div>
                    </div>
                </div>
                <div class="project-meta">
                    <div class="track-count">
                        🎼 <?= $p['track_count'] ?> track<?= $p['track_count'] != 1 ? 's' : '' ?>
                    </div>
                    <div style="display:flex;gap:.5rem;">
                        <a href="project.php?id=<?= $p['project_id'] ?>" class="btn btn-secondary btn-sm">
                            Open →
                        </a>
                        <form method="POST" style="display:inline;"
                              onsubmit="return confirm('Delete &quot;<?= h(addslashes($p['project_name'])) ?>&quot; and all its tracks?')">
                            <input type="hidden" name="action"     value="delete_project">
                            <input type="hidden" name="project_id" value="<?= $p['project_id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm">🗑</button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div><!-- /container -->

<!-- Create Project Modal -->
<div class="modal-overlay" id="createModal"
     onclick="if(event.target===this)this.classList.remove('active')">
    <div class="modal">
        <div class="modal-header">
            <h2>🎵 New Project</h2>
            <button class="modal-close"
                    onclick="document.getElementById('createModal').classList.remove('active')">✕</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create_project">
            <div class="form-group">
                <label for="project_name">Project Name</label>
                <input type="text" id="project_name" name="project_name"
                       placeholder="e.g. Summer EP, Beat Pack Vol.1"
                       required maxlength="100">
            </div>
            <div style="display:flex;gap:.8rem;justify-content:flex-end;margin-top:1.5rem;">
                <button type="button" class="btn btn-secondary"
                        onclick="document.getElementById('createModal').classList.remove('active')">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">Create Project →</button>
            </div>
        </form>
    </div>
</div>

<script src="js/main.js"></script>
</body>
</html>
