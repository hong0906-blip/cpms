<?php
/**
 * Builds a privacy-minimized management summary from stored CPMS risk results,
 * requests a structured Korean briefing, validates it, and stores it separately.
 * PHP 5.6 / MySQL 5.6 compatible.
 */

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;
use Exception;
use PDO;

require_once __DIR__ . '/OpenAiResponsesClient.php';

class AiExecutiveBriefService
{
    const RUN_TABLE = 'cpms_ai_gpt_runs';
    const BRIEF_TABLE = 'cpms_ai_executive_briefs';
    const RISK_TABLE = 'cpms_ai_profit_risk_results';
    const TASK_TYPE = 'EXECUTIVE_BRIEF';
    const MAX_PROJECTS = 20;
    const MAX_INPUT_BYTES = 61440;

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

    public static function tableExists($pdo, $table)
    {
        $pdo = self::pdo($pdo);
        $table = trim((string)$table);
        if (!$pdo || !self::validIdentifier($table)) return false;
        $key = self::connectionKey($pdo) . ':' . $table;
        if (array_key_exists($key, self::$tableCache)) return self::$tableCache[$key];
        try {
            $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table_name');
            if (!$st || !$st->execute(array(':table_name' => $table))) return self::$tableCache[$key] = false;
            return self::$tableCache[$key] = ((int)$st->fetchColumn() > 0);
        } catch (Exception $e) {
            return self::$tableCache[$key] = false;
        }
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
            if (is_array($rows)) {
                foreach ($rows as $row) if (isset($row['Field'])) $columns[(string)$row['Field']] = true;
            }
        } catch (Exception $e) {
            $columns = array();
        }
        return self::$columnCache[$key] = $columns;
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
            if (is_array($rows)) {
                foreach ($rows as $row) if (isset($row['Key_name'])) $indexes[(string)$row['Key_name']] = true;
            }
        } catch (Exception $e) {
            $indexes = array();
        }
        return self::$indexCache[$key] = $indexes;
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
            'id','run_uid','task_type','analysis_date','target_ym','source_fingerprint','schema_version','trigger_type','run_status',
            'model_name','openai_response_id','http_status','input_token_count','output_token_count','total_token_count',
            'source_project_count','actor_employee_id','actor_name','started_at','finished_at','error_code','error_summary','created_at'
        );
    }

    public static function requiredRunIndexes()
    {
        return array('PRIMARY','uk_ai_gpt_run_uid','idx_ai_gpt_task_date','idx_ai_gpt_source','idx_ai_gpt_status');
    }

    public static function requiredBriefColumns()
    {
        return array(
            'id','run_id','analysis_date','target_ym','source_fingerprint','schema_version','model_name','source_project_count',
            'company_status','headline','executive_summary','top_risk_count','check_today_count','key_metrics_data','top_risks_data',
            'positive_signals_data','check_today_data','data_limitations_data','disclaimer','source_summary_data','raw_structured_output',
            'generated_at','created_at','updated_at'
        );
    }

    public static function requiredBriefIndexes()
    {
        return array('PRIMARY','uk_ai_executive_brief_source','idx_ai_executive_brief_date','idx_ai_executive_brief_status');
    }

    public static function createRunTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS cpms_ai_gpt_runs (\n"
            . "    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,\n"
            . "    run_uid VARCHAR(64) NOT NULL,\n"
            . "    task_type VARCHAR(40) NOT NULL,\n"
            . "    analysis_date DATE NOT NULL,\n"
            . "    target_ym CHAR(7) NULL,\n"
            . "    source_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,\n"
            . "    schema_version VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,\n"
            . "    trigger_type VARCHAR(20) NOT NULL,\n"
            . "    run_status VARCHAR(20) NOT NULL,\n"
            . "    model_name VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,\n"
            . "    openai_response_id VARCHAR(100) NULL,\n"
            . "    http_status INT NULL,\n"
            . "    input_token_count INT UNSIGNED NULL,\n"
            . "    output_token_count INT UNSIGNED NULL,\n"
            . "    total_token_count INT UNSIGNED NULL,\n"
            . "    source_project_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    actor_employee_id INT NULL,\n"
            . "    actor_name VARCHAR(100) NULL,\n"
            . "    started_at DATETIME NOT NULL,\n"
            . "    finished_at DATETIME NULL,\n"
            . "    error_code VARCHAR(100) NULL,\n"
            . "    error_summary VARCHAR(500) NULL,\n"
            . "    created_at DATETIME NOT NULL,\n"
            . "    UNIQUE KEY uk_ai_gpt_run_uid (run_uid),\n"
            . "    KEY idx_ai_gpt_task_date (task_type,analysis_date,started_at),\n"
            . "    KEY idx_ai_gpt_source (task_type,source_fingerprint),\n"
            . "    KEY idx_ai_gpt_status (run_status,started_at)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    public static function createBriefTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS cpms_ai_executive_briefs (\n"
            . "    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,\n"
            . "    run_id BIGINT UNSIGNED NULL,\n"
            . "    analysis_date DATE NOT NULL,\n"
            . "    target_ym CHAR(7) NOT NULL,\n"
            . "    source_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,\n"
            . "    schema_version VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,\n"
            . "    model_name VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,\n"
            . "    source_project_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    company_status VARCHAR(20) NOT NULL,\n"
            . "    headline VARCHAR(300) NOT NULL,\n"
            . "    executive_summary TEXT NOT NULL,\n"
            . "    top_risk_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    check_today_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    key_metrics_data MEDIUMTEXT NULL,\n"
            . "    top_risks_data MEDIUMTEXT NULL,\n"
            . "    positive_signals_data MEDIUMTEXT NULL,\n"
            . "    check_today_data MEDIUMTEXT NULL,\n"
            . "    data_limitations_data MEDIUMTEXT NULL,\n"
            . "    disclaimer VARCHAR(500) NULL,\n"
            . "    source_summary_data MEDIUMTEXT NULL,\n"
            . "    raw_structured_output MEDIUMTEXT NULL,\n"
            . "    generated_at DATETIME NOT NULL,\n"
            . "    created_at DATETIME NOT NULL,\n"
            . "    updated_at DATETIME NOT NULL,\n"
            . "    UNIQUE KEY uk_ai_executive_brief_source (source_fingerprint,schema_version,model_name),\n"
            . "    KEY idx_ai_executive_brief_date (analysis_date,target_ym),\n"
            . "    KEY idx_ai_executive_brief_status (company_status,analysis_date)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    private static function runColumnDefinitions()
    {
        return array(
            'run_uid'=>'VARCHAR(64) NOT NULL','task_type'=>'VARCHAR(40) NOT NULL','analysis_date'=>'DATE NOT NULL','target_ym'=>'CHAR(7) NULL',
            'source_fingerprint'=>'CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL','schema_version'=>'VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL','trigger_type'=>'VARCHAR(20) NOT NULL',
            'run_status'=>'VARCHAR(20) NOT NULL','model_name'=>'VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL','openai_response_id'=>'VARCHAR(100) NULL',
            'http_status'=>'INT NULL','input_token_count'=>'INT UNSIGNED NULL','output_token_count'=>'INT UNSIGNED NULL','total_token_count'=>'INT UNSIGNED NULL',
            'source_project_count'=>'INT UNSIGNED NOT NULL DEFAULT 0','actor_employee_id'=>'INT NULL','actor_name'=>'VARCHAR(100) NULL',
            'started_at'=>'DATETIME NOT NULL','finished_at'=>'DATETIME NULL','error_code'=>'VARCHAR(100) NULL','error_summary'=>'VARCHAR(500) NULL','created_at'=>'DATETIME NOT NULL'
        );
    }

    private static function briefColumnDefinitions()
    {
        return array(
            'run_id'=>'BIGINT UNSIGNED NULL','analysis_date'=>'DATE NOT NULL','target_ym'=>'CHAR(7) NOT NULL','source_fingerprint'=>'CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL',
            'schema_version'=>'VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL','model_name'=>'VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL','source_project_count'=>'INT UNSIGNED NOT NULL DEFAULT 0',
            'company_status'=>'VARCHAR(20) NOT NULL','headline'=>'VARCHAR(300) NOT NULL','executive_summary'=>'TEXT NOT NULL',
            'top_risk_count'=>'INT UNSIGNED NOT NULL DEFAULT 0','check_today_count'=>'INT UNSIGNED NOT NULL DEFAULT 0','key_metrics_data'=>'MEDIUMTEXT NULL',
            'top_risks_data'=>'MEDIUMTEXT NULL','positive_signals_data'=>'MEDIUMTEXT NULL','check_today_data'=>'MEDIUMTEXT NULL',
            'data_limitations_data'=>'MEDIUMTEXT NULL','disclaimer'=>'VARCHAR(500) NULL','source_summary_data'=>'MEDIUMTEXT NULL',
            'raw_structured_output'=>'MEDIUMTEXT NULL','generated_at'=>'DATETIME NOT NULL','created_at'=>'DATETIME NOT NULL','updated_at'=>'DATETIME NOT NULL'
        );
    }

    private static function ensureOwnedTable($pdo, $table, $columns, $indexes, &$updated)
    {
        if (!in_array($table, array(self::RUN_TABLE, self::BRIEF_TABLE), true)) throw new Exception('unsupported table');
        if (!self::columnExists($pdo, $table, 'id')) {
            if ($pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST') === false) throw new Exception('schema update failed');
            $updated[] = $table . '.column:id';
            self::clearSchemaCache($pdo);
        }
        foreach ($columns as $column => $definition) {
            if (!self::columnExists($pdo, $table, $column)) {
                if ($pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition) === false) throw new Exception('schema update failed');
                $updated[] = $table . '.column:' . $column;
                self::clearSchemaCache($pdo);
            }
        }
        $existing = self::getTableIndexes($pdo, $table);
        foreach ($indexes as $name => $definition) {
            if (!isset($existing[$name])) {
                if ($pdo->exec('ALTER TABLE `' . $table . '` ADD ' . $definition) === false) throw new Exception('schema update failed');
                $updated[] = $table . '.index:' . $name;
                self::clearSchemaCache($pdo);
                $existing[$name] = true;
            }
        }
    }

    public static function installOrUpdate($pdo = null)
    {
        $pdo = self::pdo($pdo);
        if (!$pdo) return array('ok'=>false,'message'=>'DB 연결 상태를 확인할 수 없습니다.','created'=>array(),'updated'=>array());
        $created = array();
        $updated = array();
        try {
            if (!self::tableExists($pdo, self::RUN_TABLE)) $created[] = self::RUN_TABLE;
            if (!self::tableExists($pdo, self::BRIEF_TABLE)) $created[] = self::BRIEF_TABLE;
            if ($pdo->exec(self::createRunTableSql()) === false) throw new Exception('run install failed');
            if ($pdo->exec(self::createBriefTableSql()) === false) throw new Exception('brief install failed');
            self::clearSchemaCache($pdo);
            $briefIndexes = self::getTableIndexes($pdo, self::BRIEF_TABLE);
            if (!isset($briefIndexes['uk_ai_executive_brief_source'])) {
                foreach (array(
                    'source_fingerprint'=>'CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL',
                    'schema_version'=>'VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL',
                    'model_name'=>'VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL'
                ) as $asciiColumn=>$asciiDefinition) {
                    if (self::columnExists($pdo,self::BRIEF_TABLE,$asciiColumn)) {
                        if ($pdo->exec('ALTER TABLE `' . self::BRIEF_TABLE . '` MODIFY COLUMN `' . $asciiColumn . '` ' . $asciiDefinition) === false) throw new Exception('brief key update failed');
                        $updated[] = self::BRIEF_TABLE . '.column:' . $asciiColumn;
                    }
                }
                self::clearSchemaCache($pdo);
            }
            self::ensureOwnedTable($pdo, self::RUN_TABLE, self::runColumnDefinitions(), array(
                'PRIMARY'=>'PRIMARY KEY (`id`)','uk_ai_gpt_run_uid'=>'UNIQUE KEY `uk_ai_gpt_run_uid` (`run_uid`)',
                'idx_ai_gpt_task_date'=>'KEY `idx_ai_gpt_task_date` (`task_type`,`analysis_date`,`started_at`)',
                'idx_ai_gpt_source'=>'KEY `idx_ai_gpt_source` (`task_type`,`source_fingerprint`)',
                'idx_ai_gpt_status'=>'KEY `idx_ai_gpt_status` (`run_status`,`started_at`)'
            ), $updated);
            self::ensureOwnedTable($pdo, self::BRIEF_TABLE, self::briefColumnDefinitions(), array(
                'PRIMARY'=>'PRIMARY KEY (`id`)','uk_ai_executive_brief_source'=>'UNIQUE KEY `uk_ai_executive_brief_source` (`source_fingerprint`,`schema_version`,`model_name`)',
                'idx_ai_executive_brief_date'=>'KEY `idx_ai_executive_brief_date` (`analysis_date`,`target_ym`)',
                'idx_ai_executive_brief_status'=>'KEY `idx_ai_executive_brief_status` (`company_status`,`analysis_date`)'
            ), $updated);
            self::clearSchemaCache($pdo);
            if (!self::isInstalled($pdo)) throw new Exception('schema incomplete');
            return array('ok'=>true,'message'=>count($created)>0?'OpenAI 경영 브리핑 전용 테이블을 설치했습니다.':'OpenAI 경영 브리핑 테이블 구조를 확인했습니다.','created'=>$created,'updated'=>$updated);
        } catch (Exception $e) {
            return array('ok'=>false,'message'=>'OpenAI 경영 브리핑 테이블 설치 또는 확인에 실패했습니다.','created'=>$created,'updated'=>$updated);
        }
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

    public static function isInstalled($pdo = null)
    {
        $pdo = self::pdo($pdo);
        if (!$pdo) return false;
        $key = self::connectionKey($pdo);
        if (array_key_exists($key, self::$installedCache)) return self::$installedCache[$key];
        $run = self::tableSchemaStatus($pdo, self::RUN_TABLE, self::requiredRunColumns(), self::requiredRunIndexes());
        $brief = self::tableSchemaStatus($pdo, self::BRIEF_TABLE, self::requiredBriefColumns(), self::requiredBriefIndexes());
        return self::$installedCache[$key] = (!empty($run['installed']) && !empty($brief['installed']));
    }

    public static function latestRiskContext($pdo = null)
    {
        $empty = array('available'=>false,'analysis_date'=>'','target_ym'=>'','project_count'=>0);
        $pdo = self::pdo($pdo);
        if (!$pdo || !self::tableExists($pdo, self::RISK_TABLE)) return $empty;
        foreach (array('id','analysis_date','target_ym','project_id') as $column) if (!self::columnExists($pdo, self::RISK_TABLE, $column)) return $empty;
        try {
            $st = $pdo->query('SELECT analysis_date,target_ym FROM `' . self::RISK_TABLE . '` ORDER BY analysis_date DESC,id DESC LIMIT 1');
            $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
            if (!is_array($row)) return $empty;
            $analysisDate = isset($row['analysis_date']) ? (string)$row['analysis_date'] : '';
            $targetYm = isset($row['target_ym']) ? (string)$row['target_ym'] : '';
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $analysisDate) || !preg_match('/^\d{4}-\d{2}$/', $targetYm)) return $empty;
            $countSt = $pdo->prepare('SELECT COUNT(*) FROM `' . self::RISK_TABLE . '` WHERE analysis_date=:analysis_date AND target_ym=:target_ym');
            if (!$countSt || !$countSt->execute(array(':analysis_date'=>$analysisDate, ':target_ym'=>$targetYm))) return $empty;
            $count = (int)$countSt->fetchColumn();
            return array('available'=>$count>0,'analysis_date'=>$analysisDate,'target_ym'=>$targetYm,'project_count'=>$count);
        } catch (Exception $e) {
            return $empty;
        }
    }

    public static function schemaStatus($pdo = null)
    {
        $pdo = self::pdo($pdo);
        $config = OpenAiResponsesClient::maskedConfigurationStatus();
        $result = array(
            'db_available'=>(bool)$pdo,'curl_available'=>!empty($config['curl_available']),'api_key_configured'=>!empty($config['available']),
            'api_key_source'=>isset($config['source'])?$config['source']:'NONE','model'=>isset($config['model'])?$config['model']:OpenAiResponsesClient::DEFAULT_MODEL,
            'qa_model'=>isset($config['qa_model'])?$config['qa_model']:OpenAiResponsesClient::DEFAULT_MODEL,
            'reasoning_effort'=>isset($config['reasoning_effort'])?$config['reasoning_effort']:OpenAiResponsesClient::DEFAULT_REASONING_EFFORT,
            'qa_reasoning_effort'=>isset($config['qa_reasoning_effort'])?$config['qa_reasoning_effort']:OpenAiResponsesClient::DEFAULT_REASONING_EFFORT,
            'max_output_tokens'=>isset($config['max_output_tokens'])?(int)$config['max_output_tokens']:1800,
            'qa_max_output_tokens'=>isset($config['qa_max_output_tokens'])?(int)$config['qa_max_output_tokens']:1400,
            'timeout_seconds'=>isset($config['timeout_seconds'])?(int)$config['timeout_seconds']:60,
            'connect_timeout_seconds'=>isset($config['connect_timeout_seconds'])?(int)$config['connect_timeout_seconds']:10,
            'schema_version'=>isset($config['schema_version'])?$config['schema_version']:OpenAiResponsesClient::DEFAULT_SCHEMA_VERSION,
            'run'=>array('table_exists'=>false,'installed'=>false,'missing_columns'=>array(),'missing_indexes'=>array()),
            'brief'=>array('table_exists'=>false,'installed'=>false,'missing_columns'=>array(),'missing_indexes'=>array()),
            'installed'=>false,'latest_risk'=>array('available'=>false,'analysis_date'=>'','target_ym'=>'','project_count'=>0),
            'completed_count'=>0,'failed_count'=>0,'cached_count'=>0,'latest_run'=>array(),'brief_count'=>0,'brief_project_count'=>0,'latest_brief'=>array()
        );
        if (!$pdo) return $result;
        try {
            $result['latest_risk'] = self::latestRiskContext($pdo);
            $result['run'] = self::tableSchemaStatus($pdo, self::RUN_TABLE, self::requiredRunColumns(), self::requiredRunIndexes());
            $result['brief'] = self::tableSchemaStatus($pdo, self::BRIEF_TABLE, self::requiredBriefColumns(), self::requiredBriefIndexes());
            $result['installed'] = !empty($result['run']['installed']) && !empty($result['brief']['installed']);
            if (!empty($result['run']['installed'])) {
                $st = $pdo->query("SELECT COALESCE(SUM(CASE WHEN run_status='COMPLETED' THEN 1 ELSE 0 END),0) AS completed_count,COALESCE(SUM(CASE WHEN run_status='FAILED' OR run_status='REFUSED' THEN 1 ELSE 0 END),0) AS failed_count,COALESCE(SUM(CASE WHEN run_status='CACHED' THEN 1 ELSE 0 END),0) AS cached_count FROM `" . self::RUN_TABLE . "` WHERE task_type='EXECUTIVE_BRIEF'");
                $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
                if (is_array($row)) {
                    $result['completed_count'] = isset($row['completed_count']) ? (int)$row['completed_count'] : 0;
                    $result['failed_count'] = isset($row['failed_count']) ? (int)$row['failed_count'] : 0;
                    $result['cached_count'] = isset($row['cached_count']) ? (int)$row['cached_count'] : 0;
                }
                $st = $pdo->query("SELECT id,analysis_date,target_ym,run_status,model_name,http_status,input_token_count,output_token_count,total_token_count,started_at,finished_at,error_code,error_summary FROM `" . self::RUN_TABLE . "` WHERE task_type='EXECUTIVE_BRIEF' ORDER BY started_at DESC,id DESC LIMIT 1");
                $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
                $result['latest_run'] = is_array($row) ? $row : array();
            }
            if (!empty($result['brief']['installed'])) {
                $st = $pdo->query('SELECT COUNT(*) AS brief_count,COALESCE(SUM(source_project_count),0) AS project_count FROM `' . self::BRIEF_TABLE . '`');
                $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
                if (is_array($row)) {
                    $result['brief_count'] = isset($row['brief_count']) ? (int)$row['brief_count'] : 0;
                    $result['brief_project_count'] = isset($row['project_count']) ? (int)$row['project_count'] : 0;
                }
                $result['latest_brief'] = self::latestBrief($pdo);
            }
        } catch (Exception $e) {
        }
        return $result;
    }

    private static function decodeArray($value)
    {
        if (!is_string($value) || trim($value) === '') return array();
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : array();
    }

    public static function decodeData($value)
    {
        return self::decodeArray($value);
    }

    private static function shortText($value, $length)
    {
        $value = trim((string)$value);
        if (function_exists('mb_substr')) return mb_substr($value, 0, $length, 'UTF-8');
        return substr($value, 0, $length);
    }

    private static function redactText($value, $length)
    {
        $value = self::shortText(strip_tags((string)$value), $length);
        $patterns = array(
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu',
            '/\b01[016789][\s.\-]?\d{3,4}[\s.\-]?\d{4}\b/u',
            '/\b0\d{1,2}[\s.\-]?\d{3,4}[\s.\-]?\d{4}\b/u',
            '/\b\d{6}[\s\-]?[1-4]\d{6}\b/u',
            '/\bBearer\s+[A-Za-z0-9._~+\/\-]+=*/iu',
            '/\bsk-[A-Za-z0-9_\-]{12,}\b/u'
        );
        foreach ($patterns as $pattern) {
            $replaced = preg_replace($pattern, '[개인정보 제외]', $value);
            if (is_string($replaced)) $value = $replaced;
        }
        return trim($value);
    }

    private static function compactMessages($value, $maxItems, $maxLength)
    {
        $decoded = is_array($value) ? $value : self::decodeArray($value);
        $result = array();
        foreach ($decoded as $item) {
            $text = '';
            if (is_string($item) || is_numeric($item)) $text = (string)$item;
            else if (is_array($item)) {
                foreach (array('message','recommended_action','title','label','summary') as $key) {
                    if (isset($item[$key]) && (is_string($item[$key]) || is_numeric($item[$key]))) { $text = (string)$item[$key]; break; }
                }
            }
            $text = self::redactText($text, $maxLength);
            if ($text !== '' && !in_array($text, $result, true)) $result[] = $text;
            if (count($result) >= $maxItems) break;
        }
        return $result;
    }

    private static function metric($id, $label, $value, $unit)
    {
        return array('metric_id'=>$id,'label'=>$label,'value'=>$value,'unit'=>$unit);
    }

    public static function buildSourceData($pdo = null)
    {
        $pdo = self::pdo($pdo);
        $context = self::latestRiskContext($pdo);
        $empty = array('ok'=>false,'message'=>'최신 적자·원가율 위험분석 결과가 없습니다.','analysis_date'=>'','target_ym'=>'','project_count'=>0,'source_data'=>array(),'input_bytes'=>0);
        if (!$pdo) { $empty['message'] = 'DB 연결 상태를 확인할 수 없습니다.'; return $empty; }
        if (empty($context['available'])) return $empty;
        $params = array(':analysis_date'=>$context['analysis_date'], ':target_ym'=>$context['target_ym']);
        try {
            $aggregateSql = "SELECT COUNT(*) AS project_count,"
                . "COALESCE(SUM(CASE WHEN risk_grade='NORMAL' THEN 1 ELSE 0 END),0) AS normal_count,"
                . "COALESCE(SUM(CASE WHEN risk_grade='WATCH' THEN 1 ELSE 0 END),0) AS watch_count,"
                . "COALESCE(SUM(CASE WHEN risk_grade='WARNING' THEN 1 ELSE 0 END),0) AS warning_count,"
                . "COALESCE(SUM(CASE WHEN risk_grade='CRITICAL' THEN 1 ELSE 0 END),0) AS critical_count,"
                . "COALESCE(SUM(CASE WHEN risk_grade='INSUFFICIENT' THEN 1 ELSE 0 END),0) AS insufficient_count,"
                . "COALESCE(SUM(monthly_sales_amount),0) AS monthly_sales_total,COALESCE(SUM(monthly_forecast_input_amount),0) AS monthly_forecast_input_total,"
                . "COALESCE(SUM(monthly_forecast_profit_amount),0) AS monthly_forecast_profit_total,COALESCE(SUM(cumulative_projected_profit_amount),0) AS cumulative_projected_profit_total "
                . "FROM `" . self::RISK_TABLE . "` WHERE analysis_date=:analysis_date AND target_ym=:target_ym";
            $st = $pdo->prepare($aggregateSql);
            if (!$st || !$st->execute($params)) return $empty;
            $company = $st->fetch(PDO::FETCH_ASSOC);
            if (!is_array($company) || (int)$company['project_count'] <= 0) { $empty['message']='분석된 현장이 없습니다.'; return $empty; }

            $detailSql = 'SELECT project_id,project_name_snapshot,project_status_snapshot,monthly_sales_amount,monthly_forecast_input_amount,'
                . 'monthly_forecast_profit_amount,monthly_forecast_cost_rate,cumulative_projected_profit_amount,cumulative_projected_cost_rate,'
                . 'contract_input_utilization_rate,reliability_score,reliability_grade,anomaly_score,anomaly_grade,risk_score,risk_grade,'
                . 'confidence_level,sales_basis,primary_risk_type,risk_factor_data,recommendation_data FROM `' . self::RISK_TABLE . '` '
                . 'WHERE analysis_date=:analysis_date AND target_ym=:target_ym '
                . "ORDER BY CASE risk_grade WHEN 'CRITICAL' THEN 1 WHEN 'WARNING' THEN 2 WHEN 'WATCH' THEN 3 WHEN 'INSUFFICIENT' THEN 4 ELSE 5 END,"
                . 'risk_score DESC,CASE WHEN monthly_forecast_profit_amount<0 THEN ABS(monthly_forecast_profit_amount) ELSE 0 END DESC,project_id ASC LIMIT 20';
            $detailSt = $pdo->prepare($detailSql);
            if (!$detailSt || !$detailSt->execute($params)) return $empty;
            $rows = $detailSt->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows)) $rows = array();

            $companyMetrics = array(
                self::metric('company.project_count','분석 현장 수',(int)$company['project_count'],'개'),
                self::metric('company.normal_count','안정 현장 수',(int)$company['normal_count'],'개'),
                self::metric('company.watch_count','관심 현장 수',(int)$company['watch_count'],'개'),
                self::metric('company.warning_count','주의 현장 수',(int)$company['warning_count'],'개'),
                self::metric('company.critical_count','적자 위험 현장 수',(int)$company['critical_count'],'개'),
                self::metric('company.insufficient_count','판단자료 부족 현장 수',(int)$company['insufficient_count'],'개'),
                self::metric('company.monthly_sales_total','회사 월 예상매출 합계',(float)$company['monthly_sales_total'],'원'),
                self::metric('company.monthly_forecast_input_total','회사 월 예상투입비 합계',(float)$company['monthly_forecast_input_total'],'원'),
                self::metric('company.monthly_forecast_profit_total','회사 월 예상손익 합계',(float)$company['monthly_forecast_profit_total'],'원'),
                self::metric('company.cumulative_projected_profit_total','회사 누적 예상손익 합계',(float)$company['cumulative_projected_profit_total'],'원')
            );
            $projects = array();
            foreach ($rows as $row) {
                $id = isset($row['project_id']) ? (int)$row['project_id'] : 0;
                if ($id <= 0) continue;
                $prefix = 'project.' . $id . '.';
                $projects[] = array(
                    'project_id'=>$id,
                    'project_name'=>self::redactText(isset($row['project_name_snapshot'])?$row['project_name_snapshot']:'',190),
                    'project_status'=>self::redactText(isset($row['project_status_snapshot'])?$row['project_status_snapshot']:'',50),
                    'monthly_sales_amount'=>isset($row['monthly_sales_amount'])?(float)$row['monthly_sales_amount']:0.0,
                    'monthly_forecast_input_amount'=>isset($row['monthly_forecast_input_amount'])?(float)$row['monthly_forecast_input_amount']:0.0,
                    'monthly_forecast_profit_amount'=>isset($row['monthly_forecast_profit_amount'])&&$row['monthly_forecast_profit_amount']!==null?(float)$row['monthly_forecast_profit_amount']:null,
                    'monthly_forecast_cost_rate'=>isset($row['monthly_forecast_cost_rate'])&&$row['monthly_forecast_cost_rate']!==null?(float)$row['monthly_forecast_cost_rate']:null,
                    'cumulative_projected_profit_amount'=>isset($row['cumulative_projected_profit_amount'])&&$row['cumulative_projected_profit_amount']!==null?(float)$row['cumulative_projected_profit_amount']:null,
                    'cumulative_projected_cost_rate'=>isset($row['cumulative_projected_cost_rate'])&&$row['cumulative_projected_cost_rate']!==null?(float)$row['cumulative_projected_cost_rate']:null,
                    'contract_input_utilization_rate'=>isset($row['contract_input_utilization_rate'])&&$row['contract_input_utilization_rate']!==null?(float)$row['contract_input_utilization_rate']:null,
                    'reliability_score'=>isset($row['reliability_score'])&&$row['reliability_score']!==null?(float)$row['reliability_score']:null,
                    'reliability_grade'=>self::redactText(isset($row['reliability_grade'])?$row['reliability_grade']:'',20),
                    'anomaly_score'=>isset($row['anomaly_score'])&&$row['anomaly_score']!==null?(float)$row['anomaly_score']:null,
                    'anomaly_grade'=>self::redactText(isset($row['anomaly_grade'])?$row['anomaly_grade']:'',20),
                    'risk_score'=>isset($row['risk_score'])&&$row['risk_score']!==null?(float)$row['risk_score']:null,
                    'risk_grade'=>self::redactText(isset($row['risk_grade'])?$row['risk_grade']:'INSUFFICIENT',20),
                    'confidence_level'=>self::redactText(isset($row['confidence_level'])?$row['confidence_level']:'LOW',20),
                    'sales_basis'=>self::redactText(isset($row['sales_basis'])?$row['sales_basis']:'MISSING',30),
                    'primary_risk_type'=>self::redactText(isset($row['primary_risk_type'])?$row['primary_risk_type']:'',50),
                    'risk_factors'=>self::compactMessages(isset($row['risk_factor_data'])?$row['risk_factor_data']:'',3,160),
                    'recommendations'=>self::compactMessages(isset($row['recommendation_data'])?$row['recommendation_data']:'',3,160),
                    'evidence_ids'=>array(
                        $prefix . 'monthly_sales_amount',$prefix . 'monthly_forecast_input_amount',$prefix . 'monthly_forecast_profit_amount',
                        $prefix . 'monthly_forecast_cost_rate',$prefix . 'cumulative_projected_profit_amount',$prefix . 'cumulative_projected_cost_rate',
                        $prefix . 'contract_input_utilization_rate',$prefix . 'reliability_score',$prefix . 'reliability_grade',
                        $prefix . 'anomaly_score',$prefix . 'anomaly_grade',$prefix . 'risk_score',$prefix . 'risk_grade',
                        $prefix . 'confidence_level',$prefix . 'sales_basis',$prefix . 'primary_risk_type'
                    )
                );
            }
            $source = array(
                'analysis_date'=>$context['analysis_date'],'target_ym'=>$context['target_ym'],
                'company_metrics'=>$companyMetrics,
                'grade_summary'=>array('NORMAL'=>(int)$company['normal_count'],'WATCH'=>(int)$company['watch_count'],'WARNING'=>(int)$company['warning_count'],'CRITICAL'=>(int)$company['critical_count'],'INSUFFICIENT'=>(int)$company['insufficient_count']),
                'detailed_project_count'=>count($projects),'omitted_project_count'=>max(0,(int)$company['project_count']-count($projects)),
                'projects'=>$projects,
                'data_notice'=>'모든 금액과 비율은 CPMS PHP 계산결과이며 GPT는 재계산하지 않습니다.'
            );
            $json = self::encodeData($source);
            while (is_string($json) && strlen($json) > self::MAX_INPUT_BYTES && count($source['projects']) > 1) {
                array_pop($source['projects']);
                $source['detailed_project_count'] = count($source['projects']);
                $source['omitted_project_count'] = max(0,(int)$company['project_count']-count($source['projects']));
                $json = self::encodeData($source);
            }
            if (!is_string($json)) { $empty['message']='OpenAI 요청자료를 준비하지 못했습니다.'; return $empty; }
            return array('ok'=>true,'message'=>'브리핑 입력자료를 준비했습니다.','analysis_date'=>$context['analysis_date'],'target_ym'=>$context['target_ym'],'project_count'=>(int)$company['project_count'],'source_data'=>$source,'input_bytes'=>strlen($json));
        } catch (Exception $e) {
            return $empty;
        }
    }

    private static function isListArray($value)
    {
        if (!is_array($value)) return false;
        $expected = 0;
        foreach (array_keys($value) as $key) {
            if ($key !== $expected) return false;
            $expected++;
        }
        return true;
    }

    public static function canonicalize($value)
    {
        if (!is_array($value)) return $value;
        if (self::isListArray($value)) {
            $result = array();
            foreach ($value as $item) $result[] = self::canonicalize($item);
            return $result;
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = self::canonicalize($item);
        return $value;
    }

    private static function encodeData($value)
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : null;
    }

    public static function sourceFingerprint($sourceData, $analysisDate, $targetYm, $schemaVersion, $modelName)
    {
        $canonical = self::canonicalize(array(
            'schema_version'=>(string)$schemaVersion,'model_name'=>(string)$modelName,'target_ym'=>(string)$targetYm,
            'analysis_date'=>(string)$analysisDate,'source_data'=>is_array($sourceData)?$sourceData:array()
        ));
        $json = self::encodeData($canonical);
        return is_string($json) ? hash('sha256', $json) : '';
    }

    public static function structuredSchema()
    {
        $stringArray = array('type'=>'array','items'=>array('type'=>'string'));
        return array(
            'type'=>'object','additionalProperties'=>false,
            'required'=>array('company_status','headline','executive_summary','key_metrics','top_risks','positive_signals','check_today','data_limitations','disclaimer'),
            'properties'=>array(
                'company_status'=>array('type'=>'string','enum'=>array('NORMAL','WATCH','WARNING','CRITICAL','INSUFFICIENT')),
                'headline'=>array('type'=>'string'),
                'executive_summary'=>array('type'=>'string'),
                'key_metrics'=>array('type'=>'array','items'=>array('type'=>'object','additionalProperties'=>false,'required'=>array('metric_id','label','interpretation'),'properties'=>array('metric_id'=>array('type'=>'string'),'label'=>array('type'=>'string'),'interpretation'=>array('type'=>'string')))),
                'top_risks'=>array('type'=>'array','maxItems'=>5,'items'=>array('type'=>'object','additionalProperties'=>false,'required'=>array('project_id','project_name','severity','risk_type','title','explanation','evidence_ids','recommended_actions'),'properties'=>array(
                    'project_id'=>array('type'=>'integer'),'project_name'=>array('type'=>'string'),'severity'=>array('type'=>'string','enum'=>array('WATCH','WARNING','CRITICAL','INSUFFICIENT')),
                    'risk_type'=>array('type'=>'string'),'title'=>array('type'=>'string'),'explanation'=>array('type'=>'string'),'evidence_ids'=>$stringArray,
                    'recommended_actions'=>array('type'=>'array','maxItems'=>3,'items'=>array('type'=>'string'))
                ))),
                'positive_signals'=>array('type'=>'array','maxItems'=>5,'items'=>array('type'=>'string')),
                'check_today'=>array('type'=>'array','maxItems'=>7,'items'=>array('type'=>'string')),
                'data_limitations'=>array('type'=>'array','maxItems'=>7,'items'=>array('type'=>'string')),
                'disclaimer'=>array('type'=>'string')
            )
        );
    }

    public static function instructions()
    {
        return "당신은 건설회사 대표에게 CPMS 경영예측 결과를 설명하는 경영관리 보조자입니다.\n"
            . "반드시 제공된 JSON 자료만 사용하고 지정된 JSON Schema 형식으로 한국어 응답을 작성하세요.\n"
            . "숫자를 새로 계산하지 말고 제공되지 않은 숫자, 현장, 원인을 만들지 마세요. 확정매출과 예상매출을 구분하세요.\n"
            . "입력 신뢰도가 낮거나 판단자료가 부족하면 단정하지 말고 그 한계를 명시하세요. 적자나 손실이 확정됐다고 표현하지 마세요.\n"
            . "직원이나 현장 책임자를 비난하지 말고 문제 직원, 태만, 조작, 횡령 등의 표현을 사용하지 마세요. 회계감사나 법률판단처럼 표현하지 마세요.\n"
            . "대표가 바로 확인할 수 있는 행동을 간결하게 제안하고 같은 내용을 반복하지 마세요. 어려운 통계용어는 피하세요.\n"
            . "evidence_ids에는 입력자료에 존재하는 ID만 사용하고 project_id와 project_name은 입력자료 그대로 사용하세요.\n"
            . "모든 결과가 CPMS 입력자료와 통계 예측을 설명한 관리 참고자료임을 disclaimer에 포함하세요.";
    }

    public static function buildRequestPayload($sourceData)
    {
        $inputJson = self::encodeData($sourceData);
        if (!is_string($inputJson)) return array();
        return array(
            'model'=>OpenAiResponsesClient::model(),'store'=>false,'instructions'=>self::instructions(),
            'input'=>array(array('role'=>'user','content'=>array(array('type'=>'input_text','text'=>$inputJson)))),
            'max_output_tokens'=>OpenAiResponsesClient::maxOutputTokens(),
            'reasoning'=>array('effort'=>OpenAiResponsesClient::reasoningEffort()),
            'text'=>array('format'=>array('type'=>'json_schema','name'=>'cpms_executive_brief','description'=>'CPMS 경영예측 결과를 설명하는 대표용 브리핑','strict'=>true,'schema'=>self::structuredSchema()))
        );
    }

    private static function collectValidationContext($sourceData)
    {
        $context = array('projects'=>array(),'evidence'=>array(),'company_evidence'=>array());
        if (isset($sourceData['company_metrics']) && is_array($sourceData['company_metrics'])) {
            foreach ($sourceData['company_metrics'] as $metric) if (is_array($metric) && isset($metric['metric_id'])) {
                $context['evidence'][(string)$metric['metric_id']] = true;
                $context['company_evidence'][(string)$metric['metric_id']] = true;
            }
        }
        if (isset($sourceData['projects']) && is_array($sourceData['projects'])) {
            foreach ($sourceData['projects'] as $project) {
                if (!is_array($project) || !isset($project['project_id'])) continue;
                $id = (int)$project['project_id'];
                $context['projects'][$id] = isset($project['project_name']) ? (string)$project['project_name'] : '';
                if (isset($project['evidence_ids']) && is_array($project['evidence_ids'])) foreach ($project['evidence_ids'] as $evidence) $context['evidence'][(string)$evidence] = true;
            }
        }
        return $context;
    }

    private static function validateKeys($value, $required, $allowed)
    {
        if (!is_array($value)) return false;
        foreach ($required as $key) if (!array_key_exists($key, $value)) return false;
        foreach (array_keys($value) as $key) if (!in_array($key, $allowed, true)) return false;
        return true;
    }

    private static function containsUnsafeText($value)
    {
        $text = is_string($value) ? $value : self::encodeData($value);
        if (!is_string($text)) return true;
        foreach (array('적자 확정','손실 확정','망한 현장','부실 현장','문제 직원','업무태만','조작','횡령','범죄 의심','책임자 문책','해고','처벌') as $phrase) {
            if (strpos($text, $phrase) !== false) return true;
        }
        $patterns = array(
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu',
            '/\b01[016789][\s.\-]?\d{3,4}[\s.\-]?\d{4}\b/u',
            '/\b0\d{1,2}[\s.\-]?\d{3,4}[\s.\-]?\d{4}\b/u',
            '/\b\d{6}[\s\-]?[1-4]\d{6}\b/u'
        );
        foreach ($patterns as $pattern) if (preg_match($pattern, $text)) return true;
        return false;
    }

    private static function normalizedNumbers($value)
    {
        $text=is_string($value)?$value:self::encodeData($value);
        if(!is_string($text)) return array();
        $previous='';
        while($previous!==$text) {
            $previous=$text;
            $replaced=preg_replace('/(-?\d+),(\d{3})(?=\D|$)/u','$1$2',$text);
            if(is_string($replaced)) $text=$replaced; else break;
        }
        preg_match_all('/-?\d+(?:\.\d+)?/u',$text,$matches);
        $result=array();
        foreach(isset($matches[0])?$matches[0]:array() as $number) {
            $negative=substr($number,0,1)==='-';
            if($negative) $number=substr($number,1);
            $parts=explode('.',$number,2);
            $integer=ltrim($parts[0],'0');
            if($integer==='') $integer='0';
            $fraction=isset($parts[1])?rtrim($parts[1],'0'):'';
            $normalized=$integer . ($fraction!==''?'.'.$fraction:'');
            if($negative && $normalized!=='0') $normalized='-'.$normalized;
            $result[$normalized]=true;
        }
        return $result;
    }

    private static function containsUnprovidedNumber($data, $sourceData)
    {
        $allowed=self::normalizedNumbers($sourceData);
        $used=self::normalizedNumbers($data);
        foreach($used as $number=>$unused) if(!isset($allowed[$number])) return true;
        return false;
    }

    private static function validateStringArray($value, $maxItems, $maxLength)
    {
        if (!is_array($value) || count($value) > $maxItems) return false;
        foreach ($value as $item) if (!is_string($item) || self::textLength($item) > $maxLength) return false;
        return true;
    }

    private static function textLength($value)
    {
        return function_exists('mb_strlen') ? mb_strlen((string)$value, 'UTF-8') : strlen((string)$value);
    }

    public static function validateStructuredOutput($data, $sourceData)
    {
        $required = array('company_status','headline','executive_summary','key_metrics','top_risks','positive_signals','check_today','data_limitations','disclaimer');
        if (!self::validateKeys($data, $required, $required)) return array('ok'=>false,'message'=>'OpenAI 응답 형식을 확인하지 못했습니다.');
        if (!in_array($data['company_status'], array('NORMAL','WATCH','WARNING','CRITICAL','INSUFFICIENT'), true)) return array('ok'=>false,'message'=>'OpenAI 응답 형식을 확인하지 못했습니다.');
        if (!is_string($data['headline']) || self::textLength($data['headline']) > 300 || !is_string($data['executive_summary']) || self::textLength($data['executive_summary']) > 2000 || !is_string($data['disclaimer']) || self::textLength($data['disclaimer']) > 500) return array('ok'=>false,'message'=>'OpenAI 응답 형식을 확인하지 못했습니다.');
        if (!self::validateStringArray($data['positive_signals'],5,500) || !self::validateStringArray($data['check_today'],7,500) || !self::validateStringArray($data['data_limitations'],7,500)) return array('ok'=>false,'message'=>'OpenAI 응답 형식을 확인하지 못했습니다.');
        $context = self::collectValidationContext($sourceData);
        if (!is_array($data['key_metrics']) || count($data['key_metrics']) > 20) return array('ok'=>false,'message'=>'OpenAI 응답 형식을 확인하지 못했습니다.');
        foreach ($data['key_metrics'] as $metric) {
            $keys = array('metric_id','label','interpretation');
            if (!self::validateKeys($metric,$keys,$keys) || !is_string($metric['metric_id']) || !isset($context['company_evidence'][$metric['metric_id']]) || !is_string($metric['label']) || !is_string($metric['interpretation']) || self::textLength($metric['label'])>120 || self::textLength($metric['interpretation'])>500) return array('ok'=>false,'message'=>'OpenAI 응답 형식을 확인하지 못했습니다.');
        }
        if (!is_array($data['top_risks']) || count($data['top_risks']) > 5) return array('ok'=>false,'message'=>'OpenAI 응답 형식을 확인하지 못했습니다.');
        foreach ($data['top_risks'] as $risk) {
            $keys = array('project_id','project_name','severity','risk_type','title','explanation','evidence_ids','recommended_actions');
            if (!self::validateKeys($risk,$keys,$keys) || !is_int($risk['project_id']) || !isset($context['projects'][$risk['project_id']]) || !is_string($risk['project_name']) || $context['projects'][$risk['project_id']] !== $risk['project_name']) return array('ok'=>false,'message'=>'OpenAI 응답 형식을 확인하지 못했습니다.');
            if (!in_array($risk['severity'],array('WATCH','WARNING','CRITICAL','INSUFFICIENT'),true) || !is_string($risk['risk_type']) || !is_string($risk['title']) || !is_string($risk['explanation'])) return array('ok'=>false,'message'=>'OpenAI 응답 형식을 확인하지 못했습니다.');
            if (self::textLength($risk['risk_type'])>100 || self::textLength($risk['title'])>300 || self::textLength($risk['explanation'])>1500) return array('ok'=>false,'message'=>'OpenAI 응답 형식을 확인하지 못했습니다.');
            if (!is_array($risk['evidence_ids']) || count($risk['evidence_ids'])>20) return array('ok'=>false,'message'=>'OpenAI 응답 형식을 확인하지 못했습니다.');
            foreach ($risk['evidence_ids'] as $evidence) if (!is_string($evidence) || !isset($context['evidence'][$evidence])) return array('ok'=>false,'message'=>'OpenAI 응답 형식을 확인하지 못했습니다.');
            if (!self::validateStringArray($risk['recommended_actions'],3,500)) return array('ok'=>false,'message'=>'OpenAI 응답 형식을 확인하지 못했습니다.');
        }
        if (self::containsUnsafeText($data)) return array('ok'=>false,'message'=>'OpenAI 응답에 저장할 수 없는 표현 또는 개인정보 형식이 포함되어 있습니다.');
        if (self::containsUnprovidedNumber($data,$sourceData)) return array('ok'=>false,'message'=>'OpenAI 응답에 원본자료에서 확인되지 않은 숫자가 포함되어 있습니다.');
        return array('ok'=>true,'message'=>'OpenAI 응답 형식을 확인했습니다.','data'=>$data);
    }

    private static function normalizeTrigger($value)
    {
        $value = strtoupper(trim((string)$value));
        return in_array($value,array('MANUAL','CLI','SYSTEM'),true) ? $value : 'SYSTEM';
    }

    private static function now()
    {
        try { $date = new \DateTime('now', new \DateTimeZone('Asia/Seoul')); return $date->format('Y-m-d H:i:s'); }
        catch (Exception $e) { return date('Y-m-d H:i:s'); }
    }

    private static function generateUid()
    {
        $bytes = function_exists('openssl_random_pseudo_bytes') ? @openssl_random_pseudo_bytes(32) : false;
        if (!is_string($bytes) || strlen($bytes)<16) $bytes = uniqid((string)mt_rand(),true) . microtime(true);
        return hash('sha256',$bytes);
    }

    private static function resolveActor($trigger)
    {
        if ($trigger !== 'MANUAL') return array('id'=>null,'name'=>'');
        $user = Auth::user();
        $id = is_array($user) && isset($user['id']) && is_numeric($user['id']) ? (int)$user['id'] : 0;
        $name = self::redactText(Auth::userName(),100);
        return array('id'=>$id>0?$id:null,'name'=>$name);
    }

    private static function createRun($pdo, $source, $fingerprint, $trigger, $status)
    {
        $actor = self::resolveActor($trigger);
        $now = self::now();
        $sql = 'INSERT INTO `' . self::RUN_TABLE . '` (run_uid,task_type,analysis_date,target_ym,source_fingerprint,schema_version,trigger_type,run_status,model_name,source_project_count,actor_employee_id,actor_name,started_at,created_at) '
            . 'VALUES (:run_uid,:task_type,:analysis_date,:target_ym,:source_fingerprint,:schema_version,:trigger_type,:run_status,:model_name,:source_project_count,:actor_employee_id,:actor_name,:started_at,:created_at)';
        $st = $pdo->prepare($sql);
        if (!$st) return 0;
        $ok = $st->execute(array(
            ':run_uid'=>self::generateUid(),':task_type'=>self::TASK_TYPE,':analysis_date'=>$source['analysis_date'],':target_ym'=>$source['target_ym'],
            ':source_fingerprint'=>$fingerprint,':schema_version'=>OpenAiResponsesClient::schemaVersion(),':trigger_type'=>$trigger,':run_status'=>$status,
            ':model_name'=>OpenAiResponsesClient::model(),':source_project_count'=>(int)$source['project_count'],':actor_employee_id'=>$actor['id'],
            ':actor_name'=>$actor['name']!==''?$actor['name']:null,':started_at'=>$now,':created_at'=>$now
        ));
        return $ok ? (int)$pdo->lastInsertId() : 0;
    }

    private static function finishRun($pdo, $runId, $status, $apiResult, $errorCode, $message)
    {
        if ((int)$runId<=0) return false;
        $usage = isset($apiResult['usage'])&&is_array($apiResult['usage'])?$apiResult['usage']:array();
        $st = $pdo->prepare('UPDATE `' . self::RUN_TABLE . '` SET run_status=:run_status,openai_response_id=:response_id,http_status=:http_status,input_token_count=:input_tokens,output_token_count=:output_tokens,total_token_count=:total_tokens,finished_at=:finished_at,error_code=:error_code,error_summary=:error_summary WHERE id=:id');
        if (!$st) return false;
        return $st->execute(array(
            ':run_status'=>$status,':response_id'=>!empty($apiResult['response_id'])?self::shortText($apiResult['response_id'],100):null,
            ':http_status'=>isset($apiResult['http_status'])&&$apiResult['http_status']!==null?(int)$apiResult['http_status']:null,
            ':input_tokens'=>isset($usage['input_tokens'])&&$usage['input_tokens']!==null?(int)$usage['input_tokens']:null,
            ':output_tokens'=>isset($usage['output_tokens'])&&$usage['output_tokens']!==null?(int)$usage['output_tokens']:null,
            ':total_tokens'=>isset($usage['total_tokens'])&&$usage['total_tokens']!==null?(int)$usage['total_tokens']:null,
            ':finished_at'=>self::now(),':error_code'=>$errorCode!==''?self::shortText($errorCode,100):null,
            ':error_summary'=>$message!==''?self::shortText($message,500):null,':id'=>(int)$runId
        ));
    }

    private static function findCachedBrief($pdo, $fingerprint)
    {
        try {
            $st = $pdo->prepare('SELECT id,analysis_date,target_ym,source_project_count,company_status,headline,generated_at FROM `' . self::BRIEF_TABLE . '` WHERE source_fingerprint=:source_fingerprint AND schema_version=:schema_version AND model_name=:model_name LIMIT 1');
            if (!$st || !$st->execute(array(':source_fingerprint'=>$fingerprint,':schema_version'=>OpenAiResponsesClient::schemaVersion(),':model_name'=>OpenAiResponsesClient::model()))) return array();
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return is_array($row)?$row:array();
        } catch (Exception $e) { return array(); }
    }

    private static function acquireLock($pdo, $fingerprint)
    {
        $name = 'cpms_ai_gpt_executive_brief_' . substr($fingerprint,0,24);
        try {
            $st = $pdo->prepare('SELECT GET_LOCK(:lock_name,0)');
            if (!$st || !$st->execute(array(':lock_name'=>$name))) return array('ok'=>true,'name'=>'');
            return array('ok'=>(int)$st->fetchColumn()===1,'name'=>$name);
        } catch (Exception $e) { return array('ok'=>true,'name'=>''); }
    }

    private static function releaseLock($pdo, $lock)
    {
        if (!is_array($lock) || empty($lock['name'])) return;
        try { $st=$pdo->prepare('SELECT RELEASE_LOCK(:lock_name)'); if ($st) $st->execute(array(':lock_name'=>$lock['name'])); } catch (Exception $e) {}
    }

    private static function clearStaleRuns($pdo, $fingerprint)
    {
        try {
            $st=$pdo->prepare("UPDATE `" . self::RUN_TABLE . "` SET run_status='FAILED',finished_at=:finished_at,error_code='STALE_RUN',error_summary='오래된 실행상태를 종료했습니다.' WHERE task_type=:task_type AND source_fingerprint=:fingerprint AND run_status='RUNNING' AND started_at<DATE_SUB(NOW(),INTERVAL 1 HOUR)");
            if ($st) $st->execute(array(':finished_at'=>self::now(),':task_type'=>self::TASK_TYPE,':fingerprint'=>$fingerprint));
        } catch (Exception $e) {}
    }

    private static function hasRunning($pdo, $fingerprint)
    {
        try {
            $st=$pdo->prepare("SELECT COUNT(*) FROM `" . self::RUN_TABLE . "` WHERE task_type=:task_type AND source_fingerprint=:fingerprint AND run_status='RUNNING' AND started_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)");
            if (!$st || !$st->execute(array(':task_type'=>self::TASK_TYPE,':fingerprint'=>$fingerprint))) return false;
            return (int)$st->fetchColumn()>0;
        } catch (Exception $e) { return false; }
    }

    private static function repeatedTooSoon($pdo, $trigger)
    {
        if ($trigger!=='MANUAL') return false;
        $actor=self::resolveActor($trigger);
        if ($actor['id']===null && $actor['name']==='') return false;
        try {
            $where=$actor['id']!==null?'actor_employee_id=:actor_id':'actor_name=:actor_name';
            $params=$actor['id']!==null?array(':actor_id'=>$actor['id']):array(':actor_name'=>$actor['name']);
            $st=$pdo->prepare("SELECT COUNT(*) FROM `" . self::RUN_TABLE . "` WHERE task_type='EXECUTIVE_BRIEF' AND " . $where . " AND started_at>=DATE_SUB(NOW(),INTERVAL 30 SECOND)");
            if (!$st || !$st->execute($params)) return false;
            return (int)$st->fetchColumn()>0;
        } catch (Exception $e) { return false; }
    }

    private static function saveBrief($pdo, $runId, $source, $fingerprint, $data)
    {
        $now=self::now();
        $sourceJson=self::encodeData($source['source_data']);
        $rawJson=self::encodeData($data);
        if (!is_string($sourceJson) || !is_string($rawJson)) return false;
        $sql='INSERT INTO `' . self::BRIEF_TABLE . '` (run_id,analysis_date,target_ym,source_fingerprint,schema_version,model_name,source_project_count,company_status,headline,executive_summary,top_risk_count,check_today_count,key_metrics_data,top_risks_data,positive_signals_data,check_today_data,data_limitations_data,disclaimer,source_summary_data,raw_structured_output,generated_at,created_at,updated_at) VALUES '
            . '(:run_id,:analysis_date,:target_ym,:fingerprint,:schema_version,:model_name,:project_count,:company_status,:headline,:executive_summary,:top_risk_count,:check_today_count,:key_metrics,:top_risks,:positive_signals,:check_today,:data_limitations,:disclaimer,:source_summary,:raw_output,:generated_at,:created_at,:updated_at) '
            . 'ON DUPLICATE KEY UPDATE run_id=VALUES(run_id),analysis_date=VALUES(analysis_date),target_ym=VALUES(target_ym),source_project_count=VALUES(source_project_count),company_status=VALUES(company_status),headline=VALUES(headline),executive_summary=VALUES(executive_summary),top_risk_count=VALUES(top_risk_count),check_today_count=VALUES(check_today_count),key_metrics_data=VALUES(key_metrics_data),top_risks_data=VALUES(top_risks_data),positive_signals_data=VALUES(positive_signals_data),check_today_data=VALUES(check_today_data),data_limitations_data=VALUES(data_limitations_data),disclaimer=VALUES(disclaimer),source_summary_data=VALUES(source_summary_data),raw_structured_output=VALUES(raw_structured_output),generated_at=VALUES(generated_at),updated_at=VALUES(updated_at)';
        $st=$pdo->prepare($sql);
        if (!$st) return false;
        return $st->execute(array(
            ':run_id'=>(int)$runId,':analysis_date'=>$source['analysis_date'],':target_ym'=>$source['target_ym'],':fingerprint'=>$fingerprint,
            ':schema_version'=>OpenAiResponsesClient::schemaVersion(),':model_name'=>OpenAiResponsesClient::model(),':project_count'=>(int)$source['project_count'],
            ':company_status'=>$data['company_status'],':headline'=>self::shortText($data['headline'],300),':executive_summary'=>$data['executive_summary'],
            ':top_risk_count'=>count($data['top_risks']),':check_today_count'=>count($data['check_today']),':key_metrics'=>self::encodeData($data['key_metrics']),
            ':top_risks'=>self::encodeData($data['top_risks']),':positive_signals'=>self::encodeData($data['positive_signals']),':check_today'=>self::encodeData($data['check_today']),
            ':data_limitations'=>self::encodeData($data['data_limitations']),':disclaimer'=>self::shortText($data['disclaimer'],500),':source_summary'=>$sourceJson,
            ':raw_output'=>$rawJson,':generated_at'=>$now,':created_at'=>$now,':updated_at'=>$now
        ));
    }

    public static function generateLatest($pdo = null, $triggerType = 'SYSTEM', $force = false)
    {
        $pdo=self::pdo($pdo);
        $trigger=self::normalizeTrigger($triggerType);
        $empty=array('ok'=>false,'cached'=>false,'busy'=>false,'status'=>'FAILED','analysis_date'=>'','target_ym'=>'','projects'=>0,'model'=>OpenAiResponsesClient::model(),'brief_id'=>0,'message'=>'경영 브리핑을 생성하지 못했습니다.');
        if (!$pdo) { $empty['message']='DB 연결 상태를 확인할 수 없습니다.'; return $empty; }
        if (!self::isInstalled($pdo)) { $empty['message']='OpenAI 경영 브리핑 테이블을 먼저 설치해주세요.'; return $empty; }
        $source=self::buildSourceData($pdo);
        if (empty($source['ok'])) { $empty['message']=isset($source['message'])?$source['message']:$empty['message']; return $empty; }
        $empty['analysis_date']=$source['analysis_date']; $empty['target_ym']=$source['target_ym']; $empty['projects']=$source['project_count'];
        $fingerprint=self::sourceFingerprint($source['source_data'],$source['analysis_date'],$source['target_ym'],OpenAiResponsesClient::schemaVersion(),OpenAiResponsesClient::model());
        if ($fingerprint==='') { $empty['message']='브리핑 원본자료를 확인하지 못했습니다.'; return $empty; }
        if (!$force) {
            $cached=self::findCachedBrief($pdo,$fingerprint);
            if (!empty($cached)) {
                $runId=self::createRun($pdo,$source,$fingerprint,$trigger,'CACHED');
                if ($runId>0) self::finishRun($pdo,$runId,'CACHED',array(),'', '동일 자료의 저장된 브리핑을 사용했습니다.');
                return array_merge($empty,array('ok'=>true,'cached'=>true,'status'=>'CACHED','brief_id'=>(int)$cached['id'],'message'=>'동일 자료의 저장된 경영 브리핑을 사용했습니다.'));
            }
        }
        if (!OpenAiResponsesClient::hasApiKey()) { $empty['message']='OpenAI API 키가 설정되지 않았습니다.'; return $empty; }
        if (!function_exists('curl_init')) { $empty['message']='서버의 PHP cURL 기능을 확인해주세요.'; return $empty; }
        if (self::repeatedTooSoon($pdo,$trigger)) { $empty['busy']=true; $empty['message']='잠시 후 다시 시도해주세요.'; return $empty; }
        $lock=self::acquireLock($pdo,$fingerprint);
        if (empty($lock['ok'])) { $empty['busy']=true; $empty['message']='같은 자료의 경영 브리핑이 이미 생성 중입니다.'; return $empty; }
        $runId=0;
        try {
            self::clearStaleRuns($pdo,$fingerprint);
            if (self::hasRunning($pdo,$fingerprint)) { self::releaseLock($pdo,$lock); $empty['busy']=true; $empty['message']='같은 자료의 경영 브리핑이 이미 생성 중입니다.'; return $empty; }
            if (!$force) {
                $cached=self::findCachedBrief($pdo,$fingerprint);
                if (!empty($cached)) { self::releaseLock($pdo,$lock); return array_merge($empty,array('ok'=>true,'cached'=>true,'status'=>'CACHED','brief_id'=>(int)$cached['id'],'message'=>'동일 자료의 저장된 경영 브리핑을 사용했습니다.')); }
            }
            $runId=self::createRun($pdo,$source,$fingerprint,$trigger,'RUNNING');
            if ($runId<=0) throw new Exception('run unavailable');
            $payload=self::buildRequestPayload($source['source_data']);
            if (count($payload)===0) throw new Exception('payload unavailable');
            $api=OpenAiResponsesClient::request($payload,self::TASK_TYPE);
            if (empty($api['ok'])) {
                $status=!empty($api['refused'])?'REFUSED':'FAILED';
                self::finishRun($pdo,$runId,$status,$api,isset($api['error_code'])?$api['error_code']:'OPENAI_FAILED',isset($api['message'])?$api['message']:'OpenAI 요청에 실패했습니다.');
                self::releaseLock($pdo,$lock);
                return array_merge($empty,array('status'=>$status,'message'=>isset($api['message'])?$api['message']:$empty['message']));
            }
            $decoded=json_decode($api['output_text'],true);
            $validation=self::validateStructuredOutput($decoded,$source['source_data']);
            if (empty($validation['ok'])) {
                self::finishRun($pdo,$runId,'FAILED',$api,'OUTPUT_VALIDATION_FAILED',isset($validation['message'])?$validation['message']:'OpenAI 응답 형식을 확인하지 못했습니다.');
                self::releaseLock($pdo,$lock);
                return array_merge($empty,array('message'=>isset($validation['message'])?$validation['message']:$empty['message']));
            }
            if (!self::saveBrief($pdo,$runId,$source,$fingerprint,$validation['data'])) throw new Exception('save unavailable');
            self::finishRun($pdo,$runId,'COMPLETED',$api,'','');
            $brief=self::findCachedBrief($pdo,$fingerprint);
            self::releaseLock($pdo,$lock);
            return array_merge($empty,array('ok'=>true,'status'=>'COMPLETED','brief_id'=>isset($brief['id'])?(int)$brief['id']:0,'message'=>'대표용 경영 브리핑을 생성했습니다.'));
        } catch (Exception $e) {
            if ($runId>0) self::finishRun($pdo,$runId,'FAILED',array(),'BRIEF_FAILED','경영 브리핑 생성 중 오류가 발생했습니다.');
            error_log('[OpenAI] task=EXECUTIVE_BRIEF status=FAILED');
            self::releaseLock($pdo,$lock);
            return $empty;
        }
    }

    public static function latestBrief($pdo = null)
    {
        $pdo=self::pdo($pdo);
        if (!$pdo || !self::tableExists($pdo,self::BRIEF_TABLE)) return array();
        try {
            $sql='SELECT id,run_id,analysis_date,target_ym,schema_version,model_name,source_project_count,company_status,headline,executive_summary,top_risk_count,check_today_count,key_metrics_data,top_risks_data,positive_signals_data,check_today_data,data_limitations_data,disclaimer,source_summary_data,generated_at,created_at,updated_at FROM `' . self::BRIEF_TABLE . '` ORDER BY generated_at DESC,id DESC LIMIT 1';
            $st=$pdo->query($sql); $row=$st?$st->fetch(PDO::FETCH_ASSOC):false;
            return is_array($row)?$row:array();
        } catch (Exception $e) { return array(); }
    }

    public static function briefOriginalProjects($brief)
    {
        $map=array();
        if (!is_array($brief)) return $map;
        $source=self::decodeArray(isset($brief['source_summary_data'])?$brief['source_summary_data']:'');
        if (isset($source['projects'])&&is_array($source['projects'])) foreach ($source['projects'] as $project) if (is_array($project)&&isset($project['project_id'])) $map[(int)$project['project_id']]=$project;
        return $map;
    }
}
