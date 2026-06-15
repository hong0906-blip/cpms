<?php

if (!function_exists('cpms_sales_cache_key')) {
    function cpms_sales_cache_key($pdo, $suffix) {
        $prefix = 'nopdo';
        if ($pdo && function_exists('spl_object_hash')) {
            $prefix = spl_object_hash($pdo);
        }
        return $prefix . ':' . (string)$suffix;
    }
}

if (!function_exists('cpms_sales_table_exists')) {
    function cpms_sales_table_exists($pdo, $table) {
        static $cache = array();
        static $dbNameCache = array();
        if (!$pdo) return false;
        $cacheKey = cpms_sales_cache_key($pdo, 'table:' . (string)$table);
        if (isset($cache[$cacheKey])) return $cache[$cacheKey];
        try {
            $pdoKey = cpms_sales_cache_key($pdo, 'db');
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

if (!function_exists('cpms_sales_column_exists')) {
    function cpms_sales_column_exists($pdo, $table, $column) {
        static $cache = array();
        static $dbNameCache = array();
        if (!$pdo) return false;
        $cacheKey = cpms_sales_cache_key($pdo, 'column:' . (string)$table . ':' . (string)$column);
        if (isset($cache[$cacheKey])) return $cache[$cacheKey];
        try {
            $pdoKey = cpms_sales_cache_key($pdo, 'db');
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

if (!function_exists('cpms_sales_normalize_unit')) {
    function cpms_sales_normalize_unit($unit) {
        $unit = trim((string)$unit);
        if ($unit === '') return '';
        $unit = preg_replace('/\s+/u', '', $unit);
        $unit = str_replace('.', '', $unit);
        $unit = strtoupper($unit);
        return trim((string)$unit);
    }
}

if (!function_exists('cpms_sales_is_no_multiply_unit')) {
    function cpms_sales_is_no_multiply_unit($unit) {
        static $noMultiplyUnits = null;
        if ($noMultiplyUnits === null) {
            $noMultiplyUnits = array('EA' => true, 'SET' => true, '조' => true, '본' => true);
        }
        $normalized = cpms_sales_normalize_unit($unit);
        return isset($noMultiplyUnits[$normalized]);
    }
}

if (!function_exists('cpms_sales_period_range')) {
    function cpms_sales_period_range($ym) {
        $ym = trim((string)$ym);
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) { $ym = date('Y-m'); }
        if (function_exists('cpms_cost_period_range')) {
            $range = cpms_cost_period_range($ym, 'sales');
            $start = isset($range['start']) ? (string)$range['start'] : '';
            $end = isset($range['end']) ? (string)$range['end'] : '';
            if ($start !== '' && $end !== '') return $range;
        }
        $start = $ym . '-01';
        $ts = strtotime($start);
        $end = date('Y-m-t', $ts);
        return array('start' => $start, 'end' => $end);
    }
}

if (!function_exists('cpms_sales_total_between')) {
    function cpms_sales_total_between($pdo, $projectId, $startDate, $endDate) {
        $result = array('amount' => 0.0, 'stats' => array('schedule_task_rows' => 0, 'work_item_line_rows' => 0, 'unit_price_rows' => 0, 'completed_task_rows' => 0, 'sales_sum' => 0.0));
        if (!$pdo || $projectId <= 0) return $result;
        static $cache = array();
        $cacheKey = cpms_sales_cache_key($pdo, 'sales-between:' . (int)$projectId . ':' . (string)$startDate . ':' . (string)$endDate);
        if (isset($cache[$cacheKey])) return $cache[$cacheKey];
        if (!cpms_sales_table_exists($pdo, 'cpms_schedule_tasks')) return $result;
        if (!cpms_sales_table_exists($pdo, 'cpms_work_item_lines')) return $result;
        if (!cpms_sales_table_exists($pdo, 'cpms_project_unit_prices')) return $result;

        $requiredTaskCols = array('project_id', 'work_id', 'end_date', 'progress');
        foreach ($requiredTaskCols as $col) { if (!cpms_sales_column_exists($pdo, 'cpms_schedule_tasks', $col)) return $result; }
        $requiredLineCols = array('work_id', 'unit_price_id');
        foreach ($requiredLineCols as $col) { if (!cpms_sales_column_exists($pdo, 'cpms_work_item_lines', $col)) return $result; }
        if (!cpms_sales_column_exists($pdo, 'cpms_project_unit_prices', 'id')) return $result;
        if (!cpms_sales_column_exists($pdo, 'cpms_project_unit_prices', 'unit_price')) return $result;

        $hasLinePlannedQty = cpms_sales_column_exists($pdo, 'cpms_work_item_lines', 'planned_qty');
        $hasUnitQty = cpms_sales_column_exists($pdo, 'cpms_project_unit_prices', 'qty');
        $hasUnitCol = cpms_sales_column_exists($pdo, 'cpms_project_unit_prices', 'unit');

        try {
            $today = date('Y-m-d');
            $lineSelect = " st.id AS task_id, pup.id AS unit_price_id, wil.work_id AS work_item_work_id, pup.unit_price AS unit_price ";
            $lineSelect .= $hasLinePlannedQty ? ", wil.planned_qty AS planned_qty" : ", NULL AS planned_qty";
            $lineSelect .= $hasUnitQty ? ", pup.qty AS contract_qty" : ", NULL AS contract_qty";
            $lineSelect .= $hasUnitCol ? ", pup.unit AS unit" : ", '' AS unit";

            $sql = "SELECT " . $lineSelect . " FROM cpms_schedule_tasks st LEFT JOIN cpms_work_item_lines wil ON wil.work_id = st.work_id LEFT JOIN cpms_project_unit_prices pup ON pup.id = wil.unit_price_id WHERE st.project_id = :pid AND st.end_date IS NOT NULL AND st.end_date <> '' AND st.end_date BETWEEN :start AND :end AND ( COALESCE(st.progress, 0) >= 100 OR ( st.end_date < :today AND (st.progress IS NULL OR st.progress = 0) ) )";
            $st = $pdo->prepare($sql);
            $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
            $st->bindValue(':start', (string)$startDate);
            $st->bindValue(':end', (string)$endDate);
            $st->bindValue(':today', (string)$today);
            $st->execute();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows) || count($rows) === 0) {
                $cache[$cacheKey] = $result;
                return $result;
            }

            $taskSet = array(); $lineSet = array(); $unitSet = array();
            $totalSales = 0.0;
            $workAmountByTask = array();
            foreach ($rows as $row) {
                $taskId = isset($row['task_id']) ? (int)$row['task_id'] : 0;
                if ($taskId <= 0) continue;
                $taskSet[$taskId] = true;
                $lineKey = isset($row['work_item_work_id']) ? (string)$row['work_item_work_id'] : '';
                if ($lineKey !== '') $lineSet[$lineKey] = true;
                $upId = isset($row['unit_price_id']) ? (int)$row['unit_price_id'] : 0;
                if ($upId > 0) $unitSet[$upId] = true;

                $unitPrice = isset($row['unit_price']) && is_numeric((string)$row['unit_price']) ? (float)$row['unit_price'] : 0.0;
                if (!isset($workAmountByTask[$taskId])) $workAmountByTask[$taskId] = 0.0;
                if (!array_key_exists('unit_price', $row) || $row['unit_price'] === null) continue;
                $plannedQtyRaw = isset($row['planned_qty']) ? $row['planned_qty'] : null;
                $contractQtyRaw = isset($row['contract_qty']) ? $row['contract_qty'] : null;
                if ($plannedQtyRaw !== null && trim((string)$plannedQtyRaw) !== '') {
                    $qtyUsed = is_numeric((string)$plannedQtyRaw) ? (float)$plannedQtyRaw : 0.0;
                } else {
                    $qtyUsed = is_numeric((string)$contractQtyRaw) ? (float)$contractQtyRaw : 0.0;
                }
                $unitRaw = isset($row['unit']) ? (string)$row['unit'] : '';
                $lineAmount = cpms_sales_is_no_multiply_unit($unitRaw) ? $unitPrice : ($qtyUsed * $unitPrice);
                $workAmountByTask[$taskId] += $lineAmount;
            }
            foreach ($workAmountByTask as $taskWorkAmount) { $totalSales += (float)$taskWorkAmount; }

            $result['amount'] = (float)$totalSales;
            $result['stats']['schedule_task_rows'] = count($taskSet);
            $result['stats']['work_item_line_rows'] = count($lineSet);
            $result['stats']['unit_price_rows'] = count($unitSet);
            $result['stats']['completed_task_rows'] = count($taskSet);
            $result['stats']['sales_sum'] = (float)$totalSales;
            $cache[$cacheKey] = $result;
            return $cache[$cacheKey];
        } catch (Exception $e) {
            $cache[$cacheKey] = $result;
            return $result;
        }
    }
}

if (!function_exists('cpms_sales_total_all')) {
    function cpms_sales_total_all($pdo, $projectId) {
        $result = cpms_sales_total_between($pdo, $projectId, '1900-01-01', '2999-12-31');
        return isset($result['amount']) ? (float)$result['amount'] : 0.0;
    }
}

if (!function_exists('cpms_confirmed_sales_total_between')) {
    function cpms_confirmed_sales_total_between($pdo, $projectId, $startDate, $endDate) {
        if (!$pdo || $projectId <= 0) return 0.0;
        static $cache = array();
        $cacheKey = cpms_sales_cache_key($pdo, 'confirmed-between:' . (int)$projectId . ':' . (string)$startDate . ':' . (string)$endDate);
        if (isset($cache[$cacheKey])) return $cache[$cacheKey];
        if (!cpms_sales_table_exists($pdo, 'cpms_progress_billings')) return 0.0;
        if (!cpms_sales_column_exists($pdo, 'cpms_progress_billings', 'project_id')) return 0.0;
        if (!cpms_sales_column_exists($pdo, 'cpms_progress_billings', 'recognized_amount')) return 0.0;

        try {
            $amountExpr = cpms_sales_column_exists($pdo, 'cpms_progress_billings', 'requested_amount') ? "COALESCE(NULLIF(recognized_amount, 0), requested_amount, 0)" : "recognized_amount";
            $sql = "SELECT COALESCE(SUM(" . $amountExpr . "), 0) FROM cpms_progress_billings WHERE project_id = :pid";
            if (cpms_sales_column_exists($pdo, 'cpms_progress_billings', 'progress_date')) {
                if (cpms_sales_column_exists($pdo, 'cpms_progress_billings', 'created_at')) {
                    $sql .= " AND COALESCE(progress_date, DATE(created_at)) BETWEEN :start_date AND :end_date";
                } else {
                    $sql .= " AND progress_date IS NOT NULL AND progress_date BETWEEN :start_date AND :end_date";
                }
            }
            $st = $pdo->prepare($sql);
            $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
            if (strpos($sql, ':start_date') !== false) {
                $st->bindValue(':start_date', (string)$startDate);
                $st->bindValue(':end_date', (string)$endDate);
            }
            $st->execute();
            $cache[$cacheKey] = (float)$st->fetchColumn();
            return $cache[$cacheKey];
        } catch (Exception $e) {
            $cache[$cacheKey] = 0.0;
            return 0.0;
        }
    }
}

if (!function_exists('cpms_confirmed_sales_total_all')) {
    function cpms_confirmed_sales_total_all($pdo, $projectId) {
        if (!$pdo || $projectId <= 0) return 0.0;
        static $cache = array();
        $cacheKey = cpms_sales_cache_key($pdo, 'confirmed-all:' . (int)$projectId);
        if (isset($cache[$cacheKey])) return $cache[$cacheKey];
        if (cpms_sales_table_exists($pdo, 'cpms_progress_billings') && cpms_sales_column_exists($pdo, 'cpms_progress_billings', 'recognized_amount')) {
            try {
                $amountExpr = cpms_sales_column_exists($pdo, 'cpms_progress_billings', 'requested_amount') ? "COALESCE(NULLIF(recognized_amount, 0), requested_amount, 0)" : "recognized_amount";
                $st = $pdo->prepare("SELECT COALESCE(SUM(" . $amountExpr . "), 0) FROM cpms_progress_billings WHERE project_id = :pid");
                $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
                $st->execute();
                $amount = (float)$st->fetchColumn();
                if ($amount > 0) {
                    $cache[$cacheKey] = $amount;
                    return $cache[$cacheKey];
                }
            } catch (Exception $e) {
            }
        }
        if (cpms_sales_table_exists($pdo, 'cpms_monthly_recognized') && cpms_sales_column_exists($pdo, 'cpms_monthly_recognized', 'recognized_cum_amount')) {
            try {
                $stLegacy = $pdo->prepare("SELECT COALESCE(MAX(recognized_cum_amount), 0) FROM cpms_monthly_recognized WHERE project_id = :pid");
                $stLegacy->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
                $stLegacy->execute();
                $cache[$cacheKey] = (float)$stLegacy->fetchColumn();
                return $cache[$cacheKey];
            } catch (Exception $e) {
                $cache[$cacheKey] = 0.0;
                return 0.0;
            }
        }
        $cache[$cacheKey] = 0.0;
        return 0.0;
    }
}

if (!function_exists('cpms_confirmed_sales_has_data')) {
    function cpms_confirmed_sales_has_data($pdo, $projectId) {
        if (!$pdo || $projectId <= 0) return false;
        static $cache = array();
        $cacheKey = cpms_sales_cache_key($pdo, 'confirmed-has-data:' . (int)$projectId);
        if (isset($cache[$cacheKey])) return $cache[$cacheKey];
        if (cpms_sales_table_exists($pdo, 'cpms_progress_billings')) {
            try {
                $amountExpr = cpms_sales_column_exists($pdo, 'cpms_progress_billings', 'requested_amount') ? "COALESCE(NULLIF(recognized_amount, 0), requested_amount, 0)" : "recognized_amount";
                $st = $pdo->prepare("SELECT COUNT(*) FROM cpms_progress_billings WHERE project_id = :pid AND " . $amountExpr . " > 0");
                $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
                $st->execute();
                if ((int)$st->fetchColumn() > 0) {
                    $cache[$cacheKey] = true;
                    return true;
                }
            } catch (Exception $e) {
            }
        }
        if (cpms_sales_table_exists($pdo, 'cpms_monthly_recognized')) {
            try {
                $stLegacy = $pdo->prepare("SELECT COUNT(*) FROM cpms_monthly_recognized WHERE project_id = :pid AND recognized_cum_amount > 0");
                $stLegacy->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
                $stLegacy->execute();
                $cache[$cacheKey] = ((int)$stLegacy->fetchColumn() > 0);
                return $cache[$cacheKey];
            } catch (Exception $e) {
                $cache[$cacheKey] = false;
                return false;
            }
        }
        $cache[$cacheKey] = false;
        return false;
    }
}

if (!function_exists('cpms_sales_monthly_map')) {
    function cpms_sales_monthly_map($pdo, $projectId, $allMonths) {
        $months = array();
        foreach ($allMonths as $ym) { $months[$ym] = 0.0; }
        $diag = array('basis' => '공사 상황 탭 매출 기준(현월 1일~말일)', 'stats' => array('schedule_task_rows' => 0, 'work_item_line_rows' => 0, 'unit_price_rows' => 0, 'completed_task_rows' => 0, 'sales_sum' => 0.0));
        foreach ($allMonths as $ym) {
            $range = cpms_sales_period_range($ym);
            $start = isset($range['start']) ? (string)$range['start'] : '';
            $end = isset($range['end']) ? (string)$range['end'] : '';
            if ($start === '' || $end === '') continue;
            $one = cpms_sales_total_between($pdo, $projectId, $start, $end);
            $months[$ym] = isset($one['amount']) ? (float)$one['amount'] : 0.0;
            if (isset($one['stats']) && is_array($one['stats'])) {
                $diag['stats']['schedule_task_rows'] += isset($one['stats']['schedule_task_rows']) ? (int)$one['stats']['schedule_task_rows'] : 0;
                $diag['stats']['work_item_line_rows'] += isset($one['stats']['work_item_line_rows']) ? (int)$one['stats']['work_item_line_rows'] : 0;
                $diag['stats']['unit_price_rows'] += isset($one['stats']['unit_price_rows']) ? (int)$one['stats']['unit_price_rows'] : 0;
                $diag['stats']['completed_task_rows'] += isset($one['stats']['completed_task_rows']) ? (int)$one['stats']['completed_task_rows'] : 0;
            }
            $diag['stats']['sales_sum'] += $months[$ym];
        }
        return array('months' => $months, 'basis' => $diag['basis'], 'stats' => $diag['stats']);
    }
}
