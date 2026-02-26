<?php
function is_authenticated(): bool {
    return isset($_SESSION['user']['id']);
}

function has_role(string $role): bool {
    if (!is_authenticated()) return false;
    $hierarchy = ['employee' => 0, 'manager' => 1, 'admin' => 2];
    $current  = $hierarchy[$_SESSION['user']['role']] ?? 0;
    $required = $hierarchy[$role] ?? 0;
    return $current >= $required;
}

function require_auth(): void {
    if (!is_authenticated()) {
        if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }
        header('Location: ' . APP_URL . '?page=login');
        exit;
    }
}

function require_role(string $role): void {
    require_auth();
    if (!has_role($role)) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Forbidden']);
        exit;
    }
}

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
}
