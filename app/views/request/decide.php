<?php
require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location:?r=login'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location:?r=대시보드'); exit; }
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) { flash_set('error', 'CSRF 오류'); header('Location:?r=대시보드'); exit; }

$requestId = isset($_POST['request_id']) ? trim((string)$_POST['request_id']) : '';
$decision = isset($_POST['decision']) ? strtoupper(trim((string)$_POST['decision'])) : '';
$rejectReason = isset($_POST['reject_reason']) ? trim((string)$_POST['reject_reason']) : '';

if ($requestId === '' || ($decision !== 'APPROVED' && $decision !== 'REJECTED')) {
    flash_set('error', '요청 값이 올바르지 않습니다.');
    header('Location:?r=대시보드');
    exit;
}
if ($decision === 'REJECTED' && $rejectReason === '') {
    flash_set('error', '반려사유를 입력하세요.');
    header('Location:?r=대시보드');
    exit;
}

$pdo = Db::pdo();
$me = cpms_find_employee_id_by_email($pdo, (string)Auth::userEmail());
$store = cpms_request_store_load();
$foundIndex = -1;
for ($i = 0; $i < count($store['requests']); $i++) {
    if (isset($store['requests'][$i]['request_id']) && $store['requests'][$i]['request_id'] === $requestId) {
        $foundIndex = $i;
        break;
    }
}
if ($foundIndex < 0) {
    flash_set('error', '요청을 찾을 수 없습니다.');
    header('Location:?r=대시보드');
    exit;
}
$req = $store['requests'][$foundIndex];
if ((string)$req['status'] !== 'PENDING') {
    flash_set('error', '이미 처리된 요청입니다.');
    header('Location:?r=대시보드');
    exit;
}
if ((int)$req['target_user_id'] !== (int)$me) {
    flash_set('error', '처리 권한이 없습니다.');
    header('Location:?r=대시보드');
    exit;
}

$now = date('Y-m-d H:i:s');
$store['requests'][$foundIndex]['status'] = $decision;
$store['requests'][$foundIndex]['decided_at'] = $now;
$store['requests'][$foundIndex]['decided_by_user_id'] = (int)$me;
$store['requests'][$foundIndex]['reject_reason'] = ($decision === 'REJECTED') ? $rejectReason : '';

if ($decision === 'APPROVED' && isset($req['request_type']) && $req['request_type'] === 'LABOR_MANPOWER_CHANGE') {
    $payload = isset($req['payload']) && is_array($req['payload']) ? $req['payload'] : array();
    $projectId = isset($payload['project_id']) ? (int)$payload['project_id'] : 0;
    $month = isset($payload['month']) ? (string)$payload['month'] : '';
    $workerName = isset($payload['worker_name']) ? (string)$payload['worker_name'] : '';
    $date = isset($payload['date']) ? (string)$payload['date'] : '';
    $newValue = isset($payload['requested_value']) ? (float)$payload['requested_value'] : 0.0;

    if ($projectId <= 0 || $month === '' || $workerName === '' || $date === '') {
        flash_set('error', '승인 반영 payload가 올바르지 않습니다.');
        header('Location:?r=대시보드');
        exit;
    }

    $ok = cpms_set_labor_override($projectId, $month, $workerName, $date, $newValue, array(
        'source' => 'REQUEST_APPROVED',
        'request_id' => $requestId,
        'approved_by_email' => (string)Auth::userEmail(),
        'approved_by_name' => (string)Auth::userName(),
    ));
    if (!$ok) {
        flash_set('error', '승인 반영 저장에 실패했습니다.');
        header('Location:?r=대시보드');
        exit;
    }
}

$store['logs'][] = array(
    'at' => $now,
    'request_id' => $requestId,
    'action' => $decision,
    'actor_email' => (string)Auth::userEmail(),
    'actor_name' => (string)Auth::userName(),
    'reject_reason' => ($decision === 'REJECTED') ? $rejectReason : '',
);

if (!cpms_request_store_save($store)) {
    flash_set('error', '요청 상태 저장에 실패했습니다.');
    header('Location:?r=대시보드');
    exit;
}

flash_set('success', $decision === 'APPROVED' ? '요청을 승인했습니다.' : '요청을 반려했습니다.');
header('Location:?r=대시보드');
exit;