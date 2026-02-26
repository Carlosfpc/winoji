<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/logger.php';

// API files must never output HTML errors
ini_set('display_errors', '0');

function attempt_login(string $email, string $password): array {
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return ['success' => false, 'error' => 'Invalid email or password'];
    }

    // Get team membership
    $stmt2 = $pdo->prepare('SELECT team_id FROM team_members WHERE user_id = ? LIMIT 1');
    $stmt2->execute([$user['id']]);
    $team = $stmt2->fetch();

    return ['success' => true, 'user' => [
        'id'      => $user['id'],
        'name'    => $user['name'],
        'email'   => $user['email'],
        'role'    => $user['role'],
        'avatar'  => $user['avatar'] ?? null,
        'team_id' => $team ? (int)$team['team_id'] : null,
    ]];
}

// Handle POST login request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'login') {
    header('Content-Type: application/json');
    $body = json_decode(file_get_contents('php://input'), true);
    $result = attempt_login($body['email'] ?? '', $body['password'] ?? '');

    if ($result['success']) {
        session_regenerate_id(true);
        $_SESSION['user'] = $result['user'];
        app_log('info', 'User logged in', ['user_id' => $result['user']['id'], 'email' => $result['user']['email'], 'ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
    } else {
        app_log('warning', 'Failed login attempt', ['email' => $body['email'] ?? '', 'ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
    }

    echo json_encode($result);
    exit;
}

// Handle POST logout request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'logout') {
    header('Content-Type: application/json');
    session_destroy();
    echo json_encode(['success' => true]);
    exit;
}

$action = $_GET['action'] ?? '';

if ($action === 'change_password') {
    header('Content-Type: application/json');
    require_auth();
    verify_csrf();
    $pdo = get_db();
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $current  = $data['current_password'] ?? '';
    $new      = $data['new_password'] ?? '';
    if (strlen($new) < 6) { echo json_encode(['success' => false, 'error' => 'La nueva contraseña debe tener al menos 6 caracteres']); exit; }
    $user_id = current_user()['id'];
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$user_id]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($current, $row['password_hash'])) {
        echo json_encode(['success' => false, 'error' => 'Contraseña actual incorrecta']); exit;
    }
    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $user_id]);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'update_profile') {
    header('Content-Type: application/json');
    require_auth();
    verify_csrf();
    $pdo = get_db();
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $name = trim($data['name'] ?? '');
    if (!$name) { echo json_encode(['success' => false, 'error' => 'El nombre no puede estar vacío']); exit; }
    $user_id = current_user()['id'];
    $pdo->prepare('UPDATE users SET name = ? WHERE id = ?')->execute([$name, $user_id]);
    $_SESSION['user']['name'] = $name;
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'update_avatar') {
    header('Content-Type: application/json');
    require_auth();
    verify_csrf();
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $avatar = trim($data['avatar'] ?? '');
    // Accept empty string (to remove avatar) or a data URL
    if ($avatar !== '' && !str_starts_with($avatar, 'data:image/')) {
        echo json_encode(['success' => false, 'error' => 'Formato de imagen inválido']); exit;
    }
    // 256px JPEG at 88% quality ~25-60KB → base64 ~80KB. 200KB is generous.
    if (strlen($avatar) > 200000) {
        echo json_encode(['success' => false, 'error' => 'Imagen demasiado grande']); exit;
    }
    $uid = current_user()['id'];
    get_db()->prepare('UPDATE users SET avatar = ? WHERE id = ?')->execute([$avatar ?: null, $uid]);
    $_SESSION['user']['avatar'] = $avatar ?: null;
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'forgot_password') {
    header('Content-Type: application/json');
    $pdo = get_db();
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $email = trim($data['email'] ?? '');
    if (!$email) { echo json_encode(['success' => false, 'error' => 'Email required']); exit; }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    // Always return success to avoid leaking whether email exists
    if ($user) {
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $pdo->prepare('INSERT INTO password_resets (user_id, token, expires_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE token=VALUES(token), expires_at=VALUES(expires_at), used=0')
            ->execute([$user['id'], $token, $expires]);
        require_once __DIR__ . '/../includes/mailer.php';
        $resetUrl = APP_URL . '?page=login&reset_token=' . $token;
        $html = "
<h2 style=\"font-family:sans-serif;\">Password Reset</h2>
<p style=\"font-family:sans-serif;\">Click below to reset your password. This link expires in 1 hour.</p>
<p><a href=\"{$resetUrl}\" style=\"background:#34BF1F;color:#fff;padding:0.5rem 1rem;border-radius:4px;text-decoration:none;\">Reset Password</a></p>
<p style=\"font-family:sans-serif;color:#888;font-size:0.85rem;\">If you didn't request this, ignore this email.</p>
";
        send_email($email, 'Password Reset Request', $html);
    }
    echo json_encode(['success' => true, 'message' => 'If that email exists, a reset link was sent.']);
    exit;
}

if ($action === 'reset_password') {
    header('Content-Type: application/json');
    $pdo = get_db();
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $token    = trim($data['token'] ?? '');
    $password = $data['password'] ?? '';
    if (!$token || strlen($password) < 6) {
        echo json_encode(['success' => false, 'error' => 'Invalid request']); exit;
    }
    $stmt = $pdo->prepare('SELECT user_id FROM password_resets WHERE token = ? AND used = 0 AND expires_at > NOW()');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if (!$row) { echo json_encode(['success' => false, 'error' => 'Invalid or expired token']); exit; }

    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $row['user_id']]);
    $pdo->prepare('UPDATE password_resets SET used = 1 WHERE token = ?')->execute([$token]);
    echo json_encode(['success' => true]);
    exit;
}
