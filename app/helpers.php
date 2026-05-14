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

if (!function_exists('csrf_token')) {
function csrf_token() {
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(openssl_random_pseudo_bytes(16));
    }
    return $_SESSION['_csrf'];
}
}

if (!function_exists('csrf_check')) {
function csrf_check($token) {
    return isset($_SESSION['_csrf']) && is_string($token) && hash_equals($_SESSION['_csrf'], $token);
}
}

if (!function_exists('csrf_validate')) {
function csrf_validate() {
    $token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
    if (!function_exists('csrf_check') || !csrf_check($token)) {
        if (function_exists('flash_set')) {
            flash_set('danger', '요청 시간이 만료되었거나 보안 토큰이 올바르지 않습니다. 다시 시도해주세요.');
        }
        $back = isset($_SERVER['HTTP_REFERER']) && $_SERVER['HTTP_REFERER'] !== ''
            ? $_SERVER['HTTP_REFERER']
            : '?r=대시보드';
        header('Location: '.$back);
        exit;
    }
}
}

if (!function_exists('flash_set')) {
function flash_set($type, $message) {
    $_SESSION['_flash'] = array('type' => $type, 'message' => $message);
}
}

if (!function_exists('flash_get')) {
function flash_get() {
    if (!empty($_SESSION['_flash'])) {
        $f = $_SESSION['_flash'];
        unset($_SESSION['_flash']);
        return $f;
    }
    return null;
}
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
    $projectId = (int)$projectId;
    $month = trim((string)$month);
    $rows = array();
    try {
        $pdo = \App\Core\Db::pdo();
        if ($pdo && cpms_ensure_labor_override_table($pdo)) {
            $sql = "SELECT worker_key, work_date, worker_name, old_value, new_value, status, reason, requested_by, approved_by, approved_at, created_at, updated_at
                    FROM cpms_labor_gongsu_overrides
                    WHERE project_id = :pid AND month = :month AND status IN ('applied','approved')";
            $st = $pdo->prepare($sql);
            $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
            $st->bindValue(':month', $month, PDO::PARAM_STR);
            $st->execute();
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $workerKey = isset($r['worker_key']) ? trim((string)$r['worker_key']) : '';
                $workDate = isset($r['work_date']) ? trim((string)$r['work_date']) : '';
                if ($workerKey === '' || $workDate === '') continue;
                if (!isset($rows[$workerKey]) || !is_array($rows[$workerKey])) $rows[$workerKey] = array();
                $rows[$workerKey][$workDate] = array('worker_name' => (string)$r['worker_name'], 'value' => (float)$r['new_value'], 'meta' => $r);
            }
            return $rows;
        }
    } catch (Exception $e) {}    
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

function cpms_ensure_labor_override_table($pdo) {
    if (!$pdo) return false;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_labor_gongsu_overrides (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            month CHAR(7) NOT NULL,
            worker_key VARCHAR(120) NOT NULL,
            worker_name VARCHAR(120) NOT NULL,
            work_date DATE NOT NULL,
            old_value DECIMAL(5,2) NULL,
            new_value DECIMAL(5,2) NOT NULL,
            reason VARCHAR(255) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'applied',
            requested_by INT NULL,
            approved_by INT NULL,
            approved_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            rejected_by INT NULL,
            rejected_at DATETIME NULL,
            reject_reason VARCHAR(255) NULL,            
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uk_labor_override(project_id, worker_key, work_date),
            KEY idx_labor_override_project_month(project_id, month),
            KEY idx_labor_override_status(status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

        $cols = array();
        try {
            $stCols = $pdo->query("SHOW COLUMNS FROM cpms_labor_gongsu_overrides");
            while ($row = $stCols->fetch(PDO::FETCH_ASSOC)) {
                if (isset($row['Field'])) $cols[(string)$row['Field']] = true;
            }
        } catch (Exception $e) {
            $cols = array();
        }
        $adds = array(
            'month' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN month CHAR(7) NOT NULL AFTER project_id",
            'worker_key' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN worker_key VARCHAR(120) NOT NULL AFTER month",
            'worker_name' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN worker_name VARCHAR(120) NOT NULL AFTER worker_key",
            'work_date' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN work_date DATE NOT NULL AFTER worker_name",
            'old_value' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN old_value DECIMAL(5,2) NULL AFTER work_date",
            'new_value' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN new_value DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER old_value",
            'reason' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN reason VARCHAR(255) NULL AFTER new_value",
            'status' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'applied' AFTER reason",
            'requested_by' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN requested_by INT NULL AFTER status",
            'approved_by' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN approved_by INT NULL AFTER requested_by",
            'approved_at' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN approved_at DATETIME NULL AFTER approved_by",
            'created_at' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN created_at DATETIME NOT NULL AFTER approved_at",
            'rejected_by' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN rejected_by INT NULL AFTER approved_at",
            'rejected_at' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN rejected_at DATETIME NULL AFTER rejected_by",
            'reject_reason' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN reject_reason VARCHAR(255) NULL AFTER rejected_at",
            'updated_at' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN updated_at DATETIME NOT NULL AFTER reject_reason"
        );
        foreach ($adds as $col => $sql) {
            if (!isset($cols[$col])) $pdo->exec($sql);
        }

        $idx = array();
        try {
            $stIdx = $pdo->query("SHOW INDEX FROM cpms_labor_gongsu_overrides");
            while ($row = $stIdx->fetch(PDO::FETCH_ASSOC)) {
                if (isset($row['Key_name'])) $idx[(string)$row['Key_name']] = true;
            }
        } catch (Exception $e) {
            $idx = array();
        }
        if (!isset($idx['uk_labor_override'])) $pdo->exec("ALTER TABLE cpms_labor_gongsu_overrides ADD UNIQUE KEY uk_labor_override(project_id, worker_key, work_date)");
        if (!isset($idx['idx_labor_override_project_month'])) $pdo->exec("ALTER TABLE cpms_labor_gongsu_overrides ADD KEY idx_labor_override_project_month(project_id, month)");
        if (!isset($idx['idx_labor_override_status'])) $pdo->exec("ALTER TABLE cpms_labor_gongsu_overrides ADD KEY idx_labor_override_status(status)");        
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function cpms_load_labor_override_pending($projectId, $month) {
    $list = array();
    try {
        $pdo = \App\Core\Db::pdo();
        if (!$pdo || !cpms_ensure_labor_override_table($pdo)) return $list;
        $st = $pdo->prepare("SELECT worker_name, work_date, old_value, new_value, reason, updated_at FROM cpms_labor_gongsu_overrides WHERE project_id=:pid AND month=:month AND status='pending' ORDER BY updated_at DESC");
        $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
        $st->bindValue(':month', (string)$month, PDO::PARAM_STR);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return $list;
    }
}