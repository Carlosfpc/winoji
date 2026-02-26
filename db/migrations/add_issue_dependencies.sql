CREATE TABLE IF NOT EXISTS issue_dependencies (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    from_issue_id INT NOT NULL,
    to_issue_id   INT NOT NULL,
    type          ENUM('blocks','relates_to') NOT NULL DEFAULT 'blocks',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dep (from_issue_id, to_issue_id, type),
    FOREIGN KEY (from_issue_id) REFERENCES issues(id) ON DELETE CASCADE,
    FOREIGN KEY (to_issue_id)   REFERENCES issues(id) ON DELETE CASCADE
);
