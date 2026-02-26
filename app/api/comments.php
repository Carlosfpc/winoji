<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/activity.php';
function list_comments(int $issue_id): array {
    $pdo = get_db();
    $stmt = $pdo->prepare(
        'SELECT c.*, u.name as author_name, u.avatar as author_avatar
         FROM comments c JOIN users u ON c.user_id = u.id
         WHERE c.issue_id = ? ORDER BY c.created_at ASC'
    );
    $stmt->execute([$issue_id]);
    return $stmt->fetchAll();
}

function create_comment(int $issue_id, string $body, int $user_id): array {
    $pdo = get_db();
    $stmt = $pdo->prepare('INSERT INTO comments (issue_id, content, user_id) VALUES (?,?,?)');
    $stmt->execute([$issue_id, $body, $user_id]);
    return ['success' => true, 'id' => (int)$pdo->lastInsertId()];
}

function delete_comment(int $id, int $user_id, string $role): array {
    $pdo = get_db();
    // Only author or admin can delete
    if ($role !== 'admin') {
        $stmt = $pdo->prepare('SELECT user_id FROM comments WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row || (int)$row['user_id'] !== $user_id) {
            return ['success' => false, 'error' => 'Not authorized'];
        }
    }
    $pdo->prepare('DELETE FROM comments WHERE id = ?')->execute([$id]);
    return ['success' => true];
}

function update_comment(int $id, string $content, int $user_id, string $role): array {
    if ($id <= 0 || $content === '') {
        http_response_code(400);
        return ['success' => false, 'error' => 'id y content son requeridos'];
    }
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT user_id, issue_id FROM comments WHERE id = ?');
    $stmt->execute([$id]);
    $comment = $stmt->fetch();
    if (!$comment) {
        http_response_code(404);
        return ['success' => false, 'error' => 'Comentario no encontrado'];
    }
    if ((int)$comment['user_id'] !== $user_id && $role !== 'admin') {
        http_response_code(403);
        return ['success' => false, 'error' => 'Sin permiso'];
    }
    $pdo->prepare('UPDATE comments SET content = ? WHERE id = ?')->execute([$content, $id]);
    return ['success' => true, 'id' => $id];
}

if (php_sapi_name() !== 'cli') {
    require_auth();
    header('Content-Type: application/json');
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    $b = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];
    $user = current_user();

    if ($method === 'POST') { verify_csrf(); }
    match(true) {
        $method === 'GET'  && $action === 'list'   => print json_encode(['success'=>true,'data'=>list_comments((int)($_GET['issue_id']??0))]),
        $method === 'POST' && $action === 'create' => (function() use ($b, $user) {
            if (empty($b['issue_id']) || empty($b['body'])) {
                print json_encode(['success'=>false,'error'=>'issue_id and body required']); return true;
            }
            $result = create_comment((int)$b['issue_id'], $b['body'], $user['id']);
            if (!empty($result['success'])) {
                // Fetch issue to get project_id and title for activity
                $issue = get_db()->prepare('SELECT project_id, title FROM issues WHERE id = ?');
                $issue->execute([(int)$b['issue_id']]);
                $row = $issue->fetch();
                if ($row) {
                    log_activity($row['project_id'], $user['id'], 'comment_added', 'comment', $result['id'], $row['title']);
                    notify_project($row['project_id'], $user['id'], 'comment_added', 'comment', $result['id'], $row['title']);
                    // Detect @mentions and send targeted notifications
                    preg_match_all('/@([\w]+(?:\s[\w]+)*)/', $b['body'], $matches);
                    if (!empty($matches[1])) {
                        foreach ($matches[1] as $mentionedName) {
                            $mu = get_db()->prepare("SELECT id FROM users WHERE name = ? LIMIT 1");
                            $mu->execute([trim($mentionedName)]);
                            $muid = $mu->fetchColumn();
                            if ($muid && (int)$muid !== $user['id']) {
                                notify_user((int)$muid, $row['project_id'], $user['id'], 'mention', 'comment', $result['id'], $row['title']);
                            }
                        }
                    }
                }
            }
            print json_encode($result);
            return true;
        })(),
        $method === 'POST' && $action === 'delete' => print json_encode(delete_comment((int)($b['id']??0), $user['id'], $user['role'])),
        $method === 'POST' && $action === 'update' => print json_encode(update_comment((int)($b['id']??0), trim($b['content']??''), $user['id'], $user['role'])),
        default => null
    };
    exit;
}
