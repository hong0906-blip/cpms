<?php
/**
 * 파일: app/views/project/partials/monthly_summary_snapshot_helper.php
 * 공무 > 월별 투입비 집계의 일일 스냅샷/전일 비교/거래처 정보 helper
 * PHP 5.6 / MySQL 5.6 호환
 */

use App\Core\Auth;

if (!function_exists('cpms_monthly_summary_snapshot_valid_date')) {
function cpms_monthly_summary_snapshot_valid_date($value) {
    $value = trim((string)$value);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) return '';
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $value : '';
}}

if (!function_exists('cpms_monthly_summary_snapshot_valid_ym')) {
function cpms_monthly_summary_snapshot_valid_ym($value) {
    $value = trim((string)$value);
    if (!preg_match('/^(\d{4})-(\d{2})$/', $value, $m)) return '';
    $month = (int)$m[2];
    return ($month >= 1 && $month <= 12) ? $value : '';
}}

if (!function_exists('cpms_monthly_summary_snapshot_table_exists')) {
function cpms_monthly_summary_snapshot_table_exists($pdo, $table) {
    if (!$pdo || !preg_match('/^[A-Za-z0-9_]+$/', (string)$table)) return false;
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table_name');
        $st->bindValue(':table_name', (string)$table, PDO::PARAM_STR);
        $st->execute();
        return ((int)$st->fetchColumn() > 0);
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_monthly_summary_snapshot_column_exists')) {
function cpms_monthly_summary_snapshot_column_exists($pdo, $table, $column) {
    if (!$pdo || !preg_match('/^[A-Za-z0-9_]+$/', (string)$table) || !preg_match('/^[A-Za-z0-9_]+$/', (string)$column)) return false;
    try {
        $st = $pdo->prepare('SHOW COLUMNS FROM `' . $table . '` LIKE :column_name');
        $st->bindValue(':column_name', (string)$column, PDO::PARAM_STR);
        $st->execute();
        return $st->fetch(PDO::FETCH_ASSOC) ? true : false;
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_monthly_summary_can_daily_view')) {
function cpms_monthly_summary_can_daily_view() {
    if (!Auth::check()) return false;

    if (method_exists('App\\Core\\Auth', 'canAccessUsageAnalytics') && Auth::canAccessUsageAnalytics()) {
        return true;
    }

    $name = trim((string)Auth::userName());
    if ($name === '박원덕' || strpos($name, '박원덕') !== false) return true;

    $department = trim((string)Auth::userDepartment());
    $departmentNormalized = str_replace(array('[', ']', ' ', '부', '팀'), '', $department);
    if (strpos($departmentNormalized, '개발') !== false) return true;

    $position = method_exists('App\\Core\\Auth', 'userPosition') ? trim((string)Auth::userPosition()) : '';
    $storedRole = method_exists('App\\Core\\Auth', 'userStoredRole') ? trim((string)Auth::userStoredRole()) : '';
    foreach (array($position, $storedRole) as $value) {
        $normalized = str_replace(array(' ', '님'), '', (string)$value);
        if (in_array($normalized, array('대표', '대표이사', '부사장'), true)) return true;
    }
    return false;
}}

if (!function_exists('cpms_monthly_summary_snapshot_dates')) {
function cpms_monthly_summary_snapshot_dates($pdo, $ym) {
    $result = array();
    $ym = cpms_monthly_summary_snapshot_valid_ym($ym);
    if (!$pdo || $ym === '' || !cpms_monthly_summary_snapshot_table_exists($pdo, 'cpms_ai_daily_snapshots')) return $result;
    try {
        $st = $pdo->prepare('SELECT DISTINCT snapshot_date FROM cpms_ai_daily_snapshots WHERE target_ym=:ym ORDER BY snapshot_date DESC');
        $st->bindValue(':ym', $ym, PDO::PARAM_STR);
        $st->execute();
        while ($value = $st->fetchColumn()) {
            $date = cpms_monthly_summary_snapshot_valid_date($value);
            if ($date !== '') $result[] = $date;
        }
    } catch (Exception $e) {
        return array();
    }
    return $result;
}}

if (!function_exists('cpms_monthly_summary_snapshot_load_map')) {
function cpms_monthly_summary_snapshot_load_map($pdo, $snapshotDate) {
    $map = array();
    $snapshotDate = cpms_monthly_summary_snapshot_valid_date($snapshotDate);
    if (!$pdo || $snapshotDate === '' || !cpms_monthly_summary_snapshot_table_exists($pdo, 'cpms_ai_daily_snapshots')) return $map;
    try {
        $sql = 'SELECT snapshot_date,target_ym,captured_at,project_id,project_name_snapshot,contract_amount,monthly_sales_amount,cumulative_sales_amount,labor_amount,outsourcing_amount,purchase_amount,material_amount,equipment_amount,other_expense_amount,safety_amount,health_amount,other_amount,monthly_input_amount,cumulative_input_amount,monthly_profit_amount,monthly_cost_rate,cumulative_profit_amount,cumulative_cost_rate,today_event_count,month_event_count,latest_event_at,last_captured_at FROM cpms_ai_daily_snapshots WHERE snapshot_date=:snapshot_date';
        $st = $pdo->prepare($sql);
        $st->bindValue(':snapshot_date', $snapshotDate, PDO::PARAM_STR);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $projectId = isset($row['project_id']) ? (int)$row['project_id'] : 0;
                if ($projectId > 0) $map[$projectId] = $row;
            }
        }
    } catch (Exception $e) {
        return array();
    }
    return $map;
}}

if (!function_exists('cpms_monthly_summary_snapshot_context')) {
function cpms_monthly_summary_snapshot_context($pdo, $ym, $requestedDate, $dailyMode) {
    $ym = cpms_monthly_summary_snapshot_valid_ym($ym);
    $dates = cpms_monthly_summary_snapshot_dates($pdo, $ym);
    $selectedDate = cpms_monthly_summary_snapshot_valid_date($requestedDate);
    if ($selectedDate !== '' && substr($selectedDate, 0, 7) !== $ym) $selectedDate = '';
    if ($selectedDate === '' || !in_array($selectedDate, $dates, true)) {
        $selectedDate = count($dates) > 0 ? $dates[0] : '';
    }

    $previousDate = '';
    if ($selectedDate !== '') {
        foreach ($dates as $date) {
            if ($date < $selectedDate) {
                $previousDate = $date;
                break;
            }
        }
    }

    return array(
        'installed' => cpms_monthly_summary_snapshot_table_exists($pdo, 'cpms_ai_daily_snapshots'),
        'mode' => $dailyMode ? 'daily' : 'monthly',
        'dates' => $dates,
        'selected_date' => $selectedDate,
        'previous_date' => $previousDate,
        'current' => $selectedDate !== '' ? cpms_monthly_summary_snapshot_load_map($pdo, $selectedDate) : array(),
        'previous' => $previousDate !== '' ? cpms_monthly_summary_snapshot_load_map($pdo, $previousDate) : array(),
    );
}}

if (!function_exists('cpms_monthly_summary_snapshot_amount')) {
function cpms_monthly_summary_snapshot_amount($row, $key) {
    return is_array($row) && isset($row[$key]) ? (float)$row[$key] : 0.0;
}}

if (!function_exists('cpms_monthly_summary_snapshot_delta_map')) {
function cpms_monthly_summary_snapshot_delta_map($current, $previous) {
    $currentMaterial = cpms_monthly_summary_snapshot_amount($current, 'purchase_amount')
        + cpms_monthly_summary_snapshot_amount($current, 'material_amount')
        + cpms_monthly_summary_snapshot_amount($current, 'other_expense_amount');
    $previousMaterial = cpms_monthly_summary_snapshot_amount($previous, 'purchase_amount')
        + cpms_monthly_summary_snapshot_amount($previous, 'material_amount')
        + cpms_monthly_summary_snapshot_amount($previous, 'other_expense_amount');
    return array(
        'labor' => cpms_monthly_summary_snapshot_amount($current, 'labor_amount') - cpms_monthly_summary_snapshot_amount($previous, 'labor_amount'),
        'equipment' => cpms_monthly_summary_snapshot_amount($current, 'equipment_amount') - cpms_monthly_summary_snapshot_amount($previous, 'equipment_amount'),
        'material' => $currentMaterial - $previousMaterial,
        'outsourcing' => cpms_monthly_summary_snapshot_amount($current, 'outsourcing_amount') - cpms_monthly_summary_snapshot_amount($previous, 'outsourcing_amount'),
        'monthly_total' => cpms_monthly_summary_snapshot_amount($current, 'monthly_input_amount') - cpms_monthly_summary_snapshot_amount($previous, 'monthly_input_amount'),
        'cumulative_input' => cpms_monthly_summary_snapshot_amount($current, 'cumulative_input_amount') - cpms_monthly_summary_snapshot_amount($previous, 'cumulative_input_amount'),
    );
}}

if (!function_exists('cpms_monthly_summary_snapshot_metrics')) {
function cpms_monthly_summary_snapshot_metrics($project, $current, $previous, $remark, $selectedDate, $previousDate) {
    $projectId = isset($project['id']) ? (int)$project['id'] : 0;
    $projectName = isset($project['name']) ? (string)$project['name'] : '';
    $materialPurchase = cpms_monthly_summary_snapshot_amount($current, 'purchase_amount')
        + cpms_monthly_summary_snapshot_amount($current, 'material_amount')
        + cpms_monthly_summary_snapshot_amount($current, 'other_expense_amount');
    $deltas = cpms_monthly_summary_snapshot_delta_map($current, $previous);
    $breakdown = array(
        'labor' => cpms_monthly_summary_snapshot_amount($current, 'labor_amount'),
        'outsourcing' => cpms_monthly_summary_snapshot_amount($current, 'outsourcing_amount'),
        'equipment' => cpms_monthly_summary_snapshot_amount($current, 'equipment_amount'),
        'material' => cpms_monthly_summary_snapshot_amount($current, 'material_amount'),
        'purchase' => cpms_monthly_summary_snapshot_amount($current, 'purchase_amount'),
        'other' => cpms_monthly_summary_snapshot_amount($current, 'other_expense_amount'),
        'safety' => cpms_monthly_summary_snapshot_amount($current, 'safety_amount') + cpms_monthly_summary_snapshot_amount($current, 'health_amount'),
        'deduction' => cpms_monthly_summary_snapshot_amount($current, 'other_amount'),
    );
    $monthInput = cpms_monthly_summary_snapshot_amount($current, 'monthly_input_amount');
    $totalInput = cpms_monthly_summary_snapshot_amount($current, 'cumulative_input_amount');
    return array(
        'project_id' => $projectId,
        'project_name' => $projectName,
        'contract_amount' => isset($project['contract_amount']) ? (float)$project['contract_amount'] : cpms_monthly_summary_snapshot_amount($current, 'contract_amount'),
        'previous_input' => $totalInput - $monthInput,
        'month_input' => $monthInput,
        'total_input' => $totalInput,
        'labor_amount' => cpms_monthly_summary_snapshot_amount($current, 'labor_amount'),
        'equipment_amount' => cpms_monthly_summary_snapshot_amount($current, 'equipment_amount'),
        'material_purchase_amount' => $materialPurchase,
        'outsourcing_amount' => cpms_monthly_summary_snapshot_amount($current, 'outsourcing_amount'),
        'monthly_cost_total' => $monthInput,
        'current_revenue' => cpms_monthly_summary_snapshot_amount($current, 'monthly_sales_amount'),
        'worker_output_days' => 0.0,
        'equipment' => array('excavator'=>0.0, 'dump'=>0.0, 'other'=>0.0, 'forklift'=>0.0),
        'cumulative_input' => $totalInput,
        'cumulative_revenue' => cpms_monthly_summary_snapshot_amount($current, 'cumulative_sales_amount'),
        'breakdown' => $breakdown,
        'remark' => (string)$remark,
        'snapshot_date' => (string)$selectedDate,
        'previous_snapshot_date' => (string)$previousDate,
        'daily_delta' => $deltas,
        'snapshot_fast_path' => true,
        'representative_name' => isset($project['representative_name']) ? (string)$project['representative_name'] : '',
        'contact' => isset($project['contact']) ? (string)$project['contact'] : '',
        'business_no' => isset($project['business_no']) ? (string)$project['business_no'] : '',
    );
}}

if (!function_exists('cpms_monthly_summary_contact_map')) {
function cpms_monthly_summary_contact_map($pdo) {
    $map = array();
    if (!$pdo || !cpms_monthly_summary_snapshot_table_exists($pdo, 'cpms_outsourcing_costs')) return $map;
    foreach (array('project_id','representative_name','contact','business_no','id') as $column) {
        if (!cpms_monthly_summary_snapshot_column_exists($pdo, 'cpms_outsourcing_costs', $column)) return $map;
    }
    try {
        $sql = "SELECT o.project_id,o.representative_name,o.contact,o.business_no,o.company_name
                  FROM cpms_outsourcing_costs o
                  INNER JOIN (
                    SELECT project_id,MAX(id) AS max_id
                      FROM cpms_outsourcing_costs
                     WHERE project_id IS NOT NULL
                       AND project_id>0
                       AND (is_deleted=0 OR is_deleted IS NULL)
                       AND (COALESCE(representative_name,'')<>'' OR COALESCE(contact,'')<>'' OR COALESCE(business_no,'')<>'')
                     GROUP BY project_id
                  ) x ON x.max_id=o.id";
        $st = $pdo->query($sql);
        $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $projectId = isset($row['project_id']) ? (int)$row['project_id'] : 0;
                if ($projectId <= 0) continue;
                $map[$projectId] = array(
                    'representative_name' => isset($row['representative_name']) ? trim((string)$row['representative_name']) : '',
                    'contact' => isset($row['contact']) ? trim((string)$row['contact']) : '',
                    'business_no' => isset($row['business_no']) ? trim((string)$row['business_no']) : '',
                    'company_name' => isset($row['company_name']) ? trim((string)$row['company_name']) : '',
                );
            }
        }
    } catch (Exception $e) {
        return array();
    }
    return $map;
}}

if (!function_exists('cpms_monthly_summary_apply_contact')) {
function cpms_monthly_summary_apply_contact($project, $contactMap) {
    if (!is_array($project)) $project = array();
    $projectId = isset($project['id']) ? (int)$project['id'] : 0;
    $contact = ($projectId > 0 && isset($contactMap[$projectId]) && is_array($contactMap[$projectId])) ? $contactMap[$projectId] : array();
    $project['representative_name'] = isset($contact['representative_name']) ? (string)$contact['representative_name'] : '';
    $project['contact'] = isset($contact['contact']) ? (string)$contact['contact'] : '';
    $project['business_no'] = isset($contact['business_no']) ? (string)$contact['business_no'] : '';
    return $project;
}}

if (!function_exists('cpms_monthly_summary_event_change_context')) {
function cpms_monthly_summary_event_change_context($pdo, $projectId, $snapshotDate, $ym) {
    $empty = array('deltas'=>array(), 'target_ids'=>array(), 'target_deltas'=>array());
    $projectId = (int)$projectId;
    $snapshotDate = cpms_monthly_summary_snapshot_valid_date($snapshotDate);
    $ym = cpms_monthly_summary_snapshot_valid_ym($ym);
    if (!$pdo || $projectId <= 0 || $snapshotDate === '' || $ym === '' || !cpms_monthly_summary_snapshot_table_exists($pdo, 'cpms_cost_data_events')) return $empty;
    try {
        $sql = "SELECT cost_type,target_type,target_id,delta_amount,event_action,event_at
                  FROM cpms_cost_data_events
                 WHERE project_id=:project_id
                   AND event_at>=:day_start
                   AND event_at<:day_end
                   AND (settlement_ym=:ym OR settlement_ym IS NULL OR settlement_ym='')
                 ORDER BY event_at ASC,id ASC";
        $st = $pdo->prepare($sql);
        $st->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        $st->bindValue(':day_start', $snapshotDate . ' 00:00:00', PDO::PARAM_STR);
        $st->bindValue(':day_end', date('Y-m-d', strtotime($snapshotDate . ' +1 day')) . ' 00:00:00', PDO::PARAM_STR);
        $st->bindValue(':ym', $ym, PDO::PARAM_STR);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) $rows = array();
        foreach ($rows as $row) {
            $costType = strtolower(trim(isset($row['cost_type']) ? (string)$row['cost_type'] : ''));
            if ($costType === 'purchase' || $costType === 'other_expense') $costType = 'material';
            if (!in_array($costType, array('labor','equipment','material','outsourcing'), true)) continue;
            $delta = isset($row['delta_amount']) ? (float)$row['delta_amount'] : 0.0;
            if (!isset($empty['deltas'][$costType])) $empty['deltas'][$costType] = 0.0;
            $empty['deltas'][$costType] += $delta;
            $targetType = strtolower(trim(isset($row['target_type']) ? (string)$row['target_type'] : ''));
            $targetId = trim(isset($row['target_id']) ? (string)$row['target_id'] : '');
            if ($targetId === '') continue;
            if (!isset($empty['target_ids'][$costType])) $empty['target_ids'][$costType] = array();
            if (!isset($empty['target_ids'][$costType][$targetType])) $empty['target_ids'][$costType][$targetType] = array();
            $empty['target_ids'][$costType][$targetType][$targetId] = true;
            if (!isset($empty['target_deltas'][$costType])) $empty['target_deltas'][$costType] = array();
            if (!isset($empty['target_deltas'][$costType][$targetType])) $empty['target_deltas'][$costType][$targetType] = array();
            if (!isset($empty['target_deltas'][$costType][$targetType][$targetId])) $empty['target_deltas'][$costType][$targetType][$targetId] = 0.0;
            $empty['target_deltas'][$costType][$targetType][$targetId] += $delta;
        }
    } catch (Exception $e) {
        return array('deltas'=>array(), 'target_ids'=>array(), 'target_deltas'=>array());
    }
    return $empty;
}}

if (!function_exists('cpms_monthly_summary_project_snapshot_change')) {
function cpms_monthly_summary_project_snapshot_change($pdo, $projectId, $snapshotDate, $ym) {
    $snapshotDate = cpms_monthly_summary_snapshot_valid_date($snapshotDate);
    $ym = cpms_monthly_summary_snapshot_valid_ym($ym);
    $result = array(
        'snapshot_date'=>$snapshotDate,
        'previous_date'=>'',
        'deltas'=>array('labor'=>0.0,'equipment'=>0.0,'material'=>0.0,'outsourcing'=>0.0,'monthly_total'=>0.0),
        'target_ids'=>array(),
        'target_deltas'=>array(),
    );
    if (!$pdo || (int)$projectId <= 0 || $snapshotDate === '' || $ym === '' || !cpms_monthly_summary_snapshot_table_exists($pdo, 'cpms_ai_daily_snapshots')) return $result;
    try {
        $stPrev = $pdo->prepare('SELECT MAX(snapshot_date) FROM cpms_ai_daily_snapshots WHERE project_id=:project_id AND target_ym=:ym AND snapshot_date<:snapshot_date');
        $stPrev->bindValue(':project_id', (int)$projectId, PDO::PARAM_INT);
        $stPrev->bindValue(':ym', $ym, PDO::PARAM_STR);
        $stPrev->bindValue(':snapshot_date', $snapshotDate, PDO::PARAM_STR);
        $stPrev->execute();
        $previousDate = cpms_monthly_summary_snapshot_valid_date($stPrev->fetchColumn());
        $result['previous_date'] = $previousDate;
        $currentMap = cpms_monthly_summary_snapshot_load_map($pdo, $snapshotDate);
        $previousMap = $previousDate !== '' ? cpms_monthly_summary_snapshot_load_map($pdo, $previousDate) : array();
        $current = isset($currentMap[(int)$projectId]) ? $currentMap[(int)$projectId] : array();
        $previous = isset($previousMap[(int)$projectId]) ? $previousMap[(int)$projectId] : array();
        $result['deltas'] = cpms_monthly_summary_snapshot_delta_map($current, $previous);
        $events = cpms_monthly_summary_event_change_context($pdo, $projectId, $snapshotDate, $ym);
        $result['target_ids'] = isset($events['target_ids']) ? $events['target_ids'] : array();
        $result['target_deltas'] = isset($events['target_deltas']) ? $events['target_deltas'] : array();
    } catch (Exception $e) {
        return $result;
    }
    return $result;
}}

if (!function_exists('cpms_monthly_summary_change_target_delta')) {
function cpms_monthly_summary_change_target_delta($change, $costType, $aliases, $targetId) {
    $targetId = trim((string)$targetId);
    if ($targetId === '' || !is_array($change) || !isset($change['target_deltas'][$costType]) || !is_array($change['target_deltas'][$costType])) return 0.0;
    foreach ((array)$aliases as $alias) {
        $alias = strtolower(trim((string)$alias));
        if ($alias === '') continue;
        if (isset($change['target_deltas'][$costType][$alias][$targetId])) return (float)$change['target_deltas'][$costType][$alias][$targetId];
    }
    return 0.0;
}}

if (!function_exists('cpms_monthly_summary_apply_detail_change_context')) {
function cpms_monthly_summary_apply_detail_change_context($monthPayload, $change) {
    if (!is_array($monthPayload)) $monthPayload = array();
    if (!is_array($change)) $change = array();
    $monthPayload['change'] = $change;

    if (isset($monthPayload['equipment']) && is_array($monthPayload['equipment'])) {
        foreach ($monthPayload['equipment'] as $index=>$row) {
            $delta = 0.0;
            $sourceIds = isset($row['source_ids']) && is_array($row['source_ids']) ? $row['source_ids'] : array();
            foreach ($sourceIds as $sourceId) {
                $delta += cpms_monthly_summary_change_target_delta($change, 'equipment', array('equipment','equipment_usage'), $sourceId);
            }
            $monthPayload['equipment'][$index]['change_amount'] = $delta;
            $monthPayload['equipment'][$index]['is_changed'] = $delta > 0.01;
        }
    }

    if (isset($monthPayload['material']) && is_array($monthPayload['material'])) {
        foreach ($monthPayload['material'] as $index=>$row) {
            $sourceId = isset($row['source_id']) ? (string)$row['source_id'] : '';
            $delta = cpms_monthly_summary_change_target_delta($change, 'material', array('material','material_usage'), $sourceId);
            $monthPayload['material'][$index]['change_amount'] = $delta;
            $monthPayload['material'][$index]['is_changed'] = $delta > 0.01;
        }
    }

    if (isset($monthPayload['manual_outsourcing']) && is_array($monthPayload['manual_outsourcing'])) {
        foreach ($monthPayload['manual_outsourcing'] as $index=>$row) {
            $sourceId = isset($row['source_id']) ? (string)$row['source_id'] : '';
            $delta = cpms_monthly_summary_change_target_delta($change, 'outsourcing', array('outsourcing','outsourcing_cost'), $sourceId);
            $monthPayload['manual_outsourcing'][$index]['change_amount'] = $delta;
            $monthPayload['manual_outsourcing'][$index]['is_changed'] = $delta > 0.01;
        }
    }

    $laborDelta = isset($change['deltas']['labor']) ? (float)$change['deltas']['labor'] : 0.0;
    if (isset($monthPayload['labor']) && is_array($monthPayload['labor']) && $laborDelta > 0.01) {
        foreach ($monthPayload['labor'] as $index=>$row) {
            $monthPayload['labor'][$index]['category_changed'] = true;
        }
    }
    $outsourcingDelta = isset($change['deltas']['outsourcing']) ? (float)$change['deltas']['outsourcing'] : 0.0;
    if (isset($monthPayload['labor_outsourcing']) && is_array($monthPayload['labor_outsourcing']) && $outsourcingDelta > 0.01) {
        foreach ($monthPayload['labor_outsourcing'] as $index=>$row) {
            $monthPayload['labor_outsourcing'][$index]['category_changed'] = true;
        }
    }
    return $monthPayload;
}}
