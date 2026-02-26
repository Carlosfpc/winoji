<?php
require_once __DIR__ . '/../bootstrap.php';

function list_issue_types(int $project_id): array {
    $stmt = get_db()->prepare('SELECT * FROM issue_types WHERE project_id = ? ORDER BY created_at');
    $stmt->execute([$project_id]);
    return $stmt->fetchAll();
}

function create_issue_type(int $project_id, string $name, string $color, ?string $description): array {
    $pdo  = get_db();
    $stmt = $pdo->prepare('INSERT INTO issue_types (project_id, name, color, description) VALUES (?,?,?,?)');
    $stmt->execute([$project_id, $name, $color, $description]);
    return ['success' => true, 'id' => (int)$pdo->lastInsertId()];
}

function update_issue_type(int $id, string $name, string $color, ?string $description): array {
    get_db()->prepare('UPDATE issue_types SET name = ?, color = ?, description = ? WHERE id = ?')
            ->execute([$name, $color, $description, $id]);
    return ['success' => true];
}

function delete_issue_type(int $id): array {
    $pdo  = get_db();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM issues WHERE type_id = ?');
    $stmt->execute([$id]);
    if ((int)$stmt->fetchColumn() > 0) {
        return ['success' => false, 'error' => 'Hay issues con este tipo. Cámbia su tipo antes de eliminar.'];
    }
    $pdo->prepare('DELETE FROM issue_types WHERE id = ?')->execute([$id]);
    return ['success' => true];
}

/** Seeds default types when a new project is created */
function seed_default_issue_types(int $project_id): void {
    $pdo      = get_db();
    $defaults = [
        ['Feature', '#3b82f6', 'Nueva funcionalidad o mejora'],
        ['Bug',     '#ef4444', 'Error o comportamiento incorrecto'],
        ['Task',    '#6b7280', 'Tarea de desarrollo o mantenimiento'],
        ['Story',   '#8b5cf6', 'Historia de usuario'],
    ];
    $stmt = $pdo->prepare('INSERT INTO issue_types (project_id, name, color, description) VALUES (?,?,?,?)');
    foreach ($defaults as [$name, $color, $desc]) {
        $stmt->execute([$project_id, $name, $color, $desc]);
    }
}

// HTTP routing
if (php_sapi_name() !== 'cli') {
    require_auth();
    header('Content-Type: application/json');
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    if ($method === 'POST') { verify_csrf(); }
    $b = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];

    if ($method === 'GET' && $action === 'list') {
        echo json_encode(['success' => true, 'data' => list_issue_types((int)($_GET['project_id'] ?? 0))]);
    } elseif ($method === 'POST' && $action === 'create') {
        if (empty($b['name']) || empty($b['project_id'])) {
            echo json_encode(['success' => false, 'error' => 'project_id y name son obligatorios']);
        } else {
            echo json_encode(create_issue_type((int)$b['project_id'], $b['name'], $b['color'] ?? '#6b7280', $b['description'] ?? null));
        }
    } elseif ($method === 'POST' && $action === 'update') {
        if (empty($b['id']) || empty($b['name'])) {
            echo json_encode(['success' => false, 'error' => 'id y name son obligatorios']);
        } else {
            echo json_encode(update_issue_type((int)$b['id'], $b['name'], $b['color'] ?? '#6b7280', $b['description'] ?? null));
        }
    } elseif ($method === 'POST' && $action === 'delete') {
        echo json_encode(delete_issue_type((int)($b['id'] ?? 0)));
    }
    exit;
}
