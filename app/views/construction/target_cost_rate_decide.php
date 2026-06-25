<?php
/**
 * 공사 > 상황: 목표원가율 변경 승인/반려
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/partials/target_cost_rate_helper.php';

use App\Core\Auth;
use App\Core\Db;

function cpms_target_cost_rate_decide_redirect($projectId, $year, $fromYm, $toYm) {
    $url = '?r=공사&pid=' . (int)$projectId . '&tab=status';
    if ((int)$year > 0) $url .= '&year=' . (int)$year;
    if ($fromYm !== '') $url .= '&from_ym=' . rawurlencode((string)$fromYm);
    if ($toYm !== '') $url .= '&to_ym=' . rawurlencode((string)$toYm);
    header('Location: ' . $url);
    exit;
}

if (!Auth::check()) { header('Location:?r=login'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$year = isset($_POST['year']) ? (int)$_POST['year'] : 0;
$fromYm = isset($_POST['from_ym']) ? trim((string)$_POST['from_ym']) : '';
$toYm = isset($_POST['to_ym']) ? trim((string)$_POST['to_ym']) : '';

if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    flash_set('error', '보안 토큰이 유효하지 않습니다.');
    cpms_target_cost_rate_decide_redirect($projectId, $year, $fromYm, $toYm);
}

$requestId = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
$decision = isset($_POST['decision']) ? strtolower(trim((string)$_POST['decision'])) : '';
$memo = isset($_POST['memo']) ? trim((string)$_POST['memo']) : '';

if ($requestId <= 0 || $projectId <= 0) {
    flash_set('error', '요청 정보가 올바르지 않습니다.');
    cpms_target_cost_rate_decide_redirect($projectId, $year, $fromYm, $toYm);
}
if ($decision !== 'approve' && $decision !== 'reject') {
    flash_set('error', '처리 유형이 올바르지 않습니다.');
    cpms_target_cost_rate_decide_redirect($projectId, $year, $fromYm, $toYm);
}
if ($decision === 'reject' && $memo === '') {
    flash_set('error', '반려 사유를 입력하세요.');
    cpms_target_cost_rate_decide_redirect($projectId, $year, $fromYm, $toYm);
}

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error', 'DB 연결 실패');
    cpms_target_cost_rate_decide_redirect($projectId, $year, $fromYm, $toYm);
}
cpms_target_cost_rate_ensure_schema($pdo);

if (!cpms_target_cost_rate_is_vp_user($pdo)) {
    flash_set('error', '목표원가율 변경은 부사장 승인 권한이 필요합니다.');
    cpms_target_cost_rate_decide_redirect($projectId, $year, $fromYm, $toYm);
}

$user = Auth::user();
$userId = (is_array($user) && isset($user['id']) && is_numeric($user['id'])) ? (int)$user['id'] : null;
$userName = (is_array($user) && isset($user['name'])) ? trim((string)$user['name']) : '';
$userEmail = method_exists('App\\Core\\Auth', 'userEmail') ? trim((string)Auth::userEmail()) : '';
$employee = cpms_target_cost_rate_user_employee($pdo);
$employeeId = ($employee && isset($employee['id']) && is_numeric((string)$employee['id'])) ? (int)$employee['id'] : $userId;
if ($userName === '' && $employee && isset($employee['name'])) $userName = trim((string)$employee['name']);

try {
    $pdo->beginTransaction();
    $st = $pdo->prepare("SELECT * FROM cpms_project_target_cost_rate_requests WHERE id=:id AND project_id=:pid FOR UPDATE");
    $st->bindValue(':id', $requestId, PDO::PARAM_INT);
    $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $st->execute();
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $pdo->rollBack();
        flash_set('error', '승인 요청을 찾을 수 없습니다.');
        cpms_target_cost_rate_decide_redirect($projectId, $year, $fromYm, $toYm);
    }
    if ((string)$row['status'] !== 'pending') {
        $pdo->rollBack();
        flash_set('error', '이미 처리된 요청입니다.');
        cpms_target_cost_rate_decide_redirect($projectId, $year, $fromYm, $toYm);
    }

    if ($decision === 'reject') {
        $up = $pdo->prepare("UPDATE cpms_project_target_cost_rate_requests
            SET status='rejected', decided_by=:decided_by, decided_by_name=:decided_name, decided_by_email=:decided_email, decided_at=NOW(), decision_memo=:memo, updated_at=NOW()
            WHERE id=:id");
        $up->bindValue(':decided_by', $employeeId, $employeeId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $up->bindValue(':decided_name', $userName !== '' ? $userName : null, $userName !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $up->bindValue(':decided_email', $userEmail !== '' ? $userEmail : null, $userEmail !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $up->bindValue(':memo', $memo !== '' ? $memo : null, $memo !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $up->bindValue(':id', $requestId, PDO::PARAM_INT);
        $up->execute();
        $pdo->commit();
        flash_set('success', '목표원가율 변경 요청을 반려했습니다.');
        cpms_target_cost_rate_decide_redirect($projectId, $year, $fromYm, $toYm);
    }

    $rate = isset($row['new_rate']) ? (float)$row['new_rate'] : 0.0;
    $save = $pdo->prepare("INSERT INTO cpms_project_target_cost_rates
            (project_id, target_rate, created_by, created_by_name, created_at, updated_by, updated_by_name, updated_at)
        VALUES
            (:pid, :rate, :created_by, :created_name, NOW(), :updated_by, :updated_name, NOW())
        ON DUPLICATE KEY UPDATE
            target_rate=VALUES(target_rate),
            updated_by=VALUES(updated_by),
            updated_by_name=VALUES(updated_by_name),
            updated_at=NOW()");
    $save->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $save->bindValue(':rate', $rate);
    $save->bindValue(':created_by', $employeeId, $employeeId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $save->bindValue(':created_name', $userName !== '' ? $userName : null, $userName !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $save->bindValue(':updated_by', $employeeId, $employeeId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $save->bindValue(':updated_name', $userName !== '' ? $userName : null, $userName !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $save->execute();

    $up = $pdo->prepare("UPDATE cpms_project_target_cost_rate_requests
        SET status='approved', decided_by=:decided_by, decided_by_name=:decided_name, decided_by_email=:decided_email, decided_at=NOW(), decision_memo=:memo, updated_at=NOW()
        WHERE id=:id");
    $up->bindValue(':decided_by', $employeeId, $employeeId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $up->bindValue(':decided_name', $userName !== '' ? $userName : null, $userName !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $up->bindValue(':decided_email', $userEmail !== '' ? $userEmail : null, $userEmail !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $up->bindValue(':memo', $memo !== '' ? $memo : null, $memo !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $up->bindValue(':id', $requestId, PDO::PARAM_INT);
    $up->execute();

    $pdo->commit();
    flash_set('success', '목표원가율 변경 요청을 승인했습니다.');
    cpms_target_cost_rate_decide_redirect($projectId, $year, $fromYm, $toYm);
} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    flash_set('error', '처리 실패: ' . $e->getMessage());
    cpms_target_cost_rate_decide_redirect($projectId, $year, $fromYm, $toYm);
}
?>
