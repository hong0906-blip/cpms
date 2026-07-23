-- 공사 > 노무비 > 인원작성 비고
-- MySQL 5.6에서는 실행 전 컬럼 존재 여부를 확인해야 합니다.
ALTER TABLE cpms_project_labor_workers
    ADD COLUMN remark VARCHAR(255) NULL AFTER company_name;
