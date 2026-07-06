<?php
/**
 * C:\www\cpms\app\views\dashboard\employee.php
 * - 기존 샘플 유지 + ✅ "내가 등록한 이슈" 목록 추가
 */

require_once __DIR__ . '/../partials/TaskList.php';
require_once __DIR__ . '/../partials/cost_metrics.php';
require_once __DIR__ . '/../tasks/dashboard_sections.php';
require_once __DIR__ . '/notice_board.php';

use App\Core\Db;

$user = \App\Core\Auth::user();
$pdo = Db::pdo();
if (function_exists('cpms_tasks_process_delayed_notifications')) {
    $delayNotifyNow = time();
    $delayNotifyKey = '_cpms_dashboard_delay_notify_at';
    $delayNotifyLast = isset($_SESSION[$delayNotifyKey]) ? (int)$_SESSION[$delayNotifyKey] : 0;
    if ($delayNotifyLast <= 0 || ($delayNotifyNow - $delayNotifyLast) >= 300) {
        $_SESSION[$delayNotifyKey] = $delayNotifyNow;
        cpms_tasks_process_delayed_notifications($pdo, 20);
    }
}

$userEmail = '';
$userName  = '';
try {
    $userEmail = (string)\App\Core\Auth::userEmail();
    $userName  = (string)\App\Core\Auth::userName();
} catch (Exception $e) {
    $userEmail = '';
    $userName = '';
}

$myIssues = array();
if ($pdo && $userEmail !== '') {
    try {
        $sql = "SELECT i.*, p.name AS project_name
                FROM cpms_project_issues i
                LEFT JOIN cpms_projects p ON p.id = i.project_id
                WHERE i.created_by_email = :em
                ORDER BY i.id DESC
                LIMIT 20";
        $st = $pdo->prepare($sql);
        $st->bindValue(':em', $userEmail);
        $st->execute();
        $myIssues = $st->fetchAll();
    } catch (Exception $e) {
        $myIssues = array();
    }
}

// ✅ 내 프로젝트 안전사고(최근 10) - 공사팀이 "내 프로젝트만" 보는 흐름 보조용
$mySafety = array();
if ($pdo && $userEmail !== '') {
    try {
        // employees 테이블에서 내 employee_id 찾고, 멤버(main/sub) 프로젝트만 조회
        $eid = 0;
        $stE = $pdo->prepare("SELECT id FROM employees WHERE email = :em LIMIT 1");
        $stE->bindValue(':em', $userEmail);
        $stE->execute();
        $eid = (int)$stE->fetchColumn();

        if ($eid > 0) {
            $sql = "SELECT i.*, p.name AS project_name
                    FROM cpms_safety_incidents i
                    LEFT JOIN cpms_projects p ON p.id = i.project_id
                    JOIN cpms_project_members pm ON pm.project_id = i.project_id
                    WHERE pm.employee_id = :eid
                      AND pm.role IN ('main','sub')
                    ORDER BY i.id DESC
                    LIMIT 10";
            $st = $pdo->prepare($sql);
            $st->bindValue(':eid', $eid, PDO::PARAM_INT);
            $st->execute();
            $mySafety = $st->fetchAll();
        }
    } catch (Exception $e) {
        $mySafety = array();
    }
}


// ✅ 원가/공정 KPI(week/month)
$period = isset($_GET['period']) ? trim((string)$_GET['period']) : 'week';
if ($period !== 'month') $period = 'week';
$periodLabel = ($period === 'month') ? '월간' : '주간';

$kpiRows = array();
if ($pdo && $userEmail !== '') {
    try {
        $eid2 = 0;
        $stE2 = $pdo->prepare("SELECT id FROM employees WHERE email = :em LIMIT 1");
        $stE2->bindValue(':em', $userEmail);
        $stE2->execute();
        $eid2 = (int)$stE2->fetchColumn();

        if ($eid2 > 0) {
            $sql = "SELECT DISTINCT p.id, p.name
                    FROM cpms_projects p
                    JOIN cpms_project_members pm ON pm.project_id = p.id
                    WHERE pm.employee_id = :eid
                      AND LOWER(TRIM(pm.role)) IN ('main','sub')
                      AND p.name NOT LIKE '(가제)%'
                    ORDER BY p.id DESC";
            $stP = $pdo->prepare($sql);
            $stP->bindValue(':eid', $eid2, PDO::PARAM_INT);
            $stP->execute();
            $ps = $stP->fetchAll();

            foreach ($ps as $pr) {
                $m = cpms_project_cost_metrics($pdo, (int)$pr['id'], $period);
                $m['project_id'] = (int)$pr['id'];
                $m['project_name'] = (string)$pr['name'];
                $kpiRows[count($kpiRows)] = $m;
            }

            usort($kpiRows, function($a, $b){
                $av = ($a['cost_rate'] === null) ? -1 : (float)$a['cost_rate'];
                $bv = ($b['cost_rate'] === null) ? -1 : (float)$b['cost_rate'];
                if ($av === $bv) return 0;
                return ($av > $bv) ? -1 : 1;
            });
        }
    } catch (Exception $e) {
        $kpiRows = array();
    }
}

$flash = flash_get();

// 요청사항(받은요청/나의요청)
$myReceivedRequests = array();
$mySentRequests = array();
$requestTargetNameMap = array();
$myUserId = cpms_find_employee_id_by_email($pdo, $userEmail);
$reqStore = cpms_request_store_load();
$allReq = isset($reqStore['requests']) && is_array($reqStore['requests']) ? $reqStore['requests'] : array();
if ($pdo) {
    try {
        $stNm = $pdo->query("SELECT id, name FROM employees");
        $nmRows = $stNm->fetchAll();
        foreach ($nmRows as $nr) $requestTargetNameMap[(int)$nr['id']] = (string)$nr['name'];
    } catch (Exception $e) {
        $requestTargetNameMap = array();
    }
}
for ($i = count($allReq) - 1; $i >= 0; $i--) {
    $rq = $allReq[$i];
    if (!is_array($rq)) continue;
    if ((int)$myUserId > 0 && (int)$rq['target_user_id'] === (int)$myUserId) $myReceivedRequests[count($myReceivedRequests)] = $rq;
    if ((int)$myUserId > 0 && (int)$rq['requester_user_id'] === (int)$myUserId) $mySentRequests[count($mySentRequests)] = $rq;
}

?>


<!-- 대시보드 명판 최상단 -->
<div class="cpms-dashboard-hero bg-gradient-to-r from-blue-600 to-cyan-500 rounded-3xl p-8 text-white shadow-xl shadow-blue-500/20 mb-8">
    <div class="flex items-start gap-4">
        <div class="p-4 bg-white/20 rounded-3xl border border-white/20">
            <i data-lucide="sparkles" class="w-8 h-8 text-yellow-200"></i>
        </div>
        <div class="flex-1">
            <h2 class="text-3xl font-extrabold">대시보드</h2>
            <p class="text-blue-100 text-lg mt-2"><?php echo ($userName !== '') ? (h($userName) . '님, ') : ''; ?>오늘도 안전하게 진행하세요.</p>
        </div>
        <div class="cpms-attendance-actions flex flex-wrap items-center gap-3"><!-- 직원 대시보드 UI 정리 -->
            <?php
            require_once __DIR__.'/../attendance/common.php';
            $eid_btn = attendance_employee_id($pdo);
            $today_btn = attendance_today();
            $row_btn = attendance_today_record($pdo, $eid_btn);
            $todayRecordId = ($row_btn && isset($row_btn['id'])) ? (int)$row_btn['id'] : 0;
            $todayCheckIn = ($row_btn && isset($row_btn['check_in']) && $row_btn['check_in']) ? (string)$row_btn['check_in'] : '';
            $todayCheckOut = ($row_btn && isset($row_btn['check_out']) && $row_btn['check_out']) ? (string)$row_btn['check_out'] : '';
            $attendanceGeofence = attendance_geofence_settings($pdo);
            $canCheckIn = false;
            $canCheckOut = false;
            $showDone = false;
            $hasEmployee = ((int)$eid_btn > 0);
            if ($hasEmployee) {
                if (!$row_btn || $todayCheckIn === '') {
                    $canCheckIn = true;
                } else if ($todayCheckIn !== '' && $todayCheckOut === '') {
                    $canCheckOut = true;
                } else {
                    $showDone = true;
                }
            }
            $debugAttendance = isset($_GET['debug_attendance']) && (string)$_GET['debug_attendance'] === '1';
            ?>
            <?php if (!$hasEmployee): ?>
                <div class='px-5 py-3 rounded-2xl bg-amber-100 text-amber-800 font-extrabold text-base'>직원 정보를 찾을 수 없습니다.</div>
            <?php else: ?>
                <?php if ($canCheckIn): ?>
                    <form method='post' action='?r=attendance/check_in'><input type='hidden' name='_csrf' value='<?php echo h(csrf_token());?>'><button class='px-5 py-3 rounded-2xl bg-white text-blue-700 font-extrabold text-base'>출근</button></form>
                <?php endif; ?>
                <?php if ($canCheckOut): ?>
                    <form method='post' action='?r=attendance/check_out'><input type='hidden' name='_csrf' value='<?php echo h(csrf_token());?>'><button class='px-5 py-3 rounded-2xl bg-emerald-100 text-emerald-700 font-extrabold text-base'>퇴근</button></form>
                <?php endif; ?>
                <?php if ($showDone): ?>
                    <div class='px-5 py-3 rounded-2xl bg-slate-100 text-slate-700 font-extrabold text-base'>오늘 근무 완료</div>
                <?php endif; ?>
            <?php endif; ?>
            <button type='button' data-attendance-request-open class='cpms-mobile-hide px-5 py-3 rounded-2xl bg-blue-900/80 text-white font-extrabold text-base border border-white/40'>출퇴근 요청</button>
            <?php if ($debugAttendance): ?>
                <div class='basis-full mt-1 p-3 rounded-xl bg-black/60 text-white text-xs leading-6'>
                    employee_id: <?php echo h((string)$eid_btn); ?><br>
                    attendance_today(): <?php echo h($today_btn); ?><br>
                    attendance_now(): <?php echo h(attendance_now()); ?><br>
                    today_record_id: <?php echo h($todayRecordId > 0 ? (string)$todayRecordId : '없음'); ?><br>
                    today_record_work_date: <?php echo h(($row_btn && isset($row_btn['work_date']) && $row_btn['work_date']) ? (string)$row_btn['work_date'] : '없음'); ?><br>
                    today_check_in: <?php echo h($todayCheckIn !== '' ? $todayCheckIn : '없음'); ?><br>
                    today_check_out: <?php echo h($todayCheckOut !== '' ? $todayCheckOut : '없음'); ?><br>
                    canCheckIn: <?php echo $canCheckIn ? 'true' : 'false'; ?><br>
                    canCheckOut: <?php echo $canCheckOut ? 'true' : 'false'; ?>
                </div>
            <?php endif; ?>
        </div>  
    </div>
</div>

<?php cpms_render_dashboard_notice_modal(); ?>

<?php
require_once __DIR__ . '/../attendance/common.php';
if (!function_exists('cpms_dashboard_attendance_time')) {
function cpms_dashboard_attendance_time($value) {
    $value = trim((string)$value);
    if ($value === '') return '';
    if (strlen($value) >= 16) return substr($value, 11, 5);
    return $value;
}}
if (!function_exists('cpms_dashboard_attendance_is_late')) {
function cpms_dashboard_attendance_is_late($checkIn) {
    $time = cpms_dashboard_attendance_time($checkIn);
    if ($time === '' || strlen($time) < 5) return false;
    return (strcmp(substr($time, 0, 5), '08:00') > 0);
}}
if (!function_exists('cpms_dashboard_attendance_is_missing_checkout')) {
function cpms_dashboard_attendance_is_missing_checkout($workDate, $checkIn, $checkOut, $today, $nowTime, $cutoffTime) {
    $workDate = trim((string)$workDate);
    $checkIn = trim((string)$checkIn);
    $checkOut = trim((string)$checkOut);
    if ($workDate === '' || $checkIn === '' || $checkOut !== '') return false;
    if ($workDate < $today) return true;
    if ($workDate === $today && strcmp($nowTime, $cutoffTime) >= 0) return true;
    return false;
}}
if (!function_exists('cpms_dashboard_attendance_is_business_day')) {
function cpms_dashboard_attendance_is_business_day($workDate) {
    $ts = strtotime($workDate);
    if ($ts === false) return false;
    $weekNo = (int)date('N', $ts);
    return ($weekNo >= 1 && $weekNo <= 5);
}}
$eid_att = attendance_employee_id($pdo);
$today_att = attendance_today();
$attendanceNow_att = attendance_now();
$attendanceNowTime_att = strlen($attendanceNow_att) >= 19 ? substr($attendanceNow_att, 11, 8) : date('H:i:s');
$attendanceMissingCheckoutCutoff = '18:00:00';
$attendanceWorkMonth = isset($_GET['attendance_work_month']) ? trim((string)$_GET['attendance_work_month']) : substr($today_att, 0, 7);
$attendanceWorkWeekParam = isset($_GET['attendance_work_week']) ? trim((string)$_GET['attendance_work_week']) : '';
$attendanceWorkWeekSelection = attendance_month_week_selection($attendanceWorkMonth, $attendanceWorkWeekParam, $today_att);
$attendanceWorkMonth = isset($attendanceWorkWeekSelection['month']) ? $attendanceWorkWeekSelection['month'] : substr($today_att, 0, 7);
$ws_att = isset($attendanceWorkWeekSelection['start']) ? $attendanceWorkWeekSelection['start'] : $today_att;
$we_att = isset($attendanceWorkWeekSelection['end']) ? $attendanceWorkWeekSelection['end'] : $today_att;
$attendanceWorkWeekOptions = isset($attendanceWorkWeekSelection['options']) && is_array($attendanceWorkWeekSelection['options']) ? $attendanceWorkWeekSelection['options'] : array();
$attendanceWorkWeekLabel = isset($attendanceWorkWeekSelection['label']) ? (string)$attendanceWorkWeekSelection['label'] : '';
$attendanceWorkWeekRangeLabel = isset($attendanceWorkWeekSelection['range_label']) ? (string)$attendanceWorkWeekSelection['range_label'] : ($ws_att . ' ~ ' . $we_att);
$attendanceRequestMonth = isset($_GET['attendance_request_month']) ? trim((string)$_GET['attendance_request_month']) : date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $attendanceRequestMonth)) $attendanceRequestMonth = date('Y-m');
$attendanceRequestMonthStart = $attendanceRequestMonth . '-01';
$attendanceRequestMonthEnd = date('Y-m-t', strtotime($attendanceRequestMonthStart));
$attendanceIssueSince = '2026-07-01';
$attendanceIssueSinceMonth = substr($attendanceIssueSince, 0, 7);
$attendanceIssueCurrentMonth = substr($today_att, 0, 7);
$attendanceIssueMonth = isset($_GET['attendance_issue_month']) ? trim((string)$_GET['attendance_issue_month']) : 'all';
if ($attendanceIssueMonth !== 'all' && !preg_match('/^\d{4}-\d{2}$/', $attendanceIssueMonth)) $attendanceIssueMonth = 'all';
if ($attendanceIssueMonth !== 'all' && $attendanceIssueMonth < $attendanceIssueSinceMonth) $attendanceIssueMonth = $attendanceIssueSinceMonth;
$attendanceIssueMonthOptions = array();
$issueMonthStartTs = strtotime($attendanceIssueSinceMonth . '-01');
$issueMonthEndTs = strtotime($attendanceIssueCurrentMonth . '-01');
if ($issueMonthEndTs !== false && $issueMonthStartTs !== false && $issueMonthEndTs < $issueMonthStartTs) $issueMonthEndTs = $issueMonthStartTs;
while ($issueMonthStartTs !== false && $issueMonthEndTs !== false && $issueMonthStartTs <= $issueMonthEndTs) {
    $attendanceIssueMonthOptions[count($attendanceIssueMonthOptions)] = array(
        'value' => date('Y-m', $issueMonthStartTs),
        'label' => date('Y년 n월', $issueMonthStartTs)
    );
    $issueMonthStartTs = strtotime('+1 month', $issueMonthStartTs);
}
$attendanceIssueRangeLabel = '전체(2026-07-01 이후)';
if ($attendanceIssueMonth !== 'all') {
    $issueSelectedTs = strtotime($attendanceIssueMonth . '-01');
    if ($issueSelectedTs !== false) $attendanceIssueRangeLabel = date('Y년 n월', $issueSelectedTs);
}
$todayRow = array();
$todayInState = '미처리';
$todayOutState = '미처리';
$myReqs = array();
$myAttendanceIssues = array();
$myMissingCheckoutCount = 0;
$myLateCount = 0;
$myAbsentCount = 0;
$myFilteredMissingCheckoutCount = 0;
$myFilteredLateCount = 0;
$myFilteredAbsentCount = 0;
$pendingCnt = 0;
$weekWork = 0;
$todayMismatch = false;
if ($pdo && $eid_att > 0) {
    try {
        $stTodayRaw = $pdo->prepare("SELECT * FROM cpms_attendance_records WHERE employee_id=:e AND work_date=:d LIMIT 1");
        $stTodayRaw->execute(array(':e' => $eid_att, ':d' => $today_att));
        $todayRaw = $stTodayRaw->fetch(PDO::FETCH_ASSOC);
        $todayMismatch = ($todayRaw && !attendance_record_datetime_matches_work_date($todayRaw));
        $todayRow = attendance_today_record($pdo, $eid_att);

        $st2 = $pdo->prepare("SELECT * FROM cpms_attendance_requests WHERE employee_id=:e AND request_date BETWEEN :s AND :e2 ORDER BY id DESC LIMIT 100");
        $st2->execute(array(':e' => $eid_att, ':s' => $attendanceRequestMonthStart, ':e2' => $attendanceRequestMonthEnd));
        $myReqs = $st2->fetchAll();
        if (!is_array($myReqs)) $myReqs = array();

        $st3 = $pdo->prepare("SELECT COALESCE(SUM(work_minutes),0) FROM cpms_attendance_records WHERE employee_id=:e AND work_date BETWEEN :s AND :w");
        $st3->execute(array(':e' => $eid_att, ':s' => $ws_att, ':w' => $we_att));
        $weekWork = (int)$st3->fetchColumn();

        $st4 = $pdo->prepare("SELECT COUNT(*) FROM cpms_attendance_requests WHERE employee_id=:e AND status='pending'");
        $st4->execute(array(':e' => $eid_att));
        $pendingCnt = (int)$st4->fetchColumn();

        $attendanceRecordDateMap = array();
        $attendanceLeaveMap = array();
        if (attendance_table_exists($pdo, 'cpms_leave_records')) {
            try {
                $stLeave = $pdo->prepare("SELECT leave_date FROM cpms_leave_records WHERE employee_id=:e AND leave_date BETWEEN :s AND :t");
                $stLeave->execute(array(':e' => $eid_att, ':s' => $attendanceIssueSince, ':t' => $today_att));
                $leaveRows = $stLeave->fetchAll(PDO::FETCH_ASSOC);
                if (is_array($leaveRows)) {
                    foreach ($leaveRows as $leaveRow) {
                        $leaveDate = isset($leaveRow['leave_date']) ? trim((string)$leaveRow['leave_date']) : '';
                        if ($leaveDate !== '') $attendanceLeaveMap[$leaveDate] = true;
                    }
                }
            } catch (Exception $e) {
            }
        }
        if (attendance_table_exists($pdo, 'cpms_approval_documents')) {
            try {
                $stApprovalLeave = $pdo->prepare("SELECT content FROM cpms_approval_documents WHERE doc_type='leave' AND created_by_id=:e AND UPPER(COALESCE(doc_status,'')) IN ('APPROVED','COMPLETED') ORDER BY id DESC");
                $stApprovalLeave->execute(array(':e' => $eid_att));
                $approvalLeaveRows = $stApprovalLeave->fetchAll(PDO::FETCH_ASSOC);
                if (is_array($approvalLeaveRows)) {
                    foreach ($approvalLeaveRows as $approvalLeaveRow) {
                        $content = array();
                        $rawContent = isset($approvalLeaveRow['content']) ? trim((string)$approvalLeaveRow['content']) : '';
                        if ($rawContent !== '') {
                            $decodedContent = json_decode($rawContent, true);
                            if (is_array($decodedContent)) $content = $decodedContent;
                        }
                        $leaveStart = isset($content['leave_start_date']) ? trim((string)$content['leave_start_date']) : '';
                        $leaveEnd = isset($content['leave_end_date']) ? trim((string)$content['leave_end_date']) : '';
                        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $leaveStart) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $leaveEnd)) continue;
                        if ($leaveEnd < $attendanceIssueSince || $leaveStart > $today_att) continue;
                        $cursorTs = strtotime($leaveStart < $attendanceIssueSince ? $attendanceIssueSince : $leaveStart);
                        $endTs = strtotime($leaveEnd > $today_att ? $today_att : $leaveEnd);
                        while ($cursorTs !== false && $endTs !== false && $cursorTs <= $endTs) {
                            $leaveDate = date('Y-m-d', $cursorTs);
                            if (cpms_dashboard_attendance_is_business_day($leaveDate)) $attendanceLeaveMap[$leaveDate] = true;
                            $cursorTs = strtotime('+1 day', $cursorTs);
                        }
                    }
                }
            } catch (Exception $e) {
            }
        }

        $stIssue = $pdo->prepare("SELECT id,work_date,check_in,check_out,status,created_at,updated_at FROM cpms_attendance_records WHERE employee_id=:e AND work_date BETWEEN :issue_since AND :today ORDER BY work_date DESC, id DESC");
        $stIssue->execute(array(':e' => $eid_att, ':issue_since' => $attendanceIssueSince, ':today' => $today_att));
        $issueRows = $stIssue->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($issueRows)) {
            foreach ($issueRows as $issueRow) {
                $issueDateValue = isset($issueRow['work_date']) ? trim((string)$issueRow['work_date']) : '';
                if ($issueDateValue === '' || $issueDateValue < $attendanceIssueSince) continue;
                $issueCheckIn = isset($issueRow['check_in']) ? trim((string)$issueRow['check_in']) : '';
                $issueCheckOut = isset($issueRow['check_out']) ? trim((string)$issueRow['check_out']) : '';
                if ($issueCheckIn !== '') $attendanceRecordDateMap[$issueDateValue] = true;
                $issueMissing = cpms_dashboard_attendance_is_missing_checkout($issueDateValue, $issueCheckIn, $issueCheckOut, $today_att, $attendanceNowTime_att, $attendanceMissingCheckoutCutoff);
                $issueLate = cpms_dashboard_attendance_is_late($issueCheckIn);
                if (!$issueMissing && !$issueLate) continue;
                $issueRow['_missing_checkout'] = $issueMissing ? 1 : 0;
                $issueRow['_late'] = $issueLate ? 1 : 0;
                $issueRow['_absent'] = 0;
                if ($issueMissing) $myMissingCheckoutCount++;
                if ($issueLate) $myLateCount++;
                if ($attendanceIssueMonth === 'all' || substr($issueDateValue, 0, 7) === $attendanceIssueMonth) {
                    if ($issueMissing) $myFilteredMissingCheckoutCount++;
                    if ($issueLate) $myFilteredLateCount++;
                    $myAttendanceIssues[count($myAttendanceIssues)] = $issueRow;
                }
            }
        }
        $absenceTs = strtotime($attendanceIssueSince);
        $absenceEndTs = strtotime($today_att);
        while ($absenceTs !== false && $absenceEndTs !== false && $absenceTs <= $absenceEndTs) {
            $absenceDate = date('Y-m-d', $absenceTs);
            $showTodayAbsence = ($absenceDate < $today_att || ($absenceDate === $today_att && strcmp($attendanceNowTime_att, $attendanceMissingCheckoutCutoff) >= 0));
            if ($showTodayAbsence && cpms_dashboard_attendance_is_business_day($absenceDate) && !isset($attendanceLeaveMap[$absenceDate]) && !isset($attendanceRecordDateMap[$absenceDate])) {
                $absentRow = array(
                    'id' => 0,
                    'work_date' => $absenceDate,
                    'check_in' => '',
                    'check_out' => '',
                    'status' => '미출근',
                    'created_at' => '',
                    'updated_at' => '',
                    '_missing_checkout' => 0,
                    '_late' => 0,
                    '_absent' => 1
                );
                $myAbsentCount++;
                if ($attendanceIssueMonth === 'all' || substr($absenceDate, 0, 7) === $attendanceIssueMonth) {
                    $myFilteredAbsentCount++;
                    $myAttendanceIssues[count($myAttendanceIssues)] = $absentRow;
                }
            }
            $absenceTs = strtotime('+1 day', $absenceTs);
        }
        usort($myAttendanceIssues, function($a, $b) {
            $ad = isset($a['work_date']) ? (string)$a['work_date'] : '';
            $bd = isset($b['work_date']) ? (string)$b['work_date'] : '';
            if ($ad === $bd) return 0;
            return ($ad > $bd) ? -1 : 1;
        });

        if ($todayRow) {
            $todayInState = (isset($todayRow['check_in']) && $todayRow['check_in']) ? '처리' : '미처리';
            $todayOutState = (isset($todayRow['check_out']) && $todayRow['check_out']) ? '처리' : '미처리';
        }
    } catch (Exception $e) {
    }
}
?>
<script>
(function(){
    try{
        var forms = document.querySelectorAll("form[action='?r=attendance/check_in'], form[action='?r=attendance/check_out']");
        if(!forms || !forms.length) return;
        var geofenceEnabled = <?php echo !empty($attendanceGeofence['enabled']) ? 'true' : 'false'; ?>;
        function ensureHidden(form, name){
            var input = form.querySelector("input[name='" + name + "']");
            if(!input){
                input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                form.appendChild(input);
            }
            return input;
        }
        function restoreButton(button, text){
            if(!button) return;
            button.disabled = false;
            button.textContent = text;
        }
        function handleSubmit(e){
            var form = e.currentTarget;
            if(!form || !geofenceEnabled) return;
            if(form.getAttribute('data-geo-ready') === '1') return;
            e.preventDefault();
            if(!navigator.geolocation){
                alert('이 브라우저에서는 위치 확인을 지원하지 않습니다.');
                return;
            }
            var button = form.querySelector('button[type=\"submit\"], button:not([type])');
            var originalText = button ? button.textContent : '';
            if(button){
                button.disabled = true;
                button.textContent = '위치 확인 중...';
            }
            navigator.geolocation.getCurrentPosition(function(pos){
                ensureHidden(form, 'geo_lat').value = pos.coords.latitude;
                ensureHidden(form, 'geo_lng').value = pos.coords.longitude;
                ensureHidden(form, 'geo_accuracy').value = pos.coords.accuracy;
                form.setAttribute('data-geo-ready', '1');
                form.submit();
            }, function(err){
                restoreButton(button, originalText);
                if(err && err.code === 1){
                    alert('위치 권한을 허용해야 출퇴근을 등록할 수 있습니다.');
                    return;
                }
                alert('현재 위치를 확인하지 못했습니다. 잠시 후 다시 시도해주세요.');
            }, {
                enableHighAccuracy: true,
                timeout: 12000,
                maximumAge: 0
            });
        }
        for(var i=0;i<forms.length;i++){
            forms[i].addEventListener('submit', handleSubmit);
        }
    }catch(e){}
})();
</script>

<div class="cpms-dashboard-attendance-block">
<div class='bg-white/80 rounded-3xl p-6 border mb-6'><!-- 직원 대시보드 UI 정리 + 공제시간 표시 제거 -->
<h3 class='text-2xl font-extrabold mb-4'>내 근태 현황</h3>
<div class='grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 text-base'>
<div class='p-4 rounded-2xl bg-gray-50'><div class='text-gray-500'>오늘 상태</div><div class='font-extrabold text-lg'><?php echo h(isset($todayRow['status'])?$todayRow['status']:'출근 전');?></div></div>
<div class='p-4 rounded-2xl bg-gray-50'><div class='text-gray-500'>출근 / 퇴근</div><div class='font-extrabold text-lg'><?php if($todayMismatch){ ?>날짜 불일치 기록 감지<br><span class='text-red-600 text-base'>관리자 확인 필요</span><?php } else { ?><?php echo h(isset($todayRow['check_in'])&&$todayRow['check_in']?$todayRow['check_in']:'-');?> / <?php echo h(isset($todayRow['check_out'])&&$todayRow['check_out']?$todayRow['check_out']:'-');?><?php } ?></div></div>
<div class='p-4 rounded-2xl bg-gray-50'>
    <div class='text-gray-500'>선택 주 누적 근무시간</div>
    <div class='font-extrabold text-lg'><?php echo attendance_hm($weekWork);?></div>
    <div class='text-xs text-gray-500 mt-1'><?php echo h($attendanceWorkWeekLabel !== '' ? $attendanceWorkWeekLabel : '선택 주'); ?> · <?php echo h($attendanceWorkWeekRangeLabel); ?></div>
    <form method='get' action='' class='mt-3 flex flex-wrap items-center gap-2'>
        <input type='hidden' name='r' value='대시보드'>
        <input type='hidden' name='dv' value='employee'>
        <input type='hidden' name='period' value='<?php echo h($period); ?>'>
        <input type='month' name='attendance_work_month' value='<?php echo h($attendanceWorkMonth); ?>' class='px-3 py-2 rounded-xl border border-gray-200 bg-white text-sm' onchange='this.form.submit()'>
        <select name='attendance_work_week' class='px-3 py-2 rounded-xl border border-gray-200 bg-white text-sm' onchange='this.form.submit()'>
            <?php foreach($attendanceWorkWeekOptions as $weekOption): ?>
                <option value='<?php echo h(isset($weekOption['value']) ? $weekOption['value'] : ''); ?>' <?php echo (isset($weekOption['start']) && $weekOption['start'] === $ws_att) ? 'selected' : ''; ?>>
                    <?php echo h((isset($weekOption['label']) ? $weekOption['label'] : '') . ' (' . (isset($weekOption['range_label']) ? $weekOption['range_label'] : '') . ')'); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>
</div>
</div>

<?php
// 휴가 현황 잔여만 표시
$vac=array('monthly_granted'=>0.0,'monthly_used'=>0.0,'monthly_left'=>0.0,'annual_granted'=>0.0,'annual_used'=>0.0,'annual_left'=>0.0,'hire_notice'=>'','has_hire_date'=>false,'is_under_one_year'=>false,'display_balance'=>0.0,'display_type'=>'','half_available'=>false);
if($pdo&&$eid_att>0){
 try{
  $emp=$pdo->prepare("SELECT hire_date,leave_monthly_balance,leave_annual_balance FROM employees WHERE id=:e LIMIT 1");
  $emp->execute(array(':e'=>$eid_att));
  $er=$emp->fetch();
  $hireDate=$er&&!empty($er['hire_date'])?(string)$er['hire_date']:'';
  $manualMonthly=($er&&$er['leave_monthly_balance']!==null)?(float)$er['leave_monthly_balance']:null;
  $manualAnnual=($er&&$er['leave_annual_balance']!==null)?(float)$er['leave_annual_balance']:null;
  $base=attendance_auto_leave_granted($hireDate,$today_att);

  $stV=$pdo->prepare("SELECT leave_type,leave_amount FROM cpms_leave_records WHERE employee_id=:e");
  $stV->execute(array(':e'=>$eid_att));
  $legacyHalf=0;
  foreach($stV->fetchAll() as $vr){
    $t=(string)$vr['leave_type']; $amt=(float)$vr['leave_amount']; if($amt<=0)$amt=1.0;
    if($t==='월차'){ $vac['monthly_used']+=$amt; continue; }
    if($t==='연차'){ $vac['annual_used']+=$amt; continue; }
    if($t==='월차반차'||$t==='오전월차반차'||$t==='오후월차반차'){ $vac['monthly_used']+=0.5; continue; }
    if($t==='연차반차'||$t==='오전연차반차'||$t==='오후연차반차'){ $vac['annual_used']+=0.5; continue; }
    if($t==='오전반차'||$t==='오후반차'||$t==='반차'){ $legacyHalf++; continue; }
  }

  if($base['hire_missing']){
    $vac['hire_notice']='휴가 계산 불가';
  }else{
    $vac['has_hire_date']=true;
    $vac['monthly_granted']=(float)$base['monthly'];
    $vac['annual_granted']=(float)$base['annual'];
    $vac['is_under_one_year']=((float)$base['annual']<=0.0);
    if($legacyHalf>0){
      for($i=0;$i<$legacyHalf;$i++){
        if($vac['is_under_one_year']){ $vac['monthly_used']+=0.5; }
        else{ $vac['annual_used']+=0.5; }
      }
    }
    $vac['monthly_left']=($manualMonthly!==null)?$manualMonthly:max(0,$vac['monthly_granted']-$vac['monthly_used']);
    $vac['annual_left']=($manualAnnual!==null)?$manualAnnual:max(0,$vac['annual_granted']-$vac['annual_used']);

    // 1년 미만 월차 기준
    if($vac['is_under_one_year']){
      $vac['display_type']='monthly';
      $vac['display_balance']=(float)$vac['monthly_left'];
    }else{ // 1년 이상 연차 기준
      $vac['display_type']='annual';
      $vac['display_balance']=(float)$vac['annual_left'];
    }
    $vac['half_available']=($vac['display_balance']>=0.5); // 반차 0.5일 차감 표시    
  }

 }catch(Exception $e){}
}
?>
<div class='mt-5 pt-5 border-t border-gray-100'><!-- 휴가 현황 잔여만 표시 -->
<h4 class='text-xl font-extrabold mb-4'>휴가 현황</h4>
<div class='grid grid-cols-1 md:grid-cols-4 gap-3'>
<?php if(!$vac['has_hire_date']): ?>
<div class='md:col-span-2 p-4 rounded-2xl bg-amber-50 border border-amber-200 text-amber-800'>
  <div class='font-extrabold text-lg'>휴가 계산 불가</div>
  <div class='text-sm mt-1'>직원명부에서 입사일을 입력해주세요.</div>
</div>
<?php else: ?>
  <div class='p-4 rounded-2xl bg-blue-50 border border-blue-100'>
    <div class='text-gray-600 text-sm'><?php echo ($vac['display_type']==='monthly')?'월차 잔여':'연차 잔여';?></div>
    <div class='font-extrabold text-3xl <?php echo ((float)$vac['display_balance']<0)?'text-rose-700':'text-blue-700';?> mt-2'><?php echo h(attendance_float_fmt($vac['display_balance']));?><span class='text-base ml-1'>일</span></div><?php if((float)$vac['display_balance']<0){ ?><div class='mt-2 text-xs text-rose-700 font-bold'>마이너스 잔여 (청산필요)</div><?php } ?>
  </div>
  <div class='p-4 rounded-2xl bg-gray-50 border border-gray-100'>
    <div class='text-gray-600 text-sm'>반차 가능</div>
    <div class='mt-2'>
      <span class='inline-flex items-center px-3 py-1 rounded-full text-sm font-extrabold <?php echo $vac['half_available']?'bg-emerald-100 text-emerald-700':'bg-red-100 text-red-700';?>'><?php echo $vac['half_available']?'가능':'불가';?></span>
    </div>
  </div>
<?php endif; ?>
  <div class='md:col-span-2 p-4 rounded-2xl bg-rose-50 border border-rose-100 hover:bg-rose-100 transition cursor-pointer' data-attendance-issue-open role='button' tabindex='0'>
    <div class='flex items-start justify-between gap-3'>
      <div class='min-w-0'>
        <div class='text-gray-700 text-sm font-bold'>나의 근태 미처리 현황</div>
        <div class='mt-2 flex flex-wrap items-end gap-2'>
          <span class='font-extrabold text-3xl text-rose-700'><?php echo (int)($myMissingCheckoutCount + $myAbsentCount); ?><span class='text-base ml-1'>건</span></span>
          <span class='inline-flex items-center px-2 py-1 rounded-full text-xs font-extrabold bg-rose-100 text-rose-700'>퇴근 <?php echo (int)$myMissingCheckoutCount; ?>건</span>
          <span class='inline-flex items-center px-2 py-1 rounded-full text-xs font-extrabold bg-slate-200 text-slate-800'>미출근 <?php echo (int)$myAbsentCount; ?>건</span>
          <span class='inline-flex items-center px-2 py-1 rounded-full text-xs font-extrabold bg-amber-100 text-amber-800'>지각 <?php echo (int)$myLateCount; ?>건</span>
        </div>
        <div class='mt-2 text-xs text-gray-600'>클릭하면 전체 미출근, 퇴근 미처리와 지각 기록을 확인합니다.</div>
      </div>
      <button type='button' data-attendance-request-open data-attendance-request-type='check_out' class='shrink-0 px-3 py-2 rounded-xl bg-gray-900 text-white text-sm font-extrabold'>요청보내기</button>
    </div>
  </div>
</div>
<?php if($vac['has_hire_date']): ?>
<?php if($vac['display_type']!=='monthly'): ?>
<div class='text-sm text-gray-600 mt-3'>
    입사 1년 이상은 연차 기준으로 표시됩니다.
</div>
<div class='text-sm text-gray-500 mt-1'>
    반차는 연차에서 0.5일 차감됩니다.
</div>
<?php endif; ?>
<?php endif; ?>
</div></div>
<div id='attendanceIssueModal' class='fixed inset-0 z-50 hidden'><!-- 내 퇴근 미처리/지각 현황 모달 -->
<div class='absolute inset-0 bg-black/50' data-attendance-issue-close></div>
<div class='relative max-w-5xl mx-auto mt-10 mb-10 bg-white rounded-3xl border shadow-2xl p-6 max-h-[85vh] overflow-y-auto'>
<div class='flex flex-wrap items-start justify-between gap-3 mb-4'>
    <div>
        <h3 class='text-2xl font-extrabold'>나의 근태 미처리/지각 현황</h3>
        <div class='text-sm text-gray-600 mt-1'>전체 퇴근 미처리 <?php echo (int)$myMissingCheckoutCount; ?>건 · 미출근 <?php echo (int)$myAbsentCount; ?>건 · 지각 <?php echo (int)$myLateCount; ?>건</div>
    </div>
    <div class='flex items-center gap-2'>
        <button type='button' data-attendance-request-open data-attendance-request-type='check_out' class='px-4 py-2 rounded-xl bg-gray-900 text-white font-bold'>요청보내기</button>
        <button type='button' data-attendance-issue-close class='px-4 py-2 rounded-xl bg-gray-100 font-bold'>닫기</button>
    </div>
</div>
<div class='mb-3 flex flex-wrap items-end justify-between gap-3'>
    <div>
        <div class='text-sm font-bold text-gray-900'><?php echo h($attendanceIssueRangeLabel); ?></div>
        <div class='text-xs text-gray-500 mt-1'>선택 범위 퇴근 미처리 <?php echo (int)$myFilteredMissingCheckoutCount; ?>건 · 미출근 <?php echo (int)$myFilteredAbsentCount; ?>건 · 지각 <?php echo (int)$myFilteredLateCount; ?>건 · 오늘 미출근/퇴근 미처리는 18:00 이후부터 표시됩니다.</div>
    </div>
    <form method='get' action='' class='flex items-center gap-2'>
        <input type='hidden' name='r' value='대시보드'>
        <select name='attendance_issue_month' class='px-3 py-2 rounded-xl border border-gray-200 text-sm font-bold' onchange='this.form.submit()'>
            <option value='all' <?php echo ($attendanceIssueMonth === 'all') ? 'selected' : ''; ?>>전체</option>
            <?php foreach($attendanceIssueMonthOptions as $issueMonthOption): ?>
                <option value='<?php echo h($issueMonthOption['value']); ?>' <?php echo ($attendanceIssueMonth === $issueMonthOption['value']) ? 'selected' : ''; ?>><?php echo h($issueMonthOption['label']); ?></option>
            <?php endforeach; ?>
        </select>
        <button type='submit' class='px-4 py-2 rounded-xl bg-gray-900 text-white text-sm font-bold'>조회</button>
    </form>
</div>
<div class='overflow-x-auto border rounded-2xl'>
    <table class='min-w-full text-sm'>
        <tr class='bg-gray-50'>
            <th class='p-2 text-left'>근무일</th>
            <th class='p-2 text-left'>구분</th>
            <th class='p-2 text-left'>출근</th>
            <th class='p-2 text-left'>퇴근</th>
            <th class='p-2 text-left'>상태</th>
            <th class='p-2 text-left'>요청</th>
        </tr>
        <?php if (count($myAttendanceIssues) === 0): ?>
            <tr><td colspan='6' class='p-5 text-center text-gray-500'>미출근, 퇴근 미처리 또는 지각 기록이 없습니다.</td></tr>
        <?php else: ?>
            <?php foreach($myAttendanceIssues as $issueRow): ?>
                <?php
                $issueDate = isset($issueRow['work_date']) ? (string)$issueRow['work_date'] : '';
                $issueCheckIn = isset($issueRow['check_in']) ? trim((string)$issueRow['check_in']) : '';
                $issueCheckOut = isset($issueRow['check_out']) ? trim((string)$issueRow['check_out']) : '';
                $issueMissing = !empty($issueRow['_missing_checkout']);
                $issueLate = !empty($issueRow['_late']);
                $issueAbsent = !empty($issueRow['_absent']);
                $issueRequestType = $issueAbsent ? 'check_in' : (($issueMissing && $issueLate) ? 'both' : ($issueMissing ? 'check_out' : 'check_in'));
                ?>
                <tr class='border-t'>
                    <td class='p-2 whitespace-nowrap font-bold text-gray-900'><?php echo h($issueDate); ?></td>
                    <td class='p-2'>
                        <div class='flex flex-wrap gap-1'>
                            <?php if($issueAbsent): ?><span class='inline-flex items-center px-2 py-1 rounded-full text-xs font-extrabold bg-slate-200 text-slate-800'>미출근</span><?php endif; ?>
                            <?php if($issueMissing): ?><span class='inline-flex items-center px-2 py-1 rounded-full text-xs font-extrabold bg-rose-100 text-rose-700'>퇴근 미처리</span><?php endif; ?>
                            <?php if($issueLate): ?><span class='inline-flex items-center px-2 py-1 rounded-full text-xs font-extrabold bg-amber-100 text-amber-800'>지각</span><?php endif; ?>
                        </div>
                    </td>
                    <td class='p-2 whitespace-nowrap'><?php echo h(cpms_dashboard_attendance_time($issueCheckIn)); ?></td>
                    <td class='p-2 whitespace-nowrap'><?php echo h($issueCheckOut !== '' ? cpms_dashboard_attendance_time($issueCheckOut) : '-'); ?></td>
                    <td class='p-2 whitespace-nowrap'><?php echo h(isset($issueRow['status']) ? (string)$issueRow['status'] : ''); ?></td>
                    <td class='p-2 whitespace-nowrap'>
                        <button type='button' data-attendance-request-open data-attendance-request-date='<?php echo h($issueDate); ?>' data-attendance-request-type='<?php echo h($issueRequestType); ?>' class='px-3 py-1 rounded-xl bg-blue-600 text-white text-xs font-extrabold'>요청보내기</button>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </table>
</div>
</div></div>
<div id='attendanceRequestModal' class='fixed inset-0 z-50 hidden'><!-- 출퇴근 요청 모달 -->
<div class='absolute inset-0 bg-black/50' data-attendance-request-close></div><div class='relative max-w-5xl mx-auto mt-10 mb-10 bg-white rounded-3xl border shadow-2xl p-6 max-h-[85vh] overflow-y-auto'>
<div class='flex items-center justify-between mb-4'><h3 class='text-2xl font-extrabold'>출퇴근 수정 요청</h3><button type='button' data-attendance-request-close class='px-4 py-2 rounded-xl bg-gray-100 font-bold'>닫기</button></div>
<form method='post' action='?r=attendance/request_save' class='grid grid-cols-1 md:grid-cols-2 gap-3 mb-6' data-attendance-request-form>
    <input type='hidden' name='_csrf' value='<?php echo h(csrf_token());?>'>
    <div>
        <div class='text-sm text-gray-600 mb-1'>요청 날짜</div>
        <input class='w-full px-3 py-2 rounded-xl border' type='date' name='request_date' value='<?php echo h($today_att);?>' required>
    </div>
    <div>
        <div class='text-sm text-gray-600 mb-1'>요청 종류</div>
        <select class='w-full px-3 py-2 rounded-xl border' name='request_type'>
            <option value='check_in'>출근시간 수정</option>
            <option value='check_out'>퇴근시간 수정</option>
            <option value='both'>출근+퇴근 수정</option>
        </select>
    </div>
    <div>
        <div class='text-sm text-gray-600 mb-1'>요청 출근시간</div>
        <input class='w-full px-3 py-2 rounded-xl border' type='datetime-local' name='requested_check_in'>
    </div>
    <div>
        <div class='text-sm text-gray-600 mb-1'>요청 퇴근시간</div>
        <input class='w-full px-3 py-2 rounded-xl border' type='datetime-local' name='requested_check_out'>
    </div>
    <div class='md:col-span-2 text-xs text-blue-700 bg-blue-50 border border-blue-100 rounded-xl px-3 py-2' data-attendance-request-help>출근시간 수정은 승인 시 출근중 상태로 반영되고, 퇴근시간은 입력할 수 없습니다.</div>
    <div class='md:col-span-2'>
        <div class='text-sm text-gray-600 mb-1'>요청 사유</div>
        <input class='w-full px-3 py-2 rounded-xl border' type='text' name='reason' placeholder='요청 사유'>
    </div>
    <div class='md:col-span-2'>
        <button type='submit' class='px-5 py-3 rounded-2xl bg-blue-600 text-white font-extrabold' data-attendance-request-submit>요청</button>
    </div>
</form>
<div class='flex flex-wrap items-center justify-between gap-3 mb-2'>
    <h4 class='text-xl font-extrabold'>내 요청 목록</h4>
    <form method='get' action='' class='flex flex-wrap items-center gap-2'>
        <input type='hidden' name='r' value='대시보드'>
        <input type='month' name='attendance_request_month' value='<?php echo h($attendanceRequestMonth); ?>' class='px-3 py-2 rounded-xl border border-gray-200'>
        <button type='submit' class='px-4 py-2 rounded-xl bg-gray-900 text-white font-bold'>월별 조회</button>
    </form>
</div>
<div class='overflow-x-auto border rounded-2xl'>
    <table class='min-w-full text-sm'>
        <tr class='bg-gray-50'><th class='p-2 text-left'>요청 날짜</th><th class='p-2 text-left'>요청 종류</th><th class='p-2 text-left'>요청 시간</th><th class='p-2 text-left'>상태</th><th class='p-2 text-left'>반려사유</th><th class='p-2 text-left'>요청일</th></tr>
        <?php if (count($myReqs) === 0): ?>
            <tr><td colspan='6' class='p-4 text-center text-gray-500'>선택 월에 표시할 요청이 없습니다.</td></tr>
        <?php else: ?>
            <?php foreach($myReqs as $rq): ?>
                <tr class='border-t'><td class='p-2'><?php echo h($rq['request_date']);?></td><td class='p-2'><?php echo h(attendance_request_type_label(isset($rq['request_type']) ? $rq['request_type'] : ''));?></td><td class='p-2'><?php echo h(trim((isset($rq['requested_check_in']) ? $rq['requested_check_in'] : '') . ' / ' . (isset($rq['requested_check_out']) ? $rq['requested_check_out'] : ''), ' /'));?></td><td class='p-2'><?php echo h(attendance_request_status_label(isset($rq['status']) ? $rq['status'] : ''));?></td><td class='p-2'><?php echo h(isset($rq['reject_reason']) ? $rq['reject_reason'] : '');?></td><td class='p-2'><?php echo h(isset($rq['created_at']) ? $rq['created_at'] : '');?></td></tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </table>
</div></div></div>
<script>
(function(){
    try{
        var issueModal=document.getElementById('attendanceIssueModal');
        var m=document.getElementById('attendanceRequestModal');
        var todayValue=<?php echo json_encode($today_att); ?>;
        var cs=m?m.querySelectorAll('[data-attendance-request-close]'):[];
        var form=m?m.querySelector('[data-attendance-request-form]'):null;
        var fDate=m?m.querySelector('input[name="request_date"]'):null;
        var fType=m?m.querySelector('select[name="request_type"]'):null;
        var fCi=m?m.querySelector('input[name="requested_check_in"]'):null;
        var fCo=m?m.querySelector('input[name="requested_check_out"]'):null;
        var help=m?m.querySelector('[data-attendance-request-help]'):null;
        var submitBtn=m?m.querySelector('[data-attendance-request-submit]'):null;
        var submitting=false;
        function syncDate(v){if(!fDate||!v)return;var d=(v+'').substr(0,10);if(d.length===10)fDate.value=d;}
        function setDisabled(input, disabled){if(!input)return;input.disabled=disabled;if(disabled)input.value='';}
        function syncType(){
            var type=fType?fType.value:'check_in';
            if(type==='check_in'){
                setDisabled(fCi,false);setDisabled(fCo,true);
                if(fCi)fCi.required=true;if(fCo)fCo.required=false;
                if(help)help.textContent='출근시간 수정은 승인 시 출근중 상태로 반영되고, 퇴근시간은 입력할 수 없습니다.';
            }else if(type==='check_out'){
                setDisabled(fCi,true);setDisabled(fCo,false);
                if(fCi)fCi.required=false;if(fCo)fCo.required=true;
                if(help)help.textContent='퇴근시간 수정은 출근시간을 입력할 수 없고, 승인 시 퇴근완료 상태로 반영됩니다.';
            }else{
                setDisabled(fCi,false);setDisabled(fCo,false);
                if(fCi)fCi.required=true;if(fCo)fCo.required=true;
                if(help)help.textContent='출근+퇴근 수정은 출근시간과 퇴근시간을 모두 선택할 수 있습니다.';
            }
        }
        function bodyUnlockIfIdle(){
            var requestOpen=(m && !m.classList.contains('hidden'));
            var issueOpen=(issueModal && !issueModal.classList.contains('hidden'));
            if(!requestOpen && !issueOpen)document.body.classList.remove('overflow-hidden');
        }
        function openIssue(){
            if(!issueModal)return;
            issueModal.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
        }
        function closeIssue(){
            if(!issueModal)return;
            issueModal.classList.add('hidden');
            bodyUnlockIfIdle();
        }
        function openRequest(trigger,e){
            if(e && e.stopPropagation)e.stopPropagation();
            if(!m)return;
            if(issueModal)issueModal.classList.add('hidden');
            var reqDate=trigger?trigger.getAttribute('data-attendance-request-date'):'';
            var reqType=trigger?trigger.getAttribute('data-attendance-request-type'):'';
            if(fDate)fDate.value=reqDate?reqDate:todayValue;
            if(fType)fType.value=reqType?reqType:'check_in';
            if(fCi)fCi.value='';
            if(fCo)fCo.value='';
            syncType();
            m.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
            setTimeout(function(){
                var target=null;
                var type=fType?fType.value:'check_in';
                if(type==='check_out')target=fCo;
                else target=fCi;
                if(target && !target.disabled)target.focus();
            },0);
        }
        function closeRequest(){
            if(!m)return;
            m.classList.add('hidden');
            bodyUnlockIfIdle();
        }
        var issueOpeners=document.querySelectorAll('[data-attendance-issue-open]');
        for(var oi=0;oi<issueOpeners.length;oi++){
            issueOpeners[oi].addEventListener('click',function(e){openIssue();});
            issueOpeners[oi].addEventListener('keydown',function(e){
                var key=e.key||e.keyCode;
                if(key==='Enter'||key===' '||key===13||key===32){e.preventDefault();openIssue();}
            });
        }
        if(issueModal){
            var issueClose=issueModal.querySelectorAll('[data-attendance-issue-close]');
            for(var ic=0;ic<issueClose.length;ic++){issueClose[ic].addEventListener('click',closeIssue);}
        }
        var requestOpeners=document.querySelectorAll('[data-attendance-request-open]');
        for(var ro=0;ro<requestOpeners.length;ro++){
            requestOpeners[ro].addEventListener('click',function(e){openRequest(this,e);});
        }
        for(var i=0;i<cs.length;i++){cs[i].addEventListener('click',closeRequest);}
        if(fType)fType.addEventListener('change',syncType);
        if(fCi)fCi.addEventListener('change',function(){syncDate(this.value);});
        if(fCo)fCo.addEventListener('change',function(){syncDate(this.value);});
        if(form){
            form.addEventListener('submit',function(e){
                if(submitting){e.preventDefault();return false;}
                submitting=true;
                if(submitBtn){submitBtn.disabled=true;submitBtn.textContent='요청 중...';}
            });
        }
        syncType();
        if(window.location.search.indexOf('attendance_issue_month=')!==-1)openIssue();
        if(window.location.search.indexOf('attendance_request_month=')!==-1)openRequest(null,null);
        document.addEventListener('keydown',function(e){
            if(e.key==='Escape'||e.keyCode===27){
                if(m && !m.classList.contains('hidden'))closeRequest();
                else closeIssue();
            }
        });
    }catch(e){}
})();
</script>

<?php if ($flash): ?>
    <div class="mb-4 p-4 rounded-2xl border <?php echo ($flash['type']==='success')?'bg-emerald-50 border-emerald-200 text-emerald-700':'bg-red-50 border-red-200 text-red-700'; ?>">
        <?php echo h($flash['message']); ?>
    </div>
<?php endif; ?>
</div>

<?php cpms_render_employee_task_dashboard($pdo); ?>

<div class="cpms-dashboard-desktop-extra cpms-mobile-hide">


<?php if (false): ?>
<div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-8">
    <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100">
        <h3 class="text-xl font-extrabold text-gray-900">요청사항(받은 요청)</h3>
        <div class="text-sm text-gray-600 mt-1">내가 처리해야 하는 요청입니다.</div>
        <div class="mt-4 space-y-3">
            <?php if (count($myReceivedRequests) === 0): ?>
                <div class="text-sm text-gray-500">받은 요청이 없습니다.</div>
            <?php else: ?>
                <?php foreach ($myReceivedRequests as $rq): ?>
                    <?php $pl = isset($rq['payload']) && is_array($rq['payload']) ? $rq['payload'] : array(); ?>
                    <div class="p-4 rounded-2xl border border-gray-100">
                        <div class="text-xs text-gray-500"><?php echo h($rq['request_type']); ?> · <?php echo h($rq['created_at']); ?></div>
                        <div class="font-bold text-gray-900 mt-1"><?php echo h(isset($pl['worker_name']) ? $pl['worker_name'] : '-'); ?> / <?php echo h(isset($pl['date']) ? $pl['date'] : '-'); ?> / <?php echo h(isset($pl['old_value']) ? $pl['old_value'] : '-'); ?> → <?php echo h(isset($pl['requested_value']) ? $pl['requested_value'] : '-'); ?></div>
                        <div class="text-sm text-gray-600 mt-1">요청자: <?php echo h(isset($rq['requester_name']) ? $rq['requester_name'] : ''); ?> · 사유: <?php echo h($rq['reason']); ?></div>
                        <div class="text-xs mt-1 font-bold">상태: <?php echo h($rq['status']); ?></div>
                        <?php if ($rq['status'] === 'PENDING'): ?>
                        <div class="flex gap-2 mt-3">
                            <form method="post" action="<?php echo h(base_url()); ?>/?r=request/decide">
                                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                <input type="hidden" name="request_id" value="<?php echo h($rq['request_id']); ?>">
                                <input type="hidden" name="decision" value="APPROVED">
                                <button class="px-3 py-1 rounded-xl bg-emerald-600 text-white text-xs font-bold" type="submit">승인</button>
                            </form>
                            <form method="post" action="<?php echo h(base_url()); ?>/?r=request/decide" onsubmit="var r=prompt('반려 사유를 입력하세요'); if(!r){return false;} this.reject_reason.value=r;">
                                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                <input type="hidden" name="request_id" value="<?php echo h($rq['request_id']); ?>">
                                <input type="hidden" name="decision" value="REJECTED">
                                <input type="hidden" name="reject_reason" value="">
                                <button class="px-3 py-1 rounded-xl bg-rose-600 text-white text-xs font-bold" type="submit">반려</button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100">
        <h3 class="text-xl font-extrabold text-gray-900">나의 요청사항(보낸 요청)</h3>
        <div class="text-sm text-gray-600 mt-1">내가 요청한 건의 상태를 확인합니다.</div>
        <div class="mt-4 space-y-3">
            <?php if (count($mySentRequests) === 0): ?>
                <div class="text-sm text-gray-500">보낸 요청이 없습니다.</div>
            <?php else: ?>
                <?php foreach ($mySentRequests as $rq): ?>
                    <?php $pl = isset($rq['payload']) && is_array($rq['payload']) ? $rq['payload'] : array(); ?>
                    <div class="p-4 rounded-2xl border border-gray-100">
                        <div class="text-xs text-gray-500"><?php echo h($rq['request_type']); ?> · <?php echo h($rq['created_at']); ?></div>
                        <div class="font-bold text-gray-900 mt-1"><?php echo h(isset($pl['worker_name']) ? $pl['worker_name'] : '-'); ?> / <?php echo h(isset($pl['date']) ? $pl['date'] : '-'); ?> / <?php echo h(isset($pl['old_value']) ? $pl['old_value'] : '-'); ?> → <?php echo h(isset($pl['requested_value']) ? $pl['requested_value'] : '-'); ?></div>
                        <div class="text-sm text-gray-600 mt-1">대상자: <?php echo h(isset($requestTargetNameMap[(int)$rq['target_user_id']]) ? $requestTargetNameMap[(int)$rq['target_user_id']] : $rq['target_user_id']); ?> · 상태: <b><?php echo h($rq['status']); ?></b></div>
                        <?php if (!empty($rq['decided_at'])): ?><div class="text-xs text-gray-500">처리일: <?php echo h($rq['decided_at']); ?></div><?php endif; ?>
                        <?php if ($rq['status'] === 'REJECTED' && !empty($rq['reject_reason'])): ?><div class="text-xs text-rose-600">반려사유: <?php echo h($rq['reject_reason']); ?></div><?php endif; ?>
                        <?php if ($rq['status'] === 'REJECTED'): ?>
                            <button type="button" class="mt-2 px-3 py-1 rounded-xl bg-gray-900 text-white text-xs font-bold btn-rereq" data-target="<?php echo (int)$rq['target_user_id']; ?>" data-reason="<?php echo h($rq['reason']); ?>" data-request-id="<?php echo h($rq['request_id']); ?>" data-payload="<?php echo h(json_encode($pl)); ?>">재요청</button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
(function(){
    var csrf = <?php echo json_encode(csrf_token()); ?>;
    document.querySelectorAll('.btn-rereq').forEach(function(btn){
        btn.addEventListener('click', function(){
            var reason = prompt('요청 사유를 입력하세요', btn.getAttribute('data-reason') || '');
            if (!reason) return;
            var target = prompt('대상 임원 ID를 입력하세요', btn.getAttribute('data-target') || '');
            if (!target) return;
            var payload = btn.getAttribute('data-payload') || '{}';
            var fd = new FormData();
            fd.append('_csrf', csrf);
            fd.append('request_type', 'LABOR_MANPOWER_CHANGE');
            fd.append('target_user_id', target);
            fd.append('reason', reason);
            fd.append('re_request_of', btn.getAttribute('data-request-id') || '');
            fd.append('payload', payload);
            fetch('<?php echo h(base_url()); ?>/?r=request/create', { method:'POST', body:fd })
                .then(function(r){ return r.json(); })
                .then(function(res){ if(!res || !res.ok) throw new Error(res && res.message ? res.message : '재요청 실패'); alert('재요청이 생성되었습니다.'); location.reload(); })
                .catch(function(e){ alert(e.message || '재요청 실패'); });
        });
    });
})();
</script>

<!-- ✅ 프로젝트별 원가/공정 KPI -->
<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 mb-8">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h3 class="text-xl font-extrabold text-gray-900">프로젝트별 원가/공정 KPI</h3>
            <div class="text-sm text-gray-600 mt-1">공사 섹션 기준 내 담당(main/sub) 프로젝트만 표시 · <?php echo h($periodLabel); ?> · 원가율 높은 순</div>
        </div>
        <form method="get" class="flex items-center gap-2">
            <input type="hidden" name="r" value="대시보드">
            <input type="hidden" name="dv" value="employee">
            <select name="period" onchange="this.form.submit()" class="px-3 py-2 rounded-2xl border border-gray-200 text-sm">
                <option value="week" <?php echo ($period==='week')?'selected':''; ?>>주간</option>
                <option value="month" <?php echo ($period==='month')?'selected':''; ?>>월간</option>
            </select>
        </form>
    </div>

    <?php if (count($kpiRows) === 0): ?>
        <div class="text-sm text-gray-600">표시할 프로젝트가 없습니다.</div>
    <?php else: ?>
        <div class="overflow-x-auto rounded-2xl border border-gray-200">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-gray-600">
                <tr>
                    <th class="p-3 text-left font-extrabold">프로젝트</th>
                    <th class="p-3 text-center font-extrabold">원가율</th>
                    <th class="p-3 text-center font-extrabold">공정률</th>
                    <th class="p-3 text-center font-extrabold">노무/자재/안전 차이</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($kpiRows as $r): ?>
                    <tr class="border-t border-gray-100">
                        <td class="p-3">
                            <a class="font-bold text-indigo-600 hover:underline" href="<?php echo h(base_url()); ?>/?r=공사&pid=<?php echo (int)$r['project_id']; ?>&tab=status"><?php echo h($r['project_name']); ?></a>
                        </td>
                        <td class="p-3 text-center"><?php echo h($r['cost_rate_label']); ?><?php if ($r['cost_rate_note'] !== ''): ?> (<?php echo h($r['cost_rate_note']); ?>)<?php endif; ?></td>
                        <td class="p-3 text-center"><?php echo number_format((float)$r['progress_rate'], 2); ?>%</td>
                        <td class="p-3 text-center"><?php echo number_format((float)$r['variance_labor']); ?> / <?php echo number_format((float)$r['variance_material']); ?> / <?php echo number_format((float)$r['variance_safety']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- 기존 샘플 카드 -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
    <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 hover:shadow-xl transition-all duration-300">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-semibold text-gray-600">오늘 할 일</p>
                <p class="text-3xl font-extrabold text-gray-900 mt-2">—</p>
            </div>
            <div class="p-4 bg-gradient-to-br from-indigo-500 to-purple-500 rounded-2xl shadow-lg shadow-indigo-500/30">
                <i data-lucide="check-circle" class="w-6 h-6 text-white"></i>
            </div>
        </div>
        <p class="text-sm text-gray-500 mt-4">샘플 카드(확장 예정)</p>
    </div>

    <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 hover:shadow-xl transition-all duration-300">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-semibold text-gray-600">이슈(내가 등록)</p>
                <p class="text-3xl font-extrabold text-gray-900 mt-2"><?php echo (int)count($myIssues); ?></p>
            </div>
            <div class="p-4 bg-gradient-to-br from-rose-500 to-orange-500 rounded-2xl shadow-lg shadow-rose-500/30">
                <i data-lucide="alert-circle" class="w-6 h-6 text-white"></i>
            </div>
        </div>
        <p class="text-sm text-gray-500 mt-4">최근 20건 기준 표시</p>
    </div>

    <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 hover:shadow-xl transition-all duration-300">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-semibold text-gray-600">안전사고(내 프로젝트)</p>
                <p class="text-3xl font-extrabold text-gray-900 mt-2"><?php echo (int)count($mySafety); ?></p>
            </div>
            <div class="p-4 bg-gradient-to-br from-rose-600 to-red-500 rounded-2xl shadow-lg shadow-rose-500/30">
                <i data-lucide="shield-alert" class="w-6 h-6 text-white"></i>
            </div>
        </div>
        <p class="text-sm text-gray-500 mt-4">최근 10건 기준 표시</p>
    </div>
</div>

<!-- ✅ 내 프로젝트 안전사고(요약) -->
<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 mb-6">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h3 class="text-xl font-extrabold text-gray-900">안전사고(내 프로젝트 · 최근 10)</h3>
            <div class="text-sm text-gray-600 mt-1">공사에서 등록한 안전사고가 있으면 여기서도 확인됩니다.</div>
        </div>
        <a href="<?php echo h(base_url()); ?>/?r=안전/보건" class="px-4 py-2 rounded-2xl bg-gray-100 text-gray-900 font-bold hover:bg-gray-200">안전 탭</a>
    </div>

    <?php if ($userEmail === ''): ?>
        <div class="text-sm text-rose-700 font-bold">로그인 사용자 이메일이 없어 조회할 수 없습니다.</div>
    <?php elseif (count($mySafety) === 0): ?>
        <div class="text-sm text-gray-600">내 프로젝트의 안전사고가 없습니다.</div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($mySafety as $it): ?>
                <?php
                // ✅ 오타 수정: "$<?php" → "<?php" (이 1개만 수정)
                $stt = isset($it['status']) ? (string)$it['status'] : '접수';
                $badge = ($stt === '처리완료') ? 'bg-emerald-50 text-emerald-700 border-emerald-100'
                       : (($stt === '처리중') ? 'bg-blue-50 text-blue-700 border-blue-100'
                       : 'bg-rose-50 text-rose-700 border-rose-100');
                ?>
                <div class="p-4 rounded-2xl border border-gray-100 bg-white hover:shadow-md transition">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="font-extrabold text-gray-900 truncate"><?php echo h($it['title']); ?></div>
                            <div class="text-xs text-gray-500 mt-1">
                                프로젝트: <b><?php echo h($it['project_name']); ?></b>
                                · 등록: <?php echo h($it['created_by_name']); ?>
                                · 접수시간: <?php echo h($it['created_at']); ?>
                                <?php if (!empty($it['occurred_at'])): ?> · 발생: <?php echo h($it['occurred_at']); ?><?php endif; ?>
                            </div>
                        </div>
                        <span class="text-xs font-bold px-3 py-1 rounded-full border <?php echo h($badge); ?>"><?php echo h($stt); ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- ✅ 내가 등록한 이슈 -->
<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h3 class="text-xl font-extrabold text-gray-900">내가 등록한 이슈(최근 20)</h3>
            <div class="text-sm text-gray-600 mt-1">내가 등록한 이슈만 보여줍니다.</div>
        </div>
        <a href="<?php echo h(base_url()); ?>/?r=공사" class="px-4 py-2 rounded-2xl bg-gray-900 text-white font-extrabold">공사로</a>
    </div>

    <?php if ($userEmail === ''): ?>
        <div class="text-sm text-rose-700 font-bold">로그인 사용자 이메일이 없어 조회할 수 없습니다.</div>
    <?php elseif (count($myIssues) === 0): ?>
        <div class="text-sm text-gray-600">내가 등록한 이슈가 없습니다.</div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($myIssues as $it): ?>
                <?php
                $stt = isset($it['status']) ? (string)$it['status'] : '처리중';
                $badge = ($stt === '처리완료') ? 'bg-emerald-50 text-emerald-700 border-emerald-100'
                       : 'bg-blue-50 text-blue-700 border-blue-100';
                ?>
                <div class="p-4 rounded-2xl border border-gray-100 bg-white hover:shadow-md transition">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="font-extrabold text-gray-900"><?php echo h($it['reason']); ?></div>
                            <div class="text-xs text-gray-500 mt-1">
                                프로젝트: <b><?php echo h($it['project_name']); ?></b>
                                · 등록일: <?php echo h($it['created_at']); ?>
                            </div>
                        </div>
                        <span class="text-xs font-bold px-3 py-1 rounded-full border <?php echo h($badge); ?>"><?php echo h($stt); ?></span>
                    </div>

                    <div class="mt-3">
                        <a class="text-sm font-bold text-indigo-600 hover:underline"
                           href="<?php echo h(base_url()); ?>/?r=project/detail&id=<?php echo (int)$it['project_id']; ?>">
                            프로젝트 상세로 이동
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="text-xs text-gray-500 mt-3">* 상태 변경은 임원 또는 등록자만 가능합니다(기존 정책).</div>
    <?php endif; ?>
</div>

<!-- 기존 TaskList(샘플) -->
<div class="mt-8">
    <?php render_task_list_sample(); ?>
</div>
<?php endif; ?>
<?php if (false): ?>
<div class="bg-white rounded-2xl p-4 border mb-4"><h3 class="font-bold">받은 전자결재</h3><p><a href="?r=approval_home">전자결재에서 확인</a></p><h3 class="font-bold mt-3">나의 전자결재 요청</h3><p><a href="?r=approval_home">전자결재에서 확인</a></p></div>
<?php endif; ?>
</div>
