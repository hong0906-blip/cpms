<?php
/**
 * 파일: app/views/project/partials/monthly_cost_detail_helper.php
 * 월별 투입비 상세 모달 공통 데이터 helper
 * - 공무 > 월별 투입비 집계
 * - 공사 > 투입비 상세
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../construction/tabs/partials/labor_data_loader.php';
require_once __DIR__ . '/../../construction/tabs/partials/outsourcing_data_helper.php';
require_once __DIR__ . '/../../construction/partials/material_statement_helper.php';
require_once __DIR__ . '/../../construction/partials/equipment_statement_helper.php';
require_once __DIR__ . '/../../safety/safety_cost_helper.php';
require_once __DIR__ . '/../../../services/CostChangeService.php';
require_once __DIR__ . '/../../../services/VendorService.php';

if (!function_exists('cpms_monthly_summary_ym_valid')) {
function cpms_monthly_summary_ym_valid($ym) {
    return preg_match('/^\d{4}-\d{2}$/', (string)$ym) ? true : false;
}}

if (!function_exists('cpms_monthly_summary_table_exists')) {
function cpms_monthly_summary_table_exists($pdo, $table) {
    if (!$pdo || trim((string)$table) === '') return false;
    try {
        $st = $pdo->prepare('SHOW TABLES LIKE :t');
        $st->bindValue(':t', (string)$table);
        $st->execute();
        return is_array($st->fetch());
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_monthly_summary_column_exists')) {
function cpms_monthly_summary_column_exists($pdo, $table, $column) {
    if (!$pdo || trim((string)$table) === '' || trim((string)$column) === '') return false;
    try {
        $st = $pdo->prepare('SHOW COLUMNS FROM `' . str_replace('`', '', (string)$table) . '` LIKE :c');
        $st->bindValue(':c', (string)$column);
        $st->execute();
        return is_array($st->fetch());
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_monthly_summary_parse_money')) {
function cpms_monthly_summary_parse_money($value) {
    if (function_exists('cpms_parse_money_value')) return (float)cpms_parse_money_value($value);
    $raw = preg_replace('/[^0-9.\-]/', '', (string)$value);
    if ($raw === '' || !is_numeric($raw)) return 0.0;
    return (float)$raw;
}}

if (!function_exists('cpms_monthly_summary_first_text')) {
function cpms_monthly_summary_first_text($row, $keys) {
    if (!is_array($row) || !is_array($keys)) return '';
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row)) continue;
        $value = trim((string)$row[$key]);
        if ($value !== '') return $value;
    }
    return '';
}}

if (!function_exists('cpms_monthly_summary_period')) {
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
}}

if (!function_exists('cpms_monthly_cost_detail_file_payload')) {
function cpms_monthly_cost_detail_file_payload($fileType, $row, $routeId) {
    $routeId = (int)$routeId;
    if ($routeId <= 0 || !is_array($row)) return null;
    $name = isset($row['original_name']) ? trim((string)$row['original_name']) : '';
    if ($name === '' && isset($row['statement_original_name'])) $name = trim((string)$row['statement_original_name']);
    if ($name === '') $name = '첨부파일';
    $mime = isset($row['mime_type']) ? trim((string)$row['mime_type']) : '';
    if ($mime === '' && isset($row['statement_mime_type'])) $mime = trim((string)$row['statement_mime_type']);
    $size = isset($row['file_size']) ? (int)$row['file_size'] : 0;
    if ($size <= 0 && isset($row['statement_file_size'])) $size = (int)$row['statement_file_size'];

    if ($fileType === 'material') {
        $base = base_url() . '/?r=construction/material_statement_download&id=' . $routeId;
    } else if ($fileType === 'equipment') {
        $base = base_url() . '/?r=construction/equipment_statement_download&id=' . $routeId;
    } else if ($fileType === 'outsourcing') {
        $base = base_url() . '/?r=construction/outsourcing_file_download&id=' . $routeId;
    } else {
        return null;
    }

    return array(
        'name' => $name,
        'mime_type' => $mime,
        'file_size' => $size,
        'view_url' => $base . '&view=1',
        'download_url' => $base . '&download=1',
    );
}}

if (!function_exists('cpms_monthly_cost_detail_safety_file_payload')) {
function cpms_monthly_cost_detail_safety_file_payload($row) {
    if (!is_array($row)) return null;
    $recordId = isset($row['id']) ? trim((string)$row['id']) : '';
    $pdf = isset($row['pdf']) && is_array($row['pdf']) ? $row['pdf'] : array();
    if ($recordId === '' || count($pdf) === 0) return null;
    if (function_exists('cpms_safety_cost_file_exists') && !cpms_safety_cost_file_exists($row)) return null;

    $name = isset($pdf['original_name']) ? trim((string)$pdf['original_name']) : '';
    if ($name === '') $name = '안전관리비 명세표.pdf';
    $mime = isset($pdf['mime_type']) ? trim((string)$pdf['mime_type']) : 'application/pdf';
    $size = isset($pdf['file_size']) ? (int)$pdf['file_size'] : 0;
    $base = base_url() . '/?r=safety/safety_cost_download&id=' . rawurlencode($recordId);

    return array(
        'name' => $name,
        'mime_type' => $mime,
        'file_size' => $size,
        'view_url' => $base,
        'download_url' => $base . '&download=1',
    );
}}

if (!function_exists('cpms_monthly_cost_detail_date_label')) {
function cpms_monthly_cost_detail_date_label($dates) {
    if (!is_array($dates) || count($dates) === 0) return '';
    $unique = array();
    foreach ($dates as $date) {
        $date = trim((string)$date);
        if ($date !== '') $unique[$date] = $date;
    }
    if (count($unique) === 0) return '';
    $values = array_values($unique);
    sort($values);
    if (count($values) === 1) return $values[0];
    return $values[0] . ' 외 ' . (count($values) - 1) . '일';
}}

if (!function_exists('cpms_monthly_summary_labor_detail_rows')) {
function cpms_monthly_summary_labor_detail_rows($pdo, $projectId, $projectName, $ym) {
    $result = array(
        'labor' => array(),
        'labor_outsourcing' => array(),
        'labor_total' => 0.0,
        'labor_outsourcing_total' => 0.0,
    );
    if (!$pdo || (int)$projectId <= 0 || trim((string)$projectName) === '' || !cpms_monthly_summary_ym_valid($ym)) return $result;
    if (!function_exists('cpms_load_project_labor_workers') || !function_exists('cpms_build_timesheet_workers') || !function_exists('cpms_load_gongsu_data')) return $result;

    try {
        $directMembers = function_exists('cpms_load_direct_team_members') ? cpms_load_direct_team_members($pdo) : array();
        $projectWorkers = cpms_load_project_labor_workers($pdo, (int)$projectId);
        if (function_exists('cpms_load_project_labor_worker_month_ratio_map') && function_exists('cpms_apply_project_labor_worker_month_ratios')) {
            $ratioMap = cpms_load_project_labor_worker_month_ratio_map($pdo, (int)$projectId, (string)$ym, $projectWorkers);
            $projectWorkers = cpms_apply_project_labor_worker_month_ratios($projectWorkers, $ratioMap);
            $projectWorkers = cpms_apply_project_labor_worker_month_wages($projectWorkers, cpms_load_project_labor_worker_wage_map($pdo, (int)$projectId, (string)$ym));
        }
        $workerRows = cpms_build_project_worker_rows($projectWorkers, $directMembers, $pdo, (string)$ym);
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
            $hasRange = preg_match('/^\d{4}-\d{2}-\d{2}$/', $outsourcingStart)
                && preg_match('/^\d{4}-\d{2}-\d{2}$/', $outsourcingEnd)
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
                if ($hasRange && !($dateKey >= $outsourcingStart && $dateKey <= $outsourcingEnd)) $dayOutsourcingRatio = 0;
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
                    'worker_key' => $workerKey,
                    'company_name' => $companyName,
                    'ratio_label' => $laborRatioLabel,
                    'output_days' => $laborOutputDays,
                    'total_gongsu' => $laborGongsu,
                    'wage_rate' => $wageRate,
                    'amount' => $laborAmount,
                    'remark' => $remark,
                    'files' => array(),
                );
                $result['labor_total'] += $laborAmount;
            }

            if ($outsourcingAmount > 0 && $outsourcingGongsu > 0) {
                $outsourcingRatioLabel = (string)$outsourcingRatio . '%';
                if ($hasRange) $outsourcingRatioLabel .= ' (선택기간)';
                $result['labor_outsourcing'][] = array(
                    'name' => $workerName,
                    'worker_key' => $workerKey,
                    'ratio_label' => $outsourcingRatioLabel,
                    'output_days' => $outsourcingOutputDays,
                    'total_gongsu' => $outsourcingGongsu,
                    'wage_rate' => $wageRate,
                    'amount' => $outsourcingAmount,
                    'company_name' => $companyName,
                    'remark' => $remark,
                    'files' => array(),
                );
                $result['labor_outsourcing_total'] += $outsourcingAmount;
            }
        }

        if (cpms_monthly_summary_table_exists($pdo, 'cpms_labor_force_adjustments')) {
            try {
                $stForce = $pdo->prepare('SELECT id, amount, memo FROM cpms_labor_force_adjustments WHERE project_id = :pid AND month = :ym LIMIT 1');
                $stForce->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
                $stForce->bindValue(':ym', (string)$ym);
                $stForce->execute();
                $forceRow = $stForce->fetch(PDO::FETCH_ASSOC);
                $forceAmount = is_array($forceRow) && isset($forceRow['amount']) ? (float)$forceRow['amount'] : 0.0;
                if ($forceAmount > 0) {
                    $result['labor'][] = array(
                        'name' => '노무비 강제입력',
                        'worker_key' => 'labor_force_adjustment',
                        'company_name' => '창명건설',
                        'source_type' => 'labor_force_adjustment',
                        'source_id' => is_array($forceRow) && isset($forceRow['id']) ? (string)$forceRow['id'] : '',
                        'ratio_label' => '100%',
                        'output_days' => null,
                        'total_gongsu' => null,
                        'wage_rate' => null,
                        'amount' => $forceAmount,
                        'remark' => is_array($forceRow) && isset($forceRow['memo']) ? trim((string)$forceRow['memo']) : '',
                        'files' => array(),
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
        $companyCompare = strcmp((string)$a['company_name'], (string)$b['company_name']);
        if ($companyCompare !== 0) return $companyCompare;
        return strcmp((string)$a['name'], (string)$b['name']);
    });
    usort($result['labor_outsourcing'], function($a, $b) {
        $companyCompare = strcmp((string)$a['company_name'], (string)$b['company_name']);
        if ($companyCompare !== 0) return $companyCompare;
        return strcmp((string)$a['name'], (string)$b['name']);
    });
    return $result;
}}

if (!function_exists('cpms_monthly_summary_equipment_detail_rows')) {
function cpms_monthly_summary_equipment_detail_rows($pdo, $projectId, $ym) {
    $result = array('rows' => array(), 'total' => 0.0);
    if (!$pdo || (int)$projectId <= 0 || !cpms_monthly_summary_ym_valid($ym)) return $result;
    if (!cpms_monthly_summary_table_exists($pdo, 'cpms_equipment_items') || !cpms_monthly_summary_table_exists($pdo, 'cpms_equipment_usage')) return $result;

    try {
        $metaInstalled = \App\Services\CostChangeService::isInstalled($pdo);
        $hasDeleted = cpms_monthly_summary_column_exists($pdo, 'cpms_equipment_items', 'is_deleted');
        $metaJoin = $metaInstalled ? " LEFT JOIN cpms_cost_record_meta crm ON crm.target_type='equipment' AND crm.target_id=CAST(u.id AS CHAR)" : '';
        \App\Services\VendorService::bootstrap($pdo, true);
        $equipmentVendorIdSelect = \App\Services\VendorService::hasVendorReference($pdo, 'cpms_equipment_items') ? 'e.vendor_id' : '0 AS vendor_id';
        $sql = "SELECT u.*, " . $equipmentVendorIdSelect . ", e.category, e.vendor_name, e.spec, e.base_rate, e.remark AS item_remark
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
        $rows = \App\Services\VendorService::applyCurrentVendorRows($pdo, $rows, 'vendor_name', '', '', '');

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
                    'use_dates' => array(),
                    'use_date_label' => '',
                    'category' => $categoryLabel,
                    'vendor_name' => $vendorName,
                    'total_work_unit' => 0.0,
                    'base_rate' => $rate,
                    'amount' => 0.0,
                    'remark' => $remark,
                    'files' => array(),
                    'source_type' => 'equipment',
                    'source_ids' => array(),
                    '_file_keys' => array(),
                );
            }
            $useDate = isset($row['use_date']) ? trim((string)$row['use_date']) : '';
            if ($useDate !== '') $grouped[$groupKey]['use_dates'][$useDate] = $useDate;
            $grouped[$groupKey]['total_work_unit'] += $workUnit;
            $grouped[$groupKey]['amount'] += $amount;
            $result['total'] += $amount;

            $usageId = isset($row['id']) ? (int)$row['id'] : 0;
            if ($usageId > 0) $grouped[$groupKey]['source_ids'][] = (string)$usageId;
            $storedPath = isset($row['statement_stored_path']) ? trim((string)$row['statement_stored_path']) : '';
            if ($storedPath !== '' && $usageId > 0) {
                $fileKey = $storedPath;
                if (!isset($grouped[$groupKey]['_file_keys'][$fileKey])) {
                    $filePayload = cpms_monthly_cost_detail_file_payload('equipment', $row, $usageId);
                    if (is_array($filePayload)) $grouped[$groupKey]['files'][] = $filePayload;
                    $grouped[$groupKey]['_file_keys'][$fileKey] = true;
                }
            }
        }
        foreach ($grouped as $groupKey => $groupRow) {
            $grouped[$groupKey]['use_date_label'] = cpms_monthly_cost_detail_date_label(isset($groupRow['use_dates']) ? $groupRow['use_dates'] : array());
            unset($grouped[$groupKey]['use_dates']);
            unset($grouped[$groupKey]['_file_keys']);
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
}}

if (!function_exists('cpms_monthly_summary_material_detail_rows')) {
function cpms_monthly_summary_material_detail_rows($pdo, $projectId, $ym) {
    $result = array('rows' => array(), 'total' => 0.0);
    if (!$pdo || (int)$projectId <= 0 || !cpms_monthly_summary_ym_valid($ym)) return $result;
    if (!cpms_monthly_summary_table_exists($pdo, 'cpms_material_items') || !cpms_monthly_summary_table_exists($pdo, 'cpms_material_usage')) return $result;

    try {
        $metaInstalled = \App\Services\CostChangeService::isInstalled($pdo);
        $hasDeleted = cpms_monthly_summary_column_exists($pdo, 'cpms_material_items', 'is_deleted');
        $metaJoin = $metaInstalled ? " LEFT JOIN cpms_cost_record_meta crm ON crm.target_type='material' AND crm.target_id=CAST(u.id AS CHAR)" : '';
        \App\Services\VendorService::bootstrap($pdo, true);
        $materialVendorIdSelect = \App\Services\VendorService::hasVendorReference($pdo, 'cpms_material_items') ? 'm.vendor_id' : '0 AS vendor_id';
        $sql = "SELECT u.*, " . $materialVendorIdSelect . ", m.category, m.vendor_name, m.remark AS item_remark
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
        $rows = \App\Services\VendorService::applyCurrentVendorRows($pdo, $rows, 'vendor_name', '', '', '');

        $usageIds = array();
        foreach ($rows as $row) {
            $usageId = isset($row['id']) ? (int)$row['id'] : 0;
            if ($usageId > 0) $usageIds[] = $usageId;
        }
        $fileMap = function_exists('cpms_material_statement_files_by_usage_ids')
            ? cpms_material_statement_files_by_usage_ids($pdo, $usageIds)
            : array();

        foreach ($rows as $row) {
            $amount = isset($row['amount']) ? (float)$row['amount'] : 0.0;
            $usageId = isset($row['id']) ? (int)$row['id'] : 0;
            $files = array();
            if ($usageId > 0 && isset($fileMap[$usageId]) && is_array($fileMap[$usageId])) {
                foreach ($fileMap[$usageId] as $fileRow) {
                    $fileId = isset($fileRow['id']) ? (int)$fileRow['id'] : 0;
                    $filePayload = cpms_monthly_cost_detail_file_payload('material', $fileRow, $fileId);
                    if (is_array($filePayload)) $files[] = $filePayload;
                }
            }
            $result['rows'][] = array(
                'use_date' => isset($row['use_date']) ? (string)$row['use_date'] : '',
                'category' => isset($row['category']) ? trim((string)$row['category']) : '',
                'vendor_name' => isset($row['vendor_name']) ? trim((string)$row['vendor_name']) : '',
                'amount' => $amount,
                'remark' => cpms_monthly_summary_first_text($row, array('memo', 'usage_memo', 'use_content', 'item_name', 'remark', 'item_remark')),
                'files' => $files,
                'source_type' => 'material',
                'source_id' => $usageId > 0 ? (string)$usageId : '',
            );
            $result['total'] += $amount;
        }
    } catch (Exception $e) {
        return $result;
    }
    return $result;
}}

if (!function_exists('cpms_monthly_summary_safety_detail_rows')) {
function cpms_monthly_summary_safety_detail_rows($pdo, $projectId, $ym) {
    $result = array('rows' => array(), 'total' => 0.0);
    if (!$pdo || (int)$projectId <= 0 || !cpms_monthly_summary_ym_valid($ym) || !function_exists('cpms_safety_cost_project_items')) return $result;

    try {
        $rows = cpms_safety_cost_project_items((int)$projectId);
        if (!is_array($rows)) $rows = array();
        $rows = \App\Services\VendorService::applyCurrentVendorRows($pdo, $rows, 'vendor_name', 'representative', 'phone', 'biz_no');
        foreach ($rows as $row) {
            $useDate = isset($row['use_date']) ? cpms_safety_cost_valid_date($row['use_date']) : '';
            if ($useDate === '') continue;
            $recordId = isset($row['id']) ? trim((string)$row['id']) : '';
            $settlementYm = \App\Services\CostChangeService::effectiveSettlementYm(
                $pdo,
                'safety',
                $recordId,
                'safety',
                $useDate
            );
            if ((string)$settlementYm !== (string)$ym) continue;

            $amount = function_exists('cpms_safety_cost_row_amount') ? (float)cpms_safety_cost_row_amount($row) : 0.0;
            $files = array();
            $filePayload = cpms_monthly_cost_detail_safety_file_payload($row);
            if (is_array($filePayload)) $files[] = $filePayload;
            $result['rows'][] = array(
                'use_date' => $useDate,
                'category' => isset($row['category']) ? trim((string)$row['category']) : '안전관리비',
                'vendor_name' => isset($row['vendor_name']) ? trim((string)$row['vendor_name']) : '',
                'item_name' => cpms_monthly_summary_first_text($row, array('item_name', 'use_content')),
                'amount' => $amount,
                'remark' => cpms_monthly_summary_first_text($row, array('remark', 'use_content')),
                'files' => $files,
                'source_type' => 'safety',
                'source_id' => $recordId,
            );
            $result['total'] += $amount;
        }
    } catch (Exception $e) {
        return $result;
    }
    return $result;
}}

if (!function_exists('cpms_monthly_summary_manual_outsourcing_detail_rows')) {
function cpms_monthly_summary_manual_outsourcing_detail_rows($pdo, $projectId, $ym) {
    $result = array('rows' => array(), 'total' => 0.0);
    if (!$pdo || (int)$projectId <= 0 || !cpms_monthly_summary_ym_valid($ym) || !function_exists('cpms_outsourcing_manual_rows')) return $result;
    $period = cpms_monthly_summary_period('outsourcing', $ym);
    try {
        $rows = cpms_outsourcing_manual_rows($pdo, (int)$projectId, (string)$period['start'], (string)$period['end']);
        if (!is_array($rows)) $rows = array();
        $costIds = array();
        foreach ($rows as $row) {
            $costId = isset($row['id']) ? (int)$row['id'] : 0;
            if ($costId > 0) $costIds[] = $costId;
        }
        $fileMap = function_exists('cpms_outsourcing_files_by_cost_ids') ? cpms_outsourcing_files_by_cost_ids($pdo, $costIds) : array();
        foreach ($rows as $row) {
            $amount = isset($row['amount']) ? (float)$row['amount'] : 0.0;
            $costId = isset($row['id']) ? (int)$row['id'] : 0;
            $files = array();
            if ($costId > 0 && isset($fileMap[$costId]) && is_array($fileMap[$costId])) {
                foreach ($fileMap[$costId] as $fileRow) {
                    $fileId = isset($fileRow['id']) ? (int)$fileRow['id'] : 0;
                    $filePayload = cpms_monthly_cost_detail_file_payload('outsourcing', $fileRow, $fileId);
                    if (is_array($filePayload)) $files[] = $filePayload;
                }
            }
            $result['rows'][] = array(
                'expense_date' => isset($row['expense_date']) ? (string)$row['expense_date'] : '',
                'category' => isset($row['category']) ? trim((string)$row['category']) : '외주비',
                'company_name' => isset($row['company_name']) ? trim((string)$row['company_name']) : '',
                'amount' => $amount,
                'memo' => isset($row['memo']) ? trim((string)$row['memo']) : '',
                'files' => $files,
                'source_type' => 'outsourcing',
                'source_id' => $costId > 0 ? (string)$costId : '',
            );
            $result['total'] += $amount;
        }
    } catch (Exception $e) {
        return $result;
    }
    return $result;
}}

if (!function_exists('cpms_monthly_cost_detail_month_payload')) {
function cpms_monthly_cost_detail_month_payload($pdo, $projectId, $projectName, $ym, $totals) {
    if (!is_array($totals)) $totals = array();
    $labor = cpms_monthly_summary_labor_detail_rows($pdo, $projectId, $projectName, $ym);
    $equipment = cpms_monthly_summary_equipment_detail_rows($pdo, $projectId, $ym);
    $material = cpms_monthly_summary_material_detail_rows($pdo, $projectId, $ym);
    $safety = cpms_monthly_summary_safety_detail_rows($pdo, $projectId, $ym);
    $manualOutsourcing = cpms_monthly_summary_manual_outsourcing_detail_rows($pdo, $projectId, $ym);

    $calculatedLabor = isset($labor['labor_total']) ? (float)$labor['labor_total'] : 0.0;
    $calculatedLaborOutsourcing = isset($labor['labor_outsourcing_total']) ? (float)$labor['labor_outsourcing_total'] : 0.0;
    $calculatedEquipment = isset($equipment['total']) ? (float)$equipment['total'] : 0.0;
    $calculatedMaterial = isset($material['total']) ? (float)$material['total'] : 0.0;
    $calculatedSafety = isset($safety['total']) ? (float)$safety['total'] : 0.0;
    $calculatedManualOutsourcing = isset($manualOutsourcing['total']) ? (float)$manualOutsourcing['total'] : 0.0;

    return array(
        'ym' => (string)$ym,
        'totals' => array(
            'labor' => isset($totals['labor']) ? (float)$totals['labor'] : $calculatedLabor,
            'equipment' => isset($totals['equipment']) ? (float)$totals['equipment'] : $calculatedEquipment,
            'material' => isset($totals['material']) ? (float)$totals['material'] : $calculatedMaterial,
            'safety' => isset($totals['safety']) ? (float)$totals['safety'] : $calculatedSafety,
            'outsourcing' => isset($totals['outsourcing']) ? (float)$totals['outsourcing'] : ($calculatedLaborOutsourcing + $calculatedManualOutsourcing),
        ),
        'labor' => isset($labor['labor']) ? $labor['labor'] : array(),
        'labor_outsourcing' => isset($labor['labor_outsourcing']) ? $labor['labor_outsourcing'] : array(),
        'equipment' => isset($equipment['rows']) ? $equipment['rows'] : array(),
        'material' => isset($material['rows']) ? $material['rows'] : array(),
        'safety' => isset($safety['rows']) ? $safety['rows'] : array(),
        'manual_outsourcing' => isset($manualOutsourcing['rows']) ? $manualOutsourcing['rows'] : array(),
    );
}}

if (!function_exists('cpms_monthly_cost_detail_payload_for_summary_rows')) {
function cpms_monthly_cost_detail_payload_for_summary_rows($pdo, $summaryRows, $ym) {
    $payload = array();
    if (!$pdo || !is_array($summaryRows) || !cpms_monthly_summary_ym_valid($ym)) return $payload;
    foreach ($summaryRows as $summaryRow) {
        $projectId = isset($summaryRow['project_id']) ? (int)$summaryRow['project_id'] : 0;
        $projectName = isset($summaryRow['project_name']) ? (string)$summaryRow['project_name'] : '';
        if ($projectId <= 0) continue;
        $totals = array(
            'labor' => isset($summaryRow['labor_amount']) ? (float)$summaryRow['labor_amount'] : 0.0,
            'equipment' => isset($summaryRow['equipment_amount']) ? (float)$summaryRow['equipment_amount'] : 0.0,
            'material' => isset($summaryRow['material_purchase_amount']) ? (float)$summaryRow['material_purchase_amount'] : 0.0,
            'outsourcing' => isset($summaryRow['outsourcing_amount']) ? (float)$summaryRow['outsourcing_amount'] : 0.0,
        );
        $payload[(string)$projectId] = array(
            'project_name' => $projectName,
            'months' => array(
                (string)$ym => cpms_monthly_cost_detail_month_payload($pdo, $projectId, $projectName, $ym, $totals),
            ),
        );
    }
    return $payload;
}}

if (!function_exists('cpms_monthly_cost_detail_payload_for_project')) {
function cpms_monthly_cost_detail_payload_for_project($pdo, $projectId, $projectName, $months) {
    $payload = array();
    $projectId = (int)$projectId;
    if (!$pdo || $projectId <= 0 || trim((string)$projectName) === '' || !is_array($months)) return $payload;
    $monthPayload = array();
    foreach ($months as $ym) {
        $ym = trim((string)$ym);
        if (!cpms_monthly_summary_ym_valid($ym)) continue;
        $monthPayload[$ym] = cpms_monthly_cost_detail_month_payload($pdo, $projectId, $projectName, $ym, array());
    }
    $payload[(string)$projectId] = array(
        'project_name' => (string)$projectName,
        'months' => $monthPayload,
    );
    return $payload;
}}
