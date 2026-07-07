<?php
/**
 * Construction daily calendar status.
 * PHP 5.6 compatible.
 */

use App\Core\Db;

require_once __DIR__ . '/../partials/schedule_auto_progress_helper.php';
require_once __DIR__ . '/partials/labor_data_loader.php';

$projectId = isset($pid) ? (int)$pid : 0;
$pdo = isset($pdo) ? $pdo : Db::pdo();
if (!$pdo || $projectId <= 0) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">일별 현황을 불러올 수 없습니다.</div>';
    return;
}

cpms_schedule_apply_auto_progress($pdo, $projectId);

if (!function_exists('cpms_daily_status_table_exists')) {
function cpms_daily_status_table_exists($pdo, $table) {
    if (!$pdo) return false;
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE :tbl");
        $st->bindValue(':tbl', (string)$table);
        $st->execute();
        return $st->fetch() ? true : false;
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_daily_status_column_exists')) {
function cpms_daily_status_column_exists($pdo, $table, $column) {
    if (!$pdo) return false;
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `" . $table . "` LIKE :col");
        $st->bindValue(':col', (string)$column);
        $st->execute();
        return $st->fetch() ? true : false;
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_daily_status_money')) {
function cpms_daily_status_money($value) {
    $value = (float)$value;
    if (abs($value) < 0.0001) return '0';
    return number_format($value, 0);
}}

if (!function_exists('cpms_daily_status_qty')) {
function cpms_daily_status_qty($value) {
    if ($value === null || $value === '') return '';
    $num = (float)$value;
    if (abs($num - round($num)) < 0.0001) return (string)(int)round($num);
    $text = number_format($num, 4, '.', '');
    return rtrim(rtrim($text, '0'), '.');
}}

if (!function_exists('cpms_daily_status_worker_key')) {
function cpms_daily_status_worker_key($name) {
    $name = trim((string)$name);
    if ($name === '') return '';
    if (function_exists('cpms_normalize_worker_key')) {
        return cpms_normalize_worker_key($name);
    }
    return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
}}

if (!function_exists('cpms_daily_status_unit_price')) {
function cpms_daily_status_unit_price($row) {
    $unitPrice = (isset($row['unit_price']) && is_numeric((string)$row['unit_price'])) ? (float)$row['unit_price'] : 0.0;
    if (abs($unitPrice) > 0.0001) return $unitPrice;
    $material = (isset($row['material_unit_price']) && is_numeric((string)$row['material_unit_price'])) ? (float)$row['material_unit_price'] : 0.0;
    $labor = (isset($row['labor_unit_price']) && is_numeric((string)$row['labor_unit_price'])) ? (float)$row['labor_unit_price'] : 0.0;
    $expense = (isset($row['expense_unit_price']) && is_numeric((string)$row['expense_unit_price'])) ? (float)$row['expense_unit_price'] : 0.0;
    return $material + $labor + $expense;
}}

if (!function_exists('cpms_daily_status_add_sales')) {
function cpms_daily_status_add_sales(&$days, $date, $taskName, $itemName, $doneQty, $unitPrice, $amount, $basis, $remainQty) {
    if (!isset($days[$date])) return;
    $days[$date]['sales'] += (float)$amount;
    $days[$date]['sales_details'][] = array(
        'task_name' => (string)$taskName,
        'item_name' => (string)$itemName,
        'done_qty' => cpms_daily_status_qty($doneQty),
        'remain_qty' => ($remainQty === null ? '' : cpms_daily_status_qty($remainQty)),
        'unit_price' => (float)$unitPrice,
        'amount' => (float)$amount,
        'basis' => (string)$basis
    );
}}

if (!function_exists('cpms_daily_status_add_cost_detail')) {
function cpms_daily_status_add_cost_detail(&$days, $date, $type, $label, $amount, $memo) {
    if (!isset($days[$date])) return;
    $days[$date][$type] += (float)$amount;
    $days[$date]['cost_details'][] = array(
        'type' => (string)$type,
        'label' => (string)$label,
        'amount' => (float)$amount,
        'memo' => (string)$memo
    );
}}

$projectStart = isset($projectRow['start_date']) ? trim((string)$projectRow['start_date']) : '';
$projectEnd = isset($projectRow['end_date']) ? trim((string)$projectRow['end_date']) : '';
$selectedMonth = isset($_GET['month']) ? trim((string)$_GET['month']) : '';
$dailyStatusTodayYmd = function_exists('cpms_schedule_auto_today') ? cpms_schedule_auto_today() : date('Y-m-d');
$todayMonth = substr($dailyStatusTodayYmd, 0, 7);
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = $todayMonth;
    if ($projectStart !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $projectStart) && $projectEnd !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $projectEnd)) {
        if ($todayMonth < substr($projectStart, 0, 7) || $todayMonth > substr($projectEnd, 0, 7)) {
            $selectedMonth = substr($projectStart, 0, 7);
        }
    }
}

$monthOptions = array();
try {
    $startYm = ($projectStart !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $projectStart)) ? substr($projectStart, 0, 7) : $selectedMonth;
    $endYm = ($projectEnd !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $projectEnd)) ? substr($projectEnd, 0, 7) : $selectedMonth;
    if ($endYm < $startYm) { $tmpYm = $startYm; $startYm = $endYm; $endYm = $tmpYm; }
    $cur = new DateTime($startYm . '-01');
    $endObj = new DateTime($endYm . '-01');
    while ($cur <= $endObj) {
        $monthOptions[] = $cur->format('Y-m');
        $cur->modify('+1 month');
    }
} catch (Exception $e) {
    $monthOptions = array($selectedMonth);
}
if (!in_array($selectedMonth, $monthOptions, true)) {
    $monthOptions[] = $selectedMonth;
    sort($monthOptions);
}

$monthStart = $selectedMonth . '-01';
$monthStartTs = strtotime($monthStart . ' 00:00:00');
if ($monthStartTs === false || $monthStartTs <= 0) {
    $selectedMonth = date('Y-m');
    $monthStart = $selectedMonth . '-01';
    $monthStartTs = strtotime($monthStart . ' 00:00:00');
}
$monthEnd = date('Y-m-t', $monthStartTs);
$daysInMonth = (int)date('t', $monthStartTs);
$firstWeekday = (int)date('w', $monthStartTs);
$todayYmd = $dailyStatusTodayYmd;

$days = array();
for ($d = 1; $d <= $daysInMonth; $d++) {
    $date = $selectedMonth . '-' . str_pad((string)$d, 2, '0', STR_PAD_LEFT);
    $days[$date] = array(
        'date' => $date,
        'sales' => 0.0,
        'material' => 0.0,
        'equipment' => 0.0,
        'labor' => 0.0,
        'total_input' => 0.0,
        'diff' => 0.0,
        'sales_details' => array(),
        'cost_details' => array()
    );
}

$hasMaterialUnitPrice = cpms_daily_status_column_exists($pdo, 'cpms_project_unit_prices', 'material_unit_price');
$hasLaborUnitPrice = cpms_daily_status_column_exists($pdo, 'cpms_project_unit_prices', 'labor_unit_price');
$hasExpenseUnitPrice = cpms_daily_status_column_exists($pdo, 'cpms_project_unit_prices', 'expense_unit_price');

$itemProgressKeys = array();
$itemCumulativeByDate = array();
$itemTotalQtyByKey = array();
if (cpms_daily_status_table_exists($pdo, 'cpms_schedule_task_item_progress')) {
    try {
        $stCum = $pdo->prepare("SELECT task_id, unit_price_id, work_date, total_qty, done_qty FROM cpms_schedule_task_item_progress WHERE project_id=:pid AND work_date <= :e ORDER BY task_id ASC, unit_price_id ASC, work_date ASC");
        $stCum->execute(array(':pid'=>$projectId, ':e'=>$monthEnd));
        $cumRows = $stCum->fetchAll(PDO::FETCH_ASSOC);
        $runningMap = array();
        if (is_array($cumRows)) {
            foreach ($cumRows as $cumRow) {
                $cumTaskId = isset($cumRow['task_id']) ? (int)$cumRow['task_id'] : 0;
                $cumUnitId = isset($cumRow['unit_price_id']) ? (int)$cumRow['unit_price_id'] : 0;
                $cumDate = isset($cumRow['work_date']) ? (string)$cumRow['work_date'] : '';
                if ($cumTaskId <= 0 || $cumUnitId <= 0 || $cumDate === '') continue;
                $cumKey = $cumTaskId . '|' . $cumUnitId;
                if (!isset($runningMap[$cumKey])) $runningMap[$cumKey] = 0.0;
                $cumDone = isset($cumRow['done_qty']) && is_numeric((string)$cumRow['done_qty']) ? (float)$cumRow['done_qty'] : 0.0;
                $runningMap[$cumKey] += $cumDone;
                if (!isset($itemCumulativeByDate[$cumKey])) $itemCumulativeByDate[$cumKey] = array();
                $itemCumulativeByDate[$cumKey][$cumDate] = $runningMap[$cumKey];
                $cumTotal = isset($cumRow['total_qty']) && is_numeric((string)$cumRow['total_qty']) ? (float)$cumRow['total_qty'] : 0.0;
                if ($cumTotal > 0) $itemTotalQtyByKey[$cumKey] = $cumTotal;
            }
        }

        $select = "p.task_id, p.unit_price_id, p.work_date, p.total_qty, p.done_qty, p.is_auto, p.is_manual, st.name AS task_name, u.item_name, u.unit, u.unit_price";
        $select .= $hasMaterialUnitPrice ? ", u.material_unit_price" : ", NULL AS material_unit_price";
        $select .= $hasLaborUnitPrice ? ", u.labor_unit_price" : ", NULL AS labor_unit_price";
        $select .= $hasExpenseUnitPrice ? ", u.expense_unit_price" : ", NULL AS expense_unit_price";
        $sql = "SELECT " . $select . "
                FROM cpms_schedule_task_item_progress p
                INNER JOIN cpms_schedule_tasks st ON st.id=p.task_id AND st.project_id=p.project_id
                INNER JOIN cpms_project_unit_prices u ON u.id=p.unit_price_id AND u.project_id=p.project_id
                WHERE p.project_id=:pid AND p.work_date BETWEEN :s AND :e AND COALESCE(p.done_qty,0) <> 0
                ORDER BY p.work_date ASC, st.sort_order ASC, st.id ASC, u.id ASC";
        $st = $pdo->prepare($sql);
        $st->execute(array(':pid'=>$projectId, ':s'=>$monthStart, ':e'=>$monthEnd));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $date = isset($row['work_date']) ? (string)$row['work_date'] : '';
                $taskId = isset($row['task_id']) ? (int)$row['task_id'] : 0;
                if ($date === '' || !isset($days[$date]) || $taskId <= 0) continue;
                $unitPriceId = isset($row['unit_price_id']) ? (int)$row['unit_price_id'] : 0;
                $doneQty = isset($row['done_qty']) ? (float)$row['done_qty'] : 0.0;
                $unitPrice = cpms_daily_status_unit_price($row);
                $amount = $doneQty * $unitPrice;
                $basis = ((isset($row['is_manual']) && (int)$row['is_manual'] === 1) ? '수동수정' : ((isset($row['is_auto']) && (int)$row['is_auto'] === 1) ? '자동분배' : '저장값'));
                $itemProgressKeys[$taskId . '|' . $date] = true;
                $itemKey = $taskId . '|' . $unitPriceId;
                $totalQty = isset($row['total_qty']) && is_numeric((string)$row['total_qty']) ? (float)$row['total_qty'] : 0.0;
                if ($totalQty <= 0 && isset($itemTotalQtyByKey[$itemKey])) $totalQty = (float)$itemTotalQtyByKey[$itemKey];
                $doneToDate = (isset($itemCumulativeByDate[$itemKey]) && isset($itemCumulativeByDate[$itemKey][$date])) ? (float)$itemCumulativeByDate[$itemKey][$date] : $doneQty;
                $remainQty = null;
                if ($totalQty > 0) {
                    $remainQty = $totalQty - $doneToDate;
                    if ($remainQty < 0) $remainQty = 0;
                }
                cpms_daily_status_add_sales($days, $date, isset($row['task_name']) ? $row['task_name'] : '', isset($row['item_name']) ? $row['item_name'] : '', $doneQty, $unitPrice, $amount, $basis, $remainQty);
            }
        }
    } catch (Exception $e) {}
}

if (cpms_daily_status_table_exists($pdo, 'cpms_schedule_progress') && cpms_daily_status_table_exists($pdo, 'cpms_work_item_lines')) {
    try {
        $lineSelect = "wil.work_id, wil.unit_price_id, CASE WHEN wil.planned_qty IS NULL OR wil.planned_qty = '' THEN COALESCE(u.qty,0) ELSE wil.planned_qty END AS line_qty, u.item_name, u.unit, u.unit_price";
        $lineSelect .= $hasMaterialUnitPrice ? ", u.material_unit_price" : ", NULL AS material_unit_price";
        $lineSelect .= $hasLaborUnitPrice ? ", u.labor_unit_price" : ", NULL AS labor_unit_price";
        $lineSelect .= $hasExpenseUnitPrice ? ", u.expense_unit_price" : ", NULL AS expense_unit_price";
        $lineSql = "SELECT " . $lineSelect . "
                    FROM cpms_work_item_lines wil
                    INNER JOIN cpms_project_unit_prices u ON u.id=wil.unit_price_id
                    WHERE u.project_id=:pid
                    ORDER BY wil.work_id ASC, u.id ASC";
        $stLines = $pdo->prepare($lineSql);
        $stLines->execute(array(':pid'=>$projectId));
        $lineRows = $stLines->fetchAll(PDO::FETCH_ASSOC);
        $linesByWork = array();
        if (is_array($lineRows)) {
            foreach ($lineRows as $line) {
                $workId = isset($line['work_id']) ? (int)$line['work_id'] : 0;
                if ($workId <= 0) continue;
                if (!isset($linesByWork[$workId])) $linesByWork[$workId] = array();
                $linesByWork[$workId][] = $line;
            }
        }

        $taskCumulativeByDate = array();
        $stTaskCum = $pdo->prepare("SELECT task_id, work_date, done_qty FROM cpms_schedule_progress WHERE project_id=:pid AND work_date <= :e ORDER BY task_id ASC, work_date ASC");
        $stTaskCum->execute(array(':pid'=>$projectId, ':e'=>$monthEnd));
        $taskCumRows = $stTaskCum->fetchAll(PDO::FETCH_ASSOC);
        $taskRunningMap = array();
        if (is_array($taskCumRows)) {
            foreach ($taskCumRows as $taskCumRow) {
                $cumTaskId = isset($taskCumRow['task_id']) ? (int)$taskCumRow['task_id'] : 0;
                $cumDate = isset($taskCumRow['work_date']) ? (string)$taskCumRow['work_date'] : '';
                if ($cumTaskId <= 0 || $cumDate === '') continue;
                if (!isset($taskRunningMap[$cumTaskId])) $taskRunningMap[$cumTaskId] = 0.0;
                $taskRunningMap[$cumTaskId] += isset($taskCumRow['done_qty']) && is_numeric((string)$taskCumRow['done_qty']) ? (float)$taskCumRow['done_qty'] : 0.0;
                if (!isset($taskCumulativeByDate[$cumTaskId])) $taskCumulativeByDate[$cumTaskId] = array();
                $taskCumulativeByDate[$cumTaskId][$cumDate] = $taskRunningMap[$cumTaskId];
            }
        }

        $sql = "SELECT p.task_id, p.work_date, p.done_qty, p.is_auto, p.is_manual, st.name AS task_name, st.work_id
                FROM cpms_schedule_progress p
                INNER JOIN cpms_schedule_tasks st ON st.id=p.task_id AND st.project_id=p.project_id
                WHERE p.project_id=:pid AND p.work_date BETWEEN :s AND :e AND COALESCE(p.done_qty,0) <> 0 AND st.work_id IS NOT NULL AND st.work_id > 0
                ORDER BY p.work_date ASC, st.sort_order ASC, st.id ASC";
        $stProg = $pdo->prepare($sql);
        $stProg->execute(array(':pid'=>$projectId, ':s'=>$monthStart, ':e'=>$monthEnd));
        $progRows = $stProg->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($progRows)) {
            foreach ($progRows as $row) {
                $date = isset($row['work_date']) ? (string)$row['work_date'] : '';
                $taskId = isset($row['task_id']) ? (int)$row['task_id'] : 0;
                $workId = isset($row['work_id']) ? (int)$row['work_id'] : 0;
                if ($date === '' || !isset($days[$date]) || $taskId <= 0 || $workId <= 0) continue;
                if (isset($itemProgressKeys[$taskId . '|' . $date])) continue;
                if (!isset($linesByWork[$workId]) || !is_array($linesByWork[$workId])) continue;
                $taskDone = isset($row['done_qty']) ? (float)$row['done_qty'] : 0.0;
                if ($taskDone <= 0) continue;
                $totalLineQty = 0.0;
                $taskDoneToDate = (isset($taskCumulativeByDate[$taskId]) && isset($taskCumulativeByDate[$taskId][$date])) ? (float)$taskCumulativeByDate[$taskId][$date] : $taskDone;
                foreach ($linesByWork[$workId] as $line) {
                    $lineQty = isset($line['line_qty']) ? (float)$line['line_qty'] : 0.0;
                    if ($lineQty > 0) $totalLineQty += $lineQty;
                }
                if ($totalLineQty <= 0) continue;
                $basis = ((isset($row['is_manual']) && (int)$row['is_manual'] === 1) ? 'task 수동값 배분' : 'task 자동값 배분');
                foreach ($linesByWork[$workId] as $line) {
                    $lineQty = isset($line['line_qty']) ? (float)$line['line_qty'] : 0.0;
                    if ($lineQty <= 0) continue;
                    $ratio = $lineQty / $totalLineQty;
                    $doneQty = round($taskDone * $ratio, 4);
                    if ($doneQty <= 0) continue;
                    $lineDoneToDate = round($taskDoneToDate * $ratio, 4);
                    $remainQty = $lineQty - $lineDoneToDate;
                    if ($remainQty < 0) $remainQty = 0;
                    $unitPrice = cpms_daily_status_unit_price($line);
                    $amount = $doneQty * $unitPrice;
                    cpms_daily_status_add_sales($days, $date, isset($row['task_name']) ? $row['task_name'] : '', isset($line['item_name']) ? $line['item_name'] : '', $doneQty, $unitPrice, $amount, $basis, $remainQty);
                }
            }
        }
    } catch (Exception $e) {}
}

if (cpms_daily_status_table_exists($pdo, 'cpms_material_usage')) {
    try {
        $hasAdvance = cpms_daily_status_column_exists($pdo, 'cpms_material_usage', 'advance_yn');
        $sql = "SELECT u.use_date, u.amount, u.memo, i.category, i.vendor_name";
        $sql .= $hasAdvance ? ", u.advance_yn" : ", 'N' AS advance_yn";
        $sql .= " FROM cpms_material_usage u LEFT JOIN cpms_material_items i ON i.id=u.material_id WHERE u.project_id=:pid AND u.use_date BETWEEN :s AND :e ORDER BY u.use_date ASC, u.id ASC";
        $st = $pdo->prepare($sql);
        $st->execute(array(':pid'=>$projectId, ':s'=>$monthStart, ':e'=>$monthEnd));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $date = isset($row['use_date']) ? (string)$row['use_date'] : '';
                if ($date === '' || !isset($days[$date])) continue;
                $amount = isset($row['amount']) ? (float)$row['amount'] : 0.0;
                $label = trim((string)(isset($row['category']) ? $row['category'] : '자재'));
                $vendor = trim((string)(isset($row['vendor_name']) ? $row['vendor_name'] : ''));
                if ($vendor !== '') $label .= ' / ' . $vendor;
                if (isset($row['advance_yn']) && strtoupper((string)$row['advance_yn']) === 'Y') $label .= ' / 선급';
                cpms_daily_status_add_cost_detail($days, $date, 'material', $label, $amount, isset($row['memo']) ? $row['memo'] : '');
            }
        }
    } catch (Exception $e) {}
}

if (cpms_daily_status_table_exists($pdo, 'cpms_equipment_usage')) {
    try {
        $hasWorkUnit = cpms_daily_status_column_exists($pdo, 'cpms_equipment_usage', 'work_unit');
        $hasBaseRateSnapshot = cpms_daily_status_column_exists($pdo, 'cpms_equipment_usage', 'base_rate_snapshot');
        $sql = "SELECT u.use_date, u.amount, u.memo, i.category, i.vendor_name, i.spec, i.base_rate";
        $sql .= $hasWorkUnit ? ", u.work_unit" : ", 1 AS work_unit";
        $sql .= $hasBaseRateSnapshot ? ", u.base_rate_snapshot" : ", 0 AS base_rate_snapshot";
        $sql .= " FROM cpms_equipment_usage u LEFT JOIN cpms_equipment_items i ON i.id=u.equipment_id WHERE u.project_id=:pid AND u.use_date BETWEEN :s AND :e ORDER BY u.use_date ASC, u.id ASC";
        $st = $pdo->prepare($sql);
        $st->execute(array(':pid'=>$projectId, ':s'=>$monthStart, ':e'=>$monthEnd));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $date = isset($row['use_date']) ? (string)$row['use_date'] : '';
                if ($date === '' || !isset($days[$date])) continue;
                $stored = isset($row['amount']) ? (float)$row['amount'] : 0.0;
                $workUnit = isset($row['work_unit']) ? (float)$row['work_unit'] : 1.0;
                if ($workUnit <= 0) $workUnit = 1.0;
                $rate = isset($row['base_rate_snapshot']) ? (float)$row['base_rate_snapshot'] : 0.0;
                if ($rate <= 0 && isset($row['base_rate'])) $rate = (float)$row['base_rate'];
                $amount = (abs($stored) > 0.0001) ? $stored : ($workUnit * $rate);
                $label = trim((string)(isset($row['vendor_name']) ? $row['vendor_name'] : '장비'));
                $spec = trim((string)(isset($row['spec']) ? $row['spec'] : ''));
                if ($spec !== '') $label .= ' / ' . $spec;
                cpms_daily_status_add_cost_detail($days, $date, 'equipment', $label, $amount, isset($row['memo']) ? $row['memo'] : '');
            }
        }
    } catch (Exception $e) {}
}

try {
    $projectName = isset($projectRow['name']) ? (string)$projectRow['name'] : '';
    $gongsuData = cpms_load_gongsu_data($pdo, $projectName, $selectedMonth);
    $gongsuMap = isset($gongsuData['gongsu_map']) && is_array($gongsuData['gongsu_map']) ? $gongsuData['gongsu_map'] : array();
    $outputDays = isset($gongsuData['output_days']) && is_array($gongsuData['output_days']) ? $gongsuData['output_days'] : array();
    $gongsuUnit = isset($gongsuData['gongsu_unit']) && is_array($gongsuData['gongsu_unit']) ? $gongsuData['gongsu_unit'] : array();
    $overrideDataset = function_exists('cpms_apply_labor_overrides_to_dataset')
        ? cpms_apply_labor_overrides_to_dataset($gongsuMap, $outputDays, $gongsuUnit, $projectId, $selectedMonth)
        : array(
            'gongsu_map' => $gongsuMap,
            'output_days' => $outputDays,
            'gongsu_unit' => $gongsuUnit,
        );
    $gongsuMap = isset($overrideDataset['gongsu_map']) && is_array($overrideDataset['gongsu_map']) ? $overrideDataset['gongsu_map'] : array();

    $directTeamMembers = cpms_load_direct_team_members($pdo);
    $projectLaborWorkers = cpms_load_project_labor_workers($pdo, $projectId);
    $workerRows = cpms_build_project_worker_rows($projectLaborWorkers, $directTeamMembers);
    $timesheetWorkers = cpms_build_timesheet_workers($workerRows);
    $workerByKey = array();
    if (is_array($timesheetWorkers)) {
        foreach ($timesheetWorkers as $worker) {
            $workerName = isset($worker['name']) ? (string)$worker['name'] : '';
            $key = cpms_daily_status_worker_key($workerName);
            if ($key !== '') $workerByKey[$key] = $worker;
        }
    }
    $attendanceWorkers = isset($gongsuData['all_workers']) && is_array($gongsuData['all_workers']) ? $gongsuData['all_workers'] : (isset($gongsuData['workers']) && is_array($gongsuData['workers']) ? $gongsuData['workers'] : array());
    foreach ($attendanceWorkers as $worker) {
        $workerName = is_array($worker) ? (isset($worker['name']) ? (string)$worker['name'] : '') : (string)$worker;
        $key = cpms_daily_status_worker_key($workerName);
        if ($key !== '' && !isset($workerByKey[$key])) {
            $workerByKey[$key] = is_array($worker) ? $worker : array('name' => $workerName);
        }
    }

    foreach ($gongsuMap as $workerKey => $dailyMap) {
        if (!is_array($dailyMap)) continue;
        $worker = isset($workerByKey[$workerKey]) ? $workerByKey[$workerKey] : array('name' => $workerKey);
        $workerName = isset($worker['name']) ? (string)$worker['name'] : (string)$workerKey;
        $wageRate = function_exists('cpms_resolve_labor_wage_rate') ? (float)cpms_resolve_labor_wage_rate($worker) : 0.0;
        foreach ($dailyMap as $date => $gongsuValue) {
            if (!isset($days[$date]) || !is_numeric($gongsuValue)) continue;
            $gongsu = (float)$gongsuValue;
            if ($gongsu <= 0) continue;
            $amount = $gongsu * $wageRate;
            cpms_daily_status_add_cost_detail($days, $date, 'labor', $workerName . ' / ' . cpms_daily_status_qty($gongsu) . '공수', $amount, '임금단가 ' . cpms_daily_status_money($wageRate));
        }
    }
} catch (Exception $e) {}

foreach ($days as $date => $row) {
    $days[$date]['total_input'] = (float)$row['material'] + (float)$row['equipment'] + (float)$row['labor'];
    $days[$date]['diff'] = (float)$row['sales'] - (float)$days[$date]['total_input'];
}

$calendarData = array_values($days);
?>

<style>
.daily-status-calendar { display:grid; grid-template-columns:repeat(7,minmax(0,1fr)); gap:8px; }
.daily-status-day { min-height:168px; border:1px solid #e5e7eb; border-radius:8px; padding:10px; text-align:left; background:#fff; transition:box-shadow .15s ease, transform .15s ease; }
.daily-status-day:hover { box-shadow:0 10px 20px rgba(15,23,42,.08); transform:translateY(-1px); }
.daily-status-empty { background:#f8fafc; color:#94a3b8; }
.daily-status-today { border:2px solid #2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.12); }
.daily-status-plus { background:#f0fdf4; border-color:#bbf7d0; }
.daily-status-minus { background:#fff1f2; border-color:#fecdd3; }
.daily-status-muted { background:#f8fafc; }
@media (max-width: 900px) {
  .daily-status-calendar { grid-template-columns:repeat(2,minmax(0,1fr)); }
  .daily-status-day { min-height:150px; }
}
</style>

<div class="bg-white rounded-3xl border border-gray-200 p-6 shadow-sm">
    <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-3 mb-4">
        <div>
            <h3 class="text-xl font-extrabold text-gray-900">일별 현황</h3>
        </div>
        <form method="get" action="" class="flex items-end gap-2">
            <input type="hidden" name="r" value="공사">
            <input type="hidden" name="pid" value="<?php echo (int)$projectId; ?>">
            <input type="hidden" name="tab" value="daily_status">
            <div>
                <label class="text-xs font-bold text-gray-500">월 선택</label>
                <select name="month" class="mt-1 px-3 py-2 rounded-xl border border-gray-200 text-sm">
                    <?php foreach ($monthOptions as $ym): ?>
                        <option value="<?php echo h($ym); ?>" <?php echo ($ym === $selectedMonth) ? 'selected' : ''; ?>>
                            <?php echo h(substr($ym, 0, 4) . '년 ' . substr($ym, 5, 2) . '월'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="px-4 py-2 rounded-xl bg-gray-900 text-white text-sm font-extrabold">보기</button>
        </form>
    </div>

    <div class="daily-status-calendar text-xs">
        <?php $weekNames = array('일', '월', '화', '수', '목', '금', '토'); ?>
        <?php foreach ($weekNames as $weekName): ?>
            <div class="px-2 py-2 rounded-lg bg-gray-100 text-center font-extrabold text-gray-600"><?php echo h($weekName); ?></div>
        <?php endforeach; ?>
        <?php for ($blank = 0; $blank < $firstWeekday; $blank++): ?>
            <div class="daily-status-day daily-status-empty"></div>
        <?php endfor; ?>
        <?php foreach ($days as $date => $day): ?>
            <?php
            $dayNo = (int)substr($date, 8, 2);
            $weekLabel = $weekNames[(int)date('w', strtotime($date . ' 00:00:00'))];
            $hasMoney = (abs((float)$day['sales']) + abs((float)$day['total_input'])) > 0.0001;
            $diff = (float)$day['diff'];
            $cellClass = 'daily-status-day';
            if (!$hasMoney) $cellClass .= ' daily-status-muted';
            else if ($diff > 0) $cellClass .= ' daily-status-plus';
            else if ($diff < 0) $cellClass .= ' daily-status-minus';
            if ($date === $todayYmd) $cellClass .= ' daily-status-today';
            $diffText = ($diff > 0 ? '+' : '') . cpms_daily_status_money($diff);
            ?>
            <button type="button" class="<?php echo h($cellClass); ?>" data-daily-status-date="<?php echo h($date); ?>">
                <div class="flex items-center justify-between gap-2">
                    <div class="font-extrabold text-gray-900"><?php echo (int)$dayNo; ?>일 <?php echo h($weekLabel); ?></div>
                    <?php if ($date === $todayYmd): ?><span class="px-2 py-0.5 rounded-full bg-blue-600 text-white text-[10px] font-bold">오늘</span><?php endif; ?>
                </div>
                <?php if ($hasMoney): ?>
                    <div class="mt-3 space-y-1 text-[11px] leading-4">
                        <div class="flex justify-between gap-2"><span class="text-gray-500">예상매출</span><b><?php echo cpms_daily_status_money($day['sales']); ?></b></div>
                        <div class="flex justify-between gap-2"><span class="text-gray-500">자재</span><b><?php echo cpms_daily_status_money($day['material']); ?></b></div>
                        <div class="flex justify-between gap-2"><span class="text-gray-500">장비</span><b><?php echo cpms_daily_status_money($day['equipment']); ?></b></div>
                        <div class="flex justify-between gap-2"><span class="text-gray-500">노무</span><b><?php echo cpms_daily_status_money($day['labor']); ?></b></div>
                        <div class="flex justify-between gap-2 pt-1 border-t border-gray-200"><span class="text-gray-600">총투입</span><b><?php echo cpms_daily_status_money($day['total_input']); ?></b></div>
                        <div class="flex justify-between gap-2 <?php echo $diff >= 0 ? 'text-emerald-700' : 'text-rose-700'; ?>"><span>차액</span><b><?php echo h($diffText); ?></b></div>
                    </div>
                <?php else: ?>
                    <div class="mt-8 text-center text-gray-400 font-bold">금액 없음</div>
                <?php endif; ?>
            </button>
        <?php endforeach; ?>
    </div>
</div>

<div id="dailyStatusModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/40" data-daily-status-close></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
        <div class="w-full max-w-4xl bg-white rounded-3xl shadow-2xl border border-gray-100 overflow-hidden">
            <div class="p-5 border-b border-gray-100 flex items-center justify-between">
                <div>
                    <h3 class="text-xl font-extrabold text-gray-900" id="dailyStatusModalTitle">일별 현황</h3>
                    <div class="text-sm text-gray-500 mt-1" id="dailyStatusModalDiff"></div>
                </div>
                <button type="button" class="px-3 py-2 rounded-xl border border-gray-200 text-sm font-bold" data-daily-status-close>닫기</button>
            </div>
            <div class="p-5 space-y-5 max-h-[75vh] overflow-auto">
                <div>
                    <div class="text-sm font-extrabold text-gray-900 mb-2">예상매출</div>
                    <div class="overflow-auto border border-gray-200 rounded-2xl">
                        <table class="w-full text-xs border-collapse">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="p-2 border text-left">공정/작업명</th>
                                    <th class="p-2 border text-left">내역서 항목명</th>
                                    <th class="p-2 border text-right">완료수량</th>
                                    <th class="p-2 border text-right">남은수량</th>
                                    <th class="p-2 border text-right">단가</th>
                                    <th class="p-2 border text-right">금액</th>
                                    <th class="p-2 border text-center">기준</th>
                                </tr>
                            </thead>
                            <tbody id="dailyStatusSalesBody"></tbody>
                        </table>
                    </div>
                </div>
                <div>
                    <div class="text-sm font-extrabold text-gray-900 mb-2">투입비</div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-sm">
                        <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4"><div class="text-xs text-gray-500 font-bold">자재구입비 합계</div><div class="mt-1 text-lg font-extrabold" id="dailyStatusMaterialSum">0</div></div>
                        <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4"><div class="text-xs text-gray-500 font-bold">장비비 합계</div><div class="mt-1 text-lg font-extrabold" id="dailyStatusEquipmentSum">0</div></div>
                        <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4"><div class="text-xs text-gray-500 font-bold">노무비 합계</div><div class="mt-1 text-lg font-extrabold" id="dailyStatusLaborSum">0</div></div>
                    </div>
                    <div class="mt-3 text-xs text-gray-500" id="dailyStatusCostDetails"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    var rows = <?php echo json_encode($calendarData, JSON_UNESCAPED_UNICODE); ?>;
    var map = {};
    for (var i = 0; i < rows.length; i++) map[rows[i].date] = rows[i];
    var modal = document.getElementById('dailyStatusModal');
    function money(v){
        var n = parseFloat(v || 0);
        if (isNaN(n)) n = 0;
        var sign = n < 0 ? '-' : '';
        n = Math.abs(Math.round(n));
        return sign + String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }
    function esc(s){
        return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }
    function openModal(date){
        var data = map[date];
        if (!data || !modal) return;
        document.getElementById('dailyStatusModalTitle').textContent = date + ' 일별 현황';
        var diff = parseFloat(data.diff || 0);
        document.getElementById('dailyStatusModalDiff').textContent = '예상매출 ' + money(data.sales) + ' / 총투입 ' + money(data.total_input) + ' / 차액 ' + (diff > 0 ? '+' : '') + money(diff);
        document.getElementById('dailyStatusMaterialSum').textContent = money(data.material);
        document.getElementById('dailyStatusEquipmentSum').textContent = money(data.equipment);
        document.getElementById('dailyStatusLaborSum').textContent = money(data.labor);
        var body = document.getElementById('dailyStatusSalesBody');
        body.innerHTML = '';
        var sales = data.sales_details || [];
        if (!sales.length) {
            body.innerHTML = '<tr><td class="p-4 border text-center text-gray-500" colspan="7">예상매출 산출 내역이 없습니다.</td></tr>';
        } else {
            for (var i = 0; i < sales.length; i++) {
                var r = sales[i];
                body.innerHTML += '<tr>' +
                    '<td class="p-2 border">' + esc(r.task_name) + '</td>' +
                    '<td class="p-2 border">' + esc(r.item_name) + '</td>' +
                    '<td class="p-2 border text-right">' + esc(r.done_qty) + '</td>' +
                    '<td class="p-2 border text-right">' + esc(r.remain_qty) + '</td>' +
                    '<td class="p-2 border text-right">' + money(r.unit_price) + '</td>' +
                    '<td class="p-2 border text-right font-bold">' + money(r.amount) + '</td>' +
                    '<td class="p-2 border text-center">' + esc(r.basis) + '</td>' +
                    '</tr>';
            }
        }
        var detailEl = document.getElementById('dailyStatusCostDetails');
        var costs = data.cost_details || [];
        if (!costs.length) {
            detailEl.textContent = '투입비 상세 내역이 없습니다.';
        } else {
            var html = '<div class="rounded-2xl border border-gray-200 overflow-hidden"><table class="w-full border-collapse"><tbody>';
            for (var j = 0; j < costs.length; j++) {
                var c = costs[j];
                var typeLabel = c.type === 'material' ? '자재' : (c.type === 'equipment' ? '장비' : '노무');
                html += '<tr><td class="p-2 border text-gray-500">' + typeLabel + '</td><td class="p-2 border">' + esc(c.label) + '</td><td class="p-2 border text-right font-bold">' + money(c.amount) + '</td><td class="p-2 border text-gray-500">' + esc(c.memo) + '</td></tr>';
            }
            html += '</tbody></table></div>';
            detailEl.innerHTML = html;
        }
        modal.classList.remove('hidden');
    }
    function closeModal(){ if (modal) modal.classList.add('hidden'); }
    var buttons = document.querySelectorAll('[data-daily-status-date]');
    for (var i = 0; i < buttons.length; i++) {
        buttons[i].addEventListener('click', function(){ openModal(this.getAttribute('data-daily-status-date')); });
    }
    var closes = document.querySelectorAll('[data-daily-status-close]');
    for (var j = 0; j < closes.length; j++) closes[j].addEventListener('click', closeModal);
})();
</script>
