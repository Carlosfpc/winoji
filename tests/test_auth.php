<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/includes/db.php';
require_once __DIR__ . '/../app/api/auth.php';

// Test login with valid credentials
$result = attempt_login('admin@example.com', 'password');
assert($result['success'] === true, 'Valid login should succeed');
assert(isset($result['user']['id']), 'Should return user data');

// Test login with invalid credentials
$result2 = attempt_login('admin@example.com', 'wrong');
assert($result2['success'] === false, 'Invalid login should fail');

echo "PASS: Auth login logic works\n";
