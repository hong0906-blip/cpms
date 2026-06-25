<?php
/**
 * - 공사: 노무비 탭
 * - 하위 탭: 공수 / 인원작성
 * - PHP 5.6 호환
 */

$canEditLabor = isset($canEdit) ? (bool)$canEdit : false;
$laborTab = isset($_GET['labor_tab']) ? trim((string)$_GET['labor_tab']) : 'timesheet';
if ($laborTab === '') $laborTab = 'timesheet';

$laborTabs = array(
    'timesheet' => '공수',
    'workers'   => '인원 작성',
);
if (!$canEditLabor && isset($laborTabs['workers'])) unset($laborTabs['workers']);
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

$downloadUrl = base_url() . '/?r=construction/labor_sheet_download&pid=' . (int)$pid . '&month=' . urlencode($selectedMonth);

require_once __DIR__ . '/partials/labor_data_loader.php';

$directTeamMembers = cpms_load_direct_team_members(isset($pdo) ? $pdo : null);
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
if (isset($pdo) && $pdo) {
    try {
        cpms_ensure_labor_override_table($pdo);
        $sql = "SELECT worker_name, work_date, old_value, new_value, reason, reject_reason, status, created_at, rejected_at
                FROM cpms_labor_gongsu_overrides
                WHERE project_id = :pid AND month = :month AND status IN ('pending','rejected')
                ORDER BY created_at DESC
                LIMIT 20";
        $st = $pdo->prepare($sql);
        $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $st->bindValue(':month', $selectedMonth, PDO::PARAM_STR);
        $st->execute();
        $overrideRequestRows = $st->fetchAll();
    } catch (Exception $e) {
        $overrideRequestRows = array();
    }
}

$executiveUsers = array();
if (isset($pdo) && $pdo) {
    try {
        $stExec = $pdo->query("SELECT id, name, email, position FROM employees WHERE role = 'executive' ORDER BY name ASC");
        $executiveUsers = $stExec->fetchAll();
    } catch (Exception $e) {
        $executiveUsers = array();
    }
}

cpms_cleanup_project_labor_workers(isset($pdo) ? $pdo : null, $projectId, $excludedWorkers); // 장비기사 기존 기록 삭제(soft delete)
cpms_sync_project_labor_workers_from_attendance(isset($pdo) ? $pdo : null, $projectId, $attendanceWorkers); // 장비기사 제외
$projectLaborWorkers = cpms_load_project_labor_workers(isset($pdo) ? $pdo : null, $projectId);
$workerRows = cpms_build_project_worker_rows($projectLaborWorkers, $directTeamMembers);
$laborWorkerMonthMap = function_exists('cpms_load_project_labor_worker_month_map') ? cpms_load_project_labor_worker_month_map(isset($pdo) ? $pdo : null, $projectId, $selectedMonth) : array();
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

if (!function_exists('cpms_labor_tab_monthly_pay_total')) {
    function cpms_labor_tab_monthly_pay_total($workers, $gongsuMap, $selectedMonth) {
        $total = 0.0;
        if (!is_array($workers)) return $total;
        foreach ($workers as $worker) {
            $workerName = isset($worker['name']) ? (string)$worker['name'] : '';
            $workerKey = cpms_normalize_worker_key($workerName);
            if ($workerKey === '') continue;
            $dailyMap = (isset($gongsuMap[$workerKey]) && is_array($gongsuMap[$workerKey])) ? $gongsuMap[$workerKey] : array();
            $totalGongsu = 0.0;
            foreach ($dailyMap as $dateKey => $gongsuValue) {
                if (!is_numeric($gongsuValue)) continue;
                if (strpos((string)$dateKey, (string)$selectedMonth) !== 0) continue;
                $totalGongsu += (float)$gongsuValue;
            }
            if ($totalGongsu <= 0) continue;
            $wageRate = function_exists('cpms_resolve_labor_wage_rate') ? (float)cpms_resolve_labor_wage_rate($worker) : 0.0;
            if ($wageRate <= 0) continue;
            $total += $totalGongsu * $wageRate;
        }
        return $total;
    }
}

$canManageLaborForce = (\App\Core\Auth::isMaster() || \App\Core\Auth::userRole() === 'executive');
$laborForceRow = function_exists('cpms_labor_force_load') ? cpms_labor_force_load(isset($pdo) ? $pdo : null, $projectId, $selectedMonth) : array('amount' => 0.0, 'memo' => '');
$laborForceAmount = isset($laborForceRow['amount']) ? (float)$laborForceRow['amount'] : 0.0;
$laborBaseAmount = cpms_labor_tab_monthly_pay_total($timesheetWorkers, $attendanceGongsuMap, $selectedMonth);
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
                        onchange="location.href='?r=공사&pid=<?php echo (int)$pid; ?>&tab=labor&labor_tab=<?php echo h($laborTab); ?>&month=' + encodeURIComponent(this.value)">
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
                    공수 다운로드
                </a>
            <?php else: ?>
                <button type="button"
                        class="px-4 py-2 rounded-2xl bg-gray-200 text-gray-500 font-extrabold cursor-not-allowed"
                        title="<?php echo $canEditLabor ? '해당 월이 종료된 후 다운로드할 수 있습니다.' : '다운로드 권한이 없습니다.'; ?>">
                    공수 다운로드
                </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="mt-5 grid grid-cols-1 <?php echo $canManageLaborForce ? 'md:grid-cols-3' : 'md:grid-cols-2'; ?> gap-3">
        <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4">
            <div class="text-xs font-bold text-gray-500">공수 기준 노무비</div>
            <div class="mt-1 text-xl font-extrabold text-gray-900"><?php echo number_format($laborBaseAmount); ?>원</div>
        </div>
        <?php if ($canManageLaborForce): ?>
        <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4">
            <div class="text-xs font-bold text-gray-500">강제입력 노무비</div>
            <div class="mt-1 text-xl font-extrabold text-gray-900"><?php echo number_format($laborForceAmount); ?>원</div>
        </div>
        <?php endif; ?>
        <div class="rounded-2xl border border-gray-900 bg-gray-900 p-4 text-white">
            <div class="text-xs font-bold text-gray-300">월 노무비 합계</div>
            <div class="mt-1 text-xl font-extrabold"><?php echo number_format($laborTotalAmount); ?>원</div>
        </div>
    </div>

    <?php if ($canManageLaborForce): ?>
        <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/labor_force_save" class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 p-4">
            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
            <input type="hidden" name="month" value="<?php echo h($selectedMonth); ?>">
            <input type="hidden" name="labor_tab" value="<?php echo h($laborTab); ?>">
            <div class="grid grid-cols-1 md:grid-cols-[220px_1fr_auto] gap-3 items-end">
                <div>
                    <label class="text-xs font-bold text-amber-800">임원 전용 강제입력</label>
                    <input type="text" name="amount" value="<?php echo h(number_format($laborForceAmount)); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-amber-200 bg-white text-right font-bold">
                </div>
                <div>
                    <label class="text-xs font-bold text-amber-800">메모</label>
                    <input type="text" name="memo" value="<?php echo h(isset($laborForceRow['memo']) ? $laborForceRow['memo'] : ''); ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-amber-200 bg-white" placeholder="예: 시프티 미등록 인원 노무비 보정">
                </div>
                <button type="submit" class="px-4 py-2 rounded-xl bg-amber-600 text-white font-extrabold">저장</button>
            </div>
            <?php if (isset($laborForceRow['updated_at']) && trim((string)$laborForceRow['updated_at']) !== ''): ?>
                <div class="mt-2 text-xs text-amber-800">최근 저장: <?php echo h($laborForceRow['updated_at']); ?> <?php echo h(isset($laborForceRow['updated_by_name']) ? $laborForceRow['updated_by_name'] : ''); ?></div>
            <?php endif; ?>
        </form>
    <?php endif; ?>
</div>
<?php if (!empty($pendingOverrideRows)): ?>
<div class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
    <div class="font-bold mb-1">공수 수정 승인대기</div>
    <?php foreach ($pendingOverrideRows as $pr): ?>
        <div>- <?php echo h($pr['worker_name']); ?> / <?php echo h($pr['work_date']); ?> / <?php echo h($pr['old_value']); ?> → <?php echo h($pr['new_value']); ?> (승인대기)</div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<div class="mt-3 bg-white rounded-3xl border border-gray-200 p-4 shadow-sm">
    <div class="font-extrabold text-gray-900 text-base">공수 수정 요청 내역</div>
    <div class="mt-3 space-y-2">
        <?php if (empty($overrideRequestRows)): ?>
            <div class="text-sm text-gray-500">요청 내역이 없습니다.</div>
        <?php else: ?>
            <?php foreach ($overrideRequestRows as $rr): ?>
                <?php $isRejected = ((string)$rr['status'] === 'rejected'); ?>
                <div class="rounded-2xl border border-gray-100 p-3 text-sm">
                    <div class="flex flex-wrap items-center gap-2">
                        <?php if ($isRejected): ?>
                            <span class="px-2 py-1 rounded-full bg-red-100 text-red-700 text-xs font-bold">반려</span>
                        <?php else: ?>
                            <span class="px-2 py-1 rounded-full bg-amber-100 text-amber-700 text-xs font-bold">승인대기</span>
                        <?php endif; ?>
                        <span class="text-gray-800 font-bold"><?php echo h($rr['worker_name']); ?></span>
                        <span class="text-gray-500"><?php echo h($rr['work_date']); ?></span>
                    </div>
                    <div class="mt-1 text-gray-700">기존 공수: <?php echo h($rr['old_value']); ?> → 요청 공수: <span class="font-extrabold"><?php echo h($rr['new_value']); ?></span></div>
                    <div class="text-gray-700">요청사유: <?php echo h(trim((string)$rr['reason']) !== '' ? $rr['reason'] : '-'); ?></div>
                    <?php if ($isRejected): ?>
                        <div class="text-red-700">반려사유: <?php echo h(trim((string)$rr['reject_reason']) !== '' ? $rr['reject_reason'] : '-'); ?></div>
                    <?php endif; ?>
                    <div class="text-xs text-gray-500 mt-1">요청일: <?php echo h($rr['created_at']); ?><?php if ($isRejected): ?> · 처리일: <?php echo h($rr['rejected_at']); ?><?php endif; ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
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
    $timesheetRows = $timesheetRows;
    $periodEnd = $periodEnd;
    $timesheetWorkers = $timesheetWorkers;
    $attendanceGongsuMap = $attendanceGongsuMap;
    $attendanceGongsuUnit = $attendanceGongsuUnit;
    $attendanceOutputDays = $attendanceOutputDays;
    $attendanceTimeMap = $attendanceTimeMap;
    $showBankColumns = false;    
    $canEdit = $canEditLabor;
    require __DIR__ . '/partials/labor_sheet_table.php';
    ?>
<?php else: ?>
    <?php $showSensitiveLaborFields = (\App\Core\Auth::isMaster() || \App\Core\Auth::canManageEmployees()); ?>
    <div class="bg-white rounded-3xl border border-gray-200 p-6 shadow-sm">
        <h4 class="text-lg font-extrabold text-gray-900">인원 작성</h4>
        <div class="text-sm text-gray-600 mt-1">임금 단가 및 계좌 정보를 등록합니다.</div>
        <div class="text-xs text-gray-500 mt-2">* 직영팀 인원은 관리팀 섹션의 직영팀 명부에서 선택해 프로젝트에 추가합니다.</div>

        <form id="workforceAddForm" method="post" action="<?php echo h(base_url()); ?>/?r=construction/labor_worker_add" style="display:none;">
            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
            <input type="hidden" name="month" value="<?php echo h($selectedMonth); ?>">
            <input type="hidden" name="labor_tab" value="workers">
            <input type="hidden" name="workforce_worker_id" id="workforceAddWorkerId" value="">
        </form>

        <div class="mt-4 grid gap-3 lg:grid-cols-3">
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4">
                <div class="text-sm font-extrabold text-gray-900">인력관리에서 가져오기</div>
                <div class="text-xs text-gray-600 mt-1">현재 현장 인원작성 명단의 이름을 인력관리와 맞춰 전체 입력합니다.</div>
                <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/labor_workers_save" class="mt-3 flex flex-wrap justify-end gap-2">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
                    <input type="hidden" name="month" value="<?php echo h($selectedMonth); ?>">
                    <input type="hidden" name="labor_tab" value="workers">
                    <button type="submit" name="action" value="apply_workforce_by_name" class="px-4 py-2 rounded-2xl bg-emerald-600 text-white font-extrabold" onclick="return confirm('현재 현장 인원 명단을 인력관리 기준으로 전체 입력할까요?');">
                        현재 명단 전체 가져오기
                    </button>
                    <button type="button" class="px-4 py-2 rounded-2xl border border-emerald-200 bg-white text-emerald-700 font-extrabold" data-workforce-modal-open>
                        개별 검색
                    </button>
                </form>
            </div>

            <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/labor_worker_add" class="rounded-2xl border border-gray-200 p-4">
                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
                <input type="hidden" name="month" value="<?php echo h($selectedMonth); ?>">
                <input type="hidden" name="labor_tab" value="workers">
                <div class="text-sm font-extrabold text-gray-900">직영팀에서 추가</div>
                <div class="text-xs text-gray-500 mt-1">관리부 직영팀 명부에 등록된 인원을 프로젝트에 연결합니다.</div>
                <div class="mt-3">
                    <label class="text-xs font-bold text-gray-500">직영팀 선택</label>
                    <select name="direct_member_id" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-200 text-sm">
                        <option value="">직영팀 선택</option>
                        <?php foreach ($directTeamMembers as $member): ?>
                            <option value="<?php echo (int)$member['id']; ?>">
                                <?php echo h(isset($member['name']) ? $member['name'] : ''); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mt-3 flex justify-end">
                    <button type="submit" class="px-4 py-2 rounded-2xl bg-gray-900 text-white font-extrabold">
                        직영팀 추가
                    </button>
                </div>
            </form>

            <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/labor_worker_add" class="rounded-2xl border border-gray-200 p-4">
                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
                <input type="hidden" name="month" value="<?php echo h($selectedMonth); ?>">
                <input type="hidden" name="labor_tab" value="workers">
                <div class="text-sm font-extrabold text-gray-900">직접 인원 추가</div>
                <div class="text-xs text-gray-500 mt-1">근로자 시프티에서 못 가져온 인원을 직접 추가합니다.</div>
                <div class="mt-3 grid gap-3 md:grid-cols-2">
                    <div>
                        <label class="text-xs font-bold text-gray-500">이름</label>
                        <input name="manual_name" type="text" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-200 text-sm" placeholder="이름 입력">
                    </div>
                    <div>
                        <label class="text-xs font-bold text-gray-500">업체명</label>
                        <input name="manual_company_name" type="text" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-200 text-sm" placeholder="창명건설">
                    </div>
                </div>
                <div class="mt-3 flex justify-end">
                    <button type="submit" class="px-4 py-2 rounded-2xl bg-blue-600 text-white font-extrabold">
                        인원 추가
                    </button>
                </div>
            </form>
        </div>

        <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/labor_workers_save" class="mt-4 flex justify-end">
            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
            <input type="hidden" name="month" value="<?php echo h($selectedMonth); ?>">
            <input type="hidden" name="labor_tab" value="workers">
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

            <div class="overflow-x-auto">
                <table class="min-w-[1000px] w-full border border-gray-200 text-sm">
                    <thead class="bg-gray-100 text-gray-700">
                    <tr>
                        <th class="border border-gray-200 px-2 py-2">성명</th>
                        <th class="border border-gray-200 px-2 py-2">핸드폰 번호</th>
                        <th class="border border-gray-200 px-2 py-2">주소</th>
                        <th class="border border-gray-200 px-2 py-2">구분/직종</th>
                        <th class="border border-gray-200 px-2 py-2">임금단가</th>
                        <?php if ($showSensitiveLaborFields): ?>
                            <th class="border border-gray-200 px-2 py-2">계좌번호</th>
                            <th class="border border-gray-200 px-2 py-2">은행명</th>
                            <th class="border border-gray-200 px-2 py-2">예금주</th>
                        <?php endif; ?>
                        <th class="border border-gray-200 px-2 py-2">인력사업체명</th>
                        <th class="border border-gray-200 px-2 py-2">상태</th>
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
                            $sourceType = isset($member['source_type']) ? trim((string)$member['source_type']) : 'manual';
                            $matchedStatus = isset($member['matched_status']) ? trim((string)$member['matched_status']) : 'manual';
                            $isMonthAssigned = (isset($member['month_assigned']) && (int)$member['month_assigned'] === 1);
                            $statusText = '수동입력';
                            if ($matchedStatus === 'matched') $statusText = '인력관리 등록됨';
                            else if ($matchedStatus === 'duplicate') $statusText = '동명이인 확인 필요';
                            else if ($matchedStatus === 'not_found') $statusText = '인력관리 미등록';
                            if ($isMonthAssigned) $statusText .= ' / ' . $selectedMonth . ' 추가';
                            ?>
                            <tr class="<?php echo ($rowIndex % 2 === 0) ? 'bg-white' : 'bg-gray-50'; ?>">
                                <td class="border border-gray-200 px-2 py-2">
                                    <input type="hidden" name="workers[<?php echo $workerId; ?>][worker_id]" value="<?php echo (int)$masterWorkerId; ?>">
                                    <input type="hidden" name="workers[<?php echo $workerId; ?>][worker_name_snapshot]" value="<?php echo h(isset($member['name']) ? $member['name'] : ''); ?>">
                                    <input type="hidden" name="workers[<?php echo $workerId; ?>][source_type]" value="<?php echo h($sourceType); ?>">
                                    <input type="hidden" name="workers[<?php echo $workerId; ?>][matched_status]" value="<?php echo h($matchedStatus); ?>">
                                    <input class="w-full px-2 py-1 border border-gray-200 rounded-lg bg-gray-100" type="text" value="<?php echo h(isset($member['name']) ? $member['name'] : ''); ?>" placeholder="성명" readonly>
                                </td>
                                <td class="border border-gray-200 px-2 py-2">
                                    <input name="workers[<?php echo $workerId; ?>][phone]" class="w-full px-2 py-1 border border-gray-200 rounded-lg" type="text" value="<?php echo h(isset($member['phone']) ? $member['phone'] : ''); ?>" placeholder="핸드폰 번호">
                                </td>
                                <td class="border border-gray-200 px-2 py-2">
                                    <input name="workers[<?php echo $workerId; ?>][address]" class="w-full px-2 py-1 border border-gray-200 rounded-lg" type="text" value="<?php echo h(isset($member['address']) ? $member['address'] : ''); ?>" placeholder="주소">
                                </td>
                                <td class="border border-gray-200 px-2 py-2">
                                    <input name="workers[<?php echo $workerId; ?>][job_type_snapshot]" class="w-full px-2 py-1 border border-gray-200 rounded-lg" type="text" value="<?php echo h($jobTypeSnapshot); ?>" placeholder="구분/직종">
                                </td>
                                <td class="border border-gray-200 px-2 py-2">
                                    <input name="workers[<?php echo $workerId; ?>][deposit_rate]" class="w-full px-2 py-1 border border-gray-200 rounded-lg" type="text" value="<?php echo h(isset($member['deposit_rate']) ? $member['deposit_rate'] : '0'); ?>" placeholder="임금단가">
                                </td>
                                <?php if ($showSensitiveLaborFields): ?>
                                    <td class="border border-gray-200 px-2 py-2">
                                        <input name="workers[<?php echo $workerId; ?>][bank_account]" class="w-full px-2 py-1 border border-gray-200 rounded-lg" type="text" value="<?php echo h(isset($member['bank_account']) ? $member['bank_account'] : ''); ?>" placeholder="계좌번호">
                                    </td>
                                    <td class="border border-gray-200 px-2 py-2">
                                        <input name="workers[<?php echo $workerId; ?>][bank_name]" class="w-full px-2 py-1 border border-gray-200 rounded-lg" type="text" value="<?php echo h(isset($member['bank_name']) ? $member['bank_name'] : ''); ?>" placeholder="은행명">
                                    </td>
                                    <td class="border border-gray-200 px-2 py-2">
                                        <input name="workers[<?php echo $workerId; ?>][account_holder]" class="w-full px-2 py-1 border border-gray-200 rounded-lg" type="text" value="<?php echo h(isset($member['account_holder']) ? $member['account_holder'] : ''); ?>" placeholder="예금주">
                                    </td>
                                <?php endif; ?>
                                <td class="border border-gray-200 px-2 py-2">
                                    <input name="workers[<?php echo $workerId; ?>][company_name]" class="w-full px-2 py-1 border border-gray-200 rounded-lg" type="text" value="<?php echo h($companyName); ?>" placeholder="인력사업체명">
                                </td>
                                <td class="border border-gray-200 px-2 py-2 text-xs font-bold text-gray-700">
                                    <?php echo h($statusText); ?>
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
                            <td colspan="<?php echo $showSensitiveLaborFields ? 11 : 8; ?>" class="border border-gray-200 px-2 py-6 text-center text-gray-500">등록된 인원이 없습니다.</td>
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

        <div id="workforceSearchModal" class="fixed inset-0 z-50 hidden">
            <div class="absolute inset-0 bg-black/40" data-workforce-modal-close></div>
            <div class="absolute inset-0 flex items-center justify-center p-4">
                <div class="w-full max-w-2xl bg-white rounded-3xl shadow-2xl border border-gray-100 overflow-hidden">
                    <div class="p-5 border-b border-gray-100 flex items-center justify-between">
                        <div>
                            <div class="text-lg font-extrabold text-gray-900">인력관리에서 가져오기</div>
                            <div class="text-xs text-gray-500 mt-1">이름을 검색하고 선택하세요.</div>
                        </div>
                        <button type="button" class="px-3 py-2 rounded-xl border border-gray-200" data-workforce-modal-close>닫기</button>
                    </div>
                    <div class="p-5">
                        <div class="flex gap-2">
                            <input id="workforceSearchInput" class="flex-1 px-4 py-3 rounded-2xl border border-gray-200" placeholder="이름을 입력하세요">
                            <button type="button" id="workforceSearchButton" class="px-4 py-3 rounded-2xl bg-gray-900 text-white font-extrabold">검색</button>
                        </div>
                        <div id="workforceSearchResults" class="mt-4 space-y-2 text-sm"></div>
                    </div>
                </div>
            </div>
        </div>

        <script defer src="<?php echo h(asset_url('assets/js/labor_personnel.js')); ?>"></script>
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

<div id="modal-gongsuRequest" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/40" data-modal-close="gongsuRequest"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
        <div class="w-full max-w-xl bg-white rounded-3xl shadow-2xl border border-gray-100 overflow-hidden">
            <div class="p-6 border-b border-gray-100 flex items-center justify-between">
                <div>
                    <h3 class="text-xl font-extrabold text-gray-900">공수 수정</h3>
                    <div class="text-xs text-gray-500 mt-1">공수는 0.1 단위 입력을 권장합니다.</div>
                </div>
                <button type="button" class="p-3 rounded-2xl hover:bg-gray-50" data-modal-close="gongsuRequest">닫기</button>
            </div>
            <div class="p-6 space-y-4">
                <div class="rounded-2xl border border-amber-100 bg-amber-50 p-4 text-sm text-amber-800 leading-6">
                    <div>1.2 미만은 즉시 반영됩니다.</div>
                    <div>1.2 이상 1.4 미만은 박원덕 상무 승인 후 반영됩니다.</div>
                    <div>1.4 이상은 박원덕 상무 승인 후 부사장 승인까지 완료되어야 반영됩니다.</div>
                </div>
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
                    <label class="text-xs font-bold text-gray-500">요청 사유 입력</label>
                    <textarea id="gongsuRequestReason" class="mt-1 px-4 py-3 rounded-2xl border border-gray-200 w-full" rows="3" placeholder="예: 점심시간 없이 근무"></textarea>
                    <div class="mt-2 text-xs text-gray-500">1.2 이상 공수 수정은 요청 사유가 필요합니다. 예: 점심시간 없이 근무</div>
                </div>
                <div class="flex items-center justify-between gap-2">
                    <button type="button" class="px-4 py-2 rounded-2xl border border-red-200 text-red-600 font-extrabold hidden" id="gongsuRequestDelete">공수 삭제</button>
                    <div class="flex items-center justify-end gap-2 ml-auto">
                        <button type="button" class="px-4 py-2 rounded-2xl border border-gray-200 text-gray-700 font-extrabold" data-modal-close="gongsuRequest">취소</button>
                        <button type="button" class="px-5 py-2 rounded-2xl bg-gray-900 text-white font-extrabold" id="gongsuRequestSubmit">요청/저장</button>
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
            if (nextValue >= 1.2 && !reason) { alert('1.2 이상 공수 수정은 승인 요청사유가 필요합니다.'); return; }
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
    var projectName = <?php echo json_encode(isset($projectRow['name']) ? (string)$projectRow['name'] : ''); ?>;
    var requestCtx = null;
    var addCtx = null;
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
        if (outputDaysCell) outputDaysCell.textContent = String(outputDays);
        if (totalGongsuCell) totalGongsuCell.textContent = formatValue(totalGongsu);
        if (totalPayCell) totalPayCell.textContent = formatMoney(totalGongsu * (isNaN(wageRate) ? 0 : wageRate));
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

    var modalCloseButtons = document.querySelectorAll('[data-modal-close="gongsuRequest"], [data-modal-close="gongsuAddConfirm"]');
    for (var i = 0; i < modalCloseButtons.length; i++) {
        modalCloseButtons[i].addEventListener('click', function(){
            closeModal('modal-' + this.getAttribute('data-modal-close'));
        }, true);
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
            if (nextValue >= 1.2 && !reason) {
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
