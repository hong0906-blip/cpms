-- 관리 > 직영팀 명부 확장
-- MySQL 5.6 호환: ADD COLUMN IF NOT EXISTS 대신 information_schema + PREPARE 사용

CREATE TABLE IF NOT EXISTS direct_team_members (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    photo_path VARCHAR(255) NULL,
    phone VARCHAR(50) NULL,
    hire_date DATE NULL,
    resign_date DATE NULL,
    vehicle_number VARCHAR(50) NULL,
    resident_no VARCHAR(30) NULL,
    bank_account VARCHAR(80) NULL,
    bank_name VARCHAR(50) NULL,
    account_holder VARCHAR(100) NULL,
    monthly_salary INT UNSIGNED NOT NULL DEFAULT 0,
    address VARCHAR(255) NULL,
    note VARCHAR(120) NULL,
    deposit_rate INT NOT NULL DEFAULT 0,
    daily_wage INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_direct_team_active (is_active),
    KEY idx_direct_team_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

SET @cpms_direct_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='direct_team_members' AND COLUMN_NAME='photo_path')=0, 'ALTER TABLE direct_team_members ADD COLUMN photo_path VARCHAR(255) NULL', 'SELECT 1');
PREPARE cpms_direct_stmt FROM @cpms_direct_sql; EXECUTE cpms_direct_stmt; DEALLOCATE PREPARE cpms_direct_stmt;
SET @cpms_direct_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='direct_team_members' AND COLUMN_NAME='phone')=0, 'ALTER TABLE direct_team_members ADD COLUMN phone VARCHAR(50) NULL', 'SELECT 1');
PREPARE cpms_direct_stmt FROM @cpms_direct_sql; EXECUTE cpms_direct_stmt; DEALLOCATE PREPARE cpms_direct_stmt;
SET @cpms_direct_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='direct_team_members' AND COLUMN_NAME='hire_date')=0, 'ALTER TABLE direct_team_members ADD COLUMN hire_date DATE NULL', 'SELECT 1');
PREPARE cpms_direct_stmt FROM @cpms_direct_sql; EXECUTE cpms_direct_stmt; DEALLOCATE PREPARE cpms_direct_stmt;
SET @cpms_direct_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='direct_team_members' AND COLUMN_NAME='resign_date')=0, 'ALTER TABLE direct_team_members ADD COLUMN resign_date DATE NULL', 'SELECT 1');
PREPARE cpms_direct_stmt FROM @cpms_direct_sql; EXECUTE cpms_direct_stmt; DEALLOCATE PREPARE cpms_direct_stmt;
SET @cpms_direct_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='direct_team_members' AND COLUMN_NAME='vehicle_number')=0, 'ALTER TABLE direct_team_members ADD COLUMN vehicle_number VARCHAR(50) NULL', 'SELECT 1');
PREPARE cpms_direct_stmt FROM @cpms_direct_sql; EXECUTE cpms_direct_stmt; DEALLOCATE PREPARE cpms_direct_stmt;
SET @cpms_direct_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='direct_team_members' AND COLUMN_NAME='resident_no')=0, 'ALTER TABLE direct_team_members ADD COLUMN resident_no VARCHAR(30) NULL', 'SELECT 1');
PREPARE cpms_direct_stmt FROM @cpms_direct_sql; EXECUTE cpms_direct_stmt; DEALLOCATE PREPARE cpms_direct_stmt;
SET @cpms_direct_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='direct_team_members' AND COLUMN_NAME='bank_account')=0, 'ALTER TABLE direct_team_members ADD COLUMN bank_account VARCHAR(80) NULL', 'SELECT 1');
PREPARE cpms_direct_stmt FROM @cpms_direct_sql; EXECUTE cpms_direct_stmt; DEALLOCATE PREPARE cpms_direct_stmt;
SET @cpms_direct_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='direct_team_members' AND COLUMN_NAME='bank_name')=0, 'ALTER TABLE direct_team_members ADD COLUMN bank_name VARCHAR(50) NULL', 'SELECT 1');
PREPARE cpms_direct_stmt FROM @cpms_direct_sql; EXECUTE cpms_direct_stmt; DEALLOCATE PREPARE cpms_direct_stmt;
SET @cpms_direct_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='direct_team_members' AND COLUMN_NAME='account_holder')=0, 'ALTER TABLE direct_team_members ADD COLUMN account_holder VARCHAR(100) NULL', 'SELECT 1');
PREPARE cpms_direct_stmt FROM @cpms_direct_sql; EXECUTE cpms_direct_stmt; DEALLOCATE PREPARE cpms_direct_stmt;
SET @cpms_direct_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='direct_team_members' AND COLUMN_NAME='monthly_salary')=0, 'ALTER TABLE direct_team_members ADD COLUMN monthly_salary INT UNSIGNED NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE cpms_direct_stmt FROM @cpms_direct_sql; EXECUTE cpms_direct_stmt; DEALLOCATE PREPARE cpms_direct_stmt;
SET @cpms_direct_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='direct_team_members' AND COLUMN_NAME='is_active')=0, 'ALTER TABLE direct_team_members ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE cpms_direct_stmt FROM @cpms_direct_sql; EXECUTE cpms_direct_stmt; DEALLOCATE PREPARE cpms_direct_stmt;
SET @cpms_direct_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='direct_team_members' AND COLUMN_NAME='created_at')=0, 'ALTER TABLE direct_team_members ADD COLUMN created_at DATETIME NULL', 'SELECT 1');
PREPARE cpms_direct_stmt FROM @cpms_direct_sql; EXECUTE cpms_direct_stmt; DEALLOCATE PREPARE cpms_direct_stmt;
SET @cpms_direct_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='direct_team_members' AND COLUMN_NAME='updated_at')=0, 'ALTER TABLE direct_team_members ADD COLUMN updated_at DATETIME NULL', 'SELECT 1');
PREPARE cpms_direct_stmt FROM @cpms_direct_sql; EXECUTE cpms_direct_stmt; DEALLOCATE PREPARE cpms_direct_stmt;
