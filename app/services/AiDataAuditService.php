<?php
/**
 * AI 예측 도입 전 운영 데이터 준비상태를 읽기 전용으로 점검한다.
 *
 * - PHP 5.6 / MySQL 5.6 호환
 * - 원본 행을 불러오지 않고 집계 결과만 조회
 * - 테이블이나 컬럼이 없어도 각 점검 영역을 항상 반환
 */

namespace App\Services;

use App\Core\Db;
use PDO;
use Exception;

require_once __DIR__ . '/OpenAiResponsesClient.php';

class AiDataAuditService
{
    private $pdo;
    private $tableCache = array();
    private $columnCache = array();
    private $attendancePdo = null;
    private $attendanceResolved = false;

    public function __construct($pdo = null)
    {
        $this->pdo = func_num_args() > 0 ? $pdo : Db::pdo();
    }

    public function pdo()
    {
        return $this->pdo;
    }

    private function pdoKey($pdo)
    {
        if ($pdo && function_exists('spl_object_hash')) {
            return spl_object_hash($pdo);
        }
        return 'default';
    }

    private function validIdentifier($value)
    {
        return preg_match('/^[A-Za-z0-9_]+$/', (string)$value) ? true : false;
    }

    public function tableExists($table, $pdo = null)
    {
        $pdo = $pdo ? $pdo : $this->pdo;
        if (!$pdo || !$this->validIdentifier($table)) return false;

        $cacheKey = $this->pdoKey($pdo) . ':' . (string)$table;
        if (isset($this->tableCache[$cacheKey])) return $this->tableCache[$cacheKey];

        try {
            $st = $pdo->prepare(
                "SELECT COUNT(*)
                   FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = :table_name"
            );
            if (!$st || !$st->bindValue(':table_name', (string)$table) || !$st->execute()) {
                $this->tableCache[$cacheKey] = false;
                return false;
            }
            $this->tableCache[$cacheKey] = ((int)$st->fetchColumn() > 0);
        } catch (Exception $e) {
            $this->tableCache[$cacheKey] = false;
        }

        return $this->tableCache[$cacheKey];
    }

    public function getTableColumns($table, $pdo = null)
    {
        $pdo = $pdo ? $pdo : $this->pdo;
        if (!$pdo || !$this->validIdentifier($table)) return array();

        $cacheKey = $this->pdoKey($pdo) . ':' . (string)$table;
        if (isset($this->columnCache[$cacheKey])) return $this->columnCache[$cacheKey];

        $columns = array();
        try {
            $st = $pdo->prepare(
                "SELECT COLUMN_NAME
                   FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = :table_name
                  ORDER BY ORDINAL_POSITION"
            );
            if (!$st || !$st->bindValue(':table_name', (string)$table) || !$st->execute()) {
                $this->columnCache[$cacheKey] = array();
                return $this->columnCache[$cacheKey];
            }
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                if (!isset($row['COLUMN_NAME'])) continue;
                $column = (string)$row['COLUMN_NAME'];
                if ($this->validIdentifier($column)) $columns[$column] = true;
            }
        } catch (Exception $e) {
            $columns = array();
        }

        $this->columnCache[$cacheKey] = $columns;
        return $this->columnCache[$cacheKey];
    }

    public function columnExists($table, $column, $pdo = null)
    {
        if (!$this->validIdentifier($column)) return false;
        $columns = $this->getTableColumns($table, $pdo);
        return isset($columns[(string)$column]);
    }

    /**
     * 내부에서 조립한 SELECT 집계만 실행한다. 예외 원문은 반환하지 않는다.
     */
    public function safeAggregate($sql, $params = array(), $failureMessage = '데이터 집계 실패', $pdo = null)
    {
        $pdo = $pdo ? $pdo : $this->pdo;
        $result = array('ok' => false, 'row' => array(), 'message' => $this->formatWarning($failureMessage));
        if (!$pdo || !preg_match('/^\s*SELECT\b/i', (string)$sql)) return $result;

        try {
            $st = $pdo->prepare((string)$sql);
            if (!$st) return $result;
            foreach ($params as $key => $value) {
                if (is_int($value)) $bound = $st->bindValue($key, $value, PDO::PARAM_INT);
                else if ($value === null) $bound = $st->bindValue($key, null, PDO::PARAM_NULL);
                else $bound = $st->bindValue($key, (string)$value, PDO::PARAM_STR);
                if (!$bound) return $result;
            }
            if (!$st->execute()) return $result;
            $row = $st->fetch(PDO::FETCH_ASSOC);
            $result['ok'] = true;
            $result['row'] = is_array($row) ? $row : array();
            $result['message'] = '';
        } catch (Exception $e) {
            $result['ok'] = false;
            $result['row'] = array();
        }
        return $result;
    }

    private function emptySection($key, $label)
    {
        return array(
            'key' => (string)$key,
            'label' => (string)$label,
            'available' => false,
            'unavailable_reason' => '',
            'score' => null,
            'grade' => '확인 불가',
            'status' => 'unavailable',
            'row_count' => 0,
            'project_count' => 0,
            'month_count' => 0,
            'first_date' => '',
            'last_date' => '',
            'data_span_months' => 0,
            'learning_judgement' => '데이터 없음',
            'metrics' => array(),
            'missing_tables' => array(),
            'missing_columns' => array(),
            'warnings' => array(),
            'recommendations' => array(),
            'highlights' => array(),
        );
    }

    private function addMissingTable(&$section, $table)
    {
        if (!in_array((string)$table, $section['missing_tables'], true)) {
            $section['missing_tables'][] = (string)$table;
        }
    }

    private function addMissingColumn(&$section, $table, $column)
    {
        $value = (string)$table . '.' . (string)$column;
        if (!in_array($value, $section['missing_columns'], true)) {
            $section['missing_columns'][] = $value;
        }
    }

    private function addWarning(&$section, $message)
    {
        $message = $this->formatWarning($message);
        if ($message !== '' && !in_array($message, $section['warnings'], true)) {
            $section['warnings'][] = $message;
        }
    }

    private function addRecommendation(&$section, $message)
    {
        $message = trim((string)$message);
        if ($message !== '' && !in_array($message, $section['recommendations'], true)) {
            $section['recommendations'][] = $message;
        }
    }

    private function addMetric(&$section, $label, $result, $rate, $judgement, $description)
    {
        $section['metrics'][] = array(
            'label' => (string)$label,
            'result' => (string)$result,
            'rate' => $rate === null ? null : round((float)$rate, 1),
            'rate_label' => $rate === null ? '-' : number_format((float)$rate, 1) . '%',
            'judgement' => (string)$judgement,
            'description' => (string)$description,
        );
    }

    private function countText($value, $unit = '건')
    {
        return number_format((int)$value) . (string)$unit;
    }

    private function coverageRate($count, $total)
    {
        $total = (int)$total;
        if ($total <= 0) return 0.0;
        return round(((int)$count / $total) * 100, 1);
    }

    private function coverageResult($count, $total)
    {
        return $this->countText($total) . ' 중 ' . $this->countText($count);
    }

    private function rateJudgement($rate, $total)
    {
        if ((int)$total <= 0) return '데이터 없음';
        $rate = (float)$rate;
        if ($rate >= 95) return '양호';
        if ($rate >= 80) return '보완 필요';
        return '부족';
    }

    private function scoreFromRate($rate)
    {
        return max(0.0, min(100.0, (float)$rate));
    }

    private function monthScore($months)
    {
        $months = (int)$months;
        if ($months <= 0) return 0.0;
        if ($months <= 2) return 30.0;
        if ($months <= 5) return 60.0;
        if ($months <= 11) return 80.0;
        return 100.0;
    }

    private function learningJudgement($months)
    {
        $months = (int)$months;
        if ($months <= 0) return '데이터 없음';
        if ($months <= 2) return '학습자료 부족';
        if ($months <= 5) return '시범 예측 가능';
        if ($months <= 11) return '기본 예측 가능';
        return '계절성 분석 가능';
    }

    private function presentCondition($column, $dateType)
    {
        if (!$this->validIdentifier($column)) return '1=0';
        $quoted = '`' . $column . '`';
        if ($dateType) {
            return $quoted . " IS NOT NULL AND " . $quoted . " <> '0000-00-00' AND " . $quoted . " <> '0000-00-00 00:00:00'";
        }
        return $quoted . " IS NOT NULL AND TRIM(CAST(" . $quoted . " AS CHAR)) <> ''";
    }

    private function firstExistingColumn($table, $candidates, $pdo = null)
    {
        foreach ($candidates as $candidate) {
            if ($this->columnExists($table, $candidate, $pdo)) return (string)$candidate;
        }
        return '';
    }

    private function anyPresentCondition($table, $candidates, $pdo = null)
    {
        $conditions = array();
        foreach ($candidates as $candidate) {
            if ($this->columnExists($table, $candidate, $pdo)) {
                $conditions[] = '(' . $this->presentCondition($candidate, false) . ')';
            }
        }
        return count($conditions) > 0 ? implode(' OR ', $conditions) : '';
    }

    private function structureScore($tables, $columns)
    {
        $total = count($tables) + count($columns);
        if ($total <= 0) return 0.0;
        $found = 0;
        foreach ($tables as $table => $exists) if ($exists) $found++;
        foreach ($columns as $key => $exists) if ($exists) $found++;
        return round(($found / $total) * 100, 1);
    }

    private function setSectionSpan(&$section, $firstDate, $lastDate, $monthCount)
    {
        $section['first_date'] = trim((string)$firstDate);
        $section['last_date'] = trim((string)$lastDate);
        $section['month_count'] = max(0, (int)$monthCount);
        $section['data_span_months'] = $section['month_count'];
        $section['learning_judgement'] = $this->learningJudgement($section['month_count']);
    }

    public function calculateSectionScore($scoreItems)
    {
        $sum = 0.0;
        $count = 0;
        foreach ($scoreItems as $item) {
            if (!is_array($item) || empty($item['applicable'])) continue;
            $score = isset($item['score']) ? (float)$item['score'] : 0.0;
            $sum += max(0.0, min(100.0, $score));
            $count++;
        }
        if ($count <= 0) return null;
        return (int)round($sum / $count);
    }

    private function finalizeSection($section, $scoreItems)
    {
        $section['warnings'] = array_values(array_unique($section['warnings']));
        $section['recommendations'] = array_values(array_unique($section['recommendations']));
        if (empty($section['available'])) {
            $section['score'] = null;
            $section['grade'] = '확인 불가';
            $section['status'] = 'unavailable';
            if ($section['unavailable_reason'] === '') $section['unavailable_reason'] = '자료 구조를 확인할 수 없습니다.';
            return $section;
        }

        $score = $this->calculateSectionScore($scoreItems);
        if ($score === null) {
            $section['available'] = false;
            $section['unavailable_reason'] = '평가 가능한 항목이 없습니다.';
            $section['score'] = null;
            $section['grade'] = '확인 불가';
            $section['status'] = 'unavailable';
            return $section;
        }

        $section['score'] = $score;
        $section['grade'] = $this->scoreGrade($score);
        if ($score >= 90) $section['status'] = 'excellent';
        else if ($score >= 75) $section['status'] = 'good';
        else if ($score >= 60) $section['status'] = 'warning';
        else $section['status'] = 'danger';
        return $section;
    }

    public function scoreGrade($score, $available = true)
    {
        if (!$available || $score === null) return '확인 불가';
        $score = (int)$score;
        if ($score >= 90) return '준비 우수';
        if ($score >= 75) return '준비 양호';
        if ($score >= 60) return '보완 필요';
        return '준비 부족';
    }

    public function formatWarning($message)
    {
        $message = trim((string)$message);
        if ($message === '') return '';
        if (preg_match('~SQLSTATE|\bSELECT\b|\bFROM\b|[A-Za-z]:[\\\\/]|/var/|/www/~i', $message)) {
            return '데이터 집계 실패';
        }
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]+/', ' ', $message);
    }

    private function attendancePdo()
    {
        if ($this->attendanceResolved) return $this->attendancePdo;
        $this->attendanceResolved = true;

        $helper = dirname(__DIR__) . '/views/construction/tabs/partials/labor_data_loader.php';
        if (is_file($helper)) require_once $helper;
        if (!function_exists('cpms_load_attendance_pdo')) return null;

        try {
            $pdo = cpms_load_attendance_pdo();
            if ($pdo instanceof PDO) $this->attendancePdo = $pdo;
        } catch (Exception $e) {
            $this->attendancePdo = null;
        }
        return $this->attendancePdo;
    }

    private function auditChangeEvidence($costType)
    {
        $result = array(
            'installed' => false,
            'row_count' => 0,
            'old_data_count' => 0,
            'requested_data_count' => 0,
            'requester_count' => 0,
        );
        $table = 'cpms_cost_change_requests';
        if (!$this->tableExists($table)) return $result;
        $hasCostType = $this->columnExists($table, 'cost_type');
        $hasTargetType = $this->columnExists($table, 'target_type');
        if (!$hasCostType && !$hasTargetType) return $result;

        $where = array();
        $params = array();
        if ($hasCostType) {
            $where[] = '`cost_type` = :cost_type';
            $params[':cost_type'] = (string)$costType;
        }
        if ($hasTargetType) {
            $where[] = '`target_type` = :target_type';
            $params[':target_type'] = (string)$costType;
        }
        $select = array('COUNT(*) AS row_count');
        $select[] = $this->columnExists($table, 'old_data')
            ? "COALESCE(SUM(CASE WHEN old_data IS NOT NULL AND TRIM(old_data) <> '' AND old_data <> '{}' THEN 1 ELSE 0 END), 0) AS old_data_count"
            : '0 AS old_data_count';
        $select[] = $this->columnExists($table, 'requested_data')
            ? "COALESCE(SUM(CASE WHEN requested_data IS NOT NULL AND TRIM(requested_data) <> '' AND requested_data <> '{}' THEN 1 ELSE 0 END), 0) AS requested_data_count"
            : '0 AS requested_data_count';
        $actorCondition = $this->anyPresentCondition($table, array('requester_employee_id', 'requester_name', 'requester_email'));
        $select[] = $actorCondition !== ''
            ? 'COALESCE(SUM(CASE WHEN ' . $actorCondition . ' THEN 1 ELSE 0 END), 0) AS requester_count'
            : '0 AS requester_count';

        $agg = $this->safeAggregate(
            'SELECT ' . implode(', ', $select) . ' FROM `' . $table . '` WHERE (' . implode(' OR ', $where) . ')',
            $params,
            '변경이력 데이터 집계 실패'
        );
        if (!$agg['ok']) return $result;
        $row = $agg['row'];
        $result['installed'] = true;
        $result['row_count'] = isset($row['row_count']) ? (int)$row['row_count'] : 0;
        $result['old_data_count'] = isset($row['old_data_count']) ? (int)$row['old_data_count'] : 0;
        $result['requested_data_count'] = isset($row['requested_data_count']) ? (int)$row['requested_data_count'] : 0;
        $result['requester_count'] = isset($row['requester_count']) ? (int)$row['requester_count'] : 0;
        return $result;
    }

    public function auditAll()
    {
        $definitions = array(
            'labor' => array('label' => '노무비', 'method' => 'auditLabor'),
            'material' => array('label' => '자재비', 'method' => 'auditMaterial'),
            'equipment' => array('label' => '장비비', 'method' => 'auditEquipment'),
            'outsourcing' => array('label' => '외주비', 'method' => 'auditOutsourcing'),
            'cost_change' => array('label' => '비용 변경이력', 'method' => 'auditCostChangeHistory'),
            'sales' => array('label' => '계약·매출', 'method' => 'auditProjectAndSales'),
        );
        $sections = array();
        foreach ($definitions as $key => $definition) {
            try {
                $method = $definition['method'];
                $sections[$key] = $this->$method();
            } catch (Exception $e) {
                $section = $this->emptySection($key, $definition['label']);
                $section['unavailable_reason'] = '데이터 집계 실패';
                $this->addWarning($section, '데이터 집계 실패');
                $sections[$key] = $this->finalizeSection($section, array());
            }
        }

        $snapshotStatus = $this->auditDailySnapshots();
        $forecastStatus = $this->auditMonthlyForecasts();
        $reliabilityStatus = $this->auditInputReliability();
        $anomalyStatus = $this->auditAnomalyDetection();
        $profitRiskStatus = $this->auditProfitRisk();
        $ceoIndexQaStatus = $this->auditCeoIndexAndQa();
        $openAiStatus = $this->auditOpenAiExecutiveBrief();
        $overall = $this->calculateOverallScore($sections);
        $globalWarnings = array();
        $globalRecommendations = array();
        if ((int)$overall['unavailable_count'] > 0) {
            $globalWarnings[] = '일부 자료 확인 불가: 확인 가능한 영역끼리 가중치를 다시 계산했습니다.';
        }
        if (!$this->pdo) {
            $globalWarnings[] = 'CPMS 데이터베이스 연결을 확인할 수 없습니다.';
        }
        if (isset($snapshotStatus['warning']) && $snapshotStatus['warning'] !== '') {
            $globalWarnings[] = $snapshotStatus['warning'];
        }
        if (isset($snapshotStatus['recommendation']) && $snapshotStatus['recommendation'] !== '') {
            $globalRecommendations[] = $snapshotStatus['recommendation'];
        }
        if (isset($forecastStatus['warning']) && $forecastStatus['warning'] !== '') {
            $globalWarnings[] = $forecastStatus['warning'];
        }
        if (isset($forecastStatus['recommendation']) && $forecastStatus['recommendation'] !== '') {
            $globalRecommendations[] = $forecastStatus['recommendation'];
        }
        if (isset($reliabilityStatus['warning']) && $reliabilityStatus['warning'] !== '') {
            $globalWarnings[] = $reliabilityStatus['warning'];
        }
        if (isset($reliabilityStatus['recommendation']) && $reliabilityStatus['recommendation'] !== '') {
            $globalRecommendations[] = $reliabilityStatus['recommendation'];
        }
        if (isset($anomalyStatus['warning']) && $anomalyStatus['warning'] !== '') {
            $globalWarnings[] = $anomalyStatus['warning'];
        }
        if (isset($anomalyStatus['recommendation']) && $anomalyStatus['recommendation'] !== '') {
            $globalRecommendations[] = $anomalyStatus['recommendation'];
        }
        if (isset($profitRiskStatus['warning']) && $profitRiskStatus['warning'] !== '') {
            $globalWarnings[] = $profitRiskStatus['warning'];
        }
        if (isset($profitRiskStatus['recommendation']) && $profitRiskStatus['recommendation'] !== '') {
            $globalRecommendations[] = $profitRiskStatus['recommendation'];
        }
        if (isset($ceoIndexQaStatus['warning']) && $ceoIndexQaStatus['warning'] !== '') {
            $globalWarnings[] = $ceoIndexQaStatus['warning'];
        }
        if (isset($ceoIndexQaStatus['recommendation']) && $ceoIndexQaStatus['recommendation'] !== '') {
            $globalRecommendations[] = $ceoIndexQaStatus['recommendation'];
        }
        if (isset($openAiStatus['warning']) && $openAiStatus['warning'] !== '') {
            $globalWarnings[] = $openAiStatus['warning'];
        }
        if (isset($openAiStatus['recommendation']) && $openAiStatus['recommendation'] !== '') {
            $globalRecommendations[] = $openAiStatus['recommendation'];
        }
        foreach ($sections as $section) {
            if (!empty($section['warnings'])) $globalWarnings[] = $section['label'] . ': ' . $section['warnings'][0];
            if (!empty($section['recommendations'])) $globalRecommendations[] = $section['label'] . ': ' . $section['recommendations'][0];
        }
        $globalWarnings = array_values(array_unique($globalWarnings));
        $globalRecommendations = array_values(array_unique($globalRecommendations));

        $minimumMonths = null;
        foreach ($sections as $section) {
            if (empty($section['available'])) continue;
            $months = isset($section['month_count']) ? (int)$section['month_count'] : 0;
            if ($minimumMonths === null || $months < $minimumMonths) $minimumMonths = $months;
        }
        if ($minimumMonths === null) $minimumMonths = 0;

        return array(
            'checked_at' => date('Y-m-d H:i:s'),
            'overall_score' => $overall['score'],
            'overall_grade' => $this->scoreGrade($overall['score'], $overall['score'] !== null),
            'overall_status' => $overall['status'],
            'available_weight' => $overall['available_weight'],
            'unavailable_count' => $overall['unavailable_count'],
            'minimum_learning_months' => (int)$minimumMonths,
            'minimum_learning_judgement' => $this->learningJudgement($minimumMonths),
            'sections' => $sections,
            'daily_snapshot' => $snapshotStatus,
            'monthly_forecast' => $forecastStatus,
            'input_reliability' => $reliabilityStatus,
            'anomaly_detection' => $anomalyStatus,
            'profit_risk' => $profitRiskStatus,
            'ceo_index_qa' => $ceoIndexQaStatus,
            'openai_executive_brief' => $openAiStatus,
            'global_warnings' => $globalWarnings,
            'global_recommendations' => $globalRecommendations,
            'read_only' => true,
            'gpt_connected' => !empty($openAiStatus['api_key_configured']) && !empty($openAiStatus['curl_available']),
        );
    }

    public function auditDailySnapshots()
    {
        $result = array(
            'run_table_installed' => false,
            'snapshot_table_installed' => false,
            'installed' => false,
            'snapshot_date_count' => 0,
            'project_count' => 0,
            'first_snapshot_date' => '',
            'latest_snapshot_date' => '',
            'latest_run_status' => '',
            'latest_run_failure_count' => 0,
            'setup_required' => false,
            'message' => '',
            'warning' => '',
            'recommendation' => '',
        );
        if (!$this->pdo) {
            $result['setup_required'] = true;
            $result['message'] = '일일 스냅샷 DB 상태를 확인할 수 없습니다.';
            return $result;
        }

        $runTable = 'cpms_ai_snapshot_runs';
        $snapshotTable = 'cpms_ai_daily_snapshots';
        $runRequired = array('snapshot_date', 'run_status', 'failure_count', 'started_at');
        $snapshotRequired = array('snapshot_date', 'project_id', 'last_captured_at');
        $result['run_table_installed'] = $this->tableExists($runTable);
        $result['snapshot_table_installed'] = $this->tableExists($snapshotTable);
        foreach ($runRequired as $column) {
            if (!$this->columnExists($runTable, $column)) $result['run_table_installed'] = false;
        }
        foreach ($snapshotRequired as $column) {
            if (!$this->columnExists($snapshotTable, $column)) $result['snapshot_table_installed'] = false;
        }
        $result['installed'] = $result['run_table_installed'] && $result['snapshot_table_installed'];
        if (!$result['installed']) {
            $result['setup_required'] = true;
            $result['message'] = '일일 현황 스냅샷이 아직 설치되지 않았습니다.';
            $result['warning'] = '일일 현황 스냅샷이 아직 설치되지 않았습니다.';
            $result['recommendation'] = '일일 스냅샷 설정에서 테이블을 설치해주세요.';
            return $result;
        }

        $snapshotAgg = $this->safeAggregate(
            "SELECT COUNT(DISTINCT snapshot_date) AS date_count, COUNT(DISTINCT project_id) AS project_count, MIN(snapshot_date) AS first_date, MAX(snapshot_date) AS latest_date FROM `" . $snapshotTable . "`",
            array(),
            '일일 스냅샷 집계 실패'
        );
        if ($snapshotAgg['ok']) {
            $row = $snapshotAgg['row'];
            $result['snapshot_date_count'] = isset($row['date_count']) ? (int)$row['date_count'] : 0;
            $result['project_count'] = isset($row['project_count']) ? (int)$row['project_count'] : 0;
            $result['first_snapshot_date'] = isset($row['first_date']) && $row['first_date'] !== null ? (string)$row['first_date'] : '';
            $result['latest_snapshot_date'] = isset($row['latest_date']) && $row['latest_date'] !== null ? (string)$row['latest_date'] : '';
        }

        try {
            $st = $this->pdo->query("SELECT run_status, failure_count FROM `" . $runTable . "` ORDER BY started_at DESC LIMIT 1");
            $run = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
            if (is_array($run)) {
                $result['latest_run_status'] = isset($run['run_status']) ? (string)$run['run_status'] : '';
                $result['latest_run_failure_count'] = isset($run['failure_count']) ? (int)$run['failure_count'] : 0;
            }
        } catch (Exception $e) {
        }

        if ($result['snapshot_date_count'] === 0) {
            $result['message'] = '스냅샷 구조는 설치됐으며 오늘 자료부터 저장할 수 있습니다.';
        } else {
            $result['message'] = '현장별 일일 투입현황 스냅샷을 저장하고 있습니다.';
        }
        if ($result['latest_run_failure_count'] > 0) {
            $result['warning'] = '최근 일일 스냅샷 실행에서 일부 현장 집계 실패가 확인됐습니다.';
            $result['recommendation'] = '일일 스냅샷 설정에서 최근 실행 상태를 확인해주세요.';
        }
        return $result;
    }

    public function auditMonthlyForecasts()
    {
        $result = array(
            'run_table_installed' => false,
            'forecast_table_installed' => false,
            'installed' => false,
            'result_count' => 0,
            'project_count' => 0,
            'latest_forecast_date' => '',
            'latest_run_status' => '',
            'latest_run_insufficient_count' => 0,
            'setup_required' => false,
            'message' => '',
            'warning' => '',
            'recommendation' => '',
        );
        if (!$this->pdo) {
            $result['setup_required'] = true;
            $result['message'] = '기본 월말 예측 DB 상태를 확인할 수 없습니다.';
            return $result;
        }

        $runTable = 'cpms_ai_forecast_runs';
        $forecastTable = 'cpms_ai_monthly_forecasts';
        $runRequired = array('forecast_date', 'run_status', 'insufficient_count', 'started_at');
        $forecastRequired = array('forecast_date', 'project_id', 'data_status', 'last_calculated_at');
        $result['run_table_installed'] = $this->tableExists($runTable);
        $result['forecast_table_installed'] = $this->tableExists($forecastTable);
        foreach ($runRequired as $column) {
            if (!$this->columnExists($runTable, $column)) $result['run_table_installed'] = false;
        }
        foreach ($forecastRequired as $column) {
            if (!$this->columnExists($forecastTable, $column)) $result['forecast_table_installed'] = false;
        }
        $result['installed'] = $result['run_table_installed'] && $result['forecast_table_installed'];
        if (!$result['installed']) {
            $result['setup_required'] = true;
            $result['message'] = '기본 월말 예측 기능이 아직 설치되지 않았습니다.';
            $result['warning'] = '기본 월말 예측 기능이 아직 설치되지 않았습니다.';
            $result['recommendation'] = '기본 월말 예측 설정에서 테이블을 설치해주세요.';
            return $result;
        }

        $forecastAgg = $this->safeAggregate(
            "SELECT COUNT(*) AS result_count, COUNT(DISTINCT project_id) AS project_count, MAX(forecast_date) AS latest_date FROM `" . $forecastTable . "`",
            array(),
            '기본 월말 예측 결과 집계 실패'
        );
        if ($forecastAgg['ok']) {
            $row = $forecastAgg['row'];
            $result['result_count'] = isset($row['result_count']) ? (int)$row['result_count'] : 0;
            $result['project_count'] = isset($row['project_count']) ? (int)$row['project_count'] : 0;
            $result['latest_forecast_date'] = isset($row['latest_date']) && $row['latest_date'] !== null ? (string)$row['latest_date'] : '';
        }

        try {
            $st = $this->pdo->query("SELECT run_status, insufficient_count FROM `" . $runTable . "` ORDER BY started_at DESC,id DESC LIMIT 1");
            $run = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
            if (is_array($run)) {
                $result['latest_run_status'] = isset($run['run_status']) ? (string)$run['run_status'] : '';
                $result['latest_run_insufficient_count'] = isset($run['insufficient_count']) ? (int)$run['insufficient_count'] : 0;
            }
        } catch (Exception $e) {
        }

        if ($result['result_count'] === 0) {
            $result['message'] = '예측 구조는 설치됐으며 스냅샷 생성 후 예측을 실행할 수 있습니다.';
        } else {
            $result['message'] = '저장된 스냅샷을 기준으로 기본 월말 예측 결과를 기록하고 있습니다.';
        }
        return $result;
    }

    public function auditInputReliability()
    {
        $result = array(
            'run_table_installed' => false,
            'result_table_installed' => false,
            'installed' => false,
            'result_count' => 0,
            'project_count' => 0,
            'latest_analysis_date' => '',
            'average_score' => null,
            'insufficient_count' => 0,
            'latest_run_status' => '',
            'latest_run_failure_count' => 0,
            'setup_required' => false,
            'message' => '',
            'warning' => '',
            'recommendation' => '',
        );
        if (!$this->pdo) {
            $result['setup_required'] = true;
            $result['message'] = '입력 신뢰도 분석 DB 상태를 확인할 수 없습니다.';
            return $result;
        }

        $runTable = 'cpms_ai_reliability_runs';
        $resultTable = 'cpms_ai_input_reliability';
        $runRequired = array('analysis_date', 'run_status', 'failure_count', 'started_at');
        $resultRequired = array('analysis_date', 'project_id', 'reliability_score', 'reliability_grade', 'last_calculated_at');
        $result['run_table_installed'] = $this->tableExists($runTable);
        $result['result_table_installed'] = $this->tableExists($resultTable);
        foreach ($runRequired as $column) {
            if (!$this->columnExists($runTable, $column)) $result['run_table_installed'] = false;
        }
        foreach ($resultRequired as $column) {
            if (!$this->columnExists($resultTable, $column)) $result['result_table_installed'] = false;
        }
        $result['installed'] = $result['run_table_installed'] && $result['result_table_installed'];
        if (!$result['installed']) {
            $result['setup_required'] = true;
            $result['message'] = '입력 신뢰도 기능이 아직 설치되지 않았습니다.';
            $result['warning'] = '입력 신뢰도 기능이 아직 설치되지 않았습니다.';
            $result['recommendation'] = '입력 신뢰도 설정에서 분석 테이블을 설치해주세요.';
            return $result;
        }

        $aggregate = $this->safeAggregate(
            "SELECT COUNT(*) AS result_count, COUNT(DISTINCT project_id) AS project_count, MAX(analysis_date) AS latest_date, AVG(reliability_score) AS average_score, COALESCE(SUM(CASE WHEN reliability_grade='INSUFFICIENT' THEN 1 ELSE 0 END),0) AS insufficient_count FROM `" . $resultTable . "`",
            array(),
            '입력 신뢰도 결과 집계 실패'
        );
        if ($aggregate['ok']) {
            $row = $aggregate['row'];
            $result['result_count'] = isset($row['result_count']) ? (int)$row['result_count'] : 0;
            $result['project_count'] = isset($row['project_count']) ? (int)$row['project_count'] : 0;
            $result['latest_analysis_date'] = isset($row['latest_date']) && $row['latest_date'] !== null ? (string)$row['latest_date'] : '';
            $result['average_score'] = isset($row['average_score']) && $row['average_score'] !== null ? round((float)$row['average_score'], 2) : null;
            $result['insufficient_count'] = isset($row['insufficient_count']) ? (int)$row['insufficient_count'] : 0;
        }

        try {
            $st = $this->pdo->query("SELECT run_status, failure_count FROM `" . $runTable . "` ORDER BY started_at DESC,id DESC LIMIT 1");
            $run = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
            if (is_array($run)) {
                $result['latest_run_status'] = isset($run['run_status']) ? (string)$run['run_status'] : '';
                $result['latest_run_failure_count'] = isset($run['failure_count']) ? (int)$run['failure_count'] : 0;
            }
        } catch (Exception $e) {
        }

        if ($result['result_count'] === 0) {
            $result['message'] = '신뢰도 구조는 설치됐으며 월말 예측 실행 후 계산할 수 있습니다.';
        } else {
            $result['message'] = '저장된 예측·스냅샷·통합 비용 이벤트를 기준으로 입력 신뢰도를 기록하고 있습니다.';
        }
        if ($result['latest_run_failure_count'] > 0) {
            $result['warning'] = '최근 입력 신뢰도 실행에서 일부 현장 분석 실패가 확인됐습니다.';
            $result['recommendation'] = '입력 신뢰도 설정에서 최근 실행 상태를 확인해주세요.';
        }
        return $result;
    }

    public function auditAnomalyDetection()
    {
        $result = array(
            'run_table_installed'=>false,'result_table_installed'=>false,'installed'=>false,
            'result_count'=>0,'project_count'=>0,'latest_analysis_date'=>'','normal_count'=>0,
            'watch_count'=>0,'warning_count'=>0,'critical_count'=>0,'insufficient_count'=>0,
            'latest_run_status'=>'','latest_run_failure_count'=>0,'setup_required'=>false,
            'message'=>'','warning'=>'','recommendation'=>'',
        );
        if (!$this->pdo) {
            $result['setup_required'] = true;
            $result['message'] = '이상징후 탐지 DB 상태를 확인할 수 없습니다.';
            return $result;
        }
        $runTable = 'cpms_ai_anomaly_runs';
        $resultTable = 'cpms_ai_anomaly_results';
        $runRequired = array('analysis_date','run_status','failure_count','started_at');
        $resultRequired = array('analysis_date','project_id','anomaly_grade','anomaly_count','last_calculated_at');
        $result['run_table_installed'] = $this->tableExists($runTable);
        $result['result_table_installed'] = $this->tableExists($resultTable);
        foreach ($runRequired as $column) if (!$this->columnExists($runTable,$column)) $result['run_table_installed'] = false;
        foreach ($resultRequired as $column) if (!$this->columnExists($resultTable,$column)) $result['result_table_installed'] = false;
        $result['installed'] = $result['run_table_installed'] && $result['result_table_installed'];
        if (!$result['installed']) {
            $result['setup_required'] = true;
            $result['message'] = '이상징후 탐지 기능이 아직 설치되지 않았습니다.';
            $result['warning'] = '이상징후 탐지 기능이 아직 설치되지 않았습니다.';
            $result['recommendation'] = '이상징후 탐지 설정에서 분석 테이블을 설치해주세요.';
            return $result;
        }
        $aggregate = $this->safeAggregate(
            "SELECT COUNT(*) AS result_count,COUNT(DISTINCT project_id) AS project_count,MAX(analysis_date) AS latest_date,"
            . "COALESCE(SUM(CASE WHEN anomaly_grade='NORMAL' THEN 1 ELSE 0 END),0) AS normal_count,"
            . "COALESCE(SUM(CASE WHEN anomaly_grade='WATCH' THEN 1 ELSE 0 END),0) AS watch_count,"
            . "COALESCE(SUM(CASE WHEN anomaly_grade='WARNING' THEN 1 ELSE 0 END),0) AS warning_count,"
            . "COALESCE(SUM(CASE WHEN anomaly_grade='CRITICAL' THEN 1 ELSE 0 END),0) AS critical_count,"
            . "COALESCE(SUM(CASE WHEN anomaly_grade='INSUFFICIENT' THEN 1 ELSE 0 END),0) AS insufficient_count FROM `" . $resultTable . "`",
            array(),
            '이상징후 탐지 결과 집계 실패'
        );
        if ($aggregate['ok']) {
            $row = $aggregate['row'];
            foreach (array('result_count','project_count','normal_count','watch_count','warning_count','critical_count','insufficient_count') as $key) $result[$key] = isset($row[$key])?(int)$row[$key]:0;
            $result['latest_analysis_date'] = isset($row['latest_date'])&&$row['latest_date']!==null?(string)$row['latest_date']:'';
        }
        try {
            $st = $this->pdo->query("SELECT run_status,failure_count FROM `" . $runTable . "` ORDER BY started_at DESC,id DESC LIMIT 1");
            $run = $st?$st->fetch(PDO::FETCH_ASSOC):false;
            if (is_array($run)) {
                $result['latest_run_status'] = isset($run['run_status'])?(string)$run['run_status']:'';
                $result['latest_run_failure_count'] = isset($run['failure_count'])?(int)$run['failure_count']:0;
            }
        } catch (Exception $e) {
        }
        if ($result['result_count'] === 0) $result['message'] = '이상징후 탐지 구조는 설치됐으며 입력 신뢰도 계산 후 실행할 수 있습니다.';
        else $result['message'] = '저장된 신뢰도·예측·스냅샷·통합 비용 이벤트를 기준으로 이상징후를 기록하고 있습니다.';
        if ($result['latest_run_failure_count'] > 0) {
            $result['warning'] = '최근 이상징후 탐지 실행에서 일부 현장 분석 실패가 확인됐습니다.';
            $result['recommendation'] = '이상징후 탐지 설정에서 최근 실행 상태를 확인해주세요.';
        }
        return $result;
    }

    public function auditProfitRisk()
    {
        $result = array(
            'run_table_installed'=>false,'result_table_installed'=>false,'installed'=>false,
            'result_count'=>0,'project_count'=>0,'latest_analysis_date'=>'','normal_count'=>0,
            'watch_count'=>0,'warning_count'=>0,'critical_count'=>0,'insufficient_count'=>0,
            'latest_run_status'=>'','latest_run_failure_count'=>0,'setup_required'=>false,
            'message'=>'','warning'=>'','recommendation'=>'',
        );
        if (!$this->pdo) {
            $result['setup_required'] = true;
            $result['message'] = '적자·원가율 위험분석 DB 상태를 확인할 수 없습니다.';
            return $result;
        }
        $runTable = 'cpms_ai_profit_risk_runs';
        $resultTable = 'cpms_ai_profit_risk_results';
        $runRequired = array('analysis_date','run_status','failure_count','started_at');
        $resultRequired = array('analysis_date','project_id','risk_grade','risk_score','last_calculated_at');
        $result['run_table_installed'] = $this->tableExists($runTable);
        $result['result_table_installed'] = $this->tableExists($resultTable);
        foreach ($runRequired as $column) if (!$this->columnExists($runTable,$column)) $result['run_table_installed'] = false;
        foreach ($resultRequired as $column) if (!$this->columnExists($resultTable,$column)) $result['result_table_installed'] = false;
        $result['installed'] = $result['run_table_installed'] && $result['result_table_installed'];
        if (!$result['installed']) {
            $result['setup_required'] = true;
            $result['message'] = '적자·원가율 위험분석 기능이 아직 설치되지 않았습니다.';
            $result['warning'] = '적자·원가율 위험분석 기능이 아직 설치되지 않았습니다.';
            $result['recommendation'] = '적자·원가율 위험 설정에서 분석 테이블을 설치해주세요.';
            return $result;
        }
        $aggregate = $this->safeAggregate(
            "SELECT COUNT(*) AS result_count,COUNT(DISTINCT project_id) AS project_count,MAX(analysis_date) AS latest_date,"
            . "COALESCE(SUM(CASE WHEN risk_grade='NORMAL' THEN 1 ELSE 0 END),0) AS normal_count,"
            . "COALESCE(SUM(CASE WHEN risk_grade='WATCH' THEN 1 ELSE 0 END),0) AS watch_count,"
            . "COALESCE(SUM(CASE WHEN risk_grade='WARNING' THEN 1 ELSE 0 END),0) AS warning_count,"
            . "COALESCE(SUM(CASE WHEN risk_grade='CRITICAL' THEN 1 ELSE 0 END),0) AS critical_count,"
            . "COALESCE(SUM(CASE WHEN risk_grade='INSUFFICIENT' THEN 1 ELSE 0 END),0) AS insufficient_count FROM `" . $resultTable . "`",
            array(),
            '적자·원가율 위험분석 결과 집계 실패'
        );
        if ($aggregate['ok']) {
            $row = $aggregate['row'];
            foreach (array('result_count','project_count','normal_count','watch_count','warning_count','critical_count','insufficient_count') as $key) $result[$key] = isset($row[$key]) ? (int)$row[$key] : 0;
            $result['latest_analysis_date'] = isset($row['latest_date']) && $row['latest_date'] !== null ? (string)$row['latest_date'] : '';
        }
        try {
            $st = $this->pdo->query("SELECT run_status,failure_count FROM `" . $runTable . "` ORDER BY started_at DESC,id DESC LIMIT 1");
            $run = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
            if (is_array($run)) {
                $result['latest_run_status'] = isset($run['run_status']) ? (string)$run['run_status'] : '';
                $result['latest_run_failure_count'] = isset($run['failure_count']) ? (int)$run['failure_count'] : 0;
            }
        } catch (Exception $e) {
        }
        if ($result['result_count'] === 0) $result['message'] = '위험분석 구조는 설치됐으며 월말 예측 실행 후 분석할 수 있습니다.';
        else $result['message'] = '저장된 스냅샷·월말 예측·입력 신뢰도·이상징후를 기준으로 손익 위험을 분석하고 있습니다.';
        if ($result['latest_run_failure_count'] > 0) {
            $result['warning'] = '최근 적자·원가율 위험분석 실행에서 일부 현장 분석 실패가 확인됐습니다.';
            $result['recommendation'] = '적자·원가율 위험 설정에서 최근 실행 상태를 확인해주세요.';
        }
        return $result;
    }

    public function auditOpenAiExecutiveBrief()
    {
        $config = OpenAiResponsesClient::maskedConfigurationStatus();
        $result = array(
            'curl_available'=>!empty($config['curl_available']),
            'api_key_configured'=>!empty($config['available']),
            'api_key_source'=>isset($config['source'])?(string)$config['source']:'NONE',
            'run_table_installed'=>false,'brief_table_installed'=>false,'installed'=>false,
            'completed_count'=>0,'failed_count'=>0,'cached_count'=>0,
            'latest_run_date'=>'','latest_run_status'=>'','latest_model'=>'',
            'latest_brief_date'=>'','latest_brief_target_ym'=>'',
            'message'=>'','warning'=>'','recommendation'=>''
        );
        if (!$this->pdo) {
            $result['message'] = 'OpenAI 경영 브리핑 DB 상태를 확인할 수 없습니다.';
            return $result;
        }
        $runTable='cpms_ai_gpt_runs';
        $briefTable='cpms_ai_executive_briefs';
        $runRequired=array('task_type','run_status','model_name','started_at');
        $briefRequired=array('analysis_date','target_ym','generated_at');
        $result['run_table_installed']=$this->tableExists($runTable);
        $result['brief_table_installed']=$this->tableExists($briefTable);
        foreach($runRequired as $column) if(!$this->columnExists($runTable,$column)) $result['run_table_installed']=false;
        foreach($briefRequired as $column) if(!$this->columnExists($briefTable,$column)) $result['brief_table_installed']=false;
        $result['installed']=$result['run_table_installed']&&$result['brief_table_installed'];
        if (!$result['api_key_configured']) {
            $result['warning']='OpenAI API 키가 아직 설정되지 않았습니다.';
            $result['recommendation']='OpenAI 연결 설정에서 서버 비밀설정 상태를 확인해주세요.';
        }
        if (!$result['curl_available']) {
            $result['warning']='서버의 PHP cURL 기능을 확인해주세요.';
            $result['recommendation']='OpenAI 연결 설정에서 PHP cURL 사용 가능 여부를 확인해주세요.';
        }
        if (!$result['installed']) {
            $result['message']='OpenAI 경영 브리핑 기능이 아직 설치되지 않았습니다.';
            if ($result['recommendation']==='') $result['recommendation']='OpenAI 연결 설정에서 경영 브리핑 테이블을 설치해주세요.';
            return $result;
        }
        $aggregate=$this->safeAggregate(
            "SELECT COALESCE(SUM(CASE WHEN run_status='COMPLETED' THEN 1 ELSE 0 END),0) AS completed_count,COALESCE(SUM(CASE WHEN run_status='FAILED' OR run_status='REFUSED' THEN 1 ELSE 0 END),0) AS failed_count,COALESCE(SUM(CASE WHEN run_status='CACHED' THEN 1 ELSE 0 END),0) AS cached_count FROM `" . $runTable . "` WHERE task_type='EXECUTIVE_BRIEF'",
            array(),
            'OpenAI 실행이력 집계 실패'
        );
        if($aggregate['ok']) {
            $row=$aggregate['row'];
            foreach(array('completed_count','failed_count','cached_count') as $key) $result[$key]=isset($row[$key])?(int)$row[$key]:0;
        }
        try {
            $st=$this->pdo->query("SELECT started_at,run_status,model_name FROM `" . $runTable . "` WHERE task_type='EXECUTIVE_BRIEF' ORDER BY started_at DESC,id DESC LIMIT 1");
            $row=$st?$st->fetch(PDO::FETCH_ASSOC):false;
            if(is_array($row)) {
                $result['latest_run_date']=isset($row['started_at'])?(string)$row['started_at']:'';
                $result['latest_run_status']=isset($row['run_status'])?(string)$row['run_status']:'';
                $result['latest_model']=isset($row['model_name'])?(string)$row['model_name']:'';
            }
            $st=$this->pdo->query("SELECT generated_at,target_ym FROM `" . $briefTable . "` ORDER BY generated_at DESC,id DESC LIMIT 1");
            $row=$st?$st->fetch(PDO::FETCH_ASSOC):false;
            if(is_array($row)) {
                $result['latest_brief_date']=isset($row['generated_at'])?(string)$row['generated_at']:'';
                $result['latest_brief_target_ym']=isset($row['target_ym'])?(string)$row['target_ym']:'';
            }
        } catch(Exception $e) {
        }
        $result['message']=$result['latest_brief_date']===''?'OpenAI 연결 후 최신 위험분석을 이용해 경영 브리핑을 생성할 수 있습니다.':'최신 적자·원가율 위험분석을 이용한 대표용 경영 브리핑을 저장하고 있습니다.';
        return $result;
    }

    public function auditCeoIndexAndQa()
    {
        $config = OpenAiResponsesClient::maskedConfigurationStatus();
        $result = array(
            'run_table_installed'=>false,'result_table_installed'=>false,'project_table_installed'=>false,'qa_table_installed'=>false,'installed'=>false,
            'latest_score'=>null,'latest_grade'=>'','coverage_rate'=>null,'latest_analysis_date'=>'','latest_target_ym'=>'','latest_calculated_at'=>'',
            'question_total_count'=>0,'question_completed_count'=>0,'question_failed_count'=>0,'latest_question_at'=>'',
            'brief_model'=>isset($config['model'])?(string)$config['model']:'','qa_model'=>isset($config['qa_model'])?(string)$config['qa_model']:'',
            'latest_brief_model'=>'','latest_qa_model'=>'','message'=>'','warning'=>'','recommendation'=>''
        );
        if (!$this->pdo) {
            $result['message']='CEO Index와 대표 질문 DB 상태를 확인할 수 없습니다.';
            return $result;
        }
        $tables=array(
            'run'=>array('name'=>'cpms_ai_ceo_index_runs','required'=>array('analysis_date','run_status','started_at')),
            'result'=>array('name'=>'cpms_ai_ceo_index_results','required'=>array('analysis_date','target_ym','ceo_index_score','ceo_index_grade','coverage_rate','last_calculated_at')),
            'project'=>array('name'=>'cpms_ai_ceo_project_results','required'=>array('analysis_date','target_ym','project_id','project_index_grade')),
            'qa'=>array('name'=>'cpms_ai_executive_qa_history','required'=>array('analysis_date','target_ym','answer_status','model_name','created_at'))
        );
        foreach ($tables as $key=>$definition) {
            $installed=$this->tableExists($definition['name']);
            foreach ($definition['required'] as $column) if (!$this->columnExists($definition['name'],$column)) $installed=false;
            $result[$key . '_table_installed']=$installed;
        }
        $result['installed']=$result['run_table_installed']&&$result['result_table_installed']&&$result['project_table_installed']&&$result['qa_table_installed'];
        if (!$result['run_table_installed']||!$result['result_table_installed']||!$result['project_table_installed']) {
            $result['message']='CEO Index 기능이 아직 설치되지 않았습니다.';
            $result['warning']='CEO Index 전용 테이블이 아직 설치되지 않았습니다.';
            $result['recommendation']='CEO Index 화면에서 전용 테이블을 설치해주세요.';
        } else {
            try {
                $st=$this->pdo->query("SELECT analysis_date,target_ym,ceo_index_score,ceo_index_grade,coverage_rate,last_calculated_at FROM `cpms_ai_ceo_index_results` ORDER BY analysis_date DESC,last_calculated_at DESC,id DESC LIMIT 1");
                $row=$st?$st->fetch(PDO::FETCH_ASSOC):false;
                if (is_array($row)) {
                    $result['latest_score']=isset($row['ceo_index_score'])&&$row['ceo_index_score']!==null?round((float)$row['ceo_index_score'],2):null;
                    $result['latest_grade']=isset($row['ceo_index_grade'])?(string)$row['ceo_index_grade']:'';
                    $result['coverage_rate']=isset($row['coverage_rate'])&&$row['coverage_rate']!==null?round((float)$row['coverage_rate'],3):null;
                    $result['latest_analysis_date']=isset($row['analysis_date'])?(string)$row['analysis_date']:'';
                    $result['latest_target_ym']=isset($row['target_ym'])?(string)$row['target_ym']:'';
                    $result['latest_calculated_at']=isset($row['last_calculated_at'])?(string)$row['last_calculated_at']:'';
                }
            } catch (Exception $e) {
            }
            $result['message']=$result['latest_analysis_date']===''?'CEO Index 구조는 설치됐으며 최신 위험분석 결과로 계산할 수 있습니다.':'저장된 위험분석 결과를 기준으로 CEO Index를 기록하고 있습니다.';
        }
        if (!$result['qa_table_installed']) {
            if ($result['warning']==='') $result['warning']='대표 질문·답변 이력 테이블이 아직 설치되지 않았습니다.';
            if ($result['recommendation']==='') $result['recommendation']='CEO Index 화면에서 대표 질문 이력 테이블을 설치해주세요.';
        } else {
            $aggregate=$this->safeAggregate(
                "SELECT COUNT(*) AS total_count,COALESCE(SUM(CASE WHEN answer_status IN ('ANSWERED','LIMITED','NOT_AVAILABLE') THEN 1 ELSE 0 END),0) AS completed_count,COALESCE(SUM(CASE WHEN answer_status IN ('FAILED','REFUSED') THEN 1 ELSE 0 END),0) AS failed_count,MAX(created_at) AS latest_at FROM `cpms_ai_executive_qa_history`",
                array(),
                '대표 질문 이력 집계 실패'
            );
            if ($aggregate['ok']) {
                $row=$aggregate['row'];
                $result['question_total_count']=isset($row['total_count'])?(int)$row['total_count']:0;
                $result['question_completed_count']=isset($row['completed_count'])?(int)$row['completed_count']:0;
                $result['question_failed_count']=isset($row['failed_count'])?(int)$row['failed_count']:0;
                $result['latest_question_at']=isset($row['latest_at'])&&$row['latest_at']!==null?(string)$row['latest_at']:'';
            }
            try {
                $st=$this->pdo->query("SELECT model_name FROM `cpms_ai_executive_qa_history` ORDER BY created_at DESC,id DESC LIMIT 1");
                $model=$st?$st->fetchColumn():false;
                if ($model!==false&&$model!==null) $result['latest_qa_model']=(string)$model;
            } catch (Exception $e) {
            }
        }
        if ($this->tableExists('cpms_ai_executive_briefs')&&$this->columnExists('cpms_ai_executive_briefs','model_name')) {
            try {
                $st=$this->pdo->query("SELECT model_name FROM `cpms_ai_executive_briefs` ORDER BY generated_at DESC,id DESC LIMIT 1");
                $model=$st?$st->fetchColumn():false;
                if ($model!==false&&$model!==null) $result['latest_brief_model']=(string)$model;
            } catch (Exception $e) {
            }
        }
        return $result;
    }

    public function calculateOverallScore($sections)
    {
        $weights = array(
            'labor' => 25,
            'material' => 20,
            'equipment' => 15,
            'outsourcing' => 15,
            'cost_change' => 15,
            'sales' => 10,
        );
        $weighted = 0.0;
        $availableWeight = 0.0;
        $unavailable = 0;
        foreach ($weights as $key => $weight) {
            if (!isset($sections[$key]) || empty($sections[$key]['available']) || $sections[$key]['score'] === null) {
                $unavailable++;
                continue;
            }
            $weighted += ((float)$sections[$key]['score']) * (float)$weight;
            $availableWeight += (float)$weight;
        }
        $score = $availableWeight > 0 ? (int)round($weighted / $availableWeight) : null;
        $status = 'unavailable';
        if ($score !== null) {
            if ($score >= 90) $status = 'excellent';
            else if ($score >= 75) $status = 'good';
            else if ($score >= 60) $status = 'warning';
            else $status = 'danger';
        }
        return array(
            'score' => $score,
            'status' => $status,
            'available_weight' => (int)$availableWeight,
            'unavailable_count' => (int)$unavailable,
        );
    }

    public function auditMaterial()
    {
        $section = $this->emptySection('material', '자재비');
        if (!$this->pdo) {
            $section['unavailable_reason'] = 'CPMS DB 연결 확인 불가';
            $this->addWarning($section, '테이블 확인 실패');
            return $this->finalizeSection($section, array());
        }

        $tables = array(
            'cpms_material_items' => $this->tableExists('cpms_material_items'),
            'cpms_material_usage' => $this->tableExists('cpms_material_usage'),
            'cpms_material_statement_files' => $this->tableExists('cpms_material_statement_files'),
        );
        foreach ($tables as $table => $exists) if (!$exists) $this->addMissingTable($section, $table);

        $required = array('project_id', 'material_id', 'use_date', 'amount', 'created_at', 'memo', 'advance_yn');
        $columns = array();
        foreach ($required as $column) {
            $exists = $this->columnExists('cpms_material_usage', $column);
            $columns['cpms_material_usage.' . $column] = $exists;
            if (!$exists) $this->addMissingColumn($section, 'cpms_material_usage', $column);
        }
        $createdByColumn = $this->firstExistingColumn('cpms_material_usage', array('created_by', 'created_by_id', 'created_by_name', 'created_by_email'));
        $updatedAtColumn = $this->firstExistingColumn('cpms_material_usage', array('updated_at', 'modified_at'));
        $updatedByColumn = $this->firstExistingColumn('cpms_material_usage', array('updated_by', 'updated_by_id', 'updated_by_name', 'updated_by_email', 'modified_by'));
        if ($createdByColumn === '') $this->addMissingColumn($section, 'cpms_material_usage', 'created_by');
        if ($updatedAtColumn === '') $this->addMissingColumn($section, 'cpms_material_usage', 'updated_at');
        if ($updatedByColumn === '') $this->addMissingColumn($section, 'cpms_material_usage', 'updated_by');

        if (!$tables['cpms_material_usage']) {
            $section['unavailable_reason'] = '자재 사용내역 테이블 없음';
            $this->addWarning($section, '자재 사용내역 테이블을 확인할 수 없습니다.');
            $this->addRecommendation($section, '현재 저장 구조에 자재 사용내역 테이블과 필수 컬럼을 마련해야 합니다.');
            return $this->finalizeSection($section, array());
        }

        $select = array('COUNT(*) AS row_count');
        $select[] = $this->columnExists('cpms_material_usage', 'project_id') ? 'COUNT(DISTINCT project_id) AS project_count' : '0 AS project_count';
        $select[] = $this->columnExists('cpms_material_usage', 'use_date') ? 'COALESCE(SUM(CASE WHEN ' . $this->presentCondition('use_date', true) . ' THEN 1 ELSE 0 END), 0) AS date_count' : '0 AS date_count';
        $select[] = $this->columnExists('cpms_material_usage', 'amount') ? 'COALESCE(SUM(CASE WHEN amount IS NOT NULL THEN 1 ELSE 0 END), 0) AS amount_count' : '0 AS amount_count';
        $select[] = $this->columnExists('cpms_material_usage', 'created_at') ? 'COALESCE(SUM(CASE WHEN ' . $this->presentCondition('created_at', true) . ' THEN 1 ELSE 0 END), 0) AS created_count' : '0 AS created_count';
        $select[] = $createdByColumn !== '' ? 'COALESCE(SUM(CASE WHEN ' . $this->presentCondition($createdByColumn, false) . ' THEN 1 ELSE 0 END), 0) AS created_by_count' : '0 AS created_by_count';
        $select[] = $updatedAtColumn !== '' ? 'COALESCE(SUM(CASE WHEN ' . $this->presentCondition($updatedAtColumn, true) . ' THEN 1 ELSE 0 END), 0) AS updated_count' : '0 AS updated_count';
        $select[] = $updatedByColumn !== '' ? 'COALESCE(SUM(CASE WHEN ' . $this->presentCondition($updatedByColumn, false) . ' THEN 1 ELSE 0 END), 0) AS updated_by_count' : '0 AS updated_by_count';
        if ($this->columnExists('cpms_material_usage', 'use_date')) {
            $select[] = "MIN(CASE WHEN " . $this->presentCondition('use_date', true) . " THEN use_date ELSE NULL END) AS first_date";
            $select[] = "MAX(CASE WHEN " . $this->presentCondition('use_date', true) . " THEN use_date ELSE NULL END) AS last_date";
            $select[] = "COUNT(DISTINCT CASE WHEN " . $this->presentCondition('use_date', true) . " THEN DATE_FORMAT(use_date, '%Y-%m') ELSE NULL END) AS month_count";
        } else {
            $select[] = "'' AS first_date";
            $select[] = "'' AS last_date";
            $select[] = '0 AS month_count';
        }
        $agg = $this->safeAggregate('SELECT ' . implode(', ', $select) . ' FROM `cpms_material_usage`', array(), '자재비 데이터 집계 실패');
        if (!$agg['ok']) {
            $section['unavailable_reason'] = '자재비 데이터 집계 실패';
            $this->addWarning($section, '자재비 데이터 집계 실패');
            return $this->finalizeSection($section, array());
        }

        $row = $agg['row'];
        $total = isset($row['row_count']) ? (int)$row['row_count'] : 0;
        $section['available'] = true;
        $section['row_count'] = $total;
        $section['project_count'] = isset($row['project_count']) ? (int)$row['project_count'] : 0;
        $this->setSectionSpan($section, isset($row['first_date']) ? $row['first_date'] : '', isset($row['last_date']) ? $row['last_date'] : '', isset($row['month_count']) ? $row['month_count'] : 0);

        $structureScore = $this->structureScore($tables, $columns);
        $this->addMetric($section, '필수 테이블·컬럼 구조', count($section['missing_tables']) === 0 && count($section['missing_columns']) <= 3 ? '기본 구조 확인' : '보완 필요', $structureScore, $structureScore >= 90 ? '양호' : '보완 필요', '자재 마스터, 사용내역, 거래명세표 및 기본 컬럼을 확인합니다.');
        $dateRate = $this->coverageRate(isset($row['date_count']) ? $row['date_count'] : 0, $total);
        $amountRate = $this->coverageRate(isset($row['amount_count']) ? $row['amount_count'] : 0, $total);
        $createdRate = $this->coverageRate(isset($row['created_count']) ? $row['created_count'] : 0, $total);
        $createdByRate = $this->coverageRate(isset($row['created_by_count']) ? $row['created_by_count'] : 0, $total);
        $updatedRate = $this->coverageRate(isset($row['updated_count']) ? $row['updated_count'] : 0, $total);
        $updatedByRate = $this->coverageRate(isset($row['updated_by_count']) ? $row['updated_by_count'] : 0, $total);
        $this->addMetric($section, '총 사용내역', $this->countText($total), null, $total > 0 ? '확인' : '데이터 없음', '개인정보 없이 전체 건수만 집계합니다.');
        $this->addMetric($section, '실제 사용일', $this->coverageResult(isset($row['date_count']) ? $row['date_count'] : 0, $total), $dateRate, $this->rateJudgement($dateRate, $total), 'use_date 저장률입니다.');
        $this->addMetric($section, '금액', $this->coverageResult(isset($row['amount_count']) ? $row['amount_count'] : 0, $total), $amountRate, $this->rateJudgement($amountRate, $total), '0원을 포함해 amount 값의 존재 여부를 확인합니다.');
        $this->addMetric($section, '최초 입력시각', $this->coverageResult(isset($row['created_count']) ? $row['created_count'] : 0, $total), $createdRate, $this->rateJudgement($createdRate, $total), 'created_at 저장률입니다.');
        $this->addMetric($section, '최초 입력자', $createdByColumn === '' ? '컬럼 없음' : $this->coverageResult(isset($row['created_by_count']) ? $row['created_by_count'] : 0, $total), $createdByColumn === '' ? null : $createdByRate, $createdByColumn === '' ? '부족' : $this->rateJudgement($createdByRate, $total), '등록자 개인정보는 표시하지 않고 보유율만 집계합니다.');
        $this->addMetric($section, '최종 수정시각', $updatedAtColumn === '' ? '컬럼 없음' : $this->coverageResult(isset($row['updated_count']) ? $row['updated_count'] : 0, $total), $updatedAtColumn === '' ? null : $updatedRate, $updatedAtColumn === '' ? '부족' : $this->rateJudgement($updatedRate, $total), '일반 당월 수정의 시각 추적 가능 여부입니다.');
        $this->addMetric($section, '최종 수정자', $updatedByColumn === '' ? '컬럼 없음' : $this->coverageResult(isset($row['updated_by_count']) ? $row['updated_by_count'] : 0, $total), $updatedByColumn === '' ? null : $updatedByRate, $updatedByColumn === '' ? '부족' : $this->rateJudgement($updatedByRate, $total), '수정자 개인정보는 표시하지 않고 보유율만 집계합니다.');

        $history = $this->auditChangeEvidence('material');
        $historyScore = 0.0;
        if ($history['row_count'] > 0) {
            $historyCoverage = ($this->coverageRate($history['old_data_count'], $history['row_count']) + $this->coverageRate($history['requested_data_count'], $history['row_count'])) / 2;
            $historyScore = min(60.0, $historyCoverage * 0.6);
            $this->addMetric($section, '변경 전후 이력', $this->countText($history['row_count']) . ' (마감 승인 자료)', $historyScore, '부분확보', '마감월 승인 자료에는 전후 값이 있으나 일반 당월 수정 이력은 별도로 남지 않습니다.');
        } else {
            $this->addMetric($section, '변경 전후 이력', $history['installed'] ? '연결 이력 0건' : '공통 변경이력 구조 없음', 0, '부족', '일반 당월 수정 전후 값을 확인할 수 없습니다.');
        }

        if ($tables['cpms_material_statement_files']) {
            $statementSelect = array('COUNT(*) AS file_count');
            $statementSelect[] = $this->columnExists('cpms_material_statement_files', 'material_usage_id') ? 'COALESCE(SUM(CASE WHEN material_usage_id IS NOT NULL AND material_usage_id > 0 THEN 1 ELSE 0 END), 0) AS linked_count' : '0 AS linked_count';
            $statementWhere = $this->columnExists('cpms_material_statement_files', 'is_deleted') ? ' WHERE is_deleted = 0' : '';
            $statementAgg = $this->safeAggregate('SELECT ' . implode(', ', $statementSelect) . ' FROM `cpms_material_statement_files`' . $statementWhere, array(), '자재 거래명세표 집계 실패');
            if ($statementAgg['ok']) {
                $fileCount = isset($statementAgg['row']['file_count']) ? (int)$statementAgg['row']['file_count'] : 0;
                $linkedCount = isset($statementAgg['row']['linked_count']) ? (int)$statementAgg['row']['linked_count'] : 0;
                $fileRate = $this->coverageRate($linkedCount, $fileCount);
                $this->addMetric($section, '거래명세표 연결', $this->coverageResult($linkedCount, $fileCount), $fileRate, $this->rateJudgement($fileRate, $fileCount), '사용내역 ID와 연결된 파일 건수입니다.');
            }
        }
        $this->addMetric($section, '학습 가능 기간', $this->countText($section['month_count'], '개월'), null, $section['learning_judgement'], '실제 데이터가 존재하는 서로 다른 연월 수입니다.');

        $this->addWarning($section, '일반 당월 수정 시 변경 전 금액과 수정 담당자 이력이 충분하지 않습니다.');
        $this->addRecommendation($section, '향후 일반 수정에도 최초 입력자, 최종 수정자와 변경 전후 값을 남길 수 있는 공통 이력이 필요합니다.');
        if ($section['month_count'] < 6) $this->addRecommendation($section, '월말 예측 전 최소 6개월 이상의 자재비 사용일·금액 자료를 확보하세요.');

        return $this->finalizeSection($section, array(
            array('applicable' => true, 'score' => $structureScore),
            array('applicable' => true, 'score' => $dateRate),
            array('applicable' => true, 'score' => $createdRate),
            array('applicable' => true, 'score' => $createdByRate),
            array('applicable' => true, 'score' => $updatedRate),
            array('applicable' => true, 'score' => $updatedByRate),
            array('applicable' => true, 'score' => $historyScore),
            array('applicable' => true, 'score' => $this->monthScore($section['month_count'])),
        ));
    }

    public function auditEquipment()
    {
        $section = $this->emptySection('equipment', '장비비');
        if (!$this->pdo) {
            $section['unavailable_reason'] = 'CPMS DB 연결 확인 불가';
            $this->addWarning($section, '테이블 확인 실패');
            return $this->finalizeSection($section, array());
        }

        $tables = array(
            'cpms_equipment_items' => $this->tableExists('cpms_equipment_items'),
            'cpms_equipment_usage' => $this->tableExists('cpms_equipment_usage'),
            'cpms_equipment_gongsu_overrides' => $this->tableExists('cpms_equipment_gongsu_overrides'),
        );
        foreach ($tables as $table => $exists) if (!$exists) $this->addMissingTable($section, $table);
        $required = array('project_id', 'equipment_id', 'use_date', 'work_unit', 'base_rate_snapshot', 'amount', 'is_manual_unit', 'memo', 'created_at');
        $columns = array();
        foreach ($required as $column) {
            $exists = $this->columnExists('cpms_equipment_usage', $column);
            $columns['cpms_equipment_usage.' . $column] = $exists;
            if (!$exists) $this->addMissingColumn($section, 'cpms_equipment_usage', $column);
        }
        $createdByColumn = $this->firstExistingColumn('cpms_equipment_usage', array('created_by', 'created_by_id', 'created_by_name', 'created_by_email'));
        $updatedAtColumn = $this->firstExistingColumn('cpms_equipment_usage', array('updated_at', 'modified_at'));
        $updatedByColumn = $this->firstExistingColumn('cpms_equipment_usage', array('updated_by', 'updated_by_id', 'updated_by_name', 'updated_by_email', 'modified_by'));
        if ($createdByColumn === '') $this->addMissingColumn($section, 'cpms_equipment_usage', 'created_by');
        if ($updatedAtColumn === '') $this->addMissingColumn($section, 'cpms_equipment_usage', 'updated_at');
        if ($updatedByColumn === '') $this->addMissingColumn($section, 'cpms_equipment_usage', 'updated_by');

        if (!$tables['cpms_equipment_usage']) {
            $section['unavailable_reason'] = '장비 사용내역 테이블 없음';
            $this->addWarning($section, '장비 사용내역 테이블을 확인할 수 없습니다.');
            $this->addRecommendation($section, '현재 저장 구조에 장비 사용내역 테이블과 필수 컬럼을 마련해야 합니다.');
            return $this->finalizeSection($section, array());
        }

        $select = array('COUNT(*) AS row_count');
        $select[] = $this->columnExists('cpms_equipment_usage', 'project_id') ? 'COUNT(DISTINCT project_id) AS project_count' : '0 AS project_count';
        foreach (array('use_date' => 'date_count', 'created_at' => 'created_count') as $column => $alias) {
            $select[] = $this->columnExists('cpms_equipment_usage', $column) ? 'COALESCE(SUM(CASE WHEN ' . $this->presentCondition($column, true) . ' THEN 1 ELSE 0 END), 0) AS ' . $alias : '0 AS ' . $alias;
        }
        foreach (array('work_unit' => 'work_unit_count', 'base_rate_snapshot' => 'base_rate_count', 'amount' => 'amount_count') as $column => $alias) {
            $select[] = $this->columnExists('cpms_equipment_usage', $column) ? 'COALESCE(SUM(CASE WHEN `' . $column . '` IS NOT NULL THEN 1 ELSE 0 END), 0) AS ' . $alias : '0 AS ' . $alias;
        }
        $select[] = $createdByColumn !== '' ? 'COALESCE(SUM(CASE WHEN ' . $this->presentCondition($createdByColumn, false) . ' THEN 1 ELSE 0 END), 0) AS created_by_count' : '0 AS created_by_count';
        $select[] = $updatedAtColumn !== '' ? 'COALESCE(SUM(CASE WHEN ' . $this->presentCondition($updatedAtColumn, true) . ' THEN 1 ELSE 0 END), 0) AS updated_count' : '0 AS updated_count';
        $select[] = $updatedByColumn !== '' ? 'COALESCE(SUM(CASE WHEN ' . $this->presentCondition($updatedByColumn, false) . ' THEN 1 ELSE 0 END), 0) AS updated_by_count' : '0 AS updated_by_count';
        $select[] = $this->columnExists('cpms_equipment_usage', 'statement_stored_path') ? "COALESCE(SUM(CASE WHEN statement_stored_path IS NOT NULL AND TRIM(statement_stored_path) <> '' THEN 1 ELSE 0 END), 0) AS statement_count" : '0 AS statement_count';
        if ($this->columnExists('cpms_equipment_usage', 'use_date')) {
            $select[] = "MIN(CASE WHEN " . $this->presentCondition('use_date', true) . " THEN use_date ELSE NULL END) AS first_date";
            $select[] = "MAX(CASE WHEN " . $this->presentCondition('use_date', true) . " THEN use_date ELSE NULL END) AS last_date";
            $select[] = "COUNT(DISTINCT CASE WHEN " . $this->presentCondition('use_date', true) . " THEN DATE_FORMAT(use_date, '%Y-%m') ELSE NULL END) AS month_count";
        } else {
            $select[] = "'' AS first_date";
            $select[] = "'' AS last_date";
            $select[] = '0 AS month_count';
        }
        $agg = $this->safeAggregate('SELECT ' . implode(', ', $select) . ' FROM `cpms_equipment_usage`', array(), '장비비 데이터 집계 실패');
        if (!$agg['ok']) {
            $section['unavailable_reason'] = '장비비 데이터 집계 실패';
            $this->addWarning($section, '장비비 데이터 집계 실패');
            return $this->finalizeSection($section, array());
        }

        $row = $agg['row'];
        $total = isset($row['row_count']) ? (int)$row['row_count'] : 0;
        $section['available'] = true;
        $section['row_count'] = $total;
        $section['project_count'] = isset($row['project_count']) ? (int)$row['project_count'] : 0;
        $this->setSectionSpan($section, isset($row['first_date']) ? $row['first_date'] : '', isset($row['last_date']) ? $row['last_date'] : '', isset($row['month_count']) ? $row['month_count'] : 0);
        $structureScore = $this->structureScore($tables, $columns);
        $this->addMetric($section, '필수 테이블·컬럼 구조', count($section['missing_tables']) === 0 && count($section['missing_columns']) <= 3 ? '기본 구조 확인' : '보완 필요', $structureScore, $structureScore >= 90 ? '양호' : '보완 필요', '장비 마스터, 사용내역, 공수 조정 구조를 확인합니다.');

        $dateRate = $this->coverageRate(isset($row['date_count']) ? $row['date_count'] : 0, $total);
        $workUnitRate = $this->coverageRate(isset($row['work_unit_count']) ? $row['work_unit_count'] : 0, $total);
        $baseRateRate = $this->coverageRate(isset($row['base_rate_count']) ? $row['base_rate_count'] : 0, $total);
        $amountRate = $this->coverageRate(isset($row['amount_count']) ? $row['amount_count'] : 0, $total);
        $createdRate = $this->coverageRate(isset($row['created_count']) ? $row['created_count'] : 0, $total);
        $createdByRate = $this->coverageRate(isset($row['created_by_count']) ? $row['created_by_count'] : 0, $total);
        $updatedRate = $this->coverageRate(isset($row['updated_count']) ? $row['updated_count'] : 0, $total);
        $updatedByRate = $this->coverageRate(isset($row['updated_by_count']) ? $row['updated_by_count'] : 0, $total);
        $this->addMetric($section, '총 사용내역', $this->countText($total), null, $total > 0 ? '확인' : '데이터 없음', '개인정보 없이 전체 건수만 집계합니다.');
        $this->addMetric($section, '실제 사용일', $this->coverageResult(isset($row['date_count']) ? $row['date_count'] : 0, $total), $dateRate, $this->rateJudgement($dateRate, $total), 'use_date 저장률입니다.');
        $this->addMetric($section, '기준단가', $this->coverageResult(isset($row['base_rate_count']) ? $row['base_rate_count'] : 0, $total), $baseRateRate, $this->rateJudgement($baseRateRate, $total), 'base_rate_snapshot 저장률입니다.');
        $this->addMetric($section, '금액', $this->coverageResult(isset($row['amount_count']) ? $row['amount_count'] : 0, $total), $amountRate, $this->rateJudgement($amountRate, $total), 'amount 저장률입니다.');
        $this->addMetric($section, '공수', $this->coverageResult(isset($row['work_unit_count']) ? $row['work_unit_count'] : 0, $total), $workUnitRate, $this->rateJudgement($workUnitRate, $total), 'work_unit 저장률입니다.');
        $this->addMetric($section, '최초 입력시각', $this->coverageResult(isset($row['created_count']) ? $row['created_count'] : 0, $total), $createdRate, $this->rateJudgement($createdRate, $total), 'created_at 저장률입니다.');
        $this->addMetric($section, '최초 입력자', $createdByColumn === '' ? '컬럼 없음' : $this->coverageResult(isset($row['created_by_count']) ? $row['created_by_count'] : 0, $total), $createdByColumn === '' ? null : $createdByRate, $createdByColumn === '' ? '부족' : $this->rateJudgement($createdByRate, $total), '등록자 개인정보는 표시하지 않고 보유율만 집계합니다.');
        $this->addMetric($section, '최종 수정시각', $updatedAtColumn === '' ? '컬럼 없음' : $this->coverageResult(isset($row['updated_count']) ? $row['updated_count'] : 0, $total), $updatedAtColumn === '' ? null : $updatedRate, $updatedAtColumn === '' ? '부족' : $this->rateJudgement($updatedRate, $total), '일반 수정 시각 추적 가능 여부입니다.');
        $this->addMetric($section, '최종 수정자', $updatedByColumn === '' ? '컬럼 없음' : $this->coverageResult(isset($row['updated_by_count']) ? $row['updated_by_count'] : 0, $total), $updatedByColumn === '' ? null : $updatedByRate, $updatedByColumn === '' ? '부족' : $this->rateJudgement($updatedByRate, $total), '수정자 개인정보는 표시하지 않고 보유율만 집계합니다.');
        if ($this->columnExists('cpms_equipment_usage', 'statement_stored_path')) {
            $statementRate = $this->coverageRate(isset($row['statement_count']) ? $row['statement_count'] : 0, $total);
            $this->addMetric($section, '거래명세표', $this->coverageResult(isset($row['statement_count']) ? $row['statement_count'] : 0, $total), $statementRate, $this->rateJudgement($statementRate, $total), '장비 사용내역에 연결된 거래명세표 비율입니다.');
        } else {
            $this->addMetric($section, '거래명세표', '연결 컬럼 없음', null, '보완 필요', '현재 구조는 별도 테이블이 아니라 장비 사용내역의 파일 컬럼을 사용합니다.');
        }

        $history = $this->auditChangeEvidence('equipment');
        $overrideCount = 0;
        $overrideOldCount = 0;
        if ($tables['cpms_equipment_gongsu_overrides']) {
            $overrideSelect = array('COUNT(*) AS row_count');
            $overrideSelect[] = $this->columnExists('cpms_equipment_gongsu_overrides', 'old_value') ? 'COALESCE(SUM(CASE WHEN old_value IS NOT NULL THEN 1 ELSE 0 END), 0) AS old_count' : '0 AS old_count';
            $overrideAgg = $this->safeAggregate('SELECT ' . implode(', ', $overrideSelect) . ' FROM `cpms_equipment_gongsu_overrides`', array(), '장비 공수 변경이력 집계 실패');
            if ($overrideAgg['ok']) {
                $overrideCount = isset($overrideAgg['row']['row_count']) ? (int)$overrideAgg['row']['row_count'] : 0;
                $overrideOldCount = isset($overrideAgg['row']['old_count']) ? (int)$overrideAgg['row']['old_count'] : 0;
            }
        }
        $evidenceCount = $history['row_count'] + $overrideCount;
        $historyScore = 0.0;
        if ($evidenceCount > 0) {
            $completeCount = $history['old_data_count'] + $overrideOldCount;
            $historyScore = min(70.0, $this->coverageRate($completeCount, $evidenceCount) * 0.7);
            $this->addMetric($section, '변경 전후 이력', $this->countText($evidenceCount) . ' (승인·공수 조정)', $historyScore, '부분확보', '마감 승인과 공수 조정에는 일부 전후 값이 있으나 일반 당월 수정 이력은 별도 보존되지 않습니다.');
        } else {
            $this->addMetric($section, '변경 전후 이력', '연결 이력 0건', 0, '부족', '변경 전 공수·금액을 확인할 자료가 없습니다.');
        }
        $this->addMetric($section, '학습 가능 기간', $this->countText($section['month_count'], '개월'), null, $section['learning_judgement'], '실제 데이터가 존재하는 서로 다른 연월 수입니다.');

        $this->addWarning($section, '일반 당월 수정 시 변경 전 공수·금액과 수정 담당자 이력이 충분하지 않습니다.');
        $this->addRecommendation($section, '일반 장비비 수정에도 공수·기준단가·금액의 변경 전후 값과 수정자를 남기세요.');
        if ($section['month_count'] < 6) $this->addRecommendation($section, '월말 예측 전 최소 6개월 이상의 장비 사용일·공수·금액 자료를 확보하세요.');

        return $this->finalizeSection($section, array(
            array('applicable' => true, 'score' => $structureScore),
            array('applicable' => true, 'score' => $dateRate),
            array('applicable' => true, 'score' => $createdRate),
            array('applicable' => true, 'score' => $createdByRate),
            array('applicable' => true, 'score' => $updatedRate),
            array('applicable' => true, 'score' => $updatedByRate),
            array('applicable' => true, 'score' => $historyScore),
            array('applicable' => true, 'score' => $this->monthScore($section['month_count'])),
        ));
    }

    public function auditOutsourcing()
    {
        $section = $this->emptySection('outsourcing', '외주비');
        if (!$this->pdo) {
            $section['unavailable_reason'] = 'CPMS DB 연결 확인 불가';
            $this->addWarning($section, '테이블 확인 실패');
            return $this->finalizeSection($section, array());
        }
        $table = 'cpms_outsourcing_costs';
        $fileTable = 'cpms_outsourcing_cost_files';
        $tableExists = $this->tableExists($table);
        if (!$tableExists) $this->addMissingTable($section, $table);

        $required = array('project_id', 'expense_date', 'company_name', 'amount', 'created_by_name', 'created_by_email', 'created_at', 'updated_at', 'is_deleted');
        $columns = array();
        foreach ($required as $column) {
            $exists = $this->columnExists($table, $column);
            $columns[$table . '.' . $column] = $exists;
            if (!$exists) $this->addMissingColumn($section, $table, $column);
        }
        $updatedByColumn = $this->firstExistingColumn($table, array('updated_by', 'updated_by_id', 'updated_by_name', 'updated_by_email', 'modified_by'));
        if ($updatedByColumn === '') $this->addMissingColumn($section, $table, 'updated_by');

        if (!$tableExists) {
            $section['unavailable_reason'] = '외주비 테이블 없음';
            $this->addWarning($section, '외주비 관련 테이블이 설치되지 않았습니다.');
            $this->addRecommendation($section, '외주비 사용내역 구조를 먼저 마련해야 합니다.');
            return $this->finalizeSection($section, array());
        }

        $activeCondition = $this->columnExists($table, 'is_deleted') ? 'is_deleted = 0' : '1=1';
        $creatorCondition = $this->anyPresentCondition($table, array('created_by_name', 'created_by_email'));
        $select = array('COUNT(*) AS row_count');
        $select[] = 'COALESCE(SUM(CASE WHEN ' . $activeCondition . ' THEN 1 ELSE 0 END), 0) AS active_count';
        $select[] = $this->columnExists($table, 'project_id') ? 'COUNT(DISTINCT CASE WHEN ' . $activeCondition . ' THEN project_id ELSE NULL END) AS project_count' : '0 AS project_count';
        $select[] = $this->columnExists($table, 'expense_date') ? 'COALESCE(SUM(CASE WHEN ' . $activeCondition . ' AND ' . $this->presentCondition('expense_date', true) . ' THEN 1 ELSE 0 END), 0) AS date_count' : '0 AS date_count';
        $select[] = $this->columnExists($table, 'amount') ? 'COALESCE(SUM(CASE WHEN ' . $activeCondition . ' AND amount IS NOT NULL THEN 1 ELSE 0 END), 0) AS amount_count' : '0 AS amount_count';
        $select[] = $creatorCondition !== '' ? 'COALESCE(SUM(CASE WHEN ' . $activeCondition . ' AND (' . $creatorCondition . ') THEN 1 ELSE 0 END), 0) AS creator_count' : '0 AS creator_count';
        $select[] = $this->columnExists($table, 'created_at') ? 'COALESCE(SUM(CASE WHEN ' . $activeCondition . ' AND ' . $this->presentCondition('created_at', true) . ' THEN 1 ELSE 0 END), 0) AS created_count' : '0 AS created_count';
        $select[] = $this->columnExists($table, 'updated_at') ? 'COALESCE(SUM(CASE WHEN ' . $activeCondition . ' AND ' . $this->presentCondition('updated_at', true) . ' THEN 1 ELSE 0 END), 0) AS updated_count' : '0 AS updated_count';
        $select[] = $updatedByColumn !== '' ? 'COALESCE(SUM(CASE WHEN ' . $activeCondition . ' AND ' . $this->presentCondition($updatedByColumn, false) . ' THEN 1 ELSE 0 END), 0) AS updated_by_count' : '0 AS updated_by_count';
        if ($this->columnExists($table, 'expense_date')) {
            $select[] = 'MIN(CASE WHEN ' . $activeCondition . ' AND ' . $this->presentCondition('expense_date', true) . ' THEN expense_date ELSE NULL END) AS first_date';
            $select[] = 'MAX(CASE WHEN ' . $activeCondition . ' AND ' . $this->presentCondition('expense_date', true) . ' THEN expense_date ELSE NULL END) AS last_date';
            $select[] = "COUNT(DISTINCT CASE WHEN " . $activeCondition . ' AND ' . $this->presentCondition('expense_date', true) . " THEN DATE_FORMAT(expense_date, '%Y-%m') ELSE NULL END) AS month_count";
        } else {
            $select[] = "'' AS first_date";
            $select[] = "'' AS last_date";
            $select[] = '0 AS month_count';
        }
        $agg = $this->safeAggregate('SELECT ' . implode(', ', $select) . ' FROM `' . $table . '`', array(), '외주비 데이터 집계 실패');
        if (!$agg['ok']) {
            $section['unavailable_reason'] = '외주비 데이터 집계 실패';
            $this->addWarning($section, '외주비 데이터 집계 실패');
            return $this->finalizeSection($section, array());
        }

        $row = $agg['row'];
        $total = isset($row['row_count']) ? (int)$row['row_count'] : 0;
        $active = isset($row['active_count']) ? (int)$row['active_count'] : 0;
        $section['available'] = true;
        $section['row_count'] = $total;
        $section['project_count'] = isset($row['project_count']) ? (int)$row['project_count'] : 0;
        $this->setSectionSpan($section, isset($row['first_date']) ? $row['first_date'] : '', isset($row['last_date']) ? $row['last_date'] : '', isset($row['month_count']) ? $row['month_count'] : 0);

        $structureScore = $this->structureScore(array($table => true), $columns);
        $dateRate = $this->coverageRate(isset($row['date_count']) ? $row['date_count'] : 0, $active);
        $amountRate = $this->coverageRate(isset($row['amount_count']) ? $row['amount_count'] : 0, $active);
        $creatorRate = $this->coverageRate(isset($row['creator_count']) ? $row['creator_count'] : 0, $active);
        $createdRate = $this->coverageRate(isset($row['created_count']) ? $row['created_count'] : 0, $active);
        $updatedRate = $this->coverageRate(isset($row['updated_count']) ? $row['updated_count'] : 0, $active);
        $updatedByRate = $this->coverageRate(isset($row['updated_by_count']) ? $row['updated_by_count'] : 0, $active);
        $this->addMetric($section, '필수 테이블·컬럼 구조', count($section['missing_columns']) <= 1 ? '기본 구조 확인' : '보완 필요', $structureScore, $structureScore >= 90 ? '양호' : '보완 필요', '외주비 입력 테이블과 필수 컬럼을 확인합니다.');
        $this->addMetric($section, '전체 자료', $this->countText($total), null, $total > 0 ? '확인' : '데이터 없음', '삭제 표시된 자료를 포함한 전체 건수입니다.');
        $this->addMetric($section, '삭제되지 않은 자료', $this->countText($active), null, $active > 0 ? '확인' : '데이터 없음', '현재 유효한 외주비 건수입니다.');
        $this->addMetric($section, '실제 발생일', $this->coverageResult(isset($row['date_count']) ? $row['date_count'] : 0, $active), $dateRate, $this->rateJudgement($dateRate, $active), 'expense_date 저장률입니다.');
        $this->addMetric($section, '금액', $this->coverageResult(isset($row['amount_count']) ? $row['amount_count'] : 0, $active), $amountRate, $this->rateJudgement($amountRate, $active), 'amount 저장률입니다.');
        $this->addMetric($section, '최초 등록자', $creatorCondition === '' ? '컬럼 없음' : $this->coverageResult(isset($row['creator_count']) ? $row['creator_count'] : 0, $active), $creatorCondition === '' ? null : $creatorRate, $creatorCondition === '' ? '부족' : $this->rateJudgement($creatorRate, $active), '이름이나 이메일은 표시하지 않고 등록자 정보 보유율만 집계합니다.');
        $this->addMetric($section, '최초 등록시각', $this->coverageResult(isset($row['created_count']) ? $row['created_count'] : 0, $active), $createdRate, $this->rateJudgement($createdRate, $active), 'created_at 저장률입니다.');
        $this->addMetric($section, '최종 수정시각', $this->coverageResult(isset($row['updated_count']) ? $row['updated_count'] : 0, $active), $updatedRate, $this->rateJudgement($updatedRate, $active), 'updated_at 저장률입니다.');
        $this->addMetric($section, '최종 수정자', $updatedByColumn === '' ? '컬럼 없음' : $this->coverageResult(isset($row['updated_by_count']) ? $row['updated_by_count'] : 0, $active), $updatedByColumn === '' ? null : $updatedByRate, $updatedByColumn === '' ? '부족' : $this->rateJudgement($updatedByRate, $active), '수정자 개인정보는 표시하지 않고 보유율만 집계합니다.');

        $history = $this->auditChangeEvidence('outsourcing');
        $historyScore = 0.0;
        if ($history['row_count'] > 0) {
            $historyCoverage = ($this->coverageRate($history['old_data_count'], $history['row_count']) + $this->coverageRate($history['requested_data_count'], $history['row_count'])) / 2;
            $historyScore = min(60.0, $historyCoverage * 0.6);
            $this->addMetric($section, '변경 전후 이력', $this->countText($history['row_count']) . ' (마감 승인 자료)', $historyScore, '부분확보', '마감월 승인에는 전후 값이 있으나 일반 수정 전 금액은 별도 보존되지 않습니다.');
        } else {
            $this->addMetric($section, '변경 전후 이력', $history['installed'] ? '연결 이력 0건' : '공통 변경이력 구조 없음', 0, '부족', '일반 수정 전 금액을 확인할 수 없습니다.');
        }
        if ($this->tableExists($fileTable)) {
            $fileWhere = $this->columnExists($fileTable, 'is_deleted') ? ' WHERE is_deleted = 0' : '';
            $fileAgg = $this->safeAggregate('SELECT COUNT(*) AS file_count FROM `' . $fileTable . '`' . $fileWhere, array(), '외주비 첨부자료 집계 실패');
            if ($fileAgg['ok']) $this->addMetric($section, '첨부 증빙', $this->countText(isset($fileAgg['row']['file_count']) ? $fileAgg['row']['file_count'] : 0), null, '확인', '첨부파일 내용이나 업체별 상세는 표시하지 않습니다.');
        }
        $this->addMetric($section, '학습 가능 기간', $this->countText($section['month_count'], '개월'), null, $section['learning_judgement'], '실제 데이터가 존재하는 서로 다른 연월 수입니다.');

        if ($updatedByColumn === '') {
            $this->addWarning($section, '최초 등록자는 있으나 최종 수정자 컬럼이 없습니다.');
            $this->addRecommendation($section, '일반 수정 시 최종 수정 담당자를 별도로 기록하세요.');
        }
        $this->addWarning($section, '일반 수정 전 금액을 보존하는 변경 전후 이력이 충분하지 않습니다.');
        $this->addRecommendation($section, '일반 수정과 삭제에도 변경 전 금액과 처리자를 남길 수 있는 이력이 필요합니다.');
        if ($section['month_count'] < 6) $this->addRecommendation($section, '월말 예측 전 최소 6개월 이상의 외주비 발생일·금액 자료를 확보하세요.');

        return $this->finalizeSection($section, array(
            array('applicable' => true, 'score' => $structureScore),
            array('applicable' => true, 'score' => $dateRate),
            array('applicable' => true, 'score' => $createdRate),
            array('applicable' => true, 'score' => $creatorRate),
            array('applicable' => true, 'score' => $updatedRate),
            array('applicable' => true, 'score' => $updatedByRate),
            array('applicable' => true, 'score' => $historyScore),
            array('applicable' => true, 'score' => $this->monthScore($section['month_count'])),
        ));
    }

    public function auditCostChangeHistory()
    {
        $section = $this->emptySection('cost_change', '비용 변경이력');
        $section['history_setup_required'] = false;
        if (!$this->pdo) {
            $section['unavailable_reason'] = 'CPMS DB 연결 확인 불가';
            $this->addWarning($section, '테이블 확인 실패');
            return $this->finalizeSection($section, array());
        }
        $eventTable = 'cpms_cost_data_events';
        $eventInstalled = $this->tableExists($eventTable);
        if (!$eventInstalled) {
            $section['history_setup_required'] = true;
            $this->addMetric($section, '통합 비용 입력·변경이력', '미설치', null, '보완 필요', '일반 당월 입력·수정·삭제 이력을 저장하는 통합 테이블입니다.');
            $this->addWarning($section, '통합 비용 입력·변경이력이 아직 설치되지 않았습니다.');
            $this->addRecommendation($section, 'AI 데이터 이력 설정에서 통합 이력 테이블을 설치해주세요.');
        } else {
            $eventRequiredColumns = array('event_at', 'source_type', 'actor_employee_id', 'actor_name', 'old_amount', 'new_amount', 'cost_type');
            $eventColumnsReady = true;
            foreach ($eventRequiredColumns as $eventColumn) {
                if (!$this->columnExists($eventTable, $eventColumn)) $eventColumnsReady = false;
            }
            if (!$eventColumnsReady) {
                $section['history_setup_required'] = true;
                $this->addMetric($section, '통합 비용 입력·변경이력', '구조 보완 필요', null, '보완 필요', '설치 화면에서 필수 컬럼과 인덱스를 다시 확인해주세요.');
                $this->addWarning($section, '통합 비용 입력·변경이력의 필수 구조가 일부 부족합니다.');
                $this->addRecommendation($section, 'AI 데이터 이력 설정에서 통합 이력 테이블 구조를 확인해주세요.');
            } else {
                $eventSql = "SELECT COUNT(*) AS event_count,"
                    . " COUNT(DISTINCT DATE_FORMAT(event_at, '%Y-%m')) AS event_month_count,"
                    . " COALESCE(SUM(CASE WHEN source_type IS NOT NULL AND TRIM(source_type) <> '' THEN 1 ELSE 0 END),0) AS source_count,"
                    . " COALESCE(SUM(CASE WHEN actor_employee_id IS NOT NULL OR (actor_name IS NOT NULL AND TRIM(actor_name) <> '') THEN 1 ELSE 0 END),0) AS actor_count,"
                    . " COALESCE(SUM(CASE WHEN old_amount IS NOT NULL OR new_amount IS NOT NULL THEN 1 ELSE 0 END),0) AS amount_trace_count,"
                    . " COUNT(DISTINCT cost_type) AS cost_type_count, MAX(event_at) AS last_event_at"
                    . " FROM `" . $eventTable . "`";
                $eventAgg = $this->safeAggregate($eventSql, array(), '통합 비용 이력 집계 실패');
                if ($eventAgg['ok']) {
                    $eventRow = $eventAgg['row'];
                    $eventTotal = isset($eventRow['event_count']) ? (int)$eventRow['event_count'] : 0;
                    $eventMonthCount = isset($eventRow['event_month_count']) ? (int)$eventRow['event_month_count'] : 0;
                    $eventSourceRate = $this->coverageRate(isset($eventRow['source_count']) ? $eventRow['source_count'] : 0, $eventTotal);
                    $eventActorRate = $this->coverageRate(isset($eventRow['actor_count']) ? $eventRow['actor_count'] : 0, $eventTotal);
                    $eventAmountRate = $this->coverageRate(isset($eventRow['amount_trace_count']) ? $eventRow['amount_trace_count'] : 0, $eventTotal);
                    $this->addMetric($section, '통합 비용 입력·변경이력', '설치 완료', 100, '양호', '앞으로 발생하는 비용 이벤트를 통합 구조로 기록합니다.');
                    $this->addMetric($section, '통합 이력 전체 이벤트', $this->countText($eventTotal), null, $eventTotal > 0 ? '확인' : '데이터 없음', '과거자료를 임의 생성하지 않고 설치 이후 이벤트만 집계합니다.');
                    $this->addMetric($section, '통합 이력 보유월', $this->countText($eventMonthCount, '개월'), null, $this->learningJudgement($eventMonthCount), '실제 이벤트가 존재하는 서로 다른 연월 수입니다.');
                    $this->addMetric($section, '입력 출처', $this->coverageResult(isset($eventRow['source_count']) ? $eventRow['source_count'] : 0, $eventTotal), $eventSourceRate, $this->rateJudgement($eventSourceRate, $eventTotal), '직접입력·엑셀·승인·강제입력 등의 출처 보유율입니다.');
                    $this->addMetric($section, '통합 이력 처리자', $this->coverageResult(isset($eventRow['actor_count']) ? $eventRow['actor_count'] : 0, $eventTotal), $eventActorRate, $this->rateJudgement($eventActorRate, $eventTotal), '개인정보 목록 없이 처리자 식별정보 보유율만 집계합니다.');
                    $this->addMetric($section, '변경 전후 금액 추적', $this->coverageResult(isset($eventRow['amount_trace_count']) ? $eventRow['amount_trace_count'] : 0, $eventTotal), $eventAmountRate, $this->rateJudgement($eventAmountRate, $eventTotal), '공수처럼 금액이 없는 조정은 전후 공수 자료로 별도 보존됩니다.');
                    $this->addMetric($section, '최근 통합 이벤트', isset($eventRow['last_event_at']) && $eventRow['last_event_at'] !== null ? $eventRow['last_event_at'] : '-', null, $eventTotal > 0 ? '확인' : '데이터 없음', '가장 최근에 기록된 이벤트 시각입니다.');
                    $this->addMetric($section, '기록 중인 비용 종류', $this->countText(isset($eventRow['cost_type_count']) ? $eventRow['cost_type_count'] : 0, '종류'), null, $eventTotal > 0 ? '확인' : '데이터 없음', '통합 이력에 실제 기록된 비용 종류 수입니다.');
                    if ($eventTotal === 0) {
                        $section['highlights'][] = '통합 이력 구조는 설치됐으며 앞으로 발생하는 변경부터 기록됩니다.';
                    }
                } else {
                    $this->addWarning($section, '통합 비용 이력 집계 실패');
                }
            }
        }
        $tables = array(
            'cpms_cost_change_requests' => $this->tableExists('cpms_cost_change_requests'),
            'cpms_cost_change_logs' => $this->tableExists('cpms_cost_change_logs'),
            'cpms_cost_record_meta' => $this->tableExists('cpms_cost_record_meta'),
        );
        foreach ($tables as $table => $exists) if (!$exists) $this->addMissingTable($section, $table);
        $requiredMap = array(
            'cpms_cost_change_requests' => array('id', 'project_id', 'requester_employee_id', 'requester_name', 'use_date', 'old_settlement_ym', 'new_settlement_ym', 'old_data', 'requested_data', 'old_amount', 'new_amount', 'status', 'created_at', 'updated_at'),
            'cpms_cost_change_logs' => array('request_id', 'actor_employee_id', 'actor_name', 'event_data', 'created_at'),
            'cpms_cost_record_meta' => array('target_type', 'target_id', 'project_id', 'actual_use_date', 'settlement_ym', 'last_request_id', 'applied_data', 'created_at', 'updated_at'),
        );
        $columns = array();
        foreach ($requiredMap as $table => $required) {
            foreach ($required as $column) {
                $exists = $this->columnExists($table, $column);
                $columns[$table . '.' . $column] = $exists;
                if (!$exists) $this->addMissingColumn($section, $table, $column);
            }
        }
        if (!$tables['cpms_cost_change_requests']) {
            $section['unavailable_reason'] = '비용 변경 요청 테이블 없음';
            $this->addWarning($section, '비용 변경 승인 관련 테이블이 설치되지 않았습니다.');
            $this->addRecommendation($section, '향후 변경 승인 기능이 설치되면 전후 값과 처리 로그를 학습자료로 활용할 수 있습니다.');
            return $this->finalizeSection($section, array());
        }

        $table = 'cpms_cost_change_requests';
        $requesterCondition = $this->anyPresentCondition($table, array('requester_employee_id', 'requester_name', 'requester_email'));
        $select = array('COUNT(*) AS row_count');
        $select[] = $this->columnExists($table, 'project_id') ? 'COUNT(DISTINCT project_id) AS project_count' : '0 AS project_count';
        $select[] = $this->columnExists($table, 'status') ? "COALESCE(SUM(CASE WHEN status IN ('APPROVED','COMPLETED') THEN 1 ELSE 0 END), 0) AS approved_count" : '0 AS approved_count';
        $select[] = $this->columnExists($table, 'status') ? "COALESCE(SUM(CASE WHEN status = 'REJECTED' THEN 1 ELSE 0 END), 0) AS rejected_count" : '0 AS rejected_count';
        $select[] = $requesterCondition !== '' ? 'COALESCE(SUM(CASE WHEN ' . $requesterCondition . ' THEN 1 ELSE 0 END), 0) AS requester_count' : '0 AS requester_count';
        foreach (array('old_amount' => 'old_amount_count', 'new_amount' => 'new_amount_count') as $column => $alias) {
            $select[] = $this->columnExists($table, $column) ? 'COALESCE(SUM(CASE WHEN `' . $column . '` IS NOT NULL THEN 1 ELSE 0 END), 0) AS ' . $alias : '0 AS ' . $alias;
        }
        foreach (array('old_data' => 'old_data_count', 'requested_data' => 'requested_data_count') as $column => $alias) {
            $select[] = $this->columnExists($table, $column) ? "COALESCE(SUM(CASE WHEN `" . $column . "` IS NOT NULL AND TRIM(`" . $column . "`) <> '' AND `" . $column . "` <> '{}' THEN 1 ELSE 0 END), 0) AS " . $alias : '0 AS ' . $alias;
        }
        $select[] = $this->columnExists($table, 'use_date') ? 'COALESCE(SUM(CASE WHEN ' . $this->presentCondition('use_date', true) . ' THEN 1 ELSE 0 END), 0) AS use_date_count' : '0 AS use_date_count';
        $select[] = $this->columnExists($table, 'new_settlement_ym') ? "COALESCE(SUM(CASE WHEN new_settlement_ym REGEXP '^[0-9]{4}-[0-9]{2}$' THEN 1 ELSE 0 END), 0) AS settlement_count" : '0 AS settlement_count';
        $select[] = $this->columnExists($table, 'created_at') ? 'COALESCE(SUM(CASE WHEN ' . $this->presentCondition('created_at', true) . ' THEN 1 ELSE 0 END), 0) AS created_count' : '0 AS created_count';
        $select[] = $this->columnExists($table, 'updated_at') ? 'COALESCE(SUM(CASE WHEN ' . $this->presentCondition('updated_at', true) . ' THEN 1 ELSE 0 END), 0) AS updated_count' : '0 AS updated_count';
        if ($this->columnExists($table, 'created_at')) {
            $select[] = 'MIN(CASE WHEN ' . $this->presentCondition('created_at', true) . ' THEN created_at ELSE NULL END) AS first_date';
            $select[] = 'MAX(CASE WHEN ' . $this->presentCondition('created_at', true) . ' THEN created_at ELSE NULL END) AS last_date';
            $select[] = "COUNT(DISTINCT CASE WHEN " . $this->presentCondition('created_at', true) . " THEN DATE_FORMAT(created_at, '%Y-%m') ELSE NULL END) AS month_count";
        } else {
            $select[] = "'' AS first_date";
            $select[] = "'' AS last_date";
            $select[] = '0 AS month_count';
        }
        $agg = $this->safeAggregate('SELECT ' . implode(', ', $select) . ' FROM `' . $table . '`', array(), '비용 변경이력 데이터 집계 실패');
        if (!$agg['ok']) {
            $section['unavailable_reason'] = '비용 변경이력 데이터 집계 실패';
            $this->addWarning($section, '비용 변경이력 데이터 집계 실패');
            return $this->finalizeSection($section, array());
        }

        $row = $agg['row'];
        $total = isset($row['row_count']) ? (int)$row['row_count'] : 0;
        $section['available'] = true;
        $section['row_count'] = $total;
        $section['project_count'] = isset($row['project_count']) ? (int)$row['project_count'] : 0;
        $this->setSectionSpan($section, isset($row['first_date']) ? $row['first_date'] : '', isset($row['last_date']) ? $row['last_date'] : '', isset($row['month_count']) ? $row['month_count'] : 0);

        $structureScore = $this->structureScore($tables, $columns);
        $requesterRate = $this->coverageRate(isset($row['requester_count']) ? $row['requester_count'] : 0, $total);
        $oldAmountRate = $this->coverageRate(isset($row['old_amount_count']) ? $row['old_amount_count'] : 0, $total);
        $newAmountRate = $this->coverageRate(isset($row['new_amount_count']) ? $row['new_amount_count'] : 0, $total);
        $oldDataRate = $this->coverageRate(isset($row['old_data_count']) ? $row['old_data_count'] : 0, $total);
        $requestedDataRate = $this->coverageRate(isset($row['requested_data_count']) ? $row['requested_data_count'] : 0, $total);
        $useDateRate = $this->coverageRate(isset($row['use_date_count']) ? $row['use_date_count'] : 0, $total);
        $settlementRate = $this->coverageRate(isset($row['settlement_count']) ? $row['settlement_count'] : 0, $total);
        $createdRate = $this->coverageRate(isset($row['created_count']) ? $row['created_count'] : 0, $total);
        $updatedRate = $this->coverageRate(isset($row['updated_count']) ? $row['updated_count'] : 0, $total);

        $logLinked = 0;
        $logActorRate = 0.0;
        if ($tables['cpms_cost_change_logs'] && $this->columnExists('cpms_cost_change_logs', 'request_id')) {
            $logActorCondition = $this->anyPresentCondition('cpms_cost_change_logs', array('actor_employee_id', 'actor_name', 'actor_email'));
            $logSelect = 'COUNT(DISTINCT request_id) AS linked_count';
            $logSelect .= $logActorCondition !== '' ? ', COALESCE(SUM(CASE WHEN ' . $logActorCondition . ' THEN 1 ELSE 0 END), 0) AS actor_count, COUNT(*) AS log_count' : ', 0 AS actor_count, COUNT(*) AS log_count';
            $logAgg = $this->safeAggregate('SELECT ' . $logSelect . ' FROM `cpms_cost_change_logs`', array(), '비용 변경 로그 집계 실패');
            if ($logAgg['ok']) {
                $logLinked = isset($logAgg['row']['linked_count']) ? (int)$logAgg['row']['linked_count'] : 0;
                $logCount = isset($logAgg['row']['log_count']) ? (int)$logAgg['row']['log_count'] : 0;
                $logActorRate = $this->coverageRate(isset($logAgg['row']['actor_count']) ? $logAgg['row']['actor_count'] : 0, $logCount);
            }
        }
        $logLinkRate = $this->coverageRate($logLinked, $total);
        $historyScore = ($oldDataRate + $requestedDataRate + $oldAmountRate + $newAmountRate + $logLinkRate) / 5;

        $this->addMetric($section, '필수 테이블·컬럼 구조', count($section['missing_tables']) === 0 && count($section['missing_columns']) === 0 ? '전체 구조 확인' : '일부 보완 필요', $structureScore, $structureScore >= 90 ? '양호' : '보완 필요', '요청, 로그, 적용 메타 구조를 함께 확인합니다.');
        $this->addMetric($section, '전체 변경 요청', $this->countText($total), null, $total > 0 ? '확인' : '데이터 없음', '개인정보 없이 요청 건수만 집계합니다.');
        $this->addMetric($section, '승인 완료', $this->countText(isset($row['approved_count']) ? $row['approved_count'] : 0), null, '확인', 'APPROVED 또는 COMPLETED 상태입니다.');
        $this->addMetric($section, '반려', $this->countText(isset($row['rejected_count']) ? $row['rejected_count'] : 0), null, '확인', 'REJECTED 상태입니다.');
        $this->addMetric($section, '요청자', $this->coverageResult(isset($row['requester_count']) ? $row['requester_count'] : 0, $total), $requesterRate, $this->rateJudgement($requesterRate, $total), '요청자 개인정보는 표시하지 않고 보유율만 집계합니다.');
        $this->addMetric($section, '변경 전 금액', $this->coverageResult(isset($row['old_amount_count']) ? $row['old_amount_count'] : 0, $total), $oldAmountRate, $this->rateJudgement($oldAmountRate, $total), 'old_amount 컬럼의 값 존재 여부입니다.');
        $this->addMetric($section, '변경 후 금액', $this->coverageResult(isset($row['new_amount_count']) ? $row['new_amount_count'] : 0, $total), $newAmountRate, $this->rateJudgement($newAmountRate, $total), 'new_amount 컬럼의 값 존재 여부입니다.');
        $this->addMetric($section, '변경 전 자료', $this->coverageResult(isset($row['old_data_count']) ? $row['old_data_count'] : 0, $total), $oldDataRate, $this->rateJudgement($oldDataRate, $total), 'old_data 구조화 자료 보유율입니다.');
        $this->addMetric($section, '요청 자료', $this->coverageResult(isset($row['requested_data_count']) ? $row['requested_data_count'] : 0, $total), $requestedDataRate, $this->rateJudgement($requestedDataRate, $total), 'requested_data 구조화 자료 보유율입니다.');
        $this->addMetric($section, '실제 사용일', $this->coverageResult(isset($row['use_date_count']) ? $row['use_date_count'] : 0, $total), $useDateRate, $this->rateJudgement($useDateRate, $total), 'use_date 보유율입니다.');
        $this->addMetric($section, '귀속월', $this->coverageResult(isset($row['settlement_count']) ? $row['settlement_count'] : 0, $total), $settlementRate, $this->rateJudgement($settlementRate, $total), 'new_settlement_ym 보유율입니다.');
        $this->addMetric($section, '로그 연결', $this->coverageResult($logLinked, $total), $logLinkRate, $this->rateJudgement($logLinkRate, $total), '요청 ID에 연결된 처리 로그 보유율입니다.');
        $this->addMetric($section, '로그 처리자', $tables['cpms_cost_change_logs'] ? number_format($logActorRate, 1) . '%' : '로그 테이블 없음', $tables['cpms_cost_change_logs'] ? $logActorRate : null, $tables['cpms_cost_change_logs'] ? ($logActorRate >= 95 ? '양호' : '보완 필요') : '부족', '처리자 개인정보는 표시하지 않고 로그 내 보유율만 집계합니다.');
        $this->addMetric($section, '학습 가능 기간', $this->countText($section['month_count'], '개월'), null, $section['learning_judgement'], '변경 요청이 실제 존재하는 서로 다른 연월 수입니다.');

        if ($total > 0 && $historyScore >= 80) {
            $section['highlights'][] = '비용 변경 승인 이력은 변경 사유와 전후 값을 함께 보유해 AI 학습에 유용합니다.';
            $this->addRecommendation($section, '현재의 변경 전후 값과 승인 로그 기록 방식을 계속 유지하세요.');
        }
        if ($logLinkRate < 95 && $total > 0) {
            $this->addWarning($section, '일부 비용 변경 요청에 연결된 처리 로그가 부족합니다.');
            $this->addRecommendation($section, '모든 요청 단계에 요청 ID 기반 처리 로그가 남는지 확인하세요.');
        }
        if ($section['month_count'] < 6) $this->addRecommendation($section, '승인 변경이력을 최소 6개월 이상 축적하면 입력 지연·누락 패턴 분석에 도움이 됩니다.');

        return $this->finalizeSection($section, array(
            array('applicable' => true, 'score' => $structureScore),
            array('applicable' => true, 'score' => $useDateRate),
            array('applicable' => true, 'score' => $createdRate),
            array('applicable' => true, 'score' => $requesterRate),
            array('applicable' => true, 'score' => $updatedRate),
            array('applicable' => $tables['cpms_cost_change_logs'], 'score' => $logActorRate),
            array('applicable' => true, 'score' => $historyScore),
            array('applicable' => true, 'score' => $this->monthScore($section['month_count'])),
        ));
    }

    public function auditProjectAndSales()
    {
        $section = $this->emptySection('sales', '계약·매출');
        if (!$this->pdo) {
            $section['unavailable_reason'] = 'CPMS DB 연결 확인 불가';
            $this->addWarning($section, '테이블 확인 실패');
            return $this->finalizeSection($section, array());
        }
        $projectTable = 'cpms_projects';
        if (!$this->tableExists($projectTable)) {
            $this->addMissingTable($section, $projectTable);
            $section['unavailable_reason'] = '프로젝트 테이블 없음';
            $this->addWarning($section, '프로젝트·계약 자료를 확인할 수 없습니다.');
            $this->addRecommendation($section, '프로젝트 기본정보 구조를 먼저 확인하세요.');
            return $this->finalizeSection($section, array());
        }
        $required = array('id', 'contract_amount', 'start_date', 'end_date', 'status');
        $columns = array();
        foreach ($required as $column) {
            $exists = $this->columnExists($projectTable, $column);
            $columns[$projectTable . '.' . $column] = $exists;
            if (!$exists) $this->addMissingColumn($section, $projectTable, $column);
        }
        $select = array('COUNT(*) AS project_count');
        $select[] = $this->columnExists($projectTable, 'contract_amount') ? 'COALESCE(SUM(CASE WHEN contract_amount IS NOT NULL AND contract_amount > 0 THEN 1 ELSE 0 END), 0) AS contract_count' : '0 AS contract_count';
        $select[] = $this->columnExists($projectTable, 'contract_amount') ? 'COALESCE(SUM(CASE WHEN contract_amount IS NULL OR contract_amount <= 0 THEN 1 ELSE 0 END), 0) AS contract_missing_count' : 'COUNT(*) AS contract_missing_count';
        $select[] = $this->columnExists($projectTable, 'start_date') ? 'COALESCE(SUM(CASE WHEN ' . $this->presentCondition('start_date', true) . ' THEN 1 ELSE 0 END), 0) AS start_count' : '0 AS start_count';
        $select[] = $this->columnExists($projectTable, 'end_date') ? 'COALESCE(SUM(CASE WHEN ' . $this->presentCondition('end_date', true) . ' THEN 1 ELSE 0 END), 0) AS end_count' : '0 AS end_count';
        $select[] = $this->columnExists($projectTable, 'status') ? 'COALESCE(SUM(CASE WHEN status IS NOT NULL AND TRIM(status) <> \'\' THEN 1 ELSE 0 END), 0) AS status_count' : '0 AS status_count';
        $projectAgg = $this->safeAggregate('SELECT ' . implode(', ', $select) . ' FROM `' . $projectTable . '`', array(), '프로젝트·계약 데이터 집계 실패');
        if (!$projectAgg['ok']) {
            $section['unavailable_reason'] = '프로젝트·계약 데이터 집계 실패';
            $this->addWarning($section, '프로젝트·계약 데이터 집계 실패');
            return $this->finalizeSection($section, array());
        }

        $salesSources = array();
        $salesSourceNames = array();
        if (
            $this->tableExists('cpms_progress_billings')
            && $this->columnExists('cpms_progress_billings', 'project_id')
            && $this->columnExists('cpms_progress_billings', 'recognized_amount')
        ) {
            $hasProgressDate = $this->columnExists('cpms_progress_billings', 'progress_date');
            $hasCreatedAt = $this->columnExists('cpms_progress_billings', 'created_at');
            if ($hasProgressDate || $hasCreatedAt) {
                if ($hasProgressDate && $hasCreatedAt) $dateExpr = 'COALESCE(progress_date, DATE(created_at))';
                else if ($hasProgressDate) $dateExpr = 'progress_date';
                else $dateExpr = 'DATE(created_at)';
                $amountExpr = $this->columnExists('cpms_progress_billings', 'requested_amount')
                    ? 'COALESCE(NULLIF(recognized_amount, 0), requested_amount, 0)'
                    : 'COALESCE(recognized_amount, 0)';
                $salesSources[] = "SELECT project_id, CASE WHEN " . $dateExpr . " IS NOT NULL THEN DATE_FORMAT(" . $dateExpr . ", '%Y-%m') ELSE NULL END AS ym FROM `cpms_progress_billings` WHERE " . $amountExpr . ' > 0';
                $salesSourceNames[] = 'cpms_progress_billings';
            }
        }
        if (
            $this->tableExists('cpms_monthly_recognized')
            && $this->columnExists('cpms_monthly_recognized', 'project_id')
            && $this->columnExists('cpms_monthly_recognized', 'ym')
            && $this->columnExists('cpms_monthly_recognized', 'recognized_cum_amount')
        ) {
            $salesSources[] = "SELECT project_id, CASE WHEN ym REGEXP '^[0-9]{4}-[0-9]{2}$' THEN ym ELSE NULL END AS ym FROM `cpms_monthly_recognized` WHERE COALESCE(recognized_cum_amount, 0) > 0";
            $salesSourceNames[] = 'cpms_monthly_recognized';
        }
        foreach (array('cpms_schedule_task_item_progress', 'cpms_schedule_progress') as $progressTable) {
            if (
                $this->tableExists($progressTable)
                && $this->columnExists($progressTable, 'project_id')
                && $this->columnExists($progressTable, 'work_date')
                && $this->columnExists($progressTable, 'done_qty')
            ) {
                $salesSources[] = 'SELECT project_id, CASE WHEN ' . $this->presentCondition('work_date', true) . " THEN DATE_FORMAT(work_date, '%Y-%m') ELSE NULL END AS ym FROM `" . $progressTable . '` WHERE COALESCE(done_qty, 0) <> 0';
                $salesSourceNames[] = $progressTable;
            }
        }
        if (
            $this->tableExists('cpms_schedule_tasks')
            && $this->columnExists('cpms_schedule_tasks', 'project_id')
            && $this->columnExists('cpms_schedule_tasks', 'end_date')
            && $this->columnExists('cpms_schedule_tasks', 'progress')
            && $this->columnExists('cpms_schedule_tasks', 'work_id')
            && $this->tableExists('cpms_work_item_lines')
            && $this->columnExists('cpms_work_item_lines', 'work_id')
            && $this->columnExists('cpms_work_item_lines', 'unit_price_id')
            && $this->tableExists('cpms_project_unit_prices')
            && $this->columnExists('cpms_project_unit_prices', 'id')
            && $this->columnExists('cpms_project_unit_prices', 'project_id')
            && $this->columnExists('cpms_project_unit_prices', 'unit_price')
        ) {
            $salesSources[] = "SELECT st.project_id, DATE_FORMAT(st.end_date, '%Y-%m') AS ym
                                 FROM `cpms_schedule_tasks` st
                                 INNER JOIN `cpms_work_item_lines` wil ON wil.work_id = st.work_id
                                 INNER JOIN `cpms_project_unit_prices` pup ON pup.id = wil.unit_price_id AND pup.project_id = st.project_id
                                WHERE st.end_date IS NOT NULL
                                  AND st.end_date <> '0000-00-00'
                                  AND COALESCE(pup.unit_price, 0) > 0
                                  AND (COALESCE(st.progress, 0) >= 100 OR (st.end_date < CURDATE() AND COALESCE(st.progress, 0) = 0))";
            $salesSourceNames[] = 'cpms_schedule_tasks';
        }

        $salesRow = array('row_count' => 0, 'date_count' => 0, 'project_count' => 0, 'month_count' => 0, 'first_ym' => '', 'last_ym' => '');
        $salesAggregateOk = true;
        if (count($salesSources) > 0) {
            $salesSql = "SELECT COUNT(*) AS row_count,
                                COALESCE(SUM(CASE WHEN ym IS NOT NULL THEN 1 ELSE 0 END), 0) AS date_count,
                                COUNT(DISTINCT CASE WHEN ym IS NOT NULL THEN project_id ELSE NULL END) AS project_count,
                                COUNT(DISTINCT ym) AS month_count,
                                MIN(ym) AS first_ym,
                                MAX(ym) AS last_ym
                           FROM (" . implode(' UNION ALL ', $salesSources) . ') sales_source';
            $salesAgg = $this->safeAggregate($salesSql, array(), '매출·기성 데이터 집계 실패');
            if ($salesAgg['ok']) $salesRow = array_merge($salesRow, $salesAgg['row']);
            else $salesAggregateOk = false;
        }

        $projectRow = $projectAgg['row'];
        $projectCount = isset($projectRow['project_count']) ? (int)$projectRow['project_count'] : 0;
        $salesCount = isset($salesRow['row_count']) ? (int)$salesRow['row_count'] : 0;
        $section['available'] = true;
        $section['row_count'] = $salesCount;
        $section['project_count'] = $projectCount;
        $firstYm = isset($salesRow['first_ym']) ? trim((string)$salesRow['first_ym']) : '';
        $lastYm = isset($salesRow['last_ym']) ? trim((string)$salesRow['last_ym']) : '';
        $this->setSectionSpan($section, $firstYm, $lastYm, isset($salesRow['month_count']) ? $salesRow['month_count'] : 0);

        $sourceStructureScore = count($salesSources) > 0 ? 100.0 : 0.0;
        $structureScore = ($this->structureScore(array($projectTable => true), $columns) + $sourceStructureScore) / 2;
        $contractRate = $this->coverageRate(isset($projectRow['contract_count']) ? $projectRow['contract_count'] : 0, $projectCount);
        $startRate = $this->coverageRate(isset($projectRow['start_count']) ? $projectRow['start_count'] : 0, $projectCount);
        $endRate = $this->coverageRate(isset($projectRow['end_count']) ? $projectRow['end_count'] : 0, $projectCount);
        $statusRate = $this->coverageRate(isset($projectRow['status_count']) ? $projectRow['status_count'] : 0, $projectCount);
        $salesProjectRate = $this->coverageRate(isset($salesRow['project_count']) ? $salesRow['project_count'] : 0, $projectCount);
        $salesDateRate = $this->coverageRate(isset($salesRow['date_count']) ? $salesRow['date_count'] : 0, $salesCount);
        $this->addMetric($section, '필수 테이블·컬럼 구조', count($section['missing_columns']) === 0 && count($salesSources) > 0 ? '프로젝트와 매출 자료 구조 확인' : '일부 보완 필요', $structureScore, $structureScore >= 90 ? '양호' : '보완 필요', 'cpms_projects와 실제 매출 로더가 사용하는 기성·공정 자료를 확인합니다.');
        $this->addMetric($section, '전체 프로젝트', $this->countText($projectCount, '개'), null, $projectCount > 0 ? '확인' : '데이터 없음', '프로젝트명이나 담당자는 표시하지 않습니다.');
        $this->addMetric($section, '계약금액', $this->coverageResult(isset($projectRow['contract_count']) ? $projectRow['contract_count'] : 0, $projectCount), $contractRate, $this->rateJudgement($contractRate, $projectCount), '0보다 큰 계약금액 입력률입니다.');
        $this->addMetric($section, '계약금액 0·미입력', $this->countText(isset($projectRow['contract_missing_count']) ? $projectRow['contract_missing_count'] : 0, '개'), null, ((int)(isset($projectRow['contract_missing_count']) ? $projectRow['contract_missing_count'] : 0) === 0) ? '양호' : '보완 필요', '계약금액이 없거나 0인 프로젝트 수입니다.');
        $this->addMetric($section, '프로젝트 시작일', $this->coverageResult(isset($projectRow['start_count']) ? $projectRow['start_count'] : 0, $projectCount), $startRate, $this->rateJudgement($startRate, $projectCount), 'start_date 보유율입니다.');
        $this->addMetric($section, '프로젝트 종료일', $this->coverageResult(isset($projectRow['end_count']) ? $projectRow['end_count'] : 0, $projectCount), $endRate, $this->rateJudgement($endRate, $projectCount), 'end_date 보유율입니다.');
        $this->addMetric($section, '프로젝트 상태', $this->coverageResult(isset($projectRow['status_count']) ? $projectRow['status_count'] : 0, $projectCount), $statusRate, $this->rateJudgement($statusRate, $projectCount), 'status 보유율입니다.');
        $this->addMetric($section, '월별 매출·기성 자료', count($salesSourceNames) > 0 ? implode(', ', $salesSourceNames) : '확인 가능한 자료 없음', null, count($salesSources) > 0 && $salesAggregateOk ? '확인' : '부족', '공사 상황 탭의 확정매출 우선 및 공정 기반 예상매출 자료 출처입니다.');
        $this->addMetric($section, '매출 자료 보유 프로젝트', $this->countText(isset($salesRow['project_count']) ? $salesRow['project_count'] : 0, '개'), $salesProjectRate, $this->rateJudgement($salesProjectRate, $projectCount), '월 식별이 가능한 매출·기성·공정 자료가 있는 프로젝트 수입니다.');
        $this->addMetric($section, '매출 자료 기준월', $this->coverageResult(isset($salesRow['date_count']) ? $salesRow['date_count'] : 0, $salesCount), $salesDateRate, $this->rateJudgement($salesDateRate, $salesCount), '월별 집계에 사용할 날짜 또는 연월 보유율입니다.');
        $this->addMetric($section, '학습 가능 기간', $this->countText($section['month_count'], '개월'), null, $section['learning_judgement'], '실제 매출·기성·공정 자료가 존재하는 서로 다른 연월 수입니다.');

        if (count($salesSources) === 0) {
            $this->addWarning($section, '월별 매출 또는 기성 자료를 확인할 수 없습니다.');
            $this->addRecommendation($section, '확정 기성 또는 공정 기반 월별 매출 자료의 날짜와 금액을 지속적으로 기록하세요.');
        }
        if (!$salesAggregateOk) $this->addWarning($section, '매출·기성 데이터 집계 실패');
        if ($contractRate < 95 && $projectCount > 0) $this->addRecommendation($section, '계약금액이 0이거나 없는 프로젝트를 확인하세요.');
        if ($section['month_count'] < 6) $this->addRecommendation($section, '월말 예측 전 최소 6개월 이상의 월별 매출·기성 자료를 확보하세요.');

        return $this->finalizeSection($section, array(
            array('applicable' => true, 'score' => $structureScore),
            array('applicable' => true, 'score' => $contractRate),
            array('applicable' => true, 'score' => ($startRate + $endRate) / 2),
            array('applicable' => true, 'score' => $statusRate),
            array('applicable' => count($salesSources) > 0, 'score' => $salesDateRate),
            array('applicable' => count($salesSources) > 0, 'score' => $salesProjectRate),
            array('applicable' => count($salesSources) > 0, 'score' => $this->monthScore($section['month_count'])),
        ));
    }

    public function auditLabor()
    {
        $section = $this->emptySection('labor', '노무비');
        $internalTables = array(
            'cpms_labor_gongsu_overrides',
            'cpms_labor_force_adjustments',
            'cpms_project_labor_workers',
            'cpms_project_labor_worker_months',
        );
        $tableStates = array();
        foreach ($internalTables as $table) {
            $tableStates[$table] = $this->pdo ? $this->tableExists($table) : false;
            if (!$tableStates[$table]) $this->addMissingTable($section, $table);
        }

        $attendancePdo = $this->attendancePdo();
        $attendanceConnected = $attendancePdo instanceof PDO;
        $attendanceDataReady = false;
        $attendanceRow = array('row_count' => 0, 'done_count' => 0, 'project_count' => 0, 'date_count' => 0, 'first_date' => '', 'last_date' => '', 'month_count' => 0);
        if ($attendanceConnected) {
            $attendanceExists = $this->tableExists('attendance', $attendancePdo);
            $sitesExists = $this->tableExists('sites', $attendancePdo);
            if (!$attendanceExists) $this->addMissingTable($section, 'attendance.attendance');
            if (!$sitesExists) $this->addMissingTable($section, 'attendance.sites');
            if (!$attendanceExists || !$sitesExists) {
                $this->addWarning($section, '출퇴근 연동 DB의 일부 테이블을 확인할 수 없습니다.');
            }
            if ($attendanceExists) {
                foreach (array('site_id', 'status', 'start_time_phone') as $column) {
                    if (!$this->columnExists('attendance', $column, $attendancePdo)) $this->addMissingColumn($section, 'attendance.attendance', $column);
                }
                $dateColumn = $this->firstExistingColumn('attendance', array('start_time_phone', 'work_date', 'attendance_date', 'date'), $attendancePdo);
                $select = array('COUNT(*) AS row_count');
                $select[] = $this->columnExists('attendance', 'status', $attendancePdo) ? "COALESCE(SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END), 0) AS done_count" : '0 AS done_count';
                $select[] = $this->columnExists('attendance', 'site_id', $attendancePdo) ? 'COUNT(DISTINCT site_id) AS project_count' : '0 AS project_count';
                $select[] = $dateColumn !== '' ? 'COALESCE(SUM(CASE WHEN ' . $this->presentCondition($dateColumn, true) . ' THEN 1 ELSE 0 END), 0) AS date_count' : '0 AS date_count';
                if ($dateColumn !== '') {
                    $dateExpr = $dateColumn === 'date' || $dateColumn === 'work_date' || $dateColumn === 'attendance_date' ? '`' . $dateColumn . '`' : 'DATE(`' . $dateColumn . '`)';
                    $select[] = 'MIN(CASE WHEN ' . $this->presentCondition($dateColumn, true) . ' THEN ' . $dateExpr . ' ELSE NULL END) AS first_date';
                    $select[] = 'MAX(CASE WHEN ' . $this->presentCondition($dateColumn, true) . ' THEN ' . $dateExpr . ' ELSE NULL END) AS last_date';
                    $select[] = "COUNT(DISTINCT CASE WHEN " . $this->presentCondition($dateColumn, true) . " THEN DATE_FORMAT(" . $dateExpr . ", '%Y-%m') ELSE NULL END) AS month_count";
                } else {
                    $select[] = "'' AS first_date";
                    $select[] = "'' AS last_date";
                    $select[] = '0 AS month_count';
                }
                $attendanceAgg = $this->safeAggregate('SELECT ' . implode(', ', $select) . ' FROM `attendance`', array(), '외부 출퇴근 DB 데이터 집계 실패', $attendancePdo);
                if ($attendanceAgg['ok']) {
                    $attendanceRow = array_merge($attendanceRow, $attendanceAgg['row']);
                    $attendanceDataReady = true;
                }
                else $this->addWarning($section, '외부 출퇴근 DB 데이터 집계 실패');
            }
        } else {
            $this->addWarning($section, '출퇴근 연동 DB 확인 불가');
            $this->addRecommendation($section, 'attendance 연결 상태와 읽기 권한을 확인하되 CPMS 운영 데이터는 변경하지 마세요.');
        }

        $overrideRow = array('row_count' => 0, 'project_count' => 0, 'date_count' => 0, 'created_count' => 0, 'updated_count' => 0, 'requester_count' => 0, 'old_count' => 0, 'applied_count' => 0, 'approved_count' => 0, 'pending_count' => 0, 'rejected_count' => 0, 'first_date' => '', 'last_date' => '', 'month_count' => 0);
        if ($tableStates['cpms_labor_gongsu_overrides']) {
            $table = 'cpms_labor_gongsu_overrides';
            $requesterCondition = $this->anyPresentCondition($table, array('requested_by', 'requested_by_name', 'requested_by_email'));
            $select = array('COUNT(*) AS row_count');
            $select[] = $this->columnExists($table, 'project_id') ? 'COUNT(DISTINCT project_id) AS project_count' : '0 AS project_count';
            $select[] = $this->columnExists($table, 'work_date') ? 'COALESCE(SUM(CASE WHEN ' . $this->presentCondition('work_date', true) . ' THEN 1 ELSE 0 END), 0) AS date_count' : '0 AS date_count';
            $select[] = $this->columnExists($table, 'created_at') ? 'COALESCE(SUM(CASE WHEN ' . $this->presentCondition('created_at', true) . ' THEN 1 ELSE 0 END), 0) AS created_count' : '0 AS created_count';
            $select[] = $this->columnExists($table, 'updated_at') ? 'COALESCE(SUM(CASE WHEN ' . $this->presentCondition('updated_at', true) . ' THEN 1 ELSE 0 END), 0) AS updated_count' : '0 AS updated_count';
            $select[] = $requesterCondition !== '' ? 'COALESCE(SUM(CASE WHEN ' . $requesterCondition . ' THEN 1 ELSE 0 END), 0) AS requester_count' : '0 AS requester_count';
            $select[] = $this->columnExists($table, 'old_value') ? 'COALESCE(SUM(CASE WHEN old_value IS NOT NULL THEN 1 ELSE 0 END), 0) AS old_count' : '0 AS old_count';
            if ($this->columnExists($table, 'status')) {
                $select[] = "COALESCE(SUM(CASE WHEN status = 'applied' THEN 1 ELSE 0 END), 0) AS applied_count";
                $select[] = "COALESCE(SUM(CASE WHEN status IN ('approved','applied') THEN 1 ELSE 0 END), 0) AS approved_count";
                $select[] = "COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_count";
                $select[] = "COALESCE(SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END), 0) AS rejected_count";
            } else {
                $select[] = '0 AS applied_count'; $select[] = '0 AS approved_count'; $select[] = '0 AS pending_count'; $select[] = '0 AS rejected_count';
            }
            if ($this->columnExists($table, 'work_date')) {
                $select[] = 'MIN(CASE WHEN ' . $this->presentCondition('work_date', true) . ' THEN work_date ELSE NULL END) AS first_date';
                $select[] = 'MAX(CASE WHEN ' . $this->presentCondition('work_date', true) . ' THEN work_date ELSE NULL END) AS last_date';
                $select[] = "COUNT(DISTINCT CASE WHEN " . $this->presentCondition('work_date', true) . " THEN DATE_FORMAT(work_date, '%Y-%m') ELSE NULL END) AS month_count";
            } else if ($this->columnExists($table, 'month')) {
                $select[] = 'MIN(month) AS first_date'; $select[] = 'MAX(month) AS last_date'; $select[] = 'COUNT(DISTINCT month) AS month_count';
            } else {
                $select[] = "'' AS first_date"; $select[] = "'' AS last_date"; $select[] = '0 AS month_count';
            }
            $overrideAgg = $this->safeAggregate('SELECT ' . implode(', ', $select) . ' FROM `' . $table . '`', array(), '노무비 공수 조정 데이터 집계 실패');
            if ($overrideAgg['ok']) $overrideRow = array_merge($overrideRow, $overrideAgg['row']);
            else $this->addWarning($section, '노무비 공수 조정 데이터 집계 실패');
        }

        $forceRow = array('row_count' => 0, 'project_count' => 0, 'month_count' => 0, 'created_count' => 0, 'updated_count' => 0, 'actor_count' => 0, 'first_date' => '', 'last_date' => '');
        if ($tableStates['cpms_labor_force_adjustments']) {
            $table = 'cpms_labor_force_adjustments';
            $actorCondition = $this->anyPresentCondition($table, array('updated_by', 'updated_by_name', 'updated_by_email'));
            $select = array('COUNT(*) AS row_count');
            $select[] = $this->columnExists($table, 'project_id') ? 'COUNT(DISTINCT project_id) AS project_count' : '0 AS project_count';
            $select[] = $this->columnExists($table, 'month') ? "COUNT(DISTINCT CASE WHEN month REGEXP '^[0-9]{4}-[0-9]{2}$' THEN month ELSE NULL END) AS month_count" : '0 AS month_count';
            $select[] = $this->columnExists($table, 'created_at') ? 'COALESCE(SUM(CASE WHEN ' . $this->presentCondition('created_at', true) . ' THEN 1 ELSE 0 END), 0) AS created_count' : '0 AS created_count';
            $select[] = $this->columnExists($table, 'updated_at') ? 'COALESCE(SUM(CASE WHEN ' . $this->presentCondition('updated_at', true) . ' THEN 1 ELSE 0 END), 0) AS updated_count' : '0 AS updated_count';
            $select[] = $actorCondition !== '' ? 'COALESCE(SUM(CASE WHEN ' . $actorCondition . ' THEN 1 ELSE 0 END), 0) AS actor_count' : '0 AS actor_count';
            $select[] = $this->columnExists($table, 'month') ? 'MIN(month) AS first_date, MAX(month) AS last_date' : "'' AS first_date, '' AS last_date";
            $forceAgg = $this->safeAggregate('SELECT ' . implode(', ', $select) . ' FROM `' . $table . '`', array(), '노무비 강제조정 데이터 집계 실패');
            if ($forceAgg['ok']) $forceRow = array_merge($forceRow, $forceAgg['row']);
            else $this->addWarning($section, '노무비 강제조정 데이터 집계 실패');
        }

        $workerRow = array('row_count' => 0, 'project_count' => 0, 'rate_count' => 0, 'auto_count' => 0, 'manual_count' => 0);
        $sourceColumnsAvailable = false;
        if ($tableStates['cpms_project_labor_workers']) {
            $table = 'cpms_project_labor_workers';
            $sourceColumn = $this->firstExistingColumn($table, array('source_type', 'source'));
            $sourceColumnsAvailable = $sourceColumn !== '';
            $activeCondition = $this->columnExists($table, 'is_deleted') ? 'is_deleted = 0' : '1=1';
            $select = array('COALESCE(SUM(CASE WHEN ' . $activeCondition . ' THEN 1 ELSE 0 END), 0) AS row_count');
            $select[] = $this->columnExists($table, 'project_id') ? 'COUNT(DISTINCT CASE WHEN ' . $activeCondition . ' THEN project_id ELSE NULL END) AS project_count' : '0 AS project_count';
            $rateConditions = array();
            foreach (array('daily_wage_snapshot', 'deposit_rate') as $rateColumn) if ($this->columnExists($table, $rateColumn)) $rateConditions[] = '`' . $rateColumn . '` > 0';
            $select[] = count($rateConditions) > 0 ? 'COALESCE(SUM(CASE WHEN ' . $activeCondition . ' AND (' . implode(' OR ', $rateConditions) . ') THEN 1 ELSE 0 END), 0) AS rate_count' : '0 AS rate_count';
            if ($sourceColumn !== '') {
                $select[] = "COALESCE(SUM(CASE WHEN " . $activeCondition . " AND LOWER(TRIM(`" . $sourceColumn . "`)) IN ('attendance','shiftee','auto') THEN 1 ELSE 0 END), 0) AS auto_count";
                $select[] = "COALESCE(SUM(CASE WHEN " . $activeCondition . " AND LOWER(TRIM(`" . $sourceColumn . "`)) IN ('manual','direct') THEN 1 ELSE 0 END), 0) AS manual_count";
            } else {
                $select[] = '0 AS auto_count'; $select[] = '0 AS manual_count';
            }
            $workerAgg = $this->safeAggregate('SELECT ' . implode(', ', $select) . ' FROM `' . $table . '`', array(), '노무비 인원별 단가 데이터 집계 실패');
            if ($workerAgg['ok']) $workerRow = array_merge($workerRow, $workerAgg['row']);
            else $this->addWarning($section, '노무비 인원별 단가 데이터 집계 실패');
        }

        $ratioRow = array('row_count' => 0, 'project_count' => 0, 'month_count' => 0, 'configured_count' => 0);
        if ($tableStates['cpms_project_labor_worker_months']) {
            $table = 'cpms_project_labor_worker_months';
            $activeCondition = $this->columnExists($table, 'is_deleted') ? 'is_deleted = 0' : '1=1';
            $select = array('COALESCE(SUM(CASE WHEN ' . $activeCondition . ' THEN 1 ELSE 0 END), 0) AS row_count');
            $select[] = $this->columnExists($table, 'project_id') ? 'COUNT(DISTINCT CASE WHEN ' . $activeCondition . ' THEN project_id ELSE NULL END) AS project_count' : '0 AS project_count';
            $select[] = $this->columnExists($table, 'month') ? "COUNT(DISTINCT CASE WHEN " . $activeCondition . " AND month REGEXP '^[0-9]{4}-[0-9]{2}$' THEN month ELSE NULL END) AS month_count" : '0 AS month_count';
            $select[] = $this->columnExists($table, 'outsourcing_ratio') ? 'COALESCE(SUM(CASE WHEN ' . $activeCondition . ' AND outsourcing_ratio > 0 THEN 1 ELSE 0 END), 0) AS configured_count' : '0 AS configured_count';
            $ratioAgg = $this->safeAggregate('SELECT ' . implode(', ', $select) . ' FROM `' . $table . '`', array(), '노무비 외주비 비율 데이터 집계 실패');
            if ($ratioAgg['ok']) $ratioRow = array_merge($ratioRow, $ratioAgg['row']);
            else $this->addWarning($section, '노무비 외주비 비율 데이터 집계 실패');
        }

        $attendanceTotal = isset($attendanceRow['row_count']) ? (int)$attendanceRow['row_count'] : 0;
        $overrideTotal = isset($overrideRow['row_count']) ? (int)$overrideRow['row_count'] : 0;
        $forceTotal = isset($forceRow['row_count']) ? (int)$forceRow['row_count'] : 0;
        $hasInternal = $tableStates['cpms_labor_gongsu_overrides'] || $tableStates['cpms_labor_force_adjustments'] || $tableStates['cpms_project_labor_workers'] || $tableStates['cpms_project_labor_worker_months'];
        if (!$hasInternal && !$attendanceDataReady) {
            $section['unavailable_reason'] = '노무비 데이터 출처 확인 불가';
            $this->addWarning($section, 'CPMS 내부 노무비 자료와 출퇴근 연동 자료를 모두 확인할 수 없습니다.');
            return $this->finalizeSection($section, array());
        }
        $section['available'] = true;
        $section['row_count'] = $attendanceTotal + $overrideTotal + $forceTotal;
        $section['project_count'] = max(
            isset($attendanceRow['project_count']) ? (int)$attendanceRow['project_count'] : 0,
            isset($overrideRow['project_count']) ? (int)$overrideRow['project_count'] : 0,
            isset($forceRow['project_count']) ? (int)$forceRow['project_count'] : 0,
            isset($workerRow['project_count']) ? (int)$workerRow['project_count'] : 0,
            isset($ratioRow['project_count']) ? (int)$ratioRow['project_count'] : 0
        );
        $firstDates = array();
        $lastDates = array();
        foreach (array($attendanceRow, $overrideRow, $forceRow) as $sourceRow) {
            if (isset($sourceRow['first_date']) && trim((string)$sourceRow['first_date']) !== '') $firstDates[] = substr((string)$sourceRow['first_date'], 0, 10);
            if (isset($sourceRow['last_date']) && trim((string)$sourceRow['last_date']) !== '') $lastDates[] = substr((string)$sourceRow['last_date'], 0, 10);
        }
        sort($firstDates); sort($lastDates);
        $monthCount = max(
            isset($attendanceRow['month_count']) ? (int)$attendanceRow['month_count'] : 0,
            isset($overrideRow['month_count']) ? (int)$overrideRow['month_count'] : 0,
            isset($forceRow['month_count']) ? (int)$forceRow['month_count'] : 0,
            isset($ratioRow['month_count']) ? (int)$ratioRow['month_count'] : 0
        );
        $this->setSectionSpan($section, count($firstDates) > 0 ? $firstDates[0] : '', count($lastDates) > 0 ? $lastDates[count($lastDates) - 1] : '', $monthCount);

        $structureChecks = array();
        foreach ($tableStates as $table => $exists) $structureChecks[$table] = $exists;
        if ($attendanceConnected) {
            $structureChecks['attendance.attendance'] = $this->tableExists('attendance', $attendancePdo);
            $structureChecks['attendance.sites'] = $this->tableExists('sites', $attendancePdo);
        }
        $structureScore = $this->structureScore($structureChecks, array());
        $attendanceDateRate = $this->coverageRate(isset($attendanceRow['date_count']) ? $attendanceRow['date_count'] : 0, $attendanceTotal);
        $overrideDateRate = $this->coverageRate(isset($overrideRow['date_count']) ? $overrideRow['date_count'] : 0, $overrideTotal);
        $dateDenominator = $attendanceTotal + $overrideTotal + $forceTotal;
        $dateNumerator = (isset($attendanceRow['date_count']) ? (int)$attendanceRow['date_count'] : 0) + (isset($overrideRow['date_count']) ? (int)$overrideRow['date_count'] : 0) + $forceTotal;
        $dateRate = $this->coverageRate($dateNumerator, $dateDenominator);
        $traceTotal = $overrideTotal + $forceTotal;
        $createdCount = (isset($overrideRow['created_count']) ? (int)$overrideRow['created_count'] : 0) + (isset($forceRow['created_count']) ? (int)$forceRow['created_count'] : 0);
        $updatedCount = (isset($overrideRow['updated_count']) ? (int)$overrideRow['updated_count'] : 0) + (isset($forceRow['updated_count']) ? (int)$forceRow['updated_count'] : 0);
        $actorCount = (isset($overrideRow['requester_count']) ? (int)$overrideRow['requester_count'] : 0) + (isset($forceRow['actor_count']) ? (int)$forceRow['actor_count'] : 0);
        $createdRate = $this->coverageRate($createdCount, $traceTotal);
        $updatedRate = $this->coverageRate($updatedCount, $traceTotal);
        $actorRate = $this->coverageRate($actorCount, $traceTotal);
        $historyRate = $this->coverageRate(isset($overrideRow['old_count']) ? $overrideRow['old_count'] : 0, $overrideTotal);
        $workerTotal = isset($workerRow['row_count']) ? (int)$workerRow['row_count'] : 0;
        $rateCoverage = $this->coverageRate(isset($workerRow['rate_count']) ? $workerRow['rate_count'] : 0, $workerTotal);

        $this->addMetric($section, '데이터 출처 구조', count($section['missing_tables']) === 0 ? '전체 출처 확인' : '일부 출처 보완 필요', $structureScore, $structureScore >= 90 ? '양호' : '보완 필요', '자동연동, 공수 조정, 강제입력, 단가, 외주비 비율 자료를 구분해 확인합니다.');
        if ($attendanceConnected && $attendanceDataReady) {
            $doneCount = isset($attendanceRow['done_count']) ? (int)$attendanceRow['done_count'] : 0;
            $this->addMetric($section, '자동연동 데이터', $this->countText($attendanceTotal) . ' / 완료 ' . $this->countText($doneCount), $attendanceDateRate, $this->rateJudgement($attendanceDateRate, $attendanceTotal), 'attendance DB의 출퇴근 자료이며 이름은 표시하지 않습니다.');
            $this->addMetric($section, '자동연동 현장', $this->countText(isset($attendanceRow['project_count']) ? $attendanceRow['project_count'] : 0, '개'), null, '확인', 'attendance.site_id 기준 현장 수입니다.');
        } else {
            $this->addMetric($section, '자동연동 데이터', '출퇴근 연동 DB 확인 불가', null, '확인 불가', 'CPMS 내부 노무비 자료 점검은 계속 수행했습니다.');
        }
        $this->addMetric($section, '직접입력·공수조정', $this->countText($overrideTotal) . ' / 즉시반영 ' . $this->countText(isset($overrideRow['applied_count']) ? $overrideRow['applied_count'] : 0), $overrideDateRate, $this->rateJudgement($overrideDateRate, $overrideTotal), '공수 수정 자료와 직접 반영 건수를 집계합니다.');
        $this->addMetric($section, '관리자 강제조정', $this->countText($forceTotal), null, $forceTotal > 0 ? '확인' : ($tableStates['cpms_labor_force_adjustments'] ? '데이터 없음' : '확인 불가'), '개발부서 전용 월별 강제입력 자료입니다.');
        $this->addMetric($section, '승인 반영 데이터', $this->countText(isset($overrideRow['approved_count']) ? $overrideRow['approved_count'] : 0) . ' / 대기 ' . $this->countText(isset($overrideRow['pending_count']) ? $overrideRow['pending_count'] : 0) . ' / 반려 ' . $this->countText(isset($overrideRow['rejected_count']) ? $overrideRow['rejected_count'] : 0), null, $tableStates['cpms_labor_gongsu_overrides'] ? '확인' : '확인 불가', '공수 승인 상태별 건수입니다.');
        $this->addMetric($section, '인원별 단가', $this->coverageResult(isset($workerRow['rate_count']) ? $workerRow['rate_count'] : 0, $workerTotal), $tableStates['cpms_project_labor_workers'] ? $rateCoverage : null, $tableStates['cpms_project_labor_workers'] ? $this->rateJudgement($rateCoverage, $workerTotal) : '확인 불가', '단가 보유율만 표시하며 개인별 금액은 표시하지 않습니다.');
        $this->addMetric($section, '입력 출처 구분', $sourceColumnsAvailable ? '자동 ' . $this->countText(isset($workerRow['auto_count']) ? $workerRow['auto_count'] : 0) . ' / 직접 ' . $this->countText(isset($workerRow['manual_count']) ? $workerRow['manual_count'] : 0) : '출처 컬럼 없음', null, $sourceColumnsAvailable ? '확인' : '보완 필요', 'source 또는 source_type 기준 집계이며 사람 이름은 표시하지 않습니다.');
        $this->addMetric($section, '외주비 비율배분', $this->countText(isset($ratioRow['configured_count']) ? $ratioRow['configured_count'] : 0) . ' / 전체 ' . $this->countText(isset($ratioRow['row_count']) ? $ratioRow['row_count'] : 0), null, $tableStates['cpms_project_labor_worker_months'] ? '확인' : '확인 불가', '월별 외주비 비율이 설정된 자료 수입니다.');
        $this->addMetric($section, '최초 입력시각', $this->coverageResult($createdCount, $traceTotal), $traceTotal > 0 ? $createdRate : null, $traceTotal > 0 ? $this->rateJudgement($createdRate, $traceTotal) : '데이터 없음', 'CPMS 내부 조정 자료의 created_at 보유율입니다.');
        $this->addMetric($section, '입력·수정 담당자', $this->coverageResult($actorCount, $traceTotal), $traceTotal > 0 ? $actorRate : null, $traceTotal > 0 ? $this->rateJudgement($actorRate, $traceTotal) : '데이터 없음', '담당자 개인정보는 표시하지 않고 보유율만 집계합니다.');
        $this->addMetric($section, '최종 수정시각', $this->coverageResult($updatedCount, $traceTotal), $traceTotal > 0 ? $updatedRate : null, $traceTotal > 0 ? $this->rateJudgement($updatedRate, $traceTotal) : '데이터 없음', 'CPMS 내부 조정 자료의 updated_at 보유율입니다.');
        $this->addMetric($section, '변경 전후 이력', $this->coverageResult(isset($overrideRow['old_count']) ? $overrideRow['old_count'] : 0, $overrideTotal), $overrideTotal > 0 ? $historyRate : null, $overrideTotal > 0 ? $this->rateJudgement($historyRate, $overrideTotal) : '데이터 없음', '공수 조정의 old_value와 new_value 추적 가능 여부입니다.');
        $this->addMetric($section, '학습 가능 기간', $this->countText($section['month_count'], '개월'), null, $section['learning_judgement'], '여러 출처 중 가장 긴 실제 보유기간을 보수적으로 표시합니다.');

        if (!$sourceColumnsAvailable) {
            $this->addWarning($section, '노무비 자동연동과 직접입력 출처 구분이 충분하지 않습니다.');
            $this->addRecommendation($section, '노무비 자료마다 자동연동, 직접입력, 관리자 강제조정, 승인 반영 출처가 유지되는지 확인하세요.');
        }
        if (!$tableStates['cpms_labor_gongsu_overrides']) $this->addWarning($section, '공수 수정·승인 자료를 확인할 수 없습니다.');
        if ($section['month_count'] < 6) $this->addRecommendation($section, '월말 예측 전 최소 6개월 이상의 출퇴근·공수·단가 자료를 확보하세요.');

        return $this->finalizeSection($section, array(
            array('applicable' => true, 'score' => $structureScore),
            array('applicable' => $dateDenominator > 0, 'score' => $dateRate),
            array('applicable' => $traceTotal > 0, 'score' => $createdRate),
            array('applicable' => $traceTotal > 0, 'score' => $actorRate),
            array('applicable' => $traceTotal > 0, 'score' => $updatedRate),
            array('applicable' => $traceTotal > 0, 'score' => $actorRate),
            array('applicable' => $overrideTotal > 0, 'score' => $historyRate),
            array('applicable' => true, 'score' => $this->monthScore($section['month_count'])),
        ));
    }
}
