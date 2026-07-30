<?php
/**
 * 비용 변경 요청 상세/변경 전후 비교/승인 진행이력.
 * PHP 5.6 호환.
 */

require_once __DIR__ . '/_common.php';
cpms_cost_change_require_login();
$pdo = Db::pdo();
cpms_cost_change_require_installed($pdo);
$requestId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$request = CostChangeService::requestById($pdo, $requestId);
if (!$request || !CostChangeService::canViewRequest($pdo, $request)) {
    http_response_code(403);
    echo '<div class="rounded-2xl border border-red-200 bg-red-50 p-4 text-red-700 font-bold">요청을 열람할 권한이 없습니다.</div>';
    return;
}
$oldData = CostChangeService::jsonDecode($request['old_data']);
$newData = CostChangeService::jsonDecode($request['requested_data']);
$diffRows = cpms_cost_change_diff_rows($oldData, $newData);
$files = CostChangeService::files($pdo, $requestId);
$logs = CostChangeService::logs($pdo, $requestId);
$canAct = CostChangeService::canActRequest($request);
$isRequester = CostChangeService::isRequester($request);
$target = null;
if (trim((string)$request['target_id']) !== '') {
    $target = CostChangeService::loadTarget($pdo, $request['target_type'], $request['target_id'], $request['project_id']);
}
$existingFiles = array();
try {
    if ((string)$request['target_type'] === 'material' && (int)$request['target_id'] > 0 && CostChangeService::tableExists($pdo, 'cpms_material_statement_files')) {
        $st = $pdo->prepare("SELECT id,original_name FROM cpms_material_statement_files WHERE material_usage_id=:target_id AND is_deleted=0 ORDER BY id ASC");
        $st->execute(array(':target_id'=>(int)$request['target_id']));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) $existingFiles[] = array('name'=>$row['original_name'], 'url'=>'?r=construction/material_statement_download&id=' . (int)$row['id']);
    } else if ((string)$request['target_type'] === 'outsourcing' && (int)$request['target_id'] > 0 && CostChangeService::tableExists($pdo, 'cpms_outsourcing_cost_files')) {
        $st = $pdo->prepare("SELECT id,original_name FROM cpms_outsourcing_cost_files WHERE outsourcing_cost_id=:target_id AND is_deleted=0 ORDER BY id ASC");
        $st->execute(array(':target_id'=>(int)$request['target_id']));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) $existingFiles[] = array('name'=>$row['original_name'], 'url'=>'?r=construction/outsourcing_file_download&id=' . (int)$row['id']);
    } else if ((string)$request['target_type'] === 'equipment' && is_array($target) && isset($target['native']['statement_stored_path']) && trim((string)$target['native']['statement_stored_path']) !== '') {
        $existingFiles[] = array('name'=>isset($target['native']['statement_original_name']) ? $target['native']['statement_original_name'] : '장비 거래명세표', 'url'=>'?r=construction/equipment_statement_download&id=' . (int)$request['target_id']);
    } else if ((string)$request['target_type'] === 'safety' && is_array($target) && isset($target['native']['pdf']) && is_array($target['native']['pdf']) && count($target['native']['pdf']) > 0) {
        $existingFiles[] = array('name'=>isset($target['native']['pdf']['original_name']) ? $target['native']['pdf']['original_name'] : '기존 PDF', 'url'=>'?r=safety/safety_cost_download&id=' . rawurlencode((string)$request['target_id']));
    }
} catch (Exception $e) {
    $existingFiles = array();
}
?>

<div class="max-w-6xl mx-auto space-y-5">
    <div class="rounded-2xl border border-gray-200 bg-white p-5">
        <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-2xl font-extrabold">비용 변경 요청 상세</h2>
                    <span class="px-2 py-1 rounded-full border text-xs font-bold <?php echo h(CostChangeService::statusClass($request['status'])); ?>"><?php echo h(CostChangeService::statusLabel($request['status'])); ?></span>
                </div>
                <div class="mt-2 text-sm text-gray-500"><?php echo h($request['request_no']); ?> · <?php echo h($request['created_at']); ?></div>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="<?php echo h($isRequester ? '?r=cost_change/my' : '?r=cost_change/approvals'); ?>" class="px-4 py-2 rounded-xl border border-gray-300 bg-white font-bold">목록</a>
                <a href="<?php echo h(cpms_cost_change_target_url($request)); ?>" class="px-4 py-2 rounded-xl border border-blue-300 bg-blue-50 text-blue-700 font-bold">원래 비용화면</a>
            </div>
        </div>
    </div>

    <?php if ((string)$request['status'] === CostChangeService::STATUS_REJECTED): ?>
        <div class="rounded-2xl border border-red-300 bg-red-50 p-5 text-red-800">
            <div class="font-extrabold">반려 · <?php echo h($request['rejected_stage'] === 'FIRST' ? '1차 승인단계' : '최종 승인단계'); ?></div>
            <div class="mt-2"><?php echo nl2br(h($request['rejected_reason'])); ?></div>
            <div class="mt-1 text-sm"><?php echo h($request['rejected_by_name']); ?> · <?php echo h($request['rejected_at']); ?></div>
        </div>
    <?php elseif ((string)$request['status'] === CostChangeService::STATUS_FAILED): ?>
        <div class="rounded-2xl border border-red-300 bg-red-50 p-5 text-red-800">
            <div class="font-extrabold">최종 승인 후 자동 반영 실패</div>
            <div class="mt-2"><?php echo nl2br(h($request['apply_error'])); ?></div>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        <div class="rounded-xl border border-gray-200 bg-white p-4"><div class="text-xs text-gray-500">현장 / 요청부서</div><div class="font-bold"><?php echo h($request['project_name']); ?> / <?php echo h($request['request_department']); ?></div></div>
        <div class="rounded-xl border border-gray-200 bg-white p-4"><div class="text-xs text-gray-500">요청자</div><div class="font-bold"><?php echo h($request['requester_name']); ?></div></div>
        <div class="rounded-xl border border-gray-200 bg-white p-4"><div class="text-xs text-gray-500">요청 종류</div><div class="font-bold"><?php echo h(CostChangeService::costTypeLabel($request['cost_type']) . ' / ' . CostChangeService::requestTypeLabel($request['request_type'])); ?></div></div>
    </div>

    <div class="rounded-2xl border border-gray-200 bg-white p-5">
        <h3 class="text-lg font-extrabold">변경 전 / 변경 요청 비교</h3>
        <div class="mt-3 overflow-x-auto">
            <table class="min-w-[720px] w-full text-sm">
                <thead class="bg-gray-50"><tr><th class="p-3 text-left">항목</th><th class="p-3 text-left">변경 전</th><th class="p-3 text-left">변경 요청</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($diffRows as $row): ?>
                        <tr class="<?php echo $row['changed'] ? 'bg-yellow-50' : ''; ?>">
                            <td class="p-3 font-bold"><?php echo h($row['label']); ?></td>
                            <td class="p-3"><?php echo h($row['old']); ?></td>
                            <td class="p-3 <?php echo $row['changed'] ? 'font-extrabold text-blue-700' : ''; ?>"><?php echo h($row['new']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-4"><div class="text-xs text-gray-500">변경 사유</div><div class="mt-1"><?php echo nl2br(h($request['reason'])); ?></div></div>
        <div class="mt-3 text-sm">증감 금액: <strong class="<?php echo ((float)$request['new_amount'] - (float)$request['old_amount']) < 0 ? 'text-red-700' : 'text-blue-700'; ?>"><?php echo h(cpms_cost_change_money((float)$request['new_amount'] - (float)$request['old_amount'])); ?></strong></div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="rounded-2xl border border-gray-200 bg-white p-5">
            <h3 class="font-extrabold">기존 원본 첨부파일</h3>
            <div class="mt-3 flex flex-wrap gap-2">
                <?php if (count($existingFiles) === 0): ?><span class="text-sm text-gray-400">기존 첨부파일 없음</span><?php endif; ?>
                <?php foreach ($existingFiles as $file): ?><a href="<?php echo h($file['url']); ?>" class="px-3 py-2 rounded-lg border border-gray-300 bg-white text-sm font-bold"><?php echo h($file['name']); ?></a><?php endforeach; ?>
            </div>
        </div>
        <div class="rounded-2xl border border-blue-200 bg-blue-50 p-5">
            <h3 class="font-extrabold text-blue-900">승인 요청 증빙자료</h3>
            <div class="mt-3 flex flex-wrap gap-2">
                <?php if (count($files) === 0): ?><span class="text-sm text-blue-500">새 증빙자료 없음</span><?php endif; ?>
                <?php foreach ($files as $file): ?>
                    <a href="?r=cost_change/file&id=<?php echo (int)$file['id']; ?>&download=1" class="px-3 py-2 rounded-lg border border-blue-300 bg-white text-sm font-bold text-blue-700">
                        <?php echo h(($file['file_group'] === 'INHERITED' ? '[기존 요청] ' : '[새 제출] ') . $file['original_name']); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="rounded-2xl border border-gray-200 bg-white p-5">
        <h3 class="font-extrabold">승인 진행상황</h3>
        <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3">
            <div class="rounded-xl border border-gray-200 p-4">
                <div class="text-sm font-bold">1차 · 박원덕 전무</div>
                <div class="mt-1"><?php echo h($request['first_result'] !== null ? $request['first_result'] : '대기'); ?> <?php echo h($request['first_acted_at']); ?></div>
                <?php if (trim((string)$request['first_opinion']) !== ''): ?><div class="mt-2 text-sm text-gray-600"><?php echo nl2br(h($request['first_opinion'])); ?></div><?php endif; ?>
            </div>
            <div class="rounded-xl border border-gray-200 p-4">
                <div class="text-sm font-bold">최종 · 부사장</div>
                <div class="mt-1"><?php echo h($request['final_result'] !== null ? $request['final_result'] : '대기'); ?> <?php echo h($request['final_acted_at']); ?></div>
                <?php if (trim((string)$request['final_opinion']) !== ''): ?><div class="mt-2 text-sm text-gray-600"><?php echo nl2br(h($request['final_opinion'])); ?></div><?php endif; ?>
            </div>
        </div>
        <div class="mt-4 space-y-2">
            <?php foreach ($logs as $log): ?>
                <div class="rounded-lg border border-gray-100 bg-gray-50 px-3 py-2 text-sm">
                    <strong><?php echo h($log['event_type']); ?></strong> · <?php echo h($log['actor_name'] !== '' ? $log['actor_name'] : '시스템'); ?> · <?php echo h($log['created_at']); ?>
                    <?php if (trim((string)$log['event_note']) !== ''): ?><div class="mt-1 text-gray-600"><?php echo h($log['event_note']); ?></div><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($canAct): ?>
    <div class="rounded-2xl border border-indigo-200 bg-indigo-50 p-5">
        <h3 class="font-extrabold text-indigo-900"><?php echo (string)$request['status'] === CostChangeService::STATUS_FIRST_PENDING ? '1차 승인 처리' : '최종 승인 처리'; ?></h3>
        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-3">
            <form method="post" action="?r=cost_change/decide" class="space-y-2">
                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="request_id" value="<?php echo (int)$requestId; ?>"><input type="hidden" name="decision" value="approve">
                <textarea name="opinion" rows="3" class="w-full px-3 py-2 rounded-xl border border-gray-300 bg-white" placeholder="승인 의견(선택)"></textarea>
                <button type="submit" class="w-full px-4 py-2 rounded-xl bg-emerald-700 text-white font-extrabold" onclick="this.disabled=true;this.form.submit();">승인</button>
            </form>
            <form method="post" action="?r=cost_change/decide" class="space-y-2">
                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="request_id" value="<?php echo (int)$requestId; ?>"><input type="hidden" name="decision" value="reject">
                <textarea name="opinion" rows="3" class="w-full px-3 py-2 rounded-xl border border-red-300 bg-white" placeholder="반려 사유(필수)" required></textarea>
                <button type="submit" class="w-full px-4 py-2 rounded-xl bg-red-700 text-white font-extrabold" onclick="if(this.form.opinion.value.trim()==='')return false;this.disabled=true;this.form.submit();">반려</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isRequester && in_array((string)$request['status'], array(CostChangeService::STATUS_FIRST_PENDING, CostChangeService::STATUS_FINAL_PENDING, CostChangeService::STATUS_REJECTED), true)): ?>
    <div class="flex flex-wrap justify-end gap-2">
        <?php if ((string)$request['status'] === CostChangeService::STATUS_REJECTED): ?><a href="?r=cost_change/request&parent_id=<?php echo (int)$requestId; ?>&project_id=<?php echo (int)$request['project_id']; ?>&target_type=<?php echo h(urlencode($request['target_type'])); ?>&target_id=<?php echo h(urlencode($request['target_id'])); ?>&request_type=<?php echo h(urlencode($request['request_type'])); ?>" class="px-4 py-2 rounded-xl bg-blue-700 text-white font-bold">수정 후 재요청</a><?php endif; ?>
        <form method="post" action="?r=cost_change/cancel" onsubmit="return confirm('이 반려 요청을 취소 상태로 변경할까요?');">
            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="request_id" value="<?php echo (int)$requestId; ?>">
            <button type="submit" class="px-4 py-2 rounded-xl border border-gray-300 bg-white font-bold">요청 취소</button>
        </form>
    </div>
    <?php endif; ?>
</div>
