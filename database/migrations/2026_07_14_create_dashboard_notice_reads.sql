CREATE TABLE IF NOT EXISTS cpms_dashboard_notice_reads (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    notice_id VARCHAR(100) NOT NULL,
    employee_id INT NOT NULL,
    read_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_notice_employee (notice_id, employee_id),
    KEY idx_notice_id (notice_id),
    KEY idx_employee_id (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
