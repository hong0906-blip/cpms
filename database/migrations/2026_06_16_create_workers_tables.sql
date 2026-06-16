-- C:\www\cpms\database\migrations\2026_06_16_create_workers_tables.sql
-- 관리 > 인력관리 기본 테이블
-- PHP 5.6 + MySQL 5.6 환경 기준

CREATE TABLE IF NOT EXISTS workers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    import_no VARCHAR(50) NULL,
    name VARCHAR(100) NOT NULL,
    resident_no_enc TEXT NULL,
    resident_no_hash CHAR(64) NULL,
    birth_date DATE NULL,
    phone VARCHAR(50) NULL,
    phone_digits VARCHAR(30) NULL,
    address VARCHAR(255) NULL,
    job_type VARCHAR(100) NULL,
    agency_name VARCHAR(100) NULL,
    daily_wage INT DEFAULT 0,
    account_holder VARCHAR(100) NULL,
    bank_name VARCHAR(100) NULL,
    bank_account_enc TEXT NULL,
    bank_account_hash CHAR(64) NULL,
    memo TEXT NULL,
    source_type VARCHAR(30) DEFAULT 'manual',
    is_active TINYINT DEFAULT 1,
    created_by INT NULL,
    updated_by INT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    deleted_at DATETIME NULL,
    INDEX idx_workers_name(name),
    INDEX idx_workers_phone_digits(phone_digits),
    INDEX idx_workers_agency_name(agency_name),
    INDEX idx_workers_job_type(job_type),
    INDEX idx_workers_resident_hash(resident_no_hash),
    INDEX idx_workers_active(is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS worker_import_batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    original_filename VARCHAR(255) NULL,
    stored_filename VARCHAR(255) NULL,
    total_rows INT DEFAULT 0,
    success_rows INT DEFAULT 0,
    update_rows INT DEFAULT 0,
    skip_rows INT DEFAULT 0,
    error_rows INT DEFAULT 0,
    uploaded_by INT NULL,
    uploaded_at DATETIME NOT NULL,
    INDEX idx_worker_import_uploaded_at(uploaded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS worker_import_errors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    batch_id INT NOT NULL,
    row_no INT NOT NULL,
    error_message TEXT NULL,
    raw_json TEXT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_worker_import_errors_batch(batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
