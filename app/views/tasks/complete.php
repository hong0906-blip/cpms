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
$currentEmployee = cpms_tasks_current_employee($pdo);
$taskId = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
$completedMemo = trim((string)(isset($_POST['completed_memo']) ? $_POST['completed_memo'] : ''));
$task = cpms_tasks_find_task($pdo, $taskId);

if (!$task || !cpms_tasks_can_change_status($task, isset($currentEmployee['id']) ? (int)$currentEmployee['id'] : 0)) {
    flash_set('danger', '완료 처리 권한이 없습니다.');
    cpms_tasks_redirect_back();
}
if (isset($task['task_type']) && (string)$task['task_type'] === 'meeting' && !cpms_tasks_can_complete_meeting_after_response($task, isset($currentEmployee['id']) ? (int)$currentEmployee['id'] : 0)) {
    flash_set('danger', '회의 요청은 참석가능 또는 참석불가능 선택 후 완료 처리해주세요.');
    cpms_tasks_redirect_back();
}

$now = cpms_tasks_now();

try {
    $st = $pdo->prepare("UPDATE cpms_tasks SET status = 'done', completed_at = :completed_at, completed_by = :completed_by, completed_memo = :completed_memo, updated_at = :updated_at WHERE id = :id");
    $st->execute(array(
        ':completed_at' => $now,
        ':completed_by' => (int)$currentEmployee['id'] > 0 ? (int)$currentEmployee['id'] : null,
        ':completed_memo' => $completedMemo !== '' ? $completedMemo : null,
        ':updated_at' => $now,
        ':id' => $taskId,
    ));
    $groupKey = (isset($task['group_key']) && trim((string)$task['group_key']) !== '') ? trim((string)$task['group_key']) : '';
    if ($groupKey !== '' && cpms_tasks_column_exists($pdo, 'cpms_tasks', 'group_key') && cpms_tasks_should_sync_group_completion($groupKey)) {
        $syncMemo = $completedMemo;
        if ($syncMemo === '') {
            $syncMemo = urldecode('%EA%B3%B5%EC%9A%A9%20%ED%95%A0%EC%9D%BC%20%EB%AC%B6%EC%9D%8C%20%EC%9E%90%EB%8F%99%20%EC%99%84%EB%A3%8C');
        }
        $st = $pdo->prepare("SELECT id,status FROM cpms_tasks WHERE group_key=:group_key AND id<>:id");
        $st->execute(array(
            ':group_key' => $groupKey,
            ':id' => $taskId
        ));
        $siblings = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($siblings)) {
            $siblings = array();
        }
        $up = $pdo->prepare("UPDATE cpms_tasks SET status='done', completed_at=:completed_at, completed_by=:completed_by, completed_memo=:completed_memo, updated_at=:updated_at WHERE id=:id");
        for ($i = 0; $i < count($siblings); $i++) {
            if (isset($siblings[$i]['status']) && in_array((string)$siblings[$i]['status'], array('done', 'cancelled'), true)) {
                continue;
            }
            $up->execute(array(
                ':completed_at' => $now,
                ':completed_by' => (int)$currentEmployee['id'] > 0 ? (int)$currentEmployee['id'] : null,
                ':completed_memo' => $syncMemo,
                ':updated_at' => $now,
                ':id' => (int)$siblings[$i]['id']
            ));
            cpms_tasks_insert_log($pdo, (int)$siblings[$i]['id'], $currentEmployee, 'completed', $syncMemo, isset($siblings[$i]['status']) ? $siblings[$i]['status'] : null, 'done');
        }
    }
    if (isset($_FILES['attachments'])) {
        cpms_tasks_save_uploaded_files($pdo, $taskId, $_FILES['attachments'], (int)$currentEmployee['id']);
    }
    cpms_tasks_insert_log($pdo, $taskId, $currentEmployee, 'completed', $completedMemo, isset($task['status']) ? $task['status'] : null, 'done');
    if (function_exists('cpms_samsung_portal_handle_task_completed')) {
        cpms_samsung_portal_handle_task_completed($pdo, $task, $currentEmployee, $now);
    }
    $updatedTask = cpms_tasks_find_task($pdo, $taskId);
    if ($updatedTask && (!isset($updatedTask['task_type']) || (string)$updatedTask['task_type'] !== 'meeting')) {
        cpms_tasks_send_completed_notification($pdo, $updatedTask);
    }
    flash_set('success', '업무를 완료 처리했습니다.');
} catch (Exception $e) {
    flash_set('danger', '완료 처리에 실패했습니다.');
}

cpms_tasks_redirect_back();
