<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/partials/equipment_gongsu_approval_helper.php';
require_once __DIR__ . '/../../services/CostChangeService.php';
require_once __DIR__ . '/../../services/CostDataEventService.php';

use App\Core\Auth;
use App\Core\Db;
use App\Services\CostChangeService;
use App\Services\CostDataEventService;

function cpms_equipment_gongsu_decide_redirect($type, $message) {
    flash_set($type, $message);
    header('Location: ' . base_url() . '/?r=dashboard_executive&exec_tab=approval');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') cpms_equipment_gongsu_decide_redirect('error', '잘못된 요청 방식입니다.');
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) cpms_equipment_gongsu_decide_redirect('error', 'CSRF 검증에 실패했습니다.');
if (!Auth::check()) cpms_equipment_gongsu_decide_redirect('error', '로그인이 필요합니다.');

$allowed = Auth::isMaster() || Auth::userRole() === 'executive' || Auth::canManageEmployees();
if (!$allowed) cpms_equipment_gongsu_decide_redirect('error', '권한이 없습니다.');

$overrideId = isset($_POST['override_id']) ? (int)$_POST['override_id'] : 0;
$decision = isset($_POST['decision']) ? strtolower(trim((string)$_POST['decision'])) : '';
$rejectReason = isset($_POST['reject_reason']) ? trim((string)$_POST['reject_reason']) : '';
if ($overrideId <= 0) cpms_equipment_gongsu_decide_redirect('error', '요청 ID가 올바르지 않습니다.');
if ($decision !== 'approve' && $decision !== 'reject') cpms_equipment_gongsu_decide_redirect('error', '처리 유형이 올바르지 않습니다.');
if ($decision === 'reject' && $rejectReason === '') cpms_equipment_gongsu_decide_redirect('error', '반려사유를 입력하세요.');

$pdo = Db::pdo();
if (!$pdo) cpms_equipment_gongsu_decide_redirect('error', 'DB 연결 실패');
cpms_equipment_gongsu_ensure_schema($pdo);

$user = Auth::user();
$userId = (is_array($user) && isset($user['id']) && is_numeric($user['id'])) ? (int)$user['id'] : null;
$userEmail = method_exists('App\\Core\\Auth', 'userEmail') ? trim((string)Auth::userEmail()) : '';
$userName = (is_array($user) && isset($user['name'])) ? trim((string)$user['name']) : '';
$employee = function_exists('cpms_labor_find_employee_by_email') ? cpms_labor_find_employee_by_email($pdo, $userEmail) : null;
$employeeId = ($employee && isset($employee['id'])) ? (int)$employee['id'] : 0;
if ($userName === '' && $employee && isset($employee['name'])) $userName = trim((string)$employee['name']);

try {
    $pdo->beginTransaction();
    $st = $pdo->prepare("SELECT * FROM cpms_equipment_gongsu_overrides WHERE id=:id FOR UPDATE");
    $st->execute(array(':id'=>$overrideId));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) { $pdo->rollBack(); cpms_equipment_gongsu_decide_redirect('error', '요청을 찾을 수 없습니다.'); }
    if ((string)$row['status'] !== 'pending') { $pdo->rollBack(); cpms_equipment_gongsu_decide_redirect('error', '이미 처리된 요청입니다.'); }

    $currentApproverId = isset($row['current_approver_employee_id']) ? (int)$row['current_approver_employee_id'] : 0;
    $currentApproverEmail = isset($row['current_approver_email']) ? trim((string)$row['current_approver_email']) : '';
    $emailMatches = ($userEmail !== '' && $currentApproverEmail !== '' && strtolower($userEmail) === strtolower($currentApproverEmail));
    $idMatches = ($employeeId > 0 && $currentApproverId > 0 && $employeeId === $currentApproverId);
    if (!Auth::isMaster() && !$idMatches && !$emailMatches) {
        $pdo->rollBack();
        cpms_equipment_gongsu_decide_redirect('error', '이 요청을 처리할 권한이 없습니다.');
    }

    $actorId = $employeeId > 0 ? $employeeId : $userId;
    $requiredLevel = isset($row['approval_required_level']) ? trim((string)$row['approval_required_level']) : 'DIRECTOR_ONLY';
    $stage = isset($row['approval_stage']) ? trim((string)$row['approval_stage']) : 'DIRECTOR_PENDING';

    if ($decision === 'reject') {
        $up = $pdo->prepare("UPDATE cpms_equipment_gongsu_overrides SET status='rejected', approval_stage='REJECTED', reject_reason=:reason, rejected_by=:uid, rejected_at=NOW(), updated_at=NOW() WHERE id=:id");
        $up->execute(array(':reason'=>$rejectReason, ':uid'=>$actorId, ':id'=>$overrideId));
        $pdo->commit();
        cpms_equipment_gongsu_decide_redirect('success', '장비공수 수정 요청을 반려했습니다.');
    }

    if ($requiredLevel === 'DIRECTOR_THEN_VP' && $stage === 'DIRECTOR_PENDING') {
        $vp = function_exists('cpms_labor_find_vp_approver') ? cpms_labor_find_vp_approver($pdo) : null;
        if (!$vp) { $pdo->rollBack(); cpms_equipment_gongsu_decide_redirect('error', '부사장 승인자를 직원명부에서 찾을 수 없습니다.'); }
        $up = $pdo->prepare("UPDATE cpms_equipment_gongsu_overrides SET status='pending', approval_stage='VP_PENDING', first_approver_employee_id=:first_id, first_approver_name=:first_name, first_approver_email=:first_email, first_approved_at=NOW(), current_approver_employee_id=:vp_id, current_approver_name=:vp_name, current_approver_email=:vp_email, updated_at=NOW() WHERE id=:id");
        $up->execute(array(
            ':first_id'=>$actorId,
            ':first_name'=>$userName,
            ':first_email'=>$userEmail,
            ':vp_id'=>(int)$vp['id'],
            ':vp_name'=>isset($vp['name']) ? (string)$vp['name'] : '',
            ':vp_email'=>isset($vp['email']) ? (string)$vp['email'] : '',
            ':id'=>$overrideId
        ));
        $pdo->commit();
        cpms_equipment_gongsu_send_notification($pdo, $overrideId, 'VP_REQUEST');
        cpms_equipment_gongsu_decide_redirect('success', '1차 승인 완료 후 2차 승인 요청으로 전달했습니다.');
    }

    $oldUsageEvent = false;
    try {
        $stUsageEvent = $pdo->prepare("SELECT id, project_id, equipment_id, use_date, work_unit, base_rate_snapshot, amount, is_manual_unit, memo FROM cpms_equipment_usage WHERE id=:id AND project_id=:pid LIMIT 1 FOR UPDATE");
        $stUsageEvent->execute(array(':id' => (int)$row['equipment_usage_id'], ':pid' => (int)$row['project_id']));
        $oldUsageEvent = $stUsageEvent->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $costEventException) {
        error_log('[CostDataEvent] event capture failed');
    }
    cpms_equipment_gongsu_apply_usage($pdo, (int)$row['equipment_usage_id'], (float)$row['new_value']);
    if ($stage === 'VP_PENDING') {
        $up = $pdo->prepare("UPDATE cpms_equipment_gongsu_overrides SET status='applied', approval_stage='COMPLETED', second_approver_employee_id=:eid, second_approver_name=:name, second_approver_email=:email, second_approved_at=NOW(), approved_by=:uid, approved_at=NOW(), current_approver_employee_id=NULL, current_approver_name=NULL, current_approver_email=NULL, updated_at=NOW() WHERE id=:id");
    } else {
        $up = $pdo->prepare("UPDATE cpms_equipment_gongsu_overrides SET status='applied', approval_stage='COMPLETED', first_approver_employee_id=:eid, first_approver_name=:name, first_approver_email=:email, first_approved_at=NOW(), approved_by=:uid, approved_at=NOW(), current_approver_employee_id=NULL, current_approver_name=NULL, current_approver_email=NULL, updated_at=NOW() WHERE id=:id");
    }
    $up->execute(array(':eid'=>$actorId, ':name'=>$userName, ':email'=>$userEmail, ':uid'=>$actorId, ':id'=>$overrideId));
    $newUsageEvent = false;
    if (is_array($oldUsageEvent)) {
        try {
            $stUsageEvent->execute(array(':id' => (int)$row['equipment_usage_id'], ':pid' => (int)$row['project_id']));
            $newUsageEvent = $stUsageEvent->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $costEventException) {
            error_log('[CostDataEvent] event capture failed');
        }
    }
    $pdo->commit();
    if (is_array($oldUsageEvent) && is_array($newUsageEvent)) {
        CostDataEventService::recordChange($pdo, array(
            'project_id' => (int)$row['project_id'],
            'cost_type' => 'equipment',
            'target_type' => 'equipment_usage',
            'target_id' => (string)$row['equipment_usage_id'],
            'event_action' => 'ADJUST',
            'source_type' => 'APPROVAL',
            'actual_date' => isset($row['use_date']) ? $row['use_date'] : '',
            'settlement_ym' => CostChangeService::settlementYm('equipment', isset($row['use_date']) ? $row['use_date'] : ''),
            'old_amount' => isset($oldUsageEvent['amount']) ? $oldUsageEvent['amount'] : null,
            'new_amount' => isset($newUsageEvent['amount']) ? $newUsageEvent['amount'] : null,
            'old_data' => $oldUsageEvent,
            'new_data' => $newUsageEvent,
            'reason' => isset($row['reason']) ? $row['reason'] : '',
            'dedupe_key' => 'equipment_gongsu:' . $overrideId,
            'source_file' => __FILE__,
        ));
    }
    cpms_equipment_gongsu_decide_redirect('success', '장비공수 수정 요청을 승인했습니다.');
} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    cpms_equipment_gongsu_decide_redirect('error', '처리 실패: ' . $e->getMessage());
}
?>
