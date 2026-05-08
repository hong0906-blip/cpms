<?php
use App\Core\Db;
use App\Core\Auth;

$canManage = Auth::canManageEmployees();
if (!$canManage) { echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">접근 권한이 없습니다.</div>'; return; }
$pdo = Db::pdo(); $q = isset($_GET['q']) ? trim((string)$_GET['q']) : ''; $rows = array(); $dbOk = ($pdo !== null); $employeeLoadError='';
$deptOptions = array('관리', '공무', '품질', '안전', '공사');
$positionOptions = array('주임','대리','과장','차장','부장','이사','전무','상무','부사장','고문','대표');

function cpms_column_exists($pdo, $table, $column) {
    try { $dbName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn(); if ($dbName === '') return false;
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:tbl AND COLUMN_NAME=:col");
        $st->execute(array(':db'=>$dbName,':tbl'=>$table,':col'=>$column)); return ((int)$st->fetchColumn() > 0);
    } catch (\Exception $e) { return false; }
}

// 직원명부 컬럼 존재 여부 체크
$positionEnabled=false; $hireDateEnabled=false; $leaveMonthlyEnabled=false; $leaveAnnualEnabled=false; $leaveHalfEnabled=false;
if ($dbOk) {
    $positionEnabled = cpms_column_exists($pdo, 'employees', 'position');
    $hireDateEnabled = cpms_column_exists($pdo, 'employees', 'hire_date');
    $leaveMonthlyEnabled = cpms_column_exists($pdo, 'employees', 'leave_monthly_balance');
    $leaveAnnualEnabled = cpms_column_exists($pdo, 'employees', 'leave_annual_balance');
    $leaveHalfEnabled = cpms_column_exists($pdo, 'employees', 'leave_half_balance');    
}

if ($dbOk && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) { flash_set('error','보안 토큰이 유효하지 않습니다.'); header('Location: ?r=관리'); exit; }
    $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
    try {
        if ($action === 'add_position_column') {
            if (cpms_column_exists($pdo,'employees','position')) flash_set('success','이미 존재합니다: position');
            else { $pdo->exec("ALTER TABLE employees ADD COLUMN position VARCHAR(20) NULL AFTER department"); flash_set('success','position 컬럼을 추가했습니다.'); }
        }
        // 입사일 컬럼 추가 버튼
        if ($action === 'add_hire_date_column') {
            if (cpms_column_exists($pdo,'employees','hire_date')) flash_set('success','이미 존재합니다: hire_date');
            else { $pdo->exec("ALTER TABLE employees ADD COLUMN hire_date DATE NULL AFTER position"); flash_set('success','hire_date 컬럼을 추가했습니다.'); }
        }
        // 휴가잔여 컬럼 추가 버튼
        if ($action === 'add_leave_balance_columns' || $action === 'add_employee_attendance_columns') {
            $added=array(); $exists=array();
            foreach (array('leave_monthly_balance','leave_annual_balance','leave_half_balance') as $c) {
                if (cpms_column_exists($pdo,'employees',$c)) $exists[]=$c; else { $pdo->exec("ALTER TABLE employees ADD COLUMN {$c} DECIMAL(6,2) NULL"); $added[]=$c; }
            }
            flash_set('success', '완료: 추가('.implode(', ', $added).') / 이미존재('.implode(', ', $exists).')');
        }
    } catch (\Exception $e) { flash_set('error', '컬럼 처리 실패: '.$e->getMessage()); }
    header('Location: ?r=관리'); exit;
}

if ($dbOk) {
    $positionSelect = $positionEnabled ? 'position' : "'' AS position";
    $hireDateSelect = $hireDateEnabled ? 'hire_date' : 'NULL AS hire_date';
    $lmSelect = $leaveMonthlyEnabled ? 'leave_monthly_balance' : 'NULL AS leave_monthly_balance';
    $laSelect = $leaveAnnualEnabled ? 'leave_annual_balance' : 'NULL AS leave_annual_balance';
    $lhSelect = $leaveHalfEnabled ? 'leave_half_balance' : 'NULL AS leave_half_balance';
    $sql = "SELECT id,email,name,department,{$positionSelect},{$hireDateSelect},{$lmSelect},{$laSelect},{$lhSelect},role,photo_path,is_active FROM employees WHERE 1=1";
    $params=array();
    if ($q !== '') { $sql .= " AND (email LIKE :q OR name LIKE :q OR department LIKE :q".($positionEnabled?" OR position LIKE :q":"").")"; $params[':q']='%'.$q.'%'; }
    $sql .= " ORDER BY is_active DESC, role DESC, department ASC, name ASC, id DESC LIMIT 500";
    // 직원명부 안전 SELECT
    try { $st=$pdo->prepare($sql); foreach($params as $k=>$v){$st->bindValue($k,$v);} $st->execute(); $rows=$st->fetchAll(); }
    // 직원명부 조회 오류 표시
    catch (\Exception $e) { $rows=array(); $employeeLoadError = '직원명부 조회 중 오류가 발생했습니다: '.$e->getMessage(); }
}
?>
<div class="text-sm text-gray-500">관리</div><h2 class="text-2xl font-extrabold">직원명부</h2>
<details class="mb-4"><summary>직원명부 진단</summary><div class="text-xs">EMPLOYEES_PAGE_LOADED = yes / position=<?php echo $positionEnabled?'yes':'no'; ?> / hire_date=<?php echo $hireDateEnabled?'yes':'no'; ?> / leave_monthly_balance=<?php echo $leaveMonthlyEnabled?'yes':'no'; ?> / leave_annual_balance=<?php echo $leaveAnnualEnabled?'yes':'no'; ?> / leave_half_balance=<?php echo $leaveHalfEnabled?'yes':'no'; ?></div></details>
<?php if (!empty($employeeLoadError)): ?><div class="mb-4 border border-red-300 bg-red-50 text-red-700 p-3 rounded"><?php echo h($employeeLoadError); ?></div><?php endif; ?>
<div class="mb-4 flex gap-2 flex-wrap">
<form method="post"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="add_position_column"><button class="px-3 py-2 border rounded">직급 컬럼 추가</button></form>
<form method="post"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="add_hire_date_column"><button class="px-3 py-2 border rounded">입사날짜 컬럼 추가</button></form>
<form method="post"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="add_leave_balance_columns"><button class="px-3 py-2 border rounded">휴가잔여 컬럼 추가</button></form>
<form method="post"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="add_employee_attendance_columns"><button class="px-3 py-2 border rounded">직원명부 추가 컬럼 전체 생성/확인</button></form>
</div>
<form method="get" class="mb-4"><input type="hidden" name="r" value="관리"><input name="q" value="<?php echo h($q); ?>" placeholder="검색"><button>검색</button></form>
<table><tr><th>이름</th><th>이메일</th><th>부서</th><th>직급</th><th>입사일</th><th>권한</th></tr><?php foreach($rows as $r): ?><tr><td><?php echo h($r['name']);?></td><td><?php echo h($r['email']);?></td><td><?php echo h($r['department']);?></td><td><?php echo h($r['position']);?></td><td><?php echo h($r['hire_date']);?></td><td><?php echo h($r['role']);?></td></tr><?php endforeach; ?></table>
<div class="mt-4">
<form method="post" action="?r=admin/employees_save"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="save"><input name="email" required placeholder="email"><input name="name" required placeholder="name"><select name="department"><option value=""></option><?php foreach($deptOptions as $d): ?><option value="<?php echo h($d); ?>"><?php echo h($d); ?></option><?php endforeach; ?></select><?php if($positionEnabled): ?><select name="position"><option value=""></option><?php foreach($positionOptions as $p): ?><option value="<?php echo h($p); ?>"><?php echo h($p); ?></option><?php endforeach; ?></select><?php endif; ?>
<?php if($hireDateEnabled): ?><input type="date" name="hire_date"><?php endif; ?>
<?php if($leaveMonthlyEnabled || $leaveAnnualEnabled || $leaveHalfEnabled): ?>
<?php if($leaveMonthlyEnabled): ?><input type="number" step="0.01" name="leave_monthly_balance" placeholder="남은 월차"><?php endif; ?>
<?php if($leaveAnnualEnabled): ?><input type="number" step="0.01" name="leave_annual_balance" placeholder="남은 연차"><?php endif; ?>
<?php if($leaveHalfEnabled): ?><input type="number" step="0.01" name="leave_half_balance" placeholder="남은 반차"><?php endif; ?>
<?php else: ?><div>입사일/휴가잔여 컬럼을 먼저 추가하세요.</div><?php endif; ?>
<button>저장</button></form></div>