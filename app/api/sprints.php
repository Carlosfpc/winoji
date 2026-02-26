<?php
require_once __DIR__ . '/../bootstrap.php';

if (php_sapi_name() !== 'cli') {
    require_auth();
    header('Content-Type: application/json');
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    $user   = current_user();
    $uid    = $user['id'];

    if ($method === 'POST') { verify_csrf(); }
    $b = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];

    match(true) {

        // List all sprints for a project
        $method === 'GET' && $action === 'list' => (function() {
            $project_id = (int)($_GET['project_id'] ?? 0);
            if (!$project_id) { print json_encode(['success' => false, 'error' => 'project_id requerido']); return; }
            require_project_access($project_id);
            $stmt = get_db()->prepare(
                "SELECT s.*, u.name AS creator_name,
                    (SELECT COUNT(*) FROM issues WHERE sprint_id = s.id) AS issue_count
                 FROM sprints s JOIN users u ON s.created_by = u.id
                 WHERE s.project_id = ?
                 ORDER BY FIELD(s.status,'active','planning','completed'), s.start_date DESC"
            );
            $stmt->execute([$project_id]);
            print json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        })(),

        // Get sprint with its issues
        $method === 'GET' && $action === 'get' => (function() {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) { print json_encode(['success' => false, 'error' => 'id requerido']); return; }
            $pdo = get_db();
            $stmt = $pdo->prepare("SELECT * FROM sprints WHERE id = ?");
            $stmt->execute([$id]);
            $sprint = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$sprint) { print json_encode(['success' => false, 'error' => 'Sprint no encontrado']); return; }
            require_project_access((int)$sprint['project_id']);
            $issStmt = $pdo->prepare(
                "SELECT i.*, u.name AS assignee_name, t.name AS type_name, t.color AS type_color
                 FROM issues i
                 LEFT JOIN users u ON i.assigned_to = u.id
                 LEFT JOIN issue_types t ON i.type_id = t.id
                 WHERE i.sprint_id = ?
                 ORDER BY FIELD(i.priority,'critical','high','medium','low'), i.created_at DESC"
            );
            $issStmt->execute([$id]);
            $sprint['issues'] = $issStmt->fetchAll(PDO::FETCH_ASSOC);
            print json_encode(['success' => true, 'data' => $sprint]);
        })(),

        // Issues without a sprint (backlog)
        $method === 'GET' && $action === 'backlog' => (function() {
            $project_id = (int)($_GET['project_id'] ?? 0);
            if (!$project_id) { print json_encode(['success' => false, 'error' => 'project_id requerido']); return; }
            require_project_access($project_id);
            $stmt = get_db()->prepare(
                "SELECT i.*, u.name AS assignee_name, t.name AS type_name, t.color AS type_color
                 FROM issues i
                 LEFT JOIN users u ON i.assigned_to = u.id
                 LEFT JOIN issue_types t ON i.type_id = t.id
                 WHERE i.project_id = ? AND i.sprint_id IS NULL AND i.status != 'done'
                 ORDER BY FIELD(i.priority,'critical','high','medium','low'), i.created_at DESC"
            );
            $stmt->execute([$project_id]);
            print json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        })(),

        // Create sprint
        $method === 'POST' && $action === 'create' => (function() use ($b, $uid) {
            $project_id = (int)($b['project_id'] ?? 0);
            $name       = trim($b['name']       ?? '');
            $start_date = trim($b['start_date'] ?? '');
            $end_date   = trim($b['end_date']   ?? '');
            if (!$project_id || !$name || !$start_date || !$end_date) {
                print json_encode(['success' => false, 'error' => 'Faltan campos requeridos']); return;
            }
            if (!\DateTime::createFromFormat('Y-m-d', $start_date) || !\DateTime::createFromFormat('Y-m-d', $end_date)) {
                print json_encode(['success' => false, 'error' => 'Formato de fecha inválido (YYYY-MM-DD)']); return;
            }
            if ($start_date >= $end_date) {
                print json_encode(['success' => false, 'error' => 'La fecha de fin debe ser posterior a la de inicio']); return;
            }
            require_project_access($project_id);
            $pdo = get_db();
            $pdo->prepare("INSERT INTO sprints (project_id, name, start_date, end_date, created_by) VALUES (?,?,?,?,?)")
                ->execute([$project_id, $name, $start_date, $end_date, $uid]);
            print json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
        })(),

        // Update sprint (only if planning)
        $method === 'POST' && $action === 'update' => (function() use ($b) {
            $id         = (int)($b['id']         ?? 0);
            $name       = trim($b['name']        ?? '');
            $start_date = trim($b['start_date']  ?? '');
            $end_date   = trim($b['end_date']    ?? '');
            if (!$id || !$name || !$start_date || !$end_date) {
                print json_encode(['success' => false, 'error' => 'Faltan campos requeridos']); return;
            }
            if (!\DateTime::createFromFormat('Y-m-d', $start_date) || !\DateTime::createFromFormat('Y-m-d', $end_date)) {
                print json_encode(['success' => false, 'error' => 'Formato de fecha inválido (YYYY-MM-DD)']); return;
            }
            if ($start_date >= $end_date) {
                print json_encode(['success' => false, 'error' => 'La fecha de fin debe ser posterior a la de inicio']); return;
            }
            $pdo  = get_db();
            $stmt = $pdo->prepare("SELECT project_id, status FROM sprints WHERE id = ?");
            $stmt->execute([$id]);
            $sprint = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$sprint) { print json_encode(['success' => false, 'error' => 'Sprint no encontrado']); return; }
            require_project_access((int)$sprint['project_id']);
            if ($sprint['status'] !== 'planning') {
                print json_encode(['success' => false, 'error' => 'Solo se pueden editar sprints en planning']); return;
            }
            $pdo->prepare("UPDATE sprints SET name=?, start_date=?, end_date=? WHERE id=?")
                ->execute([$name, $start_date, $end_date, $id]);
            print json_encode(['success' => true]);
        })(),

        // Start sprint: planning → active (only one active per project)
        $method === 'POST' && $action === 'start' => (function() use ($b) {
            $id = (int)($b['id'] ?? 0);
            if (!$id) { print json_encode(['success' => false, 'error' => 'id requerido']); return; }
            $pdo  = get_db();
            $stmt = $pdo->prepare("SELECT project_id, status FROM sprints WHERE id = ?");
            $stmt->execute([$id]);
            $sprint = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$sprint) { print json_encode(['success' => false, 'error' => 'Sprint no encontrado']); return; }
            require_project_access((int)$sprint['project_id']);
            if ($sprint['status'] !== 'planning') {
                print json_encode(['success' => false, 'error' => 'Solo se pueden iniciar sprints en planning']); return;
            }
            $check = $pdo->prepare("SELECT id FROM sprints WHERE project_id = ? AND status = 'active'");
            $check->execute([$sprint['project_id']]);
            if ($check->fetch()) {
                print json_encode(['success' => false, 'error' => 'Ya hay un sprint activo en este proyecto']); return;
            }
            $pdo->prepare("UPDATE sprints SET status = 'active' WHERE id = ?")->execute([$id]);
            print json_encode(['success' => true]);
        })(),

        // Complete sprint: active → completed, non-done issues go to backlog
        $method === 'POST' && $action === 'complete' => (function() use ($b) {
            $id = (int)($b['id'] ?? 0);
            if (!$id) { print json_encode(['success' => false, 'error' => 'id requerido']); return; }
            $pdo  = get_db();
            $stmt = $pdo->prepare("SELECT project_id, status FROM sprints WHERE id = ?");
            $stmt->execute([$id]);
            $sprint = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$sprint) { print json_encode(['success' => false, 'error' => 'Sprint no encontrado']); return; }
            require_project_access((int)$sprint['project_id']);
            if ($sprint['status'] !== 'active') {
                print json_encode(['success' => false, 'error' => 'Solo se pueden completar sprints activos']); return;
            }
            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE issues SET sprint_id = NULL WHERE sprint_id = ? AND status != 'done'")
                    ->execute([$id]);
                $pdo->prepare("UPDATE sprints SET status = 'completed' WHERE id = ?")->execute([$id]);
                $pdo->commit();
            } catch (\Exception $e) {
                $pdo->rollBack();
                print json_encode(['success' => false, 'error' => 'Error al completar el sprint']); return;
            }
            print json_encode(['success' => true]);
        })(),

        // Delete sprint (manager+, only if no issues assigned)
        $method === 'POST' && $action === 'delete' => (function() use ($b) {
            $id = (int)($b['id'] ?? 0);
            if (!$id) { print json_encode(['success' => false, 'error' => 'id requerido']); return; }
            $pdo  = get_db();
            $stmt = $pdo->prepare("SELECT project_id FROM sprints WHERE id = ?");
            $stmt->execute([$id]);
            $sprint = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$sprint) { print json_encode(['success' => false, 'error' => 'Sprint no encontrado']); return; }
            require_project_access((int)$sprint['project_id']);
            require_role('manager');
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM issues WHERE sprint_id = ?");
            $cnt->execute([$id]);
            if ((int)$cnt->fetchColumn() > 0) {
                print json_encode(['success' => false, 'error' => 'No se puede eliminar un sprint con issues asignadas']); return;
            }
            $pdo->prepare("DELETE FROM sprints WHERE id = ?")->execute([$id]);
            print json_encode(['success' => true]);
        })(),

        // Add issue to sprint
        $method === 'POST' && $action === 'add_issue' => (function() use ($b) {
            $sprint_id = (int)($b['sprint_id'] ?? 0);
            $issue_id  = (int)($b['issue_id']  ?? 0);
            if (!$sprint_id || !$issue_id) {
                print json_encode(['success' => false, 'error' => 'sprint_id e issue_id requeridos']); return;
            }
            $pdo  = get_db();
            $stmt = $pdo->prepare("SELECT project_id FROM sprints WHERE id = ? AND status != 'completed'");
            $stmt->execute([$sprint_id]);
            $sprint = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$sprint) { print json_encode(['success' => false, 'error' => 'Sprint no encontrado o ya completado']); return; }
            require_project_access((int)$sprint['project_id']);
            $iStmt = $pdo->prepare("SELECT id FROM issues WHERE id = ? AND project_id = ?");
            $iStmt->execute([$issue_id, $sprint['project_id']]);
            if (!$iStmt->fetch()) { print json_encode(['success' => false, 'error' => 'Issue no encontrada en este proyecto']); return; }
            $pdo->prepare("UPDATE issues SET sprint_id = ? WHERE id = ?")->execute([$sprint_id, $issue_id]);
            print json_encode(['success' => true]);
        })(),

        // Remove issue from sprint (back to backlog)
        $method === 'POST' && $action === 'remove_issue' => (function() use ($b) {
            $issue_id = (int)($b['issue_id'] ?? 0);
            if (!$issue_id) { print json_encode(['success' => false, 'error' => 'issue_id requerido']); return; }
            $pdo  = get_db();
            $stmt = $pdo->prepare("SELECT project_id FROM issues WHERE id = ?");
            $stmt->execute([$issue_id]);
            $issue = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$issue) { print json_encode(['success' => false, 'error' => 'Issue no encontrada']); return; }
            require_project_access((int)$issue['project_id']);
            $pdo->prepare("UPDATE issues SET sprint_id = NULL WHERE id = ?")->execute([$issue_id]);
            print json_encode(['success' => true]);
        })(),

        default => print json_encode(['success' => false, 'error' => 'Acción no válida'])
    };
    exit;
}
