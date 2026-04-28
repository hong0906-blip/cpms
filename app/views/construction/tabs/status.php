<?php
/**
 * 공사 > 상황 탭(연도별 월/분기 비용+매출 그래프)
 * - 연도 선택 + 월별/분기별 5항목(노무/장비/안전/자재/매출) 막대그래프
 * - 회사 월 기준: 전월 25일 ~ 현월 24일
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/partials/labor_data_loader.php';

if (!function_exists('cpms_status_table_exists')) {
    function cpms_status_table_exists($pdo, $table) {
        if (!$pdo) return false;
        try {
            $dbName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
            if ($dbName === '') return false;
            $sql = "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl";
            $st = $pdo->prepare($sql);
            $st->bindValue(':db', $dbName);
            $st->bindValue(':tbl', (string)$table);
            $st->execute();
            return ((int)$st->fetchColumn() > 0);
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('cpms_status_column_exists')) {
    function cpms_status_column_exists($pdo, $table, $column) {
        if (!$pdo) return false;
        try {
            $dbName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
            if ($dbName === '') return false;
            $sql = "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl AND COLUMN_NAME = :col";
            $st = $pdo->prepare($sql);
            $st->bindValue(':db', $dbName);
            $st->bindValue(':tbl', (string)$table);
            $st->bindValue(':col', (string)$column);
            $st->execute();
            return ((int)$st->fetchColumn() > 0);
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('cpms_status_sum_between')) {
    function cpms_status_sum_between($pdo, $table, $dateColumn, $projectId, $startDate, $endDate, $extraWhere, $extraParams) {
        if (!$pdo) return 0;
        if (!cpms_status_table_exists($pdo, $table)) return 0;
        if (!cpms_status_column_exists($pdo, $table, 'project_id')) return 0;
        if (!cpms_status_column_exists($pdo, $table, 'amount')) return 0;
        if (!cpms_status_column_exists($pdo, $table, $dateColumn)) return 0;

        try {
            $sql = "SELECT COALESCE(SUM(amount), 0) FROM `" . $table . "` WHERE project_id = :pid AND `" . $dateColumn . "` BETWEEN :start AND :end";
            if ($extraWhere !== '') {
                $sql .= ' ' . $extraWhere;
            }
            $st = $pdo->prepare($sql);
            $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
            $st->bindValue(':start', (string)$startDate);
            $st->bindValue(':end', (string)$endDate);

            if (is_array($extraParams)) {
                foreach ($extraParams as $k => $v) {
                    $st->bindValue($k, $v);
                }
            }

            $st->execute();
            return (float)$st->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }
}

if (!function_exists('cpms_status_sum_all')) {
    function cpms_status_sum_all($pdo, $table, $projectId, $extraWhere, $extraParams) {
        if (!$pdo) return 0;
        if (!cpms_status_table_exists($pdo, $table)) return 0;
        if (!cpms_status_column_exists($pdo, $table, 'project_id')) return 0;
        if (!cpms_status_column_exists($pdo, $table, 'amount')) return 0;

        try {
            $sql = "SELECT COALESCE(SUM(amount), 0) FROM `" . $table . "` WHERE project_id = :pid";
            if ($extraWhere !== '') {
                $sql .= ' ' . $extraWhere;
            }
            $st = $pdo->prepare($sql);
            $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);

            if (is_array($extraParams)) {
                foreach ($extraParams as $k => $v) {
                    $st->bindValue($k, $v);
                }
            }

            $st->execute();
            return (float)$st->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }
}

if (!function_exists('cpms_status_money')) {
    function cpms_status_money($amount) {
        return number_format((float)$amount) . '원';
    }
}

if (!function_exists('cpms_status_parse_money')) {
    function cpms_status_parse_money($value) {
        $value = trim((string)$value);
        if ($value === '') return 0.0;
        $value = str_replace(',', '', $value);
        if (!is_numeric($value)) return 0.0;
        return (float)$value;
    }
}

if (!function_exists('cpms_status_normalize_unit')) {
    function cpms_status_normalize_unit($unit) {
        $unit = trim((string)$unit);
        if ($unit === '') return '';
        $unit = preg_replace('/\s+/u', '', $unit);
        $unit = str_replace('.', '', $unit);
        $unit = strtoupper($unit);
        return trim((string)$unit);
    }
}

if (!function_exists('cpms_status_is_no_multiply_unit')) {
    function cpms_status_is_no_multiply_unit($unit) {
        static $noMultiplyUnits = null;
        if ($noMultiplyUnits === null) {
            $noMultiplyUnits = array('EA' => true, 'SET' => true, '조' => true, '본' => true);
        }
        $normalized = cpms_status_normalize_unit($unit);
        return isset($noMultiplyUnits[$normalized]);
    }
}

if (!function_exists('cpms_status_apply_overrides')) {
    function cpms_status_apply_overrides($map, $projectId, $month) {
        $rows = cpms_load_labor_overrides((int)$projectId, (string)$month);
        if (!is_array($rows)) return $map;

        foreach ($rows as $workerKey => $dateRows) {
            if (!isset($map[$workerKey]) || !is_array($map[$workerKey])) $map[$workerKey] = array();
            if (!is_array($dateRows)) continue;
            foreach ($dateRows as $dateKey => $entry) {
                if (is_array($entry) && isset($entry['value']) && is_numeric($entry['value'])) {
                    $map[$workerKey][$dateKey] = (float)$entry['value'];
                }
            }
        }

        return $map;
    }
}

if (!function_exists('cpms_status_labor_wage_map')) {
    function cpms_status_labor_wage_map($pdo, $projectId) {
        $wageMap = array();
        if (!$pdo || $projectId <= 0) return $wageMap;

        $directTeamMembers = cpms_load_direct_team_members($pdo);
        $projectWorkers = cpms_load_project_labor_workers($pdo, (int)$projectId);
        $workerRows = cpms_build_project_worker_rows($projectWorkers, $directTeamMembers);
        $timesheetWorkers = cpms_build_timesheet_workers($workerRows);

        foreach ($timesheetWorkers as $worker) {
            $name = isset($worker['name']) ? (string)$worker['name'] : '';
            $key = cpms_normalize_worker_key($name);
            if ($key === '') continue;
            $wageRateRaw = isset($worker['deposit_rate']) ? (string)$worker['deposit_rate'] : '';
            $wageMap[$key] = cpms_status_parse_money($wageRateRaw);
        }

        return $wageMap;
    }
}

if (!function_exists('cpms_status_labor_total_between')) {
    function cpms_status_labor_total_between($pdo, $projectId, $projectName, $startDate, $endDate, $laborWageMap) {
        if (!$pdo || $projectId <= 0) return 0.0;
        $projectName = trim((string)$projectName);
        if ($projectName === '') return 0.0;

        try {
            $startObj = new DateTime((string)$startDate);
            $endObj = new DateTime((string)$endDate);
        } catch (Exception $e) {
            return 0.0;
        }

        if ($startObj > $endObj) return 0.0;

        $months = array();
        $cursor = clone $startObj;
        while ($cursor <= $endObj) {
            $months[$cursor->format('Y-m')] = true;
            $cursor->modify('+1 day');
        }

        $sumGongsu = array();
        $outputDaysSet = array();

        foreach ($months as $ym => $unused) {
            $gongsuData = cpms_load_gongsu_data($pdo, $projectName, $ym);
            $gongsuMap = isset($gongsuData['gongsu_map']) && is_array($gongsuData['gongsu_map']) ? $gongsuData['gongsu_map'] : array();
            $gongsuMap = cpms_status_apply_overrides($gongsuMap, (int)$projectId, $ym);

            foreach ($gongsuMap as $workerKey => $dailyMap) {
                if (!is_array($dailyMap)) continue;
                if (!isset($sumGongsu[$workerKey])) $sumGongsu[$workerKey] = 0.0;
                if (!isset($outputDaysSet[$workerKey])) $outputDaysSet[$workerKey] = array();

                foreach ($dailyMap as $dateKey => $gongsuValue) {
                    if (!is_numeric($gongsuValue)) continue;
                    if ((string)$dateKey < (string)$startDate || (string)$dateKey > (string)$endDate) continue;
                    $sumGongsu[$workerKey] += (float)$gongsuValue;
                    $outputDaysSet[$workerKey][$dateKey] = true;
                }
            }
        }

        $totalLabor = 0.0;
        foreach ($sumGongsu as $workerKey => $workerSumGongsu) {
            $outputDays = isset($outputDaysSet[$workerKey]) && is_array($outputDaysSet[$workerKey]) ? count($outputDaysSet[$workerKey]) : 0;
            if ($outputDays <= 0) continue;

            $gongsuUnit = ((float)$workerSumGongsu) / $outputDays;
            $wageRate = isset($laborWageMap[$workerKey]) ? (float)$laborWageMap[$workerKey] : 0.0;

            // 상황탭 노무비=지급총액 합 (지급총액 = 공수단위 * 임금단가 * 출력일수)
            $totalLabor += ((float)$gongsuUnit) * $wageRate * $outputDays;
        }

        return (float)$totalLabor;
    }
}

if (!function_exists('cpms_status_sales_total_between')) {
    function cpms_status_sales_total_between($pdo, $projectId, $startDate, $endDate) {
        if (!$pdo || $projectId <= 0) return 0.0;
        if (!cpms_status_table_exists($pdo, 'cpms_schedule_tasks')) return 0.0;
        if (!cpms_status_table_exists($pdo, 'cpms_work_item_lines')) return 0.0;
        if (!cpms_status_table_exists($pdo, 'cpms_project_unit_prices')) return 0.0;

        $requiredTaskCols = array('project_id', 'work_id', 'end_date', 'progress');
        foreach ($requiredTaskCols as $col) {
            if (!cpms_status_column_exists($pdo, 'cpms_schedule_tasks', $col)) return 0.0;
        }
        $requiredLineCols = array('work_id', 'unit_price_id');
        foreach ($requiredLineCols as $col) {
            if (!cpms_status_column_exists($pdo, 'cpms_work_item_lines', $col)) return 0.0;
        }
        if (!cpms_status_column_exists($pdo, 'cpms_project_unit_prices', 'id')) return 0.0;
        if (!cpms_status_column_exists($pdo, 'cpms_project_unit_prices', 'unit_price')) return 0.0;

        $hasLinePlannedQty = cpms_status_column_exists($pdo, 'cpms_work_item_lines', 'planned_qty');
        $hasUnitQty = cpms_status_column_exists($pdo, 'cpms_project_unit_prices', 'qty');
        $hasUnitCol = cpms_status_column_exists($pdo, 'cpms_project_unit_prices', 'unit');
        $hasItemNameCol = cpms_status_column_exists($pdo, 'cpms_project_unit_prices', 'item_name');

        try {
            $today = date('Y-m-d');
            $lineSelect = "
                st.id AS task_id,
                pup.unit_price AS unit_price
            ";
            if ($hasLinePlannedQty) {
                $lineSelect .= ", wil.planned_qty AS planned_qty";
            } else {
                $lineSelect .= ", NULL AS planned_qty";
            }
            if ($hasUnitQty) {
                $lineSelect .= ", pup.qty AS contract_qty";
            } else {
                $lineSelect .= ", NULL AS contract_qty";
            }
            if ($hasUnitCol) {
                $lineSelect .= ", pup.unit AS unit";
            } else {
                $lineSelect .= ", '' AS unit";
            }
            if ($hasItemNameCol) {
                $lineSelect .= ", pup.item_name AS item_name";
            } else {
                $lineSelect .= ", '' AS item_name";
            }      
                  
            $sql = "
                SELECT " . $lineSelect . "
                FROM cpms_schedule_tasks st
                LEFT JOIN cpms_work_item_lines wil ON wil.work_id = st.work_id
                LEFT JOIN cpms_project_unit_prices pup ON pup.id = wil.unit_price_id
                WHERE st.project_id = :pid
                  AND st.end_date IS NOT NULL
                  AND st.end_date <> ''
                  AND st.end_date BETWEEN :start AND :end
                  AND (
                    COALESCE(st.progress, 0) >= 100
                    OR (
                        st.end_date < :today
                        AND (st.progress IS NULL OR st.progress = 0)
                    )
                  )
            ";

            $st = $pdo->prepare($sql);
            $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
            $st->bindValue(':start', (string)$startDate);
            $st->bindValue(':end', (string)$endDate);
            $st->bindValue(':today', (string)$today);
            $st->execute();

            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows) || count($rows) === 0) return 0.0;

            $totalSales = 0.0;
            $workAmountByTask = array();
            foreach ($rows as $row) {
                $taskId = isset($row['task_id']) ? (int)$row['task_id'] : 0;
                if ($taskId <= 0) continue;

                $unitPrice = isset($row['unit_price']) && is_numeric((string)$row['unit_price']) ? (float)$row['unit_price'] : 0.0;
                if (!isset($workAmountByTask[$taskId])) $workAmountByTask[$taskId] = 0.0;
                if (!array_key_exists('unit_price', $row) || $row['unit_price'] === null) continue;

                $plannedQtyRaw = isset($row['planned_qty']) ? $row['planned_qty'] : null;
                $contractQtyRaw = isset($row['contract_qty']) ? $row['contract_qty'] : null;

                // 매출 단위별 곱하기 규칙(EA/SET/조/본 제외): planned_qty 우선, 없으면 계약수량 사용
                if ($plannedQtyRaw !== null && trim((string)$plannedQtyRaw) !== '') {
                    $qtyUsed = is_numeric((string)$plannedQtyRaw) ? (float)$plannedQtyRaw : 0.0;
                } else {
                    $qtyUsed = is_numeric((string)$contractQtyRaw) ? (float)$contractQtyRaw : 0.0;
                }

                $unitRaw = isset($row['unit']) ? (string)$row['unit'] : '';
                $lineAmount = cpms_status_is_no_multiply_unit($unitRaw)
                    ? $unitPrice
                    : ($qtyUsed * $unitPrice);

                $workAmountByTask[$taskId] += $lineAmount;

                // 매출 단위별 곱하기 규칙(EA/SET/조/본 제외) 디버그(임시):
                // 관리자 전용 출력이 필요하면 아래 로그 배열을 화면/로그로 노출해 확인하세요.
                // $debugSalesLines[] = array(
                //     'task_id' => $taskId,
                //     'item_name' => isset($row['item_name']) ? (string)$row['item_name'] : '',
                //     'unit' => $unitRaw,
                //     'qty_used' => $qtyUsed,
                //     'unit_price' => $unitPrice,
                //     'line_amount' => $lineAmount,
                // );
            }

            foreach ($workAmountByTask as $taskWorkAmount) {
                $totalSales += (float)$taskWorkAmount;
            }

            return (float)$totalSales;
        } catch (Exception $e) {
            return 0.0;
        }
    }
}

if (!function_exists('cpms_status_sales_total_all')) {
    function cpms_status_sales_total_all($pdo, $projectId) {
        if (!$pdo || $projectId <= 0) return 0.0;

        try {
            return cpms_status_sales_total_between($pdo, $projectId, '1900-01-01', '2999-12-31');
        } catch (Exception $e) {
            return 0.0;
        }
    }
}

$projectStartDate = isset($projectRow['start_date']) ? (string)$projectRow['start_date'] : date('Y-m-d');
$projectEndDate = isset($projectRow['end_date']) ? (string)$projectRow['end_date'] : date('Y-m-d');
$projectName = isset($projectRow['name']) ? (string)$projectRow['name'] : '';

$startYear = (int)date('Y');
$endYear = (int)date('Y');
try {
    $startYear = (int)(new DateTime($projectStartDate))->format('Y');
    $endYear = (int)(new DateTime($projectEndDate))->format('Y');
} catch (Exception $e) {
    $startYear = (int)date('Y');
    $endYear = (int)date('Y');
}
if ($startYear > $endYear) {
    $tmp = $startYear;
    $startYear = $endYear;
    $endYear = $tmp;
}

$years = array();
for ($y = $startYear; $y <= $endYear; $y++) {
    $years[] = $y;
}
if (count($years) === 0) {
    $years[] = (int)date('Y');
}

$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
if (!in_array($selectedYear, $years, true)) {
    $selectedYear = (int)date('Y');
    if (!in_array($selectedYear, $years, true)) {
        $selectedYear = $years[count($years) - 1];
    }
}

// 상황탭 매출 추가/색상변경/상단금액구조 변경
$categories = array(
    'labor' => array('label' => '노무비', 'color' => '#FACC15'),
    'equipment' => array('label' => '장비비', 'color' => '#EF4444'),
    'safety' => array('label' => '안전관리비', 'color' => '#22C55E'),
    'materials' => array('label' => '자재구입비', 'color' => '#A855F7'),
    'sales' => array('label' => '매출', 'color' => '#3B82F6'),    
);

$laborWageMap = cpms_status_labor_wage_map($pdo, (int)$pid);

$monthlyData = array();
$yearTotals = array('labor' => 0, 'equipment' => 0, 'safety' => 0, 'materials' => 0, 'sales' => 0);
$maxMonthlyValue = 0;

for ($m = 1; $m <= 12; $m++) {
    $monthObj = DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-01', $selectedYear, $m));
    if (!$monthObj) continue;

    $rangeStartObj = clone $monthObj;
    $rangeStartObj->modify('-1 month');
    $rangeStartObj->setDate((int)$rangeStartObj->format('Y'), (int)$rangeStartObj->format('m'), 25);

    $rangeEndObj = clone $monthObj;
    $rangeEndObj->setDate((int)$rangeEndObj->format('Y'), (int)$rangeEndObj->format('m'), 24);

    $rangeStart = $rangeStartObj->format('Y-m-d');
    $rangeEnd = $rangeEndObj->format('Y-m-d');

    $equipment = cpms_status_sum_between($pdo, 'cpms_equipment_usage', 'use_date', $pid, $rangeStart, $rangeEnd, '', array());
    $materials = cpms_status_sum_between($pdo, 'cpms_material_usage', 'use_date', $pid, $rangeStart, $rangeEnd, '', array());
    $safety = cpms_status_sum_between(
        $pdo,
        'cpms_daily_cost_entries',
        'cost_date',
        $pid,
        $rangeStart,
        $rangeEnd,
        "AND cost_type IN ('안전','안전관리비')",
        array()
    );

    // 상황탭 노무비=지급총액 합
    $labor = cpms_status_labor_total_between($pdo, (int)$pid, $projectName, $rangeStart, $rangeEnd, $laborWageMap);

    // 상황탭 매출 추가/색상변경/상단금액구조 변경: 완료 공정 기준 매출 인식
    $sales = cpms_status_sales_total_between($pdo, (int)$pid, $rangeStart, $rangeEnd);

    $row = array(
        'month' => $m,
        'label' => $m . '월',
        'start' => $rangeStart,
        'end' => $rangeEnd,
        'labor' => $labor,
        'materials' => $materials,
        'safety' => $safety,
        'materials' => $materials,
        'sales' => $sales,        
    );

    foreach ($yearTotals as $key => $sumValue) {
        $yearTotals[$key] += isset($row[$key]) ? (float)$row[$key] : 0;
        if (isset($row[$key]) && (float)$row[$key] > $maxMonthlyValue) {
            $maxMonthlyValue = (float)$row[$key];
        }
    }

    $monthlyData[] = $row;
}

$quarterlyData = array();
$maxQuarterValue = 0;
for ($q = 1; $q <= 4; $q++) {
    $startMonth = (($q - 1) * 3) + 1;
    $endMonth = $startMonth + 2;

    $qRow = array(
        'quarter' => $q,
        'label' => $q . 'Q',
        'labor' => 0,
        'equipment' => 0,
        'safety' => 0,
        'materials' => 0,
        'sales' => 0,        
    );

    foreach ($monthlyData as $mRow) {
        $mm = isset($mRow['month']) ? (int)$mRow['month'] : 0;
        if ($mm < $startMonth || $mm > $endMonth) continue;

        foreach ($yearTotals as $key => $ignored) {
            $qRow[$key] += isset($mRow[$key]) ? (float)$mRow[$key] : 0;
        }
    }

    foreach ($yearTotals as $key => $ignored) {
        if ((float)$qRow[$key] > $maxQuarterValue) {
            $maxQuarterValue = (float)$qRow[$key];
        }
    }

    $quarterlyData[] = $qRow;
}

// 상황탭 매출 추가/색상변경/상단금액구조 변경: 상단 전체 누적 금액(연도 변경과 무관)
$overallTotals = array('labor' => 0, 'equipment' => 0, 'safety' => 0, 'materials' => 0, 'sales' => 0);
foreach ($years as $yy) {
    for ($m = 1; $m <= 12; $m++) {
        $monthObj = DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-01', (int)$yy, $m));
        if (!$monthObj) continue;

        $rangeStartObj = clone $monthObj;
        $rangeStartObj->modify('-1 month');
        $rangeStartObj->setDate((int)$rangeStartObj->format('Y'), (int)$rangeStartObj->format('m'), 25);

        $rangeEndObj = clone $monthObj;
        $rangeEndObj->setDate((int)$rangeEndObj->format('Y'), (int)$rangeEndObj->format('m'), 24);

        $rangeStart = $rangeStartObj->format('Y-m-d');
        $rangeEnd = $rangeEndObj->format('Y-m-d');

        $overallTotals['equipment'] += cpms_status_sum_between($pdo, 'cpms_equipment_usage', 'use_date', $pid, $rangeStart, $rangeEnd, '', array());
        $overallTotals['materials'] += cpms_status_sum_between($pdo, 'cpms_material_usage', 'use_date', $pid, $rangeStart, $rangeEnd, '', array());
        $overallTotals['safety'] += cpms_status_sum_between(
            $pdo,
            'cpms_daily_cost_entries',
            'cost_date',
            $pid,
            $rangeStart,
            $rangeEnd,
            "AND cost_type IN ('안전','안전관리비')",
            array()
        );
        $overallTotals['labor'] += cpms_status_labor_total_between($pdo, (int)$pid, $projectName, $rangeStart, $rangeEnd, $laborWageMap);
    }
}
$overallTotals['sales'] = cpms_status_sales_total_all($pdo, (int)$pid);
$overallUsageTotal = $overallTotals['labor'] + $overallTotals['equipment'] + $overallTotals['safety'] + $overallTotals['materials'];
$overallNetTotal = $overallTotals['sales'] - $overallUsageTotal;

if ($maxMonthlyValue <= 0) $maxMonthlyValue = 1;
if ($maxQuarterValue <= 0) $maxQuarterValue = 1;
?>

<style>
.cpms-status-wrap .card { border:1px solid #e5e7eb; border-radius:16px; background:#fff; }
.cpms-status-wrap .summary-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
.cpms-status-wrap .chart-wrap { border:1px solid #e5e7eb; border-radius:16px; padding:16px; background:#fff; }
.cpms-status-wrap .chart-scroll { overflow-x:auto; }
.cpms-status-wrap .chart-row { min-width:900px; height:280px; display:flex; align-items:flex-end; gap:12px; padding:8px 4px 0 4px; border-bottom:1px solid #e5e7eb; }
.cpms-status-wrap .group { flex:1; min-width:55px; display:flex; flex-direction:column; align-items:center; }
.cpms-status-wrap .bars { width:100%; height:230px; display:flex; align-items:flex-end; justify-content:center; gap:4px; }
.cpms-status-wrap .bar { width:18%; min-width:9px; border-radius:6px 6px 0 0; position:relative; }
.cpms-status-wrap .bar .value { position:absolute; top:-20px; left:50%; transform:translateX(-50%); font-size:10px; color:#374151; white-space:nowrap; }
.cpms-status-wrap .xlabel { margin-top:8px; font-size:12px; color:#4b5563; font-weight:700; }
.cpms-status-wrap .legend { display:flex; flex-wrap:wrap; gap:10px; margin-top:10px; }
.cpms-status-wrap .legend-item { display:flex; align-items:center; gap:6px; font-size:12px; color:#374151; }
.cpms-status-wrap .dot { width:12px; height:12px; border-radius:3px; display:inline-block; }
@media (max-width: 980px) {
    .cpms-status-wrap .summary-grid { grid-template-columns:repeat(1,minmax(0,1fr)); }
}
</style>

<div class="cpms-status-wrap space-y-4">
    <div class="card p-5">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h3 class="text-xl font-extrabold text-gray-900">상황</h3>
                <div class="text-sm text-gray-600 mt-1">연도별 월/분기 비용/매출 현황(회사 월 기준: 전월 25일~현월 24일)</div>
            </div>
        </div>

        <!-- 상황탭 매출 추가/색상변경/상단금액구조 변경 -->        
        <div class="mt-4 p-4 rounded-2xl bg-gray-900 text-white">
            <div class="text-sm text-gray-200">총 매출금액 (전체 누적, 순이익)</div>
            <div class="text-3xl font-extrabold mt-1"><?php echo h(cpms_status_money($overallNetTotal)); ?></div>
        </div>

        <div class="summary-grid mt-3">
            <div class="p-3 rounded-xl" style="border:1px solid #e5e7eb;">
                <div class="text-xs text-gray-500">순수 총매출금액 (전체 누적)</div>
                <div class="text-lg font-extrabold text-gray-900"><?php echo h(cpms_status_money($overallTotals['sales'])); ?></div>
            </div>
            <div class="p-3 rounded-xl" style="border:1px solid #e5e7eb;">
                <div class="text-xs text-gray-500">총사용금액 (전체 누적)</div>
                <div class="text-lg font-extrabold text-gray-900"><?php echo h(cpms_status_money($overallUsageTotal)); ?></div>
            </div>
        </div>
    </div>

    <div class="flex justify-end">
        <form method="get" action="" class="flex flex-wrap items-end gap-2">
            <input type="hidden" name="r" value="공사">
            <input type="hidden" name="pid" value="<?php echo (int)$pid; ?>">
            <input type="hidden" name="tab" value="status">
            <label class="text-sm font-bold text-gray-700">연도</label>
            <select name="year" class="px-3 py-2 rounded-xl border border-gray-300" onchange="this.form.submit()">
                <?php foreach ($years as $yy): ?>
                    <option value="<?php echo (int)$yy; ?>" <?php echo ($selectedYear === (int)$yy) ? 'selected' : ''; ?>>
                        <?php echo (int)$yy; ?>년
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <div class="chart-wrap">
        <div class="flex items-center justify-between">
            <h4 class="text-lg font-extrabold text-gray-900">월별 비용/매출 그래프</h4>
            <div class="text-xs text-gray-500">기준: 전월 25일 ~ 현월 24일</div>
        </div>

        <div class="chart-scroll">
            <div class="chart-row">
                <?php foreach ($monthlyData as $row): ?>
                    <div class="group">
                        <div class="bars">
                            <?php foreach ($categories as $key => $meta): ?>
                                <?php
                                $amount = isset($row[$key]) ? (float)$row[$key] : 0;
                                $height = ($amount <= 0) ? 2 : max(2, ($amount / $maxMonthlyValue) * 100);
                                $title = $row['label'] . ' ' . $meta['label'] . ': ' . cpms_status_money($amount) . ' (' . $row['start'] . ' ~ ' . $row['end'] . ')';
                                ?>
                                <div class="bar" title="<?php echo h($title); ?>" style="height:<?php echo round($height, 2); ?>%; background:<?php echo h($meta['color']); ?>;">
                                    <span class="value"><?php echo h(number_format($amount)); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="xlabel"><?php echo h($row['label']); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="legend">
            <?php foreach ($categories as $meta): ?>
                <span class="legend-item"><span class="dot" style="background:<?php echo h($meta['color']); ?>;"></span><?php echo h($meta['label']); ?></span>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="chart-wrap">
        <h4 class="text-lg font-extrabold text-gray-900">분기별 비용/매출 그래프</h4>
        <div class="chart-scroll">
            <div class="chart-row" style="min-width:520px;">
                <?php foreach ($quarterlyData as $row): ?>
                    <div class="group">
                        <div class="bars">
                            <?php foreach ($categories as $key => $meta): ?>
                                <?php
                                $amount = isset($row[$key]) ? (float)$row[$key] : 0;
                                $height = ($amount <= 0) ? 2 : max(2, ($amount / $maxQuarterValue) * 100);
                                $title = $row['label'] . ' ' . $meta['label'] . ': ' . cpms_status_money($amount);
                                ?>
                                <div class="bar" title="<?php echo h($title); ?>" style="height:<?php echo round($height, 2); ?>%; background:<?php echo h($meta['color']); ?>;">
                                    <span class="value"><?php echo h(number_format($amount)); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="xlabel"><?php echo h($row['label']); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="legend">
            <?php foreach ($categories as $meta): ?>
                <span class="legend-item"><span class="dot" style="background:<?php echo h($meta['color']); ?>;"></span><?php echo h($meta['label']); ?></span>
            <?php endforeach; ?>
        </div>
    </div>
</div>