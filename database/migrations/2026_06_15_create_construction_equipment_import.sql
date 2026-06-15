-- C:\www\cpms\database\migrations\2026_06_15_create_construction_equipment_import.sql
-- 장비비 엑셀 업로드 이력 테이블
-- 실제 날짜별 장비비는 기존 cpms_equipment_items / cpms_equipment_usage 테이블에 저장한다.

CREATE TABLE IF NOT EXISTS cpms_equipment_excel_import_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    base_ym CHAR(7) NOT NULL,
    original_name VARCHAR(255) DEFAULT '',
    stored_name VARCHAR(255) DEFAULT '',
    total_count INT NOT NULL DEFAULT 0,
    saved_count INT NOT NULL DEFAULT 0,
    updated_count INT NOT NULL DEFAULT 0,
    skipped_count INT NOT NULL DEFAULT 0,
    error_count INT NOT NULL DEFAULT 0,
    total_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    created_by_name VARCHAR(100) DEFAULT '',
    created_by_email VARCHAR(190) DEFAULT '',
    created_at DATETIME NOT NULL,
    KEY idx_project_ym (project_id, base_ym),
    KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
