<?php
/**
 * 비용 원본자료별 변경이력.
 * PHP 5.6 호환.
 */

require_once __DIR__ . '/_common.php';
cpms_cost_change_require_login();
$pdo = Db::pdo();
cpms_cost_change_require_installed($pdo);
$targetType = isset($_GET['target_type']) ? trim((string)$_GET['target_type']) : '';
$targetId = isset($_GET['target_id']) ? trim((string)$_GET['target_id']) : '';
$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
if ($targetType === '' || $targetId === '' || $projectId <= 0 || !CostChangeService::canViewProject($pdo, $projectId, $targetType)) {
    http_response_code(403);
    echo '<div class="rounded-2xl border border-red-200 bg-red-50 p-4 text-red-700 font-bold">변경이력을 열람할 권한이 없습니다.</div>';
    return;
}
try {
    $st = $pdo->prepare("SELECT * FROM cpms_cost_change_requests WHERE target_type=:target_type AND target_id=:target_id AND project_id=:project_id ORDER BY created_at ASC,id ASC");
    $st->execute(array(':target_type'=>$targetType, ':target_id'=>$targetId, ':project_id'=>$projectId));
    $requests = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $requests = array();
}
$target = CostChangeService::loadTarget($pdo, $targetType, $targetId, $projectId);
?>

<div class="max-w-5xl mx-auto space-y-5">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
        <div><h2 class="text-2xl font-extrabold">비용 변경이력</h2><p class="mt-1 text-sm text-gray-500"><?php echo h(CostChangeService::projectName($pdo, $projectId)); ?> · 원본 연결 <?php echo h($targetType . ':' . $targetId); ?></p></div>
        <a href="<?php echo h($targetType === 'safety' ? '?r=safety_home&pid=' . $projectId . '&tab=safety_cost' : '?r=construction_home&pid=' . $projectId); ?>" class="px-4 py-2 rounded-xl border border-gray-300 bg-white font-bold">비용화면으로</a>
    </div>
    <?php if (is_array($target)): ?>
    <div class="rounded-2xl border border-gray-200 bg-white p-5">
        <div class="font-extrabold">현재 원본 연결상태</div>
        <div class="mt-2 grid grid-cols-1 md:grid-cols-3 gap-2 text-sm">
            <div>실제 사용일자: <strong><?php echo h($target['use_date']); ?></strong></div>
            <div>귀속월: <strong><?php echo h($target['settlement_ym']); ?>월분</strong></div>
            <div>금액: <strong><?php echo h(cpms_cost_change_money($target['amount'])); ?></strong></div>
        </div>
    </div>
    <?php endif; ?>
    <div class="space-y-3">
        <?php if (count($requests) === 0): ?><div class="rounded-2xl border border-dashed border-gray-300 p-6 text-center text-gray-500">변경이력이 없습니다.</div><?php endif; ?>
        <?php foreach ($requests as $request): ?>
            <?php $old = CostChangeService::jsonDecode($request['old_data']); $new = CostChangeService::jsonDecode($request['requested_data']); ?>
            <a href="?r=cost_change/detail&id=<?php echo (int)$request['id']; ?>" class="block rounded-2xl border border-gray-200 bg-white p-4">
                <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-2">
                    <div>
                        <div class="font-extrabold"><?php echo h($request['request_no']); ?> · <?php echo h(CostChangeService::requestTypeLabel($request['request_type'])); ?></div>
                        <div class="mt-1 text-sm text-gray-600"><?php echo h($request['created_at']); ?> / 요청자 <?php echo h($request['requester_name']); ?> / 사유 <?php echo h($request['reason']); ?></div>
                        <div class="mt-2 text-sm">금액 <?php echo h(cpms_cost_change_money($request['old_amount'])); ?> → <strong><?php echo h(cpms_cost_change_money($request['new_amount'])); ?></strong></div>
                        <div class="mt-1 text-xs text-gray-500">1차 <?php echo h($request['first_result'] !== null ? $request['first_result'] : '대기'); ?> / 최종 <?php echo h($request['final_result'] !== null ? $request['final_result'] : '대기'); ?> / 실제 반영 <?php echo h($request['applied_at'] !== null ? $request['applied_at'] : '-'); ?></div>
                    </div>
                    <span class="inline-flex px-2 py-1 rounded-full border text-xs font-bold <?php echo h(CostChangeService::statusClass($request['status'])); ?>"><?php echo h(CostChangeService::statusLabel($request['status'])); ?></span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
</div>

