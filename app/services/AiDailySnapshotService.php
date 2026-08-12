<?php
/**
 * 현장별 일일 투입현황 스냅샷 서비스.
 *
 * 기존 CompanyProfitSummaryService의 현장 손익 계산 결과를 재사용하고,
 * 스냅샷 전용 테이블 두 개만 설치/갱신한다.
 * PHP 5.6 / MySQL 5.6 compatible.
 */

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;
use Exception;
use PDO;

require_once __DIR__ . '/CostChangeService.php';
require_once __DIR__ . '/CompanyProfitSummaryService.php';

class AiDailySnapshotService
{
    const RUN_TABLE = 'cpms_ai_snapshot_runs';
    const SNAPSHOT_TABLE = 'cpms_ai_daily_snapshots';
    const EVENT_TABLE = 'cpms_cost_data_events';

    private static $tableCache = array();
    private static $columnCache = array();
    private static $indexCache = array();
    private static $installedCache = array();

    public static function pdo($pdo = null)
    {
        if ($pdo) return $pdo;
        return Db::pdo();
    }

    private static function connectionKey($pdo)
    {
        return is_object($pdo) ? spl_object_hash($pdo) : 'none';
    }

    private static function validIdentifier($value)
    {
        return preg_match('/^[A-Za-z0-9_]+$/', (string)$value) === 1;
    }

    public static function validDate($value)
    {
        $value = trim((string)$value);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) return '';
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $value : '';
    }

    public static function validYm($value)
    {
        $value = trim((string)$value);
        if (!preg_match('/^(\d{4})-(\d{2})$/', $value, $m)) return '';
        $month = (int)$m[2];
        return ($month >= 1 && $month <= 12) ? $value : '';
    }

    public static function businessToday()
    {
        if (class_exists('App\\Services\\CostChangeService')) {
            return CostChangeService::businessToday();
        }
        return date('Y-m-d');
    }

    private static function businessNow($format)
    {
        try {
            $now = new \DateTime('now', new \DateTimeZone('Asia/Seoul'));
            return $now->format($format);
        } catch (Exception $e) {
            return date($format);
        }
    }

    public static function tableExists($pdo, $table)
    {
        $pdo = self::pdo($pdo);
        $table = trim((string)$table);
        if (!$pdo || !self::validIdentifier($table)) return false;
        $key = self::connectionKey($pdo) . ':' . $table;
        if (array_key_exists($key, self::$tableCache)) return self::$tableCache[$key];
        try {
            $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name');
            $st->bindValue(':table_name', $table, PDO::PARAM_STR);
            $st->execute();
            self::$tableCache[$key] = ((int)$st->fetchColumn() > 0);
        } catch (Exception $e) {
            self::$tableCache[$key] = false;
        }
        return self::$tableCache[$key];
    }

    public static function getTableColumns($pdo, $table)
    {
        $pdo = self::pdo($pdo);
        $table = trim((string)$table);
        if (!$pdo || !self::validIdentifier($table) || !self::tableExists($pdo, $table)) return array();
        $key = self::connectionKey($pdo) . ':' . $table;
        if (isset(self::$columnCache[$key])) return self::$columnCache[$key];
        $columns = array();
        try {
            $st = $pdo->query('SHOW COLUMNS FROM `' . $table . '`');
            $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
            foreach ($rows as $row) {
                if (isset($row['Field'])) $columns[(string)$row['Field']] = true;
            }
        } catch (Exception $e) {
            $columns = array();
        }
        self::$columnCache[$key] = $columns;
        return $columns;
    }

    public static function columnExists($pdo, $table, $column)
    {
        $columns = self::getTableColumns($pdo, $table);
        return isset($columns[(string)$column]);
    }

    public static function getTableIndexes($pdo, $table)
    {
        $pdo = self::pdo($pdo);
        $table = trim((string)$table);
        if (!$pdo || !self::validIdentifier($table) || !self::tableExists($pdo, $table)) return array();
        $key = self::connectionKey($pdo) . ':' . $table;
        if (isset(self::$indexCache[$key])) return self::$indexCache[$key];
        $indexes = array();
        try {
            $st = $pdo->query('SHOW INDEX FROM `' . $table . '`');
            $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
            foreach ($rows as $row) {
                if (isset($row['Key_name'])) $indexes[(string)$row['Key_name']] = true;
            }
        } catch (Exception $e) {
            $indexes = array();
        }
        self::$indexCache[$key] = $indexes;
        return $indexes;
    }

    private static function clearSchemaCache($pdo)
    {
        $prefix = self::connectionKey($pdo) . ':';
        foreach (array_keys(self::$tableCache) as $key) if (strpos($key, $prefix) === 0) unset(self::$tableCache[$key]);
        foreach (array_keys(self::$columnCache) as $key) if (strpos($key, $prefix) === 0) unset(self::$columnCache[$key]);
        foreach (array_keys(self::$indexCache) as $key) if (strpos($key, $prefix) === 0) unset(self::$indexCache[$key]);
        unset(self::$installedCache[self::connectionKey($pdo)]);
    }

    public static function requiredRunColumns()
    {
        return array(
            'id', 'run_uid', 'snapshot_date', 'target_ym', 'trigger_type', 'run_status',
            'project_count', 'success_count', 'failure_count', 'monthly_input_total',
            'cumulative_input_total', 'actor_employee_id', 'actor_name', 'started_at',
            'finished_at', 'error_summary', 'created_at'
        );
    }

    public static function requiredRunIndexes()
    {
        return array('PRIMARY', 'uk_ai_snapshot_run_uid', 'idx_ai_snapshot_run_date', 'idx_ai_snapshot_run_status');
    }

    public static function requiredSnapshotColumns()
    {
        return array(
            'id', 'run_id', 'snapshot_date', 'target_ym', 'captured_at', 'project_id',
            'project_name_snapshot', 'project_status_snapshot', 'project_start_date',
            'project_end_date', 'contract_amount', 'monthly_sales_amount',
            'cumulative_sales_amount', 'labor_amount', 'outsourcing_amount',
            'purchase_amount', 'material_amount', 'equipment_amount',
            'other_expense_amount', 'safety_amount', 'health_amount', 'other_amount',
            'monthly_input_amount', 'cumulative_input_amount', 'monthly_profit_amount',
            'monthly_cost_rate', 'cumulative_profit_amount', 'cumulative_cost_rate',
            'today_event_count', 'month_event_count', 'latest_event_at',
            'missing_section_count', 'data_flags', 'detail_data', 'first_captured_at',
            'last_captured_at', 'capture_count', 'created_at', 'updated_at'
        );
    }

    public static function requiredSnapshotIndexes()
    {
        return array(
            'PRIMARY', 'uk_ai_daily_snapshot', 'idx_ai_snapshot_project_date',
            'idx_ai_snapshot_target_month', 'idx_ai_snapshot_run', 'idx_ai_snapshot_status'
        );
    }

    public static function createRunTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS cpms_ai_snapshot_runs (\n"
            . "    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,\n"
            . "    run_uid VARCHAR(64) NOT NULL,\n"
            . "    snapshot_date DATE NOT NULL,\n"
            . "    target_ym CHAR(7) NOT NULL,\n"
            . "    trigger_type VARCHAR(20) NOT NULL,\n"
            . "    run_status VARCHAR(20) NOT NULL,\n"
            . "    project_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    success_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    failure_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    monthly_input_total DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    cumulative_input_total DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    actor_employee_id INT NULL,\n"
            . "    actor_name VARCHAR(100) NULL,\n"
            . "    started_at DATETIME NOT NULL,\n"
            . "    finished_at DATETIME NULL,\n"
            . "    error_summary TEXT NULL,\n"
            . "    created_at DATETIME NOT NULL,\n"
            . "    UNIQUE KEY uk_ai_snapshot_run_uid (run_uid),\n"
            . "    KEY idx_ai_snapshot_run_date (snapshot_date, started_at),\n"
            . "    KEY idx_ai_snapshot_run_status (run_status, started_at)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    public static function createSnapshotTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS cpms_ai_daily_snapshots (\n"
            . "    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,\n"
            . "    run_id BIGINT UNSIGNED NULL,\n"
            . "    snapshot_date DATE NOT NULL,\n"
            . "    target_ym CHAR(7) NOT NULL,\n"
            . "    captured_at DATETIME NOT NULL,\n"
            . "    project_id INT UNSIGNED NOT NULL,\n"
            . "    project_name_snapshot VARCHAR(190) NULL,\n"
            . "    project_status_snapshot VARCHAR(50) NULL,\n"
            . "    project_start_date DATE NULL,\n"
            . "    project_end_date DATE NULL,\n"
            . "    contract_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    monthly_sales_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    cumulative_sales_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    labor_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    outsourcing_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    purchase_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    material_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    equipment_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    other_expense_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    safety_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    health_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    other_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    monthly_input_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    cumulative_input_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    monthly_profit_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    monthly_cost_rate DECIMAL(8,3) NULL,\n"
            . "    cumulative_profit_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    cumulative_cost_rate DECIMAL(8,3) NULL,\n"
            . "    today_event_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    month_event_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    latest_event_at DATETIME NULL,\n"
            . "    missing_section_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    data_flags MEDIUMTEXT NULL,\n"
            . "    detail_data MEDIUMTEXT NULL,\n"
            . "    first_captured_at DATETIME NOT NULL,\n"
            . "    last_captured_at DATETIME NOT NULL,\n"
            . "    capture_count INT UNSIGNED NOT NULL DEFAULT 1,\n"
            . "    created_at DATETIME NOT NULL,\n"
            . "    updated_at DATETIME NOT NULL,\n"
            . "    UNIQUE KEY uk_ai_daily_snapshot (snapshot_date, project_id),\n"
            . "    KEY idx_ai_snapshot_project_date (project_id, snapshot_date),\n"
            . "    KEY idx_ai_snapshot_target_month (target_ym, snapshot_date),\n"
            . "    KEY idx_ai_snapshot_run (run_id),\n"
            . "    KEY idx_ai_snapshot_status (project_status_snapshot, snapshot_date)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    private static function runColumnDefinitions()
    {
        return array(
            'run_uid'=>'VARCHAR(64) NOT NULL', 'snapshot_date'=>'DATE NOT NULL', 'target_ym'=>'CHAR(7) NOT NULL',
            'trigger_type'=>'VARCHAR(20) NOT NULL', 'run_status'=>'VARCHAR(20) NOT NULL',
            'project_count'=>'INT UNSIGNED NOT NULL DEFAULT 0', 'success_count'=>'INT UNSIGNED NOT NULL DEFAULT 0',
            'failure_count'=>'INT UNSIGNED NOT NULL DEFAULT 0', 'monthly_input_total'=>'DECIMAL(18,2) NOT NULL DEFAULT 0',
            'cumulative_input_total'=>'DECIMAL(18,2) NOT NULL DEFAULT 0', 'actor_employee_id'=>'INT NULL',
            'actor_name'=>'VARCHAR(100) NULL', 'started_at'=>'DATETIME NOT NULL', 'finished_at'=>'DATETIME NULL',
            'error_summary'=>'TEXT NULL', 'created_at'=>'DATETIME NOT NULL'
        );
    }

    private static function snapshotColumnDefinitions()
    {
        return array(
            'run_id'=>'BIGINT UNSIGNED NULL', 'snapshot_date'=>'DATE NOT NULL', 'target_ym'=>'CHAR(7) NOT NULL',
            'captured_at'=>'DATETIME NOT NULL', 'project_id'=>'INT UNSIGNED NOT NULL',
            'project_name_snapshot'=>'VARCHAR(190) NULL', 'project_status_snapshot'=>'VARCHAR(50) NULL',
            'project_start_date'=>'DATE NULL', 'project_end_date'=>'DATE NULL',
            'contract_amount'=>'DECIMAL(18,2) NOT NULL DEFAULT 0', 'monthly_sales_amount'=>'DECIMAL(18,2) NOT NULL DEFAULT 0',
            'cumulative_sales_amount'=>'DECIMAL(18,2) NOT NULL DEFAULT 0', 'labor_amount'=>'DECIMAL(18,2) NOT NULL DEFAULT 0',
            'outsourcing_amount'=>'DECIMAL(18,2) NOT NULL DEFAULT 0', 'purchase_amount'=>'DECIMAL(18,2) NOT NULL DEFAULT 0',
            'material_amount'=>'DECIMAL(18,2) NOT NULL DEFAULT 0', 'equipment_amount'=>'DECIMAL(18,2) NOT NULL DEFAULT 0',
            'other_expense_amount'=>'DECIMAL(18,2) NOT NULL DEFAULT 0', 'safety_amount'=>'DECIMAL(18,2) NOT NULL DEFAULT 0',
            'health_amount'=>'DECIMAL(18,2) NOT NULL DEFAULT 0', 'other_amount'=>'DECIMAL(18,2) NOT NULL DEFAULT 0',
            'monthly_input_amount'=>'DECIMAL(18,2) NOT NULL DEFAULT 0', 'cumulative_input_amount'=>'DECIMAL(18,2) NOT NULL DEFAULT 0',
            'monthly_profit_amount'=>'DECIMAL(18,2) NOT NULL DEFAULT 0', 'monthly_cost_rate'=>'DECIMAL(8,3) NULL',
            'cumulative_profit_amount'=>'DECIMAL(18,2) NOT NULL DEFAULT 0', 'cumulative_cost_rate'=>'DECIMAL(8,3) NULL',
            'today_event_count'=>'INT UNSIGNED NOT NULL DEFAULT 0', 'month_event_count'=>'INT UNSIGNED NOT NULL DEFAULT 0',
            'latest_event_at'=>'DATETIME NULL', 'missing_section_count'=>'INT UNSIGNED NOT NULL DEFAULT 0',
            'data_flags'=>'MEDIUMTEXT NULL', 'detail_data'=>'MEDIUMTEXT NULL', 'first_captured_at'=>'DATETIME NOT NULL',
            'last_captured_at'=>'DATETIME NOT NULL', 'capture_count'=>'INT UNSIGNED NOT NULL DEFAULT 1',
            'created_at'=>'DATETIME NOT NULL', 'updated_at'=>'DATETIME NOT NULL'
        );
    }

    private static function ensureOwnedTable($pdo, $table, $columns, $indexes, &$updated)
    {
        if (!in_array($table, array(self::RUN_TABLE, self::SNAPSHOT_TABLE), true)) {
            throw new Exception('unsupported snapshot table');
        }
        if (!self::columnExists($pdo, $table, 'id')) {
            $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST');
            $updated[] = $table . '.column:id';
            self::clearSchemaCache($pdo);
        }
        foreach ($columns as $column => $definition) {
            if (!self::columnExists($pdo, $table, $column)) {
                $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
                $updated[] = $table . '.column:' . $column;
                self::clearSchemaCache($pdo);
            }
        }
        $existing = self::getTableIndexes($pdo, $table);
        foreach ($indexes as $name => $definition) {
            if (!isset($existing[$name])) {
                $pdo->exec('ALTER TABLE `' . $table . '` ADD ' . $definition);
                $updated[] = $table . '.index:' . $name;
                self::clearSchemaCache($pdo);
                $existing[$name] = true;
            }
        }
    }

    public static function installOrUpdate($pdo = null)
    {
        $pdo = self::pdo($pdo);
        if (!$pdo) return array('ok'=>false, 'message'=>'DB 연결을 확인할 수 없습니다.', 'created'=>array(), 'updated'=>array());
        $created = array();
        $updated = array();
        try {
            if (!self::tableExists($pdo, self::RUN_TABLE)) $created[] = self::RUN_TABLE;
            if (!self::tableExists($pdo, self::SNAPSHOT_TABLE)) $created[] = self::SNAPSHOT_TABLE;
            $pdo->exec(self::createRunTableSql());
            $pdo->exec(self::createSnapshotTableSql());
            self::clearSchemaCache($pdo);
            self::ensureOwnedTable($pdo, self::RUN_TABLE, self::runColumnDefinitions(), array(
                'PRIMARY'=>'PRIMARY KEY (`id`)',
                'uk_ai_snapshot_run_uid'=>'UNIQUE KEY `uk_ai_snapshot_run_uid` (`run_uid`)',
                'idx_ai_snapshot_run_date'=>'KEY `idx_ai_snapshot_run_date` (`snapshot_date`,`started_at`)',
                'idx_ai_snapshot_run_status'=>'KEY `idx_ai_snapshot_run_status` (`run_status`,`started_at`)'
            ), $updated);
            self::ensureOwnedTable($pdo, self::SNAPSHOT_TABLE, self::snapshotColumnDefinitions(), array(
                'PRIMARY'=>'PRIMARY KEY (`id`)',
                'uk_ai_daily_snapshot'=>'UNIQUE KEY `uk_ai_daily_snapshot` (`snapshot_date`,`project_id`)',
                'idx_ai_snapshot_project_date'=>'KEY `idx_ai_snapshot_project_date` (`project_id`,`snapshot_date`)',
                'idx_ai_snapshot_target_month'=>'KEY `idx_ai_snapshot_target_month` (`target_ym`,`snapshot_date`)',
                'idx_ai_snapshot_run'=>'KEY `idx_ai_snapshot_run` (`run_id`)',
                'idx_ai_snapshot_status'=>'KEY `idx_ai_snapshot_status` (`project_status_snapshot`,`snapshot_date`)'
            ), $updated);
            self::clearSchemaCache($pdo);
            if (!self::isInstalled($pdo)) throw new Exception('snapshot schema incomplete');
            return array(
                'ok'=>true,
                'message'=>count($created) > 0 ? '일일 스냅샷 전용 테이블을 설치했습니다.' : '일일 스냅샷 전용 테이블 구조를 확인했습니다.',
                'created'=>$created,
                'updated'=>$updated
            );
        } catch (Exception $e) {
            return array('ok'=>false, 'message'=>'일일 스냅샷 테이블 설치 또는 확인에 실패했습니다.', 'created'=>$created, 'updated'=>$updated);
        }
    }

    public static function isInstalled($pdo = null)
    {
        $pdo = self::pdo($pdo);
        if (!$pdo) return false;
        $key = self::connectionKey($pdo);
        if (array_key_exists($key, self::$installedCache)) return self::$installedCache[$key];
        $required = array(
            self::RUN_TABLE=>array('columns'=>self::requiredRunColumns(), 'indexes'=>self::requiredRunIndexes()),
            self::SNAPSHOT_TABLE=>array('columns'=>self::requiredSnapshotColumns(), 'indexes'=>self::requiredSnapshotIndexes())
        );
        foreach ($required as $table=>$schema) {
            if (!self::tableExists($pdo, $table)) {
                self::$installedCache[$key] = false;
                return false;
            }
            foreach ($schema['columns'] as $column) {
                if (!self::columnExists($pdo, $table, $column)) {
                    self::$installedCache[$key] = false;
                    return false;
                }
            }
            $indexes = self::getTableIndexes($pdo, $table);
            foreach ($schema['indexes'] as $index) {
                if (!isset($indexes[$index])) {
                    self::$installedCache[$key] = false;
                    return false;
                }
            }
        }
        self::$installedCache[$key] = true;
        return true;
    }

    private static function tableSchemaStatus($pdo, $table, $requiredColumns, $requiredIndexes)
    {
        $result = array('table_exists'=>false, 'installed'=>false, 'missing_columns'=>array(), 'missing_indexes'=>array());
        $result['table_exists'] = self::tableExists($pdo, $table);
        if (!$result['table_exists']) {
            $result['missing_columns'] = $requiredColumns;
            $result['missing_indexes'] = $requiredIndexes;
            return $result;
        }
        foreach ($requiredColumns as $column) if (!self::columnExists($pdo, $table, $column)) $result['missing_columns'][] = $column;
        $indexes = self::getTableIndexes($pdo, $table);
        foreach ($requiredIndexes as $index) if (!isset($indexes[$index])) $result['missing_indexes'][] = $index;
        $result['installed'] = count($result['missing_columns']) === 0 && count($result['missing_indexes']) === 0;
        return $result;
    }

    public static function schemaStatus($pdo = null)
    {
        $pdo = self::pdo($pdo);
        $result = array(
            'db_available'=>(bool)$pdo,
            'run'=>array('table_exists'=>false,'installed'=>false,'missing_columns'=>array(),'missing_indexes'=>array()),
            'snapshot'=>array('table_exists'=>false,'installed'=>false,'missing_columns'=>array(),'missing_indexes'=>array()),
            'installed'=>false, 'snapshot_date_count'=>0, 'project_count'=>0, 'snapshot_row_count'=>0,
            'latest_snapshot_date'=>'', 'last_captured_at'=>'', 'latest_run'=>array()
        );
        if (!$pdo) return $result;
        $result['run'] = self::tableSchemaStatus($pdo, self::RUN_TABLE, self::requiredRunColumns(), self::requiredRunIndexes());
        $result['snapshot'] = self::tableSchemaStatus($pdo, self::SNAPSHOT_TABLE, self::requiredSnapshotColumns(), self::requiredSnapshotIndexes());
        $result['installed'] = !empty($result['run']['installed']) && !empty($result['snapshot']['installed']);
        if (!empty($result['snapshot']['installed'])) {
            try {
                $row = $pdo->query('SELECT COUNT(*) AS row_count, COUNT(DISTINCT snapshot_date) AS date_count, COUNT(DISTINCT project_id) AS project_count, MAX(snapshot_date) AS latest_snapshot_date, MAX(last_captured_at) AS last_captured_at FROM `' . self::SNAPSHOT_TABLE . '`')->fetch(PDO::FETCH_ASSOC);
                if (is_array($row)) {
                    $result['snapshot_row_count'] = isset($row['row_count']) ? (int)$row['row_count'] : 0;
                    $result['snapshot_date_count'] = isset($row['date_count']) ? (int)$row['date_count'] : 0;
                    $result['project_count'] = isset($row['project_count']) ? (int)$row['project_count'] : 0;
                    $result['latest_snapshot_date'] = isset($row['latest_snapshot_date']) && $row['latest_snapshot_date'] !== null ? (string)$row['latest_snapshot_date'] : '';
                    $result['last_captured_at'] = isset($row['last_captured_at']) && $row['last_captured_at'] !== null ? (string)$row['last_captured_at'] : '';
                }
            } catch (Exception $e) {
            }
        }
        if (!empty($result['run']['installed'])) {
            try {
                $st = $pdo->query('SELECT id,run_uid,snapshot_date,target_ym,trigger_type,run_status,project_count,success_count,failure_count,monthly_input_total,cumulative_input_total,started_at,finished_at,error_summary FROM `' . self::RUN_TABLE . '` ORDER BY started_at DESC,id DESC LIMIT 1');
                $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
                $result['latest_run'] = is_array($row) ? $row : array();
            } catch (Exception $e) {
                $result['latest_run'] = array();
            }
        }
        return $result;
    }

    private static function generateRunUid()
    {
        $random = uniqid((string)mt_rand(), true) . microtime(true);
        if (function_exists('openssl_random_pseudo_bytes')) {
            $bytes = @openssl_random_pseudo_bytes(24);
            if ($bytes !== false) $random .= bin2hex($bytes);
        }
        return 'snap_' . self::businessNow('YmdHis') . '_' . substr(hash('sha256', $random), 0, 40);
    }

    private static function normalizeTrigger($value)
    {
        $value = strtoupper(trim((string)$value));
        return in_array($value, array('MANUAL','CLI','SYSTEM'), true) ? $value : 'SYSTEM';
    }

    private static function actor($trigger)
    {
        if ($trigger !== 'MANUAL') return array('id'=>null, 'name'=>null);
        $user = Auth::user();
        $id = is_array($user) && isset($user['id']) && is_numeric($user['id']) ? (int)$user['id'] : 0;
        $name = trim((string)Auth::userName());
        if (function_exists('mb_substr')) $name = mb_substr($name, 0, 100, 'UTF-8');
        else $name = substr($name, 0, 100);
        return array('id'=>$id > 0 ? $id : null, 'name'=>$name !== '' ? $name : null);
    }

    private static function encodeData($value)
    {
        if (!is_array($value)) return null;
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : null;
    }

    public static function decodeData($value)
    {
        if (!is_string($value) || trim($value) === '') return array();
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : array();
    }

    private static function acquireLock($pdo, $snapshotDate)
    {
        $name = 'cpms_ai_daily_snapshot_' . str_replace('-', '', (string)$snapshotDate);
        try {
            $st = $pdo->prepare('SELECT GET_LOCK(:lock_name, 0)');
            $st->bindValue(':lock_name', $name, PDO::PARAM_STR);
            $st->execute();
            return array('ok'=>((int)$st->fetchColumn() === 1), 'name'=>$name, 'supported'=>true);
        } catch (Exception $e) {
            return array('ok'=>true, 'name'=>'', 'supported'=>false);
        }
    }

    private static function releaseLock($pdo, $lock)
    {
        if (!$pdo || !is_array($lock) || empty($lock['supported']) || empty($lock['name'])) return;
        try {
            $st = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $st->bindValue(':lock_name', (string)$lock['name'], PDO::PARAM_STR);
            $st->execute();
        } catch (Exception $e) {
        }
    }

    private static function clearStaleRuns($pdo, $snapshotDate)
    {
        try {
            $sql = "UPDATE `" . self::RUN_TABLE . "` SET run_status='FAILED',finished_at=NOW(),error_summary='실행 제한시간을 초과해 실패 처리했습니다.' WHERE snapshot_date=:snapshot_date AND run_status='RUNNING' AND started_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)";
            $st = $pdo->prepare($sql);
            $st->bindValue(':snapshot_date', $snapshotDate, PDO::PARAM_STR);
            $st->execute();
        } catch (Exception $e) {
        }
    }

    private static function hasRecentRunning($pdo, $snapshotDate)
    {
        try {
            $sql = "SELECT id FROM `" . self::RUN_TABLE . "` WHERE snapshot_date=:snapshot_date AND run_status='RUNNING' AND started_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) ORDER BY id DESC LIMIT 1";
            $st = $pdo->prepare($sql);
            $st->bindValue(':snapshot_date', $snapshotDate, PDO::PARAM_STR);
            $st->execute();
            return $st->fetchColumn() !== false;
        } catch (Exception $e) {
            return false;
        }
    }

    private static function createRun($pdo, $snapshotDate, $targetYm, $trigger, $projectCount)
    {
        $actor = self::actor($trigger);
        $now = self::businessNow('Y-m-d H:i:s');
        $sql = 'INSERT INTO `' . self::RUN_TABLE . '` (run_uid,snapshot_date,target_ym,trigger_type,run_status,project_count,success_count,failure_count,monthly_input_total,cumulative_input_total,actor_employee_id,actor_name,started_at,finished_at,error_summary,created_at) VALUES (:run_uid,:snapshot_date,:target_ym,:trigger_type,\'RUNNING\',:project_count,0,0,0,0,:actor_employee_id,:actor_name,:started_at,NULL,NULL,:created_at)';
        $st = $pdo->prepare($sql);
        $st->execute(array(
            ':run_uid'=>self::generateRunUid(), ':snapshot_date'=>$snapshotDate, ':target_ym'=>$targetYm,
            ':trigger_type'=>$trigger, ':project_count'=>(int)$projectCount,
            ':actor_employee_id'=>$actor['id'], ':actor_name'=>$actor['name'], ':started_at'=>$now, ':created_at'=>$now
        ));
        return (int)$pdo->lastInsertId();
    }

    private static function finishRun($pdo, $runId, $status, $projectCount, $successCount, $failureCount, $monthlyTotal, $cumulativeTotal, $errorSummary)
    {
        $allowed = array('COMPLETED'=>true, 'PARTIAL'=>true, 'FAILED'=>true);
        if (!isset($allowed[$status])) $status = 'FAILED';
        $sql = 'UPDATE `' . self::RUN_TABLE . '` SET run_status=:run_status,project_count=:project_count,success_count=:success_count,failure_count=:failure_count,monthly_input_total=:monthly_total,cumulative_input_total=:cumulative_total,finished_at=:finished_at,error_summary=:error_summary WHERE id=:id';
        $st = $pdo->prepare($sql);
        $st->execute(array(
            ':run_status'=>$status, ':project_count'=>(int)$projectCount, ':success_count'=>(int)$successCount,
            ':failure_count'=>(int)$failureCount, ':monthly_total'=>number_format((float)$monthlyTotal, 2, '.', ''),
            ':cumulative_total'=>number_format((float)$cumulativeTotal, 2, '.', ''), ':finished_at'=>self::businessNow('Y-m-d H:i:s'),
            ':error_summary'=>$errorSummary !== '' ? $errorSummary : null, ':id'=>(int)$runId
        ));
    }

    public static function loadProjects($pdo, $targetYm)
    {
        $pdo = self::pdo($pdo);
        if (!$pdo || self::validYm($targetYm) === '' || !function_exists('cpms_company_profit_load_projects')) return array();
        $filters = array(
            'scope'=>'month', 'start_month'=>$targetYm, 'end_month'=>$targetYm,
            'status'=>'', 'q'=>'', 'project_id'=>0
        );
        $projects = cpms_company_profit_load_projects($pdo, $filters);
        $result = array();
        $seen = array();
        foreach ($projects as $project) {
            $id = isset($project['id']) ? (int)$project['id'] : 0;
            $name = isset($project['name']) ? trim((string)$project['name']) : '';
            $status = isset($project['status']) ? trim((string)$project['status']) : '';
            if ($id <= 0 || $name === '' || strpos($name, '(가제)') === 0 || strpos($name, '(테스트)') === 0) continue;
            if ($status === '삭제' || $status === '테스트') continue;
            $result[] = $project;
            $seen[$id] = true;
        }

        $additionalIds = self::projectIdsWithTargetData($pdo, $targetYm);
        foreach ($additionalIds as $additionalId) {
            if (isset($seen[$additionalId])) unset($additionalIds[$additionalId]);
        }
        if (count($additionalIds) > 0 && self::tableExists($pdo, 'cpms_projects')) {
            $placeholders = array();
            $params = array();
            foreach (array_values($additionalIds) as $index=>$projectId) {
                $key = ':project_id_' . $index;
                $placeholders[] = $key;
                $params[$key] = (int)$projectId;
            }
            try {
                $settlementSelect = self::columnExists($pdo, 'cpms_projects', 'settlement_completed_at')
                    ? ', settlement_completed_at'
                    : ', NULL AS settlement_completed_at';
                $sql = "SELECT id,name,client,contractor,location,start_date,end_date,contract_amount,status" . $settlementSelect
                    . " FROM cpms_projects WHERE id IN (" . implode(',', $placeholders) . ") AND name NOT LIKE '(가제)%' ORDER BY id DESC";
                $st = $pdo->prepare($sql);
                foreach ($params as $key=>$value) $st->bindValue($key, $value, PDO::PARAM_INT);
                $st->execute();
                $extraProjects = $st->fetchAll(PDO::FETCH_ASSOC);
                if (is_array($extraProjects)) {
                    foreach ($extraProjects as $project) {
                        $id = isset($project['id']) ? (int)$project['id'] : 0;
                        $name = isset($project['name']) ? trim((string)$project['name']) : '';
                        $status = isset($project['status']) ? trim((string)$project['status']) : '';
                        if ($id <= 0 || $name === '' || isset($seen[$id]) || strpos($name, '(가제)') === 0 || strpos($name, '(테스트)') === 0) continue;
                        if ($status === '삭제' || $status === '테스트') continue;
                        $result[] = $project;
                        $seen[$id] = true;
                    }
                }
            } catch (Exception $e) {
                error_log('[AiDailySnapshot] additional project load failed');
            }
        }
        return $result;
    }

    private static function addProjectIdsByDate($pdo, $table, $dateColumn, $startDate, $endDate, &$ids)
    {
        if (!self::validIdentifier($table) || !self::validIdentifier($dateColumn)) return;
        if (!self::tableExists($pdo, $table) || !self::columnExists($pdo, $table, 'project_id') || !self::columnExists($pdo, $table, $dateColumn)) return;
        try {
            $sql = 'SELECT DISTINCT project_id FROM `' . $table . '` WHERE `' . $dateColumn . '` BETWEEN :start_date AND :end_date AND project_id IS NOT NULL AND project_id>0';
            $st = $pdo->prepare($sql);
            $st->bindValue(':start_date', $startDate, PDO::PARAM_STR);
            $st->bindValue(':end_date', $endDate, PDO::PARAM_STR);
            $st->execute();
            while (($projectId = $st->fetchColumn()) !== false) {
                $projectId = (int)$projectId;
                if ($projectId > 0) $ids[$projectId] = $projectId;
            }
        } catch (Exception $e) {
        }
    }

    private static function addProjectIdsByMonth($pdo, $table, $monthColumn, $targetYm, &$ids, $extraWhere)
    {
        if (!self::validIdentifier($table) || !self::validIdentifier($monthColumn)) return;
        if (!self::tableExists($pdo, $table) || !self::columnExists($pdo, $table, 'project_id') || !self::columnExists($pdo, $table, $monthColumn)) return;
        try {
            $sql = 'SELECT DISTINCT project_id FROM `' . $table . '` WHERE `' . $monthColumn . '`=:target_ym AND project_id IS NOT NULL AND project_id>0';
            if ($extraWhere !== '') $sql .= ' AND ' . $extraWhere;
            $st = $pdo->prepare($sql);
            $st->bindValue(':target_ym', $targetYm, PDO::PARAM_STR);
            $st->execute();
            while (($projectId = $st->fetchColumn()) !== false) {
                $projectId = (int)$projectId;
                if ($projectId > 0) $ids[$projectId] = $projectId;
            }
        } catch (Exception $e) {
        }
    }

    private static function projectIdsWithTargetData($pdo, $targetYm)
    {
        $ids = array();
        if (self::tableExists($pdo, 'cpms_projects') && self::columnExists($pdo, 'cpms_projects', 'id') && self::columnExists($pdo, 'cpms_projects', 'status')) {
            try {
                $stCurrent = $pdo->prepare("SELECT id FROM cpms_projects WHERE status IN (:status_active,:status_contract,:status_bid) AND id>0");
                $stCurrent->execute(array(
                    ':status_active'=>'진행중',
                    ':status_contract'=>'계약중',
                    ':status_bid'=>'입찰 진행중'
                ));
                while (($projectId = $stCurrent->fetchColumn()) !== false) {
                    $projectId = (int)$projectId;
                    if ($projectId > 0) $ids[$projectId] = $projectId;
                }
            } catch (Exception $e) {
            }
        }
        $laborPeriod = CostChangeService::periodForYm('labor', $targetYm);
        $outsourcingPeriod = CostChangeService::periodForYm('outsourcing', $targetYm);
        $costPeriod = CostChangeService::periodForYm('material', $targetYm);
        self::addProjectIdsByDate($pdo, 'cpms_material_usage', 'use_date', $costPeriod['start'], $costPeriod['end'], $ids);
        self::addProjectIdsByDate($pdo, 'cpms_equipment_usage', 'use_date', $costPeriod['start'], $costPeriod['end'], $ids);
        self::addProjectIdsByDate($pdo, 'cpms_outsourcing_costs', 'expense_date', $outsourcingPeriod['start'], $outsourcingPeriod['end'], $ids);
        self::addProjectIdsByDate($pdo, 'cpms_progress_billings', 'progress_date', $laborPeriod['start'], $laborPeriod['end'], $ids);
        self::addProjectIdsByDate($pdo, 'cpms_schedule_task_item_progress', 'work_date', $laborPeriod['start'], $laborPeriod['end'], $ids);
        self::addProjectIdsByDate($pdo, 'cpms_schedule_progress', 'work_date', $laborPeriod['start'], $laborPeriod['end'], $ids);
        self::addProjectIdsByMonth($pdo, 'cpms_monthly_recognized', 'ym', $targetYm, $ids, '');
        self::addProjectIdsByMonth($pdo, 'cpms_labor_force_adjustments', 'month', $targetYm, $ids, '');
        self::addProjectIdsByMonth($pdo, 'cpms_project_monthly_deductions', 'ym', $targetYm, $ids, '');
        $overrideWhere = self::columnExists($pdo, 'cpms_labor_gongsu_overrides', 'status') ? "status='applied'" : '';
        self::addProjectIdsByMonth($pdo, 'cpms_labor_gongsu_overrides', 'month', $targetYm, $ids, $overrideWhere);
        $metaWhere = self::columnExists($pdo, 'cpms_cost_record_meta', 'is_deleted') ? 'COALESCE(is_deleted,0)=0' : '';
        self::addProjectIdsByMonth($pdo, 'cpms_cost_record_meta', 'settlement_ym', $targetYm, $ids, $metaWhere);
        return $ids;
    }

    private static function sourceState($pdo)
    {
        $attendanceAvailable = null;
        if (function_exists('cpms_load_attendance_pdo')) {
            try {
                $attendancePdo = cpms_load_attendance_pdo();
                $attendanceAvailable = $attendancePdo instanceof PDO;
            } catch (Exception $e) {
                $attendanceAvailable = false;
            }
        }
        $checks = array(
            'material'=>self::tableExists($pdo, 'cpms_material_usage') && self::tableExists($pdo, 'cpms_material_items'),
            'equipment'=>self::tableExists($pdo, 'cpms_equipment_usage') && self::tableExists($pdo, 'cpms_equipment_items'),
            'outsourcing'=>self::tableExists($pdo, 'cpms_outsourcing_costs'),
            'labor_workers'=>self::tableExists($pdo, 'cpms_project_labor_workers'),
            'labor_force'=>self::tableExists($pdo, 'cpms_labor_force_adjustments'),
            'sales_confirmed'=>self::tableExists($pdo, 'cpms_progress_billings') || self::tableExists($pdo, 'cpms_monthly_recognized'),
            'sales_expected'=>self::tableExists($pdo, 'cpms_schedule_tasks') && self::tableExists($pdo, 'cpms_project_unit_prices'),
            'safety_health'=>function_exists('cpms_safety_cost_project_items_between'),
            'attendance'=>$attendanceAvailable
        );
        $missing = array();
        $warnings = array();
        if (!$checks['material']) $missing[] = 'material';
        if (!$checks['equipment']) $missing[] = 'equipment';
        if (!$checks['outsourcing']) $missing[] = 'outsourcing';
        if (!$checks['labor_workers'] && !$checks['labor_force']) $missing[] = 'labor';
        if (!$checks['sales_confirmed'] && !$checks['sales_expected']) $missing[] = 'sales';
        if (!$checks['safety_health']) $missing[] = 'safety_health';
        if ($attendanceAvailable === false) $warnings[] = '출퇴근 자료 확인 불가';
        return array('checks'=>$checks, 'missing'=>$missing, 'warnings'=>$warnings);
    }

    private static function eventMap($pdo, $projectIds, $snapshotDate, $targetYm)
    {
        $map = array();
        if (!$pdo || count($projectIds) === 0 || !self::tableExists($pdo, self::EVENT_TABLE)) return $map;
        if (!self::columnExists($pdo, self::EVENT_TABLE, 'project_id') || !self::columnExists($pdo, self::EVENT_TABLE, 'event_at')) return $map;
        $placeholders = array();
        $params = array(':day_start'=>$snapshotDate . ' 00:00:00', ':day_end'=>$snapshotDate . ' 23:59:59', ':month_start'=>$targetYm . '-01 00:00:00');
        try {
            $nextMonthObject = new \DateTime($targetYm . '-01 00:00:00', new \DateTimeZone('Asia/Seoul'));
            $nextMonthObject->modify('+1 month');
            $nextMonth = $nextMonthObject->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            $nextMonth = $targetYm . '-31 23:59:59';
        }
        $params[':month_end'] = $nextMonth;
        foreach (array_values($projectIds) as $index=>$projectId) {
            $key = ':pid' . $index;
            $placeholders[] = $key;
            $params[$key] = (int)$projectId;
        }
        try {
            $sql = "SELECT project_id,COALESCE(SUM(CASE WHEN event_at BETWEEN :day_start AND :day_end THEN 1 ELSE 0 END),0) AS today_count,COALESCE(SUM(CASE WHEN event_at >= :month_start AND event_at < :month_end THEN 1 ELSE 0 END),0) AS month_count,MAX(event_at) AS latest_event_at FROM `" . self::EVENT_TABLE . "` WHERE project_id IN (" . implode(',', $placeholders) . ') GROUP BY project_id';
            $st = $pdo->prepare($sql);
            foreach ($params as $key=>$value) $st->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            $st->execute();
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $projectId = isset($row['project_id']) ? (int)$row['project_id'] : 0;
                if ($projectId > 0) $map[$projectId] = $row;
            }
        } catch (Exception $e) {
            return array();
        }
        return $map;
    }

    private static function splitSafetyHealth($projectId, $startDate, $endDate)
    {
        $result = array('safety'=>0.0, 'health'=>0.0, 'other'=>0.0);
        if (!function_exists('cpms_safety_cost_project_items_between') || !function_exists('cpms_safety_cost_row_amount')) return $result;
        $rows = cpms_safety_cost_project_items_between((int)$projectId, $startDate, $endDate);
        foreach ($rows as $row) {
            $category = isset($row['category']) ? trim((string)$row['category']) : '';
            $amount = (float)cpms_safety_cost_row_amount($row);
            if ($category === '검진비') $result['health'] += $amount;
            else if ($category === '기타 안전·보건 비용') $result['other'] += $amount;
            else $result['safety'] += $amount;
        }
        return $result;
    }

    private static function cleanProjectDate($value)
    {
        $date = self::validDate($value);
        return $date !== '' ? $date : null;
    }

    public static function calculateProject($pdo, $project, $snapshotDate, $eventRow, $sourceState)
    {
        $projectId = isset($project['id']) ? (int)$project['id'] : 0;
        $projectName = isset($project['name']) ? trim((string)$project['name']) : '';
        $snapshotDate = self::validDate($snapshotDate);
        if (!$pdo || $projectId <= 0 || $projectName === '' || $snapshotDate === '') throw new Exception('project calculation unavailable');
        $targetYm = substr($snapshotDate, 0, 7);
        $startDate = self::cleanProjectDate(isset($project['start_date']) ? $project['start_date'] : '');
        $startYm = $startDate !== null ? substr($startDate, 0, 7) : $targetYm;
        if ($startYm > $targetYm) $startYm = $targetYm;
        $months = function_exists('cpms_company_profit_months_between') ? cpms_company_profit_months_between($startYm, $targetYm) : array($targetYm);
        if (count($months) === 0) $months = array($targetYm);
        if (!function_exists('cpms_company_profit_project_summary')) throw new Exception('project summary unavailable');
        $summary = cpms_company_profit_project_summary($pdo, $project, $months);
        $monthly = isset($summary['monthly'][$targetYm]) && is_array($summary['monthly'][$targetYm]) ? $summary['monthly'][$targetYm] : array();
        if (count($monthly) === 0) throw new Exception('monthly summary unavailable');

        $costPeriod = CostChangeService::periodForYm('material', $targetYm);
        $safetySplit = self::splitSafetyHealth($projectId, $costPeriod['start'], $costPeriod['end']);
        $labor = isset($monthly['labor']) ? (float)$monthly['labor'] : 0.0;
        $outsourcing = isset($monthly['outsourcing']) ? (float)$monthly['outsourcing'] : 0.0;
        $purchase = isset($monthly['purchase_cost']) ? (float)$monthly['purchase_cost'] : 0.0;
        $material = isset($monthly['material_cost']) ? (float)$monthly['material_cost'] : 0.0;
        $equipment = isset($monthly['equipment']) ? (float)$monthly['equipment'] : 0.0;
        $otherExpense = isset($monthly['other_cost']) ? (float)$monthly['other_cost'] : 0.0;
        $safety = (float)$safetySplit['safety'];
        $health = (float)$safetySplit['health'];
        $other = (isset($monthly['deduction']) ? (float)$monthly['deduction'] : 0.0) + (float)$safetySplit['other'];
        $monthlyInput = isset($monthly['input_cost']) ? (float)$monthly['input_cost'] : 0.0;
        $componentTotal = $labor + $outsourcing + $purchase + $material + $equipment + $otherExpense + $safety + $health + $other;
        $warnings = isset($sourceState['warnings']) && is_array($sourceState['warnings']) ? $sourceState['warnings'] : array();
        if (abs($monthlyInput - $componentTotal) > 0.01) {
            $other += ($monthlyInput - $componentTotal);
            $warnings[] = '기존 손익 합계와 세부 구분 차이를 기타 투입비에 반영';
        }
        $monthlySales = isset($monthly['sales']) ? (float)$monthly['sales'] : 0.0;
        $cumulativeSales = isset($summary['sales']) ? (float)$summary['sales'] : 0.0;
        $cumulativeInput = isset($summary['input_cost']) ? (float)$summary['input_cost'] : 0.0;
        $missing = isset($sourceState['missing']) && is_array($sourceState['missing']) ? $sourceState['missing'] : array();
        if ($startDate === null) {
            $missing[] = 'project_start_date';
            $warnings[] = '프로젝트 시작일이 없어 누적 계산 범위를 확인할 수 없음';
        }
        if ($monthlySales <= 0 && $monthlyInput > 0) $warnings[] = '매출 자료 없음';
        $missing = array_values(array_unique($missing));
        if (!self::tableExists($pdo, self::EVENT_TABLE)) $warnings[] = '통합 비용 이벤트 미설치';
        $dataFlags = array(
            'missing'=>array_values($missing),
            'warnings'=>$warnings,
            'sources'=>array(
                'calculation'=>'CompanyProfitSummaryService',
                'labor'=>'attendance_or_cpms_gongsu',
                'material'=>'cpms_material_usage',
                'equipment'=>'cpms_equipment_usage',
                'outsourcing'=>'labor_allocation_and_cpms_outsourcing_costs',
                'sales'=>'confirmed_sales_first',
                'safety_health'=>'safety_cost_store'
            )
        );
        $detail = array(
            'periods'=>array(
                'labor'=>CostChangeService::periodForYm('labor', $targetYm),
                'outsourcing'=>CostChangeService::periodForYm('outsourcing', $targetYm),
                'sales'=>CostChangeService::periodForYm('labor', $targetYm),
                'other_costs'=>$costPeriod
            ),
            'outsourcing'=>array(
                'labor_allocation'=>isset($monthly['labor_outsourcing']) ? (float)$monthly['labor_outsourcing'] : 0.0,
                'direct_input'=>isset($monthly['manual_outsourcing']) ? (float)$monthly['manual_outsourcing'] : 0.0
            ),
            'sales_basis'=>!empty($monthly['has_confirmed']) ? 'confirmed' : 'expected',
            'company_overhead'=>'현장별 미배분 회사 관리비 제외',
            'cumulative_months'=>count($months)
        );
        return array(
            'snapshot_date'=>$snapshotDate, 'target_ym'=>$targetYm, 'project_id'=>$projectId,
            'project_name_snapshot'=>$projectName,
            'project_status_snapshot'=>isset($project['status']) ? trim((string)$project['status']) : '',
            'project_start_date'=>$startDate,
            'project_end_date'=>self::cleanProjectDate(isset($project['end_date']) ? $project['end_date'] : ''),
            'contract_amount'=>isset($project['contract_amount']) ? (float)$project['contract_amount'] : 0.0,
            'monthly_sales_amount'=>$monthlySales, 'cumulative_sales_amount'=>$cumulativeSales,
            'labor_amount'=>$labor, 'outsourcing_amount'=>$outsourcing, 'purchase_amount'=>$purchase,
            'material_amount'=>$material, 'equipment_amount'=>$equipment, 'other_expense_amount'=>$otherExpense,
            'safety_amount'=>$safety, 'health_amount'=>$health, 'other_amount'=>$other,
            'monthly_input_amount'=>$monthlyInput, 'cumulative_input_amount'=>$cumulativeInput,
            'monthly_profit_amount'=>$monthlySales - $monthlyInput,
            'monthly_cost_rate'=>$monthlySales > 0 ? ($monthlyInput / $monthlySales * 100) : null,
            'cumulative_profit_amount'=>$cumulativeSales - $cumulativeInput,
            'cumulative_cost_rate'=>$cumulativeSales > 0 ? ($cumulativeInput / $cumulativeSales * 100) : null,
            'today_event_count'=>isset($eventRow['today_count']) ? (int)$eventRow['today_count'] : 0,
            'month_event_count'=>isset($eventRow['month_count']) ? (int)$eventRow['month_count'] : 0,
            'latest_event_at'=>isset($eventRow['latest_event_at']) && $eventRow['latest_event_at'] !== null ? (string)$eventRow['latest_event_at'] : null,
            'missing_section_count'=>count($missing), 'data_flags'=>self::encodeData($dataFlags),
            'detail_data'=>self::encodeData($detail)
        );
    }

    public static function saveSnapshot($pdo, $runId, $row)
    {
        $now = self::businessNow('Y-m-d H:i:s');
        $columns = array(
            'run_id','snapshot_date','target_ym','captured_at','project_id','project_name_snapshot','project_status_snapshot',
            'project_start_date','project_end_date','contract_amount','monthly_sales_amount','cumulative_sales_amount',
            'labor_amount','outsourcing_amount','purchase_amount','material_amount','equipment_amount','other_expense_amount',
            'safety_amount','health_amount','other_amount','monthly_input_amount','cumulative_input_amount','monthly_profit_amount',
            'monthly_cost_rate','cumulative_profit_amount','cumulative_cost_rate','today_event_count','month_event_count',
            'latest_event_at','missing_section_count','data_flags','detail_data','first_captured_at','last_captured_at',
            'capture_count','created_at','updated_at'
        );
        $params = array();
        foreach ($columns as $column) $params[] = ':' . $column;
        $updates = array();
        foreach ($columns as $column) {
            if (in_array($column, array('snapshot_date','project_id','first_captured_at','capture_count','created_at'), true)) continue;
            $updates[] = '`' . $column . '`=VALUES(`' . $column . '`)';
        }
        $updates[] = '`capture_count`=`capture_count`+1';
        $sql = 'INSERT INTO `' . self::SNAPSHOT_TABLE . '` (`' . implode('`,`', $columns) . '`) VALUES (' . implode(',', $params) . ') ON DUPLICATE KEY UPDATE ' . implode(',', $updates);
        $values = array();
        foreach ($columns as $column) {
            if ($column === 'run_id') $value = (int)$runId;
            else if ($column === 'captured_at' || $column === 'first_captured_at' || $column === 'last_captured_at' || $column === 'created_at' || $column === 'updated_at') $value = $now;
            else if ($column === 'capture_count') $value = 1;
            else $value = array_key_exists($column, $row) ? $row[$column] : null;
            $values[':' . $column] = $value;
        }
        $st = $pdo->prepare($sql);
        return $st->execute($values);
    }

    public static function captureToday($pdo = null, $triggerType = 'SYSTEM')
    {
        $pdo = self::pdo($pdo);
        $snapshotDate = self::businessToday();
        $targetYm = substr($snapshotDate, 0, 7);
        $empty = array('ok'=>false, 'busy'=>false, 'date'=>$snapshotDate, 'status'=>'FAILED', 'projects'=>0, 'success'=>0, 'failed'=>0, 'monthly_input_total'=>0.0, 'cumulative_input_total'=>0.0, 'message'=>'스냅샷 실행에 실패했습니다.');
        if (!$pdo) {
            $empty['message'] = 'DB 연결을 확인할 수 없습니다.';
            return $empty;
        }
        if (!self::isInstalled($pdo)) {
            $empty['message'] = '일일 스냅샷 테이블을 먼저 설치해주세요.';
            return $empty;
        }
        $trigger = self::normalizeTrigger($triggerType);
        $lock = self::acquireLock($pdo, $snapshotDate);
        if (empty($lock['ok'])) {
            $empty['busy'] = true;
            $empty['message'] = '이미 스냅샷 생성이 진행 중입니다.';
            return $empty;
        }
        $runId = 0;
        try {
            self::clearStaleRuns($pdo, $snapshotDate);
            if (self::hasRecentRunning($pdo, $snapshotDate)) {
                self::releaseLock($pdo, $lock);
                $empty['busy'] = true;
                $empty['message'] = '이미 스냅샷 생성이 진행 중입니다.';
                return $empty;
            }
            $projects = self::loadProjects($pdo, $targetYm);
            $projectCount = count($projects);
            $runId = self::createRun($pdo, $snapshotDate, $targetYm, $trigger, $projectCount);
            $projectIds = array();
            foreach ($projects as $project) if (isset($project['id']) && (int)$project['id'] > 0) $projectIds[] = (int)$project['id'];
            $events = self::eventMap($pdo, $projectIds, $snapshotDate, $targetYm);
            $sources = self::sourceState($pdo);
            $success = 0;
            $failed = 0;
            $monthlyTotal = 0.0;
            $cumulativeTotal = 0.0;
            foreach ($projects as $project) {
                $projectId = isset($project['id']) ? (int)$project['id'] : 0;
                try {
                    $row = self::calculateProject($pdo, $project, $snapshotDate, isset($events[$projectId]) ? $events[$projectId] : array(), $sources);
                    self::saveSnapshot($pdo, $runId, $row);
                    $success++;
                    $monthlyTotal += isset($row['monthly_input_amount']) ? (float)$row['monthly_input_amount'] : 0.0;
                    $cumulativeTotal += isset($row['cumulative_input_amount']) ? (float)$row['cumulative_input_amount'] : 0.0;
                } catch (Exception $e) {
                    $failed++;
                    error_log('[AiDailySnapshot] project aggregation failed');
                }
            }
            if ($failed === 0) $status = 'COMPLETED';
            else if ($success > 0) $status = 'PARTIAL';
            else $status = 'FAILED';
            $errorSummary = $failed > 0 ? '일부 프로젝트 비용 집계 실패: ' . $failed . '건' : '';
            if ($projectCount > 0 && $success === 0 && $failed > 0) $errorSummary = '전체 프로젝트 비용 집계 실패: ' . $failed . '건';
            self::finishRun($pdo, $runId, $status, $projectCount, $success, $failed, $monthlyTotal, $cumulativeTotal, $errorSummary);
            self::releaseLock($pdo, $lock);
            return array(
                'ok'=>$status === 'COMPLETED' || $status === 'PARTIAL', 'busy'=>false, 'date'=>$snapshotDate,
                'status'=>$status, 'projects'=>$projectCount, 'success'=>$success, 'failed'=>$failed,
                'monthly_input_total'=>$monthlyTotal, 'cumulative_input_total'=>$cumulativeTotal,
                'message'=>$status === 'COMPLETED' ? '오늘 스냅샷 저장을 완료했습니다.' : ($status === 'PARTIAL' ? '일부 현장을 제외하고 스냅샷을 저장했습니다.' : '스냅샷 집계에 실패했습니다.')
            );
        } catch (Exception $e) {
            if ($runId > 0) {
                try { self::finishRun($pdo, $runId, 'FAILED', 0, 0, 0, 0, 0, '스냅샷 실행 중 오류가 발생했습니다.'); } catch (Exception $ignored) {}
            }
            error_log('[AiDailySnapshot] capture failed');
            self::releaseLock($pdo, $lock);
            return $empty;
        }
    }

    public static function latestSnapshotDate($pdo = null)
    {
        $pdo = self::pdo($pdo);
        if (!$pdo || !self::isInstalled($pdo)) return '';
        try {
            $value = $pdo->query('SELECT MAX(snapshot_date) FROM `' . self::SNAPSHOT_TABLE . '`')->fetchColumn();
            return $value !== false && $value !== null ? (string)$value : '';
        } catch (Exception $e) {
            return '';
        }
    }

    private static function buildHistoryWhere($filters, &$params)
    {
        $filters = is_array($filters) ? $filters : array();
        $params = array();
        $where = array('1=1');
        $date = self::validDate(isset($filters['snapshot_date']) ? $filters['snapshot_date'] : '');
        $ym = self::validYm(isset($filters['target_ym']) ? $filters['target_ym'] : '');
        if ($date !== '') { $where[] = 's.snapshot_date=:snapshot_date'; $params[':snapshot_date'] = $date; }
        if ($ym !== '') { $where[] = 's.target_ym=:target_ym'; $params[':target_ym'] = $ym; }
        if (isset($filters['project_id']) && (int)$filters['project_id'] > 0) { $where[] = 's.project_id=:project_id'; $params[':project_id'] = (int)$filters['project_id']; }
        if (isset($filters['project_status']) && trim((string)$filters['project_status']) !== '') { $where[] = 's.project_status_snapshot=:project_status'; $params[':project_status'] = trim((string)$filters['project_status']); }
        if (isset($filters['q']) && trim((string)$filters['q']) !== '') { $where[] = 's.project_name_snapshot LIKE :q'; $params[':q'] = '%' . trim((string)$filters['q']) . '%'; }
        return implode(' AND ', $where);
    }

    private static function bindValues($st, $params)
    {
        foreach ($params as $key=>$value) $st->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }

    public static function countSnapshots($pdo, $filters)
    {
        $pdo = self::pdo($pdo);
        if (!$pdo || !self::isInstalled($pdo)) return 0;
        $params = array();
        $where = self::buildHistoryWhere($filters, $params);
        try {
            $st = $pdo->prepare('SELECT COUNT(*) FROM `' . self::SNAPSHOT_TABLE . '` s WHERE ' . $where);
            self::bindValues($st, $params);
            $st->execute();
            return (int)$st->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    public static function listSnapshots($pdo, $filters, $page, $perPage)
    {
        $pdo = self::pdo($pdo);
        $page = max(1, (int)$page);
        $perPage = max(1, min(100, (int)$perPage));
        if (!$pdo || !self::isInstalled($pdo)) return array();
        $params = array();
        $where = self::buildHistoryWhere($filters, $params);
        $offset = ($page - 1) * $perPage;
        try {
            $sql = 'SELECT s.id,s.snapshot_date,s.target_ym,s.captured_at,s.project_id,s.project_name_snapshot,s.project_status_snapshot,s.project_start_date,s.project_end_date,s.contract_amount,s.monthly_sales_amount,s.cumulative_sales_amount,s.labor_amount,s.outsourcing_amount,s.purchase_amount,s.material_amount,s.equipment_amount,s.other_expense_amount,s.safety_amount,s.health_amount,s.other_amount,s.monthly_input_amount,s.cumulative_input_amount,s.monthly_profit_amount,s.monthly_cost_rate,s.cumulative_profit_amount,s.cumulative_cost_rate,s.today_event_count,s.month_event_count,s.latest_event_at,s.missing_section_count,s.data_flags,s.detail_data,s.first_captured_at,s.last_captured_at,s.capture_count,(SELECT p.monthly_input_amount FROM `' . self::SNAPSHOT_TABLE . '` p WHERE p.project_id=s.project_id AND p.snapshot_date<s.snapshot_date ORDER BY p.snapshot_date DESC LIMIT 1) AS previous_monthly_input_amount FROM `' . self::SNAPSHOT_TABLE . '` s WHERE ' . $where . ' ORDER BY s.project_name_snapshot ASC,s.project_id ASC LIMIT :limit OFFSET :offset';
            $st = $pdo->prepare($sql);
            self::bindValues($st, $params);
            $st->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $st->bindValue(':offset', $offset, PDO::PARAM_INT);
            $st->execute();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : array();
        } catch (Exception $e) {
            return array();
        }
    }

    public static function historySummary($pdo, $filters)
    {
        $empty = array('project_count'=>0,'monthly_input_total'=>0,'cumulative_input_total'=>0,'monthly_sales_total'=>0,'monthly_profit_total'=>0,'event_count'=>0,'missing_project_count'=>0,'last_captured_at'=>'');
        $pdo = self::pdo($pdo);
        if (!$pdo || !self::isInstalled($pdo)) return $empty;
        $params = array();
        $where = self::buildHistoryWhere($filters, $params);
        try {
            $sql = 'SELECT COUNT(*) AS project_count,COALESCE(SUM(s.monthly_input_amount),0) AS monthly_input_total,COALESCE(SUM(s.cumulative_input_amount),0) AS cumulative_input_total,COALESCE(SUM(s.monthly_sales_amount),0) AS monthly_sales_total,COALESCE(SUM(s.monthly_profit_amount),0) AS monthly_profit_total,COALESCE(SUM(s.today_event_count),0) AS event_count,COALESCE(SUM(CASE WHEN s.missing_section_count>0 THEN 1 ELSE 0 END),0) AS missing_project_count,MAX(s.last_captured_at) AS last_captured_at FROM `' . self::SNAPSHOT_TABLE . '` s WHERE ' . $where;
            $st = $pdo->prepare($sql);
            self::bindValues($st, $params);
            $st->execute();
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? array_merge($empty, $row) : $empty;
        } catch (Exception $e) {
            return $empty;
        }
    }

    public static function historyOptions($pdo = null)
    {
        $result = array('projects'=>array(), 'statuses'=>array(), 'months'=>array(), 'dates'=>array());
        $pdo = self::pdo($pdo);
        if (!$pdo || !self::isInstalled($pdo)) return $result;
        try {
            $result['projects'] = $pdo->query('SELECT project_id,MAX(project_name_snapshot) AS project_name FROM `' . self::SNAPSHOT_TABLE . '` GROUP BY project_id ORDER BY project_name ASC,project_id ASC')->fetchAll(PDO::FETCH_ASSOC);
            $result['statuses'] = $pdo->query("SELECT DISTINCT project_status_snapshot AS status FROM `" . self::SNAPSHOT_TABLE . "` WHERE project_status_snapshot IS NOT NULL AND project_status_snapshot<>'' ORDER BY project_status_snapshot ASC")->fetchAll(PDO::FETCH_ASSOC);
            $result['months'] = $pdo->query('SELECT DISTINCT target_ym FROM `' . self::SNAPSHOT_TABLE . '` ORDER BY target_ym DESC')->fetchAll(PDO::FETCH_COLUMN);
            $result['dates'] = $pdo->query('SELECT DISTINCT snapshot_date FROM `' . self::SNAPSHOT_TABLE . '` ORDER BY snapshot_date DESC LIMIT 366')->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
        }
        return $result;
    }
}
