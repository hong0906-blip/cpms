<?php
/**
 * 작업 탭
 * - 작업내용 레이어 추가
 * - 작업 + 내역서 항목 묶음 관리
 * - PHP 5.6 호환
 */

use App\Core\Db;

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
    $sqlUnit = "SELECT id, item_name, spec, unit, qty, unit_price";
    $sqlUnit .= $hasMaterialUnitPrice ? ", material_unit_price" : ", NULL AS material_unit_price";
    $sqlUnit .= $hasLaborUnitPrice ? ", labor_unit_price" : ", NULL AS labor_unit_price";
    $sqlUnit .= $hasExpenseUnitPrice ? ", expense_unit_price" : ", NULL AS expense_unit_price";
    $sqlUnit .= " FROM cpms_project_unit_prices WHERE project_id = :pid";
    if ($hasIsActive) $sqlUnit .= " AND (is_active = 1 OR is_active IS NULL)";
    $sqlUnit .= " ORDER BY id ASC LIMIT 2000";
    $stU = $pdo->prepare($sqlUnit);
    $stU->bindValue(':pid', (int)$pid, PDO::PARAM_INT);
    $stU->execute();
    $unitPrices = $stU->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $unitPrices = array();
}

try {
    $lineSelect = "SELECT l.work_id, l.unit_price_id, l.planned_qty, u.item_name, u.unit, u.qty, u.unit_price";
    $lineSelect .= $hasMaterialUnitPrice ? ", u.material_unit_price" : ", NULL AS material_unit_price";
    $lineSelect .= $hasLaborUnitPrice ? ", u.labor_unit_price" : ", NULL AS labor_unit_price";
    $lineSelect .= $hasExpenseUnitPrice ? ", u.expense_unit_price" : ", NULL AS expense_unit_price";
    $lineSelect .= " FROM cpms_work_item_lines l INNER JOIN cpms_project_unit_prices u ON u.id = l.unit_price_id WHERE u.project_id = :pid ORDER BY l.work_id ASC, u.id ASC";
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
}
?>

<div class="bg-white rounded-3xl border border-gray-200 p-6 shadow-sm">
    <div class="flex items-center justify-between gap-3">
        <div>
            <h3 class="text-xl font-extrabold text-gray-900">작업</h3>
            <div class="text-sm text-gray-600 mt-1">작업내용을 만들고 내역서 항목을 여러 개 묶어서 공정표와 연결합니다.</div>
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
            <div class="text-xs text-gray-500 mb-3">변경 지점 주석: 작업내용 레이어 추가</div>
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
                    <div class="max-h-[420px] overflow-auto border border-gray-200 rounded-xl">
                        <table class="w-full text-xs border-collapse">
                            <thead class="bg-gray-50 sticky top-0">
                            <tr>
                                <th class="p-2 border text-center">선택</th>
                                <th class="p-2 border text-left">항목명</th>
                                <th class="p-2 border text-left">규격</th>
                                <th class="p-2 border text-right">기본수량</th>
                                <th class="p-2 border text-right">단가계</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (count($unitPrices) === 0): ?>
                                <tr><td colspan="5" class="p-2 border text-center text-gray-500">내역서 항목이 없습니다.</td></tr>
                            <?php else: ?>
                                <?php foreach ($unitPrices as $u): ?>
                                    <?php
                                    $uid = (int)$u['id'];
                                    $sel = isset($editingLineMap[$uid]);
                                    $unitPriceDisplay = cpms_work_unit_price_value($u);
                                    ?>
                                    <tr>
                                        <td class="p-2 border text-center"><input type="checkbox" name="selected_unit_price_ids[]" value="<?php echo $uid; ?>" <?php echo $sel ? 'checked' : ''; ?>></td>
                                        <td class="p-2 border"><?php echo h($u['item_name']); ?></td>
                                        <td class="p-2 border"><?php echo h(isset($u['spec']) ? $u['spec'] : ''); ?></td>
                                        <td class="p-2 border text-right"><?php echo cpms_work_format_qty0($u['qty']); ?> <?php echo h($u['unit']); ?></td>
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
