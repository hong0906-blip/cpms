<?php
/**
 * - 공사: 노무비 탭
 * - 하위 탭: 공수 / 노무비 / 외주비 / 인원작성
 * - PHP 5.6 호환
 */
require_once __DIR__ . '/../../../services/CostChangeService.php';

use App\Services\CostChangeService;

$canEditLabor = isset($canEdit) ? (bool)$canEdit : false;
$laborRelaxedRegistration = \App\Core\Auth::isDevelopmentDepartment();
$laborGongsuApprovalExempt = $laborRelaxedRegistration;
$laborTab = isset($_GET['labor_tab']) ? trim((string)$_GET['labor_tab']) : 'timesheet';
if ($laborTab === '') $laborTab = 'timesheet';

$laborTabs = array(
    'timesheet' => '공수',
    'labor' => '노무비',
    'outsourcing' => '외주비',
    'workers'   => '인원 작성',
    'worker_register' => '인원 등록',
);
if (!$canEditLabor) {
    if (isset($laborTabs['workers'])) unset($laborTabs['workers']);
    if (isset($laborTabs['worker_register'])) unset($laborTabs['worker_register']);
}
if (!isset($laborTabs[$laborTab])) $laborTab = 'timesheet';

$laborSort = isset($_GET['labor_sort']) ? trim((string)$_GET['labor_sort']) : 'job_type';
$laborSortAllowed = array('name', 'job_type', 'output_days', 'total_gongsu', 'wage_rate', 'company', 'labor_ratio', 'outsourcing_ratio', 'labor_amount', 'outsourcing_amount');
if (!in_array($laborSort, $laborSortAllowed, true)) $laborSort = 'job_type';
$laborSortDir = isset($_GET['labor_sort_dir']) ? trim((string)$_GET['labor_sort_dir']) : 'asc';
if ($laborSortDir !== 'desc') $laborSortDir = 'asc';
$workerSort = isset($_GET['worker_sort']) ? trim((string)$_GET['worker_sort']) : 'company';
$workerSortAllowed = array('company', 'name', 'allocation', 'phone', 'address', 'job_type', 'wage', 'remark');
if (!in_array($workerSort, $workerSortAllowed, true)) $workerSort = 'company';
$workerSortDir = isset($_GET['worker_sort_dir']) ? trim((string)$_GET['worker_sort_dir']) : 'asc';
if ($workerSortDir !== 'desc') $workerSortDir = 'asc';

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
    $currentMonth = date('Y-m');
    if (in_array($currentMonth, $months, true)) {
        $selectedMonth = $currentMonth;
    } else {
        $selectedMonth = $months[count($months) - 1];
    }
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

$downloadLaborTab = in_array($laborTab, array('timesheet', 'labor', 'outsourcing'), true) ? $laborTab : 'timesheet';
$downloadLabel = $downloadLaborTab === 'labor' ? '노무비 다운로드' : ($downloadLaborTab === 'outsourcing' ? '외주비 다운로드' : '공수 다운로드');
$downloadUrl = base_url() . '/?r=construction/labor_sheet_download&pid=' . (int)$pid . '&month=' . urlencode($selectedMonth) . '&labor_tab=' . urlencode($downloadLaborTab) . '&labor_sort=' . urlencode($laborSort) . '&labor_sort_dir=' . urlencode($laborSortDir);

require_once __DIR__ . '/partials/labor_data_loader.php';

$directTeamMembers = cpms_load_direct_team_members(isset($pdo) ? $pdo : null);
$activeDirectTeamMembers = array();
$directTeamActiveMap = array();
foreach ($directTeamMembers as $directTeamMember) {
    $directTeamMemberId = isset($directTeamMember['id']) ? (int)$directTeamMember['id'] : 0;
    $directTeamMemberActive = !isset($directTeamMember['is_active']) || (int)$directTeamMember['is_active'] === 1;
    if ($directTeamMemberId > 0) $directTeamActiveMap[$directTeamMemberId] = $directTeamMemberActive;
    if ($directTeamMemberId > 0 && $directTeamMemberActive) $activeDirectTeamMembers[] = $directTeamMember;
}
$gongsuData = cpms_load_gongsu_data(isset($pdo) ? $pdo : null, isset($projectRow['name']) ? $projectRow['name'] : '', $selectedMonth);

$attendanceWorkers = isset($gongsuData['all_workers']) ? $gongsuData['all_workers'] : (isset($gongsuData['workers']) ? $gongsuData['workers'] : array());
$excludedWorkers = isset($gongsuData['excluded_workers']) ? $gongsuData['excluded_workers'] : array();
$attendanceGongsuMap = isset($gongsuData['gongsu_map']) ? $gongsuData['gongsu_map'] : array();
$attendanceGongsuUnit = isset($gongsuData['gongsu_unit']) ? $gongsuData['gongsu_unit'] : array();
$attendanceOutputDays = isset($gongsuData['output_days']) ? $gongsuData['output_days'] : array();
$attendanceTimeMap = isset($gongsuData['time_map']) && is_array($gongsuData['time_map']) ? $gongsuData['time_map'] : array();

$projectId = isset($pid) ? (int)$pid : 0;
$overrideDataset = function_exists('cpms_apply_labor_overrides_to_dataset')
    ? cpms_apply_labor_overrides_to_dataset($attendanceGongsuMap, $attendanceOutputDays, $attendanceGongsuUnit, $projectId, $selectedMonth)
    : array(
        'gongsu_map' => $attendanceGongsuMap,
        'output_days' => $attendanceOutputDays,
        'gongsu_unit' => $attendanceGongsuUnit,
    );
$attendanceGongsuMap = isset($overrideDataset['gongsu_map']) && is_array($overrideDataset['gongsu_map']) ? $overrideDataset['gongsu_map'] : array();
$attendanceOutputDays = isset($overrideDataset['output_days']) && is_array($overrideDataset['output_days']) ? $overrideDataset['output_days'] : array();
$attendanceGongsuUnit = isset($overrideDataset['gongsu_unit']) && is_array($overrideDataset['gongsu_unit']) ? $overrideDataset['gongsu_unit'] : array();
$pendingOverrideRows = cpms_load_labor_override_pending($projectId, $selectedMonth);
$overrideRequestRows = array();
$overrideHistoryMap = array();
if (isset($pdo) && $pdo) {
    try {
        cpms_ensure_labor_override_table($pdo);
        $sql = "SELECT id, batch_token, request_scope, worker_name, work_date, old_value, new_value, reason, reject_reason, status, requested_by, requested_by_email, created_at, updated_at, rejected_at
                FROM cpms_labor_gongsu_overrides
                WHERE project_id = :pid AND month = :month
                  AND (status = 'pending' OR (status = 'rejected' AND rejected_acknowledged_at IS NULL))
                ORDER BY updated_at DESC, id DESC";
        $st = $pdo->prepare($sql);
        $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $st->bindValue(':month', $selectedMonth, PDO::PARAM_STR);
        $st->execute();
        $overrideRequestRows = cpms_labor_group_override_rows($st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        $overrideRequestRows = array();
    }

    try {
        cpms_ensure_labor_override_table($pdo);
        $sqlHistory = "SELECT id, worker_key, worker_name, work_date, old_value, new_value, is_deleted_entry, reason, status,
                              requested_by_email, requested_by_name, created_at,
                              first_approver_name, first_approver_email, first_approved_at,
                              second_approver_name, second_approver_email, second_approved_at,
                              approved_at, final_approved_at,
                              rejected_by_name, rejected_by_email, rejected_at, reject_reason,
                              approval_stage, approval_required_level
                       FROM cpms_labor_gongsu_overrides
                       WHERE project_id = :pid AND month = :month
                       ORDER BY created_at DESC, id DESC";
        $stHistory = $pdo->prepare($sqlHistory);
        $stHistory->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $stHistory->bindValue(':month', $selectedMonth, PDO::PARAM_STR);
        $stHistory->execute();
        $historyRows = $stHistory->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($historyRows)) {
            foreach ($historyRows as $historyRow) {
                $historyKey = trim((string)(isset($historyRow['worker_key']) ? $historyRow['worker_key'] : '')) . '|' . trim((string)(isset($historyRow['work_date']) ? $historyRow['work_date'] : ''));
                if ($historyKey === '|') continue;
                if (!isset($overrideHistoryMap[$historyKey])) $overrideHistoryMap[$historyKey] = array();
                $overrideHistoryMap[$historyKey][] = $historyRow;
            }
        }
    } catch (Exception $e) {
        $overrideHistoryMap = array();
    }
}

$executiveUsers = array();
if (isset($pdo) && $pdo) {
    try {
        $stExec = $pdo->query("SELECT id, name, email, position FROM employees WHERE role = 'executive' AND is_active = 1 ORDER BY name ASC");
        $executiveUsers = $stExec->fetchAll();
    } catch (Exception $e) {
        $executiveUsers = array();
    }
}

cpms_cleanup_project_labor_workers(isset($pdo) ? $pdo : null, $projectId, $excludedWorkers); // 장비기사 기존 기록 삭제(soft delete)
cpms_sync_project_labor_workers_from_attendance(isset($pdo) ? $pdo : null, $projectId, $attendanceWorkers); // 장비기사 제외
// 기존 현장 인원은 삭제하지 않고, 안전한 일치 규칙으로 관리 인력 마스터에 자동 편입합니다.
if ($canEditLabor && isset($pdo) && $pdo && cpms_labor_load_workforce_services()) {
    try {
        $workforceSyncRepo = new WorkerRepository($pdo);
        $workforceSyncUser = \App\Core\Auth::user();
        $workforceSyncUserId = is_array($workforceSyncUser) && isset($workforceSyncUser['id']) ? (int)$workforceSyncUser['id'] : 0;
        $workforceSyncRepo->syncLegacyProjectWorkers($projectId, $workforceSyncUserId, 1000);
    } catch (Exception $e) {
        // 화면 표시는 기존 인원 데이터로 계속 진행합니다.
    }
}
$projectLaborWorkers = cpms_load_project_labor_workers(isset($pdo) ? $pdo : null, $projectId);
$laborWorkerRatioMap = cpms_load_project_labor_worker_month_ratio_map(isset($pdo) ? $pdo : null, $projectId, $selectedMonth, $projectLaborWorkers);
$projectLaborWorkers = cpms_apply_project_labor_worker_month_ratios($projectLaborWorkers, $laborWorkerRatioMap);
$laborWorkerWageMap = cpms_load_project_labor_worker_wage_map(isset($pdo) ? $pdo : null, $projectId, $selectedMonth);
$projectLaborWorkers = cpms_apply_project_labor_worker_month_wages($projectLaborWorkers, $laborWorkerWageMap);
$workerRows = cpms_build_project_worker_rows($projectLaborWorkers, $directTeamMembers, isset($pdo) ? $pdo : null, $selectedMonth);
$laborWorkerMonthMap = function_exists('cpms_load_project_labor_worker_month_map') ? cpms_load_project_labor_worker_month_map(isset($pdo) ? $pdo : null, $projectId, $selectedMonth) : array();
$previousLaborMonth = date('Y-m', strtotime($selectedMonth . '-01 -1 month'));
$previousLaborWorkers = ($laborTab === 'workers' && $canEditLabor && function_exists('cpms_load_project_labor_workers_for_month'))
    ? cpms_load_project_labor_workers_for_month(isset($pdo) ? $pdo : null, $projectId, $previousLaborMonth)
    : array();
if (is_array($workerRows) && is_array($laborWorkerMonthMap) && count($laborWorkerMonthMap) > 0) {
    foreach ($workerRows as $workerRowIndex => $workerRow) {
        $laborWorkerId = isset($workerRow['id']) ? (int)$workerRow['id'] : 0;
        $isMonthAssigned = ($laborWorkerId > 0 && isset($laborWorkerMonthMap[$laborWorkerId])) ? 1 : 0;
        $workerRows[$workerRowIndex]['month_assigned'] = $isMonthAssigned;
        if (isset($workerRows[$workerRowIndex]['data']) && is_array($workerRows[$workerRowIndex]['data'])) {
            $workerRows[$workerRowIndex]['data']['month_assigned'] = $isMonthAssigned;
        }
    }
}
$timesheetWorkers = cpms_build_timesheet_workers($workerRows);
// 공수 월별 출력일수 필터: 선택월에 실제 공수가 있는 인원만 표시
if (is_array($timesheetWorkers)) {
    $filteredTimesheetWorkers = array();
    foreach ($timesheetWorkers as $worker) {
        $workerName = isset($worker['name']) ? (string)$worker['name'] : '';
        $workerKey = cpms_normalize_worker_key($workerName);
        if ($workerKey === '') continue;
        $workerOutputDays = isset($attendanceOutputDays[$workerKey]) ? (int)$attendanceOutputDays[$workerKey] : 0;
        $isMonthAssigned = (isset($worker['month_assigned']) && (int)$worker['month_assigned'] === 1);
        if ($workerOutputDays <= 0 && !$isMonthAssigned) continue;
        $filteredTimesheetWorkers[] = $worker;
    }
    $timesheetWorkers = $filteredTimesheetWorkers;
}
if (function_exists('cpms_sort_labor_workers')) {
    $timesheetWorkers = cpms_sort_labor_workers($timesheetWorkers, $laborSort, $laborSortDir, $attendanceGongsuMap, $attendanceOutputDays, $selectedMonth);
}

$outsourcingTimesheetWorkers = array();
$laborTimesheetWorkers = array();
if (is_array($timesheetWorkers)) {
    foreach ($timesheetWorkers as $worker) {
        $allocationAmounts = cpms_labor_calculate_worker_month_amounts($worker, $attendanceGongsuMap, $selectedMonth);
        if (isset($allocationAmounts['outsourcing_amount']) && (float)$allocationAmounts['outsourcing_amount'] > 0) $outsourcingTimesheetWorkers[] = $worker;
        if (isset($allocationAmounts['labor_amount']) && (float)$allocationAmounts['labor_amount'] > 0) $laborTimesheetWorkers[] = $worker;
    }
}
$laborTimesheetRows = count($laborTimesheetWorkers);
if ($laborTimesheetRows < 1) $laborTimesheetRows = 1;
$outsourcingTimesheetRows = count($outsourcingTimesheetWorkers);
if ($outsourcingTimesheetRows < 1) $outsourcingTimesheetRows = 1;

$workerRowsForSelectedMonth = array();
if (is_array($workerRows)) {
    foreach ($workerRows as $row) {
        $member = isset($row['data']) && is_array($row['data']) ? $row['data'] : array();
        $workerName = isset($member['name']) ? (string)$member['name'] : '';
        $workerKey = cpms_normalize_worker_key($workerName);
        if ($workerKey === '') continue;
        $workerOutputDays = isset($attendanceOutputDays[$workerKey]) ? (int)$attendanceOutputDays[$workerKey] : 0;
        $isMonthAssigned = (isset($row['month_assigned']) && (int)$row['month_assigned'] === 1) || (isset($member['month_assigned']) && (int)$member['month_assigned'] === 1);
        if ($workerOutputDays <= 0 && !$isMonthAssigned) continue;
        $workerRowsForSelectedMonth[] = $row;
    }
}
if (function_exists('cpms_sort_labor_worker_rows')) {
    $workerRowsForSelectedMonth = cpms_sort_labor_worker_rows($workerRowsForSelectedMonth, $workerSort, $workerSortDir);
}

if (!function_exists('cpms_labor_worker_sort_header')) {
    function cpms_labor_worker_sort_header($field, $label, $currentSort, $currentDir, $projectId, $selectedMonth, $laborSort, $laborSortDir) {
        $isActive = ((string)$currentSort === (string)$field);
        $arrow = ($isActive && $currentDir === 'desc') ? '▼' : '▲';
        $nextDir = ($isActive && $currentDir === 'asc') ? 'desc' : 'asc';
        $href = base_url() . '/?r=공사&pid=' . (int)$projectId
            . '&tab=labor&labor_tab=workers&month=' . urlencode($selectedMonth)
            . '&labor_sort=' . urlencode($laborSort) . '&labor_sort_dir=' . urlencode($laborSortDir)
            . '&worker_sort=' . urlencode($field) . '&worker_sort_dir=' . urlencode($nextDir);
        return '<a href="' . h($href) . '" class="inline-flex items-center justify-center gap-1 whitespace-nowrap hover:text-blue-700">'
            . '<span>' . h($label) . '</span><span class="text-[10px] leading-none">' . h($arrow) . '</span></a>';
    }
}

$timesheetRows = count($timesheetWorkers);
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

if (!function_exists('cpms_labor_tab_monthly_allocation_totals')) {
    function cpms_labor_tab_monthly_allocation_totals($workers, $gongsuMap, $selectedMonth) {
        $totals = array('total_amount' => 0.0, 'labor_amount' => 0.0, 'outsourcing_amount' => 0.0);
        if (!is_array($workers)) return $totals;
        foreach ($workers as $worker) {
            $amounts = cpms_labor_calculate_worker_month_amounts($worker, $gongsuMap, $selectedMonth);
            $totals['total_amount'] += isset($amounts['total_amount']) ? (float)$amounts['total_amount'] : 0.0;
            $totals['labor_amount'] += isset($amounts['labor_amount']) ? (float)$amounts['labor_amount'] : 0.0;
            $totals['outsourcing_amount'] += isset($amounts['outsourcing_amount']) ? (float)$amounts['outsourcing_amount'] : 0.0;
        }
        return $totals;
    }
}

$canManageLaborForce = \App\Core\Auth::isDevelopmentDepartment();
$laborForceRow = function_exists('cpms_labor_force_load') ? cpms_labor_force_load(isset($pdo) ? $pdo : null, $projectId, $selectedMonth) : array('amount' => 0.0, 'memo' => '');
$laborForceAmount = isset($laborForceRow['amount']) ? (float)$laborForceRow['amount'] : 0.0;
$laborAllocationTotals = cpms_labor_tab_monthly_allocation_totals($timesheetWorkers, $attendanceGongsuMap, $selectedMonth);
$fullPayTotalAmount = isset($laborAllocationTotals['total_amount']) ? (float)$laborAllocationTotals['total_amount'] : 0.0;
$laborBaseAmount = isset($laborAllocationTotals['labor_amount']) ? (float)$laborAllocationTotals['labor_amount'] : 0.0;
$outsourcingBaseAmount = isset($laborAllocationTotals['outsourcing_amount']) ? (float)$laborAllocationTotals['outsourcing_amount'] : 0.0;
$laborTotalAmount = $laborBaseAmount + $laborForceAmount;

$todayKey = date('Y-m-d');
$todayWorkers = array();
foreach ($timesheetWorkers as $worker) {
    $name = isset($worker['name']) ? (string)$worker['name'] : '';
    $key = cpms_timesheet_worker_key($name);
    if ($key === '') continue;
    $dailyMap = isset($attendanceGongsuMap[$key]) ? $attendanceGongsuMap[$key] : array();
    if (!isset($dailyMap[$todayKey])) continue;
    $gongsuValue = $dailyMap[$todayKey];
    if ($gongsuValue === null || $gongsuValue === '') continue;
    $todayWorkers[] = array(
        'name' => $name,
        'gongsu' => cpms_format_gongsu_value($gongsuValue),
    );
}
?>

<div class="bg-white rounded-3xl border border-gray-200 p-6 shadow-sm">
    <div class="flex flex-wrap items-center gap-3 justify-between">
        <div>
            <h3 class="text-xl font-extrabold text-gray-900">노무비</h3>
        </div>

        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label class="text-xs font-bold text-gray-500">월 선택</label>
                <select class="mt-1 px-3 py-2 rounded-xl border border-gray-200 text-sm"
                        onchange="location.href='?r=공사&pid=<?php echo (int)$pid; ?>&tab=labor&labor_tab=<?php echo h($laborTab); ?>&labor_sort=<?php echo h($laborSort); ?>&labor_sort_dir=<?php echo h($laborSortDir); ?>&worker_sort=<?php echo h($workerSort); ?>&worker_sort_dir=<?php echo h($workerSortDir); ?>&month=' + encodeURIComponent(this.value)">
                    <?php foreach ($months as $ym): ?>
                        <option value="<?php echo h($ym); ?>" <?php echo ($ym === $selectedMonth) ? 'selected' : ''; ?>>
                            <?php echo h(isset($monthLabels[$ym]) ? $monthLabels[$ym] : $ym); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($canEditLabor && $canDownload): ?>
                <a href="<?php echo h($downloadUrl); ?>"
                   class="px-4 py-2 rounded-2xl bg-gray-900 text-white font-extrabold shadow hover:shadow-lg transition">
                    <?php echo h($downloadLabel); ?>
                </a>
            <?php else: ?>
                <button type="button"
                        class="px-4 py-2 rounded-2xl bg-gray-200 text-gray-500 font-extrabold cursor-not-allowed"
                        title="<?php echo $canEditLabor ? '해당 월이 종료된 후 다운로드할 수 있습니다.' : '다운로드 권한이 없습니다.'; ?>">
                    <?php echo h($downloadLabel); ?>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($laborTab === 'timesheet'): ?>
    <div class="mt-5 grid grid-cols-1 gap-3">
        <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4">
            <div class="text-xs font-bold text-gray-500">공수 전체 지급총액</div>
            <div class="mt-1 text-xl font-extrabold text-gray-900"><?php echo number_format($fullPayTotalAmount); ?>원</div>
        </div>
    </div>
    <?php elseif ($laborTab === 'labor'): ?>
    <div class="mt-5 grid grid-cols-1 <?php echo $canManageLaborForce ? 'md:grid-cols-3' : ''; ?> gap-3">
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4">
            <div class="text-xs font-bold text-emerald-700">인원 노무비 반영금액</div>
            <div class="mt-1 text-xl font-extrabold text-emerald-950"><?php echo number_format($laborBaseAmount); ?>원</div>
        </div>
        <?php if ($canManageLaborForce): ?>
        <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4">
            <div class="text-xs font-bold text-gray-500">강제입력 노무비</div>
            <div class="mt-1 text-xl font-extrabold text-gray-900"><?php echo number_format($laborForceAmount); ?>원</div>
        </div>
        <div class="rounded-2xl border border-gray-900 bg-gray-900 p-4 text-white">
            <div class="text-xs font-bold text-gray-300">월 노무비 합계</div>
            <div class="mt-1 text-xl font-extrabold"><?php echo number_format($laborTotalAmount); ?>원</div>
            <div class="mt-1 text-[11px] text-gray-300">인원 노무비 + 강제입력 노무비</div>
        </div>
        <?php endif; ?>
    </div>
    <?php elseif ($laborTab === 'outsourcing'): ?>
    <div class="mt-5 grid grid-cols-1 gap-3">
        <div class="rounded-2xl border border-blue-200 bg-blue-50 p-4">
            <div class="text-xs font-bold text-blue-700">인원 외주비 전체 합계</div>
            <div class="mt-1 text-xl font-extrabold text-blue-950"><?php echo number_format($outsourcingBaseAmount); ?>원</div>
        </div>
    </div>
    <?php else: ?>
    <div class="mt-5 grid grid-cols-1 md:grid-cols-3 gap-3">
        <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4"><div class="text-xs font-bold text-gray-500">전체 지급총액</div><div class="mt-1 text-xl font-extrabold text-gray-900"><?php echo number_format($fullPayTotalAmount); ?>원</div></div>
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4"><div class="text-xs font-bold text-emerald-700">인원 노무비</div><div class="mt-1 text-xl font-extrabold text-emerald-950"><?php echo number_format($laborBaseAmount); ?>원</div></div>
        <div class="rounded-2xl border border-blue-200 bg-blue-50 p-4"><div class="text-xs font-bold text-blue-700">인원 외주비</div><div class="mt-1 text-xl font-extrabold text-blue-950"><?php echo number_format($outsourcingBaseAmount); ?>원</div></div>
    </div>
    <?php endif; ?>

    <?php if ($canManageLaborForce && $laborTab === 'workers'): ?>
        <?php
        $laborForceTargetId = isset($laborForceRow['id']) ? (int)$laborForceRow['id'] : 0;
        $laborForceLock = CostChangeService::lockInfo('labor', $selectedMonth . '-01', $selectedMonth, date('Y-m-d'));
        $laborForceActiveRequest = $laborForceTargetId > 0 ? CostChangeService::activeRequest($pdo, 'labor_force', (string)$laborForceTargetId) : null;
        $laborForceHistoryCount = $laborForceTargetId > 0 ? CostChangeService::historyCount($pdo, 'labor_force', (string)$laborForceTargetId) : 0;
        $laborForceLatestRequest = $laborForceHistoryCount > 0 ? CostChangeService::latestRequest($pdo, 'labor_force', (string)$laborForceTargetId) : null;
        $laborForceReturnUrl = '?r=공사&pid=' . (int)$pid . '&tab=labor&labor_tab=workers&month=' . $selectedMonth;
        ?>
        <?php if (!empty($laborForceLock['locked'])): ?>
        <div class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 p-4">
            <div class="font-extrabold text-amber-900">마감된 노무비 월입니다. 변경하려면 비용 변경 승인이 필요합니다.</div>
            <div class="mt-3 flex flex-wrap gap-2">
                <?php if (is_array($laborForceActiveRequest)): ?>
                    <span class="px-3 py-2 rounded-xl bg-white text-amber-800 font-bold"><?php echo h(CostChangeService::statusLabel($laborForceActiveRequest['status'])); ?></span>
                    <a href="?r=cost_change/detail&id=<?php echo (int)$laborForceActiveRequest['id']; ?>" class="px-3 py-2 rounded-xl border border-blue-200 bg-white text-blue-700 font-bold">요청 상세</a>
                <?php elseif ($laborForceTargetId > 0): ?>
                    <?php foreach (array('MODIFY'=>'수정 승인 요청','MONTH_MOVE'=>'귀속월 변경 요청','DELETE'=>'삭제 승인 요청') as $requestCode=>$requestLabel): ?>
                        <a href="?r=cost_change/request&project_id=<?php echo (int)$pid; ?>&target_type=labor_force&target_id=<?php echo (int)$laborForceTargetId; ?>&request_type=<?php echo h($requestCode); ?>&return_url=<?php echo rawurlencode($laborForceReturnUrl); ?>" class="px-3 py-2 rounded-xl border border-amber-300 bg-white text-amber-800 font-bold"><?php echo h($requestLabel); ?></a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <a href="?r=cost_change/request&project_id=<?php echo (int)$pid; ?>&target_type=labor_force&request_type=ADD&return_url=<?php echo rawurlencode($laborForceReturnUrl); ?>" class="px-3 py-2 rounded-xl border border-amber-300 bg-white text-amber-800 font-bold">추가 승인 요청</a>
                <?php endif; ?>
                <?php if ($laborForceHistoryCount > 0): ?><a href="?r=cost_change/history&target_type=labor_force&target_id=<?php echo (int)$laborForceTargetId; ?>&project_id=<?php echo (int)$pid; ?>" class="px-3 py-2 rounded-xl border border-blue-200 bg-white text-blue-700 font-bold"><?php echo h(CostChangeService::historyBadgeLabel($laborForceLatestRequest, $laborForceHistoryCount)); ?></a><?php endif; ?>
            </div>
        </div>
        <?php else: ?>
        <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/labor_force_save" class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 p-4">
            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
            <input type="hidden" name="month" value="<?php echo h($selectedMonth); ?>">
            <input type="hidden" name="labor_tab" value="<?php echo h($laborTab); ?>">
            <input type="hidden" name="worker_sort" value="<?php echo h($workerSort); ?>">
            <input type="hidden" name="worker_sort_dir" value="<?php echo h($workerSortDir); ?>">
            <div class="grid grid-cols-1 md:grid-cols-[220px_1fr_auto] gap-3 items-end">
                <div>
                    <label class="text-xs font-bold text-amber-800">개발 부서 전용 강제입력</label>
                    <input type="text" name="amount" value="<?php echo h(number_format($laborForceAmount)); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-amber-200 bg-white text-right font-bold">
                </div>
                <div>
                    <label class="text-xs font-bold text-amber-800">메모</label>
                    <input type="text" name="memo" value="<?php echo h(isset($laborForceRow['memo']) ? $laborForceRow['memo'] : ''); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-amber-200 bg-white" placeholder="예: 시프티 미등록 인원 노무비 보정" required>
                </div>
                <button type="submit" class="px-4 py-2 rounded-xl bg-amber-600 text-white font-extrabold">저장</button>
            </div>
            <?php if (isset($laborForceRow['updated_at']) && trim((string)$laborForceRow['updated_at']) !== ''): ?>
                <div class="mt-2 text-xs text-amber-800">최근 저장: <?php echo h($laborForceRow['updated_at']); ?> <?php echo h(isset($laborForceRow['updated_by_name']) ? $laborForceRow['updated_by_name'] : ''); ?></div>
            <?php endif; ?>
        </form>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php if ($laborTab === 'timesheet'): ?>
<style>
    details.cpms-labor-collapsible > summary .cpms-collapse-close-label { display: none; }
    details.cpms-labor-collapsible[open] > summary .cpms-collapse-open-label { display: none; }
    details.cpms-labor-collapsible[open] > summary .cpms-collapse-close-label { display: inline; }
</style>
<?php if (!empty($pendingOverrideRows)): ?>
<details class="cpms-labor-collapsible mt-3 rounded-xl border border-amber-200 bg-amber-50 text-sm text-amber-900">
    <summary class="cursor-pointer select-none px-4 py-3 font-extrabold">공수 수정 승인대기 <?php echo count($pendingOverrideRows); ?>건 · <span class="cpms-collapse-open-label">펼치기</span><span class="cpms-collapse-close-label">접기</span></summary>
    <div class="space-y-1 border-t border-amber-200 px-4 py-3">
    <?php foreach ($pendingOverrideRows as $pr): ?>
        <?php
        $pendingWorkerCount = isset($pr['worker_count']) ? (int)$pr['worker_count'] : 1;
        $pendingWorkerNames = isset($pr['worker_names_text']) && trim((string)$pr['worker_names_text']) !== '' ? (string)$pr['worker_names_text'] : (isset($pr['worker_name']) ? (string)$pr['worker_name'] : '-');
        ?>
        <div>- <?php echo h($pendingWorkerNames); ?><?php if ($pendingWorkerCount > 1): ?> (총 <?php echo $pendingWorkerCount; ?>명)<?php endif; ?> / <?php echo h($pr['work_date']); ?> / <?php if ($pendingWorkerCount > 1): ?>전체 → <?php else: ?><?php echo h($pr['old_value']); ?> → <?php endif; ?><?php echo h($pr['new_value']); ?> (승인대기)</div>
    <?php endforeach; ?>
    </div>
</details>
<?php endif; ?>
<details class="cpms-labor-collapsible mt-3 bg-white rounded-3xl border border-gray-200 shadow-sm">
    <summary class="cursor-pointer select-none px-4 py-4 font-extrabold text-gray-900 text-base">공수 수정 요청 내역 <?php echo count($overrideRequestRows); ?>건 · <span class="cpms-collapse-open-label">펼치기</span><span class="cpms-collapse-close-label">접기</span></summary>
    <div class="space-y-2 border-t border-gray-100 px-4 py-4">
        <?php if (empty($overrideRequestRows)): ?>
            <div class="text-sm text-gray-500">요청 내역이 없습니다.</div>
        <?php else: ?>
            <?php foreach ($overrideRequestRows as $rr): ?>
                <?php
                // 파일: app/views/construction/tabs/labor.php - 일괄 요청은 한 카드에 전체 이름을 표시합니다.
                $isRejected = ((string)$rr['status'] === 'rejected');
                $requestWorkerCount = isset($rr['worker_count']) ? (int)$rr['worker_count'] : 1;
                if ($requestWorkerCount < 1) $requestWorkerCount = 1;
                $requestWorkerNames = isset($rr['worker_names_text']) && trim((string)$rr['worker_names_text']) !== '' ? (string)$rr['worker_names_text'] : (isset($rr['worker_name']) ? (string)$rr['worker_name'] : '-');
                $isBulkRequest = isset($rr['batch_token']) && trim((string)$rr['batch_token']) !== '' && $requestWorkerCount > 1;
                $requestCreatedAt = isset($rr['created_at']) ? $rr['created_at'] : '';
                $requestOwnerEmail = isset($rr['requested_by_email']) ? strtolower(trim((string)$rr['requested_by_email'])) : '';
                $currentLaborUserEmail = strtolower(trim((string)\App\Core\Auth::userEmail()));
                $requestOwnerId = isset($rr['requested_by']) ? (int)$rr['requested_by'] : 0;
                $currentLaborUserId = method_exists('App\\Core\\Auth', 'id') ? (int)\App\Core\Auth::id() : 0;
                $canCancelPendingRequest = \App\Core\Auth::isMaster()
                    || ($requestOwnerId > 0 && $currentLaborUserId > 0 && $requestOwnerId === $currentLaborUserId)
                    || ($requestOwnerEmail !== '' && $currentLaborUserEmail !== '' && $requestOwnerEmail === $currentLaborUserEmail);
                ?>
                <div class="rounded-2xl border border-gray-100 p-3 text-sm" data-override-request-card>
                    <div class="flex flex-wrap items-center gap-2">
                        <?php if ($isRejected): ?>
                            <span class="px-2 py-1 rounded-full bg-red-100 text-red-700 text-xs font-bold">반려</span>
                        <?php else: ?>
                            <span class="px-2 py-1 rounded-full bg-amber-100 text-amber-700 text-xs font-bold">승인대기</span>
                        <?php endif; ?>
                        <span class="text-gray-800 font-bold"><?php echo h($requestWorkerNames); ?></span>
                        <?php if ($requestWorkerCount > 1): ?><span class="text-xs font-bold text-blue-700">총 <?php echo $requestWorkerCount; ?>명 일괄</span><?php endif; ?>
                        <span class="text-gray-500"><?php echo h($rr['work_date']); ?></span>
                    </div>
                    <?php if ($isBulkRequest): ?>
                        <div class="mt-1 text-gray-700">전체 <?php echo $requestWorkerCount; ?>명 요청 공수: <span class="font-extrabold"><?php echo h($rr['new_value']); ?></span></div>
                    <?php else: ?>
                        <div class="mt-1 text-gray-700">기존 공수: <?php echo h($rr['old_value']); ?> → 요청 공수: <span class="font-extrabold"><?php echo h($rr['new_value']); ?></span></div>
                    <?php endif; ?>
                    <div class="text-gray-700">요청사유: <?php echo h(trim((string)$rr['reason']) !== '' ? $rr['reason'] : '-'); ?></div>
                    <?php if ($isRejected): ?>
                        <div class="text-red-700">반려사유: <?php echo h(trim((string)$rr['reject_reason']) !== '' ? $rr['reject_reason'] : '-'); ?></div>
                    <?php endif; ?>
                    <div class="text-xs text-gray-500 mt-1">요청일: <?php echo h($requestCreatedAt); ?><?php if ($isRejected): ?> · 처리일: <?php echo h($rr['rejected_at']); ?><?php endif; ?></div>
                    <?php if ($isRejected): ?>
                        <div class="mt-3 flex justify-end">
                            <button type="button" class="px-3 py-2 rounded-xl bg-red-600 text-white text-xs font-extrabold" data-rejected-acknowledge data-override-id="<?php echo (int)$rr['id']; ?>">반려 확인</button>
                        </div>
                    <?php elseif ($canCancelPendingRequest): ?>
                        <div class="mt-3 flex justify-end">
                            <button type="button" class="px-3 py-2 rounded-xl border border-red-200 bg-white text-red-600 text-xs font-extrabold" data-pending-cancel data-override-id="<?php echo (int)$rr['id']; ?>">승인요청 취소</button>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</details>
<?php endif; ?>

<div class="flex flex-wrap gap-2 mt-4 mb-6">
    <?php foreach ($laborTabs as $k => $label): ?>
        <a href="<?php echo h(base_url()); ?>/?r=공사&pid=<?php echo (int)$pid; ?>&tab=labor&labor_tab=<?php echo h($k); ?>&month=<?php echo h($selectedMonth); ?>&labor_sort=<?php echo h($laborSort); ?>&labor_sort_dir=<?php echo h($laborSortDir); ?>&worker_sort=<?php echo h($workerSort); ?>&worker_sort_dir=<?php echo h($workerSortDir); ?>"
           class="px-4 py-2 rounded-2xl border font-extrabold <?php echo ($k===$laborTab)?'bg-gray-900 text-white border-gray-900':'bg-white text-gray-900 border-gray-200 hover:bg-gray-50'; ?>">
            <?php echo h($label); ?>
        </a>
    <?php endforeach; ?>
</div>

<?php if ($laborTab === 'timesheet'): ?>
    <?php if ($canEditLabor): ?>
    <div class="mb-3 flex flex-wrap items-center justify-end gap-2">
        <button type="button" class="px-4 py-2 rounded-2xl bg-red-600 text-white font-extrabold shadow-sm hover:shadow transition" data-labor-bulk-value="2">
            2공수 일괄<?php echo $laborGongsuApprovalExempt ? ' · 즉시입력' : ' · 승인요청'; ?>
        </button>
        <button type="button" class="px-4 py-2 rounded-2xl bg-amber-600 text-white font-extrabold shadow-sm hover:shadow transition" data-labor-bulk-value="1.5">
            1.5공수 일괄<?php echo $laborGongsuApprovalExempt ? ' · 즉시입력' : ' · 승인요청'; ?>
        </button>
        <button type="button" class="px-4 py-2 rounded-2xl bg-orange-600 text-white font-extrabold shadow-sm hover:shadow transition" data-labor-bulk-value="1.4">
            1.4공수 일괄<?php echo $laborGongsuApprovalExempt ? ' · 즉시입력' : ' · 승인요청'; ?>
        </button>
        <button type="button" class="px-4 py-2 rounded-2xl bg-yellow-600 text-white font-extrabold shadow-sm hover:shadow transition" data-labor-bulk-value="1.3">
            1.3공수 일괄<?php echo $laborGongsuApprovalExempt ? ' · 즉시입력' : ' · 승인요청'; ?>
        </button>
        <button type="button" class="px-4 py-2 rounded-2xl bg-gray-900 text-white font-extrabold shadow-sm hover:shadow transition" data-labor-bulk-value="1">
            1공수일괄
        </button>
        <button type="button" class="px-4 py-2 rounded-2xl border border-gray-200 bg-white text-gray-900 font-extrabold hover:bg-gray-50 transition" data-labor-bulk-value="0.5">
            0.5공수 일괄
        </button>
        <span id="laborBulkSelectedCount" class="px-3 py-2 rounded-2xl bg-blue-50 text-blue-700 text-xs font-extrabold">선택 0명</span>
        <span id="laborBulkStatus" class="text-xs font-bold text-gray-500"></span>
    </div>
    <?php endif; ?>
    <?php
    $projectRow = $projectRow;
    $selectedMonth = $selectedMonth;
    $periodStart = $periodStart;
    $timesheetRows = $timesheetRows;
    $periodEnd = $periodEnd;
    $timesheetWorkers = $timesheetWorkers;
    $attendanceGongsuMap = $attendanceGongsuMap;
    $attendanceGongsuUnit = $attendanceGongsuUnit;
    $attendanceOutputDays = $attendanceOutputDays;
    $attendanceTimeMap = $attendanceTimeMap;
    $showBankColumns = false;    
    $canEdit = $canEditLabor;
    $laborSheetTab = 'timesheet';
    require __DIR__ . '/partials/labor_sheet_table.php';
    ?>
<?php elseif ($laborTab === 'labor'): ?>
    <?php
    // 파일: app/views/construction/tabs/labor.php
    // 노무비가 1% 이상 반영되는 인원만 공수 수정 기능 없이 조회합니다.
    $projectRow = $projectRow;
    $selectedMonth = $selectedMonth;
    $periodStart = $periodStart;
    $timesheetRows = $laborTimesheetRows;
    $periodEnd = $periodEnd;
    $timesheetWorkers = $laborTimesheetWorkers;
    $attendanceGongsuMap = $attendanceGongsuMap;
    $attendanceGongsuUnit = $attendanceGongsuUnit;
    $attendanceOutputDays = $attendanceOutputDays;
    $attendanceTimeMap = $attendanceTimeMap;
    $showBankColumns = false;
    $canEdit = false;
    $laborSheetTab = 'labor';
    require __DIR__ . '/partials/labor_sheet_table.php';
    $canEdit = $canEditLabor;
    ?>
<?php elseif ($laborTab === 'outsourcing'): ?>
    <?php
    // 파일: app/views/construction/tabs/labor.php
    // 외주비가 1% 이상 반영되는 인원만 공수 수정 기능 없이 조회합니다.
    $projectRow = $projectRow;
    $selectedMonth = $selectedMonth;
    $periodStart = $periodStart;
    $timesheetRows = $outsourcingTimesheetRows;
    $periodEnd = $periodEnd;
    $timesheetWorkers = $outsourcingTimesheetWorkers;
    $attendanceGongsuMap = $attendanceGongsuMap;
    $attendanceGongsuUnit = $attendanceGongsuUnit;
    $attendanceOutputDays = $attendanceOutputDays;
    $attendanceTimeMap = $attendanceTimeMap;
    $showBankColumns = false;
    $canEdit = false;
    $laborSheetTab = 'outsourcing';
    require __DIR__ . '/partials/labor_sheet_table.php';
    $canEdit = $canEditLabor;
    ?>
<?php elseif ($laborTab === 'workers'): ?>
    <?php // 공사섹션에서는 권한과 관계없이 계좌번호·은행명·예금주를 표시하지 않습니다. ?>
    <?php $showSensitiveLaborFields = false; ?>
    <div class="bg-white rounded-3xl border border-gray-200 p-6 shadow-sm">
        <h4 class="text-lg font-extrabold text-gray-900">인원 작성</h4>
        <div class="text-sm text-gray-600 mt-1">인력사 업체명, 구분/직종, 비고, 선택 월의 임금단가와 노무비·외주비 비용배분을 수정할 수 있습니다.</div>
        <div class="text-xs text-gray-500 mt-2">* 이름·연락처·주민번호는 관리섹션 인력관리의 등록정보를 사용하며 계좌정보는 공사섹션에 표시하지 않습니다.</div>
        <div class="mt-4 rounded-2xl border border-blue-200 bg-blue-50 p-4">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div class="w-full max-w-xs">
                    <label class="text-sm font-extrabold text-blue-900">월별 보기</label>
                    <select class="mt-2 w-full px-3 py-2 rounded-xl border border-blue-200 bg-white text-sm font-bold"
                            onchange="location.href='?r=공사&pid=<?php echo (int)$pid; ?>&tab=labor&labor_tab=workers&labor_sort=<?php echo h($laborSort); ?>&labor_sort_dir=<?php echo h($laborSortDir); ?>&worker_sort=<?php echo h($workerSort); ?>&worker_sort_dir=<?php echo h($workerSortDir); ?>&month=' + encodeURIComponent(this.value)">
                        <?php foreach ($months as $workerMonthOption): ?>
                            <option value="<?php echo h($workerMonthOption); ?>" <?php echo $workerMonthOption === $selectedMonth ? 'selected' : ''; ?>><?php echo h(isset($monthLabels[$workerMonthOption]) ? $monthLabels[$workerMonthOption] : $workerMonthOption); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="button" class="px-4 py-2 rounded-2xl bg-blue-700 text-white font-extrabold shadow-sm hover:bg-blue-800" data-previous-workers-modal-open>
                    전달인원 가져오기
                </button>
            </div>
            <div class="mt-2 text-xs font-bold text-blue-800"><?php echo h($selectedMonth); ?>에 저장한 비용 배분과 외주비 적용기간은 다른 월에 영향을 주지 않습니다.</div>
        </div>

        <form id="workforceAddForm" method="post" action="<?php echo h(base_url()); ?>/?r=construction/labor_worker_add" style="display:none;">
            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
            <input type="hidden" name="month" value="<?php echo h($selectedMonth); ?>">
            <input type="hidden" name="labor_tab" value="workers">
            <input type="hidden" name="worker_sort" value="<?php echo h($workerSort); ?>">
            <input type="hidden" name="worker_sort_dir" value="<?php echo h($workerSortDir); ?>">
            <input type="hidden" name="workforce_worker_id" id="workforceAddWorkerId" value="">
        </form>

        <div class="mt-4 rounded-2xl border border-emerald-200 bg-emerald-50 p-5">
            <div class="text-sm font-extrabold text-gray-900">직접 인원 추가</div>
            <div class="text-xs text-gray-600 mt-1">관리섹션 인력관리의 이름을 검색합니다. 정확히 한 명이면 Enter로 바로 추가되고, 동명이인은 확인창에서 선택합니다.</div>
            <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                <input id="workforceQuickSearchInput" type="text" autocomplete="off" class="flex-1 px-4 py-3 rounded-2xl border border-emerald-200 bg-white" placeholder="인력관리 이름 검색">
                <button type="button" id="workforceQuickSearchButton" class="px-5 py-3 rounded-2xl bg-emerald-600 text-white font-extrabold">검색</button>
            </div>
            <div id="workforceQuickSearchStatus" class="mt-2 text-xs font-bold text-emerald-800"></div>
        </div>

        <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/labor_worker_add" class="mt-4 rounded-2xl border border-violet-200 bg-violet-50 p-5">
            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
            <input type="hidden" name="month" value="<?php echo h($selectedMonth); ?>">
            <input type="hidden" name="labor_tab" value="workers">
            <input type="hidden" name="worker_sort" value="<?php echo h($workerSort); ?>">
            <input type="hidden" name="worker_sort_dir" value="<?php echo h($workerSortDir); ?>">
            <div class="text-sm font-extrabold text-violet-950">직영팀 추가</div>
            <div class="mt-1 text-xs text-violet-800">재직 중인 직영팀을 선택합니다. 직영팀 단가는 선택 월의 월급 ÷ 전체 현장 출역일수로 자동 계산됩니다.</div>
            <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                <select name="direct_member_id" required class="flex-1 px-4 py-3 rounded-2xl border border-violet-200 bg-white font-bold">
                    <option value="">직영팀 이름 선택</option>
                    <?php foreach ($activeDirectTeamMembers as $directTeamOption): ?>
                        <?php $directTeamOptionId = isset($directTeamOption['id']) ? (int)$directTeamOption['id'] : 0; ?>
                        <option value="<?php echo $directTeamOptionId; ?>"><?php echo h(isset($directTeamOption['name']) ? $directTeamOption['name'] : ''); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="px-5 py-3 rounded-2xl bg-violet-700 text-white font-extrabold disabled:bg-gray-300" <?php echo count($activeDirectTeamMembers) === 0 ? 'disabled' : ''; ?>>직영팀 추가</button>
            </div>
            <?php if (count($activeDirectTeamMembers) === 0): ?><div class="mt-2 text-xs font-bold text-red-700">관리 &gt; 직영팀 명부에 재직자를 먼저 등록해주세요.</div><?php endif; ?>
        </form>

        <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/labor_workers_save" class="mt-4 flex justify-end">
            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
            <input type="hidden" name="month" value="<?php echo h($selectedMonth); ?>">
            <input type="hidden" name="labor_tab" value="workers">
            <input type="hidden" name="worker_sort" value="<?php echo h($workerSort); ?>">
            <input type="hidden" name="worker_sort_dir" value="<?php echo h($workerSortDir); ?>">
            <button type="submit" name="action" value="apply_latest_wage" class="px-4 py-2 rounded-2xl border border-emerald-200 text-emerald-700 font-extrabold" onclick="return confirm('인력관리의 최신 단가를 현재 프로젝트 인원에 적용할까요?');">
                최신 단가 적용
            </button>
        </form>

        <!-- 인원작성 저장 기능 -->
        <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/labor_workers_save" class="mt-4">
            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
            <input type="hidden" name="month" value="<?php echo h($selectedMonth); ?>">
            <input type="hidden" name="labor_tab" value="workers">
            <input type="hidden" name="worker_sort" value="<?php echo h($workerSort); ?>">
            <input type="hidden" name="worker_sort_dir" value="<?php echo h($workerSortDir); ?>">

            <div class="overflow-x-auto">
                <table class="min-w-[1450px] w-full border border-gray-200 text-sm">
                    <thead class="bg-gray-100 text-gray-700">
                    <tr>
                        <th class="border border-gray-200 px-2 py-2"><?php echo cpms_labor_worker_sort_header('company', '인력사 업체명', $workerSort, $workerSortDir, $pid, $selectedMonth, $laborSort, $laborSortDir); ?></th>
                        <th class="border border-gray-200 px-2 py-2"><?php echo cpms_labor_worker_sort_header('name', '성명', $workerSort, $workerSortDir, $pid, $selectedMonth, $laborSort, $laborSortDir); ?></th>
                        <th class="border border-gray-200 px-2 py-2 min-w-[250px]"><?php echo cpms_labor_worker_sort_header('allocation', '비용 배분 (' . $selectedMonth . ')', $workerSort, $workerSortDir, $pid, $selectedMonth, $laborSort, $laborSortDir); ?></th>
                        <th class="border border-gray-200 px-2 py-2"><?php echo cpms_labor_worker_sort_header('phone', '핸드폰 번호', $workerSort, $workerSortDir, $pid, $selectedMonth, $laborSort, $laborSortDir); ?></th>
                        <th class="border border-gray-200 px-2 py-2"><?php echo cpms_labor_worker_sort_header('address', '주소', $workerSort, $workerSortDir, $pid, $selectedMonth, $laborSort, $laborSortDir); ?></th>
                        <th class="border border-gray-200 px-2 py-2"><?php echo cpms_labor_worker_sort_header('job_type', '구분/직종', $workerSort, $workerSortDir, $pid, $selectedMonth, $laborSort, $laborSortDir); ?></th>
                        <th class="border border-gray-200 px-2 py-2"><?php echo cpms_labor_worker_sort_header('wage', '임금단가', $workerSort, $workerSortDir, $pid, $selectedMonth, $laborSort, $laborSortDir); ?></th>
                        <?php if ($showSensitiveLaborFields): ?>
                            <th class="border border-gray-200 px-2 py-2"><?php echo cpms_labor_worker_sort_header('bank_account', '계좌번호', $workerSort, $workerSortDir, $pid, $selectedMonth, $laborSort, $laborSortDir); ?></th>
                            <th class="border border-gray-200 px-2 py-2"><?php echo cpms_labor_worker_sort_header('bank_name', '은행명', $workerSort, $workerSortDir, $pid, $selectedMonth, $laborSort, $laborSortDir); ?></th>
                            <th class="border border-gray-200 px-2 py-2"><?php echo cpms_labor_worker_sort_header('account_holder', '예금주', $workerSort, $workerSortDir, $pid, $selectedMonth, $laborSort, $laborSortDir); ?></th>
                        <?php endif; ?>
                        <th class="border border-gray-200 px-2 py-2"><?php echo cpms_labor_worker_sort_header('remark', '비고', $workerSort, $workerSortDir, $pid, $selectedMonth, $laborSort, $laborSortDir); ?></th>
                        <th class="border border-gray-200 px-2 py-2">삭제</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php $rowIndex = 0; ?>
                    <?php if (!empty($workerRowsForSelectedMonth)): ?>
                        <?php foreach ($workerRowsForSelectedMonth as $row): ?>
                            <?php
                            $member = isset($row['data']) && is_array($row['data']) ? $row['data'] : array();
                            $workerId = isset($row['id']) ? (int)$row['id'] : 0;
                            $companyName = isset($member['company_name']) ? trim((string)$member['company_name']) : '';
                            if ($companyName === '') $companyName = '창명건설';
                            $masterWorkerId = isset($member['worker_id']) ? (int)$member['worker_id'] : 0;
                            $jobTypeSnapshot = isset($member['job_type_snapshot']) ? trim((string)$member['job_type_snapshot']) : '';
                            $remark = isset($member['remark']) ? trim((string)$member['remark']) : '';
                            $sourceType = isset($member['source_type']) ? trim((string)$member['source_type']) : 'manual';
                            $matchedStatus = isset($member['matched_status']) ? trim((string)$member['matched_status']) : 'manual';
                            $isDirectSalary = isset($member['salary_allocation_mode']) && (int)$member['salary_allocation_mode'] === 1;
                            $directSalaryRate = $isDirectSalary && isset($member['salary_daily_rate']) ? (float)$member['salary_daily_rate'] : 0.0;
                            $directSalaryDays = $isDirectSalary && isset($member['salary_total_output_days']) ? (int)$member['salary_total_output_days'] : 0;
                            $isMonthAssigned = (isset($member['month_assigned']) && (int)$member['month_assigned'] === 1);
                            $outsourcingRatio = function_exists('cpms_resolve_worker_outsourcing_ratio') ? cpms_resolve_worker_outsourcing_ratio($member) : ((isset($member['is_outsourcing']) && (int)$member['is_outsourcing'] === 1) ? 100 : 0);
                            if ($isDirectSalary) $outsourcingRatio = 0;
                            $laborRatio = 100 - $outsourcingRatio;
                            $allocationPreset = in_array($outsourcingRatio, array(0, 30, 40, 50, 100), true) ? (string)$outsourcingRatio : 'custom';
                            $outsourcingStartDate = isset($member['outsourcing_start_date']) ? trim((string)$member['outsourcing_start_date']) : '';
                            $outsourcingEndDate = isset($member['outsourcing_end_date']) ? trim((string)$member['outsourcing_end_date']) : '';
                            ?>
                            <tr class="<?php echo ($rowIndex % 2 === 0) ? 'bg-white' : 'bg-gray-50'; ?>">
                                <td class="border border-gray-200 px-2 py-2">
                                    <input name="workers[<?php echo $workerId; ?>][company_name]" class="w-full px-2 py-1 border border-gray-200 rounded-lg" type="text" value="<?php echo h($companyName); ?>" placeholder="인력사 업체명">
                                </td>
                                <td class="border border-gray-200 px-2 py-2">
                                    <div class="font-extrabold text-gray-900"><?php echo h(isset($member['name']) ? $member['name'] : ''); ?></div>
                                </td>
                                <td class="border border-gray-200 px-2 py-2" data-labor-allocation>
                                    <input type="hidden" name="workers[<?php echo $workerId; ?>][outsourcing_ratio]" value="<?php echo (int)$outsourcingRatio; ?>" data-allocation-ratio>
                                    <select class="w-full px-2 py-2 rounded-xl border border-gray-200 bg-white text-sm font-bold" data-allocation-preset <?php echo $isDirectSalary ? 'disabled' : ''; ?>>
                                        <option value="0" <?php echo $allocationPreset === '0' ? 'selected' : ''; ?>>전액 노무비</option>
                                        <option value="30" <?php echo $allocationPreset === '30' ? 'selected' : ''; ?>>노무비 70% / 외주비 30%</option>
                                        <option value="40" <?php echo $allocationPreset === '40' ? 'selected' : ''; ?>>노무비 60% / 외주비 40%</option>
                                        <option value="50" <?php echo $allocationPreset === '50' ? 'selected' : ''; ?>>노무비 50% / 외주비 50%</option>
                                        <option value="100" <?php echo $allocationPreset === '100' ? 'selected' : ''; ?>>전액 외주비</option>
                                        <option value="custom" <?php echo $allocationPreset === 'custom' ? 'selected' : ''; ?>>직접 입력</option>
                                    </select>
                                    <div class="mt-2 <?php echo $allocationPreset === 'custom' ? '' : 'hidden'; ?>" data-allocation-custom>
                                        <label class="text-xs font-bold text-gray-600">외주비 비율</label>
                                        <div class="mt-1 flex items-center gap-2">
                                            <input type="number" min="0" max="100" step="1" value="<?php echo (int)$outsourcingRatio; ?>" class="w-24 px-2 py-1 rounded-lg border border-gray-300 text-right font-bold" data-allocation-custom-input>
                                            <span class="font-bold text-gray-600">%</span>
                                        </div>
                                    </div>
                                    <div class="mt-2 rounded-lg bg-gray-50 px-2 py-1 text-xs font-extrabold text-gray-700" data-allocation-summary>
                                        <?php echo $isDirectSalary ? '직영팀 월급제 · 전액 노무비' : '노무비 ' . (int)$laborRatio . '% + 외주비 ' . (int)$outsourcingRatio . '% = 100%'; ?>
                                    </div>
                                    <div class="mt-2 rounded-xl border border-blue-100 bg-blue-50 p-2">
                                        <div class="text-xs font-extrabold text-blue-900">외주비 적용기간</div>
                                        <div class="mt-1 grid grid-cols-2 gap-2">
                                            <label class="text-[11px] font-bold text-gray-600">시작일
                                                <input type="date" name="workers[<?php echo $workerId; ?>][outsourcing_start_date]" min="<?php echo h($periodStart); ?>" max="<?php echo h($periodEnd); ?>" value="<?php echo h($outsourcingStartDate); ?>" class="mt-1 w-full rounded-lg border border-blue-200 bg-white px-2 py-1 text-xs" data-allocation-start-date <?php echo $isDirectSalary ? 'disabled' : ''; ?>>
                                            </label>
                                            <label class="text-[11px] font-bold text-gray-600">종료일
                                                <input type="date" name="workers[<?php echo $workerId; ?>][outsourcing_end_date]" min="<?php echo h($periodStart); ?>" max="<?php echo h($periodEnd); ?>" value="<?php echo h($outsourcingEndDate); ?>" class="mt-1 w-full rounded-lg border border-blue-200 bg-white px-2 py-1 text-xs" data-allocation-end-date <?php echo $isDirectSalary ? 'disabled' : ''; ?>>
                                            </label>
                                        </div>
                                        <div class="mt-1 text-[11px] text-blue-800">비워두면 선택 월 전체에 비율을 적용합니다.</div>
                                    </div>
                                </td>
                                <td class="border border-gray-200 px-2 py-2">
                                    <?php echo h(isset($member['phone']) && trim((string)$member['phone']) !== '' ? $member['phone'] : '-'); ?>
                                </td>
                                <td class="border border-gray-200 px-2 py-2">
                                    <?php echo h(isset($member['address']) && trim((string)$member['address']) !== '' ? $member['address'] : '-'); ?>
                                </td>
                                <td class="border border-gray-200 px-2 py-2">
                                    <input name="workers[<?php echo $workerId; ?>][job_type]" class="w-full px-2 py-1 border border-gray-200 rounded-lg" type="text" maxlength="100" value="<?php echo h($jobTypeSnapshot); ?>" placeholder="구분/직종">
                                </td>
                                <td class="border border-gray-200 px-2 py-2">
                                    <?php if ($isDirectSalary): ?>
                                        <input type="hidden" name="workers[<?php echo $workerId; ?>][deposit_rate]" value="<?php echo h((string)(int)round($directSalaryRate)); ?>">
                                        <div class="rounded-lg border border-violet-200 bg-violet-50 px-2 py-2 text-right font-extrabold text-violet-900"><?php echo number_format($directSalaryRate); ?>원</div>
                                        <div class="mt-1 text-[11px] font-bold text-violet-700">월급 <?php echo number_format(isset($member['monthly_salary']) ? (int)$member['monthly_salary'] : 0); ?>원 ÷ 전체 <?php echo number_format($directSalaryDays); ?>일</div>
                                    <?php else: ?>
                                        <input name="workers[<?php echo $workerId; ?>][deposit_rate]" class="w-full px-2 py-1 border border-gray-200 rounded-lg" type="text" value="<?php echo h(isset($member['deposit_rate']) ? $member['deposit_rate'] : '0'); ?>" placeholder="임금단가">
                                    <?php endif; ?>
                                </td>
                                <?php if ($showSensitiveLaborFields): ?>
                                    <td class="border border-gray-200 px-2 py-2">
                                        <?php echo h(isset($member['bank_account']) && trim((string)$member['bank_account']) !== '' ? CryptoHelper::maskBankAccount($member['bank_account']) : '-'); ?>
                                    </td>
                                    <td class="border border-gray-200 px-2 py-2">
                                        <?php echo h(isset($member['bank_name']) && trim((string)$member['bank_name']) !== '' ? $member['bank_name'] : '-'); ?>
                                    </td>
                                    <td class="border border-gray-200 px-2 py-2">
                                        <?php echo h(isset($member['account_holder']) && trim((string)$member['account_holder']) !== '' ? $member['account_holder'] : '-'); ?>
                                    </td>
                                <?php endif; ?>
                                <td class="border border-gray-200 px-2 py-2">
                                    <input name="workers[<?php echo $workerId; ?>][remark]" class="w-full px-2 py-1 border border-gray-200 rounded-lg" type="text" maxlength="255" value="<?php echo h($remark); ?>" placeholder="비고">
                                </td>
                                <td class="border border-gray-200 px-2 py-2 text-center">
                                    <?php if ($workerId > 0): ?>
                                        <button type="submit"
                                                name="action"
                                                value="delete"
                                                formaction="<?php echo h(base_url()); ?>/?r=construction/labor_workers_save"
                                                class="px-2 py-1 rounded-lg border border-red-200 text-red-600 hover:bg-red-50 text-xs font-bold"
                                                onclick="document.getElementById('delete_worker_id').value='<?php echo $workerId; ?>'; return confirm('해당 인원을 삭제할까요?');">
                                            삭제
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php $rowIndex++; ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr class="bg-white">
                            <td colspan="<?php echo $showSensitiveLaborFields ? 12 : 9; ?>" class="border border-gray-200 px-2 py-6 text-center text-gray-500">등록된 인원이 없습니다.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <input type="hidden" id="delete_worker_id" name="delete_worker_id" value="">
            <div class="mt-4 flex justify-end">
                <button type="submit" name="action" value="save" class="px-4 py-2 rounded-2xl bg-gray-900 text-white font-extrabold">
                    저장
                </button>
            </div>
        </form>    

        <div id="previousWorkersImportModal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
            <div class="absolute inset-0 bg-black/50" data-previous-workers-modal-close></div>
            <div class="absolute inset-0 flex items-center justify-center p-4">
                <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/labor_workers_save" class="relative flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-3xl border border-gray-100 bg-white shadow-2xl" id="previousWorkersImportForm">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
                    <input type="hidden" name="month" value="<?php echo h($selectedMonth); ?>">
                    <input type="hidden" name="labor_tab" value="workers">
                    <input type="hidden" name="worker_sort" value="<?php echo h($workerSort); ?>">
                    <input type="hidden" name="worker_sort_dir" value="<?php echo h($workerSortDir); ?>">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 p-5">
                        <div>
                            <div class="text-lg font-extrabold text-gray-900">전달인원 가져오기</div>
                            <div class="mt-1 text-xs text-gray-500"><?php echo h($previousLaborMonth); ?>에 등록된 인원을 선택해 <?php echo h($selectedMonth); ?>로 가져옵니다.</div>
                        </div>
                        <button type="button" class="rounded-xl border border-gray-200 px-3 py-2 font-bold text-gray-700" data-previous-workers-modal-close>닫기</button>
                    </div>
                    <div class="flex-1 overflow-auto p-5">
                        <div class="mb-3 rounded-xl border border-blue-100 bg-blue-50 px-3 py-2 text-xs font-bold text-blue-900">가져온 인원은 업체명·성명·핸드폰 번호·임금단가를 그대로 사용하며, 비용배분은 전액 노무비로 시작합니다.</div>
                        <table class="min-w-[760px] w-full border border-gray-200 text-sm">
                            <thead class="bg-gray-100 text-gray-700">
                                <tr>
                                    <th class="w-12 border border-gray-200 px-3 py-2 text-center"><input type="checkbox" data-previous-workers-select-all aria-label="전체 선택"></th>
                                    <th class="border border-gray-200 px-3 py-2">인력사 업체명</th>
                                    <th class="border border-gray-200 px-3 py-2">성명</th>
                                    <th class="border border-gray-200 px-3 py-2">핸드폰 번호</th>
                                    <th class="border border-gray-200 px-3 py-2 text-right">임금 단가</th>
                                    <th class="border border-gray-200 px-3 py-2 text-center">상태</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (count($previousLaborWorkers) > 0): ?>
                                <?php foreach ($previousLaborWorkers as $previousLaborWorker): ?>
                                    <?php
                                    $previousWorkerId = isset($previousLaborWorker['id']) ? (int)$previousLaborWorker['id'] : 0;
                                    $previousWorkerCompany = isset($previousLaborWorker['agency_name_snapshot']) ? trim((string)$previousLaborWorker['agency_name_snapshot']) : '';
                                    if ($previousWorkerCompany === '' && isset($previousLaborWorker['company_name'])) $previousWorkerCompany = trim((string)$previousLaborWorker['company_name']);
                                    if ($previousWorkerCompany === '') $previousWorkerCompany = '창명건설';
                                    $previousWorkerName = isset($previousLaborWorker['worker_name_snapshot']) ? trim((string)$previousLaborWorker['worker_name_snapshot']) : '';
                                    if ($previousWorkerName === '' && isset($previousLaborWorker['name'])) $previousWorkerName = trim((string)$previousLaborWorker['name']);
                                    $previousWorkerPhone = isset($previousLaborWorker['phone']) ? trim((string)$previousLaborWorker['phone']) : '';
                                    $previousWorkerWage = isset($previousLaborWorker['daily_wage_snapshot']) ? (int)$previousLaborWorker['daily_wage_snapshot'] : 0;
                                    if ($previousWorkerWage <= 0 && isset($previousLaborWorker['deposit_rate'])) $previousWorkerWage = (int)$previousLaborWorker['deposit_rate'];
                                    $previousWorkerAlreadyCurrent = isset($laborWorkerMonthMap[$previousWorkerId]);
                                    $previousDirectMemberId = isset($previousLaborWorker['direct_member_id']) ? (int)$previousLaborWorker['direct_member_id'] : 0;
                                    $previousDirectMemberRetired = $previousDirectMemberId > 0 && (!isset($directTeamActiveMap[$previousDirectMemberId]) || !$directTeamActiveMap[$previousDirectMemberId]);
                                    $previousWorkerDisabled = $previousWorkerAlreadyCurrent || $previousDirectMemberRetired;
                                    ?>
                                    <tr class="<?php echo $previousWorkerDisabled ? 'bg-gray-50 text-gray-400' : 'bg-white'; ?>">
                                        <td class="border border-gray-200 px-3 py-2 text-center"><input type="checkbox" name="previous_worker_ids[]" value="<?php echo $previousWorkerId; ?>" data-previous-worker-check <?php echo $previousWorkerDisabled ? 'disabled' : ''; ?> aria-label="<?php echo h($previousWorkerName); ?> 선택"></td>
                                        <td class="border border-gray-200 px-3 py-2 font-bold"><?php echo h($previousWorkerCompany); ?></td>
                                        <td class="border border-gray-200 px-3 py-2 font-extrabold"><?php echo h($previousWorkerName); ?></td>
                                        <td class="border border-gray-200 px-3 py-2"><?php echo h($previousWorkerPhone === '' ? '-' : $previousWorkerPhone); ?></td>
                                        <td class="border border-gray-200 px-3 py-2 text-right font-bold"><?php echo h(number_format($previousWorkerWage)); ?>원</td>
                                        <td class="border border-gray-200 px-3 py-2 text-center"><?php if ($previousDirectMemberRetired): ?><span class="rounded-full bg-red-100 px-2 py-1 text-xs font-bold text-red-700">퇴직 · 추가 불가</span><?php elseif ($previousWorkerAlreadyCurrent): ?><span class="rounded-full bg-gray-200 px-2 py-1 text-xs font-bold text-gray-600">이미 등록</span><?php else: ?><span class="text-xs font-bold text-blue-700">가져오기 가능</span><?php endif; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="6" class="border border-gray-200 px-3 py-10 text-center text-gray-500"><?php echo h($previousLaborMonth); ?>에 등록된 전달 인원이 없습니다.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 bg-gray-50 p-5">
                        <div class="text-sm font-extrabold text-gray-700">선택 <span class="text-blue-700" data-previous-workers-selected-count>0</span>명</div>
                        <div class="flex gap-2">
                            <button type="button" class="rounded-xl border border-gray-300 bg-white px-4 py-2 font-bold" data-previous-workers-modal-close>취소</button>
                            <button type="submit" name="action" value="import_previous" class="rounded-xl bg-blue-700 px-5 py-2 font-extrabold text-white disabled:cursor-not-allowed disabled:bg-gray-300" data-previous-workers-import-submit disabled>선택 인원 가져오기</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div id="workforceSearchModal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
            <div class="absolute inset-0 bg-black/40" data-workforce-modal-close></div>
            <div class="absolute inset-0 flex items-center justify-center p-4">
                <div class="w-full max-w-2xl bg-white rounded-3xl shadow-2xl border border-gray-100 overflow-hidden">
                    <div class="p-5 border-b border-gray-100 flex items-center justify-between">
                        <div>
                            <div class="text-lg font-extrabold text-gray-900">동명이인 선택</div>
                            <div class="text-xs text-gray-500 mt-1">연락처와 주민번호 앞자리를 확인한 뒤 추가할 인원을 선택하세요.</div>
                        </div>
                        <button type="button" class="px-3 py-2 rounded-xl border border-gray-200" data-workforce-modal-close>닫기</button>
                    </div>
                    <div class="p-5">
                        <div id="workforceSearchResults" class="space-y-2 text-sm"></div>
                        <div class="mt-5 flex justify-end gap-2">
                            <button type="button" class="px-4 py-2 rounded-xl border border-gray-200 font-bold" data-workforce-modal-close>취소</button>
                            <button type="button" id="workforceDuplicateAddButton" class="px-5 py-2 rounded-xl bg-emerald-600 text-white font-extrabold">인원 추가</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php $laborPersonnelJsPath = dirname(dirname(dirname(dirname(__DIR__)))) . '/public/assets/js/labor_personnel.js'; ?>
        <script defer src="<?php echo h(asset_url('assets/js/labor_personnel.js') . '?v=' . (string)@filemtime($laborPersonnelJsPath)); ?>"></script>
        <script>
        // 파일: app/views/construction/tabs/labor.php
        // 현장 담당자가 외주비 비율만 선택하면 노무비 비율과 합계가 즉시 보이도록 합니다.
        (function(){
            var previousModal = document.getElementById('previousWorkersImportModal');
            var previousForm = document.getElementById('previousWorkersImportForm');
            var previousOpenButtons = document.querySelectorAll('[data-previous-workers-modal-open]');
            var previousCloseButtons = document.querySelectorAll('[data-previous-workers-modal-close]');
            var previousSelectAll = previousModal ? previousModal.querySelector('[data-previous-workers-select-all]') : null;
            var previousImportButton = previousModal ? previousModal.querySelector('[data-previous-workers-import-submit]') : null;
            var previousSelectedCount = previousModal ? previousModal.querySelector('[data-previous-workers-selected-count]') : null;
            function previousEnabledChecks() {
                return previousModal ? previousModal.querySelectorAll('[data-previous-worker-check]:not(:disabled)') : [];
            }
            function updatePreviousSelection() {
                var checks = previousEnabledChecks();
                var checked = 0;
                for (var previousIndex = 0; previousIndex < checks.length; previousIndex++) if (checks[previousIndex].checked) checked++;
                if (previousSelectedCount) previousSelectedCount.textContent = String(checked);
                if (previousImportButton) previousImportButton.disabled = checked === 0;
                if (previousSelectAll) {
                    previousSelectAll.disabled = checks.length === 0;
                    previousSelectAll.checked = checks.length > 0 && checked === checks.length;
                    previousSelectAll.indeterminate = checked > 0 && checked < checks.length;
                }
            }
            function openPreviousModal() {
                if (!previousModal) return;
                previousModal.classList.remove('hidden');
                previousModal.setAttribute('aria-hidden', 'false');
                document.body.classList.add('overflow-hidden');
                updatePreviousSelection();
            }
            function closePreviousModal() {
                if (!previousModal) return;
                previousModal.classList.add('hidden');
                previousModal.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('overflow-hidden');
            }
            for (var previousOpenIndex = 0; previousOpenIndex < previousOpenButtons.length; previousOpenIndex++) previousOpenButtons[previousOpenIndex].addEventListener('click', openPreviousModal);
            for (var previousCloseIndex = 0; previousCloseIndex < previousCloseButtons.length; previousCloseIndex++) previousCloseButtons[previousCloseIndex].addEventListener('click', closePreviousModal);
            if (previousSelectAll) previousSelectAll.addEventListener('change', function(){var checks=previousEnabledChecks();for(var checkIndex=0;checkIndex<checks.length;checkIndex++)checks[checkIndex].checked=previousSelectAll.checked;updatePreviousSelection();});
            if (previousModal) previousModal.addEventListener('change', function(e){if(e.target&&e.target.hasAttribute('data-previous-worker-check'))updatePreviousSelection();});
            if (previousForm) previousForm.addEventListener('submit', function(e){var checks=previousEnabledChecks(),checked=0;for(var checkIndex=0;checkIndex<checks.length;checkIndex++)if(checks[checkIndex].checked)checked++;if(checked===0){e.preventDefault();alert('전달에서 가져올 인원을 선택해주세요.');}});
            document.addEventListener('keydown', function(e){if(e.key==='Escape'&&previousModal&&!previousModal.classList.contains('hidden'))closePreviousModal();});
            updatePreviousSelection();

            function clampRatio(value) {
                var ratio = parseInt(value, 10);
                if (isNaN(ratio)) ratio = 0;
                if (ratio < 0) ratio = 0;
                if (ratio > 100) ratio = 100;
                return ratio;
            }
            function updateAllocation(box, ratio, keepPreset) {
                if (!box) return;
                ratio = clampRatio(ratio);
                var hidden = box.querySelector('[data-allocation-ratio]');
                var preset = box.querySelector('[data-allocation-preset]');
                var customBox = box.querySelector('[data-allocation-custom]');
                var customInput = box.querySelector('[data-allocation-custom-input]');
                var summary = box.querySelector('[data-allocation-summary]');
                if (hidden) hidden.value = String(ratio);
                if (customInput) customInput.value = String(ratio);
                if (summary) summary.textContent = '노무비 ' + String(100 - ratio) + '% + 외주비 ' + String(ratio) + '% = 100%';
                if (!keepPreset && preset) {
                    var fixed = ratio === 0 || ratio === 30 || ratio === 40 || ratio === 50 || ratio === 100;
                    preset.value = fixed ? String(ratio) : 'custom';
                }
                if (customBox && preset) {
                    if (preset.value === 'custom') customBox.classList.remove('hidden');
                    else customBox.classList.add('hidden');
                }
            }
            var boxes = document.querySelectorAll('[data-labor-allocation]');
            for (var i = 0; i < boxes.length; i++) {
                (function(box){
                    var preset = box.querySelector('[data-allocation-preset]');
                    var customInput = box.querySelector('[data-allocation-custom-input]');
                    if (preset) {
                        preset.addEventListener('change', function(){
                            if (preset.value === 'custom') {
                                updateAllocation(box, customInput ? customInput.value : 0, true);
                                if (customInput) customInput.focus();
                            } else {
                                updateAllocation(box, preset.value, true);
                            }
                        });
                    }
                    if (customInput) {
                        customInput.addEventListener('input', function(){ updateAllocation(box, customInput.value, true); });
                        customInput.addEventListener('change', function(){ updateAllocation(box, customInput.value, true); });
                    }
                })(boxes[i]);
            }
        })();
        </script>
    </div>
<?php else: ?>
    <div class="bg-white rounded-3xl border border-gray-200 p-6 shadow-sm">
        <div>
            <h4 class="text-lg font-extrabold text-gray-900">인원 등록</h4>
            <div class="mt-1 text-sm text-gray-600">등록한 인원은 관리섹션 인력관리에도 즉시 저장되며 모든 현장에서 이름으로 검색할 수 있습니다.</div>
        </div>
        <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/labor_workforce_save" class="mt-5 space-y-5" data-construction-workforce-register>
            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
            <input type="hidden" name="month" value="<?php echo h($selectedMonth); ?>">
            <label class="flex cursor-pointer items-start gap-3 rounded-2xl border border-blue-200 bg-blue-50 px-4 py-3">
                <input type="checkbox" name="is_outsourcing" value="1" class="mt-1 h-5 w-5 rounded border-blue-300 text-blue-600" data-outsourcing-worker>
                <span>
                    <span class="block text-sm font-extrabold text-blue-900">외주비인원</span>
                    <span class="mt-1 block text-xs text-blue-700">체크하면 임금단가, 주민번호, 은행명, 계좌번호, 예금주는 입력하지 않아도 됩니다.</span>
                </span>
            </label>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <label><span class="block text-sm font-bold text-gray-700 mb-1">이름</span><input name="name" required maxlength="100" class="w-full px-4 py-3 rounded-2xl border border-gray-200"></label>
                <label><span class="block text-sm font-bold text-gray-700 mb-1">연락처</span><input name="phone" <?php echo $laborRelaxedRegistration ? '' : 'required'; ?> maxlength="50" class="w-full px-4 py-3 rounded-2xl border border-gray-200" placeholder="010-0000-0000"></label>
                <label data-outsourcing-optional-label><span class="block text-sm font-bold text-gray-700 mb-1">주민번호</span><input name="resident_no" <?php echo $laborRelaxedRegistration ? '' : 'required'; ?> maxlength="14" class="w-full px-4 py-3 rounded-2xl border border-gray-200" placeholder="000000-0000000" data-outsourcing-optional-field data-default-required="<?php echo $laborRelaxedRegistration ? '0' : '1'; ?>"></label>
                <label><span class="block text-sm font-bold text-gray-700 mb-1">구분/직종</span><input name="job_type" <?php echo $laborRelaxedRegistration ? '' : 'required'; ?> maxlength="100" class="w-full px-4 py-3 rounded-2xl border border-gray-200"></label>
                <label><span class="block text-sm font-bold text-gray-700 mb-1">인력사 업체명</span><input name="agency_name" <?php echo $laborRelaxedRegistration ? '' : 'required'; ?> maxlength="100" class="w-full px-4 py-3 rounded-2xl border border-gray-200"></label>
                <label data-outsourcing-optional-label><span class="block text-sm font-bold text-gray-700 mb-1">임금단가</span><input name="daily_wage" <?php echo $laborRelaxedRegistration ? '' : 'required'; ?> type="number" min="<?php echo $laborRelaxedRegistration ? '0' : '1'; ?>" step="1" class="w-full px-4 py-3 rounded-2xl border border-gray-200" data-outsourcing-optional-field data-default-required="<?php echo $laborRelaxedRegistration ? '0' : '1'; ?>" data-default-min="<?php echo $laborRelaxedRegistration ? '0' : '1'; ?>"></label>
                <label data-outsourcing-optional-label><span class="block text-sm font-bold text-gray-700 mb-1">은행명</span><input name="bank_name" <?php echo $laborRelaxedRegistration ? '' : 'required'; ?> maxlength="100" class="w-full px-4 py-3 rounded-2xl border border-gray-200" data-outsourcing-optional-field data-default-required="<?php echo $laborRelaxedRegistration ? '0' : '1'; ?>"></label>
                <label data-outsourcing-optional-label><span class="block text-sm font-bold text-gray-700 mb-1">계좌번호</span><input name="bank_account" <?php echo $laborRelaxedRegistration ? '' : 'required'; ?> maxlength="100" class="w-full px-4 py-3 rounded-2xl border border-gray-200" data-outsourcing-optional-field data-default-required="<?php echo $laborRelaxedRegistration ? '0' : '1'; ?>"></label>
                <label data-outsourcing-optional-label><span class="block text-sm font-bold text-gray-700 mb-1">예금주</span><input name="account_holder" <?php echo $laborRelaxedRegistration ? '' : 'required'; ?> maxlength="100" class="w-full px-4 py-3 rounded-2xl border border-gray-200" data-outsourcing-optional-field data-default-required="<?php echo $laborRelaxedRegistration ? '0' : '1'; ?>"></label>
            </div>
            <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-bold text-amber-800">
                <span data-outsourcing-default-hint><?php echo $laborRelaxedRegistration ? '개발부서는 이름만 입력해도 임시 등록할 수 있으며 나머지 정보는 인력관리에서 나중에 보완할 수 있습니다.' : '일반 인원은 위 9개 항목을 모두 입력해야 등록할 수 있습니다.'; ?></span>
                <span class="hidden" data-outsourcing-checked-hint>외주비인원은 이름, 연락처, 구분/직종, 인력사 업체명만 입력하면 등록할 수 있습니다.</span>
            </div>
            <div class="flex justify-end">
                <button type="submit" class="px-6 py-3 rounded-2xl bg-emerald-600 text-white font-extrabold">인력 등록</button>
            </div>
        </form>
        <script>
        (function(){
            var form = document.querySelector('[data-construction-workforce-register]');
            if (!form) return;
            var checkbox = form.querySelector('[data-outsourcing-worker]');
            var fields = form.querySelectorAll('[data-outsourcing-optional-field]');
            var defaultHint = form.querySelector('[data-outsourcing-default-hint]');
            var checkedHint = form.querySelector('[data-outsourcing-checked-hint]');
            if (!checkbox) return;

            function syncOutsourcingFields() {
                var isOutsourcing = checkbox.checked;
                var i;
                for (i = 0; i < fields.length; i++) {
                    fields[i].required = !isOutsourcing && fields[i].getAttribute('data-default-required') === '1';
                    fields[i].setAttribute('aria-required', fields[i].required ? 'true' : 'false');
                    if (fields[i].getAttribute('data-default-min') !== null) {
                        fields[i].min = isOutsourcing ? '0' : fields[i].getAttribute('data-default-min');
                    }
                    if (fields[i].parentNode) fields[i].parentNode.classList.toggle('opacity-60', isOutsourcing);
                }
                if (defaultHint) defaultHint.classList.toggle('hidden', isOutsourcing);
                if (checkedHint) checkedHint.classList.toggle('hidden', !isOutsourcing);
            }

            checkbox.addEventListener('change', syncOutsourcingFields);
            syncOutsourcingFields();
        })();
        </script>
    </div>
<?php endif; ?>


<div id="modal-gongsuAddConfirm" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/40" data-modal-close="gongsuAddConfirm"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
        <div class="w-full max-w-sm bg-white rounded-3xl shadow-2xl border border-gray-100 overflow-hidden">
            <div class="p-6">
                <div class="text-lg font-extrabold text-gray-900">공수를 추가할까요?</div>
                <div class="mt-2 text-sm text-gray-600">빈칸에 공수 1을 자동으로 입력합니다.</div>
                <div class="mt-5 flex items-center justify-end gap-2">
                    <button type="button" class="px-4 py-2 rounded-2xl border border-gray-200 text-gray-700 font-extrabold" data-modal-close="gongsuAddConfirm">아니요</button>
                    <button type="button" class="px-5 py-2 rounded-2xl bg-gray-900 text-white font-extrabold" id="gongsuAddConfirmYes">예</button>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="modal-laborBulkRequest" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/40" data-labor-bulk-request-close></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
        <div class="w-full max-w-2xl max-h-[88vh] overflow-hidden bg-white rounded-3xl shadow-2xl border border-gray-100">
            <div class="p-6 border-b border-gray-100 flex shrink-0 items-center justify-between">
                <div>
                    <h3 class="text-xl font-extrabold text-gray-900"><?php echo $laborGongsuApprovalExempt ? '일괄 공수 입력' : '일괄 공수 승인 요청'; ?></h3>
                    <div class="text-xs text-gray-500 mt-1"><?php echo $laborGongsuApprovalExempt ? '개발부서는 승인 없이 공수가 바로 적용됩니다.' : '공수 적용일자, 대상 인원, 요청 공수와 신청 사유를 확인합니다.'; ?></div>
                </div>
                <button type="button" class="px-3 py-2 rounded-xl border border-gray-200 font-bold" data-labor-bulk-request-close>닫기</button>
            </div>
            <div class="p-6 space-y-4 overflow-y-auto max-h-[70vh]">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div class="rounded-2xl border border-blue-200 bg-blue-50 p-4">
                        <label class="text-xs font-bold text-blue-700" for="laborBulkRequestDate">공수 적용일자</label>
                        <input type="date"
                               id="laborBulkRequestDate"
                               min="<?php echo h($periodStart); ?>"
                               max="<?php echo h($periodEnd); ?>"
                               class="mt-2 w-full rounded-xl border border-blue-200 bg-white px-3 py-2 text-sm font-extrabold text-blue-900"
                               required>
                    </div>
                    <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4">
                        <div class="text-xs font-bold text-gray-500">요청 공수</div>
                        <div class="mt-1 text-xl font-extrabold text-gray-900" id="laborBulkRequestValue">-</div>
                    </div>
                    <div class="rounded-2xl border border-violet-200 bg-violet-50 p-4">
                        <div class="text-xs font-bold text-violet-700">요청 범위</div>
                        <div class="mt-1 text-lg font-extrabold text-violet-900" id="laborBulkRequestScope">-</div>
                    </div>
                </div>
                <div>
                    <div class="text-xs font-bold text-gray-500">요청 인원</div>
                    <div id="laborBulkRequestNames" class="mt-2 max-h-44 overflow-y-auto rounded-2xl border border-gray-200 bg-gray-50 p-3 text-sm text-gray-700"></div>
                </div>
                <div>
                    <label class="text-xs font-bold text-gray-500" for="laborBulkRequestReason"><?php echo $laborGongsuApprovalExempt ? '변경 사유(선택)' : '신청 사유'; ?></label>
                    <textarea id="laborBulkRequestReason" rows="4" maxlength="255" class="mt-1 w-full px-4 py-3 rounded-2xl border border-gray-200" placeholder="예: 점심시간 없이 연장 근무" <?php echo $laborGongsuApprovalExempt ? '' : 'required'; ?>></textarea>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" class="px-4 py-2 rounded-2xl border border-gray-200 font-extrabold" data-labor-bulk-request-close>취소</button>
                    <button type="button" id="laborBulkRequestSubmit" class="px-5 py-2 rounded-2xl bg-gray-900 text-white font-extrabold"><?php echo $laborGongsuApprovalExempt ? '바로 적용' : '승인 요청'; ?></button>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="modal-gongsuRequest" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/40" data-modal-close="gongsuRequest"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
        <div class="w-full max-w-xl max-h-[calc(100vh-2rem)] bg-white rounded-3xl shadow-2xl border border-gray-100 overflow-hidden flex flex-col">
            <div class="p-6 border-b border-gray-100 flex items-center justify-between">
                <div>
                    <h3 class="text-xl font-extrabold text-gray-900">공수 수정</h3>
                    <div class="text-xs text-gray-500 mt-1">공수는 0.1 단위 입력을 권장합니다.</div>
                </div>
                <button type="button" class="p-3 rounded-2xl hover:bg-gray-50" data-modal-close="gongsuRequest">닫기</button>
            </div>
            <div class="min-h-0 overflow-y-auto p-6 space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                    <div>
                        <label class="text-xs font-bold text-gray-500">현장명</label>
                        <div class="mt-1 px-4 py-3 rounded-2xl border border-gray-200 bg-gray-50 text-gray-700" id="gongsuProjectName"></div>
                    </div>
                    <div>
                        <label class="text-xs font-bold text-gray-500">작업자명</label>
                        <div class="mt-1 px-4 py-3 rounded-2xl border border-gray-200 bg-gray-50 text-gray-700" id="gongsuWorkerName"></div>
                    </div>
                    <div>
                        <label class="text-xs font-bold text-gray-500">작업일자</label>
                        <div class="mt-1 px-4 py-3 rounded-2xl border border-gray-200 bg-gray-50 text-gray-700" id="gongsuWorkerDate"></div>
                    </div>
                    <div>
                        <label class="text-xs font-bold text-gray-500">출근시간</label>
                        <div class="mt-1 px-4 py-3 rounded-2xl border border-gray-200 bg-gray-50 text-gray-700" id="gongsuStartTime">-</div>
                    </div>
                    <div>
                        <label class="text-xs font-bold text-gray-500">퇴근시간</label>
                        <div class="mt-1 px-4 py-3 rounded-2xl border border-gray-200 bg-gray-50 text-gray-700" id="gongsuEndTime">-</div>
                    </div>
                    <div>
                        <label class="text-xs font-bold text-gray-500">기존 공수</label>
                        <div class="mt-1 px-4 py-3 rounded-2xl border border-gray-200 bg-gray-50 text-gray-700" id="gongsuCurrentValue"></div>
                    </div>
                </div>
                <div>
                    <label class="text-xs font-bold text-gray-500">변경 공수 입력</label>
                    <input id="gongsuRequestedValue" type="number" min="0" step="0.1" class="mt-1 px-4 py-3 rounded-2xl border border-gray-200 w-full" placeholder="예: 1.3">
                </div>
                <div>
                    <label class="text-xs font-bold text-gray-500"><?php echo $laborGongsuApprovalExempt ? '변경 사유 입력(선택)' : '요청 사유 입력'; ?></label>
                    <textarea id="gongsuRequestReason" class="mt-1 px-4 py-3 rounded-2xl border border-gray-200 w-full" rows="3" placeholder="예: 점심시간 없이 근무"></textarea>
                    <div class="mt-2 text-xs text-gray-500"><?php echo $laborGongsuApprovalExempt ? '개발부서는 1.2 이상 공수도 승인 없이 바로 반영됩니다.' : '1.2 이상 공수 수정은 요청 사유가 필요합니다. 예: 점심시간 없이 근무'; ?></div>
                </div>
                <div id="gongsuHistoryBox" class="rounded-2xl border border-gray-200 bg-gray-50 text-sm hidden">
                    <button type="button" id="gongsuHistoryToggle" class="flex w-full items-center justify-between px-4 py-3 text-left font-extrabold text-gray-900" aria-expanded="false" aria-controls="gongsuHistoryContent">
                        <span>이력</span>
                        <span id="gongsuHistoryToggleIcon" aria-hidden="true">▼</span>
                    </button>
                    <div id="gongsuHistoryContent" class="hidden max-h-64 space-y-3 overflow-y-auto border-t border-gray-200 px-4 py-3 text-gray-700"></div>
                </div>
                <div class="flex items-center justify-between gap-2">
                    <button type="button" class="px-4 py-2 rounded-2xl border border-red-200 text-red-600 font-extrabold hidden" id="gongsuRequestDelete">공수 삭제</button>
                    <div class="flex items-center justify-end gap-2 ml-auto">
                        <button type="button" class="px-4 py-2 rounded-2xl border border-gray-200 text-gray-700 font-extrabold" data-modal-close="gongsuRequest">취소</button>
                        <button type="button" class="px-5 py-2 rounded-2xl bg-gray-900 text-white font-extrabold" id="gongsuRequestSubmit"><?php echo $laborGongsuApprovalExempt ? '저장' : '요청/저장'; ?></button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    var csrf = <?php echo json_encode(csrf_token()); ?>;
    var projectName = <?php echo json_encode(isset($projectRow['name']) ? (string)$projectRow['name'] : ''); ?>;    
    var gongsuApprovalExempt = <?php echo $laborGongsuApprovalExempt ? 'true' : 'false'; ?>;
    var requestCtx = null;
    var savingCell = false;
    function openModal(){ var m=document.getElementById('modal-gongsuRequest'); if(m)m.classList.remove('hidden'); }
    function closeModal(){ var m=document.getElementById('modal-gongsuRequest'); if(m)m.classList.add('hidden'); }
    var closeButtons = document.querySelectorAll('[data-modal-close="gongsuRequest"]');
    for (var i=0; i<closeButtons.length; i++) closeButtons[i].addEventListener('click', closeModal);

    function formatValue(v){
        var n = parseFloat(v);
        if (isNaN(n)) return '';
        if (Math.abs(n - Math.round(n)) < 0.0001) return String(Math.round(n));
        return String(n.toFixed(2)).replace(/0+$/,'').replace(/\.$/,'');
    }

    function openRequestModal(cell, ctx){
        requestCtx = { cell:cell, ctx:ctx };
        document.getElementById('gongsuProjectName').textContent = projectName || '-';        
        document.getElementById('gongsuWorkerName').textContent = ctx.workerName;
        document.getElementById('gongsuWorkerDate').textContent = ctx.date;
        document.getElementById('gongsuStartTime').textContent = ctx.startTime || '-';
        document.getElementById('gongsuEndTime').textContent = ctx.endTime || '-';
        document.getElementById('gongsuCurrentValue').textContent = formatValue(ctx.oldValue);
        document.getElementById('gongsuRequestedValue').value = formatValue(ctx.oldValue);
        document.getElementById('gongsuRequestReason').value = '';
        openModal();
        setTimeout(function(){ var input=document.getElementById('gongsuRequestedValue'); if(input){ input.focus(); input.select(); } }, 0);        
    }

    function saveGongsuCell(cell, ctx, newValue, reason){
        if (savingCell) return;
        savingCell = true;
        var body = [
            '_csrf=' + encodeURIComponent(csrf),
            'project_id=' + encodeURIComponent(ctx.projectId),
            'month=' + encodeURIComponent(ctx.month),
            'worker_name=' + encodeURIComponent(ctx.workerName),
            'work_date=' + encodeURIComponent(ctx.date),
            'worker_key=' + encodeURIComponent(ctx.workerKey),
            'old_value=' + encodeURIComponent(String(ctx.oldValue)),
            'new_value=' + encodeURIComponent(String(newValue)),
            'reason=' + encodeURIComponent(reason || '')
        ].join('&');
        fetch('?r=construction/labor_gongsu_override_save', {
            method:'POST',
            credentials:'same-origin',
            headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
            body:body
        })
            .then(function(r){
                return r.text().then(function(text){
                    var data = null;
                    try { data = JSON.parse(text); } catch (e) {}
                    if (!data) throw new Error('서버 응답이 JSON이 아닙니다.');
                    if (!r.ok || !data.ok) throw new Error(data.message ? data.message : '저장 실패');
                    return data;
                });
            })
            .then(function(data){
                if (data.mode === 'pending') {
                    var display = formatValue(ctx.oldValue);
                    cell.textContent = display;
                    cell.setAttribute('data-old-value', display);
                    var pending = document.createElement('small');
                    pending.className = 'cpms-pending-badge ml-1 text-[10px] text-amber-600';
                    pending.textContent = '승인대기';
                    cell.appendChild(pending);
                    alert(data.message || '승인 요청으로 등록되었습니다.');
                    closeModal();                    
                    return;
                }
                var value = (data && typeof data.value !== 'undefined') ? data.value : newValue;
                var displayValue = formatValue(value);
                cell.textContent = displayValue;
                cell.setAttribute('data-old-value', displayValue);
                closeModal();
            })
            .catch(function(e){
                if (window.console && console.error) console.error('gongsu save failed:', e);
                alert(e && e.message ? e.message : '저장 실패');
            })
            .then(function(){ savingCell = false; });
    }

    var cells = document.querySelectorAll('.cpms-gongsu-cell');
    for (var c=0; c<cells.length; c++) {
        cells[c].addEventListener('click', function(){
            var cell = this;
            var oldValueRaw = (cell.getAttribute('data-old-value') || '').replace(/\s+/g,'');
            var oldValue = oldValueRaw === '' ? 0 : parseFloat(oldValueRaw);
            if (isNaN(oldValue)) oldValue = 0;
            openRequestModal(cell, {
                projectId:cell.getAttribute('data-project-id'),
                month:cell.getAttribute('data-month'),
                workerName:cell.getAttribute('data-worker-name'),
                workerKey:(cell.getAttribute('data-worker-key') || '').trim(),
                date:cell.getAttribute('data-date'),
                startTime:cell.getAttribute('data-start-time') || '-',
                endTime:cell.getAttribute('data-end-time') || '-',
                oldValue:oldValue
            });
        });
    }

    var submitBtn = document.getElementById('gongsuRequestSubmit');
    if (submitBtn) {
        submitBtn.addEventListener('click', function(){
            if (!requestCtx) return;
            var reason = document.getElementById('gongsuRequestReason').value.replace(/^\s+|\s+$/g, '');
            var requestedVal = document.getElementById('gongsuRequestedValue').value.replace(/\s+/g, '');
            if (!/^\d+(\.\d+)?$/.test(requestedVal)) { alert('변경 공수는 숫자 형식으로 입력하세요.'); return; }
            var nextValue = parseFloat(requestedVal);
            if (isNaN(nextValue) || nextValue < 0) { alert('변경 공수는 0 이상만 가능합니다.'); return; }
            if (!gongsuApprovalExempt && nextValue >= 1.2 && !reason) { alert('1.2 이상 공수 수정은 승인 요청사유가 필요합니다.'); return; }
            if (!requestCtx.ctx.projectId || isNaN(parseInt(requestCtx.ctx.projectId, 10)) || parseInt(requestCtx.ctx.projectId, 10) <= 0) {
                alert('프로젝트 정보가 올바르지 않아 저장할 수 없습니다. 페이지를 새로고침 후 다시 시도해 주세요.');
                return;
            }
            saveGongsuCell(requestCtx.cell, requestCtx.ctx, nextValue, reason);
        });
    }
})();
</script>
<script>
(function(){
    var csrf = <?php echo json_encode(csrf_token()); ?>;
    var projectId = <?php echo (int)$projectId; ?>;
    var projectName = <?php echo json_encode(isset($projectRow['name']) ? (string)$projectRow['name'] : ''); ?>;
    var selectedMonth = <?php echo json_encode($selectedMonth); ?>;
    var todayDate = <?php echo json_encode(date('Y-m-d')); ?>;
    var gongsuApprovalExempt = <?php echo $laborGongsuApprovalExempt ? 'true' : 'false'; ?>;
    var gongsuHistoryMap = <?php echo json_encode($overrideHistoryMap, JSON_UNESCAPED_UNICODE); ?>;
    var requestCtx = null;
    var addCtx = null;
    var laborBulkApprovalState = null;
    var savingCell = false;

    function openModal(id){
        var modal = document.getElementById(id);
        if (modal) modal.classList.remove('hidden');
    }

    function closeModal(id){
        var modal = document.getElementById(id);
        if (modal) modal.classList.add('hidden');
    }

    function closeAllModals(){
        closeModal('modal-gongsuRequest');
        closeModal('modal-gongsuAddConfirm');
        closeModal('modal-laborBulkRequest');
    }

    function formatValue(v){
        var n = parseFloat(v);
        if (isNaN(n)) return '';
        if (Math.abs(n - Math.round(n)) < 0.0001) return String(Math.round(n));
        return String(n.toFixed(2)).replace(/0+$/,'').replace(/\.$/,'');
    }

    function formatMoney(v){
        var n = parseFloat(v);
        if (isNaN(n) || Math.abs(n) < 0.0001) return '0';
        return String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function parseCellValue(cell){
        if (!cell) return 0;
        var raw = (cell.getAttribute('data-old-value') || '').replace(/\s+/g, '');
        if (raw === '') return 0;
        var n = parseFloat(raw);
        return isNaN(n) ? 0 : n;
    }

    function updateLaborSheetTotals(){
        if (document.querySelectorAll('.cpms-gongsu-cell').length === 0) return;
        var rows = document.querySelectorAll('.cpms-timesheet-row');
        var dailyTotals = {};
        var totalOutputDays = 0;
        var totalGongsu = 0;
        var totalPay = 0;
        var todayAttendanceCount = 0;
        var groupTotals = {};
        for (var r = 0; r < rows.length; r++) {
            var row = rows[r];
            var groupKey = row.getAttribute('data-group-key') || '';
            if (!groupTotals[groupKey]) groupTotals[groupKey] = {daily:{}, outputDays:0, gongsu:0, pay:0};
            var buttons = row.querySelectorAll('.cpms-gongsu-cell');
            var rowOutputDays = 0;
            var rowGongsu = 0;
            for (var i = 0; i < buttons.length; i++) {
                var value = parseCellValue(buttons[i]);
                if (value <= 0) continue;
                var date = buttons[i].getAttribute('data-date') || '';
                rowOutputDays++;
                rowGongsu += value;
                if (date !== '') {
                    if (!dailyTotals[date]) dailyTotals[date] = 0;
                    dailyTotals[date] += value;
                    if (!groupTotals[groupKey].daily[date]) groupTotals[groupKey].daily[date] = 0;
                    groupTotals[groupKey].daily[date] += value;
                }
            }
            var wageRate = parseFloat(row.getAttribute('data-wage-rate') || '0');
            if (isNaN(wageRate)) wageRate = 0;
            var payUnits = row.getAttribute('data-pay-unit') === 'days' ? rowOutputDays : rowGongsu;
            totalOutputDays += rowOutputDays;
            totalGongsu += rowGongsu;
            totalPay += payUnits * wageRate;
            groupTotals[groupKey].outputDays += rowOutputDays;
            groupTotals[groupKey].gongsu += rowGongsu;
            groupTotals[groupKey].pay += payUnits * wageRate;
            if (todayDate) {
                var todayCell = row.querySelector('.cpms-gongsu-cell[data-date="' + todayDate + '"]');
                if (todayCell && parseCellValue(todayCell) > 0) todayAttendanceCount++;
            }
        }

        var dayCells = document.querySelectorAll('.cpms-daily-total');
        for (var d = 0; d < dayCells.length; d++) {
            var day = dayCells[d].getAttribute('data-date') || '';
            var dayValue = dailyTotals[day] || 0;
            dayCells[d].textContent = dayValue > 0 ? formatValue(dayValue) : '0';
        }
        var outputTotalCell = document.querySelector('.cpms-sheet-output-days-total');
        var gongsuTotalCell = document.querySelector('.cpms-sheet-gongsu-total');
        var payTotalCell = document.querySelector('.cpms-sheet-pay-total');
        var totalAttendanceCell = document.querySelector('.cpms-attendance-total-count');
        var todayAttendanceCell = document.querySelector('.cpms-attendance-today-count');
        if (outputTotalCell) outputTotalCell.textContent = String(totalOutputDays);
        if (gongsuTotalCell) gongsuTotalCell.textContent = totalGongsu > 0 ? formatValue(totalGongsu) : '0';
        if (payTotalCell) payTotalCell.textContent = formatMoney(totalPay);
        if (totalAttendanceCell) totalAttendanceCell.textContent = totalGongsu > 0 ? formatValue(totalGongsu) : '0';
        if (todayAttendanceCell) todayAttendanceCell.textContent = String(todayAttendanceCount);

        var subtotalRows = document.querySelectorAll('.cpms-labor-subtotal-row');
        for (var s = 0; s < subtotalRows.length; s++) {
            var subtotalRow = subtotalRows[s];
            var subtotalKey = subtotalRow.getAttribute('data-group-key') || '';
            var subtotalData = groupTotals[subtotalKey] || {daily:{}, outputDays:0, gongsu:0, pay:0};
            var subtotalDailyCells = subtotalRow.querySelectorAll('.cpms-subtotal-daily');
            for (var sd = 0; sd < subtotalDailyCells.length; sd++) {
                var subtotalDate = subtotalDailyCells[sd].getAttribute('data-date') || '';
                var subtotalDayValue = subtotalData.daily[subtotalDate] || 0;
                subtotalDailyCells[sd].textContent = subtotalDayValue > 0 ? formatValue(subtotalDayValue) : '0';
            }
            var subtotalOutputCell = subtotalRow.querySelector('.cpms-subtotal-output-days');
            var subtotalGongsuCell = subtotalRow.querySelector('.cpms-subtotal-gongsu');
            var subtotalPayCell = subtotalRow.querySelector('.cpms-subtotal-pay');
            if (subtotalOutputCell) subtotalOutputCell.textContent = String(subtotalData.outputDays);
            if (subtotalGongsuCell) subtotalGongsuCell.textContent = subtotalData.gongsu > 0 ? formatValue(subtotalData.gongsu) : '0';
            if (subtotalPayCell) subtotalPayCell.textContent = formatMoney(subtotalData.pay);
        }
    }

    function setCellDisplay(cell, displayValue, isPending){
        if (!cell) return;
        while (cell.firstChild) cell.removeChild(cell.firstChild);
        cell.setAttribute('data-old-value', displayValue || '');
        if (displayValue) {
            cell.appendChild(document.createTextNode(displayValue));
        }
        if (isPending) {
            var pending = document.createElement('small');
            pending.className = 'cpms-pending-badge ml-1 text-[10px] text-amber-600';
            pending.textContent = '승인대기';
            cell.appendChild(pending);
        }
    }

    function updateRowSummary(cell){
        if (!cell || !cell.closest) return;
        var row = cell.closest('tr');
        if (!row) return;
        var buttons = row.querySelectorAll('.cpms-gongsu-cell');
        var outputDays = 0;
        var totalGongsu = 0;
        for (var i = 0; i < buttons.length; i++) {
            var value = parseCellValue(buttons[i]);
            if (value <= 0) continue;
            outputDays++;
            totalGongsu += value;
        }
        var outputDaysCell = row.querySelector('.cpms-output-days');
        var totalGongsuCell = row.querySelector('.cpms-total-gongsu');
        var totalPayCell = row.querySelector('.cpms-total-pay');
        var wageRate = parseFloat(row.getAttribute('data-wage-rate') || '0');
        var payUnits = row.getAttribute('data-pay-unit') === 'days' ? outputDays : totalGongsu;
        if (outputDaysCell) outputDaysCell.textContent = String(outputDays);
        if (totalGongsuCell) totalGongsuCell.textContent = totalGongsu > 0 ? formatValue(totalGongsu) : '0';
        if (totalPayCell) totalPayCell.textContent = formatMoney(payUnits * (isNaN(wageRate) ? 0 : wageRate));
        updateLaborSheetTotals();
    }

    function escHtml(value){
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function pickName(row, nameKey, emailKey, fallback){
        var name = row && row[nameKey] ? String(row[nameKey]) : '';
        var email = row && row[emailKey] ? String(row[emailKey]) : '';
        if (name) return name;
        if (email) return email;
        return fallback || '-';
    }

    function historyStatusLabel(row){
        var status = row && row.status ? String(row.status) : '';
        if (status === 'rejected') return '반려';
        if (status === 'applied') return '승인완료';
        if (status === 'pending') return '승인대기';
        if (status === 'cancelled') return '요청취소';
        if (status === 'deleted') return '삭제';
        return status || '-';
    }

    function addHistoryLine(lines, label, value){
        if (value === null || typeof value === 'undefined' || String(value) === '') return;
        lines.push('<div><span class="font-bold text-gray-500">' + escHtml(label) + ':</span> ' + escHtml(value) + '</div>');
    }

    function setGongsuHistoryExpanded(expanded){
        var toggle = document.getElementById('gongsuHistoryToggle');
        var icon = document.getElementById('gongsuHistoryToggleIcon');
        var content = document.getElementById('gongsuHistoryContent');
        if (!toggle || !icon || !content) return;
        toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        icon.textContent = expanded ? '▲' : '▼';
        if (expanded) content.classList.remove('hidden');
        else content.classList.add('hidden');
    }

    var gongsuHistoryToggle = document.getElementById('gongsuHistoryToggle');
    if (gongsuHistoryToggle) {
        gongsuHistoryToggle.addEventListener('click', function(){
            setGongsuHistoryExpanded(this.getAttribute('aria-expanded') !== 'true');
        });
    }

    function renderGongsuHistory(ctx){
        var box = document.getElementById('gongsuHistoryBox');
        var content = document.getElementById('gongsuHistoryContent');
        if (!box || !content) return;
        setGongsuHistoryExpanded(false);
        content.innerHTML = '';
        var key = String((ctx && ctx.workerKey) ? ctx.workerKey : '').replace(/^\s+|\s+$/g, '') + '|' + String((ctx && ctx.date) ? ctx.date : '');
        var rows = (gongsuHistoryMap && gongsuHistoryMap[key]) ? gongsuHistoryMap[key] : [];
        if (!rows.length) {
            box.classList.add('hidden');
            return;
        }

        var html = '';
        for (var i = 0; i < rows.length; i++) {
            var row = rows[i] || {};
            var oldValue = (row.old_value === null || typeof row.old_value === 'undefined') ? '-' : formatValue(row.old_value);
            var newValue = (String(row.is_deleted_entry || '0') === '1') ? '삭제' : formatValue(row.new_value);
            var lines = [];
            addHistoryLine(lines, '요청일시', row.created_at || '-');
            addHistoryLine(lines, '요청자', pickName(row, 'requested_by_name', 'requested_by_email', '-'));
            addHistoryLine(lines, '변경공수', oldValue + ' → ' + newValue);
            addHistoryLine(lines, '사유', row.reason || '-');

            if (row.first_approved_at || row.first_approver_name || row.first_approver_email) {
                addHistoryLine(lines, '1차 승인', pickName(row, 'first_approver_name', 'first_approver_email', '-') + (row.first_approved_at ? ' / ' + row.first_approved_at : ''));
            }
            if (row.second_approved_at || row.second_approver_name || row.second_approver_email) {
                addHistoryLine(lines, '2차 승인', pickName(row, 'second_approver_name', 'second_approver_email', '-') + (row.second_approved_at ? ' / ' + row.second_approved_at : ''));
            }
            if (row.rejected_at || row.rejected_by_name || row.rejected_by_email) {
                addHistoryLine(lines, '반려자', pickName(row, 'rejected_by_name', 'rejected_by_email', '-') + (row.rejected_at ? ' / ' + row.rejected_at : ''));
                addHistoryLine(lines, '반려사유', row.reject_reason || '-');
            }
            addHistoryLine(lines, '최종상태', historyStatusLabel(row));

            html += '<div class="rounded-xl border border-gray-200 bg-white p-3 leading-6">' + lines.join('') + '</div>';
        }
        content.innerHTML = html;
        box.classList.remove('hidden');
    }

    function openRequestModal(cell, ctx){
        requestCtx = { cell:cell, ctx:ctx };
        document.getElementById('gongsuProjectName').textContent = projectName || '-';
        document.getElementById('gongsuWorkerName').textContent = ctx.workerName;
        document.getElementById('gongsuWorkerDate').textContent = ctx.date;
        document.getElementById('gongsuStartTime').textContent = ctx.startTime || '-';
        document.getElementById('gongsuEndTime').textContent = ctx.endTime || '-';
        document.getElementById('gongsuCurrentValue').textContent = formatValue(ctx.oldValue);
        document.getElementById('gongsuRequestedValue').value = formatValue(ctx.oldValue);
        document.getElementById('gongsuRequestReason').value = '';
        var deleteBtn = document.getElementById('gongsuRequestDelete');
        if (deleteBtn) {
            if (ctx.oldValue > 0) deleteBtn.classList.remove('hidden');
            else deleteBtn.classList.add('hidden');
        }
        renderGongsuHistory(ctx);
        openModal('modal-gongsuRequest');
        setTimeout(function(){
            var input = document.getElementById('gongsuRequestedValue');
            if (input) {
                input.focus();
                input.select();
            }
        }, 0);
    }

    function openAddConfirmModal(cell, ctx){
        addCtx = { cell:cell, ctx:ctx };
        openModal('modal-gongsuAddConfirm');
    }

    function saveGongsuCell(cell, ctx, newValue, reason, options){
        if (savingCell) return;
        options = options || {};
        savingCell = true;
        var body = [
            '_csrf=' + encodeURIComponent(csrf),
            'project_id=' + encodeURIComponent(ctx.projectId),
            'month=' + encodeURIComponent(ctx.month),
            'worker_name=' + encodeURIComponent(ctx.workerName),
            'work_date=' + encodeURIComponent(ctx.date),
            'worker_key=' + encodeURIComponent(ctx.workerKey),
            'old_value=' + encodeURIComponent(String(ctx.oldValue)),
            'new_value=' + encodeURIComponent(String(newValue)),
            'reason=' + encodeURIComponent(reason || ''),
            'delete_mode=' + encodeURIComponent(options.deleteMode ? '1' : '0')
        ].join('&');

        fetch('?r=construction/labor_gongsu_override_save', {
            method:'POST',
            credentials:'same-origin',
            headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
            body:body
        })
            .then(function(r){
                return r.text().then(function(text){
                    var data = null;
                    try { data = JSON.parse(text); } catch (e) {}
                    if (!data) throw new Error('서버 응답이 JSON이 아닙니다.');
                    if (!r.ok || !data.ok) throw new Error(data.message ? data.message : '저장 실패');
                    return data;
                });
            })
            .then(function(data){
                if (data.mode === 'pending') {
                    setCellDisplay(cell, formatValue(ctx.oldValue), true);
                    alert(data.message || '승인 요청으로 등록되었습니다.');
                    closeAllModals();
                    return;
                }
                var value = (data && typeof data.value !== 'undefined') ? data.value : newValue;
                var displayValue = (data && data.deleted === 'Y') ? '' : formatValue(value);
                setCellDisplay(cell, displayValue, false);
                updateRowSummary(cell);
                closeAllModals();
            })
            .catch(function(e){
                if (window.console && console.error) console.error('gongsu save failed:', e);
                alert(e && e.message ? e.message : '저장에 실패했습니다.');
            })
            .then(function(){ savingCell = false; });
    }

    function buildCellContext(cell){
        var oldValue = parseCellValue(cell);
        return {
            projectId:cell.getAttribute('data-project-id'),
            month:cell.getAttribute('data-month'),
            workerName:cell.getAttribute('data-worker-name'),
            workerKey:(cell.getAttribute('data-worker-key') || '').replace(/^\s+|\s+$/g, ''),
            date:cell.getAttribute('data-date'),
            startTime:cell.getAttribute('data-start-time') || '-',
            endTime:cell.getAttribute('data-end-time') || '-',
            oldValue:oldValue
        };
    }

    function pad2(value){
        value = parseInt(value, 10);
        if (isNaN(value)) value = 0;
        return value < 10 ? '0' + value : String(value);
    }

    function nowDateTimeText(){
        var d = new Date();
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()) + ' ' + pad2(d.getHours()) + ':' + pad2(d.getMinutes()) + ':' + pad2(d.getSeconds());
    }

    function pushGongsuHistory(ctx, oldValue, newValue, reason, isDeleted){
        if (!ctx) return;
        var key = String(ctx.workerKey || '').replace(/^\s+|\s+$/g, '') + '|' + String(ctx.date || '');
        if (key === '|') return;
        if (!gongsuHistoryMap[key]) gongsuHistoryMap[key] = [];
        gongsuHistoryMap[key].unshift({
            worker_key:ctx.workerKey || '',
            worker_name:ctx.workerName || '',
            work_date:ctx.date || '',
            old_value:formatValue(oldValue),
            new_value:formatValue(newValue),
            is_deleted_entry:isDeleted ? '1' : '0',
            reason:reason || '-',
            status:'applied',
            requested_by_email:'',
            requested_by_name:'',
            created_at:nowDateTimeText()
        });
    }

    function flashGongsuCell(cell){
        if (!cell || !cell.classList) return;
        cell.classList.remove('cpms-gongsu-just-saved');
        setTimeout(function(){
            cell.classList.add('cpms-gongsu-just-saved');
            setTimeout(function(){ cell.classList.remove('cpms-gongsu-just-saved'); }, 700);
        }, 10);
    }

    function parseJsonResponse(r){
        return r.text().then(function(text){
            var data = null;
            try { data = JSON.parse(text); } catch (e) {}
            if (!data) throw new Error('서버 응답이 JSON이 아닙니다.');
            if (!r.ok || !data.ok) throw new Error(data.message ? data.message : '저장 실패');
            return data;
        });
    }

    function saveGongsuBulkCell(cell, ctx, newValue, reason){
        var body = [
            '_csrf=' + encodeURIComponent(csrf),
            'project_id=' + encodeURIComponent(ctx.projectId),
            'month=' + encodeURIComponent(ctx.month),
            'worker_name=' + encodeURIComponent(ctx.workerName),
            'work_date=' + encodeURIComponent(ctx.date),
            'worker_key=' + encodeURIComponent(ctx.workerKey),
            'old_value=' + encodeURIComponent(String(ctx.oldValue)),
            'new_value=' + encodeURIComponent(String(newValue)),
            'reason=' + encodeURIComponent(reason || ''),
            'delete_mode=0'
        ].join('&');

        return fetch('?r=construction/labor_gongsu_override_save', {
            method:'POST',
            credentials:'same-origin',
            headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
            body:body
        })
            .then(parseJsonResponse)
            .then(function(data){
                if (data.mode === 'pending') {
                    setCellDisplay(cell, formatValue(ctx.oldValue), true);
                    return data;
                }
                var value = (data && typeof data.value !== 'undefined') ? data.value : newValue;
                var displayValue = formatValue(value);
                setCellDisplay(cell, displayValue, false);
                updateRowSummary(cell);
                pushGongsuHistory(ctx, ctx.oldValue, value, reason, false);
                flashGongsuCell(cell);
                return data;
            });
    }

    // 파일: app/views/construction/tabs/labor.php
    // 1.3/1.4/1.5/2공수는 선택 인원을 한 번의 HTTP 요청으로 보내 같은 일괄 묶음으로 저장합니다.
    function saveGongsuBulkApprovalRequest(targets, newValue, reason, requestScope){
        var entries = [];
        for (var i = 0; i < targets.length; i++) {
            entries.push({
                worker_name:targets[i].ctx.workerName,
                worker_key:targets[i].ctx.workerKey,
                work_date:targets[i].ctx.date,
                old_value:String(targets[i].ctx.oldValue)
            });
        }
        var body = [
            '_csrf=' + encodeURIComponent(csrf),
            'project_id=' + encodeURIComponent(targets[0].ctx.projectId),
            'month=' + encodeURIComponent(targets[0].ctx.month),
            'new_value=' + encodeURIComponent(String(newValue)),
            'reason=' + encodeURIComponent(reason || ''),
            'request_scope=' + encodeURIComponent(requestScope === 'all' ? 'all' : 'partial'),
            'bulk_entries=' + encodeURIComponent(JSON.stringify(entries))
        ].join('&');

        return fetch('?r=construction/labor_gongsu_override_save', {
            method:'POST',
            credentials:'same-origin',
            headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
            body:body
        })
            .then(parseJsonResponse)
            .then(function(data){
                for (var i = 0; i < targets.length; i++) {
                    if (data.mode === 'pending') {
                        setCellDisplay(targets[i].cell, formatValue(targets[i].ctx.oldValue), true);
                    } else {
                        setCellDisplay(targets[i].cell, formatValue(newValue), false);
                        updateRowSummary(targets[i].cell);
                        pushGongsuHistory(targets[i].ctx, targets[i].ctx.oldValue, newValue, reason, false);
                        flashGongsuCell(targets[i].cell);
                    }
                }
                return data;
            });
    }

    function setLaborBulkStatus(text){
        var status = document.getElementById('laborBulkStatus');
        if (status) status.textContent = text || '';
    }

    function refreshLaborBulkSelectedCount(checked, total){
        var label = document.getElementById('laborBulkSelectedCount');
        if (!label) return;
        if (typeof checked === 'undefined' || typeof total === 'undefined') {
            var checks = document.querySelectorAll('.cpms-labor-worker-check');
            total = checks.length;
            checked = 0;
            for (var i = 0; i < checks.length; i++) {
                if (checks[i].checked) checked++;
            }
        }
        label.textContent = '선택 ' + checked + '명 / 전체 ' + total + '명';
    }

    function setLaborBulkDisabled(disabled){
        var buttons = document.querySelectorAll('[data-labor-bulk-value]');
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].disabled = !!disabled;
            if (disabled) buttons[i].classList.add('opacity-60', 'cursor-not-allowed');
            else buttons[i].classList.remove('opacity-60', 'cursor-not-allowed');
        }
    }

    function refreshLaborBulkSelectAll(){
        var all = document.getElementById('laborBulkSelectAll');
        if (!all) {
            refreshLaborBulkSelectedCount();
            return;
        }
        var checks = document.querySelectorAll('.cpms-labor-worker-check');
        var checked = 0;
        for (var i = 0; i < checks.length; i++) {
            if (checks[i].checked) checked++;
        }
        all.checked = (checks.length > 0 && checked === checks.length);
        all.indeterminate = (checked > 0 && checked < checks.length);
        refreshLaborBulkSelectedCount(checked, checks.length);
    }

    function laborBulkDefaultDate(){
        if (todayDate.indexOf(selectedMonth + '-') === 0) return todayDate;
        return selectedMonth + '-01';
    }

    function laborBulkTargetsForDate(checks, workDate){
        var targets = [];
        for (var i = 0; i < checks.length; i++) {
            var row = checks[i].closest ? checks[i].closest('tr') : null;
            var cell = row ? row.querySelector('.cpms-gongsu-cell[data-date="' + workDate + '"]') : null;
            if (cell) {
                var ctx = buildCellContext(cell);
                ctx.oldValue = parseCellValue(cell);
                targets.push({cell:cell, ctx:ctx});
            }
        }
        return targets;
    }

    function refreshLaborBulkApprovalDate(showAlert){
        if (!laborBulkApprovalState) return false;
        var dateBox = document.getElementById('laborBulkRequestDate');
        var workDate = dateBox ? String(dateBox.value || '') : '';
        if (!/^\d{4}-\d{2}-\d{2}$/.test(workDate) || workDate.indexOf(selectedMonth + '-') !== 0) {
            if (showAlert) alert('선택한 출력월 안의 공수 적용일자를 선택해 주세요.');
            if (dateBox) dateBox.focus();
            return false;
        }
        var targets = laborBulkTargetsForDate(laborBulkApprovalState.checks, workDate);
        if (!targets.length) {
            if (showAlert) alert('선택한 날짜에 입력할 수 있는 인원이 없습니다.');
            return false;
        }
        var selectedNames = [];
        for (var nameIndex = 0; nameIndex < targets.length; nameIndex++) selectedNames.push(targets[nameIndex].ctx.workerName);
        var totalWorkers = document.querySelectorAll('.cpms-labor-worker-check').length;
        var requestScope = totalWorkers > 0 && targets.length === totalWorkers ? 'all' : 'partial';
        laborBulkApprovalState.targets = targets;
        laborBulkApprovalState.scope = requestScope;
        laborBulkApprovalState.workDate = workDate;
        var scopeBox = document.getElementById('laborBulkRequestScope');
        var namesBox = document.getElementById('laborBulkRequestNames');
        if (scopeBox) scopeBox.textContent = requestScope === 'all' ? '[전체요청] 전체 ' + targets.length + '명' : '선택요청 ' + targets.length + '명';
        if (namesBox) namesBox.textContent = selectedNames.join(', ');
        return true;
    }

    function openLaborBulkApproval(value, checks){
        var selectedChecks = [];
        for (var checkIndex = 0; checkIndex < checks.length; checkIndex++) selectedChecks.push(checks[checkIndex]);
        laborBulkApprovalState = {checks:selectedChecks, targets:[], value:value, scope:'partial', workDate:''};
        var dateBox = document.getElementById('laborBulkRequestDate');
        var valueBox = document.getElementById('laborBulkRequestValue');
        var reasonBox = document.getElementById('laborBulkRequestReason');
        if (dateBox) dateBox.value = laborBulkDefaultDate();
        if (valueBox) valueBox.textContent = formatValue(value) + '공수';
        if (reasonBox) reasonBox.value = '';
        if (!refreshLaborBulkApprovalDate(true)) {
            laborBulkApprovalState = null;
            return;
        }
        openModal('modal-laborBulkRequest');
        if (dateBox) dateBox.focus();
    }

    function runLaborBulkInput(value){
        var checks = document.querySelectorAll('.cpms-labor-worker-check:checked');
        if (!checks.length) {
            alert('일괄 입력할 인원을 선택하세요.');
            return;
        }

        if (value >= 1.2) {
            openLaborBulkApproval(value, checks);
            return;
        }

        if (todayDate.indexOf(selectedMonth + '-') !== 0) {
            alert('오늘 날짜가 현재 출력월에 없습니다.');
            return;
        }
        var targets = laborBulkTargetsForDate(checks, todayDate);
        if (!targets.length) {
            alert('오늘 날짜에 입력할 수 있는 셀이 없습니다.');
            return;
        }
        var reason = '일괄 공수 입력(' + formatValue(value) + '공수)';

        var index = 0;
        var success = 0;
        var failed = 0;
        setLaborBulkDisabled(true);
        setLaborBulkStatus('0/' + targets.length + ' 저장 중');

        function next(){
            if (index >= targets.length) {
                setLaborBulkDisabled(false);
                setLaborBulkStatus(success + '/' + targets.length + ' 저장 완료' + (failed > 0 ? ' · 실패 ' + failed + '건' : ''));
                return;
            }
            var target = targets[index];
            index++;
            target.ctx.oldValue = parseCellValue(target.cell);
            saveGongsuBulkCell(target.cell, target.ctx, value, reason)
                .then(function(){ success++; })
                .catch(function(e){
                    failed++;
                    if (window.console && console.error) console.error('bulk gongsu save failed:', e);
                })
                .then(function(){
                    setLaborBulkStatus(index + '/' + targets.length + ' 저장 중');
                    next();
                });
        }
        next();
    }

    var laborBulkRequestCloseButtons = document.querySelectorAll('[data-labor-bulk-request-close]');
    for (var bulkCloseIndex = 0; bulkCloseIndex < laborBulkRequestCloseButtons.length; bulkCloseIndex++) {
        laborBulkRequestCloseButtons[bulkCloseIndex].addEventListener('click', function(){
            closeModal('modal-laborBulkRequest');
            laborBulkApprovalState = null;
        });
    }
    var laborBulkRequestDate = document.getElementById('laborBulkRequestDate');
    if (laborBulkRequestDate) {
        laborBulkRequestDate.addEventListener('change', function(){
            refreshLaborBulkApprovalDate(true);
        });
    }
    var laborBulkRequestSubmit = document.getElementById('laborBulkRequestSubmit');
    if (laborBulkRequestSubmit) {
        laborBulkRequestSubmit.addEventListener('click', function(){
            if (!laborBulkApprovalState) return;
            if (!refreshLaborBulkApprovalDate(true)) return;
            if (!laborBulkApprovalState.targets || !laborBulkApprovalState.targets.length) return;
            var reasonBox = document.getElementById('laborBulkRequestReason');
            var reason = reasonBox ? String(reasonBox.value || '').replace(/^\s+|\s+$/g, '') : '';
            if (!gongsuApprovalExempt && !reason) {
                alert('일괄 공수 승인 요청사유를 입력해 주세요.');
                if (reasonBox) reasonBox.focus();
                return;
            }
            var state = laborBulkApprovalState;
            laborBulkRequestSubmit.disabled = true;
            laborBulkRequestSubmit.textContent = gongsuApprovalExempt ? '적용 중' : '요청 중';
            setLaborBulkDisabled(true);
            setLaborBulkStatus(state.targets.length + (gongsuApprovalExempt ? '명 일괄 적용 중' : '명 일괄 승인 요청 중'));
            saveGongsuBulkApprovalRequest(state.targets, state.value, reason, state.scope)
                .then(function(data){
                    setLaborBulkStatus(state.targets.length + (data && data.mode === 'applied' ? '명 일괄 적용 완료' : '명 승인 요청 완료'));
                    closeModal('modal-laborBulkRequest');
                    laborBulkApprovalState = null;
                    alert(data && data.message ? data.message : (gongsuApprovalExempt ? '일괄 공수를 적용했습니다.' : '일괄 승인 요청을 보냈습니다.'));
                    window.location.reload();
                })
                .catch(function(e){
                    setLaborBulkStatus(gongsuApprovalExempt ? '일괄 공수 적용 실패' : '일괄 승인 요청 실패');
                    if (window.console && console.error) console.error('bulk approval request failed:', e);
                    alert(e && e.message ? e.message : (gongsuApprovalExempt ? '일괄 공수 적용에 실패했습니다.' : '일괄 승인 요청에 실패했습니다.'));
                })
                .then(function(){
                    setLaborBulkDisabled(false);
                    laborBulkRequestSubmit.disabled = false;
                    laborBulkRequestSubmit.textContent = gongsuApprovalExempt ? '바로 적용' : '승인 요청';
                });
        });
    }

    var modalCloseButtons = document.querySelectorAll('[data-modal-close="gongsuRequest"], [data-modal-close="gongsuAddConfirm"]');
    for (var i = 0; i < modalCloseButtons.length; i++) {
        modalCloseButtons[i].addEventListener('click', function(){
            closeModal('modal-' + this.getAttribute('data-modal-close'));
        }, true);
    }

    var laborBulkSelectAll = document.getElementById('laborBulkSelectAll');
    if (laborBulkSelectAll) {
        laborBulkSelectAll.addEventListener('change', function(){
            var checks = document.querySelectorAll('.cpms-labor-worker-check');
            for (var i = 0; i < checks.length; i++) {
                checks[i].checked = laborBulkSelectAll.checked;
            }
            refreshLaborBulkSelectAll();
        });
    }

    var laborWorkerChecks = document.querySelectorAll('.cpms-labor-worker-check');
    for (var lw = 0; lw < laborWorkerChecks.length; lw++) {
        laborWorkerChecks[lw].addEventListener('change', refreshLaborBulkSelectAll);
    }
    refreshLaborBulkSelectAll();
    if (document.querySelector('.cpms-gongsu-cell')) {
        updateLaborSheetTotals();
    }

    var laborBulkButtons = document.querySelectorAll('[data-labor-bulk-value]');
    for (var lb = 0; lb < laborBulkButtons.length; lb++) {
        laborBulkButtons[lb].addEventListener('click', function(event){
            event.preventDefault();
            var value = parseFloat(this.getAttribute('data-labor-bulk-value') || '0');
            if (isNaN(value) || value <= 0) return;
            runLaborBulkInput(value);
        });
    }

    var rejectedAcknowledgeButtons = document.querySelectorAll('[data-rejected-acknowledge]');
    for (var ra = 0; ra < rejectedAcknowledgeButtons.length; ra++) {
        rejectedAcknowledgeButtons[ra].addEventListener('click', function(event){
            event.preventDefault();
            var button = this;
            var overrideId = parseInt(button.getAttribute('data-override-id') || '0', 10);
            if (overrideId <= 0) return;
            if (!window.confirm('반려 내용을 확인하고 요청 목록에서 숨길까요?')) return;
            button.disabled = true;
            button.textContent = '확인 처리 중';
            var body = [
                '_csrf=' + encodeURIComponent(csrf),
                'action=acknowledge_rejected',
                'project_id=' + encodeURIComponent(String(projectId)),
                'month=' + encodeURIComponent(selectedMonth),
                'override_id=' + encodeURIComponent(String(overrideId))
            ].join('&');
            fetch('?r=construction/labor_gongsu_override_save', {
                method:'POST',
                credentials:'same-origin',
                headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
                body:body
            })
                .then(parseJsonResponse)
                .then(function(){ window.location.reload(); })
                .catch(function(e){
                    button.disabled = false;
                    button.textContent = '반려 확인';
                    alert(e && e.message ? e.message : '반려 확인 처리에 실패했습니다.');
                });
        });
    }

    var pendingCancelButtons = document.querySelectorAll('[data-pending-cancel]');
    for (var pc = 0; pc < pendingCancelButtons.length; pc++) {
        pendingCancelButtons[pc].addEventListener('click', function(event){
            event.preventDefault();
            var button = this;
            var overrideId = parseInt(button.getAttribute('data-override-id') || '0', 10);
            if (overrideId <= 0) return;
            if (!window.confirm('이 공수 승인 요청을 취소할까요? 일괄 요청이면 묶음 전체가 취소됩니다.')) return;
            button.disabled = true;
            button.textContent = '취소 처리 중';
            var body = [
                '_csrf=' + encodeURIComponent(csrf),
                'action=cancel_pending',
                'project_id=' + encodeURIComponent(String(projectId)),
                'month=' + encodeURIComponent(selectedMonth),
                'override_id=' + encodeURIComponent(String(overrideId))
            ].join('&');
            fetch('?r=construction/labor_gongsu_override_save', {
                method:'POST',
                credentials:'same-origin',
                headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
                body:body
            })
                .then(parseJsonResponse)
                .then(function(data){
                    alert(data && data.message ? data.message : '승인 요청을 취소했습니다.');
                    window.location.reload();
                })
                .catch(function(e){
                    button.disabled = false;
                    button.textContent = '승인요청 취소';
                    alert(e && e.message ? e.message : '승인 요청 취소에 실패했습니다.');
                });
        });
    }

    var slots = document.querySelectorAll('.cpms-gongsu-cell-slot');
    for (var s = 0; s < slots.length; s++) {
        slots[s].addEventListener('click', function(event){
            event.preventDefault();
            event.stopImmediatePropagation();
            var cell = this.querySelector('.cpms-gongsu-cell');
            if (!cell) return;
            var oldValueRaw = (cell.getAttribute('data-old-value') || '').replace(/\s+/g, '');
            var oldValue = parseCellValue(cell);
            var ctx = {
                projectId:cell.getAttribute('data-project-id'),
                month:cell.getAttribute('data-month'),
                workerName:cell.getAttribute('data-worker-name'),
                workerKey:(cell.getAttribute('data-worker-key') || '').trim(),
                date:cell.getAttribute('data-date'),
                startTime:cell.getAttribute('data-start-time') || '-',
                endTime:cell.getAttribute('data-end-time') || '-',
                oldValue:oldValue
            };
            if (oldValueRaw === '') {
                openAddConfirmModal(cell, ctx);
                return;
            }
            openRequestModal(cell, ctx);
        }, true);
    }

    var cells = document.querySelectorAll('.cpms-gongsu-cell');
    for (var c = 0; c < cells.length; c++) {
        cells[c].addEventListener('click', function(event){
            event.preventDefault();
            event.stopImmediatePropagation();
            var cell = this;
            var oldValueRaw = (cell.getAttribute('data-old-value') || '').replace(/\s+/g, '');
            var oldValue = parseCellValue(cell);
            var ctx = {
                projectId:cell.getAttribute('data-project-id'),
                month:cell.getAttribute('data-month'),
                workerName:cell.getAttribute('data-worker-name'),
                workerKey:(cell.getAttribute('data-worker-key') || '').trim(),
                date:cell.getAttribute('data-date'),
                startTime:cell.getAttribute('data-start-time') || '-',
                endTime:cell.getAttribute('data-end-time') || '-',
                oldValue:oldValue
            };
            if (oldValueRaw === '') {
                openAddConfirmModal(cell, ctx);
                return;
            }
            openRequestModal(cell, ctx);
        }, true);
    }

    var addConfirmYesBtn = document.getElementById('gongsuAddConfirmYes');
    if (addConfirmYesBtn) {
        addConfirmYesBtn.addEventListener('click', function(event){
            event.preventDefault();
            event.stopImmediatePropagation();
            if (!addCtx) return;
            if (!addCtx.ctx.projectId || isNaN(parseInt(addCtx.ctx.projectId, 10)) || parseInt(addCtx.ctx.projectId, 10) <= 0) {
                alert('프로젝트 정보를 확인할 수 없습니다. 페이지를 새로고침한 뒤 다시 시도해주세요.');
                return;
            }
            saveGongsuCell(addCtx.cell, addCtx.ctx, 1, '', { deleteMode:false });
        }, true);
    }

    var submitBtn = document.getElementById('gongsuRequestSubmit');
    if (submitBtn) {
        submitBtn.addEventListener('click', function(event){
            event.preventDefault();
            event.stopImmediatePropagation();
            if (!requestCtx) return;
            var reason = document.getElementById('gongsuRequestReason').value.replace(/^\s+|\s+$/g, '');
            var requestedVal = document.getElementById('gongsuRequestedValue').value.replace(/\s+/g, '');
            if (!/^\d+(\.\d+)?$/.test(requestedVal)) {
                alert('변경 공수는 숫자 형식으로 입력하세요.');
                return;
            }
            var nextValue = parseFloat(requestedVal);
            if (isNaN(nextValue) || nextValue < 0) {
                alert('변경 공수는 0 이상만 가능합니다.');
                return;
            }
            if (!gongsuApprovalExempt && nextValue >= 1.2 && !reason) {
                alert('1.2 이상 공수 수정은 승인 요청사유가 필요합니다.');
                return;
            }
            if (!requestCtx.ctx.projectId || isNaN(parseInt(requestCtx.ctx.projectId, 10)) || parseInt(requestCtx.ctx.projectId, 10) <= 0) {
                alert('프로젝트 정보가 올바르지 않아 저장할 수 없습니다. 페이지를 새로고침한 뒤 다시 시도해주세요.');
                return;
            }
            saveGongsuCell(requestCtx.cell, requestCtx.ctx, nextValue, reason, { deleteMode:false });
        }, true);
    }

    var deleteBtn = document.getElementById('gongsuRequestDelete');
    if (deleteBtn) {
        deleteBtn.addEventListener('click', function(event){
            event.preventDefault();
            event.stopImmediatePropagation();
            if (!requestCtx) return;
            if (!window.confirm('공수를 삭제할까요?')) return;
            saveGongsuCell(requestCtx.cell, requestCtx.ctx, 0, '', { deleteMode:true });
        }, true);
    }
})();
</script>
