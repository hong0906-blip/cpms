<?php
/**
 * CEO Index - final monthly cost forecast V2.
 *
 * Forecast policy:
 * - Historical finalized CPMS amounts are used as amount-learning data.
 * - Timing completion rates remain visible even when direct projection is blocked.
 * - current / completion direct projection is allowed only with reliable timing data.
 * - Zero-input categories without current-month activity do not copy historical/company amounts into the point forecast.
 * - Bid/contract/completed projects without current-month activity are excluded from the point forecast.
 *
 * PHP 5.6 / MySQL 5.6 compatible.
 */
namespace App\Services;

use App\Core\Db;
use App\Core\Auth;
use PDO;
use Exception;

require_once __DIR__ . '/AiInputCompletionPatternService.php';
require_once __DIR__ . '/AiCostProjectionRiskService.php';
require_once __DIR__ . '/AiMonthlyForecastService.php';
require_once __DIR__ . '/AiCostDataGovernanceService.php';
require_once __DIR__ . '/AiProjectTypeService.php';
require_once __DIR__ . '/AiForecastAccuracyService.php';

class AiCostForecastV2Service
{
    const RUN_TABLE = 'cpms_ai_cost_forecast_runs_v2';
    const RESULT_TABLE = 'cpms_ai_cost_forecast_results_v2';
    const CATEGORY_TABLE = 'cpms_ai_cost_forecast_category_results_v2';
    const SNAPSHOT_TABLE = 'cpms_ai_daily_snapshots';
    const OLD_FORECAST_TABLE = 'cpms_ai_monthly_forecasts';
    const EVENT_TABLE = 'cpms_cost_data_events';
    const CALCULATION_VERSION = 'COST_FORECAST_V2';

    private static $tableCache = array();

    public static function pdo($pdo = null)
    {
        return $pdo ? $pdo : Db::pdo();
    }

    private static function key($pdo)
    {
        return is_object($pdo) ? spl_object_hash($pdo) : 'none';
    }

    public static function tableExists($pdo, $table)
    {
        if (!$pdo || !preg_match('/^[A-Za-z0-9_]+$/', (string)$table)) return false;
        $key = self::key($pdo) . ':' . $table;
        if (array_key_exists($key, self::$tableCache)) return self::$tableCache[$key];
        try {
            $st = $pdo->prepare('SHOW TABLES LIKE :table');
            $ok = $st && $st->execute(array(':table'=>$table)) && $st->fetchColumn() !== false;
            self::$tableCache[$key] = $ok;
            return $ok;
        } catch (Exception $e) {
            self::$tableCache[$key] = false;
            return false;
        }
    }

    public static function categories()
    {
        return AiInputCompletionPatternService::categories();
    }

    public static function categoryLabels()
    {
        return array(
            'labor'=>'노무비',
            'outsourcing'=>'외주비',
            'purchase'=>'구매품',
            'material'=>'자재비',
            'equipment'=>'장비비',
            'other_expense'=>'기타경비',
            'safety'=>'안전관리비',
            'other'=>'기타 투입비'
        );
    }

    private static function encode($value)
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : null;
    }

    public static function decode($value)
    {
        if (!is_string($value) || trim($value) === '') return array();
        $data = json_decode($value, true);
        return is_array($data) ? $data : array();
    }

    public static function businessToday()
    {
        return CostChangeService::businessToday();
    }

    public static function createRunTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS cpms_ai_cost_forecast_runs_v2 (\n"
            . " id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,\n"
            . " run_uid VARCHAR(64) NOT NULL,\n"
            . " analysis_date DATE NOT NULL,\n"
            . " target_ym CHAR(7) NOT NULL,\n"
            . " snapshot_date DATE NULL,\n"
            . " trigger_type VARCHAR(20) NOT NULL,\n"
            . " run_status VARCHAR(20) NOT NULL,\n"
            . " calculation_version VARCHAR(40) NOT NULL,\n"
            . " source_fingerprint CHAR(64) NOT NULL,\n"
            . " project_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . " success_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . " failure_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . " insufficient_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . " current_input_total DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . " final_forecast_total DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . " forecast_low_total DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . " forecast_high_total DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . " actor_employee_id INT NULL,\n"
            . " actor_name VARCHAR(100) NULL,\n"
            . " started_at DATETIME NOT NULL,\n"
            . " finished_at DATETIME NULL,\n"
            . " error_summary VARCHAR(500) NULL,\n"
            . " created_at DATETIME NOT NULL,\n"
            . " UNIQUE KEY uk_ai_cost_forecast_v2_run_uid (run_uid),\n"
            . " KEY idx_ai_cost_forecast_v2_run_date (analysis_date,started_at),\n"
            . " KEY idx_ai_cost_forecast_v2_run_status (run_status,started_at),\n"
            . " KEY idx_ai_cost_forecast_v2_run_source (source_fingerprint)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    public static function createResultTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS cpms_ai_cost_forecast_results_v2 (\n"
            . " id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,\n"
            . " run_id BIGINT UNSIGNED NULL,\n"
            . " analysis_date DATE NOT NULL,\n"
            . " target_ym CHAR(7) NOT NULL,\n"
            . " snapshot_date DATE NOT NULL,\n"
            . " project_id INT UNSIGNED NOT NULL,\n"
            . " project_name_snapshot VARCHAR(190) NULL,\n"
            . " project_status_snapshot VARCHAR(50) NULL,\n"
            . " current_input_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . " expected_completion_rate DECIMAL(8,3) NULL,\n"
            . " expected_unentered_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . " final_forecast_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . " forecast_low_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . " forecast_high_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . " forecast_confidence_score DECIMAL(6,2) NULL,\n"
            . " forecast_confidence_grade VARCHAR(20) NOT NULL,\n"
            . " forecast_method VARCHAR(50) NOT NULL,\n"
            . " fallback_level VARCHAR(40) NOT NULL,\n"
            . " sample_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . " category_analyzable_rate DECIMAL(8,3) NOT NULL DEFAULT 0,\n"
            . " cumulative_current_input_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . " cumulative_projected_input_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . " contract_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . " projected_cumulative_cost_rate DECIMAL(8,3) NULL,\n"
            . " contract_risk_grade VARCHAR(20) NOT NULL,\n"
            . " overinput_grade VARCHAR(20) NOT NULL,\n"
            . " missing_possibility_grade VARCHAR(20) NOT NULL,\n"
            . " anomaly_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . " data_status VARCHAR(30) NOT NULL,\n"
            . " calculation_version VARCHAR(40) NOT NULL,\n"
            . " source_fingerprint CHAR(64) NOT NULL,\n"
            . " method_data MEDIUMTEXT NULL,\n"
            . " warning_data MEDIUMTEXT NULL,\n"
            . " risk_data MEDIUMTEXT NULL,\n"
            . " first_created_at DATETIME NOT NULL,\n"
            . " last_calculated_at DATETIME NOT NULL,\n"
            . " calculation_count INT UNSIGNED NOT NULL DEFAULT 1,\n"
            . " created_at DATETIME NOT NULL,\n"
            . " updated_at DATETIME NOT NULL,\n"
            . " UNIQUE KEY uk_ai_cost_forecast_v2_result (analysis_date,target_ym,project_id),\n"
            . " KEY idx_ai_cost_forecast_v2_project (project_id,analysis_date),\n"
            . " KEY idx_ai_cost_forecast_v2_month (target_ym,analysis_date),\n"
            . " KEY idx_ai_cost_forecast_v2_confidence (forecast_confidence_grade,analysis_date),\n"
            . " KEY idx_ai_cost_forecast_v2_source (source_fingerprint)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    public static function createCategoryTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS cpms_ai_cost_forecast_category_results_v2 (\n"
            . " id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,\n"
            . " run_id BIGINT UNSIGNED NULL,\n"
            . " analysis_date DATE NOT NULL,\n"
            . " target_ym CHAR(7) NOT NULL,\n"
            . " snapshot_date DATE NOT NULL,\n"
            . " project_id INT UNSIGNED NOT NULL,\n"
            . " cost_type VARCHAR(40) NOT NULL,\n"
            . " current_input_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . " expected_completion_rate DECIMAL(8,3) NULL,\n"
            . " expected_unentered_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . " final_forecast_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . " forecast_low_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . " forecast_high_amount DECIMAL(18,2) NOT NULL DEFAULT 0,\n"
            . " forecast_confidence_score DECIMAL(6,2) NULL,\n"
            . " forecast_confidence_grade VARCHAR(20) NOT NULL,\n"
            . " forecast_method VARCHAR(50) NOT NULL,\n"
            . " fallback_level VARCHAR(40) NOT NULL,\n"
            . " sample_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . " event_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . " average_input_lag_days DECIMAL(8,3) NULL,\n"
            . " completion_volatility DECIMAL(8,3) NULL,\n"
            . " late_bulk_rate DECIMAL(8,3) NULL,\n"
            . " correction_rate DECIMAL(8,3) NULL,\n"
            . " month_move_rate DECIMAL(8,3) NULL,\n"
            . " overinput_grade VARCHAR(20) NOT NULL,\n"
            . " missing_possibility_grade VARCHAR(20) NOT NULL,\n"
            . " data_status VARCHAR(30) NOT NULL,\n"
            . " calculation_version VARCHAR(40) NOT NULL,\n"
            . " candidate_data MEDIUMTEXT NULL,\n"
            . " anomaly_type_data MEDIUMTEXT NULL,\n"
            . " first_created_at DATETIME NOT NULL,\n"
            . " last_calculated_at DATETIME NOT NULL,\n"
            . " calculation_count INT UNSIGNED NOT NULL DEFAULT 1,\n"
            . " created_at DATETIME NOT NULL,\n"
            . " updated_at DATETIME NOT NULL,\n"
            . " UNIQUE KEY uk_ai_cost_forecast_v2_category (analysis_date,target_ym,project_id,cost_type),\n"
            . " KEY idx_ai_cost_forecast_v2_category_project (project_id,analysis_date),\n"
            . " KEY idx_ai_cost_forecast_v2_category_type (cost_type,analysis_date),\n"
            . " KEY idx_ai_cost_forecast_v2_category_run (run_id)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    public static function requiredTables()
    {
        $resultSql = str_replace(
            'UNIQUE KEY uk_ai_cost_forecast_v2_result (analysis_date,target_ym,project_id)',
            'UNIQUE KEY uk_ai_cost_forecast_v2_result (run_id,project_id)',
            self::createResultTableSql()
        );
        $categorySql = str_replace(
            'UNIQUE KEY uk_ai_cost_forecast_v2_category (analysis_date,target_ym,project_id,cost_type)',
            'UNIQUE KEY uk_ai_cost_forecast_v2_category (run_id,project_id,cost_type)',
            self::createCategoryTableSql()
        );
        return array(
            self::RUN_TABLE=>self::createRunTableSql(),
            self::RESULT_TABLE=>$resultSql,
            self::CATEGORY_TABLE=>$categorySql
        );
    }

    public static function columnExists($pdo, $table, $column)
    {
        if (!$pdo || !preg_match('/^[A-Za-z0-9_]+$/', (string)$table) || !preg_match('/^[A-Za-z0-9_]+$/', (string)$column)) return false;
        try {
            $st = $pdo->prepare('SHOW COLUMNS FROM `' . $table . '` LIKE :column');
            return $st && $st->execute(array(':column'=>$column)) && $st->fetch(PDO::FETCH_ASSOC) !== false;
        } catch (Exception $e) {
            return false;
        }
    }

    private static function indexColumns($pdo, $table, $index)
    {
        $cols = array();
        try {
            $st = $pdo->prepare('SHOW INDEX FROM `' . $table . '` WHERE Key_name=:name');
            if (!$st || !$st->execute(array(':name'=>$index))) return $cols;
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            usort($rows, array(__CLASS__, 'compareIndexSequence'));
            foreach ($rows as $row) $cols[] = (string)$row['Column_name'];
        } catch (Exception $e) {
        }
        return $cols;
    }

    public static function compareIndexSequence($a, $b)
    {
        $as = isset($a['Seq_in_index']) ? (int)$a['Seq_in_index'] : 0;
        $bs = isset($b['Seq_in_index']) ? (int)$b['Seq_in_index'] : 0;
        if ($as === $bs) return 0;
        return $as < $bs ? -1 : 1;
    }

    private static function ensureAppendOnlyIndex($pdo, $table, $index, $columns)
    {
        $current = self::indexColumns($pdo, $table, $index);
        if ($current === $columns) return true;
        $quoted = array();
        foreach ($columns as $column) $quoted[] = '`' . $column . '`';
        $alter = 'ALTER TABLE `' . $table . '` ';
        if (count($current) > 0) $alter .= 'DROP INDEX `' . $index . '`, ';
        $alter .= 'ADD UNIQUE KEY `' . $index . '` (' . implode(',', $quoted) . ')';
        return $pdo->exec($alter) !== false;
    }

    public static function installOrUpdate($pdo = null)
    {
        $pdo = self::pdo($pdo);
        if (!$pdo) return array('ok'=>false,'message'=>'DB 연결 상태를 확인할 수 없습니다.');
        try {
            foreach (self::requiredTables() as $table=>$sql) {
                if ($pdo->exec($sql) === false) return array('ok'=>false,'message'=>'V2 예측 테이블을 설치하지 못했습니다.');
            }
            $resultColumns = array(
                'analysis_datetime'=>'DATETIME NULL',
                'amount_pattern_month_count'=>'INT UNSIGNED NOT NULL DEFAULT 0',
                'timing_pattern_month_count'=>'INT UNSIGNED NOT NULL DEFAULT 0',
                'live_input_sample_count'=>'INT UNSIGNED NOT NULL DEFAULT 0',
                'data_origin_summary'=>'MEDIUMTEXT NULL',
                'new_site_flag'=>'TINYINT(1) NOT NULL DEFAULT 0',
                'contract_change_flag'=>'TINYINT(1) NOT NULL DEFAULT 0',
                'schedule_change_flag'=>'TINYINT(1) NOT NULL DEFAULT 0',
                'similar_project_sample_count'=>'INT UNSIGNED NOT NULL DEFAULT 0'
            );
            $categoryColumns = $resultColumns;
            foreach (array(self::RESULT_TABLE=>$resultColumns, self::CATEGORY_TABLE=>$categoryColumns) as $table=>$columns) {
                foreach ($columns as $column=>$definition) {
                    if (!self::columnExists($pdo, $table, $column)) {
                        if ($pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition) === false) {
                            return array('ok'=>false,'message'=>'예측 버전 보존용 컬럼을 추가하지 못했습니다.');
                        }
                    }
                }
            }
            if (!self::ensureAppendOnlyIndex($pdo, self::RESULT_TABLE, 'uk_ai_cost_forecast_v2_result', array('run_id','project_id'))) {
                return array('ok'=>false,'message'=>'현장 예측 실행별 보존 인덱스를 구성하지 못했습니다.');
            }
            if (!self::ensureAppendOnlyIndex($pdo, self::CATEGORY_TABLE, 'uk_ai_cost_forecast_v2_category', array('run_id','project_id','cost_type'))) {
                return array('ok'=>false,'message'=>'비용항목 예측 실행별 보존 인덱스를 구성하지 못했습니다.');
            }
            self::$tableCache = array();
            return array(
                'ok'=>self::isInstalled($pdo),
                'message'=>self::isInstalled($pdo) ? 'V2 예측 테이블과 실행별 보존 구조를 확인했습니다.' : 'V2 예측 테이블 구조를 확인해주세요.'
            );
        } catch (Exception $e) {
            error_log('[AI Forecast V2] install failed: ' . $e->getMessage());
            return array('ok'=>false,'message'=>'V2 예측 테이블 설치를 확인하지 못했습니다.');
        }
    }

    public static function isInstalled($pdo = null)
    {
        $pdo = self::pdo($pdo);
        if (!$pdo) return false;
        foreach (array_keys(self::requiredTables()) as $table) {
            if (!self::tableExists($pdo, $table)) return false;
        }
        return true;
    }

    public static function schemaStatus($pdo = null)
    {
        $pdo = self::pdo($pdo);
        $s = array('db_available'=>(bool)$pdo,'installed'=>false,'latest_result'=>array(),'latest_run'=>array(),'result_count'=>0);
        if (!$pdo) return $s;
        $s['installed'] = self::isInstalled($pdo);
        if (!$s['installed']) return $s;
        try {
            $st = $pdo->query('SELECT * FROM `' . self::RESULT_TABLE . '` ORDER BY analysis_date DESC,id DESC LIMIT 1');
            $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
            $s['latest_result'] = is_array($row) ? $row : array();
            $st = $pdo->query('SELECT * FROM `' . self::RUN_TABLE . '` ORDER BY started_at DESC,id DESC LIMIT 1');
            $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
            $s['latest_run'] = is_array($row) ? $row : array();
            $st = $pdo->query('SELECT COUNT(*) FROM `' . self::RESULT_TABLE . '`');
            $s['result_count'] = $st ? (int)$st->fetchColumn() : 0;
        } catch (Exception $e) {
        }
        return $s;
    }

    public static function weightedMedian($candidates)
    {
        $rows = array();
        $total = 0.0;
        foreach ((array)$candidates as $row) {
            if (!isset($row['value']) || !is_numeric($row['value'])) continue;
            $weight = isset($row['weight']) ? max(0, (float)$row['weight']) : 1;
            if ($weight <= 0) continue;
            $row['value'] = (float)$row['value'];
            $row['weight'] = $weight;
            $rows[] = $row;
            $total += $weight;
        }
        if (count($rows) === 0) return null;
        usort($rows, array(__CLASS__, 'compareCandidate'));
        $running = 0.0;
        foreach ($rows as $row) {
            $running += $row['weight'];
            if ($running >= $total / 2) return $row['value'];
        }
        return $rows[count($rows) - 1]['value'];
    }

    public static function compareCandidate($a, $b)
    {
        if ($a['value'] == $b['value']) return 0;
        return $a['value'] < $b['value'] ? -1 : 1;
    }

    private static function clamp($value, $min, $max)
    {
        return max((float)$min, min((float)$max, (float)$value));
    }

    public static function confidenceGrade($score)
    {
        if ($score === null) return 'INSUFFICIENT';
        if ($score >= 85) return 'HIGH';
        if ($score >= 70) return 'MEDIUM';
        if ($score >= 50) return 'LOW';
        return 'VERY_LOW';
    }

    public static function adjustConfidence($score, $factors)
    {
        if ($score === null) return null;
        $factors = is_array($factors) ? $factors : array();
        $score = (float)$score;
        $live = isset($factors['live_input_sample_count']) ? (int)$factors['live_input_sample_count'] : 0;
        $operational = isset($factors['operational_input_sample_count']) ? (int)$factors['operational_input_sample_count'] : $live;
        $timing = isset($factors['timing_pattern_month_count']) ? (int)$factors['timing_pattern_month_count'] : 0;
        $amount = isset($factors['amount_pattern_month_count']) ? (int)$factors['amount_pattern_month_count'] : 0;
        $work = isset($factors['work_pattern_month_count']) ? (int)$factors['work_pattern_month_count'] : 0;
        $similar = isset($factors['similar_project_sample_count']) ? (int)$factors['similar_project_sample_count'] : 0;

        /*
         * 입력시점 자료가 없더라도 노무비의 날짜별 상세공수 패턴이 있으면
         * "분석불가"로 떨어뜨리지 않는다. 다만 실제 입력지연 패턴과는 별개이므로 신뢰도 상한을 둔다.
         */
        if ($timing <= 0) {
            if ($work >= 3 && $amount >= 3) $score = min($score, 79);
            else if ($work === 2 && $amount > 0) $score = min($score, 69);
            else if ($work === 1 && $amount > 0) $score = min($score, 59);
            else $score = min($score, 49);
        } else if ($timing === 1) {
            $score = min($score, $work >= 2 ? 69 : 59);
        } else if ($timing === 2) {
            $score = min($score, $work >= 2 ? 79 : 69);
        }

        if ($operational <= 0 && $timing <= 0 && $work <= 0) $score = min($score, 49);
        if ($amount <= 0) $score -= 10;
        if (!empty($factors['new_site_flag'])) $score -= 10;
        if (!empty($factors['contract_change_flag'])) $score -= 10;
        if (!empty($factors['schedule_change_flag'])) $score -= 8;
        if ($similar <= 0 && $work <= 0) $score -= 5;
        else if ($similar >= 3) $score += 3;
        if ($work >= 3) $score += 4;
        else if ($work >= 1) $score += 2;

        $correction = isset($factors['recent_correction_ratio']) ? (float)$factors['recent_correction_ratio'] : 0;
        if ($correction >= 0.25) $score -= 20;
        else if ($correction >= 0.10) $score -= 10;
        else if ($correction >= 0.05) $score -= 5;
        return round(max(0, min(100, $score)), 2);
    }

    private static function confidence($pattern, $progressRate, $analyzableRate)
    {
        $scores = array();
        $weights = array();
        $months = isset($pattern['sample_month_count']) ? (int)$pattern['sample_month_count'] : 0;
        $scores[] = min(100, $months / 6 * 100);
        $weights[] = 25;
        if (isset($pattern['expected_completion_rate']) && $pattern['expected_completion_rate'] !== null) {
            $scores[] = self::clamp($pattern['expected_completion_rate'], 0, 100);
            $weights[] = 20;
        }
        if (isset($pattern['completion_volatility']) && $pattern['completion_volatility'] !== null) {
            $scores[] = self::clamp(100 - (float)$pattern['completion_volatility'] * 2, 0, 100);
            $weights[] = 20;
        }
        $scores[] = self::clamp(100 - abs(100 - (float)$progressRate) * 0.25, 40, 100);
        $weights[] = 15;
        $scores[] = self::clamp($analyzableRate, 0, 100);
        $weights[] = 10;
        if (isset($pattern['correction_rate']) && $pattern['correction_rate'] !== null) {
            $lateBulk = isset($pattern['late_bulk_rate']) && $pattern['late_bulk_rate'] !== null ? (float)$pattern['late_bulk_rate'] : 0.0;
            $scores[] = self::clamp(100 - (float)$pattern['correction_rate'] - $lateBulk * 0.5, 0, 100);
            $weights[] = 10;
        }
        $available = array_sum($weights);
        if ($available < 60) return null;
        $sum = 0.0;
        for ($i = 0; $i < count($scores); $i++) $sum += $scores[$i] * $weights[$i];
        return round($sum / $available, 2);
    }

    private static function amountOnlyConfidence($amountMonths, $current, $hasActivity)
    {
        $amountMonths = max(0, (int)$amountMonths);
        if ($amountMonths <= 0 && (float)$current <= 0 && !$hasActivity) return null;
        $score = 20 + min(5, $amountMonths) * 5;
        if ((float)$current > 0) $score += 5;
        if ($hasActivity) $score += 4;
        return (float)min(49, $score);
    }

    private static function workPatternConfidence($amountMonths, $workMonths, $current, $hasActivity)
    {
        $amountMonths = max(0, (int)$amountMonths);
        $workMonths = max(0, (int)$workMonths);
        if ($workMonths <= 0) return self::amountOnlyConfidence($amountMonths, $current, $hasActivity);
        $score = 34 + min(5, $amountMonths) * 4 + min(5, $workMonths) * 6;
        if ((float)$current > 0) $score += 5;
        if ($hasActivity) $score += 3;
        if ($workMonths === 1) $score = min($score, 59);
        else if ($workMonths === 2) $score = min($score, 69);
        else $score = min($score, 79);
        return (float)max(0, $score);
    }

    private static function sourceFingerprint($context, $snapshots, $patterns)
    {
        return hash('sha256', self::encode(array(
            'context'=>$context,
            'snapshots'=>$snapshots,
            'patterns'=>$patterns,
            'version'=>self::CALCULATION_VERSION
        )));
    }

    private static function latestSnapshotContext($pdo)
    {
        $empty = array('available'=>false,'snapshot_date'=>'','target_ym'=>'');
        if (!self::tableExists($pdo, self::SNAPSHOT_TABLE)) return $empty;
        try {
            $st = $pdo->query('SELECT snapshot_date,target_ym FROM `' . self::SNAPSHOT_TABLE . '` ORDER BY snapshot_date DESC,id DESC LIMIT 1');
            $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
            if (!is_array($row)) return $empty;
            return array(
                'available'=>true,
                'snapshot_date'=>(string)$row['snapshot_date'],
                'analysis_date'=>(string)$row['snapshot_date'],
                'target_ym'=>(string)$row['target_ym']
            );
        } catch (Exception $e) {
            return $empty;
        }
    }

    /**
     * Current-month input events grouped by project and cost type.
     * This is used only to decide whether a zero-input category has real activity.
     */
    private static function currentActivityMap($pdo, $context)
    {
        $map = array();
        if (!$pdo || !self::tableExists($pdo, self::EVENT_TABLE)) return $map;
        foreach (array('project_id','cost_type','settlement_ym','event_action') as $column) {
            if (!self::columnExists($pdo, self::EVENT_TABLE, $column)) return $map;
        }
        try {
            $sql = 'SELECT project_id,cost_type,COUNT(*) AS event_count FROM `' . self::EVENT_TABLE . '` '
                . 'WHERE settlement_ym=:ym AND event_action<>\'DELETE\' GROUP BY project_id,cost_type';
            $st = $pdo->prepare($sql);
            if (!$st || !$st->execute(array(':ym'=>$context['target_ym']))) return $map;
            foreach ((array)$st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $projectId = isset($row['project_id']) ? (int)$row['project_id'] : 0;
                $costType = isset($row['cost_type']) ? strtolower(trim((string)$row['cost_type'])) : '';
                if ($costType === 'health') $costType = 'safety';
                if ($projectId <= 0 || $costType === '') continue;
                if (!isset($map[$projectId])) $map[$projectId] = array();
                $map[$projectId][$costType] = (isset($map[$projectId][$costType]) ? (int)$map[$projectId][$costType] : 0)
                    + (isset($row['event_count']) ? (int)$row['event_count'] : 0);
            }
        } catch (Exception $e) {
            return array();
        }
        return $map;
    }

    private static function loadSnapshots($pdo, $context)
    {
        $cols = array();
        foreach (array_values(self::categories()) as $column) $cols[] = 's.' . $column;
        /* 기존 스냅샷의 health_amount는 별도 비용항목으로 노출하지 않고 안전관리비에 합친다. */
        $cols[] = 's.health_amount';
        $sql = 'SELECT s.snapshot_date,s.target_ym,s.project_id,s.project_name_snapshot,s.project_status_snapshot,'
            . 's.contract_amount,s.cumulative_input_amount,s.monthly_input_amount,s.today_event_count,s.month_event_count,s.latest_event_at,'
            . implode(',', $cols)
            . ',p.start_date AS project_start_date,p.end_date AS project_end_date '
            . 'FROM `' . self::SNAPSHOT_TABLE . '` s LEFT JOIN cpms_projects p ON p.id=s.project_id '
            . 'WHERE s.snapshot_date=:date AND s.target_ym=:ym ORDER BY s.project_id';
        try {
            $st = $pdo->prepare($sql);
            if (!$st || !$st->execute(array(':date'=>$context['snapshot_date'], ':ym'=>$context['target_ym']))) return array();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows)) return array();
            $activityMap = self::currentActivityMap($pdo, $context);
            foreach ($rows as $index=>$row) {
                $projectId = isset($row['project_id']) ? (int)$row['project_id'] : 0;
                $rows[$index]['safety_amount'] = (isset($row['safety_amount']) ? (float)$row['safety_amount'] : 0.0)
                    + (isset($row['health_amount']) ? (float)$row['health_amount'] : 0.0);
                $rows[$index]['health_amount'] = 0.0;
                $rows[$index]['_category_activity'] = isset($activityMap[$projectId]) ? $activityMap[$projectId] : array();
                $activityTotal = 0;
                foreach ($rows[$index]['_category_activity'] as $count) $activityTotal += (int)$count;
                $rows[$index]['_project_activity_count'] = $activityTotal;
            }
            return $rows;
        } catch (Exception $e) {
            return array();
        }
    }

    private static function oldForecastMap($pdo, $context)
    {
        $map = array();
        if (!self::tableExists($pdo, self::OLD_FORECAST_TABLE)) return $map;
        try {
            $sql = 'SELECT project_id,category_forecast_data FROM `' . self::OLD_FORECAST_TABLE . '` '
                . 'WHERE target_ym=:ym AND forecast_date=(SELECT MAX(forecast_date) FROM `' . self::OLD_FORECAST_TABLE . '` WHERE target_ym=:ym2)';
            $st = $pdo->prepare($sql);
            if (!$st || !$st->execute(array(':ym'=>$context['target_ym'], ':ym2'=>$context['target_ym']))) return $map;
            foreach ((array)$st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $map[(int)$row['project_id']] = self::decode($row['category_forecast_data']);
            }
        } catch (Exception $e) {
        }
        return $map;
    }

    private static function recentPaceMap($pdo, $context)
    {
        $map = array();
        $start = date('Y-m-d', strtotime($context['snapshot_date'] . ' -14 days'));
        $cols = array_values(self::categories());
        if (!in_array('health_amount', $cols, true)) $cols[] = 'health_amount';
        try {
            $sql = 'SELECT snapshot_date,project_id,' . implode(',', $cols) . ' FROM `' . self::SNAPSHOT_TABLE . '` '
                . 'WHERE target_ym=:ym AND snapshot_date>=:start AND snapshot_date<=:end ORDER BY project_id,snapshot_date';
            $st = $pdo->prepare($sql);
            if (!$st || !$st->execute(array(':ym'=>$context['target_ym'], ':start'=>$start, ':end'=>$context['snapshot_date']))) return $map;
            foreach ((array)$st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $projectId = (int)$row['project_id'];
                $row['safety_amount'] = (isset($row['safety_amount']) ? (float)$row['safety_amount'] : 0.0)
                    + (isset($row['health_amount']) ? (float)$row['health_amount'] : 0.0);
                $row['health_amount'] = 0.0;
                if (!isset($map[$projectId])) $map[$projectId] = array('first'=>$row,'last'=>$row);
                $map[$projectId]['last'] = $row;
            }
        } catch (Exception $e) {
        }
        return $map;
    }

    private static function projectChangeFlags($pdo, $projectId, $analysisDate)
    {
        $flags = array('contract_change_flag'=>0,'schedule_change_flag'=>0);
        $projectId = (int)$projectId;
        if (!$pdo || $projectId <= 0) return $flags;
        try {
            if (self::tableExists($pdo, 'cpms_contract_change_logs')) {
                $st = $pdo->prepare('SELECT COUNT(*) FROM cpms_contract_change_logs WHERE project_id=:project');
                if ($st && $st->execute(array(':project'=>$projectId)) && (int)$st->fetchColumn() > 0) $flags['contract_change_flag'] = 1;
            } else if (self::tableExists($pdo, 'cpms_contract_versions')) {
                $st = $pdo->prepare('SELECT COUNT(*) FROM cpms_contract_versions WHERE project_id=:project');
                if ($st && $st->execute(array(':project'=>$projectId)) && (int)$st->fetchColumn() > 1) $flags['contract_change_flag'] = 1;
            }
            if (self::tableExists($pdo, 'cpms_schedule_tasks') && self::columnExists($pdo, 'cpms_schedule_tasks', 'updated_at')) {
                $start = date('Y-m-d 00:00:00', strtotime($analysisDate . ' -30 days'));
                $end = $analysisDate . ' 23:59:59';
                $st = $pdo->prepare('SELECT COUNT(*) FROM cpms_schedule_tasks WHERE project_id=:project AND updated_at>=:start AND updated_at<=:end');
                if ($st && $st->execute(array(':project'=>$projectId, ':start'=>$start, ':end'=>$end)) && (int)$st->fetchColumn() > 0) {
                    $flags['schedule_change_flag'] = 1;
                }
            }
        } catch (Exception $e) {
        }
        return $flags;
    }

    private static function recentCorrectionRatio($pdo, $projectId, $targetYm, $analysisDate, $baseAmount)
    {
        if (!$pdo || $baseAmount <= 0 || !self::tableExists($pdo, self::EVENT_TABLE) || !self::columnExists($pdo, self::EVENT_TABLE, 'data_origin')) return 0.0;
        try {
            $start = date('Y-m-d 00:00:00', strtotime($analysisDate . ' -60 days'));
            $end = $analysisDate . ' 23:59:59';
            $st = $pdo->prepare(
                'SELECT SUM(ABS(COALESCE(delta_amount,0))) FROM ' . self::EVENT_TABLE
                . ' WHERE project_id=:project AND settlement_ym=:ym AND data_origin IN (\'ADMIN_CORRECTION\',\'RE_ENTRY\')'
                . ' AND event_at>=:start AND event_at<=:end'
            );
            if (!$st || !$st->execute(array(':project'=>(int)$projectId, ':ym'=>$targetYm, ':start'=>$start, ':end'=>$end))) return 0.0;
            $amount = $st->fetchColumn();
            return $amount === false ? 0.0 : round((float)$amount / max(1, (float)$baseAmount), 6);
        } catch (Exception $e) {
            return 0.0;
        }
    }

    private static function projectHasActivity($snapshot)
    {
        if (isset($snapshot['monthly_input_amount']) && (float)$snapshot['monthly_input_amount'] != 0.0) return true;
        if (isset($snapshot['month_event_count']) && (int)$snapshot['month_event_count'] > 0) return true;
        if (isset($snapshot['_project_activity_count']) && (int)$snapshot['_project_activity_count'] > 0) return true;
        return false;
    }

    private static function categoryHasActivity($snapshot, $cost, $current)
    {
        if ((float)$current != 0.0) return true;
        $map = isset($snapshot['_category_activity']) && is_array($snapshot['_category_activity']) ? $snapshot['_category_activity'] : array();
        if (isset($map[$cost]) && (int)$map[$cost] > 0) return true;
        return false;
    }

    private static function projectDormantForPointForecast($snapshot)
    {
        if (self::projectHasActivity($snapshot)) return false;
        $current = isset($snapshot['monthly_input_amount']) ? (float)$snapshot['monthly_input_amount'] : 0.0;
        if ($current != 0.0) return false;

        $status = isset($snapshot['project_status_snapshot']) ? trim((string)$snapshot['project_status_snapshot']) : '';
        $inactiveStatuses = array('입찰 진행중','입찰중','계약중','정산완료','완료','종료','중지','대기','삭제','테스트');
        if (in_array($status, $inactiveStatuses, true)) return true;

        $targetYm = isset($snapshot['target_ym']) ? (string)$snapshot['target_ym'] : '';
        $period = CostChangeService::periodForYm('material', $targetYm);
        $periodStart = isset($period['start']) ? strtotime($period['start']) : false;
        $periodEnd = strtotime($targetYm . '-01');
        if ($periodEnd !== false) $periodEnd = strtotime(date('Y-m-t', $periodEnd));

        $startDate = isset($snapshot['project_start_date']) ? trim((string)$snapshot['project_start_date']) : '';
        $endDate = isset($snapshot['project_end_date']) ? trim((string)$snapshot['project_end_date']) : '';
        if ($startDate !== '' && strtotime($startDate) !== false && $periodEnd !== false && strtotime($startDate) > $periodEnd) return true;
        if ($endDate !== '' && strtotime($endDate) !== false && $periodStart !== false && strtotime($endDate) < $periodStart) return true;
        return false;
    }

    private static function calculateCategory($pdo, $snapshot, $cost, $column, $pattern, $oldCategory, $pace, $projectDormant)
    {
        $current = isset($snapshot[$column]) ? max(0.0, (float)$snapshot[$column]) : 0.0;
        $progress = AiInputCompletionPatternService::progress($snapshot['snapshot_date'], $snapshot['target_ym'], $cost);
        $completion = isset($pattern['expected_completion_rate']) && $pattern['expected_completion_rate'] !== null
            ? (float)$pattern['expected_completion_rate']
            : null;
        $minRate = AiInputCompletionPatternService::minCompletionRate($pdo);
        $sample = isset($pattern['sample_month_count']) ? (int)$pattern['sample_month_count'] : 0;
        $amountMonths = isset($pattern['amount_pattern_month_count']) ? (int)$pattern['amount_pattern_month_count'] : 0;
        $learning = AiInputCompletionPatternService::learningState($sample);
        $directBlocked = !empty($pattern['direct_projection_blocked'])
            || $completion === null
            || $completion < $minRate
            || $sample < 3;
        $categoryActivity = self::categoryHasActivity($snapshot, $cost, $current);
        $projectActivity = self::projectHasActivity($snapshot);
        $candidates = array();
        $patternValue = null;
        $workPatternValue = null;
        $periodPaceValue = null;

        $detail = AiInputCompletionPatternService::decode(isset($pattern['detail_data']) ? $pattern['detail_data'] : '');
        $historical = isset($detail['historical_final_median']) && is_numeric($detail['historical_final_median'])
            ? max(0.0, (float)$detail['historical_final_median'])
            : null;
        $workOccurrenceRate = isset($detail['expected_work_occurrence_rate']) && is_numeric($detail['expected_work_occurrence_rate'])
            ? max(0.0, min(100.0, (float)$detail['expected_work_occurrence_rate']))
            : null;
        $workMonths = isset($detail['work_pattern_month_count']) ? max(0, (int)$detail['work_pattern_month_count']) : 0;
        $workVolatility = isset($detail['work_pattern_volatility']) && is_numeric($detail['work_pattern_volatility'])
            ? max(0.0, (float)$detail['work_pattern_volatility'])
            : null;
        $lagSampleCount = isset($detail['lag_sample_count']) ? max(0, (int)$detail['lag_sample_count']) : 0;
        $sameDayInputRate = isset($detail['same_day_input_rate']) && is_numeric($detail['same_day_input_rate']) ? (float)$detail['same_day_input_rate'] : null;
        $withinOneBusinessDayRate = isset($detail['within_one_business_day_rate']) && is_numeric($detail['within_one_business_day_rate']) ? (float)$detail['within_one_business_day_rate'] : null;
        $lateTwoPlusInputRate = isset($detail['late_two_plus_input_rate']) && is_numeric($detail['late_two_plus_input_rate']) ? (float)$detail['late_two_plus_input_rate'] : null;
        $inputLagBasis = isset($detail['input_lag_basis']) ? (string)$detail['input_lag_basis'] : '';
        $inputLagOrigin = isset($detail['input_lag_origin']) ? (string)$detail['input_lag_origin'] : '';
        $inputLagScope = isset($detail['input_lag_scope']) ? (string)$detail['input_lag_scope'] : (isset($pattern['fallback_level']) ? (string)$pattern['fallback_level'] : '');
        $inputLagHolidayBasis = isset($detail['input_lag_holiday_basis']) ? (string)$detail['input_lag_holiday_basis'] : '';
        $oldValue = isset($oldCategory['forecast']) && is_numeric($oldCategory['forecast'])
            ? max(0.0, (float)$oldCategory['forecast'])
            : null;
        $similar = AiProjectTypeService::comparableHistoricalMedian($pdo, (int)$snapshot['project_id'], $snapshot['target_ym'], $cost, $column);
        $similarValue = !empty($similar['available']) && isset($similar['median']) && is_numeric($similar['median'])
            ? max(0.0, (float)$similar['median'])
            : null;

        /*
         * No point forecast for projects that are bid/contract/completed/out-of-period and have no current activity.
         */
        if ($projectDormant) {
            $forecast = $current;
            $low = $current;
            $high = $current;
            $method = 'NO_PROJECT_ACTIVITY';
            $confidence = $amountMonths > 0 ? self::amountOnlyConfidence($amountMonths, $current, false) : null;
            $over = AiCostProjectionRiskService::overinputGrade($current, $forecast, $historical, $completion);
            $missing = AiCostProjectionRiskService::missingPossibilityGrade($completion, $progress['rate'], isset($pattern['event_count']) ? $pattern['event_count'] : 0, isset($pattern['average_input_lag_days']) ? $pattern['average_input_lag_days'] : null);
            $row = array(
                'cost_type'=>$cost,
                'current_input_amount'=>round($current,2),
                'expected_completion_rate'=>$completion,
                'expected_unentered_amount'=>0.0,
                'final_forecast_amount'=>round($forecast,2),
                'forecast_low_amount'=>round($low,2),
                'forecast_high_amount'=>round($high,2),
                'forecast_confidence_score'=>$confidence,
                'forecast_confidence_grade'=>self::confidenceGrade($confidence),
                'forecast_method'=>$method,
                'fallback_level'=>isset($pattern['fallback_level']) ? (string)$pattern['fallback_level'] : 'COLD_START',
                'sample_count'=>$sample,
                'amount_pattern_month_count'=>$amountMonths,
                'timing_pattern_month_count'=>$sample,
                'live_input_sample_count'=>0,
                'data_origin_summary'=>array(),
                'similar_project_sample_count'=>0,
                'event_count'=>0,
                'average_input_lag_days'=>isset($pattern['average_input_lag_days']) ? $pattern['average_input_lag_days'] : null,
                'completion_volatility'=>isset($pattern['completion_volatility']) ? $pattern['completion_volatility'] : null,
                'late_bulk_rate'=>isset($pattern['late_bulk_rate']) ? $pattern['late_bulk_rate'] : null,
                'correction_rate'=>isset($pattern['correction_rate']) ? $pattern['correction_rate'] : null,
                'month_move_rate'=>isset($pattern['month_move_rate']) ? $pattern['month_move_rate'] : null,
                'overinput_grade'=>$over,
                'missing_possibility_grade'=>$missing,
                'data_status'=>'WAITING_ACTIVITY',
                'calculation_version'=>self::CALCULATION_VERSION,
                'candidate_data'=>array(
                    'candidates'=>array(),
                    'learning'=>$learning,
                    'direct_projection_blocked'=>1,
                    'direct_projection_block_reason'=>'PROJECT_NO_ACTIVITY',
                    'historical_reference'=>$historical,
                    'work_occurrence_rate'=>$workOccurrenceRate,
                    'work_pattern_month_count'=>$workMonths,
                    'lag_sample_count'=>$lagSampleCount,
                    'same_day_input_rate'=>$sameDayInputRate,
                    'within_one_business_day_rate'=>$withinOneBusinessDayRate,
                    'late_two_plus_input_rate'=>$lateTwoPlusInputRate,
                    'input_lag_basis'=>$inputLagBasis,
                    'input_lag_origin'=>$inputLagOrigin,
                    'input_lag_scope'=>$inputLagScope,
                    'input_lag_holiday_basis'=>$inputLagHolidayBasis,
                    'project_activity'=>0,
                    'category_activity'=>0
                ),
                'progress_rate'=>$progress['rate']
            );
            $row['anomaly_types'] = AiCostProjectionRiskService::anomalyTypes($row);
            return $row;
        }

        /*
         * Zero input + no event for this category: do not copy history into the point forecast.
         * Historical/similar amount is retained only as the upper reference range.
         */
        if ($current <= 0 && !$categoryActivity) {
            $referenceHigh = 0.0;
            if ($historical !== null) $referenceHigh = max($referenceHigh, $historical);
            if ($similarValue !== null) $referenceHigh = max($referenceHigh, $similarValue);
            $forecast = 0.0;
            $low = 0.0;
            $high = round($referenceHigh, 2);
            $confidence = self::amountOnlyConfidence($amountMonths, 0, false);
            $over = AiCostProjectionRiskService::overinputGrade(0, 0, $historical, $completion);
            $missing = AiCostProjectionRiskService::missingPossibilityGrade($completion, $progress['rate'], isset($pattern['event_count']) ? $pattern['event_count'] : 0, isset($pattern['average_input_lag_days']) ? $pattern['average_input_lag_days'] : null);
            $row = array(
                'cost_type'=>$cost,
                'current_input_amount'=>0.0,
                'expected_completion_rate'=>$completion,
                'expected_unentered_amount'=>0.0,
                'final_forecast_amount'=>0.0,
                'forecast_low_amount'=>0.0,
                'forecast_high_amount'=>$high,
                'forecast_confidence_score'=>$confidence,
                'forecast_confidence_grade'=>self::confidenceGrade($confidence),
                'forecast_method'=>'NO_CATEGORY_ACTIVITY',
                'fallback_level'=>isset($pattern['fallback_level']) ? (string)$pattern['fallback_level'] : 'COLD_START',
                'sample_count'=>$sample,
                'amount_pattern_month_count'=>$amountMonths,
                'timing_pattern_month_count'=>$sample,
                'live_input_sample_count'=>0,
                'data_origin_summary'=>array(),
                'similar_project_sample_count'=>!empty($similar['available']) ? (int)$similar['sample_count'] : 0,
                'event_count'=>isset($pattern['event_count']) ? (int)$pattern['event_count'] : 0,
                'average_input_lag_days'=>isset($pattern['average_input_lag_days']) ? $pattern['average_input_lag_days'] : null,
                'completion_volatility'=>isset($pattern['completion_volatility']) ? $pattern['completion_volatility'] : null,
                'late_bulk_rate'=>isset($pattern['late_bulk_rate']) ? $pattern['late_bulk_rate'] : null,
                'correction_rate'=>isset($pattern['correction_rate']) ? $pattern['correction_rate'] : null,
                'month_move_rate'=>isset($pattern['month_move_rate']) ? $pattern['month_move_rate'] : null,
                'overinput_grade'=>$over,
                'missing_possibility_grade'=>$missing,
                'data_status'=>'WAITING_ACTIVITY',
                'calculation_version'=>self::CALCULATION_VERSION,
                'candidate_data'=>array(
                    'candidates'=>array(),
                    'learning'=>$learning,
                    'direct_projection_blocked'=>1,
                    'direct_projection_block_reason'=>'CATEGORY_NO_ACTIVITY',
                    'historical_reference'=>$historical,
                    'work_occurrence_rate'=>$workOccurrenceRate,
                    'work_pattern_month_count'=>$workMonths,
                    'lag_sample_count'=>$lagSampleCount,
                    'same_day_input_rate'=>$sameDayInputRate,
                    'within_one_business_day_rate'=>$withinOneBusinessDayRate,
                    'late_two_plus_input_rate'=>$lateTwoPlusInputRate,
                    'input_lag_basis'=>$inputLagBasis,
                    'input_lag_origin'=>$inputLagOrigin,
                    'input_lag_scope'=>$inputLagScope,
                    'input_lag_holiday_basis'=>$inputLagHolidayBasis,
                    'similar_reference'=>$similarValue,
                    'project_activity'=>$projectActivity ? 1 : 0,
                    'category_activity'=>0
                ),
                'progress_rate'=>$progress['rate']
            );
            $row['anomaly_types'] = AiCostProjectionRiskService::anomalyTypes($row);
            return $row;
        }

        if (!$directBlocked && $completion !== null && $completion > 0) {
            $patternValue = $current / ($completion / 100);
            $candidates[] = array('type'=>'COMPLETION_PATTERN','value'=>$patternValue,'weight'=>40);
        }

        /*
         * 노무비는 과거 상세공수의 실제 근무일 분포를 별도 작업발생 패턴으로 사용한다.
         * 과거 자료를 오늘 복원입력했더라도 work_date 기준이므로 사용할 수 있지만,
         * 등록시각은 과거 입력지연으로 사용하지 않는다.
         */
        if ($cost === 'labor' && $current > 0 && $workOccurrenceRate !== null && $workOccurrenceRate >= 5.0 && $workMonths > 0) {
            $workPatternValue = $current / max(0.05, $workOccurrenceRate / 100);
            $workWeight = $workMonths >= 3 ? 65 : ($workMonths === 2 ? 50 : 35);
            if ($workVolatility !== null && $workVolatility >= 30) $workWeight = max(25, $workWeight - 15);
            $candidates[] = array(
                'type'=>'LABOR_WORK_PATTERN',
                'value'=>$workPatternValue,
                'weight'=>$workWeight
            );
        }

        /*
         * When timing learning is still weak, use calendar-period pace as the point estimate anchor.
         * This prevents a small current input from jumping to a very large historical median.
         */
        $progressRate = isset($progress['rate']) ? (float)$progress['rate'] : 0.0;
        if ($current > 0 && $progressRate >= 5.0) {
            $periodPaceValue = $current / max(0.05, $progressRate / 100);
            $candidates[] = array(
                'type'=>'PERIOD_PROGRESS_BASELINE',
                'value'=>$periodPaceValue,
                'weight'=>$directBlocked ? ($workPatternValue !== null ? 35 : 60) : 20
            );
        } else if ($current > 0 && $directBlocked) {
            $candidates[] = array('type'=>'CURRENT_BASELINE','value'=>$current,'weight'=>60);
        }

        if ($historical !== null && $historical > 0) {
            $candidates[] = array('type'=>'HISTORICAL_MEDIAN','value'=>$historical,'weight'=>$workPatternValue !== null ? 30 : 25);
        }
        if ($oldValue !== null && $oldValue > 0 && $categoryActivity) {
            $candidates[] = array('type'=>'BASIC_FORECAST','value'=>$oldValue,'weight'=>$directBlocked ? 10 : 20);
        }
        if ($similarValue !== null && $similarValue > 0 && $categoryActivity) {
            $candidates[] = array('type'=>'SAME_PROJECT_TYPE_MEDIAN','value'=>$similarValue,'weight'=>10);
        }
        if (is_array($pace) && isset($pace['first'][$column]) && isset($pace['last'][$column])) {
            $delta = max(0, (float)$pace['last'][$column] - (float)$pace['first'][$column]);
            $remaining = max(0, 100 - $progressRate);
            $paceValue = $current + $delta * ($remaining / max(1, $progressRate)) * 0.8;
            if ($delta > 0) $candidates[] = array('type'=>'RECENT_PACE','value'=>$paceValue,'weight'=>20);
        }

        $forecast = self::weightedMedian($candidates);
        if ($forecast === null) $forecast = $current;
        $forecast = max($current, $forecast);

        $method = 'COLD_START';
        $workSelected = $workPatternValue !== null && abs($forecast - $workPatternValue) <= max(1.0, $workPatternValue * 0.03);
        if ($workSelected) $method = 'LABOR_WORK_PATTERN';
        else if ($directBlocked && $periodPaceValue !== null) $method = 'PERIOD_PROGRESS_BASELINE';
        else if (!$directBlocked && $patternValue !== null) $method = count($candidates) > 1 ? 'COMPLETION_AND_HISTORICAL' : 'COMPLETION_PATTERN';
        else if ($historical !== null) $method = count($candidates) > 1 ? 'HISTORICAL_WITH_CURRENT' : 'HISTORICAL_MEDIAN';
        else if (count($candidates) > 0) $method = (string)$candidates[0]['type'];

        $fallback = isset($pattern['fallback_level']) ? (string)$pattern['fallback_level'] : 'COLD_START';
        $vol = isset($pattern['completion_volatility']) && $pattern['completion_volatility'] !== null ? (float)$pattern['completion_volatility'] : 35;
        $width = 0.12 + min(0.35, $vol / 100) + ($sample < 3 ? 0.15 : 0) + ($fallback === 'COLD_START' ? 0.18 : 0);
        $width = min(0.55, $width);
        $low = max($current, $forecast * (1 - $width));
        $high = max($low, $forecast * (1 + $width));
        if ($directBlocked && $historical !== null) $high = max($high, $historical);
        if ($directBlocked && $similarValue !== null) $high = max($high, $similarValue);

        $origins = AiCostDataGovernanceService::originSummary($pdo, (int)$snapshot['project_id'], $snapshot['target_ym'], $cost);
        if ($cost === 'safety') {
            /* 과거 검진비가 health로 분류된 이력도 안전관리비 운영표본으로 합친다. */
            $healthOrigins = AiCostDataGovernanceService::originSummary($pdo, (int)$snapshot['project_id'], $snapshot['target_ym'], 'health');
            if (isset($healthOrigins['origins']) && is_array($healthOrigins['origins'])) {
                if (!isset($origins['origins']) || !is_array($origins['origins'])) $origins['origins'] = array();
                foreach ($healthOrigins['origins'] as $originKey=>$originCount) {
                    $origins['origins'][$originKey] = isset($origins['origins'][$originKey])
                        ? (int)$origins['origins'][$originKey] + (int)$originCount : (int)$originCount;
                }
            }
        }
        $originCounts = isset($origins['origins']) && is_array($origins['origins']) ? $origins['origins'] : array();
        $liveSamples = isset($originCounts['LIVE_EMPLOYEE_INPUT']) ? (int)$originCounts['LIVE_EMPLOYEE_INPUT'] : 0;
        $operationalSamples = $liveSamples;
        /* 출퇴근/공수 자동계산은 노무비에서 정상 운영자료로 인정한다. 강제입력은 ADMIN_CORRECTION이므로 포함되지 않는다. */
        if ($cost === 'labor' && isset($originCounts['SYSTEM_IMPORT'])) $operationalSamples += (int)$originCounts['SYSTEM_IMPORT'];
        $accuracy = AiForecastAccuracyService::historicalPerformance($pdo, (int)$snapshot['project_id'], $cost);

        $analyzable = $completion === null ? 0 : 100;
        $confidence = self::confidence($pattern, $progressRate, $analyzable);
        if ($confidence === null) {
            $confidence = $workMonths > 0
                ? self::workPatternConfidence($amountMonths, $workMonths, $current, $categoryActivity)
                : self::amountOnlyConfidence($amountMonths, $current, $categoryActivity);
        }
        if ($directBlocked && $confidence !== null) {
            if ($workMonths >= 3) $confidence = min(79, $confidence);
            else if ($workMonths === 2) $confidence = min(69, $confidence);
            else if ($workMonths === 1) $confidence = min(59, $confidence);
            else $confidence = min(49, $confidence);
        } else if ($completion !== null && $completion < $minRate && $confidence !== null) {
            $confidence = max(0, $confidence - 15);
        }
        if ($confidence !== null && $sample === 1 && $workMonths < 2) $confidence = min(59, $confidence);
        else if ($confidence !== null && $sample === 2 && $workMonths < 3) $confidence = min(69, $confidence);
        if ($confidence !== null && $operationalSamples === 0 && $sample === 0 && $workMonths === 0) $confidence = min($confidence, 49);
        if ($confidence !== null && !empty($accuracy['available']) && $accuracy['wape'] !== null) {
            $accuracyScore = max(0, min(100, (1 - (float)$accuracy['wape']) * 100));
            $confidence = round($confidence * 0.8 + $accuracyScore * 0.2, 2);
            if ($directBlocked) {
                if ($workMonths >= 3) $confidence = min(79, $confidence);
                else if ($workMonths === 2) $confidence = min(69, $confidence);
                else if ($workMonths === 1) $confidence = min(59, $confidence);
                else $confidence = min(49, $confidence);
            }
        }

        $over = AiCostProjectionRiskService::overinputGrade($current, $forecast, $historical, $completion);
        $missing = AiCostProjectionRiskService::missingPossibilityGrade(
            $completion,
            $progressRate,
            isset($pattern['event_count']) ? $pattern['event_count'] : 0,
            isset($pattern['average_input_lag_days']) ? $pattern['average_input_lag_days'] : null
        );

        $row = array(
            'cost_type'=>$cost,
            'current_input_amount'=>round($current,2),
            'expected_completion_rate'=>$completion,
            'expected_unentered_amount'=>round(max(0,$forecast-$current),2),
            'final_forecast_amount'=>round($forecast,2),
            'forecast_low_amount'=>round($low,2),
            'forecast_high_amount'=>round($high,2),
            'forecast_confidence_score'=>$confidence,
            'forecast_confidence_grade'=>self::confidenceGrade($confidence),
            'forecast_method'=>$method,
            'fallback_level'=>$fallback,
            'sample_count'=>$sample,
            'amount_pattern_month_count'=>$amountMonths,
            'timing_pattern_month_count'=>$sample,
            'live_input_sample_count'=>$liveSamples,
            'data_origin_summary'=>$origins,
            'similar_project_sample_count'=>!empty($similar['available']) ? (int)$similar['sample_count'] : 0,
            'event_count'=>isset($pattern['event_count']) ? (int)$pattern['event_count'] : 0,
            'average_input_lag_days'=>isset($pattern['average_input_lag_days']) ? $pattern['average_input_lag_days'] : null,
            'completion_volatility'=>isset($pattern['completion_volatility']) ? $pattern['completion_volatility'] : null,
            'late_bulk_rate'=>isset($pattern['late_bulk_rate']) ? $pattern['late_bulk_rate'] : null,
            'correction_rate'=>isset($pattern['correction_rate']) ? $pattern['correction_rate'] : null,
            'month_move_rate'=>isset($pattern['month_move_rate']) ? $pattern['month_move_rate'] : null,
            'overinput_grade'=>$over,
            'missing_possibility_grade'=>$missing,
            'data_status'=>$workMonths >= 3 ? 'WORK_READY' : ($workMonths > 0 ? 'WORK_LIMITED' : ($directBlocked ? 'LIMITED' : ($sample >= 3 ? 'READY' : 'LIMITED'))),
            'calculation_version'=>self::CALCULATION_VERSION,
            'candidate_data'=>array(
                'candidates'=>$candidates,
                'learning'=>$learning,
                'amount_pattern_month_count'=>$amountMonths,
                'timing_pattern_month_count'=>$sample,
                'work_pattern_month_count'=>$workMonths,
                'work_occurrence_rate'=>$workOccurrenceRate,
                'lag_sample_count'=>$lagSampleCount,
                'same_day_input_rate'=>$sameDayInputRate,
                'within_one_business_day_rate'=>$withinOneBusinessDayRate,
                'late_two_plus_input_rate'=>$lateTwoPlusInputRate,
                'input_lag_basis'=>$inputLagBasis,
                'input_lag_origin'=>$inputLagOrigin,
                'work_pattern_value'=>$workPatternValue,
                'operational_input_sample_count'=>$operationalSamples,
                'live_input_sample_count'=>$liveSamples,
                'data_origin_summary'=>$origins,
                'similar_project_sample_count'=>!empty($similar['available']) ? (int)$similar['sample_count'] : 0,
                'historical_accuracy'=>$accuracy,
                'direct_projection_blocked'=>$directBlocked ? 1 : 0,
                'direct_projection_block_reason'=>isset($pattern['direct_projection_block_reason']) ? $pattern['direct_projection_block_reason'] : ($directBlocked ? 'SAFETY_GUARD' : 'READY'),
                'historical_reference'=>$historical,
                'period_progress_baseline'=>$periodPaceValue,
                'project_activity'=>$projectActivity ? 1 : 0,
                'category_activity'=>$categoryActivity ? 1 : 0
            ),
            'progress_rate'=>$progressRate
        );
        $row['anomaly_types'] = AiCostProjectionRiskService::anomalyTypes($row);
        return $row;
    }

    public static function calculateProject($pdo, $snapshot, $oldCategories, $pace)
    {
        $categories = array();
        $patterns = array();
        $totals = array(
            'current'=>0.0,'forecast'=>0.0,'low'=>0.0,'high'=>0.0,
            'completion_weighted'=>0.0,'completion_base'=>0.0,
            'confidence_weighted'=>0.0,'confidence_base'=>0.0
        );
        $sample = 0;
        $amountMonths = 0;
        $workMonths = 0;
        $liveSamples = 0;
        $operationalSamples = 0;
        $similarSamples = 0;
        $originTotals = array();
        $available = 0;
        $methods = array();
        $fallbacks = array();
        $projectDormant = self::projectDormantForPointForecast($snapshot);

        foreach (self::categories() as $cost=>$column) {
            $pattern = AiInputCompletionPatternService::loadBestPattern(
                $pdo,
                $snapshot['snapshot_date'],
                $snapshot['target_ym'],
                (int)$snapshot['project_id'],
                $cost
            );
            $patterns[$cost] = $pattern;
            $old = isset($oldCategories[$cost]) && is_array($oldCategories[$cost]) ? $oldCategories[$cost] : array();
            $row = self::calculateCategory($pdo, $snapshot, $cost, $column, $pattern, $old, $pace, $projectDormant);
            $categories[] = $row;

            $amount = max(1, (float)$row['current_input_amount']);
            $candidateMeta = isset($row['candidate_data']) && is_array($row['candidate_data']) ? $row['candidate_data'] : array();
            $categoryActive = !empty($candidateMeta['category_activity']);
            $totals['current'] += $row['current_input_amount'];
            $totals['forecast'] += $row['final_forecast_amount'];
            $totals['low'] += $row['forecast_low_amount'];
            $totals['high'] += $row['forecast_high_amount'];

            /*
             * CEO Index 전체 입력완료율/신뢰도는 이번 달 실제 활동이 있는 비용항목만 반영한다.
             * 활동 없음/해당 없음 항목의 0%를 평균에 섞으면 회사 전체 입력완료율이 부당하게 낮아진다.
             */
            if ($categoryActive && $row['expected_completion_rate'] !== null) {
                $totals['completion_weighted'] += (float)$row['expected_completion_rate'] * $amount;
                $totals['completion_base'] += $amount;
                $available++;
            }
            if ($categoryActive && $row['forecast_confidence_score'] !== null) {
                $totals['confidence_weighted'] += (float)$row['forecast_confidence_score'] * $amount;
                $totals['confidence_base'] += $amount;
            }
            $sample = max($sample, (int)$row['sample_count']);
            $amountMonths = max($amountMonths, (int)$row['amount_pattern_month_count']);
            $workMonths = max($workMonths, isset($candidateMeta['work_pattern_month_count']) ? (int)$candidateMeta['work_pattern_month_count'] : 0);
            $liveSamples += (int)$row['live_input_sample_count'];
            $operationalSamples += isset($candidateMeta['operational_input_sample_count']) ? (int)$candidateMeta['operational_input_sample_count'] : (int)$row['live_input_sample_count'];
            $similarSamples = max($similarSamples, (int)$row['similar_project_sample_count']);
            $rowOrigins = isset($row['data_origin_summary']['origins']) && is_array($row['data_origin_summary']['origins'])
                ? $row['data_origin_summary']['origins']
                : array();
            foreach ($rowOrigins as $origin=>$count) {
                $originTotals[$origin] = isset($originTotals[$origin]) ? $originTotals[$origin] + (int)$count : (int)$count;
            }
            $methods[$row['forecast_method']] = isset($methods[$row['forecast_method']]) ? $methods[$row['forecast_method']] + 1 : 1;
            $fallbacks[$row['fallback_level']] = isset($fallbacks[$row['fallback_level']]) ? $fallbacks[$row['fallback_level']] + 1 : 1;
        }

        arsort($methods);
        arsort($fallbacks);
        $method = count($methods) ? key($methods) : 'INSUFFICIENT';
        $fallback = count($fallbacks) ? key($fallbacks) : 'COLD_START';
        $completion = $totals['completion_base'] > 0 ? round($totals['completion_weighted'] / $totals['completion_base'], 3) : null;
        $confidence = $totals['confidence_base'] > 0 ? round($totals['confidence_weighted'] / $totals['confidence_base'], 2) : null;
        $cumulative = isset($snapshot['cumulative_input_amount']) ? (float)$snapshot['cumulative_input_amount'] : 0.0;
        $projectedCumulative = max($cumulative, $cumulative - $totals['current'] + $totals['forecast']);

        $startDate = isset($snapshot['project_start_date']) ? (string)$snapshot['project_start_date'] : '';
        $newSite = $startDate !== '' && strtotime($startDate) !== false
            && strtotime($snapshot['snapshot_date']) - strtotime($startDate) < 90 * 86400
            && strtotime($snapshot['snapshot_date']) >= strtotime($startDate)
            ? 1 : 0;
        $changeFlags = self::projectChangeFlags($pdo, (int)$snapshot['project_id'], $snapshot['snapshot_date']);
        $correctionRatio = self::recentCorrectionRatio(
            $pdo,
            (int)$snapshot['project_id'],
            $snapshot['target_ym'],
            $snapshot['snapshot_date'],
            max($totals['current'], $totals['forecast'])
        );
        $confidenceFactors = array(
            'live_input_sample_count'=>$liveSamples,
            'operational_input_sample_count'=>$operationalSamples,
            'timing_pattern_month_count'=>$sample,
            'amount_pattern_month_count'=>$amountMonths,
            'work_pattern_month_count'=>$workMonths,
            'similar_project_sample_count'=>$similarSamples,
            'new_site_flag'=>$newSite,
            'contract_change_flag'=>$changeFlags['contract_change_flag'],
            'schedule_change_flag'=>$changeFlags['schedule_change_flag'],
            'recent_correction_ratio'=>$correctionRatio
        );
        $confidence = self::adjustConfidence($confidence, $confidenceFactors);
        if ($projectDormant && $confidence !== null) $confidence = min(49, $confidence);

        foreach ($categories as $categoryIndex=>$categoryValue) {
            $categories[$categoryIndex]['new_site_flag'] = $newSite;
            $categories[$categoryIndex]['contract_change_flag'] = $changeFlags['contract_change_flag'];
            $categories[$categoryIndex]['schedule_change_flag'] = $changeFlags['schedule_change_flag'];
            $categoryFactors = $confidenceFactors;
            $categoryFactors['live_input_sample_count'] = isset($categoryValue['live_input_sample_count']) ? (int)$categoryValue['live_input_sample_count'] : 0;
            $categoryFactors['timing_pattern_month_count'] = isset($categoryValue['timing_pattern_month_count']) ? (int)$categoryValue['timing_pattern_month_count'] : 0;
            $categoryFactors['amount_pattern_month_count'] = isset($categoryValue['amount_pattern_month_count']) ? (int)$categoryValue['amount_pattern_month_count'] : 0;
            $categoryMeta = isset($categoryValue['candidate_data']) && is_array($categoryValue['candidate_data']) ? $categoryValue['candidate_data'] : array();
            $categoryFactors['work_pattern_month_count'] = isset($categoryMeta['work_pattern_month_count']) ? (int)$categoryMeta['work_pattern_month_count'] : 0;
            $categoryFactors['operational_input_sample_count'] = isset($categoryMeta['operational_input_sample_count']) ? (int)$categoryMeta['operational_input_sample_count'] : (isset($categoryValue['live_input_sample_count']) ? (int)$categoryValue['live_input_sample_count'] : 0);
            $categoryFactors['similar_project_sample_count'] = isset($categoryValue['similar_project_sample_count']) ? (int)$categoryValue['similar_project_sample_count'] : 0;
            $categories[$categoryIndex]['forecast_confidence_score'] = self::adjustConfidence($categoryValue['forecast_confidence_score'], $categoryFactors);
            $categories[$categoryIndex]['forecast_confidence_grade'] = self::confidenceGrade($categories[$categoryIndex]['forecast_confidence_score']);
        }

        $risk = AiCostProjectionRiskService::summarizeProject($categories, $projectedCumulative, (float)$snapshot['contract_amount'], $confidence);
        $warningData = array();
        if ($confidence === null) $warningData[] = '예측 신뢰도를 계산할 자료가 부족합니다.';
        if ($projectDormant) $warningData[] = '이번 달 실제 입력 또는 활동이 없어 최종 예상 합계에서 과거금액 자동 복사를 하지 않았습니다.';

        return array(
            'project_id'=>(int)$snapshot['project_id'],
            'project_name_snapshot'=>(string)$snapshot['project_name_snapshot'],
            'project_status_snapshot'=>(string)$snapshot['project_status_snapshot'],
            'current_input_amount'=>round($totals['current'],2),
            'expected_completion_rate'=>$completion,
            'expected_unentered_amount'=>round(max(0,$totals['forecast']-$totals['current']),2),
            'final_forecast_amount'=>round(max($totals['current'],$totals['forecast']),2),
            'forecast_low_amount'=>round(max($totals['current'],$totals['low']),2),
            'forecast_high_amount'=>round(max($totals['low'],$totals['high']),2),
            'forecast_confidence_score'=>$confidence,
            'forecast_confidence_grade'=>self::confidenceGrade($confidence),
            'forecast_method'=>$projectDormant ? 'NO_PROJECT_ACTIVITY' : (count($methods)>1 ? 'MIXED' : $method),
            'fallback_level'=>$fallback,
            'sample_count'=>$sample,
            'amount_pattern_month_count'=>$amountMonths,
            'timing_pattern_month_count'=>$sample,
            'live_input_sample_count'=>$liveSamples,
            'data_origin_summary'=>$originTotals,
            'new_site_flag'=>$newSite,
            'contract_change_flag'=>$changeFlags['contract_change_flag'],
            'schedule_change_flag'=>$changeFlags['schedule_change_flag'],
            'similar_project_sample_count'=>$similarSamples,
            'category_analyzable_rate'=>round($available / count(self::categories()) * 100, 3),
            'cumulative_current_input_amount'=>round($cumulative,2),
            'cumulative_projected_input_amount'=>round($projectedCumulative,2),
            'contract_amount'=>(float)$snapshot['contract_amount'],
            'projected_cumulative_cost_rate'=>$risk['contract_risk']['rate'],
            'contract_risk_grade'=>$risk['contract_risk']['grade'],
            'overinput_grade'=>$risk['overinput_grade'],
            'missing_possibility_grade'=>$risk['missing_possibility_grade'],
            'anomaly_count'=>count($risk['anomaly_types']),
            'data_status'=>$confidence===null ? ($workMonths>0?'AMOUNT_AND_WORK':($amountMonths>0?'AMOUNT_ONLY':'INSUFFICIENT')) : ($available<count(self::categories())?'LIMITED':'READY'),
            'calculation_version'=>self::CALCULATION_VERSION,
            'method_data'=>array(
                'methods'=>$methods,
                'fallbacks'=>$fallbacks,
                'project_type'=>AiProjectTypeService::projectType($pdo,(int)$snapshot['project_id']),
                'confidence_factors'=>$confidenceFactors,
                'work_pattern_month_count'=>$workMonths,
                'operational_input_sample_count'=>$operationalSamples,
                'project_activity'=>self::projectHasActivity($snapshot) ? 1 : 0,
                'project_point_forecast_excluded'=>$projectDormant ? 1 : 0
            ),
            'warning_data'=>$warningData,
            'risk_data'=>$risk,
            'categories'=>$categories,
            'patterns'=>$patterns
        );
    }

    private static function actor($trigger)
    {
        if ($trigger === 'CLI' || $trigger === 'SYSTEM') return array('id'=>null,'name'=>null);
        $user = Auth::user();
        $name = isset($user['name']) ? (string)$user['name'] : '';
        $name = function_exists('mb_substr') ? mb_substr($name,0,100,'UTF-8') : substr($name,0,100);
        return array('id'=>isset($user['employee_id'])?(int)$user['employee_id']:null,'name'=>$name!==''?$name:null);
    }

    private static function acquireLock($pdo, $context)
    {
        $name = 'cpms_ai_cost_forecast_v2_' . str_replace('-','',$context['analysis_date']) . '_' . str_replace('-','',$context['target_ym']);
        try {
            $st = $pdo->prepare('SELECT GET_LOCK(:name,0)');
            if (!$st || !$st->execute(array(':name'=>$name))) return array('ok'=>true,'name'=>'');
            return array('ok'=>(int)$st->fetchColumn()===1,'name'=>$name);
        } catch (Exception $e) {
            return array('ok'=>true,'name'=>'');
        }
    }

    private static function releaseLock($pdo, $lock)
    {
        if (empty($lock['name'])) return;
        try {
            $st = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
            if ($st) $st->execute(array(':name'=>$lock['name']));
        } catch (Exception $e) {
        }
    }

    private static function createRun($pdo, $context, $trigger, $fingerprint, $count)
    {
        $actor = self::actor($trigger);
        $uid = hash('sha256', uniqid('',true) . microtime(true) . mt_rand());
        $now = date('Y-m-d H:i:s');
        $sql = 'INSERT INTO `' . self::RUN_TABLE . '` '
            . '(run_uid,analysis_date,target_ym,snapshot_date,trigger_type,run_status,calculation_version,source_fingerprint,project_count,actor_employee_id,actor_name,started_at,created_at) '
            . 'VALUES (:uid,:date,:ym,:snapshot,:trigger,\'RUNNING\',:version,:fingerprint,:count,:actor_id,:actor_name,:started,:created)';
        $st = $pdo->prepare($sql);
        if (!$st || !$st->execute(array(
            ':uid'=>$uid,
            ':date'=>$context['analysis_date'],
            ':ym'=>$context['target_ym'],
            ':snapshot'=>$context['snapshot_date'],
            ':trigger'=>$trigger,
            ':version'=>self::CALCULATION_VERSION,
            ':fingerprint'=>$fingerprint,
            ':count'=>$count,
            ':actor_id'=>$actor['id'],
            ':actor_name'=>$actor['name'],
            ':started'=>$now,
            ':created'=>$now
        ))) return 0;
        return (int)$pdo->lastInsertId();
    }

    private static function finishRun($pdo, $runId, $status, $counts, $totals, $error)
    {
        $sql = 'UPDATE `' . self::RUN_TABLE . '` SET run_status=:status,success_count=:success,failure_count=:failure,'
            . 'insufficient_count=:insufficient,current_input_total=:current,final_forecast_total=:forecast,'
            . 'forecast_low_total=:low,forecast_high_total=:high,finished_at=:finished,error_summary=:error WHERE id=:id';
        $st = $pdo->prepare($sql);
        if ($st) {
            $st->execute(array(
                ':status'=>$status,
                ':success'=>$counts['success'],
                ':failure'=>$counts['failure'],
                ':insufficient'=>$counts['insufficient'],
                ':current'=>$totals['current'],
                ':forecast'=>$totals['forecast'],
                ':low'=>$totals['low'],
                ':high'=>$totals['high'],
                ':finished'=>date('Y-m-d H:i:s'),
                ':error'=>$error,
                ':id'=>$runId
            ));
        }
    }

    private static function saveCategory($pdo, $runId, $context, $projectId, $row)
    {
        $now = date('Y-m-d H:i:s');
        $sql = 'INSERT INTO `' . self::CATEGORY_TABLE . '` '
            . '(run_id,analysis_date,analysis_datetime,target_ym,snapshot_date,project_id,cost_type,current_input_amount,expected_completion_rate,'
            . 'expected_unentered_amount,final_forecast_amount,forecast_low_amount,forecast_high_amount,forecast_confidence_score,'
            . 'forecast_confidence_grade,forecast_method,fallback_level,sample_count,amount_pattern_month_count,timing_pattern_month_count,'
            . 'live_input_sample_count,data_origin_summary,new_site_flag,contract_change_flag,schedule_change_flag,similar_project_sample_count,'
            . 'event_count,average_input_lag_days,completion_volatility,late_bulk_rate,correction_rate,month_move_rate,overinput_grade,'
            . 'missing_possibility_grade,data_status,calculation_version,candidate_data,anomaly_type_data,first_created_at,last_calculated_at,'
            . 'calculation_count,created_at,updated_at) VALUES '
            . '(:run,:date,:analysis_datetime,:ym,:snapshot,:project,:cost,:current,:completion,:unentered,:forecast,:low,:high,:confidence,'
            . ':grade,:method,:fallback,:samples,:amount_months,:timing_months,:live_samples,:origins,:new_site,:contract_change,:schedule_change,'
            . ':similar_samples,:events,:lag,:volatility,:bulk,:correction,:move,:overinput,:missing,:status,:version,:candidates,:anomalies,'
            . ':first,:last,1,:created,:updated)';
        $st = $pdo->prepare($sql);
        if (!$st) return false;
        return $st->execute(array(
            ':run'=>$runId,
            ':date'=>$context['analysis_date'],
            ':analysis_datetime'=>$now,
            ':ym'=>$context['target_ym'],
            ':snapshot'=>$context['snapshot_date'],
            ':project'=>$projectId,
            ':cost'=>$row['cost_type'],
            ':current'=>$row['current_input_amount'],
            ':completion'=>$row['expected_completion_rate'],
            ':unentered'=>$row['expected_unentered_amount'],
            ':forecast'=>$row['final_forecast_amount'],
            ':low'=>$row['forecast_low_amount'],
            ':high'=>$row['forecast_high_amount'],
            ':confidence'=>$row['forecast_confidence_score'],
            ':grade'=>$row['forecast_confidence_grade'],
            ':method'=>$row['forecast_method'],
            ':fallback'=>$row['fallback_level'],
            ':samples'=>$row['sample_count'],
            ':amount_months'=>$row['amount_pattern_month_count'],
            ':timing_months'=>$row['timing_pattern_month_count'],
            ':live_samples'=>$row['live_input_sample_count'],
            ':origins'=>self::encode($row['data_origin_summary']),
            ':new_site'=>!empty($row['new_site_flag'])?1:0,
            ':contract_change'=>!empty($row['contract_change_flag'])?1:0,
            ':schedule_change'=>!empty($row['schedule_change_flag'])?1:0,
            ':similar_samples'=>$row['similar_project_sample_count'],
            ':events'=>$row['event_count'],
            ':lag'=>$row['average_input_lag_days'],
            ':volatility'=>$row['completion_volatility'],
            ':bulk'=>$row['late_bulk_rate'],
            ':correction'=>$row['correction_rate'],
            ':move'=>$row['month_move_rate'],
            ':overinput'=>$row['overinput_grade'],
            ':missing'=>$row['missing_possibility_grade'],
            ':status'=>$row['data_status'],
            ':version'=>self::CALCULATION_VERSION,
            ':candidates'=>self::encode($row['candidate_data']),
            ':anomalies'=>self::encode($row['anomaly_types']),
            ':first'=>$now,
            ':last'=>$now,
            ':created'=>$now,
            ':updated'=>$now
        ));
    }

    private static function saveResult($pdo, $runId, $context, $fingerprint, $row)
    {
        $now = date('Y-m-d H:i:s');
        $sql = 'INSERT INTO `' . self::RESULT_TABLE . '` '
            . '(run_id,analysis_date,analysis_datetime,target_ym,snapshot_date,project_id,project_name_snapshot,project_status_snapshot,current_input_amount,'
            . 'expected_completion_rate,expected_unentered_amount,final_forecast_amount,forecast_low_amount,forecast_high_amount,forecast_confidence_score,'
            . 'forecast_confidence_grade,forecast_method,fallback_level,sample_count,amount_pattern_month_count,timing_pattern_month_count,live_input_sample_count,'
            . 'data_origin_summary,new_site_flag,contract_change_flag,schedule_change_flag,similar_project_sample_count,category_analyzable_rate,'
            . 'cumulative_current_input_amount,cumulative_projected_input_amount,contract_amount,projected_cumulative_cost_rate,contract_risk_grade,'
            . 'overinput_grade,missing_possibility_grade,anomaly_count,data_status,calculation_version,source_fingerprint,method_data,warning_data,risk_data,'
            . 'first_created_at,last_calculated_at,calculation_count,created_at,updated_at) VALUES '
            . '(:run,:date,:analysis_datetime,:ym,:snapshot,:project,:name,:project_status,:current,:completion,:unentered,:forecast,:low,:high,:confidence,'
            . ':grade,:method,:fallback,:samples,:amount_months,:timing_months,:live_samples,:origins,:new_site,:contract_change,:schedule_change,:similar_samples,'
            . ':analyzable,:cumulative,:projected,:contract,:cost_rate,:contract_risk,:overinput,:missing,:anomalies,:data_status,:version,:fingerprint,'
            . ':methods,:warnings,:risk,:first,:last,1,:created,:updated)';
        $st = $pdo->prepare($sql);
        if (!$st) return false;
        return $st->execute(array(
            ':run'=>$runId,
            ':date'=>$context['analysis_date'],
            ':analysis_datetime'=>$now,
            ':ym'=>$context['target_ym'],
            ':snapshot'=>$context['snapshot_date'],
            ':project'=>$row['project_id'],
            ':name'=>$row['project_name_snapshot'],
            ':project_status'=>$row['project_status_snapshot'],
            ':current'=>$row['current_input_amount'],
            ':completion'=>$row['expected_completion_rate'],
            ':unentered'=>$row['expected_unentered_amount'],
            ':forecast'=>$row['final_forecast_amount'],
            ':low'=>$row['forecast_low_amount'],
            ':high'=>$row['forecast_high_amount'],
            ':confidence'=>$row['forecast_confidence_score'],
            ':grade'=>$row['forecast_confidence_grade'],
            ':method'=>$row['forecast_method'],
            ':fallback'=>$row['fallback_level'],
            ':samples'=>$row['sample_count'],
            ':amount_months'=>$row['amount_pattern_month_count'],
            ':timing_months'=>$row['timing_pattern_month_count'],
            ':live_samples'=>$row['live_input_sample_count'],
            ':origins'=>self::encode($row['data_origin_summary']),
            ':new_site'=>!empty($row['new_site_flag'])?1:0,
            ':contract_change'=>!empty($row['contract_change_flag'])?1:0,
            ':schedule_change'=>!empty($row['schedule_change_flag'])?1:0,
            ':similar_samples'=>$row['similar_project_sample_count'],
            ':analyzable'=>$row['category_analyzable_rate'],
            ':cumulative'=>$row['cumulative_current_input_amount'],
            ':projected'=>$row['cumulative_projected_input_amount'],
            ':contract'=>$row['contract_amount'],
            ':cost_rate'=>$row['projected_cumulative_cost_rate'],
            ':contract_risk'=>$row['contract_risk_grade'],
            ':overinput'=>$row['overinput_grade'],
            ':missing'=>$row['missing_possibility_grade'],
            ':anomalies'=>$row['anomaly_count'],
            ':data_status'=>$row['data_status'],
            ':version'=>self::CALCULATION_VERSION,
            ':fingerprint'=>$fingerprint,
            ':methods'=>self::encode($row['method_data']),
            ':warnings'=>self::encode($row['warning_data']),
            ':risk'=>self::encode($row['risk_data']),
            ':first'=>$now,
            ':last'=>$now,
            ':created'=>$now,
            ':updated'=>$now
        ));
    }

    /**
     * 파일: app/services/AiCostForecastV2Service.php
     * 화면: CEO Index > 투입비 예측 / GPT 요약
     *
     * 같은 날짜에 강제 파이프라인을 여러 번 실행하면 RESULT_TABLE에는 run별 결과가 누적된다.
     * 일부 하위 분석 서비스가 analysis_date/target_ym만으로 읽기 때문에 과거 run까지 합산되는 문제가 있었다.
     * 최신 정상 run의 프로젝트 결과만 남기고, 상세 비용항목(CATEGORY_TABLE)과 run 이력은 보존한다.
     */
    private static function pruneSupersededProjectResults($pdo, $runId, $context)
    {
        if (!$pdo || (int)$runId <= 0 || empty($context['analysis_date']) || empty($context['target_ym'])) return false;
        try {
            $sql = 'DELETE FROM `' . self::RESULT_TABLE . '` '
                . 'WHERE analysis_date=:date AND target_ym=:ym AND (run_id IS NULL OR run_id<>:run_id)';
            $st = $pdo->prepare($sql);
            if (!$st) return false;
            return $st->execute(array(
                ':date'=>(string)$context['analysis_date'],
                ':ym'=>(string)$context['target_ym'],
                ':run_id'=>(int)$runId
            ));
        } catch (Exception $e) {
            error_log('[AI Forecast V2] superseded project result cleanup failed');
            return false;
        }
    }

    public static function forecastLatest($pdo = null, $triggerType = 'SYSTEM')
    {
        $pdo = self::pdo($pdo);
        $empty = array('ok'=>false,'status'=>'FAILED','projects'=>0,'success'=>0,'failed'=>0,'message'=>'V2 최종 투입비 예측을 실행하지 못했습니다.');
        if (!$pdo) {
            $empty['message'] = 'DB 연결 상태를 확인할 수 없습니다.';
            return $empty;
        }
        if (!self::isInstalled($pdo)) {
            $empty['message'] = 'V2 예측 테이블을 먼저 설치해주세요.';
            return $empty;
        }
        $context = self::latestSnapshotContext($pdo);
        if (empty($context['available'])) {
            $empty['message'] = '먼저 일일 스냅샷을 생성해주세요.';
            return $empty;
        }
        $snapshots = self::loadSnapshots($pdo, $context);
        if (count($snapshots) === 0) {
            $empty['message'] = '예측할 현장 스냅샷이 없습니다.';
            return $empty;
        }
        $old = self::oldForecastMap($pdo, $context);
        $pace = self::recentPaceMap($pdo, $context);
        $fingerprint = self::sourceFingerprint($context, $snapshots, array('pattern_date'=>$context['analysis_date']));
        $lock = self::acquireLock($pdo, $context);
        if (empty($lock['ok'])) {
            $empty['message'] = '이미 V2 예측 계산이 진행 중입니다.';
            return $empty;
        }
        $trigger = in_array(strtoupper((string)$triggerType), array('MANUAL','CLI','SYSTEM'), true)
            ? strtoupper((string)$triggerType)
            : 'SYSTEM';
        $runId = 0;

        try {
            $runId = self::createRun($pdo, $context, $trigger, $fingerprint, count($snapshots));
            if ($runId <= 0) throw new Exception('run');
            $counts = array('success'=>0,'failure'=>0,'insufficient'=>0);
            $totals = array('current'=>0.0,'forecast'=>0.0,'low'=>0.0,'high'=>0.0);
            foreach ($snapshots as $snapshot) {
                try {
                    $projectId = (int)$snapshot['project_id'];
                    $row = self::calculateProject(
                        $pdo,
                        $snapshot,
                        isset($old[$projectId]) ? $old[$projectId] : array(),
                        isset($pace[$projectId]) ? $pace[$projectId] : array()
                    );
                    $categoryOk = true;
                    foreach ($row['categories'] as $category) {
                        if (!self::saveCategory($pdo, $runId, $context, $projectId, $category)) $categoryOk = false;
                    }
                    if (!$categoryOk || !self::saveResult($pdo, $runId, $context, $fingerprint, $row)) throw new Exception('save');
                    $counts['success']++;
                    if ($row['forecast_confidence_grade'] === 'INSUFFICIENT') $counts['insufficient']++;
                    $totals['current'] += $row['current_input_amount'];
                    $totals['forecast'] += $row['final_forecast_amount'];
                    $totals['low'] += $row['forecast_low_amount'];
                    $totals['high'] += $row['forecast_high_amount'];
                } catch (Exception $e) {
                    $counts['failure']++;
                    error_log('[AI Forecast V2] project calculation failed');
                }
            }
            $status = $counts['success'] === 0 ? 'FAILED' : ($counts['failure'] > 0 ? 'PARTIAL' : 'COMPLETED');
            self::finishRun(
                $pdo,
                $runId,
                $status,
                $counts,
                $totals,
                $counts['failure'] > 0 ? '일부 현장 V2 예측 실패: ' . $counts['failure'] . '건' : null
            );
            if ($status === 'COMPLETED') {
                self::pruneSupersededProjectResults($pdo, $runId, $context);
            }
            self::releaseLock($pdo, $lock);
            return array(
                'ok'=>$counts['success']>0,
                'status'=>$status,
                'projects'=>count($snapshots),
                'success'=>$counts['success'],
                'failed'=>$counts['failure'],
                'insufficient'=>$counts['insufficient'],
                'message'=>$status==='COMPLETED' ? 'V2 최종 투입비 예측을 완료했습니다.' : ($status==='PARTIAL' ? '일부 현장을 제외하고 V2 예측을 완료했습니다.' : 'V2 예측에 실패했습니다.')
            );
        } catch (Exception $e) {
            if ($runId > 0) {
                self::finishRun(
                    $pdo,
                    $runId,
                    'FAILED',
                    array('success'=>0,'failure'=>count($snapshots),'insufficient'=>0),
                    array('current'=>0,'forecast'=>0,'low'=>0,'high'=>0),
                    'V2 예측 실행 중 오류가 발생했습니다.'
                );
            }
            self::releaseLock($pdo, $lock);
            error_log('[AI Forecast V2] run failed');
            return $empty;
        }
    }

    public static function latestContext($pdo = null, $targetYm = '')
    {
        $pdo = self::pdo($pdo);
        $empty = array('available'=>false,'calculation_version'=>self::CALCULATION_VERSION);
        if (!$pdo || !self::isInstalled($pdo)) return $empty;
        try {
            $where = '';
            $params = array(':result_version'=>self::CALCULATION_VERSION, ':run_version'=>self::CALCULATION_VERSION);
            if (CostChangeService::validYm($targetYm) !== '') {
                $where = ' AND r.target_ym=:ym';
                $params[':ym'] = $targetYm;
            }
            $sql = 'SELECT r.analysis_date,r.target_ym,r.snapshot_date,r.run_id,r.calculation_version '
                . 'FROM `' . self::RESULT_TABLE . '` r INNER JOIN `' . self::RUN_TABLE . '` u '
                . 'ON u.id=r.run_id AND u.run_status=\'COMPLETED\' AND u.calculation_version=:run_version '
                . 'WHERE r.calculation_version=:result_version' . $where . ' ORDER BY r.analysis_date DESC,r.id DESC LIMIT 1';
            $st = $pdo->prepare($sql);
            if (!$st || !$st->execute($params)) return $empty;
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || (string)$row['calculation_version'] !== self::CALCULATION_VERSION) return $empty;
            $row['available'] = true;
            return $row;
        } catch (Exception $e) {
            return $empty;
        }
    }

    public static function latestCompanySummary($pdo = null, $targetYm = '')
    {
        $pdo = self::pdo($pdo);
        $context = self::latestContext($pdo, $targetYm);
        $empty = array(
            'available'=>false,
            'calculation_version'=>self::CALCULATION_VERSION,
            'project_count'=>0,
            'current_input_total'=>null,
            'expected_unentered_total'=>null,
            'final_forecast_total'=>null,
            'forecast_low_total'=>null,
            'forecast_high_total'=>null,
            'expected_completion_rate'=>null,
            'confidence_score'=>null,
            'confidence_grade'=>'INSUFFICIENT',
            'overinput_count'=>0,
            'missing_count'=>0,
            'anomaly_count'=>0
        );
        if (empty($context['available'])) return $empty;
        try {
            $sql = 'SELECT COUNT(*) AS project_count,SUM(current_input_amount) AS current_total,'
                . 'SUM(expected_unentered_amount) AS unentered_total,SUM(final_forecast_amount) AS forecast_total,'
                . 'SUM(forecast_low_amount) AS low_total,SUM(forecast_high_amount) AS high_total,'
                . 'SUM(CASE WHEN overinput_grade IN (\'WARNING\',\'CRITICAL\') THEN 1 ELSE 0 END) AS overinput_count,'
                . 'SUM(CASE WHEN missing_possibility_grade=\'HIGH\' THEN 1 ELSE 0 END) AS missing_count,'
                . 'SUM(anomaly_count) AS anomaly_count,'
                . 'SUM(CASE WHEN expected_completion_rate IS NOT NULL THEN expected_completion_rate*GREATEST(current_input_amount,1) ELSE 0 END)'
                . '/NULLIF(SUM(CASE WHEN expected_completion_rate IS NOT NULL THEN GREATEST(current_input_amount,1) ELSE 0 END),0) AS completion_rate,'
                . 'SUM(CASE WHEN forecast_confidence_score IS NOT NULL THEN forecast_confidence_score*GREATEST(final_forecast_amount,1) ELSE 0 END)'
                . '/NULLIF(SUM(CASE WHEN forecast_confidence_score IS NOT NULL THEN GREATEST(final_forecast_amount,1) ELSE 0 END),0) AS confidence_score,'
                . 'MAX(last_calculated_at) AS last_calculated_at FROM `' . self::RESULT_TABLE . '` '
                . 'WHERE analysis_date=:date AND target_ym=:ym AND run_id=:run_id AND calculation_version=:version';
            $st = $pdo->prepare($sql);
            if (!$st || !$st->execute(array(
                ':date'=>$context['analysis_date'],
                ':ym'=>$context['target_ym'],
                ':run_id'=>(int)$context['run_id'],
                ':version'=>self::CALCULATION_VERSION
            ))) return $empty;
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || (int)$row['project_count'] <= 0) return $empty;
            $score = $row['confidence_score'] === null ? null : (float)$row['confidence_score'];
            return array(
                'available'=>true,
                'calculation_version'=>self::CALCULATION_VERSION,
                'run_id'=>(int)$context['run_id'],
                'analysis_date'=>$context['analysis_date'],
                'target_ym'=>$context['target_ym'],
                'snapshot_date'=>$context['snapshot_date'],
                'project_count'=>(int)$row['project_count'],
                'current_input_total'=>$row['current_total']===null?null:(float)$row['current_total'],
                'expected_unentered_total'=>$row['unentered_total']===null?null:(float)$row['unentered_total'],
                'final_forecast_total'=>$row['forecast_total']===null?null:(float)$row['forecast_total'],
                'forecast_low_total'=>$row['low_total']===null?null:(float)$row['low_total'],
                'forecast_high_total'=>$row['high_total']===null?null:(float)$row['high_total'],
                'expected_completion_rate'=>$row['completion_rate']===null?null:(float)$row['completion_rate'],
                'confidence_score'=>$score,
                'confidence_grade'=>self::confidenceGrade($score),
                'overinput_count'=>(int)$row['overinput_count'],
                'missing_count'=>(int)$row['missing_count'],
                'anomaly_count'=>(int)$row['anomaly_count'],
                'last_calculated_at'=>(string)$row['last_calculated_at']
            );
        } catch (Exception $e) {
            return $empty;
        }
    }

    private static function resultFilterSql($filters, $params)
    {
        $sql = '';
        if (isset($filters['project_id']) && (int)$filters['project_id'] > 0) {
            $sql .= ' AND project_id=:project';
            $params[':project'] = (int)$filters['project_id'];
        }
        if (empty($filters['include_zero'])) {
            $sql .= " AND (current_input_amount<>0 OR expected_unentered_amount<>0 OR final_forecast_amount<>0 OR anomaly_count>0 OR overinput_grade IN ('WATCH','WARNING','CRITICAL') OR missing_possibility_grade IN ('MEDIUM','HIGH') OR contract_risk_grade IN ('WATCH','WARNING','CRITICAL'))";
        }
        return array($sql, $params);
    }

    public static function countResults($pdo, $context, $filters = array())
    {
        $pdo = self::pdo($pdo);
        if (!$pdo || empty($context['available'])) return 0;
        try {
            $sql = 'SELECT COUNT(*) FROM `' . self::RESULT_TABLE . '` WHERE analysis_date=:date AND target_ym=:ym AND run_id=:run_id AND calculation_version=:version';
            $params = array(':date'=>$context['analysis_date'],':ym'=>$context['target_ym'],':run_id'=>(int)$context['run_id'],':version'=>self::CALCULATION_VERSION);
            $filter = self::resultFilterSql($filters, $params);
            $sql .= $filter[0];
            $params = $filter[1];
            $st = $pdo->prepare($sql);
            return $st && $st->execute($params) ? (int)$st->fetchColumn() : 0;
        } catch (Exception $e) {
            return 0;
        }
    }

    public static function listResults($pdo, $context, $filters, $page, $perPage)
    {
        $pdo = self::pdo($pdo);
        $page = max(1, (int)$page);
        $perPage = max(1, min(100, (int)$perPage));
        if (!$pdo || empty($context['available'])) return array();
        try {
            $sql = 'SELECT * FROM `' . self::RESULT_TABLE . '` WHERE analysis_date=:date AND target_ym=:ym AND run_id=:run_id AND calculation_version=:version';
            $params = array(':date'=>$context['analysis_date'],':ym'=>$context['target_ym'],':run_id'=>(int)$context['run_id'],':version'=>self::CALCULATION_VERSION);
            $filter = self::resultFilterSql($filters, $params);
            $sql .= $filter[0];
            $params = $filter[1];
            $sql .= ' ORDER BY CASE overinput_grade WHEN \'CRITICAL\' THEN 1 WHEN \'WARNING\' THEN 2 WHEN \'WATCH\' THEN 3 ELSE 4 END, final_forecast_amount DESC LIMIT :limit OFFSET :offset';
            $st = $pdo->prepare($sql);
            if (!$st) return array();
            foreach ($params as $key=>$value) $st->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            $st->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $st->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
            if (!$st->execute()) return array();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : array();
        } catch (Exception $e) {
            return array();
        }
    }

    public static function categoryRows($pdo, $analysisDate, $targetYm, $projectId = 0, $runId = 0)
    {
        $pdo = self::pdo($pdo);
        if (!$pdo || !self::isInstalled($pdo)) return array();
        try {
            $sql = 'SELECT * FROM `' . self::CATEGORY_TABLE . '` WHERE analysis_date=:date AND target_ym=:ym AND calculation_version=:version';
            $params = array(':date'=>$analysisDate,':ym'=>$targetYm,':version'=>self::CALCULATION_VERSION);
            if ((int)$runId > 0) {
                $sql .= ' AND run_id=:run_id';
                $params[':run_id'] = (int)$runId;
            }
            if ((int)$projectId > 0) {
                $sql .= ' AND project_id=:project';
                $params[':project'] = (int)$projectId;
            }
            $sql .= ' ORDER BY project_id,cost_type';
            $st = $pdo->prepare($sql);
            if (!$st || !$st->execute($params)) return array();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : array();
        } catch (Exception $e) {
            return array();
        }
    }

    public static function availableMonths($pdo = null)
    {
        $pdo = self::pdo($pdo);
        $rows = array();
        if (!$pdo || !self::isInstalled($pdo)) return $rows;
        try {
            $sql = 'SELECT DISTINCT r.target_ym FROM `' . self::RESULT_TABLE . '` r '
                . 'INNER JOIN `' . self::RUN_TABLE . '` u ON u.id=r.run_id AND u.run_status=\'COMPLETED\' AND u.calculation_version=:run_version '
                . 'WHERE r.calculation_version=:result_version ORDER BY r.target_ym DESC LIMIT 36';
            $st = $pdo->prepare($sql);
            if (!$st || !$st->execute(array(':run_version'=>self::CALCULATION_VERSION, ':result_version'=>self::CALCULATION_VERSION))) return $rows;
            foreach ((array)$st->fetchAll(PDO::FETCH_ASSOC) as $row) $rows[] = (string)$row['target_ym'];
        } catch (Exception $e) {
        }
        return $rows;
    }
}
?>
