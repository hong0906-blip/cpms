<?php
if (!class_exists('App\\Core\\Db')) {
    require_once dirname(dirname(__DIR__)) . '/bootstrap.php';
}
require_once __DIR__ . '/helpers.php';

if (!function_exists('cpms_tasks_delayed_notify_json')) {
function cpms_tasks_delayed_notify_json($ok, $result, $statusCode)
{
    if (!headers_sent()) {
        http_response_code((int)$statusCode);
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode(array(
        'ok' => $ok ? 1 : 0,
        'result' => $result,
    ));
    exit;
}}

$isCli = (php_sapi_name() === 'cli');
$remoteAddr = isset($_SERVER['REMOTE_ADDR']) ? trim((string)$_SERVER['REMOTE_ADDR']) : '';
$serverAddr = isset($_SERVER['SERVER_ADDR']) ? trim((string)$_SERVER['SERVER_ADDR']) : '';
$isLocal = ($remoteAddr === '127.0.0.1' || $remoteAddr === '::1' || ($serverAddr !== '' && $remoteAddr === $serverAddr));

$allowed = ($isCli || $isLocal);
if (!$allowed && class_exists('App\\Core\\Auth')) {
    try {
        $allowed = \App\Core\Auth::check()
            && (\App\Core\Auth::isMaster() || \App\Core\Auth::canManageEmployees() || \App\Core\Auth::userRole() === 'executive');
    } catch (Exception $e) {
        $allowed = false;
    }
}

if (!$allowed) {
    cpms_tasks_delayed_notify_json(false, array('message' => '403 Forbidden'), 403);
}

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 200;
if ($limit <= 0) $limit = 200;

try {
    $pdo = \App\Core\Db::pdo();
    $result = cpms_tasks_process_delayed_notifications($pdo, $limit);
    cpms_tasks_delayed_notify_json(true, $result, 200);
} catch (Exception $e) {
    cpms_tasks_delayed_notify_json(false, array('message' => $e->getMessage()), 500);
}
