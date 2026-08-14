-- CPMS integrated vendor master (PHP/web bootstrap also applies this safely).
-- Existing transaction columns and values are not removed or rewritten.
CREATE TABLE IF NOT EXISTS cpms_vendors (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    vendor_uid VARCHAR(32) NULL,
    business_no VARCHAR(30) NULL,
    business_no_key VARCHAR(30) NULL,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(255) NULL,
    representative VARCHAR(100) NULL,
    phone VARCHAR(50) NULL,
    bank_name VARCHAR(100) NULL,
    account_number VARCHAR(100) NULL,
    account_holder VARCHAR(100) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_source VARCHAR(30) NOT NULL DEFAULT 'manual',
    created_by_name VARCHAR(100) NULL,
    created_by_email VARCHAR(190) NULL,
    created_at DATETIME NOT NULL,
    updated_by_name VARCHAR(100) NULL,
    updated_by_email VARCHAR(190) NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_vendor_uid (vendor_uid),
    UNIQUE KEY uk_vendor_business_no (business_no_key),
    KEY idx_vendor_name (name),
    KEY idx_vendor_active_name (is_active, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
