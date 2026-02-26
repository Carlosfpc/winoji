<?php
/**
 * Auto-prepend bootstrap — loaded before every PHP file by Apache.
 * Initialises config, DB, auth, and session so API files work standalone.
 */

// Config (defines APP_URL, DB_*, etc.)
if (!defined('APP_URL')) {
    require_once __DIR__ . '/../config/config.php';
}

// Session (API files may need session for auth checks)
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? 80) == 443;
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    if (!isset($_SESSION['_init'])) {
        session_regenerate_id(true);
        $_SESSION['_init'] = true;
    }
}

// DB helper
if (!function_exists('get_db')) {
    require_once __DIR__ . '/includes/db.php';
}

// Auth helpers
if (!function_exists('require_auth')) {
    require_once __DIR__ . '/includes/auth.php';
}

// Verify the current user's team has access to a given project.
// Exits with 403 JSON if not. Call from API endpoints that accept project_id.
if (!function_exists('require_project_access')) {
    function require_project_access(int $project_id): void {
        if ($project_id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'project_id requerido']);
            exit;
        }
        $user = current_user();
        $team_id = $user['team_id'] ?? null;
        if (!$team_id) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Sin equipo asignado']);
            exit;
        }
        // A project is accessible if: the current user created it, OR it has any
        // issue/page associated and its id matches one the team uses (loose check:
        // team admin created it). For simplicity we check team_members table:
        // allow if the user is a member of a team that has at least one member
        // who created this project, OR if the user themselves created it.
        $pdo = get_db();
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM projects p WHERE p.id = ?
             AND (p.created_by = ? OR p.created_by IN (
                 SELECT user_id FROM team_members WHERE team_id = ?
             ))'
        );
        $stmt->execute([$project_id, $user['id'], $team_id]);
        if ((int)$stmt->fetchColumn() === 0) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Acceso denegado a este proyecto']);
            exit;
        }
    }
}
