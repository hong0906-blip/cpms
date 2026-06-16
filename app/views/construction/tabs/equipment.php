<?php
/**
 * 공사 > 장비 탭
 * - 서브탭: 장비월별(monthly), 장비입력(input)
 * - 월 선택(ym=YYYY-MM) 공통 적용
 * - 월별 양식(전월 26~말일 + 선택월 1~25, 2줄 출력) 출력
 * - PHP 5.6 호환
 */

use App\Core\Db;
require_once __DIR__ . '/../partials/equipment_gongsu_approval_helper.php';
require_once __DIR__ . '/../partials/master_dedupe_helper.php';
require_once __DIR__ . '/../partials/project_month_options_helper.php';

$canEditEquipment = isset($canEdit) ? (bool)$canEdit : false;

$pdo = Db::pdo();
if (!$pdo) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">DB 연결 실패</div>';
    return;
}
cpms_equipment_gongsu_ensure_schema($pdo);

$equipTab = isset($_GET['equip_tab']) ? trim((string)$_GET['equip_tab']) : 'monthly';
if ($equipTab !== 'monthly' && $equipTab !== 'input') {
    $equipTab = 'monthly';
}
if (!$canEditEquipment && $equipTab === 'input') {
    $equipTab = 'monthly';
}

$ym = isset($_GET['ym']) ? trim((string)$_GET['ym']) : date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
    $ym = date('Y-m');
}
$year = (int)substr($ym, 0, 4);
$month = (int)substr($ym, 5, 2);
if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
    $ym = date('Y-m');
    $year = (int)substr($ym, 0, 4);
    $month = (int)substr($ym, 5, 2);
}

$monthData = cpms_construction_project_month_options($pdo, (int)$pid, $ym);
$monthOptions = (isset($monthData['months']) && is_array($monthData['months'])) ? $monthData['months'] : array();
$ym = isset($monthData['selected_ym']) ? (string)$monthData['selected_ym'] : $ym;
$monthSelectMessage = isset($monthData['message']) ? (string)$monthData['message'] : '';
$year = (int)substr($ym, 0, 4);
$month = (int)substr($ym, 5, 2);

$baseUrl = base_url() . '/?r=공사&pid=' . (int)$pid . '&tab=equipment';
// 달력 전월/현월 계산 수정
$currFirst = new DateTime($ym . '-01');
$prevFirst = clone $currFirst;
$prevFirst->modify('-1 month');
$prevYm = $prevFirst->format('Y-m');
$prevLastDay = (int)$prevFirst->format('t');
$monthlyStart = $prevYm . '-26';
$monthlyEnd = $ym . '-25';

$items = array();
$itemMap = array();
$usageRows = array();
$usageByEquipment = array();
$usageByDate = array();
$pendingByUsage = array();

try {
    $stItem = $pdo->prepare("SELECT * FROM cpms_equipment_items WHERE project_id = :pid AND is_deleted = 0 ORDER BY category ASC, vendor_name ASC, spec ASC, id ASC");
    $stItem->bindValue(':pid', (int)$pid, PDO::PARAM_INT);
    $stItem->execute();
    $items = $stItem->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as $it) {
        $eid = (int)$it['id'];
        $itemMap[$eid] = $it;
        $usageByEquipment[$eid] = array();
    }

    if (count($items) > 0) {
        $stUsage = $pdo->prepare("SELECT u.*, i.category, i.vendor_name, i.spec
            FROM cpms_equipment_usage u
            JOIN cpms_equipment_items i ON i.id = u.equipment_id
            WHERE u.project_id = :pid
              AND i.is_deleted = 0
              AND u.use_date BETWEEN :s AND :e
            ORDER BY i.category ASC, i.vendor_name ASC, i.spec ASC, u.use_date ASC");
        $stUsage->bindValue(':pid', (int)$pid, PDO::PARAM_INT);
        $stUsage->bindValue(':s', $monthlyStart);
        $stUsage->bindValue(':e', $monthlyEnd);
        $stUsage->execute();
        $usageRows = $stUsage->fetchAll(PDO::FETCH_ASSOC);

        foreach ($usageRows as $usageIndex => $ur) {
            $eid = (int)$ur['equipment_id'];
            $d = (string)$ur['use_date'];
            $workUnit = isset($ur['work_unit']) ? (float)$ur['work_unit'] : 1.0;
            if ($workUnit <= 0) $workUnit = 1.0;
            $storedAmount = isset($ur['amount']) ? (float)$ur['amount'] : 0.0;
            $rateSnapshot = isset($ur['base_rate_snapshot']) ? (float)$ur['base_rate_snapshot'] : 0.0;
            $masterBaseRate = (isset($itemMap[$eid]) && isset($itemMap[$eid]['base_rate'])) ? (float)$itemMap[$eid]['base_rate'] : 0.0;
            if ($rateSnapshot <= 0 && $masterBaseRate > 0) $rateSnapshot = $masterBaseRate;
            $ur['_work_unit'] = $workUnit;
            $ur['_base_rate_snapshot'] = $rateSnapshot;
            if ($storedAmount > 0) {
                $ur['_calc_amount'] = $storedAmount;
            } else if ($rateSnapshot > 0) {
                $ur['_calc_amount'] = $workUnit * $rateSnapshot;
            } else {
                $ur['_calc_amount'] = 0.0;
            }
            if (!isset($usageByEquipment[$eid])) $usageByEquipment[$eid] = array();
            $usageByEquipment[$eid][$d] = $ur;
            $usageRows[$usageIndex] = $ur;
            if (!isset($usageByDate[$d])) $usageByDate[$d] = 0.0;
            $usageByDate[$d] += $workUnit;
        }

        $stPending = $pdo->prepare("SELECT *
            FROM cpms_equipment_gongsu_overrides
            WHERE project_id = :pid
              AND status = 'pending'
              AND use_date BETWEEN :s AND :e
            ORDER BY id DESC");
        $stPending->bindValue(':pid', (int)$pid, PDO::PARAM_INT);
        $stPending->bindValue(':s', $monthlyStart);
        $stPending->bindValue(':e', $monthlyEnd);
        $stPending->execute();
        $pendingRows = $stPending->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($pendingRows)) {
            foreach ($pendingRows as $pr) {
                $pendingByUsage[(int)$pr['equipment_usage_id']] = $pr;
            }
        }
    }
} catch (Exception $e) {
    echo '<div class="bg-yellow-50 border border-yellow-200 text-yellow-800 rounded-2xl p-4 mb-4">장비 테이블이 아직 준비되지 않았습니다. DB 설정에서 "3) 장비 입력 테이블 생성/확인"을 먼저 실행하세요.</div>';
    $items = array();
    $itemMap = array();
    $usageRows = array();
    $usageByEquipment = array();
    $usageByDate = array();
    $pendingByUsage = array();
    $vendorPresets = array();    
}

$dateSlots = array();
for ($d = 26; $d <= 31; $d++) {
    $valid = ($d <= $prevLastDay);
    $dateSlots[] = array(
        'label' => '전월 ' . $d,
        'date' => $prevYm . '-' . sprintf('%02d', $d),
        'valid' => $valid,
    );
}
for ($d = 1; $d <= 25; $d++) {
    $valid = true;
    $dateSlots[] = array(
        'label' => $d,
        'date' => $ym . '-' . sprintf('%02d', $d),
        'valid' => $valid,
    );
}

// 장비월별 날짜 2줄
$splitIndex = 0;
$preferredFirstRowCount = (7 + 15); // 전월26~말일 + 현월 1~15
if ($preferredFirstRowCount >= count($dateSlots)) {
    $splitIndex = (int)ceil(count($dateSlots) / 2);
} else {
    $splitIndex = $preferredFirstRowCount;
}
$dateSlotsRow1 = array_slice($dateSlots, 0, $splitIndex);
$dateSlotsRow2 = array_slice($dateSlots, $splitIndex);

function equipment_money($v)
{
    return number_format((float)$v, 0);
}
function equipment_gongsu($v)
{
    $n = (float)$v;
    if (abs($n - round($n)) < 0.001) return number_format($n, 0);
    return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
}
function equipment_render_gongsu_cell($usageRow, $pendingByUsage, $item)
{
    global $canEditEquipment;
    if (!is_array($usageRow)) {
        return '<td class="border p-1 text-center text-gray-300">-</td>';
    }
    $unit = isset($usageRow['_work_unit']) ? (float)$usageRow['_work_unit'] : 1.0;
    if ($unit <= 0) {
        return '<td class="border p-1 text-center text-gray-300">-</td>';
    }
    $usageId = isset($usageRow['id']) ? (int)$usageRow['id'] : 0;
    $projectId = isset($usageRow['project_id']) ? (int)$usageRow['project_id'] : 0;
    $date = isset($usageRow['use_date']) ? (string)$usageRow['use_date'] : '';
    $equipmentName = trim((string)(isset($item['spec']) ? $item['spec'] : ''));
    if ($equipmentName === '') $equipmentName = trim((string)(isset($item['vendor_name']) ? $item['vendor_name'] : ''));
    $vendorName = trim((string)(isset($item['vendor_name']) ? $item['vendor_name'] : ''));
    $pending = ($usageId > 0 && isset($pendingByUsage[$usageId]) && is_array($pendingByUsage[$usageId])) ? $pendingByUsage[$usageId] : null;

    $html = '<td class="border p-1 text-center">';
    if (!$canEditEquipment) {
        $html .= '<span class="inline-flex min-h-[28px] items-center justify-center px-1 font-bold text-gray-800">' . h(equipment_gongsu($unit)) . '</span>';
        if ($pending) {
            $newValue = isset($pending['new_value']) ? (float)$pending['new_value'] : 0.0;
            $html .= '<div class="mt-1 text-[11px] text-amber-700 font-bold">' . h(equipment_gongsu($newValue)) . ' 승인대기</div>';
        }
        $html .= '</td>';
        return $html;
    }
    $html .= '<button type="button" class="px-2 py-1 rounded-lg border border-blue-200 bg-blue-50 text-blue-700 font-bold hover:bg-blue-100"';
    $html .= ' data-equipment-gongsu-cell="1"';
    $html .= ' data-project-id="' . $projectId . '"';
    $html .= ' data-usage-id="' . $usageId . '"';
    $html .= ' data-equipment-name="' . h($equipmentName) . '"';
    $html .= ' data-vendor-name="' . h($vendorName) . '"';
    $html .= ' data-use-date="' . h($date) . '"';
    $html .= ' data-old-value="' . h(number_format($unit, 2, '.', '')) . '"';
    $html .= '>' . h(equipment_gongsu($unit)) . '</button>';
    if ($pending) {
        $newValue = isset($pending['new_value']) ? (float)$pending['new_value'] : 0.0;
        $html .= '<div class="mt-1 text-[11px] text-amber-700 font-bold">' . h(equipment_gongsu($newValue)) . ' 승인대기</div>';
    }
    $html .= '</td>';
    return $html;
}
function equipment_render_grouped_gongsu_cell($slotBundle, $pendingByUsage, $item)
{
    if (!is_array($slotBundle)) {
        return '<td class="border p-1 text-center text-gray-300">-</td>';
    }
    $rows = isset($slotBundle['rows']) && is_array($slotBundle['rows']) ? $slotBundle['rows'] : array();
    $totalUnit = isset($slotBundle['total_unit']) ? (float)$slotBundle['total_unit'] : 0.0;
    if (count($rows) === 1) {
        return equipment_render_gongsu_cell($rows[0], $pendingByUsage, $item);
    }
    if ($totalUnit <= 0) {
        return '<td class="border p-1 text-center text-gray-300">-</td>';
    }
    $html = '<td class="border p-1 text-center">';
    $html .= '<div class="font-bold text-gray-800">' . h(equipment_gongsu($totalUnit)) . '</div>';
    $html .= '<div class="text-[10px] text-amber-700">중복 묶음</div>';
    $html .= '</td>';
    return $html;
}
function equipment_display_group_key($row)
{
    $vendorKey = cpms_master_dedupe_text_key(isset($row['vendor_name']) ? $row['vendor_name'] : '');
    $specKey = cpms_master_dedupe_text_key(isset($row['spec']) ? $row['spec'] : '');
    if ($vendorKey === '' && $specKey === '') {
        return 'id:' . (isset($row['id']) ? (int)$row['id'] : 0);
    }
    return (isset($row['project_id']) ? (int)$row['project_id'] : 0) . '|vendor:' . $vendorKey . '|spec:' . $specKey;
}

$displayItems = array();
foreach ($items as $it) {
    $groupKey = equipment_display_group_key($it);
    if (!isset($displayItems[$groupKey])) {
        $displayItems[$groupKey] = array(
            'group_key' => $groupKey,
            'category' => isset($it['category']) ? (string)$it['category'] : '',
            'vendor_name' => isset($it['vendor_name']) ? (string)$it['vendor_name'] : '',
            'spec' => isset($it['spec']) ? (string)$it['spec'] : '',
            'representative' => isset($it['representative']) ? (string)$it['representative'] : '',
            'phone' => isset($it['phone']) ? (string)$it['phone'] : '',
            'biz_no' => isset($it['biz_no']) ? (string)$it['biz_no'] : '',
            'base_rate' => isset($it['base_rate']) ? (float)$it['base_rate'] : 0.0,
            'remark' => isset($it['remark']) ? (string)$it['remark'] : '',
            'item_ids' => array(),
            'slot_usage' => array()
        );
    }
    $displayItems[$groupKey]['category'] = cpms_merge_first_non_empty($displayItems[$groupKey]['category'], isset($it['category']) ? $it['category'] : '');
    $displayItems[$groupKey]['vendor_name'] = cpms_merge_first_non_empty($displayItems[$groupKey]['vendor_name'], isset($it['vendor_name']) ? $it['vendor_name'] : '');
    $displayItems[$groupKey]['spec'] = cpms_merge_first_non_empty($displayItems[$groupKey]['spec'], isset($it['spec']) ? $it['spec'] : '');
    if ((float)$displayItems[$groupKey]['base_rate'] <= 0 && isset($it['base_rate']) && (float)$it['base_rate'] > 0) {
        $displayItems[$groupKey]['base_rate'] = (float)$it['base_rate'];
    }
    $displayItems[$groupKey]['representative'] = cpms_merge_first_non_empty($displayItems[$groupKey]['representative'], isset($it['representative']) ? $it['representative'] : '');
    $displayItems[$groupKey]['phone'] = cpms_merge_first_non_empty($displayItems[$groupKey]['phone'], isset($it['phone']) ? $it['phone'] : '');
    $displayItems[$groupKey]['biz_no'] = cpms_merge_first_non_empty($displayItems[$groupKey]['biz_no'], isset($it['biz_no']) ? $it['biz_no'] : '');
    $displayItems[$groupKey]['remark'] = cpms_merge_first_non_empty($displayItems[$groupKey]['remark'], isset($it['remark']) ? $it['remark'] : '');
    $displayItems[$groupKey]['item_ids'][count($displayItems[$groupKey]['item_ids'])] = isset($it['id']) ? (int)$it['id'] : 0;
}
foreach ($displayItems as $groupKey => $displayItem) {
    foreach ($dateSlots as $slot) {
        $displayItems[$groupKey]['slot_usage'][$slot['date']] = array('rows' => array(), 'total_unit' => 0.0, 'total_amount' => 0.0);
    }
}
foreach ($usageRows as $ur) {
    $equipmentId = isset($ur['equipment_id']) ? (int)$ur['equipment_id'] : 0;
    if (!isset($itemMap[$equipmentId])) continue;
    $groupKey = equipment_display_group_key($itemMap[$equipmentId]);
    if (!isset($displayItems[$groupKey])) continue;
    $useDate = isset($ur['use_date']) ? (string)$ur['use_date'] : '';
    if ($useDate === '') continue;
    if (!isset($displayItems[$groupKey]['slot_usage'][$useDate])) {
        $displayItems[$groupKey]['slot_usage'][$useDate] = array('rows' => array(), 'total_unit' => 0.0, 'total_amount' => 0.0);
    }
    $slotUnit = isset($ur['_work_unit']) ? (float)$ur['_work_unit'] : 1.0;
    $slotAmount = isset($ur['_calc_amount']) ? (float)$ur['_calc_amount'] : 0.0;
    $displayItems[$groupKey]['slot_usage'][$useDate]['rows'][count($displayItems[$groupKey]['slot_usage'][$useDate]['rows'])] = $ur;
    $displayItems[$groupKey]['slot_usage'][$useDate]['total_unit'] += $slotUnit;
    $displayItems[$groupKey]['slot_usage'][$useDate]['total_amount'] += $slotAmount;
}

$equipmentExcelToken = isset($_GET['equipment_excel_token']) ? trim((string)$_GET['equipment_excel_token']) : '';
$equipmentExcelPreview = null;
$equipmentExcelRows = array();
$equipmentExcelSummary = array();
$equipmentExcelWarnings = array();
$equipmentExcelSaveableCount = 0;
if ($equipmentExcelToken !== '' && isset($_SESSION['equipment_excel_preview'][$equipmentExcelToken]) && is_array($_SESSION['equipment_excel_preview'][$equipmentExcelToken])) {
    $candidateEquipmentPreview = $_SESSION['equipment_excel_preview'][$equipmentExcelToken];
    if (isset($candidateEquipmentPreview['project_id']) && (int)$candidateEquipmentPreview['project_id'] === (int)$pid) {
        $equipmentExcelPreview = $candidateEquipmentPreview;
        $equipmentExcelRows = (isset($equipmentExcelPreview['rows']) && is_array($equipmentExcelPreview['rows'])) ? $equipmentExcelPreview['rows'] : array();
        $equipmentExcelSummary = (isset($equipmentExcelPreview['summary']) && is_array($equipmentExcelPreview['summary'])) ? $equipmentExcelPreview['summary'] : array();
        $equipmentExcelWarnings = (isset($equipmentExcelPreview['warnings']) && is_array($equipmentExcelPreview['warnings'])) ? $equipmentExcelPreview['warnings'] : array();
        foreach ($equipmentExcelRows as $equipmentExcelRow) {
            if (is_array($equipmentExcelRow) && isset($equipmentExcelRow['saveable']) && (int)$equipmentExcelRow['saveable'] === 1) {
                $equipmentExcelSaveableCount++;
            }
        }
    } else {
        $equipmentExcelToken = '';
    }
}
?>

<div class="bg-white rounded-3xl border border-gray-200 p-6 shadow-sm">
    <div class="flex flex-col lg:flex-row lg:items-end gap-3 justify-between">
        <div>
            <h3 class="text-xl font-extrabold text-gray-900">장비</h3>
            <div class="text-sm text-gray-600 mt-1">월별 양식 출력 + 월별 입력</div>
        </div>

        <form method="get" action="" class="flex items-end gap-2">
            <input type="hidden" name="r" value="공사">
            <input type="hidden" name="pid" value="<?php echo (int)$pid; ?>">
            <input type="hidden" name="tab" value="equipment">
            <input type="hidden" name="equip_tab" value="<?php echo h($equipTab); ?>">
            <div>
                <label class="text-xs font-bold text-gray-600">월 선택</label>
                <select name="ym" class="mt-1 px-3 py-2 rounded-xl border border-gray-300 text-sm">
                    <?php foreach ($monthOptions as $opt): ?>
                        <option value="<?php echo h($opt); ?>" <?php echo ($opt === $ym) ? 'selected' : ''; ?>>
                            <?php echo h(substr($opt, 0, 4) . '년 ' . substr($opt, 5, 2) . '월'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($monthSelectMessage !== ''): ?>
                    <div class="text-xs text-gray-500 mt-1"><?php echo h($monthSelectMessage); ?></div>
                <?php endif; ?>
            </div>
            <button type="submit" class="px-4 py-2 rounded-xl bg-gray-900 text-white font-bold text-sm">적용</button>
        </form>
    </div>

    <div class="mt-4 flex flex-wrap gap-2">
        <a href="<?php echo h($baseUrl . '&equip_tab=monthly&ym=' . urlencode($ym)); ?>"
           class="px-4 py-2 rounded-xl border font-bold text-sm <?php echo ($equipTab === 'monthly') ? 'bg-gray-900 text-white border-gray-900' : 'bg-white text-gray-800 border-gray-300'; ?>">장비월별</a>
        <?php if ($canEditEquipment): ?>
            <a href="<?php echo h($baseUrl . '&equip_tab=input&ym=' . urlencode($ym)); ?>"
               class="px-4 py-2 rounded-xl border font-bold text-sm <?php echo ($equipTab === 'input') ? 'bg-gray-900 text-white border-gray-900' : 'bg-white text-gray-800 border-gray-300'; ?>">장비입력</a>
            <a href="<?php echo h($baseUrl . '&equip_tab=input&ym=' . urlencode($ym)); ?>#equipmentExcelUpload"
               class="px-4 py-2 rounded-xl border font-bold text-sm bg-white text-blue-700 border-blue-200 hover:bg-blue-50">엑셀 업로드</a>
        <?php endif; ?>
    </div>

    <?php if ($equipTab === 'input'): ?>
        <div class="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="border border-gray-200 rounded-2xl p-4">
                <div class="text-lg font-extrabold mb-3">새작성</div>
                <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/equipment_item_save" class="space-y-3" id="equipmentCreateForm">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
                    <input type="hidden" name="equip_tab" value="input">
                    <input type="hidden" name="ym" value="<?php echo h($ym); ?>">

                    <!-- 업체 검색 자동완성/공용프리셋 -->
                    <div class="bg-gray-50 border border-gray-200 rounded-xl p-3 vendor-search-wrap">
                        <label class="text-sm font-bold text-gray-700">업체명 검색 자동완성</label>
                        <input type="text" class="mt-1 w-full px-3 py-2 border rounded-xl bg-white js-equipment-vendor-search" placeholder="업체명 2글자 이상 입력">
                        <div class="vendor-suggest-list mt-2 hidden border border-gray-200 rounded-xl bg-white max-h-48 overflow-auto"></div>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <input type="text" name="category" class="px-3 py-2 border rounded-xl" placeholder="구분" required>
                        <input type="text" name="vendor_name" class="px-3 py-2 border rounded-xl" placeholder="업체명" required>
                        <input type="text" name="spec" class="px-3 py-2 border rounded-xl" placeholder="규격(직접입력)">
                        <input type="text" name="representative" class="px-3 py-2 border rounded-xl" placeholder="대표자명">
                        <input type="text" name="phone" class="px-3 py-2 border rounded-xl" placeholder="전화번호">
                        <input type="text" name="biz_no" class="px-3 py-2 border rounded-xl" placeholder="사업자등록번호">
                        <input type="number" step="0.01" min="0" name="base_rate" class="px-3 py-2 border rounded-xl" placeholder="기본단가">
                        <input type="text" name="remark" class="px-3 py-2 border rounded-xl" placeholder="비고">
                    </div>

                    <!-- 장비 달력(전월/현월 2달력) -->
                    <div class="border border-gray-200 rounded-xl p-3" data-calendar-wrapper data-ym="<?php echo h($ym); ?>" data-prev-ym="<?php echo h($prevYm); ?>" data-target="equipmentCreateDateInputs" data-chip-target="equipmentCreateDateChips" data-prev-grid-target="equipmentCreateCalPrev" data-curr-grid-target="equipmentCreateCalCurr">
                        <div class="flex items-center justify-between gap-2 mb-2">
                            <label class="text-sm font-bold text-gray-700">사용일자(<?php echo h($prevYm); ?> 26일~<?php echo h($ym); ?> 25일, 복수 선택)</label>
                            <button type="button" class="px-3 py-1 rounded-lg border border-gray-300 text-sm" data-toggle-calendar>날짜 선택</button>
                        </div>
                        <div class="hidden border border-gray-200 rounded-lg p-2 bg-gray-50" data-calendar-box>
                            <div class="grid grid-cols-1 xl:grid-cols-2 gap-3">
                                <div id="equipmentCreateCalPrev"></div>
                                <div id="equipmentCreateCalCurr"></div>
                            </div>
                        </div>
                        <div id="equipmentCreateDateChips" class="mt-2 flex flex-wrap gap-2"></div>
                        <div id="equipmentCreateDateInputs"></div>
                    </div>

                    <button type="submit" class="px-4 py-2 rounded-xl bg-blue-600 text-white font-bold">저장</button>
                </form>
            </div>

            <div class="border border-gray-200 rounded-2xl p-4">
                <div class="text-lg font-extrabold mb-3">등록 장비 + 사용일자 추가</div>
                <div class="max-h-[520px] overflow-auto">
                    <table class="w-full text-sm border-collapse">
                        <thead>
                        <tr class="bg-gray-50">
                            <th class="p-2 border text-left">구분</th>
                            <th class="p-2 border text-left">업체명</th>
                            <th class="p-2 border text-left">규격</th>
                            <th class="p-2 border text-right">기본단가</th>
                            <th class="p-2 border text-left">사용일자 추가</th>
                            <th class="p-2 border text-center">관리</th>                            
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (count($items) === 0): ?>
                            <tr><td colspan="6" class="p-3 border text-center text-gray-500">등록된 장비가 없습니다.</td></tr>
                        <?php else: ?>
                            <?php foreach ($items as $it): ?>
                                <tr>
                                    <td class="p-2 border"><?php echo h($it['category']); ?></td>
                                    <td class="p-2 border"><?php echo h($it['vendor_name']); ?></td>
                                    <td class="p-2 border"><?php echo h($it['spec']); ?></td>
                                    <td class="p-2 border text-right"><?php echo equipment_money($it['base_rate']); ?></td>
                                    <td class="p-2 border">
                                        <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/equipment_usage_save" class="space-y-2" data-usage-form>
                                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                            <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
                                            <input type="hidden" name="equipment_id" value="<?php echo (int)$it['id']; ?>">
                                            <input type="hidden" name="equip_tab" value="input">
                                            <input type="hidden" name="ym" value="<?php echo h($ym); ?>">

                                            <!-- 장비 달력(전월/현월 2달력) -->
                                            <div class="border border-gray-200 rounded-lg p-2" data-calendar-wrapper data-ym="<?php echo h($ym); ?>" data-prev-ym="<?php echo h($prevYm); ?>" data-target="usageDateInputs_<?php echo (int)$it['id']; ?>" data-chip-target="usageDateChips_<?php echo (int)$it['id']; ?>" data-prev-grid-target="usageDatePrev_<?php echo (int)$it['id']; ?>" data-curr-grid-target="usageDateCurr_<?php echo (int)$it['id']; ?>">
                                                <div class="flex items-center justify-between">
                                                    <div class="text-xs text-gray-700 font-bold">날짜(<?php echo h($prevYm); ?> 26일~<?php echo h($ym); ?> 25일)</div>
                                                    <button type="button" class="px-2 py-1 rounded border text-xs" data-toggle-calendar>날짜 선택</button>
                                                </div>
                                                <div class="hidden mt-2 border border-gray-200 rounded p-2 bg-gray-50" data-calendar-box>
                                                    <div class="grid grid-cols-1 xl:grid-cols-2 gap-2">
                                                        <div id="usageDatePrev_<?php echo (int)$it['id']; ?>"></div>
                                                        <div id="usageDateCurr_<?php echo (int)$it['id']; ?>"></div>
                                                    </div>
                                                </div>
                                                <div id="usageDateChips_<?php echo (int)$it['id']; ?>" class="mt-2 flex flex-wrap gap-1"></div>
                                                <div id="usageDateInputs_<?php echo (int)$it['id']; ?>"></div>
                                            </div>
                                            <button type="submit" class="px-3 py-1 rounded-lg bg-gray-800 text-white">추가</button>
                                        </form>
                                    </td>
                                    <td class="p-2 border text-center">
                                        <!-- 등록장비 삭제 -->
                                        <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/equipment_item_delete" onsubmit="return confirm('삭제할까요?');">
                                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                            <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
                                            <input type="hidden" name="equipment_id" value="<?php echo (int)$it['id']; ?>">
                                            <input type="hidden" name="equip_tab" value="input">
                                            <input type="hidden" name="ym" value="<?php echo h($ym); ?>">
                                            <button type="submit" class="px-2 py-1 rounded border border-red-300 text-red-600 text-xs">삭제</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="equipmentExcelUpload" class="mt-6 border border-gray-200 rounded-2xl p-4">
            <div class="flex flex-col gap-1 mb-4">
                <div class="text-lg font-extrabold">월별 장비비 엑셀 업로드</div>
                <div class="text-xs text-gray-600">장비비 양식의 2.장비비 또는 장비비 시트를 읽고, J~AE 날짜별 금액을 장비입력에 등록합니다.</div>
            </div>

            <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/equipment_excel_preview" enctype="multipart/form-data" class="space-y-3">
                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
                <input type="hidden" name="ym" value="<?php echo h($ym); ?>">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="text-sm font-bold text-gray-700">기준년월</label>
                        <select name="base_ym" class="mt-1 w-full px-3 py-2 border rounded-xl" required>
                            <?php foreach ($monthOptions as $opt): ?>
                                <option value="<?php echo h($opt); ?>" <?php echo ($opt === $ym) ? 'selected' : ''; ?>>
                                    <?php echo h(substr($opt, 0, 4) . '년 ' . substr($opt, 5, 2) . '월'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-700">엑셀 파일</label>
                        <input type="file" name="equipment_excel_file" accept=".xlsx" class="mt-1 block w-full text-sm border rounded-xl px-3 py-2" required>
                    </div>
                </div>

                <div class="rounded-xl bg-gray-50 border border-gray-200 p-3 text-xs text-gray-600 leading-5">
                    등록 전 미리보기에서 신규, 기존 업데이트, 중복 제외, 오류를 확인할 수 있습니다. 현재 서버에서는 .xlsx 형식을 사용해주세요.
                </div>
                <button type="submit" class="px-4 py-2 rounded-xl bg-gray-900 text-white font-bold">업로드/미리보기</button>
            </form>

            <?php if ($equipmentExcelPreview !== null): ?>
                <?php
                $equipmentExcelYm = isset($equipmentExcelPreview['ym']) ? (string)$equipmentExcelPreview['ym'] : $ym;
                $equipmentExcelOriginalName = isset($equipmentExcelPreview['original_name']) ? (string)$equipmentExcelPreview['original_name'] : '';
                $equipmentExcelSheetName = isset($equipmentExcelPreview['sheet_name']) ? (string)$equipmentExcelPreview['sheet_name'] : '';
                ?>
                <div class="mt-5 border-t border-gray-200 pt-4">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2 mb-3">
                        <div>
                            <div class="font-extrabold text-gray-900">미리보기</div>
                            <div class="text-xs text-gray-500">
                                <?php echo h($equipmentExcelYm); ?> 기준 · <?php echo h($equipmentExcelOriginalName); ?> · 시트: <?php echo h($equipmentExcelSheetName); ?>
                            </div>
                        </div>
                        <div class="text-xs text-gray-600">
                            총 <?php echo (int)(isset($equipmentExcelSummary['total_count']) ? $equipmentExcelSummary['total_count'] : 0); ?>건 /
                            등록 가능 <?php echo (int)(isset($equipmentExcelSummary['valid_count']) ? $equipmentExcelSummary['valid_count'] : 0); ?>건 /
                            오류 <?php echo (int)(isset($equipmentExcelSummary['error_count']) ? $equipmentExcelSummary['error_count'] : 0); ?>건 /
                            중복 <?php echo (int)(isset($equipmentExcelSummary['duplicate_count']) ? $equipmentExcelSummary['duplicate_count'] : 0); ?>건 /
                            업데이트 <?php echo (int)(isset($equipmentExcelSummary['update_count']) ? $equipmentExcelSummary['update_count'] : 0); ?>건 /
                            총액 <?php echo equipment_money(isset($equipmentExcelSummary['total_amount']) ? $equipmentExcelSummary['total_amount'] : 0); ?>원
                        </div>
                    </div>

                    <?php if (count($equipmentExcelWarnings) > 0): ?>
                        <div class="mb-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800 leading-5">
                            <?php foreach ($equipmentExcelWarnings as $warningMessage): ?>
                                <div><?php echo h($warningMessage); ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/equipment_excel_save">
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                        <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
                        <input type="hidden" name="ym" value="<?php echo h($equipmentExcelYm); ?>">
                        <input type="hidden" name="equipment_excel_token" value="<?php echo h($equipmentExcelToken); ?>">

                        <div class="overflow-auto max-h-[560px] border border-gray-200 rounded-xl">
                            <table class="min-w-[1500px] w-full text-xs border-collapse">
                                <thead>
                                <tr class="bg-gray-50">
                                    <th class="p-2 border text-center">등록</th>
                                    <th class="p-2 border text-left">상태</th>
                                    <th class="p-2 border text-left">날짜</th>
                                    <th class="p-2 border text-left">장비구분</th>
                                    <th class="p-2 border text-left">업체명</th>
                                    <th class="p-2 border text-left">사업자등록번호</th>
                                    <th class="p-2 border text-left">규격</th>
                                    <th class="p-2 border text-right">기본단가</th>
                                    <th class="p-2 border text-right">금액</th>
                                    <th class="p-2 border text-left">비고</th>
                                    <th class="p-2 border text-left">오류/경고</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($equipmentExcelRows as $excelIdx => $excelRow): ?>
                                    <?php
                                    $excelStatusType = isset($excelRow['status_type']) ? (string)$excelRow['status_type'] : 'new';
                                    $excelSaveable = (isset($excelRow['saveable']) && (int)$excelRow['saveable'] === 1);
                                    $excelRowClass = '';
                                    if ($excelStatusType === 'error') $excelRowClass = 'bg-red-50';
                                    else if ($excelStatusType === 'duplicate') $excelRowClass = 'bg-gray-100';
                                    else if ($excelStatusType === 'update') $excelRowClass = 'bg-blue-50';
                                    $excelMessages = array();
                                    if (isset($excelRow['errors']) && is_array($excelRow['errors'])) {
                                        foreach ($excelRow['errors'] as $excelMessage) $excelMessages[count($excelMessages)] = $excelMessage;
                                    }
                                    if (isset($excelRow['warnings']) && is_array($excelRow['warnings'])) {
                                        foreach ($excelRow['warnings'] as $excelMessage) $excelMessages[count($excelMessages)] = $excelMessage;
                                    }
                                    ?>
                                    <tr class="<?php echo h($excelRowClass); ?>">
                                        <td class="p-2 border text-center">
                                            <?php if ($excelSaveable): ?>
                                                <input type="checkbox" name="rows[<?php echo (int)$excelIdx; ?>][include]" value="1" checked>
                                            <?php else: ?>
                                                <span class="text-gray-400">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-2 border min-w-[120px]"><?php echo h(isset($excelRow['status']) ? $excelRow['status'] : ''); ?></td>
                                        <td class="p-2 border whitespace-nowrap"><?php echo h(isset($excelRow['work_date']) ? $excelRow['work_date'] : ''); ?></td>
                                        <td class="p-2 border">
                                            <?php if ($excelSaveable): ?>
                                                <input type="text" name="rows[<?php echo (int)$excelIdx; ?>][equipment_category]" value="<?php echo h(isset($excelRow['equipment_category']) ? $excelRow['equipment_category'] : ''); ?>" class="w-full min-w-[100px] px-2 py-1 border rounded" lang="ko" autocomplete="off">
                                            <?php else: ?>
                                                <?php echo h(isset($excelRow['equipment_category']) ? $excelRow['equipment_category'] : ''); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-2 border">
                                            <?php if ($excelSaveable): ?>
                                                <input type="text" name="rows[<?php echo (int)$excelIdx; ?>][vendor_name]" value="<?php echo h(isset($excelRow['vendor_name']) ? $excelRow['vendor_name'] : ''); ?>" class="w-full min-w-[140px] px-2 py-1 border rounded" lang="ko" autocomplete="off">
                                            <?php else: ?>
                                                <?php echo h(isset($excelRow['vendor_name']) ? $excelRow['vendor_name'] : ''); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-2 border">
                                            <?php if ($excelSaveable): ?>
                                                <input type="text" name="rows[<?php echo (int)$excelIdx; ?>][business_no]" value="<?php echo h(isset($excelRow['business_no']) ? $excelRow['business_no'] : ''); ?>" class="w-full min-w-[140px] px-2 py-1 border rounded" autocomplete="off">
                                            <?php else: ?>
                                                <?php echo h(isset($excelRow['business_no']) ? $excelRow['business_no'] : ''); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-2 border">
                                            <?php if ($excelSaveable): ?>
                                                <input type="text" name="rows[<?php echo (int)$excelIdx; ?>][equipment_spec]" value="<?php echo h(isset($excelRow['equipment_spec']) ? $excelRow['equipment_spec'] : ''); ?>" class="w-full min-w-[120px] px-2 py-1 border rounded" lang="ko" autocomplete="off">
                                                <input type="hidden" name="rows[<?php echo (int)$excelIdx; ?>][representative]" value="<?php echo h(isset($excelRow['representative']) ? $excelRow['representative'] : ''); ?>">
                                                <input type="hidden" name="rows[<?php echo (int)$excelIdx; ?>][phone]" value="<?php echo h(isset($excelRow['phone']) ? $excelRow['phone'] : ''); ?>">
                                            <?php else: ?>
                                                <?php echo h(isset($excelRow['equipment_spec']) ? $excelRow['equipment_spec'] : ''); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-2 border text-right">
                                            <?php if ($excelSaveable): ?>
                                                <input type="text" name="rows[<?php echo (int)$excelIdx; ?>][base_price]" value="<?php echo h(equipment_money(isset($excelRow['base_price']) ? $excelRow['base_price'] : 0)); ?>" class="w-full min-w-[100px] px-2 py-1 border rounded text-right">
                                            <?php else: ?>
                                                <?php echo equipment_money(isset($excelRow['base_price']) ? $excelRow['base_price'] : 0); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-2 border text-right">
                                            <?php if ($excelSaveable): ?>
                                                <input type="text" name="rows[<?php echo (int)$excelIdx; ?>][amount]" value="<?php echo h(equipment_money(isset($excelRow['amount']) ? $excelRow['amount'] : 0)); ?>" class="w-full min-w-[110px] px-2 py-1 border rounded text-right">
                                            <?php else: ?>
                                                <?php echo equipment_money(isset($excelRow['amount']) ? $excelRow['amount'] : 0); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-2 border">
                                            <?php if ($excelSaveable): ?>
                                                <input type="text" name="rows[<?php echo (int)$excelIdx; ?>][memo]" value="<?php echo h(isset($excelRow['memo']) ? $excelRow['memo'] : ''); ?>" class="w-full min-w-[140px] px-2 py-1 border rounded" lang="ko" autocomplete="off">
                                            <?php else: ?>
                                                <?php echo h(isset($excelRow['memo']) ? $excelRow['memo'] : ''); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-2 border min-w-[220px]"><?php echo h(implode(' / ', $excelMessages)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-3 flex flex-col md:flex-row md:items-center md:justify-between gap-2">
                            <div class="text-xs text-gray-500">체크된 등록 가능 행만 저장됩니다. 같은 장비/날짜가 있으면 새 행을 만들지 않고 기존 사용내역을 업데이트합니다.</div>
                            <button type="submit" class="px-4 py-2 rounded-xl bg-blue-600 text-white font-bold" <?php echo ($equipmentExcelSaveableCount <= 0) ? 'disabled' : ''; ?>>등록하기</button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <script>
        (function(){
            var selectedYm = <?php echo json_encode($ym); ?>;
            // 장비 달력(전월/현월 2달력)
            window.cpmsEquipRange = {
                ym: <?php echo json_encode($ym); ?>,
                prevYm: <?php echo json_encode($prevYm); ?>,
                prevLastDay: <?php echo (int)$prevLastDay; ?>,
                start: <?php echo json_encode($monthlyStart); ?>,
                end: <?php echo json_encode($monthlyEnd); ?>
            };
            var rangeInfo = window.cpmsEquipRange || {};

            function pad2(v){ return (v < 10 ? '0' : '') + v; }
            function ymdToTs(dateText){
                if (!/^\d{4}-\d{2}-\d{2}$/.test(dateText || '')) return null;
                return new Date(dateText + 'T00:00:00').getTime();
            }
            function classToggle(baseClass, enabled, selected){
                if (!enabled) return baseClass + ' bg-gray-100 text-gray-400 border-gray-200 cursor-not-allowed';
                if (selected) return baseClass + ' bg-blue-600 text-white border-blue-600';
                return baseClass + ' bg-white text-gray-700 border-gray-300 hover:bg-blue-50';
            }

            // 업체 자동완성 이벤트 위임
            var createForm = document.getElementById('equipmentCreateForm');
            var equipmentVendorTimers = {};
            function hideSuggestList(listEl){
                if (!listEl) return;
                listEl.innerHTML = '';
                if (listEl.className.indexOf('hidden') === -1) listEl.className += ' hidden';
                listEl.style.display = 'none';
            }
            function showSuggestList(listEl){
                if (!listEl) return;
                listEl.className = listEl.className.replace(/\bhidden\b/g, '').replace(/\s+/g, ' ').trim();
                listEl.style.display = 'block';
            }
            function fillEquipmentPreset(formEl, p){
                if (!formEl || !p) return;
                if (formEl.elements['category']) formEl.elements['category'].value = p.category || '';
                if (formEl.elements['vendor_name']) formEl.elements['vendor_name'].value = p.vendor_name || '';
                if (formEl.elements['representative']) formEl.elements['representative'].value = p.representative || '';
                if (formEl.elements['phone']) formEl.elements['phone'].value = p.phone || '';
                if (formEl.elements['biz_no']) formEl.elements['biz_no'].value = p.biz_no || '';
                if (formEl.elements['base_rate']) formEl.elements['base_rate'].value = p.base_rate || '';
                if (formEl.elements['remark']) formEl.elements['remark'].value = p.remark || '';
            }
            function renderEquipmentSuggestions(inputEl, rows){
                var wrap = inputEl ? inputEl.closest('.vendor-search-wrap') : null;
                var listEl = wrap ? wrap.querySelector('.vendor-suggest-list') : null;
                if (!listEl) return;
                listEl.innerHTML = '';
                if (!rows || !rows.length) {
                    var empty = document.createElement('div');
                    empty.className = 'px-3 py-2 text-sm text-gray-500';
                    empty.textContent = '검색 결과 없음';
                    listEl.appendChild(empty);
                    showSuggestList(listEl);
                    return;
                }
                for (var i=0; i<rows.length; i++) {
                    (function(row){
                        var btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'block w-full text-left px-3 py-2 border-b last:border-b-0 hover:bg-blue-50';
                        btn.textContent = (row.vendor_name || '') + (row.phone ? ' (' + row.phone + ')' : '');
                        btn.setAttribute('data-vendor-item', '1');
                        btn.vendorData = row;
                        listEl.appendChild(btn);
                    })(rows[i]);
                }
                showSuggestList(listEl);
            }
            document.addEventListener('input', function(e){
                var inputEl = e.target;
                if (!inputEl || inputEl.className.indexOf('js-equipment-vendor-search') === -1) return;
                var wrap = inputEl.closest('.vendor-search-wrap');
                var listEl = wrap ? wrap.querySelector('.vendor-suggest-list') : null;
                if (!listEl) return;
                var q = (inputEl.value || '').trim();
                if (equipmentVendorTimers[inputEl]) clearTimeout(equipmentVendorTimers[inputEl]);
                if (q.length < 2) { hideSuggestList(listEl); return; }
                equipmentVendorTimers[inputEl] = setTimeout(function(){
                    // 프리셋 최신 검색
                    var xhr = new XMLHttpRequest();
                    xhr.open('GET', '<?php echo h(base_url()); ?>/?r=construction/equipment_vendor_search&q=' + encodeURIComponent(q), true);
                    xhr.onreadystatechange = function(){
                        if (xhr.readyState !== 4) return;
                        var rows = [];
                        if (xhr.status === 200) {
                            try { var json = JSON.parse(xhr.responseText); rows = (json && json.items) ? json.items : []; } catch (err) { rows = []; }
                        }
                        renderEquipmentSuggestions(inputEl, rows);
                    };
                    xhr.send();
                }, 250);
            });
            document.addEventListener('click', function(e){
                var target = e.target;
                if (target && target.getAttribute && target.getAttribute('data-vendor-item') === '1') {
                    var wrap = target.closest('.vendor-search-wrap');
                    var inputEl = wrap ? wrap.querySelector('.js-equipment-vendor-search') : null;
                    var formEl = target.closest('form');
                    // 자동채움 재초기화
                    fillEquipmentPreset(formEl, target.vendorData || {});
                    if (inputEl) inputEl.value = (target.vendorData && target.vendorData.vendor_name) ? target.vendorData.vendor_name : '';
                    hideSuggestList(wrap ? wrap.querySelector('.vendor-suggest-list') : null);
                    return;
                }
                var lists = document.querySelectorAll('.vendor-search-wrap .vendor-suggest-list');
                for (var i=0; i<lists.length; i++) {
                    if (!lists[i].contains(target)) hideSuggestList(lists[i]);
                }
            });            

            // 장비 사용일자 달력 선택
            function initCalendar(wrapper){
                var ym = wrapper.getAttribute('data-ym') || selectedYm;
                var prevGridId = wrapper.getAttribute('data-prev-grid-target');
                var wrapperPrevYm = wrapper.getAttribute('data-prev-ym') || (rangeInfo.prevYm || '');
                var currGridId = wrapper.getAttribute('data-curr-grid-target');
                var chipId = wrapper.getAttribute('data-chip-target');
                var inputId = wrapper.getAttribute('data-target');
                var toggleBtn = wrapper.querySelector('[data-toggle-calendar]');
                var box = wrapper.querySelector('[data-calendar-box]');
                var prevGrid = document.getElementById(prevGridId);
                var currGrid = document.getElementById(currGridId);
                var chips = document.getElementById(chipId);
                var inputs = document.getElementById(inputId);
                var selected = {};

                if (!box || !prevGrid || !currGrid || !chips || !inputs) return;

                var parts = ym.split('-');
                var year = parseInt(parts[0], 10);
                var month = parseInt(parts[1], 10);
                var prevYm = wrapperPrevYm;
                var prevParts = prevYm.split('-');
                var prevYear = parseInt(prevParts[0], 10);
                var prevMonth = parseInt(prevParts[1], 10);
                var prevLastDay = new Date(prevYear, prevMonth, 0).getDate();
                var currLastDay = new Date(year, month, 0).getDate();
                var startDate = (rangeInfo.start && rangeInfo.prevYm === prevYm && rangeInfo.ym === ym) ? rangeInfo.start : (prevYm + '-26');
                var endDate = (rangeInfo.end && rangeInfo.prevYm === prevYm && rangeInfo.ym === ym) ? rangeInfo.end : (ym + '-25');
                var startTs = ymdToTs(startDate);
                var endTs = ymdToTs(endDate);

                function renderInputs(){
                    inputs.innerHTML = '';
                    var keys = Object.keys(selected).sort();
                    for (var i=0; i<keys.length; i++) {
                        var hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = 'usage_dates[]';
                        hidden.value = keys[i];
                        inputs.appendChild(hidden);
                    }
                }

                function removeDate(d){
                    if (selected[d]) {
                        delete selected[d];
                        render();
                    }
                }

                function renderChips(){
                    chips.innerHTML = '';
                    var keys = Object.keys(selected).sort();
                    for (var i=0; i<keys.length; i++) {
                        (function(dateVal){
                            var chip = document.createElement('span');
                            chip.className = 'inline-flex items-center gap-1 px-2 py-1 rounded-full bg-blue-50 text-blue-700 text-xs border border-blue-200';
                            chip.textContent = dateVal;

                            var xBtn = document.createElement('button');
                            xBtn.type = 'button';
                            xBtn.className = 'text-blue-700 font-bold';
                            xBtn.textContent = '×';
                            xBtn.addEventListener('click', function(){ removeDate(dateVal); });
                            chip.appendChild(xBtn);
                            chips.appendChild(chip);
                        })(keys[i]);
                    }
                }

                function toggleDate(dateStr){
                    var ts = ymdToTs(dateStr);
                    if (ts === null || (startTs !== null && ts < startTs) || (endTs !== null && ts > endTs)) {
                        alert('선택 가능 범위(' + startDate + ' ~ ' + endDate + ') 날짜만 선택할 수 있습니다.');
                        return;
                    }
                    if (selected[dateStr]) delete selected[dateStr];
                    else selected[dateStr] = true;
                    render();
                }

                function renderMonthCalendar(root, targetYm, dayStart, dayEnd, modeLabel){
                    root.innerHTML = '';
                    var head = document.createElement('div');
                    head.className = 'text-xs font-bold mb-2 text-gray-700';
                    head.textContent = modeLabel + ' (' + targetYm + ')';
                    root.appendChild(head);

                    var weekHead = document.createElement('div');
                    weekHead.className = 'grid grid-cols-7 gap-1 mb-1';
                    var weekdays = ['일','월','화','수','목','금','토'];
                    for (var w = 0; w < weekdays.length; w++) {
                        var wCell = document.createElement('div');
                        wCell.className = 'text-[11px] text-center text-gray-500 font-bold';
                        wCell.textContent = weekdays[w];
                        weekHead.appendChild(wCell);
                    }
                    root.appendChild(weekHead);

                    var firstWeekday = new Date(targetYm + '-01T00:00:00').getDay();
                    var targetParts = targetYm.split('-');
                    var y = parseInt(targetParts[0], 10);
                    var m = parseInt(targetParts[1], 10);
                    var lastDay = new Date(y, m, 0).getDate();
                    var grid = document.createElement('div');
                    grid.className = 'grid grid-cols-7 gap-1';

                    for (var e = 0; e < firstWeekday; e++) {
                        var emptyCell = document.createElement('div');
                        emptyCell.className = 'h-7';
                        grid.appendChild(emptyCell);
                    }

                    for (var day=1; day<=lastDay; day++) {
                        (function(d){
                            var dateStr = targetYm + '-' + pad2(d);
                            var isEnabled = (d >= dayStart && d <= dayEnd);
                            var btn = document.createElement('button');
                            btn.type = 'button';
                            btn.className = classToggle('h-7 rounded border text-xs', isEnabled, !!selected[dateStr]);
                            btn.textContent = d;
                            if (!isEnabled) btn.disabled = true;
                            btn.setAttribute('data-date', dateStr);
                            btn.addEventListener('click', function(){
                                if (!isEnabled) return;
                                toggleDate(dateStr);
                            });
                            grid.appendChild(btn);
                        })(day);
                    }
                    root.appendChild(grid);
                }

                function renderGrids(){
                    renderMonthCalendar(prevGrid, prevYm, 26, prevLastDay, '전월');
                    renderMonthCalendar(currGrid, ym, 1, 25, '현월');
                }

                function render(){
                    renderGrids();
                    renderChips();
                    renderInputs();
                }

                if (toggleBtn) {
                    toggleBtn.addEventListener('click', function(){
                        if (box.className.indexOf('hidden') !== -1) box.className = box.className.replace('hidden', '').trim();
                        else box.className += ' hidden';
                    });
                }

                var form = wrapper.closest('form');
                if (form) {
                    form.addEventListener('submit', function(e){
                        if (Object.keys(selected).length <= 0) {
                            e.preventDefault();
                            alert('사용일자를 달력에서 1개 이상 선택하세요.');
                        }
                    });
                }

                render();
            }

            var wrappers = document.querySelectorAll('[data-calendar-wrapper]');
            for (var i=0; i<wrappers.length; i++) {
                initCalendar(wrappers[i]);
            }
        })();
        </script>        
    <?php else: ?>
        <div class="mt-6 overflow-auto">
            <table class="min-w-[1800px] w-full border-collapse text-xs">
                <thead>
                    <!-- 장비월별 날짜 2줄 -->
                    <tr class="bg-gray-50">
                        <th class="border p-2" rowspan="2">구분</th>
                        <th class="border p-2" rowspan="2">업체명</th>
                        <th class="border p-2" rowspan="2">규격</th>
                        <th class="border p-2" rowspan="2">대표자명</th>
                        <th class="border p-2" rowspan="2">전화번호</th>
                        <th class="border p-2" rowspan="2">사업자등록번호</th>
                        <th class="border p-2" rowspan="2">기본단가</th>
                        <?php foreach ($dateSlotsRow1 as $slot): ?>
                            <th class="border p-1 text-center <?php echo $slot['valid'] ? '' : 'bg-gray-200 text-gray-500'; ?>" style="min-width:52px;"><?php echo h($slot['label']); ?></th>
                        <?php endforeach; ?>
                        <th class="border p-2" rowspan="2">총 장비공수</th>
                        <th class="border p-2" rowspan="2">총 장비비</th>
                        <th class="border p-2" rowspan="2">비고</th>
                    </tr>
                    <tr class="bg-gray-50">
                        <?php foreach ($dateSlotsRow2 as $slot): ?>
                            <th class="border p-1 text-center <?php echo $slot['valid'] ? '' : 'bg-gray-200 text-gray-500'; ?>" style="min-width:52px;"><?php echo h($slot['label']); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php
                $sumWorkUnit = 0.0;
                $sumAmount = 0.0;
                $sumByDate = array();
                foreach ($dateSlots as $slot) {
                    $sumByDate[$slot['date']] = 0.0;
                }
                $lastCategory = '__none__';
                ?>
                <?php if (count($displayItems) === 0): ?>
                    <tr><td class="border p-3 text-center text-gray-500" colspan="48">등록된 장비가 없습니다.</td></tr>
                <?php else: ?>
                    <?php foreach ($displayItems as $it): ?>
                        <?php
                        $rowWorkUnit = 0.0;
                        $rowAmount = 0.0;
                        foreach ($dateSlots as $slotAll) {
                            if (!$slotAll['valid']) {
                                continue;
                            }
                            $slotUnit = isset($it['slot_usage'][$slotAll['date']]['total_unit']) ? (float)$it['slot_usage'][$slotAll['date']]['total_unit'] : 0.0;
                            $slotAmount = isset($it['slot_usage'][$slotAll['date']]['total_amount']) ? (float)$it['slot_usage'][$slotAll['date']]['total_amount'] : 0.0;
                            if ($slotUnit > 0) {
                                $rowWorkUnit += $slotUnit;
                                $rowAmount += $slotAmount;
                                $sumByDate[$slotAll['date']] += $slotUnit;
                            }
                        }                        
                        ?>
                        <tr>
                            <td class="border p-1 text-center" rowspan="2"><?php echo h($it['category']); ?></td>
                            <td class="border p-1" rowspan="2"><?php echo h($it['vendor_name']); ?></td>
                            <td class="border p-1" rowspan="2"><?php echo h($it['spec']); ?></td>
                            <td class="border p-1" rowspan="2"><?php echo h($it['representative']); ?></td>
                            <td class="border p-1" rowspan="2"><?php echo h($it['phone']); ?></td>
                            <td class="border p-1" rowspan="2"><?php echo h($it['biz_no']); ?></td>
                            <td class="border p-1 text-right" rowspan="2"><?php echo equipment_money($it['base_rate']); ?></td>

                            <?php foreach ($dateSlotsRow1 as $slot): ?>
                                <?php
                                if (!$slot['valid']) {
                                    echo '<td class="border p-1 text-center bg-gray-200 text-gray-500">X</td>';
                                    continue;
                                }
                                $slotBundle = isset($it['slot_usage'][$slot['date']]) ? $it['slot_usage'][$slot['date']] : null;
                                echo equipment_render_grouped_gongsu_cell($slotBundle, $pendingByUsage, $it);
                                ?>
                            <?php endforeach; ?>

                            <td class="border p-1 text-center" rowspan="2"><?php echo equipment_gongsu($rowWorkUnit); ?></td>
                            <td class="border p-1 text-right" rowspan="2"><?php echo equipment_money($rowAmount); ?></td>
                            <td class="border p-1" rowspan="2"><?php echo h($it['remark']); ?></td>
                        </tr>
                        <tr>
                            <?php foreach ($dateSlotsRow2 as $slot): ?>
                                <?php
                                if (!$slot['valid']) {
                                    echo '<td class="border p-1 text-center bg-gray-200 text-gray-500">X</td>';
                                    continue;
                                }
                                $slotBundle = isset($it['slot_usage'][$slot['date']]) ? $it['slot_usage'][$slot['date']] : null;
                                echo equipment_render_grouped_gongsu_cell($slotBundle, $pendingByUsage, $it);
                                ?>
                            <?php endforeach; ?>
                        </tr>
                        <?php
                        $lastCategory = (string)$it['category'];
                        $sumWorkUnit += $rowWorkUnit;
                        $sumAmount += $rowAmount;
                        ?>
                    <?php endforeach; ?>

                    <tr class="bg-yellow-50 font-bold">
                        <td class="border p-1 text-center" colspan="7" rowspan="2">합계</td>
                        <?php foreach ($dateSlotsRow1 as $slot): ?>
                            <?php if (!$slot['valid']): ?>
                                <td class="border p-1 text-center bg-gray-200 text-gray-500">X</td>
                            <?php else: ?>
                                <td class="border p-1 text-right"><?php echo equipment_gongsu(isset($sumByDate[$slot['date']]) ? $sumByDate[$slot['date']] : 0); ?></td>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <td class="border p-1 text-center" rowspan="2"><?php echo equipment_gongsu($sumWorkUnit); ?></td>
                        <td class="border p-1 text-right" rowspan="2"><?php echo equipment_money($sumAmount); ?></td>
                        <td class="border p-1" rowspan="2"></td>
                    </tr>
                    <tr class="bg-yellow-50 font-bold">
                        <?php foreach ($dateSlotsRow2 as $slot): ?>
                            <?php if (!$slot['valid']): ?>
                                <td class="border p-1 text-center bg-gray-200 text-gray-500">X</td>
                            <?php else: ?>
                                <td class="border p-1 text-right"><?php echo equipment_gongsu(isset($sumByDate[$slot['date']]) ? $sumByDate[$slot['date']] : 0); ?></td>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div id="equipmentGongsuModal" class="hidden fixed inset-0 z-50 bg-black/40 items-center justify-center p-4">
            <div class="bg-white rounded-2xl shadow-xl border border-gray-200 w-full max-w-lg p-5">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <div class="text-lg font-extrabold text-gray-900">장비공수 수정</div>
                        <div class="text-sm text-gray-500" id="equipmentGongsuMeta"></div>
                    </div>
                    <button type="button" class="px-3 py-1 rounded-lg border border-gray-300 text-sm" data-equipment-gongsu-close>닫기</button>
                </div>
                <div class="mt-4 space-y-3">
                    <input type="hidden" id="equipmentGongsuUsageId" value="">
                    <input type="hidden" id="equipmentGongsuProjectId" value="<?php echo (int)$pid; ?>">
                    <div class="grid grid-cols-2 gap-3 text-sm">
                        <div class="bg-gray-50 rounded-xl p-3">
                            <div class="text-xs text-gray-500 font-bold">기존 장비공수</div>
                            <div class="mt-1 text-xl font-extrabold" id="equipmentGongsuOldText">1</div>
                        </div>
                        <label class="block">
                            <span class="text-xs text-gray-500 font-bold">변경 장비공수</span>
                            <input type="number" step="0.01" min="0" id="equipmentGongsuNewValue" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300" value="1">
                        </label>
                    </div>
                    <label class="block">
                        <span class="text-xs text-gray-500 font-bold">요청 사유</span>
                        <textarea id="equipmentGongsuReason" rows="3" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-300" placeholder="1.2 이상은 승인 요청 사유가 필요합니다."></textarea>
                    </label>
                    <div class="text-xs text-gray-500">1.2 미만은 즉시 반영되고, 1.2 이상은 승인 요청으로 처리됩니다.</div>
                    <div class="flex justify-end gap-2">
                        <button type="button" class="px-4 py-2 rounded-xl border border-gray-300 font-bold" data-equipment-gongsu-close>취소</button>
                        <button type="button" class="px-4 py-2 rounded-xl bg-blue-600 text-white font-bold" id="equipmentGongsuSubmit">저장/요청</button>
                    </div>
                </div>
            </div>
        </div>
        <script>
        (function(){
            var modal = document.getElementById('equipmentGongsuModal');
            if (!modal) return;
            var meta = document.getElementById('equipmentGongsuMeta');
            var usageId = document.getElementById('equipmentGongsuUsageId');
            var projectId = document.getElementById('equipmentGongsuProjectId');
            var oldText = document.getElementById('equipmentGongsuOldText');
            var newValue = document.getElementById('equipmentGongsuNewValue');
            var reason = document.getElementById('equipmentGongsuReason');
            var submitBtn = document.getElementById('equipmentGongsuSubmit');

            function showModal(){
                modal.className = modal.className.replace(/\bhidden\b/g, '').replace(/\s+/g, ' ').trim();
                if (modal.className.indexOf('flex') === -1) modal.className += ' flex';
            }
            function hideModal(){
                modal.className = modal.className.replace(/\bflex\b/g, '').replace(/\s+/g, ' ').trim();
                if (modal.className.indexOf('hidden') === -1) modal.className += ' hidden';
            }
            document.addEventListener('click', function(e){
                var cell = e.target && e.target.closest ? e.target.closest('[data-equipment-gongsu-cell]') : null;
                if (cell) {
                    usageId.value = cell.getAttribute('data-usage-id') || '';
                    projectId.value = cell.getAttribute('data-project-id') || '<?php echo (int)$pid; ?>';
                    var oldVal = cell.getAttribute('data-old-value') || '1.00';
                    oldText.textContent = oldVal;
                    newValue.value = oldVal;
                    reason.value = '';
                    var name = cell.getAttribute('data-equipment-name') || '';
                    var vendor = cell.getAttribute('data-vendor-name') || '';
                    var useDate = cell.getAttribute('data-use-date') || '';
                    meta.textContent = vendor + (name ? ' / ' + name : '') + (useDate ? ' / ' + useDate : '');
                    showModal();
                    return;
                }
                if (e.target && e.target.getAttribute && e.target.getAttribute('data-equipment-gongsu-close') !== null) {
                    hideModal();
                }
            });
            if (submitBtn) {
                submitBtn.addEventListener('click', function(){
                    var v = parseFloat(newValue.value || '0');
                    if (isNaN(v) || v < 0) { alert('변경 장비공수를 확인해주세요.'); return; }
                    if (v >= 1.2 && (reason.value || '').replace(/\s+/g, '') === '') {
                        alert('1.2 이상 장비공수는 요청 사유가 필요합니다.');
                        return;
                    }
                    var params = new URLSearchParams();
                    params.append('_csrf', <?php echo json_encode(csrf_token()); ?>);
                    params.append('project_id', projectId.value || '<?php echo (int)$pid; ?>');
                    params.append('equipment_usage_id', usageId.value || '');
                    params.append('new_value', newValue.value || '');
                    params.append('reason', reason.value || '');
                    submitBtn.disabled = true;
                    fetch('<?php echo h(base_url()); ?>/?r=construction/equipment_gongsu_override_save', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                        body: params.toString()
                    }).then(function(res){
                        return res.text().then(function(text){ return {ok: res.ok, text: text}; });
                    }).then(function(resp){
                        var json = null;
                        try { json = JSON.parse(resp.text); } catch (err) {}
                        if (!resp.ok || !json || !json.ok) {
                            alert((json && json.message) ? json.message : ('장비공수 저장 실패: ' + resp.text.substring(0, 200)));
                            return;
                        }
                        alert(json.message || '장비공수가 처리되었습니다.');
                        window.location.reload();
                    }).catch(function(){
                        alert('장비공수 저장 중 통신 오류가 발생했습니다.');
                    }).finally(function(){
                        submitBtn.disabled = false;
                    });
                });
            }
        })();
        </script>
    <?php endif; ?>
</div>
