<?php
use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/helpers.php';

if (!Auth::check()) {
    header('Location: ?r=login');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash_set('danger', '잘못된 요청입니다.');
    cpms_tasks_redirect_back();
}
if (!csrf_check(isset($_POST['_csrf']) ? $_POST['_csrf'] : '')) {
    flash_set('danger', '보안 토큰이 올바르지 않습니다.');
    cpms_tasks_redirect_back();
}

$pdo = Db::pdo();
$setupResults = array();
cpms_tasks_ensure_schema($pdo, $setupResults);

$currentEmployee = cpms_tasks_current_employee($pdo);
if ((int)$currentEmployee['id'] <= 0) {
    flash_set('danger', '직원 정보를 찾을 수 없습니다.');
    cpms_tasks_redirect_back();
}

$title = trim((string)(isset($_POST['title']) ? $_POST['title'] : ''));
$content = trim((string)(isset($_POST['content']) ? $_POST['content'] : ''));
$taskKind = trim((string)(isset($_POST['task_kind']) ? $_POST['task_kind'] : 'task'));
$isMeetingRequest = ($taskKind === 'meeting' || (isset($_POST['task_type']) && (string)$_POST['task_type'] === 'meeting'));
$department = trim((string)(isset($_POST['department']) ? $_POST['department'] : ''));
$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$dueDate = trim((string)(isset($_POST['due_date']) ? $_POST['due_date'] : ''));
$dueTime = trim((string)(isset($_POST['due_time']) ? $_POST['due_time'] : ''));
$meetingDate = trim((string)(isset($_POST['meeting_date']) ? $_POST['meeting_date'] : ''));
$meetingTime = trim((string)(isset($_POST['meeting_time']) ? $_POST['meeting_time'] : ''));
$isUrgent = isset($_POST['is_urgent']) ? 1 : 0;
$priorityOptions = cpms_tasks_priority_options();
$priority = trim((string)(isset($_POST['priority']) ? $_POST['priority'] : 'normal'));
if (!isset($priorityOptions[$priority])) $priority = 'normal';

$assigneeEmployeeIds = array();
$assigneeIdSeen = array();
if (isset($_POST['assignee_employee_ids'])) {
    $rawAssigneeIds = $_POST['assignee_employee_ids'];
    if (!is_array($rawAssigneeIds)) {
        $rawAssigneeIds = explode(',', (string)$rawAssigneeIds);
    }
    foreach ($rawAssigneeIds as $rawAssigneeId) {
        $assigneeId = (int)$rawAssigneeId;
        if ($assigneeId <= 0 || isset($assigneeIdSeen[$assigneeId])) continue;
        $assigneeIdSeen[$assigneeId] = true;
        $assigneeEmployeeIds[count($assigneeEmployeeIds)] = $assigneeId;
    }
}
if (isset($_POST['assignee_employee_id'])) {
    $legacyAssigneeId = (int)$_POST['assignee_employee_id'];
    if ($legacyAssigneeId > 0 && !isset($assigneeIdSeen[$legacyAssigneeId])) {
        $assigneeIdSeen[$legacyAssigneeId] = true;
        $assigneeEmployeeIds[count($assigneeEmployeeIds)] = $legacyAssigneeId;
    }
}
$requestedAssigneeCount = count($assigneeEmployeeIds);

if ($title === '' || $requestedAssigneeCount === 0) {
    flash_set('danger', $isMeetingRequest ? '회의 제목과 참석자를 확인해주세요.' : '업무 제목과 담당자를 확인해주세요.');
    cpms_tasks_redirect_back();
}

if ($isMeetingRequest) {
    if ($meetingDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $meetingDate) || $meetingTime === '') {
        flash_set('danger', '회의 일자와 시간을 확인해주세요.');
        cpms_tasks_redirect_back();
    }
    $meetingTime = cpms_tasks_normalize_time_value($meetingTime);
    if ($meetingTime === '') {
        flash_set('danger', '회의 시간을 확인해주세요.');
        cpms_tasks_redirect_back();
    }
    $conflictMeeting = cpms_tasks_find_meeting_conflict($pdo, $meetingDate, $meetingTime, '');
    if ($conflictMeeting) {
        flash_set('danger', '이미 확정된 회의가 있어 해당 시간에는 회의 요청을 등록할 수 없습니다. 확정 회의: ' . cpms_tasks_meeting_time_text(isset($conflictMeeting['due_date']) ? $conflictMeeting['due_date'] : '', isset($conflictMeeting['due_time']) ? $conflictMeeting['due_time'] : ''));
        cpms_tasks_redirect_back();
    }
    $dueDate = $meetingDate;
    $dueTime = $meetingTime;
    $isUrgent = 0;
}

$requesterEmployeeId = (int)$currentEmployee['id'];
if ($isMeetingRequest && $requesterEmployeeId > 0 && !isset($assigneeIdSeen[$requesterEmployeeId])) {
    $assigneeIdSeen[$requesterEmployeeId] = true;
    $assigneeEmployeeIds[count($assigneeEmployeeIds)] = $requesterEmployeeId;
}

$assignees = array();
for ($i = 0; $i < count($assigneeEmployeeIds); $i++) {
    $assigneeEmployeeId = (int)$assigneeEmployeeIds[$i];
    if ($assigneeEmployeeId <= 0) continue;
    $assignee = cpms_tasks_find_employee_by_id($pdo, $assigneeEmployeeId);
    if (!$assignee && $assigneeEmployeeId === $requesterEmployeeId) {
        $assignee = $currentEmployee;
    }
    if (!$assignee) {
        flash_set('danger', '담당자 정보를 찾을 수 없습니다.');
        cpms_tasks_redirect_back();
    }

    $assigneeLeaveInfo = null;
    if ($assigneeEmployeeId !== $requesterEmployeeId && function_exists('approval_current_leave_info_for_employee')) {
        $assigneeLeaveInfo = approval_current_leave_info_for_employee($pdo, $assignee, cpms_tasks_today());
    }
    if (is_array($assigneeLeaveInfo)) {
        $assigneeName = isset($assignee['name']) ? (string)$assignee['name'] : '';
        flash_set('danger', ($assigneeName !== '' ? $assigneeName . '님은 ' : '') . approval_ko('%ED%98%84%EC%9E%AC%20%ED%9C%B4%EA%B0%80%EC%A4%91%EC%9D%B4%EB%AF%80%EB%A1%9C%20%EC%97%85%EB%AC%B4%EC%9A%94%EC%B2%AD%EC%9D%84%20%ED%95%A0%20%EC%88%98%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
        cpms_tasks_redirect_back();
    }

    $assignees[count($assignees)] = $assignee;
}

if (count($assignees) === 0) {
    flash_set('danger', $isMeetingRequest ? '회의 참석자를 확인해주세요.' : '담당자를 확인해주세요.');
    cpms_tasks_redirect_back();
}

$department = $department !== '' ? cpms_tasks_normalize_department($department) : '';
if ($dueTime === '') $dueTime = '18:00';
if (strlen($dueTime) === 5) $dueTime .= ':00';

$taskType = 'general';
if ($isMeetingRequest) {
    $taskType = 'meeting';
    $priority = 'normal';
} else if ($isUrgent === 1) {
    $taskType = 'urgent';
    $priority = 'urgent';
    $dueDate = cpms_tasks_today();
    $dueTime = '18:00:00';
} else if ($priority === 'urgent') {
    $taskType = 'urgent';
    $isUrgent = 1;
}

if ($dueDate === '') {
    $dueTime = null;
}

$project = cpms_tasks_resolve_project($pdo, $projectId);
$now = cpms_tasks_now();
$hasGroupKey = cpms_tasks_column_exists($pdo, 'cpms_tasks', 'group_key');
$groupKey = '';
if ($hasGroupKey) {
    $groupPrefix = $isMeetingRequest ? 'meeting_request' : 'task_request';
    $groupKey = $groupPrefix . ':' . $requesterEmployeeId . ':' . date('YmdHis') . ':' . substr(md5(uniqid('', true)), 0, 8);
}

try {
    $columns = "title, content, requester_employee_id, requester_name, requester_email, assignee_employee_id, assignee_name, assignee_email, department, project_id, project_name, task_type, priority, is_urgent, due_date, due_time, status, created_by, created_at, updated_at";
    $values = ":title, :content, :requester_employee_id, :requester_name, :requester_email, :assignee_employee_id, :assignee_name, :assignee_email, :department, :project_id, :project_name, :task_type, :priority, :is_urgent, :due_date, :due_time, :status, :created_by, :created_at, :updated_at";
    if ($hasGroupKey) {
        $columns .= ", group_key";
        $values .= ", :group_key";
    }
    $st = $pdo->prepare("INSERT INTO cpms_tasks (" . $columns . ") VALUES (" . $values . ")");
    $createdTaskIds = array();
    $createdAssigneeIds = array();
    $firstTaskId = 0;
    $transactionStarted = false;
    if (method_exists($pdo, 'inTransaction') && !$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $transactionStarted = true;
    }

    for ($i = 0; $i < count($assignees); $i++) {
        $assignee = $assignees[$i];
        $assigneeId = isset($assignee['id']) ? (int)$assignee['id'] : 0;
        $assigneeDepartment = $department !== '' ? $department : cpms_tasks_employee_department($assignee);
        $rowStatus = ($isMeetingRequest && $assigneeId === $requesterEmployeeId) ? 'meeting_owner' : 'pending';
        $params = array(
            ':title' => $title,
            ':content' => $content !== '' ? $content : null,
            ':requester_employee_id' => (int)$currentEmployee['id'],
            ':requester_name' => (string)$currentEmployee['name'],
            ':requester_email' => (string)$currentEmployee['email'],
            ':assignee_employee_id' => $assigneeId,
            ':assignee_name' => isset($assignee['name']) ? (string)$assignee['name'] : '',
            ':assignee_email' => isset($assignee['email']) ? (string)$assignee['email'] : '',
            ':department' => $assigneeDepartment,
            ':project_id' => (int)$project['project_id'] > 0 ? (int)$project['project_id'] : null,
            ':project_name' => (string)$project['project_name'],
            ':task_type' => $taskType,
            ':priority' => $priority,
            ':is_urgent' => $isUrgent,
            ':due_date' => $dueDate !== '' ? $dueDate : null,
            ':due_time' => $dueTime !== null ? $dueTime : null,
            ':status' => $rowStatus,
            ':created_by' => (int)$currentEmployee['id'],
            ':created_at' => $now,
            ':updated_at' => $now,
        );
        if ($hasGroupKey) {
            $params[':group_key'] = $groupKey !== '' ? $groupKey : null;
        }
        $st->execute($params);
        $taskId = (int)$pdo->lastInsertId();
        if ($taskId <= 0) continue;
        if ($firstTaskId <= 0) $firstTaskId = $taskId;
        $createdTaskIds[count($createdTaskIds)] = $taskId;
        $createdAssigneeIds[$taskId] = isset($assignee['id']) ? (int)$assignee['id'] : 0;
        cpms_tasks_insert_log($pdo, $taskId, $currentEmployee, 'created', $content, null, $rowStatus);
    }

    if ($transactionStarted) {
        $pdo->commit();
    }

    if ($firstTaskId > 0 && isset($_FILES['attachments'])) {
        $savedFiles = cpms_tasks_save_uploaded_files($pdo, $firstTaskId, $_FILES['attachments'], (int)$currentEmployee['id'], 'request');
        if (is_array($savedFiles) && count($savedFiles) > 0 && count($createdTaskIds) > 1) {
            for ($i = 0; $i < count($createdTaskIds); $i++) {
                if ((int)$createdTaskIds[$i] === (int)$firstTaskId) continue;
                cpms_tasks_copy_saved_files_to_task($pdo, $savedFiles, (int)$createdTaskIds[$i], (int)$currentEmployee['id'], 'request');
            }
        }
    }

    for ($i = 0; $i < count($createdTaskIds); $i++) {
        $taskId = (int)$createdTaskIds[$i];
        $assigneeId = isset($createdAssigneeIds[$taskId]) ? (int)$createdAssigneeIds[$taskId] : 0;
        $task = cpms_tasks_find_task($pdo, $taskId);
        if ($task) {
            cpms_tasks_send_created_notification($pdo, $task);
        }
    }

    if (count($createdTaskIds) > 0) {
        $messagePrefix = $isMeetingRequest ? '회의 요청' : '업무 요청';
        flash_set('success', $messagePrefix . '이 등록되었습니다. 내 할일에도 추가되었습니다.');
    } else {
        flash_set('danger', $isMeetingRequest ? '회의 요청 저장에 실패했습니다.' : '업무 요청 저장에 실패했습니다.');
    }
} catch (Exception $e) {
    if (isset($transactionStarted) && $transactionStarted && method_exists($pdo, 'inTransaction') && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash_set('danger', $isMeetingRequest ? '회의 요청 저장에 실패했습니다.' : '업무 요청 저장에 실패했습니다.');
}

cpms_tasks_redirect_back();
