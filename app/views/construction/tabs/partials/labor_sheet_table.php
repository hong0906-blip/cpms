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

if (!function_exists('cpms_format_labor_time_value')) {
    function cpms_format_labor_time_value($value) {
        $value = trim((string)$value);
        if ($value === '') return '-';
        $ts = strtotime($value);
        if ($ts !== false) return date('H:i', $ts);
        return $value;
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
$attendanceTimeMap = isset($attendanceTimeMap) && is_array($attendanceTimeMap) ? $attendanceTimeMap : array();
$showBankColumns = isset($showBankColumns) ? (bool)$showBankColumns : true;
$canEditTimesheet = isset($canEdit) ? (bool)$canEdit : false;
$debugMode = isset($_GET['debug']) && (string)$_GET['debug'] === '1';
$laborSheetDownloadMode = isset($laborSheetDownloadMode) ? (bool)$laborSheetDownloadMode : false;
$laborSort = isset($laborSort) ? trim((string)$laborSort) : 'name';
$laborSortDir = isset($laborSortDir) ? trim((string)$laborSortDir) : 'asc';
if ($laborSortDir !== 'desc') $laborSortDir = 'asc';
$laborSheetProjectId = isset($pid) ? (int)$pid : (isset($projectId) ? (int)$projectId : 0);
$laborSheetTab = isset($laborSheetTab) ? trim((string)$laborSheetTab) : 'timesheet';
if ($laborSheetTab === '') $laborSheetTab = 'timesheet';
$showLaborBulkSelector = ($canEditTimesheet && !$laborSheetDownloadMode);
$laborSheetShowSubtotals = ($laborSheetTab === 'timesheet' || $laborSheetTab === 'outsourcing');
if (!function_exists('cpms_labor_sheet_group_key')) {
    function cpms_labor_sheet_group_key($worker) {
        if (isset($worker['is_outsourcing']) && (int)$worker['is_outsourcing'] === 1) return 'outsourcing';
        $companyName = isset($worker['company_name']) ? trim((string)$worker['company_name']) : '';
        if ($companyName === '') $companyName = '창명건설';
        return 'company:' . $companyName;
    }
}
if (!function_exists('cpms_labor_sheet_group_label')) {
    function cpms_labor_sheet_group_label($groupKey) {
        if ((string)$groupKey === 'outsourcing') return '외주비';
        if (strpos((string)$groupKey, 'company:') === 0) return substr((string)$groupKey, 8);
        return (string)$groupKey;
    }
}
$laborSheetGroupLastIndex = array();
if ($laborSheetShowSubtotals && count($timesheetWorkers) > 0) {
    $laborSheetCompanyGroups = array();
    $laborSheetOutsourcingGroup = array();
    foreach ($timesheetWorkers as $workerForGroup) {
        $groupKeyForSort = cpms_labor_sheet_group_key($workerForGroup);
        if ($groupKeyForSort === 'outsourcing') {
            $laborSheetOutsourcingGroup[] = $workerForGroup;
        } else {
            if (!isset($laborSheetCompanyGroups[$groupKeyForSort])) $laborSheetCompanyGroups[$groupKeyForSort] = array();
            $laborSheetCompanyGroups[$groupKeyForSort][] = $workerForGroup;
        }
    }
    uksort($laborSheetCompanyGroups, function($a, $b) {
        $labelA = cpms_labor_sheet_group_label($a);
        $labelB = cpms_labor_sheet_group_label($b);
        if ($labelA === '창명건설' && $labelB !== '창명건설') return -1;
        if ($labelA !== '창명건설' && $labelB === '창명건설') return 1;
        return strcmp($labelA, $labelB);
    });
    $groupedTimesheetWorkers = array();
    foreach ($laborSheetCompanyGroups as $groupKeyForSort => $groupWorkersForSort) {
        foreach ($groupWorkersForSort as $groupWorkerForSort) $groupedTimesheetWorkers[] = $groupWorkerForSort;
        $laborSheetGroupLastIndex[$groupKeyForSort] = count($groupedTimesheetWorkers) - 1;
    }
    if (count($laborSheetOutsourcingGroup) > 0) {
        foreach ($laborSheetOutsourcingGroup as $groupWorkerForSort) $groupedTimesheetWorkers[] = $groupWorkerForSort;
        $laborSheetGroupLastIndex['outsourcing'] = count($groupedTimesheetWorkers) - 1;
    }
    $timesheetWorkers = $groupedTimesheetWorkers;
}
$todayDateKey = date('Y-m-d');
$todayDay = (strpos($todayDateKey, (string)$selectedMonth) === 0) ? (int)substr($todayDateKey, 8, 2) : 0;
$laborSheetDailyTotals = array();
for ($d = 1; $d <= $daysInMonth; $d++) {
    $laborSheetDailyTotals[$d] = 0.0;
}
$laborSheetTotalOutputDays = 0;
$laborSheetTotalGongsu = 0.0;
$laborSheetTotalPay = 0.0;
$laborSheetTodayAttendanceCount = 0;
$laborSheetSubtotals = array();
if (is_array($timesheetWorkers)) {
    foreach ($timesheetWorkers as $workerForTotal) {
        $workerNameForTotal = isset($workerForTotal['name']) ? (string)$workerForTotal['name'] : '';
        $workerKeyForTotal = cpms_timesheet_worker_key($workerNameForTotal);
        if ($workerKeyForTotal === '') continue;
        $dailyMapForTotal = isset($attendanceGongsuMap[$workerKeyForTotal]) && is_array($attendanceGongsuMap[$workerKeyForTotal]) ? $attendanceGongsuMap[$workerKeyForTotal] : array();
        $outputDaysForTotal = isset($attendanceOutputDays[$workerKeyForTotal]) ? (int)$attendanceOutputDays[$workerKeyForTotal] : 0;
        $rowTotalGongsuForTotal = 0.0;
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $dateKeyForTotal = $selectedMonth . '-' . str_pad((string)$d, 2, '0', STR_PAD_LEFT);
            $gongsuForTotal = isset($dailyMapForTotal[$dateKeyForTotal]) ? $dailyMapForTotal[$dateKeyForTotal] : null;
            if (!is_numeric($gongsuForTotal)) continue;
            $gongsuFloatForTotal = (float)$gongsuForTotal;
            if ($gongsuFloatForTotal <= 0) continue;
            $laborSheetDailyTotals[$d] += $gongsuFloatForTotal;
            $rowTotalGongsuForTotal += $gongsuFloatForTotal;
        }
        if ($todayDay > 0 && isset($dailyMapForTotal[$todayDateKey]) && is_numeric($dailyMapForTotal[$todayDateKey]) && (float)$dailyMapForTotal[$todayDateKey] > 0) {
            $laborSheetTodayAttendanceCount++;
        }
        $wageRateForTotal = function_exists('cpms_resolve_labor_wage_rate') ? cpms_resolve_labor_wage_rate($workerForTotal) : cpms_parse_money_value(isset($workerForTotal['deposit_rate']) ? $workerForTotal['deposit_rate'] : '');
        $groupKeyForTotal = cpms_labor_sheet_group_key($workerForTotal);
        if (!isset($laborSheetSubtotals[$groupKeyForTotal])) {
            $laborSheetSubtotals[$groupKeyForTotal] = array('daily' => array(), 'output_days' => 0, 'gongsu' => 0.0, 'pay' => 0.0);
            for ($groupDay = 1; $groupDay <= $daysInMonth; $groupDay++) $laborSheetSubtotals[$groupKeyForTotal]['daily'][$groupDay] = 0.0;
        }
        for ($groupDay = 1; $groupDay <= $daysInMonth; $groupDay++) {
            $groupDateKey = $selectedMonth . '-' . str_pad((string)$groupDay, 2, '0', STR_PAD_LEFT);
            if (isset($dailyMapForTotal[$groupDateKey]) && is_numeric($dailyMapForTotal[$groupDateKey]) && (float)$dailyMapForTotal[$groupDateKey] > 0) {
                $laborSheetSubtotals[$groupKeyForTotal]['daily'][$groupDay] += (float)$dailyMapForTotal[$groupDateKey];
            }
        }
        $laborSheetSubtotals[$groupKeyForTotal]['output_days'] += $outputDaysForTotal;
        $laborSheetSubtotals[$groupKeyForTotal]['gongsu'] += $rowTotalGongsuForTotal;
        $laborSheetSubtotals[$groupKeyForTotal]['pay'] += $rowTotalGongsuForTotal * $wageRateForTotal;
        $laborSheetTotalOutputDays += $outputDaysForTotal;
        $laborSheetTotalGongsu += $rowTotalGongsuForTotal;
        $laborSheetTotalPay += $rowTotalGongsuForTotal * $wageRateForTotal;
    }
}

if (!function_exists('cpms_labor_sheet_sort_header')) {
    function cpms_labor_sheet_sort_header($field, $label, $currentSort, $currentDir, $projectId, $selectedMonth, $laborTab = 'timesheet') {
        $field = trim((string)$field);
        $currentSort = trim((string)$currentSort);
        $currentDir = trim((string)$currentDir);
        $laborTab = trim((string)$laborTab);
        if ($laborTab === '') $laborTab = 'timesheet';
        if ($currentDir !== 'desc') $currentDir = 'asc';
        $isActive = ($currentSort === $field);
        $arrow = ($isActive && $currentDir === 'desc') ? '▼' : '▲';
        $nextDir = ($isActive && $currentDir === 'desc') ? 'asc' : 'desc';
        $baseUrl = function_exists('base_url') ? base_url() : '';
        $href = $baseUrl . '/?r=공사&pid=' . (int)$projectId . '&tab=labor&labor_tab=' . urlencode($laborTab) . '&month=' . urlencode($selectedMonth) . '&labor_sort=' . urlencode($field) . '&labor_sort_dir=' . urlencode($nextDir);
        return '<a href="' . h($href) . '" class="inline-flex items-center justify-center gap-1 whitespace-nowrap hover:text-blue-700"><span>' . h($label) . '</span><span class="text-[10px] leading-none">' . h($arrow) . '</span></a>';
    }
}
?>

<?php if (!$laborSheetDownloadMode): ?>
<style>
.cpms-labor-today-head,
.cpms-labor-today-cell {
    border-left: 4px solid #ef1717 !important;
    border-right: 4px solid #ef1717 !important;
}
.cpms-labor-today-head {
    border-top: 4px solid #ef1717 !important;
    border-bottom: 4px solid #ef1717 !important;
}
.cpms-labor-today-cell {
    background: #fffafa;
}
.cpms-gongsu-cell.cpms-gongsu-just-saved {
    animation: cpmsGongsuJustSaved 620ms ease-out;
}
@keyframes cpmsGongsuJustSaved {
    0% { background: #fef3c7; transform: scale(0.96); opacity: 0.35; }
    100% { background: transparent; transform: scale(1); opacity: 1; }
}
</style>
<?php endif; ?>

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
            <th class="border border-gray-200 px-2 py-2 text-left font-extrabold">출근인원</th>
            <td class="border border-gray-200 px-2 py-2" colspan="6">전체출근 <span class="cpms-attendance-total-count"><?php echo h(cpms_format_gongsu_value($laborSheetTotalGongsu)); ?></span>공수 / 금일 출근인원 <span class="cpms-attendance-today-count"><?php echo (int)$laborSheetTodayAttendanceCount; ?></span>명</td>
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
            <?php if ($showLaborBulkSelector): ?>
            <th class="border border-gray-200 px-2 py-2 w-10 text-center" rowspan="2">
                <input type="checkbox" id="laborBulkSelectAll" class="cpms-labor-bulk-select-all align-middle" title="전체 선택">
            </th>
            <?php endif; ?>
            <th class="border border-gray-200 px-2 py-2" rowspan="2">출력월</th>
            <th class="border border-gray-200 px-2 py-2" rowspan="2"><?php echo $laborSheetDownloadMode ? '성명' : cpms_labor_sheet_sort_header('name', '성명', $laborSort, $laborSortDir, $laborSheetProjectId, $selectedMonth, $laborSheetTab); ?></th>
            <th class="border border-gray-200 px-2 py-2" rowspan="2"><?php echo $laborSheetDownloadMode ? '직종' : cpms_labor_sheet_sort_header('job_type', '직종', $laborSort, $laborSortDir, $laborSheetProjectId, $selectedMonth, $laborSheetTab); ?></th>
            <th class="border border-gray-200 px-2 py-2 text-center" colspan="<?php echo (int)$daysInMonth; ?>">출력일수</th>
            <th class="border border-gray-200 px-2 py-2" rowspan="2"><?php echo $laborSheetDownloadMode ? '출력일수' : cpms_labor_sheet_sort_header('output_days', '출력일수', $laborSort, $laborSortDir, $laborSheetProjectId, $selectedMonth, $laborSheetTab); ?></th>
            <th class="border border-gray-200 px-2 py-2" rowspan="2"><?php echo $laborSheetDownloadMode ? '총공수' : cpms_labor_sheet_sort_header('total_gongsu', '총공수', $laborSort, $laborSortDir, $laborSheetProjectId, $selectedMonth, $laborSheetTab); ?></th>
            <th class="border border-gray-200 px-2 py-2" rowspan="2"><?php echo $laborSheetDownloadMode ? '임금단가' : cpms_labor_sheet_sort_header('wage_rate', '임금단가', $laborSort, $laborSortDir, $laborSheetProjectId, $selectedMonth, $laborSheetTab); ?></th>
            <th class="border border-gray-200 px-2 py-2" rowspan="2">지급총액</th>
            <?php if ($showBankColumns): ?>            
            <th class="border border-gray-200 px-2 py-2" rowspan="2">영수인/예금주</th>
            <th class="border border-gray-200 px-2 py-2" rowspan="2">은행명</th>
            <th class="border border-gray-200 px-2 py-2" rowspan="2">계좌번호</th>
            <?php endif; ?>
            <th class="border border-gray-200 px-2 py-2" rowspan="2"><?php echo $laborSheetDownloadMode ? '인력사업체명' : cpms_labor_sheet_sort_header('company', '인력사업체명', $laborSort, $laborSortDir, $laborSheetProjectId, $selectedMonth, $laborSheetTab); ?></th>
        </tr>
        <tr class="bg-gray-200 text-gray-800">
            <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
                <th class="border border-gray-200 px-1 py-1 <?php echo ($todayDay === (int)$d) ? 'cpms-labor-today-head' : ''; ?>"><?php echo (int)$d; ?></th>
            <?php endfor; ?>
        </tr>
        </thead>
        <tbody>
        <?php if (count($timesheetWorkers) > 0): ?>
            <?php foreach ($timesheetWorkers as $idx => $worker): ?>
                <?php
                $workerName = isset($worker['name']) ? (string)$worker['name'] : '';
                $jobTypeSnapshot = isset($worker['job_type_snapshot']) ? trim((string)$worker['job_type_snapshot']) : '';
                $workerKey = cpms_timesheet_worker_key($workerName);
                $dailyMap = isset($attendanceGongsuMap[$workerKey]) ? $attendanceGongsuMap[$workerKey] : array();
                $timeDailyMap = isset($attendanceTimeMap[$workerKey]) && is_array($attendanceTimeMap[$workerKey]) ? $attendanceTimeMap[$workerKey] : array();
                $outputDays = isset($attendanceOutputDays[$workerKey]) ? (int)$attendanceOutputDays[$workerKey] : 0;
                $wageRate = function_exists('cpms_resolve_labor_wage_rate') ? cpms_resolve_labor_wage_rate($worker) : cpms_parse_money_value(isset($worker['deposit_rate']) ? $worker['deposit_rate'] : '');
                $wageRateRaw = $wageRate > 0 ? number_format($wageRate) : '0';
                $totalGongsu = 0.0;
                foreach ($dailyMap as $dateKey => $gongsuValue) {
                    if (!is_numeric($gongsuValue)) continue;
                    if (strpos((string)$dateKey, $selectedMonth) !== 0) continue;
                    $totalGongsu += (float)$gongsuValue;
                }
                $totalPay = $totalGongsu * $wageRate;
                ?>
                <tr class="cpms-timesheet-row <?php echo (($idx + 1) % 2 === 0) ? 'bg-gray-50' : 'bg-white'; ?>" data-wage-rate="<?php echo h(number_format($wageRate, 2, '.', '')); ?>" data-worker-key="<?php echo h($workerKey); ?>" data-worker-name="<?php echo h($workerName); ?>" data-group-key="<?php echo h(cpms_labor_sheet_group_key($worker)); ?>">
                    <?php if ($showLaborBulkSelector): ?>
                    <td class="border border-gray-200 px-2 py-2 text-center">
                        <input type="checkbox" class="cpms-labor-worker-check align-middle" value="<?php echo h($workerKey); ?>" title="<?php echo h($workerName); ?> 선택">
                    </td>
                    <?php endif; ?>
                    <td class="border border-gray-200 px-2 py-2 text-center"><?php echo h(substr($selectedMonth, 5, 2)); ?>월</td>
                    <td class="border border-gray-200 px-2 py-2"><?php echo h($workerName); ?></td>
                    <td class="border border-gray-200 px-2 py-2"><?php echo h($jobTypeSnapshot); ?></td>
                    <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
                        <?php
                        $dateKey = $selectedMonth . '-' . str_pad((string)$d, 2, '0', STR_PAD_LEFT);
                        $gongsuValue = isset($dailyMap[$dateKey]) ? $dailyMap[$dateKey] : null;
                        $gongsuDisplay = cpms_format_gongsu_value($gongsuValue);
                        $timeEntry = isset($timeDailyMap[$dateKey]) && is_array($timeDailyMap[$dateKey]) ? $timeDailyMap[$dateKey] : array();
                        $startTimeDisplay = cpms_format_labor_time_value(isset($timeEntry['start']) ? $timeEntry['start'] : '');
                        $endTimeDisplay = cpms_format_labor_time_value(isset($timeEntry['end']) ? $timeEntry['end'] : '');
                        ?>
                        <td class="cpms-gongsu-cell-slot border border-gray-200 px-0 py-0 text-center <?php echo ($dateKey === $todayDateKey) ? 'cpms-labor-today-cell' : ''; ?>" data-date="<?php echo h($dateKey); ?>">
                            <?php if ($canEditTimesheet): ?>
                            <button type="button"
                                    class="cpms-gongsu-cell flex w-full min-h-[28px] items-center justify-center rounded px-1 hover:bg-yellow-50"
                                    data-project-id="<?php echo (int)(isset($pid) ? $pid : (isset($projectId) ? $projectId : 0)); ?>"
                                    data-month="<?php echo h($selectedMonth); ?>"
                                    data-worker-name="<?php echo h($workerName); ?>"
                                    data-date="<?php echo h($dateKey); ?>"
                                    data-worker-key="<?php echo h($workerKey); ?>"
                                    data-start-time="<?php echo h($startTimeDisplay); ?>"
                                    data-end-time="<?php echo h($endTimeDisplay); ?>"
                                    data-old-value="<?php echo h($gongsuDisplay); ?>"><?php echo h($gongsuDisplay); ?></button>
                            <?php else: ?>
                                <span class="flex w-full min-h-[28px] items-center justify-center px-1"><?php echo h($gongsuDisplay); ?></span>
                            <?php endif; ?>
                        </td>
                    <?php endfor; ?>
                    <td class="cpms-output-days border border-gray-200 px-2 py-2 text-center"><?php echo h($outputDays > 0 ? (string)$outputDays : '0'); ?></td>
                    <td class="cpms-total-gongsu border border-gray-200 px-2 py-2 text-right"><?php echo h(cpms_format_gongsu_value($totalGongsu)); ?></td>
                    <td class="border border-gray-200 px-2 py-2 text-right"><?php echo h($wageRateRaw !== '' ? $wageRateRaw : '0'); ?></td>
                    <td class="cpms-total-pay border border-gray-200 px-2 py-2 text-right"><?php echo h($totalPay > 0 ? cpms_format_money_value($totalPay) : '0'); ?></td>
                    <?php if ($showBankColumns): ?>
                    <td class="border border-gray-200 px-2 py-2"><?php echo h(isset($worker['account_holder']) ? $worker['account_holder'] : ''); ?></td>
                    <td class="border border-gray-200 px-2 py-2"><?php echo h(isset($worker['bank_name']) ? $worker['bank_name'] : ''); ?></td>
                    <td class="border border-gray-200 px-2 py-2"><?php echo h(isset($worker['bank_account']) ? $worker['bank_account'] : ''); ?></td>
                    <?php endif; ?>
                    <td class="border border-gray-200 px-2 py-2"><?php echo h(isset($worker['company_name']) ? $worker['company_name'] : ''); ?></td>
                </tr>
                <?php if ($debugMode): ?>
                <tr class="bg-yellow-50">
                    <td class="border border-gray-200 px-2 py-1 text-[10px] text-gray-700" colspan="<?php echo ($showBankColumns ? (11 + (int)$daysInMonth) : (8 + (int)$daysInMonth)) + ($showLaborBulkSelector ? 1 : 0); ?>">
                        <?php echo h($workerName); ?> / 총공수 <?php echo h(cpms_format_gongsu_value($totalGongsu)); ?> / 출력일수 <?php echo h((string)$outputDays); ?> / 임금단가원본 deposit_rate=<?php echo h(isset($worker['deposit_rate']) ? (string)$worker['deposit_rate'] : ''); ?> / daily_wage=<?php echo h(isset($worker['daily_wage']) ? (string)$worker['daily_wage'] : ''); ?> / 적용단가=<?php echo h((string)(int)round($wageRate)); ?> / 지급총액=<?php echo h((string)(int)round($totalPay)); ?>
                    </td>
                </tr>
                <?php endif; ?>                
                <?php
                $rowGroupKey = cpms_labor_sheet_group_key($worker);
                $showGroupSubtotal = $laborSheetShowSubtotals && isset($laborSheetGroupLastIndex[$rowGroupKey]) && (int)$laborSheetGroupLastIndex[$rowGroupKey] === (int)$idx;
                $groupSubtotal = isset($laborSheetSubtotals[$rowGroupKey]) ? $laborSheetSubtotals[$rowGroupKey] : array('daily'=>array(), 'output_days'=>0, 'gongsu'=>0, 'pay'=>0);
                ?>
                <?php if ($showGroupSubtotal): ?>
                <tr class="bg-amber-50 text-amber-950 font-extrabold cpms-labor-subtotal-row" data-group-key="<?php echo h($rowGroupKey); ?>">
                    <td class="border border-gray-200 px-2 py-2 text-center" colspan="<?php echo 3 + ($showLaborBulkSelector ? 1 : 0); ?>">소계 <?php echo h(cpms_labor_sheet_group_label($rowGroupKey)); ?></td>
                    <?php for ($subtotalDay = 1; $subtotalDay <= $daysInMonth; $subtotalDay++): ?>
                        <?php $subtotalDaily = isset($groupSubtotal['daily'][$subtotalDay]) ? (float)$groupSubtotal['daily'][$subtotalDay] : 0.0; ?>
                        <td class="cpms-subtotal-daily border border-gray-200 px-1 py-2 text-center" data-date="<?php echo h($selectedMonth . '-' . str_pad((string)$subtotalDay, 2, '0', STR_PAD_LEFT)); ?>"><?php echo h($subtotalDaily > 0 ? cpms_format_gongsu_value($subtotalDaily) : '0'); ?></td>
                    <?php endfor; ?>
                    <td class="cpms-subtotal-output-days border border-gray-200 px-2 py-2 text-center"><?php echo h((string)(int)$groupSubtotal['output_days']); ?></td>
                    <td class="cpms-subtotal-gongsu border border-gray-200 px-2 py-2 text-right"><?php echo h(cpms_format_gongsu_value($groupSubtotal['gongsu'])); ?></td>
                    <td class="border border-gray-200 px-2 py-2 text-center">-</td>
                    <td class="cpms-subtotal-pay border border-gray-200 px-2 py-2 text-right"><?php echo h(cpms_format_money_value($groupSubtotal['pay'])); ?></td>
                    <?php if ($showBankColumns): ?><td class="border border-gray-200"></td><td class="border border-gray-200"></td><td class="border border-gray-200"></td><?php endif; ?>
                    <td class="border border-gray-200"></td>
                </tr>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php else: ?>
            <?php for ($i = 1; $i <= $timesheetRows; $i++): ?>
                <tr class="<?php echo ($i % 2 === 0) ? 'bg-gray-50' : 'bg-white'; ?>">
                    <?php if ($showLaborBulkSelector): ?>
                    <td class="border border-gray-200 px-2 py-2 text-center"></td>
                    <?php endif; ?>
                    <td class="border border-gray-200 px-2 py-2 text-center"><?php echo h(substr($selectedMonth, 5, 2)); ?>월</td>
                    <td class="border border-gray-200 px-2 py-2"></td>
                    <td class="border border-gray-200 px-2 py-2 text-center"></td>
                    <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
                        <?php $emptyDateKey = $selectedMonth . '-' . str_pad((string)$d, 2, '0', STR_PAD_LEFT); ?>
                        <td class="border border-gray-200 px-1 py-1 text-center <?php echo ($emptyDateKey === $todayDateKey) ? 'cpms-labor-today-cell' : ''; ?>"></td>
                    <?php endfor; ?>
                    <td class="border border-gray-200 px-2 py-2 text-center">0</td>
                    <td class="border border-gray-200 px-2 py-2 text-right">0</td>
                    <td class="border border-gray-200 px-2 py-2 text-right">0</td>
                    <td class="border border-gray-200 px-2 py-2 text-right">0</td>                    
                    <?php if ($showBankColumns): ?>
                    <td class="border border-gray-200 px-2 py-2"></td>
                    <td class="border border-gray-200 px-2 py-2"></td>
                    <td class="border border-gray-200 px-2 py-2"></td>
                    <?php endif; ?>
                    <td class="border border-gray-200 px-2 py-2"></td>
                </tr>
            <?php endfor; ?>
        <?php endif; ?>
        <tr class="bg-blue-50 text-blue-950 font-extrabold">
            <td class="border border-gray-200 px-2 py-2 text-center" colspan="<?php echo 3 + ($showLaborBulkSelector ? 1 : 0); ?>">합계</td>
            <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
                <?php $dayTotal = isset($laborSheetDailyTotals[$d]) ? (float)$laborSheetDailyTotals[$d] : 0.0; ?>
                <td class="cpms-daily-total border border-gray-200 px-1 py-2 text-center" data-date="<?php echo h($selectedMonth . '-' . str_pad((string)$d, 2, '0', STR_PAD_LEFT)); ?>"><?php echo h($dayTotal > 0 ? cpms_format_gongsu_value($dayTotal) : '0'); ?></td>
            <?php endfor; ?>
            <td class="cpms-sheet-output-days-total border border-gray-200 px-2 py-2 text-center"><?php echo h((string)(int)$laborSheetTotalOutputDays); ?></td>
            <td class="cpms-sheet-gongsu-total border border-gray-200 px-2 py-2 text-right"><?php echo h(cpms_format_gongsu_value($laborSheetTotalGongsu)); ?></td>
            <td class="border border-gray-200 px-2 py-2 text-center">-</td>
            <td class="cpms-sheet-pay-total border border-gray-200 px-2 py-2 text-right"><?php echo h(cpms_format_money_value($laborSheetTotalPay)); ?></td>
            <?php if ($showBankColumns): ?>
            <td class="border border-gray-200 px-2 py-2"></td>
            <td class="border border-gray-200 px-2 py-2"></td>
            <td class="border border-gray-200 px-2 py-2"></td>
            <?php endif; ?>
            <td class="border border-gray-200 px-2 py-2"></td>
        </tr>
        </tbody>
    </table>
</div>
