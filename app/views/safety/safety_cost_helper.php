<?php
/**
 * Safety cost file store helper.
 * PHP 5.6 compatible.
 */

if (!function_exists('cpms_safety_cost_store_path')) {
function cpms_safety_cost_store_path()
{
    return cpms_storage_root() . '/safety_costs/usage.json';
}}

if (!function_exists('cpms_safety_cost_files_root')) {
function cpms_safety_cost_files_root()
{
    return cpms_storage_root() . '/safety_costs/files';
}}

if (!function_exists('cpms_safety_cost_read_store')) {
function cpms_safety_cost_read_store()
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $data = cpms_read_json_file(cpms_safety_cost_store_path(), array('items' => array()));
    if (!is_array($data)) $data = array();
    if (!isset($data['items']) || !is_array($data['items'])) $data['items'] = array();
    $cache = $data;
    return $cache;
}}

if (!function_exists('cpms_safety_cost_write_store')) {
function cpms_safety_cost_write_store($data)
{
    if (!is_array($data)) $data = array();
    if (!isset($data['items']) || !is_array($data['items'])) $data['items'] = array();
    $data['updated_at'] = date('Y-m-d H:i:s');
    return cpms_write_json_file(cpms_safety_cost_store_path(), $data);
}}

if (!function_exists('cpms_safety_cost_new_id')) {
function cpms_safety_cost_new_id()
{
    return 'SC-' . date('YmdHis') . '-' . substr(md5(uniqid('', true)), 0, 8);
}}

if (!function_exists('cpms_safety_cost_parse_amount')) {
function cpms_safety_cost_parse_amount($value)
{
    $raw = preg_replace('/[^0-9.\-]/', '', (string)$value);
    if ($raw === '' || $raw === '-' || $raw === '.' || !is_numeric($raw)) return 0.0;
    return (float)$raw;
}}

if (!function_exists('cpms_safety_cost_valid_date')) {
function cpms_safety_cost_valid_date($date)
{
    $date = trim((string)$date);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return '';
    $year = (int)substr($date, 0, 4);
    $month = (int)substr($date, 5, 2);
    $day = (int)substr($date, 8, 2);
    if (!checkdate($month, $day, $year)) return '';
    $ts = strtotime($date);
    if ($ts === false) return '';
    return date('Y-m-d', $ts);
}}

if (!function_exists('cpms_safety_cost_user_id')) {
function cpms_safety_cost_user_id()
{
    $u = class_exists('App\\Core\\Auth') ? \App\Core\Auth::user() : null;
    if (is_array($u) && isset($u['id'])) return (int)$u['id'];
    return 0;
}}

if (!function_exists('cpms_safety_cost_normalize_dept')) {
function cpms_safety_cost_normalize_dept($dept)
{
    $dept = trim((string)$dept);
    $map = array(
        '안전부' => '안전',
        '안전팀' => '안전',
        '안전/보건' => '안전',
        '안전보건' => '안전',
        '공무부' => '공무',
        '공무팀' => '공무',
        '공사부' => '공사',
        '공사팀' => '공사',
        '품질부' => '품질',
        '품질팀' => '품질',
        '품질관리' => '품질',
        '품질관리부' => '품질',
        '품질관리팀' => '품질',
        '관리부' => '관리',
        '관리팀' => '관리'
    );
    if (isset($map[$dept])) return $map[$dept];
    return $dept;
}}

if (!function_exists('cpms_safety_cost_table_exists')) {
function cpms_safety_cost_table_exists($pdo, $table)
{
    static $cache = array();
    if (!$pdo || trim((string)$table) === '') return false;
    $key = spl_object_hash($pdo) . ':' . (string)$table;
    if (isset($cache[$key])) return $cache[$key];
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t");
        $st->bindValue(':t', (string)$table);
        $st->execute();
        $cache[$key] = ((int)$st->fetchColumn() > 0);
        return $cache[$key];
    } catch (Exception $e) {
        $cache[$key] = false;
        return false;
    }
}}

if (!function_exists('cpms_safety_cost_column_exists')) {
function cpms_safety_cost_column_exists($pdo, $table, $column)
{
    static $cache = array();
    if (!$pdo || trim((string)$table) === '' || trim((string)$column) === '') return false;
    $key = spl_object_hash($pdo) . ':' . (string)$table . ':' . (string)$column;
    if (isset($cache[$key])) return $cache[$key];
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c");
        $st->bindValue(':t', (string)$table);
        $st->bindValue(':c', (string)$column);
        $st->execute();
        $cache[$key] = ((int)$st->fetchColumn() > 0);
        return $cache[$key];
    } catch (Exception $e) {
        $cache[$key] = false;
        return false;
    }
}}

if (!function_exists('cpms_safety_cost_user_employee_id')) {
function cpms_safety_cost_user_employee_id($pdo)
{
    $userId = cpms_safety_cost_user_id();
    if ($userId > 0) return $userId;
    if (!$pdo || !class_exists('App\\Core\\Auth')) return 0;
    $email = trim((string)\App\Core\Auth::userEmail());
    if ($email === '') return 0;
    if (function_exists('cpms_find_employee_id_by_email')) {
        return (int)cpms_find_employee_id_by_email($pdo, $email);
    }
    try {
        $st = $pdo->prepare("SELECT id FROM employees WHERE email = :email LIMIT 1");
        $st->bindValue(':email', $email);
        $st->execute();
        return (int)$st->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}}

if (!function_exists('cpms_safety_cost_user_can_view_all_projects')) {
function cpms_safety_cost_user_can_view_all_projects()
{
    if (!class_exists('App\\Core\\Auth') || !\App\Core\Auth::check()) return false;
    if (\App\Core\Auth::isMaster() || \App\Core\Auth::userRole() === 'executive') return true;
    $dept = cpms_safety_cost_normalize_dept(\App\Core\Auth::userDepartment());
    return ($dept === '공무' || $dept === '관리');
}}

if (!function_exists('cpms_safety_cost_project_role_row')) {
function cpms_safety_cost_project_role_row($pdo, $projectId)
{
    static $cache = array();
    $projectId = (int)$projectId;
    if (!$pdo || $projectId <= 0 || !cpms_safety_cost_table_exists($pdo, 'cpms_construction_roles')) return null;
    $key = spl_object_hash($pdo) . ':' . $projectId;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $st = $pdo->prepare("SELECT * FROM cpms_construction_roles WHERE project_id = :pid LIMIT 1");
        $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $cache[$key] = is_array($row) ? $row : null;
    } catch (Exception $e) {
        $cache[$key] = null;
    }
    return $cache[$key];
}}

if (!function_exists('cpms_safety_cost_user_has_project_role')) {
function cpms_safety_cost_user_has_project_role($pdo, $projectId, $column)
{
    $column = trim((string)$column);
    if ($column === '') return false;
    $userId = cpms_safety_cost_user_employee_id($pdo);
    if ($userId <= 0) return false;
    $row = cpms_safety_cost_project_role_row($pdo, (int)$projectId);
    if (!is_array($row) || !isset($row[$column])) return false;
    return ((int)$row[$column] === $userId);
}}

if (!function_exists('cpms_safety_cost_user_can_manage_project')) {
function cpms_safety_cost_user_can_manage_project($pdo, $projectId)
{
    $projectId = (int)$projectId;
    if ($projectId <= 0 || !class_exists('App\\Core\\Auth') || !\App\Core\Auth::check()) return false;
    if (\App\Core\Auth::isMaster() || \App\Core\Auth::userRole() === 'executive') return true;

    $dept = cpms_safety_cost_normalize_dept(\App\Core\Auth::userDepartment());
    if ($dept !== '안전') return false;

    $userId = cpms_safety_cost_user_employee_id($pdo);
    if (!$pdo) return false;
    if (!cpms_safety_cost_table_exists($pdo, 'cpms_construction_roles')) {
        return true;
    }
    if ($userId <= 0) return false;

    try {
        $row = cpms_safety_cost_project_role_row($pdo, $projectId);
        if (!is_array($row)) return false;
        return (isset($row['safety_employee_id']) && (int)$row['safety_employee_id'] === $userId);
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_safety_cost_user_can_view_project')) {
function cpms_safety_cost_user_can_view_project($pdo, $projectId)
{
    $projectId = (int)$projectId;
    if ($projectId <= 0 || !class_exists('App\\Core\\Auth') || !\App\Core\Auth::check()) return false;
    if (cpms_safety_cost_user_can_view_all_projects()) return true;
    if (cpms_safety_cost_user_can_manage_project($pdo, $projectId)) return true;
    if (cpms_safety_cost_user_has_project_role($pdo, $projectId, 'quality_employee_id')) return true;
    if (cpms_safety_cost_user_has_project_role($pdo, $projectId, 'site_employee_id')) return true;
    if (function_exists('cpms_is_project_member_or_executive')) {
        if (cpms_is_project_member_or_executive($pdo, $projectId, \App\Core\Auth::userRole(), \App\Core\Auth::userEmail())) return true;
    }
    return false;
}}

if (!function_exists('cpms_safety_incident_user_can_manage_project')) {
function cpms_safety_incident_user_can_manage_project($pdo, $projectId)
{
    $projectId = (int)$projectId;
    if ($projectId <= 0 || !class_exists('App\\Core\\Auth') || !\App\Core\Auth::check()) return false;
    if (\App\Core\Auth::isMaster() || \App\Core\Auth::userRole() === 'executive') return true;
    if (cpms_safety_cost_user_can_manage_project($pdo, $projectId)) return true;
    if (method_exists('App\\Core\\Auth', 'canManageConstruction') && \App\Core\Auth::canManageConstruction()) {
        return cpms_safety_cost_user_can_view_project($pdo, $projectId);
    }
    return false;
}}

if (!function_exists('cpms_safety_cost_project_name')) {
function cpms_safety_cost_project_name($pdo, $projectId)
{
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

if (!function_exists('cpms_safety_cost_project_rows_for_user')) {
function cpms_safety_cost_project_rows_for_user($pdo)
{
    $rows = array();
    if (!$pdo || !cpms_safety_cost_table_exists($pdo, 'cpms_projects')) return $rows;
    try {
        $st = $pdo->query("SELECT id, name, start_date, end_date FROM cpms_projects ORDER BY id DESC");
        $all = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
        if (!is_array($all)) $all = array();
        foreach ($all as $row) {
            $pid = isset($row['id']) ? (int)$row['id'] : 0;
            if ($pid <= 0) continue;
            if (!cpms_safety_cost_user_can_view_project($pdo, $pid)) continue;
            $row['can_manage_safety_cost'] = cpms_safety_cost_user_can_manage_project($pdo, $pid) ? 1 : 0;
            $rows[count($rows)] = $row;
        }
    } catch (Exception $e) {
        $rows = array();
    }
    return $rows;
}}

if (!function_exists('cpms_safety_cost_is_active')) {
function cpms_safety_cost_is_active($row)
{
    if (!is_array($row)) return false;
    if (isset($row['is_deleted']) && (int)$row['is_deleted'] === 1) return false;
    $status = isset($row['status']) ? trim((string)$row['status']) : 'active';
    $statusLower = strtolower($status);
    if ($statusLower === 'deleted' || $statusLower === 'cancelled' || $status === '삭제' || $status === '취소') return false;
    return true;
}}

if (!function_exists('cpms_safety_cost_all_items')) {
function cpms_safety_cost_all_items()
{
    $store = cpms_safety_cost_read_store();
    $items = isset($store['items']) && is_array($store['items']) ? $store['items'] : array();
    return $items;
}}

if (!function_exists('cpms_safety_cost_project_items')) {
function cpms_safety_cost_project_items($projectId)
{
    $projectId = (int)$projectId;
    $result = array();
    if ($projectId <= 0) return $result;
    $items = cpms_safety_cost_all_items();
    foreach ($items as $row) {
        if (!is_array($row) || !cpms_safety_cost_is_active($row)) continue;
        if (!isset($row['project_id']) || (int)$row['project_id'] !== $projectId) continue;
        $result[count($result)] = $row;
    }
    usort($result, function($a, $b) {
        $ad = isset($a['use_date']) ? (string)$a['use_date'] : '';
        $bd = isset($b['use_date']) ? (string)$b['use_date'] : '';
        if ($ad === $bd) return strcmp(isset($b['created_at']) ? (string)$b['created_at'] : '', isset($a['created_at']) ? (string)$a['created_at'] : '');
        return strcmp($bd, $ad);
    });
    return $result;
}}

if (!function_exists('cpms_safety_cost_project_items_between')) {
function cpms_safety_cost_project_items_between($projectId, $startDate, $endDate)
{
    $result = array();
    $startDate = cpms_safety_cost_valid_date($startDate);
    $endDate = cpms_safety_cost_valid_date($endDate);
    if ($startDate === '' || $endDate === '') return $result;
    if ($startDate > $endDate) {
        $tmp = $startDate;
        $startDate = $endDate;
        $endDate = $tmp;
    }
    $items = cpms_safety_cost_project_items((int)$projectId);
    foreach ($items as $row) {
        $date = isset($row['use_date']) ? cpms_safety_cost_valid_date($row['use_date']) : '';
        if ($date === '' || $date < $startDate || $date > $endDate) continue;
        $result[count($result)] = $row;
    }
    return $result;
}}

if (!function_exists('cpms_safety_cost_row_amount')) {
function cpms_safety_cost_row_amount($row)
{
    if (!is_array($row)) return 0.0;
    $fallback = null;
    foreach (array('amount', 'supply_amount', 'used_amount', 'cost', 'price') as $key) {
        if (!isset($row[$key]) || !is_numeric((string)$row[$key])) continue;
        $value = (float)$row[$key];
        if ($fallback === null) $fallback = $value;
        if (abs($value) > 0.0001) return $value;
    }
    return ($fallback === null) ? 0.0 : (float)$fallback;
}}

if (!function_exists('cpms_safety_cost_total')) {
function cpms_safety_cost_total($projectId)
{
    $sum = 0.0;
    $items = cpms_safety_cost_project_items((int)$projectId);
    foreach ($items as $row) {
        $sum += cpms_safety_cost_row_amount($row);
    }
    return $sum;
}}

if (!function_exists('cpms_safety_cost_total_between')) {
function cpms_safety_cost_total_between($projectId, $startDate, $endDate)
{
    $sum = 0.0;
    $items = cpms_safety_cost_project_items_between((int)$projectId, $startDate, $endDate);
    foreach ($items as $row) {
        $sum += cpms_safety_cost_row_amount($row);
    }
    return $sum;
}}

if (!function_exists('cpms_safety_cost_total_except')) {
function cpms_safety_cost_total_except($projectId, $excludeId)
{
    $sum = 0.0;
    $excludeId = trim((string)$excludeId);
    $items = cpms_safety_cost_project_items((int)$projectId);
    foreach ($items as $row) {
        $rowId = isset($row['id']) ? (string)$row['id'] : '';
        if ($excludeId !== '' && $rowId === $excludeId) continue;
        $sum += cpms_safety_cost_row_amount($row);
    }
    return $sum;
}}

if (!function_exists('cpms_safety_cost_find_item')) {
function cpms_safety_cost_find_item($id)
{
    $id = trim((string)$id);
    if ($id === '') return null;
    $items = cpms_safety_cost_all_items();
    foreach ($items as $row) {
        if (is_array($row) && isset($row['id']) && (string)$row['id'] === $id) return $row;
    }
    return null;
}}

if (!function_exists('cpms_safety_cost_safe_file_name')) {
function cpms_safety_cost_safe_file_name($projectId, $recordId, $ext)
{
    return 'safety_cost_' . (int)$projectId . '_' . preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$recordId) . '_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 6) . '.' . strtolower((string)$ext);
}}

if (!function_exists('cpms_safety_cost_store_uploaded_pdf')) {
function cpms_safety_cost_store_uploaded_pdf($fieldName, $projectId, $recordId, $useDate, &$message)
{
    $message = '';
    $result = array('has_file' => 0);
    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) return $result;
    $file = $_FILES[$fieldName];
    $error = isset($file['error']) ? (int)$file['error'] : UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) return $result;
    $result['has_file'] = 1;
    if ($error !== UPLOAD_ERR_OK) {
        $message = 'PDF 업로드에 실패했습니다.';
        return $result;
    }
    $tmpName = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        $message = '정상적인 업로드 파일이 아닙니다.';
        return $result;
    }
    $originalName = isset($file['name']) ? (string)$file['name'] : '';
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        $message = 'PDF 파일만 업로드할 수 있습니다.';
        return $result;
    }
    $size = isset($file['size']) ? (int)$file['size'] : 0;
    if ($size <= 0 || $size > 20 * 1024 * 1024) {
        $message = 'PDF 파일 용량이 올바르지 않습니다. (최대 20MB)';
        return $result;
    }
    $ym = (cpms_safety_cost_valid_date($useDate) !== '') ? substr($useDate, 0, 7) : date('Y-m');
    $dir = cpms_safety_cost_files_root() . '/' . (int)$projectId . '/' . $ym;
    if (!cpms_ensure_dir($dir)) {
        $message = 'PDF 저장 폴더를 만들 수 없습니다.';
        return $result;
    }
    $storedName = cpms_safety_cost_safe_file_name($projectId, $recordId, $ext);
    $dest = $dir . '/' . $storedName;
    if (!@move_uploaded_file($tmpName, $dest)) {
        $message = 'PDF 파일 저장에 실패했습니다.';
        return $result;
    }
    $storedPath = 'safety_costs/files/' . (int)$projectId . '/' . $ym . '/' . $storedName;
    return array(
        'has_file' => 1,
        'ok' => 1,
        'original_name' => $originalName,
        'stored_name' => $storedName,
        'stored_path' => $storedPath,
        'file_size' => $size,
        'mime_type' => 'application/pdf'
    );
}}

if (!function_exists('cpms_safety_cost_resolve_path')) {
function cpms_safety_cost_resolve_path($storedPath)
{
    $storedPath = str_replace('\\', '/', trim((string)$storedPath));
    $storedPath = ltrim($storedPath, '/');
    if ($storedPath === '' || strpos($storedPath, 'safety_costs/files/') !== 0) return '';
    $root = realpath(cpms_safety_cost_files_root());
    if ($root === false) return '';
    $candidate = realpath(cpms_storage_root() . '/' . $storedPath);
    if ($candidate === false || !is_file($candidate)) return '';
    $rootNorm = rtrim(str_replace('\\', '/', $root), '/');
    $candNorm = str_replace('\\', '/', $candidate);
    if (strpos($candNorm, $rootNorm . '/') !== 0) return '';
    return $candidate;
}}

if (!function_exists('cpms_safety_cost_file_exists')) {
function cpms_safety_cost_file_exists($row)
{
    if (!is_array($row) || !isset($row['pdf']) || !is_array($row['pdf'])) return false;
    $path = isset($row['pdf']['stored_path']) ? (string)$row['pdf']['stored_path'] : '';
    return cpms_safety_cost_resolve_path($path) !== '';
}}

if (!function_exists('cpms_safety_cost_pdf_links_html')) {
function cpms_safety_cost_pdf_links_html($row)
{
    if (!is_array($row) || !isset($row['pdf']) || !is_array($row['pdf'])) {
        return '<span class="text-gray-400 text-xs">첨부 없음</span>';
    }
    $id = isset($row['id']) ? (string)$row['id'] : '';
    $storedPath = isset($row['pdf']['stored_path']) ? (string)$row['pdf']['stored_path'] : '';
    if ($id === '' || trim($storedPath) === '') {
        return '<span class="text-gray-400 text-xs">첨부 없음</span>';
    }
    if (!cpms_safety_cost_file_exists($row)) {
        return '<span class="text-red-500 text-xs">PDF 파일 없음</span>';
    }
    $title = isset($row['pdf']['original_name']) ? (string)$row['pdf']['original_name'] : 'safety_cost.pdf';
    $viewUrl = base_url() . '/?r=safety/safety_cost_download&id=' . rawurlencode($id);
    $downUrl = $viewUrl . '&download=1';
    return '<span class="inline-flex flex-wrap gap-1">'
        . '<a class="inline-flex items-center px-2 py-1 rounded-lg border border-blue-200 bg-blue-50 text-blue-700 text-xs font-bold" target="_blank" href="' . h($viewUrl) . '" title="' . h($title) . '">PDF 보기</a>'
        . '<a class="inline-flex items-center px-2 py-1 rounded-lg border border-gray-200 bg-white text-gray-700 text-xs font-bold" href="' . h($downUrl) . '" title="' . h($title) . '">다운로드</a>'
        . '</span>';
}}

if (!function_exists('cpms_safety_cost_unit_row_amount')) {
function cpms_safety_cost_unit_row_amount($row)
{
    if (!is_array($row)) return 0.0;
    $amount = isset($row['amount']) && is_numeric((string)$row['amount']) ? (float)$row['amount'] : 0.0;
    if (abs($amount) > 0.0001) return $amount;
    $qty = isset($row['qty']) && is_numeric((string)$row['qty']) ? (float)$row['qty'] : 0.0;
    $safetyUnit = isset($row['safety_unit_price']) && is_numeric((string)$row['safety_unit_price']) ? (float)$row['safety_unit_price'] : 0.0;
    if (abs($qty) > 0.0001 && abs($safetyUnit) > 0.0001) return $qty * $safetyUnit;
    $unitPrice = isset($row['unit_price']) && is_numeric((string)$row['unit_price']) ? (float)$row['unit_price'] : 0.0;
    if (abs($qty) > 0.0001 && abs($unitPrice) > 0.0001) return $qty * $unitPrice;
    return $unitPrice;
}}

if (!function_exists('cpms_safety_cost_contract_total_query')) {
function cpms_safety_cost_contract_total_query($pdo, $projectId, $mode)
{
    $rows = array();
    if (!$pdo || (int)$projectId <= 0 || !cpms_safety_cost_table_exists($pdo, 'cpms_project_unit_prices')) return $rows;
    if (!cpms_safety_cost_column_exists($pdo, 'cpms_project_unit_prices', 'item_name')) return $rows;

    $select = array('item_name');
    foreach (array('spec', 'qty', 'unit_price', 'safety_unit_price', 'amount', 'is_safety') as $col) {
        if (cpms_safety_cost_column_exists($pdo, 'cpms_project_unit_prices', $col)) {
            $select[count($select)] = $col;
        } else {
            $select[count($select)] = "NULL AS " . $col;
        }
    }
    $where = array('project_id = :pid');
    if (cpms_safety_cost_column_exists($pdo, 'cpms_project_unit_prices', 'is_active')) {
        $where[count($where)] = '(is_active = 1 OR is_active IS NULL)';
    }
    if (cpms_safety_cost_column_exists($pdo, 'cpms_project_unit_prices', 'is_current')) {
        $where[count($where)] = '(is_current = 1 OR is_current IS NULL)';
    }

    if ($mode === 'exact') {
        $where[count($where)] = "TRIM(COALESCE(item_name, '')) = :exact_name";
    } else {
        $or = array("item_name LIKE :keyword");
        if (cpms_safety_cost_column_exists($pdo, 'cpms_project_unit_prices', 'spec')) {
            $or[count($or)] = "spec LIKE :keyword";
        }
        if (cpms_safety_cost_column_exists($pdo, 'cpms_project_unit_prices', 'is_safety')) {
            $or[count($or)] = "is_safety = 1";
        }
        $where[count($where)] = '(' . implode(' OR ', $or) . ')';
    }

    try {
        $sql = 'SELECT ' . implode(', ', $select) . ' FROM cpms_project_unit_prices WHERE ' . implode(' AND ', $where);
        $st = $pdo->prepare($sql);
        $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
        if ($mode === 'exact') {
            $st->bindValue(':exact_name', '안전관리비');
        } else {
            $st->bindValue(':keyword', '%안전관리비%');
        }
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) $rows = array();
    } catch (Exception $e) {
        $rows = array();
    }
    return $rows;
}}

if (!function_exists('cpms_safety_cost_contract_total')) {
function cpms_safety_cost_contract_total($pdo, $projectId)
{
    static $cache = array();
    $projectId = (int)$projectId;
    if (!$pdo || $projectId <= 0) return 0.0;
    $key = spl_object_hash($pdo) . ':' . $projectId;
    if (isset($cache[$key])) return $cache[$key];

    $rows = cpms_safety_cost_contract_total_query($pdo, $projectId, 'exact');
    if (count($rows) === 0) {
        $rows = cpms_safety_cost_contract_total_query($pdo, $projectId, 'fallback');
    }
    $sum = 0.0;
    foreach ($rows as $row) {
        $sum += cpms_safety_cost_unit_row_amount($row);
    }
    $cache[$key] = $sum;
    return $cache[$key];
}}

if (!function_exists('cpms_safety_cost_rate_label')) {
function cpms_safety_cost_rate_label($value)
{
    return number_format((float)$value, 1) . '%';
}}

if (!function_exists('cpms_safety_cost_money_label')) {
function cpms_safety_cost_money_label($value)
{
    return number_format((float)round((float)$value)) . '원';
}}

if (!function_exists('cpms_safety_cost_summary')) {
function cpms_safety_cost_summary($pdo, $projectId)
{
    $projectId = (int)$projectId;
    $contractTotal = cpms_safety_cost_contract_total($pdo, $projectId);
    $limit110 = round($contractTotal * 1.1);
    $usedTotal = cpms_safety_cost_total($projectId);
    $remaining = $limit110 - $usedTotal;
    $useRate = ($contractTotal > 0) ? (($usedTotal / $contractTotal) * 100) : 0.0;
    $remainingRate = ($limit110 > 0) ? (($remaining / $limit110) * 100) : 0.0;
    $limitUseRate = ($limit110 > 0) ? (($usedTotal / $limit110) * 100) : 0.0;
    return array(
        'contract_total' => $contractTotal,
        'limit_110' => $limit110,
        'used_total' => $usedTotal,
        'remaining' => $remaining,
        'use_rate' => $useRate,
        'remaining_rate' => $remainingRate,
        'limit_use_rate' => $limitUseRate
    );
}}
