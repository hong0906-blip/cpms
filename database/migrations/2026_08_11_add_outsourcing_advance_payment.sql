-- PHP 5.6 / MySQL 5.6 호환
-- 공사 > 외주비 입력 선급여부 (N/Y)
-- MySQL 5.6은 ADD COLUMN IF NOT EXISTS를 지원하지 않으므로 컬럼 존재 여부 확인 후 1회 실행합니다.
ALTER TABLE cpms_outsourcing_costs
    ADD COLUMN advance_payment_yn CHAR(1) NOT NULL DEFAULT 'N' AFTER amount;
