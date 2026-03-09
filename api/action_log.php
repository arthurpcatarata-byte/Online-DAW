<?php
// API: Log an action (for undo/redo audit trail)
require_once '../config.php';
requireLogin();
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $project_id = (int)($_GET['project_id'] ?? 0);
    $limit      = min(100, max(1, (int)($_GET['limit'] ?? 50)));
    if (!$project_id) { echo json_encode(['ok' => false]); exit; }

    $stmt = $pdo->prepare("SELECT project_id FROM `Project` WHERE project_id=? AND user_id=?");
    $stmt->execute([$project_id, $user_id]);
    if (!$stmt->fetch()) { echo json_encode(['ok' => false, 'error' => 'Not found']); exit; }

    $stmt = $pdo->prepare("SELECT al.*, u.username FROM `ActionLog` al JOIN `User` u ON al.user_id=u.user_id WHERE al.project_id=? ORDER BY al.created_at DESC LIMIT ?");
    $stmt->execute([$project_id, $limit]);
    echo json_encode(['ok' => true, 'actions' => $stmt->fetchAll()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']); exit;
}

$project_id  = (int)($_POST['project_id']  ?? 0);
$action_type = trim($_POST['action_type']  ?? '');
$entity_type = trim($_POST['entity_type']  ?? '');
$entity_id   = (int)($_POST['entity_id']   ?? 0);
$old_data    = $_POST['old_data']           ?? null;
$new_data    = $_POST['new_data']           ?? null;

if (!$project_id || empty($action_type) || empty($entity_type)) {
    echo json_encode(['ok' => false, 'error' => 'Missing fields']); exit;
}

$stmt = $pdo->prepare("SELECT project_id FROM `Project` WHERE project_id=? AND user_id=?");
$stmt->execute([$project_id, $user_id]);
if (!$stmt->fetch()) { echo json_encode(['ok' => false, 'error' => 'Not found']); exit; }

// Validate JSON if provided
if ($old_data !== null) { json_decode($old_data); if (json_last_error() !== JSON_ERROR_NONE) $old_data = null; }
if ($new_data !== null) { json_decode($new_data); if (json_last_error() !== JSON_ERROR_NONE) $new_data = null; }

$pdo->prepare("INSERT INTO `ActionLog` (project_id, user_id, action_type, entity_type, entity_id, old_data, new_data) VALUES (?,?,?,?,?,?,?)")
    ->execute([$project_id, $user_id, $action_type, $entity_type, $entity_id ?: null, $old_data, $new_data]);

echo json_encode(['ok' => true, 'log_id' => (int)$pdo->lastInsertId()]);
