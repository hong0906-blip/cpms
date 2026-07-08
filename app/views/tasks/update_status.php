<?php
use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/helpers.php';

$isAjax = (isset($_POST['ajax']) && (string)$_POST['ajax'] === '1')
    || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
$cpmsTasksStatusAjaxBufferLevel = -1;
$cpmsTasksStatusResponseSent = false;
if ($isAjax) {
    $cpmsTasksStatusAjaxBufferLevel = ob_get_level();
    ob_start();
}
if (!function_exists('cpms_tasks_status_clear_output')) {
function cpms_tasks_status_clear_output()
{
    global $cpmsTasksStatusAjaxBufferLevel;
    if ((int)$cpmsTasksStatusAjaxBufferLevel < 0) return;
    while (ob_get_level() > (int)$cpmsTasksStatusAjaxBufferLevel) {
        @ob_end_clean();
    }
}}
if (!function_exists('cpms_tasks_status_debug_log')) {
function cpms_tasks_status_debug_log($message)
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . (string)$message . "\n";
    error_log('[CPMS tasks/update_status] ' . (string)$message);
    if (function_exists('cpms_storage_root')) {
        $dir = rtrim(cpms_storage_root(), '/\\') . '/logs';
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        if (is_dir($dir) && is_writable($dir)) {
            @error_log($line, 3, $dir . '/tasks_update_status.log');
        }
    }
}}
if (!function_exists('cpms_tasks_status_shutdown')) {
function cpms_tasks_status_shutdown()
{
    global $isAjax, $cpmsTasksStatusResponseSent;
    if (!$isAjax || $cpmsTasksStatusResponseSent) return;
    $error = error_get_last();
    if (!is_array($error) || !isset($error['type'])) return;
    if (!in_array((int)$error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) return;
    cpms_tasks_status_debug_log('fatal type=' . (int)$error['type'] . ' message=' . (isset($error['message']) ? $error['message'] : '') . ' file=' . (isset($error['file']) ? $error['file'] : '') . ' line=' . (isset($error['line']) ? $error['line'] : ''));
    cpms_tasks_status_clear_output();
    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(array(
        'ok' => 0,
        'message' => '업무 상태 변경 중 서버 오류가 발생했습니다.',
    ));
}}
if ($isAjax) {
    register_shutdown_function('cpms_tasks_status_shutdown');
}
if (!function_exists('cpms_tasks_status_json_response')) {
function cpms_tasks_status_json_response($ok, $message, $extra)
{
    global $cpmsTasksStatusResponseSent;
    $cpmsTasksStatusResponseSent = true;
    cpms_tasks_status_clear_output();
    if (!headers_sent()) {
        http_response_code(200);
    }
    header('Content-Type: application/json; charset=utf-8');
    $payload = is_array($extra) ? $extra : array();
    $payload['ok'] = $ok ? 1 : 0;
    $payload['message'] = (string)$message;
    echo json_encode($payload);
    exit;
}}
if (!function_exists('cpms_tasks_status_fail')) {
function cpms_tasks_status_fail($message, $isAjax)
{
    if ($isAjax) {
        cpms_tasks_status_debug_log('fail message=' . (string)$message . ' task_id=' . (isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0) . ' status=' . (isset($_POST['status']) ? (string)$_POST['status'] : ''));
        cpms_tasks_status_json_response(false, $message, array());
    }
    flash_set('danger', $message);
    cpms_tasks_redirect_back();
}}
if (!function_exists('cpms_tasks_status_lane_key')) {
function cpms_tasks_status_lane_key($status)
{
    $status = (string)$status;
    if ($status === 'done' || $status === 'completed') return 'done';
    if ($status === 'completion_pending') return 'completion_pending';
    if ($status === 'rejected' || $status === 'cancelled' || $status === 'meeting_unavailable') return 'rejected';
    if (in_array($status, array('progress', 'processing', 'revision', 'meeting_available'), true)) return 'progress';
    return 'pending';
}}
if (!function_exists('cpms_tasks_status_update_task')) {
function cpms_tasks_status_update_task($pdo, $taskId, $status, $now, $currentEmployeeId, $completedMemo, $markCompleted)
{
    $sets = array('status = :status');
    $params = array(
        ':status' => (string)$status,
        ':id' => (int)$taskId,
    );

    if (cpms_tasks_column_exists($pdo, 'cpms_tasks', 'completed_at')) {
        $sets[count($sets)] = 'completed_at = :completed_at';
        $params[':completed_at'] = $markCompleted ? $now : null;
    }
    if (cpms_tasks_column_exists($pdo, 'cpms_tasks', 'completed_by')) {
        $sets[count($sets)] = 'completed_by = :completed_by';
        $params[':completed_by'] = ($markCompleted && (int)$currentEmployeeId > 0) ? (int)$currentEmployeeId : null;
    }
    if (cpms_tasks_column_exists($pdo, 'cpms_tasks', 'completed_memo')) {
        $sets[count($sets)] = 'completed_memo = :completed_memo';
        $params[':completed_memo'] = ($markCompleted && trim((string)$completedMemo) !== '') ? (string)$completedMemo : null;
    }
    if (cpms_tasks_column_exists($pdo, 'cpms_tasks', 'updated_at')) {
        $sets[count($sets)] = 'updated_at = :updated_at';
        $params[':updated_at'] = $now;
    }

    $st = $pdo->prepare("UPDATE cpms_tasks SET " . implode(', ', $sets) . " WHERE id = :id");
    $st->execute($params);
}}
if (!Auth::check()) {
    if ($isAjax) cpms_tasks_status_json_response(false, '로그인이 필요합니다. 다시 로그인해 주세요.', array('auth_required' => 1, 'login_url' => '?r=login'));
    header('Location: ?r=login');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cpms_tasks_status_fail('잘못된 요청입니다.', $isAjax);
}
if (!csrf_check(isset($_POST['_csrf']) ? $_POST['_csrf'] : '')) {
    cpms_tasks_status_fail('보안 토큰이 올바르지 않습니다.', $isAjax);
}

$pdo = Db::pdo();
$setupResults = array();
$needsSchemaEnsure = !cpms_tasks_column_exists($pdo, 'cpms_tasks', 'updated_at')
    || !cpms_tasks_column_exists($pdo, 'cpms_tasks', 'completed_at')
    || !cpms_tasks_column_exists($pdo, 'cpms_tasks', 'completed_by')
    || !cpms_tasks_column_exists($pdo, 'cpms_tasks', 'completed_memo');
if ($needsSchemaEnsure && function_exists('cpms_tasks_ensure_schema')) {
    cpms_tasks_ensure_schema($pdo, $setupResults);
}
$currentEmployee = cpms_tasks_current_employee($pdo);
$currentEmployeeId = isset($currentEmployee['id']) ? (int)$currentEmployee['id'] : 0;
$taskId = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
$status = trim((string)(isset($_POST['task_state']) ? $_POST['task_state'] : (isset($_POST['status']) ? $_POST['status'] : '')));
$completedMemo = trim((string)(isset($_POST['completed_memo']) ? $_POST['completed_memo'] : ''));
$allowedStatuses = array('pending', 'progress', 'completion_pending', 'done', 'rejected');

if (!in_array($status, $allowedStatuses, true)) {
    cpms_tasks_status_fail('변경할 상태가 올바르지 않습니다.', $isAjax);
}

$task = cpms_tasks_find_task($pdo, $taskId);
if (!$task) {
    cpms_tasks_status_fail('상태 변경 권한이 없습니다.', $isAjax);
}
$requestedStatus = $status;
$isMeetingTask = isset($task['task_type']) && (string)$task['task_type'] === 'meeting';
$canApproveDone = (!$isMeetingTask && $status === 'done' && cpms_tasks_can_approve_completion($task, $currentEmployeeId));
$canSelfComplete = (!$isMeetingTask && $status === 'done' && cpms_tasks_is_self_request($task) && cpms_tasks_can_submit_completion($task, $currentEmployeeId));
$canSubmitCompletion = (!$isMeetingTask && $status === 'completion_pending' && cpms_tasks_can_submit_completion($task, $currentEmployeeId));
$canChangeStatus = cpms_tasks_can_change_status($task, $currentEmployeeId);
if (!$canApproveDone && !$canSelfComplete && !$canSubmitCompletion && !$canChangeStatus) {
    cpms_tasks_status_fail('상태 변경 권한이 없습니다.', $isAjax);
}
if ($isMeetingTask && $status === 'progress') $status = 'meeting_available';
if ($isMeetingTask && $status === 'rejected') $status = 'meeting_unavailable';
if ($status === 'done' && isset($task['status']) && in_array((string)$task['status'], array('done', 'cancelled'), true)) {
    cpms_tasks_status_fail('이미 완료 또는 취소된 업무입니다.', $isAjax);
}
if (!$isMeetingTask && $requestedStatus === 'done' && !$canApproveDone && !$canSelfComplete) {
    cpms_tasks_status_fail('완료는 요청자 승인 후 처리할 수 있습니다.', $isAjax);
}
if (!$isMeetingTask && isset($task['status']) && (string)$task['status'] === 'completion_pending' && !in_array($requestedStatus, array('done', 'completion_pending'), true)) {
    cpms_tasks_status_fail('완료 대기중 업무는 요청자의 승인 또는 반려로만 변경할 수 있습니다.', $isAjax);
}
if (!$isMeetingTask && $status === 'done' && !$canApproveDone && $completedMemo === '') {
    cpms_tasks_status_fail('완료 처리 내용을 입력해주세요.', $isAjax);
}
if (!$isMeetingTask && $status === 'completion_pending' && $completedMemo === '') {
    cpms_tasks_status_fail('완료 처리 내용을 입력해주세요.', $isAjax);
}

try {
    $now = cpms_tasks_now();
    $notificationSent = false;
    if ($status === 'done' && $canApproveDone) {
        if (!cpms_tasks_approve_completion($pdo, $task, $currentEmployee, $completedMemo, $now)) {
            throw new Exception('completion approve failed');
        }
    } elseif ($status === 'completion_pending') {
        if (!cpms_tasks_request_completion($pdo, $task, $currentEmployee, $completedMemo, $now)) {
            throw new Exception('completion request failed');
        }
        $updatedTask = cpms_tasks_find_task($pdo, $taskId);
        if ($updatedTask) $notificationSent = cpms_tasks_send_completion_pending_notification($pdo, $updatedTask);
    } elseif ($status === 'done') {
        cpms_tasks_complete_task_and_group($pdo, $task, $currentEmployee, $completedMemo, $now);
        $updatedTask = cpms_tasks_find_task($pdo, $taskId);
        if ($updatedTask) $notificationSent = cpms_tasks_send_completed_notification($pdo, $updatedTask);
    } elseif ($isMeetingTask && in_array($status, array('meeting_available', 'meeting_unavailable'), true)) {
        cpms_tasks_status_update_task($pdo, $taskId, $status, $now, $currentEmployeeId, null, true);
        cpms_tasks_insert_log($pdo, $taskId, $currentEmployee, $status === 'meeting_available' ? 'meeting_available_action' : 'meeting_unavailable_action', '', isset($task['status']) ? $task['status'] : null, $status);
        $updatedTask = cpms_tasks_find_task($pdo, $taskId);
        if ($status === 'meeting_available' && $updatedTask) cpms_tasks_send_meeting_confirmed_notification($pdo, $updatedTask);
    } else {
        cpms_tasks_status_update_task($pdo, $taskId, $status, $now, $currentEmployeeId, null, false);
        cpms_tasks_insert_log($pdo, $taskId, $currentEmployee, 'status_changed', '', isset($task['status']) ? $task['status'] : null, $status);
    }
    if ($isAjax) {
        $updatedTask = cpms_tasks_find_task($pdo, $taskId);
        $responseStatus = $updatedTask && isset($updatedTask['status']) ? (string)$updatedTask['status'] : $status;
        $displayStatusKey = ($updatedTask && cpms_tasks_is_delayed($updatedTask)) ? 'delayed' : $responseStatus;
        cpms_tasks_status_json_response(true, '업무 상태가 변경되었습니다.', array(
            'task_id' => $taskId,
            'status' => $responseStatus,
            'requested_status' => $requestedStatus,
            'lane_key' => cpms_tasks_status_lane_key($responseStatus),
            'status_label' => cpms_tasks_status_label($responseStatus),
            'status_class' => cpms_tasks_badge_class('status', $displayStatusKey),
            'display_status' => $updatedTask ? cpms_tasks_display_status($updatedTask) : cpms_tasks_status_label($responseStatus),
            'notification_sent' => $notificationSent ? 1 : 0,
        ));
    }
    flash_set('success', '업무 상태가 변경되었습니다.');
} catch (Exception $e) {
    cpms_tasks_status_debug_log('exception task_id=' . (int)$taskId . ' status=' . (string)$status . ' error=' . $e->getMessage());
    if ($isAjax) cpms_tasks_status_json_response(false, '업무 상태 변경에 실패했습니다.', array());
    flash_set('danger', '업무 상태 변경에 실패했습니다.');
}

cpms_tasks_redirect_back();
