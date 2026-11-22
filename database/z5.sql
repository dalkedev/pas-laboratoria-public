SET @z5_sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE goscieportalu ADD COLUMN application VARCHAR(16) DEFAULT NULL', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'goscieportalu' AND COLUMN_NAME = 'application');
PREPARE z5_statement FROM @z5_sql;
EXECUTE z5_statement;
DEALLOCATE PREPARE z5_statement;

SET @z5_sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE goscieportalu ADD COLUMN event_type VARCHAR(16) DEFAULT NULL', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'goscieportalu' AND COLUMN_NAME = 'event_type');
PREPARE z5_statement FROM @z5_sql;
EXECUTE z5_statement;
DEALLOCATE PREPARE z5_statement;

SET @z5_sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE goscieportalu ADD COLUMN login_name VARCHAR(128) DEFAULT NULL', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'goscieportalu' AND COLUMN_NAME = 'login_name');
PREPARE z5_statement FROM @z5_sql;
EXECUTE z5_statement;
DEALLOCATE PREPARE z5_statement;

SET @z5_sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE goscieportalu ADD COLUMN login_success TINYINT UNSIGNED DEFAULT NULL', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'goscieportalu' AND COLUMN_NAME = 'login_success');
PREPARE z5_statement FROM @z5_sql;
EXECUTE z5_statement;
DEALLOCATE PREPARE z5_statement;
