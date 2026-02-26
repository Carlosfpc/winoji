<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';

function log_activity(int $project_id, int $user_id, string $action, string $entity_type, int $entity_id, ?string $entity_title = null): void {
    try {
        get_db()->prepare(
            "INSERT INTO activity_log (project_id, user_id, action, entity_type, entity_id, entity_title) VALUES (?,?,?,?,?,?)"
        )->execute([$project_id, $user_id, $action, $entity_type, $entity_id, $entity_title]);
    } catch (Exception $e) {
        // Non-fatal: don't let logging break the main operation
    }
}

function get_recent_activity(int $project_id, int $limit = 20): array {
    $stmt = get_db()->prepare(
        "SELECT al.*, u.name AS user_name
         FROM activity_log al
         JOIN users u ON al.user_id = u.id
         WHERE al.project_id = ?
         ORDER BY al.created_at DESC
         LIMIT ?"
    );
    $stmt->execute([$project_id, $limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function notify_project(int $project_id, int $actor_id, string $type, string $entity_type, int $entity_id, ?string $entity_title = null): void {
    try {
        $pdo = get_db();
        $stmt = $pdo->prepare(
            "SELECT DISTINCT tm.user_id
             FROM team_members tm
             WHERE tm.team_id = (
                 SELECT tm2.team_id FROM team_members tm2
                 WHERE tm2.user_id = (SELECT created_by FROM projects WHERE id = ?)
                 LIMIT 1
             ) AND tm.user_id != ?"
        );
        $stmt->execute([$project_id, $actor_id]);
        $recipients = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (empty($recipients)) return;
        $ins = $pdo->prepare(
            "INSERT INTO notifications (user_id, project_id, actor_id, type, entity_type, entity_id, entity_title)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($recipients as $uid) {
            $ins->execute([(int)$uid, $project_id, $actor_id, $type, $entity_type, $entity_id, $entity_title]);
        }
        // Send email notifications
        $actor_stmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
        $actor_stmt->execute([$actor_id]);
        $actor_name = $actor_stmt->fetchColumn() ?: null;
        foreach ($recipients as $uid) {
            maybe_send_email_notification((int)$uid, $type, $entity_type, $entity_title, $actor_name);
        }
    } catch (Exception $e) {
        // Non-fatal
    }
}

function notify_user(int $user_id, int $project_id, int $actor_id, string $type, string $entity_type, int $entity_id, ?string $entity_title = null): void {
    if ($user_id === $actor_id) return;
    try {
        get_db()->prepare(
            "INSERT INTO notifications (user_id, project_id, actor_id, type, entity_type, entity_id, entity_title)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([$user_id, $project_id, $actor_id, $type, $entity_type, $entity_id, $entity_title]);
        $actor_stmt = get_db()->prepare('SELECT name FROM users WHERE id = ?');
        $actor_stmt->execute([$actor_id]);
        $actor_name = $actor_stmt->fetchColumn() ?: null;
        maybe_send_email_notification($user_id, $type, $entity_type, $entity_title, $actor_name);
    } catch (Exception $e) {
        // Non-fatal
    }
}
