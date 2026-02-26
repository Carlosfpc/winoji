<?php
/**
 * Structured application logger.
 * Writes JSON lines to storage/logs/app.log.
 *
 * Usage: app_log('info', 'message', ['key' => 'value']);
 */
function app_log(string $level, string $message, array $context = []): void {
    $logFile = __DIR__ . '/../../storage/logs/app.log';
    $dir = dirname($logFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $entry = json_encode([
        'time'    => date('c'),
        'level'   => strtoupper($level),
        'message' => $message,
        'context' => $context,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}
