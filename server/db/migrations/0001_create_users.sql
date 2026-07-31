CREATE TABLE users (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email           VARCHAR(255) NOT NULL UNIQUE,
  display_name    VARCHAR(255) NOT NULL,
  role            ENUM('user','admin') NOT NULL DEFAULT 'user',
  status          ENUM('active','disabled') NOT NULL DEFAULT 'active',
  security_stamp  CHAR(32) NOT NULL,
  last_login_at   DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
