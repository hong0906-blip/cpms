<?php
use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function cpms_tasks_deferred_json($payload, $statusCode)
{
    http_response_code((int)$statusCode);
    echo json_encode($payload);
    exit;
}

if (!Auth::check()) {
    cpms_tasks_deferred_json(array('ok' => false, 'message' => 'Login required.'), 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cpms_tasks_deferred_json(array('ok' => false, 'message' => 'Method not allowed.'), 405);
}
if (!csrf_check(isset($_POST['_csrf']) ? $_POST['_csrf'] : '')) {
    cpms_tasks_deferred_json(array('ok' => false, 'message' => 'Invalid security token.'), 403);
}

$pdo = Db::pdo();
$currentEmployee = cpms_tasks_current_employee($pdo);
$currentEmployeeId = isset($currentEmployee['id']) ? (int)$currentEmployee['id'] : 0;
if (!$pdo || $currentEmployeeId <= 0) {
    cpms_tasks_deferred_json(array('ok' => false, 'message' => 'Employee was not found.'), 404);
}

$setupResults = array();
if (!cpms_tasks_schema_ready_quick($pdo)) cpms_tasks_ensure_schema($pdo, $setupResults);
if (function_exists('session_write_close')) @session_write_close();
@ignore_user_abort(true);
@set_time_limit(240);

$notificationResult = cpms_tasks_process_deferred_notifications($pdo, 10);
$driveResult = cpms_tasks_process_pending_drive_files($pdo, $currentEmployeeId, 3);
$remaining = (isset($driveResult['remaining']) ? (int)$driveResult['remaining'] : 0)
    + (isset($notificationResult['remaining']) ? (int)$notificationResult['remaining'] : 0);

cpms_tasks_deferred_json(array(
    'ok' => true,
    'remaining' => $remaining,
    'drive' => $driveResult,
    'notifications' => $notificationResult,
), 200);
