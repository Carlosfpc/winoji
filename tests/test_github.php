<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/includes/db.php';
require_once __DIR__ . '/../app/api/github.php';

// Test token encryption/decryption (no DB needed)
$token = 'ghp_testtoken123';
$encrypted = encrypt_token($token);
assert($encrypted !== $token, 'Token should be encrypted');
$decrypted = decrypt_token($encrypted);
assert($decrypted === $token, 'Token should decrypt correctly');

echo "PASS: GitHub token encryption works\n";
