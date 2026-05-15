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
        if (!cpms_google_chat_table_exists($pdo, 'cpms_google_chat_notifications')) return;
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
            $ok = approval_google_chat_send_message($pdo, $spaceName, $messageText);
            $lastError = function_exists('approval_google_chat_get_last_error') ? approval_google_chat_get_last_error() : '';
            if ($ok) {
                error_log('[google_chat_exec_notify] sent source='.$sourceType.' source_id='.(int)$sourceId.' receiver='.$receiverId);
                cpms_google_chat_log_notification($pdo, array('source_type'=>$sourceType,'source_id'=>$sourceId,'event_type'=>$eventType,'receiver_employee_id'=>$receiverId,'receiver_name'=>$receiverName,'receiver_email'=>$receiverEmail,'dm_space_name'=>$spaceName,'message_text'=>$messageText,'send_status'=>'SUCCESS','error_message'=>null,'sent_at'=>date('Y-m-d H:i:s')));
            } else {
                error_log('[google_chat_exec_notify] send failed source='.$sourceType.' source_id='.(int)$sourceId.' receiver='.$receiverId.' error='.$lastError);
                cpms_google_chat_log_notification($pdo, array('source_type'=>$sourceType,'source_id'=>$sourceId,'event_type'=>$eventType,'receiver_employee_id'=>$receiverId,'receiver_name'=>$receiverName,'receiver_email'=>$receiverEmail,'dm_space_name'=>$spaceName,'message_text'=>$messageText,'send_status'=>'FAILED','error_message'=>$lastError,'sent_at'=>null));
            }
        }
    } catch (Exception $e) { error_log('[google_chat_exec_notify] '.$e->getMessage()); }
}
}