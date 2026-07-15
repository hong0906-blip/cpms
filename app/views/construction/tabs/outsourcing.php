<?php
/**
 * 공사 > 외주비
 * - 월별 외주비: 노무비 연동 외주 인원 + 직접 입력 외주비
 * - 외주비 입력
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/partials/outsourcing_data_helper.php';

$outsourcingCanEdit = isset($canEdit) ? (bool)$canEdit : false;
$outsourcingTab = isset($_GET['outsourcing_tab']) ? trim((string)$_GET['outsourcing_tab']) : 'monthly';
if ($outsourcingTab !== 'input') $outsourcingTab = 'monthly';
if (!$outsourcingCanEdit && $outsourcingTab === 'input') $outsourcingTab = 'monthly';
$selectedMonth = isset($_GET['month']) ? trim((string)$_GET['month']) : '';

$months = array();
$monthLabels = array();
try {
    $startObj = new DateTime(isset($projectRow['start_date']) ? (string)$projectRow['start_date'] : date('Y-m-01'));
    $endObj = new DateTime(isset($projectRow['end_date']) ? (string)$projectRow['end_date'] : date('Y-m-t'));
    $startObj->modify('first day of this month');
    $endObj->modify('first day of this month');
    $cursor = clone $startObj;
    while ($cursor <= $endObj) {
        $ym = $cursor->format('Y-m');
        $months[] = $ym;
        $monthLabels[$ym] = $cursor->format('Y년 m월');
        $cursor->modify('+1 month');
    }
} catch (Exception $e) {
    $months = array(date('Y-m'));
    $monthLabels = array(date('Y-m') => date('Y년 m월'));
}
if (count($months) === 0) {
    $months[] = date('Y-m');
    $monthLabels[date('Y-m')] = date('Y년 m월');
}
if (!in_array($selectedMonth, $months, true)) {
    $selectedMonth = in_array(date('Y-m'), $months, true) ? date('Y-m') : $months[count($months) - 1];
}
$monthStart = $selectedMonth . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));
$projectName = isset($projectRow['name']) ? (string)$projectRow['name'] : '';

$laborOutsourcing = cpms_outsourcing_labor_company_rows_for_month($pdo, (int)$pid, $projectName, $selectedMonth);
$laborOutsourcingRows = isset($laborOutsourcing['rows']) && is_array($laborOutsourcing['rows']) ? $laborOutsourcing['rows'] : array();
$laborOutsourcingTotal = isset($laborOutsourcing['total']) ? (float)$laborOutsourcing['total'] : 0.0;
$manualMonthlyRows = cpms_outsourcing_manual_rows($pdo, (int)$pid, $monthStart, $monthEnd);
$manualAllRows = cpms_outsourcing_manual_rows($pdo, (int)$pid, '', '');
$manualMonthlyTotal = 0.0;
foreach ($manualMonthlyRows as $manualMonthlyRow) {
    $manualMonthlyTotal += isset($manualMonthlyRow['amount']) ? (float)$manualMonthlyRow['amount'] : 0.0;
}
$monthlyTotal = $laborOutsourcingTotal + $manualMonthlyTotal;

$editId = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
$editRow = null;
if ($editId > 0) {
    foreach ($manualAllRows as $oneEditRow) {
        if (isset($oneEditRow['id']) && (int)$oneEditRow['id'] === $editId) {
            $editRow = $oneEditRow;
            break;
        }
    }
}
$inputDate = $editRow && isset($editRow['expense_date']) ? (string)$editRow['expense_date'] : date('Y-m-d');
if (!$editRow && strpos($inputDate, $selectedMonth) !== 0) $inputDate = $selectedMonth . '-01';
?>

<div class="bg-white rounded-3xl border border-gray-200 p-6 shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h3 class="text-xl font-extrabold text-gray-900">외주비</h3>
            <div class="mt-1 text-sm text-gray-600">노무비에서 외주비로 선택한 인원과 직접 입력한 외주비를 함께 조회합니다.</div>
        </div>
        <?php if ($outsourcingTab === 'monthly'): ?>
        <div>
            <label class="text-xs font-bold text-gray-500">월 선택</label>
            <select class="mt-1 px-3 py-2 rounded-xl border border-gray-200 text-sm"
                    onchange="location.href='?r=공사&pid=<?php echo (int)$pid; ?>&tab=outsourcing&outsourcing_tab=monthly&month=' + encodeURIComponent(this.value)">
                <?php foreach ($months as $ym): ?>
                    <option value="<?php echo h($ym); ?>" <?php echo $ym === $selectedMonth ? 'selected' : ''; ?>><?php echo h(isset($monthLabels[$ym]) ? $monthLabels[$ym] : $ym); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
    </div>

    <div class="mt-5 flex flex-wrap gap-2">
        <a href="<?php echo h(base_url()); ?>/?r=공사&pid=<?php echo (int)$pid; ?>&tab=outsourcing&outsourcing_tab=monthly&month=<?php echo h($selectedMonth); ?>"
           class="px-4 py-2 rounded-xl border font-extrabold text-sm <?php echo $outsourcingTab === 'monthly' ? 'bg-gray-900 text-white border-gray-900' : 'bg-white text-gray-800 border-gray-300'; ?>">월별 외주비</a>
        <?php if ($outsourcingCanEdit): ?>
        <a href="<?php echo h(base_url()); ?>/?r=공사&pid=<?php echo (int)$pid; ?>&tab=outsourcing&outsourcing_tab=input&month=<?php echo h($selectedMonth); ?>"
           class="px-4 py-2 rounded-xl border font-extrabold text-sm <?php echo $outsourcingTab === 'input' ? 'bg-gray-900 text-white border-gray-900' : 'bg-white text-gray-800 border-gray-300'; ?>">외주비 입력</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($outsourcingTab === 'monthly'): ?>
<div class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-3">
    <div class="rounded-2xl border border-blue-200 bg-blue-50 p-4">
        <div class="text-xs font-bold text-blue-700">노무비 연동 외주비</div>
        <div class="mt-1 text-xl font-extrabold text-gray-900"><?php echo h(number_format($laborOutsourcingTotal)); ?>원</div>
    </div>
    <div class="rounded-2xl border border-violet-200 bg-violet-50 p-4">
        <div class="text-xs font-bold text-violet-700">직접 입력 외주비</div>
        <div class="mt-1 text-xl font-extrabold text-gray-900"><?php echo h(number_format($manualMonthlyTotal)); ?>원</div>
    </div>
    <div class="rounded-2xl border border-gray-900 bg-gray-900 p-4 text-white">
        <div class="text-xs font-bold text-gray-300">월 외주비 합계</div>
        <div class="mt-1 text-xl font-extrabold"><?php echo h(number_format($monthlyTotal)); ?>원</div>
    </div>
</div>

<div class="mt-4 bg-white rounded-3xl border border-gray-200 p-4 shadow-sm overflow-x-auto">
    <table class="min-w-[1000px] w-full border border-gray-200 text-sm">
        <thead class="bg-gray-100 text-gray-700">
        <tr>
            <th class="border border-gray-200 px-3 py-2">일자</th>
            <th class="border border-gray-200 px-3 py-2">구분</th>
            <th class="border border-gray-200 px-3 py-2">업체명</th>
            <th class="border border-gray-200 px-3 py-2">대표자명</th>
            <th class="border border-gray-200 px-3 py-2">사업자번호</th>
            <th class="border border-gray-200 px-3 py-2">연락처</th>
            <th class="border border-gray-200 px-3 py-2 text-right">금액</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($laborOutsourcingRows as $row): ?>
            <?php $workerNames = isset($row['worker_names']) && is_array($row['worker_names']) ? implode(', ', $row['worker_names']) : ''; ?>
            <tr class="bg-blue-50/40">
                <td class="border border-gray-200 px-3 py-2"><?php echo h(isset($row['expense_date']) ? $row['expense_date'] : ''); ?></td>
                <td class="border border-gray-200 px-3 py-2"><span class="px-2 py-1 rounded-lg bg-blue-100 text-blue-700 text-xs font-bold">노무비</span></td>
                <td class="border border-gray-200 px-3 py-2 font-bold" title="<?php echo h($workerNames !== '' ? '외주 선택 인원: ' . $workerNames : ''); ?>"><?php echo h(isset($row['company_name']) ? $row['company_name'] : ''); ?></td>
                <td class="border border-gray-200 px-3 py-2">-</td>
                <td class="border border-gray-200 px-3 py-2">-</td>
                <td class="border border-gray-200 px-3 py-2"><?php echo h(isset($row['contact']) && trim((string)$row['contact']) !== '' ? $row['contact'] : '-'); ?></td>
                <td class="border border-gray-200 px-3 py-2 text-right font-bold"><?php echo h(number_format(isset($row['amount']) ? (float)$row['amount'] : 0)); ?></td>
            </tr>
        <?php endforeach; ?>
        <?php foreach ($manualMonthlyRows as $row): ?>
            <tr class="bg-violet-50/40">
                <td class="border border-gray-200 px-3 py-2"><?php echo h(isset($row['expense_date']) ? $row['expense_date'] : ''); ?></td>
                <td class="border border-gray-200 px-3 py-2"><span class="px-2 py-1 rounded-lg bg-violet-100 text-violet-700 text-xs font-bold">외주비</span></td>
                <td class="border border-gray-200 px-3 py-2 font-bold"><?php echo h(isset($row['company_name']) ? $row['company_name'] : ''); ?></td>
                <td class="border border-gray-200 px-3 py-2"><?php echo h(isset($row['representative_name']) && trim((string)$row['representative_name']) !== '' ? $row['representative_name'] : '-'); ?></td>
                <td class="border border-gray-200 px-3 py-2"><?php echo h(isset($row['business_no']) && trim((string)$row['business_no']) !== '' ? $row['business_no'] : '-'); ?></td>
                <td class="border border-gray-200 px-3 py-2"><?php echo h(isset($row['contact']) && trim((string)$row['contact']) !== '' ? $row['contact'] : '-'); ?></td>
                <td class="border border-gray-200 px-3 py-2 text-right font-bold"><?php echo h(number_format(isset($row['amount']) ? (float)$row['amount'] : 0)); ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (count($laborOutsourcingRows) === 0 && count($manualMonthlyRows) === 0): ?>
            <tr><td colspan="7" class="border border-gray-200 px-3 py-8 text-center text-gray-500">선택한 월의 외주비 내역이 없습니다.</td></tr>
        <?php endif; ?>
        </tbody>
        <tfoot class="bg-gray-900 text-white font-extrabold">
        <tr><td colspan="6" class="border border-gray-700 px-3 py-2 text-center">합계</td><td class="border border-gray-700 px-3 py-2 text-right"><?php echo h(number_format($monthlyTotal)); ?></td></tr>
        </tfoot>
    </table>
</div>

<?php else: ?>
<div class="mt-4 bg-white rounded-3xl border border-gray-200 p-5 shadow-sm">
    <h4 class="text-lg font-extrabold text-gray-900"><?php echo $editRow ? '외주비 수정' : '외주비 입력'; ?></h4>
    <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/outsourcing_cost_save" class="mt-4 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3">
        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
        <input type="hidden" name="month" value="<?php echo h($selectedMonth); ?>">
        <input type="hidden" name="entry_id" value="<?php echo $editRow ? (int)$editRow['id'] : 0; ?>">
        <input type="hidden" name="category" value="외주비">
        <div><label class="text-xs font-bold text-gray-600">일자</label><input required type="date" name="expense_date" value="<?php echo h($inputDate); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-200"></div>
        <div><label class="text-xs font-bold text-gray-600">구분</label><input readonly value="외주비" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-200 bg-gray-100"></div>
        <div><label class="text-xs font-bold text-gray-600">업체명</label><input required name="company_name" value="<?php echo h($editRow && isset($editRow['company_name']) ? $editRow['company_name'] : ''); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-200"></div>
        <div><label class="text-xs font-bold text-gray-600">대표자명</label><input name="representative_name" value="<?php echo h($editRow && isset($editRow['representative_name']) ? $editRow['representative_name'] : ''); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-200"></div>
        <div><label class="text-xs font-bold text-gray-600">사업자번호</label><input name="business_no" value="<?php echo h($editRow && isset($editRow['business_no']) ? $editRow['business_no'] : ''); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-200"></div>
        <div><label class="text-xs font-bold text-gray-600">연락처</label><input name="contact" value="<?php echo h($editRow && isset($editRow['contact']) ? $editRow['contact'] : ''); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-200"></div>
        <div><label class="text-xs font-bold text-gray-600">금액</label><input required name="amount" inputmode="numeric" value="<?php echo h($editRow && isset($editRow['amount']) ? number_format((float)$editRow['amount'], 0, '.', '') : ''); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-200 text-right"></div>
        <div class="flex items-end gap-2">
            <button type="submit" name="action" value="save" class="px-5 py-2 rounded-xl bg-gray-900 text-white font-extrabold"><?php echo $editRow ? '수정 저장' : '저장'; ?></button>
            <?php if ($editRow): ?><a href="?r=공사&pid=<?php echo (int)$pid; ?>&tab=outsourcing&outsourcing_tab=input&month=<?php echo h($selectedMonth); ?>" class="px-4 py-2 rounded-xl border border-gray-300 font-bold">취소</a><?php endif; ?>
        </div>
    </form>
</div>

<div class="mt-4 bg-white rounded-3xl border border-gray-200 p-4 shadow-sm overflow-x-auto">
    <table class="min-w-[1100px] w-full border border-gray-200 text-sm">
        <thead class="bg-gray-100 text-gray-700"><tr><th class="border px-3 py-2">일자</th><th class="border px-3 py-2">구분</th><th class="border px-3 py-2">업체명</th><th class="border px-3 py-2">대표자명</th><th class="border px-3 py-2">사업자번호</th><th class="border px-3 py-2">연락처</th><th class="border px-3 py-2 text-right">금액</th><th class="border px-3 py-2">관리</th></tr></thead>
        <tbody>
        <?php foreach ($manualAllRows as $row): ?>
            <tr>
                <td class="border px-3 py-2"><?php echo h($row['expense_date']); ?></td><td class="border px-3 py-2">외주비</td><td class="border px-3 py-2 font-bold"><?php echo h($row['company_name']); ?></td><td class="border px-3 py-2"><?php echo h(isset($row['representative_name']) ? $row['representative_name'] : ''); ?></td><td class="border px-3 py-2"><?php echo h(isset($row['business_no']) ? $row['business_no'] : ''); ?></td><td class="border px-3 py-2"><?php echo h(isset($row['contact']) ? $row['contact'] : ''); ?></td><td class="border px-3 py-2 text-right font-bold"><?php echo h(number_format((float)$row['amount'])); ?></td>
                <td class="border px-3 py-2"><div class="flex justify-center gap-2"><a href="?r=공사&pid=<?php echo (int)$pid; ?>&tab=outsourcing&outsourcing_tab=input&month=<?php echo h(substr((string)$row['expense_date'], 0, 7)); ?>&edit_id=<?php echo (int)$row['id']; ?>" class="px-2 py-1 rounded-lg border border-gray-300 text-xs font-bold">수정</a><form method="post" action="<?php echo h(base_url()); ?>/?r=construction/outsourcing_cost_save" onsubmit="return confirm('이 외주비 입력 내역을 삭제할까요?');"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>"><input type="hidden" name="month" value="<?php echo h(substr((string)$row['expense_date'], 0, 7)); ?>"><input type="hidden" name="entry_id" value="<?php echo (int)$row['id']; ?>"><button name="action" value="delete" class="px-2 py-1 rounded-lg border border-red-200 text-red-600 text-xs font-bold">삭제</button></form></div></td>
            </tr>
        <?php endforeach; ?>
        <?php if (count($manualAllRows) === 0): ?><tr><td colspan="8" class="border px-3 py-8 text-center text-gray-500">입력된 외주비가 없습니다.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
