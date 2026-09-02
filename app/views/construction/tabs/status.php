<?php
/**
 * 공사 > 상황 탭(연도별 월/분기 비용+매출 그래프)
 * - 연도 선택 + 월별/분기별 5항목(노무/장비/안전/자재/매출) 막대그래프
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/partials/labor_data_loader.php';
require_once __DIR__ . '/partials/outsourcing_data_helper.php';
require_once __DIR__ . '/partials/sales_data_loader.php';
require_once __DIR__ . '/../../safety/safety_cost_helper.php';
require_once __DIR__ . '/../../../services/CostChangeService.php';
require_once __DIR__ . '/../partials/schedule_auto_progress_helper.php';
require_once __DIR__ . '/../partials/target_cost_rate_helper.php';

if (!function_exists('cpms_status_cache_key')) {
    function cpms_status_cache_key($pdo, $suffix) {
        $prefix = 'nopdo';
        if ($pdo && function_exists('spl_object_hash')) {
            $prefix = spl_object_hash($pdo);
        }
        return $prefix . ':' . (string)$suffix;
    }
}

if (!function_exists('cpms_cost_period_range')) {
    function cpms_cost_period_range($ym, $type) {
        $ym = trim((string)$ym);
        $type = trim((string)$type);
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) { $ym = date('Y-m'); }
        if ($type === 'labor' || $type === 'outsourcing' || $type === 'sales') {
            $start = $ym . '-01';
            $ts = strtotime($start);
            $end = date('Y-m-t', $ts);
            return array('start' => $start, 'end' => $end);
        }
        $currentStartTs = strtotime($ym . '-01');
        $prevMonthTs = strtotime('-1 month', $currentStartTs);
        $start = date('Y-m', $prevMonthTs) . '-26';
        $end = $ym . '-25';
        return array('start' => $start, 'end' => $end);
    }
}

if (!function_exists('cpms_status_table_exists')) {
    function cpms_status_table_exists($pdo, $table) {
        static $cache = array();
        static $dbNameCache = array();
        if (!$pdo) return false;
        $cacheKey = cpms_status_cache_key($pdo, 'table:' . (string)$table);
        if (isset($cache[$cacheKey])) return $cache[$cacheKey];
        try {
            $pdoKey = cpms_status_cache_key($pdo, 'db');
            if (!isset($dbNameCache[$pdoKey])) {
                $dbNameCache[$pdoKey] = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
            }
            $dbName = (string)$dbNameCache[$pdoKey];
            if ($dbName === '') return false;
            $sql = "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl";
            $st = $pdo->prepare($sql);
            $st->bindValue(':db', $dbName);
            $st->bindValue(':tbl', (string)$table);
            $st->execute();
            $cache[$cacheKey] = ((int)$st->fetchColumn() > 0);
            return $cache[$cacheKey];
        } catch (Exception $e) {
            $cache[$cacheKey] = false;
            return false;
        }
    }
}

if (!function_exists('cpms_status_column_exists')) {
    function cpms_status_column_exists($pdo, $table, $column) {
        static $cache = array();
        static $dbNameCache = array();
        if (!$pdo) return false;
        $cacheKey = cpms_status_cache_key($pdo, 'column:' . (string)$table . ':' . (string)$column);
        if (isset($cache[$cacheKey])) return $cache[$cacheKey];
        try {
            $pdoKey = cpms_status_cache_key($pdo, 'db');
            if (!isset($dbNameCache[$pdoKey])) {
                $dbNameCache[$pdoKey] = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
            }
            $dbName = (string)$dbNameCache[$pdoKey];
            if ($dbName === '') return false;
            $sql = "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl AND COLUMN_NAME = :col";
            $st = $pdo->prepare($sql);
            $st->bindValue(':db', $dbName);
            $st->bindValue(':tbl', (string)$table);
            $st->bindValue(':col', (string)$column);
            $st->execute();
            $cache[$cacheKey] = ((int)$st->fetchColumn() > 0);
            return $cache[$cacheKey];
        } catch (Exception $e) {
            $cache[$cacheKey] = false;
            return false;
        }
    }
}

if (!function_exists('cpms_status_sum_between')) {
    function cpms_status_sum_between($pdo, $table, $dateColumn, $projectId, $startDate, $endDate, $extraWhere, $extraParams) {
        static $cache = array();
        if (!$pdo) return 0;
        $paramKey = is_array($extraParams) ? md5(serialize($extraParams)) : 'noparams';
        $cacheKey = cpms_status_cache_key($pdo, 'sum-between:' . $table . ':' . $dateColumn . ':' . (int)$projectId . ':' . (string)$startDate . ':' . (string)$endDate . ':' . (string)$extraWhere . ':' . $paramKey);
        if (isset($cache[$cacheKey])) return $cache[$cacheKey];
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
            $cache[$cacheKey] = (float)$st->fetchColumn();
            return $cache[$cacheKey];
        } catch (Exception $e) {
            $cache[$cacheKey] = 0;
            return 0;
        }
    }
}

if (!function_exists('cpms_status_sum_all')) {
    function cpms_status_sum_all($pdo, $table, $projectId, $extraWhere, $extraParams) {
        static $cache = array();
        if (!$pdo) return 0;
        $paramKey = is_array($extraParams) ? md5(serialize($extraParams)) : 'noparams';
        $cacheKey = cpms_status_cache_key($pdo, 'sum-all:' . $table . ':' . (int)$projectId . ':' . (string)$extraWhere . ':' . $paramKey);
        if (isset($cache[$cacheKey])) return $cache[$cacheKey];
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
            $cache[$cacheKey] = (float)$st->fetchColumn();
            return $cache[$cacheKey];
        } catch (Exception $e) {
            $cache[$cacheKey] = 0;
            return 0;
        }
    }
}

if (!function_exists('cpms_status_equipment_total_between')) {
    function cpms_status_equipment_total_between($pdo, $projectId, $startDate, $endDate) {
        static $cache = array();
        if (!$pdo) return 0.0;
        $cacheKey = cpms_status_cache_key($pdo, 'equipment-between:' . (int)$projectId . ':' . (string)$startDate . ':' . (string)$endDate);
        if (isset($cache[$cacheKey])) return $cache[$cacheKey];
        if (!cpms_status_table_exists($pdo, 'cpms_equipment_usage')) return 0.0;
        if (!cpms_status_column_exists($pdo, 'cpms_equipment_usage', 'project_id')) return 0.0;
        if (!cpms_status_column_exists($pdo, 'cpms_equipment_usage', 'use_date')) return 0.0;

        $hasWorkUnit = cpms_status_column_exists($pdo, 'cpms_equipment_usage', 'work_unit');
        $hasBaseRate = cpms_status_column_exists($pdo, 'cpms_equipment_usage', 'base_rate_snapshot');
        $hasAmount = cpms_status_column_exists($pdo, 'cpms_equipment_usage', 'amount');
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
            if (
                cpms_status_table_exists($pdo, 'cpms_equipment_items') &&
                cpms_status_column_exists($pdo, 'cpms_equipment_usage', 'equipment_id') &&
                cpms_status_column_exists($pdo, 'cpms_equipment_items', 'is_deleted')
            ) {
                $fromSql .= " INNER JOIN cpms_equipment_items e ON e.id = u.equipment_id AND e.project_id = u.project_id";
                $deletedWhere .= " AND (e.is_deleted = 0 OR e.is_deleted IS NULL)";
            }
            if ($hasWorkUnit && $hasBaseRate) {
                if ($hasAmount) {
                    $amountExpr = "COALESCE(NULLIF(u.amount, 0), COALESCE(NULLIF(u.work_unit, 0), 1) * COALESCE(NULLIF(u.base_rate_snapshot, 0), 0))";
                } else {
                    $amountExpr = "COALESCE(NULLIF(u.work_unit, 0), 1) * COALESCE(NULLIF(u.base_rate_snapshot, 0), 0)";
                }
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
            $cache[$cacheKey] = 0.0;
            return 0.0;
        }
    }
}

if (!function_exists('cpms_status_material_category_sum_between')) {
    function cpms_status_material_category_sum_between($pdo, $projectId, $startDate, $endDate) {
        $result = array('자재비'=>0.0, '구매품'=>0.0, '기타경비'=>0.0, '안전관리비'=>0.0);
        static $cache = array();
        if (!$pdo) return $result;
        $cacheKey = cpms_status_cache_key($pdo, 'material-between:' . (int)$projectId . ':' . (string)$startDate . ':' . (string)$endDate);
        if (isset($cache[$cacheKey])) return $cache[$cacheKey];
        if (!cpms_status_table_exists($pdo, 'cpms_material_usage')) return $result;
        if (!cpms_status_table_exists($pdo, 'cpms_material_items')) return $result;
        if (!cpms_status_column_exists($pdo, 'cpms_material_usage', 'project_id')) return $result;
        if (!cpms_status_column_exists($pdo, 'cpms_material_usage', 'use_date')) return $result;
        if (!cpms_status_column_exists($pdo, 'cpms_material_usage', 'amount')) return $result;

        try {
            $deletedWhere = cpms_status_column_exists($pdo, 'cpms_material_items', 'is_deleted') ? " AND (i.is_deleted = 0 OR i.is_deleted IS NULL)" : "";
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
            foreach ($rows as $r) {
                $cat = trim((string)(isset($r['category']) ? $r['category'] : ''));
                if (!isset($result[$cat])) $cat = '자재비';
                $result[$cat] += isset($r['amount']) ? (float)$r['amount'] : 0.0;
            }
        } catch (Exception $e) {
            $cache[$cacheKey] = $result;
            return $result;
        }
        $cache[$cacheKey] = $result;
        return $result;
    }
}

if (!function_exists('cpms_status_money')) {
    function cpms_status_money($amount) {
        return number_format((float)$amount) . '원';
    }
}

if (!function_exists('cpms_status_confirmed_money')) {
    function cpms_status_confirmed_money($amount) {
        return ((float)$amount > 0) ? cpms_status_money($amount) : '기성 없음';
    }
}

if (!function_exists('cpms_status_cost_rate_info')) {
    function cpms_status_cost_rate_info($sales, $usedTotal) {
        $sales = (float)$sales;
        $usedTotal = (float)$usedTotal;
        if ($sales > 0) {
            $rate = ($usedTotal / $sales) * 100;
            return array(
                'cost_rate' => $rate,
                'cost_rate_label' => number_format($rate, 1) . '%',
                'no_sales' => 0,
            );
        }
        if ($usedTotal > 0) {
            return array(
                // 0원 매출을 큰 원가율 숫자로 치환하지 않고 상태값으로만 표시한다.
                'cost_rate' => 0.0,
                'cost_rate_label' => '매출 없음',
                'no_sales' => 1,
            );
        }
        return array(
            'cost_rate' => 0.0,
            'cost_rate_label' => '0%',
            'no_sales' => 0,
        );
    }
}

if (!function_exists('cpms_status_target_cost_amount')) {
    function cpms_status_target_cost_amount($sales, $targetRate) {
        $sales = (float)$sales;
        $targetRate = (float)$targetRate;
        if ($sales <= 0 || $targetRate <= 0) return 0.0;
        return round($sales * ($targetRate / 100));
    }
}

if (!function_exists('cpms_status_target_cost_rate_with_amount')) {
    function cpms_status_target_cost_rate_with_amount($targetRate, $targetAmount) {
        $targetRate = (float)$targetRate;
        if ($targetRate <= 0) return '-';
        return cpms_target_cost_rate_format($targetRate) . ' (' . cpms_status_money($targetAmount) . ')';
    }
}

if (!function_exists('cpms_status_ym_valid')) {
    function cpms_status_ym_valid($ym) {
        return preg_match('/^\d{4}-\d{2}$/', (string)$ym) ? true : false;
    }
}

if (!function_exists('cpms_status_months_between')) {
    function cpms_status_months_between($fromYm, $toYm) {
        $months = array();
        if (!cpms_status_ym_valid($fromYm) || !cpms_status_ym_valid($toYm)) return $months;
        try {
            $cursor = new DateTime($fromYm . '-01');
            $end = new DateTime($toYm . '-01');
        } catch (Exception $e) {
            return $months;
        }
        $guard = 0;
        while ($cursor <= $end && $guard < 240) {
            $months[] = $cursor->format('Y-m');
            $cursor->modify('+1 month');
            $guard++;
        }
        return $months;
    }
}

if (!function_exists('cpms_status_ym_label')) {
    function cpms_status_ym_label($ym) {
        if (!cpms_status_ym_valid($ym)) return (string)$ym;
        return substr((string)$ym, 2, 2) . '년 ' . substr((string)$ym, 5, 2) . '월';
    }
}

if (!function_exists('cpms_status_rate_class')) {
    function cpms_status_rate_class($costRate, $noSales) {
        $costRate = (float)$costRate;
        $noSales = (int)$noSales;
        if ($noSales === 1 || $costRate > 100) return 'text-red-700 bg-red-50 border-red-100';
        if ($costRate > 70) return 'text-orange-700 bg-orange-50 border-orange-100';
        return 'text-blue-700 bg-blue-50 border-blue-100';
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
        static $cache = array();
        $cacheKey = cpms_status_cache_key($pdo, 'labor-wage-map:' . (int)$projectId);
        if (isset($cache[$cacheKey])) return $cache[$cacheKey];

        $directTeamMembers = cpms_load_direct_team_members($pdo);
        $projectWorkers = cpms_load_project_labor_workers($pdo, (int)$projectId);
        $workerRows = cpms_build_project_worker_rows($projectWorkers, $directTeamMembers);
        $timesheetWorkers = cpms_build_timesheet_workers($workerRows);

        foreach ($timesheetWorkers as $worker) {
            $name = isset($worker['name']) ? (string)$worker['name'] : '';
            $key = cpms_normalize_worker_key($name);
            if ($key === '') continue;
            if (function_exists('cpms_resolve_labor_wage_rate')) {
                $wageMap[$key] = (float)cpms_resolve_labor_wage_rate($worker);
            } else {
                $wageRateRaw = isset($worker['deposit_rate']) ? (string)$worker['deposit_rate'] : '';
                $wageMap[$key] = cpms_status_parse_money($wageRateRaw);
            }
        }

        $cache[$cacheKey] = $wageMap;
        return $wageMap;
    }
}

if (!function_exists('cpms_status_labor_total_between')) {
    function cpms_status_labor_total_between($pdo, $projectId, $projectName, $startDate, $endDate, $laborWageMap, $outsourcingOnly = false) {
        static $cache = array();
        if (!$pdo || $projectId <= 0) return 0.0;
        $cacheKey = cpms_status_cache_key($pdo, 'labor-between:' . (int)$projectId . ':' . trim((string)$projectName) . ':' . (string)$startDate . ':' . (string)$endDate . ':' . ($outsourcingOnly ? 'outsourcing' : 'labor'));
        if (isset($cache[$cacheKey])) return $cache[$cacheKey];
        $projectName = trim((string)$projectName);
        if ($projectName === '') return 0.0;

        try {
            $startObj = new DateTime((string)$startDate);
            $endObj = new DateTime((string)$endDate);
        } catch (Exception $e) {
            return 0.0;
        }

        if ($startObj > $endObj) {
            $cache[$cacheKey] = 0.0;
            return 0.0;
        }

        $months = array();
        $cursor = clone $startObj;
        while ($cursor <= $endObj) {
            $months[$cursor->format('Y-m')] = true;
            $cursor->modify('+1 day');
        }

        $directTeamMembers = cpms_load_direct_team_members($pdo);
        $baseProjectWorkers = cpms_load_project_labor_workers($pdo, (int)$projectId);
        $totalLabor = 0.0;

        foreach ($months as $ym => $unused) {
            $gongsuData = cpms_load_gongsu_data($pdo, $projectName, $ym);
            $gongsuMap = isset($gongsuData['gongsu_map']) && is_array($gongsuData['gongsu_map']) ? $gongsuData['gongsu_map'] : array();
            $gongsuMap = cpms_status_apply_overrides($gongsuMap, (int)$projectId, $ym);

            // 파일: app/views/construction/tabs/status.php
            // 적용 월마다 근로자 비율이 다를 수 있으므로 월 단위로 비율 맵을 다시 구성합니다.
            $ratioMap = cpms_load_project_labor_worker_month_ratio_map($pdo, (int)$projectId, $ym, $baseProjectWorkers);
            $monthProjectWorkers = cpms_apply_project_labor_worker_month_ratios($baseProjectWorkers, $ratioMap);
            $monthProjectWorkers = cpms_apply_project_labor_worker_month_wages($monthProjectWorkers, cpms_load_project_labor_worker_wage_map($pdo, (int)$projectId, $ym));
            $workerRows = cpms_build_project_worker_rows($monthProjectWorkers, $directTeamMembers, $pdo, $ym);
            $timesheetWorkers = cpms_build_timesheet_workers($workerRows);
            $workerAllocationMap = array();
            foreach ($timesheetWorkers as $timesheetWorker) {
                $timesheetWorkerName = isset($timesheetWorker['name']) ? (string)$timesheetWorker['name'] : '';
                $timesheetWorkerKey = cpms_normalize_worker_key($timesheetWorkerName);
                if ($timesheetWorkerKey === '') continue;
                $workerAllocationMap[$timesheetWorkerKey] = $timesheetWorker;
            }

            foreach ($gongsuMap as $workerKey => $dailyMap) {
                if (!is_array($dailyMap)) continue;
                $workerMonthGongsu = 0.0;

                foreach ($dailyMap as $dateKey => $gongsuValue) {
                    if (!is_numeric($gongsuValue)) continue;
                    if ((string)$dateKey < (string)$startDate || (string)$dateKey > (string)$endDate) continue;
                    $workerMonthGongsu += (float)$gongsuValue;
                }
                if ($workerMonthGongsu <= 0) continue;
                $wageRate = isset($laborWageMap[$workerKey]) ? (float)$laborWageMap[$workerKey] : 0.0;
                $allocationWorker = isset($workerAllocationMap[$workerKey]) && is_array($workerAllocationMap[$workerKey]) ? $workerAllocationMap[$workerKey] : array('name'=>$workerKey, 'deposit_rate'=>$wageRate, 'outsourcing_ratio'=>0);
                $amounts = cpms_labor_calculate_worker_period_amounts($allocationWorker, $dailyMap, $startDate, $endDate);
                if ($outsourcingOnly) {
                    $totalLabor += isset($amounts['outsourcing_amount']) ? (float)$amounts['outsourcing_amount'] : 0.0;
                } else {
                    $totalLabor += isset($amounts['labor_amount']) ? (float)$amounts['labor_amount'] : 0.0;
                }
            }
        }

        if (!$outsourcingOnly && function_exists('cpms_labor_force_amount_between')) {
            $totalLabor += cpms_labor_force_amount_between($pdo, (int)$projectId, $startDate, $endDate);
        }

        $cache[$cacheKey] = (float)$totalLabor;
        return $cache[$cacheKey];
    }
}

if (!function_exists('cpms_status_sales_total_between')) {
    function cpms_status_sales_total_between($pdo, $projectId, $startDate, $endDate) {
        $res = cpms_sales_total_between($pdo, $projectId, $startDate, $endDate);
        return isset($res['amount']) ? (float)$res['amount'] : 0.0;
    }
}

if (!function_exists('cpms_status_sales_total_all')) {
    function cpms_status_sales_total_all($pdo, $projectId) {
        return (float)cpms_sales_total_all($pdo, $projectId);
    }
}

if (!function_exists('cpms_status_confirmed_sales_total_between')) {
    function cpms_status_confirmed_sales_total_between($pdo, $projectId, $startDate, $endDate) {
        if (!function_exists('cpms_confirmed_sales_total_between')) return 0.0;
        return (float)cpms_confirmed_sales_total_between($pdo, $projectId, $startDate, $endDate);
    }
}

if (!function_exists('cpms_status_confirmed_sales_total_all')) {
    function cpms_status_confirmed_sales_total_all($pdo, $projectId) {
        if (!function_exists('cpms_confirmed_sales_total_all')) return 0.0;
        return (float)cpms_confirmed_sales_total_all($pdo, $projectId);
    }
}

$projectStartDate = isset($projectRow['start_date']) ? (string)$projectRow['start_date'] : date('Y-m-d');
$projectEndDate = isset($projectRow['end_date']) ? (string)$projectRow['end_date'] : date('Y-m-d');
$projectName = isset($projectRow['name']) ? (string)$projectRow['name'] : '';
if (isset($pdo) && isset($pid) && function_exists('cpms_schedule_apply_auto_progress')) {
    cpms_schedule_apply_auto_progress($pdo, (int)$pid);
}

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

$currentYm = date('Y-m');
try {
    $currentDate = new DateTime('now', new DateTimeZone('Asia/Seoul'));
    $currentYm = $currentDate->format('Y-m');
} catch (Exception $e) {
    $currentYm = date('Y-m');
}
$fromYm = isset($_GET['from_ym']) ? trim((string)$_GET['from_ym']) : '';
$toYm = $currentYm;
if (!cpms_status_ym_valid($fromYm)) {
    $fromYm = sprintf('%04d-01', $selectedYear);
}
if ($fromYm > $toYm) {
    $fromYm = $toYm;
}
$periodMonths = cpms_status_months_between($fromYm, $toYm);
if (count($periodMonths) === 0) {
    $fromYm = $toYm;
    $periodMonths = cpms_status_months_between($fromYm, $toYm);
}
$periodLabel = cpms_status_ym_label($fromYm) . ' ~ ' . cpms_status_ym_label($toYm);

cpms_target_cost_rate_ensure_schema($pdo);
$targetRateRow = cpms_target_cost_rate_current($pdo, (int)$pid);
$targetRateValue = ($targetRateRow && isset($targetRateRow['target_rate'])) ? (float)$targetRateRow['target_rate'] : 0.0;
$targetRateByMonth = cpms_target_cost_rate_effective_map($pdo, (int)$pid, $periodMonths, $targetRateValue);
$pendingTargetRateRequest = cpms_target_cost_rate_pending($pdo, (int)$pid);
$canEditTargetRate = class_exists('App\\Core\\Auth') ? \App\Core\Auth::canManageConstruction() : false;
$canApproveTargetRate = cpms_target_cost_rate_is_vp_user($pdo);

// 상황탭 매출 추가/색상변경/상단금액구조 변경
$categories = array(
    'sales' => array('label' => '매출(확정우선)', 'color' => '#3B82F6'),
    'used_total' => array('label' => '투입원가', 'color' => '#F97316'),
    'labor' => array('label' => '노무비', 'color' => '#FACC15'),
    'equipment' => array('label' => '장비비', 'color' => '#EF4444'),
    'safety' => array('label' => '안전관리비', 'color' => '#22C55E'),
    'materials' => array('label' => '자재구입비', 'color' => '#A855F7'),
    'outsourcing' => array('label' => '외주비', 'color' => '#06B6D4'),
    'purchase' => array('label' => '구매품', 'color' => '#EC4899'),
);

$laborWageMap = cpms_status_labor_wage_map($pdo, (int)$pid);
$debugMode = isset($_GET['debug']) && (string)$_GET['debug'] === '1';
$periodDiagnostics = array();

$monthlyData = array();
$yearTotals = array('labor' => 0, 'outsourcing' => 0, 'equipment' => 0, 'safety' => 0, 'materials' => 0, 'purchase' => 0, 'sales' => 0, 'expected_sales' => 0, 'confirmed_sales' => 0, 'used_total' => 0, 'target_cost_amount' => 0, 'profit' => 0);
$maxMonthlyValue = 0;

foreach ($periodMonths as $ym) {
    $m = (int)substr($ym, 5, 2);
    $rowYear = (int)substr($ym, 0, 4);
    $laborRange = cpms_cost_period_range($ym, 'labor');
    $outsourcingRange = cpms_cost_period_range($ym, 'outsourcing');
    $costRange = cpms_cost_period_range($ym, 'material');
    $salesRange = cpms_cost_period_range($ym, 'sales');

    $laborStart = isset($laborRange['start']) ? (string)$laborRange['start'] : '';
    $laborEnd = isset($laborRange['end']) ? (string)$laborRange['end'] : '';
    $outsourcingStart = isset($outsourcingRange['start']) ? (string)$outsourcingRange['start'] : '';
    $outsourcingEnd = isset($outsourcingRange['end']) ? (string)$outsourcingRange['end'] : '';
    $costStart = isset($costRange['start']) ? (string)$costRange['start'] : '';
    $costEnd = isset($costRange['end']) ? (string)$costRange['end'] : '';
    $salesStart = isset($salesRange['start']) ? (string)$salesRange['start'] : '';
    $salesEnd = isset($salesRange['end']) ? (string)$salesRange['end'] : '';
    if ($debugMode) {
        $periodDiagnostics[] = $ym . ' 매출 기간: ' . $salesStart . ' ~ ' . $salesEnd;
        $periodDiagnostics[] = $ym . ' 노무비 기간: ' . $laborStart . ' ~ ' . $laborEnd;
        $periodDiagnostics[] = $ym . ' 자재/장비 기간: ' . $costStart . ' ~ ' . $costEnd;
    }

    $equipment = cpms_status_equipment_total_between($pdo, $pid, $costStart, $costEnd);
    $materialByCategory = cpms_status_material_category_sum_between($pdo, $pid, $costStart, $costEnd);
    $materials = (float)$materialByCategory['자재비'] + (float)$materialByCategory['기타경비'];
    $purchase = (float)$materialByCategory['구매품'];
    $safety = cpms_safety_cost_total_between((int)$pid, $costStart, $costEnd);

    // 상황탭 노무비/외주비는 월별 비용 배분 반영금액을 각각 합산합니다.
    $labor = cpms_status_labor_total_between($pdo, (int)$pid, $projectName, $laborStart, $laborEnd, $laborWageMap);
    $outsourcingLabor = cpms_status_labor_total_between($pdo, (int)$pid, $projectName, $laborStart, $laborEnd, $laborWageMap, true);
    $outsourcingManual = cpms_outsourcing_manual_total_between($pdo, (int)$pid, $outsourcingStart, $outsourcingEnd);
    $outsourcing = $outsourcingLabor + $outsourcingManual;

    // 상황탭 매출 추가/색상변경/상단금액구조 변경: 완료 공정 기준 매출 인식
    $expectedSales = cpms_status_sales_total_between($pdo, (int)$pid, $salesStart, $salesEnd);
    $confirmedSales = cpms_status_confirmed_sales_total_between($pdo, (int)$pid, $salesStart, $salesEnd);
    // 상황 탭에서는 0원 기성을 '기성 없음'으로 보고 예상매출 기준 원가율을 유지한다.
    $hasConfirmedSales = ($confirmedSales > 0);
    $sales = $hasConfirmedSales ? $confirmedSales : $expectedSales;
    $targetCostRate = isset($targetRateByMonth[$ym]) ? (float)$targetRateByMonth[$ym] : $targetRateValue;
    $targetCostAmount = cpms_status_target_cost_amount($sales, $targetCostRate);
    $usedTotal = $labor + $outsourcing + $equipment + $materials + $purchase + $safety;
    $profit = $sales - $usedTotal;
    $costRateInfo = cpms_status_cost_rate_info($sales, $usedTotal);

    $row = array(
        'ym' => $ym,
        'year' => $rowYear,
        'month' => $m,
        'label' => cpms_status_ym_label($ym),
        'start' => $costStart,
        'end' => $costEnd,
        'cost_start' => $costStart,
        'cost_end' => $costEnd,
        'labor_start' => $laborStart,
        'labor_end' => $laborEnd,
        'sales_start' => $salesStart,
        'sales_end' => $salesEnd,
        'labor' => $labor,
        'outsourcing' => $outsourcing,
        'equipment' => $equipment,
        'materials' => $materials,
        'purchase' => $purchase,
        'safety' => $safety,
        'sales' => $sales,
        'expected_sales' => $expectedSales,
        'confirmed_sales' => $confirmedSales,
        'has_confirmed_sales' => $hasConfirmedSales ? 1 : 0,
        'used_total' => $usedTotal,
        'target_cost_rate' => $targetCostRate,
        'target_cost_rate_label' => cpms_target_cost_rate_format($targetCostRate),
        'target_cost_amount' => $targetCostAmount,
        'profit' => $profit,
        'cost_rate' => $costRateInfo['cost_rate'],
        'cost_rate_label' => $costRateInfo['cost_rate_label'],
        'no_sales' => $costRateInfo['no_sales'],
        'stackedCostTotal' => $materials + $safety + $outsourcing + $purchase,
    );

    foreach ($yearTotals as $key => $sumValue) {
        $yearTotals[$key] += isset($row[$key]) ? (float)$row[$key] : 0;
    }
    foreach (array('sales', 'used_total', 'labor', 'equipment') as $barKey) {
        if (isset($row[$barKey]) && (float)$row[$barKey] > $maxMonthlyValue) $maxMonthlyValue = (float)$row[$barKey];
    }
    if ((float)$row['stackedCostTotal'] > $maxMonthlyValue) {
        $maxMonthlyValue = (float)$row['stackedCostTotal'];
    }

    $monthlyData[] = $row;
}

$quarterlyData = array();
$maxQuarterValue = 0;
$quarterlyMap = array();
foreach ($monthlyData as $mRow) {
    $mYear = isset($mRow['year']) ? (int)$mRow['year'] : $selectedYear;
    $mm = isset($mRow['month']) ? (int)$mRow['month'] : 0;
    if ($mm <= 0) continue;
    $q = (int)ceil($mm / 3);
    $qKey = sprintf('%04d-Q%d', $mYear, $q);
    if (!isset($quarterlyMap[$qKey])) {
        $quarterlyMap[$qKey] = array(
            'quarter' => $q,
            'year' => $mYear,
            'label' => substr((string)$mYear, 2, 2) . '년 ' . $q . 'Q',
            'labor' => 0,
            'outsourcing' => 0,
            'equipment' => 0,
            'safety' => 0,
            'materials' => 0,
            'purchase' => 0,
            'sales' => 0,
            'expected_sales' => 0,
            'confirmed_sales' => 0,
            'used_total' => 0,
            'target_cost_amount' => 0,
            'profit' => 0,
            'cost_rate' => 0,
            'cost_rate_label' => '0%',
            'no_sales' => 0,
            'stackedCostTotal' => 0,
        );
    }

    foreach ($yearTotals as $key => $ignored) {
        $quarterlyMap[$qKey][$key] += isset($mRow[$key]) ? (float)$mRow[$key] : 0;
    }
}

foreach ($quarterlyMap as $qRow) {
    $qRow['stackedCostTotal'] = (float)$qRow['materials'] + (float)$qRow['safety'] + (float)$qRow['outsourcing'] + (float)$qRow['purchase'];
    $qCostRateInfo = cpms_status_cost_rate_info(isset($qRow['sales']) ? $qRow['sales'] : 0, isset($qRow['used_total']) ? $qRow['used_total'] : 0);
    $qRow['cost_rate'] = $qCostRateInfo['cost_rate'];
    $qRow['cost_rate_label'] = $qCostRateInfo['cost_rate_label'];
    $qRow['no_sales'] = $qCostRateInfo['no_sales'];
    foreach (array('sales', 'used_total', 'labor', 'equipment') as $barKey) {
        if (isset($qRow[$barKey]) && (float)$qRow[$barKey] > $maxQuarterValue) $maxQuarterValue = (float)$qRow[$barKey];
    }
    if ((float)$qRow['stackedCostTotal'] > $maxQuarterValue) {
        $maxQuarterValue = (float)$qRow['stackedCostTotal'];
    }
    $quarterlyData[] = $qRow;
}

// 상황탭 매출 추가/색상변경/상단금액구조 변경: 상단 전체 누적 금액(연도 변경과 무관)
$overallTotals = array('labor' => 0, 'outsourcing' => 0, 'equipment' => 0, 'safety' => 0, 'materials' => 0, 'purchase' => 0, 'sales' => 0, 'expected_sales' => 0, 'confirmed_sales' => 0);
foreach ($years as $yy) {
    for ($m = 1; $m <= 12; $m++) {
        $ym = sprintf('%04d-%02d', (int)$yy, $m);
        $laborRange = cpms_cost_period_range($ym, 'labor');
        $outsourcingRange = cpms_cost_period_range($ym, 'outsourcing');
        $costRange = cpms_cost_period_range($ym, 'material');
        $salesRange = cpms_cost_period_range($ym, 'sales');

        $laborStart = isset($laborRange['start']) ? (string)$laborRange['start'] : '';
        $laborEnd = isset($laborRange['end']) ? (string)$laborRange['end'] : '';
        $outsourcingStart = isset($outsourcingRange['start']) ? (string)$outsourcingRange['start'] : '';
        $outsourcingEnd = isset($outsourcingRange['end']) ? (string)$outsourcingRange['end'] : '';
        $costStart = isset($costRange['start']) ? (string)$costRange['start'] : '';
        $costEnd = isset($costRange['end']) ? (string)$costRange['end'] : '';
        $salesStart = isset($salesRange['start']) ? (string)$salesRange['start'] : '';
        $salesEnd = isset($salesRange['end']) ? (string)$salesRange['end'] : '';

        $overallTotals['equipment'] += cpms_status_equipment_total_between($pdo, $pid, $costStart, $costEnd);
        $overallMaterialByCategory = cpms_status_material_category_sum_between($pdo, $pid, $costStart, $costEnd);
        $overallTotals['materials'] += (float)$overallMaterialByCategory['자재비'] + (float)$overallMaterialByCategory['기타경비'];
        $overallTotals['purchase'] += (float)$overallMaterialByCategory['구매품'];
        $overallTotals['labor'] += cpms_status_labor_total_between($pdo, (int)$pid, $projectName, $laborStart, $laborEnd, $laborWageMap);
        $overallTotals['outsourcing'] += cpms_status_labor_total_between($pdo, (int)$pid, $projectName, $laborStart, $laborEnd, $laborWageMap, true);
        $overallTotals['outsourcing'] += cpms_outsourcing_manual_total_between($pdo, (int)$pid, $outsourcingStart, $outsourcingEnd);

        $overallExpectedSales = cpms_status_sales_total_between($pdo, (int)$pid, $salesStart, $salesEnd);
        $overallConfirmedSales = cpms_status_confirmed_sales_total_between($pdo, (int)$pid, $salesStart, $salesEnd);
        $overallTotals['expected_sales'] += $overallExpectedSales;
        $overallTotals['confirmed_sales'] += $overallConfirmedSales;
        $overallTotals['sales'] += ($overallConfirmedSales > 0) ? $overallConfirmedSales : $overallExpectedSales;
    }
}
$currentOutsourcingRange = cpms_cost_period_range($currentYm, 'outsourcing');
$currentOutsourcingStart = isset($currentOutsourcingRange['start']) ? (string)$currentOutsourcingRange['start'] : ($currentYm . '-01');
$currentOutsourcingEnd = isset($currentOutsourcingRange['end']) ? (string)$currentOutsourcingRange['end'] : date('Y-m-t', strtotime($currentYm . '-01'));
$currentOutsourcingTotal = cpms_status_labor_total_between($pdo, (int)$pid, $projectName, $currentOutsourcingStart, $currentOutsourcingEnd, $laborWageMap, true);
$currentOutsourcingTotal += cpms_outsourcing_manual_total_between($pdo, (int)$pid, $currentOutsourcingStart, $currentOutsourcingEnd);
$overallInputCostTotal = $overallTotals['labor'] + $overallTotals['outsourcing'] + $overallTotals['equipment'] + $overallTotals['materials'] + $overallTotals['purchase'];
$overallTargetCostAmount = cpms_status_target_cost_amount($overallTotals['sales'], $targetRateValue);
$overallCostRateInfo = cpms_status_cost_rate_info($overallTotals['sales'], $overallInputCostTotal);
$overallNetTotal = $overallTotals['sales'] - $overallInputCostTotal;
$periodCostRateInfo = cpms_status_cost_rate_info($yearTotals['sales'], $yearTotals['used_total']);

if ($maxMonthlyValue <= 0) $maxMonthlyValue = 1;
if ($maxQuarterValue <= 0) $maxQuarterValue = 1;
?>

<style>
.cpms-status-wrap .card { border:1px solid #e5e7eb; border-radius:16px; background:#fff; }
.cpms-status-wrap .summary-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
.cpms-status-wrap .summary-grid > div { min-width:0; }
.cpms-status-wrap .chart-wrap { border:1px solid #e5e7eb; border-radius:16px; padding:16px; background:#fff; }
.cpms-status-wrap .chart-scroll { overflow-x:auto; }
.cpms-status-wrap .chart-row { min-width:900px; height:280px; display:flex; align-items:flex-end; gap:12px; padding:8px 4px 0 4px; border-bottom:1px solid #e5e7eb; }
.cpms-status-wrap .group { flex:1; min-width:55px; display:flex; flex-direction:column; align-items:center; }
.cpms-status-wrap .bars { width:100%; height:230px; display:flex; align-items:flex-end; justify-content:center; gap:4px; }
.cpms-status-wrap .bar { width:18%; min-width:9px; border-radius:6px 6px 0 0; position:relative; }
.cpms-status-wrap .bar .value { position:absolute; top:-20px; left:50%; transform:translateX(-50%); font-size:10px; color:#374151; white-space:nowrap; }
.cpms-status-wrap .bar.stacked { display:flex; flex-direction:column-reverse; overflow:hidden; }
.cpms-status-wrap .bar.stacked .segment { width:100%; }
.cpms-status-wrap .xlabel { margin-top:8px; font-size:12px; color:#4b5563; font-weight:700; }
.cpms-status-wrap .legend { display:flex; flex-wrap:wrap; gap:10px; margin-top:10px; }
.cpms-status-wrap .legend-item { display:flex; align-items:center; gap:6px; font-size:12px; color:#374151; }
.cpms-status-wrap .summary-grid .text-lg,
.cpms-status-wrap .summary-grid .text-3xl { word-break:keep-all; overflow-wrap:anywhere; }
.cpms-status-wrap .cpms-status-rate-badge { min-width:52px; }
.cpms-status-wrap .dot { width:12px; height:12px; border-radius:3px; display:inline-block; }
.cpms-status-wrap .target-rate-card input,
.cpms-status-wrap .target-rate-card textarea { font-size:12px; }
.cpms-status-wrap .target-rate-card textarea { min-height:38px; resize:vertical; }
@media (max-width: 980px) {
    .cpms-status-wrap .summary-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
}
@media (max-width: 767px) {
    .cpms-status-wrap .card { border-radius:16px; }
    .cpms-status-wrap .summary-grid { grid-template-columns:repeat(2,minmax(0,1fr)); gap:8px; }
    .cpms-status-wrap .summary-grid > div { padding:clamp(8px, 2.6vw, 12px); }
    .cpms-status-wrap .summary-grid .text-xs { font-size:clamp(10px, 2.8vw, 12px); line-height:1.35; }
    .cpms-status-wrap .summary-grid .text-lg { font-size:clamp(12px, 3.8vw, 16px); line-height:1.35; }
    .cpms-status-wrap .chart-wrap { padding:12px; border-radius:16px; }
    .cpms-status-wrap .chart-row { min-width:760px; height:230px; gap:8px; }
    .cpms-status-wrap .chart-row[style] { min-width:520px !important; }
    .cpms-status-wrap .bars { height:184px; gap:3px; }
    .cpms-status-wrap .bar .value { display:none; }
    .cpms-status-wrap .legend { gap:8px; }
    .cpms-status-wrap .legend-item { font-size:11px; }
    .cpms-status-wrap .cpms-status-filter { width:100%; justify-content:stretch; }
    .cpms-status-wrap .cpms-status-monthly-chart .chart-scroll,
    .cpms-status-wrap .cpms-status-monthly-chart .legend,
    .cpms-status-wrap .cpms-status-quarterly-chart {
        display:none !important;
    }
    .cpms-status-wrap .cpms-status-mobile-table {
        min-width:0;
    }
    .cpms-status-wrap .cpms-status-mobile-table th:nth-child(2),
    .cpms-status-wrap .cpms-status-mobile-table th:nth-child(3),
    .cpms-status-wrap .cpms-status-mobile-table th:nth-child(4),
    .cpms-status-wrap .cpms-status-mobile-table th:nth-child(5),
    .cpms-status-wrap .cpms-status-mobile-table th:nth-child(6),
    .cpms-status-wrap .cpms-status-mobile-table td:nth-child(2),
    .cpms-status-wrap .cpms-status-mobile-table td:nth-child(3),
    .cpms-status-wrap .cpms-status-mobile-table td:nth-child(4),
    .cpms-status-wrap .cpms-status-mobile-table td:nth-child(5),
    .cpms-status-wrap .cpms-status-mobile-table td:nth-child(6) {
        display:none !important;
    }
    .cpms-status-wrap .cpms-status-mobile-table th,
    .cpms-status-wrap .cpms-status-mobile-table td {
        white-space:normal;
    }
}
</style>

<div class="cpms-status-wrap space-y-4">
    <div class="card p-5">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h3 class="text-xl font-extrabold text-gray-900">상황</h3>
            </div>
        </div>

        <!-- 상황탭 매출 추가/색상변경/상단금액구조 변경 -->
        <div class="mt-4 p-4 rounded-2xl bg-gray-900 text-white">
            <div class="text-sm text-gray-200">손익 (확정 우선 매출 - 총 투입원가)</div>
            <div class="text-3xl font-extrabold mt-1"><?php echo h(cpms_status_money($overallNetTotal)); ?></div>
        </div>

        <div class="summary-grid mt-3">
            <div class="p-3 rounded-xl" style="border:1px solid #e5e7eb;">
                <div class="text-xs text-gray-500">총 적용매출 (확정 우선)</div>
                <div class="text-lg font-extrabold text-gray-900"><?php echo h(cpms_status_money($overallTotals['sales'])); ?></div>
            </div>
            <div class="p-3 rounded-xl" style="border:1px solid #e5e7eb;">
                <div class="text-xs text-gray-500">총 확정매출</div>
                <div class="text-lg font-extrabold text-gray-900"><?php echo h(cpms_status_confirmed_money($overallTotals['confirmed_sales'])); ?></div>
            </div>
            <div class="p-3 rounded-xl" style="border:1px solid #e5e7eb;">
                <div class="text-xs text-gray-500">총 예상매출</div>
                <div class="text-lg font-extrabold text-gray-900"><?php echo h(cpms_status_money($overallTotals['expected_sales'])); ?></div>
            </div>
            <div class="p-3 rounded-xl" style="border:1px solid #e5e7eb;">
                <div class="text-xs text-gray-500">총 투입원가</div>
                <div class="text-lg font-extrabold text-gray-900"><?php echo h(cpms_status_money($overallInputCostTotal)); ?></div>
            </div>
            <div class="p-3 rounded-xl" style="border:1px solid #e5e7eb;">
                <div class="text-xs text-gray-500">총원가율(현재)</div>
                <div class="text-lg font-extrabold text-gray-900"><?php echo h($overallCostRateInfo['cost_rate_label']); ?></div>
            </div>
            <div class="target-rate-card p-3 rounded-xl" style="border:1px solid #e5e7eb;">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <div class="text-xs text-gray-500">목표원가율</div>
                        <div class="text-lg font-extrabold text-gray-900"><?php echo h(cpms_status_target_cost_rate_with_amount($targetRateValue, $overallTargetCostAmount)); ?></div>
                    </div>
                    <?php if ($pendingTargetRateRequest): ?>
                        <span class="px-2 py-1 rounded-lg bg-amber-50 text-amber-700 border border-amber-100 text-xs font-extrabold">승인대기</span>
                    <?php endif; ?>
                </div>
                <?php if ($pendingTargetRateRequest): ?>
                    <div class="mt-2 text-xs text-gray-700">
                        <?php echo h(cpms_target_cost_rate_format(isset($pendingTargetRateRequest['old_rate']) ? $pendingTargetRateRequest['old_rate'] : 0)); ?>
                        &rarr;
                        <?php echo h(cpms_target_cost_rate_format(isset($pendingTargetRateRequest['new_rate']) ? $pendingTargetRateRequest['new_rate'] : 0)); ?>
                    </div>
                    <?php if ($canApproveTargetRate): ?>
                        <div class="mt-2 grid grid-cols-1 gap-2">
                            <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/target_cost_rate_decide" class="space-y-2">
                                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
                                <input type="hidden" name="request_id" value="<?php echo (int)$pendingTargetRateRequest['id']; ?>">
                                <input type="hidden" name="decision" value="approve">
                                <input type="hidden" name="year" value="<?php echo (int)$selectedYear; ?>">
                                <input type="hidden" name="from_ym" value="<?php echo h($fromYm); ?>">
                                <input type="hidden" name="to_ym" value="<?php echo h($toYm); ?>">
                                <input name="memo" class="w-full px-2 py-2 rounded-lg border border-gray-200" placeholder="승인 메모">
                                <button class="w-full px-3 py-2 rounded-lg bg-gray-900 text-white font-extrabold text-xs">승인</button>
                            </form>
                            <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/target_cost_rate_decide" class="space-y-2">
                                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
                                <input type="hidden" name="request_id" value="<?php echo (int)$pendingTargetRateRequest['id']; ?>">
                                <input type="hidden" name="decision" value="reject">
                                <input type="hidden" name="year" value="<?php echo (int)$selectedYear; ?>">
                                <input type="hidden" name="from_ym" value="<?php echo h($fromYm); ?>">
                                <input type="hidden" name="to_ym" value="<?php echo h($toYm); ?>">
                                <input name="memo" required class="w-full px-2 py-2 rounded-lg border border-gray-200" placeholder="반려 사유">
                                <button class="w-full px-3 py-2 rounded-lg bg-red-600 text-white font-extrabold text-xs">반려</button>
                            </form>
                        </div>
                    <?php endif; ?>
                <?php elseif ($canEditTargetRate): ?>
                    <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/target_cost_rate_save" class="mt-2 space-y-2">
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                        <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
                        <input type="hidden" name="year" value="<?php echo (int)$selectedYear; ?>">
                        <input type="hidden" name="from_ym" value="<?php echo h($fromYm); ?>">
                        <input type="hidden" name="to_ym" value="<?php echo h($toYm); ?>">
                        <div class="flex gap-2">
                            <input name="target_rate" required class="w-full px-2 py-2 rounded-lg border border-gray-200 text-right" value="<?php echo $targetRateValue > 0 ? h(number_format($targetRateValue, 1, '.', '')) : ''; ?>" placeholder="%">
                            <button class="px-3 py-2 rounded-lg bg-gray-900 text-white font-extrabold text-xs">저장</button>
                        </div>
                        <?php if ($targetRateValue > 0): ?>
                            <textarea name="reason" class="w-full px-2 py-2 rounded-lg border border-gray-200" placeholder="변경 사유"></textarea>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <div class="summary-grid mt-3">
            <div class="p-3 rounded-xl" style="border:1px solid #bae6fd; background:#f0f9ff;">
                <div class="text-xs text-sky-700">총 외주비</div>
                <div class="text-lg font-extrabold text-gray-900"><?php echo h(cpms_status_money($overallTotals['outsourcing'])); ?></div>
            </div>
            <div class="p-3 rounded-xl" style="border:1px solid #bae6fd; background:#f0f9ff;">
                <div class="text-xs text-sky-700">이번달 외주비</div>
                <div class="text-lg font-extrabold text-gray-900"><?php echo h(cpms_status_money($currentOutsourcingTotal)); ?></div>
            </div>
        </div>
    </div>

    <div class="flex justify-end">
        <form method="get" action="" class="cpms-status-filter flex flex-wrap items-end gap-2">
            <input type="hidden" name="r" value="공사">
            <input type="hidden" name="pid" value="<?php echo (int)$pid; ?>">
            <input type="hidden" name="tab" value="status">
            <div>
                <label class="block text-sm font-bold text-gray-700">연도</label>
                <select name="year" data-status-year class="px-3 py-2 rounded-xl border border-gray-300">
                    <?php foreach ($years as $yy): ?>
                        <option value="<?php echo (int)$yy; ?>" <?php echo ($selectedYear === (int)$yy) ? 'selected' : ''; ?>>
                            <?php echo (int)$yy; ?>년
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-bold text-gray-700">시작월</label>
                <input type="month" name="from_ym" data-status-from class="px-3 py-2 rounded-xl border border-gray-300" value="<?php echo h($fromYm); ?>" max="<?php echo h($currentYm); ?>">
            </div>
            <div>
                <label class="block text-sm font-bold text-gray-700">종료월</label>
                <input type="month" name="to_ym" data-status-to class="px-3 py-2 rounded-xl border border-gray-200 bg-gray-100 text-gray-500" value="<?php echo h($toYm); ?>" readonly aria-readonly="true" title="종료월은 이번 달로 고정됩니다.">
            </div>
            <button class="px-4 py-2 rounded-xl bg-gray-900 text-white font-extrabold">조회</button>
        </form>
    </div>

    <div class="chart-wrap cpms-status-monthly-chart">
        <div class="flex items-center justify-between">
            <h4 class="text-lg font-extrabold text-gray-900">월별 비용/매출 그래프</h4>
            <div class="text-sm font-bold text-gray-500"><?php echo h($periodLabel); ?></div>
        </div>
        <?php if ($debugMode && count($periodDiagnostics) > 0): ?>
            <div class="mt-3 rounded-xl border border-blue-100 bg-blue-50 p-3 text-xs text-blue-900">
                <?php foreach ($periodDiagnostics as $diag): ?>
                    <div><?php echo h($diag); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="chart-scroll">
            <div class="chart-row">
                <?php foreach ($monthlyData as $row): ?>
                    <div class="group">
                        <div class="bars">
                            <?php
                            // 매출 맨 왼쪽
                            $salesAmount = isset($row['sales']) ? (float)$row['sales'] : 0;
                            $salesHeight = ($salesAmount <= 0) ? 2 : max(2, ($salesAmount / $maxMonthlyValue) * 100);
                            $salesTitle = $row['label'] . ' ' . $categories['sales']['label'] . ': ' . cpms_status_money($salesAmount) . ' (' . $row['sales_start'] . ' ~ ' . $row['sales_end'] . ')';

                            $usedTotalAmount = isset($row['used_total']) ? (float)$row['used_total'] : 0;
                            $usedTotalHeight = ($usedTotalAmount <= 0) ? 2 : max(2, ($usedTotalAmount / $maxMonthlyValue) * 100);
                            $usedTotalTitle = $row['label'] . ' ' . $categories['used_total']['label'] . ': ' . cpms_status_money($usedTotalAmount) . ' (' . $row['cost_start'] . ' ~ ' . $row['cost_end'] . ')';

                            $laborAmount = isset($row['labor']) ? (float)$row['labor'] : 0;
                            $laborHeight = ($laborAmount <= 0) ? 2 : max(2, ($laborAmount / $maxMonthlyValue) * 100);
                            $laborTitle = $row['label'] . ' ' . $categories['labor']['label'] . ': ' . cpms_status_money($laborAmount) . ' (' . $row['labor_start'] . ' ~ ' . $row['labor_end'] . ')';

                            $equipmentAmount = isset($row['equipment']) ? (float)$row['equipment'] : 0;
                            $equipmentHeight = ($equipmentAmount <= 0) ? 2 : max(2, ($equipmentAmount / $maxMonthlyValue) * 100);
                            $equipmentTitle = $row['label'] . ' ' . $categories['equipment']['label'] . ': ' . cpms_status_money($equipmentAmount) . ' (' . $row['cost_start'] . ' ~ ' . $row['cost_end'] . ')';

                            // 안전관리비/자재구입비/외주비/구매품 단일 스택
                            $materialsAmount = isset($row['materials']) ? (float)$row['materials'] : 0;
                            $safetyAmount = isset($row['safety']) ? (float)$row['safety'] : 0;
                            $outsourcingAmount = isset($row['outsourcing']) ? (float)$row['outsourcing'] : 0;
                            $purchaseAmount = isset($row['purchase']) ? (float)$row['purchase'] : 0;
                            $stackedCostTotal = isset($row['stackedCostTotal']) ? (float)$row['stackedCostTotal'] : ($materialsAmount + $safetyAmount + $outsourcingAmount + $purchaseAmount);
                            $stackedCostHeight = ($stackedCostTotal <= 0) ? 2 : max(2, ($stackedCostTotal / $maxMonthlyValue) * 100);
                            $materialsPercent = ($stackedCostTotal > 0) ? (($materialsAmount / $stackedCostTotal) * 100) : 25;
                            $safetyPercent = ($stackedCostTotal > 0) ? (($safetyAmount / $stackedCostTotal) * 100) : 25;
                            $outsourcingPercent = ($stackedCostTotal > 0) ? (($outsourcingAmount / $stackedCostTotal) * 100) : 25;
                            $purchasePercent = ($stackedCostTotal > 0) ? (($purchaseAmount / $stackedCostTotal) * 100) : 25;
                            $stackTitle = $row['label'] . ' 안전관리비: ' . cpms_status_money($safetyAmount) . ' / 자재구입비: ' . cpms_status_money($materialsAmount) . ' / 외주비: ' . cpms_status_money($outsourcingAmount) . ' / 구매품: ' . cpms_status_money($purchaseAmount) . ' / 합계: ' . cpms_status_money($stackedCostTotal) . ' (' . $row['cost_start'] . ' ~ ' . $row['cost_end'] . ')';
                            ?>
                            <div class="bar" title="<?php echo h($salesTitle); ?>" style="height:<?php echo round($salesHeight, 2); ?>%; background:<?php echo h($categories['sales']['color']); ?>;">
                                <span class="value"><?php echo h(number_format($salesAmount)); ?></span>
                            </div>
                            <div class="bar" title="<?php echo h($usedTotalTitle); ?>" style="height:<?php echo round($usedTotalHeight, 2); ?>%; background:<?php echo h($categories['used_total']['color']); ?>;">
                                <span class="value"><?php echo h(number_format($usedTotalAmount)); ?></span>
                            </div>
                            <div class="bar" title="<?php echo h($laborTitle); ?>" style="height:<?php echo round($laborHeight, 2); ?>%; background:<?php echo h($categories['labor']['color']); ?>;">
                                <span class="value"><?php echo h(number_format($laborAmount)); ?></span>
                            </div>
                            <div class="bar" title="<?php echo h($equipmentTitle); ?>" style="height:<?php echo round($equipmentHeight, 2); ?>%; background:<?php echo h($categories['equipment']['color']); ?>;">
                                <span class="value"><?php echo h(number_format($equipmentAmount)); ?></span>
                            </div>
                            <div class="bar stacked" title="<?php echo h($stackTitle); ?>" style="height:<?php echo round($stackedCostHeight, 2); ?>%;">
                                <span class="segment" style="height:<?php echo round($materialsPercent, 2); ?>%; background:<?php echo h($categories['materials']['color']); ?>;"></span>
                                <span class="segment" style="height:<?php echo round($safetyPercent, 2); ?>%; background:<?php echo h($categories['safety']['color']); ?>;"></span>
                                <span class="segment" style="height:<?php echo round($outsourcingPercent, 2); ?>%; background:<?php echo h($categories['outsourcing']['color']); ?>;"></span>
                                <span class="segment" style="height:<?php echo round($purchasePercent, 2); ?>%; background:<?php echo h($categories['purchase']['color']); ?>;"></span>
                                <span class="value"><?php echo h(number_format($stackedCostTotal)); ?></span>
                            </div>
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
        <div class="cpms-responsive-table-wrap mt-4 rounded-2xl border border-gray-200">
            <table class="cpms-responsive-table cpms-status-mobile-table text-sm">
                <thead class="bg-gray-50 text-gray-500">
                    <tr>
                        <th class="px-3 py-2 text-left font-bold">월</th>
                        <th class="px-3 py-2 text-right font-bold">확정매출</th>
                        <th class="px-3 py-2 text-right font-bold">예상매출</th>
                        <th class="px-3 py-2 text-right font-bold">적용매출</th>
                        <th class="px-3 py-2 text-right font-bold">투입원가</th>
                        <th class="px-3 py-2 text-right font-bold">목표원가</th>
                        <th class="px-3 py-2 text-right font-bold">손익</th>
                        <th class="px-3 py-2 text-right font-bold">원가율</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($monthlyData as $row): ?>
                        <?php
                        $rowCostRate = isset($row['cost_rate']) ? (float)$row['cost_rate'] : 0.0;
                        $rowNoSales = isset($row['no_sales']) ? (int)$row['no_sales'] : 0;
                        $rowRateClass = cpms_status_rate_class($rowCostRate, $rowNoSales);
                        $rowProfit = isset($row['profit']) ? (float)$row['profit'] : 0.0;
                        $rowProfitClass = ($rowProfit < 0) ? 'text-red-700' : 'text-blue-700';
                        $rowTargetRate = isset($row['target_cost_rate']) ? (float)$row['target_cost_rate'] : 0.0;
                        $rowTargetRateLabel = cpms_target_cost_rate_format($rowTargetRate);
                        ?>
                        <tr class="border-t border-gray-100">
                            <td class="px-3 py-2 text-gray-700 font-bold"><?php echo h(isset($row['label']) ? $row['label'] : '-'); ?></td>
                            <td class="px-3 py-2 text-right text-gray-800"><?php echo h(cpms_status_confirmed_money(isset($row['confirmed_sales']) ? $row['confirmed_sales'] : 0)); ?></td>
                            <td class="px-3 py-2 text-right text-gray-800"><?php echo h(cpms_status_money(isset($row['expected_sales']) ? $row['expected_sales'] : 0)); ?></td>
                            <td class="px-3 py-2 text-right text-gray-900 font-bold"><?php echo h(cpms_status_money(isset($row['sales']) ? $row['sales'] : 0)); ?></td>
                            <td class="px-3 py-2 text-right text-gray-800"><?php echo h(cpms_status_money(isset($row['used_total']) ? $row['used_total'] : 0)); ?></td>
                            <td class="px-3 py-2 text-right text-gray-800" title="<?php echo h('목표원가율 ' . $rowTargetRateLabel); ?>">
                                <div><?php echo h(cpms_status_money(isset($row['target_cost_amount']) ? $row['target_cost_amount'] : 0)); ?></div>
                                <?php if ($rowTargetRate > 0): ?><div class="text-xs text-gray-400"><?php echo h($rowTargetRateLabel); ?></div><?php endif; ?>
                            </td>
                            <td class="px-3 py-2 text-right font-extrabold <?php echo h($rowProfitClass); ?>"><?php echo h(cpms_status_money($rowProfit)); ?></td>
                            <td class="px-3 py-2 text-right">
                                <span class="cpms-chip cpms-status-rate-badge inline-flex px-2 py-1 rounded-xl border text-xs font-extrabold <?php echo $rowRateClass; ?>"><?php echo h(isset($row['cost_rate_label']) ? $row['cost_rate_label'] : '0%'); ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <?php
                $periodRateClass = cpms_status_rate_class(isset($periodCostRateInfo['cost_rate']) ? $periodCostRateInfo['cost_rate'] : 0, isset($periodCostRateInfo['no_sales']) ? $periodCostRateInfo['no_sales'] : 0);
                $periodProfitClass = ((float)$yearTotals['profit'] < 0) ? 'text-red-700' : 'text-blue-700';
                ?>
                <tfoot class="bg-gray-50">
                    <tr class="border-t border-gray-200">
                        <td class="px-3 py-2 text-gray-900 font-extrabold">합계</td>
                        <td class="px-3 py-2 text-right text-gray-900 font-extrabold"><?php echo h(cpms_status_confirmed_money($yearTotals['confirmed_sales'])); ?></td>
                        <td class="px-3 py-2 text-right text-gray-900 font-extrabold"><?php echo h(cpms_status_money($yearTotals['expected_sales'])); ?></td>
                        <td class="px-3 py-2 text-right text-gray-900 font-extrabold"><?php echo h(cpms_status_money($yearTotals['sales'])); ?></td>
                        <td class="px-3 py-2 text-right text-gray-900 font-extrabold"><?php echo h(cpms_status_money($yearTotals['used_total'])); ?></td>
                        <td class="px-3 py-2 text-right text-gray-900 font-extrabold"><?php echo h(cpms_status_money($yearTotals['target_cost_amount'])); ?></td>
                        <td class="px-3 py-2 text-right font-extrabold <?php echo h($periodProfitClass); ?>"><?php echo h(cpms_status_money($yearTotals['profit'])); ?></td>
                        <td class="px-3 py-2 text-right">
                            <span class="cpms-chip cpms-status-rate-badge inline-flex px-2 py-1 rounded-xl border text-xs font-extrabold <?php echo $periodRateClass; ?>"><?php echo h(isset($periodCostRateInfo['cost_rate_label']) ? $periodCostRateInfo['cost_rate_label'] : '0%'); ?></span>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="chart-wrap cpms-status-quarterly-chart">
        <h4 class="text-lg font-extrabold text-gray-900">분기별 비용/매출 그래프</h4>
        <div class="chart-scroll">
            <div class="chart-row" style="min-width:520px;">
                <?php foreach ($quarterlyData as $row): ?>
                    <div class="group">
                        <div class="bars">
                            <?php
                            // 매출 맨 왼쪽
                            $salesAmount = isset($row['sales']) ? (float)$row['sales'] : 0;
                            $salesHeight = ($salesAmount <= 0) ? 2 : max(2, ($salesAmount / $maxQuarterValue) * 100);
                            $salesTitle = $row['label'] . ' ' . $categories['sales']['label'] . ': ' . cpms_status_money($salesAmount);

                            $usedTotalAmount = isset($row['used_total']) ? (float)$row['used_total'] : 0;
                            $usedTotalHeight = ($usedTotalAmount <= 0) ? 2 : max(2, ($usedTotalAmount / $maxQuarterValue) * 100);
                            $usedTotalTitle = $row['label'] . ' ' . $categories['used_total']['label'] . ': ' . cpms_status_money($usedTotalAmount);

                            $laborAmount = isset($row['labor']) ? (float)$row['labor'] : 0;
                            $laborHeight = ($laborAmount <= 0) ? 2 : max(2, ($laborAmount / $maxQuarterValue) * 100);
                            $laborTitle = $row['label'] . ' ' . $categories['labor']['label'] . ': ' . cpms_status_money($laborAmount);

                            $equipmentAmount = isset($row['equipment']) ? (float)$row['equipment'] : 0;
                            $equipmentHeight = ($equipmentAmount <= 0) ? 2 : max(2, ($equipmentAmount / $maxQuarterValue) * 100);
                            $equipmentTitle = $row['label'] . ' ' . $categories['equipment']['label'] . ': ' . cpms_status_money($equipmentAmount);

                            // 안전관리비/자재구입비/외주비/구매품 단일 스택
                            $materialsAmount = isset($row['materials']) ? (float)$row['materials'] : 0;
                            $safetyAmount = isset($row['safety']) ? (float)$row['safety'] : 0;
                            $outsourcingAmount = isset($row['outsourcing']) ? (float)$row['outsourcing'] : 0;
                            $purchaseAmount = isset($row['purchase']) ? (float)$row['purchase'] : 0;
                            $stackedCostTotal = isset($row['stackedCostTotal']) ? (float)$row['stackedCostTotal'] : ($materialsAmount + $safetyAmount + $outsourcingAmount + $purchaseAmount);
                            $stackedCostHeight = ($stackedCostTotal <= 0) ? 2 : max(2, ($stackedCostTotal / $maxQuarterValue) * 100);
                            $materialsPercent = ($stackedCostTotal > 0) ? (($materialsAmount / $stackedCostTotal) * 100) : 25;
                            $safetyPercent = ($stackedCostTotal > 0) ? (($safetyAmount / $stackedCostTotal) * 100) : 25;
                            $outsourcingPercent = ($stackedCostTotal > 0) ? (($outsourcingAmount / $stackedCostTotal) * 100) : 25;
                            $purchasePercent = ($stackedCostTotal > 0) ? (($purchaseAmount / $stackedCostTotal) * 100) : 25;
                            $stackTitle = $row['label'] . ' 안전관리비: ' . cpms_status_money($safetyAmount) . ' / 자재구입비: ' . cpms_status_money($materialsAmount) . ' / 외주비: ' . cpms_status_money($outsourcingAmount) . ' / 구매품: ' . cpms_status_money($purchaseAmount) . ' / 합계: ' . cpms_status_money($stackedCostTotal);
                            ?>
                            <div class="bar" title="<?php echo h($salesTitle); ?>" style="height:<?php echo round($salesHeight, 2); ?>%; background:<?php echo h($categories['sales']['color']); ?>;">
                                <span class="value"><?php echo h(number_format($salesAmount)); ?></span>
                            </div>
                            <div class="bar" title="<?php echo h($usedTotalTitle); ?>" style="height:<?php echo round($usedTotalHeight, 2); ?>%; background:<?php echo h($categories['used_total']['color']); ?>;">
                                <span class="value"><?php echo h(number_format($usedTotalAmount)); ?></span>
                            </div>
                            <div class="bar" title="<?php echo h($laborTitle); ?>" style="height:<?php echo round($laborHeight, 2); ?>%; background:<?php echo h($categories['labor']['color']); ?>;">
                                <span class="value"><?php echo h(number_format($laborAmount)); ?></span>
                            </div>
                            <div class="bar" title="<?php echo h($equipmentTitle); ?>" style="height:<?php echo round($equipmentHeight, 2); ?>%; background:<?php echo h($categories['equipment']['color']); ?>;">
                                <span class="value"><?php echo h(number_format($equipmentAmount)); ?></span>
                            </div>
                            <div class="bar stacked" title="<?php echo h($stackTitle); ?>" style="height:<?php echo round($stackedCostHeight, 2); ?>%;">
                                <span class="segment" style="height:<?php echo round($materialsPercent, 2); ?>%; background:<?php echo h($categories['materials']['color']); ?>;"></span>
                                <span class="segment" style="height:<?php echo round($safetyPercent, 2); ?>%; background:<?php echo h($categories['safety']['color']); ?>;"></span>
                                <span class="segment" style="height:<?php echo round($outsourcingPercent, 2); ?>%; background:<?php echo h($categories['outsourcing']['color']); ?>;"></span>
                                <span class="segment" style="height:<?php echo round($purchasePercent, 2); ?>%; background:<?php echo h($categories['purchase']['color']); ?>;"></span>
                                <span class="value"><?php echo h(number_format($stackedCostTotal)); ?></span>
                            </div>
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

<script>
(function(){
    var yearEl = document.querySelector('[data-status-year]');
    var fromEl = document.querySelector('[data-status-from]');
    var toEl = document.querySelector('[data-status-to]');
    if (!yearEl || !fromEl || !toEl) return;
    yearEl.addEventListener('change', function(){
        var y = String(yearEl.value || '');
        if (!/^\d{4}$/.test(y)) return;
        var nextFrom = y + '-01';
        fromEl.value = (nextFrom > toEl.value) ? toEl.value : nextFrom;
    });
})();
</script>
