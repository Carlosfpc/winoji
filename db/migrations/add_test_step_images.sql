-- Reference images attached to test case step definitions
CREATE TABLE IF NOT EXISTS test_step_images (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    step_id    INT NOT NULL,
    image      MEDIUMTEXT NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (step_id) REFERENCES test_steps(id) ON DELETE CASCADE
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
