<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/activity.php';

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
        $method === 'GET' && $action === 'list' => (function() use ($uid) {
            $page        = max(1, (int)($_GET['page']     ?? 1));
            $per_page    = max(1, min(100, (int)($_GET['per_page'] ?? 20)));
            $offset      = ($page - 1) * $per_page;
            $extra       = !empty($_GET['unread']) ? ' AND n.read_at IS NULL' : '';

            $cnt = get_db()->prepare("SELECT COUNT(*) FROM notifications n WHERE n.user_id = ?{$extra}");
            $cnt->execute([$uid]);
            $total = (int)$cnt->fetchColumn();

            $rows = get_db()->prepare(
                "SELECT n.*, u.name AS actor_name, u.avatar AS actor_avatar
                 FROM notifications n
                 JOIN users u ON n.actor_id = u.id
                 WHERE n.user_id = ?{$extra}
                 ORDER BY n.created_at DESC
                 LIMIT ? OFFSET ?"
            );
            $rows->execute([$uid, $per_page, $offset]);
            print json_encode([
                'success'  => true,
                'data'     => $rows->fetchAll(PDO::FETCH_ASSOC),
                'total'    => $total,
                'page'     => $page,
                'per_page' => $per_page,
            ]);
        })(),

        $method === 'GET' && $action === 'unread_count' => (function() use ($uid) {
            $cnt = get_db()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL");
            $cnt->execute([$uid]);
            print json_encode(['success' => true, 'count' => (int)$cnt->fetchColumn()]);
        })(),

        $method === 'POST' && $action === 'mark_read' => (function() use ($b, $uid) {
            $id = (int)($b['id'] ?? 0);
            if (!$id) { print json_encode(['success' => false, 'error' => 'id requerido']); return true; }
            get_db()->prepare("UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ?")
                    ->execute([$id, $uid]);
            print json_encode(['success' => true]);
        })(),

        $method === 'POST' && $action === 'mark_all_read' => (function() use ($uid) {
            get_db()->prepare("UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL")
                    ->execute([$uid]);
            print json_encode(['success' => true]);
        })(),

        default => print json_encode(['success' => false, 'error' => 'Acción no válida'])
    };
    exit;
}
