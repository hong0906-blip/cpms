<?php
/**
 * C:\www\cpms\app\views\dashboard\executive.php
 * - 기존 샘플 카드 유지 + ✅ 이슈 목록/상태처리 추가
 */

require_once __DIR__ . '/../partials/TaskList.php';
require_once __DIR__ . '/../partials/cost_metrics.php';
require_once __DIR__ . '/partials/project_cost_summary_helper.php';
require_once __DIR__ . '/../construction/partials/equipment_gongsu_approval_helper.php';
require_once __DIR__ . '/../tasks/dashboard_sections.php';

use App\Core\Db;

$user = \App\Core\Auth::user();
$pdo = Db::pdo();
$userEmail = (string)\App\Core\Auth::userEmail();
$projectCostSummary = cpms_dashboard_project_cost_summary($pdo);
if (!isset($projectCostSummary['project_count'])) $projectCostSummary['project_count'] = 0;
if (!isset($projectCostSummary['projects']) || !is_array($projectCostSummary['projects'])) $projectCostSummary['projects'] = array();

// 임원 대시보드 이슈 목록(최근 20)
$issues = array();
if ($pdo) {
    try {
        $sql = "SELECT i.*, p.name AS project_name
                FROM cpms_project_issues i
                LEFT JOIN cpms_projects p ON p.id = i.project_id
                ORDER BY i.id DESC
                LIMIT 20";
        $st = $pdo->query($sql);
        $issues = $st->fetchAll();
    } catch (Exception $e) {
        $issues = array();
    }
}


// 공사/임원 이슈 댓글 통합 조회(cpms_project_issue_comments)
$issueCommentsByIssueId = array();
if ($pdo && count($issues) > 0) {
    try {
        $issueIds = array();
        foreach ($issues as $issueRow) { $issueIds[count($issueIds)] = (int)$issueRow['id']; }
        $issueIds = array_values(array_unique($issueIds));
        if (count($issueIds) > 0) {
            $placeholders = implode(',', array_fill(0, count($issueIds), '?'));
            $sqlComments = "SELECT issue_id, comment_text AS comment_body, created_by_email, created_by_name, created_at FROM cpms_project_issue_comments WHERE issue_id IN (".$placeholders.") ORDER BY id ASC";
            $stc = $pdo->prepare($sqlComments);
            foreach ($issueIds as $idx => $iid) {
                $stc->bindValue($idx + 1, (int)$iid, PDO::PARAM_INT);
            }
            $stc->execute();
            $commentRows = $stc->fetchAll();
            foreach ($commentRows as $cr) {
                $key = (int)$cr['issue_id'];
                if (!isset($issueCommentsByIssueId[$key])) $issueCommentsByIssueId[$key] = array();
                $issueCommentsByIssueId[$key][count($issueCommentsByIssueId[$key])] = $cr;
            }
        }
    } catch (Exception $e) {
        $issueCommentsByIssueId = array();
    }
}

// ✅ 안전사고 목록(최근 10)
$safetyIncidents = array();
if ($pdo) {
    try {
        $sql = "SELECT i.*, p.name AS project_name
                FROM cpms_safety_incidents i
                LEFT JOIN cpms_projects p ON p.id = i.project_id
                ORDER BY i.id DESC
                LIMIT 10";
        $st = $pdo->query($sql);
        $safetyIncidents = $st->fetchAll();
    } catch (Exception $e) {
        $safetyIncidents = array();
    }
}


// ✅ 원가/공정 KPI(week/month)
$WARN_RATE = 85;
$period = isset($_GET['period']) ? trim((string)$_GET['period']) : 'week';
if ($period !== 'month') $period = 'week';
$periodLabel = ($period === 'month') ? '월간' : '주간';

$kpiRows = array();
if ($pdo) {
    try {
        $stP = $pdo->query("SELECT id, name FROM cpms_projects ORDER BY id DESC");
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
    } catch (Exception $e) {
        $kpiRows = array();
    }
}

$flash = flash_get();


// 임원 공수 승인대기 카드
$pendingGongsuOverrides = array();
$myUserId = cpms_find_employee_id_by_email($pdo, $userEmail);
if ($pdo) {
    try {
        cpms_ensure_labor_override_table($pdo);
        $sql = "SELECT o.id, o.project_id, o.month, o.worker_name, o.work_date, o.old_value, o.new_value, o.reason, o.requested_by, o.requested_by_email, o.requested_by_name, o.approval_stage, o.approval_required_level, o.current_approver_employee_id, o.current_approver_name, o.current_approver_email, o.created_at, p.name AS project_name, e.name AS requested_emp_name
                FROM cpms_labor_gongsu_overrides o
                LEFT JOIN cpms_projects p ON p.id = o.project_id
                LEFT JOIN employees e ON e.id = o.requested_by
                WHERE o.status = 'pending'
                  AND (
                        o.current_approver_employee_id = :my_employee_id
                        OR LOWER(o.current_approver_email) = LOWER(:my_email)
                      )                
                ORDER BY o.created_at DESC";
        $st = $pdo->prepare($sql);
        $st->bindValue(':my_employee_id', (int)$myUserId, PDO::PARAM_INT);
        $st->bindValue(':my_email', (string)$userEmail, PDO::PARAM_STR);
        $st->execute();
        $pendingGongsuOverrides = $st->fetchAll();
    } catch (Exception $e) {
        $pendingGongsuOverrides = array();
    }
}

$pendingEquipmentGongsuOverrides = array();
if ($pdo) {
    try {
        cpms_equipment_gongsu_ensure_schema($pdo);
        $sqlEq = "SELECT o.*, p.name AS project_name, e.vendor_name, e.spec
                FROM cpms_equipment_gongsu_overrides o
                LEFT JOIN cpms_projects p ON p.id = o.project_id
                LEFT JOIN cpms_equipment_items e ON e.id = o.equipment_id
                WHERE o.status = 'pending'
                  AND (
                        o.current_approver_employee_id = :my_employee_id
                        OR LOWER(o.current_approver_email) = LOWER(:my_email)
                      )
                ORDER BY o.created_at DESC";
        $stEq = $pdo->prepare($sqlEq);
        $stEq->bindValue(':my_employee_id', (int)$myUserId, PDO::PARAM_INT);
        $stEq->bindValue(':my_email', (string)$userEmail, PDO::PARAM_STR);
        $stEq->execute();
        $pendingEquipmentGongsuOverrides = $stEq->fetchAll();
        if (!is_array($pendingEquipmentGongsuOverrides)) $pendingEquipmentGongsuOverrides = array();
    } catch (Exception $e) {
        $pendingEquipmentGongsuOverrides = array();
    }
}

$myReceivedRequests = array();
$requestTargetNameMap = array();
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
}

?>


<!-- 대시보드 명판 최상단 -->
<div class="bg-gradient-to-r from-indigo-600 to-purple-500 rounded-3xl p-8 text-white shadow-xl shadow-indigo-500/20 mb-8">
    <div class="flex items-start gap-4">
        <div class="p-4 bg-white/20 rounded-3xl border border-white/20">
            <i data-lucide="layout-dashboard" class="w-8 h-8 text-yellow-200"></i>
        </div>
        <div class="flex-1">
            <h2 class="text-3xl font-extrabold">대시보드</h2>
            <p class="text-indigo-100 text-lg mt-2">전체 현황 및 이슈를 확인/처리합니다.</p>
        </div>
    </div>
</div>

<?php require_once __DIR__.'/../attendance/common.php'; list($ews,$ewe)=attendance_week_range(attendance_today()); $risk52=array();$absent=array();$leaveToday=0;$today=attendance_today();$leaveExTypes=array('월차','연차','반차','오전반차','오후반차','월차반차','연차반차','오전월차반차','오후월차반차','오전연차반차','오후연차반차','대체휴무','기타휴무','휴무');$leaveMainTypes=array('월차','연차','반차','오전반차','오후반차','월차반차','연차반차','오전월차반차','오후월차반차','오전연차반차','오후연차반차'); if($pdo){ try{$sql="SELECT e.id,e.name,e.department,e.position,COALESCE(SUM(a.work_minutes),0) m FROM employees e LEFT JOIN cpms_attendance_records a ON a.employee_id=e.id AND a.work_date BETWEEN :s AND :e WHERE e.is_active=1 GROUP BY e.id,e.name,e.department,e.position";$st=$pdo->prepare($sql);$st->execute(array(':s'=>$ews,':e'=>$ewe));foreach($st->fetchAll() as $r){if((int)$r['m']>3120)$risk52[count($risk52)]=$r;} // 미출근자에서 휴가자 제외
$leaveQMarks=array(); foreach($leaveExTypes as $v){$leaveQMarks[count($leaveQMarks)]='?';}
$leaveSql="SELECT DISTINCT employee_id FROM cpms_leave_records WHERE leave_date=? AND leave_type IN (".implode(',', $leaveQMarks).")";
$leaveParams=array_merge(array($today),$leaveExTypes);
$stLeaveEx=$pdo->prepare($leaveSql);$stLeaveEx->execute($leaveParams);$leaveExIds=$stLeaveEx->fetchAll(PDO::FETCH_COLUMN,0);
$leaveExMap=array(); if($leaveExIds){foreach($leaveExIds as $eid){$leaveExMap[(int)$eid]=1;}}
$stActive=$pdo->query("SELECT id,name,department,position FROM employees WHERE is_active=1");$activeRows=$stActive?$stActive->fetchAll():array();
$stAtt=$pdo->prepare("SELECT DISTINCT employee_id FROM cpms_attendance_records WHERE work_date=? AND (check_in IS NOT NULL OR status IN ('출근중','퇴근완료'))");$stAtt->execute(array($today));$attIds=$stAtt->fetchAll(PDO::FETCH_COLUMN,0);$attMap=array(); if($attIds){foreach($attIds as $eid){$attMap[(int)$eid]=1;}}
$absent=array(); foreach($activeRows as $ar){$eid=(int)$ar['id']; if(isset($attMap[$eid])) continue; if(isset($leaveExMap[$eid])) continue; $absent[count($absent)]=array('name'=>$ar['name'],'department'=>$ar['department'],'position'=>$ar['position']);}
// 월차/연차/반차자 포함
$leaveMainQMarks=array(); foreach($leaveMainTypes as $v){$leaveMainQMarks[count($leaveMainQMarks)]='?';}
$leaveMainSql="SELECT COUNT(DISTINCT employee_id) FROM cpms_leave_records WHERE leave_date=? AND leave_type IN (".implode(',', $leaveMainQMarks).")";
$leaveMainParams=array_merge(array($today),$leaveMainTypes);
$stL=$pdo->prepare($leaveMainSql);$stL->execute($leaveMainParams);$leaveToday=(int)$stL->fetchColumn(); }catch(Exception $e){} }
?>
<div class='grid grid-cols-1 xl:grid-cols-2 gap-6 mb-6'><!-- 임원 대시보드 UI 정리 + 52시간 초과자 카드 정리 -->
<div class='bg-white/80 rounded-3xl p-6 border overflow-visible'>
<h3 class='text-2xl font-extrabold mb-4'>근태 리스크 현황</h3>
<div class='grid grid-cols-1 md:grid-cols-2 gap-4'>
<div class='p-4 rounded-2xl bg-gray-50 relative group cursor-pointer overflow-visible'>
<div class='text-gray-600 text-lg font-bold'>오늘 미출근자 수</div>
<div class='text-4xl font-extrabold mt-2'><?php echo count($absent);?>명</div>
<!-- 미출근자 명단 hover -->
<div class='hidden md:block absolute left-0 top-full mt-2 w-96 max-w-[92vw] p-4 rounded-2xl bg-white border border-gray-200 shadow-2xl z-[9999] opacity-0 group-hover:opacity-100 group-hover:visible invisible transition'>
<div class='font-extrabold text-lg mb-2'>오늘 미출근자 명단</div>
<?php if(count($absent)===0): ?><div class='text-base leading-8 text-gray-700'>오늘 미출근자는 없습니다.</div><?php else: ?><ul class='space-y-2'><?php foreach($absent as $ab): ?><li class='text-base leading-8 text-gray-800'><?php echo h($ab['name']);?> / <?php echo h($ab['department']?$ab['department']:'-');?> / <?php echo h($ab['position']?$ab['position']:'-');?></li><?php endforeach; ?></ul><?php endif; ?>
</div>
<details class='md:hidden mt-3'>
<summary class='inline-block px-3 py-2 rounded-xl bg-gray-200 text-base font-bold'>명단 보기</summary>
<div class='mt-3 p-3 rounded-xl bg-white border border-gray-200'><?php if(count($absent)===0): ?><div class='text-base leading-8 text-gray-700'>오늘 미출근자는 없습니다.</div><?php else: ?><ul class='space-y-2'><?php foreach($absent as $ab): ?><li class='text-base leading-8 text-gray-800'><?php echo h($ab['name']);?> / <?php echo h($ab['department']?$ab['department']:'-');?> / <?php echo h($ab['position']?$ab['position']:'-');?></li><?php endforeach; ?></ul><?php endif; ?></div>
</details>
</div>
<div class='p-4 rounded-2xl bg-indigo-50'><div class='text-gray-600 text-lg font-bold'>오늘 월차/연차/반차자 수</div><div class='text-4xl font-extrabold text-indigo-700 mt-2'><?php echo (int)$leaveToday;?>명</div></div>
<div class='p-4 rounded-2xl bg-red-50 md:col-span-2'><div class='text-gray-600 text-lg font-bold'>52시간 초과자 수</div><div class='text-4xl font-extrabold text-red-700 mt-2'><?php echo count($risk52);?>명</div></div>
<!-- 40시간 초과자 카드 제거 -->
<!-- 승인대기 요청 카드 제거 -->
</div></div>
<div class='bg-white rounded-3xl p-6 border'>
<h3 class='text-2xl font-extrabold mb-4 text-red-700'>52시간 초과자 목록</h3>
<?php if(count($risk52)===0): ?><div class='p-4 rounded-2xl bg-emerald-50 text-emerald-700 font-bold'>이번 주 52시간 초과자는 없습니다.</div><?php else: ?><div class='space-y-3'><?php foreach($risk52 as $r): ?><div class='p-4 rounded-2xl bg-red-50 border border-red-200'><div class='font-extrabold text-lg text-red-700'><?php echo h($r['name']);?></div><div class='text-sm text-gray-700'><?php echo h(isset($r['department'])?$r['department']:'-');?> / <?php echo h(isset($r['position'])?$r['position']:'-');?></div><div class='font-extrabold text-red-700'>이번 주 인정 근무시간: <?php echo attendance_hm((int)$r['m']);?></div></div><?php endforeach; ?></div><?php endif; ?>
</div></div>

<?php if ($flash): ?>
    <div class="mb-4 p-4 rounded-2xl border <?php echo ($flash['type']==='success')?'bg-emerald-50 border-emerald-200 text-emerald-700':'bg-red-50 border-red-200 text-red-700'; ?>">
        <?php echo h($flash['message']); ?>
    </div>
<?php endif; ?>

<?php cpms_render_executive_task_dashboard($pdo); ?>

<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 mb-8">
    <div class="flex items-start justify-between gap-4 mb-4">
        <div>
            <h3 class="text-2xl font-extrabold text-gray-900">공수 수정 승인대기</h3>            
            <div class="text-sm text-gray-600 mt-1">내게 요청된 공수 수정 요청을 승인 또는 반려합니다.</div>
        </div>
        <span class="px-3 py-1 rounded-full bg-amber-50 text-amber-700 border border-amber-100 text-sm font-bold">대기 <?php echo count($pendingGongsuOverrides); ?>건</span>
    </div>
    <div class="space-y-3">
        <?php if (count($pendingGongsuOverrides) === 0): ?>
            <div class="text-sm text-gray-500">승인대기 중인 공수 수정 요청이 없습니다.</div>
        <?php else: ?>
            <?php foreach ($pendingGongsuOverrides as $ov): ?>
                <div class="p-4 rounded-2xl border bg-gray-50 border-gray-100">
                    <?php $requesterName = trim((string)$ov['requested_by_name']) !== '' ? $ov['requested_by_name'] : (trim((string)$ov['requested_emp_name']) !== '' ? $ov['requested_emp_name'] : (trim((string)$ov['requested_by_email']) !== '' ? $ov['requested_by_email'] : '-')); ?>              
                    <div class="text-xs text-gray-500">요청일: <?php echo h($ov['created_at']); ?></div>
                    <div class="font-bold text-gray-900 mt-1 text-lg">현장명: <?php echo h($ov['project_name'] ? $ov['project_name'] : '-'); ?></div>
                    <div class="text-sm text-gray-700 mt-1">요청자: <?php echo h($requesterName); ?></div>
                    <div class="text-sm text-gray-700 mt-1">작업자명: <?php echo h($ov['worker_name']); ?> / 작업일자: <?php echo h($ov['work_date']); ?></div>
                    <?php $stageLabel = (isset($ov['approval_stage']) && (string)$ov['approval_stage'] === 'VP_PENDING') ? '부사장 승인' : '상무 승인'; ?>
                    <div class="text-sm text-gray-700">요청 내용: 공수 <?php echo h($ov['old_value']); ?> -> <span class="font-extrabold text-emerald-700"><?php echo h($ov['new_value']); ?></span> 변경</div>
                    <div class="text-sm text-gray-700">요청사유: <?php echo h(trim((string)$ov['reason']) !== '' ? $ov['reason'] : '-'); ?></div>
                    <div class="text-sm text-gray-700">현재 승인 단계: <span class="font-extrabold text-amber-700"><?php echo h($stageLabel); ?></span></div>
                    <div class="flex flex-wrap gap-2 mt-3">
                        <form method="post" action="?r=construction/labor_gongsu_override_decide">
                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                            <input type="hidden" name="override_id" value="<?php echo (int)$ov['id']; ?>">
                            <input type="hidden" name="decision" value="approve">
                            <button class="px-3 py-1 rounded-xl bg-emerald-600 text-white text-xs font-bold" type="submit">승인</button>
                        </form>
                        <form method="post" action="?r=construction/labor_gongsu_override_decide" class="flex flex-wrap items-center gap-2">
                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                            <input type="hidden" name="override_id" value="<?php echo (int)$ov['id']; ?>">
                            <input type="hidden" name="decision" value="reject">
                            <input type="text" name="reject_reason" class="px-3 py-1 rounded-xl border border-gray-200 text-xs" placeholder="반려사유 입력" required>
                            <button class="px-3 py-1 rounded-xl bg-rose-600 text-white text-xs font-bold" type="submit">반려</button>
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
            <div class="text-sm text-gray-600 mt-1">내게 요청된 장비공수 수정 요청을 승인 또는 반려합니다.</div>
        </div>
        <span class="px-3 py-1 rounded-full bg-amber-50 text-amber-700 border border-amber-100 text-sm font-bold">대기 <?php echo count($pendingEquipmentGongsuOverrides); ?>건</span>
    </div>
    <div class="space-y-3">
        <?php if (count($pendingEquipmentGongsuOverrides) === 0): ?>
            <div class="text-sm text-gray-500">승인대기 중인 장비공수 수정 요청이 없습니다.</div>
        <?php else: ?>
            <?php foreach ($pendingEquipmentGongsuOverrides as $ov): ?>
                <div class="p-4 rounded-2xl border bg-gray-50 border-gray-100">
                    <?php
                    $requesterName = trim((string)$ov['requested_by_name']) !== '' ? $ov['requested_by_name'] : (trim((string)$ov['requested_by_email']) !== '' ? $ov['requested_by_email'] : '-');
                    $equipmentName = trim((string)(isset($ov['spec']) ? $ov['spec'] : ''));
                    if ($equipmentName === '') $equipmentName = trim((string)(isset($ov['vendor_name']) ? $ov['vendor_name'] : ''));
                    $stageLabel = (isset($ov['approval_stage']) && (string)$ov['approval_stage'] === 'VP_PENDING') ? '부사장 승인' : '상무 승인';
                    ?>
                    <div class="text-xs text-gray-500">요청일: <?php echo h($ov['created_at']); ?></div>
                    <div class="font-bold text-gray-900 mt-1 text-lg">현장명: <?php echo h($ov['project_name'] ? $ov['project_name'] : '-'); ?></div>
                    <div class="text-sm text-gray-700 mt-1">요청자: <?php echo h($requesterName); ?></div>
                    <div class="text-sm text-gray-700 mt-1">장비명: <?php echo h($equipmentName); ?> / 사용일자: <?php echo h($ov['use_date']); ?></div>
                    <div class="text-sm text-gray-700">요청 내용: 장비공수 <?php echo h($ov['old_value']); ?> -> <span class="font-extrabold text-emerald-700"><?php echo h($ov['new_value']); ?></span> 변경</div>
                    <div class="text-sm text-gray-700">요청사유: <?php echo h(trim((string)$ov['reason']) !== '' ? $ov['reason'] : '-'); ?></div>
                    <div class="text-sm text-gray-700">현재 승인 단계: <span class="font-extrabold text-amber-700"><?php echo h($stageLabel); ?></span></div>
                    <div class="flex flex-wrap gap-2 mt-3">
                        <form method="post" action="?r=construction/equipment_gongsu_override_decide">
                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                            <input type="hidden" name="override_id" value="<?php echo (int)$ov['id']; ?>">
                            <input type="hidden" name="decision" value="approve">
                            <button class="px-3 py-1 rounded-xl bg-emerald-600 text-white text-xs font-bold" type="submit">승인</button>
                        </form>
                        <form method="post" action="?r=construction/equipment_gongsu_override_decide" class="flex flex-wrap items-center gap-2">
                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                            <input type="hidden" name="override_id" value="<?php echo (int)$ov['id']; ?>">
                            <input type="hidden" name="decision" value="reject">
                            <input type="text" name="reject_reason" class="px-3 py-1 rounded-xl border border-gray-200 text-xs" placeholder="반려사유 입력" required>
                            <button class="px-3 py-1 rounded-xl bg-rose-600 text-white text-xs font-bold" type="submit">반려</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php if (false): ?>
<div class="grid grid-cols-1 xl:grid-cols-1 gap-6 mb-8">
    <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100">
        <h3 class="text-xl font-extrabold text-gray-900">요청사항(받은 요청)</h3>
        <div class="text-sm text-gray-600 mt-1">내가 처리해야 하는 요청입니다.</div>
        <div class="mt-4 space-y-3">
            <?php if (count($myReceivedRequests) === 0): ?>
                <div class="text-sm text-gray-500">받은 요청이 없습니다.</div>
            <?php else: ?>
                <?php foreach ($myReceivedRequests as $rq): ?>
                    <?php $pl = isset($rq['payload']) && is_array($rq['payload']) ? $rq['payload'] : array(); ?>
                    <div class="p-4 rounded-2xl border bg-gray-50 border-gray-100">
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
                            <form method="post" action="<?php echo h(base_url()); ?>/?r=request/decide" class="flex flex-wrap items-center gap-2">
                                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                <input type="hidden" name="request_id" value="<?php echo h($rq['request_id']); ?>">
                                <input type="hidden" name="decision" value="REJECTED">
                                <input type="text" name="reject_reason" class="px-3 py-1 rounded-xl border border-gray-200 text-xs" placeholder="반려 사유" required>
                                <button class="px-3 py-1 rounded-xl bg-rose-600 text-white text-xs font-bold" type="submit">반려</button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
<?php endif; ?>

<?php if (false): ?>
<!-- ✅ 프로젝트별 원가/공정 KPI -->
<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 mb-8">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h3 class="text-xl font-extrabold text-gray-900">프로젝트별 원가/공정 KPI</h3>
            <div class="text-sm text-gray-600 mt-1">공사 섹션 기준 전체 프로젝트 · <?php echo h($periodLabel); ?> · 원가율 높은 순 · 85% 초과 경고</div>
        </div>
        <form method="get" class="flex items-center gap-2">
            <input type="hidden" name="r" value="대시보드">
            <input type="hidden" name="dv" value="executive">
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
                    <th class="p-3 text-center font-extrabold">경고</th>
                    <th class="p-3 text-center font-extrabold">노무/자재/안전 차이</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($kpiRows as $r): ?>
                    <?php $warn = ($r['cost_rate'] !== null && (float)$r['cost_rate'] > $WARN_RATE); ?>
                    <tr class="border-t border-gray-100">
                        <td class="p-3"><a class="font-bold text-indigo-600 hover:underline" href="<?php echo h(base_url()); ?>/?r=공사&pid=<?php echo (int)$r['project_id']; ?>&tab=cost_progress&sub=summary&period=<?php echo h($period); ?>"><?php echo h($r['project_name']); ?></a></td>
                        <td class="p-3 text-center"><?php echo h($r['cost_rate_label']); ?><?php if ($r['cost_rate_note'] !== ''): ?> (<?php echo h($r['cost_rate_note']); ?>)<?php endif; ?></td>
                        <td class="p-3 text-center"><?php echo number_format((float)$r['progress_rate'], 2); ?>%</td>
                        <td class="p-3 text-center"><?php echo $warn ? '<span class="text-red-600 font-extrabold">빨간불</span>' : '-'; ?></td>
                        <td class="p-3 text-center"><?php echo number_format((float)$r['variance_labor']); ?> / <?php echo number_format((float)$r['variance_material']); ?> / <?php echo number_format((float)$r['variance_safety']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php
$projectCostRows = isset($projectCostSummary['projects']) && is_array($projectCostSummary['projects']) ? $projectCostSummary['projects'] : array();
$projectCostCount = isset($projectCostSummary['project_count']) ? (int)$projectCostSummary['project_count'] : 0;
?>
<!-- KPI 카드 -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
    <div class="relative group bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 hover:shadow-xl transition-all duration-300 cursor-pointer focus:outline-none focus:ring-4 focus:ring-blue-100" tabindex="0" data-project-cost-open="1">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-semibold text-gray-600">전체 프로젝트</p>
                <p class="text-3xl font-extrabold text-gray-900 mt-2"><?php echo (int)$projectCostCount; ?>건</p>
            </div>
            <div class="p-4 bg-gradient-to-br from-blue-500 to-indigo-500 rounded-2xl shadow-lg shadow-blue-500/30">
                <i data-lucide="folder" class="w-6 h-6 text-white"></i>
            </div>
        </div>
        <p class="text-sm text-gray-600 mt-4 font-semibold">현재 등록된 프로젝트 <?php echo (int)$projectCostCount; ?>건</p>
        <p class="text-xs text-gray-500 mt-1">마우스를 올리면 프로젝트별 매출 대비 사용금액을 확인할 수 있습니다.</p>
        <p class="text-xs text-blue-600 mt-1 lg:hidden">터치하면 상세보기</p>

        <div class="hidden lg:block absolute left-0 top-full z-30 mt-3 w-[440px] max-w-[calc(100vw-2rem)] rounded-3xl border border-gray-100 bg-white shadow-2xl shadow-gray-300/50 p-5 opacity-0 invisible translate-y-2 group-hover:opacity-100 group-hover:visible group-hover:translate-y-0 transition-all duration-200">
            <div class="flex items-center justify-between gap-3 mb-4">
                <div>
                    <div class="text-sm font-extrabold text-gray-900">전체 프로젝트 <?php echo (int)$projectCostCount; ?>건</div>
                    <div class="text-xs text-gray-500">프로젝트별 매출 대비 사용금액 요약</div>
                </div>
                <span class="px-3 py-1 rounded-full bg-blue-50 text-blue-700 text-xs font-bold">공무 데이터</span>
            </div>
            <?php if (count($projectCostRows) === 0): ?>
                <div class="rounded-2xl bg-gray-50 p-4 text-sm text-gray-500">표시할 프로젝트가 없습니다.</div>
            <?php else: ?>
                <div class="max-h-96 overflow-y-auto pr-1 space-y-3">
                    <?php foreach ($projectCostRows as $projectCost): ?>
                        <?php
                        $projectName = isset($projectCost['project_name']) && trim((string)$projectCost['project_name']) !== '' ? (string)$projectCost['project_name'] : '-';
                        $statusColor = isset($projectCost['status_color']) ? (string)$projectCost['status_color'] : 'blue';
                        $isOverSales = isset($projectCost['is_over_sales']) && (int)$projectCost['is_over_sales'] === 1;
                        $noSales = isset($projectCost['no_sales']) && (int)$projectCost['no_sales'] === 1;
                        $dotClass = ($statusColor === 'red') ? 'bg-red-500' : 'bg-blue-500';
                        if ($isOverSales || $noSales) $dotClass = 'bg-red-600 ring-4 ring-red-100';
                        $rateClass = ($statusColor === 'red') ? 'text-red-700' : 'text-blue-700';
                        ?>
                        <div class="rounded-2xl border border-gray-100 bg-gray-50 p-4">
                            <div class="flex items-start gap-3">
                                <span class="mt-1 h-3 w-3 rounded-full flex-none <?php echo h($dotClass); ?>"></span>
                                <div class="min-w-0 flex-1">
                                    <a href="<?php echo h(base_url()); ?>/?r=project/detail&id=<?php echo (int)$projectCost['project_id']; ?>" class="font-extrabold text-gray-900 hover:text-blue-700 hover:underline block truncate"><?php echo h($projectName); ?></a>
                                    <div class="grid grid-cols-2 gap-x-3 gap-y-1 mt-2 text-xs text-gray-600">
                                        <div>매출 <span class="font-bold text-gray-900"><?php echo h(cpms_dashboard_money($projectCost['sales'])); ?></span></div>
                                        <div>사용 <span class="font-bold text-gray-900"><?php echo h(cpms_dashboard_money($projectCost['used_total'])); ?></span></div>
                                        <div>장비 <?php echo h(cpms_dashboard_money($projectCost['equipment'])); ?></div>
                                        <div>노무 <?php echo h(cpms_dashboard_money($projectCost['labor'])); ?></div>
                                        <div>자재 <?php echo h(cpms_dashboard_money($projectCost['materials'])); ?></div>
                                        <div class="font-extrabold <?php echo h($rateClass); ?>">원가율 <?php echo h($projectCost['cost_rate_label']); ?></div>
                                    </div>
                                    <?php if ($noSales): ?>
                                        <div class="mt-2 text-xs font-bold text-red-700">매출 없음 / 사용금액 발생</div>
                                    <?php elseif ($isOverSales): ?>
                                        <div class="mt-2 text-xs font-bold text-red-700">매출 대비 사용금액 초과</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="mt-4 rounded-2xl bg-gray-50 px-4 py-3 text-xs text-gray-500">
                원가율 = (장비비 + 노무비 + 자재구입비) ÷ 매출 × 100<br>
                80% 초과 시 빨간색으로 표시됩니다.
            </div>
        </div>
    </div>

    <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 hover:shadow-xl transition-all duration-300">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-semibold text-gray-600">미처리 이슈</p>
                <p class="text-3xl font-extrabold text-gray-900 mt-2"><?php echo (int)count($issues); ?></p>
            </div>
            <div class="p-4 bg-gradient-to-br from-rose-500 to-orange-500 rounded-2xl shadow-lg shadow-rose-500/30">
                <i data-lucide="alert-triangle" class="w-6 h-6 text-white"></i>
            </div>
        </div>
        <p class="text-sm text-gray-500 mt-4">최근 20건 기준 표시</p>
    </div>

    <div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 hover:shadow-xl transition-all duration-300">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-semibold text-gray-600">안전사고</p>
                <p class="text-3xl font-extrabold text-gray-900 mt-2"><?php echo (int)count($safetyIncidents); ?></p>
            </div>
            <div class="p-4 bg-gradient-to-br from-rose-600 to-red-500 rounded-2xl shadow-lg shadow-rose-500/30">
                <i data-lucide="shield-alert" class="w-6 h-6 text-white"></i>
            </div>
        </div>
        <p class="text-sm text-gray-500 mt-4">최근 10건 기준 표시</p>
    </div>
</div>

<div id="cpmsProjectCostModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-950/50 p-4" aria-hidden="true">
    <div class="w-full max-w-3xl max-h-[90vh] overflow-hidden rounded-3xl bg-white shadow-2xl">
        <div class="flex items-start justify-between gap-4 border-b border-gray-100 px-6 py-5">
            <div>
                <div class="text-2xl font-extrabold text-gray-900">전체 프로젝트 <?php echo (int)$projectCostCount; ?>건</div>
                <div class="text-sm text-gray-500 mt-1">프로젝트별 매출 대비 사용금액과 원가율을 확인합니다.</div>
            </div>
            <button type="button" class="rounded-2xl border border-gray-200 px-4 py-2 text-sm font-bold text-gray-600 hover:bg-gray-50" data-project-cost-close>닫기</button>
        </div>
        <div class="max-h-[65vh] overflow-y-auto px-6 py-5">
            <?php if (count($projectCostRows) === 0): ?>
                <div class="rounded-2xl bg-gray-50 p-5 text-sm text-gray-500">표시할 프로젝트가 없습니다.</div>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($projectCostRows as $projectCost): ?>
                        <?php
                        $projectName = isset($projectCost['project_name']) && trim((string)$projectCost['project_name']) !== '' ? (string)$projectCost['project_name'] : '-';
                        $statusColor = isset($projectCost['status_color']) ? (string)$projectCost['status_color'] : 'blue';
                        $isOverSales = isset($projectCost['is_over_sales']) && (int)$projectCost['is_over_sales'] === 1;
                        $noSales = isset($projectCost['no_sales']) && (int)$projectCost['no_sales'] === 1;
                        $dotClass = ($statusColor === 'red') ? 'bg-red-500' : 'bg-blue-500';
                        if ($isOverSales || $noSales) $dotClass = 'bg-red-600 ring-4 ring-red-100';
                        $rateClass = ($statusColor === 'red') ? 'text-red-700' : 'text-blue-700';
                        ?>
                        <div class="rounded-3xl border border-gray-100 bg-gray-50 p-5">
                            <div class="flex items-start gap-3">
                                <span class="mt-1 h-3 w-3 rounded-full flex-none <?php echo h($dotClass); ?>"></span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <a href="<?php echo h(base_url()); ?>/?r=project/detail&id=<?php echo (int)$projectCost['project_id']; ?>" class="font-extrabold text-lg text-gray-900 hover:text-blue-700 hover:underline"><?php echo h($projectName); ?></a>
                                        <?php if ($isOverSales): ?><span class="px-3 py-1 rounded-full bg-red-50 text-red-700 text-xs font-bold">매출 초과</span><?php endif; ?>
                                        <?php if ($noSales): ?><span class="px-3 py-1 rounded-full bg-red-50 text-red-700 text-xs font-bold">매출 없음</span><?php endif; ?>
                                    </div>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2 mt-3 text-sm text-gray-600">
                                        <div class="rounded-2xl bg-white px-3 py-2">매출 <b class="text-gray-900"><?php echo h(cpms_dashboard_money($projectCost['sales'])); ?></b></div>
                                        <div class="rounded-2xl bg-white px-3 py-2">사용금액 <b class="text-gray-900"><?php echo h(cpms_dashboard_money($projectCost['used_total'])); ?></b></div>
                                        <div class="rounded-2xl bg-white px-3 py-2">원가율 <b class="<?php echo h($rateClass); ?>"><?php echo h($projectCost['cost_rate_label']); ?></b></div>
                                        <div class="rounded-2xl bg-white px-3 py-2">장비비 <?php echo h(cpms_dashboard_money($projectCost['equipment'])); ?></div>
                                        <div class="rounded-2xl bg-white px-3 py-2">노무비 <?php echo h(cpms_dashboard_money($projectCost['labor'])); ?></div>
                                        <div class="rounded-2xl bg-white px-3 py-2">자재구입비 <?php echo h(cpms_dashboard_money($projectCost['materials'])); ?></div>
                                    </div>
                                    <?php if ($noSales): ?>
                                        <div class="mt-3 text-sm font-bold text-red-700">매출 없음 / 사용금액 발생</div>
                                    <?php elseif ($isOverSales): ?>
                                        <div class="mt-3 text-sm font-bold text-red-700">매출보다 장비비 + 노무비 + 자재구입비 합계가 큽니다.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="border-t border-gray-100 bg-gray-50 px-6 py-4 text-xs text-gray-500">
            원가율 = (장비비 + 노무비 + 자재구입비) ÷ 매출 × 100 · 80% 초과 시 빨간색으로 표시됩니다.
        </div>
    </div>
</div>

<script>
(function(){
    var modal = document.getElementById('cpmsProjectCostModal');
    if (!modal) return;
    function insideLink(el) {
        while (el && el !== document) {
            if (el.tagName && el.tagName.toLowerCase() === 'a') return true;
            el = el.parentNode;
        }
        return false;
    }
    function openModal() {
        modal.classList.remove('hidden');
        modal.setAttribute('aria-hidden', 'false');
        if (document.body) document.body.classList.add('overflow-hidden');
    }
    function closeModal() {
        modal.classList.add('hidden');
        modal.setAttribute('aria-hidden', 'true');
        if (document.body) document.body.classList.remove('overflow-hidden');
    }
    var openers = document.querySelectorAll('[data-project-cost-open]');
    for (var i = 0; i < openers.length; i++) {
        openers[i].addEventListener('click', function(event){
            if (insideLink(event.target)) return;
            openModal();
        });
        openers[i].addEventListener('keydown', function(event){
            var key = event.key || event.keyCode;
            if (key === 'Enter' || key === ' ' || key === 13 || key === 32) {
                event.preventDefault();
                openModal();
            }
        });
    }
    var closers = modal.querySelectorAll('[data-project-cost-close]');
    for (var j = 0; j < closers.length; j++) {
        closers[j].addEventListener('click', closeModal);
    }
    modal.addEventListener('click', function(event){
        if (event.target === modal) closeModal();
    });
    document.addEventListener('keydown', function(event){
        var key = event.key || event.keyCode;
        if (key === 'Escape' || key === 'Esc' || key === 27) closeModal();
    });
})();
</script>

<!-- ✅ 안전사고(임원) -->
<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100 mb-6">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h3 class="text-xl font-extrabold text-gray-900">안전사고(최근 10)</h3>
            <div class="text-sm text-gray-600 mt-1">공사에서 등록한 안전사고를 확인합니다.</div>
        </div>
        <a href="<?php echo h(base_url()); ?>/?r=safety_home" class="px-4 py-2 rounded-2xl bg-gray-900 text-white font-extrabold">안전 탭으로</a>
    </div>

    <?php if (count($safetyIncidents) === 0): ?>
        <div class="text-sm text-gray-600">등록된 안전사고가 없습니다.</div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($safetyIncidents as $it): ?>
                <?php
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
                            <div class="text-xs mt-1">등급: <b class="<?php echo ((isset($it['severity']) && in_array($it['severity'], array('중대','긴급'), true)) ? 'text-red-600' : 'text-gray-700'); ?>"><?php echo h(isset($it['severity']) ? $it['severity'] : '보통'); ?></b></div>                            
                            <?php if (!empty($it['description'])): ?>
                                <div class="text-sm text-gray-700 mt-2 whitespace-pre-line"><?php echo h($it['description']); ?></div>
                            <?php endif; ?>
                            <div class="mt-2 text-xs text-gray-600">
                                <?php if (!empty($it['action_note'])): ?>
                                    <div><b>후속조치:</b> <?php echo nl2br(h($it['action_note'])); ?></div>
                                    <div class="mt-1">조치자: <?php echo h(isset($it['action_by_name']) && trim((string)$it['action_by_name']) !== '' ? $it['action_by_name'] : '-'); ?> · 조치일: <?php echo h(isset($it['action_at']) && $it['action_at'] ? $it['action_at'] : '-'); ?></div>
                                <?php else: ?>
                                    <div class="text-gray-400">후속조치 미입력</div>
                                <?php endif; ?>
                            </div>                            
                        </div>
                        <span class="text-xs font-bold px-3 py-1 rounded-full border <?php echo h($badge); ?>"><?php echo h($stt); ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="text-xs text-gray-500 mt-3">* 안전사고 상태 변경은 안전팀/임원이 안전 탭에서 처리합니다.</div>
    <?php endif; ?>
</div>

<!-- ✅ 이슈 목록 + 상태 처리 -->
<div class="bg-white/80 backdrop-blur-sm rounded-3xl shadow-lg shadow-gray-200/50 p-6 border border-gray-100">
    <div class="mb-4">
        <h3 class="text-xl font-extrabold text-gray-900">이슈(최근 20)</h3>
        <div class="text-sm text-gray-600 mt-1">공사/공무에서 등록한 이슈를 확인하고 상태를 처리합니다.</div>
    </div>

    <?php if (count($issues) === 0): ?>
        <div class="text-sm text-gray-600">등록된 이슈가 없습니다.</div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($issues as $it): ?>
                <?php
                $stt = isset($it['status']) ? (string)$it['status'] : '접수';
                $badge = ($stt === '처리완료') ? 'bg-emerald-50 text-emerald-700 border-emerald-100'
                       : (($stt === '처리중') ? 'bg-blue-50 text-blue-700 border-blue-100' : 'bg-rose-50 text-rose-700 border-rose-100');
                $issueId = (int)$it['id'];
                $issueComments = isset($issueCommentsByIssueId[$issueId]) ? $issueCommentsByIssueId[$issueId] : array();
                ?>
                <div class="p-4 rounded-2xl border border-gray-100 bg-white hover:shadow-md transition">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="font-extrabold text-gray-900"><?php echo h(isset($it['title']) && trim((string)$it['title'])!=='' ? $it['title'] : (isset($it['reason'])?$it['reason']:'-')); ?></div>
                            <div class="text-xs text-gray-500 mt-1">
                                현장명: <b><?php echo h($it['project_name']); ?></b>
                                · 등록자: <?php echo h($it['created_by_name']); ?>
                                · 등록일: <?php echo h($it['created_at']); ?>
                            </div>
                            <div class="text-xs text-gray-600 mt-1">중요도: <b><?php echo h(isset($it['priority']) ? $it['priority'] : '-'); ?></b></div>
                            <div class="text-sm text-gray-700 mt-2 whitespace-pre-line"><?php echo h(isset($it['description']) && trim((string)$it['description']) !== '' ? $it['description'] : (isset($it['content']) ? $it['content'] : '-')); ?></div>

                            <div class="mt-4 p-3 rounded-xl bg-gray-50 border border-gray-200">
                                <div class="text-sm font-bold text-gray-800 mb-2">이슈 댓글</div>
                                <?php if (count($issueComments) === 0): ?>
                                    <div class="text-sm text-gray-500">댓글이 없습니다.</div>
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
                                    <input type="hidden" name="issue_id" value="<?php echo (int)$it['id']; ?>">
                                    <input type="hidden" name="redirect" value="dashboard_executive">
                                    <textarea name="comment_text" rows="2" class="w-full px-3 py-2 rounded-2xl border border-gray-200 text-sm" placeholder="댓글을 입력하세요"></textarea>
                                    <button type="submit" class="mt-2 px-3 py-2 rounded-2xl bg-gray-900 text-white font-extrabold text-sm">댓글 등록</button>
                                </form>
                            </div>                          
                        </div>

                        <div class="flex flex-col items-end gap-2">
                            <!-- 이슈 상태 AJAX 제거 / 댓글 기능 유지 -->
                            <div class="text-[11px] text-gray-500">ISSUE_STATUS_ROUTE = construction/issue_state_save</div>
                            <div class="text-[11px] text-gray-500">ISSUE_STATUS_METHOD = POST</div>
                            <form method="post" action="?r=construction/issue_state_save" class="flex items-center gap-2">
                                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                <input type="hidden" name="issue_id" value="<?php echo (int)$issueId; ?>">
                                <input type="hidden" name="redirect" value="dashboard_executive">
                                <span class="text-xs font-bold px-3 py-1 rounded-full border <?php echo h($badge); ?>"><?php echo h($stt); ?></span>
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

<!-- 기존 TaskList(샘플) -->
<div class="mt-8">
    <?php render_task_list_sample(); ?>
</div>
<?php /** 52시간 초과자: 임원 근태 리스크 현황 */ require_once __DIR__.'/../attendance/common.php'; $pdo2=\App\Core\Db::pdo(); $today=attendance_today(); list($ws,$we)=attendance_week_range($today); $over52=array();$over40=array();$pendingReq=0; if($pdo2){$set=attendance_settings($pdo2);$st=$pdo2->prepare("SELECT e.name,SUM(a.work_minutes) m FROM employees e LEFT JOIN cpms_attendance_records a ON a.employee_id=e.id AND a.work_date BETWEEN :s AND :e GROUP BY e.id,e.name");$st->execute(array(':s'=>$ws,':e'=>$we));foreach($st->fetchAll() as $r){$h=$r['m']/60;if($h>(float)$set['max_weekly_hours'])$over52[count($over52)]=$r['name'].'('.number_format($h,2).'h)';elseif($h>(float)$set['standard_weekly_hours'])$over40[count($over40)]=$r['name'].'('.number_format($h,2).'h)';} $pendingReq=(int)$pdo2->query("SELECT COUNT(*) FROM cpms_attendance_requests WHERE status='pending'")->fetchColumn(); } ?>
<div><h3>근태 리스크 현황</h3><p style='color:red'>이번 주 52시간 초과자: <?php echo h(implode(', ',$over52));?></p><p>이번 주 40시간 초과자: <?php echo h(implode(', ',$over40));?></p><p>출퇴근 요청 승인대기 건수: <?php echo (int)$pendingReq;?></p></div>

<div class="bg-white rounded-2xl p-4 border mb-4"><h3 class="font-bold">전자결재 승인대기</h3><p><a href="?r=approval_home">전자결재에서 확인</a></p></div>
