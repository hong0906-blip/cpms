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
$newAssigneeId = isset($_POST['assignee_employee_id']) ? (int)$_POST['assignee_employee_id'] : 0;
$reason = isset($_POST['transfer_reason']) ? trim((string)$_POST['transfer_reason']) : '';

$task = cpms_tasks_find_task($pdo, $taskId);
if (!$task || !cpms_tasks_can_transfer($task, $currentEmployeeId)) {
    flash_set('danger', '담당자 변경 요청 권한이 없거나 이미 변경 요청이 진행 중입니다.');
    cpms_tasks_redirect_back();
}
if ($newAssigneeId <= 0 || $newAssigneeId === $currentEmployeeId) {
    flash_set('danger', '변경 요청할 담당자를 선택해주세요.');
    cpms_tasks_redirect_back();
}

$newAssignee = null;
$activeEmployees = cpms_tasks_fetch_active_employees($pdo);
for ($i = 0; $i < count($activeEmployees); $i++) {
    if (isset($activeEmployees[$i]['id']) && (int)$activeEmployees[$i]['id'] === $newAssigneeId) {
        $newAssignee = $activeEmployees[$i];
        break;
    }
}
if (!$newAssignee) {
    flash_set('danger', '변경 요청할 담당자 정보를 찾을 수 없습니다.');
    cpms_tasks_redirect_back();
}

if (cpms_tasks_request_transfer($pdo, $task, $newAssignee, $currentEmployee, $reason)) {
    flash_set('success', '업무 요청자에게 담당자 변경 승인을 요청했습니다.');
} else {
    flash_set('danger', '담당자 변경 요청에 실패했습니다.');
}

cpms_tasks_redirect_back();
