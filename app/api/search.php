<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_auth();
header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
$project_id = (int)($_GET['project_id'] ?? 0);

if (strlen($q) < 2 || !$project_id) {
    echo json_encode(['results' => []]);
    exit;
}

require_project_access($project_id);

$like = '%' . $q . '%';
$pdo = get_db();

// Search issues within the validated project
$stmt = $pdo->prepare('SELECT id, title, status, priority, "issue" as type FROM issues WHERE project_id = ? AND (title LIKE ? OR description LIKE ?) ORDER BY created_at DESC LIMIT 10');
$stmt->execute([$project_id, $like, $like]);
$issues = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Search wiki pages: only general pages + pages of this project
$stmt = $pdo->prepare('SELECT id, title, "page" as type FROM pages WHERE (scope = "general" OR (scope = "project" AND project_id = ?)) AND (title LIKE ? OR content LIKE ?) ORDER BY updated_at DESC LIMIT 10');
$stmt->execute([$project_id, $like, $like]);
$pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['results' => array_merge($issues, $pages)]);
