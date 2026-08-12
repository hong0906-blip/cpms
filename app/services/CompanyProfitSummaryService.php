<?php
/**
 * Company profit summary service.
 * PHP 5.6 compatible.
 */

require_once __DIR__ . '/CompanyOverheadService.php';
require_once __DIR__ . '/CompanyProfitChartService.php';
require_once __DIR__ . '/CostChangeService.php';
require_once __DIR__ . '/../views/construction/tabs/partials/labor_data_loader.php';
require_once __DIR__ . '/../views/construction/tabs/partials/outsourcing_data_helper.php';
require_once __DIR__ . '/../views/construction/tabs/partials/sales_data_loader.php';
require_once __DIR__ . '/../views/safety/safety_cost_helper.php';

if (!function_exists('cpms_company_profit_cache_key')) {
function cpms_company_profit_cache_key($pdo, $suffix) {
    $prefix = 'nopdo';
    if ($pdo && function_exists('spl_object_hash')) $prefix = spl_object_hash($pdo);
    return $prefix . ':' . (string)$suffix;
}}

if (!function_exists('cpms_company_profit_shared_cache_root')) {
function cpms_company_profit_shared_cache_root() {
    $storageRoot = function_exists('cpms_storage_root')
        ? cpms_storage_root()
        : dirname(dirname(__DIR__)) . '/storage';
    return rtrim((string)$storageRoot, '/\\') . '/cache/company_profit';
}}

if (!function_exists('cpms_company_profit_shared_cache_path')) {
function cpms_company_profit_shared_cache_path($cacheKey) {
    $cacheKey = strtolower(trim((string)$cacheKey));
    if (!preg_match('/^[a-f0-9]{32}$/', $cacheKey)) return '';
    return cpms_company_profit_shared_cache_root() . '/' . $cacheKey . '.json';
}}

if (!function_exists('cpms_company_profit_shared_cache_read')) {
function cpms_company_profit_shared_cache_read($cacheKey, $ttlSeconds) {
    $path = cpms_company_profit_shared_cache_path($cacheKey);
    $ttlSeconds = max(1, (int)$ttlSeconds);
    if ($path === '' || !is_file($path)) return null;
    $mtime = @filemtime($path);
    if ($mtime === false || (time() - (int)$mtime) > $ttlSeconds) return null;
    $text = @file_get_contents($path);
    if ($text === false || trim($text) === '') return null;
    $payload = @json_decode($text, true);
    if (!is_array($payload) || !isset($payload['data']) || !is_array($payload['data'])) return null;
    if (!isset($payload['version']) || (int)$payload['version'] !== 1) return null;
    return $payload['data'];
}}

if (!function_exists('cpms_company_profit_shared_cache_write')) {
function cpms_company_profit_shared_cache_write($cacheKey, $data) {
    $path = cpms_company_profit_shared_cache_path($cacheKey);
    if ($path === '' || !is_array($data)) return false;
    $dir = dirname($path);
    $dirReady = function_exists('cpms_ensure_dir') ? cpms_ensure_dir($dir) : (is_dir($dir) || @mkdir($dir, 0777, true));
    if (!$dirReady) return false;
    $options = defined('JSON_UNESCAPED_UNICODE') ? JSON_UNESCAPED_UNICODE : 0;
    $json = json_encode(array('version' => 1, 'saved_at' => time(), 'data' => $data), $options);
    if ($json === false || $json === '') return false;
    return (@file_put_contents($path, $json, LOCK_EX) !== false);
}}

if (!function_exists('cpms_company_profit_table_exists')) {
function cpms_company_profit_table_exists($pdo, $table) {
    static $cache = array();
    if (!$pdo) return false;
    $cacheKey = cpms_company_profit_cache_key($pdo, 'table:' . (string)$table);
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE :tbl");
        $st->bindValue(':tbl', (string)$table);
        $st->execute();
        $cache[$cacheKey] = $st->fetch(PDO::FETCH_ASSOC) ? true : false;
        return $cache[$cacheKey];
    } catch (Exception $e) {
        $cache[$cacheKey] = false;
        return false;
    }
}}

if (!function_exists('cpms_company_profit_column_exists')) {
function cpms_company_profit_column_exists($pdo, $table, $column) {
    static $cache = array();
    if (!$pdo) return false;
    $cacheKey = cpms_company_profit_cache_key($pdo, 'column:' . (string)$table . ':' . (string)$column);
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `" . $table . "` LIKE :col");
        $st->bindValue(':col', (string)$column);
        $st->execute();
        $cache[$cacheKey] = $st->fetch(PDO::FETCH_ASSOC) ? true : false;
        return $cache[$cacheKey];
    } catch (Exception $e) {
        $cache[$cacheKey] = false;
        return false;
    }
}}

if (!function_exists('cpms_company_profit_project_settlement_date_expr')) {
function cpms_company_profit_project_settlement_date_expr($pdo) {
    if (cpms_company_profit_column_exists($pdo, 'cpms_projects', 'settlement_completed_at')) {
        return "COALESCE(NULLIF(settlement_completed_at, '0000-00-00'), NULLIF(end_date, '0000-00-00'))";
    }
    return "NULLIF(end_date, '0000-00-00')";
}}

if (!function_exists('cpms_company_profit_project_visible_end_expr')) {
function cpms_company_profit_project_visible_end_expr($pdo) {
    $settlementDateExpr = cpms_company_profit_project_settlement_date_expr($pdo);
    return "CASE WHEN status = '정산완료' AND " . $settlementDateExpr . " IS NOT NULL THEN STR_TO_DATE(CONCAT(YEAR(" . $settlementDateExpr . "), '-12-31'), '%Y-%m-%d') ELSE NULLIF(end_date, '0000-00-00') END";
}}

if (!function_exists('cpms_company_profit_cost_period_range')) {
function cpms_company_profit_cost_period_range($ym, $type) {
    $ym = trim((string)$ym);
    $type = trim((string)$type);
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');

    if ($type === 'labor' || $type === 'outsourcing' || $type === 'sales') {
        $start = $ym . '-01';
        return array('start' => $start, 'end' => date('Y-m-t', strtotime($start)));
    }

    $currentStartTs = strtotime($ym . '-01');
    $prevMonthTs = strtotime('-1 month', $currentStartTs);
    return array(
        'start' => date('Y-m', $prevMonthTs) . '-26',
        'end' => $ym . '-25',
    );
}}

if (!function_exists('cpms_company_profit_month_valid')) {
function cpms_company_profit_month_valid($ym) {
    return preg_match('/^\d{4}-\d{2}$/', (string)$ym) ? true : false;
}}

if (!function_exists('cpms_company_profit_months_between')) {
function cpms_company_profit_months_between($startYm, $endYm) {
    $months = array();
    if (!cpms_company_profit_month_valid($startYm) || !cpms_company_profit_month_valid($endYm)) return $months;
    $startTs = strtotime($startYm . '-01');
    $endTs = strtotime($endYm . '-01');
    if ($startTs === false || $endTs === false) return $months;
    if ($startTs > $endTs) {
        $tmp = $startTs;
        $startTs = $endTs;
        $endTs = $tmp;
    }
    $cursor = $startTs;
    while ($cursor <= $endTs) {
        $months[] = date('Y-m', $cursor);
        $cursor = strtotime('+1 month', $cursor);
    }
    return $months;
}}

if (!function_exists('cpms_company_profit_cost_rate_info')) {
function cpms_company_profit_cost_rate_info($sales, $usedTotal) {
    $sales = (float)$sales;
    $usedTotal = (float)$usedTotal;
    if ($sales > 0) {
        $rate = ($usedTotal / $sales) * 100;
        return array('cost_rate' => $rate, 'cost_rate_label' => number_format($rate, 1) . '%', 'no_sales' => 0);
    }
    if ($usedTotal > 0) {
        return array('cost_rate' => 999.0, 'cost_rate_label' => '매출 없음', 'no_sales' => 1);
    }
    return array('cost_rate' => 0.0, 'cost_rate_label' => '0%', 'no_sales' => 0);
}}

if (!function_exists('cpms_company_profit_available_years')) {
function cpms_company_profit_available_years($pdo) {
    $currentYear = (int)date('Y');
    $minYear = $currentYear;
    $maxYear = $currentYear;

    if ($pdo && cpms_company_profit_table_exists($pdo, 'cpms_projects')) {
        try {
            $visibleEndExpr = cpms_company_profit_project_visible_end_expr($pdo);
            $sql = "SELECT MIN(YEAR(start_date)) AS min_start, MAX(YEAR(" . $visibleEndExpr . ")) AS max_end FROM cpms_projects WHERE name NOT LIKE '(가제)%'";
            $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                if (isset($row['min_start']) && (int)$row['min_start'] > 0) $minYear = min($minYear, (int)$row['min_start']);
                if (isset($row['max_end']) && (int)$row['max_end'] > 0) $maxYear = max($maxYear, (int)$row['max_end']);
            }
        } catch (Exception $e) {
        }
    }

    if (function_exists('cpms_archive_summary_years')) {
        $archiveYears = cpms_archive_summary_years();
        foreach ($archiveYears as $archiveYear) {
            if ((int)$archiveYear > 0) {
                $minYear = min($minYear, (int)$archiveYear);
                $maxYear = max($maxYear, (int)$archiveYear);
            }
        }
    }

    if ($minYear > $maxYear) {
        $tmp = $minYear;
        $minYear = $maxYear;
        $maxYear = $tmp;
    }

    $years = array();
    for ($y = $minYear; $y <= $maxYear; $y++) $years[] = $y;
    if (count($years) === 0) $years[] = $currentYear;
    return $years;
}}

if (!function_exists('cpms_company_profit_status_options')) {
function cpms_company_profit_status_options($pdo) {
    $rows = array();
    if (!$pdo || !cpms_company_profit_table_exists($pdo, 'cpms_projects')) return $rows;
    try {
        $st = $pdo->query("SELECT DISTINCT status FROM cpms_projects WHERE status IS NOT NULL AND status <> '' AND name NOT LIKE '(가제)%' ORDER BY status ASC");
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $status = isset($row['status']) ? trim((string)$row['status']) : '';
            if ($status !== '') $rows[] = $status;
        }
    } catch (Exception $e) {
    }
    return $rows;
}}

if (!function_exists('cpms_company_profit_normalize_filters')) {
function cpms_company_profit_normalize_filters($request, $pdo) {
    $years = cpms_company_profit_available_years($pdo);
    $currentYear = (int)date('Y');
    $year = isset($request['year']) ? (int)$request['year'] : $currentYear;
    if (!in_array($year, $years, true)) {
        $year = in_array($currentYear, $years, true) ? $currentYear : $years[count($years) - 1];
    }

    $viewMode = isset($request['view_mode']) ? trim((string)$request['view_mode']) : 'monthly';
    if ($viewMode !== 'monthly' && $viewMode !== 'quarterly' && $viewMode !== 'yearly') $viewMode = 'monthly';

    $hasCustomRange = isset($request['start_month']) || isset($request['end_month']);
    $scope = isset($request['scope']) ? trim((string)$request['scope']) : ($hasCustomRange ? 'custom' : 'year');
    if ($scope !== 'year' && $scope !== 'month' && $scope !== 'custom' && $scope !== 'all') $scope = 'year';

    $month = isset($request['month']) ? (int)$request['month'] : 0;
    if ($month < 1 || $month > 12) $month = 0;

    $defaultEndMonth = ($year === $currentYear) ? date('Y-m') : sprintf('%04d-12', $year);
    $startMonth = isset($request['start_month']) ? trim((string)$request['start_month']) : '';
    $endMonth = isset($request['end_month']) ? trim((string)$request['end_month']) : '';
    if (!cpms_company_profit_month_valid($startMonth)) $startMonth = sprintf('%04d-01', $year);
    if (!cpms_company_profit_month_valid($endMonth)) $endMonth = $defaultEndMonth;

    if ($scope === 'month') {
        if ($month <= 0) $month = (int)date('n');
        $startMonth = sprintf('%04d-%02d', $year, $month);
        $endMonth = $startMonth;
    } else if ($scope === 'year') {
        $startMonth = sprintf('%04d-01', $year);
        $endMonth = $defaultEndMonth;
    } else if ($scope === 'all') {
        $startMonth = sprintf('%04d-01', $years[0]);
        $endMonth = sprintf('%04d-12', $years[count($years) - 1]);
    }
    if (strtotime($startMonth . '-01') > strtotime($endMonth . '-01')) {
        $tmpMonth = $startMonth;
        $startMonth = $endMonth;
        $endMonth = $tmpMonth;
    }

    $status = isset($request['status']) ? trim((string)$request['status']) : '';
    $q = isset($request['q']) ? trim((string)$request['q']) : '';
    $projectId = isset($request['project_id']) ? (int)$request['project_id'] : 0;

    return array(
        'year' => $year,
        'month' => $month,
        'scope' => $scope,
        'start_month' => $startMonth,
        'end_month' => $endMonth,
        'view_mode' => $viewMode,
        'status' => $status,
        'q' => $q,
        'project_id' => $projectId,
        'years' => $years,
        'status_options' => cpms_company_profit_status_options($pdo),
    );
}}

if (!function_exists('cpms_company_profit_load_projects')) {
function cpms_company_profit_load_projects($pdo, $filters) {
    $projects = array();
    if (!$pdo || !cpms_company_profit_table_exists($pdo, 'cpms_projects')) return $projects;

    $where = array();
    $params = array();

    $status = isset($filters['status']) ? trim((string)$filters['status']) : '';
    $where[] = "name NOT LIKE '(가제)%'";
    $projectIdFilter = isset($filters['project_id']) ? (int)$filters['project_id'] : 0;
    if ($projectIdFilter > 0) {
        $where[] = "id = :project_id";
        $params[':project_id'] = $projectIdFilter;
    }
    if ($status !== '') {
        $where[] = "status = :status";
        $params[':status'] = $status;
    }

    $q = isset($filters['q']) ? trim((string)$filters['q']) : '';
    if ($q !== '') {
        $where[] = "(name LIKE :q OR client LIKE :q OR contractor LIKE :q OR location LIKE :q)";
        $params[':q'] = '%' . $q . '%';
    }

    $scope = isset($filters['scope']) ? trim((string)$filters['scope']) : '';
    $startMonth = isset($filters['start_month']) ? trim((string)$filters['start_month']) : '';
    $endMonth = isset($filters['end_month']) ? trim((string)$filters['end_month']) : '';
    if ($scope !== 'all' && cpms_company_profit_month_valid($startMonth) && cpms_company_profit_month_valid($endMonth)) {
        $periodStart = $startMonth . '-01';
        $periodEnd = date('Y-m-t', strtotime($endMonth . '-01'));
        if (strtotime($periodStart) > strtotime($periodEnd)) {
            $tmpPeriod = $periodStart;
            $periodStart = date('Y-m-01', strtotime($periodEnd));
            $periodEnd = date('Y-m-t', strtotime($tmpPeriod));
        }
        $visibleEndExpr = cpms_company_profit_project_visible_end_expr($pdo);
        $where[] = "((start_date IS NULL OR start_date = '0000-00-00' OR start_date <= :period_end) AND (" . $visibleEndExpr . " IS NULL OR " . $visibleEndExpr . " >= :period_start))";
        $params[':period_start'] = $periodStart;
        $params[':period_end'] = $periodEnd;
    }

    try {
        $settlementSelect = cpms_company_profit_column_exists($pdo, 'cpms_projects', 'settlement_completed_at') ? ", settlement_completed_at" : ", NULL AS settlement_completed_at";
        $sql = "SELECT id, name, client, contractor, location, start_date, end_date, contract_amount, status" . $settlementSelect . " FROM cpms_projects";
        if (count($where) > 0) $sql .= " WHERE " . implode(" AND ", $where);
        $sql .= " ORDER BY id DESC";
        $st = $pdo->prepare($sql);
        foreach ($params as $key => $value) $st->bindValue($key, $value);
        $st->execute();
        $projects = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($projects)) $projects = array();
    } catch (Exception $e) {
        error_log('[company_profit] project load failed: ' . $e->getMessage());
        $projects = array();
    }

    return $projects;
}}

if (!function_exists('cpms_company_profit_equipment_total_between')) {
function cpms_company_profit_equipment_total_between($pdo, $projectId, $startDate, $endDate) {
    static $cache = array();
    if (!$pdo || (int)$projectId <= 0) return 0.0;
    $cacheKey = cpms_company_profit_cache_key($pdo, 'equipment:' . (int)$projectId . ':' . $startDate . ':' . $endDate);
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];
    if (!cpms_company_profit_table_exists($pdo, 'cpms_equipment_usage')) return 0.0;
    if (!cpms_company_profit_column_exists($pdo, 'cpms_equipment_usage', 'project_id')) return 0.0;
    if (!cpms_company_profit_column_exists($pdo, 'cpms_equipment_usage', 'use_date')) return 0.0;

    $hasWorkUnit = cpms_company_profit_column_exists($pdo, 'cpms_equipment_usage', 'work_unit');
    $hasBaseRate = cpms_company_profit_column_exists($pdo, 'cpms_equipment_usage', 'base_rate_snapshot');
    $hasAmount = cpms_company_profit_column_exists($pdo, 'cpms_equipment_usage', 'amount');
    if (!$hasAmount && (!$hasWorkUnit || !$hasBaseRate)) return 0.0;

    try {
        $fromSql = "cpms_equipment_usage u";
        $deletedWhere = "";
        $dateExpr = "u.use_date";
        if (\App\Services\CostChangeService::isInstalled($pdo)) {
            $fromSql .= " LEFT JOIN cpms_cost_record_meta ccm ON ccm.target_type = 'equipment' AND ccm.target_id = CAST(u.id AS CHAR)";
            $dateExpr = "COALESCE(CONCAT(ccm.settlement_ym, '-25'), u.use_date)";
            $deletedWhere .= " AND (ccm.is_deleted = 0 OR ccm.is_deleted IS NULL)";
        }
        if (cpms_company_profit_table_exists($pdo, 'cpms_equipment_items') &&
            cpms_company_profit_column_exists($pdo, 'cpms_equipment_usage', 'equipment_id') &&
            cpms_company_profit_column_exists($pdo, 'cpms_equipment_items', 'is_deleted')) {
            $fromSql .= " INNER JOIN cpms_equipment_items e ON e.id = u.equipment_id AND e.project_id = u.project_id";
            $deletedWhere .= " AND (e.is_deleted = 0 OR e.is_deleted IS NULL)";
        }

        if ($hasWorkUnit && $hasBaseRate) {
            $amountExpr = "COALESCE(NULLIF(u.work_unit, 0), 1) * COALESCE(NULLIF(u.base_rate_snapshot, 0)" . ($hasAmount ? ", u.amount" : "") . ", 0)";
            $sql = "SELECT COALESCE(SUM(" . $amountExpr . "), 0) FROM " . $fromSql . " WHERE u.project_id = :pid AND " . $dateExpr . " BETWEEN :start AND :end" . $deletedWhere;
        } else {
            $sql = "SELECT COALESCE(SUM(u.amount), 0) FROM " . $fromSql . " WHERE u.project_id = :pid AND " . $dateExpr . " BETWEEN :start AND :end" . $deletedWhere;
        }

        $st = $pdo->prepare($sql);
        $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
        $st->bindValue(':start', (string)$startDate);
        $st->bindValue(':end', (string)$endDate);
        $st->execute();
        $cache[$cacheKey] = (float)$st->fetchColumn();
        return $cache[$cacheKey];
    } catch (Exception $e) {
        error_log('[company_profit] equipment total failed: ' . $e->getMessage());
        $cache[$cacheKey] = 0.0;
        return 0.0;
    }
}}

if (!function_exists('cpms_company_profit_material_category_sum_between')) {
function cpms_company_profit_material_category_sum_between($pdo, $projectId, $startDate, $endDate) {
    $result = array('자재비' => 0.0, '구매품' => 0.0, '기타경비' => 0.0, '안전관리비' => 0.0);
    static $cache = array();
    if (!$pdo || (int)$projectId <= 0) return $result;
    $cacheKey = cpms_company_profit_cache_key($pdo, 'material:' . (int)$projectId . ':' . $startDate . ':' . $endDate);
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];
    if (!cpms_company_profit_table_exists($pdo, 'cpms_material_usage')) return $result;
    if (!cpms_company_profit_table_exists($pdo, 'cpms_material_items')) return $result;
    if (!cpms_company_profit_column_exists($pdo, 'cpms_material_usage', 'project_id')) return $result;
    if (!cpms_company_profit_column_exists($pdo, 'cpms_material_usage', 'use_date')) return $result;
    if (!cpms_company_profit_column_exists($pdo, 'cpms_material_usage', 'amount')) return $result;

    try {
        $deletedWhere = cpms_company_profit_column_exists($pdo, 'cpms_material_items', 'is_deleted') ? " AND (i.is_deleted = 0 OR i.is_deleted IS NULL)" : "";
        $metaJoin = "";
        $dateExpr = "u.use_date";
        if (\App\Services\CostChangeService::isInstalled($pdo)) {
            $metaJoin = " LEFT JOIN cpms_cost_record_meta ccm ON ccm.target_type = 'material' AND ccm.target_id = CAST(u.id AS CHAR)";
            $dateExpr = "COALESCE(CONCAT(ccm.settlement_ym, '-25'), u.use_date)";
            $deletedWhere .= " AND (ccm.is_deleted = 0 OR ccm.is_deleted IS NULL)";
        }
        $sql = "SELECT COALESCE(i.category, '') AS category, COALESCE(SUM(u.amount), 0) AS amount
                  FROM cpms_material_usage u
                  LEFT JOIN cpms_material_items i ON i.id = u.material_id
                  " . $metaJoin . "
                 WHERE u.project_id = :pid
                   AND " . $dateExpr . " BETWEEN :start AND :end" . $deletedWhere . "
                 GROUP BY COALESCE(i.category, '')";
        $st = $pdo->prepare($sql);
        $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
        $st->bindValue(':start', (string)$startDate);
        $st->bindValue(':end', (string)$endDate);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) $rows = array();
        foreach ($rows as $row) {
            $cat = isset($row['category']) ? trim((string)$row['category']) : '';
            if (!isset($result[$cat])) $cat = '자재비';
            $result[$cat] += isset($row['amount']) ? (float)$row['amount'] : 0.0;
        }
    } catch (Exception $e) {
        error_log('[company_profit] material total failed: ' . $e->getMessage());
    }

    $cache[$cacheKey] = $result;
    return $result;
}}

if (!function_exists('cpms_company_profit_labor_wage_map')) {
function cpms_company_profit_labor_wage_map($pdo, $projectId) {
    $wageMap = array();
    if (!$pdo || (int)$projectId <= 0) return $wageMap;
    if (!function_exists('cpms_load_direct_team_members') || !function_exists('cpms_load_project_labor_workers') || !function_exists('cpms_build_project_worker_rows') || !function_exists('cpms_build_timesheet_workers')) {
        return $wageMap;
    }

    static $cache = array();
    $cacheKey = cpms_company_profit_cache_key($pdo, 'labor-wage:' . (int)$projectId);
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];

    try {
        $directTeamMembers = cpms_load_direct_team_members($pdo);
        $projectWorkers = cpms_load_project_labor_workers($pdo, (int)$projectId);
        $workerRows = cpms_build_project_worker_rows($projectWorkers, $directTeamMembers);
        $timesheetWorkers = cpms_build_timesheet_workers($workerRows);
        foreach ($timesheetWorkers as $worker) {
            $name = isset($worker['name']) ? (string)$worker['name'] : '';
            $key = function_exists('cpms_normalize_worker_key') ? cpms_normalize_worker_key($name) : trim($name);
            if ($key === '') continue;
            if (function_exists('cpms_resolve_labor_wage_rate')) {
                $wageMap[$key] = (float)cpms_resolve_labor_wage_rate($worker);
            } else {
                $raw = isset($worker['deposit_rate']) ? preg_replace('/[^0-9.\-]/', '', (string)$worker['deposit_rate']) : '';
                $wageMap[$key] = ($raw !== '' && is_numeric($raw)) ? (float)$raw : 0.0;
            }
        }
    } catch (Exception $e) {
        error_log('[company_profit] labor wage map failed: ' . $e->getMessage());
    }

    $cache[$cacheKey] = $wageMap;
    return $wageMap;
}}

if (!function_exists('cpms_company_profit_apply_labor_overrides')) {
function cpms_company_profit_apply_labor_overrides($map, $projectId, $month) {
    if (function_exists('cpms_apply_labor_overrides_to_map')) {
        return cpms_apply_labor_overrides_to_map($map, (int)$projectId, (string)$month);
    }
    return $map;
}}

if (!function_exists('cpms_company_profit_labor_total_between')) {
function cpms_company_profit_labor_total_between($pdo, $projectId, $projectName, $startDate, $endDate, $laborWageMap) {
    static $cache = array();
    if (!$pdo || (int)$projectId <= 0) return 0.0;
    if (!function_exists('cpms_load_gongsu_data')) return 0.0;
    $cacheKey = cpms_company_profit_cache_key($pdo, 'labor-total:' . (int)$projectId . ':' . $projectName . ':' . $startDate . ':' . $endDate);
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];

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
        $gongsuData = cpms_load_gongsu_data($pdo, (string)$projectName, $ym);
        $gongsuMap = (isset($gongsuData['gongsu_map']) && is_array($gongsuData['gongsu_map'])) ? $gongsuData['gongsu_map'] : array();
        $gongsuMap = cpms_company_profit_apply_labor_overrides($gongsuMap, (int)$projectId, $ym);
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
        $wageRate = isset($laborWageMap[$workerKey]) ? (float)$laborWageMap[$workerKey] : 0.0;
        $totalLabor += ((float)$workerSumGongsu) * $wageRate;
    }

    if (function_exists('cpms_labor_force_amount_between')) {
        $totalLabor += cpms_labor_force_amount_between($pdo, (int)$projectId, $startDate, $endDate);
    }

    $cache[$cacheKey] = (float)$totalLabor;
    return $cache[$cacheKey];
}}

if (!function_exists('cpms_company_profit_expected_sales_between')) {
function cpms_company_profit_expected_sales_between($pdo, $projectId, $startDate, $endDate) {
    if (!function_exists('cpms_sales_total_between')) return 0.0;
    $res = cpms_sales_total_between($pdo, (int)$projectId, (string)$startDate, (string)$endDate);
    return (is_array($res) && isset($res['amount'])) ? (float)$res['amount'] : 0.0;
}}

if (!function_exists('cpms_company_profit_confirmed_sales_month')) {
function cpms_company_profit_confirmed_sales_month($pdo, $projectId, $ym) {
    $result = array('has_input' => false, 'amount' => 0.0, 'rows' => 0);
    if (!$pdo || (int)$projectId <= 0 || !cpms_company_profit_month_valid($ym)) return $result;

    $range = cpms_company_profit_cost_period_range($ym, 'sales');
    $startDate = $range['start'];
    $endDate = $range['end'];

    if (cpms_company_profit_table_exists($pdo, 'cpms_progress_billings') &&
        cpms_company_profit_column_exists($pdo, 'cpms_progress_billings', 'project_id') &&
        cpms_company_profit_column_exists($pdo, 'cpms_progress_billings', 'recognized_amount')) {
        try {
            $hasRequested = cpms_company_profit_column_exists($pdo, 'cpms_progress_billings', 'requested_amount');
            $amountExpr = $hasRequested ? "COALESCE(NULLIF(recognized_amount, 0), requested_amount, 0)" : "recognized_amount";
            $whereDate = "";
            if (cpms_company_profit_column_exists($pdo, 'cpms_progress_billings', 'progress_date')) {
                if (cpms_company_profit_column_exists($pdo, 'cpms_progress_billings', 'created_at')) {
                    $whereDate = " AND COALESCE(progress_date, DATE(created_at)) BETWEEN :start_date AND :end_date";
                } else {
                    $whereDate = " AND progress_date IS NOT NULL AND progress_date BETWEEN :start_date AND :end_date";
                }
            } else if (cpms_company_profit_column_exists($pdo, 'cpms_progress_billings', 'created_at')) {
                $whereDate = " AND DATE(created_at) BETWEEN :start_date AND :end_date";
            }
            if ($whereDate !== '') {
                $sql = "SELECT COUNT(*) AS row_count, COALESCE(SUM(" . $amountExpr . "), 0) AS amount
                          FROM cpms_progress_billings
                         WHERE project_id = :pid" . $whereDate;
                $st = $pdo->prepare($sql);
                $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
                $st->bindValue(':start_date', (string)$startDate);
                $st->bindValue(':end_date', (string)$endDate);
                $st->execute();
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if (is_array($row) && (int)$row['row_count'] > 0) {
                    $result['has_input'] = true;
                    $result['rows'] = (int)$row['row_count'];
                    $result['amount'] = isset($row['amount']) ? (float)$row['amount'] : 0.0;
                    return $result;
                }
            }
        } catch (Exception $e) {
            error_log('[company_profit] confirmed sales failed: ' . $e->getMessage());
        }
    }

    if (cpms_company_profit_table_exists($pdo, 'cpms_monthly_recognized') &&
        cpms_company_profit_column_exists($pdo, 'cpms_monthly_recognized', 'ym') &&
        cpms_company_profit_column_exists($pdo, 'cpms_monthly_recognized', 'recognized_cum_amount')) {
        try {
            $st = $pdo->prepare("SELECT recognized_cum_amount FROM cpms_monthly_recognized WHERE project_id = :pid AND ym = :ym LIMIT 1");
            $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
            $st->bindValue(':ym', (string)$ym);
            $st->execute();
            $cur = $st->fetchColumn();
            if ($cur !== false) {
                $prevYm = date('Y-m', strtotime($ym . '-01 -1 month'));
                $stPrev = $pdo->prepare("SELECT recognized_cum_amount FROM cpms_monthly_recognized WHERE project_id = :pid AND ym = :ym LIMIT 1");
                $stPrev->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
                $stPrev->bindValue(':ym', (string)$prevYm);
                $stPrev->execute();
                $prev = $stPrev->fetchColumn();
                $result['has_input'] = true;
                $result['rows'] = 1;
                $result['amount'] = max(0.0, (float)$cur - ($prev !== false ? (float)$prev : 0.0));
                return $result;
            }
        } catch (Exception $e) {
            error_log('[company_profit] legacy confirmed sales failed: ' . $e->getMessage());
        }
    }

    return $result;
}}

if (!function_exists('cpms_company_profit_project_id_list')) {
function cpms_company_profit_project_id_list($projects) {
    $ids = array();
    if (!is_array($projects)) return $ids;
    foreach ($projects as $project) {
        $projectId = isset($project['id']) ? (int)$project['id'] : 0;
        if ($projectId > 0) $ids[$projectId] = $projectId;
    }
    return array_values($ids);
}}

if (!function_exists('cpms_company_profit_empty_month_map')) {
function cpms_company_profit_empty_month_map($projectIds, $months, $defaultValue) {
    $map = array();
    foreach ($projectIds as $projectId) {
        $projectId = (int)$projectId;
        $map[$projectId] = array();
        foreach ($months as $ym) $map[$projectId][(string)$ym] = $defaultValue;
    }
    return $map;
}}

if (!function_exists('cpms_company_profit_settlement_ym_from_date')) {
function cpms_company_profit_settlement_ym_from_date($dateValue, $calendarMonth) {
    $dateValue = trim((string)$dateValue);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateValue)) return '';
    $ym = substr($dateValue, 0, 7);
    if (!$calendarMonth && (int)substr($dateValue, 8, 2) >= 26) {
        $ts = strtotime($ym . '-01 +1 month');
        if ($ts !== false) $ym = date('Y-m', $ts);
    }
    return $ym;
}}

if (!function_exists('cpms_company_profit_preload_confirmed_sales')) {
function cpms_company_profit_preload_confirmed_sales($pdo, $projects, $months) {
    $projectIds = cpms_company_profit_project_id_list($projects);
    $emptyValue = array('has_input' => false, 'amount' => 0.0, 'rows' => 0);
    $result = array(
        'available' => false,
        'map' => cpms_company_profit_empty_month_map($projectIds, $months, $emptyValue),
    );
    if (!$pdo || count($projectIds) === 0 || count($months) === 0) return $result;

    $firstYm = (string)$months[0];
    $lastYm = (string)$months[count($months) - 1];
    $startDate = $firstYm . '-01';
    $endDate = date('Y-m-t', strtotime($lastYm . '-01'));
    $idSql = implode(',', array_map('intval', $projectIds));

    if (cpms_company_profit_table_exists($pdo, 'cpms_progress_billings') &&
        cpms_company_profit_column_exists($pdo, 'cpms_progress_billings', 'project_id') &&
        cpms_company_profit_column_exists($pdo, 'cpms_progress_billings', 'recognized_amount')) {
        $dateExpr = '';
        if (cpms_company_profit_column_exists($pdo, 'cpms_progress_billings', 'progress_date')) {
            $dateExpr = cpms_company_profit_column_exists($pdo, 'cpms_progress_billings', 'created_at')
                ? "COALESCE(progress_date, DATE(created_at))"
                : "progress_date";
        } else if (cpms_company_profit_column_exists($pdo, 'cpms_progress_billings', 'created_at')) {
            $dateExpr = "DATE(created_at)";
        }
        if ($dateExpr !== '') {
            try {
                $hasRequested = cpms_company_profit_column_exists($pdo, 'cpms_progress_billings', 'requested_amount');
                $amountExpr = $hasRequested ? "COALESCE(NULLIF(recognized_amount, 0), requested_amount, 0)" : "recognized_amount";
                $sql = "SELECT project_id, DATE_FORMAT(" . $dateExpr . ", '%Y-%m') AS ym,
                               COUNT(*) AS row_count, COALESCE(SUM(" . $amountExpr . "), 0) AS amount
                          FROM cpms_progress_billings
                         WHERE project_id IN (" . $idSql . ")
                           AND " . $dateExpr . " BETWEEN :start_date AND :end_date
                         GROUP BY project_id, DATE_FORMAT(" . $dateExpr . ", '%Y-%m')";
                $st = $pdo->prepare($sql);
                $st->bindValue(':start_date', $startDate);
                $st->bindValue(':end_date', $endDate);
                $st->execute();
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                if (!is_array($rows)) $rows = array();
                foreach ($rows as $row) {
                    $projectId = isset($row['project_id']) ? (int)$row['project_id'] : 0;
                    $ym = isset($row['ym']) ? (string)$row['ym'] : '';
                    if (!isset($result['map'][$projectId][$ym])) continue;
                    $result['map'][$projectId][$ym] = array(
                        'has_input' => true,
                        'amount' => isset($row['amount']) ? (float)$row['amount'] : 0.0,
                        'rows' => isset($row['row_count']) ? (int)$row['row_count'] : 0,
                    );
                }
                $result['available'] = true;
            } catch (Exception $e) {
                error_log('[company_profit] confirmed sales preload failed: ' . $e->getMessage());
            }
        }
    }

    if (cpms_company_profit_table_exists($pdo, 'cpms_monthly_recognized') &&
        cpms_company_profit_column_exists($pdo, 'cpms_monthly_recognized', 'project_id') &&
        cpms_company_profit_column_exists($pdo, 'cpms_monthly_recognized', 'ym') &&
        cpms_company_profit_column_exists($pdo, 'cpms_monthly_recognized', 'recognized_cum_amount')) {
        try {
            $legacyStartYm = date('Y-m', strtotime($firstYm . '-01 -1 month'));
            $sql = "SELECT project_id, ym, recognized_cum_amount
                      FROM cpms_monthly_recognized
                     WHERE project_id IN (" . $idSql . ") AND ym BETWEEN :start_ym AND :end_ym";
            $st = $pdo->prepare($sql);
            $st->bindValue(':start_ym', $legacyStartYm);
            $st->bindValue(':end_ym', $lastYm);
            $st->execute();
            $legacyRows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($legacyRows)) $legacyRows = array();
            $cumulative = array();
            foreach ($legacyRows as $legacyRow) {
                $projectId = isset($legacyRow['project_id']) ? (int)$legacyRow['project_id'] : 0;
                $ym = isset($legacyRow['ym']) ? (string)$legacyRow['ym'] : '';
                if ($projectId <= 0 || !cpms_company_profit_month_valid($ym)) continue;
                if (!isset($cumulative[$projectId])) $cumulative[$projectId] = array();
                $cumulative[$projectId][$ym] = isset($legacyRow['recognized_cum_amount']) ? (float)$legacyRow['recognized_cum_amount'] : 0.0;
            }
            foreach ($projectIds as $projectId) {
                foreach ($months as $ym) {
                    if (!isset($result['map'][$projectId][$ym]) || !empty($result['map'][$projectId][$ym]['has_input'])) continue;
                    if (!isset($cumulative[$projectId][$ym])) continue;
                    $prevYm = date('Y-m', strtotime($ym . '-01 -1 month'));
                    $previous = isset($cumulative[$projectId][$prevYm]) ? (float)$cumulative[$projectId][$prevYm] : 0.0;
                    $result['map'][$projectId][$ym] = array(
                        'has_input' => true,
                        'amount' => max(0.0, (float)$cumulative[$projectId][$ym] - $previous),
                        'rows' => 1,
                    );
                }
            }
            $result['available'] = true;
        } catch (Exception $e) {
            error_log('[company_profit] legacy confirmed sales preload failed: ' . $e->getMessage());
        }
    }

    return $result;
}}

if (!function_exists('cpms_company_profit_preload_expected_activity')) {
function cpms_company_profit_preload_expected_activity($pdo, $projects, $months) {
    $projectIds = cpms_company_profit_project_id_list($projects);
    $result = array(
        'available' => false,
        'map' => cpms_company_profit_empty_month_map($projectIds, $months, false),
    );
    if (!$pdo || count($projectIds) === 0 || count($months) === 0) return $result;
    if (!cpms_company_profit_table_exists($pdo, 'cpms_schedule_tasks') ||
        !cpms_company_profit_table_exists($pdo, 'cpms_project_unit_prices') ||
        !cpms_company_profit_column_exists($pdo, 'cpms_schedule_tasks', 'project_id')) {
        $result['available'] = true;
        return $result;
    }

    $firstYm = (string)$months[0];
    $lastYm = (string)$months[count($months) - 1];
    $startDate = $firstYm . '-01';
    $endDate = date('Y-m-t', strtotime($lastYm . '-01'));
    $idSql = implode(',', array_map('intval', $projectIds));
    $selects = array();
    $activityParams = array();
    $selectIndex = 0;

    if (cpms_company_profit_table_exists($pdo, 'cpms_schedule_task_item_progress') &&
        cpms_company_profit_column_exists($pdo, 'cpms_schedule_task_item_progress', 'project_id') &&
        cpms_company_profit_column_exists($pdo, 'cpms_schedule_task_item_progress', 'work_date') &&
        cpms_company_profit_column_exists($pdo, 'cpms_schedule_task_item_progress', 'done_qty')) {
        $startKey = ':start_date_' . $selectIndex;
        $endKey = ':end_date_' . $selectIndex;
        $activityParams[$startKey] = $startDate;
        $activityParams[$endKey] = $endDate;
        $selects[] = "SELECT project_id, DATE_FORMAT(work_date, '%Y-%m') AS ym
                       FROM cpms_schedule_task_item_progress
                      WHERE project_id IN (" . $idSql . ") AND work_date BETWEEN " . $startKey . " AND " . $endKey . "
                        AND COALESCE(done_qty, 0) <> 0";
        $selectIndex++;
    }
    if (cpms_company_profit_table_exists($pdo, 'cpms_schedule_progress') &&
        cpms_company_profit_column_exists($pdo, 'cpms_schedule_progress', 'project_id') &&
        cpms_company_profit_column_exists($pdo, 'cpms_schedule_progress', 'work_date') &&
        cpms_company_profit_column_exists($pdo, 'cpms_schedule_progress', 'done_qty')) {
        $startKey = ':start_date_' . $selectIndex;
        $endKey = ':end_date_' . $selectIndex;
        $activityParams[$startKey] = $startDate;
        $activityParams[$endKey] = $endDate;
        $selects[] = "SELECT project_id, DATE_FORMAT(work_date, '%Y-%m') AS ym
                       FROM cpms_schedule_progress
                      WHERE project_id IN (" . $idSql . ") AND work_date BETWEEN " . $startKey . " AND " . $endKey . "
                        AND COALESCE(done_qty, 0) <> 0";
        $selectIndex++;
    }
    if (cpms_company_profit_column_exists($pdo, 'cpms_schedule_tasks', 'end_date') &&
        cpms_company_profit_column_exists($pdo, 'cpms_schedule_tasks', 'progress')) {
        $startKey = ':start_date_' . $selectIndex;
        $endKey = ':end_date_' . $selectIndex;
        $todayKey = ':today_' . $selectIndex;
        $activityParams[$startKey] = $startDate;
        $activityParams[$endKey] = $endDate;
        $activityParams[$todayKey] = date('Y-m-d');
        $selects[] = "SELECT project_id, DATE_FORMAT(end_date, '%Y-%m') AS ym
                       FROM cpms_schedule_tasks
                      WHERE project_id IN (" . $idSql . ") AND end_date BETWEEN " . $startKey . " AND " . $endKey . "
                        AND (COALESCE(progress, 0) >= 100 OR (end_date < " . $todayKey . " AND (progress IS NULL OR progress = 0)))";
        $selectIndex++;
    }

    if (count($selects) === 0) {
        $result['available'] = true;
        return $result;
    }
    try {
        $sql = "SELECT project_id, ym FROM (" . implode(' UNION ', $selects) . ") company_profit_expected_activity GROUP BY project_id, ym";
        $st = $pdo->prepare($sql);
        foreach ($activityParams as $paramKey => $paramValue) $st->bindValue($paramKey, $paramValue);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) $rows = array();
        foreach ($rows as $row) {
            $projectId = isset($row['project_id']) ? (int)$row['project_id'] : 0;
            $ym = isset($row['ym']) ? (string)$row['ym'] : '';
            if (isset($result['map'][$projectId][$ym])) $result['map'][$projectId][$ym] = true;
        }
        $result['available'] = true;
    } catch (Exception $e) {
        error_log('[company_profit] expected activity preload failed: ' . $e->getMessage());
    }
    return $result;
}}

if (!function_exists('cpms_company_profit_preload_cost_maps')) {
function cpms_company_profit_preload_cost_maps($pdo, $projects, $months) {
    $projectIds = cpms_company_profit_project_id_list($projects);
    $materialDefault = array('자재비' => 0.0, '구매품' => 0.0, '기타경비' => 0.0, '안전관리비' => 0.0);
    $result = array(
        'available' => array('equipment' => false, 'materials' => false, 'manual_outsourcing' => false, 'safety' => false, 'deduction' => false),
        'equipment' => cpms_company_profit_empty_month_map($projectIds, $months, 0.0),
        'materials' => cpms_company_profit_empty_month_map($projectIds, $months, $materialDefault),
        'manual_outsourcing' => cpms_company_profit_empty_month_map($projectIds, $months, 0.0),
        'safety' => cpms_company_profit_empty_month_map($projectIds, $months, 0.0),
        'deduction' => cpms_company_profit_empty_month_map($projectIds, $months, 0.0),
    );
    if (!$pdo || count($projectIds) === 0 || count($months) === 0) return $result;

    $firstYm = (string)$months[0];
    $lastYm = (string)$months[count($months) - 1];
    $costStartRange = cpms_company_profit_cost_period_range($firstYm, 'material');
    $costEndRange = cpms_company_profit_cost_period_range($lastYm, 'material');
    $costStart = $costStartRange['start'];
    $costEnd = $costEndRange['end'];
    $idSql = implode(',', array_map('intval', $projectIds));
    $metaInstalled = class_exists('App\\Services\\CostChangeService') && \App\Services\CostChangeService::isInstalled($pdo);

    if (cpms_company_profit_table_exists($pdo, 'cpms_equipment_usage') &&
        cpms_company_profit_column_exists($pdo, 'cpms_equipment_usage', 'project_id') &&
        cpms_company_profit_column_exists($pdo, 'cpms_equipment_usage', 'use_date')) {
        $hasWorkUnit = cpms_company_profit_column_exists($pdo, 'cpms_equipment_usage', 'work_unit');
        $hasBaseRate = cpms_company_profit_column_exists($pdo, 'cpms_equipment_usage', 'base_rate_snapshot');
        $hasAmount = cpms_company_profit_column_exists($pdo, 'cpms_equipment_usage', 'amount');
        if ($hasAmount || ($hasWorkUnit && $hasBaseRate)) {
            try {
                $fromSql = "cpms_equipment_usage u";
                $whereSql = '';
                $dateExpr = 'u.use_date';
                if ($metaInstalled) {
                    $fromSql .= " LEFT JOIN cpms_cost_record_meta ccm ON ccm.target_type = 'equipment' AND ccm.target_id = CAST(u.id AS CHAR)";
                    $dateExpr = "COALESCE(CONCAT(NULLIF(ccm.settlement_ym, ''), '-25'), u.use_date)";
                    $whereSql .= " AND (ccm.is_deleted = 0 OR ccm.is_deleted IS NULL)";
                }
                if (cpms_company_profit_table_exists($pdo, 'cpms_equipment_items') &&
                    cpms_company_profit_column_exists($pdo, 'cpms_equipment_usage', 'equipment_id') &&
                    cpms_company_profit_column_exists($pdo, 'cpms_equipment_items', 'is_deleted')) {
                    $fromSql .= " INNER JOIN cpms_equipment_items e ON e.id = u.equipment_id AND e.project_id = u.project_id";
                    $whereSql .= " AND (e.is_deleted = 0 OR e.is_deleted IS NULL)";
                }
                $amountExpr = ($hasWorkUnit && $hasBaseRate)
                    ? "COALESCE(NULLIF(u.work_unit, 0), 1) * COALESCE(NULLIF(u.base_rate_snapshot, 0)" . ($hasAmount ? ", u.amount" : "") . ", 0)"
                    : "u.amount";
                $sql = "SELECT u.project_id, " . $dateExpr . " AS effective_date, COALESCE(SUM(" . $amountExpr . "), 0) AS amount
                          FROM " . $fromSql . "
                         WHERE u.project_id IN (" . $idSql . ") AND " . $dateExpr . " BETWEEN :start_date AND :end_date" . $whereSql . "
                         GROUP BY u.project_id, " . $dateExpr;
                $st = $pdo->prepare($sql);
                $st->execute(array(':start_date' => $costStart, ':end_date' => $costEnd));
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                if (!is_array($rows)) $rows = array();
                foreach ($rows as $row) {
                    $projectId = isset($row['project_id']) ? (int)$row['project_id'] : 0;
                    $ym = cpms_company_profit_settlement_ym_from_date(isset($row['effective_date']) ? $row['effective_date'] : '', false);
                    if (isset($result['equipment'][$projectId][$ym])) $result['equipment'][$projectId][$ym] += isset($row['amount']) ? (float)$row['amount'] : 0.0;
                }
                $result['available']['equipment'] = true;
            } catch (Exception $e) {
                error_log('[company_profit] equipment preload failed: ' . $e->getMessage());
            }
        }
    }

    if (cpms_company_profit_table_exists($pdo, 'cpms_material_usage') &&
        cpms_company_profit_table_exists($pdo, 'cpms_material_items') &&
        cpms_company_profit_column_exists($pdo, 'cpms_material_usage', 'project_id') &&
        cpms_company_profit_column_exists($pdo, 'cpms_material_usage', 'use_date') &&
        cpms_company_profit_column_exists($pdo, 'cpms_material_usage', 'amount')) {
        try {
            $metaJoin = '';
            $dateExpr = 'u.use_date';
            $whereSql = cpms_company_profit_column_exists($pdo, 'cpms_material_items', 'is_deleted') ? " AND (i.is_deleted = 0 OR i.is_deleted IS NULL)" : '';
            if ($metaInstalled) {
                $metaJoin = " LEFT JOIN cpms_cost_record_meta ccm ON ccm.target_type = 'material' AND ccm.target_id = CAST(u.id AS CHAR)";
                $dateExpr = "COALESCE(CONCAT(NULLIF(ccm.settlement_ym, ''), '-25'), u.use_date)";
                $whereSql .= " AND (ccm.is_deleted = 0 OR ccm.is_deleted IS NULL)";
            }
            $sql = "SELECT u.project_id, " . $dateExpr . " AS effective_date, COALESCE(i.category, '') AS category,
                           COALESCE(SUM(u.amount), 0) AS amount
                      FROM cpms_material_usage u
                      LEFT JOIN cpms_material_items i ON i.id = u.material_id" . $metaJoin . "
                     WHERE u.project_id IN (" . $idSql . ") AND " . $dateExpr . " BETWEEN :start_date AND :end_date" . $whereSql . "
                     GROUP BY u.project_id, " . $dateExpr . ", COALESCE(i.category, '')";
            $st = $pdo->prepare($sql);
            $st->execute(array(':start_date' => $costStart, ':end_date' => $costEnd));
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows)) $rows = array();
            foreach ($rows as $row) {
                $projectId = isset($row['project_id']) ? (int)$row['project_id'] : 0;
                $ym = cpms_company_profit_settlement_ym_from_date(isset($row['effective_date']) ? $row['effective_date'] : '', false);
                $category = isset($row['category']) ? trim((string)$row['category']) : '';
                if (!isset($materialDefault[$category])) $category = '자재비';
                if (isset($result['materials'][$projectId][$ym])) $result['materials'][$projectId][$ym][$category] += isset($row['amount']) ? (float)$row['amount'] : 0.0;
            }
            $result['available']['materials'] = true;
        } catch (Exception $e) {
            error_log('[company_profit] material preload failed: ' . $e->getMessage());
        }
    }

    if (cpms_company_profit_table_exists($pdo, 'cpms_outsourcing_costs') &&
        cpms_company_profit_column_exists($pdo, 'cpms_outsourcing_costs', 'project_id') &&
        cpms_company_profit_column_exists($pdo, 'cpms_outsourcing_costs', 'expense_date') &&
        cpms_company_profit_column_exists($pdo, 'cpms_outsourcing_costs', 'amount')) {
        try {
            $fromSql = 'cpms_outsourcing_costs o';
            $ymExpr = "DATE_FORMAT(o.expense_date, '%Y-%m')";
            $whereSql = cpms_company_profit_column_exists($pdo, 'cpms_outsourcing_costs', 'is_deleted') ? ' AND (o.is_deleted = 0 OR o.is_deleted IS NULL)' : '';
            if ($metaInstalled) {
                $fromSql .= " LEFT JOIN cpms_cost_record_meta ccm ON ccm.target_type = 'outsourcing' AND ccm.target_id = CAST(o.id AS CHAR)";
                $ymExpr = "CASE WHEN ccm.manual_settlement_yn = 1 AND ccm.settlement_ym REGEXP '^[0-9]{4}-[0-9]{2}$' THEN ccm.settlement_ym ELSE DATE_FORMAT(o.expense_date, '%Y-%m') END";
                $whereSql .= ' AND (ccm.is_deleted = 0 OR ccm.is_deleted IS NULL)';
            }
            $sql = "SELECT o.project_id, " . $ymExpr . " AS ym, COALESCE(SUM(o.amount), 0) AS amount
                      FROM " . $fromSql . "
                     WHERE o.project_id IN (" . $idSql . ") AND " . $ymExpr . " BETWEEN :start_ym AND :end_ym" . $whereSql . "
                     GROUP BY o.project_id, " . $ymExpr;
            $st = $pdo->prepare($sql);
            $st->execute(array(':start_ym' => $firstYm, ':end_ym' => $lastYm));
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows)) $rows = array();
            foreach ($rows as $row) {
                $projectId = isset($row['project_id']) ? (int)$row['project_id'] : 0;
                $ym = isset($row['ym']) ? (string)$row['ym'] : '';
                if (isset($result['manual_outsourcing'][$projectId][$ym])) $result['manual_outsourcing'][$projectId][$ym] += isset($row['amount']) ? (float)$row['amount'] : 0.0;
            }
            $result['available']['manual_outsourcing'] = true;
        } catch (Exception $e) {
            error_log('[company_profit] outsourcing preload failed: ' . $e->getMessage());
        }
    }

    if (cpms_company_profit_table_exists($pdo, 'cpms_project_monthly_deductions') &&
        cpms_company_profit_column_exists($pdo, 'cpms_project_monthly_deductions', 'project_id') &&
        cpms_company_profit_column_exists($pdo, 'cpms_project_monthly_deductions', 'ym') &&
        cpms_company_profit_column_exists($pdo, 'cpms_project_monthly_deductions', 'amount')) {
        try {
            $sql = "SELECT project_id, ym, COALESCE(SUM(amount), 0) AS amount
                      FROM cpms_project_monthly_deductions
                     WHERE project_id IN (" . $idSql . ") AND ym BETWEEN :start_ym AND :end_ym
                     GROUP BY project_id, ym";
            $st = $pdo->prepare($sql);
            $st->execute(array(':start_ym' => $firstYm, ':end_ym' => $lastYm));
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows)) $rows = array();
            foreach ($rows as $row) {
                $projectId = isset($row['project_id']) ? (int)$row['project_id'] : 0;
                $ym = isset($row['ym']) ? (string)$row['ym'] : '';
                if (isset($result['deduction'][$projectId][$ym])) $result['deduction'][$projectId][$ym] += isset($row['amount']) ? (float)$row['amount'] : 0.0;
            }
            $result['available']['deduction'] = true;
        } catch (Exception $e) {
            error_log('[company_profit] deduction preload failed: ' . $e->getMessage());
        }
    }

    if (function_exists('cpms_safety_cost_all_items') && function_exists('cpms_safety_cost_row_amount')) {
        try {
            $safetyMeta = array();
            if ($metaInstalled) {
                $sql = "SELECT target_id, settlement_ym, manual_settlement_yn, is_deleted
                          FROM cpms_cost_record_meta
                         WHERE target_type = 'safety' AND project_id IN (" . $idSql . ")";
                $metaRows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
                if (!is_array($metaRows)) $metaRows = array();
                foreach ($metaRows as $metaRow) {
                    $targetId = isset($metaRow['target_id']) ? (string)$metaRow['target_id'] : '';
                    if ($targetId !== '') $safetyMeta[$targetId] = $metaRow;
                }
            }
            $projectSet = array_fill_keys($projectIds, true);
            $items = cpms_safety_cost_all_items();
            foreach ($items as $item) {
                if (!is_array($item) || !cpms_safety_cost_is_active($item)) continue;
                $projectId = isset($item['project_id']) ? (int)$item['project_id'] : 0;
                if (!isset($projectSet[$projectId])) continue;
                $targetId = isset($item['id']) ? (string)$item['id'] : '';
                $meta = ($targetId !== '' && isset($safetyMeta[$targetId])) ? $safetyMeta[$targetId] : array();
                if (isset($meta['is_deleted']) && (int)$meta['is_deleted'] === 1) continue;
                $ym = isset($meta['settlement_ym']) && cpms_company_profit_month_valid($meta['settlement_ym'])
                    ? (string)$meta['settlement_ym']
                    : cpms_company_profit_settlement_ym_from_date(isset($item['use_date']) ? $item['use_date'] : '', false);
                if (!isset($result['safety'][$projectId][$ym])) continue;
                $result['safety'][$projectId][$ym] += (float)cpms_safety_cost_row_amount($item);
            }
            $result['available']['safety'] = true;
        } catch (Exception $e) {
            error_log('[company_profit] safety preload failed: ' . $e->getMessage());
        }
    }

    return $result;
}}

if (!function_exists('cpms_company_profit_preload_context')) {
function cpms_company_profit_preload_context($pdo, $projects, $months) {
    $confirmed = cpms_company_profit_preload_confirmed_sales($pdo, $projects, $months);
    $expectedActivity = cpms_company_profit_preload_expected_activity($pdo, $projects, $months);
    $costs = cpms_company_profit_preload_cost_maps($pdo, $projects, $months);
    $costs['confirmed_available'] = !empty($confirmed['available']);
    $costs['confirmed'] = isset($confirmed['map']) && is_array($confirmed['map']) ? $confirmed['map'] : array();
    $costs['expected_activity_available'] = !empty($expectedActivity['available']);
    $costs['expected_activity'] = isset($expectedActivity['map']) && is_array($expectedActivity['map']) ? $expectedActivity['map'] : array();
    return $costs;
}}

if (!function_exists('cpms_company_profit_project_month_metrics')) {
function cpms_company_profit_project_month_metrics($pdo, $project, $ym, $laborWageMap, $preloaded = array()) {
    $projectId = isset($project['id']) ? (int)$project['id'] : 0;
    $projectName = isset($project['name']) ? (string)$project['name'] : '';

    $salesRange = cpms_company_profit_cost_period_range($ym, 'sales');
    $laborRange = cpms_company_profit_cost_period_range($ym, 'labor');
    $outsourcingRange = cpms_company_profit_cost_period_range($ym, 'outsourcing');
    $costRange = cpms_company_profit_cost_period_range($ym, 'material');

    if (!is_array($preloaded)) $preloaded = array();
    if (!empty($preloaded['confirmed_available']) && isset($preloaded['confirmed'][$projectId][$ym])) {
        $confirmed = $preloaded['confirmed'][$projectId][$ym];
    } else {
        $confirmed = cpms_company_profit_confirmed_sales_month($pdo, $projectId, $ym);
    }
    $confirmedSales = isset($confirmed['amount']) ? (float)$confirmed['amount'] : 0.0;
    $hasConfirmed = !empty($confirmed['has_input']);
    $skipConfirmedExpected = !empty($preloaded['skip_confirmed_expected']);
    $hasExpectedActivity = empty($preloaded['expected_activity_available']) || !empty($preloaded['expected_activity'][$projectId][$ym]);
    $expectedSales = (($hasConfirmed && $skipConfirmedExpected) || !$hasExpectedActivity)
        ? 0.0
        : cpms_company_profit_expected_sales_between($pdo, $projectId, $salesRange['start'], $salesRange['end']);
    $sales = $hasConfirmed ? $confirmedSales : $expectedSales;

    $equipment = !empty($preloaded['available']['equipment']) && isset($preloaded['equipment'][$projectId][$ym])
        ? (float)$preloaded['equipment'][$projectId][$ym]
        : cpms_company_profit_equipment_total_between($pdo, $projectId, $costRange['start'], $costRange['end']);
    $materialByCategory = !empty($preloaded['available']['materials']) && isset($preloaded['materials'][$projectId][$ym])
        ? $preloaded['materials'][$projectId][$ym]
        : cpms_company_profit_material_category_sum_between($pdo, $projectId, $costRange['start'], $costRange['end']);
    $materialCost = (float)$materialByCategory['자재비'];
    $purchaseCost = (float)$materialByCategory['구매품'];
    $otherCost = (float)$materialByCategory['기타경비'];
    $materials = $materialCost + $purchaseCost + $otherCost;
    $laborGross = cpms_company_profit_labor_total_between($pdo, $projectId, $projectName, $laborRange['start'], $laborRange['end'], $laborWageMap);
    $laborOutsourcing = 0.0;
    if (function_exists('cpms_outsourcing_labor_company_rows_for_month')) {
        $laborOutsourcingRow = cpms_outsourcing_labor_company_rows_for_month($pdo, $projectId, $projectName, $ym);
        $laborOutsourcing = isset($laborOutsourcingRow['total']) ? (float)$laborOutsourcingRow['total'] : 0.0;
    }
    if ($laborOutsourcing < 0) $laborOutsourcing = 0.0;
    if ($laborOutsourcing > $laborGross) $laborOutsourcing = $laborGross;
    $labor = $laborGross - $laborOutsourcing;
    if (!empty($preloaded['available']['manual_outsourcing']) && isset($preloaded['manual_outsourcing'][$projectId][$ym])) {
        $manualOutsourcing = (float)$preloaded['manual_outsourcing'][$projectId][$ym];
    } else {
        $manualOutsourcing = cpms_company_profit_table_exists($pdo, 'cpms_outsourcing_costs') && function_exists('cpms_outsourcing_manual_total_between')
            ? (float)cpms_outsourcing_manual_total_between($pdo, $projectId, $outsourcingRange['start'], $outsourcingRange['end'])
            : 0.0;
    }
    $outsourcing = $laborOutsourcing + $manualOutsourcing;
    if (!empty($preloaded['available']['safety']) && isset($preloaded['safety'][$projectId][$ym])) {
        $safety = (float)$preloaded['safety'][$projectId][$ym];
    } else {
        $safety = function_exists('cpms_safety_cost_total_between')
            ? (float)cpms_safety_cost_total_between($projectId, $costRange['start'], $costRange['end'])
            : 0.0;
    }
    $deduction = 0.0;
    if (!empty($preloaded['available']['deduction']) && isset($preloaded['deduction'][$projectId][$ym])) {
        $deduction = (float)$preloaded['deduction'][$projectId][$ym];
    } else if (cpms_company_profit_table_exists($pdo, 'cpms_project_monthly_deductions')) {
        try {
            $stDeduction = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cpms_project_monthly_deductions WHERE project_id = :pid AND ym = :ym");
            $stDeduction->bindValue(':pid', $projectId, PDO::PARAM_INT);
            $stDeduction->bindValue(':ym', $ym);
            $stDeduction->execute();
            $deduction = (float)$stDeduction->fetchColumn();
        } catch (Exception $e) {
            $deduction = 0.0;
        }
    }
    $usedTotal = $labor + $outsourcing + $equipment + $materials + $safety + $deduction;
    $targetAmount = round($sales * 0.7);
    $costRate = cpms_company_profit_cost_rate_info($sales, $usedTotal);

    return array(
        'ym' => $ym,
        'sales' => $sales,
        'expected_sales' => $expectedSales,
        'confirmed_sales' => $confirmedSales,
        'has_confirmed' => $hasConfirmed ? 1 : 0,
        'confirmed_rows' => isset($confirmed['rows']) ? (int)$confirmed['rows'] : 0,
        'labor' => $labor,
        'outsourcing' => $outsourcing,
        'labor_outsourcing' => $laborOutsourcing,
        'manual_outsourcing' => $manualOutsourcing,
        'equipment' => $equipment,
        'materials' => $materials,
        'material_cost' => $materialCost,
        'purchase_cost' => $purchaseCost,
        'other_cost' => $otherCost,
        'safety_cost' => $safety,
        'deduction' => $deduction,
        'input_cost' => $usedTotal,
        'target_amount' => $targetAmount,
        'net_profit' => $sales - $usedTotal,
        'cost_rate' => $costRate['cost_rate'],
        'cost_rate_label' => $costRate['cost_rate_label'],
        'no_sales' => $costRate['no_sales'],
    );
}}

if (!function_exists('cpms_company_profit_project_summary')) {
function cpms_company_profit_project_summary($pdo, $project, $months, $preloaded = array()) {
    if (!is_array($preloaded)) $preloaded = array();
    $projectId = isset($project['id']) ? (int)$project['id'] : 0;
    $laborWageMap = cpms_company_profit_labor_wage_map($pdo, $projectId);
    $row = array(
        'id' => $projectId,
        'name' => isset($project['name']) ? (string)$project['name'] : ('현장 #' . $projectId),
        'client' => isset($project['client']) ? (string)$project['client'] : '',
        'status' => isset($project['status']) ? (string)$project['status'] : '',
        'start_date' => isset($project['start_date']) ? (string)$project['start_date'] : '',
        'end_date' => isset($project['end_date']) ? (string)$project['end_date'] : '',
        'sales' => 0.0,
        'expected_sales' => 0.0,
        'confirmed_sales' => 0.0,
        'labor' => 0.0,
        'outsourcing' => 0.0,
        'equipment' => 0.0,
        'material_cost' => 0.0,
        'purchase_cost' => 0.0,
        'other_cost' => 0.0,
        'safety_cost' => 0.0,
        'deduction' => 0.0,
        'input_cost' => 0.0,
        'target_amount' => 0.0,
        'net_profit' => 0.0,
        'cost_rate' => 0.0,
        'cost_rate_label' => '0%',
        'no_sales' => 0,
        'basis' => 'none',
        'monthly' => array(),
        'error' => '',
    );

    $confirmedCount = 0;
    $expectedCount = 0;
    foreach ($months as $ym) {
        $monthRow = cpms_company_profit_project_month_metrics($pdo, $project, $ym, $laborWageMap, $preloaded);
        $row['monthly'][$ym] = $monthRow;
        $row['sales'] += (float)$monthRow['sales'];
        $row['expected_sales'] += (float)$monthRow['expected_sales'];
        $row['confirmed_sales'] += (float)$monthRow['confirmed_sales'];
        $row['labor'] += isset($monthRow['labor']) ? (float)$monthRow['labor'] : 0.0;
        $row['outsourcing'] += isset($monthRow['outsourcing']) ? (float)$monthRow['outsourcing'] : 0.0;
        $row['equipment'] += isset($monthRow['equipment']) ? (float)$monthRow['equipment'] : 0.0;
        $row['material_cost'] += isset($monthRow['material_cost']) ? (float)$monthRow['material_cost'] : 0.0;
        $row['purchase_cost'] += isset($monthRow['purchase_cost']) ? (float)$monthRow['purchase_cost'] : 0.0;
        $row['other_cost'] += isset($monthRow['other_cost']) ? (float)$monthRow['other_cost'] : 0.0;
        $row['safety_cost'] += isset($monthRow['safety_cost']) ? (float)$monthRow['safety_cost'] : 0.0;
        $row['deduction'] += isset($monthRow['deduction']) ? (float)$monthRow['deduction'] : 0.0;
        $row['input_cost'] += (float)$monthRow['input_cost'];
        $row['target_amount'] += (float)$monthRow['target_amount'];

        if (!empty($monthRow['has_confirmed'])) {
            $confirmedCount++;
        } else if ((float)$monthRow['expected_sales'] > 0) {
            $expectedCount++;
        }
    }

    $row['net_profit'] = $row['sales'] - $row['input_cost'];
    $rateInfo = cpms_company_profit_cost_rate_info($row['sales'], $row['input_cost']);
    $row['cost_rate'] = $rateInfo['cost_rate'];
    $row['cost_rate_label'] = $rateInfo['cost_rate_label'];
    $row['no_sales'] = $rateInfo['no_sales'];

    if ($confirmedCount > 0 && $expectedCount > 0) $row['basis'] = 'mixed';
    else if ($confirmedCount > 0) $row['basis'] = 'confirmed';
    else if ($expectedCount > 0) $row['basis'] = 'expected';

    return $row;
}}

if (!function_exists('cpms_company_profit_group_key')) {
function cpms_company_profit_group_key($ym, $viewMode) {
    $year = substr((string)$ym, 0, 4);
    $month = (int)substr((string)$ym, 5, 2);
    if ($viewMode === 'yearly') return $year;
    if ($viewMode === 'quarterly') {
        $q = (int)ceil($month / 3);
        return $year . '-Q' . $q;
    }
    return (string)$ym;
}}

if (!function_exists('cpms_company_profit_group_label')) {
function cpms_company_profit_group_label($key, $viewMode) {
    if ($viewMode === 'yearly') return (string)$key . '년';
    if ($viewMode === 'quarterly') {
        $parts = explode('-Q', (string)$key);
        if (count($parts) === 2) return $parts[0] . '년 ' . $parts[1] . '분기';
    }
    if (preg_match('/^\d{4}-\d{2}$/', (string)$key)) {
        return (int)substr((string)$key, 5, 2) . '월';
    }
    return (string)$key;
}}

if (!function_exists('cpms_company_profit_empty_period_row')) {
function cpms_company_profit_empty_period_row($key, $viewMode) {
    return array(
        'key' => $key,
        'label' => cpms_company_profit_group_label($key, $viewMode),
        'sales' => 0.0,
        'project_input_cost' => 0.0,
        'overhead' => 0.0,
        'total_input_cost' => 0.0,
        'net_profit' => 0.0,
        'cost_rate' => 0.0,
        'cost_rate_label' => '0%',
        'no_sales' => 0,
    );
}}

if (!function_exists('cpms_company_profit_build_period_rows')) {
function cpms_company_profit_build_period_rows($projectRows, $months, $viewMode, $overheadByMonth) {
    $rows = array();
    foreach ($months as $ym) {
        $key = cpms_company_profit_group_key($ym, $viewMode);
        if (!isset($rows[$key])) $rows[$key] = cpms_company_profit_empty_period_row($key, $viewMode);
        $rows[$key]['overhead'] += isset($overheadByMonth[$ym]) ? (float)$overheadByMonth[$ym] : 0.0;
    }

    foreach ($projectRows as $projectRow) {
        $monthly = (isset($projectRow['monthly']) && is_array($projectRow['monthly'])) ? $projectRow['monthly'] : array();
        foreach ($months as $ym) {
            if (!isset($monthly[$ym]) || !is_array($monthly[$ym])) continue;
            $key = cpms_company_profit_group_key($ym, $viewMode);
            if (!isset($rows[$key])) $rows[$key] = cpms_company_profit_empty_period_row($key, $viewMode);
            $rows[$key]['sales'] += isset($monthly[$ym]['sales']) ? (float)$monthly[$ym]['sales'] : 0.0;
            $rows[$key]['project_input_cost'] += isset($monthly[$ym]['input_cost']) ? (float)$monthly[$ym]['input_cost'] : 0.0;
        }
    }

    foreach ($rows as $key => $row) {
        $rows[$key]['total_input_cost'] = (float)$row['project_input_cost'] + (float)$row['overhead'];
        $rows[$key]['net_profit'] = (float)$row['sales'] - (float)$rows[$key]['total_input_cost'];
        $rateInfo = cpms_company_profit_cost_rate_info($rows[$key]['sales'], $rows[$key]['total_input_cost']);
        $rows[$key]['cost_rate'] = $rateInfo['cost_rate'];
        $rows[$key]['cost_rate_label'] = $rateInfo['cost_rate_label'];
        $rows[$key]['no_sales'] = $rateInfo['no_sales'];
    }

    return array_values($rows);
}}

if (!function_exists('cpms_company_profit_safety_cost_total_summary')) {
function cpms_company_profit_safety_cost_total_summary($pdo, $projects) {
    $summary = array(
        'project_count' => 0,
        'contract_total' => 0.0,
        'limit_110' => 0.0,
        'used_total' => 0.0,
        'used_current_year' => 0.0,
        'current_year' => (int)date('Y'),
        'remaining' => 0.0,
        'use_rate' => 0.0,
        'remaining_rate' => 0.0,
        'limit_use_rate' => 0.0,
    );
    if (!$pdo || !is_array($projects)) return $summary;
    $currentYear = (int)$summary['current_year'];
    $projectIds = cpms_company_profit_project_id_list($projects);
    $summary['project_count'] = count($projectIds);
    if (count($projectIds) === 0) return $summary;
    $idSql = implode(',', array_map('intval', $projectIds));

    $contractLoaded = false;
    if (cpms_company_profit_table_exists($pdo, 'cpms_project_unit_prices') &&
        cpms_company_profit_column_exists($pdo, 'cpms_project_unit_prices', 'project_id') &&
        cpms_company_profit_column_exists($pdo, 'cpms_project_unit_prices', 'item_name') &&
        cpms_company_profit_column_exists($pdo, 'cpms_project_unit_prices', 'is_safety')) {
        try {
            $select = array('project_id', 'item_name');
            foreach (array('spec', 'qty', 'unit_price', 'safety_unit_price', 'amount') as $column) {
                $select[] = cpms_company_profit_column_exists($pdo, 'cpms_project_unit_prices', $column) ? $column : ('NULL AS ' . $column);
            }
            $where = array('project_id IN (' . $idSql . ')', 'is_safety = 1');
            if (cpms_company_profit_column_exists($pdo, 'cpms_project_unit_prices', 'is_active')) $where[] = '(is_active = 1 OR is_active IS NULL)';
            if (cpms_company_profit_column_exists($pdo, 'cpms_project_unit_prices', 'is_current')) $where[] = '(is_current = 1 OR is_current IS NULL)';
            $rows = $pdo->query('SELECT ' . implode(', ', $select) . ' FROM cpms_project_unit_prices WHERE ' . implode(' AND ', $where))->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows)) $rows = array();
            foreach ($rows as $row) {
                $summary['contract_total'] += function_exists('cpms_safety_cost_unit_row_amount')
                    ? (float)cpms_safety_cost_unit_row_amount($row)
                    : (isset($row['amount']) ? (float)$row['amount'] : 0.0);
            }
            $contractLoaded = true;
        } catch (Exception $e) {
            error_log('[company_profit] safety contract preload failed: ' . $e->getMessage());
        }
    }
    if (!$contractLoaded && function_exists('cpms_safety_cost_contract_total')) {
        foreach ($projectIds as $projectId) $summary['contract_total'] += (float)cpms_safety_cost_contract_total($pdo, $projectId);
    }

    $usageLoaded = false;
    if (function_exists('cpms_safety_cost_all_items') && function_exists('cpms_safety_cost_row_amount')) {
        try {
            $projectSet = array_fill_keys($projectIds, true);
            $safetyMeta = array();
            if (class_exists('App\\Services\\CostChangeService') && \App\Services\CostChangeService::isInstalled($pdo)) {
                $metaRows = $pdo->query("SELECT target_id, settlement_ym FROM cpms_cost_record_meta WHERE target_type = 'safety' AND project_id IN (" . $idSql . ")")->fetchAll(PDO::FETCH_ASSOC);
                if (!is_array($metaRows)) $metaRows = array();
                foreach ($metaRows as $metaRow) {
                    $targetId = isset($metaRow['target_id']) ? (string)$metaRow['target_id'] : '';
                    if ($targetId !== '') $safetyMeta[$targetId] = isset($metaRow['settlement_ym']) ? (string)$metaRow['settlement_ym'] : '';
                }
            }
            $items = cpms_safety_cost_all_items();
            foreach ($items as $item) {
                if (!is_array($item) || !cpms_safety_cost_is_active($item)) continue;
                $projectId = isset($item['project_id']) ? (int)$item['project_id'] : 0;
                if (!isset($projectSet[$projectId])) continue;
                $amount = (float)cpms_safety_cost_row_amount($item);
                $summary['used_total'] += $amount;
                $targetId = isset($item['id']) ? (string)$item['id'] : '';
                $ym = ($targetId !== '' && isset($safetyMeta[$targetId]) && cpms_company_profit_month_valid($safetyMeta[$targetId]))
                    ? $safetyMeta[$targetId]
                    : cpms_company_profit_settlement_ym_from_date(isset($item['use_date']) ? $item['use_date'] : '', false);
                if ((int)substr((string)$ym, 0, 4) === $currentYear) $summary['used_current_year'] += $amount;
            }
            $usageLoaded = true;
        } catch (Exception $e) {
            error_log('[company_profit] safety usage preload failed: ' . $e->getMessage());
        }
    }
    if (!$usageLoaded) {
        $currentYearStart = sprintf('%04d-01-01', $currentYear);
        $currentYearEnd = sprintf('%04d-12-31', $currentYear);
        foreach ($projectIds as $projectId) {
            $summary['used_total'] += function_exists('cpms_safety_cost_total') ? (float)cpms_safety_cost_total($projectId) : 0.0;
            $summary['used_current_year'] += function_exists('cpms_safety_cost_total_between') ? (float)cpms_safety_cost_total_between($projectId, $currentYearStart, $currentYearEnd) : 0.0;
        }
    }
    $summary['limit_110'] = round($summary['contract_total'] * 1.1);
    $summary['remaining'] = $summary['limit_110'] - $summary['used_total'];
    $summary['use_rate'] = ($summary['contract_total'] > 0) ? (($summary['used_total'] / $summary['contract_total']) * 100) : 0.0;
    $summary['remaining_rate'] = ($summary['limit_110'] > 0) ? (($summary['remaining'] / $summary['limit_110']) * 100) : 0.0;
    $summary['limit_use_rate'] = ($summary['limit_110'] > 0) ? (($summary['used_total'] / $summary['limit_110']) * 100) : 0.0;
    return $summary;
}}

if (!function_exists('cpms_company_profit_build_dashboard')) {
function cpms_company_profit_build_dashboard($pdo, $request) {
    $filters = cpms_company_profit_normalize_filters(is_array($request) ? $request : array(), $pdo);
    $months = cpms_company_profit_months_between($filters['start_month'], $filters['end_month']);
    if (count($months) === 0) $months = cpms_company_profit_months_between(date('Y') . '-01', date('Y') . '-12');

    $overheadByMonth = array();
    $overheadTotal = 0.0;
    $overheadHasData = false;
    $overheadCategories = array();
    foreach ($months as $ym) {
        $oneOverhead = cpms_company_overhead_summary(array($ym));
        $oneTotal = isset($oneOverhead['total']) ? (float)$oneOverhead['total'] : 0.0;
        $overheadByMonth[$ym] = $oneTotal;
        $overheadTotal += $oneTotal;
        if (!empty($oneOverhead['has_data'])) $overheadHasData = true;
        if (isset($oneOverhead['categories']) && is_array($oneOverhead['categories'])) {
            foreach ($oneOverhead['categories'] as $catKey => $catRow) {
                if (!isset($overheadCategories[$catKey])) {
                    $overheadCategories[$catKey] = array(
                        'label' => isset($catRow['label']) ? (string)$catRow['label'] : (string)$catKey,
                        'amount' => 0.0,
                        'has_data' => false,
                    );
                }
                $overheadCategories[$catKey]['amount'] += isset($catRow['amount']) ? (float)$catRow['amount'] : 0.0;
                if (!empty($catRow['has_data'])) $overheadCategories[$catKey]['has_data'] = true;
            }
        }
    }
    $overheadSummary = array(
        'total' => $overheadTotal,
        'has_data' => $overheadHasData,
        'months' => $months,
        'categories' => $overheadCategories,
        'missing_notice' => '총관리비 데이터 미등록',
    );

    $result = array(
        'filters' => $filters,
        'months' => $months,
        'db_ok' => ($pdo ? true : false),
        'projects' => array(),
        'period_rows' => array(),
        'overhead' => $overheadSummary,
        'safety_cost' => array(
            'project_count' => 0,
            'contract_total' => 0.0,
            'limit_110' => 0.0,
            'used_total' => 0.0,
            'used_current_year' => 0.0,
            'current_year' => (int)date('Y'),
            'remaining' => 0.0,
            'use_rate' => 0.0,
            'remaining_rate' => 0.0,
            'limit_use_rate' => 0.0,
        ),
        'totals' => array(
            'sales' => 0.0,
            'project_input_cost' => 0.0,
            'overhead' => 0.0,
            'total_input_cost' => 0.0,
            'target_amount' => 0.0,
            'net_profit' => 0.0,
            'cost_rate' => 0.0,
            'cost_rate_label' => '0%',
            'no_sales' => 0,
        ),
        'errors' => array(),
    );

    if (!$pdo) {
        $result['errors'][] = 'DB 연결이 없어 회사손익 데이터를 계산할 수 없습니다.';
        return $result;
    }

    $projects = cpms_company_profit_load_projects($pdo, $filters);
    $preloaded = cpms_company_profit_preload_context($pdo, $projects, $months);
    $preloaded['skip_confirmed_expected'] = !empty($request['_skip_confirmed_expected']);
    $safetyProjects = !empty($filters['project_id'])
        ? $projects
        : cpms_company_profit_load_projects($pdo, array('status' => '', 'q' => ''));
    $result['safety_cost'] = cpms_company_profit_safety_cost_total_summary($pdo, $safetyProjects);
    foreach ($projects as $project) {
        try {
            $projectSummary = cpms_company_profit_project_summary($pdo, $project, $months, $preloaded);
            $result['projects'][] = $projectSummary;
            $result['totals']['sales'] += (float)$projectSummary['sales'];
            $result['totals']['project_input_cost'] += (float)$projectSummary['input_cost'];
            $result['totals']['target_amount'] += (float)$projectSummary['target_amount'];
        } catch (Exception $e) {
            $projectName = isset($project['name']) ? (string)$project['name'] : '현장';
            error_log('[company_profit] project summary failed: ' . $projectName . ' / ' . $e->getMessage());
            $result['errors'][] = $projectName . ' 계산 중 일부 오류가 발생했습니다.';
            $fallback = array(
                'id' => isset($project['id']) ? (int)$project['id'] : 0,
                'name' => $projectName,
                'status' => isset($project['status']) ? (string)$project['status'] : '',
                'sales' => 0.0,
                'input_cost' => 0.0,
                'target_amount' => 0.0,
                'cost_rate' => 0.0,
                'cost_rate_label' => '0%',
                'net_profit' => 0.0,
                'basis' => 'none',
                'monthly' => array(),
                'error' => '계산 오류',
            );
            $result['projects'][] = $fallback;
        }
    }

    $result['totals']['overhead'] = isset($result['overhead']['total']) ? (float)$result['overhead']['total'] : 0.0;
    $result['totals']['total_input_cost'] = $result['totals']['project_input_cost'] + $result['totals']['overhead'];
    $result['totals']['net_profit'] = $result['totals']['sales'] - $result['totals']['total_input_cost'];
    $rateInfo = cpms_company_profit_cost_rate_info($result['totals']['sales'], $result['totals']['total_input_cost']);
    $result['totals']['cost_rate'] = $rateInfo['cost_rate'];
    $result['totals']['cost_rate_label'] = $rateInfo['cost_rate_label'];
    $result['totals']['no_sales'] = $rateInfo['no_sales'];

    $result['period_rows'] = cpms_company_profit_build_period_rows($result['projects'], $months, $filters['view_mode'], $overheadByMonth);

    return $result;
}}
