<?php
/**
 * 공사 > 원가/공정 탭
 * - sub=work/cost/recogn/summary
 */
require_once __DIR__ . '/../../partials/cost_metrics.php';
require_once __DIR__ . '/../../../services/CostChangeService.php';

use App\Services\CostChangeService;

$canEditCostProgress = isset($canEdit) ? (bool)$canEdit : false;
$sub = isset($_GET['sub']) ? trim((string)$_GET['sub']) : 'summary';
if (!in_array($sub, array('work','cost','recogn','summary'), true)) $sub = 'summary';
if (!$canEditCostProgress && $sub !== 'summary') $sub = 'summary';
$period = isset($_GET['period']) ? trim((string)$_GET['period']) : 'week';
if ($period !== 'month') $period = 'week';

$unitRows = array();
$dailyCostRows = array();
try {
    $st = $pdo->prepare("SELECT id, item_name, spec, qty, unit, unit_price FROM cpms_project_unit_prices WHERE project_id=:pid ORDER BY id DESC");
    $st->bindValue(':pid', $pid, PDO::PARAM_INT); $st->execute(); $unitRows = $st->fetchAll();
} catch (Exception $e) { $unitRows = array(); }
try {
    $stDailyCosts = $pdo->prepare("SELECT * FROM cpms_daily_cost_entries WHERE project_id=:pid ORDER BY cost_date DESC,id DESC LIMIT 300");
    $stDailyCosts->execute(array(':pid'=>(int)$pid));
    $dailyCostRows = $stDailyCosts->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $dailyCostRows = array(); }

$summary = array();
try { $summary = cpms_project_cost_metrics($pdo, $pid, $period); } catch (Exception $e) { $summary = array(); }
?>
<div class="bg-white rounded-3xl border border-gray-100 p-5">
    <div class="flex gap-2 mb-4">
        <?php foreach (array('work'=>'실적수량 입력','cost'=>'원가 입력','recogn'=>'월별 인정기성','summary'=>'주간/월간 요약') as $k=>$lb): ?>
            <?php if (!$canEditCostProgress && $k !== 'summary') continue; ?>
            <a class="px-3 py-2 rounded-2xl border <?php echo ($sub===$k)?'bg-gray-900 text-white':'bg-white'; ?>" href="<?php echo h(base_url()); ?>/?r=공사&pid=<?php echo (int)$pid; ?>&tab=cost_progress&sub=<?php echo h($k); ?>&period=<?php echo h($period); ?>"><?php echo h($lb); ?></a>
        <?php endforeach; ?>
    </div>

    <?php if ($sub === 'work' && $canEditCostProgress): ?>
        <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/daily_work_save" class="grid grid-cols-1 md:grid-cols-6 gap-2">
            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
            <select name="unit_price_id" class="px-3 py-2 border rounded-2xl md:col-span-2"><?php foreach($unitRows as $u): ?><option value="<?php echo (int)$u['id']; ?>"><?php echo h($u['item_name']); ?> (계약수량 <?php echo h($u['qty']); ?>)</option><?php endforeach; ?></select>
            <input type="date" name="work_date" class="px-3 py-2 border rounded-2xl" value="<?php echo h(date('Y-m-d')); ?>">
            <input type="text" name="done_qty" class="px-3 py-2 border rounded-2xl" placeholder="실적수량">
            <input type="text" name="memo" class="px-3 py-2 border rounded-2xl" placeholder="메모">
            <button class="px-3 py-2 rounded-2xl bg-gray-900 text-white">저장</button>
        </form>
    <?php endif; ?>

    <?php if ($sub === 'cost' && $canEditCostProgress): ?>
        <div class="mb-3 flex flex-wrap justify-end gap-2">
            <a href="?r=cost_change/request&project_id=<?php echo (int)$pid; ?>&target_type=daily_cost&request_type=ADD&return_url=<?php echo rawurlencode('?r=공사&pid=' . (int)$pid . '&tab=cost_progress&sub=cost'); ?>" class="px-4 py-2 rounded-xl border border-amber-300 bg-amber-50 text-amber-800 font-extrabold text-sm">마감월 추가 승인 요청</a>
        </div>
        <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/daily_cost_save" class="grid grid-cols-1 md:grid-cols-6 gap-2">
            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
            <input type="date" name="cost_date" class="px-3 py-2 border rounded-2xl" value="<?php echo h(date('Y-m-d')); ?>">
            <select name="cost_type" class="px-3 py-2 border rounded-2xl"><?php foreach(array('노무','자재','안전','장비','외주','기타') as $t): ?><option value="<?php echo h($t); ?>"><?php echo h($t); ?></option><?php endforeach; ?></select>
            <input type="text" name="amount" class="px-3 py-2 border rounded-2xl" placeholder="금액">
            <input type="text" name="memo" class="px-3 py-2 border rounded-2xl md:col-span-2" placeholder="메모">
            <button class="px-3 py-2 rounded-2xl bg-gray-900 text-white">저장</button>
        </form>
        <div class="mt-5 overflow-x-auto rounded-2xl border border-gray-200">
            <table class="min-w-[900px] w-full text-sm">
                <thead class="bg-gray-50"><tr><th class="px-3 py-2 text-left">사용일자</th><th class="px-3 py-2 text-left">구분</th><th class="px-3 py-2 text-right">금액</th><th class="px-3 py-2 text-left">비고</th><th class="px-3 py-2 text-center">변경 관리</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                <?php foreach ($dailyCostRows as $dailyCostRow): ?>
                    <?php
                    $dailyCostId = isset($dailyCostRow['id']) ? (int)$dailyCostRow['id'] : 0;
                    $dailyCostDate = isset($dailyCostRow['cost_date']) ? (string)$dailyCostRow['cost_date'] : '';
                    $dailyLockType = (isset($dailyCostRow['cost_type']) && (string)$dailyCostRow['cost_type'] === '노무') ? 'labor' : 'daily_cost';
                    $dailySettlementYm = CostChangeService::effectiveSettlementYm($pdo, 'daily_cost', (string)$dailyCostId, $dailyLockType, $dailyCostDate);
                    $dailyLock = CostChangeService::lockInfo($dailyLockType, $dailyCostDate, $dailySettlementYm, date('Y-m-d'));
                    $dailyActiveRequest = $dailyCostId > 0 ? CostChangeService::activeRequest($pdo, 'daily_cost', (string)$dailyCostId) : null;
                    $dailyHistoryCount = $dailyCostId > 0 ? CostChangeService::historyCount($pdo, 'daily_cost', (string)$dailyCostId) : 0;
                    $dailyLatestRequest = $dailyHistoryCount > 0 ? CostChangeService::latestRequest($pdo, 'daily_cost', (string)$dailyCostId) : null;
                    $dailyReturnUrl = '?r=공사&pid=' . (int)$pid . '&tab=cost_progress&sub=cost';
                    ?>
                    <tr class="<?php echo !empty($dailyLock['locked']) ? 'bg-gray-50' : ''; ?>">
                        <td class="px-3 py-2"><?php echo h($dailyCostDate); ?></td>
                        <td class="px-3 py-2"><?php echo h(isset($dailyCostRow['cost_type']) ? $dailyCostRow['cost_type'] : ''); ?></td>
                        <td class="px-3 py-2 text-right font-bold"><?php echo number_format(isset($dailyCostRow['amount']) ? (float)$dailyCostRow['amount'] : 0); ?>원</td>
                        <td class="px-3 py-2"><?php echo h(isset($dailyCostRow['memo']) ? $dailyCostRow['memo'] : ''); ?></td>
                        <td class="px-3 py-2 text-center">
                            <?php if (is_array($dailyActiveRequest)): ?>
                                <span class="text-xs font-extrabold text-amber-700"><?php echo h(CostChangeService::statusLabel($dailyActiveRequest['status'])); ?></span>
                                <a href="?r=cost_change/detail&id=<?php echo (int)$dailyActiveRequest['id']; ?>" class="ml-1 text-xs text-blue-700 underline">상세</a>
                            <?php elseif (!empty($dailyLock['locked'])): ?>
                                <div class="flex flex-wrap justify-center gap-1">
                                <?php foreach (array('MODIFY'=>'수정 승인 요청','MONTH_MOVE'=>'귀속월 변경 요청','DELETE'=>'삭제 승인 요청') as $requestCode=>$requestLabel): ?>
                                    <a href="?r=cost_change/request&project_id=<?php echo (int)$pid; ?>&target_type=daily_cost&target_id=<?php echo (int)$dailyCostId; ?>&request_type=<?php echo h($requestCode); ?>&return_url=<?php echo rawurlencode($dailyReturnUrl); ?>" class="px-2 py-1 rounded-lg border border-amber-200 bg-amber-50 text-amber-800 text-[11px] font-bold"><?php echo h($requestLabel); ?></a>
                                <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span class="text-xs font-bold text-emerald-700">현재 입력월</span>
                            <?php endif; ?>
                            <?php if ($dailyHistoryCount > 0): ?><a href="?r=cost_change/history&target_type=daily_cost&target_id=<?php echo (int)$dailyCostId; ?>&project_id=<?php echo (int)$pid; ?>" class="ml-1 text-[11px] font-bold text-blue-700 underline"><?php echo h(CostChangeService::historyBadgeLabel($dailyLatestRequest, $dailyHistoryCount)); ?></a><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (count($dailyCostRows) === 0): ?><tr><td colspan="5" class="px-3 py-6 text-center text-gray-500">입력된 기타 투입비가 없습니다.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($sub === 'recogn' && $canEditCostProgress): ?>
        <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/recognized_save" class="grid grid-cols-1 md:grid-cols-4 gap-2">
            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
            <input type="month" name="ym" class="px-3 py-2 border rounded-2xl" value="<?php echo h(date('Y-m')); ?>">
            <input type="text" name="recognized_cum_amount" class="px-3 py-2 border rounded-2xl" placeholder="인정기성 누계">
            <input type="text" name="memo" class="px-3 py-2 border rounded-2xl" placeholder="메모(선택)">
            <button class="px-3 py-2 rounded-2xl bg-gray-900 text-white">저장</button>
        </form>
    <?php endif; ?>

    <?php if ($sub === 'summary'): ?>
        <form method="get" class="mb-4"><input type="hidden" name="r" value="공사"><input type="hidden" name="pid" value="<?php echo (int)$pid; ?>"><input type="hidden" name="tab" value="cost_progress"><input type="hidden" name="sub" value="summary"><select name="period" onchange="this.form.submit()" class="px-3 py-2 border rounded-2xl"><option value="week" <?php echo ($period==='week')?'selected':''; ?>>week</option><option value="month" <?php echo ($period==='month')?'selected':''; ?>>month</option></select></form>
        <div class="text-sm">공정률: <b><?php echo number_format(isset($summary['progress_rate'])?$summary['progress_rate']:0,2); ?>%</b> / 내부기성: <b><?php echo number_format(isset($summary['internal_progress_amount'])?$summary['internal_progress_amount']:0); ?>원</b></div>
        <div class="text-sm">원가율: <b><?php echo h(isset($summary['cost_rate_label'])?$summary['cost_rate_label']:'-'); ?></b> <?php if(!empty($summary['cost_rate_note'])): ?>(<?php echo h($summary['cost_rate_note']); ?>)<?php endif; ?> / 실제원가: <b><?php echo number_format(isset($summary['actual_total_cost'])?$summary['actual_total_cost']:0); ?>원</b></div>
        <div class="text-sm mt-2">노무 계획/실적/차이: <?php echo number_format($summary['planned_labor']); ?> / <?php echo number_format($summary['actual_labor']); ?> / <?php echo number_format($summary['variance_labor']); ?></div>
        <div class="text-sm">자재 계획/실적/차이: <?php echo number_format($summary['planned_material']); ?> / <?php echo number_format($summary['actual_material']); ?> / <?php echo number_format($summary['variance_material']); ?></div>
        <div class="text-sm">안전 계획/실적/차이: <?php echo number_format($summary['planned_safety']); ?> / <?php echo number_format($summary['actual_safety']); ?> / <?php echo number_format($summary['variance_safety']); ?></div>
        <div class="text-sm mt-2">누계 내부기성 - 누계 인정기성: <b><?php echo number_format($summary['cum_gap']); ?>원</b></div>
    <?php endif; ?>
</div>
