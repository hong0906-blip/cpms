<?php
/**
 * 비용 변경 기존자료 초기화 미리보기.
 * 원본 변경 없이 대상건수와 귀속월 예시만 표시한다.
 * PHP 5.6 호환.
 */

require_once __DIR__ . '/_common.php';
cpms_cost_change_require_login();
if (!CostChangeService::canAdmin()) {
    http_response_code(403);
    echo '403 Forbidden';
    return;
}

$pdo = Db::pdo();
$previewRows = array();
$sources = array(
    array('table'=>'cpms_material_usage', 'label'=>'자재구입비', 'date'=>'use_date', 'type'=>'material'),
    array('table'=>'cpms_equipment_usage', 'label'=>'장비비', 'date'=>'use_date', 'type'=>'equipment'),
    array('table'=>'cpms_outsourcing_costs', 'label'=>'외주비', 'date'=>'expense_date', 'type'=>'outsourcing'),
    array('table'=>'cpms_labor_force_adjustments', 'label'=>'노무비 강제입력', 'date'=>'month', 'type'=>'labor'),
    array('table'=>'cpms_daily_cost_entries', 'label'=>'기타 투입비', 'date'=>'cost_date', 'type'=>'daily_cost')
);
foreach ($sources as $source) {
    $count = 0;
    $sampleDate = '';
    $sampleYm = '';
    if ($pdo && CostChangeService::tableExists($pdo, $source['table'])) {
        try {
            $count = (int)$pdo->query("SELECT COUNT(*) FROM " . $source['table'])->fetchColumn();
            $st = $pdo->query("SELECT " . $source['date'] . " FROM " . $source['table'] . " ORDER BY id DESC LIMIT 1");
            $sampleDate = $st ? (string)$st->fetchColumn() : '';
            if ($source['type'] === 'labor') {
                $sampleYm = CostChangeService::validYm($sampleDate);
                if ($sampleYm !== '') $sampleDate .= '-01';
            } else {
                $sampleYm = CostChangeService::settlementYm($source['type'], $sampleDate);
            }
        } catch (Exception $e) {
            $count = 0;
        }
    }
    $previewRows[] = array('label'=>$source['label'], 'count'=>$count, 'sample_date'=>$sampleDate, 'sample_ym'=>$sampleYm);
}
$safetyHelper = __DIR__ . '/../safety/safety_cost_helper.php';
if (is_file($safetyHelper)) require_once $safetyHelper;
$safetyItems = function_exists('cpms_safety_cost_all_items') ? cpms_safety_cost_all_items() : array();
$safetySampleDate = '';
if (count($safetyItems) > 0) {
    $lastSafety = $safetyItems[count($safetyItems) - 1];
    $safetySampleDate = isset($lastSafety['use_date']) ? (string)$lastSafety['use_date'] : '';
}
$previewRows[] = array('label'=>'안전·보건 비용', 'count'=>count($safetyItems), 'sample_date'=>$safetySampleDate, 'sample_ym'=>CostChangeService::settlementYm('safety', $safetySampleDate));
?>

<div class="space-y-4">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
        <div>
            <h2 class="text-2xl font-extrabold">비용자료 귀속월 초기화 미리보기</h2>
            <p class="text-sm text-gray-500 mt-1">실행 시 원본 금액/날짜를 바꾸지 않고 계산된 귀속월 메타가 없는 자료에만 생성합니다.</p>
        </div>
        <a href="?r=관리&tab=cost_change" class="px-4 py-2 rounded-xl border border-gray-300 bg-white font-bold">관리로 돌아가기</a>
    </div>
    <div class="overflow-x-auto rounded-2xl border border-gray-200 bg-white">
        <table class="min-w-[720px] w-full text-sm">
            <thead class="bg-gray-50">
                <tr><th class="p-3 text-left">비용 구분</th><th class="p-3 text-right">대상건수</th><th class="p-3 text-left">최근 날짜 예시</th><th class="p-3 text-left">계산 귀속월 예시</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($previewRows as $row): ?>
                    <tr>
                        <td class="p-3 font-bold"><?php echo h($row['label']); ?></td>
                        <td class="p-3 text-right"><?php echo number_format((int)$row['count']); ?>건</td>
                        <td class="p-3"><?php echo h($row['sample_date'] !== '' ? $row['sample_date'] : '-'); ?></td>
                        <td class="p-3"><?php echo h($row['sample_ym'] !== '' ? $row['sample_ym'] . '월분' : '-'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

