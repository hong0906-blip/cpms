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
$cancelReason = trim((string)(isset($_POST['cancel_reason']) ? $_POST['cancel_reason'] : ''));
$task = cpms_tasks_find_task($pdo, $taskId);

if (!$task || !cpms_tasks_can_cancel($task, isset($currentEmployee['id']) ? (int)$currentEmployee['id'] : 0)) {
    flash_set('danger', '업무 취소 권한이 없습니다.');
    cpms_tasks_redirect_back();
}

try {
    $st = $pdo->prepare("UPDATE cpms_tasks SET status = 'cancelled', cancelled_at = :cancelled_at, cancelled_by = :cancelled_by, cancel_reason = :cancel_reason, updated_at = :updated_at WHERE id = :id");
    $st->execute(array(
        ':cancelled_at' => cpms_tasks_now(),
        ':cancelled_by' => (int)$currentEmployee['id'] > 0 ? (int)$currentEmployee['id'] : null,
        ':cancel_reason' => $cancelReason !== '' ? $cancelReason : null,
        ':updated_at' => cpms_tasks_now(),
        ':id' => $taskId,
    ));
    cpms_tasks_insert_log($pdo, $taskId, $currentEmployee, 'cancelled', $cancelReason, isset($task['status']) ? $task['status'] : null, 'cancelled');
    flash_set('success', '업무 요청을 취소했습니다.');
} catch (Exception $e) {
    flash_set('danger', '업무 취소 처리에 실패했습니다.');
}

cpms_tasks_redirect_back();
