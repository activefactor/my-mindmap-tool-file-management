CREATE TABLE audit_logs (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_user_id BIGINT UNSIGNED NULL,
  action        VARCHAR(64) NOT NULL,
  target        VARCHAR(255) NULL,
  detail        JSON NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
  KEY idx_audit_logs_created (created_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
