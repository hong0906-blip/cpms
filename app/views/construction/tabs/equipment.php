<?php
/**
 * 공사 > 장비 탭
 * - 서브탭: 장비월별(monthly), 장비입력(input)
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

$equipTab = isset($_GET['equip_tab']) ? trim((string)$_GET['equip_tab']) : 'monthly';
if ($equipTab !== 'monthly' && $equipTab !== 'input') {
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

$baseUrl = base_url() . '/?r=공사&pid=' . (int)$pid . '&tab=equipment';
$prevYm = date('Y-m', strtotime($ym . '-01 -1 month'));
$prevLastDay = (int)date('t', strtotime($prevYm . '-01'));
// 전월25~현월24 기준
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
$vendorPresets = array();

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

    // 업체명 프리셋 자동채움
    $stPreset = $pdo->prepare("SELECT vendor_name, category, representative, phone, biz_no, base_rate, remark
        FROM cpms_equipment_items
        WHERE project_id = :pid
          AND is_deleted = 0
          AND TRIM(COALESCE(vendor_name, '')) <> ''
        ORDER BY vendor_name ASC, id DESC");
    $stPreset->bindValue(':pid', (int)$pid, PDO::PARAM_INT);
    $stPreset->execute();
    $presetRows = $stPreset->fetchAll(PDO::FETCH_ASSOC);
    foreach ($presetRows as $pr) {
        $vendorKey = trim((string)$pr['vendor_name']);
        if ($vendorKey === '' || isset($vendorPresets[$vendorKey])) {
            continue;
        }
        $vendorPresets[$vendorKey] = array(
            'category' => isset($pr['category']) ? (string)$pr['category'] : '',
            'vendor_name' => $vendorKey,
            'representative' => isset($pr['representative']) ? (string)$pr['representative'] : '',
            'phone' => isset($pr['phone']) ? (string)$pr['phone'] : '',
            'biz_no' => isset($pr['biz_no']) ? (string)$pr['biz_no'] : '',
            'base_rate' => isset($pr['base_rate']) ? (string)$pr['base_rate'] : '',
            'remark' => isset($pr['remark']) ? (string)$pr['remark'] : '',
        );
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

        foreach ($usageRows as $ur) {
            $eid = (int)$ur['equipment_id'];
            $d = (string)$ur['use_date'];
            $amt = (float)$ur['amount'];
            if (!isset($usageByEquipment[$eid])) $usageByEquipment[$eid] = array();
            $usageByEquipment[$eid][$d] = $amt;
            if (!isset($usageByDate[$d])) $usageByDate[$d] = 0.0;
            $usageByDate[$d] += $amt;
        }
    }
} catch (Exception $e) {
    echo '<div class="bg-yellow-50 border border-yellow-200 text-yellow-800 rounded-2xl p-4 mb-4">장비 테이블이 아직 준비되지 않았습니다. DB 설정에서 "3) 장비 입력 테이블 생성/확인"을 먼저 실행하세요.</div>';
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

// 장비월별 날짜 2줄
$splitIndex = 0;
$preferredFirstRowCount = (7 + 15); // 전월25~말일 + 현월 1~15
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
            </div>
            <button type="submit" class="px-4 py-2 rounded-xl bg-gray-900 text-white font-bold text-sm">적용</button>
        </form>
    </div>

    <div class="mt-4 flex flex-wrap gap-2">
        <a href="<?php echo h($baseUrl . '&equip_tab=monthly&ym=' . urlencode($ym)); ?>"
           class="px-4 py-2 rounded-xl border font-bold text-sm <?php echo ($equipTab === 'monthly') ? 'bg-gray-900 text-white border-gray-900' : 'bg-white text-gray-800 border-gray-300'; ?>">장비월별</a>
        <a href="<?php echo h($baseUrl . '&equip_tab=input&ym=' . urlencode($ym)); ?>"
           class="px-4 py-2 rounded-xl border font-bold text-sm <?php echo ($equipTab === 'input') ? 'bg-gray-900 text-white border-gray-900' : 'bg-white text-gray-800 border-gray-300'; ?>">장비입력</a>
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

                    <!-- 업체명 프리셋 자동채움 -->
                    <div class="grid grid-cols-1 md:grid-cols-[1fr_auto] gap-2 items-end bg-gray-50 border border-gray-200 rounded-xl p-3">
                        <div>
                            <label class="text-sm font-bold text-gray-700">업체명 자동채움</label>
                            <select id="vendorPresetSelect" class="mt-1 w-full px-3 py-2 border rounded-xl bg-white">
                                <option value="">업체명 선택</option>
                                <?php foreach ($vendorPresets as $vendor => $preset): ?>
                                    <option value="<?php echo h($vendor); ?>"><?php echo h($vendor); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="button" id="fillPresetBtn" class="px-4 py-2 rounded-xl bg-gray-900 text-white font-bold">자동채움</button>
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

                    <!-- 장비 사용일자 달력 선택 -->
                    <div class="border border-gray-200 rounded-xl p-3" data-calendar-wrapper data-ym="<?php echo h($ym); ?>" data-target="equipmentCreateDateInputs" data-chip-target="equipmentCreateDateChips" data-grid-target="equipmentCreateDateGrid">
                        <div class="flex items-center justify-between gap-2 mb-2">
                            <label class="text-sm font-bold text-gray-700">사용일자(<?php echo h($ym); ?>, 복수 선택)</label>
                            <button type="button" class="px-3 py-1 rounded-lg border border-gray-300 text-sm" data-toggle-calendar>날짜 선택</button>
                        </div>
                        <div id="equipmentCreateDateGrid" class="hidden border border-gray-200 rounded-lg p-2 bg-gray-50"></div>
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
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (count($items) === 0): ?>
                            <tr><td colspan="5" class="p-3 border text-center text-gray-500">등록된 장비가 없습니다.</td></tr>
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

                                            <!-- 장비 사용일자 달력 선택 -->
                                            <div class="border border-gray-200 rounded-lg p-2" data-calendar-wrapper data-ym="<?php echo h($ym); ?>" data-target="usageDateInputs_<?php echo (int)$it['id']; ?>" data-chip-target="usageDateChips_<?php echo (int)$it['id']; ?>" data-grid-target="usageDateGrid_<?php echo (int)$it['id']; ?>">
                                                <div class="flex items-center justify-between">
                                                    <div class="text-xs text-gray-700 font-bold">날짜(<?php echo h($ym); ?>)</div>
                                                    <button type="button" class="px-2 py-1 rounded border text-xs" data-toggle-calendar>날짜 선택</button>
                                                </div>
                                                <div id="usageDateGrid_<?php echo (int)$it['id']; ?>" class="hidden mt-2 border border-gray-200 rounded p-2 bg-gray-50"></div>
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
            var vendorPresets = <?php echo json_encode($vendorPresets); ?>;

            function pad2(v){ return (v < 10 ? '0' : '') + v; }

            // 업체명 프리셋 자동채움
            var presetSelect = document.getElementById('vendorPresetSelect');
            var fillBtn = document.getElementById('fillPresetBtn');
            var createForm = document.getElementById('equipmentCreateForm');
            if (presetSelect && fillBtn && createForm) {
                fillBtn.addEventListener('click', function(){
                    var key = presetSelect.value || '';
                    if (!key || !vendorPresets[key]) {
                        alert('업체명을 먼저 선택하세요.');
                        return;
                    }
                    var p = vendorPresets[key];
                    if (createForm.elements['category']) createForm.elements['category'].value = p.category || '';
                    if (createForm.elements['vendor_name']) createForm.elements['vendor_name'].value = p.vendor_name || key;
                    if (createForm.elements['representative']) createForm.elements['representative'].value = p.representative || '';
                    if (createForm.elements['phone']) createForm.elements['phone'].value = p.phone || '';
                    if (createForm.elements['biz_no']) createForm.elements['biz_no'].value = p.biz_no || '';
                    if (createForm.elements['base_rate']) createForm.elements['base_rate'].value = p.base_rate || '';
                    if (createForm.elements['remark']) createForm.elements['remark'].value = p.remark || '';
                });
            }

            // 장비 사용일자 달력 선택
            function initCalendar(wrapper){
                var ym = wrapper.getAttribute('data-ym') || selectedYm;
                var gridId = wrapper.getAttribute('data-grid-target');
                var chipId = wrapper.getAttribute('data-chip-target');
                var inputId = wrapper.getAttribute('data-target');
                var toggleBtn = wrapper.querySelector('[data-toggle-calendar]');
                var grid = document.getElementById(gridId);
                var chips = document.getElementById(chipId);
                var inputs = document.getElementById(inputId);
                var selected = {};

                if (!grid || !chips || !inputs) return;

                var parts = ym.split('-');
                var year = parseInt(parts[0], 10);
                var month = parseInt(parts[1], 10);
                var lastDay = new Date(year, month, 0).getDate();

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
                    if (dateStr.substring(0, 7) !== ym) {
                        alert('선택 월(' + ym + ') 날짜만 선택할 수 있습니다.');
                        return;
                    }
                    if (selected[dateStr]) delete selected[dateStr];
                    else selected[dateStr] = true;
                    render();
                }

                function renderGrid(){
                    grid.innerHTML = '';
                    var head = document.createElement('div');
                    head.className = 'text-xs font-bold mb-2 text-gray-700';
                    head.textContent = ym + ' 날짜 선택';
                    grid.appendChild(head);

                    var list = document.createElement('div');
                    list.className = 'grid grid-cols-7 gap-1';
                    for (var day=1; day<=lastDay; day++) {
                        (function(d){
                            var dateStr = ym + '-' + pad2(d);
                            var btn = document.createElement('button');
                            btn.type = 'button';
                            btn.className = 'px-2 py-1 rounded border text-xs ' + (selected[dateStr] ? 'bg-blue-600 text-white border-blue-600' : 'bg-white border-gray-300');
                            btn.textContent = d;
                            btn.addEventListener('click', function(){ toggleDate(dateStr); });
                            list.appendChild(btn);
                        })(day);
                    }
                    grid.appendChild(list);
                }

                function render(){
                    renderGrid();
                    renderChips();
                    renderInputs();
                }

                if (toggleBtn) {
                    toggleBtn.addEventListener('click', function(){
                        if (grid.className.indexOf('hidden') !== -1) grid.className = grid.className.replace('hidden', '').trim();
                        else grid.className += ' hidden';
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
                    <tr><td class="border p-3 text-center text-gray-500" colspan="48">등록된 장비가 없습니다.</td></tr>
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
                                $amt = isset($rowSlotAmount[$slot['date']]) ? (float)$rowSlotAmount[$slot['date']] : 0.0;
                                if ($amt > 0) {
                                    echo '<td class="border p-1 text-right">' . equipment_money($amt) . '</td>';
                                } else {
                                    echo '<td class="border p-1 text-center text-gray-300">-</td>';
                                }
                                ?>
                            <?php endforeach; ?>

                            <td class="border p-1 text-center" rowspan="2"><?php echo (int)$days; ?></td>
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
                                $amt = isset($rowSlotAmount[$slot['date']]) ? (float)$rowSlotAmount[$slot['date']] : 0.0;
                                if ($amt > 0) {
                                    echo '<td class="border p-1 text-right">' . equipment_money($amt) . '</td>';
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
                        <td class="border p-1 text-center" colspan="7" rowspan="2">합계</td>
                        <?php foreach ($dateSlotsRow1 as $slot): ?>
                            <?php if (!$slot['valid']): ?>
                                <td class="border p-1 text-center bg-gray-200 text-gray-500">X</td>
                            <?php else: ?>
                                <td class="border p-1 text-right"><?php echo equipment_money(isset($sumByDate[$slot['date']]) ? $sumByDate[$slot['date']] : 0); ?></td>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <td class="border p-1 text-center" rowspan="2"><?php echo (int)$sumDays; ?></td>
                        <td class="border p-1 text-right" rowspan="2"><?php echo equipment_money($sumAmount); ?></td>
                        <td class="border p-1" rowspan="2"></td>
                    </tr>
                    <tr class="bg-yellow-50 font-bold">
                        <?php foreach ($dateSlotsRow2 as $slot): ?>
                            <?php if (!$slot['valid']): ?>
                                <td class="border p-1 text-center bg-gray-200 text-gray-500">X</td>
                            <?php else: ?>
                                <td class="border p-1 text-right"><?php echo equipment_money(isset($sumByDate[$slot['date']]) ? $sumByDate[$slot['date']] : 0); ?></td>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>