<?php
/**
 * CEO Index 투입비 예측 - 입력완료 패턴 / 과거 금액 학습 서비스.
 *
 * 핵심 원칙
 * 1) 과거 월별 최종 투입금액은 기존 CPMS 원본 집계에서 바로 학습한다.
 * 2) 과거 이관/강제입력 자료는 "금액" 학습에만 사용한다.
 * 3) 실제 입력시점 패턴은 과거 일일 스냅샷의 날짜별 변화로 학습한다.
 * 4) 과거 인원/날짜별 공수를 직접 복원한 자료는 노무비의 작업발생 패턴으로 사용한다.
 *    단, 나중에 입력한 등록시각은 과거 입력지연 패턴으로 사용하지 않는다.
 * 5) 강제 총액 입력은 월 최종금액 학습에만 사용한다.
 * 6) 회사 전체의 절대 금액 중앙값을 개별 현장 예상금액으로 복사하지 않는다.
 * 7) 입력완료율 표본이 3개월 미만이거나 완료율이 너무 낮으면 직접 확대 예측을 막는다.
 * 8) 입력지연은 실제 직접입력 건만 사용일/근무일과 저장일을 영업일 기준으로 비교한다.
 *    입력이 없는 날짜는 지연건으로 만들지 않고, 주말/공휴일과 과거이관/강제입력/자동계산은 제외한다.
 *
 * PHP 5.6 / MySQL 5.6 compatible.
 */
namespace App\Services;

use App\Core\Db;
use PDO;
use Exception;

require_once __DIR__ . '/CostChangeService.php';
require_once __DIR__ . '/AiCostDataGovernanceService.php';
require_once __DIR__ . '/CompanyProfitSummaryService.php';

class AiInputCompletionPatternService
{
    const TABLE_NAME = 'cpms_ai_input_completion_patterns';
    const SNAPSHOT_TABLE = 'cpms_ai_daily_snapshots';
    const EVENT_TABLE = 'cpms_cost_data_events';
    const CALCULATION_VERSION = 'INPUT_PATTERN_V7';
    const DEFAULT_GRACE_DAYS = 0;
    const DEFAULT_MIN_COMPLETION_RATE = 20;
    const MIN_TIMING_MONTHS_FOR_DIRECT_PROJECTION = 3;
    const HISTORY_MONTH_LIMIT = 18;
    const WORK_PATTERN_MONTH_LIMIT = 6;

    private static $tableCache = array();

    public static function pdo($pdo = null)
    {
        return $pdo ? $pdo : Db::pdo();
    }

    public static function businessToday()
    {
        return CostChangeService::businessToday();
    }

    public static function validYm($value)
    {
        return CostChangeService::validYm($value);
    }

    private static function connectionKey($pdo)
    {
        return is_object($pdo) ? spl_object_hash($pdo) : 'none';
    }

    public static function tableExists($pdo, $table)
    {
        if (!$pdo || !preg_match('/^[A-Za-z0-9_]+$/', (string)$table)) return false;
        $key = self::connectionKey($pdo) . ':' . $table;
        if (array_key_exists($key, self::$tableCache)) return self::$tableCache[$key];
        try {
            $st = $pdo->prepare('SHOW TABLES LIKE :table_name');
            $ok = $st && $st->execute(array(':table_name' => $table)) && $st->fetchColumn() !== false;
            self::$tableCache[$key] = $ok;
            return $ok;
        } catch (Exception $e) {
            self::$tableCache[$key] = false;
            return false;
        }
    }

    public static function categories()
    {
        return array(
            'labor' => 'labor_amount',
            'outsourcing' => 'outsourcing_amount',
            'purchase' => 'purchase_amount',
            'material' => 'material_amount',
            'equipment' => 'equipment_amount',
            'other_expense' => 'other_expense_amount',
            'safety' => 'safety_amount',
            'other' => 'other_amount'
        );
    }

    public static function createTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS cpms_ai_input_completion_patterns (\n"
            . " id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,\n"
            . " analysis_date DATE NOT NULL,\n target_ym CHAR(7) NOT NULL,\n"
            . " scope_type VARCHAR(30) NOT NULL,\n project_id INT UNSIGNED NOT NULL DEFAULT 0,\n cost_type VARCHAR(40) NOT NULL,\n"
            . " progress_day INT UNSIGNED NOT NULL DEFAULT 0,\n progress_rate DECIMAL(8,3) NOT NULL DEFAULT 0,\n expected_completion_rate DECIMAL(8,3) NULL,\n"
            . " sample_month_count INT UNSIGNED NOT NULL DEFAULT 0,\n amount_pattern_month_count INT UNSIGNED NOT NULL DEFAULT 0,\n event_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . " average_input_lag_days DECIMAL(8,3) NULL,\n completion_volatility DECIMAL(8,3) NULL,\n late_bulk_rate DECIMAL(8,3) NULL,\n correction_rate DECIMAL(8,3) NULL,\n month_move_rate DECIMAL(8,3) NULL,\n"
            . " fallback_level VARCHAR(40) NOT NULL,\n data_status VARCHAR(30) NOT NULL,\n calculation_version VARCHAR(40) NOT NULL,\n source_fingerprint CHAR(64) NOT NULL,\n"
            . " detail_data MEDIUMTEXT NULL,\n first_created_at DATETIME NOT NULL,\n last_calculated_at DATETIME NOT NULL,\n calculation_count INT UNSIGNED NOT NULL DEFAULT 1,\n created_at DATETIME NOT NULL,\n updated_at DATETIME NOT NULL,\n"
            . " UNIQUE KEY uk_ai_input_pattern (analysis_date,target_ym,scope_type,project_id,cost_type,progress_day),\n"
            . " KEY idx_ai_input_pattern_lookup (target_ym,project_id,cost_type,scope_type),\n"
            . " KEY idx_ai_input_pattern_source (source_fingerprint)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    public static function requiredColumns()
    {
        return array(
            'id','analysis_date','target_ym','scope_type','project_id','cost_type','progress_day','progress_rate',
            'expected_completion_rate','sample_month_count','amount_pattern_month_count','event_count',
            'average_input_lag_days','completion_volatility','late_bulk_rate','correction_rate','month_move_rate',
            'fallback_level','data_status','calculation_version','source_fingerprint','detail_data','first_created_at',
            'last_calculated_at','calculation_count','created_at','updated_at'
        );
    }

    public static function installOrUpdate($pdo = null)
    {
        $pdo = self::pdo($pdo);
        if (!$pdo) return array('ok'=>false,'message'=>'DB 연결 상태를 확인할 수 없습니다.');
        try {
            if ($pdo->exec(self::createTableSql()) === false) {
                return array('ok'=>false,'message'=>'입력패턴 테이블을 설치하지 못했습니다.');
            }
            if (!AiCostDataGovernanceService::columnExists($pdo, self::TABLE_NAME, 'amount_pattern_month_count')) {
                if ($pdo->exec('ALTER TABLE `' . self::TABLE_NAME . '` ADD COLUMN `amount_pattern_month_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `sample_month_count`') === false) {
                    return array('ok'=>false,'message'=>'입력패턴의 금액표본 구조를 추가하지 못했습니다.');
                }
            }
            self::$tableCache = array();
            return array(
                'ok'=>self::isInstalled($pdo),
                'message'=>self::isInstalled($pdo) ? '입력패턴 테이블 설치를 확인했습니다.' : '입력패턴 테이블 구조를 확인해주세요.'
            );
        } catch (Exception $e) {
            error_log('[AI Input Pattern] install failed');
            return array('ok'=>false,'message'=>'입력패턴 테이블 설치를 확인하지 못했습니다.');
        }
    }

    public static function isInstalled($pdo = null)
    {
        $pdo = self::pdo($pdo);
        if (!$pdo || !self::tableExists($pdo, self::TABLE_NAME)) return false;
        try {
            $st = $pdo->query('SHOW COLUMNS FROM `' . self::TABLE_NAME . '`');
            if (!$st) return false;
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $found = array();
            foreach ($rows as $row) {
                if (isset($row['Field'])) $found[(string)$row['Field']] = true;
            }
            foreach (self::requiredColumns() as $column) {
                if (!isset($found[$column])) return false;
            }
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public static function schemaStatus($pdo = null)
    {
        $pdo = self::pdo($pdo);
        $status = array(
            'db_available'=>(bool)$pdo,
            'installed'=>false,
            'row_count'=>0,
            'latest_analysis_date'=>'',
            'min_completion_rate'=>self::DEFAULT_MIN_COMPLETION_RATE
        );
        if (!$pdo) return $status;
        $status['installed'] = self::isInstalled($pdo);
        $status['min_completion_rate'] = self::minCompletionRate($pdo);
        if (!$status['installed']) return $status;
        try {
            $st = $pdo->query('SELECT COUNT(*) AS row_count,MAX(analysis_date) AS latest_analysis_date FROM `' . self::TABLE_NAME . '`');
            $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
            if (is_array($row)) {
                $status['row_count'] = (int)$row['row_count'];
                $status['latest_analysis_date'] = (string)$row['latest_analysis_date'];
            }
        } catch (Exception $e) {
        }
        return $status;
    }

    private static function settingInt($pdo, $key, $default, $min, $max)
    {
        $value = CostChangeService::setting($pdo, $key);
        if ($value === '' || !is_numeric($value)) return $default;
        return max($min, min($max, (int)$value));
    }

    public static function graceDays($pdo = null)
    {
        /* CPMS에는 마감 후 유예기간을 두지 않는다. */
        return 0;
    }

    public static function minCompletionRate($pdo = null)
    {
        return self::settingInt(
            self::pdo($pdo),
            'min_completion_rate_for_direct_projection',
            self::DEFAULT_MIN_COMPLETION_RATE,
            5,
            80
        );
    }

    public static function saveSettings($pdo, $graceDays, $minCompletionRate)
    {
        $pdo = self::pdo($pdo);
        if (!$pdo) return false;
        $minCompletionRate = max(5, min(80, (int)$minCompletionRate));
        return CostChangeService::saveSetting($pdo, 'min_completion_rate_for_direct_projection', $minCompletionRate);
    }

    public static function progress($snapshotDate, $targetYm, $costType)
    {
        $period = CostChangeService::periodForYm($costType, $targetYm);
        if (empty($period['start']) || empty($period['end'])) {
            return array('day'=>0,'rate'=>0,'start'=>'','end'=>'');
        }
        $start = strtotime($period['start']);
        $end = strtotime($period['end']);
        $date = strtotime($snapshotDate);
        if ($start === false || $end === false || $date === false) {
            return array('day'=>0,'rate'=>0,'start'=>$period['start'],'end'=>$period['end']);
        }
        $total = max(1, (int)floor(($end - $start) / 86400) + 1);
        $elapsed = (int)floor(($date - $start) / 86400) + 1;
        $elapsed = max(0, min($total, $elapsed));
        return array(
            'day'=>$elapsed,
            'rate'=>round($elapsed / $total * 100, 3),
            'start'=>$period['start'],
            'end'=>$period['end']
        );
    }

    public static function median($values)
    {
        $clean = array();
        foreach ($values as $value) {
            if (is_numeric($value)) $clean[] = (float)$value;
        }
        if (count($clean) === 0) return null;
        sort($clean, SORT_NUMERIC);
        $count = count($clean);
        $mid = (int)floor($count / 2);
        return $count % 2 ? round($clean[$mid], 3) : round(($clean[$mid - 1] + $clean[$mid]) / 2, 3);
    }

    public static function volatility($values)
    {
        $clean = array();
        foreach ($values as $value) {
            if (is_numeric($value)) $clean[] = (float)$value;
        }
        if (count($clean) < 2) return null;
        $mean = array_sum($clean) / count($clean);
        $sum = 0.0;
        foreach ($clean as $value) $sum += pow($value - $mean, 2);
        return round(sqrt($sum / count($clean)), 3);
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

    private static function latestContext($pdo)
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
                'target_ym'=>(string)$row['target_ym']
            );
        } catch (Exception $e) {
            return $empty;
        }
    }

    public static function finalizedYm($ym, $today, $costType, $graceDays = 0)
    {
        $period = CostChangeService::periodForYm($costType, $ym);
        if (empty($period['end'])) return false;
        $end = strtotime($period['end']);
        $now = strtotime($today);
        return $end !== false && $now !== false && $now > $end;
    }

    /**
     * 현재 예측월 직전부터 최대 18개월의 과거월 목록.
     */
    private static function historicalMonths($targetYm)
    {
        $months = array();
        if (self::validYm($targetYm) === '') return $months;
        $startTs = strtotime($targetYm . '-01 -' . self::HISTORY_MONTH_LIMIT . ' months');
        $endTs = strtotime($targetYm . '-01 -1 month');
        if ($startTs === false || $endTs === false) return $months;
        $cursor = $startTs;
        while ($cursor <= $endTs) {
            $months[] = date('Y-m', $cursor);
            $cursor = strtotime('+1 month', $cursor);
        }
        return $months;
    }

    /**
     * 안전 메뉴에서 입력되는 비용은 AI에서는 모두 하나의 '안전관리비'로 본다.
     * 실제 CPMS 비용 구분에는 별도 '보건관리비' 항목이 없으므로 검진비와
     * 기타 안전·보건 비용도 안전관리비 안에 포함한다.
     *
     * DB의 기존 health_amount 컬럼은 하위호환을 위해 남겨두되 신규 AI 비용항목으로 사용하지 않는다.
     */
    private static function splitSafetyHealth($projectId, $startDate, $endDate)
    {
        $result = array('safety'=>0.0, 'health'=>0.0, 'other'=>0.0);
        if (!function_exists('cpms_safety_cost_project_items_between') || !function_exists('cpms_safety_cost_row_amount')) {
            return $result;
        }
        try {
            $rows = cpms_safety_cost_project_items_between((int)$projectId, $startDate, $endDate);
            foreach ((array)$rows as $row) {
                $amount = (float)cpms_safety_cost_row_amount($row);
                if ($amount > 0) $result['safety'] += $amount;
            }
        } catch (Exception $e) {
            return array('safety'=>0.0, 'health'=>0.0, 'other'=>0.0);
        }
        return $result;
    }

    /**
     * 기존 CPMS에 이미 입력되어 있는 과거 월별 최종 투입비를 금액 학습자료로 만든다.
     *
     * 중요: 여기서는 입력일/수정일을 학습하지 않는다.
     * 과거에 나중에 몰아서 입력한 자료도 "그 달의 최종 금액"으로만 사용한다.
     */
    private static function historicalAmountSamples($pdo, $targetYm, $today)
    {
        $samples = array();
        $months = self::historicalMonths($targetYm);
        if (!$pdo || count($months) === 0) return $samples;
        if (!function_exists('cpms_company_profit_load_projects') || !function_exists('cpms_company_profit_project_summary')) {
            return $samples;
        }

        $filters = array(
            'scope'=>'custom',
            'start_month'=>$months[0],
            'end_month'=>$months[count($months) - 1],
            'status'=>'',
            'q'=>'',
            'project_id'=>0
        );

        try {
            $projects = cpms_company_profit_load_projects($pdo, $filters);
        } catch (Exception $e) {
            return $samples;
        }

        foreach ((array)$projects as $project) {
            $projectId = isset($project['id']) ? (int)$project['id'] : 0;
            if ($projectId <= 0) continue;

            try {
                $summary = cpms_company_profit_project_summary($pdo, $project, $months);
            } catch (Exception $e) {
                continue;
            }
            if (!isset($summary['monthly']) || !is_array($summary['monthly'])) continue;

            foreach ($months as $ym) {
                if (!isset($summary['monthly'][$ym]) || !is_array($summary['monthly'][$ym])) continue;
                $monthly = $summary['monthly'][$ym];
                $costPeriod = CostChangeService::periodForYm('material', $ym);
                $safetySplit = self::splitSafetyHealth($projectId, $costPeriod['start'], $costPeriod['end']);

                $amounts = array(
                    'labor'=>isset($monthly['labor']) ? (float)$monthly['labor'] : 0.0,
                    'outsourcing'=>isset($monthly['outsourcing']) ? (float)$monthly['outsourcing'] : 0.0,
                    'purchase'=>isset($monthly['purchase_cost']) ? (float)$monthly['purchase_cost'] : 0.0,
                    'material'=>isset($monthly['material_cost']) ? (float)$monthly['material_cost'] : 0.0,
                    'equipment'=>isset($monthly['equipment']) ? (float)$monthly['equipment'] : 0.0,
                    'other_expense'=>isset($monthly['other_cost']) ? (float)$monthly['other_cost'] : 0.0,
                    'safety'=>(float)$safetySplit['safety'],
                    'other'=>(isset($monthly['deduction']) ? (float)$monthly['deduction'] : 0.0)
                );

                /* 일일 스냅샷과 동일하게 세부합계와 기존 손익합계의 차이는 기타 투입비로 맞춘다. */
                $monthlyInput = isset($monthly['input_cost']) ? (float)$monthly['input_cost'] : 0.0;
                $componentTotal = 0.0;
                foreach ($amounts as $amount) $componentTotal += (float)$amount;
                if (abs($monthlyInput - $componentTotal) > 0.01) {
                    $amounts['other'] += ($monthlyInput - $componentTotal);
                }

                foreach ($amounts as $costType=>$finalAmount) {
                    $finalAmount = max(0.0, (float)$finalAmount);
                    if ($finalAmount <= 0) continue;
                    if (!self::finalizedYm($ym, $today, $costType)) continue;

                    $samples[] = array(
                        'project_id'=>$projectId,
                        'project_name'=>isset($project['name']) ? (string)$project['name'] : '',
                        'cost_type'=>$costType,
                        'ym'=>$ym,
                        'completion_rate'=>null,
                        'final_amount'=>$finalAmount,
                        'timing_eligible'=>0,
                        'progress_rate'=>null,
                        'progress_day'=>null,
                        'amount_source'=>'CPMS_FINALIZED_ACTUAL',
                        'timing_source'=>'NONE'
                    );
                }
            }
        }

        return $samples;
    }

    /**
     * 입력시점 학습은 실제 당시의 상태가 남아 있는 일일 스냅샷만 사용한다.
     */
    private static function historyRows($pdo, $targetYm, $today, $graceDays)
    {
        if (!self::tableExists($pdo, self::SNAPSHOT_TABLE)) return array();
        $min = date('Y-m', strtotime($targetYm . '-01 -' . self::HISTORY_MONTH_LIMIT . ' months'));
        $columns = array_values(self::categories());
        /* 과거 스냅샷에 분리 저장되어 있던 health_amount는 안전관리비로 합치기 위해 같이 읽는다. */
        if (!in_array('health_amount', $columns, true)) $columns[] = 'health_amount';
        $sql = 'SELECT snapshot_date,target_ym,project_id,project_name_snapshot,' . implode(',', $columns)
            . ',monthly_input_amount FROM `' . self::SNAPSHOT_TABLE . '`'
            . ' WHERE target_ym>=:min_ym AND target_ym<:target_ym'
            . ' ORDER BY project_id,target_ym,snapshot_date';
        try {
            $st = $pdo->prepare($sql);
            if (!$st || !$st->execute(array(':min_ym'=>$min, ':target_ym'=>$targetYm))) return array();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : array();
        } catch (Exception $e) {
            return array();
        }
    }

    /**
     * 실제 등록된 건의 발생일(actual_date)과 저장일(event_at)을 비교하기 위한 공휴일 지도.
     *
     * - 입력이 전혀 없는 날짜를 지연건으로 만들지 않는다. 실제 이벤트가 있는 건만 측정한다.
     * - 토/일 및 공휴일/대체공휴일은 지연 영업일에서 제외한다.
     * - CPMS에 이미 있는 Google 공휴일 캐시를 우선 활용하고, 캐시가 없을 때를 대비해
     *   고정 공휴일과 2026년 공식 공휴일을 보완한다.
     */
    private static function inputLagHolidayMap($pdo, $startDate, $endDate)
    {
        $holidays = array();
        if (!preg_match('/^(\d{4})-\d{2}-\d{2}$/', (string)$startDate, $startParts)) return $holidays;
        if (!preg_match('/^(\d{4})-\d{2}-\d{2}$/', (string)$endDate, $endParts)) return $holidays;
        if ($startDate > $endDate) return $holidays;

        $fixed = array('01-01','03-01','05-01','05-05','06-06','08-15','10-03','10-09','12-25');
        $startYear = (int)$startParts[1];
        $endYear = (int)$endParts[1];
        for ($year=$startYear; $year<=$endYear; $year++) {
            foreach ($fixed as $monthDay) {
                $date = sprintf('%04d-%s', $year, $monthDay);
                if ($date >= $startDate && $date <= $endDate) $holidays[$date] = true;
            }
            if ($year >= 2026) {
                $date = sprintf('%04d-07-17', $year);
                if ($date >= $startDate && $date <= $endDate) $holidays[$date] = true;
            }
        }

        $official2026 = array(
            '2026-01-01','2026-02-16','2026-02-17','2026-02-18','2026-03-01','2026-03-02',
            '2026-05-01','2026-05-05','2026-05-24','2026-05-25','2026-06-03','2026-06-06',
            '2026-07-17','2026-08-15','2026-08-17','2026-09-24','2026-09-25','2026-09-26',
            '2026-10-03','2026-10-05','2026-10-09','2026-12-25'
        );
        foreach ($official2026 as $date) if ($date >= $startDate && $date <= $endDate) $holidays[$date] = true;

        if ($pdo && self::tableExists($pdo, 'cpms_holiday_cache')) {
            try {
                $st = $pdo->prepare('SELECT holiday_date FROM cpms_holiday_cache WHERE is_active=1 AND holiday_date BETWEEN :start_date AND :end_date');
                if ($st && $st->execute(array(':start_date'=>$startDate, ':end_date'=>$endDate))) {
                    foreach ((array)$st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $date = isset($row['holiday_date']) ? trim((string)$row['holiday_date']) : '';
                        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $holidays[$date] = true;
                    }
                }
            } catch (Exception $e) {
            }
        }
        return $holidays;
    }

    /**
     * 발생일 다음 날부터 실제 등록일까지의 영업일 수를 계산한다.
     * 같은 날 입력은 0일, 금요일 발생 후 월요일 입력은 주말을 빼고 1영업일이다.
     */
    private static function inputLagBusinessDays($actualDate, $eventDate, $holidays)
    {
        $actualDate = trim((string)$actualDate);
        $eventDate = trim((string)$eventDate);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $actualDate)) return null;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) return null;
        if ($eventDate <= $actualDate) return 0;

        $cursor = strtotime($actualDate . ' +1 day');
        $end = strtotime($eventDate);
        if ($cursor === false || $end === false) return null;
        $days = 0;
        $guard = 0;
        while ($cursor <= $end && $guard < 800) {
            $date = date('Y-m-d', $cursor);
            $weekday = (int)date('N', $cursor);
            if ($weekday <= 5 && !isset($holidays[$date])) $days++;
            $cursor = strtotime('+1 day', $cursor);
            $guard++;
        }
        return $days;
    }

    private static function eventStats($pdo, $targetYm, $analysisDate)
    {
        $result = array();
        if (!self::tableExists($pdo, self::EVENT_TABLE)) return $result;
        $start = date('Y-m-d', strtotime($targetYm . '-01 -' . self::HISTORY_MONTH_LIMIT . ' months'));
        $analysisDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$analysisDate) ? (string)$analysisDate : self::businessToday();
        /* 현재 분석일까지 실제로 등록된 지연 건은 즉시 반영한다. */
        $end = date('Y-m-d', strtotime($analysisDate . ' +1 day'));
        $holidayEnd = $analysisDate;
        $holidays = self::inputLagHolidayMap($pdo, $start, $holidayEnd);
        $originWhere = AiCostDataGovernanceService::columnExists($pdo, self::EVENT_TABLE, 'data_origin')
            ? " AND (data_origin='LIVE_EMPLOYEE_INPUT' OR (cost_type='labor' AND data_origin='SYSTEM_IMPORT' AND source_type IN ('ATTENDANCE','AUTO_CALC')))"
            : ' AND 1=0';

        /*
         * event_count는 실제 운영 흐름 파악을 위해 출퇴근/자동계산도 포함한다.
         * 그러나 '입력 지연'은 사람의 실제 직접입력(LIVE_EMPLOYEE_INPUT)만 측정한다.
         * 강제입력, 과거이관, 과거 복원입력, 승인/관리자 보정, 자동계산은 지연 측정에서 제외한다.
         */
        $sql = "SELECT project_id,cost_type,target_type,event_action,source_type,data_origin,actual_date,event_at,old_data,new_data "
            . "FROM `" . self::EVENT_TABLE . "` WHERE event_at>=:start_date AND event_at<:end_date AND event_action<>'DELETE'"
            . $originWhere . " ORDER BY project_id,cost_type,event_at,id";
        try {
            $st = $pdo->prepare($sql);
            if (!$st || !$st->execute(array(':start_date'=>$start, ':end_date'=>$end))) return $result;
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $groups = array();
            foreach ((array)$rows as $row) {
                $project = isset($row['project_id']) ? (int)$row['project_id'] : 0;
                $cost = isset($row['cost_type']) ? strtolower(trim((string)$row['cost_type'])) : '';
                $targetType = isset($row['target_type']) ? strtolower(trim((string)$row['target_type'])) : '';
                /* 과거 검진비(health) 및 안전비 화면에서 들어온 기타 안전·보건 비용은 안전관리비로 통합한다. */
                if ($cost === 'health') $cost = 'safety';
                if ($cost === 'other' && strpos($targetType, 'safety') !== false) $cost = 'safety';
                if ($project <= 0 || $cost === '') continue;
                $key = $project . ':' . $cost;
                if (!isset($groups[$key])) {
                    $groups[$key] = array(
                        'event_count'=>0,'human_event_count'=>0,'lag_total'=>0.0,'lag_count'=>0,'same_day_count'=>0,'within_one_count'=>0,'late_two_plus_count'=>0,
                        'correction_count'=>0,'bulk_count'=>0,'move_count'=>0
                    );
                }
                $groups[$key]['event_count']++;

                $origin = isset($row['data_origin']) ? strtoupper(trim((string)$row['data_origin'])) : '';
                $humanInput = $origin === 'LIVE_EMPLOYEE_INPUT';
                if ($humanInput) $groups[$key]['human_event_count']++;
                $actualDate = isset($row['actual_date']) ? trim((string)$row['actual_date']) : '';
                $eventDate = isset($row['event_at']) ? substr((string)$row['event_at'], 0, 10) : '';
                $lag = null;
                if ($humanInput && $actualDate !== '' && $eventDate !== '') {
                    $lag = self::inputLagBusinessDays($actualDate, $eventDate, $holidays);
                    if ($lag !== null) {
                        $groups[$key]['lag_total'] += (float)$lag;
                        $groups[$key]['lag_count']++;
                        if ((int)$lag === 0) $groups[$key]['same_day_count']++;
                        if ((int)$lag <= 1) $groups[$key]['within_one_count']++;
                        if ((int)$lag >= 2) $groups[$key]['late_two_plus_count']++;
                    }
                }

                if ($humanInput) {
                    $isInitialLaborGongsu = $cost === 'labor'
                        && isset($row['target_type']) && (string)$row['target_type'] === 'labor_gongsu_override'
                        && isset($row['source_type']) && strtoupper((string)$row['source_type']) === 'DIRECT';
                    if (!$isInitialLaborGongsu && isset($row['event_action']) && in_array(strtoupper((string)$row['event_action']), array('UPDATE','ADJUST'), true)) {
                        $groups[$key]['correction_count']++;
                    }
                    if ($lag !== null && $lag >= 7 && isset($row['source_type']) && in_array(strtoupper((string)$row['source_type']), array('EXCEL','APPROVAL'), true)) {
                        $groups[$key]['bulk_count']++;
                    }
                    if (isset($row['event_action']) && strtoupper((string)$row['event_action']) === 'UPDATE'
                        && isset($row['old_data'],$row['new_data'])
                        && strpos((string)$row['old_data'], 'settlement_ym') !== false
                        && strpos((string)$row['new_data'], 'settlement_ym') !== false) {
                        $groups[$key]['move_count']++;
                    }
                }
            }

            foreach ($groups as $key=>$group) {
                $count = max(0, (int)$group['event_count']);
                $lagCount = max(0, (int)$group['lag_count']);
                $humanCount = max(0, (int)$group['human_event_count']);
                $result[$key] = array(
                    'event_count'=>$count,
                    'human_event_count'=>$humanCount,
                    'lag_sample_count'=>$lagCount,
                    'average_lag'=>$lagCount ? round((float)$group['lag_total'] / $lagCount, 3) : null,
                    'same_day_rate'=>$lagCount ? round((int)$group['same_day_count'] / $lagCount * 100, 3) : null,
                    'within_one_business_day_rate'=>$lagCount ? round((int)$group['within_one_count'] / $lagCount * 100, 3) : null,
                    'late_two_plus_rate'=>$lagCount ? round((int)$group['late_two_plus_count'] / $lagCount * 100, 3) : null,
                    'correction_rate'=>$humanCount ? round((int)$group['correction_count'] / $humanCount * 100, 3) : null,
                    'bulk_rate'=>$humanCount ? round((int)$group['bulk_count'] / $humanCount * 100, 3) : null,
                    'move_rate'=>$humanCount ? round((int)$group['move_count'] / $humanCount * 100, 3) : null
                );
            }
        } catch (Exception $e) {
        }
        return $result;
    }

    /**
     * 과거 일일 스냅샷 자체가 실제 그날의 상태를 보존하고 있는지 확인한다.
     *
     * 예전 구현은 같은 달의 모든 원천행이 LIVE_EMPLOYEE_INPUT 이어야만 월 전체를 인정했다.
     * 노무비처럼 출퇴근/공수 자동계산이 섞이거나 정상 수정 1건이 있는 경우에도 월 전체가 탈락해
     * 오래 운영한 현장이 "마감자료 0개월"로 보이는 문제가 있었다.
     *
     * 과거 스냅샷은 임의 역산 생성하지 않으므로, 서로 다른 날짜의 관측값이 실제로 존재하고
     * 현재 예측 진행률과 가까운 관측점이 있으면 입력시점 표본으로 인정한다.
     */
    private static function snapshotTimingEvidence($monthRows, $column, $ym, $cost, $currentProgress)
    {
        $result = array('eligible'=>false,'best'=>null,'best_distance'=>9999,'distinct_days'=>0,'span_days'=>0);
        $period = CostChangeService::periodForYm($cost, $ym);
        if (empty($period['end'])) return $result;

        $dates = array();
        $firstTs = null;
        $lastTs = null;
        $best = null;
        $bestDistance = 9999;
        foreach ((array)$monthRows as $row) {
            $date = isset($row['snapshot_date']) ? (string)$row['snapshot_date'] : '';
            if ($date === '' || $date > (string)$period['end']) continue;
            $dates[$date] = true;
            $ts = strtotime($date);
            if ($ts !== false) {
                if ($firstTs === null || $ts < $firstTs) $firstTs = $ts;
                if ($lastTs === null || $ts > $lastTs) $lastTs = $ts;
            }
            $pointProgress = self::progress($date, $ym, $cost);
            $distance = abs((float)$pointProgress['rate'] - (float)$currentProgress['rate']);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $row;
            }
        }

        $distinctDays = count($dates);
        $spanDays = ($firstTs !== null && $lastTs !== null) ? (int)floor(($lastTs - $firstTs) / 86400) : 0;
        $result['best'] = $best;
        $result['best_distance'] = $bestDistance;
        $result['distinct_days'] = $distinctDays;
        $result['span_days'] = $spanDays;

        /* 서로 다른 날짜가 2개 이상이며 현재 진행률과 12.5%p 안의 관측점이 있어야 사용한다. */
        if ($distinctDays >= 2 && $spanDays >= 1 && $best !== null && $bestDistance <= 12.5) {
            $result['eligible'] = true;
        }
        return $result;
    }

    /**
     * 과거 상세 노무 입력의 "실제 근무일별 공수"를 작업발생 패턴으로 만든다.
     *
     * - 강제입력: 월 총액만 있으므로 gongsu_map이 없어 여기에는 포함되지 않는다.
     * - 과거 직접복원: 오늘 입력했더라도 work_date가 과거 날짜로 남으므로 작업발생 패턴에는 포함된다.
     * - 단, 오늘의 등록시각을 과거 입력지연으로 착각하지 않도록 입력시점 학습에는 절대 사용하지 않는다.
     */
    private static function laborWorkPatternSamples($pdo, $amountSamples, $context)
    {
        $samples = array();
        if (!$pdo || !function_exists('cpms_load_gongsu_data')) return $samples;
        if (empty($context['snapshot_date']) || empty($context['target_ym'])) return $samples;

        $targetProgress = self::progress($context['snapshot_date'], $context['target_ym'], 'labor');
        $targetDay = isset($targetProgress['day']) ? (int)$targetProgress['day'] : 0;
        if ($targetDay <= 0) return $samples;

        $groups = array();
        foreach ((array)$amountSamples as $sample) {
            if (!isset($sample['cost_type']) || (string)$sample['cost_type'] !== 'labor') continue;
            $projectId = isset($sample['project_id']) ? (int)$sample['project_id'] : 0;
            $ym = isset($sample['ym']) ? (string)$sample['ym'] : '';
            if ($projectId <= 0 || self::validYm($ym) === '') continue;
            if (!isset($groups[$projectId])) $groups[$projectId] = array();
            $groups[$projectId][$ym] = $sample;
        }

        foreach ($groups as $projectId=>$monthMap) {
            krsort($monthMap, SORT_STRING);
            $used = 0;
            foreach ($monthMap as $ym=>$amountSample) {
                if ($used >= self::WORK_PATTERN_MONTH_LIMIT) break;
                $projectName = isset($amountSample['project_name']) ? trim((string)$amountSample['project_name']) : '';
                if ($projectName === '') continue;

                try {
                    $gongsuData = cpms_load_gongsu_data($pdo, $projectName, $ym);
                } catch (Exception $e) {
                    continue;
                }
                $gongsuMap = isset($gongsuData['gongsu_map']) && is_array($gongsuData['gongsu_map'])
                    ? $gongsuData['gongsu_map'] : array();
                $outputDays = isset($gongsuData['output_days']) && is_array($gongsuData['output_days'])
                    ? $gongsuData['output_days'] : array();
                $gongsuUnit = isset($gongsuData['gongsu_unit']) && is_array($gongsuData['gongsu_unit'])
                    ? $gongsuData['gongsu_unit'] : array();

                /*
                 * 공사 > 노무비에서 사람이 과거 날짜의 공수를 하나씩 직접 입력하면
                 * cpms_labor_gongsu_overrides에 저장된다. 화면에서 실제 노무비를 계산할 때와 동일하게
                 * 승인된 공수 보정값까지 합친 뒤 작업발생 패턴을 만든다.
                 * 월 총액 강제입력(cpms_labor_force_adjustments)은 이 데이터셋에 포함되지 않는다.
                 */
                if (function_exists('cpms_apply_labor_overrides_to_dataset')) {
                    try {
                        $overrideData = cpms_apply_labor_overrides_to_dataset(
                            $gongsuMap,
                            $outputDays,
                            $gongsuUnit,
                            (int)$projectId,
                            (string)$ym
                        );
                        if (is_array($overrideData) && isset($overrideData['gongsu_map']) && is_array($overrideData['gongsu_map'])) {
                            $gongsuMap = $overrideData['gongsu_map'];
                        }
                    } catch (Exception $e) {
                        /* 보정자료를 읽지 못해도 원래 상세공수 자료로 학습을 계속한다. */
                    }
                }
                if (count($gongsuMap) === 0) continue;

                $daysInMonth = (int)date('t', strtotime($ym . '-01'));
                $cutoffDay = min(max(1, $targetDay), max(1, $daysInMonth));
                $cutoffDate = sprintf('%s-%02d', $ym, $cutoffDay);
                $total = 0.0;
                $partial = 0.0;
                $workDates = array();
                $workers = 0;

                foreach ($gongsuMap as $workerKey=>$dailyMap) {
                    if (!is_array($dailyMap)) continue;
                    $workerHas = false;
                    foreach ($dailyMap as $workDate=>$gongsuValue) {
                        if (strpos((string)$workDate, $ym . '-') !== 0 || !is_numeric($gongsuValue)) continue;
                        $gongsu = (float)$gongsuValue;
                        if ($gongsu <= 0) continue;
                        $total += $gongsu;
                        if ((string)$workDate <= $cutoffDate) $partial += $gongsu;
                        $workDates[(string)$workDate] = true;
                        $workerHas = true;
                    }
                    if ($workerHas) $workers++;
                }
                if ($total <= 0 || count($workDates) === 0) continue;

                $rate = max(0.0, min(100.0, $partial / $total * 100));
                $samples[] = array(
                    'project_id'=>(int)$projectId,
                    'project_name'=>$projectName,
                    'cost_type'=>'labor',
                    'ym'=>$ym,
                    'final_amount'=>isset($amountSample['final_amount']) ? (float)$amountSample['final_amount'] : 0.0,
                    'work_occurrence_rate'=>round($rate, 3),
                    'work_pattern_eligible'=>1,
                    'work_source'=>'DETAILED_DAILY_GONGSU',
                    'work_total_gongsu'=>round($total, 3),
                    'work_partial_gongsu'=>round($partial, 3),
                    'work_day_count'=>count($workDates),
                    'work_worker_count'=>$workers
                );
                $used++;
            }
        }
        return $samples;
    }

    /**
     * 당시 시점의 입력완료율 표본을 일일 스냅샷에서 만든다.
     * 최종금액은 비율 계산용이며, 최종 금액 학습의 주 데이터는 historicalAmountSamples()이다.
     */
    private static function timingSamplesFromSnapshots($rows, $today, $graceDays, $context)
    {
        $categories = self::categories();
        $groups = array();
        foreach ((array)$rows as $row) {
            $project = (int)$row['project_id'];
            $ym = (string)$row['target_ym'];
            foreach ($categories as $cost=>$column) {
                if (!self::finalizedYm($ym, $today, $cost)) continue;
                $key = $project . ':' . $ym . ':' . $cost;
                if (!isset($groups[$key])) $groups[$key] = array();
                $groups[$key][] = $row;
            }
        }

        $samples = array();
        foreach ($groups as $key=>$monthRows) {
            $parts = explode(':', $key, 3);
            $project = (int)$parts[0];
            $ym = $parts[1];
            $cost = $parts[2];
            $column = $categories[$cost];
            $period = CostChangeService::periodForYm($cost, $ym);
            $final = null;

            foreach ($monthRows as $row) {
                if (!empty($period['end']) && (string)$row['snapshot_date'] >= (string)$period['end']) {
                    $final = $row;
                }
            }
            if (!$final) continue;

            $finalAmount = isset($final[$column]) ? (float)$final[$column] : 0.0;
            if ($cost === 'safety' && isset($final['health_amount'])) $finalAmount += (float)$final['health_amount'];
            if ($finalAmount <= 0) continue;

            $currentProgress = self::progress($context['snapshot_date'], $context['target_ym'], $cost);
            $evidence = self::snapshotTimingEvidence($monthRows, $column, $ym, $cost, $currentProgress);
            if (empty($evidence['eligible']) || !is_array($evidence['best'])) continue;

            $best = $evidence['best'];
            $partial = isset($best[$column]) ? (float)$best[$column] : 0.0;
            if ($cost === 'safety' && isset($best['health_amount'])) $partial += (float)$best['health_amount'];
            $rate = max(0, min(100, $partial / $finalAmount * 100));

            /* 원천분류는 품질 가중치용으로만 사용하고, 정상 일일 스냅샷 월 전체를 탈락시키지 않는다. */
            $governancePdo = isset($context['pdo']) ? $context['pdo'] : null;
            $strictEligible = AiCostDataGovernanceService::timingGroupEligible($governancePdo, $project, $ym, $cost);
            $samples[] = array(
                'project_id'=>$project,
                'cost_type'=>$cost,
                'ym'=>$ym,
                'completion_rate'=>$rate,
                'final_amount'=>$finalAmount,
                'timing_eligible'=>1,
                'timing_quality'=>$strictEligible ? 1.0 : 0.75,
                'progress_rate'=>$currentProgress['rate'],
                'progress_day'=>$currentProgress['day'],
                'amount_source'=>'SNAPSHOT_FINAL_FALLBACK',
                'timing_source'=>$strictEligible ? 'LIVE_DAILY_SNAPSHOT_VERIFIED' : 'DAILY_SNAPSHOT_OBSERVED',
                'snapshot_distinct_days'=>isset($evidence['distinct_days']) ? (int)$evidence['distinct_days'] : 0,
                'snapshot_span_days'=>isset($evidence['span_days']) ? (int)$evidence['span_days'] : 0,
                'snapshot_progress_distance'=>isset($evidence['best_distance']) ? (float)$evidence['best_distance'] : null
            );
        }
        return $samples;
    }

    /**
     * 금액 표본과 입력시점 표본을 월/현장/비용항목 단위로 합친다.
     */
    private static function mergeSamples($amountSamples, $timingSamples, $workSamples)
    {
        $map = array();
        foreach ((array)$amountSamples as $sample) {
            $key = (int)$sample['project_id'] . ':' . (string)$sample['ym'] . ':' . (string)$sample['cost_type'];
            $map[$key] = $sample;
        }
        foreach ((array)$timingSamples as $sample) {
            $key = (int)$sample['project_id'] . ':' . (string)$sample['ym'] . ':' . (string)$sample['cost_type'];
            if (isset($map[$key])) {
                $map[$key]['completion_rate'] = isset($sample['completion_rate']) ? $sample['completion_rate'] : null;
                $map[$key]['timing_eligible'] = !empty($sample['timing_eligible']) ? 1 : 0;
                $map[$key]['timing_quality'] = isset($sample['timing_quality']) ? (float)$sample['timing_quality'] : 1.0;
                $map[$key]['progress_rate'] = isset($sample['progress_rate']) ? $sample['progress_rate'] : null;
                $map[$key]['progress_day'] = isset($sample['progress_day']) ? $sample['progress_day'] : null;
                $map[$key]['timing_source'] = isset($sample['timing_source']) ? (string)$sample['timing_source'] : 'DAILY_SNAPSHOT_OBSERVED';
            } else {
                $map[$key] = $sample;
            }
        }
        foreach ((array)$workSamples as $sample) {
            $key = (int)$sample['project_id'] . ':' . (string)$sample['ym'] . ':' . (string)$sample['cost_type'];
            if (!isset($map[$key])) {
                $map[$key] = array(
                    'project_id'=>(int)$sample['project_id'],
                    'project_name'=>isset($sample['project_name']) ? (string)$sample['project_name'] : '',
                    'cost_type'=>(string)$sample['cost_type'],
                    'ym'=>(string)$sample['ym'],
                    'completion_rate'=>null,
                    'final_amount'=>isset($sample['final_amount']) ? (float)$sample['final_amount'] : 0.0,
                    'timing_eligible'=>0,
                    'progress_rate'=>null,
                    'progress_day'=>null,
                    'amount_source'=>'CPMS_FINALIZED_ACTUAL',
                    'timing_source'=>'NONE'
                );
            }
            $map[$key]['work_occurrence_rate'] = isset($sample['work_occurrence_rate']) ? (float)$sample['work_occurrence_rate'] : null;
            $map[$key]['work_pattern_eligible'] = !empty($sample['work_pattern_eligible']) ? 1 : 0;
            $map[$key]['work_source'] = isset($sample['work_source']) ? (string)$sample['work_source'] : 'DETAILED_DAILY_GONGSU';
            $map[$key]['work_total_gongsu'] = isset($sample['work_total_gongsu']) ? (float)$sample['work_total_gongsu'] : null;
            $map[$key]['work_partial_gongsu'] = isset($sample['work_partial_gongsu']) ? (float)$sample['work_partial_gongsu'] : null;
            $map[$key]['work_day_count'] = isset($sample['work_day_count']) ? (int)$sample['work_day_count'] : 0;
            $map[$key]['work_worker_count'] = isset($sample['work_worker_count']) ? (int)$sample['work_worker_count'] : 0;
        }
        return array_values($map);
    }

    private static function aggregateSamples($samples, $scope, $projectId, $costType, $eventStats)
    {
        $rates = array();
        $months = array();
        $workRates = array();
        $workMonths = array();
        $amountMonths = array();
        $finals = array();
        $events = 0;
        $humanEvents = 0;
        $lags = array();
        $lagSamples = 0;
        $sameDayRates = array();
        $withinOneRates = array();
        $lateTwoPlusRates = array();
        $bulk = array();
        $corrections = array();
        $moves = array();
        $seenEvent = array();

        foreach ((array)$samples as $sample) {
            if ($scope === 'PROJECT_CATEGORY' && ((int)$sample['project_id'] !== $projectId || $sample['cost_type'] !== $costType)) continue;
            if ($scope === 'PROJECT_ALL' && (int)$sample['project_id'] !== $projectId) continue;
            if ($scope === 'COMPANY_CATEGORY' && $sample['cost_type'] !== $costType) continue;

            $amountMonths[$sample['ym']] = true;
            $finals[] = $sample['final_amount'];

            if (!empty($sample['work_pattern_eligible']) && isset($sample['work_occurrence_rate']) && $sample['work_occurrence_rate'] !== null) {
                $workRates[] = (float)$sample['work_occurrence_rate'];
                $workMonths[$sample['ym']] = true;
            }

            /*
             * 입력지연/수정 통계는 일일 스냅샷 보유 여부와 별개다.
             * 실제 직접입력 이벤트가 있으면 입력완료율 표본이 없는 항목도 지연 측정은 가능해야 한다.
             */
            $eventKey = (int)$sample['project_id'] . ':' . $sample['cost_type'];
            if (isset($eventStats[$eventKey]) && !isset($seenEvent[$eventKey])) {
                $seenEvent[$eventKey] = true;
                $row = $eventStats[$eventKey];
                $events += (int)$row['event_count'];
                if (isset($row['human_event_count'])) $humanEvents += (int)$row['human_event_count'];
                if ($row['average_lag'] !== null) $lags[] = $row['average_lag'];
                if (isset($row['lag_sample_count'])) $lagSamples += (int)$row['lag_sample_count'];
                if (isset($row['same_day_rate']) && $row['same_day_rate'] !== null) $sameDayRates[] = $row['same_day_rate'];
                if (isset($row['within_one_business_day_rate']) && $row['within_one_business_day_rate'] !== null) $withinOneRates[] = $row['within_one_business_day_rate'];
                if (isset($row['late_two_plus_rate']) && $row['late_two_plus_rate'] !== null) $lateTwoPlusRates[] = $row['late_two_plus_rate'];
                if ($row['bulk_rate'] !== null) $bulk[] = $row['bulk_rate'];
                if ($row['correction_rate'] !== null) $corrections[] = $row['correction_rate'];
                if ($row['move_rate'] !== null) $moves[] = $row['move_rate'];
            }

            if (empty($sample['timing_eligible']) || $sample['completion_rate'] === null) continue;
            $rates[] = $sample['completion_rate'];
            $months[$sample['ym']] = true;
        }

        /*
         * 과거 금액/스냅샷 표본이 없는 신규 현장이라도 현재 실제 직접입력 이벤트가 있으면
         * 입력지연 통계는 즉시 표시할 수 있어야 한다. 샘플 루프에서 잡히지 않은 이벤트를 보완한다.
         */
        foreach ((array)$eventStats as $eventKey=>$row) {
            if (isset($seenEvent[$eventKey])) continue;
            $parts = explode(':', (string)$eventKey, 2);
            $eventProject = isset($parts[0]) ? (int)$parts[0] : 0;
            $eventCost = isset($parts[1]) ? (string)$parts[1] : '';
            $match = false;
            if ($scope === 'PROJECT_CATEGORY') $match = $eventProject === $projectId && $eventCost === $costType;
            else if ($scope === 'PROJECT_ALL') $match = $eventProject === $projectId;
            else if ($scope === 'COMPANY_CATEGORY') $match = $eventCost === $costType;
            else if ($scope === 'COMPANY_ALL') $match = true;
            if (!$match) continue;

            $seenEvent[$eventKey] = true;
            $events += isset($row['event_count']) ? (int)$row['event_count'] : 0;
            if (isset($row['human_event_count'])) $humanEvents += (int)$row['human_event_count'];
            if (isset($row['average_lag']) && $row['average_lag'] !== null) $lags[] = $row['average_lag'];
            if (isset($row['lag_sample_count'])) $lagSamples += (int)$row['lag_sample_count'];
            if (isset($row['same_day_rate']) && $row['same_day_rate'] !== null) $sameDayRates[] = $row['same_day_rate'];
            if (isset($row['within_one_business_day_rate']) && $row['within_one_business_day_rate'] !== null) $withinOneRates[] = $row['within_one_business_day_rate'];
            if (isset($row['late_two_plus_rate']) && $row['late_two_plus_rate'] !== null) $lateTwoPlusRates[] = $row['late_two_plus_rate'];
            if (isset($row['bulk_rate']) && $row['bulk_rate'] !== null) $bulk[] = $row['bulk_rate'];
            if (isset($row['correction_rate']) && $row['correction_rate'] !== null) $corrections[] = $row['correction_rate'];
            if (isset($row['move_rate']) && $row['move_rate'] !== null) $moves[] = $row['move_rate'];
        }

        $sampleMonths = array_keys($months);
        sort($sampleMonths, SORT_STRING);
        $amountSampleMonths = array_keys($amountMonths);
        sort($amountSampleMonths, SORT_STRING);
        $workSampleMonths = array_keys($workMonths);
        sort($workSampleMonths, SORT_STRING);

        return array(
            'expected_completion_rate'=>self::median($rates),
            'sample_month_count'=>count($months),
            'sample_months'=>$sampleMonths,
            'expected_work_occurrence_rate'=>self::median($workRates),
            'work_pattern_month_count'=>count($workMonths),
            'work_sample_months'=>$workSampleMonths,
            'work_pattern_volatility'=>self::volatility($workRates),
            'amount_pattern_month_count'=>count($amountMonths),
            'amount_sample_months'=>$amountSampleMonths,
            'event_count'=>$events,
            'human_input_event_count'=>$humanEvents,
            'average_input_lag_days'=>self::median($lags),
            'lag_sample_count'=>$lagSamples,
            'same_day_input_rate'=>self::median($sameDayRates),
            'within_one_business_day_rate'=>self::median($withinOneRates),
            'late_two_plus_input_rate'=>self::median($lateTwoPlusRates),
            'completion_volatility'=>self::volatility($rates),
            'late_bulk_rate'=>self::median($bulk),
            'correction_rate'=>self::median($corrections),
            'month_move_rate'=>self::median($moves),
            'historical_final_median'=>self::median($finals)
        );
    }

    private static function savePattern($pdo, $context, $scope, $projectId, $costType, $progress, $stats, $fingerprint)
    {
        $fallback = array(
            'PROJECT_CATEGORY'=>'PROJECT_CATEGORY',
            'PROJECT_ALL'=>'PROJECT_ALL',
            'COMPANY_CATEGORY'=>'COMPANY_CATEGORY',
            'COMPANY_ALL'=>'COMPANY_ALL'
        );

        if ($stats['expected_completion_rate'] !== null) {
            $status = $stats['sample_month_count'] >= self::MIN_TIMING_MONTHS_FOR_DIRECT_PROJECTION ? 'READY' : 'LIMITED';
        } else if (isset($stats['work_pattern_month_count']) && (int)$stats['work_pattern_month_count'] > 0) {
            $status = $stats['amount_pattern_month_count'] > 0 ? 'AMOUNT_AND_WORK' : 'WORK_ONLY';
        } else {
            $status = $stats['amount_pattern_month_count'] > 0 ? 'AMOUNT_ONLY' : 'INSUFFICIENT';
        }

        /*
         * 절대 금액 중앙값은 반드시 같은 현장 + 같은 비용항목에서만 예측 후보로 공개한다.
         * PROJECT_ALL / COMPANY_* 중앙값은 진단용으로만 저장한다.
         */
        $usableHistoricalMedian = $scope === 'PROJECT_CATEGORY' ? $stats['historical_final_median'] : null;
        $now = date('Y-m-d H:i:s');
        $sql = 'INSERT INTO `' . self::TABLE_NAME . '` '
            . '(analysis_date,target_ym,scope_type,project_id,cost_type,progress_day,progress_rate,expected_completion_rate,'
            . 'sample_month_count,amount_pattern_month_count,event_count,average_input_lag_days,completion_volatility,late_bulk_rate,'
            . 'correction_rate,month_move_rate,fallback_level,data_status,calculation_version,source_fingerprint,detail_data,'
            . 'first_created_at,last_calculated_at,calculation_count,created_at,updated_at) '
            . 'VALUES (:date,:ym,:scope,:project,:cost,:day,:progress,:completion,:months,:amount_months,:events,:lag,:volatility,'
            . ':bulk,:correction,:move,:fallback,:status,:version,:fingerprint,:detail,:first,:last,1,:created,:updated) '
            . 'ON DUPLICATE KEY UPDATE progress_rate=VALUES(progress_rate),expected_completion_rate=VALUES(expected_completion_rate),'
            . 'sample_month_count=VALUES(sample_month_count),amount_pattern_month_count=VALUES(amount_pattern_month_count),'
            . 'event_count=VALUES(event_count),average_input_lag_days=VALUES(average_input_lag_days),'
            . 'completion_volatility=VALUES(completion_volatility),late_bulk_rate=VALUES(late_bulk_rate),'
            . 'correction_rate=VALUES(correction_rate),month_move_rate=VALUES(month_move_rate),fallback_level=VALUES(fallback_level),'
            . 'data_status=VALUES(data_status),calculation_version=VALUES(calculation_version),source_fingerprint=VALUES(source_fingerprint),'
            . 'detail_data=VALUES(detail_data),last_calculated_at=VALUES(last_calculated_at),'
            . 'calculation_count=calculation_count+1,updated_at=VALUES(updated_at)';

        $detail = array(
            'historical_final_median'=>$usableHistoricalMedian,
            'scope_historical_final_median'=>$stats['historical_final_median'],
            'sample_months'=>$stats['sample_months'],
            'amount_sample_months'=>$stats['amount_sample_months'],
            'expected_work_occurrence_rate'=>isset($stats['expected_work_occurrence_rate']) ? $stats['expected_work_occurrence_rate'] : null,
            'work_pattern_month_count'=>isset($stats['work_pattern_month_count']) ? (int)$stats['work_pattern_month_count'] : 0,
            'work_sample_months'=>isset($stats['work_sample_months']) ? $stats['work_sample_months'] : array(),
            'work_pattern_volatility'=>isset($stats['work_pattern_volatility']) ? $stats['work_pattern_volatility'] : null,
            'amount_origin'=>'CPMS_FINALIZED_ACTUALS',
            'work_origin'=>'DETAILED_DAILY_GONGSU_BY_WORK_DATE',
            'timing_origin'=>'OBSERVED_DAILY_SNAPSHOT',
            'human_input_event_count'=>isset($stats['human_input_event_count']) ? (int)$stats['human_input_event_count'] : 0,
            'lag_sample_count'=>isset($stats['lag_sample_count']) ? (int)$stats['lag_sample_count'] : 0,
            'same_day_input_rate'=>isset($stats['same_day_input_rate']) ? $stats['same_day_input_rate'] : null,
            'within_one_business_day_rate'=>isset($stats['within_one_business_day_rate']) ? $stats['within_one_business_day_rate'] : null,
            'late_two_plus_input_rate'=>isset($stats['late_two_plus_input_rate']) ? $stats['late_two_plus_input_rate'] : null,
            'input_lag_basis'=>'ACTUAL_DATE_TO_EVENT_DATE_BUSINESS_DAYS',
            'input_lag_origin'=>'LIVE_EMPLOYEE_INPUT_ONLY',
            'input_lag_holiday_basis'=>'WEEKEND_AND_CPMS_HOLIDAY_CACHE',
            'input_lag_scope'=>$scope,
            'absolute_amount_scope'=>$scope === 'PROJECT_CATEGORY' ? 'SAME_PROJECT_SAME_CATEGORY' : 'DIAGNOSTIC_ONLY',
            'direct_projection_min_timing_months'=>self::MIN_TIMING_MONTHS_FOR_DIRECT_PROJECTION,
            'direct_projection_min_completion_rate'=>self::minCompletionRate($pdo)
        );

        $st = $pdo->prepare($sql);
        if (!$st) return false;
        return $st->execute(array(
            ':date'=>$context['snapshot_date'],
            ':ym'=>$context['target_ym'],
            ':scope'=>$scope,
            ':project'=>$projectId,
            ':cost'=>$costType,
            ':day'=>$progress['day'],
            ':progress'=>$progress['rate'],
            ':completion'=>$stats['expected_completion_rate'],
            ':months'=>$stats['sample_month_count'],
            ':amount_months'=>$stats['amount_pattern_month_count'],
            ':events'=>$stats['event_count'],
            ':lag'=>$stats['average_input_lag_days'],
            ':volatility'=>$stats['completion_volatility'],
            ':bulk'=>$stats['late_bulk_rate'],
            ':correction'=>$stats['correction_rate'],
            ':move'=>$stats['month_move_rate'],
            ':fallback'=>$fallback[$scope],
            ':status'=>$status,
            ':version'=>self::CALCULATION_VERSION,
            ':fingerprint'=>$fingerprint,
            ':detail'=>self::encode($detail),
            ':first'=>$now,
            ':last'=>$now,
            ':created'=>$now,
            ':updated'=>$now
        ));
    }

    private static function currentProjectIds($pdo, $context)
    {
        $projects = array();
        if (!$pdo || empty($context['snapshot_date']) || empty($context['target_ym'])) return $projects;
        if (!self::tableExists($pdo, self::SNAPSHOT_TABLE)) return $projects;
        try {
            $st = $pdo->prepare(
                'SELECT DISTINCT project_id FROM `' . self::SNAPSHOT_TABLE . '` '
                . 'WHERE snapshot_date=:snapshot_date AND target_ym=:target_ym AND project_id>0 ORDER BY project_id'
            );
            if (!$st || !$st->execute(array(
                ':snapshot_date'=>$context['snapshot_date'],
                ':target_ym'=>$context['target_ym']
            ))) return $projects;
            while (($projectId = $st->fetchColumn()) !== false) {
                $projectId = (int)$projectId;
                if ($projectId > 0) $projects[$projectId] = true;
            }
        } catch (Exception $e) {
        }
        return $projects;
    }

    public static function calculateLatest($pdo = null, $triggerType = 'SYSTEM')
    {
        $pdo = self::pdo($pdo);
        $empty = array(
            'ok'=>false,
            'status'=>'FAILED',
            'projects'=>0,
            'patterns'=>0,
            'message'=>'입력패턴을 계산하지 못했습니다.'
        );
        if (!$pdo) {
            $empty['message'] = 'DB 연결 상태를 확인할 수 없습니다.';
            return $empty;
        }
        if (!self::isInstalled($pdo)) {
            $empty['message'] = '입력패턴 테이블을 먼저 설치해주세요.';
            return $empty;
        }

        $context = self::latestContext($pdo);
        if (empty($context['available'])) {
            $empty['message'] = '입력패턴을 계산하려면 일일 스냅샷이 필요합니다.';
            return $empty;
        }

        $today = self::businessToday();

        /* 1) 기존 CPMS 과거 월별 최종금액: 금액 학습 */
        $amountSamples = self::historicalAmountSamples($pdo, $context['target_ym'], $today);

        /* 2) 당시 실제 상태가 남아 있는 스냅샷: 입력시점 학습 */
        $snapshotRows = self::historyRows($pdo, $context['target_ym'], $today, 0);
        $timingContext = $context;
        $timingContext['pdo'] = $pdo;
        $timingSamples = self::timingSamplesFromSnapshots($snapshotRows, $today, 0, $timingContext);

        /* 3) 과거 상세 노무 입력: 등록시각이 아니라 실제 근무일/공수 분포만 작업발생 패턴으로 학습 */
        $workSamples = self::laborWorkPatternSamples($pdo, $amountSamples, $context);

        /* 4) 금액 / 실제 입력시점 / 작업발생 자료를 같은 월·현장·항목끼리 결합 */
        $samples = self::mergeSamples($amountSamples, $timingSamples, $workSamples);
        $events = self::eventStats($pdo, $context['target_ym'], $context['snapshot_date']);

        $projects = self::currentProjectIds($pdo, $context);
        if (count($projects) === 0) {
            foreach ($samples as $sample) $projects[(int)$sample['project_id']] = true;
        }

        $fingerprint = hash('sha256', self::encode(array(
            'context'=>$context,
            'amount_samples'=>$amountSamples,
            'timing_samples'=>$timingSamples,
            'work_samples'=>$workSamples,
            'amount_origin'=>'CPMS_FINALIZED_ACTUALS',
            'work_origin'=>'DETAILED_DAILY_GONGSU_BY_WORK_DATE',
            'timing_origin'=>'OBSERVED_DAILY_SNAPSHOT',
            'version'=>self::CALCULATION_VERSION
        )));

        $saved = 0;
        $failed = 0;
        try {
            foreach (array_keys($projects) as $projectId) {
                foreach (self::categories() as $cost=>$column) {
                    $progress = self::progress($context['snapshot_date'], $context['target_ym'], $cost);
                    foreach (array('PROJECT_CATEGORY','PROJECT_ALL') as $scope) {
                        $scopeCost = $scope === 'PROJECT_ALL' ? 'all' : $cost;
                        $stats = self::aggregateSamples($samples, $scope, $projectId, $cost, $events);
                        if (self::savePattern($pdo, $context, $scope, $projectId, $scopeCost, $progress, $stats, $fingerprint)) $saved++;
                        else $failed++;
                    }
                }
            }

            /* 회사 범위는 입력시점 패턴 보조용이다. 절대 금액은 진단용으로만 저장한다. */
            foreach (self::categories() as $cost=>$column) {
                $progress = self::progress($context['snapshot_date'], $context['target_ym'], $cost);
                $stats = self::aggregateSamples($samples, 'COMPANY_CATEGORY', 0, $cost, $events);
                if (self::savePattern($pdo, $context, 'COMPANY_CATEGORY', 0, $cost, $progress, $stats, $fingerprint)) $saved++;
                else $failed++;
            }

            $progress = self::progress($context['snapshot_date'], $context['target_ym'], 'other');
            $stats = self::aggregateSamples($samples, 'COMPANY_ALL', 0, 'all', $events);
            if (self::savePattern($pdo, $context, 'COMPANY_ALL', 0, 'all', $progress, $stats, $fingerprint)) $saved++;
            else $failed++;
        } catch (Exception $e) {
            error_log('[AI Input Pattern] calculation failed');
            return $empty;
        }

        return array(
            'ok'=>$saved > 0,
            'status'=>$failed > 0 ? 'PARTIAL' : 'COMPLETED',
            'projects'=>count($projects),
            'patterns'=>$saved,
            'amount_sample_count'=>count($amountSamples),
            'timing_sample_count'=>count($timingSamples),
            'work_sample_count'=>count($workSamples),
            'message'=>$saved > 0
                ? '과거 최종금액, 상세 공수의 작업발생 패턴, 실제 일일 스냅샷의 입력시점을 분리해 학습했습니다.'
                : '학습 가능한 입력패턴 자료가 부족합니다.'
        );
    }

    /**
     * 입력지연/반복수정 같은 평가성 지표는 반드시 '해당 현장 + 해당 비용항목'의 실제 자료만 반환한다.
     * 예측을 위해 회사/현장 전체 패턴으로 fallback 하더라도 그 표본수와 지연값을 개별 현장 값처럼 보여주지 않는다.
     */
    private static function projectCategoryInputDiagnostics($pdo, $analysisDate, $targetYm, $projectId, $costType)
    {
        $empty = array(
            'event_count'=>0,
            'average_input_lag_days'=>null,
            'late_bulk_rate'=>null,
            'correction_rate'=>null,
            'month_move_rate'=>null,
            'lag_sample_count'=>0,
            'same_day_input_rate'=>null,
            'within_one_business_day_rate'=>null,
            'late_two_plus_input_rate'=>null,
            'input_lag_basis'=>'ACTUAL_DATE_TO_EVENT_DATE_BUSINESS_DAYS',
            'input_lag_origin'=>'LIVE_EMPLOYEE_INPUT_ONLY',
            'input_lag_holiday_basis'=>'WEEKEND_AND_CPMS_HOLIDAY_CACHE',
            'input_lag_scope'=>'NONE'
        );
        try {
            $st = $pdo->prepare(
                'SELECT * FROM `' . self::TABLE_NAME . '` '
                . "WHERE analysis_date=:date AND target_ym=:ym AND scope_type='PROJECT_CATEGORY' "
                . 'AND project_id=:project AND cost_type=:cost ORDER BY progress_day DESC,id DESC LIMIT 1'
            );
            if (!$st || !$st->execute(array(
                ':date'=>$analysisDate,
                ':ym'=>$targetYm,
                ':project'=>(int)$projectId,
                ':cost'=>(string)$costType
            ))) return $empty;
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) return $empty;
            $detail = self::decode(isset($row['detail_data']) ? $row['detail_data'] : '');
            $empty['event_count'] = isset($row['event_count']) ? max(0,(int)$row['event_count']) : 0;
            $empty['average_input_lag_days'] = isset($row['average_input_lag_days']) && $row['average_input_lag_days'] !== null ? (float)$row['average_input_lag_days'] : null;
            $empty['late_bulk_rate'] = isset($row['late_bulk_rate']) && $row['late_bulk_rate'] !== null ? (float)$row['late_bulk_rate'] : null;
            $empty['correction_rate'] = isset($row['correction_rate']) && $row['correction_rate'] !== null ? (float)$row['correction_rate'] : null;
            $empty['month_move_rate'] = isset($row['month_move_rate']) && $row['month_move_rate'] !== null ? (float)$row['month_move_rate'] : null;
            $empty['lag_sample_count'] = isset($detail['lag_sample_count']) ? max(0,(int)$detail['lag_sample_count']) : 0;
            foreach (array('same_day_input_rate','within_one_business_day_rate','late_two_plus_input_rate') as $key) {
                $empty[$key] = isset($detail[$key]) && is_numeric($detail[$key]) ? (float)$detail[$key] : null;
            }
            if ($empty['lag_sample_count'] > 0 || $empty['event_count'] > 0) $empty['input_lag_scope'] = 'PROJECT_CATEGORY';
            return $empty;
        } catch (Exception $e) {
            return $empty;
        }
    }

    /**
     * 예측에 사용할 최적 패턴을 선택한다.
     *
     * 안전장치:
     * - 입력시점 표본 3개월 미만: current / completion 직접 확대 금지
     * - 입력완료율이 설정값(기본 20%) 미만: 직접 확대 금지
     * - 금액 중앙값은 PROJECT_CATEGORY에 저장된 동일 현장/동일 비용항목 값만 사용
     */
    public static function loadBestPattern($pdo, $analysisDate, $targetYm, $projectId, $costType)
    {
        $pdo = self::pdo($pdo);
        if (!$pdo || !self::isInstalled($pdo)) {
            return array('available'=>false,'fallback_level'=>'COLD_START');
        }

        /*
         * 금액은 반드시 동일 현장 + 동일 비용항목(PROJECT_CATEGORY)의 과거 실제금액만 사용한다.
         * PROJECT_ALL / COMPANY_* 단계는 절대금액을 복사하지 않고 입력시점 비율 fallback 용도로만 사용한다.
         */
        $candidates = array(
            array('PROJECT_CATEGORY', $projectId, $costType, true),
            array('PROJECT_ALL', $projectId, 'all', false),
            array('COMPANY_CATEGORY', 0, $costType, false),
            array('COMPANY_ALL', 0, 'all', false)
        );
        $minRate = self::minCompletionRate($pdo);
        $projectInputDiagnostics = self::projectCategoryInputDiagnostics($pdo, $analysisDate, $targetYm, $projectId, $costType);

        foreach ($candidates as $candidate) {
            try {
                $sql = 'SELECT * FROM `' . self::TABLE_NAME . '` '
                    . 'WHERE analysis_date=:date AND target_ym=:ym AND scope_type=:scope '
                    . 'AND project_id=:project AND cost_type=:cost '
                    . 'ORDER BY progress_day DESC,id DESC LIMIT 1';
                $st = $pdo->prepare($sql);
                if (!$st || !$st->execute(array(
                    ':date'=>$analysisDate,
                    ':ym'=>$targetYm,
                    ':scope'=>$candidate[0],
                    ':project'=>$candidate[1],
                    ':cost'=>$candidate[2]
                ))) continue;

                $row = $st->fetch(PDO::FETCH_ASSOC);
                if (!is_array($row)) continue;
                $detail = self::decode(isset($row['detail_data']) ? $row['detail_data'] : '');

                /* 입력지연 진단값은 위에서 PROJECT_CATEGORY 전용으로 별도 확보했다. */

                /* 회사/전체 fallback의 금액 중앙값은 개별 현장 예상금액으로 사용하지 않는다. */
                if (!$candidate[3]) {
                    unset($detail['historical_final_median']);
                    unset($detail['amount_sample_months']);
                    unset($detail['expected_work_occurrence_rate']);
                    unset($detail['work_pattern_month_count']);
                    unset($detail['work_sample_months']);
                    unset($detail['work_pattern_volatility']);
                    $row['amount_pattern_month_count'] = 0;
                    $row['detail_data'] = self::encode($detail);
                }

                $hasAmount = isset($detail['historical_final_median']) && is_numeric($detail['historical_final_median']);
                $hasWorkPattern = isset($detail['expected_work_occurrence_rate']) && is_numeric($detail['expected_work_occurrence_rate'])
                    && isset($detail['work_pattern_month_count']) && (int)$detail['work_pattern_month_count'] > 0;
                $rawCompletion = isset($row['expected_completion_rate']) && $row['expected_completion_rate'] !== null
                    ? (float)$row['expected_completion_rate']
                    : null;
                $timingMonths = isset($row['sample_month_count']) ? (int)$row['sample_month_count'] : 0;

                $directAllowed = $rawCompletion !== null
                    && $rawCompletion >= $minRate
                    && $timingMonths >= self::MIN_TIMING_MONTHS_FOR_DIRECT_PROJECTION;

                /*
                 * 중요: 완료율 값 자체는 화면/신뢰도 계산에 필요하므로 절대 지우지 않는다.
                 * 직접 확대 가능 여부만 별도 flag로 전달한다.
                 */
                $row['raw_expected_completion_rate'] = $rawCompletion;
                $row['direct_projection_blocked'] = $directAllowed ? 0 : 1;
                if ($directAllowed) {
                    $row['direct_projection_block_reason'] = 'READY';
                } else if ($rawCompletion === null) {
                    $row['direct_projection_block_reason'] = 'NO_TIMING_PATTERN';
                } else if ($timingMonths < self::MIN_TIMING_MONTHS_FOR_DIRECT_PROJECTION) {
                    $row['direct_projection_block_reason'] = 'TIMING_SAMPLE_SHORTAGE';
                } else {
                    $row['direct_projection_block_reason'] = 'LOW_COMPLETION_RATE';
                }

                if ($rawCompletion === null && !$hasAmount && !$hasWorkPattern) continue;

                /*
                 * 예측용 fallback의 회사/현장전체 통계가 개별 현장의 입력지연 값으로 노출되지 않게
                 * 평가성 지표는 항상 PROJECT_CATEGORY 실제값(없으면 0/null)으로 덮어쓴다.
                 */
                $row['event_count'] = $projectInputDiagnostics['event_count'];
                $row['average_input_lag_days'] = $projectInputDiagnostics['average_input_lag_days'];
                $row['late_bulk_rate'] = $projectInputDiagnostics['late_bulk_rate'];
                $row['correction_rate'] = $projectInputDiagnostics['correction_rate'];
                $row['month_move_rate'] = $projectInputDiagnostics['month_move_rate'];
                foreach (array('lag_sample_count','same_day_input_rate','within_one_business_day_rate','late_two_plus_input_rate','input_lag_basis','input_lag_origin','input_lag_holiday_basis','input_lag_scope') as $diagKey) {
                    $detail[$diagKey] = $projectInputDiagnostics[$diagKey];
                }
                $row['detail_data'] = self::encode($detail);

                $row['available'] = true;
                return $row;
            } catch (Exception $e) {
            }
        }

        return array(
            'available'=>false,
            'fallback_level'=>'COLD_START',
            'expected_completion_rate'=>null,
            'sample_month_count'=>0,
            'amount_pattern_month_count'=>0,
            'event_count'=>0,
            'direct_projection_blocked'=>1,
            'direct_projection_block_reason'=>'NO_PATTERN'
        );
    }

    public static function learningState($monthCount)
    {
        $monthCount = max(0, (int)$monthCount);
        if ($monthCount === 0) return array('code'=>'COLD_START','label'=>'입력시점 학습자료 없음','weight'=>0,'confidence_limit'=>'INSUFFICIENT');
        if ($monthCount === 1) return array('code'=>'INITIAL','label'=>'입력시점 초기학습','weight'=>20,'confidence_limit'=>'LOW');
        if ($monthCount === 2) return array('code'=>'INITIAL_EXPANDED','label'=>'입력시점 초기학습 확대','weight'=>40,'confidence_limit'=>'MEDIUM');
        return array('code'=>'NORMAL_LEARNING','label'=>'입력시점 정상학습','weight'=>100,'confidence_limit'=>'HIGH');
    }

    /**
     * 화면 표시용 학습현황.
     * 기존 호환성을 위해 month_count/months는 입력시점 학습을 뜻한다.
     * amount_*는 기존 CPMS의 월별 최종금액 학습현황이다.
     */
    public static function learningSummary($pdo, $analysisDate, $targetYm)
    {
        $empty = array(
            'month_count'=>0,
            'months'=>array(),
            'first_ym'=>'',
            'last_ym'=>'',
            'state'=>self::learningState(0),
            'timing_month_count'=>0,
            'timing_months'=>array(),
            'timing_first_ym'=>'',
            'timing_last_ym'=>'',
            'amount_month_count'=>0,
            'amount_months'=>array(),
            'amount_first_ym'=>'',
            'amount_last_ym'=>'',
            'work_month_count'=>0,
            'work_months'=>array(),
            'work_first_ym'=>'',
            'work_last_ym'=>''
        );

        $pdo = self::pdo($pdo);
        if (!$pdo || !self::isInstalled($pdo)) return $empty;

        try {
            $st = $pdo->prepare(
                'SELECT sample_month_count,amount_pattern_month_count,detail_data '
                . 'FROM `' . self::TABLE_NAME . '` WHERE analysis_date=:date AND target_ym=:ym'
            );
            if (!$st || !$st->execute(array(':date'=>$analysisDate, ':ym'=>$targetYm))) return $empty;

            $timingMonths = array();
            $amountMonths = array();
            $workMonths = array();
            $maxTimingCount = 0;
            $maxAmountCount = 0;
            $maxWorkCount = 0;

            foreach ((array)$st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $maxTimingCount = max($maxTimingCount, isset($row['sample_month_count']) ? (int)$row['sample_month_count'] : 0);
                $maxAmountCount = max($maxAmountCount, isset($row['amount_pattern_month_count']) ? (int)$row['amount_pattern_month_count'] : 0);
                $detail = self::decode(isset($row['detail_data']) ? $row['detail_data'] : '');
                $maxWorkCount = max($maxWorkCount, isset($detail['work_pattern_month_count']) ? (int)$detail['work_pattern_month_count'] : 0);

                if (isset($detail['sample_months']) && is_array($detail['sample_months'])) {
                    foreach ($detail['sample_months'] as $ym) {
                        if (self::validYm($ym)) $timingMonths[(string)$ym] = true;
                    }
                }
                if (isset($detail['amount_sample_months']) && is_array($detail['amount_sample_months'])) {
                    foreach ($detail['amount_sample_months'] as $ym) {
                        if (self::validYm($ym)) $amountMonths[(string)$ym] = true;
                    }
                }
                if (isset($detail['work_sample_months']) && is_array($detail['work_sample_months'])) {
                    foreach ($detail['work_sample_months'] as $ym) {
                        if (self::validYm($ym)) $workMonths[(string)$ym] = true;
                    }
                }
            }

            $timingList = array_keys($timingMonths);
            sort($timingList, SORT_STRING);
            $amountList = array_keys($amountMonths);
            sort($amountList, SORT_STRING);
            $workList = array_keys($workMonths);
            sort($workList, SORT_STRING);

            $timingCount = max($maxTimingCount, count($timingList));
            $amountCount = max($maxAmountCount, count($amountList));
            $workCount = max($maxWorkCount, count($workList));

            return array(
                'month_count'=>$timingCount,
                'months'=>$timingList,
                'first_ym'=>count($timingList) ? $timingList[0] : '',
                'last_ym'=>count($timingList) ? $timingList[count($timingList) - 1] : '',
                'state'=>self::learningState($timingCount),
                'timing_month_count'=>$timingCount,
                'timing_months'=>$timingList,
                'timing_first_ym'=>count($timingList) ? $timingList[0] : '',
                'timing_last_ym'=>count($timingList) ? $timingList[count($timingList) - 1] : '',
                'amount_month_count'=>$amountCount,
                'amount_months'=>$amountList,
                'amount_first_ym'=>count($amountList) ? $amountList[0] : '',
                'amount_last_ym'=>count($amountList) ? $amountList[count($amountList) - 1] : '',
                'work_month_count'=>$workCount,
                'work_months'=>$workList,
                'work_first_ym'=>count($workList) ? $workList[0] : '',
                'work_last_ym'=>count($workList) ? $workList[count($workList) - 1] : ''
            );
        } catch (Exception $e) {
            return $empty;
        }
    }

    public static function listLatest($pdo, $filters, $page, $perPage)
    {
        $pdo = self::pdo($pdo);
        $page = max(1, (int)$page);
        $perPage = max(1, min(100, (int)$perPage));
        if (!$pdo || !self::isInstalled($pdo)) return array();

        try {
            $date = isset($filters['analysis_date']) ? (string)$filters['analysis_date'] : '';
            if ($date === '') {
                $st = $pdo->query('SELECT MAX(analysis_date) FROM `' . self::TABLE_NAME . '`');
                $date = $st ? (string)$st->fetchColumn() : '';
            }
            $sql = 'SELECT * FROM `' . self::TABLE_NAME . '` WHERE analysis_date=:date '
                . 'ORDER BY scope_type,project_id,cost_type LIMIT :limit OFFSET :offset';
            $st = $pdo->prepare($sql);
            if (!$st) return array();
            $st->bindValue(':date', $date, PDO::PARAM_STR);
            $st->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $st->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
            if (!$st->execute()) return array();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : array();
        } catch (Exception $e) {
            return array();
        }
    }
}
?>
