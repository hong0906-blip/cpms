<?php
use App\Core\Auth;
use App\Core\Db;

require_once __DIR__.'/../attendance/common.php';

$canManageAttendance = (Auth::isMaster() || attendance_is_manager());
if (!$canManageAttendance) {
    echo '권한없음';
    return;
}

$pdo = Db::pdo();
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$tab = isset($_GET['atab']) ? $_GET['atab'] : 'daily';
$requestStatusFilter = isset($_GET['status']) ? (string)$_GET['status'] : 'all';
$settings = attendance_settings($pdo);
list($ws, $we) = attendance_week_range($date);
$daily = array();
$reqs = array();
$weekly = array();
$emps = array();
$attendanceErrors = array();

if (!function_exists('cpms_column_exists')) {
    function cpms_column_exists($pdo, $table, $column)
    {
        try {
            $db = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
            if ($db === '') {
                return false;
            }
            $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:tbl AND COLUMN_NAME=:col");
            $st->execute(array(':db' => $db, ':tbl' => $table, ':col' => $column));
            return ((int)$st->fetchColumn() > 0);
        } catch (\Exception $e) {
            return false;
        }
    }
}

function attendance_request_status_label($status)
{
    if ($status === 'pending') return '승인대기';
    if ($status === 'approved') return '승인완료';
    if ($status === 'rejected') return '반려';
    return (string)$status;
}

function attendance_request_status_class($status)
{
    if ($status === 'pending') return 'bg-amber-50 text-amber-700 border-amber-200';
    if ($status === 'approved') return 'bg-emerald-50 text-emerald-700 border-emerald-200';
    if ($status === 'rejected') return 'bg-rose-50 text-rose-700 border-rose-200';
    return 'bg-gray-50 text-gray-700 border-gray-200';
}

function attendance_request_type_label($type)
{
    if ($type === 'check_in') return '출근 수정';
    if ($type === 'check_out') return '퇴근 수정';
    if ($type === 'both') return '출퇴근 수정';
    return (string)$type;
}

$positionEnabled = $pdo ? cpms_column_exists($pdo, 'employees', 'position') : false;
$hireDateEnabled = $pdo ? cpms_column_exists($pdo, 'employees', 'hire_date') : false;
$reviewedByEnabled = $pdo ? cpms_column_exists($pdo, 'cpms_attendance_requests', 'reviewed_by') : false;

if ($pdo) {
    $posSel = $positionEnabled ? 'position' : "'' AS position";
    $hireSel = $hireDateEnabled ? 'hire_date' : 'NULL AS hire_date';

    try {
        $emps = $pdo->query("SELECT id,name,department,{$posSel},{$hireSel} FROM employees ORDER BY name")->fetchAll();
    } catch (\Exception $e) {
        $attendanceErrors[] = '직원 조회 오류: '.$e->getMessage();
    }

    try {
        $st = $pdo->prepare("SELECT e.name,e.department,".($positionEnabled ? 'e.position' : "'' AS position").",a.* FROM cpms_attendance_records a JOIN employees e ON e.id=a.employee_id WHERE a.work_date=:d ORDER BY e.name");
        $st->execute(array(':d' => $date));
        $daily = $st->fetchAll();
    } catch (\Exception $e) {
        $attendanceErrors[] = '일일 조회 오류: '.$e->getMessage();
    }

    try {
        $selectReviewer = $reviewedByEnabled ? ', reviewer.name AS reviewer_name' : ", '' AS reviewer_name";
        $joinReviewer = $reviewedByEnabled ? ' LEFT JOIN employees reviewer ON reviewer.id = r.reviewed_by' : '';
        $reqs = $pdo->query("SELECT r.*, e.name, e.department, ".($positionEnabled ? 'e.position' : "'' AS position")." {$selectReviewer} FROM cpms_attendance_requests r JOIN employees e ON e.id = r.employee_id {$joinReviewer} ORDER BY r.id DESC LIMIT 100")->fetchAll();
    } catch (\Exception $e) {
        $attendanceErrors[] = '요청 조회 오류: '.$e->getMessage();
    }

    try {
        $st2 = $pdo->prepare("SELECT e.id,e.name,e.department,SUM(a.work_minutes) m FROM employees e LEFT JOIN cpms_attendance_records a ON a.employee_id=e.id AND a.work_date BETWEEN :s AND :e GROUP BY e.id,e.name,e.department ORDER BY m DESC");
        $st2->execute(array(':s' => $ws, ':e' => $we));
        $weekly = $st2->fetchAll();
    } catch (\Exception $e) {
        $attendanceErrors[] = '주간 조회 오류: '.$e->getMessage();
    }
}

$totalRequests = count($reqs);
$pendingRequests = 0;
$approvedRequests = 0;
$rejectedRequests = 0;
$filteredReqs = array();
foreach ($reqs as $rq) {
    $stVal = isset($rq['status']) ? (string)$rq['status'] : '';
    if ($stVal === 'pending') $pendingRequests++;
    if ($stVal === 'approved') $approvedRequests++;
    if ($stVal === 'rejected') $rejectedRequests++;

    if ($requestStatusFilter === 'all' || $requestStatusFilter === '' || $requestStatusFilter === $stVal) {
        $filteredReqs[] = $rq;
    }
}
?>
<div class='mb-4 flex gap-2'><a class='px-3 py-2 rounded-2xl border bg-white' href='?r=관리'>관리부 메인</a><a class='px-3 py-2 rounded-2xl border bg-white' href='?r=관리&tab=employees'>직원명부</a><a class='px-3 py-2 rounded-2xl border bg-white' href='?r=db_setup_attendance'>출퇴근 DB 설정</a></div>
<?php if(!$hireDateEnabled): ?><div style="background:#fef3c7;border:1px solid #f59e0b;padding:8px;margin:8px 0;">입사일 컬럼이 없어 연차/월차 계산은 제한됩니다. 직원명부에서 컬럼을 추가하세요.</div><?php endif; ?>
<?php foreach($attendanceErrors as $e): ?><div style="background:#fee2e2;border:1px solid #ef4444;padding:8px;margin:8px 0;"><?php echo h($e); ?></div><?php endforeach; ?>
<?php if(isset($_GET['msg']) && $_GET['msg']==='reject_reason_required'): ?><div style="background:#fef3c7;border:1px solid #f59e0b;padding:8px;margin:8px 0;">반려사유를 입력해주세요.</div><?php endif; ?>
<div class='bg-white/80 rounded-3xl shadow p-5 border border-gray-100 mb-4'><h3 class='text-xl font-extrabold mb-4'>출퇴근/근태관리</h3>
<div class='mb-4 flex flex-wrap gap-2'>
<a class='px-3 py-2 rounded-xl border <?php echo $tab==='daily'?'bg-gray-900 text-white':'bg-white';?>' href='?r=관리&tab=attendance&atab=daily'>일일 현황</a>
<a class='px-3 py-2 rounded-xl border <?php echo $tab==='requests'?'bg-gray-900 text-white':'bg-white';?>' href='?r=관리&tab=attendance&atab=requests'>요청 관리</a>
<a class='px-3 py-2 rounded-xl border <?php echo $tab==='weekly'?'bg-gray-900 text-white':'bg-white';?>' href='?r=관리&tab=attendance&atab=weekly'>주간 현황</a>
<a class='px-3 py-2 rounded-xl border <?php echo $tab==='settings'?'bg-gray-900 text-white':'bg-white';?>' href='?r=관리&tab=attendance&atab=settings'>설정</a>
</div>

<?php if($tab==='daily'): ?>
<table class='w-full text-sm border border-gray-200'>
<tr class='bg-gray-50'><th class='p-2 border'>직원명</th><th class='p-2 border'>부서</th><th class='p-2 border'>직책</th><th class='p-2 border'>출근시간</th><th class='p-2 border'>퇴근시간</th><th class='p-2 border'>상태</th><th class='p-2 border'>근무시간</th></tr>
<?php foreach($daily as $r): ?>
<tr>
<td class='p-2 border'><?php echo h(isset($r['name'])?$r['name']:'');?></td>
<td class='p-2 border'><?php echo h(isset($r['department'])?$r['department']:'');?></td>
<td class='p-2 border'><?php echo h(isset($r['position'])?$r['position']:'');?></td>
<td class='p-2 border'><?php echo h(isset($r['check_in'])?$r['check_in']:'-');?></td>
<td class='p-2 border'><?php echo h(isset($r['check_out'])?$r['check_out']:'-');?></td>
<td class='p-2 border'><?php echo h(isset($r['status'])?$r['status']:'-');?></td>
<td class='p-2 border'><?php echo isset($r['work_minutes'])?number_format(((float)$r['work_minutes'])/60,2).'h':'-';?></td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<?php if($tab==='requests'): ?>
<div class='grid grid-cols-2 md:grid-cols-4 gap-3 mb-4 text-sm'>
<div class='rounded-xl border p-3 bg-gray-50'><div class='text-gray-500'>전체 요청</div><div class='text-xl font-bold'><?php echo (int)$totalRequests;?>건</div></div>
<div class='rounded-xl border p-3 bg-amber-50'><div class='text-amber-700'>승인대기</div><div class='text-xl font-bold'><?php echo (int)$pendingRequests;?>건</div></div>
<div class='rounded-xl border p-3 bg-emerald-50'><div class='text-emerald-700'>승인완료</div><div class='text-xl font-bold'><?php echo (int)$approvedRequests;?>건</div></div>
<div class='rounded-xl border p-3 bg-rose-50'><div class='text-rose-700'>반려</div><div class='text-xl font-bold'><?php echo (int)$rejectedRequests;?>건</div></div>
</div>
<div class='mb-4 flex gap-2 text-sm'>
<a class='px-3 py-1 rounded-lg border <?php echo $requestStatusFilter==='all'?'bg-gray-900 text-white':'bg-white';?>' href='?r=관리&tab=attendance&atab=requests&status=all'>전체</a>
<a class='px-3 py-1 rounded-lg border <?php echo $requestStatusFilter==='pending'?'bg-gray-900 text-white':'bg-white';?>' href='?r=관리&tab=attendance&atab=requests&status=pending'>승인대기</a>
<a class='px-3 py-1 rounded-lg border <?php echo $requestStatusFilter==='approved'?'bg-gray-900 text-white':'bg-white';?>' href='?r=관리&tab=attendance&atab=requests&status=approved'>승인완료</a>
<a class='px-3 py-1 rounded-lg border <?php echo $requestStatusFilter==='rejected'?'bg-gray-900 text-white':'bg-white';?>' href='?r=관리&tab=attendance&atab=requests&status=rejected'>반려</a>
</div>

<?php foreach($filteredReqs as $r): $st=isset($r['status'])?$r['status']:''; ?>
<div class='rounded-2xl border border-gray-200 p-4 mb-3 bg-white shadow-sm'>
<div class='flex items-center justify-between mb-2'>
<div class='font-bold'>출퇴근 수정 요청 #<?php echo isset($r['id'])?(int)$r['id']:0;?></div>
<span class='px-2 py-1 text-xs rounded-full border <?php echo attendance_request_status_class($st);?>'><?php echo h(attendance_request_status_label($st));?></span>
</div>
<div class='grid md:grid-cols-2 gap-2 text-sm'>
<div><b>직원명</b> : <?php echo h(isset($r['name'])?$r['name']:'-');?> / <?php echo h(isset($r['department'])?$r['department']:'-');?> / <?php echo h(isset($r['position'])?$r['position']:'-');?></div>
<div><b>요청일시</b> : <?php echo h(isset($r['created_at'])?$r['created_at']:'-');?></div>
<div><b>요청일자</b> : <?php echo h(isset($r['request_date'])?$r['request_date']:'-');?></div>
<div><b>요청구분</b> : <?php echo h(attendance_request_type_label(isset($r['request_type'])?$r['request_type']:''));?></div>
<div><b>요청 출근시간</b> : <?php echo h(isset($r['requested_check_in'])?$r['requested_check_in']:'-');?></div>
<div><b>요청 퇴근시간</b> : <?php echo h(isset($r['requested_check_out'])?$r['requested_check_out']:'-');?></div>
<div class='md:col-span-2'><b>요청사유</b> : <?php echo h(isset($r['reason'])?$r['reason']:'-');?></div>
<div><b>처리자</b> : <?php echo h(isset($r['reviewer_name'])&&$r['reviewer_name']!==''?$r['reviewer_name']:(isset($r['reviewed_by'])?$r['reviewed_by']:'-'));?></div>
<div><b>처리일시</b> : <?php echo h(isset($r['reviewed_at'])?$r['reviewed_at']:'-');?></div>
<div class='md:col-span-2'><b>반려사유</b> : <?php echo h(isset($r['reject_reason'])&&$r['reject_reason']!==''?$r['reject_reason']:'-');?></div>
</div>
<?php if($canManageAttendance && $st==='pending'): ?>
<div class='mt-3 flex flex-wrap items-center gap-2'>
<form method='post' action='?r=management/attendance_request_approve' style='display:inline-block;'>
<input type='hidden' name='_csrf' value='<?php echo h(csrf_token()); ?>'>
<input type='hidden' name='id' value='<?php echo isset($r['id'])?(int)$r['id']:0; ?>'>
<button type='submit' class='px-3 py-1 rounded-lg bg-emerald-600 text-white'>승인</button>
</form>
<form method='post' action='?r=management/attendance_request_reject' style='display:inline-flex;gap:6px;align-items:center;'>
<input type='hidden' name='_csrf' value='<?php echo h(csrf_token()); ?>'>
<input type='hidden' name='id' value='<?php echo isset($r['id'])?(int)$r['id']:0; ?>'>
<input type='text' name='reject_reason' required placeholder='반려사유' class='px-2 py-1 rounded-lg border'>
<button type='submit' class='px-3 py-1 rounded-lg bg-rose-600 text-white'>반려</button>
</form>
</div>
<?php endif; ?>
</div>
<?php endforeach; ?>

<?php if(count($filteredReqs)===0): ?><div class='text-sm text-gray-500'>조건에 맞는 요청이 없습니다.</div><?php endif; ?>
<?php endif; ?>

<?php if($tab==='weekly'): foreach($weekly as $r): ?><div><?php echo h($r['name']);?> <?php echo number_format(((float)$r['m'])/60,2);?>h</div><?php endforeach; endif; ?>
<?php if($tab==='settings'): ?><form method='post' action='?r=management/attendance_settings_save'><input type='hidden' name='_csrf' value='<?php echo h(csrf_token());?>'><input name='standard_weekly_hours' value='<?php echo h($settings['standard_weekly_hours']);?>'><button>저장</button></form><?php endif; ?></div>

<div class='bg-white/80 rounded-3xl shadow p-5 border border-gray-100 mb-4'>
<h3 class='text-xl font-extrabold'>휴가 등록(관리부)</h3>
<form method='post' action='?r=management/leave_save' class='space-y-2'>
<input type='hidden' name='_csrf' value='<?php echo h(csrf_token());?>'>
<select name='employee_id' required><option value=''>직원 선택</option><?php foreach($emps as $e): ?><option value='<?php echo (int)$e['id'];?>'><?php echo h($e['name']);?></option><?php endforeach; ?></select>
<input type='date' name='leave_date' value='<?php echo h($date);?>' required>
<select name='leave_type' required>
<option value='월차'>월차</option><option value='연차'>연차</option>
<option value='월차반차'>월차반차</option><option value='연차반차'>연차반차</option>
<option value='대체휴무'>대체휴무</option><option value='기타휴무'>기타휴무</option>
</select>
<input type='number' step='0.5' min='0' name='leave_amount' placeholder='휴가일수(비우면 자동)'>
<input type='text' name='reason' placeholder='사유'>
<button class='px-3 py-2 rounded-xl bg-blue-600 text-white'>저장</button>
</form>
<div class='text-xs text-gray-500 mt-2'>관리부 반차 차감 기준: 반차는 월차반차/연차반차를 선택하여 0.5일 차감으로 저장됩니다.</div>
</div>