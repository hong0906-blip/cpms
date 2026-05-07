<?php
/** 출퇴근 시스템 관리 화면 */
use App\Core\Auth;
use App\Core\Db;

require_once __DIR__.'/../attendance/common.php';

$canManageAttendance = (Auth::isMaster() || attendance_is_manager());
if (!$canManageAttendance) {
    echo '권한없음';
    return;
}

$canShowDbButton = (Auth::isMaster() || Auth::canManageEmployees());

$pdo = Db::pdo();
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$tab = isset($_GET['atab']) ? $_GET['atab'] : 'daily';
$settings = attendance_settings($pdo);
list($ws, $we) = attendance_week_range($date);

$daily = array();
$reqs = array();
$weekly = array();
$emps = array();

if ($pdo) {
    $emps = $pdo->query("SELECT id,name,department,position,hire_date FROM employees ORDER BY name")->fetchAll();
    $st = $pdo->prepare("SELECT e.name,e.department,e.position,a.* FROM cpms_attendance_records a JOIN employees e ON e.id=a.employee_id WHERE a.work_date=:d ORDER BY e.name");
    $st->execute(array(':d' => $date));
    $daily = $st->fetchAll();
    $reqs = $pdo->query("SELECT r.*,e.name FROM cpms_attendance_requests r JOIN employees e ON e.id=r.employee_id ORDER BY r.id DESC LIMIT 100")->fetchAll();
    $st2 = $pdo->prepare("SELECT e.id,e.name,e.department,SUM(a.work_minutes) m FROM employees e LEFT JOIN cpms_attendance_records a ON a.employee_id=e.id AND a.work_date BETWEEN :s AND :e GROUP BY e.id,e.name,e.department ORDER BY m DESC");
    $st2->execute(array(':s' => $ws, ':e' => $we));
    $weekly = $st2->fetchAll();
}
?>
<!-- 출퇴근 실제 렌더링 확인 -->
<div style="background:#fef3c7;border:2px solid #dc2626;color:#7f1d1d;padding:12px 14px;border-radius:10px;margin:0 0 12px 0;font-size:13px;line-height:1.6;">
  <div style="font-weight:800;margin-bottom:6px;">ADMIN_ATTENDANCE_LOADED = 2026-근태탭-강제진단-01</div>
  <div style="font-weight:700;">ADMIN_ATTENDANCE_VERSION = 2026-근태탭-강제진단-01</div>
  <!-- OPcache/서버 캐시 확인 문구 -->
  <div style="margin:6px 0 10px 0;font-weight:700;">이 문구가 화면에 안 보이면 PHP가 최신 파일을 실행하지 않는 것입니다. OPcache/서버 캐시/다른 경로 실행을 확인하세요.</div>
  <div>__FILE__: <?php echo h(__FILE__); ?></div>
  <div>$_GET['r']: <?php echo h(isset($_GET['r']) ? $_GET['r'] : ''); ?></div>
  <div>$_GET['tab']: <?php echo h(isset($_GET['tab']) ? $_GET['tab'] : ''); ?></div>
  <div>$_GET['atab']: <?php echo h(isset($_GET['atab']) ? $_GET['atab'] : ''); ?></div>
  <div>Auth::isMaster(): <?php echo Auth::isMaster() ? 'true' : 'false'; ?></div>
  <div>Auth::canManageEmployees(): <?php echo Auth::canManageEmployees() ? 'true' : 'false'; ?></div>
  <div>DB 버튼 표시 조건(Auth::isMaster() || Auth::canManageEmployees()): <?php echo $canShowDbButton ? 'true' : 'false'; ?></div>
</div>

<div style='display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;'>
  <h3 style='margin:0;'>출퇴근/근태관리</h3>
  <?php if ($canShowDbButton): ?>
    <a href='?r=db_setup_attendance' style='display:inline-block;padding:8px 12px;background:#1d4ed8;color:#fff;border-radius:8px;font-weight:700;'>출퇴근 DB 설정</a>
  <?php endif; ?>
</div>
<a href='?r=관리&tab=attendance&atab=daily'>일일 출퇴근 현황</a> | <a href='?r=관리&tab=attendance&atab=requests'>출퇴근 요청 관리</a> | <a href='?r=관리&tab=attendance&atab=weekly'>주간 근무시간/52시간 초과자</a> | <a href='?r=관리&tab=attendance&atab=leave'>연차/월차/반차 관리</a> | <a href='?r=관리&tab=attendance&atab=settings'>근태 설정</a>
<?php if($tab==='daily'): ?><table><tr><th>직원명</th><th>부서</th><th>직책</th><th>상태</th><th>출근</th><th>퇴근</th><th>실제 체류시간</th><th>인정 근무시간</th></tr><?php foreach($daily as $r): ?><tr><td><?php echo h($r['name']);?></td><td><?php echo h($r['department']);?></td><td><?php echo h($r['position']);?></td><td><?php echo h($r['status']);?></td><td><?php echo h($r['check_in']);?></td><td><?php echo h($r['check_out']);?></td><td><?php echo number_format(((int)$r['raw_minutes'])/60,2);?>h</td><td><?php echo number_format(((int)$r['work_minutes'])/60,2);?>h</td></tr><?php endforeach; ?></table><?php endif; ?>
<?php if($tab==='requests'): foreach($reqs as $r): ?><div><?php echo h($r['name'].' '.$r['request_date'].' '.$r['request_type'].' '.$r['status']);?> <form method='post' action='?r=management/attendance_request_approve'><input type='hidden' name='_csrf' value='<?php echo h(csrf_token());?>'><input type='hidden' name='id' value='<?php echo (int)$r['id'];?>'><button>승인</button></form></div><?php endforeach; endif; ?>
<?php if($tab==='weekly'): foreach($weekly as $r): $h=$r['m']/60; ?><div><?php echo h($r['name']);?> <?php echo number_format($h,2);?>h <?php if($h>(float)$settings['max_weekly_hours']) echo '<span style="color:red">52시간 초과자</span>';?></div><?php endforeach; endif; ?>
<?php if($tab==='settings'): ?><form method='post' action='?r=management/attendance_settings_save'><input type='hidden' name='_csrf' value='<?php echo h(csrf_token());?>'>기준<input name='standard_weekly_hours' value='<?php echo h($settings['standard_weekly_hours']);?>'>최대<input name='max_weekly_hours' value='<?php echo h($settings['max_weekly_hours']);?>'> 일일공제(분)<input name='daily_break_deduct_minutes' value='<?php echo h($settings['daily_break_deduct_minutes']);?>'><button>저장</button></form><?php endif; ?>
<?php if($tab==='leave'): ?><div>연차/월차/반차 관리는 관리 저장 API(leave_save/leave_delete)로 처리하세요.</div><?php endif; ?>