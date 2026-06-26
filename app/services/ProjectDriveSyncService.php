<?php
/**
 * Existing project Google Drive folder sync service.
 * PHP 5.6 compatible.
 */

require_once __DIR__ . '/GoogleDriveHelper.php';

if (!function_exists('cpms_project_drive_sync_json_encode')) {
function cpms_project_drive_sync_json_encode($data, $pretty) {
    $options = 0;
    if (defined('JSON_UNESCAPED_UNICODE')) $options = $options | JSON_UNESCAPED_UNICODE;
    if (defined('JSON_UNESCAPED_SLASHES')) $options = $options | JSON_UNESCAPED_SLASHES;
    if ($pretty && defined('JSON_PRETTY_PRINT')) $options = $options | JSON_PRETTY_PRINT;
    return json_encode($data, $options);
}}

if (!function_exists('cpms_project_drive_sync_log_path')) {
function cpms_project_drive_sync_log_path() {
    return cpms_drive_storage_root() . '/logs/google_drive_project_sync.log';
}}

if (!function_exists('cpms_project_drive_sync_backup_dir')) {
function cpms_project_drive_sync_backup_dir() {
    return cpms_drive_storage_root() . '/backups';
}}

if (!function_exists('cpms_project_drive_sync_response_excerpt')) {
function cpms_project_drive_sync_response_excerpt($text) {
    $text = cpms_drive_redact_text((string)$text);
    if (strlen($text) > 1200) $text = substr($text, 0, 1200) . '...';
    return $text;
}}

if (!function_exists('cpms_project_drive_sync_redact_log_value')) {
function cpms_project_drive_sync_redact_log_value($value, $keyName) {
    $keyName = strtolower((string)$keyName);
    if (is_array($value)) {
        $clean = array();
        foreach ($value as $k => $v) {
            $clean[$k] = cpms_project_drive_sync_redact_log_value($v, $k);
        }
        return $clean;
    }
    if (strpos($keyName, 'private_key') !== false || strpos($keyName, 'access_token') !== false || strpos($keyName, 'refresh_token') !== false) {
        return '[redacted]';
    }
    if (is_string($value)) return cpms_project_drive_sync_response_excerpt($value);
    return $value;
}}

if (!function_exists('cpms_project_drive_sync_write_log')) {
function cpms_project_drive_sync_write_log($summary) {
    $path = cpms_project_drive_sync_log_path();
    if (!cpms_drive_ensure_dir(dirname($path))) return false;
    $safe = cpms_project_drive_sync_redact_log_value($summary, 'root');
    $line = cpms_project_drive_sync_json_encode($safe, false);
    if ($line === false || $line === '') return false;
    return (@file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX) !== false);
}}

if (!function_exists('cpms_project_drive_sync_table_exists')) {
function cpms_project_drive_sync_table_exists($pdo, $table) {
    if (!$pdo) return false;
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE :tbl");
        $st->bindValue(':tbl', (string)$table);
        $st->execute();
        return $st->fetch() ? true : false;
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_project_drive_sync_table_columns')) {
function cpms_project_drive_sync_table_columns($pdo, $table) {
    $columns = array();
    if (!$pdo) return $columns;
    try {
        $st = $pdo->query("SHOW COLUMNS FROM `" . $table . "`");
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if (isset($row['Field'])) $columns[(string)$row['Field']] = true;
        }
    } catch (Exception $e) {
        $columns = array();
    }
    return $columns;
}}

if (!function_exists('cpms_project_drive_sync_load_projects')) {
function cpms_project_drive_sync_load_projects($pdo, $scope, $projectId) {
    $result = array('ok' => false, 'projects' => array(), 'message' => '');
    if (!$pdo) {
        $result['message'] = 'DB 연결에 실패했습니다.';
        return $result;
    }
    if (!cpms_project_drive_sync_table_exists($pdo, 'cpms_projects')) {
        $result['message'] = 'cpms_projects 테이블을 찾을 수 없습니다.';
        return $result;
    }
    if (!cpms_drive_ensure_project_columns($pdo)) {
        $result['message'] = 'Drive 컬럼 확인/생성에 실패했습니다.';
        return $result;
    }

    $columns = cpms_project_drive_sync_table_columns($pdo, 'cpms_projects');
    $where = array();
    $params = array();

    if (isset($columns['is_deleted'])) $where[] = '(is_deleted IS NULL OR is_deleted = 0)';
    if (isset($columns['is_hidden'])) $where[] = '(is_hidden IS NULL OR is_hidden = 0)';
    if (isset($columns['hidden'])) $where[] = '(hidden IS NULL OR hidden = 0)';
    if (isset($columns['deleted_at'])) $where[] = '(deleted_at IS NULL)';

    if ($scope === 'failed') {
        $where[] = "(drive_status IN ('failed','partial','needs_review') OR (drive_error_message IS NOT NULL AND drive_error_message <> ''))";
    } else if ($scope === 'single') {
        $projectId = (int)$projectId;
        if ($projectId <= 0) {
            $result['message'] = '프로젝트 ID가 올바르지 않습니다.';
            return $result;
        }
        $where[] = 'id = :project_id';
        $params[':project_id'] = $projectId;
    }

    $where[] = "name NOT LIKE '(가제)%'";
    $sql = 'SELECT * FROM cpms_projects';
    if (count($where) > 0) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY id ASC';

    try {
        $st = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            if ($key === ':project_id') {
                $st->bindValue($key, (int)$value, PDO::PARAM_INT);
            } else {
                $st->bindValue($key, $value);
            }
        }
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) $rows = array();
        $result['ok'] = true;
        $result['projects'] = $rows;
        $result['message'] = '프로젝트 목록을 읽었습니다.';
        return $result;
    } catch (Exception $e) {
        $result['message'] = '프로젝트 목록 조회 실패: ' . $e->getMessage();
        return $result;
    }
}}

if (!function_exists('cpms_project_drive_sync_backup_projects')) {
function cpms_project_drive_sync_backup_projects($pdo, $userContext) {
    $result = array('ok' => false, 'path' => '', 'count' => 0, 'message' => '');
    if (!$pdo) {
        $result['message'] = 'DB 연결에 실패했습니다.';
        return $result;
    }
    if (!cpms_project_drive_sync_table_exists($pdo, 'cpms_projects')) {
        $result['message'] = 'cpms_projects 테이블을 찾을 수 없습니다.';
        return $result;
    }

    try {
        $st = $pdo->query("SELECT * FROM cpms_projects WHERE name NOT LIKE '(가제)%' ORDER BY id ASC");
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) $rows = array();
    } catch (Exception $e) {
        $result['message'] = '백업 데이터 조회 실패: ' . $e->getMessage();
        return $result;
    }

    $dir = cpms_project_drive_sync_backup_dir();
    if (!cpms_drive_ensure_dir($dir)) {
        $result['message'] = '백업 폴더를 만들 수 없습니다.';
        return $result;
    }

    $path = $dir . '/projects_before_drive_sync_' . date('Ymd_His') . '.json';
    $payload = array(
        'generated_at' => date('Y-m-d H:i:s'),
        'generated_by' => cpms_drive_user_label($userContext),
        'source_table' => 'cpms_projects',
        'row_count' => count($rows),
        'projects' => $rows
    );
    $json = cpms_project_drive_sync_json_encode($payload, true);
    if ($json === false || $json === '') {
        $result['message'] = '백업 JSON 생성에 실패했습니다.';
        return $result;
    }
    if (@file_put_contents($path, $json, LOCK_EX) === false) {
        $result['message'] = '백업 파일 저장에 실패했습니다.';
        return $result;
    }

    $result['ok'] = true;
    $result['path'] = $path;
    $result['count'] = count($rows);
    $result['message'] = '프로젝트 데이터 백업을 생성했습니다.';
    return $result;
}}

if (!function_exists('cpms_project_drive_sync_folder_aliases')) {
function cpms_project_drive_sync_folder_aliases() {
    return array(
        'public_affairs_site_docs' => array('public_affairs_site_briefing'),
        'public_affairs_monthly_cost' => array('public_affairs_monthly_input'),
        'public_affairs_progress' => array('public_affairs_progress_payment', 'public_affairs_progress_docs'),
        'management_statement' => array('management_transaction_statement'),
        'management_etc' => array('management_other'),
        'safety_health_accident' => array('safety_health_incident'),
        'safety_health_ppe' => array('safety_health_protection'),
        'safety_health_medical_checkup' => array('safety_health_checkup'),
        'safety_health_etc' => array('safety_health_other'),
        'quality_material_approval' => array('quality_material', 'quality_approval'),
        'quality_inspection' => array('quality_inspect'),
        'quality_test_report' => array('quality_test_reports'),
        'quality_submission' => array('quality_submit'),
        'quality_etc' => array('quality_other')
    );
}}

if (!function_exists('cpms_project_drive_sync_existing_drive_data')) {
function cpms_project_drive_sync_existing_drive_data($project) {
    $raw = isset($project['drive_folders_json']) ? trim((string)$project['drive_folders_json']) : '';
    if ($raw === '') return array();
    $data = @json_decode($raw, true);
    return is_array($data) ? $data : array();
}}

if (!function_exists('cpms_project_drive_sync_existing_folder_id')) {
function cpms_project_drive_sync_existing_folder_id($folders, $key) {
    if (!is_array($folders)) return '';
    if (isset($folders[$key]) && trim((string)$folders[$key]) !== '') return trim((string)$folders[$key]);
    $aliases = cpms_project_drive_sync_folder_aliases();
    if (isset($aliases[$key]) && is_array($aliases[$key])) {
        foreach ($aliases[$key] as $alias) {
            if (isset($folders[$alias]) && trim((string)$folders[$alias]) !== '') return trim((string)$folders[$alias]);
        }
    }
    return '';
}}

if (!function_exists('cpms_project_drive_sync_project_existing_folder_id')) {
function cpms_project_drive_sync_project_existing_folder_id($project, $driveData) {
    $folderId = isset($project['drive_folder_id']) ? trim((string)$project['drive_folder_id']) : '';
    if ($folderId !== '') return $folderId;
    if (is_array($driveData) && isset($driveData['project_folder_id']) && trim((string)$driveData['project_folder_id']) !== '') {
        return trim((string)$driveData['project_folder_id']);
    }
    if (is_array($driveData) && isset($driveData['folders']) && is_array($driveData['folders'])) {
        return cpms_project_drive_sync_existing_folder_id($driveData['folders'], 'project');
    }
    return '';
}}

if (!function_exists('cpms_project_drive_sync_parent_matches')) {
function cpms_project_drive_sync_parent_matches($file, $parentFolderId) {
    $parentFolderId = trim((string)$parentFolderId);
    if ($parentFolderId === '') return true;
    if (!is_array($file) || !isset($file['parents']) || !is_array($file['parents'])) return false;
    foreach ($file['parents'] as $parent) {
        if ((string)$parent === $parentFolderId) return true;
    }
    return false;
}}

if (!function_exists('cpms_project_drive_sync_get_folder_info')) {
function cpms_project_drive_sync_get_folder_info($folderId, $parentFolderId) {
    $folderId = trim((string)$folderId);
    if ($folderId === '') return array('ok' => false, 'exists' => false, 'file' => null, 'message' => 'Drive folder ID is empty.', 'http_code' => 0, 'response_excerpt' => '');
    $params = array(
        'supportsAllDrives' => 'true',
        'fields' => 'id,name,mimeType,parents,trashed,webViewLink'
    );
    $res = cpms_drive_authorized_request('GET', 'files/' . rawurlencode($folderId), $params, null, array('Accept: application/json'), false, 30);
    if (!$res['ok']) {
        return array(
            'ok' => false,
            'exists' => false,
            'file' => null,
            'message' => 'Drive folder info failed: ' . $res['error'],
            'http_code' => $res['http_code'],
            'response_excerpt' => cpms_project_drive_sync_response_excerpt($res['body'])
        );
    }
    $file = is_array($res['json']) ? $res['json'] : array();
    if (!isset($file['id'])) {
        return array('ok' => true, 'exists' => false, 'file' => null, 'message' => 'Drive folder response was not recognized.', 'http_code' => $res['http_code'], 'response_excerpt' => '');
    }
    if (!isset($file['mimeType']) || (string)$file['mimeType'] !== 'application/vnd.google-apps.folder') {
        return array('ok' => true, 'exists' => false, 'file' => $file, 'message' => 'Drive ID exists but is not a folder.', 'http_code' => $res['http_code'], 'response_excerpt' => '');
    }
    if (isset($file['trashed']) && (int)$file['trashed'] === 1) {
        return array('ok' => true, 'exists' => false, 'file' => $file, 'message' => 'Drive folder is trashed.', 'http_code' => $res['http_code'], 'response_excerpt' => '');
    }
    if (!cpms_project_drive_sync_parent_matches($file, $parentFolderId)) {
        return array('ok' => true, 'exists' => false, 'file' => $file, 'message' => 'Drive folder parent does not match.', 'http_code' => $res['http_code'], 'response_excerpt' => '');
    }
    return array('ok' => true, 'exists' => true, 'file' => $file, 'message' => 'Drive folder exists.', 'http_code' => $res['http_code'], 'response_excerpt' => '');
}}

if (!function_exists('cpms_project_drive_sync_find_folders')) {
function cpms_project_drive_sync_find_folders($name, $parentFolderId) {
    $name = cpms_drive_sanitize_folder_name($name);
    $parentFolderId = trim((string)$parentFolderId);
    if ($name === '' || $parentFolderId === '') {
        return array('ok' => false, 'files' => array(), 'message' => 'Folder name or parent folder ID is empty.', 'http_code' => 0, 'response_excerpt' => '');
    }
    $q = "'" . cpms_drive_query_escape($parentFolderId) . "' in parents and name = '" . cpms_drive_query_escape($name) . "' and mimeType = 'application/vnd.google-apps.folder' and trashed = false";
    $params = array(
        'q' => $q,
        'fields' => 'files(id,name,mimeType,parents,trashed,webViewLink)',
        'supportsAllDrives' => 'true',
        'includeItemsFromAllDrives' => 'true',
        'corpora' => 'drive',
        'driveId' => cpms_drive_shared_drive_id(),
        'pageSize' => '100'
    );
    $res = cpms_drive_authorized_request('GET', 'files', $params, null, array('Accept: application/json'), false, 30);
    if (!$res['ok']) {
        return array(
            'ok' => false,
            'files' => array(),
            'message' => 'Drive folder search failed: ' . $res['error'],
            'http_code' => $res['http_code'],
            'response_excerpt' => cpms_project_drive_sync_response_excerpt($res['body'])
        );
    }
    $files = (is_array($res['json']) && isset($res['json']['files']) && is_array($res['json']['files'])) ? $res['json']['files'] : array();
    return array('ok' => true, 'files' => $files, 'message' => 'Drive folder search completed.', 'http_code' => $res['http_code'], 'response_excerpt' => '');
}}

if (!function_exists('cpms_project_drive_sync_find_or_create_folder')) {
function cpms_project_drive_sync_find_or_create_folder($name, $parentFolderId, $context, $allowCreate) {
    $found = cpms_project_drive_sync_find_folders($name, $parentFolderId);
    if (!$found['ok']) {
        if (!is_array($context)) $context = array();
        $context['target_folder_id'] = $parentFolderId;
        $context['original_name'] = $name;
        $context['message'] = $found['message'];
        $context['http_status'] = isset($found['http_code']) ? (int)$found['http_code'] : 0;
        $context['google_response_excerpt'] = isset($found['response_excerpt']) ? $found['response_excerpt'] : '';
        cpms_drive_log_upload_failure($context);
        return array('ok' => false, 'created' => false, 'file' => null, 'status' => 'failed', 'message' => $found['message'], 'http_code' => $found['http_code'], 'response_excerpt' => $found['response_excerpt']);
    }
    $files = isset($found['files']) && is_array($found['files']) ? $found['files'] : array();
    if (count($files) > 1) {
        return array('ok' => false, 'created' => false, 'file' => null, 'status' => 'duplicate', 'message' => '같은 이름의 Drive 폴더가 여러 개 있어 확인이 필요합니다.', 'http_code' => $found['http_code'], 'response_excerpt' => '', 'duplicates' => $files);
    }
    if (count($files) === 1) {
        return array('ok' => true, 'created' => false, 'file' => $files[0], 'status' => 'reused_by_name', 'message' => '기존 Drive 폴더를 이름으로 재사용했습니다.', 'http_code' => $found['http_code'], 'response_excerpt' => '');
    }
    if (!$allowCreate) {
        return array('ok' => true, 'created' => false, 'file' => null, 'status' => 'will_create', 'message' => 'Drive 폴더 생성 예정입니다.', 'http_code' => $found['http_code'], 'response_excerpt' => '');
    }
    $created = cpms_drive_create_folder($name, $parentFolderId, $context);
    return array(
        'ok' => $created['ok'],
        'created' => !empty($created['ok']),
        'file' => isset($created['file']) ? $created['file'] : null,
        'status' => !empty($created['ok']) ? 'created' : 'failed',
        'message' => isset($created['message']) ? $created['message'] : '',
        'http_code' => isset($created['http_code']) ? (int)$created['http_code'] : 0,
        'response_excerpt' => isset($created['response']) ? cpms_project_drive_sync_response_excerpt($created['response']) : ''
    );
}}

if (!function_exists('cpms_project_drive_sync_ensure_folder')) {
function cpms_project_drive_sync_ensure_folder($name, $parentFolderId, $existingFolderId, $context, $allowCreate) {
    $warnings = array();
    $existingFolderId = trim((string)$existingFolderId);
    if ($existingFolderId !== '') {
        $info = cpms_project_drive_sync_get_folder_info($existingFolderId, $parentFolderId);
        if (!empty($info['exists']) && is_array($info['file'])) {
            return array(
                'ok' => true,
                'created' => false,
                'file' => $info['file'],
                'status' => 'reused_by_id',
                'message' => '기존 Drive ID를 확인해 재사용했습니다.',
                'http_code' => isset($info['http_code']) ? (int)$info['http_code'] : 0,
                'response_excerpt' => '',
                'warnings' => $warnings
            );
        }
        $warnings[] = '저장된 Drive ID 확인 실패: ' . (isset($info['message']) ? $info['message'] : '');
    }

    $res = cpms_project_drive_sync_find_or_create_folder($name, $parentFolderId, $context, $allowCreate);
    if (isset($res['warnings']) && is_array($res['warnings'])) {
        foreach ($res['warnings'] as $w) $warnings[] = $w;
    }
    $res['warnings'] = $warnings;
    return $res;
}}

if (!function_exists('cpms_project_drive_sync_section_labels')) {
function cpms_project_drive_sync_section_labels() {
    return array(
        'public_affairs' => '공무',
        'management' => '관리',
        'construction' => '공사',
        'safety_health' => '안전보건',
        'quality' => '품질'
    );
}}

if (!function_exists('cpms_project_drive_sync_preview_project')) {
function cpms_project_drive_sync_preview_project($project, $userContext) {
    $projectId = isset($project['id']) ? (int)$project['id'] : 0;
    $projectName = isset($project['name']) ? trim((string)$project['name']) : '';
    $folderName = cpms_drive_project_folder_name($projectName, $projectId);
    $driveData = cpms_project_drive_sync_existing_drive_data($project);
    $existingFolderId = cpms_project_drive_sync_project_existing_folder_id($project, $driveData);
    $rootFolderId = cpms_drive_folder_id('project_root');
    $storedDriveNeedsCheck = false;
    $row = array(
        'project_id' => $projectId,
        'project_name' => $projectName,
        'current_drive_status' => $existingFolderId !== '' ? 'Drive 정보 있음' : 'Drive 정보 없음',
        'project_folder_id' => $existingFolderId,
        'expected_folder_name' => $folderName,
        'planned_status' => '',
        'message' => '',
        'http_code' => 0,
        'google_response_excerpt' => ''
    );

    if ($projectId <= 0) {
        $row['planned_status'] = '프로젝트 ID 누락으로 확인 필요';
        $row['message'] = '프로젝트 ID가 없어 Drive 폴더명을 만들 수 없습니다.';
        return $row;
    }
    if ($projectName === '') {
        $row['message'] = '프로젝트명이 비어 있어 PROJECT_' . $projectId . ' 형식으로 생성합니다.';
    }
    if ($rootFolderId === '') {
        $row['planned_status'] = 'Drive 설정 확인 필요';
        $row['message'] = '02_프로젝트 Drive 폴더 ID가 설정되어 있지 않습니다.';
        return $row;
    }

    if ($existingFolderId !== '') {
        $info = cpms_project_drive_sync_get_folder_info($existingFolderId, $rootFolderId);
        $row['http_code'] = isset($info['http_code']) ? (int)$info['http_code'] : 0;
        $row['google_response_excerpt'] = isset($info['response_excerpt']) ? $info['response_excerpt'] : '';
        if (!empty($info['exists'])) {
            $row['current_drive_status'] = 'Drive ID 확인됨';
            $row['planned_status'] = '이미 Drive 정보 있음';
            if ($row['message'] === '') $row['message'] = '기존 Drive 폴더 ID를 재사용하고 하위 폴더 누락 여부만 확인합니다.';
            return $row;
        }
        $row['current_drive_status'] = 'Drive ID 확인 실패';
        $row['planned_status'] = 'Drive 정보는 있으나 폴더 확인 필요';
        $storedDriveNeedsCheck = true;
        if ($row['message'] === '') $row['message'] = isset($info['message']) ? $info['message'] : '저장된 Drive 폴더를 확인하지 못했습니다.';
    }

    $search = cpms_project_drive_sync_find_folders($folderName, $rootFolderId);
    $row['http_code'] = isset($search['http_code']) ? (int)$search['http_code'] : $row['http_code'];
    $row['google_response_excerpt'] = isset($search['response_excerpt']) ? $search['response_excerpt'] : $row['google_response_excerpt'];
    if (!$search['ok']) {
        $row['planned_status'] = 'Drive 확인 실패';
        $row['message'] = $search['message'];
        return $row;
    }
    $files = isset($search['files']) && is_array($search['files']) ? $search['files'] : array();
    if (count($files) > 1) {
        $row['current_drive_status'] = '같은 이름 폴더 중복';
        $row['planned_status'] = '중복 확인 필요';
        $row['message'] = '02_프로젝트 아래 같은 이름 폴더가 여러 개 있어 자동 선택하지 않습니다.';
    } else if (count($files) === 1) {
        $row['current_drive_status'] = '같은 이름 폴더 있음';
        $row['project_folder_id'] = isset($files[0]['id']) ? (string)$files[0]['id'] : '';
        $row['planned_status'] = $existingFolderId !== '' ? 'Drive 폴더 재연결 예정' : '기존 Drive 폴더 재사용 예정';
        if ($row['message'] === '') $row['message'] = '새 폴더를 만들지 않고 기존 폴더를 연결합니다.';
    } else {
        if ($storedDriveNeedsCheck) {
            $row['planned_status'] = 'Drive 정보는 있으나 폴더 확인 필요';
        } else {
            $row['planned_status'] = $projectName === '' ? '프로젝트명 누락: PROJECT_ID 폴더명으로 생성 예정' : 'Drive 폴더 생성 예정';
        }
        if ($row['message'] === '') $row['message'] = '실행 시 프로젝트 폴더와 기본 하위 폴더를 생성합니다.';
    }
    return $row;
}}

if (!function_exists('cpms_project_drive_sync_execute_project')) {
function cpms_project_drive_sync_execute_project($pdo, $project, $userContext) {
    $projectId = isset($project['id']) ? (int)$project['id'] : 0;
    $projectName = isset($project['name']) ? trim((string)$project['name']) : '';
    $folderName = cpms_drive_project_folder_name($projectName, $projectId);
    $rootFolderId = cpms_drive_folder_id('project_root');
    $sectionLabels = cpms_project_drive_sync_section_labels();
    $sectionStatuses = array(
        'public_affairs' => '-',
        'management' => '-',
        'construction' => '-',
        'safety_health' => '-',
        'quality' => '-'
    );
    $result = array(
        'project_id' => $projectId,
        'project_name' => $projectName,
        'project_folder_id' => '',
        'project_folder_name' => $folderName,
        'section_statuses' => $sectionStatuses,
        'final_status' => '실패',
        'message' => '',
        'created_count' => 0,
        'reused_count' => 0,
        'errors' => array(),
        'warnings' => array(),
        'http_code' => 0,
        'google_response_excerpt' => '',
        'drive_result' => array()
    );

    $driveResult = array(
        'ok' => false,
        'status' => 'failed',
        'message' => '',
        'drive' => array(
            'status' => 'failed',
            'synced_at' => date('Y-m-d H:i:s'),
            'project_folder_id' => '',
            'project_folder_name' => $folderName,
            'folders' => array()
        ),
        'errors' => array()
    );

    if ($projectId <= 0) {
        $driveResult['message'] = '프로젝트 ID가 없습니다.';
        $result['message'] = $driveResult['message'];
        $result['drive_result'] = $driveResult;
        return $result;
    }
    if ($rootFolderId === '') {
        $driveResult['message'] = '02_프로젝트 Drive 폴더 ID가 설정되어 있지 않습니다.';
        $result['message'] = $driveResult['message'];
        $result['drive_result'] = $driveResult;
        return $result;
    }

    $driveData = cpms_project_drive_sync_existing_drive_data($project);
    $existingFolders = (isset($driveData['folders']) && is_array($driveData['folders'])) ? $driveData['folders'] : array();
    $existingProjectFolderId = cpms_project_drive_sync_project_existing_folder_id($project, $driveData);
    $context = array(
        'user' => $userContext,
        'section' => 'project_drive_sync',
        'project_id' => $projectId,
        'original_name' => $folderName,
        'target_folder_id' => $rootFolderId
    );

    $projectFolder = cpms_project_drive_sync_ensure_folder($folderName, $rootFolderId, $existingProjectFolderId, $context, true);
    $result['http_code'] = isset($projectFolder['http_code']) ? (int)$projectFolder['http_code'] : 0;
    $result['google_response_excerpt'] = isset($projectFolder['response_excerpt']) ? $projectFolder['response_excerpt'] : '';
    if (isset($projectFolder['warnings']) && is_array($projectFolder['warnings'])) $result['warnings'] = array_merge($result['warnings'], $projectFolder['warnings']);

    if (!$projectFolder['ok'] || !is_array($projectFolder['file']) || !isset($projectFolder['file']['id'])) {
        $message = isset($projectFolder['message']) ? $projectFolder['message'] : '프로젝트 Drive 폴더를 준비하지 못했습니다.';
        $driveResult['message'] = $message;
        $driveResult['status'] = (isset($projectFolder['status']) && $projectFolder['status'] === 'duplicate') ? 'needs_review' : 'failed';
        $driveResult['errors'] = array($message);
        $result['final_status'] = ($driveResult['status'] === 'needs_review') ? '확인 필요' : '실패';
        $result['message'] = $message;
        $result['errors'] = $driveResult['errors'];
        $result['drive_result'] = $driveResult;
        return $result;
    }

    $projectFolderId = (string)$projectFolder['file']['id'];
    $result['project_folder_id'] = $projectFolderId;
    $driveResult['drive']['project_folder_id'] = $projectFolderId;
    $driveResult['drive']['folders']['project'] = $projectFolderId;
    if (!empty($projectFolder['created'])) $result['created_count']++;
    else $result['reused_count']++;

    $schema = cpms_drive_project_folder_schema();
    foreach ($schema as $sectionKey => $sectionInfo) {
        $sectionName = isset($sectionInfo['name']) ? (string)$sectionInfo['name'] : (string)$sectionKey;
        $sectionContext = $context;
        $sectionContext['target_folder_id'] = $projectFolderId;
        $sectionContext['original_name'] = $sectionName;
        $existingSectionId = cpms_project_drive_sync_existing_folder_id($existingFolders, $sectionKey);
        $sectionFolder = cpms_project_drive_sync_ensure_folder($sectionName, $projectFolderId, $existingSectionId, $sectionContext, true);
        if (isset($sectionFolder['http_code']) && (int)$sectionFolder['http_code'] > 0) $result['http_code'] = (int)$sectionFolder['http_code'];
        if (isset($sectionFolder['response_excerpt']) && $sectionFolder['response_excerpt'] !== '') $result['google_response_excerpt'] = $sectionFolder['response_excerpt'];
        if (isset($sectionFolder['warnings']) && is_array($sectionFolder['warnings'])) $result['warnings'] = array_merge($result['warnings'], $sectionFolder['warnings']);

        if (!$sectionFolder['ok'] || !is_array($sectionFolder['file']) || !isset($sectionFolder['file']['id'])) {
            $label = isset($sectionLabels[$sectionKey]) ? $sectionLabels[$sectionKey] : $sectionKey;
            $message = $label . ': ' . (isset($sectionFolder['message']) ? $sectionFolder['message'] : '폴더 준비 실패');
            $result['errors'][] = $message;
            $sectionStatuses[$sectionKey] = (isset($sectionFolder['status']) && $sectionFolder['status'] === 'duplicate') ? '확인 필요' : '실패';
            continue;
        }

        $sectionFolderId = (string)$sectionFolder['file']['id'];
        $driveResult['drive']['folders'][$sectionKey] = $sectionFolderId;
        $sectionCreated = !empty($sectionFolder['created']);
        $childCreated = false;
        $childFailed = false;
        if ($sectionCreated) $result['created_count']++;
        else $result['reused_count']++;

        $children = (isset($sectionInfo['children']) && is_array($sectionInfo['children'])) ? $sectionInfo['children'] : array();
        foreach ($children as $childKey => $childName) {
            $childContext = $context;
            $childContext['target_folder_id'] = $sectionFolderId;
            $childContext['original_name'] = $childName;
            $existingChildId = cpms_project_drive_sync_existing_folder_id($existingFolders, $childKey);
            $childFolder = cpms_project_drive_sync_ensure_folder($childName, $sectionFolderId, $existingChildId, $childContext, true);
            if (isset($childFolder['http_code']) && (int)$childFolder['http_code'] > 0) $result['http_code'] = (int)$childFolder['http_code'];
            if (isset($childFolder['response_excerpt']) && $childFolder['response_excerpt'] !== '') $result['google_response_excerpt'] = $childFolder['response_excerpt'];
            if (isset($childFolder['warnings']) && is_array($childFolder['warnings'])) $result['warnings'] = array_merge($result['warnings'], $childFolder['warnings']);

            if (!$childFolder['ok'] || !is_array($childFolder['file']) || !isset($childFolder['file']['id'])) {
                $message = $sectionName . '/' . $childName . ': ' . (isset($childFolder['message']) ? $childFolder['message'] : '폴더 준비 실패');
                $result['errors'][] = $message;
                $childFailed = true;
                continue;
            }
            $driveResult['drive']['folders'][$childKey] = (string)$childFolder['file']['id'];
            if (!empty($childFolder['created'])) {
                $result['created_count']++;
                $childCreated = true;
            } else {
                $result['reused_count']++;
            }
        }

        if ($childFailed) {
            $sectionStatuses[$sectionKey] = '일부 실패';
        } else if ($sectionCreated) {
            $sectionStatuses[$sectionKey] = '생성';
        } else if ($childCreated) {
            $sectionStatuses[$sectionKey] = '하위 생성';
        } else {
            $sectionStatuses[$sectionKey] = '이미 있음';
        }
    }

    $result['section_statuses'] = $sectionStatuses;
    if (count($result['errors']) > 0) {
        $hasReview = false;
        foreach ($result['errors'] as $errorMessage) {
            if (strpos($errorMessage, '여러 개') !== false || strpos($errorMessage, '중복') !== false) $hasReview = true;
        }
        $driveResult['ok'] = false;
        $driveResult['status'] = $hasReview ? 'needs_review' : 'partial';
        $driveResult['drive']['status'] = $driveResult['status'];
        $driveResult['message'] = $hasReview ? '중복 확인이 필요한 폴더가 있습니다.' : '일부 Drive 폴더 생성/확인에 실패했습니다.';
        $driveResult['errors'] = $result['errors'];
        $result['final_status'] = $hasReview ? '확인 필요' : '일부 생성';
        $result['message'] = $driveResult['message'] . ' ' . implode(' / ', $result['errors']);
    } else {
        $driveResult['ok'] = true;
        $driveResult['status'] = 'ready';
        $driveResult['drive']['status'] = 'ready';
        $driveResult['message'] = '프로젝트 Drive 폴더 구조가 준비되었습니다.';
        $result['final_status'] = ((int)$result['created_count'] > 0) ? '성공' : '이미 있음';
        $result['message'] = ((int)$result['created_count'] > 0) ? 'Drive 폴더 구조를 생성/보정했습니다.' : '기존 Drive 폴더 구조를 재사용했습니다.';
    }

    if (count($result['warnings']) > 0) {
        $result['message'] .= ' 경고: ' . implode(' / ', $result['warnings']);
    }
    $result['drive_result'] = $driveResult;
    return $result;
}}

if (!function_exists('cpms_project_drive_sync_preview')) {
function cpms_project_drive_sync_preview($pdo, $scope, $projectId, $userContext) {
    $loaded = cpms_project_drive_sync_load_projects($pdo, $scope, $projectId);
    $summary = array(
        'ok' => $loaded['ok'],
        'mode' => 'preview',
        'scope' => $scope,
        'message' => $loaded['message'],
        'total' => 0,
        'rows' => array()
    );
    if (!$loaded['ok']) return $summary;
    $projects = $loaded['projects'];
    $summary['total'] = count($projects);
    foreach ($projects as $project) {
        $summary['rows'][] = cpms_project_drive_sync_preview_project($project, $userContext);
    }
    return $summary;
}}

if (!function_exists('cpms_project_drive_sync_run')) {
function cpms_project_drive_sync_run($pdo, $scope, $projectId, $userContext) {
    $loaded = cpms_project_drive_sync_load_projects($pdo, $scope, $projectId);
    $summary = array(
        'ok' => false,
        'mode' => 'run',
        'scope' => $scope,
        'run_at' => date('Y-m-d H:i:s'),
        'actor' => cpms_drive_user_label($userContext),
        'message' => $loaded['message'],
        'backup' => array('ok' => false, 'path' => '', 'message' => ''),
        'total' => 0,
        'success' => 0,
        'failed' => 0,
        'skipped' => 0,
        'rows' => array(),
        'log_path' => cpms_project_drive_sync_log_path(),
        'log_written' => false
    );
    if (!$loaded['ok']) {
        cpms_project_drive_sync_write_log($summary);
        return $summary;
    }

    $projects = $loaded['projects'];
    $summary['total'] = count($projects);
    if (count($projects) === 0) {
        $summary['ok'] = true;
        $summary['message'] = '동기화 대상 프로젝트가 없습니다.';
        $summary['log_written'] = cpms_project_drive_sync_write_log($summary);
        return $summary;
    }

    $backup = cpms_project_drive_sync_backup_projects($pdo, $userContext);
    $summary['backup'] = $backup;
    if (!$backup['ok']) {
        $summary['message'] = '백업 실패로 동기화를 중단했습니다. ' . $backup['message'];
        $summary['failed'] = count($projects);
        $summary['log_written'] = cpms_project_drive_sync_write_log($summary);
        return $summary;
    }

    foreach ($projects as $project) {
        try {
            $row = cpms_project_drive_sync_execute_project($pdo, $project, $userContext);
            $saveOk = false;
            if (isset($row['drive_result']) && is_array($row['drive_result'])) {
                $saveOk = cpms_drive_save_project_structure_result($pdo, isset($row['project_id']) ? (int)$row['project_id'] : 0, $row['drive_result']);
            }
            $row['save_ok'] = $saveOk;
            if (!$saveOk) {
                $row['final_status'] = '실패';
                $row['message'] = '프로젝트 Drive 정보 저장 실패: ' . (isset($row['message']) ? $row['message'] : '');
                $summary['failed']++;
            } else if (isset($row['final_status']) && $row['final_status'] === '이미 있음') {
                $summary['skipped']++;
            } else if (isset($row['drive_result']['ok']) && !empty($row['drive_result']['ok'])) {
                $summary['success']++;
            } else {
                $summary['failed']++;
            }
            $summary['rows'][] = $row;
        } catch (Exception $e) {
            $failedRow = array(
                'project_id' => isset($project['id']) ? (int)$project['id'] : 0,
                'project_name' => isset($project['name']) ? (string)$project['name'] : '',
                'project_folder_id' => '',
                'section_statuses' => array(
                    'public_affairs' => '-',
                    'management' => '-',
                    'construction' => '-',
                    'safety_health' => '-',
                    'quality' => '-'
                ),
                'final_status' => '실패',
                'message' => '예외 발생: ' . $e->getMessage(),
                'http_code' => 0,
                'google_response_excerpt' => '',
                'save_ok' => false
            );
            $summary['rows'][] = $failedRow;
            $summary['failed']++;
        }
    }

    $summary['ok'] = ($summary['failed'] === 0);
    $summary['message'] = '기존 프로젝트 Drive 폴더 동기화를 완료했습니다.';
    $summary['log_written'] = cpms_project_drive_sync_write_log($summary);
    return $summary;
}}
