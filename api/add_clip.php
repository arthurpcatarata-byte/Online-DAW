<?php
// API: Quickly add a clip to a track from the arrangement view
require_once '../config.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']); exit;
}

$track_id   = (int)($_POST['track_id']   ?? 0);
$start_time = max(0.0, (float)($_POST['start_time'] ?? 0));
$duration   = (float)($_POST['duration'] ?? 4);
if ($duration <= 0) $duration = 4;

if (!$track_id) {
    echo json_encode(['ok' => false, 'error' => 'Invalid track']); exit;
}

// Verify ownership: track → project → user
$stmt = $pdo->prepare("
    SELECT t.track_id FROM `Track` t
    JOIN `Project` p ON t.project_id = p.project_id
    WHERE t.track_id = ? AND p.user_id = ?
");
$stmt->execute([$track_id, $_SESSION['user_id']]);
if (!$stmt->fetch()) {
    echo json_encode(['ok' => false, 'error' => 'Not found']); exit;
}

$file_path = null;

// Handle optional audio file upload
if (isset($_FILES['audio_file']) && $_FILES['audio_file']['error'] === UPLOAD_ERR_OK) {
    $allowed_ext = ['mp3','wav','ogg','m4a','aac','flac'];
    $ext         = strtolower(pathinfo($_FILES['audio_file']['name'], PATHINFO_EXTENSION));
    $file_size   = $_FILES['audio_file']['size'];
    $max_size    = 10 * 1024 * 1024;

    if (!in_array($ext, $allowed_ext)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid file type']); exit;
    }
    if ($file_size > $max_size) {
        echo json_encode(['ok' => false, 'error' => 'File too large (max 10 MB)']); exit;
    }

    $upload_dir = dirname(__DIR__) . '/uploads/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
    $filename = uniqid('clip_', true) . '.' . $ext;
    if (move_uploaded_file($_FILES['audio_file']['tmp_name'], $upload_dir . $filename)) {
        $file_path = 'uploads/' . $filename;
    } else {
        echo json_encode(['ok' => false, 'error' => 'Upload failed']); exit;
    }
}

$stmt = $pdo->prepare("INSERT INTO `AudioClip` (track_id, start_time, duration, file_path) VALUES (?, ?, ?, ?)");
$stmt->execute([$track_id, $start_time, $duration, $file_path]);
$clip_id = $pdo->lastInsertId();

echo json_encode([
    'ok'         => true,
    'clip_id'    => (int)$clip_id,
    'track_id'   => $track_id,
    'start_time' => $start_time,
    'duration'   => $duration,
    'file_path'  => $file_path,
]);
