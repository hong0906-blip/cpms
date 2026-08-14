-- PHP 화면에서 동일 구조를 자동 확인하므로 별도 명령 실행은 필요하지 않습니다.
-- MySQL 5.6 호환: 현장 인원의 월별 임금단가 이력

CREATE TABLE IF NOT EXISTS cpms_project_labor_worker_wages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    labor_worker_id INT UNSIGNED NOT NULL,
    effective_month CHAR(7) NOT NULL,
    daily_wage INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_project_labor_worker_wage (project_id, labor_worker_id, effective_month),
    KEY idx_project_labor_worker_wage_lookup (project_id, effective_month, labor_worker_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- 기존 이름 유일키는 동명이인 등록을 막으므로 애플리케이션이 최초 화면 접근 시
-- 안전하게 제거하고 일반 (project_id, name) 인덱스로 교체합니다.
