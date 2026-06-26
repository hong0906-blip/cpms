<?php
use App\Core\Auth;

require_once dirname(__DIR__) . '/admin/leave_management_helpers.php';
require_once dirname(__DIR__) . '/approval/_common.php';

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
    static $cache = array();
    if (!$pdo || trim((string)$tableName) === '') return false;
    $cacheKey = (function_exists('spl_object_hash') ? spl_object_hash($pdo) : 'nopdo') . ':table:' . (string)$tableName;
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE :table_name");
        $st->execute(array(':table_name' => $tableName));
        $cache[$cacheKey] = $st->fetchColumn() ? true : false;
        return $cache[$cacheKey];
    } catch (Exception $e) {
        $cache[$cacheKey] = false;
        return false;
    }
}}

if (!function_exists('cpms_tasks_column_exists')) {
function cpms_tasks_column_exists($pdo, $tableName, $columnName)
{
    static $cache = array();
    if (!$pdo || trim((string)$tableName) === '' || trim((string)$columnName) === '') return false;
    $cacheKey = (function_exists('spl_object_hash') ? spl_object_hash($pdo) : 'nopdo') . ':column:' . (string)$tableName . ':' . (string)$columnName;
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `" . str_replace('`', '``', $tableName) . "` LIKE :column_name");
        $st->execute(array(':column_name' => $columnName));
        $cache[$cacheKey] = $st->fetchColumn() ? true : false;
        return $cache[$cacheKey];
    } catch (Exception $e) {
        $cache[$cacheKey] = false;
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
        'meeting' => '회의요청',
        'approval' => '전자결재',
        'labor_gongsu' => '공수승인',
        'equipment_gongsu' => '장비공수승인',
        'attendance' => '출퇴근승인',
        'issue' => '이슈조치',
        'safety_accident' => '안전사고조치',
        'samsung_portal_employment_check' => '삼성내방 재직확인',
        'samsung_portal_safety_training' => '출입자 안전교육',
        'samsung_portal_chemical_training' => '유해화학물질 교육',
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
        'meeting_owner' => '회의요청',
        'meeting_available' => '참석가능',
        'meeting_unavailable' => '참석불가능',
        'delayed' => '지연',
        'revision' => '보완요청',
        'cancelled' => '취소',
        'created' => '등록',
        'status_changed' => '상태 변경',
        'completed' => '완료 처리',
        'meeting_available_action' => '참석가능',
        'meeting_unavailable_action' => '참석불가능',
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
    if ((string)$taskType === 'construction_schedule') return '오늘 공정';
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
        if ($value === 'meeting_available') return 'bg-emerald-50 text-emerald-700 border-emerald-200';
        if ($value === 'meeting_unavailable') return 'bg-rose-50 text-rose-700 border-rose-200';
        if ($value === 'meeting_owner') return 'bg-blue-50 text-blue-700 border-blue-200';
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

if (!function_exists('cpms_tasks_meeting_block_minutes')) {
function cpms_tasks_meeting_block_minutes()
{
    return 60;
}}

if (!function_exists('cpms_tasks_normalize_time_value')) {
function cpms_tasks_normalize_time_value($time)
{
    $time = trim((string)$time);
    if ($time === '') return '';
    if (preg_match('/^\d{1,2}:\d{2}$/', $time)) {
        $parts = explode(':', $time);
        if ((int)$parts[0] < 0 || (int)$parts[0] > 23 || (int)$parts[1] < 0 || (int)$parts[1] > 59) return '';
        return sprintf('%02d:%02d:00', (int)$parts[0], (int)$parts[1]);
    }
    if (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $time)) {
        $parts = explode(':', $time);
        if ((int)$parts[0] < 0 || (int)$parts[0] > 23 || (int)$parts[1] < 0 || (int)$parts[1] > 59 || (int)$parts[2] < 0 || (int)$parts[2] > 59) return '';
        return sprintf('%02d:%02d:%02d', (int)$parts[0], (int)$parts[1], (int)$parts[2]);
    }
    return '';
}}

if (!function_exists('cpms_tasks_meeting_time_text')) {
function cpms_tasks_meeting_time_text($date, $time)
{
    $time = cpms_tasks_normalize_time_value($time);
    if ($date === '' || $time === '') return '-';
    return (string)$date . ' ' . substr($time, 0, 5);
}}

if (!function_exists('cpms_tasks_find_meeting_conflict')) {
function cpms_tasks_find_meeting_conflict($pdo, $meetingDate, $meetingTime, $ignoreGroupKey)
{
    if (!$pdo || !cpms_tasks_table_exists($pdo, 'cpms_tasks')) return null;
    $meetingDate = trim((string)$meetingDate);
    $meetingTime = cpms_tasks_normalize_time_value($meetingTime);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $meetingDate) || $meetingTime === '') return null;
    $startTs = strtotime($meetingDate . ' ' . $meetingTime);
    if ($startTs === false) return null;
    $endTs = $startTs + (cpms_tasks_meeting_block_minutes() * 60);
    $ignoreGroupKey = trim((string)$ignoreGroupKey);

    try {
        $groupKeySelect = cpms_tasks_column_exists($pdo, 'cpms_tasks', 'group_key') ? 'group_key' : "'' AS group_key";
        $prevDate = date('Y-m-d', strtotime($meetingDate . ' -1 day'));
        $nextDate = date('Y-m-d', strtotime($meetingDate . ' +1 day'));
        $sql = "SELECT id,title,due_date,due_time,requester_name,assignee_name," . $groupKeySelect . " FROM cpms_tasks WHERE task_type='meeting' AND status='meeting_available' AND due_date IN (:due_date_prev,:due_date_current,:due_date_next)";
        $params = array(
            ':due_date_prev' => $prevDate,
            ':due_date_current' => $meetingDate,
            ':due_date_next' => $nextDate
        );
        if ($ignoreGroupKey !== '' && cpms_tasks_column_exists($pdo, 'cpms_tasks', 'group_key')) {
            $sql .= " AND (group_key IS NULL OR group_key='' OR group_key<>:ignore_group_key)";
            $params[':ignore_group_key'] = $ignoreGroupKey;
        }
        $sql .= " ORDER BY due_time ASC, id ASC";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) $rows = array();
        for ($i = 0; $i < count($rows); $i++) {
            $rowTime = isset($rows[$i]['due_time']) ? cpms_tasks_normalize_time_value($rows[$i]['due_time']) : '';
            if ($rowTime === '') continue;
            $rowDate = isset($rows[$i]['due_date']) ? trim((string)$rows[$i]['due_date']) : $meetingDate;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rowDate)) $rowDate = $meetingDate;
            $rowStartTs = strtotime($rowDate . ' ' . $rowTime);
            if ($rowStartTs === false) continue;
            $rowEndTs = $rowStartTs + (cpms_tasks_meeting_block_minutes() * 60);
            if ($startTs < $rowEndTs && $endTs > $rowStartTs) {
                return $rows[$i];
            }
        }
    } catch (Exception $e) {
        return null;
    }
    return null;
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
    if (in_array($status, array('meeting_available', 'meeting_unavailable'), true)) return false;
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
    $requestedTaskDate = isset($_GET['requested_task_date']) ? trim((string)$_GET['requested_task_date']) : '';
    if ($requestedTaskDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedTaskDate)) $url .= '&requested_task_date=' . urlencode($requestedTaskDate);
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
    static $cache = array();
    $rows = array();
    if (!$pdo) return $rows;
    $cacheKey = (function_exists('spl_object_hash') ? spl_object_hash($pdo) : 'nopdo') . ':active-employees';
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];
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
    $cache[$cacheKey] = $rows;
    return $rows;
}}

if (!function_exists('cpms_tasks_fetch_projects')) {
function cpms_tasks_fetch_projects($pdo)
{
    static $cache = array();
    $rows = array();
    if (!$pdo || !cpms_tasks_table_exists($pdo, 'cpms_projects')) return $rows;
    $cacheKey = (function_exists('spl_object_hash') ? spl_object_hash($pdo) : 'nopdo') . ':projects';
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];
    try {
        $st = $pdo->query("SELECT id, name FROM cpms_projects WHERE name NOT LIKE '(가제)%' ORDER BY id DESC");
        $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
    } catch (Exception $e) {
        $rows = array();
    }
    $cache[$cacheKey] = is_array($rows) ? $rows : array();
    return $cache[$cacheKey];
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

if (!function_exists('cpms_tasks_is_management_department')) {
function cpms_tasks_is_management_department($department)
{
    return cpms_tasks_normalize_department($department) === urldecode('%EA%B4%80%EB%A6%AC');
}}

if (!function_exists('cpms_tasks_fetch_management_employees')) {
function cpms_tasks_fetch_management_employees($pdo)
{
    $all = cpms_tasks_fetch_active_employees($pdo);
    $rows = array();
    for ($i = 0; $i < count($all); $i++) {
        if (cpms_tasks_is_management_department(isset($all[$i]['department']) ? $all[$i]['department'] : '')) {
            $rows[count($rows)] = $all[$i];
        }
    }
    return $rows;
}}

if (!function_exists('cpms_tasks_shared_group_key')) {
function cpms_tasks_shared_group_key($type, $baseDate)
{
    return 'unused_leave:' . trim((string)$type) . ':' . trim((string)$baseDate);
}}

if (!function_exists('cpms_tasks_should_sync_group_completion')) {
function cpms_tasks_should_sync_group_completion($groupKey)
{
    $groupKey = trim((string)$groupKey);
    if ($groupKey === '') return false;
    if (strpos($groupKey, 'unused_leave:') === 0) return true;
    if (strpos($groupKey, 'samsung_') === 0) return true;
    return false;
}}

if (!function_exists('cpms_tasks_unused_leave_title')) {
function cpms_tasks_unused_leave_title($type)
{
    if ($type === '2m10d') {
        return urldecode('%5B%EA%B8%B4%EA%B8%89%5D%202%EA%B0%9C%EC%9B%94%2010%EC%9D%BC%20%EC%A0%84%20%EB%AF%B8%EC%82%AC%EC%9A%A9%20%EC%97%B0%EC%B0%A8%20%EC%82%AC%EC%9A%A9%EC%B4%89%EA%B5%AC%EC%84%9C%20%EB%B0%9C%EC%86%A1');
    }
    return urldecode('%5B%EA%B8%B4%EA%B8%89%5D%206%EA%B0%9C%EC%9B%94%2010%EC%9D%BC%20%EC%A0%84%20%EB%AF%B8%EC%82%AC%EC%9A%A9%20%EC%97%B0%EC%B0%A8%20%EC%95%88%EB%82%B4%20%EB%AC%B8%EC%84%9C%20%EB%B0%9C%EC%86%A1');
}}

if (!function_exists('cpms_tasks_unused_leave_instruction')) {
function cpms_tasks_unused_leave_instruction($type)
{
    if ($type === '2m10d') {
        return urldecode('%EB%8C%80%EC%83%81%EC%9E%90%EC%97%90%EA%B2%8C%20%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%20%60%EB%AF%B8%EC%82%AC%EC%9A%A9%20%EC%97%B0%EC%B0%A8%20%EC%82%AC%EC%9A%A9%EC%B4%89%EA%B5%AC%EC%84%9C%60%EB%A5%BC%20%EB%B0%9C%EC%86%A1%ED%95%B4%20%EC%A3%BC%EC%84%B8%EC%9A%94.');
    }
    return urldecode('%EB%8C%80%EC%83%81%EC%9E%90%EC%97%90%EA%B2%8C%20%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%20%60%EB%AF%B8%EC%82%AC%EC%9A%A9%20%EC%97%B0%EC%B0%A8%20%EC%82%AC%EC%9A%A9%EC%B4%89%EA%B5%AC%EC%84%9C%60%EC%99%80%20%60%EB%AF%B8%EC%82%AC%EC%9A%A9%20%EC%97%B0%EC%B0%A8%20%EC%82%AC%EC%9A%A9%EA%B3%84%ED%9A%8D%EC%84%9C%60%EB%A5%BC%20%EB%B0%9C%EC%86%A1%ED%95%B4%20%EC%A3%BC%EC%84%B8%EC%9A%94.');
}}

if (!function_exists('cpms_tasks_unused_leave_candidate_info')) {
function cpms_tasks_unused_leave_candidate_info($pdo, $employee)
{
    $result = null;
    if (!$pdo || !is_array($employee)) {
        return $result;
    }

    $employeeId = isset($employee['id']) ? (int)$employee['id'] : 0;
    $balance = isset($employee['leave_annual_balance']) ? (float)$employee['leave_annual_balance'] : 0.0;
    if ($employeeId <= 0 || $balance <= 0) {
        return $result;
    }

    try {
        $st = $pdo->prepare("SELECT accrual_date FROM cpms_leave_accrual_logs WHERE employee_id=:employee_id AND leave_type='ANNUAL' AND accrual_date<=:today ORDER BY accrual_date DESC, id DESC LIMIT 1");
        $st->execute(array(
            ':employee_id' => $employeeId,
            ':today' => cpms_tasks_today()
        ));
        $accrualDate = cpms_leave_parse_date($st->fetchColumn());
        if ($accrualDate === '') {
            return $result;
        }
        $expiryDate = cpms_leave_add_days(cpms_leave_add_years_clamped($accrualDate, 1), -1);
        if ($expiryDate === '' || strcmp($expiryDate, cpms_tasks_today()) < 0) {
            return $result;
        }
        $trigger6 = cpms_leave_add_days(cpms_leave_add_months_clamped($expiryDate, -6), -10);
        $trigger2 = cpms_leave_add_days(cpms_leave_add_months_clamped($expiryDate, -2), -10);
        $today = cpms_tasks_today();
        $triggerType = '';
        if ($trigger6 !== '' && $today === $trigger6) {
            $triggerType = '6m10d';
        } else if ($trigger2 !== '' && $today === $trigger2) {
            $triggerType = '2m10d';
        }
        if ($triggerType === '') {
            return $result;
        }

        $result = array(
            'trigger_type' => $triggerType,
            'employee_id' => $employeeId,
            'name' => isset($employee['name']) ? (string)$employee['name'] : '',
            'department' => isset($employee['department']) ? (string)$employee['department'] : '',
            'position' => isset($employee['position']) ? (string)$employee['position'] : '',
            'annual_balance' => cpms_leave_normalize_half_step($balance),
            'annual_grant_date' => $accrualDate,
            'annual_expiry_date' => $expiryDate
        );
    } catch (Exception $e) {
        $result = null;
    }

    return $result;
}}

if (!function_exists('cpms_tasks_unused_leave_candidates')) {
function cpms_tasks_unused_leave_candidates($pdo)
{
    $result = array(
        '6m10d' => array(),
        '2m10d' => array()
    );
    if (
        !$pdo
        || !cpms_leave_table_exists($pdo, 'cpms_leave_accrual_logs')
        || !cpms_leave_column_exists($pdo, 'employees', 'hire_date')
        || !cpms_leave_column_exists($pdo, 'employees', 'leave_annual_balance')
    ) {
        return $result;
    }

    $positionColumn = cpms_tasks_column_exists($pdo, 'employees', 'position') ? 'position' : "'' AS position";
    $resignColumn = cpms_leave_column_exists($pdo, 'employees', 'resign_date') ? 'resign_date' : "NULL AS resign_date";
    try {
        $sql = "SELECT id,name,email,department," . $positionColumn . ",hire_date,leave_annual_balance,is_active," . $resignColumn . " FROM employees WHERE is_active=1 AND hire_date IS NOT NULL AND hire_date<>'' AND leave_annual_balance IS NOT NULL AND leave_annual_balance>0 ORDER BY name ASC";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = array();
        }
        for ($i = 0; $i < count($rows); $i++) {
            $resignDate = isset($rows[$i]['resign_date']) ? cpms_leave_parse_date($rows[$i]['resign_date']) : '';
            if ($resignDate !== '' && strcmp($resignDate, cpms_tasks_today()) < 0) {
                continue;
            }
            $one = cpms_tasks_unused_leave_candidate_info($pdo, $rows[$i]);
            if (!$one || !isset($one['trigger_type'])) {
                continue;
            }
            $result[$one['trigger_type']][count($result[$one['trigger_type']])] = $one;
        }
    } catch (Exception $e) {
        return $result;
    }

    return $result;
}}

if (!function_exists('cpms_tasks_unused_leave_content')) {
function cpms_tasks_unused_leave_content($type, $targets)
{
    $lines = array();
    $lines[] = cpms_tasks_unused_leave_instruction($type);
    $lines[] = '';
    $lines[] = urldecode('%EB%8C%80%EC%83%81%20%EC%9D%B8%EC%9B%90');
    for ($i = 0; $i < count($targets); $i++) {
        $one = $targets[$i];
        $parts = array();
        $parts[] = isset($one['name']) ? (string)$one['name'] : '';
        if (isset($one['department']) && trim((string)$one['department']) !== '') {
            $parts[] = trim((string)$one['department']);
        }
        if (isset($one['position']) && trim((string)$one['position']) !== '') {
            $parts[] = trim((string)$one['position']);
        }
        $label = implode(' / ', $parts);
        $lines[] = '- ' . $label
            . ' | ' . urldecode('%EC%9E%94%EC%97%AC') . ' ' . cpms_leave_format_decimal(isset($one['annual_balance']) ? $one['annual_balance'] : 0)
            . urldecode('%EC%9D%BC')
            . ' | ' . urldecode('%EB%B0%9C%ED%96%89%EC%9D%BC') . ' ' . (isset($one['annual_grant_date']) ? $one['annual_grant_date'] : '-')
            . ' | ' . urldecode('%EB%A7%8C%EB%A3%8C%EC%9D%BC') . ' ' . (isset($one['annual_expiry_date']) ? $one['annual_expiry_date'] : '-');
    }
    return implode("\n", $lines);
}}

if (!function_exists('cpms_tasks_sync_shared_group_task')) {
function cpms_tasks_sync_shared_group_task($pdo, $assignee, $groupKey, $title, $content)
{
    if (!$pdo || !is_array($assignee) || trim((string)$groupKey) === '') {
        return false;
    }
    $assigneeId = isset($assignee['id']) ? (int)$assignee['id'] : 0;
    if ($assigneeId <= 0) {
        return false;
    }

    $now = cpms_tasks_now();
    $existing = null;
    try {
        $st = $pdo->prepare("SELECT * FROM cpms_tasks WHERE assignee_employee_id=:assignee_employee_id AND group_key=:group_key ORDER BY id DESC LIMIT 1");
        $st->execute(array(
            ':assignee_employee_id' => $assigneeId,
            ':group_key' => $groupKey
        ));
        $existing = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $existing = null;
    }

    if ($existing) {
        if (isset($existing['status']) && in_array((string)$existing['status'], array('done', 'cancelled'), true)) {
            return true;
        }
        try {
            $st = $pdo->prepare("UPDATE cpms_tasks SET title=:title, content=:content, due_date=:due_date, due_time=:due_time, priority='urgent', is_urgent=1, task_type='admin', updated_at=:updated_at WHERE id=:id");
            $st->execute(array(
                ':title' => $title,
                ':content' => $content,
                ':due_date' => cpms_tasks_today(),
                ':due_time' => '18:00:00',
                ':updated_at' => $now,
                ':id' => (int)$existing['id']
            ));
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    try {
        $st = $pdo->prepare("INSERT INTO cpms_tasks (title, content, requester_employee_id, requester_name, requester_email, assignee_employee_id, assignee_name, assignee_email, department, task_type, priority, is_urgent, due_date, due_time, status, created_by, created_at, updated_at, group_key) VALUES (:title, :content, NULL, :requester_name, NULL, :assignee_employee_id, :assignee_name, :assignee_email, :department, 'admin', 'urgent', 1, :due_date, :due_time, 'pending', NULL, :created_at, :updated_at, :group_key)");
        $st->execute(array(
            ':title' => $title,
            ':content' => $content,
            ':requester_name' => urldecode('CPMS%20%EC%9E%90%EB%8F%99%EC%95%88%EB%82%B4'),
            ':assignee_employee_id' => $assigneeId,
            ':assignee_name' => isset($assignee['name']) ? (string)$assignee['name'] : '',
            ':assignee_email' => isset($assignee['email']) ? (string)$assignee['email'] : '',
            ':department' => urldecode('%EA%B4%80%EB%A6%AC'),
            ':due_date' => cpms_tasks_today(),
            ':due_time' => '18:00:00',
            ':created_at' => $now,
            ':updated_at' => $now,
            ':group_key' => $groupKey
        ));
        $taskId = (int)$pdo->lastInsertId();
        if ($taskId > 0) {
            cpms_tasks_insert_log($pdo, $taskId, array(
                'id' => 0,
                'name' => urldecode('CPMS%20%EC%9E%90%EB%8F%99%EC%95%88%EB%82%B4')
            ), 'created', $content, null, 'pending');
        }
        return true;
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_tasks_bootstrap_automations')) {
function cpms_tasks_bootstrap_automations($pdo)
{
    static $bootstrapped = false;
    if ($bootstrapped) {
        return;
    }
    $bootstrapped = true;

    if (!$pdo || !cpms_tasks_table_exists($pdo, 'cpms_tasks')) {
        return;
    }

    if (!cpms_tasks_column_exists($pdo, 'cpms_tasks', 'group_key')) {
        $results = array();
        cpms_tasks_ensure_schema($pdo, $results);
    }
    if (!cpms_tasks_column_exists($pdo, 'cpms_tasks', 'group_key')) {
        return;
    }

    $safetyHelperPath = dirname(__DIR__) . '/safety/safety_cost_helper.php';
    if (is_file($safetyHelperPath)) {
        require_once $safetyHelperPath;
    }
    if (function_exists('cpms_samsung_portal_bootstrap_automations')) {
        cpms_samsung_portal_bootstrap_automations($pdo, false);
    }

    $targetsByType = cpms_tasks_unused_leave_candidates($pdo);
    $managers = cpms_tasks_fetch_management_employees($pdo);
    if (count($managers) === 0) {
        return;
    }

    $types = array('6m10d', '2m10d');
    for ($i = 0; $i < count($types); $i++) {
        $type = $types[$i];
        if (!isset($targetsByType[$type]) || count($targetsByType[$type]) === 0) {
            continue;
        }
        $groupKey = cpms_tasks_shared_group_key($type, cpms_tasks_today());
        $title = cpms_tasks_unused_leave_title($type);
        $content = cpms_tasks_unused_leave_content($type, $targetsByType[$type]);
        for ($j = 0; $j < count($managers); $j++) {
            cpms_tasks_sync_shared_group_task($pdo, $managers[$j], $groupKey, $title, $content);
        }
    }
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

if (!function_exists('cpms_tasks_can_respond_meeting')) {
function cpms_tasks_can_respond_meeting($task, $currentEmployeeId)
{
    if (!$task || (int)$currentEmployeeId <= 0) return false;
    if (!isset($task['task_type']) || (string)$task['task_type'] !== 'meeting') return false;
    if (!isset($task['assignee_employee_id']) || (int)$task['assignee_employee_id'] !== (int)$currentEmployeeId) return false;
    if (isset($task['requester_employee_id']) && (int)$task['requester_employee_id'] === (int)$currentEmployeeId) return false;
    return true;
}}

if (!function_exists('cpms_tasks_can_complete_meeting_after_response')) {
function cpms_tasks_can_complete_meeting_after_response($task, $currentEmployeeId)
{
    if (!$task || (int)$currentEmployeeId <= 0) return false;
    if (!isset($task['task_type']) || (string)$task['task_type'] !== 'meeting') return false;
    if (!isset($task['assignee_employee_id']) || (int)$task['assignee_employee_id'] !== (int)$currentEmployeeId) return false;
    $status = isset($task['status']) ? (string)$task['status'] : '';
    return in_array($status, array('meeting_available', 'meeting_unavailable'), true);
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
                'file_size' => isset($sizes[$i]) ? (int)$sizes[$i] : 0,
                'mime_type' => isset($types[$i]) ? (string)$types[$i] : '',
            );
        } catch (Exception $e) {
        }
    }

    return $saved;
}}

if (!function_exists('cpms_tasks_copy_saved_files_to_task')) {
function cpms_tasks_copy_saved_files_to_task($pdo, $sourceFiles, $targetTaskId, $uploadedBy)
{
    $copied = array();
    if (!$pdo || (int)$targetTaskId <= 0 || !is_array($sourceFiles) || !cpms_tasks_table_exists($pdo, 'cpms_task_files')) return $copied;
    $targetDir = cpms_tasks_upload_abs_dir($targetTaskId);
    if (!cpms_ensure_dir($targetDir)) return $copied;

    for ($i = 0; $i < count($sourceFiles); $i++) {
        $file = $sourceFiles[$i];
        if (!is_array($file)) continue;
        $storedName = isset($file['stored_name']) ? trim((string)$file['stored_name']) : '';
        $storedPath = isset($file['stored_path']) ? ltrim(str_replace('\\', '/', (string)$file['stored_path']), '/') : '';
        if ($storedName === '' || $storedPath === '') continue;

        $sourcePath = cpms_tasks_public_root() . '/' . $storedPath;
        if (!is_file($sourcePath)) continue;

        $relativePath = cpms_tasks_upload_relative_dir($targetTaskId) . '/' . $storedName;
        $targetPath = $targetDir . '/' . $storedName;
        if (!@copy($sourcePath, $targetPath)) continue;

        $fileSize = @filesize($targetPath);
        if ($fileSize === false) $fileSize = isset($file['file_size']) ? (int)$file['file_size'] : 0;
        $mimeType = isset($file['mime_type']) ? (string)$file['mime_type'] : '';
        $originalName = isset($file['original_name']) ? (string)$file['original_name'] : $storedName;

        try {
            $st = $pdo->prepare("INSERT INTO cpms_task_files (task_id, original_name, stored_name, stored_path, file_size, mime_type, uploaded_by, uploaded_at) VALUES (:task_id, :original_name, :stored_name, :stored_path, :file_size, :mime_type, :uploaded_by, :uploaded_at)");
            $st->execute(array(
                ':task_id' => (int)$targetTaskId,
                ':original_name' => $originalName,
                ':stored_name' => $storedName,
                ':stored_path' => $relativePath,
                ':file_size' => (int)$fileSize,
                ':mime_type' => $mimeType,
                ':uploaded_by' => (int)$uploadedBy > 0 ? (int)$uploadedBy : null,
                ':uploaded_at' => cpms_tasks_now(),
            ));
            $copied[count($copied)] = array(
                'original_name' => $originalName,
                'stored_name' => $storedName,
                'stored_path' => $relativePath,
                'file_size' => (int)$fileSize,
                'mime_type' => $mimeType,
            );
        } catch (Exception $e) {
        }
    }

    return $copied;
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
    $isMeeting = isset($task['task_type']) && (string)$task['task_type'] === 'meeting';
    $lines[count($lines)] = $isMeeting ? '[CPMS 회의요청]' : '[CPMS 업무요청]';
    $lines[count($lines)] = '제목: ' . (isset($task['title']) ? (string)$task['title'] : '-');
    $lines[count($lines)] = '요청자: ' . (isset($task['requester_name']) ? (string)$task['requester_name'] : '-');
    $lines[count($lines)] = '담당자: ' . (isset($task['assignee_name']) ? (string)$task['assignee_name'] : '-');
    $dueLine = '-';
    if (!empty($task['due_date'])) {
        $dueLine = (string)$task['due_date'];
        if (!empty($task['due_time'])) $dueLine .= ' ' . substr((string)$task['due_time'], 0, 5);
    }
    $lines[count($lines)] = ($isMeeting ? '일시: ' : '기한: ') . $dueLine;
    $lines[count($lines)] = '중요도: ' . cpms_tasks_priority_label(isset($task['priority']) ? $task['priority'] : 'normal');
    $lines[count($lines)] = '내용: ' . cpms_tasks_text_excerpt(isset($task['content']) ? $task['content'] : '', 100);
    $lines[count($lines)] = '대시보드 나의 할일에서 확인해주세요.';
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

if (!function_exists('cpms_tasks_build_meeting_confirmed_message')) {
function cpms_tasks_build_meeting_confirmed_message($task)
{
    $lines = array();
    $lines[count($lines)] = '[CPMS 미팅확정]';
    $lines[count($lines)] = '제목: ' . (isset($task['title']) ? (string)$task['title'] : '-');
    $lines[count($lines)] = '참석자: ' . (isset($task['assignee_name']) ? (string)$task['assignee_name'] : '-');
    $lines[count($lines)] = '일시: ' . cpms_tasks_meeting_time_text(isset($task['due_date']) ? $task['due_date'] : '', isset($task['due_time']) ? $task['due_time'] : '');
    if (isset($task['content']) && trim((string)$task['content']) !== '') {
        $lines[count($lines)] = '내용: ' . cpms_tasks_text_excerpt($task['content'], 100);
    }
    $lines[count($lines)] = '대시보드 내가 요청한 업무에서 확인해주세요.';
    return implode("\n", $lines);
}}

if (!function_exists('cpms_tasks_send_created_notification')) {
function cpms_tasks_send_created_notification($pdo, $task)
{
    $taskId = isset($task['id']) ? (int)$task['id'] : 0;
    try {
        if (!$pdo || !is_array($task) || $taskId <= 0) return false;
        if (cpms_tasks_column_exists($pdo, 'cpms_tasks', 'chat_notified_at')) {
            $alreadyNotifiedAt = isset($task['chat_notified_at']) ? trim((string)$task['chat_notified_at']) : '';
            if ($alreadyNotifiedAt !== '') return false;
        }
        if (!function_exists('cpms_send_google_chat_to_employee')) {
            require_once dirname(dirname(__DIR__)) . '/helpers.php';
        }
        if (!function_exists('cpms_send_google_chat_to_employee')) {
            error_log('[task_google_chat] send failed task_id=' . $taskId . ' helper missing');
            return false;
        }
        $assigneeId = isset($task['assignee_employee_id']) ? (int)$task['assignee_employee_id'] : 0;
        if ($assigneeId <= 0) {
            error_log('[task_google_chat] send failed task_id=' . $taskId . ' assignee missing');
            return false;
        }

        $ok = cpms_send_google_chat_to_employee($pdo, $assigneeId, cpms_tasks_build_create_message($task), $taskId, 'TASK_CREATED', 'TASK');
        if ($ok) {
            if (cpms_tasks_column_exists($pdo, 'cpms_tasks', 'chat_notified_at')) {
                $st = $pdo->prepare("UPDATE cpms_tasks SET chat_notified_at = :chat_notified_at WHERE id = :id AND chat_notified_at IS NULL");
                $st->execute(array(
                    ':chat_notified_at' => cpms_tasks_now(),
                    ':id' => $taskId,
                ));
            }
        } else {
            error_log('[task_google_chat] send failed task_id=' . $taskId);
        }
        return (bool)$ok;
    } catch (Exception $e) {
        error_log('[task_google_chat] send failed task_id=' . $taskId . ' error=' . $e->getMessage());
        return false;
    }
}}

if (!function_exists('cpms_tasks_send_meeting_confirmed_notification')) {
function cpms_tasks_send_meeting_confirmed_notification($pdo, $task)
{
    if (!function_exists('cpms_send_google_chat_to_employee')) {
        require_once dirname(dirname(__DIR__)) . '/helpers.php';
    }
    if (!function_exists('cpms_send_google_chat_to_employee')) return false;
    if (!$pdo || !is_array($task)) return false;
    $requesterId = isset($task['requester_employee_id']) ? (int)$task['requester_employee_id'] : 0;
    $assigneeId = isset($task['assignee_employee_id']) ? (int)$task['assignee_employee_id'] : 0;
    if ($requesterId <= 0 || $requesterId === $assigneeId) return false;
    return cpms_send_google_chat_to_employee($pdo, $requesterId, cpms_tasks_build_meeting_confirmed_message($task), isset($task['id']) ? (int)$task['id'] : 0, 'MEETING_CONFIRMED', 'TASK');
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
            updated_at DATETIME NULL,
            chat_notified_at DATETIME NULL
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
            'chat_notified_at' => "ALTER TABLE cpms_tasks ADD COLUMN chat_notified_at DATETIME NULL AFTER updated_at",
            'group_key' => "ALTER TABLE cpms_tasks ADD COLUMN group_key VARCHAR(190) NULL AFTER chat_notified_at",
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
            'idx_group_key' => "ALTER TABLE cpms_tasks ADD INDEX idx_group_key (group_key)",
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
