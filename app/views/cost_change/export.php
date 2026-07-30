<?php
/**
 * 관리 비용 변경 이력 Excel 호환 CSV 내보내기.
 * PHP 5.6 호환.
 */

require_once __DIR__ . '/_common.php';
cpms_cost_change_require_login();
if (!CostChangeService::canAdmin()) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}
$pdo = Db::pdo();
cpms_cost_change_require_installed($pdo);
$where = array('1=1');
$params = array();
$dateFrom = isset($_GET['date_from']) ? CostChangeService::validDate($_GET['date_from']) : '';
$dateTo = isset($_GET['date_to']) ? CostChangeService::validDate($_GET['date_to']) : '';
if ($dateFrom !== '') { $where[]='created_at>=:date_from'; $params[':date_from']=$dateFrom . ' 00:00:00'; }
if ($dateTo !== '') { $where[]='created_at<=:date_to'; $params[':date_to']=$dateTo . ' 23:59:59'; }
$exact = array('project_id','cost_type','request_type','status');
foreach ($exact as $key) {
    $value = isset($_GET[$key]) ? trim((string)$_GET[$key]) : '';
    if ($value !== '' && ($key !== 'project_id' || (int)$value > 0)) { $where[]=$key . '=:' . $key; $params[':' . $key]=$key === 'project_id' ? (int)$value : $value; }
}
$settlementYm = isset($_GET['settlement_ym']) ? CostChangeService::validYm($_GET['settlement_ym']) : '';
if ($settlementYm !== '') { $where[]='new_settlement_ym=:settlement_ym'; $params[':settlement_ym']=$settlementYm; }
foreach (array('requester'=>'requester_name','department'=>'request_department') as $queryKey=>$column) {
    $value = isset($_GET[$queryKey]) ? trim((string)$_GET[$queryKey]) : '';
    if ($value !== '') { $where[]=$column . ' LIKE :' . $queryKey; $params[':' . $queryKey]='%' . $value . '%'; }
}
$approver = isset($_GET['approver']) ? trim((string)$_GET['approver']) : '';
if ($approver !== '') { $where[]='(first_approver_name LIKE :approver OR final_approver_name LIKE :approver OR rejected_by_name LIKE :approver)'; $params[':approver']='%' . $approver . '%'; }
$approvalStatus = isset($_GET['approval_status']) ? trim((string)$_GET['approval_status']) : '';
if ($approvalStatus === 'FIRST_PENDING') $where[] = "status='FIRST_PENDING'";
if ($approvalStatus === 'FINAL_PENDING') $where[] = "status='FINAL_PENDING'";
if ($approvalStatus === 'FIRST_APPROVED') $where[] = "first_result='APPROVED'";
if ($approvalStatus === 'FINAL_APPROVED') $where[] = "final_result='APPROVED'";
if ($approvalStatus === 'REJECTED') $where[] = "status='REJECTED'";
$processResult = isset($_GET['process_result']) ? trim((string)$_GET['process_result']) : '';
if (in_array($processResult, array('COMPLETED','FAILED','CANCELLED'), true)) {
    $where[] = 'status=:process_result';
    $params[':process_result'] = $processResult;
}
$st = $pdo->prepare("SELECT * FROM cpms_cost_change_requests WHERE " . implode(' AND ', $where) . " ORDER BY created_at ASC,id ASC");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="cpms_cost_change_' . date('Ymd_His') . '.csv"');
header('X-Content-Type-Options: nosniff');
echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'w');
fputcsv($out, array('요청번호','최초 요청번호','재요청 연결번호','요청자','요청부서','요청일시','현장','비용 구분','요청 종류','실제 사용일자','기존 귀속월','변경 귀속월','변경 전 금액','변경 후 금액','변경 사유','1차 결과','1차 의견','1차 처리일시','최종 결과','최종 의견','최종 처리일시','처리 결과','자동 반영일시','처리 실패 사유','요청 취소일시'));
foreach ($rows as $row) {
    fputcsv($out, array(
        $row['request_no'],$row['root_request_id'],$row['parent_request_id'],$row['requester_name'],$row['request_department'],$row['created_at'],$row['project_name'],
        CostChangeService::costTypeLabel($row['cost_type']),CostChangeService::requestTypeLabel($row['request_type']),$row['use_date'],$row['old_settlement_ym'],$row['new_settlement_ym'],
        $row['old_amount'],$row['new_amount'],$row['reason'],$row['first_result'],$row['first_opinion'],$row['first_acted_at'],$row['final_result'],$row['final_opinion'],$row['final_acted_at'],
        CostChangeService::statusLabel($row['status']),$row['applied_at'],$row['apply_error'],$row['cancelled_at']
    ));
}
fclose($out);
exit;
