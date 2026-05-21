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
$currentEmployee = cpms_tasks_current_employee($pdo);
$taskId = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
$message = trim((string)(isset($_POST['revision_message']) ? $_POST['revision_message'] : ''));
$dueDate = trim((string)(isset($_POST['due_date']) ? $_POST['due_date'] : ''));
$dueTime = trim((string)(isset($_POST['due_time']) ? $_POST['due_time'] : ''));

if ($message === '' || $dueDate === '') {
    flash_set('danger', '보완 요청 내용과 재마감일을 입력해주세요.');
    cpms_tasks_redirect_back();
}
if ($dueTime === '') $dueTime = '18:00';
if (strlen($dueTime) === 5) $dueTime .= ':00';

$task = cpms_tasks_find_task($pdo, $taskId);
if (!$task || !cpms_tasks_can_request_revision($task, isset($currentEmployee['id']) ? (int)$currentEmployee['id'] : 0)) {
    flash_set('danger', '보완 요청 권한이 없습니다.');
    cpms_tasks_redirect_back();
}

try {
    $st = $pdo->prepare("UPDATE cpms_tasks SET status = 'revision', due_date = :due_date, due_time = :due_time, updated_at = :updated_at WHERE id = :id");
    $st->execute(array(
        ':due_date' => $dueDate,
        ':due_time' => $dueTime,
        ':updated_at' => cpms_tasks_now(),
        ':id' => $taskId,
    ));
    cpms_tasks_insert_log($pdo, $taskId, $currentEmployee, 'revision_requested', $message, isset($task['status']) ? $task['status'] : null, 'revision');
    flash_set('success', '보완 요청을 등록했습니다.');
} catch (Exception $e) {
    flash_set('danger', '보완 요청 처리에 실패했습니다.');
}

cpms_tasks_redirect_back();
