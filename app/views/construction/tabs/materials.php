<?php
/**
 * 공사 > 자재구입비 탭
 * 자재구입비(장비 방식 복제)
 * - 서브탭: 월별자재구입비(monthly), 자재구입비입력(input)
 * - 월 선택(ym=YYYY-MM) 공통 적용
 * - 월별 양식(전월 26~말일 + 선택월 1~25, 2줄 출력) 출력
 * - PHP 5.6 호환
 */

use App\Core\Db;
require_once __DIR__ . '/../partials/master_dedupe_helper.php';
require_once __DIR__ . '/../partials/project_month_options_helper.php';
require_once __DIR__ . '/../partials/material_statement_helper.php';
require_once __DIR__ . '/../partials/material_usage_helper.php';
require_once __DIR__ . '/../../safety/safety_cost_helper.php';

$canEditMaterials = isset($canEdit) ? (bool)$canEdit : false;

$pdo = Db::pdo();
if (!$pdo) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">DB 연결 실패</div>';
    return;
}
cpms_material_usage_ensure_schema($pdo);
$hasMaterialAdvanceYn = cpms_material_usage_column_exists($pdo, 'advance_yn');
$canDownloadMaterialStatements = cpms_material_statement_user_can_download($pdo, (int)$pid);
$canViewMaterialInput = ($canEditMaterials || $canDownloadMaterialStatements);

$materialsTab = isset($_GET['materials_tab']) ? trim((string)$_GET['materials_tab']) : 'monthly';
if ($materialsTab !== 'monthly' && $materialsTab !== 'input') {
    $materialsTab = 'monthly';
}
if (!$canViewMaterialInput && $materialsTab === 'input') {
    $materialsTab = 'monthly';
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

$baseUrl = base_url() . '/?r=공사&pid=' . (int)$pid . '&tab=materials';
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
$statementFilesByUsage = array();
$statementFilesByGroupDate = array();
$allowedMaterialCategories = array('자재비'=>true, '구매품'=>true, '기타경비'=>true, '안전관리비'=>true);

try {
    $stItem = $pdo->prepare("SELECT * FROM cpms_material_items WHERE project_id = :pid AND is_deleted = 0 ORDER BY category ASC, vendor_name ASC, id ASC");
    $stItem->bindValue(':pid', (int)$pid, PDO::PARAM_INT);
    $stItem->execute();
    $items = $stItem->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as $it) {
        $eid = (int)$it['id'];
        $itemMap[$eid] = $it;
        $usageByEquipment[$eid] = array();
    }

    if (count($items) > 0) {
        $usageSelect = $hasMaterialAdvanceYn ? "u.*" : "u.*, 'N' AS advance_yn";
        $stUsage = $pdo->prepare("SELECT " . $usageSelect . ", i.category, i.vendor_name, i.representative, i.phone, i.biz_no, i.base_rate, i.remark
            FROM cpms_material_usage u
            JOIN cpms_material_items i ON i.id = u.material_id
            WHERE u.project_id = :pid
              AND i.is_deleted = 0
              AND i.category <> '안전관리비'
              AND u.use_date BETWEEN :s AND :e
            ORDER BY u.use_date ASC, i.category ASC, i.vendor_name ASC, u.id ASC");
        $stUsage->bindValue(':pid', (int)$pid, PDO::PARAM_INT);
        $stUsage->bindValue(':s', $monthlyStart);
        $stUsage->bindValue(':e', $monthlyEnd);
        $stUsage->execute();
        $usageRows = $stUsage->fetchAll(PDO::FETCH_ASSOC);

        foreach ($usageRows as $ur) {
            $eid = (int)$ur['material_id'];
            $d = (string)$ur['use_date'];
            $amt = (float)$ur['amount'];
            if (!isset($usageByEquipment[$eid])) $usageByEquipment[$eid] = array();
            $usageByEquipment[$eid][$d] = $amt;
            if (!isset($usageByDate[$d])) $usageByDate[$d] = 0.0;
            $usageByDate[$d] += $amt;
        }
    }
} catch (Exception $e) {
    echo '<div class="bg-yellow-50 border border-yellow-200 text-yellow-800 rounded-2xl p-4 mb-4">자재구입비 테이블이 아직 준비되지 않았습니다. DB 설정에서 "4) 자재구입비 테이블 생성/확인"을 먼저 실행하세요.</div>';
    $items = array();
    $itemMap = array();
    $usageRows = array();
    $usageByEquipment = array();
    $usageByDate = array();
    $vendorPresets = array();    
}

$statementUsageIds = array();
foreach ($usageRows as $ur) {
    if (isset($ur['id']) && (int)$ur['id'] > 0) {
        $statementUsageIds[count($statementUsageIds)] = (int)$ur['id'];
    }
}
$statementFilesByUsage = cpms_material_statement_files_by_usage_ids($pdo, $statementUsageIds);
foreach ($usageRows as $ur) {
    $uid = isset($ur['id']) ? (int)$ur['id'] : 0;
    if ($uid <= 0 || !isset($statementFilesByUsage[$uid])) continue;
    $materialIdForStatement = isset($ur['material_id']) ? (int)$ur['material_id'] : 0;
    if ($materialIdForStatement <= 0 || !isset($itemMap[$materialIdForStatement])) continue;
    $groupKeyForStatement = cpms_material_master_group_key($itemMap[$materialIdForStatement]);
    $useDateForStatement = isset($ur['use_date']) ? (string)$ur['use_date'] : '';
    if ($groupKeyForStatement === '' || $useDateForStatement === '') continue;
    if (!isset($statementFilesByGroupDate[$groupKeyForStatement])) $statementFilesByGroupDate[$groupKeyForStatement] = array();
    if (!isset($statementFilesByGroupDate[$groupKeyForStatement][$useDateForStatement])) $statementFilesByGroupDate[$groupKeyForStatement][$useDateForStatement] = array();
    foreach ($statementFilesByUsage[$uid] as $statementFileRow) {
        $statementFilesByGroupDate[$groupKeyForStatement][$useDateForStatement][count($statementFilesByGroupDate[$groupKeyForStatement][$useDateForStatement])] = $statementFileRow;
    }
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

// 월별자재구입비 날짜 2줄
$splitIndex = 0;
$preferredFirstRowCount = (7 + 15); // 전월26~말일 + 현월 1~15
if ($preferredFirstRowCount >= count($dateSlots)) {
    $splitIndex = (int)ceil(count($dateSlots) / 2);
} else {
    $splitIndex = $preferredFirstRowCount;
}
$dateSlotsRow1 = array_slice($dateSlots, 0, $splitIndex);
$dateSlotsRow2 = array_slice($dateSlots, $splitIndex);

function material_money($v)
{
    return number_format((float)$v, 0);
}
function material_money_or_dash($v)
{
    $v = (float)$v;
    return (abs($v) > 0.0001) ? number_format($v, 0) : '-';
}
function material_monthly_detail_text($row)
{
    if (!is_array($row)) return '';
    $itemName = isset($row['item_name']) ? trim((string)$row['item_name']) : '';
    $useContent = isset($row['use_content']) ? trim((string)$row['use_content']) : '';
    if ($itemName !== '' && $useContent !== '' && $itemName !== $useContent) return $itemName . ' / ' . $useContent;
    if ($useContent !== '') return $useContent;
    return $itemName;
}
function material_category_label($category)
{
    $category = trim((string)$category);
    $allowed = array('자재비'=>true, '구매품'=>true, '기타경비'=>true, '안전관리비'=>true);
    return isset($allowed[$category]) ? $category : '자재비';
}
function material_statement_cell_files($map, $groupKey, $date)
{
    if (!is_array($map)) return array();
    if (!isset($map[$groupKey]) || !is_array($map[$groupKey])) return array();
    if (!isset($map[$groupKey][$date]) || !is_array($map[$groupKey][$date])) return array();
    return $map[$groupKey][$date];
}
function material_statement_links_html($files, $label, $canDownload, $emptyLabel)
{
    if (!is_array($files) || count($files) <= 0) {
        return '<span class="text-gray-400 text-xs">' . h($emptyLabel) . '</span>';
    }
    if (!$canDownload) {
        return '<span class="text-gray-500 text-xs">' . h(urldecode('%EC%B2%A8%EB%B6%80%20%EC%9E%88%EC%9D%8C')) . '</span>';
    }
    $html = '';
    $idx = 0;
    foreach ($files as $fileRow) {
        $idx++;
        $fileId = isset($fileRow['id']) ? (int)$fileRow['id'] : 0;
        if ($fileId <= 0) continue;
        $originalName = isset($fileRow['original_name']) ? (string)$fileRow['original_name'] : '';
        $buttonLabel = (count($files) > 1) ? ($label . ' ' . $idx) : $label;
        $url = base_url() . '/?r=construction/material_statement_download&id=' . $fileId;
        $storageType = isset($fileRow['storage_type']) ? trim((string)$fileRow['storage_type']) : '';
        if ($storageType === 'google_drive') {
            $viewLink = isset($fileRow['drive_web_view_link']) ? trim((string)$fileRow['drive_web_view_link']) : '';
            $downloadLink = isset($fileRow['drive_web_content_link']) ? trim((string)$fileRow['drive_web_content_link']) : '';
            if ($viewLink === '' && $downloadLink === '') {
                $html .= '<span class="inline-flex items-center justify-center px-2 py-1 rounded-lg border border-amber-200 bg-amber-50 text-amber-700 text-xs font-bold" title="' . h($originalName) . '">' . h(cpms_construction_drive_label('file_check_required')) . '</span>';
                continue;
            }
            if ($viewLink !== '') {
                $html .= '<a class="inline-flex items-center justify-center px-2 py-1 rounded-lg border border-blue-200 bg-blue-50 text-blue-700 text-xs font-bold hover:bg-blue-100" href="' . h($url . '&view=1') . '" target="_blank" rel="noopener" title="' . h($originalName) . '">' . h(cpms_construction_drive_label('view')) . '</a>';
            }
            if ($downloadLink !== '') {
                $html .= '<a class="inline-flex items-center justify-center px-2 py-1 rounded-lg border border-gray-200 bg-white text-gray-700 text-xs font-bold hover:bg-gray-50" href="' . h($url . '&download=1') . '" title="' . h($originalName) . '">' . h(cpms_construction_drive_label('download')) . '</a>';
            }
        } else {
            $html .= '<a class="inline-flex items-center justify-center px-2 py-1 rounded-lg border border-blue-200 bg-blue-50 text-blue-700 text-xs font-bold hover:bg-blue-100" href="' . h($url) . '" title="' . h($originalName) . '">' . h($buttonLabel) . '</a>';
        }
    }
    if ($html === '') return '<span class="text-gray-400 text-xs">' . h($emptyLabel) . '</span>';
    return $html;
}

$displayItems = array();
foreach ($items as $it) {
    $groupKey = cpms_material_master_group_key($it);
    if (!isset($displayItems[$groupKey])) {
        $displayItems[$groupKey] = array(
            'group_key' => $groupKey,
            'category' => isset($it['category']) ? (string)$it['category'] : '',
            'vendor_name' => isset($it['vendor_name']) ? (string)$it['vendor_name'] : '',
            'representative' => isset($it['representative']) ? (string)$it['representative'] : '',
            'phone' => isset($it['phone']) ? (string)$it['phone'] : '',
            'biz_no' => isset($it['biz_no']) ? (string)$it['biz_no'] : '',
            'base_rate' => isset($it['base_rate']) ? (float)$it['base_rate'] : 0.0,
            'remark' => isset($it['remark']) ? (string)$it['remark'] : '',
            'item_ids' => array(),
            'slot_amounts' => array()
        );
    }
    $displayItems[$groupKey]['representative'] = cpms_merge_first_non_empty($displayItems[$groupKey]['representative'], isset($it['representative']) ? $it['representative'] : '');
    $displayItems[$groupKey]['phone'] = cpms_merge_first_non_empty($displayItems[$groupKey]['phone'], isset($it['phone']) ? $it['phone'] : '');
    $displayItems[$groupKey]['biz_no'] = cpms_merge_first_non_empty($displayItems[$groupKey]['biz_no'], isset($it['biz_no']) ? $it['biz_no'] : '');
    $displayItems[$groupKey]['remark'] = cpms_merge_first_non_empty($displayItems[$groupKey]['remark'], isset($it['remark']) ? $it['remark'] : '');
    $displayItems[$groupKey]['item_ids'][count($displayItems[$groupKey]['item_ids'])] = isset($it['id']) ? (int)$it['id'] : 0;
}
foreach ($displayItems as $groupKey => $displayItem) {
    foreach ($dateSlots as $slot) {
        $displayItems[$groupKey]['slot_amounts'][$slot['date']] = 0.0;
    }
}
foreach ($usageRows as $ur) {
    $materialId = isset($ur['material_id']) ? (int)$ur['material_id'] : 0;
    if (!isset($itemMap[$materialId])) continue;
    $groupKey = cpms_material_master_group_key($itemMap[$materialId]);
    if (!isset($displayItems[$groupKey])) continue;
    $useDate = isset($ur['use_date']) ? (string)$ur['use_date'] : '';
    if ($useDate === '') continue;
    if (!isset($displayItems[$groupKey]['slot_amounts'][$useDate])) $displayItems[$groupKey]['slot_amounts'][$useDate] = 0.0;
    $displayItems[$groupKey]['slot_amounts'][$useDate] += isset($ur['amount']) ? (float)$ur['amount'] : 0.0;
}

$monthlyRows = array();
$monthlyTotals = array('구매품'=>0.0, '자재비'=>0.0, '안전관리비'=>0.0, '기타경비'=>0.0);
foreach ($usageRows as $ur) {
    $cat = material_category_label(isset($ur['category']) ? $ur['category'] : '');
    if ($cat === '안전관리비') continue;
    if (!isset($monthlyTotals[$cat])) $cat = '자재비';
    $amount = isset($ur['amount']) ? (float)$ur['amount'] : 0.0;
    $monthlyTotals[$cat] += $amount;
    $monthlyRows[count($monthlyRows)] = array(
        'date' => isset($ur['use_date']) ? (string)$ur['use_date'] : '',
        'category' => $cat,
        'advance_yn' => cpms_material_advance_yn(isset($ur['advance_yn']) ? $ur['advance_yn'] : 'N'),
        'vendor_name' => isset($ur['vendor_name']) ? (string)$ur['vendor_name'] : '',
        'detail' => isset($ur['memo']) ? trim((string)$ur['memo']) : '',
        'representative' => isset($ur['representative']) ? (string)$ur['representative'] : '',
        'phone' => isset($ur['phone']) ? (string)$ur['phone'] : '',
        'biz_no' => isset($ur['biz_no']) ? (string)$ur['biz_no'] : '',
        'amount' => $amount,
        'remark' => isset($ur['remark']) ? (string)$ur['remark'] : ''
    );
}

try {
    $safetyRowsForMaterials = cpms_safety_cost_project_items_between((int)$pid, $monthlyStart, $monthlyEnd);
    foreach ($safetyRowsForMaterials as $safetyRow) {
        $useDate = isset($safetyRow['use_date']) ? cpms_safety_cost_valid_date($safetyRow['use_date']) : '';
        if ($useDate === '') continue;
        $amount = cpms_safety_cost_row_amount($safetyRow);
        $monthlyTotals['안전관리비'] += $amount;
        $monthlyRows[count($monthlyRows)] = array(
            'date' => $useDate,
            'category' => '안전관리비',
            'advance_yn' => 'N',
            'vendor_name' => isset($safetyRow['vendor_name']) ? (string)$safetyRow['vendor_name'] : '',
            'detail' => material_monthly_detail_text($safetyRow),
            'representative' => isset($safetyRow['representative']) ? (string)$safetyRow['representative'] : '',
            'phone' => isset($safetyRow['phone']) ? (string)$safetyRow['phone'] : '',
            'biz_no' => isset($safetyRow['biz_no']) ? (string)$safetyRow['biz_no'] : '',
            'amount' => $amount,
            'remark' => isset($safetyRow['remark']) ? (string)$safetyRow['remark'] : ''
        );
    }
} catch (Exception $e) {}

usort($monthlyRows, function($a, $b) {
    $ad = isset($a['date']) ? (string)$a['date'] : '';
    $bd = isset($b['date']) ? (string)$b['date'] : '';
    if ($ad !== $bd) return strcmp($ad, $bd);
    $ac = isset($a['category']) ? (string)$a['category'] : '';
    $bc = isset($b['category']) ? (string)$b['category'] : '';
    if ($ac !== $bc) return strcmp($ac, $bc);
    $av = isset($a['vendor_name']) ? (string)$a['vendor_name'] : '';
    $bv = isset($b['vendor_name']) ? (string)$b['vendor_name'] : '';
    return strcmp($av, $bv);
});
$monthlyOverallTotal = $monthlyTotals['구매품'] + $monthlyTotals['자재비'] + $monthlyTotals['안전관리비'] + $monthlyTotals['기타경비'];

$bulkToken = isset($_GET['bulk_token']) ? trim((string)$_GET['bulk_token']) : '';
$bulkPreview = null;
$bulkPreviewRows = array();
$bulkPreviewMeta = array();
$bulkPreviewSaveableCount = 0;
if ($bulkToken !== '' && isset($_SESSION['material_bulk_preview'][$bulkToken]) && is_array($_SESSION['material_bulk_preview'][$bulkToken])) {
    $candidatePreview = $_SESSION['material_bulk_preview'][$bulkToken];
    if (isset($candidatePreview['project_id']) && (int)$candidatePreview['project_id'] === (int)$pid) {
        $bulkPreview = $candidatePreview;
        $bulkPreviewRows = (isset($bulkPreview['rows']) && is_array($bulkPreview['rows'])) ? $bulkPreview['rows'] : array();
        $bulkPreviewMeta = (isset($bulkPreview['meta']) && is_array($bulkPreview['meta'])) ? $bulkPreview['meta'] : array();
        foreach ($bulkPreviewRows as $bulkPreviewRow) {
            if (is_array($bulkPreviewRow) && isset($bulkPreviewRow['saveable']) && (int)$bulkPreviewRow['saveable'] === 1) {
                $bulkPreviewSaveableCount++;
            }
        }
    } else {
        $bulkToken = '';
    }
}
?>

<style>
.cpms-material-ko-ime input[type="text"], .cpms-material-ko-ime textarea { ime-mode: active; }
</style>
<div class="bg-white rounded-3xl border border-gray-200 p-6 shadow-sm cpms-material-ko-ime">
    <div class="flex flex-col lg:flex-row lg:items-end gap-3 justify-between">
        <div>
            <h3 class="text-xl font-extrabold text-gray-900">자재구입비</h3>
        </div>

        <form method="get" action="" class="flex items-end gap-2">
            <input type="hidden" name="r" value="공사">
            <input type="hidden" name="pid" value="<?php echo (int)$pid; ?>">
            <input type="hidden" name="tab" value="materials">
            <input type="hidden" name="materials_tab" value="<?php echo h($materialsTab); ?>">
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
        <a href="<?php echo h($baseUrl . '&materials_tab=monthly&ym=' . urlencode($ym)); ?>"
           class="px-4 py-2 rounded-xl border font-bold text-sm <?php echo ($materialsTab === 'monthly') ? 'bg-gray-900 text-white border-gray-900' : 'bg-white text-gray-800 border-gray-300'; ?>">월별자재구입비</a>
        <?php if ($canViewMaterialInput): ?>
            <a href="<?php echo h($baseUrl . '&materials_tab=input&ym=' . urlencode($ym)); ?>"
               class="px-4 py-2 rounded-xl border font-bold text-sm <?php echo ($materialsTab === 'input') ? 'bg-gray-900 text-white border-gray-900' : 'bg-white text-gray-800 border-gray-300'; ?>">자재구입비입력</a>
        <?php endif; ?>
    </div>

    <?php if ($materialsTab === 'input'): ?>
        <?php if ($canEditMaterials): ?>
        <div class="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="border border-gray-200 rounded-2xl p-4">
                <!-- 자재 입력 모달→토글형 인라인 통일 -->
                <div class="flex items-center justify-between mb-3">
                    <div class="text-lg font-extrabold">새작성</div>
                </div>
                <!-- 자재구입비입력 토글 제거 -->
                <!-- 자재구입비 입력폼 항상 표시 -->
                <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/material_item_save" class="space-y-3" id="materialCreateForm" enctype="multipart/form-data">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
                    <input type="hidden" name="materials_tab" value="input">
                    <input type="hidden" name="ym" value="<?php echo h($ym); ?>">

                    <div class="bg-gray-50 border border-gray-200 rounded-xl p-3 vendor-search-wrap">
                        <label class="text-sm font-bold text-gray-700">업체명 검색 자동완성</label>
                        <input type="text" class="mt-1 w-full px-3 py-2 border rounded-xl bg-white js-material-vendor-search" placeholder="업체명 2글자 이상 입력" lang="ko" inputmode="text" autocomplete="off">
                        <div class="vendor-suggest-list mt-2 hidden border border-gray-200 rounded-xl bg-white max-h-48 overflow-auto"></div>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <select name="category" class="px-3 py-2 border rounded-xl" required>
                            <option value="자재비">자재비</option>
                            <option value="구매품">구매품</option>
                            <option value="기타경비">기타경비</option>
                        </select>
                        <select name="advance_yn" class="px-3 py-2 border rounded-xl" required>
                            <option value="N">선급 N</option>
                            <option value="Y">선급 Y</option>
                        </select>
                        <input type="text" name="vendor_name" class="px-3 py-2 border rounded-xl" placeholder="업체명" required lang="ko" inputmode="text" autocomplete="off">
                        <!-- 자재: 규격 제거 -->
                        <input type="text" name="representative" class="px-3 py-2 border rounded-xl" placeholder="대표자명" lang="ko" inputmode="text" autocomplete="off">
                        <input type="text" name="phone" class="px-3 py-2 border rounded-xl" placeholder="전화번호" lang="ko" inputmode="text" autocomplete="off">
                        <input type="text" name="biz_no" class="px-3 py-2 border rounded-xl" placeholder="사업자등록번호" lang="ko" inputmode="text" autocomplete="off">
                        <!-- 자재: 공급가액 표기 -->
                        <input type="number" step="0.01" min="0" name="base_rate" class="px-3 py-2 border rounded-xl" placeholder="공급가액">
                        <input type="text" name="remark" class="px-3 py-2 border rounded-xl" placeholder="비고" lang="ko" inputmode="text" autocomplete="off">
                    </div>

                    <div class="border border-gray-200 rounded-xl p-3" data-calendar-wrapper data-ym="<?php echo h($ym); ?>" data-prev-ym="<?php echo h($prevYm); ?>" data-target="materialCreateDateInputs" data-chip-target="materialCreateDateChips" data-prev-grid-target="materialCreateCalPrev" data-curr-grid-target="materialCreateCalCurr">
                        <div class="flex items-center justify-between gap-2 mb-2">
                            <label class="text-sm font-bold text-gray-700">사용일자(<?php echo h($prevYm); ?> 26일~<?php echo h($ym); ?> 25일, 복수 선택)</label>
                            <button type="button" class="px-3 py-1 rounded-lg border border-gray-300 text-sm" data-toggle-calendar>날짜 선택</button>
                        </div>
                        <div class="hidden border border-gray-200 rounded-lg p-2 bg-gray-50" data-calendar-box>
                            <div class="grid grid-cols-1 xl:grid-cols-2 gap-3">
                                <div id="materialCreateCalPrev"></div>
                                <div id="materialCreateCalCurr"></div>
                            </div>
                        </div>
                        <div id="materialCreateDateChips" class="mt-2 flex flex-wrap gap-2"></div>
                        <div id="materialCreateDateInputs"></div>
                    </div>

                    <div class="border border-gray-200 rounded-xl p-3">
                        <label class="text-sm font-bold text-gray-700">거래명세표 첨부</label>
                        <input type="file" name="statement_file" accept=".pdf,.jpg,.jpeg,.png,.xlsx,.xls" class="mt-2 block w-full text-sm">
                        <div class="text-xs text-gray-500 mt-1">PDF, 이미지, 엑셀 파일 업로드 가능</div>
                    </div>

                    <button type="submit" class="px-4 py-2 rounded-xl bg-blue-600 text-white font-bold">저장</button>
                </form>                
            </div>

            <div class="border border-gray-200 rounded-2xl p-4">
                <div class="flex flex-col gap-1 mb-4">
                    <div class="text-lg font-extrabold">월별 자재구입비 엑셀 업로드</div>
                </div>

                <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/material_item_save" enctype="multipart/form-data" class="space-y-3">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
                    <input type="hidden" name="materials_tab" value="input">
                    <input type="hidden" name="ym" value="<?php echo h($ym); ?>">
                    <input type="hidden" name="bulk_action" value="preview">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="text-sm font-bold text-gray-700">등록할 정산월</label>
                            <select name="bulk_ym" class="mt-1 w-full px-3 py-2 border rounded-xl" required>
                                <?php foreach ($monthOptions as $opt): ?>
                                    <option value="<?php echo h($opt); ?>" <?php echo ($opt === $ym) ? 'selected' : ''; ?>>
                                        <?php echo h(substr($opt, 0, 4) . '년 ' . substr($opt, 5, 2) . '월분'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="text-sm font-bold text-gray-700">엑셀 파일</label>
                            <input type="file" name="bulk_xlsx" accept=".xlsx" class="mt-1 block w-full text-sm border rounded-xl px-3 py-2" required>
                        </div>
                    </div>

                    <div class="rounded-xl bg-gray-50 border border-gray-200 p-3 text-xs text-gray-600 leading-5">
                        시트명은 3.구매,자재,경비를 먼저 읽고, 없으면 첫 번째 시트를 확인합니다. 3행 헤더, 4행 데이터부터 A~J열을 읽습니다.
                        A열 일이 26 이상이면 전월 날짜, 1~25이면 선택월 날짜로 변환합니다.
                    </div>
                    <button type="submit" class="px-4 py-2 rounded-xl bg-gray-900 text-white font-bold">업로드/미리보기</button>
                </form>

                <?php if ($bulkPreview !== null): ?>
                    <?php
                    $bulkPreviewYm = isset($bulkPreview['ym']) ? (string)$bulkPreview['ym'] : $ym;
                    $bulkPreviewOriginalName = isset($bulkPreview['original_name']) ? (string)$bulkPreview['original_name'] : '';
                    $bulkSheetName = isset($bulkPreviewMeta['sheet_name']) ? (string)$bulkPreviewMeta['sheet_name'] : '';
                    $bulkUsedFallback = isset($bulkPreviewMeta['used_fallback']) ? (int)$bulkPreviewMeta['used_fallback'] : 0;
                    ?>
                    <div class="mt-5 border-t border-gray-200 pt-4">
                        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2 mb-3">
                            <div>
                                <div class="font-extrabold text-gray-900">미리보기</div>
                                <div class="text-xs text-gray-500">
                                    <?php echo h($bulkPreviewYm); ?>월분 · <?php echo h($bulkPreviewOriginalName); ?> · 시트: <?php echo h($bulkSheetName); ?>
                                    <?php if ($bulkUsedFallback): ?> · 지정 시트가 없어 첫 번째 시트를 확인했습니다.<?php endif; ?>
                                </div>
                            </div>
                            <div class="text-xs text-gray-600">
                                정상 <?php echo (int)(isset($bulkPreviewMeta['normal_count']) ? $bulkPreviewMeta['normal_count'] : 0); ?>건 /
                                제외 <?php echo (int)(isset($bulkPreviewMeta['excluded_count']) ? $bulkPreviewMeta['excluded_count'] : 0); ?>건 /
                                오류 <?php echo (int)(isset($bulkPreviewMeta['error_count']) ? $bulkPreviewMeta['error_count'] : 0); ?>건 /
                                중복 <?php echo (int)(isset($bulkPreviewMeta['duplicate_count']) ? $bulkPreviewMeta['duplicate_count'] : 0); ?>건
                            </div>
                        </div>

                        <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/material_item_save">
                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                            <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
                            <input type="hidden" name="materials_tab" value="input">
                            <input type="hidden" name="ym" value="<?php echo h($bulkPreviewYm); ?>">
                            <input type="hidden" name="bulk_action" value="apply">
                            <input type="hidden" name="bulk_token" value="<?php echo h($bulkToken); ?>">

                            <div class="overflow-auto max-h-[520px] border border-gray-200 rounded-xl">
                                <table class="min-w-[1500px] w-full text-xs border-collapse">
                                    <thead>
                                    <tr class="bg-gray-50">
                                        <th class="p-2 border text-center">등록</th>
                                        <th class="p-2 border text-center">원본 일</th>
                                        <th class="p-2 border text-left">사용일자</th>
                                        <th class="p-2 border text-left">구분</th>
                                        <th class="p-2 border text-center">선급여부</th>
                                        <th class="p-2 border text-left">업체명</th>
                                        <th class="p-2 border text-left">내역</th>
                                        <th class="p-2 border text-left">대표자명</th>
                                        <th class="p-2 border text-left">전화번호</th>
                                        <th class="p-2 border text-left">사업자등록번호</th>
                                        <th class="p-2 border text-right">공급가액</th>
                                        <th class="p-2 border text-left">비고</th>
                                        <th class="p-2 border text-left">상태</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($bulkPreviewRows as $bulkIdx => $bulkRow): ?>
                                        <?php
                                        $bulkRowStatusType = isset($bulkRow['status_type']) ? (string)$bulkRow['status_type'] : 'normal';
                                        $bulkRowSaveable = (isset($bulkRow['saveable']) && (int)$bulkRow['saveable'] === 1);
                                        $bulkRowClass = '';
                                        if ($bulkRowStatusType === 'error') $bulkRowClass = 'bg-red-50';
                                        else if ($bulkRowStatusType === 'excluded') $bulkRowClass = 'bg-amber-50';
                                        else if ($bulkRowStatusType === 'duplicate') $bulkRowClass = 'bg-gray-100';
                                        else if ($bulkRowStatusType === 'negative') $bulkRowClass = 'bg-yellow-50';
                                        ?>
                                        <tr class="<?php echo h($bulkRowClass); ?>">
                                            <td class="p-2 border text-center">
                                                <?php if ($bulkRowSaveable): ?>
                                                    <input type="checkbox" name="rows[<?php echo (int)$bulkIdx; ?>][include]" value="1" checked>
                                                <?php else: ?>
                                                    <span class="text-gray-400">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="p-2 border text-center"><?php echo h(isset($bulkRow['raw_day']) ? $bulkRow['raw_day'] : ''); ?></td>
                                            <td class="p-2 border whitespace-nowrap"><?php echo h(isset($bulkRow['use_date']) ? $bulkRow['use_date'] : ''); ?></td>
                                            <td class="p-2 border">
                                                <?php if ($bulkRowSaveable): ?>
                                                    <select name="rows[<?php echo (int)$bulkIdx; ?>][category]" class="w-full min-w-[90px] px-2 py-1 border rounded">
                                                        <?php foreach (array('자재비', '구매품', '기타경비') as $bulkCategoryOption): ?>
                                                            <option value="<?php echo h($bulkCategoryOption); ?>" <?php echo ((isset($bulkRow['category']) ? (string)$bulkRow['category'] : '') === $bulkCategoryOption) ? 'selected' : ''; ?>><?php echo h($bulkCategoryOption); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                <?php else: ?>
                                                    <?php echo h(isset($bulkRow['category']) ? $bulkRow['category'] : ''); ?>
                                                <?php endif; ?>
                                            </td>
                                            <td class="p-2 border text-center">
                                                <?php if ($bulkRowSaveable): ?>
                                                    <select name="rows[<?php echo (int)$bulkIdx; ?>][advance_yn]" class="w-full min-w-[70px] px-2 py-1 border rounded">
                                                        <option value="N" <?php echo ((isset($bulkRow['advance_yn']) ? (string)$bulkRow['advance_yn'] : 'N') === 'N') ? 'selected' : ''; ?>>N</option>
                                                        <option value="Y" <?php echo ((isset($bulkRow['advance_yn']) ? (string)$bulkRow['advance_yn'] : 'N') === 'Y') ? 'selected' : ''; ?>>Y</option>
                                                    </select>
                                                <?php else: ?>
                                                    <?php echo h(isset($bulkRow['advance_yn']) ? $bulkRow['advance_yn'] : 'N'); ?>
                                                <?php endif; ?>
                                            </td>
                                            <?php
                                            $bulkEditableFields = array(
                                                'vendor_name'=>array('label'=>'업체명', 'class'=>'min-w-[140px]'),
                                                'detail'=>array('label'=>'내역', 'class'=>'min-w-[140px]'),
                                                'representative'=>array('label'=>'대표자명', 'class'=>'min-w-[110px]'),
                                                'phone'=>array('label'=>'전화번호', 'class'=>'min-w-[120px]'),
                                                'biz_no'=>array('label'=>'사업자등록번호', 'class'=>'min-w-[140px]')
                                            );
                                            foreach ($bulkEditableFields as $bulkFieldName => $bulkFieldInfo):
                                            ?>
                                                <td class="p-2 border">
                                                    <?php if ($bulkRowSaveable): ?>
                                                        <input type="text" name="rows[<?php echo (int)$bulkIdx; ?>][<?php echo h($bulkFieldName); ?>]" value="<?php echo h(isset($bulkRow[$bulkFieldName]) ? $bulkRow[$bulkFieldName] : ''); ?>" class="w-full <?php echo h($bulkFieldInfo['class']); ?> px-2 py-1 border rounded" lang="ko" inputmode="text" autocomplete="off">
                                                    <?php else: ?>
                                                        <?php echo h(isset($bulkRow[$bulkFieldName]) ? $bulkRow[$bulkFieldName] : ''); ?>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endforeach; ?>
                                            <td class="p-2 border text-right">
                                                <?php if ($bulkRowSaveable): ?>
                                                    <input type="text" name="rows[<?php echo (int)$bulkIdx; ?>][amount]" value="<?php echo h(material_money(isset($bulkRow['amount']) ? $bulkRow['amount'] : 0)); ?>" class="w-full min-w-[110px] px-2 py-1 border rounded text-right">
                                                <?php else: ?>
                                                    <?php echo material_money(isset($bulkRow['amount']) ? $bulkRow['amount'] : 0); ?>
                                                <?php endif; ?>
                                            </td>
                                            <td class="p-2 border">
                                                <?php if ($bulkRowSaveable): ?>
                                                    <input type="text" name="rows[<?php echo (int)$bulkIdx; ?>][remark]" value="<?php echo h(isset($bulkRow['remark']) ? $bulkRow['remark'] : ''); ?>" class="w-full min-w-[140px] px-2 py-1 border rounded" lang="ko" inputmode="text" autocomplete="off">
                                                <?php else: ?>
                                                    <?php echo h(isset($bulkRow['remark']) ? $bulkRow['remark'] : ''); ?>
                                                <?php endif; ?>
                                            </td>
                                            <td class="p-2 border min-w-[180px]"><?php echo h(isset($bulkRow['status']) ? $bulkRow['status'] : ''); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="mt-3 flex flex-col md:flex-row md:items-center md:justify-between gap-2">
                                <div class="text-xs text-gray-500">정상 행만 기본 등록 대상입니다. 오류, 제외, 중복 행은 저장되지 않습니다.</div>
                                <button type="submit" class="px-4 py-2 rounded-xl bg-blue-600 text-white font-bold" <?php echo ($bulkPreviewSaveableCount <= 0) ? 'disabled' : ''; ?>>일괄등록</button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="mt-6 border border-gray-200 rounded-2xl p-4">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2 mb-3">
                <div>
                    <div class="text-lg font-extrabold">입력내역 목록</div>
                    <?php if ($canEditMaterials && count($usageRows) > 0): ?>
                        <div class="text-xs text-gray-500 mt-1">잘못 업로드한 자료는 행을 체크한 뒤 선택 삭제할 수 있습니다.</div>
                    <?php endif; ?>
                </div>
                <?php if ($canEditMaterials && count($usageRows) > 0): ?>
                    <button type="submit" form="materialUsageBulkDeleteForm" class="px-3 py-2 rounded-xl border border-red-300 text-red-600 font-bold text-sm" onclick="return confirm('선택한 자재구입비 사용내역을 삭제할까요? 월별 자재구입비와 상황탭 집계에서도 제외됩니다.');">선택 삭제</button>
                <?php endif; ?>
            </div>
            <?php if ($canEditMaterials && count($usageRows) > 0): ?>
            <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/material_usage_delete" id="materialUsageBulkDeleteForm">
                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
                <input type="hidden" name="materials_tab" value="input">
                <input type="hidden" name="ym" value="<?php echo h($ym); ?>">
            <?php endif; ?>
            <div class="max-h-[420px] overflow-auto">
                <table class="w-full text-sm border-collapse">
                    <thead>
                    <tr class="bg-gray-50">
                        <?php if ($canEditMaterials): ?>
                            <th class="p-2 border text-center w-12">
                                <?php if (count($usageRows) > 0): ?>
                                    <input type="checkbox" id="materialUsageSelectAll" title="전체 선택">
                                <?php endif; ?>
                            </th>
                        <?php endif; ?>
                        <th class="p-2 border text-left">사용일자</th>
                        <th class="p-2 border text-left">구분</th>
                        <th class="p-2 border text-center">선급여부</th>
                        <th class="p-2 border text-left">업체명</th>
                        <th class="p-2 border text-right">금액</th>
                        <th class="p-2 border text-left">비고</th>
                        <th class="p-2 border text-left">거래명세표</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (count($usageRows) === 0): ?>
                        <tr><td colspan="<?php echo $canEditMaterials ? 8 : 7; ?>" class="p-3 border text-center text-gray-500">입력된 사용내역이 없습니다.</td></tr>
                    <?php else: ?>
                        <?php foreach ($usageRows as $ur): ?>
                            <?php
                            $usageIdForList = isset($ur['id']) ? (int)$ur['id'] : 0;
                            $listFiles = ($usageIdForList > 0 && isset($statementFilesByUsage[$usageIdForList])) ? $statementFilesByUsage[$usageIdForList] : array();
                            $usageIsNegative = (isset($ur['amount']) && (float)$ur['amount'] < 0);
                            ?>
                            <tr class="<?php echo $usageIsNegative ? 'bg-yellow-50' : ''; ?>">
                                <?php if ($canEditMaterials): ?>
                                    <td class="p-2 border text-center">
                                        <?php if ($usageIdForList > 0): ?>
                                            <input type="checkbox" name="usage_ids[]" value="<?php echo (int)$usageIdForList; ?>" class="material-usage-delete-check">
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <td class="p-2 border whitespace-nowrap"><?php echo h(isset($ur['use_date']) ? $ur['use_date'] : ''); ?></td>
                                <td class="p-2 border"><?php echo h(material_category_label(isset($ur['category']) ? $ur['category'] : '')); ?></td>
                                <td class="p-2 border text-center"><?php echo h(cpms_material_advance_yn(isset($ur['advance_yn']) ? $ur['advance_yn'] : 'N')); ?></td>
                                <td class="p-2 border"><?php echo h(isset($ur['vendor_name']) ? $ur['vendor_name'] : ''); ?></td>
                                <td class="p-2 border text-right <?php echo $usageIsNegative ? 'font-extrabold text-amber-800' : ''; ?>"><?php echo material_money(isset($ur['amount']) ? $ur['amount'] : 0); ?></td>
                                <td class="p-2 border"><?php echo h(isset($ur['memo']) ? $ur['memo'] : ''); ?></td>
                                <td class="p-2 border">
                                    <div class="flex flex-wrap gap-1">
                                        <?php echo material_statement_links_html($listFiles, '거래명세표 다운로드', $canDownloadMaterialStatements, '첨부 없음'); ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($canEditMaterials && count($usageRows) > 0): ?>
            </form>
            <?php endif; ?>
        </div>


        <script>
        (function(){
            var selectedYm = <?php echo json_encode($ym); ?>;
            // 자재구입비 달력(전월/현월 2달력)
            window.cpmsMaterialRange = {
                ym: <?php echo json_encode($ym); ?>,
                prevYm: <?php echo json_encode($prevYm); ?>,
                prevLastDay: <?php echo (int)$prevLastDay; ?>,
                start: <?php echo json_encode($monthlyStart); ?>,
                end: <?php echo json_encode($monthlyEnd); ?>
            };
            var rangeInfo = window.cpmsMaterialRange || {};

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
            var createForm = document.getElementById('materialCreateForm');
            var materialVendorTimers = {};
            function keepKoreanInputMode(root){
                if (!root || !root.querySelectorAll) return;
                var fields = root.querySelectorAll('input[type="text"], textarea');
                for (var i=0; i<fields.length; i++) {
                    fields[i].setAttribute('lang', 'ko');
                    fields[i].setAttribute('inputmode', 'text');
                    fields[i].setAttribute('autocomplete', 'off');
                    fields[i].style.imeMode = 'active';
                }
            }
            keepKoreanInputMode(document);
            var usageSelectAll = document.getElementById('materialUsageSelectAll');
            var usageDeleteForm = document.getElementById('materialUsageBulkDeleteForm');
            if (usageSelectAll) {
                usageSelectAll.addEventListener('change', function(){
                    var checks = document.querySelectorAll('.material-usage-delete-check');
                    for (var i=0; i<checks.length; i++) {
                        checks[i].checked = usageSelectAll.checked;
                    }
                });
            }
            if (usageDeleteForm) {
                usageDeleteForm.addEventListener('submit', function(ev){
                    var checks = usageDeleteForm.querySelectorAll('.material-usage-delete-check');
                    var hasChecked = false;
                    for (var i=0; i<checks.length; i++) {
                        if (checks[i].checked) { hasChecked = true; break; }
                    }
                    if (!hasChecked) {
                        ev.preventDefault();
                        alert('삭제할 사용내역을 선택해주세요.');
                    }
                });
            }
            function hideSuggestList(listEl){ if(!listEl)return; listEl.innerHTML=''; if(listEl.className.indexOf('hidden')===-1) listEl.className += ' hidden'; listEl.style.display='none'; }
            function showSuggestList(listEl){ if(!listEl)return; listEl.className=listEl.className.replace(/\bhidden\b/g,'').replace(/\s+/g,' ').trim(); listEl.style.display='block'; }
            function fillMaterialPreset(formEl, p){ if(!formEl||!p)return; var allowed={'자재비':1,'구매품':1,'기타경비':1}; if(formEl.elements['category']) formEl.elements['category'].value=allowed[p.category]?p.category:'자재비'; if(formEl.elements['vendor_name']) formEl.elements['vendor_name'].value=p.vendor_name||''; if(formEl.elements['representative']) formEl.elements['representative'].value=p.representative||''; if(formEl.elements['phone']) formEl.elements['phone'].value=p.phone||''; if(formEl.elements['biz_no']) formEl.elements['biz_no'].value=p.biz_no||''; if(formEl.elements['base_rate']) formEl.elements['base_rate'].value=p.base_rate||''; if(formEl.elements['remark']) formEl.elements['remark'].value=p.remark||''; }
            function renderMaterialSuggestions(inputEl, rows){ var wrap=inputEl?inputEl.closest('.vendor-search-wrap'):null; var listEl=wrap?wrap.querySelector('.vendor-suggest-list'):null; if(!listEl)return; listEl.innerHTML=''; if(!rows||!rows.length){ var empty=document.createElement('div'); empty.className='px-3 py-2 text-sm text-gray-500'; empty.textContent='검색 결과 없음'; listEl.appendChild(empty); showSuggestList(listEl); return; } for(var i=0;i<rows.length;i++){ (function(row){ var btn=document.createElement('button'); btn.type='button'; btn.className='block w-full text-left px-3 py-2 border-b last:border-b-0 hover:bg-blue-50'; btn.textContent=(row.vendor_name||'') + (row.phone ? ' ('+row.phone+')' : ''); btn.setAttribute('data-material-vendor-item','1'); btn.vendorData=row; btn.addEventListener('mousedown', function(ev){ ev.preventDefault(); }); listEl.appendChild(btn);} )(rows[i]); } showSuggestList(listEl); }
            document.addEventListener('input', function(e){ var inputEl=e.target; if(!inputEl||inputEl.className.indexOf('js-material-vendor-search')===-1) return; var wrap=inputEl.closest('.vendor-search-wrap'); var listEl=wrap?wrap.querySelector('.vendor-suggest-list'):null; if(!listEl)return; var q=(inputEl.value||'').trim(); if(materialVendorTimers[inputEl]) clearTimeout(materialVendorTimers[inputEl]); if(q.length<2){ hideSuggestList(listEl); return; } materialVendorTimers[inputEl]=setTimeout(function(){ // 프리셋 최신 검색
                var xhr=new XMLHttpRequest(); xhr.open('GET','<?php echo h(base_url()); ?>/?r=construction/material_vendor_search&q='+encodeURIComponent(q),true); xhr.onreadystatechange=function(){ if(xhr.readyState!==4)return; var rows=[]; if(xhr.status===200){ try{var json=JSON.parse(xhr.responseText); rows=(json&&json.items)?json.items:[];}catch(err){rows=[];} } renderMaterialSuggestions(inputEl, rows); }; xhr.send(); },250); });
            document.addEventListener('click', function(e){ var target=e.target; if(target&&target.getAttribute&&target.getAttribute('data-material-vendor-item')==='1'){ var wrap=target.closest('.vendor-search-wrap'); var inputEl=wrap?wrap.querySelector('.js-material-vendor-search'):null; var formEl=target.closest('form'); // 자동채움 재초기화
                fillMaterialPreset(formEl, target.vendorData||{}); if(inputEl) { inputEl.value=(target.vendorData&&target.vendorData.vendor_name)?target.vendorData.vendor_name:''; inputEl.focus(); } hideSuggestList(wrap?wrap.querySelector('.vendor-suggest-list'):null); return; } var lists=document.querySelectorAll('.vendor-search-wrap .vendor-suggest-list'); for(var i=0;i<lists.length;i++){ if(!lists[i].contains(target)) hideSuggestList(lists[i]); } });
            // 자재구입비 사용일자 달력 선택
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
        <style>
        .cpms-material-monthly-wrap { max-height: 72vh; background: #fff; }
        .cpms-material-sheet { min-width: 1280px; border-collapse: collapse; table-layout: fixed; font-size: 12px; color: #111827; }
        .cpms-material-sheet th, .cpms-material-sheet td { border: 1px solid #6b7280; height: 30px; padding: 0 8px; line-height: 1.2; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .cpms-material-sheet .sheet-group { background: #bfbfbf; text-align: center; font-weight: 700; }
        .cpms-material-sheet .sheet-total { background: #f7c7a8; text-align: right; font-weight: 700; }
        .cpms-material-sheet .sheet-head { background: #bfbfbf; text-align: center; font-weight: 700; }
        .cpms-material-sheet .sheet-empty-row td { color: transparent; }
        .cpms-material-sheet .text-right { text-align: right; }
        .cpms-material-sheet .text-center { text-align: center; }
        .cpms-material-sheet .sheet-muted { color: #6b7280; }
        @media (max-width: 900px) {
            .cpms-material-sheet { min-width: 1080px; font-size: 11px; }
            .cpms-material-sheet th, .cpms-material-sheet td { height: 28px; padding: 0 6px; }
        }
        </style>
        <div class="mt-6 overflow-auto cpms-material-monthly-wrap border border-gray-300">
            <table class="cpms-material-sheet w-full">
                <colgroup>
                    <col style="width:120px;">
                    <col style="width:130px;">
                    <col style="width:90px;">
                    <col style="width:140px;">
                    <col style="width:150px;">
                    <col style="width:110px;">
                    <col style="width:120px;">
                    <col style="width:150px;">
                    <col style="width:140px;">
                    <col style="width:150px;">
                </colgroup>
                <thead>
                    <tr>
                        <th class="sheet-group" colspan="2">구매품</th>
                        <th class="sheet-group" colspan="2">자재비</th>
                        <th class="sheet-group" colspan="2">안전관리비</th>
                        <th class="sheet-group" colspan="2">기타경비</th>
                        <th class="sheet-group" colspan="2">합계</th>
                    </tr>
                    <tr>
                        <td class="sheet-total" colspan="2"><?php echo material_money_or_dash($monthlyTotals['구매품']); ?></td>
                        <td class="sheet-total" colspan="2"><?php echo material_money_or_dash($monthlyTotals['자재비']); ?></td>
                        <td class="sheet-total" colspan="2"><?php echo material_money_or_dash($monthlyTotals['안전관리비']); ?></td>
                        <td class="sheet-total" colspan="2"><?php echo material_money_or_dash($monthlyTotals['기타경비']); ?></td>
                        <td class="sheet-total" colspan="2"><?php echo material_money_or_dash($monthlyOverallTotal); ?></td>
                    </tr>
                    <tr>
                        <th class="sheet-head">일</th>
                        <th class="sheet-head">구분</th>
                        <th class="sheet-head">선급여부</th>
                        <th class="sheet-head">업체명</th>
                        <th class="sheet-head">내역</th>
                        <th class="sheet-head">대표자명</th>
                        <th class="sheet-head">전화번호</th>
                        <th class="sheet-head">사업자등록번호</th>
                        <th class="sheet-head">공급가액</th>
                        <th class="sheet-head">비고</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (count($monthlyRows) > 0): ?>
                        <?php foreach ($monthlyRows as $row): ?>
                        <?php $monthlyRowIsNegative = (isset($row['amount']) && (float)$row['amount'] < 0); ?>
                        <tr class="<?php echo $monthlyRowIsNegative ? 'bg-yellow-50' : ''; ?>">
                            <td class="text-center"><?php echo h(isset($row['date']) ? $row['date'] : ''); ?></td>
                            <td class="text-center"><?php echo h(isset($row['category']) ? $row['category'] : ''); ?></td>
                            <td class="text-center"><?php echo h(isset($row['advance_yn']) ? $row['advance_yn'] : 'N'); ?></td>
                            <td><?php echo h(isset($row['vendor_name']) ? $row['vendor_name'] : ''); ?></td>
                            <td><?php echo h(isset($row['detail']) ? $row['detail'] : ''); ?></td>
                            <td><?php echo h(isset($row['representative']) ? $row['representative'] : ''); ?></td>
                            <td><?php echo h(isset($row['phone']) ? $row['phone'] : ''); ?></td>
                            <td><?php echo h(isset($row['biz_no']) ? $row['biz_no'] : ''); ?></td>
                            <td class="text-right <?php echo $monthlyRowIsNegative ? 'font-extrabold text-amber-800' : ''; ?>"><?php echo material_money(isset($row['amount']) ? $row['amount'] : 0); ?></td>
                            <td><?php echo h(isset($row['remark']) ? $row['remark'] : ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php
                $blankRowCount = 32 - count($monthlyRows);
                if ($blankRowCount < 4) $blankRowCount = 4;
                for ($i = 0; $i < $blankRowCount; $i++):
                ?>
                    <tr class="sheet-empty-row">
                        <td>&nbsp;</td>
                        <td>&nbsp;</td>
                        <td>&nbsp;</td>
                        <td>&nbsp;</td>
                        <td>&nbsp;</td>
                        <td>&nbsp;</td>
                        <td>&nbsp;</td>
                        <td>&nbsp;</td>
                        <td>&nbsp;</td>
                        <td>&nbsp;</td>
                    </tr>
                <?php endfor; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
