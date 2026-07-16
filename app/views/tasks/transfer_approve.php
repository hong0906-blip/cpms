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
$task = cpms_tasks_find_task($pdo, $taskId);

if (!$task || !cpms_tasks_can_approve_transfer_request($task, $currentEmployeeId)) {
    flash_set('danger', '담당자 변경 승인 권한이 없거나 처리할 요청이 없습니다.');
    cpms_tasks_redirect_back();
}

try {
    if (cpms_tasks_approve_transfer_request($pdo, $task, $currentEmployee)) {
        flash_set('success', '담당자 변경을 승인했습니다.');
    } else {
        flash_set('danger', '담당자 변경 승인에 실패했습니다.');
    }
} catch (Exception $e) {
    flash_set('danger', '담당자 변경 승인에 실패했습니다.');
}

cpms_tasks_redirect_back();
