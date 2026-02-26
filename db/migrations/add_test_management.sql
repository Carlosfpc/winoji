-- Test management (Xray-style)
CREATE TABLE IF NOT EXISTS test_cases (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    issue_id    INT NOT NULL,
    title       VARCHAR(255) NOT NULL,
    assignee_id INT DEFAULT NULL,
    created_by  INT NOT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (issue_id)    REFERENCES issues(id)  ON DELETE CASCADE,
    FOREIGN KEY (assignee_id) REFERENCES users(id)   ON DELETE SET NULL,
    FOREIGN KEY (created_by)  REFERENCES users(id)   ON DELETE RESTRICT
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS test_steps (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    test_case_id    INT NOT NULL,
    sort_order      INT NOT NULL DEFAULT 0,
    action          TEXT NOT NULL,
    expected_result TEXT DEFAULT NULL,
    FOREIGN KEY (test_case_id) REFERENCES test_cases(id) ON DELETE CASCADE
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS test_executions (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    test_case_id INT NOT NULL,
    executed_by  INT NOT NULL,
    result       ENUM('pass','fail') NOT NULL,
    executed_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (test_case_id) REFERENCES test_cases(id) ON DELETE CASCADE,
    FOREIGN KEY (executed_by)  REFERENCES users(id)      ON DELETE RESTRICT
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS test_execution_steps (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    execution_id INT NOT NULL,
    step_id      INT NOT NULL,
    result       ENUM('pass','fail','skip') NOT NULL,
    comment      TEXT DEFAULT NULL,
    FOREIGN KEY (execution_id) REFERENCES test_executions(id) ON DELETE CASCADE,
    FOREIGN KEY (step_id)      REFERENCES test_steps(id)      ON DELETE CASCADE
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
