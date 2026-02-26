<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/includes/db.php';
require_once __DIR__ . '/../app/includes/auth.php';
require_once __DIR__ . '/../app/api/pages.php';

$_SESSION['user'] = ['id' => 1, 'role' => 'admin'];

// Test create page
$result = create_page('Test Page', null, '<p>Hello</p>', 1);
assert($result['success'] === true, 'Should create page');
$page_id = $result['id'];

// Test get page
$page = get_page($page_id);
assert($page['title'] === 'Test Page', 'Should retrieve page');

// Test update page
$result2 = update_page($page_id, 'Updated Title', '<p>Updated</p>', 1);
assert($result2['success'] === true, 'Should update page');

// Test list pages
$pages = list_pages();
assert(is_array($pages), 'Should return array of pages');

// Cleanup
get_db()->exec("DELETE FROM pages WHERE id = $page_id");

echo "PASS: Pages CRUD works\n";
