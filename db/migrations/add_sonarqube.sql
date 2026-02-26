CREATE TABLE IF NOT EXISTS sonarqube_projects (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id        INT NOT NULL UNIQUE,
    sonar_url         VARCHAR(255) NOT NULL DEFAULT 'http://localhost:9000',
    sonar_token       TEXT NOT NULL,
    sonar_project_key VARCHAR(255) NOT NULL,
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
