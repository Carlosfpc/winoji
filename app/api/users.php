<?php
require_once __DIR__ . '/../bootstrap.php';

require_auth();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$action = $_GET['action'] ?? '';

if ($action !== 'profile') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
    exit;
}

$me      = current_user();
$user_id = (int)($_GET['id'] ?? 0);

if ($user_id <= 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Perfil no encontrado']);
    exit;
}

// Own profile → tell frontend to redirect
if ($user_id === (int)$me['id']) {
    echo json_encode(['success' => false, 'redirect' => 'profile']);
    exit;
}

$pdo = get_db();

// Verify target user belongs to same team
$stmt = $pdo->prepare(
    'SELECT u.id, u.name, u.email, u.avatar, u.role
     FROM users u
     JOIN team_members tm ON u.id = tm.user_id
     WHERE u.id = ? AND tm.team_id = ?'
);
$stmt->execute([$user_id, (int)$me['team_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Perfil no encontrado']);
    exit;
}

$project_id = (int)($_GET['project_id'] ?? 0);
if ($project_id > 0) {
    // Soft check: if project not accessible, degrade gracefully (empty sections)
    // instead of failing the entire profile request with 403.
    $team_id = (int)($me['team_id'] ?? 0);
    $chk = $pdo->prepare(
        'SELECT COUNT(*) FROM projects p WHERE p.id = ?
         AND (p.created_by = ? OR p.created_by IN (
             SELECT user_id FROM team_members WHERE team_id = ?
         ))'
    );
    $chk->execute([$project_id, (int)$me['id'], $team_id]);
    if ((int)$chk->fetchColumn() === 0) {
        $project_id = 0; // proyecto inaccesible → secciones vacías
    }
}

// Last 20 issues assigned to this user in the active project
$issues = [];
if ($project_id > 0) {
    $stmt = $pdo->prepare(
        'SELECT i.id, i.title, i.status, i.priority, t.name AS type_name, t.color AS type_color
         FROM issues i
         LEFT JOIN issue_types t ON i.type_id = t.id
         WHERE i.assigned_to = ? AND i.project_id = ?
         ORDER BY i.created_at DESC
         LIMIT 20'
    );
    $stmt->execute([$user_id, $project_id]);
    $issues = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Last 20 activity log entries for this user in the active project
$activity = [];
if ($project_id > 0) {
    $stmt = $pdo->prepare(
        'SELECT action, entity_type, entity_id, entity_title, created_at
         FROM activity_log
         WHERE user_id = ? AND project_id = ?
         ORDER BY created_at DESC
         LIMIT 20'
    );
    $stmt->execute([$user_id, $project_id]);
    $activity = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

echo json_encode([
    'success' => true,
    'data'    => [
        'user'     => $user,
        'issues'   => $issues,
        'activity' => $activity,
    ]
]);
