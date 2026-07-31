<?php
/**
 * 비용 월마감 잠금/변경승인 공통 서비스.
 * - 공사 및 안전·보건 비용의 귀속월, 승인선, 이력, 첨부, 자동반영을 한 곳에서 처리한다.
 * - PHP 5.6 / MySQL 5.6 호환.
 */

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;
use PDO;
use Exception;

class CostChangeService
{
    const STATUS_FIRST_PENDING = 'FIRST_PENDING';
    const STATUS_FINAL_PENDING = 'FINAL_PENDING';
    const STATUS_APPROVED = 'APPROVED';
    const STATUS_COMPLETED = 'COMPLETED';
    const STATUS_REJECTED = 'REJECTED';
    const STATUS_FAILED = 'FAILED';
    const STATUS_CANCELLED = 'CANCELLED';

    const REQUEST_MODIFY = 'MODIFY';
    const REQUEST_ADD = 'ADD';
    const REQUEST_MONTH_MOVE = 'MONTH_MOVE';
    const REQUEST_DELETE = 'DELETE';

    const FIRST_APPROVER_SETTING = 'cost_change_first_approver_employee_id';
    const FINAL_APPROVER_SETTING = 'cost_change_final_approver_employee_id';

    private static $installedCache = null;
    private static $metaCache = array();

    public static function pdo()
    {
        return Db::pdo();
    }

    public static function tableExists($pdo, $table)
    {
        if (!$pdo || !preg_match('/^[A-Za-z0-9_]+$/', (string)$table)) {
            return false;
        }

        try {
            $st = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = :table_name"
            );
            $st->bindValue(':table_name', (string)$table);
            $st->execute();

            return ((int)$st->fetchColumn() > 0);
        } catch (Exception $e) {
            return false;
        }
    }

    public static function columnExists($pdo, $table, $column)
    {
        if (
            !$pdo ||
            !preg_match('/^[A-Za-z0-9_]+$/', (string)$table) ||
            !preg_match('/^[A-Za-z0-9_]+$/', (string)$column)
        ) {
            return false;
        }

        try {
            $st = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = :table_name
                   AND COLUMN_NAME = :column_name"
            );
            $st->bindValue(':table_name', (string)$table);
            $st->bindValue(':column_name', (string)$column);
            $st->execute();

            return ((int)$st->fetchColumn() > 0);
        } catch (Exception $e) {
            return false;
        }
    }

    public static function isInstalled($pdo)
    {
        if (self::$installedCache !== null) {
            return self::$installedCache;
        }

        self::$installedCache =
            self::tableExists($pdo, 'cpms_cost_change_requests') &&
            self::tableExists($pdo, 'cpms_cost_change_files') &&
            self::tableExists($pdo, 'cpms_cost_change_logs') &&
            self::tableExists($pdo, 'cpms_cost_record_meta');

        return self::$installedCache;
    }

    public static function installOrUpdate($pdo)
    {
        $results = array();

        if (!$pdo) {
            return array(
                'ok' => false,
                'message' => 'DB 연결에 실패했습니다.',
                'results' => $results
            );
        }

        $tables = array(
            'cpms_cost_change_requests' => "CREATE TABLE IF NOT EXISTS cpms_cost_change_requests (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                request_no VARCHAR(40) NOT NULL,
                root_request_id INT UNSIGNED NULL,
                parent_request_id INT UNSIGNED NULL,
                project_id INT UNSIGNED NOT NULL,
                project_name VARCHAR(190) NULL,
                request_department VARCHAR(100) NULL,
                requester_employee_id INT NULL,
                requester_name VARCHAR(100) NULL,
                requester_email VARCHAR(190) NULL,
                cost_type VARCHAR(40) NOT NULL,
                target_type VARCHAR(40) NOT NULL,
                target_id VARCHAR(80) NULL,
                active_target_key VARCHAR(190) NULL,
                request_type VARCHAR(30) NOT NULL,
                use_date DATE NULL,
                old_settlement_ym CHAR(7) NULL,
                new_settlement_ym CHAR(7) NULL,
                manual_settlement_yn TINYINT(1) NOT NULL DEFAULT 0,
                old_data MEDIUMTEXT NULL,
                requested_data MEDIUMTEXT NULL,
                old_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
                new_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
                reason TEXT NOT NULL,
                status VARCHAR(30) NOT NULL,
                current_stage VARCHAR(30) NULL,
                current_approver_employee_id INT NULL,
                first_approver_employee_id INT NOT NULL,
                first_approver_name VARCHAR(100) NULL,
                first_approver_email VARCHAR(190) NULL,
                first_result VARCHAR(20) NULL,
                first_opinion TEXT NULL,
                first_acted_at DATETIME NULL,
                final_approver_employee_id INT NOT NULL,
                final_approver_name VARCHAR(100) NULL,
                final_approver_email VARCHAR(190) NULL,
                final_result VARCHAR(20) NULL,
                final_opinion TEXT NULL,
                final_acted_at DATETIME NULL,
                rejected_by_employee_id INT NULL,
                rejected_by_name VARCHAR(100) NULL,
                rejected_stage VARCHAR(30) NULL,
                rejected_reason TEXT NULL,
                rejected_at DATETIME NULL,
                apply_result MEDIUMTEXT NULL,
                applied_at DATETIME NULL,
                apply_error TEXT NULL,
                cancelled_by_employee_id INT NULL,
                cancelled_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uk_cost_change_request_no (request_no),
                UNIQUE KEY uk_cost_change_active_target (active_target_key),
                KEY idx_cost_change_requester (requester_employee_id, status),
                KEY idx_cost_change_approver (current_approver_employee_id, status),
                KEY idx_cost_change_project (project_id, cost_type, created_at),
                KEY idx_cost_change_root (root_request_id, parent_request_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            'cpms_cost_change_files' => "CREATE TABLE IF NOT EXISTS cpms_cost_change_files (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                request_id INT UNSIGNED NOT NULL,
                source_request_id INT UNSIGNED NULL,
                file_group VARCHAR(20) NOT NULL DEFAULT 'NEW',
                original_name VARCHAR(255) NOT NULL,
                stored_name VARCHAR(255) NOT NULL,
                stored_path VARCHAR(500) NOT NULL,
                extension VARCHAR(20) NULL,
                mime_type VARCHAR(120) NULL,
                file_size INT UNSIGNED NULL,
                uploaded_by INT NULL,
                uploaded_by_name VARCHAR(100) NULL,
                uploaded_at DATETIME NOT NULL,
                is_deleted TINYINT(1) NOT NULL DEFAULT 0,
                KEY idx_cost_change_file_request (request_id, is_deleted),
                KEY idx_cost_change_file_source (source_request_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            'cpms_cost_change_logs' => "CREATE TABLE IF NOT EXISTS cpms_cost_change_logs (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                request_id INT UNSIGNED NOT NULL,
                event_type VARCHAR(40) NOT NULL,
                stage VARCHAR(30) NULL,
                actor_employee_id INT NULL,
                actor_name VARCHAR(100) NULL,
                actor_email VARCHAR(190) NULL,
                event_note TEXT NULL,
                event_data MEDIUMTEXT NULL,
                created_at DATETIME NOT NULL,
                KEY idx_cost_change_log_request (request_id, created_at),
                KEY idx_cost_change_log_actor (actor_employee_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            'cpms_cost_record_meta' => "CREATE TABLE IF NOT EXISTS cpms_cost_record_meta (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                target_type VARCHAR(40) NOT NULL,
                target_id VARCHAR(80) NOT NULL,
                project_id INT UNSIGNED NOT NULL,
                actual_use_date DATE NULL,
                settlement_ym CHAR(7) NULL,
                manual_settlement_yn TINYINT(1) NOT NULL DEFAULT 0,
                manual_reason TEXT NULL,
                quantity DECIMAL(18,4) NULL,
                unit_price DECIMAL(18,2) NULL,
                vendor_name_snapshot VARCHAR(190) NULL,
                item_name_snapshot VARCHAR(255) NULL,
                is_deleted TINYINT(1) NOT NULL DEFAULT 0,
                last_request_id INT UNSIGNED NULL,
                applied_data MEDIUMTEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uk_cost_record_target (target_type, target_id),
                KEY idx_cost_record_project_month (project_id, settlement_ym, is_deleted),
                KEY idx_cost_record_request (last_request_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            'cpms_approval_settings' => "CREATE TABLE IF NOT EXISTS cpms_approval_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(100) NULL,
                setting_value TEXT NULL,
                updated_at DATETIME NULL,
                UNIQUE KEY uniq_setting_key (setting_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            'cpms_google_chat_notifications' => "CREATE TABLE IF NOT EXISTS cpms_google_chat_notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                source_type VARCHAR(50) NOT NULL,
                source_id INT NULL,
                event_type VARCHAR(50) NULL,
                receiver_employee_id INT NULL,
                receiver_name VARCHAR(100) NULL,
                receiver_email VARCHAR(190) NULL,
                dm_space_name VARCHAR(255) NULL,
                message_text TEXT NULL,
                send_status VARCHAR(20) NULL,
                error_message TEXT NULL,
                sent_at DATETIME NULL,
                created_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        foreach ($tables as $name => $sql) {
            try {
                $pdo->exec($sql);

                $results[] = array(
                    'name' => $name,
                    'ok' => true,
                    'message' => '확인/생성 완료'
                );
            } catch (Exception $e) {
                $results[] = array(
                    'name' => $name,
                    'ok' => false,
                    'message' => $e->getMessage()
                );
            }
        }

        /*
         * 승인 완료 시 기존 비용 테이블에 값을 자동 반영할 때 필요한 열만
         * 웹 초기설정에서 점진적으로 보강한다. 기존 행의 금액/일자는 갱신하지 않는다.
         */
        $usageColumns = array(
            array(
                'table' => 'cpms_material_usage',
                'column' => 'advance_yn',
                'sql' => "ALTER TABLE cpms_material_usage
                          ADD COLUMN advance_yn CHAR(1) NOT NULL DEFAULT 'N'
                          AFTER amount"
            ),
            array(
                'table' => 'cpms_equipment_usage',
                'column' => 'work_unit',
                'sql' => "ALTER TABLE cpms_equipment_usage
                          ADD COLUMN work_unit DECIMAL(6,2) NOT NULL DEFAULT 1.00
                          AFTER use_date"
            ),
            array(
                'table' => 'cpms_equipment_usage',
                'column' => 'base_rate_snapshot',
                'sql' => "ALTER TABLE cpms_equipment_usage
                          ADD COLUMN base_rate_snapshot DECIMAL(15,2) NULL
                          AFTER work_unit"
            ),
            array(
                'table' => 'cpms_equipment_usage',
                'column' => 'amount',
                'sql' => "ALTER TABLE cpms_equipment_usage
                          ADD COLUMN amount DECIMAL(15,2) NULL
                          AFTER base_rate_snapshot"
            ),
            array(
                'table' => 'cpms_equipment_usage',
                'column' => 'is_manual_unit',
                'sql' => "ALTER TABLE cpms_equipment_usage
                          ADD COLUMN is_manual_unit TINYINT(1) NOT NULL DEFAULT 0
                          AFTER amount"
            )
        );

        foreach ($usageColumns as $usageColumn) {
            if (
                !self::tableExists($pdo, $usageColumn['table']) ||
                self::columnExists(
                    $pdo,
                    $usageColumn['table'],
                    $usageColumn['column']
                )
            ) {
                continue;
            }

            try {
                $pdo->exec($usageColumn['sql']);

                $results[] = array(
                    'name' => $usageColumn['table'] . '.' . $usageColumn['column'],
                    'ok' => true,
                    'message' => '자동 반영 호환 열 생성 완료'
                );
            } catch (Exception $e) {
                $results[] = array(
                    'name' => $usageColumn['table'] . '.' . $usageColumn['column'],
                    'ok' => false,
                    'message' => $e->getMessage()
                );
            }
        }

        self::$installedCache = null;

        if (self::isInstalled($pdo)) {
            self::autoLinkApprovers($pdo);
        }

        $ok = true;

        foreach ($results as $row) {
            if (empty($row['ok'])) {
                $ok = false;
            }
        }

        return array(
            'ok' => $ok,
            'message' => $ok
                ? '비용 변경 승인 구조를 확인/생성했습니다.'
                : '일부 구조를 생성하지 못했습니다.',
            'results' => $results,
            'approvers' => self::resolveApprovers($pdo)
        );
    }

    public static function validDate($value)
    {
        $value = trim((string)$value);

        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return '';
        }

        if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return '';
        }

        return sprintf(
            '%04d-%02d-%02d',
            (int)$m[1],
            (int)$m[2],
            (int)$m[3]
        );
    }

    public static function validYm($value)
    {
        $value = trim((string)$value);

        if (!preg_match('/^(\d{4})-(\d{2})$/', $value, $m)) {
            return '';
        }

        $year = (int)$m[1];
        $month = (int)$m[2];

        if ($year < 1900 || $year > 2100 || $month < 1 || $month > 12) {
            return '';
        }

        return sprintf('%04d-%02d', $year, $month);
    }

    public static function isLaborType($costType)
    {
        return in_array(
            strtolower(trim((string)$costType)),
            array('labor', 'labor_force', '노무', '노무비'),
            true
        );
    }

    public static function settlementYm($costType, $dateValue)
    {
        $date = self::validDate($dateValue);

        if ($date === '') {
            return '';
        }

        $ym = substr($date, 0, 7);

        if (
            !self::isLaborType($costType) &&
            (int)substr($date, 8, 2) >= 26
        ) {
            $ts = strtotime($ym . '-01 +1 month');

            if ($ts !== false) {
                return date('Y-m', $ts);
            }
        }

        return $ym;
    }

    public static function businessToday()
    {
        /*
         * CPMS의 월 마감은 한국 업무일 기준이다.
         * 서버 OS/PHP 시간대 설정에 의존하지 않도록
         * 명시적인 업무 시간대로 오늘 날짜를 계산한다.
         */
        try {
            $now = new \DateTime(
                'now',
                new \DateTimeZone('Asia/Seoul')
            );

            return $now->format('Y-m-d');
        } catch (Exception $e) {
            return date('Y-m-d');
        }
    }

    public static function currentSettlementYm($costType, $today)
    {
        $businessToday = self::businessToday();
        $today = self::validDate($today);

        if (
            $today === '' ||
            $today === gmdate('Y-m-d') ||
            $today === $businessToday
        ) {
            $today = $businessToday;
        }

        return self::settlementYm($costType, $today);
    }

    public static function periodForYm($costType, $ym)
    {
        $ym = self::validYm($ym);

        if ($ym === '') {
            return array(
                'start' => '',
                'end' => ''
            );
        }

        if (self::isLaborType($costType)) {
            $lastTs = strtotime($ym . '-01 +1 month -1 day');

            return array(
                'start' => $ym . '-01',
                'end' => $lastTs === false
                    ? ''
                    : date('Y-m-d', $lastTs)
            );
        }

        $prevTs = strtotime($ym . '-01 -1 month');

        return array(
            'start' => $prevTs === false
                ? ''
                : date('Y-m', $prevTs) . '-26',
            'end' => $ym . '-25'
        );
    }

    /**
     * 비용 월마감 잠금 상태를 반환한다.
     *
     * 일반 사용자:
     * - 현재 귀속월과 다른 자료는 잠금
     * - 수정하려면 비용 변경 승인 필요
     *
     * 개발부서:
     * - 마감된 이전 달 자료는 승인 없이 직접 수정 가능
     * - 미래 귀속월 또는 잘못된 귀속월은 기존대로 잠금
     */
    public static function lockInfo(
        $costType,
        $dateValue,
        $settlementYm,
        $today
    ) {
        $date = self::validDate($dateValue);
        $ym = self::validYm($settlementYm);

        if ($ym === '') {
            $ym = self::settlementYm($costType, $date);
        }

        $currentYm = self::currentSettlementYm($costType, $today);
        $period = self::periodForYm($costType, $ym);

        $department = trim((string)Auth::userDepartment());

        $isDevelopmentDepartment = in_array(
            $department,
            array(
                '개발',
                '개발부',
                '개발팀',
                '개발부서'
            ),
            true
        );

        $isInvalidYm = ($ym === '' || $currentYm === '');
        $isCurrentPeriod = (
            !$isInvalidYm &&
            $ym === $currentYm
        );
        $isPastPeriod = (
            !$isInvalidYm &&
            strcmp($ym, $currentYm) < 0
        );
        $isFuturePeriod = (
            !$isInvalidYm &&
            strcmp($ym, $currentYm) > 0
        );

        /*
         * 개발부서는 마감된 이전 달만 잠금을 해제한다.
         * 미래 귀속월과 잘못된 귀속월은 개발부서도 잠근다.
         */
        $developmentBypass = (
            $isDevelopmentDepartment &&
            $isPastPeriod
        );

        $locked = true;

        if ($isCurrentPeriod) {
            $locked = false;
        } elseif ($developmentBypass) {
            $locked = false;
        } elseif ($isInvalidYm || $isFuturePeriod || $isPastPeriod) {
            $locked = true;
        }

        return array(
            'use_date' => $date,
            'settlement_ym' => $ym,
            'current_settlement_ym' => $currentYm,
            'period_start' => $period['start'],
            'period_end' => $period['end'],
            'locked' => $locked,
            'approval_required' => $locked
        );
    }

    public static function costTypeLabel($value)
    {
        $map = array(
            'labor' => '노무비',
            'labor_force' => '노무비',
            'material' => '자재구입비',
            'equipment' => '장비비',
            'outsourcing' => '외주비',
            'safety' => '안전·보건 비용',
            'daily_cost' => '기타 투입비'
        );

        $value = trim((string)$value);

        return isset($map[$value])
            ? $map[$value]
            : $value;
    }

    public static function requestTypeLabel($value)
    {
        $map = array(
            self::REQUEST_MODIFY => '기존 내역 수정',
            self::REQUEST_ADD => '누락 내역 추가',
            self::REQUEST_MONTH_MOVE => '귀속월 변경',
            self::REQUEST_DELETE => '내역 삭제'
        );

        return isset($map[$value])
            ? $map[$value]
            : (string)$value;
    }

    public static function statusLabel($value)
    {
        $map = array(
            self::STATUS_FIRST_PENDING => '1차 승인 대기',
            self::STATUS_FINAL_PENDING => '최종 승인 대기',
            self::STATUS_APPROVED => '승인 완료',
            self::STATUS_COMPLETED => '처리 완료',
            self::STATUS_REJECTED => '반려',
            self::STATUS_FAILED => '처리 실패',
            self::STATUS_CANCELLED => '요청 취소'
        );

        return isset($map[$value])
            ? $map[$value]
            : (string)$value;
    }

    public static function stageLabel($value)
    {
        $map = array(
            'FIRST' => '박원덕 전무 1차 승인',
            'FINAL' => '부사장 최종 승인',
            'COMPLETED' => '자동 반영 완료',
            'REJECTED' => '반려',
            'FAILED' => '자동 반영 실패',
            'CANCELLED' => '요청 취소'
        );

        return isset($map[$value])
            ? $map[$value]
            : (string)$value;
    }

    public static function requestNo()
    {
        return 'CCR-' .
            date('YmdHis') .
            '-' .
            strtoupper(
                substr(
                    sha1(
                        uniqid(
                            (string)mt_rand(),
                            true
                        )
                    ),
                    0,
                    8
                )
            );
    }

    public static function jsonEncode($value)
    {
        $json = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE
        );

        return is_string($json)
            ? $json
            : '{}';
    }

    public static function jsonDecode($value)
    {
        $decoded = json_decode(
            (string)$value,
            true
        );

        return is_array($decoded)
            ? $decoded
            : array();
    }

    public static function employeeId()
    {
        $user = Auth::user();

        if (
            is_array($user) &&
            isset($user['id']) &&
            (int)$user['id'] > 0
        ) {
            return (int)$user['id'];
        }

        $pdo = self::pdo();

        if (!$pdo) {
            return 0;
        }

        $email = trim(
            (string)Auth::userEmail()
        );

        if ($email === '') {
            return 0;
        }

        try {
            $st = $pdo->prepare(
                "SELECT id
                 FROM employees
                 WHERE LOWER(email) = LOWER(:email)
                   AND is_active = 1
                 LIMIT 1"
            );
            $st->bindValue(':email', $email);
            $st->execute();

            return (int)$st->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    public static function employeeById($pdo, $employeeId)
    {
        if (!$pdo || (int)$employeeId <= 0) {
            return null;
        }

        try {
            $st = $pdo->prepare(
                "SELECT
                    id,
                    name,
                    email,
                    department,
                    position,
                    role,
                    is_active
                 FROM employees
                 WHERE id = :id
                 LIMIT 1"
            );
            $st->bindValue(
                ':id',
                (int)$employeeId,
                PDO::PARAM_INT
            );
            $st->execute();

            $row = $st->fetch(PDO::FETCH_ASSOC);

            return is_array($row)
                ? $row
                : null;
        } catch (Exception $e) {
            return null;
        }
    }

    public static function setting($pdo, $key)
    {
        if (
            !$pdo ||
            !self::tableExists(
                $pdo,
                'cpms_approval_settings'
            )
        ) {
            return '';
        }

        try {
            $st = $pdo->prepare(
                "SELECT setting_value
                 FROM cpms_approval_settings
                 WHERE setting_key = :setting_key
                 LIMIT 1"
            );
            $st->bindValue(
                ':setting_key',
                (string)$key
            );
            $st->execute();

            $value = $st->fetchColumn();

            return $value === false
                ? ''
                : trim((string)$value);
        } catch (Exception $e) {
            return '';
        }
    }

    public static function saveSetting($pdo, $key, $value)
    {
        if (!$pdo) {
            return false;
        }

        try {
            $st = $pdo->prepare(
                "INSERT INTO cpms_approval_settings
                    (
                        setting_key,
                        setting_value,
                        updated_at
                    )
                 VALUES
                    (
                        :setting_key,
                        :setting_value,
                        NOW()
                    )
                 ON DUPLICATE KEY UPDATE
                    setting_value = VALUES(setting_value),
                    updated_at = NOW()"
            );

            $st->execute(
                array(
                    ':setting_key' => (string)$key,
                    ':setting_value' => (string)$value
                )
            );

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    private static function isFirstApproverEmployee($row)
    {
        if (
            !is_array($row) ||
            (int)$row['is_active'] !== 1
        ) {
            return false;
        }

        $name = preg_replace(
            '/\s+/u',
            '',
            trim((string)$row['name'])
        );

        $position = trim(
            (string)$row['position']
        );

        return (
            $name === '박원덕' &&
            strpos($position, '전무') !== false
        );
    }

    private static function isFinalApproverEmployee($row)
    {
        if (
            !is_array($row) ||
            (int)$row['is_active'] !== 1
        ) {
            return false;
        }

        return strpos(
            trim((string)$row['position']),
            '부사장'
        ) !== false;
    }

    public static function autoLinkApprovers($pdo)
    {
        if (!$pdo) {
            return false;
        }

        $firstSetting = self::setting(
            $pdo,
            self::FIRST_APPROVER_SETTING
        );

        if ((int)$firstSetting <= 0) {
            $legacy = self::setting(
                $pdo,
                'approval_construction_pm_employee_id'
            );

            $legacyRow = self::employeeById(
                $pdo,
                (int)$legacy
            );

            if (self::isFirstApproverEmployee($legacyRow)) {
                self::saveSetting(
                    $pdo,
                    self::FIRST_APPROVER_SETTING,
                    (int)$legacyRow['id']
                );
            } else {
                try {
                    $st = $pdo->query(
                        "SELECT
                            id,
                            name,
                            email,
                            department,
                            position,
                            role,
                            is_active
                         FROM employees
                         WHERE is_active = 1
                           AND name = '박원덕'
                           AND position LIKE '%전무%'
                         ORDER BY id ASC"
                    );

                    $rows = $st
                        ? $st->fetchAll(PDO::FETCH_ASSOC)
                        : array();

                    if (
                        count($rows) === 1 &&
                        self::isFirstApproverEmployee($rows[0])
                    ) {
                        self::saveSetting(
                            $pdo,
                            self::FIRST_APPROVER_SETTING,
                            (int)$rows[0]['id']
                        );
                    }
                } catch (Exception $e) {
                }
            }
        }

        $finalSetting = self::setting(
            $pdo,
            self::FINAL_APPROVER_SETTING
        );

        if ((int)$finalSetting <= 0) {
            try {
                $st = $pdo->query(
                    "SELECT
                        id,
                        name,
                        email,
                        department,
                        position,
                        role,
                        is_active
                     FROM employees
                     WHERE is_active = 1
                       AND position LIKE '%부사장%'
                     ORDER BY id ASC"
                );

                $rows = $st
                    ? $st->fetchAll(PDO::FETCH_ASSOC)
                    : array();

                if (
                    count($rows) === 1 &&
                    self::isFinalApproverEmployee($rows[0])
                ) {
                    self::saveSetting(
                        $pdo,
                        self::FINAL_APPROVER_SETTING,
                        (int)$rows[0]['id']
                    );
                }
            } catch (Exception $e) {
            }
        }

        return true;
    }

    public static function configureApprovers(
        $pdo,
        $firstId,
        $finalId
    ) {
        $first = self::employeeById(
            $pdo,
            (int)$firstId
        );

        $final = self::employeeById(
            $pdo,
            (int)$finalId
        );

        if (!self::isFirstApproverEmployee($first)) {
            return array(
                'ok' => false,
                'message' => '1차 승인자는 직원명부의 박원덕 전무 계정만 연결할 수 있습니다.'
            );
        }

        if (!self::isFinalApproverEmployee($final)) {
            return array(
                'ok' => false,
                'message' => '최종 승인자는 직원명부에서 직급이 부사장인 활성 계정만 연결할 수 있습니다.'
            );
        }

        if ((int)$first['id'] === (int)$final['id']) {
            return array(
                'ok' => false,
                'message' => '1차 승인자와 최종 승인자는 서로 달라야 합니다.'
            );
        }

        $ok1 = self::saveSetting(
            $pdo,
            self::FIRST_APPROVER_SETTING,
            (int)$first['id']
        );

        $ok2 = self::saveSetting(
            $pdo,
            self::FINAL_APPROVER_SETTING,
            (int)$final['id']
        );

        return array(
            'ok' => ($ok1 && $ok2),
            'message' => ($ok1 && $ok2)
                ? '고정 승인선을 직원 계정에 연결했습니다.'
                : '승인자 설정 저장에 실패했습니다.'
        );
    }

    public static function resolveApprovers($pdo)
    {
        self::autoLinkApprovers($pdo);

        $first = self::employeeById(
            $pdo,
            (int)self::setting(
                $pdo,
                self::FIRST_APPROVER_SETTING
            )
        );

        $final = self::employeeById(
            $pdo,
            (int)self::setting(
                $pdo,
                self::FINAL_APPROVER_SETTING
            )
        );

        $firstOk = self::isFirstApproverEmployee($first);
        $finalOk = self::isFinalApproverEmployee($final);

        return array(
            'ok' => ($firstOk && $finalOk),
            'first' => $firstOk
                ? $first
                : null,
            'final' => $finalOk
                ? $final
                : null,
            'message' => ($firstOk && $finalOk)
                ? '요청자 → 박원덕 전무 1차 승인 → 부사장 최종 승인'
                : '직원명부 기반 고정 승인자 연결이 필요합니다.'
        );
    }

    public static function canAdmin()
    {
        return Auth::isMaster() ||
            Auth::canManageEmployees();
    }

    public static function canManageProject(
        $pdo,
        $projectId,
        $targetType
    ) {
        $projectId = (int)$projectId;

        if (
            $projectId <= 0 ||
            !Auth::check()
        ) {
            return false;
        }

        if (Auth::isMaster()) {
            return true;
        }

        if ((string)$targetType === 'safety') {
            $helper =
                dirname(__DIR__) .
                '/views/safety/safety_cost_helper.php';

            if (is_file($helper)) {
                require_once $helper;
            }

            if (
                function_exists(
                    'cpms_safety_cost_user_can_manage_project'
                )
            ) {
                return cpms_safety_cost_user_can_manage_project(
                    $pdo,
                    $projectId
                );
            }
        }

        if (!Auth::canManageConstruction()) {
            return false;
        }

        $department = trim(
            (string)Auth::userDepartment()
        );

        if (
            in_array(
                $department,
                array(
                    '공무',
                    '공무부',
                    '공무팀',
                    '관리',
                    '관리부',
                    '관리팀'
                ),
                true
            ) ||
            Auth::userRole() === 'executive'
        ) {
            return true;
        }

        if (
            function_exists(
                'cpms_is_project_member_or_executive'
            )
        ) {
            return cpms_is_project_member_or_executive(
                $pdo,
                $projectId,
                Auth::userRole(),
                Auth::userEmail()
            );
        }

        return false;
    }

    public static function canViewProject(
        $pdo,
        $projectId,
        $targetType
    ) {
        if (self::canAdmin()) {
            return true;
        }

        if ((string)$targetType === 'safety') {
            $helper =
                dirname(__DIR__) .
                '/views/safety/safety_cost_helper.php';

            if (is_file($helper)) {
                require_once $helper;
            }

            if (
                function_exists(
                    'cpms_safety_cost_user_can_view_project'
                )
            ) {
                return cpms_safety_cost_user_can_view_project(
                    $pdo,
                    (int)$projectId
                );
            }
        }

        if (!Auth::canAccessConstruction()) {
            return false;
        }

        $department = trim(
            (string)Auth::userDepartment()
        );

        if (
            in_array(
                $department,
                array(
                    '공무',
                    '공무부',
                    '공무팀',
                    '관리',
                    '관리부',
                    '관리팀'
                ),
                true
            ) ||
            Auth::userRole() === 'executive'
        ) {
            return true;
        }

        if (
            function_exists(
                'cpms_is_project_member_or_executive'
            )
        ) {
            return cpms_is_project_member_or_executive(
                $pdo,
                (int)$projectId,
                Auth::userRole(),
                Auth::userEmail()
            );
        }

        return false;
    }

    public static function projectName($pdo, $projectId)
    {
        if (!$pdo || (int)$projectId <= 0) {
            return '';
        }

        try {
            $st = $pdo->prepare(
                "SELECT name
                 FROM cpms_projects
                 WHERE id = :id
                 LIMIT 1"
            );
            $st->bindValue(
                ':id',
                (int)$projectId,
                PDO::PARAM_INT
            );
            $st->execute();

            $name = $st->fetchColumn();

            return $name === false
                ? ''
                : trim((string)$name);
        } catch (Exception $e) {
            return '';
        }
    }

    public static function targetKey(
        $targetType,
        $targetId
    ) {
        $targetType = preg_replace(
            '/[^A-Za-z0-9_\-]/',
            '',
            trim((string)$targetType)
        );

        $targetId = preg_replace(
            '/[^A-Za-z0-9_\-:]/',
            '',
            trim((string)$targetId)
        );

        if (
            $targetType === '' ||
            $targetId === ''
        ) {
            return '';
        }

        return $targetType . ':' . $targetId;
    }

    public static function meta(
        $pdo,
        $targetType,
        $targetId
    ) {
        $key = self::targetKey(
            $targetType,
            $targetId
        );

        if (
            $key === '' ||
            !$pdo ||
            !self::isInstalled($pdo)
        ) {
            return null;
        }

        if (
            array_key_exists(
                $key,
                self::$metaCache
            )
        ) {
            return self::$metaCache[$key];
        }

        try {
            $st = $pdo->prepare(
                "SELECT *
                 FROM cpms_cost_record_meta
                 WHERE target_type = :target_type
                   AND target_id = :target_id
                 LIMIT 1"
            );

            $st->execute(
                array(
                    ':target_type' => (string)$targetType,
                    ':target_id' => (string)$targetId
                )
            );

            $row = $st->fetch(PDO::FETCH_ASSOC);

            self::$metaCache[$key] = is_array($row)
                ? $row
                : null;
        } catch (Exception $e) {
            self::$metaCache[$key] = null;
        }

        return self::$metaCache[$key];
    }

    public static function effectiveSettlementYm(
        $pdo,
        $targetType,
        $targetId,
        $costType,
        $useDate
    ) {
        $meta = self::meta(
            $pdo,
            $targetType,
            $targetId
        );

        if (
            is_array($meta) &&
            isset($meta['settlement_ym']) &&
            self::validYm(
                $meta['settlement_ym']
            ) !== ''
        ) {
            return self::validYm(
                $meta['settlement_ym']
            );
        }

        return self::settlementYm(
            $costType,
            $useDate
        );
    }

    public static function isTargetDeleted(
        $pdo,
        $targetType,
        $targetId
    ) {
        $meta = self::meta(
            $pdo,
            $targetType,
            $targetId
        );

        return
            is_array($meta) &&
            isset($meta['is_deleted']) &&
            (int)$meta['is_deleted'] === 1;
    }

    public static function loadTarget(
        $pdo,
        $targetType,
        $targetId,
        $projectId
    ) {
        $targetType = trim((string)$targetType);
        $targetId = trim((string)$targetId);
        $projectId = (int)$projectId;

        if (
            !$pdo ||
            $targetType === '' ||
            $targetId === '' ||
            $projectId <= 0
        ) {
            return null;
        }

        $row = null;

        if ($targetType === 'material') {
            $st = $pdo->prepare(
                "SELECT
                    u.*,
                    i.category,
                    i.vendor_name,
                    i.spec,
                    i.base_rate,
                    i.remark AS item_remark
                 FROM cpms_material_usage u
                 INNER JOIN cpms_material_items i
                    ON i.id = u.material_id
                   AND i.project_id = u.project_id
                 WHERE u.id = :id
                   AND u.project_id = :project_id
                 LIMIT 1"
            );

            $st->execute(
                array(
                    ':id' => (int)$targetId,
                    ':project_id' => $projectId
                )
            );

            $native = $st->fetch(PDO::FETCH_ASSOC);

            if ($native) {
                $row = array(
                    'target_type' => 'material',
                    'target_id' => (string)$native['id'],
                    'project_id' => $projectId,
                    'cost_type' => 'material',
                    'category' => isset($native['category'])
                        ? $native['category']
                        : '자재비',
                    'use_date' => $native['use_date'],
                    'vendor_name' => $native['vendor_name'],
                    'item_name' => isset($native['spec'])
                        ? $native['spec']
                        : '',
                    'quantity' => 1,
                    'unit_price' => isset($native['base_rate'])
                        ? (float)$native['base_rate']
                        : (float)$native['amount'],
                    'amount' => (float)$native['amount'],
                    'memo' => isset($native['memo'])
                        ? $native['memo']
                        : '',
                    'master_id' => (int)$native['material_id'],
                    'native' => $native
                );
            }
        } elseif ($targetType === 'equipment') {
            $st = $pdo->prepare(
                "SELECT
                    u.*,
                    i.category,
                    i.vendor_name,
                    i.spec,
                    i.base_rate,
                    i.remark AS item_remark
                 FROM cpms_equipment_usage u
                 INNER JOIN cpms_equipment_items i
                    ON i.id = u.equipment_id
                   AND i.project_id = u.project_id
                 WHERE u.id = :id
                   AND u.project_id = :project_id
                 LIMIT 1"
            );

            $st->execute(
                array(
                    ':id' => (int)$targetId,
                    ':project_id' => $projectId
                )
            );

            $native = $st->fetch(PDO::FETCH_ASSOC);

            if ($native) {
                $quantity = isset($native['work_unit'])
                    ? (float)$native['work_unit']
                    : 1;

                if ($quantity <= 0) {
                    $quantity = 1;
                }

                $row = array(
                    'target_type' => 'equipment',
                    'target_id' => (string)$native['id'],
                    'project_id' => $projectId,
                    'cost_type' => 'equipment',
                    'category' => isset($native['category'])
                        ? $native['category']
                        : '장비비',
                    'use_date' => $native['use_date'],
                    'vendor_name' => $native['vendor_name'],
                    'item_name' => isset($native['spec'])
                        ? $native['spec']
                        : '',
                    'quantity' => $quantity,
                    'unit_price' => isset($native['base_rate_snapshot'])
                        ? (float)$native['base_rate_snapshot']
                        : (float)$native['base_rate'],
                    'amount' => (float)$native['amount'],
                    'memo' => isset($native['memo'])
                        ? $native['memo']
                        : '',
                    'master_id' => (int)$native['equipment_id'],
                    'native' => $native
                );
            }
        } elseif ($targetType === 'outsourcing') {
            $st = $pdo->prepare(
                "SELECT *
                 FROM cpms_outsourcing_costs
                 WHERE id = :id
                   AND project_id = :project_id
                 LIMIT 1"
            );

            $st->execute(
                array(
                    ':id' => (int)$targetId,
                    ':project_id' => $projectId
                )
            );

            $native = $st->fetch(PDO::FETCH_ASSOC);

            if ($native) {
                $row = array(
                    'target_type' => 'outsourcing',
                    'target_id' => (string)$native['id'],
                    'project_id' => $projectId,
                    'cost_type' => 'outsourcing',
                    'category' => '외주비',
                    'use_date' => $native['expense_date'],
                    'vendor_name' => $native['company_name'],
                    'item_name' => isset($native['memo'])
                        ? $native['memo']
                        : '',
                    'quantity' => 1,
                    'unit_price' => (float)$native['amount'],
                    'amount' => (float)$native['amount'],
                    'memo' => isset($native['memo'])
                        ? $native['memo']
                        : '',
                    'native' => $native
                );
            }
        } elseif ($targetType === 'labor_force') {
            $st = $pdo->prepare(
                "SELECT *
                 FROM cpms_labor_force_adjustments
                 WHERE id = :id
                   AND project_id = :project_id
                 LIMIT 1"
            );

            $st->execute(
                array(
                    ':id' => (int)$targetId,
                    ':project_id' => $projectId
                )
            );

            $native = $st->fetch(PDO::FETCH_ASSOC);

            if ($native) {
                $month = isset($native['month'])
                    ? self::validYm($native['month'])
                    : '';

                $row = array(
                    'target_type' => 'labor_force',
                    'target_id' => (string)$native['id'],
                    'project_id' => $projectId,
                    'cost_type' => 'labor',
                    'category' => '노무비',
                    'use_date' => $month !== ''
                        ? $month . '-01'
                        : '',
                    'vendor_name' => '',
                    'item_name' => '노무비 강제입력',
                    'quantity' => 1,
                    'unit_price' => (float)$native['amount'],
                    'amount' => (float)$native['amount'],
                    'memo' => isset($native['memo'])
                        ? $native['memo']
                        : '',
                    'native' => $native
                );
            }
        } elseif ($targetType === 'daily_cost') {
            $st = $pdo->prepare(
                "SELECT *
                 FROM cpms_daily_cost_entries
                 WHERE id = :id
                   AND project_id = :project_id
                 LIMIT 1"
            );

            $st->execute(
                array(
                    ':id' => (int)$targetId,
                    ':project_id' => $projectId
                )
            );

            $native = $st->fetch(PDO::FETCH_ASSOC);

            if ($native) {
                $costType = (
                    isset($native['cost_type']) &&
                    (string)$native['cost_type'] === '노무'
                )
                    ? 'labor'
                    : 'daily_cost';

                $row = array(
                    'target_type' => 'daily_cost',
                    'target_id' => (string)$native['id'],
                    'project_id' => $projectId,
                    'cost_type' => $costType,
                    'category' => isset($native['cost_type'])
                        ? $native['cost_type']
                        : '기타',
                    'use_date' => $native['cost_date'],
                    'vendor_name' => '',
                    'item_name' => isset($native['memo'])
                        ? $native['memo']
                        : '',
                    'quantity' => 1,
                    'unit_price' => (float)$native['amount'],
                    'amount' => (float)$native['amount'],
                    'memo' => isset($native['memo'])
                        ? $native['memo']
                        : '',
                    'native' => $native
                );
            }
        } elseif ($targetType === 'safety') {
            $helper =
                dirname(__DIR__) .
                '/views/safety/safety_cost_helper.php';

            if (is_file($helper)) {
                require_once $helper;
            }

            $native = function_exists(
                'cpms_safety_cost_find_item'
            )
                ? cpms_safety_cost_find_item($targetId)
                : null;

            if (
                is_array($native) &&
                isset($native['project_id']) &&
                (int)$native['project_id'] === $projectId
            ) {
                $row = array(
                    'target_type' => 'safety',
                    'target_id' => (string)$native['id'],
                    'project_id' => $projectId,
                    'cost_type' => 'safety',
                    'category' => isset($native['category'])
                        ? $native['category']
                        : '안전관리비',
                    'use_date' => isset($native['use_date'])
                        ? $native['use_date']
                        : '',
                    'vendor_name' => isset($native['vendor_name'])
                        ? $native['vendor_name']
                        : '',
                    'item_name' => (
                        isset($native['item_name']) &&
                        trim((string)$native['item_name']) !== ''
                    )
                        ? $native['item_name']
                        : (
                            isset($native['use_content'])
                                ? $native['use_content']
                                : ''
                        ),
                    'quantity' => isset($native['quantity'])
                        ? (float)$native['quantity']
                        : 1,
                    'unit_price' => isset($native['unit_price'])
                        ? (float)$native['unit_price']
                        : (
                            function_exists(
                                'cpms_safety_cost_row_amount'
                            )
                                ? (float)cpms_safety_cost_row_amount($native)
                                : 0
                        ),
                    'amount' => function_exists(
                        'cpms_safety_cost_row_amount'
                    )
                        ? (float)cpms_safety_cost_row_amount($native)
                        : 0,
                    'memo' => isset($native['remark'])
                        ? $native['remark']
                        : '',
                    'native' => $native
                );
            }
        }

        if (!is_array($row)) {
            return null;
        }

        $row['project_name'] = self::projectName(
            $pdo,
            $projectId
        );

        $meta = self::meta(
            $pdo,
            $targetType,
            $targetId
        );

        if (is_array($meta)) {
            if (
                isset($meta['quantity']) &&
                $meta['quantity'] !== null
            ) {
                $row['quantity'] =
                    (float)$meta['quantity'];
            }

            if (
                isset($meta['unit_price']) &&
                $meta['unit_price'] !== null
            ) {
                $row['unit_price'] =
                    (float)$meta['unit_price'];
            }

            if (
                isset($meta['vendor_name_snapshot']) &&
                trim(
                    (string)$meta['vendor_name_snapshot']
                ) !== ''
            ) {
                $row['vendor_name'] =
                    (string)$meta['vendor_name_snapshot'];
            }

            if (
                isset($meta['item_name_snapshot']) &&
                trim(
                    (string)$meta['item_name_snapshot']
                ) !== ''
            ) {
                $row['item_name'] =
                    (string)$meta['item_name_snapshot'];
            }

            $row['manual_settlement_yn'] =
                isset($meta['manual_settlement_yn'])
                    ? (int)$meta['manual_settlement_yn']
                    : 0;

            $row['manual_reason'] =
                isset($meta['manual_reason'])
                    ? (string)$meta['manual_reason']
                    : '';

            $row['is_deleted'] =
                isset($meta['is_deleted'])
                    ? (int)$meta['is_deleted']
                    : 0;
        } else {
            $row['manual_settlement_yn'] = 0;
            $row['manual_reason'] = '';
            $row['is_deleted'] = 0;
        }

        $row['settlement_ym'] =
            self::effectiveSettlementYm(
                $pdo,
                $targetType,
                $targetId,
                $row['cost_type'],
                $row['use_date']
            );

        return $row;
    }

    public static function normalizeRequestedData(
        $input,
        $target
    ) {
        $requestType = isset($input['request_type'])
            ? strtoupper(
                trim(
                    (string)$input['request_type']
                )
            )
            : '';

        $targetType = isset($input['target_type'])
            ? trim(
                (string)$input['target_type']
            )
            : '';

        $costType = isset($input['cost_type'])
            ? trim(
                (string)$input['cost_type']
            )
            : $targetType;

        if (
            is_array($target) &&
            isset($target['cost_type'])
        ) {
            $costType =
                (string)$target['cost_type'];
        }

        if ($targetType === 'labor_force') {
            $costType = 'labor';
        }

        $data = is_array($target)
            ? $target
            : array();

        unset($data['native']);

        $data['target_type'] = $targetType;
        $data['cost_type'] = $costType;

        $data['project_id'] =
            isset($input['project_id'])
                ? (int)$input['project_id']
                : (
                    isset($data['project_id'])
                        ? (int)$data['project_id']
                        : 0
                );

        $data['category'] =
            isset($input['category'])
                ? trim(
                    (string)$input['category']
                )
                : (
                    isset($data['category'])
                        ? (string)$data['category']
                        : self::costTypeLabel($costType)
                );

        $data['use_date'] = self::validDate(
            isset($input['use_date'])
                ? $input['use_date']
                : (
                    isset($data['use_date'])
                        ? $data['use_date']
                        : ''
                )
        );

        $data['vendor_name'] =
            isset($input['vendor_name'])
                ? trim(
                    (string)$input['vendor_name']
                )
                : (
                    isset($data['vendor_name'])
                        ? (string)$data['vendor_name']
                        : ''
                );

        $data['item_name'] =
            isset($input['item_name'])
                ? trim(
                    (string)$input['item_name']
                )
                : (
                    isset($data['item_name'])
                        ? (string)$data['item_name']
                        : ''
                );

        $data['quantity'] =
            isset($input['quantity']) &&
            is_numeric(
                str_replace(
                    ',',
                    '',
                    (string)$input['quantity']
                )
            )
                ? (float)str_replace(
                    ',',
                    '',
                    (string)$input['quantity']
                )
                : (
                    isset($data['quantity'])
                        ? (float)$data['quantity']
                        : 1
                );

        $data['unit_price'] = self::money(
            isset($input['unit_price'])
                ? $input['unit_price']
                : (
                    isset($data['unit_price'])
                        ? $data['unit_price']
                        : 0
                )
        );

        $data['amount'] = self::money(
            isset($input['amount'])
                ? $input['amount']
                : (
                    isset($data['amount'])
                        ? $data['amount']
                        : 0
                )
        );

        $data['memo'] =
            isset($input['memo'])
                ? trim(
                    (string)$input['memo']
                )
                : (
                    isset($data['memo'])
                        ? (string)$data['memo']
                        : ''
                );

        $data['master_id'] =
            isset($input['master_id'])
                ? (int)$input['master_id']
                : (
                    isset($data['master_id'])
                        ? (int)$data['master_id']
                        : 0
                );

        $autoYm = self::settlementYm(
            $costType,
            $data['use_date']
        );

        $manualYn = (
            $requestType === self::REQUEST_MONTH_MOVE
        )
            ? 1
            : 0;

        $requestedYm = self::validYm(
            isset($input['new_settlement_ym'])
                ? $input['new_settlement_ym']
                : ''
        );

        $targetUseDate =
            is_array($target) &&
            isset($target['use_date'])
                ? self::validDate(
                    $target['use_date']
                )
                : '';

        $targetYm =
            is_array($target) &&
            isset($target['settlement_ym'])
                ? self::validYm(
                    $target['settlement_ym']
                )
                : '';

        $targetManual =
            is_array($target) &&
            isset($target['manual_settlement_yn'])
                ? (int)$target['manual_settlement_yn']
                : 0;

        if (
            $manualYn &&
            $requestedYm !== ''
        ) {
            $data['settlement_ym'] = $requestedYm;
        } elseif (
            $targetManual === 1 &&
            $targetUseDate !== '' &&
            $targetUseDate === $data['use_date'] &&
            $targetYm !== ''
        ) {
            $data['settlement_ym'] = $targetYm;
            $manualYn = 1;
        } else {
            $data['settlement_ym'] = $autoYm;
        }

        $data['manual_settlement_yn'] =
            $manualYn;

        return $data;
    }

    public static function money($value)
    {
        $raw = preg_replace(
            '/[^0-9.\-]/',
            '',
            trim((string)$value)
        );

        if (
            $raw === '' ||
            $raw === '-' ||
            $raw === '.' ||
            !is_numeric($raw)
        ) {
            return 0.0;
        }

        return (float)$raw;
    }

    public static function activeRequest(
        $pdo,
        $targetType,
        $targetId
    ) {
        if (
            !$pdo ||
            !self::isInstalled($pdo)
        ) {
            return null;
        }

        $key = self::targetKey(
            $targetType,
            $targetId
        );

        if ($key === '') {
            return null;
        }

        try {
            $st = $pdo->prepare(
                "SELECT *
                 FROM cpms_cost_change_requests
                 WHERE active_target_key = :active_key
                 LIMIT 1"
            );
            $st->bindValue(
                ':active_key',
                $key
            );
            $st->execute();

            $row = $st->fetch(PDO::FETCH_ASSOC);

            return is_array($row)
                ? $row
                : null;
        } catch (Exception $e) {
            return null;
        }
    }

    public static function logEvent(
        $pdo,
        $requestId,
        $eventType,
        $stage,
        $note,
        $data
    ) {
        if (
            !$pdo ||
            (int)$requestId <= 0
        ) {
            return false;
        }

        $actorId = self::employeeId();

        try {
            $st = $pdo->prepare(
                "INSERT INTO cpms_cost_change_logs
                    (
                        request_id,
                        event_type,
                        stage,
                        actor_employee_id,
                        actor_name,
                        actor_email,
                        event_note,
                        event_data,
                        created_at
                    )
                 VALUES
                    (
                        :request_id,
                        :event_type,
                        :stage,
                        :actor_id,
                        :actor_name,
                        :actor_email,
                        :event_note,
                        :event_data,
                        NOW()
                    )"
            );

            $st->execute(
                array(
                    ':request_id' => (int)$requestId,
                    ':event_type' => (string)$eventType,
                    ':stage' => (string)$stage,
                    ':actor_id' => $actorId > 0
                        ? $actorId
                        : null,
                    ':actor_name' => (string)Auth::userName(),
                    ':actor_email' => (string)Auth::userEmail(),
                    ':event_note' => (string)$note,
                    ':event_data' => self::jsonEncode(
                        is_array($data)
                            ? $data
                            : array()
                    )
                )
            );

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public static function requestById(
        $pdo,
        $requestId
    ) {
        if (
            !$pdo ||
            (int)$requestId <= 0 ||
            !self::isInstalled($pdo)
        ) {
            return null;
        }

        try {
            $st = $pdo->prepare(
                "SELECT *
                 FROM cpms_cost_change_requests
                 WHERE id = :id
                 LIMIT 1"
            );
            $st->bindValue(
                ':id',
                (int)$requestId,
                PDO::PARAM_INT
            );
            $st->execute();

            $row = $st->fetch(PDO::FETCH_ASSOC);

            return is_array($row)
                ? $row
                : null;
        } catch (Exception $e) {
            return null;
        }
    }

    public static function isRequester($request)
    {
        if (!is_array($request)) {
            return false;
        }

        $employeeId = self::employeeId();

        if (
            $employeeId > 0 &&
            isset($request['requester_employee_id']) &&
            $employeeId ===
                (int)$request['requester_employee_id']
        ) {
            return true;
        }

        $email = strtolower(
            trim(
                (string)Auth::userEmail()
            )
        );

        return
            $email !== '' &&
            isset($request['requester_email']) &&
            $email === strtolower(
                trim(
                    (string)$request['requester_email']
                )
            );
    }

    public static function canViewRequest(
        $pdo,
        $request
    ) {
        if (
            !is_array($request) ||
            !Auth::check()
        ) {
            return false;
        }

        if (
            self::isRequester($request) ||
            self::canAdmin()
        ) {
            return true;
        }

        $employeeId = self::employeeId();

        if (
            $employeeId > 0 &&
            (
                $employeeId ===
                    (int)$request['first_approver_employee_id'] ||
                $employeeId ===
                    (int)$request['final_approver_employee_id']
            )
        ) {
            return true;
        }

        return false;
    }

    public static function canActRequest($request)
    {
        if (
            !is_array($request) ||
            !Auth::check()
        ) {
            return false;
        }

        $employeeId = self::employeeId();

        if ($employeeId <= 0) {
            return false;
        }

        if (
            (string)$request['status'] ===
            self::STATUS_FIRST_PENDING
        ) {
            return
                $employeeId ===
                    (int)$request['first_approver_employee_id'] &&
                $employeeId ===
                    (int)$request['current_approver_employee_id'];
        }

        if (
            (string)$request['status'] ===
            self::STATUS_FINAL_PENDING
        ) {
            return
                $employeeId ===
                    (int)$request['final_approver_employee_id'] &&
                $employeeId ===
                    (int)$request['current_approver_employee_id'];
        }

        return false;
    }

    public static function uploadRows($fieldName)
    {
        $rows = array();

        if (
            !isset($_FILES[$fieldName]) ||
            !is_array($_FILES[$fieldName])
        ) {
            return $rows;
        }

        $file = $_FILES[$fieldName];

        if (
            isset($file['name']) &&
            is_array($file['name'])
        ) {
            $count = count($file['name']);

            for ($i = 0; $i < $count; $i++) {
                $rows[] = array(
                    'name' => isset($file['name'][$i])
                        ? $file['name'][$i]
                        : '',
                    'type' => isset($file['type'][$i])
                        ? $file['type'][$i]
                        : '',
                    'tmp_name' => isset($file['tmp_name'][$i])
                        ? $file['tmp_name'][$i]
                        : '',
                    'error' => isset($file['error'][$i])
                        ? $file['error'][$i]
                        : UPLOAD_ERR_NO_FILE,
                    'size' => isset($file['size'][$i])
                        ? $file['size'][$i]
                        : 0
                );
            }
        } else {
            $rows[] = $file;
        }

        return $rows;
    }

    public static function allowedExtensions()
    {
        return array(
            'pdf' => true,
            'xls' => true,
            'xlsx' => true,
            'xlsm' => true,
            'csv' => true,
            'jpg' => true,
            'jpeg' => true,
            'png' => true,
            'gif' => true,
            'webp' => true,
            'heic' => true,
            'heif' => true,
            'hwp' => true,
            'hwpx' => true,
            'doc' => true,
            'docx' => true,
            'ppt' => true,
            'pptx' => true,
            'txt' => true
        );
    }

    public static function detectMime($path)
    {
        $mime = '';

        if (
            $path === '' ||
            !is_file($path)
        ) {
            return $mime;
        }

        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(
                FILEINFO_MIME_TYPE
            );

            if ($finfo) {
                $mime = (string)@finfo_file(
                    $finfo,
                    $path
                );

                @finfo_close($finfo);
            }
        }

        if (
            $mime === '' &&
            function_exists('mime_content_type')
        ) {
            $mime =
                (string)@mime_content_type($path);
        }

        if (strpos($mime, ';') !== false) {
            $parts = explode(';', $mime, 2);
            $mime = $parts[0];
        }

        return strtolower(
            trim($mime)
        );
    }

    public static function validateUploads($fieldName)
    {
        $valid = array();
        $rows = self::uploadRows($fieldName);
        $allowed = self::allowedExtensions();

        $dangerMimes = array(
            'application/x-httpd-php' => true,
            'application/x-php' => true,
            'application/x-msdownload' => true,
            'application/x-dosexec' => true,
            'application/x-sh' => true,
            'application/x-shellscript' => true
        );

        if (count($rows) > 20) {
            throw new Exception(
                '증빙자료는 한 요청에 최대 20개까지 첨부할 수 있습니다.'
            );
        }

        foreach ($rows as $row) {
            $error = isset($row['error'])
                ? (int)$row['error']
                : UPLOAD_ERR_NO_FILE;

            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ($error !== UPLOAD_ERR_OK) {
                throw new Exception(
                    '첨부파일 업로드 중 오류가 발생했습니다.'
                );
            }

            $original = basename(
                str_replace(
                    '\\',
                    '/',
                    isset($row['name'])
                        ? (string)$row['name']
                        : ''
                )
            );

            if (
                $original === '' ||
                strlen($original) > 255
            ) {
                throw new Exception(
                    '첨부파일명이 올바르지 않습니다.'
                );
            }

            $extension = strtolower(
                pathinfo(
                    $original,
                    PATHINFO_EXTENSION
                )
            );

            if (
                $extension === '' ||
                !isset($allowed[$extension])
            ) {
                throw new Exception(
                    '허용되지 않은 첨부파일 형식입니다.'
                );
            }

            $size = isset($row['size'])
                ? (int)$row['size']
                : 0;

            if (
                $size <= 0 ||
                $size > 20 * 1024 * 1024
            ) {
                throw new Exception(
                    '첨부파일은 파일당 20MB 이하만 업로드할 수 있습니다.'
                );
            }

            $tmp = isset($row['tmp_name'])
                ? (string)$row['tmp_name']
                : '';

            if (
                $tmp === '' ||
                !is_file($tmp) ||
                !is_uploaded_file($tmp)
            ) {
                throw new Exception(
                    '정상적인 업로드 파일이 아닙니다.'
                );
            }

            $mime = self::detectMime($tmp);

            if (
                $mime !== '' &&
                (
                    isset($dangerMimes[$mime]) ||
                    strpos($mime, 'php') !== false ||
                    strpos($mime, 'executable') !== false ||
                    strpos($mime, 'shell') !== false ||
                    strpos($mime, 'dosexec') !== false
                )
            ) {
                throw new Exception(
                    '실행 가능한 위험 파일은 업로드할 수 없습니다.'
                );
            }

            $valid[] = array(
                'original_name' => $original,
                'extension' => $extension,
                'size' => $size,
                'tmp_name' => $tmp,
                'mime_type' => $mime
            );
        }

        return $valid;
    }

    public static function storeUploads(
        $pdo,
        $requestId,
        $requestNo,
        $validRows
    ) {
        $savedPaths = array();

        if (
            !is_array($validRows) ||
            count($validRows) === 0
        ) {
            return $savedPaths;
        }

        $dir =
            cpms_storage_root() .
            '/cost_changes/' .
            preg_replace(
                '/[^A-Za-z0-9_\-]/',
                '',
                (string)$requestNo
            );

        if (
            !is_dir($dir) &&
            !@mkdir($dir, 0775, true) &&
            !is_dir($dir)
        ) {
            throw new Exception(
                '첨부파일 저장 폴더를 만들지 못했습니다.'
            );
        }

        $st = $pdo->prepare(
            "INSERT INTO cpms_cost_change_files
                (
                    request_id,
                    source_request_id,
                    file_group,
                    original_name,
                    stored_name,
                    stored_path,
                    extension,
                    mime_type,
                    file_size,
                    uploaded_by,
                    uploaded_by_name,
                    uploaded_at,
                    is_deleted
                )
             VALUES
                (
                    :request_id,
                    NULL,
                    'NEW',
                    :original_name,
                    :stored_name,
                    :stored_path,
                    :extension,
                    :mime_type,
                    :file_size,
                    :uploaded_by,
                    :uploaded_by_name,
                    NOW(),
                    0
                )"
        );

        foreach ($validRows as $row) {
            $stored =
                'evidence_' .
                date('Ymd_His') .
                '_' .
                substr(
                    sha1(
                        uniqid(
                            (string)mt_rand(),
                            true
                        )
                    ),
                    0,
                    14
                ) .
                '.' .
                $row['extension'];

            $dest =
                rtrim($dir, '/\\') .
                DIRECTORY_SEPARATOR .
                $stored;

            if (
                !@move_uploaded_file(
                    $row['tmp_name'],
                    $dest
                )
            ) {
                throw new Exception(
                    '첨부파일 저장에 실패했습니다.'
                );
            }

            $relative =
                'cost_changes/' .
                preg_replace(
                    '/[^A-Za-z0-9_\-]/',
                    '',
                    (string)$requestNo
                ) .
                '/' .
                $stored;

            try {
                $st->execute(
                    array(
                        ':request_id' => (int)$requestId,
                        ':original_name' => $row['original_name'],
                        ':stored_name' => $stored,
                        ':stored_path' => $relative,
                        ':extension' => $row['extension'],
                        ':mime_type' => $row['mime_type'],
                        ':file_size' => (int)$row['size'],
                        ':uploaded_by' => self::employeeId() > 0
                            ? self::employeeId()
                            : null,
                        ':uploaded_by_name' => (string)Auth::userName()
                    )
                );

                $savedPaths[] = $dest;
            } catch (Exception $e) {
                @unlink($dest);
                throw $e;
            }
        }

        return $savedPaths;
    }

    public static function inheritFiles(
        $pdo,
        $oldRequestId,
        $newRequestId,
        $fileIds
    ) {
        if (
            !$pdo ||
            (int)$oldRequestId <= 0 ||
            (int)$newRequestId <= 0
        ) {
            return 0;
        }

        $ids = array();

        if (is_array($fileIds)) {
            foreach ($fileIds as $fileId) {
                $fileId = (int)$fileId;

                if ($fileId > 0) {
                    $ids[$fileId] = $fileId;
                }
            }
        }

        if (count($ids) === 0) {
            return 0;
        }

        $placeholders = array();

        $params = array(
            ':new_request_id' => (int)$newRequestId,
            ':old_request_id' => (int)$oldRequestId
        );

        $index = 0;

        foreach ($ids as $fileId) {
            $key = ':file_id_' . $index;
            $placeholders[] = $key;
            $params[$key] = $fileId;
            $index++;
        }

        $sql =
            "INSERT INTO cpms_cost_change_files
                (
                    request_id,
                    source_request_id,
                    file_group,
                    original_name,
                    stored_name,
                    stored_path,
                    extension,
                    mime_type,
                    file_size,
                    uploaded_by,
                    uploaded_by_name,
                    uploaded_at,
                    is_deleted
                )
             SELECT
                :new_request_id,
                request_id,
                'INHERITED',
                original_name,
                stored_name,
                stored_path,
                extension,
                mime_type,
                file_size,
                uploaded_by,
                uploaded_by_name,
                NOW(),
                0
             FROM cpms_cost_change_files
             WHERE request_id = :old_request_id
               AND is_deleted = 0
               AND id IN (" .
            implode(',', $placeholders) .
            ")";

        $st = $pdo->prepare($sql);
        $st->execute($params);

        return (int)$st->rowCount();
    }

    public static function files($pdo, $requestId)
    {
        if (
            !$pdo ||
            (int)$requestId <= 0
        ) {
            return array();
        }

        try {
            $st = $pdo->prepare(
                "SELECT *
                 FROM cpms_cost_change_files
                 WHERE request_id = :request_id
                   AND is_deleted = 0
                 ORDER BY id ASC"
            );
            $st->bindValue(
                ':request_id',
                (int)$requestId,
                PDO::PARAM_INT
            );
            $st->execute();

            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            return is_array($rows)
                ? $rows
                : array();
        } catch (Exception $e) {
            return array();
        }
    }

    public static function logs($pdo, $requestId)
    {
        if (
            !$pdo ||
            (int)$requestId <= 0
        ) {
            return array();
        }

        try {
            $st = $pdo->prepare(
                "SELECT *
                 FROM cpms_cost_change_logs
                 WHERE request_id = :request_id
                 ORDER BY id ASC"
            );
            $st->bindValue(
                ':request_id',
                (int)$requestId,
                PDO::PARAM_INT
            );
            $st->execute();

            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            return is_array($rows)
                ? $rows
                : array();
        } catch (Exception $e) {
            return array();
        }
    }

    public static function resolveFilePath($storedPath)
    {
        $storedPath = ltrim(
            str_replace(
                '\\',
                '/',
                trim((string)$storedPath)
            ),
            '/'
        );

        if (
            $storedPath === '' ||
            strpos($storedPath, '..') !== false ||
            strpos($storedPath, 'cost_changes/') !== 0
        ) {
            return '';
        }

        $root = realpath(
            cpms_storage_root()
        );

        $candidate = realpath(
            cpms_storage_root() .
            '/' .
            $storedPath
        );

        if (
            $root === false ||
            $candidate === false ||
            !is_file($candidate)
        ) {
            return '';
        }

        $rootNorm =
            rtrim(
                str_replace('\\', '/', $root),
                '/'
            ) .
            '/';

        $candidateNorm =
            str_replace('\\', '/', $candidate);

        if (
            strpos(
                $candidateNorm,
                $rootNorm
            ) !== 0
        ) {
            return '';
        }

        return $candidate;
    }

    public static function notify(
        $pdo,
        $request,
        $eventType,
        $receiverId
    ) {
        if (
            !$pdo ||
            !is_array($request) ||
            (int)$receiverId <= 0
        ) {
            return false;
        }

        if (
            !function_exists(
                'cpms_send_google_chat_to_employee'
            )
        ) {
            return false;
        }

        $requestId = isset($request['id'])
            ? (int)$request['id']
            : 0;

        $lines = array(
            '[CPMS 비용 변경 ' .
                self::statusLabel(
                    isset($request['status'])
                        ? $request['status']
                        : ''
                ) .
                ']',
            '',
            '요청번호 : ' .
                (
                    isset($request['request_no'])
                        ? $request['request_no']
                        : ''
                ),
            '현장 : ' .
                (
                    isset($request['project_name'])
                        ? $request['project_name']
                        : ''
                ),
            '비용 : ' .
                self::costTypeLabel(
                    isset($request['cost_type'])
                        ? $request['cost_type']
                        : ''
                ),
            '요청종류 : ' .
                self::requestTypeLabel(
                    isset($request['request_type'])
                        ? $request['request_type']
                        : ''
                ),
            '요청자 : ' .
                (
                    isset($request['requester_name'])
                        ? $request['requester_name']
                        : ''
                ),
            'URL : ' .
                cpms_app_route_url(
                    $pdo,
                    'cost_change/detail',
                    array('id' => $requestId),
                    (int)$receiverId
                )
        );

        return cpms_send_google_chat_to_employee(
            $pdo,
            (int)$receiverId,
            implode("\n", $lines),
            $requestId,
            (string)$eventType,
            'COST_CHANGE'
        );
    }

    private static function ensureMaterialMaster(
        $pdo,
        $projectId,
        $data
    ) {
        $masterId = isset($data['master_id'])
            ? (int)$data['master_id']
            : 0;

        if ($masterId > 0) {
            $st = $pdo->prepare(
                "SELECT id
                 FROM cpms_material_items
                 WHERE id = :id
                   AND project_id = :project_id
                   AND is_deleted = 0
                 LIMIT 1"
            );

            $st->execute(
                array(
                    ':id' => $masterId,
                    ':project_id' => (int)$projectId
                )
            );

            if ($st->fetchColumn()) {
                return $masterId;
            }
        }

        $vendor = trim(
            (string)$data['vendor_name']
        );

        $spec = trim(
            (string)$data['item_name']
        );

        $category = trim(
            (string)$data['category']
        );

        $st = $pdo->prepare(
            "SELECT id
             FROM cpms_material_items
             WHERE project_id = :project_id
               AND is_deleted = 0
               AND vendor_name = :vendor_name
               AND spec = :spec
               AND category = :category
             ORDER BY id ASC
             LIMIT 1"
        );

        $st->execute(
            array(
                ':project_id' => (int)$projectId,
                ':vendor_name' => $vendor,
                ':spec' => $spec,
                ':category' => $category
            )
        );

        $found = (int)$st->fetchColumn();

        if ($found > 0) {
            return $found;
        }

        $now = date('Y-m-d H:i:s');

        $st = $pdo->prepare(
            "INSERT INTO cpms_material_items
                (
                    project_id,
                    category,
                    vendor_name,
                    spec,
                    representative,
                    phone,
                    biz_no,
                    base_rate,
                    remark,
                    is_deleted,
                    created_at,
                    updated_at
                )
             VALUES
                (
                    :project_id,
                    :category,
                    :vendor_name,
                    :spec,
                    '',
                    '',
                    '',
                    :base_rate,
                    '',
                    0,
                    :created_at,
                    :updated_at
                )"
        );

        $st->execute(
            array(
                ':project_id' => (int)$projectId,
                ':category' => $category !== ''
                    ? $category
                    : '자재비',
                ':vendor_name' => $vendor,
                ':spec' => $spec,
                ':base_rate' => (float)$data['unit_price'],
                ':created_at' => $now,
                ':updated_at' => $now
            )
        );

        return (int)$pdo->lastInsertId();
    }

    private static function ensureEquipmentMaster(
        $pdo,
        $projectId,
        $data
    ) {
        $masterId = isset($data['master_id'])
            ? (int)$data['master_id']
            : 0;

        if ($masterId > 0) {
            $st = $pdo->prepare(
                "SELECT id
                 FROM cpms_equipment_items
                 WHERE id = :id
                   AND project_id = :project_id
                   AND is_deleted = 0
                 LIMIT 1"
            );

            $st->execute(
                array(
                    ':id' => $masterId,
                    ':project_id' => (int)$projectId
                )
            );

            if ($st->fetchColumn()) {
                return $masterId;
            }
        }

        $vendor = trim(
            (string)$data['vendor_name']
        );

        $spec = trim(
            (string)$data['item_name']
        );

        $category = trim(
            (string)$data['category']
        );

        $st = $pdo->prepare(
            "SELECT id
             FROM cpms_equipment_items
             WHERE project_id = :project_id
               AND is_deleted = 0
               AND vendor_name = :vendor_name
               AND spec = :spec
               AND category = :category
             ORDER BY id ASC
             LIMIT 1"
        );

        $st->execute(
            array(
                ':project_id' => (int)$projectId,
                ':vendor_name' => $vendor,
                ':spec' => $spec,
                ':category' => $category
            )
        );

        $found = (int)$st->fetchColumn();

        if ($found > 0) {
            return $found;
        }

        $now = date('Y-m-d H:i:s');

        $st = $pdo->prepare(
            "INSERT INTO cpms_equipment_items
                (
                    project_id,
                    category,
                    vendor_name,
                    spec,
                    representative,
                    phone,
                    biz_no,
                    base_rate,
                    remark,
                    is_deleted,
                    created_at,
                    updated_at
                )
             VALUES
                (
                    :project_id,
                    :category,
                    :vendor_name,
                    :spec,
                    '',
                    '',
                    '',
                    :base_rate,
                    '',
                    0,
                    :created_at,
                    :updated_at
                )"
        );

        $st->execute(
            array(
                ':project_id' => (int)$projectId,
                ':category' => $category !== ''
                    ? $category
                    : '장비비',
                ':vendor_name' => $vendor,
                ':spec' => $spec,
                ':base_rate' => (float)$data['unit_price'],
                ':created_at' => $now,
                ':updated_at' => $now
            )
        );

        return (int)$pdo->lastInsertId();
    }

    private static function saveMeta(
        $pdo,
        $targetType,
        $targetId,
        $projectId,
        $requestId,
        $data,
        $deleted,
        $reason
    ) {
        $st = $pdo->prepare(
            "INSERT INTO cpms_cost_record_meta
                (
                    target_type,
                    target_id,
                    project_id,
                    actual_use_date,
                    settlement_ym,
                    manual_settlement_yn,
                    manual_reason,
                    quantity,
                    unit_price,
                    vendor_name_snapshot,
                    item_name_snapshot,
                    is_deleted,
                    last_request_id,
                    applied_data,
                    created_at,
                    updated_at
                )
             VALUES
                (
                    :target_type,
                    :target_id,
                    :project_id,
                    :actual_use_date,
                    :settlement_ym,
                    :manual_yn,
                    :manual_reason,
                    :quantity,
                    :unit_price,
                    :vendor_name,
                    :item_name,
                    :is_deleted,
                    :request_id,
                    :applied_data,
                    NOW(),
                    NOW()
                )
             ON DUPLICATE KEY UPDATE
                project_id = VALUES(project_id),
                actual_use_date = VALUES(actual_use_date),
                settlement_ym = VALUES(settlement_ym),
                manual_settlement_yn = VALUES(manual_settlement_yn),
                manual_reason = VALUES(manual_reason),
                quantity = VALUES(quantity),
                unit_price = VALUES(unit_price),
                vendor_name_snapshot = VALUES(vendor_name_snapshot),
                item_name_snapshot = VALUES(item_name_snapshot),
                is_deleted = VALUES(is_deleted),
                last_request_id = VALUES(last_request_id),
                applied_data = VALUES(applied_data),
                updated_at = NOW()"
        );

        $st->execute(
            array(
                ':target_type' => (string)$targetType,
                ':target_id' => (string)$targetId,
                ':project_id' => (int)$projectId,
                ':actual_use_date' => (
                    isset($data['use_date']) &&
                    self::validDate($data['use_date']) !== ''
                )
                    ? self::validDate($data['use_date'])
                    : null,
                ':settlement_ym' => isset($data['settlement_ym'])
                    ? self::validYm($data['settlement_ym'])
                    : null,
                ':manual_yn' => isset($data['manual_settlement_yn'])
                    ? (int)$data['manual_settlement_yn']
                    : 0,
                ':manual_reason' => isset($data['manual_reason'])
                    ? (string)$data['manual_reason']
                    : (string)$reason,
                ':quantity' => isset($data['quantity'])
                    ? (float)$data['quantity']
                    : null,
                ':unit_price' => isset($data['unit_price'])
                    ? (float)$data['unit_price']
                    : null,
                ':vendor_name' => isset($data['vendor_name'])
                    ? (string)$data['vendor_name']
                    : null,
                ':item_name' => isset($data['item_name'])
                    ? (string)$data['item_name']
                    : null,
                ':is_deleted' => $deleted
                    ? 1
                    : 0,
                ':request_id' => (int)$requestId,
                ':applied_data' => self::jsonEncode($data)
            )
        );

        self::$metaCache = array();
    }

    public static function applyRequest(
        $pdo,
        $request
    ) {
        if (
            !$pdo ||
            !is_array($request)
        ) {
            throw new Exception(
                '자동 반영 대상이 올바르지 않습니다.'
            );
        }

        $requestId = (int)$request['id'];
        $projectId = (int)$request['project_id'];
        $targetType = (string)$request['target_type'];
        $targetId = trim((string)$request['target_id']);
        $requestType = (string)$request['request_type'];

        $data = self::jsonDecode(
            $request['requested_data']
        );

        $reason = (string)$request['reason'];
        $deleted = (
            $requestType === self::REQUEST_DELETE
        );

        if (
            $requestType ===
            self::REQUEST_MONTH_MOVE
        ) {
            $data['manual_reason'] = $reason;
        }

        if ($targetType === 'material') {
            if (
                $requestType ===
                self::REQUEST_ADD
            ) {
                $masterId =
                    self::ensureMaterialMaster(
                        $pdo,
                        $projectId,
                        $data
                    );

                $st = $pdo->prepare(
                    "INSERT INTO cpms_material_usage
                        (
                            project_id,
                            material_id,
                            use_date,
                            amount,
                            advance_yn,
                            memo,
                            created_at
                        )
                     VALUES
                        (
                            :project_id,
                            :master_id,
                            :use_date,
                            :amount,
                            'N',
                            :memo,
                            NOW()
                        )"
                );

                $st->execute(
                    array(
                        ':project_id' => $projectId,
                        ':master_id' => $masterId,
                        ':use_date' => $data['use_date'],
                        ':amount' => $data['amount'],
                        ':memo' => $data['memo']
                    )
                );

                $targetId =
                    (string)$pdo->lastInsertId();

                $data['master_id'] = $masterId;
            } else {
                $st = $pdo->prepare(
                    "SELECT *
                     FROM cpms_material_usage
                     WHERE id = :id
                       AND project_id = :project_id
                     FOR UPDATE"
                );

                $st->execute(
                    array(
                        ':id' => (int)$targetId,
                        ':project_id' => $projectId
                    )
                );

                if (!$st->fetch(PDO::FETCH_ASSOC)) {
                    throw new Exception(
                        '자재구입비 원본자료를 찾을 수 없습니다.'
                    );
                }

                if ($deleted) {
                    $st = $pdo->prepare(
                        "UPDATE cpms_material_usage
                         SET amount = 0
                         WHERE id = :id
                           AND project_id = :project_id"
                    );

                    $st->execute(
                        array(
                            ':id' => (int)$targetId,
                            ':project_id' => $projectId
                        )
                    );
                } elseif (
                    $requestType !==
                    self::REQUEST_MONTH_MOVE
                ) {
                    $masterId =
                        self::ensureMaterialMaster(
                            $pdo,
                            $projectId,
                            $data
                        );

                    $st = $pdo->prepare(
                        "UPDATE cpms_material_usage
                         SET
                            material_id = :master_id,
                            use_date = :use_date,
                            amount = :amount,
                            memo = :memo
                         WHERE id = :id
                           AND project_id = :project_id"
                    );

                    $st->execute(
                        array(
                            ':master_id' => $masterId,
                            ':use_date' => $data['use_date'],
                            ':amount' => $data['amount'],
                            ':memo' => $data['memo'],
                            ':id' => (int)$targetId,
                            ':project_id' => $projectId
                        )
                    );

                    $data['master_id'] = $masterId;
                }
            }
        } elseif ($targetType === 'equipment') {
            if (
                $requestType ===
                self::REQUEST_ADD
            ) {
                $masterId =
                    self::ensureEquipmentMaster(
                        $pdo,
                        $projectId,
                        $data
                    );

                $quantity = (
                    isset($data['quantity']) &&
                    (float)$data['quantity'] > 0
                )
                    ? (float)$data['quantity']
                    : 1;

                $st = $pdo->prepare(
                    "INSERT INTO cpms_equipment_usage
                        (
                            project_id,
                            equipment_id,
                            use_date,
                            work_unit,
                            base_rate_snapshot,
                            amount,
                            is_manual_unit,
                            memo,
                            created_at
                        )
                     VALUES
                        (
                            :project_id,
                            :master_id,
                            :use_date,
                            :quantity,
                            :unit_price,
                            :amount,
                            1,
                            :memo,
                            NOW()
                        )"
                );

                $st->execute(
                    array(
                        ':project_id' => $projectId,
                        ':master_id' => $masterId,
                        ':use_date' => $data['use_date'],
                        ':quantity' => $quantity,
                        ':unit_price' => $data['unit_price'],
                        ':amount' => $data['amount'],
                        ':memo' => $data['memo']
                    )
                );

                $targetId =
                    (string)$pdo->lastInsertId();

                $data['master_id'] = $masterId;
            } else {
                $st = $pdo->prepare(
                    "SELECT *
                     FROM cpms_equipment_usage
                     WHERE id = :id
                       AND project_id = :project_id
                     FOR UPDATE"
                );

                $st->execute(
                    array(
                        ':id' => (int)$targetId,
                        ':project_id' => $projectId
                    )
                );

                if (!$st->fetch(PDO::FETCH_ASSOC)) {
                    throw new Exception(
                        '장비비 원본자료를 찾을 수 없습니다.'
                    );
                }

                if ($deleted) {
                    $st = $pdo->prepare(
                        "UPDATE cpms_equipment_usage
                         SET
                            amount = 0,
                            work_unit = 0,
                            is_manual_unit = 1
                         WHERE id = :id
                           AND project_id = :project_id"
                    );

                    $st->execute(
                        array(
                            ':id' => (int)$targetId,
                            ':project_id' => $projectId
                        )
                    );
                } elseif (
                    $requestType !==
                    self::REQUEST_MONTH_MOVE
                ) {
                    $masterId =
                        self::ensureEquipmentMaster(
                            $pdo,
                            $projectId,
                            $data
                        );

                    $quantity = (
                        isset($data['quantity']) &&
                        (float)$data['quantity'] > 0
                    )
                        ? (float)$data['quantity']
                        : 1;

                    $st = $pdo->prepare(
                        "UPDATE cpms_equipment_usage
                         SET
                            equipment_id = :master_id,
                            use_date = :use_date,
                            work_unit = :quantity,
                            base_rate_snapshot = :unit_price,
                            amount = :amount,
                            is_manual_unit = 1,
                            memo = :memo
                         WHERE id = :id
                           AND project_id = :project_id"
                    );

                    $st->execute(
                        array(
                            ':master_id' => $masterId,
                            ':use_date' => $data['use_date'],
                            ':quantity' => $quantity,
                            ':unit_price' => $data['unit_price'],
                            ':amount' => $data['amount'],
                            ':memo' => $data['memo'],
                            ':id' => (int)$targetId,
                            ':project_id' => $projectId
                        )
                    );

                    $data['master_id'] = $masterId;
                }
            }
        } elseif ($targetType === 'outsourcing') {
            if (
                $requestType ===
                self::REQUEST_ADD
            ) {
                $st = $pdo->prepare(
                    "INSERT INTO cpms_outsourcing_costs
                        (
                            project_id,
                            expense_date,
                            category,
                            company_name,
                            amount,
                            memo,
                            created_by_name,
                            created_by_email,
                            is_deleted,
                            created_at,
                            updated_at
                        )
                     VALUES
                        (
                            :project_id,
                            :use_date,
                            '외주비',
                            :company_name,
                            :amount,
                            :memo,
                            :created_by_name,
                            :created_by_email,
                            0,
                            NOW(),
                            NOW()
                        )"
                );

                $st->execute(
                    array(
                        ':project_id' => $projectId,
                        ':use_date' => $data['use_date'],
                        ':company_name' => $data['vendor_name'],
                        ':amount' => $data['amount'],
                        ':memo' => $data['memo'],
                        ':created_by_name' => $request['requester_name'],
                        ':created_by_email' => $request['requester_email']
                    )
                );

                $targetId =
                    (string)$pdo->lastInsertId();
            } else {
                $st = $pdo->prepare(
                    "SELECT *
                     FROM cpms_outsourcing_costs
                     WHERE id = :id
                       AND project_id = :project_id
                     FOR UPDATE"
                );

                $st->execute(
                    array(
                        ':id' => (int)$targetId,
                        ':project_id' => $projectId
                    )
                );

                if (!$st->fetch(PDO::FETCH_ASSOC)) {
                    throw new Exception(
                        '외주비 원본자료를 찾을 수 없습니다.'
                    );
                }

                if ($deleted) {
                    $st = $pdo->prepare(
                        "UPDATE cpms_outsourcing_costs
                         SET
                            is_deleted = 1,
                            updated_at = NOW()
                         WHERE id = :id
                           AND project_id = :project_id"
                    );

                    $st->execute(
                        array(
                            ':id' => (int)$targetId,
                            ':project_id' => $projectId
                        )
                    );
                } elseif (
                    $requestType !==
                    self::REQUEST_MONTH_MOVE
                ) {
                    $st = $pdo->prepare(
                        "UPDATE cpms_outsourcing_costs
                         SET
                            expense_date = :use_date,
                            company_name = :company_name,
                            amount = :amount,
                            memo = :memo,
                            updated_at = NOW()
                         WHERE id = :id
                           AND project_id = :project_id"
                    );

                    $st->execute(
                        array(
                            ':use_date' => $data['use_date'],
                            ':company_name' => $data['vendor_name'],
                            ':amount' => $data['amount'],
                            ':memo' => $data['memo'],
                            ':id' => (int)$targetId,
                            ':project_id' => $projectId
                        )
                    );
                }
            }
        } elseif ($targetType === 'labor_force') {
            $month = self::validYm(
                isset($data['settlement_ym'])
                    ? $data['settlement_ym']
                    : ''
            );

            if ($month === '') {
                $month = substr(
                    (string)$data['use_date'],
                    0,
                    7
                );
            }

            if (
                $requestType ===
                self::REQUEST_ADD
            ) {
                $st = $pdo->prepare(
                    "INSERT INTO cpms_labor_force_adjustments
                        (
                            project_id,
                            month,
                            amount,
                            memo,
                            updated_by,
                            updated_by_name,
                            updated_by_email,
                            created_at,
                            updated_at
                        )
                     VALUES
                        (
                            :project_id,
                            :month,
                            :amount,
                            :memo,
                            :updated_by,
                            :updated_by_name,
                            :updated_by_email,
                            NOW(),
                            NOW()
                        )"
                );

                $st->execute(
                    array(
                        ':project_id' => $projectId,
                        ':month' => $month,
                        ':amount' => $data['amount'],
                        ':memo' => $data['memo'],
                        ':updated_by' => $request['requester_employee_id'],
                        ':updated_by_name' => $request['requester_name'],
                        ':updated_by_email' => $request['requester_email']
                    )
                );

                $targetId =
                    (string)$pdo->lastInsertId();
            } else {
                $st = $pdo->prepare(
                    "SELECT *
                     FROM cpms_labor_force_adjustments
                     WHERE id = :id
                       AND project_id = :project_id
                     FOR UPDATE"
                );

                $st->execute(
                    array(
                        ':id' => (int)$targetId,
                        ':project_id' => $projectId
                    )
                );

                if (!$st->fetch(PDO::FETCH_ASSOC)) {
                    throw new Exception(
                        '노무비 원본자료를 찾을 수 없습니다.'
                    );
                }

                if ($deleted) {
                    $st = $pdo->prepare(
                        "UPDATE cpms_labor_force_adjustments
                         SET
                            amount = 0,
                            updated_at = NOW()
                         WHERE id = :id
                           AND project_id = :project_id"
                    );

                    $st->execute(
                        array(
                            ':id' => (int)$targetId,
                            ':project_id' => $projectId
                        )
                    );
                } else {
                    $st = $pdo->prepare(
                        "UPDATE cpms_labor_force_adjustments
                         SET
                            month = :month,
                            amount = :amount,
                            memo = :memo,
                            updated_at = NOW()
                         WHERE id = :id
                           AND project_id = :project_id"
                    );

                    $st->execute(
                        array(
                            ':month' => $month,
                            ':amount' => $data['amount'],
                            ':memo' => $data['memo'],
                            ':id' => (int)$targetId,
                            ':project_id' => $projectId
                        )
                    );
                }
            }
        } elseif ($targetType === 'daily_cost') {
            if (
                $requestType ===
                self::REQUEST_ADD
            ) {
                $st = $pdo->prepare(
                    "INSERT INTO cpms_daily_cost_entries
                        (
                            project_id,
                            cost_date,
                            cost_type,
                            amount,
                            memo,
                            created_at
                        )
                     VALUES
                        (
                            :project_id,
                            :cost_date,
                            :cost_type,
                            :amount,
                            :memo,
                            NOW()
                        )"
                );

                $st->execute(
                    array(
                        ':project_id' => $projectId,
                        ':cost_date' => $data['use_date'],
                        ':cost_type' => $data['category'],
                        ':amount' => $data['amount'],
                        ':memo' => $data['memo']
                    )
                );

                $targetId =
                    (string)$pdo->lastInsertId();
            } else {
                $st = $pdo->prepare(
                    "SELECT *
                     FROM cpms_daily_cost_entries
                     WHERE id = :id
                       AND project_id = :project_id
                     FOR UPDATE"
                );

                $st->execute(
                    array(
                        ':id' => (int)$targetId,
                        ':project_id' => $projectId
                    )
                );

                if (!$st->fetch(PDO::FETCH_ASSOC)) {
                    throw new Exception(
                        '기타 투입비 원본자료를 찾을 수 없습니다.'
                    );
                }

                if ($deleted) {
                    $st = $pdo->prepare(
                        "UPDATE cpms_daily_cost_entries
                         SET amount = 0
                         WHERE id = :id
                           AND project_id = :project_id"
                    );

                    $st->execute(
                        array(
                            ':id' => (int)$targetId,
                            ':project_id' => $projectId
                        )
                    );
                } elseif (
                    $requestType !==
                    self::REQUEST_MONTH_MOVE
                ) {
                    $st = $pdo->prepare(
                        "UPDATE cpms_daily_cost_entries
                         SET
                            cost_date = :cost_date,
                            cost_type = :cost_type,
                            amount = :amount,
                            memo = :memo
                         WHERE id = :id
                           AND project_id = :project_id"
                    );

                    $st->execute(
                        array(
                            ':cost_date' => $data['use_date'],
                            ':cost_type' => $data['category'],
                            ':amount' => $data['amount'],
                            ':memo' => $data['memo'],
                            ':id' => (int)$targetId,
                            ':project_id' => $projectId
                        )
                    );
                }
            }
        } elseif ($targetType === 'safety') {
            self::applySafetyRequest(
                $request,
                $data,
                $deleted,
                $targetId
            );

            if (
                $requestType ===
                self::REQUEST_ADD
            ) {
                $targetId =
                    isset($data['_created_target_id'])
                        ? (string)$data['_created_target_id']
                        : $targetId;
            }
        } else {
            throw new Exception(
                '지원하지 않는 비용 종류입니다.'
            );
        }

        if ($targetId === '') {
            throw new Exception(
                '자동 반영 후 원본자료 연결번호를 확인할 수 없습니다.'
            );
        }

        self::saveMeta(
            $pdo,
            $targetType,
            $targetId,
            $projectId,
            $requestId,
            $data,
            $deleted,
            $reason
        );

        return array(
            'ok' => true,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'project_id' => $projectId,
            'settlement_ym' => isset($data['settlement_ym'])
                ? $data['settlement_ym']
                : '',
            'applied_data' => $data
        );
    }

    private static function applySafetyRequest(
        $request,
        &$data,
        $deleted,
        $targetId
    ) {
        $helper =
            dirname(__DIR__) .
            '/views/safety/safety_cost_helper.php';

        if (is_file($helper)) {
            require_once $helper;
        }

        if (
            !function_exists(
                'cpms_safety_cost_store_path'
            )
        ) {
            throw new Exception(
                '안전·보건 비용 저장 도우미를 불러오지 못했습니다.'
            );
        }

        $store = cpms_read_json_file(
            cpms_safety_cost_store_path(),
            array('items' => array())
        );

        if (!is_array($store)) {
            $store = array(
                'items' => array()
            );
        }

        if (
            !isset($store['items']) ||
            !is_array($store['items'])
        ) {
            $store['items'] = array();
        }

        $idx = -1;

        foreach ($store['items'] as $i => $row) {
            if (
                is_array($row) &&
                isset($row['id']) &&
                (string)$row['id'] ===
                    (string)$targetId
            ) {
                $idx = $i;
                break;
            }
        }

        $requestType =
            (string)$request['request_type'];

        if (
            $requestType !== self::REQUEST_ADD &&
            $idx < 0
        ) {
            throw new Exception(
                '안전·보건 비용 원본자료를 찾을 수 없습니다.'
            );
        }

        if (
            $requestType ===
            self::REQUEST_ADD
        ) {
            $targetId = function_exists(
                'cpms_safety_cost_new_id'
            )
                ? cpms_safety_cost_new_id()
                : (
                    'SC-' .
                    date('YmdHis') .
                    '-' .
                    substr(
                        md5(
                            uniqid('', true)
                        ),
                        0,
                        8
                    )
                );

            $idx = count($store['items']);

            $store['items'][$idx] = array(
                'id' => $targetId,
                'created_at' => date('Y-m-d H:i:s'),
                'created_by' => (int)$request['requester_employee_id'],
                'created_by_name' => (string)$request['requester_name'],
                'created_by_email' => (string)$request['requester_email']
            );

            $data['_created_target_id'] =
                $targetId;
        }

        if ($deleted) {
            $store['items'][$idx]['status'] =
                'deleted';

            $store['items'][$idx]['is_deleted'] =
                1;

            $store['items'][$idx]['deleted_at'] =
                date('Y-m-d H:i:s');

            $store['items'][$idx]['deleted_by_name'] =
                '비용 변경 최종승인';
        } elseif (
            $requestType !==
            self::REQUEST_MONTH_MOVE
        ) {
            $store['items'][$idx]['project_id'] =
                (int)$request['project_id'];

            $store['items'][$idx]['project_name'] =
                (string)$request['project_name'];

            $store['items'][$idx]['use_date'] =
                (string)$data['use_date'];

            $store['items'][$idx]['category'] =
                (string)$data['category'];

            $store['items'][$idx]['vendor_name'] =
                (string)$data['vendor_name'];

            $store['items'][$idx]['item_name'] =
                (string)$data['item_name'];

            $store['items'][$idx]['use_content'] =
                (string)$data['item_name'];

            $store['items'][$idx]['quantity'] =
                (float)$data['quantity'];

            $store['items'][$idx]['unit_price'] =
                (float)$data['unit_price'];

            $store['items'][$idx]['amount'] =
                (float)$data['amount'];

            $store['items'][$idx]['supply_amount'] =
                (float)$data['amount'];

            $store['items'][$idx]['remark'] =
                (string)$data['memo'];

            $store['items'][$idx]['status'] =
                'active';

            $store['items'][$idx]['is_deleted'] =
                0;

            $store['items'][$idx]['updated_at'] =
                date('Y-m-d H:i:s');
        }

        $store['updated_at'] =
            date('Y-m-d H:i:s');

        if (
            !cpms_write_json_file(
                cpms_safety_cost_store_path(),
                $store
            )
        ) {
            throw new Exception(
                '안전·보건 비용 JSON 자동 반영에 실패했습니다.'
            );
        }
    }

    public static function historyCount(
        $pdo,
        $targetType,
        $targetId
    ) {
        if (
            !$pdo ||
            !self::isInstalled($pdo)
        ) {
            return 0;
        }

        try {
            $st = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM cpms_cost_change_requests
                 WHERE target_type = :target_type
                   AND target_id = :target_id"
            );

            $st->execute(
                array(
                    ':target_type' => (string)$targetType,
                    ':target_id' => (string)$targetId
                )
            );

            return (int)$st->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    public static function latestRequest(
        $pdo,
        $targetType,
        $targetId
    ) {
        if (
            !$pdo ||
            !self::isInstalled($pdo)
        ) {
            return null;
        }

        try {
            $st = $pdo->prepare(
                "SELECT *
                 FROM cpms_cost_change_requests
                 WHERE target_type = :target_type
                   AND target_id = :target_id
                 ORDER BY created_at DESC, id DESC
                 LIMIT 1"
            );

            $st->execute(
                array(
                    ':target_type' => (string)$targetType,
                    ':target_id' => (string)$targetId
                )
            );

            $row = $st->fetch(PDO::FETCH_ASSOC);

            return is_array($row)
                ? $row
                : null;
        } catch (Exception $e) {
            return null;
        }
    }

    public static function historyBadgeLabel(
        $request,
        $count
    ) {
        $prefix = '';

        if (is_array($request)) {
            $status = isset($request['status'])
                ? (string)$request['status']
                : '';

            $requestType =
                isset($request['request_type'])
                    ? (string)$request['request_type']
                    : '';

            if ($status === self::STATUS_REJECTED) {
                $prefix = '반려 · ';
            } elseif ($status === self::STATUS_FAILED) {
                $prefix = '처리 실패 · ';
            } elseif ($status === self::STATUS_CANCELLED) {
                $prefix = '요청 취소 · ';
            } elseif (
                $status === self::STATUS_COMPLETED &&
                $requestType === self::REQUEST_MONTH_MOVE
            ) {
                $prefix = '귀속월 변경 완료 · ';
            } elseif (
                $status === self::STATUS_COMPLETED &&
                $requestType === self::REQUEST_DELETE
            ) {
                $prefix = '삭제 완료 · ';
            } elseif (
                $status === self::STATUS_COMPLETED
            ) {
                $prefix = '변경 완료 · ';
            }
        }

        return
            $prefix .
            '변경이력 ' .
            (int)$count .
            '건';
    }

    public static function statusClass($status)
    {
        $status = (string)$status;

        if (
            $status === self::STATUS_REJECTED ||
            $status === self::STATUS_FAILED
        ) {
            return 'border-red-200 bg-red-50 text-red-700';
        }

        if (
            $status === self::STATUS_COMPLETED
        ) {
            return 'border-emerald-200 bg-emerald-50 text-emerald-700';
        }

        if (
            $status === self::STATUS_CANCELLED
        ) {
            return 'border-gray-200 bg-gray-50 text-gray-600';
        }

        if (
            $status === self::STATUS_FINAL_PENDING
        ) {
            return 'border-violet-200 bg-violet-50 text-violet-700';
        }

        return 'border-amber-200 bg-amber-50 text-amber-700';
    }
}