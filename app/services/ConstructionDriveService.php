<?php
/**
 * Construction-section Google Drive helpers.
 * PHP 5.6 compatible. Reuses GoogleDriveHelper; does not implement Google auth.
 */

require_once __DIR__ . '/GoogleDriveHelper.php';

if (!function_exists('cpms_construction_drive_h')) {
function cpms_construction_drive_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}}

if (!function_exists('cpms_construction_drive_label')) {
function cpms_construction_drive_label($key) {
    $labels = array(
        'construction' => '%EA%B3%B5%EC%82%AC',
        'construction_root' => '%30%33%5F%EA%B3%B5%EC%82%AC',
        'material' => '%EC%9E%90%EC%9E%AC%EA%B5%AC%EC%9E%85%EB%B9%84',
        'material_excel' => '%EC%9E%90%EC%9E%AC%EA%B5%AC%EC%9E%85%EB%B9%84%EC%97%91%EC%85%80',
        'equipment' => '%EC%9E%A5%EB%B9%84%ED%88%AC%EC%9E%85',
        'equipment_excel' => '%EC%9E%A5%EB%B9%84%ED%88%AC%EC%9E%85%EC%97%91%EC%85%80',
        'daily_report' => '%EC%9D%BC%EC%9D%BC%EB%B3%B4%EA%B3%A0',
        'photo' => '%EA%B3%B5%EC%82%AC%EC%82%AC%EC%A7%84',
        'status' => '%EC%83%81%ED%99%A9%EC%9E%90%EB%A3%8C',
        'labor' => '%EB%85%B8%EB%AC%B4%EB%B9%84',
        'etc' => '%EA%B8%B0%ED%83%80',
        'file_check_required' => '%ED%8C%8C%EC%9D%BC%20%ED%99%95%EC%9D%B8%20%ED%95%84%EC%9A%94',
        'view' => '%EB%B3%B4%EA%B8%B0',
        'download' => '%EB%8B%A4%EC%9A%B4%EB%A1%9C%EB%93%9C',
        'month_review' => '%EA%B3%B5%EC%82%AC%20%EC%84%B9%EC%85%98%20%EC%9B%94%20%EA%B0%92%20%ED%99%95%EC%9D%B8%20%ED%95%84%EC%9A%94',
        'upload_failed_notice' => '%ED%8C%8C%EC%9D%BC%EC%9D%80%20%EC%A0%80%EC%9E%A5%EB%90%98%EC%97%88%EC%A7%80%EB%A7%8C%20Google%20Drive%20%EC%97%85%EB%A1%9C%EB%93%9C%EC%97%90%20%EC%8B%A4%ED%8C%A8%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.%20%EA%B4%80%EB%A6%AC%EC%9E%90%EC%97%90%EA%B2%8C%20%EB%AC%B8%EC%9D%98%ED%95%B4%EC%A3%BC%EC%84%B8%EC%9A%94.',
        'project_sync_required' => '%ED%94%84%EB%A1%9C%EC%A0%9D%ED%8A%B8%20Drive%20%ED%8F%B4%EB%8D%94%20%EB%8F%99%EA%B8%B0%ED%99%94%20%ED%95%84%EC%9A%94'
    );
    return isset($labels[$key]) ? urldecode($labels[$key]) : (string)$key;
}}

if (!function_exists('cpms_construction_drive_table_exists')) {
function cpms_construction_drive_table_exists($pdo, $table) {
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

if (!function_exists('cpms_construction_drive_column_exists')) {
function cpms_construction_drive_column_exists($pdo, $table, $column) {
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

if (!function_exists('cpms_construction_drive_column_sql')) {
function cpms_construction_drive_column_sql() {
    return array(
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
        'uploaded_by' => "ALTER TABLE `%s` ADD COLUMN uploaded_by INT NULL",
        'uploaded_at' => "ALTER TABLE `%s` ADD COLUMN uploaded_at DATETIME NULL",
        'upload_status' => "ALTER TABLE `%s` ADD COLUMN upload_status VARCHAR(30) NULL",
        'drive_upload_error' => "ALTER TABLE `%s` ADD COLUMN drive_upload_error TEXT NULL"
    );
}}

if (!function_exists('cpms_construction_drive_ensure_table_columns')) {
function cpms_construction_drive_ensure_table_columns($pdo, $table) {
    if (!$pdo || trim((string)$table) === '') return false;
    if (!cpms_construction_drive_table_exists($pdo, $table)) return false;
    $columns = cpms_construction_drive_column_sql();
    $ok = true;
    foreach ($columns as $column => $sqlTpl) {
        if (cpms_construction_drive_column_exists($pdo, $table, $column)) continue;
        try {
            $pdo->exec(sprintf($sqlTpl, $table));
        } catch (Exception $e) {
            $ok = false;
            cpms_drive_log_upload_failure(array(
                'section' => 'construction_schema',
                'message' => 'Construction Drive column creation failed: ' . $table . '.' . $column . ' / ' . $e->getMessage()
            ));
        }
    }
    return $ok;
}}

if (!function_exists('cpms_construction_drive_ensure_generic_table')) {
function cpms_construction_drive_ensure_generic_table($pdo) {
    if (!$pdo) return false;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_construction_drive_files (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL DEFAULT 0,
            source_table VARCHAR(80) NULL,
            source_id INT NULL,
            original_name VARCHAR(255) NOT NULL DEFAULT '',
            stored_name VARCHAR(255) NOT NULL DEFAULT '',
            stored_path VARCHAR(500) NULL,
            local_path VARCHAR(500) NULL,
            local_thumbnail_path VARCHAR(500) NULL,
            storage_type VARCHAR(30) NULL,
            drive_name VARCHAR(255) NULL,
            drive_file_id VARCHAR(128) NULL,
            drive_folder_id VARCHAR(128) NULL,
            drive_web_view_link TEXT NULL,
            drive_web_content_link TEXT NULL,
            mime_type VARCHAR(190) NULL,
            file_size BIGINT NULL,
            section VARCHAR(80) NULL,
            document_type VARCHAR(80) NULL,
            document_year VARCHAR(4) NULL,
            document_month VARCHAR(2) NULL,
            drive_year_folder_id VARCHAR(128) NULL,
            drive_type_folder_id VARCHAR(128) NULL,
            drive_month_folder_id VARCHAR(128) NULL,
            uploaded_by INT NULL,
            uploaded_by_name VARCHAR(100) NULL,
            uploaded_at DATETIME NULL,
            upload_status VARCHAR(30) NULL,
            drive_upload_error TEXT NULL,
            extra_json TEXT NULL,
            created_at DATETIME NOT NULL,
            KEY idx_project_doc (project_id, document_type, document_year, document_month),
            KEY idx_drive_file_id (drive_file_id),
            KEY idx_source (source_table, source_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return true;
    } catch (Exception $e) {
        cpms_drive_log_upload_failure(array(
            'section' => 'construction_schema',
            'message' => 'Construction Drive generic table creation failed: ' . $e->getMessage()
        ));
        return false;
    }
}}

if (!function_exists('cpms_construction_drive_document_info')) {
function cpms_construction_drive_document_info($documentType, $originalName) {
    $type = trim((string)$documentType);
    $originalName = trim((string)$originalName);
    $norm = function_exists('mb_strtolower') ? mb_strtolower($type . ' ' . $originalName, 'UTF-8') : strtolower($type . ' ' . $originalName);
    $map = array(
        'material' => array('folder_key' => 'construction_material', 'folder_label' => cpms_construction_drive_label('material'), 'document_type' => 'material', 'document_label' => cpms_construction_drive_label('material')),
        'statement' => array('folder_key' => 'construction_material', 'folder_label' => cpms_construction_drive_label('material'), 'document_type' => 'material', 'document_label' => cpms_construction_drive_label('material')),
        'material_statement' => array('folder_key' => 'construction_material', 'folder_label' => cpms_construction_drive_label('material'), 'document_type' => 'material', 'document_label' => cpms_construction_drive_label('material')),
        'material_excel' => array('folder_key' => 'construction_material', 'folder_label' => cpms_construction_drive_label('material'), 'document_type' => 'material_excel', 'document_label' => cpms_construction_drive_label('material_excel')),
        'equipment' => array('folder_key' => 'construction_equipment', 'folder_label' => cpms_construction_drive_label('equipment'), 'document_type' => 'equipment', 'document_label' => cpms_construction_drive_label('equipment')),
        'equipment_attachment' => array('folder_key' => 'construction_equipment', 'folder_label' => cpms_construction_drive_label('equipment'), 'document_type' => 'equipment', 'document_label' => cpms_construction_drive_label('equipment')),
        'equipment_excel' => array('folder_key' => 'construction_equipment', 'folder_label' => cpms_construction_drive_label('equipment'), 'document_type' => 'equipment_excel', 'document_label' => cpms_construction_drive_label('equipment_excel')),
        'daily_report' => array('folder_key' => 'construction_daily_report', 'folder_label' => cpms_construction_drive_label('daily_report'), 'document_type' => 'daily_report', 'document_label' => cpms_construction_drive_label('daily_report')),
        'photo' => array('folder_key' => 'construction_photo', 'folder_label' => cpms_construction_drive_label('photo'), 'document_type' => 'photo', 'document_label' => cpms_construction_drive_label('photo')),
        'status' => array('folder_key' => 'construction_status', 'folder_label' => cpms_construction_drive_label('status'), 'document_type' => 'status', 'document_label' => cpms_construction_drive_label('status')),
        'labor' => array('folder_key' => 'construction_labor', 'folder_label' => cpms_construction_drive_label('labor'), 'document_type' => 'labor', 'document_label' => cpms_construction_drive_label('labor')),
        'etc' => array('folder_key' => 'construction_etc', 'folder_label' => cpms_construction_drive_label('etc'), 'document_type' => 'etc', 'document_label' => cpms_construction_drive_label('etc'))
    );
    if (isset($map[$type])) return $map[$type];
    if (strpos($norm, 'excel') !== false || strpos($norm, 'xlsx') !== false || strpos($norm, 'xls') !== false) {
        if (strpos($norm, 'equipment') !== false) return $map['equipment_excel'];
        return $map['material_excel'];
    }
    if (strpos($norm, 'photo') !== false || strpos($norm, 'jpg') !== false || strpos($norm, 'jpeg') !== false || strpos($norm, 'png') !== false || strpos($norm, 'webp') !== false) return $map['photo'];
    return $map['etc'];
}}

if (!function_exists('cpms_construction_drive_folder_aliases')) {
function cpms_construction_drive_folder_aliases($key) {
    $aliases = array(
        'construction_daily_report' => array('construction_daily'),
        'construction_photo' => array('construction_photos'),
        'construction_status' => array('construction_situation'),
        'construction_etc' => array('construction_other')
    );
    return isset($aliases[$key]) ? $aliases[$key] : array();
}}

if (!function_exists('cpms_construction_drive_project_drive_data')) {
function cpms_construction_drive_project_drive_data($project) {
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

if (!function_exists('cpms_construction_drive_folder_id_from_data')) {
function cpms_construction_drive_folder_id_from_data($driveData, $key) {
    if (!is_array($driveData) || !isset($driveData['folders']) || !is_array($driveData['folders'])) return '';
    if (isset($driveData['folders'][$key]) && trim((string)$driveData['folders'][$key]) !== '') return trim((string)$driveData['folders'][$key]);
    $aliases = cpms_construction_drive_folder_aliases($key);
    foreach ($aliases as $alias) {
        if (isset($driveData['folders'][$alias]) && trim((string)$driveData['folders'][$alias]) !== '') return trim((string)$driveData['folders'][$alias]);
    }
    return '';
}}

if (!function_exists('cpms_construction_drive_save_project_data')) {
function cpms_construction_drive_save_project_data($pdo, $projectId, $driveData, $message) {
    if (!$pdo || (int)$projectId <= 0 || !is_array($driveData)) return false;
    $result = array(
        'ok' => true,
        'status' => 'ready',
        'message' => (string)$message,
        'drive' => $driveData
    );
    return cpms_drive_save_project_structure_result($pdo, (int)$projectId, $result);
}}

if (!function_exists('cpms_construction_drive_load_project')) {
function cpms_construction_drive_load_project($pdo, $projectId) {
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

if (!function_exists('cpms_construction_drive_ensure_project_data')) {
function cpms_construction_drive_ensure_project_data($pdo, $project, $userContext) {
    if (!$pdo || !is_array($project) || !isset($project['id'])) {
        return array('ok' => false, 'project' => $project, 'drive' => array(), 'message' => 'Project row is not available.', 'http_code' => 0);
    }
    $projectId = (int)$project['id'];
    $projectName = isset($project['name']) ? (string)$project['name'] : '';
    $driveData = cpms_construction_drive_project_drive_data($project);
    $projectFolderId = isset($driveData['project_folder_id']) ? trim((string)$driveData['project_folder_id']) : '';
    if ($projectFolderId === '') {
        $sync = cpms_drive_sync_project_after_create($pdo, $projectId, $projectName, $userContext, 'construction_upload');
        $driveResult = (isset($sync['drive_result']) && is_array($sync['drive_result'])) ? $sync['drive_result'] : array();
        if (empty($driveResult['ok'])) {
            $message = isset($driveResult['message']) ? (string)$driveResult['message'] : cpms_construction_drive_label('project_sync_required');
            cpms_drive_log_upload_failure(array(
                'user' => $userContext,
                'section' => 'construction',
                'project_id' => $projectId,
                'message' => $message,
                'http_status' => isset($driveResult['http_code']) ? (int)$driveResult['http_code'] : 0,
                'google_response_excerpt' => isset($driveResult['google_response_excerpt']) ? $driveResult['google_response_excerpt'] : ''
            ));
            return array('ok' => false, 'project' => $project, 'drive' => $driveData, 'message' => $message, 'http_code' => isset($driveResult['http_code']) ? (int)$driveResult['http_code'] : 0);
        }
        $fresh = cpms_construction_drive_load_project($pdo, $projectId);
        if (is_array($fresh)) $project = $fresh;
        $driveData = isset($driveResult['drive']) && is_array($driveResult['drive']) ? $driveResult['drive'] : cpms_construction_drive_project_drive_data($project);
        $projectFolderId = isset($driveData['project_folder_id']) ? trim((string)$driveData['project_folder_id']) : '';
    }
    if ($projectFolderId === '') {
        return array('ok' => false, 'project' => $project, 'drive' => $driveData, 'message' => cpms_construction_drive_label('project_sync_required'), 'http_code' => 0);
    }
    if (!isset($driveData['folders']) || !is_array($driveData['folders'])) $driveData['folders'] = array();
    $driveData['project_folder_id'] = $projectFolderId;
    $driveData['folders']['project'] = $projectFolderId;
    return array('ok' => true, 'project' => $project, 'drive' => $driveData, 'message' => 'Project Drive data is ready.', 'http_code' => 0);
}}

if (!function_exists('cpms_construction_drive_parse_month')) {
function cpms_construction_drive_parse_month($value, $fallbackDate) {
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
        if ($usedFallback) $message = cpms_construction_drive_label('month_review') . ': ' . $raw;
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

if (!function_exists('cpms_construction_drive_ensure_folder')) {
function cpms_construction_drive_ensure_folder($name, $parentId, $context) {
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

if (!function_exists('cpms_construction_drive_ensure_target_folder')) {
function cpms_construction_drive_ensure_target_folder($pdo, $projectId, $documentType, $monthValue, $fallbackDate, $userContext, $originalName) {
    $project = cpms_construction_drive_load_project($pdo, $projectId);
    if (!is_array($project)) {
        return array('ok' => false, 'message' => 'Project row was not found.', 'http_code' => 0);
    }
    $ready = cpms_construction_drive_ensure_project_data($pdo, $project, $userContext);
    if (empty($ready['ok'])) return $ready;
    $project = isset($ready['project']) && is_array($ready['project']) ? $ready['project'] : $project;
    $driveData = isset($ready['drive']) && is_array($ready['drive']) ? $ready['drive'] : array();
    if (!isset($driveData['folders']) || !is_array($driveData['folders'])) $driveData['folders'] = array();
    $projectFolderId = isset($driveData['project_folder_id']) ? trim((string)$driveData['project_folder_id']) : '';
    $projectName = isset($project['name']) ? trim((string)$project['name']) : '';

    $docInfo = cpms_construction_drive_document_info($documentType, $originalName);
    $monthInfo = cpms_construction_drive_parse_month($monthValue, $fallbackDate);
    $baseContext = array(
        'user' => $userContext,
        'section' => 'construction',
        'project_id' => (int)$projectId,
        'document_type' => isset($docInfo['document_label']) ? $docInfo['document_label'] : $documentType,
        'document_year' => $monthInfo['year'],
        'document_month' => $monthInfo['month'],
        'original_name' => $originalName
    );
    if (!empty($monthInfo['used_fallback'])) {
        cpms_drive_log_upload_failure(array_merge($baseContext, array('message' => $monthInfo['message'])));
    }
    if ($projectFolderId === '') {
        return array('ok' => false, 'message' => cpms_construction_drive_label('project_sync_required'), 'http_code' => 0);
    }

    $saveNeeded = false;
    $constructionFolderId = cpms_construction_drive_folder_id_from_data($driveData, 'construction');
    if ($constructionFolderId === '') {
        $ctx = $baseContext;
        $ctx['target_folder_id'] = $projectFolderId;
        $ctx['original_name'] = cpms_construction_drive_label('construction_root');
        $folder = cpms_construction_drive_ensure_folder(cpms_construction_drive_label('construction_root'), $projectFolderId, $ctx);
        if (empty($folder['ok'])) return $folder;
        $constructionFolderId = (string)$folder['folder_id'];
        $driveData['folders']['construction'] = $constructionFolderId;
        $saveNeeded = true;
    }

    $folderKey = isset($docInfo['folder_key']) ? (string)$docInfo['folder_key'] : 'construction_etc';
    $folderLabel = isset($docInfo['folder_label']) ? (string)$docInfo['folder_label'] : cpms_construction_drive_label('etc');
    $documentFolderId = cpms_construction_drive_folder_id_from_data($driveData, $folderKey);
    if ($documentFolderId === '') {
        $ctx = $baseContext;
        $ctx['target_folder_id'] = $constructionFolderId;
        $ctx['original_name'] = $folderLabel;
        $folder = cpms_construction_drive_ensure_folder($folderLabel, $constructionFolderId, $ctx);
        if (empty($folder['ok'])) return $folder;
        $documentFolderId = (string)$folder['folder_id'];
        $driveData['folders'][$folderKey] = $documentFolderId;
        $saveNeeded = true;
    }

    $yearContext = $baseContext;
    $yearContext['target_folder_id'] = $documentFolderId;
    $yearContext['original_name'] = (string)$monthInfo['year'];
    $yearFolder = cpms_construction_drive_ensure_folder((string)$monthInfo['year'], $documentFolderId, $yearContext);
    if (empty($yearFolder['ok'])) return $yearFolder;

    $monthContext = $baseContext;
    $monthContext['target_folder_id'] = (string)$yearFolder['folder_id'];
    $monthContext['original_name'] = (string)$monthInfo['month'];
    $monthFolder = cpms_construction_drive_ensure_folder((string)$monthInfo['month'], (string)$yearFolder['folder_id'], $monthContext);
    if (empty($monthFolder['ok'])) return $monthFolder;

    if ($saveNeeded) {
        cpms_construction_drive_save_project_data($pdo, (int)$projectId, $driveData, 'Construction Drive folders prepared.');
    }

    return array(
        'ok' => true,
        'project_name' => $projectName,
        'drive' => $driveData,
        'construction_folder_id' => $constructionFolderId,
        'document_folder_id' => $documentFolderId,
        'year_folder_id' => (string)$yearFolder['folder_id'],
        'month_folder_id' => (string)$monthFolder['folder_id'],
        'folder_id' => (string)$monthFolder['folder_id'],
        'document_info' => $docInfo,
        'month_info' => $monthInfo,
        'message' => 'Construction monthly target folder is ready.',
        'http_code' => isset($monthFolder['http_code']) ? (int)$monthFolder['http_code'] : 0
    );
}}

if (!function_exists('cpms_construction_drive_user_name')) {
function cpms_construction_drive_user_name($userContext) {
    if (is_array($userContext)) {
        if (isset($userContext['name']) && trim((string)$userContext['name']) !== '') return trim((string)$userContext['name']);
        if (isset($userContext['email']) && trim((string)$userContext['email']) !== '') return trim((string)$userContext['email']);
    }
    $label = trim((string)$userContext);
    return $label !== '' ? $label : '-';
}}

if (!function_exists('cpms_construction_drive_build_file_name')) {
function cpms_construction_drive_build_file_name($date, $documentLabel, $projectName, $userName, $originalName) {
    $date = trim((string)$date);
    $ts = $date !== '' ? strtotime($date) : false;
    if ($ts === false) $ts = time();
    $prefix = date('Y-m-d_His', $ts);
    if (date('His', $ts) === '000000') $prefix = date('Y-m-d', $ts) . '_' . date('His');
    $parts = array(
        $prefix,
        cpms_construction_drive_label('construction'),
        trim((string)$documentLabel),
        trim((string)$projectName),
        trim((string)$userName),
        trim((string)$originalName)
    );
    return cpms_drive_sanitize_file_name(implode('_', $parts), 180);
}}

if (!function_exists('cpms_construction_drive_failed_record')) {
function cpms_construction_drive_failed_record($projectId, $originalName, $localPath, $mimeType, $size, $docInfo, $monthInfo, $message) {
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
        'section' => 'construction',
        'document_type' => isset($docInfo['document_type']) ? (string)$docInfo['document_type'] : '',
        'document_year' => isset($monthInfo['year']) ? (string)$monthInfo['year'] : '',
        'document_month' => isset($monthInfo['month']) ? (string)$monthInfo['month'] : '',
        'drive_year_folder_id' => '',
        'drive_type_folder_id' => '',
        'drive_month_folder_id' => '',
        'project_id' => (string)(int)$projectId,
        'storage_type' => 'local',
        'local_backup_path' => (string)$localPath,
        'upload_status' => 'failed',
        'drive_upload_error' => cpms_drive_redact_text((string)$message),
        'uploaded_at' => date('Y-m-d H:i:s')
    );
}}

if (!function_exists('cpms_construction_drive_upload_local_file')) {
function cpms_construction_drive_upload_local_file($pdo, $projectId, $localPath, $originalName, $documentType, $monthValue, $fallbackDate, $extra, $userContext) {
    if (!is_array($extra)) $extra = array();
    $projectId = (int)$projectId;
    $localPath = trim((string)$localPath);
    $originalName = trim((string)$originalName);
    $documentType = trim((string)$documentType);
    $fallbackDate = trim((string)$fallbackDate);
    if ($fallbackDate === '') $fallbackDate = date('Y-m-d H:i:s');
    $mimeType = is_file($localPath) ? cpms_drive_detect_mime_type($localPath) : '';
    $size = is_file($localPath) ? (string)@filesize($localPath) : '';
    $docInfo = cpms_construction_drive_document_info($documentType, $originalName);
    $monthInfo = cpms_construction_drive_parse_month($monthValue, $fallbackDate);
    $context = array(
        'user' => $userContext,
        'uploaded_by' => $userContext,
        'section' => 'construction',
        'project_id' => $projectId,
        'document_type' => isset($docInfo['document_label']) ? $docInfo['document_label'] : $documentType,
        'document_year' => $monthInfo['year'],
        'document_month' => $monthInfo['month'],
        'original_name' => $originalName,
        'mime_type' => $mimeType,
        'size' => $size,
        'local_backup_path' => $localPath
    );
    if (!empty($monthInfo['used_fallback'])) {
        cpms_drive_log_upload_failure(array_merge($context, array('message' => $monthInfo['message'])));
    }
    if ($projectId <= 0 || $localPath === '' || !is_file($localPath)) {
        $message = 'Construction Drive upload skipped because project ID or local file is empty.';
        cpms_drive_log_upload_failure(array_merge($context, array('message' => $message)));
        return array('ok' => false, 'record' => cpms_construction_drive_failed_record($projectId, $originalName, $localPath, $mimeType, $size, $docInfo, $monthInfo, $message), 'message' => $message, 'http_code' => 0);
    }

    $target = cpms_construction_drive_ensure_target_folder($pdo, $projectId, $documentType, $monthValue, $fallbackDate, $userContext, $originalName);
    if (empty($target['ok'])) {
        $message = isset($target['message']) ? (string)$target['message'] : 'Construction Drive target folder prepare failed.';
        cpms_drive_log_upload_failure(array_merge($context, array(
            'message' => $message,
            'http_status' => isset($target['http_code']) ? (int)$target['http_code'] : 0
        )));
        return array('ok' => false, 'record' => cpms_construction_drive_failed_record($projectId, $originalName, $localPath, $mimeType, $size, $docInfo, $monthInfo, $message), 'message' => $message, 'http_code' => isset($target['http_code']) ? (int)$target['http_code'] : 0);
    }

    $docInfo = isset($target['document_info']) && is_array($target['document_info']) ? $target['document_info'] : $docInfo;
    $monthInfo = isset($target['month_info']) && is_array($target['month_info']) ? $target['month_info'] : $monthInfo;
    $projectName = isset($target['project_name']) ? (string)$target['project_name'] : '';
    if (isset($extra['project_name']) && trim((string)$extra['project_name']) !== '') $projectName = (string)$extra['project_name'];
    $date = isset($extra['date']) && trim((string)$extra['date']) !== '' ? trim((string)$extra['date']) : ($monthInfo['year'] . '-' . $monthInfo['month'] . '-' . date('d'));
    $driveName = cpms_construction_drive_build_file_name($date, isset($docInfo['document_label']) ? $docInfo['document_label'] : $documentType, $projectName, cpms_construction_drive_user_name($userContext), $originalName);

    $context['target_folder_id'] = (string)$target['folder_id'];
    $context['drive_folder_id'] = (string)$target['folder_id'];
    $context['document_type'] = isset($docInfo['document_label']) ? $docInfo['document_label'] : $documentType;
    $context['document_year'] = (string)$monthInfo['year'];
    $context['document_month'] = (string)$monthInfo['month'];
    $context['drive_year_folder_id'] = isset($target['year_folder_id']) ? (string)$target['year_folder_id'] : '';
    $context['drive_type_folder_id'] = isset($target['document_folder_id']) ? (string)$target['document_folder_id'] : '';
    $context['drive_month_folder_id'] = isset($target['month_folder_id']) ? (string)$target['month_folder_id'] : '';
    $upload = cpms_drive_upload_file($localPath, $driveName, (string)$target['folder_id'], $mimeType, $context);
    if (empty($upload['ok']) || !isset($upload['file']) || !is_array($upload['file'])) {
        $message = isset($upload['message']) ? (string)$upload['message'] : 'Construction file Drive upload failed.';
        return array('ok' => false, 'record' => cpms_construction_drive_failed_record($projectId, $originalName, $localPath, $mimeType, $size, $docInfo, $monthInfo, $message), 'message' => $message, 'http_code' => isset($upload['http_code']) ? (int)$upload['http_code'] : 0);
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
    $record['upload_status'] = 'uploaded';
    $record['drive_upload_error'] = '';
    $record['local_backup_path'] = $localPath;
    return array('ok' => true, 'record' => $record, 'message' => isset($upload['message']) ? (string)$upload['message'] : '', 'http_code' => isset($upload['http_code']) ? (int)$upload['http_code'] : 0);
}}

if (!function_exists('cpms_construction_drive_record_values')) {
function cpms_construction_drive_record_values($record, $userId) {
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
        'section' => isset($record['section']) ? (string)$record['section'] : 'construction',
        'document_type' => isset($record['document_type']) ? (string)$record['document_type'] : '',
        'document_year' => isset($record['document_year']) ? (string)$record['document_year'] : '',
        'document_month' => isset($record['document_month']) ? (string)$record['document_month'] : '',
        'drive_year_folder_id' => isset($record['drive_year_folder_id']) ? (string)$record['drive_year_folder_id'] : '',
        'drive_type_folder_id' => isset($record['drive_type_folder_id']) ? (string)$record['drive_type_folder_id'] : '',
        'drive_month_folder_id' => isset($record['drive_month_folder_id']) ? (string)$record['drive_month_folder_id'] : '',
        'upload_status' => isset($record['upload_status']) ? (string)$record['upload_status'] : '',
        'drive_upload_error' => isset($record['drive_upload_error']) ? cpms_drive_redact_text((string)$record['drive_upload_error']) : '',
        'uploaded_by' => (int)$userId,
        'uploaded_at' => isset($record['uploaded_at']) ? (string)$record['uploaded_at'] : date('Y-m-d H:i:s')
    );
}}

if (!function_exists('cpms_construction_drive_apply_record_to_row')) {
function cpms_construction_drive_apply_record_to_row($pdo, $table, $id, $record, $userId, $deleteOnFailureContext) {
    $id = (int)$id;
    if (!$pdo || $id <= 0 || trim((string)$table) === '' || !is_array($record)) {
        return array('ok' => false, 'message' => 'Invalid construction Drive row update request.');
    }
    if (!is_array($deleteOnFailureContext)) $deleteOnFailureContext = array();
    cpms_construction_drive_ensure_table_columns($pdo, $table);
    if (!cpms_construction_drive_table_exists($pdo, $table)) {
        return array('ok' => false, 'message' => 'Target table does not exist: ' . $table);
    }
    $values = cpms_construction_drive_record_values($record, $userId);
    $sets = array();
    $params = array(':id' => $id);
    foreach ($values as $column => $value) {
        if (!cpms_construction_drive_column_exists($pdo, $table, $column)) continue;
        $sets[] = '`' . $column . '` = :' . $column;
        $params[':' . $column] = $value;
    }
    if (count($sets) === 0) return array('ok' => false, 'message' => 'No Drive columns are available on ' . $table);
    try {
        $sql = "UPDATE `" . $table . "` SET " . implode(', ', $sets) . " WHERE id = :id";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return array('ok' => true, 'message' => 'Construction Drive record saved.');
    } catch (Exception $e) {
        $message = 'Construction Drive metadata save failed: ' . $e->getMessage();
        $skipDelete = !empty($deleteOnFailureContext['skip_delete_on_failure']);
        if (!$skipDelete && isset($record['drive_file_id']) && trim((string)$record['drive_file_id']) !== '') {
            $context = $deleteOnFailureContext;
            $context['message'] = $message;
            cpms_drive_delete_file((string)$record['drive_file_id'], $context);
        }
        cpms_drive_log_upload_failure(array(
            'section' => 'construction',
            'project_id' => isset($deleteOnFailureContext['project_id']) ? $deleteOnFailureContext['project_id'] : '',
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

if (!function_exists('cpms_construction_drive_insert_generic_record')) {
function cpms_construction_drive_insert_generic_record($pdo, $projectId, $documentType, $originalName, $storedName, $storedPath, $record, $userId, $extra) {
    if (!$pdo) return array('ok' => false, 'id' => 0, 'message' => 'DB is not available.');
    if (!is_array($extra)) $extra = array();
    cpms_construction_drive_ensure_generic_table($pdo);
    if (!cpms_construction_drive_table_exists($pdo, 'cpms_construction_drive_files')) {
        return array('ok' => false, 'id' => 0, 'message' => 'Construction Drive file table is not available.');
    }
    $values = cpms_construction_drive_record_values($record, $userId);
    $map = array(
        'project_id' => (int)$projectId,
        'source_table' => isset($extra['source_table']) ? (string)$extra['source_table'] : '',
        'source_id' => isset($extra['source_id']) ? (int)$extra['source_id'] : null,
        'original_name' => (string)$originalName,
        'stored_name' => (string)$storedName,
        'stored_path' => (string)$storedPath,
        'local_path' => (string)$storedPath,
        'local_thumbnail_path' => isset($extra['local_thumbnail_path']) ? (string)$extra['local_thumbnail_path'] : '',
        'uploaded_by_name' => isset($extra['uploaded_by_name']) ? (string)$extra['uploaded_by_name'] : '',
        'extra_json' => isset($extra['extra_json']) ? (string)$extra['extra_json'] : '',
        'created_at' => date('Y-m-d H:i:s')
    );
    foreach ($values as $column => $value) $map[$column] = $value;
    if (!isset($map['document_type']) || trim((string)$map['document_type']) === '') $map['document_type'] = (string)$documentType;
    $columns = array();
    $holders = array();
    $params = array();
    foreach ($map as $column => $value) {
        if (!cpms_construction_drive_column_exists($pdo, 'cpms_construction_drive_files', $column)) continue;
        $columns[] = '`' . $column . '`';
        $holders[] = ':' . $column;
        $params[':' . $column] = $value;
    }
    if (count($columns) === 0) return array('ok' => false, 'id' => 0, 'message' => 'No insertable construction Drive columns.');
    try {
        $sql = "INSERT INTO cpms_construction_drive_files (" . implode(',', $columns) . ") VALUES (" . implode(',', $holders) . ")";
        $st = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            if ($value === null) $st->bindValue($key, null, PDO::PARAM_NULL);
            else $st->bindValue($key, $value);
        }
        $st->execute();
        return array('ok' => true, 'id' => (int)$pdo->lastInsertId(), 'message' => 'Construction Drive file row saved.');
    } catch (Exception $e) {
        $message = 'Construction Drive file row save failed: ' . $e->getMessage();
        if (is_array($record) && isset($record['drive_file_id']) && trim((string)$record['drive_file_id']) !== '') {
            cpms_drive_delete_file((string)$record['drive_file_id'], array(
                'section' => 'construction',
                'project_id' => (int)$projectId,
                'document_type' => isset($record['document_type']) ? $record['document_type'] : $documentType,
                'document_year' => isset($record['document_year']) ? $record['document_year'] : '',
                'document_month' => isset($record['document_month']) ? $record['document_month'] : '',
                'original_name' => $originalName,
                'target_folder_id' => isset($record['drive_folder_id']) ? $record['drive_folder_id'] : '',
                'message' => $message
            ));
        }
        cpms_drive_log_upload_failure(array(
            'section' => 'construction',
            'project_id' => (int)$projectId,
            'document_type' => isset($record['document_type']) ? $record['document_type'] : $documentType,
            'original_name' => $originalName,
            'target_folder_id' => isset($record['drive_folder_id']) ? $record['drive_folder_id'] : '',
            'message' => $message
        ));
        return array('ok' => false, 'id' => 0, 'message' => $message);
    }
}}

if (!function_exists('cpms_construction_drive_flash_message')) {
function cpms_construction_drive_flash_message($baseMessage, $uploadResult) {
    if (is_array($uploadResult) && !empty($uploadResult['ok'])) return $baseMessage;
    return $baseMessage . ' ' . cpms_construction_drive_label('upload_failed_notice');
}}

if (!function_exists('cpms_construction_drive_normalize_dept')) {
function cpms_construction_drive_normalize_dept($dept) {
    $dept = trim((string)$dept);
    if ($dept === '') return '';
    $map = array(
        urldecode('%EA%B3%B5%EC%82%AC%EB%B6%80') => urldecode('%EA%B3%B5%EC%82%AC'),
        urldecode('%EA%B3%B5%EB%AC%B4%EB%B6%80') => urldecode('%EA%B3%B5%EB%AC%B4'),
        urldecode('%EA%B4%80%EB%A6%AC%EB%B6%80') => urldecode('%EA%B4%80%EB%A6%AC')
    );
    if (isset($map[$dept])) return $map[$dept];
    return $dept;
}}

if (!function_exists('cpms_construction_drive_user_can_view_project')) {
function cpms_construction_drive_user_can_view_project($pdo, $projectId) {
    if (!class_exists('App\\Core\\Auth')) return false;
    if (!\App\Core\Auth::check()) return false;
    if (\App\Core\Auth::isMaster()) return true;
    $role = \App\Core\Auth::userRole();
    if ($role === 'executive') return true;
    if (method_exists('App\\Core\\Auth', 'canAccessConstruction') && \App\Core\Auth::canAccessConstruction()) return true;
    if (method_exists('App\\Core\\Auth', 'canManageEmployees') && \App\Core\Auth::canManageEmployees()) return true;
    $dept = cpms_construction_drive_normalize_dept(\App\Core\Auth::userDepartment());
    if ($dept === urldecode('%EA%B3%B5%EC%82%AC') || $dept === urldecode('%EA%B3%B5%EB%AC%B4') || $dept === urldecode('%EA%B4%80%EB%A6%AC')) return true;
    if (function_exists('cpms_is_project_member_or_executive')) {
        return cpms_is_project_member_or_executive($pdo, (int)$projectId, $role, (string)\App\Core\Auth::userEmail());
    }
    return false;
}}

if (!function_exists('cpms_construction_drive_actions_html')) {
function cpms_construction_drive_actions_html($baseUrl, $row, $canDownload) {
    $row = is_array($row) ? $row : array();
    $storageType = isset($row['storage_type']) ? trim((string)$row['storage_type']) : '';
    $viewLink = isset($row['drive_web_view_link']) ? trim((string)$row['drive_web_view_link']) : '';
    $downloadLink = isset($row['drive_web_content_link']) ? trim((string)$row['drive_web_content_link']) : '';
    $hasLocal = false;
    foreach (array('stored_path', 'file_path', 'local_path') as $pathKey) {
        if (isset($row[$pathKey]) && trim((string)$row[$pathKey]) !== '') $hasLocal = true;
    }
    if ($storageType === 'google_drive') {
        if ($viewLink === '' && $downloadLink === '') {
            return '<span class="inline-flex items-center justify-center px-2 py-1 rounded-lg border border-amber-200 bg-amber-50 text-amber-700 text-xs font-bold">' . cpms_construction_drive_h(cpms_construction_drive_label('file_check_required')) . '</span>';
        }
        if (!$canDownload) return '';
        $html = '<span class="inline-flex flex-wrap gap-2">';
        if ($viewLink !== '') {
            $html .= '<a class="inline-flex items-center justify-center px-2 py-1 rounded-lg border border-blue-200 bg-blue-50 text-blue-700 text-xs font-bold hover:bg-blue-100" href="' . cpms_construction_drive_h($baseUrl . '&view=1') . '" target="_blank" rel="noopener">' . cpms_construction_drive_h(cpms_construction_drive_label('view')) . '</a>';
        }
        if ($downloadLink !== '') {
            $html .= '<a class="inline-flex items-center justify-center px-2 py-1 rounded-lg border border-gray-200 bg-white text-gray-700 text-xs font-bold hover:bg-gray-50" href="' . cpms_construction_drive_h($baseUrl . '&download=1') . '">' . cpms_construction_drive_h(cpms_construction_drive_label('download')) . '</a>';
        }
        $html .= '</span>';
        return $html;
    }
    if ($hasLocal && $canDownload) {
        return '<a class="inline-flex items-center justify-center px-2 py-1 rounded-lg border border-gray-200 bg-white text-gray-700 text-xs font-bold hover:bg-gray-50" href="' . cpms_construction_drive_h($baseUrl . '&download=1') . '">' . cpms_construction_drive_h(cpms_construction_drive_label('download')) . '</a>';
    }
    return '';
}}

if (!function_exists('cpms_construction_drive_select_admin_project')) {
function cpms_construction_drive_select_admin_project($pdo, $projectId) {
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

if (!function_exists('cpms_construction_drive_run_admin_check')) {
function cpms_construction_drive_run_admin_check($pdo, $userContext, $projectId) {
    $result = array(
        'project' => array('ok' => false, 'message' => ''),
        'construction_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'material_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'equipment_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'daily_report_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'photo_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'status_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'labor_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'year_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'month_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'upload' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'delete' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'cleanup' => array('ok' => false, 'message' => ''),
        'test_file' => array()
    );
    $project = cpms_construction_drive_select_admin_project($pdo, $projectId);
    if (!is_array($project)) {
        $result['project']['message'] = 'No project is available for construction Drive check.';
        return $result;
    }
    $selectedProjectId = isset($project['id']) ? (int)$project['id'] : 0;
    $result['project'] = array(
        'ok' => true,
        'id' => $selectedProjectId,
        'name' => isset($project['name']) ? (string)$project['name'] : '',
        'message' => 'Project selected.'
    );
    $target = cpms_construction_drive_ensure_target_folder($pdo, $selectedProjectId, 'material', date('Y-m'), date('Y-m-d'), $userContext, 'construction_drive_check.txt');
    $result['construction_folder'] = array('ok' => !empty($target['construction_folder_id']), 'http_code' => isset($target['http_code']) ? (int)$target['http_code'] : 0, 'message' => !empty($target['construction_folder_id']) ? '03_construction folder is ready.' : (isset($target['message']) ? $target['message'] : 'Folder check failed.'));
    $result['material_folder'] = array('ok' => !empty($target['document_folder_id']), 'http_code' => isset($target['http_code']) ? (int)$target['http_code'] : 0, 'message' => !empty($target['document_folder_id']) ? 'Material folder is ready.' : (isset($target['message']) ? $target['message'] : 'Folder check failed.'));
    $result['year_folder'] = array('ok' => !empty($target['year_folder_id']), 'http_code' => isset($target['http_code']) ? (int)$target['http_code'] : 0, 'message' => !empty($target['year_folder_id']) ? 'Year folder is ready.' : (isset($target['message']) ? $target['message'] : 'Folder check failed.'));
    $result['month_folder'] = array('ok' => !empty($target['month_folder_id']), 'http_code' => isset($target['http_code']) ? (int)$target['http_code'] : 0, 'message' => !empty($target['month_folder_id']) ? 'Month folder is ready.' : (isset($target['message']) ? $target['message'] : 'Folder check failed.'));

    $checkTypes = array('equipment', 'daily_report', 'photo', 'status', 'labor');
    foreach ($checkTypes as $checkType) {
        $subTarget = cpms_construction_drive_ensure_target_folder($pdo, $selectedProjectId, $checkType, date('Y-m'), date('Y-m-d'), $userContext, 'construction_drive_check.txt');
        $key = $checkType . '_folder';
        $result[$key] = array('ok' => !empty($subTarget['document_folder_id']), 'http_code' => isset($subTarget['http_code']) ? (int)$subTarget['http_code'] : 0, 'message' => !empty($subTarget['document_folder_id']) ? $checkType . ' folder is ready.' : (isset($subTarget['message']) ? $subTarget['message'] : 'Folder check failed.'));
    }

    if (!empty($target['ok'])) {
        $tmpDir = cpms_drive_storage_root() . '/tmp/construction_drive_check';
        if (cpms_drive_ensure_dir($tmpDir)) {
            $tmpPath = @tempnam($tmpDir, 'const_drive_');
            if ($tmpPath !== false && @file_put_contents($tmpPath, "CPMS construction Drive check\n" . date('Y-m-d H:i:s') . "\n") !== false) {
                $fileName = 'CPMS_Construction_Check_' . date('Ymd_His') . '.txt';
                $context = array(
                    'user' => $userContext,
                    'section' => 'admin_drive_check_construction',
                    'project_id' => $selectedProjectId,
                    'document_type' => cpms_construction_drive_label('material'),
                    'document_year' => date('Y'),
                    'document_month' => date('m'),
                    'original_name' => $fileName,
                    'target_folder_id' => (string)$target['folder_id']
                );
                $upload = cpms_drive_upload_file($tmpPath, $fileName, (string)$target['folder_id'], 'text/plain', $context);
                $result['upload'] = array(
                    'ok' => !empty($upload['ok']),
                    'http_code' => isset($upload['http_code']) ? (int)$upload['http_code'] : 0,
                    'message' => isset($upload['message']) ? (string)$upload['message'] : ''
                );
                if (!empty($upload['ok']) && isset($upload['file']) && is_array($upload['file'])) {
                    $result['test_file'] = array(
                        'id' => isset($upload['file']['id']) ? (string)$upload['file']['id'] : '',
                        'name' => isset($upload['file']['name']) ? (string)$upload['file']['name'] : '',
                        'webViewLink' => isset($upload['file']['webViewLink']) ? (string)$upload['file']['webViewLink'] : ''
                    );
                    $delete = cpms_drive_delete_file($result['test_file']['id'], $context);
                    $result['delete'] = array(
                        'ok' => !empty($delete['ok']),
                        'http_code' => isset($delete['http_code']) ? (int)$delete['http_code'] : 0,
                        'message' => isset($delete['message']) ? (string)$delete['message'] : ''
                    );
                }
                $result['cleanup'] = array('ok' => @unlink($tmpPath) ? true : false, 'message' => 'Temporary construction check file cleanup attempted.');
            }
        }
    }
    return $result;
}}
