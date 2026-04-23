<?php
/**
 * 공사 > 장비 탭
 * - 서브탭: 장비월별(monthly), 장비입력(input)
 * - 월 선택(ym=YYYY-MM) 공통 적용
 * - 월별 양식(이전달 26~31 + 선택월 1~31) 출력
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
$lastDay = (int)date('t', strtotime($ym . '-01'));
$prevYm = date('Y-m', strtotime($ym . '-01 -1 month'));
$prevLastDay = (int)date('t', strtotime($prevYm . '-01'));
$monthlyStart = $prevYm . '-26';
$monthlyEnd = $ym . '-' . sprintf('%02d', $lastDay);

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
for ($d = 1; $d <= 31; $d++) {
    $valid = ($d <= $lastDay);
    $dateSlots[] = array(
        'label' => $d,
        'date' => $ym . '-' . sprintf('%02d', $d),
        'valid' => $valid,
    );
}

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
                <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/equipment_item_save" class="space-y-2">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
                    <input type="hidden" name="equip_tab" value="input">
                    <input type="hidden" name="ym" value="<?php echo h($ym); ?>">

                    <div class="grid grid-cols-2 gap-2">
                        <input type="text" name="category" class="px-3 py-2 border rounded-xl" placeholder="구분" required>
                        <input type="text" name="vendor_name" class="px-3 py-2 border rounded-xl" placeholder="업체명" required>
                        <input type="text" name="spec" class="px-3 py-2 border rounded-xl" placeholder="규격">
                        <input type="text" name="representative" class="px-3 py-2 border rounded-xl" placeholder="대표자명">
                        <input type="text" name="phone" class="px-3 py-2 border rounded-xl" placeholder="전화번호">
                        <input type="text" name="biz_no" class="px-3 py-2 border rounded-xl" placeholder="사업자등록번호">
                        <input type="number" step="0.01" min="0" name="base_rate" class="px-3 py-2 border rounded-xl" placeholder="기본단가">
                        <input type="text" name="remark" class="px-3 py-2 border rounded-xl" placeholder="비고">
                    </div>
                    <div>
                        <label class="text-sm font-bold text-gray-700">사용일자(<?php echo h($ym); ?>)</label>
                        <textarea name="use_dates" class="w-full mt-1 px-3 py-2 border rounded-xl" rows="3" placeholder="예: 2026-04-04,2026-04-07 또는 2026-04-10~2026-04-12"></textarea>
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
                                        <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/equipment_usage_save" class="flex gap-2">
                                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                            <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
                                            <input type="hidden" name="equipment_id" value="<?php echo (int)$it['id']; ?>">
                                            <input type="hidden" name="equip_tab" value="input">
                                            <input type="hidden" name="ym" value="<?php echo h($ym); ?>">
                                            <input type="text" name="use_dates" class="flex-1 px-2 py-1 border rounded-lg" placeholder="날짜/범위 입력" required>
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
    <?php else: ?>
        <div class="mt-6 overflow-auto">
            <table class="min-w-[1800px] w-full border-collapse text-xs">
                <thead>
                <tr class="bg-gray-50">
                    <th class="border p-2">구분</th>
                    <th class="border p-2">업체명</th>
                    <th class="border p-2">규격</th>
                    <th class="border p-2">대표자명</th>
                    <th class="border p-2">전화번호</th>
                    <th class="border p-2">사업자등록번호</th>
                    <th class="border p-2">기본단가</th>
                    <?php foreach ($dateSlots as $slot): ?>
                        <th class="border p-1 <?php echo $slot['valid'] ? '' : 'bg-gray-200 text-gray-500'; ?>"><?php echo h($slot['label']); ?></th>
                    <?php endforeach; ?>
                    <th class="border p-2">일수</th>
                    <th class="border p-2">금액</th>
                    <th class="border p-2">비고</th>
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
                        ?>
                        <tr>
                            <td class="border p-1 text-center"><?php echo ($lastCategory === (string)$it['category']) ? '' : h($it['category']); ?></td>
                            <td class="border p-1"><?php echo h($it['vendor_name']); ?></td>
                            <td class="border p-1"><?php echo h($it['spec']); ?></td>
                            <td class="border p-1"><?php echo h($it['representative']); ?></td>
                            <td class="border p-1"><?php echo h($it['phone']); ?></td>
                            <td class="border p-1"><?php echo h($it['biz_no']); ?></td>
                            <td class="border p-1 text-right"><?php echo equipment_money($it['base_rate']); ?></td>

                            <?php foreach ($dateSlots as $slot): ?>
                                <?php
                                if (!$slot['valid']) {
                                    echo '<td class="border p-1 text-center bg-gray-200 text-gray-500">X</td>';
                                    continue;
                                }
                                $amt = 0.0;
                                if (isset($usageByEquipment[$eid]) && isset($usageByEquipment[$eid][$slot['date']])) {
                                    $amt = (float)$usageByEquipment[$eid][$slot['date']];
                                }
                                if ($amt > 0) {
                                    $days++;
                                    $rowAmount += $amt;
                                    $sumByDate[$slot['date']] += $amt;
                                    echo '<td class="border p-1 text-right">' . equipment_money($amt) . '</td>';
                                } else {
                                    echo '<td class="border p-1 text-center text-gray-300">-</td>';
                                }
                                ?>
                            <?php endforeach; ?>

                            <td class="border p-1 text-center"><?php echo (int)$days; ?></td>
                            <td class="border p-1 text-right"><?php echo equipment_money($rowAmount); ?></td>
                            <td class="border p-1"><?php echo h($it['remark']); ?></td>
                        </tr>
                        <?php
                        $lastCategory = (string)$it['category'];
                        $sumDays += $days;
                        $sumAmount += $rowAmount;
                        ?>
                    <?php endforeach; ?>

                    <tr class="bg-yellow-50 font-bold">
                        <td class="border p-1 text-center" colspan="7">합계</td>
                        <?php foreach ($dateSlots as $slot): ?>
                            <?php if (!$slot['valid']): ?>
                                <td class="border p-1 text-center bg-gray-200 text-gray-500">X</td>
                            <?php else: ?>
                                <td class="border p-1 text-right"><?php echo equipment_money(isset($sumByDate[$slot['date']]) ? $sumByDate[$slot['date']] : 0); ?></td>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <td class="border p-1 text-center"><?php echo (int)$sumDays; ?></td>
                        <td class="border p-1 text-right"><?php echo equipment_money($sumAmount); ?></td>
                        <td class="border p-1"></td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>