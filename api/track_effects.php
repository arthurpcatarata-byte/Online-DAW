<?php
// API: Manage track effects chain
require_once '../config.php';
requireLogin();
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];

// GET: list effects for a track
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $track_id = (int)($_GET['track_id'] ?? 0);
    if (!$track_id) { echo json_encode(['ok' => false, 'error' => 'Invalid track']); exit; }

    $stmt = $pdo->prepare("SELECT t.track_id FROM `Track` t JOIN `Project` p ON t.project_id=p.project_id WHERE t.track_id=? AND p.user_id=?");
    $stmt->execute([$track_id, $user_id]);
    if (!$stmt->fetch()) { echo json_encode(['ok' => false, 'error' => 'Not found']); exit; }

    $stmt = $pdo->prepare("SELECT te.*, e.effect_name, e.category FROM `TrackEffect` te JOIN `Effect` e ON te.effect_id=e.effect_id WHERE te.track_id=? ORDER BY te.position ASC");
    $stmt->execute([$track_id]);
    $chain = $stmt->fetchAll();

    $stmt2 = $pdo->query("SELECT * FROM `Effect` ORDER BY category, effect_name");
    $all = $stmt2->fetchAll();

    echo json_encode(['ok' => true, 'chain' => $chain, 'available' => $all]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']); exit;
}

$action   = $_POST['action']   ?? '';
$track_id = (int)($_POST['track_id'] ?? 0);
if (!$track_id) { echo json_encode(['ok' => false, 'error' => 'Invalid track']); exit; }

$stmt = $pdo->prepare("SELECT t.track_id FROM `Track` t JOIN `Project` p ON t.project_id=p.project_id WHERE t.track_id=? AND p.user_id=?");
$stmt->execute([$track_id, $user_id]);
if (!$stmt->fetch()) { echo json_encode(['ok' => false, 'error' => 'Not found']); exit; }

if ($action === 'add') {
    $effect_id = (int)($_POST['effect_id'] ?? 0);
    $mix       = max(0.0, min(1.0, (float)($_POST['mix'] ?? 0.5)));
    if (!$effect_id) { echo json_encode(['ok' => false, 'error' => 'Invalid effect']); exit; }

    // Get next position
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(position),0)+1 AS next_pos FROM `TrackEffect` WHERE track_id=?");
    $stmt->execute([$track_id]);
    $pos = (int)$stmt->fetchColumn();

    $pdo->prepare("INSERT INTO `TrackEffect` (track_id, effect_id, position, mix) VALUES (?,?,?,?)")
        ->execute([$track_id, $effect_id, $pos, $mix]);
    echo json_encode(['ok' => true, 'track_effect_id' => (int)$pdo->lastInsertId(), 'position' => $pos]);

} elseif ($action === 'remove') {
    $track_effect_id = (int)($_POST['track_effect_id'] ?? 0);
    $pdo->prepare("DELETE FROM `TrackEffect` WHERE track_effect_id=? AND track_id=?")->execute([$track_effect_id, $track_id]);
    echo json_encode(['ok' => true]);

} elseif ($action === 'update') {
    $track_effect_id = (int)($_POST['track_effect_id'] ?? 0);
    $mix       = max(0.0, min(1.0, (float)($_POST['mix'] ?? 0.5)));
    $is_active = !empty($_POST['is_active']) ? 1 : 0;
    $pdo->prepare("UPDATE `TrackEffect` SET mix=?, is_active=? WHERE track_effect_id=? AND track_id=?")
        ->execute([$mix, $is_active, $track_effect_id, $track_id]);
    echo json_encode(['ok' => true]);

} else {
    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}
