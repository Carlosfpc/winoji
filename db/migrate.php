<?php
/**
 * DB Migration Runner
 *
 * Usage: php db/migrate.php
 *
 * Tracks applied migrations in a `_migrations` table.
 * Applies any .sql files in db/migrations/ that haven't been run yet.
 */
require_once __DIR__ . '/../config/config.php';

$dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
$pdo = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Create tracking table if it doesn't exist
$pdo->exec("CREATE TABLE IF NOT EXISTS _migrations (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    filename    VARCHAR(255) NOT NULL UNIQUE,
    applied_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB");

// Get already-applied migrations
$applied = $pdo->query("SELECT filename FROM _migrations ORDER BY filename")
               ->fetchAll(PDO::FETCH_COLUMN);

// Discover migration files
$files = glob(__DIR__ . '/migrations/*.sql');
if (!$files) {
    echo "No migration files found in db/migrations/\n";
    exit(0);
}
sort($files);

$ran = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied)) {
        echo "  [skip] $name\n";
        continue;
    }

    $sql = file_get_contents($file);
    // Skip files that are only comments/whitespace
    $executable = preg_replace('/--[^\n]*\n?/', '', $sql);
    if (trim($executable) === '') {
        $pdo->prepare("INSERT IGNORE INTO _migrations (filename) VALUES (?)")->execute([$name]);
        echo "  [mark] $name (comment-only)\n";
        $ran++;
        continue;
    }

    try {
        // Execute each statement individually (split on semicolons)
        $stmts = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($stmts as $stmt) {
            if ($stmt === '') continue;
            try {
                $pdo->exec($stmt);
            } catch (\PDOException $inner) {
                // 1060 = Duplicate column name — column already exists, treat as warning
                if ($inner->getCode() == '42S21' || str_contains($inner->getMessage(), 'Duplicate column')) {
                    echo "  [warn] $name: column already exists (skipped)\n";
                } else {
                    throw $inner;
                }
            }
        }
        $pdo->prepare("INSERT INTO _migrations (filename) VALUES (?)")->execute([$name]);
        echo "  [ok]   $name\n";
        $ran++;
    } catch (\PDOException $e) {
        echo "  [FAIL] $name: " . $e->getMessage() . "\n";
        exit(1);
    }
}

echo $ran
    ? "\n$ran migration(s) applied.\n"
    : "\nAll migrations up to date.\n";
