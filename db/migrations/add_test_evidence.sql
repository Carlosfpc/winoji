-- Evidence images for test execution steps
CREATE TABLE IF NOT EXISTS test_evidence (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    execution_step_id INT NOT NULL,
    image             MEDIUMTEXT NOT NULL,
    created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (execution_step_id) REFERENCES test_execution_steps(id) ON DELETE CASCADE
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
