USE teamapp;

INSERT INTO team (name) VALUES ('My Team');

INSERT INTO users (name, email, password_hash, role)
VALUES ('Admin User', 'admin@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin');
-- password: password

INSERT INTO team_members (user_id, team_id, role) VALUES (1, 1, 'admin');

INSERT INTO projects (name, description, created_by) VALUES ('Demo Project', 'A demo project', 1);

INSERT INTO issues (project_id, title, description, status, priority, created_by)
VALUES
(1, 'First issue', 'This is a test issue', 'todo', 'medium', 1),
(1, 'Second issue', 'Another test issue', 'in_progress', 'high', 1);

INSERT INTO pages (title, content, created_by)
VALUES ('Welcome', '<h1>Welcome to the wiki</h1><p>Start writing here.</p>', 1);
