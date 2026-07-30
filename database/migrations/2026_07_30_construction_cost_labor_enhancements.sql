-- 공사 외주비/노무비 기능 보강
-- PHP 5.6 / MySQL 5.6 환경
-- MySQL 5.6은 ADD COLUMN IF NOT EXISTS를 지원하지 않으므로 information_schema로 확인한 뒤 동적 실행합니다.

SET @cpms_schema_sql = IF(
    (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cpms_outsourcing_costs') > 0
    AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cpms_outsourcing_costs' AND COLUMN_NAME = 'memo') = 0,
    'ALTER TABLE cpms_outsourcing_costs ADD COLUMN memo VARCHAR(500) NULL AFTER amount',
    'SELECT 1'
);
PREPARE cpms_schema_stmt FROM @cpms_schema_sql;
EXECUTE cpms_schema_stmt;
DEALLOCATE PREPARE cpms_schema_stmt;

CREATE TABLE IF NOT EXISTS cpms_outsourcing_cost_files (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    outsourcing_cost_id INT UNSIGNED NOT NULL,
    project_id INT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    stored_path VARCHAR(500) NOT NULL,
    mime_type VARCHAR(120) NULL,
    file_size INT UNSIGNED NULL,
    uploaded_by INT NULL,
    uploaded_by_name VARCHAR(100) NULL,
    uploaded_at DATETIME NOT NULL,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    KEY idx_outsourcing_file_cost (outsourcing_cost_id, is_deleted),
    KEY idx_outsourcing_file_project (project_id, is_deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

SET @cpms_schema_sql = IF(
    (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cpms_project_labor_worker_months') > 0
    AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cpms_project_labor_worker_months' AND COLUMN_NAME = 'outsourcing_start_date') = 0,
    'ALTER TABLE cpms_project_labor_worker_months ADD COLUMN outsourcing_start_date DATE NULL AFTER outsourcing_ratio_is_set',
    'SELECT 1'
);
PREPARE cpms_schema_stmt FROM @cpms_schema_sql;
EXECUTE cpms_schema_stmt;
DEALLOCATE PREPARE cpms_schema_stmt;

SET @cpms_schema_sql = IF(
    (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cpms_project_labor_worker_months') > 0
    AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cpms_project_labor_worker_months' AND COLUMN_NAME = 'outsourcing_end_date') = 0,
    'ALTER TABLE cpms_project_labor_worker_months ADD COLUMN outsourcing_end_date DATE NULL AFTER outsourcing_start_date',
    'SELECT 1'
);
PREPARE cpms_schema_stmt FROM @cpms_schema_sql;
EXECUTE cpms_schema_stmt;
DEALLOCATE PREPARE cpms_schema_stmt;

SET @cpms_schema_sql = IF(
    (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cpms_labor_gongsu_overrides') > 0
    AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cpms_labor_gongsu_overrides' AND COLUMN_NAME = 'request_scope') = 0,
    'ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN request_scope VARCHAR(20) NULL AFTER batch_token',
    'SELECT 1'
);
PREPARE cpms_schema_stmt FROM @cpms_schema_sql;
EXECUTE cpms_schema_stmt;
DEALLOCATE PREPARE cpms_schema_stmt;
