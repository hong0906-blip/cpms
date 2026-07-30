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
    if (isset($cache[$cacheKey]) && $cache[$cacheKey]) return $cache[$cacheKey];
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
    if (isset($cache[$cacheKey]) && $cache[$cacheKey]) return $cache[$cacheKey];
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
        '공사팀' => '공사',
        '공무부' => '공무',
        '공무팀' => '공무',
        '안전부' => '안전',
        '안전팀' => '안전',
        '관리부' => '관리',
        '관리팀' => '관리',
        '품질부' => '품질',
        '품질팀' => '품질',
        '개발부' => '개발',
        '개발팀' => '개발',
        '보건부' => '보건',
        '보건팀' => '보건',
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
    return array('임원', '공사', '공무', '안전', '관리', '품질', '개발', '기타');
}}

if (!function_exists('cpms_tasks_employee_department')) {
function cpms_tasks_employee_department($employee)
{
    if (!is_array($employee)) return cpms_tasks_normalize_department('');
    $role = trim(isset($employee['role']) ? (string)$employee['role'] : '');
    if ($role === 'executive') return '임원';
    return cpms_tasks_normalize_department(isset($employee['department']) ? $employee['department'] : '');
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
        'completion_pending' => '완료 대기중',
        'meeting_owner' => '회의요청',
        'meeting_available' => '참석가능',
        'meeting_unavailable' => '참석불가능',
        'delayed' => '지연',
        'revision' => '보완요청',
        'cancelled' => '취소',
        'created' => '등록',
        'status_changed' => '상태 변경',
        'completed' => '완료 처리',
        'completion_requested' => '완료 검토 요청',
        'completion_approved' => '완료 승인',
        'completion_rejected' => '완료 반려',
        'due_date_changed' => '마감 변경',
        'request_read' => '요청 읽음',
        'transfer_requested' => '담당자 변경 요청',
        'transfer_approved' => '담당자 변경 승인',
        'transferred' => '담당자 변경',
        'meeting_available_action' => '참석가능',
        'meeting_unavailable_action' => '참석불가능',
        'revision_requested' => '보완요청',
        'commented' => '메모',
        'priority_changed' => '중요도 변경',
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
        if ($value === 'completion_pending') return 'bg-amber-50 text-amber-700 border-amber-200';
        if ($value === 'meeting_available') return 'bg-emerald-50 text-emerald-700 border-emerald-200';
        if ($value === 'meeting_unavailable') return 'bg-rose-50 text-rose-700 border-rose-200';
        if ($value === 'meeting_owner') return 'bg-blue-50 text-blue-700 border-blue-200';
        if ($value === 'progress') return 'bg-blue-50 text-blue-700 border-blue-200';
        if ($value === 'revision') return 'bg-amber-50 text-amber-700 border-amber-200';
        if ($value === 'cancelled') return 'bg-slate-100 text-slate-600 border-slate-200';
        if ($value === 'rejected') return 'bg-rose-50 text-rose-700 border-rose-200';
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
    if (in_array($status, array('completion_pending', 'meeting_owner', 'meeting_available', 'meeting_unavailable'), true)) return false;
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
    $url = ($dashboardType === 'executive') ? '?r=dashboard_executive' : '?r=dashboard_employee';
    if ($dashboardType === 'executive') {
        $execTab = isset($_GET['exec_tab']) ? trim((string)$_GET['exec_tab']) : '';
        if ($execTab !== '') $url .= '&exec_tab=' . urlencode($execTab);
    }
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
        'photo_path' => '',
    );
    $result['department'] = cpms_tasks_employee_department($result);
    if (!$pdo || $result['email'] === '') return $result;

    $columns = array('id', 'name', 'email');
    $columns[count($columns)] = cpms_tasks_column_exists($pdo, 'employees', 'department') ? 'department' : "'' AS department";
    $columns[count($columns)] = cpms_tasks_column_exists($pdo, 'employees', 'position') ? 'position' : "'' AS position";
    $columns[count($columns)] = cpms_tasks_column_exists($pdo, 'employees', 'role') ? 'role' : "'employee' AS role";
    $columns[count($columns)] = cpms_tasks_column_exists($pdo, 'employees', 'photo_path') ? 'photo_path' : "'' AS photo_path";
    try {
        $st = $pdo->prepare("SELECT " . implode(', ', $columns) . " FROM employees WHERE LOWER(email) = LOWER(:email) LIMIT 1");
        $st->execute(array(':email' => $result['email']));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $result['id'] = isset($row['id']) ? (int)$row['id'] : 0;
            $result['name'] = isset($row['name']) ? (string)$row['name'] : $result['name'];
            $result['position'] = isset($row['position']) ? (string)$row['position'] : $result['position'];
            $result['role'] = isset($row['role']) ? (string)$row['role'] : $result['role'];
            $result['department'] = cpms_tasks_employee_department(array(
                'department' => isset($row['department']) ? $row['department'] : $result['department'],
                'role' => $result['role'],
            ));
            $result['photo_path'] = isset($row['photo_path']) ? (string)$row['photo_path'] : '';
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
        $rows[$index]['department'] = cpms_tasks_employee_department($row);
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
    $roleColumn = cpms_tasks_column_exists($pdo, 'employees', 'role') ? 'role' : "'employee' AS role";
    $photoColumn = cpms_tasks_column_exists($pdo, 'employees', 'photo_path') ? 'photo_path' : "'' AS photo_path";
    try {
        $st = $pdo->prepare("SELECT id, name, email, " . $departmentColumn . ", " . $positionColumn . ", " . $roleColumn . ", " . $photoColumn . " FROM employees WHERE id = :id LIMIT 1");
        $st->execute(array(':id' => $employeeId));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['department'] = cpms_tasks_employee_department($row);
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
    if (strpos($groupKey, 'task_request:') === 0) return false;
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
    if (cpms_tasks_effective_requester_employee_id($task) === (int)$currentEmployeeId) return true;
    return false;
}}

if (!function_exists('cpms_tasks_effective_requester_employee_id')) {
function cpms_tasks_effective_requester_employee_id($task)
{
    if (!$task || !is_array($task)) return 0;
    $requesterId = isset($task['requester_employee_id']) ? (int)$task['requester_employee_id'] : 0;
    if ($requesterId > 0) return $requesterId;
    return isset($task['created_by']) ? (int)$task['created_by'] : 0;
}}

if (!function_exists('cpms_tasks_can_change_status')) {
function cpms_tasks_can_change_status($task, $currentEmployeeId)
{
    if (!$task) return false;
    if (cpms_tasks_has_transfer_request($task)) return false;
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
    return ((int)$currentEmployeeId > 0 && cpms_tasks_effective_requester_employee_id($task) === (int)$currentEmployeeId);
}}

if (!function_exists('cpms_tasks_is_self_request')) {
function cpms_tasks_is_self_request($task)
{
    if (!$task) return false;
    $requesterId = cpms_tasks_effective_requester_employee_id($task);
    $assigneeId = isset($task['assignee_employee_id']) ? (int)$task['assignee_employee_id'] : 0;
    return ($requesterId > 0 && $requesterId === $assigneeId);
}}

if (!function_exists('cpms_tasks_can_submit_completion')) {
function cpms_tasks_can_submit_completion($task, $currentEmployeeId)
{
    if (!$task) return false;
    if (cpms_tasks_has_transfer_request($task)) return false;
    if (cpms_tasks_is_overall_manager()) return true;
    return ((int)$currentEmployeeId > 0 && isset($task['assignee_employee_id']) && (int)$task['assignee_employee_id'] === (int)$currentEmployeeId);
}}

if (!function_exists('cpms_tasks_can_approve_completion')) {
function cpms_tasks_can_approve_completion($task, $currentEmployeeId)
{
    if (!$task) return false;
    $status = isset($task['status']) ? (string)$task['status'] : '';
    if ($status !== 'completion_pending') return false;
    if (cpms_tasks_is_overall_manager()) return true;
    return ((int)$currentEmployeeId > 0 && cpms_tasks_effective_requester_employee_id($task) === (int)$currentEmployeeId);
}}

if (!function_exists('cpms_tasks_is_request_group_key')) {
function cpms_tasks_is_request_group_key($groupKey)
{
    $groupKey = trim((string)$groupKey);
    return ($groupKey !== '' && strpos($groupKey, 'task_request:') === 0);
}}

if (!function_exists('cpms_tasks_completion_group_summary')) {
function cpms_tasks_completion_group_summary($pdo, $task)
{
    $result = array(
        'rows' => array(),
        'total_count' => 0,
        'completion_pending_count' => 0,
        'done_count' => 0,
        'all_completion_pending' => false,
        'ready_for_approval' => false,
    );
    if (!$pdo || !is_array($task)) return $result;

    $groupKey = isset($task['group_key']) ? trim((string)$task['group_key']) : '';
    if (cpms_tasks_is_request_group_key($groupKey) && cpms_tasks_column_exists($pdo, 'cpms_tasks', 'group_key')) {
        try {
            $st = $pdo->prepare("SELECT * FROM cpms_tasks WHERE group_key = :group_key AND (status IS NULL OR status <> 'cancelled') ORDER BY id ASC");
            $st->execute(array(':group_key' => $groupKey));
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (is_array($rows)) $result['rows'] = $rows;
        } catch (Exception $e) {
            $result['rows'] = array();
        }
    } else {
        $result['rows'][0] = $task;
    }

    for ($i = 0; $i < count($result['rows']); $i++) {
        $status = isset($result['rows'][$i]['status']) ? (string)$result['rows'][$i]['status'] : '';
        $result['total_count']++;
        if ($status === 'completion_pending') $result['completion_pending_count']++;
        if ($status === 'done') $result['done_count']++;
    }
    $result['all_completion_pending'] = ($result['total_count'] > 0 && $result['completion_pending_count'] === $result['total_count']);
    $result['ready_for_approval'] = (
        $result['total_count'] > 0
        && $result['completion_pending_count'] > 0
        && ($result['completion_pending_count'] + $result['done_count']) === $result['total_count']
    );
    return $result;
}}

if (!function_exists('cpms_tasks_can_approve_group_completion')) {
function cpms_tasks_can_approve_group_completion($pdo, $task, $currentEmployeeId)
{
    if (!$pdo || !is_array($task) || (int)$currentEmployeeId <= 0) return false;
    if (!cpms_tasks_is_overall_manager() && cpms_tasks_effective_requester_employee_id($task) !== (int)$currentEmployeeId) return false;
    $summary = cpms_tasks_completion_group_summary($pdo, $task);
    return isset($summary['ready_for_approval']) && $summary['ready_for_approval'];
}}

if (!function_exists('cpms_tasks_can_reject_completion')) {
function cpms_tasks_can_reject_completion($task, $currentEmployeeId)
{
    return cpms_tasks_can_approve_completion($task, $currentEmployeeId);
}}

if (!function_exists('cpms_tasks_can_update_due_date')) {
function cpms_tasks_can_update_due_date($task, $currentEmployeeId)
{
    if (!$task) return false;
    if (cpms_tasks_is_overall_manager()) return true;
    return ((int)$currentEmployeeId > 0 && cpms_tasks_effective_requester_employee_id($task) === (int)$currentEmployeeId);
}}

if (!function_exists('cpms_tasks_can_update_content')) {
function cpms_tasks_can_update_content($task, $currentEmployeeId)
{
    if (!$task) return false;
    if (cpms_tasks_is_overall_manager()) return true;
    return ((int)$currentEmployeeId > 0 && cpms_tasks_effective_requester_employee_id($task) === (int)$currentEmployeeId);
}}

if (!function_exists('cpms_tasks_can_cancel')) {
function cpms_tasks_can_cancel($task, $currentEmployeeId)
{
    if (!$task) return false;
    if (cpms_tasks_is_overall_manager()) return true;
    return ((int)$currentEmployeeId > 0 && cpms_tasks_effective_requester_employee_id($task) === (int)$currentEmployeeId);
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

if (!function_exists('cpms_tasks_insert_comment')) {
function cpms_tasks_insert_comment($pdo, $taskId, $actor, $commentText, $parentCommentId)
{
    if (!$pdo || (int)$taskId <= 0 || trim((string)$commentText) === '') return false;
    if (!cpms_tasks_ensure_comment_schema($pdo)) return false;
    $actorId = is_array($actor) && isset($actor['id']) ? (int)$actor['id'] : 0;
    $actorName = is_array($actor) && isset($actor['name']) ? (string)$actor['name'] : '';
    $actorEmail = is_array($actor) && isset($actor['email']) ? (string)$actor['email'] : '';
    $actorPhoto = is_array($actor) && isset($actor['photo_path']) ? (string)$actor['photo_path'] : '';
    try {
        $st = $pdo->prepare("INSERT INTO cpms_task_comments
            (task_id, parent_comment_id, comment_text, created_by, created_by_name, created_by_email, created_by_photo_path, created_at)
            VALUES (:task_id, :parent_comment_id, :comment_text, :created_by, :created_by_name, :created_by_email, :created_by_photo_path, :created_at)");
        $st->bindValue(':task_id', (int)$taskId, PDO::PARAM_INT);
        if ((int)$parentCommentId > 0) $st->bindValue(':parent_comment_id', (int)$parentCommentId, PDO::PARAM_INT);
        else $st->bindValue(':parent_comment_id', null, PDO::PARAM_NULL);
        $st->bindValue(':comment_text', (string)$commentText, PDO::PARAM_STR);
        if ($actorId > 0) $st->bindValue(':created_by', $actorId, PDO::PARAM_INT);
        else $st->bindValue(':created_by', null, PDO::PARAM_NULL);
        $st->bindValue(':created_by_name', $actorName, PDO::PARAM_STR);
        $st->bindValue(':created_by_email', $actorEmail, PDO::PARAM_STR);
        $st->bindValue(':created_by_photo_path', $actorPhoto, PDO::PARAM_STR);
        $st->bindValue(':created_at', cpms_tasks_now(), PDO::PARAM_STR);
        return $st->execute();
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_tasks_drive_helper_loaded')) {
function cpms_tasks_drive_helper_loaded()
{
    if (function_exists('cpms_drive_upload_file')) return true;
    $path = dirname(dirname(__DIR__)) . '/services/GoogleDriveHelper.php';
    if (!is_file($path)) return false;
    require_once $path;
    return function_exists('cpms_drive_upload_file');
}}

if (!function_exists('cpms_tasks_drive_label')) {
function cpms_tasks_drive_label($key)
{
    $labels = array(
        'root' => '%30%35%5F%EB%82%98%EC%9D%98%ED%95%A0%EC%9D%BC',
        'received' => '%EB%B0%9B%EC%9D%80%EC%9A%94%EC%B2%AD',
        'completed' => '%EC%99%84%EB%A3%8C',
        'unknown_employee' => '%EB%AF%B8%EC%A7%80%EC%A0%95',
        'drive_failed_notice' => '%ED%8C%8C%EC%9D%BC%EC%9D%80%20%EC%A0%80%EC%9E%A5%EB%90%98%EC%97%88%EC%A7%80%EB%A7%8C%20Google%20Drive%20%EC%97%85%EB%A1%9C%EB%93%9C%EC%97%90%20%EC%8B%A4%ED%8C%A8%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.'
    );
    return isset($labels[$key]) ? urldecode($labels[$key]) : (string)$key;
}}

if (!function_exists('cpms_tasks_drive_file_role')) {
function cpms_tasks_drive_file_role($fileRole)
{
    $fileRole = trim((string)$fileRole);
    return $fileRole === 'complete' ? 'complete' : 'request';
}}

if (!function_exists('cpms_tasks_drive_stage_label')) {
function cpms_tasks_drive_stage_label($fileRole)
{
    return cpms_tasks_drive_file_role($fileRole) === 'complete' ? cpms_tasks_drive_label('completed') : cpms_tasks_drive_label('received');
}}

if (!function_exists('cpms_tasks_drive_month_name')) {
function cpms_tasks_drive_month_name($dateValue)
{
    $dateValue = trim((string)$dateValue);
    $ts = $dateValue !== '' ? strtotime($dateValue) : false;
    if ($ts === false) $ts = time();
    return date('Y-m', $ts);
}}

if (!function_exists('cpms_tasks_drive_employee_for_file')) {
function cpms_tasks_drive_employee_for_file($pdo, $task, $uploadedBy, $fileRole)
{
    $fileRole = cpms_tasks_drive_file_role($fileRole);
    $employee = null;
    if ($fileRole === 'complete' && (int)$uploadedBy > 0) {
        $employee = cpms_tasks_find_employee_by_id($pdo, (int)$uploadedBy);
    }
    if (!$employee && is_array($task) && isset($task['assignee_employee_id']) && (int)$task['assignee_employee_id'] > 0) {
        $employee = cpms_tasks_find_employee_by_id($pdo, (int)$task['assignee_employee_id']);
    }
    if (!$employee && is_array($task)) {
        $employee = array(
            'id' => isset($task['assignee_employee_id']) ? (int)$task['assignee_employee_id'] : 0,
            'name' => isset($task['assignee_name']) ? (string)$task['assignee_name'] : '',
            'email' => isset($task['assignee_email']) ? (string)$task['assignee_email'] : ''
        );
    }
    if (!is_array($employee)) $employee = array('id' => 0, 'name' => cpms_tasks_drive_label('unknown_employee'), 'email' => '');
    if (!isset($employee['name']) || trim((string)$employee['name']) === '') $employee['name'] = cpms_tasks_drive_label('unknown_employee');
    return $employee;
}}

if (!function_exists('cpms_tasks_drive_failed_record')) {
function cpms_tasks_drive_failed_record($message, $fileRole)
{
    return array(
        'storage_type' => 'local',
        'drive_name' => '',
        'drive_file_id' => '',
        'drive_folder_id' => '',
        'drive_web_view_link' => '',
        'drive_web_content_link' => '',
        'drive_root_folder_id' => '',
        'drive_employee_folder_id' => '',
        'drive_month_folder_id' => '',
        'drive_stage_folder_id' => '',
        'file_role' => cpms_tasks_drive_file_role($fileRole),
        'upload_status' => trim((string)$message) === '' ? 'local_saved' : 'failed',
        'drive_upload_error' => trim((string)$message)
    );
}}

if (!function_exists('cpms_tasks_drive_ensure_folder')) {
function cpms_tasks_drive_ensure_folder($name, $parentId, $context)
{
    $folder = cpms_drive_find_or_create_folder($name, $parentId, $context);
    if (empty($folder['ok']) || !isset($folder['file']) || !is_array($folder['file']) || !isset($folder['file']['id'])) {
        return array(
            'ok' => false,
            'folder_id' => '',
            'message' => isset($folder['message']) ? (string)$folder['message'] : 'Drive folder prepare failed.',
            'http_code' => isset($folder['http_code']) ? (int)$folder['http_code'] : 0
        );
    }
    return array(
        'ok' => true,
        'folder_id' => (string)$folder['file']['id'],
        'message' => isset($folder['message']) ? (string)$folder['message'] : '',
        'http_code' => isset($folder['http_code']) ? (int)$folder['http_code'] : 0
    );
}}

if (!function_exists('cpms_tasks_drive_ensure_target_folder')) {
function cpms_tasks_drive_ensure_target_folder($pdo, $task, $uploadedBy, $fileRole, $originalName)
{
    if (!cpms_tasks_drive_helper_loaded()) {
        return array('ok' => false, 'message' => 'Google Drive helper is not available.', 'http_code' => 0);
    }
    if (function_exists('cpms_drive_config') && cpms_drive_config('enabled') === false) {
        return array('ok' => false, 'message' => 'Google Drive integration is disabled.', 'http_code' => 0);
    }

    $configuredRoot = cpms_drive_folder_id('my_tasks');
    $configuredParent = cpms_drive_folder_id('my_tasks_parent');
    $parentId = $configuredRoot !== '' ? $configuredRoot : $configuredParent;
    if ($parentId === '' && function_exists('cpms_drive_shared_drive_id')) $parentId = cpms_drive_shared_drive_id();
    if ($parentId === '') $parentId = cpms_drive_folder_id('common_documents');
    if ($parentId === '') {
        return array('ok' => false, 'message' => 'My tasks Drive parent folder is not configured.', 'http_code' => 0);
    }

    $taskId = is_array($task) && isset($task['id']) ? (int)$task['id'] : 0;
    $fileRole = cpms_tasks_drive_file_role($fileRole);
    $employee = cpms_tasks_drive_employee_for_file($pdo, $task, $uploadedBy, $fileRole);
    $employeeName = isset($employee['name']) ? trim((string)$employee['name']) : cpms_tasks_drive_label('unknown_employee');
    if ($employeeName === '') $employeeName = cpms_tasks_drive_label('unknown_employee');
    $fallbackDate = cpms_tasks_now();
    if ($fileRole === 'request' && is_array($task) && isset($task['created_at']) && trim((string)$task['created_at']) !== '') $fallbackDate = (string)$task['created_at'];
    if ($fileRole === 'complete' && is_array($task) && isset($task['completed_at']) && trim((string)$task['completed_at']) !== '') $fallbackDate = (string)$task['completed_at'];
    $monthName = cpms_tasks_drive_month_name($fallbackDate);

    $context = array(
        'user' => $employee,
        'uploaded_by' => $uploadedBy,
        'section' => 'tasks',
        'task_id' => $taskId,
        'document_type' => cpms_tasks_drive_stage_label($fileRole),
        'document_year' => substr($monthName, 0, 4),
        'document_month' => substr($monthName, 5, 2),
        'original_name' => (string)$originalName,
        'target_folder_id' => $parentId
    );

    if ($configuredRoot !== '') {
        $rootId = $configuredRoot;
    } else {
        $rootContext = $context;
        $rootContext['original_name'] = cpms_tasks_drive_label('root');
        $root = cpms_tasks_drive_ensure_folder(cpms_tasks_drive_label('root'), $parentId, $rootContext);
        $commonParentId = cpms_drive_folder_id('common_documents');
        if (empty($root['ok']) && $commonParentId !== '' && $commonParentId !== $parentId) {
            $parentId = $commonParentId;
            $context['target_folder_id'] = $parentId;
            $rootContext = $context;
            $rootContext['original_name'] = cpms_tasks_drive_label('root');
            $root = cpms_tasks_drive_ensure_folder(cpms_tasks_drive_label('root'), $parentId, $rootContext);
        }
        if (empty($root['ok'])) return $root;
        $rootId = (string)$root['folder_id'];
    }

    $employeeContext = $context;
    $employeeContext['target_folder_id'] = $rootId;
    $employeeContext['original_name'] = $employeeName;
    $employeeFolder = cpms_tasks_drive_ensure_folder($employeeName, $rootId, $employeeContext);
    if (empty($employeeFolder['ok'])) return $employeeFolder;

    $monthContext = $context;
    $monthContext['target_folder_id'] = (string)$employeeFolder['folder_id'];
    $monthContext['original_name'] = $monthName;
    $monthFolder = cpms_tasks_drive_ensure_folder($monthName, (string)$employeeFolder['folder_id'], $monthContext);
    if (empty($monthFolder['ok'])) return $monthFolder;

    $stageName = cpms_tasks_drive_stage_label($fileRole);
    $stageContext = $context;
    $stageContext['target_folder_id'] = (string)$monthFolder['folder_id'];
    $stageContext['original_name'] = $stageName;
    $stageFolder = cpms_tasks_drive_ensure_folder($stageName, (string)$monthFolder['folder_id'], $stageContext);
    if (empty($stageFolder['ok'])) return $stageFolder;

    return array(
        'ok' => true,
        'folder_id' => (string)$stageFolder['folder_id'],
        'root_folder_id' => $rootId,
        'employee_folder_id' => (string)$employeeFolder['folder_id'],
        'month_folder_id' => (string)$monthFolder['folder_id'],
        'stage_folder_id' => (string)$stageFolder['folder_id'],
        'month_name' => $monthName,
        'stage_name' => $stageName,
        'employee_name' => $employeeName,
        'context' => $context,
        'message' => 'Tasks Drive target folder is ready.',
        'http_code' => isset($stageFolder['http_code']) ? (int)$stageFolder['http_code'] : 0
    );
}}

if (!function_exists('cpms_tasks_drive_build_file_name')) {
function cpms_tasks_drive_build_file_name($task, $fileRole, $originalName)
{
    $date = date('Y-m-d');
    if (is_array($task) && cpms_tasks_drive_file_role($fileRole) === 'request' && !empty($task['created_at'])) {
        $ts = strtotime((string)$task['created_at']);
        if ($ts !== false) $date = date('Y-m-d', $ts);
    }
    if (is_array($task) && cpms_tasks_drive_file_role($fileRole) === 'complete' && !empty($task['completed_at'])) {
        $ts2 = strtotime((string)$task['completed_at']);
        if ($ts2 !== false) $date = date('Y-m-d', $ts2);
    }
    $title = is_array($task) && isset($task['title']) ? (string)$task['title'] : '';
    $parts = array($date, cpms_tasks_drive_stage_label($fileRole), $title, date('His') . '_' . mt_rand(1000, 9999), (string)$originalName);
    if (cpms_tasks_drive_helper_loaded()) return cpms_drive_sanitize_file_name(implode('_', $parts), 180);
    return implode('_', $parts);
}}

if (!function_exists('cpms_tasks_drive_upload_local_file')) {
function cpms_tasks_drive_upload_local_file($pdo, $task, $localPath, $originalName, $mimeType, $fileSize, $uploadedBy, $fileRole)
{
    $fileRole = cpms_tasks_drive_file_role($fileRole);
    $localPath = trim((string)$localPath);
    if ($localPath === '' || !is_file($localPath)) {
        return cpms_tasks_drive_failed_record('Local task file is not available for Drive upload.', $fileRole);
    }
    if (!cpms_tasks_drive_helper_loaded()) {
        return cpms_tasks_drive_failed_record('Google Drive helper is not available.', $fileRole);
    }
    $target = cpms_tasks_drive_ensure_target_folder($pdo, $task, $uploadedBy, $fileRole, $originalName);
    if (empty($target['ok'])) {
        $message = isset($target['message']) ? (string)$target['message'] : 'Task Drive folder preparation failed.';
        return cpms_tasks_drive_failed_record($message, $fileRole);
    }

    $context = isset($target['context']) && is_array($target['context']) ? $target['context'] : array();
    $context['target_folder_id'] = (string)$target['folder_id'];
    $context['drive_folder_id'] = (string)$target['folder_id'];
    $context['drive_root_folder_id'] = isset($target['root_folder_id']) ? (string)$target['root_folder_id'] : '';
    $context['drive_employee_folder_id'] = isset($target['employee_folder_id']) ? (string)$target['employee_folder_id'] : '';
    $context['drive_month_folder_id'] = isset($target['month_folder_id']) ? (string)$target['month_folder_id'] : '';
    $context['drive_stage_folder_id'] = isset($target['stage_folder_id']) ? (string)$target['stage_folder_id'] : '';
    $context['file_role'] = $fileRole;
    $context['mime_type'] = (string)$mimeType;
    $context['size'] = (int)$fileSize;

    $driveName = cpms_tasks_drive_build_file_name($task, $fileRole, $originalName);
    $upload = cpms_drive_upload_file($localPath, $driveName, (string)$target['folder_id'], $mimeType, $context);
    if (empty($upload['ok']) || !isset($upload['file']) || !is_array($upload['file'])) {
        $message2 = isset($upload['message']) ? (string)$upload['message'] : 'Task Drive file upload failed.';
        return cpms_tasks_drive_failed_record($message2, $fileRole);
    }

    $record = cpms_drive_build_file_record($upload['file'], $context);
    return array(
        'storage_type' => 'google_drive',
        'drive_name' => isset($record['stored_name']) ? (string)$record['stored_name'] : $driveName,
        'drive_file_id' => isset($record['drive_file_id']) ? (string)$record['drive_file_id'] : '',
        'drive_folder_id' => isset($record['drive_folder_id']) ? (string)$record['drive_folder_id'] : (string)$target['folder_id'],
        'drive_web_view_link' => isset($record['drive_web_view_link']) ? (string)$record['drive_web_view_link'] : '',
        'drive_web_content_link' => isset($record['drive_web_content_link']) ? (string)$record['drive_web_content_link'] : '',
        'drive_root_folder_id' => isset($target['root_folder_id']) ? (string)$target['root_folder_id'] : '',
        'drive_employee_folder_id' => isset($target['employee_folder_id']) ? (string)$target['employee_folder_id'] : '',
        'drive_month_folder_id' => isset($target['month_folder_id']) ? (string)$target['month_folder_id'] : '',
        'drive_stage_folder_id' => isset($target['stage_folder_id']) ? (string)$target['stage_folder_id'] : '',
        'file_role' => $fileRole,
        'upload_status' => 'uploaded',
        'drive_upload_error' => ''
    );
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
    if (is_array($storedPath)) {
        $fileId = isset($storedPath['id']) ? (int)$storedPath['id'] : 0;
        if ($fileId > 0) return base_url() . '/?r=tasks/file&id=' . $fileId;
        if (isset($storedPath['drive_web_view_link']) && trim((string)$storedPath['drive_web_view_link']) !== '') return (string)$storedPath['drive_web_view_link'];
        $storedPath = isset($storedPath['stored_path']) ? $storedPath['stored_path'] : '';
    }
    $storedPath = ltrim(str_replace('\\', '/', (string)$storedPath), '/');
    return base_url() . '/' . $storedPath;
}}

if (!function_exists('cpms_tasks_local_file_path')) {
function cpms_tasks_local_file_path($storedPath)
{
    $storedPath = ltrim(str_replace('\\', '/', (string)$storedPath), '/');
    if ($storedPath === '') return '';
    $root = realpath(cpms_tasks_public_root());
    $candidate = cpms_tasks_public_root() . '/' . $storedPath;
    $real = realpath($candidate);
    if ($real === false || !is_file($real)) return '';
    if ($root !== false) {
        $rootNorm = str_replace('\\', '/', rtrim($root, '/\\')) . '/';
        $realNorm = str_replace('\\', '/', $real);
        if (strpos($realNorm, $rootNorm) !== 0) return '';
    }
    return $real;
}}

if (!function_exists('cpms_tasks_insert_file_row')) {
function cpms_tasks_insert_file_row($pdo, $row)
{
    if (!$pdo || !is_array($row)) return false;
    $columns = array(
        'task_id', 'original_name', 'stored_name', 'stored_path', 'file_size', 'mime_type', 'uploaded_by', 'uploaded_at',
        'file_role', 'storage_type', 'drive_name', 'drive_file_id', 'drive_folder_id', 'drive_web_view_link',
        'drive_web_content_link', 'drive_root_folder_id', 'drive_employee_folder_id', 'drive_month_folder_id',
        'drive_stage_folder_id', 'upload_status', 'drive_upload_error'
    );
    $params = array();
    $marks = array();
    for ($i = 0; $i < count($columns); $i++) {
        $column = $columns[$i];
        $params[':' . $column] = isset($row[$column]) ? $row[$column] : null;
        $marks[$i] = ':' . $column;
    }
    try {
        $sql = "INSERT INTO cpms_task_files (" . implode(',', $columns) . ") VALUES (" . implode(',', $marks) . ")";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return true;
    } catch (Exception $e) {
        try {
            $st2 = $pdo->prepare("INSERT INTO cpms_task_files (task_id, original_name, stored_name, stored_path, file_size, mime_type, uploaded_by, uploaded_at) VALUES (:task_id, :original_name, :stored_name, :stored_path, :file_size, :mime_type, :uploaded_by, :uploaded_at)");
            return $st2->execute(array(
                ':task_id' => isset($row['task_id']) ? (int)$row['task_id'] : 0,
                ':original_name' => isset($row['original_name']) ? (string)$row['original_name'] : '',
                ':stored_name' => isset($row['stored_name']) ? (string)$row['stored_name'] : '',
                ':stored_path' => isset($row['stored_path']) ? (string)$row['stored_path'] : '',
                ':file_size' => isset($row['file_size']) ? (int)$row['file_size'] : 0,
                ':mime_type' => isset($row['mime_type']) ? (string)$row['mime_type'] : '',
                ':uploaded_by' => isset($row['uploaded_by']) && (int)$row['uploaded_by'] > 0 ? (int)$row['uploaded_by'] : null,
                ':uploaded_at' => isset($row['uploaded_at']) ? (string)$row['uploaded_at'] : cpms_tasks_now(),
            ));
        } catch (Exception $e2) {
            return false;
        }
    }
}}

if (!function_exists('cpms_tasks_save_uploaded_files')) {
function cpms_tasks_save_uploaded_files($pdo, $taskId, $files, $uploadedBy, $fileRole = 'request')
{
    if (!$pdo || (int)$taskId <= 0 || !is_array($files) || !isset($files['name'])) return array();
    $saved = array();
    $fileRole = cpms_tasks_drive_file_role($fileRole);
    $task = cpms_tasks_find_task($pdo, (int)$taskId);
    $names = is_array($files['name']) ? $files['name'] : array($files['name']);
    $tmpNames = is_array($files['tmp_name']) ? $files['tmp_name'] : array($files['tmp_name']);
    $errors = is_array($files['error']) ? $files['error'] : array($files['error']);
    $sizes = is_array($files['size']) ? $files['size'] : array($files['size']);
    $types = is_array($files['type']) ? $files['type'] : array($files['type']);

    for ($i = 0; $i < count($names); $i++) {
        $originalName = isset($names[$i]) ? trim((string)$names[$i]) : '';
        $tmpName = isset($tmpNames[$i]) ? (string)$tmpNames[$i] : '';
        $errorCode = isset($errors[$i]) ? (int)$errors[$i] : UPLOAD_ERR_NO_FILE;
        if ($errorCode !== UPLOAD_ERR_OK || $originalName === '' || $tmpName === '') continue;
        if (!is_file($tmpName)) continue;

        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $storedName = date('YmdHis') . '_' . substr(md5(uniqid('', true) . $originalName), 0, 16);
        if ($extension !== '') $storedName .= '.' . strtolower($extension);

        $fileSize = @filesize($tmpName);
        if ($fileSize === false) $fileSize = isset($sizes[$i]) ? (int)$sizes[$i] : 0;
        $mimeType = '';
        if (cpms_tasks_drive_helper_loaded()) $mimeType = cpms_drive_detect_mime_type($tmpName);
        if ($mimeType === '') $mimeType = isset($types[$i]) ? (string)$types[$i] : '';
        if ($mimeType === '') $mimeType = 'application/octet-stream';
        $driveRecord = cpms_tasks_drive_upload_local_file($pdo, $task, $tmpName, $originalName, $mimeType, $fileSize, (int)$uploadedBy, $fileRole);
        if (!is_array($driveRecord) || !isset($driveRecord['storage_type']) || (string)$driveRecord['storage_type'] !== 'google_drive' || !isset($driveRecord['drive_file_id']) || trim((string)$driveRecord['drive_file_id']) === '') {
            continue;
        }
        if (isset($driveRecord['drive_name']) && trim((string)$driveRecord['drive_name']) !== '') {
            $storedName = (string)$driveRecord['drive_name'];
        }
        $row = array_merge(array(
            'task_id' => (int)$taskId,
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'stored_path' => '',
            'file_size' => (int)$fileSize,
            'mime_type' => $mimeType,
            'uploaded_by' => (int)$uploadedBy > 0 ? (int)$uploadedBy : null,
            'uploaded_at' => cpms_tasks_now(),
        ), is_array($driveRecord) ? $driveRecord : array());

        try {
            if (cpms_tasks_insert_file_row($pdo, $row)) {
                $saved[count($saved)] = array(
                    'original_name' => $originalName,
                    'stored_name' => $storedName,
                    'stored_path' => '',
                    'tmp_name' => $tmpName,
                    'file_size' => (int)$fileSize,
                    'mime_type' => $mimeType,
                    'file_role' => $fileRole,
                    'storage_type' => 'google_drive',
                    'drive_file_id' => isset($driveRecord['drive_file_id']) ? (string)$driveRecord['drive_file_id'] : '',
                    'drive_web_view_link' => isset($driveRecord['drive_web_view_link']) ? (string)$driveRecord['drive_web_view_link'] : '',
                    'drive_web_content_link' => isset($driveRecord['drive_web_content_link']) ? (string)$driveRecord['drive_web_content_link'] : '',
                );
            }
        } catch (Exception $e) {
        }
    }

    return $saved;
}}

if (!function_exists('cpms_tasks_copy_saved_files_to_task')) {
function cpms_tasks_copy_saved_files_to_task($pdo, $sourceFiles, $targetTaskId, $uploadedBy, $fileRole = 'request')
{
    $copied = array();
    if (!$pdo || (int)$targetTaskId <= 0 || !is_array($sourceFiles) || !cpms_tasks_table_exists($pdo, 'cpms_task_files')) return $copied;
    $fileRole = cpms_tasks_drive_file_role($fileRole);
    $targetTask = cpms_tasks_find_task($pdo, (int)$targetTaskId);

    for ($i = 0; $i < count($sourceFiles); $i++) {
        $file = $sourceFiles[$i];
        if (!is_array($file)) continue;
        $tmpName = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';
        if ($tmpName === '' || !is_file($tmpName)) continue;

        $fileSize = @filesize($tmpName);
        if ($fileSize === false) $fileSize = isset($file['file_size']) ? (int)$file['file_size'] : 0;
        $mimeType = isset($file['mime_type']) ? (string)$file['mime_type'] : '';
        $originalName = isset($file['original_name']) ? (string)$file['original_name'] : '';
        if ($originalName === '') $originalName = isset($file['stored_name']) ? (string)$file['stored_name'] : 'task_file';
        if ($mimeType === '' && cpms_tasks_drive_helper_loaded()) $mimeType = cpms_drive_detect_mime_type($tmpName);
        if ($mimeType === '') $mimeType = 'application/octet-stream';
        $driveRecord = cpms_tasks_drive_upload_local_file($pdo, $targetTask, $tmpName, $originalName, $mimeType, $fileSize, (int)$uploadedBy, $fileRole);
        if (!is_array($driveRecord) || !isset($driveRecord['storage_type']) || (string)$driveRecord['storage_type'] !== 'google_drive' || !isset($driveRecord['drive_file_id']) || trim((string)$driveRecord['drive_file_id']) === '') {
            continue;
        }
        $storedName = isset($driveRecord['drive_name']) && trim((string)$driveRecord['drive_name']) !== '' ? (string)$driveRecord['drive_name'] : (isset($file['stored_name']) ? (string)$file['stored_name'] : $originalName);
        $row = array_merge(array(
            'task_id' => (int)$targetTaskId,
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'stored_path' => '',
            'file_size' => (int)$fileSize,
            'mime_type' => $mimeType,
            'uploaded_by' => (int)$uploadedBy > 0 ? (int)$uploadedBy : null,
            'uploaded_at' => cpms_tasks_now(),
        ), is_array($driveRecord) ? $driveRecord : array());

        try {
            if (cpms_tasks_insert_file_row($pdo, $row)) {
                $copied[count($copied)] = array(
                    'original_name' => $originalName,
                    'stored_name' => $storedName,
                    'stored_path' => '',
                    'tmp_name' => $tmpName,
                    'file_size' => (int)$fileSize,
                    'mime_type' => $mimeType,
                    'file_role' => $fileRole,
                    'storage_type' => 'google_drive',
                    'drive_file_id' => isset($driveRecord['drive_file_id']) ? (string)$driveRecord['drive_file_id'] : '',
                    'drive_web_view_link' => isset($driveRecord['drive_web_view_link']) ? (string)$driveRecord['drive_web_view_link'] : '',
                    'drive_web_content_link' => isset($driveRecord['drive_web_content_link']) ? (string)$driveRecord['drive_web_content_link'] : '',
                );
            }
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

if (!function_exists('cpms_tasks_file_with_task_owner')) {
function cpms_tasks_file_with_task_owner($file, $task)
{
    if (!is_array($file)) $file = array();
    if (!is_array($task)) $task = array();
    $file['_task_id'] = isset($task['id']) ? (int)$task['id'] : 0;
    $file['_task_assignee_employee_id'] = isset($task['assignee_employee_id']) ? (int)$task['assignee_employee_id'] : 0;
    $file['_task_assignee_name'] = isset($task['assignee_name']) ? trim((string)$task['assignee_name']) : '';
    return $file;
}}

if (!function_exists('cpms_tasks_fetch_visible_files')) {
function cpms_tasks_fetch_visible_files($pdo, $task, $currentEmployeeId)
{
    $rows = array();
    if (!$pdo || !is_array($task)) return $rows;
    $taskId = isset($task['id']) ? (int)$task['id'] : 0;
    if ($taskId <= 0 || !cpms_tasks_can_view($task, (int)$currentEmployeeId)) return $rows;

    $seenFileIds = array();
    $currentFiles = cpms_tasks_fetch_files($pdo, $taskId);
    for ($i = 0; $i < count($currentFiles); $i++) {
        $file = cpms_tasks_file_with_task_owner($currentFiles[$i], $task);
        $fileId = isset($file['id']) ? (int)$file['id'] : 0;
        if ($fileId > 0) $seenFileIds[$fileId] = true;
        $rows[count($rows)] = $file;
    }

    $groupKey = isset($task['group_key']) ? trim((string)$task['group_key']) : '';
    if (!cpms_tasks_is_request_group_key($groupKey)) return $rows;

    $summary = cpms_tasks_completion_group_summary($pdo, $task);
    $groupTasks = isset($summary['rows']) && is_array($summary['rows']) ? $summary['rows'] : array();
    for ($i = 0; $i < count($groupTasks); $i++) {
        $groupTask = $groupTasks[$i];
        $groupTaskId = isset($groupTask['id']) ? (int)$groupTask['id'] : 0;
        if ($groupTaskId <= 0 || $groupTaskId === $taskId) continue;
        if (!cpms_tasks_can_view($groupTask, (int)$currentEmployeeId)) continue;

        $groupFiles = cpms_tasks_fetch_files($pdo, $groupTaskId);
        for ($j = 0; $j < count($groupFiles); $j++) {
            if (cpms_tasks_file_effective_role($groupTask, $groupFiles[$j]) !== 'complete') continue;
            $fileId = isset($groupFiles[$j]['id']) ? (int)$groupFiles[$j]['id'] : 0;
            if ($fileId > 0 && isset($seenFileIds[$fileId])) continue;
            if ($fileId > 0) $seenFileIds[$fileId] = true;
            $rows[count($rows)] = cpms_tasks_file_with_task_owner($groupFiles[$j], $groupTask);
        }
    }

    return $rows;
}}

if (!function_exists('cpms_tasks_file_effective_role')) {
function cpms_tasks_file_effective_role($task, $file)
{
    if (is_array($file) && isset($file['file_role'])) {
        $fileRole = trim((string)$file['file_role']);
        if ($fileRole === 'complete') return 'complete';
        if ($fileRole === 'request') return 'request';
    }
    if (!is_array($task) || !is_array($file)) return 'request';

    $uploadedBy = isset($file['uploaded_by']) ? (int)$file['uploaded_by'] : 0;
    $requesterId = isset($task['requester_employee_id']) ? (int)$task['requester_employee_id'] : 0;
    $completedBy = isset($task['completed_by']) ? (int)$task['completed_by'] : 0;
    if ($uploadedBy > 0 && $completedBy > 0 && $uploadedBy === $completedBy && $uploadedBy !== $requesterId) {
        return 'complete';
    }

    $uploadedAt = isset($file['uploaded_at']) ? trim((string)$file['uploaded_at']) : '';
    $completedAt = isset($task['completed_at']) ? trim((string)$task['completed_at']) : '';
    if ($uploadedAt !== '' && $completedAt !== '') {
        $uploadedTs = strtotime($uploadedAt);
        $completedTs = strtotime($completedAt);
        if ($uploadedTs !== false && $completedTs !== false && $uploadedTs >= ($completedTs - 600)) {
            return 'complete';
        }
    }
    return 'request';
}}

if (!function_exists('cpms_tasks_file_counts_for_task')) {
function cpms_tasks_file_counts_for_task($pdo, $taskId)
{
    $counts = array('total' => 0, 'request' => 0, 'complete' => 0);
    if (!$pdo || (int)$taskId <= 0 || !cpms_tasks_table_exists($pdo, 'cpms_task_files')) return $counts;
    try {
        $task = cpms_tasks_find_task($pdo, (int)$taskId);
        $st = $pdo->prepare("SELECT * FROM cpms_task_files WHERE task_id = :task_id");
        $st->execute(array(':task_id' => (int)$taskId));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) $rows = array();
        for ($i = 0; $i < count($rows); $i++) {
            $counts['total']++;
            if (cpms_tasks_file_effective_role($task, $rows[$i]) === 'complete') {
                $counts['complete']++;
            } else {
                $counts['request']++;
            }
        }
    } catch (Exception $e) {
        try {
            $st2 = $pdo->prepare("SELECT COUNT(*) FROM cpms_task_files WHERE task_id = :task_id");
            $st2->execute(array(':task_id' => (int)$taskId));
            $counts['total'] = (int)$st2->fetchColumn();
            $counts['request'] = $counts['total'];
        } catch (Exception $e2) {
        }
    }
    return $counts;
}}

if (!function_exists('cpms_tasks_mark_read')) {
function cpms_tasks_mark_read($pdo, $task, $currentEmployee)
{
    if (!$pdo || !is_array($task) || !is_array($currentEmployee)) return false;
    $taskId = isset($task['id']) ? (int)$task['id'] : 0;
    $currentEmployeeId = isset($currentEmployee['id']) ? (int)$currentEmployee['id'] : 0;
    if ($taskId <= 0 || $currentEmployeeId <= 0) return false;
    if (!isset($task['assignee_employee_id']) || (int)$task['assignee_employee_id'] !== $currentEmployeeId) return false;
    if (cpms_tasks_effective_requester_employee_id($task) === $currentEmployeeId) return false;
    if (isset($task['read_at']) && trim((string)$task['read_at']) !== '') return false;
    try {
        $sets = array('read_at = :read_at');
        $params = array(
            ':read_at' => cpms_tasks_now(),
            ':id' => $taskId,
        );
        if (cpms_tasks_column_exists($pdo, 'cpms_tasks', 'read_by')) {
            $sets[count($sets)] = 'read_by = :read_by';
            $params[':read_by'] = $currentEmployeeId;
        }
        if (cpms_tasks_column_exists($pdo, 'cpms_tasks', 'updated_at')) {
            $sets[count($sets)] = 'updated_at = :updated_at';
            $params[':updated_at'] = cpms_tasks_now();
        }
        $st = $pdo->prepare("UPDATE cpms_tasks SET " . implode(', ', $sets) . " WHERE id = :id AND (read_at IS NULL OR read_at = '')");
        $ok = $st->execute($params);
        if ($ok && $st->rowCount() > 0) {
            cpms_tasks_insert_log($pdo, $taskId, $currentEmployee, 'request_read', '요청 읽음', isset($task['status']) ? $task['status'] : null, isset($task['status']) ? $task['status'] : null);
        }
        return $ok;
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_tasks_read_status_entries')) {
function cpms_tasks_read_status_entries($item, $currentEmployeeId)
{
    $entries = array();
    if (!is_array($item) || (int)$currentEmployeeId <= 0) return $entries;
    $requesterId = cpms_tasks_effective_requester_employee_id($item);
    if ($requesterId <= 0 || $requesterId !== (int)$currentEmployeeId) return $entries;
    if (cpms_tasks_is_self_request($item)) return $entries;
    $seen = array();
    if (isset($item['assignee_read_statuses']) && is_array($item['assignee_read_statuses'])) {
        foreach ($item['assignee_read_statuses'] as $statusRow) {
            if (!is_array($statusRow)) continue;
            $assigneeId = isset($statusRow['id']) ? (int)$statusRow['id'] : 0;
            if ($assigneeId > 0 && $assigneeId === $requesterId) continue;
            if (isset($statusRow['self_request']) && (int)$statusRow['self_request'] === 1) continue;
            $assigneeName = isset($statusRow['name']) ? trim((string)$statusRow['name']) : '';
            $key = $assigneeId > 0 ? 'id:' . $assigneeId : 'name:' . $assigneeName;
            if ($key === 'name:' || isset($seen[$key])) continue;
            $seen[$key] = true;
            $readAt = isset($statusRow['read_at']) ? trim((string)$statusRow['read_at']) : '';
            $entries[count($entries)] = array(
                'name' => $assigneeName,
                'read' => $readAt !== '' ? 1 : 0,
            );
        }
    }
    if (count($entries) === 0) {
        $assigneeId = isset($item['assignee_employee_id']) ? (int)$item['assignee_employee_id'] : 0;
        if ($assigneeId > 0 && $assigneeId === $requesterId) return $entries;
        $assigneeName = isset($item['assignee_name']) ? trim((string)$item['assignee_name']) : '';
        $readAt = isset($item['read_at']) ? trim((string)$item['read_at']) : '';
        $entries[count($entries)] = array(
            'name' => $assigneeName,
            'read' => $readAt !== '' ? 1 : 0,
        );
    }
    return $entries;
}}

if (!function_exists('cpms_tasks_read_status_badges_html')) {
function cpms_tasks_read_status_badges_html($item, $currentEmployeeId, $includeNames)
{
    $entries = cpms_tasks_read_status_entries($item, $currentEmployeeId);
    if (count($entries) === 0) return '';
    $html = '';
    for ($i = 0; $i < count($entries); $i++) {
        $name = isset($entries[$i]['name']) ? trim((string)$entries[$i]['name']) : '';
        $isRead = isset($entries[$i]['read']) && (int)$entries[$i]['read'] === 1;
        $label = $isRead ? '[읽음]' : '[읽지않음]';
        if ($includeNames && $name !== '') $label = $name . ' ' . $label;
        $class = $isRead
            ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
            : 'bg-slate-50 text-slate-600 border-slate-200';
        $html .= '<span class="px-2.5 py-1 rounded-full border text-xs font-extrabold ' . $class . '">' . h($label) . '</span>';
    }
    return $html;
}}

if (!function_exists('cpms_tasks_can_transfer')) {
function cpms_tasks_can_transfer($task, $currentEmployeeId)
{
    if (!$task || (int)$currentEmployeeId <= 0) return false;
    if (isset($task['task_type']) && (string)$task['task_type'] === 'meeting') return false;
    if (!isset($task['assignee_employee_id']) || (int)$task['assignee_employee_id'] !== (int)$currentEmployeeId) return false;
    if (cpms_tasks_has_transfer_request($task)) return false;
    $status = isset($task['status']) ? (string)$task['status'] : '';
    if (in_array($status, array('completion_pending', 'done', 'cancelled'), true)) return false;
    return true;
}}

if (!function_exists('cpms_tasks_has_transfer_request')) {
function cpms_tasks_has_transfer_request($task)
{
    if (!$task || !is_array($task)) return false;
    return (isset($task['transfer_request_assignee_employee_id']) && (int)$task['transfer_request_assignee_employee_id'] > 0);
}}

if (!function_exists('cpms_tasks_can_approve_transfer_request')) {
function cpms_tasks_can_approve_transfer_request($task, $currentEmployeeId)
{
    if (!$task || (int)$currentEmployeeId <= 0 || !cpms_tasks_has_transfer_request($task)) return false;
    if (isset($task['task_type']) && (string)$task['task_type'] === 'meeting') return false;
    $status = isset($task['status']) ? (string)$task['status'] : '';
    if (in_array($status, array('completion_pending', 'done', 'cancelled'), true)) return false;
    return (cpms_tasks_effective_requester_employee_id($task) === (int)$currentEmployeeId);
}}

if (!function_exists('cpms_tasks_request_transfer')) {
function cpms_tasks_request_transfer($pdo, $task, $newAssignee, $actor, $reason)
{
    if (!$pdo || !is_array($task) || !is_array($newAssignee) || !is_array($actor)) return false;
    $taskId = isset($task['id']) ? (int)$task['id'] : 0;
    $currentAssigneeId = isset($task['assignee_employee_id']) ? (int)$task['assignee_employee_id'] : 0;
    $newAssigneeId = isset($newAssignee['id']) ? (int)$newAssignee['id'] : 0;
    $actorId = isset($actor['id']) ? (int)$actor['id'] : 0;
    if ($taskId <= 0 || $currentAssigneeId <= 0 || $actorId !== $currentAssigneeId || $newAssigneeId <= 0 || $newAssigneeId === $currentAssigneeId) return false;
    if (cpms_tasks_has_transfer_request($task)) return false;

    $reason = trim((string)$reason);
    $now = cpms_tasks_now();
    try {
        $st = $pdo->prepare("UPDATE cpms_tasks
                            SET transfer_request_assignee_employee_id = :target_employee_id,
                                transfer_request_assignee_name = :target_name,
                                transfer_request_reason = :request_reason,
                                transfer_requested_by = :requested_by,
                                transfer_requested_by_name = :requested_by_name,
                                transfer_requested_at = :requested_at,
                                updated_at = :updated_at
                            WHERE id = :id
                              AND assignee_employee_id = :current_assignee_id
                              AND (transfer_request_assignee_employee_id IS NULL OR transfer_request_assignee_employee_id = 0)
                              AND (status IS NULL OR status NOT IN ('completion_pending','done','cancelled'))");
        $ok = $st->execute(array(
            ':target_employee_id' => $newAssigneeId,
            ':target_name' => isset($newAssignee['name']) ? (string)$newAssignee['name'] : '',
            ':request_reason' => $reason !== '' ? $reason : null,
            ':requested_by' => $actorId,
            ':requested_by_name' => isset($actor['name']) ? (string)$actor['name'] : '',
            ':requested_at' => $now,
            ':updated_at' => $now,
            ':id' => $taskId,
            ':current_assignee_id' => $currentAssigneeId,
        ));
        if (!$ok || $st->rowCount() !== 1) return false;

        $message = '담당자 변경 요청: ' . (isset($task['assignee_name']) ? (string)$task['assignee_name'] : '') . ' → ' . (isset($newAssignee['name']) ? (string)$newAssignee['name'] : '');
        if ($reason !== '') $message .= "\n사유: " . $reason;
        cpms_tasks_insert_log($pdo, $taskId, $actor, 'transfer_requested', $message, isset($task['status']) ? $task['status'] : null, isset($task['status']) ? $task['status'] : null);
        if ($reason !== '') cpms_tasks_insert_comment($pdo, $taskId, $actor, $message, 0);

        $updatedTask = cpms_tasks_find_task($pdo, $taskId);
        if ($updatedTask) cpms_tasks_send_transfer_request_notification($pdo, $updatedTask);
        return true;
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_tasks_approve_transfer_request')) {
function cpms_tasks_approve_transfer_request($pdo, $task, $actor)
{
    if (!$pdo || !is_array($task) || !is_array($actor)) return false;
    $actorId = isset($actor['id']) ? (int)$actor['id'] : 0;
    if (!cpms_tasks_can_approve_transfer_request($task, $actorId)) return false;

    $currentAssigneeId = isset($task['assignee_employee_id']) ? (int)$task['assignee_employee_id'] : 0;
    $requestedBy = isset($task['transfer_requested_by']) ? (int)$task['transfer_requested_by'] : 0;
    $newAssigneeId = isset($task['transfer_request_assignee_employee_id']) ? (int)$task['transfer_request_assignee_employee_id'] : 0;
    if ($currentAssigneeId <= 0 || $requestedBy !== $currentAssigneeId || $newAssigneeId <= 0 || $newAssigneeId === $currentAssigneeId) return false;

    $newAssignee = cpms_tasks_find_employee_by_id($pdo, $newAssigneeId);
    if (!$newAssignee) return false;
    $reason = isset($task['transfer_request_reason']) ? trim((string)$task['transfer_request_reason']) : '';
    return cpms_tasks_transfer_task($pdo, $task, $newAssignee, $actor, $reason);
}}

if (!function_exists('cpms_tasks_transfer_task')) {
function cpms_tasks_transfer_task($pdo, $task, $newAssignee, $actor, $reason)
{
    if (!$pdo || !is_array($task) || !is_array($newAssignee) || !is_array($actor)) return false;
    $taskId = isset($task['id']) ? (int)$task['id'] : 0;
    $newAssigneeId = isset($newAssignee['id']) ? (int)$newAssignee['id'] : 0;
    if ($taskId <= 0 || $newAssigneeId <= 0) return false;
    $oldStatus = isset($task['status']) ? (string)$task['status'] : null;
    $oldAssigneeName = isset($task['assignee_name']) ? (string)$task['assignee_name'] : '';
    $newAssigneeName = isset($newAssignee['name']) ? (string)$newAssignee['name'] : '';
    $wasRequested = cpms_tasks_has_transfer_request($task);
    $now = cpms_tasks_now();
    $sets = array(
        'assignee_employee_id = :assignee_employee_id',
        'assignee_name = :assignee_name',
        'assignee_email = :assignee_email',
        'department = :department',
        "status = 'pending'"
    );
    $params = array(
        ':assignee_employee_id' => $newAssigneeId,
        ':assignee_name' => $newAssigneeName,
        ':assignee_email' => isset($newAssignee['email']) ? (string)$newAssignee['email'] : '',
        ':department' => isset($newAssignee['department']) ? cpms_tasks_normalize_department($newAssignee['department']) : '',
        ':id' => $taskId,
    );
    if (cpms_tasks_column_exists($pdo, 'cpms_tasks', 'read_at')) $sets[count($sets)] = 'read_at = NULL';
    if (cpms_tasks_column_exists($pdo, 'cpms_tasks', 'read_by')) $sets[count($sets)] = 'read_by = NULL';
    if (cpms_tasks_column_exists($pdo, 'cpms_tasks', 'transferred_from_employee_id')) {
        $sets[count($sets)] = 'transferred_from_employee_id = :transferred_from_employee_id';
        $params[':transferred_from_employee_id'] = isset($task['assignee_employee_id']) ? (int)$task['assignee_employee_id'] : null;
    }
    if (cpms_tasks_column_exists($pdo, 'cpms_tasks', 'transferred_from_name')) {
        $sets[count($sets)] = 'transferred_from_name = :transferred_from_name';
        $params[':transferred_from_name'] = $oldAssigneeName;
    }
    if (cpms_tasks_column_exists($pdo, 'cpms_tasks', 'transferred_by')) {
        $sets[count($sets)] = 'transferred_by = :transferred_by';
        $params[':transferred_by'] = isset($actor['id']) ? (int)$actor['id'] : null;
    }
    if (cpms_tasks_column_exists($pdo, 'cpms_tasks', 'transferred_at')) {
        $sets[count($sets)] = 'transferred_at = :transferred_at';
        $params[':transferred_at'] = $now;
    }
    if (cpms_tasks_column_exists($pdo, 'cpms_tasks', 'updated_at')) {
        $sets[count($sets)] = 'updated_at = :updated_at';
        $params[':updated_at'] = $now;
    }
    if (cpms_tasks_column_exists($pdo, 'cpms_tasks', 'transfer_request_assignee_employee_id')) $sets[count($sets)] = 'transfer_request_assignee_employee_id = NULL';
    if (cpms_tasks_column_exists($pdo, 'cpms_tasks', 'transfer_request_assignee_name')) $sets[count($sets)] = 'transfer_request_assignee_name = NULL';
    if (cpms_tasks_column_exists($pdo, 'cpms_tasks', 'transfer_request_reason')) $sets[count($sets)] = 'transfer_request_reason = NULL';
    if (cpms_tasks_column_exists($pdo, 'cpms_tasks', 'transfer_requested_by')) $sets[count($sets)] = 'transfer_requested_by = NULL';
    if (cpms_tasks_column_exists($pdo, 'cpms_tasks', 'transfer_requested_by_name')) $sets[count($sets)] = 'transfer_requested_by_name = NULL';
    if (cpms_tasks_column_exists($pdo, 'cpms_tasks', 'transfer_requested_at')) $sets[count($sets)] = 'transfer_requested_at = NULL';

    try {
        $whereSql = 'id = :id';
        if ($wasRequested) {
            $whereSql .= ' AND assignee_employee_id = :transfer_current_assignee_id AND transfer_request_assignee_employee_id = :transfer_target_assignee_id';
            $params[':transfer_current_assignee_id'] = isset($task['assignee_employee_id']) ? (int)$task['assignee_employee_id'] : 0;
            $params[':transfer_target_assignee_id'] = $newAssigneeId;
        }
        $st = $pdo->prepare("UPDATE cpms_tasks SET " . implode(', ', $sets) . " WHERE " . $whereSql);
        $ok = $st->execute($params);
        if (!$ok || $st->rowCount() !== 1) return false;
        $message = ($wasRequested ? '담당자 변경 승인: ' : '담당자 변경: ') . $oldAssigneeName . ' → ' . $newAssigneeName;
        if ($wasRequested && isset($task['transfer_requested_by_name']) && trim((string)$task['transfer_requested_by_name']) !== '') {
            $message .= "\n변경 요청자: " . trim((string)$task['transfer_requested_by_name']);
        }
        $reason = trim((string)$reason);
        if ($reason !== '') $message .= "\n사유: " . $reason;
        cpms_tasks_insert_log($pdo, $taskId, $actor, $wasRequested ? 'transfer_approved' : 'transferred', $message, $oldStatus, 'pending');
        if ($reason !== '') cpms_tasks_insert_comment($pdo, $taskId, $actor, $message, 0);
        $updatedTask = cpms_tasks_find_task($pdo, $taskId);
        if ($updatedTask) {
            if ($wasRequested) cpms_tasks_send_transfer_approved_notification($pdo, $updatedTask);
            else cpms_tasks_send_created_notification($pdo, $updatedTask);
        }
        return true;
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_tasks_due_text_from_values')) {
function cpms_tasks_due_text_from_values($dueDate, $dueTime)
{
    $dueDate = trim((string)$dueDate);
    $dueTime = trim((string)$dueTime);
    if ($dueDate === '') return '-';
    if ($dueTime !== '') return $dueDate . ' ' . substr($dueTime, 0, 5);
    return $dueDate;
}}

if (!function_exists('cpms_tasks_request_completion')) {
function cpms_tasks_request_completion($pdo, $task, $actor, $completedMemo, $now)
{
    if (!$pdo || !is_array($task) || !is_array($actor)) return false;
    $taskId = isset($task['id']) ? (int)$task['id'] : 0;
    if ($taskId <= 0) return false;
    $actorId = isset($actor['id']) ? (int)$actor['id'] : 0;
    $completedMemo = trim((string)$completedMemo);
    $now = trim((string)$now) !== '' ? (string)$now : cpms_tasks_now();

    $st = $pdo->prepare("UPDATE cpms_tasks SET status = 'completion_pending', completed_at = NULL, completed_by = :completed_by, completed_memo = :completed_memo, updated_at = :updated_at WHERE id = :id");
    $ok = $st->execute(array(
        ':completed_by' => $actorId > 0 ? $actorId : null,
        ':completed_memo' => $completedMemo !== '' ? $completedMemo : null,
        ':updated_at' => $now,
        ':id' => $taskId,
    ));
    if (!$ok) return false;
    cpms_tasks_insert_log($pdo, $taskId, $actor, 'completion_requested', $completedMemo, isset($task['status']) ? $task['status'] : null, 'completion_pending');
    if ($completedMemo !== '') cpms_tasks_insert_comment($pdo, $taskId, $actor, $completedMemo, 0);
    return true;
}}

if (!function_exists('cpms_tasks_approve_completion')) {
function cpms_tasks_approve_completion($pdo, $task, $actor, $approvalMemo, $now)
{
    if (!$pdo || !is_array($task) || !is_array($actor)) return false;
    $taskId = isset($task['id']) ? (int)$task['id'] : 0;
    if ($taskId <= 0) return false;
    $actorId = isset($actor['id']) ? (int)$actor['id'] : 0;
    $approvalMemo = trim((string)$approvalMemo);
    $now = trim((string)$now) !== '' ? (string)$now : cpms_tasks_now();
    $groupKey = isset($task['group_key']) ? trim((string)$task['group_key']) : '';
    if (cpms_tasks_is_request_group_key($groupKey)) {
        $summary = cpms_tasks_completion_group_summary($pdo, $task);
        if (!isset($summary['ready_for_approval']) || !$summary['ready_for_approval']) return false;
        $groupRows = isset($summary['rows']) && is_array($summary['rows']) ? $summary['rows'] : array();
        $transactionStarted = false;
        try {
            if (method_exists($pdo, 'inTransaction') && !$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $transactionStarted = true;
            }
            $approvedCount = 0;
            for ($i = 0; $i < count($groupRows); $i++) {
                $groupTask = $groupRows[$i];
                $groupStatus = isset($groupTask['status']) ? (string)$groupTask['status'] : '';
                if ($groupStatus === 'done') continue;
                if ($groupStatus !== 'completion_pending') throw new Exception('group completion is not ready');
                $groupTaskId = isset($groupTask['id']) ? (int)$groupTask['id'] : 0;
                if ($groupTaskId <= 0) throw new Exception('group task id missing');
                $existingGroupMemo = isset($groupTask['completed_memo']) ? trim((string)$groupTask['completed_memo']) : '';
                $sets = array(
                    "status = 'done'",
                    'completed_at = :completed_at',
                    'completed_by = :completed_by',
                    'updated_at = :updated_at'
                );
                $params = array(
                    ':completed_at' => $now,
                    ':completed_by' => $actorId > 0 ? $actorId : null,
                    ':updated_at' => $now,
                    ':id' => $groupTaskId,
                );
                if ($existingGroupMemo === '' && $approvalMemo !== '') {
                    $sets[count($sets)] = 'completed_memo = :completed_memo';
                    $params[':completed_memo'] = $approvalMemo;
                }
                $st = $pdo->prepare("UPDATE cpms_tasks SET " . implode(', ', $sets) . " WHERE id = :id AND status = 'completion_pending'");
                $ok = $st->execute($params);
                if (!$ok || $st->rowCount() !== 1) throw new Exception('group completion update failed');
                cpms_tasks_insert_log($pdo, $groupTaskId, $actor, 'completion_approved', $approvalMemo, $groupStatus, 'done');
                if ($approvalMemo !== '') cpms_tasks_insert_comment($pdo, $groupTaskId, $actor, $approvalMemo, 0);
                $approvedCount++;
            }
            if ($approvedCount <= 0) throw new Exception('no group completion updated');
            if ($transactionStarted && $pdo->inTransaction()) $pdo->commit();
            return true;
        } catch (Exception $e) {
            if ($transactionStarted && method_exists($pdo, 'inTransaction') && $pdo->inTransaction()) $pdo->rollBack();
            return false;
        }
    }
    $existingCompletedMemo = isset($task['completed_memo']) ? trim((string)$task['completed_memo']) : '';

    $sets = array(
        "status = 'done'",
        'completed_at = :completed_at',
        'completed_by = :completed_by',
        'updated_at = :updated_at'
    );
    $params = array(
        ':completed_at' => $now,
        ':completed_by' => $actorId > 0 ? $actorId : null,
        ':updated_at' => $now,
        ':id' => $taskId,
    );
    if ($existingCompletedMemo === '' && $approvalMemo !== '') {
        $sets[count($sets)] = 'completed_memo = :completed_memo';
        $params[':completed_memo'] = $approvalMemo;
    }

    $st = $pdo->prepare("UPDATE cpms_tasks SET " . implode(', ', $sets) . " WHERE id = :id");
    $ok = $st->execute($params);
    if (!$ok) return false;
    cpms_tasks_insert_log($pdo, $taskId, $actor, 'completion_approved', $approvalMemo, isset($task['status']) ? $task['status'] : null, 'done');
    if ($approvalMemo !== '') cpms_tasks_insert_comment($pdo, $taskId, $actor, $approvalMemo, 0);
    return true;
}}

if (!function_exists('cpms_tasks_reject_completion')) {
function cpms_tasks_reject_completion($pdo, $task, $actor, $feedback, $dueDate, $dueTime, $now)
{
    if (!$pdo || !is_array($task) || !is_array($actor)) return false;
    $taskId = isset($task['id']) ? (int)$task['id'] : 0;
    if ($taskId <= 0) return false;
    $feedback = trim((string)$feedback);
    $dueDate = trim((string)$dueDate);
    $dueTime = trim((string)$dueTime);
    $now = trim((string)$now) !== '' ? (string)$now : cpms_tasks_now();

    $sets = array(
        "status = 'progress'",
        'completed_at = NULL',
        'completed_by = NULL',
        'completed_memo = NULL',
        'updated_at = :updated_at'
    );
    $params = array(
        ':updated_at' => $now,
        ':id' => $taskId,
    );
    if ($dueDate !== '') {
        $sets[count($sets)] = 'due_date = :due_date';
        $sets[count($sets)] = 'due_time = :due_time';
        $params[':due_date'] = $dueDate;
        $params[':due_time'] = $dueTime !== '' ? $dueTime : null;
    }

    $st = $pdo->prepare("UPDATE cpms_tasks SET " . implode(', ', $sets) . " WHERE id = :id");
    $ok = $st->execute($params);
    if (!$ok) return false;
    $message = $feedback;
    if ($dueDate !== '') {
        $message .= ($message !== '' ? "\n" : '') . '마감: ' . cpms_tasks_due_text_from_values($dueDate, $dueTime);
    }
    cpms_tasks_insert_log($pdo, $taskId, $actor, 'completion_rejected', $message, isset($task['status']) ? $task['status'] : null, 'progress');
    if ($feedback !== '') cpms_tasks_insert_comment($pdo, $taskId, $actor, $feedback, 0);
    return true;
}}

if (!function_exists('cpms_tasks_update_due_date')) {
function cpms_tasks_update_due_date($pdo, $task, $actor, $dueDate, $dueTime, $message, $now)
{
    if (!$pdo || !is_array($task) || !is_array($actor)) return false;
    $taskId = isset($task['id']) ? (int)$task['id'] : 0;
    if ($taskId <= 0) return false;
    $dueDate = trim((string)$dueDate);
    $dueTime = trim((string)$dueTime);
    $message = trim((string)$message);
    $now = trim((string)$now) !== '' ? (string)$now : cpms_tasks_now();

    $st = $pdo->prepare("UPDATE cpms_tasks SET due_date = :due_date, due_time = :due_time, updated_at = :updated_at WHERE id = :id");
    $ok = $st->execute(array(
        ':due_date' => $dueDate !== '' ? $dueDate : null,
        ':due_time' => $dueTime !== '' ? $dueTime : null,
        ':updated_at' => $now,
        ':id' => $taskId,
    ));
    if (!$ok) return false;

    $oldDue = cpms_tasks_due_text_from_values(isset($task['due_date']) ? $task['due_date'] : '', isset($task['due_time']) ? $task['due_time'] : '');
    $newDue = cpms_tasks_due_text_from_values($dueDate, $dueTime);
    $logMessage = '마감: ' . $oldDue . ' -> ' . $newDue;
    if ($message !== '') $logMessage .= "\n" . $message;
    cpms_tasks_insert_log($pdo, $taskId, $actor, 'due_date_changed', $logMessage, isset($task['status']) ? $task['status'] : null, isset($task['status']) ? $task['status'] : null);
    if ($message !== '') cpms_tasks_insert_comment($pdo, $taskId, $actor, $message, 0);
    return true;
}}

if (!function_exists('cpms_tasks_update_content')) {
function cpms_tasks_update_content($pdo, $task, $actor, $title, $content, $message, $now)
{
    if (!$pdo || !is_array($task) || !is_array($actor)) return false;
    $taskId = isset($task['id']) ? (int)$task['id'] : 0;
    if ($taskId <= 0) return false;
    $title = trim((string)$title);
    $content = trim((string)$content);
    $message = trim((string)$message);
    $now = trim((string)$now) !== '' ? (string)$now : cpms_tasks_now();
    if ($title === '') return false;

    $oldTitle = isset($task['title']) ? (string)$task['title'] : '';
    $oldContent = isset($task['content']) ? (string)$task['content'] : '';
    $st = $pdo->prepare("UPDATE cpms_tasks SET title = :title, content = :content, updated_at = :updated_at WHERE id = :id");
    $ok = $st->execute(array(
        ':title' => $title,
        ':content' => $content !== '' ? $content : null,
        ':updated_at' => $now,
        ':id' => $taskId,
    ));
    if (!$ok) return false;

    $logMessage = '업무내용이 수정되었습니다.';
    if ($oldTitle !== $title) $logMessage .= "\n" . '제목: ' . $oldTitle . ' -> ' . $title;
    if ($oldContent !== $content) $logMessage .= "\n" . '내용 변경';
    if ($message !== '') $logMessage .= "\n" . $message;
    cpms_tasks_insert_log($pdo, $taskId, $actor, 'content_changed', $logMessage, isset($task['status']) ? $task['status'] : null, isset($task['status']) ? $task['status'] : null);
    if ($message !== '') cpms_tasks_insert_comment($pdo, $taskId, $actor, $message, 0);
    return true;
}}

if (!function_exists('cpms_tasks_complete_task_and_group')) {
function cpms_tasks_complete_task_and_group($pdo, $task, $actor, $completedMemo, $now)
{
    $result = array('ok' => false, 'completed_ids' => array(), 'synced_ids' => array());
    if (!$pdo || !is_array($task) || !is_array($actor)) return $result;
    $taskId = isset($task['id']) ? (int)$task['id'] : 0;
    if ($taskId <= 0) return $result;
    $actorId = isset($actor['id']) ? (int)$actor['id'] : 0;
    $completedMemo = trim((string)$completedMemo);
    $now = trim((string)$now) !== '' ? (string)$now : cpms_tasks_now();

    $st = $pdo->prepare("UPDATE cpms_tasks SET status = 'done', completed_at = :completed_at, completed_by = :completed_by, completed_memo = :completed_memo, updated_at = :updated_at WHERE id = :id");
    $st->execute(array(
        ':completed_at' => $now,
        ':completed_by' => $actorId > 0 ? $actorId : null,
        ':completed_memo' => $completedMemo !== '' ? $completedMemo : null,
        ':updated_at' => $now,
        ':id' => $taskId,
    ));
    $result['completed_ids'][count($result['completed_ids'])] = $taskId;
    cpms_tasks_insert_log($pdo, $taskId, $actor, 'completed', $completedMemo, isset($task['status']) ? $task['status'] : null, 'done');
    if ($completedMemo !== '') cpms_tasks_insert_comment($pdo, $taskId, $actor, $completedMemo, 0);

    $groupKey = (isset($task['group_key']) && trim((string)$task['group_key']) !== '') ? trim((string)$task['group_key']) : '';
    if ($groupKey !== '' && cpms_tasks_column_exists($pdo, 'cpms_tasks', 'group_key') && cpms_tasks_should_sync_group_completion($groupKey)) {
        $syncMemo = $completedMemo !== '' ? $completedMemo : '공용 할일 묶음 자동 완료';
        $st2 = $pdo->prepare("SELECT * FROM cpms_tasks WHERE group_key=:group_key AND id<>:id");
        $st2->execute(array(':group_key' => $groupKey, ':id' => $taskId));
        $siblings = $st2->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($siblings)) $siblings = array();
        $up = $pdo->prepare("UPDATE cpms_tasks SET status='done', completed_at=:completed_at, completed_by=:completed_by, completed_memo=:completed_memo, updated_at=:updated_at WHERE id=:id");
        for ($i = 0; $i < count($siblings); $i++) {
            if (isset($siblings[$i]['status']) && in_array((string)$siblings[$i]['status'], array('done', 'cancelled'), true)) continue;
            $siblingId = isset($siblings[$i]['id']) ? (int)$siblings[$i]['id'] : 0;
            if ($siblingId <= 0) continue;
            $up->execute(array(
                ':completed_at' => $now,
                ':completed_by' => $actorId > 0 ? $actorId : null,
                ':completed_memo' => $syncMemo,
                ':updated_at' => $now,
                ':id' => $siblingId
            ));
            $result['completed_ids'][count($result['completed_ids'])] = $siblingId;
            $result['synced_ids'][count($result['synced_ids'])] = $siblingId;
            cpms_tasks_insert_log($pdo, $siblingId, $actor, 'completed', $syncMemo, isset($siblings[$i]['status']) ? $siblings[$i]['status'] : null, 'done');
        }
    }
    $result['ok'] = true;
    return $result;
}}

if (!function_exists('cpms_tasks_ensure_comment_schema')) {
function cpms_tasks_ensure_comment_schema($pdo)
{
    if (!$pdo) return false;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_task_comments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            task_id INT NOT NULL,
            parent_comment_id INT NULL,
            comment_text TEXT NOT NULL,
            created_by INT NULL,
            created_by_name VARCHAR(100) NULL,
            created_by_email VARCHAR(190) NULL,
            created_by_photo_path VARCHAR(255) NULL,
            created_at DATETIME NULL,
            KEY idx_task_comments_task (task_id),
            KEY idx_task_comments_parent (parent_comment_id),
            KEY idx_task_comments_created_by (created_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {
        return false;
    }

    $columns = array(
        'task_id' => "ALTER TABLE cpms_task_comments ADD COLUMN task_id INT NOT NULL DEFAULT 0 AFTER id",
        'parent_comment_id' => "ALTER TABLE cpms_task_comments ADD COLUMN parent_comment_id INT NULL AFTER task_id",
        'comment_text' => "ALTER TABLE cpms_task_comments ADD COLUMN comment_text TEXT NOT NULL AFTER parent_comment_id",
        'created_by' => "ALTER TABLE cpms_task_comments ADD COLUMN created_by INT NULL AFTER comment_text",
        'created_by_name' => "ALTER TABLE cpms_task_comments ADD COLUMN created_by_name VARCHAR(100) NULL AFTER created_by",
        'created_by_email' => "ALTER TABLE cpms_task_comments ADD COLUMN created_by_email VARCHAR(190) NULL AFTER created_by_name",
        'created_by_photo_path' => "ALTER TABLE cpms_task_comments ADD COLUMN created_by_photo_path VARCHAR(255) NULL AFTER created_by_email",
        'created_at' => "ALTER TABLE cpms_task_comments ADD COLUMN created_at DATETIME NULL AFTER created_by_photo_path",
    );
    foreach ($columns as $column => $sql) {
        if (!cpms_tasks_column_exists($pdo, 'cpms_task_comments', $column)) {
            try { $pdo->exec($sql); } catch (Exception $e) {}
        }
    }

    $indexes = array(
        'idx_task_comments_task' => "ALTER TABLE cpms_task_comments ADD INDEX idx_task_comments_task (task_id)",
        'idx_task_comments_parent' => "ALTER TABLE cpms_task_comments ADD INDEX idx_task_comments_parent (parent_comment_id)",
        'idx_task_comments_created_by' => "ALTER TABLE cpms_task_comments ADD INDEX idx_task_comments_created_by (created_by)",
    );
    foreach ($indexes as $indexName => $sql) {
        if (!cpms_tasks_index_exists($pdo, 'cpms_task_comments', $indexName)) {
            try { $pdo->exec($sql); } catch (Exception $e) {}
        }
    }
    return true;
}}

if (!function_exists('cpms_tasks_fetch_comments')) {
function cpms_tasks_fetch_comments($pdo, $taskId)
{
    $rows = array();
    if (!$pdo || (int)$taskId <= 0 || !cpms_tasks_ensure_comment_schema($pdo)) return $rows;
    try {
        $st = $pdo->prepare("SELECT * FROM cpms_task_comments WHERE task_id = :task_id ORDER BY parent_comment_id ASC, id ASC");
        $st->execute(array(':task_id' => (int)$taskId));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $rows = array();
    }
    return is_array($rows) ? $rows : array();
}}

if (!function_exists('cpms_tasks_photo_url')) {
function cpms_tasks_photo_url($path)
{
    $path = trim((string)$path);
    if ($path === '') return '';
    if (preg_match('/^https?:\/\//i', $path)) return $path;
    $path = str_replace('\\', '/', $path);
    if (strpos($path, '/') === 0) return $path;
    if (function_exists('base_url')) return base_url() . '/' . ltrim($path, '/');
    return '/' . ltrim($path, '/');
}}

if (!function_exists('cpms_tasks_comment_initial')) {
function cpms_tasks_comment_initial($name)
{
    $name = trim((string)$name);
    if ($name === '') return '?';
    if (function_exists('mb_substr')) return mb_substr($name, 0, 1, 'UTF-8');
    return substr($name, 0, 1);
}}

if (!function_exists('cpms_tasks_build_comment_message')) {
function cpms_tasks_build_comment_message($task, $commentText, $actorName, $isReply)
{
    $lines = array();
    $lines[count($lines)] = $isReply ? '[CPMS 업무 대댓글]' : '[CPMS 업무 댓글]';
    $lines[count($lines)] = '업무명 : ' . (isset($task['title']) ? (string)$task['title'] : '-');
    $lines[count($lines)] = '작성자 : ' . (trim((string)$actorName) !== '' ? (string)$actorName : '-');
    $lines[count($lines)] = '내용 : ' . cpms_tasks_text_excerpt($commentText, 160);
    $lines[count($lines)] = '';
    $lines[count($lines)] = '임원 대시보드 또는 나의 할일에서 확인해주세요.';
    return implode("\n", $lines);
}}

if (!function_exists('cpms_tasks_send_comment_notifications')) {
function cpms_tasks_send_comment_notifications($pdo, $task, $commentText, $actor, $parentAuthorId)
{
    if (!function_exists('cpms_send_google_chat_to_employee')) {
        require_once dirname(dirname(__DIR__)) . '/helpers.php';
    }
    if (!$pdo || !is_array($task) || !function_exists('cpms_send_google_chat_to_employee')) return false;
    $taskId = isset($task['id']) ? (int)$task['id'] : 0;
    $actorId = isset($actor['id']) ? (int)$actor['id'] : 0;
    $actorName = isset($actor['name']) ? (string)$actor['name'] : '';
    $isReply = (int)$parentAuthorId > 0;
    $recipients = array();

    foreach (array('requester_employee_id', 'assignee_employee_id') as $field) {
        $employeeId = isset($task[$field]) ? (int)$task[$field] : 0;
        if ($employeeId > 0) $recipients[$employeeId] = $employeeId;
    }
    if ($actorId > 0) $recipients[$actorId] = $actorId;
    if ((int)$parentAuthorId > 0) $recipients[(int)$parentAuthorId] = (int)$parentAuthorId;

    $message = cpms_tasks_build_comment_message($task, $commentText, $actorName, $isReply);
    foreach ($recipients as $employeeId) {
        cpms_send_google_chat_to_employee($pdo, (int)$employeeId, $message, $taskId, $isReply ? 'TASK_REPLY_COMMENTED' : 'TASK_COMMENTED', 'TASK_COMMENT');
    }
    return true;
}}

if (!function_exists('cpms_tasks_render_comment_item')) {
function cpms_tasks_render_comment_item($comment, $childrenMap, $taskId, $returnUrl, $depth, $allowReplies = true)
{
    $commentId = isset($comment['id']) ? (int)$comment['id'] : 0;
    $name = isset($comment['created_by_name']) && trim((string)$comment['created_by_name']) !== '' ? (string)$comment['created_by_name'] : '작성자';
    $photoUrl = cpms_tasks_photo_url(isset($comment['created_by_photo_path']) ? $comment['created_by_photo_path'] : '');
    $indentClass = ((int)$depth > 0) ? 'ml-8 border-l-4 border-slate-100 pl-4' : '';
    ?>
    <div class="<?php echo h($indentClass); ?>">
        <div class="rounded-2xl border border-gray-200 bg-white p-4">
            <div class="flex items-start gap-3">
                <?php if ($photoUrl !== ''): ?>
                    <img src="<?php echo h($photoUrl); ?>" alt="<?php echo h($name); ?>" class="w-10 h-10 rounded-full object-cover border border-gray-200">
                <?php else: ?>
                    <div class="w-10 h-10 rounded-full bg-slate-100 border border-slate-200 flex items-center justify-center text-sm font-extrabold text-slate-600"><?php echo h(cpms_tasks_comment_initial($name)); ?></div>
                <?php endif; ?>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="font-extrabold text-gray-900"><?php echo h($name); ?></div>
                        <div class="text-xs text-gray-500"><?php echo h(isset($comment['created_at']) ? $comment['created_at'] : ''); ?></div>
                    </div>
                    <div class="mt-2 text-sm text-gray-800 whitespace-pre-line"><?php echo h(isset($comment['comment_text']) ? $comment['comment_text'] : ''); ?></div>
                    <?php if ($allowReplies): ?>
                        <form method="post" action="?r=task_comment_save" class="mt-3 space-y-2" data-task-comment-form>
                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                            <input type="hidden" name="task_id" value="<?php echo (int)$taskId; ?>">
                            <input type="hidden" name="parent_comment_id" value="<?php echo (int)$commentId; ?>">
                            <input type="hidden" name="return_url" value="<?php echo h($returnUrl); ?>">
                            <textarea name="comment_text" rows="4" wrap="soft" required class="block w-full min-h-[7rem] resize-y px-3 py-3 rounded-xl border border-gray-200 bg-white text-sm leading-6" placeholder="대댓글을 입력하세요. 줄바꿈과 긴 내용도 작성할 수 있습니다."></textarea>
                            <div class="flex justify-end">
                                <button type="submit" class="px-4 py-2 rounded-xl bg-gray-900 text-white text-sm font-extrabold">대댓글 등록</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php if (isset($childrenMap[$commentId]) && is_array($childrenMap[$commentId])): ?>
            <div class="mt-3 space-y-3">
                <?php foreach ($childrenMap[$commentId] as $childComment): ?>
                    <?php cpms_tasks_render_comment_item($childComment, $childrenMap, $taskId, $returnUrl, ((int)$depth + 1), $allowReplies); ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
}}

if (!function_exists('cpms_tasks_render_comments')) {
function cpms_tasks_render_comments($comments, $taskId, $returnUrl, $allowReplies = true)
{
    $childrenMap = array();
    $root = array();
    if (!is_array($comments)) $comments = array();
    foreach ($comments as $comment) {
        $parentId = isset($comment['parent_comment_id']) ? (int)$comment['parent_comment_id'] : 0;
        if ($parentId > 0) {
            if (!isset($childrenMap[$parentId])) $childrenMap[$parentId] = array();
            $childrenMap[$parentId][count($childrenMap[$parentId])] = $comment;
        } else {
            $root[count($root)] = $comment;
        }
    }
    ?>
    <div class="space-y-3" data-task-comments-list>
        <?php if (count($root) === 0): ?>
            <div class="p-4 rounded-2xl border border-dashed border-gray-300 text-sm text-gray-500">등록된 댓글이 없습니다.</div>
        <?php else: ?>
            <?php foreach ($root as $comment): ?>
                <?php cpms_tasks_render_comment_item($comment, $childrenMap, $taskId, $returnUrl, 0, $allowReplies); ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php
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

if (!function_exists('cpms_tasks_utf8_message')) {
function cpms_tasks_utf8_message($escaped)
{
    if (function_exists('json_decode')) {
        $decoded = json_decode('"' . (string)$escaped . '"');
        if (is_string($decoded)) return $decoded;
    }
    return (string)$escaped;
}}

if (!function_exists('cpms_tasks_message_task_url')) {
function cpms_tasks_message_task_url($pdo, $taskId, $chatEmployeeId = 0)
{
    $taskId = (int)$taskId;
    if ($taskId <= 0) return '';
    try {
        if (function_exists('cpms_app_dashboard_employee_url')) {
            $url = cpms_app_dashboard_employee_url($pdo, $taskId, $chatEmployeeId);
            if (trim((string)$url) !== '') return (string)$url;
        }
    } catch (Exception $e) {
    }
    $base = '';
    if (isset($_SERVER['HTTP_HOST']) && trim((string)$_SERVER['HTTP_HOST']) !== '') {
        $scheme = 'http';
        if ((isset($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off' && (string)$_SERVER['HTTPS'] !== '') || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)) {
            $scheme = 'https';
        }
        $base = $scheme . '://' . trim((string)$_SERVER['HTTP_HOST']) . (function_exists('base_url') ? base_url() : '');
    } else if (function_exists('base_url')) {
        $base = base_url();
    }
    return rtrim($base, '/') . '/?r=dashboard_employee&task_id=' . $taskId;
}}

if (!function_exists('cpms_tasks_build_create_message')) {
function cpms_tasks_build_create_message($task, $pdo = null)
{
    $lines = array();
    $isMeeting = isset($task['task_type']) && (string)$task['task_type'] === 'meeting';
    $lines[count($lines)] = $isMeeting ? cpms_tasks_utf8_message('[CPMS \ud68c\uc758 \uc694\uccad]') : cpms_tasks_utf8_message('[CPMS \uc5c5\ubb34 \uc694\uccad]');
    $lines[count($lines)] = $isMeeting ? cpms_tasks_utf8_message('\ud68c\uc758 \uc694\uccad\uc774 \ub4f1\ub85d\ub418\uc5c8\uc2b5\ub2c8\ub2e4.') : cpms_tasks_utf8_message('\uc5c5\ubb34 \uc694\uccad\uc774 \ub4f1\ub85d\ub418\uc5c8\uc2b5\ub2c8\ub2e4.');
    $lines[count($lines)] = cpms_tasks_utf8_message('\uc81c\ubaa9 : ') . (isset($task['title']) ? (string)$task['title'] : '-');
    $lines[count($lines)] = cpms_tasks_utf8_message('\uc694\uccad\uc790 : ') . (isset($task['requester_name']) ? (string)$task['requester_name'] : '-');
    $lines[count($lines)] = cpms_tasks_utf8_message('\ub2f4\ub2f9\uc790 : ') . (isset($task['assignee_name']) ? (string)$task['assignee_name'] : '-');
    $dueLine = '-';
    if (!empty($task['due_date'])) {
        $dueLine = (string)$task['due_date'];
        if (!empty($task['due_time'])) $dueLine .= ' ' . substr((string)$task['due_time'], 0, 5);
    }
    $lines[count($lines)] = ($isMeeting ? cpms_tasks_utf8_message('\uc77c\uc2dc : ') : cpms_tasks_utf8_message('\uae30\ud55c : ')) . $dueLine;
    $lines[count($lines)] = cpms_tasks_utf8_message('\uc911\uc694\ub3c4 : ') . cpms_tasks_priority_label(isset($task['priority']) ? $task['priority'] : 'normal');
    $lines[count($lines)] = cpms_tasks_utf8_message('\ub0b4\uc6a9 : ') . cpms_tasks_text_excerpt(isset($task['content']) ? $task['content'] : '', 100);
    $taskUrl = cpms_tasks_message_task_url($pdo, isset($task['id']) ? (int)$task['id'] : 0, isset($task['assignee_employee_id']) ? (int)$task['assignee_employee_id'] : 0);
    if ($taskUrl !== '') $lines[count($lines)] = 'URL : ' . $taskUrl;
    return implode("\n", $lines);
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

if (!function_exists('cpms_tasks_build_completion_pending_message')) {
function cpms_tasks_build_completion_pending_message($task)
{
    $lines = array();
    $lines[count($lines)] = '[CPMS 업무 완료 검토 요청]';
    $lines[count($lines)] = '';
    $lines[count($lines)] = '업무명: ' . (isset($task['title']) ? (string)$task['title'] : '-');
    $lines[count($lines)] = '담당자: ' . (isset($task['assignee_name']) ? (string)$task['assignee_name'] : '-');
    $lines[count($lines)] = '완료 내용: ' . cpms_tasks_text_excerpt(isset($task['completed_memo']) ? $task['completed_memo'] : '', 120);
    $lines[count($lines)] = '';
    $lines[count($lines)] = '나의 요청 업무에서 완료 승인 또는 반려를 선택해주세요.';
    return implode("\n", $lines);
}}

if (!function_exists('cpms_tasks_build_transfer_request_message')) {
function cpms_tasks_build_transfer_request_message($task)
{
    $lines = array();
    $lines[count($lines)] = '[CPMS 업무담당자 변경요청]';
    $lines[count($lines)] = '';
    $lines[count($lines)] = '업무명: ' . (isset($task['title']) ? (string)$task['title'] : '-');
    $lines[count($lines)] = '현재 담당자: ' . (isset($task['assignee_name']) ? (string)$task['assignee_name'] : '-');
    $lines[count($lines)] = '변경 요청 담당자: ' . (isset($task['transfer_request_assignee_name']) ? (string)$task['transfer_request_assignee_name'] : '-');
    $reason = isset($task['transfer_request_reason']) ? trim((string)$task['transfer_request_reason']) : '';
    if ($reason !== '') $lines[count($lines)] = '요청 사유: ' . cpms_tasks_text_excerpt($reason, 160);
    $lines[count($lines)] = '';
    $lines[count($lines)] = '내가 요청한 업무에서 담당자 변경을 승인해주세요.';
    return implode("\n", $lines);
}}

if (!function_exists('cpms_tasks_build_transfer_approved_message')) {
function cpms_tasks_build_transfer_approved_message($task)
{
    $lines = array();
    $lines[count($lines)] = '[CPMS 업무담당자 변경완료]';
    $lines[count($lines)] = '';
    $lines[count($lines)] = '업무명: ' . (isset($task['title']) ? (string)$task['title'] : '-');
    $lines[count($lines)] = '요청자: ' . (isset($task['requester_name']) ? (string)$task['requester_name'] : '-');
    $lines[count($lines)] = '담당자로 지정되었습니다.';
    $lines[count($lines)] = '';
    $lines[count($lines)] = '나의 할일에서 업무를 확인해주세요.';
    return implode("\n", $lines);
}}

if (!function_exists('cpms_tasks_build_completion_rejected_message')) {
function cpms_tasks_build_completion_rejected_message($task, $feedback)
{
    $lines = array();
    $lines[count($lines)] = '[CPMS 업무 완료 반려]';
    $lines[count($lines)] = '';
    $lines[count($lines)] = '업무명: ' . (isset($task['title']) ? (string)$task['title'] : '-');
    $lines[count($lines)] = '요청자: ' . (isset($task['requester_name']) ? (string)$task['requester_name'] : '-');
    $lines[count($lines)] = '피드백: ' . cpms_tasks_text_excerpt($feedback, 160);
    if (!empty($task['due_date'])) {
        $dueLine = (string)$task['due_date'];
        if (!empty($task['due_time'])) $dueLine .= ' ' . substr((string)$task['due_time'], 0, 5);
        $lines[count($lines)] = '마감: ' . $dueLine;
    }
    $lines[count($lines)] = '';
    $lines[count($lines)] = '나의 할일에서 다시 진행해주세요.';
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

if (!function_exists('cpms_tasks_chat_success_exists')) {
function cpms_tasks_chat_success_exists($pdo, $sourceId, $eventType, $sourceType)
{
    if (!$pdo || (int)$sourceId <= 0 || trim((string)$eventType) === '') return false;
    if (!cpms_tasks_table_exists($pdo, 'cpms_google_chat_notifications')) return false;
    try {
        $st = $pdo->prepare("SELECT id FROM cpms_google_chat_notifications
                             WHERE source_id = :source_id
                               AND event_type = :event_type
                               AND source_type = :source_type
                               AND send_status = 'SUCCESS'
                             LIMIT 1");
        $st->execute(array(
            ':source_id' => (int)$sourceId,
            ':event_type' => (string)$eventType,
            ':source_type' => (string)$sourceType,
        ));
        return $st->fetchColumn() !== false;
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_tasks_send_created_notification')) {
function cpms_tasks_send_created_notification($pdo, $task)
{
    $taskId = isset($task['id']) ? (int)$task['id'] : 0;
    try {
        if (!$pdo || !is_array($task) || $taskId <= 0) return false;
        if (cpms_tasks_chat_success_exists($pdo, $taskId, 'TASK_CREATED', 'TASK')) return false;
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

        $ok = cpms_send_google_chat_to_employee($pdo, $assigneeId, cpms_tasks_build_create_message($task, $pdo), $taskId, 'TASK_CREATED', 'TASK');
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

if (!function_exists('cpms_tasks_send_completion_pending_notification')) {
function cpms_tasks_send_completion_pending_notification($pdo, $task)
{
    if (!function_exists('cpms_send_google_chat_to_employee')) {
        require_once dirname(dirname(__DIR__)) . '/helpers.php';
    }
    if (!function_exists('cpms_send_google_chat_to_employee')) return false;
    if (!$pdo || !is_array($task)) return false;
    $requesterId = isset($task['requester_employee_id']) ? (int)$task['requester_employee_id'] : 0;
    $assigneeId = isset($task['assignee_employee_id']) ? (int)$task['assignee_employee_id'] : 0;
    if ($requesterId <= 0 || $requesterId === $assigneeId) return false;
    return cpms_send_google_chat_to_employee($pdo, $requesterId, cpms_tasks_build_completion_pending_message($task), isset($task['id']) ? (int)$task['id'] : 0, 'TASK_COMPLETION_PENDING', 'TASK');
}}

if (!function_exists('cpms_tasks_send_transfer_request_notification')) {
function cpms_tasks_send_transfer_request_notification($pdo, $task)
{
    if (!function_exists('cpms_send_google_chat_to_employee')) {
        require_once dirname(dirname(__DIR__)) . '/helpers.php';
    }
    if (!function_exists('cpms_send_google_chat_to_employee')) return false;
    if (!$pdo || !is_array($task)) return false;
    $requesterId = cpms_tasks_effective_requester_employee_id($task);
    if ($requesterId <= 0) return false;
    return cpms_send_google_chat_to_employee($pdo, $requesterId, cpms_tasks_build_transfer_request_message($task), isset($task['id']) ? (int)$task['id'] : 0, 'TASK_TRANSFER_REQUESTED', 'TASK');
}}

if (!function_exists('cpms_tasks_send_transfer_approved_notification')) {
function cpms_tasks_send_transfer_approved_notification($pdo, $task)
{
    if (!function_exists('cpms_send_google_chat_to_employee')) {
        require_once dirname(dirname(__DIR__)) . '/helpers.php';
    }
    if (!function_exists('cpms_send_google_chat_to_employee')) return false;
    if (!$pdo || !is_array($task)) return false;
    $assigneeId = isset($task['assignee_employee_id']) ? (int)$task['assignee_employee_id'] : 0;
    if ($assigneeId <= 0) return false;
    return cpms_send_google_chat_to_employee($pdo, $assigneeId, cpms_tasks_build_transfer_approved_message($task), isset($task['id']) ? (int)$task['id'] : 0, 'TASK_TRANSFER_APPROVED', 'TASK');
}}

if (!function_exists('cpms_tasks_send_completion_rejected_notification')) {
function cpms_tasks_send_completion_rejected_notification($pdo, $task, $feedback)
{
    if (!function_exists('cpms_send_google_chat_to_employee')) {
        require_once dirname(dirname(__DIR__)) . '/helpers.php';
    }
    if (!function_exists('cpms_send_google_chat_to_employee')) return false;
    if (!$pdo || !is_array($task)) return false;
    $assigneeId = isset($task['assignee_employee_id']) ? (int)$task['assignee_employee_id'] : 0;
    if ($assigneeId <= 0) return false;
    return cpms_send_google_chat_to_employee($pdo, $assigneeId, cpms_tasks_build_completion_rejected_message($task, $feedback), isset($task['id']) ? (int)$task['id'] : 0, 'TASK_COMPLETION_REJECTED', 'TASK');
}}

if (!function_exists('cpms_tasks_ensure_delay_notification_schema')) {
function cpms_tasks_ensure_delay_notification_schema($pdo)
{
    if (!$pdo) return false;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_task_delay_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            task_id INT NOT NULL,
            assignee_employee_id INT NOT NULL DEFAULT 0,
            slot_key VARCHAR(100) NOT NULL,
            slot_type VARCHAR(30) NOT NULL,
            slot_date DATE NULL,
            scheduled_at DATETIME NULL,
            sent_at DATETIME NULL,
            send_result TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NULL,
            UNIQUE KEY uniq_task_delay_slot (task_id, slot_key),
            KEY idx_task_delay_task (task_id),
            KEY idx_task_delay_assignee (assignee_employee_id),
            KEY idx_task_delay_slot_date (slot_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {
        return false;
    }

    $columns = array(
        'task_id' => "ALTER TABLE cpms_task_delay_notifications ADD COLUMN task_id INT NOT NULL DEFAULT 0 AFTER id",
        'assignee_employee_id' => "ALTER TABLE cpms_task_delay_notifications ADD COLUMN assignee_employee_id INT NOT NULL DEFAULT 0 AFTER task_id",
        'slot_key' => "ALTER TABLE cpms_task_delay_notifications ADD COLUMN slot_key VARCHAR(100) NOT NULL AFTER assignee_employee_id",
        'slot_type' => "ALTER TABLE cpms_task_delay_notifications ADD COLUMN slot_type VARCHAR(30) NOT NULL AFTER slot_key",
        'slot_date' => "ALTER TABLE cpms_task_delay_notifications ADD COLUMN slot_date DATE NULL AFTER slot_type",
        'scheduled_at' => "ALTER TABLE cpms_task_delay_notifications ADD COLUMN scheduled_at DATETIME NULL AFTER slot_date",
        'sent_at' => "ALTER TABLE cpms_task_delay_notifications ADD COLUMN sent_at DATETIME NULL AFTER scheduled_at",
        'send_result' => "ALTER TABLE cpms_task_delay_notifications ADD COLUMN send_result TINYINT(1) NOT NULL DEFAULT 0 AFTER sent_at",
        'created_at' => "ALTER TABLE cpms_task_delay_notifications ADD COLUMN created_at DATETIME NULL AFTER send_result",
    );
    foreach ($columns as $column => $sql) {
        if (!cpms_tasks_column_exists($pdo, 'cpms_task_delay_notifications', $column)) {
            try { $pdo->exec($sql); } catch (Exception $e) {}
        }
    }

    $indexes = array(
        'uniq_task_delay_slot' => "ALTER TABLE cpms_task_delay_notifications ADD UNIQUE KEY uniq_task_delay_slot (task_id, slot_key)",
        'idx_task_delay_task' => "ALTER TABLE cpms_task_delay_notifications ADD INDEX idx_task_delay_task (task_id)",
        'idx_task_delay_assignee' => "ALTER TABLE cpms_task_delay_notifications ADD INDEX idx_task_delay_assignee (assignee_employee_id)",
        'idx_task_delay_slot_date' => "ALTER TABLE cpms_task_delay_notifications ADD INDEX idx_task_delay_slot_date (slot_date)",
    );
    foreach ($indexes as $indexName => $sql) {
        if (!cpms_tasks_index_exists($pdo, 'cpms_task_delay_notifications', $indexName)) {
            try { $pdo->exec($sql); } catch (Exception $e) {}
        }
    }
    return true;
}}

if (!function_exists('cpms_tasks_delay_notification_base_url')) {
function cpms_tasks_delay_notification_base_url($pdo)
{
    if (function_exists('cpms_public_base_url')) {
        return cpms_public_base_url($pdo);
    }
    $base = '';
    try {
        if (!function_exists('approval_google_chat_setting')) {
            require_once dirname(__DIR__) . '/approval/google_chat_helpers.php';
        }
        if (function_exists('approval_google_chat_setting')) {
            $base = trim((string)approval_google_chat_setting($pdo, 'google_chat_public_base_url', ''));
        }
    } catch (Exception $e) {
        $base = '';
    }
    if ($base === '' && isset($_SERVER['HTTP_HOST']) && trim((string)$_SERVER['HTTP_HOST']) !== '') {
        $scheme = 'http';
        if ((isset($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off' && (string)$_SERVER['HTTPS'] !== '') || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)) {
            $scheme = 'https';
        }
        $base = $scheme . '://' . trim((string)$_SERVER['HTTP_HOST']) . (function_exists('base_url') ? base_url() : '');
    }
    if ($base === '') $base = function_exists('base_url') ? base_url() : '';
    return rtrim($base, '/');
}}

if (!function_exists('cpms_tasks_delay_task_url')) {
function cpms_tasks_delay_task_url($pdo, $taskId, $chatEmployeeId = 0)
{
    if (function_exists('cpms_app_dashboard_employee_url')) {
        return cpms_app_dashboard_employee_url($pdo, (int)$taskId, $chatEmployeeId);
    }
    $base = cpms_tasks_delay_notification_base_url($pdo);
    $path = '/?r=dashboard_employee&task_id=' . (int)$taskId;
    return $base !== '' ? $base . $path : $path;
}}

if (!function_exists('cpms_tasks_utf8_message')) {
function cpms_tasks_utf8_message($escaped)
{
    if (function_exists('json_decode')) {
        $decoded = json_decode('"' . (string)$escaped . '"');
        if (is_string($decoded)) return $decoded;
    }
    return (string)$escaped;
}}

if (!function_exists('cpms_tasks_build_delay_message')) {
function cpms_tasks_build_delay_message($pdo, $task, $slotType)
{
    $dueText = cpms_tasks_due_datetime($task);
    $lines = array();
    $lines[count($lines)] = cpms_tasks_utf8_message('[CPMS \uc5c5\ubb34 \uc9c0\uc5f0 \uc54c\ub9bc]');
    $lines[count($lines)] = cpms_tasks_utf8_message('\uc5c5\ubb34\uac00 \uc9c0\uc5f0\ub42c\uc2b5\ub2c8\ub2e4 \ud655\uc778 \ud574\uc8fc\uc138\uc694');
    $lines[count($lines)] = cpms_tasks_utf8_message('\uc5c5\ubb34\uba85 : ') . (isset($task['title']) ? (string)$task['title'] : '-');
    $lines[count($lines)] = cpms_tasks_utf8_message('\ub2f4\ub2f9\uc790 : ') . (isset($task['assignee_name']) ? (string)$task['assignee_name'] : '-');
    $lines[count($lines)] = cpms_tasks_utf8_message('\ub9c8\uac10 : ') . ($dueText !== '' ? $dueText : '-');
    if (isset($task['project_name']) && trim((string)$task['project_name']) !== '') {
        $lines[count($lines)] = cpms_tasks_utf8_message('\ud604\uc7a5 : ') . (string)$task['project_name'];
    }
    $lines[count($lines)] = 'URL : ' . cpms_tasks_delay_task_url($pdo, isset($task['id']) ? (int)$task['id'] : 0, isset($task['assignee_employee_id']) ? (int)$task['assignee_employee_id'] : 0);
    return implode("\n", $lines);
}}

if (!function_exists('cpms_tasks_reserve_delay_notification')) {
function cpms_tasks_reserve_delay_notification($pdo, $task, $slotKey, $slotType, $slotDate, $scheduledAt)
{
    if (!$pdo || !is_array($task) || trim((string)$slotKey) === '') return false;
    try {
        $st = $pdo->prepare("INSERT IGNORE INTO cpms_task_delay_notifications
            (task_id, assignee_employee_id, slot_key, slot_type, slot_date, scheduled_at, created_at)
            VALUES (:task_id, :assignee_employee_id, :slot_key, :slot_type, :slot_date, :scheduled_at, :created_at)");
        $st->execute(array(
            ':task_id' => isset($task['id']) ? (int)$task['id'] : 0,
            ':assignee_employee_id' => isset($task['assignee_employee_id']) ? (int)$task['assignee_employee_id'] : 0,
            ':slot_key' => (string)$slotKey,
            ':slot_type' => (string)$slotType,
            ':slot_date' => trim((string)$slotDate) !== '' ? (string)$slotDate : null,
            ':scheduled_at' => trim((string)$scheduledAt) !== '' ? (string)$scheduledAt : null,
            ':created_at' => cpms_tasks_now(),
        ));
        return ((int)$st->rowCount() > 0);
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_tasks_delay_notification_exists')) {
function cpms_tasks_delay_notification_exists($pdo, $taskId, $slotKey)
{
    if (!$pdo || (int)$taskId <= 0 || trim((string)$slotKey) === '') return false;
    try {
        $st = $pdo->prepare("SELECT id FROM cpms_task_delay_notifications WHERE task_id = :task_id AND slot_key = :slot_key LIMIT 1");
        $st->execute(array(
            ':task_id' => (int)$taskId,
            ':slot_key' => (string)$slotKey,
        ));
        return $st->fetchColumn() !== false;
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_tasks_mark_delay_notification_sent')) {
function cpms_tasks_mark_delay_notification_sent($pdo, $taskId, $slotKey, $ok)
{
    if (!$pdo || (int)$taskId <= 0 || trim((string)$slotKey) === '') return;
    try {
        $st = $pdo->prepare("UPDATE cpms_task_delay_notifications
                             SET sent_at = :sent_at, send_result = :send_result
                             WHERE task_id = :task_id AND slot_key = :slot_key");
        $st->execute(array(
            ':sent_at' => cpms_tasks_now(),
            ':send_result' => $ok ? 1 : 0,
            ':task_id' => (int)$taskId,
            ':slot_key' => (string)$slotKey,
        ));
    } catch (Exception $e) {
    }
}}

if (!function_exists('cpms_tasks_delay_slot_for_now')) {
function cpms_tasks_delay_slot_for_now($task, $nowTs)
{
    $dueAt = cpms_tasks_due_datetime($task);
    if ($dueAt === '') return null;
    $dueTs = strtotime($dueAt);
    if ($dueTs === false || $dueTs >= $nowTs) return null;
    $dueSlotKey = 'due:' . date('YmdHi', $dueTs);

    $dueDate = substr($dueAt, 0, 10);
    $today = date('Y-m-d', $nowTs);
    if (strcmp($today, $dueDate) <= 0) {
        return array('key' => $dueSlotKey, 'type' => 'due', 'date' => $dueDate, 'scheduled_at' => date('Y-m-d H:i:s', $dueTs));
    }

    $hourMinute = date('H:i', $nowTs);
    if ($hourMinute >= '17:00') {
        return array('key' => 'pm:' . date('Ymd', $nowTs), 'type' => 'pm', 'date' => $today, 'scheduled_at' => $today . ' 17:00:00');
    }
    if ($hourMinute >= '08:00') {
        return array('key' => 'am:' . date('Ymd', $nowTs), 'type' => 'am', 'date' => $today, 'scheduled_at' => $today . ' 08:00:00');
    }
    return array('key' => $dueSlotKey, 'type' => 'due', 'date' => $dueDate, 'scheduled_at' => date('Y-m-d H:i:s', $dueTs));
}}

if (!function_exists('cpms_tasks_process_delayed_notifications')) {
function cpms_tasks_process_delayed_notifications($pdo, $limit)
{
    $result = array(
        'checked' => 0,
        'reserved' => 0,
        'sent' => 0,
        'failed' => 0,
        'skipped' => 0,
        'monthly_summary' => array('ok' => true, 'skipped' => true, 'message' => 'not due')
    );
    $monthlySummaryDay = (int)date('d');
    $monthlySummaryLastDay = (int)date('t');
    if ($pdo && date('H:i') === '23:59' && in_array($monthlySummaryDay, array(10, 20, 25, $monthlySummaryLastDay), true)) {
        try {
            require_once dirname(dirname(__DIR__)) . '/services/PublicAffairsMonthlySummaryPdfService.php';
            $result['monthly_summary'] = cpms_public_affairs_monthly_summary_generate(
                $pdo,
                date('Y-m'),
                date('Y-m-d'),
                'system',
                array('mode' => 'cron', 'force' => false)
            );
        } catch (Exception $e) {
            $result['monthly_summary'] = array('ok' => false, 'skipped' => false, 'message' => $e->getMessage());
        }
    }
    if (!$pdo || !cpms_tasks_table_exists($pdo, 'cpms_tasks') || !cpms_tasks_ensure_delay_notification_schema($pdo)) return $result;
    if (!function_exists('cpms_send_google_chat_to_employee')) {
        require_once dirname(dirname(__DIR__)) . '/helpers.php';
    }
    if (!function_exists('cpms_send_google_chat_to_employee')) return $result;
    $limit = (int)$limit;
    if ($limit <= 0) $limit = 100;
    if ($limit > 500) $limit = 500;

    try {
        $sql = "SELECT * FROM cpms_tasks
                WHERE due_date IS NOT NULL AND due_date <> ''
                  AND due_date <= :today
                  AND assignee_employee_id IS NOT NULL AND assignee_employee_id > 0
                  AND (status IS NULL OR status NOT IN ('done','cancelled','APPROVED','REJECTED','approved','rejected','meeting_owner','meeting_available','meeting_unavailable'))
                ORDER BY due_date ASC, due_time ASC, id ASC
                LIMIT " . (int)$limit;
        $st = $pdo->prepare($sql);
        $st->execute(array(':today' => cpms_tasks_today()));
        $tasks = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($tasks)) $tasks = array();
    } catch (Exception $e) {
        return $result;
    }

    $nowTs = time();
    foreach ($tasks as $task) {
        $result['checked']++;
        if (!cpms_tasks_is_delayed($task)) {
            $result['skipped']++;
            continue;
        }
        $slot = null;
        $dueAt = cpms_tasks_due_datetime($task);
        $dueTs = $dueAt !== '' ? strtotime($dueAt) : false;
        $taskId = isset($task['id']) ? (int)$task['id'] : 0;
        if ($dueTs !== false && $dueTs < $nowTs) {
            $dueSlotKey = 'due:' . date('YmdHi', $dueTs);
            if (!cpms_tasks_delay_notification_exists($pdo, $taskId, $dueSlotKey)) {
                $slot = array(
                    'key' => $dueSlotKey,
                    'type' => 'due',
                    'date' => substr($dueAt, 0, 10),
                    'scheduled_at' => date('Y-m-d H:i:s', $dueTs),
                );
            }
        }
        if (!is_array($slot)) {
            $slot = cpms_tasks_delay_slot_for_now($task, $nowTs);
        }
        if (!is_array($slot) || !isset($slot['key'])) {
            $result['skipped']++;
            continue;
        }
        if (!cpms_tasks_reserve_delay_notification($pdo, $task, $slot['key'], isset($slot['type']) ? $slot['type'] : '', isset($slot['date']) ? $slot['date'] : '', isset($slot['scheduled_at']) ? $slot['scheduled_at'] : '')) {
            $result['skipped']++;
            continue;
        }
        $result['reserved']++;
        $assigneeId = isset($task['assignee_employee_id']) ? (int)$task['assignee_employee_id'] : 0;
        $eventType = 'TASK_DELAYED_' . strtoupper((string)$slot['type']);
        $ok = cpms_send_google_chat_to_employee($pdo, $assigneeId, cpms_tasks_build_delay_message($pdo, $task, isset($slot['type']) ? $slot['type'] : ''), $taskId, $eventType, 'TASK_DELAYED');
        cpms_tasks_mark_delay_notification_sent($pdo, $taskId, $slot['key'], $ok);
        if ($ok) $result['sent']++;
        else $result['failed']++;
    }
    return $result;
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
            chat_notified_at DATETIME NULL,
            read_at DATETIME NULL,
            read_by INT NULL,
            transferred_from_employee_id INT NULL,
            transferred_from_name VARCHAR(100) NULL,
            transferred_by INT NULL,
            transferred_at DATETIME NULL,
            transfer_request_assignee_employee_id INT NULL,
            transfer_request_assignee_name VARCHAR(100) NULL,
            transfer_request_reason TEXT NULL,
            transfer_requested_by INT NULL,
            transfer_requested_by_name VARCHAR(100) NULL,
            transfer_requested_at DATETIME NULL,
            group_key VARCHAR(190) NULL
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
            uploaded_at DATETIME NULL,
            file_role VARCHAR(30) NULL,
            storage_type VARCHAR(30) NULL,
            drive_name VARCHAR(255) NULL,
            drive_file_id VARCHAR(128) NULL,
            drive_folder_id VARCHAR(128) NULL,
            drive_web_view_link TEXT NULL,
            drive_web_content_link TEXT NULL,
            drive_root_folder_id VARCHAR(128) NULL,
            drive_employee_folder_id VARCHAR(128) NULL,
            drive_month_folder_id VARCHAR(128) NULL,
            drive_stage_folder_id VARCHAR(128) NULL,
            upload_status VARCHAR(30) NULL,
            drive_upload_error TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'cpms_task_comments' => "CREATE TABLE IF NOT EXISTS cpms_task_comments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            task_id INT NOT NULL,
            parent_comment_id INT NULL,
            comment_text TEXT NOT NULL,
            created_by INT NULL,
            created_by_name VARCHAR(100) NULL,
            created_by_email VARCHAR(190) NULL,
            created_by_photo_path VARCHAR(255) NULL,
            created_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'cpms_task_delay_notifications' => "CREATE TABLE IF NOT EXISTS cpms_task_delay_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            task_id INT NOT NULL,
            assignee_employee_id INT NOT NULL DEFAULT 0,
            slot_key VARCHAR(100) NOT NULL,
            slot_type VARCHAR(30) NOT NULL,
            slot_date DATE NULL,
            scheduled_at DATETIME NULL,
            sent_at DATETIME NULL,
            send_result TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NULL,
            UNIQUE KEY uniq_task_delay_slot (task_id, slot_key),
            KEY idx_task_delay_task (task_id),
            KEY idx_task_delay_assignee (assignee_employee_id),
            KEY idx_task_delay_slot_date (slot_date)
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
            'read_at' => "ALTER TABLE cpms_tasks ADD COLUMN read_at DATETIME NULL AFTER chat_notified_at",
            'read_by' => "ALTER TABLE cpms_tasks ADD COLUMN read_by INT NULL AFTER read_at",
            'transferred_from_employee_id' => "ALTER TABLE cpms_tasks ADD COLUMN transferred_from_employee_id INT NULL AFTER read_by",
            'transferred_from_name' => "ALTER TABLE cpms_tasks ADD COLUMN transferred_from_name VARCHAR(100) NULL AFTER transferred_from_employee_id",
            'transferred_by' => "ALTER TABLE cpms_tasks ADD COLUMN transferred_by INT NULL AFTER transferred_from_name",
            'transferred_at' => "ALTER TABLE cpms_tasks ADD COLUMN transferred_at DATETIME NULL AFTER transferred_by",
            'transfer_request_assignee_employee_id' => "ALTER TABLE cpms_tasks ADD COLUMN transfer_request_assignee_employee_id INT NULL AFTER transferred_at",
            'transfer_request_assignee_name' => "ALTER TABLE cpms_tasks ADD COLUMN transfer_request_assignee_name VARCHAR(100) NULL AFTER transfer_request_assignee_employee_id",
            'transfer_request_reason' => "ALTER TABLE cpms_tasks ADD COLUMN transfer_request_reason TEXT NULL AFTER transfer_request_assignee_name",
            'transfer_requested_by' => "ALTER TABLE cpms_tasks ADD COLUMN transfer_requested_by INT NULL AFTER transfer_request_reason",
            'transfer_requested_by_name' => "ALTER TABLE cpms_tasks ADD COLUMN transfer_requested_by_name VARCHAR(100) NULL AFTER transfer_requested_by",
            'transfer_requested_at' => "ALTER TABLE cpms_tasks ADD COLUMN transfer_requested_at DATETIME NULL AFTER transfer_requested_by_name",
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
            'file_role' => "ALTER TABLE cpms_task_files ADD COLUMN file_role VARCHAR(30) NULL AFTER uploaded_at",
            'storage_type' => "ALTER TABLE cpms_task_files ADD COLUMN storage_type VARCHAR(30) NULL AFTER file_role",
            'drive_name' => "ALTER TABLE cpms_task_files ADD COLUMN drive_name VARCHAR(255) NULL AFTER storage_type",
            'drive_file_id' => "ALTER TABLE cpms_task_files ADD COLUMN drive_file_id VARCHAR(128) NULL AFTER drive_name",
            'drive_folder_id' => "ALTER TABLE cpms_task_files ADD COLUMN drive_folder_id VARCHAR(128) NULL AFTER drive_file_id",
            'drive_web_view_link' => "ALTER TABLE cpms_task_files ADD COLUMN drive_web_view_link TEXT NULL AFTER drive_folder_id",
            'drive_web_content_link' => "ALTER TABLE cpms_task_files ADD COLUMN drive_web_content_link TEXT NULL AFTER drive_web_view_link",
            'drive_root_folder_id' => "ALTER TABLE cpms_task_files ADD COLUMN drive_root_folder_id VARCHAR(128) NULL AFTER drive_web_content_link",
            'drive_employee_folder_id' => "ALTER TABLE cpms_task_files ADD COLUMN drive_employee_folder_id VARCHAR(128) NULL AFTER drive_root_folder_id",
            'drive_month_folder_id' => "ALTER TABLE cpms_task_files ADD COLUMN drive_month_folder_id VARCHAR(128) NULL AFTER drive_employee_folder_id",
            'drive_stage_folder_id' => "ALTER TABLE cpms_task_files ADD COLUMN drive_stage_folder_id VARCHAR(128) NULL AFTER drive_month_folder_id",
            'upload_status' => "ALTER TABLE cpms_task_files ADD COLUMN upload_status VARCHAR(30) NULL AFTER drive_stage_folder_id",
            'drive_upload_error' => "ALTER TABLE cpms_task_files ADD COLUMN drive_upload_error TEXT NULL AFTER upload_status",
        ),
        'cpms_task_comments' => array(
            'task_id' => "ALTER TABLE cpms_task_comments ADD COLUMN task_id INT NOT NULL DEFAULT 0 AFTER id",
            'parent_comment_id' => "ALTER TABLE cpms_task_comments ADD COLUMN parent_comment_id INT NULL AFTER task_id",
            'comment_text' => "ALTER TABLE cpms_task_comments ADD COLUMN comment_text TEXT NOT NULL AFTER parent_comment_id",
            'created_by' => "ALTER TABLE cpms_task_comments ADD COLUMN created_by INT NULL AFTER comment_text",
            'created_by_name' => "ALTER TABLE cpms_task_comments ADD COLUMN created_by_name VARCHAR(100) NULL AFTER created_by",
            'created_by_email' => "ALTER TABLE cpms_task_comments ADD COLUMN created_by_email VARCHAR(190) NULL AFTER created_by_name",
            'created_by_photo_path' => "ALTER TABLE cpms_task_comments ADD COLUMN created_by_photo_path VARCHAR(255) NULL AFTER created_by_email",
            'created_at' => "ALTER TABLE cpms_task_comments ADD COLUMN created_at DATETIME NULL AFTER created_by_photo_path",
        ),
        'cpms_task_delay_notifications' => array(
            'task_id' => "ALTER TABLE cpms_task_delay_notifications ADD COLUMN task_id INT NOT NULL DEFAULT 0 AFTER id",
            'assignee_employee_id' => "ALTER TABLE cpms_task_delay_notifications ADD COLUMN assignee_employee_id INT NOT NULL DEFAULT 0 AFTER task_id",
            'slot_key' => "ALTER TABLE cpms_task_delay_notifications ADD COLUMN slot_key VARCHAR(100) NOT NULL AFTER assignee_employee_id",
            'slot_type' => "ALTER TABLE cpms_task_delay_notifications ADD COLUMN slot_type VARCHAR(30) NOT NULL AFTER slot_key",
            'slot_date' => "ALTER TABLE cpms_task_delay_notifications ADD COLUMN slot_date DATE NULL AFTER slot_type",
            'scheduled_at' => "ALTER TABLE cpms_task_delay_notifications ADD COLUMN scheduled_at DATETIME NULL AFTER slot_date",
            'sent_at' => "ALTER TABLE cpms_task_delay_notifications ADD COLUMN sent_at DATETIME NULL AFTER scheduled_at",
            'send_result' => "ALTER TABLE cpms_task_delay_notifications ADD COLUMN send_result TINYINT(1) NOT NULL DEFAULT 0 AFTER sent_at",
            'created_at' => "ALTER TABLE cpms_task_delay_notifications ADD COLUMN created_at DATETIME NULL AFTER send_result",
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
            'idx_read_at' => "ALTER TABLE cpms_tasks ADD INDEX idx_read_at (read_at)",
        ),
        'cpms_task_logs' => array(
            'idx_task_id' => "ALTER TABLE cpms_task_logs ADD INDEX idx_task_id (task_id)",
            'idx_action_type' => "ALTER TABLE cpms_task_logs ADD INDEX idx_action_type (action_type)",
        ),
        'cpms_task_files' => array(
            'idx_task_id' => "ALTER TABLE cpms_task_files ADD INDEX idx_task_id (task_id)",
            'idx_file_role' => "ALTER TABLE cpms_task_files ADD INDEX idx_file_role (file_role)",
            'idx_drive_file_id' => "ALTER TABLE cpms_task_files ADD INDEX idx_drive_file_id (drive_file_id)",
        ),
        'cpms_task_comments' => array(
            'idx_task_comments_task' => "ALTER TABLE cpms_task_comments ADD INDEX idx_task_comments_task (task_id)",
            'idx_task_comments_parent' => "ALTER TABLE cpms_task_comments ADD INDEX idx_task_comments_parent (parent_comment_id)",
            'idx_task_comments_created_by' => "ALTER TABLE cpms_task_comments ADD INDEX idx_task_comments_created_by (created_by)",
        ),
        'cpms_task_delay_notifications' => array(
            'uniq_task_delay_slot' => "ALTER TABLE cpms_task_delay_notifications ADD UNIQUE KEY uniq_task_delay_slot (task_id, slot_key)",
            'idx_task_delay_task' => "ALTER TABLE cpms_task_delay_notifications ADD INDEX idx_task_delay_task (task_id)",
            'idx_task_delay_assignee' => "ALTER TABLE cpms_task_delay_notifications ADD INDEX idx_task_delay_assignee (assignee_employee_id)",
            'idx_task_delay_slot_date' => "ALTER TABLE cpms_task_delay_notifications ADD INDEX idx_task_delay_slot_date (slot_date)",
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
