<?php
require_once __DIR__ . '/../bootstrap.php';

function list_checklist(int $issue_id): array {
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT * FROM issue_checklist WHERE issue_id = ? ORDER BY sort_order, created_at');
    $stmt->execute([$issue_id]);
    return ['success' => true, 'data' => $stmt->fetchAll()];
}

function create_checklist_item(int $issue_id, string $text): array {
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT MAX(sort_order) FROM issue_checklist WHERE issue_id = ?');
    $stmt->execute([$issue_id]);
    $maxOrder = (int)$stmt->fetchColumn();
    $pdo->prepare('INSERT INTO issue_checklist (issue_id, text, sort_order) VALUES (?, ?, ?)')->execute([$issue_id, $text, $maxOrder + 1]);
    return ['success' => true, 'id' => (int)$pdo->lastInsertId()];
}

function update_checklist_item(int $id, array $fields): array {
    $pdo = get_db();
    $allowed = ['text', 'checked', 'sort_order'];
    $sets = []; $params = [];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $fields)) { $sets[] = "$f = ?"; $params[] = $fields[$f]; }
    }
    if (empty($sets)) return ['success' => false, 'error' => 'No fields'];
    $params[] = $id;
    $pdo->prepare('UPDATE issue_checklist SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    return ['success' => true];
}

function delete_checklist_item(int $id): array {
    get_db()->prepare('DELETE FROM issue_checklist WHERE id = ?')->execute([$id]);
    return ['success' => true];
}

if (php_sapi_name() !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    require_auth();
    header('Content-Type: application/json');
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    $b = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];
    if ($method === 'POST') { verify_csrf(); }

    match(true) {
        $method === 'GET'  && $action === 'list'   => print json_encode(list_checklist((int)($_GET['issue_id'] ?? 0))),
        $method === 'POST' && $action === 'create' => print json_encode(!empty($b['text']) && !empty($b['issue_id']) ? create_checklist_item((int)$b['issue_id'], trim($b['text'])) : ['success'=>false,'error'=>'issue_id and text required']),
        $method === 'POST' && $action === 'update' => print json_encode(!empty($b['id']) ? update_checklist_item((int)$b['id'], $b) : ['success'=>false,'error'=>'id required']),
        $method === 'POST' && $action === 'delete' => print json_encode(!empty($b['id']) ? delete_checklist_item((int)$b['id']) : ['success'=>false,'error'=>'id required']),
        default => null
    };
    exit;
}
