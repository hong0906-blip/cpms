-- C:\www\cpms\database\migrations\2026_06_16_alter_project_labor_workers.sql
-- 기존 공사 > 노무비 > 인원작성 테이블에 인력관리 연결/snapshot 컬럼 추가
-- 주의: MySQL 5.6은 ADD COLUMN IF NOT EXISTS를 지원하지 않으므로,
--       운영 DB에서는 컬럼 존재 여부를 확인한 뒤 필요한 ALTER만 실행한다.

ALTER TABLE cpms_project_labor_workers
    ADD COLUMN worker_id INT NULL AFTER direct_member_id,
    ADD COLUMN worker_name_snapshot VARCHAR(100) NULL AFTER worker_id,
    ADD COLUMN agency_name_snapshot VARCHAR(100) NULL AFTER worker_name_snapshot,
    ADD COLUMN job_type_snapshot VARCHAR(100) NULL AFTER agency_name_snapshot,
    ADD COLUMN daily_wage_snapshot INT NOT NULL DEFAULT 0 AFTER job_type_snapshot,
    ADD COLUMN source_type VARCHAR(30) NOT NULL DEFAULT 'manual' AFTER daily_wage_snapshot,
    ADD COLUMN matched_status VARCHAR(30) NOT NULL DEFAULT 'manual' AFTER source_type;

ALTER TABLE cpms_project_labor_workers
    ADD INDEX idx_project_labor_worker_id(worker_id),
    ADD INDEX idx_project_labor_match(project_id, matched_status);
