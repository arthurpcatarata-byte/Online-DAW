<?php
// API: Manage user sample library
require_once '../config.php';
requireLogin();
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $tag = trim($_GET['tag'] ?? '');
    $search = trim($_GET['search'] ?? '');

    $sql = "SELECT s.*, GROUP_CONCAT(t.tag_name ORDER BY t.tag_name SEPARATOR ',') AS tags
            FROM `Sample` s
            LEFT JOIN `SampleTag` st ON s.sample_id = st.sample_id
            LEFT JOIN `Tag` t ON st.tag_id = t.tag_id
            WHERE s.user_id = ?";
    $params = [$user_id];

    if ($tag) {
        $sql .= " AND s.sample_id IN (SELECT st2.sample_id FROM `SampleTag` st2 JOIN `Tag` t2 ON st2.tag_id=t2.tag_id WHERE t2.tag_name=?)";
        $params[] = $tag;
    }
    if ($search) {
        $sql .= " AND s.sample_name LIKE ?";
        $params[] = '%' . $search . '%';
    }

    $sql .= " GROUP BY s.sample_id ORDER BY s.uploaded_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $samples = $stmt->fetchAll();
    foreach ($samples as &$s) { $s['tags'] = $s['tags'] ? explode(',', $s['tags']) : []; }

    // Also return available tags
    $tags = $pdo->query("SELECT * FROM `Tag` ORDER BY tag_name")->fetchAll();

    echo json_encode(['ok' => true, 'samples' => $samples, 'tags' => $tags]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']); exit;
}

$action = $_POST['action'] ?? 'upload';

if ($action === 'upload') {
    $sample_name = trim($_POST['sample_name'] ?? '');
    $duration    = max(0.0, (float)($_POST['duration'] ?? 0));
    $tag_ids     = array_filter(array_map('intval', (array)($_POST['tag_ids'] ?? [])));

    if (empty($sample_name) || strlen($sample_name) > 100) {
        echo json_encode(['ok' => false, 'error' => 'Sample name required (max 100 chars)']); exit;
    }

    if (!isset($_FILES['audio_file']) || $_FILES['audio_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => 'Audio file required']); exit;
    }

    $allowed_ext = ['mp3','wav','ogg','m4a','aac','flac'];
    $ext = strtolower(pathinfo($_FILES['audio_file']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_ext)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid file type']); exit;
    }
    if ($_FILES['audio_file']['size'] > 10 * 1024 * 1024) {
        echo json_encode(['ok' => false, 'error' => 'File too large (max 10 MB)']); exit;
    }

    $upload_dir = dirname(__DIR__) . '/uploads/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
    $filename = uniqid('sample_', true) . '.' . $ext;
    if (!move_uploaded_file($_FILES['audio_file']['tmp_name'], $upload_dir . $filename)) {
        echo json_encode(['ok' => false, 'error' => 'Upload failed']); exit;
    }
    $file_path = 'uploads/' . $filename;

    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO `Sample` (user_id, sample_name, file_path, duration) VALUES (?,?,?,?)")
        ->execute([$user_id, $sample_name, $file_path, $duration]);
    $sample_id = (int)$pdo->lastInsertId();

    foreach ($tag_ids as $tid) {
        $pdo->prepare("INSERT IGNORE INTO `SampleTag` (sample_id, tag_id) VALUES (?,?)")->execute([$sample_id, $tid]);
    }
    $pdo->commit();

    echo json_encode(['ok' => true, 'sample_id' => $sample_id, 'file_path' => $file_path, 'sample_name' => $sample_name]);

} elseif ($action === 'delete') {
    $sample_id = (int)($_POST['sample_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT file_path FROM `Sample` WHERE sample_id=? AND user_id=?");
    $stmt->execute([$sample_id, $user_id]);
    $row = $stmt->fetch();
    if ($row) {
        if ($row['file_path'] && file_exists(dirname(__DIR__) . '/' . $row['file_path'])) {
            unlink(dirname(__DIR__) . '/' . $row['file_path']);
        }
        $pdo->prepare("DELETE FROM `Sample` WHERE sample_id=?")->execute([$sample_id]);
    }
    echo json_encode(['ok' => true]);

} elseif ($action === 'add_tag') {
    $sample_id = (int)($_POST['sample_id'] ?? 0);
    $tag_id    = (int)($_POST['tag_id']    ?? 0);
    $stmt = $pdo->prepare("SELECT sample_id FROM `Sample` WHERE sample_id=? AND user_id=?");
    $stmt->execute([$sample_id, $user_id]);
    if ($stmt->fetch()) {
        $pdo->prepare("INSERT IGNORE INTO `SampleTag` (sample_id, tag_id) VALUES (?,?)")->execute([$sample_id, $tag_id]);
    }
    echo json_encode(['ok' => true]);

} else {
    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}
