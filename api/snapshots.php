<?php
// API: Create and manage snapshots (version history)
require_once '../config.php';
requireLogin();
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $project_id = (int)($_GET['project_id'] ?? 0);
    if (!$project_id) { echo json_encode(['ok' => false]); exit; }

    $stmt = $pdo->prepare("SELECT project_id FROM `Project` WHERE project_id=? AND user_id=?");
    $stmt->execute([$project_id, $user_id]);
    if (!$stmt->fetch()) { echo json_encode(['ok' => false, 'error' => 'Not found']); exit; }

    $stmt = $pdo->prepare("SELECT s.*, (SELECT COUNT(*) FROM `SnapshotClip` sc WHERE sc.snapshot_id=s.snapshot_id) AS clip_count FROM `Snapshot` s WHERE s.project_id=? ORDER BY s.created_at DESC");
    $stmt->execute([$project_id]);
    echo json_encode(['ok' => true, 'snapshots' => $stmt->fetchAll()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']); exit;
}

$action     = $_POST['action']     ?? '';
$project_id = (int)($_POST['project_id'] ?? 0);
if (!$project_id) { echo json_encode(['ok' => false, 'error' => 'Invalid project']); exit; }

$stmt = $pdo->prepare("SELECT project_id FROM `Project` WHERE project_id=? AND user_id=?");
$stmt->execute([$project_id, $user_id]);
if (!$stmt->fetch()) { echo json_encode(['ok' => false, 'error' => 'Not found']); exit; }

if ($action === 'create') {
    $name = trim($_POST['snapshot_name'] ?? 'Snapshot ' . date('Y-m-d H:i'));
    if (strlen($name) > 100) $name = substr($name, 0, 100);

    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO `Snapshot` (project_id, snapshot_name) VALUES (?,?)")->execute([$project_id, $name]);
    $snap_id = (int)$pdo->lastInsertId();

    // Copy all current clips into SnapshotClip
    $pdo->prepare("
        INSERT INTO `SnapshotClip` (snapshot_id, track_id, start_time, duration, file_path)
        SELECT ?, ac.track_id, ac.start_time, ac.duration, ac.file_path
        FROM `AudioClip` ac
        JOIN `Track` t ON ac.track_id = t.track_id
        WHERE t.project_id = ?
    ")->execute([$snap_id, $project_id]);
    $pdo->commit();

    echo json_encode(['ok' => true, 'snapshot_id' => $snap_id, 'snapshot_name' => $name]);

} elseif ($action === 'restore') {
    $snapshot_id = (int)($_POST['snapshot_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT snapshot_id FROM `Snapshot` WHERE snapshot_id=? AND project_id=?");
    $stmt->execute([$snapshot_id, $project_id]);
    if (!$stmt->fetch()) { echo json_encode(['ok' => false, 'error' => 'Snapshot not found']); exit; }

    $pdo->beginTransaction();
    // Delete current clips (but keep files on disk — they may be shared)
    $pdo->prepare("DELETE ac FROM `AudioClip` ac JOIN `Track` t ON ac.track_id=t.track_id WHERE t.project_id=?")->execute([$project_id]);

    // Restore from snapshot
    $pdo->prepare("
        INSERT INTO `AudioClip` (track_id, start_time, duration, file_path)
        SELECT sc.track_id, sc.start_time, sc.duration, sc.file_path
        FROM `SnapshotClip` sc WHERE sc.snapshot_id=?
    ")->execute([$snapshot_id]);
    $pdo->commit();

    echo json_encode(['ok' => true]);

} elseif ($action === 'delete') {
    $snapshot_id = (int)($_POST['snapshot_id'] ?? 0);
    $pdo->prepare("DELETE FROM `Snapshot` WHERE snapshot_id=? AND project_id=?")->execute([$snapshot_id, $project_id]);
    echo json_encode(['ok' => true]);

} else {
    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}
