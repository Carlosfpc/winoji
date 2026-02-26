<?php
require_once __DIR__ . '/../config/config.php';

if (defined('APP_ENV') && APP_ENV === 'production') {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', __DIR__ . '/../storage/logs/php-errors.log');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

// Session is started by bootstrap (auto_prepend_file).
// Only start here if bootstrap didn't run (e.g. CLI or direct PHP).
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

require_once __DIR__ . '/../app/includes/db.php';
require_once __DIR__ . '/../app/includes/auth.php';

$page = $_GET['page'] ?? 'login';
$allowed = ['login', 'dashboard', 'wiki', 'issues', 'kanban', 'sprint', 'team', 'project', 'profile', 'roadmap', 'notifications', 'user_profile'];

if (!in_array($page, $allowed)) {
    $page = '404';
}

$file = __DIR__ . '/../app/pages/' . $page . '.php';
if (!file_exists($file)) {
    http_response_code(404);
    echo "Page not found";
    exit;
}

require $file;
