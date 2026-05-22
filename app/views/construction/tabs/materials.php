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

$pdo = Db::pdo();
if (!$pdo) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">DB 연결 실패</div>';
    return;
}

$materialsTab = isset($_GET['materials_tab']) ? trim((string)$_GET['materials_tab']) : 'monthly';
if ($materialsTab !== 'monthly' && $materialsTab !== 'input') {
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

$baseUrl = base_url() . '/?r=공사&pid=' . (int)$pid . '&tab=materials';
// 달력 전월/현월 계산 수정
$currFirst = new DateTime($ym . '-01');
$prevFirst = clone $currFirst;
$prevFirst->modify('-1 month');
$prevYm = $prevFirst->format('Y-m');
$prevLastDay = (int)$prevFirst->format('t');
$monthlyStart = $prevYm . '-26';
$monthlyEnd = $ym . '-25';

$monthOptions = array();
for ($i = -12; $i <= 12; $i++) {
    $optYm = date('Y-m', strtotime($ym . '-01 ' . ($i >= 0 ? '+' . $i : $i) . ' month'));
    $monthOptions[] = $optYm;
}

$items = array();
$itemMap = array();
$usageRows = array();
$usageByEquipment = array();
$usageByDate = array();
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
        $stUsage = $pdo->prepare("SELECT u.*, i.category, i.vendor_name
            FROM cpms_material_usage u
            JOIN cpms_material_items i ON i.id = u.material_id
            WHERE u.project_id = :pid
              AND i.is_deleted = 0
              AND u.use_date BETWEEN :s AND :e
            ORDER BY i.category ASC, i.vendor_name ASC, u.use_date ASC");
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
function material_category_label($category)
{
    $category = trim((string)$category);
    $allowed = array('자재비'=>true, '구매품'=>true, '기타경비'=>true, '안전관리비'=>true);
    return isset($allowed[$category]) ? $category : '자재비';
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
?>

<div class="bg-white rounded-3xl border border-gray-200 p-6 shadow-sm">
    <div class="flex flex-col lg:flex-row lg:items-end gap-3 justify-between">
        <div>
            <h3 class="text-xl font-extrabold text-gray-900">자재구입비</h3>
            <div class="text-sm text-gray-600 mt-1">월별 양식 출력 + 월별 입력</div>
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
            </div>
            <button type="submit" class="px-4 py-2 rounded-xl bg-gray-900 text-white font-bold text-sm">적용</button>
        </form>
    </div>

    <div class="mt-4 flex flex-wrap gap-2">
        <a href="<?php echo h($baseUrl . '&materials_tab=monthly&ym=' . urlencode($ym)); ?>"
           class="px-4 py-2 rounded-xl border font-bold text-sm <?php echo ($materialsTab === 'monthly') ? 'bg-gray-900 text-white border-gray-900' : 'bg-white text-gray-800 border-gray-300'; ?>">월별자재구입비</a>
        <a href="<?php echo h($baseUrl . '&materials_tab=input&ym=' . urlencode($ym)); ?>"
           class="px-4 py-2 rounded-xl border font-bold text-sm <?php echo ($materialsTab === 'input') ? 'bg-gray-900 text-white border-gray-900' : 'bg-white text-gray-800 border-gray-300'; ?>">자재구입비입력</a>
    </div>

    <?php if ($materialsTab === 'input'): ?>
        <div class="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="border border-gray-200 rounded-2xl p-4">
                <!-- 자재 입력 모달→토글형 인라인 통일 -->
                <div class="flex items-center justify-between mb-3">
                    <div class="text-lg font-extrabold">새작성</div>
                </div>
                <!-- 자재구입비입력 토글 제거 -->
                <!-- 자재구입비 입력폼 항상 표시 -->
                <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/material_item_save" class="space-y-3" id="materialCreateForm">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
                    <input type="hidden" name="materials_tab" value="input">
                    <input type="hidden" name="ym" value="<?php echo h($ym); ?>">

                    <div class="bg-gray-50 border border-gray-200 rounded-xl p-3 vendor-search-wrap">
                        <label class="text-sm font-bold text-gray-700">업체명 검색 자동완성</label>
                        <input type="text" class="mt-1 w-full px-3 py-2 border rounded-xl bg-white js-material-vendor-search" placeholder="업체명 2글자 이상 입력">
                        <div class="vendor-suggest-list mt-2 hidden border border-gray-200 rounded-xl bg-white max-h-48 overflow-auto"></div>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <select name="category" class="px-3 py-2 border rounded-xl" required>
                            <option value="자재비">자재비</option>
                            <option value="구매품">구매품</option>
                            <option value="기타경비">기타경비</option>
                            <option value="안전관리비">안전관리비</option>
                        </select>
                        <input type="text" name="vendor_name" class="px-3 py-2 border rounded-xl" placeholder="업체명" required>
                        <!-- 자재: 규격 제거 -->
                        <input type="text" name="representative" class="px-3 py-2 border rounded-xl" placeholder="대표자명">
                        <input type="text" name="phone" class="px-3 py-2 border rounded-xl" placeholder="전화번호">
                        <input type="text" name="biz_no" class="px-3 py-2 border rounded-xl" placeholder="사업자등록번호">
                        <!-- 자재: 공급가액 표기 -->
                        <input type="number" step="0.01" min="0" name="base_rate" class="px-3 py-2 border rounded-xl" placeholder="공급가액">
                        <input type="text" name="remark" class="px-3 py-2 border rounded-xl" placeholder="비고">
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

                    <button type="submit" class="px-4 py-2 rounded-xl bg-blue-600 text-white font-bold">저장</button>
                </form>                
            </div>

            <div class="border border-gray-200 rounded-2xl p-4">
                <div class="text-lg font-extrabold mb-3">등록 자재구입비 + 사용일자 추가</div>
                <div class="max-h-[520px] overflow-auto">
                    <table class="w-full text-sm border-collapse">
                        <thead>
                        <tr class="bg-gray-50">
                            <th class="p-2 border text-left">구분</th>
                            <th class="p-2 border text-left">업체명</th>
                            <!-- 자재: 규격 제거 -->
                            <th class="p-2 border text-right">공급가액</th>
                            <th class="p-2 border text-left">사용일자 추가</th>
                            <th class="p-2 border text-center">관리</th>                        
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (count($items) === 0): ?>
                            <tr><td colspan="5" class="p-3 border text-center text-gray-500">등록된 자재구입비가 없습니다.</td></tr>
                        <?php else: ?>
                            <?php foreach ($items as $it): ?>
                                <tr>
                                    <td class="p-2 border"><?php echo h(material_category_label($it['category'])); ?></td>
                                    <td class="p-2 border"><?php echo h($it['vendor_name']); ?></td>
                                    <!-- 자재: 규격 제거 -->
                                    <!-- 자재: 공급가액 표기 -->
                                    <td class="p-2 border text-right"><?php echo material_money($it['base_rate']); ?></td>
                                    <td class="p-2 border">
                                        <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/material_usage_save" class="space-y-2" data-usage-form>
                                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                            <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
                                            <input type="hidden" name="material_id" value="<?php echo (int)$it['id']; ?>">
                                            <input type="hidden" name="materials_tab" value="input">
                                            <input type="hidden" name="ym" value="<?php echo h($ym); ?>">

                                            <!-- 자재구입비 달력(전월/현월 2달력) -->
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
                                        <!-- 등록 자재구입비 삭제 -->
                                        <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/material_item_delete" onsubmit="return confirm('삭제할까요?');">
                                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                            <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
                                            <input type="hidden" name="material_id" value="<?php echo (int)$it['id']; ?>">
                                            <input type="hidden" name="materials_tab" value="input">
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
            function hideSuggestList(listEl){ if(!listEl)return; listEl.innerHTML=''; if(listEl.className.indexOf('hidden')===-1) listEl.className += ' hidden'; listEl.style.display='none'; }
            function showSuggestList(listEl){ if(!listEl)return; listEl.className=listEl.className.replace(/\bhidden\b/g,'').replace(/\s+/g,' ').trim(); listEl.style.display='block'; }
            function fillMaterialPreset(formEl, p){ if(!formEl||!p)return; var allowed={'자재비':1,'구매품':1,'기타경비':1,'안전관리비':1}; if(formEl.elements['category']) formEl.elements['category'].value=allowed[p.category]?p.category:'자재비'; if(formEl.elements['vendor_name']) formEl.elements['vendor_name'].value=p.vendor_name||''; if(formEl.elements['representative']) formEl.elements['representative'].value=p.representative||''; if(formEl.elements['phone']) formEl.elements['phone'].value=p.phone||''; if(formEl.elements['biz_no']) formEl.elements['biz_no'].value=p.biz_no||''; if(formEl.elements['base_rate']) formEl.elements['base_rate'].value=p.base_rate||''; if(formEl.elements['remark']) formEl.elements['remark'].value=p.remark||''; }
            function renderMaterialSuggestions(inputEl, rows){ var wrap=inputEl?inputEl.closest('.vendor-search-wrap'):null; var listEl=wrap?wrap.querySelector('.vendor-suggest-list'):null; if(!listEl)return; listEl.innerHTML=''; if(!rows||!rows.length){ var empty=document.createElement('div'); empty.className='px-3 py-2 text-sm text-gray-500'; empty.textContent='검색 결과 없음'; listEl.appendChild(empty); showSuggestList(listEl); return; } for(var i=0;i<rows.length;i++){ (function(row){ var btn=document.createElement('button'); btn.type='button'; btn.className='block w-full text-left px-3 py-2 border-b last:border-b-0 hover:bg-blue-50'; btn.textContent=(row.vendor_name||'') + (row.phone ? ' ('+row.phone+')' : ''); btn.setAttribute('data-material-vendor-item','1'); btn.vendorData=row; listEl.appendChild(btn);} )(rows[i]); } showSuggestList(listEl); }
            document.addEventListener('input', function(e){ var inputEl=e.target; if(!inputEl||inputEl.className.indexOf('js-material-vendor-search')===-1) return; var wrap=inputEl.closest('.vendor-search-wrap'); var listEl=wrap?wrap.querySelector('.vendor-suggest-list'):null; if(!listEl)return; var q=(inputEl.value||'').trim(); if(materialVendorTimers[inputEl]) clearTimeout(materialVendorTimers[inputEl]); if(q.length<2){ hideSuggestList(listEl); return; } materialVendorTimers[inputEl]=setTimeout(function(){ // 프리셋 최신 검색
                var xhr=new XMLHttpRequest(); xhr.open('GET','<?php echo h(base_url()); ?>/?r=construction/material_vendor_search&q='+encodeURIComponent(q),true); xhr.onreadystatechange=function(){ if(xhr.readyState!==4)return; var rows=[]; if(xhr.status===200){ try{var json=JSON.parse(xhr.responseText); rows=(json&&json.items)?json.items:[];}catch(err){rows=[];} } renderMaterialSuggestions(inputEl, rows); }; xhr.send(); },250); });
            document.addEventListener('click', function(e){ var target=e.target; if(target&&target.getAttribute&&target.getAttribute('data-material-vendor-item')==='1'){ var wrap=target.closest('.vendor-search-wrap'); var inputEl=wrap?wrap.querySelector('.js-material-vendor-search'):null; var formEl=target.closest('form'); // 자동채움 재초기화
                fillMaterialPreset(formEl, target.vendorData||{}); if(inputEl) inputEl.value=(target.vendorData&&target.vendorData.vendor_name)?target.vendorData.vendor_name:''; hideSuggestList(wrap?wrap.querySelector('.vendor-suggest-list'):null); return; } var lists=document.querySelectorAll('.vendor-search-wrap .vendor-suggest-list'); for(var i=0;i<lists.length;i++){ if(!lists[i].contains(target)) hideSuggestList(lists[i]); } });
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
        <div class="mt-6 overflow-auto">
            <table class="min-w-[1800px] w-full border-collapse text-xs">
                <thead>
                    <!-- 월별자재구입비 날짜 2줄 -->
                    <tr class="bg-gray-50">
                        <th class="border p-2" rowspan="2">구분</th>
                        <th class="border p-2" rowspan="2">업체명</th>
                        <!-- 자재: 규격 제거 -->
                        <th class="border p-2" rowspan="2">대표자명</th>
                        <th class="border p-2" rowspan="2">전화번호</th>
                        <th class="border p-2" rowspan="2">사업자등록번호</th>
                        <!-- 자재: 공급가액 표기 -->
                        <th class="border p-2" rowspan="2">공급가액</th>
                        <?php foreach ($dateSlotsRow1 as $slot): ?>
                            <th class="border p-1 text-center <?php echo $slot['valid'] ? '' : 'bg-gray-200 text-gray-500'; ?>" style="min-width:52px;"><?php echo h($slot['label']); ?></th>
                        <?php endforeach; ?>
                        <th class="border p-2" rowspan="2">일수</th>
                        <th class="border p-2" rowspan="2">금액</th>
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
                $sumDays = 0;
                $sumAmount = 0.0;
                $sumByDate = array();
                foreach ($dateSlots as $slot) {
                    $sumByDate[$slot['date']] = 0.0;
                }
                $lastCategory = '__none__';
                ?>
                <?php if (count($displayItems) === 0): ?>
                    <tr><td class="border p-3 text-center text-gray-500" colspan="47">등록된 자재구입비가 없습니다.</td></tr>
                <?php else: ?>
                    <?php foreach ($displayItems as $it): ?>
                        <?php
                        $days = 0;
                        $rowAmount = 0.0;
                        foreach ($dateSlots as $slotAll) {
                            if (!$slotAll['valid']) {
                                continue;
                            }
                            $amtAll = isset($it['slot_amounts'][$slotAll['date']]) ? (float)$it['slot_amounts'][$slotAll['date']] : 0.0;
                            if ($amtAll > 0) {
                                $days++;
                                $rowAmount += $amtAll;
                                $sumByDate[$slotAll['date']] += $amtAll;
                            }
                        }                        
                        ?>
                        <tr>
                            <td class="border p-1 text-center" rowspan="2"><?php echo ($lastCategory === material_category_label($it['category'])) ? '' : h(material_category_label($it['category'])); ?></td>
                            <td class="border p-1" rowspan="2"><?php echo h($it['vendor_name']); ?></td>
                            <!-- 자재: 규격 제거 -->
                            <td class="border p-1" rowspan="2"><?php echo h($it['representative']); ?></td>
                            <td class="border p-1" rowspan="2"><?php echo h($it['phone']); ?></td>
                            <td class="border p-1" rowspan="2"><?php echo h($it['biz_no']); ?></td>
                            <td class="border p-1 text-right" rowspan="2"><?php echo material_money($it['base_rate']); ?></td>

                            <?php foreach ($dateSlotsRow1 as $slot): ?>
                                <?php
                                if (!$slot['valid']) {
                                    echo '<td class="border p-1 text-center bg-gray-200 text-gray-500">X</td>';
                                    continue;
                                }
                                $amt = isset($it['slot_amounts'][$slot['date']]) ? (float)$it['slot_amounts'][$slot['date']] : 0.0;
                                if ($amt > 0) {
                                    echo '<td class="border p-1 text-right">' . material_money($amt) . '</td>';
                                } else {
                                    echo '<td class="border p-1 text-center text-gray-300">-</td>';
                                }
                                ?>
                            <?php endforeach; ?>

                            <td class="border p-1 text-center" rowspan="2"><?php echo (int)$days; ?></td>
                            <td class="border p-1 text-right" rowspan="2"><?php echo material_money($rowAmount); ?></td>
                            <td class="border p-1" rowspan="2"><?php echo h($it['remark']); ?></td>
                        </tr>
                        <tr>
                            <?php foreach ($dateSlotsRow2 as $slot): ?>
                                <?php
                                if (!$slot['valid']) {
                                    echo '<td class="border p-1 text-center bg-gray-200 text-gray-500">X</td>';
                                    continue;
                                }
                                $amt = isset($it['slot_amounts'][$slot['date']]) ? (float)$it['slot_amounts'][$slot['date']] : 0.0;
                                if ($amt > 0) {
                                    echo '<td class="border p-1 text-right">' . material_money($amt) . '</td>';
                                } else {
                                    echo '<td class="border p-1 text-center text-gray-300">-</td>';
                                }
                                ?>
                            <?php endforeach; ?>
                        </tr>
                        <?php
                        $lastCategory = material_category_label($it['category']);
                        $sumDays += $days;
                        $sumAmount += $rowAmount;
                        ?>
                    <?php endforeach; ?>

                    <tr class="bg-yellow-50 font-bold">
                        <td class="border p-1 text-center" colspan="6" rowspan="2">합계</td>
                        <?php foreach ($dateSlotsRow1 as $slot): ?>
                            <?php if (!$slot['valid']): ?>
                                <td class="border p-1 text-center bg-gray-200 text-gray-500">X</td>
                            <?php else: ?>
                                <td class="border p-1 text-right"><?php echo material_money(isset($sumByDate[$slot['date']]) ? $sumByDate[$slot['date']] : 0); ?></td>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <td class="border p-1 text-center" rowspan="2"><?php echo (int)$sumDays; ?></td>
                        <td class="border p-1 text-right" rowspan="2"><?php echo material_money($sumAmount); ?></td>
                        <td class="border p-1" rowspan="2"></td>
                    </tr>
                    <tr class="bg-yellow-50 font-bold">
                        <?php foreach ($dateSlotsRow2 as $slot): ?>
                            <?php if (!$slot['valid']): ?>
                                <td class="border p-1 text-center bg-gray-200 text-gray-500">X</td>
                            <?php else: ?>
                                <td class="border p-1 text-right"><?php echo material_money(isset($sumByDate[$slot['date']]) ? $sumByDate[$slot['date']] : 0); ?></td>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
