<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/includes/db.php';
require_once __DIR__ . '/../app/includes/auth.php';
require_once __DIR__ . '/../app/api/team.php';

$_SESSION['user'] = ['id' => 1, 'role' => 'admin'];

$members = list_team_members(1);
assert(is_array($members), 'Should return array');
assert(count($members) >= 1, 'Should have at least one member');

echo "PASS: Team management works\n";
