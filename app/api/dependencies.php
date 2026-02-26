<?php
require_once __DIR__ . '/../bootstrap.php';

function get_issue_project_id(int $issue_id): ?int {
    $stmt = get_db()->prepare('SELECT project_id FROM issues WHERE id = ?');
    $stmt->execute([$issue_id]);
    $row = $stmt->fetch();
    return $row ? (int)$row['project_id'] : null;
}

if (php_sapi_name() !== 'cli') {
    require_auth();
    header('Content-Type: application/json');
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    $b      = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];
    if ($method === 'POST') { verify_csrf(); }

    match(true) {
        // GET ?action=list&issue_id=N
        $method === 'GET' && $action === 'list' => (function() {
            $issue_id = (int)($_GET['issue_id'] ?? 0);
            $pid = get_issue_project_id($issue_id);
            if ($pid) require_project_access($pid);
            $pdo = get_db();
            // Issues this issue blocks/relates_to
            $s1 = $pdo->prepare(
                "SELECT id.id, id.type, i.id as issue_id, i.title, i.status, i.priority
                 FROM issue_dependencies id JOIN issues i ON id.to_issue_id = i.id
                 WHERE id.from_issue_id = ? ORDER BY id.type, i.id"
            );
            $s1->execute([$issue_id]);
            $outgoing = $s1->fetchAll();
            // Issues that block/relate_to this issue
            $s2 = $pdo->prepare(
                "SELECT id.id, id.type, i.id as issue_id, i.title, i.status, i.priority
                 FROM issue_dependencies id JOIN issues i ON id.from_issue_id = i.id
                 WHERE id.to_issue_id = ? ORDER BY id.type, i.id"
            );
            $s2->execute([$issue_id]);
            $incoming = $s2->fetchAll();
            print json_encode(['success' => true, 'outgoing' => $outgoing, 'incoming' => $incoming]);
        })(),

        // POST ?action=add — body: { from_issue_id, to_issue_id, type }
        $method === 'POST' && $action === 'add' => (function() use ($b) {
            if (empty($b['from_issue_id']) || empty($b['to_issue_id'])) {
                print json_encode(['success' => false, 'error' => 'from_issue_id and to_issue_id required']);
                return;
            }
            $pid = get_issue_project_id((int)($b['from_issue_id'] ?? 0));
            if ($pid) require_project_access($pid);
            if ((int)$b['from_issue_id'] === (int)$b['to_issue_id']) {
                print json_encode(['success' => false, 'error' => 'Una issue no puede depender de sí misma']);
                return;
            }
            $type = in_array($b['type'] ?? '', ['blocks','relates_to']) ? $b['type'] : 'blocks';
            try {
                get_db()->prepare(
                    "INSERT IGNORE INTO issue_dependencies (from_issue_id, to_issue_id, type) VALUES (?,?,?)"
                )->execute([(int)$b['from_issue_id'], (int)$b['to_issue_id'], $type]);
                print json_encode(['success' => true]);
            } catch (Exception $e) {
                print json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
        })(),

        // POST ?action=remove — body: { id }
        $method === 'POST' && $action === 'remove' => (function() use ($b) {
            $dep = get_db()->prepare('SELECT from_issue_id FROM issue_dependencies WHERE id = ?');
            $dep->execute([(int)($b['id'] ?? 0)]);
            $row = $dep->fetch();
            if ($row) { $pid = get_issue_project_id((int)$row['from_issue_id']); if ($pid) require_project_access($pid); }
            get_db()->prepare("DELETE FROM issue_dependencies WHERE id = ?")->execute([(int)($b['id'] ?? 0)]);
            print json_encode(['success' => true]);
        })(),

        default => print json_encode(['success' => false, 'error' => 'Unknown action'])
    };
    exit;
}
