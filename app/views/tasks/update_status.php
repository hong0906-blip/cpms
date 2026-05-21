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
$status = trim((string)(isset($_POST['status']) ? $_POST['status'] : ''));
$allowedStatuses = array('pending', 'progress');

if (!in_array($status, $allowedStatuses, true)) {
    flash_set('danger', '변경할 상태가 올바르지 않습니다.');
    cpms_tasks_redirect_back();
}

$task = cpms_tasks_find_task($pdo, $taskId);
if (!$task || !cpms_tasks_can_change_status($task, isset($currentEmployee['id']) ? (int)$currentEmployee['id'] : 0)) {
    flash_set('danger', '상태 변경 권한이 없습니다.');
    cpms_tasks_redirect_back();
}

try {
    $st = $pdo->prepare("UPDATE cpms_tasks SET status = :status, updated_at = :updated_at WHERE id = :id");
    $st->execute(array(
        ':status' => $status,
        ':updated_at' => cpms_tasks_now(),
        ':id' => $taskId,
    ));
    cpms_tasks_insert_log($pdo, $taskId, $currentEmployee, 'status_changed', '', isset($task['status']) ? $task['status'] : null, $status);
    flash_set('success', '업무 상태가 변경되었습니다.');
} catch (Exception $e) {
    flash_set('danger', '업무 상태 변경에 실패했습니다.');
}

cpms_tasks_redirect_back();
