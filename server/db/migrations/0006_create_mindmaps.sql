CREATE TABLE mindmaps (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT UNSIGNED NOT NULL,
  folder_id   BIGINT UNSIGNED NULL,
  title       VARCHAR(255) NOT NULL DEFAULT '無題のマインドマップ',
  data        JSON NOT NULL,
  revision    INT UNSIGNED NOT NULL DEFAULT 1,
  deleted_at  DATETIME NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (folder_id, user_id) REFERENCES folders(id, user_id),
  KEY idx_mindmaps_list (user_id, folder_id, updated_at),
  KEY idx_mindmaps_trash (user_id, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
