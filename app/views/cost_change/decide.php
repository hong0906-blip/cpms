<?php
/**
 * 비용 변경 1차/최종 승인 및 반려 처리.
 * 행 잠금과 상태 조건으로 중복 클릭/재처리를 차단한다.
 * PHP 5.6 호환.
 */

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../services/CostDataEventService.php';
cpms_cost_change_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    cpms_cost_change_redirect('error', '보안 토큰이 올바르지 않습니다.', '?r=cost_change/approvals');
}
$pdo = Db::pdo();
cpms_cost_change_require_installed($pdo);
$requestId = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
$decision = isset($_POST['decision']) ? strtolower(trim((string)$_POST['decision'])) : '';
$opinion = isset($_POST['opinion']) ? trim((string)$_POST['opinion']) : '';
if ($requestId <= 0 || !in_array($decision, array('approve','reject'), true)) {
    cpms_cost_change_redirect('error', '처리값이 올바르지 않습니다.', '?r=cost_change/approvals');
}
if ($decision === 'reject' && $opinion === '') {
    cpms_cost_change_redirect('error', '반려 사유는 필수입니다.', '?r=cost_change/detail&id=' . $requestId);
}

$actorId = CostChangeService::employeeId();
$actorName = (string)Auth::userName();
$actorEmail = (string)Auth::userEmail();
$notifyType = '';
$extraNotifyType = '';
$notifyReceiver = 0;
$resultMessage = '';
$safetyBackupPath = '';
$safetyBackup = null;

try {
    $pdo->beginTransaction();
    $st = $pdo->prepare("SELECT * FROM cpms_cost_change_requests WHERE id=:id FOR UPDATE");
    $st->bindValue(':id', $requestId, PDO::PARAM_INT);
    $st->execute();
    $request = $st->fetch(PDO::FETCH_ASSOC);
    if (!$request) throw new Exception('요청을 찾을 수 없습니다.');
    if (!CostChangeService::canActRequest($request)) throw new Exception('현재 승인단계를 처리할 권한이 없습니다.');
    $status = (string)$request['status'];
    $stage = $status === CostChangeService::STATUS_FIRST_PENDING ? 'FIRST' : 'FINAL';

    if ($decision === 'reject') {
        if ($stage === 'FIRST') {
            $sql = "UPDATE cpms_cost_change_requests SET
                status=:status,current_stage='REJECTED',current_approver_employee_id=NULL,active_target_key=NULL,
                first_result='REJECTED',first_opinion=:opinion,first_acted_at=NOW(),
                rejected_by_employee_id=:actor_id,rejected_by_name=:actor_name,rejected_stage='FIRST',
                rejected_reason=:opinion,rejected_at=NOW(),updated_at=NOW()
                WHERE id=:id AND status=:expected_status";
        } else {
            $sql = "UPDATE cpms_cost_change_requests SET
                status=:status,current_stage='REJECTED',current_approver_employee_id=NULL,active_target_key=NULL,
                final_result='REJECTED',final_opinion=:opinion,final_acted_at=NOW(),
                rejected_by_employee_id=:actor_id,rejected_by_name=:actor_name,rejected_stage='FINAL',
                rejected_reason=:opinion,rejected_at=NOW(),updated_at=NOW()
                WHERE id=:id AND status=:expected_status";
        }
        $up = $pdo->prepare($sql);
        $up->execute(array(
            ':status'=>CostChangeService::STATUS_REJECTED,
            ':opinion'=>$opinion,
            ':actor_id'=>$actorId,
            ':actor_name'=>$actorName,
            ':id'=>$requestId,
            ':expected_status'=>$status
        ));
        if ($up->rowCount() !== 1) throw new Exception('이미 처리된 요청입니다.');
        CostChangeService::logEvent($pdo, $requestId, 'REJECTED', $stage, $opinion, array('stage'=>$stage));
        $pdo->commit();
        $notifyType = $stage === 'FIRST' ? 'FIRST_REJECTED' : 'FINAL_REJECTED';
        $notifyReceiver = (int)$request['requester_employee_id'];
        $resultMessage = $stage === 'FIRST' ? '1차 승인 단계에서 반려했습니다.' : '최종 승인 단계에서 반려했습니다.';
    } else if ($stage === 'FIRST') {
        $up = $pdo->prepare("UPDATE cpms_cost_change_requests SET
            status=:next_status,current_stage='FINAL',current_approver_employee_id=final_approver_employee_id,
            first_result='APPROVED',first_opinion=:opinion,first_acted_at=NOW(),updated_at=NOW()
            WHERE id=:id AND status=:expected_status AND current_approver_employee_id=:actor_id");
        $up->execute(array(
            ':next_status'=>CostChangeService::STATUS_FINAL_PENDING,
            ':opinion'=>$opinion,
            ':id'=>$requestId,
            ':expected_status'=>CostChangeService::STATUS_FIRST_PENDING,
            ':actor_id'=>$actorId
        ));
        if ($up->rowCount() !== 1) throw new Exception('이미 처리됐거나 승인 권한이 없는 요청입니다.');
        CostChangeService::logEvent($pdo, $requestId, 'FIRST_APPROVED', 'FIRST', $opinion, array());
        $pdo->commit();
        $notifyType = 'FINAL_REQUEST';
        $notifyReceiver = (int)$request['final_approver_employee_id'];
        $resultMessage = '1차 승인 후 부사장 최종 승인 대기로 전달했습니다.';
    } else {
        if ((string)$request['target_type'] === 'safety') {
            $safetyHelper = __DIR__ . '/../safety/safety_cost_helper.php';
            if (is_file($safetyHelper)) require_once $safetyHelper;
            if (function_exists('cpms_safety_cost_store_path')) {
                $safetyBackupPath = cpms_safety_cost_store_path();
                $safetyBackup = is_file($safetyBackupPath) ? @file_get_contents($safetyBackupPath) : null;
            }
        }
        $applyResult = CostChangeService::applyRequest($pdo, $request);
        $up = $pdo->prepare("UPDATE cpms_cost_change_requests SET
            status=:completed_status,current_stage='COMPLETED',current_approver_employee_id=NULL,active_target_key=NULL,
            target_id=:target_id,final_result='APPROVED',final_opinion=:opinion,final_acted_at=NOW(),
            apply_result=:apply_result,applied_at=NOW(),apply_error=NULL,updated_at=NOW()
            WHERE id=:id AND status=:expected_status AND current_approver_employee_id=:actor_id");
        $up->execute(array(
            ':completed_status'=>CostChangeService::STATUS_COMPLETED,
            ':target_id'=>(string)$applyResult['target_id'],
            ':opinion'=>$opinion,
            ':apply_result'=>CostChangeService::jsonEncode($applyResult),
            ':id'=>$requestId,
            ':expected_status'=>CostChangeService::STATUS_FINAL_PENDING,
            ':actor_id'=>$actorId
        ));
        if ($up->rowCount() !== 1) throw new Exception('이미 처리됐거나 승인 권한이 없는 요청입니다.');
        CostChangeService::logEvent($pdo, $requestId, 'FINAL_APPROVED', 'FINAL', $opinion, array());
        CostChangeService::logEvent($pdo, $requestId, 'APPLY_COMPLETED', 'SYSTEM', '승인 요청서 내용으로 자동 반영 완료', $applyResult);
        $pdo->commit();
        CostChangeService::recordAppliedCostDataEvent($pdo, $request, $applyResult, __FILE__);
        $notifyType = 'APPLY_COMPLETED';
        $extraNotifyType = 'FINAL_APPROVED';
        $notifyReceiver = (int)$request['requester_employee_id'];
        $resultMessage = '최종 승인과 한 건 자동 반영을 완료했습니다.';
    }

    $savedRequest = CostChangeService::requestById($pdo, $requestId);
    if ($notifyReceiver > 0) {
        if ($extraNotifyType !== '') CostChangeService::notify($pdo, $savedRequest, $extraNotifyType, $notifyReceiver);
        CostChangeService::notify($pdo, $savedRequest, $notifyType, $notifyReceiver);
    }
    cpms_cost_change_redirect('success', $resultMessage, '?r=cost_change/detail&id=' . $requestId);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($safetyBackupPath !== '' && $safetyBackup !== null) {
        @file_put_contents($safetyBackupPath, $safetyBackup, LOCK_EX);
    }

    $request = CostChangeService::requestById($pdo, $requestId);
    $isFinalApplyFailure = is_array($request)
        && (string)$request['status'] === CostChangeService::STATUS_FINAL_PENDING
        && $decision === 'approve'
        && CostChangeService::canActRequest($request);
    if ($isFinalApplyFailure) {
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare("SELECT * FROM cpms_cost_change_requests WHERE id=:id AND status=:status FOR UPDATE");
            $st->execute(array(':id'=>$requestId, ':status'=>CostChangeService::STATUS_FINAL_PENDING));
            $locked = $st->fetch(PDO::FETCH_ASSOC);
            if ($locked && CostChangeService::canActRequest($locked)) {
                $up = $pdo->prepare("UPDATE cpms_cost_change_requests SET
                    status=:failed_status,current_stage='FAILED',current_approver_employee_id=NULL,active_target_key=NULL,
                    final_result='APPROVED',final_opinion=:opinion,final_acted_at=NOW(),
                    apply_error=:apply_error,updated_at=NOW()
                    WHERE id=:id AND status=:expected_status");
                $up->execute(array(
                    ':failed_status'=>CostChangeService::STATUS_FAILED,
                    ':opinion'=>$opinion,
                    ':apply_error'=>$e->getMessage(),
                    ':id'=>$requestId,
                    ':expected_status'=>CostChangeService::STATUS_FINAL_PENDING
                ));
                CostChangeService::logEvent($pdo, $requestId, 'APPLY_FAILED', 'SYSTEM', $e->getMessage(), array());
                $pdo->commit();
                $failedRequest = CostChangeService::requestById($pdo, $requestId);
                if ((int)$failedRequest['requester_employee_id'] > 0) {
                    CostChangeService::notify($pdo, $failedRequest, 'FINAL_APPROVED', (int)$failedRequest['requester_employee_id']);
                    CostChangeService::notify($pdo, $failedRequest, 'APPLY_FAILED', (int)$failedRequest['requester_employee_id']);
                }
                try {
                    $admins = $pdo->query("SELECT id FROM employees WHERE is_active=1 AND (role='executive' OR department LIKE '관리%') ORDER BY id ASC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($admins as $admin) {
                        $adminId = isset($admin['id']) ? (int)$admin['id'] : 0;
                        if ($adminId > 0 && $adminId !== (int)$failedRequest['requester_employee_id']) CostChangeService::notify($pdo, $failedRequest, 'APPLY_FAILED_ADMIN', $adminId);
                    }
                } catch (Exception $notifyError) {
                }
                cpms_cost_change_redirect('error', '최종 승인은 기록했으나 자동 반영에 실패했습니다. 오류: ' . $e->getMessage(), '?r=cost_change/detail&id=' . $requestId);
            }
            if ($pdo->inTransaction()) $pdo->rollBack();
        } catch (Exception $failureLogError) {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
    }
    cpms_cost_change_redirect('error', '승인 처리 실패: ' . $e->getMessage(), '?r=cost_change/detail&id=' . $requestId);
}
