<?php
use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/helpers.php';
$samsungSafetyHelper = dirname(__DIR__) . '/safety/safety_cost_helper.php';
if (is_file($samsungSafetyHelper)) {
    require_once $samsungSafetyHelper;
}

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
$taskId = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
$completedMemo = trim((string)(isset($_POST['completed_memo']) ? $_POST['completed_memo'] : ''));
$task = cpms_tasks_find_task($pdo, $taskId);

if (!$task || !cpms_tasks_can_change_status($task, isset($currentEmployee['id']) ? (int)$currentEmployee['id'] : 0)) {
    flash_set('danger', '완료 처리 권한이 없습니다.');
    cpms_tasks_redirect_back();
}
$currentStatus = isset($task['status']) ? (string)$task['status'] : '';
if (in_array($currentStatus, array('done', 'cancelled'), true)) {
    flash_set('danger', '이미 완료 또는 취소된 업무입니다.');
    cpms_tasks_redirect_back();
}
if (isset($task['task_type']) && (string)$task['task_type'] === 'meeting' && !cpms_tasks_can_complete_meeting_after_response($task, isset($currentEmployee['id']) ? (int)$currentEmployee['id'] : 0)) {
    flash_set('danger', '회의 요청은 참석가능 또는 참석불가능 선택 후 완료 처리해주세요.');
    cpms_tasks_redirect_back();
}

if ((!isset($task['task_type']) || (string)$task['task_type'] !== 'meeting') && $completedMemo === '') {
    flash_set('danger', '완료 처리 내용을 입력해주세요.');
    cpms_tasks_redirect_back();
}

$now = cpms_tasks_now();

try {
    $completionResult = cpms_tasks_complete_task_and_group($pdo, $task, $currentEmployee, $completedMemo, $now);
    if (isset($_FILES['attachments'])) {
        $savedCompleteFiles = cpms_tasks_save_uploaded_files($pdo, $taskId, $_FILES['attachments'], (int)$currentEmployee['id'], 'complete');
        if (is_array($savedCompleteFiles) && count($savedCompleteFiles) > 0 && isset($completionResult['synced_ids']) && is_array($completionResult['synced_ids'])) {
            for ($i = 0; $i < count($completionResult['synced_ids']); $i++) {
                cpms_tasks_copy_saved_files_to_task($pdo, $savedCompleteFiles, (int)$completionResult['synced_ids'][$i], (int)$currentEmployee['id'], 'complete');
            }
        }
    }
    if (function_exists('cpms_samsung_portal_handle_task_completed')) {
        cpms_samsung_portal_handle_task_completed($pdo, $task, $currentEmployee, $now);
    }
    $updatedTask = cpms_tasks_find_task($pdo, $taskId);
    if ($updatedTask) {
        cpms_tasks_send_completed_notification($pdo, $updatedTask);
    }
    flash_set('success', '업무를 완료 처리했습니다.');
} catch (Exception $e) {
    flash_set('danger', '완료 처리에 실패했습니다.');
}

cpms_tasks_redirect_back();
