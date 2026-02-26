<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/includes/db.php';
require_once __DIR__ . '/../app/includes/auth.php';
require_once __DIR__ . '/../app/api/issues.php';

$_SESSION['user'] = ['id' => 1, 'role' => 'admin'];

$result = create_issue(1, 'Test Issue', 'Test body', 'todo', 'medium', null, null, 1);
assert($result['success'] === true, 'Should create issue');
$issue_id = $result['id'];

$issue = get_issue($issue_id);
assert($issue['title'] === 'Test Issue', 'Should get issue');

$result2 = update_issue_status($issue_id, 'in_progress');
assert($result2['success'] === true, 'Should update status');

$issues = list_issues(1);
assert(is_array($issues), 'Should list issues');

get_db()->exec("DELETE FROM issues WHERE id = $issue_id");
echo "PASS: Issues CRUD works\n";
