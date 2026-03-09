<?php
// API: Log a project export
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

    $stmt = $pdo->prepare("SELECT el.*, u.username FROM `ExportLog` el JOIN `User` u ON el.user_id=u.user_id WHERE el.project_id=? ORDER BY el.created_at DESC");
    $stmt->execute([$project_id]);
    echo json_encode(['ok' => true, 'exports' => $stmt->fetchAll()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']); exit;
}

$project_id = (int)($_POST['project_id'] ?? 0);
$format     = in_array($_POST['format'] ?? '', ['wav','mp3','ogg','flac']) ? $_POST['format'] : 'wav';
$file_size  = max(0, (int)($_POST['file_size'] ?? 0));

if (!$project_id) { echo json_encode(['ok' => false, 'error' => 'Invalid project']); exit; }

$stmt = $pdo->prepare("SELECT project_id FROM `Project` WHERE project_id=? AND user_id=?");
$stmt->execute([$project_id, $user_id]);
if (!$stmt->fetch()) { echo json_encode(['ok' => false, 'error' => 'Not found']); exit; }

$pdo->prepare("INSERT INTO `ExportLog` (project_id, user_id, format, file_size) VALUES (?,?,?,?)")
    ->execute([$project_id, $user_id, $format, $file_size ?: null]);

echo json_encode(['ok' => true, 'export_id' => (int)$pdo->lastInsertId()]);
