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

if (!function_exists('cpms_is_https_request')) {
function cpms_is_https_request() {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) return true;
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') return true;
    return false;
}
}

if (!function_exists('cpms_current_absolute_url')) {
function cpms_current_absolute_url() {
    $scheme = cpms_is_https_request() ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) && trim((string)$_SERVER['HTTP_HOST']) !== '' ? trim((string)$_SERVER['HTTP_HOST']) : 'cmbuild.kr';
    $uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/cpms/public/';
    if ($uri === '' || substr($uri, 0, 1) !== '/') $uri = '/' . $uri;
    return $scheme . '://' . $host . $uri;
}
}

if (!function_exists('cpms_portal_login_url')) {
function cpms_portal_login_url($returnUrl) {
    $configured = trim((string)getenv('CPMS_PORTAL_LOGIN_URL'));
    $returnUrl = trim((string)$returnUrl);
    if ($configured !== '') {
        if (strpos($configured, '{return}') !== false) {
            return str_replace('{return}', rawurlencode($returnUrl), $configured);
        }
        if ($returnUrl !== '') {
            $separator = (strpos($configured, '?') === false) ? '?' : '&';
            return $configured . $separator . 'return=' . rawurlencode($returnUrl);
        }
        return $configured;
    }

    $scheme = cpms_is_https_request() ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) && trim((string)$_SERVER['HTTP_HOST']) !== '' ? trim((string)$_SERVER['HTTP_HOST']) : 'cmbuild.kr';
    $url = $scheme . '://' . $host . '/';
    if ($returnUrl !== '') $url .= '?return=' . rawurlencode($returnUrl);
    return $url;
}
}

if (!function_exists('cpms_safe_internal_redirect_url')) {
function cpms_safe_internal_redirect_url($url, $fallback) {
    $url = trim((string)$url);
    $fallback = trim((string)$fallback);
    if ($fallback === '') $fallback = '?r=대시보드';
    if ($url === '' || preg_match('/[\r\n]/', $url)) return $fallback;
    if (preg_match('/^https?:\/\//i', $url)) {
        $host = isset($_SERVER['HTTP_HOST']) ? strtolower((string)$_SERVER['HTTP_HOST']) : '';
        $parts = @parse_url($url);
        $urlHost = is_array($parts) && isset($parts['host']) ? strtolower((string)$parts['host']) : '';
        if ($host === '' || $urlHost === '' || $host !== $urlHost) return $fallback;
    } else if (substr($url, 0, 1) !== '/' && substr($url, 0, 1) !== '?') {
        return $fallback;
    }
    return $url;
}
}

if (!function_exists('cpms_redirect_to_portal_login')) {
function cpms_redirect_to_portal_login($returnUrl) {
    $returnUrl = trim((string)$returnUrl);
    if ($returnUrl === '') $returnUrl = cpms_current_absolute_url();
    header('Location: ' . cpms_portal_login_url($returnUrl));
    exit;
}
}

if (!function_exists('cpms_hash_equals')) {
function cpms_hash_equals($known, $user) {
    if (function_exists('hash_equals')) {
        return hash_equals((string)$known, (string)$user);
    }
    $known = (string)$known;
    $user = (string)$user;
    if (strlen($known) !== strlen($user)) return false;
    $result = 0;
    for ($i = 0; $i < strlen($known); $i++) {
        $result |= ord($known[$i]) ^ ord($user[$i]);
    }
    return $result === 0;
}
}

if (!function_exists('cpms_base64url_encode')) {
function cpms_base64url_encode($value) {
    return rtrim(strtr(base64_encode((string)$value), '+/', '-_'), '=');
}
}

if (!function_exists('cpms_base64url_decode')) {
function cpms_base64url_decode($value) {
    $value = strtr((string)$value, '-_', '+/');
    $pad = strlen($value) % 4;
    if ($pad > 0) $value .= str_repeat('=', 4 - $pad);
    return base64_decode($value, true);
}
}

if (!function_exists('cpms_chat_login_secret')) {
function cpms_chat_login_secret() {
    $secretDir = cpms_storage_root() . '/secrets';
    $secretFile = $secretDir . '/cpms_chat_link_secret.php';
    if (is_file($secretFile)) {
        $loaded = @include $secretFile;
        if (is_string($loaded) && trim($loaded) !== '') return trim($loaded);
    }
    if (!is_dir($secretDir)) @mkdir($secretDir, 0777, true);
    if (!is_dir($secretDir) || !is_writable($secretDir)) return '';
    $bytes = function_exists('openssl_random_pseudo_bytes') ? openssl_random_pseudo_bytes(32) : uniqid('', true);
    if ($bytes === false || $bytes === '') $bytes = uniqid('', true);
    $secret = hash('sha256', $bytes);
    $content = "<?php\nreturn '" . $secret . "';\n";
    $tmp = $secretFile . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $content, LOCK_EX) === false) return '';
    if (!@rename($tmp, $secretFile)) {
        @unlink($tmp);
        return '';
    }
    return $secret;
}
}

if (!function_exists('cpms_chat_login_ttl_seconds')) {
function cpms_chat_login_ttl_seconds() {
    $ttl = (int)getenv('CPMS_CHAT_LINK_TTL_SECONDS');
    if ($ttl <= 0) $ttl = 60 * 60 * 24 * 3;
    if ($ttl < 300) $ttl = 300;
    if ($ttl > 60 * 60 * 24 * 14) $ttl = 60 * 60 * 24 * 14;
    return $ttl;
}
}

if (!function_exists('cpms_chat_login_token_create')) {
function cpms_chat_login_token_create($employeeId, $route) {
    $employeeId = (int)$employeeId;
    $route = trim((string)$route);
    if ($employeeId <= 0 || $route === '') return '';
    $secret = cpms_chat_login_secret();
    if ($secret === '') return '';
    $payload = array(
        'eid' => $employeeId,
        'route' => $route,
        'exp' => time() + cpms_chat_login_ttl_seconds(),
    );
    $json = json_encode($payload);
    if (!is_string($json) || $json === '') return '';
    $body = cpms_base64url_encode($json);
    $sig = hash_hmac('sha256', $body, $secret);
    return $body . '.' . $sig;
}
}

if (!function_exists('cpms_chat_login_token_payload')) {
function cpms_chat_login_token_payload($token, $route) {
    $token = trim((string)$token);
    $route = trim((string)$route);
    if ($token === '' || $route === '' || strpos($token, '.') === false) return false;
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) return false;
    $secret = cpms_chat_login_secret();
    if ($secret === '') return false;
    $expected = hash_hmac('sha256', $parts[0], $secret);
    if (!cpms_hash_equals($expected, $parts[1])) return false;
    $json = cpms_base64url_decode($parts[0]);
    if (!is_string($json) || $json === '') return false;
    $payload = json_decode($json, true);
    if (!is_array($payload)) return false;
    if (!isset($payload['eid']) || (int)$payload['eid'] <= 0) return false;
    if (!isset($payload['exp']) || (int)$payload['exp'] < time()) return false;
    if (!isset($payload['route']) || (string)$payload['route'] !== $route) return false;
    return $payload;
}
}

if (!function_exists('cpms_current_query_without_params')) {
function cpms_current_query_without_params($removeKeys) {
    if (!is_array($removeKeys)) $removeKeys = array($removeKeys);
    $params = $_GET;
    foreach ($removeKeys as $key) {
        if (isset($params[$key])) unset($params[$key]);
    }
    if (!isset($params['r']) || trim((string)$params['r']) === '') $params['r'] = 'dashboard_employee';
    return '?' . http_build_query($params, '', '&');
}
}

if (!function_exists('cpms_try_chat_link_login')) {
function cpms_try_chat_link_login($route) {
    $token = isset($_GET['_clt']) ? trim((string)$_GET['_clt']) : '';
    if ($token === '') return '';
    $payload = cpms_chat_login_token_payload($token, $route);
    $target = cpms_current_query_without_params(array('_clt'));
    if (!is_array($payload)) return $target;
    if (!class_exists('\\App\\Core\\Auth')) return $target;
    if (\App\Core\Auth::loginFromEmployeeId((int)$payload['eid'])) return $target;
    return $target;
}
}

if (!function_exists('cpms_public_base_url')) {
function cpms_public_base_url($pdo = null) {
    if (isset($_SERVER['HTTP_HOST']) && trim((string)$_SERVER['HTTP_HOST']) !== '') {
        $basePath = function_exists('base_url') ? base_url() : '/cpms/public';
        if ($basePath === '') $basePath = '/cpms/public';
        return rtrim((cpms_is_https_request() ? 'https' : 'http') . '://' . trim((string)$_SERVER['HTTP_HOST']) . $basePath, '/');
    }

    $configured = '';
    try {
        if ($pdo && !function_exists('approval_google_chat_setting')) {
            require_once __DIR__ . '/views/approval/google_chat_helpers.php';
        }
        if ($pdo && function_exists('approval_google_chat_setting')) {
            $configured = trim((string)approval_google_chat_setting($pdo, 'google_chat_public_base_url', ''));
        }
    } catch (Exception $e) {
        $configured = '';
    }

    if ($configured !== '') return rtrim($configured, '/');
    return 'https://cmbuild.kr/cpms/public';
}
}

if (!function_exists('cpms_app_route_url')) {
function cpms_app_route_url($pdo, $route, $params = array(), $chatEmployeeId = 0) {
    if (!is_array($params)) $params = array();
    $route = (string)$route;
    $query = array('r' => $route);
    foreach ($params as $key => $value) {
        if ($value === null) continue;
        $query[(string)$key] = $value;
    }
    if ((int)$chatEmployeeId > 0) {
        $token = cpms_chat_login_token_create((int)$chatEmployeeId, $route);
        if ($token !== '') $query['_clt'] = $token;
    }
    return cpms_public_base_url($pdo) . '/?' . http_build_query($query, '', '&');
}
}

if (!function_exists('cpms_app_dashboard_employee_url')) {
function cpms_app_dashboard_employee_url($pdo, $taskId = 0, $chatEmployeeId = 0) {
    $params = array();
    if ((int)$taskId > 0) $params['task_id'] = (int)$taskId;
    return cpms_app_route_url($pdo, 'dashboard_employee', $params, $chatEmployeeId);
}
}

if (!function_exists('cpms_app_executive_approval_url')) {
function cpms_app_executive_approval_url($pdo, $sourceType = '', $sourceId = 0, $chatEmployeeId = 0) {
    $params = array('exec_tab' => 'approval');
    if (trim((string)$sourceType) !== '') $params['focus_type'] = trim((string)$sourceType);
    if ((int)$sourceId > 0) $params['focus_id'] = (int)$sourceId;
    return cpms_app_route_url($pdo, 'dashboard_executive', $params, $chatEmployeeId);
}
}

if (!function_exists('cpms_app_approval_url')) {
function cpms_app_approval_url($pdo, $documentId = 0, $chatEmployeeId = 0) {
    $params = array('view' => 'active');
    if ((int)$documentId > 0) $params['document_id'] = (int)$documentId;
    return cpms_app_route_url($pdo, 'approval_home', $params, $chatEmployeeId);
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
            $sql = "SELECT worker_key, work_date, worker_name, old_value, new_value, is_deleted_entry, status, reason, requested_by, approved_by, approved_at, created_at, updated_at
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
                $rows[$workerKey][$workDate] = array(
                    'worker_name' => (string)$r['worker_name'],
                    'value' => (float)$r['new_value'],
                    'is_deleted' => (isset($r['is_deleted_entry']) && (int)$r['is_deleted_entry'] === 1),
                    'meta' => $r
                );
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

function cpms_normalize_management_department($dept) {
    $dept = trim((string)$dept);
    if ($dept === '관리부' || $dept === '관리팀') {
        return '관리';
    }
    return $dept;
}

function cpms_is_management_department_value($dept) {
    return cpms_normalize_management_department($dept) === '관리';
}

function cpms_is_management_department_user($pdo, $user) {
    if (is_array($user) && isset($user['department']) && cpms_is_management_department_value($user['department'])) {
        return true;
    }

    if (!$pdo) return false;

    $employeeId = 0;
    if (is_array($user) && isset($user['id'])) {
        $employeeId = (int)$user['id'];
    }

    try {
        if ($employeeId > 0) {
            $st = $pdo->prepare("SELECT department FROM employees WHERE id = :id LIMIT 1");
            $st->bindValue(':id', $employeeId, PDO::PARAM_INT);
            $st->execute();
            $dept = $st->fetchColumn();
            if (cpms_is_management_department_value($dept)) return true;
        }

        $email = '';
        if (is_array($user) && isset($user['email'])) {
            $email = trim((string)$user['email']);
        }
        if ($email !== '') {
            $st = $pdo->prepare("SELECT department FROM employees WHERE LOWER(email) = LOWER(:email) LIMIT 1");
            $st->bindValue(':email', $email);
            $st->execute();
            $dept = $st->fetchColumn();
            if (cpms_is_management_department_value($dept)) return true;
        }
    } catch (Exception $e) {
        return false;
    }

    return false;
}

function cpms_is_labor_override_deleted_entry($entry) {
    if (!is_array($entry)) return false;
    if (isset($entry['is_deleted'])) {
        return ((int)$entry['is_deleted'] === 1);
    }
    if (isset($entry['is_deleted_entry'])) {
        return ((int)$entry['is_deleted_entry'] === 1);
    }
    if (isset($entry['meta']) && is_array($entry['meta'])) {
        if (isset($entry['meta']['is_deleted_entry'])) {
            return ((int)$entry['meta']['is_deleted_entry'] === 1);
        }
        if (isset($entry['meta']['is_deleted'])) {
            return ((int)$entry['meta']['is_deleted'] === 1);
        }
    }
    return false;
}

function cpms_apply_labor_overrides_to_dataset($gongsuMap, $outputDays, $gongsuUnit, $projectId, $month) {
    $rows = cpms_load_labor_overrides((int)$projectId, (string)$month);
    if (!is_array($rows)) {
        return array(
            'gongsu_map' => is_array($gongsuMap) ? $gongsuMap : array(),
            'output_days' => is_array($outputDays) ? $outputDays : array(),
            'gongsu_unit' => is_array($gongsuUnit) ? $gongsuUnit : array(),
        );
    }

    if (!is_array($gongsuMap)) $gongsuMap = array();
    if (!is_array($outputDays)) $outputDays = array();
    if (!is_array($gongsuUnit)) $gongsuUnit = array();

    foreach ($rows as $workerKey => $dateRows) {
        if (!isset($gongsuMap[$workerKey]) || !is_array($gongsuMap[$workerKey])) {
            $gongsuMap[$workerKey] = array();
        }
        if (!is_array($dateRows)) continue;
        foreach ($dateRows as $dateKey => $entry) {
            if (!is_array($entry) || trim((string)$dateKey) === '') continue;
            $isDeleted = cpms_is_labor_override_deleted_entry($entry);
            $hasNumericValue = (isset($entry['value']) && is_numeric($entry['value']));
            $value = $hasNumericValue ? (float)$entry['value'] : 0.0;

            if ($isDeleted || $value <= 0) {
                if (isset($gongsuMap[$workerKey][$dateKey])) unset($gongsuMap[$workerKey][$dateKey]);
                continue;
            }

            $gongsuMap[$workerKey][$dateKey] = round($value, 2);
        }
    }

    $workerKeys = array();
    foreach ($gongsuMap as $workerKey => $unusedMap) $workerKeys[$workerKey] = true;
    foreach ($outputDays as $workerKey => $unusedDays) $workerKeys[$workerKey] = true;
    foreach ($gongsuUnit as $workerKey => $unusedUnit) $workerKeys[$workerKey] = true;
    foreach ($rows as $workerKey => $unusedOverrideRows) $workerKeys[$workerKey] = true;

    foreach (array_keys($workerKeys) as $workerKey) {
        $days = 0;
        $sum = 0.0;
        $dailyMap = (isset($gongsuMap[$workerKey]) && is_array($gongsuMap[$workerKey])) ? $gongsuMap[$workerKey] : array();
        foreach ($dailyMap as $dateKey => $gongsuValue) {
            if (!is_numeric($gongsuValue)) continue;
            if ($month !== '' && strpos((string)$dateKey, $month) !== 0) continue;
            $floatValue = (float)$gongsuValue;
            if ($floatValue <= 0) continue;
            $days++;
            $sum += $floatValue;
        }
        $outputDays[$workerKey] = $days;
        $gongsuUnit[$workerKey] = ($days > 0) ? round($sum / $days, 2) : 0.0;
    }

    return array(
        'gongsu_map' => $gongsuMap,
        'output_days' => $outputDays,
        'gongsu_unit' => $gongsuUnit,
    );
}

function cpms_apply_labor_overrides_to_map($map, $projectId, $month) {
    $dataset = cpms_apply_labor_overrides_to_dataset($map, array(), array(), $projectId, $month);
    return isset($dataset['gongsu_map']) && is_array($dataset['gongsu_map']) ? $dataset['gongsu_map'] : array();
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
            is_deleted_entry TINYINT(1) NOT NULL DEFAULT 0,
            reason VARCHAR(255) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'applied',
            requested_by INT NULL,
            requested_by_email VARCHAR(120) NULL,
            requested_by_name VARCHAR(80) NULL,            
            approved_by INT NULL,
            approved_at DATETIME NULL,
            approval_stage VARCHAR(30) NULL,
            approval_required_level VARCHAR(30) NULL,
            current_approver_employee_id INT NULL,
            current_approver_name VARCHAR(100) NULL,
            current_approver_email VARCHAR(190) NULL,
            first_approver_employee_id INT NULL,
            first_approver_name VARCHAR(100) NULL,
            first_approver_email VARCHAR(190) NULL,
            first_approved_at DATETIME NULL,
            second_approver_employee_id INT NULL,
            second_approver_name VARCHAR(100) NULL,
            second_approver_email VARCHAR(190) NULL,
            second_approved_at DATETIME NULL,
            final_approved_at DATETIME NULL,            
            created_at DATETIME NOT NULL,
            rejected_by INT NULL,
            rejected_by_name VARCHAR(100) NULL,
            rejected_by_email VARCHAR(190) NULL,            
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
            'is_deleted_entry' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN is_deleted_entry TINYINT(1) NOT NULL DEFAULT 0 AFTER new_value",
            'reason' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN reason VARCHAR(255) NULL AFTER is_deleted_entry",
            'status' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'applied' AFTER reason",
            'requested_by' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN requested_by INT NULL AFTER status",
            'requested_by_email' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN requested_by_email VARCHAR(120) NULL AFTER requested_by",
            'requested_by_name' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN requested_by_name VARCHAR(80) NULL AFTER requested_by_email",
            'approved_by' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN approved_by INT NULL AFTER requested_by_name",
            'approved_at' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN approved_at DATETIME NULL AFTER approved_by",
            'approval_stage' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN approval_stage VARCHAR(30) NULL AFTER approved_at",
            'approval_required_level' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN approval_required_level VARCHAR(30) NULL AFTER approval_stage",
            'current_approver_employee_id' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN current_approver_employee_id INT NULL AFTER approval_required_level",
            'current_approver_name' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN current_approver_name VARCHAR(100) NULL AFTER current_approver_employee_id",
            'current_approver_email' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN current_approver_email VARCHAR(190) NULL AFTER current_approver_name",
            'first_approver_employee_id' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN first_approver_employee_id INT NULL AFTER current_approver_email",
            'first_approver_name' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN first_approver_name VARCHAR(100) NULL AFTER first_approver_employee_id",
            'first_approver_email' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN first_approver_email VARCHAR(190) NULL AFTER first_approver_name",
            'first_approved_at' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN first_approved_at DATETIME NULL AFTER first_approver_email",
            'second_approver_employee_id' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN second_approver_employee_id INT NULL AFTER first_approved_at",
            'second_approver_name' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN second_approver_name VARCHAR(100) NULL AFTER second_approver_employee_id",
            'second_approver_email' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN second_approver_email VARCHAR(190) NULL AFTER second_approver_name",
            'second_approved_at' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN second_approved_at DATETIME NULL AFTER second_approver_email",
            'final_approved_at' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN final_approved_at DATETIME NULL AFTER second_approved_at",
            'created_at' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN created_at DATETIME NOT NULL AFTER final_approved_at",
            'rejected_by' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN rejected_by INT NULL AFTER created_at",
            'rejected_by_name' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN rejected_by_name VARCHAR(100) NULL AFTER rejected_by",
            'rejected_by_email' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN rejected_by_email VARCHAR(190) NULL AFTER rejected_by_name",
            'rejected_at' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN rejected_at DATETIME NULL AFTER rejected_by_email",
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
        if (!isset($idx['idx_labor_override_current_approver'])) $pdo->exec("ALTER TABLE cpms_labor_gongsu_overrides ADD KEY idx_labor_override_current_approver(current_approver_employee_id, status)");
        cpms_labor_backfill_legacy_pending_approver($pdo);
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

function cpms_labor_employee_select_columns($pdo) {
    $cols = array('id', 'name', 'email', 'position', 'role');
    $optional = array('google_chat_enabled', 'google_chat_user_name', 'google_chat_dm_space_name');
    foreach ($optional as $col) {
        $exists = false;
        try {
            $st = $pdo->prepare("SHOW COLUMNS FROM employees LIKE :col");
            $st->execute(array(':col' => $col));
            $exists = (bool)$st->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $exists = false; }
        if ($exists) $cols[] = $col;
        else if ($col === 'google_chat_enabled') $cols[] = '0 AS google_chat_enabled';
        else if ($col === 'google_chat_user_name') $cols[] = "'' AS google_chat_user_name";
        else $cols[] = "'' AS google_chat_dm_space_name";
    }
    return implode(', ', $cols);
}

function cpms_labor_find_director_approver($pdo) {
    if (!$pdo) return null;
    try {
        $sql = "SELECT " . cpms_labor_employee_select_columns($pdo) . " FROM employees WHERE is_active = 1 AND name = '박원덕' AND position LIKE '%상무%' ORDER BY id ASC LIMIT 1";
        $st = $pdo->query($sql);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
    } catch (Exception $e) {}
    return null;
}

function cpms_labor_find_vp_approver($pdo) {
    if (!$pdo) return null;
    try {
        $sql = "SELECT " . cpms_labor_employee_select_columns($pdo) . " FROM employees WHERE is_active = 1 AND position LIKE '%부사장%' ORDER BY id ASC";
        $st = $pdo->query($sql);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($rows && count($rows) > 0) {
            if (count($rows) > 1) error_log('부사장 승인자가 여러 명입니다. 첫 번째 직원을 사용했습니다.');
            return $rows[0];
        }
    } catch (Exception $e) {}
    return null;
}

function cpms_labor_find_employee_by_email($pdo, $email) {
    $email = trim((string)$email);
    if (!$pdo || $email === '') return null;
    try {
        $sql = "SELECT " . cpms_labor_employee_select_columns($pdo) . " FROM employees WHERE LOWER(email) = LOWER(:email) LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->bindValue(':email', $email, PDO::PARAM_STR);
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? $row : null;
    } catch (Exception $e) { return null; }
}

function cpms_labor_project_name($pdo, $projectId) {
    $projectId = (int)$projectId;
    if (!$pdo || $projectId <= 0) return '현장 #' . $projectId;
    try {
        $st = $pdo->prepare("SELECT name FROM cpms_projects WHERE id=:id LIMIT 1");
        $st->execute(array(':id' => $projectId));
        $name = $st->fetchColumn();
        if ($name !== false && trim((string)$name) !== '') return trim((string)$name);
    } catch (Exception $e) {}
    return '현장 #' . $projectId;
}

function cpms_labor_format_chat_gongsu($value) {
    $s = number_format((float)$value, 2, '.', '');
    $s = rtrim(rtrim($s, '0'), '.');
    return $s === '' ? '0' : $s;
}

function cpms_labor_build_override_message($pdo, $row, $secondStage) {
    $projectName = cpms_labor_project_name($pdo, isset($row['project_id']) ? (int)$row['project_id'] : 0);
    $requester = isset($row['requested_by_name']) && trim((string)$row['requested_by_name']) !== '' ? trim((string)$row['requested_by_name']) : (isset($row['requested_by_email']) ? trim((string)$row['requested_by_email']) : '');
    if ($requester === '') $requester = '-';
    $reason = isset($row['reason']) ? trim((string)$row['reason']) : '';
    $lines = array();
    $lines[] = $secondStage ? '[CPMS 공수 수정 2차 승인 요청]' : '[CPMS 공수 수정 요청]';
    $lines[] = '';
    $lines[] = '현장명 : ' . $projectName;
    $lines[] = '요청자 : ' . $requester;
    $lines[] = '요청 내용 : 공수 ' . cpms_labor_format_chat_gongsu(isset($row['old_value']) ? $row['old_value'] : 0) . ' -> ' . cpms_labor_format_chat_gongsu(isset($row['new_value']) ? $row['new_value'] : 0) . ' 변경';
    $lines[] = '요청 사유 : ' . ($reason !== '' ? $reason : '-');
    if ($secondStage) $lines[] = '1차 승인자 : ' . (isset($row['first_approver_name']) && trim((string)$row['first_approver_name']) !== '' ? trim((string)$row['first_approver_name']) : '박원덕');
    $lines[] = '';
    $lines[] = '요청내용 확인 바랍니다.';
    if (function_exists('cpms_app_executive_approval_url')) {
        $url = cpms_app_executive_approval_url($pdo, 'labor_gongsu', isset($row['id']) ? (int)$row['id'] : 0, isset($row['current_approver_employee_id']) ? (int)$row['current_approver_employee_id'] : 0);
        if ($url !== '') $lines[] = 'URL : ' . $url;
    }
    return implode("\n", $lines);
}

if (!function_exists('cpms_google_chat_strip_url_lines')) {
function cpms_google_chat_strip_url_lines($messageText) {
    $messageText = (string)$messageText;
    $lines = preg_split("/\r\n|\n|\r/", $messageText);
    if (!is_array($lines)) return $messageText;
    $kept = array();
    foreach ($lines as $line) {
        $trimmed = trim((string)$line);
        if (stripos($trimmed, 'URL') === 0) continue;
        if (preg_match('/^https?:\/\//i', $trimmed)) continue;
        $kept[count($kept)] = (string)$line;
    }
    return trim(implode("\n", $kept));
}}

function cpms_send_google_chat_to_employee($pdo, $employeeId, $messageText, $sourceId, $eventType, $sourceType) {
    try {
        require_once __DIR__ . '/views/common/chat_notification_helpers.php';
        if (!function_exists('approval_google_chat_send_message')) require_once __DIR__ . '/views/approval/google_chat_helpers.php';
        if (!$pdo || (int)$employeeId <= 0) return false;
        $sql = "SELECT " . cpms_labor_employee_select_columns($pdo) . " FROM employees WHERE id=:id LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->bindValue(':id', (int)$employeeId, PDO::PARAM_INT);
        $st->execute();
        $emp = $st->fetch(PDO::FETCH_ASSOC);
        if (!$emp) return false;
        $spaceName = isset($emp['google_chat_dm_space_name']) ? trim((string)$emp['google_chat_dm_space_name']) : '';
        $userName = isset($emp['google_chat_user_name']) ? trim((string)$emp['google_chat_user_name']) : '';
        $enabled = isset($emp['google_chat_enabled']) ? (int)$emp['google_chat_enabled'] : 0;
        if ($enabled === 1 && $spaceName === '' && $userName !== '' && function_exists('approval_google_chat_setting') && function_exists('approval_google_chat_setup_dm_space')) {
            $autoCreate = approval_google_chat_setting($pdo, 'google_chat_dm_auto_create_enabled', '0') === '1';
            if ($autoCreate) {
                $createdSpaceName = approval_google_chat_setup_dm_space($pdo, $userName);
                if (is_string($createdSpaceName) && trim($createdSpaceName) !== '') {
                    $spaceName = trim($createdSpaceName);
                    try {
                        $up = $pdo->prepare("UPDATE employees SET google_chat_dm_space_name=:space_name WHERE id=:id");
                        $up->execute(array(':space_name' => $spaceName, ':id' => (int)$employeeId));
                    } catch (Exception $e) {
                    }
                }
            }
        }
        if ($enabled !== 1 || $spaceName === '') {
            if (function_exists('cpms_google_chat_log_notification')) cpms_google_chat_log_notification($pdo, array('source_type'=>$sourceType,'source_id'=>$sourceId,'event_type'=>$eventType,'receiver_employee_id'=>(int)$employeeId,'receiver_name'=>isset($emp['name'])?$emp['name']:'','receiver_email'=>isset($emp['email'])?$emp['email']:'','dm_space_name'=>$spaceName,'message_text'=>$messageText,'send_status'=>'SKIPPED','error_message'=>'Google Chat DM disabled or dm space empty','sent_at'=>null));
            return false;
        }
        $ok = approval_google_chat_send_message($pdo, $spaceName, $messageText);
        $lastError = function_exists('approval_google_chat_get_last_error') ? approval_google_chat_get_last_error() : '';
        if (!$ok && (strpos((string)$messageText, 'http') !== false || stripos((string)$messageText, 'URL') !== false)) {
            $retryMessageText = cpms_google_chat_strip_url_lines($messageText);
            if ($retryMessageText !== '' && $retryMessageText !== (string)$messageText) {
                error_log('[google_chat] retry without url source=' . (string)$sourceType . ' source_id=' . (int)$sourceId . ' event=' . (string)$eventType);
                $retryOk = approval_google_chat_send_message($pdo, $spaceName, $retryMessageText);
                $retryError = function_exists('approval_google_chat_get_last_error') ? approval_google_chat_get_last_error() : '';
                if ($retryOk) {
                    $ok = true;
                    $messageText = $retryMessageText;
                    $lastError = null;
                } else {
                    $lastError = $retryError !== '' ? $retryError : $lastError;
                }
            }
        }
        if (function_exists('cpms_google_chat_log_notification')) cpms_google_chat_log_notification($pdo, array('source_type'=>$sourceType,'source_id'=>$sourceId,'event_type'=>$eventType,'receiver_employee_id'=>(int)$employeeId,'receiver_name'=>isset($emp['name'])?$emp['name']:'','receiver_email'=>isset($emp['email'])?$emp['email']:'','dm_space_name'=>$spaceName,'message_text'=>$messageText,'send_status'=>$ok?'SUCCESS':'FAILED','error_message'=>$ok?null:$lastError,'sent_at'=>$ok?date('Y-m-d H:i:s'):null));
        return (bool)$ok;
    } catch (Exception $e) { error_log('[labor_gongsu_chat] ' . $e->getMessage()); return false; }
}

function cpms_labor_send_override_notification($pdo, $overrideId, $eventType) {
    try {
        if (!$pdo || (int)$overrideId <= 0) return false;
        $st = $pdo->prepare("SELECT * FROM cpms_labor_gongsu_overrides WHERE id=:id LIMIT 1");
        $st->bindValue(':id', (int)$overrideId, PDO::PARAM_INT);
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;
        $employeeId = isset($row['current_approver_employee_id']) ? (int)$row['current_approver_employee_id'] : 0;
        return cpms_send_google_chat_to_employee($pdo, $employeeId, cpms_labor_build_override_message($pdo, $row, $eventType === 'VP_REQUEST'), (int)$overrideId, $eventType, 'LABOR_GONGSU_OVERRIDE');
    } catch (Exception $e) { error_log('[labor_gongsu_chat] ' . $e->getMessage()); return false; }
}

function cpms_labor_backfill_legacy_pending_approver($pdo) {
    if (!$pdo) return false;
    try {
        $director = cpms_labor_find_director_approver($pdo);
        if (!$director) return false;
        $st = $pdo->prepare("UPDATE cpms_labor_gongsu_overrides SET approval_required_level=CASE WHEN approval_required_level IS NULL OR approval_required_level='' THEN 'DIRECTOR_ONLY' ELSE approval_required_level END, approval_stage=CASE WHEN approval_stage IS NULL OR approval_stage='' THEN 'DIRECTOR_PENDING' ELSE approval_stage END, current_approver_employee_id=:eid, current_approver_name=:name, current_approver_email=:email, first_approver_employee_id=:eid2, first_approver_name=:name2, first_approver_email=:email2 WHERE status='pending' AND (approval_stage IS NULL OR approval_stage='' OR current_approver_employee_id IS NULL)");
        $st->execute(array(':eid'=>(int)$director['id'], ':name'=>isset($director['name'])?(string)$director['name']:'', ':email'=>isset($director['email'])?(string)$director['email']:'', ':eid2'=>(int)$director['id'], ':name2'=>isset($director['name'])?(string)$director['name']:'', ':email2'=>isset($director['email'])?(string)$director['email']:''));
        return true;
    } catch (Exception $e) { return false; }
}
