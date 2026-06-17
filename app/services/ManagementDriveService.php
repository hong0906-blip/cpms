<?php
/**
 * Management-section Google Drive helpers.
 * PHP 5.6 compatible. Reuses GoogleDriveHelper; does not implement Google auth.
 */

require_once __DIR__ . '/GoogleDriveHelper.php';

if (!function_exists('cpms_management_drive_h')) {
function cpms_management_drive_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}}

if (!function_exists('cpms_management_drive_label')) {
function cpms_management_drive_label($key) {
    $labels = array(
        'management' => '%EA%B4%80%EB%A6%AC',
        'management_root' => '%30%32%5F%EA%B4%80%EB%A6%AC',
        'common' => '%EA%B3%B5%ED%86%B5',
        'statement' => '%EA%B1%B0%EB%9E%98%EB%AA%85%EC%84%B8%ED%91%9C',
        'tax_invoice' => '%EC%84%B8%EA%B8%88%EA%B3%84%EC%82%B0%EC%84%9C',
        'settlement' => '%EC%A0%95%EC%82%B0%EC%9E%90%EB%A3%8C',
        'labor' => '%EB%85%B8%EB%AC%B4%EC%9E%90%EB%A3%8C',
        'manpower' => '%EC%9D%B8%EB%A0%A5%EA%B4%80%EB%A6%AC',
        'etc' => '%EA%B8%B0%ED%83%80',
        'file_check_required' => '%ED%8C%8C%EC%9D%BC%20%ED%99%95%EC%9D%B8%20%ED%95%84%EC%9A%94',
        'view' => '%EB%B3%B4%EA%B8%B0',
        'download' => '%EB%8B%A4%EC%9A%B4%EB%A1%9C%EB%93%9C',
        'month_review' => '%EA%B4%80%EB%A6%AC%20%EC%84%B9%EC%85%98%20%EC%9B%94%20%EA%B0%92%20%ED%99%95%EC%9D%B8%20%ED%95%84%EC%9A%94',
        'upload_failed_notice' => '%ED%8C%8C%EC%9D%BC%EC%9D%80%20%EC%A0%80%EC%9E%A5%EB%90%98%EC%97%88%EC%A7%80%EB%A7%8C%20Google%20Drive%20%EC%97%85%EB%A1%9C%EB%93%9C%EC%97%90%20%EC%8B%A4%ED%8C%A8%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.%20%EA%B4%80%EB%A6%AC%EC%9E%90%EC%97%90%EA%B2%8C%20%EB%AC%B8%EC%9D%98%ED%95%B4%EC%A3%BC%EC%84%B8%EC%9A%94.'
    );
    return isset($labels[$key]) ? urldecode($labels[$key]) : (string)$key;
}}

if (!function_exists('cpms_management_drive_table_exists')) {
function cpms_management_drive_table_exists($pdo, $table) {
    if (!$pdo || trim((string)$table) === '') return false;
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE :tbl");
        $st->bindValue(':tbl', (string)$table);
        $st->execute();
        return $st->fetch() ? true : false;
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_management_drive_column_exists')) {
function cpms_management_drive_column_exists($pdo, $table, $column) {
    if (!$pdo || trim((string)$table) === '' || trim((string)$column) === '') return false;
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `" . $table . "` LIKE :col");
        $st->bindValue(':col', (string)$column);
        $st->execute();
        return $st->fetch() ? true : false;
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_management_drive_column_sql')) {
function cpms_management_drive_column_sql() {
    return array(
        'project_id' => "ALTER TABLE `%s` ADD COLUMN project_id INT NULL",
        'storage_type' => "ALTER TABLE `%s` ADD COLUMN storage_type VARCHAR(30) NULL",
        'drive_name' => "ALTER TABLE `%s` ADD COLUMN drive_name VARCHAR(255) NULL",
        'drive_file_id' => "ALTER TABLE `%s` ADD COLUMN drive_file_id VARCHAR(128) NULL",
        'drive_folder_id' => "ALTER TABLE `%s` ADD COLUMN drive_folder_id VARCHAR(128) NULL",
        'drive_web_view_link' => "ALTER TABLE `%s` ADD COLUMN drive_web_view_link TEXT NULL",
        'drive_web_content_link' => "ALTER TABLE `%s` ADD COLUMN drive_web_content_link TEXT NULL",
        'mime_type' => "ALTER TABLE `%s` ADD COLUMN mime_type VARCHAR(190) NULL",
        'file_size' => "ALTER TABLE `%s` ADD COLUMN file_size BIGINT NULL",
        'section' => "ALTER TABLE `%s` ADD COLUMN section VARCHAR(80) NULL",
        'document_type' => "ALTER TABLE `%s` ADD COLUMN document_type VARCHAR(80) NULL",
        'document_year' => "ALTER TABLE `%s` ADD COLUMN document_year VARCHAR(4) NULL",
        'document_month' => "ALTER TABLE `%s` ADD COLUMN document_month VARCHAR(2) NULL",
        'drive_year_folder_id' => "ALTER TABLE `%s` ADD COLUMN drive_year_folder_id VARCHAR(128) NULL",
        'drive_type_folder_id' => "ALTER TABLE `%s` ADD COLUMN drive_type_folder_id VARCHAR(128) NULL",
        'drive_month_folder_id' => "ALTER TABLE `%s` ADD COLUMN drive_month_folder_id VARCHAR(128) NULL",
        'is_common_file' => "ALTER TABLE `%s` ADD COLUMN is_common_file TINYINT(1) NOT NULL DEFAULT 0",
        'uploaded_by' => "ALTER TABLE `%s` ADD COLUMN uploaded_by INT NULL",
        'uploaded_at' => "ALTER TABLE `%s` ADD COLUMN uploaded_at DATETIME NULL",
        'upload_status' => "ALTER TABLE `%s` ADD COLUMN upload_status VARCHAR(30) NULL",
        'drive_upload_error' => "ALTER TABLE `%s` ADD COLUMN drive_upload_error TEXT NULL"
    );
}}

if (!function_exists('cpms_management_drive_ensure_table_columns')) {
function cpms_management_drive_ensure_table_columns($pdo, $table) {
    if (!$pdo || trim((string)$table) === '') return false;
    if (!cpms_management_drive_table_exists($pdo, $table)) return false;
    $columns = cpms_management_drive_column_sql();
    $ok = true;
    foreach ($columns as $column => $sqlTpl) {
        if (cpms_management_drive_column_exists($pdo, $table, $column)) continue;
        try {
            $pdo->exec(sprintf($sqlTpl, $table));
        } catch (Exception $e) {
            $ok = false;
            cpms_drive_log_upload_failure(array(
                'section' => 'management_schema',
                'message' => 'Management Drive column creation failed: ' . $table . '.' . $column . ' / ' . $e->getMessage()
            ));
        }
    }
    return $ok;
}}

if (!function_exists('cpms_management_drive_document_info')) {
function cpms_management_drive_document_info($documentType, $originalName) {
    $type = trim((string)$documentType);
    $originalName = trim((string)$originalName);
    $norm = function_exists('mb_strtolower') ? mb_strtolower($type . ' ' . $originalName, 'UTF-8') : strtolower($type . ' ' . $originalName);
    $map = array(
        'statement' => array('folder_key' => 'management_statement', 'folder_label' => cpms_management_drive_label('statement'), 'document_type' => 'statement', 'document_label' => cpms_management_drive_label('statement')),
        'transaction_statement' => array('folder_key' => 'management_statement', 'folder_label' => cpms_management_drive_label('statement'), 'document_type' => 'statement', 'document_label' => cpms_management_drive_label('statement')),
        'material_statement' => array('folder_key' => 'management_statement', 'folder_label' => cpms_management_drive_label('statement'), 'document_type' => 'statement', 'document_label' => cpms_management_drive_label('statement')),
        'management_statement' => array('folder_key' => 'management_statement', 'folder_label' => cpms_management_drive_label('statement'), 'document_type' => 'statement', 'document_label' => cpms_management_drive_label('statement')),
        'tax_invoice' => array('folder_key' => 'management_tax_invoice', 'folder_label' => cpms_management_drive_label('tax_invoice'), 'document_type' => 'tax_invoice', 'document_label' => cpms_management_drive_label('tax_invoice')),
        'tax' => array('folder_key' => 'management_tax_invoice', 'folder_label' => cpms_management_drive_label('tax_invoice'), 'document_type' => 'tax_invoice', 'document_label' => cpms_management_drive_label('tax_invoice')),
        'invoice' => array('folder_key' => 'management_tax_invoice', 'folder_label' => cpms_management_drive_label('tax_invoice'), 'document_type' => 'tax_invoice', 'document_label' => cpms_management_drive_label('tax_invoice')),
        'settlement' => array('folder_key' => 'management_settlement', 'folder_label' => cpms_management_drive_label('settlement'), 'document_type' => 'settlement', 'document_label' => cpms_management_drive_label('settlement')),
        'labor' => array('folder_key' => 'management_labor', 'folder_label' => cpms_management_drive_label('labor'), 'document_type' => 'labor', 'document_label' => cpms_management_drive_label('labor')),
        'labor_data' => array('folder_key' => 'management_labor', 'folder_label' => cpms_management_drive_label('labor'), 'document_type' => 'labor', 'document_label' => cpms_management_drive_label('labor')),
        'labor_consultant' => array('folder_key' => 'management_labor', 'folder_label' => cpms_management_drive_label('labor'), 'document_type' => 'labor', 'document_label' => cpms_management_drive_label('labor')),
        'manpower' => array('folder_key' => 'management_manpower', 'folder_label' => cpms_management_drive_label('manpower'), 'document_type' => 'manpower', 'document_label' => cpms_management_drive_label('manpower')),
        'workforce' => array('folder_key' => 'management_manpower', 'folder_label' => cpms_management_drive_label('manpower'), 'document_type' => 'manpower', 'document_label' => cpms_management_drive_label('manpower')),
        'worker_excel' => array('folder_key' => 'management_manpower', 'folder_label' => cpms_management_drive_label('manpower'), 'document_type' => 'manpower', 'document_label' => cpms_management_drive_label('manpower')),
        'common' => array('folder_key' => 'management_etc', 'folder_label' => cpms_management_drive_label('etc'), 'document_type' => 'common', 'document_label' => cpms_management_drive_label('etc')),
        'etc' => array('folder_key' => 'management_etc', 'folder_label' => cpms_management_drive_label('etc'), 'document_type' => 'etc', 'document_label' => cpms_management_drive_label('etc'))
    );

    if (isset($map[$type])) return $map[$type];
    if (strpos($norm, 'tax') !== false || strpos($norm, 'invoice') !== false || strpos($norm, cpms_management_drive_label('tax_invoice')) !== false) return $map['tax_invoice'];
    if (strpos($norm, 'settlement') !== false || strpos($norm, cpms_management_drive_label('settlement')) !== false) return $map['settlement'];
    if (strpos($norm, 'labor') !== false || strpos($norm, cpms_management_drive_label('labor')) !== false) return $map['labor'];
    if (strpos($norm, 'workforce') !== false || strpos($norm, 'manpower') !== false || strpos($norm, cpms_management_drive_label('manpower')) !== false) return $map['manpower'];
    if (strpos($norm, 'statement') !== false || strpos($norm, cpms_management_drive_label('statement')) !== false || strpos($norm, urldecode('%EA%B1%B0%EB%9E%98%EB%AA%85%EC%84%B8%EC%84%9C')) !== false) return $map['statement'];
    return $map['etc'];
}}

if (!function_exists('cpms_management_drive_folder_aliases')) {
function cpms_management_drive_folder_aliases($key) {
    $aliases = array(
        'management_statement' => array('management_transaction_statement'),
        'management_etc' => array('management_other')
    );
    return isset($aliases[$key]) ? $aliases[$key] : array();
}}

if (!function_exists('cpms_management_drive_project_drive_data')) {
function cpms_management_drive_project_drive_data($project) {
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

if (!function_exists('cpms_management_drive_folder_id_from_data')) {
function cpms_management_drive_folder_id_from_data($driveData, $key) {
    if (!is_array($driveData) || !isset($driveData['folders']) || !is_array($driveData['folders'])) return '';
    if (isset($driveData['folders'][$key]) && trim((string)$driveData['folders'][$key]) !== '') return trim((string)$driveData['folders'][$key]);
    $aliases = cpms_management_drive_folder_aliases($key);
    foreach ($aliases as $alias) {
        if (isset($driveData['folders'][$alias]) && trim((string)$driveData['folders'][$alias]) !== '') return trim((string)$driveData['folders'][$alias]);
    }
    return '';
}}

if (!function_exists('cpms_management_drive_save_project_data')) {
function cpms_management_drive_save_project_data($pdo, $projectId, $driveData, $message) {
    if (!$pdo || (int)$projectId <= 0 || !is_array($driveData)) return false;
    $result = array(
        'ok' => true,
        'status' => 'ready',
        'message' => (string)$message,
        'drive' => $driveData
    );
    return cpms_drive_save_project_structure_result($pdo, (int)$projectId, $result);
}}

if (!function_exists('cpms_management_drive_load_project')) {
function cpms_management_drive_load_project($pdo, $projectId) {
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

if (!function_exists('cpms_management_drive_ensure_project_data')) {
function cpms_management_drive_ensure_project_data($pdo, $project, $userContext) {
    if (!$pdo || !is_array($project) || !isset($project['id'])) {
        return array('ok' => false, 'project' => $project, 'drive' => array(), 'message' => 'Project row is not available.', 'http_code' => 0);
    }
    $projectId = (int)$project['id'];
    $projectName = isset($project['name']) ? (string)$project['name'] : '';
    $driveData = cpms_management_drive_project_drive_data($project);
    $projectFolderId = isset($driveData['project_folder_id']) ? trim((string)$driveData['project_folder_id']) : '';
    if ($projectFolderId === '') {
        $sync = cpms_drive_sync_project_after_create($pdo, $projectId, $projectName, $userContext, 'management_upload');
        $driveResult = (isset($sync['drive_result']) && is_array($sync['drive_result'])) ? $sync['drive_result'] : array();
        if (empty($driveResult['ok'])) {
            $message = isset($driveResult['message']) ? (string)$driveResult['message'] : 'Project Drive folder sync failed.';
            cpms_drive_log_upload_failure(array(
                'user' => $userContext,
                'section' => 'management',
                'project_id' => $projectId,
                'message' => $message,
                'http_status' => isset($driveResult['http_code']) ? (int)$driveResult['http_code'] : 0,
                'google_response_excerpt' => isset($driveResult['google_response_excerpt']) ? $driveResult['google_response_excerpt'] : ''
            ));
            return array('ok' => false, 'project' => $project, 'drive' => $driveData, 'message' => $message, 'http_code' => isset($driveResult['http_code']) ? (int)$driveResult['http_code'] : 0);
        }
        $fresh = cpms_management_drive_load_project($pdo, $projectId);
        if (is_array($fresh)) $project = $fresh;
        $driveData = isset($driveResult['drive']) && is_array($driveResult['drive']) ? $driveResult['drive'] : cpms_management_drive_project_drive_data($project);
        $projectFolderId = isset($driveData['project_folder_id']) ? trim((string)$driveData['project_folder_id']) : '';
    }
    if ($projectFolderId === '') {
        return array('ok' => false, 'project' => $project, 'drive' => $driveData, 'message' => 'Project Drive folder ID is empty. Project Drive folder sync is required.', 'http_code' => 0);
    }
    if (!isset($driveData['folders']) || !is_array($driveData['folders'])) $driveData['folders'] = array();
    $driveData['project_folder_id'] = $projectFolderId;
    $driveData['folders']['project'] = $projectFolderId;
    return array('ok' => true, 'project' => $project, 'drive' => $driveData, 'message' => 'Project Drive data is ready.', 'http_code' => 0);
}}

if (!function_exists('cpms_management_drive_parse_month')) {
function cpms_management_drive_parse_month($value, $fallbackDate) {
    $raw = trim((string)$value);
    $fallbackDate = trim((string)$fallbackDate);
    $year = '';
    $month = '';
    $usedFallback = false;
    $message = '';

    if ($raw !== '') {
        if (preg_match('/(\d{4})\D{0,5}(\d{1,2})/u', $raw, $m)) {
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
        if ($usedFallback) $message = cpms_management_drive_label('month_review') . ': ' . $raw;
    }

    return array(
        'year' => $year,
        'month' => $month,
        'ym' => $year . '-' . $month,
        'used_fallback' => $usedFallback,
        'message' => $message
    );
}}

if (!function_exists('cpms_management_drive_ensure_folder')) {
function cpms_management_drive_ensure_folder($name, $parentId, $context) {
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

if (!function_exists('cpms_management_drive_ensure_target_folder')) {
function cpms_management_drive_ensure_target_folder($pdo, $projectId, $documentType, $monthValue, $fallbackDate, $userContext, $originalName) {
    $projectId = (int)$projectId;
    $docInfo = cpms_management_drive_document_info($documentType, $originalName);
    $monthInfo = cpms_management_drive_parse_month($monthValue, $fallbackDate);
    $baseContext = array(
        'user' => $userContext,
        'section' => 'management',
        'project_id' => $projectId,
        'is_common_file' => $projectId > 0 ? 0 : 1,
        'document_type' => isset($docInfo['document_label']) ? $docInfo['document_label'] : $documentType,
        'document_year' => $monthInfo['year'],
        'document_month' => $monthInfo['month'],
        'original_name' => $originalName
    );
    if (!empty($monthInfo['used_fallback'])) {
        cpms_drive_log_upload_failure(array_merge($baseContext, array('message' => $monthInfo['message'])));
    }

    $driveData = array('folders' => array());
    $project = false;
    $projectName = cpms_management_drive_label('common');
    if ($projectId > 0) {
        $project = cpms_management_drive_load_project($pdo, $projectId);
        if (!is_array($project)) {
            return array('ok' => false, 'message' => 'Project row was not found.', 'http_code' => 0);
        }
        $ready = cpms_management_drive_ensure_project_data($pdo, $project, $userContext);
        if (empty($ready['ok'])) return $ready;
        $project = isset($ready['project']) && is_array($ready['project']) ? $ready['project'] : $project;
        $driveData = isset($ready['drive']) && is_array($ready['drive']) ? $ready['drive'] : array('folders' => array());
        if (!isset($driveData['folders']) || !is_array($driveData['folders'])) $driveData['folders'] = array();
        $parentId = isset($driveData['project_folder_id']) ? trim((string)$driveData['project_folder_id']) : '';
        $projectName = isset($project['name']) ? (string)$project['name'] : '';
        $managementId = cpms_management_drive_folder_id_from_data($driveData, 'management');
        if ($managementId === '') {
            $folder = cpms_management_drive_ensure_folder(cpms_management_drive_label('management_root'), $parentId, array_merge($baseContext, array('target_folder_id' => $parentId)));
            if (empty($folder['ok'])) return $folder;
            $managementId = $folder['folder_id'];
            $driveData['folders']['management'] = $managementId;
        }
    } else {
        $parentId = cpms_drive_folder_id('common_documents');
        if ($parentId === '') {
            return array('ok' => false, 'message' => 'Common documents Drive folder ID is empty.', 'http_code' => 0);
        }
        $folder = cpms_management_drive_ensure_folder(cpms_management_drive_label('management'), $parentId, array_merge($baseContext, array('target_folder_id' => $parentId)));
        if (empty($folder['ok'])) return $folder;
        $managementId = $folder['folder_id'];
    }

    $childKey = isset($docInfo['folder_key']) ? (string)$docInfo['folder_key'] : 'management_etc';
    $childLabel = isset($docInfo['folder_label']) ? (string)$docInfo['folder_label'] : cpms_management_drive_label('etc');
    $childId = $projectId > 0 ? cpms_management_drive_folder_id_from_data($driveData, $childKey) : '';
    if ($childId === '') {
        $folder = cpms_management_drive_ensure_folder($childLabel, $managementId, array_merge($baseContext, array('target_folder_id' => $managementId)));
        if (empty($folder['ok'])) return $folder;
        $childId = $folder['folder_id'];
        if ($projectId > 0) $driveData['folders'][$childKey] = $childId;
    }

    $yearKey = $childKey . '_' . $monthInfo['year'];
    $yearId = $projectId > 0 ? cpms_management_drive_folder_id_from_data($driveData, $yearKey) : '';
    if ($yearId === '') {
        $folder = cpms_management_drive_ensure_folder($monthInfo['year'], $childId, array_merge($baseContext, array('target_folder_id' => $childId)));
        if (empty($folder['ok'])) return $folder;
        $yearId = $folder['folder_id'];
        if ($projectId > 0) $driveData['folders'][$yearKey] = $yearId;
    }

    $monthKey = $yearKey . '_' . $monthInfo['month'];
    $monthId = $projectId > 0 ? cpms_management_drive_folder_id_from_data($driveData, $monthKey) : '';
    if ($monthId === '') {
        $folder = cpms_management_drive_ensure_folder($monthInfo['month'], $yearId, array_merge($baseContext, array('target_folder_id' => $yearId)));
        if (empty($folder['ok'])) return $folder;
        $monthId = $folder['folder_id'];
        if ($projectId > 0) $driveData['folders'][$monthKey] = $monthId;
    }

    if ($projectId > 0) {
        cpms_management_drive_save_project_data($pdo, $projectId, $driveData, 'Management Drive monthly folders updated.');
    }

    return array(
        'ok' => true,
        'project' => $project,
        'project_name' => $projectName,
        'drive' => $driveData,
        'document_info' => $docInfo,
        'month_info' => $monthInfo,
        'management_folder_id' => $managementId,
        'document_folder_id' => $childId,
        'year_folder_id' => $yearId,
        'month_folder_id' => $monthId,
        'folder_id' => $monthId,
        'is_common_file' => $projectId > 0 ? 0 : 1,
        'message' => 'Management Drive target folder is ready.',
        'http_code' => 0
    );
}}

if (!function_exists('cpms_management_drive_user_name')) {
function cpms_management_drive_user_name($userContext) {
    if (is_array($userContext)) {
        if (isset($userContext['name']) && trim((string)$userContext['name']) !== '') return trim((string)$userContext['name']);
        if (isset($userContext['email']) && trim((string)$userContext['email']) !== '') return trim((string)$userContext['email']);
    }
    $label = cpms_drive_user_label($userContext);
    return $label !== '' ? $label : '-';
}}

if (!function_exists('cpms_management_drive_build_file_name')) {
function cpms_management_drive_build_file_name($date, $documentLabel, $projectName, $userName, $originalName) {
    $date = trim((string)$date);
    if ($date === '') $date = date('Y-m-d');
    $targetName = trim((string)$projectName);
    if ($targetName === '') $targetName = cpms_management_drive_label('common');
    $parts = array(
        $date,
        cpms_management_drive_label('management'),
        trim((string)$documentLabel),
        $targetName,
        trim((string)$userName),
        date('His') . '_' . mt_rand(1000, 9999),
        trim((string)$originalName)
    );
    return cpms_drive_sanitize_file_name(implode('_', $parts), 180);
}}

if (!function_exists('cpms_management_drive_failed_record')) {
function cpms_management_drive_failed_record($projectId, $isCommonFile, $originalName, $localPath, $mimeType, $size, $documentInfo, $monthInfo, $message) {
    return array(
        'original_name' => (string)$originalName,
        'stored_name' => '',
        'drive_file_id' => '',
        'drive_folder_id' => '',
        'drive_web_view_link' => '',
        'drive_web_content_link' => '',
        'mime_type' => (string)$mimeType,
        'size' => (string)$size,
        'section' => 'management',
        'document_type' => isset($documentInfo['document_type']) ? (string)$documentInfo['document_type'] : '',
        'document_year' => isset($monthInfo['year']) ? (string)$monthInfo['year'] : '',
        'document_month' => isset($monthInfo['month']) ? (string)$monthInfo['month'] : '',
        'drive_year_folder_id' => '',
        'drive_type_folder_id' => '',
        'drive_month_folder_id' => '',
        'project_id' => (string)(int)$projectId,
        'is_common_file' => (int)$isCommonFile,
        'storage_type' => 'local',
        'local_backup_path' => (string)$localPath,
        'upload_status' => 'failed',
        'drive_upload_error' => cpms_drive_redact_text((string)$message),
        'uploaded_at' => date('Y-m-d H:i:s')
    );
}}

if (!function_exists('cpms_management_drive_upload_local_file')) {
function cpms_management_drive_upload_local_file($pdo, $projectId, $localPath, $originalName, $documentType, $monthValue, $fallbackDate, $extra, $userContext) {
    $extra = is_array($extra) ? $extra : array();
    $projectId = (int)$projectId;
    $localPath = trim((string)$localPath);
    $originalName = trim((string)$originalName);
    $mimeType = cpms_drive_detect_mime_type($localPath);
    $size = is_file($localPath) ? (int)@filesize($localPath) : 0;
    $docInfo = cpms_management_drive_document_info($documentType, $originalName);
    $monthInfo = cpms_management_drive_parse_month($monthValue, $fallbackDate);
    $isCommonFile = $projectId > 0 ? 0 : 1;
    $context = array(
        'user' => $userContext,
        'uploaded_by' => $userContext,
        'section' => 'management',
        'project_id' => $projectId,
        'is_common_file' => $isCommonFile,
        'document_type' => isset($docInfo['document_label']) ? $docInfo['document_label'] : $documentType,
        'document_year' => isset($monthInfo['year']) ? $monthInfo['year'] : '',
        'document_month' => isset($monthInfo['month']) ? $monthInfo['month'] : '',
        'original_name' => $originalName,
        'mime_type' => $mimeType,
        'size' => $size,
        'local_backup_path' => $localPath
    );

    if ($localPath === '' || !is_file($localPath)) {
        $message = 'Local management file is not available for Drive upload.';
        cpms_drive_log_upload_failure(array_merge($context, array('message' => $message)));
        return array('ok' => false, 'record' => cpms_management_drive_failed_record($projectId, $isCommonFile, $originalName, $localPath, $mimeType, $size, $docInfo, $monthInfo, $message), 'message' => $message, 'http_code' => 0);
    }

    $target = cpms_management_drive_ensure_target_folder($pdo, $projectId, $documentType, $monthValue, $fallbackDate, $userContext, $originalName);
    if (empty($target['ok'])) {
        $message = isset($target['message']) ? $target['message'] : 'Management Drive folder preparation failed.';
        cpms_drive_log_upload_failure(array_merge($context, array(
            'message' => $message,
            'http_status' => isset($target['http_code']) ? (int)$target['http_code'] : 0
        )));
        return array('ok' => false, 'record' => cpms_management_drive_failed_record($projectId, $isCommonFile, $originalName, $localPath, $mimeType, $size, $docInfo, $monthInfo, $message), 'message' => $message, 'http_code' => isset($target['http_code']) ? (int)$target['http_code'] : 0);
    }

    $docInfo = isset($target['document_info']) && is_array($target['document_info']) ? $target['document_info'] : $docInfo;
    $monthInfo = isset($target['month_info']) && is_array($target['month_info']) ? $target['month_info'] : $monthInfo;
    $projectName = isset($target['project_name']) ? (string)$target['project_name'] : '';
    if (!empty($target['is_common_file'])) $projectName = cpms_management_drive_label('common');
    if (isset($extra['project_name']) && trim((string)$extra['project_name']) !== '') $projectName = (string)$extra['project_name'];
    $date = $monthInfo['year'] . '-' . $monthInfo['month'] . '-' . date('d');
    if (isset($extra['date']) && trim((string)$extra['date']) !== '') $date = trim((string)$extra['date']);
    $driveName = cpms_management_drive_build_file_name($date, isset($docInfo['document_label']) ? $docInfo['document_label'] : $documentType, $projectName, cpms_management_drive_user_name($userContext), $originalName);

    $context['target_folder_id'] = (string)$target['folder_id'];
    $context['drive_year_folder_id'] = isset($target['year_folder_id']) ? (string)$target['year_folder_id'] : '';
    $context['drive_type_folder_id'] = isset($target['document_folder_id']) ? (string)$target['document_folder_id'] : '';
    $context['drive_month_folder_id'] = isset($target['month_folder_id']) ? (string)$target['month_folder_id'] : '';
    $context['document_year'] = (string)$monthInfo['year'];
    $context['document_month'] = (string)$monthInfo['month'];
    $context['document_type'] = isset($docInfo['document_label']) ? $docInfo['document_label'] : $documentType;
    $upload = cpms_drive_upload_file($localPath, $driveName, (string)$target['folder_id'], $mimeType, $context);
    if (empty($upload['ok']) || !isset($upload['file']) || !is_array($upload['file'])) {
        $message = isset($upload['message']) ? $upload['message'] : 'Management file Drive upload failed.';
        return array('ok' => false, 'record' => cpms_management_drive_failed_record($projectId, !empty($target['is_common_file']) ? 1 : 0, $originalName, $localPath, $mimeType, $size, $docInfo, $monthInfo, $message), 'message' => $message, 'http_code' => isset($upload['http_code']) ? (int)$upload['http_code'] : 0);
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
    $record['is_common_file'] = !empty($target['is_common_file']) ? 1 : 0;
    $record['upload_status'] = 'uploaded';
    $record['drive_upload_error'] = '';
    $record['local_backup_path'] = $localPath;

    return array('ok' => true, 'record' => $record, 'message' => isset($upload['message']) ? $upload['message'] : '', 'http_code' => isset($upload['http_code']) ? (int)$upload['http_code'] : 0);
}}

if (!function_exists('cpms_management_drive_record_values')) {
function cpms_management_drive_record_values($record, $userId) {
    $record = is_array($record) ? $record : array();
    return array(
        'project_id' => isset($record['project_id']) ? (int)$record['project_id'] : null,
        'storage_type' => isset($record['storage_type']) ? (string)$record['storage_type'] : '',
        'drive_name' => isset($record['stored_name']) ? (string)$record['stored_name'] : '',
        'drive_file_id' => isset($record['drive_file_id']) ? (string)$record['drive_file_id'] : '',
        'drive_folder_id' => isset($record['drive_folder_id']) ? (string)$record['drive_folder_id'] : '',
        'drive_web_view_link' => isset($record['drive_web_view_link']) ? (string)$record['drive_web_view_link'] : '',
        'drive_web_content_link' => isset($record['drive_web_content_link']) ? (string)$record['drive_web_content_link'] : '',
        'mime_type' => isset($record['mime_type']) ? (string)$record['mime_type'] : '',
        'file_size' => isset($record['size']) ? (string)$record['size'] : '',
        'section' => isset($record['section']) ? (string)$record['section'] : 'management',
        'document_type' => isset($record['document_type']) ? (string)$record['document_type'] : '',
        'document_year' => isset($record['document_year']) ? (string)$record['document_year'] : '',
        'document_month' => isset($record['document_month']) ? (string)$record['document_month'] : '',
        'drive_year_folder_id' => isset($record['drive_year_folder_id']) ? (string)$record['drive_year_folder_id'] : '',
        'drive_type_folder_id' => isset($record['drive_type_folder_id']) ? (string)$record['drive_type_folder_id'] : '',
        'drive_month_folder_id' => isset($record['drive_month_folder_id']) ? (string)$record['drive_month_folder_id'] : '',
        'is_common_file' => isset($record['is_common_file']) ? (int)$record['is_common_file'] : 0,
        'upload_status' => isset($record['upload_status']) ? (string)$record['upload_status'] : '',
        'drive_upload_error' => isset($record['drive_upload_error']) ? cpms_drive_redact_text((string)$record['drive_upload_error']) : '',
        'uploaded_by' => (int)$userId,
        'uploaded_at' => isset($record['uploaded_at']) ? (string)$record['uploaded_at'] : date('Y-m-d H:i:s')
    );
}}

if (!function_exists('cpms_management_drive_apply_record_to_row')) {
function cpms_management_drive_apply_record_to_row($pdo, $table, $id, $record, $userId, $deleteOnFailureContext) {
    $id = (int)$id;
    if (!$pdo || $id <= 0 || trim((string)$table) === '' || !is_array($record)) {
        return array('ok' => false, 'message' => 'Invalid management Drive row update request.');
    }
    cpms_management_drive_ensure_table_columns($pdo, $table);
    if (!cpms_management_drive_table_exists($pdo, $table)) {
        return array('ok' => false, 'message' => 'Target table does not exist: ' . $table);
    }

    $values = cpms_management_drive_record_values($record, $userId);
    $sets = array();
    $params = array(':id' => $id);
    foreach ($values as $column => $value) {
        if (!cpms_management_drive_column_exists($pdo, $table, $column)) continue;
        $sets[] = '`' . $column . '` = :' . $column;
        $params[':' . $column] = $value;
    }
    if (count($sets) === 0) return array('ok' => false, 'message' => 'No Drive columns are available on ' . $table);
    try {
        $sql = "UPDATE `" . $table . "` SET " . implode(', ', $sets) . " WHERE id = :id";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return array('ok' => true, 'message' => 'Management Drive record saved.');
    } catch (Exception $e) {
        $message = 'Management Drive metadata save failed: ' . $e->getMessage();
        if (isset($record['drive_file_id']) && trim((string)$record['drive_file_id']) !== '') {
            $context = is_array($deleteOnFailureContext) ? $deleteOnFailureContext : array();
            $context['message'] = $message;
            cpms_drive_delete_file((string)$record['drive_file_id'], $context);
        }
        cpms_drive_log_upload_failure(array(
            'section' => 'management',
            'project_id' => isset($deleteOnFailureContext['project_id']) ? $deleteOnFailureContext['project_id'] : '',
            'is_common_file' => isset($record['is_common_file']) ? (int)$record['is_common_file'] : 0,
            'document_type' => isset($record['document_type']) ? $record['document_type'] : '',
            'document_year' => isset($record['document_year']) ? $record['document_year'] : '',
            'document_month' => isset($record['document_month']) ? $record['document_month'] : '',
            'original_name' => isset($record['original_name']) ? $record['original_name'] : '',
            'target_folder_id' => isset($record['drive_folder_id']) ? $record['drive_folder_id'] : '',
            'message' => $message
        ));
        return array('ok' => false, 'message' => $message);
    }
}}

if (!function_exists('cpms_management_drive_flash_message')) {
function cpms_management_drive_flash_message($baseMessage, $uploadResult) {
    if (is_array($uploadResult) && !empty($uploadResult['ok'])) return $baseMessage;
    return $baseMessage . ' ' . cpms_management_drive_label('upload_failed_notice');
}}

if (!function_exists('cpms_management_drive_select_admin_project')) {
function cpms_management_drive_select_admin_project($pdo, $projectId) {
    if (!$pdo) return false;
    try {
        if ((int)$projectId > 0) {
            $st = $pdo->prepare("SELECT * FROM cpms_projects WHERE id = :id LIMIT 1");
            $st->bindValue(':id', (int)$projectId, PDO::PARAM_INT);
            $st->execute();
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) return $row;
        }
        $st2 = $pdo->query("SELECT * FROM cpms_projects ORDER BY id ASC LIMIT 1");
        $row2 = $st2 ? $st2->fetch(PDO::FETCH_ASSOC) : false;
        return is_array($row2) ? $row2 : false;
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_management_drive_run_admin_check')) {
function cpms_management_drive_run_admin_check($pdo, $userContext, $projectId) {
    $result = array(
        'project' => array('ok' => false, 'message' => ''),
        'management_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'statement_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'tax_invoice_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'settlement_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'labor_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'manpower_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'year_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'month_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'upload' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'delete' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'common_management_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'common_manpower_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'common_year_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'common_month_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'common_upload' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'common_delete' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'cleanup' => array('ok' => false, 'message' => ''),
        'test_file' => array(),
        'common_test_file' => array()
    );

    $project = cpms_management_drive_select_admin_project($pdo, $projectId);
    if (is_array($project)) {
        $selectedProjectId = isset($project['id']) ? (int)$project['id'] : 0;
        $result['project'] = array('ok' => true, 'id' => $selectedProjectId, 'name' => isset($project['name']) ? (string)$project['name'] : '', 'message' => 'Project selected.');
        $target = cpms_management_drive_ensure_target_folder($pdo, $selectedProjectId, 'statement', date('Y-m'), date('Y-m-d'), $userContext, 'management_drive_check.txt');
        $result['management_folder'] = array('ok' => !empty($target['management_folder_id']), 'http_code' => isset($target['http_code']) ? (int)$target['http_code'] : 0, 'message' => !empty($target['management_folder_id']) ? '02_management folder is ready.' : (isset($target['message']) ? $target['message'] : 'Folder check failed.'));
        $result['statement_folder'] = array('ok' => !empty($target['document_folder_id']), 'http_code' => isset($target['http_code']) ? (int)$target['http_code'] : 0, 'message' => !empty($target['document_folder_id']) ? 'Statement folder is ready.' : (isset($target['message']) ? $target['message'] : 'Folder check failed.'));
        $result['year_folder'] = array('ok' => !empty($target['year_folder_id']), 'http_code' => isset($target['http_code']) ? (int)$target['http_code'] : 0, 'message' => !empty($target['year_folder_id']) ? 'Year folder is ready.' : (isset($target['message']) ? $target['message'] : 'Folder check failed.'));
        $result['month_folder'] = array('ok' => !empty($target['month_folder_id']), 'http_code' => isset($target['http_code']) ? (int)$target['http_code'] : 0, 'message' => !empty($target['month_folder_id']) ? 'Month folder is ready.' : (isset($target['message']) ? $target['message'] : 'Folder check failed.'));

        $checkTypes = array('tax_invoice', 'settlement', 'labor', 'manpower');
        foreach ($checkTypes as $checkType) {
            $subTarget = cpms_management_drive_ensure_target_folder($pdo, $selectedProjectId, $checkType, date('Y-m'), date('Y-m-d'), $userContext, 'management_drive_check.txt');
            $key = $checkType . '_folder';
            $result[$key] = array('ok' => !empty($subTarget['document_folder_id']), 'http_code' => isset($subTarget['http_code']) ? (int)$subTarget['http_code'] : 0, 'message' => !empty($subTarget['document_folder_id']) ? $checkType . ' folder is ready.' : (isset($subTarget['message']) ? $subTarget['message'] : 'Folder check failed.'));
        }

        if (!empty($target['ok'])) {
            $tmpDir = cpms_drive_storage_root() . '/tmp/management_drive_check';
            if (cpms_drive_ensure_dir($tmpDir)) {
                $tmpPath = @tempnam($tmpDir, 'mgmt_drive_');
                if ($tmpPath !== false && @file_put_contents($tmpPath, "CPMS management Drive check\n" . date('Y-m-d H:i:s') . "\n") !== false) {
                    $fileName = 'CPMS_Management_Check_' . date('Ymd_His') . '.txt';
                    $context = array('user' => $userContext, 'section' => 'admin_drive_check_management', 'project_id' => $selectedProjectId, 'document_type' => cpms_management_drive_label('statement'), 'original_name' => $fileName, 'target_folder_id' => (string)$target['folder_id']);
                    $upload = cpms_drive_upload_file($tmpPath, $fileName, (string)$target['folder_id'], 'text/plain', $context);
                    $result['upload'] = array('ok' => !empty($upload['ok']), 'http_code' => isset($upload['http_code']) ? (int)$upload['http_code'] : 0, 'message' => isset($upload['message']) ? (string)$upload['message'] : '');
                    if (!empty($upload['ok']) && isset($upload['file']) && is_array($upload['file'])) {
                        $result['test_file'] = array('id' => isset($upload['file']['id']) ? (string)$upload['file']['id'] : '', 'name' => isset($upload['file']['name']) ? (string)$upload['file']['name'] : '', 'webViewLink' => isset($upload['file']['webViewLink']) ? (string)$upload['file']['webViewLink'] : '');
                        $delete = cpms_drive_delete_file($result['test_file']['id'], $context);
                        $result['delete'] = array('ok' => !empty($delete['ok']), 'http_code' => isset($delete['http_code']) ? (int)$delete['http_code'] : 0, 'message' => isset($delete['message']) ? (string)$delete['message'] : '');
                    }
                    $result['cleanup'] = array('ok' => @unlink($tmpPath) ? true : false, 'message' => 'Temporary management check file cleanup attempted.');
                }
            }
        }
    } else {
        $result['project']['message'] = 'No project is available for management Drive check.';
    }

    $commonTarget = cpms_management_drive_ensure_target_folder($pdo, 0, 'manpower', date('Y-m'), date('Y-m-d'), $userContext, 'management_common_drive_check.txt');
    $result['common_management_folder'] = array('ok' => !empty($commonTarget['management_folder_id']), 'http_code' => isset($commonTarget['http_code']) ? (int)$commonTarget['http_code'] : 0, 'message' => !empty($commonTarget['management_folder_id']) ? 'Common management folder is ready.' : (isset($commonTarget['message']) ? $commonTarget['message'] : 'Folder check failed.'));
    $result['common_manpower_folder'] = array('ok' => !empty($commonTarget['document_folder_id']), 'http_code' => isset($commonTarget['http_code']) ? (int)$commonTarget['http_code'] : 0, 'message' => !empty($commonTarget['document_folder_id']) ? 'Common manpower folder is ready.' : (isset($commonTarget['message']) ? $commonTarget['message'] : 'Folder check failed.'));
    $result['common_year_folder'] = array('ok' => !empty($commonTarget['year_folder_id']), 'http_code' => isset($commonTarget['http_code']) ? (int)$commonTarget['http_code'] : 0, 'message' => !empty($commonTarget['year_folder_id']) ? 'Common year folder is ready.' : (isset($commonTarget['message']) ? $commonTarget['message'] : 'Folder check failed.'));
    $result['common_month_folder'] = array('ok' => !empty($commonTarget['month_folder_id']), 'http_code' => isset($commonTarget['http_code']) ? (int)$commonTarget['http_code'] : 0, 'message' => !empty($commonTarget['month_folder_id']) ? 'Common month folder is ready.' : (isset($commonTarget['message']) ? $commonTarget['message'] : 'Folder check failed.'));
    if (!empty($commonTarget['ok'])) {
        $tmpDir2 = cpms_drive_storage_root() . '/tmp/management_drive_check';
        if (cpms_drive_ensure_dir($tmpDir2)) {
            $tmpPath2 = @tempnam($tmpDir2, 'mgmt_common_');
            if ($tmpPath2 !== false && @file_put_contents($tmpPath2, "CPMS common management Drive check\n" . date('Y-m-d H:i:s') . "\n") !== false) {
                $fileName2 = 'CPMS_Management_Common_Check_' . date('Ymd_His') . '.txt';
                $context2 = array('user' => $userContext, 'section' => 'admin_drive_check_management_common', 'is_common_file' => 1, 'document_type' => cpms_management_drive_label('manpower'), 'original_name' => $fileName2, 'target_folder_id' => (string)$commonTarget['folder_id']);
                $upload2 = cpms_drive_upload_file($tmpPath2, $fileName2, (string)$commonTarget['folder_id'], 'text/plain', $context2);
                $result['common_upload'] = array('ok' => !empty($upload2['ok']), 'http_code' => isset($upload2['http_code']) ? (int)$upload2['http_code'] : 0, 'message' => isset($upload2['message']) ? (string)$upload2['message'] : '');
                if (!empty($upload2['ok']) && isset($upload2['file']) && is_array($upload2['file'])) {
                    $result['common_test_file'] = array('id' => isset($upload2['file']['id']) ? (string)$upload2['file']['id'] : '', 'name' => isset($upload2['file']['name']) ? (string)$upload2['file']['name'] : '', 'webViewLink' => isset($upload2['file']['webViewLink']) ? (string)$upload2['file']['webViewLink'] : '');
                    $delete2 = cpms_drive_delete_file($result['common_test_file']['id'], $context2);
                    $result['common_delete'] = array('ok' => !empty($delete2['ok']), 'http_code' => isset($delete2['http_code']) ? (int)$delete2['http_code'] : 0, 'message' => isset($delete2['message']) ? (string)$delete2['message'] : '');
                }
                @unlink($tmpPath2);
            }
        }
    }
    return $result;
}}
