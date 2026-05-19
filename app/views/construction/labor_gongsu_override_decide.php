<?php
// 공수 승인 처리 / 공수 반려 처리
require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

function cpms_labor_decide_redirect($type, $message) {
    flash_set($type, $message);
    header('Location: ' . base_url() . '/?r=대시보드&dv=executive');
    exit;
}

function cpms_labor_decide_ensure_columns($pdo) {
    if (!$pdo) return false;
    return cpms_ensure_labor_override_table($pdo);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cpms_labor_decide_redirect('error', '잘못된 요청 방식입니다.');
}

if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    cpms_labor_decide_redirect('error', 'CSRF 검증에 실패했습니다.');
}

$allowed = false;
if (Auth::isMaster()) $allowed = true;
if (Auth::userRole() === 'executive') $allowed = true;
if (Auth::canManageEmployees()) $allowed = true;

if (!$allowed) {
    cpms_labor_decide_redirect('error', '권한이 없습니다.');
}

$overrideId = isset($_POST['override_id']) ? (int)$_POST['override_id'] : 0;
$decision = isset($_POST['decision']) ? strtolower(trim((string)$_POST['decision'])) : '';
$rejectReason = isset($_POST['reject_reason']) ? trim((string)$_POST['reject_reason']) : '';

if ($overrideId <= 0) cpms_labor_decide_redirect('error', '요청 ID가 올바르지 않습니다.');
if ($decision !== 'approve' && $decision !== 'reject') cpms_labor_decide_redirect('error', '처리 유형이 올바르지 않습니다.');
if ($decision === 'reject' && $rejectReason === '') cpms_labor_decide_redirect('error', '반려사유를 입력하세요.');

$pdo = Db::pdo();
if (!$pdo) cpms_labor_decide_redirect('error', 'DB 연결에 실패했습니다.');
if (!cpms_labor_decide_ensure_columns($pdo)) cpms_labor_decide_redirect('error', '테이블 준비에 실패했습니다.');

$user = Auth::user();
$userId = (is_array($user) && isset($user['id']) && is_numeric($user['id'])) ? (int)$user['id'] : null;
$userEmail = method_exists('App\\Core\\Auth', 'userEmail') ? trim((string)Auth::userEmail()) : '';
$userName = (is_array($user) && isset($user['name'])) ? trim((string)$user['name']) : '';
$employee = cpms_labor_find_employee_by_email($pdo, $userEmail);
$employeeId = ($employee && isset($employee['id'])) ? (int)$employee['id'] : 0;
if ($userName === '' && $employee && isset($employee['name'])) $userName = trim((string)$employee['name']);
$isMaster = Auth::isMaster();

try {
    $pdo->beginTransaction();

    $stRow = $pdo->prepare("SELECT * FROM cpms_labor_gongsu_overrides WHERE id = :id FOR UPDATE");
    $stRow->bindValue(':id', $overrideId, PDO::PARAM_INT);
    $stRow->execute();
    $row = $stRow->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $pdo->rollBack();
        cpms_labor_decide_redirect('error', '대상 요청을 찾을 수 없습니다.');
    }

    if ((string)$row['status'] !== 'pending') {
        $pdo->rollBack();
        cpms_labor_decide_redirect('error', '이미 처리된 요청입니다.');
    }

    $currentApproverId = isset($row['current_approver_employee_id']) ? (int)$row['current_approver_employee_id'] : 0;
    $currentApproverEmail = isset($row['current_approver_email']) ? trim((string)$row['current_approver_email']) : '';
    $emailMatches = ($userEmail !== '' && $currentApproverEmail !== '' && strtolower($userEmail) === strtolower($currentApproverEmail));
    $idMatches = ($employeeId > 0 && $currentApproverId > 0 && $employeeId === $currentApproverId);
    if (!$isMaster && !$idMatches && !$emailMatches) {
        $pdo->rollBack();
        cpms_labor_decide_redirect('error', '이 요청을 처리할 권한이 없습니다.');
    }

    $requiredLevel = isset($row['approval_required_level']) && trim((string)$row['approval_required_level']) !== '' ? trim((string)$row['approval_required_level']) : 'DIRECTOR_ONLY';
    $stage = isset($row['approval_stage']) && trim((string)$row['approval_stage']) !== '' ? trim((string)$row['approval_stage']) : 'DIRECTOR_PENDING';
    $actorId = $employeeId > 0 ? $employeeId : $userId;
    $actorName = $userName;
    $actorEmail = $userEmail;

    if ($decision === 'reject') {
        $st = $pdo->prepare("UPDATE cpms_labor_gongsu_overrides
            SET status='rejected', approval_stage='REJECTED', reject_reason=:reject_reason, rejected_by=:uid, rejected_by_name=:uname, rejected_by_email=:uemail, rejected_at=NOW(), updated_at=NOW()
            WHERE id=:id AND status='pending'");
        $st->bindValue(':reject_reason', $rejectReason, PDO::PARAM_STR);
        $st->bindValue(':uid', $actorId, ($actorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT));
        $st->bindValue(':uname', $actorName !== '' ? $actorName : null, $actorName !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $st->bindValue(':uemail', $actorEmail !== '' ? $actorEmail : null, $actorEmail !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $st->bindValue(':id', $overrideId, PDO::PARAM_INT);
        $st->execute();
        $pdo->commit();
        cpms_labor_decide_redirect('success', '공수 수정 요청을 반려했습니다.');
    }

    if ($requiredLevel === 'DIRECTOR_THEN_VP' && $stage === 'DIRECTOR_PENDING') {
        $vp = cpms_labor_find_vp_approver($pdo);
        if (!$vp) {
            $pdo->rollBack();
            cpms_labor_decide_redirect('error', '부사장 승인자를 직원명부에서 찾을 수 없습니다.');
        }
        $st = $pdo->prepare("UPDATE cpms_labor_gongsu_overrides
            SET status='pending', approval_stage='VP_PENDING', first_approver_employee_id=:first_id, first_approver_name=:first_name, first_approver_email=:first_email, first_approved_at=NOW(), current_approver_employee_id=:vp_id, current_approver_name=:vp_name, current_approver_email=:vp_email, updated_at=NOW()
            WHERE id=:id AND status='pending'");
        $st->bindValue(':first_id', $actorId, ($actorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT));
        $st->bindValue(':first_name', $actorName !== '' ? $actorName : null, $actorName !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $st->bindValue(':first_email', $actorEmail !== '' ? $actorEmail : null, $actorEmail !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $st->bindValue(':vp_id', (int)$vp['id'], PDO::PARAM_INT);
        $st->bindValue(':vp_name', isset($vp['name']) ? (string)$vp['name'] : '', PDO::PARAM_STR);
        $st->bindValue(':vp_email', isset($vp['email']) ? (string)$vp['email'] : '', PDO::PARAM_STR);
        $st->bindValue(':id', $overrideId, PDO::PARAM_INT);
        $st->execute();
        $pdo->commit();
        cpms_labor_send_override_notification($pdo, $overrideId, 'VP_REQUEST');
        cpms_labor_decide_redirect('success', '1차 승인 완료 후 부사장 2차 승인 요청으로 전달했습니다.');
    }

    if ($requiredLevel === 'DIRECTOR_THEN_VP' && $stage === 'VP_PENDING') {
        $st = $pdo->prepare("UPDATE cpms_labor_gongsu_overrides
            SET status='applied', approval_stage='COMPLETED', second_approver_employee_id=:second_id, second_approver_name=:second_name, second_approver_email=:second_email, second_approved_at=NOW(), final_approved_at=NOW(), approved_by=:uid, approved_at=NOW(), current_approver_employee_id=NULL, current_approver_name=NULL, current_approver_email=NULL, updated_at=NOW()
            WHERE id=:id AND status='pending'");
        $st->bindValue(':second_id', $actorId, ($actorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT));
        $st->bindValue(':second_name', $actorName !== '' ? $actorName : null, $actorName !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $st->bindValue(':second_email', $actorEmail !== '' ? $actorEmail : null, $actorEmail !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $st->bindValue(':uid', $actorId, ($actorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT));
        $st->bindValue(':id', $overrideId, PDO::PARAM_INT);
        $st->execute();
        $pdo->commit();
        cpms_labor_decide_redirect('success', '공수 수정 요청을 최종 승인했습니다.');
    }

    $st = $pdo->prepare("UPDATE cpms_labor_gongsu_overrides
        SET status='applied', approval_stage='COMPLETED', first_approver_employee_id=:first_id, first_approver_name=:first_name, first_approver_email=:first_email, first_approved_at=NOW(), final_approved_at=NOW(), approved_by=:uid, approved_at=NOW(), current_approver_employee_id=NULL, current_approver_name=NULL, current_approver_email=NULL, updated_at=NOW()
        WHERE id=:id AND status='pending'");
    $st->bindValue(':first_id', $actorId, ($actorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT));
    $st->bindValue(':first_name', $actorName !== '' ? $actorName : null, $actorName !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $st->bindValue(':first_email', $actorEmail !== '' ? $actorEmail : null, $actorEmail !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $st->bindValue(':uid', $actorId, ($actorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT));
    $st->bindValue(':id', $overrideId, PDO::PARAM_INT);
    $st->execute();
    $pdo->commit();
    cpms_labor_decide_redirect('success', '공수 수정 요청을 승인했습니다.');
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    cpms_labor_decide_redirect('error', '처리 실패: ' . $e->getMessage());
}