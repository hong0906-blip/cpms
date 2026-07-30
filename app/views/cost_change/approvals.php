<?php
/**
 * 임원대시보드 > 비용 변경 승인.
 * 박원덕 전무/부사장 직원 ID에 맞는 단계만 기본 표시한다.
 * PHP 5.6 호환.
 */

require_once __DIR__ . '/_common.php';
cpms_cost_change_require_login();
$pdo = Db::pdo();
cpms_cost_change_require_installed($pdo);
$approvers = CostChangeService::resolveApprovers($pdo);
$employeeId = CostChangeService::employeeId();
$isFirst = !empty($approvers['first']) && $employeeId === (int)$approvers['first']['id'];
$isFinal = !empty($approvers['final']) && $employeeId === (int)$approvers['final']['id'];
if (!$isFirst && !$isFinal && !CostChangeService::canAdmin()) {
    http_response_code(403);
    echo '<div class="rounded-2xl border border-red-200 bg-red-50 p-4 text-red-700 font-bold">비용 변경 승인자 권한이 없습니다.</div>';
    return;
}

$view = isset($_GET['view']) ? trim((string)$_GET['view']) : 'pending';
if (!in_array($view, array('pending','approved','rejected','history'), true)) $view = 'pending';
$where = array();
$params = array();
if (!CostChangeService::canAdmin()) {
    if ($isFirst && !$isFinal) {
        $where[] = 'first_approver_employee_id=:employee_id';
    } else if ($isFinal && !$isFirst) {
        $where[] = 'final_approver_employee_id=:employee_id';
    } else {
        $where[] = '(first_approver_employee_id=:employee_id OR final_approver_employee_id=:employee_id)';
    }
    $params[':employee_id'] = $employeeId;
}
if ($view === 'pending') {
    $where[] = 'current_approver_employee_id=:current_employee_id';
    $where[] = "status IN ('FIRST_PENDING','FINAL_PENDING')";
    $params[':current_employee_id'] = $employeeId;
} else if ($view === 'approved') {
    if ($isFirst && !$isFinal) $where[] = "first_result='APPROVED'";
    else if ($isFinal && !$isFirst) $where[] = "final_result='APPROVED'";
    else $where[] = "(first_result='APPROVED' OR final_result='APPROVED')";
} else if ($view === 'rejected') {
    $where[] = "status='REJECTED'";
    if ($isFirst && !$isFinal) $where[] = "rejected_stage='FIRST'";
    else if ($isFinal && !$isFirst) $where[] = "rejected_stage='FINAL'";
}
$sqlWhere = count($where) > 0 ? (' WHERE ' . implode(' AND ', $where)) : '';
try {
    $st = $pdo->prepare("SELECT * FROM cpms_cost_change_requests" . $sqlWhere . " ORDER BY created_at DESC,id DESC LIMIT 1000");
    $st->execute($params);
    $requests = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $requests = array();
}
?>

<div class="space-y-5">
    <div class="rounded-2xl border border-gray-200 bg-white p-5">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h2 class="text-2xl font-extrabold">비용 변경 승인</h2>
                <p class="mt-1 text-sm text-gray-500"><?php echo $isFirst ? '박원덕 전무 1차 승인 단계' : ($isFinal ? '부사장 최종 승인 단계' : '관리자 조회'); ?> · 고정 승인선만 처리할 수 있습니다.</p>
            </div>
            <a href="?r=dashboard_executive" class="px-4 py-2 rounded-xl border border-gray-300 bg-white font-bold">임원대시보드</a>
        </div>
        <div class="mt-4 flex flex-wrap gap-2">
            <?php foreach (array('pending'=>'승인 대기','approved'=>'승인 완료','rejected'=>'반려','history'=>'전체 처리이력') as $key=>$label): ?>
                <a href="?r=cost_change/approvals&view=<?php echo h($key); ?>" class="px-4 py-2 rounded-xl border font-bold <?php echo $view === $key ? 'bg-gray-900 text-white border-gray-900' : 'bg-white border-gray-300'; ?>"><?php echo h($label); ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-3">
        <?php if (count($requests) === 0): ?>
            <div class="rounded-2xl border border-dashed border-gray-300 bg-white p-6 text-center text-gray-500">표시할 비용 변경 요청이 없습니다.</div>
        <?php endif; ?>
        <?php foreach ($requests as $request): ?>
            <a href="?r=cost_change/detail&id=<?php echo (int)$request['id']; ?>" class="block rounded-2xl border border-gray-200 bg-white p-4 hover:shadow-md transition">
                <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-extrabold text-gray-900"><?php echo h($request['request_no']); ?></span>
                            <span class="px-2 py-1 rounded-full border text-xs font-bold <?php echo h(CostChangeService::statusClass($request['status'])); ?>"><?php echo h(CostChangeService::statusLabel($request['status'])); ?></span>
                        </div>
                        <div class="mt-2 text-sm text-gray-700"><?php echo h($request['project_name']); ?> · <?php echo h(CostChangeService::costTypeLabel($request['cost_type'])); ?> · <?php echo h(CostChangeService::requestTypeLabel($request['request_type'])); ?></div>
                        <div class="mt-1 text-sm text-gray-500">요청자 <?php echo h($request['requester_name']); ?> / <?php echo h($request['request_department']); ?> / <?php echo h($request['created_at']); ?></div>
                    </div>
                    <div class="text-left md:text-right">
                        <div class="text-xs text-gray-500">요청금액</div>
                        <div class="text-lg font-extrabold"><?php echo h(cpms_cost_change_money($request['new_amount'])); ?></div>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
</div>
