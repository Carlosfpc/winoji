<?php
require_once __DIR__ . '/../bootstrap.php';
function get_profile(int $user_id): ?array {
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT id, name, email, avatar, role FROM users WHERE id = ?');
    $stmt->execute([$user_id]);
    return $stmt->fetch() ?: null;
}

function update_profile(int $user_id, array $fields): array {
    $pdo = get_db();
    $allowed = ['name', 'email', 'avatar'];
    $sets = []; $params = [];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $fields) && $fields[$f] !== null) {
            $sets[] = "$f = ?";
            $params[] = $fields[$f];
        }
    }

    // Handle password change
    if (!empty($fields['new_password'])) {
        if (empty($fields['current_password'])) {
            return ['success' => false, 'error' => 'Current password required'];
        }
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$user_id]);
        $row = $stmt->fetch();
        if (!password_verify($fields['current_password'], $row['password_hash'])) {
            return ['success' => false, 'error' => 'Current password is incorrect'];
        }
        $sets[] = 'password_hash = ?';
        $params[] = password_hash($fields['new_password'], PASSWORD_DEFAULT);
    }

    // Handle GitHub token
    if (array_key_exists('github_token', $fields)) {
        if (!empty($fields['github_token'])) {
            require_once __DIR__ . '/github.php';
            $sets[] = 'github_token = ?';
            $params[] = encrypt_token($fields['github_token']);
        } else {
            $sets[] = 'github_token = ?';
            $params[] = null;
        }
    }

    if (empty($sets)) return ['success' => false, 'error' => 'Nothing to update'];
    $params[] = $user_id;
    $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);

    // Update session name if changed
    if (!empty($fields['name'])) {
        $_SESSION['user']['name'] = $fields['name'];
    }

    return ['success' => true];
}

if (php_sapi_name() !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    require_auth();
    header('Content-Type: application/json');
    $method = $_SERVER['REQUEST_METHOD'];
    $user_id = current_user()['id'];

    if ($method === 'GET') {
        echo json_encode(['success' => true, 'data' => get_profile($user_id)]);
    } elseif ($method === 'POST') {
        verify_csrf();
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        echo json_encode(update_profile($user_id, $b));
    }
    exit;
}
