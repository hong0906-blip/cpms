<?php
/**
 * 요청자 비용 변경 요청 취소.
 * PHP 5.6 호환.
 */

require_once __DIR__ . '/_common.php';
cpms_cost_change_require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    cpms_cost_change_redirect('error', '보안 토큰이 올바르지 않습니다.', '?r=cost_change/my');
}
$pdo = Db::pdo();
cpms_cost_change_require_installed($pdo);
$requestId = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
try {
    $pdo->beginTransaction();
    $st = $pdo->prepare("SELECT * FROM cpms_cost_change_requests WHERE id=:id FOR UPDATE");
    $st->execute(array(':id'=>$requestId));
    $request = $st->fetch(PDO::FETCH_ASSOC);
    if (!$request || !CostChangeService::isRequester($request)) throw new Exception('본인 요청만 취소할 수 있습니다.');
    $allowed = array(CostChangeService::STATUS_FIRST_PENDING, CostChangeService::STATUS_FINAL_PENDING, CostChangeService::STATUS_REJECTED);
    if (!in_array((string)$request['status'], $allowed, true)) throw new Exception('현재 상태에서는 요청을 취소할 수 없습니다.');
    $up = $pdo->prepare("UPDATE cpms_cost_change_requests SET
        status=:status,current_stage='CANCELLED',current_approver_employee_id=NULL,active_target_key=NULL,
        cancelled_by_employee_id=:actor_id,cancelled_at=NOW(),updated_at=NOW()
        WHERE id=:id AND status=:expected_status");
    $up->execute(array(
        ':status'=>CostChangeService::STATUS_CANCELLED,
        ':actor_id'=>CostChangeService::employeeId(),
        ':id'=>$requestId,
        ':expected_status'=>$request['status']
    ));
    if ($up->rowCount() !== 1) throw new Exception('이미 처리된 요청입니다.');
    CostChangeService::logEvent($pdo, $requestId, 'CANCELLED', 'REQUESTER', '요청자가 요청을 취소함', array());
    $pdo->commit();
    cpms_cost_change_redirect('success', '비용 변경 요청을 취소했습니다.', '?r=cost_change/detail&id=' . $requestId);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    cpms_cost_change_redirect('error', $e->getMessage(), '?r=cost_change/my');
}

