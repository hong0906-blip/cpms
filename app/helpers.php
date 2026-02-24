<?php
function h($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

/**
 * base_url()
 * 예) /cpms/public
 */
function base_url() {
    $scriptName = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
    $dir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    return $dir === '' ? '' : $dir;
}

function asset_url($path) {
    $path = ltrim($path, '/');
    return base_url() . '/' . $path;
}

function csrf_token() {
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(openssl_random_pseudo_bytes(16));
    }
    return $_SESSION['_csrf'];
}

function csrf_check($token) {
    return isset($_SESSION['_csrf']) && is_string($token) && hash_equals($_SESSION['_csrf'], $token);
}

function flash_set($type, $message) {
    $_SESSION['_flash'] = array('type' => $type, 'message' => $message);
}

function flash_get() {
    if (!empty($_SESSION['_flash'])) {
        $f = $_SESSION['_flash'];
        unset($_SESSION['_flash']);
        return $f;
    }
    return null;
}

function cpms_storage_root() {
    return dirname(__DIR__) . '/storage';
}

function cpms_ensure_dir($dir) {
    if (is_dir($dir)) return true;
    return @mkdir($dir, 0777, true);
}

function cpms_request_store_path() {
    return cpms_storage_root() . '/requests/request_center.json';
}

function cpms_read_json_file($path, $defaultValue) {
    if (!is_file($path)) return $defaultValue;
    $txt = @file_get_contents($path);
    if ($txt === false || trim($txt) === '') return $defaultValue;
    $arr = @json_decode($txt, true);
    return is_array($arr) ? $arr : $defaultValue;
}

function cpms_write_json_file($path, $data) {
    $dir = dirname($path);
    if (!cpms_ensure_dir($dir)) return false;
    return (@file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) !== false);
}

function cpms_request_store_load() {
    $data = cpms_read_json_file(cpms_request_store_path(), array());
    if (!isset($data['requests']) || !is_array($data['requests'])) $data['requests'] = array();
    if (!isset($data['logs']) || !is_array($data['logs'])) $data['logs'] = array();
    return $data;
}

function cpms_request_store_save($data) {
    return cpms_write_json_file(cpms_request_store_path(), $data);
}

function cpms_request_new_id() {
    return 'REQ-' . date('YmdHis') . '-' . substr(md5(uniqid('', true)), 0, 8);
}

function cpms_find_employee_id_by_email($pdo, $email) {
    $email = trim((string)$email);
    if (!$pdo || $email === '') return 0;
    try {
        $st = $pdo->prepare("SELECT id FROM employees WHERE email = :em LIMIT 1");
        $st->bindValue(':em', $email);
        $st->execute();
        return (int)$st->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

function cpms_is_project_member_or_executive($pdo, $projectId, $role, $email) {
    if ($role === 'executive') return true;
    if ($projectId <= 0 || !$pdo) return false;
    $eid = cpms_find_employee_id_by_email($pdo, $email);
    if ($eid <= 0) return false;
    try {
        $sql = "SELECT COUNT(*) FROM cpms_project_members WHERE project_id = :pid AND employee_id = :eid AND LOWER(TRIM(role)) IN ('main','sub')";
        $st = $pdo->prepare($sql);
        $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
        $st->bindValue(':eid', (int)$eid, PDO::PARAM_INT);
        $st->execute();
        return ((int)$st->fetchColumn() > 0);
    } catch (Exception $e) {
        return false;
    }
}

function cpms_labor_override_path($projectId, $month) {
    return cpms_storage_root() . '/labor_overrides/' . ((int)$projectId) . '/' . $month . '.json';
}

function cpms_load_labor_overrides($projectId, $month) {
    return cpms_read_json_file(cpms_labor_override_path($projectId, $month), array());
}

function cpms_save_labor_overrides($projectId, $month, $rows) {
    return cpms_write_json_file(cpms_labor_override_path($projectId, $month), $rows);
}

function cpms_set_labor_override($projectId, $month, $workerName, $date, $value, $meta) {
    $workerName = trim((string)$workerName);
    $date = trim((string)$date);
    if ($workerName === '' || $date === '') return false;
    $rows = cpms_load_labor_overrides((int)$projectId, (string)$month);
    $key = function_exists('mb_strtolower') ? mb_strtolower($workerName, 'UTF-8') : strtolower($workerName);
    if (!isset($rows[$key]) || !is_array($rows[$key])) $rows[$key] = array();
    $rows[$key][$date] = array(
        'worker_name' => $workerName,
        'value' => (float)$value,
        'updated_at' => date('Y-m-d H:i:s'),
        'meta' => is_array($meta) ? $meta : array(),
    );
    return cpms_save_labor_overrides((int)$projectId, (string)$month, $rows);
}