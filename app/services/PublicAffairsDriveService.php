<?php
/**
 * Public affairs project-file Google Drive helpers.
 * PHP 5.6 compatible. Reuses GoogleDriveHelper; does not implement Google auth.
 */

require_once __DIR__ . '/GoogleDriveHelper.php';

if (!function_exists('cpms_public_affairs_drive_h')) {
function cpms_public_affairs_drive_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}}

if (!function_exists('cpms_public_affairs_drive_label')) {
function cpms_public_affairs_drive_label($key) {
    $labels = array(
        'public_affairs' => '%EA%B3%B5%EB%AC%B4',
        'public_affairs_root' => '01_%EA%B3%B5%EB%AC%B4',
        'estimate' => '%EB%82%B4%EC%97%AD%EC%84%9C',
        'contract' => '%EA%B3%84%EC%95%BD%EC%84%9C',
        'site_docs' => '%ED%98%84%EC%84%A4%EC%9E%90%EB%A3%8C',
        'monthly_cost' => '%EC%9B%94%EB%B3%84%ED%88%AC%EC%9E%85%EB%B9%84',
        'progress' => '%EA%B8%B0%EC%84%B1',
        'other' => '%EA%B8%B0%ED%83%80',
        'original_estimate' => '%EB%8B%B9%EC%B4%88%EB%82%B4%EC%97%AD%EC%84%9C',
        'change_estimate' => '%EB%B3%80%EA%B2%BD%EA%B3%84%EC%95%BD%EB%82%B4%EC%97%AD%EC%84%9C',
        'additional_estimate' => '%EC%B6%94%EA%B0%80%EA%B3%B5%EC%82%AC%EB%82%B4%EC%97%AD%EC%84%9C',
        'progress_invoice' => '%EA%B8%B0%EC%84%B1%EC%B2%AD%EA%B5%AC%EC%84%9C',
        'progress_statement' => '%EA%B8%B0%EC%84%B1%EB%82%B4%EC%97%AD%EC%84%9C',
        'progress_attachment' => '%EA%B8%B0%EC%84%B1%EC%B2%A8%EB%B6%80%ED%8C%8C%EC%9D%BC',
        'file_check_required' => '%ED%8C%8C%EC%9D%BC%20%ED%99%95%EC%9D%B8%20%ED%95%84%EC%9A%94',
        'view' => '%EB%B3%B4%EA%B8%B0',
        'download' => '%EB%8B%A4%EC%9A%B4%EB%A1%9C%EB%93%9C'
    );
    return isset($labels[$key]) ? urldecode($labels[$key]) : (string)$key;
}}

if (!function_exists('cpms_public_affairs_drive_table_exists')) {
function cpms_public_affairs_drive_table_exists($pdo, $table) {
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

if (!function_exists('cpms_public_affairs_drive_column_exists')) {
function cpms_public_affairs_drive_column_exists($pdo, $table, $column) {
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

if (!function_exists('cpms_public_affairs_drive_ensure_history_table')) {
function cpms_public_affairs_drive_ensure_history_table($pdo) {
    if (!$pdo) return false;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_project_contract_change_files (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            original_name VARCHAR(255) NOT NULL DEFAULT '',
            stored_name VARCHAR(255) NOT NULL DEFAULT '',
            stored_path VARCHAR(500) NOT NULL DEFAULT '',
            file_type VARCHAR(50) NULL,
            uploaded_by INT NULL,
            uploaded_at DATETIME NULL,
            applied_token VARCHAR(100) NULL,
            change_summary TEXT NULL,
            KEY idx_project (project_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return true;
    } catch (Exception $e) {
        cpms_drive_log_upload_failure(array(
            'section' => 'public_affairs_schema',
            'message' => 'Public affairs history table creation failed: ' . $e->getMessage()
        ));
        return false;
    }
}}

if (!function_exists('cpms_public_affairs_drive_column_sql')) {
function cpms_public_affairs_drive_column_sql() {
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
        'drive_month_folder_id' => "ALTER TABLE `%s` ADD COLUMN drive_month_folder_id VARCHAR(128) NULL",
        'uploaded_by' => "ALTER TABLE `%s` ADD COLUMN uploaded_by INT NULL",
        'uploaded_at' => "ALTER TABLE `%s` ADD COLUMN uploaded_at DATETIME NULL",
        'upload_status' => "ALTER TABLE `%s` ADD COLUMN upload_status VARCHAR(30) NULL",
        'drive_upload_error' => "ALTER TABLE `%s` ADD COLUMN drive_upload_error TEXT NULL"
    );
}}

if (!function_exists('cpms_public_affairs_drive_ensure_table_columns')) {
function cpms_public_affairs_drive_ensure_table_columns($pdo, $table) {
    if (!$pdo || trim((string)$table) === '') return false;
    if ($table === 'cpms_project_contract_change_files') {
        cpms_public_affairs_drive_ensure_history_table($pdo);
    }
    if (!cpms_public_affairs_drive_table_exists($pdo, $table)) return false;

    $columns = cpms_public_affairs_drive_column_sql();
    $ok = true;
    foreach ($columns as $column => $sqlTpl) {
        if (cpms_public_affairs_drive_column_exists($pdo, $table, $column)) continue;
        try {
            $pdo->exec(sprintf($sqlTpl, $table));
        } catch (Exception $e) {
            $ok = false;
            cpms_drive_log_upload_failure(array(
                'section' => 'public_affairs_schema',
                'message' => 'Public affairs Drive column creation failed: ' . $table . '.' . $column . ' / ' . $e->getMessage()
            ));
        }
    }
    return $ok;
}}

if (!function_exists('cpms_public_affairs_drive_document_info')) {
function cpms_public_affairs_drive_document_info($documentType, $originalName) {
    $type = trim((string)$documentType);
    $originalName = trim((string)$originalName);
    $norm = function_exists('mb_strtolower') ? mb_strtolower($type . ' ' . $originalName, 'UTF-8') : strtolower($type . ' ' . $originalName);

    $map = array(
        'contract_only' => array('folder_key' => 'public_affairs_contract', 'folder_label' => cpms_public_affairs_drive_label('contract'), 'document_type' => 'contract', 'document_label' => cpms_public_affairs_drive_label('contract')),
        'contract' => array('folder_key' => 'public_affairs_contract', 'folder_label' => cpms_public_affairs_drive_label('contract'), 'document_type' => 'contract', 'document_label' => cpms_public_affairs_drive_label('contract')),
        'unit_price_original' => array('folder_key' => 'public_affairs_estimate', 'folder_label' => cpms_public_affairs_drive_label('estimate'), 'document_type' => 'unit_price_original', 'document_label' => cpms_public_affairs_drive_label('original_estimate')),
        'original_estimate' => array('folder_key' => 'public_affairs_estimate', 'folder_label' => cpms_public_affairs_drive_label('estimate'), 'document_type' => 'unit_price_original', 'document_label' => cpms_public_affairs_drive_label('original_estimate')),
        'unit_price_update' => array('folder_key' => 'public_affairs_estimate', 'folder_label' => cpms_public_affairs_drive_label('estimate'), 'document_type' => 'unit_price_update', 'document_label' => cpms_public_affairs_drive_label('change_estimate')),
        'change_estimate' => array('folder_key' => 'public_affairs_estimate', 'folder_label' => cpms_public_affairs_drive_label('estimate'), 'document_type' => 'unit_price_update', 'document_label' => cpms_public_affairs_drive_label('change_estimate')),
        'additional_work_estimate' => array('folder_key' => 'public_affairs_estimate', 'folder_label' => cpms_public_affairs_drive_label('estimate'), 'document_type' => 'additional_work_estimate', 'document_label' => cpms_public_affairs_drive_label('additional_estimate')),
        'site_docs' => array('folder_key' => 'public_affairs_site_docs', 'folder_label' => cpms_public_affairs_drive_label('site_docs'), 'document_type' => 'site_docs', 'document_label' => cpms_public_affairs_drive_label('site_docs')),
        'monthly_cost' => array('folder_key' => 'public_affairs_monthly_cost', 'folder_label' => cpms_public_affairs_drive_label('monthly_cost'), 'document_type' => 'monthly_cost', 'document_label' => cpms_public_affairs_drive_label('monthly_cost')),
        'progress_payment' => array('folder_key' => 'public_affairs_progress', 'folder_label' => cpms_public_affairs_drive_label('progress'), 'document_type' => 'progress_payment', 'document_label' => cpms_public_affairs_drive_label('progress')),
        'progress_invoice' => array('folder_key' => 'public_affairs_progress', 'folder_label' => cpms_public_affairs_drive_label('progress'), 'document_type' => 'progress_invoice', 'document_label' => cpms_public_affairs_drive_label('progress_invoice')),
        'progress_statement' => array('folder_key' => 'public_affairs_progress', 'folder_label' => cpms_public_affairs_drive_label('progress'), 'document_type' => 'progress_statement', 'document_label' => cpms_public_affairs_drive_label('progress_statement')),
        'progress_attachment' => array('folder_key' => 'public_affairs_progress', 'folder_label' => cpms_public_affairs_drive_label('progress'), 'document_type' => 'progress_attachment', 'document_label' => cpms_public_affairs_drive_label('progress_attachment'))
    );

    if (isset($map[$type])) {
        $info = $map[$type];
    } else {
        $info = array('folder_key' => 'public_affairs_other', 'folder_label' => cpms_public_affairs_drive_label('other'), 'document_type' => 'public_affairs_other', 'document_label' => cpms_public_affairs_drive_label('other'));
    }

    if ($type === 'progress_attachment') {
        if (strpos($norm, urldecode('%EC%B2%AD%EA%B5%AC')) !== false) {
            $info['document_type'] = 'progress_invoice';
            $info['document_label'] = cpms_public_affairs_drive_label('progress_invoice');
        } else if (strpos($norm, urldecode('%EB%82%B4%EC%97%AD')) !== false) {
            $info['document_type'] = 'progress_statement';
            $info['document_label'] = cpms_public_affairs_drive_label('progress_statement');
        }
    }

    return $info;
}}

if (!function_exists('cpms_public_affairs_drive_folder_aliases')) {
function cpms_public_affairs_drive_folder_aliases($key) {
    $aliases = array(
        'public_affairs_site_docs' => array('public_affairs_site_briefing'),
        'public_affairs_monthly_cost' => array('public_affairs_monthly_input'),
        'public_affairs_progress' => array('public_affairs_progress_payment', 'public_affairs_progress_docs')
    );
    return isset($aliases[$key]) ? $aliases[$key] : array();
}}

if (!function_exists('cpms_public_affairs_drive_project_drive_data')) {
function cpms_public_affairs_drive_project_drive_data($project) {
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

if (!function_exists('cpms_public_affairs_drive_folder_id_from_data')) {
function cpms_public_affairs_drive_folder_id_from_data($driveData, $key) {
    if (!is_array($driveData) || !isset($driveData['folders']) || !is_array($driveData['folders'])) return '';
    if (isset($driveData['folders'][$key]) && trim((string)$driveData['folders'][$key]) !== '') {
        return trim((string)$driveData['folders'][$key]);
    }
    $aliases = cpms_public_affairs_drive_folder_aliases($key);
    foreach ($aliases as $alias) {
        if (isset($driveData['folders'][$alias]) && trim((string)$driveData['folders'][$alias]) !== '') {
            return trim((string)$driveData['folders'][$alias]);
        }
    }
    return '';
}}

if (!function_exists('cpms_public_affairs_drive_save_project_data')) {
function cpms_public_affairs_drive_save_project_data($pdo, $projectId, $driveData, $message) {
    if (!$pdo || (int)$projectId <= 0 || !is_array($driveData)) return false;
    $result = array(
        'ok' => true,
        'status' => 'ready',
        'message' => (string)$message,
        'drive' => $driveData
    );
    return cpms_drive_save_project_structure_result($pdo, (int)$projectId, $result);
}}

if (!function_exists('cpms_public_affairs_drive_load_project')) {
function cpms_public_affairs_drive_load_project($pdo, $projectId) {
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

if (!function_exists('cpms_public_affairs_drive_ensure_project_data')) {
function cpms_public_affairs_drive_ensure_project_data($pdo, $project, $userContext) {
    if (!$pdo || !is_array($project) || !isset($project['id'])) {
        return array('ok' => false, 'project' => $project, 'drive' => array(), 'message' => 'Project row is not available.', 'http_code' => 0);
    }
    $projectId = (int)$project['id'];
    $projectName = isset($project['name']) ? (string)$project['name'] : '';
    $driveData = cpms_public_affairs_drive_project_drive_data($project);
    $projectFolderId = isset($driveData['project_folder_id']) ? trim((string)$driveData['project_folder_id']) : '';

    if ($projectFolderId === '') {
        $sync = cpms_drive_sync_project_after_create($pdo, $projectId, $projectName, $userContext, 'public_affairs_upload');
        $driveResult = (isset($sync['drive_result']) && is_array($sync['drive_result'])) ? $sync['drive_result'] : array();
        if (empty($driveResult['ok'])) {
            $message = isset($driveResult['message']) ? (string)$driveResult['message'] : 'Project Drive folder sync failed.';
            cpms_drive_log_upload_failure(array(
                'user' => $userContext,
                'section' => 'public_affairs',
                'project_id' => $projectId,
                'message' => $message,
                'http_status' => isset($driveResult['http_code']) ? (int)$driveResult['http_code'] : 0,
                'google_response_excerpt' => isset($driveResult['google_response_excerpt']) ? $driveResult['google_response_excerpt'] : ''
            ));
            return array('ok' => false, 'project' => $project, 'drive' => $driveData, 'message' => $message, 'http_code' => isset($driveResult['http_code']) ? (int)$driveResult['http_code'] : 0);
        }
        $fresh = cpms_public_affairs_drive_load_project($pdo, $projectId);
        if (is_array($fresh)) $project = $fresh;
        $driveData = isset($driveResult['drive']) && is_array($driveResult['drive']) ? $driveResult['drive'] : cpms_public_affairs_drive_project_drive_data($project);
        $projectFolderId = isset($driveData['project_folder_id']) ? trim((string)$driveData['project_folder_id']) : '';
    }

    if ($projectFolderId === '') {
        return array('ok' => false, 'project' => $project, 'drive' => $driveData, 'message' => 'Project Drive folder ID is empty.', 'http_code' => 0);
    }
    if (!isset($driveData['folders']) || !is_array($driveData['folders'])) $driveData['folders'] = array();
    $driveData['project_folder_id'] = $projectFolderId;
    $driveData['folders']['project'] = $projectFolderId;
    return array('ok' => true, 'project' => $project, 'drive' => $driveData, 'message' => 'Project Drive data is ready.', 'http_code' => 0);
}}

if (!function_exists('cpms_public_affairs_drive_parse_month')) {
function cpms_public_affairs_drive_parse_month($value, $fallbackDate) {
    $raw = trim((string)$value);
    $fallbackDate = trim((string)$fallbackDate);
    $year = '';
    $month = '';
    $usedFallback = false;
    $message = '';

    if ($raw !== '') {
        if (preg_match('/(\d{4})\D{0,3}(\d{1,2})/u', $raw, $m)) {
            $year = (string)$m[1];
            $month = sprintf('%02d', (int)$m[2]);
        }
    }

    if ($year === '' || $month === '' || (int)$month < 1 || (int)$month > 12) {
        $ts = false;
        if ($fallbackDate !== '') $ts = strtotime($fallbackDate);
        if ($ts === false) $ts = time();
        $year = date('Y', $ts);
        $month = date('m', $ts);
        $usedFallback = ($raw !== '');
        if ($usedFallback) $message = 'Month value needs review: ' . $raw;
    }

    return array(
        'year' => $year,
        'month' => $month,
        'ym' => $year . '-' . $month,
        'used_fallback' => $usedFallback,
        'message' => $message
    );
}}

if (!function_exists('cpms_public_affairs_drive_ensure_folder')) {
function cpms_public_affairs_drive_ensure_folder($name, $parentId, $context) {
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

if (!function_exists('cpms_public_affairs_drive_ensure_target_folder')) {
function cpms_public_affairs_drive_ensure_target_folder($pdo, $projectId, $documentType, $monthValue, $fallbackDate, $userContext, $originalName) {
    $project = cpms_public_affairs_drive_load_project($pdo, $projectId);
    if (!is_array($project)) {
        return array('ok' => false, 'message' => 'Project row was not found.', 'http_code' => 0);
    }
    $ready = cpms_public_affairs_drive_ensure_project_data($pdo, $project, $userContext);
    if (empty($ready['ok'])) return $ready;
    $project = isset($ready['project']) && is_array($ready['project']) ? $ready['project'] : $project;
    $driveData = isset($ready['drive']) && is_array($ready['drive']) ? $ready['drive'] : array();
    if (!isset($driveData['folders']) || !is_array($driveData['folders'])) $driveData['folders'] = array();
    $projectFolderId = isset($driveData['project_folder_id']) ? trim((string)$driveData['project_folder_id']) : '';

    $docInfo = cpms_public_affairs_drive_document_info($documentType, $originalName);
    $monthInfo = cpms_public_affairs_drive_parse_month($monthValue, $fallbackDate);
    $baseContext = array(
        'user' => $userContext,
        'section' => 'public_affairs',
        'project_id' => $projectId,
        'document_type' => isset($docInfo['document_label']) ? $docInfo['document_label'] : $documentType,
        'original_name' => $originalName
    );

    if (!empty($monthInfo['used_fallback'])) {
        cpms_drive_log_upload_failure(array_merge($baseContext, array(
            'message' => $monthInfo['message']
        )));
    }

    $publicAffairsId = cpms_public_affairs_drive_folder_id_from_data($driveData, 'public_affairs');
    if ($publicAffairsId === '') {
        $context = array_merge($baseContext, array('target_folder_id' => $projectFolderId));
        $folder = cpms_public_affairs_drive_ensure_folder(cpms_public_affairs_drive_label('public_affairs_root'), $projectFolderId, $context);
        if (empty($folder['ok'])) return $folder;
        $publicAffairsId = $folder['folder_id'];
        $driveData['folders']['public_affairs'] = $publicAffairsId;
    }

    $childKey = isset($docInfo['folder_key']) ? (string)$docInfo['folder_key'] : 'public_affairs_other';
    $childLabel = isset($docInfo['folder_label']) ? (string)$docInfo['folder_label'] : cpms_public_affairs_drive_label('other');
    $childId = cpms_public_affairs_drive_folder_id_from_data($driveData, $childKey);
    if ($childId === '') {
        $context = array_merge($baseContext, array('target_folder_id' => $publicAffairsId));
        $folder = cpms_public_affairs_drive_ensure_folder($childLabel, $publicAffairsId, $context);
        if (empty($folder['ok'])) return $folder;
        $childId = $folder['folder_id'];
        $driveData['folders'][$childKey] = $childId;
    }

    $yearKey = $childKey . '_' . $monthInfo['year'];
    $yearId = cpms_public_affairs_drive_folder_id_from_data($driveData, $yearKey);
    if ($yearId === '') {
        $context = array_merge($baseContext, array('target_folder_id' => $childId));
        $folder = cpms_public_affairs_drive_ensure_folder($monthInfo['year'], $childId, $context);
        if (empty($folder['ok'])) return $folder;
        $yearId = $folder['folder_id'];
        $driveData['folders'][$yearKey] = $yearId;
    }

    $monthKey = $yearKey . '_' . $monthInfo['month'];
    $monthId = cpms_public_affairs_drive_folder_id_from_data($driveData, $monthKey);
    if ($monthId === '') {
        $context = array_merge($baseContext, array('target_folder_id' => $yearId));
        $folder = cpms_public_affairs_drive_ensure_folder($monthInfo['month'], $yearId, $context);
        if (empty($folder['ok'])) return $folder;
        $monthId = $folder['folder_id'];
        $driveData['folders'][$monthKey] = $monthId;
    }

    cpms_public_affairs_drive_save_project_data($pdo, $projectId, $driveData, 'Public affairs Drive monthly folders updated.');

    return array(
        'ok' => true,
        'project' => $project,
        'project_name' => isset($project['name']) ? (string)$project['name'] : '',
        'drive' => $driveData,
        'document_info' => $docInfo,
        'month_info' => $monthInfo,
        'public_affairs_folder_id' => $publicAffairsId,
        'document_folder_id' => $childId,
        'year_folder_id' => $yearId,
        'month_folder_id' => $monthId,
        'folder_id' => $monthId,
        'message' => 'Public affairs Drive target folder is ready.',
        'http_code' => 0
    );
}}

if (!function_exists('cpms_public_affairs_drive_user_name')) {
function cpms_public_affairs_drive_user_name($userContext) {
    if (is_array($userContext)) {
        if (isset($userContext['name']) && trim((string)$userContext['name']) !== '') return trim((string)$userContext['name']);
        if (isset($userContext['email']) && trim((string)$userContext['email']) !== '') return trim((string)$userContext['email']);
    }
    $label = cpms_drive_user_label($userContext);
    return $label !== '' ? $label : '-';
}}

if (!function_exists('cpms_public_affairs_drive_build_file_name')) {
function cpms_public_affairs_drive_build_file_name($date, $documentLabel, $projectName, $userName, $originalName) {
    $date = trim((string)$date);
    if ($date === '') $date = date('Y-m-d');
    $parts = array(
        $date,
        cpms_public_affairs_drive_label('public_affairs'),
        trim((string)$documentLabel),
        trim((string)$projectName),
        trim((string)$userName),
        date('His') . '_' . mt_rand(1000, 9999),
        trim((string)$originalName)
    );
    return cpms_drive_sanitize_file_name(implode('_', $parts), 180);
}}

if (!function_exists('cpms_public_affairs_drive_failed_record')) {
function cpms_public_affairs_drive_failed_record($originalName, $localPath, $mimeType, $size, $documentInfo, $monthInfo, $message) {
    return array(
        'original_name' => (string)$originalName,
        'stored_name' => '',
        'drive_file_id' => '',
        'drive_folder_id' => '',
        'drive_web_view_link' => '',
        'drive_web_content_link' => '',
        'mime_type' => (string)$mimeType,
        'size' => (string)$size,
        'section' => 'public_affairs',
        'document_type' => isset($documentInfo['document_type']) ? (string)$documentInfo['document_type'] : '',
        'document_year' => isset($monthInfo['year']) ? (string)$monthInfo['year'] : '',
        'document_month' => isset($monthInfo['month']) ? (string)$monthInfo['month'] : '',
        'drive_year_folder_id' => '',
        'drive_month_folder_id' => '',
        'storage_type' => 'local',
        'local_backup_path' => (string)$localPath,
        'upload_status' => 'failed',
        'drive_upload_error' => cpms_drive_redact_text((string)$message),
        'uploaded_at' => date('Y-m-d H:i:s')
    );
}}

if (!function_exists('cpms_public_affairs_drive_upload_local_file')) {
function cpms_public_affairs_drive_upload_local_file($pdo, $projectId, $localPath, $originalName, $documentType, $monthValue, $fallbackDate, $extra, $userContext) {
    $extra = is_array($extra) ? $extra : array();
    $localPath = trim((string)$localPath);
    $originalName = trim((string)$originalName);
    $mimeType = cpms_drive_detect_mime_type($localPath);
    $size = is_file($localPath) ? (int)@filesize($localPath) : 0;
    $docInfo = cpms_public_affairs_drive_document_info($documentType, $originalName);
    $monthInfo = cpms_public_affairs_drive_parse_month($monthValue, $fallbackDate);

    $context = array(
        'user' => $userContext,
        'uploaded_by' => $userContext,
        'section' => 'public_affairs',
        'project_id' => (int)$projectId,
        'document_type' => isset($docInfo['document_label']) ? $docInfo['document_label'] : $documentType,
        'original_name' => $originalName,
        'mime_type' => $mimeType,
        'size' => $size,
        'local_backup_path' => $localPath
    );

    if ($localPath === '' || !is_file($localPath)) {
        $message = 'Local public affairs file is not available for Drive upload.';
        cpms_drive_log_upload_failure(array_merge($context, array('message' => $message)));
        return array('ok' => false, 'record' => cpms_public_affairs_drive_failed_record($originalName, $localPath, $mimeType, $size, $docInfo, $monthInfo, $message), 'message' => $message, 'http_code' => 0);
    }

    $target = cpms_public_affairs_drive_ensure_target_folder($pdo, $projectId, $documentType, $monthValue, $fallbackDate, $userContext, $originalName);
    if (empty($target['ok'])) {
        $message = isset($target['message']) ? $target['message'] : 'Public affairs Drive folder preparation failed.';
        cpms_drive_log_upload_failure(array_merge($context, array(
            'message' => $message,
            'http_status' => isset($target['http_code']) ? (int)$target['http_code'] : 0
        )));
        return array('ok' => false, 'record' => cpms_public_affairs_drive_failed_record($originalName, $localPath, $mimeType, $size, $docInfo, $monthInfo, $message), 'message' => $message, 'http_code' => isset($target['http_code']) ? (int)$target['http_code'] : 0);
    }

    $docInfo = isset($target['document_info']) && is_array($target['document_info']) ? $target['document_info'] : $docInfo;
    $monthInfo = isset($target['month_info']) && is_array($target['month_info']) ? $target['month_info'] : $monthInfo;
    $projectName = isset($target['project_name']) ? (string)$target['project_name'] : (isset($extra['project_name']) ? (string)$extra['project_name'] : '');
    $date = $monthInfo['year'] . '-' . $monthInfo['month'] . '-' . date('d');
    if (isset($extra['date']) && trim((string)$extra['date']) !== '') $date = trim((string)$extra['date']);
    $driveName = cpms_public_affairs_drive_build_file_name($date, isset($docInfo['document_label']) ? $docInfo['document_label'] : $documentType, $projectName, cpms_public_affairs_drive_user_name($userContext), $originalName);

    $context['target_folder_id'] = (string)$target['folder_id'];
    $context['document_type'] = isset($docInfo['document_label']) ? $docInfo['document_label'] : $documentType;
    $upload = cpms_drive_upload_file($localPath, $driveName, (string)$target['folder_id'], $mimeType, $context);
    if (empty($upload['ok']) || !isset($upload['file']) || !is_array($upload['file'])) {
        $message = isset($upload['message']) ? $upload['message'] : 'Public affairs file Drive upload failed.';
        return array('ok' => false, 'record' => cpms_public_affairs_drive_failed_record($originalName, $localPath, $mimeType, $size, $docInfo, $monthInfo, $message), 'message' => $message, 'http_code' => isset($upload['http_code']) ? (int)$upload['http_code'] : 0);
    }

    $context['uploaded_at'] = date('Y-m-d H:i:s');
    $record = cpms_drive_build_file_record($upload['file'], $context);
    $record['document_type'] = isset($docInfo['document_type']) ? (string)$docInfo['document_type'] : (string)$documentType;
    $record['document_year'] = (string)$monthInfo['year'];
    $record['document_month'] = (string)$monthInfo['month'];
    $record['drive_year_folder_id'] = isset($target['year_folder_id']) ? (string)$target['year_folder_id'] : '';
    $record['drive_month_folder_id'] = isset($target['month_folder_id']) ? (string)$target['month_folder_id'] : '';
    $record['upload_status'] = 'uploaded';
    $record['drive_upload_error'] = '';
    $record['local_backup_path'] = $localPath;

    return array('ok' => true, 'record' => $record, 'message' => isset($upload['message']) ? $upload['message'] : '', 'http_code' => isset($upload['http_code']) ? (int)$upload['http_code'] : 0);
}}

if (!function_exists('cpms_public_affairs_drive_record_values')) {
function cpms_public_affairs_drive_record_values($record, $userId) {
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
        'section' => isset($record['section']) ? (string)$record['section'] : 'public_affairs',
        'document_type' => isset($record['document_type']) ? (string)$record['document_type'] : '',
        'document_year' => isset($record['document_year']) ? (string)$record['document_year'] : '',
        'document_month' => isset($record['document_month']) ? (string)$record['document_month'] : '',
        'drive_year_folder_id' => isset($record['drive_year_folder_id']) ? (string)$record['drive_year_folder_id'] : '',
        'drive_month_folder_id' => isset($record['drive_month_folder_id']) ? (string)$record['drive_month_folder_id'] : '',
        'upload_status' => isset($record['upload_status']) ? (string)$record['upload_status'] : '',
        'drive_upload_error' => isset($record['drive_upload_error']) ? cpms_drive_redact_text((string)$record['drive_upload_error']) : '',
        'uploaded_by' => (int)$userId,
        'uploaded_at' => isset($record['uploaded_at']) ? (string)$record['uploaded_at'] : date('Y-m-d H:i:s')
    );
}}

if (!function_exists('cpms_public_affairs_drive_apply_record_to_row')) {
function cpms_public_affairs_drive_apply_record_to_row($pdo, $table, $id, $record, $userId, $deleteOnFailureContext) {
    $id = (int)$id;
    if (!$pdo || $id <= 0 || trim((string)$table) === '' || !is_array($record)) {
        return array('ok' => false, 'message' => 'Invalid Drive row update request.');
    }
    if (!is_array($deleteOnFailureContext)) {
        $deleteOnFailureContext = array();
    }
    cpms_public_affairs_drive_ensure_table_columns($pdo, $table);
    if (!cpms_public_affairs_drive_table_exists($pdo, $table)) {
        return array('ok' => false, 'message' => 'Target table does not exist: ' . $table);
    }

    $values = cpms_public_affairs_drive_record_values($record, $userId);
    $sets = array();
    $params = array(':id' => $id);
    foreach ($values as $column => $value) {
        if (!cpms_public_affairs_drive_column_exists($pdo, $table, $column)) continue;
        $sets[] = '`' . $column . '` = :' . $column;
        $params[':' . $column] = $value;
    }
    if (count($sets) === 0) return array('ok' => false, 'message' => 'No Drive columns are available on ' . $table);

    try {
        $sql = "UPDATE `" . $table . "` SET " . implode(', ', $sets) . " WHERE id = :id";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return array('ok' => true, 'message' => 'Drive record saved.');
    } catch (Exception $e) {
        $message = 'Drive metadata save failed: ' . $e->getMessage();
        $skipDelete = (is_array($deleteOnFailureContext) && !empty($deleteOnFailureContext['skip_delete_on_failure']));
        if (!$skipDelete && isset($record['drive_file_id']) && trim((string)$record['drive_file_id']) !== '') {
            $context = is_array($deleteOnFailureContext) ? $deleteOnFailureContext : array();
            $context['message'] = $message;
            cpms_drive_delete_file((string)$record['drive_file_id'], $context);
        }
        cpms_drive_log_upload_failure(array(
            'section' => 'public_affairs',
            'project_id' => isset($deleteOnFailureContext['project_id']) ? $deleteOnFailureContext['project_id'] : '',
            'document_type' => isset($record['document_type']) ? $record['document_type'] : '',
            'original_name' => isset($record['original_name']) ? $record['original_name'] : '',
            'target_folder_id' => isset($record['drive_folder_id']) ? $record['drive_folder_id'] : '',
            'message' => $message
        ));
        return array('ok' => false, 'message' => $message);
    }
}}

if (!function_exists('cpms_public_affairs_drive_insert_history_record')) {
function cpms_public_affairs_drive_insert_history_record($pdo, $projectId, $fileType, $originalName, $storedName, $storedPath, $summary, $record, $userId) {
    if (!$pdo) return array('ok' => false, 'id' => 0, 'message' => 'DB is not available.');
    cpms_public_affairs_drive_ensure_history_table($pdo);
    cpms_public_affairs_drive_ensure_table_columns($pdo, 'cpms_project_contract_change_files');
    if (!cpms_public_affairs_drive_table_exists($pdo, 'cpms_project_contract_change_files')) {
        return array('ok' => false, 'id' => 0, 'message' => 'History table is not available.');
    }

    $map = array(
        'project_id' => (int)$projectId,
        'original_name' => (string)$originalName,
        'stored_name' => (string)$storedName,
        'stored_path' => (string)$storedPath,
        'file_type' => (string)$fileType,
        'uploaded_by' => (int)$userId,
        'uploaded_at' => date('Y-m-d H:i:s'),
        'applied_token' => '',
        'change_summary' => cpms_drive_json_encode($summary)
    );
    $driveValues = cpms_public_affairs_drive_record_values($record, $userId);
    foreach ($driveValues as $column => $value) {
        $map[$column] = $value;
    }

    $columns = array();
    $holders = array();
    $params = array();
    foreach ($map as $column => $value) {
        if (!cpms_public_affairs_drive_column_exists($pdo, 'cpms_project_contract_change_files', $column)) continue;
        $columns[] = '`' . $column . '`';
        $holders[] = ':' . $column;
        $params[':' . $column] = $value;
    }
    if (count($columns) === 0) return array('ok' => false, 'id' => 0, 'message' => 'No insertable history columns.');

    try {
        $sql = "INSERT INTO cpms_project_contract_change_files (" . implode(',', $columns) . ") VALUES (" . implode(',', $holders) . ")";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return array('ok' => true, 'id' => (int)$pdo->lastInsertId(), 'message' => 'History row saved.');
    } catch (Exception $e) {
        $message = 'History Drive metadata save failed: ' . $e->getMessage();
        if (is_array($record) && isset($record['drive_file_id']) && trim((string)$record['drive_file_id']) !== '') {
            cpms_drive_delete_file((string)$record['drive_file_id'], array(
                'section' => 'public_affairs',
                'project_id' => (int)$projectId,
                'document_type' => isset($record['document_type']) ? $record['document_type'] : '',
                'original_name' => $originalName,
                'target_folder_id' => isset($record['drive_folder_id']) ? $record['drive_folder_id'] : '',
                'message' => $message
            ));
        }
        cpms_drive_log_upload_failure(array(
            'section' => 'public_affairs',
            'project_id' => (int)$projectId,
            'document_type' => isset($record['document_type']) ? $record['document_type'] : '',
            'original_name' => $originalName,
            'target_folder_id' => isset($record['drive_folder_id']) ? $record['drive_folder_id'] : '',
            'message' => $message
        ));
        return array('ok' => false, 'id' => 0, 'message' => $message);
    }
}}

if (!function_exists('cpms_public_affairs_drive_flash_message')) {
function cpms_public_affairs_drive_flash_message($baseMessage, $uploadResult) {
    if (is_array($uploadResult) && !empty($uploadResult['ok'])) return $baseMessage;
    return $baseMessage . ' ' . urldecode('%ED%8C%8C%EC%9D%BC%EC%9D%80%20%EC%A0%80%EC%9E%A5%EB%90%98%EC%97%88%EC%A7%80%EB%A7%8C%20Google%20Drive%20%EC%97%85%EB%A1%9C%EB%93%9C%EC%97%90%20%EC%8B%A4%ED%8C%A8%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.%20%EA%B4%80%EB%A6%AC%EC%9E%90%EC%97%90%EA%B2%8C%20%EB%AC%B8%EC%9D%98%ED%95%B4%EC%A3%BC%EC%84%B8%EC%9A%94.');
}}

if (!function_exists('cpms_public_affairs_drive_normalize_dept')) {
function cpms_public_affairs_drive_normalize_dept($dept) {
    $dept = trim((string)$dept);
    $map = array(
        urldecode('%EA%B4%80%EB%A6%AC%EB%B6%80') => urldecode('%EA%B4%80%EB%A6%AC'),
        urldecode('%EA%B3%B5%EB%AC%B4%EB%B6%80') => urldecode('%EA%B3%B5%EB%AC%B4'),
        urldecode('%EA%B3%B5%EC%82%AC%EB%B6%80') => urldecode('%EA%B3%B5%EC%82%AC')
    );
    return isset($map[$dept]) ? $map[$dept] : $dept;
}}

if (!function_exists('cpms_public_affairs_user_can_view_project')) {
function cpms_public_affairs_user_can_view_project($pdo, $projectId) {
    if (class_exists('App\\Core\\Auth')) {
        if (\App\Core\Auth::isMaster()) return true;
        $role = \App\Core\Auth::userRole();
        if ($role === 'executive') return true;
        $dept = cpms_public_affairs_drive_normalize_dept(\App\Core\Auth::userDepartment());
        if ($dept === urldecode('%EA%B3%B5%EB%AC%B4') || $dept === urldecode('%EA%B4%80%EB%A6%AC')) return true;
        if (function_exists('cpms_is_project_member_or_executive')) {
            return cpms_is_project_member_or_executive($pdo, (int)$projectId, $role, (string)\App\Core\Auth::userEmail());
        }
    }
    return false;
}}

if (!function_exists('cpms_public_affairs_drive_actions_html')) {
function cpms_public_affairs_drive_actions_html($type, $id, $row) {
    $type = trim((string)$type);
    $id = (int)$id;
    $row = is_array($row) ? $row : array();
    if ($type === '' || $id <= 0) return '';
    $storageType = isset($row['storage_type']) ? trim((string)$row['storage_type']) : '';
    $viewLink = isset($row['drive_web_view_link']) ? trim((string)$row['drive_web_view_link']) : '';
    $downloadLink = isset($row['drive_web_content_link']) ? trim((string)$row['drive_web_content_link']) : '';
    $hasLocal = false;
    foreach (array('stored_path', 'attachment_stored_path') as $pathKey) {
        if (isset($row[$pathKey]) && trim((string)$row[$pathKey]) !== '') $hasLocal = true;
    }
    if ($storageType === 'google_drive') {
        if ($viewLink === '' && $downloadLink === '') {
            return '<span class="text-xs text-amber-700 font-bold">' . cpms_public_affairs_drive_h(cpms_public_affairs_drive_label('file_check_required')) . '</span>';
        }
        $base = '?r=project/public_affairs_file&type=' . rawurlencode($type) . '&id=' . $id;
        $html = '<span class="inline-flex flex-wrap gap-2">';
        if ($viewLink !== '') {
            $html .= '<a class="text-blue-700 font-bold" href="' . cpms_public_affairs_drive_h($base . '&view=1') . '" target="_blank" rel="noopener">' . cpms_public_affairs_drive_h(cpms_public_affairs_drive_label('view')) . '</a>';
        }
        if ($downloadLink !== '') {
            $html .= '<a class="text-gray-700 font-bold" href="' . cpms_public_affairs_drive_h($base . '&download=1') . '">' . cpms_public_affairs_drive_h(cpms_public_affairs_drive_label('download')) . '</a>';
        }
        $html .= '</span>';
        return $html;
    }
    if ($hasLocal) {
        $base = '?r=project/public_affairs_file&type=' . rawurlencode($type) . '&id=' . $id;
        return '<a class="text-blue-700 font-bold" href="' . cpms_public_affairs_drive_h($base . '&download=1') . '">' . cpms_public_affairs_drive_h(cpms_public_affairs_drive_label('download')) . '</a>';
    }
    return '';
}}

if (!function_exists('cpms_public_affairs_drive_select_admin_project')) {
function cpms_public_affairs_drive_select_admin_project($pdo, $projectId) {
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

if (!function_exists('cpms_public_affairs_drive_run_admin_check')) {
function cpms_public_affairs_drive_run_admin_check($pdo, $userContext, $projectId) {
    $result = array(
        'project' => array('ok' => false, 'message' => ''),
        'public_affairs_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'progress_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'year_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'month_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'upload' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'delete' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'cleanup' => array('ok' => false, 'message' => ''),
        'test_file' => array()
    );
    $project = cpms_public_affairs_drive_select_admin_project($pdo, $projectId);
    if (!is_array($project)) {
        $result['project']['message'] = 'No project is available for public affairs Drive check.';
        return $result;
    }
    $selectedProjectId = isset($project['id']) ? (int)$project['id'] : 0;
    $result['project'] = array(
        'ok' => true,
        'id' => $selectedProjectId,
        'name' => isset($project['name']) ? (string)$project['name'] : '',
        'message' => 'Project selected.'
    );

    $target = cpms_public_affairs_drive_ensure_target_folder($pdo, $selectedProjectId, 'progress_attachment', date('Y-m'), date('Y-m-d'), $userContext, 'public_affairs_drive_check.txt');
    $result['public_affairs_folder'] = array('ok' => !empty($target['public_affairs_folder_id']), 'http_code' => isset($target['http_code']) ? (int)$target['http_code'] : 0, 'message' => !empty($target['public_affairs_folder_id']) ? '01_public_affairs folder is ready.' : (isset($target['message']) ? $target['message'] : 'Folder check failed.'));
    $result['progress_folder'] = array('ok' => !empty($target['document_folder_id']), 'http_code' => isset($target['http_code']) ? (int)$target['http_code'] : 0, 'message' => !empty($target['document_folder_id']) ? 'Progress folder is ready.' : (isset($target['message']) ? $target['message'] : 'Folder check failed.'));
    $result['year_folder'] = array('ok' => !empty($target['year_folder_id']), 'http_code' => isset($target['http_code']) ? (int)$target['http_code'] : 0, 'message' => !empty($target['year_folder_id']) ? 'Year folder is ready.' : (isset($target['message']) ? $target['message'] : 'Folder check failed.'));
    $result['month_folder'] = array('ok' => !empty($target['month_folder_id']), 'http_code' => isset($target['http_code']) ? (int)$target['http_code'] : 0, 'message' => !empty($target['month_folder_id']) ? 'Month folder is ready.' : (isset($target['message']) ? $target['message'] : 'Folder check failed.'));
    if (empty($target['ok'])) return $result;

    $tmpDir = cpms_drive_storage_root() . '/tmp/public_affairs_drive_check';
    if (!cpms_drive_ensure_dir($tmpDir)) {
        $result['upload']['message'] = 'Temporary public affairs check directory could not be created.';
        return $result;
    }
    $tmpPath = @tempnam($tmpDir, 'pa_drive_');
    if ($tmpPath === false || @file_put_contents($tmpPath, "CPMS public affairs Drive check\n" . date('Y-m-d H:i:s') . "\n") === false) {
        $result['upload']['message'] = 'Temporary public affairs check file could not be created.';
        return $result;
    }
    $fileName = 'CPMS_Public_Affairs_Check_' . date('Ymd_His') . '.txt';
    $context = array(
        'user' => $userContext,
        'section' => 'admin_drive_check_public_affairs',
        'project_id' => $selectedProjectId,
        'document_type' => cpms_public_affairs_drive_label('progress'),
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
    $result['cleanup'] = array('ok' => @unlink($tmpPath) ? true : false, 'message' => 'Temporary public affairs check file cleanup attempted.');
    return $result;
}}
