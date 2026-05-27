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

use App\Core\Db;

$user = \App\Core\Auth::user();
$pdo = Db::pdo();
$userEmail = (string)\App\Core\Auth::userEmail();
$flash = flash_get();

$projectCostSummary = cpms_dashboard_project_cost_summary($pdo);
$projectCostCount = isset($projectCostSummary['project_count']) ? (int)$projectCostSummary['project_count'] : 0;

$issues = array();
$safetyIncidents = array();
$issueCommentsByIssueId = array();
$pendingGongsuOverrides = array();
$pendingEquipmentGongsuOverrides = array();
$myUserId = cpms_find_employee_id_by_email($pdo, $userEmail);

$today = attendance_today();
list($weekStart, $weekEnd) = attendance_week_range($today);
$risk52 = array();
$absent = array();
$presentPeople = array();
$leavePeople = array();
$leaveToday = 0;
$todayPresent = 0;

$leaveExTypes = array('월차', '연차', '반차', '오전반차', '오후반차', '월차반차', '연차반차', '오전월차반차', '오후월차반차', '오전연차반차', '오후연차반차', '대체휴무', '기타휴무', '휴무');
$leaveMainTypes = array('월차', '연차', '반차', '오전반차', '오후반차', '월차반차', '연차반차', '오전월차반차', '오후월차반차', '오전연차반차', '오후연차반차');

$statusBadgeClass = function ($status) {
    if ($status === '처리완료') return 'bg-emerald-50 text-emerald-700 border-emerald-100';
    if ($status === '처리중') return 'bg-blue-50 text-blue-700 border-blue-100';
    return 'bg-rose-50 text-rose-700 border-rose-100';
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

        $leaveQMarks = implode(',', array_fill(0, count($leaveExTypes), '?'));
        $leaveSql = "SELECT DISTINCT employee_id
                     FROM cpms_leave_records
                     WHERE leave_date = ?
                       AND leave_type IN (" . $leaveQMarks . ")";
        $leaveParams = array_merge(array($today), $leaveExTypes);
        $stLeaveEx = $pdo->prepare($leaveSql);
        $stLeaveEx->execute($leaveParams);
        $leaveExMap = array();
        foreach ($stLeaveEx->fetchAll(PDO::FETCH_COLUMN, 0) as $eid) {
            $leaveExMap[(int)$eid] = 1;
        }

        $activeRows = $pdo->query("SELECT id, name, department, position FROM employees WHERE is_active = 1")->fetchAll();
        $stAtt = $pdo->prepare("SELECT DISTINCT employee_id
                                FROM cpms_attendance_records
                                WHERE work_date = ?
                                  AND (check_in IS NOT NULL OR status IN ('출근중','퇴근완료'))");
        $stAtt->execute(array($today));
        $attMap = array();
        foreach ($stAtt->fetchAll(PDO::FETCH_COLUMN, 0) as $eid) {
            $attMap[(int)$eid] = 1;
        }
        $todayPresent = count($attMap);

        foreach ($activeRows as $ar) {
            $eid = (int)$ar['id'];
            if (isset($attMap[$eid])) {
                $presentPeople[] = array(
                    'name' => $ar['name'],
                    'department' => $ar['department'],
                    'position' => $ar['position'],
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

        $leaveMainQMarks = implode(',', array_fill(0, count($leaveMainTypes), '?'));
        $leaveMainSql = "SELECT COUNT(DISTINCT employee_id)
                         FROM cpms_leave_records
                         WHERE leave_date = ?
                           AND leave_type IN (" . $leaveMainQMarks . ")";
        $leaveMainParams = array_merge(array($today), $leaveMainTypes);
        $stL = $pdo->prepare($leaveMainSql);
        $stL->execute($leaveMainParams);
        $leaveToday = (int)$stL->fetchColumn();

        $leavePeopleSql = "SELECT DISTINCT e.name, e.department, e.position
                           FROM cpms_leave_records l
                           INNER JOIN employees e ON e.id = l.employee_id
                           WHERE l.leave_date = ?
                             AND l.leave_type IN (" . $leaveMainQMarks . ")
                           ORDER BY e.name ASC";
        $stLeavePeople = $pdo->prepare($leavePeopleSql);
        $stLeavePeople->execute($leaveMainParams);
        $leavePeople = $stLeavePeople->fetchAll();
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
                                <li class="text-base leading-8 text-gray-800"><?php echo h($person['name']); ?> / <?php echo h($person['department'] ?: '-'); ?> / <?php echo h($person['position'] ?: '-'); ?></li>
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
                                    <li class="text-base leading-8 text-gray-800"><?php echo h($person['name']); ?> / <?php echo h($person['department'] ?: '-'); ?> / <?php echo h($person['position'] ?: '-'); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </details>
            </div>
            <div class="p-4 rounded-2xl bg-indigo-50 md:col-span-2 relative group cursor-pointer">
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
        </div>
    </div>

    <div class="bg-white/80 rounded-3xl p-6 border">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="p-4 rounded-2xl bg-blue-50">
                <div class="text-gray-600 font-bold">전체 프로젝트</div>
                <div class="text-4xl font-extrabold text-blue-700 mt-2"><?php echo $projectCostCount; ?>건</div>
            </div>
            <div class="p-4 rounded-2xl bg-amber-50">
                <div class="text-gray-600 font-bold">미처리 이슈</div>
                <div class="text-4xl font-extrabold text-amber-700 mt-2"><?php echo count($issues); ?>건</div>
            </div>
            <div class="p-4 rounded-2xl bg-rose-50">
                <div class="text-gray-600 font-bold">안전사고</div>
                <div class="text-4xl font-extrabold text-rose-700 mt-2"><?php echo count($safetyIncidents); ?>건</div>
            </div>
        </div>
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

<?php cpms_render_executive_task_dashboard($pdo); ?>

<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 mb-8">
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

<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 mb-8">
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

<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 mb-6">
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

<div class="mt-8">
    <?php render_task_list_sample(); ?>
</div>
