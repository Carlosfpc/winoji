<?php
require_once __DIR__ . '/../bootstrap.php';
function list_team_members(int $team_id): array {
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT u.id, u.name, u.email, u.avatar, u.role, tm.role as team_role FROM users u JOIN team_members tm ON u.id = tm.user_id WHERE tm.team_id = ?');
    $stmt->execute([$team_id]);
    return $stmt->fetchAll();
}

function invite_user(string $name, string $email, string $role, int $team_id): array {
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $existing = $stmt->fetchColumn();
    if ($existing) return ['success' => false, 'error' => 'User already exists'];

    $temp_password = bin2hex(random_bytes(8));
    $hash = password_hash($temp_password, PASSWORD_DEFAULT);
    $pdo->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?,?,?,?)')->execute([$name, $email, $hash, $role]);
    $user_id = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO team_members (user_id, team_id, role) VALUES (?,?,?)')->execute([$user_id, $team_id, $role]);

    // Send invite email
    require_once __DIR__ . '/../includes/mailer.php';
    $loginUrl = APP_URL . '?page=login';
    $html = "
<h2 style=\"font-family:sans-serif;\">You've been invited to Team App</h2>
<p style=\"font-family:sans-serif;\">Your account has been created. Use these credentials to log in:</p>
<table style=\"font-family:sans-serif;border-collapse:collapse;\">
  <tr><td style=\"padding:4px 12px 4px 0;\"><strong>Email:</strong></td><td>{$email}</td></tr>
  <tr><td style=\"padding:4px 12px 4px 0;\"><strong>Temporary password:</strong></td><td>{$temp_password}</td></tr>
</table>
<p style=\"margin-top:1rem;\"><a href=\"{$loginUrl}\" style=\"background:#34BF1F;color:#fff;padding:0.5rem 1rem;border-radius:4px;text-decoration:none;\">Log in now</a></p>
<p style=\"font-family:sans-serif;color:#888;font-size:0.85rem;\">Please change your password after logging in.</p>
";
    send_email($email, 'You have been invited to Team App', $html);

    return ['success' => true, 'temp_password' => $temp_password, 'user_id' => $user_id];
}

function update_user_role(int $user_id, string $role): array {
    $pdo = get_db();
    $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $user_id]);
    $pdo->prepare('UPDATE team_members SET role = ? WHERE user_id = ?')->execute([$role, $user_id]);
    return ['success' => true];
}

function remove_team_member(int $user_id, int $team_id): array {
    get_db()->prepare('DELETE FROM team_members WHERE user_id = ? AND team_id = ?')->execute([$user_id, $team_id]);
    return ['success' => true];
}

if (php_sapi_name() !== 'cli') {
    require_auth();
    header('Content-Type: application/json');
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    $b = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];

    // Require team membership
    $team_id = current_user()['team_id'] ?? null;
    if ($team_id === null) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No team membership']);
        exit;
    }

    if ($method === 'POST') { verify_csrf(); }
    match(true) {
        $method === 'GET'  && $action === 'members'     => print json_encode(['success'=>true,'data'=>list_team_members($team_id)]),
        $method === 'POST' && $action === 'invite'      => (function() use ($b, $team_id) { require_role('admin'); $valid_roles = ['admin','manager','employee']; $role = in_array($b['role'] ?? '', $valid_roles) ? $b['role'] : 'employee'; print json_encode(!empty($b['name']) && !empty($b['email']) ? invite_user($b['name'], $b['email'], $role, $team_id) : ['success'=>false,'error'=>'name and email required']); })(),
        $method === 'POST' && $action === 'remove'      => (function() use ($b, $team_id) { require_role('admin'); print json_encode(remove_team_member((int)($b['user_id']??0), $team_id)); })(),
        $method === 'POST' && $action === 'update_role' => (function() use ($b) { require_role('admin'); $valid = ['admin','manager','employee']; $role = in_array($b['role']??'', $valid) ? $b['role'] : null; print json_encode($role && !empty($b['user_id']) ? update_user_role((int)$b['user_id'], $role) : ['success'=>false,'error'=>'user_id y role requeridos']); })(),
        default => null
    };
    exit;
}
