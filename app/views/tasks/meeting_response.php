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
$isAjax = (isset($_POST['ajax']) && (string)$_POST['ajax'] === '1')
    || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
if (!function_exists('cpms_tasks_meeting_response_json_response')) {
function cpms_tasks_meeting_response_json_response($ok, $message, $extra)
{
    header('Content-Type: application/json; charset=utf-8');
    $payload = is_array($extra) ? $extra : array();
    $payload['ok'] = $ok ? 1 : 0;
    $payload['message'] = (string)$message;
    echo json_encode($payload);
    exit;
}}
if (!function_exists('cpms_tasks_meeting_response_lane_key')) {
function cpms_tasks_meeting_response_lane_key($status)
{
    if ((string)$status === 'meeting_available') return 'progress';
    if ((string)$status === 'meeting_unavailable') return 'rejected';
    if ((string)$status === 'done') return 'done';
    return 'pending';
}}
if (!csrf_check(isset($_POST['_csrf']) ? $_POST['_csrf'] : '')) {
    flash_set('danger', '보안 토큰이 올바르지 않습니다.');
    cpms_tasks_redirect_back();
}

$pdo = Db::pdo();
$setupResults = array();
cpms_tasks_ensure_schema($pdo, $setupResults);
$currentEmployee = cpms_tasks_current_employee($pdo);
$taskId = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
$response = trim((string)(isset($_POST['response']) ? $_POST['response'] : ''));
$reason = trim((string)(isset($_POST['reason']) ? $_POST['reason'] : ''));
$task = cpms_tasks_find_task($pdo, $taskId);

if (!$task || !cpms_tasks_can_respond_meeting($task, isset($currentEmployee['id']) ? (int)$currentEmployee['id'] : 0)) {
    flash_set('danger', '회의 참석 응답 권한이 없습니다.');
    cpms_tasks_redirect_back();
}

$currentStatus = isset($task['status']) ? (string)$task['status'] : '';
if (in_array($currentStatus, array('meeting_available', 'meeting_unavailable', 'cancelled'), true)) {
    flash_set('danger', '이미 처리된 회의 요청입니다.');
    cpms_tasks_redirect_back();
}

if ($response !== 'available' && $response !== 'unavailable') {
    flash_set('danger', '회의 참석 응답이 올바르지 않습니다.');
    cpms_tasks_redirect_back();
}

if ($response === 'unavailable' && $reason === '') {
    flash_set('danger', '참석불가능 사유를 입력해주세요.');
    cpms_tasks_redirect_back();
}

$groupKey = isset($task['group_key']) ? trim((string)$task['group_key']) : '';
if ($response === 'available') {
    $conflictMeeting = cpms_tasks_find_meeting_conflict($pdo, isset($task['due_date']) ? $task['due_date'] : '', isset($task['due_time']) ? $task['due_time'] : '', $groupKey);
    if ($conflictMeeting) {
        flash_set('danger', '이미 확정된 회의가 있어 참석가능 처리할 수 없습니다. 확정 회의: ' . cpms_tasks_meeting_time_text(isset($conflictMeeting['due_date']) ? $conflictMeeting['due_date'] : '', isset($conflictMeeting['due_time']) ? $conflictMeeting['due_time'] : ''));
        cpms_tasks_redirect_back();
    }
}

$newStatus = $response === 'available' ? 'meeting_available' : 'meeting_unavailable';
$actionType = $response === 'available' ? 'meeting_available_action' : 'meeting_unavailable_action';
$message = $response === 'available' ? '참석가능' : $reason;
$now = cpms_tasks_now();

try {
    $st = $pdo->prepare("UPDATE cpms_tasks SET status=:status, completed_at=:completed_at, completed_by=:completed_by, completed_memo=:completed_memo, updated_at=:updated_at WHERE id=:id");
    $st->execute(array(
        ':status' => $newStatus,
        ':completed_at' => $now,
        ':completed_by' => isset($currentEmployee['id']) && (int)$currentEmployee['id'] > 0 ? (int)$currentEmployee['id'] : null,
        ':completed_memo' => $response === 'unavailable' ? $reason : null,
        ':updated_at' => $now,
        ':id' => $taskId
    ));
    cpms_tasks_insert_log($pdo, $taskId, $currentEmployee, $actionType, $message, $currentStatus, $newStatus);
    $updatedTask = cpms_tasks_find_task($pdo, $taskId);
    if ($response === 'available' && $updatedTask) {
        cpms_tasks_send_meeting_confirmed_notification($pdo, $updatedTask);
    }
    if ($isAjax) {
        $responseStatus = $updatedTask && isset($updatedTask['status']) ? (string)$updatedTask['status'] : $newStatus;
        $displayStatusKey = ($updatedTask && cpms_tasks_is_delayed($updatedTask)) ? 'delayed' : $responseStatus;
        cpms_tasks_meeting_response_json_response(true, $response === 'available' ? '참석가능으로 처리되었습니다.' : '참석불가능으로 처리되었습니다.', array(
            'task_id' => $taskId,
            'status' => $responseStatus,
            'lane_key' => cpms_tasks_meeting_response_lane_key($responseStatus),
            'status_label' => cpms_tasks_status_label($responseStatus),
            'status_class' => cpms_tasks_badge_class('status', $displayStatusKey),
            'display_status' => $updatedTask ? cpms_tasks_display_status($updatedTask) : cpms_tasks_status_label($responseStatus),
        ));
    }
    flash_set('success', $response === 'available' ? '참석가능으로 처리했습니다.' : '참석불가능으로 처리했습니다.');
} catch (Exception $e) {
    if ($isAjax) cpms_tasks_meeting_response_json_response(false, '회의 응답 처리에 실패했습니다.', array());
    flash_set('danger', '회의 참석 응답 저장에 실패했습니다.');
}

cpms_tasks_redirect_back();
