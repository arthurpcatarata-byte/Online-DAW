<?php
// API: Move a clip to a new start_time (called on drag-drop)
require_once '../config.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']); exit;
}

$clip_id    = (int)($_POST['clip_id']    ?? 0);
$start_time = (float)($_POST['start_time'] ?? 0);
$start_time = max(0.0, $start_time);

if (!$clip_id) {
    echo json_encode(['ok' => false, 'error' => 'Invalid clip']); exit;
}

// Verify the clip belongs to this user via track → project → user
$stmt = $pdo->prepare("
    SELECT a.clip_id FROM `AudioClip` a
    JOIN `Track` t    ON a.track_id   = t.track_id
    JOIN `Project` p  ON t.project_id = p.project_id
    WHERE a.clip_id = ? AND p.user_id = ?
");
$stmt->execute([$clip_id, $_SESSION['user_id']]);

if (!$stmt->fetch()) {
    echo json_encode(['ok' => false, 'error' => 'Not found']); exit;
}

$pdo->prepare("UPDATE `AudioClip` SET start_time = ? WHERE clip_id = ?")
    ->execute([$start_time, $clip_id]);

echo json_encode(['ok' => true, 'clip_id' => $clip_id, 'start_time' => $start_time]);
