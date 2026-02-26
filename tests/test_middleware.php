<?php
// Note: No session_start() here - we manipulate $_SESSION directly for testing
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/includes/auth.php';

// Test: not logged in
$_SESSION = [];
$result = is_authenticated();
assert($result === false, 'Should return false when not authenticated');

// Test: logged in
$_SESSION['user'] = ['id' => 1, 'role' => 'admin'];
$result = is_authenticated();
assert($result === true, 'Should return true when authenticated');

// Test: role check
assert(has_role('admin') === true, 'Admin should have admin role');
assert(has_role('member') === true, 'Admin should also pass member check');

$_SESSION['user']['role'] = 'member';
assert(has_role('admin') === false, 'Member should not have admin role');

echo "PASS: Auth middleware works\n";
