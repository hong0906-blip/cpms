<?php
/**
 * 비용 변경 승인 요청 저장.
 * 중복 요청, 권한, 잠금, 첨부 보안을 저장 단계에서 재검사한다.
 * PHP 5.6 호환.
 */

require_once __DIR__ . '/_common.php';
cpms_cost_change_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}
$returnUrl = cpms_cost_change_return_url(isset($_POST['return_url']) ? $_POST['return_url'] : '', '?r=cost_change/my');
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    cpms_cost_change_redirect('error', '보안 토큰이 올바르지 않습니다.', $returnUrl);
}

$pdo = Db::pdo();
cpms_cost_change_require_installed($pdo);
$approvers = CostChangeService::resolveApprovers($pdo);
if (empty($approvers['ok'])) {
    cpms_cost_change_redirect('error', '고정 승인자가 직원 계정에 연결되지 않았습니다. 관리섹션에서 먼저 설정해주세요.', $returnUrl);
}

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$targetType = isset($_POST['target_type']) ? trim((string)$_POST['target_type']) : '';
$targetId = isset($_POST['target_id']) ? trim((string)$_POST['target_id']) : '';
$requestType = isset($_POST['request_type']) ? strtoupper(trim((string)$_POST['request_type'])) : '';
$parentId = isset($_POST['parent_id']) ? (int)$_POST['parent_id'] : 0;
$reason = isset($_POST['reason']) ? trim((string)$_POST['reason']) : '';
$allowedTargetTypes = array('material','equipment','outsourcing','labor_force','safety','daily_cost');
$allowedRequestTypes = array(CostChangeService::REQUEST_MODIFY, CostChangeService::REQUEST_ADD, CostChangeService::REQUEST_MONTH_MOVE, CostChangeService::REQUEST_DELETE);
if ($projectId <= 0 || !in_array($targetType, $allowedTargetTypes, true) || !in_array($requestType, $allowedRequestTypes, true) || $reason === '') {
    cpms_cost_change_redirect('error', '필수 요청값과 변경 사유를 확인해주세요.', $returnUrl);
}
if (!CostChangeService::canManageProject($pdo, $projectId, $targetType)) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}

$parent = null;
$rootRequestId = null;
if ($parentId > 0) {
    $parent = CostChangeService::requestById($pdo, $parentId);
    if (!$parent || !CostChangeService::isRequester($parent) || (string)$parent['status'] !== CostChangeService::STATUS_REJECTED) {
        cpms_cost_change_redirect('error', '재요청할 수 없는 요청입니다.', $returnUrl);
    }
    if ((int)$parent['project_id'] !== $projectId || (string)$parent['target_type'] !== $targetType || (string)$parent['request_type'] !== $requestType) {
        cpms_cost_change_redirect('error', '기존 반려 요청과 재요청 대상이 일치하지 않습니다.', $returnUrl);
    }
    $targetId = (string)$parent['target_id'];
    $rootRequestId = isset($parent['root_request_id']) && (int)$parent['root_request_id'] > 0 ? (int)$parent['root_request_id'] : (int)$parent['id'];
}

$target = null;
if ($requestType !== CostChangeService::REQUEST_ADD) {
    $target = CostChangeService::loadTarget($pdo, $targetType, $targetId, $projectId);
    if (!$target) cpms_cost_change_redirect('error', '대상 원본자료를 찾을 수 없습니다.', $returnUrl);
    $active = CostChangeService::activeRequest($pdo, $targetType, $targetId);
    if ($active) cpms_cost_change_redirect('error', '같은 자료에 처리 중인 요청이 있어 중복 요청할 수 없습니다.', $returnUrl);
}

$requestedData = CostChangeService::normalizeRequestedData($_POST, $target);
$useDate = isset($requestedData['use_date']) ? CostChangeService::validDate($requestedData['use_date']) : '';
if ($useDate === '') cpms_cost_change_redirect('error', '실제 사용일자를 올바르게 입력해주세요.', $returnUrl);
if ($requestType !== CostChangeService::REQUEST_DELETE && $requestType !== CostChangeService::REQUEST_MONTH_MOVE && (float)$requestedData['amount'] == 0.0) {
    cpms_cost_change_redirect('error', '요청 금액을 입력해주세요.', $returnUrl);
}
if ($requestType === CostChangeService::REQUEST_MONTH_MOVE) {
    $moveYm = isset($requestedData['settlement_ym']) ? CostChangeService::validYm($requestedData['settlement_ym']) : '';
    if ($moveYm === '' || (is_array($target) && $moveYm === (string)$target['settlement_ym'])) {
        cpms_cost_change_redirect('error', '기존 귀속월과 다른 변경 귀속월을 선택해주세요.', $returnUrl);
    }
}

$costType = isset($requestedData['cost_type']) ? (string)$requestedData['cost_type'] : $targetType;
$sourceYm = is_array($target) && isset($target['settlement_ym']) ? (string)$target['settlement_ym'] : (isset($requestedData['settlement_ym']) ? (string)$requestedData['settlement_ym'] : '');
$sourceUseDate = is_array($target) && isset($target['use_date']) ? (string)$target['use_date'] : $useDate;
$sourceLock = CostChangeService::lockInfo($costType, $sourceUseDate, $sourceYm, date('Y-m-d'));
$destinationLock = CostChangeService::lockInfo($costType, $useDate, isset($requestedData['settlement_ym']) ? $requestedData['settlement_ym'] : '', date('Y-m-d'));
if ($requestType !== CostChangeService::REQUEST_MONTH_MOVE && empty($sourceLock['locked']) && empty($destinationLock['locked'])) {
    cpms_cost_change_redirect('error', '현재 입력 가능한 귀속월은 기존 비용 화면에서 일반 저장을 이용해주세요.', $returnUrl);
}

try {
    $validUploads = CostChangeService::validateUploads('evidence_files');
} catch (Exception $e) {
    cpms_cost_change_redirect('error', $e->getMessage(), $returnUrl);
}

$oldData = is_array($target) ? $target : array();
unset($oldData['native']);
$oldAmount = isset($oldData['amount']) ? (float)$oldData['amount'] : 0.0;
$newAmount = $requestType === CostChangeService::REQUEST_DELETE ? 0.0 : (isset($requestedData['amount']) ? (float)$requestedData['amount'] : 0.0);
$requestNo = CostChangeService::requestNo();
$employeeId = CostChangeService::employeeId();
$projectName = CostChangeService::projectName($pdo, $projectId);
$activeTargetKey = $requestType === CostChangeService::REQUEST_ADD ? null : CostChangeService::targetKey($targetType, $targetId);
$savedPaths = array();

try {
    $pdo->beginTransaction();
    $sql = "INSERT INTO cpms_cost_change_requests
        (request_no,root_request_id,parent_request_id,project_id,project_name,request_department,requester_employee_id,requester_name,requester_email,
         cost_type,target_type,target_id,active_target_key,request_type,use_date,old_settlement_ym,new_settlement_ym,manual_settlement_yn,
         old_data,requested_data,old_amount,new_amount,reason,status,current_stage,current_approver_employee_id,
         first_approver_employee_id,first_approver_name,first_approver_email,
         final_approver_employee_id,final_approver_name,final_approver_email,created_at,updated_at)
        VALUES
        (:request_no,:root_request_id,:parent_request_id,:project_id,:project_name,:request_department,:requester_id,:requester_name,:requester_email,
         :cost_type,:target_type,:target_id,:active_target_key,:request_type,:use_date,:old_ym,:new_ym,:manual_yn,
         :old_data,:requested_data,:old_amount,:new_amount,:reason,:status,'FIRST',:current_approver_id,
         :first_id,:first_name,:first_email,:final_id,:final_name,:final_email,NOW(),NOW())";
    $st = $pdo->prepare($sql);
    $st->execute(array(
        ':request_no'=>$requestNo,
        ':root_request_id'=>$rootRequestId,
        ':parent_request_id'=>$parentId > 0 ? $parentId : null,
        ':project_id'=>$projectId,
        ':project_name'=>$projectName,
        ':request_department'=>(string)Auth::userDepartment(),
        ':requester_id'=>$employeeId > 0 ? $employeeId : null,
        ':requester_name'=>(string)Auth::userName(),
        ':requester_email'=>(string)Auth::userEmail(),
        ':cost_type'=>$costType,
        ':target_type'=>$targetType,
        ':target_id'=>$targetId !== '' ? $targetId : null,
        ':active_target_key'=>$activeTargetKey,
        ':request_type'=>$requestType,
        ':use_date'=>$useDate,
        ':old_ym'=>isset($oldData['settlement_ym']) ? $oldData['settlement_ym'] : null,
        ':new_ym'=>isset($requestedData['settlement_ym']) ? $requestedData['settlement_ym'] : null,
        ':manual_yn'=>isset($requestedData['manual_settlement_yn']) ? (int)$requestedData['manual_settlement_yn'] : 0,
        ':old_data'=>CostChangeService::jsonEncode($oldData),
        ':requested_data'=>CostChangeService::jsonEncode($requestedData),
        ':old_amount'=>$oldAmount,
        ':new_amount'=>$newAmount,
        ':reason'=>$reason,
        ':status'=>CostChangeService::STATUS_FIRST_PENDING,
        ':current_approver_id'=>(int)$approvers['first']['id'],
        ':first_id'=>(int)$approvers['first']['id'],
        ':first_name'=>(string)$approvers['first']['name'],
        ':first_email'=>(string)$approvers['first']['email'],
        ':final_id'=>(int)$approvers['final']['id'],
        ':final_name'=>(string)$approvers['final']['name'],
        ':final_email'=>(string)$approvers['final']['email']
    ));
    $requestId = (int)$pdo->lastInsertId();
    if ($requestId <= 0) throw new Exception('요청번호를 생성하지 못했습니다.');
    if ($rootRequestId === null) {
        $pdo->prepare("UPDATE cpms_cost_change_requests SET root_request_id=:root_id WHERE id=:id")->execute(array(':root_id'=>$requestId, ':id'=>$requestId));
    }
    if ($parentId > 0) {
        $inheritFileIds = isset($_POST['inherit_file_ids']) && is_array($_POST['inherit_file_ids']) ? $_POST['inherit_file_ids'] : array();
        CostChangeService::inheritFiles($pdo, $parentId, $requestId, $inheritFileIds);
    }
    $savedPaths = CostChangeService::storeUploads($pdo, $requestId, $requestNo, $validUploads);
    CostChangeService::logEvent($pdo, $requestId, $parentId > 0 ? 'RESUBMITTED' : 'REQUESTED', 'FIRST', $reason, array('old'=>$oldData, 'requested'=>$requestedData));
    $pdo->commit();

    $savedRequest = CostChangeService::requestById($pdo, $requestId);
    CostChangeService::notify($pdo, $savedRequest, 'FIRST_REQUEST', (int)$approvers['first']['id']);
    cpms_cost_change_redirect('success', '비용 변경 승인 요청을 보냈습니다. 요청번호: ' . $requestNo, '?r=cost_change/detail&id=' . $requestId);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($savedPaths as $savedPath) {
        if (is_file($savedPath)) @unlink($savedPath);
    }
    $message = strpos(strtolower($e->getMessage()), 'duplicate') !== false
        ? '같은 자료에 처리 중인 요청이 있어 중복 요청할 수 없습니다.'
        : '승인 요청 저장 실패: ' . $e->getMessage();
    cpms_cost_change_redirect('error', $message, $returnUrl);
}
