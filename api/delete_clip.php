<?php
// API: Delete a clip (used from arrangement view via AJAX)
require_once '../config.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false]); exit;
}

$clip_id = (int)($_POST['clip_id'] ?? 0);
if (!$clip_id) { echo json_encode(['ok' => false]); exit; }

$stmt = $pdo->prepare("
    SELECT a.clip_id, a.file_path FROM `AudioClip` a
    JOIN `Track` t   ON a.track_id   = t.track_id
    JOIN `Project` p ON t.project_id = p.project_id
    WHERE a.clip_id = ? AND p.user_id = ?
");
$stmt->execute([$clip_id, $_SESSION['user_id']]);
$row = $stmt->fetch();

if (!$row) { echo json_encode(['ok' => false]); exit; }

if ($row['file_path'] && file_exists(dirname(__DIR__) . '/' . $row['file_path'])) {
    unlink(dirname(__DIR__) . '/' . $row['file_path']);
}

$pdo->prepare("DELETE FROM `AudioClip` WHERE clip_id = ?")->execute([$clip_id]);
echo json_encode(['ok' => true]);
