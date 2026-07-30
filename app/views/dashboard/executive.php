<?php
/**
 * C:\www\cpms\app\views\dashboard\executive.php
 */

require_once __DIR__ . '/../partials/TaskList.php';
require_once __DIR__ . '/../partials/cost_metrics.php';
require_once __DIR__ . '/partials/project_cost_summary_helper.php';
require_once __DIR__ . '/../construction/partials/equipment_gongsu_approval_helper.php';
require_once __DIR__ . '/../tasks/dashboard_sections.php';
require_once __DIR__ . '/../attendance/common.php';
require_once __DIR__ . '/../approval/_common.php';
require_once __DIR__ . '/../common/chat_notification_helpers.php';
require_once __DIR__ . '/../common/company_chat_daily_helpers.php';
require_once __DIR__ . '/notice_board.php';

use App\Core\Db;

$user = \App\Core\Auth::user();
$pdo = Db::pdo();
$userEmail = (string)\App\Core\Auth::userEmail();
$flash = flash_get();

$projectCostFragmentOnly = isset($projectCostFragmentOnly) ? (bool)$projectCostFragmentOnly : false;
$debugProjectCost = isset($_GET['debug_project_cost']) && (string)$_GET['debug_project_cost'] === '1';
$loadProjectCostSummary = $projectCostFragmentOnly || (isset($_GET['load_project_cost']) && (string)$_GET['load_project_cost'] === '1');

$executiveTabKeys = array(
    'main' => true,
    'myTasks' => true,
    'department' => true,
    'approval' => true,
    'siteIssues' => true,
    'progressStatements' => true,
    'attendanceManagement' => true,
);
$activeExecutiveTab = isset($_GET['exec_tab']) ? (string)$_GET['exec_tab'] : '';
if ($activeExecutiveTab === '' && isset($_GET['task_department'])) {
    $activeExecutiveTab = 'department';
}
if ($activeExecutiveTab === '') {
    $activeExecutiveTab = 'main';
}
if ($activeExecutiveTab === 'siteissues' || $activeExecutiveTab === 'site_issues') {
    $activeExecutiveTab = 'siteIssues';
}
if ($activeExecutiveTab === 'attendance' || $activeExecutiveTab === 'attendance_management' || $activeExecutiveTab === 'attendanceManagement') {
    $activeExecutiveTab = 'attendanceManagement';
}
if (!isset($executiveTabKeys[$activeExecutiveTab])) {
    $activeExecutiveTab = 'main';
}

$needsMainData = ($activeExecutiveTab === 'main');
$needsIssueData = ($activeExecutiveTab === 'main' || $activeExecutiveTab === 'siteIssues');
$needsIssueCommentsData = ($activeExecutiveTab === 'siteIssues');
$needsApprovalData = ($activeExecutiveTab === 'approval');
$needsAttendanceData = ($activeExecutiveTab === 'main');

if (!$projectCostFragmentOnly && function_exists('cpms_tasks_process_delayed_notifications')) {
    $delayNotifyNow = time();
    $delayNotifyKey = '_cpms_dashboard_delay_notify_at';
    $delayNotifyLast = isset($_SESSION[$delayNotifyKey]) ? (int)$_SESSION[$delayNotifyKey] : 0;
    if ($delayNotifyLast <= 0 || ($delayNotifyNow - $delayNotifyLast) >= 300) {
        $_SESSION[$delayNotifyKey] = $delayNotifyNow;
        cpms_tasks_process_delayed_notifications($pdo, 20);
        if (function_exists('attendance_process_morning_missing_checkin_notifications')) {
            attendance_process_morning_missing_checkin_notifications($pdo, 20);
        }
        if (function_exists('cpms_company_chat_process_daily_leave')) {
            cpms_company_chat_process_daily_leave($pdo, false);
        }
        if (function_exists('cpms_company_chat_process_daily_leave_additions')) {
            cpms_company_chat_process_daily_leave_additions($pdo);
        }
    }
}

$projectCostCount = (($projectCostFragmentOnly || $needsMainData) && function_exists('cpms_dashboard_project_count')) ? (int)cpms_dashboard_project_count($pdo) : 0;
$projectCostSummary = $loadProjectCostSummary ? cpms_dashboard_project_cost_summary($pdo) : array('project_count' => $projectCostCount, 'projects' => array());

if (!function_exists('cpms_render_executive_project_cost_summary_body')) {
function cpms_render_executive_project_cost_summary_body($projectCostSummary, $debugProjectCost)
{
    ob_start();
    if (empty($projectCostSummary['projects'])) {
        ?>
        <div class="text-sm text-gray-600">표시할 프로젝트가 없습니다.</div>
        <?php
    } else {
        ?>
        <div class="space-y-4 max-h-[34rem] overflow-y-auto">
            <?php foreach ($projectCostSummary['projects'] as $projectRow): ?>
                <?php
                $costRateValue = isset($projectRow['cost_rate']) ? (float)$projectRow['cost_rate'] : 0.0;
                $noSales = isset($projectRow['no_sales']) ? (int)$projectRow['no_sales'] : 0;
                if ($noSales === 1 || $costRateValue > 100) {
                    $rateClass = 'bg-red-50 text-red-700 border-red-100';
                } else if ($costRateValue > 70) {
                    $rateClass = 'bg-orange-50 text-orange-700 border-orange-100';
                } else {
                    $rateClass = 'bg-blue-50 text-blue-700 border-blue-100';
                }
                $targetOver = isset($projectRow['is_target_over']) ? (int)$projectRow['is_target_over'] : 0;
                $monthlyRows = isset($projectRow['monthly_rows']) && is_array($projectRow['monthly_rows']) ? $projectRow['monthly_rows'] : array();
                $firstMonthlyRow = count($monthlyRows) > 0 ? $monthlyRows[0] : null;
                ?>
                <div class="p-4 rounded-2xl border border-gray-200 bg-slate-50">
                    <div class="flex flex-col gap-3">
                        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
                            <div class="font-extrabold text-gray-900"><?php echo h($projectRow['project_name']); ?></div>
                            <?php if ($targetOver === 1): ?>
                                <span class="cpms-chip self-start md:self-auto px-3 py-1 rounded-full bg-red-50 text-red-700 border border-red-100 text-xs font-extrabold">목표초과</span>
                            <?php endif; ?>
                        </div>
                        <div class="grid grid-cols-2 lg:grid-cols-4 gap-2">
                            <div class="rounded-2xl border bg-white p-3">
                                <div class="text-xs text-gray-500 font-bold">총 원가율</div>
                                <div class="mt-1 text-lg font-extrabold <?php echo $rateClass; ?> inline-block px-2 py-1 rounded-xl border"><?php echo h($projectRow['cost_rate_label']); ?></div>
                            </div>
                            <div class="rounded-2xl border bg-white p-3">
                                <div class="text-xs text-gray-500 font-bold">총 매출</div>
                                <div class="mt-1 text-lg font-extrabold text-gray-900"><?php echo h(cpms_dashboard_money(isset($projectRow['sales']) ? $projectRow['sales'] : 0)); ?></div>
                                <div class="mt-1 text-xs text-gray-500"><?php echo h(isset($projectRow['sales_basis']) ? $projectRow['sales_basis'] : ''); ?></div>
                            </div>
                            <div class="rounded-2xl border bg-white p-3">
                                <div class="text-xs text-gray-500 font-bold">총 투입원가</div>
                                <div class="mt-1 text-lg font-extrabold text-gray-900"><?php echo h(cpms_dashboard_money(isset($projectRow['used_total']) ? $projectRow['used_total'] : 0)); ?></div>
                            </div>
                            <div class="rounded-2xl border bg-white p-3">
                                <div class="text-xs text-gray-500 font-bold">총 투입목표금액</div>
                                <div class="mt-1 text-lg font-extrabold text-gray-900"><?php echo h(isset($projectRow['target_amount_label']) ? $projectRow['target_amount_label'] : cpms_dashboard_money(0)); ?></div>
                            </div>
                        </div>
                        <div class="cpms-chip-row text-xs text-gray-600">
                            <span class="cpms-chip px-3 py-1 rounded-full bg-white border border-gray-200 text-gray-700 font-bold">총 원가율 <?php echo h(isset($projectRow['cost_rate_label']) ? $projectRow['cost_rate_label'] : '0%'); ?></span>
                            <span class="cpms-chip px-3 py-1 rounded-full bg-white border border-gray-200 text-gray-700 font-bold">총 매출 <?php echo h(cpms_dashboard_money(isset($projectRow['sales']) ? $projectRow['sales'] : 0)); ?></span>
                            <span class="cpms-chip px-3 py-1 rounded-full bg-white border border-gray-200 text-gray-700 font-bold"><?php echo h(isset($projectRow['sales_basis']) ? $projectRow['sales_basis'] : ''); ?></span>
                            <span class="cpms-chip px-3 py-1 rounded-full bg-white border border-gray-200 text-gray-700 font-bold">총 투입원가 <?php echo h(cpms_dashboard_money(isset($projectRow['used_total']) ? $projectRow['used_total'] : 0)); ?></span>
                            <span class="cpms-chip px-3 py-1 rounded-full bg-white border border-gray-200 text-gray-700 font-bold">총 투입목표금액 <?php echo h(cpms_dashboard_money(isset($projectRow['target_amount']) ? $projectRow['target_amount'] : 0)); ?></span>
                            <?php if ($targetOver === 1): ?>
                                <span class="cpms-chip px-3 py-1 rounded-full bg-red-50 text-red-700 border border-red-100 font-bold">목표초과</span>
                            <?php endif; ?>
                        </div>
                        <div class="cpms-chip-row text-xs text-gray-600">
                            <span class="cpms-chip">노무 <?php echo h(cpms_dashboard_money(isset($projectRow['labor']) ? $projectRow['labor'] : 0)); ?></span>
                            <span class="cpms-chip">장비 <?php echo h(cpms_dashboard_money(isset($projectRow['equipment']) ? $projectRow['equipment'] : 0)); ?></span>
                            <span class="cpms-chip">자재 <?php echo h(cpms_dashboard_money(isset($projectRow['materials']) ? $projectRow['materials'] : 0)); ?></span>
                        </div>
                        <?php if ($debugProjectCost): ?>
                            <div class="rounded-2xl border border-blue-100 bg-blue-50 p-3 text-xs text-blue-900">
                                <div><b>project_id</b>: <?php echo (int)(isset($projectRow['project_id']) ? $projectRow['project_id'] : 0); ?></div>
                                <div><b>project_name</b>: <?php echo h(isset($projectRow['project_name']) ? $projectRow['project_name'] : ''); ?></div>
                                <div><b>monthly_rows count</b>: <?php echo count($monthlyRows); ?></div>
                                <div><b>sales</b>: <?php echo h((string)(isset($projectRow['sales']) ? $projectRow['sales'] : 0)); ?></div>
                                <div><b>used_total</b>: <?php echo h((string)(isset($projectRow['used_total']) ? $projectRow['used_total'] : 0)); ?></div>
                                <div><b>target_amount</b>: <?php echo h((string)(isset($projectRow['target_amount']) ? $projectRow['target_amount'] : 0)); ?></div>
                                <?php if (is_array($firstMonthlyRow)): ?>
                                    <div><b>first monthly row sample</b>: <?php echo h((isset($firstMonthlyRow['label']) ? $firstMonthlyRow['label'] : '-') . ' / sales=' . (isset($firstMonthlyRow['sales']) ? $firstMonthlyRow['sales'] : 0) . ' / used_total=' . (isset($firstMonthlyRow['used_total']) ? $firstMonthlyRow['used_total'] : 0) . ' / target_amount=' . (isset($firstMonthlyRow['target_amount']) ? $firstMonthlyRow['target_amount'] : 0) . ' / rate=' . (isset($firstMonthlyRow['cost_rate_label']) ? $firstMonthlyRow['cost_rate_label'] : '0%')); ?></div>
                                <?php else: ?>
                                    <div><b>first monthly row sample</b>: -</div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <details class="rounded-2xl border border-gray-200 bg-white">
                            <summary class="cursor-pointer px-4 py-3 text-sm font-extrabold text-gray-800">월별 보기</summary>
                            <div class="cpms-responsive-table-wrap border-t border-gray-100">
                                <?php if (count($monthlyRows) === 0): ?>
                                    <div class="p-4 text-sm text-gray-500">월별 데이터가 없습니다.</div>
                                <?php else: ?>
                                    <table class="cpms-responsive-table text-sm">
                                        <thead class="bg-gray-50 text-gray-500">
                                            <tr>
                                                <th class="px-3 py-2 text-left font-bold">월</th>
                                                <th class="px-3 py-2 text-right font-bold">원가율</th>
                                                <th class="px-3 py-2 text-right font-bold">매출</th>
                                                <th class="px-3 py-2 text-left font-bold">매출기준</th>
                                                <th class="px-3 py-2 text-right font-bold">투입원가</th>
                                                <th class="px-3 py-2 text-right font-bold">투입목표금액</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($monthlyRows as $monthRow): ?>
                                                <?php
                                                $monthRate = isset($monthRow['cost_rate']) ? (float)$monthRow['cost_rate'] : 0.0;
                                                $monthNoSales = isset($monthRow['no_sales']) ? (int)$monthRow['no_sales'] : 0;
                                                if ($monthNoSales === 1 || $monthRate > 100) {
                                                    $monthRateClass = 'text-red-700 bg-red-50 border-red-100';
                                                } else if ($monthRate > 70) {
                                                    $monthRateClass = 'text-orange-700 bg-orange-50 border-orange-100';
                                                } else {
                                                    $monthRateClass = 'text-blue-700 bg-blue-50 border-blue-100';
                                                }
                                                $monthTargetAmount = isset($monthRow['target_amount']) ? (float)$monthRow['target_amount'] : 0.0;
                                                $monthUsedTotal = isset($monthRow['used_total']) ? (float)$monthRow['used_total'] : 0.0;
                                                $monthTargetOver = ($monthTargetAmount > 0 && $monthUsedTotal > $monthTargetAmount) ? 1 : 0;
                                                ?>
                                                <tr class="border-t border-gray-100">
                                                    <td class="px-3 py-2 text-gray-700 font-bold"><?php echo h(isset($monthRow['label']) ? $monthRow['label'] : '-'); ?></td>
                                                    <td class="px-3 py-2 text-right">
                                                        <span class="cpms-chip inline-flex px-2 py-1 rounded-xl border text-xs font-extrabold <?php echo $monthRateClass; ?>"><?php echo h(isset($monthRow['cost_rate_label']) ? $monthRow['cost_rate_label'] : '0%'); ?></span>
                                                    </td>
                                                    <td class="px-3 py-2 text-right text-gray-800"><?php echo h(cpms_dashboard_money(isset($monthRow['sales']) ? $monthRow['sales'] : 0)); ?></td>
                                                    <td class="px-3 py-2 text-gray-600"><?php echo h(isset($monthRow['sales_basis']) ? $monthRow['sales_basis'] : ''); ?></td>
                                                    <td class="px-3 py-2 text-right text-gray-800"><?php echo h(cpms_dashboard_money(isset($monthRow['used_total']) ? $monthRow['used_total'] : 0)); ?></td>
                                                    <td class="px-3 py-2 text-right text-gray-800">
                                                        <?php echo h(cpms_dashboard_money(isset($monthRow['target_amount']) ? $monthRow['target_amount'] : 0)); ?>
                                                        <?php if ($monthTargetOver === 1): ?>
                                                            <span class="cpms-chip ml-2 px-2 py-0.5 rounded-full bg-red-50 text-red-700 border border-red-100 font-bold">목표초과</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                            </div>
                        </details>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }
    return ob_get_clean();
}}

$projectCostSummaryBodyHtml = '';
if ($loadProjectCostSummary) {
    $projectCostSummaryBodyHtml = cpms_render_executive_project_cost_summary_body($projectCostSummary, $debugProjectCost);
    $projectCostCount = isset($projectCostSummary['project_count']) ? (int)$projectCostSummary['project_count'] : $projectCostCount;
}

if ($projectCostFragmentOnly) {
    echo $projectCostSummaryBodyHtml !== '' ? $projectCostSummaryBodyHtml : '<div class="text-sm text-gray-600">표시할 프로젝트가 없습니다.</div>';
    return;
}

$issues = array();
$safetyIncidents = array();
$issueCommentsByIssueId = array();
$pendingGongsuOverrides = array();
$pendingEquipmentGongsuOverrides = array();
$myUserId = $needsApprovalData ? cpms_find_employee_id_by_email($pdo, $userEmail) : 0;

$today = attendance_today();
$weekStart = $today;
$weekEnd = $today;
$risk52Month = isset($_GET['risk52_month']) ? trim((string)$_GET['risk52_month']) : substr($today, 0, 7);
$risk52WeekParam = isset($_GET['risk52_week']) ? trim((string)$_GET['risk52_week']) : '';
$risk52WeekSelection = array('month' => substr($today, 0, 7), 'start' => $today, 'end' => $today, 'label' => '', 'range_label' => '', 'options' => array());
if ($needsAttendanceData) {
    $risk52WeekSelection = attendance_month_week_selection($risk52Month, $risk52WeekParam, $today);
    $risk52Month = isset($risk52WeekSelection['month']) ? $risk52WeekSelection['month'] : substr($today, 0, 7);
    $weekStart = isset($risk52WeekSelection['start']) ? $risk52WeekSelection['start'] : $today;
    $weekEnd = isset($risk52WeekSelection['end']) ? $risk52WeekSelection['end'] : $today;
}
$risk52WeekOptions = isset($risk52WeekSelection['options']) && is_array($risk52WeekSelection['options']) ? $risk52WeekSelection['options'] : array();
$risk52WeekLabel = isset($risk52WeekSelection['label']) ? (string)$risk52WeekSelection['label'] : '';
$risk52WeekRangeLabel = isset($risk52WeekSelection['range_label']) ? (string)$risk52WeekSelection['range_label'] : ($weekStart . ' ~ ' . $weekEnd);
$currentLeaveIndex = ($needsAttendanceData && function_exists('approval_current_leave_index')) ? approval_current_leave_index($pdo, $today) : array('by_id' => array(), 'by_email' => array(), 'by_name' => array(), 'people' => array());
$todayHolidayInfo = $needsAttendanceData ? attendance_holiday_info($pdo, $today) : null;
$tomorrowTs = strtotime($today . ' +1 day');
$tomorrow = ($tomorrowTs !== false) ? date('Y-m-d', $tomorrowTs) : date('Y-m-d', strtotime('+1 day'));
$tomorrowLeaveIndex = ($needsAttendanceData && function_exists('approval_current_leave_index')) ? approval_current_leave_index($pdo, $tomorrow) : array('by_id' => array(), 'by_email' => array(), 'by_name' => array(), 'people' => array());
$risk52 = array();
$absent = array();
$workingPeople = array();
$checkedOutPeople = array();
$leavePeople = isset($currentLeaveIndex['people']) && is_array($currentLeaveIndex['people']) ? $currentLeaveIndex['people'] : array();
$leaveTomorrowPeople = isset($tomorrowLeaveIndex['people']) && is_array($tomorrowLeaveIndex['people']) ? $tomorrowLeaveIndex['people'] : array();
$leaveTomorrow = count($leaveTomorrowPeople);
$todayWorkStatus = 0;

if (!function_exists('cpms_executive_dashboard_filter_attendance_excluded')) {
function cpms_executive_dashboard_filter_attendance_excluded($people) {
    if (!is_array($people)) return array();
    $filtered = array();
    foreach ($people as $person) {
        if (attendance_is_excluded_employee($person)) continue;
        $filtered[count($filtered)] = $person;
    }
    return $filtered;
}}
$leavePeople = cpms_executive_dashboard_filter_attendance_excluded($leavePeople);
$leaveTomorrowPeople = cpms_executive_dashboard_filter_attendance_excluded($leaveTomorrowPeople);
$leaveTomorrow = count($leaveTomorrowPeople);

$leaveExTypes = array('월차', '연차', '반차', '오전반차', '오후반차', '월차반차', '연차반차', '오전월차반차', '오후월차반차', '오전연차반차', '오후연차반차', '대체휴무', '기타휴무', '휴무');
$leaveMainTypes = array('월차', '연차', '반차', '오전반차', '오후반차', '월차반차', '연차반차', '오전월차반차', '오후월차반차', '오전연차반차', '오후연차반차');

if (!function_exists('cpms_executive_dashboard_compact_text')) {
function cpms_executive_dashboard_compact_text($value) {
    return str_replace(array(' ', "\t", "\r", "\n"), '', trim((string)$value));
}}
if (!function_exists('cpms_executive_dashboard_is_representative')) {
function cpms_executive_dashboard_is_representative($person) {
    $position = is_array($person) && isset($person['position']) ? cpms_executive_dashboard_compact_text($person['position']) : '';
    if ($position !== '' && strpos($position, '대표') !== false) return true;
    $role = is_array($person) && isset($person['role']) ? strtolower(cpms_executive_dashboard_compact_text($person['role'])) : '';
    return ($role === 'ceo' || ($role !== '' && strpos($role, '대표') !== false));
}}
if (!function_exists('cpms_executive_dashboard_is_executive_person')) {
function cpms_executive_dashboard_is_executive_person($person) {
    if (!is_array($person)) return false;
    $role = isset($person['role']) ? strtolower(trim((string)$person['role'])) : '';
    if ($role === 'executive') return true;
    $position = isset($person['position']) ? cpms_executive_dashboard_compact_text($person['position']) : '';
    if ($position === '') return false;
    $executiveWords = array('회장', '대표', '대표이사', '부사장', '고문', '전무', '상무', '이사', '임원');
    for ($i = 0; $i < count($executiveWords); $i++) {
        if (strpos($position, $executiveWords[$i]) !== false) return true;
    }
    return false;
}}
if (!function_exists('cpms_executive_dashboard_department_label')) {
function cpms_executive_dashboard_department_label($person) {
    if (cpms_executive_dashboard_is_executive_person($person)) return '임원';
    $department = is_array($person) && isset($person['department']) ? trim((string)$person['department']) : '';
    return $department !== '' ? $department : '-';
}}
$currentExecutiveDashboardPerson = array(
    'name' => $user && isset($user['name']) ? $user['name'] : '',
    'role' => $user && isset($user['role']) ? $user['role'] : \App\Core\Auth::userRole(),
    'department' => $user && isset($user['department']) ? $user['department'] : \App\Core\Auth::userDepartment(),
    'position' => $user && isset($user['position']) ? $user['position'] : \App\Core\Auth::userPosition()
);
$hideMyAttendanceSection = cpms_executive_dashboard_is_representative($currentExecutiveDashboardPerson);

$statusBadgeClass = function ($status) {
    if ($status === '처리완료') return 'bg-emerald-50 text-emerald-700 border-emerald-100';
    if ($status === '처리중') return 'bg-blue-50 text-blue-700 border-blue-100';
    return 'bg-rose-50 text-rose-700 border-rose-100';
};

$hideFromTodayAttendanceCards = function ($person) {
    return cpms_executive_dashboard_is_representative($person) || attendance_is_excluded_employee($person);
};

$hideFromTodayAbsentCards = function ($person) {
    return attendance_is_excluded_employee($person);
};

$attendanceTimeLabel = function ($value) {
    $value = trim((string)$value);
    if ($value === '') return '-';
    if (preg_match('/\d{2}:\d{2}/', $value, $m)) return $m[0];
    return $value;
};

if (!function_exists('cpms_render_executive_attendance_modal')) {
function cpms_render_executive_attendance_modal($modalKey, $title, $people, $kind, $attendanceTimeLabel) {
    $modalKey = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$modalKey);
    if (!is_array($people)) $people = array();
    $isPresent = ((string)$kind === 'present');
    $accentClass = $isPresent ? 'text-sky-700 bg-sky-50 border-sky-100' : 'text-rose-700 bg-rose-50 border-rose-100';
    ?>
    <div id="modal-execAttendance-<?php echo h($modalKey); ?>" class="cpms-exec-attendance-modal fixed inset-0 z-50 hidden" role="dialog" aria-modal="true" aria-labelledby="modal-execAttendance-title-<?php echo h($modalKey); ?>">
        <div class="absolute inset-0 bg-slate-950/55" data-exec-attendance-close="<?php echo h($modalKey); ?>"></div>
        <div class="absolute inset-0 flex items-center justify-center p-4">
            <div class="relative w-full max-w-5xl max-h-[88vh] overflow-hidden rounded-3xl bg-white shadow-2xl border border-gray-100">
                <div class="flex items-center justify-between gap-4 px-5 py-4 border-b border-gray-100 bg-gradient-to-r from-slate-50 to-white">
                    <div class="min-w-0">
                        <div id="modal-execAttendance-title-<?php echo h($modalKey); ?>" class="text-xl font-extrabold text-gray-900"><?php echo h($title); ?></div>
                        <div class="mt-1 text-sm text-gray-500">한 줄에 3명씩 표시됩니다.</div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="inline-flex px-3 py-1.5 rounded-full border text-sm font-extrabold <?php echo h($accentClass); ?>"><?php echo count($people); ?>명</span>
                        <button type="button" class="px-3 py-2 rounded-xl border border-gray-200 bg-white text-sm font-extrabold text-gray-700 hover:bg-gray-50" data-exec-attendance-close="<?php echo h($modalKey); ?>">닫기</button>
                    </div>
                </div>
                <div class="p-4 md:p-5 overflow-y-auto max-h-[70vh]">
                    <?php if (count($people) === 0): ?>
                        <div class="p-8 rounded-2xl border border-dashed border-gray-200 bg-gray-50 text-center text-sm font-bold text-gray-500"><?php echo h($title); ?> 명단이 없습니다.</div>
                    <?php else: ?>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                            <?php foreach ($people as $personIndex => $person): ?>
                                <?php
                                $isExtraPerson = ((int)$personIndex >= 10);
                                $positionLabel = isset($person['position']) && trim((string)$person['position']) !== '' ? $person['position'] : '-';
                                ?>
                                <article class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm hover:shadow-md transition <?php echo $isExtraPerson ? 'hidden' : ''; ?>" <?php echo $isExtraPerson ? 'data-exec-attendance-extra="' . h($modalKey) . '"' : ''; ?>>
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <div class="text-base font-extrabold text-gray-900 truncate"><?php echo h(isset($person['name']) ? $person['name'] : '-'); ?></div>
                                            <div class="mt-1 text-xs text-gray-500 break-words"><?php echo h(cpms_executive_dashboard_department_label($person)); ?> · <?php echo h($positionLabel); ?></div>
                                        </div>
                                        <span class="shrink-0 inline-flex w-9 h-9 items-center justify-center rounded-xl border font-black <?php echo h($accentClass); ?>"><?php echo $isPresent ? '출' : '미'; ?></span>
                                    </div>
                                    <?php if ($isPresent): ?>
                                        <?php
                                        $checkInLabel = call_user_func($attendanceTimeLabel, isset($person['check_in']) ? $person['check_in'] : '');
                                        $checkOutLabel = call_user_func($attendanceTimeLabel, isset($person['check_out']) ? $person['check_out'] : '');
                                        ?>
                                        <div class="mt-3 grid grid-cols-2 gap-2 text-xs">
                                            <div class="rounded-xl bg-sky-50 px-3 py-2"><span class="block text-sky-600 font-bold">출근</span><b class="text-sky-900"><?php echo h($checkInLabel); ?></b></div>
                                            <div class="rounded-xl bg-slate-50 px-3 py-2"><span class="block text-slate-500 font-bold">퇴근</span><b class="text-slate-800"><?php echo h($checkOutLabel); ?></b></div>
                                        </div>
                                    <?php else: ?>
                                        <div class="mt-3 rounded-xl bg-rose-50 px-3 py-2 text-xs font-bold text-rose-700">오늘 출근 기록 없음</div>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                        <?php if (count($people) > 10): ?>
                            <div class="mt-5 text-center">
                                <button type="button" class="inline-flex items-center justify-center px-5 py-3 rounded-2xl bg-gray-900 text-white text-sm font-extrabold shadow-lg shadow-gray-900/10" data-exec-attendance-more="<?php echo h($modalKey); ?>">더보기 (<?php echo count($people) - 10; ?>명)</button>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}}

if (!function_exists('cpms_render_executive_work_status_modal')) {
function cpms_render_executive_work_status_modal($workingPeople, $checkedOutPeople, $attendanceTimeLabel) {
    if (!is_array($workingPeople)) $workingPeople = array();
    if (!is_array($checkedOutPeople)) $checkedOutPeople = array();
    $totalPeople = count($workingPeople);
    ?>
    <div id="modal-execAttendance-todayWorkStatus" class="cpms-exec-attendance-modal fixed inset-0 z-50 hidden" role="dialog" aria-modal="true" aria-labelledby="modal-execAttendance-title-todayWorkStatus">
        <div class="absolute inset-0 bg-slate-950/55" data-exec-attendance-close="todayWorkStatus"></div>
        <div class="absolute inset-0 flex items-center justify-center p-4">
            <div class="relative w-full max-w-6xl max-h-[90vh] overflow-hidden rounded-3xl bg-white shadow-2xl border border-gray-100">
                <div class="flex items-center justify-between gap-4 px-5 py-4 border-b border-gray-100 bg-gradient-to-r from-emerald-50 to-white">
                    <div class="min-w-0">
                        <div id="modal-execAttendance-title-todayWorkStatus" class="text-xl font-extrabold text-gray-900">근무 현황</div>
                        <div class="mt-1 text-sm text-gray-500">근무중 인원과 퇴근한 사람을 구분해 표시합니다.</div>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <span class="inline-flex px-3 py-1.5 rounded-full border border-emerald-100 bg-emerald-50 text-sm font-extrabold text-emerald-700"><?php echo $totalPeople; ?>명</span>
                        <button type="button" class="px-3 py-2 rounded-xl border border-gray-200 bg-white text-sm font-extrabold text-gray-700 hover:bg-gray-50" data-exec-attendance-close="todayWorkStatus">닫기</button>
                    </div>
                </div>
                <div class="p-4 md:p-5 overflow-y-auto max-h-[76vh] space-y-6">
                    <section>
                        <div class="flex items-center justify-between gap-3 mb-3">
                            <h4 class="text-lg font-extrabold text-emerald-800">근무중 인원</h4>
                            <span class="px-3 py-1 rounded-full bg-emerald-50 border border-emerald-100 text-sm font-extrabold text-emerald-700"><?php echo count($workingPeople); ?>명</span>
                        </div>
                        <?php if (count($workingPeople) === 0): ?>
                            <div class="p-5 rounded-2xl border border-dashed border-emerald-200 bg-emerald-50/50 text-center text-sm font-bold text-emerald-700">현재 근무중인 인원이 없습니다.</div>
                        <?php else: ?>
                            <div class="grid grid-cols-2 lg:grid-cols-4 gap-1.5 sm:gap-2">
                                <?php foreach ($workingPeople as $person): ?>
                                    <?php $checkInLabel = call_user_func($attendanceTimeLabel, isset($person['check_in']) ? $person['check_in'] : ''); ?>
                                    <div class="flex items-center justify-between gap-2 px-2.5 py-2.5 sm:px-3 sm:py-3 rounded-xl border border-gray-200 bg-white">
                                        <div class="min-w-0">
                                            <div class="text-sm sm:text-base font-extrabold text-gray-900 truncate"><?php echo h(isset($person['name']) ? $person['name'] : '-'); ?></div>
                                            <div class="mt-0.5 text-[10px] sm:text-xs text-gray-500 truncate"><?php echo h(cpms_executive_dashboard_department_label($person)); ?> · <?php echo h(isset($person['position']) && trim((string)$person['position']) !== '' ? $person['position'] : '-'); ?></div>
                                        </div>
                                        <div class="shrink-0 text-right text-[10px] sm:text-xs text-gray-500">출근 <b class="sm:ml-1 text-emerald-800"><?php echo h($checkInLabel); ?></b></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="pt-5 border-t border-gray-200">
                        <div class="flex items-center justify-between gap-3 mb-3">
                            <h4 class="text-lg font-extrabold text-slate-800">퇴근한 사람</h4>
                            <span class="px-3 py-1 rounded-full bg-slate-100 border border-slate-200 text-sm font-extrabold text-slate-700"><?php echo count($checkedOutPeople); ?>명</span>
                        </div>
                        <?php if (count($checkedOutPeople) === 0): ?>
                            <div class="p-5 rounded-2xl border border-dashed border-slate-200 bg-slate-50 text-center text-sm font-bold text-slate-600">오늘 퇴근한 사람이 없습니다.</div>
                        <?php else: ?>
                            <div class="grid grid-cols-2 lg:grid-cols-4 gap-1.5 sm:gap-2">
                                <?php foreach ($checkedOutPeople as $person): ?>
                                    <?php
                                    $checkInLabel = call_user_func($attendanceTimeLabel, isset($person['check_in']) ? $person['check_in'] : '');
                                    $checkOutLabel = call_user_func($attendanceTimeLabel, isset($person['check_out']) ? $person['check_out'] : '');
                                    ?>
                                    <div class="flex items-center justify-between gap-2 px-2.5 py-2.5 sm:px-3 sm:py-3 rounded-xl border border-gray-200 bg-white">
                                        <div class="min-w-0">
                                            <div class="text-sm sm:text-base font-extrabold text-gray-900 truncate"><?php echo h(isset($person['name']) ? $person['name'] : '-'); ?></div>
                                            <div class="mt-0.5 text-[10px] sm:text-xs text-gray-500 truncate"><?php echo h(cpms_executive_dashboard_department_label($person)); ?> · <?php echo h(isset($person['position']) && trim((string)$person['position']) !== '' ? $person['position'] : '-'); ?></div>
                                        </div>
                                        <div class="shrink-0 text-right text-[10px] sm:text-xs leading-4 sm:leading-5 text-gray-500">
                                            <div>출근 <b class="sm:ml-1 text-sky-800"><?php echo h($checkInLabel); ?></b></div>
                                            <div>퇴근 <b class="sm:ml-1 text-slate-800"><?php echo h($checkOutLabel); ?></b></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                </div>
            </div>
        </div>
    </div>
    <?php
}}

if ($pdo) {
    if ($needsIssueData) {
    try {
        $issuesSql = "SELECT i.*, p.name AS project_name
                      FROM cpms_project_issues i
                      LEFT JOIN cpms_projects p ON p.id = i.project_id
                      WHERE COALESCE(i.status, '') NOT IN ('처리완료','완료','done','closed','complete')
                      ORDER BY i.id DESC
                      LIMIT 20";
        $issues = $pdo->query($issuesSql)->fetchAll();
    } catch (Exception $e) {
        $issues = array();
    }
    }

    if ($needsIssueData) {
    try {
        $safetySql = "SELECT i.*, p.name AS project_name
                      FROM cpms_safety_incidents i
                      LEFT JOIN cpms_projects p ON p.id = i.project_id
                      WHERE COALESCE(i.status, '') NOT IN ('처리완료','완료','done','closed','complete')
                      ORDER BY i.id DESC
                      LIMIT 10";
        $safetyIncidents = $pdo->query($safetySql)->fetchAll();
    } catch (Exception $e) {
        $safetyIncidents = array();
    }
    }

    if ($needsIssueCommentsData && count($issues) > 0) {
        try {
            $issueIds = array();
            foreach ($issues as $issueRow) $issueIds[] = (int)$issueRow['id'];
            $issueIds = array_values(array_unique($issueIds));
            if (count($issueIds) > 0) {
                $placeholders = implode(',', array_fill(0, count($issueIds), '?'));
                $sqlComments = "SELECT issue_id, comment_text AS comment_body, created_by_email, created_by_name, created_at
                                FROM cpms_project_issue_comments
                                WHERE issue_id IN (" . $placeholders . ")
                                ORDER BY id ASC";
                $stc = $pdo->prepare($sqlComments);
                foreach ($issueIds as $idx => $iid) {
                    $stc->bindValue($idx + 1, (int)$iid, PDO::PARAM_INT);
                }
                $stc->execute();
                foreach ($stc->fetchAll() as $cr) {
                    $key = (int)$cr['issue_id'];
                    if (!isset($issueCommentsByIssueId[$key])) $issueCommentsByIssueId[$key] = array();
                    $issueCommentsByIssueId[$key][] = $cr;
                }
            }
        } catch (Exception $e) {
            $issueCommentsByIssueId = array();
        }
    }

    if ($needsAttendanceData) {
    try {
        $sql = "SELECT e.id, e.name, e.department, e.position, e.role, COALESCE(SUM(a.work_minutes), 0) AS m
                FROM employees e
                LEFT JOIN cpms_attendance_records a
                  ON a.employee_id = e.id
                 AND a.work_date BETWEEN :s AND :e
                WHERE e.is_active = 1
                GROUP BY e.id, e.name, e.department, e.position, e.role";
        $st = $pdo->prepare($sql);
        $st->execute(array(':s' => $weekStart, ':e' => $weekEnd));
        foreach ($st->fetchAll() as $r) {
            if (attendance_is_excluded_employee($r)) continue;
            if ((int)$r['m'] > 3120) $risk52[] = $r;
        }

        $leaveExMap = isset($currentLeaveIndex['by_id']) && is_array($currentLeaveIndex['by_id']) ? $currentLeaveIndex['by_id'] : array();
        $leaveExNameMap = isset($currentLeaveIndex['by_name']) && is_array($currentLeaveIndex['by_name']) ? $currentLeaveIndex['by_name'] : array();

        $activeRows = $pdo->query("SELECT id, name, department, position, role FROM employees WHERE is_active = 1")->fetchAll();
        $stAtt = $pdo->prepare("SELECT employee_id, MIN(check_in) AS check_in, MAX(check_out) AS check_out
                                FROM cpms_attendance_records
                                WHERE work_date = ?
                                  AND (check_in IS NOT NULL OR status IN ('출근중','퇴근완료'))
                                GROUP BY employee_id");
        $stAtt->execute(array($today));
        $attMap = array();
        foreach ($stAtt->fetchAll(PDO::FETCH_ASSOC) as $attRow) {
            $attMap[(int)$attRow['employee_id']] = array(
                'check_in' => isset($attRow['check_in']) ? $attRow['check_in'] : '',
                'check_out' => isset($attRow['check_out']) ? $attRow['check_out'] : '',
            );
        }
        foreach ($activeRows as $ar) {
            if ($hideFromTodayAttendanceCards($ar)) continue;

            $eid = (int)$ar['id'];
            if (isset($attMap[$eid])) {
                $presentPerson = array(
                    'name' => $ar['name'],
                    'department' => $ar['department'],
                    'position' => $ar['position'],
                    'role' => isset($ar['role']) ? $ar['role'] : '',
                    'check_in' => isset($attMap[$eid]['check_in']) ? $attMap[$eid]['check_in'] : '',
                    'check_out' => isset($attMap[$eid]['check_out']) ? $attMap[$eid]['check_out'] : '',
                );
                $employeeName = isset($ar['name']) ? trim((string)$ar['name']) : '';
                $isOnLeaveToday = isset($leaveExMap[$eid]) || ($employeeName !== '' && isset($leaveExNameMap[$employeeName]));
                $checkIn = isset($attMap[$eid]['check_in']) ? trim((string)$attMap[$eid]['check_in']) : '';
                $checkOut = isset($attMap[$eid]['check_out']) ? trim((string)$attMap[$eid]['check_out']) : '';
                if (!$isOnLeaveToday && $checkIn !== '' && $checkOut === '') {
                    $workingPeople[] = $presentPerson;
                }
                if ($checkIn !== '' && $checkOut !== '') {
                    $checkedOutPeople[] = $presentPerson;
                }
                continue;
            }
            if (isset($attMap[$eid]) || isset($leaveExMap[$eid])) continue;
            if (is_array($todayHolidayInfo)) continue;
            if ($hideFromTodayAbsentCards($ar)) continue;
            $absent[] = array(
                'name' => $ar['name'],
                'department' => $ar['department'],
                'position' => $ar['position'],
                'role' => isset($ar['role']) ? $ar['role'] : '',
            );
        }
        $attendanceSortTimeKey = function ($value) {
            $value = trim((string)$value);
            if ($value === '') return '99:99:99';
            if (preg_match('/(\d{1,2}):(\d{2})(?::(\d{2}))?/', $value, $match)) {
                $hour = isset($match[1]) ? (int)$match[1] : 99;
                $minute = isset($match[2]) ? (int)$match[2] : 99;
                $second = isset($match[3]) ? (int)$match[3] : 0;
                return sprintf('%02d:%02d:%02d', $hour, $minute, $second);
            }
            return '99:99:99';
        };
        usort($workingPeople, function ($a, $b) use ($attendanceSortTimeKey) {
            $aCheckIn = $attendanceSortTimeKey(isset($a['check_in']) ? $a['check_in'] : '');
            $bCheckIn = $attendanceSortTimeKey(isset($b['check_in']) ? $b['check_in'] : '');
            $timeCompare = strcmp($aCheckIn, $bCheckIn);
            if ($timeCompare !== 0) return $timeCompare;
            $aName = isset($a['name']) ? (string)$a['name'] : '';
            $bName = isset($b['name']) ? (string)$b['name'] : '';
            return strcmp($aName, $bName);
        });
        usort($checkedOutPeople, function ($a, $b) use ($attendanceSortTimeKey) {
            $aCheckOut = $attendanceSortTimeKey(isset($a['check_out']) ? $a['check_out'] : '');
            $bCheckOut = $attendanceSortTimeKey(isset($b['check_out']) ? $b['check_out'] : '');
            $timeCompare = strcmp($aCheckOut, $bCheckOut);
            if ($timeCompare !== 0) return $timeCompare;
            $aName = isset($a['name']) ? (string)$a['name'] : '';
            $bName = isset($b['name']) ? (string)$b['name'] : '';
            return strcmp($aName, $bName);
        });
        usort($absent, function ($a, $b) {
            $aName = isset($a['name']) ? (string)$a['name'] : '';
            $bName = isset($b['name']) ? (string)$b['name'] : '';
            return strcmp($aName, $bName);
        });
        $todayWorkStatus = count($workingPeople);

        $leavePeople = isset($currentLeaveIndex['people']) && is_array($currentLeaveIndex['people']) ? cpms_executive_dashboard_filter_attendance_excluded($currentLeaveIndex['people']) : array();
        $leaveTomorrowPeople = isset($tomorrowLeaveIndex['people']) && is_array($tomorrowLeaveIndex['people']) ? cpms_executive_dashboard_filter_attendance_excluded($tomorrowLeaveIndex['people']) : array();
        $leaveTomorrow = count($leaveTomorrowPeople);
    } catch (Exception $e) {
    }
    }

    if ($needsApprovalData) {
    try {
        cpms_ensure_labor_override_table($pdo);
        $sql = "SELECT o.id, o.project_id, o.month, o.batch_token, o.request_scope, o.worker_name, o.work_date, o.old_value, o.new_value, o.reason, o.status,
                       o.requested_by, o.requested_by_email, o.requested_by_name, o.approval_stage,
                       o.current_approver_employee_id, o.current_approver_email, o.created_at, o.updated_at,
                       p.name AS project_name, e.name AS requested_emp_name
                FROM cpms_labor_gongsu_overrides o
                LEFT JOIN cpms_projects p ON p.id = o.project_id
                LEFT JOIN employees e ON e.id = o.requested_by
                WHERE o.status = 'pending'
                  AND (o.current_approver_employee_id = :my_employee_id OR LOWER(o.current_approver_email) = LOWER(:my_email))
                ORDER BY o.updated_at DESC, o.id DESC";
        $st = $pdo->prepare($sql);
        $st->bindValue(':my_employee_id', (int)$myUserId, PDO::PARAM_INT);
        $st->bindValue(':my_email', (string)$userEmail, PDO::PARAM_STR);
        $st->execute();
        $pendingGongsuOverrides = cpms_labor_group_override_rows($st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        $pendingGongsuOverrides = array();
    }
    }

    if ($needsApprovalData) {
    try {
        cpms_equipment_gongsu_ensure_schema($pdo);
        $sqlEq = "SELECT o.*, p.name AS project_name, e.vendor_name, e.spec
                  FROM cpms_equipment_gongsu_overrides o
                  LEFT JOIN cpms_projects p ON p.id = o.project_id
                  LEFT JOIN cpms_equipment_items e ON e.id = o.equipment_id
                  WHERE o.status = 'pending'
                    AND (o.current_approver_employee_id = :my_employee_id OR LOWER(o.current_approver_email) = LOWER(:my_email))
                  ORDER BY o.created_at DESC";
        $stEq = $pdo->prepare($sqlEq);
        $stEq->bindValue(':my_employee_id', (int)$myUserId, PDO::PARAM_INT);
        $stEq->bindValue(':my_email', (string)$userEmail, PDO::PARAM_STR);
        $stEq->execute();
        $pendingEquipmentGongsuOverrides = $stEq->fetchAll();
    } catch (Exception $e) {
        $pendingEquipmentGongsuOverrides = array();
    }
    }
}

$executiveTabBaseUrl = base_url() . '/?r=dashboard_executive';
$executiveTaskReturnUrl = '?r=dashboard_executive&exec_tab=' . urlencode($activeExecutiveTab);
if (isset($_GET['task_department']) && trim((string)$_GET['task_department']) !== '') {
    $executiveTaskReturnUrl .= '&task_department=' . urlencode(trim((string)$_GET['task_department']));
}
?>

<style>
  @media (min-width: 1024px) {
    .cpms-exec-dashboard .text-4xl { font-size: 1.75rem; line-height: 2rem; }
    .cpms-exec-dashboard .text-3xl { font-size: 1.5rem; line-height: 1.8rem; }
    .cpms-exec-dashboard .text-2xl { font-size: 1.25rem; line-height: 1.55rem; }
    .cpms-exec-dashboard .text-xl { font-size: 1.1rem; line-height: 1.45rem; }
    .cpms-exec-dashboard .text-lg { font-size: .95rem; line-height: 1.4rem; }
    .cpms-exec-dashboard .p-8 { padding: 1.25rem; }
    .cpms-exec-dashboard .p-7 { padding: 1.125rem; }
    .cpms-exec-dashboard .p-6 { padding: 1rem; }
    .cpms-exec-dashboard .gap-6 { gap: 1rem; }
    .cpms-exec-dashboard .mb-8 { margin-bottom: 1.25rem; }
    .cpms-exec-dashboard .mb-6 { margin-bottom: 1rem; }
    .cpms-exec-dashboard .rounded-3xl { border-radius: 1.125rem; }
  }
  details.cpms-approval-collapsible > summary .cpms-collapse-close-label { display: none; }
  details.cpms-approval-collapsible[open] > summary .cpms-collapse-open-label { display: none; }
  details.cpms-approval-collapsible[open] > summary .cpms-collapse-close-label { display: inline; }
</style>
<div class="cpms-exec-dashboard">
<div class="cpms-dashboard-hero bg-gradient-to-r from-indigo-600 to-purple-500 rounded-3xl p-5 text-white shadow-xl shadow-indigo-500/20 mb-5">
    <div class="flex flex-wrap items-start gap-4">
        <div class="p-3 bg-white/20 rounded-2xl border border-white/20">
            <i data-lucide="layout-dashboard" class="w-6 h-6 text-yellow-200"></i>
        </div>
        <div class="flex-1">
            <h2 class="text-2xl font-extrabold">임원 대시보드</h2>
        </div>
        <?php
        $cpmsAttendanceActionsShowRequest = false;
        require __DIR__ . '/partials/attendance_actions.php';
        unset($cpmsAttendanceActionsShowRequest);
        ?>
        <div class="hidden md:flex flex-wrap items-center justify-end gap-3">
            <button type="button" data-modal-open="meetingCreate" class="px-4 py-2 rounded-xl bg-white/20 text-white font-extrabold text-sm border border-white/30 hover:bg-white/30">회의 요청</button>
            <button type="button" data-modal-open="taskCreate" class="px-4 py-2 rounded-xl bg-white text-indigo-700 font-extrabold text-sm shadow-lg shadow-indigo-900/10">업무 요청</button>
        </div>
    </div>
</div>

<?php if ($activeExecutiveTab === 'main'): ?>
    <?php cpms_render_dashboard_birthday_modal($pdo); ?>
<?php endif; ?>

<div class="md:hidden grid grid-cols-2 gap-2 mb-6">
    <button type="button" data-modal-open="taskCreate" class="min-h-[54px] rounded-[14px] bg-gray-900 text-white font-extrabold text-sm flex flex-col items-center justify-center gap-1">
        <i data-lucide="send" class="w-5 h-5"></i>
        <span>업무요청</span>
    </button>
    <button type="button" data-modal-open="meetingCreate" class="min-h-[54px] rounded-[14px] bg-blue-600 text-white font-extrabold text-sm flex flex-col items-center justify-center gap-1">
        <i data-lucide="calendar-plus" class="w-5 h-5"></i>
        <span>회의요청</span>
    </button>
</div>

<?php
$executiveEmployeeTaskAvailable = true;
if ($activeExecutiveTab === 'myTasks' && function_exists('cpms_tasks_current_employee')) {
    $executiveEmployeeTaskUser = cpms_tasks_current_employee($pdo);
    $executiveEmployeeTaskAvailable = (isset($executiveEmployeeTaskUser['id']) && (int)$executiveEmployeeTaskUser['id'] > 0);
}
if ($activeExecutiveTab !== 'myTasks' || !$executiveEmployeeTaskAvailable) cpms_render_task_request_modals($pdo, $executiveTaskReturnUrl);
?>

<?php if ($flash): ?>
    <div class="mb-4 p-4 rounded-2xl border <?php echo ($flash['type'] === 'success') ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-red-50 border-red-200 text-red-700'; ?>">
        <?php echo h($flash['message']); ?>
    </div>
<?php endif; ?>

<style>
  .cpms-exec-tab-btn[aria-selected="true"] {
    background: #111827;
    border-color: #111827;
    color: #fff;
    box-shadow: 0 10px 24px rgba(15, 23, 42, .14);
  }
  .cpms-exec-tab-btn[aria-selected="false"] {
    background: #fff;
    border-color: #e5e7eb;
    color: #334155;
  }
</style>

<div class="mb-4 flex flex-wrap items-center gap-2" role="tablist" aria-label="임원 대시보드 탭">
    <a href="<?php echo h($executiveTabBaseUrl . '&exec_tab=main'); ?>" role="tab" class="cpms-exec-tab-btn px-4 py-2 rounded-xl border text-sm font-extrabold transition" data-executive-tab="main" aria-selected="<?php echo ($activeExecutiveTab === 'main') ? 'true' : 'false'; ?>">메인</a>
    <a href="<?php echo h($executiveTabBaseUrl . '&exec_tab=myTasks'); ?>" role="tab" class="cpms-exec-tab-btn px-4 py-2 rounded-xl border text-sm font-extrabold transition" data-executive-tab="myTasks" aria-selected="<?php echo ($activeExecutiveTab === 'myTasks') ? 'true' : 'false'; ?>">나의할일</a>
    <a href="<?php echo h($executiveTabBaseUrl . '&exec_tab=department'); ?>" role="tab" class="cpms-exec-tab-btn px-4 py-2 rounded-xl border text-sm font-extrabold transition" data-executive-tab="department" aria-selected="<?php echo ($activeExecutiveTab === 'department') ? 'true' : 'false'; ?>">부서별 업무현황</a>
    <a href="<?php echo h($executiveTabBaseUrl . '&exec_tab=approval'); ?>" role="tab" class="cpms-exec-tab-btn px-4 py-2 rounded-xl border text-sm font-extrabold transition" data-executive-tab="approval" aria-selected="<?php echo ($activeExecutiveTab === 'approval') ? 'true' : 'false'; ?>">승인대기</a>
    <a href="<?php echo h($executiveTabBaseUrl . '&exec_tab=siteIssues'); ?>" role="tab" class="cpms-exec-tab-btn px-4 py-2 rounded-xl border text-sm font-extrabold transition" data-executive-tab="siteIssues" aria-selected="<?php echo ($activeExecutiveTab === 'siteIssues') ? 'true' : 'false'; ?>">현장별 이슈</a>
    <a href="<?php echo h($executiveTabBaseUrl . '&exec_tab=progressStatements'); ?>" role="tab" class="cpms-exec-tab-btn px-4 py-2 rounded-xl border text-sm font-extrabold transition" data-executive-tab="progressStatements" aria-selected="<?php echo ($activeExecutiveTab === 'progressStatements') ? 'true' : 'false'; ?>">기성내역서 현황</a>
    <a href="<?php echo h($executiveTabBaseUrl . '&exec_tab=attendanceManagement'); ?>" role="tab" class="cpms-exec-tab-btn px-4 py-2 rounded-xl border text-sm font-extrabold transition" data-executive-tab="attendanceManagement" aria-selected="<?php echo ($activeExecutiveTab === 'attendanceManagement') ? 'true' : 'false'; ?>">출퇴근 근태관리</a>
</div>

<div data-executive-tab-panels>
<?php if ($activeExecutiveTab === 'main'): ?>
<section data-executive-tab-panel="main">

<?php
$cpmsEmployeeAttendanceFormHiddenInputs = array('r' => 'dashboard_executive', 'exec_tab' => 'main');
$cpmsEmployeeAttendanceReturnUrl = '?r=dashboard_executive&exec_tab=main';
$cpmsEmployeeAttendanceShowFlash = false;
if (!$hideMyAttendanceSection) {
    require __DIR__ . '/partials/employee_attendance_section.php';
}
unset($cpmsEmployeeAttendanceFormHiddenInputs, $cpmsEmployeeAttendanceReturnUrl, $cpmsEmployeeAttendanceShowFlash);
?>

<div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-6">
    <div class="bg-white/80 rounded-3xl p-6 border overflow-visible">
        <h3 class="text-2xl font-extrabold mb-4">근태 리스크 현황</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <button type="button" class="group min-w-0 p-4 rounded-2xl bg-rose-50 border border-rose-100 text-left hover:-translate-y-0.5 hover:shadow-lg hover:shadow-rose-100/70 transition" data-exec-attendance-open="todayAbsent" aria-haspopup="dialog">
                <div class="font-bold text-gray-600 group-hover:text-rose-700 whitespace-nowrap leading-tight tracking-tight" style="font-size:clamp(.68rem,.9vw,.95rem)">오늘 미출근자 수</div>
                <div class="flex items-end justify-between gap-2 mt-3">
                    <div class="font-extrabold text-rose-700 leading-none" style="font-size:clamp(1.75rem,2.3vw,2.25rem)"><?php echo count($absent); ?>명</div>
                    <span class="shrink-0 inline-flex items-center px-2 py-1.5 rounded-full bg-white text-rose-700 border border-rose-100 text-[11px] font-extrabold">명단 보기</span>
                </div>
            </button>
            <button type="button" class="group min-w-0 p-4 rounded-2xl bg-emerald-50 border border-emerald-100 text-left hover:-translate-y-0.5 hover:shadow-lg hover:shadow-emerald-100/70 transition" data-exec-attendance-open="todayWorkStatus" aria-haspopup="dialog">
                <div class="font-bold text-gray-600 group-hover:text-emerald-700 whitespace-nowrap leading-tight tracking-tight" style="font-size:clamp(.68rem,.9vw,.95rem)">근무 현황</div>
                <div class="flex items-end justify-between gap-2 mt-3">
                    <div class="font-extrabold text-emerald-700 leading-none" style="font-size:clamp(1.75rem,2.3vw,2.25rem)"><?php echo $todayWorkStatus; ?>명</div>
                    <span class="shrink-0 inline-flex items-center px-2 py-1.5 rounded-full bg-white text-emerald-700 border border-emerald-100 text-[11px] font-extrabold">현황 보기</span>
                </div>
            </button>
            <div class="min-w-0 p-4 rounded-2xl bg-violet-50 relative group cursor-pointer">
                <div class="font-bold text-gray-600 whitespace-nowrap leading-tight tracking-tight" style="font-size:clamp(.68rem,.9vw,.95rem)">명일 월차/연차/반차자 수</div>
                <div class="flex items-end justify-between gap-2 mt-3">
                    <div class="font-extrabold text-violet-700 leading-none" style="font-size:clamp(1.75rem,2.3vw,2.25rem)"><?php echo $leaveTomorrow; ?>명</div>
                    <div class="shrink-0 text-[10px] text-violet-700 font-bold"><?php echo h($tomorrow); ?></div>
                </div>
                <div class="hidden md:block absolute left-0 top-full mt-2 w-96 max-w-[92vw] p-4 rounded-2xl bg-white border border-gray-200 shadow-2xl z-[9999] opacity-0 invisible group-hover:opacity-100 group-hover:visible transition">
                    <div class="font-extrabold text-lg mb-2">명일 월차/연차/반차자 명단</div>
                    <?php if (count($leaveTomorrowPeople) === 0): ?>
                        <div class="text-base leading-8 text-gray-700">명일 월차/연차/반차자는 없습니다.</div>
                    <?php else: ?>
                        <ul class="space-y-2">
                            <?php foreach ($leaveTomorrowPeople as $person): ?>
                                <li class="text-base leading-8 text-gray-800"><?php echo h($person['name']); ?> / <?php echo h(cpms_executive_dashboard_department_label($person)); ?> / <?php echo h($person['position'] ?: '-'); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                <details class="md:hidden mt-3">
                    <summary class="inline-block px-3 py-2 rounded-xl bg-violet-100 text-base font-bold">명단 보기</summary>
                    <div class="mt-3 p-3 rounded-xl bg-white border border-gray-200">
                        <?php if (count($leaveTomorrowPeople) === 0): ?>
                            <div class="text-base leading-8 text-gray-700">명일 월차/연차/반차자는 없습니다.</div>
                        <?php else: ?>
                            <ul class="space-y-2">
                                <?php foreach ($leaveTomorrowPeople as $person): ?>
                                    <li class="text-base leading-8 text-gray-800"><?php echo h($person['name']); ?> / <?php echo h(cpms_executive_dashboard_department_label($person)); ?> / <?php echo h($person['position'] ?: '-'); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </details>
            </div>
        </div>
    </div>

    <div class="bg-white/80 rounded-3xl p-6 border">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
            <div>
                <h3 class="text-2xl font-extrabold">52시간 초과자 명단</h3>
                <div class="text-sm text-gray-500 mt-1"><?php echo h($risk52WeekLabel !== '' ? $risk52WeekLabel : '선택 주'); ?> 기준 · <?php echo h($risk52WeekRangeLabel); ?></div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <form method="get" action="" class="flex flex-wrap items-center gap-2">
                    <input type="hidden" name="r" value="dashboard_executive">
                    <input type="hidden" name="exec_tab" value="main">
                    <input type="month" name="risk52_month" value="<?php echo h($risk52Month); ?>" class="px-3 py-2 rounded-xl border border-gray-200 text-sm bg-white" onchange="this.form.submit()">
                    <select name="risk52_week" class="px-3 py-2 rounded-xl border border-gray-200 text-sm bg-white" onchange="this.form.submit()">
                        <?php foreach ($risk52WeekOptions as $weekOption): ?>
                            <option value="<?php echo h(isset($weekOption['value']) ? $weekOption['value'] : ''); ?>" <?php echo (isset($weekOption['start']) && $weekOption['start'] === $weekStart) ? 'selected' : ''; ?>>
                                <?php echo h((isset($weekOption['label']) ? $weekOption['label'] : '') . ' (' . (isset($weekOption['range_label']) ? $weekOption['range_label'] : '') . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <div class="px-4 py-2 rounded-2xl bg-red-50 text-red-700 font-extrabold border border-red-100">
                    <?php echo count($risk52); ?>명
                </div>
            </div>
        </div>
        <?php if (count($risk52) === 0): ?>
            <div class="p-6 rounded-2xl border border-dashed border-emerald-200 bg-emerald-50 text-emerald-700 font-bold">
                현재 52시간 초과자는 없습니다.
            </div>
        <?php else: ?>
            <div class="space-y-3">
                <?php foreach ($risk52 as $person): ?>
                    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3 p-4 rounded-2xl border border-red-100 bg-red-50/70">
                        <div class="min-w-0">
                            <div class="text-lg font-extrabold text-gray-900"><?php echo h($person['name']); ?></div>
                            <div class="text-sm text-gray-600 mt-1"><?php echo h(cpms_executive_dashboard_department_label($person) . ' / ' . ($person['position'] ?: '-')); ?></div>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="px-3 py-1 rounded-full bg-white border border-red-100 text-sm font-bold text-gray-700">주간 누적</span>
                            <span class="text-2xl font-extrabold text-red-700"><?php echo h(attendance_hm((int)$person['m'])); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
cpms_render_executive_attendance_modal('todayAbsent', '오늘 미출근자 명단', $absent, 'absent', $attendanceTimeLabel);
cpms_render_executive_work_status_modal($workingPeople, $checkedOutPeople, $attendanceTimeLabel);
?>
<script>
(function(){
    function modalByKey(key) {
        return document.getElementById('modal-execAttendance-' + key);
    }
    function closeModal(key) {
        var modal = modalByKey(key);
        if (modal) modal.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
    }
    var openButtons = document.querySelectorAll('[data-exec-attendance-open]');
    for (var i = 0; i < openButtons.length; i++) {
        openButtons[i].addEventListener('click', function(){
            var key = this.getAttribute('data-exec-attendance-open') || '';
            var modal = modalByKey(key);
            if (!modal) return;
            modal.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
        });
    }
    var closeButtons = document.querySelectorAll('[data-exec-attendance-close]');
    for (var j = 0; j < closeButtons.length; j++) {
        closeButtons[j].addEventListener('click', function(){
            closeModal(this.getAttribute('data-exec-attendance-close') || '');
        });
    }
    var moreButtons = document.querySelectorAll('[data-exec-attendance-more]');
    for (var k = 0; k < moreButtons.length; k++) {
        moreButtons[k].addEventListener('click', function(){
            var key = this.getAttribute('data-exec-attendance-more') || '';
            var extraPeople = document.querySelectorAll('[data-exec-attendance-extra="' + key + '"]');
            for (var n = 0; n < extraPeople.length; n++) extraPeople[n].classList.remove('hidden');
            this.classList.add('hidden');
        });
    }
    document.addEventListener('keydown', function(event){
        if (event.key !== 'Escape') return;
        var openModals = document.querySelectorAll('.cpms-exec-attendance-modal:not(.hidden)');
        for (var m = 0; m < openModals.length; m++) openModals[m].classList.add('hidden');
        if (openModals.length > 0) document.body.classList.remove('overflow-hidden');
    });
})();
</script>

<div class="bg-white/80 rounded-3xl p-6 border mb-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
        <div>
            <h3 class="text-2xl font-extrabold text-gray-900"><?php echo h(approval_ko('%ED%98%84%EC%9E%AC%20%ED%9C%B4%EA%B0%80%EC%A4%91%20%EC%9D%B8%EC%9B%90')); ?></h3>
        </div>
        <span class="px-4 py-2 rounded-2xl bg-indigo-50 text-indigo-700 font-extrabold border border-indigo-100"><?php echo count($leavePeople); ?><?php echo h(approval_ko('%EB%AA%85')); ?></span>
    </div>
    <?php if (count($leavePeople) === 0): ?>
        <div class="p-6 rounded-2xl border border-dashed border-gray-300 text-sm text-gray-500"><?php echo h(approval_ko('%ED%98%84%EC%9E%AC%20%ED%9C%B4%EA%B0%80%EC%A4%91%EC%9D%B8%20%EC%9D%B8%EC%9B%90%EC%9D%B4%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.')); ?></div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
            <?php foreach ($leavePeople as $person): ?>
                <div class="p-4 rounded-2xl border border-indigo-100 bg-indigo-50/60">
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="text-lg font-extrabold text-gray-900 break-words"><?php echo h(isset($person['name']) ? $person['name'] : '-'); ?></div>
                        <span class="px-2 py-1 rounded-full bg-white text-indigo-700 border border-indigo-100 text-xs font-extrabold whitespace-nowrap"><?php echo h(isset($person['status_label']) ? $person['status_label'] : approval_ko('%ED%9C%B4%EA%B0%80%EC%A4%91')); ?></span>
                    </div>
                    <div class="text-sm text-gray-600 mt-1"><?php echo h(cpms_executive_dashboard_department_label($person) . ' / ' . (isset($person['position']) && trim((string)$person['position']) !== '' ? $person['position'] : (isset($person['role']) && trim((string)$person['role']) !== '' ? $person['role'] : '-'))); ?></div>
                    <div class="text-sm font-bold text-gray-800 mt-2"><?php echo h(isset($person['period']) ? $person['period'] : '-'); ?></div>
                    <?php if (isset($person['type_label']) && trim((string)$person['type_label']) !== ''): ?>
                        <div class="text-xs text-gray-500 mt-1"><?php echo h($person['type_label']); ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
    <button type="button"
            class="p-4 rounded-3xl bg-blue-50 border text-left"
            data-project-cost-modal-open="projectCostSummary"
            data-project-cost-modal-url="<?php echo h(base_url()); ?>/?r=dashboard_executive&amp;fragment=project_cost_summary&amp;load_project_cost=1<?php echo $debugProjectCost ? '&amp;debug_project_cost=1' : ''; ?>">
            <div class="text-gray-600 font-bold">전체 프로젝트</div>
            <div class="text-4xl font-extrabold text-blue-700 mt-2"><?php echo $projectCostCount; ?>건</div>
            <div class="text-sm text-blue-700 font-bold mt-2">클릭해서 프로젝트별 원가율 보기</div>
    </button>
    <a href="<?php echo h($executiveTabBaseUrl . '&exec_tab=siteIssues'); ?>" class="group block p-4 rounded-3xl bg-amber-50 border border-amber-100 hover:-translate-y-0.5 hover:shadow-lg hover:shadow-amber-100/70 transition">
        <div class="text-gray-600 font-bold">미처리 이슈</div>
        <div class="text-4xl font-extrabold text-amber-700 mt-2"><?php echo count($issues); ?>건</div>
        <div class="mt-1 text-xs font-extrabold text-amber-700">현장별 이슈 탭으로 이동</div>
    </a>
    <a href="<?php echo h($executiveTabBaseUrl . '&exec_tab=siteIssues'); ?>" class="group block p-4 rounded-3xl bg-rose-50 border border-rose-100 hover:-translate-y-0.5 hover:shadow-lg hover:shadow-rose-100/70 transition">
        <div class="text-gray-600 font-bold">안전사고</div>
        <div class="text-4xl font-extrabold text-rose-700 mt-2"><?php echo count($safetyIncidents); ?>건</div>
        <div class="mt-1 text-xs font-extrabold text-rose-700">현장별 이슈 탭으로 이동</div>
    </a>
</div>

<div id="modal-projectCostSummary" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/40" data-project-cost-modal-close="projectCostSummary"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
        <div class="w-full max-w-6xl max-h-[88vh] overflow-hidden rounded-3xl bg-white shadow-2xl border border-gray-100">
            <div class="flex items-center justify-between px-6 py-5 border-b border-gray-100">
                <div>
                    <div class="text-2xl font-extrabold text-gray-900">프로젝트별 원가율 현황</div>
                    <div class="text-sm text-gray-500 mt-1">원가율은 기성관리 인정금액(확정매출)을 우선 사용합니다. 기성매출이 없으면 예상매출 기준 임시 원가율로 표시됩니다.</div>
                </div>
                <button type="button" class="p-3 rounded-2xl hover:bg-gray-100" data-project-cost-modal-close="projectCostSummary">닫기</button>
            </div>
            <div class="p-4 md:p-6 overflow-y-auto max-h-[74vh]" data-project-cost-modal-body="projectCostSummary" data-loaded="0">
            <?php if (!$loadProjectCostSummary): ?>
                <div class="text-sm text-gray-600">프로젝트별 원가율을 불러오는 중입니다.</div>
            <?php elseif (empty($projectCostSummary['projects'])): ?>
                <div class="text-sm text-gray-600">표시할 프로젝트가 없습니다.</div>
            <?php else: ?>
                <div class="space-y-4 max-h-[34rem] overflow-y-auto">
                    <?php foreach ($projectCostSummary['projects'] as $projectRow): ?>
                        <?php
                        $costRateValue = isset($projectRow['cost_rate']) ? (float)$projectRow['cost_rate'] : 0.0;
                        $noSales = isset($projectRow['no_sales']) ? (int)$projectRow['no_sales'] : 0;
                        if ($noSales === 1 || $costRateValue > 100) {
                            $rateClass = 'bg-red-50 text-red-700 border-red-100';
                        } else if ($costRateValue > 70) {
                            $rateClass = 'bg-orange-50 text-orange-700 border-orange-100';
                        } else {
                            $rateClass = 'bg-blue-50 text-blue-700 border-blue-100';
                        }
                        $targetOver = isset($projectRow['is_target_over']) ? (int)$projectRow['is_target_over'] : 0;
                        $monthlyRows = isset($projectRow['monthly_rows']) && is_array($projectRow['monthly_rows']) ? $projectRow['monthly_rows'] : array();
                        $firstMonthlyRow = count($monthlyRows) > 0 ? $monthlyRows[0] : null;
                        ?>
                        <div class="p-4 rounded-2xl border border-gray-200 bg-slate-50">
                            <div class="flex flex-col gap-3">
                                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
                                    <div class="font-extrabold text-gray-900"><?php echo h($projectRow['project_name']); ?></div>
                                    <?php if ($targetOver === 1): ?>
                                        <span class="cpms-chip self-start md:self-auto px-3 py-1 rounded-full bg-red-50 text-red-700 border border-red-100 text-xs font-extrabold">목표초과</span>
                                    <?php endif; ?>
                                </div>
                                <div class="grid grid-cols-2 lg:grid-cols-4 gap-2">
                                    <div class="rounded-2xl border bg-white p-3">
                                        <div class="text-xs text-gray-500 font-bold">총 원가율</div>
                                        <div class="mt-1 text-lg font-extrabold <?php echo $rateClass; ?> inline-block px-2 py-1 rounded-xl border"><?php echo h($projectRow['cost_rate_label']); ?></div>
                                    </div>
                                    <div class="rounded-2xl border bg-white p-3">
                                        <div class="text-xs text-gray-500 font-bold">총 매출</div>
                                        <div class="mt-1 text-lg font-extrabold text-gray-900"><?php echo h(cpms_dashboard_money(isset($projectRow['sales']) ? $projectRow['sales'] : 0)); ?></div>
                                        <div class="mt-1 text-xs text-gray-500"><?php echo h(isset($projectRow['sales_basis']) ? $projectRow['sales_basis'] : ''); ?></div>
                                    </div>
                                    <div class="rounded-2xl border bg-white p-3">
                                        <div class="text-xs text-gray-500 font-bold">총 투입원가</div>
                                        <div class="mt-1 text-lg font-extrabold text-gray-900"><?php echo h(cpms_dashboard_money(isset($projectRow['used_total']) ? $projectRow['used_total'] : 0)); ?></div>
                                    </div>
                                    <div class="rounded-2xl border bg-white p-3">
                                        <div class="text-xs text-gray-500 font-bold">총 투입목표금액</div>
                                        <div class="mt-1 text-lg font-extrabold text-gray-900"><?php echo h(isset($projectRow['target_amount_label']) ? $projectRow['target_amount_label'] : cpms_dashboard_money(0)); ?></div>
                                    </div>
                                </div>
                                <div class="cpms-chip-row text-xs text-gray-600">
                                    <span class="cpms-chip px-3 py-1 rounded-full bg-white border border-gray-200 text-gray-700 font-bold">총 원가율 <?php echo h(isset($projectRow['cost_rate_label']) ? $projectRow['cost_rate_label'] : '0%'); ?></span>
                                    <span class="cpms-chip px-3 py-1 rounded-full bg-white border border-gray-200 text-gray-700 font-bold">총 매출 <?php echo h(cpms_dashboard_money(isset($projectRow['sales']) ? $projectRow['sales'] : 0)); ?></span>
                                    <span class="cpms-chip px-3 py-1 rounded-full bg-white border border-gray-200 text-gray-700 font-bold"><?php echo h(isset($projectRow['sales_basis']) ? $projectRow['sales_basis'] : ''); ?></span>
                                    <span class="cpms-chip px-3 py-1 rounded-full bg-white border border-gray-200 text-gray-700 font-bold">총 투입원가 <?php echo h(cpms_dashboard_money(isset($projectRow['used_total']) ? $projectRow['used_total'] : 0)); ?></span>
                                    <span class="cpms-chip px-3 py-1 rounded-full bg-white border border-gray-200 text-gray-700 font-bold">총 투입목표금액 <?php echo h(cpms_dashboard_money(isset($projectRow['target_amount']) ? $projectRow['target_amount'] : 0)); ?></span>
                                    <?php if ($targetOver === 1): ?>
                                        <span class="cpms-chip px-3 py-1 rounded-full bg-red-50 text-red-700 border border-red-100 font-bold">목표초과</span>
                                    <?php endif; ?>
                                </div>
                                <div class="cpms-chip-row text-xs text-gray-600">
                                    <span class="cpms-chip">노무 <?php echo h(cpms_dashboard_money(isset($projectRow['labor']) ? $projectRow['labor'] : 0)); ?></span>
                                    <span class="cpms-chip">장비 <?php echo h(cpms_dashboard_money(isset($projectRow['equipment']) ? $projectRow['equipment'] : 0)); ?></span>
                                    <span class="cpms-chip">자재 <?php echo h(cpms_dashboard_money(isset($projectRow['materials']) ? $projectRow['materials'] : 0)); ?></span>
                                </div>
                                <?php if ($debugProjectCost): ?>
                                    <div class="rounded-2xl border border-blue-100 bg-blue-50 p-3 text-xs text-blue-900">
                                        <div><b>project_id</b>: <?php echo (int)(isset($projectRow['project_id']) ? $projectRow['project_id'] : 0); ?></div>
                                        <div><b>project_name</b>: <?php echo h(isset($projectRow['project_name']) ? $projectRow['project_name'] : ''); ?></div>
                                        <div><b>monthly_rows count</b>: <?php echo count($monthlyRows); ?></div>
                                        <div><b>sales</b>: <?php echo h((string)(isset($projectRow['sales']) ? $projectRow['sales'] : 0)); ?></div>
                                        <div><b>used_total</b>: <?php echo h((string)(isset($projectRow['used_total']) ? $projectRow['used_total'] : 0)); ?></div>
                                        <div><b>target_amount</b>: <?php echo h((string)(isset($projectRow['target_amount']) ? $projectRow['target_amount'] : 0)); ?></div>
                                        <?php if (is_array($firstMonthlyRow)): ?>
                                            <div><b>first monthly row sample</b>: <?php echo h((isset($firstMonthlyRow['label']) ? $firstMonthlyRow['label'] : '-') . ' / sales=' . (isset($firstMonthlyRow['sales']) ? $firstMonthlyRow['sales'] : 0) . ' / used_total=' . (isset($firstMonthlyRow['used_total']) ? $firstMonthlyRow['used_total'] : 0) . ' / target_amount=' . (isset($firstMonthlyRow['target_amount']) ? $firstMonthlyRow['target_amount'] : 0) . ' / rate=' . (isset($firstMonthlyRow['cost_rate_label']) ? $firstMonthlyRow['cost_rate_label'] : '0%')); ?></div>
                                        <?php else: ?>
                                            <div><b>first monthly row sample</b>: -</div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <details class="rounded-2xl border border-gray-200 bg-white">
                                    <summary class="cursor-pointer px-4 py-3 text-sm font-extrabold text-gray-800">월별 보기</summary>
                                    <div class="cpms-responsive-table-wrap border-t border-gray-100">
                                        <?php if (count($monthlyRows) === 0): ?>
                                            <div class="p-4 text-sm text-gray-500">월별 데이터가 없습니다.</div>
                                        <?php else: ?>
                                            <table class="cpms-responsive-table text-sm">
                                                <thead class="bg-gray-50 text-gray-500">
                                                    <tr>
                                                        <th class="px-3 py-2 text-left font-bold">월</th>
                                                        <th class="px-3 py-2 text-right font-bold">원가율</th>
                                                        <th class="px-3 py-2 text-right font-bold">매출</th>
                                                        <th class="px-3 py-2 text-left font-bold">매출기준</th>
                                                        <th class="px-3 py-2 text-right font-bold">투입원가</th>
                                                        <th class="px-3 py-2 text-right font-bold">투입목표금액</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($monthlyRows as $monthRow): ?>
                                                        <?php
                                                        $monthRate = isset($monthRow['cost_rate']) ? (float)$monthRow['cost_rate'] : 0.0;
                                                        $monthNoSales = isset($monthRow['no_sales']) ? (int)$monthRow['no_sales'] : 0;
                                                        if ($monthNoSales === 1 || $monthRate > 100) {
                                                            $monthRateClass = 'text-red-700 bg-red-50 border-red-100';
                                                        } else if ($monthRate > 70) {
                                                            $monthRateClass = 'text-orange-700 bg-orange-50 border-orange-100';
                                                        } else {
                                                            $monthRateClass = 'text-blue-700 bg-blue-50 border-blue-100';
                                                        }
                                                        $monthTargetAmount = isset($monthRow['target_amount']) ? (float)$monthRow['target_amount'] : 0.0;
                                                        $monthUsedTotal = isset($monthRow['used_total']) ? (float)$monthRow['used_total'] : 0.0;
                                                        $monthTargetOver = ($monthTargetAmount > 0 && $monthUsedTotal > $monthTargetAmount) ? 1 : 0;
                                                        ?>
                                                        <tr class="border-t border-gray-100">
                                                            <td class="px-3 py-2 text-gray-700 font-bold"><?php echo h(isset($monthRow['label']) ? $monthRow['label'] : '-'); ?></td>
                                                            <td class="px-3 py-2 text-right">
                                                                <span class="cpms-chip inline-flex px-2 py-1 rounded-xl border text-xs font-extrabold <?php echo $monthRateClass; ?>"><?php echo h(isset($monthRow['cost_rate_label']) ? $monthRow['cost_rate_label'] : '0%'); ?></span>
                                                            </td>
                                                            <td class="px-3 py-2 text-right text-gray-800"><?php echo h(cpms_dashboard_money(isset($monthRow['sales']) ? $monthRow['sales'] : 0)); ?></td>
                                                            <td class="px-3 py-2 text-gray-600"><?php echo h(isset($monthRow['sales_basis']) ? $monthRow['sales_basis'] : ''); ?></td>
                                                            <td class="px-3 py-2 text-right text-gray-800"><?php echo h(cpms_dashboard_money(isset($monthRow['used_total']) ? $monthRow['used_total'] : 0)); ?></td>
                                                            <td class="px-3 py-2 text-right text-gray-800">
                                                                <?php echo h(cpms_dashboard_money(isset($monthRow['target_amount']) ? $monthRow['target_amount'] : 0)); ?>
                                                                <?php if ($monthTargetOver === 1): ?>
                                                                    <span class="cpms-chip ml-2 px-2 py-0.5 rounded-full bg-red-50 text-red-700 border border-red-100 font-bold">목표초과</span>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        <?php endif; ?>
                                    </div>
                                </details>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    var openButtons = document.querySelectorAll('[data-project-cost-modal-open]');
    var closeButtons = document.querySelectorAll('[data-project-cost-modal-close]');
    function loadProjectCostModal(url) {
        var body = document.querySelector('[data-project-cost-modal-body="projectCostSummary"]');
        if (!body || !url) return;
        if (body.getAttribute('data-loaded') === '1' || body.getAttribute('data-loading') === '1') return;

        body.setAttribute('data-loading', '1');
        body.innerHTML = '<div class="text-sm text-gray-600">프로젝트별 원가율을 불러오는 중입니다.</div>';
        var xhr = new XMLHttpRequest();
        xhr.open('GET', url, true);
        xhr.onreadystatechange = function(){
            if (xhr.readyState !== 4) return;
            body.removeAttribute('data-loading');
            if (xhr.status >= 200 && xhr.status < 300) {
                body.innerHTML = xhr.responseText;
                body.setAttribute('data-loaded', '1');
                return;
            }
            body.innerHTML = '<div class="text-sm text-red-600">프로젝트별 원가율을 불러오지 못했습니다. 다시 시도해주세요.</div>';
        };
        xhr.send(null);
    }
    function openModal(key) {
        var modal = document.getElementById('modal-' + key);
        if (modal) modal.classList.remove('hidden');
    }
    function closeModal(key) {
        var modal = document.getElementById('modal-' + key);
        if (modal) modal.classList.add('hidden');
    }
    for (var i = 0; i < openButtons.length; i++) {
        openButtons[i].addEventListener('click', function(){
            loadProjectCostModal(this.getAttribute('data-project-cost-modal-url'));
            openModal(this.getAttribute('data-project-cost-modal-open'));
        });
    }
    for (var j = 0; j < closeButtons.length; j++) {
        closeButtons[j].addEventListener('click', function(){
            closeModal(this.getAttribute('data-project-cost-modal-close'));
        });
    }
})();
</script>

</section>

<?php elseif ($activeExecutiveTab === 'myTasks'): ?>
<section data-executive-tab-panel="myTasks">
<?php
if (!$executiveEmployeeTaskAvailable) {
    ?>
    <div class="bg-white/80 rounded-3xl p-6 border text-sm font-bold text-gray-600">직원 정보를 찾을 수 없어 나의할일을 불러올 수 없습니다.</div>
    <?php
} else {
    $executiveMyTasksReturnUrl = '?r=dashboard_executive&exec_tab=myTasks';
    if (isset($_GET['requested_task_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['requested_task_date'])) {
        $executiveMyTasksReturnUrl .= '&requested_task_date=' . urlencode((string)$_GET['requested_task_date']);
    }
    cpms_render_employee_task_dashboard($pdo, array(
        'return_url' => $executiveMyTasksReturnUrl,
        'form_hidden_inputs' => array('r' => 'dashboard_executive', 'exec_tab' => 'myTasks')
    ));
}
?>
</section>

<?php elseif ($activeExecutiveTab === 'department'): ?>
<section data-executive-tab-panel="department">
<?php cpms_render_executive_task_dashboard($pdo); ?>

<div class="mt-8">
    <?php render_task_list_sample(); ?>
</div>
</section>

<?php elseif ($activeExecutiveTab === 'approval'): ?>
<section data-executive-tab-panel="approval">
<div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100">
    <div class="flex items-start justify-between gap-4 mb-4">
        <div>
            <h3 class="text-2xl font-extrabold text-gray-900">공수 수정 승인대기</h3>
        </div>
        <span class="px-3 py-1 rounded-full bg-amber-50 text-amber-700 border border-amber-100 text-sm font-bold">대기 <?php echo count($pendingGongsuOverrides); ?>건</span>
    </div>
    <details class="cpms-approval-collapsible rounded-2xl border border-gray-200 bg-gray-50/70">
        <summary class="cursor-pointer select-none px-4 py-3 text-sm font-extrabold text-gray-800">승인 요청 <span class="cpms-collapse-open-label">펼치기</span><span class="cpms-collapse-close-label">접기</span></summary>
    <div class="space-y-3 px-4 pb-4">
        <?php if (count($pendingGongsuOverrides) === 0): ?>
            <div class="text-sm text-gray-500">승인 대기 중인 공수 수정 요청이 없습니다.</div>
        <?php else: ?>
            <?php foreach ($pendingGongsuOverrides as $ov): ?>
                <?php
                // 파일: app/views/dashboard/executive.php - 일괄 요청은 전체 이름을 보여 주고 승인 버튼은 한 번만 제공합니다.
                $requesterName = trim((string)$ov['requested_by_name']) !== '' ? $ov['requested_by_name'] : (trim((string)$ov['requested_emp_name']) !== '' ? $ov['requested_emp_name'] : (trim((string)$ov['requested_by_email']) !== '' ? $ov['requested_by_email'] : '-'));
                $overrideWorkerCount = isset($ov['worker_count']) ? (int)$ov['worker_count'] : 1;
                if ($overrideWorkerCount < 1) $overrideWorkerCount = 1;
                $overrideWorkerNames = isset($ov['worker_names_text']) && trim((string)$ov['worker_names_text']) !== '' ? (string)$ov['worker_names_text'] : (isset($ov['worker_name']) ? (string)$ov['worker_name'] : '-');
                $isBulkOverride = isset($ov['batch_token']) && trim((string)$ov['batch_token']) !== '' && $overrideWorkerCount > 1;
                $isAllOverride = isset($ov['request_scope']) && (string)$ov['request_scope'] === 'all';
                $overrideRequestedAt = isset($ov['created_at']) ? $ov['created_at'] : '';
                ?>
                <div class="p-4 rounded-2xl border bg-gray-50 border-gray-100">
                    <div class="text-xs text-gray-500">요청일 <?php echo h($overrideRequestedAt); ?></div>
                    <?php if ($isAllOverride): ?><span class="mt-2 inline-flex px-2 py-1 rounded-full bg-violet-100 text-violet-800 border border-violet-200 text-xs font-extrabold">[전체요청]</span><?php endif; ?>
                    <div class="font-bold text-gray-900 mt-1 text-lg">현장명: <?php echo h($ov['project_name'] ?: '-'); ?></div>
                    <div class="text-sm text-gray-700 mt-1">요청자: <?php echo h($requesterName); ?></div>
                    <div class="text-sm text-gray-700 mt-1">작업자: <span class="font-bold"><?php echo h($overrideWorkerNames); ?></span><?php if ($overrideWorkerCount > 1): ?> (총 <?php echo $overrideWorkerCount; ?>명)<?php endif; ?></div>
                    <div class="text-sm text-gray-700">작업일자: <?php echo h($ov['work_date']); ?></div>
                    <?php if ($isBulkOverride): ?>
                        <div class="text-sm text-gray-700">변경: 전체 <?php echo $overrideWorkerCount; ?>명 → <span class="font-extrabold text-emerald-700"><?php echo h($ov['new_value']); ?>공수</span></div>
                    <?php else: ?>
                        <div class="text-sm text-gray-700">변경: <?php echo h($ov['old_value']); ?> → <span class="font-extrabold text-emerald-700"><?php echo h($ov['new_value']); ?></span></div>
                    <?php endif; ?>
                    <div class="text-sm text-gray-700">사유: <?php echo h(trim((string)$ov['reason']) !== '' ? $ov['reason'] : '-'); ?></div>
                    <details class="mt-3 rounded-xl border border-gray-200 bg-white">
                        <summary class="cursor-pointer px-3 py-2 text-xs font-extrabold text-blue-700">상세</summary>
                        <div class="border-t border-gray-100 p-3">
                            <div class="grid grid-cols-1 gap-2">
                                <?php $overrideItems = isset($ov['items']) && is_array($ov['items']) ? $ov['items'] : array($ov); ?>
                                <?php foreach ($overrideItems as $overrideItem): ?>
                                    <div class="rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-700">
                                        <span class="font-extrabold"><?php echo h(isset($overrideItem['worker_name']) ? $overrideItem['worker_name'] : '-'); ?></span>
                                        · <?php echo h(isset($overrideItem['work_date']) ? $overrideItem['work_date'] : '-'); ?>
                                        · <?php echo h(isset($overrideItem['old_value']) ? $overrideItem['old_value'] : ''); ?> → <span class="font-extrabold text-emerald-700"><?php echo h(isset($overrideItem['new_value']) ? $overrideItem['new_value'] : ''); ?>공수</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="mt-2 text-xs text-gray-700">요청사유: <?php echo h(trim((string)$ov['reason']) !== '' ? $ov['reason'] : '-'); ?></div>
                        </div>
                    </details>
                    <div class="flex flex-col sm:flex-row flex-wrap gap-2 mt-3">
                        <form method="post" action="?r=construction/labor_gongsu_override_decide">
                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                            <input type="hidden" name="override_id" value="<?php echo (int)$ov['id']; ?>">
                            <input type="hidden" name="decision" value="approve">
                            <button class="w-full sm:w-auto px-3 py-2 rounded-xl bg-emerald-600 text-white text-xs font-bold" type="submit">승인</button>
                        </form>
                        <form method="post" action="?r=construction/labor_gongsu_override_decide" class="w-full flex flex-col sm:flex-row gap-2">
                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                            <input type="hidden" name="override_id" value="<?php echo (int)$ov['id']; ?>">
                            <input type="hidden" name="decision" value="reject">
                            <input type="text" name="reject_reason" class="w-full sm:w-auto px-3 py-2 rounded-xl border border-gray-200 text-xs" placeholder="반려 사유 입력" required>
                            <button class="w-full sm:w-auto px-3 py-2 rounded-xl bg-rose-600 text-white text-xs font-bold" type="submit">반려</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    </details>
</div>

<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100">
    <div class="flex items-start justify-between gap-4 mb-4">
        <div>
            <h3 class="text-2xl font-extrabold text-gray-900">장비공수 수정 승인대기</h3>
        </div>
        <span class="px-3 py-1 rounded-full bg-amber-50 text-amber-700 border border-amber-100 text-sm font-bold">대기 <?php echo count($pendingEquipmentGongsuOverrides); ?>건</span>
    </div>
    <div class="space-y-3">
        <?php if (count($pendingEquipmentGongsuOverrides) === 0): ?>
            <div class="text-sm text-gray-500">승인 대기 중인 장비공수 수정 요청이 없습니다.</div>
        <?php else: ?>
            <?php foreach ($pendingEquipmentGongsuOverrides as $ov): ?>
                <?php
                $requesterName = trim((string)$ov['requested_by_name']) !== '' ? $ov['requested_by_name'] : (trim((string)$ov['requested_by_email']) !== '' ? $ov['requested_by_email'] : '-');
                $equipmentName = trim((string)(isset($ov['spec']) ? $ov['spec'] : ''));
                if ($equipmentName === '') $equipmentName = trim((string)(isset($ov['vendor_name']) ? $ov['vendor_name'] : ''));
                ?>
                <div class="p-4 rounded-2xl border bg-gray-50 border-gray-100">
                    <div class="text-xs text-gray-500">요청일 <?php echo h($ov['created_at']); ?></div>
                    <div class="font-bold text-gray-900 mt-1 text-lg">현장명: <?php echo h($ov['project_name'] ?: '-'); ?></div>
                    <div class="text-sm text-gray-700 mt-1">요청자: <?php echo h($requesterName); ?></div>
                    <div class="text-sm text-gray-700 mt-1">장비명: <?php echo h($equipmentName !== '' ? $equipmentName : '-'); ?> / 사용일자: <?php echo h($ov['use_date']); ?></div>
                    <div class="text-sm text-gray-700">변경: <?php echo h($ov['old_value']); ?> → <span class="font-extrabold text-emerald-700"><?php echo h($ov['new_value']); ?></span></div>
                    <div class="text-sm text-gray-700">사유: <?php echo h(trim((string)$ov['reason']) !== '' ? $ov['reason'] : '-'); ?></div>
                    <div class="flex flex-col sm:flex-row flex-wrap gap-2 mt-3">
                        <form method="post" action="?r=construction/equipment_gongsu_override_decide">
                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                            <input type="hidden" name="override_id" value="<?php echo (int)$ov['id']; ?>">
                            <input type="hidden" name="decision" value="approve">
                            <button class="w-full sm:w-auto px-3 py-2 rounded-xl bg-emerald-600 text-white text-xs font-bold" type="submit">승인</button>
                        </form>
                        <form method="post" action="?r=construction/equipment_gongsu_override_decide" class="w-full flex flex-col sm:flex-row gap-2">
                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                            <input type="hidden" name="override_id" value="<?php echo (int)$ov['id']; ?>">
                            <input type="hidden" name="decision" value="reject">
                            <input type="text" name="reject_reason" class="w-full sm:w-auto px-3 py-2 rounded-xl border border-gray-200 text-xs" placeholder="반려 사유 입력" required>
                            <button class="w-full sm:w-auto px-3 py-2 rounded-xl bg-rose-600 text-white text-xs font-bold" type="submit">반려</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

</div>
</section>

<?php elseif ($activeExecutiveTab === 'progressStatements'): ?>
<section data-executive-tab-panel="progressStatements">
<?php require __DIR__ . '/partials/progress_statement_status.php'; ?>
</section>
<?php elseif ($activeExecutiveTab === 'attendanceManagement'): ?>
<section data-executive-tab-panel="attendanceManagement">
<?php
$cpmsAttendanceEmbeddedInExecutiveDashboard = true;
require __DIR__ . '/../admin/attendance.php';
unset($cpmsAttendanceEmbeddedInExecutiveDashboard);
?>
</section>

<?php elseif ($activeExecutiveTab === 'siteIssues'): ?>
<section data-executive-tab-panel="siteIssues">
<div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h3 class="text-xl font-extrabold text-gray-900">안전사고</h3>
        </div>
        <a href="<?php echo h(base_url()); ?>/?r=safety_home" class="px-4 py-2 rounded-2xl bg-gray-900 text-white font-extrabold">안전 탭으로</a>
    </div>

    <?php if (count($safetyIncidents) === 0): ?>
        <div class="text-sm text-gray-600">미처리 안전사고가 없습니다.</div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($safetyIncidents as $it): ?>
                <?php $stt = isset($it['status']) ? (string)$it['status'] : '접수'; ?>
                <div class="p-4 rounded-2xl border border-gray-100 bg-white hover:shadow-md transition">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="font-extrabold text-gray-900 truncate"><?php echo h($it['title']); ?></div>
                            <div class="text-xs text-gray-500 mt-1">
                                프로젝트: <b><?php echo h($it['project_name']); ?></b>
                                · 등록자: <?php echo h($it['created_by_name']); ?>
                                · 접수시간: <?php echo h($it['created_at']); ?>
                                <?php if (!empty($it['occurred_at'])): ?> · 발생: <?php echo h($it['occurred_at']); ?><?php endif; ?>
                            </div>
                            <?php if (!empty($it['description'])): ?>
                                <div class="text-sm text-gray-700 mt-2 whitespace-pre-line"><?php echo h($it['description']); ?></div>
                            <?php endif; ?>
                        </div>
                        <span class="text-xs font-bold px-3 py-1 rounded-full border <?php echo h($statusBadgeClass($stt)); ?>"><?php echo h($stt); ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100">
    <div class="mb-4">
        <h3 class="text-xl font-extrabold text-gray-900">이슈</h3>
    </div>

    <?php if (count($issues) === 0): ?>
        <div class="text-sm text-gray-600">미처리 이슈가 없습니다.</div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($issues as $it): ?>
                <?php
                $stt = isset($it['status']) ? (string)$it['status'] : '접수';
                $issueId = (int)$it['id'];
                $issueComments = isset($issueCommentsByIssueId[$issueId]) ? $issueCommentsByIssueId[$issueId] : array();
                ?>
                <div class="p-4 rounded-2xl border border-gray-100 bg-white hover:shadow-md transition">
                    <div class="flex flex-col items-stretch gap-3 md:flex-row md:items-start md:justify-between">
                        <div class="w-full min-w-0 flex-1">
                            <div class="font-extrabold text-gray-900 break-words"><?php echo h(isset($it['title']) && trim((string)$it['title']) !== '' ? $it['title'] : (isset($it['reason']) ? $it['reason'] : '-')); ?></div>
                            <div class="text-xs text-gray-500 mt-1">
                                현장명: <b><?php echo h($it['project_name']); ?></b>
                                · 등록자: <?php echo h($it['created_by_name']); ?>
                                · 등록일: <?php echo h($it['created_at']); ?>
                            </div>
                            <div class="text-sm text-gray-700 mt-2 whitespace-pre-line break-words"><?php echo h(isset($it['description']) && trim((string)$it['description']) !== '' ? $it['description'] : (isset($it['content']) ? $it['content'] : '-')); ?></div>

                            <div class="mt-4 p-3 rounded-xl bg-gray-50 border border-gray-200">
                                <div class="text-sm font-bold text-gray-800 mb-2">댓글</div>
                                <?php if (count($issueComments) === 0): ?>
                                    <div class="text-sm text-gray-500">등록된 댓글이 없습니다.</div>
                                <?php else: ?>
                                    <div class="space-y-2">
                                        <?php foreach ($issueComments as $c): ?>
                                            <div class="text-sm rounded-lg bg-white border border-gray-200 p-2">
                                                <div class="text-xs text-gray-500"><?php echo h(trim((string)$c['created_by_name']) !== '' ? $c['created_by_name'] : '작성자'); ?> · <?php echo h($c['created_at']); ?></div>
                                                <div class="text-gray-800 whitespace-pre-line"><?php echo h($c['comment_body']); ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <form method="post" action="?r=construction/issue_comment_create" class="mt-3">
                                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                    <input type="hidden" name="issue_id" value="<?php echo $issueId; ?>">
                                    <input type="hidden" name="redirect" value="dashboard_executive">
                                    <textarea name="comment_text" rows="2" class="w-full px-3 py-2 rounded-2xl border border-gray-200 text-sm" placeholder="댓글 입력"></textarea>
                                    <button type="submit" class="mt-2 px-3 py-2 rounded-2xl bg-gray-900 text-white font-extrabold text-sm">댓글 등록</button>
                                </form>
                            </div>
                        </div>

                        <div class="w-full md:w-auto">
                            <form method="post" action="?r=construction/issue_state_save" class="flex w-full flex-wrap items-center gap-2 md:w-auto md:flex-nowrap">
                                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                <input type="hidden" name="issue_id" value="<?php echo $issueId; ?>">
                                <input type="hidden" name="redirect" value="dashboard_executive">
                                <span class="shrink-0 whitespace-nowrap text-xs font-bold px-3 py-1 rounded-full border <?php echo h($statusBadgeClass($stt)); ?>"><?php echo h($stt); ?></span>
                                <select name="status" class="min-w-0 flex-1 px-3 py-2 rounded-2xl border border-gray-200 text-sm md:flex-none">
                                    <option value="접수" <?php echo ($stt === '접수') ? 'selected' : ''; ?>>접수</option>
                                    <option value="처리중" <?php echo ($stt === '처리중') ? 'selected' : ''; ?>>처리중</option>
                                    <option value="처리완료" <?php echo ($stt === '처리완료') ? 'selected' : ''; ?>>처리완료</option>
                                </select>
                                <button type="submit" class="shrink-0 whitespace-nowrap px-3 py-2 rounded-2xl bg-gray-900 text-white font-extrabold text-sm">변경</button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
</div>
</section>
<?php endif; ?>
</div>
</div>
