<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/activity.php';
function list_issues(int $project_id, array $filters = [], int $page = 1, int $per_page = 25): array {
    $pdo = get_db();
    $sql = 'SELECT i.*, i.story_points, u.name as assignee_name,
        it.name as type_name, it.color as type_color,
        (SELECT JSON_ARRAYAGG(JSON_OBJECT(\'id\', l.id, \'name\', l.name, \'color\', l.color))
         FROM issue_labels il JOIN labels l ON il.label_id = l.id WHERE il.issue_id = i.id) as labels_json,
        (SELECT DATE(MAX(sl.changed_at)) FROM issue_status_log sl WHERE sl.issue_id = i.id AND sl.new_status = \'done\') as completed_at
        FROM issues i
        LEFT JOIN users u ON i.assigned_to = u.id
        LEFT JOIN issue_types it ON i.type_id = it.id
        WHERE i.project_id = ?';
    $params = [$project_id];
    if (!empty($filters['status']))   { $sql .= ' AND i.status = ?';    $params[] = $filters['status']; }
    if (!empty($filters['priority'])) { $sql .= ' AND i.priority = ?';  $params[] = $filters['priority']; }
    if (!empty($filters['type_id']))  { $sql .= ' AND i.type_id = ?';   $params[] = (int)$filters['type_id']; }
    if (isset($filters['assigned_to']) && $filters['assigned_to'] === 'none') {
        $sql .= ' AND i.assigned_to IS NULL';
    } elseif (!empty($filters['assigned_to'])) {
        $sql .= ' AND i.assigned_to = ?'; $params[] = (int)$filters['assigned_to'];
    }

    // Count total
    $countSql = 'SELECT COUNT(*) FROM issues i LEFT JOIN users u ON i.assigned_to = u.id WHERE i.project_id = ?';
    $countParams = [$project_id];
    if (!empty($filters['status']))   { $countSql .= ' AND i.status = ?';   $countParams[] = $filters['status']; }
    if (!empty($filters['priority'])) { $countSql .= ' AND i.priority = ?'; $countParams[] = $filters['priority']; }
    if (!empty($filters['type_id']))  { $countSql .= ' AND i.type_id = ?';  $countParams[] = (int)$filters['type_id']; }
    if (isset($filters['assigned_to']) && $filters['assigned_to'] === 'none') {
        $countSql .= ' AND i.assigned_to IS NULL';
    } elseif (!empty($filters['assigned_to'])) {
        $countSql .= ' AND i.assigned_to = ?'; $countParams[] = (int)$filters['assigned_to'];
    }
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($countParams);
    $total = (int)$countStmt->fetchColumn();

    $offset = ($page - 1) * $per_page;
    $sql .= ' ORDER BY i.created_at DESC LIMIT ? OFFSET ?';
    $params[] = $per_page; $params[] = $offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return ['items' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'per_page' => $per_page];
}

function get_issue(int $id): ?array {
    $pdo  = get_db();
    $stmt = $pdo->prepare('SELECT i.*, i.story_points, u.name as assignee_name, it.name as type_name, it.color as type_color
        FROM issues i
        LEFT JOIN users u ON i.assigned_to = u.id
        LEFT JOIN issue_types it ON i.type_id = it.id
        WHERE i.id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function create_issue(int $project_id, string $title, ?string $desc, string $status, string $priority, ?int $assigned_to, ?string $due_date, int $user_id, ?int $type_id = null, ?int $story_points = null): array {
    $pdo  = get_db();
    $stmt = $pdo->prepare('INSERT INTO issues (project_id, title, description, status, priority, type_id, assigned_to, due_date, story_points, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$project_id, $title, $desc, $status, $priority, $type_id, $assigned_to, $due_date, $story_points, $user_id]);
    return ['success' => true, 'id' => (int)$pdo->lastInsertId()];
}

function update_issue(int $id, array $fields, int $user_id = 0): array {
    $pdo = get_db();
    $allowed = ['title','description','status','priority','type_id','assigned_to','due_date','story_points'];
    $sets = []; $params = [];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $fields)) { $sets[] = "$f = ?"; $params[] = $fields[$f]; }
    }
    if (empty($sets)) return ['success' => false, 'error' => 'No fields'];
    // Validate story_points if present
    if (array_key_exists('story_points', $fields)) {
        $sp = $fields['story_points'];
        if (!is_null($sp) && !(is_numeric($sp) && (int)$sp >= 1 && (int)$sp <= 100)) {
            return ['success' => false, 'error' => 'story_points must be null or an integer 1-100'];
        }
    }
    // Log status change if status is being updated
    if (array_key_exists('status', $fields) && $user_id > 0) {
        $cur = $pdo->prepare('SELECT status FROM issues WHERE id = ?');
        $cur->execute([$id]);
        $row = $cur->fetch();
        if ($row && $row['status'] !== $fields['status']) {
            $pdo->prepare('INSERT INTO issue_status_log (issue_id, old_status, new_status, changed_by) VALUES (?, ?, ?, ?)')
                ->execute([$id, $row['status'], $fields['status'], $user_id]);
        }
    }
    $params[] = $id;
    $pdo->prepare('UPDATE issues SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    return ['success' => true];
}

function update_issue_status(int $id, string $status): array {
    return update_issue($id, ['status' => $status]);
}

function get_status_log(int $issue_id): array {
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT sl.*, u.name as changed_by_name FROM issue_status_log sl LEFT JOIN users u ON sl.changed_by = u.id WHERE sl.issue_id = ? ORDER BY sl.changed_at ASC');
    $stmt->execute([$issue_id]);
    return ['success' => true, 'data' => $stmt->fetchAll()];
}

function delete_issue(int $id, int $user_id, string $user_role): array {
    $pdo = get_db();
    $issue = $pdo->prepare('SELECT created_by, assigned_to FROM issues WHERE id = ?');
    $issue->execute([$id]);
    $row = $issue->fetch();
    if (!$row) return ['success' => false, 'error' => 'Issue not found'];
    if ($user_role !== 'admin' && $row['created_by'] !== $user_id && $row['assigned_to'] !== $user_id) {
        return ['success' => false, 'error' => 'No tienes permiso para eliminar esta issue'];
    }
    $pdo->prepare('DELETE FROM issues WHERE id = ?')->execute([$id]);
    return ['success' => true];
}

// HTTP routing
if (php_sapi_name() !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    require_auth();

    // Handle CSV export separately (outputs CSV, not JSON)
    if (($_GET['action'] ?? '') === 'export') {
        $project_id = (int)($_GET['project_id'] ?? 0);
        if (!$project_id) { http_response_code(400); exit('project_id requerido'); }
        require_project_access($project_id);

        $where = ["i.project_id = :pid"];
        $params = ['pid' => $project_id];

        if (!empty($_GET['status'])) {
            $where[] = "i.status = :status";
            $params['status'] = $_GET['status'];
        }
        if (!empty($_GET['priority'])) {
            $where[] = "i.priority = :priority";
            $params['priority'] = $_GET['priority'];
        }
        if (!empty($_GET['assigned_to'])) {
            $where[] = "i.assigned_to = :assigned_to";
            $params['assigned_to'] = (int)$_GET['assigned_to'];
        }
        if (!empty($_GET['type_id'])) {
            $where[] = "i.type_id = :type_id";
            $params['type_id'] = (int)$_GET['type_id'];
        }

        $sql = "SELECT i.id, i.title, i.status, i.priority, i.story_points, i.due_date, i.created_at,
                       COALESCE(it.name, '') AS type_name,
                       COALESCE(u.name, '') AS assignee_name
                FROM issues i
                LEFT JOIN issue_types it ON i.type_id = it.id
                LEFT JOIN users u ON i.assigned_to = u.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY i.created_at DESC";

        $stmt = get_db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="issues-' . date('Y-m-d') . '.csv"');
        header('Cache-Control: no-cache, no-store, must-revalidate');

        $out = fopen('php://output', 'w');
        // UTF-8 BOM for Excel compatibility
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['ID', 'Título', 'Estado', 'Prioridad', 'Tipo', 'Asignado a', 'Puntos', 'Fecha límite', 'Creado']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['id'],
                $r['title'],
                $r['status'],
                $r['priority'],
                $r['type_name'],
                $r['assignee_name'],
                $r['story_points'] ?? '',
                $r['due_date'] ?? '',
                $r['created_at'],
            ]);
        }
        fclose($out);
        exit;
    }

    header('Content-Type: application/json');
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    $b = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];

    if ($method === 'POST') { verify_csrf(); }

    // Validate project access for project-scoped actions
    $req_project_id = (int)(($method === 'POST' ? $b : $_GET)['project_id'] ?? 0);
    if (in_array($action, ['list','create'])) require_project_access($req_project_id);

    match(true) {
        $method === 'GET' && $action === 'list'    => print json_encode(array_merge(['success'=>true], list_issues((int)($_GET['project_id']??0), $_GET, (int)($_GET['page']??1), min((int)($_GET['per_page']??25), 500)))),
        $method === 'GET' && $action === 'get'        => print json_encode(($i=get_issue((int)($_GET['id']??0))) ? ['success'=>true,'data'=>$i] : ['success'=>false,'error'=>'Not found']),
        $method === 'GET' && $action === 'status_log' => print json_encode(get_status_log((int)($_GET['id']??0))),
        $method === 'POST' && $action === 'create' => (function() use ($b) {
            if (empty($b['title'])) { print json_encode(['success'=>false,'error'=>'title required']); return true; }
            $pid = (int)($b['project_id']??0);
            $result = create_issue($pid, $b['title'], $b['description']??null, $b['status']??'todo', $b['priority']??'medium', $b['assigned_to']??null, $b['due_date']??null, current_user()['id'], isset($b['type_id']) ? (int)$b['type_id'] : null, isset($b['story_points']) && $b['story_points'] !== null && $b['story_points'] !== '' ? (int)$b['story_points'] : null);
            if (!empty($result['success'])) {
                $u = current_user();
                log_activity($pid, $u['id'], 'issue_created', 'issue', $result['id'], $b['title']);
                notify_project($pid, $u['id'], 'issue_created', 'issue', $result['id'], $b['title']);
                if (!empty($b['assigned_to']) && (int)$b['assigned_to'] !== $u['id']) {
                    notify_user((int)$b['assigned_to'], $pid, $u['id'], 'issue_assigned', 'issue', $result['id'], $b['title']);
                }
            }
            print json_encode($result);
            return true;
        })(),
        $method === 'POST' && $action === 'update' => (function() use ($b) {
            if (empty($b['id'])) { print json_encode(['success'=>false,'error'=>'id required']); return true; }
            $u = current_user();
            $result = update_issue((int)$b['id'], $b, $u['id']);
            if (!empty($result['success'])) {
                $issue = get_issue((int)$b['id']);
                if ($issue) {
                    log_activity($issue['project_id'], $u['id'], 'issue_updated', 'issue', $issue['id'], $issue['title']);
                    notify_project($issue['project_id'], $u['id'], 'issue_updated', 'issue', $issue['id'], $issue['title']);
                    if (!empty($b['assigned_to']) && (int)$b['assigned_to'] !== $u['id']) {
                        notify_user((int)$b['assigned_to'], $issue['project_id'], $u['id'], 'issue_assigned', 'issue', $issue['id'], $issue['title']);
                    }
                }
            }
            print json_encode($result);
            return true;
        })(),
        $method === 'POST' && $action === 'delete' => (function() use ($b) {
            $u = current_user();
            $issue = get_issue((int)($b['id']??0));
            $result = delete_issue((int)($b['id']??0), $u['id'], $u['role']);
            if (!empty($result['success']) && $issue) {
                log_activity($issue['project_id'], $u['id'], 'issue_deleted', 'issue', $issue['id'], $issue['title']);
            }
            print json_encode($result);
            return true;
        })(),
        default => null
    };
    exit;
}
