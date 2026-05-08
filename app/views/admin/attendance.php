<?php
use App\Core\Auth; use App\Core\Db;
require_once __DIR__.'/../attendance/common.php';
$canManageAttendance=(Auth::isMaster()||attendance_is_manager()); if(!$canManageAttendance){echo '권한없음'; return;}
$pdo=Db::pdo(); $date=isset($_GET['date'])?$_GET['date']:date('Y-m-d'); $tab=isset($_GET['atab'])?$_GET['atab']:'daily'; $settings=attendance_settings($pdo); list($ws,$we)=attendance_week_range($date);
$daily=array();$reqs=array();$weekly=array();$emps=array();$attendanceErrors=array();

if (!function_exists('cpms_column_exists')) {
function cpms_column_exists($pdo, $table, $column) {
    try { $db=(string)$pdo->query("SELECT DATABASE()")->fetchColumn(); if($db==='') return false;
        $st=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:tbl AND COLUMN_NAME=:col");
        $st->execute(array(':db'=>$db,':tbl'=>$table,':col'=>$column)); return ((int)$st->fetchColumn()>0);
    } catch (\Exception $e) { return false; }
}}
$positionEnabled = $pdo ? cpms_column_exists($pdo,'employees','position') : false;
$hireDateEnabled = $pdo ? cpms_column_exists($pdo,'employees','hire_date') : false;

if ($pdo) {
    $posSel=$positionEnabled?'position':"'' AS position";
    $hireSel=$hireDateEnabled?'hire_date':'NULL AS hire_date';
    // 출퇴근 employees 컬럼 안전 SELECT
    try { $emps=$pdo->query("SELECT id,name,department,{$posSel},{$hireSel} FROM employees ORDER BY name")->fetchAll(); }
    catch (\Exception $e) { $attendanceErrors[]='직원 조회 오류: '.$e->getMessage(); }

    // daily/requests/weekly 안전 조회
    try { $st=$pdo->prepare("SELECT e.name,e.department,".($positionEnabled?'e.position':"'' AS position").",a.* FROM cpms_attendance_records a JOIN employees e ON e.id=a.employee_id WHERE a.work_date=:d ORDER BY e.name"); $st->execute(array(':d'=>$date)); $daily=$st->fetchAll(); }
    catch (\Exception $e) { $attendanceErrors[]='일일 조회 오류: '.$e->getMessage(); }
    try { $reqs=$pdo->query("SELECT r.*,e.name FROM cpms_attendance_requests r JOIN employees e ON e.id=r.employee_id ORDER BY r.id DESC LIMIT 100")->fetchAll(); }
    catch (\Exception $e) { $attendanceErrors[]='요청 조회 오류: '.$e->getMessage(); }
    try { $st2=$pdo->prepare("SELECT e.id,e.name,e.department,SUM(a.work_minutes) m FROM employees e LEFT JOIN cpms_attendance_records a ON a.employee_id=e.id AND a.work_date BETWEEN :s AND :e GROUP BY e.id,e.name,e.department ORDER BY m DESC"); $st2->execute(array(':s'=>$ws,':e'=>$we)); $weekly=$st2->fetchAll(); }
    catch (\Exception $e) { $attendanceErrors[]='주간 조회 오류: '.$e->getMessage(); }
}
?>
<div><a href='?r=관리&tab=employees'>직원명부</a> | <a href='?r=db_setup_attendance'>출퇴근 DB 설정</a></div>
<?php if(!$hireDateEnabled): ?><div style="background:#fef3c7;border:1px solid #f59e0b;padding:8px;margin:8px 0;">입사일 컬럼이 없어 연차/월차 계산은 제한됩니다. 직원명부에서 컬럼을 추가하세요.</div><?php endif; ?>
<?php foreach($attendanceErrors as $e): ?><div style="background:#fee2e2;border:1px solid #ef4444;padding:8px;margin:8px 0;"><?php echo h($e); ?></div><?php endforeach; ?>
<h3>출퇴근/근태관리</h3>
<a href='?r=관리&tab=attendance&atab=daily'>일일</a> | <a href='?r=관리&tab=attendance&atab=requests'>요청</a> | <a href='?r=관리&tab=attendance&atab=weekly'>주간</a> | <a href='?r=관리&tab=attendance&atab=settings'>설정</a>
<?php if($tab==='daily'): ?><table><tr><th>직원명</th><th>부서</th><th>직책</th></tr><?php foreach($daily as $r): ?><tr><td><?php echo h($r['name']);?></td><td><?php echo h($r['department']);?></td><td><?php echo h($r['position']);?></td></tr><?php endforeach; ?></table><?php endif; ?>
<?php if($tab==='requests'): foreach($reqs as $r): ?><div><?php echo h($r['name']);?></div><?php endforeach; endif; ?>
<?php if($tab==='weekly'): foreach($weekly as $r): ?><div><?php echo h($r['name']);?> <?php echo number_format(((float)$r['m'])/60,2);?>h</div><?php endforeach; endif; ?>
<?php if($tab==='settings'): ?><form method='post' action='?r=management/attendance_settings_save'><input type='hidden' name='_csrf' value='<?php echo h(csrf_token());?>'><input name='standard_weekly_hours' value='<?php echo h($settings['standard_weekly_hours']);?>'><button>저장</button></form><?php endif; ?>