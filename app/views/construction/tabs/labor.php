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

$projectId = isset($pid) ? (int)$pid : 0;

if (!function_exists('cpms_apply_labor_overrides_to_map')) {
    function cpms_apply_labor_overrides_to_map($map, $projectId, $month) {
        $rows = cpms_load_labor_overrides((int)$projectId, (string)$month);
        if (!is_array($rows)) return $map;
        foreach ($rows as $workerKey => $dateRows) {
            if (!isset($map[$workerKey]) || !is_array($map[$workerKey])) $map[$workerKey] = array();
            if (!is_array($dateRows)) continue;
            foreach ($dateRows as $dateKey => $entry) {
                if (is_array($entry) && isset($entry['value']) && is_numeric($entry['value'])) {
                    $map[$workerKey][$dateKey] = (float)$entry['value'];
                }
            }
        }
        return $map;
    }
}
$attendanceGongsuMap = cpms_apply_labor_overrides_to_map($attendanceGongsuMap, $projectId, $selectedMonth);
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
$timesheetWorkers = cpms_build_timesheet_workers($workerRows);
// 공수 월별 출력일수 필터(월별 only): 선택 월 output_days > 0 인 사람만 공수 표에 표시
if (is_array($timesheetWorkers)) {
    $filteredTimesheetWorkers = array();
    foreach ($timesheetWorkers as $worker) {
        $workerName = isset($worker['name']) ? (string)$worker['name'] : '';
        $workerKey = cpms_normalize_worker_key($workerName);
        if ($workerKey === '') continue;
        $workerOutputDays = isset($attendanceOutputDays[$workerKey]) ? (int)$attendanceOutputDays[$workerKey] : 0;
        if ($workerOutputDays <= 0) continue;
        $filteredTimesheetWorkers[] = $worker;
    }
    $timesheetWorkers = $filteredTimesheetWorkers;
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
    <div class="text-xs text-gray-500 mt-1">승인대기/반려 내역을 확인합니다.</div>
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
    $showBankColumns = false;    
    require __DIR__ . '/partials/labor_sheet_table.php';
    ?>
<?php else: ?>
    <div class="bg-white rounded-3xl border border-gray-200 p-6 shadow-sm">
        <h4 class="text-lg font-extrabold text-gray-900">인원 작성</h4>
        <div class="text-sm text-gray-600 mt-1">임금 단가 및 계좌 정보를 등록합니다.</div>
        <div class="text-xs text-gray-500 mt-2">* 직영팀 인원은 관리팀 섹션의 직영팀 명부에서 선택해 프로젝트에 추가합니다.</div>

        <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/labor_worker_add" class="mt-4 flex flex-wrap items-end gap-2">
            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
            <input type="hidden" name="month" value="<?php echo h($selectedMonth); ?>">
            <input type="hidden" name="labor_tab" value="workers">
            <div>
                <label class="text-xs font-bold text-gray-500">직영팀 선택</label>
                <select name="direct_member_id" class="mt-1 px-3 py-2 rounded-xl border border-gray-200 text-sm min-w-[220px]">
                    <option value="">직영팀 선택</option>
                    <?php foreach ($directTeamMembers as $member): ?>
                        <option value="<?php echo (int)$member['id']; ?>">
                            <?php echo h(isset($member['name']) ? $member['name'] : ''); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="px-4 py-2 rounded-2xl bg-gray-900 text-white font-extrabold">
                직영팀 추가
            </button>
        </form>

        <!-- 인원작성 저장 기능 -->
        <form method="post" action="<?php echo h(base_url()); ?>/?r=construction/labor_workers_save" class="mt-4">
            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="project_id" value="<?php echo (int)$pid; ?>">
            <input type="hidden" name="month" value="<?php echo h($selectedMonth); ?>">
            <input type="hidden" name="labor_tab" value="workers">

            <div class="overflow-x-auto">
                <table class="min-w-[1100px] w-full border border-gray-200 text-sm">
                    <thead class="bg-gray-100 text-gray-700">
                    <tr>
                        <th class="border border-gray-200 px-2 py-2">성명</th>
                        <th class="border border-gray-200 px-2 py-2">주민등록번호</th>
                        <th class="border border-gray-200 px-2 py-2">핸드폰 번호</th>
                        <th class="border border-gray-200 px-2 py-2">주소</th>
                        <th class="border border-gray-200 px-2 py-2">임금단가</th>
                        <th class="border border-gray-200 px-2 py-2">계좌번호</th>
                        <th class="border border-gray-200 px-2 py-2">은행명</th>
                        <th class="border border-gray-200 px-2 py-2">예금주</th>
                        <th class="border border-gray-200 px-2 py-2">인력사업체명</th>
                        <th class="border border-gray-200 px-2 py-2">삭제</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php $rowIndex = 0; ?>
                    <?php if (!empty($workerRows)): ?>
                        <?php foreach ($workerRows as $row): ?>
                            <?php
                            $member = isset($row['data']) && is_array($row['data']) ? $row['data'] : array();
                            $workerId = isset($row['id']) ? (int)$row['id'] : 0;
                            $companyName = isset($member['company_name']) ? trim((string)$member['company_name']) : '';
                            if ($companyName === '') $companyName = '창명건설';
                            ?>
                            <tr class="<?php echo ($rowIndex % 2 === 0) ? 'bg-white' : 'bg-gray-50'; ?>">
                                <td class="border border-gray-200 px-2 py-2">
                                    <input class="w-full px-2 py-1 border border-gray-200 rounded-lg bg-gray-100" type="text" value="<?php echo h(isset($member['name']) ? $member['name'] : ''); ?>" placeholder="성명" readonly>
                                </td>
                                <td class="border border-gray-200 px-2 py-2">
                                    <input name="workers[<?php echo $workerId; ?>][resident_no]" class="w-full px-2 py-1 border border-gray-200 rounded-lg" type="text" value="<?php echo h(isset($member['resident_no']) ? $member['resident_no'] : ''); ?>" placeholder="주민등록번호">
                                </td>
                                <td class="border border-gray-200 px-2 py-2">
                                    <input name="workers[<?php echo $workerId; ?>][phone]" class="w-full px-2 py-1 border border-gray-200 rounded-lg" type="text" value="<?php echo h(isset($member['phone']) ? $member['phone'] : ''); ?>" placeholder="핸드폰 번호">
                                </td>
                                <td class="border border-gray-200 px-2 py-2">
                                    <input name="workers[<?php echo $workerId; ?>][address]" class="w-full px-2 py-1 border border-gray-200 rounded-lg" type="text" value="<?php echo h(isset($member['address']) ? $member['address'] : ''); ?>" placeholder="주소">
                                </td>
                                <td class="border border-gray-200 px-2 py-2">
                                    <input name="workers[<?php echo $workerId; ?>][deposit_rate]" class="w-full px-2 py-1 border border-gray-200 rounded-lg" type="text" value="<?php echo h(isset($member['deposit_rate']) ? $member['deposit_rate'] : '0'); ?>" placeholder="임금단가">
                                </td>
                                <td class="border border-gray-200 px-2 py-2">
                                    <input name="workers[<?php echo $workerId; ?>][bank_account]" class="w-full px-2 py-1 border border-gray-200 rounded-lg" type="text" value="<?php echo h(isset($member['bank_account']) ? $member['bank_account'] : ''); ?>" placeholder="계좌번호">
                                </td>
                                <td class="border border-gray-200 px-2 py-2">
                                    <input name="workers[<?php echo $workerId; ?>][bank_name]" class="w-full px-2 py-1 border border-gray-200 rounded-lg" type="text" value="<?php echo h(isset($member['bank_name']) ? $member['bank_name'] : ''); ?>" placeholder="은행명">
                                </td>
                                <td class="border border-gray-200 px-2 py-2">
                                    <input name="workers[<?php echo $workerId; ?>][account_holder]" class="w-full px-2 py-1 border border-gray-200 rounded-lg" type="text" value="<?php echo h(isset($member['account_holder']) ? $member['account_holder'] : ''); ?>" placeholder="예금주">
                                </td>
                                <td class="border border-gray-200 px-2 py-2">
                                    <input name="workers[<?php echo $workerId; ?>][company_name]" class="w-full px-2 py-1 border border-gray-200 rounded-lg" type="text" value="<?php echo h($companyName); ?>" placeholder="인력사업체명">
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
                            <td colspan="10" class="border border-gray-200 px-2 py-6 text-center text-gray-500">등록된 인원이 없습니다.</td>
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
    </div>
<?php endif; ?>


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
                <div class="flex items-center justify-end gap-2">
                    <button type="button" class="px-4 py-2 rounded-2xl border border-gray-200 text-gray-700 font-extrabold" data-modal-close="gongsuRequest">취소</button>
                    <button type="button" class="px-5 py-2 rounded-2xl bg-gray-900 text-white font-extrabold" id="gongsuRequestSubmit">요청/저장</button>
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