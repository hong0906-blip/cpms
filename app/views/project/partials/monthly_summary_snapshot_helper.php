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
    );
}}


if (!function_exists('cpms_monthly_summary_snapshot_valid_datetime')) {
function cpms_monthly_summary_snapshot_valid_datetime($value) {
    $value = trim((string)$value);
    return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) ? $value : '';
}}

if (!function_exists('cpms_monthly_summary_event_change_context')) {
function cpms_monthly_summary_event_change_context($pdo, $projectId, $rangeStart, $rangeEnd, $ym) {
    $empty = array('deltas'=>array(), 'target_ids'=>array(), 'target_deltas'=>array(), 'worker_keys'=>array());
    $projectId = (int)$projectId;
    $rangeStart = cpms_monthly_summary_snapshot_valid_datetime($rangeStart);
    $rangeEnd = cpms_monthly_summary_snapshot_valid_datetime($rangeEnd);
    $ym = cpms_monthly_summary_snapshot_valid_ym($ym);
    if (!$pdo || $projectId <= 0 || $rangeStart === '' || $rangeEnd === '' || $ym === '' || $rangeEnd <= $rangeStart || !cpms_monthly_summary_snapshot_table_exists($pdo, 'cpms_cost_data_events')) return $empty;
    try {
        /*
         * 변경 목록은 시간 단위로 자르지 않는다.
         * 비교 스냅샷 날짜 00:00:00부터 현재 스냅샷 날짜 23:59:59까지
         * 날짜 전체를 조회하여, 전날 스냅샷 이후 반영된 행을 빠뜨리지 않는다.
         */
        $sql = "SELECT cost_type,target_type,target_id,delta_amount,event_action,event_at
                  FROM cpms_cost_data_events
                 WHERE project_id=:project_id
                   AND event_at>=:range_start
                   AND event_at<=:range_end
                   AND (settlement_ym=:ym OR settlement_ym IS NULL OR settlement_ym='')
                 ORDER BY event_at ASC,id ASC";
        $st = $pdo->prepare($sql);
        $st->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        $st->bindValue(':range_start', $rangeStart, PDO::PARAM_STR);
        $st->bindValue(':range_end', $rangeEnd, PDO::PARAM_STR);
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
            if ($targetType === '') $targetType = $costType;

            if (!isset($empty['target_ids'][$costType])) $empty['target_ids'][$costType] = array();
            if (!isset($empty['target_ids'][$costType][$targetType])) $empty['target_ids'][$costType][$targetType] = array();
            $empty['target_ids'][$costType][$targetType][$targetId] = true;

            if (!isset($empty['target_deltas'][$costType])) $empty['target_deltas'][$costType] = array();
            if (!isset($empty['target_deltas'][$costType][$targetType])) $empty['target_deltas'][$costType][$targetType] = array();
            if (!isset($empty['target_deltas'][$costType][$targetType][$targetId])) $empty['target_deltas'][$costType][$targetType][$targetId] = 0.0;
            $empty['target_deltas'][$costType][$targetType][$targetId] += $delta;
        }

        /* 노무 공수 승인 이벤트는 이벤트 ID가 아니라 override ID를 가리키므로 근로자 키로 다시 연결한다. */
        $laborOverrideIds = array();
        if (isset($empty['target_ids']['labor']['labor_gongsu_override']) && is_array($empty['target_ids']['labor']['labor_gongsu_override'])) {
            foreach (array_keys($empty['target_ids']['labor']['labor_gongsu_override']) as $overrideId) {
                $overrideId = (int)$overrideId;
                if ($overrideId > 0) $laborOverrideIds[$overrideId] = $overrideId;
            }
        }
        if (count($laborOverrideIds) > 0 && cpms_monthly_summary_snapshot_table_exists($pdo, 'cpms_labor_gongsu_overrides')) {
            $holders = array();
            $params = array();
            foreach (array_values($laborOverrideIds) as $index=>$overrideId) {
                $key = ':labor_override_' . $index;
                $holders[] = $key;
                $params[$key] = (int)$overrideId;
            }
            try {
                $stLabor = $pdo->prepare('SELECT id,worker_key,worker_name FROM cpms_labor_gongsu_overrides WHERE id IN (' . implode(',', $holders) . ')');
                foreach ($params as $key=>$value) $stLabor->bindValue($key, $value, PDO::PARAM_INT);
                $stLabor->execute();
                $laborRows = $stLabor->fetchAll(PDO::FETCH_ASSOC);
                if (is_array($laborRows)) {
                    foreach ($laborRows as $laborRow) {
                        $workerKey = isset($laborRow['worker_key']) ? trim((string)$laborRow['worker_key']) : '';
                        if ($workerKey === '' && isset($laborRow['worker_name'])) $workerKey = trim((string)$laborRow['worker_name']);
                        if ($workerKey !== '') {
                            $workerKey = preg_replace('/\s+/u', '', $workerKey);
                            $workerKey = function_exists('mb_strtolower') ? mb_strtolower($workerKey, 'UTF-8') : strtolower($workerKey);
                            $empty['worker_keys'][$workerKey] = true;
                        }
                    }
                }
            } catch (Exception $laborException) {
            }
        }
    } catch (Exception $e) {
        return array('deltas'=>array(), 'target_ids'=>array(), 'target_deltas'=>array(), 'worker_keys'=>array());
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
        'event_range_start'=>'',
        'event_range_end'=>'',
        'deltas'=>array('labor'=>0.0,'equipment'=>0.0,'material'=>0.0,'outsourcing'=>0.0,'monthly_total'=>0.0),
        'target_ids'=>array(),
        'target_deltas'=>array(),
        'worker_keys'=>array(),
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

        /*
         * 시간 비교를 제거하고 날짜 전체를 비교한다.
         * 예: 비교일 2026-08-05 / 기준일 2026-08-06이면
         *     2026-08-05 00:00:00 ~ 2026-08-06 23:59:59 전체를 조회한다.
         */
        $rangeStartDate = $previousDate !== '' ? $previousDate : date('Y-m-d', strtotime($snapshotDate . ' -1 day'));
        $rangeStart = $rangeStartDate . ' 00:00:00';
        $rangeEnd = $snapshotDate . ' 23:59:59';

        $result['event_range_start'] = $rangeStart;
        $result['event_range_end'] = $rangeEnd;
        $events = cpms_monthly_summary_event_change_context($pdo, $projectId, $rangeStart, $rangeEnd, $ym);
        $result['target_ids'] = isset($events['target_ids']) ? $events['target_ids'] : array();
        $result['target_deltas'] = isset($events['target_deltas']) ? $events['target_deltas'] : array();
        $result['worker_keys'] = isset($events['worker_keys']) ? $events['worker_keys'] : array();
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
    /* 저장 화면마다 target_type 이름이 조금 달라도 같은 비용구분 안의 원본 ID가 같으면 같은 행으로 본다. */
    foreach ($change['target_deltas'][$costType] as $targetType=>$targetMap) {
        if (is_array($targetMap) && isset($targetMap[$targetId])) return (float)$targetMap[$targetId];
    }
    return 0.0;
}}

if (!function_exists('cpms_monthly_summary_change_worker_key')) {
function cpms_monthly_summary_change_worker_key($value) {
    $value = preg_replace('/\s+/u', '', trim((string)$value));
    if ($value === '') return '';
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}}

if (!function_exists('cpms_monthly_summary_change_worker_marked')) {
function cpms_monthly_summary_change_worker_marked($change, $workerKey, $workerName) {
    if (!is_array($change) || !isset($change['worker_keys']) || !is_array($change['worker_keys'])) return false;
    $keys = array(cpms_monthly_summary_change_worker_key($workerKey), cpms_monthly_summary_change_worker_key($workerName));
    foreach ($keys as $key) {
        if ($key !== '' && isset($change['worker_keys'][$key])) return true;
    }
    return false;
}}

if (!function_exists('cpms_monthly_summary_detail_row_date')) {
function cpms_monthly_summary_detail_row_date($row) {
    if (!is_array($row)) return '';
    foreach (array('use_date','expense_date','work_date','date') as $key) {
        if (!isset($row[$key])) continue;
        $date = cpms_monthly_summary_snapshot_valid_date($row[$key]);
        if ($date !== '') return $date;
    }
    return '';
}}

if (!function_exists('cpms_monthly_summary_mark_snapshot_new_rows')) {
function cpms_monthly_summary_mark_snapshot_new_rows($rows, $change, $costType) {
    if (!is_array($rows)) return array();
    if (!is_array($change) || !isset($change['deltas'][$costType])) return $rows;

    $delta = (float)$change['deltas'][$costType];
    if ($delta <= 0.01) return $rows;

    $previousDate = cpms_monthly_summary_snapshot_valid_date(isset($change['previous_date']) ? $change['previous_date'] : '');
    $snapshotDate = cpms_monthly_summary_snapshot_valid_date(isset($change['snapshot_date']) ? $change['snapshot_date'] : '');
    if ($previousDate === '' || $snapshotDate === '') return $rows;

    $alreadyMarked = 0.0;
    foreach ($rows as $row) {
        if (!empty($row['is_changed'])) {
            $rowDelta = isset($row['change_amount']) ? (float)$row['change_amount'] : 0.0;
            if ($rowDelta > 0.01) $alreadyMarked += $rowDelta;
        }
    }
    $remaining = $delta - $alreadyMarked;
    if ($remaining <= 0.01) return $rows;

    $candidates = array();
    $candidateTotal = 0.0;
    foreach ($rows as $index=>$row) {
        if (!empty($row['is_changed'])) continue;
        $rowDate = cpms_monthly_summary_detail_row_date($row);
        if ($rowDate === '' || $rowDate < $previousDate || $rowDate > $snapshotDate) continue;
        $amount = isset($row['amount']) ? (float)$row['amount'] : 0.0;
        if ($amount <= 0.01) continue;
        $candidates[] = array('index'=>(int)$index, 'amount'=>$amount);
        $candidateTotal += $amount;
    }

    /* 한 행 금액이 스냅샷 증가액과 정확히 같으면 그 행만 강조한다. */
    foreach ($candidates as $candidate) {
        if (abs((float)$candidate['amount'] - $remaining) <= 0.01) {
            $index = (int)$candidate['index'];
            $rows[$index]['is_changed'] = true;
            $rows[$index]['change_amount'] = (float)$candidate['amount'];
            $rows[$index]['change_basis'] = 'snapshot_date_amount';
            return $rows;
        }
    }

    /* 같은 날짜 범위의 신규 행 합계가 증가액과 같으면 해당 행들을 모두 강조한다. */
    if (count($candidates) > 0 && abs($candidateTotal - $remaining) <= 0.01) {
        foreach ($candidates as $candidate) {
            $index = (int)$candidate['index'];
            $rows[$index]['is_changed'] = true;
            $rows[$index]['change_amount'] = (float)$candidate['amount'];
            $rows[$index]['change_basis'] = 'snapshot_date_total';
        }
    }

    return $rows;
}}

if (!function_exists('cpms_monthly_summary_apply_detail_change_context')) {
function cpms_monthly_summary_apply_detail_change_context($monthPayload, $change) {
    if (!is_array($monthPayload)) $monthPayload = array();
    if (!is_array($change)) $change = array();
    $monthPayload['change'] = $change;

    foreach (array('labor','labor_outsourcing') as $laborSection) {
        if (!isset($monthPayload[$laborSection]) || !is_array($monthPayload[$laborSection])) continue;
        foreach ($monthPayload[$laborSection] as $index=>$row) {
            $delta = 0.0;
            $isChanged = false;
            $sourceType = isset($row['source_type']) ? strtolower(trim((string)$row['source_type'])) : '';
            $sourceId = isset($row['source_id']) ? (string)$row['source_id'] : '';
            if ($sourceType === 'labor_force_adjustment' && $sourceId !== '') {
                $delta = cpms_monthly_summary_change_target_delta($change, 'labor', array('labor_force_adjustment','labor_force'), $sourceId);
                $isChanged = abs($delta) > 0.01;
            } else {
                $workerKey = isset($row['worker_key']) ? (string)$row['worker_key'] : '';
                $workerName = isset($row['name']) ? (string)$row['name'] : '';
                $isChanged = cpms_monthly_summary_change_worker_marked($change, $workerKey, $workerName);
            }
            $monthPayload[$laborSection][$index]['change_amount'] = $delta;
            $monthPayload[$laborSection][$index]['is_changed'] = $isChanged;
        }
    }

    if (isset($monthPayload['equipment']) && is_array($monthPayload['equipment'])) {
        foreach ($monthPayload['equipment'] as $index=>$row) {
            $delta = 0.0;
            $sourceIds = isset($row['source_ids']) && is_array($row['source_ids']) ? $row['source_ids'] : array();
            foreach ($sourceIds as $sourceId) {
                $delta += cpms_monthly_summary_change_target_delta($change, 'equipment', array('equipment','equipment_usage'), $sourceId);
            }
            $monthPayload['equipment'][$index]['change_amount'] = $delta;
            $monthPayload['equipment'][$index]['is_changed'] = abs($delta) > 0.01;
        }
    }

    if (isset($monthPayload['material']) && is_array($monthPayload['material'])) {
        foreach ($monthPayload['material'] as $index=>$row) {
            $sourceId = isset($row['source_id']) ? (string)$row['source_id'] : '';
            $delta = cpms_monthly_summary_change_target_delta($change, 'material', array('material','material_usage'), $sourceId);
            $monthPayload['material'][$index]['change_amount'] = $delta;
            $monthPayload['material'][$index]['is_changed'] = abs($delta) > 0.01;
        }
    }

    if (isset($monthPayload['manual_outsourcing']) && is_array($monthPayload['manual_outsourcing'])) {
        foreach ($monthPayload['manual_outsourcing'] as $index=>$row) {
            $sourceId = isset($row['source_id']) ? (string)$row['source_id'] : '';
            $delta = cpms_monthly_summary_change_target_delta($change, 'outsourcing', array('outsourcing','outsourcing_cost'), $sourceId);
            $monthPayload['manual_outsourcing'][$index]['change_amount'] = $delta;
            $monthPayload['manual_outsourcing'][$index]['is_changed'] = abs($delta) > 0.01;
        }
    }

    /*
     * 이벤트 ID 연결이 누락된 경우에도 스냅샷 증가액과 상세 행 금액을 날짜 단위로 비교한다.
     * 비교일~기준일 범위에서 새로 나타난 금액과 증가액이 일치하는 행만 연두색으로 표시한다.
     */
    if (isset($monthPayload['material']) && is_array($monthPayload['material'])) {
        $monthPayload['material'] = cpms_monthly_summary_mark_snapshot_new_rows($monthPayload['material'], $change, 'material');
    }
    if (isset($monthPayload['manual_outsourcing']) && is_array($monthPayload['manual_outsourcing'])) {
        $monthPayload['manual_outsourcing'] = cpms_monthly_summary_mark_snapshot_new_rows($monthPayload['manual_outsourcing'], $change, 'outsourcing');
    }
    if (isset($monthPayload['labor']) && is_array($monthPayload['labor'])) {
        $monthPayload['labor'] = cpms_monthly_summary_mark_snapshot_new_rows($monthPayload['labor'], $change, 'labor');
    }
    if (isset($monthPayload['labor_outsourcing']) && is_array($monthPayload['labor_outsourcing'])) {
        $monthPayload['labor_outsourcing'] = cpms_monthly_summary_mark_snapshot_new_rows($monthPayload['labor_outsourcing'], $change, 'outsourcing');
    }

    return $monthPayload;
}}
