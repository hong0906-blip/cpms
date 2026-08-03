<?php
/**
 * 현장별 기본 월말 예상 투입비 서비스.
 *
 * 저장된 일일 스냅샷만 사용하며 운영 비용 원본을 다시 집계하지 않는다.
 * PHP 5.6 / MySQL 5.6 compatible.
 */

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;
use Exception;
use PDO;

require_once __DIR__ . '/CostChangeService.php';
require_once __DIR__ . '/AiDailySnapshotService.php';

class AiMonthlyForecastService
{
    const RUN_TABLE = 'cpms_ai_forecast_runs';
    const FORECAST_TABLE = 'cpms_ai_monthly_forecasts';
    const SNAPSHOT_TABLE = 'cpms_ai_daily_snapshots';

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
            'id','run_uid','forecast_date','target_ym','snapshot_date','trigger_type','run_status',
            'project_count','success_count','failure_count','insufficient_count','current_input_total',
            'forecast_input_total','forecast_low_total','forecast_high_total','actor_employee_id',
            'actor_name','started_at','finished_at','error_summary','created_at'
        );
    }

    public static function requiredRunIndexes()
    {
        return array('PRIMARY','uk_ai_forecast_run_uid','idx_ai_forecast_run_date','idx_ai_forecast_run_status');
    }

    public static function requiredForecastColumns()
    {
        return array(
            'id','run_id','forecast_date','target_ym','snapshot_date','project_id','project_name_snapshot',
            'project_status_snapshot','current_input_amount','forecast_input_amount','forecast_low_amount',
            'forecast_high_amount','remaining_estimated_amount','basis_type','data_status','history_month_count',
            'snapshot_history_count','labor_progress_rate','non_labor_progress_rate','category_forecast_data',
            'basis_detail','warning_data','first_created_at','last_calculated_at','calculation_count','created_at','updated_at'
        );
    }

    public static function requiredForecastIndexes()
    {
        return array(
            'PRIMARY','uk_ai_monthly_forecast','idx_ai_forecast_project','idx_ai_forecast_target_month',
            'idx_ai_forecast_basis','idx_ai_forecast_run'
        );
    }

    public static function createRunTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS cpms_ai_forecast_runs (\n"
            . "    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,\n"
            . "    run_uid VARCHAR(64) NOT NULL,\n"
            . "    forecast_date DATE NOT NULL,\n"
            . "    target_ym CHAR(7) NOT NULL,\n"
            . "    snapshot_date DATE NULL,\n"
            . "    trigger_type VARCHAR(20) NOT NULL,\n"
            . "    run_status VARCHAR(20) NOT NULL,\n"
            . "    project_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    success_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    failure_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    insufficient_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    current_input_total DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    forecast_input_total DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    forecast_low_total DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    forecast_high_total DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    actor_employee_id INT NULL,\n"
            . "    actor_name VARCHAR(100) NULL,\n"
            . "    started_at DATETIME NOT NULL,\n"
            . "    finished_at DATETIME NULL,\n"
            . "    error_summary TEXT NULL,\n"
            . "    created_at DATETIME NOT NULL,\n"
            . "    UNIQUE KEY uk_ai_forecast_run_uid (run_uid),\n"
            . "    KEY idx_ai_forecast_run_date (forecast_date, started_at),\n"
            . "    KEY idx_ai_forecast_run_status (run_status, started_at)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    public static function createForecastTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS cpms_ai_monthly_forecasts (\n"
            . "    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,\n"
            . "    run_id BIGINT UNSIGNED NULL,\n"
            . "    forecast_date DATE NOT NULL,\n"
            . "    target_ym CHAR(7) NOT NULL,\n"
            . "    snapshot_date DATE NULL,\n"
            . "    project_id INT UNSIGNED NOT NULL,\n"
            . "    project_name_snapshot VARCHAR(190) NULL,\n"
            . "    project_status_snapshot VARCHAR(50) NULL,\n"
            . "    current_input_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    forecast_input_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    forecast_low_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    forecast_high_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    remaining_estimated_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    basis_type VARCHAR(30) NOT NULL,\n"
            . "    data_status VARCHAR(30) NOT NULL,\n"
            . "    history_month_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    snapshot_history_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    labor_progress_rate DECIMAL(8,3) NULL,\n"
            . "    non_labor_progress_rate DECIMAL(8,3) NULL,\n"
            . "    category_forecast_data MEDIUMTEXT NULL,\n"
            . "    basis_detail MEDIUMTEXT NULL,\n"
            . "    warning_data MEDIUMTEXT NULL,\n"
            . "    first_created_at DATETIME NOT NULL,\n"
            . "    last_calculated_at DATETIME NOT NULL,\n"
            . "    calculation_count INT UNSIGNED NOT NULL DEFAULT 1,\n"
            . "    created_at DATETIME NOT NULL,\n"
            . "    updated_at DATETIME NOT NULL,\n"
            . "    UNIQUE KEY uk_ai_monthly_forecast (forecast_date, target_ym, project_id),\n"
            . "    KEY idx_ai_forecast_project (project_id, forecast_date),\n"
            . "    KEY idx_ai_forecast_target_month (target_ym, forecast_date),\n"
            . "    KEY idx_ai_forecast_basis (basis_type, data_status),\n"
            . "    KEY idx_ai_forecast_run (run_id)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    private static function runColumnDefinitions()
    {
        return array(
            'run_uid'=>'VARCHAR(64) NOT NULL','forecast_date'=>'DATE NOT NULL','target_ym'=>'CHAR(7) NOT NULL','snapshot_date'=>'DATE NULL',
            'trigger_type'=>'VARCHAR(20) NOT NULL','run_status'=>'VARCHAR(20) NOT NULL','project_count'=>'INT UNSIGNED NOT NULL DEFAULT 0',
            'success_count'=>'INT UNSIGNED NOT NULL DEFAULT 0','failure_count'=>'INT UNSIGNED NOT NULL DEFAULT 0','insufficient_count'=>'INT UNSIGNED NOT NULL DEFAULT 0',
            'current_input_total'=>'DECIMAL(18,2) NOT NULL DEFAULT 0','forecast_input_total'=>'DECIMAL(18,2) NOT NULL DEFAULT 0',
            'forecast_low_total'=>'DECIMAL(18,2) NOT NULL DEFAULT 0','forecast_high_total'=>'DECIMAL(18,2) NOT NULL DEFAULT 0',
            'actor_employee_id'=>'INT NULL','actor_name'=>'VARCHAR(100) NULL','started_at'=>'DATETIME NOT NULL','finished_at'=>'DATETIME NULL',
            'error_summary'=>'TEXT NULL','created_at'=>'DATETIME NOT NULL'
        );
    }

    private static function forecastColumnDefinitions()
    {
        return array(
            'run_id'=>'BIGINT UNSIGNED NULL','forecast_date'=>'DATE NOT NULL','target_ym'=>'CHAR(7) NOT NULL','snapshot_date'=>'DATE NULL',
            'project_id'=>'INT UNSIGNED NOT NULL','project_name_snapshot'=>'VARCHAR(190) NULL','project_status_snapshot'=>'VARCHAR(50) NULL',
            'current_input_amount'=>'DECIMAL(18,2) NOT NULL DEFAULT 0','forecast_input_amount'=>'DECIMAL(18,2) NOT NULL DEFAULT 0',
            'forecast_low_amount'=>'DECIMAL(18,2) NOT NULL DEFAULT 0','forecast_high_amount'=>'DECIMAL(18,2) NOT NULL DEFAULT 0',
            'remaining_estimated_amount'=>'DECIMAL(18,2) NOT NULL DEFAULT 0','basis_type'=>'VARCHAR(30) NOT NULL','data_status'=>'VARCHAR(30) NOT NULL',
            'history_month_count'=>'INT UNSIGNED NOT NULL DEFAULT 0','snapshot_history_count'=>'INT UNSIGNED NOT NULL DEFAULT 0',
            'labor_progress_rate'=>'DECIMAL(8,3) NULL','non_labor_progress_rate'=>'DECIMAL(8,3) NULL',
            'category_forecast_data'=>'MEDIUMTEXT NULL','basis_detail'=>'MEDIUMTEXT NULL','warning_data'=>'MEDIUMTEXT NULL',
            'first_created_at'=>'DATETIME NOT NULL','last_calculated_at'=>'DATETIME NOT NULL','calculation_count'=>'INT UNSIGNED NOT NULL DEFAULT 1',
            'created_at'=>'DATETIME NOT NULL','updated_at'=>'DATETIME NOT NULL'
        );
    }

    private static function ensureOwnedTable($pdo, $table, $columns, $indexes, &$updated)
    {
        if (!in_array($table, array(self::RUN_TABLE,self::FORECAST_TABLE), true)) throw new Exception('unsupported forecast table');
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
            if (!self::tableExists($pdo, self::FORECAST_TABLE)) $created[] = self::FORECAST_TABLE;
            $pdo->exec(self::createRunTableSql());
            $pdo->exec(self::createForecastTableSql());
            self::clearSchemaCache($pdo);
            self::ensureOwnedTable($pdo, self::RUN_TABLE, self::runColumnDefinitions(), array(
                'PRIMARY'=>'PRIMARY KEY (`id`)','uk_ai_forecast_run_uid'=>'UNIQUE KEY `uk_ai_forecast_run_uid` (`run_uid`)',
                'idx_ai_forecast_run_date'=>'KEY `idx_ai_forecast_run_date` (`forecast_date`,`started_at`)',
                'idx_ai_forecast_run_status'=>'KEY `idx_ai_forecast_run_status` (`run_status`,`started_at`)'
            ), $updated);
            self::ensureOwnedTable($pdo, self::FORECAST_TABLE, self::forecastColumnDefinitions(), array(
                'PRIMARY'=>'PRIMARY KEY (`id`)','uk_ai_monthly_forecast'=>'UNIQUE KEY `uk_ai_monthly_forecast` (`forecast_date`,`target_ym`,`project_id`)',
                'idx_ai_forecast_project'=>'KEY `idx_ai_forecast_project` (`project_id`,`forecast_date`)',
                'idx_ai_forecast_target_month'=>'KEY `idx_ai_forecast_target_month` (`target_ym`,`forecast_date`)',
                'idx_ai_forecast_basis'=>'KEY `idx_ai_forecast_basis` (`basis_type`,`data_status`)',
                'idx_ai_forecast_run'=>'KEY `idx_ai_forecast_run` (`run_id`)'
            ), $updated);
            self::clearSchemaCache($pdo);
            if (!self::isInstalled($pdo)) throw new Exception('forecast schema incomplete');
            return array(
                'ok'=>true,
                'message'=>count($created)>0 ? '기본 월말 예측 전용 테이블을 설치했습니다.' : '기본 월말 예측 전용 테이블 구조를 확인했습니다.',
                'created'=>$created,'updated'=>$updated
            );
        } catch (Exception $e) {
            return array('ok'=>false,'message'=>'기본 월말 예측 테이블 설치 또는 확인에 실패했습니다.','created'=>$created,'updated'=>$updated);
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
            self::FORECAST_TABLE=>array('columns'=>self::requiredForecastColumns(),'indexes'=>self::requiredForecastIndexes())
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

    public static function latestSnapshotContext($pdo = null)
    {
        $empty = array('available'=>false,'snapshot_date'=>'','target_ym'=>'','project_count'=>0);
        $pdo = self::pdo($pdo);
        if (!$pdo || !self::tableExists($pdo, self::SNAPSHOT_TABLE)) return $empty;
        foreach (array('snapshot_date','target_ym','project_id') as $column) if (!self::columnExists($pdo, self::SNAPSHOT_TABLE, $column)) return $empty;
        try {
            $st = $pdo->query('SELECT snapshot_date,target_ym FROM `' . self::SNAPSHOT_TABLE . '` ORDER BY snapshot_date DESC,id DESC LIMIT 1');
            $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
            if (!is_array($row)) return $empty;
            $date = self::validDate(isset($row['snapshot_date']) ? $row['snapshot_date'] : '');
            $ym = self::validYm(isset($row['target_ym']) ? $row['target_ym'] : '');
            if ($date==='' || $ym==='') return $empty;
            $countSt = $pdo->prepare('SELECT COUNT(*) FROM `' . self::SNAPSHOT_TABLE . '` WHERE snapshot_date=:snapshot_date AND target_ym=:target_ym');
            $countSt->execute(array(':snapshot_date'=>$date,':target_ym'=>$ym));
            return array('available'=>true,'snapshot_date'=>$date,'target_ym'=>$ym,'project_count'=>(int)$countSt->fetchColumn());
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
            'forecast'=>array('table_exists'=>false,'installed'=>false,'missing_columns'=>array(),'missing_indexes'=>array()),
            'installed'=>false,'latest_snapshot'=>array('available'=>false,'snapshot_date'=>'','target_ym'=>'','project_count'=>0),
            'result_count'=>0,'project_count'=>0,'latest_forecast_date'=>'','last_calculated_at'=>'','latest_run'=>array()
        );
        if (!$pdo) return $result;
        $result['latest_snapshot'] = self::latestSnapshotContext($pdo);
        $result['run'] = self::tableSchemaStatus($pdo, self::RUN_TABLE, self::requiredRunColumns(), self::requiredRunIndexes());
        $result['forecast'] = self::tableSchemaStatus($pdo, self::FORECAST_TABLE, self::requiredForecastColumns(), self::requiredForecastIndexes());
        $result['installed'] = !empty($result['run']['installed']) && !empty($result['forecast']['installed']);
        if (!empty($result['forecast']['installed'])) {
            try {
                $row = $pdo->query('SELECT COUNT(*) AS result_count,COUNT(DISTINCT project_id) AS project_count,MAX(forecast_date) AS latest_forecast_date,MAX(last_calculated_at) AS last_calculated_at FROM `' . self::FORECAST_TABLE . '`')->fetch(PDO::FETCH_ASSOC);
                if (is_array($row)) {
                    $result['result_count'] = isset($row['result_count']) ? (int)$row['result_count'] : 0;
                    $result['project_count'] = isset($row['project_count']) ? (int)$row['project_count'] : 0;
                    $result['latest_forecast_date'] = isset($row['latest_forecast_date']) && $row['latest_forecast_date']!==null ? (string)$row['latest_forecast_date'] : '';
                    $result['last_calculated_at'] = isset($row['last_calculated_at']) && $row['last_calculated_at']!==null ? (string)$row['last_calculated_at'] : '';
                }
            } catch (Exception $e) {
            }
        }
        if (!empty($result['run']['installed'])) {
            try {
                $st = $pdo->query('SELECT id,forecast_date,target_ym,snapshot_date,trigger_type,run_status,project_count,success_count,failure_count,insufficient_count,current_input_total,forecast_input_total,forecast_low_total,forecast_high_total,started_at,finished_at,error_summary FROM `' . self::RUN_TABLE . '` ORDER BY started_at DESC,id DESC LIMIT 1');
                $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
                $result['latest_run'] = is_array($row) ? $row : array();
            } catch (Exception $e) {
            }
        }
        return $result;
    }

    public static function categoryDefinitions()
    {
        return array(
            'labor'=>array('label'=>'노무비','column'=>'labor_amount','period'=>'labor'),
            'outsourcing'=>array('label'=>'외주비','column'=>'outsourcing_amount','period'=>'non_labor'),
            'purchase'=>array('label'=>'구매품','column'=>'purchase_amount','period'=>'non_labor'),
            'material'=>array('label'=>'자재비','column'=>'material_amount','period'=>'non_labor'),
            'equipment'=>array('label'=>'장비비','column'=>'equipment_amount','period'=>'non_labor'),
            'other_expense'=>array('label'=>'기타경비','column'=>'other_expense_amount','period'=>'non_labor'),
            'safety'=>array('label'=>'안전관리비','column'=>'safety_amount','period'=>'non_labor'),
            'health'=>array('label'=>'보건비','column'=>'health_amount','period'=>'non_labor'),
            'other'=>array('label'=>'기타 투입비','column'=>'other_amount','period'=>'non_labor')
        );
    }

    private static function dateObject($value)
    {
        if (self::validDate($value)==='') return null;
        try {
            return new \DateTime($value . ' 00:00:00', new \DateTimeZone('Asia/Seoul'));
        } catch (Exception $e) {
            return null;
        }
    }

    private static function inclusiveProgress($snapshotDate, $startDate, $endDate)
    {
        $snapshot = self::dateObject($snapshotDate);
        $start = self::dateObject($startDate);
        $end = self::dateObject($endDate);
        if (!$snapshot || !$start || !$end || $end < $start) return 0.0;
        if ($snapshot < $start) return 0.0;
        if ($snapshot >= $end) return 1.0;
        $total = (int)floor(($end->getTimestamp() - $start->getTimestamp()) / 86400) + 1;
        $elapsed = (int)floor(($snapshot->getTimestamp() - $start->getTimestamp()) / 86400) + 1;
        if ($total <= 0) return 0.0;
        return max(0.0, min(1.0, (float)$elapsed / (float)$total));
    }

    public static function progressRates($snapshotDate, $targetYm)
    {
        $snapshotDate = self::validDate($snapshotDate);
        $targetYm = self::validYm($targetYm);
        if ($snapshotDate==='' || $targetYm==='') return array('labor'=>0.0,'non_labor'=>0.0);
        $labor = CostChangeService::periodForYm('labor', $targetYm);
        $nonLabor = CostChangeService::periodForYm('material', $targetYm);
        return array(
            'labor'=>self::inclusiveProgress($snapshotDate, $labor['start'], $labor['end']),
            'non_labor'=>self::inclusiveProgress($snapshotDate, $nonLabor['start'], $nonLabor['end'])
        );
    }

    private static function monthOffset($ym, $offset)
    {
        if (self::validYm($ym)==='') return '';
        try {
            $date = new \DateTime($ym . '-01 00:00:00', new \DateTimeZone('Asia/Seoul'));
            $date->modify(($offset>=0 ? '+' : '') . (int)$offset . ' month');
            return $date->format('Y-m');
        } catch (Exception $e) {
            return '';
        }
    }

    private static function monthEnd($ym)
    {
        if (self::validYm($ym)==='') return '';
        try {
            $date = new \DateTime($ym . '-01 00:00:00', new \DateTimeZone('Asia/Seoul'));
            return $date->format('Y-m-t');
        } catch (Exception $e) {
            return '';
        }
    }

    public static function median($values)
    {
        $clean = array();
        foreach ((array)$values as $value) if (is_numeric($value)) $clean[] = (float)$value;
        if (count($clean)===0) return null;
        sort($clean, SORT_NUMERIC);
        $count = count($clean);
        $middle = (int)floor($count / 2);
        return ($count % 2) ? $clean[$middle] : (($clean[$middle-1] + $clean[$middle]) / 2.0);
    }

    public static function percentile($values, $percentile)
    {
        $clean = array();
        foreach ((array)$values as $value) if (is_numeric($value)) $clean[] = (float)$value;
        if (count($clean)===0) return null;
        sort($clean, SORT_NUMERIC);
        $p = max(0.0, min(1.0, (float)$percentile));
        $position = (count($clean)-1) * $p;
        $lower = (int)floor($position);
        $upper = (int)ceil($position);
        if ($lower===$upper) return $clean[$lower];
        $weight = $position - $lower;
        return $clean[$lower] + (($clean[$upper]-$clean[$lower]) * $weight);
    }

    private static function moneyValue($value)
    {
        if (!is_numeric($value)) return 0.0;
        return round((float)$value, 2);
    }

    private static function encodeData($value)
    {
        if (!is_array($value)) return null;
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : null;
    }

    public static function decodeData($value)
    {
        if (!is_string($value) || trim($value)==='') return array();
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : array();
    }

    public static function loadLatestSnapshotProjects($pdo, $snapshotDate, $targetYm, &$loadOk = null)
    {
        $loadOk = false;
        $pdo = self::pdo($pdo);
        if (!$pdo || self::validDate($snapshotDate)==='' || self::validYm($targetYm)==='') return array();
        $columns = array(
            'id','snapshot_date','target_ym','project_id','project_name_snapshot','project_status_snapshot','monthly_input_amount',
            'today_event_count','month_event_count','latest_event_at'
        );
        foreach (self::categoryDefinitions() as $definition) $columns[] = $definition['column'];
        try {
            $sql = 'SELECT `' . implode('`,`', array_unique($columns)) . '` FROM `' . self::SNAPSHOT_TABLE . '` WHERE snapshot_date=:snapshot_date AND target_ym=:target_ym ORDER BY project_id ASC,id ASC';
            $st = $pdo->prepare($sql);
            $st->execute(array(':snapshot_date'=>$snapshotDate,':target_ym'=>$targetYm));
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $loadOk = true;
            return is_array($rows) ? $rows : array();
        } catch (Exception $e) {
            return array();
        }
    }

    public static function loadProjectHistory($pdo, $projectId, $targetYm, &$loadOk = null)
    {
        $loadOk = false;
        $pdo = self::pdo($pdo);
        $projectId = (int)$projectId;
        if (!$pdo || $projectId<=0 || self::validYm($targetYm)==='') return array();
        $startYm = self::monthOffset($targetYm, -18);
        $columns = array('id','snapshot_date','target_ym');
        foreach (self::categoryDefinitions() as $definition) $columns[] = $definition['column'];
        try {
            $sql = 'SELECT `' . implode('`,`', array_unique($columns)) . '` FROM `' . self::SNAPSHOT_TABLE . '` WHERE project_id=:project_id AND target_ym<:target_ym AND target_ym>=:start_ym ORDER BY target_ym ASC,snapshot_date ASC,id ASC';
            $st = $pdo->prepare($sql);
            $st->execute(array(':project_id'=>$projectId,':target_ym'=>$targetYm,':start_ym'=>$startYm));
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $loadOk = true;
            return is_array($rows) ? $rows : array();
        } catch (Exception $e) {
            return array();
        }
    }

    private static function categoryHistory($rows, $categoryKey, $currentProgress)
    {
        $definitions = self::categoryDefinitions();
        if (!isset($definitions[$categoryKey])) return array('finals'=>array(),'ratios'=>array(),'months'=>array());
        $definition = $definitions[$categoryKey];
        $byMonth = array();
        foreach ((array)$rows as $row) {
            $ym = self::validYm(isset($row['target_ym']) ? $row['target_ym'] : '');
            $date = self::validDate(isset($row['snapshot_date']) ? $row['snapshot_date'] : '');
            if ($ym==='' || $date==='') continue;
            if (!isset($byMonth[$ym])) $byMonth[$ym] = array();
            $byMonth[$ym][] = $row;
        }
        krsort($byMonth, SORT_STRING);
        $finals = array();
        $ratios = array();
        $months = array();
        foreach ($byMonth as $ym=>$monthRows) {
            $period = $definition['period']==='labor' ? CostChangeService::periodForYm('labor', $ym) : CostChangeService::periodForYm('material', $ym);
            $finalRequiredDate = $definition['period']==='labor' ? self::monthEnd($ym) : $period['end'];
            $finalRow = null;
            foreach ($monthRows as $row) {
                $date = isset($row['snapshot_date']) ? (string)$row['snapshot_date'] : '';
                if ($date >= $finalRequiredDate) $finalRow = $row;
            }
            if (!is_array($finalRow)) continue;
            $finalAmount = isset($finalRow[$definition['column']]) ? max(0.0, (float)$finalRow[$definition['column']]) : 0.0;
            if ($finalAmount<=0) continue;
            $finals[] = $finalAmount;
            $months[] = $ym;
            $closest = null;
            $closestDiff = null;
            foreach ($monthRows as $row) {
                $date = isset($row['snapshot_date']) ? (string)$row['snapshot_date'] : '';
                if ($date > $finalRequiredDate) continue;
                $rates = self::progressRates($date, $ym);
                $progress = isset($rates[$definition['period']]) ? (float)$rates[$definition['period']] : 0.0;
                $diff = abs($progress - (float)$currentProgress);
                if ($diff<=0.10 && ($closestDiff===null || $diff<$closestDiff)) {
                    $closest = $row;
                    $closestDiff = $diff;
                }
            }
            if (is_array($closest)) {
                $pointAmount = isset($closest[$definition['column']]) ? max(0.0, (float)$closest[$definition['column']]) : 0.0;
                $ratio = $finalAmount>0 ? $pointAmount/$finalAmount : 0.0;
                if ($ratio>=0.03 && $ratio<=1.50) $ratios[] = $ratio;
            }
        }
        return array('finals'=>$finals,'ratios'=>$ratios,'months'=>$months);
    }

    private static function normalizeBounds($current, $forecast, $low, $high)
    {
        $current = max(0.0, self::moneyValue($current));
        $forecast = max($current, self::moneyValue($forecast));
        $low = max($current, self::moneyValue($low));
        $high = max($low, self::moneyValue($high));
        return array('current'=>$current,'forecast'=>$forecast,'low'=>$low,'high'=>$high);
    }

    public static function forecastCategory($current, $progressRate, $history)
    {
        $current = max(0.0, self::moneyValue($current));
        $progressRate = max(0.0, min(1.0, (float)$progressRate));
        $finals = isset($history['finals']) && is_array($history['finals']) ? array_slice($history['finals'], 0, 6) : array();
        $ratios = isset($history['ratios']) && is_array($history['ratios']) ? $history['ratios'] : array();
        $historyCount = count($finals);
        $result = array(
            'current'=>$current,'forecast'=>$current,'low'=>$current,'high'=>$current,
            'progress_rate'=>round($progressRate*100,3),'basis_type'=>'INSUFFICIENT','data_status'=>'INSUFFICIENT',
            'history_month_count'=>$historyCount,'guide'=>'예측자료 부족','warning'=>'일일 스냅샷과 월별 자료가 더 쌓이면 자동으로 개선됩니다.'
        );

        if ($current>0 && count($ratios)>=3) {
            $medianRatio = self::median($ratios);
            $q25 = self::percentile($ratios, 0.25);
            $q75 = self::percentile($ratios, 0.75);
            if ($medianRatio!==null && $medianRatio>0 && $q25!==null && $q25>0 && $q75!==null && $q75>0) {
                $bounds = self::normalizeBounds($current, $current/$medianRatio, $current/$q75, $current/$q25);
                return array_merge($result, $bounds, array(
                    'basis_type'=>'HISTORICAL_RATIO','data_status'=>'READY','history_month_count'=>count($ratios),
                    'guide'=>'과거 동일 진행시점 ' . count($ratios) . '개월 입력완료율 중앙값',
                    'warning'=>'과거 입력완료율을 이용한 기본 통계 예측입니다.'
                ));
            }
        }

        if ($historyCount>=3) {
            $median = self::median($finals);
            $q25 = self::percentile($finals, 0.25);
            $q75 = self::percentile($finals, 0.75);
            $bounds = self::normalizeBounds($current, $median, $q25, $q75);
            return array_merge($result, $bounds, array(
                'basis_type'=>'RECENT_MEDIAN','data_status'=>'LIMITED','history_month_count'=>$historyCount,
                'guide'=>'최근 ' . $historyCount . '개월 월 투입금액 중앙값',
                'warning'=>'현재 월의 공사상황이 과거와 다를 수 있습니다.'
            ));
        }

        if ($current>0 && $progressRate>=0.20) {
            $linear = $current/$progressRate;
            $bounds = self::normalizeBounds($current, $linear, $linear*0.70, $linear*1.30);
            return array_merge($result, $bounds, array(
                'basis_type'=>'LINEAR','data_status'=>'LIMITED','history_month_count'=>$historyCount,
                'guide'=>'단순 기간 진행률을 이용한 기초 추정',
                'warning'=>'기간 진행률만 사용한 참고용 범위입니다.'
            ));
        }

        if ($historyCount>0) {
            $recent = self::median($finals);
            $result['high'] = max($current, self::moneyValue($recent));
            $result['warning'] = '예측자료 부족: 최근 월 자료는 상한 참고값으로만 사용했습니다.';
        } else {
            $result['warning'] = '범위 계산 불가: 일일 스냅샷과 월별 자료가 더 쌓이면 자동으로 개선됩니다.';
        }
        return $result;
    }

    private static function representativeBasis($categories)
    {
        $counts = array();
        foreach ($categories as $category) {
            $active = (float)$category['current']>0 || (float)$category['forecast']>0 || (int)$category['history_month_count']>0;
            if (!$active) continue;
            $basis = isset($category['basis_type']) ? (string)$category['basis_type'] : 'INSUFFICIENT';
            if (!isset($counts[$basis])) $counts[$basis] = 0;
            $counts[$basis]++;
        }
        if (count($counts)===0) return array('basis'=>'INSUFFICIENT','counts'=>$counts);
        arsort($counts, SORT_NUMERIC);
        $keys = array_keys($counts);
        $basis = $keys[0];
        if (isset($keys[1]) && $counts[$keys[1]]===$counts[$basis]) $basis = 'MIXED';
        return array('basis'=>$basis,'counts'=>$counts);
    }

    private static function projectDataStatus($categories)
    {
        $activeCount = 0;
        $insufficientCount = 0;
        $limitedCount = 0;
        $totalAmount = 0.0;
        $insufficientAmount = 0.0;
        foreach ($categories as $category) {
            $active = (float)$category['current']>0 || (float)$category['forecast']>0 || (int)$category['history_month_count']>0;
            if (!$active) continue;
            $activeCount++;
            $amount = max((float)$category['forecast'], (float)$category['current']);
            $totalAmount += $amount;
            if ($category['data_status']==='INSUFFICIENT') {
                $insufficientCount++;
                $insufficientAmount += $amount;
            } else if ($category['data_status']==='LIMITED') {
                $limitedCount++;
            }
        }
        if ($activeCount===0) return 'INSUFFICIENT';
        $share = $totalAmount>0 ? $insufficientAmount/$totalAmount : 0.0;
        if ($share>=0.25 || $insufficientCount>($activeCount/2)) return 'INSUFFICIENT';
        if ($limitedCount>0 || $insufficientCount>0) return 'LIMITED';
        return 'READY';
    }

    public static function calculateProject($snapshot, $historyRows)
    {
        $projectId = isset($snapshot['project_id']) ? (int)$snapshot['project_id'] : 0;
        $snapshotDate = self::validDate(isset($snapshot['snapshot_date']) ? $snapshot['snapshot_date'] : '');
        $targetYm = self::validYm(isset($snapshot['target_ym']) ? $snapshot['target_ym'] : '');
        if ($projectId<=0 || $snapshotDate==='' || $targetYm==='') throw new Exception('forecast project unavailable');
        $rates = self::progressRates($snapshotDate, $targetYm);
        $categories = array();
        $historyMonths = array();
        $warnings = array('현재 결과는 기본 통계 예측이며 확정금액이 아닙니다.');
        foreach (self::categoryDefinitions() as $key=>$definition) {
            $current = isset($snapshot[$definition['column']]) ? max(0.0, (float)$snapshot[$definition['column']]) : 0.0;
            $progress = isset($rates[$definition['period']]) ? $rates[$definition['period']] : 0.0;
            $history = self::categoryHistory($historyRows, $key, $progress);
            foreach ($history['months'] as $month) $historyMonths[$month] = true;
            $category = self::forecastCategory($current, $progress, $history);
            $category['label'] = $definition['label'];
            $categories[$key] = $category;
            if ($category['data_status']!=='READY') $warnings[] = $definition['label'] . ': ' . $category['warning'];
        }

        $currentTotal = isset($snapshot['monthly_input_amount']) ? max(0.0, (float)$snapshot['monthly_input_amount']) : 0.0;
        $forecastTotal = 0.0;
        $lowTotal = 0.0;
        $highTotal = 0.0;
        foreach ($categories as $category) {
            $forecastTotal += (float)$category['forecast'];
            $lowTotal += (float)$category['low'];
            $highTotal += (float)$category['high'];
        }
        if ($forecastTotal<$currentTotal) {
            $difference = $currentTotal-$forecastTotal;
            $categories['other']['forecast'] += $difference;
            $forecastTotal += $difference;
        }
        if ($lowTotal<$currentTotal) {
            $difference = $currentTotal-$lowTotal;
            $categories['other']['low'] += $difference;
            $lowTotal += $difference;
        }
        if ($highTotal<$lowTotal) {
            $difference = $lowTotal-$highTotal;
            $categories['other']['high'] += $difference;
            $highTotal += $difference;
        }
        $forecastTotal = self::moneyValue($forecastTotal);
        $lowTotal = self::moneyValue($lowTotal);
        $highTotal = self::moneyValue($highTotal);
        $representative = self::representativeBasis($categories);
        $dataStatus = self::projectDataStatus($categories);
        $basisDetail = array(
            'version'=>'BASIC_MONTH_END_V1','snapshot_date'=>$snapshotDate,'target_ym'=>$targetYm,
            'representative_basis'=>$representative['basis'],'basis_counts'=>$representative['counts'],
            'labor_progress_rate'=>round($rates['labor']*100,3),'non_labor_progress_rate'=>round($rates['non_labor']*100,3),
            'input_activity'=>array(
                'today_event_count'=>isset($snapshot['today_event_count'])?(int)$snapshot['today_event_count']:0,
                'month_event_count'=>isset($snapshot['month_event_count'])?(int)$snapshot['month_event_count']:0,
                'latest_event_at'=>isset($snapshot['latest_event_at']) && $snapshot['latest_event_at']!==null?(string)$snapshot['latest_event_at']:''
            ),
            'notice'=>'입력 신뢰도와 담당자별 입력 지연은 다음 단계에서 반영됩니다.'
        );
        return array(
            'forecast_date'=>self::businessToday(),'target_ym'=>$targetYm,'snapshot_date'=>$snapshotDate,'project_id'=>$projectId,
            'project_name_snapshot'=>isset($snapshot['project_name_snapshot']) ? trim((string)$snapshot['project_name_snapshot']) : '',
            'project_status_snapshot'=>isset($snapshot['project_status_snapshot']) ? trim((string)$snapshot['project_status_snapshot']) : '',
            'current_input_amount'=>self::moneyValue($currentTotal),'forecast_input_amount'=>$forecastTotal,
            'forecast_low_amount'=>$lowTotal,'forecast_high_amount'=>$highTotal,
            'remaining_estimated_amount'=>max(0.0, self::moneyValue($forecastTotal-$currentTotal)),
            'basis_type'=>$representative['basis'],'data_status'=>$dataStatus,
            'history_month_count'=>count($historyMonths),'snapshot_history_count'=>count($historyRows),
            'labor_progress_rate'=>round($rates['labor']*100,3),'non_labor_progress_rate'=>round($rates['non_labor']*100,3),
            'category_forecast_data'=>self::encodeData($categories),'basis_detail'=>self::encodeData($basisDetail),
            'warning_data'=>self::encodeData(array_values(array_unique($warnings)))
        );
    }

    private static function normalizeTrigger($value)
    {
        $value = strtoupper(trim((string)$value));
        return in_array($value, array('MANUAL','CLI','SYSTEM'), true) ? $value : 'SYSTEM';
    }

    private static function actor($trigger)
    {
        if ($trigger!=='MANUAL') return array('id'=>null,'name'=>null);
        $user = Auth::user();
        $id = is_array($user) && isset($user['id']) && is_numeric($user['id']) ? (int)$user['id'] : 0;
        $name = trim((string)Auth::userName());
        $name = function_exists('mb_substr') ? mb_substr($name,0,100,'UTF-8') : substr($name,0,100);
        return array('id'=>$id>0?$id:null,'name'=>$name!==''?$name:null);
    }

    private static function runUid()
    {
        $random = uniqid((string)mt_rand(), true) . microtime(true);
        if (function_exists('openssl_random_pseudo_bytes')) {
            $bytes = @openssl_random_pseudo_bytes(24);
            if ($bytes!==false) $random .= bin2hex($bytes);
        }
        return 'forecast_' . self::businessNow('YmdHis') . '_' . substr(hash('sha256',$random),0,36);
    }

    private static function acquireLock($pdo, $forecastDate, $targetYm)
    {
        $name = 'cpms_ai_monthly_forecast_' . str_replace('-','',$forecastDate) . '_' . str_replace('-','',$targetYm);
        try {
            $st = $pdo->prepare('SELECT GET_LOCK(:lock_name,0)');
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
            $st = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $st->bindValue(':lock_name',$lock['name'],PDO::PARAM_STR);
            $st->execute();
        } catch (Exception $e) {
        }
    }

    private static function clearStaleRuns($pdo, $forecastDate, $targetYm)
    {
        try {
            $sql = "UPDATE `" . self::RUN_TABLE . "` SET run_status='FAILED',finished_at=NOW(),error_summary='실행 제한시간을 초과해 실패 처리했습니다.' WHERE forecast_date=:forecast_date AND target_ym=:target_ym AND run_status='RUNNING' AND started_at<DATE_SUB(NOW(),INTERVAL 1 HOUR)";
            $st = $pdo->prepare($sql);
            $st->execute(array(':forecast_date'=>$forecastDate,':target_ym'=>$targetYm));
        } catch (Exception $e) {
        }
    }

    private static function hasRecentRunning($pdo, $forecastDate, $targetYm)
    {
        try {
            $sql = "SELECT id FROM `" . self::RUN_TABLE . "` WHERE forecast_date=:forecast_date AND target_ym=:target_ym AND run_status='RUNNING' AND started_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR) ORDER BY id DESC LIMIT 1";
            $st = $pdo->prepare($sql);
            $st->execute(array(':forecast_date'=>$forecastDate,':target_ym'=>$targetYm));
            return $st->fetchColumn()!==false;
        } catch (Exception $e) {
            return false;
        }
    }

    private static function createRun($pdo, $forecastDate, $targetYm, $snapshotDate, $trigger, $projectCount)
    {
        $actor = self::actor($trigger);
        $now = self::businessNow('Y-m-d H:i:s');
        $sql = 'INSERT INTO `' . self::RUN_TABLE . '` (run_uid,forecast_date,target_ym,snapshot_date,trigger_type,run_status,project_count,success_count,failure_count,insufficient_count,current_input_total,forecast_input_total,forecast_low_total,forecast_high_total,actor_employee_id,actor_name,started_at,finished_at,error_summary,created_at) VALUES (:run_uid,:forecast_date,:target_ym,:snapshot_date,:trigger_type,\'RUNNING\',:project_count,0,0,0,0,0,0,0,:actor_employee_id,:actor_name,:started_at,NULL,NULL,:created_at)';
        $st = $pdo->prepare($sql);
        $st->execute(array(
            ':run_uid'=>self::runUid(),':forecast_date'=>$forecastDate,':target_ym'=>$targetYm,':snapshot_date'=>$snapshotDate,
            ':trigger_type'=>$trigger,':project_count'=>(int)$projectCount,':actor_employee_id'=>$actor['id'],':actor_name'=>$actor['name'],
            ':started_at'=>$now,':created_at'=>$now
        ));
        return (int)$pdo->lastInsertId();
    }

    private static function finishRun($pdo, $runId, $status, $counts, $totals, $errorSummary)
    {
        if (!in_array($status,array('COMPLETED','PARTIAL','FAILED'),true)) $status='FAILED';
        $sql = 'UPDATE `' . self::RUN_TABLE . '` SET run_status=:run_status,project_count=:project_count,success_count=:success_count,failure_count=:failure_count,insufficient_count=:insufficient_count,current_input_total=:current_total,forecast_input_total=:forecast_total,forecast_low_total=:low_total,forecast_high_total=:high_total,finished_at=:finished_at,error_summary=:error_summary WHERE id=:id';
        $st = $pdo->prepare($sql);
        $st->execute(array(
            ':run_status'=>$status,':project_count'=>(int)$counts['projects'],':success_count'=>(int)$counts['success'],
            ':failure_count'=>(int)$counts['failed'],':insufficient_count'=>(int)$counts['insufficient'],
            ':current_total'=>self::moneyValue($totals['current']),':forecast_total'=>self::moneyValue($totals['forecast']),
            ':low_total'=>self::moneyValue($totals['low']),':high_total'=>self::moneyValue($totals['high']),
            ':finished_at'=>self::businessNow('Y-m-d H:i:s'),':error_summary'=>$errorSummary!==''?$errorSummary:null,':id'=>(int)$runId
        ));
    }

    public static function saveForecast($pdo, $runId, $row)
    {
        $now = self::businessNow('Y-m-d H:i:s');
        $columns = array(
            'run_id','forecast_date','target_ym','snapshot_date','project_id','project_name_snapshot','project_status_snapshot',
            'current_input_amount','forecast_input_amount','forecast_low_amount','forecast_high_amount','remaining_estimated_amount',
            'basis_type','data_status','history_month_count','snapshot_history_count','labor_progress_rate','non_labor_progress_rate',
            'category_forecast_data','basis_detail','warning_data','first_created_at','last_calculated_at','calculation_count','created_at','updated_at'
        );
        $params = array();
        foreach ($columns as $column) $params[] = ':' . $column;
        $updates = array();
        foreach ($columns as $column) {
            if (in_array($column,array('forecast_date','target_ym','project_id','first_created_at','calculation_count','created_at'),true)) continue;
            $updates[] = '`' . $column . '`=VALUES(`' . $column . '`)';
        }
        $updates[] = '`calculation_count`=`calculation_count`+1';
        $sql = 'INSERT INTO `' . self::FORECAST_TABLE . '` (`' . implode('`,`',$columns) . '`) VALUES (' . implode(',',$params) . ') ON DUPLICATE KEY UPDATE ' . implode(',',$updates);
        $values = array();
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

    public static function forecastLatest($pdo = null, $triggerType = 'SYSTEM')
    {
        $pdo = self::pdo($pdo);
        $forecastDate = self::businessToday();
        $empty = array(
            'ok'=>false,'busy'=>false,'forecast_date'=>$forecastDate,'target_ym'=>'','snapshot_date'=>'','status'=>'FAILED',
            'projects'=>0,'success'=>0,'insufficient'=>0,'failed'=>0,'current_input_total'=>0.0,'forecast_input_total'=>0.0,
            'forecast_low_total'=>0.0,'forecast_high_total'=>0.0,'message'=>'월말 예측 실행에 실패했습니다.'
        );
        if (!$pdo) { $empty['message']='DB 연결을 확인할 수 없습니다.'; return $empty; }
        if (!self::isInstalled($pdo)) { $empty['message']='기본 월말 예측 테이블을 먼저 설치해주세요.'; return $empty; }
        $snapshot = self::latestSnapshotContext($pdo);
        if (empty($snapshot['available'])) {
            $empty['message']='월말 예측을 실행하려면 먼저 오늘 스냅샷을 생성해주세요.';
            return $empty;
        }
        $targetYm = $snapshot['target_ym'];
        $snapshotDate = $snapshot['snapshot_date'];
        $empty['target_ym']=$targetYm;
        $empty['snapshot_date']=$snapshotDate;
        $trigger = self::normalizeTrigger($triggerType);
        $lock = self::acquireLock($pdo,$forecastDate,$targetYm);
        if (empty($lock['ok'])) {
            $empty['busy']=true;
            $empty['message']='이미 월말 예측 계산이 진행 중입니다.';
            return $empty;
        }
        $runId=0;
        $counts=array('projects'=>0,'success'=>0,'failed'=>0,'insufficient'=>0);
        $totals=array('current'=>0.0,'forecast'=>0.0,'low'=>0.0,'high'=>0.0);
        try {
            self::clearStaleRuns($pdo,$forecastDate,$targetYm);
            if (self::hasRecentRunning($pdo,$forecastDate,$targetYm)) {
                self::releaseLock($pdo,$lock);
                $empty['busy']=true;
                $empty['message']='이미 월말 예측 계산이 진행 중입니다.';
                return $empty;
            }
            $projectsLoaded = false;
            $projects = self::loadLatestSnapshotProjects($pdo,$snapshotDate,$targetYm,$projectsLoaded);
            if (!$projectsLoaded) throw new Exception('forecast projects unavailable');
            $counts['projects']=count($projects);
            $runId=self::createRun($pdo,$forecastDate,$targetYm,$snapshotDate,$trigger,$counts['projects']);
            foreach ($projects as $project) {
                try {
                    $projectId=isset($project['project_id'])?(int)$project['project_id']:0;
                    if ($projectId<=0) throw new Exception('forecast project unavailable');
                    $historyLoaded=false;
                    $history=self::loadProjectHistory($pdo,$projectId,$targetYm,$historyLoaded);
                    if (!$historyLoaded) throw new Exception('forecast history unavailable');
                    $row=self::calculateProject($project,$history);
                    self::saveForecast($pdo,$runId,$row);
                    $counts['success']++;
                    if ($row['data_status']==='INSUFFICIENT') $counts['insufficient']++;
                    $totals['current']+=(float)$row['current_input_amount'];
                    $totals['forecast']+=(float)$row['forecast_input_amount'];
                    $totals['low']+=(float)$row['forecast_low_amount'];
                    $totals['high']+=(float)$row['forecast_high_amount'];
                } catch (Exception $e) {
                    $counts['failed']++;
                    error_log('[AiMonthlyForecast] project forecast failed');
                }
            }
            if ($counts['failed']===0) $status='COMPLETED';
            else if ($counts['success']>0) $status='PARTIAL';
            else $status='FAILED';
            $errorSummary=$counts['failed']>0 ? '일부 프로젝트 예측 실패: ' . $counts['failed'] . '건' : '';
            if ($counts['projects']>0 && $counts['success']===0 && $counts['failed']>0) $errorSummary='전체 프로젝트 예측 실패: ' . $counts['failed'] . '건';
            self::finishRun($pdo,$runId,$status,$counts,$totals,$errorSummary);
            self::releaseLock($pdo,$lock);
            return array(
                'ok'=>$status==='COMPLETED'||$status==='PARTIAL','busy'=>false,'forecast_date'=>$forecastDate,
                'target_ym'=>$targetYm,'snapshot_date'=>$snapshotDate,'status'=>$status,'projects'=>$counts['projects'],
                'success'=>$counts['success'],'insufficient'=>$counts['insufficient'],'failed'=>$counts['failed'],
                'current_input_total'=>$totals['current'],'forecast_input_total'=>$totals['forecast'],
                'forecast_low_total'=>$totals['low'],'forecast_high_total'=>$totals['high'],
                'message'=>$status==='COMPLETED'?'기본 월말 예측 계산을 완료했습니다.':($status==='PARTIAL'?'일부 현장을 제외하고 기본 월말 예측을 저장했습니다.':'월말 예측 계산에 실패했습니다.')
            );
        } catch (Exception $e) {
            if ($runId>0) {
                try { self::finishRun($pdo,$runId,'FAILED',$counts,$totals,'월말 예측 실행 중 오류가 발생했습니다.'); } catch (Exception $ignored) {}
            }
            error_log('[AiMonthlyForecast] forecast run failed');
            self::releaseLock($pdo,$lock);
            return $empty;
        }
    }

    public static function latestForecastContext($pdo = null)
    {
        $empty=array('forecast_date'=>'','target_ym'=>'');
        $pdo=self::pdo($pdo);
        if (!$pdo || !self::isInstalled($pdo)) return $empty;
        try {
            $st=$pdo->query('SELECT forecast_date,target_ym FROM `' . self::FORECAST_TABLE . '` ORDER BY forecast_date DESC,id DESC LIMIT 1');
            $row=$st?$st->fetch(PDO::FETCH_ASSOC):false;
            return is_array($row)?array('forecast_date'=>(string)$row['forecast_date'],'target_ym'=>(string)$row['target_ym']):$empty;
        } catch (Exception $e) { return $empty; }
    }

    private static function buildHistoryWhere($filters, &$params)
    {
        $filters=is_array($filters)?$filters:array();
        $params=array();
        $where=array('1=1');
        $date=self::validDate(isset($filters['forecast_date'])?$filters['forecast_date']:'');
        $ym=self::validYm(isset($filters['target_ym'])?$filters['target_ym']:'');
        if ($date!=='') { $where[]='f.forecast_date=:forecast_date'; $params[':forecast_date']=$date; }
        if ($ym!=='') { $where[]='f.target_ym=:target_ym'; $params[':target_ym']=$ym; }
        if (isset($filters['project_id']) && (int)$filters['project_id']>0) { $where[]='f.project_id=:project_id'; $params[':project_id']=(int)$filters['project_id']; }
        if (isset($filters['project_status']) && trim((string)$filters['project_status'])!=='') { $where[]='f.project_status_snapshot=:project_status'; $params[':project_status']=trim((string)$filters['project_status']); }
        if (isset($filters['data_status']) && in_array($filters['data_status'],array('READY','LIMITED','INSUFFICIENT'),true)) { $where[]='f.data_status=:data_status'; $params[':data_status']=$filters['data_status']; }
        if (isset($filters['basis_type']) && in_array($filters['basis_type'],array('HISTORICAL_RATIO','RECENT_MEDIAN','LINEAR','INSUFFICIENT','MIXED'),true)) { $where[]='f.basis_type=:basis_type'; $params[':basis_type']=$filters['basis_type']; }
        if (isset($filters['q']) && trim((string)$filters['q'])!=='') { $where[]='f.project_name_snapshot LIKE :q'; $params[':q']='%' . trim((string)$filters['q']) . '%'; }
        return implode(' AND ',$where);
    }

    private static function bindValues($st, $params)
    {
        foreach ($params as $key=>$value) $st->bindValue($key,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);
    }

    public static function countForecasts($pdo, $filters)
    {
        $pdo=self::pdo($pdo);
        if (!$pdo || !self::isInstalled($pdo)) return 0;
        $params=array();
        $where=self::buildHistoryWhere($filters,$params);
        try {
            $st=$pdo->prepare('SELECT COUNT(*) FROM `' . self::FORECAST_TABLE . '` f WHERE ' . $where);
            self::bindValues($st,$params);$st->execute();return (int)$st->fetchColumn();
        } catch (Exception $e) { return 0; }
    }

    public static function listForecasts($pdo, $filters, $page, $perPage)
    {
        $pdo=self::pdo($pdo);$page=max(1,(int)$page);$perPage=max(1,min(100,(int)$perPage));
        if (!$pdo || !self::isInstalled($pdo)) return array();
        $params=array();$where=self::buildHistoryWhere($filters,$params);$offset=($page-1)*$perPage;
        try {
            $sql='SELECT f.id,f.run_id,f.forecast_date,f.target_ym,f.snapshot_date,f.project_id,f.project_name_snapshot,f.project_status_snapshot,f.current_input_amount,f.forecast_input_amount,f.forecast_low_amount,f.forecast_high_amount,f.remaining_estimated_amount,f.basis_type,f.data_status,f.history_month_count,f.snapshot_history_count,f.labor_progress_rate,f.non_labor_progress_rate,f.category_forecast_data,f.basis_detail,f.warning_data,f.first_created_at,f.last_calculated_at,f.calculation_count,(SELECT p.forecast_input_amount FROM `' . self::FORECAST_TABLE . '` p WHERE p.project_id=f.project_id AND p.target_ym=f.target_ym AND p.forecast_date<f.forecast_date ORDER BY p.forecast_date DESC,p.id DESC LIMIT 1) AS previous_forecast_input_amount FROM `' . self::FORECAST_TABLE . '` f WHERE ' . $where . ' ORDER BY f.forecast_input_amount DESC,f.project_id ASC LIMIT :limit OFFSET :offset';
            $st=$pdo->prepare($sql);self::bindValues($st,$params);$st->bindValue(':limit',$perPage,PDO::PARAM_INT);$st->bindValue(':offset',$offset,PDO::PARAM_INT);$st->execute();
            $rows=$st->fetchAll(PDO::FETCH_ASSOC);return is_array($rows)?$rows:array();
        } catch (Exception $e) { return array(); }
    }

    public static function historySummary($pdo, $filters)
    {
        $empty=array('project_count'=>0,'current_total'=>0,'forecast_total'=>0,'low_total'=>0,'high_total'=>0,'remaining_total'=>0,'insufficient_count'=>0,'last_calculated_at'=>'');
        $pdo=self::pdo($pdo);if (!$pdo || !self::isInstalled($pdo)) return $empty;
        $params=array();$where=self::buildHistoryWhere($filters,$params);
        try {
            $sql='SELECT COUNT(*) AS project_count,COALESCE(SUM(f.current_input_amount),0) AS current_total,COALESCE(SUM(f.forecast_input_amount),0) AS forecast_total,COALESCE(SUM(f.forecast_low_amount),0) AS low_total,COALESCE(SUM(f.forecast_high_amount),0) AS high_total,COALESCE(SUM(f.remaining_estimated_amount),0) AS remaining_total,COALESCE(SUM(CASE WHEN f.data_status=\'INSUFFICIENT\' THEN 1 ELSE 0 END),0) AS insufficient_count,MAX(f.last_calculated_at) AS last_calculated_at FROM `' . self::FORECAST_TABLE . '` f WHERE ' . $where;
            $st=$pdo->prepare($sql);self::bindValues($st,$params);$st->execute();$row=$st->fetch(PDO::FETCH_ASSOC);
            return is_array($row)?array_merge($empty,$row):$empty;
        } catch (Exception $e) { return $empty; }
    }

    public static function historyOptions($pdo = null)
    {
        $result=array('projects'=>array(),'statuses'=>array(),'dates'=>array(),'months'=>array());
        $pdo=self::pdo($pdo);if (!$pdo || !self::isInstalled($pdo)) return $result;
        try {
            $result['projects']=$pdo->query('SELECT project_id,MAX(project_name_snapshot) AS project_name FROM `' . self::FORECAST_TABLE . '` GROUP BY project_id ORDER BY project_name ASC,project_id ASC')->fetchAll(PDO::FETCH_ASSOC);
            $result['statuses']=$pdo->query("SELECT DISTINCT project_status_snapshot AS status FROM `" . self::FORECAST_TABLE . "` WHERE project_status_snapshot IS NOT NULL AND project_status_snapshot<>'' ORDER BY project_status_snapshot ASC")->fetchAll(PDO::FETCH_ASSOC);
            $result['dates']=$pdo->query('SELECT DISTINCT forecast_date FROM `' . self::FORECAST_TABLE . '` ORDER BY forecast_date DESC LIMIT 366')->fetchAll(PDO::FETCH_COLUMN);
            $result['months']=$pdo->query('SELECT DISTINCT target_ym FROM `' . self::FORECAST_TABLE . '` ORDER BY target_ym DESC')->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
        }
        return $result;
    }
}
