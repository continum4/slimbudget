ALTER TABLE users
  ADD session_version INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Raising it closes every open session of the user',
  ADD password_expires_at DATETIME NULL COMMENT 'Only for temporary passwords',
  ADD totp_secret VARCHAR(255) NULL COMMENT 'Encrypted; null when two-step verification is off',
  ADD totp_last_step INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'A code works only once';

UPDATE users SET password_expires_at=NOW() + INTERVAL 7 DAY WHERE must_change_password=1;

ALTER TABLE login_attempts
  MODIFY username VARCHAR(60) NOT NULL,
  ADD KEY (username, created_at),
  ADD KEY (ip, created_at),
  ADD KEY (created_at);

CREATE TABLE recovery_codes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  code_hash CHAR(64) NOT NULL,
  UNIQUE (user_id, code_hash),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE audit_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  action VARCHAR(40) NOT NULL,
  detail VARCHAR(255) NULL,
  ip VARCHAR(45) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (created_at),
  KEY (user_id, action, ip),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
