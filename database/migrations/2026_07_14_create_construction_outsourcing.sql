-- PHP 5.6 / MySQL 5.6 호환
-- 1) 공사 > 노무비 > 인원작성 외주비 선택값
-- 주의: MySQL 5.6은 ADD COLUMN IF NOT EXISTS를 지원하지 않으므로 컬럼 존재 여부 확인 후 실행합니다.
ALTER TABLE cpms_project_labor_workers
    ADD COLUMN is_outsourcing TINYINT(1) NOT NULL DEFAULT 0 AFTER company_name;

-- 2) 공사 > 외주비 > 외주비 입력
CREATE TABLE cpms_outsourcing_costs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    expense_date DATE NOT NULL,
    category VARCHAR(20) NOT NULL DEFAULT '외주비',
    company_name VARCHAR(120) NOT NULL,
    representative_name VARCHAR(100) NULL,
    business_no VARCHAR(30) NULL,
    contact VARCHAR(50) NULL,
    amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    created_by_name VARCHAR(100) NULL,
    created_by_email VARCHAR(190) NULL,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_outsourcing_project_date (project_id, expense_date, is_deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
