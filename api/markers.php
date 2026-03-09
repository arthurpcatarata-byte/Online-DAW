<?php
// API: Manage timeline markers
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

    $stmt = $pdo->prepare("SELECT * FROM `Marker` WHERE project_id=? ORDER BY `time` ASC");
    $stmt->execute([$project_id]);
    echo json_encode(['ok' => true, 'markers' => $stmt->fetchAll()]);
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

if ($action === 'add') {
    $time  = max(0.0, (float)($_POST['time']  ?? 0));
    $label = trim($_POST['label'] ?? '');
    $color = trim($_POST['color'] ?? '#f59e0b');
    if (empty($label) || strlen($label) > 100) { echo json_encode(['ok' => false, 'error' => 'Label required (max 100 chars)']); exit; }
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#f59e0b';

    $pdo->prepare("INSERT INTO `Marker` (project_id, `time`, label, color) VALUES (?,?,?,?)")->execute([$project_id, $time, $label, $color]);
    echo json_encode(['ok' => true, 'marker_id' => (int)$pdo->lastInsertId(), 'time' => $time, 'label' => $label, 'color' => $color]);

} elseif ($action === 'update') {
    $marker_id = (int)($_POST['marker_id'] ?? 0);
    $time  = max(0.0, (float)($_POST['time']  ?? 0));
    $label = trim($_POST['label'] ?? '');
    $color = trim($_POST['color'] ?? '#f59e0b');
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#f59e0b';
    $pdo->prepare("UPDATE `Marker` SET `time`=?, label=?, color=? WHERE marker_id=? AND project_id=?")->execute([$time, $label, $color, $marker_id, $project_id]);
    echo json_encode(['ok' => true]);

} elseif ($action === 'delete') {
    $marker_id = (int)($_POST['marker_id'] ?? 0);
    $pdo->prepare("DELETE FROM `Marker` WHERE marker_id=? AND project_id=?")->execute([$marker_id, $project_id]);
    echo json_encode(['ok' => true]);

} else {
    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}
