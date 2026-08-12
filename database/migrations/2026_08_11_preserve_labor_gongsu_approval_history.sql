-- 공수 승인요청이 기존 승인 완료 행을 덮어쓰지 않도록 셀 고유키를 일반 인덱스로 전환합니다.
-- PHP 5.6 / MySQL 5.6 호환

SET @cpms_schema_sql = IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cpms_labor_gongsu_overrides') > 0
    AND (SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'cpms_labor_gongsu_overrides'
           AND INDEX_NAME = 'uk_labor_override') > 0,
    'ALTER TABLE cpms_labor_gongsu_overrides DROP INDEX uk_labor_override',
    'SELECT 1'
);
PREPARE cpms_schema_stmt FROM @cpms_schema_sql;
EXECUTE cpms_schema_stmt;
DEALLOCATE PREPARE cpms_schema_stmt;

SET @cpms_schema_sql = IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cpms_labor_gongsu_overrides') > 0
    AND (SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'cpms_labor_gongsu_overrides'
           AND INDEX_NAME = 'idx_labor_override_cell') = 0,
    'ALTER TABLE cpms_labor_gongsu_overrides ADD KEY idx_labor_override_cell(project_id, worker_key, work_date)',
    'SELECT 1'
);
PREPARE cpms_schema_stmt FROM @cpms_schema_sql;
EXECUTE cpms_schema_stmt;
DEALLOCATE PREPARE cpms_schema_stmt;
