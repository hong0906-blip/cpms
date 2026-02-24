<?php
require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

header('Content-Type: application/json; charset=utf-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(array('ok' => false, 'message' => '로그인이 필요합니다.'));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('ok' => false, 'message' => '허용되지 않은 요청입니다.'));
    exit;
}
$csrf = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
if (!csrf_check($csrf)) {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'message' => 'CSRF 오류'));
    exit;
}

$requestType = isset($_POST['request_type']) ? trim((string)$_POST['request_type']) : '';
$targetUserId = isset($_POST['target_user_id']) ? (int)$_POST['target_user_id'] : 0;
$reason = isset($_POST['reason']) ? trim((string)$_POST['reason']) : '';
$reRequestOf = isset($_POST['re_request_of']) ? trim((string)$_POST['re_request_of']) : '';
$payloadRaw = isset($_POST['payload']) ? (string)$_POST['payload'] : '{}';
$payload = @json_decode($payloadRaw, true);
if (!is_array($payload)) $payload = array();

if ($requestType === '' || $targetUserId <= 0 || $reason === '') {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'message' => '필수값이 누락되었습니다.'));
    exit;
}

$role = Auth::userRole();
$dept = Auth::userDepartment();
if (!($role === 'executive' || $dept === '공사')) {
    http_response_code(403);
    echo json_encode(array('ok' => false, 'message' => '요청 생성 권한이 없습니다.'));
    exit;
}

if ($requestType === 'LABOR_MANPOWER_CHANGE') {
    $projectId = isset($payload['project_id']) ? (int)$payload['project_id'] : 0;
    $requestValue = isset($payload['requested_value']) ? (float)$payload['requested_value'] : 0.0;
    $pdo = Db::pdo();
    if (!cpms_is_project_member_or_executive($pdo, $projectId, $role, (string)Auth::userEmail())) {
        http_response_code(403);
        echo json_encode(array('ok' => false, 'message' => '담당 프로젝트만 요청할 수 있습니다.'));
        exit;
    }
    if ($requestValue < 1.5) {
        http_response_code(400);
        echo json_encode(array('ok' => false, 'message' => '1.5 이상만 요청 가능합니다.'));
        exit;
    }
}

$pdo = Db::pdo();
$requesterUserId = cpms_find_employee_id_by_email($pdo, (string)Auth::userEmail());
$requestId = cpms_request_new_id();
$now = date('Y-m-d H:i:s');

$store = cpms_request_store_load();
$record = array(
    'request_id' => $requestId,
    'request_type' => $requestType,
    'status' => 'PENDING',
    'requester_user_id' => (int)$requesterUserId,
    'requester_name' => (string)Auth::userName(),
    'requester_email' => (string)Auth::userEmail(),
    'target_user_id' => (int)$targetUserId,
    'created_at' => $now,
    'decided_at' => null,
    'decided_by_user_id' => null,
    'reason' => $reason,
    'reject_reason' => '',
    'payload' => $payload,
);
if ($reRequestOf !== '') $record['payload']['re_request_of'] = $reRequestOf;

$store['requests'][] = $record;
$store['logs'][] = array(
    'at' => $now,
    'request_id' => $requestId,
    'action' => 'CREATED',
    'actor_email' => (string)Auth::userEmail(),
    'actor_name' => (string)Auth::userName(),
);

if (!cpms_request_store_save($store)) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'message' => '요청 저장에 실패했습니다.'));
    exit;
}

echo json_encode(array('ok' => true, 'request_id' => $requestId));