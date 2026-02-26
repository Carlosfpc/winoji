<?php
/**
 * PHP built-in server router.
 * Usage: php -S localhost:8000 router.php (from project root)
 */
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Serve static files directly (CSS, JS, images)
if (preg_match('/\.(css|js|png|jpg|ico|svg|woff2?)$/', $uri)) {
    return false; // Let built-in server handle it
}

// Route /public/* to public/index.php (page requests)
if ($uri === '/public' || $uri === '/public/' || preg_match('#^/public/?\?#', $_SERVER['REQUEST_URI'])) {
    $_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/public/index.php';
    require __DIR__ . '/public/index.php';
    return true;
}

// Route /public directly (no trailing slash, no query string)
if ($uri === '/public') {
    require __DIR__ . '/public/index.php';
    return true;
}

// Route /app/api/*.php directly
if (preg_match('#^/app/api/[^/]+\.php$#', $uri)) {
    $file = __DIR__ . $uri;
    if (file_exists($file)) {
        require $file;
        return true;
    }
}

// Route /public/index.php
if ($uri === '/public/index.php') {
    require __DIR__ . '/public/index.php';
    return true;
}

// Serve existing files directly
$file = __DIR__ . $uri;
if (file_exists($file) && !is_dir($file)) {
    return false;
}

// Default: public/index.php handles everything else
require __DIR__ . '/public/index.php';
