<?php
/**
 * Electronic approval Google Drive attachment helpers.
 * PHP 5.6 compatible. Reuses GoogleDriveHelper; does not implement Google auth.
 */

require_once __DIR__ . '/GoogleDriveHelper.php';

if (!function_exists('cpms_approval_drive_table_exists')) {
function cpms_approval_drive_table_exists($pdo, $table) {
    if (!$pdo || trim((string)$table) === '') return false;
    if (function_exists('approval_table_exists')) return approval_table_exists($pdo, $table);
    try {
        $db = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
        if ($db === '') return false;
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:tbl");
        $st->execute(array(':db' => $db, ':tbl' => $table));
        return ((int)$st->fetchColumn() > 0);
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_approval_drive_column_exists')) {
function cpms_approval_drive_column_exists($pdo, $table, $column) {
    if (!$pdo || trim((string)$table) === '' || trim((string)$column) === '') return false;
    if (function_exists('approval_table_column_exists')) return approval_table_column_exists($pdo, $table, $column);
    try {
        $db = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
        if ($db === '') return false;
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:tbl AND COLUMN_NAME=:col");
        $st->execute(array(':db' => $db, ':tbl' => $table, ':col' => $column));
        return ((int)$st->fetchColumn() > 0);
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_approval_drive_ensure_file_columns')) {
function cpms_approval_drive_ensure_file_columns($pdo) {
    if (!$pdo || !cpms_approval_drive_table_exists($pdo, 'cpms_approval_files')) return false;
    $columns = array(
        'storage_type' => "ALTER TABLE cpms_approval_files ADD COLUMN storage_type VARCHAR(30) NULL",
        'drive_name' => "ALTER TABLE cpms_approval_files ADD COLUMN drive_name VARCHAR(255) NULL",
        'drive_file_id' => "ALTER TABLE cpms_approval_files ADD COLUMN drive_file_id VARCHAR(128) NULL",
        'drive_folder_id' => "ALTER TABLE cpms_approval_files ADD COLUMN drive_folder_id VARCHAR(128) NULL",
        'drive_web_view_link' => "ALTER TABLE cpms_approval_files ADD COLUMN drive_web_view_link TEXT NULL",
        'drive_web_content_link' => "ALTER TABLE cpms_approval_files ADD COLUMN drive_web_content_link TEXT NULL",
        'mime_type' => "ALTER TABLE cpms_approval_files ADD COLUMN mime_type VARCHAR(190) NULL",
        'file_size' => "ALTER TABLE cpms_approval_files ADD COLUMN file_size BIGINT NULL",
        'uploaded_by' => "ALTER TABLE cpms_approval_files ADD COLUMN uploaded_by VARCHAR(190) NULL",
        'uploaded_at' => "ALTER TABLE cpms_approval_files ADD COLUMN uploaded_at DATETIME NULL",
        'upload_status' => "ALTER TABLE cpms_approval_files ADD COLUMN upload_status VARCHAR(30) NULL",
        'drive_upload_error' => "ALTER TABLE cpms_approval_files ADD COLUMN drive_upload_error TEXT NULL"
    );
    $ok = true;
    foreach ($columns as $column => $sql) {
        if (cpms_approval_drive_column_exists($pdo, 'cpms_approval_files', $column)) continue;
        try {
            $pdo->exec($sql);
        } catch (Exception $e) {
            $ok = false;
            cpms_drive_log_upload_failure(array(
                'section' => 'approval_schema',
                'message' => 'Approval file Drive column creation failed: ' . $column . ' / ' . $e->getMessage()
            ));
        }
    }
    return $ok;
}}

if (!function_exists('cpms_approval_drive_ensure_document_columns')) {
function cpms_approval_drive_ensure_document_columns($pdo) {
    if (!$pdo || !cpms_approval_drive_table_exists($pdo, 'cpms_approval_documents')) return false;
    if (cpms_approval_drive_column_exists($pdo, 'cpms_approval_documents', 'project_id')) return true;
    try {
        $pdo->exec("ALTER TABLE cpms_approval_documents ADD COLUMN project_id INT NULL");
        return true;
    } catch (Exception $e) {
        cpms_drive_log_upload_failure(array(
            'section' => 'approval_schema',
            'message' => 'Approval document project_id column creation failed: ' . $e->getMessage()
        ));
        return false;
    }
}}

if (!function_exists('cpms_approval_drive_normalize_text')) {
function cpms_approval_drive_normalize_text($value) {
    if (function_exists('approval_normalize_compare_text')) return approval_normalize_compare_text($value);
    $value = trim((string)$value);
    $value = str_replace(array(' ', "\t", "\r", "\n", '-', '_', '/', '.', ','), '', $value);
    if (function_exists('mb_strtolower')) return mb_strtolower($value, 'UTF-8');
    return strtolower($value);
}}

if (!function_exists('cpms_approval_drive_folder_key_from_value')) {
function cpms_approval_drive_folder_key_from_value($value) {
    $raw = trim((string)$value);
    if ($raw === '') return '';
    $lower = strtolower($raw);
    if ($lower === 'proposal' || $lower === 'draft' || $lower === 'draft_doc') return 'draft';
    if ($lower === 'leave' || $lower === 'vacation') return 'leave';
    if ($lower === 'expense' || $lower === 'expense_resolution') return 'expense';
    if ($lower === 'unused_leave_notice' || $lower === 'unused_leave_plan') return 'unused_leave';
    if ($lower === 'completed' || $lower === 'completed_document') return 'completed';
    if ($lower === 'other' || $lower === 'etc') return 'other';

    $norm = cpms_approval_drive_normalize_text($raw);
    $names = cpms_drive_approval_folder_names();
    foreach ($names as $key => $name) {
        if ($norm === cpms_approval_drive_normalize_text($name)) return $key;
    }
    if ($norm === cpms_approval_drive_normalize_text(urldecode('%ED%92%88%EC%9D%98'))) return 'proposal';
    if (strpos($norm, cpms_approval_drive_normalize_text(urldecode('%EC%A7%80%EC%B6%9C%EA%B2%B0%EC%9D%98'))) !== false) return 'expense';
    if (strpos($norm, cpms_approval_drive_normalize_text(urldecode('%EB%AF%B8%EC%82%AC%EC%9A%A9%EC%97%B0%EC%B0%A8'))) !== false) return 'unused_leave';
    if ($norm === cpms_approval_drive_normalize_text(urldecode('%EA%B8%B0%ED%83%80'))) return 'other';
    return '';
}}

if (!function_exists('cpms_approval_drive_document_folder')) {
function cpms_approval_drive_document_folder($docType, $content) {
    $docType = trim((string)$docType);
    $docTypeKey = cpms_approval_drive_folder_key_from_value($docType);
    if (in_array($docTypeKey, array('leave', 'unused_leave', 'expense', 'completed'), true)) {
        return array('key' => $docTypeKey, 'label' => cpms_drive_approval_folder_name($docTypeKey));
    }

    $content = is_array($content) ? $content : array();
    $keys = array('document_type', 'form_type', 'approval_type', 'draft_type', 'doc_type');
    for ($i = 0; $i < count($keys); $i++) {
        $key = $keys[$i];
        if (!isset($content[$key])) continue;
        $mapped = cpms_approval_drive_folder_key_from_value($content[$key]);
        if ($mapped !== '') return array('key' => $mapped, 'label' => cpms_drive_approval_folder_name($mapped));
    }

    if ($docTypeKey !== '') return array('key' => $docTypeKey, 'label' => cpms_drive_approval_folder_name($docTypeKey));
    return array('key' => 'other', 'label' => cpms_drive_approval_folder_name('other'));
}}

if (!function_exists('cpms_approval_drive_document_date')) {
function cpms_approval_drive_document_date($docRow, $content) {
    $content = is_array($content) ? $content : array();
    $candidates = array();
    foreach (array('draft_date', 'request_date', 'sent_at', 'created_at', 'updated_at') as $key) {
        if (isset($content[$key])) $candidates[] = $content[$key];
    }
    if (is_array($docRow)) {
        foreach (array('created_at', 'updated_at') as $key) {
            if (isset($docRow[$key])) $candidates[] = $docRow[$key];
        }
    }
    for ($i = 0; $i < count($candidates); $i++) {
        $ts = strtotime((string)$candidates[$i]);
        if ($ts !== false) return date('Y-m-d', $ts);
    }
    return date('Y-m-d');
}}

if (!function_exists('cpms_approval_drive_project_id')) {
function cpms_approval_drive_project_id($docRow, $content) {
    if (is_array($docRow) && isset($docRow['project_id']) && (int)$docRow['project_id'] > 0) return (int)$docRow['project_id'];
    if (is_array($content) && isset($content['project_id']) && (int)$content['project_id'] > 0) return (int)$content['project_id'];
    return 0;
}}

if (!function_exists('cpms_approval_drive_drafter_name')) {
function cpms_approval_drive_drafter_name($docRow, $content) {
    $content = is_array($content) ? $content : array();
    foreach (array('drafter_name', 'applicant_name', 'sender_name', 'writer_name') as $key) {
        if (isset($content[$key]) && trim((string)$content[$key]) !== '') return trim((string)$content[$key]);
    }
    if (is_array($docRow) && isset($docRow['created_by_name']) && trim((string)$docRow['created_by_name']) !== '') return trim((string)$docRow['created_by_name']);
    return '-';
}}

if (!function_exists('cpms_approval_drive_build_file_name')) {
function cpms_approval_drive_build_file_name($date, $documentTypeLabel, $drafterName, $originalName) {
    $date = trim((string)$date);
    if ($date === '') $date = date('Y-m-d');
    $parts = array(
        $date,
        urldecode('%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC'),
        trim((string)$documentTypeLabel),
        trim((string)$drafterName),
        date('His') . '_' . mt_rand(1000, 9999),
        trim((string)$originalName)
    );
    return cpms_drive_sanitize_file_name(implode('_', $parts), 180);
}}

if (!function_exists('cpms_approval_drive_failed_record')) {
function cpms_approval_drive_failed_record($originalName, $localPath, $mimeType, $size, $userContext, $message) {
    return array(
        'original_name' => (string)$originalName,
        'stored_name' => '',
        'drive_file_id' => '',
        'drive_folder_id' => '',
        'drive_web_view_link' => '',
        'drive_web_content_link' => '',
        'mime_type' => (string)$mimeType,
        'size' => (string)$size,
        'uploaded_by' => cpms_drive_user_label($userContext),
        'uploaded_at' => date('Y-m-d H:i:s'),
        'storage_type' => 'local',
        'local_backup_path' => (string)$localPath,
        'upload_status' => 'failed',
        'drive_upload_error' => cpms_drive_redact_text((string)$message)
    );
}}

if (!function_exists('cpms_approval_drive_upload_local_file')) {
function cpms_approval_drive_upload_local_file($localPath, $originalName, $docRow, $content, $fileMeta, $userContext) {
    $localPath = trim((string)$localPath);
    $originalName = trim((string)$originalName);
    $fileMeta = is_array($fileMeta) ? $fileMeta : array();
    $docRow = is_array($docRow) ? $docRow : array();
    $content = is_array($content) ? $content : array();
    $mimeType = cpms_drive_detect_mime_type($localPath);
    $size = (is_file($localPath) ? (int)@filesize($localPath) : 0);
    $date = cpms_approval_drive_document_date($docRow, $content);
    $folderInfo = cpms_approval_drive_document_folder(isset($docRow['doc_type']) ? $docRow['doc_type'] : '', $content);
    $projectId = cpms_approval_drive_project_id($docRow, $content);
    $documentId = isset($docRow['id']) ? (int)$docRow['id'] : 0;
    $context = array(
        'user' => $userContext,
        'uploaded_by' => $userContext,
        'section' => 'approval',
        'approval_document_id' => $documentId,
        'approval_id' => $documentId,
        'document_type' => $folderInfo['label'],
        'project_id' => $projectId > 0 ? $projectId : '',
        'original_name' => $originalName,
        'mime_type' => $mimeType,
        'size' => $size,
        'local_backup_path' => isset($fileMeta['local_path']) ? (string)$fileMeta['local_path'] : ''
    );

    if ($localPath === '' || !is_file($localPath)) {
        $message = 'Local approval file is not available for Drive upload.';
        cpms_drive_log_upload_failure(array_merge($context, array('message' => $message)));
        return array('ok' => false, 'record' => cpms_approval_drive_failed_record($originalName, isset($fileMeta['local_path']) ? $fileMeta['local_path'] : '', $mimeType, $size, $userContext, $message), 'message' => $message, 'http_code' => 0);
    }

    $folder = cpms_drive_ensure_approval_folder((int)substr($date, 0, 4), $folderInfo['key'], $context);
    if (empty($folder['ok'])) {
        $message = isset($folder['message']) ? $folder['message'] : 'Approval Drive folder preparation failed.';
        return array('ok' => false, 'record' => cpms_approval_drive_failed_record($originalName, isset($fileMeta['local_path']) ? $fileMeta['local_path'] : '', $mimeType, $size, $userContext, $message), 'message' => $message, 'http_code' => isset($folder['http_code']) ? (int)$folder['http_code'] : 0);
    }

    $context['target_folder_id'] = (string)$folder['folder_id'];
    $driveName = cpms_approval_drive_build_file_name($date, $folderInfo['label'], cpms_approval_drive_drafter_name($docRow, $content), $originalName);
    $upload = cpms_drive_upload_file($localPath, $driveName, (string)$folder['folder_id'], $mimeType, $context);
    if (empty($upload['ok']) || !isset($upload['file']) || !is_array($upload['file'])) {
        $message = isset($upload['message']) ? $upload['message'] : 'Approval file Drive upload failed.';
        return array('ok' => false, 'record' => cpms_approval_drive_failed_record($originalName, isset($fileMeta['local_path']) ? $fileMeta['local_path'] : '', $mimeType, $size, $userContext, $message), 'message' => $message, 'http_code' => isset($upload['http_code']) ? (int)$upload['http_code'] : 0);
    }

    $context['uploaded_at'] = date('Y-m-d H:i:s');
    $record = cpms_drive_build_file_record($upload['file'], $context);
    $record['upload_status'] = 'uploaded';
    $record['drive_upload_error'] = '';
    return array('ok' => true, 'record' => $record, 'message' => isset($upload['message']) ? $upload['message'] : '', 'http_code' => isset($upload['http_code']) ? (int)$upload['http_code'] : 0);
}}

if (!function_exists('cpms_approval_drive_save_file_row')) {
function cpms_approval_drive_save_file_row($pdo, $row) {
    if (!$pdo || !is_array($row)) return array('ok' => false, 'id' => 0, 'message' => 'Invalid file row.');
    $cols = array('document_id', 'original_name', 'saved_name', 'file_path', 'file_label', 'file_type', 'created_at');
    $marks = array(':document_id', ':original_name', ':saved_name', ':file_path', ':file_label', ':file_type', 'NOW()');
    $params = array(
        ':document_id' => isset($row['document_id']) ? (int)$row['document_id'] : 0,
        ':original_name' => isset($row['original_name']) ? (string)$row['original_name'] : '',
        ':saved_name' => isset($row['saved_name']) ? (string)$row['saved_name'] : '',
        ':file_path' => isset($row['file_path']) ? (string)$row['file_path'] : '',
        ':file_label' => isset($row['file_label']) ? (string)$row['file_label'] : '',
        ':file_type' => isset($row['file_type']) ? (string)$row['file_type'] : ''
    );
    $extra = array(
        'storage_type' => isset($row['storage_type']) ? (string)$row['storage_type'] : 'local',
        'drive_name' => isset($row['stored_name']) ? (string)$row['stored_name'] : '',
        'drive_file_id' => isset($row['drive_file_id']) ? (string)$row['drive_file_id'] : '',
        'drive_folder_id' => isset($row['drive_folder_id']) ? (string)$row['drive_folder_id'] : '',
        'drive_web_view_link' => isset($row['drive_web_view_link']) ? (string)$row['drive_web_view_link'] : '',
        'drive_web_content_link' => isset($row['drive_web_content_link']) ? (string)$row['drive_web_content_link'] : '',
        'mime_type' => isset($row['mime_type']) ? (string)$row['mime_type'] : '',
        'file_size' => (isset($row['size']) && $row['size'] !== '') ? (int)$row['size'] : null,
        'uploaded_by' => isset($row['uploaded_by']) ? (string)$row['uploaded_by'] : '',
        'uploaded_at' => isset($row['uploaded_at']) ? (string)$row['uploaded_at'] : date('Y-m-d H:i:s'),
        'upload_status' => isset($row['upload_status']) ? (string)$row['upload_status'] : '',
        'drive_upload_error' => isset($row['drive_upload_error']) ? (string)$row['drive_upload_error'] : ''
    );
    foreach ($extra as $column => $value) {
        if (!cpms_approval_drive_column_exists($pdo, 'cpms_approval_files', $column)) continue;
        $param = ':' . $column;
        $cols[] = $column;
        $marks[] = $param;
        $params[$param] = $value;
    }
    try {
        $sql = "INSERT INTO cpms_approval_files (" . implode(',', $cols) . ") VALUES (" . implode(',', $marks) . ")";
        $pdo->prepare($sql)->execute($params);
        return array('ok' => true, 'id' => (int)$pdo->lastInsertId(), 'message' => '');
    } catch (Exception $e) {
        cpms_drive_log_upload_failure(array(
            'section' => 'approval_db_save',
            'approval_document_id' => isset($row['document_id']) ? (int)$row['document_id'] : 0,
            'document_type' => isset($row['document_type']) ? (string)$row['document_type'] : '',
            'project_id' => isset($row['project_id']) ? (string)$row['project_id'] : '',
            'original_name' => isset($row['original_name']) ? (string)$row['original_name'] : '',
            'target_folder_id' => isset($row['drive_folder_id']) ? (string)$row['drive_folder_id'] : '',
            'message' => 'Approval file metadata save failed: ' . $e->getMessage()
        ));
        return array('ok' => false, 'id' => 0, 'message' => $e->getMessage());
    }
}}

if (!function_exists('cpms_approval_drive_h')) {
function cpms_approval_drive_h($value) {
    if (function_exists('h')) return h($value);
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}}

if (!function_exists('cpms_approval_drive_file_storage_type')) {
function cpms_approval_drive_file_storage_type($fileRow) {
    if (!is_array($fileRow) || !isset($fileRow['storage_type']) || trim((string)$fileRow['storage_type']) === '') return 'local';
    return strtolower(trim((string)$fileRow['storage_type']));
}}

if (!function_exists('cpms_approval_drive_file_links_html')) {
function cpms_approval_drive_file_links_html($fileRow) {
    if (!is_array($fileRow)) return cpms_approval_drive_h(urldecode('%ED%8C%8C%EC%9D%BC%20%ED%99%95%EC%9D%B8%20%ED%95%84%EC%9A%94'));
    $id = isset($fileRow['id']) ? (int)$fileRow['id'] : 0;
    $storageType = cpms_approval_drive_file_storage_type($fileRow);
    $viewText = cpms_approval_drive_h(urldecode('%EB%B3%B4%EA%B8%B0'));
    $downText = cpms_approval_drive_h(urldecode('%EB%8B%A4%EC%9A%B4%EB%A1%9C%EB%93%9C'));
    $needText = cpms_approval_drive_h(urldecode('%ED%8C%8C%EC%9D%BC%20%ED%99%95%EC%9D%B8%20%ED%95%84%EC%9A%94'));
    if ($id <= 0) return '<span class="text-amber-700">' . $needText . '</span>';
    $base = '?r=approval_file&id=' . $id;
    $html = array();
    if ($storageType === 'google_drive') {
        $hasView = (isset($fileRow['drive_web_view_link']) && trim((string)$fileRow['drive_web_view_link']) !== '');
        $hasContent = (isset($fileRow['drive_web_content_link']) && trim((string)$fileRow['drive_web_content_link']) !== '');
        if ($hasView) $html[] = '<a href="' . cpms_approval_drive_h($base) . '" target="_blank">' . $viewText . '</a>';
        if ($hasContent) $html[] = '<a href="' . cpms_approval_drive_h($base . '&download=1') . '">' . $downText . '</a>';
    } else {
        $hasLocal = (isset($fileRow['file_path']) && trim((string)$fileRow['file_path']) !== '');
        if ($hasLocal) {
            $html[] = '<a href="' . cpms_approval_drive_h($base) . '" target="_blank">' . $viewText . '</a>';
            $html[] = '<a href="' . cpms_approval_drive_h($base . '&download=1') . '">' . $downText . '</a>';
        }
    }
    if (count($html) === 0) return '<span class="text-amber-700">' . $needText . '</span>';
    return implode(' ', $html);
}}

if (!function_exists('cpms_approval_drive_is_absolute_path')) {
function cpms_approval_drive_is_absolute_path($path) {
    $path = trim((string)$path);
    return (strpos($path, '/') === 0 || preg_match('/^[A-Za-z]:[\/\\\\]/', $path));
}}

if (!function_exists('cpms_approval_drive_resolve_local_path')) {
function cpms_approval_drive_resolve_local_path($storedPath) {
    $storedPath = trim((string)$storedPath);
    if ($storedPath === '') return '';
    $root = realpath(dirname(dirname(__DIR__)));
    $candidate = cpms_approval_drive_is_absolute_path($storedPath) ? $storedPath : dirname(dirname(__DIR__)) . '/' . ltrim($storedPath, '/\\');
    $real = realpath($candidate);
    if ($real === false || !is_file($real)) return '';
    if ($root !== false) {
        $rootNorm = str_replace('\\', '/', rtrim($root, '/\\')) . '/';
        $realNorm = str_replace('\\', '/', $real);
        if (strpos($realNorm, $rootNorm) !== 0) return '';
    }
    return $real;
}}
