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
$currentEmployeeId = isset($currentEmployee['id']) ? (int)$currentEmployee['id'] : 0;
$taskId = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
$dueDate = trim((string)(isset($_POST['due_date']) ? $_POST['due_date'] : ''));
$dueTime = trim((string)(isset($_POST['due_time']) ? $_POST['due_time'] : ''));
$message = trim((string)(isset($_POST['message']) ? $_POST['message'] : ''));

if ($dueDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
    flash_set('danger', '마감일자를 입력해주세요.');
    cpms_tasks_redirect_back();
}
if ($dueTime === '') $dueTime = '18:00';
$dueTime = cpms_tasks_normalize_time_value($dueTime);
if ($dueTime === '') {
    flash_set('danger', '마감시간이 올바르지 않습니다.');
    cpms_tasks_redirect_back();
}

$task = cpms_tasks_find_task($pdo, $taskId);
if (!$task || !cpms_tasks_can_update_due_date($task, $currentEmployeeId)) {
    flash_set('danger', '마감일자 수정 권한이 없습니다.');
    cpms_tasks_redirect_back();
}
if (isset($task['status']) && in_array((string)$task['status'], array('done', 'cancelled'), true)) {
    flash_set('danger', '완료 또는 취소된 업무의 마감일자는 수정할 수 없습니다.');
    cpms_tasks_redirect_back();
}

try {
    if (cpms_tasks_update_due_date($pdo, $task, $currentEmployee, $dueDate, $dueTime, $message, cpms_tasks_now())) {
        flash_set('success', '마감일자를 수정했습니다.');
    } else {
        flash_set('danger', '마감일자 수정에 실패했습니다.');
    }
} catch (Exception $e) {
    flash_set('danger', '마감일자 수정에 실패했습니다.');
}

cpms_tasks_redirect_back();
