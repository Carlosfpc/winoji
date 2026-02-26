<?php
require_once __DIR__ . '/../bootstrap.php';
function list_projects(): array {
    $stmt = get_db()->query('SELECT * FROM projects ORDER BY created_at DESC');
    return $stmt->fetchAll();
}

function create_project(string $name, ?string $description, int $user_id): array {
    $pdo = get_db();
    $pdo->prepare('INSERT INTO projects (name, description, created_by) VALUES (?,?,?)')->execute([$name, $description, $user_id]);
    $id = (int)$pdo->lastInsertId();
    require_once __DIR__ . '/issue_types.php';
    seed_default_issue_types($id);
    return ['success' => true, 'id' => $id];
}

function update_project(int $id, string $name, ?string $description, int $user_id): array {
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT created_by FROM projects WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) return ['success' => false, 'error' => 'Proyecto no encontrado'];
    // Only creator or admin can update
    $user = current_user();
    if ($row['created_by'] !== $user_id && $user['role'] !== 'admin') {
        return ['success' => false, 'error' => 'Sin permiso para editar este proyecto'];
    }
    $pdo->prepare('UPDATE projects SET name = ?, description = ? WHERE id = ?')->execute([$name, $description, $id]);
    return ['success' => true];
}

function delete_project(int $id, int $user_id): array {
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT id FROM projects WHERE id = ?');
    $stmt->execute([$id]);
    if (!$stmt->fetch()) return ['success' => false, 'error' => 'Proyecto no encontrado'];
    // Only admins can delete projects
    if (current_user()['role'] !== 'admin') {
        return ['success' => false, 'error' => 'Solo los administradores pueden eliminar proyectos'];
    }
    $pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$id]);
    return ['success' => true];
}

if (php_sapi_name() !== 'cli') {
    require_auth();
    header('Content-Type: application/json');
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    $b = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];

    if ($method === 'POST') { verify_csrf(); }
    $uid = current_user()['id'];
    match(true) {
        $method === 'GET'  && $action === 'list'   => print json_encode(['success'=>true,'data'=>list_projects()]),
        $method === 'POST' && $action === 'create' => print json_encode(!empty($b['name']) ? create_project($b['name'], $b['description']??null, $uid) : ['success'=>false,'error'=>'name required']),
        $method === 'POST' && $action === 'update' => print json_encode(!empty($b['id']) && !empty($b['name']) ? update_project((int)$b['id'], $b['name'], $b['description']??null, $uid) : ['success'=>false,'error'=>'id y name requeridos']),
        $method === 'POST' && $action === 'delete' => print json_encode(!empty($b['id']) ? delete_project((int)$b['id'], $uid) : ['success'=>false,'error'=>'id requerido']),
        default => null
    };
    exit;
}
