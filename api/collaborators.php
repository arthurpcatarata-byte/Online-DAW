<?php
// API: Manage project collaborators
require_once '../config.php';
requireLogin();
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $project_id = (int)($_GET['project_id'] ?? 0);
    if (!$project_id) { echo json_encode(['ok' => false]); exit; }

    // Owner or collaborator can view
    $stmt = $pdo->prepare("SELECT user_id FROM `Project` WHERE project_id=?");
    $stmt->execute([$project_id]);
    $proj = $stmt->fetch();
    if (!$proj) { echo json_encode(['ok' => false, 'error' => 'Not found']); exit; }

    $isOwner = ($proj['user_id'] == $user_id);
    if (!$isOwner) {
        $stmt = $pdo->prepare("SELECT collab_id FROM `ProjectCollaborator` WHERE project_id=? AND user_id=?");
        $stmt->execute([$project_id, $user_id]);
        if (!$stmt->fetch()) { echo json_encode(['ok' => false, 'error' => 'Not found']); exit; }
    }

    $stmt = $pdo->prepare("SELECT pc.*, u.username, u.email FROM `ProjectCollaborator` pc JOIN `User` u ON pc.user_id=u.user_id WHERE pc.project_id=? ORDER BY pc.added_at");
    $stmt->execute([$project_id]);
    echo json_encode(['ok' => true, 'collaborators' => $stmt->fetchAll(), 'is_owner' => $isOwner]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']); exit;
}

$action     = $_POST['action']     ?? '';
$project_id = (int)($_POST['project_id'] ?? 0);
if (!$project_id) { echo json_encode(['ok' => false, 'error' => 'Invalid project']); exit; }

// Only owner can manage collaborators
$stmt = $pdo->prepare("SELECT project_id FROM `Project` WHERE project_id=? AND user_id=?");
$stmt->execute([$project_id, $user_id]);
if (!$stmt->fetch()) { echo json_encode(['ok' => false, 'error' => 'Not authorized']); exit; }

if ($action === 'add') {
    $identifier = trim($_POST['identifier'] ?? '');
    $role       = in_array($_POST['role'] ?? '', ['viewer','editor']) ? $_POST['role'] : 'viewer';

    if (empty($identifier)) { echo json_encode(['ok' => false, 'error' => 'Username or email required']); exit; }

    $stmt = $pdo->prepare("SELECT user_id, username FROM `User` WHERE username=? OR email=? LIMIT 1");
    $stmt->execute([$identifier, $identifier]);
    $target = $stmt->fetch();
    if (!$target) { echo json_encode(['ok' => false, 'error' => 'User not found']); exit; }
    if ($target['user_id'] == $user_id) { echo json_encode(['ok' => false, 'error' => 'Cannot add yourself']); exit; }

    // Check for existing
    $stmt = $pdo->prepare("SELECT collab_id FROM `ProjectCollaborator` WHERE project_id=? AND user_id=?");
    $stmt->execute([$project_id, $target['user_id']]);
    if ($stmt->fetch()) { echo json_encode(['ok' => false, 'error' => 'Already a collaborator']); exit; }

    $pdo->prepare("INSERT INTO `ProjectCollaborator` (project_id, user_id, role) VALUES (?,?,?)")
        ->execute([$project_id, $target['user_id'], $role]);
    echo json_encode(['ok' => true, 'collab_id' => (int)$pdo->lastInsertId(), 'username' => $target['username'], 'role' => $role]);

} elseif ($action === 'remove') {
    $collab_id = (int)($_POST['collab_id'] ?? 0);
    $pdo->prepare("DELETE FROM `ProjectCollaborator` WHERE collab_id=? AND project_id=?")->execute([$collab_id, $project_id]);
    echo json_encode(['ok' => true]);

} elseif ($action === 'update_role') {
    $collab_id = (int)($_POST['collab_id'] ?? 0);
    $role      = in_array($_POST['role'] ?? '', ['viewer','editor']) ? $_POST['role'] : 'viewer';
    $pdo->prepare("UPDATE `ProjectCollaborator` SET role=? WHERE collab_id=? AND project_id=?")->execute([$role, $collab_id, $project_id]);
    echo json_encode(['ok' => true]);

} else {
    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}
