<?php
/**
 * CEO Index 투입비 예측 - 입력완료 패턴 / 과거 금액 학습 서비스.
 *
 * 핵심 원칙
 * 1) 과거 월별 최종 투입금액은 기존 CPMS 원본 집계에서 바로 학습한다.
 * 2) 과거 이관/강제입력 자료는 "금액" 학습에만 사용한다.
 * 3) "언제 입력됐는지" 패턴은 신뢰 가능한 LIVE 입력 + 일일 스냅샷만 사용한다.
 * 4) 회사 전체의 절대 금액 중앙값을 개별 현장 예상금액으로 복사하지 않는다.
 * 5) 입력완료율 표본이 3개월 미만이거나 완료율이 너무 낮으면 직접 확대 예측을 막는다.
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
    const CALCULATION_VERSION = 'INPUT_PATTERN_V3';
    const DEFAULT_GRACE_DAYS = 0;
    const DEFAULT_MIN_COMPLETION_RATE = 20;
    const MIN_TIMING_MONTHS_FOR_DIRECT_PROJECTION = 3;
    const HISTORY_MONTH_LIMIT = 18;

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
            'health' => 'health_amount',
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
     * 안전/보건 비용은 일일 스냅샷과 동일한 기준으로 분리한다.
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
                $category = isset($row['category']) ? trim((string)$row['category']) : '';
                $amount = (float)cpms_safety_cost_row_amount($row);
                if ($category === '검진비') $result['health'] += $amount;
                else if ($category === '기타 안전·보건 비용') $result['other'] += $amount;
                else $result['safety'] += $amount;
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
                    'health'=>(float)$safetySplit['health'],
                    'other'=>(isset($monthly['deduction']) ? (float)$monthly['deduction'] : 0.0) + (float)$safetySplit['other']
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

    private static function eventStats($pdo, $targetYm)
    {
        $result = array();
        if (!self::tableExists($pdo, self::EVENT_TABLE)) return $result;
        $start = date('Y-m-d', strtotime($targetYm . '-01 -' . self::HISTORY_MONTH_LIMIT . ' months'));
        $end = $targetYm . '-01';
        $originWhere = AiCostDataGovernanceService::columnExists($pdo, self::EVENT_TABLE, 'data_origin')
            ? " AND data_origin='LIVE_EMPLOYEE_INPUT'"
            : ' AND 1=0';
        $sql = "SELECT project_id,cost_type,COUNT(*) AS event_count,"
            . "AVG(CASE WHEN actual_date IS NOT NULL THEN GREATEST(0,DATEDIFF(DATE(event_at),actual_date)) ELSE NULL END) AS average_lag,"
            . "SUM(CASE WHEN event_action IN ('UPDATE','ADJUST') THEN 1 ELSE 0 END) AS correction_count,"
            . "SUM(CASE WHEN source_type IN ('EXCEL','APPROVAL') AND actual_date IS NOT NULL AND DATEDIFF(DATE(event_at),actual_date)>=7 THEN 1 ELSE 0 END) AS bulk_count,"
            . "SUM(CASE WHEN event_action='UPDATE' AND old_data LIKE '%settlement_ym%' AND new_data LIKE '%settlement_ym%' THEN 1 ELSE 0 END) AS move_count "
            . "FROM `" . self::EVENT_TABLE . "` WHERE event_at>=:start_date AND event_at<:end_date AND event_action<>'DELETE'"
            . $originWhere . " GROUP BY project_id,cost_type";
        try {
            $st = $pdo->prepare($sql);
            if (!$st || !$st->execute(array(':start_date'=>$start, ':end_date'=>$end))) return $result;
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ((array)$rows as $row) {
                $project = (int)$row['project_id'];
                $cost = (string)$row['cost_type'];
                $count = max(0, (int)$row['event_count']);
                $result[$project . ':' . $cost] = array(
                    'event_count'=>$count,
                    'average_lag'=>$row['average_lag'] === null ? null : (float)$row['average_lag'],
                    'correction_rate'=>$count ? round((int)$row['correction_count'] / $count * 100, 3) : null,
                    'bulk_rate'=>$count ? round((int)$row['bulk_count'] / $count * 100, 3) : null,
                    'move_rate'=>$count ? round((int)$row['move_count'] / $count * 100, 3) : null
                );
            }
        } catch (Exception $e) {
        }
        return $result;
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
            if ($finalAmount <= 0) continue;

            $governancePdo = isset($context['pdo']) ? $context['pdo'] : null;
            $timingEligible = AiCostDataGovernanceService::timingGroupEligible($governancePdo, $project, $ym, $cost);
            if (!$timingEligible) continue;

            $currentProgress = self::progress($context['snapshot_date'], $context['target_ym'], $cost);
            $best = null;
            $bestDistance = 9999;
            foreach ($monthRows as $row) {
                $p = self::progress($row['snapshot_date'], $ym, $cost);
                $distance = abs((float)$p['rate'] - (float)$currentProgress['rate']);
                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                    $best = $row;
                }
            }
            if (!$best) continue;

            $partial = isset($best[$column]) ? (float)$best[$column] : 0.0;
            $rate = max(0, min(100, $partial / $finalAmount * 100));
            $samples[] = array(
                'project_id'=>$project,
                'cost_type'=>$cost,
                'ym'=>$ym,
                'completion_rate'=>$rate,
                'final_amount'=>$finalAmount,
                'timing_eligible'=>1,
                'progress_rate'=>$currentProgress['rate'],
                'progress_day'=>$currentProgress['day'],
                'amount_source'=>'SNAPSHOT_FINAL_FALLBACK',
                'timing_source'=>'LIVE_DAILY_SNAPSHOT'
            );
        }
        return $samples;
    }

    /**
     * 금액 표본과 입력시점 표본을 월/현장/비용항목 단위로 합친다.
     */
    private static function mergeSamples($amountSamples, $timingSamples)
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
                $map[$key]['progress_rate'] = isset($sample['progress_rate']) ? $sample['progress_rate'] : null;
                $map[$key]['progress_day'] = isset($sample['progress_day']) ? $sample['progress_day'] : null;
                $map[$key]['timing_source'] = 'LIVE_DAILY_SNAPSHOT';
            } else {
                $map[$key] = $sample;
            }
        }
        return array_values($map);
    }

    private static function aggregateSamples($samples, $scope, $projectId, $costType, $eventStats)
    {
        $rates = array();
        $months = array();
        $amountMonths = array();
        $finals = array();
        $events = 0;
        $lags = array();
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

            if (empty($sample['timing_eligible']) || $sample['completion_rate'] === null) continue;
            $rates[] = $sample['completion_rate'];
            $months[$sample['ym']] = true;

            $eventKey = (int)$sample['project_id'] . ':' . $sample['cost_type'];
            if (isset($eventStats[$eventKey]) && !isset($seenEvent[$eventKey])) {
                $seenEvent[$eventKey] = true;
                $row = $eventStats[$eventKey];
                $events += (int)$row['event_count'];
                if ($row['average_lag'] !== null) $lags[] = $row['average_lag'];
                if ($row['bulk_rate'] !== null) $bulk[] = $row['bulk_rate'];
                if ($row['correction_rate'] !== null) $corrections[] = $row['correction_rate'];
                if ($row['move_rate'] !== null) $moves[] = $row['move_rate'];
            }
        }

        $sampleMonths = array_keys($months);
        sort($sampleMonths, SORT_STRING);
        $amountSampleMonths = array_keys($amountMonths);
        sort($amountSampleMonths, SORT_STRING);

        return array(
            'expected_completion_rate'=>self::median($rates),
            'sample_month_count'=>count($months),
            'sample_months'=>$sampleMonths,
            'amount_pattern_month_count'=>count($amountMonths),
            'amount_sample_months'=>$amountSampleMonths,
            'event_count'=>$events,
            'average_input_lag_days'=>self::median($lags),
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

        $status = $stats['expected_completion_rate'] === null
            ? ($stats['amount_pattern_month_count'] > 0 ? 'AMOUNT_ONLY' : 'INSUFFICIENT')
            : ($stats['sample_month_count'] >= self::MIN_TIMING_MONTHS_FOR_DIRECT_PROJECTION ? 'READY' : 'LIMITED');

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
            'amount_origin'=>'CPMS_FINALIZED_ACTUALS',
            'timing_origin'=>'LIVE_DAILY_SNAPSHOT_ONLY',
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

        /* 3) 두 종류 학습자료를 같은 월/현장/항목끼리 결합 */
        $samples = self::mergeSamples($amountSamples, $timingSamples);
        $events = self::eventStats($pdo, $context['target_ym']);

        $projects = self::currentProjectIds($pdo, $context);
        if (count($projects) === 0) {
            foreach ($samples as $sample) $projects[(int)$sample['project_id']] = true;
        }

        $fingerprint = hash('sha256', self::encode(array(
            'context'=>$context,
            'amount_samples'=>$amountSamples,
            'timing_samples'=>$timingSamples,
            'amount_origin'=>'CPMS_FINALIZED_ACTUALS',
            'timing_origin'=>'LIVE_DAILY_SNAPSHOT_ONLY',
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
            'message'=>$saved > 0
                ? '기존 CPMS 과거금액과 신뢰 가능한 입력시점 패턴을 분리해 학습했습니다.'
                : '학습 가능한 입력패턴 자료가 부족합니다.'
        );
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

        $candidates = array(
            array('PROJECT_CATEGORY', $projectId, $costType, true),
            array('PROJECT_ALL', $projectId, 'all', false),
            array('COMPANY_CATEGORY', 0, $costType, false),
            array('COMPANY_ALL', 0, 'all', false)
        );
        $minRate = self::minCompletionRate($pdo);

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
                $hasAmount = isset($detail['historical_final_median']) && is_numeric($detail['historical_final_median']);

                $rawCompletion = isset($row['expected_completion_rate']) && $row['expected_completion_rate'] !== null
                    ? (float)$row['expected_completion_rate']
                    : null;
                $timingMonths = isset($row['sample_month_count']) ? (int)$row['sample_month_count'] : 0;

                $directAllowed = $rawCompletion !== null
                    && $rawCompletion >= $minRate
                    && $timingMonths >= self::MIN_TIMING_MONTHS_FOR_DIRECT_PROJECTION;

                if (!$directAllowed && $rawCompletion !== null) {
                    $row['raw_expected_completion_rate'] = $rawCompletion;
                    $row['expected_completion_rate'] = null;
                    $row['direct_projection_blocked'] = 1;
                    if ($timingMonths < self::MIN_TIMING_MONTHS_FOR_DIRECT_PROJECTION) {
                        $row['direct_projection_block_reason'] = 'TIMING_SAMPLE_SHORTAGE';
                    } else {
                        $row['direct_projection_block_reason'] = 'LOW_COMPLETION_RATE';
                    }
                } else {
                    $row['direct_projection_blocked'] = 0;
                    $row['direct_projection_block_reason'] = $directAllowed ? 'READY' : 'NO_TIMING_PATTERN';
                }

                if ($row['expected_completion_rate'] === null && !$hasAmount) continue;
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
            'amount_last_ym'=>''
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
            $maxTimingCount = 0;
            $maxAmountCount = 0;

            foreach ((array)$st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $maxTimingCount = max($maxTimingCount, isset($row['sample_month_count']) ? (int)$row['sample_month_count'] : 0);
                $maxAmountCount = max($maxAmountCount, isset($row['amount_pattern_month_count']) ? (int)$row['amount_pattern_month_count'] : 0);
                $detail = self::decode(isset($row['detail_data']) ? $row['detail_data'] : '');

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
            }

            $timingList = array_keys($timingMonths);
            sort($timingList, SORT_STRING);
            $amountList = array_keys($amountMonths);
            sort($amountList, SORT_STRING);

            $timingCount = max($maxTimingCount, count($timingList));
            $amountCount = max($maxAmountCount, count($amountList));

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
                'amount_last_ym'=>count($amountList) ? $amountList[count($amountList) - 1] : ''
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
