<?php
/**
 * CEO Index calculator based only on saved CPMS AI risk results.
 * PHP 5.6 / MySQL 5.6 compatible.
 */

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;
use Exception;
use PDO;

class AiCeoIndexService
{
    const RUN_TABLE = 'cpms_ai_ceo_index_runs';
    const RESULT_TABLE = 'cpms_ai_ceo_index_results';
    const PROJECT_TABLE = 'cpms_ai_ceo_project_results';
    const RISK_TABLE = 'cpms_ai_profit_risk_results';
    const V2_RUN_TABLE = 'cpms_ai_ceo_index_runs_v2';
    const V2_RESULT_TABLE = 'cpms_ai_ceo_index_results_v2';
    const V2_PROJECT_TABLE = 'cpms_ai_ceo_project_results_v2';
    const V2_FORECAST_TABLE = 'cpms_ai_cost_forecast_results_v2';
    const V2_FORECAST_RUN_TABLE = 'cpms_ai_cost_forecast_runs_v2';
    const V2_VERSION = 'COST_FORECAST_V2';

    private static $tableCache = array();
    private static $columnCache = array();
    private static $indexCache = array();

    public static function pdo($pdo = null)
    {
        if ($pdo) return $pdo;
        try { return Db::pdo(); } catch (Exception $e) { return null; }
    }

    private static function connectionKey($pdo)
    {
        return is_object($pdo) ? spl_object_hash($pdo) : 'none';
    }

    private static function validIdentifier($value)
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_]+$/', $value);
    }

    public static function tableExists($pdo, $table)
    {
        if (!$pdo || !self::validIdentifier($table)) return false;
        $key = self::connectionKey($pdo) . ':' . $table;
        if (array_key_exists($key, self::$tableCache)) return self::$tableCache[$key];
        try {
            $st = $pdo->prepare('SHOW TABLES LIKE :table_name');
            if (!$st || !$st->execute(array(':table_name'=>$table))) return self::$tableCache[$key] = false;
            return self::$tableCache[$key] = (bool)$st->fetchColumn();
        } catch (Exception $e) { return self::$tableCache[$key] = false; }
    }

    public static function getTableColumns($pdo, $table)
    {
        if (!$pdo || !self::validIdentifier($table) || !self::tableExists($pdo, $table)) return array();
        $key = self::connectionKey($pdo) . ':' . $table;
        if (isset(self::$columnCache[$key])) return self::$columnCache[$key];
        $columns = array();
        try {
            $st = $pdo->query('SHOW COLUMNS FROM `' . $table . '`');
            if ($st) while ($row = $st->fetch(PDO::FETCH_ASSOC)) if (isset($row['Field'])) $columns[(string)$row['Field']] = $row;
        } catch (Exception $e) {}
        return self::$columnCache[$key] = $columns;
    }

    public static function columnExists($pdo, $table, $column)
    {
        $columns = self::getTableColumns($pdo, $table);
        return isset($columns[$column]);
    }

    public static function getTableIndexes($pdo, $table)
    {
        if (!$pdo || !self::validIdentifier($table) || !self::tableExists($pdo, $table)) return array();
        $key = self::connectionKey($pdo) . ':' . $table;
        if (isset(self::$indexCache[$key])) return self::$indexCache[$key];
        $indexes = array();
        try {
            $st = $pdo->query('SHOW INDEX FROM `' . $table . '`');
            if ($st) while ($row = $st->fetch(PDO::FETCH_ASSOC)) if (isset($row['Key_name'])) $indexes[(string)$row['Key_name']] = true;
        } catch (Exception $e) {}
        return self::$indexCache[$key] = $indexes;
    }

    public static function clearSchemaCache($pdo)
    {
        $prefix = self::connectionKey($pdo) . ':';
        foreach (array_keys(self::$tableCache) as $key) if (strpos($key, $prefix) === 0) unset(self::$tableCache[$key]);
        foreach (array_keys(self::$columnCache) as $key) if (strpos($key, $prefix) === 0) unset(self::$columnCache[$key]);
        foreach (array_keys(self::$indexCache) as $key) if (strpos($key, $prefix) === 0) unset(self::$indexCache[$key]);
    }

    public static function requiredRunColumns()
    {
        return array('id','run_uid','analysis_date','target_ym','source_fingerprint','trigger_type','run_status','source_project_count','analyzable_project_count','ceo_index_score','ceo_index_grade','coverage_rate','actor_employee_id','actor_name','started_at','finished_at','error_summary','created_at');
    }

    public static function requiredResultColumns()
    {
        return array('id','run_id','analysis_date','target_ym','source_fingerprint','ceo_index_score','ceo_index_grade','previous_score','score_change','financial_stability_score','input_reliability_score','anomaly_stability_score','sales_certainty_score','data_readiness_score','source_project_count','analyzable_project_count','coverage_rate','normal_count','watch_count','warning_count','critical_count','insufficient_count','monthly_sales_total','monthly_forecast_input_total','monthly_forecast_profit_total','cumulative_projected_profit_total','component_data','warning_data','data_status','first_created_at','last_calculated_at','calculation_count','created_at','updated_at');
    }

    public static function requiredProjectColumns()
    {
        return array('id','run_id','analysis_date','target_ym','project_id','project_name_snapshot','project_status_snapshot','project_index_score','project_index_grade','financial_stability_score','input_reliability_score','anomaly_stability_score','sales_certainty_score','data_readiness_score','available_weight','monthly_sales_amount','monthly_forecast_input_amount','monthly_forecast_profit_amount','monthly_forecast_cost_rate','risk_score','risk_grade','reliability_score','reliability_grade','anomaly_score','anomaly_grade','sales_basis','confidence_level','data_status','first_created_at','last_calculated_at','calculation_count','created_at','updated_at');
    }

    public static function createRunTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS cpms_ai_ceo_index_runs (\n"
            . " id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,\n run_uid VARCHAR(64) NOT NULL,\n analysis_date DATE NOT NULL,\n target_ym CHAR(7) NOT NULL,\n source_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,\n trigger_type VARCHAR(20) NOT NULL,\n run_status VARCHAR(20) NOT NULL,\n source_project_count INT UNSIGNED NOT NULL DEFAULT 0,\n analyzable_project_count INT UNSIGNED NOT NULL DEFAULT 0,\n ceo_index_score DECIMAL(6,2) NULL,\n ceo_index_grade VARCHAR(20) NOT NULL,\n coverage_rate DECIMAL(8,3) NOT NULL DEFAULT 0,\n actor_employee_id INT NULL,\n actor_name VARCHAR(100) NULL,\n started_at DATETIME NOT NULL,\n finished_at DATETIME NULL,\n error_summary VARCHAR(500) NULL,\n created_at DATETIME NOT NULL,\n UNIQUE KEY uk_ai_ceo_index_run_uid (run_uid),\n KEY idx_ai_ceo_index_run_date (analysis_date,started_at),\n KEY idx_ai_ceo_index_run_status (run_status,started_at),\n KEY idx_ai_ceo_index_run_source (source_fingerprint)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    public static function createResultTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS cpms_ai_ceo_index_results (\n"
            . " id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,\n run_id BIGINT UNSIGNED NULL,\n analysis_date DATE NOT NULL,\n target_ym CHAR(7) NOT NULL,\n source_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,\n ceo_index_score DECIMAL(6,2) NULL,\n ceo_index_grade VARCHAR(20) NOT NULL,\n previous_score DECIMAL(6,2) NULL,\n score_change DECIMAL(6,2) NULL,\n financial_stability_score DECIMAL(6,2) NULL,\n input_reliability_score DECIMAL(6,2) NULL,\n anomaly_stability_score DECIMAL(6,2) NULL,\n sales_certainty_score DECIMAL(6,2) NULL,\n data_readiness_score DECIMAL(6,2) NULL,\n source_project_count INT UNSIGNED NOT NULL DEFAULT 0,\n analyzable_project_count INT UNSIGNED NOT NULL DEFAULT 0,\n coverage_rate DECIMAL(8,3) NOT NULL DEFAULT 0,\n normal_count INT UNSIGNED NOT NULL DEFAULT 0,\n watch_count INT UNSIGNED NOT NULL DEFAULT 0,\n warning_count INT UNSIGNED NOT NULL DEFAULT 0,\n critical_count INT UNSIGNED NOT NULL DEFAULT 0,\n insufficient_count INT UNSIGNED NOT NULL DEFAULT 0,\n monthly_sales_total DECIMAL(18,2) NOT NULL DEFAULT 0,\n monthly_forecast_input_total DECIMAL(18,2) NOT NULL DEFAULT 0,\n monthly_forecast_profit_total DECIMAL(18,2) NOT NULL DEFAULT 0,\n cumulative_projected_profit_total DECIMAL(18,2) NOT NULL DEFAULT 0,\n component_data MEDIUMTEXT NULL,\n warning_data MEDIUMTEXT NULL,\n data_status VARCHAR(30) NOT NULL,\n first_created_at DATETIME NOT NULL,\n last_calculated_at DATETIME NOT NULL,\n calculation_count INT UNSIGNED NOT NULL DEFAULT 1,\n created_at DATETIME NOT NULL,\n updated_at DATETIME NOT NULL,\n UNIQUE KEY uk_ai_ceo_index_result (analysis_date,target_ym),\n KEY idx_ai_ceo_index_grade (ceo_index_grade,analysis_date),\n KEY idx_ai_ceo_index_source (source_fingerprint)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    public static function createProjectTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS cpms_ai_ceo_project_results (\n"
            . " id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,\n run_id BIGINT UNSIGNED NULL,\n analysis_date DATE NOT NULL,\n target_ym CHAR(7) NOT NULL,\n project_id INT UNSIGNED NOT NULL,\n project_name_snapshot VARCHAR(190) NULL,\n project_status_snapshot VARCHAR(50) NULL,\n project_index_score DECIMAL(6,2) NULL,\n project_index_grade VARCHAR(20) NOT NULL,\n financial_stability_score DECIMAL(6,2) NULL,\n input_reliability_score DECIMAL(6,2) NULL,\n anomaly_stability_score DECIMAL(6,2) NULL,\n sales_certainty_score DECIMAL(6,2) NULL,\n data_readiness_score DECIMAL(6,2) NULL,\n available_weight DECIMAL(8,3) NOT NULL DEFAULT 0,\n monthly_sales_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n monthly_forecast_input_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n monthly_forecast_profit_amount DECIMAL(18,2) NULL,\n monthly_forecast_cost_rate DECIMAL(8,3) NULL,\n risk_score DECIMAL(6,2) NULL,\n risk_grade VARCHAR(20) NULL,\n reliability_score DECIMAL(6,2) NULL,\n reliability_grade VARCHAR(20) NULL,\n anomaly_score DECIMAL(6,2) NULL,\n anomaly_grade VARCHAR(20) NULL,\n sales_basis VARCHAR(30) NULL,\n confidence_level VARCHAR(20) NULL,\n data_status VARCHAR(30) NOT NULL,\n first_created_at DATETIME NOT NULL,\n last_calculated_at DATETIME NOT NULL,\n calculation_count INT UNSIGNED NOT NULL DEFAULT 1,\n created_at DATETIME NOT NULL,\n updated_at DATETIME NOT NULL,\n UNIQUE KEY uk_ai_ceo_project_result (analysis_date,target_ym,project_id),\n KEY idx_ai_ceo_project (project_id,analysis_date),\n KEY idx_ai_ceo_project_grade (project_index_grade,analysis_date),\n KEY idx_ai_ceo_project_score (project_index_score,analysis_date),\n KEY idx_ai_ceo_project_run (run_id)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    private static function columnDefinitions($table)
    {
        $definitions = array();
        $sql = '';
        if ($table === self::RUN_TABLE) $sql = self::createRunTableSql();
        else if ($table === self::RESULT_TABLE) $sql = self::createResultTableSql();
        else if ($table === self::PROJECT_TABLE) $sql = self::createProjectTableSql();
        if ($sql === '') return $definitions;
        if (preg_match_all('/^\s*([a-zA-Z0-9_]+)\s+([^,\n]+)(?:,|$)/m', $sql, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $name = isset($match[1]) ? $match[1] : '';
                if ($name === '' || in_array(strtoupper($name), array('CREATE','PRIMARY','UNIQUE','KEY'), true) || $name === 'id') continue;
                $definitions[$name] = trim($match[2]);
            }
        }
        return $definitions;
    }

    private static function indexDefinitions($table)
    {
        if ($table === self::RUN_TABLE) return array('PRIMARY'=>'PRIMARY KEY (`id`)','uk_ai_ceo_index_run_uid'=>'UNIQUE KEY `uk_ai_ceo_index_run_uid` (`run_uid`)','idx_ai_ceo_index_run_date'=>'KEY `idx_ai_ceo_index_run_date` (`analysis_date`,`started_at`)','idx_ai_ceo_index_run_status'=>'KEY `idx_ai_ceo_index_run_status` (`run_status`,`started_at`)','idx_ai_ceo_index_run_source'=>'KEY `idx_ai_ceo_index_run_source` (`source_fingerprint`)');
        if ($table === self::RESULT_TABLE) return array('PRIMARY'=>'PRIMARY KEY (`id`)','uk_ai_ceo_index_result'=>'UNIQUE KEY `uk_ai_ceo_index_result` (`analysis_date`,`target_ym`)','idx_ai_ceo_index_grade'=>'KEY `idx_ai_ceo_index_grade` (`ceo_index_grade`,`analysis_date`)','idx_ai_ceo_index_source'=>'KEY `idx_ai_ceo_index_source` (`source_fingerprint`)');
        return array('PRIMARY'=>'PRIMARY KEY (`id`)','uk_ai_ceo_project_result'=>'UNIQUE KEY `uk_ai_ceo_project_result` (`analysis_date`,`target_ym`,`project_id`)','idx_ai_ceo_project'=>'KEY `idx_ai_ceo_project` (`project_id`,`analysis_date`)','idx_ai_ceo_project_grade'=>'KEY `idx_ai_ceo_project_grade` (`project_index_grade`,`analysis_date`)','idx_ai_ceo_project_score'=>'KEY `idx_ai_ceo_project_score` (`project_index_score`,`analysis_date`)','idx_ai_ceo_project_run'=>'KEY `idx_ai_ceo_project_run` (`run_id`)');
    }

    private static function requiredIndexes($table)
    {
        return array_keys(self::indexDefinitions($table));
    }

    private static function ensureOwnedTable($pdo, $table, &$updated)
    {
        if (!in_array($table, array(self::RUN_TABLE,self::RESULT_TABLE,self::PROJECT_TABLE), true)) throw new Exception('unsupported table');
        if (!self::columnExists($pdo,$table,'id')) {
            if ($pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST') === false) throw new Exception('schema id failed');
            $updated[] = $table . '.column:id';
            self::clearSchemaCache($pdo);
        }
        foreach (self::columnDefinitions($table) as $column=>$definition) {
            if (!self::columnExists($pdo,$table,$column)) {
                if ($pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition) === false) throw new Exception('schema update failed');
                $updated[] = $table . '.column:' . $column;
                self::clearSchemaCache($pdo);
            }
        }
        $existing = self::getTableIndexes($pdo,$table);
        foreach (self::indexDefinitions($table) as $name=>$definition) if (!isset($existing[$name])) {
            if ($pdo->exec('ALTER TABLE `' . $table . '` ADD ' . $definition) === false) throw new Exception('schema index failed');
            $updated[] = $table . '.index:' . $name;
            self::clearSchemaCache($pdo);
        }
    }

    public static function installOrUpdate($pdo = null)
    {
        $pdo = self::pdo($pdo);
        if (!$pdo) return array('ok'=>false,'message'=>'DB 연결 상태를 확인할 수 없습니다.','created'=>array(),'updated'=>array());
        $created = array(); $updated = array();
        try {
            $sqls = array(self::RUN_TABLE=>self::createRunTableSql(),self::RESULT_TABLE=>self::createResultTableSql(),self::PROJECT_TABLE=>self::createProjectTableSql());
            foreach ($sqls as $table=>$sql) {
                if (!self::tableExists($pdo,$table)) $created[] = $table;
                if ($pdo->exec($sql) === false) throw new Exception('table install failed');
            }
            self::clearSchemaCache($pdo);
            foreach (array_keys($sqls) as $table) self::ensureOwnedTable($pdo,$table,$updated);
            self::clearSchemaCache($pdo);
            if (!self::isInstalled($pdo)) throw new Exception('schema incomplete');
            return array('ok'=>true,'message'=>count($created)>0?'CEO Index 전용 테이블을 설치했습니다.':'CEO Index 테이블 구조를 확인했습니다.','created'=>$created,'updated'=>$updated);
        } catch (Exception $e) {
            return array('ok'=>false,'message'=>'CEO Index 테이블 설치 또는 확인에 실패했습니다.','created'=>$created,'updated'=>$updated);
        }
    }

    private static function schemaPart($pdo,$table,$columns)
    {
        $result=array('table_exists'=>false,'installed'=>false,'missing_columns'=>array(),'missing_indexes'=>array());
        $result['table_exists']=self::tableExists($pdo,$table);
        if (!$result['table_exists']) { $result['missing_columns']=$columns; $result['missing_indexes']=self::requiredIndexes($table); return $result; }
        foreach ($columns as $column) if (!self::columnExists($pdo,$table,$column)) $result['missing_columns'][]=$column;
        $existing=self::getTableIndexes($pdo,$table);
        foreach (self::requiredIndexes($table) as $index) if (!isset($existing[$index])) $result['missing_indexes'][]=$index;
        $result['installed']=count($result['missing_columns'])===0&&count($result['missing_indexes'])===0;
        return $result;
    }

    public static function isInstalled($pdo = null)
    {
        $pdo=self::pdo($pdo);
        if (!$pdo) return false;
        return !empty(self::schemaPart($pdo,self::RUN_TABLE,self::requiredRunColumns())['installed'])
            && !empty(self::schemaPart($pdo,self::RESULT_TABLE,self::requiredResultColumns())['installed'])
            && !empty(self::schemaPart($pdo,self::PROJECT_TABLE,self::requiredProjectColumns())['installed']);
    }

    public static function latestRiskContext($pdo = null)
    {
        $empty=array('available'=>false,'analysis_date'=>'','target_ym'=>'','project_count'=>0);
        $pdo=self::pdo($pdo);
        if (!$pdo||!self::tableExists($pdo,self::RISK_TABLE)) return $empty;
        foreach (array('analysis_date','target_ym','project_id') as $column) if (!self::columnExists($pdo,self::RISK_TABLE,$column)) return $empty;
        try {
            $st=$pdo->query('SELECT analysis_date,target_ym FROM `' . self::RISK_TABLE . '` ORDER BY analysis_date DESC,id DESC LIMIT 1');
            $row=$st?$st->fetch(PDO::FETCH_ASSOC):false;
            if (!is_array($row)) return $empty;
            $date=isset($row['analysis_date'])?(string)$row['analysis_date']:''; $ym=isset($row['target_ym'])?(string)$row['target_ym']:'';
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)||!preg_match('/^\d{4}-\d{2}$/',$ym)) return $empty;
            $count=$pdo->prepare('SELECT COUNT(*) FROM `' . self::RISK_TABLE . '` WHERE analysis_date=:date AND target_ym=:ym');
            if (!$count||!$count->execute(array(':date'=>$date,':ym'=>$ym))) return $empty;
            $total=(int)$count->fetchColumn();
            return array('available'=>$total>0,'analysis_date'=>$date,'target_ym'=>$ym,'project_count'=>$total);
        } catch (Exception $e) { return $empty; }
    }

    public static function schemaStatus($pdo = null)
    {
        $pdo=self::pdo($pdo);
        $result=array('db_available'=>(bool)$pdo,'run'=>array(),'result'=>array(),'project'=>array(),'installed'=>false,'latest_risk'=>array('available'=>false,'analysis_date'=>'','target_ym'=>'','project_count'=>0),'latest_result'=>array(),'latest_run'=>array(),'result_count'=>0,'project_result_count'=>0);
        if (!$pdo) return $result;
        try {
            $result['latest_risk']=self::latestRiskContext($pdo);
            $result['run']=self::schemaPart($pdo,self::RUN_TABLE,self::requiredRunColumns());
            $result['result']=self::schemaPart($pdo,self::RESULT_TABLE,self::requiredResultColumns());
            $result['project']=self::schemaPart($pdo,self::PROJECT_TABLE,self::requiredProjectColumns());
            $result['installed']=!empty($result['run']['installed'])&&!empty($result['result']['installed'])&&!empty($result['project']['installed']);
            if (!empty($result['result']['installed'])) {
                $st=$pdo->query('SELECT COUNT(*) FROM `' . self::RESULT_TABLE . '`'); $result['result_count']=$st?(int)$st->fetchColumn():0;
                $result['latest_result']=self::latestResult($pdo);
            }
            if (!empty($result['project']['installed'])) { $st=$pdo->query('SELECT COUNT(*) FROM `' . self::PROJECT_TABLE . '`'); $result['project_result_count']=$st?(int)$st->fetchColumn():0; }
            if (!empty($result['run']['installed'])) { $st=$pdo->query('SELECT * FROM `' . self::RUN_TABLE . '` ORDER BY started_at DESC,id DESC LIMIT 1'); $row=$st?$st->fetch(PDO::FETCH_ASSOC):false; $result['latest_run']=is_array($row)?$row:array(); }
        } catch (Exception $e) {}
        return $result;
    }

    private static function clampScore($value)
    {
        if ($value===null||!is_numeric($value)) return null;
        return round(max(0,min(100,(float)$value)),2);
    }

    public static function grade($score)
    {
        if ($score===null||!is_numeric($score)) return 'INSUFFICIENT';
        $score=(float)$score;
        if ($score>=80) return 'STABLE';
        if ($score>=65) return 'WATCH';
        if ($score>=50) return 'WARNING';
        return 'CRITICAL';
    }

    public static function gradeLabel($grade)
    {
        $labels=array('STABLE'=>'안정','WATCH'=>'관심','WARNING'=>'주의','CRITICAL'=>'긴급 확인','INSUFFICIENT'=>'판단자료 부족');
        return isset($labels[$grade])?$labels[$grade]:'판단자료 부족';
    }

    public static function calculateProject($row)
    {
        $weights=array('financial_stability_score'=>45,'input_reliability_score'=>20,'anomaly_stability_score'=>15,'sales_certainty_score'=>10,'data_readiness_score'=>10);
        $risk=isset($row['risk_score'])&&$row['risk_score']!==null?self::clampScore($row['risk_score']):null;
        $anomaly=isset($row['anomaly_score'])&&$row['anomaly_score']!==null?self::clampScore($row['anomaly_score']):null;
        $salesBasis=isset($row['sales_basis'])?strtoupper(trim((string)$row['sales_basis'])):'MISSING';
        $confidence=isset($row['confidence_level'])?strtoupper(trim((string)$row['confidence_level'])):'';
        $dataStatus=isset($row['data_status'])?strtoupper(trim((string)$row['data_status'])):'';
        $salesScores=array('CONFIRMED'=>100,'MIXED'=>70,'ESTIMATED'=>40);
        $readinessScores=array('HIGH'=>100,'MEDIUM'=>70,'LOW'=>40);
        $components=array(
            'financial_stability_score'=>$risk===null?null:self::clampScore(100-$risk),
            'input_reliability_score'=>isset($row['reliability_score'])&&$row['reliability_score']!==null?self::clampScore($row['reliability_score']):null,
            'anomaly_stability_score'=>$anomaly===null?null:self::clampScore(100-$anomaly),
            'sales_certainty_score'=>isset($salesScores[$salesBasis])?$salesScores[$salesBasis]:null,
            'data_readiness_score'=>$dataStatus==='INSUFFICIENT'?0:(isset($readinessScores[$confidence])?$readinessScores[$confidence]:null)
        );
        $available=0.0; $weighted=0.0;
        foreach ($weights as $key=>$weight) if ($components[$key]!==null) { $available+=$weight; $weighted+=(float)$components[$key]*$weight; }
        $score=($components['financial_stability_score']!==null&&$available>=70)?round($weighted/$available,2):null;
        return array(
            'project_id'=>isset($row['project_id'])?(int)$row['project_id']:0,'project_name_snapshot'=>isset($row['project_name_snapshot'])?(string)$row['project_name_snapshot']:'','project_status_snapshot'=>isset($row['project_status_snapshot'])?(string)$row['project_status_snapshot']:'',
            'project_index_score'=>$score,'project_index_grade'=>self::grade($score),'financial_stability_score'=>$components['financial_stability_score'],'input_reliability_score'=>$components['input_reliability_score'],'anomaly_stability_score'=>$components['anomaly_stability_score'],'sales_certainty_score'=>$components['sales_certainty_score'],'data_readiness_score'=>$components['data_readiness_score'],'available_weight'=>$available,
            'monthly_sales_amount'=>isset($row['monthly_sales_amount'])?(float)$row['monthly_sales_amount']:0.0,'monthly_forecast_input_amount'=>isset($row['monthly_forecast_input_amount'])?(float)$row['monthly_forecast_input_amount']:0.0,'monthly_forecast_profit_amount'=>isset($row['monthly_forecast_profit_amount'])&&$row['monthly_forecast_profit_amount']!==null?(float)$row['monthly_forecast_profit_amount']:null,'monthly_forecast_cost_rate'=>isset($row['monthly_forecast_cost_rate'])&&$row['monthly_forecast_cost_rate']!==null?(float)$row['monthly_forecast_cost_rate']:null,
            'cumulative_projected_profit_amount'=>isset($row['cumulative_projected_profit_amount'])&&$row['cumulative_projected_profit_amount']!==null?(float)$row['cumulative_projected_profit_amount']:null,
            'risk_score'=>$risk,'risk_grade'=>isset($row['risk_grade'])?(string)$row['risk_grade']:'','reliability_score'=>$components['input_reliability_score'],'reliability_grade'=>isset($row['reliability_grade'])?(string)$row['reliability_grade']:'','anomaly_score'=>$anomaly,'anomaly_grade'=>isset($row['anomaly_grade'])?(string)$row['anomaly_grade']:'','sales_basis'=>$salesBasis,'confidence_level'=>$confidence,'data_status'=>$dataStatus!==''?$dataStatus:'INSUFFICIENT'
        );
    }

    public static function companyWeights($projects)
    {
        $weights=array(); $bases=array(); $count=count($projects);
        if ($count===0) return $weights;
        foreach ($projects as $index=>$project) $bases[$index]=max(isset($project['monthly_sales_amount'])?(float)$project['monthly_sales_amount']:0,isset($project['monthly_forecast_input_amount'])?(float)$project['monthly_forecast_input_amount']:0,1);
        if ($count===1) return array(0=>100.0);
        $sum=array_sum($bases);
        foreach ($bases as $index=>$base) $weights[$index]=$sum>0?($base/$sum*100):100/$count;
        if ($count===2) return $weights;
        if ($count===3) return array(0=>100/3,1=>100/3,2=>100/3);
        $fixed=array();
        for ($loop=0;$loop<20;$loop++) {
            $changed=false; $fixedTotal=0.0; $openBase=0.0;
            foreach ($weights as $index=>$weight) {
                if (isset($fixed[$index])) $fixedTotal+=$fixed[$index];
                else if ($weight>30.000001) { $fixed[$index]=30.0; $fixedTotal+=30.0; $changed=true; }
            }
            foreach ($bases as $index=>$base) if (!isset($fixed[$index])) $openBase+=$base;
            if ($openBase<=0) break;
            $remaining=max(0,100-$fixedTotal);
            foreach ($bases as $index=>$base) $weights[$index]=isset($fixed[$index])?$fixed[$index]:$remaining*$base/$openBase;
            if (!$changed) break;
        }
        $total=array_sum($weights);
        if ($total>0) foreach ($weights as $index=>$weight) $weights[$index]=$weight/$total*100;
        return $weights;
    }

    public static function aggregateCompany($projects)
    {
        $weights=self::companyWeights($projects);
        $componentKeys=array('financial_stability_score','input_reliability_score','anomaly_stability_score','sales_certainty_score','data_readiness_score');
        $componentTotals=array(); $componentWeights=array();
        foreach ($componentKeys as $key) { $componentTotals[$key]=0.0; $componentWeights[$key]=0.0; }
        $result=array('source_project_count'=>count($projects),'analyzable_project_count'=>0,'coverage_rate'=>0.0,'normal_count'=>0,'watch_count'=>0,'warning_count'=>0,'critical_count'=>0,'insufficient_count'=>0,'monthly_sales_total'=>0.0,'monthly_forecast_input_total'=>0.0,'monthly_forecast_profit_total'=>0.0,'cumulative_projected_profit_total'=>0.0);
        $scoreTotal=0.0; $scoreWeight=0.0;
        foreach ($projects as $index=>$project) {
            $weight=isset($weights[$index])?(float)$weights[$index]:0.0;
            $grade=isset($project['project_index_grade'])?$project['project_index_grade']:'INSUFFICIENT';
            if ($grade==='STABLE') $result['normal_count']++; else if ($grade==='WATCH') $result['watch_count']++; else if ($grade==='WARNING') $result['warning_count']++; else if ($grade==='CRITICAL') $result['critical_count']++; else $result['insufficient_count']++;
            if (isset($project['project_index_score'])&&$project['project_index_score']!==null) { $result['analyzable_project_count']++; $scoreTotal+=(float)$project['project_index_score']*$weight; $scoreWeight+=$weight; }
            foreach ($componentKeys as $key) if (isset($project[$key])&&$project[$key]!==null) { $componentTotals[$key]+=(float)$project[$key]*$weight; $componentWeights[$key]+=$weight; }
            $result['monthly_sales_total']+=(float)$project['monthly_sales_amount'];
            $result['monthly_forecast_input_total']+=(float)$project['monthly_forecast_input_amount'];
            if ($project['monthly_forecast_profit_amount']!==null) $result['monthly_forecast_profit_total']+=(float)$project['monthly_forecast_profit_amount'];
            if (isset($project['cumulative_projected_profit_amount'])&&$project['cumulative_projected_profit_amount']!==null) $result['cumulative_projected_profit_total']+=(float)$project['cumulative_projected_profit_amount'];
        }
        $result['coverage_rate']=round($scoreWeight,3);
        $score=$scoreWeight>=60&&$scoreWeight>0?round($scoreTotal/$scoreWeight,2):null;
        $result['ceo_index_score']=$score; $result['ceo_index_grade']=self::grade($score); $result['data_status']=$score===null?'INSUFFICIENT':($scoreWeight<80?'LIMITED':'READY');
        foreach ($componentKeys as $key) $result[$key]=$componentWeights[$key]>0?round($componentTotals[$key]/$componentWeights[$key],2):null;
        $result['component_data']=array('project_weights'=>$weights,'component_coverage'=>$componentWeights);
        $warnings=array();
        if ($scoreWeight<60) $warnings[]='분석 가능한 현장 비중이 60% 미만이어서 회사 CEO Index를 산출하지 않았습니다.';
        else if ($scoreWeight<80) $warnings[]='일부 현장은 구성자료가 부족하여 분석 가능 비율을 함께 확인해야 합니다.';
        $result['warning_data']=$warnings;
        return $result;
    }

    public static function canonicalize($value)
    {
        if (!is_array($value)) return $value;
        $keys=array_keys($value); $list=$keys===range(0,count($value)-1);
        if (!$list) ksort($value,SORT_STRING);
        foreach ($value as $key=>$item) $value[$key]=self::canonicalize($item);
        return $value;
    }

    private static function encode($value)
    {
        $json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        return is_string($json)?$json:null;
    }

    public static function sourceFingerprint($context,$rows)
    {
        $source=array('analysis_date'=>isset($context['analysis_date'])?$context['analysis_date']:'','target_ym'=>isset($context['target_ym'])?$context['target_ym']:'','projects'=>array(),'company'=>array('monthly_sales_total'=>0.0,'monthly_forecast_input_total'=>0.0,'monthly_forecast_profit_total'=>0.0,'cumulative_projected_profit_total'=>0.0));
        foreach ($rows as $row) {
            $item=array();
            foreach (array('project_id','risk_score','risk_grade','reliability_score','anomaly_score','sales_basis','confidence_level','monthly_sales_amount','monthly_forecast_input_amount','monthly_forecast_profit_amount','cumulative_projected_profit_amount','data_status') as $key) $item[$key]=array_key_exists($key,$row)?$row[$key]:null;
            $source['projects'][]=$item;
            $source['company']['monthly_sales_total']+=(float)$item['monthly_sales_amount'];
            $source['company']['monthly_forecast_input_total']+=(float)$item['monthly_forecast_input_amount'];
            if ($item['monthly_forecast_profit_amount']!==null) $source['company']['monthly_forecast_profit_total']+=(float)$item['monthly_forecast_profit_amount'];
            if ($item['cumulative_projected_profit_amount']!==null) $source['company']['cumulative_projected_profit_total']+=(float)$item['cumulative_projected_profit_amount'];
        }
        usort($source['projects'],array(__CLASS__,'sortProjectSource'));
        $json=self::encode(self::canonicalize($source));
        return is_string($json)?hash('sha256',$json):'';
    }

    public static function sortProjectSource($a,$b)
    {
        $aa=isset($a['project_id'])?(int)$a['project_id']:0; $bb=isset($b['project_id'])?(int)$b['project_id']:0;
        return $aa===$bb?0:($aa<$bb?-1:1);
    }

    private static function loadRiskRows($pdo,$context)
    {
        try {
            $sql='SELECT project_id,project_name_snapshot,project_status_snapshot,monthly_sales_amount,monthly_forecast_input_amount,monthly_forecast_profit_amount,monthly_forecast_cost_rate,cumulative_projected_profit_amount,risk_score,risk_grade,reliability_score,reliability_grade,anomaly_score,anomaly_grade,sales_basis,confidence_level,data_status FROM `' . self::RISK_TABLE . '` WHERE analysis_date=:date AND target_ym=:ym ORDER BY project_id ASC,id ASC';
            $st=$pdo->prepare($sql);
            if (!$st||!$st->execute(array(':date'=>$context['analysis_date'],':ym'=>$context['target_ym']))) return array();
            $rows=$st->fetchAll(PDO::FETCH_ASSOC); return is_array($rows)?$rows:array();
        } catch (Exception $e) { return array(); }
    }

    private static function now()
    {
        try { $d=new \DateTime('now',new \DateTimeZone('Asia/Seoul')); return $d->format('Y-m-d H:i:s'); } catch (Exception $e) { return date('Y-m-d H:i:s'); }
    }

    private static function trigger($value)
    {
        $value=strtoupper(trim((string)$value)); return in_array($value,array('MANUAL','CLI','SYSTEM'),true)?$value:'SYSTEM';
    }

    private static function actor($trigger)
    {
        if ($trigger!=='MANUAL') return array('id'=>null,'name'=>null);
        $user=Auth::user(); $id=is_array($user)&&isset($user['id'])?(int)$user['id']:0; $name=trim((string)Auth::userName());
        return array('id'=>$id>0?$id:null,'name'=>$name!==''?substr($name,0,100):null);
    }

    private static function uid()
    {
        $bytes=function_exists('openssl_random_pseudo_bytes')?@openssl_random_pseudo_bytes(32):false;
        if (!is_string($bytes)||strlen($bytes)<16) $bytes=uniqid((string)mt_rand(),true).microtime(true);
        return hash('sha256',$bytes);
    }

    private static function createRun($pdo,$context,$fingerprint,$trigger,$status,$projects,$analyzable,$score,$grade,$coverage)
    {
        $actor=self::actor($trigger); $now=self::now();
        $st=$pdo->prepare('INSERT INTO `' . self::RUN_TABLE . '` (run_uid,analysis_date,target_ym,source_fingerprint,trigger_type,run_status,source_project_count,analyzable_project_count,ceo_index_score,ceo_index_grade,coverage_rate,actor_employee_id,actor_name,started_at,created_at) VALUES (:uid,:date,:ym,:fingerprint,:trigger,:status,:projects,:analyzable,:score,:grade,:coverage,:actor_id,:actor_name,:started,:created)');
        if (!$st||!$st->execute(array(':uid'=>self::uid(),':date'=>$context['analysis_date'],':ym'=>$context['target_ym'],':fingerprint'=>$fingerprint,':trigger'=>$trigger,':status'=>$status,':projects'=>(int)$projects,':analyzable'=>(int)$analyzable,':score'=>$score,':grade'=>$grade,':coverage'=>$coverage,':actor_id'=>$actor['id'],':actor_name'=>$actor['name'],':started'=>$now,':created'=>$now))) return 0;
        return (int)$pdo->lastInsertId();
    }

    private static function finishRun($pdo,$runId,$status,$aggregate,$message)
    {
        $st=$pdo->prepare('UPDATE `' . self::RUN_TABLE . '` SET run_status=:status,source_project_count=:projects,analyzable_project_count=:analyzable,ceo_index_score=:score,ceo_index_grade=:grade,coverage_rate=:coverage,finished_at=:finished,error_summary=:message WHERE id=:id');
        return $st?$st->execute(array(':status'=>$status,':projects'=>(int)$aggregate['source_project_count'],':analyzable'=>(int)$aggregate['analyzable_project_count'],':score'=>$aggregate['ceo_index_score'],':grade'=>$aggregate['ceo_index_grade'],':coverage'=>$aggregate['coverage_rate'],':finished'=>self::now(),':message'=>$message!==''?substr($message,0,500):null,':id'=>(int)$runId)):false;
    }

    private static function acquireLock($pdo,$context)
    {
        $name='cpms_ai_ceo_index_'.str_replace('-','',$context['analysis_date']).'_'.str_replace('-','',$context['target_ym']);
        try { $st=$pdo->prepare('SELECT GET_LOCK(:name,0)'); if (!$st||!$st->execute(array(':name'=>$name))) return array('ok'=>true,'name'=>''); return array('ok'=>(int)$st->fetchColumn()===1,'name'=>$name); } catch (Exception $e) { return array('ok'=>true,'name'=>''); }
    }

    private static function releaseLock($pdo,$lock)
    {
        if (!is_array($lock)||empty($lock['name'])) return;
        try { $st=$pdo->prepare('SELECT RELEASE_LOCK(:name)'); if ($st) $st->execute(array(':name'=>$lock['name'])); } catch (Exception $e) {}
    }

    private static function clearStaleRuns($pdo,$context)
    {
        try { $st=$pdo->prepare("UPDATE `" . self::RUN_TABLE . "` SET run_status='FAILED',finished_at=:finished,error_summary='오래된 실행상태를 종료했습니다.' WHERE analysis_date=:date AND target_ym=:ym AND run_status='RUNNING' AND started_at<DATE_SUB(NOW(),INTERVAL 1 HOUR)"); if ($st) $st->execute(array(':finished'=>self::now(),':date'=>$context['analysis_date'],':ym'=>$context['target_ym'])); } catch (Exception $e) {}
    }

    private static function hasRunning($pdo,$context)
    {
        try { $st=$pdo->prepare("SELECT COUNT(*) FROM `" . self::RUN_TABLE . "` WHERE analysis_date=:date AND target_ym=:ym AND run_status='RUNNING' AND started_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)"); return $st&&$st->execute(array(':date'=>$context['analysis_date'],':ym'=>$context['target_ym']))&&(int)$st->fetchColumn()>0; } catch (Exception $e) { return false; }
    }

    private static function previousScore($pdo,$context)
    {
        try { $st=$pdo->prepare('SELECT ceo_index_score FROM `' . self::RESULT_TABLE . '` WHERE target_ym=:ym AND analysis_date<:date AND ceo_index_score IS NOT NULL ORDER BY analysis_date DESC,id DESC LIMIT 1'); if (!$st||!$st->execute(array(':ym'=>$context['target_ym'],':date'=>$context['analysis_date']))) return null; $value=$st->fetchColumn(); return $value===false||$value===null?null:(float)$value; } catch (Exception $e) { return null; }
    }

    private static function saveProject($pdo,$runId,$context,$row)
    {
        $now=self::now();
        $columns=array_values(array_diff(self::requiredProjectColumns(),array('id'))); $holders=array(); $updates=array(); $values=array();
        foreach ($columns as $column) { $holders[]=':'.$column; if (!in_array($column,array('analysis_date','target_ym','project_id','first_created_at','calculation_count','created_at'),true)) $updates[]='`'.$column.'`=VALUES(`'.$column.'`)'; $values[':'.$column]=array_key_exists($column,$row)?$row[$column]:null; }
        foreach (array('run_id'=>(int)$runId,'analysis_date'=>$context['analysis_date'],'target_ym'=>$context['target_ym'],'first_created_at'=>$now,'last_calculated_at'=>$now,'calculation_count'=>1,'created_at'=>$now,'updated_at'=>$now) as $key=>$value) $values[':'.$key]=$value;
        $updates[]='`calculation_count`=`calculation_count`+1';
        $st=$pdo->prepare('INSERT INTO `' . self::PROJECT_TABLE . '` (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',$holders).') ON DUPLICATE KEY UPDATE '.implode(',',$updates));
        return $st?$st->execute($values):false;
    }

    private static function saveCompany($pdo,$runId,$context,$fingerprint,$aggregate,$previous)
    {
        $now=self::now(); $change=$previous!==null&&$aggregate['ceo_index_score']!==null?round((float)$aggregate['ceo_index_score']-$previous,2):null;
        $sql='INSERT INTO `' . self::RESULT_TABLE . '` (run_id,analysis_date,target_ym,source_fingerprint,ceo_index_score,ceo_index_grade,previous_score,score_change,financial_stability_score,input_reliability_score,anomaly_stability_score,sales_certainty_score,data_readiness_score,source_project_count,analyzable_project_count,coverage_rate,normal_count,watch_count,warning_count,critical_count,insufficient_count,monthly_sales_total,monthly_forecast_input_total,monthly_forecast_profit_total,cumulative_projected_profit_total,component_data,warning_data,data_status,first_created_at,last_calculated_at,calculation_count,created_at,updated_at) VALUES (:run_id,:date,:ym,:fingerprint,:score,:grade,:previous,:change,:financial,:reliability,:anomaly,:sales,:readiness,:projects,:analyzable,:coverage,:normal,:watch,:warning,:critical,:insufficient,:monthly_sales,:monthly_input,:monthly_profit,:cumulative_profit,:components,:warnings,:data_status,:first_created,:last_calculated,1,:created,:updated) ON DUPLICATE KEY UPDATE run_id=VALUES(run_id),source_fingerprint=VALUES(source_fingerprint),ceo_index_score=VALUES(ceo_index_score),ceo_index_grade=VALUES(ceo_index_grade),previous_score=VALUES(previous_score),score_change=VALUES(score_change),financial_stability_score=VALUES(financial_stability_score),input_reliability_score=VALUES(input_reliability_score),anomaly_stability_score=VALUES(anomaly_stability_score),sales_certainty_score=VALUES(sales_certainty_score),data_readiness_score=VALUES(data_readiness_score),source_project_count=VALUES(source_project_count),analyzable_project_count=VALUES(analyzable_project_count),coverage_rate=VALUES(coverage_rate),normal_count=VALUES(normal_count),watch_count=VALUES(watch_count),warning_count=VALUES(warning_count),critical_count=VALUES(critical_count),insufficient_count=VALUES(insufficient_count),monthly_sales_total=VALUES(monthly_sales_total),monthly_forecast_input_total=VALUES(monthly_forecast_input_total),monthly_forecast_profit_total=VALUES(monthly_forecast_profit_total),cumulative_projected_profit_total=VALUES(cumulative_projected_profit_total),component_data=VALUES(component_data),warning_data=VALUES(warning_data),data_status=VALUES(data_status),last_calculated_at=VALUES(last_calculated_at),calculation_count=calculation_count+1,updated_at=VALUES(updated_at)';
        $st=$pdo->prepare($sql);
        return $st?$st->execute(array(':run_id'=>(int)$runId,':date'=>$context['analysis_date'],':ym'=>$context['target_ym'],':fingerprint'=>$fingerprint,':score'=>$aggregate['ceo_index_score'],':grade'=>$aggregate['ceo_index_grade'],':previous'=>$previous,':change'=>$change,':financial'=>$aggregate['financial_stability_score'],':reliability'=>$aggregate['input_reliability_score'],':anomaly'=>$aggregate['anomaly_stability_score'],':sales'=>$aggregate['sales_certainty_score'],':readiness'=>$aggregate['data_readiness_score'],':projects'=>$aggregate['source_project_count'],':analyzable'=>$aggregate['analyzable_project_count'],':coverage'=>$aggregate['coverage_rate'],':normal'=>$aggregate['normal_count'],':watch'=>$aggregate['watch_count'],':warning'=>$aggregate['warning_count'],':critical'=>$aggregate['critical_count'],':insufficient'=>$aggregate['insufficient_count'],':monthly_sales'=>$aggregate['monthly_sales_total'],':monthly_input'=>$aggregate['monthly_forecast_input_total'],':monthly_profit'=>$aggregate['monthly_forecast_profit_total'],':cumulative_profit'=>$aggregate['cumulative_projected_profit_total'],':components'=>self::encode($aggregate['component_data']),':warnings'=>self::encode($aggregate['warning_data']),':data_status'=>$aggregate['data_status'],':first_created'=>$now,':last_calculated'=>$now,':created'=>$now,':updated'=>$now)):false;
    }

    private static function cachedResult($pdo,$fingerprint)
    {
        try { $st=$pdo->prepare('SELECT * FROM `' . self::RESULT_TABLE . '` WHERE source_fingerprint=:fingerprint ORDER BY last_calculated_at DESC,id DESC LIMIT 1'); if (!$st||!$st->execute(array(':fingerprint'=>$fingerprint))) return array(); $row=$st->fetch(PDO::FETCH_ASSOC); return is_array($row)?$row:array(); } catch (Exception $e) { return array(); }
    }

    public static function calculateLatest($pdo = null,$triggerType = 'SYSTEM')
    {
        $pdo=self::pdo($pdo); $trigger=self::trigger($triggerType);
        $empty=array('ok'=>false,'cached'=>false,'busy'=>false,'status'=>'FAILED','analysis_date'=>'','target_ym'=>'','projects'=>0,'analyzable'=>0,'coverage'=>0,'score'=>null,'grade'=>'INSUFFICIENT','message'=>'CEO Index를 계산하지 못했습니다.');
        if (!$pdo) { $empty['message']='DB 연결 상태를 확인할 수 없습니다.'; return $empty; }
        if (!self::isInstalled($pdo)) { $empty['message']='CEO Index 테이블을 먼저 설치해주세요.'; return $empty; }
        $context=self::latestRiskContext($pdo);
        if (empty($context['available'])) { $empty['message']='CEO Index를 계산하려면 먼저 적자·원가율 위험분석을 실행해주세요.'; return $empty; }
        $empty['analysis_date']=$context['analysis_date']; $empty['target_ym']=$context['target_ym'];
        $riskRows=self::loadRiskRows($pdo,$context);
        if (count($riskRows)===0) { $empty['message']='CEO Index에 사용할 위험분석 결과가 없습니다.'; return $empty; }
        $fingerprint=self::sourceFingerprint($context,$riskRows);
        if ($fingerprint==='') return $empty;
        $cached=self::cachedResult($pdo,$fingerprint);
        if (!empty($cached)) {
            $runId=self::createRun($pdo,$context,$fingerprint,$trigger,'CACHED',(int)$cached['source_project_count'],(int)$cached['analyzable_project_count'],$cached['ceo_index_score'],$cached['ceo_index_grade'],$cached['coverage_rate']);
            if ($runId>0) self::finishRun($pdo,$runId,'CACHED',array('source_project_count'=>(int)$cached['source_project_count'],'analyzable_project_count'=>(int)$cached['analyzable_project_count'],'ceo_index_score'=>$cached['ceo_index_score'],'ceo_index_grade'=>$cached['ceo_index_grade'],'coverage_rate'=>$cached['coverage_rate']),'동일 자료의 저장된 CEO Index를 사용했습니다.');
            return array_merge($empty,array('ok'=>true,'cached'=>true,'status'=>'CACHED','projects'=>(int)$cached['source_project_count'],'analyzable'=>(int)$cached['analyzable_project_count'],'coverage'=>(float)$cached['coverage_rate'],'score'=>$cached['ceo_index_score']===null?null:(float)$cached['ceo_index_score'],'grade'=>$cached['ceo_index_grade'],'message'=>'동일 자료의 저장된 CEO Index를 사용했습니다.'));
        }
        $lock=self::acquireLock($pdo,$context);
        if (empty($lock['ok'])) { $empty['busy']=true; $empty['message']='CEO Index 계산이 이미 진행 중입니다.'; return $empty; }
        $runId=0;
        try {
            self::clearStaleRuns($pdo,$context);
            if (self::hasRunning($pdo,$context)) { self::releaseLock($pdo,$lock); $empty['busy']=true; $empty['message']='CEO Index 계산이 이미 진행 중입니다.'; return $empty; }
            $runId=self::createRun($pdo,$context,$fingerprint,$trigger,'RUNNING',count($riskRows),0,null,'INSUFFICIENT',0);
            if ($runId<=0) throw new Exception('run failed');
            $projects=array(); $failed=0;
            foreach ($riskRows as $riskRow) {
                try {
                    $project=self::calculateProject($riskRow);
                    if ($project['project_id']<=0) throw new Exception('project failed');
                    $projects[]=$project;
                    if (!self::saveProject($pdo,$runId,$context,$project)) {
                        $failed++;
                        error_log('[AiCeoIndex] project result save failed');
                    }
                } catch (Exception $e) {
                    $failed++;
                    error_log('[AiCeoIndex] project calculation failed');
                }
            }
            $aggregate=self::aggregateCompany($projects); $previous=self::previousScore($pdo,$context);
            if (count($projects)===0||!self::saveCompany($pdo,$runId,$context,$fingerprint,$aggregate,$previous)) throw new Exception('company failed');
            $status=$failed>0?'PARTIAL':'COMPLETED'; $message=$failed>0?'일부 현장을 제외하고 CEO Index를 계산했습니다.':'CEO Index 계산을 완료했습니다.';
            self::finishRun($pdo,$runId,$status,$aggregate,$failed>0?'일부 프로젝트 CEO Index 계산 실패: '.$failed.'건':'');
            self::releaseLock($pdo,$lock);
            return array_merge($empty,array('ok'=>true,'status'=>$status,'projects'=>$aggregate['source_project_count'],'analyzable'=>$aggregate['analyzable_project_count'],'coverage'=>$aggregate['coverage_rate'],'score'=>$aggregate['ceo_index_score'],'grade'=>$aggregate['ceo_index_grade'],'message'=>$message));
        } catch (Exception $e) {
            $aggregate=array('source_project_count'=>count($riskRows),'analyzable_project_count'=>0,'ceo_index_score'=>null,'ceo_index_grade'=>'INSUFFICIENT','coverage_rate'=>0);
            if ($runId>0) self::finishRun($pdo,$runId,'FAILED',$aggregate,'CEO Index 계산 중 오류가 발생했습니다.');
            error_log('[AiCeoIndex] calculation failed'); self::releaseLock($pdo,$lock); return $empty;
        }
    }

    public static function latestResult($pdo = null)
    {
        $pdo=self::pdo($pdo); if (!$pdo||!self::tableExists($pdo,self::RESULT_TABLE)) return array();
        try { $st=$pdo->query('SELECT * FROM `' . self::RESULT_TABLE . '` ORDER BY analysis_date DESC,last_calculated_at DESC,id DESC LIMIT 1'); $row=$st?$st->fetch(PDO::FETCH_ASSOC):false; return is_array($row)?$row:array(); } catch (Exception $e) { return array(); }
    }

    public static function listProjects($pdo,$analysisDate,$targetYm,$page,$perPage)
    {
        $pdo=self::pdo($pdo); $page=max(1,(int)$page); $perPage=max(1,min(100,(int)$perPage));
        if (!$pdo||!self::tableExists($pdo,self::PROJECT_TABLE)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$analysisDate)||!preg_match('/^\d{4}-\d{2}$/',(string)$targetYm)) return array();
        try {
            $sql="SELECT * FROM `" . self::PROJECT_TABLE . "` WHERE analysis_date=:date AND target_ym=:ym ORDER BY CASE project_index_grade WHEN 'CRITICAL' THEN 1 WHEN 'WARNING' THEN 2 WHEN 'WATCH' THEN 3 WHEN 'INSUFFICIENT' THEN 4 ELSE 5 END, risk_score DESC, CASE WHEN monthly_forecast_profit_amount<0 THEN ABS(monthly_forecast_profit_amount) ELSE 0 END DESC, project_id ASC LIMIT :limit OFFSET :offset";
            $st=$pdo->prepare($sql); if (!$st) return array();
            if (!$st->bindValue(':date',$analysisDate,PDO::PARAM_STR)||!$st->bindValue(':ym',$targetYm,PDO::PARAM_STR)||!$st->bindValue(':limit',$perPage,PDO::PARAM_INT)||!$st->bindValue(':offset',($page-1)*$perPage,PDO::PARAM_INT)||!$st->execute()) return array();
            $rows=$st->fetchAll(PDO::FETCH_ASSOC); return is_array($rows)?$rows:array();
        } catch (Exception $e) { return array(); }
    }

    public static function countProjects($pdo,$analysisDate,$targetYm)
    {
        $pdo=self::pdo($pdo); if (!$pdo||!self::tableExists($pdo,self::PROJECT_TABLE)) return 0;
        try { $st=$pdo->prepare('SELECT COUNT(*) FROM `' . self::PROJECT_TABLE . '` WHERE analysis_date=:date AND target_ym=:ym'); return $st&&$st->execute(array(':date'=>$analysisDate,':ym'=>$targetYm))?(int)$st->fetchColumn():0; } catch (Exception $e) { return 0; }
    }

    public static function latestDashboardCard($pdo = null)
    {
        $row=self::latestResult($pdo);
        if (empty($row)) return array('available'=>false);
        return array('available'=>true,'score'=>isset($row['ceo_index_score'])&&$row['ceo_index_score']!==null?(float)$row['ceo_index_score']:null,'grade'=>isset($row['ceo_index_grade'])?$row['ceo_index_grade']:'INSUFFICIENT','monthly_profit'=>isset($row['monthly_forecast_profit_total'])?(float)$row['monthly_forecast_profit_total']:0,'critical_count'=>isset($row['critical_count'])?(int)$row['critical_count']:0,'warning_count'=>isset($row['warning_count'])?(int)$row['warning_count']:0,'insufficient_count'=>isset($row['insufficient_count'])?(int)$row['insufficient_count']:0,'analysis_date'=>isset($row['analysis_date'])?(string)$row['analysis_date']:'');
    }

    public static function createV2RunTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS cpms_ai_ceo_index_runs_v2 (\n id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,\n run_uid VARCHAR(64) NOT NULL,\n analysis_date DATE NOT NULL,\n target_ym CHAR(7) NOT NULL,\n trigger_type VARCHAR(20) NOT NULL,\n run_status VARCHAR(20) NOT NULL,\n calculation_version VARCHAR(40) NOT NULL,\n source_fingerprint CHAR(64) NOT NULL,\n source_project_count INT UNSIGNED NOT NULL DEFAULT 0,\n analyzable_project_count INT UNSIGNED NOT NULL DEFAULT 0,\n ceo_index_score DECIMAL(6,2) NULL,\n ceo_index_grade VARCHAR(20) NOT NULL,\n coverage_rate DECIMAL(8,3) NOT NULL DEFAULT 0,\n started_at DATETIME NOT NULL,\n finished_at DATETIME NULL,\n error_summary VARCHAR(500) NULL,\n created_at DATETIME NOT NULL,\n UNIQUE KEY uk_ai_ceo_v2_run_uid (run_uid),\n KEY idx_ai_ceo_v2_run_date (analysis_date,started_at),\n KEY idx_ai_ceo_v2_run_status (run_status,started_at)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    public static function createV2ResultTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS cpms_ai_ceo_index_results_v2 (\n id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,\n run_id BIGINT UNSIGNED NULL,\n analysis_date DATE NOT NULL,\n target_ym CHAR(7) NOT NULL,\n calculation_version VARCHAR(40) NOT NULL,\n source_fingerprint CHAR(64) NOT NULL,\n ceo_index_score DECIMAL(6,2) NULL,\n ceo_index_grade VARCHAR(20) NOT NULL,\n previous_score DECIMAL(6,2) NULL,\n score_change DECIMAL(6,2) NULL,\n input_stability_score DECIMAL(6,2) NULL,\n completion_confidence_score DECIMAL(6,2) NULL,\n anomaly_stability_score DECIMAL(6,2) NULL,\n overinput_stability_score DECIMAL(6,2) NULL,\n data_readiness_score DECIMAL(6,2) NULL,\n source_project_count INT UNSIGNED NOT NULL DEFAULT 0,\n analyzable_project_count INT UNSIGNED NOT NULL DEFAULT 0,\n coverage_rate DECIMAL(8,3) NOT NULL DEFAULT 0,\n current_input_total DECIMAL(18,2) NOT NULL DEFAULT 0,\n expected_unentered_total DECIMAL(18,2) NOT NULL DEFAULT 0,\n final_forecast_total DECIMAL(18,2) NOT NULL DEFAULT 0,\n forecast_low_total DECIMAL(18,2) NOT NULL DEFAULT 0,\n forecast_high_total DECIMAL(18,2) NOT NULL DEFAULT 0,\n confidence_score DECIMAL(6,2) NULL,\n critical_count INT UNSIGNED NOT NULL DEFAULT 0,\n warning_count INT UNSIGNED NOT NULL DEFAULT 0,\n missing_count INT UNSIGNED NOT NULL DEFAULT 0,\n insufficient_count INT UNSIGNED NOT NULL DEFAULT 0,\n component_data MEDIUMTEXT NULL,\n warning_data MEDIUMTEXT NULL,\n data_status VARCHAR(30) NOT NULL,\n first_created_at DATETIME NOT NULL,\n last_calculated_at DATETIME NOT NULL,\n calculation_count INT UNSIGNED NOT NULL DEFAULT 1,\n created_at DATETIME NOT NULL,\n updated_at DATETIME NOT NULL,\n UNIQUE KEY uk_ai_ceo_v2_result (analysis_date,target_ym),\n KEY idx_ai_ceo_v2_grade (ceo_index_grade,analysis_date),\n KEY idx_ai_ceo_v2_source (source_fingerprint)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    public static function createV2ProjectTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS cpms_ai_ceo_project_results_v2 (\n id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,\n run_id BIGINT UNSIGNED NULL,\n analysis_date DATE NOT NULL,\n target_ym CHAR(7) NOT NULL,\n project_id INT UNSIGNED NOT NULL,\n project_name_snapshot VARCHAR(190) NULL,\n project_status_snapshot VARCHAR(50) NULL,\n calculation_version VARCHAR(40) NOT NULL,\n project_index_score DECIMAL(6,2) NULL,\n project_index_grade VARCHAR(20) NOT NULL,\n input_stability_score DECIMAL(6,2) NULL,\n completion_confidence_score DECIMAL(6,2) NULL,\n anomaly_stability_score DECIMAL(6,2) NULL,\n overinput_stability_score DECIMAL(6,2) NULL,\n data_readiness_score DECIMAL(6,2) NULL,\n available_weight DECIMAL(8,3) NOT NULL DEFAULT 0,\n current_input_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n expected_completion_rate DECIMAL(8,3) NULL,\n expected_unentered_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n final_forecast_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n forecast_low_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n forecast_high_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n forecast_confidence_score DECIMAL(6,2) NULL,\n forecast_confidence_grade VARCHAR(20) NOT NULL,\n overinput_grade VARCHAR(20) NOT NULL,\n missing_possibility_grade VARCHAR(20) NOT NULL,\n contract_risk_grade VARCHAR(20) NOT NULL,\n anomaly_count INT UNSIGNED NOT NULL DEFAULT 0,\n data_status VARCHAR(30) NOT NULL,\n first_created_at DATETIME NOT NULL,\n last_calculated_at DATETIME NOT NULL,\n calculation_count INT UNSIGNED NOT NULL DEFAULT 1,\n created_at DATETIME NOT NULL,\n updated_at DATETIME NOT NULL,\n UNIQUE KEY uk_ai_ceo_v2_project (analysis_date,target_ym,project_id),\n KEY idx_ai_ceo_v2_project_date (project_id,analysis_date),\n KEY idx_ai_ceo_v2_project_grade (project_index_grade,analysis_date),\n KEY idx_ai_ceo_v2_project_run (run_id)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    public static function installV2($pdo = null)
    {
        $pdo=self::pdo($pdo); if(!$pdo)return array('ok'=>false,'message'=>'DB 연결 상태를 확인할 수 없습니다.');
        try { foreach(array(self::createV2RunTableSql(),self::createV2ResultTableSql(),self::createV2ProjectTableSql()) as $sql) if($pdo->exec($sql)===false)return array('ok'=>false,'message'=>'CEO Index V2 테이블을 설치하지 못했습니다.'); self::clearSchemaCache($pdo); return array('ok'=>self::isV2Installed($pdo),'message'=>self::isV2Installed($pdo)?'CEO Index V2 테이블 설치를 확인했습니다.':'CEO Index V2 테이블 구조를 확인해주세요.'); } catch(Exception $e){error_log('[AiCeoIndexV2] install failed');return array('ok'=>false,'message'=>'CEO Index V2 설치를 확인하지 못했습니다.');}
    }

    public static function isV2Installed($pdo = null)
    {
        $pdo=self::pdo($pdo);return $pdo&&self::tableExists($pdo,self::V2_RUN_TABLE)&&self::tableExists($pdo,self::V2_RESULT_TABLE)&&self::tableExists($pdo,self::V2_PROJECT_TABLE);
    }

    private static function v2GradeScore($grade,$map)
    {
        $grade=strtoupper(trim((string)$grade));return isset($map[$grade])?(float)$map[$grade]:null;
    }

    public static function calculateProjectV2($row)
    {
        $over=self::v2GradeScore(isset($row['overinput_grade'])?$row['overinput_grade']:'',array('NORMAL'=>100,'WATCH'=>70,'WARNING'=>40,'CRITICAL'=>10));
        $inputStability=self::v2GradeScore(isset($row['missing_possibility_grade'])?$row['missing_possibility_grade']:'',array('LOW'=>100,'MEDIUM'=>65,'HIGH'=>25));
        $confidence=isset($row['forecast_confidence_score'])&&$row['forecast_confidence_score']!==null?self::clampScore($row['forecast_confidence_score']):null;
        $anomaly=isset($row['anomaly_count'])?self::clampScore(100-min(100,(int)$row['anomaly_count']*15)):null;
        $readiness=isset($row['category_analyzable_rate'])?self::clampScore($row['category_analyzable_rate']):null;
        $components=array('input_stability_score'=>$inputStability,'completion_confidence_score'=>$confidence,'anomaly_stability_score'=>$anomaly,'overinput_stability_score'=>$over,'data_readiness_score'=>$readiness);
        $weights=array('input_stability_score'=>35,'completion_confidence_score'=>25,'anomaly_stability_score'=>20,'overinput_stability_score'=>10,'data_readiness_score'=>10);$available=0.0;$weighted=0.0;
        foreach($weights as $key=>$weight)if($components[$key]!==null){$available+=$weight;$weighted+=$components[$key]*$weight;}
        $score=$available>=60?round($weighted/$available,2):null;
        return array_merge($row,$components,array('project_index_score'=>$score,'project_index_grade'=>self::grade($score),'available_weight'=>$available,'calculation_version'=>self::V2_VERSION));
    }

    private static function v2Context($pdo,$targetYm='')
    {
        $empty=array('available'=>false);if(!$pdo||!self::tableExists($pdo,self::V2_FORECAST_TABLE))return $empty;
        try{$where='';$params=array();if(preg_match('/^\d{4}-\d{2}$/',(string)$targetYm)){$where=' WHERE target_ym=:ym';$params[':ym']=$targetYm;}$st=$pdo->prepare('SELECT analysis_date,target_ym FROM `'.self::V2_FORECAST_TABLE.'`'.$where.' ORDER BY analysis_date DESC,id DESC LIMIT 1');if(!$st||!$st->execute($params))return $empty;$row=$st->fetch(PDO::FETCH_ASSOC);if(!is_array($row))return $empty;$row['available']=true;return $row;}catch(Exception $e){return $empty;}
    }

    private static function v2Rows($pdo,$context)
    {
        try{$st=$pdo->prepare('SELECT * FROM `'.self::V2_FORECAST_TABLE.'` WHERE analysis_date=:date AND target_ym=:ym ORDER BY project_id');if(!$st||!$st->execute(array(':date'=>$context['analysis_date'],':ym'=>$context['target_ym'])))return array();$rows=$st->fetchAll(PDO::FETCH_ASSOC);return is_array($rows)?$rows:array();}catch(Exception $e){return array();}
    }

    private static function saveV2Project($pdo,$runId,$context,$row)
    {
        $now=date('Y-m-d H:i:s');$sql='INSERT INTO `'.self::V2_PROJECT_TABLE.'` (run_id,analysis_date,target_ym,project_id,project_name_snapshot,project_status_snapshot,calculation_version,project_index_score,project_index_grade,input_stability_score,completion_confidence_score,anomaly_stability_score,overinput_stability_score,data_readiness_score,available_weight,current_input_amount,expected_completion_rate,expected_unentered_amount,final_forecast_amount,forecast_low_amount,forecast_high_amount,forecast_confidence_score,forecast_confidence_grade,overinput_grade,missing_possibility_grade,contract_risk_grade,anomaly_count,data_status,first_created_at,last_calculated_at,calculation_count,created_at,updated_at) VALUES (:run,:date,:ym,:project,:name,:project_status,:version,:score,:grade,:input_stability,:confidence_component,:anomaly_stability,:overinput_stability,:readiness,:available,:current,:completion,:unentered,:forecast,:low,:high,:confidence,:confidence_grade,:overinput,:missing,:contract_risk,:anomalies,:data_status,:first,:last,1,:created,:updated) ON DUPLICATE KEY UPDATE run_id=VALUES(run_id),project_name_snapshot=VALUES(project_name_snapshot),project_status_snapshot=VALUES(project_status_snapshot),project_index_score=VALUES(project_index_score),project_index_grade=VALUES(project_index_grade),input_stability_score=VALUES(input_stability_score),completion_confidence_score=VALUES(completion_confidence_score),anomaly_stability_score=VALUES(anomaly_stability_score),overinput_stability_score=VALUES(overinput_stability_score),data_readiness_score=VALUES(data_readiness_score),available_weight=VALUES(available_weight),current_input_amount=VALUES(current_input_amount),expected_completion_rate=VALUES(expected_completion_rate),expected_unentered_amount=VALUES(expected_unentered_amount),final_forecast_amount=VALUES(final_forecast_amount),forecast_low_amount=VALUES(forecast_low_amount),forecast_high_amount=VALUES(forecast_high_amount),forecast_confidence_score=VALUES(forecast_confidence_score),forecast_confidence_grade=VALUES(forecast_confidence_grade),overinput_grade=VALUES(overinput_grade),missing_possibility_grade=VALUES(missing_possibility_grade),contract_risk_grade=VALUES(contract_risk_grade),anomaly_count=VALUES(anomaly_count),data_status=VALUES(data_status),last_calculated_at=VALUES(last_calculated_at),calculation_count=calculation_count+1,updated_at=VALUES(updated_at)';$st=$pdo->prepare($sql);if(!$st)return false;return $st->execute(array(':run'=>$runId,':date'=>$context['analysis_date'],':ym'=>$context['target_ym'],':project'=>$row['project_id'],':name'=>$row['project_name_snapshot'],':project_status'=>$row['project_status_snapshot'],':version'=>self::V2_VERSION,':score'=>$row['project_index_score'],':grade'=>$row['project_index_grade'],':input_stability'=>$row['input_stability_score'],':confidence_component'=>$row['completion_confidence_score'],':anomaly_stability'=>$row['anomaly_stability_score'],':overinput_stability'=>$row['overinput_stability_score'],':readiness'=>$row['data_readiness_score'],':available'=>$row['available_weight'],':current'=>$row['current_input_amount'],':completion'=>$row['expected_completion_rate'],':unentered'=>$row['expected_unentered_amount'],':forecast'=>$row['final_forecast_amount'],':low'=>$row['forecast_low_amount'],':high'=>$row['forecast_high_amount'],':confidence'=>$row['forecast_confidence_score'],':confidence_grade'=>$row['forecast_confidence_grade'],':overinput'=>$row['overinput_grade'],':missing'=>$row['missing_possibility_grade'],':contract_risk'=>$row['contract_risk_grade'],':anomalies'=>$row['anomaly_count'],':data_status'=>$row['data_status'],':first'=>$now,':last'=>$now,':created'=>$now,':updated'=>$now));
    }

    private static function aggregateV2($projects)
    {
        $weights=self::companyWeightsV2($projects);$keys=array('input_stability_score','completion_confidence_score','anomaly_stability_score','overinput_stability_score','data_readiness_score');$totals=array();$bases=array();foreach($keys as $key){$totals[$key]=0.0;$bases[$key]=0.0;}$result=array('source_project_count'=>count($projects),'analyzable_project_count'=>0,'coverage_rate'=>0,'current_input_total'=>0,'expected_unentered_total'=>0,'final_forecast_total'=>0,'forecast_low_total'=>0,'forecast_high_total'=>0,'critical_count'=>0,'warning_count'=>0,'missing_count'=>0,'insufficient_count'=>0);$sum=0.0;$base=0.0;$confidenceSum=0.0;$confidenceBase=0.0;
        foreach($projects as $index=>$row){$weight=isset($weights[$index])?$weights[$index]:0;$result['current_input_total']+=(float)$row['current_input_amount'];$result['expected_unentered_total']+=(float)$row['expected_unentered_amount'];$result['final_forecast_total']+=(float)$row['final_forecast_amount'];$result['forecast_low_total']+=(float)$row['forecast_low_amount'];$result['forecast_high_total']+=(float)$row['forecast_high_amount'];if($row['project_index_score']!==null){$sum+=(float)$row['project_index_score']*$weight;$base+=$weight;$result['analyzable_project_count']++;}else$result['insufficient_count']++;if($row['project_index_grade']==='CRITICAL')$result['critical_count']++;else if($row['project_index_grade']==='WARNING')$result['warning_count']++;if($row['missing_possibility_grade']==='HIGH')$result['missing_count']++;if($row['forecast_confidence_score']!==null){$confidenceSum+=(float)$row['forecast_confidence_score']*$weight;$confidenceBase+=$weight;}foreach($keys as $key)if($row[$key]!==null){$totals[$key]+=(float)$row[$key]*$weight;$bases[$key]+=$weight;}}
        $result['coverage_rate']=round($base,3);$result['ceo_index_score']=$base>=60?round($sum/$base,2):null;$result['ceo_index_grade']=self::grade($result['ceo_index_score']);$result['confidence_score']=$confidenceBase>0?round($confidenceSum/$confidenceBase,2):null;$result['data_status']=$result['ceo_index_score']===null?'INSUFFICIENT':($base<80?'LIMITED':'READY');foreach($keys as $key)$result[$key]=$bases[$key]>0?round($totals[$key]/$bases[$key],2):null;$result['component_data']=array('project_weights'=>$weights,'component_coverage'=>$bases);$result['warning_data']=$base<60?array('분석 가능한 현장 비중이 60% 미만입니다.'):array();return $result;
    }

    public static function companyWeightsV2($projects)
    {
        $copy=array();foreach($projects as $row){$copy[]=array('monthly_sales_amount'=>0,'monthly_forecast_input_amount'=>max((float)$row['final_forecast_amount'],(float)$row['current_input_amount'],1));}return self::companyWeights($copy);
    }

    private static function saveV2Company($pdo,$runId,$context,$fingerprint,$a,$previous)
    {
        $now=date('Y-m-d H:i:s');$change=$previous===null||$a['ceo_index_score']===null?null:round($a['ceo_index_score']-$previous,2);$sql='INSERT INTO `'.self::V2_RESULT_TABLE.'` (run_id,analysis_date,target_ym,calculation_version,source_fingerprint,ceo_index_score,ceo_index_grade,previous_score,score_change,input_stability_score,completion_confidence_score,anomaly_stability_score,overinput_stability_score,data_readiness_score,source_project_count,analyzable_project_count,coverage_rate,current_input_total,expected_unentered_total,final_forecast_total,forecast_low_total,forecast_high_total,confidence_score,critical_count,warning_count,missing_count,insufficient_count,component_data,warning_data,data_status,first_created_at,last_calculated_at,calculation_count,created_at,updated_at) VALUES (:run,:date,:ym,:version,:fingerprint,:score,:grade,:previous,:change,:input_stability,:completion,:anomaly,:overinput,:readiness,:projects,:analyzable,:coverage,:current,:unentered,:forecast,:low,:high,:confidence,:critical,:warning,:missing,:insufficient,:components,:warnings,:data_status,:first,:last,1,:created,:updated) ON DUPLICATE KEY UPDATE run_id=VALUES(run_id),source_fingerprint=VALUES(source_fingerprint),ceo_index_score=VALUES(ceo_index_score),ceo_index_grade=VALUES(ceo_index_grade),previous_score=VALUES(previous_score),score_change=VALUES(score_change),input_stability_score=VALUES(input_stability_score),completion_confidence_score=VALUES(completion_confidence_score),anomaly_stability_score=VALUES(anomaly_stability_score),overinput_stability_score=VALUES(overinput_stability_score),data_readiness_score=VALUES(data_readiness_score),source_project_count=VALUES(source_project_count),analyzable_project_count=VALUES(analyzable_project_count),coverage_rate=VALUES(coverage_rate),current_input_total=VALUES(current_input_total),expected_unentered_total=VALUES(expected_unentered_total),final_forecast_total=VALUES(final_forecast_total),forecast_low_total=VALUES(forecast_low_total),forecast_high_total=VALUES(forecast_high_total),confidence_score=VALUES(confidence_score),critical_count=VALUES(critical_count),warning_count=VALUES(warning_count),missing_count=VALUES(missing_count),insufficient_count=VALUES(insufficient_count),component_data=VALUES(component_data),warning_data=VALUES(warning_data),data_status=VALUES(data_status),last_calculated_at=VALUES(last_calculated_at),calculation_count=calculation_count+1,updated_at=VALUES(updated_at)';$st=$pdo->prepare($sql);if(!$st)return false;return $st->execute(array(':run'=>$runId,':date'=>$context['analysis_date'],':ym'=>$context['target_ym'],':version'=>self::V2_VERSION,':fingerprint'=>$fingerprint,':score'=>$a['ceo_index_score'],':grade'=>$a['ceo_index_grade'],':previous'=>$previous,':change'=>$change,':input_stability'=>$a['input_stability_score'],':completion'=>$a['completion_confidence_score'],':anomaly'=>$a['anomaly_stability_score'],':overinput'=>$a['overinput_stability_score'],':readiness'=>$a['data_readiness_score'],':projects'=>$a['source_project_count'],':analyzable'=>$a['analyzable_project_count'],':coverage'=>$a['coverage_rate'],':current'=>$a['current_input_total'],':unentered'=>$a['expected_unentered_total'],':forecast'=>$a['final_forecast_total'],':low'=>$a['forecast_low_total'],':high'=>$a['forecast_high_total'],':confidence'=>$a['confidence_score'],':critical'=>$a['critical_count'],':warning'=>$a['warning_count'],':missing'=>$a['missing_count'],':insufficient'=>$a['insufficient_count'],':components'=>self::encode($a['component_data']),':warnings'=>self::encode($a['warning_data']),':data_status'=>$a['data_status'],':first'=>$now,':last'=>$now,':created'=>$now,':updated'=>$now));
    }

    public static function calculateLatestV2($pdo=null,$triggerType='SYSTEM')
    {
        $pdo=self::pdo($pdo);$empty=array('ok'=>false,'status'=>'FAILED','message'=>'CEO Index V2를 계산하지 못했습니다.');if(!$pdo){$empty['message']='DB 연결 상태를 확인할 수 없습니다.';return $empty;}if(!self::isV2Installed($pdo)){$empty['message']='CEO Index V2 테이블을 먼저 설치해주세요.';return $empty;}$context=self::v2Context($pdo);if(empty($context['available'])){$empty['message']='먼저 V2 최종 투입비 예측을 실행해주세요.';return $empty;}$source=self::v2Rows($pdo,$context);if(count($source)===0)return $empty;$fingerprint=hash('sha256',self::encode(array('context'=>$context,'source'=>$source,'version'=>self::V2_VERSION)));$trigger=in_array(strtoupper((string)$triggerType),array('MANUAL','CLI','SYSTEM'),true)?strtoupper((string)$triggerType):'SYSTEM';$now=date('Y-m-d H:i:s');$uid=hash('sha256',uniqid('',true).mt_rand());
        try{$st=$pdo->prepare('INSERT INTO `'.self::V2_RUN_TABLE.'` (run_uid,analysis_date,target_ym,trigger_type,run_status,calculation_version,source_fingerprint,source_project_count,started_at,created_at) VALUES (:uid,:date,:ym,:trigger,\'RUNNING\',:version,:fingerprint,:count,:started,:created)');if(!$st||!$st->execute(array(':uid'=>$uid,':date'=>$context['analysis_date'],':ym'=>$context['target_ym'],':trigger'=>$trigger,':version'=>self::V2_VERSION,':fingerprint'=>$fingerprint,':count'=>count($source),':started'=>$now,':created'=>$now)))throw new Exception('run');$runId=(int)$pdo->lastInsertId();$projects=array();$failed=0;foreach($source as $row){$project=self::calculateProjectV2($row);$projects[]=$project;if(!self::saveV2Project($pdo,$runId,$context,$project))$failed++;}$aggregate=self::aggregateV2($projects);$st=$pdo->prepare('SELECT ceo_index_score FROM `'.self::V2_RESULT_TABLE.'` WHERE target_ym=:ym AND analysis_date<:date ORDER BY analysis_date DESC,id DESC LIMIT 1');$previous=null;if($st&&$st->execute(array(':ym'=>$context['target_ym'],':date'=>$context['analysis_date']))){$value=$st->fetchColumn();if($value!==false&&$value!==null)$previous=(float)$value;}if(!self::saveV2Company($pdo,$runId,$context,$fingerprint,$aggregate,$previous))throw new Exception('company');$status=$failed>0?'PARTIAL':'COMPLETED';$st=$pdo->prepare('UPDATE `'.self::V2_RUN_TABLE.'` SET run_status=:status,analyzable_project_count=:analyzable,ceo_index_score=:score,ceo_index_grade=:grade,coverage_rate=:coverage,finished_at=:finished,error_summary=:error WHERE id=:id');if($st)$st->execute(array(':status'=>$status,':analyzable'=>$aggregate['analyzable_project_count'],':score'=>$aggregate['ceo_index_score'],':grade'=>$aggregate['ceo_index_grade'],':coverage'=>$aggregate['coverage_rate'],':finished'=>date('Y-m-d H:i:s'),':error'=>$failed?'일부 현장 CEO Index V2 저장 실패: '.$failed.'건':null,':id'=>$runId));return array('ok'=>true,'status'=>$status,'projects'=>count($projects),'analyzable'=>$aggregate['analyzable_project_count'],'score'=>$aggregate['ceo_index_score'],'grade'=>$aggregate['ceo_index_grade'],'coverage'=>$aggregate['coverage_rate'],'message'=>'CEO Index V2 계산을 완료했습니다.');}catch(Exception $e){error_log('[AiCeoIndexV2] calculation failed');return $empty;}
    }

    public static function latestNormalV2($pdo=null,$targetYm='')
    {
        $pdo=self::pdo($pdo);
        if(!$pdo||!self::isV2Installed($pdo)||!self::tableExists($pdo,self::V2_FORECAST_TABLE)||!self::tableExists($pdo,self::V2_FORECAST_RUN_TABLE))return array();
        try{
            $where='';$params=array(':ceo_run_version'=>self::V2_VERSION,':ceo_result_version'=>self::V2_VERSION,':forecast_run_version'=>self::V2_VERSION,':forecast_result_version'=>self::V2_VERSION);
            if(preg_match('/^\d{4}-\d{2}$/',(string)$targetYm)){$where=' AND r.target_ym=:ym';$params[':ym']=$targetYm;}
            $sql='SELECT r.* FROM `'.self::V2_RESULT_TABLE.'` r INNER JOIN `'.self::V2_RUN_TABLE.'` cr ON cr.id=r.run_id AND cr.run_status=\'COMPLETED\' AND cr.calculation_version=:ceo_run_version WHERE r.calculation_version=:ceo_result_version'.$where.' AND EXISTS (SELECT 1 FROM `'.self::V2_FORECAST_TABLE.'` f INNER JOIN `'.self::V2_FORECAST_RUN_TABLE.'` fr ON fr.id=f.run_id AND fr.run_status=\'COMPLETED\' AND fr.calculation_version=:forecast_run_version WHERE f.analysis_date=r.analysis_date AND f.target_ym=r.target_ym AND f.calculation_version=:forecast_result_version LIMIT 1) ORDER BY r.analysis_date DESC,r.id DESC LIMIT 1';
            $st=$pdo->prepare($sql);if(!$st||!$st->execute($params))return array();$row=$st->fetch(PDO::FETCH_ASSOC);if(!is_array($row)||!isset($row['calculation_version'])||$row['calculation_version']!==self::V2_VERSION||!isset($row['run_id'])||(int)$row['run_id']<=0)return array();return $row;
        }catch(Exception $e){return array();}
    }

    public static function latestV2($pdo=null,$targetYm='')
    {
        return self::latestNormalV2($pdo,$targetYm);
    }

    public static function listV2Projects($pdo,$analysisDate,$targetYm,$page,$perPage,$runId=0)
    {
        $pdo=self::pdo($pdo);$page=max(1,(int)$page);$perPage=max(1,min(100,(int)$perPage));if(!$pdo||!self::isV2Installed($pdo))return array();try{$sql="SELECT * FROM `".self::V2_PROJECT_TABLE."` WHERE analysis_date=:date AND target_ym=:ym";if((int)$runId>0)$sql.=' AND run_id=:run_id';$sql.=" ORDER BY CASE project_index_grade WHEN 'CRITICAL' THEN 1 WHEN 'WARNING' THEN 2 WHEN 'WATCH' THEN 3 WHEN 'INSUFFICIENT' THEN 4 ELSE 5 END,final_forecast_amount DESC LIMIT :limit OFFSET :offset";$st=$pdo->prepare($sql);if(!$st)return array();$st->bindValue(':date',$analysisDate,PDO::PARAM_STR);$st->bindValue(':ym',$targetYm,PDO::PARAM_STR);if((int)$runId>0)$st->bindValue(':run_id',(int)$runId,PDO::PARAM_INT);$st->bindValue(':limit',$perPage,PDO::PARAM_INT);$st->bindValue(':offset',($page-1)*$perPage,PDO::PARAM_INT);if(!$st->execute())return array();$rows=$st->fetchAll(PDO::FETCH_ASSOC);return is_array($rows)?$rows:array();}catch(Exception $e){return array();}
    }
}
