<?php
function approval_setting_value($pdo, $key, $defaultValue) {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM cpms_approval_settings WHERE setting_key=:k LIMIT 1");
        $st->execute(array(':k'=>$key));
        $v = $st->fetchColumn();
        return ($v===false || $v===null || $v==='') ? $defaultValue : (string)$v;
    } catch (Exception $e) { return $defaultValue; }
}

function approval_queue_notification($pdo, $documentId, $eventType, $receiverEmployeeId, $messageText) {
    $st = $pdo->prepare("SELECT id,name,email,google_chat_enabled,google_chat_dm_space_name FROM employees WHERE id=:id LIMIT 1");
    $st->execute(array(':id'=>(int)$receiverEmployeeId));
    $emp = $st->fetch();
    if (!$emp) return;
    $dmEnabled = approval_setting_value($pdo, 'google_chat_dm_enabled', '0') === '1';
    $status = 'READY'; $err = null;
    $dmSpace = isset($emp['google_chat_dm_space_name']) ? trim((string)$emp['google_chat_dm_space_name']) : '';
    if (!$dmEnabled || (int)$emp['google_chat_enabled'] !== 1) { $status = 'DISABLED'; }
    else if ($dmSpace === '') { $status = 'FAILED'; $err = 'DM Space ID가 등록되지 않았습니다.'; }
    else { $status = 'SENT'; }
    $sentAt = ($status==='SENT') ? date('Y-m-d H:i:s') : null;
    $pdo->prepare("INSERT INTO cpms_approval_notifications (document_id,event_type,receiver_employee_id,receiver_name,receiver_email,message_text,dm_space_name,send_status,sent_at,error_message,created_at) VALUES (:d,:e,:rid,:rn,:re,:m,:s,:st,:sa,:er,NOW())")
        ->execute(array(':d'=>$documentId,':e'=>$eventType,':rid'=>$emp['id'],':rn'=>$emp['name'],':re'=>$emp['email'],':m'=>$messageText,':s'=>$dmSpace,':st'=>$status,':sa'=>$sentAt,':er'=>$err));
}