<?php
use App\Core\Db;

require_once __DIR__ . '/../construction/tabs/partials/sales_data_loader.php';
require_once __DIR__ . '/../construction/tabs/partials/labor_data_loader.php';
require_once __DIR__ . '/../construction/tabs/partials/outsourcing_data_helper.php';
require_once __DIR__ . '/../safety/safety_cost_helper.php';

$pdo = Db::pdo();
$selectedYm = isset($_GET['ym']) ? trim((string)$_GET['ym']) : date('Y-m');
if (!cpms_monthly_summary_ym_valid($selectedYm)) {
    $selectedYm = date('Y-m');
}

function cpms_monthly_summary_ym_valid($ym) {
    return preg_match('/^\d{4}-\d{2}$/', (string)$ym);
}

function cpms_monthly_summary_money($value) {
    if ((float)$value == 0.0) return '-';
    return number_format((float)$value, 0);
}

function cpms_monthly_summary_count($value) {
    $n = (float)$value;
    if ($n == 0.0) return '-';
    if (abs($n - round($n)) < 0.001) return number_format($n, 0);
    return rtrim(rtrim(number_format($n, 2, '.', ','), '0'), '.');
}

function cpms_monthly_summary_ratio($a, $b) {
    $b = (float)$b;
    if ($b <= 0) return '-';
    return number_format(((float)$a / $b) * 100, 1) . '%';
}

function cpms_monthly_summary_cache_key($pdo, $suffix) {
    $prefix = 'nopdo';
    if ($pdo && function_exists('spl_object_hash')) {
        $prefix = spl_object_hash($pdo);
    }
    return $prefix . ':' . (string)$suffix;
}

function cpms_monthly_summary_table_exists($pdo, $table) {
    if (!$pdo) return false;
    static $cache = array();
    $key = cpms_monthly_summary_cache_key($pdo, 'table:' . (string)$table);
    if (isset($cache[$key])) return $cache[$key];
    try {
        $st = $pdo->prepare('SHOW TABLES LIKE :t');
        $st->bindValue(':t', $table);
        $st->execute();
        $cache[$key] = is_array($st->fetch());
        return $cache[$key];
    } catch (Exception $e) {
        $cache[$key] = false;
        return false;
    }
}

function cpms_monthly_summary_column_exists($pdo, $table, $column) {
    if (!$pdo) return false;
    static $cache = array();
    $key = cpms_monthly_summary_cache_key($pdo, 'column:' . (string)$table . ':' . (string)$column);
    if (isset($cache[$key])) return $cache[$key];
    try {
        $st = $pdo->prepare('SHOW COLUMNS FROM ' . $table . ' LIKE :c');
        $st->bindValue(':c', $column);
        $st->execute();
        $cache[$key] = is_array($st->fetch());
        return $cache[$key];
    } catch (Exception $e) {
        $cache[$key] = false;
        return false;
    }
}

function cpms_monthly_summary_zero_map($months) {
    $map = array();
    foreach ($months as $ym) {
        $map[$ym] = 0.0;
    }
    return $map;
}

function cpms_monthly_summary_cost_ym($useDate) {
    $useDate = trim((string)$useDate);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $useDate)) return '';
    $day = (int)substr($useDate, 8, 2);
    $baseYm = substr($useDate, 0, 7);
    if ($day >= 26) {
        return date('Y-m', strtotime($baseYm . '-01 +1 month'));
    }
    return $baseYm;
}

function cpms_monthly_summary_sales_range($ym) {
    if (function_exists('cpms_sales_period_range')) {
        $range = cpms_sales_period_range($ym);
        return array(
            'start' => isset($range['start']) ? (string)$range['start'] : $ym . '-01',
            'end' => isset($range['end']) ? (string)$range['end'] : date('Y-m-t', strtotime($ym . '-01')),
        );
    }
    return array('start' => $ym . '-01', 'end' => date('Y-m-t', strtotime($ym . '-01')));
}

function cpms_monthly_summary_months_until($project, $selectedYm) {
    $startYm = $selectedYm;
    if (is_array($project) && isset($project['start_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$project['start_date'])) {
        $startYm = substr((string)$project['start_date'], 0, 7);
    }
    if ($startYm > $selectedYm) {
        $startYm = $selectedYm;
    }
    $months = array();
    $cur = strtotime($startYm . '-01');
    $end = strtotime($selectedYm . '-01');
    while ($cur !== false && $cur <= $end) {
        $months[] = date('Y-m', $cur);
        $cur = strtotime('+1 month', $cur);
    }
    if (count($months) === 0) $months[] = $selectedYm;
    return $months;
}

function cpms_monthly_summary_month_options($projects, $selectedYm) {
    $map = array();
    $map[date('Y-m')] = true;
    $map[$selectedYm] = true;
    foreach ($projects as $project) {
        $start = isset($project['start_date']) ? (string)$project['start_date'] : '';
        $end = isset($project['end_date']) ? (string)$project['end_date'] : '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            continue;
        }
        $cur = strtotime(substr($start, 0, 7) . '-01');
        $last = strtotime(substr($end, 0, 7) . '-01');
        while ($cur !== false && $cur <= $last) {
            $map[date('Y-m', $cur)] = true;
            $cur = strtotime('+1 month', $cur);
        }
    }
    ksort($map);
    return array_keys($map);
}

function cpms_monthly_summary_ensure_remark_table($pdo) {
    if (!$pdo) return false;
    static $cache = array();
    $key = cpms_monthly_summary_cache_key($pdo, 'remark-table');
    if (isset($cache[$key])) return $cache[$key];
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_project_monthly_summary_remarks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            ym VARCHAR(7) NOT NULL,
            remark TEXT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uk_project_monthly_summary_remark (project_id, ym),
            KEY idx_ym (ym)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $cache[$key] = true;
        return true;
    } catch (Exception $e) {
        $cache[$key] = false;
        return false;
    }
}

function cpms_monthly_summary_load_remarks($pdo, $ym) {
    $map = array();
    if (!$pdo || !cpms_monthly_summary_ensure_remark_table($pdo)) return $map;
    try {
        $st = $pdo->prepare('SELECT project_id, remark FROM cpms_project_monthly_summary_remarks WHERE ym = :ym');
        $st->bindValue(':ym', $ym);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $map[(int)$row['project_id']] = isset($row['remark']) ? (string)$row['remark'] : '';
            }
        }
    } catch (Exception $e) {
    }
    return $map;
}

function cpms_monthly_summary_confirmed_revenue_map($pdo, $projectId, $months) {
    $result = array('months' => cpms_monthly_summary_zero_map($months), 'total' => 0.0, 'has_monthly_amount' => false);
    if (!$pdo || (int)$projectId <= 0) return $result;
    if (!function_exists('cpms_confirmed_sales_has_data') || !cpms_confirmed_sales_has_data($pdo, (int)$projectId)) return $result;
    foreach ($months as $ym) {
        $range = cpms_monthly_summary_sales_range($ym);
        $amount = function_exists('cpms_confirmed_sales_total_between') ? (float)cpms_confirmed_sales_total_between($pdo, (int)$projectId, $range['start'], $range['end']) : 0.0;
        $result['months'][$ym] = $amount;
        $result['total'] += $amount;
    }
    if ($result['total'] > 0) $result['has_monthly_amount'] = true;
    return $result;
}

function cpms_monthly_summary_revenue_map($pdo, $projectId, $months) {
    $confirmed = cpms_monthly_summary_confirmed_revenue_map($pdo, $projectId, $months);
    if (isset($confirmed['has_monthly_amount']) && $confirmed['has_monthly_amount']) {
        return $confirmed['months'];
    }
    if (function_exists('cpms_sales_monthly_map')) {
        $fallback = cpms_sales_monthly_map($pdo, $projectId, $months);
        if (isset($fallback['months']) && is_array($fallback['months'])) {
            return $fallback['months'];
        }
    }
    return cpms_monthly_summary_zero_map($months);
}

function cpms_monthly_summary_parse_money($value) {
    if (function_exists('cpms_parse_money_value')) return (float)cpms_parse_money_value($value);
    $raw = preg_replace('/[^0-9.\-]/', '', (string)$value);
    if ($raw === '' || !is_numeric($raw)) return 0.0;
    return (float)$raw;
}

function cpms_monthly_summary_labor_breakdown($pdo, $projectId, $projectName, $ym) {
    $result = array('amount' => 0.0, 'output_day_sum' => 0.0, 'workers_considered' => 0);
    if (!function_exists('cpms_load_gongsu_data') || !function_exists('cpms_build_timesheet_workers') || !function_exists('cpms_load_direct_team_members') || !function_exists('cpms_load_project_labor_workers') || !function_exists('cpms_build_project_worker_rows')) return $result;
    static $directTeamCache = array();
    static $timesheetCache = array();
    $pdoKey = cpms_monthly_summary_cache_key($pdo, 'labor');
    if (!isset($directTeamCache[$pdoKey])) {
        $directTeamCache[$pdoKey] = cpms_load_direct_team_members($pdo);
    }
    $timesheetKey = $pdoKey . ':project:' . (int)$projectId;
    if (!isset($timesheetCache[$timesheetKey])) {
        $projectLaborWorkers = cpms_load_project_labor_workers($pdo, $projectId);
        $workerRows = cpms_build_project_worker_rows($projectLaborWorkers, $directTeamCache[$pdoKey]);
        $timesheetCache[$timesheetKey] = cpms_build_timesheet_workers($workerRows);
    }
    $timesheetWorkers = $timesheetCache[$timesheetKey];
    $gongsuData = cpms_load_gongsu_data($pdo, $projectName, $ym);
    $attendanceGongsuMap = isset($gongsuData['gongsu_map']) && is_array($gongsuData['gongsu_map']) ? $gongsuData['gongsu_map'] : array();
    $attendanceGongsuUnit = isset($gongsuData['gongsu_unit']) && is_array($gongsuData['gongsu_unit']) ? $gongsuData['gongsu_unit'] : array();
    $attendanceOutputDays = isset($gongsuData['output_days']) && is_array($gongsuData['output_days']) ? $gongsuData['output_days'] : array();
    if (function_exists('cpms_apply_labor_overrides_to_dataset')) {
        $overrideDataset = cpms_apply_labor_overrides_to_dataset($attendanceGongsuMap, $attendanceOutputDays, $attendanceGongsuUnit, $projectId, $ym);
        $attendanceGongsuMap = isset($overrideDataset['gongsu_map']) && is_array($overrideDataset['gongsu_map']) ? $overrideDataset['gongsu_map'] : array();
        $attendanceOutputDays = isset($overrideDataset['output_days']) && is_array($overrideDataset['output_days']) ? $overrideDataset['output_days'] : array();
    }
    foreach ($timesheetWorkers as $worker) {
        $workerName = isset($worker['name']) ? (string)$worker['name'] : '';
        $workerKey = function_exists('cpms_normalize_worker_key') ? cpms_normalize_worker_key($workerName) : trim((string)$workerName);
        if ($workerKey === '') continue;
        $outputDays = isset($attendanceOutputDays[$workerKey]) ? (int)$attendanceOutputDays[$workerKey] : 0;
        if ($outputDays <= 0) continue;
        $dailyMap = isset($attendanceGongsuMap[$workerKey]) && is_array($attendanceGongsuMap[$workerKey]) ? $attendanceGongsuMap[$workerKey] : array();
        $totalGongsu = 0.0;
        foreach ($dailyMap as $dateKey => $gongsuValue) {
            if (!is_numeric($gongsuValue)) continue;
            if (strpos((string)$dateKey, $ym) !== 0) continue;
            $totalGongsu += (float)$gongsuValue;
        }
        if ($totalGongsu <= 0) continue;
        $wageRate = function_exists('cpms_resolve_labor_wage_rate') ? (float)cpms_resolve_labor_wage_rate($worker) : cpms_monthly_summary_parse_money(isset($worker['deposit_rate']) ? $worker['deposit_rate'] : '');
        if ($wageRate <= 0) continue;
        $result['amount'] += $totalGongsu * $wageRate;
        $result['output_day_sum'] += $outputDays;
        $result['workers_considered']++;
    }
    $forceAmount = function_exists('cpms_labor_force_amount') ? (float)cpms_labor_force_amount($pdo, $projectId, $ym) : 0.0;
    if ($forceAmount > 0) $result['amount'] += $forceAmount;
    return $result;
}

function cpms_monthly_summary_equipment_bucket($category, $spec) {
    $text = trim((string)$category . ' ' . (string)$spec);
    if (strpos($text, '굴삭') !== false || stripos($text, 'excav') !== false) return 'excavator';
    if (strpos($text, '덤프') !== false || stripos($text, 'dump') !== false) return 'dump';
    if (strpos($text, '지게') !== false || stripos($text, 'fork') !== false) return 'forklift';
    return 'other';
}

function cpms_monthly_summary_project_metrics($pdo, $project, $selectedYm, $remark) {
    $projectId = isset($project['id']) ? (int)$project['id'] : 0;
    $projectName = isset($project['name']) ? (string)$project['name'] : '';
    $months = cpms_monthly_summary_months_until($project, $selectedYm);
    $inputByMonth = cpms_monthly_summary_zero_map($months);
    $laborByMonth = cpms_monthly_summary_zero_map($months);
    $costEndDate = $selectedYm . '-25';
    $equipmentCounts = array('excavator' => 0.0, 'dump' => 0.0, 'other' => 0.0, 'forklift' => 0.0);
    $workerOutputDays = 0.0;
    $breakdown = array('labor' => 0.0, 'outsourcing' => 0.0, 'equipment' => 0.0, 'material' => 0.0, 'purchase' => 0.0, 'other' => 0.0, 'safety' => 0.0, 'deduction' => 0.0);

    if ($pdo && $projectId > 0) {
        if (cpms_monthly_summary_table_exists($pdo, 'cpms_material_items') && cpms_monthly_summary_table_exists($pdo, 'cpms_material_usage')) {
            try {
                $deletedWhere = cpms_monthly_summary_column_exists($pdo, 'cpms_material_items', 'is_deleted') ? ' AND (m.is_deleted = 0 OR m.is_deleted IS NULL)' : '';
                $st = $pdo->prepare('SELECT m.category, u.use_date, u.amount FROM cpms_material_items m INNER JOIN cpms_material_usage u ON u.material_id = m.id AND u.project_id = m.project_id WHERE m.project_id = :pid AND u.use_date <= :cost_end' . $deletedWhere);
                $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
                $st->bindValue(':cost_end', $costEndDate);
                $st->execute();
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                if (is_array($rows)) {
                    foreach ($rows as $row) {
                        $category = isset($row['category']) ? trim((string)$row['category']) : '';
                        if ($category === '안전관리비') continue;
                        $ym = cpms_monthly_summary_cost_ym(isset($row['use_date']) ? $row['use_date'] : '');
                        if ($ym === '' || $ym > $selectedYm) continue;
                        if (!isset($inputByMonth[$ym])) $inputByMonth[$ym] = 0.0;
                        $materialAmount = isset($row['amount']) ? (float)$row['amount'] : 0.0;
                        $inputByMonth[$ym] += $materialAmount;
                        if ($ym === $selectedYm) {
                            if ($category === '구매품') $breakdown['purchase'] += $materialAmount;
                            else if ($category === '기타경비') $breakdown['other'] += $materialAmount;
                            else $breakdown['material'] += $materialAmount;
                        }
                    }
                }
            } catch (Exception $e) {
            }
        }

        if (function_exists('cpms_safety_cost_project_items')) {
            $safetyRows = cpms_safety_cost_project_items($projectId);
            if (is_array($safetyRows)) {
                foreach ($safetyRows as $row) {
                    $useDate = isset($row['use_date']) ? cpms_safety_cost_valid_date($row['use_date']) : '';
                    if ($useDate === '') continue;
                    $ym = substr($useDate, 0, 7);
                    if ($ym > $selectedYm) continue;
                    if (!isset($inputByMonth[$ym])) $inputByMonth[$ym] = 0.0;
                    $safetyAmount = cpms_safety_cost_row_amount($row);
                    $inputByMonth[$ym] += $safetyAmount;
                    if ($ym === $selectedYm) $breakdown['safety'] += $safetyAmount;
                }
            }
        }

        if (cpms_monthly_summary_table_exists($pdo, 'cpms_equipment_items') && cpms_monthly_summary_table_exists($pdo, 'cpms_equipment_usage')) {
            try {
                $hasWorkUnit = cpms_monthly_summary_column_exists($pdo, 'cpms_equipment_usage', 'work_unit');
                $hasBaseRate = cpms_monthly_summary_column_exists($pdo, 'cpms_equipment_usage', 'base_rate_snapshot');
                $hasDeleted = cpms_monthly_summary_column_exists($pdo, 'cpms_equipment_items', 'is_deleted');
                $extra = '';
                if ($hasWorkUnit) $extra .= ', u.work_unit';
                if ($hasBaseRate) $extra .= ', u.base_rate_snapshot';
                $deletedWhere = $hasDeleted ? ' AND (e.is_deleted = 0 OR e.is_deleted IS NULL)' : '';
                $st = $pdo->prepare('SELECT e.category, e.spec, e.base_rate, u.use_date, u.amount' . $extra . ' FROM cpms_equipment_items e INNER JOIN cpms_equipment_usage u ON u.equipment_id = e.id AND u.project_id = e.project_id WHERE e.project_id = :pid AND u.use_date <= :cost_end' . $deletedWhere);
                $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
                $st->bindValue(':cost_end', $costEndDate);
                $st->execute();
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                if (is_array($rows)) {
                    foreach ($rows as $row) {
                        $ym = cpms_monthly_summary_cost_ym(isset($row['use_date']) ? $row['use_date'] : '');
                        if ($ym === '' || $ym > $selectedYm) continue;
                        $workUnit = ($hasWorkUnit && isset($row['work_unit'])) ? (float)$row['work_unit'] : 1.0;
                        if ($workUnit <= 0) $workUnit = 1.0;
                        $storedAmount = isset($row['amount']) ? (float)$row['amount'] : 0.0;
                        $rate = ($hasBaseRate && isset($row['base_rate_snapshot'])) ? (float)$row['base_rate_snapshot'] : 0.0;
                        if ($rate <= 0 && isset($row['base_rate'])) $rate = (float)$row['base_rate'];
                        $amount = $storedAmount;
                        if ($rate > 0) $amount = $workUnit * $rate;
                        if (!isset($inputByMonth[$ym])) $inputByMonth[$ym] = 0.0;
                        $inputByMonth[$ym] += $amount;
                        if ($ym === $selectedYm) $breakdown['equipment'] += $amount;
                        $bucket = cpms_monthly_summary_equipment_bucket(isset($row['category']) ? $row['category'] : '', isset($row['spec']) ? $row['spec'] : '');
                        $equipmentCounts[$bucket] += $workUnit;
                    }
                }
            } catch (Exception $e) {
            }
        }

        foreach ($months as $ym) {
            $labor = cpms_monthly_summary_labor_breakdown($pdo, $projectId, $projectName, $ym);
            $laborGrossAmount = isset($labor['amount']) ? (float)$labor['amount'] : 0.0;
            $laborOutsourcingAmount = 0.0;
            if (function_exists('cpms_outsourcing_labor_company_rows_for_month')) {
                $laborOutsourcingRow = cpms_outsourcing_labor_company_rows_for_month($pdo, $projectId, $projectName, $ym);
                $laborOutsourcingAmount = isset($laborOutsourcingRow['total']) ? (float)$laborOutsourcingRow['total'] : 0.0;
            }
            if ($laborOutsourcingAmount < 0) $laborOutsourcingAmount = 0.0;
            if ($laborOutsourcingAmount > $laborGrossAmount) $laborOutsourcingAmount = $laborGrossAmount;
            $laborAmount = $laborGrossAmount - $laborOutsourcingAmount;
            $monthStart = $ym . '-01';
            $monthEnd = date('Y-m-t', strtotime($monthStart));
            $manualOutsourcingAmount = cpms_monthly_summary_table_exists($pdo, 'cpms_outsourcing_costs') && function_exists('cpms_outsourcing_manual_total_between') ? (float)cpms_outsourcing_manual_total_between($pdo, $projectId, $monthStart, $monthEnd) : 0.0;
            $laborByMonth[$ym] = $laborAmount;
            $inputByMonth[$ym] += $laborGrossAmount + $manualOutsourcingAmount;
            if ($ym === $selectedYm) {
                $breakdown['labor'] += $laborAmount;
                $breakdown['outsourcing'] += $laborOutsourcingAmount + $manualOutsourcingAmount;
            }
            $workerOutputDays += isset($labor['output_day_sum']) ? (float)$labor['output_day_sum'] : 0.0;
        }

        if (cpms_monthly_summary_table_exists($pdo, 'cpms_project_monthly_deductions')) {
            try {
                $st = $pdo->prepare('SELECT ym, amount FROM cpms_project_monthly_deductions WHERE project_id = :pid AND ym <= :ym');
                $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
                $st->bindValue(':ym', $selectedYm);
                $st->execute();
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                if (is_array($rows)) {
                    foreach ($rows as $row) {
                        $ym = isset($row['ym']) ? (string)$row['ym'] : '';
                        if (!cpms_monthly_summary_ym_valid($ym)) continue;
                        if (!isset($inputByMonth[$ym])) $inputByMonth[$ym] = 0.0;
                        $deductionAmount = isset($row['amount']) ? (float)$row['amount'] : 0.0;
                        $inputByMonth[$ym] += $deductionAmount;
                        if ($ym === $selectedYm) $breakdown['deduction'] += $deductionAmount;
                    }
                }
            } catch (Exception $e) {
            }
        }
    }

    $revenueByMonth = $pdo && $projectId > 0 ? cpms_monthly_summary_revenue_map($pdo, $projectId, $months) : cpms_monthly_summary_zero_map($months);
    $previousInput = 0.0;
    $monthInput = 0.0;
    $cumulativeRevenue = 0.0;
    $currentRevenue = 0.0;
    foreach ($inputByMonth as $ym => $amount) {
        if ($ym < $selectedYm) $previousInput += (float)$amount;
        if ($ym === $selectedYm) $monthInput += (float)$amount;
    }
    foreach ($revenueByMonth as $ym => $amount) {
        if ($ym <= $selectedYm) $cumulativeRevenue += (float)$amount;
        if ($ym === $selectedYm) $currentRevenue += (float)$amount;
    }
    $totalInput = $previousInput + $monthInput;
    return array(
        'project_id' => $projectId,
        'project_name' => $projectName,
        'contract_amount' => isset($project['contract_amount']) ? (float)$project['contract_amount'] : 0.0,
        'previous_input' => $previousInput,
        'month_input' => $monthInput,
        'total_input' => $totalInput,
        'labor_amount' => isset($laborByMonth[$selectedYm]) ? (float)$laborByMonth[$selectedYm] : 0.0,
        'current_revenue' => $currentRevenue,
        'worker_output_days' => $workerOutputDays,
        'equipment' => $equipmentCounts,
        'cumulative_input' => $totalInput,
        'cumulative_revenue' => $cumulativeRevenue,
        'breakdown' => $breakdown,
        'remark' => (string)$remark,
    );
}

$projects = array();
$errors = array();
$summaryRows = array();
$monthOptions = array($selectedYm);

if (!$pdo) {
    $errors[] = 'DB 연결이 필요합니다.';
} else {
    try {
        cpms_monthly_summary_ensure_remark_table($pdo);
        $st = $pdo->query("SELECT id, name, start_date, end_date, contract_amount FROM cpms_projects WHERE name NOT LIKE '(가제)%' ORDER BY id DESC");
        $projects = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($projects)) $projects = array();
        $monthOptions = cpms_monthly_summary_month_options($projects, $selectedYm);
        $remarks = cpms_monthly_summary_load_remarks($pdo, $selectedYm);
        foreach ($projects as $project) {
            $pid2 = isset($project['id']) ? (int)$project['id'] : 0;
            $summaryRows[] = cpms_monthly_summary_project_metrics($pdo, $project, $selectedYm, isset($remarks[$pid2]) ? $remarks[$pid2] : '');
        }
    } catch (Exception $e) {
        $errors[] = '월별 투입비 집계를 불러오지 못했습니다. 오류: ' . $e->getMessage();
    }
}

$summaryTotals = array(
    'contract_amount' => 0.0,
    'previous_input' => 0.0,
    'month_input' => 0.0,
    'total_input' => 0.0,
    'labor_amount' => 0.0,
    'current_revenue' => 0.0,
    'worker_output_days' => 0.0,
    'equipment' => array(
        'excavator' => 0.0,
        'dump' => 0.0,
        'other' => 0.0,
        'forklift' => 0.0,
    ),
    'cumulative_input' => 0.0,
    'cumulative_revenue' => 0.0,
    'breakdown' => array('labor' => 0.0, 'outsourcing' => 0.0, 'equipment' => 0.0, 'material' => 0.0, 'purchase' => 0.0, 'other' => 0.0, 'safety' => 0.0, 'deduction' => 0.0),
);
foreach ($summaryRows as $row) {
    $summaryTotals['contract_amount'] += isset($row['contract_amount']) ? (float)$row['contract_amount'] : 0.0;
    $summaryTotals['previous_input'] += isset($row['previous_input']) ? (float)$row['previous_input'] : 0.0;
    $summaryTotals['month_input'] += isset($row['month_input']) ? (float)$row['month_input'] : 0.0;
    $summaryTotals['total_input'] += isset($row['total_input']) ? (float)$row['total_input'] : 0.0;
    $summaryTotals['labor_amount'] += isset($row['labor_amount']) ? (float)$row['labor_amount'] : 0.0;
    $summaryTotals['current_revenue'] += isset($row['current_revenue']) ? (float)$row['current_revenue'] : 0.0;
    $summaryTotals['worker_output_days'] += isset($row['worker_output_days']) ? (float)$row['worker_output_days'] : 0.0;
    $summaryTotals['cumulative_input'] += isset($row['cumulative_input']) ? (float)$row['cumulative_input'] : 0.0;
    $summaryTotals['cumulative_revenue'] += isset($row['cumulative_revenue']) ? (float)$row['cumulative_revenue'] : 0.0;
    $rowBreakdown = isset($row['breakdown']) && is_array($row['breakdown']) ? $row['breakdown'] : array();
    foreach ($summaryTotals['breakdown'] as $breakdownKey => $breakdownAmount) {
        $summaryTotals['breakdown'][$breakdownKey] += isset($rowBreakdown[$breakdownKey]) ? (float)$rowBreakdown[$breakdownKey] : 0.0;
    }
    $eqTotal = isset($row['equipment']) && is_array($row['equipment']) ? $row['equipment'] : array();
    foreach ($summaryTotals['equipment'] as $bucket => $amount) {
        $summaryTotals['equipment'][$bucket] += isset($eqTotal[$bucket]) ? (float)$eqTotal[$bucket] : 0.0;
    }
}

$monthTitle = substr($selectedYm, 5, 2) . '월';
?>
<div class="bg-white rounded-3xl border border-gray-100 p-5">
  <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <div>
      <h3 class="text-xl font-extrabold text-gray-900">월별 투입비 집계</h3>
      <div class="text-sm text-gray-500 mt-1"><?php echo h(str_replace('-', '.', $selectedYm)); ?> 기준</div>
    </div>
    <form method="get" class="flex flex-wrap items-center gap-2">
      <input type="hidden" name="r" value="공무">
      <input type="hidden" name="tab" value="monthly_summary">
      <select name="ym" class="px-3 py-2 border rounded-xl min-w-[150px]">
        <?php foreach ($monthOptions as $ymOpt): ?>
          <option value="<?php echo h($ymOpt); ?>" <?php echo $ymOpt === $selectedYm ? 'selected' : ''; ?>><?php echo h(str_replace('-', '.', $ymOpt)); ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="px-4 py-2 rounded-xl bg-blue-600 text-white font-bold">조회</button>
    </form>
  </div>

  <?php if (count($errors) > 0): ?>
    <div class="mb-3 rounded-xl border border-red-200 bg-red-50 text-red-800 p-3 text-sm">
      <?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (count($summaryRows) === 0): ?>
    <div class="text-sm text-gray-600">표시할 프로젝트가 없습니다.</div>
  <?php else: ?>
    <?php
      $mobileMonthProfit = (float)$summaryTotals['current_revenue'] - (float)$summaryTotals['month_input'];
      $mobileBreakdown = isset($summaryTotals['breakdown']) && is_array($summaryTotals['breakdown']) ? $summaryTotals['breakdown'] : array();
      $mobileCards = array(
        array('매출', $summaryTotals['current_revenue'], 'bg-slate-900 text-white'),
        array('총투입비', $summaryTotals['month_input'], 'bg-white border border-gray-200 text-gray-900'),
        array('손익', $mobileMonthProfit, 'bg-white border border-gray-200 ' . ($mobileMonthProfit < 0 ? 'text-red-600' : 'text-blue-700')),
        array('노무비', isset($mobileBreakdown['labor']) ? $mobileBreakdown['labor'] : 0, 'bg-gray-50 border border-gray-200 text-gray-900'),
        array('외주비', isset($mobileBreakdown['outsourcing']) ? $mobileBreakdown['outsourcing'] : 0, 'bg-gray-50 border border-gray-200 text-gray-900'),
        array('장비비', isset($mobileBreakdown['equipment']) ? $mobileBreakdown['equipment'] : 0, 'bg-gray-50 border border-gray-200 text-gray-900'),
        array('자재비', isset($mobileBreakdown['material']) ? $mobileBreakdown['material'] : 0, 'bg-gray-50 border border-gray-200 text-gray-900'),
        array('구매품', isset($mobileBreakdown['purchase']) ? $mobileBreakdown['purchase'] : 0, 'bg-gray-50 border border-gray-200 text-gray-900'),
        array('기타경비', isset($mobileBreakdown['other']) ? $mobileBreakdown['other'] : 0, 'bg-gray-50 border border-gray-200 text-gray-900'),
        array('안전관리비', isset($mobileBreakdown['safety']) ? $mobileBreakdown['safety'] : 0, 'bg-gray-50 border border-gray-200 text-gray-900'),
        array('공제분', isset($mobileBreakdown['deduction']) ? $mobileBreakdown['deduction'] : 0, 'bg-gray-50 border border-gray-200 text-gray-900')
      );
    ?>
    <div class="cpms-monthly-mobile-summary" aria-label="월별 투입비 모바일 합계">
      <?php foreach ($mobileCards as $mobileCard): ?>
        <div class="rounded-2xl p-4 <?php echo h($mobileCard[2]); ?>">
          <div class="text-xs opacity-70 font-bold"><?php echo h($mobileCard[0]); ?></div>
          <div class="mt-1 text-lg font-extrabold break-words"><?php echo h(cpms_monthly_summary_money($mobileCard[1])); ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <form method="post" action="?r=project/monthly_summary_remark_save">
      <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
      <input type="hidden" name="ym" value="<?php echo h($selectedYm); ?>">
      <div class="overflow-x-auto">
        <table class="min-w-[1500px] w-full text-xs border border-gray-200">
          <thead>
            <tr class="bg-[#d7aa8a] text-gray-900">
              <th class="border p-2 align-middle" rowspan="2">현장명</th>
              <th class="border p-2 align-middle text-right" rowspan="2">계약금액</th>
              <th class="border p-2 text-center" colspan="4"><?php echo h($monthTitle); ?> 투입 금액</th>
              <th class="border p-2 align-middle text-right" rowspan="2">금회 예상기성금액</th>
              <th class="border p-2 align-middle text-right" rowspan="2">누적작업인원</th>
              <th class="border p-2 align-middle text-right" rowspan="2">굴삭기</th>
              <th class="border p-2 align-middle text-right" rowspan="2">덤프</th>
              <th class="border p-2 align-middle text-right" rowspan="2">기타장비</th>
              <th class="border p-2 align-middle text-right" rowspan="2">지게차</th>
              <th class="border p-2 align-middle text-right" rowspan="2">누적투입금액<br>(A)</th>
              <th class="border p-2 align-middle text-right" rowspan="2">누적기성금액<br>(B)</th>
              <th class="border p-2 align-middle text-right" rowspan="2">A/B</th>
              <th class="border p-2 align-middle" rowspan="2">비고</th>
            </tr>
            <tr class="bg-[#efd6c2] text-gray-900">
              <th class="border p-2 text-right">누적투입금액</th>
              <th class="border p-2 text-right">월투입금액</th>
              <th class="border p-2 text-right">합계</th>
              <th class="border p-2 text-right">노무비</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($summaryRows as $row): ?>
              <?php $eq = isset($row['equipment']) && is_array($row['equipment']) ? $row['equipment'] : array(); ?>
              <tr class="odd:bg-white even:bg-gray-50">
                <td class="border p-2 font-bold text-gray-900"><?php echo h($row['project_name']); ?></td>
                <td class="border p-2 text-right"><?php echo h(cpms_monthly_summary_money($row['contract_amount'])); ?></td>
                <td class="border p-2 text-right"><?php echo h(cpms_monthly_summary_money($row['previous_input'])); ?></td>
                <td class="border p-2 text-right"><?php echo h(cpms_monthly_summary_money($row['month_input'])); ?></td>
                <td class="border p-2 text-right font-bold"><?php echo h(cpms_monthly_summary_money($row['total_input'])); ?></td>
                <td class="border p-2 text-right"><?php echo h(cpms_monthly_summary_money($row['labor_amount'])); ?></td>
                <td class="border p-2 text-right"><?php echo h(cpms_monthly_summary_money($row['current_revenue'])); ?></td>
                <td class="border p-2 text-right"><?php echo h(cpms_monthly_summary_count($row['worker_output_days'])); ?></td>
                <td class="border p-2 text-right"><?php echo h(cpms_monthly_summary_count(isset($eq['excavator']) ? $eq['excavator'] : 0)); ?></td>
                <td class="border p-2 text-right"><?php echo h(cpms_monthly_summary_count(isset($eq['dump']) ? $eq['dump'] : 0)); ?></td>
                <td class="border p-2 text-right"><?php echo h(cpms_monthly_summary_count(isset($eq['other']) ? $eq['other'] : 0)); ?></td>
                <td class="border p-2 text-right"><?php echo h(cpms_monthly_summary_count(isset($eq['forklift']) ? $eq['forklift'] : 0)); ?></td>
                <td class="border p-2 text-right font-bold"><?php echo h(cpms_monthly_summary_money($row['cumulative_input'])); ?></td>
                <td class="border p-2 text-right font-bold"><?php echo h(cpms_monthly_summary_money($row['cumulative_revenue'])); ?></td>
                <td class="border p-2 text-right"><?php echo h(cpms_monthly_summary_ratio($row['cumulative_input'], $row['cumulative_revenue'])); ?></td>
                <td class="border p-2">
                  <input type="text" name="remarks[<?php echo (int)$row['project_id']; ?>]" value="<?php echo h($row['remark']); ?>" class="w-full min-w-[180px] px-2 py-1 border rounded-lg">
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <?php $eqTotal = isset($summaryTotals['equipment']) && is_array($summaryTotals['equipment']) ? $summaryTotals['equipment'] : array(); ?>
            <tr class="bg-gray-100 font-extrabold text-gray-900">
              <td class="border p-2">합계</td>
              <td class="border p-2 text-right"><?php echo h(cpms_monthly_summary_money($summaryTotals['contract_amount'])); ?></td>
              <td class="border p-2 text-right"><?php echo h(cpms_monthly_summary_money($summaryTotals['previous_input'])); ?></td>
              <td class="border p-2 text-right"><?php echo h(cpms_monthly_summary_money($summaryTotals['month_input'])); ?></td>
              <td class="border p-2 text-right"><?php echo h(cpms_monthly_summary_money($summaryTotals['total_input'])); ?></td>
              <td class="border p-2 text-right"><?php echo h(cpms_monthly_summary_money($summaryTotals['labor_amount'])); ?></td>
              <td class="border p-2 text-right"><?php echo h(cpms_monthly_summary_money($summaryTotals['current_revenue'])); ?></td>
              <td class="border p-2 text-right"><?php echo h(cpms_monthly_summary_count($summaryTotals['worker_output_days'])); ?></td>
              <td class="border p-2 text-right"><?php echo h(cpms_monthly_summary_count(isset($eqTotal['excavator']) ? $eqTotal['excavator'] : 0)); ?></td>
              <td class="border p-2 text-right"><?php echo h(cpms_monthly_summary_count(isset($eqTotal['dump']) ? $eqTotal['dump'] : 0)); ?></td>
              <td class="border p-2 text-right"><?php echo h(cpms_monthly_summary_count(isset($eqTotal['other']) ? $eqTotal['other'] : 0)); ?></td>
              <td class="border p-2 text-right"><?php echo h(cpms_monthly_summary_count(isset($eqTotal['forklift']) ? $eqTotal['forklift'] : 0)); ?></td>
              <td class="border p-2 text-right"><?php echo h(cpms_monthly_summary_money($summaryTotals['cumulative_input'])); ?></td>
              <td class="border p-2 text-right"><?php echo h(cpms_monthly_summary_money($summaryTotals['cumulative_revenue'])); ?></td>
              <td class="border p-2 text-right"><?php echo h(cpms_monthly_summary_ratio($summaryTotals['cumulative_input'], $summaryTotals['cumulative_revenue'])); ?></td>
              <td class="border p-2 text-center">-</td>
            </tr>
          </tfoot>
        </table>
      </div>
      <div class="mt-3 flex justify-end">
        <button type="submit" class="px-4 py-2 rounded-xl bg-gray-900 text-white font-bold">비고 저장</button>
      </div>
    </form>
  <?php endif; ?>
</div>
