<?php
/**
 * Dashboard notice board.
 * PHP 5.6 compatible.
 */

if (!function_exists('cpms_dashboard_notice_label')) {
function cpms_dashboard_notice_label($key) {
    $labels = array(
        'notice' => '%EA%B3%B5%EC%A7%80%EC%82%AC%ED%95%AD',
        'subtitle' => '%EC%B5%9C%EC%8B%A0+%EA%B3%B5%EC%A7%80+%EB%82%B4%EC%9A%A9%EC%9D%84+%ED%99%95%EC%9D%B8%ED%95%B4+%EC%A3%BC%EC%84%B8%EC%9A%94.',
        'empty' => '%EB%93%B1%EB%A1%9D%EB%90%9C+%EA%B3%B5%EC%A7%80%EC%82%AC%ED%95%AD%EC%9D%B4+%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.',
        'pinned' => '%EA%B3%A0%EC%A0%95',
        'normal' => '%EC%9D%BC%EB%B0%98',
        'title' => '%EC%A0%9C%EB%AA%A9',
        'writer' => '%EC%9E%91%EC%84%B1%EC%9E%90',
        'date' => '%EB%93%B1%EB%A1%9D%EC%9D%BC',
        'status' => '%EC%83%81%ED%83%9C',
        'manage' => '%EA%B4%80%EB%A6%AC',
        'create' => '%EB%93%B1%EB%A1%9D',
        'edit' => '%EC%88%98%EC%A0%95',
        'save' => '%EC%A0%80%EC%9E%A5',
        'delete' => '%EC%82%AD%EC%A0%9C',
        'cancel' => '%EC%B7%A8%EC%86%8C',
        'active' => '%ED%99%9C%EC%84%B1',
        'inactive' => '%EC%88%A8%EA%B9%80',
        'fixed' => '%EC%83%81%EB%8B%A8+%EA%B3%A0%EC%A0%95',
        'notice_title' => '%EA%B3%B5%EC%A7%80+%EC%A0%9C%EB%AA%A9',
        'notice_content' => '%EA%B3%B5%EC%A7%80+%EB%82%B4%EC%9A%A9',
        'recent' => '%EC%B5%9C%EA%B7%BC+%EA%B3%B5%EC%A7%80',
        'close' => '%EB%8B%AB%EA%B8%B0',
        'all' => '%EC%A0%84%EC%B2%B4',
        'detail' => '%EA%B3%B5%EC%A7%80+%EC%83%81%EC%84%B8',
        'visible' => '%EB%85%B8%EC%B6%9C',
        'count_unit' => '%EA%B1%B4',
        'edit_save' => '%EC%88%98%EC%A0%95+%EC%A0%80%EC%9E%A5',
        'new_notice' => '%EC%83%88+%EA%B3%B5%EC%A7%80+%EB%93%B1%EB%A1%9D',
        'today_hidden' => '%EC%98%A4%EB%8A%98+23%3A59%EA%B9%8C%EC%A7%80+%EB%8B%A4%EC%8B%9C+%ED%91%9C%EC%8B%9C%EB%90%98%EC%A7%80+%EC%95%8A%EC%8A%B5%EB%8B%88%EB%8B%A4.',
        'confirm_delete' => '%EC%82%AD%EC%A0%9C%ED%95%98%EC%8B%9C%EA%B2%A0%EC%8A%B5%EB%8B%88%EA%B9%8C%3F',
        'saved' => '%EA%B3%B5%EC%A7%80%EB%A5%BC+%EB%93%B1%EB%A1%9D%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.',
        'deleted' => '%EA%B3%B5%EC%A7%80%EB%A5%BC+%EC%82%AD%EC%A0%9C%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.',
        'invalid' => '%EC%A0%9C%EB%AA%A9%EA%B3%BC+%EB%82%B4%EC%9A%A9%EC%9D%84+%EC%9E%85%EB%A0%A5%ED%95%B4+%EC%A3%BC%EC%84%B8%EC%9A%94.',
        'forbidden' => '%EA%B6%8C%ED%95%9C%EC%9D%B4+%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.'
    );
    return isset($labels[$key]) ? urldecode($labels[$key]) : (string)$key;
}}

if (!function_exists('cpms_dashboard_notice_store_path')) {
function cpms_dashboard_notice_store_path() {
    return cpms_storage_root() . '/notices/dashboard_notices.json';
}}

if (!function_exists('cpms_dashboard_notice_read_store')) {
function cpms_dashboard_notice_read_store() {
    $data = cpms_read_json_file(cpms_dashboard_notice_store_path(), array('items' => array()));
    if (!is_array($data)) $data = array();
    if (!isset($data['items']) || !is_array($data['items'])) $data['items'] = array();
    return $data;
}}

if (!function_exists('cpms_dashboard_notice_write_store')) {
function cpms_dashboard_notice_write_store($data) {
    if (!is_array($data)) $data = array();
    if (!isset($data['items']) || !is_array($data['items'])) $data['items'] = array();
    $data['updated_at'] = date('Y-m-d H:i:s');
    return cpms_write_json_file(cpms_dashboard_notice_store_path(), $data);
}}

if (!function_exists('cpms_dashboard_notice_new_id')) {
function cpms_dashboard_notice_new_id() {
    return 'DN-' . date('YmdHis') . '-' . substr(md5(uniqid('', true)), 0, 8);
}}

if (!function_exists('cpms_dashboard_notice_flash_set')) {
function cpms_dashboard_notice_flash_set($type, $message) {
    $_SESSION['_dashboard_notice_flash'] = array('type' => (string)$type, 'message' => (string)$message);
}}

if (!function_exists('cpms_dashboard_notice_flash_get')) {
function cpms_dashboard_notice_flash_get() {
    if (!empty($_SESSION['_dashboard_notice_flash']) && is_array($_SESSION['_dashboard_notice_flash'])) {
        $flash = $_SESSION['_dashboard_notice_flash'];
        unset($_SESSION['_dashboard_notice_flash']);
        return $flash;
    }
    return null;
}}

if (!function_exists('cpms_dashboard_notice_can_manage')) {
function cpms_dashboard_notice_can_manage() {
    if (!class_exists('App\\Core\\Auth') || !\App\Core\Auth::check()) return false;
    if (\App\Core\Auth::isMaster()) return true;
    if (\App\Core\Auth::userRole() === 'executive') return true;

    $dept = trim((string)\App\Core\Auth::userDepartment());
    $dept = str_replace(array(' ', "\t", "\r", "\n"), '', $dept);
    $managementDepts = array(
        urldecode('%EA%B4%80%EB%A6%AC'),
        urldecode('%EA%B4%80%EB%A6%AC%EB%B6%80'),
        urldecode('%EA%B4%80%EB%A6%AC%ED%8C%80')
    );
    if (in_array($dept, $managementDepts, true)) return true;

    $roleText = trim((string)\App\Core\Auth::userRole());
    $positionText = method_exists('App\\Core\\Auth', 'userPosition') ? trim((string)\App\Core\Auth::userPosition()) : '';
    $checkText = $roleText . ' ' . $positionText;
    $checkText = str_replace(array(' ', "\t", "\r", "\n", '-', '_'), '', $checkText);
    if (function_exists('mb_strtolower')) $checkText = mb_strtolower($checkText, 'UTF-8');
    else $checkText = strtolower($checkText);

    $allowedWords = array(
        urldecode('%EB%8C%80%ED%91%9C'),
        urldecode('%EB%B6%80%EC%82%AC%EC%9E%A5'),
        'ceo',
        'president',
        'vicepresident',
        'vp'
    );
    foreach ($allowedWords as $word) {
        $word = str_replace(array(' ', "\t", "\r", "\n", '-', '_'), '', (string)$word);
        if (function_exists('mb_strtolower')) $word = mb_strtolower($word, 'UTF-8');
        else $word = strtolower($word);
        if ($word !== '' && strpos($checkText, $word) !== false) return true;
    }

    return false;
}}

if (!function_exists('cpms_dashboard_notice_normalize_item')) {
function cpms_dashboard_notice_normalize_item($item) {
    if (!is_array($item)) $item = array();
    $id = isset($item['id']) ? trim((string)$item['id']) : '';
    if ($id === '') $id = cpms_dashboard_notice_new_id();
    return array(
        'id' => $id,
        'title' => isset($item['title']) ? trim((string)$item['title']) : '',
        'content' => isset($item['content']) ? trim((string)$item['content']) : '',
        'author_name' => isset($item['author_name']) ? trim((string)$item['author_name']) : '',
        'author_email' => isset($item['author_email']) ? trim((string)$item['author_email']) : '',
        'is_active' => isset($item['is_active']) ? (int)$item['is_active'] : 1,
        'is_pinned' => isset($item['is_pinned']) ? (int)$item['is_pinned'] : 0,
        'created_at' => isset($item['created_at']) ? trim((string)$item['created_at']) : date('Y-m-d H:i:s'),
        'updated_at' => isset($item['updated_at']) ? trim((string)$item['updated_at']) : ''
    );
}}

if (!function_exists('cpms_dashboard_notice_sorted_items')) {
function cpms_dashboard_notice_sorted_items($includeInactive) {
    $store = cpms_dashboard_notice_read_store();
    $items = array();
    foreach ($store['items'] as $item) {
        $row = cpms_dashboard_notice_normalize_item($item);
        if (!$includeInactive && (int)$row['is_active'] !== 1) continue;
        if ($row['title'] === '' || $row['content'] === '') continue;
        $items[] = $row;
    }
    usort($items, function($a, $b) {
        $aPinned = isset($a['is_pinned']) ? (int)$a['is_pinned'] : 0;
        $bPinned = isset($b['is_pinned']) ? (int)$b['is_pinned'] : 0;
        if ($aPinned !== $bPinned) {
            return ($aPinned > $bPinned) ? -1 : 1;
        }

        $at = isset($a['created_at']) ? strtotime((string)$a['created_at']) : 0;
        $bt = isset($b['created_at']) ? strtotime((string)$b['created_at']) : 0;
        if ($at === $bt) {
            $aid = isset($a['id']) ? (string)$a['id'] : '';
            $bid = isset($b['id']) ? (string)$b['id'] : '';
            return strcmp($bid, $aid);
        }
        return ($at > $bt) ? -1 : 1;
    });
    return $items;
}}

if (!function_exists('cpms_dashboard_notice_save_item')) {
function cpms_dashboard_notice_save_item($input) {
    $store = cpms_dashboard_notice_read_store();
    $id = isset($input['id']) ? trim((string)$input['id']) : '';
    $now = date('Y-m-d H:i:s');
    $found = false;
    $savedItem = null;
    $nextItems = array();

    $userName = class_exists('App\\Core\\Auth') ? (string)\App\Core\Auth::userName() : '';
    $userEmail = class_exists('App\\Core\\Auth') ? (string)\App\Core\Auth::userEmail() : '';

    foreach ($store['items'] as $item) {
        $row = cpms_dashboard_notice_normalize_item($item);
        if ($id !== '' && $row['id'] === $id) {
            $row['title'] = isset($input['title']) ? trim((string)$input['title']) : '';
            $row['content'] = isset($input['content']) ? trim((string)$input['content']) : '';
            $row['is_active'] = isset($input['is_active']) ? (int)$input['is_active'] : 0;
            $row['is_pinned'] = isset($input['is_pinned']) ? (int)$input['is_pinned'] : 0;
            $row['updated_at'] = $now;
            $found = true;
            $savedItem = $row;
        }
        $nextItems[] = $row;
    }

    if (!$found) {
        $savedItem = array(
            'id' => cpms_dashboard_notice_new_id(),
            'title' => isset($input['title']) ? trim((string)$input['title']) : '',
            'content' => isset($input['content']) ? trim((string)$input['content']) : '',
            'author_name' => $userName,
            'author_email' => $userEmail,
            'is_active' => isset($input['is_active']) ? (int)$input['is_active'] : 1,
            'is_pinned' => isset($input['is_pinned']) ? (int)$input['is_pinned'] : 0,
            'created_at' => $now,
            'updated_at' => ''
        );
        $nextItems[] = $savedItem;
    }

    $store['items'] = $nextItems;
    $ok = cpms_dashboard_notice_write_store($store);
    return array(
        'ok' => $ok ? true : false,
        'created' => $found ? false : true,
        'item' => is_array($savedItem) ? $savedItem : array()
    );
}}

if (!function_exists('cpms_dashboard_notice_employee_column_exists')) {
function cpms_dashboard_notice_employee_column_exists($pdo, $column) {
    static $cache = array();
    if (!$pdo) return false;
    $column = trim((string)$column);
    if ($column === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) return false;
    $cacheKey = (is_object($pdo) ? spl_object_hash($pdo) : 'pdo') . ':' . $column;
    if (array_key_exists($cacheKey, $cache)) return $cache[$cacheKey];
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM employees LIKE :col");
        $st->execute(array(':col' => $column));
        $cache[$cacheKey] = (bool)$st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $cache[$cacheKey] = false;
    }
    return $cache[$cacheKey];
}}

if (!function_exists('cpms_dashboard_notice_normalize_access_text')) {
function cpms_dashboard_notice_normalize_access_text($value) {
    $value = trim((string)$value);
    $value = str_replace(array(' ', "\t", "\r", "\n", '-', '_', '/', '\\', '[', ']', '(', ')', '{', '}'), '', $value);
    if (function_exists('mb_strtolower')) return mb_strtolower($value, 'UTF-8');
    return strtolower($value);
}}

if (!function_exists('cpms_dashboard_notice_is_development_department')) {
function cpms_dashboard_notice_is_development_department($department) {
    $department = cpms_dashboard_notice_normalize_access_text($department);
    $allowed = array(
        cpms_dashboard_notice_normalize_access_text('개발'),
        cpms_dashboard_notice_normalize_access_text('개발부'),
        cpms_dashboard_notice_normalize_access_text('개발팀'),
        cpms_dashboard_notice_normalize_access_text('개발부서')
    );
    return in_array($department, $allowed, true);
}}

if (!function_exists('cpms_dashboard_notice_is_representative_or_vp')) {
function cpms_dashboard_notice_is_representative_or_vp($role, $position, $name) {
    $values = array((string)$role, (string)$position, (string)$name);
    $words = array('대표', '부사장', 'ceo', 'president', 'vicepresident', 'vp');
    for ($i = 0; $i < count($values); $i++) {
        $value = cpms_dashboard_notice_normalize_access_text($values[$i]);
        if ($value === '') continue;
        for ($j = 0; $j < count($words); $j++) {
            $word = cpms_dashboard_notice_normalize_access_text($words[$j]);
            if ($word !== '' && strpos($value, $word) !== false) return true;
        }
    }
    return false;
}}

if (!function_exists('cpms_dashboard_notice_current_employee')) {
function cpms_dashboard_notice_current_employee($pdo) {
    $user = class_exists('App\\Core\\Auth') ? \App\Core\Auth::user() : array();
    if (!is_array($user)) $user = array();
    $row = array(
        'id' => isset($user['id']) ? (int)$user['id'] : 0,
        'email' => class_exists('App\\Core\\Auth') ? (string)\App\Core\Auth::userEmail() : '',
        'name' => class_exists('App\\Core\\Auth') ? (string)\App\Core\Auth::userName() : '',
        'department' => class_exists('App\\Core\\Auth') ? (string)\App\Core\Auth::userDepartment() : '',
        'position' => class_exists('App\\Core\\Auth') ? (string)\App\Core\Auth::userPosition() : '',
        'role' => isset($user['role']) ? (string)$user['role'] : (class_exists('App\\Core\\Auth') ? (string)\App\Core\Auth::userRole() : '')
    );
    if (!$pdo || trim((string)$row['email']) === '') return $row;

    try {
        $positionSelect = cpms_dashboard_notice_employee_column_exists($pdo, 'position') ? 'position' : "'' AS position";
        $departmentSelect = cpms_dashboard_notice_employee_column_exists($pdo, 'department') ? 'department' : "'' AS department";
        $roleSelect = cpms_dashboard_notice_employee_column_exists($pdo, 'role') ? 'role' : "'' AS role";
        $st = $pdo->prepare("SELECT id,email,name," . $departmentSelect . "," . $positionSelect . "," . $roleSelect . " FROM employees WHERE email=:email LIMIT 1");
        $st->execute(array(':email' => $row['email']));
        $found = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($found)) return $found;
    } catch (Exception $e) {
    }
    return $row;
}}

if (!function_exists('cpms_dashboard_notice_current_employee_id')) {
function cpms_dashboard_notice_current_employee_id($pdo) {
    $row = cpms_dashboard_notice_current_employee($pdo);
    return is_array($row) && isset($row['id']) ? (int)$row['id'] : 0;
}}

if (!function_exists('cpms_dashboard_notice_can_view_unread')) {
function cpms_dashboard_notice_can_view_unread($pdo) {
    if (!class_exists('App\\Core\\Auth') || !\App\Core\Auth::check()) return false;
    $row = cpms_dashboard_notice_current_employee($pdo);
    if (!is_array($row)) return false;
    if (cpms_dashboard_notice_is_representative_or_vp(
        isset($row['role']) ? $row['role'] : '',
        isset($row['position']) ? $row['position'] : '',
        isset($row['name']) ? $row['name'] : ''
    )) return true;
    return cpms_dashboard_notice_is_development_department(isset($row['department']) ? $row['department'] : '');
}}

if (!function_exists('cpms_dashboard_notice_ensure_read_schema')) {
function cpms_dashboard_notice_ensure_read_schema($pdo) {
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!$pdo) {
        $ready = false;
        return false;
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_dashboard_notice_reads (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            notice_id VARCHAR(100) NOT NULL,
            employee_id INT NOT NULL,
            read_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_notice_employee (notice_id, employee_id),
            KEY idx_notice_id (notice_id),
            KEY idx_employee_id (employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ready = true;
    } catch (Exception $e) {
        error_log('[dashboard_notice_read] schema ensure failed: ' . $e->getMessage());
        $ready = false;
    }
    return $ready;
}}

if (!function_exists('cpms_dashboard_notice_exists')) {
function cpms_dashboard_notice_exists($noticeId) {
    $noticeId = trim((string)$noticeId);
    if ($noticeId === '') return false;
    $store = cpms_dashboard_notice_read_store();
    foreach ($store['items'] as $item) {
        if (is_array($item) && isset($item['id']) && trim((string)$item['id']) === $noticeId) return true;
    }
    return false;
}}

if (!function_exists('cpms_dashboard_notice_mark_read')) {
function cpms_dashboard_notice_mark_read($pdo, $noticeId, $employeeId) {
    $noticeId = trim((string)$noticeId);
    $employeeId = (int)$employeeId;
    if (!$pdo || $noticeId === '' || strlen($noticeId) > 100 || $employeeId <= 0) return false;
    if (!cpms_dashboard_notice_exists($noticeId)) return false;
    if (!cpms_dashboard_notice_ensure_read_schema($pdo)) return false;
    try {
        $now = date('Y-m-d H:i:s');
        $st = $pdo->prepare("INSERT INTO cpms_dashboard_notice_reads(notice_id,employee_id,read_at) VALUES(:notice_id,:employee_id,:read_at) ON DUPLICATE KEY UPDATE read_at=:updated_read_at");
        return $st->execute(array(
            ':notice_id' => $noticeId,
            ':employee_id' => $employeeId,
            ':read_at' => $now,
            ':updated_read_at' => $now
        ));
    } catch (Exception $e) {
        error_log('[dashboard_notice_read] mark failed: ' . $e->getMessage());
        return false;
    }
}}

if (!function_exists('cpms_dashboard_notice_unread_employee_map')) {
function cpms_dashboard_notice_unread_employee_map($pdo, $notices, $excludeEmployeeId) {
    if (!$pdo || !is_array($notices) || !cpms_dashboard_notice_ensure_read_schema($pdo)) return false;
    $excludeEmployeeId = (int)$excludeEmployeeId;
    $noticeMap = array();
    $noticeIds = array();
    foreach ($notices as $notice) {
        $noticeId = is_array($notice) && isset($notice['id']) ? trim((string)$notice['id']) : '';
        if ($noticeId === '' || isset($noticeMap[$noticeId])) continue;
        $noticeIds[] = $noticeId;
        $noticeMap[$noticeId] = array();
    }
    if (count($noticeIds) === 0) return $noticeMap;

    try {
        $positionSelect = cpms_dashboard_notice_employee_column_exists($pdo, 'position') ? 'position' : "'' AS position";
        $departmentSelect = cpms_dashboard_notice_employee_column_exists($pdo, 'department') ? 'department' : "'' AS department";
        $roleSelect = cpms_dashboard_notice_employee_column_exists($pdo, 'role') ? 'role' : "'' AS role";
        $where = cpms_dashboard_notice_employee_column_exists($pdo, 'is_active') ? ' WHERE (is_active IS NULL OR is_active=1)' : '';
        $employeeSql = "SELECT id,email,name," . $departmentSelect . "," . $positionSelect . "," . $roleSelect . " FROM employees" . $where . " ORDER BY department ASC, name ASC, id ASC";
        $employeeRows = $pdo->query($employeeSql)->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($employeeRows)) $employeeRows = array();

        $eligibleEmployees = array();
        $excludedUnreadNames = array('노준형', '이호상');
        foreach ($employeeRows as $employeeRow) {
            $employeeId = isset($employeeRow['id']) ? (int)$employeeRow['id'] : 0;
            if ($employeeId <= 0 || ($excludeEmployeeId > 0 && $employeeId === $excludeEmployeeId)) continue;
            $employeeName = isset($employeeRow['name']) ? trim((string)$employeeRow['name']) : '';
            if (in_array($employeeName, $excludedUnreadNames, true)) continue;
            if (cpms_dashboard_notice_is_representative_or_vp(
                isset($employeeRow['role']) ? $employeeRow['role'] : '',
                isset($employeeRow['position']) ? $employeeRow['position'] : '',
                ''
            )) continue;
            $eligibleEmployees[] = $employeeRow;
        }

        $params = array();
        $placeholders = array();
        for ($i = 0; $i < count($noticeIds); $i++) {
            $key = ':notice_' . $i;
            $placeholders[] = $key;
            $params[$key] = $noticeIds[$i];
        }
        $readMap = array();
        $stRead = $pdo->prepare("SELECT notice_id,employee_id FROM cpms_dashboard_notice_reads WHERE notice_id IN (" . implode(',', $placeholders) . ")");
        $stRead->execute($params);
        $readRows = $stRead->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($readRows)) {
            foreach ($readRows as $readRow) {
                $readNoticeId = isset($readRow['notice_id']) ? (string)$readRow['notice_id'] : '';
                $readEmployeeId = isset($readRow['employee_id']) ? (int)$readRow['employee_id'] : 0;
                if ($readNoticeId !== '' && $readEmployeeId > 0) $readMap[$readNoticeId . ':' . $readEmployeeId] = true;
            }
        }

        foreach ($notices as $notice) {
            $noticeId = is_array($notice) && isset($notice['id']) ? trim((string)$notice['id']) : '';
            if ($noticeId === '' || !isset($noticeMap[$noticeId])) continue;
            $authorEmail = is_array($notice) && isset($notice['author_email']) ? strtolower(trim((string)$notice['author_email'])) : '';
            foreach ($eligibleEmployees as $employeeRow) {
                $employeeId = isset($employeeRow['id']) ? (int)$employeeRow['id'] : 0;
                $employeeEmail = isset($employeeRow['email']) ? strtolower(trim((string)$employeeRow['email'])) : '';
                if ($authorEmail !== '' && $employeeEmail !== '' && $authorEmail === $employeeEmail) continue;
                if (isset($readMap[$noticeId . ':' . $employeeId])) continue;
                $noticeMap[$noticeId][] = $employeeRow;
            }
        }
        return $noticeMap;
    } catch (Exception $e) {
        error_log('[dashboard_notice_read] unread lookup failed: ' . $e->getMessage());
        return false;
    }
}}

if (!function_exists('cpms_dashboard_notice_receiver_employee_ids')) {
function cpms_dashboard_notice_receiver_employee_ids($pdo) {
    $ids = array();
    if (!$pdo) return $ids;
    if (!cpms_dashboard_notice_employee_column_exists($pdo, 'google_chat_enabled')) return $ids;

    $where = array('google_chat_enabled = 1');
    if (cpms_dashboard_notice_employee_column_exists($pdo, 'is_active')) {
        $where[] = 'is_active = 1';
    }

    $hasDmSpace = cpms_dashboard_notice_employee_column_exists($pdo, 'google_chat_dm_space_name');
    $hasUserName = cpms_dashboard_notice_employee_column_exists($pdo, 'google_chat_user_name');
    $dmConditions = array();
    if ($hasDmSpace) $dmConditions[] = "(google_chat_dm_space_name IS NOT NULL AND TRIM(google_chat_dm_space_name) <> '')";
    if ($hasUserName) $dmConditions[] = "(google_chat_user_name IS NOT NULL AND TRIM(google_chat_user_name) <> '')";
    if (count($dmConditions) === 0) return $ids;
    $where[] = '(' . implode(' OR ', $dmConditions) . ')';

    try {
        $sql = 'SELECT id FROM employees WHERE ' . implode(' AND ', $where) . ' ORDER BY id ASC';
        $st = $pdo->query($sql);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $employeeId = isset($row['id']) ? (int)$row['id'] : 0;
            if ($employeeId > 0) $ids[] = $employeeId;
        }
    } catch (Exception $e) {
        error_log('[dashboard_notice_chat] receiver lookup failed: ' . $e->getMessage());
    }
    return $ids;
}}

if (!function_exists('cpms_dashboard_notice_build_created_dm_message')) {
function cpms_dashboard_notice_build_created_dm_message($pdo, $notice, $employeeId) {
    if (!is_array($notice)) $notice = array();
    $title = isset($notice['title']) ? trim((string)$notice['title']) : '';
    $author = isset($notice['author_name']) ? trim((string)$notice['author_name']) : '';
    if ($author === '') $author = isset($notice['author_email']) ? trim((string)$notice['author_email']) : '';
    if ($author === '') $author = '-';
    if ($title === '') $title = '-';

    if (function_exists('cpms_app_route_url')) {
        $url = cpms_app_route_url($pdo, 'notices', array(), (int)$employeeId);
    } else if (function_exists('cpms_public_base_url')) {
        $url = cpms_public_base_url($pdo) . '/?r=notices';
    } else {
        $url = '?r=notices';
    }

    $lines = array();
    $lines[] = urldecode('%EA%B3%B5%EC%A7%80%EC%82%AC%ED%95%AD%EC%9D%B4%20%EC%9E%91%EC%84%B1%EB%90%98%EC%97%88%EC%8A%B5%EB%8B%88%EB%8B%A4.%20%ED%99%95%EC%9D%B8%ED%95%B4%EC%A3%BC%EC%84%B8%EC%9A%94.');
    $lines[] = urldecode('%EC%9E%91%EC%84%B1%EC%9E%90') . ' : ' . $author;
    $lines[] = urldecode('%EC%A0%9C%EB%AA%A9') . ' : ' . $title;
    $lines[] = 'URL : ' . $url;
    return implode("\n", $lines);
}}

if (!function_exists('cpms_dashboard_notice_send_created_dm')) {
function cpms_dashboard_notice_send_created_dm($pdo, $notice) {
    $result = array('total' => 0, 'sent' => 0, 'failed' => 0);
    if (!$pdo || !is_array($notice)) return $result;
    if (!function_exists('cpms_send_google_chat_to_employee')) return $result;

    $employeeIds = cpms_dashboard_notice_receiver_employee_ids($pdo);
    $result['total'] = count($employeeIds);
    foreach ($employeeIds as $employeeId) {
        $message = cpms_dashboard_notice_build_created_dm_message($pdo, $notice, (int)$employeeId);
        $ok = cpms_send_google_chat_to_employee($pdo, (int)$employeeId, $message, 0, 'NOTICE_CREATED', 'DASHBOARD_NOTICE');
        if ($ok) $result['sent']++;
        else $result['failed']++;
    }
    return $result;
}}

if (!function_exists('cpms_dashboard_notice_source_id')) {
function cpms_dashboard_notice_source_id($notice) {
    $noticeId = is_array($notice) && isset($notice['id']) ? trim((string)$notice['id']) : '';
    if ($noticeId === '') $noticeId = date('YmdHis');
    return (int)hexdec(substr(md5($noticeId), 0, 7));
}}

if (!function_exists('cpms_dashboard_notice_excerpt')) {
function cpms_dashboard_notice_excerpt($content, $limit) {
    $content = trim((string)$content);
    $limit = (int)$limit;
    if ($limit <= 0) return '';
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($content, 'UTF-8') <= $limit) return $content;
        return mb_substr($content, 0, $limit, 'UTF-8') . '...';
    }
    if (preg_match_all('/./us', $content, $matches) && isset($matches[0]) && is_array($matches[0])) {
        if (count($matches[0]) <= $limit) return $content;
        return implode('', array_slice($matches[0], 0, $limit)) . '...';
    }
    if (strlen($content) <= $limit) return $content;
    return substr($content, 0, $limit) . '...';
}}

if (!function_exists('cpms_dashboard_notice_build_created_company_message')) {
function cpms_dashboard_notice_build_created_company_message($notice) {
    return "공지 사항 작성되었습니다.\n공지사항 메뉴에서 최신 공지글 확인해주세요.";
}}

if (!function_exists('cpms_dashboard_notice_send_created_company_chat')) {
function cpms_dashboard_notice_send_created_company_chat($pdo, $notice) {
    if (!$pdo || !is_array($notice)) return false;
    if (!function_exists('cpms_google_chat_send_to_company_space')) {
        require_once __DIR__ . '/../common/chat_notification_helpers.php';
    }
    if (!function_exists('cpms_google_chat_send_to_company_space')) return false;
    $message = cpms_dashboard_notice_build_created_company_message($notice);
    $sourceId = cpms_dashboard_notice_source_id($notice);
    return cpms_google_chat_send_to_company_space($pdo, $message, 'NOTICE_CREATED_COMPANY_SPACE', $sourceId, 'DASHBOARD_NOTICE');
}}

if (!function_exists('cpms_dashboard_notice_delete_item')) {
function cpms_dashboard_notice_delete_item($id) {
    $id = trim((string)$id);
    if ($id === '') return false;
    $store = cpms_dashboard_notice_read_store();
    $nextItems = array();
    foreach ($store['items'] as $item) {
        $row = cpms_dashboard_notice_normalize_item($item);
        if ($row['id'] === $id) continue;
        $nextItems[] = $row;
    }
    $store['items'] = $nextItems;
    return cpms_dashboard_notice_write_store($store);
}}

if (!function_exists('cpms_dashboard_notice_return_url')) {
function cpms_dashboard_notice_return_url() {
    $url = isset($_SERVER['REQUEST_URI']) ? trim((string)$_SERVER['REQUEST_URI']) : '';
    if ($url === '') $url = '?r=dashboard';
    return $url;
}}

if (!function_exists('cpms_dashboard_notice_meta')) {
function cpms_dashboard_notice_meta($notice) {
    $author = isset($notice['author_name']) && trim((string)$notice['author_name']) !== '' ? trim((string)$notice['author_name']) : '-';
    $created = isset($notice['created_at']) ? trim((string)$notice['created_at']) : '';
    if ($created !== '' && strlen($created) > 16) $created = substr($created, 0, 16);
    return $author . ' / ' . ($created !== '' ? $created : '-');
}}

if (!function_exists('cpms_render_dashboard_notice_board')) {
function cpms_render_dashboard_notice_board($pdo) {
    $canManage = cpms_dashboard_notice_can_manage();
    $canViewUnread = cpms_dashboard_notice_can_view_unread($pdo);
    $items = cpms_dashboard_notice_sorted_items($canManage);
    $noticePageSize = 15;
    $pinnedItems = array();
    $regularItems = array();
    foreach ($items as $noticeItem) {
        if (isset($noticeItem['is_pinned']) && (int)$noticeItem['is_pinned'] === 1) {
            $pinnedItems[] = $noticeItem;
        } else {
            $regularItems[] = $noticeItem;
        }
    }
    $noticeTotalCount = count($regularItems);
    $noticeTotalPages = max(1, (int)ceil($noticeTotalCount / $noticePageSize));
    $noticePage = isset($_GET['notice_page']) ? (int)$_GET['notice_page'] : 1;
    if ($noticePage < 1) $noticePage = 1;
    if ($noticePage > $noticeTotalPages) $noticePage = $noticeTotalPages;
    $pageRegularItems = array_slice($regularItems, ($noticePage - 1) * $noticePageSize, $noticePageSize);
    $pageItems = array_merge($pinnedItems, $pageRegularItems);
    $currentEmployeeId = cpms_dashboard_notice_current_employee_id($pdo);
    $unreadEmployeeMap = $canViewUnread ? cpms_dashboard_notice_unread_employee_map($pdo, $pageItems, $currentEmployeeId) : array();
    $unreadTrackingAvailable = is_array($unreadEmployeeMap);
    $noticePageParams = $_GET;
    if (!is_array($noticePageParams)) $noticePageParams = array();
    if (!isset($noticePageParams['r']) || trim((string)$noticePageParams['r']) === '') {
        $noticePageParams['r'] = 'notices';
    }
    $returnUrl = cpms_dashboard_notice_return_url();
    $actionUrl = base_url() . '/?r=notice_save';
    $readActionUrl = base_url() . '/?r=notice_read';
    $noticeFlash = cpms_dashboard_notice_flash_get();
    ?>
    <style>
      #cpmsDashboardNoticeBoard .cpms-notice-mobile-meta { display: none; }
      @media (max-width: 767px) {
        #cpmsDashboardNoticeBoard { padding: 16px; border-radius: 20px; }
        #cpmsDashboardNoticeBoard .cpms-notice-table { overflow: hidden; border: 1px solid #e2e8f0; background: #fff; }
        #cpmsDashboardNoticeBoard .cpms-notice-table table,
        #cpmsDashboardNoticeBoard .cpms-notice-table thead,
        #cpmsDashboardNoticeBoard .cpms-notice-table tbody,
        #cpmsDashboardNoticeBoard .cpms-notice-table tr { display: block; width: 100%; min-width: 0; }
        #cpmsDashboardNoticeBoard .cpms-notice-table table { min-width: 0; }
        #cpmsDashboardNoticeBoard .cpms-notice-table thead { display: block; background: #f8fafc; }
        #cpmsDashboardNoticeBoard .cpms-notice-table thead tr,
        #cpmsDashboardNoticeBoard .cpms-notice-table tbody tr {
          display: grid;
          grid-template-columns: 60px minmax(0, 1fr) auto;
          align-items: center;
          margin: 0;
          padding: 0;
          border: 0;
          border-top: 1px solid #e2e8f0;
          border-radius: 0;
          background: #fff;
          box-shadow: none;
        }
        #cpmsDashboardNoticeBoard .cpms-notice-table thead tr { border-top: 0; background: #f8fafc; }
        #cpmsDashboardNoticeBoard .cpms-notice-table th {
          display: block;
          width: auto;
          min-width: 0;
          padding: 10px 8px;
          font-size: 12px;
        }
        #cpmsDashboardNoticeBoard .cpms-notice-table th:nth-child(3),
        #cpmsDashboardNoticeBoard .cpms-notice-table th:nth-child(4),
        #cpmsDashboardNoticeBoard .cpms-notice-table td[data-notice-writer-cell],
        #cpmsDashboardNoticeBoard .cpms-notice-table td[data-notice-date-cell] { display: none; }
        #cpmsDashboardNoticeBoard .cpms-notice-table td {
          display: block;
          width: auto;
          min-width: 0;
          padding: 12px 8px;
          border: 0;
          text-align: left;
        }
        #cpmsDashboardNoticeBoard .cpms-notice-table td::before { display: none; }
        #cpmsDashboardNoticeBoard .cpms-notice-table td[data-notice-title-cell] button { width: 100%; line-height: 1.45; }
        #cpmsDashboardNoticeBoard .cpms-notice-table td[data-notice-manage-cell] { padding-left: 4px; text-align: right; }
        #cpmsDashboardNoticeBoard .cpms-notice-table td[data-notice-manage-cell] > div { margin-left: 0; }
        #cpmsDashboardNoticeBoard .cpms-notice-table .cpms-notice-mobile-meta {
          display: block;
          margin-top: 4px;
          overflow: hidden;
          color: #64748b;
          font-size: 11px;
          font-weight: 500;
          line-height: 1.4;
          text-overflow: ellipsis;
          white-space: nowrap;
        }
      }
    </style>
    <div id="cpmsDashboardNoticeBoard" class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 mb-8">
        <?php if (is_array($noticeFlash) && isset($noticeFlash['message']) && trim((string)$noticeFlash['message']) !== ''): ?>
            <div class="mb-4 p-4 rounded-2xl border <?php echo (isset($noticeFlash['type']) && (string)$noticeFlash['type'] === 'success') ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-red-50 border-red-200 text-red-700'; ?>">
                <?php echo h($noticeFlash['message']); ?>
            </div>
        <?php endif; ?>
        <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4 mb-5">
            <div class="min-w-0">
                <div class="flex items-center gap-3">
                    <span class="inline-flex items-center justify-center w-11 h-11 rounded-2xl bg-sky-50 text-sky-700 border border-sky-100">
                        <i data-lucide="megaphone" class="w-5 h-5"></i>
                    </span>
                    <div>
                        <h2 class="text-2xl font-extrabold text-gray-900"><?php echo h(cpms_dashboard_notice_label('notice')); ?></h2>
                        <div class="text-sm text-gray-500 mt-1"><?php echo h(cpms_dashboard_notice_label('subtitle')); ?></div>
                    </div>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="px-3 py-2 rounded-full bg-slate-100 text-slate-700 text-sm font-extrabold"><?php echo h(cpms_dashboard_notice_label('all')); ?> <?php echo count($items); ?><?php echo h(cpms_dashboard_notice_label('count_unit')); ?></span>
                <?php if ($canManage): ?>
                    <button type="button" data-dashboard-notice-create class="inline-flex items-center gap-2 px-4 py-3 rounded-2xl bg-gray-900 text-white text-sm font-extrabold">
                        <i data-lucide="plus" class="w-4 h-4"></i><?php echo h(cpms_dashboard_notice_label('create')); ?>
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <?php if (count($items) === 0): ?>
            <div class="p-6 rounded-2xl border border-dashed border-gray-300 text-sm text-gray-500"><?php echo h(cpms_dashboard_notice_label('empty')); ?></div>
        <?php else: ?>
            <div class="cpms-notice-table overflow-x-auto rounded-2xl border border-gray-200 bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-gray-600">
                        <tr>
                            <th class="px-4 py-3 text-left font-extrabold w-24"><?php echo h(cpms_dashboard_notice_label('status')); ?></th>
                            <th class="px-4 py-3 text-left font-extrabold"><?php echo h(cpms_dashboard_notice_label('title')); ?></th>
                            <th class="px-4 py-3 text-left font-extrabold w-36"><?php echo h(cpms_dashboard_notice_label('writer')); ?></th>
                            <th class="px-4 py-3 text-left font-extrabold w-40"><?php echo h(cpms_dashboard_notice_label('date')); ?></th>
                            <?php if ($canManage): ?>
                                <th class="px-4 py-3 text-right font-extrabold w-36"><?php echo h(cpms_dashboard_notice_label('manage')); ?></th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pageItems as $notice): ?>
                            <?php
                            $noticeId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$notice['id']);
                            $noticeTitle = isset($notice['title']) ? (string)$notice['title'] : '';
                            $noticeContent = isset($notice['content']) ? (string)$notice['content'] : '';
                            $createdAt = isset($notice['created_at']) ? (string)$notice['created_at'] : '';
                            if ($createdAt !== '' && strlen($createdAt) > 16) $createdAt = substr($createdAt, 0, 16);
                            ?>
                            <tr class="border-t border-gray-100 hover:bg-sky-50/40">
                                <td class="px-4 py-3 align-top" data-label="<?php echo h(cpms_dashboard_notice_label('status')); ?>">
                                    <?php if ((int)$notice['is_pinned'] === 1): ?>
                                        <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-amber-50 text-amber-700 border border-amber-100 text-xs font-extrabold">
                                            <i data-lucide="pin" class="w-3 h-3"></i><?php echo h(cpms_dashboard_notice_label('pinned')); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex px-2 py-1 rounded-full bg-slate-100 text-slate-600 text-xs font-bold"><?php echo h(cpms_dashboard_notice_label('normal')); ?></span>
                                    <?php endif; ?>
                                    <?php if ($canManage && (int)$notice['is_active'] !== 1): ?>
                                        <span class="mt-1 inline-flex px-2 py-1 rounded-full bg-gray-100 text-gray-500 text-xs font-bold"><?php echo h(cpms_dashboard_notice_label('inactive')); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 align-top" data-notice-title-cell data-label="<?php echo h(cpms_dashboard_notice_label('title')); ?>">
                                    <button type="button" class="text-left font-extrabold text-gray-900 hover:text-sky-700 break-words" data-dashboard-notice-open="<?php echo h($noticeId); ?>">
                                        <?php echo h($noticeTitle); ?>
                                    </button>
                                    <div class="cpms-notice-mobile-meta">
                                        <?php echo h(isset($notice['author_name']) && trim((string)$notice['author_name']) !== '' ? $notice['author_name'] : '-'); ?>
                                        <span aria-hidden="true">·</span>
                                        <?php echo h($createdAt !== '' ? $createdAt : '-'); ?>
                                    </div>
                                    <div id="cpmsDashboardNoticeContent-<?php echo h($noticeId); ?>" class="hidden">
                                        <div data-notice-title><?php echo h($noticeTitle); ?></div>
                                        <div data-notice-meta><?php echo h(cpms_dashboard_notice_meta($notice)); ?></div>
                                        <div data-notice-body>
                                            <div class="whitespace-normal"><?php echo nl2br(h($noticeContent)); ?></div>
                                            <?php if ($canViewUnread): ?>
                                                <?php
                                                $unreadEmployees = ($unreadTrackingAvailable && isset($unreadEmployeeMap[$notice['id']]) && is_array($unreadEmployeeMap[$notice['id']])) ? $unreadEmployeeMap[$notice['id']] : array();
                                                $unreadCount = count($unreadEmployees);
                                                $visibleUnreadEmployees = array_slice($unreadEmployees, 0, 10);
                                                $extraUnreadEmployees = array_slice($unreadEmployees, 10);
                                                ?>
                                                <div class="mt-6 pt-5 border-t border-gray-200" data-notice-unread-section>
                                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                                        <div class="font-extrabold text-gray-900">미열람자</div>
                                                        <?php if ($unreadTrackingAvailable): ?>
                                                            <span class="inline-flex px-2 py-1 rounded-full bg-rose-50 text-rose-700 text-xs font-extrabold"><?php echo (int)$unreadCount; ?>명</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if (!$unreadTrackingAvailable): ?>
                                                        <div class="mt-3 text-sm text-rose-600">미열람 정보를 불러오지 못했습니다.</div>
                                                    <?php elseif ($unreadCount === 0): ?>
                                                        <div class="mt-3 text-sm text-emerald-700 font-bold">모두 읽었습니다.</div>
                                                    <?php else: ?>
                                                        <div class="mt-3 flex flex-wrap gap-2">
                                                            <?php foreach ($visibleUnreadEmployees as $unreadEmployee): ?>
                                                                <?php
                                                                $unreadName = isset($unreadEmployee['name']) ? trim((string)$unreadEmployee['name']) : '';
                                                                if ($unreadName === '') $unreadName = isset($unreadEmployee['email']) ? trim((string)$unreadEmployee['email']) : '';
                                                                if ($unreadName === '') $unreadName = '#' . (isset($unreadEmployee['id']) ? (int)$unreadEmployee['id'] : 0);
                                                                $unreadMeta = array();
                                                                if (isset($unreadEmployee['department']) && trim((string)$unreadEmployee['department']) !== '') $unreadMeta[] = trim((string)$unreadEmployee['department']);
                                                                if (isset($unreadEmployee['position']) && trim((string)$unreadEmployee['position']) !== '') $unreadMeta[] = trim((string)$unreadEmployee['position']);
                                                                ?>
                                                                <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-gray-100 text-gray-800 text-xs font-bold">
                                                                    <?php echo h($unreadName); ?><?php if (count($unreadMeta) > 0): ?><span class="text-gray-500 font-normal">· <?php echo h(implode(' ', $unreadMeta)); ?></span><?php endif; ?>
                                                                </span>
                                                            <?php endforeach; ?>
                                                        </div>
                                                        <?php if (count($extraUnreadEmployees) > 0): ?>
                                                            <div class="hidden mt-2" data-notice-unread-extra>
                                                                <div class="flex flex-wrap gap-2">
                                                                <?php foreach ($extraUnreadEmployees as $unreadEmployee): ?>
                                                                    <?php
                                                                    $unreadName = isset($unreadEmployee['name']) ? trim((string)$unreadEmployee['name']) : '';
                                                                    if ($unreadName === '') $unreadName = isset($unreadEmployee['email']) ? trim((string)$unreadEmployee['email']) : '';
                                                                    if ($unreadName === '') $unreadName = '#' . (isset($unreadEmployee['id']) ? (int)$unreadEmployee['id'] : 0);
                                                                    $unreadMeta = array();
                                                                    if (isset($unreadEmployee['department']) && trim((string)$unreadEmployee['department']) !== '') $unreadMeta[] = trim((string)$unreadEmployee['department']);
                                                                    if (isset($unreadEmployee['position']) && trim((string)$unreadEmployee['position']) !== '') $unreadMeta[] = trim((string)$unreadEmployee['position']);
                                                                    ?>
                                                                    <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-gray-100 text-gray-800 text-xs font-bold">
                                                                        <?php echo h($unreadName); ?><?php if (count($unreadMeta) > 0): ?><span class="text-gray-500 font-normal">· <?php echo h(implode(' ', $unreadMeta)); ?></span><?php endif; ?>
                                                                    </span>
                                                                <?php endforeach; ?>
                                                                </div>
                                                            </div>
                                                            <button type="button" class="mt-3 px-3 py-2 rounded-xl border border-gray-200 bg-white text-gray-700 text-xs font-extrabold" data-notice-unread-toggle data-expand-text="더보기 (<?php echo count($extraUnreadEmployees); ?>명)" data-collapse-text="접기">더보기 (<?php echo count($extraUnreadEmployees); ?>명)</button>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3 align-top text-gray-600" data-notice-writer-cell data-label="<?php echo h(cpms_dashboard_notice_label('writer')); ?>"><?php echo h(isset($notice['author_name']) && trim((string)$notice['author_name']) !== '' ? $notice['author_name'] : '-'); ?></td>
                                <td class="px-4 py-3 align-top text-gray-600" data-notice-date-cell data-label="<?php echo h(cpms_dashboard_notice_label('date')); ?>"><?php echo h($createdAt !== '' ? $createdAt : '-'); ?></td>
                                <?php if ($canManage): ?>
                                    <td class="px-4 py-3 align-top text-right" data-notice-manage-cell data-label="<?php echo h(cpms_dashboard_notice_label('manage')); ?>">
                                        <div class="inline-flex items-center gap-2">
                                            <button type="button"
                                                class="inline-flex items-center justify-center w-9 h-9 rounded-xl border border-gray-200 bg-white text-gray-700 hover:bg-gray-50"
                                                title="<?php echo h(cpms_dashboard_notice_label('edit')); ?>"
                                                data-dashboard-notice-edit
                                                data-id="<?php echo h($notice['id']); ?>"
                                                data-title="<?php echo h($noticeTitle); ?>"
                                                data-content="<?php echo h($noticeContent); ?>"
                                                data-active="<?php echo (int)$notice['is_active']; ?>"
                                                data-pinned="<?php echo (int)$notice['is_pinned']; ?>">
                                                <i data-lucide="pencil" class="w-4 h-4"></i>
                                            </button>
                                            <form method="post" action="<?php echo h($actionUrl); ?>" data-dashboard-notice-delete>
                                                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                <input type="hidden" name="return_url" value="<?php echo h($returnUrl); ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo h($notice['id']); ?>">
                                                <button type="submit" class="inline-flex items-center justify-center w-9 h-9 rounded-xl border border-red-100 bg-red-50 text-red-700 hover:bg-red-100" title="<?php echo h(cpms_dashboard_notice_label('delete')); ?>">
                                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($noticeTotalPages > 1): ?>
                <nav class="mt-5 flex flex-wrap items-center justify-center gap-2" aria-label="공지사항 페이지">
                    <?php for ($noticePageNumber = 1; $noticePageNumber <= $noticeTotalPages; $noticePageNumber++): ?>
                        <?php
                        $noticePageParams['notice_page'] = $noticePageNumber;
                        $noticePageUrl = '?' . http_build_query($noticePageParams, '', '&');
                        $noticePageClass = ($noticePageNumber === $noticePage)
                            ? 'bg-sky-600 border-sky-600 text-white shadow-sm'
                            : 'bg-white border-gray-200 text-gray-700 hover:bg-sky-50 hover:text-sky-700';
                        ?>
                        <a href="<?php echo h($noticePageUrl); ?>"
                           class="inline-flex min-w-[38px] h-10 items-center justify-center rounded-xl border px-3 text-sm font-extrabold <?php echo h($noticePageClass); ?>"
                           <?php echo ($noticePageNumber === $noticePage) ? 'aria-current="page"' : ''; ?>><?php echo $noticePageNumber; ?></a>
                    <?php endfor; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>

    </div>

    <?php if ($canManage): ?>
        <div id="modal-dashboardNoticeForm" class="fixed inset-0 z-50 hidden">
            <div class="absolute inset-0 bg-black/45" data-dashboard-notice-form-close></div>
            <div class="absolute inset-0 flex items-center justify-center p-4">
                <div id="cpmsDashboardNoticeFormWrap" class="w-full max-w-3xl max-h-[88vh] overflow-y-auto rounded-3xl bg-white shadow-2xl border border-gray-100">
                    <div class="flex items-center justify-between px-6 py-5 border-b border-gray-100">
                        <div class="text-2xl font-extrabold text-gray-900" data-dashboard-notice-form-title><?php echo h(cpms_dashboard_notice_label('new_notice')); ?></div>
                        <button type="button" class="p-3 rounded-2xl hover:bg-gray-100" data-dashboard-notice-form-close><?php echo h(cpms_dashboard_notice_label('close')); ?></button>
                    </div>
                    <form id="cpmsDashboardNoticeForm" method="post" action="<?php echo h($actionUrl); ?>" class="p-5 md:p-6 space-y-3">
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                        <input type="hidden" name="return_url" value="<?php echo h($returnUrl); ?>">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="id" value="">
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-1"><?php echo h(cpms_dashboard_notice_label('notice_title')); ?></label>
                            <input type="text" name="title" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white" required>
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-1"><?php echo h(cpms_dashboard_notice_label('notice_content')); ?></label>
                            <textarea name="content" rows="5" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white" required></textarea>
                        </div>
                        <div class="flex flex-wrap items-center justify-between gap-3 pt-2">
                            <div class="flex flex-wrap items-center gap-3">
                                <label class="inline-flex items-center gap-2 px-3 py-2 rounded-2xl bg-white border border-gray-200 text-sm font-bold text-gray-700">
                                    <input type="hidden" name="is_active" value="0">
                                    <input type="checkbox" name="is_active" value="1" checked>
                                    <?php echo h(cpms_dashboard_notice_label('visible')); ?>
                                </label>
                                <label class="inline-flex items-center gap-2 px-3 py-2 rounded-2xl bg-white border border-gray-200 text-sm font-bold text-gray-700">
                                    <input type="hidden" name="is_pinned" value="0">
                                    <input type="checkbox" name="is_pinned" value="1">
                                    <?php echo h(cpms_dashboard_notice_label('fixed')); ?>
                                </label>
                            </div>
                            <div class="flex items-center gap-2">
                                <button type="button" data-dashboard-notice-form-reset class="px-4 py-3 rounded-2xl border border-gray-200 bg-white text-gray-700 font-extrabold"><?php echo h(cpms_dashboard_notice_label('cancel')); ?></button>
                                <button type="submit" data-dashboard-notice-submit class="px-5 py-3 rounded-2xl bg-gray-900 text-white font-extrabold"><?php echo h(cpms_dashboard_notice_label('save')); ?></button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div id="modal-dashboardNoticeDetail" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/45" data-dashboard-notice-detail-close></div>
        <div class="absolute inset-0 flex items-center justify-center p-4">
            <div class="w-full max-w-3xl max-h-[88vh] overflow-hidden rounded-3xl bg-white shadow-2xl border border-gray-100">
                <div class="flex items-center justify-between px-6 py-5 border-b border-gray-100">
                    <div class="min-w-0">
                        <div class="text-2xl font-extrabold text-gray-900" data-dashboard-notice-detail-title><?php echo h(cpms_dashboard_notice_label('detail')); ?></div>
                        <div class="text-sm text-gray-500 mt-1" data-dashboard-notice-detail-meta></div>
                    </div>
                    <button type="button" class="p-3 rounded-2xl hover:bg-gray-100" data-dashboard-notice-detail-close><?php echo h(cpms_dashboard_notice_label('close')); ?></button>
                </div>
                <div class="p-5 md:p-6 overflow-y-auto max-h-[66vh] text-sm leading-7 text-gray-700" data-dashboard-notice-detail-body></div>
            </div>
        </div>
    </div>

    <script>
    (function(){
        var detailModal = document.getElementById('modal-dashboardNoticeDetail');
        var formModal = document.getElementById('modal-dashboardNoticeForm');
        var deleteMessage = <?php echo json_encode(cpms_dashboard_notice_label('confirm_delete')); ?>;
        var editSaveText = <?php echo json_encode(cpms_dashboard_notice_label('edit_save')); ?>;
        var saveText = <?php echo json_encode(cpms_dashboard_notice_label('save')); ?>;
        var newNoticeText = <?php echo json_encode(cpms_dashboard_notice_label('new_notice')); ?>;
        var noticeReadUrl = <?php echo json_encode($readActionUrl); ?>;
        var noticeReadToken = <?php echo json_encode(csrf_token()); ?>;

        function markNoticeRead(id) {
            if (!id || !noticeReadUrl || !window.XMLHttpRequest) return;
            try {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', noticeReadUrl, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
                xhr.send('_csrf=' + encodeURIComponent(noticeReadToken || '') + '&notice_id=' + encodeURIComponent(id));
            } catch (ignore) {}
        }

        function closeDetail() {
            if (detailModal) detailModal.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }
        function closeFormModal() {
            if (formModal) formModal.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }
        function openFormModal() {
            if (!formModal) return;
            formModal.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
        }
        function openDetail(id) {
            if (!detailModal || !id) return;
            var template = document.getElementById('cpmsDashboardNoticeContent-' + id);
            if (!template) return;
            var title = template.querySelector('[data-notice-title]');
            var meta = template.querySelector('[data-notice-meta]');
            var body = template.querySelector('[data-notice-body]');
            var detailTitle = detailModal.querySelector('[data-dashboard-notice-detail-title]');
            var detailMeta = detailModal.querySelector('[data-dashboard-notice-detail-meta]');
            var detailBody = detailModal.querySelector('[data-dashboard-notice-detail-body]');
            if (detailTitle) detailTitle.textContent = title ? title.textContent : '';
            if (detailMeta) detailMeta.textContent = meta ? meta.textContent : '';
            if (detailBody) detailBody.innerHTML = body ? body.innerHTML : '';
            detailModal.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
            markNoticeRead(id);
        }

        if (detailModal) {
            detailModal.addEventListener('click', function(e){
                var target = e.target;
                while (target && target !== detailModal && !target.hasAttribute('data-notice-unread-toggle')) target = target.parentNode;
                if (!target || target === detailModal) return;
                e.preventDefault();
                var section = target.parentNode;
                var extra = section ? section.querySelector('[data-notice-unread-extra]') : null;
                if (!extra) return;
                var isHidden = extra.classList.contains('hidden');
                if (isHidden) extra.classList.remove('hidden');
                else extra.classList.add('hidden');
                target.textContent = isHidden ? (target.getAttribute('data-collapse-text') || '접기') : (target.getAttribute('data-expand-text') || '더보기');
            });
        }

        var detailCloseButtons = document.querySelectorAll('[data-dashboard-notice-detail-close]');
        for (var j = 0; j < detailCloseButtons.length; j++) {
            detailCloseButtons[j].addEventListener('click', function(e){
                e.preventDefault();
                closeDetail();
            });
        }

        var openButtons = document.querySelectorAll('[data-dashboard-notice-open]');
        for (var k = 0; k < openButtons.length; k++) {
            openButtons[k].addEventListener('click', function(e){
                e.preventDefault();
                openDetail(this.getAttribute('data-dashboard-notice-open'));
            });
        }

        var deleteForms = document.querySelectorAll('[data-dashboard-notice-delete]');
        for (var d = 0; d < deleteForms.length; d++) {
            deleteForms[d].addEventListener('submit', function(e){
                if (!confirm(deleteMessage)) {
                    e.preventDefault();
                    return false;
                }
            });
        }

        var form = document.getElementById('cpmsDashboardNoticeForm');
        if (form) {
            var formTitle = formModal ? formModal.querySelector('[data-dashboard-notice-form-title]') : null;
            var submitButton = form.querySelector('[data-dashboard-notice-submit]');
            var idInput = form.querySelector('input[name="id"]');
            var titleInput = form.querySelector('input[name="title"]');
            var contentInput = form.querySelector('textarea[name="content"]');
            var activeInput = form.querySelector('input[type="checkbox"][name="is_active"]');
            var pinnedInput = form.querySelector('input[type="checkbox"][name="is_pinned"]');
            function resetForm() {
                if (idInput) idInput.value = '';
                if (titleInput) titleInput.value = '';
                if (contentInput) contentInput.value = '';
                if (activeInput) activeInput.checked = true;
                if (pinnedInput) pinnedInput.checked = false;
                if (submitButton) submitButton.textContent = saveText;
                if (formTitle) formTitle.textContent = newNoticeText;
            }
            var resetButton = form.querySelector('[data-dashboard-notice-form-reset]');
            if (resetButton) {
                resetButton.addEventListener('click', function(e){
                    e.preventDefault();
                    resetForm();
                    closeFormModal();
                });
            }
            var formCloseButtons = formModal ? formModal.querySelectorAll('[data-dashboard-notice-form-close]') : [];
            for (var fidx = 0; fidx < formCloseButtons.length; fidx++) {
                formCloseButtons[fidx].addEventListener('click', function(e){
                    e.preventDefault();
                    resetForm();
                    closeFormModal();
                });
            }
            var createButtons = document.querySelectorAll('[data-dashboard-notice-create]');
            for (var cidx = 0; cidx < createButtons.length; cidx++) {
                createButtons[cidx].addEventListener('click', function(e){
                    e.preventDefault();
                    resetForm();
                    openFormModal();
                    if (titleInput) titleInput.focus();
                });
            }
            var editButtons = document.querySelectorAll('[data-dashboard-notice-edit]');
            for (var eidx = 0; eidx < editButtons.length; eidx++) {
                editButtons[eidx].addEventListener('click', function(e){
                    e.preventDefault();
                    if (idInput) idInput.value = this.getAttribute('data-id') || '';
                    if (titleInput) titleInput.value = this.getAttribute('data-title') || '';
                    if (contentInput) contentInput.value = this.getAttribute('data-content') || '';
                    if (activeInput) activeInput.checked = (this.getAttribute('data-active') === '1');
                    if (pinnedInput) pinnedInput.checked = (this.getAttribute('data-pinned') === '1');
                    if (submitButton) submitButton.textContent = editSaveText;
                    if (formTitle) formTitle.textContent = editSaveText;
                    openFormModal();
                    if (titleInput) titleInput.focus();
                });
            }
        }

        document.addEventListener('keydown', function(e){
            if (e.key === 'Escape') {
                if (detailModal && !detailModal.classList.contains('hidden')) closeDetail();
                else if (formModal && !formModal.classList.contains('hidden')) closeFormModal();
            }
        });

        if (window.lucide) { try { lucide.createIcons(); } catch (ignore) {} }
    })();
    </script>
    <?php
}}

if (!function_exists('cpms_render_dashboard_notice_modal')) {
function cpms_render_dashboard_notice_modal() {
    $activeItems = cpms_dashboard_notice_sorted_items(false);
    if (count($activeItems) === 0) return;
    $signatureParts = array();
    foreach ($activeItems as $noticeSignatureRow) {
        $signatureParts[] =
            (isset($noticeSignatureRow['id']) ? (string)$noticeSignatureRow['id'] : '') . ':' .
            (isset($noticeSignatureRow['created_at']) ? (string)$noticeSignatureRow['created_at'] : '') . ':' .
            (isset($noticeSignatureRow['updated_at']) ? (string)$noticeSignatureRow['updated_at'] : '') . ':' .
            md5((isset($noticeSignatureRow['title']) ? (string)$noticeSignatureRow['title'] : '') . "\n" . (isset($noticeSignatureRow['content']) ? (string)$noticeSignatureRow['content'] : ''));
    }
    $noticeSignature = md5(implode('|', $signatureParts));
    ?>
    <div id="modal-dashboardNoticeAuto" class="fixed inset-0 z-50 hidden" data-dashboard-notice-auto="1">
        <div class="absolute inset-0 bg-black/45" data-dashboard-notice-auto-close></div>
        <div class="absolute inset-0 flex items-center justify-center p-4">
            <div class="w-full max-w-3xl max-h-[88vh] overflow-hidden rounded-3xl bg-white shadow-2xl border border-gray-100">
                <div class="flex items-center justify-between px-6 py-5 border-b border-gray-100">
                    <div>
                        <div class="text-2xl font-extrabold text-gray-900"><?php echo h(cpms_dashboard_notice_label('notice')); ?></div>
                        <div class="text-sm text-gray-500 mt-1"><?php echo h(cpms_dashboard_notice_label('recent')); ?></div>
                    </div>
                    <button type="button" class="p-3 rounded-2xl hover:bg-gray-100" data-dashboard-notice-auto-close><?php echo h(cpms_dashboard_notice_label('close')); ?></button>
                </div>
                <div class="p-5 md:p-6 overflow-y-auto max-h-[66vh] space-y-4">
                    <?php foreach ($activeItems as $notice): ?>
                        <div class="rounded-2xl border border-gray-200 bg-slate-50 p-4">
                            <div class="flex flex-wrap items-center gap-2">
                                <?php if ((int)$notice['is_pinned'] === 1): ?>
                                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-amber-50 text-amber-700 border border-amber-100 text-xs font-extrabold">
                                        <i data-lucide="pin" class="w-3 h-3"></i><?php echo h(cpms_dashboard_notice_label('pinned')); ?>
                                    </span>
                                <?php endif; ?>
                                <div class="text-lg font-extrabold text-gray-900"><?php echo h(isset($notice['title']) ? $notice['title'] : ''); ?></div>
                            </div>
                            <div class="text-xs text-gray-500 mt-2"><?php echo h(cpms_dashboard_notice_meta($notice)); ?></div>
                            <div class="mt-4 text-sm leading-7 text-gray-700 whitespace-normal"><?php echo nl2br(h(isset($notice['content']) ? $notice['content'] : '')); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div class="text-xs text-gray-500"><?php echo h(cpms_dashboard_notice_label('today_hidden')); ?></div>
                    <button type="button" class="px-5 py-3 rounded-2xl bg-gray-900 text-white font-extrabold" data-dashboard-notice-auto-close><?php echo h(cpms_dashboard_notice_label('close')); ?></button>
                </div>
            </div>
        </div>
    </div>
    <script>
    (function(){
        var autoModal = document.getElementById('modal-dashboardNoticeAuto');
        var storageKey = 'cpms_dashboard_notice_closed_until';
        var signatureKey = 'cpms_dashboard_notice_signature';
        var noticeSignature = <?php echo json_encode($noticeSignature); ?>;
        function endOfTodayTime() {
            var now = new Date();
            return new Date(now.getFullYear(), now.getMonth(), now.getDate(), 23, 59, 59, 999).getTime();
        }
        function shouldShowAuto() {
            try {
                var raw = window.localStorage ? localStorage.getItem(storageKey) : '';
                var storedSignature = window.localStorage ? (localStorage.getItem(signatureKey) || '') : '';
                var until = raw ? parseInt(raw, 10) : 0;
                if (noticeSignature && storedSignature !== noticeSignature) return true;
                if (until && until >= new Date().getTime()) return false;
            } catch (e) {}
            return true;
        }
        function closeAuto() {
            if (autoModal) autoModal.classList.add('hidden');
            try {
                if (window.localStorage) {
                    localStorage.setItem(storageKey, String(endOfTodayTime()));
                    localStorage.setItem(signatureKey, noticeSignature || '');
                }
            } catch (e) {}
            document.body.classList.remove('overflow-hidden');
        }
        function openAuto() {
            if (!autoModal || !shouldShowAuto()) return;
            autoModal.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
        }
        var closeButtons = document.querySelectorAll('[data-dashboard-notice-auto-close]');
        for (var i = 0; i < closeButtons.length; i++) {
            closeButtons[i].addEventListener('click', function(e){
                e.preventDefault();
                closeAuto();
            });
        }
        document.addEventListener('keydown', function(e){
            if (e.key === 'Escape' && autoModal && !autoModal.classList.contains('hidden')) closeAuto();
        });
        openAuto();
        if (window.lucide) { try { lucide.createIcons(); } catch (ignore) {} }
    })();
    </script>
    <?php
}}

if (!function_exists('cpms_dashboard_birthday_text')) {
function cpms_dashboard_birthday_text($encoded) {
    return urldecode($encoded);
}}

if (!function_exists('cpms_dashboard_birthday_today')) {
function cpms_dashboard_birthday_today() {
    if (function_exists('attendance_today')) return attendance_today();
    try {
        $dt = new DateTime('now', new DateTimeZone('Asia/Seoul'));
        return $dt->format('Y-m-d');
    } catch (Exception $e) {
        return date('Y-m-d');
    }
}}

if (!function_exists('cpms_dashboard_birthday_employee_column_exists')) {
function cpms_dashboard_birthday_employee_column_exists($pdo, $column) {
    if (!$pdo) return false;
    $column = trim((string)$column);
    if ($column === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) return false;
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM employees LIKE :col");
        $st->execute(array(':col' => $column));
        return (bool)$st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_dashboard_birthday_month_day')) {
function cpms_dashboard_birthday_month_day($value) {
    $value = trim((string)$value);
    if ($value === '' || $value === '0000-00-00') return array(0, 0);

    $month = 0;
    $day = 0;
    if (preg_match('/^\d{4}[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $value, $m)) {
        $month = (int)$m[1];
        $day = (int)$m[2];
    } else if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})$/', $value, $m)) {
        $month = (int)$m[1];
        $day = (int)$m[2];
    } else if (preg_match('/(\d{1,2})\D+(\d{1,2})/', $value, $m)) {
        $month = (int)$m[1];
        $day = (int)$m[2];
    }

    if (!checkdate($month, $day, 2000)) return array(0, 0);
    return array($month, $day);
}}

if (!function_exists('cpms_dashboard_birthday_date_for_year')) {
function cpms_dashboard_birthday_date_for_year($year, $month, $day) {
    $year = (int)$year;
    $month = (int)$month;
    $day = (int)$day;
    if ($year < 1 || !checkdate($month, $day, $year)) return '';
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}}

if (!function_exists('cpms_dashboard_birthday_holiday_dates')) {
function cpms_dashboard_birthday_holiday_dates($pdo, $startDate, $endDate) {
    $holidayDates = array();
    $startDate = trim((string)$startDate);
    $endDate = trim((string)$endDate);

    if ($pdo) {
        try {
            $st = $pdo->prepare("SELECT holiday_date FROM cpms_holiday_cache WHERE is_active=1 AND holiday_date BETWEEN :start_date AND :end_date");
            $st->execute(array(
                ':start_date' => $startDate,
                ':end_date' => $endDate
            ));
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $holidayDate = isset($row['holiday_date']) ? trim((string)$row['holiday_date']) : '';
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $holidayDate)) {
                    $holidayDates[$holidayDate] = true;
                }
            }
        } catch (Exception $e) {
            error_log('[dashboard_birthday] holiday lookup failed: ' . $e->getMessage());
        }
    }

    /*
     * 2026년에 새로 공휴일이 된 날짜는 기존 Google 공휴일 캐시에 아직
     * 반영되지 않았을 수 있으므로 법정 공휴일을 코드에서도 보완한다.
     * 나머지 공휴일과 대체공휴일은 위의 등록 공휴일 캐시를 사용한다.
     */
    if (preg_match('/^(\d{4})-\d{2}-\d{2}$/', $startDate, $startParts)
        && preg_match('/^(\d{4})-\d{2}-\d{2}$/', $endDate, $endParts)) {
        $startYear = (int)$startParts[1];
        $endYear = (int)$endParts[1];
        if ($endYear >= 2026) {
            if ($startYear < 2026) $startYear = 2026;
            for ($year = $startYear; $year <= $endYear; $year++) {
                $newStatutoryDates = array(
                    sprintf('%04d-05-01', $year),
                    sprintf('%04d-07-17', $year)
                );
                foreach ($newStatutoryDates as $statutoryDate) {
                    if ($statutoryDate >= $startDate && $statutoryDate <= $endDate) {
                        $holidayDates[$statutoryDate] = true;
                    }
                }
            }
        }
    }

    return $holidayDates;
}}

if (!function_exists('cpms_dashboard_birthday_is_non_working_date')) {
function cpms_dashboard_birthday_is_non_working_date($date, $holidayDates) {
    $date = trim((string)$date);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts)) return true;

    $year = (int)$parts[1];
    $month = (int)$parts[2];
    $day = (int)$parts[3];
    if (!checkdate($month, $day, $year)) return true;

    $timestamp = mktime(12, 0, 0, $month, $day, $year);
    $weekday = (int)date('N', $timestamp);
    if ($weekday === 6 || $weekday === 7) return true;

    return is_array($holidayDates) && isset($holidayDates[$date]);
}}

if (!function_exists('cpms_dashboard_birthday_celebration_date')) {
function cpms_dashboard_birthday_celebration_date($birthdayDate, $holidayDates) {
    $birthdayDate = trim((string)$birthdayDate);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $birthdayDate, $parts)) return '';
    if (!checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) return '';

    $celebrationDate = $birthdayDate;
    for ($i = 0; $i < 370; $i++) {
        if (!cpms_dashboard_birthday_is_non_working_date($celebrationDate, $holidayDates)) {
            return $celebrationDate;
        }
        $timestamp = strtotime($celebrationDate . ' 12:00:00 -1 day');
        if ($timestamp === false) return '';
        $celebrationDate = date('Y-m-d', $timestamp);
    }

    return '';
}}

if (!function_exists('cpms_dashboard_birthday_message')) {
function cpms_dashboard_birthday_message($person) {
    $name = is_array($person) && isset($person['name']) ? trim((string)$person['name']) : '';
    $position = is_array($person) && isset($person['position']) ? trim((string)$person['position']) : '';
    $suffix = cpms_dashboard_birthday_text('%EC%83%9D%EC%9D%BC%EC%B6%95%ED%95%98%ED%95%A9%EB%8B%88%EB%8B%A4%21');
    if ($name === '') return '';
    if ($position !== '') return $name . ' ' . $position . cpms_dashboard_birthday_text('%EB%8B%98%20') . $suffix;
    return $name . cpms_dashboard_birthday_text('%EB%8B%98%20') . $suffix;
}}

if (!function_exists('cpms_dashboard_birthday_today_employees')) {
function cpms_dashboard_birthday_today_employees($pdo, $today) {
    $items = array();
    if (!$pdo) return $items;
    if (!cpms_dashboard_birthday_employee_column_exists($pdo, 'name')) return $items;
    if (!cpms_dashboard_birthday_employee_column_exists($pdo, 'birth_date')) return $items;

    $today = trim((string)$today);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $today, $todayParts)) return $items;
    $todayYear = (int)$todayParts[1];
    $todayMonth = (int)$todayParts[2];
    $todayDay = (int)$todayParts[3];
    if (!checkdate($todayMonth, $todayDay, $todayYear)) return $items;

    $holidayDates = cpms_dashboard_birthday_holiday_dates(
        $pdo,
        sprintf('%04d-01-01', $todayYear - 1),
        sprintf('%04d-12-31', $todayYear + 1)
    );

    $positionSelect = cpms_dashboard_birthday_employee_column_exists($pdo, 'position') ? 'position' : "'' AS position";
    $where = array("birth_date IS NOT NULL", "CAST(birth_date AS CHAR) <> ''", "CAST(birth_date AS CHAR) <> '0000-00-00'");
    if (cpms_dashboard_birthday_employee_column_exists($pdo, 'is_active')) {
        $where[] = 'is_active = 1';
    }

    try {
        $sql = "SELECT id, name, " . $positionSelect . ", birth_date FROM employees WHERE " . implode(' AND ', $where) . " ORDER BY name ASC, id ASC";
        $st = $pdo->query($sql);
        if (!$st) return array();
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $birthParts = cpms_dashboard_birthday_month_day(isset($row['birth_date']) ? $row['birth_date'] : '');
            $birthMonth = isset($birthParts[0]) ? (int)$birthParts[0] : 0;
            $birthDay = isset($birthParts[1]) ? (int)$birthParts[1] : 0;
            if ($birthMonth < 1 || $birthDay < 1) continue;

            $birthdayDate = '';
            $celebrationDate = '';
            $candidateYears = array($todayYear, $todayYear + 1);
            foreach ($candidateYears as $candidateYear) {
                $candidateBirthdayDate = cpms_dashboard_birthday_date_for_year($candidateYear, $birthMonth, $birthDay);
                if ($candidateBirthdayDate === '') continue;
                $candidateCelebrationDate = cpms_dashboard_birthday_celebration_date($candidateBirthdayDate, $holidayDates);
                if ($candidateCelebrationDate !== $today) continue;
                $birthdayDate = $candidateBirthdayDate;
                $celebrationDate = $candidateCelebrationDate;
                break;
            }
            if ($celebrationDate === '') continue;

            $message = cpms_dashboard_birthday_message($row);
            if ($message === '') continue;
            $row['birthday_date'] = $birthdayDate;
            $row['celebration_date'] = $celebrationDate;
            $row['message'] = $message;
            $items[] = $row;
        }
    } catch (Exception $e) {
        return array();
    }

    return $items;
}}

if (!function_exists('cpms_dashboard_birthday_comment_schema')) {
function cpms_dashboard_birthday_comment_encode_storage($text) {
    $text = (string)$text;
    $encoded = preg_replace_callback('/[\xF0-\xF4][\x80-\xBF]{3}/', function($match) {
        return '[[CPMS_BDAY_U8_' . strtoupper(bin2hex($match[0])) . ']]';
    }, $text);
    return is_string($encoded) ? $encoded : $text;
}

function cpms_dashboard_birthday_comment_decode_storage($text) {
    $text = (string)$text;
    $decoded = preg_replace_callback('/\[\[CPMS_BDAY_U8_(F[0-4](?:[89AB][0-9A-F]){3})\]\]/i', function($match) {
        $bytes = pack('H*', (string)$match[1]);
        return preg_match('//u', $bytes) ? $bytes : $match[0];
    }, $text);
    return is_string($decoded) ? $decoded : $text;
}

function cpms_dashboard_birthday_comment_schema($pdo) {
    static $ready = null;
    if ($ready !== null) return $ready;
    $ready = false;
    if (!$pdo) return false;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_birthday_comments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            celebration_date DATE NOT NULL,
            birthday_employee_id INT NOT NULL,
            comment_text TEXT NOT NULL,
            created_by_employee_id INT NULL,
            created_by_name VARCHAR(100) NOT NULL,
            created_by_email VARCHAR(190) NULL,
            created_by_photo_path VARCHAR(500) NULL,
            created_at DATETIME NOT NULL,
            KEY idx_birthday_comments_day_employee (celebration_date, birthday_employee_id),
            KEY idx_birthday_comments_author (created_by_employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $columnStatement = $pdo->query("SHOW FULL COLUMNS FROM cpms_birthday_comments LIKE 'comment_text'");
        $commentColumn = $columnStatement ? $columnStatement->fetch(PDO::FETCH_ASSOC) : false;
        $commentCollation = is_array($commentColumn) && isset($commentColumn['Collation'])
            ? (string)$commentColumn['Collation']
            : '';
        $commentType = is_array($commentColumn) && isset($commentColumn['Type'])
            ? strtolower((string)$commentColumn['Type'])
            : '';
        if (strpos($commentType, 'text') === false) {
            $pdo->exec("ALTER TABLE cpms_birthday_comments MODIFY comment_text TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL");
        }
        if (stripos($commentCollation, 'utf8mb4_') !== 0) {
            $pdo->exec("ALTER TABLE cpms_birthday_comments CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }
        $ready = true;
    } catch (Exception $e) {
        error_log('[birthday_comment] schema error: ' . $e->getMessage());
    }
    return $ready;
}}

if (!function_exists('cpms_dashboard_birthday_current_employee')) {
function cpms_dashboard_birthday_current_employee($pdo) {
    $user = class_exists('App\\Core\\Auth') ? \App\Core\Auth::user() : null;
    $employee = array(
        'id' => (is_array($user) && isset($user['id'])) ? (int)$user['id'] : 0,
        'name' => (is_array($user) && isset($user['name'])) ? (string)$user['name'] : '',
        'email' => (is_array($user) && isset($user['email'])) ? (string)$user['email'] : '',
        'photo_path' => (is_array($user) && isset($user['photo_path'])) ? (string)$user['photo_path'] : ''
    );
    if (!$pdo || trim((string)$employee['email']) === '') return $employee;

    try {
        $photoSelect = cpms_dashboard_birthday_employee_column_exists($pdo, 'photo_path') ? 'photo_path' : "'' AS photo_path";
        $st = $pdo->prepare("SELECT id, name, email, " . $photoSelect . " FROM employees WHERE LOWER(email)=LOWER(:email) LIMIT 1");
        $st->execute(array(':email' => $employee['email']));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $employee['id'] = isset($row['id']) ? (int)$row['id'] : $employee['id'];
            $employee['name'] = isset($row['name']) ? (string)$row['name'] : $employee['name'];
            $employee['email'] = isset($row['email']) ? (string)$row['email'] : $employee['email'];
            $employee['photo_path'] = isset($row['photo_path']) ? (string)$row['photo_path'] : $employee['photo_path'];
        }
    } catch (Exception $e) {
    }
    return $employee;
}}

if (!function_exists('cpms_dashboard_birthday_photo_url')) {
function cpms_dashboard_birthday_photo_url($path) {
    $path = trim((string)$path);
    if ($path === '') return '';
    if (preg_match('/^https?:\/\//i', $path)) return $path;
    $path = str_replace('\\', '/', $path);
    if (strpos($path, '/') === 0) return $path;
    return function_exists('base_url') ? base_url() . '/' . ltrim($path, '/') : '/' . ltrim($path, '/');
}}

if (!function_exists('cpms_dashboard_birthday_initial')) {
function cpms_dashboard_birthday_initial($name) {
    $name = trim((string)$name);
    if ($name === '') return '?';
    if (function_exists('mb_substr')) return mb_substr($name, 0, 1, 'UTF-8');
    return substr($name, 0, 1);
}}

if (!function_exists('cpms_dashboard_birthday_comment_map')) {
function cpms_dashboard_birthday_comment_map($pdo, $celebrationDate, $birthdays) {
    $map = array();
    if (!$pdo || !is_array($birthdays) || count($birthdays) === 0) return $map;
    if (!cpms_dashboard_birthday_comment_schema($pdo)) return $map;

    $params = array(':celebration_date' => (string)$celebrationDate);
    $placeholders = array();
    foreach ($birthdays as $index => $birthday) {
        $employeeId = isset($birthday['id']) ? (int)$birthday['id'] : 0;
        if ($employeeId <= 0) continue;
        $key = ':birthday_employee_' . (int)$index;
        $placeholders[] = $key;
        $params[$key] = $employeeId;
        $map[$employeeId] = array();
    }
    if (count($placeholders) === 0) return $map;

    try {
        $sql = "SELECT * FROM cpms_birthday_comments
                WHERE celebration_date=:celebration_date
                  AND birthday_employee_id IN (" . implode(',', $placeholders) . ")
                ORDER BY id ASC";
        $st = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            if ($key === ':celebration_date') $st->bindValue($key, (string)$value, PDO::PARAM_STR);
            else $st->bindValue($key, (int)$value, PDO::PARAM_INT);
        }
        $st->execute();
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $employeeId = isset($row['birthday_employee_id']) ? (int)$row['birthday_employee_id'] : 0;
            if ($employeeId <= 0) continue;
            $row['comment_text'] = cpms_dashboard_birthday_comment_decode_storage(isset($row['comment_text']) ? $row['comment_text'] : '');
            if (!isset($map[$employeeId])) $map[$employeeId] = array();
            $map[$employeeId][] = $row;
        }
    } catch (Exception $e) {
        error_log('[birthday_comment] fetch error: ' . $e->getMessage());
    }
    return $map;
}}

if (!function_exists('cpms_dashboard_birthday_return_url')) {
function cpms_dashboard_birthday_return_url() {
    $dashboardType = isset($_SESSION['dashboardType']) ? (string)$_SESSION['dashboardType'] : 'employee';
    $route = ($dashboardType === 'executive') ? 'dashboard_executive' : 'dashboard_employee';
    $params = array('r' => $route);
    if ($route === 'dashboard_executive' && isset($_GET['exec_tab']) && trim((string)$_GET['exec_tab']) !== '') {
        $params['exec_tab'] = trim((string)$_GET['exec_tab']);
    }
    return '?' . http_build_query($params, '', '&');
}}

if (!function_exists('cpms_dashboard_birthday_comment_flash_set')) {
function cpms_dashboard_birthday_comment_flash_set($type, $message) {
    $_SESSION['_birthday_comment_flash'] = array('type' => (string)$type, 'message' => (string)$message);
}}

if (!function_exists('cpms_dashboard_birthday_comment_flash_get')) {
function cpms_dashboard_birthday_comment_flash_get() {
    if (empty($_SESSION['_birthday_comment_flash']) || !is_array($_SESSION['_birthday_comment_flash'])) return null;
    $flash = $_SESSION['_birthday_comment_flash'];
    unset($_SESSION['_birthday_comment_flash']);
    return $flash;
}}

if (!function_exists('cpms_render_dashboard_birthday_modal')) {
function cpms_render_dashboard_birthday_modal($pdo) {
    $today = cpms_dashboard_birthday_today();
    $birthdayCommentFlash = cpms_dashboard_birthday_comment_flash_get();
    $birthdays = cpms_dashboard_birthday_today_employees($pdo, $today);
    if (count($birthdays) === 0) return;

    $birthdayCommentsAvailable = cpms_dashboard_birthday_comment_schema($pdo);
    $birthdayCommentMap = $birthdayCommentsAvailable ? cpms_dashboard_birthday_comment_map($pdo, $today, $birthdays) : array();
    $birthdayCurrentEmployee = cpms_dashboard_birthday_current_employee($pdo);
    $birthdayCurrentEmployeeId = isset($birthdayCurrentEmployee['id']) ? (int)$birthdayCurrentEmployee['id'] : 0;
    $birthdayCurrentEmail = isset($birthdayCurrentEmployee['email']) ? strtolower(trim((string)$birthdayCurrentEmployee['email'])) : '';
    $birthdayCurrentName = isset($birthdayCurrentEmployee['name']) && trim((string)$birthdayCurrentEmployee['name']) !== '' ? trim((string)$birthdayCurrentEmployee['name']) : '작성자';
    $birthdayCurrentPhotoUrl = cpms_dashboard_birthday_photo_url(isset($birthdayCurrentEmployee['photo_path']) ? $birthdayCurrentEmployee['photo_path'] : '');
    $birthdayReturnUrl = cpms_dashboard_birthday_return_url();
    $cakeSrc = function_exists('asset_url') ? asset_url('assets/img/birthday-cake.svg') : 'assets/img/birthday-cake.svg';
    $fireworkSrc = function_exists('asset_url') ? asset_url('assets/img/birthday-fireworks.svg') : 'assets/img/birthday-fireworks.svg';
    ?>
    <style>
      .cpms-birthday-modal-card{background:linear-gradient(135deg,#fff7ed 0%,#ffffff 48%,#ecfeff 100%)}
      .cpms-birthday-visual{display:flex;align-items:center;justify-content:center;gap:14px;margin:2px auto 14px}
      .cpms-birthday-img{display:block;max-width:100%;height:auto;filter:drop-shadow(0 16px 20px rgba(15,23,42,.18))}
      .cpms-birthday-cake{width:148px;animation:cpms-birthday-cake-pop 1.4s ease-in-out infinite}
      .cpms-birthday-firework{width:104px;animation:cpms-birthday-firework-pop 1.1s ease-in-out infinite}
      .cpms-birthday-firework.is-right{animation-delay:.18s}
      .cpms-birthday-message{word-break:keep-all;overflow-wrap:anywhere;letter-spacing:0}
      .cpms-birthday-comment-text{white-space:pre-wrap;overflow-wrap:anywhere}
      .cpms-birthday-emoji-button{font-size:1rem;line-height:1.5rem}
      .cpms-birthday-comments-panel{max-height:280px;overflow-y:auto;overscroll-behavior:contain;scrollbar-gutter:stable}
      @keyframes cpms-birthday-cake-pop{0%,100%{transform:translateY(0) scale(1)}50%{transform:translateY(-5px) scale(1.035)}}
      @keyframes cpms-birthday-firework-pop{0%,100%{transform:scale(1);opacity:.92}50%{transform:scale(1.08);opacity:1}}
      @media (max-width:640px){
        .cpms-birthday-visual{gap:8px}
        .cpms-birthday-cake{width:116px}
        .cpms-birthday-firework{width:76px}
        .cpms-birthday-comments-panel{max-height:230px}
      }
      @media (prefers-reduced-motion:reduce){
        .cpms-birthday-cake,.cpms-birthday-firework{animation:none}
      }
    </style>
    <div id="modal-dashboardBirthdayAuto" class="fixed inset-0 hidden" style="z-index:60;" data-dashboard-birthday-auto="1">
        <div class="absolute inset-0 bg-slate-950/55" data-dashboard-birthday-auto-close></div>
        <div class="absolute inset-0 flex items-center justify-center p-4">
            <div class="cpms-birthday-modal-card relative w-full max-w-3xl max-h-[90vh] overflow-hidden rounded-3xl bg-white shadow-2xl border border-amber-100 flex flex-col">
                <div class="p-4 md:p-6 text-center overflow-hidden min-h-0 flex-1 flex flex-col">
                    <div class="flex-none">
                      <div class="cpms-birthday-visual" aria-hidden="true">
                        <img class="cpms-birthday-img cpms-birthday-firework" src="<?php echo h($fireworkSrc); ?>" alt="<?php echo h(cpms_dashboard_birthday_text('%ED%8F%AD%EC%A3%BD%20%EC%9D%B4%EB%AF%B8%EC%A7%80')); ?>">
                        <img class="cpms-birthday-img cpms-birthday-cake" src="<?php echo h($cakeSrc); ?>" alt="<?php echo h(cpms_dashboard_birthday_text('%EC%BC%80%EC%9D%B4%ED%81%AC%20%EC%9D%B4%EB%AF%B8%EC%A7%80')); ?>">
                        <img class="cpms-birthday-img cpms-birthday-firework is-right" src="<?php echo h($fireworkSrc); ?>" alt="<?php echo h(cpms_dashboard_birthday_text('%ED%8F%AD%EC%A3%BD%20%EC%9D%B4%EB%AF%B8%EC%A7%80')); ?>">
                      </div>
                      <div class="inline-flex items-center justify-center px-3 py-1 rounded-full bg-amber-100 text-amber-800 text-sm font-extrabold border border-amber-200"><?php echo h(cpms_dashboard_birthday_text('%EC%98%A4%EB%8A%98%20%EC%B6%95%ED%95%98%ED%95%A0%20%EC%83%9D%EC%9D%BC%EC%9E%90')); ?></div>
                    <?php if (count($birthdays) === 1): ?>
                        <div class="cpms-birthday-message mt-3 text-3xl md:text-5xl leading-tight font-black text-gray-950"><?php echo h(isset($birthdays[0]['message']) ? $birthdays[0]['message'] : ''); ?></div>
                    <?php else: ?>
                        <div class="mt-3 max-h-32 overflow-y-auto space-y-2">
                            <?php foreach ($birthdays as $birthday): ?>
                                <div class="cpms-birthday-message rounded-2xl border border-amber-100 bg-white/80 px-4 py-3 text-2xl md:text-3xl leading-snug font-black text-gray-950"><?php echo h(isset($birthday['message']) ? $birthday['message'] : ''); ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="mt-3 text-base md:text-lg font-extrabold text-slate-600"><?php echo h(cpms_dashboard_birthday_text('%ED%95%A8%EA%BB%98%20%EC%B6%95%ED%95%98%ED%95%B4%EC%A3%BC%EC%84%B8%EC%9A%94.')); ?></div>
                    <?php if (is_array($birthdayCommentFlash) && !empty($birthdayCommentFlash['message'])): ?>
                        <?php
                        $birthdayFlashType = isset($birthdayCommentFlash['type']) ? (string)$birthdayCommentFlash['type'] : 'error';
                        $birthdayFlashClass = ($birthdayFlashType === 'success')
                            ? 'bg-emerald-50 border-emerald-200 text-emerald-800'
                            : (($birthdayFlashType === 'warning') ? 'bg-amber-50 border-amber-200 text-amber-800' : 'bg-red-50 border-red-200 text-red-800');
                        ?>
                        <div class="mt-3 rounded-2xl border px-4 py-2 text-sm font-extrabold <?php echo h($birthdayFlashClass); ?>"><?php echo h($birthdayCommentFlash['message']); ?></div>
                    <?php endif; ?>
                    </div>

                    <?php if ($birthdayCommentsAvailable): ?>
                        <div class="cpms-birthday-comments-panel min-h-0 mt-4 space-y-3 text-left pr-1">
                            <?php foreach ($birthdays as $birthdayIndex => $birthday): ?>
                                <?php
                                $birthdayEmployeeId = isset($birthday['id']) ? (int)$birthday['id'] : 0;
                                $birthdayName = isset($birthday['name']) ? trim((string)$birthday['name']) : '생일자';
                                $birthdayComments = isset($birthdayCommentMap[$birthdayEmployeeId]) && is_array($birthdayCommentMap[$birthdayEmployeeId]) ? $birthdayCommentMap[$birthdayEmployeeId] : array();
                                $birthdayTextareaId = 'cpmsBirthdayComment-' . $birthdayEmployeeId . '-' . (int)$birthdayIndex;
                                ?>
                                <section class="rounded-2xl border border-amber-100 bg-white/90 p-3 shadow-sm">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <div class="font-black text-gray-950"><?php echo h($birthdayName); ?>님께 축하 댓글</div>
                                        <div class="text-xs font-extrabold text-amber-700">댓글 <?php echo count($birthdayComments); ?>개</div>
                                    </div>

                                    <div class="mt-2 max-h-32 overflow-y-auto space-y-2 pr-1">
                                        <?php if (count($birthdayComments) === 0): ?>
                                            <div class="rounded-2xl border border-dashed border-amber-200 bg-amber-50/60 px-4 py-3 text-sm text-gray-500">첫 번째 생일 축하 댓글을 남겨주세요.</div>
                                        <?php else: ?>
                                            <?php foreach ($birthdayComments as $birthdayComment): ?>
                                                <?php
                                                $commentId = isset($birthdayComment['id']) ? (int)$birthdayComment['id'] : 0;
                                                $commentName = isset($birthdayComment['created_by_name']) && trim((string)$birthdayComment['created_by_name']) !== '' ? trim((string)$birthdayComment['created_by_name']) : '작성자';
                                                $commentPhotoUrl = cpms_dashboard_birthday_photo_url(isset($birthdayComment['created_by_photo_path']) ? $birthdayComment['created_by_photo_path'] : '');
                                                $commentAuthorId = isset($birthdayComment['created_by_employee_id']) ? (int)$birthdayComment['created_by_employee_id'] : 0;
                                                $commentAuthorEmail = isset($birthdayComment['created_by_email']) ? strtolower(trim((string)$birthdayComment['created_by_email'])) : '';
                                                $canEditBirthdayComment = false;
                                                if ($commentId > 0 && $birthdayCurrentEmployeeId > 0 && $commentAuthorId === $birthdayCurrentEmployeeId) {
                                                    $canEditBirthdayComment = true;
                                                } elseif ($commentId > 0 && $commentAuthorId <= 0 && $birthdayCurrentEmail !== '' && $commentAuthorEmail === $birthdayCurrentEmail) {
                                                    $canEditBirthdayComment = true;
                                                }
                                                $birthdayEditTextareaId = 'cpmsBirthdayCommentEdit-' . $commentId;
                                                ?>
                                                <div class="flex items-start gap-3 rounded-2xl bg-slate-50 px-3 py-3 border border-slate-100">
                                                    <?php if ($commentPhotoUrl !== ''): ?>
                                                        <img src="<?php echo h($commentPhotoUrl); ?>" alt="<?php echo h($commentName); ?>" class="w-9 h-9 rounded-full object-cover border border-gray-200 flex-none">
                                                    <?php else: ?>
                                                        <div class="w-9 h-9 rounded-full bg-white border border-gray-200 flex items-center justify-center text-sm font-extrabold text-slate-600 flex-none"><?php echo h(cpms_dashboard_birthday_initial($commentName)); ?></div>
                                                    <?php endif; ?>
                                                    <div class="min-w-0 flex-1">
                                                        <div class="font-extrabold text-sm text-gray-900"><?php echo h($commentName); ?></div>
                                                        <div class="cpms-birthday-comment-text mt-1 text-base text-gray-800"><?php echo h(isset($birthdayComment['comment_text']) ? $birthdayComment['comment_text'] : ''); ?></div>
                                                        <?php if ($canEditBirthdayComment): ?>
                                                            <details class="mt-2">
                                                                <summary class="inline-block cursor-pointer text-xs font-extrabold text-sky-700">수정</summary>
                                                                <form method="post" action="?r=birthday_comment_save" accept-charset="UTF-8" data-birthday-comment-form="1" class="mt-2 rounded-2xl border border-sky-100 bg-white p-3">
                                                                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                                    <input type="hidden" name="comment_id" value="<?php echo $commentId; ?>">
                                                                    <input type="hidden" name="birthday_employee_id" value="<?php echo $birthdayEmployeeId; ?>">
                                                                    <input type="hidden" name="return_url" value="<?php echo h($birthdayReturnUrl); ?>">
                                                                    <textarea id="<?php echo h($birthdayEditTextareaId); ?>" name="comment_text" rows="2" maxlength="500" required class="w-full px-3 py-2 rounded-xl border border-gray-200 text-base text-gray-900 bg-white"><?php echo h(isset($birthdayComment['comment_text']) ? $birthdayComment['comment_text'] : ''); ?></textarea>
                                                                    <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                                                                        <div class="flex items-center gap-2 text-base" aria-label="수정할 생일 이모티콘">
                                                                            <button type="button" class="cpms-birthday-emoji-button px-3 py-2 rounded-xl border border-amber-200 bg-amber-50" data-birthday-emoji-code="1F389" data-birthday-target="<?php echo h($birthdayEditTextareaId); ?>" aria-label="폭죽 이모티콘 추가">&#x1F389;</button>
                                                                            <button type="button" class="cpms-birthday-emoji-button px-3 py-2 rounded-xl border border-pink-200 bg-pink-50" data-birthday-emoji-code="1F382" data-birthday-target="<?php echo h($birthdayEditTextareaId); ?>" aria-label="생일 케이크 이모티콘 추가">&#x1F382;</button>
                                                                        </div>
                                                                        <button type="submit" class="px-4 py-2 rounded-xl bg-sky-700 text-white text-sm font-extrabold">수정 저장</button>
                                                                    </div>
                                                                </form>
                                                            </details>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>

                                    <form method="post" action="?r=birthday_comment_save" accept-charset="UTF-8" data-birthday-comment-form="1" class="mt-3 rounded-2xl border border-gray-200 bg-white p-3">
                                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                        <input type="hidden" name="birthday_employee_id" value="<?php echo $birthdayEmployeeId; ?>">
                                        <input type="hidden" name="return_url" value="<?php echo h($birthdayReturnUrl); ?>">
                                        <div class="flex items-center gap-2 mb-2">
                                            <?php if ($birthdayCurrentPhotoUrl !== ''): ?>
                                                <img src="<?php echo h($birthdayCurrentPhotoUrl); ?>" alt="<?php echo h($birthdayCurrentName); ?>" class="w-10 h-10 rounded-full object-cover border border-gray-200 flex-none">
                                            <?php else: ?>
                                                <div class="w-10 h-10 rounded-full bg-slate-100 border border-slate-200 flex items-center justify-center text-sm font-extrabold text-slate-600 flex-none"><?php echo h(cpms_dashboard_birthday_initial($birthdayCurrentName)); ?></div>
                                            <?php endif; ?>
                                            <div class="font-extrabold text-gray-900"><?php echo h($birthdayCurrentName); ?></div>
                                        </div>
                                        <textarea id="<?php echo h($birthdayTextareaId); ?>" name="comment_text" rows="1" maxlength="500" required class="w-full px-3 py-2 rounded-xl border border-gray-200 text-base text-gray-900 bg-white" placeholder="생일 축하 메시지를 입력해주세요."></textarea>
                                        <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                                            <div class="flex items-center gap-2 text-base" aria-label="생일 이모티콘">
                                                <button type="button" class="cpms-birthday-emoji-button px-3 py-2 rounded-xl border border-amber-200 bg-amber-50" data-birthday-emoji-code="1F389" data-birthday-target="<?php echo h($birthdayTextareaId); ?>" aria-label="폭죽 이모티콘 추가">&#x1F389;</button>
                                                <button type="button" class="cpms-birthday-emoji-button px-3 py-2 rounded-xl border border-pink-200 bg-pink-50" data-birthday-emoji-code="1F382" data-birthday-target="<?php echo h($birthdayTextareaId); ?>" aria-label="생일 케이크 이모티콘 추가">&#x1F382;</button>
                                            </div>
                                            <button type="submit" class="px-5 py-3 rounded-2xl bg-gray-900 text-white text-sm font-extrabold">축하 댓글 등록</button>
                                        </div>
                                    </form>
                                </section>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="mt-5 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-700">댓글 기능을 준비하지 못했습니다. 관리자에게 문의해주세요.</div>
                    <?php endif; ?>
                </div>
                <div class="px-6 py-4 border-t border-amber-100 bg-white/70 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div class="text-xs text-gray-500">생일자 팝업은 대시보드 메인에 진입할 때 표시됩니다.</div>
                    <button type="button" class="px-5 py-3 rounded-2xl bg-gray-900 text-white font-extrabold" data-dashboard-birthday-auto-close><?php echo h(cpms_dashboard_notice_label('close')); ?></button>
                </div>
            </div>
        </div>
    </div>
    <script>
    (function(){
        var birthdayModal = document.getElementById('modal-dashboardBirthdayAuto');
        function noticeModalOpen() {
            var noticeModal = document.getElementById('modal-dashboardNoticeAuto');
            return !!(noticeModal && !noticeModal.classList.contains('hidden'));
        }
        function closeBirthday() {
            if (birthdayModal) birthdayModal.classList.add('hidden');
            if (!noticeModalOpen()) document.body.classList.remove('overflow-hidden');
        }
        function openBirthday() {
            if (!birthdayModal) return;
            birthdayModal.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
        }
        function birthdayEmojiFromCode(hexCode) {
            var code = parseInt(hexCode, 16);
            if (!code || code < 0) return '';
            if (code <= 0xFFFF) return String.fromCharCode(code);
            code -= 0x10000;
            return String.fromCharCode(0xD800 + (code >> 10), 0xDC00 + (code & 0x3FF));
        }
        function birthdayHexByte(value) {
            var hex = value.toString(16).toUpperCase();
            return hex.length < 2 ? '0' + hex : hex;
        }
        function birthdayEncodeCommentForStorage(value) {
            value = String(value || '');
            var result = '';
            for (var index = 0; index < value.length; index++) {
                var high = value.charCodeAt(index);
                if (high >= 0xD800 && high <= 0xDBFF && index + 1 < value.length) {
                    var low = value.charCodeAt(index + 1);
                    if (low >= 0xDC00 && low <= 0xDFFF) {
                        var code = 0x10000 + ((high - 0xD800) << 10) + (low - 0xDC00);
                        var utf8Hex = birthdayHexByte(0xF0 | (code >> 18))
                            + birthdayHexByte(0x80 | ((code >> 12) & 0x3F))
                            + birthdayHexByte(0x80 | ((code >> 6) & 0x3F))
                            + birthdayHexByte(0x80 | (code & 0x3F));
                        result += '[[CPMS_BDAY_U8_' + utf8Hex + ']]';
                        index++;
                        continue;
                    }
                }
                result += value.charAt(index);
            }
            return result;
        }
        var birthdayCommentForms = document.querySelectorAll('form[data-birthday-comment-form="1"]');
        for (var formIndex = 0; formIndex < birthdayCommentForms.length; formIndex++) {
            birthdayCommentForms[formIndex].addEventListener('submit', function(){
                var textarea = this.querySelector('textarea[name="comment_text"]');
                if (textarea) textarea.value = birthdayEncodeCommentForStorage(textarea.value);
            });
        }
        var emojiButtons = document.querySelectorAll('[data-birthday-emoji-code][data-birthday-target]');
        for (var emojiIndex = 0; emojiIndex < emojiButtons.length; emojiIndex++) {
            emojiButtons[emojiIndex].addEventListener('click', function(){
                var textarea = document.getElementById(this.getAttribute('data-birthday-target') || '');
                var emoji = birthdayEmojiFromCode(this.getAttribute('data-birthday-emoji-code') || '');
                if (!textarea || emoji === '') return;
                var start = typeof textarea.selectionStart === 'number' ? textarea.selectionStart : textarea.value.length;
                var end = typeof textarea.selectionEnd === 'number' ? textarea.selectionEnd : textarea.value.length;
                textarea.value = textarea.value.substring(0, start) + emoji + textarea.value.substring(end);
                var nextPosition = start + emoji.length;
                if (textarea.setSelectionRange) textarea.setSelectionRange(nextPosition, nextPosition);
                textarea.focus();
            });
        }
        var closeButtons = document.querySelectorAll('[data-dashboard-birthday-auto-close]');
        for (var i = 0; i < closeButtons.length; i++) {
            closeButtons[i].addEventListener('click', function(e){
                e.preventDefault();
                closeBirthday();
            });
        }
        document.addEventListener('keydown', function(e){
            if (e.key === 'Escape' && birthdayModal && !birthdayModal.classList.contains('hidden')) closeBirthday();
        });
        setTimeout(openBirthday, 120);
    })();
    </script>
    <?php
}}
?>
