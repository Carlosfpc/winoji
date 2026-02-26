<?php
require_once __DIR__ . '/../bootstrap.php';

if (php_sapi_name() !== 'cli') {
    require_auth();
    header('Content-Type: application/json');
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    $b      = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];
    if ($method === 'POST') { verify_csrf(); }

    match(true) {
        $method === 'GET' && $action === 'list' => (function() {
            $pid  = (int)($_GET['project_id'] ?? 0);
            if ($pid) require_project_access($pid);
            $stmt = get_db()->prepare(
                'SELECT it.id, it.name, it.title, it.description, it.priority,
                        it.type_id, itype.name as type_name, itype.color as type_color
                 FROM issue_templates it
                 LEFT JOIN issue_types itype ON it.type_id = itype.id
                 WHERE it.project_id = ?
                 ORDER BY it.name'
            );
            $stmt->execute([$pid]);
            print json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
        })(),

        $method === 'POST' && $action === 'create' => (function() use ($b) {
            if (empty($b['project_id']) || empty($b['name'])) {
                print json_encode(['success' => false, 'error' => 'project_id and name required']);
                return;
            }
            require_project_access((int)$b['project_id']);
            $valid_priorities = ['low', 'medium', 'high', 'critical'];
            $priority = in_array($b['priority'] ?? '', $valid_priorities) ? $b['priority'] : 'medium';
            $pdo  = get_db();
            $stmt = $pdo->prepare(
                'INSERT INTO issue_templates (project_id, name, title, description, type_id, priority)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                (int)$b['project_id'],
                trim($b['name']),
                trim($b['title'] ?? ''),
                $b['description'] ?? '',
                !empty($b['type_id']) ? (int)$b['type_id'] : null,
                $priority,
            ]);
            print json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
        })(),

        $method === 'POST' && $action === 'update' => (function() use ($b) {
            if (empty($b['id'])) {
                print json_encode(['success' => false, 'error' => 'id required']);
                return;
            }
            // Look up template's project to verify access
            $tplRow = get_db()->prepare('SELECT project_id FROM issue_templates WHERE id = ?');
            $tplRow->execute([(int)$b['id']]);
            $tplData = $tplRow->fetch();
            if (!$tplData) { print json_encode(['success' => false, 'error' => 'Template not found']); return; }
            require_project_access((int)$tplData['project_id']);
            $name = trim($b['name'] ?? '');
            if ($name === '') { print json_encode(['success' => false, 'error' => 'name required']); return; }
            $valid_priorities = ['low', 'medium', 'high', 'critical'];
            $priority = in_array($b['priority'] ?? '', $valid_priorities) ? $b['priority'] : 'medium';
            get_db()->prepare(
                'UPDATE issue_templates SET name=?, title=?, description=?, type_id=?, priority=? WHERE id=?'
            )->execute([
                $name,
                trim($b['title'] ?? ''),
                $b['description'] ?? '',
                !empty($b['type_id']) ? (int)$b['type_id'] : null,
                $priority,
                (int)$b['id'],
            ]);
            print json_encode(['success' => true]);
        })(),

        $method === 'POST' && $action === 'delete' => (function() use ($b) {
            $tplRow = get_db()->prepare('SELECT project_id FROM issue_templates WHERE id = ?');
            $tplRow->execute([(int)($b['id'] ?? 0)]);
            $tplData = $tplRow->fetch();
            if (!$tplData) { print json_encode(['success' => true]); return; } // already deleted = OK
            require_project_access((int)$tplData['project_id']);
            get_db()->prepare('DELETE FROM issue_templates WHERE id = ?')
                ->execute([(int)($b['id'] ?? 0)]);
            print json_encode(['success' => true]);
        })(),

        default => print json_encode(['success' => false, 'error' => 'Unknown action'])
    };
    exit;
}
