<?php
/**
 * 현장별 비용 이상징후 탐지 서비스.
 * 저장된 신뢰도·예측·스냅샷·통합 비용 이벤트만 사용한다.
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
require_once __DIR__ . '/AiInputReliabilityService.php';

class AiAnomalyDetectionService
{
    const RUN_TABLE = 'cpms_ai_anomaly_runs';
    const RESULT_TABLE = 'cpms_ai_anomaly_results';
    const RELIABILITY_TABLE = 'cpms_ai_input_reliability';
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
            if (!$st) return self::$tableCache[$key] = false;
            if (!$st->execute(array(':table_name'=>$table))) return self::$tableCache[$key] = false;
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
            if (!is_array($rows)) $rows = array();
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
            if (!is_array($rows)) $rows = array();
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
            'id','run_uid','analysis_date','target_ym','snapshot_date','forecast_date','reliability_date',
            'trigger_type','run_status','project_count','success_count','normal_count','watch_count',
            'warning_count','critical_count','insufficient_count','failure_count','detected_anomaly_count',
            'actor_employee_id','actor_name','started_at','finished_at','error_summary','created_at'
        );
    }

    public static function requiredRunIndexes()
    {
        return array('PRIMARY','uk_ai_anomaly_run_uid','idx_ai_anomaly_run_date','idx_ai_anomaly_run_status');
    }

    public static function requiredResultColumns()
    {
        return array(
            'id','run_id','analysis_date','target_ym','snapshot_date','forecast_date','reliability_date',
            'project_id','project_name_snapshot','project_status_snapshot','current_input_amount',
            'forecast_input_amount','forecast_low_amount','forecast_high_amount','reliability_score',
            'reliability_grade','anomaly_score','anomaly_grade','highest_severity','anomaly_count',
            'watch_count','warning_count','critical_count','primary_anomaly_type','anomaly_type_flags',
            'snapshot_age_days','latest_event_at','data_status','confidence_level','anomaly_data',
            'summary_data','recommendation_data','warning_data','first_created_at','last_calculated_at',
            'calculation_count','created_at','updated_at'
        );
    }

    public static function requiredResultIndexes()
    {
        return array(
            'PRIMARY','uk_ai_anomaly_result','idx_ai_anomaly_project','idx_ai_anomaly_target_month',
            'idx_ai_anomaly_grade','idx_ai_anomaly_score','idx_ai_anomaly_primary_type','idx_ai_anomaly_run'
        );
    }

    public static function createRunTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS cpms_ai_anomaly_runs (\n"
            . "    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,\n"
            . "    run_uid VARCHAR(64) NOT NULL,\n"
            . "    analysis_date DATE NOT NULL,\n"
            . "    target_ym CHAR(7) NOT NULL,\n"
            . "    snapshot_date DATE NULL,\n"
            . "    forecast_date DATE NULL,\n"
            . "    reliability_date DATE NULL,\n"
            . "    trigger_type VARCHAR(20) NOT NULL,\n"
            . "    run_status VARCHAR(20) NOT NULL,\n"
            . "    project_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    success_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    normal_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    watch_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    warning_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    critical_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    insufficient_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    failure_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    detected_anomaly_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    actor_employee_id INT NULL,\n"
            . "    actor_name VARCHAR(100) NULL,\n"
            . "    started_at DATETIME NOT NULL,\n"
            . "    finished_at DATETIME NULL,\n"
            . "    error_summary TEXT NULL,\n"
            . "    created_at DATETIME NOT NULL,\n"
            . "    UNIQUE KEY uk_ai_anomaly_run_uid (run_uid),\n"
            . "    KEY idx_ai_anomaly_run_date (analysis_date, started_at),\n"
            . "    KEY idx_ai_anomaly_run_status (run_status, started_at)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    public static function createResultTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS cpms_ai_anomaly_results (\n"
            . "    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,\n"
            . "    run_id BIGINT UNSIGNED NULL,\n"
            . "    analysis_date DATE NOT NULL,\n"
            . "    target_ym CHAR(7) NOT NULL,\n"
            . "    snapshot_date DATE NULL,\n"
            . "    forecast_date DATE NULL,\n"
            . "    reliability_date DATE NULL,\n"
            . "    project_id INT UNSIGNED NOT NULL,\n"
            . "    project_name_snapshot VARCHAR(190) NULL,\n"
            . "    project_status_snapshot VARCHAR(50) NULL,\n"
            . "    current_input_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    forecast_input_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    forecast_low_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    forecast_high_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    reliability_score DECIMAL(6,2) NULL,\n"
            . "    reliability_grade VARCHAR(20) NULL,\n"
            . "    anomaly_score DECIMAL(6,2) NULL,\n"
            . "    anomaly_grade VARCHAR(20) NOT NULL,\n"
            . "    highest_severity VARCHAR(20) NOT NULL,\n"
            . "    anomaly_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    watch_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    warning_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    critical_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    primary_anomaly_type VARCHAR(50) NULL,\n"
            . "    anomaly_type_flags VARCHAR(500) NULL,\n"
            . "    snapshot_age_days INT NULL,\n"
            . "    latest_event_at DATETIME NULL,\n"
            . "    data_status VARCHAR(30) NOT NULL,\n"
            . "    confidence_level VARCHAR(20) NOT NULL,\n"
            . "    anomaly_data MEDIUMTEXT NULL,\n"
            . "    summary_data MEDIUMTEXT NULL,\n"
            . "    recommendation_data MEDIUMTEXT NULL,\n"
            . "    warning_data MEDIUMTEXT NULL,\n"
            . "    first_created_at DATETIME NOT NULL,\n"
            . "    last_calculated_at DATETIME NOT NULL,\n"
            . "    calculation_count INT UNSIGNED NOT NULL DEFAULT 1,\n"
            . "    created_at DATETIME NOT NULL,\n"
            . "    updated_at DATETIME NOT NULL,\n"
            . "    UNIQUE KEY uk_ai_anomaly_result (analysis_date, target_ym, project_id),\n"
            . "    KEY idx_ai_anomaly_project (project_id, analysis_date),\n"
            . "    KEY idx_ai_anomaly_target_month (target_ym, analysis_date),\n"
            . "    KEY idx_ai_anomaly_grade (anomaly_grade, analysis_date),\n"
            . "    KEY idx_ai_anomaly_score (anomaly_score, analysis_date),\n"
            . "    KEY idx_ai_anomaly_primary_type (primary_anomaly_type, analysis_date),\n"
            . "    KEY idx_ai_anomaly_run (run_id)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    private static function runColumnDefinitions()
    {
        return array(
            'run_uid'=>'VARCHAR(64) NOT NULL','analysis_date'=>'DATE NOT NULL','target_ym'=>'CHAR(7) NOT NULL',
            'snapshot_date'=>'DATE NULL','forecast_date'=>'DATE NULL','reliability_date'=>'DATE NULL',
            'trigger_type'=>'VARCHAR(20) NOT NULL','run_status'=>'VARCHAR(20) NOT NULL',
            'project_count'=>'INT UNSIGNED NOT NULL DEFAULT 0','success_count'=>'INT UNSIGNED NOT NULL DEFAULT 0',
            'normal_count'=>'INT UNSIGNED NOT NULL DEFAULT 0','watch_count'=>'INT UNSIGNED NOT NULL DEFAULT 0',
            'warning_count'=>'INT UNSIGNED NOT NULL DEFAULT 0','critical_count'=>'INT UNSIGNED NOT NULL DEFAULT 0',
            'insufficient_count'=>'INT UNSIGNED NOT NULL DEFAULT 0','failure_count'=>'INT UNSIGNED NOT NULL DEFAULT 0',
            'detected_anomaly_count'=>'INT UNSIGNED NOT NULL DEFAULT 0','actor_employee_id'=>'INT NULL',
            'actor_name'=>'VARCHAR(100) NULL','started_at'=>'DATETIME NOT NULL','finished_at'=>'DATETIME NULL',
            'error_summary'=>'TEXT NULL','created_at'=>'DATETIME NOT NULL'
        );
    }

    private static function resultColumnDefinitions()
    {
        return array(
            'run_id'=>'BIGINT UNSIGNED NULL','analysis_date'=>'DATE NOT NULL','target_ym'=>'CHAR(7) NOT NULL',
            'snapshot_date'=>'DATE NULL','forecast_date'=>'DATE NULL','reliability_date'=>'DATE NULL',
            'project_id'=>'INT UNSIGNED NOT NULL','project_name_snapshot'=>'VARCHAR(190) NULL',
            'project_status_snapshot'=>'VARCHAR(50) NULL','current_input_amount'=>'DECIMAL(18,2) NOT NULL DEFAULT 0',
            'forecast_input_amount'=>'DECIMAL(18,2) NOT NULL DEFAULT 0','forecast_low_amount'=>'DECIMAL(18,2) NOT NULL DEFAULT 0',
            'forecast_high_amount'=>'DECIMAL(18,2) NOT NULL DEFAULT 0','reliability_score'=>'DECIMAL(6,2) NULL',
            'reliability_grade'=>'VARCHAR(20) NULL','anomaly_score'=>'DECIMAL(6,2) NULL',
            'anomaly_grade'=>'VARCHAR(20) NOT NULL','highest_severity'=>'VARCHAR(20) NOT NULL',
            'anomaly_count'=>'INT UNSIGNED NOT NULL DEFAULT 0','watch_count'=>'INT UNSIGNED NOT NULL DEFAULT 0',
            'warning_count'=>'INT UNSIGNED NOT NULL DEFAULT 0','critical_count'=>'INT UNSIGNED NOT NULL DEFAULT 0',
            'primary_anomaly_type'=>'VARCHAR(50) NULL','anomaly_type_flags'=>'VARCHAR(500) NULL',
            'snapshot_age_days'=>'INT NULL','latest_event_at'=>'DATETIME NULL','data_status'=>'VARCHAR(30) NOT NULL',
            'confidence_level'=>'VARCHAR(20) NOT NULL','anomaly_data'=>'MEDIUMTEXT NULL','summary_data'=>'MEDIUMTEXT NULL',
            'recommendation_data'=>'MEDIUMTEXT NULL','warning_data'=>'MEDIUMTEXT NULL',
            'first_created_at'=>'DATETIME NOT NULL','last_calculated_at'=>'DATETIME NOT NULL',
            'calculation_count'=>'INT UNSIGNED NOT NULL DEFAULT 1','created_at'=>'DATETIME NOT NULL','updated_at'=>'DATETIME NOT NULL'
        );
    }

    private static function ensureOwnedTable($pdo, $table, $columns, $indexes, &$updated)
    {
        if (!in_array($table, array(self::RUN_TABLE,self::RESULT_TABLE), true)) throw new Exception('unsupported anomaly table');
        if (!self::columnExists($pdo, $table, 'id')) {
            if ($pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST') === false) throw new Exception('anomaly column update failed');
            $updated[] = $table . '.column:id';
            self::clearSchemaCache($pdo);
        }
        foreach ($columns as $column=>$definition) {
            if (!self::columnExists($pdo, $table, $column)) {
                if ($pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition) === false) throw new Exception('anomaly column update failed');
                $updated[] = $table . '.column:' . $column;
                self::clearSchemaCache($pdo);
            }
        }
        $existing = self::getTableIndexes($pdo, $table);
        foreach ($indexes as $name=>$definition) {
            if (!isset($existing[$name])) {
                if ($pdo->exec('ALTER TABLE `' . $table . '` ADD ' . $definition) === false) throw new Exception('anomaly index update failed');
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
            if ($pdo->exec(self::createRunTableSql()) === false) throw new Exception('anomaly run table install failed');
            if ($pdo->exec(self::createResultTableSql()) === false) throw new Exception('anomaly result table install failed');
            self::clearSchemaCache($pdo);
            self::ensureOwnedTable($pdo, self::RUN_TABLE, self::runColumnDefinitions(), array(
                'PRIMARY'=>'PRIMARY KEY (`id`)','uk_ai_anomaly_run_uid'=>'UNIQUE KEY `uk_ai_anomaly_run_uid` (`run_uid`)',
                'idx_ai_anomaly_run_date'=>'KEY `idx_ai_anomaly_run_date` (`analysis_date`,`started_at`)',
                'idx_ai_anomaly_run_status'=>'KEY `idx_ai_anomaly_run_status` (`run_status`,`started_at`)'
            ), $updated);
            self::ensureOwnedTable($pdo, self::RESULT_TABLE, self::resultColumnDefinitions(), array(
                'PRIMARY'=>'PRIMARY KEY (`id`)','uk_ai_anomaly_result'=>'UNIQUE KEY `uk_ai_anomaly_result` (`analysis_date`,`target_ym`,`project_id`)',
                'idx_ai_anomaly_project'=>'KEY `idx_ai_anomaly_project` (`project_id`,`analysis_date`)',
                'idx_ai_anomaly_target_month'=>'KEY `idx_ai_anomaly_target_month` (`target_ym`,`analysis_date`)',
                'idx_ai_anomaly_grade'=>'KEY `idx_ai_anomaly_grade` (`anomaly_grade`,`analysis_date`)',
                'idx_ai_anomaly_score'=>'KEY `idx_ai_anomaly_score` (`anomaly_score`,`analysis_date`)',
                'idx_ai_anomaly_primary_type'=>'KEY `idx_ai_anomaly_primary_type` (`primary_anomaly_type`,`analysis_date`)',
                'idx_ai_anomaly_run'=>'KEY `idx_ai_anomaly_run` (`run_id`)'
            ), $updated);
            self::clearSchemaCache($pdo);
            if (!self::isInstalled($pdo)) throw new Exception('anomaly schema incomplete');
            return array('ok'=>true,'message'=>count($created)>0?'이상징후 탐지 전용 테이블을 설치했습니다.':'이상징후 탐지 전용 테이블 구조를 확인했습니다.','created'=>$created,'updated'=>$updated);
        } catch (Exception $e) {
            return array('ok'=>false,'message'=>'이상징후 탐지 테이블 설치 또는 확인에 실패했습니다.','created'=>$created,'updated'=>$updated);
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
        $result['installed'] = count($result['missing_columns']) === 0 && count($result['missing_indexes']) === 0;
        return $result;
    }

    public static function latestReliabilityContext($pdo = null)
    {
        $empty = array('available'=>false,'reliability_date'=>'','target_ym'=>'','forecast_date'=>'','snapshot_date'=>'','project_count'=>0);
        $pdo = self::pdo($pdo);
        if (!$pdo || !self::tableExists($pdo, self::RELIABILITY_TABLE)) return $empty;
        foreach (array('analysis_date','target_ym','forecast_date','snapshot_date','project_id') as $column) if (!self::columnExists($pdo, self::RELIABILITY_TABLE, $column)) return $empty;
        try {
            $st = $pdo->query('SELECT analysis_date,target_ym,forecast_date,snapshot_date FROM `' . self::RELIABILITY_TABLE . '` ORDER BY analysis_date DESC,id DESC LIMIT 1');
            $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
            if (!is_array($row)) return $empty;
            $reliabilityDate = self::validDate(isset($row['analysis_date'])?$row['analysis_date']:'');
            $targetYm = self::validYm(isset($row['target_ym'])?$row['target_ym']:'');
            $forecastDate = self::validDate(isset($row['forecast_date'])?$row['forecast_date']:'');
            $snapshotDate = self::validDate(isset($row['snapshot_date'])?$row['snapshot_date']:'');
            if ($reliabilityDate === '' || $targetYm === '') return $empty;
            $countSt = $pdo->prepare('SELECT COUNT(*) FROM `' . self::RELIABILITY_TABLE . '` WHERE analysis_date=:analysis_date AND target_ym=:target_ym');
            if (!$countSt || !$countSt->execute(array(':analysis_date'=>$reliabilityDate,':target_ym'=>$targetYm))) return $empty;
            return array(
                'available'=>true,'reliability_date'=>$reliabilityDate,'target_ym'=>$targetYm,
                'forecast_date'=>$forecastDate,'snapshot_date'=>$snapshotDate,'project_count'=>(int)$countSt->fetchColumn()
            );
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
            'installed'=>false,'latest_reliability'=>array('available'=>false,'reliability_date'=>'','target_ym'=>'','forecast_date'=>'','snapshot_date'=>'','project_count'=>0),
            'result_count'=>0,'project_count'=>0,'latest_analysis_date'=>'','last_calculated_at'=>'','latest_run'=>array()
        );
        if (!$pdo) return $result;
        try {
            $result['latest_reliability'] = self::latestReliabilityContext($pdo);
            $result['run'] = self::tableSchemaStatus($pdo, self::RUN_TABLE, self::requiredRunColumns(), self::requiredRunIndexes());
            $result['result'] = self::tableSchemaStatus($pdo, self::RESULT_TABLE, self::requiredResultColumns(), self::requiredResultIndexes());
            $result['installed'] = !empty($result['run']['installed']) && !empty($result['result']['installed']);
            if (!empty($result['result']['installed'])) {
                $st = $pdo->query('SELECT COUNT(*) AS result_count,COUNT(DISTINCT project_id) AS project_count,MAX(analysis_date) AS latest_analysis_date,MAX(last_calculated_at) AS last_calculated_at FROM `' . self::RESULT_TABLE . '`');
                $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
                if (is_array($row)) {
                    $result['result_count'] = isset($row['result_count'])?(int)$row['result_count']:0;
                    $result['project_count'] = isset($row['project_count'])?(int)$row['project_count']:0;
                    $result['latest_analysis_date'] = isset($row['latest_analysis_date'])&&$row['latest_analysis_date']!==null?(string)$row['latest_analysis_date']:'';
                    $result['last_calculated_at'] = isset($row['last_calculated_at'])&&$row['last_calculated_at']!==null?(string)$row['last_calculated_at']:'';
                }
            }
            if (!empty($result['run']['installed'])) {
                $st = $pdo->query('SELECT id,analysis_date,target_ym,snapshot_date,forecast_date,reliability_date,trigger_type,run_status,project_count,success_count,normal_count,watch_count,warning_count,critical_count,insufficient_count,failure_count,detected_anomaly_count,started_at,finished_at,error_summary FROM `' . self::RUN_TABLE . '` ORDER BY started_at DESC,id DESC LIMIT 1');
                $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
                $result['latest_run'] = is_array($row)?$row:array();
            }
        } catch (Exception $e) {
        }
        return $result;
    }

    public static function categoryDefinitions()
    {
        return array(
            'labor'=>array('label'=>'노무비','column'=>'labor_amount','period'=>'labor','event_type'=>'labor'),
            'outsourcing'=>array('label'=>'외주비','column'=>'outsourcing_amount','period'=>'non_labor','event_type'=>'outsourcing'),
            'purchase'=>array('label'=>'구매품','column'=>'purchase_amount','period'=>'non_labor','event_type'=>'material'),
            'material'=>array('label'=>'자재비','column'=>'material_amount','period'=>'non_labor','event_type'=>'material'),
            'equipment'=>array('label'=>'장비비','column'=>'equipment_amount','period'=>'non_labor','event_type'=>'equipment'),
            'other_expense'=>array('label'=>'기타경비','column'=>'other_expense_amount','period'=>'non_labor','event_type'=>'other'),
            'safety'=>array('label'=>'안전관리비','column'=>'safety_amount','period'=>'non_labor','event_type'=>'safety'),
            'health'=>array('label'=>'보건비','column'=>'health_amount','period'=>'non_labor','event_type'=>'health'),
            'other'=>array('label'=>'기타 투입비','column'=>'other_amount','period'=>'non_labor','event_type'=>'other')
        );
    }

    public static function anomalyLabels()
    {
        return array(
            'SNAPSHOT_STALE'=>'스냅샷 최신성 저하','NO_RECENT_INPUT'=>'장기 미입력',
            'EXPECTED_CATEGORY_MISSING'=>'예상 비용항목 미입력','DAILY_COST_SPIKE'=>'일일 총투입비 급증',
            'DAILY_COST_REVERSAL'=>'일일 총투입비 감소·정정','CATEGORY_COST_SPIKE'=>'비용항목 급증',
            'FORECAST_JUMP'=>'월말 예상금액 급변','FORECAST_RANGE_EXPANSION'=>'예상범위 확대',
            'BULK_BACKFILL'=>'과거일자 집중입력','REPEATED_CORRECTION'=>'반복 수정·삭제',
            'CATEGORY_MIX_SHIFT'=>'비용 구성 변화'
        );
    }

    public static function decodeData($value)
    {
        if (!is_string($value) || trim($value) === '') return array();
        $decoded = json_decode($value, true);
        return is_array($decoded)?$decoded:array();
    }

    private static function encodeData($value)
    {
        if (!is_array($value)) return null;
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json)?$json:null;
    }

    private static function dateObject($value)
    {
        $date = self::validDate(substr(trim((string)$value), 0, 10));
        if ($date === '') return null;
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
        $days = (int)floor(($reference->getTimestamp() - $date->getTimestamp()) / 86400);
        return $days >= 0 ? $days : 0;
    }

    private static function offsetDate($date, $days)
    {
        $value = self::dateObject($date);
        if (!$value) return '';
        $value->modify(($days >= 0 ? '+' : '') . (int)$days . ' day');
        return $value->format('Y-m-d');
    }

    private static function monthOffset($ym, $offset)
    {
        if (self::validYm($ym) === '') return '';
        try {
            $date = new \DateTime($ym . '-01 00:00:00', new \DateTimeZone('Asia/Seoul'));
            $date->modify(($offset >= 0 ? '+' : '') . (int)$offset . ' month');
            return $date->format('Y-m');
        } catch (Exception $e) {
            return '';
        }
    }

    private static function bindValues($st, $params)
    {
        if (!$st) return false;
        foreach ($params as $key=>$value) {
            if (!$st->bindValue($key, $value, is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR)) return false;
        }
        return true;
    }

    private static function projectPlaceholders($projectIds, &$params, $prefix)
    {
        $placeholders = array();
        foreach (array_values($projectIds) as $index=>$projectId) {
            $key = ':' . $prefix . $index;
            $placeholders[] = $key;
            $params[$key] = (int)$projectId;
        }
        return $placeholders;
    }

    private static function categoryAmounts($row)
    {
        $result = array();
        foreach (self::categoryDefinitions() as $key=>$definition) {
            $result[$key] = isset($row[$definition['column']]) && is_numeric($row[$definition['column']]) ? max(0.0, (float)$row[$definition['column']]) : 0.0;
        }
        return $result;
    }

    public static function loadReliabilityRows($pdo, $context, &$loadOk = null)
    {
        $loadOk = false;
        $pdo = self::pdo($pdo);
        if (!$pdo || empty($context['available'])) return array();
        try {
            $sql = 'SELECT id,analysis_date,target_ym,forecast_date,snapshot_date,project_id,project_name_snapshot,project_status_snapshot,'
                . 'current_input_amount,forecast_input_amount,forecast_low_amount,forecast_high_amount,reliability_score,reliability_grade,data_status,'
                . 'available_weight,category_reliability_data,missing_category_count,snapshot_age_days,latest_event_at '
                . 'FROM `' . self::RELIABILITY_TABLE . '` WHERE analysis_date=:analysis_date AND target_ym=:target_ym ORDER BY project_id ASC,id ASC';
            $st = $pdo->prepare($sql);
            if (!$st || !$st->execute(array(':analysis_date'=>$context['reliability_date'],':target_ym'=>$context['target_ym']))) return array();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows)) return array();
            $loadOk = true;
            return $rows;
        } catch (Exception $e) {
            return array();
        }
    }

    public static function loadForecastMap($pdo, $projectIds, $context, &$loadOk = null)
    {
        $loadOk = false;
        $map = array();
        $pdo = self::pdo($pdo);
        if (!$pdo || count($projectIds) === 0 || empty($context['forecast_date']) || !self::tableExists($pdo, self::FORECAST_TABLE)) return $map;
        $params = array(':forecast_date'=>$context['forecast_date'],':target_ym'=>$context['target_ym']);
        $placeholders = self::projectPlaceholders($projectIds, $params, 'fp');
        try {
            $sql = 'SELECT f.id,f.forecast_date,f.target_ym,f.snapshot_date,f.project_id,f.current_input_amount,f.forecast_input_amount,'
                . 'f.forecast_low_amount,f.forecast_high_amount,f.data_status,f.category_forecast_data,'
                . 'p.forecast_date AS previous_forecast_date,p.forecast_input_amount AS previous_forecast_input_amount,'
                . 'p.forecast_low_amount AS previous_forecast_low_amount,p.forecast_high_amount AS previous_forecast_high_amount '
                . 'FROM `' . self::FORECAST_TABLE . '` f LEFT JOIN `' . self::FORECAST_TABLE . '` p ON p.id=('
                . 'SELECT p2.id FROM `' . self::FORECAST_TABLE . '` p2 WHERE p2.project_id=f.project_id AND p2.target_ym=f.target_ym '
                . 'AND p2.forecast_date<f.forecast_date ORDER BY p2.forecast_date DESC,p2.id DESC LIMIT 1) '
                . 'WHERE f.forecast_date=:forecast_date AND f.target_ym=:target_ym AND f.project_id IN (' . implode(',', $placeholders) . ')';
            $st = $pdo->prepare($sql);
            if (!$st || !self::bindValues($st, $params) || !$st->execute()) return $map;
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) $map[(int)$row['project_id']] = $row;
            $loadOk = true;
        } catch (Exception $e) {
        }
        return $map;
    }

    public static function loadSnapshotMap($pdo, $projectIds, $context, &$loadOk = null)
    {
        $loadOk = false;
        $map = array();
        $pdo = self::pdo($pdo);
        if (!$pdo || count($projectIds) === 0 || empty($context['snapshot_date']) || !self::tableExists($pdo, self::SNAPSHOT_TABLE)) return $map;
        $params = array(':snapshot_date'=>$context['snapshot_date'],':target_ym'=>$context['target_ym']);
        $placeholders = self::projectPlaceholders($projectIds, $params, 'sp');
        $columns = array('id','snapshot_date','target_ym','project_id','contract_amount','monthly_input_amount','latest_event_at');
        foreach (self::categoryDefinitions() as $definition) $columns[] = $definition['column'];
        $select = array();
        foreach (array_unique($columns) as $column) {
            $select[] = 's.`' . $column . '`';
            if (!in_array($column, array('id','target_ym','project_id','contract_amount','latest_event_at'), true)) $select[] = 'p.`' . $column . '` AS `previous_' . $column . '`';
        }
        try {
            $sql = 'SELECT ' . implode(',', $select) . ' FROM `' . self::SNAPSHOT_TABLE . '` s LEFT JOIN `' . self::SNAPSHOT_TABLE . '` p ON p.id=('
                . 'SELECT p2.id FROM `' . self::SNAPSHOT_TABLE . '` p2 WHERE p2.project_id=s.project_id AND p2.target_ym=s.target_ym '
                . 'AND p2.snapshot_date<s.snapshot_date ORDER BY p2.snapshot_date DESC,p2.id DESC LIMIT 1) '
                . 'WHERE s.snapshot_date=:snapshot_date AND s.target_ym=:target_ym AND s.project_id IN (' . implode(',', $placeholders) . ')';
            $st = $pdo->prepare($sql);
            if (!$st || !self::bindValues($st, $params) || !$st->execute()) return $map;
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) $map[(int)$row['project_id']] = $row;
            $loadOk = true;
        } catch (Exception $e) {
        }
        return $map;
    }

    private static function emptyEventCategory()
    {
        return array(
            'latest_input_at'=>'','current_period_count'=>0,'bulk_backfill_count'=>0,'bulk_excel_count'=>0,
            'correction_count_7d'=>0,'manual_count_90d'=>0,'system_count_90d'=>0,
            'repeated_target_count'=>0,'repeated_target_max'=>0
        );
    }

    private static function mapEventTypeToCategories($eventType)
    {
        $categories = array();
        foreach (self::categoryDefinitions() as $key=>$definition) if ($definition['event_type'] === $eventType) $categories[] = $key;
        return $categories;
    }

    public static function loadEventStats($pdo, $projectIds, $analysisDate, $targetYm)
    {
        $result = array('available'=>false,'projects'=>array());
        $pdo = self::pdo($pdo);
        if (!$pdo || count($projectIds) === 0) { $result['available'] = true; return $result; }
        if (!self::tableExists($pdo, self::EVENT_TABLE)) return $result;
        foreach (array('project_id','cost_type','target_type','target_id','event_action','source_type','actual_date','settlement_ym','event_at') as $column) {
            if (!self::columnExists($pdo, self::EVENT_TABLE, $column)) return $result;
        }
        $laborPeriod = CostChangeService::periodForYm('labor', $targetYm);
        $costPeriod = CostChangeService::periodForYm('material', $targetYm);
        $params = array(
            ':analysis_date'=>$analysisDate,':start90'=>self::offsetDate($analysisDate,-89) . ' 00:00:00',
            ':start7'=>self::offsetDate($analysisDate,-6) . ' 00:00:00',':end_at'=>self::offsetDate($analysisDate,1) . ' 00:00:00',
            ':target_ym'=>$targetYm,':labor_start'=>$laborPeriod['start'],':labor_end'=>$laborPeriod['end'],
            ':cost_start'=>$costPeriod['start'],':cost_end'=>$costPeriod['end']
        );
        $placeholders = self::projectPlaceholders($projectIds, $params, 'ep');
        $currentPeriod = "event_action<>'DELETE' AND (settlement_ym=:target_ym OR (settlement_ym IS NULL AND actual_date IS NOT NULL AND ((cost_type='labor' AND actual_date BETWEEN :labor_start AND :labor_end) OR (cost_type<>'labor' AND actual_date BETWEEN :cost_start AND :cost_end))))";
        try {
            $sql = 'SELECT project_id,cost_type,MAX(CASE WHEN event_action<>\'DELETE\' THEN event_at ELSE NULL END) AS latest_input_at,'
                . 'SUM(CASE WHEN ' . $currentPeriod . ' THEN 1 ELSE 0 END) AS current_period_count,'
                . 'SUM(CASE WHEN DATE(event_at)=:analysis_date AND actual_date IS NOT NULL AND DATEDIFF(DATE(event_at),actual_date)>=7 THEN 1 ELSE 0 END) AS bulk_backfill_count,'
                . "SUM(CASE WHEN DATE(event_at)=:analysis_date AND actual_date IS NOT NULL AND DATEDIFF(DATE(event_at),actual_date)>=7 AND source_type='EXCEL' THEN 1 ELSE 0 END) AS bulk_excel_count,"
                . "SUM(CASE WHEN event_at>=:start7 AND event_action IN ('UPDATE','DELETE','ADJUST') THEN 1 ELSE 0 END) AS correction_count_7d,"
                . "SUM(CASE WHEN source_type NOT IN ('AUTO_CALC','SYSTEM') THEN 1 ELSE 0 END) AS manual_count_90d,"
                . "SUM(CASE WHEN source_type IN ('AUTO_CALC','SYSTEM') THEN 1 ELSE 0 END) AS system_count_90d "
                . 'FROM `' . self::EVENT_TABLE . '` WHERE project_id IN (' . implode(',', $placeholders) . ') AND event_at>=:start90 AND event_at<:end_at GROUP BY project_id,cost_type';
            $st = $pdo->prepare($sql);
            if (!$st || !self::bindValues($st, $params) || !$st->execute()) return $result;
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $projectId = (int)$row['project_id'];
                $eventType = isset($row['cost_type'])?trim((string)$row['cost_type']):'';
                foreach (self::mapEventTypeToCategories($eventType) as $category) {
                    if (!isset($result['projects'][$projectId][$category])) $result['projects'][$projectId][$category] = self::emptyEventCategory();
                    $item =& $result['projects'][$projectId][$category];
                    $item['latest_input_at'] = isset($row['latest_input_at'])&&$row['latest_input_at']!==null?(string)$row['latest_input_at']:'';
                    foreach (array('current_period_count','bulk_backfill_count','bulk_excel_count','correction_count_7d','manual_count_90d','system_count_90d') as $key) $item[$key] += isset($row[$key])?(int)$row[$key]:0;
                    unset($item);
                }
            }

            $repeatParams = array(':start7_repeat'=>self::offsetDate($analysisDate,-6) . ' 00:00:00',':end_repeat'=>self::offsetDate($analysisDate,1) . ' 00:00:00');
            $repeatPlaceholders = self::projectPlaceholders($projectIds, $repeatParams, 'rp');
            $repeatSql = "SELECT project_id,cost_type,COUNT(*) AS repeated_target_count,MAX(change_count) AS repeated_target_max FROM ("
                . "SELECT project_id,cost_type,target_type,target_id,COUNT(*) AS change_count FROM `" . self::EVENT_TABLE . "` "
                . "WHERE project_id IN (" . implode(',', $repeatPlaceholders) . ") AND event_at>=:start7_repeat AND event_at<:end_repeat "
                . "AND event_action IN ('UPDATE','DELETE','ADJUST') AND target_id IS NOT NULL AND target_id<>'' "
                . "GROUP BY project_id,cost_type,target_type,target_id HAVING COUNT(*)>=3) repeated GROUP BY project_id,cost_type";
            $repeatSt = $pdo->prepare($repeatSql);
            if ($repeatSt && self::bindValues($repeatSt, $repeatParams) && $repeatSt->execute()) {
                while ($row = $repeatSt->fetch(PDO::FETCH_ASSOC)) {
                    $projectId = (int)$row['project_id'];
                    foreach (self::mapEventTypeToCategories(isset($row['cost_type'])?(string)$row['cost_type']:'') as $category) {
                        if (!isset($result['projects'][$projectId][$category])) $result['projects'][$projectId][$category] = self::emptyEventCategory();
                        $result['projects'][$projectId][$category]['repeated_target_count'] += (int)$row['repeated_target_count'];
                        $result['projects'][$projectId][$category]['repeated_target_max'] = max($result['projects'][$projectId][$category]['repeated_target_max'], (int)$row['repeated_target_max']);
                    }
                }
            }
            $result['available'] = true;
        } catch (Exception $e) {
            return array('available'=>false,'projects'=>array());
        }
        return $result;
    }

    public static function loadHistoricalMix($pdo, $projectIds, $targetYm, &$loadOk = null)
    {
        $loadOk = false;
        $result = array();
        $pdo = self::pdo($pdo);
        if (!$pdo || count($projectIds) === 0 || !self::tableExists($pdo, self::SNAPSHOT_TABLE)) return $result;
        $params = array(':target_ym'=>$targetYm,':start_ym'=>self::monthOffset($targetYm,-18));
        $placeholders = self::projectPlaceholders($projectIds, $params, 'hp');
        $columns = array('project_id','target_ym','snapshot_date','monthly_input_amount');
        foreach (self::categoryDefinitions() as $definition) $columns[] = $definition['column'];
        try {
            $sql = 'SELECT `' . implode('`,`', array_unique($columns)) . '` FROM `' . self::SNAPSHOT_TABLE . '` s WHERE s.project_id IN (' . implode(',', $placeholders) . ') '
                . 'AND s.target_ym<:target_ym AND s.target_ym>=:start_ym AND s.snapshot_date>=LAST_DAY(CONCAT(s.target_ym,\'-01\')) '
                . 'AND s.id=(SELECT s2.id FROM `' . self::SNAPSHOT_TABLE . '` s2 WHERE s2.project_id=s.project_id AND s2.target_ym=s.target_ym '
                . 'AND s2.snapshot_date>=LAST_DAY(CONCAT(s2.target_ym,\'-01\')) ORDER BY s2.snapshot_date DESC,s2.id DESC LIMIT 1) '
                . 'ORDER BY s.project_id ASC,s.target_ym DESC';
            $st = $pdo->prepare($sql);
            if (!$st || !self::bindValues($st, $params) || !$st->execute()) return $result;
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $projectId = (int)$row['project_id'];
                if ($projectId <= 0 || isset($result[$projectId]) && count($result[$projectId]) >= 12) continue;
                if (!isset($result[$projectId])) $result[$projectId] = array();
                $result[$projectId][] = $row;
            }
            $loadOk = true;
        } catch (Exception $e) {
        }
        return $result;
    }

    private static function severityByThreshold($value, $watch, $warning, $critical)
    {
        $value = (float)$value;
        if ($value >= (float)$critical) return 'CRITICAL';
        if ($value >= (float)$warning) return 'WARNING';
        if ($value >= (float)$watch) return 'WATCH';
        return '';
    }

    public static function snapshotStaleSeverity($days)
    {
        if ($days === null) return '';
        if ((int)$days >= 5) return 'CRITICAL';
        if ((int)$days >= 3) return 'WARNING';
        if ((int)$days >= 2) return 'WATCH';
        return '';
    }

    public static function noRecentInputSeverity($days)
    {
        if ($days === null) return '';
        if ((int)$days >= 15) return 'CRITICAL';
        if ((int)$days >= 8) return 'WARNING';
        if ((int)$days >= 5) return 'WATCH';
        return '';
    }

    public static function missingCategorySeverity($progressRate)
    {
        return self::severityByThreshold($progressRate, 40, 60, 80);
    }

    public static function dailySpikeSeverity($rate)
    {
        return self::severityByThreshold($rate, 15, 30, 60);
    }

    public static function dailyReversalSeverity($rate)
    {
        return self::severityByThreshold($rate, 10, 20, 40);
    }

    public static function categorySpikeSeverity($rate)
    {
        return self::severityByThreshold($rate, 30, 60, 100);
    }

    public static function forecastJumpSeverity($rate)
    {
        return self::severityByThreshold($rate, 10, 20, 35);
    }

    public static function rangeExpansionSeverity($rate, $hasPrevious, $expandedPoints)
    {
        if (!$hasPrevious) return (float)$rate > 50.0 ? 'WATCH' : '';
        if ((float)$rate <= 35.0 || (float)$expandedPoints < 10.0) return '';
        return self::severityByThreshold($rate, 35, 50, 80);
    }

    public static function bulkBackfillSeverity($count)
    {
        return self::severityByThreshold($count, 5, 15, 30);
    }

    public static function repeatedCorrectionSeverity($count)
    {
        return self::severityByThreshold($count, 5, 10, 20);
    }

    public static function mixShiftSeverity($points)
    {
        return self::severityByThreshold($points, 15, 25, 40);
    }

    private static function lowerSeverity($severity)
    {
        if ($severity === 'CRITICAL') return 'WARNING';
        if ($severity === 'WARNING') return 'WATCH';
        return $severity;
    }

    private static function severityRank($severity)
    {
        $ranks = array(''=>0,'WATCH'=>1,'WARNING'=>2,'CRITICAL'=>3);
        return isset($ranks[$severity])?$ranks[$severity]:0;
    }

    public static function confidenceLevel($reliabilityScore, $availableWeight, $comparisonAvailable)
    {
        if ($reliabilityScore !== null && (float)$reliabilityScore >= 70.0 && (float)$availableWeight >= 80.0 && $comparisonAvailable) return 'HIGH';
        if ($reliabilityScore !== null && (float)$reliabilityScore >= 50.0 && (float)$availableWeight >= 60.0) return 'MEDIUM';
        return 'LOW';
    }

    private static function shortText($value, $length)
    {
        $value = trim((string)$value);
        if (function_exists('mb_substr')) return mb_substr($value, 0, $length, 'UTF-8');
        return substr($value, 0, $length);
    }

    private static function addAnomaly(&$anomalies, $type, $category, $severity, $confidence, $title, $summary, $observed, $baseline, $difference, $rate, $evidence, $action)
    {
        if ($severity === '' || !isset(self::anomalyLabels()[$type])) return;
        $key = $type . ':' . (string)$category;
        foreach ($anomalies as $existing) if (isset($existing['_key']) && $existing['_key'] === $key) return;
        $definitions = self::categoryDefinitions();
        $anomalies[] = array(
            '_key'=>$key,'type'=>$type,'label'=>self::anomalyLabels()[$type],
            'category'=>$category!==''?$category:null,
            'category_label'=>$category!==''&&isset($definitions[$category])?$definitions[$category]['label']:null,
            'severity'=>$severity,'confidence'=>$confidence,'title'=>self::shortText($title,300),
            'summary'=>self::shortText($summary,500),'observed_value'=>$observed,'baseline_value'=>$baseline,
            'difference_value'=>$difference,'deviation_rate'=>$rate,
            'evidence'=>array_values(array_slice(is_array($evidence)?$evidence:array(),0,10)),
            'recommended_action'=>self::shortText($action,500)
        );
    }

    private static function cleanAnomalies($anomalies)
    {
        $result = array();
        foreach ($anomalies as $item) {
            if (isset($item['_key'])) unset($item['_key']);
            $result[] = $item;
        }
        return $result;
    }

    private static function eventCategory($categories, $key)
    {
        return isset($categories[$key]) && is_array($categories[$key]) ? array_merge(self::emptyEventCategory(), $categories[$key]) : self::emptyEventCategory();
    }

    private static function categoryExpected($key, $reliabilityCategories, $forecastCategories, $current)
    {
        if (isset($reliabilityCategories[$key]['expected']) && $reliabilityCategories[$key]['expected']) return true;
        if ((float)$current > 0) return true;
        if (isset($forecastCategories[$key]) && is_array($forecastCategories[$key])) {
            $row = $forecastCategories[$key];
            if (isset($row['forecast']) && (float)$row['forecast'] > 0) return true;
            if (isset($row['history_month_count']) && (int)$row['history_month_count'] > 0) return true;
        }
        return false;
    }

    private static function categoryProgress($key, $forecastCategories, $snapshotDate, $targetYm)
    {
        if (isset($forecastCategories[$key]['progress_rate']) && is_numeric($forecastCategories[$key]['progress_rate'])) {
            return max(0.0, min(100.0, (float)$forecastCategories[$key]['progress_rate']));
        }
        $rates = AiMonthlyForecastService::progressRates($snapshotDate, $targetYm);
        $definitions = self::categoryDefinitions();
        $period = isset($definitions[$key])?$definitions[$key]['period']:'non_labor';
        return isset($rates[$period]) ? round((float)$rates[$period] * 100, 3) : 0.0;
    }

    private static function baseProjectResult($reliability, $analysisDate)
    {
        return array(
            'analysis_date'=>$analysisDate,'target_ym'=>isset($reliability['target_ym'])?(string)$reliability['target_ym']:'',
            'snapshot_date'=>isset($reliability['snapshot_date'])&&$reliability['snapshot_date']!==''?$reliability['snapshot_date']:null,
            'forecast_date'=>isset($reliability['forecast_date'])&&$reliability['forecast_date']!==''?$reliability['forecast_date']:null,
            'reliability_date'=>isset($reliability['analysis_date'])&&$reliability['analysis_date']!==''?$reliability['analysis_date']:null,
            'project_id'=>isset($reliability['project_id'])?(int)$reliability['project_id']:0,
            'project_name_snapshot'=>isset($reliability['project_name_snapshot'])?self::shortText($reliability['project_name_snapshot'],190):'',
            'project_status_snapshot'=>isset($reliability['project_status_snapshot'])?self::shortText($reliability['project_status_snapshot'],50):'',
            'current_input_amount'=>isset($reliability['current_input_amount'])?(float)$reliability['current_input_amount']:0.0,
            'forecast_input_amount'=>isset($reliability['forecast_input_amount'])?(float)$reliability['forecast_input_amount']:0.0,
            'forecast_low_amount'=>isset($reliability['forecast_low_amount'])?(float)$reliability['forecast_low_amount']:0.0,
            'forecast_high_amount'=>isset($reliability['forecast_high_amount'])?(float)$reliability['forecast_high_amount']:0.0,
            'reliability_score'=>isset($reliability['reliability_score'])&&$reliability['reliability_score']!==null?(float)$reliability['reliability_score']:null,
            'reliability_grade'=>isset($reliability['reliability_grade'])?(string)$reliability['reliability_grade']:null,
            'anomaly_score'=>null,'anomaly_grade'=>'INSUFFICIENT','highest_severity'=>'',
            'anomaly_count'=>0,'watch_count'=>0,'warning_count'=>0,'critical_count'=>0,
            'primary_anomaly_type'=>null,'anomaly_type_flags'=>null,'snapshot_age_days'=>null,
            'latest_event_at'=>isset($reliability['latest_event_at'])&&$reliability['latest_event_at']!==null?$reliability['latest_event_at']:null,
            'data_status'=>'INSUFFICIENT','confidence_level'=>'LOW','anomaly_data'=>self::encodeData(array()),
            'summary_data'=>self::encodeData(array()),'recommendation_data'=>self::encodeData(array()),
            'warning_data'=>self::encodeData(array('필수 비교자료가 부족합니다.'))
        );
    }

    public static function calculateProject($reliability, $forecast, $snapshot, $eventCategories, $historyRows, $analysisDate, $eventAvailable)
    {
        $analysisDate = self::validDate($analysisDate);
        $projectId = isset($reliability['project_id'])?(int)$reliability['project_id']:0;
        $targetYm = self::validYm(isset($reliability['target_ym'])?$reliability['target_ym']:'');
        if ($analysisDate === '' || $projectId <= 0 || $targetYm === '') throw new Exception('anomaly project unavailable');
        $row = self::baseProjectResult($reliability, $analysisDate);
        $forecast = is_array($forecast)?$forecast:array();
        $snapshot = is_array($snapshot)?$snapshot:array();
        $eventCategories = is_array($eventCategories)?$eventCategories:array();
        $historyRows = is_array($historyRows)?$historyRows:array();
        $warnings = array();
        $recommendations = array();
        $anomalies = array();
        $comparisonCount = 0;

        if (count($forecast) === 0) $warnings[] = '입력 신뢰도와 연결된 월말 예측 자료가 없습니다.';
        if (count($snapshot) === 0) $warnings[] = '스냅샷 자료 없음';
        if (count($forecast) === 0 || count($snapshot) === 0) {
            $row['warning_data'] = self::encodeData($warnings);
            $row['summary_data'] = self::encodeData(array('안내'=>'비교자료가 더 쌓이면 해당 이상징후를 분석할 수 있습니다.'));
            $row['recommendation_data'] = self::encodeData(array('일일 스냅샷, 월말 예측, 입력 신뢰도를 순서대로 다시 확인해주세요.'));
            return $row;
        }

        $snapshotDate = self::validDate(isset($snapshot['snapshot_date'])?$snapshot['snapshot_date']:'');
        $row['snapshot_date'] = $snapshotDate!==''?$snapshotDate:$row['snapshot_date'];
        $row['forecast_date'] = isset($forecast['forecast_date'])?$forecast['forecast_date']:$row['forecast_date'];
        $row['current_input_amount'] = isset($snapshot['monthly_input_amount'])?(float)$snapshot['monthly_input_amount']:$row['current_input_amount'];
        $row['forecast_input_amount'] = isset($forecast['forecast_input_amount'])?(float)$forecast['forecast_input_amount']:$row['forecast_input_amount'];
        $row['forecast_low_amount'] = isset($forecast['forecast_low_amount'])?(float)$forecast['forecast_low_amount']:$row['forecast_low_amount'];
        $row['forecast_high_amount'] = isset($forecast['forecast_high_amount'])?(float)$forecast['forecast_high_amount']:$row['forecast_high_amount'];
        $snapshotAge = self::ageDays($analysisDate, $snapshotDate);
        $row['snapshot_age_days'] = $snapshotAge;
        $snapshotSeverity = self::snapshotStaleSeverity($snapshotAge);
        $baseConfidence = self::confidenceLevel($row['reliability_score'], isset($reliability['available_weight'])?(float)$reliability['available_weight']:0.0, true);
        if ($snapshotSeverity !== '') {
            self::addAnomaly($anomalies,'SNAPSHOT_STALE','',$snapshotSeverity,$baseConfidence,
                '최신 스냅샷이 분석일보다 오래되었습니다.','최신 스냅샷이 ' . (int)$snapshotAge . '일 전 자료입니다.',
                $snapshotAge,0,$snapshotAge,null,array('최신 스냅샷: ' . $snapshotDate,'분석일: ' . $analysisDate),'일일 스냅샷 실행 상태를 확인해주세요.');
        }

        $reliabilityCategories = self::decodeData(isset($reliability['category_reliability_data'])?$reliability['category_reliability_data']:'');
        $forecastCategories = self::decodeData(isset($forecast['category_forecast_data'])?$forecast['category_forecast_data']:'');
        $currentCategories = self::categoryAmounts($snapshot);
        $previousCategories = array();
        foreach (self::categoryDefinitions() as $key=>$definition) {
            $previousColumn = 'previous_' . $definition['column'];
            $previousCategories[$key] = isset($snapshot[$previousColumn])&&is_numeric($snapshot[$previousColumn])?max(0.0,(float)$snapshot[$previousColumn]):0.0;
        }

        if ($eventAvailable) $comparisonCount++;
        else $warnings[] = '통합 비용 이벤트를 확인할 수 없어 입력활동 기반 규칙을 제외했습니다.';
        foreach (self::categoryDefinitions() as $key=>$definition) {
            $current = $currentCategories[$key];
            $expected = self::categoryExpected($key,$reliabilityCategories,$forecastCategories,$current);
            $progress = self::categoryProgress($key,$forecastCategories,$snapshotDate,$targetYm);
            $event = self::eventCategory($eventCategories,$key);
            $missingTriggered = false;
            if ($eventAvailable && $expected && $current <= 0 && (int)$event['current_period_count'] === 0) {
                $severity = self::missingCategorySeverity($progress);
                if ($severity !== '') {
                    $missingTriggered = true;
                    self::addAnomaly($anomalies,'EXPECTED_CATEGORY_MISSING',$key,$severity,$baseConfidence,
                        $definition['label'] . '가 예상되지만 현재 입력금액이 없습니다.',
                        '대상기간 진행률 ' . number_format($progress,1) . '%에서 입력자료가 확인되지 않습니다.',
                        0,isset($forecastCategories[$key]['forecast'])?(float)$forecastCategories[$key]['forecast']:0.0,0,$progress,
                        array('현재 금액: 0원','기간 진행률: ' . number_format($progress,1) . '%'),
                        $definition['label'] . ' 사용 여부와 귀속월을 확인해주세요.');
                }
            }
            if ($key !== 'purchase' && $eventAvailable && !$missingTriggered && $expected && $progress >= 30.0 && (int)$event['manual_count_90d'] > 0 && $event['latest_input_at'] !== '') {
                $eventAge = self::ageDays($analysisDate,$event['latest_input_at']);
                $severity = self::noRecentInputSeverity($eventAge);
                if ($severity !== '') {
                    self::addAnomaly($anomalies,'NO_RECENT_INPUT',$key,$severity,$baseConfidence,
                        $definition['label'] . ' 최근 입력활동이 오래되었습니다.',
                        '최근 입력 후 ' . (int)$eventAge . '일이 지났습니다.',
                        $eventAge,0,$eventAge,null,array('최근 입력: ' . substr($event['latest_input_at'],0,10),'기간 진행률: ' . number_format($progress,1) . '%'),
                        $definition['label'] . ' 입력자료와 정산 여부를 확인해주세요.');
                }
            }

            if ($eventAvailable && $key !== 'purchase') {
                $bulkSeverity = self::bulkBackfillSeverity((int)$event['bulk_backfill_count']);
                if ($bulkSeverity !== '') {
                    $bulkGuide = (int)$event['bulk_excel_count'] > 0 ? ' 엑셀 또는 일괄등록에 따른 정상 입력일 수 있습니다.' : '';
                    self::addAnomaly($anomalies,'BULK_BACKFILL',$key,$bulkSeverity,$baseConfidence,
                        $definition['label'] . ' 과거일자 자료가 집중 입력되었습니다.',
                        '분석일에 실제 발생일보다 7일 이상 지난 자료 ' . (int)$event['bulk_backfill_count'] . '건이 입력되었습니다.' . $bulkGuide,
                        (int)$event['bulk_backfill_count'],0,(int)$event['bulk_backfill_count'],null,
                        array('과거일자 입력: ' . (int)$event['bulk_backfill_count'] . '건','엑셀 입력: ' . (int)$event['bulk_excel_count'] . '건'),
                        '일괄입력 또는 정상 정산자료인지 확인해주세요.');
                }
                $correctionSeverity = self::repeatedCorrectionSeverity((int)$event['correction_count_7d']);
                if ($correctionSeverity !== '') {
                    $evidence = array('최근 7일 수정·삭제·조정: ' . (int)$event['correction_count_7d'] . '건');
                    if ((int)$event['repeated_target_count'] > 0) $evidence[] = '동일 대상 3회 이상 수정: ' . (int)$event['repeated_target_count'] . '개';
                    self::addAnomaly($anomalies,'REPEATED_CORRECTION',$key,$correctionSeverity,$baseConfidence,
                        $definition['label'] . ' 수정·삭제가 반복되었습니다.',
                        '최근 7일 수정·삭제·조정 이벤트가 ' . (int)$event['correction_count_7d'] . '건 확인되었습니다.',
                        (int)$event['correction_count_7d'],0,(int)$event['correction_count_7d'],null,$evidence,
                        '중복입력, 귀속월 변경 또는 정상적인 정산작업인지 확인해주세요.');
                }
            }
        }

        $previousSnapshotDate = self::validDate(isset($snapshot['previous_snapshot_date'])?$snapshot['previous_snapshot_date']:'');
        $previousTotal = isset($snapshot['previous_monthly_input_amount'])?(float)$snapshot['previous_monthly_input_amount']:0.0;
        $currentTotal = (float)$row['current_input_amount'];
        $contractAmount = isset($snapshot['contract_amount'])?max(0.0,(float)$snapshot['contract_amount']):0.0;
        $totalMinimum = max(5000000.0,$contractAmount*0.002);
        if ($previousSnapshotDate !== '') {
            $comparisonCount++;
            $difference = $currentTotal-$previousTotal;
            if ($difference >= $totalMinimum && $previousTotal > 0) {
                $rate = $difference/$previousTotal*100;
                $severity = self::dailySpikeSeverity($rate);
                self::addAnomaly($anomalies,'DAILY_COST_SPIKE','',$severity,$baseConfidence,
                    '월 투입비가 직전 스냅샷보다 크게 증가했습니다.','월 투입비가 ' . number_format($rate,1) . '% 증가했습니다.',
                    $currentTotal,$previousTotal,$difference,round($rate,3),array('직전 스냅샷: ' . $previousSnapshotDate,'최신 스냅샷: ' . $snapshotDate,'최소 확인금액: ' . number_format($totalMinimum) . '원'),
                    '신규 비용 입력과 귀속월을 확인해주세요.');
            } else if ($difference <= -$totalMinimum && $previousTotal > 0) {
                $rate = abs($difference)/$previousTotal*100;
                $severity = self::dailyReversalSeverity($rate);
                self::addAnomaly($anomalies,'DAILY_COST_REVERSAL','',$severity,$baseConfidence,
                    '월 투입비가 직전 스냅샷보다 감소했습니다.','월 투입비가 ' . number_format($rate,1) . '% 감소했습니다.',
                    $currentTotal,$previousTotal,$difference,round($rate,3),array('직전 스냅샷: ' . $previousSnapshotDate,'최신 스냅샷: ' . $snapshotDate,'최소 확인금액: ' . number_format($totalMinimum) . '원'),
                    '비용 삭제·귀속월 이동·금액 정정 여부를 확인해주세요.');
            }

            foreach (self::categoryDefinitions() as $key=>$definition) {
                $current = $currentCategories[$key];
                $previous = $previousCategories[$key];
                $increase = $current-$previous;
                $forecastAmount = isset($forecastCategories[$key]['forecast'])?(float)$forecastCategories[$key]['forecast']:0.0;
                $minimum = max(2000000.0,$forecastAmount*0.02);
                if ($increase < $minimum) continue;
                $rate = null;
                if ($previous > 0) {
                    $rate = $increase/$previous*100;
                } else if ($forecastAmount > 0) {
                    $rate = $increase/$forecastAmount*100;
                }
                if ($rate === null) continue;
                $severity = self::categorySpikeSeverity($rate);
                if ($previous <= 0 && $severity === 'CRITICAL') $severity = 'WARNING';
                self::addAnomaly($anomalies,'CATEGORY_COST_SPIKE',$key,$severity,$baseConfidence,
                    $definition['label'] . '가 직전 스냅샷보다 크게 증가했습니다.',
                    $definition['label'] . '가 ' . number_format($rate,1) . '% 증가했습니다.',
                    $current,$previous,$increase,round($rate,3),array('직전 스냅샷: ' . $previousSnapshotDate,'최신 스냅샷: ' . $snapshotDate,'최소 확인금액: ' . number_format($minimum) . '원'),
                    '신규 ' . $definition['label'] . ' 입력과 귀속월을 확인해주세요.');
            }
        } else {
            $warnings[] = '직전 일일 스냅샷이 없어 일일 증감 규칙을 제외했습니다.';
        }

        $previousForecast = isset($forecast['previous_forecast_input_amount'])&&$forecast['previous_forecast_input_amount']!==null?(float)$forecast['previous_forecast_input_amount']:null;
        $forecastAmount = (float)$row['forecast_input_amount'];
        if ($previousForecast !== null && $previousForecast > 0) {
            $comparisonCount++;
            $difference = $forecastAmount-$previousForecast;
            $changeRate = abs($difference)/$previousForecast*100;
            $minimum = max(5000000.0,$forecastAmount*0.02);
            if (abs($difference) >= $minimum) {
                $severity = self::forecastJumpSeverity($changeRate);
                $forecastDataStatus = isset($forecast['data_status'])?(string)$forecast['data_status']:'';
                $confidence = $baseConfidence;
                if ($forecastDataStatus === 'INSUFFICIENT') { $severity = self::lowerSeverity($severity); $confidence = 'LOW'; }
                self::addAnomaly($anomalies,'FORECAST_JUMP','',$severity,$confidence,
                    '월말 예상금액이 직전 예측보다 크게 변경되었습니다.',
                    '월말 예상금액이 직전 예측 대비 ' . number_format($changeRate,1) . '% 변경되었습니다.',
                    $forecastAmount,$previousForecast,$difference,round($changeRate,3),array('직전 예측일: ' . (isset($forecast['previous_forecast_date'])?$forecast['previous_forecast_date']:'-'),'최신 예측일: ' . $row['forecast_date'],'최소 확인금액: ' . number_format($minimum) . '원'),
                    '스냅샷 입력 변화와 예측 근거를 확인해주세요.');
            }
        } else {
            $warnings[] = '직전 월말 예측이 없어 예측 급변 규칙을 제외했습니다.';
        }

        $rangeRate = $forecastAmount > 0 ? max(0.0,(float)$row['forecast_high_amount']-(float)$row['forecast_low_amount'])/$forecastAmount*100 : 0.0;
        $previousRangeRate = null;
        if ($previousForecast !== null && $previousForecast > 0 && isset($forecast['previous_forecast_low_amount']) && isset($forecast['previous_forecast_high_amount'])) {
            $previousRangeRate = max(0.0,(float)$forecast['previous_forecast_high_amount']-(float)$forecast['previous_forecast_low_amount'])/$previousForecast*100;
        }
        $expandedPoints = $previousRangeRate !== null ? $rangeRate-$previousRangeRate : 0.0;
        $rangeSeverity = self::rangeExpansionSeverity($rangeRate,$previousRangeRate!==null,$expandedPoints);
        self::addAnomaly($anomalies,'FORECAST_RANGE_EXPANSION','',$rangeSeverity,$previousRangeRate!==null?$baseConfidence:'LOW',
            '월말 예상범위가 넓어졌습니다.','현재 예상범위가 예상금액의 ' . number_format($rangeRate,1) . '%입니다.',
            round($rangeRate,3),$previousRangeRate!==null?round($previousRangeRate,3):null,$previousRangeRate!==null?round($expandedPoints,3):null,round($rangeRate,3),
            array('예상 하한: ' . number_format((float)$row['forecast_low_amount']) . '원','예상 상한: ' . number_format((float)$row['forecast_high_amount']) . '원'),
            '예측 자료상태와 비용별 예상범위를 확인해주세요.');

        if (count($historyRows) >= 3 && $forecastAmount > 0) {
            $comparisonCount++;
            foreach (self::categoryDefinitions() as $key=>$definition) {
                $shares = array();
                foreach ($historyRows as $history) {
                    $total = isset($history['monthly_input_amount'])?(float)$history['monthly_input_amount']:0.0;
                    if ($total <= 0) continue;
                    $value = isset($history[$definition['column']])?(float)$history[$definition['column']]:0.0;
                    $shares[] = max(0.0,$value)/$total*100;
                }
                if (count($shares) < 3) continue;
                $median = AiMonthlyForecastService::median($shares);
                $currentForecast = isset($forecastCategories[$key]['forecast'])?(float)$forecastCategories[$key]['forecast']:0.0;
                $currentShare = $forecastAmount > 0 ? $currentForecast/$forecastAmount*100 : 0.0;
                $points = abs($currentShare-(float)$median);
                $severity = self::mixShiftSeverity($points);
                self::addAnomaly($anomalies,'CATEGORY_MIX_SHIFT',$key,$severity,$baseConfidence,
                    $definition['label'] . '의 예상 비용구성 비중이 과거와 다릅니다.',
                    '현재 비중과 과거 완료월 중앙값의 차이가 ' . number_format($points,1) . '%p입니다.',
                    round($currentShare,3),round((float)$median,3),round($currentShare-(float)$median,3),round($points,3),
                    array('현재 예상 비중: ' . number_format($currentShare,1) . '%','과거 ' . count($shares) . '개월 중앙값: ' . number_format((float)$median,1) . '%'),
                    '공정 진행단계 변화에 따른 정상적인 비용 구성 변화일 수 있습니다.');
            }
        } else {
            $warnings[] = '과거 완료월 3개월 자료가 없어 비용 구성 변화 규칙을 제외했습니다.';
        }

        $latestEventAt = isset($reliability['latest_event_at'])&&$reliability['latest_event_at']!==null?(string)$reliability['latest_event_at']:'';
        foreach ($eventCategories as $event) if (isset($event['latest_input_at']) && (string)$event['latest_input_at'] > $latestEventAt) $latestEventAt = (string)$event['latest_input_at'];
        $row['latest_event_at'] = $latestEventAt!==''?$latestEventAt:null;

        $counts = array('WATCH'=>0,'WARNING'=>0,'CRITICAL'=>0);
        $highest = '';
        $flags = array();
        $recommendations = array();
        foreach ($anomalies as $anomaly) {
            if (isset($counts[$anomaly['severity']])) $counts[$anomaly['severity']]++;
            if (self::severityRank($anomaly['severity']) > self::severityRank($highest)) $highest = $anomaly['severity'];
            $flags[$anomaly['type']] = $anomaly['type'];
            if (!empty($anomaly['recommended_action'])) $recommendations[] = $anomaly['recommended_action'];
        }
        $essentialInsufficient = $row['reliability_score'] === null || (isset($reliability['available_weight']) && (float)$reliability['available_weight'] < 40.0);
        if (count($anomalies) === 0 && $comparisonCount === 0) $essentialInsufficient = true;
        if ($essentialInsufficient) {
            $grade = 'INSUFFICIENT';
            $score = null;
            $dataStatus = 'INSUFFICIENT';
            $warnings[] = '비교자료가 더 쌓이면 해당 이상징후를 분석할 수 있습니다.';
        } else {
            $grade = $highest!==''?$highest:'NORMAL';
            $baseScores = array(''=>0,'WATCH'=>25,'WARNING'=>55,'CRITICAL'=>85);
            $score = isset($baseScores[$highest])?$baseScores[$highest]:0;
            if (count($anomalies) > 1) $score += min(15,(count($anomalies)-1)*5);
            $score = min(100,$score);
            $dataStatus = $comparisonCount >= 3 && $eventAvailable ? 'READY' : 'LIMITED';
        }
        $row['anomaly_score'] = $score;
        $row['anomaly_grade'] = $grade;
        $row['highest_severity'] = $highest;
        $row['anomaly_count'] = count($anomalies);
        $row['watch_count'] = $counts['WATCH'];
        $row['warning_count'] = $counts['WARNING'];
        $row['critical_count'] = $counts['CRITICAL'];
        $row['primary_anomaly_type'] = count($anomalies)>0?$anomalies[0]['type']:null;
        if ($highest !== '') foreach ($anomalies as $anomaly) if ($anomaly['severity'] === $highest) { $row['primary_anomaly_type'] = $anomaly['type']; break; }
        $row['anomaly_type_flags'] = count($flags)>0?implode(',',array_values($flags)):null;
        $row['data_status'] = $dataStatus;
        $row['confidence_level'] = self::confidenceLevel($row['reliability_score'],isset($reliability['available_weight'])?(float)$reliability['available_weight']:0.0,$comparisonCount>0);
        $row['anomaly_data'] = self::encodeData(self::cleanAnomalies($anomalies));
        $row['summary_data'] = self::encodeData(array(
            'comparison_source_count'=>$comparisonCount,'event_analysis_available'=>(bool)$eventAvailable,
            'previous_snapshot_date'=>$previousSnapshotDate,'previous_forecast_date'=>isset($forecast['previous_forecast_date'])?$forecast['previous_forecast_date']:'',
            'historical_month_count'=>count($historyRows),'notice'=>'이상징후는 확인이 필요한 데이터 변화이며 실제 오류나 문제를 확정하는 결과가 아닙니다.'
        ));
        $row['recommendation_data'] = self::encodeData(array_values(array_unique($recommendations)));
        $row['warning_data'] = self::encodeData(array_values(array_unique($warnings)));
        return $row;
    }

    private static function normalizeTrigger($value)
    {
        $value = strtoupper(trim((string)$value));
        return in_array($value,array('MANUAL','CLI','SYSTEM'),true)?$value:'SYSTEM';
    }

    private static function actor($trigger)
    {
        if ($trigger !== 'MANUAL') return array('id'=>null,'name'=>null);
        $user = Auth::user();
        $id = is_array($user)&&isset($user['id'])&&is_numeric($user['id'])?(int)$user['id']:0;
        $name = trim((string)Auth::userName());
        if ($name === '' || strpos($name,'@') !== false) $name = $id>0?'직원 #' . $id:'';
        return array('id'=>$id>0?$id:null,'name'=>$name!==''?self::shortText($name,100):null);
    }

    private static function runUid()
    {
        $random = uniqid((string)mt_rand(),true) . microtime(true);
        if (function_exists('openssl_random_pseudo_bytes')) {
            $bytes = @openssl_random_pseudo_bytes(24);
            if ($bytes !== false) $random .= bin2hex($bytes);
        }
        return 'anomaly_' . self::businessNow('YmdHis') . '_' . substr(hash('sha256',$random),0,32);
    }

    private static function acquireLock($pdo, $analysisDate, $targetYm)
    {
        $name = 'cpms_ai_anomaly_detection_' . str_replace('-','',$analysisDate) . '_' . str_replace('-','',$targetYm);
        try {
            $st = $pdo->prepare('SELECT GET_LOCK(:lock_name,0)');
            if (!$st || !$st->execute(array(':lock_name'=>$name))) return array('ok'=>true,'name'=>'','supported'=>false);
            return array('ok'=>((int)$st->fetchColumn()===1),'name'=>$name,'supported'=>true);
        } catch (Exception $e) {
            return array('ok'=>true,'name'=>'','supported'=>false);
        }
    }

    private static function releaseLock($pdo, $lock)
    {
        if (!$pdo || !is_array($lock) || empty($lock['supported']) || empty($lock['name'])) return;
        try {
            $st = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            if ($st) $st->execute(array(':lock_name'=>$lock['name']));
        } catch (Exception $e) {
        }
    }

    private static function clearStaleRuns($pdo, $analysisDate, $targetYm)
    {
        try {
            $sql = "UPDATE `" . self::RUN_TABLE . "` SET run_status='FAILED',finished_at=NOW(),error_summary='실행 제한시간을 초과해 실패 처리했습니다.' WHERE analysis_date=:analysis_date AND target_ym=:target_ym AND run_status='RUNNING' AND started_at<DATE_SUB(NOW(),INTERVAL 1 HOUR)";
            $st = $pdo->prepare($sql);
            if ($st) $st->execute(array(':analysis_date'=>$analysisDate,':target_ym'=>$targetYm));
        } catch (Exception $e) {
        }
    }

    private static function hasRecentRunning($pdo, $analysisDate, $targetYm)
    {
        try {
            $sql = "SELECT id FROM `" . self::RUN_TABLE . "` WHERE analysis_date=:analysis_date AND target_ym=:target_ym AND run_status='RUNNING' AND started_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR) ORDER BY id DESC LIMIT 1";
            $st = $pdo->prepare($sql);
            if (!$st || !$st->execute(array(':analysis_date'=>$analysisDate,':target_ym'=>$targetYm))) return false;
            return $st->fetchColumn() !== false;
        } catch (Exception $e) {
            return false;
        }
    }

    private static function createRun($pdo, $analysisDate, $context, $trigger, $projectCount)
    {
        $actor = self::actor($trigger);
        $now = self::businessNow('Y-m-d H:i:s');
        $sql = 'INSERT INTO `' . self::RUN_TABLE . '` (run_uid,analysis_date,target_ym,snapshot_date,forecast_date,reliability_date,trigger_type,run_status,project_count,success_count,normal_count,watch_count,warning_count,critical_count,insufficient_count,failure_count,detected_anomaly_count,actor_employee_id,actor_name,started_at,finished_at,error_summary,created_at) VALUES (:run_uid,:analysis_date,:target_ym,:snapshot_date,:forecast_date,:reliability_date,:trigger_type,\'RUNNING\',:project_count,0,0,0,0,0,0,0,0,:actor_employee_id,:actor_name,:started_at,NULL,NULL,:created_at)';
        $st = $pdo->prepare($sql);
        if (!$st) return 0;
        $ok = $st->execute(array(
            ':run_uid'=>self::runUid(),':analysis_date'=>$analysisDate,':target_ym'=>$context['target_ym'],
            ':snapshot_date'=>$context['snapshot_date']!==''?$context['snapshot_date']:null,
            ':forecast_date'=>$context['forecast_date']!==''?$context['forecast_date']:null,
            ':reliability_date'=>$context['reliability_date'],':trigger_type'=>$trigger,':project_count'=>(int)$projectCount,
            ':actor_employee_id'=>$actor['id'],':actor_name'=>$actor['name'],':started_at'=>$now,':created_at'=>$now
        ));
        return $ok?(int)$pdo->lastInsertId():0;
    }

    private static function finishRun($pdo, $runId, $status, $counts, $errorSummary)
    {
        if (!in_array($status,array('COMPLETED','PARTIAL','FAILED'),true)) $status = 'FAILED';
        $sql = 'UPDATE `' . self::RUN_TABLE . '` SET run_status=:run_status,project_count=:project_count,success_count=:success_count,normal_count=:normal_count,watch_count=:watch_count,warning_count=:warning_count,critical_count=:critical_count,insufficient_count=:insufficient_count,failure_count=:failure_count,detected_anomaly_count=:detected_anomaly_count,finished_at=:finished_at,error_summary=:error_summary WHERE id=:id';
        $st = $pdo->prepare($sql);
        if (!$st) return false;
        return $st->execute(array(
            ':run_status'=>$status,':project_count'=>(int)$counts['projects'],':success_count'=>(int)$counts['success'],
            ':normal_count'=>(int)$counts['NORMAL'],':watch_count'=>(int)$counts['WATCH'],
            ':warning_count'=>(int)$counts['WARNING'],':critical_count'=>(int)$counts['CRITICAL'],
            ':insufficient_count'=>(int)$counts['INSUFFICIENT'],':failure_count'=>(int)$counts['failed'],
            ':detected_anomaly_count'=>(int)$counts['anomalies'],':finished_at'=>self::businessNow('Y-m-d H:i:s'),
            ':error_summary'=>$errorSummary!==''?$errorSummary:null,':id'=>(int)$runId
        ));
    }

    public static function runStatus($successCount, $failureCount)
    {
        if ((int)$failureCount === 0) return 'COMPLETED';
        if ((int)$successCount > 0) return 'PARTIAL';
        return 'FAILED';
    }

    public static function saveResult($pdo, $runId, $row)
    {
        $now = self::businessNow('Y-m-d H:i:s');
        $columns = array_values(array_diff(self::requiredResultColumns(),array('id')));
        $params = array();
        foreach ($columns as $column) $params[] = ':' . $column;
        $updates = array();
        foreach ($columns as $column) {
            if (in_array($column,array('analysis_date','target_ym','project_id','first_created_at','calculation_count','created_at'),true)) continue;
            $updates[] = '`' . $column . '`=VALUES(`' . $column . '`)';
        }
        $updates[] = '`calculation_count`=`calculation_count`+1';
        $sql = 'INSERT INTO `' . self::RESULT_TABLE . '` (`' . implode('`,`',$columns) . '`) VALUES (' . implode(',',$params) . ') ON DUPLICATE KEY UPDATE ' . implode(',',$updates);
        $values = array();
        foreach ($columns as $column) {
            if ($column === 'run_id') $value = (int)$runId;
            else if (in_array($column,array('first_created_at','last_calculated_at','created_at','updated_at'),true)) $value = $now;
            else if ($column === 'calculation_count') $value = 1;
            else $value = array_key_exists($column,$row)?$row[$column]:null;
            $values[':' . $column] = $value;
        }
        $st = $pdo->prepare($sql);
        return $st ? $st->execute($values) : false;
    }

    public static function detectLatest($pdo = null, $triggerType = 'SYSTEM')
    {
        $pdo = self::pdo($pdo);
        $analysisDate = self::businessToday();
        $empty = array(
            'ok'=>false,'busy'=>false,'analysis_date'=>$analysisDate,'target_ym'=>'','snapshot_date'=>'','forecast_date'=>'','reliability_date'=>'',
            'status'=>'FAILED','projects'=>0,'success'=>0,'normal'=>0,'watch'=>0,'warning'=>0,'critical'=>0,
            'insufficient'=>0,'anomalies'=>0,'failed'=>0,'message'=>'이상징후 탐지에 실패했습니다.'
        );
        if (!$pdo) { $empty['message'] = 'DB 연결을 확인할 수 없습니다.'; return $empty; }
        if (!self::isInstalled($pdo)) { $empty['message'] = '이상징후 탐지 테이블을 먼저 설치해주세요.'; return $empty; }
        $context = self::latestReliabilityContext($pdo);
        if (empty($context['available'])) { $empty['message'] = '이상징후를 탐지하려면 먼저 입력 신뢰도를 계산해주세요.'; return $empty; }
        foreach (array('target_ym','snapshot_date','forecast_date','reliability_date') as $key) $empty[$key] = $context[$key];
        $trigger = self::normalizeTrigger($triggerType);
        $lock = self::acquireLock($pdo,$analysisDate,$context['target_ym']);
        if (empty($lock['ok'])) { $empty['busy'] = true; $empty['message'] = '이미 이상징후 탐지가 진행 중입니다.'; return $empty; }
        $runId = 0;
        $counts = array('projects'=>0,'success'=>0,'NORMAL'=>0,'WATCH'=>0,'WARNING'=>0,'CRITICAL'=>0,'INSUFFICIENT'=>0,'anomalies'=>0,'failed'=>0);
        try {
            self::clearStaleRuns($pdo,$analysisDate,$context['target_ym']);
            if (self::hasRecentRunning($pdo,$analysisDate,$context['target_ym'])) {
                self::releaseLock($pdo,$lock);
                $empty['busy'] = true;
                $empty['message'] = '이미 이상징후 탐지가 진행 중입니다.';
                return $empty;
            }
            $reliabilityLoaded = false;
            $reliabilityRows = self::loadReliabilityRows($pdo,$context,$reliabilityLoaded);
            if (!$reliabilityLoaded) throw new Exception('anomaly reliability unavailable');
            $counts['projects'] = count($reliabilityRows);
            $projectIds = array();
            foreach ($reliabilityRows as $reliability) if (isset($reliability['project_id']) && (int)$reliability['project_id']>0) $projectIds[(int)$reliability['project_id']] = (int)$reliability['project_id'];
            $forecastLoaded = false;
            $forecasts = self::loadForecastMap($pdo,$projectIds,$context,$forecastLoaded);
            $snapshotLoaded = false;
            $snapshots = self::loadSnapshotMap($pdo,$projectIds,$context,$snapshotLoaded);
            $eventStats = self::loadEventStats($pdo,$projectIds,$analysisDate,$context['target_ym']);
            $historyLoaded = false;
            $history = self::loadHistoricalMix($pdo,$projectIds,$context['target_ym'],$historyLoaded);
            $runId = self::createRun($pdo,$analysisDate,$context,$trigger,$counts['projects']);
            if ($runId <= 0) throw new Exception('anomaly run unavailable');
            foreach ($reliabilityRows as $reliability) {
                try {
                    $projectId = isset($reliability['project_id'])?(int)$reliability['project_id']:0;
                    if ($projectId <= 0) throw new Exception('anomaly project unavailable');
                    $forecast = isset($forecasts[$projectId])?$forecasts[$projectId]:array();
                    $snapshot = isset($snapshots[$projectId])?$snapshots[$projectId]:array();
                    $events = isset($eventStats['projects'][$projectId])?$eventStats['projects'][$projectId]:array();
                    $historyRows = isset($history[$projectId])?$history[$projectId]:array();
                    $row = self::calculateProject($reliability,$forecast,$snapshot,$events,$historyRows,$analysisDate,!empty($eventStats['available']));
                    if (!self::saveResult($pdo,$runId,$row)) throw new Exception('anomaly result save failed');
                    $counts['success']++;
                    $grade = isset($row['anomaly_grade'])?$row['anomaly_grade']:'INSUFFICIENT';
                    if (isset($counts[$grade])) $counts[$grade]++;
                    $counts['anomalies'] += isset($row['anomaly_count'])?(int)$row['anomaly_count']:0;
                } catch (Exception $e) {
                    $counts['failed']++;
                    error_log('[AiAnomalyDetection] project detection failed');
                }
            }
            $status = self::runStatus($counts['success'],$counts['failed']);
            $errorSummary = $counts['failed']>0?'일부 프로젝트 이상징후 탐지 실패: ' . $counts['failed'] . '건':'';
            if ($counts['projects']>0 && $counts['success']===0 && $counts['failed']>0) $errorSummary = '전체 프로젝트 이상징후 탐지 실패: ' . $counts['failed'] . '건';
            if (!self::finishRun($pdo,$runId,$status,$counts,$errorSummary)) throw new Exception('anomaly run finish failed');
            self::releaseLock($pdo,$lock);
            return array(
                'ok'=>$status==='COMPLETED'||$status==='PARTIAL','busy'=>false,'analysis_date'=>$analysisDate,
                'target_ym'=>$context['target_ym'],'snapshot_date'=>$context['snapshot_date'],'forecast_date'=>$context['forecast_date'],
                'reliability_date'=>$context['reliability_date'],'status'=>$status,'projects'=>$counts['projects'],
                'success'=>$counts['success'],'normal'=>$counts['NORMAL'],'watch'=>$counts['WATCH'],'warning'=>$counts['WARNING'],
                'critical'=>$counts['CRITICAL'],'insufficient'=>$counts['INSUFFICIENT'],'anomalies'=>$counts['anomalies'],'failed'=>$counts['failed'],
                'message'=>$status==='COMPLETED'?'이상징후 탐지를 완료했습니다.':($status==='PARTIAL'?'일부 현장을 제외하고 이상징후 결과를 저장했습니다.':'이상징후 탐지에 실패했습니다.')
            );
        } catch (Exception $e) {
            if ($runId > 0) {
                try { self::finishRun($pdo,$runId,'FAILED',$counts,'이상징후 탐지 실행 중 오류가 발생했습니다.'); } catch (Exception $ignored) {}
            }
            error_log('[AiAnomalyDetection] detection run failed');
            self::releaseLock($pdo,$lock);
            return $empty;
        }
    }

    public static function latestResultContext($pdo = null)
    {
        $empty = array('analysis_date'=>'','target_ym'=>'');
        $pdo = self::pdo($pdo);
        if (!$pdo || !self::isInstalled($pdo)) return $empty;
        try {
            $st = $pdo->query('SELECT analysis_date,target_ym FROM `' . self::RESULT_TABLE . '` ORDER BY analysis_date DESC,id DESC LIMIT 1');
            $row = $st?$st->fetch(PDO::FETCH_ASSOC):false;
            return is_array($row)?array('analysis_date'=>(string)$row['analysis_date'],'target_ym'=>(string)$row['target_ym']):$empty;
        } catch (Exception $e) {
            return $empty;
        }
    }

    private static function buildHistoryWhere($filters, &$params)
    {
        $filters = is_array($filters)?$filters:array();
        $params = array();
        $where = array('1=1');
        $date = self::validDate(isset($filters['analysis_date'])?$filters['analysis_date']:'');
        $ym = self::validYm(isset($filters['target_ym'])?$filters['target_ym']:'');
        if ($date !== '') { $where[] = 'r.analysis_date=:analysis_date'; $params[':analysis_date'] = $date; }
        if ($ym !== '') { $where[] = 'r.target_ym=:target_ym'; $params[':target_ym'] = $ym; }
        if (isset($filters['project_id']) && (int)$filters['project_id']>0) { $where[] = 'r.project_id=:project_id'; $params[':project_id'] = (int)$filters['project_id']; }
        if (isset($filters['project_status']) && trim((string)$filters['project_status'])!=='') { $where[] = 'r.project_status_snapshot=:project_status'; $params[':project_status'] = trim((string)$filters['project_status']); }
        if (isset($filters['grade']) && in_array($filters['grade'],array('NORMAL','WATCH','WARNING','CRITICAL','INSUFFICIENT'),true)) { $where[] = 'r.anomaly_grade=:grade'; $params[':grade'] = $filters['grade']; }
        if (isset($filters['anomaly_type']) && isset(self::anomalyLabels()[$filters['anomaly_type']])) { $where[] = 'r.primary_anomaly_type=:anomaly_type'; $params[':anomaly_type'] = $filters['anomaly_type']; }
        if (isset($filters['data_status']) && in_array($filters['data_status'],array('READY','LIMITED','INSUFFICIENT'),true)) { $where[] = 'r.data_status=:data_status'; $params[':data_status'] = $filters['data_status']; }
        if (isset($filters['q']) && trim((string)$filters['q'])!=='') { $where[] = 'r.project_name_snapshot LIKE :q'; $params[':q'] = '%' . trim((string)$filters['q']) . '%'; }
        return implode(' AND ',$where);
    }

    public static function countResults($pdo, $filters)
    {
        $pdo = self::pdo($pdo);
        if (!$pdo || !self::isInstalled($pdo)) return 0;
        $params = array();
        $where = self::buildHistoryWhere($filters,$params);
        try {
            $st = $pdo->prepare('SELECT COUNT(*) FROM `' . self::RESULT_TABLE . '` r WHERE ' . $where);
            if (!$st || !self::bindValues($st,$params) || !$st->execute()) return 0;
            return (int)$st->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    public static function listResults($pdo, $filters, $page, $perPage)
    {
        $pdo = self::pdo($pdo);
        $page = max(1,(int)$page);
        $perPage = max(1,min(100,(int)$perPage));
        if (!$pdo || !self::isInstalled($pdo)) return array();
        $params = array();
        $where = self::buildHistoryWhere($filters,$params);
        $offset = ($page-1)*$perPage;
        try {
            $sql = 'SELECT r.id,r.run_id,r.analysis_date,r.target_ym,r.snapshot_date,r.forecast_date,r.reliability_date,r.project_id,'
                . 'r.project_name_snapshot,r.project_status_snapshot,r.current_input_amount,r.forecast_input_amount,r.forecast_low_amount,'
                . 'r.forecast_high_amount,r.reliability_score,r.reliability_grade,r.anomaly_score,r.anomaly_grade,r.highest_severity,'
                . 'r.anomaly_count,r.watch_count,r.warning_count,r.critical_count,r.primary_anomaly_type,r.anomaly_type_flags,'
                . 'r.snapshot_age_days,r.latest_event_at,r.data_status,r.confidence_level,r.anomaly_data,r.summary_data,'
                . 'r.recommendation_data,r.warning_data,r.first_created_at,r.last_calculated_at,r.calculation_count '
                . 'FROM `' . self::RESULT_TABLE . '` r WHERE ' . $where
                . " ORDER BY CASE r.anomaly_grade WHEN 'CRITICAL' THEN 1 WHEN 'WARNING' THEN 2 WHEN 'WATCH' THEN 3 WHEN 'INSUFFICIENT' THEN 4 ELSE 5 END ASC,"
                . 'r.anomaly_score DESC,r.project_id ASC LIMIT :limit OFFSET :offset';
            $st = $pdo->prepare($sql);
            if (!$st || !self::bindValues($st,$params)) return array();
            if (!$st->bindValue(':limit',$perPage,PDO::PARAM_INT) || !$st->bindValue(':offset',$offset,PDO::PARAM_INT) || !$st->execute()) return array();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows)?$rows:array();
        } catch (Exception $e) {
            return array();
        }
    }

    public static function historySummary($pdo, $filters)
    {
        $empty = array('project_count'=>0,'normal_count'=>0,'watch_count'=>0,'warning_count'=>0,'critical_count'=>0,'insufficient_count'=>0,'anomaly_count'=>0,'last_calculated_at'=>'');
        $pdo = self::pdo($pdo);
        if (!$pdo || !self::isInstalled($pdo)) return $empty;
        $params = array();
        $where = self::buildHistoryWhere($filters,$params);
        try {
            $sql = "SELECT COUNT(*) AS project_count,"
                . "SUM(CASE WHEN r.anomaly_grade='NORMAL' THEN 1 ELSE 0 END) AS normal_count,"
                . "SUM(CASE WHEN r.anomaly_grade='WATCH' THEN 1 ELSE 0 END) AS watch_count,"
                . "SUM(CASE WHEN r.anomaly_grade='WARNING' THEN 1 ELSE 0 END) AS warning_count,"
                . "SUM(CASE WHEN r.anomaly_grade='CRITICAL' THEN 1 ELSE 0 END) AS critical_count,"
                . "SUM(CASE WHEN r.anomaly_grade='INSUFFICIENT' THEN 1 ELSE 0 END) AS insufficient_count,"
                . 'COALESCE(SUM(r.anomaly_count),0) AS anomaly_count,MAX(r.last_calculated_at) AS last_calculated_at '
                . 'FROM `' . self::RESULT_TABLE . '` r WHERE ' . $where;
            $st = $pdo->prepare($sql);
            if (!$st || !self::bindValues($st,$params) || !$st->execute()) return $empty;
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return is_array($row)?array_merge($empty,$row):$empty;
        } catch (Exception $e) {
            return $empty;
        }
    }

    public static function historyOptions($pdo = null)
    {
        $result = array('projects'=>array(),'statuses'=>array(),'dates'=>array(),'months'=>array());
        $pdo = self::pdo($pdo);
        if (!$pdo || !self::isInstalled($pdo)) return $result;
        try {
            $st = $pdo->query('SELECT project_id,MAX(project_name_snapshot) AS project_name FROM `' . self::RESULT_TABLE . '` GROUP BY project_id ORDER BY project_name ASC,project_id ASC');
            if ($st) { $rows = $st->fetchAll(PDO::FETCH_ASSOC); if (is_array($rows)) $result['projects'] = $rows; }
            $st = $pdo->query("SELECT DISTINCT project_status_snapshot AS status FROM `" . self::RESULT_TABLE . "` WHERE project_status_snapshot IS NOT NULL AND project_status_snapshot<>'' ORDER BY project_status_snapshot ASC");
            if ($st) { $rows = $st->fetchAll(PDO::FETCH_ASSOC); if (is_array($rows)) $result['statuses'] = $rows; }
            $st = $pdo->query('SELECT DISTINCT analysis_date FROM `' . self::RESULT_TABLE . '` ORDER BY analysis_date DESC LIMIT 366');
            if ($st) { $rows = $st->fetchAll(PDO::FETCH_COLUMN); if (is_array($rows)) $result['dates'] = $rows; }
            $st = $pdo->query('SELECT DISTINCT target_ym FROM `' . self::RESULT_TABLE . '` ORDER BY target_ym DESC');
            if ($st) { $rows = $st->fetchAll(PDO::FETCH_COLUMN); if (is_array($rows)) $result['months'] = $rows; }
        } catch (Exception $e) {
        }
        return $result;
    }
}
