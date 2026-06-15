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

if (!function_exists('cpms_samsung_portal_label')) {
function cpms_samsung_portal_label($encoded)
{
    return urldecode((string)$encoded);
}}

if (!function_exists('cpms_samsung_portal_store_template')) {
function cpms_samsung_portal_store_template($data)
{
    if (!is_array($data)) $data = array();
    if (!isset($data['items']) || !is_array($data['items'])) $data['items'] = array();
    if (!isset($data['samsung_portal']) || !is_array($data['samsung_portal'])) $data['samsung_portal'] = array();
    if (!isset($data['samsung_portal']['records']) || !is_array($data['samsung_portal']['records'])) $data['samsung_portal']['records'] = array();
    if (!isset($data['samsung_portal']['automation_runs']) || !is_array($data['samsung_portal']['automation_runs'])) $data['samsung_portal']['automation_runs'] = array();
    if (!isset($data['samsung_portal']['task_map']) || !is_array($data['samsung_portal']['task_map'])) $data['samsung_portal']['task_map'] = array();
    if (!isset($data['samsung_portal']['completion_logs']) || !is_array($data['samsung_portal']['completion_logs'])) $data['samsung_portal']['completion_logs'] = array();
    if (!isset($data['samsung_portal']['employment_checks']) || !is_array($data['samsung_portal']['employment_checks'])) $data['samsung_portal']['employment_checks'] = array();
    return $data;
}}

if (!function_exists('cpms_samsung_portal_load_store')) {
function cpms_samsung_portal_load_store()
{
    return cpms_samsung_portal_store_template(cpms_read_json_file(cpms_safety_cost_store_path(), array('items' => array())));
}}

if (!function_exists('cpms_samsung_portal_save_store')) {
function cpms_samsung_portal_save_store($data)
{
    $data = cpms_samsung_portal_store_template($data);
    $data['updated_at'] = date('Y-m-d H:i:s');
    return cpms_write_json_file(cpms_safety_cost_store_path(), $data);
}}

if (!function_exists('cpms_samsung_portal_trim')) {
function cpms_samsung_portal_trim($value)
{
    return trim((string)$value);
}}

if (!function_exists('cpms_samsung_portal_normalize_header')) {
function cpms_samsung_portal_normalize_header($value)
{
    $value = trim((string)$value);
    $value = str_replace(array(' ', "\t", "\r", "\n"), '', $value);
    return $value;
}}

if (!function_exists('cpms_samsung_portal_employee_key')) {
function cpms_samsung_portal_employee_key($name, $loginId)
{
    $base = strtolower(trim((string)$name)) . '|' . strtolower(trim((string)$loginId));
    return 'sp_' . substr(md5($base), 0, 24);
}}

if (!function_exists('cpms_samsung_portal_valid_date')) {
function cpms_samsung_portal_valid_date($date)
{
    return cpms_safety_cost_valid_date($date);
}}

if (!function_exists('cpms_samsung_portal_parse_expire_date')) {
function cpms_samsung_portal_parse_expire_date($value)
{
    $value = trim((string)$value);
    if ($value === '') return '';
    if (preg_match('/\((\d{4}-\d{2}-\d{2})\)/', $value, $m)) {
        return cpms_samsung_portal_valid_date($m[1]);
    }
    if (preg_match('/(\d{4})[\.\-\/](\d{1,2})[\.\-\/](\d{1,2})/', $value, $m)) {
        $date = sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
        return cpms_samsung_portal_valid_date($date);
    }
    return '';
}}

if (!function_exists('cpms_samsung_portal_date_status')) {
function cpms_samsung_portal_date_status($date)
{
    $date = cpms_samsung_portal_valid_date($date);
    if ($date === '') {
        return array('label' => cpms_samsung_portal_label('%EB%82%A0%EC%A7%9C%20%EC%97%86%EC%9D%8C'), 'class' => 'bg-slate-100 text-slate-600 border-slate-200', 'days' => null);
    }
    $today = date('Y-m-d');
    $diff = (int)floor((strtotime($date) - strtotime($today)) / 86400);
    if ($diff < 0) {
        return array('label' => cpms_samsung_portal_label('%EB%A7%8C%EB%A3%8C%EB%90%A8'), 'class' => 'bg-red-50 text-red-700 border-red-200', 'days' => $diff);
    }
    if ($diff === 0) {
        return array('label' => cpms_samsung_portal_label('%EC%98%A4%EB%8A%98%20%EB%A7%8C%EB%A3%8C'), 'class' => 'bg-rose-50 text-rose-700 border-rose-200', 'days' => $diff);
    }
    if ($diff <= 10) {
        return array('label' => cpms_samsung_portal_label('10%EC%9D%BC%20%EC%9D%B4%EB%82%B4%20%EB%A7%8C%EB%A3%8C'), 'class' => 'bg-amber-50 text-amber-700 border-amber-200', 'days' => $diff);
    }
    return array('label' => cpms_samsung_portal_label('%EC%A0%95%EC%83%81'), 'class' => 'bg-emerald-50 text-emerald-700 border-emerald-200', 'days' => $diff);
}}

if (!function_exists('cpms_samsung_portal_string_contains')) {
function cpms_samsung_portal_string_contains($haystack, $needle)
{
    $haystack = (string)$haystack;
    $needle = (string)$needle;
    if ($needle === '') return true;
    if (function_exists('mb_stripos')) {
        return mb_stripos($haystack, $needle, 0, 'UTF-8') !== false;
    }
    return stripos($haystack, $needle) !== false;
}}

if (!function_exists('cpms_samsung_portal_records')) {
function cpms_samsung_portal_records($query)
{
    $data = cpms_samsung_portal_load_store();
    $records = isset($data['samsung_portal']['records']) ? $data['samsung_portal']['records'] : array();
    $rows = array();
    $query = trim((string)$query);
    foreach ($records as $key => $row) {
        if (!is_array($row)) continue;
        if (!cpms_samsung_portal_record_is_active($row)) continue;
        $row['record_key'] = (string)$key;
        $name = isset($row['name']) ? (string)$row['name'] : '';
        $loginId = isset($row['login_id']) ? (string)$row['login_id'] : '';
        if ($query !== '' && !cpms_samsung_portal_string_contains($name, $query) && !cpms_samsung_portal_string_contains($loginId, $query)) {
            continue;
        }
        $rows[count($rows)] = $row;
    }
    usort($rows, function($a, $b) {
        $an = isset($a['name']) ? (string)$a['name'] : '';
        $bn = isset($b['name']) ? (string)$b['name'] : '';
        $cmp = strcmp($an, $bn);
        if ($cmp !== 0) return $cmp;
        return strcmp(isset($a['login_id']) ? (string)$a['login_id'] : '', isset($b['login_id']) ? (string)$b['login_id'] : '');
    });
    return $rows;
}}

if (!function_exists('cpms_samsung_portal_record_is_active')) {
function cpms_samsung_portal_record_is_active($row)
{
    if (!is_array($row)) return false;
    if (isset($row['is_deleted']) && (int)$row['is_deleted'] === 1) return false;
    $status = isset($row['status']) ? strtolower(trim((string)$row['status'])) : '';
    if ($status === 'deleted' || $status === 'cancelled') return false;
    return true;
}}

if (!function_exists('cpms_samsung_portal_files_root')) {
function cpms_samsung_portal_files_root()
{
    return cpms_storage_root() . '/safety_costs/samsung_portal_files';
}}

if (!function_exists('cpms_samsung_portal_health_type_label')) {
function cpms_samsung_portal_health_type_label($type)
{
    $type = trim((string)$type);
    if ($type === 'pre_placement') return cpms_samsung_portal_label('%EB%B0%B0%EC%B9%98%EC%A0%84%EA%B1%B4%EA%B0%95%EA%B2%80%EC%A7%84');
    if ($type === 'general') return cpms_samsung_portal_label('%EC%9D%BC%EB%B0%98%EA%B1%B4%EA%B0%95%EA%B2%80%EC%A7%84');
    return '';
}}

if (!function_exists('cpms_samsung_portal_health_type_valid')) {
function cpms_samsung_portal_health_type_valid($type)
{
    return ((string)$type === 'pre_placement' || (string)$type === 'general');
}}

if (!function_exists('cpms_samsung_portal_health_record')) {
function cpms_samsung_portal_health_record($row, $type)
{
    if (!is_array($row) || !cpms_samsung_portal_health_type_valid($type)) return array();
    if (!isset($row['health_checks']) || !is_array($row['health_checks'])) return array();
    if (!isset($row['health_checks'][$type]) || !is_array($row['health_checks'][$type])) return array();
    return $row['health_checks'][$type];
}}

if (!function_exists('cpms_samsung_portal_health_uploaded_at')) {
function cpms_samsung_portal_health_uploaded_at($row, $type)
{
    $file = cpms_samsung_portal_health_record($row, $type);
    return isset($file['uploaded_at']) ? (string)$file['uploaded_at'] : '';
}}

if (!function_exists('cpms_samsung_portal_safe_file_name')) {
function cpms_samsung_portal_safe_file_name($recordKey, $type, $originalName)
{
    $ext = strtolower(pathinfo((string)$originalName, PATHINFO_EXTENSION));
    $recordKey = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$recordKey);
    $type = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$type);
    return 'samsung_health_' . $recordKey . '_' . $type . '_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 8) . '.' . $ext;
}}

if (!function_exists('cpms_samsung_portal_store_health_file')) {
function cpms_samsung_portal_store_health_file($fieldName, $recordKey, $type, &$message)
{
    $message = '';
    $result = array();
    if (!cpms_samsung_portal_health_type_valid($type)) {
        $message = cpms_samsung_portal_label('%EA%B1%B4%EA%B0%95%EA%B2%80%EC%A7%84%20%EA%B5%AC%EB%B6%84%EC%9D%B4%20%EC%98%AC%EB%B0%94%EB%A5%B4%EC%A7%80%20%EC%95%8A%EC%8A%B5%EB%8B%88%EB%8B%A4.');
        return $result;
    }
    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
        $message = cpms_samsung_portal_label('%EC%97%85%EB%A1%9C%EB%93%9C%ED%95%A0%20%ED%8C%8C%EC%9D%BC%EC%9D%84%20%EC%84%A0%ED%83%9D%ED%95%B4%EC%A3%BC%EC%84%B8%EC%9A%94.');
        return $result;
    }
    $file = $_FILES[$fieldName];
    $error = isset($file['error']) ? (int)$file['error'] : UPLOAD_ERR_NO_FILE;
    if ($error !== UPLOAD_ERR_OK) {
        $message = cpms_samsung_portal_label('%EA%B1%B4%EA%B0%95%EA%B2%80%EC%A7%84%20%ED%8C%8C%EC%9D%BC%20%EC%97%85%EB%A1%9C%EB%93%9C%EC%97%90%20%EC%8B%A4%ED%8C%A8%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.');
        return $result;
    }
    $tmp = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        $message = cpms_samsung_portal_label('%EC%A0%95%EC%83%81%EC%A0%81%EC%9D%B8%20%EC%97%85%EB%A1%9C%EB%93%9C%20%ED%8C%8C%EC%9D%BC%EC%9D%B4%20%EC%95%84%EB%8B%99%EB%8B%88%EB%8B%A4.');
        return $result;
    }
    $originalName = isset($file['name']) ? (string)$file['name'] : '';
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = array('pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png');
    if (!isset($allowed[$ext])) {
        $message = cpms_samsung_portal_label('PDF/JPG/PNG%20%ED%8C%8C%EC%9D%BC%EB%A7%8C%20%EC%97%85%EB%A1%9C%EB%93%9C%ED%95%A0%20%EC%88%98%20%EC%9E%88%EC%8A%B5%EB%8B%88%EB%8B%A4.');
        return $result;
    }
    $size = isset($file['size']) ? (int)$file['size'] : 0;
    if ($size <= 0 || $size > 20 * 1024 * 1024) {
        $message = cpms_samsung_portal_label('%ED%8C%8C%EC%9D%BC%20%EC%9A%A9%EB%9F%89%EC%9D%84%20%ED%99%95%EC%9D%B8%ED%95%B4%EC%A3%BC%EC%84%B8%EC%9A%94.%20%EC%B5%9C%EB%8C%80%2020MB');
        return $result;
    }
    $safeRecordKey = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$recordKey);
    if ($safeRecordKey === '') {
        $message = cpms_samsung_portal_label('%EB%8C%80%EC%83%81%20%EC%9D%B8%EC%9B%90%20%EC%A0%95%EB%B3%B4%EA%B0%80%20%EC%98%AC%EB%B0%94%EB%A5%B4%EC%A7%80%20%EC%95%8A%EC%8A%B5%EB%8B%88%EB%8B%A4.');
        return $result;
    }
    $dir = cpms_samsung_portal_files_root() . '/' . $safeRecordKey . '/' . $type;
    if (!cpms_ensure_dir($dir)) {
        $message = cpms_samsung_portal_label('%ED%8C%8C%EC%9D%BC%20%EC%A0%80%EC%9E%A5%20%ED%8F%B4%EB%8D%94%EB%A5%BC%20%EB%A7%8C%EB%93%A4%20%EC%88%98%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.');
        return $result;
    }
    $storedName = cpms_samsung_portal_safe_file_name($safeRecordKey, $type, $originalName);
    $dest = $dir . '/' . $storedName;
    if (!@move_uploaded_file($tmp, $dest)) {
        $message = cpms_samsung_portal_label('%ED%8C%8C%EC%9D%BC%20%EC%A0%80%EC%9E%A5%EC%97%90%20%EC%8B%A4%ED%8C%A8%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.');
        return $result;
    }
    return array(
        'original_name' => $originalName,
        'stored_name' => $storedName,
        'stored_path' => 'safety_costs/samsung_portal_files/' . $safeRecordKey . '/' . $type . '/' . $storedName,
        'file_size' => $size,
        'mime_type' => $allowed[$ext],
        'uploaded_at' => date('Y-m-d H:i:s'),
        'uploaded_by' => cpms_samsung_portal_user_label()
    );
}}

if (!function_exists('cpms_samsung_portal_resolve_health_path')) {
function cpms_samsung_portal_resolve_health_path($storedPath)
{
    $storedPath = str_replace('\\', '/', trim((string)$storedPath));
    $storedPath = ltrim($storedPath, '/');
    if ($storedPath === '' || strpos($storedPath, 'safety_costs/samsung_portal_files/') !== 0) return '';
    $root = realpath(cpms_samsung_portal_files_root());
    if ($root === false) return '';
    $candidate = realpath(cpms_storage_root() . '/' . $storedPath);
    if ($candidate === false || !is_file($candidate)) return '';
    $rootNorm = rtrim(str_replace('\\', '/', $root), '/');
    $candidateNorm = str_replace('\\', '/', $candidate);
    if (strpos($candidateNorm, $rootNorm . '/') !== 0) return '';
    return $candidate;
}}

if (!function_exists('cpms_samsung_portal_health_file_exists')) {
function cpms_samsung_portal_health_file_exists($row, $type)
{
    $file = cpms_samsung_portal_health_record($row, $type);
    $path = isset($file['stored_path']) ? (string)$file['stored_path'] : '';
    return cpms_samsung_portal_resolve_health_path($path) !== '';
}}

if (!function_exists('cpms_samsung_portal_health_file_url')) {
function cpms_samsung_portal_health_file_url($recordKey, $type)
{
    if (!cpms_samsung_portal_health_type_valid($type)) return '';
    return base_url() . '/?r=safety/samsung_portal_health_download&record_key=' . rawurlencode((string)$recordKey) . '&type=' . rawurlencode((string)$type);
}}

if (!function_exists('cpms_samsung_portal_summary')) {
function cpms_samsung_portal_summary($records)
{
    $summary = array('total' => 0, 'soon' => 0, 'today' => 0, 'expired' => 0, 'missing' => 0);
    if (!is_array($records)) return $summary;
    $summary['total'] = count($records);
    for ($i = 0; $i < count($records); $i++) {
        $dates = array(
            isset($records[$i]['safety_training_expire_date']) ? $records[$i]['safety_training_expire_date'] : '',
            isset($records[$i]['chemical_training_expire_date']) ? $records[$i]['chemical_training_expire_date'] : ''
        );
        for ($j = 0; $j < count($dates); $j++) {
            $st = cpms_samsung_portal_date_status($dates[$j]);
            $label = isset($st['label']) ? (string)$st['label'] : '';
            if ($label === cpms_samsung_portal_label('%EB%A7%8C%EB%A3%8C%EB%90%A8')) $summary['expired']++;
            else if ($label === cpms_samsung_portal_label('%EC%98%A4%EB%8A%98%20%EB%A7%8C%EB%A3%8C')) $summary['today']++;
            else if ($label === cpms_samsung_portal_label('10%EC%9D%BC%20%EC%9D%B4%EB%82%B4%20%EB%A7%8C%EB%A3%8C')) $summary['soon']++;
            else if ($label === cpms_samsung_portal_label('%EB%82%A0%EC%A7%9C%20%EC%97%86%EC%9D%8C')) $summary['missing']++;
        }
    }
    return $summary;
}}

if (!function_exists('cpms_samsung_portal_is_safety_department')) {
function cpms_samsung_portal_is_safety_department($department)
{
    $department = trim((string)$department);
    $safety = cpms_samsung_portal_label('%EC%95%88%EC%A0%84');
    $normalized = function_exists('cpms_safety_cost_normalize_dept') ? cpms_safety_cost_normalize_dept($department) : $department;
    if ($normalized === $safety || $department === $safety) return true;
    if ($department !== '' && cpms_samsung_portal_string_contains($department, $safety)) return true;
    if (cpms_samsung_portal_string_contains(strtolower($department), 'safety')) return true;
    return false;
}}

if (!function_exists('cpms_samsung_portal_can_view')) {
function cpms_samsung_portal_can_view()
{
    if (!class_exists('App\\Core\\Auth') || !\App\Core\Auth::check()) return false;
    if (\App\Core\Auth::isMaster() || \App\Core\Auth::userRole() === 'executive') return true;
    return cpms_samsung_portal_is_safety_department(\App\Core\Auth::userDepartment());
}}

if (!function_exists('cpms_samsung_portal_can_edit')) {
function cpms_samsung_portal_can_edit()
{
    if (!class_exists('App\\Core\\Auth') || !\App\Core\Auth::check()) return false;
    if (\App\Core\Auth::isMaster()) return true;
    return cpms_samsung_portal_is_safety_department(\App\Core\Auth::userDepartment());
}}

if (!function_exists('cpms_samsung_portal_user_label')) {
function cpms_samsung_portal_user_label()
{
    if (!class_exists('App\\Core\\Auth') || !\App\Core\Auth::check()) return '';
    $name = trim((string)\App\Core\Auth::userName());
    $email = trim((string)\App\Core\Auth::userEmail());
    if ($name !== '') return $name;
    return $email;
}}

if (!function_exists('cpms_samsung_portal_read_xlsx_rows')) {
function cpms_samsung_portal_read_xlsx_rows($path, &$message)
{
    $message = '';
    $rows = array();
    $appRoot = dirname(dirname(__DIR__));
    if (!class_exists('App\\Core\\EstimateXlsxReader')) {
        $readerPath = $appRoot . '/core/EstimateXlsxReader.php';
        if (is_file($readerPath)) require_once $readerPath;
    }
    if (class_exists('App\\Core\\EstimateXlsxReader')) {
        $sheetName = cpms_samsung_portal_label('%EC%82%AC%EC%9A%A9%EC%9E%90%EB%AA%A9%EB%A1%9D');
        $res = \App\Core\EstimateXlsxReader::readSheetByName($path, $sheetName, 5000);
        if (is_array($res) && empty($res['error']) && isset($res['rows']) && is_array($res['rows'])) {
            return $res['rows'];
        }
        $message = (is_array($res) && isset($res['error'])) ? (string)$res['error'] : '';
    }
    if (!class_exists('App\\Core\\SimpleXlsxReader')) {
        $readerPath = $appRoot . '/core/SimpleXlsxReader.php';
        if (is_file($readerPath)) require_once $readerPath;
    }
    if (class_exists('App\\Core\\SimpleXlsxReader')) {
        $res = \App\Core\SimpleXlsxReader::readFirstSheet($path, 5000);
        if (is_array($res) && empty($res['error']) && isset($res['rows']) && is_array($res['rows'])) {
            return $res['rows'];
        }
        $message = (is_array($res) && isset($res['error'])) ? (string)$res['error'] : $message;
    }
    if ($message === '') $message = cpms_samsung_portal_label('%EC%97%91%EC%85%80%20%ED%8C%8C%EC%9D%BC%EC%9D%84%20%EC%9D%BD%EC%9D%84%20%EC%88%98%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.');
    return $rows;
}}

if (!function_exists('cpms_samsung_portal_import_xlsx')) {
function cpms_samsung_portal_import_xlsx($filePath, $actor)
{
    $result = array('ok' => false, 'message' => '', 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'missing_headers' => array());
    $rows = cpms_samsung_portal_read_xlsx_rows($filePath, $readMessage);
    if (count($rows) < 2) {
        $result['message'] = $readMessage !== '' ? $readMessage : cpms_samsung_portal_label('%EC%97%91%EC%85%80%202%ED%96%89%20%ED%97%A4%EB%8D%94%EB%A5%BC%20%EC%B0%BE%EC%A7%80%20%EB%AA%BB%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.');
        return $result;
    }
    $required = array(
        'name' => cpms_samsung_portal_label('%EC%9E%84%EC%A7%81%EC%9B%90%EB%AA%85'),
        'login_id' => cpms_samsung_portal_label('%EC%95%84%EC%9D%B4%EB%94%94'),
        'safety_training' => cpms_samsung_portal_label('%EC%B6%9C%EC%9E%85%EC%9E%90%20%EC%95%88%EC%A0%84%EA%B5%90%EC%9C%A1'),
        'chemical_training' => cpms_samsung_portal_label('%EC%9C%A0%ED%95%B4%ED%99%94%ED%95%99%EB%AC%BC%EC%A7%88%20%EC%A2%85%EC%82%AC%EC%9E%90%EA%B5%90%EC%9C%A1')
    );
    $header = isset($rows[1]) && is_array($rows[1]) ? $rows[1] : array();
    $indexes = array();
    foreach ($required as $key => $label) {
        $target = cpms_samsung_portal_normalize_header($label);
        for ($i = 0; $i < count($header); $i++) {
            if (cpms_samsung_portal_normalize_header(isset($header[$i]) ? $header[$i] : '') === $target) {
                $indexes[$key] = $i;
                break;
            }
        }
        if (!isset($indexes[$key])) $result['missing_headers'][count($result['missing_headers'])] = $label;
    }
    if (count($result['missing_headers']) > 0) {
        $result['message'] = cpms_samsung_portal_label('%ED%95%84%EC%88%98%20%ED%97%A4%EB%8D%94%EA%B0%80%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4: ') . implode(', ', $result['missing_headers']);
        return $result;
    }

    $data = cpms_samsung_portal_load_store();
    $now = date('Y-m-d H:i:s');
    $records = isset($data['samsung_portal']['records']) ? $data['samsung_portal']['records'] : array();
    for ($r = 2; $r < count($rows); $r++) {
        $row = is_array($rows[$r]) ? $rows[$r] : array();
        $name = cpms_samsung_portal_trim(isset($row[$indexes['name']]) ? $row[$indexes['name']] : '');
        $loginId = cpms_samsung_portal_trim(isset($row[$indexes['login_id']]) ? $row[$indexes['login_id']] : '');
        if ($name === '' || $loginId === '') {
            $result['skipped']++;
            continue;
        }
        $safetyRaw = cpms_samsung_portal_trim(isset($row[$indexes['safety_training']]) ? $row[$indexes['safety_training']] : '');
        $chemicalRaw = cpms_samsung_portal_trim(isset($row[$indexes['chemical_training']]) ? $row[$indexes['chemical_training']] : '');
        $key = cpms_samsung_portal_employee_key($name, $loginId);
        $old = isset($records[$key]) && is_array($records[$key]) ? $records[$key] : array();
        $record = $old;
        $record['name'] = $name;
        $record['login_id'] = $loginId;
        $record['password'] = isset($old['password']) ? (string)$old['password'] : '';
        $record['phone'] = isset($old['phone']) ? (string)$old['phone'] : '';
        $record['carrier'] = isset($old['carrier']) ? (string)$old['carrier'] : '';
        $record['safety_training_text'] = $safetyRaw;
        $record['safety_training_expire_date'] = cpms_samsung_portal_parse_expire_date($safetyRaw);
        $record['chemical_training_text'] = $chemicalRaw;
        $record['chemical_training_expire_date'] = cpms_samsung_portal_parse_expire_date($chemicalRaw);
        if (!isset($record['created_at']) || trim((string)$record['created_at']) === '') $record['created_at'] = $now;
        $record['last_excel_upload_at'] = $now;
        $record['last_modified_by'] = (string)$actor;
        $record['last_modified_at'] = $now;
        $records[$key] = $record;
        if (count($old) === 0) $result['inserted']++;
        else $result['updated']++;
    }
    $data['samsung_portal']['records'] = $records;
    $data['samsung_portal']['last_upload_at'] = $now;
    $data['samsung_portal']['last_upload_by'] = (string)$actor;
    if (!cpms_samsung_portal_save_store($data)) {
        $result['message'] = cpms_samsung_portal_label('%EC%82%BC%EC%84%B1%20%ED%8F%AC%ED%83%88%20%EB%8D%B0%EC%9D%B4%ED%84%B0%20%EC%A0%80%EC%9E%A5%EC%97%90%20%EC%8B%A4%ED%8C%A8%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.');
        return $result;
    }
    $result['ok'] = true;
    $result['message'] = cpms_samsung_portal_label('%EC%97%85%EB%A1%9C%EB%93%9C%20%EC%99%84%EB%A3%8C');
    return $result;
}}

if (!function_exists('cpms_samsung_portal_redirect')) {
function cpms_samsung_portal_redirect($extra)
{
    $url = '?r=safety_home&tab=samsung_portal';
    $q = trim((string)$extra);
    if ($q !== '') $url .= '&' . ltrim($q, '&');
    header('Location: ' . $url);
    exit;
}}

if (!function_exists('cpms_samsung_portal_handle_upload_request')) {
function cpms_samsung_portal_handle_upload_request($pdo)
{
    if (!class_exists('App\\Core\\Auth') || !\App\Core\Auth::check()) {
        header('Location: ?r=login');
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
        flash_set('error', cpms_samsung_portal_label('%EB%B3%B4%EC%95%88%20%ED%86%A0%ED%81%B0%EC%9D%B4%20%EC%98%AC%EB%B0%94%EB%A5%B4%EC%A7%80%20%EC%95%8A%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
        cpms_samsung_portal_redirect('');
    }
    if (!cpms_samsung_portal_can_edit()) {
        flash_set('error', cpms_samsung_portal_label('%EC%82%BC%EC%84%B1%20%EC%83%81%EC%83%9D%ED%98%91%EB%A0%A5%ED%8F%AC%ED%83%88%20%EC%88%98%EC%A0%95%20%EA%B6%8C%ED%95%9C%EC%9D%B4%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
        cpms_samsung_portal_redirect('');
    }
    if (!isset($_FILES['samsung_excel']) || !is_array($_FILES['samsung_excel'])) {
        flash_set('error', cpms_samsung_portal_label('%EC%97%85%EB%A1%9C%EB%93%9C%ED%95%A0%20%EC%97%91%EC%85%80%20%ED%8C%8C%EC%9D%BC%EC%9D%84%20%EC%84%A0%ED%83%9D%ED%95%B4%EC%A3%BC%EC%84%B8%EC%9A%94.'));
        cpms_samsung_portal_redirect('');
    }
    $file = $_FILES['samsung_excel'];
    $error = isset($file['error']) ? (int)$file['error'] : UPLOAD_ERR_NO_FILE;
    if ($error !== UPLOAD_ERR_OK) {
        flash_set('error', cpms_samsung_portal_label('%EC%97%91%EC%85%80%20%EC%97%85%EB%A1%9C%EB%93%9C%EC%97%90%20%EC%8B%A4%ED%8C%A8%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
        cpms_samsung_portal_redirect('');
    }
    $name = isset($file['name']) ? (string)$file['name'] : '';
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext !== 'xlsx') {
        flash_set('error', cpms_samsung_portal_label('xlsx%20%ED%8C%8C%EC%9D%BC%EB%A7%8C%20%EC%97%85%EB%A1%9C%EB%93%9C%ED%95%A0%20%EC%88%98%20%EC%9E%88%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
        cpms_samsung_portal_redirect('');
    }
    $tmp = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        flash_set('error', cpms_samsung_portal_label('%EC%A0%95%EC%83%81%EC%A0%81%EC%9D%B8%20%EC%97%85%EB%A1%9C%EB%93%9C%20%ED%8C%8C%EC%9D%BC%EC%9D%B4%20%EC%95%84%EB%8B%99%EB%8B%88%EB%8B%A4.'));
        cpms_samsung_portal_redirect('');
    }
    $result = cpms_samsung_portal_import_xlsx($tmp, cpms_samsung_portal_user_label());
    if (empty($result['ok'])) {
        flash_set('error', isset($result['message']) ? $result['message'] : cpms_samsung_portal_label('%EC%97%85%EB%A1%9C%EB%93%9C%20%EC%8B%A4%ED%8C%A8'));
        cpms_samsung_portal_redirect('');
    }
    if ($pdo && function_exists('cpms_samsung_portal_bootstrap_automations')) {
        cpms_samsung_portal_bootstrap_automations($pdo, true);
    }
    $message = cpms_samsung_portal_label('%EC%97%85%EB%A1%9C%EB%93%9C%20%EC%99%84%EB%A3%8C: ')
        . cpms_samsung_portal_label('%EC%8B%A0%EA%B7%9C') . ' ' . (int)$result['inserted']
        . ', ' . cpms_samsung_portal_label('%EA%B0%B1%EC%8B%A0') . ' ' . (int)$result['updated']
        . ', ' . cpms_samsung_portal_label('%EC%A0%9C%EC%99%B8') . ' ' . (int)$result['skipped'];
    flash_set('success', $message);
    cpms_samsung_portal_redirect('');
}}

if (!function_exists('cpms_samsung_portal_handle_save_request')) {
function cpms_samsung_portal_handle_save_request($pdo)
{
    if (!class_exists('App\\Core\\Auth') || !\App\Core\Auth::check()) {
        header('Location: ?r=login');
        exit;
    }
    $search = isset($_POST['q']) ? trim((string)$_POST['q']) : '';
    $extra = $search !== '' ? 'q=' . rawurlencode($search) : '';
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
        flash_set('error', cpms_samsung_portal_label('%EB%B3%B4%EC%95%88%20%ED%86%A0%ED%81%B0%EC%9D%B4%20%EC%98%AC%EB%B0%94%EB%A5%B4%EC%A7%80%20%EC%95%8A%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
        cpms_samsung_portal_redirect($extra);
    }
    if (!cpms_samsung_portal_can_edit()) {
        flash_set('error', cpms_samsung_portal_label('%EC%82%BC%EC%84%B1%20%EC%83%81%EC%83%9D%ED%98%91%EB%A0%A5%ED%8F%AC%ED%83%88%20%EC%88%98%EC%A0%95%20%EA%B6%8C%ED%95%9C%EC%9D%B4%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
        cpms_samsung_portal_redirect($extra);
    }
    $recordKey = isset($_POST['record_key']) ? trim((string)$_POST['record_key']) : '';
    $data = cpms_samsung_portal_load_store();
    if ($recordKey === '' || !isset($data['samsung_portal']['records'][$recordKey]) || !is_array($data['samsung_portal']['records'][$recordKey])) {
        flash_set('error', cpms_samsung_portal_label('%EC%A0%80%EC%9E%A5%ED%95%A0%20%EB%8C%80%EC%83%81%EC%9D%84%20%EC%B0%BE%EC%A7%80%20%EB%AA%BB%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
        cpms_samsung_portal_redirect($extra);
    }
    $row = $data['samsung_portal']['records'][$recordKey];
    $row['password'] = isset($_POST['password']) ? trim((string)$_POST['password']) : '';
    $row['phone'] = isset($_POST['phone']) ? trim((string)$_POST['phone']) : '';
    $row['carrier'] = isset($_POST['carrier']) ? trim((string)$_POST['carrier']) : '';
    $row['last_modified_by'] = cpms_samsung_portal_user_label();
    $row['last_modified_at'] = date('Y-m-d H:i:s');
    $data['samsung_portal']['records'][$recordKey] = $row;
    if (!cpms_samsung_portal_save_store($data)) {
        flash_set('error', cpms_samsung_portal_label('%EC%A0%80%EC%9E%A5%EC%97%90%20%EC%8B%A4%ED%8C%A8%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
        cpms_samsung_portal_redirect($extra);
    }
    flash_set('success', cpms_samsung_portal_label('%EC%A0%80%EC%9E%A5%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
    cpms_samsung_portal_redirect($extra);
}}

if (!function_exists('cpms_samsung_portal_handle_delete_request')) {
function cpms_samsung_portal_handle_delete_request($pdo)
{
    if (!class_exists('App\\Core\\Auth') || !\App\Core\Auth::check()) {
        header('Location: ?r=login');
        exit;
    }
    $search = isset($_POST['q']) ? trim((string)$_POST['q']) : '';
    $extra = $search !== '' ? 'q=' . rawurlencode($search) : '';
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
        flash_set('error', cpms_samsung_portal_label('%EB%B3%B4%EC%95%88%20%ED%86%A0%ED%81%B0%EC%9D%B4%20%EC%98%AC%EB%B0%94%EB%A5%B4%EC%A7%80%20%EC%95%8A%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
        cpms_samsung_portal_redirect($extra);
    }
    if (!cpms_samsung_portal_can_edit()) {
        flash_set('error', cpms_samsung_portal_label('%EC%82%AD%EC%A0%9C%20%EA%B6%8C%ED%95%9C%EC%9D%B4%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
        cpms_samsung_portal_redirect($extra);
    }
    $recordKey = isset($_POST['record_key']) ? trim((string)$_POST['record_key']) : '';
    $data = cpms_samsung_portal_load_store();
    if ($recordKey === '' || !isset($data['samsung_portal']['records'][$recordKey]) || !is_array($data['samsung_portal']['records'][$recordKey])) {
        flash_set('error', cpms_samsung_portal_label('%EC%82%AD%EC%A0%9C%ED%95%A0%20%EB%8C%80%EC%83%81%EC%9D%84%20%EC%B0%BE%EC%A7%80%20%EB%AA%BB%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
        cpms_samsung_portal_redirect($extra);
    }
    $data['samsung_portal']['records'][$recordKey]['is_deleted'] = 1;
    $data['samsung_portal']['records'][$recordKey]['status'] = 'deleted';
    $data['samsung_portal']['records'][$recordKey]['deleted_at'] = date('Y-m-d H:i:s');
    $data['samsung_portal']['records'][$recordKey]['deleted_by'] = cpms_samsung_portal_user_label();
    $data['samsung_portal']['records'][$recordKey]['last_modified_by'] = cpms_samsung_portal_user_label();
    $data['samsung_portal']['records'][$recordKey]['last_modified_at'] = date('Y-m-d H:i:s');
    if (!cpms_samsung_portal_save_store($data)) {
        flash_set('error', cpms_samsung_portal_label('%EC%82%AD%EC%A0%9C%20%EC%A0%80%EC%9E%A5%EC%97%90%20%EC%8B%A4%ED%8C%A8%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
        cpms_samsung_portal_redirect($extra);
    }
    flash_set('success', cpms_samsung_portal_label('%EB%AA%A9%EB%A1%9D%EC%97%90%EC%84%9C%20%EC%82%AD%EC%A0%9C%20%EC%B2%98%EB%A6%AC%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
    cpms_samsung_portal_redirect($extra);
}}

if (!function_exists('cpms_samsung_portal_handle_health_upload_request')) {
function cpms_samsung_portal_handle_health_upload_request($pdo)
{
    if (!class_exists('App\\Core\\Auth') || !\App\Core\Auth::check()) {
        header('Location: ?r=login');
        exit;
    }
    $search = isset($_POST['q']) ? trim((string)$_POST['q']) : '';
    $extra = $search !== '' ? 'q=' . rawurlencode($search) : '';
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
        flash_set('error', cpms_samsung_portal_label('%EB%B3%B4%EC%95%88%20%ED%86%A0%ED%81%B0%EC%9D%B4%20%EC%98%AC%EB%B0%94%EB%A5%B4%EC%A7%80%20%EC%95%8A%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
        cpms_samsung_portal_redirect($extra);
    }
    if (!cpms_samsung_portal_can_edit()) {
        flash_set('error', cpms_samsung_portal_label('%EA%B1%B4%EA%B0%95%EA%B2%80%EC%A7%84%20%EC%97%85%EB%A1%9C%EB%93%9C%20%EA%B6%8C%ED%95%9C%EC%9D%B4%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
        cpms_samsung_portal_redirect($extra);
    }
    $recordKey = isset($_POST['record_key']) ? trim((string)$_POST['record_key']) : '';
    $type = isset($_POST['health_type']) ? trim((string)$_POST['health_type']) : '';
    $data = cpms_samsung_portal_load_store();
    if ($recordKey === '' || !isset($data['samsung_portal']['records'][$recordKey]) || !is_array($data['samsung_portal']['records'][$recordKey]) || !cpms_samsung_portal_record_is_active($data['samsung_portal']['records'][$recordKey])) {
        flash_set('error', cpms_samsung_portal_label('%EC%97%85%EB%A1%9C%EB%93%9C%ED%95%A0%20%EC%9D%B8%EC%9B%90%EC%9D%84%20%EC%B0%BE%EC%A7%80%20%EB%AA%BB%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
        cpms_samsung_portal_redirect($extra);
    }
    $file = cpms_samsung_portal_store_health_file('health_file', $recordKey, $type, $message);
    if (!is_array($file) || count($file) === 0) {
        flash_set('error', $message !== '' ? $message : cpms_samsung_portal_label('%EA%B1%B4%EA%B0%95%EA%B2%80%EC%A7%84%20%ED%8C%8C%EC%9D%BC%20%EC%A0%80%EC%9E%A5%EC%97%90%20%EC%8B%A4%ED%8C%A8%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
        cpms_samsung_portal_redirect($extra);
    }
    if (!isset($data['samsung_portal']['records'][$recordKey]['health_checks']) || !is_array($data['samsung_portal']['records'][$recordKey]['health_checks'])) {
        $data['samsung_portal']['records'][$recordKey]['health_checks'] = array();
    }
    $data['samsung_portal']['records'][$recordKey]['health_checks'][$type] = $file;
    $data['samsung_portal']['records'][$recordKey]['last_modified_by'] = cpms_samsung_portal_user_label();
    $data['samsung_portal']['records'][$recordKey]['last_modified_at'] = date('Y-m-d H:i:s');
    if (!cpms_samsung_portal_save_store($data)) {
        flash_set('error', cpms_samsung_portal_label('%EA%B1%B4%EA%B0%95%EA%B2%80%EC%A7%84%20%ED%8C%8C%EC%9D%BC%20%EC%A0%95%EB%B3%B4%20%EC%A0%80%EC%9E%A5%EC%97%90%20%EC%8B%A4%ED%8C%A8%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
        cpms_samsung_portal_redirect($extra);
    }
    flash_set('success', cpms_samsung_portal_health_type_label($type) . cpms_samsung_portal_label('%20%ED%8C%8C%EC%9D%BC%EC%9D%84%20%EC%97%85%EB%A1%9C%EB%93%9C%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
    cpms_samsung_portal_redirect($extra);
}}

if (!function_exists('cpms_samsung_portal_handle_health_download_request')) {
function cpms_samsung_portal_handle_health_download_request($pdo)
{
    if (!class_exists('App\\Core\\Auth') || !\App\Core\Auth::check() || !cpms_samsung_portal_can_view()) {
        http_response_code(403);
        echo '403 Forbidden';
        exit;
    }
    $recordKey = isset($_GET['record_key']) ? trim((string)$_GET['record_key']) : '';
    $type = isset($_GET['type']) ? trim((string)$_GET['type']) : '';
    $data = cpms_samsung_portal_load_store();
    if ($recordKey === '' || !cpms_samsung_portal_health_type_valid($type) || !isset($data['samsung_portal']['records'][$recordKey]) || !is_array($data['samsung_portal']['records'][$recordKey])) {
        http_response_code(404);
        echo 'File not found';
        exit;
    }
    $row = $data['samsung_portal']['records'][$recordKey];
    $file = cpms_samsung_portal_health_record($row, $type);
    $path = isset($file['stored_path']) ? cpms_samsung_portal_resolve_health_path($file['stored_path']) : '';
    if ($path === '') {
        http_response_code(404);
        echo 'File not found';
        exit;
    }
    $mime = isset($file['mime_type']) && trim((string)$file['mime_type']) !== '' ? (string)$file['mime_type'] : 'application/octet-stream';
    $name = isset($file['original_name']) && trim((string)$file['original_name']) !== '' ? (string)$file['original_name'] : basename($path);
    $disposition = (isset($_GET['download']) && (string)$_GET['download'] === '1') ? 'attachment' : 'inline';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', basename($name)) . '"');
    readfile($path);
    exit;
}}

if (!function_exists('cpms_samsung_portal_require_tasks')) {
function cpms_samsung_portal_require_tasks()
{
    if (!function_exists('cpms_tasks_current_employee')) {
        $path = dirname(__DIR__) . '/tasks/helpers.php';
        if (is_file($path)) require_once $path;
    }
    return function_exists('cpms_tasks_current_employee') && function_exists('cpms_tasks_column_exists');
}}

if (!function_exists('cpms_samsung_portal_fetch_safety_employees')) {
function cpms_samsung_portal_fetch_safety_employees($pdo)
{
    $rows = array();
    if (!$pdo || !function_exists('cpms_tasks_fetch_active_employees')) return $rows;
    $all = cpms_tasks_fetch_active_employees($pdo);
    for ($i = 0; $i < count($all); $i++) {
        if (cpms_samsung_portal_is_safety_department(isset($all[$i]['department']) ? $all[$i]['department'] : '')) {
            $rows[count($rows)] = $all[$i];
        }
    }
    return $rows;
}}

if (!function_exists('cpms_samsung_portal_task_key_part')) {
function cpms_samsung_portal_task_key_part($value)
{
    $value = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)$value);
    $value = trim($value, '_');
    return $value !== '' ? $value : 'unknown';
}}

if (!function_exists('cpms_samsung_portal_group_completed')) {
function cpms_samsung_portal_group_completed($pdo, $groupKey)
{
    if (!$pdo || trim((string)$groupKey) === '' || !function_exists('cpms_tasks_table_exists') || !cpms_tasks_table_exists($pdo, 'cpms_tasks')) return false;
    if (!cpms_tasks_column_exists($pdo, 'cpms_tasks', 'group_key')) return false;
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM cpms_tasks WHERE group_key=:group_key AND status='done'");
        $st->execute(array(':group_key' => (string)$groupKey));
        return ((int)$st->fetchColumn() > 0);
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_samsung_portal_absolute_task_url')) {
function cpms_samsung_portal_absolute_task_url($taskId)
{
    $path = base_url() . '/?r=tasks/detail&id=' . (int)$taskId;
    $host = isset($_SERVER['HTTP_HOST']) ? trim((string)$_SERVER['HTTP_HOST']) : '';
    if ($host === '') return $path;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    return ($https ? 'https://' : 'http://') . $host . $path;
}}

if (!function_exists('cpms_samsung_portal_sync_task_map')) {
function cpms_samsung_portal_sync_task_map($groupKey, $map)
{
    $data = cpms_samsung_portal_load_store();
    $data['samsung_portal']['task_map'][(string)$groupKey] = $map;
    cpms_samsung_portal_save_store($data);
}}

if (!function_exists('cpms_samsung_portal_sync_safety_task')) {
function cpms_samsung_portal_sync_safety_task($pdo, $assignee, $groupKey, $title, $content, $taskType, $dueDate, $map)
{
    if (!$pdo || !is_array($assignee) || trim((string)$groupKey) === '') return false;
    if (!cpms_samsung_portal_require_tasks()) return false;
    if (!cpms_tasks_table_exists($pdo, 'cpms_tasks')) return false;
    $results = array();
    cpms_tasks_ensure_schema($pdo, $results);
    if (!cpms_tasks_column_exists($pdo, 'cpms_tasks', 'group_key')) return false;
    if (cpms_samsung_portal_group_completed($pdo, $groupKey)) return false;
    $assigneeId = isset($assignee['id']) ? (int)$assignee['id'] : 0;
    if ($assigneeId <= 0) return false;
    $existing = null;
    try {
        $st = $pdo->prepare("SELECT * FROM cpms_tasks WHERE assignee_employee_id=:assignee_employee_id AND group_key=:group_key ORDER BY id DESC LIMIT 1");
        $st->execute(array(':assignee_employee_id' => $assigneeId, ':group_key' => (string)$groupKey));
        $existing = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $existing = null;
    }
    $now = date('Y-m-d H:i:s');
    if ($existing) {
        cpms_samsung_portal_sync_task_map($groupKey, $map);
        if (isset($existing['status']) && in_array((string)$existing['status'], array('done', 'cancelled'), true)) return false;
        try {
            $st = $pdo->prepare("UPDATE cpms_tasks SET title=:title, content=:content, due_date=:due_date, due_time='18:00:00', priority='high', is_urgent=1, task_type=:task_type, updated_at=:updated_at WHERE id=:id");
            $st->execute(array(
                ':title' => $title,
                ':content' => $content,
                ':due_date' => $dueDate,
                ':task_type' => $taskType,
                ':updated_at' => $now,
                ':id' => (int)$existing['id']
            ));
        } catch (Exception $e) {
            return false;
        }
        return false;
    }
    try {
        $st = $pdo->prepare("INSERT INTO cpms_tasks (title, content, requester_employee_id, requester_name, requester_email, assignee_employee_id, assignee_name, assignee_email, department, project_id, project_name, task_type, priority, is_urgent, due_date, due_time, status, created_by, created_at, updated_at, group_key) VALUES (:title, :content, NULL, :requester_name, NULL, :assignee_employee_id, :assignee_name, :assignee_email, :department, NULL, NULL, :task_type, 'high', 1, :due_date, '18:00:00', 'pending', NULL, :created_at, :updated_at, :group_key)");
        $st->execute(array(
            ':title' => $title,
            ':content' => $content,
            ':requester_name' => cpms_samsung_portal_label('CPMS%20%EC%9E%90%EB%8F%99%EC%95%88%EB%82%B4'),
            ':assignee_employee_id' => $assigneeId,
            ':assignee_name' => isset($assignee['name']) ? (string)$assignee['name'] : '',
            ':assignee_email' => isset($assignee['email']) ? (string)$assignee['email'] : '',
            ':department' => cpms_samsung_portal_label('%EC%95%88%EC%A0%84'),
            ':task_type' => $taskType,
            ':due_date' => $dueDate,
            ':created_at' => $now,
            ':updated_at' => $now,
            ':group_key' => (string)$groupKey
        ));
        $taskId = (int)$pdo->lastInsertId();
        if ($taskId > 0) {
            $linkedContent = cpms_samsung_portal_label('%EB%A7%81%ED%81%AC: ') . cpms_samsung_portal_absolute_task_url($taskId) . "\n" . $content;
            $up = $pdo->prepare("UPDATE cpms_tasks SET content=:content WHERE id=:id");
            $up->execute(array(':content' => $linkedContent, ':id' => $taskId));
            cpms_tasks_insert_log($pdo, $taskId, array('id' => 0, 'name' => cpms_samsung_portal_label('CPMS%20%EC%9E%90%EB%8F%99%EC%95%88%EB%82%B4')), 'created', $linkedContent, null, 'pending');
            $task = cpms_tasks_find_task($pdo, $taskId);
            if ($task && function_exists('cpms_tasks_send_created_notification')) {
                cpms_tasks_send_created_notification($pdo, $task);
            }
            cpms_samsung_portal_sync_task_map($groupKey, $map);
            return true;
        }
    } catch (Exception $e) {
        error_log('[samsung_portal_task] ' . $e->getMessage());
        return false;
    }
    return false;
}}

if (!function_exists('cpms_samsung_portal_in_training_window')) {
function cpms_samsung_portal_in_training_window($expireDate)
{
    $expireDate = cpms_samsung_portal_valid_date($expireDate);
    if ($expireDate === '') return false;
    $today = date('Y-m-d');
    $diff = (int)floor((strtotime($expireDate) - strtotime($today)) / 86400);
    return ($diff >= 0 && $diff <= 10);
}}

if (!function_exists('cpms_samsung_portal_bootstrap_automations')) {
function cpms_samsung_portal_bootstrap_automations($pdo, $force)
{
    if (!$pdo || !cpms_samsung_portal_require_tasks()) return;
    $data = cpms_samsung_portal_load_store();
    $today = date('Y-m-d');
    if (!$force && isset($data['samsung_portal']['automation_runs'][$today]) && !empty($data['samsung_portal']['automation_runs'][$today]['checked_at'])) {
        return;
    }
    $created = 0;
    $errors = array();
    $safetyEmployees = cpms_samsung_portal_fetch_safety_employees($pdo);
    if (count($safetyEmployees) === 0) {
        $data['samsung_portal']['automation_runs'][$today] = array('checked_at' => date('Y-m-d H:i:s'), 'created' => 0, 'message' => 'no safety employees');
        cpms_samsung_portal_save_store($data);
        return;
    }
    $monthKey = date('Y_m');
    if (date('d') === '01') {
        $groupKey = 'samsung_employment_' . $monthKey;
        $title = cpms_samsung_portal_label('%5B%ED%99%94%EC%84%B1%2F%EA%B8%B0%ED%9D%A5%20%EC%82%BC%EC%84%B1%EB%82%B4%EB%B0%A9%20%EC%9E%AC%EC%A7%81%ED%99%95%EC%9D%B8%20%EC%9A%94%EC%B2%AD%5D');
        $content = cpms_samsung_portal_label('%5B%EC%82%BC%EC%84%B1%EC%83%81%EC%83%9D%ED%98%91%EB%A0%A5%ED%8F%AC%ED%83%88%EC%97%90%EC%84%9C%20%EC%9E%AC%EC%A7%81%ED%99%95%EC%9D%B8%20%EB%B6%80%ED%83%81%EB%93%9C%EB%A6%BD%EB%8B%88%EB%8B%A4%5D');
        $dueDate = date('Y-m-10');
        for ($i = 0; $i < count($safetyEmployees); $i++) {
            if (cpms_samsung_portal_sync_safety_task($pdo, $safetyEmployees[$i], $groupKey, $title, $content, 'samsung_portal_employment_check', $dueDate, array('kind' => 'employment', 'month' => $monthKey))) $created++;
        }
    }
    $records = isset($data['samsung_portal']['records']) && is_array($data['samsung_portal']['records']) ? $data['samsung_portal']['records'] : array();
    foreach ($records as $recordKey => $row) {
        if (!is_array($row)) continue;
        if (!cpms_samsung_portal_record_is_active($row)) continue;
        $empName = isset($row['name']) ? (string)$row['name'] : '';
        $loginId = isset($row['login_id']) ? (string)$row['login_id'] : '';
        if ($empName === '' || $loginId === '') continue;
        $safeLogin = cpms_samsung_portal_task_key_part($loginId);
        $safetyExpire = isset($row['safety_training_expire_date']) ? (string)$row['safety_training_expire_date'] : '';
        if (cpms_samsung_portal_in_training_window($safetyExpire)) {
            $groupKey = 'samsung_safety_training_' . $safeLogin . '_' . str_replace('-', '_', $safetyExpire);
            $title = cpms_samsung_portal_label('%5B%EC%B6%9C%EC%9E%85%EC%9E%90%20%EC%95%88%EC%A0%84%EA%B5%90%EC%9C%A1%20%EC%A7%84%ED%96%89%5D');
            $content = cpms_samsung_portal_label('%EC%9E%84%EC%A7%81%EC%9B%90%EB%AA%85: ') . $empName . "\n"
                . cpms_samsung_portal_label('%EC%95%84%EC%9D%B4%EB%94%94: ') . $loginId . "\n"
                . cpms_samsung_portal_label('%EC%B6%9C%EC%9E%85%EC%9E%90%20%EC%95%88%EC%A0%84%EA%B5%90%EC%9C%A1%20%EB%A7%8C%EB%A3%8C%EC%9D%BC: ') . $safetyExpire . "\n"
                . cpms_samsung_portal_label('%EC%82%BC%EC%84%B1%EC%83%81%EC%83%9D%ED%98%91%EB%A0%A5%ED%8F%AC%ED%83%88%EC%97%90%EC%84%9C%20%EC%B6%9C%EC%9E%85%EC%9E%90%20%EC%95%88%EC%A0%84%EA%B5%90%EC%9C%A1%20%EC%A7%84%ED%96%89%20%EB%B6%80%ED%83%81%EB%93%9C%EB%A6%BD%EB%8B%88%EB%8B%A4.');
            for ($i = 0; $i < count($safetyEmployees); $i++) {
                if (cpms_samsung_portal_sync_safety_task($pdo, $safetyEmployees[$i], $groupKey, $title, $content, 'samsung_portal_safety_training', $safetyExpire, array('kind' => 'safety_training', 'record_key' => (string)$recordKey, 'expire_date' => $safetyExpire))) $created++;
            }
        }
        $chemicalExpire = isset($row['chemical_training_expire_date']) ? (string)$row['chemical_training_expire_date'] : '';
        if (cpms_samsung_portal_in_training_window($chemicalExpire)) {
            $groupKey = 'samsung_chemical_training_' . $safeLogin . '_' . str_replace('-', '_', $chemicalExpire);
            $title = cpms_samsung_portal_label('%5B%EC%9C%A0%ED%95%B4%ED%99%94%ED%95%99%EB%AC%BC%EC%A7%88%20%EA%B5%90%EC%9C%A1%EC%A7%84%ED%96%89%5D');
            $content = cpms_samsung_portal_label('%EC%9E%84%EC%A7%81%EC%9B%90%EB%AA%85: ') . $empName . "\n"
                . cpms_samsung_portal_label('%EC%95%84%EC%9D%B4%EB%94%94: ') . $loginId . "\n"
                . cpms_samsung_portal_label('%EC%9C%A0%ED%95%B4%ED%99%94%ED%95%99%EB%AC%BC%EC%A7%88%EA%B5%90%EC%9C%A1%20%EB%A7%8C%EB%A3%8C%EC%9D%BC: ') . $chemicalExpire . "\n"
                . cpms_samsung_portal_label('%EC%82%BC%EC%84%B1%EC%83%81%EC%83%9D%ED%98%91%EB%A0%A5%ED%8F%AC%ED%83%88%EC%97%90%EC%84%9C%20%EC%9C%A0%ED%95%B4%ED%99%94%ED%95%99%EB%AC%BC%EC%A7%88%20%EA%B5%90%EC%9C%A1%20%EC%A7%84%ED%96%89%20%EB%B6%80%ED%83%81%EB%93%9C%EB%A6%BD%EB%8B%88%EB%8B%A4.');
            for ($i = 0; $i < count($safetyEmployees); $i++) {
                if (cpms_samsung_portal_sync_safety_task($pdo, $safetyEmployees[$i], $groupKey, $title, $content, 'samsung_portal_chemical_training', $chemicalExpire, array('kind' => 'chemical_training', 'record_key' => (string)$recordKey, 'expire_date' => $chemicalExpire))) $created++;
            }
        }
    }
    $data = cpms_samsung_portal_load_store();
    $data['samsung_portal']['automation_runs'][$today] = array('checked_at' => date('Y-m-d H:i:s'), 'created' => $created, 'errors' => $errors);
    cpms_samsung_portal_save_store($data);
}}

if (!function_exists('cpms_samsung_portal_add_year')) {
function cpms_samsung_portal_add_year($date)
{
    $date = cpms_samsung_portal_valid_date($date);
    if ($date === '') return '';
    $ts = strtotime($date . ' +1 year');
    if ($ts === false) return '';
    return date('Y-m-d', $ts);
}}

if (!function_exists('cpms_samsung_portal_handle_task_completed')) {
function cpms_samsung_portal_handle_task_completed($pdo, $task, $actor, $completedAt)
{
    if (!$pdo || !is_array($task)) return false;
    $groupKey = isset($task['group_key']) ? trim((string)$task['group_key']) : '';
    if ($groupKey === '' || strpos($groupKey, 'samsung_') !== 0) return false;
    $data = cpms_samsung_portal_load_store();
    $map = isset($data['samsung_portal']['task_map'][$groupKey]) && is_array($data['samsung_portal']['task_map'][$groupKey]) ? $data['samsung_portal']['task_map'][$groupKey] : array();
    $kind = isset($map['kind']) ? (string)$map['kind'] : '';
    $completedDate = substr((string)$completedAt, 0, 10);
    $completedDate = cpms_samsung_portal_valid_date($completedDate) !== '' ? $completedDate : date('Y-m-d');
    $actorName = is_array($actor) && isset($actor['name']) ? (string)$actor['name'] : '';
    $actorId = is_array($actor) && isset($actor['id']) ? (int)$actor['id'] : 0;
    if ($kind === 'employment') {
        $month = isset($map['month']) ? (string)$map['month'] : date('Y_m');
        $data['samsung_portal']['employment_checks'][$month] = array(
            'completed_at' => (string)$completedAt,
            'completed_by' => $actorName,
            'completed_by_id' => $actorId,
            'group_key' => $groupKey
        );
    } else if ($kind === 'safety_training' || $kind === 'chemical_training') {
        $recordKey = isset($map['record_key']) ? (string)$map['record_key'] : '';
        if ($recordKey !== '' && isset($data['samsung_portal']['records'][$recordKey]) && is_array($data['samsung_portal']['records'][$recordKey])) {
            $newExpire = cpms_samsung_portal_add_year($completedDate);
            if ($kind === 'safety_training') {
                $data['samsung_portal']['records'][$recordKey]['last_safety_training_completed_date'] = $completedDate;
                $data['samsung_portal']['records'][$recordKey]['safety_training_expire_date'] = $newExpire;
                $data['samsung_portal']['records'][$recordKey]['safety_training_text'] = cpms_samsung_portal_label('%EC%99%84%EB%A3%8C') . '(' . $newExpire . ')';
            } else {
                $data['samsung_portal']['records'][$recordKey]['last_chemical_training_completed_date'] = $completedDate;
                $data['samsung_portal']['records'][$recordKey]['chemical_training_expire_date'] = $newExpire;
                $data['samsung_portal']['records'][$recordKey]['chemical_training_text'] = cpms_samsung_portal_label('%EC%99%84%EB%A3%8C') . '(' . $newExpire . ')';
            }
            $data['samsung_portal']['records'][$recordKey]['last_modified_by'] = $actorName;
            $data['samsung_portal']['records'][$recordKey]['last_modified_at'] = (string)$completedAt;
        }
    }
    $data['samsung_portal']['completion_logs'][count($data['samsung_portal']['completion_logs'])] = array(
        'group_key' => $groupKey,
        'task_id' => isset($task['id']) ? (int)$task['id'] : 0,
        'kind' => $kind,
        'completed_at' => (string)$completedAt,
        'completed_by' => $actorName,
        'completed_by_id' => $actorId
    );
    return cpms_samsung_portal_save_store($data);
}}
