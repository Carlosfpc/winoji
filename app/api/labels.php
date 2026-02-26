<?php
require_once __DIR__ . '/../bootstrap.php';
function list_labels(int $project_id): array {
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT * FROM labels WHERE project_id = ? ORDER BY name');
    $stmt->execute([$project_id]);
    return $stmt->fetchAll();
}

function create_label(int $project_id, string $name, string $color): array {
    // Validate color is a hex color (#rrggbb or #rgb)
    if (!preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $color)) {
        return ['success' => false, 'error' => 'Color inválido, usa formato #rrggbb'];
    }
    $pdo = get_db();
    $stmt = $pdo->prepare('INSERT INTO labels (project_id, name, color) VALUES (?,?,?)');
    $stmt->execute([$project_id, $name, $color]);
    return ['success' => true, 'id' => (int)$pdo->lastInsertId()];
}

function update_label(int $id, string $name, string $color): array {
    if ($id <= 0) return ['success' => false, 'error' => 'ID inválido'];
    if ($name === '') return ['success' => false, 'error' => 'El nombre no puede estar vacío'];
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) return ['success' => false, 'error' => 'Color inválido, usa formato #rrggbb'];
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT project_id FROM labels WHERE id = ?');
    $stmt->execute([$id]);
    $label = $stmt->fetch();
    if (!$label) return ['success' => false, 'error' => 'Label no encontrado', 'code' => 404];
    require_project_access((int)$label['project_id']);
    $pdo->prepare('UPDATE labels SET name = ?, color = ? WHERE id = ?')->execute([$name, $color, $id]);
    return ['success' => true, 'id' => $id];
}

function delete_label(int $id): array {
    get_db()->prepare('DELETE FROM labels WHERE id = ?')->execute([$id]);
    return ['success' => true];
}

function get_issue_labels(int $issue_id): array {
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT l.* FROM labels l JOIN issue_labels il ON l.id = il.label_id WHERE il.issue_id = ?');
    $stmt->execute([$issue_id]);
    return $stmt->fetchAll();
}

function add_label_to_issue(int $issue_id, int $label_id): array {
    try {
        get_db()->prepare('INSERT INTO issue_labels (issue_id, label_id) VALUES (?,?)')->execute([$issue_id, $label_id]);
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Label already added'];
    }
}

function remove_label_from_issue(int $issue_id, int $label_id): array {
    get_db()->prepare('DELETE FROM issue_labels WHERE issue_id = ? AND label_id = ?')->execute([$issue_id, $label_id]);
    return ['success' => true];
}

if (php_sapi_name() !== 'cli') {
    require_auth();
    header('Content-Type: application/json');
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    $b = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];

    if ($method === 'POST') { verify_csrf(); }
    if (in_array($action, ['list','create'])) require_project_access((int)(($action === 'list' ? $_GET : $b)['project_id'] ?? 0));
    match(true) {
        $method === 'GET'  && $action === 'list'              => print json_encode(['success'=>true,'data'=>list_labels((int)($_GET['project_id']??0))]),
        $method === 'GET'  && $action === 'issue_labels'      => print json_encode(['success'=>true,'data'=>get_issue_labels((int)($_GET['issue_id']??0))]),
        $method === 'POST' && $action === 'create'            => print json_encode(!empty($b['project_id']) && !empty($b['name']) ? create_label((int)$b['project_id'], $b['name'], $b['color']??'#34BF1F') : ['success'=>false,'error'=>'project_id and name required']),
        $method === 'POST' && $action === 'update'            => print json_encode(update_label((int)($b['id']??0), trim((string)($b['name']??'')), trim((string)($b['color']??'')))),
        $method === 'POST' && $action === 'delete'            => print json_encode(delete_label((int)($b['id']??0))),
        $method === 'POST' && $action === 'add_to_issue'      => print json_encode(add_label_to_issue((int)($b['issue_id']??0), (int)($b['label_id']??0))),
        $method === 'POST' && $action === 'remove_from_issue' => print json_encode(remove_label_from_issue((int)($b['issue_id']??0), (int)($b['label_id']??0))),
        default => null
    };
    exit;
}
