<?php
/**
 * 파일: app/views/construction/tabs/outsourcing.php
 * 공사 > 외주비
 * - 월별 외주비: 노무비 월별 비율 연동 인원 + 직접 입력 외주비
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
$manualCostIds = array();
foreach ($manualAllRows as $manualFileRow) {
    if (isset($manualFileRow['id'])) $manualCostIds[] = (int)$manualFileRow['id'];
}
$outsourcingFilesByCost = cpms_outsourcing_files_by_cost_ids($pdo, $manualCostIds);
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
            <div class="mt-1 text-sm text-gray-600">인원별 월 외주비 반영금액과 직접 입력한 외주비를 함께 조회합니다.</div>
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
                <td class="border border-gray-200 px-3 py-2"><span class="px-2 py-1 rounded-lg bg-blue-100 text-blue-700 text-xs font-bold">인원 외주비</span></td>
                <td class="border border-gray-200 px-3 py-2 font-bold" title="<?php echo h($workerNames !== '' ? '외주비 반영 인원: ' . $workerNames : ''); ?>"><?php echo h(isset($row['company_name']) ? $row['company_name'] : ''); ?></td>
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
    <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/outsourcing_cost_save" class="mt-4 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3" id="outsourcingCostForm" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
        <input type="hidden" name="month" value="<?php echo h($selectedMonth); ?>">
        <input type="hidden" name="entry_id" value="<?php echo $editRow ? (int)$editRow['id'] : 0; ?>">
        <input type="hidden" name="category" value="외주비">
        <div class="md:col-span-2 xl:col-span-4 bg-gray-50 border border-gray-200 rounded-xl p-3 outsourcing-vendor-search-wrap">
            <label class="text-sm font-bold text-gray-700" for="outsourcingVendorSearch">업체명 검색 자동완성</label>
            <input type="text" id="outsourcingVendorSearch" class="mt-1 w-full px-3 py-2 border rounded-xl bg-white js-outsourcing-vendor-search" placeholder="업체명 2글자 이상 입력" lang="ko" inputmode="text" autocomplete="off" aria-autocomplete="list" aria-controls="outsourcingVendorSuggestList" aria-expanded="false">
            <div id="outsourcingVendorSuggestList" class="outsourcing-vendor-suggest-list mt-2 hidden border border-gray-200 rounded-xl bg-white max-h-48 overflow-auto" role="listbox"></div>
        </div>
        <div><label class="text-xs font-bold text-gray-600">일자</label><input required type="date" name="expense_date" value="<?php echo h($inputDate); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-200"></div>
        <div><label class="text-xs font-bold text-gray-600">구분</label><input readonly value="외주비" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-200 bg-gray-100"></div>
        <div><label class="text-xs font-bold text-gray-600">업체명</label><input required name="company_name" value="<?php echo h($editRow && isset($editRow['company_name']) ? $editRow['company_name'] : ''); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-200" lang="ko" inputmode="text" autocomplete="off"></div>
        <div><label class="text-xs font-bold text-gray-600">대표자명</label><input name="representative_name" value="<?php echo h($editRow && isset($editRow['representative_name']) ? $editRow['representative_name'] : ''); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-200"></div>
        <div><label class="text-xs font-bold text-gray-600">사업자번호</label><input name="business_no" value="<?php echo h($editRow && isset($editRow['business_no']) ? $editRow['business_no'] : ''); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-200"></div>
        <div><label class="text-xs font-bold text-gray-600">연락처</label><input name="contact" value="<?php echo h($editRow && isset($editRow['contact']) ? $editRow['contact'] : ''); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-200"></div>
        <div><label class="text-xs font-bold text-gray-600">금액</label><input required name="amount" inputmode="numeric" value="<?php echo h($editRow && isset($editRow['amount']) ? number_format((float)$editRow['amount'], 0, '.', '') : ''); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-200 text-right"></div>
        <div class="md:col-span-2 xl:col-span-4">
            <label class="text-xs font-bold text-gray-600">비고</label>
            <textarea name="memo" rows="3" maxlength="500" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-200" placeholder="외주비 관련 비고를 입력하세요."><?php echo h($editRow && isset($editRow['memo']) ? $editRow['memo'] : ''); ?></textarea>
        </div>
        <div class="md:col-span-2 xl:col-span-4 rounded-xl border border-gray-200 bg-gray-50 p-3">
            <label class="text-xs font-bold text-gray-600">파일 업로드</label>
            <input type="file" name="attachments[]" multiple accept=".pdf,.xls,.xlsx,.xlsm,.xlsb,.csv,application/pdf,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel.sheet.macroenabled.12,application/vnd.ms-excel.sheet.binary.macroenabled.12,text/csv" class="mt-2 block w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm">
            <div class="mt-1 text-xs text-gray-500">PDF·엑셀(XLS, XLSX, XLSM, XLSB, CSV), 파일당 20MB 이하 · 여러 파일 선택 가능</div>
            <?php if ($editRow && isset($outsourcingFilesByCost[(int)$editRow['id']])): ?>
                <div class="mt-2 flex flex-wrap gap-2">
                    <?php foreach ($outsourcingFilesByCost[(int)$editRow['id']] as $attachedFile): ?>
                        <a class="rounded-lg border border-blue-200 bg-white px-2 py-1 text-xs font-bold text-blue-700" href="<?php echo h(base_url()); ?>/?r=construction/outsourcing_file_download&id=<?php echo (int)$attachedFile['id']; ?>"><?php echo h($attachedFile['original_name']); ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="flex items-end gap-2">
            <button type="submit" name="action" value="save" class="px-5 py-2 rounded-xl bg-gray-900 text-white font-extrabold"><?php echo $editRow ? '수정 저장' : '저장'; ?></button>
            <?php if ($editRow): ?><a href="?r=공사&pid=<?php echo (int)$pid; ?>&tab=outsourcing&outsourcing_tab=input&month=<?php echo h($selectedMonth); ?>" class="px-4 py-2 rounded-xl border border-gray-300 font-bold">취소</a><?php endif; ?>
        </div>
    </form>
</div>

<script>
(function(){
    var searchInput = document.getElementById('outsourcingVendorSearch');
    var form = document.getElementById('outsourcingCostForm');
    var suggestList = document.getElementById('outsourcingVendorSuggestList');
    var searchTimer = null;
    var requestSequence = 0;

    if (!searchInput || !form || !suggestList) return;

    function hideSuggestions(){
        suggestList.innerHTML = '';
        if (suggestList.className.indexOf('hidden') === -1) suggestList.className += ' hidden';
        suggestList.style.display = 'none';
        searchInput.setAttribute('aria-expanded', 'false');
    }

    function showSuggestions(){
        suggestList.className = suggestList.className.replace(/\bhidden\b/g, '').replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '');
        suggestList.style.display = 'block';
        searchInput.setAttribute('aria-expanded', 'true');
    }

    function fillVendorFields(item){
        if (!item) return;
        if (form.elements['company_name']) form.elements['company_name'].value = item.vendor_name || '';
        if (form.elements['representative_name']) form.elements['representative_name'].value = item.representative || '';
        if (form.elements['business_no']) form.elements['business_no'].value = item.biz_no || '';
        if (form.elements['contact']) form.elements['contact'].value = item.phone || '';
    }

    function renderSuggestions(items){
        suggestList.innerHTML = '';
        if (!items || !items.length) {
            var empty = document.createElement('div');
            empty.className = 'px-3 py-2 text-sm text-gray-500';
            empty.textContent = '검색 결과 없음';
            suggestList.appendChild(empty);
            showSuggestions();
            return;
        }

        for (var i = 0; i < items.length; i++) {
            (function(item){
                var button = document.createElement('button');
                var detail = [];
                button.type = 'button';
                button.className = 'block w-full text-left px-3 py-2 border-b last:border-b-0 hover:bg-blue-50';
                button.setAttribute('role', 'option');
                button.setAttribute('data-outsourcing-vendor-item', '1');
                if (item.representative) detail[detail.length] = item.representative;
                if (item.phone) detail[detail.length] = item.phone;
                button.textContent = (item.vendor_name || '') + (detail.length ? ' (' + detail.join(' / ') + ')' : '');
                button.vendorData = item;
                button.addEventListener('mousedown', function(event){
                    event.preventDefault();
                });
                suggestList.appendChild(button);
            })(items[i]);
        }
        showSuggestions();
    }

    searchInput.addEventListener('input', function(){
        var query = (searchInput.value || '').replace(/^\s+|\s+$/g, '');
        var currentSequence;
        if (searchTimer) clearTimeout(searchTimer);
        requestSequence++;
        currentSequence = requestSequence;
        if (query.length < 2) {
            hideSuggestions();
            return;
        }

        searchTimer = setTimeout(function(){
            var xhr = new XMLHttpRequest();
            xhr.open('GET', '<?php echo h(base_url()); ?>/?r=construction/material_vendor_search&q=' + encodeURIComponent(query), true);
            xhr.onreadystatechange = function(){
                var items = arrayFallback();
                if (xhr.readyState !== 4 || currentSequence !== requestSequence) return;
                if (xhr.status === 200) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        items = response && response.items ? response.items : arrayFallback();
                    } catch (error) {
                        items = arrayFallback();
                    }
                }
                renderSuggestions(items);
            };
            xhr.send();
        }, 250);
    });

    function arrayFallback(){
        return [];
    }

    suggestList.addEventListener('click', function(event){
        var target = event.target;
        while (target && target !== suggestList && (!target.getAttribute || target.getAttribute('data-outsourcing-vendor-item') !== '1')) {
            target = target.parentNode;
        }
        if (!target || target === suggestList) return;
        fillVendorFields(target.vendorData || {});
        searchInput.value = target.vendorData && target.vendorData.vendor_name ? target.vendorData.vendor_name : '';
        hideSuggestions();
        if (form.elements['company_name']) form.elements['company_name'].focus();
    });

    document.addEventListener('click', function(event){
        var wrap = searchInput.parentNode;
        if (wrap && !wrap.contains(event.target)) hideSuggestions();
    });

    searchInput.addEventListener('keydown', function(event){
        if (event.keyCode === 27) hideSuggestions();
    });
})();
</script>

<div class="mt-4 bg-white rounded-3xl border border-gray-200 p-4 shadow-sm overflow-x-auto">
    <table class="min-w-[1450px] w-full border border-gray-200 text-sm">
        <thead class="bg-gray-100 text-gray-700"><tr><th class="border px-3 py-2">일자</th><th class="border px-3 py-2">구분</th><th class="border px-3 py-2">업체명</th><th class="border px-3 py-2">대표자명</th><th class="border px-3 py-2">사업자번호</th><th class="border px-3 py-2">연락처</th><th class="border px-3 py-2 text-right">금액</th><th class="border px-3 py-2">비고</th><th class="border px-3 py-2">파일</th><th class="border px-3 py-2">관리</th></tr></thead>
        <tbody>
        <?php foreach ($manualAllRows as $row): ?>
            <tr>
                <td class="border px-3 py-2"><?php echo h($row['expense_date']); ?></td><td class="border px-3 py-2">외주비</td><td class="border px-3 py-2 font-bold"><?php echo h($row['company_name']); ?></td><td class="border px-3 py-2"><?php echo h(isset($row['representative_name']) ? $row['representative_name'] : ''); ?></td><td class="border px-3 py-2"><?php echo h(isset($row['business_no']) ? $row['business_no'] : ''); ?></td><td class="border px-3 py-2"><?php echo h(isset($row['contact']) ? $row['contact'] : ''); ?></td><td class="border px-3 py-2 text-right font-bold"><?php echo h(number_format((float)$row['amount'])); ?></td>
                <td class="border px-3 py-2 whitespace-pre-line"><?php echo h(isset($row['memo']) && trim((string)$row['memo']) !== '' ? $row['memo'] : '-'); ?></td>
                <td class="border px-3 py-2">
                    <?php $rowFiles = isset($outsourcingFilesByCost[(int)$row['id']]) ? $outsourcingFilesByCost[(int)$row['id']] : array(); ?>
                    <?php if (count($rowFiles) <= 0): ?>
                        <span class="text-gray-400">-</span>
                    <?php else: ?>
                        <div class="flex flex-col gap-1">
                            <?php foreach ($rowFiles as $rowFile): ?>
                                <a class="text-xs font-bold text-blue-700 hover:underline" href="<?php echo h(base_url()); ?>/?r=construction/outsourcing_file_download&id=<?php echo (int)$rowFile['id']; ?>"><?php echo h($rowFile['original_name']); ?></a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </td>
                <td class="border px-3 py-2"><div class="flex justify-center gap-2"><a href="?r=공사&pid=<?php echo (int)$pid; ?>&tab=outsourcing&outsourcing_tab=input&month=<?php echo h(substr((string)$row['expense_date'], 0, 7)); ?>&edit_id=<?php echo (int)$row['id']; ?>" class="px-2 py-1 rounded-lg border border-gray-300 text-xs font-bold">수정</a><form method="post" action="<?php echo h(base_url()); ?>/?r=construction/outsourcing_cost_save" onsubmit="return confirm('이 외주비 입력 내역을 삭제할까요?');"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>"><input type="hidden" name="month" value="<?php echo h(substr((string)$row['expense_date'], 0, 7)); ?>"><input type="hidden" name="entry_id" value="<?php echo (int)$row['id']; ?>"><button name="action" value="delete" class="px-2 py-1 rounded-lg border border-red-200 text-red-600 text-xs font-bold">삭제</button></form></div></td>
            </tr>
        <?php endforeach; ?>
        <?php if (count($manualAllRows) === 0): ?><tr><td colspan="10" class="border px-3 py-8 text-center text-gray-500">입력된 외주비가 없습니다.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
