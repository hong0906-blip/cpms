<?php
/**
 * Company Google Chat daily notification helpers.
 * PHP 5.6 compatible.
 */

if (!function_exists('cpms_company_chat_daily_source_id')) {
function cpms_company_chat_daily_source_id($baseDate) {
    return (int)str_replace('-', '', (string)$baseDate);
}}

if (!function_exists('cpms_company_chat_notification_success_exists')) {
function cpms_company_chat_notification_success_exists($pdo, $sourceType, $eventType, $sourceId, $employeeId) {
    if (!$pdo || !function_exists('cpms_google_chat_table_exists')) return false;
    if (!cpms_google_chat_table_exists($pdo, 'cpms_google_chat_notifications')) return false;
    try {
        $sql = "SELECT id FROM cpms_google_chat_notifications
                WHERE source_type=:source_type
                  AND event_type=:event_type
                  AND source_id=:source_id
                  AND send_status='SUCCESS'";
        $params = array(
            ':source_type' => (string)$sourceType,
            ':event_type' => (string)$eventType,
            ':source_id' => (int)$sourceId
        );
        if ($employeeId === null) {
            $sql .= ' AND receiver_employee_id IS NULL';
        } else {
            $sql .= ' AND receiver_employee_id=:employee_id';
            $params[':employee_id'] = (int)$employeeId;
        }
        $sql .= ' LIMIT 1';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return (bool)$st->fetchColumn();
    } catch (Exception $e) {
        error_log('[company_chat_daily] duplicate lookup failed: ' . $e->getMessage());
        return false;
    }
}}

if (!function_exists('cpms_company_chat_daily_time_reached')) {
function cpms_company_chat_daily_time_reached($timeText) {
    $now = new DateTime('now', new DateTimeZone('Asia/Seoul'));
    return strcmp($now->format('H:i:s'), (string)$timeText) >= 0;
}}

if (!function_exists('cpms_company_chat_leave_date_label')) {
function cpms_company_chat_leave_date_label($baseDate) {
    try {
        $dateValue = new DateTime((string)$baseDate . ' 00:00:00', new DateTimeZone('Asia/Seoul'));
    } catch (Exception $e) {
        $dateValue = new DateTime('now', new DateTimeZone('Asia/Seoul'));
    }
    $weekdays = array('일', '월', '화', '수', '목', '금', '토');
    $weekNo = (int)$dateValue->format('w');
    return $dateValue->format('Y/m/d') . '(' . $weekdays[$weekNo] . ')';
}}

if (!function_exists('cpms_company_chat_leave_person_line')) {
function cpms_company_chat_leave_person_line($person) {
    if (!is_array($person)) $person = array();
    $name = isset($person['name']) ? trim((string)$person['name']) : '';
    $position = isset($person['position']) ? trim((string)$person['position']) : '';
    $department = isset($person['department']) ? trim((string)$person['department']) : '';
    $leaveType = isset($person['type_label']) ? trim((string)$person['type_label']) : '';
    if ($name === '') $name = '-';
    if ($leaveType === '') $leaveType = '휴가';
    $employeeText = $name . $position;
    if ($department !== '') $employeeText .= '/' . $department;
    return $employeeText . ' - ' . $leaveType;
}}

if (!function_exists('cpms_company_chat_build_daily_leave_message')) {
function cpms_company_chat_build_daily_leave_message($baseDate, $people) {
    if (!is_array($people)) $people = array();
    $lines = array(cpms_company_chat_leave_date_label($baseDate) . ' 연차자 전달드립니다.', '');
    if (count($people) === 0) {
        $lines[count($lines)] = '금일 연차자는 없습니다.';
    } else {
        for ($i = 0; $i < count($people); $i++) {
            $lines[count($lines)] = cpms_company_chat_leave_person_line($people[$i]);
        }
    }
    return implode("\n", $lines);
}}

if (!function_exists('cpms_company_chat_process_daily_leave')) {
function cpms_company_chat_process_daily_leave($pdo, $force) {
    $result = array('type' => 'leave', 'checked' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'reason' => '');
    if (!$pdo) {
        $result['failed'] = 1;
        $result['reason'] = 'DB connection is unavailable.';
        return $result;
    }
    if (!cpms_company_chat_daily_time_reached('08:00:00')) {
        $result['skipped'] = 1;
        $result['reason'] = '08:00 이전에는 휴가자 알림을 발송하지 않습니다.';
        return $result;
    }
    $baseDate = (new DateTime('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d');
    $sourceId = cpms_company_chat_daily_source_id($baseDate);
    $sourceType = 'DAILY_LEAVE';
    $eventType = 'DAILY_LEAVE_COMPANY_SPACE';
    if (!$force && cpms_company_chat_notification_success_exists($pdo, $sourceType, $eventType, $sourceId, null)) {
        $result['skipped'] = 1;
        $result['reason'] = '이미 성공 발송된 날짜입니다.';
        return $result;
    }
    $people = function_exists('approval_current_leave_people') ? approval_current_leave_people($pdo, $baseDate) : array();
    if (!is_array($people)) $people = array();
    $result['checked'] = count($people);
    $message = cpms_company_chat_build_daily_leave_message($baseDate, $people);
    $ok = cpms_google_chat_send_to_company_space($pdo, $message, $eventType, $sourceId, $sourceType);
    if ($ok) {
        $result['sent'] = 1;
    } else {
        $result['failed'] = 1;
        $result['reason'] = function_exists('approval_google_chat_get_last_error') ? trim((string)approval_google_chat_get_last_error()) : '';
        if ($result['reason'] === '') $result['reason'] = 'Google Chat API send failed.';
    }
    return $result;
}}

if (!function_exists('cpms_company_chat_missing_checkout_leave_map')) {
function cpms_company_chat_missing_checkout_leave_map($pdo, $baseDate) {
    $map = array();
    $people = function_exists('approval_current_leave_people') ? approval_current_leave_people($pdo, $baseDate) : array();
    if (!is_array($people)) return $map;
    for ($i = 0; $i < count($people); $i++) {
        $employeeId = isset($people[$i]['employee_id']) ? (int)$people[$i]['employee_id'] : 0;
        $email = isset($people[$i]['email']) ? strtolower(trim((string)$people[$i]['email'])) : '';
        $name = isset($people[$i]['name']) ? trim((string)$people[$i]['name']) : '';
        if ($employeeId > 0) $map['id:' . $employeeId] = true;
        if ($email !== '') $map['email:' . $email] = true;
        if ($name !== '' && $name !== '-') $map['name:' . $name] = true;
    }
    return $map;
}}

if (!function_exists('cpms_company_chat_employee_is_on_leave')) {
function cpms_company_chat_employee_is_on_leave($employee, $leaveMap) {
    if (!is_array($employee) || !is_array($leaveMap)) return false;
    $employeeId = isset($employee['id']) ? (int)$employee['id'] : 0;
    $email = isset($employee['email']) ? strtolower(trim((string)$employee['email'])) : '';
    $name = isset($employee['name']) ? trim((string)$employee['name']) : '';
    if ($employeeId > 0 && isset($leaveMap['id:' . $employeeId])) return true;
    if ($email !== '' && isset($leaveMap['email:' . $email])) return true;
    if ($name !== '' && isset($leaveMap['name:' . $name])) return true;
    return false;
}}

if (!function_exists('cpms_company_chat_missing_checkout_candidates')) {
function cpms_company_chat_missing_checkout_candidates($pdo, $baseDate, $limit) {
    $rows = array();
    if (!$pdo || !function_exists('attendance_table_exists')) return $rows;
    if (!attendance_table_exists($pdo, 'cpms_attendance_records') || !attendance_table_exists($pdo, 'employees')) return $rows;
    if (!attendance_table_column_exists_for_settings($pdo, 'employees', 'google_chat_enabled')) return $rows;
    if (!attendance_table_column_exists_for_settings($pdo, 'employees', 'is_active')) return $rows;

    $chatTargets = array();
    if (attendance_table_column_exists_for_settings($pdo, 'employees', 'google_chat_dm_space_name')) {
        $chatTargets[count($chatTargets)] = "(e.google_chat_dm_space_name IS NOT NULL AND TRIM(e.google_chat_dm_space_name) <> '')";
    }
    $autoCreateEnabled = function_exists('approval_google_chat_setting') && approval_google_chat_setting($pdo, 'google_chat_dm_auto_create_enabled', '0') === '1';
    if ($autoCreateEnabled && attendance_table_column_exists_for_settings($pdo, 'employees', 'google_chat_user_name')) {
        $chatTargets[count($chatTargets)] = "(e.google_chat_user_name IS NOT NULL AND TRIM(e.google_chat_user_name) <> '')";
    }
    if (count($chatTargets) === 0) return $rows;

    $limit = (int)$limit;
    if ($limit <= 0) $limit = 500;
    if ($limit > 1000) $limit = 1000;
    $positionSelect = attendance_table_column_exists_for_settings($pdo, 'employees', 'position') ? 'e.position' : "'' AS position";
    $roleSelect = attendance_table_column_exists_for_settings($pdo, 'employees', 'role') ? 'e.role' : "'' AS role";
    try {
        $sql = "SELECT e.id, e.name, e.email, e.department, " . $positionSelect . ", " . $roleSelect . "
                FROM cpms_attendance_records a
                INNER JOIN employees e ON e.id=a.employee_id
                WHERE a.work_date=:work_date
                  AND a.check_in IS NOT NULL
                  AND TRIM(CAST(a.check_in AS CHAR)) <> ''
                  AND (a.check_out IS NULL OR TRIM(CAST(a.check_out AS CHAR)) = '')
                  AND e.google_chat_enabled=1
                  AND e.is_active=1
                  AND (" . implode(' OR ', $chatTargets) . ")
                ORDER BY e.id ASC
                LIMIT " . $limit;
        $st = $pdo->prepare($sql);
        $st->execute(array(':work_date' => (string)$baseDate));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) $rows = array();
        if (function_exists('attendance_filter_representative_rows')) {
            $rows = attendance_filter_representative_rows($rows);
        }
    } catch (Exception $e) {
        error_log('[company_chat_daily] missing checkout lookup failed: ' . $e->getMessage());
        $rows = array();
    }
    return $rows;
}}

if (!function_exists('cpms_company_chat_missing_checkout_message')) {
function cpms_company_chat_missing_checkout_message() {
    return "[CPMS 근태 알림]\n\n금일 미퇴근중입니다. 확인해주세요.\n\n누락이시라면 요청 보내기에서 요청 바랍니다.";
}}

if (!function_exists('cpms_company_chat_process_missing_checkout')) {
function cpms_company_chat_process_missing_checkout($pdo, $force, $limit) {
    $result = array('type' => 'missing_checkout', 'checked' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'reason' => '');
    if (!$pdo) {
        $result['failed'] = 1;
        $result['reason'] = 'DB connection is unavailable.';
        return $result;
    }
    if (!cpms_company_chat_daily_time_reached('19:00:00')) {
        $result['skipped'] = 1;
        $result['reason'] = '19:00 이전에는 미퇴근 알림을 발송하지 않습니다.';
        return $result;
    }
    $baseDate = (new DateTime('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d');
    $sourceId = cpms_company_chat_daily_source_id($baseDate);
    $sourceType = 'ATTENDANCE_MISSING_CHECKOUT';
    $eventType = 'MISSING_CHECKOUT_REMINDER';
    $leaveMap = cpms_company_chat_missing_checkout_leave_map($pdo, $baseDate);
    $rows = cpms_company_chat_missing_checkout_candidates($pdo, $baseDate, $limit);
    $message = cpms_company_chat_missing_checkout_message();
    for ($i = 0; $i < count($rows); $i++) {
        $employeeId = isset($rows[$i]['id']) ? (int)$rows[$i]['id'] : 0;
        if ($employeeId <= 0) continue;
        $result['checked']++;
        if (cpms_company_chat_employee_is_on_leave($rows[$i], $leaveMap)) {
            $result['skipped']++;
            continue;
        }
        if (!$force && cpms_company_chat_notification_success_exists($pdo, $sourceType, $eventType, $sourceId, $employeeId)) {
            $result['skipped']++;
            continue;
        }
        $ok = cpms_send_google_chat_to_employee($pdo, $employeeId, $message, $sourceId, $eventType, $sourceType);
        if ($ok) $result['sent']++;
        else $result['failed']++;
    }
    return $result;
}}
?>
