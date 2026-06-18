<?php
/**
 * Quality file upload/list/download helper.
 * PHP 5.6 compatible.
 */

require_once dirname(dirname(__DIR__)) . '/services/QualityDriveService.php';

if (!function_exists('cpms_quality_file_label')) {
function cpms_quality_file_label($key) {
    $labels = array(
        'none' => '%EC%B2%A8%EB%B6%80%20%EC%97%86%EC%9D%8C',
        'file_missing' => '%ED%8C%8C%EC%9D%BC%20%ED%99%95%EC%9D%B8%20%ED%95%84%EC%9A%94',
        'saved' => '%ED%92%88%EC%A7%88%20%ED%8C%8C%EC%9D%BC%EC%9D%84%20%EC%A0%80%EC%9E%A5%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.',
        'save_failed' => '%ED%92%88%EC%A7%88%20%ED%8C%8C%EC%9D%BC%20%EC%A0%95%EB%B3%B4%20%EC%A0%80%EC%9E%A5%EC%97%90%20%EC%8B%A4%ED%8C%A8%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.',
        'upload_failed' => '%ED%8C%8C%EC%9D%BC%20%EC%97%85%EB%A1%9C%EB%93%9C%EC%97%90%20%EC%8B%A4%ED%8C%A8%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.',
        'no_file' => '%EC%97%85%EB%A1%9C%EB%93%9C%ED%95%A0%20%ED%8C%8C%EC%9D%BC%EC%9D%84%20%EC%84%A0%ED%83%9D%ED%95%B4%EC%A3%BC%EC%84%B8%EC%9A%94.'
    );
    return isset($labels[$key]) ? urldecode($labels[$key]) : (string)$key;
}}

if (!function_exists('cpms_quality_file_store_path')) {
function cpms_quality_file_store_path() {
    return cpms_storage_root() . '/quality/files.json';
}}

if (!function_exists('cpms_quality_file_root')) {
function cpms_quality_file_root() {
    return cpms_storage_root() . '/quality/files';
}}

if (!function_exists('cpms_quality_file_read_store')) {
function cpms_quality_file_read_store() {
    $data = cpms_read_json_file(cpms_quality_file_store_path(), array('items' => array()));
    if (!is_array($data)) $data = array();
    if (!isset($data['items']) || !is_array($data['items'])) $data['items'] = array();
    return $data;
}}

if (!function_exists('cpms_quality_file_write_store')) {
function cpms_quality_file_write_store($data) {
    if (!is_array($data)) $data = array();
    if (!isset($data['items']) || !is_array($data['items'])) $data['items'] = array();
    $data['updated_at'] = date('Y-m-d H:i:s');
    return cpms_write_json_file(cpms_quality_file_store_path(), $data);
}}

if (!function_exists('cpms_quality_file_new_id')) {
function cpms_quality_file_new_id() {
    return 'QF-' . date('YmdHis') . '-' . substr(md5(uniqid('', true)), 0, 8);
}}

if (!function_exists('cpms_quality_file_valid_date')) {
function cpms_quality_file_valid_date($date) {
    $date = trim((string)$date);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return '';
    $year = (int)substr($date, 0, 4);
    $month = (int)substr($date, 5, 2);
    $day = (int)substr($date, 8, 2);
    if (!checkdate($month, $day, $year)) return '';
    return $date;
}}

if (!function_exists('cpms_quality_file_document_options')) {
function cpms_quality_file_document_options() {
    return array(
        'material_approval' => cpms_quality_drive_label('material_approval'),
        'inspection' => cpms_quality_drive_label('inspection'),
        'test_report' => cpms_quality_drive_label('test_report'),
        'cqi' => cpms_quality_drive_label('cqi'),
        'submission' => cpms_quality_drive_label('submission'),
        'etc' => cpms_quality_drive_label('etc')
    );
}}

if (!function_exists('cpms_quality_file_document_label')) {
function cpms_quality_file_document_label($documentType) {
    $opts = cpms_quality_file_document_options();
    return isset($opts[$documentType]) ? $opts[$documentType] : cpms_quality_drive_label('etc');
}}

if (!function_exists('cpms_quality_file_user_id')) {
function cpms_quality_file_user_id() {
    $u = class_exists('App\\Core\\Auth') ? \App\Core\Auth::user() : null;
    if (is_array($u) && isset($u['id'])) return (int)$u['id'];
    return 0;
}}

if (!function_exists('cpms_quality_file_normalize_dept')) {
function cpms_quality_file_normalize_dept($dept) {
    $dept = trim((string)$dept);
    $map = array(
        urldecode('%ED%92%88%EC%A7%88%EB%B6%80') => urldecode('%ED%92%88%EC%A7%88'),
        urldecode('%ED%92%88%EC%A7%88%ED%8C%80') => urldecode('%ED%92%88%EC%A7%88'),
        urldecode('%ED%92%88%EC%A7%88%EA%B4%80%EB%A6%AC') => urldecode('%ED%92%88%EC%A7%88'),
        urldecode('%ED%92%88%EC%A7%88%EA%B4%80%EB%A6%AC%EB%B6%80') => urldecode('%ED%92%88%EC%A7%88'),
        urldecode('%ED%92%88%EC%A7%88%EA%B4%80%EB%A6%AC%ED%8C%80') => urldecode('%ED%92%88%EC%A7%88')
    );
    if (isset($map[$dept])) return $map[$dept];
    return $dept;
}}

if (!function_exists('cpms_quality_file_table_exists')) {
function cpms_quality_file_table_exists($pdo, $table) {
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

if (!function_exists('cpms_quality_file_current_employee_id')) {
function cpms_quality_file_current_employee_id($pdo) {
    $userId = cpms_quality_file_user_id();
    if ($userId > 0) return $userId;
    if (!$pdo || !class_exists('App\\Core\\Auth') || !cpms_quality_file_table_exists($pdo, 'employees')) return 0;
    $email = trim((string)\App\Core\Auth::userEmail());
    if ($email === '') return 0;
    try {
        $st = $pdo->prepare("SELECT id FROM employees WHERE email = :email LIMIT 1");
        $st->bindValue(':email', $email);
        $st->execute();
        return (int)$st->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}}

if (!function_exists('cpms_quality_file_user_has_project_role')) {
function cpms_quality_file_user_has_project_role($pdo, $projectId, $column) {
    $column = trim((string)$column);
    if (!$pdo || (int)$projectId <= 0 || $column === '' || !cpms_quality_file_table_exists($pdo, 'cpms_construction_roles')) return false;
    $employeeId = cpms_quality_file_current_employee_id($pdo);
    if ($employeeId <= 0) return false;
    try {
        $st = $pdo->prepare("SELECT * FROM cpms_construction_roles WHERE project_id = :pid LIMIT 1");
        $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !isset($row[$column])) return false;
        return ((int)$row[$column] === $employeeId);
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_quality_file_can_access_all')) {
function cpms_quality_file_can_access_all() {
    if (!class_exists('App\\Core\\Auth') || !\App\Core\Auth::check()) return false;
    if (\App\Core\Auth::isMaster() || \App\Core\Auth::userRole() === 'executive') return true;
    if (method_exists('App\\Core\\Auth', 'canManageEmployees') && \App\Core\Auth::canManageEmployees()) return true;
    $dept = cpms_quality_file_normalize_dept(\App\Core\Auth::userDepartment());
    return ($dept === urldecode('%ED%92%88%EC%A7%88'));
}}

if (!function_exists('cpms_quality_file_can_view')) {
function cpms_quality_file_can_view($pdo, $projectId) {
    if (!class_exists('App\\Core\\Auth') || !\App\Core\Auth::check()) return false;
    if (cpms_quality_file_can_access_all()) return true;
    if ((int)$projectId <= 0) return false;
    if (cpms_quality_file_user_has_project_role($pdo, (int)$projectId, 'quality_employee_id')) return true;
    if (function_exists('cpms_is_project_member_or_executive')) {
        if (cpms_is_project_member_or_executive($pdo, (int)$projectId, \App\Core\Auth::userRole(), \App\Core\Auth::userEmail())) return true;
    }
    return false;
}}

if (!function_exists('cpms_quality_file_can_upload')) {
function cpms_quality_file_can_upload($pdo, $projectId) {
    if (!class_exists('App\\Core\\Auth') || !\App\Core\Auth::check()) return false;
    if (cpms_quality_file_can_access_all()) return true;
    if ((int)$projectId <= 0) return false;
    if (cpms_quality_file_user_has_project_role($pdo, (int)$projectId, 'quality_employee_id')) return true;
    if (function_exists('cpms_is_project_member_or_executive')) {
        if (cpms_is_project_member_or_executive($pdo, (int)$projectId, \App\Core\Auth::userRole(), \App\Core\Auth::userEmail())) return true;
    }
    return false;
}}

if (!function_exists('cpms_quality_file_project_name')) {
function cpms_quality_file_project_name($pdo, $projectId) {
    if (!$pdo || (int)$projectId <= 0) return '';
    try {
        $st = $pdo->prepare("SELECT name FROM cpms_projects WHERE id = :id LIMIT 1");
        $st->bindValue(':id', (int)$projectId, PDO::PARAM_INT);
        $st->execute();
        $name = $st->fetchColumn();
        return ($name !== false) ? trim((string)$name) : '';
    } catch (Exception $e) {
        return '';
    }
}}

if (!function_exists('cpms_quality_file_projects_for_user')) {
function cpms_quality_file_projects_for_user($pdo) {
    $rows = array();
    if (!$pdo || !cpms_quality_file_table_exists($pdo, 'cpms_projects')) return $rows;
    try {
        $st = $pdo->query("SELECT id, name FROM cpms_projects ORDER BY id DESC LIMIT 300");
        $all = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
        foreach ($all as $row) {
            $pid = isset($row['id']) ? (int)$row['id'] : 0;
            if ($pid <= 0 || !cpms_quality_file_can_view($pdo, $pid)) continue;
            $rows[] = $row;
        }
    } catch (Exception $e) {
        return $rows;
    }
    return $rows;
}}

if (!function_exists('cpms_quality_file_all_items')) {
function cpms_quality_file_all_items() {
    $store = cpms_quality_file_read_store();
    $items = isset($store['items']) && is_array($store['items']) ? $store['items'] : array();
    $active = array();
    foreach ($items as $row) {
        if (!is_array($row)) continue;
        if (isset($row['is_deleted']) && (int)$row['is_deleted'] === 1) continue;
        if (isset($row['status']) && (string)$row['status'] === 'deleted') continue;
        $active[] = $row;
    }
    usort($active, function($a, $b) {
        $aa = isset($a['uploaded_at']) ? (string)$a['uploaded_at'] : '';
        $bb = isset($b['uploaded_at']) ? (string)$b['uploaded_at'] : '';
        return strcmp($bb, $aa);
    });
    return $active;
}}

if (!function_exists('cpms_quality_file_visible_items')) {
function cpms_quality_file_visible_items($pdo) {
    $items = cpms_quality_file_all_items();
    $visible = array();
    foreach ($items as $row) {
        $pid = isset($row['project_id']) ? (int)$row['project_id'] : 0;
        if (cpms_quality_file_can_view($pdo, $pid)) $visible[] = $row;
    }
    return $visible;
}}

if (!function_exists('cpms_quality_file_find_item')) {
function cpms_quality_file_find_item($id) {
    $id = trim((string)$id);
    if ($id === '') return null;
    $items = cpms_quality_file_all_items();
    foreach ($items as $row) {
        if (is_array($row) && isset($row['id']) && (string)$row['id'] === $id) return $row;
    }
    return null;
}}

if (!function_exists('cpms_quality_file_safe_name')) {
function cpms_quality_file_safe_name($projectId, $recordId, $originalName) {
    $ext = strtolower(pathinfo((string)$originalName, PATHINFO_EXTENSION));
    if ($ext === '') $ext = 'bin';
    return 'quality_' . (int)$projectId . '_' . preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$recordId) . '_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 6) . '.' . $ext;
}}

if (!function_exists('cpms_quality_file_allowed_exts')) {
function cpms_quality_file_allowed_exts() {
    return array(
        'pdf' => true, 'xlsx' => true, 'xls' => true, 'csv' => true,
        'jpg' => true, 'jpeg' => true, 'png' => true, 'webp' => true,
        'zip' => true, 'doc' => true, 'docx' => true, 'hwp' => true, 'hwpx' => true,
        'txt' => true
    );
}}

if (!function_exists('cpms_quality_file_store_uploaded')) {
function cpms_quality_file_store_uploaded($file, $projectId, $recordId, $monthValue, &$message) {
    $message = '';
    if (!is_array($file)) {
        $message = cpms_quality_file_label('no_file');
        return false;
    }
    $error = isset($file['error']) ? (int)$file['error'] : UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) {
        $message = cpms_quality_file_label('no_file');
        return false;
    }
    if ($error !== UPLOAD_ERR_OK) {
        $message = cpms_quality_file_label('upload_failed');
        return false;
    }
    $tmp = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        $message = cpms_quality_file_label('upload_failed');
        return false;
    }
    $originalName = isset($file['name']) ? (string)$file['name'] : '';
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = cpms_quality_file_allowed_exts();
    if ($ext === '' || !isset($allowed[$ext])) {
        $message = '허용되지 않는 파일 형식입니다.';
        return false;
    }
    $size = isset($file['size']) ? (int)$file['size'] : 0;
    if ($size <= 0 || $size > 80 * 1024 * 1024) {
        $message = '파일 용량이 올바르지 않습니다. (최대 80MB)';
        return false;
    }
    $month = cpms_quality_drive_parse_month($monthValue, date('Y-m-d H:i:s'));
    $scope = (int)$projectId > 0 ? (string)(int)$projectId : 'common';
    $dir = cpms_quality_file_root() . '/' . $scope . '/' . $month['year'] . '-' . $month['month'];
    if (!cpms_ensure_dir($dir)) {
        $message = '품질 파일 저장 폴더를 만들 수 없습니다.';
        return false;
    }
    $storedName = cpms_quality_file_safe_name($projectId, $recordId, $originalName);
    $dest = $dir . '/' . $storedName;
    if (!@move_uploaded_file($tmp, $dest)) {
        $message = cpms_quality_file_label('upload_failed');
        return false;
    }
    $storedPath = 'quality/files/' . $scope . '/' . $month['year'] . '-' . $month['month'] . '/' . $storedName;
    return array(
        'original_name' => $originalName,
        'stored_name' => $storedName,
        'stored_path' => $storedPath,
        'local_path' => $storedPath,
        'file_size' => $size,
        'mime_type' => cpms_drive_detect_mime_type($dest),
        'uploaded_at' => date('Y-m-d H:i:s')
    );
}}

if (!function_exists('cpms_quality_file_resolve_path')) {
function cpms_quality_file_resolve_path($storedPath) {
    $storedPath = str_replace('\\', '/', trim((string)$storedPath));
    $storedPath = ltrim($storedPath, '/');
    if ($storedPath === '' || strpos($storedPath, 'quality/files/') !== 0) return '';
    $root = realpath(cpms_quality_file_root());
    if ($root === false) return '';
    $candidate = realpath(cpms_storage_root() . '/' . $storedPath);
    if ($candidate === false || !is_file($candidate)) return '';
    $rootNorm = rtrim(str_replace('\\', '/', $root), '/');
    $candidateNorm = str_replace('\\', '/', $candidate);
    if (strpos($candidateNorm, $rootNorm . '/') !== 0) return '';
    return $candidate;
}}

if (!function_exists('cpms_quality_file_has_local')) {
function cpms_quality_file_has_local($row) {
    if (!is_array($row)) return false;
    $path = isset($row['stored_path']) ? (string)$row['stored_path'] : (isset($row['local_path']) ? (string)$row['local_path'] : '');
    return cpms_quality_file_resolve_path($path) !== '';
}}

if (!function_exists('cpms_quality_file_normalize_uploads')) {
function cpms_quality_file_normalize_uploads($files) {
    $items = array();
    if (!is_array($files)) return $items;
    if (isset($files['name']) && is_array($files['name'])) {
        $count = count($files['name']);
        for ($i = 0; $i < $count; $i++) {
            $items[] = array(
                'name' => isset($files['name'][$i]) ? $files['name'][$i] : '',
                'type' => isset($files['type'][$i]) ? $files['type'][$i] : '',
                'tmp_name' => isset($files['tmp_name'][$i]) ? $files['tmp_name'][$i] : '',
                'error' => isset($files['error'][$i]) ? $files['error'][$i] : UPLOAD_ERR_NO_FILE,
                'size' => isset($files['size'][$i]) ? $files['size'][$i] : 0
            );
        }
    } else {
        $items[] = $files;
    }
    return $items;
}}

if (!function_exists('cpms_quality_file_actions_html')) {
function cpms_quality_file_actions_html($row) {
    if (!is_array($row) || !isset($row['id'])) return '';
    $base = base_url() . '/?r=quality/file_download&id=' . rawurlencode((string)$row['id']);
    $isDrive = cpms_quality_drive_is_drive_file($row);
    if ($isDrive) {
        $hasView = cpms_quality_drive_link($row, false) !== '';
        $hasDownload = isset($row['drive_web_content_link']) && trim((string)$row['drive_web_content_link']) !== '';
        if (!$hasView && !$hasDownload) {
            return '<span class="text-red-500 text-xs">' . cpms_quality_drive_h(cpms_quality_drive_label('file_check_required')) . '</span>';
        }
        $html = '<span class="inline-flex flex-wrap gap-1">';
        if ($hasView) {
            $html .= '<a class="inline-flex items-center px-2 py-1 rounded-lg border border-blue-200 bg-blue-50 text-blue-700 text-xs font-bold" target="_blank" rel="noopener" href="' . cpms_quality_drive_h($base) . '">' . cpms_quality_drive_h(cpms_quality_drive_label('view')) . '</a>';
        }
        if ($hasDownload) {
            $html .= '<a class="inline-flex items-center px-2 py-1 rounded-lg border border-gray-200 bg-white text-gray-700 text-xs font-bold" href="' . cpms_quality_drive_h($base . '&download=1') . '">' . cpms_quality_drive_h(cpms_quality_drive_label('download')) . '</a>';
        }
        $html .= '</span>';
        return $html;
    }
    if (cpms_quality_file_has_local($row)) {
        return '<a class="inline-flex items-center px-2 py-1 rounded-lg border border-gray-200 bg-white text-gray-700 text-xs font-bold" href="' . cpms_quality_drive_h($base . '&download=1') . '">' . cpms_quality_drive_h(cpms_quality_drive_label('download')) . '</a>';
    }
    return '<span class="text-red-500 text-xs">' . cpms_quality_drive_h(cpms_quality_drive_label('file_check_required')) . '</span>';
}}
