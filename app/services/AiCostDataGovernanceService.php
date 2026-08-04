<?php
/**
 * CPMS 투입비 데이터 원천, 날짜 의미, 변경 연결을 관리한다.
 *
 * 원본 비용 테이블은 변경하지 않고 원본 행별 매핑을 별도 보존한다.
 * PHP 5.6 / MySQL 5.6 compatible.
 */
namespace App\Services;

use App\Core\Auth;
use App\Core\Db;
use PDO;
use Exception;

require_once __DIR__ . '/CostChangeService.php';

class AiCostDataGovernanceService
{
    const MAP_TABLE = 'cpms_ai_cost_data_origins';
    const HISTORY_TABLE = 'cpms_ai_cost_data_origin_history';
    const LIVE_START_YM = '2026-07';

    private static $tableCache = array();
    private static $columnCache = array();
    private static $timingCache = array();
    private static $amountCache = array();

    public static function pdo($pdo = null)
    {
        return $pdo ? $pdo : Db::pdo();
    }

    private static function connectionKey($pdo)
    {
        return is_object($pdo) ? spl_object_hash($pdo) : 'none';
    }

    private static function validIdentifier($value)
    {
        return preg_match('/^[A-Za-z0-9_]+$/', (string)$value) === 1;
    }

    public static function tableExists($pdo, $table)
    {
        $pdo = self::pdo($pdo);
        if (!$pdo || !self::validIdentifier($table)) return false;
        $key = self::connectionKey($pdo) . ':' . $table;
        if (array_key_exists($key, self::$tableCache)) return self::$tableCache[$key];
        try {
            $st = $pdo->prepare('SHOW TABLES LIKE :table_name');
            if (!$st || !$st->execute(array(':table_name'=>(string)$table))) {
                self::$tableCache[$key] = false;
            } else {
                self::$tableCache[$key] = $st->fetchColumn() !== false;
            }
        } catch (Exception $e) {
            self::$tableCache[$key] = false;
        }
        return self::$tableCache[$key];
    }

    public static function columns($pdo, $table)
    {
        $pdo = self::pdo($pdo);
        if (!$pdo || !self::validIdentifier($table) || !self::tableExists($pdo, $table)) return array();
        $key = self::connectionKey($pdo) . ':' . $table;
        if (isset(self::$columnCache[$key])) return self::$columnCache[$key];
        $columns = array();
        try {
            $st = $pdo->query('SHOW COLUMNS FROM `' . $table . '`');
            if ($st) {
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                if (is_array($rows)) foreach ($rows as $row) if (isset($row['Field'])) $columns[(string)$row['Field']] = true;
            }
        } catch (Exception $e) {
            $columns = array();
        }
        self::$columnCache[$key] = $columns;
        return $columns;
    }

    public static function columnExists($pdo, $table, $column)
    {
        $columns = self::columns($pdo, $table);
        return isset($columns[(string)$column]);
    }

    private static function clearCache()
    {
        self::$tableCache = array();
        self::$columnCache = array();
        self::$timingCache = array();
        self::$amountCache = array();
    }

    public static function origins()
    {
        return array(
            'LIVE_EMPLOYEE_INPUT',
            'HISTORICAL_MIGRATION',
            'MANUAL_BACKFILL',
            'SYSTEM_IMPORT',
            'ADMIN_CORRECTION',
            'RE_ENTRY',
            'UNKNOWN_REVIEW'
        );
    }

    public static function originLabels()
    {
        return array(
            'LIVE_EMPLOYEE_INPUT'=>'실제 직원 입력',
            'HISTORICAL_MIGRATION'=>'과거자료 이관',
            'MANUAL_BACKFILL'=>'누락자료 보충',
            'SYSTEM_IMPORT'=>'시스템 가져오기',
            'ADMIN_CORRECTION'=>'관리자 보정',
            'RE_ENTRY'=>'재입력',
            'UNKNOWN_REVIEW'=>'원천 확인 필요'
        );
    }

    public static function originLabel($origin)
    {
        $labels = self::originLabels();
        return isset($labels[$origin]) ? $labels[$origin] : '원천 확인 필요';
    }

    public static function normalizeOrigin($origin)
    {
        $origin = strtoupper(trim((string)$origin));
        return in_array($origin, self::origins(), true) ? $origin : 'UNKNOWN_REVIEW';
    }

    public static function defaultEligibility($origin, $verified, $hasReason)
    {
        $origin = self::normalizeOrigin($origin);
        $verified = !empty($verified);
        $hasReason = !empty($hasReason);
        if ($origin === 'LIVE_EMPLOYEE_INPUT') return array('amount'=>1, 'timing'=>1);
        if ($origin === 'HISTORICAL_MIGRATION') return array('amount'=>1, 'timing'=>0);
        if ($origin === 'ADMIN_CORRECTION') return array('amount'=>$hasReason ? 1 : 0, 'timing'=>0);
        if ($origin === 'RE_ENTRY') return array('amount'=>$verified ? 1 : 0, 'timing'=>0);
        if ($origin === 'MANUAL_BACKFILL' || $origin === 'SYSTEM_IMPORT') return array('amount'=>$verified ? 1 : 0, 'timing'=>0);
        return array('amount'=>0, 'timing'=>0);
    }

    public static function requiresChangeReason($origin)
    {
        return self::normalizeOrigin($origin)==='ADMIN_CORRECTION';
    }

    /**
     * 기간 하나만으로 7월 이후 자료를 실제 입력으로 확정하지 않는다.
     * 정상 업무화면을 뜻하는 DIRECT와 같은 생성경로 근거를 함께 사용한다.
     */
    public static function classifyOrigin($sourceType, $eventAction, $eventAt, $options)
    {
        $options = is_array($options) ? $options : array();
        if (isset($options['data_origin']) && in_array(strtoupper(trim((string)$options['data_origin'])), self::origins(), true)) {
            return strtoupper(trim((string)$options['data_origin']));
        }
        if (!empty($options['reentry_of_target_id']) || strtoupper(trim((string)$eventAction)) === 'RE_ENTRY') return 'RE_ENTRY';
        $sourceType = strtoupper(trim((string)$sourceType));
        if ($sourceType === 'MANUAL_BACKFILL') return 'MANUAL_BACKFILL';
        if ($sourceType === 'HISTORICAL_MIGRATION') return 'HISTORICAL_MIGRATION';
        if ($sourceType === 'EXCEL' || $sourceType === 'AUTO_CALC' || $sourceType === 'ATTENDANCE' || $sourceType === 'SYSTEM_IMPORT') return 'SYSTEM_IMPORT';
        if ($sourceType === 'ADMIN_FORCE' || $sourceType === 'APPROVAL') return 'ADMIN_CORRECTION';
        if ($sourceType !== 'DIRECT') return 'UNKNOWN_REVIEW';
        if (array_key_exists('employee_input_verified',$options) && empty($options['employee_input_verified'])) return 'UNKNOWN_REVIEW';
        $ym = preg_match('/^(\d{4}-\d{2})-\d{2}/', (string)$eventAt, $m) ? $m[1] : '';
        $accountingMonth = isset($options['settlement_ym']) && preg_match('/^\d{4}-\d{2}$/',(string)$options['settlement_ym']) ? (string)$options['settlement_ym'] : '';
        if ($ym >= self::LIVE_START_YM && $accountingMonth !== '' && $accountingMonth < self::LIVE_START_YM) return 'UNKNOWN_REVIEW';
        if ($ym !== '' && $ym < self::LIVE_START_YM) return 'HISTORICAL_MIGRATION';
        return 'LIVE_EMPLOYEE_INPUT';
    }

    public static function canonicalSourceTable($targetType)
    {
        $targetType = strtolower(trim((string)$targetType));
        $map = array(
            'material'=>'cpms_material_usage', 'material_usage'=>'cpms_material_usage',
            'equipment'=>'cpms_equipment_usage', 'equipment_usage'=>'cpms_equipment_usage',
            'outsourcing'=>'cpms_outsourcing_costs', 'outsourcing_cost'=>'cpms_outsourcing_costs',
            'daily_cost'=>'cpms_daily_cost_entries',
            'monthly_deduction'=>'cpms_project_monthly_deductions', 'project_monthly_deduction'=>'cpms_project_monthly_deductions',
            'labor_force'=>'cpms_labor_force_adjustments', 'labor_force_adjustment'=>'cpms_labor_force_adjustments',
            'labor_gongsu_override'=>'cpms_labor_gongsu_overrides',
            'safety'=>'cpms_safety_cost_store', 'safety_cost'=>'cpms_safety_cost_store'
        );
        if (isset($map[$targetType])) return $map[$targetType];
        return preg_match('/^[a-z0-9_]+$/', $targetType) ? $targetType : 'unknown_cost_source';
    }

    private static function normalizeCostType($value)
    {
        $value = strtolower(trim((string)$value));
        $map = array(
            'labor'=>'labor', '노무'=>'labor', '노무비'=>'labor',
            'material'=>'material', '자재'=>'material', '자재비'=>'material',
            'purchase'=>'purchase', '구매품'=>'purchase',
            'equipment'=>'equipment', '장비'=>'equipment', '장비비'=>'equipment',
            'outsourcing'=>'outsourcing', '외주'=>'outsourcing', '외주비'=>'outsourcing',
            'safety'=>'safety', '안전'=>'safety', '안전관리비'=>'safety',
            'health'=>'health', '보건'=>'health', '보건비'=>'health',
            'other_expense'=>'other_expense', '기타경비'=>'other_expense',
            'other'=>'other', '기타'=>'other'
        );
        return isset($map[$value]) ? $map[$value] : 'other';
    }

    public static function createMapTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS cpms_ai_cost_data_origins (\n"
            . " id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,\n"
            . " source_table VARCHAR(80) NOT NULL,\n source_row_id VARCHAR(80) NOT NULL,\n"
            . " project_id INT UNSIGNED NULL,\n cost_type VARCHAR(40) NOT NULL,\n"
            . " occurred_date DATE NULL,\n accounting_month CHAR(7) NULL,\n entered_at DATETIME NULL,\n updated_at DATETIME NULL,\n"
            . " payment_due_date DATE NULL,\n paid_at DATETIME NULL,\n"
            . " data_origin VARCHAR(40) NOT NULL,\n classification_reason VARCHAR(500) NULL,\n"
            . " classified_by INT NULL,\n classified_at DATETIME NOT NULL,\n reviewed_by INT NULL,\n reviewed_at DATETIME NULL,\n"
            . " is_amount_eligible TINYINT(1) NOT NULL DEFAULT 0,\n is_timing_eligible TINYINT(1) NOT NULL DEFAULT 0,\n is_active TINYINT(1) NOT NULL DEFAULT 1,\n"
            . " previous_source_table VARCHAR(80) NULL,\n previous_source_row_id VARCHAR(80) NULL,\n latest_event_id BIGINT UNSIGNED NULL,\n"
            . " created_at DATETIME NOT NULL,\n updated_record_at DATETIME NOT NULL,\n"
            . " UNIQUE KEY uk_ai_cost_origin_source (source_table,source_row_id),\n"
            . " KEY idx_ai_cost_origin_month (accounting_month,project_id,cost_type,is_active),\n"
            . " KEY idx_ai_cost_origin_origin (data_origin,is_active),\n"
            . " KEY idx_ai_cost_origin_timing (is_timing_eligible,accounting_month,cost_type),\n"
            . " KEY idx_ai_cost_origin_review (data_origin,reviewed_at)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    public static function createHistoryTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS cpms_ai_cost_data_origin_history (\n"
            . " id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,\n"
            . " origin_map_id BIGINT UNSIGNED NOT NULL,\n source_table VARCHAR(80) NOT NULL,\n source_row_id VARCHAR(80) NOT NULL,\n"
            . " event_type VARCHAR(30) NOT NULL,\n before_data MEDIUMTEXT NULL,\n after_data MEDIUMTEXT NULL,\n"
            . " actor_employee_id INT NULL,\n actor_name VARCHAR(100) NULL,\n change_reason VARCHAR(500) NULL,\n created_at DATETIME NOT NULL,\n"
            . " KEY idx_ai_cost_origin_history_map (origin_map_id,created_at),\n"
            . " KEY idx_ai_cost_origin_history_source (source_table,source_row_id,created_at)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    public static function installOrUpdate($pdo = null)
    {
        $pdo = self::pdo($pdo);
        if (!$pdo) return array('ok'=>false, 'message'=>'DB 연결 상태를 확인할 수 없습니다.');
        try {
            $ok1 = $pdo->exec(self::createMapTableSql());
            $ok2 = $pdo->exec(self::createHistoryTableSql());
            if ($ok1 === false || $ok2 === false) return array('ok'=>false, 'message'=>'데이터 원천 관리 구조를 설치하지 못했습니다.');
            self::clearCache();
            return array('ok'=>self::isInstalled($pdo), 'message'=>self::isInstalled($pdo) ? '데이터 원천 관리 구조를 확인했습니다.' : '데이터 원천 관리 구조를 확인해주세요.');
        } catch (Exception $e) {
            error_log('[AI Cost Origin] install failed');
            return array('ok'=>false, 'message'=>'데이터 원천 관리 구조 설치 중 오류가 발생했습니다.');
        }
    }

    public static function isInstalled($pdo = null)
    {
        $pdo = self::pdo($pdo);
        return $pdo && self::tableExists($pdo, self::MAP_TABLE) && self::tableExists($pdo, self::HISTORY_TABLE);
    }

    private static function encode($value)
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : null;
    }

    private static function actor()
    {
        $user = Auth::user();
        $id = is_array($user) && isset($user['id']) ? (int)$user['id'] : 0;
        $name = trim((string)Auth::userName());
        if (function_exists('mb_substr')) $name = mb_substr($name, 0, 100, 'UTF-8');
        else $name = substr($name, 0, 100);
        return array('id'=>$id > 0 ? $id : null, 'name'=>$name !== '' ? $name : null);
    }

    private static function validDateTime($value)
    {
        $value = trim((string)$value);
        return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) ? $value : null;
    }

    private static function rowBySource($pdo, $sourceTable, $sourceRowId)
    {
        if (!$pdo || !self::isInstalled($pdo)) return array();
        try {
            $st = $pdo->prepare('SELECT * FROM `' . self::MAP_TABLE . '` WHERE source_table=:source_table AND source_row_id=:source_row_id LIMIT 1');
            if (!$st || !$st->execute(array(':source_table'=>$sourceTable, ':source_row_id'=>$sourceRowId))) return array();
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : array();
        } catch (Exception $e) {
            return array();
        }
    }

    private static function history($pdo, $mapId, $sourceTable, $sourceRowId, $eventType, $before, $after, $reason, $actor)
    {
        try {
            $st = $pdo->prepare('INSERT INTO `' . self::HISTORY_TABLE . '` (origin_map_id,source_table,source_row_id,event_type,before_data,after_data,actor_employee_id,actor_name,change_reason,created_at) VALUES (:map_id,:source_table,:source_row_id,:event_type,:before_data,:after_data,:actor_id,:actor_name,:reason,:created_at)');
            if (!$st) return false;
            return $st->execute(array(
                ':map_id'=>(int)$mapId, ':source_table'=>$sourceTable, ':source_row_id'=>$sourceRowId,
                ':event_type'=>$eventType, ':before_data'=>self::encode($before), ':after_data'=>self::encode($after),
                ':actor_id'=>isset($actor['id']) ? $actor['id'] : null, ':actor_name'=>isset($actor['name']) ? $actor['name'] : null,
                ':reason'=>$reason !== '' ? substr($reason, 0, 500) : null, ':created_at'=>date('Y-m-d H:i:s')
            ));
        } catch (Exception $e) {
            return false;
        }
    }

    public static function syncEvent($pdo, $eventId, $options)
    {
        $pdo = self::pdo($pdo);
        $options = is_array($options) ? $options : array();
        if (!$pdo || !self::isInstalled($pdo)) return array('ok'=>true, 'skipped'=>true, 'reason'=>'not_installed');
        $targetType = isset($options['target_type']) ? trim((string)$options['target_type']) : '';
        $targetId = isset($options['target_id']) ? trim((string)$options['target_id']) : '';
        if ($targetType === '' || $targetId === '') return array('ok'=>true, 'skipped'=>true, 'reason'=>'source_missing');
        $sourceTable = self::canonicalSourceTable($targetType);
        $eventAction = strtoupper(trim(isset($options['event_action']) ? (string)$options['event_action'] : 'ADJUST'));
        $eventAt = self::validDateTime(isset($options['event_at']) ? $options['event_at'] : '');
        if ($eventAt === null) $eventAt = date('Y-m-d H:i:s');
        $origin = self::classifyOrigin(isset($options['source_type']) ? $options['source_type'] : '', $eventAction, $eventAt, $options);
        $reason = trim(isset($options['classification_reason']) ? (string)$options['classification_reason'] : (isset($options['reason']) ? (string)$options['reason'] : ''));
        if ($reason === '') $reason = '입력 경로와 변경 이벤트를 기준으로 자동 분류';
        $verified = !empty($options['amount_verified']) || $origin === 'LIVE_EMPLOYEE_INPUT' || $origin === 'HISTORICAL_MIGRATION' || ($origin === 'ADMIN_CORRECTION' && trim(isset($options['reason']) ? (string)$options['reason'] : '') !== '');
        $eligibility = self::defaultEligibility($origin, $verified, trim(isset($options['reason']) ? (string)$options['reason'] : '') !== '');
        $existing = self::rowBySource($pdo, $sourceTable, $targetId);
        if ($eventAction === 'CREATE' && !empty($existing) && (int)$existing['is_active'] === 0) {
            $origin = 'RE_ENTRY';
            $eligibility = self::defaultEligibility($origin, true, true);
            $reason = '삭제 이력이 있는 비용의 재입력';
        }
        $hasActualDate = array_key_exists('actual_date', $options);
        $hasAccountingMonth = array_key_exists('settlement_ym', $options);
        $actualDate = CostChangeService::validDate(isset($options['actual_date']) ? $options['actual_date'] : '');
        if (!$hasActualDate && !empty($existing) && !empty($existing['occurred_date'])) $actualDate = (string)$existing['occurred_date'];
        if ($origin === 'HISTORICAL_MIGRATION') $actualDate = '';
        $accountingMonth = CostChangeService::validYm(isset($options['settlement_ym']) ? $options['settlement_ym'] : '');
        if (!$hasAccountingMonth && !empty($existing) && !empty($existing['accounting_month'])) $accountingMonth = (string)$existing['accounting_month'];
        if ($accountingMonth === '' && $actualDate !== '') $accountingMonth = CostChangeService::settlementYm(isset($options['cost_type']) ? $options['cost_type'] : 'other', $actualDate);
        $actor = self::actor();
        $now = date('Y-m-d H:i:s');
        $isActive = $eventAction === 'DELETE' ? 0 : 1;
        if ($eventAction === 'RESTORE') $isActive = 1;
        $enteredAt = !empty($existing) && !empty($existing['entered_at']) ? $existing['entered_at'] : $eventAt;
        $projectId = isset($options['project_id']) && (int)$options['project_id'] > 0 ? (int)$options['project_id'] : (!empty($existing['project_id']) ? (int)$existing['project_id'] : null);
        $costType = isset($options['cost_type']) && trim((string)$options['cost_type']) !== '' ? self::normalizeCostType($options['cost_type']) : (!empty($existing['cost_type']) ? (string)$existing['cost_type'] : 'other');
        try {
            if (empty($existing)) {
                $sql = 'INSERT INTO `' . self::MAP_TABLE . '` (source_table,source_row_id,project_id,cost_type,occurred_date,accounting_month,entered_at,updated_at,payment_due_date,paid_at,data_origin,classification_reason,classified_by,classified_at,reviewed_by,reviewed_at,is_amount_eligible,is_timing_eligible,is_active,previous_source_table,previous_source_row_id,latest_event_id,created_at,updated_record_at) VALUES (:source_table,:source_row_id,:project_id,:cost_type,:occurred_date,:accounting_month,:entered_at,:updated_at,NULL,NULL,:data_origin,:reason,:classified_by,:classified_at,NULL,NULL,:amount_eligible,:timing_eligible,:is_active,:previous_table,:previous_id,:event_id,:created_at,:updated_record_at)';
                $st = $pdo->prepare($sql);
                if (!$st || !$st->execute(array(
                    ':source_table'=>$sourceTable, ':source_row_id'=>$targetId,
                    ':project_id'=>$projectId,
                    ':cost_type'=>$costType,
                    ':occurred_date'=>$actualDate !== '' ? $actualDate : null, ':accounting_month'=>$accountingMonth !== '' ? $accountingMonth : null,
                    ':entered_at'=>$enteredAt, ':updated_at'=>$eventAt, ':data_origin'=>$origin, ':reason'=>$reason,
                    ':classified_by'=>$actor['id'], ':classified_at'=>$now, ':amount_eligible'=>$eligibility['amount'], ':timing_eligible'=>$eligibility['timing'],
                    ':is_active'=>$isActive, ':previous_table'=>isset($options['reentry_of_target_type']) ? self::canonicalSourceTable($options['reentry_of_target_type']) : null,
                    ':previous_id'=>isset($options['reentry_of_target_id']) && trim((string)$options['reentry_of_target_id']) !== '' ? trim((string)$options['reentry_of_target_id']) : null,
                    ':event_id'=>(int)$eventId > 0 ? (int)$eventId : null, ':created_at'=>$now, ':updated_record_at'=>$now
                ))) return array('ok'=>false, 'message'=>'데이터 원천 정보를 저장하지 못했습니다.');
                $mapId = (int)$pdo->lastInsertId();
                $after = self::rowBySource($pdo, $sourceTable, $targetId);
                self::history($pdo, $mapId, $sourceTable, $targetId, 'CREATE', array(), $after, $reason, $actor);
            } else {
                $sql = 'UPDATE `' . self::MAP_TABLE . '` SET project_id=:project_id,cost_type=:cost_type,occurred_date=:occurred_date,accounting_month=:accounting_month,entered_at=:entered_at,updated_at=:updated_at,data_origin=:data_origin,classification_reason=:reason,classified_by=:classified_by,classified_at=:classified_at,is_amount_eligible=:amount_eligible,is_timing_eligible=:timing_eligible,is_active=:is_active,latest_event_id=:event_id,updated_record_at=:updated_record_at WHERE id=:id';
                $st = $pdo->prepare($sql);
                if (!$st || !$st->execute(array(
                    ':project_id'=>$projectId,
                    ':cost_type'=>$costType, ':occurred_date'=>$actualDate !== '' ? $actualDate : null,
                    ':accounting_month'=>$accountingMonth !== '' ? $accountingMonth : null, ':entered_at'=>$enteredAt, ':updated_at'=>$eventAt,
                    ':data_origin'=>$origin, ':reason'=>$reason, ':classified_by'=>$actor['id'], ':classified_at'=>$now,
                    ':amount_eligible'=>$eligibility['amount'], ':timing_eligible'=>$eligibility['timing'], ':is_active'=>$isActive,
                    ':event_id'=>(int)$eventId > 0 ? (int)$eventId : null, ':updated_record_at'=>$now, ':id'=>(int)$existing['id']
                ))) return array('ok'=>false, 'message'=>'데이터 원천 정보를 갱신하지 못했습니다.');
                $after = self::rowBySource($pdo, $sourceTable, $targetId);
                self::history($pdo, (int)$existing['id'], $sourceTable, $targetId, $eventAction, $existing, $after, $reason, $actor);
            }
            self::$timingCache = array();
            self::$amountCache = array();
            return array('ok'=>true, 'skipped'=>false, 'data_origin'=>$origin);
        } catch (Exception $e) {
            error_log('[AI Cost Origin] event sync failed');
            return array('ok'=>false, 'message'=>'데이터 원천 정보를 반영하지 못했습니다.');
        }
    }

    private static function sourceDefinitions()
    {
        return array(
            array('table'=>'cpms_material_usage','cost_type'=>'material','date'=>'use_date','ym'=>''),
            array('table'=>'cpms_equipment_usage','cost_type'=>'equipment','date'=>'use_date','ym'=>''),
            array('table'=>'cpms_outsourcing_costs','cost_type'=>'outsourcing','date'=>'expense_date','ym'=>''),
            array('table'=>'cpms_daily_cost_entries','cost_type'=>'other','date'=>'cost_date','ym'=>''),
            array('table'=>'cpms_labor_force_adjustments','cost_type'=>'labor','date'=>'','ym'=>'month'),
            array('table'=>'cpms_labor_gongsu_overrides','cost_type'=>'labor','date'=>'work_date','ym'=>'month'),
            array('table'=>'cpms_project_monthly_deductions','cost_type'=>'other','date'=>'','ym'=>'ym')
        );
    }

    private static function materialUsageCostType($pdo, $materialId)
    {
        $materialId = (int)$materialId;
        if (!$pdo || $materialId <= 0 || !self::tableExists($pdo, 'cpms_material_items') || !self::columnExists($pdo, 'cpms_material_items', 'category')) return 'material';
        try {
            $st = $pdo->prepare('SELECT category FROM cpms_material_items WHERE id=:id LIMIT 1');
            if (!$st || !$st->execute(array(':id'=>$materialId))) return 'material';
            $category = trim((string)$st->fetchColumn());
            if ($category === '구매품') return 'purchase';
            if ($category === '기타경비') return 'other_expense';
        } catch (Exception $e) {
        }
        return 'material';
    }

    /** 원본 DB 테이블로 직접 연결할 수 없는 안전·보건 JSON 자료 등은 기존 통합 이벤트 근거로만 보완한다. */
    private static function classifyUnmappedEvents($pdo)
    {
        $classified = 0;
        $failed = 0;
        if (!self::tableExists($pdo, 'cpms_cost_data_events')) return array('classified'=>0, 'failed'=>0);
        try {
            $st = $pdo->query('SELECT * FROM cpms_cost_data_events WHERE target_type IS NOT NULL AND target_type<>\'\' AND target_id IS NOT NULL AND target_id<>\'\' ORDER BY id DESC');
            if (!$st) return array('classified'=>0, 'failed'=>1);
            while ($event = $st->fetch(PDO::FETCH_ASSOC)) {
                $sourceTable = self::canonicalSourceTable(isset($event['target_type']) ? $event['target_type'] : '');
                $sourceRowId = isset($event['target_id']) ? trim((string)$event['target_id']) : '';
                if ($sourceTable === 'unknown_cost_source' || $sourceRowId === '' || !empty(self::rowBySource($pdo, $sourceTable, $sourceRowId))) continue;
                $eventAt = isset($event['event_at']) ? (string)$event['event_at'] : '';
                $ym = CostChangeService::validYm(isset($event['settlement_ym']) ? $event['settlement_ym'] : '');
                $origin = $ym !== '' && $ym < self::LIVE_START_YM
                    ? 'HISTORICAL_MIGRATION'
                    : self::classifyOrigin(isset($event['source_type']) ? $event['source_type'] : '', isset($event['event_action']) ? $event['event_action'] : '', $eventAt, array('settlement_ym'=>$ym));
                $options = $event;
                $options['data_origin'] = $origin;
                $options['classification_reason'] = $origin === 'HISTORICAL_MIGRATION' ? '2026년 6월분까지의 기존 통합 비용 이벤트 초기 분류' : '기존 통합 비용 이벤트의 입력 경로 근거로 초기 분류';
                $result = self::syncEvent($pdo, isset($event['id']) ? (int)$event['id'] : 0, $options);
                if (!empty($result['ok']) && empty($result['skipped'])) $classified++;
                else $failed++;
            }
        } catch (Exception $e) {
            $failed++;
            error_log('[AI Cost Origin] unmapped event classification failed');
        }
        return array('classified'=>$classified, 'failed'=>$failed);
    }

    private static function latestEventEvidence($pdo, $sourceTable, $rowId)
    {
        if (!self::tableExists($pdo, 'cpms_cost_data_events')) return array();
        $aliases = array(
            'cpms_material_usage'=>array('material_usage','material'),
            'cpms_equipment_usage'=>array('equipment_usage','equipment'),
            'cpms_outsourcing_costs'=>array('outsourcing_cost','outsourcing'),
            'cpms_daily_cost_entries'=>array('daily_cost'),
            'cpms_labor_force_adjustments'=>array('labor_force_adjustment','labor_force'),
            'cpms_labor_gongsu_overrides'=>array('labor_gongsu_override')
        );
        $types = isset($aliases[$sourceTable]) ? $aliases[$sourceTable] : array();
        if (count($types) === 0) return array();
        $holders = array();
        $params = array(':target_id'=>(string)$rowId);
        foreach ($types as $index=>$type) {
            $key = ':target_type_' . $index;
            $holders[] = $key;
            $params[$key] = $type;
        }
        try {
            $sql = 'SELECT * FROM cpms_cost_data_events WHERE target_id=:target_id AND target_type IN (' . implode(',', $holders) . ') ORDER BY event_at DESC,id DESC LIMIT 1';
            $st = $pdo->prepare($sql);
            if (!$st || !$st->execute($params)) return array();
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : array();
        } catch (Exception $e) {
            return array();
        }
    }

    /** 기존 원본은 건드리지 않고 아직 매핑되지 않은 행만 초기 분류한다. */
    public static function classifyExisting($pdo = null)
    {
        $pdo = self::pdo($pdo);
        if (!$pdo || !self::isInstalled($pdo)) return array('ok'=>false, 'classified'=>0, 'failed'=>0, 'message'=>'데이터 원천 관리 구조를 먼저 설치해주세요.');
        $classified = 0;
        $failed = 0;
        $actor = self::actor();
        foreach (self::sourceDefinitions() as $definition) {
            $table = $definition['table'];
            if (!self::tableExists($pdo, $table) || !self::columnExists($pdo, $table, 'id') || !self::columnExists($pdo, $table, 'project_id')) continue;
            $select = array('id','project_id');
            foreach (array($definition['date'],$definition['ym'],'created_at','updated_at','cost_type') as $column) {
                if ($column !== '' && self::columnExists($pdo, $table, $column) && !in_array($column, $select, true)) $select[] = $column;
            }
            if ($table === 'cpms_material_usage' && self::columnExists($pdo, $table, 'material_id')) $select[] = 'material_id';
            try {
                $stRows = $pdo->query('SELECT `' . implode('`,`', $select) . '` FROM `' . $table . '` ORDER BY id ASC');
                if (!$stRows) { $failed++; continue; }
                while ($row = $stRows->fetch(PDO::FETCH_ASSOC)) {
                    $rowId = isset($row['id']) ? (string)$row['id'] : '';
                    if ($rowId === '' || !empty(self::rowBySource($pdo, $table, $rowId))) continue;
                    $date = $definition['date'] !== '' && isset($row[$definition['date']]) ? CostChangeService::validDate($row[$definition['date']]) : '';
                    $ym = $definition['ym'] !== '' && isset($row[$definition['ym']]) ? CostChangeService::validYm($row[$definition['ym']]) : '';
                    $costType = $definition['cost_type'];
                    if ($table === 'cpms_material_usage' && isset($row['material_id'])) $costType = self::materialUsageCostType($pdo, $row['material_id']);
                    if ($table === 'cpms_daily_cost_entries' && isset($row['cost_type'])) $costType = self::normalizeCostType($row['cost_type']);
                    if ($ym === '' && $date !== '') $ym = CostChangeService::settlementYm($costType, $date);
                    $evidence = self::latestEventEvidence($pdo, $table, $rowId);
                    if ($ym !== '' && $ym < self::LIVE_START_YM) {
                        $origin = 'HISTORICAL_MIGRATION';
                        $reason = '2026년 6월분까지의 기존 대리입력 자료 초기 분류';
                    } else if (!empty($evidence)) {
                        $origin = self::classifyOrigin(isset($evidence['source_type']) ? $evidence['source_type'] : '', isset($evidence['event_action']) ? $evidence['event_action'] : '', isset($evidence['event_at']) ? $evidence['event_at'] : '', $evidence);
                        $reason = '통합 비용 이벤트의 입력 경로 근거로 초기 분류';
                    } else {
                        $origin = 'UNKNOWN_REVIEW';
                        $reason = '기간 외에 원천을 확정할 입력 경로 근거가 없어 검토 필요';
                    }
                    $verified = $origin === 'LIVE_EMPLOYEE_INPUT' || $origin === 'HISTORICAL_MIGRATION' || $origin === 'ADMIN_CORRECTION';
                    $eligibility = self::defaultEligibility($origin, $verified, $origin === 'ADMIN_CORRECTION');
                    $enteredAt = isset($row['created_at']) ? self::validDateTime($row['created_at']) : null;
                    $updatedAt = isset($row['updated_at']) ? self::validDateTime($row['updated_at']) : $enteredAt;
                    $occurredDate = $origin === 'HISTORICAL_MIGRATION' ? null : ($date !== '' ? $date : null);
                    $now = date('Y-m-d H:i:s');
                    $sql = 'INSERT IGNORE INTO `' . self::MAP_TABLE . '` (source_table,source_row_id,project_id,cost_type,occurred_date,accounting_month,entered_at,updated_at,payment_due_date,paid_at,data_origin,classification_reason,classified_by,classified_at,reviewed_by,reviewed_at,is_amount_eligible,is_timing_eligible,is_active,latest_event_id,created_at,updated_record_at) VALUES (:source_table,:source_row_id,:project_id,:cost_type,:occurred_date,:accounting_month,:entered_at,:updated_at,NULL,NULL,:data_origin,:reason,:classified_by,:classified_at,NULL,NULL,:amount_eligible,:timing_eligible,1,:event_id,:created_at,:updated_record_at)';
                    $st = $pdo->prepare($sql);
                    if (!$st || !$st->execute(array(
                        ':source_table'=>$table, ':source_row_id'=>$rowId, ':project_id'=>(int)$row['project_id'], ':cost_type'=>$costType,
                        ':occurred_date'=>$occurredDate, ':accounting_month'=>$ym !== '' ? $ym : null, ':entered_at'=>$enteredAt, ':updated_at'=>$updatedAt,
                        ':data_origin'=>$origin, ':reason'=>$reason, ':classified_by'=>$actor['id'], ':classified_at'=>$now,
                        ':amount_eligible'=>$eligibility['amount'], ':timing_eligible'=>$eligibility['timing'],
                        ':event_id'=>isset($evidence['id']) ? (int)$evidence['id'] : null, ':created_at'=>$now, ':updated_record_at'=>$now
                    ))) { $failed++; continue; }
                    if ($st->rowCount() > 0) {
                        $classified++;
                        $mapId = (int)$pdo->lastInsertId();
                        $after = self::rowBySource($pdo, $table, $rowId);
                        self::history($pdo, $mapId, $table, $rowId, 'INITIAL_CLASSIFICATION', array(), $after, $reason, $actor);
                    }
                }
            } catch (Exception $e) {
                $failed++;
                error_log('[AI Cost Origin] existing classification failed: ' . $table);
            }
        }
        $eventFallback = self::classifyUnmappedEvents($pdo);
        $classified += isset($eventFallback['classified']) ? (int)$eventFallback['classified'] : 0;
        $failed += isset($eventFallback['failed']) ? (int)$eventFallback['failed'] : 0;
        self::$timingCache = array();
        self::$amountCache = array();
        return array('ok'=>$failed === 0, 'classified'=>$classified, 'failed'=>$failed, 'message'=>'기존자료 원천분류: 신규 ' . $classified . '건, 확인 실패 ' . $failed . '건. 원본 자료는 변경하지 않았습니다.');
    }

    public static function reviewOrigin($pdo, $mapId, $origin, $amountEligible, $timingEligible, $reason)
    {
        $pdo = self::pdo($pdo);
        $mapId = (int)$mapId;
        $origin = self::normalizeOrigin($origin);
        $reason = trim((string)$reason);
        if (!$pdo || !self::isInstalled($pdo) || $mapId <= 0) return array('ok'=>false, 'message'=>'검토할 자료를 찾을 수 없습니다.');
        if ($reason === '') return array('ok'=>false, 'message'=>'분류 또는 보정 사유를 입력해주세요.');
        if ($origin === 'UNKNOWN_REVIEW' && !empty($timingEligible)) return array('ok'=>false, 'message'=>'원천 확인 필요 자료는 입력시점 패턴에 포함할 수 없습니다.');
        if ($origin !== 'LIVE_EMPLOYEE_INPUT') $timingEligible = 0;
        try {
            $st = $pdo->prepare('SELECT * FROM `' . self::MAP_TABLE . '` WHERE id=:id LIMIT 1');
            if (!$st || !$st->execute(array(':id'=>$mapId))) return array('ok'=>false, 'message'=>'검토할 자료를 조회하지 못했습니다.');
            $before = $st->fetch(PDO::FETCH_ASSOC);
            if (!is_array($before)) return array('ok'=>false, 'message'=>'검토할 자료를 찾을 수 없습니다.');
            $actor = self::actor();
            $now = date('Y-m-d H:i:s');
            $up = $pdo->prepare('UPDATE `' . self::MAP_TABLE . '` SET data_origin=:origin,classification_reason=:reason,classified_by=:actor_id,classified_at=:classified_at,reviewed_by=:reviewed_by,reviewed_at=:reviewed_at,is_amount_eligible=:amount_eligible,is_timing_eligible=:timing_eligible,updated_record_at=:updated_at WHERE id=:id');
            if (!$up || !$up->execute(array(':origin'=>$origin, ':reason'=>substr($reason,0,500), ':actor_id'=>$actor['id'], ':classified_at'=>$now, ':reviewed_by'=>$actor['id'], ':reviewed_at'=>$now, ':amount_eligible'=>!empty($amountEligible)?1:0, ':timing_eligible'=>!empty($timingEligible)?1:0, ':updated_at'=>$now, ':id'=>$mapId))) return array('ok'=>false, 'message'=>'원천 검토결과를 저장하지 못했습니다.');
            $st2 = $pdo->prepare('SELECT * FROM `' . self::MAP_TABLE . '` WHERE id=:id LIMIT 1');
            $after = array();
            if ($st2 && $st2->execute(array(':id'=>$mapId))) {
                $loaded = $st2->fetch(PDO::FETCH_ASSOC);
                if (is_array($loaded)) $after = $loaded;
            }
            self::history($pdo, $mapId, $before['source_table'], $before['source_row_id'], 'REVIEW', $before, $after, $reason, $actor);
            self::$timingCache = array();
            self::$amountCache = array();
            return array('ok'=>true, 'message'=>'데이터 원천 검토결과를 저장했습니다.');
        } catch (Exception $e) {
            error_log('[AI Cost Origin] review failed');
            return array('ok'=>false, 'message'=>'데이터 원천 검토결과를 저장하지 못했습니다.');
        }
    }

    public static function amountGroupEligible($pdo, $projectId, $accountingMonth, $costType)
    {
        $projectId = (int)$projectId;
        $accountingMonth = CostChangeService::validYm($accountingMonth);
        $costType = trim((string)$costType);
        if ($accountingMonth !== '' && $accountingMonth < self::LIVE_START_YM) return true;
        $pdo = self::pdo($pdo);
        if (!$pdo || !self::isInstalled($pdo) || $projectId <= 0 || $accountingMonth === '' || $costType === '') return false;
        $key = self::connectionKey($pdo) . ':' . $projectId . ':' . $accountingMonth . ':' . $costType;
        if (array_key_exists($key, self::$amountCache)) return self::$amountCache[$key];
        try {
            $sql = 'SELECT COUNT(*) AS total_count,SUM(CASE WHEN is_amount_eligible=1 THEN 1 ELSE 0 END) AS eligible_count FROM `' . self::MAP_TABLE . '` WHERE project_id=:project_id AND accounting_month=:accounting_month AND cost_type=:cost_type AND is_active=1';
            $st = $pdo->prepare($sql);
            if (!$st || !$st->execute(array(':project_id'=>$projectId, ':accounting_month'=>$accountingMonth, ':cost_type'=>$costType))) return false;
            $row = $st->fetch(PDO::FETCH_ASSOC);
            $total = is_array($row) ? (int)$row['total_count'] : 0;
            $eligible = is_array($row) ? (int)$row['eligible_count'] : 0;
            self::$amountCache[$key] = $total > 0 && $eligible === $total;
        } catch (Exception $e) {
            self::$amountCache[$key] = false;
        }
        return self::$amountCache[$key];
    }

    public static function timingGroupEligible($pdo, $projectId, $accountingMonth, $costType)
    {
        $pdo = self::pdo($pdo);
        $projectId = (int)$projectId;
        $accountingMonth = CostChangeService::validYm($accountingMonth);
        $costType = trim((string)$costType);
        if (!$pdo || !self::isInstalled($pdo) || $projectId <= 0 || $accountingMonth === '' || $costType === '') return false;
        $key = self::connectionKey($pdo) . ':' . $projectId . ':' . $accountingMonth . ':' . $costType;
        if (array_key_exists($key, self::$timingCache)) return self::$timingCache[$key];
        try {
            $sql = 'SELECT COUNT(*) AS total_count,SUM(CASE WHEN data_origin=\'LIVE_EMPLOYEE_INPUT\' AND is_timing_eligible=1 THEN 1 ELSE 0 END) AS eligible_count FROM `' . self::MAP_TABLE . '` WHERE project_id=:project_id AND accounting_month=:accounting_month AND cost_type=:cost_type AND is_active=1';
            $st = $pdo->prepare($sql);
            if (!$st || !$st->execute(array(':project_id'=>$projectId, ':accounting_month'=>$accountingMonth, ':cost_type'=>$costType))) return false;
            $row = $st->fetch(PDO::FETCH_ASSOC);
            $total = is_array($row) ? (int)$row['total_count'] : 0;
            $eligible = is_array($row) ? (int)$row['eligible_count'] : 0;
            self::$timingCache[$key] = $total > 0 && $eligible === $total;
        } catch (Exception $e) {
            self::$timingCache[$key] = false;
        }
        return self::$timingCache[$key];
    }

    public static function originSummary($pdo, $projectId, $accountingMonth, $costType)
    {
        $pdo = self::pdo($pdo);
        $result = array('available'=>false, 'total_count'=>0, 'amount_eligible_count'=>0, 'timing_eligible_count'=>0, 'origins'=>array());
        if (!$pdo || !self::isInstalled($pdo)) return $result;
        try {
            $sql = 'SELECT data_origin,COUNT(*) AS row_count,SUM(is_amount_eligible) AS amount_count,SUM(is_timing_eligible) AS timing_count FROM `' . self::MAP_TABLE . '` WHERE project_id=:project_id AND accounting_month=:accounting_month AND cost_type=:cost_type AND is_active=1 GROUP BY data_origin ORDER BY data_origin';
            $st = $pdo->prepare($sql);
            if (!$st || !$st->execute(array(':project_id'=>(int)$projectId, ':accounting_month'=>(string)$accountingMonth, ':cost_type'=>(string)$costType))) return $result;
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows)) return $result;
            foreach ($rows as $row) {
                $origin = self::normalizeOrigin(isset($row['data_origin']) ? $row['data_origin'] : '');
                $count = (int)$row['row_count'];
                $result['origins'][$origin] = $count;
                $result['total_count'] += $count;
                $result['amount_eligible_count'] += (int)$row['amount_count'];
                $result['timing_eligible_count'] += (int)$row['timing_count'];
            }
            $result['available'] = $result['total_count'] > 0;
        } catch (Exception $e) {
        }
        return $result;
    }

    public static function status($pdo = null)
    {
        $pdo = self::pdo($pdo);
        $result = array('installed'=>false, 'total_count'=>0, 'unknown_count'=>0, 'timing_eligible_count'=>0, 'origin_counts'=>array());
        if (!$pdo || !self::isInstalled($pdo)) return $result;
        $result['installed'] = true;
        try {
            $st = $pdo->query('SELECT data_origin,COUNT(*) AS row_count,SUM(is_timing_eligible) AS timing_count FROM `' . self::MAP_TABLE . '` WHERE is_active=1 GROUP BY data_origin');
            if ($st) {
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                if (is_array($rows)) foreach ($rows as $row) {
                    $origin = self::normalizeOrigin($row['data_origin']);
                    $count = (int)$row['row_count'];
                    $result['origin_counts'][$origin] = $count;
                    $result['total_count'] += $count;
                    $result['timing_eligible_count'] += (int)$row['timing_count'];
                    if ($origin === 'UNKNOWN_REVIEW') $result['unknown_count'] += $count;
                }
            }
        } catch (Exception $e) {
        }
        return $result;
    }

    public static function listMappings($pdo, $filters, $page, $perPage)
    {
        $pdo = self::pdo($pdo);
        $filters = is_array($filters) ? $filters : array();
        $page = max(1, (int)$page);
        $perPage = max(1, min(100, (int)$perPage));
        if (!$pdo || !self::isInstalled($pdo)) return array();
        $where = array('1=1');
        $params = array();
        if (!empty($filters['origin'])) { $where[] = 'data_origin=:origin'; $params[':origin'] = self::normalizeOrigin($filters['origin']); }
        if (!empty($filters['accounting_month']) && CostChangeService::validYm($filters['accounting_month']) !== '') { $where[] = 'accounting_month=:accounting_month'; $params[':accounting_month'] = $filters['accounting_month']; }
        if (!empty($filters['project_id']) && (int)$filters['project_id'] > 0) { $where[] = 'project_id=:project_id'; $params[':project_id'] = (int)$filters['project_id']; }
        try {
            $sql = 'SELECT m.*,p.name AS project_name FROM `' . self::MAP_TABLE . '` m LEFT JOIN cpms_projects p ON p.id=m.project_id WHERE ' . implode(' AND ', $where) . ' ORDER BY CASE WHEN m.data_origin=\'UNKNOWN_REVIEW\' THEN 0 ELSE 1 END,m.accounting_month DESC,m.id DESC LIMIT :limit OFFSET :offset';
            $st = $pdo->prepare($sql);
            if (!$st) return array();
            foreach ($params as $key=>$value) $st->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            $st->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $st->bindValue(':offset', ($page-1)*$perPage, PDO::PARAM_INT);
            if (!$st->execute()) return array();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : array();
        } catch (Exception $e) {
            return array();
        }
    }
}
?>
