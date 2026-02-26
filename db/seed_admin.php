<?php
require_once __DIR__ . '/../config/config.php';
$pdo = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// Create team if none exists
$team = $pdo->query("SELECT id FROM team LIMIT 1")->fetch();
if (!$team) {
    $pdo->exec("INSERT INTO team (name) VALUES ('My Team')");
    $team_id = (int)$pdo->lastInsertId();
    echo "Created team id=$team_id\n";
} else {
    $team_id = (int)$team['id'];
    echo "Team exists id=$team_id\n";
}

// Create admin user if not exists
$user = $pdo->query("SELECT id FROM users WHERE email='admin@example.com' LIMIT 1")->fetch();
if (!$user) {
    $hash = password_hash('admin123', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?,?,?,?)")
        ->execute(['Admin', 'admin@example.com', $hash, 'admin']);
    $user_id = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO team_members (team_id, user_id, role) VALUES (?,?,?)")
        ->execute([$team_id, $user_id, 'admin']);
    echo "Created admin user id=$user_id\n";
    echo "  Email:    admin@example.com\n";
    echo "  Password: admin123\n";
} else {
    echo "Admin user already exists\n";
}

// Create a default project
$project = $pdo->query("SELECT id FROM projects LIMIT 1")->fetch();
if (!$project) {
    $user_id = $user['id'] ?? $pdo->query("SELECT id FROM users WHERE email='admin@example.com'")->fetchColumn();
    $pdo->prepare("INSERT INTO projects (name, description, created_by) VALUES (?,?,?)")
        ->execute(['My Project', 'Default project', $user_id]);
    echo "Created default project\n";
} else {
    echo "Project already exists\n";
}

echo "\nDone. Open http://localhost:8000 and log in.\n";
