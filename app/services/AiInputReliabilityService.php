<?php
/**
 * 투입비 입력 신뢰도 및 입력 지연 분석 서비스.
 * 저장된 예측·스냅샷·통합 비용 이벤트만 사용한다.
 * PHP 5.6 / MySQL 5.6 compatible.
 */

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;
use Exception;
use PDO;

require_once __DIR__ . '/CostChangeService.php';
require_once __DIR__ . '/CostDataEventService.php';
require_once __DIR__ . '/AiDailySnapshotService.php';
require_once __DIR__ . '/AiMonthlyForecastService.php';

class AiInputReliabilityService
{
    const RUN_TABLE = 'cpms_ai_reliability_runs';
    const RESULT_TABLE = 'cpms_ai_input_reliability';
    const FORECAST_TABLE = 'cpms_ai_monthly_forecasts';
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
        return AiDailySnapshotService::validDate($value);
    }

    public static function validYm($value)
    {
        return AiDailySnapshotService::validYm($value);
    }

    public static function businessToday()
    {
        return CostChangeService::businessToday();
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
            $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table_name');
            if (!$st) {
                self::$tableCache[$key] = false;
                return false;
            }
            $st->bindValue(':table_name', $table, PDO::PARAM_STR);
            if (!$st->execute()) {
                self::$tableCache[$key] = false;
                return false;
            }
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
            foreach ($rows as $row) if (isset($row['Field'])) $columns[(string)$row['Field']] = true;
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
            foreach ($rows as $row) if (isset($row['Key_name'])) $indexes[(string)$row['Key_name']] = true;
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
            'id','run_uid','analysis_date','target_ym','forecast_date','snapshot_date','trigger_type','run_status',
            'project_count','success_count','insufficient_count','failure_count','average_score','high_count',
            'good_count','caution_count','low_count','actor_employee_id','actor_name','started_at','finished_at',
            'error_summary','created_at'
        );
    }

    public static function requiredRunIndexes()
    {
        return array('PRIMARY','uk_ai_reliability_run_uid','idx_ai_reliability_run_date','idx_ai_reliability_run_status');
    }

    public static function requiredResultColumns()
    {
        return array(
            'id','run_id','analysis_date','target_ym','forecast_date','snapshot_date','project_id',
            'project_name_snapshot','project_status_snapshot','current_input_amount','forecast_input_amount',
            'forecast_low_amount','forecast_high_amount','reliability_score','reliability_grade','data_status',
            'completeness_score','freshness_score','history_score','input_timing_score','stability_score',
            'expected_category_count','observed_category_count','missing_category_count','snapshot_age_days',
            'latest_event_at','latest_event_age_days','event_count_30d','event_count_90d','average_input_lag_days',
            'late_input_rate','input_lag_sample_count','forecast_range_rate','forecast_change_rate',
            'history_month_count','available_weight','category_reliability_data','reason_data','warning_data',
            'actor_input_data','first_created_at','last_calculated_at','calculation_count','created_at','updated_at'
        );
    }

    public static function requiredResultIndexes()
    {
        return array(
            'PRIMARY','uk_ai_input_reliability','idx_ai_reliability_project','idx_ai_reliability_target_month',
            'idx_ai_reliability_grade','idx_ai_reliability_score','idx_ai_reliability_run'
        );
    }

    public static function createRunTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS cpms_ai_reliability_runs (\n"
            . "    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,\n"
            . "    run_uid VARCHAR(64) NOT NULL,\n"
            . "    analysis_date DATE NOT NULL,\n"
            . "    target_ym CHAR(7) NOT NULL,\n"
            . "    forecast_date DATE NULL,\n"
            . "    snapshot_date DATE NULL,\n"
            . "    trigger_type VARCHAR(20) NOT NULL,\n"
            . "    run_status VARCHAR(20) NOT NULL,\n"
            . "    project_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    success_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    insufficient_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    failure_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    average_score DECIMAL(6,2) NULL,\n"
            . "    high_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    good_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    caution_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    low_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    actor_employee_id INT NULL,\n"
            . "    actor_name VARCHAR(100) NULL,\n"
            . "    started_at DATETIME NOT NULL,\n"
            . "    finished_at DATETIME NULL,\n"
            . "    error_summary TEXT NULL,\n"
            . "    created_at DATETIME NOT NULL,\n"
            . "    UNIQUE KEY uk_ai_reliability_run_uid (run_uid),\n"
            . "    KEY idx_ai_reliability_run_date (analysis_date, started_at),\n"
            . "    KEY idx_ai_reliability_run_status (run_status, started_at)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    public static function createResultTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS cpms_ai_input_reliability (\n"
            . "    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,\n"
            . "    run_id BIGINT UNSIGNED NULL,\n"
            . "    analysis_date DATE NOT NULL,\n"
            . "    target_ym CHAR(7) NOT NULL,\n"
            . "    forecast_date DATE NULL,\n"
            . "    snapshot_date DATE NULL,\n"
            . "    project_id INT UNSIGNED NOT NULL,\n"
            . "    project_name_snapshot VARCHAR(190) NULL,\n"
            . "    project_status_snapshot VARCHAR(50) NULL,\n"
            . "    current_input_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    forecast_input_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    forecast_low_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    forecast_high_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    reliability_score DECIMAL(6,2) NULL,\n"
            . "    reliability_grade VARCHAR(20) NOT NULL,\n"
            . "    data_status VARCHAR(30) NOT NULL,\n"
            . "    completeness_score DECIMAL(6,2) NULL,\n"
            . "    freshness_score DECIMAL(6,2) NULL,\n"
            . "    history_score DECIMAL(6,2) NULL,\n"
            . "    input_timing_score DECIMAL(6,2) NULL,\n"
            . "    stability_score DECIMAL(6,2) NULL,\n"
            . "    expected_category_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    observed_category_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    missing_category_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    snapshot_age_days INT NULL,\n"
            . "    latest_event_at DATETIME NULL,\n"
            . "    latest_event_age_days INT NULL,\n"
            . "    event_count_30d INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    event_count_90d INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    average_input_lag_days DECIMAL(8,2) NULL,\n"
            . "    late_input_rate DECIMAL(8,3) NULL,\n"
            . "    input_lag_sample_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    forecast_range_rate DECIMAL(8,3) NULL,\n"
            . "    forecast_change_rate DECIMAL(8,3) NULL,\n"
            . "    history_month_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    available_weight DECIMAL(8,3) NOT NULL DEFAULT 0,\n"
            . "    category_reliability_data MEDIUMTEXT NULL,\n"
            . "    reason_data MEDIUMTEXT NULL,\n"
            . "    warning_data MEDIUMTEXT NULL,\n"
            . "    actor_input_data MEDIUMTEXT NULL,\n"
            . "    first_created_at DATETIME NOT NULL,\n"
            . "    last_calculated_at DATETIME NOT NULL,\n"
            . "    calculation_count INT UNSIGNED NOT NULL DEFAULT 1,\n"
            . "    created_at DATETIME NOT NULL,\n"
            . "    updated_at DATETIME NOT NULL,\n"
            . "    UNIQUE KEY uk_ai_input_reliability (analysis_date, target_ym, project_id),\n"
            . "    KEY idx_ai_reliability_project (project_id, analysis_date),\n"
            . "    KEY idx_ai_reliability_target_month (target_ym, analysis_date),\n"
            . "    KEY idx_ai_reliability_grade (reliability_grade, analysis_date),\n"
            . "    KEY idx_ai_reliability_score (reliability_score, analysis_date),\n"
            . "    KEY idx_ai_reliability_run (run_id)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    private static function runColumnDefinitions()
    {
        return array(
            'run_uid'=>'VARCHAR(64) NOT NULL','analysis_date'=>'DATE NOT NULL','target_ym'=>'CHAR(7) NOT NULL',
            'forecast_date'=>'DATE NULL','snapshot_date'=>'DATE NULL','trigger_type'=>'VARCHAR(20) NOT NULL',
            'run_status'=>'VARCHAR(20) NOT NULL','project_count'=>'INT UNSIGNED NOT NULL DEFAULT 0',
            'success_count'=>'INT UNSIGNED NOT NULL DEFAULT 0','insufficient_count'=>'INT UNSIGNED NOT NULL DEFAULT 0',
            'failure_count'=>'INT UNSIGNED NOT NULL DEFAULT 0','average_score'=>'DECIMAL(6,2) NULL',
            'high_count'=>'INT UNSIGNED NOT NULL DEFAULT 0','good_count'=>'INT UNSIGNED NOT NULL DEFAULT 0',
            'caution_count'=>'INT UNSIGNED NOT NULL DEFAULT 0','low_count'=>'INT UNSIGNED NOT NULL DEFAULT 0',
            'actor_employee_id'=>'INT NULL','actor_name'=>'VARCHAR(100) NULL','started_at'=>'DATETIME NOT NULL',
            'finished_at'=>'DATETIME NULL','error_summary'=>'TEXT NULL','created_at'=>'DATETIME NOT NULL'
        );
    }

    private static function resultColumnDefinitions()
    {
        return array(
            'run_id'=>'BIGINT UNSIGNED NULL','analysis_date'=>'DATE NOT NULL','target_ym'=>'CHAR(7) NOT NULL',
            'forecast_date'=>'DATE NULL','snapshot_date'=>'DATE NULL','project_id'=>'INT UNSIGNED NOT NULL',
            'project_name_snapshot'=>'VARCHAR(190) NULL','project_status_snapshot'=>'VARCHAR(50) NULL',
            'current_input_amount'=>'DECIMAL(18,2) NOT NULL DEFAULT 0','forecast_input_amount'=>'DECIMAL(18,2) NOT NULL DEFAULT 0',
            'forecast_low_amount'=>'DECIMAL(18,2) NOT NULL DEFAULT 0','forecast_high_amount'=>'DECIMAL(18,2) NOT NULL DEFAULT 0',
            'reliability_score'=>'DECIMAL(6,2) NULL','reliability_grade'=>'VARCHAR(20) NOT NULL','data_status'=>'VARCHAR(30) NOT NULL',
            'completeness_score'=>'DECIMAL(6,2) NULL','freshness_score'=>'DECIMAL(6,2) NULL','history_score'=>'DECIMAL(6,2) NULL',
            'input_timing_score'=>'DECIMAL(6,2) NULL','stability_score'=>'DECIMAL(6,2) NULL',
            'expected_category_count'=>'INT UNSIGNED NOT NULL DEFAULT 0','observed_category_count'=>'INT UNSIGNED NOT NULL DEFAULT 0',
            'missing_category_count'=>'INT UNSIGNED NOT NULL DEFAULT 0','snapshot_age_days'=>'INT NULL','latest_event_at'=>'DATETIME NULL',
            'latest_event_age_days'=>'INT NULL','event_count_30d'=>'INT UNSIGNED NOT NULL DEFAULT 0','event_count_90d'=>'INT UNSIGNED NOT NULL DEFAULT 0',
            'average_input_lag_days'=>'DECIMAL(8,2) NULL','late_input_rate'=>'DECIMAL(8,3) NULL',
            'input_lag_sample_count'=>'INT UNSIGNED NOT NULL DEFAULT 0','forecast_range_rate'=>'DECIMAL(8,3) NULL',
            'forecast_change_rate'=>'DECIMAL(8,3) NULL','history_month_count'=>'INT UNSIGNED NOT NULL DEFAULT 0',
            'available_weight'=>'DECIMAL(8,3) NOT NULL DEFAULT 0','category_reliability_data'=>'MEDIUMTEXT NULL',
            'reason_data'=>'MEDIUMTEXT NULL','warning_data'=>'MEDIUMTEXT NULL','actor_input_data'=>'MEDIUMTEXT NULL',
            'first_created_at'=>'DATETIME NOT NULL','last_calculated_at'=>'DATETIME NOT NULL',
            'calculation_count'=>'INT UNSIGNED NOT NULL DEFAULT 1','created_at'=>'DATETIME NOT NULL','updated_at'=>'DATETIME NOT NULL'
        );
    }

    private static function ensureOwnedTable($pdo, $table, $columns, $indexes, &$updated)
    {
        if (!in_array($table, array(self::RUN_TABLE,self::RESULT_TABLE), true)) throw new Exception('unsupported reliability table');
        if (!self::columnExists($pdo, $table, 'id')) {
            $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST');
            $updated[] = $table . '.column:id';
            self::clearSchemaCache($pdo);
        }
        foreach ($columns as $column=>$definition) {
            if (!self::columnExists($pdo, $table, $column)) {
                $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
                $updated[] = $table . '.column:' . $column;
                self::clearSchemaCache($pdo);
            }
        }
        $existing = self::getTableIndexes($pdo, $table);
        foreach ($indexes as $name=>$definition) {
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
        if (!$pdo) return array('ok'=>false,'message'=>'DB 연결을 확인할 수 없습니다.','created'=>array(),'updated'=>array());
        $created = array();
        $updated = array();
        try {
            if (!self::tableExists($pdo, self::RUN_TABLE)) $created[] = self::RUN_TABLE;
            if (!self::tableExists($pdo, self::RESULT_TABLE)) $created[] = self::RESULT_TABLE;
            $pdo->exec(self::createRunTableSql());
            $pdo->exec(self::createResultTableSql());
            self::clearSchemaCache($pdo);
            self::ensureOwnedTable($pdo, self::RUN_TABLE, self::runColumnDefinitions(), array(
                'PRIMARY'=>'PRIMARY KEY (`id`)','uk_ai_reliability_run_uid'=>'UNIQUE KEY `uk_ai_reliability_run_uid` (`run_uid`)',
                'idx_ai_reliability_run_date'=>'KEY `idx_ai_reliability_run_date` (`analysis_date`,`started_at`)',
                'idx_ai_reliability_run_status'=>'KEY `idx_ai_reliability_run_status` (`run_status`,`started_at`)'
            ), $updated);
            self::ensureOwnedTable($pdo, self::RESULT_TABLE, self::resultColumnDefinitions(), array(
                'PRIMARY'=>'PRIMARY KEY (`id`)','uk_ai_input_reliability'=>'UNIQUE KEY `uk_ai_input_reliability` (`analysis_date`,`target_ym`,`project_id`)',
                'idx_ai_reliability_project'=>'KEY `idx_ai_reliability_project` (`project_id`,`analysis_date`)',
                'idx_ai_reliability_target_month'=>'KEY `idx_ai_reliability_target_month` (`target_ym`,`analysis_date`)',
                'idx_ai_reliability_grade'=>'KEY `idx_ai_reliability_grade` (`reliability_grade`,`analysis_date`)',
                'idx_ai_reliability_score'=>'KEY `idx_ai_reliability_score` (`reliability_score`,`analysis_date`)',
                'idx_ai_reliability_run'=>'KEY `idx_ai_reliability_run` (`run_id`)'
            ), $updated);
            self::clearSchemaCache($pdo);
            if (!self::isInstalled($pdo)) throw new Exception('reliability schema incomplete');
            return array('ok'=>true,'message'=>count($created)>0?'입력 신뢰도 전용 테이블을 설치했습니다.':'입력 신뢰도 전용 테이블 구조를 확인했습니다.','created'=>$created,'updated'=>$updated);
        } catch (Exception $e) {
            return array('ok'=>false,'message'=>'입력 신뢰도 테이블 설치 또는 확인에 실패했습니다.','created'=>$created,'updated'=>$updated);
        }
    }

    public static function isInstalled($pdo = null)
    {
        $pdo = self::pdo($pdo);
        if (!$pdo) return false;
        $key = self::connectionKey($pdo);
        if (array_key_exists($key, self::$installedCache)) return self::$installedCache[$key];
        $required = array(
            self::RUN_TABLE=>array('columns'=>self::requiredRunColumns(),'indexes'=>self::requiredRunIndexes()),
            self::RESULT_TABLE=>array('columns'=>self::requiredResultColumns(),'indexes'=>self::requiredResultIndexes())
        );
        foreach ($required as $table=>$schema) {
            if (!self::tableExists($pdo, $table)) return self::$installedCache[$key] = false;
            foreach ($schema['columns'] as $column) if (!self::columnExists($pdo, $table, $column)) return self::$installedCache[$key] = false;
            $indexes = self::getTableIndexes($pdo, $table);
            foreach ($schema['indexes'] as $index) if (!isset($indexes[$index])) return self::$installedCache[$key] = false;
        }
        return self::$installedCache[$key] = true;
    }

    private static function tableSchemaStatus($pdo, $table, $columns, $indexes)
    {
        $result = array('table_exists'=>false,'installed'=>false,'missing_columns'=>array(),'missing_indexes'=>array());
        $result['table_exists'] = self::tableExists($pdo, $table);
        if (!$result['table_exists']) {
            $result['missing_columns'] = $columns;
            $result['missing_indexes'] = $indexes;
            return $result;
        }
        foreach ($columns as $column) if (!self::columnExists($pdo, $table, $column)) $result['missing_columns'][] = $column;
        $existing = self::getTableIndexes($pdo, $table);
        foreach ($indexes as $index) if (!isset($existing[$index])) $result['missing_indexes'][] = $index;
        $result['installed'] = count($result['missing_columns'])===0 && count($result['missing_indexes'])===0;
        return $result;
    }

    public static function latestForecastContext($pdo = null)
    {
        $empty = array('available'=>false,'forecast_date'=>'','target_ym'=>'','snapshot_date'=>'','project_count'=>0);
        $pdo = self::pdo($pdo);
        if (!$pdo || !self::tableExists($pdo, self::FORECAST_TABLE)) return $empty;
        foreach (array('forecast_date','target_ym','snapshot_date','project_id') as $column) if (!self::columnExists($pdo, self::FORECAST_TABLE, $column)) return $empty;
        try {
            $st = $pdo->query('SELECT forecast_date,target_ym,snapshot_date FROM `' . self::FORECAST_TABLE . '` ORDER BY forecast_date DESC,id DESC LIMIT 1');
            $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
            if (!is_array($row)) return $empty;
            $forecastDate = self::validDate(isset($row['forecast_date'])?$row['forecast_date']:'');
            $targetYm = self::validYm(isset($row['target_ym'])?$row['target_ym']:'');
            $snapshotDate = self::validDate(isset($row['snapshot_date'])?$row['snapshot_date']:'');
            if ($forecastDate==='' || $targetYm==='') return $empty;
            $countSt = $pdo->prepare('SELECT COUNT(*) FROM `' . self::FORECAST_TABLE . '` WHERE forecast_date=:forecast_date AND target_ym=:target_ym');
            if (!$countSt || !$countSt->execute(array(':forecast_date'=>$forecastDate,':target_ym'=>$targetYm))) return $empty;
            return array('available'=>true,'forecast_date'=>$forecastDate,'target_ym'=>$targetYm,'snapshot_date'=>$snapshotDate,'project_count'=>(int)$countSt->fetchColumn());
        } catch (Exception $e) {
            return $empty;
        }
    }

    public static function schemaStatus($pdo = null)
    {
        $pdo = self::pdo($pdo);
        $result = array(
            'db_available'=>(bool)$pdo,
            'run'=>array('table_exists'=>false,'installed'=>false,'missing_columns'=>array(),'missing_indexes'=>array()),
            'result'=>array('table_exists'=>false,'installed'=>false,'missing_columns'=>array(),'missing_indexes'=>array()),
            'installed'=>false,'latest_forecast'=>array('available'=>false,'forecast_date'=>'','target_ym'=>'','snapshot_date'=>'','project_count'=>0),
            'result_count'=>0,'project_count'=>0,'latest_analysis_date'=>'','last_calculated_at'=>'','latest_run'=>array()
        );
        if (!$pdo) return $result;
        $result['latest_forecast'] = self::latestForecastContext($pdo);
        $result['run'] = self::tableSchemaStatus($pdo, self::RUN_TABLE, self::requiredRunColumns(), self::requiredRunIndexes());
        $result['result'] = self::tableSchemaStatus($pdo, self::RESULT_TABLE, self::requiredResultColumns(), self::requiredResultIndexes());
        $result['installed'] = !empty($result['run']['installed']) && !empty($result['result']['installed']);
        if (!empty($result['result']['installed'])) {
            try {
                $st = $pdo->query('SELECT COUNT(*) AS result_count,COUNT(DISTINCT project_id) AS project_count,MAX(analysis_date) AS latest_analysis_date,MAX(last_calculated_at) AS last_calculated_at FROM `' . self::RESULT_TABLE . '`');
                $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
                if (is_array($row)) {
                    $result['result_count'] = isset($row['result_count'])?(int)$row['result_count']:0;
                    $result['project_count'] = isset($row['project_count'])?(int)$row['project_count']:0;
                    $result['latest_analysis_date'] = isset($row['latest_analysis_date'])&&$row['latest_analysis_date']!==null?(string)$row['latest_analysis_date']:'';
                    $result['last_calculated_at'] = isset($row['last_calculated_at'])&&$row['last_calculated_at']!==null?(string)$row['last_calculated_at']:'';
                }
            } catch (Exception $e) {
            }
        }
        if (!empty($result['run']['installed'])) {
            try {
                $st = $pdo->query('SELECT id,analysis_date,target_ym,forecast_date,snapshot_date,trigger_type,run_status,project_count,success_count,insufficient_count,failure_count,average_score,high_count,good_count,caution_count,low_count,started_at,finished_at,error_summary FROM `' . self::RUN_TABLE . '` ORDER BY started_at DESC,id DESC LIMIT 1');
                $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
                $result['latest_run'] = is_array($row)?$row:array();
            } catch (Exception $e) {
            }
        }
        return $result;
    }

    public static function categoryDefinitions()
    {
        return array(
            'labor'=>array('label'=>'노무비','source_key'=>'labor'),
            'outsourcing'=>array('label'=>'외주비','source_key'=>'outsourcing'),
            'purchase'=>array('label'=>'구매품','source_key'=>'material'),
            'material'=>array('label'=>'자재비','source_key'=>'material'),
            'equipment'=>array('label'=>'장비비','source_key'=>'equipment'),
            'other_expense'=>array('label'=>'기타경비','source_key'=>''),
            'safety'=>array('label'=>'안전관리비','source_key'=>'safety_health'),
            'health'=>array('label'=>'보건비','source_key'=>'safety_health'),
            'other'=>array('label'=>'기타 투입비','source_key'=>'')
        );
    }

    private static function encodeData($value)
    {
        if (!is_array($value)) return null;
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json)?$json:null;
    }

    public static function decodeData($value)
    {
        if (!is_string($value) || trim($value)==='') return array();
        $decoded = json_decode($value, true);
        return is_array($decoded)?$decoded:array();
    }

    private static function clampScore($value)
    {
        if (!is_numeric($value)) return null;
        return round(max(0.0,min(100.0,(float)$value)),2);
    }

    private static function dateObject($value)
    {
        $date = self::validDate(substr(trim((string)$value),0,10));
        if ($date==='') return null;
        try {
            return new \DateTime($date . ' 00:00:00', new \DateTimeZone('Asia/Seoul'));
        } catch (Exception $e) {
            return null;
        }
    }

    public static function ageDays($referenceDate, $value)
    {
        $reference = self::dateObject($referenceDate);
        $date = self::dateObject($value);
        if (!$reference || !$date) return null;
        $days = (int)floor(($reference->getTimestamp()-$date->getTimestamp())/86400);
        return $days>=0?$days:null;
    }

    private static function offsetDate($date, $days)
    {
        $object = self::dateObject($date);
        if (!$object) return '';
        $object->modify(((int)$days>=0?'+':'') . (int)$days . ' day');
        return $object->format('Y-m-d');
    }

    public static function snapshotFreshnessScore($ageDays)
    {
        if ($ageDays===null || !is_numeric($ageDays) || (int)$ageDays<0) return null;
        $age = (int)$ageDays;
        if ($age===0) return 100.0;
        if ($age===1) return 85.0;
        if ($age===2) return 70.0;
        if ($age===3) return 55.0;
        if ($age<=7) return 35.0;
        return 10.0;
    }

    public static function eventFreshnessScore($ageDays)
    {
        if ($ageDays===null || !is_numeric($ageDays) || (int)$ageDays<0) return null;
        $age = (int)$ageDays;
        if ($age<=1) return 100.0;
        if ($age<=3) return 80.0;
        if ($age<=7) return 60.0;
        if ($age<=14) return 35.0;
        return 15.0;
    }

    public static function freshnessScore($snapshotScore, $eventScore)
    {
        if ($snapshotScore===null) return null;
        if ($eventScore===null) return self::clampScore($snapshotScore);
        return self::clampScore(((float)$snapshotScore*0.70)+((float)$eventScore*0.30));
    }

    public static function basisScore($basisType)
    {
        $scores = array('HISTORICAL_RATIO'=>100.0,'RECENT_MEDIAN'=>75.0,'LINEAR'=>45.0,'INSUFFICIENT'=>20.0);
        $basisType = strtoupper(trim((string)$basisType));
        return isset($scores[$basisType])?$scores[$basisType]:null;
    }

    public static function historyMonthScore($months)
    {
        $months = max(0,(int)$months);
        if ($months>=12) return 100.0;
        if ($months>=6) return 85.0;
        if ($months>=3) return 65.0;
        if ($months>=1) return 35.0;
        return 0.0;
    }

    public static function categoryHistoryScore($basisType, $months)
    {
        $basis = self::basisScore($basisType);
        if ($basis===null) return null;
        return self::clampScore(($basis*0.60)+(self::historyMonthScore($months)*0.40));
    }

    public static function inputTimingScore($averageLagDays, $lateInputRate, $sampleCount)
    {
        if ((int)$sampleCount<5 || !is_numeric($averageLagDays) || !is_numeric($lateInputRate)) return null;
        $average = (float)$averageLagDays;
        if ($average<0) return null;
        if ($average<=2) $averageScore=100.0;
        else if ($average<=5) $averageScore=80.0;
        else if ($average<=10) $averageScore=60.0;
        else if ($average<=20) $averageScore=35.0;
        else $averageScore=15.0;
        $late = max(0.0,min(100.0,(float)$lateInputRate));
        return self::clampScore(($averageScore*0.70)+((100.0-$late)*0.30));
    }

    public static function rangeStabilityScore($rate)
    {
        if ($rate===null || !is_numeric($rate) || (float)$rate<0) return null;
        $rate=(float)$rate;
        if ($rate<=10) return 100.0;
        if ($rate<=20) return 80.0;
        if ($rate<=35) return 60.0;
        if ($rate<=50) return 40.0;
        return 20.0;
    }

    public static function changeStabilityScore($rate)
    {
        if ($rate===null || !is_numeric($rate) || (float)$rate<0) return null;
        $rate=(float)$rate;
        if ($rate<=5) return 100.0;
        if ($rate<=10) return 80.0;
        if ($rate<=20) return 60.0;
        if ($rate<=35) return 40.0;
        return 20.0;
    }

    public static function stabilityScore($rangeRate, $changeRate)
    {
        $rangeScore = self::rangeStabilityScore($rangeRate);
        $changeScore = self::changeStabilityScore($changeRate);
        if ($rangeScore===null) return null;
        if ($changeScore===null) return $rangeScore;
        return self::clampScore(($rangeScore*0.70)+($changeScore*0.30));
    }

    public static function reliabilityGrade($score)
    {
        if ($score===null || !is_numeric($score)) return 'INSUFFICIENT';
        $score=(float)$score;
        if ($score>=85) return 'HIGH';
        if ($score>=70) return 'GOOD';
        if ($score>=50) return 'CAUTION';
        return 'LOW';
    }

    public static function weightedScore($scores)
    {
        $weights = array('completeness'=>25.0,'freshness'=>20.0,'history'=>20.0,'input_timing'=>20.0,'stability'=>15.0);
        $available = 0.0;
        $total = 0.0;
        foreach ($weights as $key=>$weight) {
            if (!array_key_exists($key,$scores) || $scores[$key]===null || !is_numeric($scores[$key])) continue;
            $available += $weight;
            $total += self::clampScore($scores[$key])*$weight;
        }
        $required = isset($scores['completeness']) && $scores['completeness']!==null && isset($scores['freshness']) && $scores['freshness']!==null;
        if (!$required || $available<60.0) return array('score'=>null,'available_weight'=>round($available,3),'grade'=>'INSUFFICIENT','data_status'=>'INSUFFICIENT');
        $score = self::clampScore($total/$available);
        return array(
            'score'=>$score,'available_weight'=>round($available,3),'grade'=>self::reliabilityGrade($score),
            'data_status'=>$available>=80.0?'READY':'LIMITED'
        );
    }

    private static function bindValues($st, $params)
    {
        foreach ($params as $key=>$value) $st->bindValue($key,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);
    }

    private static function projectPlaceholders($projectIds, &$params)
    {
        $placeholders=array();
        foreach (array_values($projectIds) as $index=>$projectId) {
            $key=':pid' . $index;
            $placeholders[]=$key;
            $params[$key]=(int)$projectId;
        }
        return $placeholders;
    }

    public static function loadLatestForecastRows($pdo, $context, &$loadOk = null)
    {
        $loadOk=false;
        $pdo=self::pdo($pdo);
        if (!$pdo || empty($context['available'])) return array();
        try {
            $sql='SELECT f.id,f.forecast_date,f.target_ym,f.snapshot_date,f.project_id,f.project_name_snapshot,f.project_status_snapshot,'
                . 'f.current_input_amount,f.forecast_input_amount,f.forecast_low_amount,f.forecast_high_amount,f.basis_type,f.data_status,'
                . 'f.history_month_count,f.category_forecast_data,'
                . 'p.forecast_input_amount AS previous_forecast_input_amount,p.category_forecast_data AS previous_category_forecast_data '
                . 'FROM `' . self::FORECAST_TABLE . '` f LEFT JOIN `' . self::FORECAST_TABLE . '` p ON p.id=('
                . 'SELECT p2.id FROM `' . self::FORECAST_TABLE . '` p2 WHERE p2.project_id=f.project_id AND p2.target_ym=f.target_ym '
                . 'AND p2.forecast_date<f.forecast_date ORDER BY p2.forecast_date DESC,p2.id DESC LIMIT 1) '
                . 'WHERE f.forecast_date=:forecast_date AND f.target_ym=:target_ym ORDER BY f.project_id ASC';
            $st=$pdo->prepare($sql);
            $st->execute(array(':forecast_date'=>$context['forecast_date'],':target_ym'=>$context['target_ym']));
            $rows=$st->fetchAll(PDO::FETCH_ASSOC);
            $loadOk=true;
            return is_array($rows)?$rows:array();
        } catch (Exception $e) {
            return array();
        }
    }

    public static function loadSnapshotMap($pdo, $forecastRows, $targetYm, &$loadOk = null)
    {
        $loadOk=false;
        $map=array();
        $pdo=self::pdo($pdo);
        if (!$pdo || !self::tableExists($pdo,self::SNAPSHOT_TABLE)) return $map;
        $ids=array();
        $dates=array();
        foreach ((array)$forecastRows as $row) {
            $projectId=isset($row['project_id'])?(int)$row['project_id']:0;
            $date=self::validDate(isset($row['snapshot_date'])?$row['snapshot_date']:'');
            if ($projectId>0) $ids[$projectId]=$projectId;
            if ($date!=='') $dates[$date]=$date;
        }
        if (count($ids)===0 || count($dates)===0) { $loadOk=true; return $map; }
        $params=array(':target_ym'=>$targetYm);
        $pidPlaceholders=self::projectPlaceholders($ids,$params);
        $datePlaceholders=array();
        foreach (array_values($dates) as $index=>$date) {
            $key=':snapshot_date' . $index;
            $datePlaceholders[]=$key;
            $params[$key]=$date;
        }
        try {
            $sql='SELECT snapshot_date,target_ym,project_id,latest_event_at,missing_section_count,data_flags,detail_data '
                . 'FROM `' . self::SNAPSHOT_TABLE . '` WHERE target_ym=:target_ym AND project_id IN (' . implode(',',$pidPlaceholders) . ') '
                . 'AND snapshot_date IN (' . implode(',',$datePlaceholders) . ')';
            $st=$pdo->prepare($sql);
            self::bindValues($st,$params);
            $st->execute();
            while ($row=$st->fetch(PDO::FETCH_ASSOC)) {
                $projectId=isset($row['project_id'])?(int)$row['project_id']:0;
                $date=isset($row['snapshot_date'])?(string)$row['snapshot_date']:'';
                if ($projectId>0 && $date!=='') $map[$projectId . ':' . $date]=$row;
            }
            $loadOk=true;
        } catch (Exception $e) {
        }
        return $map;
    }

    private static function emptyEventRow()
    {
        return array(
            'event_count_30d'=>0,'event_count_90d'=>0,'latest_event_at'=>'','current_period_count'=>0,
            'lag_count'=>0,'lag_sum'=>0.0,'late_count'=>0,'negative_count'=>0,'approval_count'=>0,'approval_lag_sum'=>0.0
        );
    }

    public static function loadEventStats($pdo, $projectIds, $analysisDate, $targetYm)
    {
        $result=array('available'=>false,'projects'=>array());
        $pdo=self::pdo($pdo);
        if (!$pdo || count($projectIds)===0) { $result['available']=true; return $result; }
        if (!self::tableExists($pdo,self::EVENT_TABLE)) return $result;
        foreach (array('project_id','cost_type','event_action','source_type','actual_date','settlement_ym','event_at') as $column) {
            if (!self::columnExists($pdo,self::EVENT_TABLE,$column)) return $result;
        }
        $laborPeriod=CostChangeService::periodForYm('labor',$targetYm);
        $costPeriod=CostChangeService::periodForYm('material',$targetYm);
        $params=array(
            ':start90'=>self::offsetDate($analysisDate,-89) . ' 00:00:00',':start30'=>self::offsetDate($analysisDate,-29) . ' 00:00:00',
            ':end_at'=>self::offsetDate($analysisDate,1) . ' 00:00:00',':target_ym'=>$targetYm,
            ':labor_start'=>$laborPeriod['start'],':labor_end'=>$laborPeriod['end'],
            ':cost_start'=>$costPeriod['start'],':cost_end'=>$costPeriod['end']
        );
        $placeholders=self::projectPlaceholders($projectIds,$params);
        $valid="source_type IN ('DIRECT','EXCEL','ADMIN_FORCE','ATTENDANCE') AND event_action<>'DELETE' AND actual_date IS NOT NULL";
        $nonNegative=$valid . ' AND DATEDIFF(DATE(event_at),actual_date)>=0';
        $currentPeriod="event_action<>'DELETE' AND (settlement_ym=:target_ym OR (settlement_ym IS NULL AND ((cost_type='labor' AND actual_date BETWEEN :labor_start AND :labor_end) OR (cost_type<>'labor' AND actual_date BETWEEN :cost_start AND :cost_end))))";
        try {
            $sql='SELECT project_id,cost_type,'
                . 'SUM(CASE WHEN event_at>=:start30 THEN 1 ELSE 0 END) AS event_count_30d,COUNT(*) AS event_count_90d,MAX(event_at) AS latest_event_at,'
                . 'SUM(CASE WHEN ' . $currentPeriod . ' THEN 1 ELSE 0 END) AS current_period_count,'
                . 'SUM(CASE WHEN ' . $nonNegative . ' THEN 1 ELSE 0 END) AS lag_count,'
                . 'SUM(CASE WHEN ' . $nonNegative . ' THEN DATEDIFF(DATE(event_at),actual_date) ELSE 0 END) AS lag_sum,'
                . 'SUM(CASE WHEN ' . $nonNegative . ' AND DATEDIFF(DATE(event_at),actual_date)>5 THEN 1 ELSE 0 END) AS late_count,'
                . 'SUM(CASE WHEN ' . $valid . ' AND DATEDIFF(DATE(event_at),actual_date)<0 THEN 1 ELSE 0 END) AS negative_count,'
                . "SUM(CASE WHEN source_type='APPROVAL' AND actual_date IS NOT NULL AND DATEDIFF(DATE(event_at),actual_date)>=0 THEN 1 ELSE 0 END) AS approval_count,"
                . "SUM(CASE WHEN source_type='APPROVAL' AND actual_date IS NOT NULL AND DATEDIFF(DATE(event_at),actual_date)>=0 THEN DATEDIFF(DATE(event_at),actual_date) ELSE 0 END) AS approval_lag_sum "
                . 'FROM `' . self::EVENT_TABLE . '` WHERE project_id IN (' . implode(',',$placeholders) . ') AND event_at>=:start90 AND event_at<:end_at '
                . 'GROUP BY project_id,cost_type';
            $st=$pdo->prepare($sql);
            self::bindValues($st,$params);
            $st->execute();
            $definitions=self::categoryDefinitions();
            while ($row=$st->fetch(PDO::FETCH_ASSOC)) {
                $projectId=isset($row['project_id'])?(int)$row['project_id']:0;
                $costType=isset($row['cost_type'])?trim((string)$row['cost_type']):'';
                if ($projectId<=0 || !isset($definitions[$costType])) continue;
                if (!isset($result['projects'][$projectId])) $result['projects'][$projectId]=array();
                $result['projects'][$projectId][$costType]=array_merge(self::emptyEventRow(),array(
                    'event_count_30d'=>(int)$row['event_count_30d'],'event_count_90d'=>(int)$row['event_count_90d'],
                    'latest_event_at'=>$row['latest_event_at']!==null?(string)$row['latest_event_at']:'',
                    'current_period_count'=>(int)$row['current_period_count'],'lag_count'=>(int)$row['lag_count'],
                    'lag_sum'=>(float)$row['lag_sum'],'late_count'=>(int)$row['late_count'],'negative_count'=>(int)$row['negative_count'],
                    'approval_count'=>(int)$row['approval_count'],'approval_lag_sum'=>(float)$row['approval_lag_sum']
                ));
            }
            $result['available']=true;
        } catch (Exception $e) {
            $result=array('available'=>false,'projects'=>array());
        }
        return $result;
    }

    private static function safeActorName($name, $employeeId)
    {
        $name=trim((string)$name);
        $unsafe = $name==='' || strpos($name,'@')!==false
            || preg_match('/\b01[016789][-. ]?\d{3,4}[-. ]?\d{4}\b/u',$name)
            || preg_match('/\b\d{6}[- ]?[1-4]\d{6}\b/u',$name);
        if ($unsafe) return (int)$employeeId>0?'직원 #' . (int)$employeeId:'';
        return function_exists('mb_substr')?mb_substr($name,0,100,'UTF-8'):substr($name,0,100);
    }

    public static function loadActorStats($pdo, $projectIds, $analysisDate)
    {
        $map=array();
        $pdo=self::pdo($pdo);
        if (!$pdo || count($projectIds)===0 || !self::tableExists($pdo,self::EVENT_TABLE)) return $map;
        foreach (array('project_id','cost_type','event_action','source_type','actual_date','event_at','actor_employee_id','actor_name') as $column) {
            if (!self::columnExists($pdo,self::EVENT_TABLE,$column)) return $map;
        }
        $params=array(':start90'=>self::offsetDate($analysisDate,-89) . ' 00:00:00',':end_at'=>self::offsetDate($analysisDate,1) . ' 00:00:00');
        $placeholders=self::projectPlaceholders($projectIds,$params);
        try {
            $sql="SELECT project_id,actor_employee_id,actor_name,cost_type,COUNT(*) AS event_count,"
                . 'SUM(DATEDIFF(DATE(event_at),actual_date)) AS lag_sum,'
                . 'SUM(CASE WHEN DATEDIFF(DATE(event_at),actual_date)>5 THEN 1 ELSE 0 END) AS late_count,MAX(event_at) AS latest_event_at '
                . 'FROM `' . self::EVENT_TABLE . '` WHERE project_id IN (' . implode(',',$placeholders) . ') '
                . "AND event_at>=:start90 AND event_at<:end_at AND source_type IN ('DIRECT','EXCEL','ADMIN_FORCE','ATTENDANCE') "
                . "AND event_action<>'DELETE' AND actual_date IS NOT NULL AND DATEDIFF(DATE(event_at),actual_date)>=0 "
                . "AND (actor_employee_id IS NOT NULL OR (actor_name IS NOT NULL AND actor_name<>'')) "
                . 'GROUP BY project_id,actor_employee_id,actor_name,cost_type';
            $st=$pdo->prepare($sql);
            self::bindValues($st,$params);
            $st->execute();
            $raw=array();
            while ($row=$st->fetch(PDO::FETCH_ASSOC)) {
                $projectId=(int)$row['project_id'];
                $employeeId=isset($row['actor_employee_id'])?(int)$row['actor_employee_id']:0;
                $name=self::safeActorName(isset($row['actor_name'])?$row['actor_name']:'',$employeeId);
                if ($projectId<=0 || $name==='') continue;
                $actorKey=$employeeId>0?'id:' . $employeeId:'name:' . strtolower($name);
                if (!isset($raw[$projectId][$actorKey])) $raw[$projectId][$actorKey]=array('name'=>$name,'count'=>0,'lag_sum'=>0.0,'late_count'=>0,'types'=>array(),'latest_event_at'=>'');
                $item=&$raw[$projectId][$actorKey];
                $count=(int)$row['event_count'];
                $item['count']+=$count;
                $item['lag_sum']+=(float)$row['lag_sum'];
                $item['late_count']+=(int)$row['late_count'];
                $type=isset($row['cost_type'])?(string)$row['cost_type']:'other';
                if (!isset($item['types'][$type])) $item['types'][$type]=0;
                $item['types'][$type]+=$count;
                $latest=$row['latest_event_at']!==null?(string)$row['latest_event_at']:'';
                if ($latest>$item['latest_event_at']) $item['latest_event_at']=$latest;
                unset($item);
            }
            foreach ($raw as $projectId=>$actors) {
                $list=array();
                foreach ($actors as $item) {
                    if ((int)$item['count']<5) continue;
                    arsort($item['types'],SORT_NUMERIC);
                    $types=array_keys($item['types']);
                    $mainType=isset($types[0])?$types[0]:'other';
                    $list[]=array(
                        'actor_name'=>$item['name'],'event_count'=>(int)$item['count'],
                        'average_input_lag_days'=>round($item['lag_sum']/$item['count'],2),
                        'late_input_count'=>(int)$item['late_count'],
                        'late_input_rate'=>round($item['late_count']/$item['count']*100,3),
                        'main_cost_type'=>$mainType,'latest_event_at'=>$item['latest_event_at']
                    );
                }
                usort($list,array(__CLASS__,'compareActorName'));
                $map[$projectId]=array_slice($list,0,100);
            }
        } catch (Exception $e) {
            return array();
        }
        return $map;
    }

    public static function compareActorName($a, $b)
    {
        return strcmp(isset($a['actor_name'])?$a['actor_name']:'',isset($b['actor_name'])?$b['actor_name']:'');
    }

    private static function categoryMissing($key, $missing)
    {
        if (in_array($key,$missing,true)) return true;
        if (($key==='purchase' || $key==='material') && in_array('material',$missing,true)) return true;
        if (($key==='safety' || $key==='health') && in_array('safety_health',$missing,true)) return true;
        return false;
    }

    private static function eventSummary($eventCategories, $snapshotLatest)
    {
        $result=self::emptyEventRow();
        $result['latest_event_at']=trim((string)$snapshotLatest);
        foreach ((array)$eventCategories as $row) {
            foreach (array('event_count_30d','event_count_90d','current_period_count','lag_count','late_count','negative_count','approval_count') as $key) $result[$key]+=(int)$row[$key];
            foreach (array('lag_sum','approval_lag_sum') as $key) $result[$key]+=(float)$row[$key];
            if (isset($row['latest_event_at']) && (string)$row['latest_event_at']>$result['latest_event_at']) $result['latest_event_at']=(string)$row['latest_event_at'];
        }
        return $result;
    }

    private static function categoryWeightedHistory($categoryRows)
    {
        $items=array();
        $amountTotal=0.0;
        foreach ($categoryRows as $row) {
            if (empty($row['expected']) || $row['history_score']===null) continue;
            $amount=max(0.0,(float)$row['forecast_amount']);
            $items[]=array('score'=>(float)$row['history_score'],'amount'=>$amount);
            $amountTotal+=$amount;
        }
        if (count($items)===0) return null;
        $total=0.0;
        foreach ($items as $item) $total+=$item['score']*($amountTotal>0?$item['amount']/$amountTotal:1.0/count($items));
        return self::clampScore($total);
    }

    private static function categoryReason($row)
    {
        $reasons=array();
        $warnings=array();
        if (empty($row['expected'])) {
            $reasons[]='현재 자료에서 입력 예상 항목으로 확인되지 않았습니다.';
            return array($reasons,$warnings);
        }
        if (!empty($row['observed'])) $reasons[]='현재 대상기간의 입력 또는 정상 자료원이 확인됩니다.';
        else $warnings[]=$row['label'] . ' 입력자료가 부족합니다.';
        if ((int)$row['event_count_30d']>0) $reasons[]='최근 30일 입력활동이 확인됩니다.';
        else if ((int)$row['event_count_90d']>0 && isset($row['latest_event_age_days']) && $row['latest_event_age_days']!==null) $warnings[]='최근 입력이 ' . (int)$row['latest_event_age_days'] . '일 전 자료입니다.';
        else if ((int)$row['event_count_90d']>0) $warnings[]='최근 30일 입력활동이 없습니다.';
        if ((int)$row['history_month_count']>=3) $reasons[]='과거 완료월 ' . (int)$row['history_month_count'] . '개월 자료를 사용했습니다.';
        else $warnings[]='과거 완료월 자료가 ' . (int)$row['history_month_count'] . '개월뿐입니다.';
        if ($row['input_timing_score']===null) $warnings[]='판단 가능한 입력 지연 표본이 부족합니다.';
        else if ((float)$row['average_input_lag_days']<=2) $reasons[]='입력 지연이 평균 2일 이내입니다.';
        else if ((float)$row['average_input_lag_days']>10) $warnings[]='평균 입력 지연일이 ' . number_format((float)$row['average_input_lag_days'],1) . '일입니다.';
        if ($row['range_rate']!==null && (float)$row['range_rate']<=20) $reasons[]='예상범위가 비교적 안정적입니다.';
        else if ($row['range_rate']!==null && (float)$row['range_rate']>35) $warnings[]='예상범위가 예상금액의 ' . number_format((float)$row['range_rate'],1) . '%입니다.';
        if ((int)$row['negative_count']>0) $warnings[]='실제 발생일보다 앞선 입력시각 자료는 지연 계산에서 제외했습니다.';
        return array(array_values(array_unique($reasons)),array_values(array_unique($warnings)));
    }

    public static function calculateProject($forecast, $snapshot, $eventCategories, $actorRows, $analysisDate)
    {
        $projectId=isset($forecast['project_id'])?(int)$forecast['project_id']:0;
        $targetYm=self::validYm(isset($forecast['target_ym'])?$forecast['target_ym']:'');
        $forecastDate=self::validDate(isset($forecast['forecast_date'])?$forecast['forecast_date']:'');
        $forecastSnapshotDate=self::validDate(isset($forecast['snapshot_date'])?$forecast['snapshot_date']:'');
        $analysisDate=self::validDate($analysisDate);
        if ($projectId<=0 || $targetYm==='' || $forecastDate==='' || $analysisDate==='') throw new Exception('reliability project unavailable');
        $snapshot=is_array($snapshot)?$snapshot:array();
        $snapshotFound=count($snapshot)>0;
        $snapshotDate=$snapshotFound?self::validDate(isset($snapshot['snapshot_date'])?$snapshot['snapshot_date']:''):$forecastSnapshotDate;
        $snapshotAge=$snapshotFound?self::ageDays($analysisDate,$snapshotDate):null;
        $snapshotFresh=self::snapshotFreshnessScore($snapshotAge);
        $flags=$snapshotFound?AiDailySnapshotService::decodeData(isset($snapshot['data_flags'])?$snapshot['data_flags']:''):array();
        $missing=isset($flags['missing'])&&is_array($flags['missing'])?$flags['missing']:array();
        $sources=isset($flags['sources'])&&is_array($flags['sources'])?$flags['sources']:array();
        $forecastCategories=AiMonthlyForecastService::decodeData(isset($forecast['category_forecast_data'])?$forecast['category_forecast_data']:'');
        $previousCategories=AiMonthlyForecastService::decodeData(isset($forecast['previous_category_forecast_data'])?$forecast['previous_category_forecast_data']:'');
        $eventCategories=is_array($eventCategories)?$eventCategories:array();
        $snapshotLatest=$snapshotFound&&isset($snapshot['latest_event_at'])&&$snapshot['latest_event_at']!==null?(string)$snapshot['latest_event_at']:'';
        $eventTotal=self::eventSummary($eventCategories,$snapshotLatest);
        $latestEventAt=$eventTotal['latest_event_at']!==''?$eventTotal['latest_event_at']:null;
        $latestEventAge=$latestEventAt!==null?self::ageDays($analysisDate,$latestEventAt):null;
        $freshness=self::freshnessScore($snapshotFresh,self::eventFreshnessScore($latestEventAge));

        $categoryRows=array();
        $expectedCount=0;
        $observedCount=0;
        $missingCount=0;
        foreach (self::categoryDefinitions() as $key=>$definition) {
            $fc=isset($forecastCategories[$key])&&is_array($forecastCategories[$key])?$forecastCategories[$key]:array();
            $event=isset($eventCategories[$key])?array_merge(self::emptyEventRow(),$eventCategories[$key]):self::emptyEventRow();
            $current=isset($fc['current'])&&is_numeric($fc['current'])?max(0.0,(float)$fc['current']):0.0;
            $forecastAmount=isset($fc['forecast'])&&is_numeric($fc['forecast'])?max(0.0,(float)$fc['forecast']):0.0;
            $low=isset($fc['low'])&&is_numeric($fc['low'])?max(0.0,(float)$fc['low']):$current;
            $high=isset($fc['high'])&&is_numeric($fc['high'])?max($low,(float)$fc['high']):$low;
            $historyMonths=isset($fc['history_month_count'])?(int)$fc['history_month_count']:0;
            $basis=isset($fc['basis_type'])?(string)$fc['basis_type']:'INSUFFICIENT';
            $sourceKey=$definition['source_key'];
            $sourcePresent=$sourceKey!==''&&isset($sources[$sourceKey])&&trim((string)$sources[$sourceKey])!=='';
            $isMissing=self::categoryMissing($key,$missing);
            $expected=$current>0 || $forecastAmount>0 || $historyMonths>0 || (int)$event['current_period_count']>0 || $sourcePresent || $isMissing;
            $observed=$expected && !$isMissing && ($current>0 || (int)$event['current_period_count']>0 || $sourcePresent);
            if ($expected) $expectedCount++;
            if ($observed) $observedCount++;
            if ($expected && !$observed) $missingCount++;
            $completeness=$expected?($observed?100.0:0.0):null;
            $categoryEventAge=$event['latest_event_at']!==''?self::ageDays($analysisDate,$event['latest_event_at']):null;
            $categoryFresh=$expected?self::freshnessScore($snapshotFresh,self::eventFreshnessScore($categoryEventAge)):null;
            $history=$expected?self::categoryHistoryScore($basis,$historyMonths):null;
            $lagCount=(int)$event['lag_count'];
            $averageLag=$lagCount>0?round((float)$event['lag_sum']/$lagCount,2):null;
            $lateRate=$lagCount>0?round((int)$event['late_count']/$lagCount*100,3):null;
            $timing=self::inputTimingScore($averageLag,$lateRate,$lagCount);
            $rangeRate=$forecastAmount>0?round(max(0.0,$high-$low)/$forecastAmount*100,3):null;
            $previousForecast=isset($previousCategories[$key]['forecast'])&&is_numeric($previousCategories[$key]['forecast'])?(float)$previousCategories[$key]['forecast']:0.0;
            $changeRate=$previousForecast>0?round(abs($forecastAmount-$previousForecast)/$previousForecast*100,3):null;
            $stability=$expected?self::stabilityScore($rangeRate,$changeRate):null;
            $weighted=self::weightedScore(array('completeness'=>$completeness,'freshness'=>$categoryFresh,'history'=>$history,'input_timing'=>$timing,'stability'=>$stability));
            $row=array(
                'label'=>$definition['label'],'expected'=>$expected,'observed'=>$observed,'current_amount'=>round($current,2),
                'forecast_amount'=>round($forecastAmount,2),'basis_type'=>$basis,'history_month_count'=>$historyMonths,
                'latest_event_at'=>$event['latest_event_at'],'latest_event_age_days'=>$categoryEventAge,'event_count_30d'=>(int)$event['event_count_30d'],
                'event_count_90d'=>(int)$event['event_count_90d'],'average_input_lag_days'=>$averageLag,
                'late_input_rate'=>$lateRate,'input_lag_sample_count'=>$lagCount,'range_rate'=>$rangeRate,
                'forecast_change_rate'=>$changeRate,'completeness_score'=>$completeness,'freshness_score'=>$categoryFresh,
                'history_score'=>$history,'input_timing_score'=>$timing,'stability_score'=>$stability,
                'score'=>$weighted['score'],'grade'=>$weighted['grade'],'data_status'=>$weighted['data_status'],
                'negative_count'=>(int)$event['negative_count'],'approval_count'=>(int)$event['approval_count']
            );
            list($row['reasons'],$row['warnings'])=self::categoryReason($row);
            $categoryRows[$key]=$row;
        }

        $snapshotMissingCount=$snapshotFound&&isset($snapshot['missing_section_count'])?(int)$snapshot['missing_section_count']:0;
        if ($snapshotMissingCount>$missingCount) {
            $genericPenalty=min($snapshotMissingCount-$missingCount,$observedCount);
            $observedCount-=$genericPenalty;
            $missingCount+=$genericPenalty;
        }
        $completeness=$expectedCount>0?self::clampScore($observedCount/$expectedCount*100):null;
        $history=self::categoryWeightedHistory($categoryRows);
        $lagCount=(int)$eventTotal['lag_count'];
        $averageLag=$lagCount>0?round((float)$eventTotal['lag_sum']/$lagCount,2):null;
        $lateRate=$lagCount>0?round((int)$eventTotal['late_count']/$lagCount*100,3):null;
        $timing=self::inputTimingScore($averageLag,$lateRate,$lagCount);
        $forecastAmount=isset($forecast['forecast_input_amount'])?max(0.0,(float)$forecast['forecast_input_amount']):0.0;
        $low=isset($forecast['forecast_low_amount'])?max(0.0,(float)$forecast['forecast_low_amount']):0.0;
        $high=isset($forecast['forecast_high_amount'])?max($low,(float)$forecast['forecast_high_amount']):$low;
        $rangeRate=$forecastAmount>0?round(($high-$low)/$forecastAmount*100,3):null;
        $previous=isset($forecast['previous_forecast_input_amount'])&&is_numeric($forecast['previous_forecast_input_amount'])?(float)$forecast['previous_forecast_input_amount']:0.0;
        $changeRate=$previous>0?round(abs($forecastAmount-$previous)/$previous*100,3):null;
        $stability=self::stabilityScore($rangeRate,$changeRate);
        $weighted=self::weightedScore(array('completeness'=>$completeness,'freshness'=>$freshness,'history'=>$history,'input_timing'=>$timing,'stability'=>$stability));

        $reasons=array();
        $warnings=array();
        if ($snapshotFound && $snapshotAge===0) $reasons[]='오늘 스냅샷이 반영되었습니다.';
        else if ($snapshotFound && $snapshotAge!==null) $warnings[]='최신 스냅샷이 ' . $snapshotAge . '일 전 자료입니다.';
        else $warnings[]='예측에 사용한 일일 스냅샷을 확인할 수 없습니다.';
        if ((int)$eventTotal['event_count_30d']>0) $reasons[]='최근 입력활동이 확인됩니다.';
        if ((int)$forecast['history_month_count']>0) $reasons[]='과거 완료월 ' . (int)$forecast['history_month_count'] . '개월 자료를 사용했습니다.';
        else $warnings[]='과거 완료월 자료가 부족합니다.';
        if ($rangeRate!==null && $rangeRate<=20) $reasons[]='예상범위가 비교적 안정적입니다.';
        else if ($rangeRate!==null && $rangeRate>35) $warnings[]='예측범위가 예상금액의 ' . number_format($rangeRate,1) . '%입니다.';
        if ($timing===null) $warnings[]='판단 가능한 입력 지연 표본이 부족합니다.';
        else if ($averageLag<=2) $reasons[]='입력 지연이 평균 2일 이내입니다.';
        else if ($averageLag>10) $warnings[]='평균 입력 지연일이 ' . number_format($averageLag,1) . '일입니다.';
        if ((int)$eventTotal['negative_count']>0) $warnings[]='실제 발생일보다 앞선 입력시각 ' . (int)$eventTotal['negative_count'] . '건은 지연 계산에서 제외했습니다.';
        if ((int)$eventTotal['approval_count']>0) $reasons[]='승인 반영 이벤트 ' . (int)$eventTotal['approval_count'] . '건은 개인 입력 지연에서 제외했습니다.';
        if ($snapshotMissingCount>0) $warnings[]='일일 스냅샷에서 확인 불가 비용 구분이 ' . $snapshotMissingCount . '개 있습니다.';
        foreach ($categoryRows as $row) foreach ($row['warnings'] as $warning) $warnings[]=$row['label'] . ': ' . $warning;
        $reasons=array_values(array_unique($reasons));
        $warnings=array_values(array_unique($warnings));

        return array(
            'analysis_date'=>$analysisDate,'target_ym'=>$targetYm,'forecast_date'=>$forecastDate,'snapshot_date'=>$snapshotDate!==''?$snapshotDate:null,
            'project_id'=>$projectId,'project_name_snapshot'=>isset($forecast['project_name_snapshot'])?trim((string)$forecast['project_name_snapshot']):'',
            'project_status_snapshot'=>isset($forecast['project_status_snapshot'])?trim((string)$forecast['project_status_snapshot']):'',
            'current_input_amount'=>isset($forecast['current_input_amount'])?(float)$forecast['current_input_amount']:0.0,
            'forecast_input_amount'=>$forecastAmount,'forecast_low_amount'=>$low,'forecast_high_amount'=>$high,
            'reliability_score'=>$weighted['score'],'reliability_grade'=>$weighted['grade'],'data_status'=>$weighted['data_status'],
            'completeness_score'=>$completeness,'freshness_score'=>$freshness,'history_score'=>$history,
            'input_timing_score'=>$timing,'stability_score'=>$stability,'expected_category_count'=>$expectedCount,
            'observed_category_count'=>$observedCount,'missing_category_count'=>$missingCount,'snapshot_age_days'=>$snapshotAge,
            'latest_event_at'=>$latestEventAt,'latest_event_age_days'=>$latestEventAge,'event_count_30d'=>(int)$eventTotal['event_count_30d'],
            'event_count_90d'=>(int)$eventTotal['event_count_90d'],'average_input_lag_days'=>$averageLag,
            'late_input_rate'=>$lateRate,'input_lag_sample_count'=>$lagCount,'forecast_range_rate'=>$rangeRate,
            'forecast_change_rate'=>$changeRate,'history_month_count'=>isset($forecast['history_month_count'])?(int)$forecast['history_month_count']:0,
            'available_weight'=>$weighted['available_weight'],'category_reliability_data'=>self::encodeData($categoryRows),
            'reason_data'=>self::encodeData($reasons),'warning_data'=>self::encodeData($warnings),
            'actor_input_data'=>self::encodeData(is_array($actorRows)?$actorRows:array())
        );
    }

    private static function normalizeTrigger($value)
    {
        $value=strtoupper(trim((string)$value));
        return in_array($value,array('MANUAL','CLI','SYSTEM'),true)?$value:'SYSTEM';
    }

    private static function actor($trigger)
    {
        if ($trigger!=='MANUAL') return array('id'=>null,'name'=>null);
        $user=Auth::user();
        $id=is_array($user)&&isset($user['id'])&&is_numeric($user['id'])?(int)$user['id']:0;
        $name=self::safeActorName(Auth::userName(),$id);
        return array('id'=>$id>0?$id:null,'name'=>$name!==''?$name:null);
    }

    private static function runUid()
    {
        $random=uniqid((string)mt_rand(),true) . microtime(true);
        if (function_exists('openssl_random_pseudo_bytes')) {
            $bytes=@openssl_random_pseudo_bytes(24);
            if ($bytes!==false) $random.=bin2hex($bytes);
        }
        return 'reliability_' . self::businessNow('YmdHis') . '_' . substr(hash('sha256',$random),0,32);
    }

    private static function acquireLock($pdo, $analysisDate, $targetYm)
    {
        $name='cpms_ai_input_reliability_' . str_replace('-','',$analysisDate) . '_' . str_replace('-','',$targetYm);
        try {
            $st=$pdo->prepare('SELECT GET_LOCK(:lock_name,0)');
            $st->bindValue(':lock_name',$name,PDO::PARAM_STR);
            $st->execute();
            return array('ok'=>((int)$st->fetchColumn()===1),'name'=>$name,'supported'=>true);
        } catch (Exception $e) {
            return array('ok'=>true,'name'=>'','supported'=>false);
        }
    }

    private static function releaseLock($pdo, $lock)
    {
        if (!$pdo || !is_array($lock) || empty($lock['supported']) || empty($lock['name'])) return;
        try {
            $st=$pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $st->bindValue(':lock_name',$lock['name'],PDO::PARAM_STR);
            $st->execute();
        } catch (Exception $e) {
        }
    }

    private static function clearStaleRuns($pdo, $analysisDate, $targetYm)
    {
        try {
            $sql="UPDATE `" . self::RUN_TABLE . "` SET run_status='FAILED',finished_at=NOW(),error_summary='실행 제한시간을 초과해 실패 처리했습니다.' WHERE analysis_date=:analysis_date AND target_ym=:target_ym AND run_status='RUNNING' AND started_at<DATE_SUB(NOW(),INTERVAL 1 HOUR)";
            $st=$pdo->prepare($sql);
            $st->execute(array(':analysis_date'=>$analysisDate,':target_ym'=>$targetYm));
        } catch (Exception $e) {
        }
    }

    private static function hasRecentRunning($pdo, $analysisDate, $targetYm)
    {
        try {
            $sql="SELECT id FROM `" . self::RUN_TABLE . "` WHERE analysis_date=:analysis_date AND target_ym=:target_ym AND run_status='RUNNING' AND started_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR) ORDER BY id DESC LIMIT 1";
            $st=$pdo->prepare($sql);
            $st->execute(array(':analysis_date'=>$analysisDate,':target_ym'=>$targetYm));
            return $st->fetchColumn()!==false;
        } catch (Exception $e) {
            return false;
        }
    }

    private static function createRun($pdo, $analysisDate, $context, $trigger, $projectCount)
    {
        $actor=self::actor($trigger);
        $now=self::businessNow('Y-m-d H:i:s');
        $sql='INSERT INTO `' . self::RUN_TABLE . '` (run_uid,analysis_date,target_ym,forecast_date,snapshot_date,trigger_type,run_status,project_count,success_count,insufficient_count,failure_count,average_score,high_count,good_count,caution_count,low_count,actor_employee_id,actor_name,started_at,finished_at,error_summary,created_at) VALUES (:run_uid,:analysis_date,:target_ym,:forecast_date,:snapshot_date,:trigger_type,\'RUNNING\',:project_count,0,0,0,NULL,0,0,0,0,:actor_employee_id,:actor_name,:started_at,NULL,NULL,:created_at)';
        $st=$pdo->prepare($sql);
        $st->execute(array(
            ':run_uid'=>self::runUid(),':analysis_date'=>$analysisDate,':target_ym'=>$context['target_ym'],
            ':forecast_date'=>$context['forecast_date'],':snapshot_date'=>$context['snapshot_date']!==''?$context['snapshot_date']:null,
            ':trigger_type'=>$trigger,':project_count'=>(int)$projectCount,':actor_employee_id'=>$actor['id'],':actor_name'=>$actor['name'],
            ':started_at'=>$now,':created_at'=>$now
        ));
        return (int)$pdo->lastInsertId();
    }

    private static function finishRun($pdo, $runId, $status, $counts, $errorSummary)
    {
        if (!in_array($status,array('COMPLETED','PARTIAL','FAILED'),true)) $status='FAILED';
        $average=$counts['score_count']>0?round($counts['score_total']/$counts['score_count'],2):null;
        $sql='UPDATE `' . self::RUN_TABLE . '` SET run_status=:run_status,project_count=:project_count,success_count=:success_count,insufficient_count=:insufficient_count,failure_count=:failure_count,average_score=:average_score,high_count=:high_count,good_count=:good_count,caution_count=:caution_count,low_count=:low_count,finished_at=:finished_at,error_summary=:error_summary WHERE id=:id';
        $st=$pdo->prepare($sql);
        $st->execute(array(
            ':run_status'=>$status,':project_count'=>(int)$counts['projects'],':success_count'=>(int)$counts['success'],
            ':insufficient_count'=>(int)$counts['insufficient'],':failure_count'=>(int)$counts['failed'],':average_score'=>$average,
            ':high_count'=>(int)$counts['HIGH'],':good_count'=>(int)$counts['GOOD'],':caution_count'=>(int)$counts['CAUTION'],
            ':low_count'=>(int)$counts['LOW'],':finished_at'=>self::businessNow('Y-m-d H:i:s'),
            ':error_summary'=>$errorSummary!==''?$errorSummary:null,':id'=>(int)$runId
        ));
    }

    public static function saveResult($pdo, $runId, $row)
    {
        $now=self::businessNow('Y-m-d H:i:s');
        $columns=self::requiredResultColumns();
        $columns=array_values(array_diff($columns,array('id')));
        $params=array();
        foreach ($columns as $column) $params[]=':' . $column;
        $updates=array();
        foreach ($columns as $column) {
            if (in_array($column,array('analysis_date','target_ym','project_id','first_created_at','calculation_count','created_at'),true)) continue;
            $updates[]='`' . $column . '`=VALUES(`' . $column . '`)';
        }
        $updates[]='`calculation_count`=`calculation_count`+1';
        $sql='INSERT INTO `' . self::RESULT_TABLE . '` (`' . implode('`,`',$columns) . '`) VALUES (' . implode(',',$params) . ') ON DUPLICATE KEY UPDATE ' . implode(',',$updates);
        $values=array();
        foreach ($columns as $column) {
            if ($column==='run_id') $value=(int)$runId;
            else if (in_array($column,array('first_created_at','last_calculated_at','created_at','updated_at'),true)) $value=$now;
            else if ($column==='calculation_count') $value=1;
            else $value=array_key_exists($column,$row)?$row[$column]:null;
            $values[':' . $column]=$value;
        }
        $st=$pdo->prepare($sql);
        return $st->execute($values);
    }

    public static function calculateLatest($pdo = null, $triggerType = 'SYSTEM')
    {
        $pdo=self::pdo($pdo);
        $analysisDate=self::businessToday();
        $empty=array(
            'ok'=>false,'busy'=>false,'analysis_date'=>$analysisDate,'target_ym'=>'','forecast_date'=>'','snapshot_date'=>'',
            'status'=>'FAILED','projects'=>0,'success'=>0,'insufficient'=>0,'failed'=>0,'average_score'=>null,
            'high'=>0,'good'=>0,'caution'=>0,'low'=>0,'message'=>'입력 신뢰도 계산에 실패했습니다.'
        );
        if (!$pdo) { $empty['message']='DB 연결을 확인할 수 없습니다.'; return $empty; }
        if (!self::isInstalled($pdo)) { $empty['message']='입력 신뢰도 테이블을 먼저 설치해주세요.'; return $empty; }
        $context=self::latestForecastContext($pdo);
        if (empty($context['available'])) { $empty['message']='입력 신뢰도를 계산하려면 먼저 월말 예측을 실행해주세요.'; return $empty; }
        $empty['target_ym']=$context['target_ym'];
        $empty['forecast_date']=$context['forecast_date'];
        $empty['snapshot_date']=$context['snapshot_date'];
        $trigger=self::normalizeTrigger($triggerType);
        $lock=self::acquireLock($pdo,$analysisDate,$context['target_ym']);
        if (empty($lock['ok'])) { $empty['busy']=true; $empty['message']='이미 입력 신뢰도 계산이 진행 중입니다.'; return $empty; }
        $runId=0;
        $counts=array('projects'=>0,'success'=>0,'insufficient'=>0,'failed'=>0,'HIGH'=>0,'GOOD'=>0,'CAUTION'=>0,'LOW'=>0,'score_total'=>0.0,'score_count'=>0);
        try {
            self::clearStaleRuns($pdo,$analysisDate,$context['target_ym']);
            if (self::hasRecentRunning($pdo,$analysisDate,$context['target_ym'])) {
                self::releaseLock($pdo,$lock);
                $empty['busy']=true;
                $empty['message']='이미 입력 신뢰도 계산이 진행 중입니다.';
                return $empty;
            }
            $forecastsLoaded=false;
            $forecasts=self::loadLatestForecastRows($pdo,$context,$forecastsLoaded);
            if (!$forecastsLoaded) throw new Exception('reliability forecasts unavailable');
            $counts['projects']=count($forecasts);
            $projectIds=array();
            foreach ($forecasts as $forecast) if (isset($forecast['project_id']) && (int)$forecast['project_id']>0) $projectIds[(int)$forecast['project_id']]=(int)$forecast['project_id'];
            $snapshotsLoaded=false;
            $snapshots=self::loadSnapshotMap($pdo,$forecasts,$context['target_ym'],$snapshotsLoaded);
            $eventStats=self::loadEventStats($pdo,$projectIds,$analysisDate,$context['target_ym']);
            $actorStats=self::loadActorStats($pdo,$projectIds,$analysisDate);
            $runId=self::createRun($pdo,$analysisDate,$context,$trigger,$counts['projects']);
            foreach ($forecasts as $forecast) {
                try {
                    $projectId=isset($forecast['project_id'])?(int)$forecast['project_id']:0;
                    if ($projectId<=0) throw new Exception('reliability project unavailable');
                    $snapshotKey=$projectId . ':' . (isset($forecast['snapshot_date'])?(string)$forecast['snapshot_date']:'');
                    $snapshot=isset($snapshots[$snapshotKey])?$snapshots[$snapshotKey]:array();
                    $projectEvents=isset($eventStats['projects'][$projectId])?$eventStats['projects'][$projectId]:array();
                    $projectActors=isset($actorStats[$projectId])?$actorStats[$projectId]:array();
                    $row=self::calculateProject($forecast,$snapshot,$projectEvents,$projectActors,$analysisDate);
                    self::saveResult($pdo,$runId,$row);
                    $counts['success']++;
                    $grade=$row['reliability_grade'];
                    if ($grade==='INSUFFICIENT') $counts['insufficient']++;
                    else if (isset($counts[$grade])) $counts[$grade]++;
                    if ($row['reliability_score']!==null) { $counts['score_total']+=(float)$row['reliability_score']; $counts['score_count']++; }
                } catch (Exception $e) {
                    $counts['failed']++;
                    error_log('[AiInputReliability] project calculation failed');
                }
            }
            if ($counts['failed']===0) $status='COMPLETED';
            else if ($counts['success']>0) $status='PARTIAL';
            else $status='FAILED';
            $errorSummary=$counts['failed']>0?'일부 프로젝트 입력 신뢰도 계산 실패: ' . $counts['failed'] . '건':'';
            if ($counts['projects']>0 && $counts['success']===0 && $counts['failed']>0) $errorSummary='전체 프로젝트 입력 신뢰도 계산 실패: ' . $counts['failed'] . '건';
            self::finishRun($pdo,$runId,$status,$counts,$errorSummary);
            self::releaseLock($pdo,$lock);
            $average=$counts['score_count']>0?round($counts['score_total']/$counts['score_count'],2):null;
            return array(
                'ok'=>$status==='COMPLETED'||$status==='PARTIAL','busy'=>false,'analysis_date'=>$analysisDate,
                'target_ym'=>$context['target_ym'],'forecast_date'=>$context['forecast_date'],'snapshot_date'=>$context['snapshot_date'],
                'status'=>$status,'projects'=>$counts['projects'],'success'=>$counts['success'],'insufficient'=>$counts['insufficient'],
                'failed'=>$counts['failed'],'average_score'=>$average,'high'=>$counts['HIGH'],'good'=>$counts['GOOD'],
                'caution'=>$counts['CAUTION'],'low'=>$counts['LOW'],
                'message'=>$status==='COMPLETED'?'입력 신뢰도 계산을 완료했습니다.':($status==='PARTIAL'?'일부 현장을 제외하고 입력 신뢰도를 저장했습니다.':'입력 신뢰도 계산에 실패했습니다.')
            );
        } catch (Exception $e) {
            if ($runId>0) {
                try { self::finishRun($pdo,$runId,'FAILED',$counts,'입력 신뢰도 실행 중 오류가 발생했습니다.'); } catch (Exception $ignored) {}
            }
            error_log('[AiInputReliability] calculation run failed');
            self::releaseLock($pdo,$lock);
            return $empty;
        }
    }

    public static function latestResultContext($pdo = null)
    {
        $empty=array('analysis_date'=>'','target_ym'=>'');
        $pdo=self::pdo($pdo);
        if (!$pdo || !self::isInstalled($pdo)) return $empty;
        try {
            $st=$pdo->query('SELECT analysis_date,target_ym FROM `' . self::RESULT_TABLE . '` ORDER BY analysis_date DESC,id DESC LIMIT 1');
            $row=$st?$st->fetch(PDO::FETCH_ASSOC):false;
            return is_array($row)?array('analysis_date'=>(string)$row['analysis_date'],'target_ym'=>(string)$row['target_ym']):$empty;
        } catch (Exception $e) { return $empty; }
    }

    private static function buildHistoryWhere($filters, &$params)
    {
        $filters=is_array($filters)?$filters:array();
        $params=array();
        $where=array('1=1');
        $date=self::validDate(isset($filters['analysis_date'])?$filters['analysis_date']:'');
        $ym=self::validYm(isset($filters['target_ym'])?$filters['target_ym']:'');
        if ($date!=='') { $where[]='r.analysis_date=:analysis_date'; $params[':analysis_date']=$date; }
        if ($ym!=='') { $where[]='r.target_ym=:target_ym'; $params[':target_ym']=$ym; }
        if (isset($filters['project_id']) && (int)$filters['project_id']>0) { $where[]='r.project_id=:project_id'; $params[':project_id']=(int)$filters['project_id']; }
        if (isset($filters['project_status']) && trim((string)$filters['project_status'])!=='') { $where[]='r.project_status_snapshot=:project_status'; $params[':project_status']=trim((string)$filters['project_status']); }
        if (isset($filters['grade']) && in_array($filters['grade'],array('HIGH','GOOD','CAUTION','LOW','INSUFFICIENT'),true)) { $where[]='r.reliability_grade=:grade'; $params[':grade']=$filters['grade']; }
        if (isset($filters['data_status']) && in_array($filters['data_status'],array('READY','LIMITED','INSUFFICIENT'),true)) { $where[]='r.data_status=:data_status'; $params[':data_status']=$filters['data_status']; }
        if (isset($filters['q']) && trim((string)$filters['q'])!=='') { $where[]='r.project_name_snapshot LIKE :q'; $params[':q']='%' . trim((string)$filters['q']) . '%'; }
        return implode(' AND ',$where);
    }

    public static function countResults($pdo, $filters)
    {
        $pdo=self::pdo($pdo);
        if (!$pdo || !self::isInstalled($pdo)) return 0;
        $params=array();
        $where=self::buildHistoryWhere($filters,$params);
        try {
            $st=$pdo->prepare('SELECT COUNT(*) FROM `' . self::RESULT_TABLE . '` r WHERE ' . $where);
            self::bindValues($st,$params);
            $st->execute();
            return (int)$st->fetchColumn();
        } catch (Exception $e) { return 0; }
    }

    public static function listResults($pdo, $filters, $page, $perPage)
    {
        $pdo=self::pdo($pdo);
        $page=max(1,(int)$page);
        $perPage=max(1,min(100,(int)$perPage));
        if (!$pdo || !self::isInstalled($pdo)) return array();
        $params=array();
        $where=self::buildHistoryWhere($filters,$params);
        $offset=($page-1)*$perPage;
        try {
            $sql='SELECT r.id,r.run_id,r.analysis_date,r.target_ym,r.forecast_date,r.snapshot_date,r.project_id,r.project_name_snapshot,r.project_status_snapshot,'
                . 'r.current_input_amount,r.forecast_input_amount,r.forecast_low_amount,r.forecast_high_amount,r.reliability_score,r.reliability_grade,r.data_status,'
                . 'r.completeness_score,r.freshness_score,r.history_score,r.input_timing_score,r.stability_score,r.expected_category_count,r.observed_category_count,'
                . 'r.missing_category_count,r.snapshot_age_days,r.latest_event_at,r.latest_event_age_days,r.event_count_30d,r.event_count_90d,r.average_input_lag_days,'
                . 'r.late_input_rate,r.input_lag_sample_count,r.forecast_range_rate,r.forecast_change_rate,r.history_month_count,r.available_weight,'
                . 'r.category_reliability_data,r.reason_data,r.warning_data,r.actor_input_data,r.first_created_at,r.last_calculated_at,r.calculation_count '
                . 'FROM `' . self::RESULT_TABLE . '` r WHERE ' . $where
                . ' ORDER BY (r.reliability_score IS NOT NULL) ASC,r.reliability_score ASC,r.project_id ASC LIMIT :limit OFFSET :offset';
            $st=$pdo->prepare($sql);
            self::bindValues($st,$params);
            $st->bindValue(':limit',$perPage,PDO::PARAM_INT);
            $st->bindValue(':offset',$offset,PDO::PARAM_INT);
            $st->execute();
            $rows=$st->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows)?$rows:array();
        } catch (Exception $e) { return array(); }
    }

    public static function historySummary($pdo, $filters)
    {
        $empty=array('project_count'=>0,'average_score'=>null,'high_count'=>0,'good_count'=>0,'caution_count'=>0,'low_count'=>0,'insufficient_count'=>0,'last_calculated_at'=>'');
        $pdo=self::pdo($pdo);
        if (!$pdo || !self::isInstalled($pdo)) return $empty;
        $params=array();
        $where=self::buildHistoryWhere($filters,$params);
        try {
            $sql="SELECT COUNT(*) AS project_count,AVG(r.reliability_score) AS average_score,"
                . "SUM(CASE WHEN r.reliability_grade='HIGH' THEN 1 ELSE 0 END) AS high_count,"
                . "SUM(CASE WHEN r.reliability_grade='GOOD' THEN 1 ELSE 0 END) AS good_count,"
                . "SUM(CASE WHEN r.reliability_grade='CAUTION' THEN 1 ELSE 0 END) AS caution_count,"
                . "SUM(CASE WHEN r.reliability_grade='LOW' THEN 1 ELSE 0 END) AS low_count,"
                . "SUM(CASE WHEN r.reliability_grade='INSUFFICIENT' THEN 1 ELSE 0 END) AS insufficient_count,"
                . 'MAX(r.last_calculated_at) AS last_calculated_at FROM `' . self::RESULT_TABLE . '` r WHERE ' . $where;
            $st=$pdo->prepare($sql);
            self::bindValues($st,$params);
            $st->execute();
            $row=$st->fetch(PDO::FETCH_ASSOC);
            return is_array($row)?array_merge($empty,$row):$empty;
        } catch (Exception $e) { return $empty; }
    }

    public static function historyOptions($pdo = null)
    {
        $result=array('projects'=>array(),'statuses'=>array(),'dates'=>array(),'months'=>array());
        $pdo=self::pdo($pdo);
        if (!$pdo || !self::isInstalled($pdo)) return $result;
        try {
            $st=$pdo->query('SELECT project_id,MAX(project_name_snapshot) AS project_name FROM `' . self::RESULT_TABLE . '` GROUP BY project_id ORDER BY project_name ASC,project_id ASC');
            if ($st) $result['projects']=$st->fetchAll(PDO::FETCH_ASSOC);
            $st=$pdo->query("SELECT DISTINCT project_status_snapshot AS status FROM `" . self::RESULT_TABLE . "` WHERE project_status_snapshot IS NOT NULL AND project_status_snapshot<>'' ORDER BY project_status_snapshot ASC");
            if ($st) $result['statuses']=$st->fetchAll(PDO::FETCH_ASSOC);
            $st=$pdo->query('SELECT DISTINCT analysis_date FROM `' . self::RESULT_TABLE . '` ORDER BY analysis_date DESC LIMIT 366');
            if ($st) $result['dates']=$st->fetchAll(PDO::FETCH_COLUMN);
            $st=$pdo->query('SELECT DISTINCT target_ym FROM `' . self::RESULT_TABLE . '` ORDER BY target_ym DESC');
            if ($st) $result['months']=$st->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
        }
        return $result;
    }
}
