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

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$month = isset($_POST['month']) ? trim((string)$_POST['month']) : '';
$workerName = isset($_POST['worker_name']) ? trim((string)$_POST['worker_name']) : '';
$date = isset($_POST['date']) ? trim((string)$_POST['date']) : '';
$newValueRaw = isset($_POST['new_value']) ? trim((string)$_POST['new_value']) : '';
$oldValueRaw = isset($_POST['old_value']) ? trim((string)$_POST['old_value']) : '';

if ($projectId <= 0 || $month === '' || $workerName === '' || $date === '' || $newValueRaw === '' || !is_numeric($newValueRaw)) {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'message' => '요청값이 올바르지 않습니다.'));
    exit;
}

$newValue = (float)$newValueRaw;
$oldValue = is_numeric($oldValueRaw) ? (float)$oldValueRaw : 0.0;
if ($newValue < 0) {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'message' => '공수는 0 이상이어야 합니다.'));
    exit;
}
if ($newValue >= 1.5) {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'message' => '1.5 이상은 수정요청으로 처리하세요.'));
    exit;
}

$role = Auth::userRole();
$dept = Auth::userDepartment();
$email = (string)Auth::userEmail();
if (!($role === 'executive' || $dept === '공사')) {
    http_response_code(403);
    echo json_encode(array('ok' => false, 'message' => '권한이 없습니다.'));
    exit;
}

$pdo = Db::pdo();
if (!cpms_is_project_member_or_executive($pdo, $projectId, $role, $email)) {
    http_response_code(403);
    echo json_encode(array('ok' => false, 'message' => '담당 프로젝트만 수정할 수 있습니다.'));
    exit;
}

$ok = cpms_set_labor_override($projectId, $month, $workerName, $date, $newValue, array(
    'source' => 'DIRECT_EDIT',
    'updated_by_email' => $email,
    'updated_by_name' => (string)Auth::userName(),
    'old_value' => $oldValue,
));

if (!$ok) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'message' => '저장에 실패했습니다.'));
    exit;
}

echo json_encode(array('ok' => true, 'value' => $newValue));