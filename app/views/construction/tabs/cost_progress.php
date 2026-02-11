<?php
/**
 * 공사 > 원가/공정 탭
 * - sub=work/cost/recogn/summary
 */
require_once __DIR__ . '/../../partials/cost_metrics.php';

$sub = isset($_GET['sub']) ? trim((string)$_GET['sub']) : 'summary';
if (!in_array($sub, array('work','cost','recogn','summary'), true)) $sub = 'summary';
$period = isset($_GET['period']) ? trim((string)$_GET['period']) : 'week';
if ($period !== 'month') $period = 'week';

$unitRows = array();
try {
    $st = $pdo->prepare("SELECT id, item_name, spec, qty, unit, unit_price FROM cpms_project_unit_prices WHERE project_id=:pid ORDER BY id DESC");
    $st->bindValue(':pid', $pid, PDO::PARAM_INT); $st->execute(); $unitRows = $st->fetchAll();
} catch (Exception $e) { $unitRows = array(); }

$summary = array();
try { $summary = cpms_project_cost_metrics($pdo, $pid, $period); } catch (Exception $e) { $summary = array(); }
?>
<div class="bg-white rounded-3xl border border-gray-100 p-5">
    <div class="flex gap-2 mb-4">
        <?php foreach (array('work'=>'실적수량 입력','cost'=>'원가 입력','recogn'=>'월별 인정기성','summary'=>'주간/월간 요약') as $k=>$lb): ?>
            <a class="px-3 py-2 rounded-2xl border <?php echo ($sub===$k)?'bg-gray-900 text-white':'bg-white'; ?>" href="<?php echo h(base_url()); ?>/?r=공사&pid=<?php echo (int)$pid; ?>&tab=cost_progress&sub=<?php echo h($k); ?>&period=<?php echo h($period); ?>"><?php echo h($lb); ?></a>
        <?php endforeach; ?>
    </div>

    <?php if ($sub === 'work'): ?>
        <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/daily_work_save" class="grid grid-cols-1 md:grid-cols-6 gap-2">
            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
            <select name="unit_price_id" class="px-3 py-2 border rounded-2xl md:col-span-2"><?php foreach($unitRows as $u): ?><option value="<?php echo (int)$u['id']; ?>"><?php echo h($u['item_name']); ?> (계약수량 <?php echo h($u['qty']); ?>)</option><?php endforeach; ?></select>
            <input type="date" name="work_date" class="px-3 py-2 border rounded-2xl" value="<?php echo h(date('Y-m-d')); ?>">
            <input type="text" name="done_qty" class="px-3 py-2 border rounded-2xl" placeholder="실적수량">
            <input type="text" name="memo" class="px-3 py-2 border rounded-2xl" placeholder="메모">
            <button class="px-3 py-2 rounded-2xl bg-gray-900 text-white">저장</button>
        </form>
    <?php endif; ?>

    <?php if ($sub === 'cost'): ?>
        <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/daily_cost_save" class="grid grid-cols-1 md:grid-cols-6 gap-2">
            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
            <input type="date" name="cost_date" class="px-3 py-2 border rounded-2xl" value="<?php echo h(date('Y-m-d')); ?>">
            <select name="cost_type" class="px-3 py-2 border rounded-2xl"><?php foreach(array('노무','자재','안전','장비','외주','기타') as $t): ?><option value="<?php echo h($t); ?>"><?php echo h($t); ?></option><?php endforeach; ?></select>
            <input type="text" name="amount" class="px-3 py-2 border rounded-2xl" placeholder="금액">
            <input type="text" name="memo" class="px-3 py-2 border rounded-2xl md:col-span-2" placeholder="메모">
            <button class="px-3 py-2 rounded-2xl bg-gray-900 text-white">저장</button>
        </form>
    <?php endif; ?>

    <?php if ($sub === 'recogn'): ?>
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