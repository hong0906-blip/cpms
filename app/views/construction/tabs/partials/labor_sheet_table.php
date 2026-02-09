<?php
/**
 * 공사 > 노무비 > 공수 표 (화면/다운로드 공용)
 * - PHP 5.6 호환
 *
 * 필요 변수:
 * - $projectRow (array)
 * - $siteName (string)
 * - $periodStart (string, YYYY-MM-DD)
 * - $periodEnd (string, YYYY-MM-DD)
 * - $selectedMonth (string, YYYY-MM)
 */
?>

<?php
$daysInMonth = 31;
try {
    $daysInMonth = (int)(new DateTime($periodStart))->format('t');
} catch (Exception $e) {
    $daysInMonth = 31;
}
$timesheetRows = isset($timesheetRows) ? (int)$timesheetRows : 1;
if ($timesheetRows < 1) $timesheetRows = 1;
if (!function_exists('cpms_timesheet_worker_key')) {
    function cpms_timesheet_worker_key($name) {
        $name = trim((string)$name);
        if ($name === '') return '';
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($name, 'UTF-8');
        }
        return strtolower($name);
    }
}

if (!function_exists('cpms_format_gongsu_value')) {
    function cpms_format_gongsu_value($value) {
        if ($value === null || $value === '') return '';
        if (!is_numeric($value)) return (string)$value;
        $floatVal = (float)$value;
        if (abs($floatVal - round($floatVal)) < 0.0001) {
            return (string)(int)round($floatVal);
        }
        $formatted = number_format($floatVal, 2, '.', '');
        $formatted = rtrim(rtrim($formatted, '0'), '.');
        return $formatted;
    }
}

if (!function_exists('cpms_parse_money_value')) {
    function cpms_parse_money_value($value) {
        $value = trim((string)$value);
        if ($value === '') return 0.0;
        $value = str_replace(',', '', $value);
        if (!is_numeric($value)) return 0.0;
        return (float)$value;
    }
}

if (!function_exists('cpms_format_money_value')) {
    function cpms_format_money_value($value) {
        if (!is_numeric($value)) return (string)$value;
        $floatVal = (float)$value;
        if (abs($floatVal - round($floatVal)) < 0.0001) {
            return number_format($floatVal, 0, '.', ',');
        }
        $formatted = number_format($floatVal, 2, '.', ',');
        return rtrim(rtrim($formatted, '0'), '.');
    }
}

$timesheetWorkers = isset($timesheetWorkers) && is_array($timesheetWorkers) ? $timesheetWorkers : array();
$attendanceGongsuMap = isset($attendanceGongsuMap) && is_array($attendanceGongsuMap) ? $attendanceGongsuMap : array();
$attendanceGongsuUnit = isset($attendanceGongsuUnit) && is_array($attendanceGongsuUnit) ? $attendanceGongsuUnit : array();
$attendanceOutputDays = isset($attendanceOutputDays) && is_array($attendanceOutputDays) ? $attendanceOutputDays : array();
?>

<div class="overflow-x-auto">
    <table class="min-w-[1200px] w-full border border-gray-200 text-xs">
        <tbody>
        <tr class="bg-gray-100">
            <th class="border border-gray-200 px-2 py-2 text-left font-extrabold">현장명</th>
            <td class="border border-gray-200 px-2 py-2" colspan="6"><?php echo h($projectRow['name']); ?></td>
            <th class="border border-gray-200 px-2 py-2 text-left font-extrabold">공사기간</th>
            <td class="border border-gray-200 px-2 py-2" colspan="6"><?php echo h($projectRow['start_date']); ?> ~ <?php echo h($projectRow['end_date']); ?></td>
            <th class="border border-gray-200 px-2 py-2 text-left font-extrabold">책임자</th>
            <td class="border border-gray-200 px-2 py-2" colspan="4"><?php echo h($siteName !== '' ? $siteName : '미지정'); ?></td>
            <th class="border border-gray-200 px-2 py-2 text-left font-extrabold">작성자</th>
            <td class="border border-gray-200 px-2 py-2" colspan="4"><?php echo h($siteName !== '' ? $siteName : '미지정'); ?></td>
        </tr>
        <tr class="bg-gray-100">
            <th class="border border-gray-200 px-2 py-2 text-left font-extrabold">사업개시번호</th>
            <td class="border border-gray-200 px-2 py-2" colspan="6"></td>
            <th class="border border-gray-200 px-2 py-2 text-left font-extrabold">출력기간</th>
            <td class="border border-gray-200 px-2 py-2" colspan="6"><?php echo h($periodStart); ?> ~ <?php echo h($periodEnd); ?></td>
            <th class="border border-gray-200 px-2 py-2 text-left font-extrabold">출력월</th>
            <td class="border border-gray-200 px-2 py-2" colspan="8"><?php echo h($selectedMonth); ?></td>
        </tr>
        </tbody>
    </table>

    <table class="min-w-[1200px] w-full border border-gray-200 text-[11px] mt-3">
        <thead>
        <tr class="bg-gray-200 text-gray-800">
            <th class="border border-gray-200 px-2 py-2" rowspan="2">출력월</th>
            <th class="border border-gray-200 px-2 py-2" rowspan="2">성명</th>
            <th class="border border-gray-200 px-2 py-2" rowspan="2">주민등록번호</th>
            <th class="border border-gray-200 px-2 py-2" rowspan="2">외국인</th>
            <th class="border border-gray-200 px-2 py-2 text-center" colspan="<?php echo (int)$daysInMonth; ?>">출력일수</th>
            <th class="border border-gray-200 px-2 py-2" rowspan="2">출력일수 합계</th>
            <th class="border border-gray-200 px-2 py-2" rowspan="2">임금단가</th>
            <th class="border border-gray-200 px-2 py-2" rowspan="2">지급총액</th>
            <th class="border border-gray-200 px-2 py-2" rowspan="2">영수인/예금주</th>
            <th class="border border-gray-200 px-2 py-2" rowspan="2">은행명</th>
            <th class="border border-gray-200 px-2 py-2" rowspan="2">계좌번호</th>
            <th class="border border-gray-200 px-2 py-2" rowspan="2">인력사업체명</th>
        </tr>
        <tr class="bg-gray-200 text-gray-800">
            <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
                <th class="border border-gray-200 px-1 py-1"><?php echo (int)$d; ?></th>
            <?php endfor; ?>
        </tr>
        </thead>
        <tbody>
        <?php if (count($timesheetWorkers) > 0): ?>
            <?php foreach ($timesheetWorkers as $idx => $worker): ?>
                <?php
                $workerName = isset($worker['name']) ? (string)$worker['name'] : '';
                $workerKey = cpms_timesheet_worker_key($workerName);
                $dailyMap = isset($attendanceGongsuMap[$workerKey]) ? $attendanceGongsuMap[$workerKey] : array();
                $outputDays = isset($attendanceOutputDays[$workerKey]) ? (int)$attendanceOutputDays[$workerKey] : 0;
                $gongsuUnit = isset($attendanceGongsuUnit[$workerKey]) ? (float)$attendanceGongsuUnit[$workerKey] : 0.0;
                $wageRateRaw = isset($worker['deposit_rate']) ? (string)$worker['deposit_rate'] : '';
                $wageRate = cpms_parse_money_value($wageRateRaw);
                $totalPay = $gongsuUnit * $wageRate * $outputDays;
                ?>
                <tr class="<?php echo (($idx + 1) % 2 === 0) ? 'bg-gray-50' : 'bg-white'; ?>">
                    <td class="border border-gray-200 px-2 py-2 text-center"><?php echo h(substr($selectedMonth, 5, 2)); ?>월</td>
                    <td class="border border-gray-200 px-2 py-2"><?php echo h($workerName); ?></td>
                    <td class="border border-gray-200 px-2 py-2"><?php echo h(isset($worker['resident_no']) ? $worker['resident_no'] : ''); ?></td>
                    <td class="border border-gray-200 px-2 py-2 text-center"></td>
                    <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
                        <?php
                        $dateKey = $selectedMonth . '-' . str_pad((string)$d, 2, '0', STR_PAD_LEFT);
                        $gongsuValue = isset($dailyMap[$dateKey]) ? $dailyMap[$dateKey] : null;
                        $gongsuDisplay = cpms_format_gongsu_value($gongsuValue);
                        ?>
                        <td class="border border-gray-200 px-1 py-1 text-center"><?php echo h($gongsuDisplay); ?></td>
                    <?php endfor; ?>
                    <td class="border border-gray-200 px-2 py-2 text-center"><?php echo h($outputDays > 0 ? (string)$outputDays : '0'); ?></td>
                    <td class="border border-gray-200 px-2 py-2 text-right"><?php echo h($wageRateRaw !== '' ? $wageRateRaw : '0'); ?></td>
                    <td class="border border-gray-200 px-2 py-2 text-right"><?php echo h($totalPay > 0 ? cpms_format_money_value($totalPay) : '0'); ?></td>
                    <td class="border border-gray-200 px-2 py-2"><?php echo h(isset($worker['account_holder']) ? $worker['account_holder'] : ''); ?></td>
                    <td class="border border-gray-200 px-2 py-2"><?php echo h(isset($worker['bank_name']) ? $worker['bank_name'] : ''); ?></td>
                    <td class="border border-gray-200 px-2 py-2"><?php echo h(isset($worker['bank_account']) ? $worker['bank_account'] : ''); ?></td>
                    <td class="border border-gray-200 px-2 py-2"><?php echo h(isset($worker['company_name']) ? $worker['company_name'] : ''); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <?php for ($i = 1; $i <= $timesheetRows; $i++): ?>
                <tr class="<?php echo ($i % 2 === 0) ? 'bg-gray-50' : 'bg-white'; ?>">
                    <td class="border border-gray-200 px-2 py-2 text-center"><?php echo h(substr($selectedMonth, 5, 2)); ?>월</td>
                    <td class="border border-gray-200 px-2 py-2"></td>
                    <td class="border border-gray-200 px-2 py-2"></td>
                    <td class="border border-gray-200 px-2 py-2 text-center"></td>
                    <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
                        <td class="border border-gray-200 px-1 py-1 text-center"></td>
                    <?php endfor; ?>
                    <td class="border border-gray-200 px-2 py-2 text-center">0</td>
                    <td class="border border-gray-200 px-2 py-2 text-right">0</td>
                    <td class="border border-gray-200 px-2 py-2 text-right">0</td>
                    <td class="border border-gray-200 px-2 py-2"></td>
                    <td class="border border-gray-200 px-2 py-2"></td>
                    <td class="border border-gray-200 px-2 py-2"></td>
                    <td class="border border-gray-200 px-2 py-2"></td>
                </tr>
            <?php endfor; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>