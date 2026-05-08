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
$date = isset($_POST['work_date']) ? trim((string)$_POST['work_date']) : (isset($_POST['date']) ? trim((string)$_POST['date']) : '');
$workerKey = isset($_POST['worker_key']) ? trim((string)$_POST['worker_key']) : '';
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
$role = Auth::userRole();
$email = (string)Auth::userEmail();
if (!Auth::canManageConstruction()) {
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

if (!preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'message' => '날짜 형식 오류입니다. (YYYY-MM-DD)'));
    exit;
}
if ($workerKey === '') $workerKey = function_exists('mb_strtolower') ? mb_strtolower($workerName, 'UTF-8') : strtolower($workerName);
if ($workerKey === '') {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'message' => 'worker_key 누락'));
    exit;
}
if (!cpms_ensure_labor_override_table($pdo)) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'message' => '공수 override 테이블 생성/확인 실패'));
    exit;
}
try {
    $mode = ($newValue >= 1.5) ? 'pending' : 'applied';
    $sql = "INSERT INTO cpms_labor_gongsu_overrides
        (project_id, month, worker_key, worker_name, work_date, old_value, new_value, reason, status, requested_by, created_at, updated_at)
        VALUES (:project_id, :month, :worker_key, :worker_name, :work_date, :old_value, :new_value, :reason, :status, :requested_by, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            worker_name = VALUES(worker_name),
            old_value = VALUES(old_value),
            new_value = VALUES(new_value),
            reason = VALUES(reason),
            status = VALUES(status),
            requested_by = VALUES(requested_by),
            updated_at = NOW()";
    $st = $pdo->prepare($sql);
    $st->bindValue(':project_id', $projectId, PDO::PARAM_INT);
    $st->bindValue(':month', $month, PDO::PARAM_STR);
    $st->bindValue(':worker_key', $workerKey, PDO::PARAM_STR);
    $st->bindValue(':worker_name', $workerName, PDO::PARAM_STR);
    $st->bindValue(':work_date', $date, PDO::PARAM_STR);
    $st->bindValue(':old_value', $oldValue);
    $st->bindValue(':new_value', $newValue);
    $st->bindValue(':reason', isset($_POST['reason']) ? trim((string)$_POST['reason']) : '', PDO::PARAM_STR);
    $st->bindValue(':status', $mode, PDO::PARAM_STR);
    $st->bindValue(':requested_by', (int)Auth::id(), PDO::PARAM_INT);
    $st->execute();
    // 공수 수정 저장 실패 해결: 1.5 미만 즉시 반영은 기존 json override도 동기화
    if ($mode === 'applied') {
        cpms_set_labor_override($projectId, $month, $workerName, $date, $newValue, array('source' => 'DIRECT_EDIT'));
        echo json_encode(array('ok' => true, 'mode' => 'applied', 'message' => '공수가 수정되었습니다.', 'value' => number_format($newValue, 2, '.', '')));
    } else {
        echo json_encode(array('ok' => true, 'mode' => 'pending', 'message' => '1.5 이상 공수는 승인 요청으로 등록되었습니다.'));
    }
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'message' => 'SQL 오류: ' . $e->getMessage()));
    exit;
}