<?php
require_once __DIR__ . '/helpers.php';
require_once dirname(__DIR__) . '/attendance/common.php';
require_once dirname(__DIR__) . '/construction/partials/equipment_gongsu_approval_helper.php';
require_once dirname(dirname(__DIR__)) . '/services/PublicAffairsCollaborationService.php';

if (!function_exists('cpms_task_feed_item')) {
function cpms_task_feed_item($row)
{
    $item = array(
        'source_type' => isset($row['source_type']) ? (string)$row['source_type'] : 'task',
        'source_id' => isset($row['source_id']) ? (int)$row['source_id'] : 0,
        'title' => isset($row['title']) ? (string)$row['title'] : '',
        'content' => isset($row['content']) ? (string)$row['content'] : '',
        'requester_name' => isset($row['requester_name']) ? (string)$row['requester_name'] : '',
        'requester_employee_id' => isset($row['requester_employee_id']) ? (int)$row['requester_employee_id'] : 0,
        'assignee_name' => isset($row['assignee_name']) ? (string)$row['assignee_name'] : '',
        'assignee_employee_id' => isset($row['assignee_employee_id']) ? (int)$row['assignee_employee_id'] : 0,
        'department' => isset($row['department']) ? cpms_tasks_normalize_department($row['department']) : '기타',
        'project_id' => isset($row['project_id']) ? (int)$row['project_id'] : 0,
        'project_name' => isset($row['project_name']) ? (string)$row['project_name'] : '',
        'due_date' => isset($row['due_date']) ? (string)$row['due_date'] : '',
        'due_time' => isset($row['due_time']) ? (string)$row['due_time'] : '',
        'priority' => isset($row['priority']) ? (string)$row['priority'] : 'normal',
        'is_urgent' => isset($row['is_urgent']) ? (int)$row['is_urgent'] : 0,
        'status' => isset($row['status']) ? (string)$row['status'] : 'pending',
        'task_type' => isset($row['task_type']) ? (string)$row['task_type'] : 'general',
        'action_url' => isset($row['action_url']) ? (string)$row['action_url'] : '',
        'is_direct_task' => isset($row['is_direct_task']) ? (int)$row['is_direct_task'] : 0,
        'created_at' => isset($row['created_at']) ? (string)$row['created_at'] : '',
        'completed_at' => isset($row['completed_at']) ? (string)$row['completed_at'] : '',
    );
    $item['display_status'] = isset($row['display_status']) && trim((string)$row['display_status']) !== ''
        ? (string)$row['display_status']
        : cpms_tasks_display_status($item);
    return $item;
}}

if (!function_exists('cpms_task_feed_sort')) {
function cpms_task_feed_sort($a, $b)
{
    $aUrgent = isset($a['is_urgent']) ? (int)$a['is_urgent'] : 0;
    $bUrgent = isset($b['is_urgent']) ? (int)$b['is_urgent'] : 0;
    if ($aUrgent !== $bUrgent) return ($aUrgent > $bUrgent) ? -1 : 1;

    $aDue = cpms_tasks_due_datetime($a);
    $bDue = cpms_tasks_due_datetime($b);
    if ($aDue === '' && $bDue !== '') return 1;
    if ($aDue !== '' && $bDue === '') return -1;
    if ($aDue !== '' && $bDue !== '') {
        $aTs = strtotime($aDue);
        $bTs = strtotime($bDue);
        if ($aTs !== $bTs) return ($aTs < $bTs) ? -1 : 1;
    }

    $aId = isset($a['source_id']) ? (int)$a['source_id'] : 0;
    $bId = isset($b['source_id']) ? (int)$b['source_id'] : 0;
    if ($aId === $bId) return 0;
    return ($aId > $bId) ? -1 : 1;
}}

if (!function_exists('cpms_task_feed_is_direct_work_task')) {
function cpms_task_feed_is_direct_work_task($item)
{
    if (!is_array($item)) return false;
    $sourceType = isset($item['source_type']) ? (string)$item['source_type'] : '';
    $taskType = isset($item['task_type']) ? (string)$item['task_type'] : '';
    return ($sourceType === 'task' && isset($item['is_direct_task']) && (int)$item['is_direct_task'] === 1 && $taskType !== 'meeting');
}}

if (!function_exists('cpms_task_feed_is_done_today')) {
function cpms_task_feed_is_done_today($item)
{
    if (!is_array($item)) return false;
    $status = isset($item['status']) ? (string)$item['status'] : '';
    if ($status !== 'done') return false;
    $today = cpms_tasks_today();
    $completedAt = isset($item['completed_at']) ? trim((string)$item['completed_at']) : '';
    if ($completedAt !== '' && substr($completedAt, 0, 10) === $today) return true;
    $dueDate = isset($item['due_date']) ? trim((string)$item['due_date']) : '';
    return ($dueDate === $today);
}}

if (!function_exists('cpms_task_feed_should_show')) {
function cpms_task_feed_should_show($item)
{
    if (!is_array($item)) return false;
    $status = isset($item['status']) ? (string)$item['status'] : '';
    if (cpms_task_feed_is_direct_work_task($item)) {
        if ($status === 'done') return cpms_task_feed_is_done_today($item);
        if ($status === 'cancelled') return false;
        return true;
    }
    if ($status === 'done') return cpms_task_feed_is_done_today($item);
    if (cpms_tasks_is_closed_status($status)) return false;
    $dueDate = isset($item['due_date']) ? trim((string)$item['due_date']) : '';
    $sourceType = isset($item['source_type']) ? (string)$item['source_type'] : '';
    if ($sourceType !== 'public_affairs_collab' && $dueDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate) && strcmp($dueDate, cpms_tasks_today()) < 0) return false;
    return true;
}}

if (!function_exists('cpms_task_feed_counts_as_today')) {
function cpms_task_feed_counts_as_today($item)
{
    if (!is_array($item)) return false;
    $status = isset($item['status']) ? (string)$item['status'] : '';
    if ($status === 'done') return cpms_task_feed_is_done_today($item);
    if (cpms_tasks_is_closed_status($status)) return false;
    $sourceType = isset($item['source_type']) ? (string)$item['source_type'] : '';
    if ($sourceType === 'construction_schedule') return false;
    if (cpms_task_feed_is_direct_work_task($item)) return true;
    $dueDate = isset($item['due_date']) ? trim((string)$item['due_date']) : '';
    return (cpms_tasks_is_due_today($item) || $dueDate === '');
}}

if (!function_exists('cpms_task_feed_merge')) {
function cpms_task_feed_merge($lists)
{
    $merged = array();
    if (!is_array($lists)) return $merged;
    foreach ($lists as $list) {
        if (!is_array($list)) continue;
        foreach ($list as $row) {
            if (!is_array($row)) continue;
            $item = cpms_task_feed_item($row);
            if (!cpms_task_feed_should_show($item)) continue;
            $merged[count($merged)] = $item;
        }
    }
    usort($merged, 'cpms_task_feed_sort');
    return $merged;
}}

if (!function_exists('cpms_task_feed_direct_tasks_for_employee')) {
function cpms_task_feed_direct_tasks_for_employee($pdo, $employeeId)
{
    $rows = array();
    if (!$pdo || (int)$employeeId <= 0 || !cpms_tasks_table_exists($pdo, 'cpms_tasks')) return $rows;
    try {
        $hasCompletedAt = cpms_tasks_column_exists($pdo, 'cpms_tasks', 'completed_at');
        $params = array(':employee_id' => (int)$employeeId, ':today_due' => cpms_tasks_today());
        $doneTodaySql = $hasCompletedAt
            ? "(status IS NULL OR status <> 'done' OR DATE(completed_at) = :today_completed OR due_date = :today_due)"
            : "(status IS NULL OR status <> 'done' OR due_date = :today_due)";
        if ($hasCompletedAt) $params[':today_completed'] = cpms_tasks_today();
        $st = $pdo->prepare("SELECT * FROM cpms_tasks
                              WHERE assignee_employee_id = :employee_id
                                AND (status IS NULL OR status <> 'cancelled')
                                AND " . $doneTodaySql . "
                              ORDER BY is_urgent DESC, due_date ASC, due_time ASC, id DESC");
        $st->execute($params);
        $tasks = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($tasks)) $tasks = array();
        foreach ($tasks as $task) {
            $rows[count($rows)] = array(
                'source_type' => 'task',
                'source_id' => isset($task['id']) ? (int)$task['id'] : 0,
                'title' => isset($task['title']) ? (string)$task['title'] : '',
                'content' => isset($task['content']) ? (string)$task['content'] : '',
                'requester_name' => isset($task['requester_name']) ? (string)$task['requester_name'] : '',
                'requester_employee_id' => isset($task['requester_employee_id']) ? (int)$task['requester_employee_id'] : 0,
                'assignee_name' => isset($task['assignee_name']) ? (string)$task['assignee_name'] : '',
                'assignee_employee_id' => isset($task['assignee_employee_id']) ? (int)$task['assignee_employee_id'] : 0,
                'department' => isset($task['department']) ? (string)$task['department'] : '',
                'project_id' => isset($task['project_id']) ? (int)$task['project_id'] : 0,
                'project_name' => isset($task['project_name']) ? (string)$task['project_name'] : '',
                'due_date' => isset($task['due_date']) ? (string)$task['due_date'] : '',
                'due_time' => isset($task['due_time']) ? (string)$task['due_time'] : '',
                'priority' => isset($task['priority']) ? (string)$task['priority'] : 'normal',
                'is_urgent' => isset($task['is_urgent']) ? (int)$task['is_urgent'] : 0,
                'status' => isset($task['status']) ? (string)$task['status'] : 'pending',
                'task_type' => isset($task['task_type']) ? (string)$task['task_type'] : 'general',
                'display_status' => cpms_tasks_display_status($task),
                'action_url' => '?r=tasks/detail&id=' . (int)$task['id'],
                'is_direct_task' => 1,
                'created_at' => isset($task['created_at']) ? (string)$task['created_at'] : '',
                'completed_at' => isset($task['completed_at']) ? (string)$task['completed_at'] : '',
            );
        }
    } catch (Exception $e) {
        $rows = array();
    }
    return $rows;
}}

if (!function_exists('cpms_task_feed_direct_tasks_requested_by_employee')) {
function cpms_task_feed_direct_tasks_requested_by_employee($pdo, $employeeId, $requestedDate = '')
{
    $rows = array();
    if (!$pdo || (int)$employeeId <= 0 || !cpms_tasks_table_exists($pdo, 'cpms_tasks')) return $rows;
    $requestedDate = trim((string)$requestedDate);
    if ($requestedDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedDate)) $requestedDate = '';
    try {
        $sql = "SELECT * FROM cpms_tasks WHERE requester_employee_id = :employee_id";
        $params = array(':employee_id' => (int)$employeeId);
        if ($requestedDate !== '') {
            $sql .= " AND DATE(created_at) = :requested_date";
            $params[':requested_date'] = $requestedDate;
        }
        $sql .= " ORDER BY created_at DESC, id DESC";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $tasks = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($tasks)) $tasks = array();
        foreach ($tasks as $task) {
            $rows[count($rows)] = array(
                'source_type' => 'task',
                'source_id' => isset($task['id']) ? (int)$task['id'] : 0,
                'title' => isset($task['title']) ? (string)$task['title'] : '',
                'content' => isset($task['content']) ? (string)$task['content'] : '',
                'requester_name' => isset($task['requester_name']) ? (string)$task['requester_name'] : '',
                'requester_employee_id' => isset($task['requester_employee_id']) ? (int)$task['requester_employee_id'] : 0,
                'assignee_name' => isset($task['assignee_name']) ? (string)$task['assignee_name'] : '',
                'assignee_employee_id' => isset($task['assignee_employee_id']) ? (int)$task['assignee_employee_id'] : 0,
                'department' => isset($task['department']) ? (string)$task['department'] : '',
                'project_id' => isset($task['project_id']) ? (int)$task['project_id'] : 0,
                'project_name' => isset($task['project_name']) ? (string)$task['project_name'] : '',
                'due_date' => isset($task['due_date']) ? (string)$task['due_date'] : '',
                'due_time' => isset($task['due_time']) ? (string)$task['due_time'] : '',
                'priority' => isset($task['priority']) ? (string)$task['priority'] : 'normal',
                'is_urgent' => isset($task['is_urgent']) ? (int)$task['is_urgent'] : 0,
                'status' => isset($task['status']) ? (string)$task['status'] : 'pending',
                'task_type' => isset($task['task_type']) ? (string)$task['task_type'] : 'general',
                'display_status' => cpms_tasks_display_status($task),
                'action_url' => '?r=tasks/detail&id=' . (int)$task['id'],
                'is_direct_task' => 1,
                'created_at' => isset($task['created_at']) ? (string)$task['created_at'] : '',
                'group_key' => isset($task['group_key']) ? (string)$task['group_key'] : '',
            );
        }

        $grouped = array();
        $order = array();
        for ($i = 0; $i < count($rows); $i++) {
            $row = $rows[$i];
            $groupKey = isset($row['group_key']) ? trim((string)$row['group_key']) : '';
            $key = $groupKey !== '' ? 'group:' . $groupKey : 'task:' . (isset($row['source_id']) ? (int)$row['source_id'] : 0);
            if (!isset($grouped[$key])) {
                $grouped[$key] = array(
                    'row' => $row,
                    'assignees' => array(),
                    'statuses' => array()
                );
                $order[count($order)] = $key;
            } else {
                $existingAssigneeId = isset($grouped[$key]['row']['assignee_employee_id']) ? (int)$grouped[$key]['row']['assignee_employee_id'] : 0;
                $currentAssigneeId = isset($row['assignee_employee_id']) ? (int)$row['assignee_employee_id'] : 0;
                if ($existingAssigneeId === (int)$employeeId && $currentAssigneeId !== (int)$employeeId) {
                    $grouped[$key]['row'] = $row;
                }
            }
            $assigneeId = isset($row['assignee_employee_id']) ? (int)$row['assignee_employee_id'] : 0;
            $assigneeName = isset($row['assignee_name']) ? trim((string)$row['assignee_name']) : '';
            $assigneeKey = $assigneeId > 0 ? 'id:' . $assigneeId : 'name:' . $assigneeName;
            if ($assigneeName !== '' && !isset($grouped[$key]['assignees'][$assigneeKey])) {
                $grouped[$key]['assignees'][$assigneeKey] = array(
                    'id' => $assigneeId,
                    'name' => $assigneeName
                );
            }
            $status = isset($row['status']) ? (string)$row['status'] : '';
            if ($status !== '') {
                $grouped[$key]['statuses'][$status] = true;
            }
        }

        $dedupedRows = array();
        for ($i = 0; $i < count($order); $i++) {
            $key = $order[$i];
            if (!isset($grouped[$key])) continue;
            $row = $grouped[$key]['row'];
            $assigneeRecords = $grouped[$key]['assignees'];
            $names = array();
            $allNames = array();
            foreach ($assigneeRecords as $assigneeRecord) {
                if (!is_array($assigneeRecord)) continue;
                $name = isset($assigneeRecord['name']) ? trim((string)$assigneeRecord['name']) : '';
                $id = isset($assigneeRecord['id']) ? (int)$assigneeRecord['id'] : 0;
                if ($name === '') continue;
                $allNames[count($allNames)] = $name;
                if (count($assigneeRecords) > 1 && $id === (int)$employeeId) continue;
                $names[count($names)] = $name;
            }
            if (count($names) === 0) {
                $names = $allNames;
            }
            if (count($names) === 1) {
                $row['assignee_name'] = $names[0];
            } else if (count($names) > 1) {
                $row['assignee_name'] = $names[0] . ' 외 ' . (count($names) - 1) . '명';
            }
            if (isset($row['task_type']) && (string)$row['task_type'] === 'meeting') {
                $statuses = isset($grouped[$key]['statuses']) && is_array($grouped[$key]['statuses']) ? $grouped[$key]['statuses'] : array();
                if (isset($statuses['meeting_available'])) {
                    $row['status'] = 'meeting_available';
                } else if (isset($statuses['pending'])) {
                    $row['status'] = 'pending';
                } else if (isset($statuses['meeting_unavailable'])) {
                    $row['status'] = 'meeting_unavailable';
                }
                $row['display_status'] = cpms_tasks_display_status($row);
            }
            unset($row['group_key']);
            $dedupedRows[count($dedupedRows)] = $row;
        }
        $rows = $dedupedRows;
    } catch (Exception $e) {
        $rows = array();
    }
    return $rows;
}}

if (!function_exists('cpms_task_feed_approval_items_for_employee')) {
function cpms_task_feed_approval_items_for_employee($pdo, $employeeId, $employeeEmail)
{
    $rows = array();
    if (!$pdo || !cpms_tasks_table_exists($pdo, 'cpms_approval_documents') || !cpms_tasks_table_exists($pdo, 'cpms_approval_lines')) return $rows;
    try {
        $sql = "SELECT d.id, d.title, d.doc_type, d.created_by_id, d.created_by_name, d.created_at, l.role_type
                FROM cpms_approval_documents d
                JOIN cpms_approval_lines l ON l.document_id = d.id
                WHERE d.doc_status = 'PENDING'
                  AND l.line_status = 'PENDING'
                  AND (l.approver_id = :employee_id OR LOWER(TRIM(l.approver_email)) = LOWER(TRIM(:employee_email)))
                ORDER BY d.created_at DESC, d.id DESC";
        $st = $pdo->prepare($sql);
        $st->execute(array(':employee_id' => (int)$employeeId, ':employee_email' => (string)$employeeEmail));
        $items = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($items)) $items = array();
        foreach ($items as $item) {
            $rows[count($rows)] = array(
                'source_type' => 'approval',
                'source_id' => (int)$item['id'],
                'title' => trim((string)$item['title']) !== '' ? (string)$item['title'] : '전자결재 승인 요청',
                'content' => '',
                'requester_name' => isset($item['created_by_name']) ? (string)$item['created_by_name'] : '',
                'requester_employee_id' => isset($item['created_by_id']) ? (int)$item['created_by_id'] : 0,
                'assignee_name' => '',
                'assignee_employee_id' => (int)$employeeId,
                'department' => '',
                'project_id' => 0,
                'project_name' => '',
                'due_date' => '',
                'due_time' => '',
                'priority' => 'normal',
                'is_urgent' => 0,
                'status' => 'PENDING',
                'task_type' => 'approval',
                'display_status' => '승인대기',
                'action_url' => '?r=approval_detail&id=' . (int)$item['id'],
                'is_direct_task' => 0,
            );
        }
    } catch (Exception $e) {
        $rows = array();
    }
    return $rows;
}}

if (!function_exists('cpms_task_feed_labor_gongsu_items_for_employee')) {
function cpms_task_feed_labor_gongsu_items_for_employee($pdo, $employeeId, $employeeEmail)
{
    $rows = array();
    if (!$pdo || !cpms_tasks_table_exists($pdo, 'cpms_labor_gongsu_overrides')) return $rows;
    try {
        $sql = "SELECT o.id, o.project_id, o.project_id, o.worker_name, o.work_date, o.old_value, o.new_value, o.reason, o.requested_by, o.requested_by_name, o.requested_by_email, o.approval_stage, o.created_at, p.name AS project_name
                FROM cpms_labor_gongsu_overrides o
                LEFT JOIN cpms_projects p ON p.id = o.project_id
                WHERE o.status = 'pending'
                  AND (o.current_approver_employee_id = :employee_id OR LOWER(TRIM(o.current_approver_email)) = LOWER(TRIM(:employee_email)))
                ORDER BY o.created_at DESC, o.id DESC";
        $st = $pdo->prepare($sql);
        $st->execute(array(':employee_id' => (int)$employeeId, ':employee_email' => (string)$employeeEmail));
        $items = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($items)) $items = array();
        foreach ($items as $item) {
            $title = trim((string)$item['project_name']) !== '' ? (string)$item['project_name'] . ' 공수승인' : '공수승인 요청';
            $rows[count($rows)] = array(
                'source_type' => 'labor_gongsu',
                'source_id' => (int)$item['id'],
                'title' => $title,
                'content' => '작업자: ' . (isset($item['worker_name']) ? (string)$item['worker_name'] : '-') . ' / 작업일자: ' . (isset($item['work_date']) ? (string)$item['work_date'] : '-'),
                'requester_name' => trim((string)$item['requested_by_name']) !== '' ? (string)$item['requested_by_name'] : (string)$item['requested_by_email'],
                'requester_employee_id' => isset($item['requested_by']) ? (int)$item['requested_by'] : 0,
                'assignee_name' => '',
                'assignee_employee_id' => (int)$employeeId,
                'department' => '',
                'project_id' => isset($item['project_id']) ? (int)$item['project_id'] : 0,
                'project_name' => isset($item['project_name']) ? (string)$item['project_name'] : '',
                'due_date' => isset($item['work_date']) ? (string)$item['work_date'] : '',
                'due_time' => '',
                'priority' => 'normal',
                'is_urgent' => 0,
                'status' => 'PENDING',
                'task_type' => 'labor_gongsu',
                'display_status' => '승인대기',
                'action_url' => '?r=대시보드&dv=executive',
                'is_direct_task' => 0,
            );
        }
    } catch (Exception $e) {
        $rows = array();
    }
    return $rows;
}}

if (!function_exists('cpms_task_feed_equipment_gongsu_items_for_employee')) {
function cpms_task_feed_equipment_gongsu_items_for_employee($pdo, $employeeId, $employeeEmail)
{
    $rows = array();
    if (!$pdo || !cpms_tasks_table_exists($pdo, 'cpms_equipment_gongsu_overrides')) return $rows;
    try {
        $sql = "SELECT o.id, o.project_id, o.use_date, o.old_value, o.new_value, o.reason, o.requested_by, o.requested_by_name, o.requested_by_email, o.created_at, p.name AS project_name, e.vendor_name, e.spec
                FROM cpms_equipment_gongsu_overrides o
                LEFT JOIN cpms_projects p ON p.id = o.project_id
                LEFT JOIN cpms_equipment_items e ON e.id = o.equipment_id
                WHERE o.status = 'pending'
                  AND (o.current_approver_employee_id = :employee_id OR LOWER(TRIM(o.current_approver_email)) = LOWER(TRIM(:employee_email)))
                ORDER BY o.created_at DESC, o.id DESC";
        $st = $pdo->prepare($sql);
        $st->execute(array(':employee_id' => (int)$employeeId, ':employee_email' => (string)$employeeEmail));
        $items = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($items)) $items = array();
        foreach ($items as $item) {
            $equipmentName = trim((string)$item['vendor_name'] . ' ' . $item['spec']);
            $title = trim((string)$item['project_name']) !== '' ? (string)$item['project_name'] . ' 장비공수승인' : '장비공수승인 요청';
            $rows[count($rows)] = array(
                'source_type' => 'equipment_gongsu',
                'source_id' => (int)$item['id'],
                'title' => $title,
                'content' => '장비: ' . ($equipmentName !== '' ? $equipmentName : '-') . ' / 사용일자: ' . (isset($item['use_date']) ? (string)$item['use_date'] : '-'),
                'requester_name' => trim((string)$item['requested_by_name']) !== '' ? (string)$item['requested_by_name'] : (string)$item['requested_by_email'],
                'requester_employee_id' => isset($item['requested_by']) ? (int)$item['requested_by'] : 0,
                'assignee_name' => '',
                'assignee_employee_id' => (int)$employeeId,
                'department' => '',
                'project_id' => isset($item['project_id']) ? (int)$item['project_id'] : 0,
                'project_name' => isset($item['project_name']) ? (string)$item['project_name'] : '',
                'due_date' => isset($item['use_date']) ? (string)$item['use_date'] : '',
                'due_time' => '',
                'priority' => 'normal',
                'is_urgent' => 0,
                'status' => 'PENDING',
                'task_type' => 'equipment_gongsu',
                'display_status' => '승인대기',
                'action_url' => '?r=대시보드&dv=executive',
                'is_direct_task' => 0,
            );
        }
    } catch (Exception $e) {
        $rows = array();
    }
    return $rows;
}}

if (!function_exists('cpms_task_feed_attendance_items_for_employee')) {
function cpms_task_feed_attendance_items_for_employee($pdo, $employeeId, $employeeMeta)
{
    $rows = array();
    $canApprove = false;
    if (is_array($employeeMeta)) {
        $department = isset($employeeMeta['department']) ? $employeeMeta['department'] : '';
        $canApprove = cpms_tasks_is_management_department($department);
    } else {
        $canApprove = false;
    }
    if (!$pdo || !$canApprove || !cpms_tasks_table_exists($pdo, 'cpms_attendance_requests')) return $rows;
    $positionColumn = cpms_tasks_column_exists($pdo, 'employees', 'position') ? 'e.position' : "'' AS position";
    try {
        $sql = "SELECT r.id, r.employee_id, r.request_date, r.request_type, r.requested_check_in, r.requested_check_out, r.reason, r.created_at, e.name, e.department, " . $positionColumn . "
                FROM cpms_attendance_requests r
                JOIN employees e ON e.id = r.employee_id
                WHERE r.status = 'pending'
                ORDER BY r.created_at DESC, r.id DESC";
        $st = $pdo->query($sql);
        $items = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
        if (!is_array($items)) $items = array();
        foreach ($items as $item) {
            $content = '요청일자: ' . (isset($item['request_date']) ? (string)$item['request_date'] : '-') . ' / 요청구분: ' . (isset($item['request_type']) ? (string)$item['request_type'] : '-');
            $rows[count($rows)] = array(
                'source_type' => 'attendance',
                'source_id' => (int)$item['id'],
                'title' => '출퇴근 승인 요청',
                'content' => $content,
                'requester_name' => isset($item['name']) ? (string)$item['name'] : '',
                'requester_employee_id' => isset($item['employee_id']) ? (int)$item['employee_id'] : 0,
                'assignee_name' => '',
                'assignee_employee_id' => (int)$employeeId,
                'department' => isset($item['department']) ? (string)$item['department'] : '',
                'project_id' => 0,
                'project_name' => '',
                'due_date' => isset($item['request_date']) ? (string)$item['request_date'] : '',
                'due_time' => '',
                'priority' => 'normal',
                'is_urgent' => 0,
                'status' => 'pending',
                'task_type' => 'attendance',
                'display_status' => '승인대기',
                'action_url' => '?r=관리&tab=attendance&atab=requests',
                'is_direct_task' => 0,
            );
        }
    } catch (Exception $e) {
        $rows = array();
    }
    return $rows;
}}

if (!function_exists('cpms_task_feed_issue_items_for_employee')) {
function cpms_task_feed_issue_items_for_employee($pdo, $employeeId, $employeeEmail)
{
    $rows = array();
    if (!$pdo || !cpms_tasks_table_exists($pdo, 'cpms_project_issues')) return $rows;
    try {
        $sql = "SELECT i.id, i.project_id, i.title, i.description, i.reason, i.status, i.priority, i.created_by, i.created_by_name, i.created_by_email, i.created_at, p.name AS project_name
                FROM cpms_project_issues i
                LEFT JOIN cpms_projects p ON p.id = i.project_id
                WHERE (i.status IS NULL OR i.status NOT IN ('처리완료', '완료'))
                  AND (
                        i.created_by = :employee_id
                        OR LOWER(TRIM(i.created_by_email)) = LOWER(TRIM(:employee_email))
                        OR EXISTS (
                            SELECT 1
                            FROM cpms_project_members pm
                            WHERE pm.project_id = i.project_id
                              AND pm.employee_id = :employee_id2
                              AND LOWER(TRIM(pm.role)) IN ('main', 'sub')
                        )
                      )
                ORDER BY i.created_at DESC, i.id DESC";
        $st = $pdo->prepare($sql);
        $st->execute(array(':employee_id' => (int)$employeeId, ':employee_email' => (string)$employeeEmail, ':employee_id2' => (int)$employeeId));
        $items = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($items)) $items = array();
        foreach ($items as $item) {
            $rows[count($rows)] = array(
                'source_type' => 'issue',
                'source_id' => (int)$item['id'],
                'title' => trim((string)$item['title']) !== '' ? (string)$item['title'] : (trim((string)$item['reason']) !== '' ? (string)$item['reason'] : '이슈 조치 요청'),
                'content' => trim((string)$item['description']) !== '' ? (string)$item['description'] : (string)$item['reason'],
                'requester_name' => isset($item['created_by_name']) ? (string)$item['created_by_name'] : '',
                'requester_employee_id' => isset($item['created_by']) ? (int)$item['created_by'] : 0,
                'assignee_name' => '',
                'assignee_employee_id' => (int)$employeeId,
                'department' => '',
                'project_id' => isset($item['project_id']) ? (int)$item['project_id'] : 0,
                'project_name' => isset($item['project_name']) ? (string)$item['project_name'] : '',
                'due_date' => '',
                'due_time' => '',
                'priority' => isset($item['priority']) && trim((string)$item['priority']) !== '' ? 'high' : 'normal',
                'is_urgent' => 0,
                'status' => 'progress',
                'task_type' => 'issue',
                'display_status' => trim((string)$item['status']) !== '' ? (string)$item['status'] : '조치필요',
                'action_url' => '?r=공사&pid=' . (int)$item['project_id'] . '&tab=issues',
                'is_direct_task' => 0,
            );
        }
    } catch (Exception $e) {
        $rows = array();
    }
    return $rows;
}}

if (!function_exists('cpms_task_feed_safety_items_for_employee')) {
function cpms_task_feed_safety_items_for_employee($pdo, $employeeId, $employeeEmail)
{
    $rows = array();
    if (!$pdo || !cpms_tasks_table_exists($pdo, 'cpms_safety_incidents')) return $rows;
    try {
        $sql = "SELECT s.id, s.project_id, s.title, s.description, s.occurred_at, s.status, s.created_by, s.created_by_name, s.created_by_email, s.created_at, p.name AS project_name
                FROM cpms_safety_incidents s
                LEFT JOIN cpms_projects p ON p.id = s.project_id
                WHERE (s.status IS NULL OR s.status NOT IN ('처리완료', '완료'))
                  AND (
                        s.created_by = :employee_id
                        OR LOWER(TRIM(s.created_by_email)) = LOWER(TRIM(:employee_email))
                        OR EXISTS (
                            SELECT 1
                            FROM cpms_construction_roles cr
                            WHERE cr.project_id = s.project_id
                              AND (:employee_id2 IN (COALESCE(cr.site_employee_id, 0), COALESCE(cr.safety_employee_id, 0), COALESCE(cr.quality_employee_id, 0)))
                        )
                      )
                ORDER BY s.created_at DESC, s.id DESC";
        $st = $pdo->prepare($sql);
        $st->execute(array(':employee_id' => (int)$employeeId, ':employee_email' => (string)$employeeEmail, ':employee_id2' => (int)$employeeId));
        $items = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($items)) $items = array();
        foreach ($items as $item) {
            $rows[count($rows)] = array(
                'source_type' => 'safety_accident',
                'source_id' => (int)$item['id'],
                'title' => trim((string)$item['title']) !== '' ? (string)$item['title'] : '안전사고 조치 요청',
                'content' => isset($item['description']) ? (string)$item['description'] : '',
                'requester_name' => isset($item['created_by_name']) ? (string)$item['created_by_name'] : '',
                'requester_employee_id' => isset($item['created_by']) ? (int)$item['created_by'] : 0,
                'assignee_name' => '',
                'assignee_employee_id' => (int)$employeeId,
                'department' => '안전',
                'project_id' => isset($item['project_id']) ? (int)$item['project_id'] : 0,
                'project_name' => isset($item['project_name']) ? (string)$item['project_name'] : '',
                'due_date' => '',
                'due_time' => '',
                'priority' => 'high',
                'is_urgent' => 0,
                'status' => 'progress',
                'task_type' => 'safety_accident',
                'display_status' => trim((string)$item['status']) !== '' ? (string)$item['status'] : '조치필요',
                'action_url' => '?r=공사&pid=' . (int)$item['project_id'] . '&tab=safety',
                'is_direct_task' => 0,
            );
        }
    } catch (Exception $e) {
        $rows = array();
    }
    return $rows;
}}

if (!function_exists('cpms_task_feed_employee_project_ids')) {
function cpms_task_feed_employee_project_ids($pdo, $employeeId)
{
    static $cache = array();
    $projectIdMap = array();
    if (!$pdo || (int)$employeeId <= 0) return array();
    $cacheKey = (function_exists('spl_object_hash') ? spl_object_hash($pdo) : 'nopdo') . ':employee-project-ids:' . (int)$employeeId;
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];

    if (cpms_tasks_table_exists($pdo, 'cpms_project_members')) {
        try {
            $st = $pdo->prepare("SELECT DISTINCT project_id FROM cpms_project_members WHERE employee_id = :employee_id AND LOWER(TRIM(role)) IN ('main', 'sub')");
            $st->execute(array(':employee_id' => (int)$employeeId));
            $items = $st->fetchAll(PDO::FETCH_ASSOC);
            if (is_array($items)) {
                foreach ($items as $item) {
                    $projectId = isset($item['project_id']) ? (int)$item['project_id'] : 0;
                    if ($projectId > 0) $projectIdMap[$projectId] = $projectId;
                }
            }
        } catch (Exception $e) {
        }
    }

    if (cpms_tasks_table_exists($pdo, 'cpms_construction_roles')) {
        try {
            $st = $pdo->prepare("SELECT DISTINCT project_id
                                 FROM cpms_construction_roles
                                 WHERE :employee_id IN (
                                     COALESCE(site_employee_id, 0),
                                     COALESCE(safety_employee_id, 0),
                                     COALESCE(quality_employee_id, 0)
                                 )");
            $st->execute(array(':employee_id' => (int)$employeeId));
            $items = $st->fetchAll(PDO::FETCH_ASSOC);
            if (is_array($items)) {
                foreach ($items as $item) {
                    $projectId = isset($item['project_id']) ? (int)$item['project_id'] : 0;
                    if ($projectId > 0) $projectIdMap[$projectId] = $projectId;
                }
            }
        } catch (Exception $e) {
        }
    }

    $cache[$cacheKey] = array_values($projectIdMap);
    return $cache[$cacheKey];
}}

if (!function_exists('cpms_task_feed_construction_schedule_items_for_employee')) {
function cpms_task_feed_construction_schedule_items_for_employee($pdo, $employeeId)
{
    $rows = array();
    if (
        !$pdo
        || (int)$employeeId <= 0
        || !cpms_tasks_table_exists($pdo, 'cpms_schedule_tasks')
        || !cpms_tasks_table_exists($pdo, 'cpms_projects')
    ) {
        return $rows;
    }

    $projectIds = cpms_task_feed_employee_project_ids($pdo, $employeeId);
    if (count($projectIds) === 0) return $rows;

    $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
    $today = cpms_tasks_today();

    try {
        $sql = "SELECT st.id, st.project_id, st.name, st.start_date, st.end_date, st.progress, p.name AS project_name
                FROM cpms_schedule_tasks st
                INNER JOIN cpms_projects p ON p.id = st.project_id
                WHERE st.project_id IN (" . $placeholders . ")
                  AND COALESCE(st.progress, 0) < 100
                  AND COALESCE(st.start_date, st.end_date) IS NOT NULL
                  AND COALESCE(st.end_date, st.start_date) IS NOT NULL
                  AND COALESCE(st.start_date, st.end_date) <= ?
                  AND COALESCE(st.end_date, st.start_date) >= ?
                ORDER BY p.name ASC, st.sort_order ASC, st.id ASC";
        $params = $projectIds;
        $params[] = $today;
        $params[] = $today;
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $items = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($items)) $items = array();
        foreach ($items as $item) {
            $taskName = trim(isset($item['name']) ? (string)$item['name'] : '');
            if ($taskName === '') continue;
            $projectName = isset($item['project_name']) ? trim((string)$item['project_name']) : '';
            $rows[count($rows)] = array(
                'source_type' => 'construction_schedule',
                'source_id' => isset($item['id']) ? (int)$item['id'] : 0,
                'title' => $taskName,
                'content' => $taskName,
                'requester_name' => $projectName,
                'requester_employee_id' => 0,
                'assignee_name' => '',
                'assignee_employee_id' => (int)$employeeId,
                'department' => '공사',
                'project_id' => isset($item['project_id']) ? (int)$item['project_id'] : 0,
                'project_name' => $projectName,
                'due_date' => $today,
                'due_time' => '',
                'priority' => 'normal',
                'is_urgent' => 0,
                'status' => 'progress',
                'task_type' => 'construction_schedule',
                'display_status' => '오늘 공정',
                'action_url' => '?r=공사&pid=' . (isset($item['project_id']) ? (int)$item['project_id'] : 0) . '&tab=gantt',
                'is_direct_task' => 0,
            );
        }
    } catch (Exception $e) {
        $rows = array();
    }

    return $rows;
}}

if (!function_exists('cpms_task_feed_public_affairs_collab_items_for_employee')) {
function cpms_task_feed_public_affairs_collab_items_for_employee($pdo, $employeeId, $employeeEmail)
{
    // 대시보드 나의할일: 공무 협업툴 담당 업무를 기존 업무 피드에 합친다.
    $rows = array();
    $employeeId = (int)$employeeId;
    $employeeEmail = strtolower(trim((string)$employeeEmail));
    $tasks = cpms_public_affairs_collab_list_tasks();
    foreach ($tasks as $task) {
        if (!is_array($task)) continue;
        $status = isset($task['status']) ? (string)$task['status'] : '';
        if ($status === '완료') continue;
        $assigneeId = isset($task['assignee_employee_id']) ? (int)$task['assignee_employee_id'] : 0;
        $assigneeEmail = isset($task['assignee_email']) ? strtolower(trim((string)$task['assignee_email'])) : '';
        $matched = false;
        if ($employeeId > 0 && $assigneeId === $employeeId) $matched = true;
        if (!$matched && $employeeEmail !== '' && $assigneeEmail !== '' && $employeeEmail === $assigneeEmail) $matched = true;
        if (!$matched) continue;
        $priority = isset($task['priority']) ? (string)$task['priority'] : '보통';
        $title = isset($task['title']) ? (string)$task['title'] : '';
        $rows[] = array(
            'source_type' => 'public_affairs_collab',
            'source_id' => isset($task['id']) ? (int)$task['id'] : 0,
            'title' => $title,
            'content' => isset($task['content']) ? (string)$task['content'] : '',
            'requester_name' => isset($task['requester_name']) ? (string)$task['requester_name'] : '',
            'requester_employee_id' => isset($task['requester_employee_id']) ? (int)$task['requester_employee_id'] : 0,
            'assignee_name' => isset($task['assignee_name']) ? (string)$task['assignee_name'] : '',
            'assignee_employee_id' => $assigneeId,
            'department' => '공무',
            'project_id' => isset($task['project_id']) ? (int)$task['project_id'] : 0,
            'project_name' => isset($task['project_name']) ? (string)$task['project_name'] : '',
            'due_date' => isset($task['due_date']) ? (string)$task['due_date'] : '',
            'due_time' => isset($task['due_time']) ? (string)$task['due_time'] : '',
            'priority' => $priority,
            'is_urgent' => ($priority === '긴급') ? 1 : 0,
            'status' => $status,
            'display_status' => $status,
            'task_type' => '공무 협업툴',
            'action_url' => '?r=공무&tab=collaboration&task_id=' . (isset($task['id']) ? (int)$task['id'] : 0),
            'is_direct_task' => 0,
            'created_at' => isset($task['created_at']) ? (string)$task['created_at'] : '',
        );
    }
    return $rows;
}}

if (!function_exists('cpms_task_feed_for_employee')) {
function cpms_task_feed_for_employee($pdo, $employeeId, $employeeEmail, $employeeMeta)
{
    static $cache = array();
    $cacheKey = (function_exists('spl_object_hash') ? spl_object_hash($pdo) : 'nopdo') . ':feed-for-employee:' . (int)$employeeId . ':' . strtolower(trim((string)$employeeEmail));
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];
    cpms_tasks_bootstrap_automations($pdo);
    $cache[$cacheKey] = cpms_task_feed_merge(array(
        cpms_task_feed_direct_tasks_for_employee($pdo, $employeeId),
        cpms_task_feed_construction_schedule_items_for_employee($pdo, $employeeId),
        cpms_task_feed_approval_items_for_employee($pdo, $employeeId, $employeeEmail),
        cpms_task_feed_labor_gongsu_items_for_employee($pdo, $employeeId, $employeeEmail),
        cpms_task_feed_equipment_gongsu_items_for_employee($pdo, $employeeId, $employeeEmail),
        cpms_task_feed_attendance_items_for_employee($pdo, $employeeId, $employeeMeta),
        cpms_task_feed_issue_items_for_employee($pdo, $employeeId, $employeeEmail),
        cpms_task_feed_safety_items_for_employee($pdo, $employeeId, $employeeEmail),
    ));
    return $cache[$cacheKey];
}}

if (!function_exists('cpms_task_feed_hide_executive_employee')) {
function cpms_task_feed_hide_executive_employee($employee)
{
    $name = trim(isset($employee['name']) ? (string)$employee['name'] : '');
    if ($name === '') return false;
    return (strpos($name, '노욱형') !== false);
}}

if (!function_exists('cpms_task_feed_for_executive')) {
function cpms_task_feed_for_executive($pdo, $filters)
{
    $result = array(
        'summary' => array(
            'today' => 0,
            'urgent' => 0,
            'due_soon' => 0,
            'delayed' => 0,
            'done' => 0,
            'approval_pending' => 0,
        ),
        'departments' => array(),
        'employees' => array(),
        'employee_rows' => array(),
    );

    if (!$pdo) return $result;

    $selectedDepartment = isset($filters['department']) ? cpms_tasks_normalize_department($filters['department']) : '';
    $employees = cpms_tasks_fetch_active_employees($pdo);
    $departmentSeed = cpms_tasks_department_options();
    foreach ($departmentSeed as $departmentName) {
        $result['departments'][$departmentName] = array(
            'department' => $departmentName,
            'today' => 0,
            'done' => 0,
            'progress' => 0,
            'delayed' => 0,
            'urgent' => 0,
            'due_soon' => 0,
        );
    }

    foreach ($employees as $employee) {
        $employeeId = isset($employee['id']) ? (int)$employee['id'] : 0;
        if (cpms_task_feed_hide_executive_employee($employee)) {
            continue;
        }
        $department = cpms_tasks_normalize_department(isset($employee['department']) ? $employee['department'] : '');
        if ($selectedDepartment !== '' && $selectedDepartment !== '전체' && $department !== $selectedDepartment) {
            continue;
        }
        $feed = cpms_task_feed_for_employee($pdo, $employeeId, isset($employee['email']) ? $employee['email'] : '', $employee);
        $metrics = array(
            'today' => 0,
            'done' => 0,
            'progress' => 0,
            'delayed' => 0,
            'urgent' => 0,
            'due_soon' => 0,
            'approval_pending' => 0,
        );

        foreach ($feed as $item) {
            if (cpms_task_feed_counts_as_today($item)) $metrics['today']++;
            if (isset($item['status']) && (string)$item['status'] === 'done') $metrics['done']++;
            if (isset($item['status']) && in_array((string)$item['status'], array('progress', 'revision'), true)) $metrics['progress']++;
            if (cpms_tasks_is_delayed($item)) $metrics['delayed']++;
            if (isset($item['is_urgent']) && (int)$item['is_urgent'] === 1) $metrics['urgent']++;
            if (cpms_tasks_is_due_soon($item)) $metrics['due_soon']++;
            if (isset($item['source_type']) && in_array((string)$item['source_type'], array('approval', 'labor_gongsu', 'equipment_gongsu', 'attendance'), true)) {
                $metrics['approval_pending']++;
            }
        }

        if (!isset($result['departments'][$department])) {
            $result['departments'][$department] = array(
                'department' => $department,
                'today' => 0,
                'done' => 0,
                'progress' => 0,
                'delayed' => 0,
                'urgent' => 0,
                'due_soon' => 0,
            );
        }
        $result['departments'][$department]['today'] += $metrics['today'];
        $result['departments'][$department]['done'] += $metrics['done'];
        $result['departments'][$department]['progress'] += $metrics['progress'];
        $result['departments'][$department]['delayed'] += $metrics['delayed'];
        $result['departments'][$department]['urgent'] += $metrics['urgent'];
        $result['departments'][$department]['due_soon'] += $metrics['due_soon'];

        $result['summary']['today'] += $metrics['today'];
        $result['summary']['urgent'] += $metrics['urgent'];
        $result['summary']['due_soon'] += $metrics['due_soon'];
        $result['summary']['delayed'] += $metrics['delayed'];
        $result['summary']['done'] += $metrics['done'];
        $result['summary']['approval_pending'] += $metrics['approval_pending'];

        $employeeRow = array(
            'employee' => $employee,
            'department' => $department,
            'metrics' => $metrics,
            'feed' => $feed,
        );
        $result['employees'][count($result['employees'])] = $employeeRow;
        $result['employee_rows'][$employeeId] = $employeeRow;
    }

    return $result;
}}
