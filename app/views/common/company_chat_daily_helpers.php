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

if (!function_exists('cpms_company_chat_daily_lock_acquire')) {
function cpms_company_chat_daily_lock_acquire($notificationType, $sourceId) {
    $safeType = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$notificationType);
    if ($safeType === '') $safeType = 'notification';
    $lockPath = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'cpms_company_chat_' . $safeType . '_' . (int)$sourceId . '.lock';
    $lockHandle = @fopen($lockPath, 'c');
    if (!$lockHandle) return false;
    if (!@flock($lockHandle, LOCK_EX | LOCK_NB)) {
        fclose($lockHandle);
        return false;
    }
    return $lockHandle;
}}

if (!function_exists('cpms_company_chat_daily_lock_release')) {
function cpms_company_chat_daily_lock_release($lockHandle) {
    if (!is_resource($lockHandle)) return;
    @flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
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

if (!function_exists('cpms_company_chat_daily_leave_member_event')) {
function cpms_company_chat_daily_leave_member_event($baseDate) {
    return 'INCLUDED_' . str_replace('-', '', (string)$baseDate);
}}

if (!function_exists('cpms_company_chat_daily_leave_pending_event')) {
function cpms_company_chat_daily_leave_pending_event($baseDate) {
    return 'PENDING_' . str_replace('-', '', (string)$baseDate);
}}

if (!function_exists('cpms_company_chat_daily_leave_snapshot_exists')) {
function cpms_company_chat_daily_leave_snapshot_exists($pdo, $baseDate) {
    if (!$pdo || !cpms_google_chat_table_exists($pdo, 'cpms_google_chat_notifications')) return false;
    try {
        $st = $pdo->prepare("SELECT id FROM cpms_google_chat_notifications WHERE source_type='DAILY_LEAVE_SNAPSHOT' AND event_type=:event_type AND source_id=:source_id AND send_status='SUCCESS' LIMIT 1");
        $st->execute(array(':event_type' => 'SNAPSHOT_' . str_replace('-', '', (string)$baseDate), ':source_id' => cpms_company_chat_daily_source_id($baseDate)));
        return (bool)$st->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_company_chat_daily_leave_sent_message')) {
function cpms_company_chat_daily_leave_sent_message($pdo, $baseDate) {
    if (!$pdo || !cpms_google_chat_table_exists($pdo, 'cpms_google_chat_notifications')) return '';
    try {
        $st = $pdo->prepare("SELECT message_text FROM cpms_google_chat_notifications WHERE source_type='DAILY_LEAVE' AND event_type='DAILY_LEAVE_COMPANY_SPACE' AND source_id=:source_id AND send_status='SUCCESS' ORDER BY id ASC LIMIT 1");
        $st->execute(array(':source_id' => cpms_company_chat_daily_source_id($baseDate)));
        $messageText = $st->fetchColumn();
        return $messageText !== false ? (string)$messageText : '';
    } catch (Exception $e) {
        error_log('[company_chat_daily] sent leave message lookup failed: ' . $e->getMessage());
        return '';
    }
}}

if (!function_exists('cpms_company_chat_daily_leave_people_in_sent_message')) {
function cpms_company_chat_daily_leave_people_in_sent_message($messageText, $people) {
    if (!is_array($people)) $people = array();
    $messageText = str_replace(array("\r\n", "\r"), "\n", (string)$messageText);
    if (trim($messageText) === '') return $people;
    $messageText = "\n" . trim($messageText) . "\n";
    $included = array();
    for ($i = 0; $i < count($people); $i++) {
        $personLine = trim(cpms_company_chat_leave_person_line($people[$i]));
        if ($personLine !== '' && strpos($messageText, "\n" . $personLine . "\n") !== false) {
            $included[count($included)] = $people[$i];
        }
    }
    return $included;
}}

if (!function_exists('cpms_company_chat_daily_leave_member_exists')) {
function cpms_company_chat_daily_leave_member_exists($pdo, $baseDate, $documentId) {
    if (!$pdo || (int)$documentId <= 0 || !cpms_google_chat_table_exists($pdo, 'cpms_google_chat_notifications')) return false;
    try {
        $st = $pdo->prepare("SELECT id FROM cpms_google_chat_notifications WHERE source_type='DAILY_LEAVE_MEMBER' AND event_type=:event_type AND source_id=:source_id AND send_status='SUCCESS' LIMIT 1");
        $st->execute(array(':event_type' => cpms_company_chat_daily_leave_member_event($baseDate), ':source_id' => (int)$documentId));
        return (bool)$st->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_company_chat_daily_leave_pending_exists')) {
function cpms_company_chat_daily_leave_pending_exists($pdo, $baseDate, $documentId) {
    if (!$pdo || (int)$documentId <= 0 || !cpms_google_chat_table_exists($pdo, 'cpms_google_chat_notifications')) return false;
    try {
        $st = $pdo->prepare("SELECT id FROM cpms_google_chat_notifications WHERE source_type='DAILY_LEAVE_ADDITION' AND event_type=:event_type AND source_id=:source_id AND send_status='RESERVED' LIMIT 1");
        $st->execute(array(':event_type' => cpms_company_chat_daily_leave_pending_event($baseDate), ':source_id' => (int)$documentId));
        return (bool)$st->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_company_chat_daily_leave_mark_member')) {
function cpms_company_chat_daily_leave_mark_member($pdo, $baseDate, $person) {
    if (!$pdo || !is_array($person)) return false;
    $documentId = isset($person['document_id']) ? (int)$person['document_id'] : 0;
    if ($documentId <= 0 || cpms_company_chat_daily_leave_member_exists($pdo, $baseDate, $documentId)) return false;
    cpms_google_chat_log_notification($pdo, array(
        'source_type' => 'DAILY_LEAVE_MEMBER',
        'source_id' => $documentId,
        'event_type' => cpms_company_chat_daily_leave_member_event($baseDate),
        'receiver_employee_id' => isset($person['employee_id']) && (int)$person['employee_id'] > 0 ? (int)$person['employee_id'] : null,
        'receiver_name' => isset($person['name']) ? (string)$person['name'] : '',
        'receiver_email' => isset($person['email']) ? (string)$person['email'] : '',
        'dm_space_name' => cpms_google_chat_company_space_name($pdo),
        'message_text' => cpms_company_chat_leave_person_line($person),
        'send_status' => 'SUCCESS',
        'error_message' => null,
        'sent_at' => date('Y-m-d H:i:s')
    ));
    return true;
}}

if (!function_exists('cpms_company_chat_daily_leave_mark_snapshot')) {
function cpms_company_chat_daily_leave_mark_snapshot($pdo, $baseDate, $people) {
    if (!$pdo) return false;
    if (!is_array($people)) $people = array();
    for ($i = 0; $i < count($people); $i++) {
        cpms_company_chat_daily_leave_mark_member($pdo, $baseDate, $people[$i]);
    }
    if (!cpms_company_chat_daily_leave_snapshot_exists($pdo, $baseDate)) {
        cpms_google_chat_log_notification($pdo, array(
            'source_type' => 'DAILY_LEAVE_SNAPSHOT',
            'source_id' => cpms_company_chat_daily_source_id($baseDate),
            'event_type' => 'SNAPSHOT_' . str_replace('-', '', (string)$baseDate),
            'receiver_employee_id' => null,
            'receiver_name' => '회사 전체방',
            'receiver_email' => null,
            'dm_space_name' => cpms_google_chat_company_space_name($pdo),
            'message_text' => '08시 휴가자 기준 명단 ' . count($people) . '명',
            'send_status' => 'SUCCESS',
            'error_message' => null,
            'sent_at' => date('Y-m-d H:i:s')
        ));
    }
    return true;
}}

if (!function_exists('cpms_company_chat_daily_leave_person_by_document')) {
function cpms_company_chat_daily_leave_person_by_document($pdo, $documentId, $baseDate) {
    if (!$pdo || (int)$documentId <= 0) return null;
    try {
        $st = $pdo->prepare("SELECT d.id, d.created_by_id, d.created_by_name, d.created_by_email, d.content, e.name AS employee_name, e.email AS employee_email, e.department AS employee_department, e.position AS employee_position FROM cpms_approval_documents d LEFT JOIN employees e ON e.id=d.created_by_id WHERE d.id=:id AND d.doc_type='leave' AND UPPER(COALESCE(d.doc_status,'')) IN ('APPROVED','COMPLETED') LIMIT 1");
        $st->execute(array(':id' => (int)$documentId));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        $content = function_exists('approval_parse_content') ? approval_parse_content(isset($row['content']) ? $row['content'] : '') : json_decode(isset($row['content']) ? (string)$row['content'] : '', true);
        if (!is_array($content)) $content = array();
        $start = isset($content['leave_start_date']) ? substr(trim((string)$content['leave_start_date']), 0, 10) : '';
        $end = isset($content['leave_end_date']) ? substr(trim((string)$content['leave_end_date']), 0, 10) : '';
        if ($start === '' || $end === '' || (string)$baseDate < $start || (string)$baseDate > $end) return null;
        $name = isset($row['employee_name']) ? trim((string)$row['employee_name']) : '';
        if ($name === '') $name = isset($row['created_by_name']) ? trim((string)$row['created_by_name']) : '';
        if ($name === '' && isset($content['applicant_name'])) $name = trim((string)$content['applicant_name']);
        $email = isset($row['employee_email']) ? trim((string)$row['employee_email']) : '';
        if ($email === '') $email = isset($row['created_by_email']) ? trim((string)$row['created_by_email']) : '';
        $department = isset($row['employee_department']) ? trim((string)$row['employee_department']) : '';
        if ($department === '' && isset($content['department'])) $department = trim((string)$content['department']);
        $position = isset($row['employee_position']) ? trim((string)$row['employee_position']) : '';
        if ($position === '' && isset($content['position'])) $position = trim((string)$content['position']);
        $typeLabel = function_exists('approval_leave_type_label_from_content') ? approval_leave_type_label_from_content($content) : (isset($content['request_type']) ? trim((string)$content['request_type']) : '');
        return array('document_id' => (int)$row['id'], 'employee_id' => isset($row['created_by_id']) ? (int)$row['created_by_id'] : 0, 'name' => $name !== '' ? $name : '-', 'email' => $email, 'department' => $department, 'position' => $position, 'type_label' => $typeLabel !== '' ? $typeLabel : '휴가');
    } catch (Exception $e) {
        error_log('[company_chat_daily] leave document lookup failed: ' . $e->getMessage());
        return null;
    }
}}

if (!function_exists('cpms_company_chat_queue_daily_leave_addition')) {
function cpms_company_chat_queue_daily_leave_addition($pdo, $documentId) {
    if (!$pdo || (int)$documentId <= 0 || !cpms_company_chat_daily_time_reached('08:00:00')) return false;
    $baseDate = (new DateTime('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d');
    $sourceId = cpms_company_chat_daily_source_id($baseDate);
    if (!cpms_company_chat_notification_success_exists($pdo, 'DAILY_LEAVE', 'DAILY_LEAVE_COMPANY_SPACE', $sourceId, null)) return false;
    $person = cpms_company_chat_daily_leave_person_by_document($pdo, $documentId, $baseDate);
    if (!is_array($person)) return false;
    $lockHandle = cpms_company_chat_daily_lock_acquire('daily_leave_addition', $sourceId);
    if (!$lockHandle) return false;
    if (cpms_company_chat_daily_leave_member_exists($pdo, $baseDate, $documentId) || cpms_company_chat_daily_leave_pending_exists($pdo, $baseDate, $documentId)) {
        cpms_company_chat_daily_lock_release($lockHandle);
        return false;
    }
    cpms_google_chat_log_notification($pdo, array(
        'source_type' => 'DAILY_LEAVE_ADDITION',
        'source_id' => (int)$documentId,
        'event_type' => cpms_company_chat_daily_leave_pending_event($baseDate),
        'receiver_employee_id' => isset($person['employee_id']) && (int)$person['employee_id'] > 0 ? (int)$person['employee_id'] : null,
        'receiver_name' => isset($person['name']) ? (string)$person['name'] : '',
        'receiver_email' => isset($person['email']) ? (string)$person['email'] : '',
        'dm_space_name' => cpms_google_chat_company_space_name($pdo),
        'message_text' => cpms_company_chat_leave_person_line($person),
        'send_status' => 'RESERVED',
        'error_message' => '추가 휴가자 10분 대기 중',
        'sent_at' => null
    ));
    cpms_company_chat_daily_lock_release($lockHandle);
    return true;
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
    $lockHandle = cpms_company_chat_daily_lock_acquire('daily_leave', $sourceId);
    if (!$lockHandle) {
        $result['skipped'] = 1;
        $result['reason'] = '다른 휴가자 전체방 알림 작업이 실행 중입니다.';
        return $result;
    }
    if (!$force && cpms_company_chat_notification_success_exists($pdo, $sourceType, $eventType, $sourceId, null)) {
        if (!cpms_company_chat_daily_leave_snapshot_exists($pdo, $baseDate)) {
            $legacyPeople = function_exists('approval_current_leave_people') ? approval_current_leave_people($pdo, $baseDate) : array();
            if (!is_array($legacyPeople)) $legacyPeople = array();
            $legacyMessage = cpms_company_chat_daily_leave_sent_message($pdo, $baseDate);
            $legacyIncludedPeople = cpms_company_chat_daily_leave_people_in_sent_message($legacyMessage, $legacyPeople);
            cpms_company_chat_daily_leave_mark_snapshot($pdo, $baseDate, $legacyIncludedPeople);
        }
        cpms_company_chat_daily_lock_release($lockHandle);
        $result['skipped'] = 1;
        $result['reason'] = '이미 성공 발송된 날짜입니다.';
        return $result;
    }
    $people = function_exists('approval_current_leave_people') ? approval_current_leave_people($pdo, $baseDate) : array();
    if (!is_array($people)) $people = array();
    $result['checked'] = count($people);
    $message = cpms_company_chat_build_daily_leave_message($baseDate, $people);
    $ok = cpms_google_chat_send_to_company_space($pdo, $message, $eventType, $sourceId, $sourceType);
    cpms_company_chat_daily_lock_release($lockHandle);
    if ($ok) {
        cpms_company_chat_daily_leave_mark_snapshot($pdo, $baseDate, $people);
        $result['sent'] = 1;
    } else {
        $result['failed'] = 1;
        $result['reason'] = function_exists('approval_google_chat_get_last_error') ? trim((string)approval_google_chat_get_last_error()) : '';
        if ($result['reason'] === '') $result['reason'] = 'Google Chat API send failed.';
    }
    return $result;
}}

if (!function_exists('cpms_company_chat_daily_leave_pending_rows')) {
function cpms_company_chat_daily_leave_pending_rows($pdo, $baseDate) {
    if (!$pdo || !cpms_google_chat_table_exists($pdo, 'cpms_google_chat_notifications')) return array();
    try {
        $st = $pdo->prepare("SELECT id, source_id, receiver_employee_id, receiver_name, receiver_email, message_text, created_at FROM cpms_google_chat_notifications WHERE source_type='DAILY_LEAVE_ADDITION' AND event_type=:event_type AND send_status='RESERVED' ORDER BY created_at ASC, id ASC");
        $st->execute(array(':event_type' => cpms_company_chat_daily_leave_pending_event($baseDate)));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    } catch (Exception $e) {
        error_log('[company_chat_daily] pending leave additions lookup failed: ' . $e->getMessage());
        return array();
    }
}}

if (!function_exists('cpms_company_chat_daily_leave_mark_pending')) {
function cpms_company_chat_daily_leave_mark_pending($pdo, $historyId, $status, $errorMessage) {
    if (!$pdo || (int)$historyId <= 0) return false;
    try {
        $st = $pdo->prepare("UPDATE cpms_google_chat_notifications SET send_status=:send_status, error_message=:error_message, sent_at=:sent_at WHERE id=:id AND send_status='RESERVED'");
        $st->execute(array(':send_status' => (string)$status, ':error_message' => $errorMessage, ':sent_at' => (string)$status === 'SUCCESS' ? date('Y-m-d H:i:s') : null, ':id' => (int)$historyId));
        return true;
    } catch (Exception $e) {
        error_log('[company_chat_daily] pending leave addition update failed: ' . $e->getMessage());
        return false;
    }
}}

if (!function_exists('cpms_company_chat_build_daily_leave_addition_message')) {
function cpms_company_chat_build_daily_leave_addition_message($baseDate, $people) {
    if (!is_array($people)) $people = array();
    $lines = array(cpms_company_chat_leave_date_label($baseDate) . ' 금일 휴가자 추가 전달드립니다.', '');
    for ($i = 0; $i < count($people); $i++) {
        $lines[count($lines)] = cpms_company_chat_leave_person_line($people[$i]);
    }
    return implode("\n", $lines);
}}

if (!function_exists('cpms_company_chat_process_daily_leave_additions')) {
function cpms_company_chat_process_daily_leave_additions($pdo) {
    $result = array('type' => 'leave_addition', 'checked' => 0, 'queued' => 0, 'pending' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'reason' => '');
    if (!$pdo || !cpms_company_chat_daily_time_reached('08:00:00')) {
        $result['skipped'] = 1;
        $result['reason'] = '08:00 이전에는 추가 휴가자 알림을 처리하지 않습니다.';
        return $result;
    }
    $baseDate = (new DateTime('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d');
    $sourceId = cpms_company_chat_daily_source_id($baseDate);
    if (!cpms_company_chat_notification_success_exists($pdo, 'DAILY_LEAVE', 'DAILY_LEAVE_COMPANY_SPACE', $sourceId, null)) {
        $result['skipped'] = 1;
        $result['reason'] = '08시 전체 휴가자 알림 성공 이력이 없습니다.';
        return $result;
    }
    if (!cpms_company_chat_daily_leave_snapshot_exists($pdo, $baseDate)) {
        $result['skipped'] = 1;
        $result['reason'] = '08시 휴가자 기준 명단을 초기화하는 중입니다.';
        return $result;
    }

    $people = function_exists('approval_current_leave_people') ? approval_current_leave_people($pdo, $baseDate) : array();
    if (!is_array($people)) $people = array();
    $result['checked'] = count($people);
    for ($i = 0; $i < count($people); $i++) {
        $documentId = isset($people[$i]['document_id']) ? (int)$people[$i]['document_id'] : 0;
        if ($documentId > 0 && !cpms_company_chat_daily_leave_member_exists($pdo, $baseDate, $documentId) && !cpms_company_chat_daily_leave_pending_exists($pdo, $baseDate, $documentId)) {
            if (cpms_company_chat_queue_daily_leave_addition($pdo, $documentId)) $result['queued']++;
        }
    }

    $lockHandle = cpms_company_chat_daily_lock_acquire('daily_leave_addition', $sourceId);
    if (!$lockHandle) {
        $result['skipped']++;
        $result['reason'] = '다른 추가 휴가자 알림 작업이 실행 중입니다.';
        return $result;
    }
    $pendingRows = cpms_company_chat_daily_leave_pending_rows($pdo, $baseDate);
    $result['pending'] = count($pendingRows);
    if (count($pendingRows) === 0) {
        cpms_company_chat_daily_lock_release($lockHandle);
        return $result;
    }
    $readyRows = array();
    $nowTimestamp = time();
    for ($i = 0; $i < count($pendingRows); $i++) {
        $createdAt = isset($pendingRows[$i]['created_at']) ? trim((string)$pendingRows[$i]['created_at']) : '';
        $readyAt = $createdAt !== '' ? strtotime($createdAt . ' +10 minutes') : false;
        if ($readyAt !== false && $nowTimestamp >= $readyAt) {
            $readyRows[count($readyRows)] = $pendingRows[$i];
        }
    }
    if (count($readyRows) === 0) {
        cpms_company_chat_daily_lock_release($lockHandle);
        $result['skipped']++;
        $result['reason'] = '추가 휴가자 확인 후 10분 대기 중입니다.';
        return $result;
    }

    $sendPeople = array();
    $sendHistoryRows = array();
    for ($i = 0; $i < count($readyRows); $i++) {
        $documentId = isset($readyRows[$i]['source_id']) ? (int)$readyRows[$i]['source_id'] : 0;
        if ($documentId <= 0 || cpms_company_chat_daily_leave_member_exists($pdo, $baseDate, $documentId)) {
            cpms_company_chat_daily_leave_mark_pending($pdo, isset($readyRows[$i]['id']) ? (int)$readyRows[$i]['id'] : 0, 'SKIPPED', '이미 공지된 휴가자');
            continue;
        }
        $person = cpms_company_chat_daily_leave_person_by_document($pdo, $documentId, $baseDate);
        if (!is_array($person)) {
            cpms_company_chat_daily_leave_mark_pending($pdo, isset($readyRows[$i]['id']) ? (int)$readyRows[$i]['id'] : 0, 'SKIPPED', '휴가 취소 또는 당일 대상 아님');
            continue;
        }
        $sendPeople[count($sendPeople)] = $person;
        $sendHistoryRows[count($sendHistoryRows)] = $readyRows[$i];
    }
    if (count($sendPeople) === 0) {
        cpms_company_chat_daily_lock_release($lockHandle);
        return $result;
    }

    $message = cpms_company_chat_build_daily_leave_addition_message($baseDate, $sendPeople);
    $ok = cpms_google_chat_send_to_company_space($pdo, $message, 'DAILY_LEAVE_ADDITION_COMPANY_SPACE', $sourceId, 'DAILY_LEAVE_ADDITION');
    if ($ok) {
        for ($i = 0; $i < count($sendPeople); $i++) {
            cpms_company_chat_daily_leave_mark_pending($pdo, isset($sendHistoryRows[$i]['id']) ? (int)$sendHistoryRows[$i]['id'] : 0, 'SUCCESS', null);
            cpms_company_chat_daily_leave_mark_member($pdo, $baseDate, $sendPeople[$i]);
        }
        $result['sent'] = count($sendPeople);
    } else {
        $result['failed'] = count($sendPeople);
        $result['reason'] = function_exists('approval_google_chat_get_last_error') ? trim((string)approval_google_chat_get_last_error()) : 'Google Chat API send failed.';
    }
    cpms_company_chat_daily_lock_release($lockHandle);
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
