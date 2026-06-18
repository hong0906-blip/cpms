<?php
/**
 * Employee vehicle number helpers.
 * PHP 5.6 compatible.
 */

if (!function_exists('cpms_normalize_vehicle_number')) {
function cpms_normalize_vehicle_number($value) {
    $raw = trim((string)$value);
    $value = $raw;
    if ($value === '') return '';
    $value = preg_replace('/[\s\-\x{2010}-\x{2015}\x{2212}]+/u', '', $value);
    if ($value === null) $value = str_replace(array('-', ' ', "\t", "\r", "\n"), '', $raw);
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($value, 'UTF-8');
    }
    return strtolower($value);
}}

if (!function_exists('cpms_employee_vehicle_numbers_from_value')) {
function cpms_employee_vehicle_numbers_from_value($value) {
    $items = array();
    if (is_array($value)) {
        foreach ($value as $one) {
            $subItems = cpms_employee_vehicle_numbers_from_value($one);
            foreach ($subItems as $sub) $items[] = $sub;
        }
    } else {
        $text = trim((string)$value);
        if ($text !== '' && substr($text, 0, 1) === '[') {
            $decoded = @json_decode($text, true);
            if (is_array($decoded)) {
                return cpms_employee_vehicle_numbers_from_value($decoded);
            }
        }
        $parts = preg_split('/[,;\r\n]+/u', $text);
        if (!is_array($parts)) $parts = array($text);
        foreach ($parts as $part) {
            $part = trim((string)$part);
            if ($part !== '') $items[] = $part;
        }
    }

    $seen = array();
    $clean = array();
    foreach ($items as $item) {
        $norm = cpms_normalize_vehicle_number($item);
        if ($norm === '' || isset($seen[$norm])) continue;
        $seen[$norm] = true;
        $clean[] = trim((string)$item);
    }
    return $clean;
}}

if (!function_exists('cpms_employee_vehicle_display')) {
function cpms_employee_vehicle_display($value) {
    $numbers = cpms_employee_vehicle_numbers_from_value($value);
    return implode(', ', $numbers);
}}

if (!function_exists('cpms_employee_vehicle_storage_file')) {
function cpms_employee_vehicle_storage_file() {
    return cpms_employee_vehicle_writable_storage_root() . '/vehicle_numbers.json';
}}

if (!function_exists('cpms_employee_vehicle_storage_roots')) {
function cpms_employee_vehicle_storage_roots() {
    $root = dirname(dirname(__DIR__));
    $roots = array($root . '/data/employees');
    if (function_exists('cpms_storage_root')) $roots[] = cpms_storage_root() . '/employees';
    else $roots[] = $root . '/storage/employees';
    return $roots;
}}

if (!function_exists('cpms_employee_vehicle_storage_file_candidates')) {
function cpms_employee_vehicle_storage_file_candidates() {
    $files = array();
    foreach (cpms_employee_vehicle_storage_roots() as $root) {
        $root = rtrim((string)$root, '/\\');
        if ($root !== '') $files[] = $root . '/vehicle_numbers.json';
    }
    return $files;
}}

if (!function_exists('cpms_employee_vehicle_writable_storage_root')) {
function cpms_employee_vehicle_writable_storage_root() {
    $roots = cpms_employee_vehicle_storage_roots();
    foreach ($roots as $root) {
        $root = rtrim((string)$root, '/\\');
        if ($root === '') continue;
        if (is_dir($root) && is_writable($root)) return $root;
        if (!is_dir($root) && @mkdir($root, 0777, true) && is_dir($root) && is_writable($root)) return $root;
    }
    return count($roots) > 0 ? rtrim((string)$roots[0], '/\\') : dirname(dirname(__DIR__)) . '/data/employees';
}}

if (!function_exists('cpms_employee_vehicle_json_encode')) {
function cpms_employee_vehicle_json_encode($data) {
    $options = 0;
    if (defined('JSON_UNESCAPED_UNICODE')) $options = $options | JSON_UNESCAPED_UNICODE;
    if (defined('JSON_PRETTY_PRINT')) $options = $options | JSON_PRETTY_PRINT;
    if (defined('JSON_UNESCAPED_SLASHES')) $options = $options | JSON_UNESCAPED_SLASHES;
    return json_encode($data, $options);
}}

if (!function_exists('cpms_employee_vehicle_set_last_error')) {
function cpms_employee_vehicle_set_last_error($message) {
    $GLOBALS['_cpms_employee_vehicle_last_error'] = (string)$message;
}}

if (!function_exists('cpms_employee_vehicle_last_error')) {
function cpms_employee_vehicle_last_error() {
    return isset($GLOBALS['_cpms_employee_vehicle_last_error']) ? (string)$GLOBALS['_cpms_employee_vehicle_last_error'] : '';
}}

if (!function_exists('cpms_employee_vehicle_read_all')) {
function cpms_employee_vehicle_read_all() {
    $merged = array();
    foreach (cpms_employee_vehicle_storage_file_candidates() as $path) {
        if (!is_file($path)) continue;
        $txt = @file_get_contents($path);
        if ($txt === false || trim($txt) === '') continue;
        $data = @json_decode($txt, true);
        if (!is_array($data)) continue;
        foreach ($data as $key => $row) $merged[(string)$key] = $row;
    }
    return $merged;
}}

if (!function_exists('cpms_employee_vehicle_write_all')) {
function cpms_employee_vehicle_write_all($data) {
    cpms_employee_vehicle_set_last_error('');
    if (!is_array($data)) $data = array();
    $path = cpms_employee_vehicle_storage_file();
    $dir = dirname($path);
    if (function_exists('cpms_ensure_dir')) {
        if (!cpms_ensure_dir($dir)) {
            cpms_employee_vehicle_set_last_error('차량번호 저장 폴더를 만들 수 없습니다: ' . $dir);
            return false;
        }
    } else if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
        cpms_employee_vehicle_set_last_error('차량번호 저장 폴더를 만들 수 없습니다: ' . $dir);
        return false;
    }
    if (!is_writable($dir)) {
        cpms_employee_vehicle_set_last_error('차량번호 저장 폴더에 쓰기 권한이 없습니다: ' . $dir);
        return false;
    }
    $json = cpms_employee_vehicle_json_encode($data);
    if (!is_string($json)) {
        $jsonError = function_exists('json_last_error_msg') ? json_last_error_msg() : 'json_encode failed';
        cpms_employee_vehicle_set_last_error('차량번호 JSON 변환 실패: ' . $jsonError);
        return false;
    }
    $ok = @file_put_contents($path, $json, LOCK_EX);
    if ($ok === false) {
        $err = error_get_last();
        cpms_employee_vehicle_set_last_error('차량번호 파일 저장 실패: ' . $path . (is_array($err) && isset($err['message']) ? ' / ' . $err['message'] : ''));
        return false;
    }
    return true;
}}

if (!function_exists('cpms_employee_vehicle_column_exists')) {
function cpms_employee_vehicle_column_exists($pdo, $column) {
    if (!$pdo) return false;
    try {
        $db = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
        if ($db === '') return false;
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME='employees' AND COLUMN_NAME=:col");
        $st->execute(array(':db' => $db, ':col' => (string)$column));
        return ((int)$st->fetchColumn() > 0);
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_employee_vehicle_columns')) {
function cpms_employee_vehicle_columns($pdo) {
    return array(
        'vehicle_numbers' => cpms_employee_vehicle_column_exists($pdo, 'vehicle_numbers'),
        'vehicle_number' => cpms_employee_vehicle_column_exists($pdo, 'vehicle_number'),
    );
}}

if (!function_exists('cpms_employee_vehicle_get_fallback')) {
function cpms_employee_vehicle_get_fallback($employeeId) {
    $employeeId = (int)$employeeId;
    if ($employeeId <= 0) return array();
    $all = cpms_employee_vehicle_read_all();
    $key = (string)$employeeId;
    if (!isset($all[$key]) || !is_array($all[$key])) return array();
    return isset($all[$key]['vehicle_numbers']) ? cpms_employee_vehicle_numbers_from_value($all[$key]['vehicle_numbers']) : array();
}}

if (!function_exists('cpms_employee_vehicle_save_fallback')) {
function cpms_employee_vehicle_save_fallback($employeeId, $vehicleNumbers, $userLabel) {
    $employeeId = (int)$employeeId;
    if ($employeeId <= 0) return false;
    $numbers = cpms_employee_vehicle_numbers_from_value($vehicleNumbers);
    $all = cpms_employee_vehicle_read_all();
    $key = (string)$employeeId;
    if (count($numbers) === 0) {
        if (isset($all[$key])) unset($all[$key]);
    } else {
        $all[$key] = array(
            'employee_id' => $employeeId,
            'vehicle_numbers' => $numbers,
            'updated_at' => date('Y-m-d H:i:s'),
            'updated_by' => trim((string)$userLabel),
        );
    }
    return cpms_employee_vehicle_write_all($all);
}}

if (!function_exists('cpms_employee_vehicle_user_label')) {
function cpms_employee_vehicle_user_label($user) {
    if (is_array($user)) {
        if (isset($user['name']) && trim((string)$user['name']) !== '') return trim((string)$user['name']);
        if (isset($user['email']) && trim((string)$user['email']) !== '') return trim((string)$user['email']);
        if (isset($user['id'])) return 'user#' . (int)$user['id'];
    }
    $text = trim((string)$user);
    return $text !== '' ? $text : '-';
}}

if (!function_exists('cpms_employee_vehicle_save')) {
function cpms_employee_vehicle_save($pdo, $employeeId, $vehicleNumbers, $user) {
    $employeeId = (int)$employeeId;
    if ($employeeId <= 0) return false;
    $numbers = cpms_employee_vehicle_numbers_from_value($vehicleNumbers);
    $display = implode(', ', $numbers);
    $columns = cpms_employee_vehicle_columns($pdo);
    $savedDb = false;

    if ($pdo && !empty($columns['vehicle_numbers'])) {
        try {
            $st = $pdo->prepare("UPDATE employees SET vehicle_numbers=:vehicle_numbers WHERE id=:id");
            $st->bindValue(':vehicle_numbers', $display);
            $st->bindValue(':id', $employeeId, PDO::PARAM_INT);
            $st->execute();
            $savedDb = true;
        } catch (Exception $e) {
            $savedDb = false;
        }
    } else if ($pdo && !empty($columns['vehicle_number'])) {
        try {
            $first = count($numbers) > 0 ? $numbers[0] : '';
            $st2 = $pdo->prepare("UPDATE employees SET vehicle_number=:vehicle_number WHERE id=:id");
            $st2->bindValue(':vehicle_number', $first);
            $st2->bindValue(':id', $employeeId, PDO::PARAM_INT);
            $st2->execute();
            $savedDb = true;
        } catch (Exception $e2) {
            $savedDb = false;
        }
    }

    if (!$savedDb || empty($columns['vehicle_numbers'])) {
        return cpms_employee_vehicle_save_fallback($employeeId, $numbers, cpms_employee_vehicle_user_label($user));
    }
    return true;
}}

if (!function_exists('cpms_employee_vehicle_row_numbers')) {
function cpms_employee_vehicle_row_numbers($row) {
    $numbers = array();
    if (is_array($row)) {
        if (isset($row['vehicle_numbers'])) {
            foreach (cpms_employee_vehicle_numbers_from_value($row['vehicle_numbers']) as $num) $numbers[] = $num;
        }
        if (isset($row['vehicle_number'])) {
            foreach (cpms_employee_vehicle_numbers_from_value($row['vehicle_number']) as $num2) $numbers[] = $num2;
        }
        if (isset($row['id'])) {
            foreach (cpms_employee_vehicle_get_fallback((int)$row['id']) as $num3) $numbers[] = $num3;
        }
    }
    return cpms_employee_vehicle_numbers_from_value($numbers);
}}

if (!function_exists('cpms_employee_vehicle_map')) {
function cpms_employee_vehicle_map($pdo) {
    $map = array();
    if (!$pdo) return $map;
    $columns = cpms_employee_vehicle_columns($pdo);
    $select = array('id', 'name', 'email', 'department', 'is_active');
    if (!empty($columns['vehicle_numbers'])) $select[] = 'vehicle_numbers';
    if (!empty($columns['vehicle_number'])) $select[] = 'vehicle_number';

    try {
        $sql = "SELECT " . implode(',', $select) . " FROM employees ORDER BY is_active DESC, name ASC, id ASC";
        $st = $pdo->query($sql);
        $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
    } catch (Exception $e) {
        $rows = array();
    }
    if (!is_array($rows)) $rows = array();

    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $numbers = cpms_employee_vehicle_row_numbers($row);
        foreach ($numbers as $number) {
            $norm = cpms_normalize_vehicle_number($number);
            if ($norm === '' || isset($map[$norm])) continue;
            $map[$norm] = array(
                'employee_id' => isset($row['id']) ? (string)$row['id'] : '',
                'employee_name' => isset($row['name']) ? (string)$row['name'] : '',
                'employee_email' => isset($row['email']) ? (string)$row['email'] : '',
                'department' => isset($row['department']) ? (string)$row['department'] : '',
                'vehicle_number' => $number,
            );
        }
    }

    return $map;
}}

if (!function_exists('cpms_find_employee_by_vehicle_number')) {
function cpms_find_employee_by_vehicle_number($pdo, $vehicleNumber) {
    $norm = cpms_normalize_vehicle_number($vehicleNumber);
    if ($norm === '') return null;
    $map = cpms_employee_vehicle_map($pdo);
    return isset($map[$norm]) ? $map[$norm] : null;
}}
