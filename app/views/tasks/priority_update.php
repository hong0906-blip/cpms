<?php
use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/helpers.php';

if (!Auth::check()) {
    header('Location: ?r=login');
    exit;
}

$isAjax = (isset($_POST['ajax']) && (string)$_POST['ajax'] === '1')
    || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

if (!function_exists('cpms_tasks_priority_json_response')) {
function cpms_tasks_priority_json_response($ok, $message, $extra)
{
    header('Content-Type: application/json; charset=utf-8');
    $payload = is_array($extra) ? $extra : array();
    $payload['ok'] = $ok ? 1 : 0;
    $payload['message'] = (string)$message;
    echo json_encode($payload);
    exit;
}}

if (!function_exists('cpms_tasks_priority_fail')) {
function cpms_tasks_priority_fail($message, $isAjax)
{
    if ($isAjax) cpms_tasks_priority_json_response(false, $message, array());
    flash_set('danger', $message);
    cpms_tasks_redirect_back();
}}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cpms_tasks_priority_fail('잘못된 요청입니다.', $isAjax);
}
if (!csrf_check(isset($_POST['_csrf']) ? $_POST['_csrf'] : '')) {
    cpms_tasks_priority_fail('보안 토큰이 올바르지 않습니다.', $isAjax);
}

$pdo = Db::pdo();
$currentEmployee = cpms_tasks_current_employee($pdo);
$currentEmployeeId = isset($currentEmployee['id']) ? (int)$currentEmployee['id'] : 0;
$taskId = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
$priority = trim((string)(isset($_POST['priority']) ? $_POST['priority'] : 'normal'));
$priorityOptions = cpms_tasks_priority_options();
if (!isset($priorityOptions[$priority])) {
    cpms_tasks_priority_fail('중요도 값이 올바르지 않습니다.', $isAjax);
}

$task = cpms_tasks_find_task($pdo, $taskId);
if (!$task || !cpms_tasks_can_view($task, $currentEmployeeId)) {
    cpms_tasks_priority_fail('업무를 찾을 수 없거나 조회 권한이 없습니다.', $isAjax);
}
if (isset($task['task_type']) && (string)$task['task_type'] === 'meeting') {
    cpms_tasks_priority_fail('회의 요청의 중요도는 변경할 수 없습니다.', $isAjax);
}
if ($currentEmployeeId <= 0 || (int)$task['assignee_employee_id'] !== $currentEmployeeId) {
    cpms_tasks_priority_fail('담당자만 중요도를 변경할 수 있습니다.', $isAjax);
}

try {
    $oldPriority = isset($task['priority']) ? (string)$task['priority'] : 'normal';
    $isUrgent = ($priority === 'urgent') ? 1 : 0;
    $taskType = isset($task['task_type']) ? (string)$task['task_type'] : 'general';
    if ($priority === 'urgent') {
        $taskType = 'urgent';
    } else if ($taskType === 'urgent') {
        $taskType = 'general';
    }
    $st = $pdo->prepare("UPDATE cpms_tasks SET priority = :priority, is_urgent = :is_urgent, task_type = :task_type, updated_at = :updated_at WHERE id = :id");
    $st->execute(array(
        ':priority' => $priority,
        ':is_urgent' => $isUrgent,
        ':task_type' => $taskType,
        ':updated_at' => cpms_tasks_now(),
        ':id' => $taskId,
    ));
    cpms_tasks_insert_log($pdo, $taskId, $currentEmployee, 'priority_changed', cpms_tasks_priority_label($oldPriority) . ' -> ' . cpms_tasks_priority_label($priority), $oldPriority, $priority);
    if ($isAjax) {
        cpms_tasks_priority_json_response(true, '중요도를 변경했습니다.', array(
            'task_id' => $taskId,
            'priority' => $priority,
            'priority_label' => cpms_tasks_priority_label($priority),
            'priority_class' => cpms_tasks_badge_class('priority', $priority),
            'is_urgent' => $isUrgent,
        ));
    }
    flash_set('success', '중요도를 변경했습니다.');
} catch (Exception $e) {
    if ($isAjax) cpms_tasks_priority_json_response(false, '중요도 변경에 실패했습니다.', array());
    flash_set('danger', '중요도 변경에 실패했습니다.');
}

cpms_tasks_redirect_back();
