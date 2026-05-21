<?php
use App\Core\Auth;

if (!function_exists('cpms_tasks_now')) {
function cpms_tasks_now()
{
    return date('Y-m-d H:i:s');
}}

if (!function_exists('cpms_tasks_today')) {
function cpms_tasks_today()
{
    return date('Y-m-d');
}}

if (!function_exists('cpms_tasks_root_path')) {
function cpms_tasks_root_path()
{
    return dirname(dirname(dirname(__DIR__)));
}}

if (!function_exists('cpms_tasks_public_root')) {
function cpms_tasks_public_root()
{
    return cpms_tasks_root_path() . '/public';
}}

if (!function_exists('cpms_tasks_table_exists')) {
function cpms_tasks_table_exists($pdo, $tableName)
{
    if (!$pdo || trim((string)$tableName) === '') return false;
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE :table_name");
        $st->execute(array(':table_name' => $tableName));
        return $st->fetchColumn() ? true : false;
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_tasks_column_exists')) {
function cpms_tasks_column_exists($pdo, $tableName, $columnName)
{
    if (!$pdo || trim((string)$tableName) === '' || trim((string)$columnName) === '') return false;
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `" . str_replace('`', '``', $tableName) . "` LIKE :column_name");
        $st->execute(array(':column_name' => $columnName));
        return $st->fetchColumn() ? true : false;
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_tasks_index_exists')) {
function cpms_tasks_index_exists($pdo, $tableName, $indexName)
{
    if (!$pdo || trim((string)$tableName) === '' || trim((string)$indexName) === '') return false;
    try {
        $st = $pdo->query("SHOW INDEX FROM `" . str_replace('`', '``', $tableName) . "`");
        if (!$st) return false;
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if (isset($row['Key_name']) && (string)$row['Key_name'] === (string)$indexName) {
                return true;
            }
        }
    } catch (Exception $e) {
        return false;
    }
    return false;
}}

if (!function_exists('cpms_tasks_add_result')) {
function cpms_tasks_add_result(&$results, $type, $name, $ok, $message)
{
    $results[count($results)] = array(
        'type' => (string)$type,
        'name' => (string)$name,
        'ok' => $ok ? 1 : 0,
        'msg' => (string)$message,
    );
}}

if (!function_exists('cpms_tasks_normalize_department')) {
function cpms_tasks_normalize_department($department)
{
    $department = trim((string)$department);
    $map = array(
        '공사부' => '공사',
        '공무부' => '공무',
        '안전부' => '안전',
        '관리부' => '관리',
        '품질부' => '품질',
        '안전/보건' => '안전',
        '안전보건' => '안전',
    );
    if (isset($map[$department])) {
        return $map[$department];
    }
    if ($department === '') return '기타';
    return $department;
}}

if (!function_exists('cpms_tasks_department_options')) {
function cpms_tasks_department_options()
{
    return array('공사', '공무', '안전', '관리', '품질', '기타');
}}

if (!function_exists('cpms_tasks_priority_options')) {
function cpms_tasks_priority_options()
{
    return array(
        'low' => '낮음',
        'normal' => '보통',
        'high' => '높음',
        'urgent' => '긴급',
    );
}}

if (!function_exists('cpms_tasks_type_options')) {
function cpms_tasks_type_options()
{
    return array(
        'general' => '일반업무',
        'urgent' => '긴급업무',
        'approval' => '전자결재',
        'labor_gongsu' => '공수승인',
        'equipment_gongsu' => '장비공수승인',
        'attendance' => '출퇴근승인',
        'issue' => '이슈조치',
        'safety_accident' => '안전사고조치',
        'field' => '현장요청',
        'construction' => '공사요청',
        'project' => '공무요청',
        'admin' => '관리요청',
    );
}}

if (!function_exists('cpms_tasks_status_label')) {
function cpms_tasks_status_label($status)
{
    $labels = array(
        'pending' => '대기',
        'progress' => '진행중',
        'done' => '완료',
        'delayed' => '지연',
        'revision' => '보완요청',
        'cancelled' => '취소',
        'created' => '등록',
        'status_changed' => '상태 변경',
        'completed' => '완료 처리',
        'revision_requested' => '보완요청',
        'commented' => '메모',
        'PENDING' => '승인대기',
        'APPROVED' => '승인완료',
        'REJECTED' => '반려',
        'approved' => '승인완료',
        'rejected' => '반려',
        'processing' => '진행중',
    );
    return isset($labels[$status]) ? $labels[$status] : (trim((string)$status) !== '' ? (string)$status : '-');
}}

if (!function_exists('cpms_tasks_type_label')) {
function cpms_tasks_type_label($taskType)
{
    $options = cpms_tasks_type_options();
    return isset($options[$taskType]) ? $options[$taskType] : (trim((string)$taskType) !== '' ? (string)$taskType : '업무');
}}

if (!function_exists('cpms_tasks_priority_label')) {
function cpms_tasks_priority_label($priority)
{
    $options = cpms_tasks_priority_options();
    return isset($options[$priority]) ? $options[$priority] : (trim((string)$priority) !== '' ? (string)$priority : '보통');
}}

if (!function_exists('cpms_tasks_badge_class')) {
function cpms_tasks_badge_class($type, $value)
{
    if ($type === 'status') {
        if ($value === 'done') return 'bg-emerald-50 text-emerald-700 border-emerald-200';
        if ($value === 'progress') return 'bg-blue-50 text-blue-700 border-blue-200';
        if ($value === 'revision') return 'bg-amber-50 text-amber-700 border-amber-200';
        if ($value === 'cancelled') return 'bg-slate-100 text-slate-600 border-slate-200';
        if ($value === 'delayed') return 'bg-rose-50 text-rose-700 border-rose-200';
        return 'bg-gray-100 text-gray-700 border-gray-200';
    }
    if ($type === 'priority') {
        if ($value === 'urgent') return 'bg-rose-50 text-rose-700 border-rose-200';
        if ($value === 'high') return 'bg-orange-50 text-orange-700 border-orange-200';
        if ($value === 'low') return 'bg-slate-100 text-slate-600 border-slate-200';
        return 'bg-blue-50 text-blue-700 border-blue-200';
    }
    return 'bg-gray-100 text-gray-700 border-gray-200';
}}

if (!function_exists('cpms_tasks_due_datetime')) {
function cpms_tasks_due_datetime($row)
{
    $dueDate = isset($row['due_date']) ? trim((string)$row['due_date']) : '';
    if ($dueDate === '') return '';
    $dueTime = isset($row['due_time']) ? trim((string)$row['due_time']) : '';
    if ($dueTime === '') $dueTime = '23:59:59';
    if (strlen($dueTime) === 5) $dueTime .= ':00';
    return $dueDate . ' ' . $dueTime;
}}

if (!function_exists('cpms_tasks_is_closed_status')) {
function cpms_tasks_is_closed_status($status)
{
    return in_array((string)$status, array('done', 'cancelled', 'APPROVED', 'REJECTED', 'approved', 'rejected'), true);
}}

if (!function_exists('cpms_tasks_is_delayed')) {
function cpms_tasks_is_delayed($row)
{
    $status = isset($row['status']) ? (string)$row['status'] : '';
    if (cpms_tasks_is_closed_status($status)) return false;
    $dueAt = cpms_tasks_due_datetime($row);
    if ($dueAt === '') return false;
    $dueTs = strtotime($dueAt);
    if ($dueTs === false) return false;
    return ($dueTs < time());
}}

if (!function_exists('cpms_tasks_is_due_today')) {
function cpms_tasks_is_due_today($row)
{
    $dueDate = isset($row['due_date']) ? trim((string)$row['due_date']) : '';
    if ($dueDate === '') return false;
    return ($dueDate === cpms_tasks_today());
}}

if (!function_exists('cpms_tasks_is_due_soon')) {
function cpms_tasks_is_due_soon($row)
{
    $status = isset($row['status']) ? (string)$row['status'] : '';
    if (cpms_tasks_is_closed_status($status)) return false;
    $dueAt = cpms_tasks_due_datetime($row);
    if ($dueAt === '') return false;
    $dueTs = strtotime($dueAt);
    if ($dueTs === false) return false;
    $diff = $dueTs - time();
    return ($diff >= 0 && $diff <= 86400);
}}

if (!function_exists('cpms_tasks_display_status')) {
function cpms_tasks_display_status($row)
{
    $status = isset($row['status']) ? (string)$row['status'] : '';
    if (cpms_tasks_is_delayed($row)) return '지연';
    return cpms_tasks_status_label($status);
}}

if (!function_exists('cpms_tasks_default_return_url')) {
function cpms_tasks_default_return_url()
{
    $dashboardType = isset($_SESSION['dashboardType']) ? (string)$_SESSION['dashboardType'] : 'employee';
    $url = '?r=대시보드';
    if ($dashboardType === 'executive') $url .= '&dv=executive';
    $departmentFilter = isset($_GET['task_department']) ? trim((string)$_GET['task_department']) : '';
    if ($departmentFilter !== '') $url .= '&task_department=' . urlencode($departmentFilter);
    return $url;
}}

if (!function_exists('cpms_tasks_redirect_back')) {
function cpms_tasks_redirect_back()
{
    $returnUrl = isset($_POST['return_url']) ? trim((string)$_POST['return_url']) : '';
    if ($returnUrl === '') $returnUrl = isset($_SERVER['HTTP_REFERER']) ? trim((string)$_SERVER['HTTP_REFERER']) : '';
    if ($returnUrl === '' || strpos($returnUrl, 'javascript:') === 0) {
        $returnUrl = cpms_tasks_default_return_url();
    }
    header('Location: ' . $returnUrl);
    exit;
}}

if (!function_exists('cpms_tasks_is_overall_manager')) {
function cpms_tasks_is_overall_manager()
{
    if (Auth::isMaster()) return true;
    if (Auth::userRole() === 'executive') return true;
    return Auth::canManageEmployees();
}}

if (!function_exists('cpms_tasks_current_employee')) {
function cpms_tasks_current_employee($pdo)
{
    $result = array(
        'id' => 0,
        'name' => (string)Auth::userName(),
        'email' => (string)Auth::userEmail(),
        'department' => cpms_tasks_normalize_department((string)Auth::userDepartment()),
        'position' => (string)Auth::userPosition(),
        'role' => (string)Auth::userRole(),
    );
    if (!$pdo || $result['email'] === '') return $result;

    $columns = array('id', 'name', 'email');
    $columns[count($columns)] = cpms_tasks_column_exists($pdo, 'employees', 'department') ? 'department' : "'' AS department";
    $columns[count($columns)] = cpms_tasks_column_exists($pdo, 'employees', 'position') ? 'position' : "'' AS position";
    $columns[count($columns)] = cpms_tasks_column_exists($pdo, 'employees', 'role') ? 'role' : "'employee' AS role";
    try {
        $st = $pdo->prepare("SELECT " . implode(', ', $columns) . " FROM employees WHERE LOWER(email) = LOWER(:email) LIMIT 1");
        $st->execute(array(':email' => $result['email']));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $result['id'] = isset($row['id']) ? (int)$row['id'] : 0;
            $result['name'] = isset($row['name']) ? (string)$row['name'] : $result['name'];
            $result['department'] = cpms_tasks_normalize_department(isset($row['department']) ? $row['department'] : $result['department']);
            $result['position'] = isset($row['position']) ? (string)$row['position'] : $result['position'];
            $result['role'] = isset($row['role']) ? (string)$row['role'] : $result['role'];
        }
    } catch (Exception $e) {
    }
    return $result;
}}

if (!function_exists('cpms_tasks_fetch_active_employees')) {
function cpms_tasks_fetch_active_employees($pdo)
{
    $rows = array();
    if (!$pdo) return $rows;
    $departmentColumn = cpms_tasks_column_exists($pdo, 'employees', 'department') ? 'department' : "'' AS department";
    $positionColumn = cpms_tasks_column_exists($pdo, 'employees', 'position') ? 'position' : "'' AS position";
    $roleColumn = cpms_tasks_column_exists($pdo, 'employees', 'role') ? 'role' : "'employee' AS role";
    $orderSql = ' ORDER BY name ASC';
    if (cpms_tasks_column_exists($pdo, 'employees', 'department') || cpms_tasks_column_exists($pdo, 'employees', 'position')) {
        $orderSql = ' ORDER BY department ASC, position ASC, name ASC';
    }
    try {
        $sql = "SELECT id, name, email, " . $departmentColumn . ", " . $positionColumn . ", " . $roleColumn . " FROM employees WHERE is_active = 1" . $orderSql;
        $st = $pdo->query($sql);
        $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
    } catch (Exception $e) {
        $rows = array();
    }
    if (!is_array($rows)) $rows = array();
    foreach ($rows as $index => $row) {
        $rows[$index]['department'] = cpms_tasks_normalize_department(isset($row['department']) ? $row['department'] : '');
    }
    return $rows;
}}

if (!function_exists('cpms_tasks_fetch_projects')) {
function cpms_tasks_fetch_projects($pdo)
{
    $rows = array();
    if (!$pdo || !cpms_tasks_table_exists($pdo, 'cpms_projects')) return $rows;
    try {
        $st = $pdo->query("SELECT id, name FROM cpms_projects ORDER BY id DESC");
        $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
    } catch (Exception $e) {
        $rows = array();
    }
    return is_array($rows) ? $rows : array();
}}

if (!function_exists('cpms_tasks_resolve_project')) {
function cpms_tasks_resolve_project($pdo, $projectId)
{
    $projectId = (int)$projectId;
    if (!$pdo || $projectId <= 0 || !cpms_tasks_table_exists($pdo, 'cpms_projects')) {
        return array('project_id' => 0, 'project_name' => '');
    }
    try {
        $st = $pdo->prepare("SELECT id, name FROM cpms_projects WHERE id = :id LIMIT 1");
        $st->execute(array(':id' => $projectId));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return array(
                'project_id' => (int)$row['id'],
                'project_name' => isset($row['name']) ? (string)$row['name'] : '',
            );
        }
    } catch (Exception $e) {
    }
    return array('project_id' => 0, 'project_name' => '');
}}

if (!function_exists('cpms_tasks_find_employee_by_id')) {
function cpms_tasks_find_employee_by_id($pdo, $employeeId)
{
    $employeeId = (int)$employeeId;
    if (!$pdo || $employeeId <= 0) return null;
    $departmentColumn = cpms_tasks_column_exists($pdo, 'employees', 'department') ? 'department' : "'' AS department";
    $positionColumn = cpms_tasks_column_exists($pdo, 'employees', 'position') ? 'position' : "'' AS position";
    try {
        $st = $pdo->prepare("SELECT id, name, email, " . $departmentColumn . ", " . $positionColumn . " FROM employees WHERE id = :id LIMIT 1");
        $st->execute(array(':id' => $employeeId));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['department'] = cpms_tasks_normalize_department(isset($row['department']) ? $row['department'] : '');
            return $row;
        }
    } catch (Exception $e) {
    }
    return null;
}}

if (!function_exists('cpms_tasks_can_view')) {
function cpms_tasks_can_view($task, $currentEmployeeId)
{
    if (!$task) return false;
    if (cpms_tasks_is_overall_manager()) return true;
    if ((int)$currentEmployeeId <= 0) return false;
    if ((int)$task['assignee_employee_id'] === (int)$currentEmployeeId) return true;
    if ((int)$task['requester_employee_id'] === (int)$currentEmployeeId) return true;
    return false;
}}

if (!function_exists('cpms_tasks_can_change_status')) {
function cpms_tasks_can_change_status($task, $currentEmployeeId)
{
    if (!$task) return false;
    if (cpms_tasks_is_overall_manager()) return true;
    return ((int)$currentEmployeeId > 0 && (int)$task['assignee_employee_id'] === (int)$currentEmployeeId);
}}

if (!function_exists('cpms_tasks_can_request_revision')) {
function cpms_tasks_can_request_revision($task, $currentEmployeeId)
{
    if (!$task) return false;
    if (cpms_tasks_is_overall_manager()) return true;
    return ((int)$currentEmployeeId > 0 && (int)$task['requester_employee_id'] === (int)$currentEmployeeId);
}}

if (!function_exists('cpms_tasks_can_cancel')) {
function cpms_tasks_can_cancel($task, $currentEmployeeId)
{
    if (!$task) return false;
    if (cpms_tasks_is_overall_manager()) return true;
    return ((int)$currentEmployeeId > 0 && (int)$task['requester_employee_id'] === (int)$currentEmployeeId);
}}

if (!function_exists('cpms_tasks_insert_log')) {
function cpms_tasks_insert_log($pdo, $taskId, $actor, $actionType, $message, $oldStatus, $newStatus)
{
    if (!$pdo || !cpms_tasks_table_exists($pdo, 'cpms_task_logs')) return false;
    try {
        $st = $pdo->prepare("INSERT INTO cpms_task_logs (task_id, actor_employee_id, actor_name, action_type, message, old_status, new_status, created_at) VALUES (:task_id, :actor_employee_id, :actor_name, :action_type, :message, :old_status, :new_status, :created_at)");
        return $st->execute(array(
            ':task_id' => (int)$taskId,
            ':actor_employee_id' => isset($actor['id']) ? (int)$actor['id'] : null,
            ':actor_name' => isset($actor['name']) ? (string)$actor['name'] : '',
            ':action_type' => (string)$actionType,
            ':message' => (string)$message,
            ':old_status' => $oldStatus !== null ? (string)$oldStatus : null,
            ':new_status' => $newStatus !== null ? (string)$newStatus : null,
            ':created_at' => cpms_tasks_now(),
        ));
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_tasks_upload_relative_dir')) {
function cpms_tasks_upload_relative_dir($taskId)
{
    return 'uploads/tasks/' . (int)$taskId;
}}

if (!function_exists('cpms_tasks_upload_abs_dir')) {
function cpms_tasks_upload_abs_dir($taskId)
{
    return cpms_tasks_public_root() . '/' . cpms_tasks_upload_relative_dir($taskId);
}}

if (!function_exists('cpms_tasks_file_url')) {
function cpms_tasks_file_url($storedPath)
{
    $storedPath = ltrim(str_replace('\\', '/', (string)$storedPath), '/');
    return base_url() . '/' . $storedPath;
}}

if (!function_exists('cpms_tasks_save_uploaded_files')) {
function cpms_tasks_save_uploaded_files($pdo, $taskId, $files, $uploadedBy)
{
    if (!$pdo || (int)$taskId <= 0 || !is_array($files) || !isset($files['name'])) return array();
    $saved = array();
    $names = is_array($files['name']) ? $files['name'] : array($files['name']);
    $tmpNames = is_array($files['tmp_name']) ? $files['tmp_name'] : array($files['tmp_name']);
    $errors = is_array($files['error']) ? $files['error'] : array($files['error']);
    $sizes = is_array($files['size']) ? $files['size'] : array($files['size']);
    $types = is_array($files['type']) ? $files['type'] : array($files['type']);
    $targetDir = cpms_tasks_upload_abs_dir($taskId);
    if (!cpms_ensure_dir($targetDir)) return $saved;

    for ($i = 0; $i < count($names); $i++) {
        $originalName = isset($names[$i]) ? trim((string)$names[$i]) : '';
        $tmpName = isset($tmpNames[$i]) ? (string)$tmpNames[$i] : '';
        $errorCode = isset($errors[$i]) ? (int)$errors[$i] : UPLOAD_ERR_NO_FILE;
        if ($errorCode !== UPLOAD_ERR_OK || $originalName === '' || $tmpName === '') continue;

        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $storedName = date('YmdHis') . '_' . substr(md5(uniqid('', true) . $originalName), 0, 16);
        if ($extension !== '') $storedName .= '.' . strtolower($extension);
        $relativePath = cpms_tasks_upload_relative_dir($taskId) . '/' . $storedName;
        $absolutePath = $targetDir . '/' . $storedName;

        if (!@move_uploaded_file($tmpName, $absolutePath)) {
            continue;
        }

        try {
            $st = $pdo->prepare("INSERT INTO cpms_task_files (task_id, original_name, stored_name, stored_path, file_size, mime_type, uploaded_by, uploaded_at) VALUES (:task_id, :original_name, :stored_name, :stored_path, :file_size, :mime_type, :uploaded_by, :uploaded_at)");
            $st->execute(array(
                ':task_id' => (int)$taskId,
                ':original_name' => $originalName,
                ':stored_name' => $storedName,
                ':stored_path' => $relativePath,
                ':file_size' => isset($sizes[$i]) ? (int)$sizes[$i] : 0,
                ':mime_type' => isset($types[$i]) ? (string)$types[$i] : '',
                ':uploaded_by' => (int)$uploadedBy > 0 ? (int)$uploadedBy : null,
                ':uploaded_at' => cpms_tasks_now(),
            ));
            $saved[count($saved)] = array(
                'original_name' => $originalName,
                'stored_name' => $storedName,
                'stored_path' => $relativePath,
            );
        } catch (Exception $e) {
        }
    }

    return $saved;
}}

if (!function_exists('cpms_tasks_find_task')) {
function cpms_tasks_find_task($pdo, $taskId)
{
    if (!$pdo || !cpms_tasks_table_exists($pdo, 'cpms_tasks')) return null;
    try {
        $st = $pdo->prepare("SELECT * FROM cpms_tasks WHERE id = :id LIMIT 1");
        $st->execute(array(':id' => (int)$taskId));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? $row : null;
    } catch (Exception $e) {
        return null;
    }
}}

if (!function_exists('cpms_tasks_fetch_logs')) {
function cpms_tasks_fetch_logs($pdo, $taskId)
{
    $rows = array();
    if (!$pdo || !cpms_tasks_table_exists($pdo, 'cpms_task_logs')) return $rows;
    try {
        $st = $pdo->prepare("SELECT * FROM cpms_task_logs WHERE task_id = :task_id ORDER BY id ASC");
        $st->execute(array(':task_id' => (int)$taskId));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $rows = array();
    }
    return is_array($rows) ? $rows : array();
}}

if (!function_exists('cpms_tasks_fetch_files')) {
function cpms_tasks_fetch_files($pdo, $taskId)
{
    $rows = array();
    if (!$pdo || !cpms_tasks_table_exists($pdo, 'cpms_task_files')) return $rows;
    try {
        $st = $pdo->prepare("SELECT * FROM cpms_task_files WHERE task_id = :task_id ORDER BY id ASC");
        $st->execute(array(':task_id' => (int)$taskId));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $rows = array();
    }
    return is_array($rows) ? $rows : array();
}}

if (!function_exists('cpms_tasks_text_excerpt')) {
function cpms_tasks_text_excerpt($text, $limit)
{
    $text = trim((string)$text);
    if ($text === '') return '';
    $text = preg_replace("/\s+/", ' ', $text);
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($text, 'UTF-8') > $limit) {
            return mb_substr($text, 0, $limit, 'UTF-8') . '...';
        }
        return $text;
    }
    if (strlen($text) > $limit) return substr($text, 0, $limit) . '...';
    return $text;
}}

if (!function_exists('cpms_tasks_build_create_message')) {
function cpms_tasks_build_create_message($task)
{
    $lines = array();
    $isUrgent = isset($task['is_urgent']) && (int)$task['is_urgent'] === 1;
    $lines[count($lines)] = $isUrgent ? '🔥 [CPMS 긴급 업무 요청]' : '[CPMS 업무 요청]';
    $lines[count($lines)] = '';
    $lines[count($lines)] = '업무명 : ' . (isset($task['title']) ? (string)$task['title'] : '-');
    $lines[count($lines)] = '요청자 : ' . (isset($task['requester_name']) ? (string)$task['requester_name'] : '-');
    $dueLine = '-';
    if (!empty($task['due_date'])) {
        $dueLine = (string)$task['due_date'];
        if (!empty($task['due_time'])) $dueLine .= ' ' . substr((string)$task['due_time'], 0, 5);
    }
    $lines[count($lines)] = '마감기한 : ' . $dueLine;
    $lines[count($lines)] = '내용 : ' . cpms_tasks_text_excerpt(isset($task['content']) ? $task['content'] : '', 120);
    $lines[count($lines)] = '';
    $lines[count($lines)] = '나의 할일에서 확인해주세요.';
    return implode("\n", $lines);
}}

if (!function_exists('cpms_tasks_build_complete_message')) {
function cpms_tasks_build_complete_message($task)
{
    $lines = array();
    $lines[count($lines)] = '[CPMS 업무 완료]';
    $lines[count($lines)] = '';
    $lines[count($lines)] = '업무명 : ' . (isset($task['title']) ? (string)$task['title'] : '-');
    $lines[count($lines)] = '담당자 : ' . (isset($task['assignee_name']) ? (string)$task['assignee_name'] : '-');
    $lines[count($lines)] = '완료메모 : ' . cpms_tasks_text_excerpt(isset($task['completed_memo']) ? $task['completed_memo'] : '', 120);
    $lines[count($lines)] = '';
    $lines[count($lines)] = '요청한 업무에서 확인해주세요.';
    return implode("\n", $lines);
}}

if (!function_exists('cpms_tasks_send_created_notification')) {
function cpms_tasks_send_created_notification($pdo, $task)
{
    if (!function_exists('cpms_send_google_chat_to_employee')) {
        require_once dirname(dirname(__DIR__)) . '/helpers.php';
    }
    if (!function_exists('cpms_send_google_chat_to_employee')) return false;
    $assigneeId = isset($task['assignee_employee_id']) ? (int)$task['assignee_employee_id'] : 0;
    if ($assigneeId <= 0) return false;
    return cpms_send_google_chat_to_employee($pdo, $assigneeId, cpms_tasks_build_create_message($task), isset($task['id']) ? (int)$task['id'] : 0, 'TASK_CREATED', 'TASK');
}}

if (!function_exists('cpms_tasks_send_completed_notification')) {
function cpms_tasks_send_completed_notification($pdo, $task)
{
    if (!function_exists('cpms_send_google_chat_to_employee')) {
        require_once dirname(dirname(__DIR__)) . '/helpers.php';
    }
    if (!function_exists('cpms_send_google_chat_to_employee')) return false;
    $requesterId = isset($task['requester_employee_id']) ? (int)$task['requester_employee_id'] : 0;
    if ($requesterId <= 0) return false;
    return cpms_send_google_chat_to_employee($pdo, $requesterId, cpms_tasks_build_complete_message($task), isset($task['id']) ? (int)$task['id'] : 0, 'TASK_COMPLETED', 'TASK');
}}

if (!function_exists('cpms_tasks_ensure_schema')) {
function cpms_tasks_ensure_schema($pdo, &$results)
{
    if (!$pdo) return false;

    $tableSql = array(
        'cpms_tasks' => "CREATE TABLE IF NOT EXISTS cpms_tasks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            content TEXT NULL,
            requester_employee_id INT NULL,
            requester_name VARCHAR(100) NULL,
            requester_email VARCHAR(190) NULL,
            assignee_employee_id INT NOT NULL,
            assignee_name VARCHAR(100) NULL,
            assignee_email VARCHAR(190) NULL,
            department VARCHAR(100) NULL,
            project_id INT NULL,
            project_name VARCHAR(255) NULL,
            task_type VARCHAR(50) NOT NULL DEFAULT 'general',
            priority VARCHAR(30) NOT NULL DEFAULT 'normal',
            is_urgent TINYINT(1) NOT NULL DEFAULT 0,
            due_date DATE NULL,
            due_time TIME NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            completed_at DATETIME NULL,
            completed_by INT NULL,
            completed_memo TEXT NULL,
            cancelled_at DATETIME NULL,
            cancelled_by INT NULL,
            cancel_reason TEXT NULL,
            created_by INT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'cpms_task_logs' => "CREATE TABLE IF NOT EXISTS cpms_task_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            task_id INT NOT NULL,
            actor_employee_id INT NULL,
            actor_name VARCHAR(100) NULL,
            action_type VARCHAR(50) NOT NULL,
            message TEXT NULL,
            old_status VARCHAR(30) NULL,
            new_status VARCHAR(30) NULL,
            created_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'cpms_task_files' => "CREATE TABLE IF NOT EXISTS cpms_task_files (
            id INT AUTO_INCREMENT PRIMARY KEY,
            task_id INT NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            stored_name VARCHAR(255) NOT NULL,
            stored_path VARCHAR(500) NOT NULL,
            file_size INT NULL,
            mime_type VARCHAR(100) NULL,
            uploaded_by INT NULL,
            uploaded_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    );

    foreach ($tableSql as $tableName => $sql) {
        try {
            $pdo->exec($sql);
            cpms_tasks_add_result($results, 'TABLE', $tableName, true, '확인/생성 완료');
        } catch (Exception $e) {
            cpms_tasks_add_result($results, 'TABLE', $tableName, false, $e->getMessage());
        }
    }

    $columns = array(
        'cpms_tasks' => array(
            'content' => "ALTER TABLE cpms_tasks ADD COLUMN content TEXT NULL AFTER title",
            'requester_employee_id' => "ALTER TABLE cpms_tasks ADD COLUMN requester_employee_id INT NULL AFTER content",
            'requester_name' => "ALTER TABLE cpms_tasks ADD COLUMN requester_name VARCHAR(100) NULL AFTER requester_employee_id",
            'requester_email' => "ALTER TABLE cpms_tasks ADD COLUMN requester_email VARCHAR(190) NULL AFTER requester_name",
            'assignee_employee_id' => "ALTER TABLE cpms_tasks ADD COLUMN assignee_employee_id INT NOT NULL DEFAULT 0 AFTER requester_email",
            'assignee_name' => "ALTER TABLE cpms_tasks ADD COLUMN assignee_name VARCHAR(100) NULL AFTER assignee_employee_id",
            'assignee_email' => "ALTER TABLE cpms_tasks ADD COLUMN assignee_email VARCHAR(190) NULL AFTER assignee_name",
            'department' => "ALTER TABLE cpms_tasks ADD COLUMN department VARCHAR(100) NULL AFTER assignee_email",
            'project_id' => "ALTER TABLE cpms_tasks ADD COLUMN project_id INT NULL AFTER department",
            'project_name' => "ALTER TABLE cpms_tasks ADD COLUMN project_name VARCHAR(255) NULL AFTER project_id",
            'task_type' => "ALTER TABLE cpms_tasks ADD COLUMN task_type VARCHAR(50) NOT NULL DEFAULT 'general' AFTER project_name",
            'priority' => "ALTER TABLE cpms_tasks ADD COLUMN priority VARCHAR(30) NOT NULL DEFAULT 'normal' AFTER task_type",
            'is_urgent' => "ALTER TABLE cpms_tasks ADD COLUMN is_urgent TINYINT(1) NOT NULL DEFAULT 0 AFTER priority",
            'due_date' => "ALTER TABLE cpms_tasks ADD COLUMN due_date DATE NULL AFTER is_urgent",
            'due_time' => "ALTER TABLE cpms_tasks ADD COLUMN due_time TIME NULL AFTER due_date",
            'status' => "ALTER TABLE cpms_tasks ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'pending' AFTER due_time",
            'completed_at' => "ALTER TABLE cpms_tasks ADD COLUMN completed_at DATETIME NULL AFTER status",
            'completed_by' => "ALTER TABLE cpms_tasks ADD COLUMN completed_by INT NULL AFTER completed_at",
            'completed_memo' => "ALTER TABLE cpms_tasks ADD COLUMN completed_memo TEXT NULL AFTER completed_by",
            'cancelled_at' => "ALTER TABLE cpms_tasks ADD COLUMN cancelled_at DATETIME NULL AFTER completed_memo",
            'cancelled_by' => "ALTER TABLE cpms_tasks ADD COLUMN cancelled_by INT NULL AFTER cancelled_at",
            'cancel_reason' => "ALTER TABLE cpms_tasks ADD COLUMN cancel_reason TEXT NULL AFTER cancelled_by",
            'created_by' => "ALTER TABLE cpms_tasks ADD COLUMN created_by INT NULL AFTER cancel_reason",
            'created_at' => "ALTER TABLE cpms_tasks ADD COLUMN created_at DATETIME NULL AFTER created_by",
            'updated_at' => "ALTER TABLE cpms_tasks ADD COLUMN updated_at DATETIME NULL AFTER created_at",
        ),
        'cpms_task_logs' => array(
            'task_id' => "ALTER TABLE cpms_task_logs ADD COLUMN task_id INT NOT NULL DEFAULT 0 AFTER id",
            'actor_employee_id' => "ALTER TABLE cpms_task_logs ADD COLUMN actor_employee_id INT NULL AFTER task_id",
            'actor_name' => "ALTER TABLE cpms_task_logs ADD COLUMN actor_name VARCHAR(100) NULL AFTER actor_employee_id",
            'action_type' => "ALTER TABLE cpms_task_logs ADD COLUMN action_type VARCHAR(50) NOT NULL DEFAULT 'commented' AFTER actor_name",
            'message' => "ALTER TABLE cpms_task_logs ADD COLUMN message TEXT NULL AFTER action_type",
            'old_status' => "ALTER TABLE cpms_task_logs ADD COLUMN old_status VARCHAR(30) NULL AFTER message",
            'new_status' => "ALTER TABLE cpms_task_logs ADD COLUMN new_status VARCHAR(30) NULL AFTER old_status",
            'created_at' => "ALTER TABLE cpms_task_logs ADD COLUMN created_at DATETIME NULL AFTER new_status",
        ),
        'cpms_task_files' => array(
            'task_id' => "ALTER TABLE cpms_task_files ADD COLUMN task_id INT NOT NULL DEFAULT 0 AFTER id",
            'original_name' => "ALTER TABLE cpms_task_files ADD COLUMN original_name VARCHAR(255) NOT NULL AFTER task_id",
            'stored_name' => "ALTER TABLE cpms_task_files ADD COLUMN stored_name VARCHAR(255) NOT NULL AFTER original_name",
            'stored_path' => "ALTER TABLE cpms_task_files ADD COLUMN stored_path VARCHAR(500) NOT NULL AFTER stored_name",
            'file_size' => "ALTER TABLE cpms_task_files ADD COLUMN file_size INT NULL AFTER stored_path",
            'mime_type' => "ALTER TABLE cpms_task_files ADD COLUMN mime_type VARCHAR(100) NULL AFTER file_size",
            'uploaded_by' => "ALTER TABLE cpms_task_files ADD COLUMN uploaded_by INT NULL AFTER mime_type",
            'uploaded_at' => "ALTER TABLE cpms_task_files ADD COLUMN uploaded_at DATETIME NULL AFTER uploaded_by",
        ),
    );

    foreach ($columns as $tableName => $tableColumns) {
        foreach ($tableColumns as $columnName => $alterSql) {
            try {
                if (cpms_tasks_column_exists($pdo, $tableName, $columnName)) {
                    cpms_tasks_add_result($results, 'COLUMN', $tableName . '.' . $columnName, true, '이미 존재');
                } else {
                    $pdo->exec($alterSql);
                    cpms_tasks_add_result($results, 'COLUMN', $tableName . '.' . $columnName, true, '추가 완료');
                }
            } catch (Exception $e) {
                cpms_tasks_add_result($results, 'COLUMN', $tableName . '.' . $columnName, false, $e->getMessage());
            }
        }
    }

    $indexes = array(
        'cpms_tasks' => array(
            'idx_assignee_status' => "ALTER TABLE cpms_tasks ADD INDEX idx_assignee_status (assignee_employee_id, status)",
            'idx_requester' => "ALTER TABLE cpms_tasks ADD INDEX idx_requester (requester_employee_id)",
            'idx_due_date' => "ALTER TABLE cpms_tasks ADD INDEX idx_due_date (due_date)",
            'idx_department' => "ALTER TABLE cpms_tasks ADD INDEX idx_department (department)",
            'idx_project_id' => "ALTER TABLE cpms_tasks ADD INDEX idx_project_id (project_id)",
            'idx_is_urgent' => "ALTER TABLE cpms_tasks ADD INDEX idx_is_urgent (is_urgent)",
        ),
        'cpms_task_logs' => array(
            'idx_task_id' => "ALTER TABLE cpms_task_logs ADD INDEX idx_task_id (task_id)",
            'idx_action_type' => "ALTER TABLE cpms_task_logs ADD INDEX idx_action_type (action_type)",
        ),
        'cpms_task_files' => array(
            'idx_task_id' => "ALTER TABLE cpms_task_files ADD INDEX idx_task_id (task_id)",
        ),
    );

    foreach ($indexes as $tableName => $tableIndexes) {
        foreach ($tableIndexes as $indexName => $sql) {
            try {
                if (cpms_tasks_index_exists($pdo, $tableName, $indexName)) {
                    cpms_tasks_add_result($results, 'INDEX', $tableName . '.' . $indexName, true, '이미 존재');
                } else {
                    $pdo->exec($sql);
                    cpms_tasks_add_result($results, 'INDEX', $tableName . '.' . $indexName, true, '추가 완료');
                }
            } catch (Exception $e) {
                cpms_tasks_add_result($results, 'INDEX', $tableName . '.' . $indexName, false, $e->getMessage());
            }
        }
    }

    $uploadRoot = cpms_tasks_public_root() . '/uploads/tasks';
    if (cpms_ensure_dir($uploadRoot)) {
        cpms_tasks_add_result($results, 'DIR', 'public/uploads/tasks', true, '업로드 폴더 확인 완료');
    } else {
        cpms_tasks_add_result($results, 'DIR', 'public/uploads/tasks', false, '업로드 폴더 생성 실패');
    }

    return true;
}}
