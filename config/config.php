<?php
// Parse .env file
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '='))
            continue;
        [$key, $val] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($val);
    }
}

function env(string $key, string $default = ''): string
{
    return $_ENV[$key] ?? getenv($key) ?: $default;
}

define('APP_ENV', env('APP_ENV', 'production'));
define('APP_URL', env('APP_URL', 'http://localhost/teamapp/public'));
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_NAME', env('DB_NAME', 'teamapp'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));
define('ENCRYPT_KEY', env('ENCRYPT_KEY', ''));
define('SMTP_HOST', env('SMTP_HOST', 'localhost'));
define('SMTP_PORT', (int) env('SMTP_PORT', '25'));
define('SMTP_USER', env('SMTP_USER', ''));
define('SMTP_PASS', env('SMTP_PASS', ''));
define('SMTP_FROM', env('SMTP_FROM', 'noreply@localhost'));
define('SMTP_FROM_NAME', env('SMTP_FROM_NAME', 'WINOJI'));
