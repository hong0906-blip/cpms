CREATE TABLE IF NOT EXISTS cpms_birthday_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    celebration_date DATE NOT NULL,
    birthday_employee_id INT NOT NULL,
    comment_text TEXT NOT NULL,
    created_by_employee_id INT NULL,
    created_by_name VARCHAR(100) NOT NULL,
    created_by_email VARCHAR(190) NULL,
    created_by_photo_path VARCHAR(500) NULL,
    created_at DATETIME NOT NULL,
    KEY idx_birthday_comments_day_employee (celebration_date, birthday_employee_id),
    KEY idx_birthday_comments_author (created_by_employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE cpms_birthday_comments
    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE cpms_birthday_comments
    MODIFY comment_text TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL;
