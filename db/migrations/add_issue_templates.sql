CREATE TABLE IF NOT EXISTS issue_templates (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    project_id  INT NOT NULL,
    name        VARCHAR(100) NOT NULL,
    title       VARCHAR(255) NOT NULL DEFAULT '',
    description TEXT,
    type_id     INT DEFAULT NULL,
    priority    ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (type_id)    REFERENCES issue_types(id) ON DELETE SET NULL
);
