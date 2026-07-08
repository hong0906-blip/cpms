<?php
use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/../tasks/helpers.php';

if (!Auth::check()) {
    header('Location: ?r=login');
    exit;
}

if (!function_exists('cpms_scheduler_valid_date')) {
function cpms_scheduler_valid_date($value)
{
    $value = trim((string)$value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return '';
    $ts = strtotime($value);
    if ($ts === false) return '';
    return date('Y-m-d', $ts) === $value ? $value : '';
}}

if (!function_exists('cpms_scheduler_valid_month')) {
function cpms_scheduler_valid_month($value)
{
    $value = trim((string)$value);
    if (!preg_match('/^\d{4}-\d{2}$/', $value)) return '';
    $date = cpms_scheduler_valid_date($value . '-01');
    return $date !== '' ? substr($date, 0, 7) : '';
}}

if (!function_exists('cpms_scheduler_date_label')) {
function cpms_scheduler_date_label($date)
{
    $date = cpms_scheduler_valid_date($date);
    if ($date === '') return '-';
    return date('Y.m.d', strtotime($date));
}}

if (!function_exists('cpms_scheduler_completed_date')) {
function cpms_scheduler_completed_date($task)
{
    if (!is_array($task)) return '';
    $completedAt = isset($task['completed_at']) ? trim((string)$task['completed_at']) : '';
    if ($completedAt === '' || $completedAt === '0000-00-00 00:00:00') return '';
    return cpms_scheduler_valid_date(substr($completedAt, 0, 10));
}}

if (!function_exists('cpms_scheduler_effective_date')) {
function cpms_scheduler_effective_date($task)
{
    if (!is_array($task)) return '';
    $dueDate = cpms_scheduler_valid_date(isset($task['due_date']) ? $task['due_date'] : '');
    if ($dueDate === '') return '';
    $status = isset($task['status']) ? trim((string)$task['status']) : '';
    if ($status === 'done') {
        $completedDate = cpms_scheduler_completed_date($task);
        if ($completedDate !== '' && strcmp($completedDate, $dueDate) < 0) return $completedDate;
    }
    return $dueDate;
}}

if (!function_exists('cpms_scheduler_effective_kind')) {
function cpms_scheduler_effective_kind($task)
{
    if (!is_array($task)) return 'due';
    $dueDate = cpms_scheduler_valid_date(isset($task['due_date']) ? $task['due_date'] : '');
    $effectiveDate = cpms_scheduler_effective_date($task);
    return ($dueDate !== '' && $effectiveDate !== '' && $effectiveDate !== $dueDate) ? 'completed' : 'due';
}}

if (!function_exists('cpms_scheduler_month_bounds')) {
function cpms_scheduler_month_bounds($month)
{
    $month = cpms_scheduler_valid_month($month);
    if ($month === '') $month = date('Y-m');
    $monthStart = $month . '-01';
    $monthEnd = date('Y-m-t', strtotime($monthStart));
    $startWeekday = (int)date('w', strtotime($monthStart));
    $endWeekday = (int)date('w', strtotime($monthEnd));
    $gridStart = date('Y-m-d', strtotime($monthStart . ' -' . $startWeekday . ' days'));
    $gridEnd = date('Y-m-d', strtotime($monthEnd . ' +' . (6 - $endWeekday) . ' days'));
    return array(
        'month' => $month,
        'month_start' => $monthStart,
        'month_end' => $monthEnd,
        'grid_start' => $gridStart,
        'grid_end' => $gridEnd,
        'label' => date('Y년 n월', strtotime($monthStart)),
    );
}}

if (!function_exists('cpms_scheduler_week_bounds')) {
function cpms_scheduler_week_bounds($date)
{
    $date = cpms_scheduler_valid_date($date);
    if ($date === '') $date = date('Y-m-d');
    $weekday = (int)date('w', strtotime($date));
    $weekStart = date('Y-m-d', strtotime($date . ' -' . $weekday . ' days'));
    $weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));
    return array(
        'date' => $date,
        'week_start' => $weekStart,
        'week_end' => $weekEnd,
        'label' => cpms_scheduler_date_label($weekStart) . ' - ' . cpms_scheduler_date_label($weekEnd),
    );
}}

if (!function_exists('cpms_scheduler_days_between')) {
function cpms_scheduler_days_between($startDate, $endDate)
{
    $days = array();
    $startDate = cpms_scheduler_valid_date($startDate);
    $endDate = cpms_scheduler_valid_date($endDate);
    if ($startDate === '' || $endDate === '') return $days;
    $ts = strtotime($startDate);
    $endTs = strtotime($endDate);
    while ($ts !== false && $ts <= $endTs) {
        $days[] = date('Y-m-d', $ts);
        $ts = strtotime(date('Y-m-d', $ts) . ' +1 day');
    }
    return $days;
}}

if (!function_exists('cpms_scheduler_fetch_active_employees')) {
function cpms_scheduler_fetch_active_employees($pdo)
{
    $rows = array();
    if (!$pdo || !cpms_tasks_table_exists($pdo, 'employees')) return $rows;

    $columns = array('id', 'name', 'email');
    $columns[] = cpms_tasks_column_exists($pdo, 'employees', 'department') ? 'department' : "'' AS department";
    $columns[] = cpms_tasks_column_exists($pdo, 'employees', 'position') ? 'position' : "'' AS position";
    $columns[] = cpms_tasks_column_exists($pdo, 'employees', 'role') ? 'role' : "'employee' AS role";
    $columns[] = cpms_tasks_column_exists($pdo, 'employees', 'is_team_leader') ? 'is_team_leader' : '0 AS is_team_leader';
    $columns[] = cpms_tasks_column_exists($pdo, 'employees', 'team_leader_id') ? 'team_leader_id' : '0 AS team_leader_id';
    $columns[] = cpms_tasks_column_exists($pdo, 'employees', 'approval_can_be_team_leader') ? 'approval_can_be_team_leader' : '0 AS approval_can_be_team_leader';
    $where = cpms_tasks_column_exists($pdo, 'employees', 'is_active') ? ' WHERE is_active = 1' : '';

    try {
        $sql = 'SELECT ' . implode(', ', $columns) . ' FROM employees' . $where . ' ORDER BY department ASC, position ASC, name ASC, id ASC';
        $st = $pdo->query($sql);
        $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
    } catch (Exception $e) {
        $rows = array();
    }

    if (!is_array($rows)) $rows = array();
    for ($i = 0; $i < count($rows); $i++) {
        $rows[$i]['id'] = isset($rows[$i]['id']) ? (int)$rows[$i]['id'] : 0;
        $rows[$i]['department'] = cpms_tasks_normalize_department(isset($rows[$i]['department']) ? $rows[$i]['department'] : '');
        $rows[$i]['is_team_leader'] = isset($rows[$i]['is_team_leader']) ? (int)$rows[$i]['is_team_leader'] : 0;
        $rows[$i]['team_leader_id'] = isset($rows[$i]['team_leader_id']) ? (int)$rows[$i]['team_leader_id'] : 0;
        $rows[$i]['approval_can_be_team_leader'] = isset($rows[$i]['approval_can_be_team_leader']) ? (int)$rows[$i]['approval_can_be_team_leader'] : 0;
    }
    return $rows;
}}

if (!function_exists('cpms_scheduler_employee_index')) {
function cpms_scheduler_employee_index($employees)
{
    $index = array();
    for ($i = 0; $i < count($employees); $i++) {
        $id = isset($employees[$i]['id']) ? (int)$employees[$i]['id'] : 0;
        if ($id > 0) $index[$id] = $employees[$i];
    }
    return $index;
}}

if (!function_exists('cpms_scheduler_current_employee')) {
function cpms_scheduler_current_employee($employees)
{
    $current = array(
        'id' => 0,
        'name' => (string)Auth::userName(),
        'email' => (string)Auth::userEmail(),
        'department' => cpms_tasks_normalize_department((string)Auth::userDepartment()),
        'position' => (string)Auth::userPosition(),
        'role' => (string)Auth::userRole(),
        'is_team_leader' => 0,
        'team_leader_id' => 0,
        'approval_can_be_team_leader' => 0,
    );
    $user = Auth::user();
    if (is_array($user) && isset($user['id'])) $current['id'] = (int)$user['id'];
    $email = strtolower(trim($current['email']));

    for ($i = 0; $i < count($employees); $i++) {
        $employeeId = isset($employees[$i]['id']) ? (int)$employees[$i]['id'] : 0;
        $employeeEmail = isset($employees[$i]['email']) ? strtolower(trim((string)$employees[$i]['email'])) : '';
        if (($current['id'] > 0 && $employeeId === (int)$current['id']) || ($email !== '' && $employeeEmail === $email)) {
            return $employees[$i];
        }
    }
    return $current;
}}

if (!function_exists('cpms_scheduler_can_view_all')) {
function cpms_scheduler_can_view_all()
{
    return ((string)Auth::userRole() === 'executive');
}}

if (!function_exists('cpms_scheduler_employee_department')) {
function cpms_scheduler_employee_department($employee)
{
    return cpms_tasks_normalize_department(isset($employee['department']) ? $employee['department'] : '');
}}

if (!function_exists('cpms_scheduler_employee_is_team_leader')) {
function cpms_scheduler_employee_is_team_leader($employee)
{
    return (!empty($employee['is_team_leader']) || !empty($employee['approval_can_be_team_leader']));
}}

if (!function_exists('cpms_scheduler_employee_role')) {
function cpms_scheduler_employee_role($employee)
{
    return isset($employee['role']) ? trim((string)$employee['role']) : '';
}}

if (!function_exists('cpms_scheduler_employee_clean_name')) {
function cpms_scheduler_employee_clean_name($employee)
{
    $name = is_array($employee) && isset($employee['name']) ? trim((string)$employee['name']) : '';
    return preg_replace('/\s+/', '', $name);
}}

if (!function_exists('cpms_scheduler_is_public_affairs_team_override')) {
function cpms_scheduler_is_public_affairs_team_override($employee)
{
    $name = cpms_scheduler_employee_clean_name($employee);
    return ($name === '홍승찬' || $name === '고형준');
}}

if (!function_exists('cpms_scheduler_public_affairs_anchor_id')) {
function cpms_scheduler_public_affairs_anchor_id($employees)
{
    for ($i = 0; $i < count($employees); $i++) {
        $name = cpms_scheduler_employee_clean_name($employees[$i]);
        if ($name === '김영기') {
            return isset($employees[$i]['id']) ? (int)$employees[$i]['id'] : 0;
        }
    }
    return 0;
}}

if (!function_exists('cpms_scheduler_team_anchor_id')) {
function cpms_scheduler_team_anchor_id($employee, $publicAffairsAnchorId)
{
    $currentId = isset($employee['id']) ? (int)$employee['id'] : 0;
    if ($publicAffairsAnchorId > 0 && ($currentId === $publicAffairsAnchorId || cpms_scheduler_is_public_affairs_team_override($employee))) {
        return $publicAffairsAnchorId;
    }
    $isLeader = cpms_scheduler_employee_is_team_leader($employee);
    $leaderId = isset($employee['team_leader_id']) ? (int)$employee['team_leader_id'] : 0;
    return $isLeader ? $currentId : ($leaderId > 0 ? $leaderId : 0);
}}

if (!function_exists('cpms_scheduler_employee_team_leader_id')) {
function cpms_scheduler_employee_team_leader_id($employee, $publicAffairsAnchorId)
{
    if ($publicAffairsAnchorId > 0 && cpms_scheduler_is_public_affairs_team_override($employee)) {
        return $publicAffairsAnchorId;
    }
    return isset($employee['team_leader_id']) ? (int)$employee['team_leader_id'] : 0;
}}

if (!function_exists('cpms_scheduler_department_group')) {
function cpms_scheduler_department_group($employee)
{
    if (cpms_scheduler_is_public_affairs_team_override($employee)) return '공무';
    $dept = cpms_scheduler_employee_department($employee);
    if ($dept === '개발') return '공무';
    if ($dept === '기타' || cpms_scheduler_employee_role($employee) === 'executive') return '임원';
    return $dept;
}}

if (!function_exists('cpms_scheduler_department_filter_label')) {
function cpms_scheduler_department_filter_label($department)
{
    $department = cpms_tasks_normalize_department($department);
    if ($department === '개발') return '공무';
    if ($department === '기타') return '임원';
    return $department;
}}

if (!function_exists('cpms_scheduler_hide_in_executive_group')) {
function cpms_scheduler_hide_in_executive_group($employee)
{
    $name = isset($employee['name']) ? preg_replace('/\s+/', '', trim((string)$employee['name'])) : '';
    $position = isset($employee['position']) ? preg_replace('/\s+/', '', trim((string)$employee['position'])) : '';
    if ($name === '대표' || $position === '대표') return true;
    if ($name === '노준형' || $name === '이호상') return true;
    return false;
}}

if (!function_exists('cpms_scheduler_add_visible_employee')) {
function cpms_scheduler_add_visible_employee(&$visible, &$seen, $employee)
{
    $id = isset($employee['id']) ? (int)$employee['id'] : 0;
    if ($id <= 0 || isset($seen[$id])) return;
    $seen[$id] = true;
    $visible[] = $employee;
}}

if (!function_exists('cpms_scheduler_visible_employees')) {
function cpms_scheduler_visible_employees($employees, $currentEmployee, $canViewAll)
{
    if ($canViewAll) return $employees;

    $visible = array();
    $seen = array();
    $currentId = isset($currentEmployee['id']) ? (int)$currentEmployee['id'] : 0;
    if ($currentId <= 0) return $visible;

    $publicAffairsAnchorId = cpms_scheduler_public_affairs_anchor_id($employees);
    $teamAnchorId = cpms_scheduler_team_anchor_id($currentEmployee, $publicAffairsAnchorId);

    for ($i = 0; $i < count($employees); $i++) {
        $employeeId = isset($employees[$i]['id']) ? (int)$employees[$i]['id'] : 0;
        $employeeLeaderId = cpms_scheduler_employee_team_leader_id($employees[$i], $publicAffairsAnchorId);
        if ($employeeId <= 0) continue;

        if ($teamAnchorId > 0) {
            if ($employeeId === $teamAnchorId || $employeeLeaderId === $teamAnchorId) {
                cpms_scheduler_add_visible_employee($visible, $seen, $employees[$i]);
            }
        } else if ($employeeId === $currentId) {
            cpms_scheduler_add_visible_employee($visible, $seen, $employees[$i]);
        }
    }

    if (count($visible) === 0) {
        for ($j = 0; $j < count($employees); $j++) {
            if (isset($employees[$j]['id']) && (int)$employees[$j]['id'] === $currentId) {
                cpms_scheduler_add_visible_employee($visible, $seen, $employees[$j]);
                break;
            }
        }
    }
    return $visible;
}}

if (!function_exists('cpms_scheduler_department_options')) {
function cpms_scheduler_department_options($employees, $canViewAll)
{
    $seen = array();
    $options = array();
    $seed = function_exists('cpms_tasks_department_options') ? cpms_tasks_department_options() : array();
    for ($i = 0; $i < count($seed); $i++) {
        $dept = cpms_scheduler_department_filter_label($seed[$i]);
        if ($dept !== '' && !isset($seen[$dept])) {
            $seen[$dept] = true;
            $options[] = $dept;
        }
    }
    for ($j = 0; $j < count($employees); $j++) {
        $dept = cpms_scheduler_department_group($employees[$j]);
        if ($dept !== '' && !isset($seen[$dept])) {
            $seen[$dept] = true;
            $options[] = $dept;
        }
    }
    if ($canViewAll) array_unshift($options, '전체');
    return $options;
}}

if (!function_exists('cpms_scheduler_candidate_employees')) {
function cpms_scheduler_candidate_employees($visibleEmployees, $selectedDepartment, $canViewAll)
{
    $rows = array();
    $selectedDepartment = cpms_scheduler_department_filter_label($selectedDepartment);
    for ($i = 0; $i < count($visibleEmployees); $i++) {
        $dept = cpms_scheduler_department_group($visibleEmployees[$i]);
        if ($canViewAll && $selectedDepartment === '임원' && cpms_scheduler_hide_in_executive_group($visibleEmployees[$i])) continue;
        if ($canViewAll && $selectedDepartment !== '' && $selectedDepartment !== '전체' && $dept !== $selectedDepartment) continue;
        $rows[] = $visibleEmployees[$i];
    }
    return $rows;
}}

if (!function_exists('cpms_scheduler_requested_employee_ids')) {
function cpms_scheduler_requested_employee_ids()
{
    $ids = array();
    $raw = isset($_GET['employee_ids']) ? $_GET['employee_ids'] : array();
    if (!is_array($raw)) $raw = array($raw);
    for ($i = 0; $i < count($raw); $i++) {
        $id = (int)$raw[$i];
        if ($id > 0 && !in_array($id, $ids, true)) $ids[] = $id;
    }
    return $ids;
}}

if (!function_exists('cpms_scheduler_selected_employees')) {
function cpms_scheduler_selected_employees($candidateEmployees, $personMode, $requestedIds)
{
    $rows = array();
    $allowed = array();
    for ($i = 0; $i < count($candidateEmployees); $i++) {
        $id = isset($candidateEmployees[$i]['id']) ? (int)$candidateEmployees[$i]['id'] : 0;
        if ($id > 0) $allowed[$id] = $candidateEmployees[$i];
    }
    if ($personMode === 'employees') {
        if (count($requestedIds) === 0) return $rows;
        for ($j = 0; $j < count($requestedIds); $j++) {
            $id = (int)$requestedIds[$j];
            if (isset($allowed[$id])) $rows[] = $allowed[$id];
        }
        return $rows;
    }
    return $candidateEmployees;
}}

if (!function_exists('cpms_scheduler_employee_ids')) {
function cpms_scheduler_employee_ids($employees)
{
    $ids = array();
    for ($i = 0; $i < count($employees); $i++) {
        $id = isset($employees[$i]['id']) ? (int)$employees[$i]['id'] : 0;
        if ($id > 0) $ids[] = $id;
    }
    return $ids;
}}

if (!function_exists('cpms_scheduler_fetch_tasks')) {
function cpms_scheduler_fetch_tasks($pdo, $employeeIds, $startDate, $endDate, $viewMode, $employeeIndex)
{
    $rows = array();
    if (!$pdo || count($employeeIds) === 0 || !cpms_tasks_table_exists($pdo, 'cpms_tasks')) return $rows;

    $startDate = cpms_scheduler_valid_date($startDate);
    $endDate = cpms_scheduler_valid_date($endDate);
    if ($startDate === '' || $endDate === '') return $rows;

    $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
    $params = array();
    for ($i = 0; $i < count($employeeIds); $i++) $params[] = (int)$employeeIds[$i];
    $hasCompletedAt = cpms_tasks_column_exists($pdo, 'cpms_tasks', 'completed_at');

    $sql = "SELECT * FROM cpms_tasks
            WHERE assignee_employee_id IN (" . $placeholders . ")
              AND due_date IS NOT NULL
              AND due_date <> '0000-00-00'
              AND (status IS NULL OR status <> 'cancelled')";

    if ($hasCompletedAt) {
        $sql .= " AND (
                    (due_date >= ? AND due_date <= ?)
                    OR (
                        status = 'done'
                        AND completed_at IS NOT NULL
                        AND completed_at <> ''
                        AND completed_at <> '0000-00-00 00:00:00'
                        AND DATE(completed_at) >= ?
                        AND DATE(completed_at) <= ?
                    )
                  )";
        $params[] = $startDate;
        $params[] = $endDate;
        $params[] = $startDate;
        $params[] = $endDate;
    } else {
        $sql .= " AND due_date >= ? AND due_date <= ?";
        $params[] = $startDate;
        $params[] = $endDate;
    }

    $sql .= " ORDER BY due_date ASC, due_time ASC, is_urgent DESC, id DESC";

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $tasks = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($tasks)) $tasks = array();
        for ($j = 0; $j < count($tasks); $j++) {
            $row = $tasks[$j];
            $assigneeId = isset($row['assignee_employee_id']) ? (int)$row['assignee_employee_id'] : 0;
            if (trim((string)(isset($row['assignee_name']) ? $row['assignee_name'] : '')) === '' && isset($employeeIndex[$assigneeId])) {
                $row['assignee_name'] = isset($employeeIndex[$assigneeId]['name']) ? (string)$employeeIndex[$assigneeId]['name'] : '';
            }
            if (trim((string)(isset($row['department']) ? $row['department'] : '')) === '' && isset($employeeIndex[$assigneeId])) {
                $row['department'] = isset($employeeIndex[$assigneeId]['department']) ? (string)$employeeIndex[$assigneeId]['department'] : '';
            }
            $dueDate = cpms_scheduler_valid_date(isset($row['due_date']) ? $row['due_date'] : '');
            if ($dueDate === '') continue;
            $createdAt = isset($row['created_at']) ? trim((string)$row['created_at']) : '';
            $requestDate = cpms_scheduler_valid_date(substr($createdAt, 0, 10));
            if ($requestDate === '') $requestDate = $dueDate;
            if (strcmp($requestDate, $dueDate) > 0) $requestDate = $dueDate;
            $row['request_date'] = $requestDate;
            $row['due_date'] = $dueDate;
            $schedulerDate = cpms_scheduler_effective_date($row);
            if ($schedulerDate === '' || strcmp($schedulerDate, $startDate) < 0 || strcmp($schedulerDate, $endDate) > 0) continue;
            $row['scheduler_date'] = $schedulerDate;
            $row['scheduler_date_kind'] = cpms_scheduler_effective_kind($row);
            $row['department'] = cpms_tasks_normalize_department(isset($row['department']) ? $row['department'] : '');
            $row['display_status'] = cpms_tasks_display_status($row);
            $row['is_delayed'] = cpms_tasks_is_delayed($row) ? 1 : 0;
            $rows[] = $row;
        }
        usort($rows, 'cpms_scheduler_task_sort');
    } catch (Exception $e) {
        $rows = array();
    }
    return $rows;
}}

if (!function_exists('cpms_scheduler_task_sort')) {
function cpms_scheduler_task_sort($a, $b)
{
    $aDate = isset($a['scheduler_date']) ? cpms_scheduler_valid_date($a['scheduler_date']) : cpms_scheduler_effective_date($a);
    $bDate = isset($b['scheduler_date']) ? cpms_scheduler_valid_date($b['scheduler_date']) : cpms_scheduler_effective_date($b);
    if ($aDate !== $bDate) return strcmp($aDate, $bDate);
    $aTime = isset($a['due_time']) ? (string)$a['due_time'] : '';
    $bTime = isset($b['due_time']) ? (string)$b['due_time'] : '';
    if ($aTime !== $bTime) return strcmp($aTime, $bTime);
    $aUrgent = isset($a['is_urgent']) ? (int)$a['is_urgent'] : 0;
    $bUrgent = isset($b['is_urgent']) ? (int)$b['is_urgent'] : 0;
    if ($aUrgent !== $bUrgent) return ($aUrgent > $bUrgent) ? -1 : 1;
    $aId = isset($a['id']) ? (int)$a['id'] : 0;
    $bId = isset($b['id']) ? (int)$b['id'] : 0;
    if ($aId === $bId) return 0;
    return ($aId > $bId) ? -1 : 1;
}}

if (!function_exists('cpms_scheduler_event_sort')) {
function cpms_scheduler_event_sort($a, $b)
{
    $aDelayed = isset($a['is_delayed']) ? (int)$a['is_delayed'] : 0;
    $bDelayed = isset($b['is_delayed']) ? (int)$b['is_delayed'] : 0;
    if ($aDelayed !== $bDelayed) return ($aDelayed > $bDelayed) ? -1 : 1;
    $aUrgent = isset($a['is_urgent']) ? (int)$a['is_urgent'] : 0;
    $bUrgent = isset($b['is_urgent']) ? (int)$b['is_urgent'] : 0;
    if ($aUrgent !== $bUrgent) return ($aUrgent > $bUrgent) ? -1 : 1;
    $aTime = isset($a['due_time']) ? (string)$a['due_time'] : '';
    $bTime = isset($b['due_time']) ? (string)$b['due_time'] : '';
    if ($aTime !== $bTime) return strcmp($aTime, $bTime);
    $aTitle = isset($a['title']) ? (string)$a['title'] : '';
    $bTitle = isset($b['title']) ? (string)$b['title'] : '';
    return strcmp($aTitle, $bTitle);
}}

if (!function_exists('cpms_scheduler_add_event')) {
function cpms_scheduler_add_event(&$eventsByDate, $date, $task, $kind)
{
    $date = cpms_scheduler_valid_date($date);
    if ($date === '') return;
    if (!isset($eventsByDate[$date])) $eventsByDate[$date] = array();
    $event = $task;
    $event['scheduler_kind'] = $kind;
    $event['scheduler_date'] = $date;
    $eventsByDate[$date][] = $event;
}}

if (!function_exists('cpms_scheduler_build_events')) {
function cpms_scheduler_build_events($tasks, $viewMode, $startDate, $endDate)
{
    $eventsByDate = array();
    $startDate = cpms_scheduler_valid_date($startDate);
    $endDate = cpms_scheduler_valid_date($endDate);
    for ($i = 0; $i < count($tasks); $i++) {
        $task = $tasks[$i];
        $eventDate = isset($task['scheduler_date']) ? cpms_scheduler_valid_date($task['scheduler_date']) : cpms_scheduler_effective_date($task);
        if ($eventDate === '') continue;
        if (strcmp($eventDate, $startDate) >= 0 && strcmp($eventDate, $endDate) <= 0) {
            $kind = isset($task['scheduler_date_kind']) ? (string)$task['scheduler_date_kind'] : cpms_scheduler_effective_kind($task);
            cpms_scheduler_add_event($eventsByDate, $eventDate, $task, $kind);
        }
    }

    foreach ($eventsByDate as $date => $events) {
        usort($events, 'cpms_scheduler_event_sort');
        $eventsByDate[$date] = $events;
    }
    return $eventsByDate;
}}

if (!function_exists('cpms_scheduler_metrics')) {
function cpms_scheduler_metrics($tasks)
{
    $metrics = array('total' => 0, 'delayed' => 0, 'urgent' => 0, 'done' => 0, 'progress' => 0);
    for ($i = 0; $i < count($tasks); $i++) {
        $metrics['total']++;
        $isDelayed = !empty($tasks[$i]['is_delayed']);
        if ($isDelayed) $metrics['delayed']++;
        if (isset($tasks[$i]['is_urgent']) && (int)$tasks[$i]['is_urgent'] === 1) $metrics['urgent']++;
        $status = isset($tasks[$i]['status']) ? (string)$tasks[$i]['status'] : '';
        if ($status === 'done') $metrics['done']++;
        else if (!$isDelayed) $metrics['progress']++;
    }
    return $metrics;
}}

if (!function_exists('cpms_scheduler_filter_attrs')) {
function cpms_scheduler_filter_attrs($task)
{
    $status = isset($task['status']) ? (string)$task['status'] : '';
    $isDelayed = !empty($task['is_delayed']);
    $isDone = ($status === 'done');
    $isUrgent = (isset($task['is_urgent']) && (int)$task['is_urgent'] === 1);
    $isProgress = (!$isDone && !$isDelayed);
    return ' data-scheduler-item="1"'
        . ' data-scheduler-delayed="' . ($isDelayed ? '1' : '0') . '"'
        . ' data-scheduler-urgent="' . ($isUrgent ? '1' : '0') . '"'
        . ' data-scheduler-progress="' . ($isProgress ? '1' : '0') . '"'
        . ' data-scheduler-done="' . ($isDone ? '1' : '0') . '"';
}}

if (!function_exists('cpms_scheduler_employee_badge_text')) {
function cpms_scheduler_employee_badge_text($employee)
{
    $name = isset($employee['name']) ? trim((string)$employee['name']) : '';
    $position = isset($employee['position']) ? trim((string)$employee['position']) : '';
    if ($name === '') $name = '-';
    return $position !== '' ? $name . ' / ' . $position : $name;
}}

if (!function_exists('cpms_scheduler_kind_label')) {
function cpms_scheduler_kind_label($kind)
{
    if ($kind === 'request') return '요청';
    if ($kind === 'progress') return '진행';
    if ($kind === 'single') return '당일';
    if ($kind === 'completed') return '완료일';
    return '마감';
}}

if (!function_exists('cpms_scheduler_url')) {
function cpms_scheduler_url($overrides)
{
    $query = array();
    $query['r'] = 'scheduler';
    $keys = array('view', 'month', 'week', 'period_month');
    if (cpms_scheduler_can_view_all()) {
        $keys[] = 'department';
        $keys[] = 'person_mode';
    }
    for ($i = 0; $i < count($keys); $i++) {
        $key = $keys[$i];
        if (isset($_GET[$key])) $query[$key] = $_GET[$key];
    }
    if (cpms_scheduler_can_view_all() && isset($_GET['employee_ids'])) $query['employee_ids'] = $_GET['employee_ids'];
    foreach ($overrides as $key => $value) {
        if ($value === null) {
            if (isset($query[$key])) unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }
    return '?' . http_build_query($query, '', '&');
}}

$pdo = Db::pdo();
$setupResults = array();
cpms_tasks_ensure_schema($pdo, $setupResults);

$viewMode = isset($_GET['view']) ? trim((string)$_GET['view']) : 'month';
if ($viewMode !== 'month' && $viewMode !== 'week') $viewMode = 'month';

$monthBounds = cpms_scheduler_month_bounds(isset($_GET['month']) ? (string)$_GET['month'] : date('Y-m'));
$weekBounds = cpms_scheduler_week_bounds(isset($_GET['week']) ? (string)$_GET['week'] : date('Y-m-d'));

if ($viewMode === 'week') {
    $rangeStart = $weekBounds['week_start'];
    $rangeEnd = $weekBounds['week_end'];
    $calendarStart = $weekBounds['week_start'];
    $calendarEnd = $weekBounds['week_end'];
    $calendarLabel = $weekBounds['label'];
    $prevDate = date('Y-m-d', strtotime($weekBounds['week_start'] . ' -7 days'));
    $nextDate = date('Y-m-d', strtotime($weekBounds['week_start'] . ' +7 days'));
    $prevUrl = cpms_scheduler_url(array('view' => 'week', 'week' => $prevDate));
    $nextUrl = cpms_scheduler_url(array('view' => 'week', 'week' => $nextDate));
} else {
    $rangeStart = $monthBounds['month_start'];
    $rangeEnd = $monthBounds['month_end'];
    $calendarStart = $monthBounds['grid_start'];
    $calendarEnd = $monthBounds['grid_end'];
    $calendarLabel = $monthBounds['label'];
    $prevMonth = date('Y-m', strtotime($monthBounds['month_start'] . ' -1 month'));
    $nextMonth = date('Y-m', strtotime($monthBounds['month_start'] . ' +1 month'));
    $prevUrl = cpms_scheduler_url(array('view' => 'month', 'month' => $prevMonth));
    $nextUrl = cpms_scheduler_url(array('view' => 'month', 'month' => $nextMonth));
}

$employees = cpms_scheduler_fetch_active_employees($pdo);
$employeeIndex = cpms_scheduler_employee_index($employees);
$currentEmployee = cpms_scheduler_current_employee($employees);
$canViewAll = cpms_scheduler_can_view_all();
$visibleEmployees = cpms_scheduler_visible_employees($employees, $currentEmployee, $canViewAll);
$departmentOptions = cpms_scheduler_department_options($visibleEmployees, $canViewAll);

$selectedDepartment = $canViewAll ? (isset($_GET['department']) ? trim((string)$_GET['department']) : '전체') : (isset($currentEmployee['department']) ? (string)$currentEmployee['department'] : '');
if ($selectedDepartment === '') $selectedDepartment = $canViewAll ? '전체' : (isset($currentEmployee['department']) ? (string)$currentEmployee['department'] : '');
$selectedDepartment = cpms_scheduler_department_filter_label($selectedDepartment);
if ($canViewAll && $selectedDepartment === '') $selectedDepartment = '전체';

$personMode = 'group';
$requestedEmployeeIds = array();
if ($canViewAll) {
    $personMode = isset($_GET['person_mode']) ? trim((string)$_GET['person_mode']) : 'group';
    if ($personMode !== 'group' && $personMode !== 'employees') $personMode = 'group';
    $requestedEmployeeIds = cpms_scheduler_requested_employee_ids();
    if (count($requestedEmployeeIds) > 0) $personMode = 'employees';
}
$candidateEmployees = cpms_scheduler_candidate_employees($visibleEmployees, $selectedDepartment, $canViewAll);
$selectedEmployees = cpms_scheduler_selected_employees($candidateEmployees, $personMode, $requestedEmployeeIds);
$selectedEmployeeIds = cpms_scheduler_employee_ids($selectedEmployees);
$monthInput = isset($monthBounds['month']) ? $monthBounds['month'] : date('Y-m');
$weekInput = isset($weekBounds['date']) ? $weekBounds['date'] : date('Y-m-d');

$calendarTasks = cpms_scheduler_fetch_tasks($pdo, $selectedEmployeeIds, $rangeStart, $rangeEnd, $viewMode, $employeeIndex);
$periodMonthDefault = $viewMode === 'week' ? substr($weekInput, 0, 7) : $monthInput;
$periodMonthBounds = cpms_scheduler_month_bounds(isset($_GET['period_month']) ? (string)$_GET['period_month'] : $periodMonthDefault);
$periodTasks = cpms_scheduler_fetch_tasks($pdo, $selectedEmployeeIds, $periodMonthBounds['month_start'], $periodMonthBounds['month_end'], 'month', $employeeIndex);
$eventsByDate = cpms_scheduler_build_events($calendarTasks, $viewMode, $rangeStart, $rangeEnd);
$metrics = cpms_scheduler_metrics($calendarTasks);
$calendarDays = cpms_scheduler_days_between($calendarStart, $calendarEnd);
$weekdayLabels = array('일', '월', '화', '수', '목', '금', '토');
$today = date('Y-m-d');
$selectedIdMap = array();
if ($personMode === 'employees') {
    for ($i = 0; $i < count($requestedEmployeeIds); $i++) $selectedIdMap[(int)$requestedEmployeeIds[$i]] = true;
}
$groupLabel = $canViewAll ? '부서 전체' : '팀 전체';
?>

<style>
    .cpms-scheduler-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch}
    .cpms-scheduler-calendar{min-width:980px;display:grid;grid-template-columns:repeat(7,minmax(0,1fr));border:1px solid #e5e7eb;border-radius:16px;overflow:hidden;background:#fff}
    .cpms-scheduler-weekday{height:42px;display:flex;align-items:center;justify-content:center;background:#f8fafc;border-right:1px solid #e5e7eb;border-bottom:1px solid #e5e7eb;font-size:12px;font-weight:900;color:#475569}
    .cpms-scheduler-weekday.is-sunday{color:#dc2626;background:#fff5f5}
    .cpms-scheduler-weekday:nth-child(7n){border-right:0}
    .cpms-scheduler-day{min-height:170px;border-right:1px solid #e5e7eb;border-bottom:1px solid #e5e7eb;background:#fff;padding:10px;min-width:0}
    .cpms-scheduler-calendar.cpms-scheduler-week .cpms-scheduler-day{min-height:520px}
    .cpms-scheduler-day:nth-child(7n){border-right:0}
    .cpms-scheduler-day.is-muted{background:#f8fafc;color:#94a3b8}
    .cpms-scheduler-day.is-sunday .cpms-scheduler-date{color:#dc2626}
    .cpms-scheduler-day.is-today{box-shadow:inset 0 0 0 2px #2563eb}
    .cpms-scheduler-day-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px}
    .cpms-scheduler-date{font-size:13px;font-weight:900;color:#0f172a}
    .cpms-scheduler-count{min-width:24px;height:22px;display:inline-flex;align-items:center;justify-content:center;border-radius:999px;background:#eef2ff;color:#3730a3;font-size:11px;font-weight:900}
    .cpms-scheduler-events{display:flex;flex-direction:column;gap:6px;max-height:430px;overflow-y:auto;padding-right:2px}
    .cpms-scheduler-calendar:not(.cpms-scheduler-week) .cpms-scheduler-events{max-height:120px}
    .cpms-scheduler-event{display:block;width:100%;text-align:left;cursor:pointer;border:1px solid #e5e7eb;border-left-width:4px;border-left-color:#2563eb;border-radius:8px;background:#fff;padding:7px 8px;text-decoration:none;color:#0f172a;box-shadow:0 1px 2px rgba(15,23,42,.04)}
    .cpms-scheduler-event:hover{border-color:#cbd5e1;background:#f8fafc}
    .cpms-scheduler-event.is-delayed{border-left-color:#dc2626;background:#fff7f7}
    .cpms-scheduler-event.is-done{border-left-color:#059669;background:#f7fffb}
    .cpms-scheduler-event-title{font-size:12px;font-weight:900;line-height:1.35;word-break:keep-all;overflow-wrap:anywhere}
    .cpms-scheduler-event-meta{margin-top:4px;font-size:11px;font-weight:700;color:#64748b;line-height:1.35;word-break:keep-all;overflow-wrap:anywhere}
    .cpms-scheduler-pill{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:2px 6px;font-size:10px;font-weight:900;line-height:1.2;white-space:nowrap}
    .cpms-scheduler-pill.kind{background:#eff6ff;color:#1d4ed8}
    .cpms-scheduler-pill.delayed{background:#fee2e2;color:#b91c1c}
    .cpms-scheduler-pill.urgent{background:#fff1f2;color:#be123c}
    .cpms-scheduler-pill.done{background:#d1fae5;color:#047857}
    .cpms-scheduler-filter-grid{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:12px;align-items:end}
    .cpms-scheduler-filter-button{transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease}
    .cpms-scheduler-filter-button.is-active{transform:translateY(-2px);box-shadow:0 12px 28px rgba(15,23,42,.14)}
    .cpms-scheduler-event{max-height:160px;transition:opacity .2s ease,transform .2s ease,max-height .22s ease,margin .22s ease,padding .22s ease,border-width .22s ease}
    .cpms-scheduler-event.is-filter-hidden{opacity:0;transform:translateY(6px);max-height:0;margin:0;padding-top:0;padding-bottom:0;border-top-width:0;border-bottom-width:0;overflow:hidden;pointer-events:none}
    .cpms-scheduler-row-filter-hidden{opacity:0;transform:translateY(6px);transition:opacity .18s ease,transform .18s ease}
    .cpms-scheduler-row-display-none{display:none}
    .cpms-scheduler-filter-empty{display:none}
    .cpms-scheduler-filter-empty.is-visible{display:block}
    @media (max-width: 1023px){.cpms-scheduler-filter-grid{grid-template-columns:1fr}.cpms-scheduler-calendar{min-width:900px}}
</style>

<div class="space-y-6 cpms-scheduler-page">
    <div class="bg-white/90 rounded-3xl border border-gray-100 shadow-sm p-6">
        <div class="flex flex-col xl:flex-row xl:items-start xl:justify-between gap-4">
            <div>
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-blue-50 text-blue-700 border border-blue-100 text-xs font-extrabold">
                    <i data-lucide="calendar-days" class="w-4 h-4"></i>
                    스케줄러
                </div>
                <h2 class="mt-3 text-3xl font-extrabold text-gray-900">업무 일정</h2>
                <div class="mt-2 text-sm font-bold text-gray-500">
                    <?php echo h($canViewAll ? '임원 조회' : '팀 조회'); ?> · <?php echo h($calendarLabel); ?>
                </div>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-5 gap-3 min-w-0" data-scheduler-status-filters>
                <button type="button" class="cpms-scheduler-filter-button is-active rounded-2xl border border-gray-200 bg-slate-50 p-4 text-left" data-scheduler-filter="all">
                    <div class="text-xs font-extrabold text-gray-500">전체</div>
                    <div class="mt-1 text-2xl font-black text-gray-900"><?php echo (int)$metrics['total']; ?></div>
                </button>
                <button type="button" class="cpms-scheduler-filter-button rounded-2xl border border-red-100 bg-red-50 p-4 text-left" data-scheduler-filter="delayed">
                    <div class="text-xs font-extrabold text-red-500">지연</div>
                    <div class="mt-1 text-2xl font-black text-red-700"><?php echo (int)$metrics['delayed']; ?></div>
                </button>
                <button type="button" class="cpms-scheduler-filter-button rounded-2xl border border-rose-100 bg-rose-50 p-4 text-left" data-scheduler-filter="urgent">
                    <div class="text-xs font-extrabold text-rose-500">긴급</div>
                    <div class="mt-1 text-2xl font-black text-rose-700"><?php echo (int)$metrics['urgent']; ?></div>
                </button>
                <button type="button" class="cpms-scheduler-filter-button rounded-2xl border border-blue-100 bg-blue-50 p-4 text-left" data-scheduler-filter="progress">
                    <div class="text-xs font-extrabold text-blue-500">진행</div>
                    <div class="mt-1 text-2xl font-black text-blue-700"><?php echo (int)$metrics['progress']; ?></div>
                </button>
                <button type="button" class="cpms-scheduler-filter-button rounded-2xl border border-emerald-100 bg-emerald-50 p-4 text-left" data-scheduler-filter="done">
                    <div class="text-xs font-extrabold text-emerald-500">완료</div>
                    <div class="mt-1 text-2xl font-black text-emerald-700"><?php echo (int)$metrics['done']; ?></div>
                </button>
            </div>
        </div>

        <form method="get" action="" class="mt-6">
            <input type="hidden" name="r" value="scheduler">
            <input type="hidden" name="view" value="<?php echo h($viewMode); ?>">
            <input type="hidden" name="period_month" value="<?php echo h($periodMonthBounds['month']); ?>">
            <div class="cpms-scheduler-filter-grid">
                <div class="col-span-12 lg:col-span-2">
                    <div class="text-xs font-extrabold text-gray-500 mb-1">보기</div>
                    <div class="grid grid-cols-2 gap-2 rounded-2xl bg-gray-100 p-1">
                        <a href="<?php echo h(cpms_scheduler_url(array('view' => 'month'))); ?>" class="text-center px-3 py-2 rounded-xl text-sm font-extrabold <?php echo $viewMode === 'month' ? 'bg-white text-blue-700 shadow-sm' : 'text-gray-600'; ?>">월별</a>
                        <a href="<?php echo h(cpms_scheduler_url(array('view' => 'week'))); ?>" class="text-center px-3 py-2 rounded-xl text-sm font-extrabold <?php echo $viewMode === 'week' ? 'bg-white text-blue-700 shadow-sm' : 'text-gray-600'; ?>">주차별</a>
                    </div>
                </div>

                <?php if ($viewMode === 'week'): ?>
                    <div class="col-span-12 lg:col-span-2">
                        <label class="block text-xs font-extrabold text-gray-500 mb-1" for="schedulerWeek">주차 기준일</label>
                        <input id="schedulerWeek" type="date" name="week" value="<?php echo h($weekInput); ?>" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white font-bold">
                        <input type="hidden" name="month" value="<?php echo h($monthInput); ?>">
                    </div>
                <?php else: ?>
                    <div class="col-span-12 lg:col-span-2">
                        <label class="block text-xs font-extrabold text-gray-500 mb-1" for="schedulerMonth">월</label>
                        <input id="schedulerMonth" type="month" name="month" value="<?php echo h($monthInput); ?>" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white font-bold">
                        <input type="hidden" name="week" value="<?php echo h($weekInput); ?>">
                    </div>
                <?php endif; ?>

                <?php if ($canViewAll): ?>
                    <div class="col-span-12 lg:col-span-2">
                        <label class="block text-xs font-extrabold text-gray-500 mb-1" for="schedulerDepartment">부서</label>
                        <select id="schedulerDepartment" name="department" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white font-bold">
                            <?php for ($i = 0; $i < count($departmentOptions); $i++): ?>
                                <?php $dept = $departmentOptions[$i]; ?>
                                <option value="<?php echo h($dept); ?>" <?php echo $dept === $selectedDepartment ? 'selected' : ''; ?>><?php echo h($dept); ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                <?php else: ?>
                    <input type="hidden" name="department" value="<?php echo h($selectedDepartment); ?>">
                <?php endif; ?>

                <?php if ($canViewAll): ?>
                    <div class="col-span-12 lg:col-span-2">
                        <label class="block text-xs font-extrabold text-gray-500 mb-1" for="schedulerPersonMode">대상</label>
                        <select id="schedulerPersonMode" name="person_mode" class="w-full px-4 py-3 rounded-2xl border border-gray-200 bg-white font-bold">
                            <option value="group" <?php echo $personMode === 'group' ? 'selected' : ''; ?>><?php echo h($groupLabel); ?></option>
                            <option value="employees" <?php echo $personMode === 'employees' ? 'selected' : ''; ?>>인원 선택</option>
                        </select>
                    </div>

                    <div class="col-span-12 lg:col-span-4">
                        <div class="flex items-center justify-between gap-3 mb-1">
                            <div class="text-xs font-extrabold text-gray-500">인원</div>
                            <div class="text-xs font-bold text-gray-400"><?php echo count($selectedEmployees); ?>명</div>
                        </div>
                        <div class="max-h-36 overflow-y-auto rounded-2xl border border-gray-200 bg-white p-3">
                            <?php if (count($candidateEmployees) === 0): ?>
                                <div class="text-sm font-bold text-gray-400">표시할 인원이 없습니다.</div>
                            <?php else: ?>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                                    <?php for ($i = 0; $i < count($candidateEmployees); $i++): ?>
                                        <?php
                                        $employee = $candidateEmployees[$i];
                                        $employeeId = isset($employee['id']) ? (int)$employee['id'] : 0;
                                        $checked = isset($selectedIdMap[$employeeId]) ? 'checked' : '';
                                        ?>
                                        <label class="flex items-center gap-2 rounded-xl bg-slate-50 px-3 py-2 text-sm font-bold text-gray-700">
                                            <input type="checkbox" name="employee_ids[]" value="<?php echo (int)$employeeId; ?>" <?php echo $checked; ?> class="rounded border-gray-300" data-scheduler-employee-checkbox>
                                            <span class="min-w-0 break-words"><?php echo h(cpms_scheduler_employee_badge_text($employee)); ?></span>
                                        </label>
                                    <?php endfor; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <input type="hidden" name="person_mode" value="group">
                    <div class="col-span-12 lg:col-span-6">
                        <div class="text-xs font-extrabold text-gray-500 mb-1">조회 범위</div>
                        <div class="px-4 py-3 rounded-2xl border border-gray-200 bg-slate-50 font-extrabold text-gray-800">
                            <?php echo h($groupLabel); ?> · <?php echo (int)count($selectedEmployees); ?>명
                        </div>
                    </div>
                <?php endif; ?>

                <div class="col-span-12 flex flex-wrap justify-end gap-2">
                    <a href="?r=scheduler" class="px-4 py-3 rounded-2xl border border-gray-200 bg-white text-gray-700 font-extrabold">오늘</a>
                    <button type="submit" class="inline-flex items-center gap-2 px-5 py-3 rounded-2xl bg-gray-900 text-white font-extrabold">
                        <i data-lucide="search" class="w-4 h-4"></i>
                        조회
                    </button>
                </div>
            </div>
        </form>
    </div>

    <div class="bg-white/90 rounded-3xl border border-gray-100 shadow-sm p-6">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-5">
            <div>
                <h3 class="text-2xl font-extrabold text-gray-900"><?php echo h($calendarLabel); ?></h3>
                <div class="mt-1 text-sm font-bold text-gray-500">
                    마감일 기준
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="<?php echo h($prevUrl); ?>" class="inline-flex items-center justify-center w-11 h-11 rounded-2xl border border-gray-200 bg-white text-gray-700" title="이전">
                    <i data-lucide="chevron-left" class="w-5 h-5"></i>
                </a>
                <a href="<?php echo h($nextUrl); ?>" class="inline-flex items-center justify-center w-11 h-11 rounded-2xl border border-gray-200 bg-white text-gray-700" title="다음">
                    <i data-lucide="chevron-right" class="w-5 h-5"></i>
                </a>
            </div>
        </div>

        <div class="cpms-scheduler-scroll">
            <div class="cpms-scheduler-calendar <?php echo $viewMode === 'week' ? 'cpms-scheduler-week' : 'cpms-scheduler-month'; ?>">
                <?php for ($i = 0; $i < count($weekdayLabels); $i++): ?>
                    <div class="cpms-scheduler-weekday <?php echo $i === 0 ? 'is-sunday' : ''; ?>"><?php echo h($weekdayLabels[$i]); ?></div>
                <?php endfor; ?>

                <?php for ($i = 0; $i < count($calendarDays); $i++): ?>
                    <?php
                    $day = $calendarDays[$i];
                    $events = isset($eventsByDate[$day]) ? $eventsByDate[$day] : array();
                    $isMuted = ($viewMode === 'month' && substr($day, 0, 7) !== $monthBounds['month']);
                    $isToday = ($day === $today);
                    $isSunday = ((int)date('w', strtotime($day)) === 0);
                    ?>
                    <div class="cpms-scheduler-day <?php echo $isMuted ? 'is-muted' : ''; ?> <?php echo $isToday ? 'is-today' : ''; ?> <?php echo $isSunday ? 'is-sunday' : ''; ?>">
                        <div class="cpms-scheduler-day-head">
                            <div class="cpms-scheduler-date"><?php echo h(date('n/j', strtotime($day))); ?></div>
                            <?php if (count($events) > 0): ?><div class="cpms-scheduler-count" data-scheduler-day-count><?php echo count($events); ?></div><?php endif; ?>
                        </div>
                        <div class="cpms-scheduler-events">
                            <?php for ($j = 0; $j < count($events); $j++): ?>
                                <?php
                                $event = $events[$j];
                                $status = isset($event['status']) ? (string)$event['status'] : '';
                                $isDelayed = !empty($event['is_delayed']);
                                $isDone = ($status === 'done');
                                $kind = isset($event['scheduler_kind']) ? (string)$event['scheduler_kind'] : 'due';
                                $dueTime = isset($event['due_time']) && trim((string)$event['due_time']) !== '' ? substr((string)$event['due_time'], 0, 5) : '';
                                $requestDate = isset($event['request_date']) ? (string)$event['request_date'] : '';
                                $dueDate = isset($event['due_date']) ? (string)$event['due_date'] : '';
                                $isCompletedDateEvent = ($kind === 'completed');
                                ?>
                                <button type="button" class="cpms-scheduler-event <?php echo $isDelayed ? 'is-delayed' : ''; ?> <?php echo $isDone ? 'is-done' : ''; ?>" data-scheduler-detail-open="1" data-task-id="<?php echo isset($event['id']) ? (int)$event['id'] : 0; ?>"<?php echo cpms_scheduler_filter_attrs($event); ?>>
                                    <div class="flex flex-wrap items-center gap-1 mb-1">
                                        <span class="cpms-scheduler-pill kind"><?php echo h(cpms_scheduler_kind_label($kind)); ?></span>
                                        <?php if ($isDelayed): ?><span class="cpms-scheduler-pill delayed">지연</span><?php endif; ?>
                                        <?php if (isset($event['is_urgent']) && (int)$event['is_urgent'] === 1): ?><span class="cpms-scheduler-pill urgent">긴급</span><?php endif; ?>
                                        <?php if ($isDone): ?><span class="cpms-scheduler-pill done">완료</span><?php endif; ?>
                                    </div>
                                    <div class="cpms-scheduler-event-title"><?php echo h(isset($event['title']) ? $event['title'] : ''); ?></div>
                                    <div class="cpms-scheduler-event-meta">
                                        <?php echo h(isset($event['assignee_name']) && trim((string)$event['assignee_name']) !== '' ? $event['assignee_name'] : '-'); ?>
                                        <?php if ($viewMode === 'week'): ?>
                                            · 마감 <?php echo h(cpms_scheduler_date_label($dueDate)); ?><?php echo $dueTime !== '' ? ' ' . h($dueTime) : ''; ?>
                                        <?php elseif ($isCompletedDateEvent): ?>
                                            · 마감 <?php echo h(cpms_scheduler_date_label($dueDate)); ?><?php echo $dueTime !== '' ? ' ' . h($dueTime) : ''; ?>
                                        <?php elseif ($dueTime !== ''): ?>
                                            · <?php echo h($dueTime); ?>
                                        <?php endif; ?>
                                    </div>
                                </button>
                            <?php endfor; ?>
                        </div>
                    </div>
                <?php endfor; ?>
            </div>
        </div>
    </div>

    <div class="bg-white/90 rounded-3xl border border-gray-100 shadow-sm p-6">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3 mb-4">
            <div>
                <h3 class="text-xl font-extrabold text-gray-900">기간 업무</h3>
                <div class="mt-1 text-sm font-bold text-gray-500"><?php echo h($periodMonthBounds['label']); ?> · <?php echo (int)count($periodTasks); ?>건</div>
            </div>
            <form method="get" action="" class="flex flex-wrap items-end gap-2">
                <input type="hidden" name="r" value="scheduler">
                <input type="hidden" name="view" value="<?php echo h($viewMode); ?>">
                <input type="hidden" name="month" value="<?php echo h($monthInput); ?>">
                <input type="hidden" name="week" value="<?php echo h($weekInput); ?>">
                <input type="hidden" name="department" value="<?php echo h($selectedDepartment); ?>">
                <input type="hidden" name="person_mode" value="<?php echo h($personMode); ?>">
                <?php if ($personMode === 'employees'): ?>
                    <?php for ($i = 0; $i < count($requestedEmployeeIds); $i++): ?>
                        <input type="hidden" name="employee_ids[]" value="<?php echo (int)$requestedEmployeeIds[$i]; ?>">
                    <?php endfor; ?>
                <?php endif; ?>
                <div>
                    <label class="block text-xs font-extrabold text-gray-500 mb-1" for="schedulerPeriodMonth">기간 업무 월</label>
                    <input id="schedulerPeriodMonth" type="month" name="period_month" value="<?php echo h($periodMonthBounds['month']); ?>" class="px-4 py-3 rounded-2xl border border-gray-200 bg-white font-bold">
                </div>
                <button type="submit" class="px-4 py-3 rounded-2xl bg-gray-900 text-white font-extrabold">조회</button>
            </form>
        </div>
        <?php if (count($periodTasks) === 0): ?>
            <div class="rounded-2xl border border-dashed border-gray-300 bg-slate-50 p-6 text-sm font-bold text-gray-500">표시할 업무가 없습니다.</div>
        <?php else: ?>
            <div class="cpms-responsive-table-wrap">
                <table class="cpms-responsive-table text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-gray-500">
                            <th class="py-3 pr-4">담당자</th>
                            <th class="py-3 pr-4 text-left">업무</th>
                            <th class="py-3 pr-4">요청일</th>
                            <th class="py-3 pr-4">마감일</th>
                            <th class="py-3 pr-4">완료일</th>
                            <th class="py-3 pr-4">상태</th>
                            <th class="py-3">상세</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ($i = 0; $i < count($periodTasks); $i++): ?>
                            <?php
                            $task = $periodTasks[$i];
                            $isDelayed = !empty($task['is_delayed']);
                            $statusValue = $isDelayed ? 'delayed' : (isset($task['status']) ? (string)$task['status'] : 'pending');
                            $dueText = isset($task['due_date']) ? (string)$task['due_date'] : '';
                            if (isset($task['due_time']) && trim((string)$task['due_time']) !== '') $dueText .= ' ' . substr((string)$task['due_time'], 0, 5);
                            $completedText = isset($task['completed_at']) && trim((string)$task['completed_at']) !== '' ? substr((string)$task['completed_at'], 0, 16) : '-';
                            ?>
                            <tr class="border-b border-gray-100" data-scheduler-period-row="1"<?php echo cpms_scheduler_filter_attrs($task); ?>>
                                <td class="py-3 pr-4 font-bold text-gray-700"><?php echo h(isset($task['assignee_name']) ? $task['assignee_name'] : '-'); ?></td>
                                <td class="py-3 pr-4 text-left" data-wrap="1">
                                    <div class="font-extrabold text-gray-900"><?php echo h(isset($task['title']) ? $task['title'] : ''); ?></div>
                                    <div class="mt-1 text-xs font-bold text-gray-500"><?php echo h(cpms_tasks_type_label(isset($task['task_type']) ? $task['task_type'] : 'general')); ?></div>
                                </td>
                                <td class="py-3 pr-4 font-bold text-gray-600"><?php echo h(isset($task['request_date']) ? $task['request_date'] : '-'); ?></td>
                                <td class="py-3 pr-4 font-bold text-gray-600"><?php echo h($dueText !== '' ? $dueText : '-'); ?></td>
                                <td class="py-3 pr-4 font-bold text-gray-600"><?php echo h($completedText); ?></td>
                                <td class="py-3 pr-4">
                                    <span class="cpms-chip px-3 py-1 rounded-full border text-xs font-extrabold <?php echo h(cpms_tasks_badge_class('status', $statusValue)); ?>">
                                        <?php echo h($isDelayed ? '지연' : (isset($task['display_status']) ? $task['display_status'] : '-')); ?>
                                    </span>
                                </td>
                                <td class="py-3">
                                    <button type="button" class="inline-flex items-center gap-1 text-blue-600 font-extrabold" data-scheduler-detail-open="1" data-task-id="<?php echo isset($task['id']) ? (int)$task['id'] : 0; ?>">
                                        상세
                                        <i data-lucide="arrow-right" class="w-4 h-4"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
            <div class="cpms-scheduler-filter-empty mt-4 rounded-2xl border border-dashed border-gray-300 bg-slate-50 p-5 text-sm font-bold text-gray-500" data-scheduler-filter-empty>선택한 상태에 해당하는 업무가 없습니다.</div>
        <?php endif; ?>
    </div>
</div>

<div id="schedulerTaskDetailModal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
    <div class="absolute inset-0 bg-black/40" data-scheduler-detail-close></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
        <div class="w-full max-w-5xl max-h-[88vh] overflow-hidden rounded-3xl bg-white shadow-2xl border border-gray-100">
            <div class="flex items-center justify-between gap-4 px-6 py-5 border-b border-gray-100">
                <div class="text-2xl font-extrabold text-gray-900">업무 상세</div>
                <button type="button" class="px-3 py-2 rounded-xl border border-gray-200 text-sm font-bold text-gray-700 hover:bg-gray-50" data-scheduler-detail-close>닫기</button>
            </div>
            <div id="schedulerTaskDetailBody" class="p-6 overflow-y-auto max-h-[74vh]">
                <div class="text-sm text-gray-500">업무 정보를 불러오는 중입니다.</div>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    function hasClass(el, cls) {
        return el && (' ' + el.className + ' ').indexOf(' ' + cls + ' ') >= 0;
    }
    function addClass(el, cls) {
        if (el && !hasClass(el, cls)) el.className = (el.className ? el.className + ' ' : '') + cls;
    }
    function removeClass(el, cls) {
        if (!el) return;
        el.className = (' ' + el.className + ' ').replace(' ' + cls + ' ', ' ').replace(/^\s+|\s+$/g, '');
    }
    var personMode = document.getElementById('schedulerPersonMode');
    var employeeChecks = document.querySelectorAll ? document.querySelectorAll('[data-scheduler-employee-checkbox]') : [];
    for (var i = 0; i < employeeChecks.length; i++) {
        employeeChecks[i].onchange = function() {
            if (this.checked && personMode) personMode.value = 'employees';
        };
    }
    if (personMode) {
        personMode.onchange = function() {
            if (this.value !== 'group') return;
            for (var i = 0; i < employeeChecks.length; i++) {
                employeeChecks[i].checked = false;
            }
        };
    }

    var schedulerFilter = 'all';

    function schedulerItemMatches(item, filter) {
        if (!item) return false;
        if (filter === 'all') return true;
        return item.getAttribute('data-scheduler-' + filter) === '1';
    }

    function setSchedulerRowVisible(row, visible) {
        if (!row) return;
        if (visible) {
            removeClass(row, 'cpms-scheduler-row-display-none');
            setTimeout(function(){ removeClass(row, 'cpms-scheduler-row-filter-hidden'); }, 20);
        } else {
            addClass(row, 'cpms-scheduler-row-filter-hidden');
            setTimeout(function(){
                if (hasClass(row, 'cpms-scheduler-row-filter-hidden')) addClass(row, 'cpms-scheduler-row-display-none');
            }, 190);
        }
    }

    function updateSchedulerDayCounts(filter) {
        var days = document.querySelectorAll ? document.querySelectorAll('.cpms-scheduler-day') : [];
        for (var i = 0; i < days.length; i++) {
            var events = days[i].querySelectorAll ? days[i].querySelectorAll('.cpms-scheduler-event[data-scheduler-item]') : [];
            var visibleCount = 0;
            for (var j = 0; j < events.length; j++) {
                if (schedulerItemMatches(events[j], filter)) visibleCount++;
            }
            var countNode = days[i].querySelector ? days[i].querySelector('[data-scheduler-day-count]') : null;
            if (countNode) {
                countNode.innerHTML = visibleCount;
                countNode.style.display = visibleCount > 0 ? 'inline-flex' : 'none';
            }
        }
    }

    function applySchedulerStatusFilter(filter) {
        schedulerFilter = filter || 'all';
        var buttons = document.querySelectorAll ? document.querySelectorAll('[data-scheduler-filter]') : [];
        for (var i = 0; i < buttons.length; i++) {
            if ((buttons[i].getAttribute('data-scheduler-filter') || 'all') === schedulerFilter) addClass(buttons[i], 'is-active');
            else removeClass(buttons[i], 'is-active');
        }

        var events = document.querySelectorAll ? document.querySelectorAll('.cpms-scheduler-event[data-scheduler-item]') : [];
        for (var j = 0; j < events.length; j++) {
            if (schedulerItemMatches(events[j], schedulerFilter)) removeClass(events[j], 'is-filter-hidden');
            else addClass(events[j], 'is-filter-hidden');
        }
        updateSchedulerDayCounts(schedulerFilter);

        var rows = document.querySelectorAll ? document.querySelectorAll('[data-scheduler-period-row]') : [];
        var visibleRows = 0;
        for (var k = 0; k < rows.length; k++) {
            var rowVisible = schedulerItemMatches(rows[k], schedulerFilter);
            if (rowVisible) visibleRows++;
            setSchedulerRowVisible(rows[k], rowVisible);
        }
        var empty = document.querySelector ? document.querySelector('[data-scheduler-filter-empty]') : null;
        if (empty) {
            if (rows.length > 0 && visibleRows === 0) addClass(empty, 'is-visible');
            else removeClass(empty, 'is-visible');
        }
    }

    var modal = document.getElementById('schedulerTaskDetailModal');
    var detailBody = document.getElementById('schedulerTaskDetailBody');

    function closeModal() {
        addClass(modal, 'hidden');
        if (modal) modal.setAttribute('aria-hidden', 'true');
    }

    function openModal(trigger) {
        if (!modal || !trigger) return;
        var taskId = trigger.getAttribute('data-task-id') || '';
        if (detailBody) detailBody.innerHTML = '<div class="text-sm text-gray-500">업무 정보를 불러오는 중입니다.</div>';
        removeClass(modal, 'hidden');
        modal.setAttribute('aria-hidden', 'false');
        if (taskId === '') {
            if (detailBody) detailBody.innerHTML = '<div class="text-sm text-red-600">업무 정보를 찾을 수 없습니다.</div>';
            return;
        }
        var xhr = new XMLHttpRequest();
        var detailUrl = '?r=tasks/detail&id=' + encodeURIComponent(taskId) + '&modal=1&readonly=1&commentable=1';
        detailUrl += '&return_url=' + encodeURIComponent(window.location.pathname + window.location.search);
        xhr.open('GET', detailUrl, true);
        xhr.onreadystatechange = function() {
            if (xhr.readyState !== 4) return;
            if (!detailBody) return;
            if (xhr.status >= 200 && xhr.status < 300) detailBody.innerHTML = xhr.responseText;
            else detailBody.innerHTML = '<div class="text-sm text-red-600">업무 정보를 불러오지 못했습니다.</div>';
            if (window.lucide) { try { lucide.createIcons(); } catch (err) {} }
        };
        xhr.send(null);
    }

    function encodeFormData(form) {
        var pairs = [];
        var elements = form.elements || [];
        for (var i = 0; i < elements.length; i++) {
            var el = elements[i];
            if (!el.name || el.disabled) continue;
            var type = (el.type || '').toLowerCase();
            if ((type === 'checkbox' || type === 'radio') && !el.checked) continue;
            if (type === 'select-multiple') {
                for (var j = 0; j < el.options.length; j++) {
                    if (el.options[j].selected) pairs[pairs.length] = encodeURIComponent(el.name) + '=' + encodeURIComponent(el.options[j].value);
                }
            } else {
                pairs[pairs.length] = encodeURIComponent(el.name) + '=' + encodeURIComponent(el.value);
            }
        }
        return pairs.join('&');
    }

    function postEncoded(url, body, callback) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', url, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onreadystatechange = function() {
            if (xhr.readyState !== 4) return;
            var response = null;
            try { response = JSON.parse(xhr.responseText); } catch (e) { response = null; }
            callback(xhr.status, response, xhr.responseText);
        };
        xhr.send(body);
    }

    function responseOk(response) {
        return response && (response.ok === 1 || response.ok === true);
    }

    function responseMessage(response, fallback) {
        if (response && response.message) return response.message;
        return fallback;
    }

    document.addEventListener('click', function(e) {
        var target = e.target;
        while (target && target !== document) {
            if (target.getAttribute && target.getAttribute('data-scheduler-filter')) {
                e.preventDefault();
                applySchedulerStatusFilter(target.getAttribute('data-scheduler-filter') || 'all');
                return;
            }
            if (target.getAttribute && target.getAttribute('data-scheduler-detail-open') === '1') {
                e.preventDefault();
                openModal(target);
                return;
            }
            if (target.getAttribute && target.hasAttribute('data-scheduler-detail-close')) {
                e.preventDefault();
                closeModal();
                return;
            }
            target = target.parentNode;
        }
    });

    document.addEventListener('submit', function(e) {
        var form = e.target;
        if (!form || !form.getAttribute || !form.hasAttribute('data-task-comment-form')) return;
        e.preventDefault();
        var submitButton = form.querySelector ? form.querySelector('button[type="submit"]') : null;
        if (submitButton) submitButton.disabled = true;
        postEncoded(form.getAttribute('action') || '?r=task_comment_save', encodeFormData(form) + '&ajax=1', function(statusCode, response, rawText) {
            if (submitButton) submitButton.disabled = false;
            if (statusCode < 200 || statusCode >= 300 || !responseOk(response)) {
                alert(responseMessage(response, '댓글 등록에 실패했습니다.'));
                return;
            }
            var wrap = form.closest ? form.closest('[data-task-comments]') : null;
            if (wrap) {
                var oldList = wrap.querySelector('[data-task-comments-list]');
                if (oldList && typeof response.comments_html !== 'undefined') oldList.outerHTML = response.comments_html;
                var countNode = wrap.querySelector('[data-task-comments-count]');
                if (countNode && typeof response.comment_count !== 'undefined') countNode.textContent = response.comment_count;
            }
            form.reset();
            if (window.lucide) { try { lucide.createIcons(); } catch (err) {} }
        });
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' || e.keyCode === 27) closeModal();
    });
})();
</script>
