<?php
/**
 * 공무 협업툴 공통 서비스
 * - 업무/댓글/첨부/변경이력을 새 MySQL 테이블 없이 JSON 파일로 분리 저장한다.
 * - 기존 CPMS의 storage 경로, Auth 세션, 프로젝트/직원 조회 방식을 재사용한다.
 * - PHP 5.6 호환 문법만 사용한다.
 */

if (!function_exists('cpms_public_affairs_collab_config_path')) {
function cpms_public_affairs_collab_config_path() {
    return dirname(__DIR__) . '/config/public_affairs_collaboration.php';
}}

if (!function_exists('cpms_public_affairs_collab_default_settings')) {
function cpms_public_affairs_collab_default_settings() {
    $defaults = array(
        'task_types' => array(),
        'statuses' => array(),
        'priorities' => array(),
        'quick_filters' => array(),
        'card_fields' => array(),
        'default_assignee_employee_id' => 0,
    );
    $configPath = cpms_public_affairs_collab_config_path();
    if (is_file($configPath)) {
        $loaded = require $configPath;
        if (is_array($loaded)) {
            foreach ($defaults as $key => $value) {
                if (isset($loaded[$key]) && is_array($loaded[$key])) {
                    $defaults[$key] = array_values($loaded[$key]);
                } elseif ($key === 'default_assignee_employee_id' && isset($loaded[$key])) {
                    $defaults[$key] = (int)$loaded[$key];
                }
            }
        }
    }
    return $defaults;
}}

if (!function_exists('cpms_public_affairs_collab_root_dir')) {
function cpms_public_affairs_collab_root_dir() {
    return cpms_storage_root() . '/public_affairs_collab';
}}

if (!function_exists('cpms_public_affairs_collab_store_path')) {
function cpms_public_affairs_collab_store_path($name) {
    $safe = preg_replace('/[^a-z0-9_\\-]/i', '', (string)$name);
    if ($safe === '') $safe = 'store';
    return cpms_public_affairs_collab_root_dir() . '/' . $safe . '.json';
}}

if (!function_exists('cpms_public_affairs_collab_read_json')) {
function cpms_public_affairs_collab_read_json($path, $defaultValue) {
    if (!is_file($path)) return $defaultValue;
    $text = @file_get_contents($path);
    if ($text === false || trim((string)$text) === '') return $defaultValue;
    $data = @json_decode($text, true);
    return is_array($data) ? $data : $defaultValue;
}}

if (!function_exists('cpms_public_affairs_collab_write_json')) {
function cpms_public_affairs_collab_write_json($path, $data) {
    $dir = dirname($path);
    if (!cpms_ensure_dir($dir)) return false;
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (!is_string($json)) return false;
    return (@file_put_contents($path, $json, LOCK_EX) !== false);
}}

if (!function_exists('cpms_public_affairs_collab_load_store')) {
function cpms_public_affairs_collab_load_store($name) {
    $path = cpms_public_affairs_collab_store_path($name);
    $store = cpms_public_affairs_collab_read_json($path, array());
    if ((string)$name === 'attachments' && !is_file($path)) {
        // 공무 협업툴 첨부파일: 이전 구현의 files.json이 있으면 attachments.json으로 이어받는다.
        $legacyPath = cpms_public_affairs_collab_root_dir() . '/files.json';
        $legacyStore = cpms_public_affairs_collab_read_json($legacyPath, array());
        if (isset($legacyStore['items']) && is_array($legacyStore['items'])) $store = $legacyStore;
    }
    if (!isset($store['last_id'])) $store['last_id'] = 0;
    if (!isset($store['items']) || !is_array($store['items'])) $store['items'] = array();
    return $store;
}}

if (!function_exists('cpms_public_affairs_collab_save_store')) {
function cpms_public_affairs_collab_save_store($name, $store) {
    if (!is_array($store)) $store = array();
    if (!isset($store['last_id'])) $store['last_id'] = 0;
    if (!isset($store['items']) || !is_array($store['items'])) $store['items'] = array();
    return cpms_public_affairs_collab_write_json(cpms_public_affairs_collab_store_path($name), $store);
}}

if (!function_exists('cpms_public_affairs_collab_normalize_setting_list')) {
function cpms_public_affairs_collab_normalize_setting_list($value, $fallback) {
    $items = array();
    if (is_string($value)) {
        $value = preg_split('/\\r\\n|\\r|\\n/', $value);
    }
    if (is_array($value)) {
        foreach ($value as $row) {
            $row = trim((string)$row);
            if ($row === '') continue;
            if (!in_array($row, $items, true)) $items[] = $row;
        }
    }
    if (count($items) === 0 && is_array($fallback)) return array_values($fallback);
    return $items;
}}

if (!function_exists('cpms_public_affairs_collab_settings')) {
function cpms_public_affairs_collab_settings() {
    $defaults = cpms_public_affairs_collab_default_settings();
    $stored = cpms_public_affairs_collab_read_json(cpms_public_affairs_collab_store_path('settings'), array());
    $settings = array();
    foreach ($defaults as $key => $fallback) {
        if ($key === 'default_assignee_employee_id') {
            $settings[$key] = isset($stored[$key]) ? (int)$stored[$key] : (int)$fallback;
        } else {
            $settings[$key] = cpms_public_affairs_collab_normalize_setting_list(isset($stored[$key]) ? $stored[$key] : array(), $fallback);
        }
    }
    return $settings;
}}

if (!function_exists('cpms_public_affairs_collab_save_settings')) {
function cpms_public_affairs_collab_save_settings($settings) {
    $defaults = cpms_public_affairs_collab_default_settings();
    $data = array();
    foreach ($defaults as $key => $fallback) {
        if ($key === 'default_assignee_employee_id') {
            $data[$key] = isset($settings[$key]) ? (int)$settings[$key] : 0;
        } else {
            $data[$key] = cpms_public_affairs_collab_normalize_setting_list(isset($settings[$key]) ? $settings[$key] : array(), $fallback);
        }
    }
    $data['updated_at'] = date('Y-m-d H:i:s');
    return cpms_public_affairs_collab_write_json(cpms_public_affairs_collab_store_path('settings'), $data);
}}

if (!function_exists('cpms_public_affairs_collab_normalize_dept')) {
function cpms_public_affairs_collab_normalize_dept($dept) {
    $dept = trim((string)$dept);
    $map = array(
        '공무부' => '공무',
        '공무팀' => '공무',
        '관리부' => '관리',
        '관리팀' => '관리',
        '공사부' => '공사',
        '공사팀' => '공사',
        '안전부' => '안전',
        '안전팀' => '안전',
        '안전/보건' => '보건',
        '안전보건' => '보건',
        '보건부' => '보건',
        '보건팀' => '보건',
        '품질부' => '품질',
        '품질팀' => '품질',
    );
    if (isset($map[$dept])) return $map[$dept];
    foreach (array('공무', '관리', '공사', '안전', '품질', '보건') as $keyword) {
        if (strpos($dept, $keyword) !== false) return $keyword;
    }
    return $dept;
}}

if (!function_exists('cpms_public_affairs_collab_table_exists')) {
function cpms_public_affairs_collab_table_exists($pdo, $tableName) {
    if (!$pdo) return false;
    $tableName = trim((string)$tableName);
    if ($tableName === '') return false;
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE :table_name");
        $st->execute(array(':table_name' => $tableName));
        return (bool)$st->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_public_affairs_collab_column_expr')) {
function cpms_public_affairs_collab_column_expr($pdo, $tableName, $columnName, $fallbackExpr) {
    if (!$pdo) return $fallbackExpr;
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `" . str_replace('`', '', $tableName) . "` LIKE :column_name");
        $st->execute(array(':column_name' => $columnName));
        if ($st->fetch(PDO::FETCH_ASSOC)) return '`' . str_replace('`', '', $columnName) . '`';
    } catch (Exception $e) {
    }
    return $fallbackExpr;
}}

if (!function_exists('cpms_public_affairs_collab_fetch_projects')) {
function cpms_public_affairs_collab_fetch_projects($pdo) {
    $rows = array();
    if (!$pdo || !cpms_public_affairs_collab_table_exists($pdo, 'cpms_projects')) return $rows;
    try {
        $clientCol = cpms_public_affairs_collab_column_expr($pdo, 'cpms_projects', 'client', "'' AS client");
        $statusCol = cpms_public_affairs_collab_column_expr($pdo, 'cpms_projects', 'status', "'' AS status");
        $st = $pdo->query("SELECT id, name, " . $clientCol . ", " . $statusCol . " FROM cpms_projects ORDER BY id DESC");
        $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
    } catch (Exception $e) {
        $rows = array();
    }
    return is_array($rows) ? $rows : array();
}}

if (!function_exists('cpms_public_affairs_collab_fetch_employees')) {
function cpms_public_affairs_collab_fetch_employees($pdo) {
    $rows = array();
    if (!$pdo || !cpms_public_affairs_collab_table_exists($pdo, 'employees')) return $rows;
    try {
        $departmentCol = cpms_public_affairs_collab_column_expr($pdo, 'employees', 'department', "'' AS department");
        $positionCol = cpms_public_affairs_collab_column_expr($pdo, 'employees', 'position', "'' AS position");
        $roleCol = cpms_public_affairs_collab_column_expr($pdo, 'employees', 'role', "'employee' AS role");
        $emailCol = cpms_public_affairs_collab_column_expr($pdo, 'employees', 'email', "'' AS email");
        $st = $pdo->query("SELECT id, name, " . $emailCol . ", " . $departmentCol . ", " . $positionCol . ", " . $roleCol . " FROM employees WHERE is_active = 1 ORDER BY department ASC, position ASC, name ASC, id ASC");
        $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
    } catch (Exception $e) {
        $rows = array();
    }
    if (!is_array($rows)) $rows = array();
    foreach ($rows as $i => $row) {
        $rows[$i]['department'] = cpms_public_affairs_collab_normalize_dept(isset($row['department']) ? $row['department'] : '');
    }
    return $rows;
}}

if (!function_exists('cpms_public_affairs_collab_current_employee')) {
function cpms_public_affairs_collab_current_employee($pdo) {
    $userName = class_exists('App\\Core\\Auth') ? (string)\App\Core\Auth::userName() : '';
    $userEmail = class_exists('App\\Core\\Auth') ? (string)\App\Core\Auth::userEmail() : '';
    $result = array(
        'id' => 0,
        'name' => $userName,
        'email' => $userEmail,
        'department' => class_exists('App\\Core\\Auth') ? cpms_public_affairs_collab_normalize_dept(\App\Core\Auth::userDepartment()) : '',
        'position' => class_exists('App\\Core\\Auth') ? (string)\App\Core\Auth::userPosition() : '',
        'role' => class_exists('App\\Core\\Auth') ? (string)\App\Core\Auth::userRole() : '',
    );
    if (!$pdo || $userEmail === '' || !cpms_public_affairs_collab_table_exists($pdo, 'employees')) return $result;
    try {
        $departmentCol = cpms_public_affairs_collab_column_expr($pdo, 'employees', 'department', "'' AS department");
        $positionCol = cpms_public_affairs_collab_column_expr($pdo, 'employees', 'position', "'' AS position");
        $roleCol = cpms_public_affairs_collab_column_expr($pdo, 'employees', 'role', "'employee' AS role");
        $st = $pdo->prepare("SELECT id, name, email, " . $departmentCol . ", " . $positionCol . ", " . $roleCol . " FROM employees WHERE LOWER(TRIM(email)) = LOWER(TRIM(:email)) LIMIT 1");
        $st->execute(array(':email' => $userEmail));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $result['id'] = isset($row['id']) ? (int)$row['id'] : 0;
            $result['name'] = isset($row['name']) && trim((string)$row['name']) !== '' ? (string)$row['name'] : $result['name'];
            $result['email'] = isset($row['email']) ? (string)$row['email'] : $result['email'];
            $result['department'] = cpms_public_affairs_collab_normalize_dept(isset($row['department']) ? $row['department'] : $result['department']);
            $result['position'] = isset($row['position']) ? (string)$row['position'] : $result['position'];
            $result['role'] = isset($row['role']) ? (string)$row['role'] : $result['role'];
        }
    } catch (Exception $e) {
    }
    return $result;
}}

if (!function_exists('cpms_public_affairs_collab_is_admin_user')) {
function cpms_public_affairs_collab_is_admin_user() {
    if (!class_exists('App\\Core\\Auth')) return false;
    if (\App\Core\Auth::isMaster()) return true;
    if (\App\Core\Auth::userRole() === 'executive') return true;
    $dept = cpms_public_affairs_collab_normalize_dept(\App\Core\Auth::userDepartment());
    return ($dept === '공무' || $dept === '관리');
}}

if (!function_exists('cpms_public_affairs_collab_can_create_task')) {
function cpms_public_affairs_collab_can_create_task() {
    if (!class_exists('App\\Core\\Auth') || !\App\Core\Auth::check()) return false;
    return true;
}}

if (!function_exists('cpms_public_affairs_collab_project_name')) {
function cpms_public_affairs_collab_project_name($projects, $projectId) {
    $projectId = (int)$projectId;
    if (!is_array($projects)) return '';
    foreach ($projects as $project) {
        if (isset($project['id']) && (int)$project['id'] === $projectId) {
            return isset($project['name']) ? (string)$project['name'] : '';
        }
    }
    return '';
}}

if (!function_exists('cpms_public_affairs_collab_employee_by_id')) {
function cpms_public_affairs_collab_employee_by_id($employees, $employeeId) {
    $employeeId = (int)$employeeId;
    if (!is_array($employees) || $employeeId <= 0) return null;
    foreach ($employees as $employee) {
        if (isset($employee['id']) && (int)$employee['id'] === $employeeId) return $employee;
    }
    return null;
}}

if (!function_exists('cpms_public_affairs_collab_employee_name')) {
function cpms_public_affairs_collab_employee_name($employee) {
    if (!is_array($employee)) return '';
    $name = isset($employee['name']) ? trim((string)$employee['name']) : '';
    return $name;
}}

if (!function_exists('cpms_public_affairs_collab_employee_email')) {
function cpms_public_affairs_collab_employee_email($employee) {
    if (!is_array($employee)) return '';
    return isset($employee['email']) ? trim((string)$employee['email']) : '';
}}

if (!function_exists('cpms_public_affairs_collab_employee_ids_from_value')) {
function cpms_public_affairs_collab_employee_ids_from_value($value) {
    $ids = array();
    if (!is_array($value)) $value = array($value);
    foreach ($value as $id) {
        $id = (int)$id;
        if ($id > 0 && !in_array($id, $ids, true)) $ids[] = $id;
    }
    return $ids;
}}

if (!function_exists('cpms_public_affairs_collab_task_ref_names')) {
function cpms_public_affairs_collab_task_ref_names($task) {
    if (!is_array($task)) return '';
    if (isset($task['reference_names']) && is_array($task['reference_names'])) {
        return implode(', ', $task['reference_names']);
    }
    return '';
}}

if (!function_exists('cpms_public_affairs_collab_task_no')) {
function cpms_public_affairs_collab_task_no($task) {
    // 공무 협업툴 업무카드: 사용자가 보는 업무번호(PA-0001 형식)를 만든다.
    if (is_array($task) && isset($task['task_no']) && trim((string)$task['task_no']) !== '') {
        return trim((string)$task['task_no']);
    }
    $taskId = is_array($task) && isset($task['id']) ? (int)$task['id'] : 0;
    return 'PA-' . str_pad((string)$taskId, 4, '0', STR_PAD_LEFT);
}}

if (!function_exists('cpms_public_affairs_collab_normalize_task')) {
function cpms_public_affairs_collab_normalize_task($task) {
    // 공무 협업툴 업무카드: 기존 JSON 데이터에 새 필드가 없어도 화면이 깨지지 않게 보정한다.
    if (!is_array($task)) $task = array();
    if (!isset($task['id'])) $task['id'] = 0;
    if (!isset($task['task_no']) || trim((string)$task['task_no']) === '') {
        $task['task_no'] = cpms_public_affairs_collab_task_no($task);
    }
    if (!isset($task['contract_impact']) || trim((string)$task['contract_impact']) === '') {
        $task['contract_impact'] = '없음';
    }
    if (!isset($task['schedule_impact']) || trim((string)$task['schedule_impact']) === '') {
        $task['schedule_impact'] = '없음';
    }
    if (!isset($task['completed_at'])) $task['completed_at'] = '';
    if (!isset($task['rejected_at'])) $task['rejected_at'] = '';
    if (!isset($task['held_at'])) $task['held_at'] = '';
    return $task;
}}

if (!function_exists('cpms_public_affairs_collab_list_tasks')) {
function cpms_public_affairs_collab_list_tasks() {
    $store = cpms_public_affairs_collab_load_store('tasks');
    $items = isset($store['items']) && is_array($store['items']) ? array_values($store['items']) : array();
    for ($i = 0; $i < count($items); $i++) {
        $items[$i] = cpms_public_affairs_collab_normalize_task($items[$i]);
    }
    usort($items, 'cpms_public_affairs_collab_sort_recent');
    return $items;
}}

if (!function_exists('cpms_public_affairs_collab_sort_recent')) {
function cpms_public_affairs_collab_sort_recent($a, $b) {
    $aId = isset($a['id']) ? (int)$a['id'] : 0;
    $bId = isset($b['id']) ? (int)$b['id'] : 0;
    if ($aId === $bId) return 0;
    return ($aId > $bId) ? -1 : 1;
}}

if (!function_exists('cpms_public_affairs_collab_find_task')) {
function cpms_public_affairs_collab_find_task($taskId) {
    $taskId = (int)$taskId;
    if ($taskId <= 0) return null;
    $store = cpms_public_affairs_collab_load_store('tasks');
    if (!isset($store['items']) || !is_array($store['items'])) return null;
    foreach ($store['items'] as $task) {
        if (is_array($task) && isset($task['id']) && (int)$task['id'] === $taskId) return cpms_public_affairs_collab_normalize_task($task);
    }
    return null;
}}

if (!function_exists('cpms_public_affairs_collab_actor_label')) {
function cpms_public_affairs_collab_actor_label($actor) {
    if (!is_array($actor)) return '';
    $name = isset($actor['name']) ? trim((string)$actor['name']) : '';
    if ($name !== '') return $name;
    return isset($actor['email']) ? trim((string)$actor['email']) : '';
}}

if (!function_exists('cpms_public_affairs_collab_add_history')) {
function cpms_public_affairs_collab_add_history($taskId, $projectId, $action, $field, $oldValue, $newValue, $message, $actor) {
    $store = cpms_public_affairs_collab_load_store('history');
    $nextId = (int)$store['last_id'] + 1;
    $store['last_id'] = $nextId;
    $store['items'][] = array(
        'id' => $nextId,
        'task_id' => (int)$taskId,
        'project_id' => (int)$projectId,
        'action' => (string)$action,
        'field' => (string)$field,
        'old_value' => is_array($oldValue) ? implode(', ', $oldValue) : (string)$oldValue,
        'new_value' => is_array($newValue) ? implode(', ', $newValue) : (string)$newValue,
        'message' => (string)$message,
        'actor_id' => is_array($actor) && isset($actor['id']) ? (int)$actor['id'] : 0,
        'actor_name' => cpms_public_affairs_collab_actor_label($actor),
        'created_at' => date('Y-m-d H:i:s'),
    );
    return cpms_public_affairs_collab_save_store('history', $store);
}}

if (!function_exists('cpms_public_affairs_collab_is_delayed')) {
function cpms_public_affairs_collab_is_delayed($task) {
    if (!is_array($task)) return false;
    $status = isset($task['status']) ? (string)$task['status'] : '';
    if ($status === '완료') return false;
    $dueDate = isset($task['due_date']) ? trim((string)$task['due_date']) : '';
    if ($dueDate === '' || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $dueDate)) return false;
    return (strcmp($dueDate, date('Y-m-d')) < 0);
}}

if (!function_exists('cpms_public_affairs_collab_is_due_today')) {
function cpms_public_affairs_collab_is_due_today($task) {
    if (!is_array($task)) return false;
    $status = isset($task['status']) ? (string)$task['status'] : '';
    if ($status === '완료') return false;
    $dueDate = isset($task['due_date']) ? trim((string)$task['due_date']) : '';
    return ($dueDate !== '' && $dueDate === date('Y-m-d'));
}}

if (!function_exists('cpms_public_affairs_collab_user_matches_task')) {
function cpms_public_affairs_collab_user_matches_task($task, $employee) {
    if (!is_array($task) || !is_array($employee)) return false;
    $employeeId = isset($employee['id']) ? (int)$employee['id'] : 0;
    $employeeEmail = isset($employee['email']) ? strtolower(trim((string)$employee['email'])) : '';
    $idFields = array('creator_employee_id', 'requester_employee_id', 'assignee_employee_id');
    foreach ($idFields as $field) {
        if ($employeeId > 0 && isset($task[$field]) && (int)$task[$field] === $employeeId) return true;
    }
    $emailFields = array('creator_email', 'requester_email', 'assignee_email');
    foreach ($emailFields as $field) {
        $value = isset($task[$field]) ? strtolower(trim((string)$task[$field])) : '';
        if ($employeeEmail !== '' && $value !== '' && $value === $employeeEmail) return true;
    }
    if (isset($task['reference_employee_ids']) && is_array($task['reference_employee_ids'])) {
        foreach ($task['reference_employee_ids'] as $id) {
            if ($employeeId > 0 && (int)$id === $employeeId) return true;
        }
    }
    if (isset($task['reference_emails']) && is_array($task['reference_emails'])) {
        foreach ($task['reference_emails'] as $email) {
            $email = strtolower(trim((string)$email));
            if ($employeeEmail !== '' && $email !== '' && $email === $employeeEmail) return true;
        }
    }
    return false;
}}

if (!function_exists('cpms_public_affairs_collab_apply_quick_filter')) {
function cpms_public_affairs_collab_apply_quick_filter($tasks, $quickFilter, $employee) {
    // 공무 협업툴 보드: 좌측 메뉴와 빠른 필터에서 쓰는 카드 필터링.
    if (!is_array($tasks)) return array();
    $quickFilter = trim((string)$quickFilter);
    if ($quickFilter === '' || $quickFilter === 'all') return $tasks;
    $result = array();
    foreach ($tasks as $task) {
        if (!is_array($task)) continue;
        $status = isset($task['status']) ? (string)$task['status'] : '';
        $priority = isset($task['priority']) ? (string)$task['priority'] : '';
        $contractImpact = isset($task['contract_impact']) ? (string)$task['contract_impact'] : '없음';
        $scheduleImpact = isset($task['schedule_impact']) ? (string)$task['schedule_impact'] : '없음';
        $matched = false;
        if ($quickFilter === 'mine') {
            $employeeId = is_array($employee) && isset($employee['id']) ? (int)$employee['id'] : 0;
            $employeeEmail = is_array($employee) && isset($employee['email']) ? strtolower(trim((string)$employee['email'])) : '';
            if ($employeeId > 0 && isset($task['assignee_employee_id']) && (int)$task['assignee_employee_id'] === $employeeId) $matched = true;
            if (!$matched && $employeeEmail !== '' && isset($task['assignee_email']) && strtolower(trim((string)$task['assignee_email'])) === $employeeEmail) $matched = true;
        }
        if ($quickFilter === 'today') $matched = cpms_public_affairs_collab_is_due_today($task);
        if ($quickFilter === 'delayed') $matched = cpms_public_affairs_collab_is_delayed($task);
        if ($quickFilter === 'urgent') $matched = ($priority === '긴급');
        if ($quickFilter === 'approval') $matched = ($status === '결재대기');
        if ($quickFilter === 'contract') $matched = ($contractImpact === '있음' || $contractImpact === '확인필요');
        if ($quickFilter === 'schedule') $matched = ($scheduleImpact === '있음' || $scheduleImpact === '확인필요');
        if ($quickFilter === 'hide_done') $matched = ($status !== '완료');
        if ($quickFilter === 'pending') $matched = ($status === '요청' || $status === '접수');
        if ($quickFilter === 'done') $matched = ($status === '완료');
        if ($matched) $result[] = $task;
    }
    return $result;
}}

if (!function_exists('cpms_public_affairs_collab_user_can_view_task')) {
function cpms_public_affairs_collab_user_can_view_task($task, $employee) {
    if (cpms_public_affairs_collab_is_admin_user()) return true;
    return (class_exists('App\\Core\\Auth') && \App\Core\Auth::check());
}}

if (!function_exists('cpms_public_affairs_collab_user_can_edit_task')) {
function cpms_public_affairs_collab_user_can_edit_task($task, $employee) {
    if (cpms_public_affairs_collab_is_admin_user()) return true;
    if (!is_array($task) || !is_array($employee)) return false;
    $employeeId = isset($employee['id']) ? (int)$employee['id'] : 0;
    if ($employeeId > 0 && isset($task['creator_employee_id']) && (int)$task['creator_employee_id'] === $employeeId) return true;
    if ($employeeId > 0 && isset($task['assignee_employee_id']) && (int)$task['assignee_employee_id'] === $employeeId) return true;
    $employeeEmail = isset($employee['email']) ? strtolower(trim((string)$employee['email'])) : '';
    if ($employeeEmail !== '' && isset($task['creator_email']) && strtolower(trim((string)$task['creator_email'])) === $employeeEmail) return true;
    if ($employeeEmail !== '' && isset($task['assignee_email']) && strtolower(trim((string)$task['assignee_email'])) === $employeeEmail) return true;
    return false;
}}

if (!function_exists('cpms_public_affairs_collab_visible_tasks')) {
function cpms_public_affairs_collab_visible_tasks($tasks, $employee) {
    $visible = array();
    if (!is_array($tasks)) return $visible;
    foreach ($tasks as $task) {
        if (!is_array($task)) continue;
        if (cpms_public_affairs_collab_user_can_view_task($task, $employee)) $visible[] = $task;
    }
    return $visible;
}}

if (!function_exists('cpms_public_affairs_collab_user_can_access_module')) {
function cpms_public_affairs_collab_user_can_access_module($employee) {
    return (class_exists('App\\Core\\Auth') && \App\Core\Auth::check());
}}

if (!function_exists('cpms_public_affairs_collab_clean_text')) {
function cpms_public_affairs_collab_clean_text($value, $maxLength) {
    $value = trim((string)$value);
    $value = str_replace("\0", '', $value);
    if ($maxLength > 0 && function_exists('mb_substr')) return mb_substr($value, 0, $maxLength, 'UTF-8');
    if ($maxLength > 0) return substr($value, 0, $maxLength);
    return $value;
}}

if (!function_exists('cpms_public_affairs_collab_choice')) {
function cpms_public_affairs_collab_choice($value, $allowed, $fallback) {
    $value = trim((string)$value);
    if (is_array($allowed) && in_array($value, $allowed, true)) return $value;
    return $fallback;
}}

if (!function_exists('cpms_public_affairs_collab_date')) {
function cpms_public_affairs_collab_date($value) {
    $value = trim((string)$value);
    if ($value !== '' && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $value)) return $value;
    return '';
}}

if (!function_exists('cpms_public_affairs_collab_time')) {
function cpms_public_affairs_collab_time($value) {
    $value = trim((string)$value);
    if ($value !== '' && preg_match('/^\\d{2}:\\d{2}$/', $value)) return $value;
    return '';
}}

if (!function_exists('cpms_public_affairs_collab_amount')) {
function cpms_public_affairs_collab_amount($value) {
    $value = trim((string)$value);
    if ($value === '') return '';
    return preg_replace('/[^0-9.\\-]/', '', $value);
}}

if (!function_exists('cpms_public_affairs_collab_create_task')) {
function cpms_public_affairs_collab_create_task($pdo, $post, $files, $actor, $projects, $employees) {
    $settings = cpms_public_affairs_collab_settings();
    $projectId = isset($post['project_id']) ? (int)$post['project_id'] : 0;
    $projectName = cpms_public_affairs_collab_clean_text(isset($post['project_name']) ? $post['project_name'] : '', 200);
    if ($projectName === '') $projectName = cpms_public_affairs_collab_clean_text(isset($post['site_name']) ? $post['site_name'] : '', 200);
    if ($projectName === '' && $projectId > 0) $projectName = cpms_public_affairs_collab_project_name($projects, $projectId);
    if ($projectName === '') return array('ok' => false, 'message' => '현장명/프로젝트명을 입력해주세요.', 'task_id' => 0);

    $title = cpms_public_affairs_collab_clean_text(isset($post['title']) ? $post['title'] : '', 200);
    if ($title === '') return array('ok' => false, 'message' => '업무 제목을 입력해주세요.', 'task_id' => 0);

    $requesterId = isset($post['requester_employee_id']) ? (int)$post['requester_employee_id'] : 0;
    if ($requesterId <= 0 && isset($actor['id'])) $requesterId = (int)$actor['id'];
    $requester = cpms_public_affairs_collab_employee_by_id($employees, $requesterId);
    if (!is_array($requester)) $requester = $actor;

    $assigneeId = isset($post['assignee_employee_id']) ? (int)$post['assignee_employee_id'] : 0;
    if ($assigneeId <= 0 && isset($settings['default_assignee_employee_id'])) $assigneeId = (int)$settings['default_assignee_employee_id'];
    $assignee = cpms_public_affairs_collab_employee_by_id($employees, $assigneeId);
    if (!is_array($assignee)) return array('ok' => false, 'message' => '담당자를 선택해주세요.', 'task_id' => 0);

    $refIds = cpms_public_affairs_collab_employee_ids_from_value(isset($post['reference_employee_ids']) ? $post['reference_employee_ids'] : array());
    $refNames = array();
    $refEmails = array();
    foreach ($refIds as $refId) {
        $refEmployee = cpms_public_affairs_collab_employee_by_id($employees, $refId);
        if (!is_array($refEmployee)) continue;
        $refName = cpms_public_affairs_collab_employee_name($refEmployee);
        if ($refName !== '') $refNames[] = $refName;
        $refEmail = cpms_public_affairs_collab_employee_email($refEmployee);
        if ($refEmail !== '') $refEmails[] = $refEmail;
    }

    $store = cpms_public_affairs_collab_load_store('tasks');
    $taskId = (int)$store['last_id'] + 1;
    $store['last_id'] = $taskId;
    $now = date('Y-m-d H:i:s');
    $task = array(
        'id' => $taskId,
        'task_no' => 'PA-' . str_pad((string)$taskId, 4, '0', STR_PAD_LEFT),
        'project_id' => $projectId,
        'project_name' => $projectName,
        'task_type' => cpms_public_affairs_collab_choice(isset($post['task_type']) ? $post['task_type'] : '', $settings['task_types'], isset($settings['task_types'][0]) ? $settings['task_types'][0] : '기타'),
        'title' => $title,
        'content' => cpms_public_affairs_collab_clean_text(isset($post['content']) ? $post['content'] : '', 0),
        'creator_employee_id' => isset($actor['id']) ? (int)$actor['id'] : 0,
        'creator_name' => cpms_public_affairs_collab_actor_label($actor),
        'creator_email' => isset($actor['email']) ? (string)$actor['email'] : '',
        'requester_employee_id' => isset($requester['id']) ? (int)$requester['id'] : 0,
        'requester_name' => cpms_public_affairs_collab_employee_name($requester),
        'requester_email' => cpms_public_affairs_collab_employee_email($requester),
        'assignee_employee_id' => isset($assignee['id']) ? (int)$assignee['id'] : 0,
        'assignee_name' => cpms_public_affairs_collab_employee_name($assignee),
        'assignee_email' => cpms_public_affairs_collab_employee_email($assignee),
        'reference_employee_ids' => $refIds,
        'reference_names' => $refNames,
        'reference_emails' => $refEmails,
        'priority' => cpms_public_affairs_collab_choice(isset($post['priority']) ? $post['priority'] : '', $settings['priorities'], '보통'),
        'status' => cpms_public_affairs_collab_choice(isset($post['status']) ? $post['status'] : '', $settings['statuses'], isset($settings['statuses'][0]) ? $settings['statuses'][0] : '요청'),
        'due_date' => cpms_public_affairs_collab_date(isset($post['due_date']) ? $post['due_date'] : ''),
        'due_time' => cpms_public_affairs_collab_time(isset($post['due_time']) ? $post['due_time'] : ''),
        'related_amount' => cpms_public_affairs_collab_amount(isset($post['related_amount']) ? $post['related_amount'] : ''),
        'contract_impact' => cpms_public_affairs_collab_clean_text(isset($post['contract_impact']) ? $post['contract_impact'] : '없음', 20),
        'schedule_impact' => cpms_public_affairs_collab_clean_text(isset($post['schedule_impact']) ? $post['schedule_impact'] : '없음', 20),
        'document_link' => cpms_public_affairs_collab_clean_text(isset($post['document_link']) ? $post['document_link'] : '', 500),
        'created_at' => $now,
        'updated_at' => $now,
        'completed_at' => '',
        'rejected_at' => '',
        'held_at' => '',
    );
    $store['items'][] = $task;
    if (!cpms_public_affairs_collab_save_store('tasks', $store)) {
        return array('ok' => false, 'message' => '업무 저장에 실패했습니다. storage 쓰기 권한을 확인해주세요.', 'task_id' => 0);
    }
    cpms_public_affairs_collab_add_history($taskId, $projectId, '업무 생성', 'task', '', $title, '공무 협업툴 업무가 생성되었습니다.', $actor);
    cpms_public_affairs_collab_save_uploaded_files($task, isset($files['attachments']) ? $files['attachments'] : null, $actor);
    return array('ok' => true, 'message' => '업무가 등록되었습니다.', 'task_id' => $taskId);
}}

if (!function_exists('cpms_public_affairs_collab_update_task')) {
function cpms_public_affairs_collab_update_task($taskId, $post, $actor, $projects, $employees) {
    $taskId = (int)$taskId;
    $settings = cpms_public_affairs_collab_settings();
    $store = cpms_public_affairs_collab_load_store('tasks');
    $foundIndex = -1;
    $task = null;
    for ($i = 0; $i < count($store['items']); $i++) {
        if (isset($store['items'][$i]['id']) && (int)$store['items'][$i]['id'] === $taskId) {
            $foundIndex = $i;
            $task = cpms_public_affairs_collab_normalize_task($store['items'][$i]);
            break;
        }
    }
    if (!is_array($task)) return array('ok' => false, 'message' => '업무를 찾을 수 없습니다.');

    $changes = array();
    $now = date('Y-m-d H:i:s');
    if (isset($post['title'])) {
        $new = cpms_public_affairs_collab_clean_text($post['title'], 200);
        if ($new !== '' && $new !== (string)$task['title']) {
            $changes[] = array('제목 변경', 'title', $task['title'], $new);
            $task['title'] = $new;
        }
    }
    if (isset($post['content'])) {
        $new = cpms_public_affairs_collab_clean_text($post['content'], 0);
        if ($new !== (string)$task['content']) {
            $changes[] = array('내용 변경', 'content', $task['content'], $new);
            $task['content'] = $new;
        }
    }
    if (isset($post['task_type'])) {
        $new = cpms_public_affairs_collab_choice($post['task_type'], $settings['task_types'], $task['task_type']);
        if ($new !== (string)$task['task_type']) {
            $changes[] = array('업무유형 변경', 'task_type', $task['task_type'], $new);
            $task['task_type'] = $new;
        }
    }
    if (isset($post['project_name'])) {
        $new = cpms_public_affairs_collab_clean_text($post['project_name'], 200);
        if ($new !== '' && $new !== (string)$task['project_name']) {
            $changes[] = array('현장명/프로젝트명 변경', 'project_name', $task['project_name'], $new);
            $task['project_name'] = $new;
        }
    }
    if (isset($post['assignee_employee_id'])) {
        $assignee = cpms_public_affairs_collab_employee_by_id($employees, (int)$post['assignee_employee_id']);
        if (is_array($assignee)) {
            $newId = isset($assignee['id']) ? (int)$assignee['id'] : 0;
            if ($newId > 0 && (!isset($task['assignee_employee_id']) || (int)$task['assignee_employee_id'] !== $newId)) {
                $changes[] = array('담당자 변경', 'assignee_employee_id', isset($task['assignee_name']) ? $task['assignee_name'] : '', cpms_public_affairs_collab_employee_name($assignee));
                $task['assignee_employee_id'] = $newId;
                $task['assignee_name'] = cpms_public_affairs_collab_employee_name($assignee);
                $task['assignee_email'] = cpms_public_affairs_collab_employee_email($assignee);
            }
        }
    }
    if (isset($post['reference_employee_ids_present'])) {
        $refIds = cpms_public_affairs_collab_employee_ids_from_value(isset($post['reference_employee_ids']) ? $post['reference_employee_ids'] : array());
        $oldNames = isset($task['reference_names']) && is_array($task['reference_names']) ? $task['reference_names'] : array();
        $refNames = array();
        $refEmails = array();
        foreach ($refIds as $refId) {
            $refEmployee = cpms_public_affairs_collab_employee_by_id($employees, $refId);
            if (!is_array($refEmployee)) continue;
            $refName = cpms_public_affairs_collab_employee_name($refEmployee);
            if ($refName !== '') $refNames[] = $refName;
            $refEmail = cpms_public_affairs_collab_employee_email($refEmployee);
            if ($refEmail !== '') $refEmails[] = $refEmail;
        }
        $oldJoined = implode(', ', $oldNames);
        $newJoined = implode(', ', $refNames);
        if ($oldJoined !== $newJoined) {
            $changes[] = array('참조자 변경', 'reference_employee_ids', $oldJoined, $newJoined);
            $task['reference_employee_ids'] = $refIds;
            $task['reference_names'] = $refNames;
            $task['reference_emails'] = $refEmails;
        }
    }
    if (isset($post['status'])) {
        $new = cpms_public_affairs_collab_choice($post['status'], $settings['statuses'], $task['status']);
        if ($new !== (string)$task['status']) {
            $oldStatus = $task['status'];
            $changes[] = array('상태 변경', 'status', $oldStatus, $new);
            $task['status'] = $new;
            if ($new === '완료') $task['completed_at'] = $now;
            if ($new === '반려') $task['rejected_at'] = $now;
            if ($new === '보류') $task['held_at'] = $now;
            if ($new === '완료') $changes[] = array('완료 처리', 'status_action', $oldStatus, $new);
            if ($new === '반려') $changes[] = array('반려 처리', 'status_action', $oldStatus, $new);
            if ($new === '보류') $changes[] = array('보류 처리', 'status_action', $oldStatus, $new);
        }
    }
    if (isset($post['priority'])) {
        $new = cpms_public_affairs_collab_choice($post['priority'], $settings['priorities'], $task['priority']);
        if ($new !== (string)$task['priority']) {
            $changes[] = array('우선순위 변경', 'priority', $task['priority'], $new);
            $task['priority'] = $new;
        }
    }
    if (isset($post['due_date'])) {
        $newDate = cpms_public_affairs_collab_date($post['due_date']);
        if ($newDate !== (string)$task['due_date']) {
            $changes[] = array('마감일 변경', 'due_date', $task['due_date'], $newDate);
            $task['due_date'] = $newDate;
        }
    }
    if (isset($post['due_time'])) {
        $newTime = cpms_public_affairs_collab_time($post['due_time']);
        if ($newTime !== (string)$task['due_time']) {
            $changes[] = array('마감일 변경', 'due_time', $task['due_time'], $newTime);
            $task['due_time'] = $newTime;
        }
    }
    if (isset($post['related_amount'])) {
        $new = cpms_public_affairs_collab_amount($post['related_amount']);
        if ($new !== (string)$task['related_amount']) {
            $changes[] = array('관련 금액 변경', 'related_amount', $task['related_amount'], $new);
            $task['related_amount'] = $new;
        }
    }
    if (isset($post['contract_impact'])) {
        $new = cpms_public_affairs_collab_clean_text($post['contract_impact'], 20);
        $old = isset($task['contract_impact']) ? (string)$task['contract_impact'] : '없음';
        if ($new !== $old) {
            $changes[] = array('계약 영향 변경', 'contract_impact', $old, $new);
            $task['contract_impact'] = $new;
        }
    }
    if (isset($post['schedule_impact'])) {
        $new = cpms_public_affairs_collab_clean_text($post['schedule_impact'], 20);
        $old = isset($task['schedule_impact']) ? (string)$task['schedule_impact'] : '없음';
        if ($new !== $old) {
            $changes[] = array('공기 영향 변경', 'schedule_impact', $old, $new);
            $task['schedule_impact'] = $new;
        }
    }
    if (isset($post['document_link'])) {
        $new = cpms_public_affairs_collab_clean_text($post['document_link'], 500);
        if ($new !== (string)$task['document_link']) {
            $changes[] = array('관련 문서 링크 변경', 'document_link', $task['document_link'], $new);
            $task['document_link'] = $new;
        }
    }

    if (count($changes) === 0) return array('ok' => true, 'message' => '변경된 내용이 없습니다.');
    $task['updated_at'] = $now;
    $store['items'][$foundIndex] = $task;
    if (!cpms_public_affairs_collab_save_store('tasks', $store)) return array('ok' => false, 'message' => '업무 수정 저장에 실패했습니다.');
    foreach ($changes as $change) {
        cpms_public_affairs_collab_add_history($taskId, isset($task['project_id']) ? (int)$task['project_id'] : 0, $change[0], $change[1], $change[2], $change[3], $change[0] . '이 기록되었습니다.', $actor);
    }
    return array('ok' => true, 'message' => '업무가 수정되었습니다.');
}}

if (!function_exists('cpms_public_affairs_collab_comments')) {
function cpms_public_affairs_collab_comments($taskId) {
    $taskId = (int)$taskId;
    $store = cpms_public_affairs_collab_load_store('comments');
    $rows = array();
    foreach ($store['items'] as $row) {
        if (is_array($row) && isset($row['task_id']) && (int)$row['task_id'] === $taskId) $rows[] = $row;
    }
    usort($rows, 'cpms_public_affairs_collab_sort_asc');
    return $rows;
}}

if (!function_exists('cpms_public_affairs_collab_sort_asc')) {
function cpms_public_affairs_collab_sort_asc($a, $b) {
    $aId = isset($a['id']) ? (int)$a['id'] : 0;
    $bId = isset($b['id']) ? (int)$b['id'] : 0;
    if ($aId === $bId) return 0;
    return ($aId < $bId) ? -1 : 1;
}}

if (!function_exists('cpms_public_affairs_collab_add_comment')) {
function cpms_public_affairs_collab_add_comment($task, $content, $actor) {
    if (!is_array($task)) return array('ok' => false, 'message' => '업무를 찾을 수 없습니다.');
    $content = cpms_public_affairs_collab_clean_text($content, 0);
    if ($content === '') return array('ok' => false, 'message' => '댓글 내용을 입력해주세요.');
    $store = cpms_public_affairs_collab_load_store('comments');
    $nextId = (int)$store['last_id'] + 1;
    $store['last_id'] = $nextId;
    $store['items'][] = array(
        'id' => $nextId,
        'task_id' => (int)$task['id'],
        'project_id' => isset($task['project_id']) ? (int)$task['project_id'] : 0,
        'content' => $content,
        'created_by_id' => is_array($actor) && isset($actor['id']) ? (int)$actor['id'] : 0,
        'created_by_name' => cpms_public_affairs_collab_actor_label($actor),
        'created_at' => date('Y-m-d H:i:s'),
    );
    if (!cpms_public_affairs_collab_save_store('comments', $store)) return array('ok' => false, 'message' => '댓글 저장에 실패했습니다.');
    cpms_public_affairs_collab_add_history((int)$task['id'], isset($task['project_id']) ? (int)$task['project_id'] : 0, '댓글 등록', 'comment', '', $content, '댓글이 등록되었습니다.', $actor);
    return array('ok' => true, 'message' => '댓글이 등록되었습니다.');
}}

if (!function_exists('cpms_public_affairs_collab_files')) {
function cpms_public_affairs_collab_files($taskId) {
    $taskId = (int)$taskId;
    $store = cpms_public_affairs_collab_load_store('attachments');
    $rows = array();
    foreach ($store['items'] as $row) {
        if (is_array($row) && isset($row['task_id']) && (int)$row['task_id'] === $taskId) $rows[] = $row;
    }
    usort($rows, 'cpms_public_affairs_collab_sort_asc');
    return $rows;
}}

if (!function_exists('cpms_public_affairs_collab_history')) {
function cpms_public_affairs_collab_history($taskId) {
    $taskId = (int)$taskId;
    $store = cpms_public_affairs_collab_load_store('history');
    $rows = array();
    foreach ($store['items'] as $row) {
        if (is_array($row) && isset($row['task_id']) && (int)$row['task_id'] === $taskId) $rows[] = $row;
    }
    usort($rows, 'cpms_public_affairs_collab_sort_asc');
    return $rows;
}}

if (!function_exists('cpms_public_affairs_collab_count_by_task')) {
function cpms_public_affairs_collab_count_by_task($storeName) {
    // 공무 협업툴 업무카드: 칸반/목록에 표시할 댓글·첨부 수를 task_id 기준으로 집계한다.
    $store = cpms_public_affairs_collab_load_store($storeName);
    $counts = array();
    $items = isset($store['items']) && is_array($store['items']) ? $store['items'] : array();
    foreach ($items as $row) {
        if (!is_array($row) || !isset($row['task_id'])) continue;
        $taskId = (int)$row['task_id'];
        if ($taskId <= 0) continue;
        if (!isset($counts[$taskId])) $counts[$taskId] = 0;
        $counts[$taskId]++;
    }
    return $counts;
}}

if (!function_exists('cpms_public_affairs_collab_task_counts')) {
function cpms_public_affairs_collab_task_counts() {
    // 공무 협업툴 업무카드: 화면에서 댓글 수와 첨부 수를 함께 보여주기 위한 통합 카운트.
    return array(
        'comments' => cpms_public_affairs_collab_count_by_task('comments'),
        'files' => cpms_public_affairs_collab_count_by_task('attachments'),
    );
}}

if (!function_exists('cpms_public_affairs_collab_count_for_task')) {
function cpms_public_affairs_collab_count_for_task($counts, $taskId, $key) {
    $taskId = (int)$taskId;
    if (!is_array($counts) || !isset($counts[$key]) || !is_array($counts[$key])) return 0;
    return isset($counts[$key][$taskId]) ? (int)$counts[$key][$taskId] : 0;
}}

if (!function_exists('cpms_public_affairs_collab_find_file')) {
function cpms_public_affairs_collab_find_file($fileId) {
    $fileId = (int)$fileId;
    $store = cpms_public_affairs_collab_load_store('attachments');
    foreach ($store['items'] as $row) {
        if (is_array($row) && isset($row['id']) && (int)$row['id'] === $fileId) return $row;
    }
    return null;
}}

if (!function_exists('cpms_public_affairs_collab_save_uploaded_files')) {
function cpms_public_affairs_collab_save_uploaded_files($task, $files, $actor) {
    if (!is_array($task) || !is_array($files) || !isset($files['name'])) return array();
    $saved = array();
    $names = is_array($files['name']) ? $files['name'] : array($files['name']);
    $tmpNames = is_array($files['tmp_name']) ? $files['tmp_name'] : array($files['tmp_name']);
    $errors = is_array($files['error']) ? $files['error'] : array($files['error']);
    $sizes = is_array($files['size']) ? $files['size'] : array($files['size']);
    $types = is_array($files['type']) ? $files['type'] : array($files['type']);
    $taskId = isset($task['id']) ? (int)$task['id'] : 0;
    $projectId = isset($task['project_id']) ? (int)$task['project_id'] : 0;
    if ($taskId <= 0) return $saved;
    $targetDir = cpms_public_affairs_collab_root_dir() . '/files/' . $projectId . '/' . $taskId;
    if (!cpms_ensure_dir($targetDir)) return $saved;
    $allowed = array('pdf'=>true,'hwp'=>true,'hwpx'=>true,'doc'=>true,'docx'=>true,'xls'=>true,'xlsx'=>true,'ppt'=>true,'pptx'=>true,'jpg'=>true,'jpeg'=>true,'png'=>true,'gif'=>true,'zip'=>true,'txt'=>true);
    $store = cpms_public_affairs_collab_load_store('attachments');
    for ($i = 0; $i < count($names); $i++) {
        $originalName = isset($names[$i]) ? trim((string)$names[$i]) : '';
        $tmpName = isset($tmpNames[$i]) ? (string)$tmpNames[$i] : '';
        $errorCode = isset($errors[$i]) ? (int)$errors[$i] : UPLOAD_ERR_NO_FILE;
        $size = isset($sizes[$i]) ? (int)$sizes[$i] : 0;
        if ($errorCode !== UPLOAD_ERR_OK || $originalName === '' || $tmpName === '') continue;
        if ($size <= 0 || $size > (50 * 1024 * 1024)) continue;
        if (!is_uploaded_file($tmpName)) continue;
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === '' || !isset($allowed[$ext])) continue;
        $storedName = 'pa_collab_' . $projectId . '_' . $taskId . '_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true) . $originalName), 0, 10) . '.' . $ext;
        $storedPath = rtrim($targetDir, '/\\') . '/' . $storedName;
        if (!@move_uploaded_file($tmpName, $storedPath)) continue;
        $nextId = (int)$store['last_id'] + 1;
        $store['last_id'] = $nextId;
        $item = array(
            'id' => $nextId,
            'task_id' => $taskId,
            'project_id' => $projectId,
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'stored_path' => $storedPath,
            'file_size' => $size,
            'mime_type' => isset($types[$i]) ? (string)$types[$i] : '',
            'uploaded_by_id' => is_array($actor) && isset($actor['id']) ? (int)$actor['id'] : 0,
            'uploaded_by_name' => cpms_public_affairs_collab_actor_label($actor),
            'uploaded_at' => date('Y-m-d H:i:s'),
        );
        $store['items'][] = $item;
        $saved[] = $item;
        cpms_public_affairs_collab_add_history($taskId, $projectId, '첨부파일 등록', 'attachment', '', $originalName, '첨부파일이 등록되었습니다.', $actor);
    }
    cpms_public_affairs_collab_save_store('attachments', $store);
    return $saved;
}}

if (!function_exists('cpms_public_affairs_collab_lower')) {
function cpms_public_affairs_collab_lower($value) {
    $value = (string)$value;
    if (function_exists('mb_strtolower')) return mb_strtolower($value, 'UTF-8');
    return strtolower($value);
}}

if (!function_exists('cpms_public_affairs_collab_apply_filters')) {
function cpms_public_affairs_collab_apply_filters($tasks, $filters) {
    if (!is_array($tasks)) return array();
    $result = array();
    $projectId = isset($filters['project_id']) ? (int)$filters['project_id'] : 0;
    $projectName = cpms_public_affairs_collab_lower(trim((string)(isset($filters['project_name']) ? $filters['project_name'] : '')));
    $assigneeId = isset($filters['assignee_employee_id']) ? (int)$filters['assignee_employee_id'] : 0;
    $requesterId = isset($filters['requester_employee_id']) ? (int)$filters['requester_employee_id'] : 0;
    $status = isset($filters['status']) ? trim((string)$filters['status']) : '';
    $priority = isset($filters['priority']) ? trim((string)$filters['priority']) : '';
    $taskType = isset($filters['task_type']) ? trim((string)$filters['task_type']) : '';
    $dueFrom = cpms_public_affairs_collab_date(isset($filters['due_from']) ? $filters['due_from'] : '');
    $dueTo = cpms_public_affairs_collab_date(isset($filters['due_to']) ? $filters['due_to'] : '');
    $keyword = cpms_public_affairs_collab_lower(trim((string)(isset($filters['keyword']) ? $filters['keyword'] : '')));
    foreach ($tasks as $task) {
        if (!is_array($task)) continue;
        if ($projectId > 0 && (!isset($task['project_id']) || (int)$task['project_id'] !== $projectId)) continue;
        if ($projectName !== '') {
            $taskProjectName = cpms_public_affairs_collab_lower(isset($task['project_name']) ? (string)$task['project_name'] : '');
            if (strpos($taskProjectName, $projectName) === false) continue;
        }
        if ($assigneeId > 0 && (!isset($task['assignee_employee_id']) || (int)$task['assignee_employee_id'] !== $assigneeId)) continue;
        if ($requesterId > 0 && (!isset($task['requester_employee_id']) || (int)$task['requester_employee_id'] !== $requesterId)) continue;
        if ($status !== '' && (!isset($task['status']) || (string)$task['status'] !== $status)) continue;
        if ($priority !== '' && (!isset($task['priority']) || (string)$task['priority'] !== $priority)) continue;
        if ($taskType !== '' && (!isset($task['task_type']) || (string)$task['task_type'] !== $taskType)) continue;
        $dueDate = isset($task['due_date']) ? (string)$task['due_date'] : '';
        if ($dueFrom !== '' && ($dueDate === '' || strcmp($dueDate, $dueFrom) < 0)) continue;
        if ($dueTo !== '' && ($dueDate === '' || strcmp($dueDate, $dueTo) > 0)) continue;
        if ($keyword !== '') {
            $haystack = cpms_public_affairs_collab_lower(
                (isset($task['title']) ? $task['title'] : '') . ' ' .
                (isset($task['content']) ? $task['content'] : '') . ' ' .
                (isset($task['project_name']) ? $task['project_name'] : '') . ' ' .
                (isset($task['assignee_name']) ? $task['assignee_name'] : '') . ' ' .
                (isset($task['requester_name']) ? $task['requester_name'] : '')
            );
            if (strpos($haystack, $keyword) === false) continue;
        }
        $result[] = $task;
    }
    return $result;
}}

if (!function_exists('cpms_public_affairs_collab_summary')) {
function cpms_public_affairs_collab_summary($tasks, $employee) {
    $summary = array('all' => 0, 'mine' => 0, 'today' => 0, 'delayed' => 0, 'done' => 0);
    $today = date('Y-m-d');
    if (!is_array($tasks)) return $summary;
    foreach ($tasks as $task) {
        if (!is_array($task)) continue;
        $summary['all']++;
        if (is_array($employee)) {
            $employeeId = isset($employee['id']) ? (int)$employee['id'] : 0;
            $employeeEmail = isset($employee['email']) ? strtolower(trim((string)$employee['email'])) : '';
            if (($employeeId > 0 && isset($task['assignee_employee_id']) && (int)$task['assignee_employee_id'] === $employeeId) ||
                ($employeeEmail !== '' && isset($task['assignee_email']) && strtolower(trim((string)$task['assignee_email'])) === $employeeEmail)) {
                $summary['mine']++;
            }
        }
        $status = isset($task['status']) ? (string)$task['status'] : '';
        if ($status === '완료') $summary['done']++;
        if ($status !== '완료' && isset($task['due_date']) && (string)$task['due_date'] === $today) $summary['today']++;
        if (cpms_public_affairs_collab_is_delayed($task)) $summary['delayed']++;
    }
    return $summary;
}}

if (!function_exists('cpms_public_affairs_collab_group_by_status')) {
function cpms_public_affairs_collab_group_by_status($tasks, $statuses) {
    $groups = array();
    if (!is_array($statuses)) $statuses = array();
    foreach ($statuses as $status) $groups[$status] = array();
    $groups['기타'] = array();
    if (is_array($tasks)) {
        foreach ($tasks as $task) {
            if (!is_array($task)) continue;
            $status = isset($task['status']) ? (string)$task['status'] : '';
            if ($status === '' || !isset($groups[$status])) $status = '기타';
            $groups[$status][] = $task;
        }
    }
    if (count($groups['기타']) === 0) unset($groups['기타']);
    return $groups;
}}
