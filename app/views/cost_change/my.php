<?php
/**
 * 요청자 본인의 비용 변경 요청 목록/과거 이력.
 * PHP 5.6 호환.
 */

require_once __DIR__ . '/_common.php';
cpms_cost_change_require_login();
$pdo = Db::pdo();
cpms_cost_change_require_installed($pdo);

$employeeId = CostChangeService::employeeId();
$email = (string)Auth::userEmail();
$view = isset($_GET['view']) && (string)$_GET['view'] === 'history' ? 'history' : 'current';
$params = array(':employee_id'=>$employeeId, ':email'=>$email);
$where = "(requester_employee_id=:employee_id OR LOWER(requester_email)=LOWER(:email))";
if ($view === 'current') {
    $where .= " AND status IN ('FIRST_PENDING','FINAL_PENDING','REJECTED','FAILED')";
}
try {
    $st = $pdo->prepare("SELECT * FROM cpms_cost_change_requests WHERE " . $where . " ORDER BY created_at DESC,id DESC LIMIT 500");
    $st->execute($params);
    $requests = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $requests = array();
}
?>

<div class="space-y-5">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
        <div>
            <h2 class="text-2xl font-extrabold">나의 비용 변경 요청</h2>
            <p class="mt-1 text-sm text-gray-500">로그인한 본인의 요청과 반려사유, 승인 진행이력만 표시됩니다.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="?r=cost_change/my" class="px-4 py-2 rounded-xl border font-bold <?php echo $view === 'current' ? 'bg-gray-900 text-white border-gray-900' : 'bg-white border-gray-300'; ?>">진행/확인 요청</a>
            <a href="?r=cost_change/my&view=history" class="px-4 py-2 rounded-xl border font-bold <?php echo $view === 'history' ? 'bg-gray-900 text-white border-gray-900' : 'bg-white border-gray-300'; ?>">과거 요청 이력</a>
        </div>
    </div>

    <div class="overflow-x-auto rounded-2xl border border-gray-200 bg-white">
        <table class="min-w-[1180px] w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="p-3 text-left">현장</th><th class="p-3 text-left">비용 구분</th><th class="p-3 text-left">요청 종류</th>
                    <th class="p-3 text-left">실제 사용일자</th><th class="p-3 text-left">귀속월</th><th class="p-3 text-right">요청 금액</th>
                    <th class="p-3 text-left">요청일</th><th class="p-3 text-left">현재 승인단계</th><th class="p-3 text-left">상태</th><th class="p-3 text-center">상세보기</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php if (count($requests) === 0): ?>
                    <tr><td colspan="10" class="p-6 text-center text-gray-500">표시할 비용 변경 요청이 없습니다.</td></tr>
                <?php endif; ?>
                <?php foreach ($requests as $request): ?>
                    <tr class="<?php echo (string)$request['status'] === CostChangeService::STATUS_REJECTED ? 'bg-red-50/60' : ''; ?>">
                        <td class="p-3 font-bold"><?php echo h($request['project_name']); ?></td>
                        <td class="p-3"><?php echo h(CostChangeService::costTypeLabel($request['cost_type'])); ?></td>
                        <td class="p-3"><?php echo h(CostChangeService::requestTypeLabel($request['request_type'])); ?></td>
                        <td class="p-3 whitespace-nowrap"><?php echo h($request['use_date']); ?></td>
                        <td class="p-3 whitespace-nowrap"><?php echo h(($request['new_settlement_ym'] !== null ? $request['new_settlement_ym'] : '-') . '월분'); ?></td>
                        <td class="p-3 text-right font-bold"><?php echo h(cpms_cost_change_money($request['new_amount'])); ?></td>
                        <td class="p-3 whitespace-nowrap"><?php echo h($request['created_at']); ?></td>
                        <td class="p-3"><?php echo h(CostChangeService::stageLabel($request['current_stage'])); ?></td>
                        <td class="p-3"><span class="inline-flex px-2 py-1 rounded-full border text-xs font-bold <?php echo h(CostChangeService::statusClass($request['status'])); ?>"><?php echo h(CostChangeService::statusLabel($request['status'])); ?></span></td>
                        <td class="p-3 text-center"><a href="?r=cost_change/detail&id=<?php echo (int)$request['id']; ?>" class="px-3 py-1 rounded-lg border border-blue-200 bg-blue-50 text-blue-700 font-bold">상세</a></td>
                    </tr>
                    <?php if ((string)$request['status'] === CostChangeService::STATUS_REJECTED): ?>
                        <tr class="bg-red-50"><td colspan="10" class="px-3 pb-3 text-red-700"><strong>반려 사유:</strong> <?php echo h($request['rejected_reason']); ?></td></tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
