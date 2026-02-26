<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/includes/db.php';

$pdo = get_db();
assert($pdo instanceof PDO, 'get_db() should return a PDO instance');

$stmt = $pdo->query("SELECT 1");
assert($stmt !== false, 'Should be able to execute a query');

echo "PASS: DB connection works\n";
