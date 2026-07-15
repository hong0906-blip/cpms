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

if (!function_exists('approval_notification_append_url')) {
    function approval_notification_append_url($pdo, $documentId, $messageText, $receiverEmployeeId)
    {
        $messageText = (string)$messageText;
        if (stripos($messageText, 'URL :') !== false || stripos($messageText, 'http://') !== false || stripos($messageText, 'https://') !== false) {
            return $messageText;
        }
        if (!function_exists('cpms_app_approval_url')) {
            return $messageText;
        }
        $url = cpms_app_approval_url($pdo, (int)$documentId, (int)$receiverEmployeeId);
        if (trim((string)$url) === '') {
            return $messageText;
        }
        return rtrim($messageText) . "\nURL : " . $url;
    }
}

if (!function_exists('approval_find_ceo_notification_employee')) {
    function approval_find_ceo_notification_employee($pdo, $documentId)
    {
        if (!$pdo) {
            return null;
        }
        if ((int)$documentId > 0 && approval_notification_table_exists($pdo, 'cpms_approval_lines')) {
            try {
                $st = $pdo->prepare("SELECT approver_id AS id, approver_name AS name, approver_email AS email, role_type FROM cpms_approval_lines WHERE document_id=:document_id ORDER BY line_order ASC, id ASC");
                $st->execute(array(':document_id' => (int)$documentId));
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                if (is_array($rows)) {
                    for ($i = 0; $i < count($rows); $i++) {
                        $role = isset($rows[$i]['role_type']) ? $rows[$i]['role_type'] : '';
                        if (approval_role_is_ceo($role) && isset($rows[$i]['id']) && (int)$rows[$i]['id'] > 0) {
                            return $rows[$i];
                        }
                    }
                }
            } catch (Exception $e) {
            }
        }
        if (function_exists('approval_find_ceo_employee_for_proxy')) {
            return approval_find_ceo_employee_for_proxy($pdo);
        }
        return null;
    }
}

if (!function_exists('approval_build_final_approved_message')) {
    function approval_build_final_approved_message($docType, $title, $creatorName)
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
            '[CPMS ' . $docTypeLabel . ' ' . approval_ko('%EC%B5%9C%EC%A2%85%EC%8A%B9%EC%9D%B8') . ']',
            '',
            approval_ko('%EB%AC%B8%EC%84%9C%EC%A2%85%EB%A5%98') . ' : ' . $docTypeLabel,
            approval_ko('%EC%A0%9C%EB%AA%A9') . ' : ' . $safeTitle,
            approval_ko('%EC%9E%91%EC%84%B1%EC%9E%90') . ' : ' . $safeCreatorName,
            '',
            approval_ko('%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%EC%97%90%EC%84%9C%20%ED%99%95%EC%9D%B8%ED%95%B4%EC%A3%BC%EC%84%B8%EC%9A%94.')
        ));
    }
}

if (!function_exists('approval_build_representative_final_approved_message')) {
    function approval_build_representative_final_approved_message($docType, $title, $creatorName)
    {
        $docTypeLabel = approval_doc_type_label($docType);
        $safeTitle = trim((string)$title);
        $safeCreatorName = trim((string)$creatorName);
        if ($safeTitle === '') {
            $safeTitle = '전자결재 문서';
        }
        if ($safeCreatorName === '') {
            $safeCreatorName = '작성자';
        }
        return implode("\n", array(
            '[CPMS 승인완료 문서 안내]',
            '',
            '최종 승인되어 완료된 전자결재 문서가 있습니다.',
            '',
            '문서종류 : ' . $docTypeLabel,
            '제목 : ' . $safeTitle,
            '작성자 : ' . $safeCreatorName,
            '',
            '전자결재 완료된 문서에서 확인해주세요.'
        ));
    }
}

if (!function_exists('approval_queue_final_approved_to_representative')) {
    function approval_queue_final_approved_to_representative($pdo, $documentId, $docRow, $skipEmployeeIds)
    {
        if (!$pdo || (int)$documentId <= 0 || !is_array($docRow)) {
            return;
        }
        $representative = null;
        if (function_exists('approval_find_ceo_employee_for_proxy')) {
            try {
                $representative = approval_find_ceo_employee_for_proxy($pdo);
            } catch (Exception $e) {
                $representative = null;
            }
        }
        if (!is_array($representative) || !isset($representative['id']) || (int)$representative['id'] <= 0) {
            $representative = approval_find_ceo_notification_employee($pdo, $documentId);
        }
        if (!is_array($representative) || !isset($representative['id']) || (int)$representative['id'] <= 0) {
            return;
        }
        $representativeId = (int)$representative['id'];
        $skipEmployeeIds = is_array($skipEmployeeIds) ? $skipEmployeeIds : array();
        for ($i = 0; $i < count($skipEmployeeIds); $i++) {
            if ($representativeId === (int)$skipEmployeeIds[$i]) {
                return;
            }
        }
        $msg = approval_build_representative_final_approved_message(
            isset($docRow['doc_type']) ? $docRow['doc_type'] : '',
            isset($docRow['title']) ? $docRow['title'] : '',
            isset($docRow['created_by_name']) ? $docRow['created_by_name'] : ''
        );
        approval_queue_notification($pdo, $documentId, 'FINAL_APPROVED_REPRESENTATIVE', $representativeId, $msg);
    }
}

if (!function_exists('approval_queue_leave_final_approved_to_ceo')) {
    function approval_queue_leave_final_approved_to_ceo($pdo, $documentId, $docRow, $skipEmployeeIds)
    {
        if (!$pdo || (int)$documentId <= 0 || !is_array($docRow)) {
            return;
        }
        $docType = isset($docRow['doc_type']) ? strtolower(trim((string)$docRow['doc_type'])) : '';
        if ($docType !== 'leave') {
            return;
        }
        approval_queue_final_approved_to_representative($pdo, $documentId, $docRow, $skipEmployeeIds);
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
            $messageText = approval_notification_append_url($pdo, $documentId, $messageText, $receiverEmployeeId);
            if (function_exists('cpms_chat_login_append_missing_tokens')) {
                $messageText = cpms_chat_login_append_missing_tokens($messageText, (int)$receiverEmployeeId);
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
        if (approval_is_proposal_doc_type($t) || in_array($t, array('leave', 'unused_leave_notice', 'unused_leave_plan'), true)) {
            return approval_doc_label($t);
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
