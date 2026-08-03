<?php
// 공수 승인 처리 / 공수 반려 처리
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../services/CostDataEventService.php';

use App\Core\Auth;
use App\Core\Db;
use App\Services\CostDataEventService;

function cpms_labor_decide_redirect($type, $message) {
    flash_set($type, $message);
    header('Location: ' . base_url() . '/?r=dashboard_executive&exec_tab=approval');
    exit;
}

function cpms_labor_decide_ensure_columns($pdo) {
    if (!$pdo) return false;
    return cpms_ensure_labor_override_table($pdo);
}

function cpms_labor_record_applied_events($pdo, $rows, $batchToken) {
    if (!is_array($rows)) return;
    foreach ($rows as $eventRow) {
        $eventId = isset($eventRow['id']) ? (int)$eventRow['id'] : 0;
        if ($eventId <= 0) continue;
        $projectId = isset($eventRow['project_id']) ? (int)$eventRow['project_id'] : 0;
        $workDate = isset($eventRow['work_date']) ? (string)$eventRow['work_date'] : '';
        $month = isset($eventRow['month']) ? (string)$eventRow['month'] : '';
        $oldValue = isset($eventRow['old_value']) ? $eventRow['old_value'] : null;
        $newValue = isset($eventRow['new_value']) ? $eventRow['new_value'] : null;
        CostDataEventService::recordChange($pdo, array(
            'project_id' => $projectId,
            'cost_type' => 'labor',
            'target_type' => 'labor_gongsu_override',
            'target_id' => (string)$eventId,
            'event_action' => 'ADJUST',
            'source_type' => 'APPROVAL',
            'batch_key' => trim((string)$batchToken) !== '' ? 'labor_gongsu:' . trim((string)$batchToken) : null,
            'actual_date' => $workDate,
            'settlement_ym' => $month,
            'old_amount' => null,
            'new_amount' => null,
            'old_data' => array('project_id'=>$projectId, 'month'=>$month, 'use_date'=>$workDate, 'work_unit'=>$oldValue, 'is_deleted_entry'=>0),
            'new_data' => array('project_id'=>$projectId, 'month'=>$month, 'use_date'=>$workDate, 'work_unit'=>$newValue, 'is_deleted_entry'=>isset($eventRow['is_deleted_entry']) ? (int)$eventRow['is_deleted_entry'] : 0),
            'reason' => isset($eventRow['reason']) ? $eventRow['reason'] : '',
            'dedupe_key' => 'labor_gongsu:' . $eventId,
            'source_file' => __FILE__,
        ));
    }
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

    // 파일: app/views/construction/labor_gongsu_override_decide.php
    // 대표 요청에 batch_token이 있으면 같은 묶음의 승인대기 행을 모두 잠그고 한 번에 처리합니다.
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

    $batchToken = isset($row['batch_token']) ? trim((string)$row['batch_token']) : '';
    $targetRows = array($row);
    if ($batchToken !== '') {
        $stBatchRows = $pdo->prepare("SELECT * FROM cpms_labor_gongsu_overrides WHERE project_id=:project_id AND month=:month AND batch_token=:batch_token AND status='pending' ORDER BY id ASC FOR UPDATE");
        $stBatchRows->execute(array(
            ':project_id'=>(int)$row['project_id'],
            ':month'=>(string)$row['month'],
            ':batch_token'=>$batchToken
        ));
        $loadedTargetRows = $stBatchRows->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($loadedTargetRows) && count($loadedTargetRows) > 0) $targetRows = $loadedTargetRows;
    }

    $requiredLevel = isset($row['approval_required_level']) && trim((string)$row['approval_required_level']) !== '' ? trim((string)$row['approval_required_level']) : 'DIRECTOR_ONLY';
    $stage = isset($row['approval_stage']) && trim((string)$row['approval_stage']) !== '' ? trim((string)$row['approval_stage']) : 'DIRECTOR_PENDING';
    foreach ($targetRows as $targetRow) {
        $targetRequiredLevel = isset($targetRow['approval_required_level']) && trim((string)$targetRow['approval_required_level']) !== '' ? trim((string)$targetRow['approval_required_level']) : 'DIRECTOR_ONLY';
        $targetStage = isset($targetRow['approval_stage']) && trim((string)$targetRow['approval_stage']) !== '' ? trim((string)$targetRow['approval_stage']) : 'DIRECTOR_PENDING';
        if ($targetRequiredLevel !== $requiredLevel || $targetStage !== $stage) {
            $pdo->rollBack();
            cpms_labor_decide_redirect('error', '일괄 요청의 승인 단계가 일치하지 않아 처리할 수 없습니다.');
        }
        $currentApproverId = isset($targetRow['current_approver_employee_id']) ? (int)$targetRow['current_approver_employee_id'] : 0;
        $currentApproverEmail = isset($targetRow['current_approver_email']) ? trim((string)$targetRow['current_approver_email']) : '';
        $emailMatches = ($userEmail !== '' && $currentApproverEmail !== '' && strtolower($userEmail) === strtolower($currentApproverEmail));
        $idMatches = ($employeeId > 0 && $currentApproverId > 0 && $employeeId === $currentApproverId);
        if (!$isMaster && !$idMatches && !$emailMatches) {
            $pdo->rollBack();
            cpms_labor_decide_redirect('error', '이 요청을 처리할 권한이 없습니다.');
        }
    }

    $actorId = $employeeId > 0 ? $employeeId : $userId;
    $actorName = $userName;
    $actorEmail = $userEmail;
    $targetCount = count($targetRows);

    if ($decision === 'reject') {
        $st = $pdo->prepare("UPDATE cpms_labor_gongsu_overrides
            SET status='rejected', approval_stage='REJECTED', reject_reason=:reject_reason, rejected_by=:uid, rejected_by_name=:uname, rejected_by_email=:uemail, rejected_at=NOW(), rejected_acknowledged_at=NULL, rejected_acknowledged_by=NULL, updated_at=NOW()
            WHERE id=:id AND status='pending'");
        foreach ($targetRows as $targetRow) {
            $st->bindValue(':reject_reason', $rejectReason, PDO::PARAM_STR);
            $st->bindValue(':uid', $actorId, ($actorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT));
            $st->bindValue(':uname', $actorName !== '' ? $actorName : null, $actorName !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $st->bindValue(':uemail', $actorEmail !== '' ? $actorEmail : null, $actorEmail !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $st->bindValue(':id', (int)$targetRow['id'], PDO::PARAM_INT);
            $st->execute();
        }
        $pdo->commit();
        cpms_labor_decide_redirect('success', $targetCount > 1 ? $targetCount . '명의 공수 일괄 요청을 반려했습니다.' : '공수 수정 요청을 반려했습니다.');
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
        foreach ($targetRows as $targetRow) {
            $st->bindValue(':first_id', $actorId, ($actorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT));
            $st->bindValue(':first_name', $actorName !== '' ? $actorName : null, $actorName !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $st->bindValue(':first_email', $actorEmail !== '' ? $actorEmail : null, $actorEmail !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $st->bindValue(':vp_id', (int)$vp['id'], PDO::PARAM_INT);
            $st->bindValue(':vp_name', isset($vp['name']) ? (string)$vp['name'] : '', PDO::PARAM_STR);
            $st->bindValue(':vp_email', isset($vp['email']) ? (string)$vp['email'] : '', PDO::PARAM_STR);
            $st->bindValue(':id', (int)$targetRow['id'], PDO::PARAM_INT);
            $st->execute();
        }
        $pdo->commit();
        cpms_labor_send_override_notification($pdo, $overrideId, 'VP_REQUEST');
        cpms_labor_decide_redirect('success', ($targetCount > 1 ? $targetCount . '명의 일괄 요청을 1차 승인하고 ' : '1차 승인 완료 후 ') . '부사장 2차 승인 요청으로 전달했습니다.');
    }

    if ($requiredLevel === 'DIRECTOR_THEN_VP' && $stage === 'VP_PENDING') {
        $st = $pdo->prepare("UPDATE cpms_labor_gongsu_overrides
            SET status='applied', approval_stage='COMPLETED', second_approver_employee_id=:second_id, second_approver_name=:second_name, second_approver_email=:second_email, second_approved_at=NOW(), final_approved_at=NOW(), approved_by=:uid, approved_at=NOW(), current_approver_employee_id=NULL, current_approver_name=NULL, current_approver_email=NULL, updated_at=NOW()
            WHERE id=:id AND status='pending'");
        foreach ($targetRows as $targetRow) {
            $st->bindValue(':second_id', $actorId, ($actorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT));
            $st->bindValue(':second_name', $actorName !== '' ? $actorName : null, $actorName !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $st->bindValue(':second_email', $actorEmail !== '' ? $actorEmail : null, $actorEmail !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $st->bindValue(':uid', $actorId, ($actorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT));
            $st->bindValue(':id', (int)$targetRow['id'], PDO::PARAM_INT);
            $st->execute();
        }
        $pdo->commit();
        cpms_labor_record_applied_events($pdo, $targetRows, $batchToken);
        cpms_labor_decide_redirect('success', $targetCount > 1 ? $targetCount . '명의 공수 일괄 요청을 최종 승인했습니다.' : '공수 수정 요청을 최종 승인했습니다.');
    }

    $st = $pdo->prepare("UPDATE cpms_labor_gongsu_overrides
        SET status='applied', approval_stage='COMPLETED', first_approver_employee_id=:first_id, first_approver_name=:first_name, first_approver_email=:first_email, first_approved_at=NOW(), final_approved_at=NOW(), approved_by=:uid, approved_at=NOW(), current_approver_employee_id=NULL, current_approver_name=NULL, current_approver_email=NULL, updated_at=NOW()
        WHERE id=:id AND status='pending'");
    foreach ($targetRows as $targetRow) {
        $st->bindValue(':first_id', $actorId, ($actorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT));
        $st->bindValue(':first_name', $actorName !== '' ? $actorName : null, $actorName !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $st->bindValue(':first_email', $actorEmail !== '' ? $actorEmail : null, $actorEmail !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $st->bindValue(':uid', $actorId, ($actorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT));
        $st->bindValue(':id', (int)$targetRow['id'], PDO::PARAM_INT);
        $st->execute();
    }
    $pdo->commit();
    cpms_labor_record_applied_events($pdo, $targetRows, $batchToken);
    cpms_labor_decide_redirect('success', $targetCount > 1 ? $targetCount . '명의 공수 일괄 요청을 승인했습니다.' : '공수 수정 요청을 승인했습니다.');
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    cpms_labor_decide_redirect('error', '처리 실패: ' . $e->getMessage());
}
