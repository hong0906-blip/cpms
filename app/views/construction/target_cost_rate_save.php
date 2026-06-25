<?php
/**
 * 공사 > 상황: 목표원가율 저장/변경승인 요청
 * - 최초 입력은 공사관리자가 바로 저장
 * - 이후 변경은 부사장 승인 요청으로 처리
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/partials/target_cost_rate_helper.php';

use App\Core\Auth;
use App\Core\Db;

function cpms_target_cost_rate_redirect($projectId, $year, $fromYm, $toYm) {
    $url = '?r=공사&pid=' . (int)$projectId . '&tab=status';
    if ((int)$year > 0) $url .= '&year=' . (int)$year;
    if ($fromYm !== '') $url .= '&from_ym=' . rawurlencode((string)$fromYm);
    if ($toYm !== '') $url .= '&to_ym=' . rawurlencode((string)$toYm);
    header('Location: ' . $url);
    exit;
}

if (!Auth::check()) { header('Location:?r=login'); exit; }
if (!Auth::canManageConstruction()) { http_response_code(403); echo '403 Forbidden'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    flash_set('error', '보안 토큰이 유효하지 않습니다.');
    cpms_target_cost_rate_redirect(isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0, 0, '', '');
}

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$year = isset($_POST['year']) ? (int)$_POST['year'] : 0;
$fromYm = isset($_POST['from_ym']) ? trim((string)$_POST['from_ym']) : '';
$toYm = isset($_POST['to_ym']) ? trim((string)$_POST['to_ym']) : '';
$rateRaw = isset($_POST['target_rate']) ? trim((string)$_POST['target_rate']) : '';
$reason = isset($_POST['reason']) ? trim((string)$_POST['reason']) : '';

if ($projectId <= 0) {
    flash_set('error', '프로젝트 정보가 올바르지 않습니다.');
    cpms_target_cost_rate_redirect($projectId, $year, $fromYm, $toYm);
}

$newRate = cpms_target_cost_rate_parse($rateRaw);
if ($newRate === null || $newRate <= 0) {
    flash_set('error', '목표원가율은 0보다 큰 숫자로 입력하세요.');
    cpms_target_cost_rate_redirect($projectId, $year, $fromYm, $toYm);
}

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error', 'DB 연결 실패');
    cpms_target_cost_rate_redirect($projectId, $year, $fromYm, $toYm);
}
cpms_target_cost_rate_ensure_schema($pdo);

$user = Auth::user();
$userId = (is_array($user) && isset($user['id']) && is_numeric($user['id'])) ? (int)$user['id'] : null;
$userName = (is_array($user) && isset($user['name'])) ? trim((string)$user['name']) : '';
$userEmail = method_exists('App\\Core\\Auth', 'userEmail') ? trim((string)Auth::userEmail()) : '';
$employee = cpms_target_cost_rate_user_employee($pdo);
$employeeId = ($employee && isset($employee['id']) && is_numeric((string)$employee['id'])) ? (int)$employee['id'] : $userId;
if ($userName === '' && $employee && isset($employee['name'])) $userName = trim((string)$employee['name']);

try {
    $pdo->beginTransaction();

    $st = $pdo->prepare("SELECT * FROM cpms_project_target_cost_rates WHERE project_id=:pid FOR UPDATE");
    $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $st->execute();
    $current = $st->fetch(PDO::FETCH_ASSOC);
    $oldRate = ($current && isset($current['target_rate'])) ? (float)$current['target_rate'] : 0.0;

    if (abs($oldRate - (float)$newRate) < 0.001) {
        $pdo->commit();
        flash_set('success', '목표원가율 변경사항이 없습니다.');
        cpms_target_cost_rate_redirect($projectId, $year, $fromYm, $toYm);
    }

    if (!$current || $oldRate <= 0) {
        $sql = "INSERT INTO cpms_project_target_cost_rates
                    (project_id, target_rate, created_by, created_by_name, created_at, updated_by, updated_by_name, updated_at)
                VALUES
                    (:pid, :rate, :created_by, :created_by_name, NOW(), :updated_by, :updated_by_name, NOW())
                ON DUPLICATE KEY UPDATE
                    target_rate=VALUES(target_rate),
                    updated_by=VALUES(updated_by),
                    updated_by_name=VALUES(updated_by_name),
                    updated_at=NOW()";
        $up = $pdo->prepare($sql);
        $up->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $up->bindValue(':rate', (float)$newRate);
        $up->bindValue(':created_by', $employeeId, $employeeId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $up->bindValue(':created_by_name', $userName !== '' ? $userName : null, $userName !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $up->bindValue(':updated_by', $employeeId, $employeeId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $up->bindValue(':updated_by_name', $userName !== '' ? $userName : null, $userName !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $up->execute();
        $pdo->commit();
        flash_set('success', '목표원가율을 저장했습니다.');
        cpms_target_cost_rate_redirect($projectId, $year, $fromYm, $toYm);
    }

    $pending = $pdo->prepare("SELECT id FROM cpms_project_target_cost_rate_requests WHERE project_id=:pid AND status='pending' LIMIT 1");
    $pending->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $pending->execute();
    if ($pending->fetch()) {
        $pdo->commit();
        flash_set('error', '이미 승인대기 중인 목표원가율 변경 요청이 있습니다.');
        cpms_target_cost_rate_redirect($projectId, $year, $fromYm, $toYm);
    }

    if ($reason === '') {
        $pdo->rollBack();
        flash_set('error', '목표원가율 변경은 승인 요청 사유를 입력해야 합니다.');
        cpms_target_cost_rate_redirect($projectId, $year, $fromYm, $toYm);
    }

    $vp = cpms_target_cost_rate_vp_approver($pdo);
    if (!$vp) {
        $pdo->rollBack();
        flash_set('error', '부사장 승인자를 직원명부에서 찾을 수 없습니다.');
        cpms_target_cost_rate_redirect($projectId, $year, $fromYm, $toYm);
    }

    $ins = $pdo->prepare("INSERT INTO cpms_project_target_cost_rate_requests
        (project_id, old_rate, new_rate, reason, status, requested_by, requested_by_name, requested_by_email, approver_employee_id, approver_name, approver_email, created_at, updated_at)
        VALUES
        (:pid, :old_rate, :new_rate, :reason, 'pending', :requested_by, :requested_name, :requested_email, :approver_id, :approver_name, :approver_email, NOW(), NOW())");
    $ins->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $ins->bindValue(':old_rate', $oldRate);
    $ins->bindValue(':new_rate', (float)$newRate);
    $ins->bindValue(':reason', $reason);
    $ins->bindValue(':requested_by', $employeeId, $employeeId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $ins->bindValue(':requested_name', $userName !== '' ? $userName : null, $userName !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $ins->bindValue(':requested_email', $userEmail !== '' ? $userEmail : null, $userEmail !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $ins->bindValue(':approver_id', isset($vp['id']) ? (int)$vp['id'] : null, isset($vp['id']) ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $ins->bindValue(':approver_name', isset($vp['name']) ? (string)$vp['name'] : '');
    $ins->bindValue(':approver_email', isset($vp['email']) ? (string)$vp['email'] : '');
    $ins->execute();
    $requestId = (int)$pdo->lastInsertId();

    $pdo->commit();
    cpms_target_cost_rate_send_notification($pdo, $requestId);
    flash_set('success', '목표원가율 변경 승인 요청을 올렸습니다.');
    cpms_target_cost_rate_redirect($projectId, $year, $fromYm, $toYm);
} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    flash_set('error', '저장 실패: ' . $e->getMessage());
    cpms_target_cost_rate_redirect($projectId, $year, $fromYm, $toYm);
}
?>
