<?php
/**
 * Quality-section Google Drive helpers.
 * PHP 5.6 compatible. Reuses GoogleDriveHelper; does not implement Google auth.
 */

require_once __DIR__ . '/GoogleDriveHelper.php';

if (!function_exists('cpms_quality_drive_h')) {
function cpms_quality_drive_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}}

if (!function_exists('cpms_quality_drive_label')) {
function cpms_quality_drive_label($key) {
    $labels = array(
        'section' => '%ED%92%88%EC%A7%88',
        'section_key' => 'quality',
        'root' => '%30%35%5F%ED%92%88%EC%A7%88',
        'common_root' => '%ED%92%88%EC%A7%88',
        'material_approval' => '%EC%9E%90%EC%9E%AC%EC%8A%B9%EC%9D%B8',
        'inspection' => '%EA%B2%80%EC%B8%A1',
        'test_report' => '%EC%8B%9C%ED%97%98%EC%84%B1%EC%A0%81%EC%84%9C',
        'cqi' => 'CQI',
        'submission' => '%EC%A0%9C%EC%B6%9C%EB%AC%B8%EC%84%9C',
        'etc' => '%EA%B8%B0%ED%83%80',
        'common' => '%EA%B3%B5%ED%86%B5',
        'file_check_required' => '%ED%8C%8C%EC%9D%BC%20%ED%99%95%EC%9D%B8%20%ED%95%84%EC%9A%94',
        'view' => '%EB%B3%B4%EA%B8%B0',
        'download' => '%EB%8B%A4%EC%9A%B4%EB%A1%9C%EB%93%9C',
        'month_review' => '%ED%92%88%EC%A7%88%20%EC%84%B9%EC%85%98%20%EC%9B%94%20%EA%B0%92%20%ED%99%95%EC%9D%B8%20%ED%95%84%EC%9A%94',
        'upload_failed_notice' => '%ED%8C%8C%EC%9D%BC%EC%9D%80%20%EC%A0%80%EC%9E%A5%EB%90%98%EC%97%88%EC%A7%80%EB%A7%8C%20Google%20Drive%20%EC%97%85%EB%A1%9C%EB%93%9C%EC%97%90%20%EC%8B%A4%ED%8C%A8%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.%20%EA%B4%80%EB%A6%AC%EC%9E%90%EC%97%90%EA%B2%8C%20%EB%AC%B8%EC%9D%98%ED%95%B4%EC%A3%BC%EC%84%B8%EC%9A%94.',
        'project_sync_required' => '%ED%94%84%EB%A1%9C%EC%A0%9D%ED%8A%B8%20Drive%20%ED%8F%B4%EB%8D%94%20%EB%8F%99%EA%B8%B0%ED%99%94%20%ED%95%84%EC%9A%94'
    );
    return isset($labels[$key]) ? urldecode($labels[$key]) : (string)$key;
}}

if (!function_exists('cpms_quality_drive_document_info')) {
function cpms_quality_drive_document_info($documentType, $originalName) {
    $type = trim((string)$documentType);
    $originalName = trim((string)$originalName);
    $norm = function_exists('mb_strtolower') ? mb_strtolower($type . ' ' . $originalName, 'UTF-8') : strtolower($type . ' ' . $originalName);
    $map = array(
        'material_approval' => array('folder_key' => 'quality_material_approval', 'folder_label' => cpms_quality_drive_label('material_approval'), 'document_type' => 'material_approval', 'document_label' => cpms_quality_drive_label('material_approval')),
        'inspection' => array('folder_key' => 'quality_inspection', 'folder_label' => cpms_quality_drive_label('inspection'), 'document_type' => 'inspection', 'document_label' => cpms_quality_drive_label('inspection')),
        'inspection_photo' => array('folder_key' => 'quality_inspection', 'folder_label' => cpms_quality_drive_label('inspection'), 'document_type' => 'inspection', 'document_label' => cpms_quality_drive_label('inspection')),
        'test_report' => array('folder_key' => 'quality_test_report', 'folder_label' => cpms_quality_drive_label('test_report'), 'document_type' => 'test_report', 'document_label' => cpms_quality_drive_label('test_report')),
        'cqi' => array('folder_key' => 'quality_cqi', 'folder_label' => cpms_quality_drive_label('cqi'), 'document_type' => 'cqi', 'document_label' => cpms_quality_drive_label('cqi')),
        'submission' => array('folder_key' => 'quality_submission', 'folder_label' => cpms_quality_drive_label('submission'), 'document_type' => 'submission', 'document_label' => cpms_quality_drive_label('submission')),
        'etc' => array('folder_key' => 'quality_etc', 'folder_label' => cpms_quality_drive_label('etc'), 'document_type' => 'etc', 'document_label' => cpms_quality_drive_label('etc'))
    );
    if (isset($map[$type])) return $map[$type];
    if (strpos($norm, 'material') !== false || strpos($norm, 'approval') !== false) return $map['material_approval'];
    if (strpos($norm, 'inspection') !== false || strpos($norm, 'photo') !== false || strpos($norm, 'jpg') !== false || strpos($norm, 'jpeg') !== false || strpos($norm, 'png') !== false || strpos($norm, 'webp') !== false) return $map['inspection'];
    if (strpos($norm, 'test') !== false || strpos($norm, 'report') !== false) return $map['test_report'];
    if (strpos($norm, 'cqi') !== false) return $map['cqi'];
    if (strpos($norm, 'submission') !== false || strpos($norm, 'submit') !== false) return $map['submission'];
    return $map['etc'];
}}

if (!function_exists('cpms_quality_drive_folder_aliases')) {
function cpms_quality_drive_folder_aliases($key) {
    $aliases = array(
        'quality_material_approval' => array('quality_material', 'quality_approval'),
        'quality_inspection' => array('quality_inspect'),
        'quality_test_report' => array('quality_test_reports'),
        'quality_submission' => array('quality_submit'),
        'quality_etc' => array('quality_other')
    );
    return isset($aliases[$key]) ? $aliases[$key] : array();
}}

if (!function_exists('cpms_quality_drive_project_drive_data')) {
function cpms_quality_drive_project_drive_data($project) {
    $data = array();
    if (is_array($project) && isset($project['drive_folders_json']) && trim((string)$project['drive_folders_json']) !== '') {
        $decoded = @json_decode((string)$project['drive_folders_json'], true);
        if (is_array($decoded)) $data = $decoded;
    }
    if (!isset($data['folders']) || !is_array($data['folders'])) $data['folders'] = array();
    if (is_array($project) && isset($project['drive_folder_id']) && trim((string)$project['drive_folder_id']) !== '') {
        $data['project_folder_id'] = trim((string)$project['drive_folder_id']);
        $data['folders']['project'] = trim((string)$project['drive_folder_id']);
    }
    return $data;
}}

if (!function_exists('cpms_quality_drive_folder_id_from_data')) {
function cpms_quality_drive_folder_id_from_data($driveData, $key) {
    if (!is_array($driveData) || !isset($driveData['folders']) || !is_array($driveData['folders'])) return '';
    if (isset($driveData['folders'][$key]) && trim((string)$driveData['folders'][$key]) !== '') return trim((string)$driveData['folders'][$key]);
    $aliases = cpms_quality_drive_folder_aliases($key);
    foreach ($aliases as $alias) {
        if (isset($driveData['folders'][$alias]) && trim((string)$driveData['folders'][$alias]) !== '') return trim((string)$driveData['folders'][$alias]);
    }
    return '';
}}

if (!function_exists('cpms_quality_drive_save_project_data')) {
function cpms_quality_drive_save_project_data($pdo, $projectId, $driveData, $message) {
    if (!$pdo || (int)$projectId <= 0 || !is_array($driveData)) return false;
    $result = array(
        'ok' => true,
        'status' => 'ready',
        'message' => (string)$message,
        'drive' => $driveData
    );
    return cpms_drive_save_project_structure_result($pdo, (int)$projectId, $result);
}}

if (!function_exists('cpms_quality_drive_load_project')) {
function cpms_quality_drive_load_project($pdo, $projectId) {
    if (!$pdo || (int)$projectId <= 0) return false;
    try {
        $st = $pdo->prepare("SELECT * FROM cpms_projects WHERE id = :id LIMIT 1");
        $st->bindValue(':id', (int)$projectId, PDO::PARAM_INT);
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : false;
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_quality_drive_ensure_project_data')) {
function cpms_quality_drive_ensure_project_data($pdo, $project, $userContext) {
    if (!$pdo || !is_array($project) || !isset($project['id'])) {
        return array('ok' => false, 'project' => $project, 'drive' => array(), 'message' => 'Project row is not available.', 'http_code' => 0);
    }
    $projectId = (int)$project['id'];
    $projectName = isset($project['name']) ? (string)$project['name'] : '';
    $driveData = cpms_quality_drive_project_drive_data($project);
    $projectFolderId = isset($driveData['project_folder_id']) ? trim((string)$driveData['project_folder_id']) : '';
    if ($projectFolderId === '') {
        $sync = cpms_drive_sync_project_after_create($pdo, $projectId, $projectName, $userContext, 'quality_upload');
        $driveResult = (isset($sync['drive_result']) && is_array($sync['drive_result'])) ? $sync['drive_result'] : array();
        if (empty($driveResult['ok'])) {
            $message = isset($driveResult['message']) ? (string)$driveResult['message'] : cpms_quality_drive_label('project_sync_required');
            cpms_drive_log_upload_failure(array(
                'user' => $userContext,
                'section' => 'quality',
                'project_id' => $projectId,
                'is_common_file' => '0',
                'message' => $message,
                'http_status' => isset($driveResult['http_code']) ? (int)$driveResult['http_code'] : 0,
                'google_response_excerpt' => isset($driveResult['google_response_excerpt']) ? $driveResult['google_response_excerpt'] : ''
            ));
            return array('ok' => false, 'project' => $project, 'drive' => $driveData, 'message' => $message, 'http_code' => isset($driveResult['http_code']) ? (int)$driveResult['http_code'] : 0);
        }
        $fresh = cpms_quality_drive_load_project($pdo, $projectId);
        if (is_array($fresh)) $project = $fresh;
        $driveData = isset($driveResult['drive']) && is_array($driveResult['drive']) ? $driveResult['drive'] : cpms_quality_drive_project_drive_data($project);
        $projectFolderId = isset($driveData['project_folder_id']) ? trim((string)$driveData['project_folder_id']) : '';
    }
    if ($projectFolderId === '') {
        return array('ok' => false, 'project' => $project, 'drive' => $driveData, 'message' => cpms_quality_drive_label('project_sync_required'), 'http_code' => 0);
    }
    if (!isset($driveData['folders']) || !is_array($driveData['folders'])) $driveData['folders'] = array();
    $driveData['project_folder_id'] = $projectFolderId;
    $driveData['folders']['project'] = $projectFolderId;
    return array('ok' => true, 'project' => $project, 'drive' => $driveData, 'message' => 'Project Drive data is ready.', 'http_code' => 0);
}}

if (!function_exists('cpms_quality_drive_parse_month')) {
function cpms_quality_drive_parse_month($value, $fallbackDate) {
    $raw = trim((string)$value);
    $fallbackDate = trim((string)$fallbackDate);
    $year = '';
    $month = '';
    $usedFallback = false;
    $message = '';
    if ($raw !== '') {
        if (preg_match('/(\d{4})\D{0,10}(\d{1,2})/u', $raw, $m)) {
            $year = (string)$m[1];
            $month = sprintf('%02d', (int)$m[2]);
        } else if (preg_match('/^\d{1,2}$/', $raw)) {
            $ts0 = $fallbackDate !== '' ? strtotime($fallbackDate) : false;
            if ($ts0 === false) $ts0 = time();
            $year = date('Y', $ts0);
            $month = sprintf('%02d', (int)$raw);
        }
    }
    if ($year === '' || $month === '' || (int)$month < 1 || (int)$month > 12) {
        $ts = $fallbackDate !== '' ? strtotime($fallbackDate) : false;
        if ($ts === false) $ts = time();
        $year = date('Y', $ts);
        $month = date('m', $ts);
        $usedFallback = ($raw !== '');
        if ($usedFallback) $message = cpms_quality_drive_label('month_review') . ': ' . $raw;
    }
    return array(
        'year' => $year,
        'month' => $month,
        'ym' => $year . '-' . $month,
        'used_fallback' => $usedFallback,
        'message' => $message,
        'raw' => $raw
    );
}}

if (!function_exists('cpms_quality_drive_ensure_folder')) {
function cpms_quality_drive_ensure_folder($name, $parentId, $context) {
    $folder = cpms_drive_find_or_create_folder($name, $parentId, $context);
    if (empty($folder['ok']) || !isset($folder['file']) || !is_array($folder['file']) || !isset($folder['file']['id'])) {
        return array(
            'ok' => false,
            'folder_id' => '',
            'message' => isset($folder['message']) ? (string)$folder['message'] : 'Drive folder prepare failed.',
            'http_code' => isset($folder['http_code']) ? (int)$folder['http_code'] : 0
        );
    }
    return array(
        'ok' => true,
        'folder_id' => (string)$folder['file']['id'],
        'message' => isset($folder['message']) ? (string)$folder['message'] : '',
        'http_code' => isset($folder['http_code']) ? (int)$folder['http_code'] : 0
    );
}}

if (!function_exists('cpms_quality_drive_base_context')) {
function cpms_quality_drive_base_context($userContext, $projectId, $isCommon, $docInfo, $monthInfo, $originalName) {
    return array(
        'user' => $userContext,
        'section' => 'quality',
        'project_id' => (int)$projectId > 0 ? (int)$projectId : '',
        'is_common_file' => $isCommon ? '1' : '0',
        'document_type' => isset($docInfo['document_label']) ? $docInfo['document_label'] : '',
        'document_year' => isset($monthInfo['year']) ? (string)$monthInfo['year'] : '',
        'document_month' => isset($monthInfo['month']) ? (string)$monthInfo['month'] : '',
        'original_name' => (string)$originalName
    );
}}

if (!function_exists('cpms_quality_drive_ensure_project_target_folder')) {
function cpms_quality_drive_ensure_project_target_folder($pdo, $projectId, $documentType, $monthValue, $fallbackDate, $userContext, $originalName) {
    $project = cpms_quality_drive_load_project($pdo, $projectId);
    if (!is_array($project)) {
        return array('ok' => false, 'message' => 'Project row was not found.', 'http_code' => 0);
    }
    $ready = cpms_quality_drive_ensure_project_data($pdo, $project, $userContext);
    if (empty($ready['ok'])) return $ready;
    $project = isset($ready['project']) && is_array($ready['project']) ? $ready['project'] : $project;
    $driveData = isset($ready['drive']) && is_array($ready['drive']) ? $ready['drive'] : array();
    if (!isset($driveData['folders']) || !is_array($driveData['folders'])) $driveData['folders'] = array();
    $projectFolderId = isset($driveData['project_folder_id']) ? trim((string)$driveData['project_folder_id']) : '';
    $projectName = isset($project['name']) ? trim((string)$project['name']) : '';
    $docInfo = cpms_quality_drive_document_info($documentType, $originalName);
    $monthInfo = cpms_quality_drive_parse_month($monthValue, $fallbackDate);
    $baseContext = cpms_quality_drive_base_context($userContext, $projectId, false, $docInfo, $monthInfo, $originalName);
    if (!empty($monthInfo['used_fallback'])) cpms_drive_log_upload_failure(array_merge($baseContext, array('message' => $monthInfo['message'])));
    if ($projectFolderId === '') {
        return array('ok' => false, 'message' => cpms_quality_drive_label('project_sync_required'), 'http_code' => 0);
    }

    $saveNeeded = false;
    $qualityFolderId = cpms_quality_drive_folder_id_from_data($driveData, 'quality');
    if ($qualityFolderId === '') {
        $ctx = $baseContext;
        $ctx['target_folder_id'] = $projectFolderId;
        $ctx['original_name'] = cpms_quality_drive_label('root');
        $folder = cpms_quality_drive_ensure_folder(cpms_quality_drive_label('root'), $projectFolderId, $ctx);
        if (empty($folder['ok'])) return $folder;
        $qualityFolderId = (string)$folder['folder_id'];
        $driveData['folders']['quality'] = $qualityFolderId;
        $saveNeeded = true;
    }

    $folderKey = isset($docInfo['folder_key']) ? (string)$docInfo['folder_key'] : 'quality_etc';
    $folderLabel = isset($docInfo['folder_label']) ? (string)$docInfo['folder_label'] : cpms_quality_drive_label('etc');
    $documentFolderId = cpms_quality_drive_folder_id_from_data($driveData, $folderKey);
    if ($documentFolderId === '') {
        $ctx = $baseContext;
        $ctx['target_folder_id'] = $qualityFolderId;
        $ctx['original_name'] = $folderLabel;
        $folder = cpms_quality_drive_ensure_folder($folderLabel, $qualityFolderId, $ctx);
        if (empty($folder['ok'])) return $folder;
        $documentFolderId = (string)$folder['folder_id'];
        $driveData['folders'][$folderKey] = $documentFolderId;
        $saveNeeded = true;
    }

    $yearContext = $baseContext;
    $yearContext['target_folder_id'] = $documentFolderId;
    $yearContext['original_name'] = (string)$monthInfo['year'];
    $yearFolder = cpms_quality_drive_ensure_folder((string)$monthInfo['year'], $documentFolderId, $yearContext);
    if (empty($yearFolder['ok'])) return $yearFolder;

    $monthContext = $baseContext;
    $monthContext['target_folder_id'] = (string)$yearFolder['folder_id'];
    $monthContext['original_name'] = (string)$monthInfo['month'];
    $monthFolder = cpms_quality_drive_ensure_folder((string)$monthInfo['month'], (string)$yearFolder['folder_id'], $monthContext);
    if (empty($monthFolder['ok'])) return $monthFolder;

    if ($saveNeeded) {
        cpms_quality_drive_save_project_data($pdo, (int)$projectId, $driveData, 'Quality Drive folders prepared.');
    }

    return array(
        'ok' => true,
        'is_common_file' => false,
        'project_name' => $projectName,
        'drive' => $driveData,
        'quality_folder_id' => $qualityFolderId,
        'document_folder_id' => $documentFolderId,
        'year_folder_id' => (string)$yearFolder['folder_id'],
        'month_folder_id' => (string)$monthFolder['folder_id'],
        'folder_id' => (string)$monthFolder['folder_id'],
        'document_info' => $docInfo,
        'month_info' => $monthInfo,
        'message' => 'Quality monthly target folder is ready.',
        'http_code' => isset($monthFolder['http_code']) ? (int)$monthFolder['http_code'] : 0
    );
}}

if (!function_exists('cpms_quality_drive_ensure_common_target_folder')) {
function cpms_quality_drive_ensure_common_target_folder($documentType, $monthValue, $fallbackDate, $userContext, $originalName) {
    $commonRootId = cpms_drive_folder_id('common_documents');
    $docInfo = cpms_quality_drive_document_info($documentType, $originalName);
    $monthInfo = cpms_quality_drive_parse_month($monthValue, $fallbackDate);
    $baseContext = cpms_quality_drive_base_context($userContext, 0, true, $docInfo, $monthInfo, $originalName);
    if (!empty($monthInfo['used_fallback'])) cpms_drive_log_upload_failure(array_merge($baseContext, array('message' => $monthInfo['message'])));
    if ($commonRootId === '') {
        $message = 'Common documents Drive folder ID is empty.';
        cpms_drive_log_upload_failure(array_merge($baseContext, array('message' => $message)));
        return array('ok' => false, 'message' => $message, 'http_code' => 0);
    }

    $ctx = $baseContext;
    $ctx['target_folder_id'] = $commonRootId;
    $ctx['original_name'] = cpms_quality_drive_label('common_root');
    $qualityRoot = cpms_quality_drive_ensure_folder(cpms_quality_drive_label('common_root'), $commonRootId, $ctx);
    if (empty($qualityRoot['ok'])) return $qualityRoot;

    $folderLabel = isset($docInfo['folder_label']) ? (string)$docInfo['folder_label'] : cpms_quality_drive_label('etc');
    $ctx['target_folder_id'] = (string)$qualityRoot['folder_id'];
    $ctx['original_name'] = $folderLabel;
    $documentFolder = cpms_quality_drive_ensure_folder($folderLabel, (string)$qualityRoot['folder_id'], $ctx);
    if (empty($documentFolder['ok'])) return $documentFolder;

    $yearContext = $baseContext;
    $yearContext['target_folder_id'] = (string)$documentFolder['folder_id'];
    $yearContext['original_name'] = (string)$monthInfo['year'];
    $yearFolder = cpms_quality_drive_ensure_folder((string)$monthInfo['year'], (string)$documentFolder['folder_id'], $yearContext);
    if (empty($yearFolder['ok'])) return $yearFolder;

    $monthContext = $baseContext;
    $monthContext['target_folder_id'] = (string)$yearFolder['folder_id'];
    $monthContext['original_name'] = (string)$monthInfo['month'];
    $monthFolder = cpms_quality_drive_ensure_folder((string)$monthInfo['month'], (string)$yearFolder['folder_id'], $monthContext);
    if (empty($monthFolder['ok'])) return $monthFolder;

    return array(
        'ok' => true,
        'is_common_file' => true,
        'project_name' => cpms_quality_drive_label('common'),
        'common_documents_folder_id' => $commonRootId,
        'quality_folder_id' => (string)$qualityRoot['folder_id'],
        'document_folder_id' => (string)$documentFolder['folder_id'],
        'year_folder_id' => (string)$yearFolder['folder_id'],
        'month_folder_id' => (string)$monthFolder['folder_id'],
        'folder_id' => (string)$monthFolder['folder_id'],
        'document_info' => $docInfo,
        'month_info' => $monthInfo,
        'message' => 'Common quality monthly target folder is ready.',
        'http_code' => isset($monthFolder['http_code']) ? (int)$monthFolder['http_code'] : 0
    );
}}

if (!function_exists('cpms_quality_drive_ensure_target_folder')) {
function cpms_quality_drive_ensure_target_folder($pdo, $projectId, $documentType, $monthValue, $fallbackDate, $userContext, $originalName) {
    if ((int)$projectId > 0) {
        return cpms_quality_drive_ensure_project_target_folder($pdo, (int)$projectId, $documentType, $monthValue, $fallbackDate, $userContext, $originalName);
    }
    return cpms_quality_drive_ensure_common_target_folder($documentType, $monthValue, $fallbackDate, $userContext, $originalName);
}}

if (!function_exists('cpms_quality_drive_user_name')) {
function cpms_quality_drive_user_name($userContext) {
    if (is_array($userContext)) {
        if (isset($userContext['name']) && trim((string)$userContext['name']) !== '') return trim((string)$userContext['name']);
        if (isset($userContext['email']) && trim((string)$userContext['email']) !== '') return trim((string)$userContext['email']);
    }
    $label = trim((string)$userContext);
    return $label !== '' ? $label : '-';
}}

if (!function_exists('cpms_quality_drive_build_file_name')) {
function cpms_quality_drive_build_file_name($date, $documentLabel, $placeName, $userName, $originalName) {
    $date = trim((string)$date);
    $ts = $date !== '' ? strtotime($date) : false;
    if ($ts === false) $ts = time();
    $prefix = date('Y-m-d_His', $ts);
    if (date('His', $ts) === '000000') $prefix = date('Y-m-d', $ts) . '_' . date('His');
    $parts = array(
        $prefix,
        cpms_quality_drive_label('section'),
        trim((string)$documentLabel),
        trim((string)$placeName),
        trim((string)$userName),
        trim((string)$originalName)
    );
    return cpms_drive_sanitize_file_name(implode('_', $parts), 180);
}}

if (!function_exists('cpms_quality_drive_failed_record')) {
function cpms_quality_drive_failed_record($projectId, $isCommon, $originalName, $localPath, $mimeType, $size, $docInfo, $monthInfo, $message) {
    $docInfo = is_array($docInfo) ? $docInfo : array();
    $monthInfo = is_array($monthInfo) ? $monthInfo : array();
    return array(
        'original_name' => (string)$originalName,
        'stored_name' => '',
        'drive_file_id' => '',
        'drive_folder_id' => '',
        'drive_web_view_link' => '',
        'drive_web_content_link' => '',
        'mime_type' => (string)$mimeType,
        'size' => (string)$size,
        'section' => 'quality',
        'document_type' => isset($docInfo['document_type']) ? (string)$docInfo['document_type'] : '',
        'document_year' => isset($monthInfo['year']) ? (string)$monthInfo['year'] : '',
        'document_month' => isset($monthInfo['month']) ? (string)$monthInfo['month'] : '',
        'drive_year_folder_id' => '',
        'drive_type_folder_id' => '',
        'drive_month_folder_id' => '',
        'project_id' => (string)(int)$projectId,
        'is_common_file' => $isCommon ? '1' : '0',
        'storage_type' => 'local',
        'local_backup_path' => (string)$localPath,
        'upload_status' => 'failed',
        'drive_upload_error' => cpms_drive_redact_text((string)$message),
        'uploaded_at' => date('Y-m-d H:i:s')
    );
}}

if (!function_exists('cpms_quality_drive_upload_local_file')) {
function cpms_quality_drive_upload_local_file($pdo, $projectId, $localPath, $originalName, $documentType, $monthValue, $fallbackDate, $extra, $userContext) {
    if (!is_array($extra)) $extra = array();
    $projectId = (int)$projectId;
    $localPath = trim((string)$localPath);
    $originalName = trim((string)$originalName);
    $documentType = trim((string)$documentType);
    $fallbackDate = trim((string)$fallbackDate);
    if ($fallbackDate === '') $fallbackDate = date('Y-m-d H:i:s');
    $mimeType = is_file($localPath) ? cpms_drive_detect_mime_type($localPath) : '';
    $size = is_file($localPath) ? (string)@filesize($localPath) : '';
    $docInfo = cpms_quality_drive_document_info($documentType, $originalName);
    $monthInfo = cpms_quality_drive_parse_month($monthValue, $fallbackDate);
    $isCommon = $projectId <= 0;
    $context = cpms_quality_drive_base_context($userContext, $projectId, $isCommon, $docInfo, $monthInfo, $originalName);
    $context['uploaded_by'] = $userContext;
    $context['mime_type'] = $mimeType;
    $context['size'] = $size;
    $context['local_backup_path'] = $localPath;
    if (!empty($monthInfo['used_fallback'])) cpms_drive_log_upload_failure(array_merge($context, array('message' => $monthInfo['message'])));
    if ($localPath === '' || !is_file($localPath)) {
        $message = 'Quality Drive upload skipped because local file is empty.';
        cpms_drive_log_upload_failure(array_merge($context, array('message' => $message)));
        return array('ok' => false, 'record' => cpms_quality_drive_failed_record($projectId, $isCommon, $originalName, $localPath, $mimeType, $size, $docInfo, $monthInfo, $message), 'message' => $message, 'http_code' => 0);
    }

    $target = cpms_quality_drive_ensure_target_folder($pdo, $projectId, $documentType, $monthValue, $fallbackDate, $userContext, $originalName);
    if (empty($target['ok'])) {
        $message = isset($target['message']) ? (string)$target['message'] : 'Quality Drive target folder prepare failed.';
        cpms_drive_log_upload_failure(array_merge($context, array(
            'message' => $message,
            'http_status' => isset($target['http_code']) ? (int)$target['http_code'] : 0
        )));
        return array('ok' => false, 'record' => cpms_quality_drive_failed_record($projectId, $isCommon, $originalName, $localPath, $mimeType, $size, $docInfo, $monthInfo, $message), 'message' => $message, 'http_code' => isset($target['http_code']) ? (int)$target['http_code'] : 0);
    }

    $isCommon = !empty($target['is_common_file']);
    $docInfo = isset($target['document_info']) && is_array($target['document_info']) ? $target['document_info'] : $docInfo;
    $monthInfo = isset($target['month_info']) && is_array($target['month_info']) ? $target['month_info'] : $monthInfo;
    $placeName = isset($target['project_name']) ? (string)$target['project_name'] : '';
    if (isset($extra['project_name']) && trim((string)$extra['project_name']) !== '') $placeName = (string)$extra['project_name'];
    if ($placeName === '') $placeName = $isCommon ? cpms_quality_drive_label('common') : '-';
    $date = isset($extra['date']) && trim((string)$extra['date']) !== '' ? trim((string)$extra['date']) : ($monthInfo['year'] . '-' . $monthInfo['month'] . '-' . date('d'));
    $driveName = cpms_quality_drive_build_file_name($date, isset($docInfo['document_label']) ? $docInfo['document_label'] : $documentType, $placeName, cpms_quality_drive_user_name($userContext), $originalName);

    $context['target_folder_id'] = (string)$target['folder_id'];
    $context['drive_folder_id'] = (string)$target['folder_id'];
    $context['is_common_file'] = $isCommon ? '1' : '0';
    $context['document_type'] = isset($docInfo['document_label']) ? $docInfo['document_label'] : $documentType;
    $context['document_year'] = (string)$monthInfo['year'];
    $context['document_month'] = (string)$monthInfo['month'];
    $context['drive_year_folder_id'] = isset($target['year_folder_id']) ? (string)$target['year_folder_id'] : '';
    $context['drive_type_folder_id'] = isset($target['document_folder_id']) ? (string)$target['document_folder_id'] : '';
    $context['drive_month_folder_id'] = isset($target['month_folder_id']) ? (string)$target['month_folder_id'] : '';
    $upload = cpms_drive_upload_file($localPath, $driveName, (string)$target['folder_id'], $mimeType, $context);
    if (empty($upload['ok']) || !isset($upload['file']) || !is_array($upload['file'])) {
        $message = isset($upload['message']) ? (string)$upload['message'] : 'Quality file Drive upload failed.';
        return array('ok' => false, 'record' => cpms_quality_drive_failed_record($projectId, $isCommon, $originalName, $localPath, $mimeType, $size, $docInfo, $monthInfo, $message), 'message' => $message, 'http_code' => isset($upload['http_code']) ? (int)$upload['http_code'] : 0);
    }

    $context['uploaded_at'] = date('Y-m-d H:i:s');
    $record = cpms_drive_build_file_record($upload['file'], $context);
    $record['document_type'] = isset($docInfo['document_type']) ? (string)$docInfo['document_type'] : (string)$documentType;
    $record['document_year'] = (string)$monthInfo['year'];
    $record['document_month'] = (string)$monthInfo['month'];
    $record['drive_year_folder_id'] = isset($target['year_folder_id']) ? (string)$target['year_folder_id'] : '';
    $record['drive_type_folder_id'] = isset($target['document_folder_id']) ? (string)$target['document_folder_id'] : '';
    $record['drive_month_folder_id'] = isset($target['month_folder_id']) ? (string)$target['month_folder_id'] : '';
    $record['project_id'] = (string)$projectId;
    $record['is_common_file'] = $isCommon ? '1' : '0';
    $record['upload_status'] = 'uploaded';
    $record['drive_upload_error'] = '';
    $record['local_backup_path'] = $localPath;
    return array('ok' => true, 'record' => $record, 'message' => isset($upload['message']) ? (string)$upload['message'] : '', 'http_code' => isset($upload['http_code']) ? (int)$upload['http_code'] : 0);
}}

if (!function_exists('cpms_quality_drive_record_values')) {
function cpms_quality_drive_record_values($record, $userId) {
    $record = is_array($record) ? $record : array();
    return array(
        'storage_type' => isset($record['storage_type']) ? (string)$record['storage_type'] : '',
        'drive_name' => isset($record['stored_name']) ? (string)$record['stored_name'] : '',
        'drive_file_id' => isset($record['drive_file_id']) ? (string)$record['drive_file_id'] : '',
        'drive_folder_id' => isset($record['drive_folder_id']) ? (string)$record['drive_folder_id'] : '',
        'drive_web_view_link' => isset($record['drive_web_view_link']) ? (string)$record['drive_web_view_link'] : '',
        'drive_web_content_link' => isset($record['drive_web_content_link']) ? (string)$record['drive_web_content_link'] : '',
        'mime_type' => isset($record['mime_type']) ? (string)$record['mime_type'] : '',
        'file_size' => isset($record['size']) ? (string)$record['size'] : '',
        'section' => isset($record['section']) ? (string)$record['section'] : 'quality',
        'document_type' => isset($record['document_type']) ? (string)$record['document_type'] : '',
        'document_year' => isset($record['document_year']) ? (string)$record['document_year'] : '',
        'document_month' => isset($record['document_month']) ? (string)$record['document_month'] : '',
        'drive_year_folder_id' => isset($record['drive_year_folder_id']) ? (string)$record['drive_year_folder_id'] : '',
        'drive_type_folder_id' => isset($record['drive_type_folder_id']) ? (string)$record['drive_type_folder_id'] : '',
        'drive_month_folder_id' => isset($record['drive_month_folder_id']) ? (string)$record['drive_month_folder_id'] : '',
        'project_id' => isset($record['project_id']) ? (string)$record['project_id'] : '',
        'is_common_file' => isset($record['is_common_file']) ? (string)$record['is_common_file'] : '',
        'upload_status' => isset($record['upload_status']) ? (string)$record['upload_status'] : '',
        'drive_upload_error' => isset($record['drive_upload_error']) ? cpms_drive_redact_text((string)$record['drive_upload_error']) : '',
        'uploaded_by' => (int)$userId,
        'uploaded_at' => isset($record['uploaded_at']) ? (string)$record['uploaded_at'] : date('Y-m-d H:i:s')
    );
}}

if (!function_exists('cpms_quality_drive_flash_message')) {
function cpms_quality_drive_flash_message($baseMessage, $uploadResult) {
    if (is_array($uploadResult) && !empty($uploadResult['ok'])) return $baseMessage;
    return $baseMessage . ' ' . cpms_quality_drive_label('upload_failed_notice');
}}

if (!function_exists('cpms_quality_drive_is_drive_file')) {
function cpms_quality_drive_is_drive_file($file) {
    return is_array($file) && isset($file['storage_type']) && (string)$file['storage_type'] === 'google_drive';
}}

if (!function_exists('cpms_quality_drive_link')) {
function cpms_quality_drive_link($file, $download) {
    if (!is_array($file)) return '';
    $downloadLink = isset($file['drive_web_content_link']) ? trim((string)$file['drive_web_content_link']) : '';
    $viewLink = isset($file['drive_web_view_link']) ? trim((string)$file['drive_web_view_link']) : '';
    if ($download && $downloadLink !== '') return $downloadLink;
    if ($viewLink !== '') return $viewLink;
    if ($downloadLink !== '') return $downloadLink;
    return '';
}}

if (!function_exists('cpms_quality_drive_delete_uploaded_record')) {
function cpms_quality_drive_delete_uploaded_record($record, $context) {
    if (!is_array($record) || !isset($record['drive_file_id']) || trim((string)$record['drive_file_id']) === '') return false;
    if (!is_array($context)) $context = array();
    if (!isset($context['section'])) $context['section'] = 'quality';
    if (!isset($context['document_type']) && isset($record['document_type'])) $context['document_type'] = $record['document_type'];
    if (!isset($context['document_year']) && isset($record['document_year'])) $context['document_year'] = $record['document_year'];
    if (!isset($context['document_month']) && isset($record['document_month'])) $context['document_month'] = $record['document_month'];
    if (!isset($context['target_folder_id']) && isset($record['drive_folder_id'])) $context['target_folder_id'] = $record['drive_folder_id'];
    return cpms_drive_delete_file((string)$record['drive_file_id'], $context);
}}

if (!function_exists('cpms_quality_drive_select_admin_project')) {
function cpms_quality_drive_select_admin_project($pdo, $projectId) {
    if (!$pdo) return false;
    try {
        if ((int)$projectId > 0) {
            $st = $pdo->prepare("SELECT * FROM cpms_projects WHERE id = :id LIMIT 1");
            $st->bindValue(':id', (int)$projectId, PDO::PARAM_INT);
            $st->execute();
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) return $row;
        }
        $st2 = $pdo->query("SELECT * FROM cpms_projects WHERE name NOT LIKE '(가제)%' ORDER BY id ASC LIMIT 1");
        $row2 = $st2 ? $st2->fetch(PDO::FETCH_ASSOC) : false;
        return is_array($row2) ? $row2 : false;
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_quality_drive_check_row')) {
function cpms_quality_drive_check_row($ok, $httpCode, $message, $id) {
    return array(
        'ok' => $ok ? true : false,
        'http_code' => (int)$httpCode,
        'message' => (string)$message,
        'id' => (string)$id
    );
}}

if (!function_exists('cpms_quality_drive_run_admin_check')) {
function cpms_quality_drive_run_admin_check($pdo, $userContext, $projectId) {
    $result = array(
        'project' => array('ok' => false, 'message' => ''),
        'quality_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'material_approval_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'inspection_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'test_report_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'cqi_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'submission_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'year_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'month_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'upload' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'delete' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'common_quality_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'common_submission_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'common_year_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'common_month_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'common_upload' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'common_delete' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'cleanup' => array('ok' => false, 'message' => ''),
        'test_file' => array(),
        'common_test_file' => array()
    );
    $project = cpms_quality_drive_select_admin_project($pdo, $projectId);
    if (!is_array($project)) {
        $result['project']['message'] = 'No project is available for quality Drive check.';
        return $result;
    }
    $selectedProjectId = isset($project['id']) ? (int)$project['id'] : 0;
    $result['project'] = array(
        'ok' => true,
        'id' => $selectedProjectId,
        'name' => isset($project['name']) ? (string)$project['name'] : '',
        'message' => 'Project selected.'
    );

    $target = cpms_quality_drive_ensure_target_folder($pdo, $selectedProjectId, 'material_approval', date('Y-m'), date('Y-m-d'), $userContext, 'quality_drive_check.txt');
    $result['quality_folder'] = cpms_quality_drive_check_row(!empty($target['quality_folder_id']), isset($target['http_code']) ? $target['http_code'] : 0, !empty($target['quality_folder_id']) ? '05_quality folder is ready.' : (isset($target['message']) ? $target['message'] : 'Folder check failed.'), isset($target['quality_folder_id']) ? $target['quality_folder_id'] : '');
    $result['material_approval_folder'] = cpms_quality_drive_check_row(!empty($target['document_folder_id']), isset($target['http_code']) ? $target['http_code'] : 0, !empty($target['document_folder_id']) ? 'Material approval folder is ready.' : (isset($target['message']) ? $target['message'] : 'Folder check failed.'), isset($target['document_folder_id']) ? $target['document_folder_id'] : '');
    $result['year_folder'] = cpms_quality_drive_check_row(!empty($target['year_folder_id']), isset($target['http_code']) ? $target['http_code'] : 0, !empty($target['year_folder_id']) ? 'Year folder is ready.' : (isset($target['message']) ? $target['message'] : 'Folder check failed.'), isset($target['year_folder_id']) ? $target['year_folder_id'] : '');
    $result['month_folder'] = cpms_quality_drive_check_row(!empty($target['month_folder_id']), isset($target['http_code']) ? $target['http_code'] : 0, !empty($target['month_folder_id']) ? 'Month folder is ready.' : (isset($target['message']) ? $target['message'] : 'Folder check failed.'), isset($target['month_folder_id']) ? $target['month_folder_id'] : '');

    $checkTypes = array('inspection', 'test_report', 'cqi', 'submission');
    foreach ($checkTypes as $checkType) {
        $subTarget = cpms_quality_drive_ensure_target_folder($pdo, $selectedProjectId, $checkType, date('Y-m'), date('Y-m-d'), $userContext, 'quality_drive_check.txt');
        $key = $checkType . '_folder';
        $result[$key] = cpms_quality_drive_check_row(!empty($subTarget['document_folder_id']), isset($subTarget['http_code']) ? $subTarget['http_code'] : 0, !empty($subTarget['document_folder_id']) ? $checkType . ' folder is ready.' : (isset($subTarget['message']) ? $subTarget['message'] : 'Folder check failed.'), isset($subTarget['document_folder_id']) ? $subTarget['document_folder_id'] : '');
    }

    $tmpPaths = array();
    if (!empty($target['ok'])) {
        $tmpDir = cpms_drive_storage_root() . '/tmp/quality_drive_check';
        if (cpms_drive_ensure_dir($tmpDir)) {
            $tmpPath = @tempnam($tmpDir, 'quality_drive_');
            if ($tmpPath !== false) $tmpPaths[] = $tmpPath;
            if ($tmpPath !== false && @file_put_contents($tmpPath, "CPMS quality Drive check\n" . date('Y-m-d H:i:s') . "\n") !== false) {
                $fileName = 'CPMS_Quality_Check_' . date('Ymd_His') . '.txt';
                $context = array(
                    'user' => $userContext,
                    'section' => 'admin_drive_check_quality',
                    'project_id' => $selectedProjectId,
                    'is_common_file' => '0',
                    'document_type' => cpms_quality_drive_label('material_approval'),
                    'document_year' => date('Y'),
                    'document_month' => date('m'),
                    'original_name' => $fileName,
                    'target_folder_id' => (string)$target['folder_id']
                );
                $upload = cpms_drive_upload_file($tmpPath, $fileName, (string)$target['folder_id'], 'text/plain', $context);
                $result['upload'] = array('ok' => !empty($upload['ok']), 'http_code' => isset($upload['http_code']) ? (int)$upload['http_code'] : 0, 'message' => isset($upload['message']) ? (string)$upload['message'] : '');
                if (!empty($upload['ok']) && isset($upload['file']) && is_array($upload['file'])) {
                    $result['test_file'] = array(
                        'id' => isset($upload['file']['id']) ? (string)$upload['file']['id'] : '',
                        'name' => isset($upload['file']['name']) ? (string)$upload['file']['name'] : '',
                        'webViewLink' => isset($upload['file']['webViewLink']) ? (string)$upload['file']['webViewLink'] : ''
                    );
                    $delete = cpms_drive_delete_file($result['test_file']['id'], $context);
                    $result['delete'] = array('ok' => !empty($delete['ok']), 'http_code' => isset($delete['http_code']) ? (int)$delete['http_code'] : 0, 'message' => isset($delete['message']) ? (string)$delete['message'] : '');
                }
            }
        }
    }

    $commonTarget = cpms_quality_drive_ensure_target_folder($pdo, 0, 'submission', date('Y-m'), date('Y-m-d'), $userContext, 'quality_common_drive_check.txt');
    $result['common_quality_folder'] = cpms_quality_drive_check_row(!empty($commonTarget['quality_folder_id']), isset($commonTarget['http_code']) ? $commonTarget['http_code'] : 0, !empty($commonTarget['quality_folder_id']) ? 'Common quality folder is ready.' : (isset($commonTarget['message']) ? $commonTarget['message'] : 'Folder check failed.'), isset($commonTarget['quality_folder_id']) ? $commonTarget['quality_folder_id'] : '');
    $result['common_submission_folder'] = cpms_quality_drive_check_row(!empty($commonTarget['document_folder_id']), isset($commonTarget['http_code']) ? $commonTarget['http_code'] : 0, !empty($commonTarget['document_folder_id']) ? 'Common submission folder is ready.' : (isset($commonTarget['message']) ? $commonTarget['message'] : 'Folder check failed.'), isset($commonTarget['document_folder_id']) ? $commonTarget['document_folder_id'] : '');
    $result['common_year_folder'] = cpms_quality_drive_check_row(!empty($commonTarget['year_folder_id']), isset($commonTarget['http_code']) ? $commonTarget['http_code'] : 0, !empty($commonTarget['year_folder_id']) ? 'Common year folder is ready.' : (isset($commonTarget['message']) ? $commonTarget['message'] : 'Folder check failed.'), isset($commonTarget['year_folder_id']) ? $commonTarget['year_folder_id'] : '');
    $result['common_month_folder'] = cpms_quality_drive_check_row(!empty($commonTarget['month_folder_id']), isset($commonTarget['http_code']) ? $commonTarget['http_code'] : 0, !empty($commonTarget['month_folder_id']) ? 'Common month folder is ready.' : (isset($commonTarget['message']) ? $commonTarget['message'] : 'Folder check failed.'), isset($commonTarget['month_folder_id']) ? $commonTarget['month_folder_id'] : '');
    if (!empty($commonTarget['ok'])) {
        $tmpDir = cpms_drive_storage_root() . '/tmp/quality_drive_check';
        if (cpms_drive_ensure_dir($tmpDir)) {
            $tmpPath2 = @tempnam($tmpDir, 'quality_common_');
            if ($tmpPath2 !== false) $tmpPaths[] = $tmpPath2;
            if ($tmpPath2 !== false && @file_put_contents($tmpPath2, "CPMS common quality Drive check\n" . date('Y-m-d H:i:s') . "\n") !== false) {
                $fileName2 = 'CPMS_Common_Quality_Check_' . date('Ymd_His') . '.txt';
                $context2 = array(
                    'user' => $userContext,
                    'section' => 'admin_drive_check_quality_common',
                    'project_id' => '',
                    'is_common_file' => '1',
                    'document_type' => cpms_quality_drive_label('submission'),
                    'document_year' => date('Y'),
                    'document_month' => date('m'),
                    'original_name' => $fileName2,
                    'target_folder_id' => (string)$commonTarget['folder_id']
                );
                $upload2 = cpms_drive_upload_file($tmpPath2, $fileName2, (string)$commonTarget['folder_id'], 'text/plain', $context2);
                $result['common_upload'] = array('ok' => !empty($upload2['ok']), 'http_code' => isset($upload2['http_code']) ? (int)$upload2['http_code'] : 0, 'message' => isset($upload2['message']) ? (string)$upload2['message'] : '');
                if (!empty($upload2['ok']) && isset($upload2['file']) && is_array($upload2['file'])) {
                    $result['common_test_file'] = array(
                        'id' => isset($upload2['file']['id']) ? (string)$upload2['file']['id'] : '',
                        'name' => isset($upload2['file']['name']) ? (string)$upload2['file']['name'] : '',
                        'webViewLink' => isset($upload2['file']['webViewLink']) ? (string)$upload2['file']['webViewLink'] : ''
                    );
                    $delete2 = cpms_drive_delete_file($result['common_test_file']['id'], $context2);
                    $result['common_delete'] = array('ok' => !empty($delete2['ok']), 'http_code' => isset($delete2['http_code']) ? (int)$delete2['http_code'] : 0, 'message' => isset($delete2['message']) ? (string)$delete2['message'] : '');
                }
            }
        }
    }
    $cleaned = true;
    foreach ($tmpPaths as $tmp) {
        if (is_file($tmp) && !@unlink($tmp)) $cleaned = false;
    }
    $result['cleanup'] = array('ok' => $cleaned, 'message' => 'Temporary quality check file cleanup attempted.');
    return $result;
}}
