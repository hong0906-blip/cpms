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

use App\Core\Db;

$user = \App\Core\Auth::user();
$pdo = Db::pdo();
$userEmail = (string)\App\Core\Auth::userEmail();
$flash = flash_get();

$projectCostFragmentOnly = isset($projectCostFragmentOnly) ? (bool)$projectCostFragmentOnly : false;
$debugProjectCost = isset($_GET['debug_project_cost']) && (string)$_GET['debug_project_cost'] === '1';
$loadProjectCostSummary = $projectCostFragmentOnly || (isset($_GET['load_project_cost']) && (string)$_GET['load_project_cost'] === '1');
$projectCostCount = function_exists('cpms_dashboard_project_count') ? (int)cpms_dashboard_project_count($pdo) : 0;
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
$myUserId = cpms_find_employee_id_by_email($pdo, $userEmail);

$today = attendance_today();
list($weekStart, $weekEnd) = attendance_week_range($today);
$currentLeaveIndex = function_exists('approval_current_leave_index') ? approval_current_leave_index($pdo, $today) : array('by_id' => array(), 'by_email' => array(), 'by_name' => array(), 'people' => array());
$tomorrowTs = strtotime($today . ' +1 day');
$tomorrow = ($tomorrowTs !== false) ? date('Y-m-d', $tomorrowTs) : date('Y-m-d', strtotime('+1 day'));
$tomorrowLeaveIndex = function_exists('approval_current_leave_index') ? approval_current_leave_index($pdo, $tomorrow) : array('by_id' => array(), 'by_email' => array(), 'by_name' => array(), 'people' => array());
$risk52 = array();
$absent = array();
$presentPeople = array();
$leavePeople = isset($currentLeaveIndex['people']) && is_array($currentLeaveIndex['people']) ? $currentLeaveIndex['people'] : array();
$leaveToday = count($leavePeople);
$leaveTomorrowPeople = isset($tomorrowLeaveIndex['people']) && is_array($tomorrowLeaveIndex['people']) ? $tomorrowLeaveIndex['people'] : array();
$leaveTomorrow = count($leaveTomorrowPeople);
$todayPresent = 0;

$leaveExTypes = array('월차', '연차', '반차', '오전반차', '오후반차', '월차반차', '연차반차', '오전월차반차', '오후월차반차', '오전연차반차', '오후연차반차', '대체휴무', '기타휴무', '휴무');
$leaveMainTypes = array('월차', '연차', '반차', '오전반차', '오후반차', '월차반차', '연차반차', '오전월차반차', '오후월차반차', '오전연차반차', '오후연차반차');

$statusBadgeClass = function ($status) {
    if ($status === '처리완료') return 'bg-emerald-50 text-emerald-700 border-emerald-100';
    if ($status === '처리중') return 'bg-blue-50 text-blue-700 border-blue-100';
    return 'bg-rose-50 text-rose-700 border-rose-100';
};

$hideFromTodayAttendanceCards = function ($person) {
    $position = '';
    if (is_array($person) && isset($person['position'])) {
        $position = trim((string)$person['position']);
    }
    $position = str_replace(array(' ', "\t", "\r", "\n"), '', $position);
    return ($position !== '' && strpos($position, '대표') !== false);
};

$attendanceTimeLabel = function ($value) {
    $value = trim((string)$value);
    if ($value === '') return '-';
    if (preg_match('/\d{2}:\d{2}/', $value, $m)) return $m[0];
    return $value;
};

if ($pdo) {
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

    if (count($issues) > 0) {
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

    try {
        $sql = "SELECT e.id, e.name, e.department, e.position, COALESCE(SUM(a.work_minutes), 0) AS m
                FROM employees e
                LEFT JOIN cpms_attendance_records a
                  ON a.employee_id = e.id
                 AND a.work_date BETWEEN :s AND :e
                WHERE e.is_active = 1
                GROUP BY e.id, e.name, e.department, e.position";
        $st = $pdo->prepare($sql);
        $st->execute(array(':s' => $weekStart, ':e' => $weekEnd));
        foreach ($st->fetchAll() as $r) {
            if ((int)$r['m'] > 3120) $risk52[] = $r;
        }

        $leaveExMap = isset($currentLeaveIndex['by_id']) && is_array($currentLeaveIndex['by_id']) ? $currentLeaveIndex['by_id'] : array();

        $activeRows = $pdo->query("SELECT id, name, department, position FROM employees WHERE is_active = 1")->fetchAll();
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
                $presentPeople[] = array(
                    'name' => $ar['name'],
                    'department' => $ar['department'],
                    'position' => $ar['position'],
                    'check_in' => isset($attMap[$eid]['check_in']) ? $attMap[$eid]['check_in'] : '',
                    'check_out' => isset($attMap[$eid]['check_out']) ? $attMap[$eid]['check_out'] : '',
                );
                continue;
            }
            if (isset($attMap[$eid]) || isset($leaveExMap[$eid])) continue;
            $absent[] = array(
                'name' => $ar['name'],
                'department' => $ar['department'],
                'position' => $ar['position'],
            );
        }
        $todayPresent = count($presentPeople);

        $leavePeople = isset($currentLeaveIndex['people']) && is_array($currentLeaveIndex['people']) ? $currentLeaveIndex['people'] : array();
        $leaveToday = count($leavePeople);
        $leaveTomorrowPeople = isset($tomorrowLeaveIndex['people']) && is_array($tomorrowLeaveIndex['people']) ? $tomorrowLeaveIndex['people'] : array();
        $leaveTomorrow = count($leaveTomorrowPeople);
    } catch (Exception $e) {
    }

    try {
        cpms_ensure_labor_override_table($pdo);
        $sql = "SELECT o.id, o.project_id, o.month, o.worker_name, o.work_date, o.old_value, o.new_value, o.reason,
                       o.requested_by, o.requested_by_email, o.requested_by_name, o.approval_stage,
                       o.current_approver_employee_id, o.current_approver_email, o.created_at,
                       p.name AS project_name, e.name AS requested_emp_name
                FROM cpms_labor_gongsu_overrides o
                LEFT JOIN cpms_projects p ON p.id = o.project_id
                LEFT JOIN employees e ON e.id = o.requested_by
                WHERE o.status = 'pending'
                  AND (o.current_approver_employee_id = :my_employee_id OR LOWER(o.current_approver_email) = LOWER(:my_email))
                ORDER BY o.created_at DESC";
        $st = $pdo->prepare($sql);
        $st->bindValue(':my_employee_id', (int)$myUserId, PDO::PARAM_INT);
        $st->bindValue(':my_email', (string)$userEmail, PDO::PARAM_STR);
        $st->execute();
        $pendingGongsuOverrides = $st->fetchAll();
    } catch (Exception $e) {
        $pendingGongsuOverrides = array();
    }

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

$executiveTabKeys = array(
    'main' => true,
    'department' => true,
    'approval' => true,
    'siteIssues' => true,
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
if (!isset($executiveTabKeys[$activeExecutiveTab])) {
    $activeExecutiveTab = 'main';
}
$executiveTabBaseUrl = base_url() . '/?r=dashboard_executive';
?>

<div class="bg-gradient-to-r from-indigo-600 to-purple-500 rounded-3xl p-8 text-white shadow-xl shadow-indigo-500/20 mb-8">
    <div class="flex items-start gap-4">
        <div class="p-4 bg-white/20 rounded-3xl border border-white/20">
            <i data-lucide="layout-dashboard" class="w-8 h-8 text-yellow-200"></i>
        </div>
        <div class="flex-1">
            <h2 class="text-3xl font-extrabold">임원 대시보드</h2>
        </div>
    </div>
</div>

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

<div class="mb-6 flex flex-wrap items-center gap-2" role="tablist" aria-label="임원 대시보드 탭">
    <a href="<?php echo h($executiveTabBaseUrl . '&exec_tab=main'); ?>" role="tab" class="cpms-exec-tab-btn px-5 py-3 rounded-2xl border text-base font-extrabold transition" data-executive-tab="main" aria-selected="<?php echo ($activeExecutiveTab === 'main') ? 'true' : 'false'; ?>">메인</a>
    <a href="<?php echo h($executiveTabBaseUrl . '&exec_tab=department'); ?>" role="tab" class="cpms-exec-tab-btn px-5 py-3 rounded-2xl border text-base font-extrabold transition" data-executive-tab="department" aria-selected="<?php echo ($activeExecutiveTab === 'department') ? 'true' : 'false'; ?>">부서별 업무현황</a>
    <a href="<?php echo h($executiveTabBaseUrl . '&exec_tab=approval'); ?>" role="tab" class="cpms-exec-tab-btn px-5 py-3 rounded-2xl border text-base font-extrabold transition" data-executive-tab="approval" aria-selected="<?php echo ($activeExecutiveTab === 'approval') ? 'true' : 'false'; ?>">승인대기</a>
    <a href="<?php echo h($executiveTabBaseUrl . '&exec_tab=siteIssues'); ?>" role="tab" class="cpms-exec-tab-btn px-5 py-3 rounded-2xl border text-base font-extrabold transition" data-executive-tab="siteIssues" aria-selected="<?php echo ($activeExecutiveTab === 'siteIssues') ? 'true' : 'false'; ?>">현장별 이슈</a>
</div>

<div data-executive-tab-panels>
<?php if ($activeExecutiveTab === 'main'): ?>
<section data-executive-tab-panel="main">

<div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-6">
    <div class="bg-white/80 rounded-3xl p-6 border overflow-visible">
        <h3 class="text-2xl font-extrabold mb-4">근태 리스크 현황</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="p-4 rounded-2xl bg-gray-50 relative group cursor-pointer">
                <div class="text-gray-600 text-lg font-bold">오늘 미출근자 수</div>
                <div class="text-4xl font-extrabold mt-2"><?php echo count($absent); ?>명</div>
                <div class="hidden md:block absolute left-0 top-full mt-2 w-96 max-w-[92vw] p-4 rounded-2xl bg-white border border-gray-200 shadow-2xl z-[9999] opacity-0 invisible group-hover:opacity-100 group-hover:visible transition">
                    <div class="font-extrabold text-lg mb-2">오늘 미출근자 명단</div>
                    <?php if (count($absent) === 0): ?>
                        <div class="text-base leading-8 text-gray-700">오늘 미출근자는 없습니다.</div>
                    <?php else: ?>
                        <ul class="space-y-2">
                            <?php foreach ($absent as $person): ?>
                                <li class="text-base leading-8 text-gray-800"><?php echo h($person['name']); ?> / <?php echo h($person['department'] ?: '-'); ?> / <?php echo h($person['position'] ?: '-'); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                <details class="md:hidden mt-3">
                    <summary class="inline-block px-3 py-2 rounded-xl bg-gray-200 text-base font-bold">명단 보기</summary>
                    <div class="mt-3 p-3 rounded-xl bg-white border border-gray-200">
                        <?php if (count($absent) === 0): ?>
                            <div class="text-base leading-8 text-gray-700">오늘 미출근자는 없습니다.</div>
                        <?php else: ?>
                            <ul class="space-y-2">
                                <?php foreach ($absent as $person): ?>
                                    <li class="text-base leading-8 text-gray-800"><?php echo h($person['name']); ?> / <?php echo h($person['department'] ?: '-'); ?> / <?php echo h($person['position'] ?: '-'); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </details>
            </div>
            <div class="p-4 rounded-2xl bg-sky-50 relative group cursor-pointer">
                <div class="text-gray-600 text-lg font-bold">오늘 출근자 수</div>
                <div class="text-4xl font-extrabold text-sky-700 mt-2"><?php echo $todayPresent; ?>명</div>
                <div class="hidden md:block absolute left-0 top-full mt-2 w-96 max-w-[92vw] p-4 rounded-2xl bg-white border border-gray-200 shadow-2xl z-[9999] opacity-0 invisible group-hover:opacity-100 group-hover:visible transition">
                    <div class="font-extrabold text-lg mb-2">오늘 출근자 명단</div>
                    <?php if (count($presentPeople) === 0): ?>
                        <div class="text-base leading-8 text-gray-700">오늘 출근자는 없습니다.</div>
                    <?php else: ?>
                        <ul class="space-y-2">
                            <?php foreach ($presentPeople as $person): ?>
                                <?php
                                $checkInLabel = $attendanceTimeLabel(isset($person['check_in']) ? $person['check_in'] : '');
                                $checkOutLabel = $attendanceTimeLabel(isset($person['check_out']) ? $person['check_out'] : '');
                                ?>
                                <li class="text-base leading-8 text-gray-800"><?php echo h($person['name']); ?> <span class="font-bold text-sky-700">(<?php echo h($checkInLabel . ' / ' . $checkOutLabel); ?>)</span> / <?php echo h($person['department'] ?: '-'); ?> / <?php echo h($person['position'] ?: '-'); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                <details class="md:hidden mt-3">
                    <summary class="inline-block px-3 py-2 rounded-xl bg-sky-100 text-base font-bold">명단 보기</summary>
                    <div class="mt-3 p-3 rounded-xl bg-white border border-gray-200">
                        <?php if (count($presentPeople) === 0): ?>
                            <div class="text-base leading-8 text-gray-700">오늘 출근자는 없습니다.</div>
                        <?php else: ?>
                            <ul class="space-y-2">
                                <?php foreach ($presentPeople as $person): ?>
                                    <?php
                                    $checkInLabel = $attendanceTimeLabel(isset($person['check_in']) ? $person['check_in'] : '');
                                    $checkOutLabel = $attendanceTimeLabel(isset($person['check_out']) ? $person['check_out'] : '');
                                    ?>
                                    <li class="text-base leading-8 text-gray-800"><?php echo h($person['name']); ?> <span class="font-bold text-sky-700">(<?php echo h($checkInLabel . ' / ' . $checkOutLabel); ?>)</span> / <?php echo h($person['department'] ?: '-'); ?> / <?php echo h($person['position'] ?: '-'); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </details>
            </div>
            <div class="p-4 rounded-2xl bg-indigo-50 relative group cursor-pointer">
                <div class="text-gray-600 text-lg font-bold">오늘 월차/연차/반차자 수</div>
                <div class="text-4xl font-extrabold text-indigo-700 mt-2"><?php echo $leaveToday; ?>명</div>
                <div class="hidden md:block absolute left-0 top-full mt-2 w-96 max-w-[92vw] p-4 rounded-2xl bg-white border border-gray-200 shadow-2xl z-[9999] opacity-0 invisible group-hover:opacity-100 group-hover:visible transition">
                    <div class="font-extrabold text-lg mb-2">오늘 월차/연차/반차자 명단</div>
                    <?php if (count($leavePeople) === 0): ?>
                        <div class="text-base leading-8 text-gray-700">오늘 월차/연차/반차자는 없습니다.</div>
                    <?php else: ?>
                        <ul class="space-y-2">
                            <?php foreach ($leavePeople as $person): ?>
                                <li class="text-base leading-8 text-gray-800"><?php echo h($person['name']); ?> / <?php echo h($person['department'] ?: '-'); ?> / <?php echo h($person['position'] ?: '-'); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                <details class="md:hidden mt-3">
                    <summary class="inline-block px-3 py-2 rounded-xl bg-indigo-100 text-base font-bold">명단 보기</summary>
                    <div class="mt-3 p-3 rounded-xl bg-white border border-gray-200">
                        <?php if (count($leavePeople) === 0): ?>
                            <div class="text-base leading-8 text-gray-700">오늘 월차/연차/반차자는 없습니다.</div>
                        <?php else: ?>
                            <ul class="space-y-2">
                                <?php foreach ($leavePeople as $person): ?>
                                    <li class="text-base leading-8 text-gray-800"><?php echo h($person['name']); ?> / <?php echo h($person['department'] ?: '-'); ?> / <?php echo h($person['position'] ?: '-'); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </details>
            </div>
            <div class="p-4 rounded-2xl bg-violet-50 relative group cursor-pointer">
                <div class="text-gray-600 text-lg font-bold">명일 월차/연차/반차자 수</div>
                <div class="text-4xl font-extrabold text-violet-700 mt-2"><?php echo $leaveTomorrow; ?>명</div>
                <div class="text-xs text-violet-700 font-bold mt-1"><?php echo h($tomorrow); ?> 기준</div>
                <div class="hidden md:block absolute left-0 top-full mt-2 w-96 max-w-[92vw] p-4 rounded-2xl bg-white border border-gray-200 shadow-2xl z-[9999] opacity-0 invisible group-hover:opacity-100 group-hover:visible transition">
                    <div class="font-extrabold text-lg mb-2">명일 월차/연차/반차자 명단</div>
                    <?php if (count($leaveTomorrowPeople) === 0): ?>
                        <div class="text-base leading-8 text-gray-700">명일 월차/연차/반차자는 없습니다.</div>
                    <?php else: ?>
                        <ul class="space-y-2">
                            <?php foreach ($leaveTomorrowPeople as $person): ?>
                                <li class="text-base leading-8 text-gray-800"><?php echo h($person['name']); ?> / <?php echo h($person['department'] ?: '-'); ?> / <?php echo h($person['position'] ?: '-'); ?></li>
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
                                    <li class="text-base leading-8 text-gray-800"><?php echo h($person['name']); ?> / <?php echo h($person['department'] ?: '-'); ?> / <?php echo h($person['position'] ?: '-'); ?></li>
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
                <div class="text-sm text-gray-500 mt-1">이번 주 누적 근무시간 기준</div>
            </div>
            <div class="px-4 py-2 rounded-2xl bg-red-50 text-red-700 font-extrabold border border-red-100">
                <?php echo count($risk52); ?>명
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
                            <div class="text-sm text-gray-600 mt-1"><?php echo h(($person['department'] ?: '-') . ' / ' . ($person['position'] ?: '-')); ?></div>
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
                    <div class="text-sm text-gray-600 mt-1"><?php echo h((isset($person['department']) && trim((string)$person['department']) !== '' ? $person['department'] : '-') . ' / ' . (isset($person['position']) && trim((string)$person['position']) !== '' ? $person['position'] : (isset($person['role']) && trim((string)$person['role']) !== '' ? $person['role'] : '-'))); ?></div>
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
    <div class="p-4 rounded-3xl bg-amber-50 border">
        <div class="text-gray-600 font-bold">미처리 이슈</div>
        <div class="text-4xl font-extrabold text-amber-700 mt-2"><?php echo count($issues); ?>건</div>
    </div>
    <div class="p-4 rounded-3xl bg-rose-50 border">
        <div class="text-gray-600 font-bold">안전사고</div>
        <div class="text-4xl font-extrabold text-rose-700 mt-2"><?php echo count($safetyIncidents); ?>건</div>
    </div>
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
    <div class="space-y-3">
        <?php if (count($pendingGongsuOverrides) === 0): ?>
            <div class="text-sm text-gray-500">승인 대기 중인 공수 수정 요청이 없습니다.</div>
        <?php else: ?>
            <?php foreach ($pendingGongsuOverrides as $ov): ?>
                <?php $requesterName = trim((string)$ov['requested_by_name']) !== '' ? $ov['requested_by_name'] : (trim((string)$ov['requested_emp_name']) !== '' ? $ov['requested_emp_name'] : (trim((string)$ov['requested_by_email']) !== '' ? $ov['requested_by_email'] : '-')); ?>
                <div class="p-4 rounded-2xl border bg-gray-50 border-gray-100">
                    <div class="text-xs text-gray-500">요청일 <?php echo h($ov['created_at']); ?></div>
                    <div class="font-bold text-gray-900 mt-1 text-lg">현장명: <?php echo h($ov['project_name'] ?: '-'); ?></div>
                    <div class="text-sm text-gray-700 mt-1">요청자: <?php echo h($requesterName); ?></div>
                    <div class="text-sm text-gray-700 mt-1">작업자: <?php echo h($ov['worker_name']); ?> / 작업일자: <?php echo h($ov['work_date']); ?></div>
                    <div class="text-sm text-gray-700">변경: <?php echo h($ov['old_value']); ?> → <span class="font-extrabold text-emerald-700"><?php echo h($ov['new_value']); ?></span></div>
                    <div class="text-sm text-gray-700">사유: <?php echo h(trim((string)$ov['reason']) !== '' ? $ov['reason'] : '-'); ?></div>
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
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="font-extrabold text-gray-900"><?php echo h(isset($it['title']) && trim((string)$it['title']) !== '' ? $it['title'] : (isset($it['reason']) ? $it['reason'] : '-')); ?></div>
                            <div class="text-xs text-gray-500 mt-1">
                                현장명: <b><?php echo h($it['project_name']); ?></b>
                                · 등록자: <?php echo h($it['created_by_name']); ?>
                                · 등록일: <?php echo h($it['created_at']); ?>
                            </div>
                            <div class="text-sm text-gray-700 mt-2 whitespace-pre-line"><?php echo h(isset($it['description']) && trim((string)$it['description']) !== '' ? $it['description'] : (isset($it['content']) ? $it['content'] : '-')); ?></div>

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

                        <div class="flex flex-col items-end gap-2">
                            <form method="post" action="?r=construction/issue_state_save" class="flex items-center gap-2">
                                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                <input type="hidden" name="issue_id" value="<?php echo $issueId; ?>">
                                <input type="hidden" name="redirect" value="dashboard_executive">
                                <span class="text-xs font-bold px-3 py-1 rounded-full border <?php echo h($statusBadgeClass($stt)); ?>"><?php echo h($stt); ?></span>
                                <select name="status" class="px-3 py-2 rounded-2xl border border-gray-200 text-sm">
                                    <option value="접수" <?php echo ($stt === '접수') ? 'selected' : ''; ?>>접수</option>
                                    <option value="처리중" <?php echo ($stt === '처리중') ? 'selected' : ''; ?>>처리중</option>
                                    <option value="처리완료" <?php echo ($stt === '처리완료') ? 'selected' : ''; ?>>처리완료</option>
                                </select>
                                <button type="submit" class="px-3 py-2 rounded-2xl bg-gray-900 text-white font-extrabold text-sm">변경</button>
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
