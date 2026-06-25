<?php
/**
 * 작업 탭
 * - 작업 + 내역서 항목 묶음 관리
 * - PHP 5.6 호환
 */

use App\Core\Db;

require_once __DIR__ . '/../helpers/quantity_remaining_helper.php';

$canEditWork = isset($canEdit) ? (bool)$canEdit : false;

$pdo = Db::pdo();
if (!$pdo) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">DB 연결 실패</div>';
    return;
}

if (!function_exists('cpms_work_column_exists')) {
    function cpms_work_column_exists($pdo, $table, $column) {
        try {
            $st = $pdo->prepare("SHOW COLUMNS FROM `" . $table . "` LIKE :col");
            $st->bindValue(':col', $column);
            $st->execute();
            return $st->fetch() ? true : false;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('cpms_work_format_qty0')) {
    function cpms_work_format_qty0($value) {
        if ($value === null || $value === '') return '';
        if (!is_numeric((string)$value)) return h((string)$value);
        return number_format(round((float)$value), 0);
    }
}

if (!function_exists('cpms_work_format_price1')) {
    function cpms_work_format_price1($value) {
        if ($value === null || $value === '') return '';
        if (!is_numeric((string)$value)) return h((string)$value);
        return number_format(round((float)$value), 0);
    }
}

if (!function_exists('cpms_work_unit_price_value')) {
    function cpms_work_unit_price_value($row) {
        $unitPrice = (isset($row['unit_price']) && is_numeric((string)$row['unit_price'])) ? (float)$row['unit_price'] : 0.0;
        if (abs($unitPrice) > 0.0001) return $unitPrice;
        $material = (isset($row['material_unit_price']) && is_numeric((string)$row['material_unit_price'])) ? (float)$row['material_unit_price'] : 0.0;
        $labor = (isset($row['labor_unit_price']) && is_numeric((string)$row['labor_unit_price'])) ? (float)$row['labor_unit_price'] : 0.0;
        $expense = (isset($row['expense_unit_price']) && is_numeric((string)$row['expense_unit_price'])) ? (float)$row['expense_unit_price'] : 0.0;
        return $material + $labor + $expense;
    }
}

$workItems = array();
$workTotals = array();
$unitPrices = array();
$lineCountByWork = array();
$hasMaterialUnitPrice = false;
$hasLaborUnitPrice = false;
$hasExpenseUnitPrice = false;
$hasIsActive = false;
$hasTradeGroup = false;
$hasSubTrade = false;
$hasLocationName = false;
$hasIsCurrentUnitPrice = false;

try {
    $st = $pdo->prepare("SELECT * FROM cpms_work_items WHERE project_id = :pid AND is_deleted = 0 ORDER BY sort_order ASC, id ASC");
    $st->bindValue(':pid', (int)$pid, PDO::PARAM_INT);
    $st->execute();
    $workItems = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    echo '<div class="bg-yellow-50 border border-yellow-200 text-yellow-800 rounded-2xl p-4 mb-4">작업 테이블이 준비되지 않았습니다. DB 설정에서 작업 테이블 생성/확인을 실행하세요.</div>';
}

try {
    $hasIsActive = cpms_work_column_exists($pdo, 'cpms_project_unit_prices', 'is_active');
    $hasMaterialUnitPrice = cpms_work_column_exists($pdo, 'cpms_project_unit_prices', 'material_unit_price');
    $hasLaborUnitPrice = cpms_work_column_exists($pdo, 'cpms_project_unit_prices', 'labor_unit_price');
    $hasExpenseUnitPrice = cpms_work_column_exists($pdo, 'cpms_project_unit_prices', 'expense_unit_price');
    $hasTradeGroup = cpms_work_column_exists($pdo, 'cpms_project_unit_prices', 'trade_group');
    $hasSubTrade = cpms_work_column_exists($pdo, 'cpms_project_unit_prices', 'sub_trade');
    $hasLocationName = cpms_work_column_exists($pdo, 'cpms_project_unit_prices', 'location_name');
    $hasIsCurrentUnitPrice = cpms_work_column_exists($pdo, 'cpms_project_unit_prices', 'is_current');
    $unitPrices = cpms_contract_items_with_remaining_quantity($pdo, (int)$pid, array('include_depleted' => true, 'limit' => 2000));
} catch (Exception $e) {
    $unitPrices = array();
}

try {
    $lineSelect = "SELECT l.work_id, l.unit_price_id, l.planned_qty, u.item_name, u.unit, u.qty, u.unit_price";
    $lineSelect .= $hasMaterialUnitPrice ? ", u.material_unit_price" : ", NULL AS material_unit_price";
    $lineSelect .= $hasLaborUnitPrice ? ", u.labor_unit_price" : ", NULL AS labor_unit_price";
    $lineSelect .= $hasExpenseUnitPrice ? ", u.expense_unit_price" : ", NULL AS expense_unit_price";
    $lineSelect .= " FROM cpms_work_item_lines l INNER JOIN cpms_project_unit_prices u ON u.id = l.unit_price_id WHERE u.project_id = :pid";
    if ($hasIsActive) $lineSelect .= " AND (u.is_active = 1 OR u.is_active IS NULL)";
    if ($hasIsCurrentUnitPrice) $lineSelect .= " AND (u.is_current = 1 OR u.is_current IS NULL)";
    $lineSelect .= " ORDER BY l.work_id ASC, u.id ASC";
    $stL = $pdo->prepare($lineSelect);
    $stL->bindValue(':pid', (int)$pid, PDO::PARAM_INT);
    $stL->execute();
    $lineRows = $stL->fetchAll(PDO::FETCH_ASSOC);
    if (is_array($lineRows)) {
        foreach ($lineRows as $lr) {
            $wid = isset($lr['work_id']) ? (int)$lr['work_id'] : 0;
            if ($wid <= 0) continue;
            if (!isset($lineCountByWork[$wid])) $lineCountByWork[$wid] = 0;
            $lineCountByWork[$wid]++;

            $qtyRaw = isset($lr['planned_qty']) && $lr['planned_qty'] !== null && $lr['planned_qty'] !== '' ? $lr['planned_qty'] : $lr['qty'];
            $qty = is_numeric((string)$qtyRaw) ? (float)$qtyRaw : 0.0;
            $unitPrice = cpms_work_unit_price_value($lr);
            $lineAmount = $qty * $unitPrice;
            if (!isset($workTotals[$wid])) $workTotals[$wid] = 0.0;
            $workTotals[$wid] += $lineAmount;
        }
    }
} catch (Exception $e) {
    $lineCountByWork = array();
    $workTotals = array();
}

$editingId = isset($_GET['work_id']) ? (int)$_GET['work_id'] : 0;
$editingRow = null;
$editingLineMap = array();
$editingScheduleCount = 0;
$workViewMode = (isset($_GET['work_view']) && (string)$_GET['work_view'] === 'trade') ? 'trade' : 'location';
$workViewUrlBase = base_url() . '/?r=공사&pid=' . (int)$pid . '&tab=work';
if ($editingId > 0) $workViewUrlBase .= '&work_id=' . (int)$editingId;
if ($editingId > 0) {
    foreach ($workItems as $it) {
        if ((int)$it['id'] === $editingId) { $editingRow = $it; break; }
    }
    if ($editingRow) {
        try {
            $stEL = $pdo->prepare("SELECT * FROM cpms_work_item_lines WHERE work_id = :wid");
            $stEL->bindValue(':wid', $editingId, PDO::PARAM_INT);
            $stEL->execute();
            $rows = $stEL->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $uid = (int)$r['unit_price_id'];
                if ($uid <= 0) continue;
                $editingLineMap[$uid] = $r;
            }
        } catch (Exception $e) {
            $editingLineMap = array();
        }
    }
    $editingScheduleCount = cpms_work_item_schedule_count($pdo, (int)$pid, $editingId, 0);
}
?>

<div class="bg-white rounded-3xl border border-gray-200 p-6 shadow-sm">
    <div class="flex items-center justify-between gap-3">
        <div>
            <h3 class="text-xl font-extrabold text-gray-900">작업</h3>
        </div>
    </div>

    <div class="mt-6 grid grid-cols-1 xl:grid-cols-12 gap-6">
        <div class="xl:col-span-4 border border-gray-200 rounded-2xl p-4">
            <div class="text-lg font-extrabold mb-3">작업 목록</div>
            <div class="overflow-auto max-h-[560px]">
                <table class="w-full text-sm border-collapse">
                    <thead>
                    <tr class="bg-gray-50">
                        <th class="p-2 border text-left">작업명</th>
                        <th class="p-2 border text-right">항목수</th>
                        <th class="p-2 border text-right">합계금액</th>
                        <?php if ($canEditWork): ?><th class="p-2 border text-center">작업</th><?php endif; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (count($workItems) === 0): ?>
                        <tr><td colspan="<?php echo $canEditWork ? 4 : 3; ?>" class="p-3 border text-center text-gray-500">등록된 작업이 없습니다.</td></tr>
                    <?php else: ?>
                        <?php foreach ($workItems as $w): ?>
                            <?php
                            $wid = (int)$w['id'];
                            $lineCnt = isset($lineCountByWork[$wid]) ? (int)$lineCountByWork[$wid] : 0;
                            $sumAmt = isset($workTotals[$wid]) ? (float)$workTotals[$wid] : 0.0;
                            ?>
                            <tr>
                                <td class="p-2 border"><?php echo h($w['title']); ?></td>
                                <td class="p-2 border text-right"><?php echo (int)$lineCnt; ?></td>
                                <td class="p-2 border text-right"><?php echo number_format($sumAmt, 0); ?></td>
                                <?php if ($canEditWork): ?>
                                <td class="p-2 border text-center">
                                    <a class="px-2 py-1 rounded-lg border border-gray-300 text-xs" href="<?php echo h(base_url()); ?>/?r=공사&pid=<?php echo (int)$pid; ?>&tab=work&work_id=<?php echo $wid; ?>">수정</a>
                                    <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/work_item_delete" class="inline-block" onsubmit="return confirm('작업을 삭제할까요?');">
                                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                        <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
                                        <input type="hidden" name="work_id" value="<?php echo $wid; ?>">
                                        <button type="submit" class="px-2 py-1 rounded-lg border border-rose-200 text-rose-700 bg-rose-50 text-xs">삭제</button>
                                    </form>
                                </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($canEditWork): ?>
        <div class="xl:col-span-8 border border-gray-200 rounded-2xl p-4">
            <div class="text-lg font-extrabold mb-1"><?php echo $editingRow ? '작업 수정' : '작업 추가'; ?></div>
            <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/work_item_save" class="space-y-3">
                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
                <input type="hidden" name="work_id" value="<?php echo $editingRow ? (int)$editingRow['id'] : 0; ?>">

                <div>
                    <label class="text-xs font-bold text-gray-600">작업명 *</label>
                    <input type="text" name="title" required maxlength="200" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300" value="<?php echo h($editingRow ? $editingRow['title'] : ''); ?>">
                </div>
                <div>
                    <label class="text-xs font-bold text-gray-600">작업 설명</label>
                    <textarea name="description" rows="2" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300"><?php echo h($editingRow ? $editingRow['description'] : ''); ?></textarea>
                </div>

                <div>
                    <div class="text-xs font-bold text-gray-600 mb-2">내역서 항목 묶기 (선택)</div>
                    <div class="mb-3 flex flex-wrap items-center gap-2">
                        <a href="<?php echo h($workViewUrlBase . '&work_view=location'); ?>" class="px-3 py-2 rounded-xl border text-xs font-extrabold <?php echo ($workViewMode === 'location') ? 'bg-gray-900 text-white border-gray-900' : 'bg-white text-gray-700 border-gray-300'; ?>">위치별 보기</a>
                        <a href="<?php echo h($workViewUrlBase . '&work_view=trade'); ?>" class="px-3 py-2 rounded-xl border text-xs font-extrabold <?php echo ($workViewMode === 'trade') ? 'bg-gray-900 text-white border-gray-900' : 'bg-white text-gray-700 border-gray-300'; ?>">공종별 보기</a>
                    </div>
                    <div class="mb-3 grid grid-cols-1 md:grid-cols-3 gap-2">
                        <div class="md:col-span-2">
                            <label class="text-xs font-bold text-gray-600">내역서 검색</label>
                            <input type="search" id="cpmsWorkUnitSearch" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300 text-sm" placeholder="위치, 공종, 품명, 규격, 단위로 검색">
                        </div>
                        <label class="mt-6 inline-flex items-center gap-2 text-xs font-bold text-gray-700">
                            <input type="checkbox" id="cpmsWorkShowDepleted" class="rounded border-gray-300">
                            소진 항목 보기
                        </label>
                    </div>
                    <div class="max-h-[420px] overflow-auto border border-gray-200 rounded-xl">
                        <table class="w-full text-xs border-collapse">
                            <thead class="bg-gray-50 sticky top-0">
                            <tr>
                                <th class="p-2 border text-center">선택</th>
                                <th class="p-2 border text-left">공종그룹</th>
                                <th class="p-2 border text-left">세부공종</th>
                                <th class="p-2 border text-left">위치</th>
                                <th class="p-2 border text-left">항목명</th>
                                <th class="p-2 border text-left">규격</th>
                                <th class="p-2 border text-left">단위</th>
                                <th class="p-2 border text-right">계약수량</th>
                                <th class="p-2 border text-right">사용수량</th>
                                <th class="p-2 border text-right">남은수량</th>
                                <th class="p-2 border text-right">배정수량</th>
                                <th class="p-2 border text-right">단가</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (count($unitPrices) === 0): ?>
                                <tr><td colspan="12" class="p-2 border text-center text-gray-500">내역서 항목이 없습니다.</td></tr>
                            <?php else: ?>
                                <?php $lastWorkGroupKey = ''; ?>
                                <?php foreach ($unitPrices as $u): ?>
                                    <?php
                                    $uid = (int)$u['id'];
                                    $tradeGroup = isset($u['trade_group']) ? trim((string)$u['trade_group']) : '';
                                    $subTrade = isset($u['sub_trade']) ? trim((string)$u['sub_trade']) : '';
                                    $locationName = isset($u['location_name']) ? trim((string)$u['location_name']) : '';
                                    if ($workViewMode === 'trade') {
                                        $groupLabel = '공종별 보기: ' . ($tradeGroup !== '' ? $tradeGroup : '미분류');
                                        $groupKey = 'trade:' . $tradeGroup;
                                    } else {
                                        $groupLabel = ($locationName !== '') ? ('위치별 보기: ' . $locationName) : ('공종그룹별 보기: ' . ($tradeGroup !== '' ? $tradeGroup : '미분류'));
                                        $groupKey = ($locationName !== '') ? ('loc:' . $locationName) : ('trade:' . $tradeGroup);
                                    }
                                    $sel = isset($editingLineMap[$uid]);
                                    $unitPriceDisplay = isset($u['unit_price_total']) ? (float)$u['unit_price_total'] : cpms_work_unit_price_value($u);
                                    $contractQty = isset($u['contract_quantity']) ? (float)$u['contract_quantity'] : (isset($u['qty']) ? (float)$u['qty'] : 0.0);
                                    $usedQty = isset($u['used_quantity']) ? (float)$u['used_quantity'] : 0.0;
                                    $remainingQty = isset($u['remaining_quantity']) ? (float)$u['remaining_quantity'] : ($contractQty - $usedQty);
                                    $isDepleted = ($remainingQty <= 0.0001);
                                    $availableForEdit = cpms_contract_item_remaining_quantity($pdo, (int)$pid, $uid, $editingId);
                                    $maxPlannedQty = ($editingScheduleCount > 0) ? ($availableForEdit / $editingScheduleCount) : $availableForEdit;
                                    if ($maxPlannedQty < 0) $maxPlannedQty = 0;
                                    $plannedValue = '';
                                    if ($sel) {
                                        $linePlanned = isset($editingLineMap[$uid]['planned_qty']) ? $editingLineMap[$uid]['planned_qty'] : null;
                                        $plannedValue = ($linePlanned !== null && $linePlanned !== '') ? (string)$linePlanned : (string)$contractQty;
                                    } else if ($remainingQty > 0.0001) {
                                        $plannedValue = (string)$remainingQty;
                                    }
                                    $rowText = $tradeGroup . ' ' . $subTrade . ' ' . $locationName . ' ' . (isset($u['item_name']) ? (string)$u['item_name'] : '') . ' ' . (isset($u['spec']) ? (string)$u['spec'] : '') . ' ' . (isset($u['unit']) ? (string)$u['unit'] : '') . ' ' . (string)$unitPriceDisplay;
                                    $rowClass = 'cpms-work-unit-row';
                                    if ($isDepleted) $rowClass .= ' cpms-work-depleted-row bg-gray-50 text-gray-400';
                                    if ($isDepleted && !$sel) $rowClass .= ' hidden';
                                    ?>
                                    <?php if ($groupKey !== $lastWorkGroupKey): ?>
                                        <tr class="cpms-work-group-row bg-slate-100 text-slate-800" data-search="<?php echo h($groupLabel); ?>" data-group-key="<?php echo h($groupKey); ?>">
                                            <td colspan="12" class="p-2 border font-extrabold"><?php echo h($groupLabel); ?></td>
                                        </tr>
                                        <?php $lastWorkGroupKey = $groupKey; ?>
                                    <?php endif; ?>
                                    <tr class="<?php echo h($rowClass); ?>" data-search="<?php echo h($rowText); ?>" data-depleted="<?php echo $isDepleted ? '1' : '0'; ?>">
                                        <td class="p-2 border text-center"><input type="checkbox" name="selected_unit_price_ids[]" value="<?php echo $uid; ?>" <?php echo $sel ? 'checked' : ''; ?>></td>
                                        <td class="p-2 border"><?php echo h($tradeGroup); ?></td>
                                        <td class="p-2 border"><?php echo h($subTrade); ?></td>
                                        <td class="p-2 border"><?php echo h($locationName); ?></td>
                                        <td class="p-2 border"><?php echo h($u['item_name']); ?></td>
                                        <td class="p-2 border"><?php echo h(isset($u['spec']) ? $u['spec'] : ''); ?></td>
                                        <td class="p-2 border"><?php echo h($u['unit']); ?></td>
                                        <td class="p-2 border text-right"><?php echo cpms_work_format_qty0($contractQty); ?></td>
                                        <td class="p-2 border text-right"><?php echo cpms_work_format_qty0($usedQty); ?></td>
                                        <td class="p-2 border text-right">
                                            <?php if ($isDepleted): ?>
                                                <span class="inline-flex items-center rounded-lg bg-gray-200 px-2 py-1 font-extrabold text-gray-600">소진됨 / 남은수량 0</span>
                                            <?php else: ?>
                                                <span class="<?php echo ($remainingQty <= 5 && $remainingQty > 0) ? 'text-amber-700 font-extrabold' : 'font-bold text-gray-900'; ?>"><?php echo cpms_work_format_qty0($remainingQty); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-2 border text-right">
                                            <input type="number" step="0.0001" min="0" max="<?php echo h((string)$maxPlannedQty); ?>" name="planned_qty_map[<?php echo $uid; ?>]" value="<?php echo h($plannedValue); ?>" class="w-24 px-2 py-1 rounded-lg border border-gray-300 text-right">
                                        </td>
                                        <td class="p-2 border text-right"><?php echo cpms_work_format_price1($unitPriceDisplay); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <button type="submit" class="px-4 py-2 rounded-xl bg-gray-900 text-white font-bold text-sm">저장</button>
                    <a href="<?php echo h(base_url()); ?>/?r=공사&pid=<?php echo (int)$pid; ?>&tab=work" class="px-4 py-2 rounded-xl border border-gray-300 text-sm">새로작성</a>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>
<script>
(function () {
    var searchInput = document.getElementById('cpmsWorkUnitSearch');
    var showDepleted = document.getElementById('cpmsWorkShowDepleted');
    var rows = Array.prototype.slice.call(document.querySelectorAll('.cpms-work-unit-row'));
    var groupRows = Array.prototype.slice.call(document.querySelectorAll('.cpms-work-group-row'));

    function normalizeText(value) {
        value = String(value || '').toLowerCase();
        value = value.replace(/,/g, ' ');
        value = value.replace(/\s+/g, ' ');
        return value;
    }

    function refreshRows() {
        var q = searchInput ? normalizeText(searchInput.value) : '';
        var includeDepleted = showDepleted && showDepleted.checked;
        for (var i = 0; i < rows.length; i++) {
            var row = rows[i];
            var haystack = normalizeText(row.getAttribute('data-search') || '');
            var depleted = row.getAttribute('data-depleted') === '1';
            var checked = !!row.querySelector('input[type="checkbox"]:checked');
            var match = (q === '' || haystack.indexOf(q) !== -1);
            var visible = match && (!depleted || includeDepleted || checked);
            if (visible) row.classList.remove('hidden');
            else row.classList.add('hidden');
        }
        for (var g = 0; g < groupRows.length; g++) {
            var group = groupRows[g];
            var groupKey = group.getAttribute('data-group-key') || '';
            var hasVisibleChild = false;
            var next = group.nextElementSibling;
            while (next && !next.classList.contains('cpms-work-group-row')) {
                if (next.classList.contains('cpms-work-unit-row') && !next.classList.contains('hidden')) {
                    hasVisibleChild = true;
                    break;
                }
                next = next.nextElementSibling;
            }
            if (groupKey !== '' && hasVisibleChild) group.classList.remove('hidden');
            else group.classList.add('hidden');
        }
    }

    if (searchInput) searchInput.addEventListener('input', refreshRows);
    if (showDepleted) showDepleted.addEventListener('change', refreshRows);
    for (var j = 0; j < rows.length; j++) {
        rows[j].addEventListener('change', refreshRows);
    }
    refreshRows();
})();
</script>
