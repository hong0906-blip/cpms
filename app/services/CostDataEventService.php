<?php
/**
 * 통합 비용 입력·변경 이벤트 서비스.
 *
 * 기존 비용 저장 로직과 분리하여 이력 테이블만 관리한다.
 * PHP 5.6 / MySQL 5.6 compatible.
 */

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;
use Exception;
use PDO;
use PDOException;

require_once __DIR__ . '/AiCostDataGovernanceService.php';

class CostDataEventService
{
    const TABLE_NAME = 'cpms_cost_data_events';

    private static $tableCache = array();
    private static $columnCache = array();
    private static $indexCache = array();
    private static $installedCache = array();
    private static $projectNameCache = array();
    private static $notInstalledLogged = array();

    public static function pdo($pdo = null)
    {
        if ($pdo) return $pdo;
        return Db::pdo();
    }

    private static function connectionKey($pdo)
    {
        if (is_object($pdo)) return spl_object_hash($pdo);
        return 'none';
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
            $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name');
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
            foreach ($rows as $row) {
                if (isset($row['Field'])) $columns[(string)$row['Field']] = $row;
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

    public static function requiredColumns()
    {
        return array(
            'id', 'event_uid', 'dedupe_key', 'batch_key', 'project_id',
            'project_name_snapshot', 'cost_type', 'target_type', 'target_id',
            'event_action', 'source_type', 'data_origin', 'actual_date', 'settlement_ym',
            'old_amount', 'new_amount', 'delta_amount', 'old_data', 'new_data',
            'actor_employee_id', 'actor_name', 'actor_department',
            'related_request_id', 'reason', 'source_file', 'event_at', 'created_at'
        );
    }

    public static function requiredIndexes()
    {
        return array(
            'PRIMARY',
            'uk_cost_data_event_uid',
            'uk_cost_data_event_dedupe',
            'idx_cost_event_time',
            'idx_cost_event_project',
            'idx_cost_event_target',
            'idx_cost_event_actor',
            'idx_cost_event_source',
            'idx_cost_event_origin',
            'idx_cost_event_request',
            'idx_cost_event_settlement'
        );
    }

    public static function isInstalled($pdo = null)
    {
        $pdo = self::pdo($pdo);
        if (!$pdo) return false;
        $key = self::connectionKey($pdo);
        if (array_key_exists($key, self::$installedCache)) return self::$installedCache[$key];
        if (!self::tableExists($pdo, self::TABLE_NAME)) {
            self::$installedCache[$key] = false;
            return false;
        }
        foreach (self::requiredColumns() as $column) {
            if (!self::columnExists($pdo, self::TABLE_NAME, $column)) {
                self::$installedCache[$key] = false;
                return false;
            }
        }
        self::$installedCache[$key] = true;
        return true;
    }

    private static function clearSchemaCache($pdo)
    {
        $key = self::connectionKey($pdo);
        foreach (array_keys(self::$tableCache) as $cacheKey) {
            if (strpos($cacheKey, $key . ':') === 0) unset(self::$tableCache[$cacheKey]);
        }
        foreach (array_keys(self::$columnCache) as $cacheKey) {
            if (strpos($cacheKey, $key . ':') === 0) unset(self::$columnCache[$cacheKey]);
        }
        foreach (array_keys(self::$indexCache) as $cacheKey) {
            if (strpos($cacheKey, $key . ':') === 0) unset(self::$indexCache[$cacheKey]);
        }
        unset(self::$installedCache[$key]);
    }

    public static function createTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS cpms_cost_data_events (\n"
            . "    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,\n"
            . "    event_uid VARCHAR(64) NOT NULL,\n"
            . "    dedupe_key VARCHAR(190) NULL,\n"
            . "    batch_key VARCHAR(100) NULL,\n"
            . "    project_id INT UNSIGNED NULL,\n"
            . "    project_name_snapshot VARCHAR(190) NULL,\n"
            . "    cost_type VARCHAR(40) NOT NULL,\n"
            . "    target_type VARCHAR(50) NOT NULL,\n"
            . "    target_id VARCHAR(80) NULL,\n"
            . "    event_action VARCHAR(30) NOT NULL,\n"
            . "    source_type VARCHAR(30) NOT NULL,\n"
            . "    data_origin VARCHAR(40) NOT NULL DEFAULT 'UNKNOWN_REVIEW',\n"
            . "    actual_date DATE NULL,\n"
            . "    settlement_ym CHAR(7) NULL,\n"
            . "    old_amount DECIMAL(18,2) NULL,\n"
            . "    new_amount DECIMAL(18,2) NULL,\n"
            . "    delta_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . "    old_data MEDIUMTEXT NULL,\n"
            . "    new_data MEDIUMTEXT NULL,\n"
            . "    actor_employee_id INT NULL,\n"
            . "    actor_name VARCHAR(100) NULL,\n"
            . "    actor_department VARCHAR(100) NULL,\n"
            . "    related_request_id INT UNSIGNED NULL,\n"
            . "    reason VARCHAR(500) NULL,\n"
            . "    source_file VARCHAR(255) NULL,\n"
            . "    event_at DATETIME NOT NULL,\n"
            . "    created_at DATETIME NOT NULL,\n"
            . "    UNIQUE KEY uk_cost_data_event_uid (event_uid),\n"
            . "    UNIQUE KEY uk_cost_data_event_dedupe (dedupe_key),\n"
            . "    KEY idx_cost_event_time (event_at),\n"
            . "    KEY idx_cost_event_project (project_id, cost_type, event_at),\n"
            . "    KEY idx_cost_event_target (target_type, target_id, event_at),\n"
            . "    KEY idx_cost_event_actor (actor_employee_id, event_at),\n"
            . "    KEY idx_cost_event_source (source_type, event_at),\n"
            . "    KEY idx_cost_event_origin (data_origin, settlement_ym, cost_type),\n"
            . "    KEY idx_cost_event_request (related_request_id),\n"
            . "    KEY idx_cost_event_settlement (settlement_ym, cost_type)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    public static function installOrUpdate($pdo = null)
    {
        $pdo = self::pdo($pdo);
        if (!$pdo) return array('ok' => false, 'message' => 'DB 연결을 확인할 수 없습니다.', 'created' => false, 'updated' => array());
        $created = !self::tableExists($pdo, self::TABLE_NAME);
        $updated = array();
        try {
            $pdo->exec(self::createTableSql());
            self::clearSchemaCache($pdo);

            if (!self::columnExists($pdo, self::TABLE_NAME, 'id')) {
                $existingIndexes = self::getTableIndexes($pdo, self::TABLE_NAME);
                if (!isset($existingIndexes['PRIMARY'])) {
                    $pdo->exec('ALTER TABLE `' . self::TABLE_NAME . '` ADD COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST');
                    $updated[] = 'column:id';
                    self::clearSchemaCache($pdo);
                }
            }

            $columnDefinitions = array(
                'event_uid' => 'VARCHAR(64) NOT NULL',
                'dedupe_key' => 'VARCHAR(190) NULL',
                'batch_key' => 'VARCHAR(100) NULL',
                'project_id' => 'INT UNSIGNED NULL',
                'project_name_snapshot' => 'VARCHAR(190) NULL',
                'cost_type' => 'VARCHAR(40) NOT NULL',
                'target_type' => 'VARCHAR(50) NOT NULL',
                'target_id' => 'VARCHAR(80) NULL',
                'event_action' => 'VARCHAR(30) NOT NULL',
                'source_type' => 'VARCHAR(30) NOT NULL',
                'data_origin' => "VARCHAR(40) NOT NULL DEFAULT 'UNKNOWN_REVIEW'",
                'actual_date' => 'DATE NULL',
                'settlement_ym' => 'CHAR(7) NULL',
                'old_amount' => 'DECIMAL(18,2) NULL',
                'new_amount' => 'DECIMAL(18,2) NULL',
                'delta_amount' => 'DECIMAL(18,2) NOT NULL DEFAULT 0',
                'old_data' => 'MEDIUMTEXT NULL',
                'new_data' => 'MEDIUMTEXT NULL',
                'actor_employee_id' => 'INT NULL',
                'actor_name' => 'VARCHAR(100) NULL',
                'actor_department' => 'VARCHAR(100) NULL',
                'related_request_id' => 'INT UNSIGNED NULL',
                'reason' => 'VARCHAR(500) NULL',
                'source_file' => 'VARCHAR(255) NULL',
                'event_at' => 'DATETIME NOT NULL',
                'created_at' => 'DATETIME NOT NULL'
            );
            foreach ($columnDefinitions as $column => $definition) {
                if (!self::columnExists($pdo, self::TABLE_NAME, $column)) {
                    $pdo->exec('ALTER TABLE `' . self::TABLE_NAME . '` ADD COLUMN `' . $column . '` ' . $definition);
                    $updated[] = 'column:' . $column;
                    self::clearSchemaCache($pdo);
                }
            }

            $indexDefinitions = array(
                'uk_cost_data_event_uid' => 'UNIQUE KEY `uk_cost_data_event_uid` (`event_uid`)',
                'uk_cost_data_event_dedupe' => 'UNIQUE KEY `uk_cost_data_event_dedupe` (`dedupe_key`)',
                'idx_cost_event_time' => 'KEY `idx_cost_event_time` (`event_at`)',
                'idx_cost_event_project' => 'KEY `idx_cost_event_project` (`project_id`,`cost_type`,`event_at`)',
                'idx_cost_event_target' => 'KEY `idx_cost_event_target` (`target_type`,`target_id`,`event_at`)',
                'idx_cost_event_actor' => 'KEY `idx_cost_event_actor` (`actor_employee_id`,`event_at`)',
                'idx_cost_event_source' => 'KEY `idx_cost_event_source` (`source_type`,`event_at`)',
                'idx_cost_event_origin' => 'KEY `idx_cost_event_origin` (`data_origin`,`settlement_ym`,`cost_type`)',
                'idx_cost_event_request' => 'KEY `idx_cost_event_request` (`related_request_id`)',
                'idx_cost_event_settlement' => 'KEY `idx_cost_event_settlement` (`settlement_ym`,`cost_type`)'
            );
            $indexes = self::getTableIndexes($pdo, self::TABLE_NAME);
            if (!isset($indexes['PRIMARY']) && self::columnExists($pdo, self::TABLE_NAME, 'id')) {
                $pdo->exec('ALTER TABLE `' . self::TABLE_NAME . '` ADD PRIMARY KEY (`id`)');
                $updated[] = 'index:PRIMARY';
                self::clearSchemaCache($pdo);
                $indexes = self::getTableIndexes($pdo, self::TABLE_NAME);
            }
            foreach ($indexDefinitions as $name => $definition) {
                if (!isset($indexes[$name])) {
                    $pdo->exec('ALTER TABLE `' . self::TABLE_NAME . '` ADD ' . $definition);
                    $updated[] = 'index:' . $name;
                }
            }
            self::clearSchemaCache($pdo);
            if (!self::isInstalled($pdo)) throw new Exception('필수 구조 확인에 실패했습니다.');
            return array(
                'ok' => true,
                'message' => $created ? '통합 비용 이력 테이블을 설치했습니다.' : '통합 비용 이력 테이블 구조를 확인했습니다.',
                'created' => $created,
                'updated' => $updated
            );
        } catch (Exception $e) {
            return array('ok' => false, 'message' => '통합 비용 이력 테이블 설치 또는 확인에 실패했습니다.', 'created' => $created, 'updated' => $updated);
        }
    }

    public static function generateEventUid()
    {
        $random = '';
        if (function_exists('openssl_random_pseudo_bytes')) {
            $bytes = @openssl_random_pseudo_bytes(24);
            if ($bytes !== false) $random = bin2hex($bytes);
        }
        if ($random === '') $random = sha1(uniqid((string)mt_rand(), true) . microtime(true));
        return 'evt_' . date('YmdHis') . '_' . substr(hash('sha256', $random . uniqid('', true)), 0, 40);
    }

    public static function generateBatchKey($prefix, $actorId)
    {
        $prefix = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$prefix);
        if ($prefix === '') $prefix = 'batch';
        return substr(strtolower($prefix) . ':' . date('YmdHis') . ':' . (int)$actorId . ':' . substr(self::generateEventUid(), -12), 0, 100);
    }

    public static function normalizeCostType($value)
    {
        $value = strtolower(trim((string)$value));
        $map = array(
            'labor' => 'labor', 'labor_force' => 'labor', '노무' => 'labor', '노무비' => 'labor',
            'material' => 'material', '자재' => 'material', '자재비' => 'material',
            'purchase' => 'purchase', '구매품' => 'purchase',
            'equipment' => 'equipment', '장비' => 'equipment', '장비비' => 'equipment',
            'outsourcing' => 'outsourcing', '외주' => 'outsourcing', '외주비' => 'outsourcing',
            'safety' => 'safety', '안전' => 'safety', '안전관리비' => 'safety', '보호구 구입비' => 'safety', '교육비' => 'safety',
            'health' => 'health', '보건' => 'health', '보건비' => 'health', '검진비' => 'health',
            'other_expense' => 'other_expense', '기타경비' => 'other_expense',
            'other' => 'other', '기타' => 'other', 'daily_cost' => 'other', 'monthly_deduction' => 'other', '기타 안전·보건 비용' => 'other'
        );
        return isset($map[$value]) ? $map[$value] : 'other';
    }

    public static function normalizeEventAction($value)
    {
        $value = strtoupper(trim((string)$value));
        $allowed = array('CREATE', 'UPDATE', 'DELETE', 'RESTORE', 'ADJUST', 'RE_ENTRY');
        return in_array($value, $allowed, true) ? $value : 'ADJUST';
    }

    public static function normalizeSourceType($value)
    {
        $value = strtoupper(trim((string)$value));
        $allowed = array('DIRECT', 'EXCEL', 'ATTENDANCE', 'APPROVAL', 'ADMIN_FORCE', 'AUTO_CALC', 'SYSTEM', 'MANUAL_BACKFILL', 'SYSTEM_IMPORT', 'HISTORICAL_MIGRATION');
        return in_array($value, $allowed, true) ? $value : 'SYSTEM';
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

    public static function money($value)
    {
        if ($value === null || $value === '') return null;
        if (is_string($value)) $value = str_replace(array(',', ' '), '', $value);
        if (!is_numeric($value)) return null;
        return number_format((float)$value, 2, '.', '');
    }

    private static function truncate($value, $length)
    {
        $value = trim((string)$value);
        if (function_exists('mb_substr')) return mb_substr($value, 0, $length, 'UTF-8');
        return substr($value, 0, $length);
    }

    private static function sanitizeText($value)
    {
        $value = (string)$value;
        $patterns = array(
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu' => '[이메일 제외]',
            '/\b\d{6}\s*[-]?\s*[1-4]\d{6}\b/u' => '[주민번호 제외]',
            '/\b01[016789]\s*[-.]?\s*\d{3,4}\s*[-.]?\s*\d{4}\b/u' => '[전화번호 제외]',
            '/\b0\d{1,2}\s*[-.]\s*\d{3,4}\s*[-.]\s*\d{4}\b/u' => '[전화번호 제외]',
            '/\b(?:\d[ -]?){10,20}\b/u' => '[번호 제외]',
            '/\bsk-[A-Za-z0-9_-]{12,}\b/u' => '[API 키 제외]',
            '/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/iu' => '[토큰 제외]'
        );
        foreach ($patterns as $pattern => $replacement) {
            $replaced = preg_replace($pattern, $replacement, $value);
            if (is_string($replaced)) $value = $replaced;
        }
        return $value;
    }

    public static function resolveActor($options)
    {
        $options = is_array($options) ? $options : array();
        if (!empty($options['skip_actor'])) return array('id' => null, 'name' => '', 'department' => '');

        $user = Auth::user();
        $id = isset($options['actor_employee_id']) ? (int)$options['actor_employee_id'] : 0;
        if ($id <= 0 && is_array($user) && isset($user['id']) && is_numeric($user['id'])) $id = (int)$user['id'];
        $name = isset($options['actor_name']) ? trim((string)$options['actor_name']) : '';
        if ($name === '') $name = trim((string)Auth::userName());
        $department = isset($options['actor_department']) ? trim((string)$options['actor_department']) : '';
        if ($department === '') $department = trim((string)Auth::userDepartment());
        return array(
            'id' => $id > 0 ? $id : null,
            'name' => self::truncate($name, 100),
            'department' => self::truncate($department, 100)
        );
    }

    public static function projectName($pdo, $projectId)
    {
        $pdo = self::pdo($pdo);
        $projectId = (int)$projectId;
        if (!$pdo || $projectId <= 0) return '';
        $key = self::connectionKey($pdo) . ':' . $projectId;
        if (array_key_exists($key, self::$projectNameCache)) return self::$projectNameCache[$key];
        $name = '';
        try {
            if (self::tableExists($pdo, 'cpms_projects') && self::columnExists($pdo, 'cpms_projects', 'name')) {
                $st = $pdo->prepare('SELECT name FROM cpms_projects WHERE id = :id LIMIT 1');
                if (!$st) return '';
                $st->bindValue(':id', $projectId, PDO::PARAM_INT);
                if (!$st->execute()) return '';
                $name = trim((string)$st->fetchColumn());
            }
        } catch (Exception $e) {
            $name = '';
        }
        self::$projectNameCache[$key] = self::truncate($name, 190);
        return self::$projectNameCache[$key];
    }

    private static function allowedSnapshotKeys()
    {
        return array(
            'project_id', 'material_id', 'equipment_id', 'use_date', 'expense_date',
            'actual_date', 'settlement_ym', 'old_settlement_ym', 'new_settlement_ym',
            'amount', 'old_amount', 'new_amount', 'work_unit', 'old_value', 'new_value',
            'base_rate_snapshot', 'base_rate', 'unit_price', 'quantity', 'is_manual_unit', 'advance_yn',
            'memo', 'remark', 'category', 'item_name', 'material_name', 'equipment_name', 'spec',
            'vendor_name', 'company_name', 'use_content', 'status', 'reason', 'is_deleted',
            'is_deleted_entry', 'month', 'cost_date', 'cost_type', 'request_type',
            'target_type', 'target_id', 'approval_stage', 'approval_required_level'
        );
    }

    public static function sanitizeSnapshot($data)
    {
        if (!is_array($data)) return array();
        $allowed = array_flip(self::allowedSnapshotKeys());
        $freeTextKeys = array_flip(array('memo', 'remark', 'reason', 'use_content', 'vendor_name', 'company_name', 'item_name', 'material_name', 'equipment_name', 'category', 'status', 'spec'));
        $clean = array();
        foreach ($data as $key => $value) {
            $key = strtolower(trim((string)$key));
            if (!isset($allowed[$key])) continue;
            if (is_array($value) || is_object($value) || is_resource($value)) continue;
            if (is_bool($value)) $value = $value ? 1 : 0;
            if (is_string($value)) {
                if (isset($freeTextKeys[$key])) $value = self::sanitizeText($value);
                $value = self::truncate($value, 1000);
            }
            $clean[$key] = $value;
        }
        ksort($clean);
        return $clean;
    }

    public static function encodeSnapshot($data)
    {
        $clean = self::sanitizeSnapshot($data);
        if (count($clean) === 0) return null;
        $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : null;
    }

    public static function decodeSnapshot($json)
    {
        if (!is_string($json) || trim($json) === '') return array();
        $data = json_decode($json, true);
        return self::sanitizeSnapshot(is_array($data) ? $data : array());
    }

    public static function amounts($action, $oldAmount, $newAmount)
    {
        $action = self::normalizeEventAction($action);
        $old = self::money($oldAmount);
        $new = self::money($newAmount);
        if ($action === 'CREATE' || $action === 'RESTORE') $old = null;
        if ($action === 'DELETE') $new = null;
        $delta = 0.0;
        if ($new !== null) $delta += (float)$new;
        if ($old !== null) $delta -= (float)$old;
        return array('old_amount' => $old, 'new_amount' => $new, 'delta_amount' => number_format($delta, 2, '.', ''));
    }

    public static function eventExistsByDedupeKey($pdo, $dedupeKey)
    {
        $pdo = self::pdo($pdo);
        $dedupeKey = trim((string)$dedupeKey);
        if (!$pdo || $dedupeKey === '' || !self::isInstalled($pdo)) return false;
        try {
            $st = $pdo->prepare('SELECT id FROM `' . self::TABLE_NAME . '` WHERE dedupe_key = :dedupe_key LIMIT 1');
            if (!$st) return false;
            $st->bindValue(':dedupe_key', self::truncate($dedupeKey, 190), PDO::PARAM_STR);
            if (!$st->execute()) return false;
            return $st->fetchColumn() !== false;
        } catch (Exception $e) {
            return false;
        }
    }

    public static function recordChange($pdo, $options)
    {
        $pdo = self::pdo($pdo);
        $options = is_array($options) ? $options : array();
        if (!$pdo) return array('ok' => false, 'skipped' => true, 'reason' => 'db_unavailable');
        if (!self::isInstalled($pdo)) {
            $key = self::connectionKey($pdo);
            if (!isset(self::$notInstalledLogged[$key])) {
                error_log('[CostDataEvent] history table is not installed');
                self::$notInstalledLogged[$key] = true;
            }
            return array('ok' => true, 'skipped' => true, 'reason' => 'not_installed');
        }

        try {
            $action = self::normalizeEventAction(isset($options['event_action']) ? $options['event_action'] : 'ADJUST');
            $source = self::normalizeSourceType(isset($options['source_type']) ? $options['source_type'] : 'SYSTEM');
            $eventAt = isset($options['event_at']) ? trim((string)$options['event_at']) : '';
            if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $eventAt)) $eventAt = date('Y-m-d H:i:s');
            $originOptions = $options;
            $originOptions['event_at'] = $eventAt;
            $dataOrigin = AiCostDataGovernanceService::classifyOrigin($source, $action, $eventAt, $originOptions);
            $reasonText = isset($options['reason']) ? trim((string)$options['reason']) : '';
            if (AiCostDataGovernanceService::requiresChangeReason($dataOrigin) && $reasonText === '') {
                return array('ok'=>false, 'skipped'=>true, 'reason'=>'admin_correction_reason_required');
            }
            $rawCostType = isset($options['cost_type']) ? $options['cost_type'] : 'other';
            $targetTypeForCost = isset($options['target_type']) ? strtolower(trim((string)$options['target_type'])) : '';
            if (($targetTypeForCost === 'material' || $targetTypeForCost === 'material_usage') && isset($options['new_data']) && is_array($options['new_data']) && !empty($options['new_data']['category'])) {
                $rawCostType = $options['new_data']['category'];
            }
            $costType = self::normalizeCostType($rawCostType);
            $oldData = self::encodeSnapshot(isset($options['old_data']) ? $options['old_data'] : array());
            $newData = self::encodeSnapshot(isset($options['new_data']) ? $options['new_data'] : array());
            $amounts = self::amounts(
                $action,
                isset($options['old_amount']) ? $options['old_amount'] : null,
                isset($options['new_amount']) ? $options['new_amount'] : null
            );
            if (($action === 'UPDATE' || $action === 'ADJUST')
                && $amounts['old_amount'] === $amounts['new_amount']
                && $oldData === $newData) {
                return array('ok' => true, 'skipped' => true, 'reason' => 'unchanged');
            }

            $dedupeKey = isset($options['dedupe_key']) ? self::truncate($options['dedupe_key'], 190) : '';
            if ($dedupeKey !== '' && self::eventExistsByDedupeKey($pdo, $dedupeKey)) {
                return array('ok' => true, 'skipped' => true, 'reason' => 'duplicate');
            }

            $projectId = isset($options['project_id']) ? (int)$options['project_id'] : 0;
            $projectName = isset($options['project_name_snapshot']) ? trim((string)$options['project_name_snapshot']) : '';
            if ($projectName === '' && $projectId > 0) $projectName = self::projectName($pdo, $projectId);
            $actualDate = self::validDate(isset($options['actual_date']) ? $options['actual_date'] : '');
            $settlementYm = self::validYm(isset($options['settlement_ym']) ? $options['settlement_ym'] : '');
            $actor = self::resolveActor($options);
            $sourceFile = isset($options['source_file']) ? str_replace('\\', '/', (string)$options['source_file']) : '';
            if ($sourceFile !== '') $sourceFile = basename($sourceFile);

            $sql = 'INSERT INTO `' . self::TABLE_NAME . '` ('
                . 'event_uid,dedupe_key,batch_key,project_id,project_name_snapshot,cost_type,target_type,target_id,'
                . 'event_action,source_type,data_origin,actual_date,settlement_ym,old_amount,new_amount,delta_amount,old_data,new_data,'
                . 'actor_employee_id,actor_name,actor_department,related_request_id,reason,source_file,event_at,created_at'
                . ') VALUES ('
                . ':event_uid,:dedupe_key,:batch_key,:project_id,:project_name_snapshot,:cost_type,:target_type,:target_id,'
                . ':event_action,:source_type,:data_origin,:actual_date,:settlement_ym,:old_amount,:new_amount,:delta_amount,:old_data,:new_data,'
                . ':actor_employee_id,:actor_name,:actor_department,:related_request_id,:reason,:source_file,:event_at,:created_at)';
            $st = $pdo->prepare($sql);
            if (!$st || !$st->execute(array(
                ':event_uid' => self::generateEventUid(),
                ':dedupe_key' => $dedupeKey !== '' ? $dedupeKey : null,
                ':batch_key' => isset($options['batch_key']) && trim((string)$options['batch_key']) !== '' ? self::truncate($options['batch_key'], 100) : null,
                ':project_id' => $projectId > 0 ? $projectId : null,
                ':project_name_snapshot' => $projectName !== '' ? self::truncate($projectName, 190) : null,
                ':cost_type' => $costType,
                ':target_type' => self::truncate(isset($options['target_type']) ? $options['target_type'] : $costType, 50),
                ':target_id' => isset($options['target_id']) && trim((string)$options['target_id']) !== '' ? self::truncate($options['target_id'], 80) : null,
                ':event_action' => $action,
                ':source_type' => $source,
                ':data_origin' => $dataOrigin,
                ':actual_date' => $actualDate !== '' ? $actualDate : null,
                ':settlement_ym' => $settlementYm !== '' ? $settlementYm : null,
                ':old_amount' => $amounts['old_amount'],
                ':new_amount' => $amounts['new_amount'],
                ':delta_amount' => $amounts['delta_amount'],
                ':old_data' => $oldData,
                ':new_data' => $newData,
                ':actor_employee_id' => $actor['id'],
                ':actor_name' => $actor['name'] !== '' ? $actor['name'] : null,
                ':actor_department' => $actor['department'] !== '' ? $actor['department'] : null,
                ':related_request_id' => isset($options['related_request_id']) && (int)$options['related_request_id'] > 0 ? (int)$options['related_request_id'] : null,
                ':reason' => $reasonText !== '' ? self::truncate(self::sanitizeText($reasonText), 500) : null,
                ':source_file' => $sourceFile !== '' ? self::truncate($sourceFile, 255) : null,
                ':event_at' => $eventAt,
                ':created_at' => date('Y-m-d H:i:s')
            ))) return array('ok' => false, 'skipped' => true, 'reason' => 'record_failed');
            $eventId = (int)$pdo->lastInsertId();
            $syncOptions = $options;
            $syncOptions['event_action'] = $action;
            $syncOptions['source_type'] = $source;
            $syncOptions['data_origin'] = $dataOrigin;
            $syncOptions['cost_type'] = $costType;
            $syncOptions['actual_date'] = $actualDate;
            $syncOptions['settlement_ym'] = $settlementYm;
            $syncOptions['event_at'] = $eventAt;
            AiCostDataGovernanceService::syncEvent($pdo, $eventId, $syncOptions);
            return array('ok' => true, 'skipped' => false, 'id' => $eventId, 'data_origin' => $dataOrigin);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') return array('ok' => true, 'skipped' => true, 'reason' => 'duplicate');
            error_log('[CostDataEvent] event record failed');
            return array('ok' => false, 'skipped' => true, 'reason' => 'record_failed');
        } catch (Exception $e) {
            error_log('[CostDataEvent] event record failed');
            return array('ok' => false, 'skipped' => true, 'reason' => 'record_failed');
        }
    }

    private static function buildWhere($filters, &$params)
    {
        $filters = is_array($filters) ? $filters : array();
        $params = array();
        $where = array('1=1');
        $startDate = self::validDate(isset($filters['start_date']) ? $filters['start_date'] : '');
        $endDate = self::validDate(isset($filters['end_date']) ? $filters['end_date'] : '');
        if ($startDate !== '') { $where[] = 'event_at >= :start_at'; $params[':start_at'] = $startDate . ' 00:00:00'; }
        if ($endDate !== '') { $where[] = 'event_at <= :end_at'; $params[':end_at'] = $endDate . ' 23:59:59'; }
        if (isset($filters['project_id']) && (int)$filters['project_id'] > 0) { $where[] = 'project_id = :project_id'; $params[':project_id'] = (int)$filters['project_id']; }
        foreach (array('cost_type', 'event_action', 'source_type') as $key) {
            if (isset($filters[$key]) && trim((string)$filters[$key]) !== '') { $where[] = $key . ' = :' . $key; $params[':' . $key] = trim((string)$filters[$key]); }
        }
        if (isset($filters['actor_name']) && trim((string)$filters['actor_name']) !== '') { $where[] = 'actor_name LIKE :actor_name'; $params[':actor_name'] = '%' . trim((string)$filters['actor_name']) . '%'; }
        if (isset($filters['related_request_id']) && (int)$filters['related_request_id'] > 0) { $where[] = 'related_request_id = :related_request_id'; $params[':related_request_id'] = (int)$filters['related_request_id']; }
        return implode(' AND ', $where);
    }

    private static function bindParams($st, $params)
    {
        foreach ($params as $key => $value) {
            $st->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }

    public static function listEvents($pdo, $filters, $page, $perPage)
    {
        $pdo = self::pdo($pdo);
        $page = max(1, (int)$page);
        $perPage = max(1, min(100, (int)$perPage));
        if (!$pdo || !self::isInstalled($pdo)) return array();
        $params = array();
        $where = self::buildWhere($filters, $params);
        $offset = ($page - 1) * $perPage;
        try {
            $sql = 'SELECT id,event_uid,batch_key,project_id,project_name_snapshot,cost_type,target_type,target_id,event_action,source_type,'
                . 'actual_date,settlement_ym,old_amount,new_amount,delta_amount,old_data,new_data,actor_employee_id,actor_name,actor_department,'
                . 'related_request_id,reason,source_file,event_at FROM `' . self::TABLE_NAME . '` WHERE ' . $where
                . ' ORDER BY event_at DESC, id DESC LIMIT :limit OFFSET :offset';
            $st = $pdo->prepare($sql);
            self::bindParams($st, $params);
            $st->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $st->bindValue(':offset', $offset, PDO::PARAM_INT);
            $st->execute();
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return array();
        }
    }

    public static function countEvents($pdo, $filters)
    {
        $pdo = self::pdo($pdo);
        if (!$pdo || !self::isInstalled($pdo)) return 0;
        $params = array();
        $where = self::buildWhere($filters, $params);
        try {
            $st = $pdo->prepare('SELECT COUNT(*) FROM `' . self::TABLE_NAME . '` WHERE ' . $where);
            self::bindParams($st, $params);
            $st->execute();
            return (int)$st->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    public static function summary($pdo)
    {
        $empty = array('total_count' => 0, 'today_count' => 0, 'recent_30_count' => 0, 'create_count' => 0, 'update_count' => 0, 'delete_count' => 0, 'last_event_at' => '');
        $pdo = self::pdo($pdo);
        if (!$pdo || !self::isInstalled($pdo)) return $empty;
        try {
            $sql = "SELECT COUNT(*) AS total_count,"
                . " COALESCE(SUM(CASE WHEN event_at >= CURDATE() THEN 1 ELSE 0 END),0) AS today_count,"
                . " COALESCE(SUM(CASE WHEN event_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END),0) AS recent_30_count,"
                . " COALESCE(SUM(CASE WHEN event_action='CREATE' THEN 1 ELSE 0 END),0) AS create_count,"
                . " COALESCE(SUM(CASE WHEN event_action IN ('UPDATE','ADJUST','RESTORE') THEN 1 ELSE 0 END),0) AS update_count,"
                . " COALESCE(SUM(CASE WHEN event_action='DELETE' THEN 1 ELSE 0 END),0) AS delete_count,"
                . " MAX(event_at) AS last_event_at FROM `" . self::TABLE_NAME . '`';
            $statement = $pdo->query($sql);
            if (!$statement) {
                return $empty;
            }
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? array_merge($empty, $row) : $empty;
        } catch (Exception $e) {
            return $empty;
        }
    }

    public static function filterOptions($pdo)
    {
        $result = array('projects' => array(), 'cost_types' => array('labor','material','equipment','outsourcing','safety','health','other'), 'event_actions' => array('CREATE','UPDATE','DELETE','RESTORE','ADJUST'), 'source_types' => array('DIRECT','EXCEL','ATTENDANCE','APPROVAL','ADMIN_FORCE','AUTO_CALC','SYSTEM'));
        $pdo = self::pdo($pdo);
        if (!$pdo || !self::isInstalled($pdo)) return $result;
        try {
            $sql = 'SELECT project_id, MAX(project_name_snapshot) AS project_name FROM `' . self::TABLE_NAME . '` WHERE project_id IS NOT NULL GROUP BY project_id ORDER BY project_name ASC, project_id ASC';
            $result['projects'] = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $result['projects'] = array();
        }
        return $result;
    }

    public static function schemaStatus($pdo = null)
    {
        $pdo = self::pdo($pdo);
        $result = array('db_available' => (bool)$pdo, 'table_exists' => false, 'installed' => false, 'missing_columns' => array(), 'missing_indexes' => array(), 'row_count' => 0, 'last_event_at' => '');
        if (!$pdo) return $result;
        $result['table_exists'] = self::tableExists($pdo, self::TABLE_NAME);
        if (!$result['table_exists']) {
            $result['missing_columns'] = self::requiredColumns();
            $result['missing_indexes'] = self::requiredIndexes();
            return $result;
        }
        foreach (self::requiredColumns() as $column) if (!self::columnExists($pdo, self::TABLE_NAME, $column)) $result['missing_columns'][] = $column;
        $indexes = self::getTableIndexes($pdo, self::TABLE_NAME);
        foreach (self::requiredIndexes() as $index) if (!isset($indexes[$index])) $result['missing_indexes'][] = $index;
        $result['installed'] = (count($result['missing_columns']) === 0 && count($result['missing_indexes']) === 0);
        if (count($result['missing_columns']) === 0) {
            $summary = self::summary($pdo);
            $result['row_count'] = (int)$summary['total_count'];
            $result['last_event_at'] = (string)$summary['last_event_at'];
        }
        return $result;
    }
}
