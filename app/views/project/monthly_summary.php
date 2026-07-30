<?php
use App\Core\Db;

require_once __DIR__ . '/../construction/tabs/partials/sales_data_loader.php';
require_once __DIR__ . '/../construction/tabs/partials/labor_data_loader.php';
require_once __DIR__ . '/../construction/tabs/partials/outsourcing_data_helper.php';
require_once __DIR__ . '/../safety/safety_cost_helper.php';
require_once __DIR__ . '/../../services/CostChangeService.php';

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

function cpms_monthly_summary_mobile_money_class($value) {
    $formatted = cpms_monthly_summary_money($value);
    $digits = preg_replace('/[^0-9]/', '', $formatted);
    $length = strlen((string)$digits);
    if ($length >= 12) return 'cpms-mobile-money cpms-mobile-money-xs';
    if ($length >= 9) return 'cpms-mobile-money cpms-mobile-money-sm';
    return 'cpms-mobile-money';
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
    $costChangeInstalled = $pdo ? \App\Services\CostChangeService::isInstalled($pdo) : false;

    if ($pdo && $projectId > 0) {
        if (cpms_monthly_summary_table_exists($pdo, 'cpms_material_items') && cpms_monthly_summary_table_exists($pdo, 'cpms_material_usage')) {
            try {
                $deletedWhere = cpms_monthly_summary_column_exists($pdo, 'cpms_material_items', 'is_deleted') ? ' AND (m.is_deleted = 0 OR m.is_deleted IS NULL)' : '';
                $materialSql = 'SELECT m.category, u.id AS usage_id, u.use_date, u.amount FROM cpms_material_items m INNER JOIN cpms_material_usage u ON u.material_id = m.id AND u.project_id = m.project_id WHERE m.project_id = :pid';
                if (!$costChangeInstalled) $materialSql .= ' AND u.use_date <= :cost_end';
                $materialSql .= $deletedWhere;
                $st = $pdo->prepare($materialSql);
                $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
                if (!$costChangeInstalled) $st->bindValue(':cost_end', $costEndDate);
                $st->execute();
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                if (is_array($rows)) {
                    foreach ($rows as $row) {
                        $category = isset($row['category']) ? trim((string)$row['category']) : '';
                        if ($category === '안전관리비') continue;
                        if ($costChangeInstalled && \App\Services\CostChangeService::isTargetDeleted($pdo, 'material', isset($row['usage_id']) ? (string)$row['usage_id'] : '')) continue;
                        $useDate = isset($row['use_date']) ? (string)$row['use_date'] : '';
                        $ym = $costChangeInstalled
                            ? \App\Services\CostChangeService::effectiveSettlementYm($pdo, 'material', isset($row['usage_id']) ? (string)$row['usage_id'] : '', 'material', $useDate)
                            : cpms_monthly_summary_cost_ym($useDate);
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
                    $ym = $costChangeInstalled
                        ? \App\Services\CostChangeService::effectiveSettlementYm($pdo, 'safety', isset($row['id']) ? (string)$row['id'] : '', 'safety', $useDate)
                        : \App\Services\CostChangeService::settlementYm('safety', $useDate);
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
                $equipmentSql = 'SELECT e.category, e.spec, e.base_rate, u.id AS usage_id, u.use_date, u.amount' . $extra . ' FROM cpms_equipment_items e INNER JOIN cpms_equipment_usage u ON u.equipment_id = e.id AND u.project_id = e.project_id WHERE e.project_id = :pid';
                if (!$costChangeInstalled) $equipmentSql .= ' AND u.use_date <= :cost_end';
                $equipmentSql .= $deletedWhere;
                $st = $pdo->prepare($equipmentSql);
                $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
                if (!$costChangeInstalled) $st->bindValue(':cost_end', $costEndDate);
                $st->execute();
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                if (is_array($rows)) {
                    foreach ($rows as $row) {
                        if ($costChangeInstalled && \App\Services\CostChangeService::isTargetDeleted($pdo, 'equipment', isset($row['usage_id']) ? (string)$row['usage_id'] : '')) continue;
                        $useDate = isset($row['use_date']) ? (string)$row['use_date'] : '';
                        $ym = $costChangeInstalled
                            ? \App\Services\CostChangeService::effectiveSettlementYm($pdo, 'equipment', isset($row['usage_id']) ? (string)$row['usage_id'] : '', 'equipment', $useDate)
                            : cpms_monthly_summary_cost_ym($useDate);
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
            $outsourcingPeriod = \App\Services\CostChangeService::periodForYm('outsourcing', $ym);
            $monthStart = $outsourcingPeriod['start'];
            $monthEnd = $outsourcingPeriod['end'];
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
    $equipmentAmount = isset($breakdown['equipment']) ? (float)$breakdown['equipment'] : 0.0;
    $materialPurchaseAmount = 0.0;
    $materialPurchaseAmount += isset($breakdown['material']) ? (float)$breakdown['material'] : 0.0;
    $materialPurchaseAmount += isset($breakdown['purchase']) ? (float)$breakdown['purchase'] : 0.0;
    $materialPurchaseAmount += isset($breakdown['other']) ? (float)$breakdown['other'] : 0.0;
    $laborAmount = isset($laborByMonth[$selectedYm]) ? (float)$laborByMonth[$selectedYm] : 0.0;
    $outsourcingAmount = isset($breakdown['outsourcing']) ? (float)$breakdown['outsourcing'] : 0.0;
    $safetyAmount = isset($breakdown['safety']) ? (float)$breakdown['safety'] : 0.0;
    $monthlyCostTotal = $laborAmount + $equipmentAmount + $materialPurchaseAmount + $outsourcingAmount + $safetyAmount;
    return array(
        'project_id' => $projectId,
        'project_name' => $projectName,
        'contract_amount' => isset($project['contract_amount']) ? (float)$project['contract_amount'] : 0.0,
        'previous_input' => $previousInput,
        'month_input' => $monthInput,
        'total_input' => $totalInput,
        'labor_amount' => $laborAmount,
        'equipment_amount' => $equipmentAmount,
        'material_purchase_amount' => $materialPurchaseAmount,
        'outsourcing_amount' => $outsourcingAmount,
        'monthly_cost_total' => $monthlyCostTotal,
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
    'equipment_amount' => 0.0,
    'material_purchase_amount' => 0.0,
    'outsourcing_amount' => 0.0,
    'monthly_cost_total' => 0.0,
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
    $summaryTotals['equipment_amount'] += isset($row['equipment_amount']) ? (float)$row['equipment_amount'] : 0.0;
    $summaryTotals['material_purchase_amount'] += isset($row['material_purchase_amount']) ? (float)$row['material_purchase_amount'] : 0.0;
    $summaryTotals['outsourcing_amount'] += isset($row['outsourcing_amount']) ? (float)$row['outsourcing_amount'] : 0.0;
    $summaryTotals['monthly_cost_total'] += isset($row['monthly_cost_total']) ? (float)$row['monthly_cost_total'] : 0.0;
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

// PDF 생성 서비스가 화면과 동일한 집계 데이터를 재사용할 때는
// 아래의 화면 HTML만 출력하지 않는다.
if (isset($cpmsMonthlySummaryDataOnly) && $cpmsMonthlySummaryDataOnly) {
    return;
}
?>
<div class="cpms-monthly-summary-shell bg-white rounded-3xl border border-gray-100 p-5">
  <div class="cpms-monthly-summary-toolbar flex flex-wrap items-center justify-between gap-3 mb-4">
    <div>
      <h3 class="text-xl font-extrabold text-gray-900">월별 투입비 집계</h3>
      <div class="text-sm text-gray-500 mt-1">
        <?php echo h(str_replace('-', '.', $selectedYm)); ?> 기준 · 오늘 날짜 <?php echo h(date('Y.m.d')); ?>
      </div>
    </div>
    <div class="flex flex-wrap items-center gap-2">
      <form method="get" class="cpms-monthly-filter flex flex-wrap items-center gap-2">
        <input type="hidden" name="r" value="공무">
        <input type="hidden" name="tab" value="monthly_summary">
        <select name="ym" class="px-3 py-2 border rounded-xl min-w-[150px]">
          <?php foreach ($monthOptions as $ymOpt): ?>
            <option value="<?php echo h($ymOpt); ?>" <?php echo $ymOpt === $selectedYm ? 'selected' : ''; ?>><?php echo h(str_replace('-', '.', $ymOpt)); ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="px-4 py-2 rounded-xl bg-blue-600 text-white font-bold">조회</button>
      </form>
      <?php
        $monthlySummaryPdfAllowed = \App\Core\Auth::isMaster()
          || \App\Core\Auth::userRole() === 'executive'
          || in_array((string)\App\Core\Auth::userDepartment(), array('공무', '공무부', '공무팀', '관리', '관리부', '관리팀'), true);
      ?>
      <?php if ($monthlySummaryPdfAllowed): ?>
        <form method="post"
              action="?r=project/monthly_summary_pdf_drive"
              onsubmit="var b=this.getElementsByTagName('button')[0];if(b){b.disabled=true;b.textContent='PDF 저장 중...';}">
          <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
          <input type="hidden" name="ym" value="<?php echo h($selectedYm); ?>">
          <button type="submit" class="px-4 py-2 rounded-xl bg-emerald-700 text-white font-bold hover:bg-emerald-800 disabled:opacity-60">
            PDF로 Google Drive 저장
          </button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if (isset($flash) && !empty($flash) && is_array($flash)): ?>
    <?php
      $monthlySummaryFlashType = isset($flash['type']) ? (string)$flash['type'] : 'info';
      $monthlySummaryFlashClass = 'border-blue-200 bg-blue-50 text-blue-800';
      if ($monthlySummaryFlashType === 'success') $monthlySummaryFlashClass = 'border-emerald-200 bg-emerald-50 text-emerald-800';
      if ($monthlySummaryFlashType === 'error' || $monthlySummaryFlashType === 'danger') $monthlySummaryFlashClass = 'border-red-200 bg-red-50 text-red-800';
      $monthlySummaryDriveResult = isset($_SESSION['_monthly_summary_drive_result']) && is_array($_SESSION['_monthly_summary_drive_result'])
        ? $_SESSION['_monthly_summary_drive_result']
        : array();
      unset($_SESSION['_monthly_summary_drive_result']);
    ?>
    <div class="cpms-monthly-summary-inset mb-3 rounded-xl border p-3 text-sm font-bold <?php echo h($monthlySummaryFlashClass); ?>">
      <div><?php echo h(isset($flash['message']) ? $flash['message'] : ''); ?></div>
      <?php if (!empty($monthlySummaryDriveResult['file_link'])): ?>
        <a href="<?php echo h($monthlySummaryDriveResult['file_link']); ?>" target="_blank" rel="noopener"
           class="inline-flex mt-2 mr-2 px-3 py-2 rounded-lg bg-emerald-700 text-white">저장된 PDF 바로 열기</a>
      <?php endif; ?>
      <?php if (!empty($monthlySummaryDriveResult['folder_link'])): ?>
        <a href="<?php echo h($monthlySummaryDriveResult['folder_link']); ?>" target="_blank" rel="noopener"
           class="inline-flex mt-2 px-3 py-2 rounded-lg border border-emerald-300 bg-white text-emerald-800">저장 폴더 열기</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (count($errors) > 0): ?>
    <div class="cpms-monthly-summary-inset mb-3 rounded-xl border border-red-200 bg-red-50 text-red-800 p-3 text-sm">
      <?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (count($summaryRows) === 0): ?>
    <div class="cpms-monthly-summary-inset text-sm text-gray-600">표시할 프로젝트가 없습니다.</div>
  <?php else: ?>
    <div class="cpms-public-affairs-mobile-table" aria-label="월별 투입비 모바일 집계표">
      <table>
        <colgroup>
          <col class="cpms-mobile-site-col">
          <col class="cpms-mobile-money-col">
          <col class="cpms-mobile-money-col">
          <col class="cpms-mobile-material-col">
          <col class="cpms-mobile-money-col">
          <col class="cpms-mobile-total-col">
          <col class="cpms-mobile-ratio-col">
        </colgroup>
        <thead>
          <tr class="bg-[#d7aa8a] text-gray-900">
            <th class="text-left" rowspan="2">현장명</th>
            <th class="text-center" colspan="5"><?php echo h($monthTitle); ?> 투입금액</th>
            <th class="text-right" rowspan="2">A/B</th>
          </tr>
          <tr class="bg-[#efd6c2] text-gray-900">
            <th class="text-right">노무비</th>
            <th class="text-right">장비비</th>
            <th class="text-right">자재구입비</th>
            <th class="text-right">외주비</th>
            <th class="text-right">합계</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($summaryRows as $row): ?>
            <tr class="odd:bg-white even:bg-gray-50">
              <td class="font-bold text-gray-900"><?php echo h($row['project_name']); ?></td>
              <td class="text-right <?php echo h(cpms_monthly_summary_mobile_money_class($row['labor_amount'])); ?>"><?php echo h(cpms_monthly_summary_money($row['labor_amount'])); ?></td>
              <td class="text-right <?php echo h(cpms_monthly_summary_mobile_money_class($row['equipment_amount'])); ?>"><?php echo h(cpms_monthly_summary_money($row['equipment_amount'])); ?></td>
              <td class="text-right <?php echo h(cpms_monthly_summary_mobile_money_class($row['material_purchase_amount'])); ?>"><?php echo h(cpms_monthly_summary_money($row['material_purchase_amount'])); ?></td>
              <td class="text-right <?php echo h(cpms_monthly_summary_mobile_money_class($row['outsourcing_amount'])); ?>"><?php echo h(cpms_monthly_summary_money($row['outsourcing_amount'])); ?></td>
              <td class="text-right font-bold <?php echo h(cpms_monthly_summary_mobile_money_class($row['monthly_cost_total'])); ?>"><?php echo h(cpms_monthly_summary_money($row['monthly_cost_total'])); ?></td>
              <td class="text-right"><?php echo h(cpms_monthly_summary_ratio($row['cumulative_input'], $row['cumulative_revenue'])); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="bg-gray-100 font-extrabold text-gray-900">
            <td>합계</td>
            <td class="text-right <?php echo h(cpms_monthly_summary_mobile_money_class($summaryTotals['labor_amount'])); ?>"><?php echo h(cpms_monthly_summary_money($summaryTotals['labor_amount'])); ?></td>
            <td class="text-right <?php echo h(cpms_monthly_summary_mobile_money_class($summaryTotals['equipment_amount'])); ?>"><?php echo h(cpms_monthly_summary_money($summaryTotals['equipment_amount'])); ?></td>
            <td class="text-right <?php echo h(cpms_monthly_summary_mobile_money_class($summaryTotals['material_purchase_amount'])); ?>"><?php echo h(cpms_monthly_summary_money($summaryTotals['material_purchase_amount'])); ?></td>
            <td class="text-right <?php echo h(cpms_monthly_summary_mobile_money_class($summaryTotals['outsourcing_amount'])); ?>"><?php echo h(cpms_monthly_summary_money($summaryTotals['outsourcing_amount'])); ?></td>
            <td class="text-right <?php echo h(cpms_monthly_summary_mobile_money_class($summaryTotals['monthly_cost_total'])); ?>"><?php echo h(cpms_monthly_summary_money($summaryTotals['monthly_cost_total'])); ?></td>
            <td class="text-right"><?php echo h(cpms_monthly_summary_ratio($summaryTotals['cumulative_input'], $summaryTotals['cumulative_revenue'])); ?></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <div class="cpms-monthly-summary-desktop">
      <div class="overflow-x-auto">
        <table class="min-w-[1050px] w-full text-xs border border-gray-200">
          <thead>
            <tr class="bg-[#d7aa8a] text-gray-900">
              <th class="border p-2 align-middle" rowspan="2">현장명</th>
              <th class="border p-2 text-center" colspan="5"><?php echo h($monthTitle); ?> 투입금액</th>
              <th class="border p-2 align-middle text-right" rowspan="2">누적투입금액<br>(A)</th>
              <th class="border p-2 align-middle text-right" rowspan="2">누적기성금액<br>(B)</th>
              <th class="border p-2 align-middle text-right" rowspan="2">A/B</th>
            </tr>
            <tr class="bg-[#efd6c2] text-gray-900">
              <th class="border p-2 text-right">노무비</th>
              <th class="border p-2 text-right">장비비</th>
              <th class="border p-2 text-right">자재구입비</th>
              <th class="border p-2 text-right">외주비</th>
              <th class="border p-2 text-right">합계</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($summaryRows as $row): ?>
              <tr class="odd:bg-white even:bg-gray-50">
                <td class="border p-2 font-bold text-gray-900"><?php echo h($row['project_name']); ?></td>
                <td class="border p-2 text-right"><?php echo h(cpms_monthly_summary_money($row['labor_amount'])); ?></td>
                <td class="border p-2 text-right"><?php echo h(cpms_monthly_summary_money($row['equipment_amount'])); ?></td>
                <td class="border p-2 text-right"><?php echo h(cpms_monthly_summary_money($row['material_purchase_amount'])); ?></td>
                <td class="border p-2 text-right"><?php echo h(cpms_monthly_summary_money($row['outsourcing_amount'])); ?></td>
                <td class="border p-2 text-right font-bold"><?php echo h(cpms_monthly_summary_money($row['monthly_cost_total'])); ?></td>
                <td class="border p-2 text-right font-bold"><?php echo h(cpms_monthly_summary_money($row['cumulative_input'])); ?></td>
                <td class="border p-2 text-right font-bold"><?php echo h(cpms_monthly_summary_money($row['cumulative_revenue'])); ?></td>
                <td class="border p-2 text-right"><?php echo h(cpms_monthly_summary_ratio($row['cumulative_input'], $row['cumulative_revenue'])); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr class="bg-gray-100 font-extrabold text-gray-900">
              <td class="border p-2">합계</td>
              <td class="border p-2 text-right"><?php echo h(cpms_monthly_summary_money($summaryTotals['labor_amount'])); ?></td>
              <td class="border p-2 text-right"><?php echo h(cpms_monthly_summary_money($summaryTotals['equipment_amount'])); ?></td>
              <td class="border p-2 text-right"><?php echo h(cpms_monthly_summary_money($summaryTotals['material_purchase_amount'])); ?></td>
              <td class="border p-2 text-right"><?php echo h(cpms_monthly_summary_money($summaryTotals['outsourcing_amount'])); ?></td>
              <td class="border p-2 text-right"><?php echo h(cpms_monthly_summary_money($summaryTotals['monthly_cost_total'])); ?></td>
              <td class="border p-2 text-right"><?php echo h(cpms_monthly_summary_money($summaryTotals['cumulative_input'])); ?></td>
              <td class="border p-2 text-right"><?php echo h(cpms_monthly_summary_money($summaryTotals['cumulative_revenue'])); ?></td>
              <td class="border p-2 text-right"><?php echo h(cpms_monthly_summary_ratio($summaryTotals['cumulative_input'], $summaryTotals['cumulative_revenue'])); ?></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  <?php endif; ?>
</div>
