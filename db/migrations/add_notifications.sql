CREATE TABLE IF NOT EXISTS notifications (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL,
    project_id   INT NOT NULL,
    actor_id     INT NOT NULL,
    type         VARCHAR(50) NOT NULL,
    entity_type  VARCHAR(20) NOT NULL,
    entity_id    INT NOT NULL,
    entity_title VARCHAR(255) NULL,
    read_at      DATETIME NULL DEFAULT NULL,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_read (user_id, read_at),
    INDEX idx_user_created (user_id, created_at DESC),
    FOREIGN KEY (user_id)  REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE CASCADE
);
