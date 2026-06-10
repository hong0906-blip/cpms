<?php
/**
 * Dashboard project cost summary helper.
 * PHP 5.6 compatible.
 */

require_once dirname(dirname(__DIR__)) . '/construction/tabs/partials/sales_data_loader.php';
require_once dirname(dirname(__DIR__)) . '/construction/tabs/partials/labor_data_loader.php';

if (!function_exists('cpms_dashboard_table_exists')) {
function cpms_dashboard_table_exists($pdo, $table)
{
    if (!$pdo || trim((string)$table) === '') return false;
    try {
        $dbName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
        if ($dbName === '') return false;
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl");
        $st->bindValue(':db', $dbName);
        $st->bindValue(':tbl', (string)$table);
        $st->execute();
        return ((int)$st->fetchColumn() > 0);
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_dashboard_column_exists')) {
function cpms_dashboard_column_exists($pdo, $table, $column)
{
    if (!$pdo || trim((string)$table) === '' || trim((string)$column) === '') return false;
    try {
        $dbName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
        if ($dbName === '') return false;
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl AND COLUMN_NAME = :col");
        $st->bindValue(':db', $dbName);
        $st->bindValue(':tbl', (string)$table);
        $st->bindValue(':col', (string)$column);
        $st->execute();
        return ((int)$st->fetchColumn() > 0);
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_dashboard_money')) {
function cpms_dashboard_money($amount)
{
    return number_format((float)$amount) . '원';
}}

if (!function_exists('cpms_dashboard_parse_money')) {
function cpms_dashboard_parse_money($value)
{
    $value = trim((string)$value);
    if ($value === '') return 0.0;
    $value = str_replace(',', '', $value);
    if (!is_numeric($value)) return 0.0;
    return (float)$value;
}}

if (!function_exists('cpms_dashboard_project_rows')) {
function cpms_dashboard_project_rows($pdo)
{
    $rows = array();
    if (!$pdo || !cpms_dashboard_table_exists($pdo, 'cpms_projects')) return $rows;

    $select = array('id');
    $select[count($select)] = cpms_dashboard_column_exists($pdo, 'cpms_projects', 'name') ? 'name' : "'' AS name";
    $select[count($select)] = cpms_dashboard_column_exists($pdo, 'cpms_projects', 'start_date') ? 'start_date' : "NULL AS start_date";
    $select[count($select)] = cpms_dashboard_column_exists($pdo, 'cpms_projects', 'end_date') ? 'end_date' : "NULL AS end_date";
    $select[count($select)] = cpms_dashboard_column_exists($pdo, 'cpms_projects', 'status') ? 'status' : "'' AS status";

    $where = array('1=1');
    if (cpms_dashboard_column_exists($pdo, 'cpms_projects', 'is_deleted')) {
        $where[count($where)] = '(is_deleted = 0 OR is_deleted IS NULL)';
    }
    if (cpms_dashboard_column_exists($pdo, 'cpms_projects', 'status')) {
        $where[count($where)] = "(status IS NULL OR status = '' OR status NOT IN ('종료','취소'))";
    }

    try {
        $sql = 'SELECT ' . implode(', ', $select) . ' FROM cpms_projects WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC';
        $st = $pdo->query($sql);
        $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
        if (!is_array($rows)) $rows = array();
    } catch (Exception $e) {
        $rows = array();
    }

    return $rows;
}}

if (!function_exists('cpms_dashboard_equipment_total')) {
function cpms_dashboard_equipment_total($pdo, $projectId)
{
    if (!$pdo || $projectId <= 0) return 0.0;
    if (!cpms_dashboard_table_exists($pdo, 'cpms_equipment_usage')) return 0.0;
    if (!cpms_dashboard_column_exists($pdo, 'cpms_equipment_usage', 'project_id')) return 0.0;

    $hasWorkUnit = cpms_dashboard_column_exists($pdo, 'cpms_equipment_usage', 'work_unit');
    $hasBaseRate = cpms_dashboard_column_exists($pdo, 'cpms_equipment_usage', 'base_rate_snapshot');
    $hasAmount = cpms_dashboard_column_exists($pdo, 'cpms_equipment_usage', 'amount');
    if (!$hasAmount && (!$hasWorkUnit || !$hasBaseRate)) return 0.0;

    try {
        if ($hasWorkUnit && $hasBaseRate) {
            $amountExpr = "COALESCE(NULLIF(work_unit, 0), 1) * COALESCE(NULLIF(base_rate_snapshot, 0)" . ($hasAmount ? ", amount" : "") . ", 0)";
            $sql = "SELECT COALESCE(SUM(" . $amountExpr . "), 0) FROM cpms_equipment_usage WHERE project_id = :pid";
        } else {
            $sql = "SELECT COALESCE(SUM(amount), 0) FROM cpms_equipment_usage WHERE project_id = :pid";
        }
        $st = $pdo->prepare($sql);
        $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
        $st->execute();
        return (float)$st->fetchColumn();
    } catch (Exception $e) {
        return 0.0;
    }
}}

if (!function_exists('cpms_dashboard_material_total')) {
function cpms_dashboard_material_total($pdo, $projectId)
{
    if (!$pdo || $projectId <= 0) return 0.0;
    if (!cpms_dashboard_table_exists($pdo, 'cpms_material_usage')) return 0.0;
    if (!cpms_dashboard_table_exists($pdo, 'cpms_material_items')) return 0.0;
    if (!cpms_dashboard_column_exists($pdo, 'cpms_material_usage', 'project_id')) return 0.0;
    if (!cpms_dashboard_column_exists($pdo, 'cpms_material_usage', 'amount')) return 0.0;

    try {
        $deletedWhere = cpms_dashboard_column_exists($pdo, 'cpms_material_items', 'is_deleted') ? " AND (i.is_deleted = 0 OR i.is_deleted IS NULL)" : "";
        $hasCategory = cpms_dashboard_column_exists($pdo, 'cpms_material_items', 'category');
        $categoryExpr = $hasCategory ? "COALESCE(i.category, '')" : "''";
        $sql = "SELECT " . $categoryExpr . " AS category, COALESCE(SUM(u.amount), 0) AS amount
                FROM cpms_material_usage u
                LEFT JOIN cpms_material_items i ON i.id = u.material_id
                WHERE u.project_id = :pid" . $deletedWhere . "
                GROUP BY " . $categoryExpr;
        $st = $pdo->prepare($sql);
        $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) $rows = array();

        $total = 0.0;
        $allowedCategories = array('자재비' => 1, '구매품' => 1, '기타경비' => 1);
        foreach ($rows as $row) {
            $category = isset($row['category']) ? trim((string)$row['category']) : '';
            if ($category === '안전관리비') continue;
            if ($hasCategory && !isset($allowedCategories[$category])) continue;
            $total += isset($row['amount']) ? (float)$row['amount'] : 0.0;
        }
        return $total;
    } catch (Exception $e) {
        return 0.0;
    }
}}

if (!function_exists('cpms_dashboard_labor_apply_overrides')) {
function cpms_dashboard_labor_apply_overrides($map, $projectId, $month)
{
    if (!function_exists('cpms_load_labor_overrides')) return $map;
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
}}

if (!function_exists('cpms_dashboard_labor_wage_map')) {
function cpms_dashboard_labor_wage_map($pdo, $projectId)
{
    $wageMap = array();
    if (!$pdo || $projectId <= 0) return $wageMap;
    if (!function_exists('cpms_load_direct_team_members') || !function_exists('cpms_load_project_labor_workers') || !function_exists('cpms_build_project_worker_rows') || !function_exists('cpms_build_timesheet_workers')) {
        return $wageMap;
    }

    $directTeamMembers = cpms_load_direct_team_members($pdo);
    $projectWorkers = cpms_load_project_labor_workers($pdo, (int)$projectId);
    $workerRows = cpms_build_project_worker_rows($projectWorkers, $directTeamMembers);
    $timesheetWorkers = cpms_build_timesheet_workers($workerRows);

    foreach ($timesheetWorkers as $worker) {
        $name = isset($worker['name']) ? (string)$worker['name'] : '';
        $key = function_exists('cpms_normalize_worker_key') ? cpms_normalize_worker_key($name) : strtolower(trim($name));
        if ($key === '') continue;
        if (function_exists('cpms_resolve_labor_wage_rate')) {
            $wageMap[$key] = (float)cpms_resolve_labor_wage_rate($worker);
        } else {
            $raw = isset($worker['deposit_rate']) ? (string)$worker['deposit_rate'] : (isset($worker['daily_wage']) ? (string)$worker['daily_wage'] : '');
            $wageMap[$key] = cpms_dashboard_parse_money($raw);
        }
    }
    return $wageMap;
}}

if (!function_exists('cpms_dashboard_valid_date')) {
function cpms_dashboard_valid_date($date)
{
    $date = trim((string)$date);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return '';
    return $date;
}}

if (!function_exists('cpms_dashboard_labor_total')) {
function cpms_dashboard_labor_total($pdo, $projectId, $projectName, $startDate, $endDate)
{
    if (!$pdo || $projectId <= 0) return 0.0;
    $projectName = trim((string)$projectName);
    if ($projectName === '' || !function_exists('cpms_load_gongsu_data')) return 0.0;

    $startDate = cpms_dashboard_valid_date($startDate);
    $endDate = cpms_dashboard_valid_date($endDate);
    if ($startDate === '') $startDate = date('Y-01-01');
    if ($endDate === '') $endDate = date('Y-m-d');
    if ($startDate > $endDate) {
        $tmp = $startDate;
        $startDate = $endDate;
        $endDate = $tmp;
    }

    $wageMap = cpms_dashboard_labor_wage_map($pdo, (int)$projectId);
    if (count($wageMap) === 0) return 0.0;

    try {
        $cursor = new DateTime(substr($startDate, 0, 7) . '-01');
        $last = new DateTime(substr($endDate, 0, 7) . '-01');
    } catch (Exception $e) {
        return 0.0;
    }

    $sumGongsu = array();
    while ($cursor <= $last) {
        $ym = $cursor->format('Y-m');
        $gongsuData = cpms_load_gongsu_data($pdo, $projectName, $ym);
        $gongsuMap = isset($gongsuData['gongsu_map']) && is_array($gongsuData['gongsu_map']) ? $gongsuData['gongsu_map'] : array();
        $gongsuMap = cpms_dashboard_labor_apply_overrides($gongsuMap, (int)$projectId, $ym);

        foreach ($gongsuMap as $workerKey => $dailyMap) {
            if (!is_array($dailyMap)) continue;
            if (!isset($sumGongsu[$workerKey])) $sumGongsu[$workerKey] = 0.0;
            foreach ($dailyMap as $dateKey => $gongsuValue) {
                if (!is_numeric($gongsuValue)) continue;
                if ((string)$dateKey < (string)$startDate || (string)$dateKey > (string)$endDate) continue;
                $sumGongsu[$workerKey] += (float)$gongsuValue;
            }
        }

        $cursor->modify('+1 month');
    }

    $total = 0.0;
    foreach ($sumGongsu as $workerKey => $gongsuTotal) {
        $wageRate = isset($wageMap[$workerKey]) ? (float)$wageMap[$workerKey] : 0.0;
        $total += ((float)$gongsuTotal) * $wageRate;
    }
    return $total;
}}

if (!function_exists('cpms_dashboard_cost_period_range')) {
function cpms_dashboard_cost_period_range($ym, $type)
{
    $ym = trim((string)$ym);
    $type = trim((string)$type);
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
        $ym = date('Y-m');
    }
    if (function_exists('cpms_cost_period_range')) {
        $range = cpms_cost_period_range($ym, $type);
        $start = isset($range['start']) ? trim((string)$range['start']) : '';
        $end = isset($range['end']) ? trim((string)$range['end']) : '';
        if ($start !== '' && $end !== '') {
            return $range;
        }
    }
    if ($type === 'labor' || $type === 'sales') {
        $start = $ym . '-01';
        return array(
            'start' => $start,
            'end' => date('Y-m-t', strtotime($start)),
        );
    }
    $currentStartTs = strtotime($ym . '-01');
    $prevMonthTs = strtotime('-1 month', $currentStartTs);
    return array(
        'start' => date('Y-m', $prevMonthTs) . '-26',
        'end' => $ym . '-25',
    );
}}

if (!function_exists('cpms_dashboard_month_cursor_bounds')) {
function cpms_dashboard_month_cursor_bounds($startDate, $endDate)
{
    $startDate = cpms_dashboard_valid_date($startDate);
    $endDate = cpms_dashboard_valid_date($endDate);
    if ($startDate === '') $startDate = date('Y-01-01');
    if ($endDate === '') $endDate = date('Y-m-d');
    if ($startDate > $endDate) {
        $tmp = $startDate;
        $startDate = $endDate;
        $endDate = $tmp;
    }
    return array(
        'start' => substr($startDate, 0, 7) . '-01',
        'end' => substr($endDate, 0, 7) . '-01',
    );
}}

if (!function_exists('cpms_dashboard_equipment_total_between')) {
function cpms_dashboard_equipment_total_between($pdo, $projectId, $startDate, $endDate)
{
    if (!$pdo || $projectId <= 0) return 0.0;
    if (!cpms_dashboard_table_exists($pdo, 'cpms_equipment_usage')) return 0.0;
    if (!cpms_dashboard_column_exists($pdo, 'cpms_equipment_usage', 'project_id')) return 0.0;
    if (!cpms_dashboard_column_exists($pdo, 'cpms_equipment_usage', 'use_date')) return 0.0;

    $hasWorkUnit = cpms_dashboard_column_exists($pdo, 'cpms_equipment_usage', 'work_unit');
    $hasBaseRate = cpms_dashboard_column_exists($pdo, 'cpms_equipment_usage', 'base_rate_snapshot');
    $hasAmount = cpms_dashboard_column_exists($pdo, 'cpms_equipment_usage', 'amount');
    if (!$hasAmount && (!$hasWorkUnit || !$hasBaseRate)) return 0.0;

    try {
        if ($hasWorkUnit && $hasBaseRate) {
            $amountExpr = "COALESCE(NULLIF(work_unit, 0), 1) * COALESCE(NULLIF(base_rate_snapshot, 0)" . ($hasAmount ? ", amount" : "") . ", 0)";
            $sql = "SELECT COALESCE(SUM(" . $amountExpr . "), 0) FROM cpms_equipment_usage WHERE project_id = :pid AND use_date BETWEEN :start AND :end";
        } else {
            $sql = "SELECT COALESCE(SUM(amount), 0) FROM cpms_equipment_usage WHERE project_id = :pid AND use_date BETWEEN :start AND :end";
        }
        $st = $pdo->prepare($sql);
        $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
        $st->bindValue(':start', (string)$startDate);
        $st->bindValue(':end', (string)$endDate);
        $st->execute();
        return (float)$st->fetchColumn();
    } catch (Exception $e) {
        return 0.0;
    }
}}

if (!function_exists('cpms_dashboard_material_total_between')) {
function cpms_dashboard_material_total_between($pdo, $projectId, $startDate, $endDate)
{
    if (!$pdo || $projectId <= 0) return 0.0;
    if (!cpms_dashboard_table_exists($pdo, 'cpms_material_usage')) return 0.0;
    if (!cpms_dashboard_table_exists($pdo, 'cpms_material_items')) return 0.0;
    if (!cpms_dashboard_column_exists($pdo, 'cpms_material_usage', 'project_id')) return 0.0;
    if (!cpms_dashboard_column_exists($pdo, 'cpms_material_usage', 'use_date')) return 0.0;
    if (!cpms_dashboard_column_exists($pdo, 'cpms_material_usage', 'amount')) return 0.0;

    try {
        $deletedWhere = cpms_dashboard_column_exists($pdo, 'cpms_material_items', 'is_deleted') ? " AND (i.is_deleted = 0 OR i.is_deleted IS NULL)" : "";
        $hasCategory = cpms_dashboard_column_exists($pdo, 'cpms_material_items', 'category');
        $categoryExpr = $hasCategory ? "COALESCE(i.category, '')" : "''";
        $sql = "SELECT " . $categoryExpr . " AS category, COALESCE(SUM(u.amount), 0) AS amount
                FROM cpms_material_usage u
                LEFT JOIN cpms_material_items i ON i.id = u.material_id
                WHERE u.project_id = :pid
                  AND u.use_date BETWEEN :start AND :end" . $deletedWhere . "
                GROUP BY " . $categoryExpr;
        $st = $pdo->prepare($sql);
        $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
        $st->bindValue(':start', (string)$startDate);
        $st->bindValue(':end', (string)$endDate);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) $rows = array();

        $total = 0.0;
        $allowedCategories = array('자재비' => 1, '구매품' => 1, '기타경비' => 1);
        foreach ($rows as $row) {
            $category = isset($row['category']) ? trim((string)$row['category']) : '';
            if ($category === '안전관리비') continue;
            if ($hasCategory && !isset($allowedCategories[$category])) continue;
            $total += isset($row['amount']) ? (float)$row['amount'] : 0.0;
        }
        return $total;
    } catch (Exception $e) {
        return 0.0;
    }
}}

if (!function_exists('cpms_dashboard_labor_total_between')) {
function cpms_dashboard_labor_total_between($pdo, $projectId, $projectName, $startDate, $endDate, $wageMap)
{
    if (!$pdo || $projectId <= 0) return 0.0;
    $projectName = trim((string)$projectName);
    if ($projectName === '' || !function_exists('cpms_load_gongsu_data')) return 0.0;
    if (!is_array($wageMap) || count($wageMap) === 0) return 0.0;

    $startDate = cpms_dashboard_valid_date($startDate);
    $endDate = cpms_dashboard_valid_date($endDate);
    if ($startDate === '' || $endDate === '' || $startDate > $endDate) return 0.0;

    try {
        $cursor = new DateTime(substr($startDate, 0, 7) . '-01');
        $last = new DateTime(substr($endDate, 0, 7) . '-01');
    } catch (Exception $e) {
        return 0.0;
    }

    $sumGongsu = array();
    while ($cursor <= $last) {
        $ym = $cursor->format('Y-m');
        $gongsuData = cpms_load_gongsu_data($pdo, $projectName, $ym);
        $gongsuMap = isset($gongsuData['gongsu_map']) && is_array($gongsuData['gongsu_map']) ? $gongsuData['gongsu_map'] : array();
        $gongsuMap = cpms_dashboard_labor_apply_overrides($gongsuMap, (int)$projectId, $ym);

        foreach ($gongsuMap as $workerKey => $dailyMap) {
            if (!is_array($dailyMap)) continue;
            if (!isset($sumGongsu[$workerKey])) $sumGongsu[$workerKey] = 0.0;
            foreach ($dailyMap as $dateKey => $gongsuValue) {
                if (!is_numeric($gongsuValue)) continue;
                if ((string)$dateKey < (string)$startDate || (string)$dateKey > (string)$endDate) continue;
                $sumGongsu[$workerKey] += (float)$gongsuValue;
            }
        }
        $cursor->modify('+1 month');
    }

    $total = 0.0;
    foreach ($sumGongsu as $workerKey => $gongsuTotal) {
        $wageRate = isset($wageMap[$workerKey]) ? (float)$wageMap[$workerKey] : 0.0;
        $total += ((float)$gongsuTotal) * $wageRate;
    }
    return $total;
}}

if (!function_exists('cpms_dashboard_cost_rate_info')) {
function cpms_dashboard_cost_rate_info($sales, $usedTotal)
{
    $sales = (float)$sales;
    $usedTotal = (float)$usedTotal;
    if ($sales > 0) {
        $costRate = ($usedTotal / $sales) * 100;
        return array(
            'cost_rate' => $costRate,
            'cost_rate_label' => number_format($costRate, 1) . '%',
            'no_sales' => 0,
        );
    }
    if ($usedTotal > 0) {
        return array(
            'cost_rate' => 999.0,
            'cost_rate_label' => '매출 없음',
            'no_sales' => 1,
        );
    }
    return array(
        'cost_rate' => 0.0,
        'cost_rate_label' => '0%',
        'no_sales' => 0,
    );
}}

if (!function_exists('cpms_dashboard_project_monthly_cost_rows')) {
function cpms_dashboard_project_monthly_cost_rows($pdo, $projectId, $projectName, $startDate, $endDate)
{
    $rows = array();
    if (!$pdo || $projectId <= 0) return $rows;

    $bounds = cpms_dashboard_month_cursor_bounds($startDate, $endDate);
    try {
        $cursor = new DateTime($bounds['start']);
        $last = new DateTime($bounds['end']);
    } catch (Exception $e) {
        return $rows;
    }

    $wageMap = cpms_dashboard_labor_wage_map($pdo, (int)$projectId);
    while ($cursor <= $last) {
        $ym = $cursor->format('Y-m');
        $salesRange = cpms_dashboard_cost_period_range($ym, 'sales');
        $laborRange = cpms_dashboard_cost_period_range($ym, 'labor');
        $costRange = cpms_dashboard_cost_period_range($ym, 'material');

        $sales = 0.0;
        if (function_exists('cpms_sales_total_between')) {
            $salesResult = cpms_sales_total_between($pdo, (int)$projectId, $salesRange['start'], $salesRange['end']);
            $sales = isset($salesResult['amount']) ? (float)$salesResult['amount'] : 0.0;
        }
        $labor = cpms_dashboard_labor_total_between($pdo, (int)$projectId, $projectName, $laborRange['start'], $laborRange['end'], $wageMap);
        $equipment = cpms_dashboard_equipment_total_between($pdo, (int)$projectId, $costRange['start'], $costRange['end']);
        $materials = cpms_dashboard_material_total_between($pdo, (int)$projectId, $costRange['start'], $costRange['end']);
        $usedTotal = $labor + $equipment + $materials;
        $targetAmount = $sales * 0.7;
        $rateInfo = cpms_dashboard_cost_rate_info($sales, $usedTotal);

        $rows[count($rows)] = array(
            'ym' => $ym,
            'label' => $ym,
            'sales' => $sales,
            'labor' => $labor,
            'equipment' => $equipment,
            'materials' => $materials,
            'used_total' => $usedTotal,
            'target_amount' => $targetAmount,
            'target_amount_label' => cpms_dashboard_money($targetAmount),
            'cost_rate' => $rateInfo['cost_rate'],
            'cost_rate_label' => $rateInfo['cost_rate_label'],
            'no_sales' => $rateInfo['no_sales'],
            'is_target_over' => ((float)$targetAmount > 0 && $usedTotal > $targetAmount) ? 1 : 0,
        );

        $cursor->modify('+1 month');
    }
    return $rows;
}}

if (!function_exists('cpms_dashboard_project_status_color')) {
function cpms_dashboard_project_status_color($sales, $usedTotal)
{
    $sales = (float)$sales;
    $usedTotal = (float)$usedTotal;
    if ($sales <= 0 && $usedTotal > 0) return 'red';
    if ($sales <= 0) return 'blue';
    $rate = ($usedTotal / $sales) * 100;
    return ($rate > 80) ? 'red' : 'blue';
}}

if (!function_exists('cpms_dashboard_project_cost_summary')) {
function cpms_dashboard_project_cost_summary($pdo)
{
    $projects = array();
    $projectRows = cpms_dashboard_project_rows($pdo);

    foreach ($projectRows as $project) {
        $projectId = isset($project['id']) ? (int)$project['id'] : 0;
        if ($projectId <= 0) continue;
        $projectName = isset($project['name']) ? (string)$project['name'] : '';
        $startDate = isset($project['start_date']) ? (string)$project['start_date'] : '';
        $endDate = isset($project['end_date']) ? (string)$project['end_date'] : '';

        $sales = function_exists('cpms_sales_total_all') ? (float)cpms_sales_total_all($pdo, $projectId) : 0.0;
        $equipment = cpms_dashboard_equipment_total($pdo, $projectId);
        $labor = cpms_dashboard_labor_total($pdo, $projectId, $projectName, $startDate, $endDate);
        $materials = cpms_dashboard_material_total($pdo, $projectId);
        $usedTotal = $equipment + $labor + $materials;
        $targetAmount = $sales * 0.7;
        $rateInfo = cpms_dashboard_cost_rate_info($sales, $usedTotal);
        $monthlyRows = cpms_dashboard_project_monthly_cost_rows($pdo, $projectId, $projectName, $startDate, $endDate);

        $statusColor = cpms_dashboard_project_status_color($sales, $usedTotal);
        $projects[count($projects)] = array(
            'project_id' => $projectId,
            'project_name' => $projectName,
            'sales' => $sales,
            'labor' => $labor,
            'equipment' => $equipment,
            'materials' => $materials,
            'used_total' => $usedTotal,
            'target_amount' => $targetAmount,
            'target_amount_label' => cpms_dashboard_money($targetAmount),
            'monthly_rows' => $monthlyRows,
            'cost_rate' => $rateInfo['cost_rate'],
            'cost_rate_label' => $rateInfo['cost_rate_label'],
            'status_color' => $statusColor,
            'is_over_sales' => ($sales > 0 && $usedTotal > $sales) ? 1 : 0,
            'is_target_over' => ((float)$targetAmount > 0 && $usedTotal > $targetAmount) ? 1 : 0,
            'no_sales' => $rateInfo['no_sales'],
            'status' => isset($project['status']) ? (string)$project['status'] : '',
        );
    }

    usort($projects, function($a, $b) {
        $av = isset($a['cost_rate']) ? (float)$a['cost_rate'] : 0.0;
        $bv = isset($b['cost_rate']) ? (float)$b['cost_rate'] : 0.0;
        if ($av === $bv) return 0;
        return ($av > $bv) ? -1 : 1;
    });

    return array(
        'project_count' => count($projectRows),
        'projects' => $projects,
    );
}}
