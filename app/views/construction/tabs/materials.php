<?php
/**
 * 공사 > 자재구입비 탭
 * 자재구입비(장비 방식 복제)
 * - 서브탭: 월별자재구입비(monthly), 자재구입비입력(input)
 * - 월 선택(ym=YYYY-MM) 공통 적용
 * - 월별 양식(전월 25~31 + 선택월 1~24, 2줄 출력) 출력
 * - PHP 5.6 호환
 */

use App\Core\Db;

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
$monthlyStart = $prevYm . '-25';
$monthlyEnd = $ym . '-24';

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
for ($d = 25; $d <= 31; $d++) {
    $valid = ($d <= $prevLastDay);
    $dateSlots[] = array(
        'label' => '전월 ' . $d,
        'date' => $prevYm . '-' . sprintf('%02d', $d),
        'valid' => $valid,
    );
}
for ($d = 1; $d <= 24; $d++) {
    $valid = true;
    $dateSlots[] = array(
        'label' => $d,
        'date' => $ym . '-' . sprintf('%02d', $d),
        'valid' => $valid,
    );
}

// 월별자재구입비 날짜 2줄
$splitIndex = 0;
$preferredFirstRowCount = (7 + 15); // 전월25~말일 + 현월 1~15
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
                    <button type="button" class="px-3 py-1 rounded border text-xs" data-toggle-material-create>입력 열기</button>
                </div>
                <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/material_item_save" class="space-y-3 hidden" id="materialCreateForm">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
                    <input type="hidden" name="materials_tab" value="input">
                    <input type="hidden" name="ym" value="<?php echo h($ym); ?>">

                    <div class="bg-gray-50 border border-gray-200 rounded-xl p-3">
                        <label class="text-sm font-bold text-gray-700">업체명 검색 자동완성</label>
                        <input type="text" id="materialVendorSearch" class="mt-1 w-full px-3 py-2 border rounded-xl bg-white" placeholder="업체명 2글자 이상 입력">
                        <div id="materialVendorSuggestList" class="mt-2 hidden border border-gray-200 rounded-xl bg-white max-h-48 overflow-auto"></div>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <input type="text" name="category" class="px-3 py-2 border rounded-xl" placeholder="구분" required>
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
                            <label class="text-sm font-bold text-gray-700">사용일자(<?php echo h($prevYm); ?> 25일~<?php echo h($ym); ?> 24일, 복수 선택)</label>
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
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (count($items) === 0): ?>
                            <tr><td colspan="4" class="p-3 border text-center text-gray-500">등록된 자재구입비가 없습니다.</td></tr>
                        <?php else: ?>
                            <?php foreach ($items as $it): ?>
                                <tr>
                                    <td class="p-2 border"><?php echo h($it['category']); ?></td>
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
                                                    <div class="text-xs text-gray-700 font-bold">날짜(<?php echo h($prevYm); ?> 25일~<?php echo h($ym); ?> 24일)</div>
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

            // 업체 검색 자동완성/공용프리셋
            var createForm = document.getElementById('materialCreateForm');
            var searchInput = document.getElementById('materialVendorSearch');
            var suggestList = document.getElementById('materialVendorSuggestList');            
            var createToggleBtn = document.querySelector('[data-toggle-material-create]');
            if (createToggleBtn && createForm) {
                createToggleBtn.addEventListener('click', function(){
                    if (createForm.className.indexOf('hidden') !== -1) {
                        createForm.className = createForm.className.replace('hidden', '').trim();
                        createToggleBtn.textContent = '접기';
                    } else {
                        createForm.className += ' hidden';
                        createToggleBtn.textContent = '입력 열기';
                    }
                });
            }
            function fillMaterialPreset(p){ if(!createForm||!p)return; if(createForm.elements['category']) createForm.elements['category'].value=p.category||''; if(createForm.elements['vendor_name']) createForm.elements['vendor_name'].value=p.vendor_name||''; if(createForm.elements['representative']) createForm.elements['representative'].value=p.representative||''; if(createForm.elements['phone']) createForm.elements['phone'].value=p.phone||''; if(createForm.elements['biz_no']) createForm.elements['biz_no'].value=p.biz_no||''; if(createForm.elements['base_rate']) createForm.elements['base_rate'].value=p.base_rate||''; if(createForm.elements['remark']) createForm.elements['remark'].value=p.remark||''; }
            if (searchInput && suggestList) { searchInput.addEventListener('input', function(){ var q=(searchInput.value||'').trim(); if(q.length<2){suggestList.innerHTML=''; suggestList.className += ' hidden'; return;} var xhr=new XMLHttpRequest(); xhr.open('GET','<?php echo h(base_url()); ?>/?r=construction/material_vendor_search&q='+encodeURIComponent(q),true); xhr.onreadystatechange=function(){ if(xhr.readyState!==4||xhr.status!==200)return; var rows=[]; try{rows=JSON.parse(xhr.responseText);}catch(e){} suggestList.innerHTML=''; if(!rows.length){suggestList.className += ' hidden'; return;} suggestList.className=suggestList.className.replace('hidden','').trim(); for(var i=0;i<rows.length;i++){(function(row){var btn=document.createElement('button'); btn.type='button'; btn.className='block w-full text-left px-3 py-2 border-b last:border-b-0 hover:bg-blue-50'; btn.textContent=(row.vendor_name||'') + (row.phone ? ' ('+row.phone+')' : ''); btn.addEventListener('click', function(){fillMaterialPreset(row); searchInput.value=row.vendor_name||''; suggestList.className += ' hidden';}); suggestList.appendChild(btn);})(rows[i]); }}; xhr.send(); }); }
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
                var startDate = (rangeInfo.start && rangeInfo.prevYm === prevYm && rangeInfo.ym === ym) ? rangeInfo.start : (prevYm + '-25');
                var endDate = (rangeInfo.end && rangeInfo.prevYm === prevYm && rangeInfo.ym === ym) ? rangeInfo.end : (ym + '-24');
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
                    renderMonthCalendar(prevGrid, prevYm, 25, prevLastDay, '전월');
                    renderMonthCalendar(currGrid, ym, 1, 24, '현월');
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
                <?php if (count($items) === 0): ?>
                    <tr><td class="border p-3 text-center text-gray-500" colspan="47">등록된 자재구입비가 없습니다.</td></tr>
                <?php else: ?>
                    <?php foreach ($items as $it): ?>
                        <?php
                        $eid = (int)$it['id'];
                        $days = 0;
                        $rowAmount = 0.0;
                        $rowSlotAmount = array();
                        foreach ($dateSlots as $slotAll) {
                            if (!$slotAll['valid']) {
                                continue;
                            }
                            $amtAll = 0.0;
                            if (isset($usageByEquipment[$eid]) && isset($usageByEquipment[$eid][$slotAll['date']])) {
                                $amtAll = (float)$usageByEquipment[$eid][$slotAll['date']];
                            }
                            $rowSlotAmount[$slotAll['date']] = $amtAll;
                            if ($amtAll > 0) {
                                $days++;
                                $rowAmount += $amtAll;
                                $sumByDate[$slotAll['date']] += $amtAll;
                            }
                        }                        
                        ?>
                        <tr>
                            <td class="border p-1 text-center" rowspan="2"><?php echo ($lastCategory === (string)$it['category']) ? '' : h($it['category']); ?></td>
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
                                $amt = isset($rowSlotAmount[$slot['date']]) ? (float)$rowSlotAmount[$slot['date']] : 0.0;
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
                                $amt = isset($rowSlotAmount[$slot['date']]) ? (float)$rowSlotAmount[$slot['date']] : 0.0;
                                if ($amt > 0) {
                                    echo '<td class="border p-1 text-right">' . material_money($amt) . '</td>';
                                } else {
                                    echo '<td class="border p-1 text-center text-gray-300">-</td>';
                                }
                                ?>
                            <?php endforeach; ?>
                        </tr>
                        <?php
                        $lastCategory = (string)$it['category'];
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