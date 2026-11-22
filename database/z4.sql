SET @z4_has_worker = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='worker');
SET @z4_worker_sql = IF(@z4_has_worker=0,'ALTER TABLE users ADD COLUMN worker TINYINT UNSIGNED NOT NULL DEFAULT 0','SELECT 1');
PREPARE z4_worker_stmt FROM @z4_worker_sql;
EXECUTE z4_worker_stmt;
DEALLOCATE PREPARE z4_worker_stmt;

CREATE TABLE IF NOT EXISTS domeny (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  host VARCHAR(253) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  port SMALLINT UNSIGNED NOT NULL,
  user_id SMALLINT UNSIGNED NOT NULL,
  errors INT UNSIGNED NOT NULL DEFAULT 0,
  total_errors INT UNSIGNED NOT NULL DEFAULT 0,
  failure DATETIME DEFAULT NULL,
  checked_at DATETIME DEFAULT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'nie sprawdzono',
  last_error VARCHAR(200) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY domeny_user_host_port (user_id,host,port),
  KEY domeny_owner (user_id),
  CONSTRAINT domeny_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci;

CREATE TABLE IF NOT EXISTS z4_probe_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  domena_id INT UNSIGNED NOT NULL,
  checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  state VARCHAR(32) NOT NULL,
  error VARCHAR(200) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY z4_history_domain_time (domena_id,checked_at),
  CONSTRAINT z4_history_domain FOREIGN KEY (domena_id) REFERENCES domeny(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci;
