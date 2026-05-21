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
$assigneeEmployeeId = isset($_POST['assignee_employee_id']) ? (int)$_POST['assignee_employee_id'] : 0;
$department = trim((string)(isset($_POST['department']) ? $_POST['department'] : ''));
$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$dueDate = trim((string)(isset($_POST['due_date']) ? $_POST['due_date'] : ''));
$dueTime = trim((string)(isset($_POST['due_time']) ? $_POST['due_time'] : ''));
$isUrgent = isset($_POST['is_urgent']) ? 1 : 0;

if ($title === '' || $assigneeEmployeeId <= 0) {
    flash_set('danger', '업무 제목과 담당자를 확인해주세요.');
    cpms_tasks_redirect_back();
}

$assignee = cpms_tasks_find_employee_by_id($pdo, $assigneeEmployeeId);
if (!$assignee) {
    flash_set('danger', '담당자 정보를 찾을 수 없습니다.');
    cpms_tasks_redirect_back();
}

if ($department === '') $department = isset($assignee['department']) ? (string)$assignee['department'] : '';
$department = cpms_tasks_normalize_department($department);
if ($dueTime === '') $dueTime = '18:00';
if (strlen($dueTime) === 5) $dueTime .= ':00';

$taskType = 'general';
$priority = 'normal';
if ($isUrgent === 1) {
    $taskType = 'urgent';
    $priority = 'urgent';
    $dueDate = cpms_tasks_today();
    $dueTime = '18:00:00';
}

if ($dueDate === '') {
    $dueTime = null;
}

$project = cpms_tasks_resolve_project($pdo, $projectId);
$now = cpms_tasks_now();

try {
    $st = $pdo->prepare("INSERT INTO cpms_tasks (title, content, requester_employee_id, requester_name, requester_email, assignee_employee_id, assignee_name, assignee_email, department, project_id, project_name, task_type, priority, is_urgent, due_date, due_time, status, created_by, created_at, updated_at) VALUES (:title, :content, :requester_employee_id, :requester_name, :requester_email, :assignee_employee_id, :assignee_name, :assignee_email, :department, :project_id, :project_name, :task_type, :priority, :is_urgent, :due_date, :due_time, 'pending', :created_by, :created_at, :updated_at)");
    $st->execute(array(
        ':title' => $title,
        ':content' => $content !== '' ? $content : null,
        ':requester_employee_id' => (int)$currentEmployee['id'],
        ':requester_name' => (string)$currentEmployee['name'],
        ':requester_email' => (string)$currentEmployee['email'],
        ':assignee_employee_id' => (int)$assignee['id'],
        ':assignee_name' => isset($assignee['name']) ? (string)$assignee['name'] : '',
        ':assignee_email' => isset($assignee['email']) ? (string)$assignee['email'] : '',
        ':department' => $department,
        ':project_id' => (int)$project['project_id'] > 0 ? (int)$project['project_id'] : null,
        ':project_name' => (string)$project['project_name'],
        ':task_type' => $taskType,
        ':priority' => $priority,
        ':is_urgent' => $isUrgent,
        ':due_date' => $dueDate !== '' ? $dueDate : null,
        ':due_time' => $dueTime !== null ? $dueTime : null,
        ':created_by' => (int)$currentEmployee['id'],
        ':created_at' => $now,
        ':updated_at' => $now,
    ));
    $taskId = (int)$pdo->lastInsertId();
    cpms_tasks_insert_log($pdo, $taskId, $currentEmployee, 'created', $content, null, 'pending');
    if (isset($_FILES['attachments'])) {
        cpms_tasks_save_uploaded_files($pdo, $taskId, $_FILES['attachments'], (int)$currentEmployee['id']);
    }
    $task = cpms_tasks_find_task($pdo, $taskId);
    if ($task) {
        cpms_tasks_send_created_notification($pdo, $task);
    }
    flash_set('success', '업무 요청이 등록되었습니다.');
} catch (Exception $e) {
    flash_set('danger', '업무 요청 저장에 실패했습니다.');
}

cpms_tasks_redirect_back();
