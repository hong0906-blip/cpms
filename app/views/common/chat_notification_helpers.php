<?php
if (!function_exists('cpms_google_chat_table_exists')) {
function cpms_google_chat_table_exists($pdo, $tableName) {
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE :tbl");
        $st->execute(array(':tbl' => $tableName));
        return (bool)$st->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}
}
if (!function_exists('cpms_google_chat_column_exists')) {
function cpms_google_chat_column_exists($pdo, $tableName, $columnName) {
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `".$tableName."` LIKE :col");
        $st->execute(array(':col' => $columnName));
        return (bool)$st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return false;
    }
}
}
if (!function_exists('cpms_chat_priority_prefix')) {
function cpms_chat_priority_prefix($type, $level) {
    $type = strtolower(trim((string)$type));
    $level = trim((string)$level);
    if ($level === '') { $level = '보통'; }
    if ($type === 'issue') {
        $map = array('낮음'=>'[⚪ 이슈발생]','보통'=>'[🟢 이슈발생]','높음'=>'[🟡 이슈발생]','긴급'=>'[🔴🔥 이슈발생]');
        return isset($map[$level]) ? $map[$level] : '[🟢 이슈발생]';
    }
    $map = array('경미'=>'[⚪ 안전사고 발생]','낮음'=>'[⚪ 안전사고 발생]','보통'=>'[🟢 안전사고 발생]','중대'=>'[🟡 안전사고 발생]','높음'=>'[🟡 안전사고 발생]','긴급'=>'[🔴🔥 안전사고 발생]');
    return isset($map[$level]) ? $map[$level] : '[🟢 안전사고 발생]';
}
}
if (!function_exists('cpms_google_chat_project_name')) {
function cpms_google_chat_project_name($pdo, $projectId) {
    $projectId = (int)$projectId;
    if ($projectId <= 0) return '현장 #0';
    $tableCandidates = array('cpms_projects', 'projects');
    $nameColumns = array('name', 'project_name', 'site_name', 'title');
    foreach ($tableCandidates as $tableName) {
        if (!cpms_google_chat_table_exists($pdo, $tableName)) continue;
        foreach ($nameColumns as $col) {
            if (!cpms_google_chat_column_exists($pdo, $tableName, $col)) continue;
            try {
                $st = $pdo->prepare("SELECT `".$col."` FROM `".$tableName."` WHERE id=:id LIMIT 1");
                $st->execute(array(':id' => $projectId));
                $name = $st->fetchColumn();
                if ($name !== false) { $name = trim((string)$name); if ($name !== '') return $name; }
            } catch (Exception $e) {}
        }
    }
    return '현장 #'.$projectId;
}
}
if (!function_exists('cpms_google_chat_log_notification')) {
function cpms_google_chat_log_notification($pdo, $data) {
    try {
        if (!cpms_google_chat_table_exists($pdo, 'cpms_google_chat_notifications')) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_google_chat_notifications (id INT AUTO_INCREMENT PRIMARY KEY, source_type VARCHAR(50) NOT NULL, source_id INT NULL, event_type VARCHAR(50) NULL, receiver_employee_id INT NULL, receiver_name VARCHAR(100) NULL, receiver_email VARCHAR(190) NULL, dm_space_name VARCHAR(255) NULL, message_text TEXT NULL, send_status VARCHAR(20) NULL, error_message TEXT NULL, sent_at DATETIME NULL, created_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8");
        }
        $st = $pdo->prepare("INSERT INTO cpms_google_chat_notifications
            (source_type, source_id, event_type, receiver_employee_id, receiver_name, receiver_email, dm_space_name, message_text, send_status, error_message, sent_at, created_at)
            VALUES
            (:source_type, :source_id, :event_type, :receiver_employee_id, :receiver_name, :receiver_email, :dm_space_name, :message_text, :send_status, :error_message, :sent_at, :created_at)");
        $st->execute(array(
            ':source_type' => isset($data['source_type']) ? (string)$data['source_type'] : '',
            ':source_id' => isset($data['source_id']) ? (int)$data['source_id'] : null,
            ':event_type' => isset($data['event_type']) ? (string)$data['event_type'] : null,
            ':receiver_employee_id' => isset($data['receiver_employee_id']) ? (int)$data['receiver_employee_id'] : null,
            ':receiver_name' => isset($data['receiver_name']) ? (string)$data['receiver_name'] : null,
            ':receiver_email' => isset($data['receiver_email']) ? (string)$data['receiver_email'] : null,
            ':dm_space_name' => isset($data['dm_space_name']) ? (string)$data['dm_space_name'] : null,
            ':message_text' => isset($data['message_text']) ? (string)$data['message_text'] : null,
            ':send_status' => isset($data['send_status']) ? (string)$data['send_status'] : null,
            ':error_message' => isset($data['error_message']) ? (string)$data['error_message'] : null,
            ':sent_at' => isset($data['sent_at']) ? (string)$data['sent_at'] : null,
            ':created_at' => date('Y-m-d H:i:s')
        ));
    } catch (Exception $e) { error_log('[google_chat_exec_notify] history log fail: '.$e->getMessage()); }
}
}
if (!function_exists('cpms_google_chat_company_space_name')) {
function cpms_google_chat_company_space_name($pdo) {
    if (!$pdo) return '';
    if (!function_exists('approval_google_chat_setting')) {
        require_once __DIR__ . '/../approval/google_chat_helpers.php';
    }
    if (!function_exists('approval_google_chat_setting')) return '';
    return trim((string)approval_google_chat_setting($pdo, 'google_chat_company_space_name', ''));
}
}
if (!function_exists('cpms_google_chat_company_leave_block_reason')) {
function cpms_google_chat_company_leave_block_reason($pdo, $sourceType, $sourceId) {
    $sourceType = trim((string)$sourceType);
    if ($sourceType !== 'DAILY_LEAVE' && $sourceType !== 'DAILY_LEAVE_ADDITION') return '';

    $dateDigits = trim((string)$sourceId);
    if (!preg_match('/^(\d{4})(\d{2})(\d{2})$/', $dateDigits, $matches)) {
        return '연차자 알림 기준일이 올바르지 않아 발송하지 않습니다.';
    }
    if (!checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1])) {
        return '연차자 알림 기준일이 올바르지 않아 발송하지 않습니다.';
    }
    $baseDate = $matches[1] . '-' . $matches[2] . '-' . $matches[3];
    try {
        $dateValue = new DateTime($baseDate . ' 00:00:00', new DateTimeZone('Asia/Seoul'));
    } catch (Exception $e) {
        return '연차자 알림 기준일을 확인할 수 없어 발송하지 않습니다.';
    }
    if ((int)$dateValue->format('N') >= 6) {
        return '주말에는 연차자 알림을 발송하지 않습니다.';
    }

    if (!function_exists('attendance_is_holiday')) {
        require_once __DIR__ . '/../attendance/common.php';
    }
    if (function_exists('attendance_is_holiday') && attendance_is_holiday($pdo, $baseDate)) {
        return '공휴일 또는 대체공휴일에는 연차자 알림을 발송하지 않습니다.';
    }
    return '';
}
}
if (!function_exists('cpms_google_chat_send_to_company_space')) {
function cpms_google_chat_send_to_company_space($pdo, $messageText, $eventType, $sourceId, $sourceType) {
    if (!function_exists('approval_google_chat_send_message')) {
        require_once __DIR__ . '/../approval/google_chat_helpers.php';
    }
    $spaceName = cpms_google_chat_company_space_name($pdo);
    $messageText = (string)$messageText;
    $logData = array(
        'source_type' => (string)$sourceType,
        'source_id' => $sourceId,
        'event_type' => (string)$eventType,
        'receiver_employee_id' => null,
        'receiver_name' => '회사 전체방',
        'receiver_email' => null,
        'dm_space_name' => $spaceName,
        'message_text' => $messageText,
        'send_status' => 'SKIPPED',
        'error_message' => null,
        'sent_at' => null
    );

    $leaveBlockReason = cpms_google_chat_company_leave_block_reason($pdo, $sourceType, $sourceId);
    if ($leaveBlockReason !== '') {
        if (function_exists('approval_google_chat_set_last_error')) approval_google_chat_set_last_error($leaveBlockReason);
        $logData['error_message'] = $leaveBlockReason;
        if ($pdo) cpms_google_chat_log_notification($pdo, $logData);
        error_log('[google_chat_company] skipped non-working day source=' . (string)$sourceType . ' source_id=' . (int)$sourceId . ' reason=' . $leaveBlockReason);
        return false;
    }

    if (!$pdo || $spaceName === '') {
        $errorMessage = 'google_chat_company_space_name 설정값이 비어 있습니다.';
        if (function_exists('approval_google_chat_set_last_error')) approval_google_chat_set_last_error($errorMessage);
        $logData['error_message'] = $errorMessage;
        if ($pdo) cpms_google_chat_log_notification($pdo, $logData);
        error_log('[google_chat_company] skipped: ' . $errorMessage);
        return false;
    }
    $chatEnabled = function_exists('approval_google_chat_setting') ? approval_google_chat_setting($pdo, 'google_chat_enabled', '0') : '0';
    if ((string)$chatEnabled !== '1') {
        $errorMessage = 'google_chat_enabled 설정이 비활성화되어 있습니다.';
        if (function_exists('approval_google_chat_set_last_error')) approval_google_chat_set_last_error($errorMessage);
        $logData['error_message'] = $errorMessage;
        cpms_google_chat_log_notification($pdo, $logData);
        error_log('[google_chat_company] skipped: ' . $errorMessage);
        return false;
    }
    if (strpos($spaceName, 'spaces/') !== 0) {
        $errorMessage = '회사 전체방 Space Name은 spaces/로 시작해야 합니다.';
        if (function_exists('approval_google_chat_set_last_error')) approval_google_chat_set_last_error($errorMessage);
        $logData['send_status'] = 'FAILED';
        $logData['error_message'] = $errorMessage;
        cpms_google_chat_log_notification($pdo, $logData);
        error_log('[google_chat_company] failed: invalid space name');
        return false;
    }

    $ok = approval_google_chat_send_message($pdo, $spaceName, $messageText);
    $lastError = function_exists('approval_google_chat_get_last_error') ? trim((string)approval_google_chat_get_last_error()) : '';
    if ($ok) {
        $logData['send_status'] = 'SUCCESS';
        $logData['sent_at'] = date('Y-m-d H:i:s');
        cpms_google_chat_log_notification($pdo, $logData);
        error_log('[google_chat_company] sent source=' . (string)$sourceType . ' source_id=' . (int)$sourceId . ' event=' . (string)$eventType);
        return true;
    }

    if ($lastError === '') $lastError = 'Google Chat API 메시지 전송에 실패했습니다.';
    $logData['send_status'] = 'FAILED';
    $logData['error_message'] = $lastError;
    cpms_google_chat_log_notification($pdo, $logData);
    error_log('[google_chat_company] failed source=' . (string)$sourceType . ' source_id=' . (int)$sourceId . ' event=' . (string)$eventType . ' error=' . $lastError);
    return false;
}
}
if (!function_exists('cpms_google_chat_send_to_executives')) {
function cpms_google_chat_send_to_executives($pdo, $messageText, $eventType, $sourceId, $sourceType) {
    try {
        if (!function_exists('approval_google_chat_send_message')) require_once __DIR__ . '/../approval/google_chat_helpers.php';
        $st = $pdo->prepare("SELECT id, name, email, google_chat_dm_space_name FROM employees WHERE is_active = 1 AND role = 'executive' AND google_chat_enabled = 1 AND google_chat_dm_space_name IS NOT NULL AND google_chat_dm_space_name <> ''");
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows || count($rows) === 0) { error_log('[google_chat_exec_notify] no executive receivers'); return; }
        foreach ($rows as $row) {
            $receiverId = isset($row['id']) ? (int)$row['id'] : null;
            $receiverName = isset($row['name']) ? (string)$row['name'] : '';
            $receiverEmail = isset($row['email']) ? (string)$row['email'] : '';
            $spaceName = isset($row['google_chat_dm_space_name']) ? trim((string)$row['google_chat_dm_space_name']) : '';
            if ($spaceName === '') continue;
            $sendMessageText = function_exists('cpms_chat_login_append_missing_tokens') ? cpms_chat_login_append_missing_tokens($messageText, (int)$receiverId) : $messageText;
            $ok = approval_google_chat_send_message($pdo, $spaceName, $sendMessageText);
            $lastError = function_exists('approval_google_chat_get_last_error') ? approval_google_chat_get_last_error() : '';
            if ($ok) {
                error_log('[google_chat_exec_notify] sent source='.$sourceType.' source_id='.(int)$sourceId.' receiver='.$receiverId);
                cpms_google_chat_log_notification($pdo, array('source_type'=>$sourceType,'source_id'=>$sourceId,'event_type'=>$eventType,'receiver_employee_id'=>$receiverId,'receiver_name'=>$receiverName,'receiver_email'=>$receiverEmail,'dm_space_name'=>$spaceName,'message_text'=>$sendMessageText,'send_status'=>'SUCCESS','error_message'=>null,'sent_at'=>date('Y-m-d H:i:s')));
            } else {
                error_log('[google_chat_exec_notify] send failed source='.$sourceType.' source_id='.(int)$sourceId.' receiver='.$receiverId.' error='.$lastError);
                cpms_google_chat_log_notification($pdo, array('source_type'=>$sourceType,'source_id'=>$sourceId,'event_type'=>$eventType,'receiver_employee_id'=>$receiverId,'receiver_name'=>$receiverName,'receiver_email'=>$receiverEmail,'dm_space_name'=>$spaceName,'message_text'=>$sendMessageText,'send_status'=>'FAILED','error_message'=>$lastError,'sent_at'=>null));
            }
        }
    } catch (Exception $e) { error_log('[google_chat_exec_notify] '.$e->getMessage()); }
}
}

if (!function_exists('cpms_google_chat_send_to_management_department')) {
function cpms_google_chat_send_to_management_department($pdo, $messageText, $eventType, $sourceId, $sourceType) {
    try {
        if (!function_exists('approval_google_chat_send_message')) require_once __DIR__ . '/../approval/google_chat_helpers.php';
        $st = $pdo->prepare("SELECT id, name, email, department, google_chat_dm_space_name FROM employees WHERE is_active = 1 AND google_chat_enabled = 1 AND google_chat_dm_space_name IS NOT NULL AND google_chat_dm_space_name <> '' AND TRIM(department) IN ('관리', '관리부') ORDER BY id ASC");
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows || count($rows) === 0) {
            error_log('[attendance_chat_notify] no management department receivers');
            return;
        }
        foreach ($rows as $row) {
            $receiverId = isset($row['id']) ? (int)$row['id'] : null;
            $receiverName = isset($row['name']) ? (string)$row['name'] : '';
            $receiverEmail = isset($row['email']) ? (string)$row['email'] : '';
            $spaceName = isset($row['google_chat_dm_space_name']) ? trim((string)$row['google_chat_dm_space_name']) : '';
            if ($spaceName === '') continue;

            $sendMessageText = function_exists('cpms_chat_login_append_missing_tokens') ? cpms_chat_login_append_missing_tokens($messageText, (int)$receiverId) : $messageText;
            $ok = approval_google_chat_send_message($pdo, $spaceName, $sendMessageText);
            $lastError = function_exists('approval_google_chat_get_last_error') ? approval_google_chat_get_last_error() : '';
            if ($ok) {
                cpms_google_chat_log_notification($pdo, array('source_type'=>$sourceType,'source_id'=>$sourceId,'event_type'=>$eventType,'receiver_employee_id'=>$receiverId,'receiver_name'=>$receiverName,'receiver_email'=>$receiverEmail,'dm_space_name'=>$spaceName,'message_text'=>$sendMessageText,'send_status'=>'SUCCESS','error_message'=>null,'sent_at'=>date('Y-m-d H:i:s')));
            } else {
                cpms_google_chat_log_notification($pdo, array('source_type'=>$sourceType,'source_id'=>$sourceId,'event_type'=>$eventType,'receiver_employee_id'=>$receiverId,'receiver_name'=>$receiverName,'receiver_email'=>$receiverEmail,'dm_space_name'=>$spaceName,'message_text'=>$sendMessageText,'send_status'=>'FAILED','error_message'=>$lastError,'sent_at'=>null));
            }
        }
    } catch (Exception $e) {
        error_log('[attendance_chat_notify] ' . $e->getMessage());
    }
}
}

if (!function_exists('cpms_google_chat_send_to_attendance_request_approvers')) {
function cpms_google_chat_send_to_attendance_request_approvers($pdo, $messageText, $eventType, $sourceId, $sourceType) {
    try {
        if (!function_exists('approval_google_chat_send_message')) require_once __DIR__ . '/../approval/google_chat_helpers.php';
        $st = $pdo->prepare("SELECT id, name, email, position, google_chat_dm_space_name FROM employees WHERE is_active = 1 AND google_chat_enabled = 1 AND google_chat_dm_space_name IS NOT NULL AND google_chat_dm_space_name <> '' AND TRIM(position) IN ('부사장', '대표') ORDER BY id ASC");
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows || count($rows) === 0) {
            error_log('[attendance_chat_notify] no vice president or representative receivers');
            return;
        }
        foreach ($rows as $row) {
            $receiverId = isset($row['id']) ? (int)$row['id'] : null;
            $receiverName = isset($row['name']) ? (string)$row['name'] : '';
            $receiverEmail = isset($row['email']) ? (string)$row['email'] : '';
            $spaceName = isset($row['google_chat_dm_space_name']) ? trim((string)$row['google_chat_dm_space_name']) : '';
            if ($spaceName === '') continue;

            $sendMessageText = function_exists('cpms_chat_login_append_missing_tokens') ? cpms_chat_login_append_missing_tokens($messageText, (int)$receiverId) : $messageText;
            $ok = approval_google_chat_send_message($pdo, $spaceName, $sendMessageText);
            $lastError = function_exists('approval_google_chat_get_last_error') ? approval_google_chat_get_last_error() : '';
            if ($ok) {
                cpms_google_chat_log_notification($pdo, array('source_type'=>$sourceType,'source_id'=>$sourceId,'event_type'=>$eventType,'receiver_employee_id'=>$receiverId,'receiver_name'=>$receiverName,'receiver_email'=>$receiverEmail,'dm_space_name'=>$spaceName,'message_text'=>$sendMessageText,'send_status'=>'SUCCESS','error_message'=>null,'sent_at'=>date('Y-m-d H:i:s')));
            } else {
                cpms_google_chat_log_notification($pdo, array('source_type'=>$sourceType,'source_id'=>$sourceId,'event_type'=>$eventType,'receiver_employee_id'=>$receiverId,'receiver_name'=>$receiverName,'receiver_email'=>$receiverEmail,'dm_space_name'=>$spaceName,'message_text'=>$sendMessageText,'send_status'=>'FAILED','error_message'=>$lastError,'sent_at'=>null));
            }
        }
    } catch (Exception $e) {
        error_log('[attendance_chat_notify] ' . $e->getMessage());
    }
}
}
