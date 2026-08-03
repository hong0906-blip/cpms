<?php
/**
 * 저장된 스냅샷·예측·입력 신뢰도·이상징후를 이용한 현장별 손익 위험 분석.
 * 실제 비용 원본을 다시 집계하지 않는다. PHP 5.6 / MySQL 5.6 compatible.
 */

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;
use Exception;
use PDO;

require_once __DIR__ . '/AiDailySnapshotService.php';
require_once __DIR__ . '/AiMonthlyForecastService.php';
require_once __DIR__ . '/AiInputReliabilityService.php';
require_once __DIR__ . '/AiAnomalyDetectionService.php';

class AiProfitRiskService
{
    const RUN_TABLE = 'cpms_ai_profit_risk_runs';
    const RESULT_TABLE = 'cpms_ai_profit_risk_results';
    const FORECAST_TABLE = 'cpms_ai_monthly_forecasts';
    const SNAPSHOT_TABLE = 'cpms_ai_daily_snapshots';
    const RELIABILITY_TABLE = 'cpms_ai_input_reliability';
    const ANOMALY_TABLE = 'cpms_ai_anomaly_results';

    private static $tableCache = array();
    private static $columnCache = array();
    private static $indexCache = array();
    private static $installedCache = array();

    public static function pdo($pdo = null)
    {
        return $pdo ? $pdo : Db::pdo();
    }

    private static function connectionKey($pdo)
    {
        return is_object($pdo) && function_exists('spl_object_hash') ? spl_object_hash($pdo) : 'none';
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
        return AiDailySnapshotService::businessToday();
    }

    private static function businessNow($format)
    {
        try {
            $date = new \DateTime('now', new \DateTimeZone('Asia/Seoul'));
            return $date->format($format);
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
            if (!$st || !$st->execute(array(':table_name'=>$table))) return self::$tableCache[$key] = false;
            return self::$tableCache[$key] = ((int)$st->fetchColumn() > 0);
        } catch (Exception $e) {
            return self::$tableCache[$key] = false;
        }
    }

    public static function getTableColumns($pdo, $table)
    {
        $pdo = self::pdo($pdo);
        $table = trim((string)$table);
        if (!$pdo || !self::validIdentifier($table) || !self::tableExists($pdo,$table)) return array();
        $key = self::connectionKey($pdo) . ':' . $table;
        if (isset(self::$columnCache[$key])) return self::$columnCache[$key];
        $columns = array();
        try {
            $st = $pdo->query('SHOW COLUMNS FROM `' . $table . '`');
            $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
            if (is_array($rows)) foreach ($rows as $row) if (isset($row['Field'])) $columns[(string)$row['Field']] = true;
        } catch (Exception $e) {
            $columns = array();
        }
        return self::$columnCache[$key] = $columns;
    }

    public static function columnExists($pdo, $table, $column)
    {
        $columns = self::getTableColumns($pdo,$table);
        return isset($columns[(string)$column]);
    }

    public static function getTableIndexes($pdo, $table)
    {
        $pdo = self::pdo($pdo);
        $table = trim((string)$table);
        if (!$pdo || !self::validIdentifier($table) || !self::tableExists($pdo,$table)) return array();
        $key = self::connectionKey($pdo) . ':' . $table;
        if (isset(self::$indexCache[$key])) return self::$indexCache[$key];
        $indexes = array();
        try {
            $st = $pdo->query('SHOW INDEX FROM `' . $table . '`');
            $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
            if (is_array($rows)) foreach ($rows as $row) if (isset($row['Key_name'])) $indexes[(string)$row['Key_name']] = true;
        } catch (Exception $e) {
            $indexes = array();
        }
        return self::$indexCache[$key] = $indexes;
    }

    private static function clearSchemaCache($pdo)
    {
        $prefix = self::connectionKey($pdo) . ':';
        foreach (array_keys(self::$tableCache) as $key) if (strpos($key,$prefix) === 0) unset(self::$tableCache[$key]);
        foreach (array_keys(self::$columnCache) as $key) if (strpos($key,$prefix) === 0) unset(self::$columnCache[$key]);
        foreach (array_keys(self::$indexCache) as $key) if (strpos($key,$prefix) === 0) unset(self::$indexCache[$key]);
        unset(self::$installedCache[self::connectionKey($pdo)]);
    }

    public static function requiredRunColumns()
    {
        return array(
            'id','run_uid','analysis_date','target_ym','snapshot_date','forecast_date','reliability_date','anomaly_date',
            'trigger_type','run_status','project_count','success_count','normal_count','watch_count','warning_count',
            'critical_count','insufficient_count','failure_count','monthly_sales_total','monthly_forecast_input_total',
            'monthly_forecast_profit_total','cumulative_sales_total','cumulative_projected_input_total',
            'cumulative_projected_profit_total','actor_employee_id','actor_name','started_at','finished_at','error_summary','created_at'
        );
    }

    public static function requiredRunIndexes()
    {
        return array('PRIMARY','uk_ai_profit_risk_run_uid','idx_ai_profit_risk_run_date','idx_ai_profit_risk_run_status');
    }

    public static function requiredResultColumns()
    {
        return array(
            'id','run_id','analysis_date','target_ym','snapshot_date','forecast_date','reliability_date','anomaly_date',
            'project_id','project_name_snapshot','project_status_snapshot','contract_amount','monthly_sales_amount',
            'monthly_current_input_amount','monthly_forecast_input_amount','monthly_forecast_low_amount','monthly_forecast_high_amount',
            'monthly_forecast_profit_amount','monthly_forecast_cost_rate','monthly_forecast_margin_rate','cumulative_sales_amount',
            'cumulative_current_input_amount','cumulative_projected_input_amount','cumulative_projected_profit_amount',
            'cumulative_projected_cost_rate','cumulative_projected_margin_rate','contract_input_utilization_rate',
            'contract_remaining_after_input','previous_monthly_cost_rate','monthly_cost_rate_change_pp','reliability_score',
            'reliability_grade','anomaly_score','anomaly_grade','sales_basis','confidence_level','data_status','risk_score',
            'risk_grade','highest_severity','primary_risk_type','risk_type_flags','risk_factor_data','summary_data',
            'recommendation_data','warning_data','first_created_at','last_calculated_at','calculation_count','created_at','updated_at'
        );
    }

    public static function requiredResultIndexes()
    {
        return array(
            'PRIMARY','uk_ai_profit_risk_result','idx_ai_profit_risk_project','idx_ai_profit_risk_target_month',
            'idx_ai_profit_risk_grade','idx_ai_profit_risk_score','idx_ai_profit_risk_primary','idx_ai_profit_risk_run'
        );
    }

    public static function createRunTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS cpms_ai_profit_risk_runs (\n"
            . "    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,\n"
            . "    run_uid VARCHAR(64) NOT NULL,\n"
            . "    analysis_date DATE NOT NULL,\n"
            . "    target_ym CHAR(7) NOT NULL,\n"
            . "    snapshot_date DATE NULL,\n"
            . "    forecast_date DATE NULL,\n"
            . "    reliability_date DATE NULL,\n"
            . "    anomaly_date DATE NULL,\n"
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
            . "    monthly_sales_total DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    monthly_forecast_input_total DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    monthly_forecast_profit_total DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    cumulative_sales_total DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    cumulative_projected_input_total DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    cumulative_projected_profit_total DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    actor_employee_id INT NULL,\n"
            . "    actor_name VARCHAR(100) NULL,\n"
            . "    started_at DATETIME NOT NULL,\n"
            . "    finished_at DATETIME NULL,\n"
            . "    error_summary TEXT NULL,\n"
            . "    created_at DATETIME NOT NULL,\n"
            . "    UNIQUE KEY uk_ai_profit_risk_run_uid (run_uid),\n"
            . "    KEY idx_ai_profit_risk_run_date (analysis_date,started_at),\n"
            . "    KEY idx_ai_profit_risk_run_status (run_status,started_at)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    public static function createResultTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS cpms_ai_profit_risk_results (\n"
            . "    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,\n"
            . "    run_id BIGINT UNSIGNED NULL,\n"
            . "    analysis_date DATE NOT NULL,\n"
            . "    target_ym CHAR(7) NOT NULL,\n"
            . "    snapshot_date DATE NULL,\n"
            . "    forecast_date DATE NULL,\n"
            . "    reliability_date DATE NULL,\n"
            . "    anomaly_date DATE NULL,\n"
            . "    project_id INT UNSIGNED NOT NULL,\n"
            . "    project_name_snapshot VARCHAR(190) NULL,\n"
            . "    project_status_snapshot VARCHAR(50) NULL,\n"
            . "    contract_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    monthly_sales_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    monthly_current_input_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    monthly_forecast_input_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    monthly_forecast_low_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    monthly_forecast_high_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    monthly_forecast_profit_amount DECIMAL(18,2) NULL,\n"
            . "    monthly_forecast_cost_rate DECIMAL(8,3) NULL,\n"
            . "    monthly_forecast_margin_rate DECIMAL(8,3) NULL,\n"
            . "    cumulative_sales_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    cumulative_current_input_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    cumulative_projected_input_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    cumulative_projected_profit_amount DECIMAL(18,2) NULL,\n"
            . "    cumulative_projected_cost_rate DECIMAL(8,3) NULL,\n"
            . "    cumulative_projected_margin_rate DECIMAL(8,3) NULL,\n"
            . "    contract_input_utilization_rate DECIMAL(8,3) NULL,\n"
            . "    contract_remaining_after_input DECIMAL(18,2) NULL,\n"
            . "    previous_monthly_cost_rate DECIMAL(8,3) NULL,\n"
            . "    monthly_cost_rate_change_pp DECIMAL(8,3) NULL,\n"
            . "    reliability_score DECIMAL(6,2) NULL,\n"
            . "    reliability_grade VARCHAR(20) NULL,\n"
            . "    anomaly_score DECIMAL(6,2) NULL,\n"
            . "    anomaly_grade VARCHAR(20) NULL,\n"
            . "    sales_basis VARCHAR(30) NOT NULL,\n"
            . "    confidence_level VARCHAR(20) NOT NULL,\n"
            . "    data_status VARCHAR(30) NOT NULL,\n"
            . "    risk_score DECIMAL(6,2) NULL,\n"
            . "    risk_grade VARCHAR(20) NOT NULL,\n"
            . "    highest_severity VARCHAR(20) NOT NULL,\n"
            . "    primary_risk_type VARCHAR(50) NULL,\n"
            . "    risk_type_flags VARCHAR(500) NULL,\n"
            . "    risk_factor_data MEDIUMTEXT NULL,\n"
            . "    summary_data MEDIUMTEXT NULL,\n"
            . "    recommendation_data MEDIUMTEXT NULL,\n"
            . "    warning_data MEDIUMTEXT NULL,\n"
            . "    first_created_at DATETIME NOT NULL,\n"
            . "    last_calculated_at DATETIME NOT NULL,\n"
            . "    calculation_count INT UNSIGNED NOT NULL DEFAULT 1,\n"
            . "    created_at DATETIME NOT NULL,\n"
            . "    updated_at DATETIME NOT NULL,\n"
            . "    UNIQUE KEY uk_ai_profit_risk_result (analysis_date,target_ym,project_id),\n"
            . "    KEY idx_ai_profit_risk_project (project_id,analysis_date),\n"
            . "    KEY idx_ai_profit_risk_target_month (target_ym,analysis_date),\n"
            . "    KEY idx_ai_profit_risk_grade (risk_grade,analysis_date),\n"
            . "    KEY idx_ai_profit_risk_score (risk_score,analysis_date),\n"
            . "    KEY idx_ai_profit_risk_primary (primary_risk_type,analysis_date),\n"
            . "    KEY idx_ai_profit_risk_run (run_id)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    private static function runColumnDefinitions()
    {
        return array(
            'run_uid'=>'VARCHAR(64) NOT NULL','analysis_date'=>'DATE NOT NULL','target_ym'=>'CHAR(7) NOT NULL',
            'snapshot_date'=>'DATE NULL','forecast_date'=>'DATE NULL','reliability_date'=>'DATE NULL','anomaly_date'=>'DATE NULL',
            'trigger_type'=>'VARCHAR(20) NOT NULL','run_status'=>'VARCHAR(20) NOT NULL','project_count'=>'INT UNSIGNED NOT NULL DEFAULT 0',
            'success_count'=>'INT UNSIGNED NOT NULL DEFAULT 0','normal_count'=>'INT UNSIGNED NOT NULL DEFAULT 0','watch_count'=>'INT UNSIGNED NOT NULL DEFAULT 0',
            'warning_count'=>'INT UNSIGNED NOT NULL DEFAULT 0','critical_count'=>'INT UNSIGNED NOT NULL DEFAULT 0','insufficient_count'=>'INT UNSIGNED NOT NULL DEFAULT 0',
            'failure_count'=>'INT UNSIGNED NOT NULL DEFAULT 0','monthly_sales_total'=>'DECIMAL(18,2) NOT NULL DEFAULT 0',
            'monthly_forecast_input_total'=>'DECIMAL(18,2) NOT NULL DEFAULT 0','monthly_forecast_profit_total'=>'DECIMAL(18,2) NOT NULL DEFAULT 0',
            'cumulative_sales_total'=>'DECIMAL(18,2) NOT NULL DEFAULT 0','cumulative_projected_input_total'=>'DECIMAL(18,2) NOT NULL DEFAULT 0',
            'cumulative_projected_profit_total'=>'DECIMAL(18,2) NOT NULL DEFAULT 0','actor_employee_id'=>'INT NULL','actor_name'=>'VARCHAR(100) NULL',
            'started_at'=>'DATETIME NOT NULL','finished_at'=>'DATETIME NULL','error_summary'=>'TEXT NULL','created_at'=>'DATETIME NOT NULL'
        );
    }

    private static function resultColumnDefinitions()
    {
        $definitions = array();
        foreach (self::requiredResultColumns() as $column) $definitions[$column] = 'TEXT NULL';
        unset($definitions['id']);
        $exact = array(
            'run_id'=>'BIGINT UNSIGNED NULL','analysis_date'=>'DATE NOT NULL','target_ym'=>'CHAR(7) NOT NULL','snapshot_date'=>'DATE NULL',
            'forecast_date'=>'DATE NULL','reliability_date'=>'DATE NULL','anomaly_date'=>'DATE NULL','project_id'=>'INT UNSIGNED NOT NULL',
            'project_name_snapshot'=>'VARCHAR(190) NULL','project_status_snapshot'=>'VARCHAR(50) NULL',
            'contract_amount'=>'DECIMAL(18,2) NOT NULL DEFAULT 0','monthly_sales_amount'=>'DECIMAL(18,2) NOT NULL DEFAULT 0',
            'monthly_current_input_amount'=>'DECIMAL(18,2) NOT NULL DEFAULT 0','monthly_forecast_input_amount'=>'DECIMAL(18,2) NOT NULL DEFAULT 0',
            'monthly_forecast_low_amount'=>'DECIMAL(18,2) NOT NULL DEFAULT 0','monthly_forecast_high_amount'=>'DECIMAL(18,2) NOT NULL DEFAULT 0',
            'monthly_forecast_profit_amount'=>'DECIMAL(18,2) NULL','monthly_forecast_cost_rate'=>'DECIMAL(8,3) NULL','monthly_forecast_margin_rate'=>'DECIMAL(8,3) NULL',
            'cumulative_sales_amount'=>'DECIMAL(18,2) NOT NULL DEFAULT 0','cumulative_current_input_amount'=>'DECIMAL(18,2) NOT NULL DEFAULT 0',
            'cumulative_projected_input_amount'=>'DECIMAL(18,2) NOT NULL DEFAULT 0','cumulative_projected_profit_amount'=>'DECIMAL(18,2) NULL',
            'cumulative_projected_cost_rate'=>'DECIMAL(8,3) NULL','cumulative_projected_margin_rate'=>'DECIMAL(8,3) NULL',
            'contract_input_utilization_rate'=>'DECIMAL(8,3) NULL','contract_remaining_after_input'=>'DECIMAL(18,2) NULL',
            'previous_monthly_cost_rate'=>'DECIMAL(8,3) NULL','monthly_cost_rate_change_pp'=>'DECIMAL(8,3) NULL',
            'reliability_score'=>'DECIMAL(6,2) NULL','reliability_grade'=>'VARCHAR(20) NULL','anomaly_score'=>'DECIMAL(6,2) NULL','anomaly_grade'=>'VARCHAR(20) NULL',
            'sales_basis'=>'VARCHAR(30) NOT NULL','confidence_level'=>'VARCHAR(20) NOT NULL','data_status'=>'VARCHAR(30) NOT NULL',
            'risk_score'=>'DECIMAL(6,2) NULL','risk_grade'=>'VARCHAR(20) NOT NULL','highest_severity'=>'VARCHAR(20) NOT NULL',
            'primary_risk_type'=>'VARCHAR(50) NULL','risk_type_flags'=>'VARCHAR(500) NULL','risk_factor_data'=>'MEDIUMTEXT NULL','summary_data'=>'MEDIUMTEXT NULL',
            'recommendation_data'=>'MEDIUMTEXT NULL','warning_data'=>'MEDIUMTEXT NULL','first_created_at'=>'DATETIME NOT NULL','last_calculated_at'=>'DATETIME NOT NULL',
            'calculation_count'=>'INT UNSIGNED NOT NULL DEFAULT 1','created_at'=>'DATETIME NOT NULL','updated_at'=>'DATETIME NOT NULL'
        );
        foreach ($exact as $column=>$definition) $definitions[$column] = $definition;
        return $definitions;
    }

    private static function ensureOwnedTable($pdo, $table, $columns, $indexes, &$updated)
    {
        if (!in_array($table,array(self::RUN_TABLE,self::RESULT_TABLE),true)) throw new Exception('unsupported risk table');
        if (!self::columnExists($pdo,$table,'id')) {
            if ($pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST') === false) throw new Exception('risk schema update failed');
            $updated[] = $table . '.column:id';
            self::clearSchemaCache($pdo);
        }
        foreach ($columns as $column=>$definition) {
            if (!self::columnExists($pdo,$table,$column)) {
                if ($pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition) === false) throw new Exception('risk schema update failed');
                $updated[] = $table . '.column:' . $column;
                self::clearSchemaCache($pdo);
            }
        }
        $existing = self::getTableIndexes($pdo,$table);
        foreach ($indexes as $name=>$definition) {
            if (!isset($existing[$name])) {
                if ($pdo->exec('ALTER TABLE `' . $table . '` ADD ' . $definition) === false) throw new Exception('risk schema update failed');
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
            if (!self::tableExists($pdo,self::RUN_TABLE)) $created[] = self::RUN_TABLE;
            if (!self::tableExists($pdo,self::RESULT_TABLE)) $created[] = self::RESULT_TABLE;
            if ($pdo->exec(self::createRunTableSql()) === false) throw new Exception('risk run install failed');
            if ($pdo->exec(self::createResultTableSql()) === false) throw new Exception('risk result install failed');
            self::clearSchemaCache($pdo);
            self::ensureOwnedTable($pdo,self::RUN_TABLE,self::runColumnDefinitions(),array(
                'PRIMARY'=>'PRIMARY KEY (`id`)','uk_ai_profit_risk_run_uid'=>'UNIQUE KEY `uk_ai_profit_risk_run_uid` (`run_uid`)',
                'idx_ai_profit_risk_run_date'=>'KEY `idx_ai_profit_risk_run_date` (`analysis_date`,`started_at`)',
                'idx_ai_profit_risk_run_status'=>'KEY `idx_ai_profit_risk_run_status` (`run_status`,`started_at`)'
            ),$updated);
            self::ensureOwnedTable($pdo,self::RESULT_TABLE,self::resultColumnDefinitions(),array(
                'PRIMARY'=>'PRIMARY KEY (`id`)','uk_ai_profit_risk_result'=>'UNIQUE KEY `uk_ai_profit_risk_result` (`analysis_date`,`target_ym`,`project_id`)',
                'idx_ai_profit_risk_project'=>'KEY `idx_ai_profit_risk_project` (`project_id`,`analysis_date`)',
                'idx_ai_profit_risk_target_month'=>'KEY `idx_ai_profit_risk_target_month` (`target_ym`,`analysis_date`)',
                'idx_ai_profit_risk_grade'=>'KEY `idx_ai_profit_risk_grade` (`risk_grade`,`analysis_date`)',
                'idx_ai_profit_risk_score'=>'KEY `idx_ai_profit_risk_score` (`risk_score`,`analysis_date`)',
                'idx_ai_profit_risk_primary'=>'KEY `idx_ai_profit_risk_primary` (`primary_risk_type`,`analysis_date`)',
                'idx_ai_profit_risk_run'=>'KEY `idx_ai_profit_risk_run` (`run_id`)'
            ),$updated);
            self::clearSchemaCache($pdo);
            if (!self::isInstalled($pdo)) throw new Exception('risk schema incomplete');
            return array('ok'=>true,'message'=>count($created)>0?'적자·원가율 위험분석 전용 테이블을 설치했습니다.':'적자·원가율 위험분석 테이블 구조를 확인했습니다.','created'=>$created,'updated'=>$updated);
        } catch (Exception $e) {
            return array('ok'=>false,'message'=>'적자·원가율 위험분석 테이블 설치 또는 확인에 실패했습니다.','created'=>$created,'updated'=>$updated);
        }
    }

    public static function isInstalled($pdo = null)
    {
        $pdo = self::pdo($pdo);
        if (!$pdo) return false;
        $key = self::connectionKey($pdo);
        if (array_key_exists($key,self::$installedCache)) return self::$installedCache[$key];
        $required = array(
            self::RUN_TABLE=>array('columns'=>self::requiredRunColumns(),'indexes'=>self::requiredRunIndexes()),
            self::RESULT_TABLE=>array('columns'=>self::requiredResultColumns(),'indexes'=>self::requiredResultIndexes())
        );
        foreach ($required as $table=>$schema) {
            if (!self::tableExists($pdo,$table)) return self::$installedCache[$key] = false;
            foreach ($schema['columns'] as $column) if (!self::columnExists($pdo,$table,$column)) return self::$installedCache[$key] = false;
            $indexes = self::getTableIndexes($pdo,$table);
            foreach ($schema['indexes'] as $index) if (!isset($indexes[$index])) return self::$installedCache[$key] = false;
        }
        return self::$installedCache[$key] = true;
    }

    private static function tableSchemaStatus($pdo, $table, $columns, $indexes)
    {
        $result = array('table_exists'=>false,'installed'=>false,'missing_columns'=>array(),'missing_indexes'=>array());
        $result['table_exists'] = self::tableExists($pdo,$table);
        if (!$result['table_exists']) {
            $result['missing_columns'] = $columns;
            $result['missing_indexes'] = $indexes;
            return $result;
        }
        foreach ($columns as $column) if (!self::columnExists($pdo,$table,$column)) $result['missing_columns'][] = $column;
        $existing = self::getTableIndexes($pdo,$table);
        foreach ($indexes as $index) if (!isset($existing[$index])) $result['missing_indexes'][] = $index;
        $result['installed'] = count($result['missing_columns'])===0 && count($result['missing_indexes'])===0;
        return $result;
    }

    public static function latestForecastContext($pdo = null)
    {
        $empty = array('available'=>false,'forecast_date'=>'','target_ym'=>'','snapshot_date'=>'','project_count'=>0);
        $pdo = self::pdo($pdo);
        if (!$pdo || !self::tableExists($pdo,self::FORECAST_TABLE)) return $empty;
        foreach (array('forecast_date','target_ym','snapshot_date','project_id') as $column) if (!self::columnExists($pdo,self::FORECAST_TABLE,$column)) return $empty;
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
            'latest_reliability_date'=>'','latest_anomaly_date'=>'',
            'result_count'=>0,'project_count'=>0,'latest_analysis_date'=>'','last_calculated_at'=>'','latest_run'=>array()
        );
        if (!$pdo) return $result;
        try {
            $result['latest_forecast'] = self::latestForecastContext($pdo);
            $result['run'] = self::tableSchemaStatus($pdo,self::RUN_TABLE,self::requiredRunColumns(),self::requiredRunIndexes());
            $result['result'] = self::tableSchemaStatus($pdo,self::RESULT_TABLE,self::requiredResultColumns(),self::requiredResultIndexes());
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
                $st = $pdo->query('SELECT id,analysis_date,target_ym,snapshot_date,forecast_date,reliability_date,anomaly_date,trigger_type,run_status,project_count,success_count,normal_count,watch_count,warning_count,critical_count,insufficient_count,failure_count,monthly_sales_total,monthly_forecast_input_total,monthly_forecast_profit_total,cumulative_sales_total,cumulative_projected_input_total,cumulative_projected_profit_total,started_at,finished_at,error_summary FROM `' . self::RUN_TABLE . '` ORDER BY started_at DESC,id DESC LIMIT 1');
                $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
                $result['latest_run'] = is_array($row)?$row:array();
            }
            if (self::tableExists($pdo,self::RELIABILITY_TABLE) && self::columnExists($pdo,self::RELIABILITY_TABLE,'analysis_date')) {
                $st = $pdo->query('SELECT MAX(analysis_date) FROM `' . self::RELIABILITY_TABLE . '`');
                $value = $st ? $st->fetchColumn() : false;
                if ($value!==false && $value!==null) $result['latest_reliability_date'] = (string)$value;
            }
            if (self::tableExists($pdo,self::ANOMALY_TABLE) && self::columnExists($pdo,self::ANOMALY_TABLE,'analysis_date')) {
                $st = $pdo->query('SELECT MAX(analysis_date) FROM `' . self::ANOMALY_TABLE . '`');
                $value = $st ? $st->fetchColumn() : false;
                if ($value!==false && $value!==null) $result['latest_anomaly_date'] = (string)$value;
            }
        } catch (Exception $e) {
        }
        return $result;
    }

    public static function riskLabels()
    {
        return array(
            'MONTHLY_COST_RATE_HIGH'=>'월 예상원가율 상승','MONTHLY_LOSS_RISK'=>'월 예상적자 위험',
            'CUMULATIVE_COST_RATE_HIGH'=>'누적 예상원가율 상승','CUMULATIVE_LOSS_RISK'=>'누적 예상적자 위험',
            'COST_RATE_WORSENING'=>'직전 분석 대비 원가율 악화','CONTRACT_INPUT_HIGH'=>'계약금액 대비 누적 투입 증가',
            'SALES_BASIS_UNCERTAIN'=>'매출기준 불확실','LOW_RELIABILITY'=>'입력 신뢰도 부족',
            'CRITICAL_ANOMALY_PRESENT'=>'긴급 확인 이상징후 존재'
        );
    }

    public static function decodeData($value)
    {
        if (!is_string($value) || trim($value)==='') return array();
        $decoded = json_decode($value,true);
        return is_array($decoded)?$decoded:array();
    }

    private static function encodeData($value)
    {
        if (!is_array($value)) return null;
        $json = json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        return is_string($json)?$json:null;
    }

    private static function shortText($value, $length)
    {
        $value = trim((string)$value);
        return function_exists('mb_substr')?mb_substr($value,0,$length,'UTF-8'):substr($value,0,$length);
    }

    private static function bindValues($st, $params)
    {
        if (!$st) return false;
        foreach ($params as $key=>$value) {
            $type = is_int($value)?PDO::PARAM_INT:($value===null?PDO::PARAM_NULL:PDO::PARAM_STR);
            if (!$st->bindValue($key,$value,$type)) return false;
        }
        return true;
    }

    private static function placeholders($ids, &$params, $prefix)
    {
        $result = array();
        foreach (array_values($ids) as $index=>$id) {
            $key = ':' . $prefix . $index;
            $result[] = $key;
            $params[$key] = (int)$id;
        }
        return $result;
    }

    public static function resolveSalesBasis($snapshot)
    {
        if (!is_array($snapshot)) return 'MISSING';
        $detail = AiDailySnapshotService::decodeData(isset($snapshot['detail_data'])?$snapshot['detail_data']:'');
        $basis = isset($detail['sales_basis'])?strtoupper(trim((string)$detail['sales_basis'])):'';
        if (in_array($basis,array('CONFIRMED','확정','CONFIRMED_SALES'),true)) return 'CONFIRMED';
        if (in_array($basis,array('MIXED','혼합'),true)) return 'MIXED';
        if (in_array($basis,array('EXPECTED','ESTIMATED','AUTO','AUTOMATIC','예상'),true)) return 'ESTIMATED';
        return 'MISSING';
    }

    public static function loadForecastRows($pdo, $context, &$loadOk = null)
    {
        $loadOk = false;
        $pdo = self::pdo($pdo);
        if (!$pdo || empty($context['available'])) return array();
        try {
            $sql = 'SELECT id,forecast_date,target_ym,snapshot_date,project_id,project_name_snapshot,project_status_snapshot,'
                . 'current_input_amount,forecast_input_amount,forecast_low_amount,forecast_high_amount,data_status '
                . 'FROM `' . self::FORECAST_TABLE . '` WHERE forecast_date=:forecast_date AND target_ym=:target_ym ORDER BY project_id ASC,id ASC';
            $st = $pdo->prepare($sql);
            if (!$st || !$st->execute(array(':forecast_date'=>$context['forecast_date'],':target_ym'=>$context['target_ym']))) return array();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows)) return array();
            $loadOk = true;
            return $rows;
        } catch (Exception $e) {
            return array();
        }
    }

    public static function loadSnapshotMap($pdo, $projectIds, $context, &$loadOk = null)
    {
        $loadOk = false;
        $map = array();
        $pdo = self::pdo($pdo);
        if (!$pdo || count($projectIds)===0 || empty($context['snapshot_date']) || !self::tableExists($pdo,self::SNAPSHOT_TABLE)) return $map;
        foreach (array('snapshot_date','target_ym','project_id','contract_amount','monthly_sales_amount','cumulative_sales_amount','monthly_input_amount','cumulative_input_amount','detail_data') as $column) {
            if (!self::columnExists($pdo,self::SNAPSHOT_TABLE,$column)) return $map;
        }
        $params = array(':snapshot_date'=>$context['snapshot_date'],':target_ym'=>$context['target_ym']);
        $holders = self::placeholders($projectIds,$params,'sp');
        try {
            $sql = 'SELECT snapshot_date,target_ym,project_id,contract_amount,monthly_sales_amount,cumulative_sales_amount,'
                . 'monthly_input_amount,cumulative_input_amount,detail_data,data_flags,last_captured_at FROM `' . self::SNAPSHOT_TABLE . '` '
                . 'WHERE snapshot_date=:snapshot_date AND target_ym=:target_ym AND project_id IN (' . implode(',',$holders) . ')';
            $st = $pdo->prepare($sql);
            if (!$st || !self::bindValues($st,$params) || !$st->execute()) return $map;
            while ($row=$st->fetch(PDO::FETCH_ASSOC)) $map[(int)$row['project_id']] = $row;
            $loadOk = true;
        } catch (Exception $e) {
        }
        return $map;
    }

    public static function loadReliabilityMap($pdo, $projectIds, $context, &$loadOk = null)
    {
        $loadOk = false;
        $map = array();
        $pdo = self::pdo($pdo);
        if (!$pdo || count($projectIds)===0 || !self::tableExists($pdo,self::RELIABILITY_TABLE)) return $map;
        foreach (array('analysis_date','target_ym','forecast_date','snapshot_date','project_id','reliability_score','reliability_grade','available_weight','data_status') as $column) {
            if (!self::columnExists($pdo,self::RELIABILITY_TABLE,$column)) return $map;
        }
        $params = array(':target_ym'=>$context['target_ym'],':forecast_date'=>$context['forecast_date']);
        $holders = self::placeholders($projectIds,$params,'rp');
        try {
            $sql = 'SELECT r.analysis_date,r.target_ym,r.forecast_date,r.snapshot_date,r.project_id,r.reliability_score,r.reliability_grade,'
                . 'r.available_weight,r.data_status FROM `' . self::RELIABILITY_TABLE . '` r WHERE r.id=('
                . 'SELECT r2.id FROM `' . self::RELIABILITY_TABLE . '` r2 WHERE r2.project_id=r.project_id AND r2.target_ym=:target_ym '
                . 'AND r2.forecast_date=:forecast_date ORDER BY r2.analysis_date DESC,r2.id DESC LIMIT 1) '
                . 'AND r.project_id IN (' . implode(',',$holders) . ')';
            $st = $pdo->prepare($sql);
            if (!$st || !self::bindValues($st,$params) || !$st->execute()) return $map;
            while ($row=$st->fetch(PDO::FETCH_ASSOC)) $map[(int)$row['project_id']] = $row;
            $loadOk = true;
        } catch (Exception $e) {
        }
        return $map;
    }

    public static function loadAnomalyMap($pdo, $projectIds, $context, &$loadOk = null)
    {
        $loadOk = false;
        $map = array();
        $pdo = self::pdo($pdo);
        if (!$pdo || count($projectIds)===0 || !self::tableExists($pdo,self::ANOMALY_TABLE)) { $loadOk = true; return $map; }
        foreach (array('analysis_date','target_ym','forecast_date','project_id','anomaly_score','anomaly_grade','primary_anomaly_type') as $column) {
            if (!self::columnExists($pdo,self::ANOMALY_TABLE,$column)) return $map;
        }
        $params = array(':target_ym'=>$context['target_ym'],':forecast_date'=>$context['forecast_date']);
        $holders = self::placeholders($projectIds,$params,'ap');
        try {
            $sql = 'SELECT a.analysis_date,a.target_ym,a.forecast_date,a.project_id,a.anomaly_score,a.anomaly_grade,a.primary_anomaly_type '
                . 'FROM `' . self::ANOMALY_TABLE . '` a WHERE a.id=(SELECT a2.id FROM `' . self::ANOMALY_TABLE . '` a2 '
                . 'WHERE a2.project_id=a.project_id AND a2.target_ym=:target_ym AND a2.forecast_date=:forecast_date '
                . 'ORDER BY a2.analysis_date DESC,a2.id DESC LIMIT 1) AND a.project_id IN (' . implode(',',$holders) . ')';
            $st = $pdo->prepare($sql);
            if (!$st || !self::bindValues($st,$params) || !$st->execute()) return $map;
            while ($row=$st->fetch(PDO::FETCH_ASSOC)) $map[(int)$row['project_id']] = $row;
            $loadOk = true;
        } catch (Exception $e) {
        }
        return $map;
    }

    public static function loadPreviousRiskMap($pdo, $projectIds, $targetYm, $analysisDate, &$loadOk = null)
    {
        $loadOk = false;
        $map = array();
        $pdo = self::pdo($pdo);
        if (!$pdo || count($projectIds)===0 || !self::isInstalled($pdo)) return $map;
        $params = array(':target_ym'=>$targetYm,':analysis_date'=>$analysisDate);
        $holders = self::placeholders($projectIds,$params,'pp');
        try {
            $sql = 'SELECT p.analysis_date,p.project_id,p.monthly_forecast_cost_rate FROM `' . self::RESULT_TABLE . '` p WHERE p.id=('
                . 'SELECT p2.id FROM `' . self::RESULT_TABLE . '` p2 WHERE p2.project_id=p.project_id AND p2.target_ym=:target_ym '
                . 'AND p2.analysis_date<:analysis_date ORDER BY p2.analysis_date DESC,p2.id DESC LIMIT 1) '
                . 'AND p.project_id IN (' . implode(',',$holders) . ')';
            $st = $pdo->prepare($sql);
            if (!$st || !self::bindValues($st,$params) || !$st->execute()) return $map;
            while ($row=$st->fetch(PDO::FETCH_ASSOC)) $map[(int)$row['project_id']] = $row;
            $loadOk = true;
        } catch (Exception $e) {
        }
        return $map;
    }

    private static function severityRank($severity)
    {
        $ranks = array(''=>0,'WATCH'=>1,'WARNING'=>2,'CRITICAL'=>3);
        return isset($ranks[$severity])?$ranks[$severity]:0;
    }

    public static function costRateSeverity($rate)
    {
        if ($rate===null || !is_numeric($rate) || (float)$rate<90.0) return '';
        if ((float)$rate>=100.0) return 'CRITICAL';
        if ((float)$rate>=95.0) return 'WARNING';
        return 'WATCH';
    }

    public static function monthlyLossSeverity($loss, $sales)
    {
        $loss = max(0.0,(float)$loss);
        $rate = (float)$sales>0?$loss/(float)$sales*100:0.0;
        if ($loss>=10000000.0 || $rate>=5.0) return 'CRITICAL';
        if ($loss>=5000000.0 || $rate>=3.0) return 'WARNING';
        if ($loss>=1000000.0 || $rate>=1.0) return 'WATCH';
        return '';
    }

    public static function cumulativeLossSeverity($loss, $sales)
    {
        $loss = max(0.0,(float)$loss);
        $rate = (float)$sales>0?$loss/(float)$sales*100:0.0;
        if ($loss>=30000000.0 || $rate>=5.0) return 'CRITICAL';
        if ($loss>=10000000.0 || $rate>=3.0) return 'WARNING';
        if ($loss>=3000000.0 || $rate>=1.0) return 'WATCH';
        return '';
    }

    public static function worseningSeverity($points)
    {
        if ($points===null || !is_numeric($points) || (float)$points<2.0) return '';
        if ((float)$points>=10.0) return 'CRITICAL';
        if ((float)$points>=5.0) return 'WARNING';
        return 'WATCH';
    }

    public static function contractSeverity($rate)
    {
        if ($rate===null || !is_numeric($rate) || (float)$rate<85.0) return '';
        if ((float)$rate>=100.0) return 'CRITICAL';
        if ((float)$rate>=95.0) return 'WARNING';
        return 'WATCH';
    }

    public static function reliabilitySeverity($score)
    {
        if ($score===null || !is_numeric($score) || (float)$score>=70.0) return '';
        return (float)$score<50.0?'WARNING':'WATCH';
    }

    public static function costRateScore($rate)
    {
        if ($rate===null || !is_numeric($rate)) return null;
        $rate = (float)$rate;
        if ($rate>=100.0) return 100.0;
        if ($rate>=95.0) return 75.0;
        if ($rate>=90.0) return 50.0;
        if ($rate>=85.0) return 25.0;
        return 10.0;
    }

    public static function marginScore($rate)
    {
        if ($rate===null || !is_numeric($rate)) return null;
        $rate = (float)$rate;
        if ($rate<0.0) return 100.0;
        if ($rate<5.0) return 75.0;
        if ($rate<10.0) return 50.0;
        if ($rate<15.0) return 25.0;
        return 10.0;
    }

    public static function worseningScore($points)
    {
        if ($points===null || !is_numeric($points)) return null;
        $points = (float)$points;
        if ($points>=10.0) return 100.0;
        if ($points>=5.0) return 70.0;
        if ($points>=2.0) return 35.0;
        return 10.0;
    }

    public static function weightedRiskScore($monthlyCostRate, $monthlyMarginRate, $cumulativeCostRate, $changePoints, $criticalAnomaly)
    {
        $items = array(
            array('score'=>self::costRateScore($monthlyCostRate),'weight'=>35),
            array('score'=>self::marginScore($monthlyMarginRate),'weight'=>25),
            array('score'=>self::costRateScore($cumulativeCostRate),'weight'=>25),
            array('score'=>self::worseningScore($changePoints),'weight'=>15)
        );
        $sum = 0.0;
        $weight = 0.0;
        foreach ($items as $item) {
            if ($item['score']===null) continue;
            $sum += (float)$item['score']*(float)$item['weight'];
            $weight += (float)$item['weight'];
        }
        if ($weight<60.0) return array('score'=>null,'available_weight'=>$weight);
        $score = $sum/$weight;
        if ($criticalAnomaly) {
            $boosted = $score+5.0;
            $score = $score<75.0 && $boosted>=75.0 ? 74.99 : $boosted;
        }
        return array('score'=>round(max(0.0,min(100.0,$score)),2),'available_weight'=>$weight);
    }

    public static function scoreGrade($score)
    {
        if ($score===null || !is_numeric($score)) return 'INSUFFICIENT';
        if ((float)$score>=75.0) return 'CRITICAL';
        if ((float)$score>=55.0) return 'WARNING';
        if ((float)$score>=35.0) return 'WATCH';
        return 'NORMAL';
    }

    public static function confidenceLevel($salesBasis, $reliabilityScore, $snapshotAge, $forecastStatus)
    {
        $salesBasis = strtoupper((string)$salesBasis);
        $forecastStatus = strtoupper((string)$forecastStatus);
        if ($salesBasis==='CONFIRMED' && $reliabilityScore!==null && (float)$reliabilityScore>=70.0
            && $snapshotAge!==null && (int)$snapshotAge<=1 && $forecastStatus==='READY') return 'HIGH';
        if ($salesBasis==='MISSING' || $salesBasis==='ESTIMATED' || $reliabilityScore===null || (float)$reliabilityScore<50.0
            || $snapshotAge===null || (int)$snapshotAge>=3 || in_array($forecastStatus,array('LIMITED','INSUFFICIENT'),true)) return 'LOW';
        return 'MEDIUM';
    }

    private static function addFactor(&$factors, $type, $severity, $confidence, $title, $observed, $baseline, $difference, $unit, $evidence, $action)
    {
        if ($severity==='' || !isset(self::riskLabels()[$type])) return;
        foreach ($factors as $factor) if (isset($factor['type']) && $factor['type']===$type) return;
        $factors[] = array(
            'type'=>$type,'label'=>self::riskLabels()[$type],'severity'=>$severity,'confidence'=>$confidence,
            'title'=>self::shortText($title,300),'observed_value'=>$observed,'baseline_value'=>$baseline,
            'difference_value'=>$difference,'unit'=>self::shortText($unit,20),
            'evidence'=>array_values(array_slice(is_array($evidence)?$evidence:array(),0,10)),
            'recommended_action'=>self::shortText($action,500)
        );
    }

    private static function primaryRisk($factors)
    {
        if (count($factors)===0) return null;
        $priority = array(
            'CUMULATIVE_LOSS_RISK'=>1,'MONTHLY_LOSS_RISK'=>2,'CUMULATIVE_COST_RATE_HIGH'=>3,'MONTHLY_COST_RATE_HIGH'=>4,
            'COST_RATE_WORSENING'=>5,'CONTRACT_INPUT_HIGH'=>6,'SALES_BASIS_UNCERTAIN'=>7,'LOW_RELIABILITY'=>8,'CRITICAL_ANOMALY_PRESENT'=>9
        );
        $selected = $factors[0];
        foreach ($factors as $factor) {
            $factorRank = self::severityRank(isset($factor['severity'])?$factor['severity']:'');
            $selectedRank = self::severityRank(isset($selected['severity'])?$selected['severity']:'');
            $factorPriority = isset($priority[$factor['type']])?$priority[$factor['type']]:99;
            $selectedPriority = isset($priority[$selected['type']])?$priority[$selected['type']]:99;
            if ($factorRank>$selectedRank || ($factorRank===$selectedRank && $factorPriority<$selectedPriority)) $selected = $factor;
        }
        return isset($selected['type'])?$selected['type']:null;
    }

    public static function calculateProject($forecast, $snapshot, $reliability, $anomaly, $previousRisk, $analysisDate)
    {
        $forecast = is_array($forecast)?$forecast:array();
        $snapshot = is_array($snapshot)?$snapshot:array();
        $reliability = is_array($reliability)?$reliability:array();
        $anomaly = is_array($anomaly)?$anomaly:array();
        $previousRisk = is_array($previousRisk)?$previousRisk:array();
        $analysisDate = self::validDate($analysisDate);
        $projectId = isset($forecast['project_id'])?(int)$forecast['project_id']:0;
        $targetYm = self::validYm(isset($forecast['target_ym'])?$forecast['target_ym']:'');
        if ($analysisDate==='' || $projectId<=0 || $targetYm==='') throw new Exception('risk project unavailable');

        $warnings = array('현재 결과는 확정손익이 아닙니다.','향후 잔여 공사비는 포함되지 않았습니다.');
        $recommendations = array();
        $summaries = array();
        $factors = array();
        $snapshotFound = count($snapshot)>0;
        $reliabilityFound = count($reliability)>0 && array_key_exists('reliability_score',$reliability);
        $snapshotDate = $snapshotFound?self::validDate(isset($snapshot['snapshot_date'])?$snapshot['snapshot_date']:''):'';
        $snapshotAge = $snapshotDate!==''?AiInputReliabilityService::ageDays($analysisDate,$snapshotDate):null;
        $salesBasis = self::resolveSalesBasis($snapshot);
        $forecastStatus = isset($forecast['data_status'])?(string)$forecast['data_status']:'INSUFFICIENT';
        $reliabilityScore = $reliabilityFound && $reliability['reliability_score']!==null?(float)$reliability['reliability_score']:null;
        $confidence = self::confidenceLevel($salesBasis,$reliabilityScore,$snapshotAge,$forecastStatus);

        $monthlySales = $snapshotFound&&isset($snapshot['monthly_sales_amount'])?(float)$snapshot['monthly_sales_amount']:0.0;
        $monthlyCurrent = $snapshotFound&&isset($snapshot['monthly_input_amount'])?(float)$snapshot['monthly_input_amount']:(isset($forecast['current_input_amount'])?(float)$forecast['current_input_amount']:0.0);
        $monthlyForecast = isset($forecast['forecast_input_amount'])?(float)$forecast['forecast_input_amount']:0.0;
        $monthlyLow = isset($forecast['forecast_low_amount'])?(float)$forecast['forecast_low_amount']:0.0;
        $monthlyHigh = isset($forecast['forecast_high_amount'])?(float)$forecast['forecast_high_amount']:0.0;
        $cumulativeSales = $snapshotFound&&isset($snapshot['cumulative_sales_amount'])?(float)$snapshot['cumulative_sales_amount']:0.0;
        $cumulativeCurrent = $snapshotFound&&isset($snapshot['cumulative_input_amount'])?(float)$snapshot['cumulative_input_amount']:0.0;
        $projectedRaw = $cumulativeCurrent-$monthlyCurrent+$monthlyForecast;
        $cumulativeProjected = max(0.0,$projectedRaw);
        if ($projectedRaw<0) $warnings[] = '누적 예상투입비 계산값이 음수여서 0원으로 제한했습니다.';
        $contract = $snapshotFound&&isset($snapshot['contract_amount'])?max(0.0,(float)$snapshot['contract_amount']):0.0;

        $salesKnown = $salesBasis!=='MISSING' && $snapshotFound;
        $monthlyProfit = $salesKnown?$monthlySales-$monthlyForecast:null;
        $monthlyCostRate = $salesKnown&&$monthlySales>0?$monthlyForecast/$monthlySales*100:null;
        $monthlyMarginRate = $salesKnown&&$monthlySales>0?$monthlyProfit/$monthlySales*100:null;
        $cumulativeProfit = $salesKnown?$cumulativeSales-$cumulativeProjected:null;
        $cumulativeCostRate = $salesKnown&&$cumulativeSales>0?$cumulativeProjected/$cumulativeSales*100:null;
        $cumulativeMarginRate = $salesKnown&&$cumulativeSales>0?$cumulativeProfit/$cumulativeSales*100:null;
        $contractRate = $contract>0?$cumulativeProjected/$contract*100:null;
        $contractRemaining = $contract>0?$contract-$cumulativeProjected:null;
        $previousRate = isset($previousRisk['monthly_forecast_cost_rate'])&&$previousRisk['monthly_forecast_cost_rate']!==null?(float)$previousRisk['monthly_forecast_cost_rate']:null;
        $costRateChange = $monthlyCostRate!==null&&$previousRate!==null?$monthlyCostRate-$previousRate:null;

        if (!$snapshotFound) $warnings[] = '스냅샷 자료가 없어 손익과 원가율을 분석할 수 없습니다.';
        if ($salesBasis==='MISSING') {
            $warnings[] = '매출기준을 확인할 수 없어 손익과 원가율을 계산하지 않았습니다.';
            $recommendations[] = '대상 월 매출금액과 확정 여부를 확인해주세요.';
        } else if ($salesBasis==='MIXED') {
            $warnings[] = '매출자료가 확정매출과 예상매출을 함께 포함하고 있습니다.';
        } else if ($salesBasis==='ESTIMATED') {
            $warnings[] = '매출자료가 예상금액을 포함하고 있습니다.';
        }
        if ($salesKnown && $monthlySales==0.0) $warnings[] = '매출 0원으로 월 원가율을 계산할 수 없습니다.';
        if ($salesKnown && $cumulativeSales==0.0) $warnings[] = '누적매출 0원으로 누적 원가율을 계산할 수 없습니다.';
        if (!$reliabilityFound || $reliabilityScore===null) $warnings[] = '입력 신뢰도 결과가 없어 분석 신뢰수준이 제한됩니다.';

        $monthlyCostSeverity = self::costRateSeverity($monthlyCostRate);
        self::addFactor($factors,'MONTHLY_COST_RATE_HIGH',$monthlyCostSeverity,$confidence,
            '월 예상원가율이 ' . ($monthlyCostRate!==null?number_format($monthlyCostRate,1) . '%':'확인 불가') . '입니다.',
            $monthlyCostRate,90.0,$monthlyCostRate!==null?$monthlyCostRate-90.0:null,'%',
            array('월 예상매출 ' . number_format($monthlySales) . '원','월 예상투입비 ' . number_format($monthlyForecast) . '원','매출기준: ' . $salesBasis),
            '예상매출과 미입력 비용을 함께 확인해주세요.');

        if ($monthlyProfit!==null && $monthlyProfit<0) {
            $loss = abs($monthlyProfit);
            $severity = self::monthlyLossSeverity($loss,$monthlySales);
            self::addFactor($factors,'MONTHLY_LOSS_RISK',$severity,$confidence,'이번 달 예상손익이 음수입니다.',
                $monthlyProfit,0.0,$monthlyProfit,'원',array('월 예상매출 ' . number_format($monthlySales) . '원','월 예상투입비 ' . number_format($monthlyForecast) . '원'),
                '월 예상매출과 아직 반영되지 않은 비용을 확인해주세요.');
        }

        $cumulativeCostSeverity = self::costRateSeverity($cumulativeCostRate);
        self::addFactor($factors,'CUMULATIVE_COST_RATE_HIGH',$cumulativeCostSeverity,$confidence,
            '누적 예상원가율이 ' . ($cumulativeCostRate!==null?number_format($cumulativeCostRate,1) . '%':'확인 불가') . '입니다.',
            $cumulativeCostRate,90.0,$cumulativeCostRate!==null?$cumulativeCostRate-90.0:null,'%',
            array('누적매출 ' . number_format($cumulativeSales) . '원','누적 예상투입비 ' . number_format($cumulativeProjected) . '원'),
            '누적 매출과 공사 진행률을 함께 확인해주세요.');

        if ($cumulativeProfit!==null && $cumulativeProfit<0) {
            $loss = abs($cumulativeProfit);
            $severity = self::cumulativeLossSeverity($loss,$cumulativeSales);
            self::addFactor($factors,'CUMULATIVE_LOSS_RISK',$severity,$confidence,'누적 예상손익이 음수입니다.',
                $cumulativeProfit,0.0,$cumulativeProfit,'원',array('누적매출 ' . number_format($cumulativeSales) . '원','누적 예상투입비 ' . number_format($cumulativeProjected) . '원'),
                '누적 매출과 계약 변경사항을 확인해주세요.');
        }

        $worseningSeverity = self::worseningSeverity($costRateChange);
        self::addFactor($factors,'COST_RATE_WORSENING',$worseningSeverity,$confidence,
            '직전 분석보다 월 예상원가율이 상승했습니다.',$monthlyCostRate,$previousRate,$costRateChange,'%p',
            array('직전 원가율 ' . number_format((float)$previousRate,1) . '%','현재 원가율 ' . number_format((float)$monthlyCostRate,1) . '%'),
            '예측 투입비와 매출 변경사항을 확인해주세요.');

        $contractSeverity = self::contractSeverity($contractRate);
        self::addFactor($factors,'CONTRACT_INPUT_HIGH',$contractSeverity,$confidence,
            '계약금액 대비 누적 예상투입 수준이 높습니다.',$contractRate,85.0,$contractRate!==null?$contractRate-85.0:null,'%',
            array('계약금액 ' . number_format($contract) . '원','누적 예상투입비 ' . number_format($cumulativeProjected) . '원'),
            '공사 진행률과 잔여 공사비를 함께 확인해주세요.');

        if ($salesBasis==='MIXED' || $salesBasis==='ESTIMATED') {
            self::addFactor($factors,'SALES_BASIS_UNCERTAIN','WATCH',$confidence,'매출자료에 예상값이 포함되어 있습니다.',
                null,null,null,'',array('매출기준: ' . $salesBasis),'대상 월 매출금액과 확정 여부를 확인해주세요.');
        }
        $reliabilitySeverity = self::reliabilitySeverity($reliabilityScore);
        self::addFactor($factors,'LOW_RELIABILITY',$reliabilitySeverity,$confidence,'입력 신뢰도가 제한적입니다.',
            $reliabilityScore,70.0,$reliabilityScore!==null?$reliabilityScore-70.0:null,'점',
            array('입력 신뢰도 ' . ($reliabilityScore!==null?number_format($reliabilityScore,1) . '점':'확인 불가')),
            '입력 신뢰도 상세와 미입력 비용을 확인해주세요.');

        $criticalAnomaly = isset($anomaly['anomaly_grade']) && (string)$anomaly['anomaly_grade']==='CRITICAL';
        if ($criticalAnomaly) {
            self::addFactor($factors,'CRITICAL_ANOMALY_PRESENT','WATCH','LOW','긴급 확인 이상징후가 함께 확인됐습니다.',
                isset($anomaly['anomaly_score'])?$anomaly['anomaly_score']:null,null,null,'점',
                array('이상징후 등급: 긴급 확인','대표 이상징후: ' . (isset($anomaly['primary_anomaly_type'])?$anomaly['primary_anomaly_type']:'-')),
                '이상징후 상세에서 비용 변화를 함께 확인해주세요.');
        }

        $scoreResult = self::weightedRiskScore($monthlyCostRate,$monthlyMarginRate,$cumulativeCostRate,$costRateChange,$criticalAnomaly);
        $riskScore = $scoreResult['score'];
        $grade = self::scoreGrade($riskScore);
        $highest = '';
        $financialHighest = '';
        $financialTypes = array(
            'MONTHLY_COST_RATE_HIGH'=>true,'MONTHLY_LOSS_RISK'=>true,'CUMULATIVE_COST_RATE_HIGH'=>true,
            'CUMULATIVE_LOSS_RISK'=>true,'COST_RATE_WORSENING'=>true,'CONTRACT_INPUT_HIGH'=>true
        );
        $flags = array();
        foreach ($factors as $factor) {
            if (self::severityRank($factor['severity'])>self::severityRank($highest)) $highest = $factor['severity'];
            if (isset($financialTypes[$factor['type']]) && self::severityRank($factor['severity'])>self::severityRank($financialHighest)) $financialHighest = $factor['severity'];
            $flags[$factor['type']] = $factor['type'];
            if (!empty($factor['recommended_action'])) $recommendations[] = $factor['recommended_action'];
        }
        if ($grade!=='INSUFFICIENT' && self::severityRank($financialHighest)>self::severityRank($grade)) $grade = $financialHighest;
        $dataStatus = 'READY';
        if (!$snapshotFound || !$reliabilityFound || $reliabilityScore===null || $salesBasis==='MISSING' || $scoreResult['available_weight']<60.0) {
            $grade = 'INSUFFICIENT';
            $riskScore = null;
            $dataStatus = 'INSUFFICIENT';
        } else if ($confidence!=='HIGH' || strtoupper($forecastStatus)!=='READY') {
            $dataStatus = 'LIMITED';
        }

        if ($monthlyCostRate!==null) $summaries[] = '이번 달 예상원가율은 ' . number_format($monthlyCostRate,1) . '%입니다.';
        if ($monthlyProfit!==null) $summaries[] = '이번 달 예상손익은 ' . number_format($monthlyProfit) . '원입니다.';
        if ($cumulativeCostRate!==null) $summaries[] = '누적 예상원가율은 ' . number_format($cumulativeCostRate,1) . '%입니다.';
        if ($costRateChange!==null) $summaries[] = '직전 분석보다 원가율이 ' . number_format($costRateChange,1) . '%p 변동했습니다.';
        $summaries[] = '매출기준: ' . $salesBasis;
        $recommendations[] = '계약금액 변경사항이 반영됐는지 확인해주세요.';

        return array(
            'analysis_date'=>$analysisDate,'target_ym'=>$targetYm,'snapshot_date'=>$snapshotDate!==''?$snapshotDate:null,
            'forecast_date'=>isset($forecast['forecast_date'])?$forecast['forecast_date']:null,
            'reliability_date'=>isset($reliability['analysis_date'])?$reliability['analysis_date']:null,
            'anomaly_date'=>isset($anomaly['analysis_date'])?$anomaly['analysis_date']:null,
            'project_id'=>$projectId,'project_name_snapshot'=>self::shortText(isset($forecast['project_name_snapshot'])?$forecast['project_name_snapshot']:'',190),
            'project_status_snapshot'=>self::shortText(isset($forecast['project_status_snapshot'])?$forecast['project_status_snapshot']:'',50),
            'contract_amount'=>round($contract,2),'monthly_sales_amount'=>round($monthlySales,2),'monthly_current_input_amount'=>round($monthlyCurrent,2),
            'monthly_forecast_input_amount'=>round($monthlyForecast,2),'monthly_forecast_low_amount'=>round($monthlyLow,2),'monthly_forecast_high_amount'=>round($monthlyHigh,2),
            'monthly_forecast_profit_amount'=>$monthlyProfit!==null?round($monthlyProfit,2):null,'monthly_forecast_cost_rate'=>$monthlyCostRate!==null?round($monthlyCostRate,3):null,
            'monthly_forecast_margin_rate'=>$monthlyMarginRate!==null?round($monthlyMarginRate,3):null,'cumulative_sales_amount'=>round($cumulativeSales,2),
            'cumulative_current_input_amount'=>round($cumulativeCurrent,2),'cumulative_projected_input_amount'=>round($cumulativeProjected,2),
            'cumulative_projected_profit_amount'=>$cumulativeProfit!==null?round($cumulativeProfit,2):null,'cumulative_projected_cost_rate'=>$cumulativeCostRate!==null?round($cumulativeCostRate,3):null,
            'cumulative_projected_margin_rate'=>$cumulativeMarginRate!==null?round($cumulativeMarginRate,3):null,'contract_input_utilization_rate'=>$contractRate!==null?round($contractRate,3):null,
            'contract_remaining_after_input'=>$contractRemaining!==null?round($contractRemaining,2):null,'previous_monthly_cost_rate'=>$previousRate!==null?round($previousRate,3):null,
            'monthly_cost_rate_change_pp'=>$costRateChange!==null?round($costRateChange,3):null,'reliability_score'=>$reliabilityScore!==null?round($reliabilityScore,2):null,
            'reliability_grade'=>isset($reliability['reliability_grade'])?(string)$reliability['reliability_grade']:null,
            'anomaly_score'=>isset($anomaly['anomaly_score'])&&$anomaly['anomaly_score']!==null?(float)$anomaly['anomaly_score']:null,
            'anomaly_grade'=>isset($anomaly['anomaly_grade'])?(string)$anomaly['anomaly_grade']:null,'sales_basis'=>$salesBasis,
            'confidence_level'=>$confidence,'data_status'=>$dataStatus,'risk_score'=>$riskScore,'risk_grade'=>$grade,'highest_severity'=>$highest,
            'primary_risk_type'=>self::primaryRisk($factors),'risk_type_flags'=>count($flags)>0?implode(',',array_values($flags)):null,
            'risk_factor_data'=>self::encodeData($factors),'summary_data'=>self::encodeData(array_values(array_unique($summaries))),
            'recommendation_data'=>self::encodeData(array_values(array_unique($recommendations))),
            'warning_data'=>self::encodeData(array_values(array_unique($warnings))),
            '_available_weight'=>$scoreResult['available_weight'],'_snapshot_age'=>$snapshotAge
        );
    }

    private static function normalizeTrigger($value)
    {
        $value = strtoupper(trim((string)$value));
        return in_array($value,array('MANUAL','CLI','SYSTEM'),true)?$value:'SYSTEM';
    }

    private static function actor($trigger)
    {
        if ($trigger!=='MANUAL') return array('id'=>null,'name'=>null);
        $user = Auth::user();
        $id = is_array($user)&&isset($user['id'])&&is_numeric($user['id'])?(int)$user['id']:0;
        $name = trim((string)Auth::userName());
        if ($name==='' || strpos($name,'@')!==false) $name = $id>0?'직원 #' . $id:'';
        return array('id'=>$id>0?$id:null,'name'=>$name!==''?self::shortText($name,100):null);
    }

    private static function runUid()
    {
        $random = uniqid((string)mt_rand(),true) . microtime(true);
        if (function_exists('openssl_random_pseudo_bytes')) {
            $bytes = @openssl_random_pseudo_bytes(24);
            if ($bytes!==false) $random .= bin2hex($bytes);
        }
        return 'profit_risk_' . self::businessNow('YmdHis') . '_' . substr(hash('sha256',$random),0,28);
    }

    private static function acquireLock($pdo, $analysisDate, $targetYm)
    {
        $name = 'cpms_ai_profit_risk_' . str_replace('-','',$analysisDate) . '_' . str_replace('-','',$targetYm);
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
            $sql = "UPDATE `" . self::RUN_TABLE . "` SET run_status='FAILED',finished_at=NOW(),error_summary='실행 제한시간을 초과해 실패 처리했습니다.' "
                . "WHERE analysis_date=:analysis_date AND target_ym=:target_ym AND run_status='RUNNING' AND started_at<DATE_SUB(NOW(),INTERVAL 1 HOUR)";
            $st = $pdo->prepare($sql);
            if ($st) $st->execute(array(':analysis_date'=>$analysisDate,':target_ym'=>$targetYm));
        } catch (Exception $e) {
        }
    }

    private static function hasRecentRunning($pdo, $analysisDate, $targetYm)
    {
        try {
            $sql = "SELECT id FROM `" . self::RUN_TABLE . "` WHERE analysis_date=:analysis_date AND target_ym=:target_ym "
                . "AND run_status='RUNNING' AND started_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR) ORDER BY id DESC LIMIT 1";
            $st = $pdo->prepare($sql);
            if (!$st || !$st->execute(array(':analysis_date'=>$analysisDate,':target_ym'=>$targetYm))) return false;
            return $st->fetchColumn()!==false;
        } catch (Exception $e) {
            return false;
        }
    }

    private static function maxMapDate($map)
    {
        $date = '';
        foreach ((array)$map as $row) if (isset($row['analysis_date']) && (string)$row['analysis_date']>$date) $date = (string)$row['analysis_date'];
        return $date;
    }

    private static function createRun($pdo, $analysisDate, $context, $trigger, $projectCount)
    {
        $actor = self::actor($trigger);
        $now = self::businessNow('Y-m-d H:i:s');
        $sql = 'INSERT INTO `' . self::RUN_TABLE . '` (run_uid,analysis_date,target_ym,snapshot_date,forecast_date,reliability_date,anomaly_date,trigger_type,run_status,project_count,success_count,normal_count,watch_count,warning_count,critical_count,insufficient_count,failure_count,monthly_sales_total,monthly_forecast_input_total,monthly_forecast_profit_total,cumulative_sales_total,cumulative_projected_input_total,cumulative_projected_profit_total,actor_employee_id,actor_name,started_at,finished_at,error_summary,created_at) VALUES (:run_uid,:analysis_date,:target_ym,:snapshot_date,:forecast_date,:reliability_date,:anomaly_date,:trigger_type,\'RUNNING\',:project_count,0,0,0,0,0,0,0,0,0,0,0,0,0,:actor_employee_id,:actor_name,:started_at,NULL,NULL,:created_at)';
        $st = $pdo->prepare($sql);
        if (!$st) return 0;
        $ok = $st->execute(array(
            ':run_uid'=>self::runUid(),':analysis_date'=>$analysisDate,':target_ym'=>$context['target_ym'],
            ':snapshot_date'=>$context['snapshot_date']!==''?$context['snapshot_date']:null,':forecast_date'=>$context['forecast_date'],
            ':reliability_date'=>$context['reliability_date']!==''?$context['reliability_date']:null,
            ':anomaly_date'=>$context['anomaly_date']!==''?$context['anomaly_date']:null,':trigger_type'=>$trigger,':project_count'=>(int)$projectCount,
            ':actor_employee_id'=>$actor['id'],':actor_name'=>$actor['name'],':started_at'=>$now,':created_at'=>$now
        ));
        return $ok?(int)$pdo->lastInsertId():0;
    }

    public static function runStatus($successCount, $failureCount)
    {
        if ((int)$failureCount===0) return 'COMPLETED';
        return (int)$successCount>0?'PARTIAL':'FAILED';
    }

    private static function finishRun($pdo, $runId, $status, $counts, $errorSummary)
    {
        if (!in_array($status,array('COMPLETED','PARTIAL','FAILED'),true)) $status = 'FAILED';
        $sql = 'UPDATE `' . self::RUN_TABLE . '` SET run_status=:run_status,project_count=:project_count,success_count=:success_count,normal_count=:normal_count,watch_count=:watch_count,warning_count=:warning_count,critical_count=:critical_count,insufficient_count=:insufficient_count,failure_count=:failure_count,monthly_sales_total=:monthly_sales_total,monthly_forecast_input_total=:monthly_forecast_input_total,monthly_forecast_profit_total=:monthly_forecast_profit_total,cumulative_sales_total=:cumulative_sales_total,cumulative_projected_input_total=:cumulative_projected_input_total,cumulative_projected_profit_total=:cumulative_projected_profit_total,finished_at=:finished_at,error_summary=:error_summary WHERE id=:id';
        $st = $pdo->prepare($sql);
        if (!$st) return false;
        return $st->execute(array(
            ':run_status'=>$status,':project_count'=>(int)$counts['projects'],':success_count'=>(int)$counts['success'],
            ':normal_count'=>(int)$counts['NORMAL'],':watch_count'=>(int)$counts['WATCH'],':warning_count'=>(int)$counts['WARNING'],
            ':critical_count'=>(int)$counts['CRITICAL'],':insufficient_count'=>(int)$counts['INSUFFICIENT'],':failure_count'=>(int)$counts['failed'],
            ':monthly_sales_total'=>$counts['monthly_sales'],':monthly_forecast_input_total'=>$counts['monthly_input'],
            ':monthly_forecast_profit_total'=>$counts['monthly_profit'],':cumulative_sales_total'=>$counts['cumulative_sales'],
            ':cumulative_projected_input_total'=>$counts['cumulative_input'],':cumulative_projected_profit_total'=>$counts['cumulative_profit'],
            ':finished_at'=>self::businessNow('Y-m-d H:i:s'),':error_summary'=>$errorSummary!==''?$errorSummary:null,':id'=>(int)$runId
        ));
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
            if ($column==='run_id') $value = (int)$runId;
            else if (in_array($column,array('first_created_at','last_calculated_at','created_at','updated_at'),true)) $value = $now;
            else if ($column==='calculation_count') $value = 1;
            else $value = array_key_exists($column,$row)?$row[$column]:null;
            $values[':' . $column] = $value;
        }
        $st = $pdo->prepare($sql);
        return $st?$st->execute($values):false;
    }

    public static function analyzeLatest($pdo = null, $triggerType = 'SYSTEM')
    {
        $pdo = self::pdo($pdo);
        $analysisDate = self::businessToday();
        $empty = array(
            'ok'=>false,'busy'=>false,'analysis_date'=>$analysisDate,'target_ym'=>'','snapshot_date'=>'','forecast_date'=>'',
            'reliability_date'=>'','anomaly_date'=>'','status'=>'FAILED','projects'=>0,'success'=>0,'normal'=>0,'watch'=>0,
            'warning'=>0,'critical'=>0,'insufficient'=>0,'failed'=>0,'message'=>'적자·원가율 위험분석에 실패했습니다.'
        );
        if (!$pdo) { $empty['message'] = 'DB 연결을 확인할 수 없습니다.'; return $empty; }
        if (!self::isInstalled($pdo)) { $empty['message'] = '적자·원가율 위험분석 테이블을 먼저 설치해주세요.'; return $empty; }
        $context = self::latestForecastContext($pdo);
        if (empty($context['available'])) { $empty['message'] = '적자·원가율 위험을 분석하려면 먼저 월말 예측을 실행해주세요.'; return $empty; }
        $empty['target_ym'] = $context['target_ym'];
        $empty['snapshot_date'] = $context['snapshot_date'];
        $empty['forecast_date'] = $context['forecast_date'];
        $trigger = self::normalizeTrigger($triggerType);
        $lock = self::acquireLock($pdo,$analysisDate,$context['target_ym']);
        if (empty($lock['ok'])) { $empty['busy']=true; $empty['message']='이미 적자·원가율 위험분석이 진행 중입니다.'; return $empty; }
        $runId = 0;
        $counts = array(
            'projects'=>0,'success'=>0,'NORMAL'=>0,'WATCH'=>0,'WARNING'=>0,'CRITICAL'=>0,'INSUFFICIENT'=>0,'failed'=>0,
            'monthly_sales'=>0.0,'monthly_input'=>0.0,'monthly_profit'=>0.0,'cumulative_sales'=>0.0,'cumulative_input'=>0.0,'cumulative_profit'=>0.0
        );
        try {
            self::clearStaleRuns($pdo,$analysisDate,$context['target_ym']);
            if (self::hasRecentRunning($pdo,$analysisDate,$context['target_ym'])) {
                self::releaseLock($pdo,$lock);
                $empty['busy']=true;
                $empty['message']='이미 적자·원가율 위험분석이 진행 중입니다.';
                return $empty;
            }
            $forecastLoaded = false;
            $forecasts = self::loadForecastRows($pdo,$context,$forecastLoaded);
            if (!$forecastLoaded) throw new Exception('risk forecast unavailable');
            $counts['projects'] = count($forecasts);
            $projectIds = array();
            foreach ($forecasts as $forecast) if (isset($forecast['project_id']) && (int)$forecast['project_id']>0) $projectIds[(int)$forecast['project_id']] = (int)$forecast['project_id'];
            $snapshotLoaded = false;
            $snapshots = self::loadSnapshotMap($pdo,$projectIds,$context,$snapshotLoaded);
            $reliabilityLoaded = false;
            $reliabilityMap = self::loadReliabilityMap($pdo,$projectIds,$context,$reliabilityLoaded);
            $anomalyLoaded = false;
            $anomalyMap = self::loadAnomalyMap($pdo,$projectIds,$context,$anomalyLoaded);
            $previousLoaded = false;
            $previousMap = self::loadPreviousRiskMap($pdo,$projectIds,$context['target_ym'],$analysisDate,$previousLoaded);
            $context['reliability_date'] = self::maxMapDate($reliabilityMap);
            $context['anomaly_date'] = self::maxMapDate($anomalyMap);
            $empty['reliability_date'] = $context['reliability_date'];
            $empty['anomaly_date'] = $context['anomaly_date'];
            $runId = self::createRun($pdo,$analysisDate,$context,$trigger,$counts['projects']);
            if ($runId<=0) throw new Exception('risk run unavailable');
            foreach ($forecasts as $forecast) {
                try {
                    $projectId = isset($forecast['project_id'])?(int)$forecast['project_id']:0;
                    if ($projectId<=0) throw new Exception('risk project unavailable');
                    $row = self::calculateProject(
                        $forecast,isset($snapshots[$projectId])?$snapshots[$projectId]:array(),
                        isset($reliabilityMap[$projectId])?$reliabilityMap[$projectId]:array(),
                        isset($anomalyMap[$projectId])?$anomalyMap[$projectId]:array(),
                        isset($previousMap[$projectId])?$previousMap[$projectId]:array(),$analysisDate
                    );
                    if (!self::saveResult($pdo,$runId,$row)) throw new Exception('risk result save failed');
                    $counts['success']++;
                    $grade = isset($row['risk_grade'])?$row['risk_grade']:'INSUFFICIENT';
                    if (isset($counts[$grade])) $counts[$grade]++;
                    $counts['monthly_sales'] += (float)$row['monthly_sales_amount'];
                    $counts['monthly_input'] += (float)$row['monthly_forecast_input_amount'];
                    if ($row['monthly_forecast_profit_amount']!==null) $counts['monthly_profit'] += (float)$row['monthly_forecast_profit_amount'];
                    $counts['cumulative_sales'] += (float)$row['cumulative_sales_amount'];
                    $counts['cumulative_input'] += (float)$row['cumulative_projected_input_amount'];
                    if ($row['cumulative_projected_profit_amount']!==null) $counts['cumulative_profit'] += (float)$row['cumulative_projected_profit_amount'];
                } catch (Exception $e) {
                    $counts['failed']++;
                    error_log('[AiProfitRisk] project analysis failed');
                }
            }
            $status = self::runStatus($counts['success'],$counts['failed']);
            $errorSummary = $counts['failed']>0?'일부 프로젝트 적자·원가율 위험분석 실패: ' . $counts['failed'] . '건':'';
            if ($counts['projects']>0 && $counts['success']===0 && $counts['failed']>0) $errorSummary = '전체 프로젝트 적자·원가율 위험분석 실패: ' . $counts['failed'] . '건';
            if (!self::finishRun($pdo,$runId,$status,$counts,$errorSummary)) throw new Exception('risk finish unavailable');
            self::releaseLock($pdo,$lock);
            return array(
                'ok'=>$status==='COMPLETED'||$status==='PARTIAL','busy'=>false,'analysis_date'=>$analysisDate,'target_ym'=>$context['target_ym'],
                'snapshot_date'=>$context['snapshot_date'],'forecast_date'=>$context['forecast_date'],'reliability_date'=>$context['reliability_date'],
                'anomaly_date'=>$context['anomaly_date'],'status'=>$status,'projects'=>$counts['projects'],'success'=>$counts['success'],
                'normal'=>$counts['NORMAL'],'watch'=>$counts['WATCH'],'warning'=>$counts['WARNING'],'critical'=>$counts['CRITICAL'],
                'insufficient'=>$counts['INSUFFICIENT'],'failed'=>$counts['failed'],
                'message'=>$status==='COMPLETED'?'적자·원가율 위험분석을 완료했습니다.':($status==='PARTIAL'?'일부 현장을 제외하고 위험분석 결과를 저장했습니다.':'적자·원가율 위험분석에 실패했습니다.')
            );
        } catch (Exception $e) {
            if ($runId>0) {
                try { self::finishRun($pdo,$runId,'FAILED',$counts,'적자·원가율 위험분석 실행 중 오류가 발생했습니다.'); } catch (Exception $ignored) {}
            }
            error_log('[AiProfitRisk] analysis run failed');
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
        if ($date!=='') { $where[]='r.analysis_date=:analysis_date'; $params[':analysis_date']=$date; }
        if ($ym!=='') { $where[]='r.target_ym=:target_ym'; $params[':target_ym']=$ym; }
        if (isset($filters['project_id']) && (int)$filters['project_id']>0) { $where[]='r.project_id=:project_id'; $params[':project_id']=(int)$filters['project_id']; }
        if (isset($filters['project_status']) && trim((string)$filters['project_status'])!=='') { $where[]='r.project_status_snapshot=:project_status'; $params[':project_status']=trim((string)$filters['project_status']); }
        if (isset($filters['risk_grade']) && in_array($filters['risk_grade'],array('NORMAL','WATCH','WARNING','CRITICAL','INSUFFICIENT'),true)) { $where[]='r.risk_grade=:risk_grade'; $params[':risk_grade']=$filters['risk_grade']; }
        if (isset($filters['confidence_level']) && in_array($filters['confidence_level'],array('HIGH','MEDIUM','LOW'),true)) { $where[]='r.confidence_level=:confidence_level'; $params[':confidence_level']=$filters['confidence_level']; }
        if (isset($filters['sales_basis']) && in_array($filters['sales_basis'],array('CONFIRMED','MIXED','ESTIMATED','MISSING'),true)) { $where[]='r.sales_basis=:sales_basis'; $params[':sales_basis']=$filters['sales_basis']; }
        if (isset($filters['primary_risk_type']) && isset(self::riskLabels()[$filters['primary_risk_type']])) { $where[]='r.primary_risk_type=:primary_risk_type'; $params[':primary_risk_type']=$filters['primary_risk_type']; }
        if (isset($filters['q']) && trim((string)$filters['q'])!=='') { $where[]='r.project_name_snapshot LIKE :q'; $params[':q']='%' . trim((string)$filters['q']) . '%'; }
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
            $sql = 'SELECT r.id,r.run_id,r.analysis_date,r.target_ym,r.snapshot_date,r.forecast_date,r.reliability_date,r.anomaly_date,'
                . 'r.project_id,r.project_name_snapshot,r.project_status_snapshot,r.contract_amount,r.monthly_sales_amount,r.monthly_current_input_amount,'
                . 'r.monthly_forecast_input_amount,r.monthly_forecast_low_amount,r.monthly_forecast_high_amount,r.monthly_forecast_profit_amount,'
                . 'r.monthly_forecast_cost_rate,r.monthly_forecast_margin_rate,r.cumulative_sales_amount,r.cumulative_current_input_amount,'
                . 'r.cumulative_projected_input_amount,r.cumulative_projected_profit_amount,r.cumulative_projected_cost_rate,r.cumulative_projected_margin_rate,'
                . 'r.contract_input_utilization_rate,r.contract_remaining_after_input,r.previous_monthly_cost_rate,r.monthly_cost_rate_change_pp,'
                . 'r.reliability_score,r.reliability_grade,r.anomaly_score,r.anomaly_grade,r.sales_basis,r.confidence_level,r.data_status,r.risk_score,'
                . 'r.risk_grade,r.highest_severity,r.primary_risk_type,r.risk_type_flags,r.risk_factor_data,r.summary_data,r.recommendation_data,r.warning_data,'
                . 'r.first_created_at,r.last_calculated_at,r.calculation_count FROM `' . self::RESULT_TABLE . '` r WHERE ' . $where
                . " ORDER BY CASE r.risk_grade WHEN 'CRITICAL' THEN 1 WHEN 'WARNING' THEN 2 WHEN 'WATCH' THEN 3 WHEN 'INSUFFICIENT' THEN 4 ELSE 5 END ASC,"
                . 'r.risk_score DESC,CASE WHEN r.monthly_forecast_profit_amount<0 THEN ABS(r.monthly_forecast_profit_amount) ELSE 0 END DESC,r.project_id ASC LIMIT :limit OFFSET :offset';
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
        $empty = array(
            'project_count'=>0,'normal_count'=>0,'watch_count'=>0,'warning_count'=>0,'critical_count'=>0,'insufficient_count'=>0,
            'monthly_sales_total'=>0,'monthly_input_total'=>0,'monthly_profit_total'=>0,'cumulative_profit_total'=>0,'last_calculated_at'=>''
        );
        $pdo = self::pdo($pdo);
        if (!$pdo || !self::isInstalled($pdo)) return $empty;
        $params = array();
        $where = self::buildHistoryWhere($filters,$params);
        try {
            $sql = "SELECT COUNT(*) AS project_count,"
                . "SUM(CASE WHEN r.risk_grade='NORMAL' THEN 1 ELSE 0 END) AS normal_count,"
                . "SUM(CASE WHEN r.risk_grade='WATCH' THEN 1 ELSE 0 END) AS watch_count,"
                . "SUM(CASE WHEN r.risk_grade='WARNING' THEN 1 ELSE 0 END) AS warning_count,"
                . "SUM(CASE WHEN r.risk_grade='CRITICAL' THEN 1 ELSE 0 END) AS critical_count,"
                . "SUM(CASE WHEN r.risk_grade='INSUFFICIENT' THEN 1 ELSE 0 END) AS insufficient_count,"
                . 'COALESCE(SUM(r.monthly_sales_amount),0) AS monthly_sales_total,COALESCE(SUM(r.monthly_forecast_input_amount),0) AS monthly_input_total,'
                . 'COALESCE(SUM(r.monthly_forecast_profit_amount),0) AS monthly_profit_total,COALESCE(SUM(r.cumulative_projected_profit_amount),0) AS cumulative_profit_total,'
                . 'MAX(r.last_calculated_at) AS last_calculated_at FROM `' . self::RESULT_TABLE . '` r WHERE ' . $where;
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
            if ($st) { $rows=$st->fetchAll(PDO::FETCH_ASSOC); if (is_array($rows)) $result['projects']=$rows; }
            $st = $pdo->query("SELECT DISTINCT project_status_snapshot AS status FROM `" . self::RESULT_TABLE . "` WHERE project_status_snapshot IS NOT NULL AND project_status_snapshot<>'' ORDER BY project_status_snapshot ASC");
            if ($st) { $rows=$st->fetchAll(PDO::FETCH_ASSOC); if (is_array($rows)) $result['statuses']=$rows; }
            $st = $pdo->query('SELECT DISTINCT analysis_date FROM `' . self::RESULT_TABLE . '` ORDER BY analysis_date DESC LIMIT 366');
            if ($st) { $rows=$st->fetchAll(PDO::FETCH_COLUMN); if (is_array($rows)) $result['dates']=$rows; }
            $st = $pdo->query('SELECT DISTINCT target_ym FROM `' . self::RESULT_TABLE . '` ORDER BY target_ym DESC');
            if ($st) { $rows=$st->fetchAll(PDO::FETCH_COLUMN); if (is_array($rows)) $result['months']=$rows; }
        } catch (Exception $e) {
        }
        return $result;
    }
}
