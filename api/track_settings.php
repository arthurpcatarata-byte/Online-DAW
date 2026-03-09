<?php
// API: Get or update track mixer settings (volume, pan, mute, solo)
require_once '../config.php';
requireLogin();
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $track_id = (int)($_GET['track_id'] ?? 0);
    if (!$track_id) { echo json_encode(['ok' => false, 'error' => 'Invalid track']); exit; }

    // Verify ownership
    $stmt = $pdo->prepare("SELECT t.track_id FROM `Track` t JOIN `Project` p ON t.project_id=p.project_id WHERE t.track_id=? AND p.user_id=?");
    $stmt->execute([$track_id, $user_id]);
    if (!$stmt->fetch()) { echo json_encode(['ok' => false, 'error' => 'Not found']); exit; }

    $stmt = $pdo->prepare("SELECT * FROM `TrackSettings` WHERE track_id=?");
    $stmt->execute([$track_id]);
    $settings = $stmt->fetch();
    if (!$settings) {
        $pdo->prepare("INSERT INTO `TrackSettings` (track_id) VALUES (?)")->execute([$track_id]);
        $settings = ['track_id' => $track_id, 'volume' => 1.0, 'pan' => 0.0, 'is_muted' => 0, 'is_solo' => 0];
    }
    echo json_encode(['ok' => true, 'settings' => $settings]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']); exit;
}

$track_id = (int)($_POST['track_id'] ?? 0);
if (!$track_id) { echo json_encode(['ok' => false, 'error' => 'Invalid track']); exit; }

$stmt = $pdo->prepare("SELECT t.track_id FROM `Track` t JOIN `Project` p ON t.project_id=p.project_id WHERE t.track_id=? AND p.user_id=?");
$stmt->execute([$track_id, $user_id]);
if (!$stmt->fetch()) { echo json_encode(['ok' => false, 'error' => 'Not found']); exit; }

// Upsert settings — only update fields that were actually sent
$stmt = $pdo->prepare("SELECT * FROM `TrackSettings` WHERE track_id=?");
$stmt->execute([$track_id]);
$existing = $stmt->fetch();

$volume   = isset($_POST['volume'])   ? max(0.0, min(2.0, (float)$_POST['volume']))   : ($existing ? (float)$existing['volume'] : 1.0);
$pan      = isset($_POST['pan'])      ? max(-1.0, min(1.0, (float)$_POST['pan']))      : ($existing ? (float)$existing['pan'] : 0.0);
$is_muted = isset($_POST['is_muted']) ? ((int)$_POST['is_muted'] ? 1 : 0)              : ($existing ? (int)$existing['is_muted'] : 0);
$is_solo  = isset($_POST['is_solo'])  ? ((int)$_POST['is_solo'] ? 1 : 0)               : ($existing ? (int)$existing['is_solo'] : 0);

if ($existing) {
    $pdo->prepare("UPDATE `TrackSettings` SET volume=?, pan=?, is_muted=?, is_solo=? WHERE track_id=?")
        ->execute([$volume, $pan, $is_muted, $is_solo, $track_id]);
} else {
    $pdo->prepare("INSERT INTO `TrackSettings` (track_id, volume, pan, is_muted, is_solo) VALUES (?,?,?,?,?)")
        ->execute([$track_id, $volume, $pan, $is_muted, $is_solo]);
}

echo json_encode(['ok' => true, 'track_id' => $track_id, 'volume' => $volume, 'pan' => $pan, 'is_muted' => $is_muted, 'is_solo' => $is_solo]);
