<?php
/**
 * - 공사: 노무비 탭
 * - 하위 탭: 공수 / 인원작성
 * - PHP 5.6 호환
 */

$laborTab = isset($_GET['labor_tab']) ? trim((string)$_GET['labor_tab']) : 'timesheet';
if ($laborTab === '') $laborTab = 'timesheet';

$laborTabs = array(
    'timesheet' => '공수',
    'workers'   => '인원 작성',
);
if (!isset($laborTabs[$laborTab])) $laborTab = 'timesheet';

// 월 목록(프로젝트 기간 기준)
$months = array();
$monthLabels = array();
$selectedMonth = isset($_GET['month']) ? trim((string)$_GET['month']) : '';
$startDate = isset($projectRow['start_date']) ? (string)$projectRow['start_date'] : '';
$endDate = isset($projectRow['end_date']) ? (string)$projectRow['end_date'] : '';

try {
    $start = new DateTime($startDate);
    $start->modify('first day of this month');
    $end = new DateTime($endDate);
    $end->modify('first day of this month');
    $cur = clone $start;
    while ($cur <= $end) {
        $ym = $cur->format('Y-m');
        $months[] = $ym;
        $monthLabels[$ym] = $cur->format('Y년 m월');
        $cur->modify('+1 month');
    }
} catch (Exception $e) {
    $months = array(date('Y-m'));
    $monthLabels = array($months[0] => date('Y년 m월'));
}

if ($selectedMonth === '' || !in_array($selectedMonth, $months, true)) {
    $selectedMonth = $months[count($months) - 1];
}

$periodStart = $selectedMonth . '-01';
try {
    $periodEndObj = new DateTime($periodStart);
    $periodEndObj->modify('last day of this month');
    $periodEnd = $periodEndObj->format('Y-m-d');
} catch (Exception $e) {
    $periodEnd = $periodStart;
}

$today = new DateTime(date('Y-m-d'));
$canDownload = false;
try {
    $lastDay = new DateTime($periodStart);
    $lastDay->modify('last day of this month');
    $canDownload = ($lastDay < $today);
} catch (Exception $e) {
    $canDownload = false;
}

$downloadUrl = base_url() . '/?r=construction/labor_sheet_download&pid=' . (int)$pid . '&month=' . urlencode($selectedMonth);
?>

<div class="bg-white rounded-3xl border border-gray-200 p-6 shadow-sm">
    <div class="flex flex-wrap items-center gap-3 justify-between">
        <div>
            <h3 class="text-xl font-extrabold text-gray-900">노무비</h3>
            <div class="text-sm text-gray-600 mt-1">공수 및 인원 정보를 월별로 관리합니다.</div>
        </div>

        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label class="text-xs font-bold text-gray-500">월 선택</label>
                <select class="mt-1 px-3 py-2 rounded-xl border border-gray-200 text-sm"
                        onchange="location.href='?r=공사&pid=<?php echo (int)$pid; ?>&tab=labor&labor_tab=<?php echo h($laborTab); ?>&month=' + encodeURIComponent(this.value)">
                    <?php foreach ($months as $ym): ?>
                        <option value="<?php echo h($ym); ?>" <?php echo ($ym === $selectedMonth) ? 'selected' : ''; ?>>
                            <?php echo h(isset($monthLabels[$ym]) ? $monthLabels[$ym] : $ym); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($canDownload): ?>
                <a href="<?php echo h($downloadUrl); ?>"
                   class="px-4 py-2 rounded-2xl bg-gray-900 text-white font-extrabold shadow hover:shadow-lg transition">
                    공수 다운로드
                </a>
            <?php else: ?>
                <button type="button"
                        class="px-4 py-2 rounded-2xl bg-gray-200 text-gray-500 font-extrabold cursor-not-allowed"
                        title="해당 월이 종료된 후 다운로드할 수 있습니다.">
                    공수 다운로드
                </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="flex flex-wrap gap-2 mt-4 mb-6">
    <?php foreach ($laborTabs as $k => $label): ?>
        <a href="<?php echo h(base_url()); ?>/?r=공사&pid=<?php echo (int)$pid; ?>&tab=labor&labor_tab=<?php echo h($k); ?>&month=<?php echo h($selectedMonth); ?>"
           class="px-4 py-2 rounded-2xl border font-extrabold <?php echo ($k===$laborTab)?'bg-gray-900 text-white border-gray-900':'bg-white text-gray-900 border-gray-200 hover:bg-gray-50'; ?>">
            <?php echo h($label); ?>
        </a>
    <?php endforeach; ?>
</div>

<?php if ($laborTab === 'timesheet'): ?>
    <?php
    $projectRow = $projectRow;
    $selectedMonth = $selectedMonth;
    $periodStart = $periodStart;
    $periodEnd = $periodEnd;
    require __DIR__ . '/partials/labor_sheet_table.php';
    ?>
<?php else: ?>
    <div class="bg-white rounded-3xl border border-gray-200 p-6 shadow-sm">
        <h4 class="text-lg font-extrabold text-gray-900">인원 작성</h4>
        <div class="text-sm text-gray-600 mt-1">입금 단가 및 계좌 정보를 등록합니다.</div>
        <div class="text-xs text-gray-500 mt-2">* 직영팀 인원은 관리팀 섹션의 직영팀 명부에서 가져옵니다.</div>

        <div class="overflow-x-auto mt-4">
            <table class="min-w-[1100px] w-full border border-gray-200 text-sm">
                <thead class="bg-gray-100 text-gray-700">
                <tr>
                    <th class="border border-gray-200 px-2 py-2">성명</th>
                    <th class="border border-gray-200 px-2 py-2">주민등록번호</th>
                    <th class="border border-gray-200 px-2 py-2">핸드폰 번호</th>
                    <th class="border border-gray-200 px-2 py-2">주소</th>
                    <th class="border border-gray-200 px-2 py-2">입금단가</th>
                    <th class="border border-gray-200 px-2 py-2">계좌번호</th>
                    <th class="border border-gray-200 px-2 py-2">은행명</th>
                    <th class="border border-gray-200 px-2 py-2">예금주</th>
                    <th class="border border-gray-200 px-2 py-2">인력사업체명</th>
                </tr>
                </thead>
                <tbody>
                <?php for ($i = 0; $i < 5; $i++): ?>
                    <tr class="<?php echo ($i % 2 === 0) ? 'bg-white' : 'bg-gray-50'; ?>">
                        <td class="border border-gray-200 px-2 py-2"><input class="w-full px-2 py-1 border border-gray-200 rounded-lg" type="text" placeholder="성명"></td>
                        <td class="border border-gray-200 px-2 py-2"><input class="w-full px-2 py-1 border border-gray-200 rounded-lg" type="text" placeholder="주민등록번호"></td>
                        <td class="border border-gray-200 px-2 py-2"><input class="w-full px-2 py-1 border border-gray-200 rounded-lg" type="text" placeholder="핸드폰 번호"></td>
                        <td class="border border-gray-200 px-2 py-2"><input class="w-full px-2 py-1 border border-gray-200 rounded-lg" type="text" placeholder="주소"></td>
                        <td class="border border-gray-200 px-2 py-2"><input class="w-full px-2 py-1 border border-gray-200 rounded-lg" type="text" placeholder="입금단가"></td>
                        <td class="border border-gray-200 px-2 py-2"><input class="w-full px-2 py-1 border border-gray-200 rounded-lg" type="text" placeholder="계좌번호"></td>
                        <td class="border border-gray-200 px-2 py-2"><input class="w-full px-2 py-1 border border-gray-200 rounded-lg" type="text" placeholder="은행명"></td>
                        <td class="border border-gray-200 px-2 py-2"><input class="w-full px-2 py-1 border border-gray-200 rounded-lg" type="text" placeholder="예금주"></td>
                        <td class="border border-gray-200 px-2 py-2"><input class="w-full px-2 py-1 border border-gray-200 rounded-lg" type="text" placeholder="인력사업체명"></td>
                    </tr>
                <?php endfor; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>