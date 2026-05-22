<?php
require_once __DIR__ . '/google_chat_helpers.php';
require_once __DIR__ . '/_common.php';

if (!function_exists('approval_notification_table_exists')) {
    function approval_notification_table_exists($pdo, $table)
    {
        try {
            $db = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
            if ($db === '') {
                return false;
            }
            $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:tbl");
            $st->execute(array(':db' => $db, ':tbl' => $table));
            return ((int)$st->fetchColumn() > 0);
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('approval_notification_column_exists')) {
    function approval_notification_column_exists($pdo, $table, $column)
    {
        try {
            $db = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
            if ($db === '') {
                return false;
            }
            $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:tbl AND COLUMN_NAME=:col");
            $st->execute(array(':db' => $db, ':tbl' => $table, ':col' => $column));
            return ((int)$st->fetchColumn() > 0);
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('approval_setting_value')) {
    function approval_setting_value($pdo, $key, $defaultValue)
    {
        try {
            $st = $pdo->prepare("SELECT setting_value FROM cpms_approval_settings WHERE setting_key=:k LIMIT 1");
            $st->execute(array(':k' => $key));
            $v = $st->fetchColumn();
            return ($v === false || $v === null || $v === '') ? $defaultValue : (string)$v;
        } catch (Exception $e) {
            return $defaultValue;
        }
    }
}

if (!function_exists('approval_queue_notification')) {
    function approval_queue_notification($pdo, $documentId, $eventType, $receiverEmployeeId, $messageText)
    {
        try {
            if ((int)$receiverEmployeeId <= 0) {
                return;
            }
            if (!approval_notification_table_exists($pdo, 'cpms_approval_notifications')) {
                return;
            }
            if (!approval_notification_table_exists($pdo, 'cpms_approval_settings')) {
                return;
            }

            $hasEnabled = approval_notification_column_exists($pdo, 'employees', 'google_chat_enabled');
            $hasDmSpace = approval_notification_column_exists($pdo, 'employees', 'google_chat_dm_space_name');
            $hasUserName = approval_notification_column_exists($pdo, 'employees', 'google_chat_user_name');

            $selEnabled = $hasEnabled ? "google_chat_enabled" : "0 AS google_chat_enabled";
            $selDmSpace = $hasDmSpace ? "google_chat_dm_space_name" : "'' AS google_chat_dm_space_name";
            $selUserName = $hasUserName ? "google_chat_user_name" : "'' AS google_chat_user_name";

            $sql = "SELECT id,name,email," . $selEnabled . "," . $selDmSpace . "," . $selUserName . " FROM employees WHERE id=:id LIMIT 1";
            $st = $pdo->prepare($sql);
            $st->execute(array(':id' => (int)$receiverEmployeeId));
            $emp = $st->fetch(PDO::FETCH_ASSOC);
            if (!$emp) {
                return;
            }

            $globalEnabled = approval_setting_value($pdo, 'google_chat_enabled', '0') === '1';
            $legacyDmEnabled = approval_setting_value($pdo, 'google_chat_dm_enabled', '') === '1';

            $dmEnabled = ($globalEnabled || $legacyDmEnabled);
            $status = 'READY';
            $err = null;
            $dmSpace = isset($emp['google_chat_dm_space_name']) ? trim((string)$emp['google_chat_dm_space_name']) : '';
            if (!$dmEnabled || (int)$emp['google_chat_enabled'] !== 1) {
                $status = 'DISABLED';
            } else if ($dmSpace === '') {
                $status = 'FAILED';
                $err = 'DM Space ID not configured';
            } else {
                $status = 'READY';
            }
            $sentAt = null;
            if ($status === 'READY') {
                $ok = approval_google_chat_send_message($pdo, $dmSpace, $messageText);
                if ($ok) {
                    $status = 'SENT';
                    $sentAt = date('Y-m-d H:i:s');
                } else {
                    $status = 'FAILED';
                    $lastErr = function_exists('approval_google_chat_get_last_error') ? approval_google_chat_get_last_error() : '';
                    $err = $lastErr !== '' ? $lastErr : 'Google Chat send failed';
                }
            }
            $pdo->prepare("INSERT INTO cpms_approval_notifications (document_id,event_type,receiver_employee_id,receiver_name,receiver_email,message_text,dm_space_name,send_status,sent_at,error_message,created_at) VALUES (:d,:e,:rid,:rn,:re,:m,:s,:st,:sa,:er,NOW())")
                ->execute(array(':d' => $documentId, ':e' => $eventType, ':rid' => $emp['id'], ':rn' => $emp['name'], ':re' => $emp['email'], ':m' => $messageText, ':s' => $dmSpace, ':st' => $status, ':sa' => $sentAt, ':er' => $err));
        } catch (Exception $e) {
            return;
        }
    }
}

if (!function_exists('approval_doc_type_label')) {
    function approval_doc_type_label($docType)
    {
        $t = trim((string)$docType);
        if ($t === 'leave') {
            return approval_doc_label('leave');
        }
        return approval_doc_label('proposal');
    }
}

if (!function_exists('approval_build_request_message')) {
    function approval_build_request_message($docType, $title, $creatorName)
    {
        $docTypeLabel = approval_doc_type_label($docType);
        $safeTitle = trim((string)$title);
        $safeCreatorName = trim((string)$creatorName);
        if ($safeTitle === '') {
            $safeTitle = approval_ko('%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%20%EB%AC%B8%EC%84%9C');
        }
        if ($safeCreatorName === '') {
            $safeCreatorName = approval_ko('%EC%9E%91%EC%84%B1%EC%9E%90');
        }
        return implode("\n", array(
            '[CPMS ' . approval_ko('%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%20%EC%9A%94%EC%B2%AD') . ']',
            '',
            approval_ko('%EB%AC%B8%EC%84%9C%EC%A2%85%EB%A5%98') . ' : ' . $docTypeLabel,
            approval_ko('%EC%A0%9C%EB%AA%A9') . ' : ' . $safeTitle,
            approval_ko('%EC%9E%91%EC%84%B1%EC%9E%90') . ' : ' . $safeCreatorName,
            '',
            approval_ko('%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%EC%97%90%EC%84%9C%20%ED%99%95%EC%9D%B8%ED%95%B4%EC%A3%BC%EC%84%B8%EC%9A%94.')
        ));
    }
}
