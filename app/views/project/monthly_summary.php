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


function cpms_monthly_summary_first_text($row, $keys) {
    if (!is_array($row) || !is_array($keys)) return '';
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row)) continue;
        $value = trim((string)$row[$key]);
        if ($value !== '') return $value;
    }
    return '';
}

function cpms_monthly_summary_period($costType, $ym) {
    $fallback = array(
        'start' => $ym . '-01',
        'end' => date('Y-m-t', strtotime($ym . '-01')),
    );
    if (!cpms_monthly_summary_ym_valid($ym)) return $fallback;
    try {
        $range = \App\Services\CostChangeService::periodForYm($costType, $ym);
        if (is_array($range)) {
            if (!empty($range['start'])) $fallback['start'] = (string)$range['start'];
            if (!empty($range['end'])) $fallback['end'] = (string)$range['end'];
        }
    } catch (Exception $e) {
    }
    return $fallback;
}

function cpms_monthly_summary_labor_detail_rows($pdo, $projectId, $projectName, $ym) {
    $result = array(
        'labor' => array(),
        'labor_outsourcing' => array(),
        'labor_total' => 0.0,
        'labor_outsourcing_total' => 0.0,
    );
    if (!$pdo || (int)$projectId <= 0 || trim((string)$projectName) === '' || !cpms_monthly_summary_ym_valid($ym)) return $result;
    if (!function_exists('cpms_load_project_labor_workers') || !function_exists('cpms_load_project_labor_worker_month_ratio_map') || !function_exists('cpms_apply_project_labor_worker_month_ratios') || !function_exists('cpms_build_project_worker_rows') || !function_exists('cpms_build_timesheet_workers') || !function_exists('cpms_load_gongsu_data')) return $result;

    try {
        $directMembers = function_exists('cpms_load_direct_team_members') ? cpms_load_direct_team_members($pdo) : array();
        $projectWorkers = cpms_load_project_labor_workers($pdo, (int)$projectId);
        $ratioMap = cpms_load_project_labor_worker_month_ratio_map($pdo, (int)$projectId, (string)$ym, $projectWorkers);
        $projectWorkers = cpms_apply_project_labor_worker_month_ratios($projectWorkers, $ratioMap);
        $workerRows = cpms_build_project_worker_rows($projectWorkers, $directMembers);
        $workers = cpms_build_timesheet_workers($workerRows);

        $gongsuData = cpms_load_gongsu_data($pdo, (string)$projectName, (string)$ym);
        $gongsuMap = isset($gongsuData['gongsu_map']) && is_array($gongsuData['gongsu_map']) ? $gongsuData['gongsu_map'] : array();
        $outputDays = isset($gongsuData['output_days']) && is_array($gongsuData['output_days']) ? $gongsuData['output_days'] : array();
        $gongsuUnit = isset($gongsuData['gongsu_unit']) && is_array($gongsuData['gongsu_unit']) ? $gongsuData['gongsu_unit'] : array();
        if (function_exists('cpms_apply_labor_overrides_to_dataset')) {
            $overrideData = cpms_apply_labor_overrides_to_dataset($gongsuMap, $outputDays, $gongsuUnit, (int)$projectId, (string)$ym);
            $gongsuMap = isset($overrideData['gongsu_map']) && is_array($overrideData['gongsu_map']) ? $overrideData['gongsu_map'] : array();
        } else if (function_exists('cpms_apply_labor_overrides_to_map')) {
            $gongsuMap = cpms_apply_labor_overrides_to_map($gongsuMap, (int)$projectId, (string)$ym);
        }

        foreach ($workers as $worker) {
            $workerName = isset($worker['name']) ? trim((string)$worker['name']) : '';
            $workerKey = function_exists('cpms_normalize_worker_key') ? cpms_normalize_worker_key($workerName) : trim((string)$workerName);
            if ($workerKey === '') continue;
            $dailyMap = isset($gongsuMap[$workerKey]) && is_array($gongsuMap[$workerKey]) ? $gongsuMap[$workerKey] : array();
            if (count($dailyMap) === 0) continue;

            $outsourcingRatio = function_exists('cpms_resolve_worker_outsourcing_ratio') ? (int)cpms_resolve_worker_outsourcing_ratio($worker) : 0;
            if ($outsourcingRatio < 0) $outsourcingRatio = 0;
            if ($outsourcingRatio > 100) $outsourcingRatio = 100;
            $laborRatio = 100 - $outsourcingRatio;
            $outsourcingStart = isset($worker['outsourcing_start_date']) ? trim((string)$worker['outsourcing_start_date']) : '';
            $outsourcingEnd = isset($worker['outsourcing_end_date']) ? trim((string)$worker['outsourcing_end_date']) : '';
            $hasRange = preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $outsourcingStart)
                && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $outsourcingEnd)
                && $outsourcingStart <= $outsourcingEnd;

            $laborOutputDays = 0;
            $laborGongsu = 0.0;
            $outsourcingOutputDays = 0;
            $outsourcingGongsu = 0.0;
            foreach ($dailyMap as $dateKey => $gongsuValue) {
                $dateKey = (string)$dateKey;
                if (strpos($dateKey, (string)$ym) !== 0 || !is_numeric($gongsuValue)) continue;
                $gongsu = (float)$gongsuValue;
                if ($gongsu <= 0) continue;
                $dayOutsourcingRatio = $outsourcingRatio;
                if ($hasRange && !($dateKey >= $outsourcingStart && $dateKey <= $outsourcingEnd)) {
                    $dayOutsourcingRatio = 0;
                }
                $dayLaborRatio = 100 - $dayOutsourcingRatio;
                if ($dayLaborRatio > 0) {
                    $laborOutputDays++;
                    $laborGongsu += $gongsu;
                }
                if ($dayOutsourcingRatio > 0) {
                    $outsourcingOutputDays++;
                    $outsourcingGongsu += $gongsu;
                }
            }

            $wageRate = function_exists('cpms_resolve_labor_wage_rate') ? (float)cpms_resolve_labor_wage_rate($worker) : cpms_monthly_summary_parse_money(isset($worker['deposit_rate']) ? $worker['deposit_rate'] : '');
            $amounts = function_exists('cpms_labor_calculate_worker_month_amounts')
                ? cpms_labor_calculate_worker_month_amounts($worker, $gongsuMap, $ym)
                : array(
                    'labor_amount' => round($laborGongsu * $wageRate * $laborRatio / 100),
                    'outsourcing_amount' => round($outsourcingGongsu * $wageRate * $outsourcingRatio / 100),
                );
            $laborAmount = isset($amounts['labor_amount']) ? (float)$amounts['labor_amount'] : 0.0;
            $outsourcingAmount = isset($amounts['outsourcing_amount']) ? (float)$amounts['outsourcing_amount'] : 0.0;
            $companyName = isset($worker['company_name']) ? trim((string)$worker['company_name']) : '';
            if ($companyName === '') $companyName = '창명건설';
            $remark = isset($worker['remark']) ? trim((string)$worker['remark']) : '';

            if ($laborAmount > 0 && $laborGongsu > 0) {
                $laborRatioLabel = (string)$laborRatio . '%';
                if ($hasRange) {
                    if ($outsourcingRatio >= 100) $laborRatioLabel = '100% (선택기간 외)';
                    else $laborRatioLabel = $laborRatio . '% (선택기간) / 100% (기간 외)';
                }
                $result['labor'][] = array(
                    'name' => $workerName,
                    'ratio_label' => $laborRatioLabel,
                    'output_days' => $laborOutputDays,
                    'total_gongsu' => $laborGongsu,
                    'wage_rate' => $wageRate,
                    'amount' => $laborAmount,
                    'remark' => $remark,
                );
                $result['labor_total'] += $laborAmount;
            }

            if ($outsourcingAmount > 0 && $outsourcingGongsu > 0) {
                $outsourcingRatioLabel = (string)$outsourcingRatio . '%';
                if ($hasRange) $outsourcingRatioLabel .= ' (선택기간)';
                $result['labor_outsourcing'][] = array(
                    'name' => $workerName,
                    'ratio_label' => $outsourcingRatioLabel,
                    'output_days' => $outsourcingOutputDays,
                    'total_gongsu' => $outsourcingGongsu,
                    'wage_rate' => $wageRate,
                    'amount' => $outsourcingAmount,
                    'company_name' => $companyName,
                );
                $result['labor_outsourcing_total'] += $outsourcingAmount;
            }
        }

        if (cpms_monthly_summary_table_exists($pdo, 'cpms_labor_force_adjustments')) {
            try {
                $stForce = $pdo->prepare('SELECT amount, memo FROM cpms_labor_force_adjustments WHERE project_id = :pid AND month = :ym LIMIT 1');
                $stForce->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
                $stForce->bindValue(':ym', (string)$ym);
                $stForce->execute();
                $forceRow = $stForce->fetch(PDO::FETCH_ASSOC);
                $forceAmount = is_array($forceRow) && isset($forceRow['amount']) ? (float)$forceRow['amount'] : 0.0;
                if ($forceAmount > 0) {
                    $result['labor'][] = array(
                        'name' => '노무비 강제입력',
                        'ratio_label' => '100%',
                        'output_days' => null,
                        'total_gongsu' => null,
                        'wage_rate' => null,
                        'amount' => $forceAmount,
                        'remark' => is_array($forceRow) && isset($forceRow['memo']) ? trim((string)$forceRow['memo']) : '',
                    );
                    $result['labor_total'] += $forceAmount;
                }
            } catch (Exception $e) {
            }
        }
    } catch (Exception $e) {
        return $result;
    }

    usort($result['labor'], function($a, $b) {
        return strcmp((string)$a['name'], (string)$b['name']);
    });
    usort($result['labor_outsourcing'], function($a, $b) {
        $companyCompare = strcmp((string)$a['company_name'], (string)$b['company_name']);
        if ($companyCompare !== 0) return $companyCompare;
        return strcmp((string)$a['name'], (string)$b['name']);
    });
    return $result;
}

function cpms_monthly_summary_equipment_detail_rows($pdo, $projectId, $ym) {
    $result = array('rows' => array(), 'total' => 0.0);
    if (!$pdo || (int)$projectId <= 0 || !cpms_monthly_summary_ym_valid($ym)) return $result;
    if (!cpms_monthly_summary_table_exists($pdo, 'cpms_equipment_items') || !cpms_monthly_summary_table_exists($pdo, 'cpms_equipment_usage')) return $result;

    try {
        $metaInstalled = \App\Services\CostChangeService::isInstalled($pdo);
        $hasDeleted = cpms_monthly_summary_column_exists($pdo, 'cpms_equipment_items', 'is_deleted');
        $metaJoin = $metaInstalled ? " LEFT JOIN cpms_cost_record_meta crm ON crm.target_type='equipment' AND crm.target_id=CAST(u.id AS CHAR)" : '';
        $sql = "SELECT u.*, e.category, e.vendor_name, e.spec, e.base_rate, e.remark AS item_remark
                FROM cpms_equipment_usage u
                INNER JOIN cpms_equipment_items e ON e.id = u.equipment_id AND e.project_id = u.project_id" . $metaJoin . "
                WHERE u.project_id = :pid";
        if ($hasDeleted) $sql .= " AND (e.is_deleted = 0 OR e.is_deleted IS NULL)";
        if ($metaInstalled) {
            $sql .= " AND COALESCE(crm.is_deleted,0)=0
                      AND COALESCE(NULLIF(crm.settlement_ym,''),IF(DAY(u.use_date)>=26,DATE_FORMAT(DATE_ADD(u.use_date,INTERVAL 1 MONTH),'%Y-%m'),DATE_FORMAT(u.use_date,'%Y-%m'))) = :ym";
        } else {
            $period = cpms_monthly_summary_period('equipment', $ym);
            $sql .= " AND u.use_date BETWEEN :period_start AND :period_end";
        }
        $sql .= " ORDER BY e.category ASC, e.vendor_name ASC, e.spec ASC, u.use_date ASC, u.id ASC";
        $st = $pdo->prepare($sql);
        $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
        if ($metaInstalled) {
            $st->bindValue(':ym', (string)$ym);
        } else {
            $st->bindValue(':period_start', (string)$period['start']);
            $st->bindValue(':period_end', (string)$period['end']);
        }
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) $rows = array();

        $grouped = array();
        foreach ($rows as $row) {
            $workUnit = isset($row['work_unit']) ? (float)$row['work_unit'] : 1.0;
            if ($workUnit <= 0) $workUnit = 1.0;
            $rate = isset($row['base_rate_snapshot']) ? (float)$row['base_rate_snapshot'] : 0.0;
            if ($rate <= 0 && isset($row['base_rate'])) $rate = (float)$row['base_rate'];
            $storedAmount = isset($row['amount']) ? (float)$row['amount'] : 0.0;
            $amount = $rate > 0 ? $workUnit * $rate : $storedAmount;
            $category = isset($row['category']) ? trim((string)$row['category']) : '';
            $spec = isset($row['spec']) ? trim((string)$row['spec']) : '';
            $categoryLabel = $category;
            if ($spec !== '') $categoryLabel .= ($categoryLabel !== '' ? ' / ' : '') . $spec;
            $vendorName = isset($row['vendor_name']) ? trim((string)$row['vendor_name']) : '';
            $remark = cpms_monthly_summary_first_text($row, array('memo', 'usage_memo', 'use_content', 'remark', 'item_remark'));
            $groupKey = (string)(isset($row['equipment_id']) ? $row['equipment_id'] : '') . '|' . number_format($rate, 2, '.', '') . '|' . $remark;
            if (!isset($grouped[$groupKey])) {
                $grouped[$groupKey] = array(
                    'category' => $categoryLabel,
                    'vendor_name' => $vendorName,
                    'total_work_unit' => 0.0,
                    'base_rate' => $rate,
                    'amount' => 0.0,
                    'remark' => $remark,
                );
            }
            $grouped[$groupKey]['total_work_unit'] += $workUnit;
            $grouped[$groupKey]['amount'] += $amount;
            $result['total'] += $amount;
        }
        $result['rows'] = array_values($grouped);
        usort($result['rows'], function($a, $b) {
            $categoryCompare = strcmp((string)$a['category'], (string)$b['category']);
            if ($categoryCompare !== 0) return $categoryCompare;
            return strcmp((string)$a['vendor_name'], (string)$b['vendor_name']);
        });
    } catch (Exception $e) {
        return $result;
    }
    return $result;
}

function cpms_monthly_summary_material_detail_rows($pdo, $projectId, $ym) {
    $result = array('rows' => array(), 'total' => 0.0);
    if (!$pdo || (int)$projectId <= 0 || !cpms_monthly_summary_ym_valid($ym)) return $result;
    if (!cpms_monthly_summary_table_exists($pdo, 'cpms_material_items') || !cpms_monthly_summary_table_exists($pdo, 'cpms_material_usage')) return $result;

    try {
        $metaInstalled = \App\Services\CostChangeService::isInstalled($pdo);
        $hasDeleted = cpms_monthly_summary_column_exists($pdo, 'cpms_material_items', 'is_deleted');
        $metaJoin = $metaInstalled ? " LEFT JOIN cpms_cost_record_meta crm ON crm.target_type='material' AND crm.target_id=CAST(u.id AS CHAR)" : '';
        $sql = "SELECT u.*, m.category, m.vendor_name, m.remark AS item_remark
                FROM cpms_material_usage u
                INNER JOIN cpms_material_items m ON m.id = u.material_id AND m.project_id = u.project_id" . $metaJoin . "
                WHERE u.project_id = :pid
                  AND m.category <> '안전관리비'";
        if ($hasDeleted) $sql .= " AND (m.is_deleted = 0 OR m.is_deleted IS NULL)";
        if ($metaInstalled) {
            $sql .= " AND COALESCE(crm.is_deleted,0)=0
                      AND COALESCE(NULLIF(crm.settlement_ym,''),IF(DAY(u.use_date)>=26,DATE_FORMAT(DATE_ADD(u.use_date,INTERVAL 1 MONTH),'%Y-%m'),DATE_FORMAT(u.use_date,'%Y-%m'))) = :ym";
        } else {
            $period = cpms_monthly_summary_period('material', $ym);
            $sql .= " AND u.use_date BETWEEN :period_start AND :period_end";
        }
        $sql .= " ORDER BY u.use_date ASC, m.category ASC, m.vendor_name ASC, u.id ASC";
        $st = $pdo->prepare($sql);
        $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
        if ($metaInstalled) {
            $st->bindValue(':ym', (string)$ym);
        } else {
            $st->bindValue(':period_start', (string)$period['start']);
            $st->bindValue(':period_end', (string)$period['end']);
        }
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) $rows = array();
        foreach ($rows as $row) {
            $amount = isset($row['amount']) ? (float)$row['amount'] : 0.0;
            $result['rows'][] = array(
                'use_date' => isset($row['use_date']) ? (string)$row['use_date'] : '',
                'category' => isset($row['category']) ? trim((string)$row['category']) : '',
                'vendor_name' => isset($row['vendor_name']) ? trim((string)$row['vendor_name']) : '',
                'amount' => $amount,
                'remark' => cpms_monthly_summary_first_text($row, array('memo', 'usage_memo', 'use_content', 'item_name', 'remark', 'item_remark')),
            );
            $result['total'] += $amount;
        }
    } catch (Exception $e) {
        return $result;
    }
    return $result;
}

function cpms_monthly_summary_manual_outsourcing_detail_rows($pdo, $projectId, $ym) {
    $result = array('rows' => array(), 'total' => 0.0);
    if (!$pdo || (int)$projectId <= 0 || !cpms_monthly_summary_ym_valid($ym) || !function_exists('cpms_outsourcing_manual_rows')) return $result;
    $period = cpms_monthly_summary_period('outsourcing', $ym);
    try {
        $rows = cpms_outsourcing_manual_rows($pdo, (int)$projectId, (string)$period['start'], (string)$period['end']);
        if (!is_array($rows)) $rows = array();
        foreach ($rows as $row) {
            $amount = isset($row['amount']) ? (float)$row['amount'] : 0.0;
            $result['rows'][] = array(
                'expense_date' => isset($row['expense_date']) ? (string)$row['expense_date'] : '',
                'category' => isset($row['category']) ? trim((string)$row['category']) : '외주비',
                'company_name' => isset($row['company_name']) ? trim((string)$row['company_name']) : '',
                'amount' => $amount,
                'memo' => isset($row['memo']) ? trim((string)$row['memo']) : '',
            );
            $result['total'] += $amount;
        }
    } catch (Exception $e) {
        return $result;
    }
    return $result;
}

function cpms_monthly_summary_mobile_detail_payload($pdo, $summaryRows, $ym) {
    $payload = array();
    if (!$pdo || !is_array($summaryRows)) return $payload;
    foreach ($summaryRows as $summaryRow) {
        $projectId = isset($summaryRow['project_id']) ? (int)$summaryRow['project_id'] : 0;
        $projectName = isset($summaryRow['project_name']) ? (string)$summaryRow['project_name'] : '';
        if ($projectId <= 0) continue;
        $labor = cpms_monthly_summary_labor_detail_rows($pdo, $projectId, $projectName, $ym);
        $equipment = cpms_monthly_summary_equipment_detail_rows($pdo, $projectId, $ym);
        $material = cpms_monthly_summary_material_detail_rows($pdo, $projectId, $ym);
        $manualOutsourcing = cpms_monthly_summary_manual_outsourcing_detail_rows($pdo, $projectId, $ym);
        $payload[(string)$projectId] = array(
            'project_name' => $projectName,
            'ym' => $ym,
            'totals' => array(
                'labor' => isset($summaryRow['labor_amount']) ? (float)$summaryRow['labor_amount'] : 0.0,
                'equipment' => isset($summaryRow['equipment_amount']) ? (float)$summaryRow['equipment_amount'] : 0.0,
                'material' => isset($summaryRow['material_purchase_amount']) ? (float)$summaryRow['material_purchase_amount'] : 0.0,
                'outsourcing' => isset($summaryRow['outsourcing_amount']) ? (float)$summaryRow['outsourcing_amount'] : 0.0,
            ),
            'labor' => isset($labor['labor']) ? $labor['labor'] : array(),
            'labor_outsourcing' => isset($labor['labor_outsourcing']) ? $labor['labor_outsourcing'] : array(),
            'equipment' => isset($equipment['rows']) ? $equipment['rows'] : array(),
            'material' => isset($material['rows']) ? $material['rows'] : array(),
            'manual_outsourcing' => isset($manualOutsourcing['rows']) ? $manualOutsourcing['rows'] : array(),
        );
    }
    return $payload;
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

$monthlySummaryMobileDetails = cpms_monthly_summary_mobile_detail_payload($pdo, $summaryRows, $selectedYm);
$monthlySummaryMobileDetailsJson = json_encode(
    $monthlySummaryMobileDetails,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
if (!is_string($monthlySummaryMobileDetailsJson) || $monthlySummaryMobileDetailsJson === '') {
    $monthlySummaryMobileDetailsJson = '{}';
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
              <td class="text-right <?php echo h(cpms_monthly_summary_mobile_money_class($row['labor_amount'])); ?>">
                <button type="button" class="cpms-monthly-detail-trigger" data-project-id="<?php echo (int)$row['project_id']; ?>" data-detail-type="labor" aria-label="<?php echo h($row['project_name']); ?> 노무비 상세 보기"><?php echo h(cpms_monthly_summary_money($row['labor_amount'])); ?></button>
              </td>
              <td class="text-right <?php echo h(cpms_monthly_summary_mobile_money_class($row['equipment_amount'])); ?>">
                <button type="button" class="cpms-monthly-detail-trigger" data-project-id="<?php echo (int)$row['project_id']; ?>" data-detail-type="equipment" aria-label="<?php echo h($row['project_name']); ?> 장비비 상세 보기"><?php echo h(cpms_monthly_summary_money($row['equipment_amount'])); ?></button>
              </td>
              <td class="text-right <?php echo h(cpms_monthly_summary_mobile_money_class($row['material_purchase_amount'])); ?>">
                <button type="button" class="cpms-monthly-detail-trigger" data-project-id="<?php echo (int)$row['project_id']; ?>" data-detail-type="material" aria-label="<?php echo h($row['project_name']); ?> 자재구입비 상세 보기"><?php echo h(cpms_monthly_summary_money($row['material_purchase_amount'])); ?></button>
              </td>
              <td class="text-right <?php echo h(cpms_monthly_summary_mobile_money_class($row['outsourcing_amount'])); ?>">
                <button type="button" class="cpms-monthly-detail-trigger" data-project-id="<?php echo (int)$row['project_id']; ?>" data-detail-type="outsourcing" aria-label="<?php echo h($row['project_name']); ?> 외주비 상세 보기"><?php echo h(cpms_monthly_summary_money($row['outsourcing_amount'])); ?></button>
              </td>
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


<style>
.cpms-monthly-detail-trigger {
  width: 100%;
  min-height: 34px;
  padding: 2px 0;
  border: 0;
  background: transparent;
  color: inherit;
  font: inherit;
  font-weight: 800;
  text-align: right;
  white-space: nowrap;
  cursor: pointer;
  text-decoration: underline;
  text-decoration-color: rgba(37, 99, 235, .38);
  text-underline-offset: 3px;
}
.cpms-monthly-detail-trigger:active {
  background: #eff6ff;
}
.cpms-cost-detail-modal {
  position: fixed;
  inset: 0;
  z-index: 2147482000;
  display: none;
  align-items: center;
  justify-content: center;
  padding: 16px;
  background: rgba(15, 23, 42, .58);
}
.cpms-cost-detail-modal.is-open {
  display: flex;
}
.cpms-cost-detail-dialog {
  width: min(1040px, calc(100vw - 24px));
  max-height: min(88vh, 820px);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  border: 1px solid #e5e7eb;
  border-radius: 24px;
  background: #fff;
  box-shadow: 0 28px 90px rgba(15, 23, 42, .32);
}
.cpms-cost-detail-header {
  flex: 0 0 auto;
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  padding: 18px 20px;
  border-bottom: 1px solid #e5e7eb;
  background: #fff;
}
.cpms-cost-detail-title {
  min-width: 0;
}
.cpms-cost-detail-title h4 {
  margin: 0;
  color: #0f172a;
  font-size: 20px;
  line-height: 1.3;
  font-weight: 900;
  word-break: keep-all;
  overflow-wrap: anywhere;
}
.cpms-cost-detail-meta {
  margin-top: 6px;
  color: #64748b;
  font-size: 13px;
  line-height: 1.45;
  font-weight: 700;
}
.cpms-cost-detail-total {
  margin-top: 8px;
  color: #0f172a;
  font-size: 16px;
  line-height: 1.35;
  font-weight: 900;
  font-variant-numeric: tabular-nums;
}
.cpms-cost-detail-close {
  flex: 0 0 auto;
  min-width: 44px;
  min-height: 44px;
  padding: 0 12px;
  border: 1px solid #d1d5db;
  border-radius: 14px;
  background: #fff;
  color: #334155;
  font-size: 14px;
  font-weight: 900;
}
.cpms-cost-detail-tabs {
  flex: 0 0 auto;
  display: none;
  gap: 8px;
  padding: 12px 16px;
  overflow-x: auto;
  border-bottom: 1px solid #e5e7eb;
  background: #f8fafc;
}
.cpms-cost-detail-tabs.is-visible {
  display: flex;
}
.cpms-cost-detail-tab {
  flex: 0 0 auto;
  min-height: 42px;
  padding: 9px 16px;
  border: 1px solid #cbd5e1;
  border-radius: 999px;
  background: #fff;
  color: #475569;
  font-size: 13px;
  font-weight: 900;
  white-space: nowrap;
}
.cpms-cost-detail-tab.is-active {
  border-color: #0f172a;
  background: #0f172a;
  color: #fff;
}
.cpms-cost-detail-body {
  flex: 1 1 auto;
  min-height: 0;
  overflow: auto;
  -webkit-overflow-scrolling: touch;
  padding: 16px;
  background: #f8fafc;
}
.cpms-cost-detail-table-wrap {
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
  border: 1px solid #e2e8f0;
  border-radius: 16px;
  background: #fff;
}
.cpms-cost-detail-table {
  width: 100%;
  min-width: 760px;
  border-collapse: separate;
  border-spacing: 0;
  font-size: 13px;
}
.cpms-cost-detail-table th,
.cpms-cost-detail-table td {
  min-height: 42px;
  padding: 10px 12px;
  border-right: 1px solid #e5e7eb;
  border-bottom: 1px solid #e5e7eb;
  vertical-align: middle;
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}
.cpms-cost-detail-table th {
  position: sticky;
  top: 0;
  z-index: 3;
  background: #e2e8f0;
  color: #1e293b;
  text-align: center;
  font-weight: 900;
}
.cpms-cost-detail-table th:first-child,
.cpms-cost-detail-table td:first-child {
  position: sticky;
  left: 0;
  z-index: 2;
  min-width: 96px;
  background: #fff;
  font-weight: 800;
}
.cpms-cost-detail-table th:first-child {
  z-index: 4;
  background: #e2e8f0;
}
.cpms-cost-detail-table tr:last-child td {
  border-bottom: 0;
}
.cpms-cost-detail-table th:last-child,
.cpms-cost-detail-table td:last-child {
  border-right: 0;
}
.cpms-cost-detail-table .is-number {
  text-align: right;
}
.cpms-cost-detail-table .is-center {
  text-align: center;
}
.cpms-cost-detail-table .is-remark {
  min-width: 160px;
  max-width: 260px;
  white-space: normal;
  word-break: keep-all;
  overflow-wrap: anywhere;
  line-height: 1.45;
}
.cpms-cost-detail-empty {
  padding: 40px 18px;
  border: 1px dashed #cbd5e1;
  border-radius: 16px;
  background: #fff;
  color: #64748b;
  text-align: center;
  font-size: 14px;
  font-weight: 800;
}
body.cpms-cost-detail-open {
  overflow: hidden;
}
@media (max-width: 767px) {
  .cpms-cost-detail-modal {
    align-items: flex-end;
    padding: 0;
  }
  .cpms-cost-detail-dialog {
    width: 100%;
    max-height: calc(92vh - env(safe-area-inset-top));
    border-right: 0;
    border-bottom: 0;
    border-left: 0;
    border-radius: 22px 22px 0 0;
  }
  .cpms-cost-detail-header {
    padding: 15px 14px 13px;
  }
  .cpms-cost-detail-title h4 {
    font-size: 17px;
  }
  .cpms-cost-detail-meta {
    font-size: 12px;
  }
  .cpms-cost-detail-total {
    font-size: 15px;
  }
  .cpms-cost-detail-tabs {
    padding: 10px 12px;
  }
  .cpms-cost-detail-tab {
    min-height: 44px;
    font-size: 12px;
  }
  .cpms-cost-detail-body {
    padding: 12px 10px calc(18px + env(safe-area-inset-bottom));
  }
  .cpms-cost-detail-table {
    min-width: 700px;
    font-size: 12px;
  }
  .cpms-cost-detail-table th,
  .cpms-cost-detail-table td {
    padding: 9px 10px;
  }
  .cpms-monthly-detail-trigger {
    min-height: 38px;
  }
}
</style>

<div id="cpmsCostDetailModal" class="cpms-cost-detail-modal" aria-hidden="true">
  <div class="cpms-cost-detail-dialog" role="dialog" aria-modal="true" aria-labelledby="cpmsCostDetailTitle">
    <div class="cpms-cost-detail-header">
      <div class="cpms-cost-detail-title">
        <h4 id="cpmsCostDetailTitle">상세 내역</h4>
        <div id="cpmsCostDetailMeta" class="cpms-cost-detail-meta"></div>
        <div id="cpmsCostDetailTotal" class="cpms-cost-detail-total"></div>
      </div>
      <button type="button" class="cpms-cost-detail-close" data-cost-detail-close aria-label="상세 모달 닫기">닫기</button>
    </div>
    <div id="cpmsCostDetailTabs" class="cpms-cost-detail-tabs">
      <button type="button" class="cpms-cost-detail-tab is-active" data-outsourcing-tab="labor_outsourcing">노무성 외주비</button>
      <button type="button" class="cpms-cost-detail-tab" data-outsourcing-tab="manual_outsourcing">외주비</button>
    </div>
    <div id="cpmsCostDetailBody" class="cpms-cost-detail-body"></div>
  </div>
</div>

<script>
(function(){
  var detailData = <?php echo $monthlySummaryMobileDetailsJson; ?>;
  var modal = document.getElementById('cpmsCostDetailModal');
  var title = document.getElementById('cpmsCostDetailTitle');
  var meta = document.getElementById('cpmsCostDetailMeta');
  var total = document.getElementById('cpmsCostDetailTotal');
  var body = document.getElementById('cpmsCostDetailBody');
  var tabs = document.getElementById('cpmsCostDetailTabs');
  var currentProjectId = '';
  var currentType = '';
  var currentOutsourcingTab = 'labor_outsourcing';

  function escapeHtml(value) {
    return String(value === null || typeof value === 'undefined' ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function formatMoney(value) {
    var number = parseFloat(value);
    if (isNaN(number) || Math.abs(number) < 0.0001) return '0';
    return String(Math.round(number)).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  function formatCount(value) {
    if (value === null || typeof value === 'undefined' || value === '') return '-';
    var number = parseFloat(value);
    if (isNaN(number)) return String(value);
    if (Math.abs(number - Math.round(number)) < 0.0001) return String(Math.round(number));
    return String(number.toFixed(2)).replace(/0+$/, '').replace(/\.$/, '');
  }

  function emptyHtml(message) {
    return '<div class="cpms-cost-detail-empty">' + escapeHtml(message || '등록된 상세 내역이 없습니다.') + '</div>';
  }

  function tableHtml(columns, rows) {
    if (!rows || !rows.length) return emptyHtml('등록된 상세 내역이 없습니다.');
    var html = '<div class="cpms-cost-detail-table-wrap"><table class="cpms-cost-detail-table"><thead><tr>';
    var i;
    for (i = 0; i < columns.length; i++) {
      html += '<th>' + escapeHtml(columns[i].label) + '</th>';
    }
    html += '</tr></thead><tbody>';
    for (var r = 0; r < rows.length; r++) {
      var row = rows[r] || {};
      html += '<tr>';
      for (i = 0; i < columns.length; i++) {
        var column = columns[i];
        var value = row[column.key];
        if (column.format === 'money') value = formatMoney(value);
        else if (column.format === 'count') value = formatCount(value);
        else if (value === null || typeof value === 'undefined' || value === '') value = '-';
        var cls = '';
        if (column.align === 'right') cls += ' is-number';
        if (column.align === 'center') cls += ' is-center';
        if (column.remark) cls += ' is-remark';
        html += '<td class="' + cls.replace(/^\s+/, '') + '">' + escapeHtml(value) + '</td>';
      }
      html += '</tr>';
    }
    html += '</tbody></table></div>';
    return html;
  }

  function setActiveOutsourcingTab(tabName) {
    currentOutsourcingTab = tabName === 'manual_outsourcing' ? 'manual_outsourcing' : 'labor_outsourcing';
    var tabButtons = tabs ? tabs.querySelectorAll('[data-outsourcing-tab]') : [];
    for (var i = 0; i < tabButtons.length; i++) {
      var isActive = tabButtons[i].getAttribute('data-outsourcing-tab') === currentOutsourcingTab;
      if (isActive) tabButtons[i].classList.add('is-active');
      else tabButtons[i].classList.remove('is-active');
    }
    renderCurrent();
  }

  function renderCurrent() {
    var project = detailData && detailData[currentProjectId] ? detailData[currentProjectId] : null;
    if (!project) {
      body.innerHTML = emptyHtml('상세 데이터를 찾을 수 없습니다.');
      return;
    }
    var rows = [];
    var columns = [];
    var label = '';
    var totalValue = 0;

    if (currentType === 'labor') {
      label = '노무비';
      totalValue = project.totals ? project.totals.labor : 0;
      rows = project.labor || [];
      columns = [
        {key:'name', label:'성명'},
        {key:'ratio_label', label:'노무비 비율', align:'center'},
        {key:'output_days', label:'출력일수', format:'count', align:'right'},
        {key:'total_gongsu', label:'총공수', format:'count', align:'right'},
        {key:'wage_rate', label:'단가', format:'money', align:'right'},
        {key:'amount', label:'지급총액', format:'money', align:'right'},
        {key:'remark', label:'비고', remark:true}
      ];
    } else if (currentType === 'equipment') {
      label = '장비비';
      totalValue = project.totals ? project.totals.equipment : 0;
      rows = project.equipment || [];
      columns = [
        {key:'category', label:'구분'},
        {key:'vendor_name', label:'업체명'},
        {key:'total_work_unit', label:'총장비공수', format:'count', align:'right'},
        {key:'base_rate', label:'단가', format:'money', align:'right'},
        {key:'amount', label:'총장비비', format:'money', align:'right'},
        {key:'remark', label:'비고', remark:true}
      ];
    } else if (currentType === 'material') {
      label = '자재구입비';
      totalValue = project.totals ? project.totals.material : 0;
      rows = project.material || [];
      columns = [
        {key:'use_date', label:'일자', align:'center'},
        {key:'category', label:'구분'},
        {key:'vendor_name', label:'업체명'},
        {key:'amount', label:'공급가액', format:'money', align:'right'},
        {key:'remark', label:'비고', remark:true}
      ];
    } else if (currentType === 'outsourcing') {
      label = currentOutsourcingTab === 'manual_outsourcing' ? '외주비' : '노무성 외주비';
      totalValue = project.totals ? project.totals.outsourcing : 0;
      if (currentOutsourcingTab === 'manual_outsourcing') {
        rows = project.manual_outsourcing || [];
        columns = [
          {key:'expense_date', label:'일자', align:'center'},
          {key:'category', label:'구분'},
          {key:'company_name', label:'업체명'},
          {key:'amount', label:'금액', format:'money', align:'right'},
          {key:'memo', label:'비고', remark:true}
        ];
      } else {
        rows = project.labor_outsourcing || [];
        columns = [
          {key:'name', label:'성명'},
          {key:'ratio_label', label:'외주비 비율', align:'center'},
          {key:'output_days', label:'출력일수', format:'count', align:'right'},
          {key:'total_gongsu', label:'총공수', format:'count', align:'right'},
          {key:'wage_rate', label:'단가', format:'money', align:'right'},
          {key:'amount', label:'지급총액', format:'money', align:'right'},
          {key:'company_name', label:'업체명'}
        ];
      }
    }

    title.textContent = project.project_name + ' · ' + label;
    meta.textContent = String(project.ym || '').replace('-', '.') + ' 기준';
    total.textContent = '총합계 ' + formatMoney(totalValue) + '원';
    body.innerHTML = tableHtml(columns, rows);
  }

  function openModal(projectId, detailType) {
    currentProjectId = String(projectId || '');
    currentType = String(detailType || '');
    currentOutsourcingTab = 'labor_outsourcing';
    if (tabs) {
      if (currentType === 'outsourcing') tabs.classList.add('is-visible');
      else tabs.classList.remove('is-visible');
    }
    setActiveOutsourcingTab('labor_outsourcing');
    if (modal) {
      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('cpms-cost-detail-open');
    }
  }

  function closeModal() {
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('cpms-cost-detail-open');
  }

  document.addEventListener('click', function(event) {
    var target = event.target;
    var trigger = target && target.closest ? target.closest('.cpms-monthly-detail-trigger') : null;
    if (trigger) {
      event.preventDefault();
      openModal(trigger.getAttribute('data-project-id'), trigger.getAttribute('data-detail-type'));
      return;
    }
    var closeButton = target && target.closest ? target.closest('[data-cost-detail-close]') : null;
    if (closeButton) {
      event.preventDefault();
      closeModal();
      return;
    }
    var tabButton = target && target.closest ? target.closest('[data-outsourcing-tab]') : null;
    if (tabButton) {
      event.preventDefault();
      setActiveOutsourcingTab(tabButton.getAttribute('data-outsourcing-tab'));
      return;
    }
    if (target === modal) closeModal();
  });

  document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape' && modal && modal.classList.contains('is-open')) closeModal();
  });
})();
</script>
