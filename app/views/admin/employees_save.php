<?php
require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }
$canManage = Auth::canManageEmployees();
$canSalary = (method_exists('App\\Core\\Auth', 'canManageSalary')) ? Auth::canManageSalary() : $canManage;
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ?r=관리&tab=employees'); exit; }
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) { flash_set('error', '보안 토큰이 유효하지 않습니다.'); header('Location: ?r=관리&tab=employees'); exit; }
$pdo = Db::pdo();
if (!$pdo) { flash_set('error', 'DB 연결 실패'); header('Location: ?r=관리&tab=employees'); exit; }

if (!function_exists('cpms_column_exists')) {
function cpms_column_exists($pdo, $table, $column) {
    try {
        $db=(string)$pdo->query("SELECT DATABASE()")->fetchColumn();
        if ($db==='') return false;
        $st=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:tbl AND COLUMN_NAME=:col");
        $st->execute(array(':db'=>$db,':tbl'=>$table,':col'=>$column));
        return ((int)$st->fetchColumn()>0);
    } catch (\Exception $e) { return false; }
}}

$positionEnabled = cpms_column_exists($pdo,'employees','position');
$hireDateEnabled = cpms_column_exists($pdo,'employees','hire_date');
$leaveMonthlyEnabled = cpms_column_exists($pdo,'employees','leave_monthly_balance');
$leaveAnnualEnabled = cpms_column_exists($pdo,'employees','leave_annual_balance');
$leaveHalfEnabled = cpms_column_exists($pdo,'employees','leave_half_balance');
$birthDateEnabled = cpms_column_exists($pdo,'employees','birth_date');
$siteManagerEnabled = cpms_column_exists($pdo,'employees','approval_can_be_site_manager');
$teamLeaderEnabled = cpms_column_exists($pdo,'employees','approval_can_be_team_leader');
$gongmuEnabled = cpms_column_exists($pdo,'employees','approval_can_be_gongmu_approver');
$manageEnabled = cpms_column_exists($pdo,'employees','approval_can_be_manage_approver');
$chatEnabledCol = cpms_column_exists($pdo,'employees','google_chat_enabled');
$chatUserEnabled = cpms_column_exists($pdo,'employees','google_chat_user_name');
$chatSpaceEnabled = cpms_column_exists($pdo,'employees','google_chat_dm_space_name');


$action = isset($_POST['action']) ? (string)$_POST['action'] : 'save';
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($action === 'delete') {
    if (!$canManage) { http_response_code(403); echo '403 Forbidden'; exit; }
    if ($id <= 0) { flash_set('error', '삭제 대상이 올바르지 않습니다.'); header('Location: ?r=관리&tab=employees'); exit; }
    try {
        $photoPath = null;
        $st0 = $pdo->prepare("SELECT photo_path FROM employees WHERE id=:id LIMIT 1");
        $st0->bindValue(':id', $id, \PDO::PARAM_INT); $st0->execute(); $row0 = $st0->fetch();
        if (is_array($row0)) $photoPath = isset($row0['photo_path']) ? $row0['photo_path'] : null;
        $st = $pdo->prepare("DELETE FROM employees WHERE id=:id");
        $st->bindValue(':id', $id, \PDO::PARAM_INT); $st->execute();
        if (is_string($photoPath) && strpos($photoPath, '/cpms/public/uploads/employees/') === 0) {
            $projectRoot = realpath(__DIR__ . '/../../../..');
            if ($projectRoot !== false) { $fs = $projectRoot . '/public/uploads/employees/' . basename($photoPath); if (is_file($fs)) @unlink($fs); }
        }
        flash_set('success', '직원이 삭제되었습니다.');
    } catch (\Exception $e) { flash_set('error', '삭제 실패: '.$e->getMessage()); }
    header('Location: ?r=관리&tab=employees'); exit;
}

if ($action === 'salary') {
    if (!$canSalary) { http_response_code(403); echo '403 Forbidden'; exit; }
    $salaryRaw = isset($_POST['monthly_salary']) ? trim((string)$_POST['monthly_salary']) : '';
    $salary = ($salaryRaw === '') ? null : max(0, (int)$salaryRaw);
    try {
        $st = $pdo->prepare("UPDATE employees SET monthly_salary=:salary WHERE id=:id");
        if ($salary === null) $st->bindValue(':salary', null, \PDO::PARAM_NULL); else $st->bindValue(':salary', $salary, \PDO::PARAM_INT);
        $st->bindValue(':id', $id, \PDO::PARAM_INT); $st->execute();
        flash_set('success', '월급이 저장되었습니다.');
    } catch (\Exception $e) { flash_set('error', '월급 저장 실패: '.$e->getMessage()); }
    header('Location: ?r=관리&tab=employees'); exit;
}

if (!$canManage) { http_response_code(403); echo '403 Forbidden'; exit; }

$email = isset($_POST['email']) ? trim((string)$_POST['email']) : ''; // employees_save email 누락 수정
$name = isset($_POST['name']) ? trim((string)$_POST['name']) : '';
$dept = isset($_POST['department']) ? trim((string)$_POST['department']) : '';
$pos = isset($_POST['position']) ? trim((string)$_POST['position']) : '';
$role = isset($_POST['role']) ? (string)$_POST['role'] : 'employee';
$isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
$hireDate = isset($_POST['hire_date']) ? trim((string)$_POST['hire_date']) : '';
$leaveMonthly = isset($_POST['leave_monthly_balance']) ? trim((string)$_POST['leave_monthly_balance']) : '';
$leaveAnnual = isset($_POST['leave_annual_balance']) ? trim((string)$_POST['leave_annual_balance']) : '';
$leaveHalf = isset($_POST['leave_half_balance']) ? trim((string)$_POST['leave_half_balance']) : '';
$birthDate = isset($_POST['birth_date']) ? trim((string)$_POST['birth_date']) : '';
$canSite = isset($_POST['approval_can_be_site_manager']) ? 1 : 0;
$canLead = isset($_POST['approval_can_be_team_leader']) ? 1 : 0;
$canGongmu = isset($_POST['approval_can_be_gongmu_approver']) ? 1 : 0;
$canManageApprover = isset($_POST['approval_can_be_manage_approver']) ? 1 : 0;
$googleChatEnabled = isset($_POST['google_chat_enabled']) ? 1 : 0;
$googleChatUserName = isset($_POST['google_chat_user_name']) ? trim((string)$_POST['google_chat_user_name']) : '';
$googleChatSpaceName = isset($_POST['google_chat_dm_space_name']) ? trim((string)$_POST['google_chat_dm_space_name']) : '';

if ($email === '' || $name === '') { flash_set('error', '이메일/이름은 필수입니다.'); header('Location: ?r=관리&tab=employees'); exit; }

$allowedDepts = array('관리', '공무', '품질', '안전', '공사');
$allowedPositions = array('주임','대리','과장','차장','부장','전무','상무','이사','부사장','고문','대표');
if (!in_array($role, array('employee','executive'), true)) $role='employee';
if ($isActive!==0 && $isActive!==1) $isActive=1;
if ($dept !== '' && !in_array($dept, $allowedDepts, true)) $dept='';
if ($pos !== '' && !in_array($pos, $allowedPositions, true)) $pos='';

try {
    $fields = array('email=:email','name=:name','department=:dept','role=:role','is_active=:active');
    if ($positionEnabled) $fields[] = 'position=:pos';
    if ($hireDateEnabled) $fields[] = 'hire_date=:hire_date';
    if ($leaveMonthlyEnabled) $fields[] = 'leave_monthly_balance=:leave_monthly_balance';
    if ($leaveAnnualEnabled) $fields[] = 'leave_annual_balance=:leave_annual_balance';
    if ($leaveHalfEnabled) $fields[] = 'leave_half_balance=:leave_half_balance';
    if ($birthDateEnabled) $fields[] = 'birth_date=:birth_date';
    if ($siteManagerEnabled) $fields[] = 'approval_can_be_site_manager=:approval_can_be_site_manager';
    if ($teamLeaderEnabled) $fields[] = 'approval_can_be_team_leader=:approval_can_be_team_leader';
    if ($gongmuEnabled) $fields[] = 'approval_can_be_gongmu_approver=:approval_can_be_gongmu_approver';
    if ($manageEnabled) $fields[] = 'approval_can_be_manage_approver=:approval_can_be_manage_approver';
    if ($chatEnabledCol) $fields[] = 'google_chat_enabled=:google_chat_enabled';
    if ($chatUserEnabled) $fields[] = 'google_chat_user_name=:google_chat_user_name';
    if ($chatSpaceEnabled) $fields[] = 'google_chat_dm_space_name=:google_chat_dm_space_name';

    if ($id > 0) {
        $sql = "UPDATE employees SET ".implode(',',$fields)." WHERE id=:id";
        $st = $pdo->prepare($sql);
    } else {
        $cols = array('email','name','department','role','is_active');
        $vals = array(':email',':name',':dept',':role',':active');
        if ($positionEnabled) { $cols[]='position'; $vals[]=':pos'; }
        if ($hireDateEnabled) { $cols[]='hire_date'; $vals[]=':hire_date'; }
        if ($leaveMonthlyEnabled) { $cols[]='leave_monthly_balance'; $vals[]=':leave_monthly_balance'; }
        if ($leaveAnnualEnabled) { $cols[]='leave_annual_balance'; $vals[]=':leave_annual_balance'; }
        if ($leaveHalfEnabled) { $cols[]='leave_half_balance'; $vals[]=':leave_half_balance'; }
        if ($birthDateEnabled) { $cols[]='birth_date'; $vals[]=':birth_date'; }
        if ($siteManagerEnabled) { $cols[]='approval_can_be_site_manager'; $vals[]=':approval_can_be_site_manager'; }
        if ($teamLeaderEnabled) { $cols[]='approval_can_be_team_leader'; $vals[]=':approval_can_be_team_leader'; }
        if ($gongmuEnabled) { $cols[]='approval_can_be_gongmu_approver'; $vals[]=':approval_can_be_gongmu_approver'; }
        if ($manageEnabled) { $cols[]='approval_can_be_manage_approver'; $vals[]=':approval_can_be_manage_approver'; }
        if ($chatEnabledCol) { $cols[]='google_chat_enabled'; $vals[]=':google_chat_enabled'; }
        if ($chatUserEnabled) { $cols[]='google_chat_user_name'; $vals[]=':google_chat_user_name'; }
        if ($chatSpaceEnabled) { $cols[]='google_chat_dm_space_name'; $vals[]=':google_chat_dm_space_name'; }        
        $sql = "INSERT INTO employees (".implode(',', $cols).") VALUES (".implode(',', $vals).")";
        $st = $pdo->prepare($sql);
    }

    $st->bindValue(':email', $email); $st->bindValue(':name', $name); $st->bindValue(':dept', $dept); $st->bindValue(':role', $role); $st->bindValue(':active', $isActive, \PDO::PARAM_INT);
    if ($positionEnabled) { if ($pos==='') $st->bindValue(':pos', null, \PDO::PARAM_NULL); else $st->bindValue(':pos', $pos); }
    if ($hireDateEnabled) { // 입사일 저장
        if ($hireDate === '') $st->bindValue(':hire_date', null, \PDO::PARAM_NULL);
        else $st->bindValue(':hire_date', $hireDate);
    }
    if ($leaveMonthlyEnabled) { // 휴가잔여 저장
        if ($leaveMonthly === '') $st->bindValue(':leave_monthly_balance', null, \PDO::PARAM_NULL);
        else $st->bindValue(':leave_monthly_balance', (float)$leaveMonthly);
    }
    if ($leaveAnnualEnabled) {
        if ($leaveAnnual === '') $st->bindValue(':leave_annual_balance', null, \PDO::PARAM_NULL);
        else $st->bindValue(':leave_annual_balance', (float)$leaveAnnual);
    }
    if ($leaveHalfEnabled) {
        if ($leaveHalf === '') $st->bindValue(':leave_half_balance', null, \PDO::PARAM_NULL);
        else $st->bindValue(':leave_half_balance', (float)$leaveHalf);
    }

    if ($birthDateEnabled) { if ($birthDate==='') $st->bindValue(':birth_date', null, \PDO::PARAM_NULL); else $st->bindValue(':birth_date', $birthDate); }
    if ($siteManagerEnabled) $st->bindValue(':approval_can_be_site_manager',$canSite,\PDO::PARAM_INT);
    if ($teamLeaderEnabled) $st->bindValue(':approval_can_be_team_leader',$canLead,\PDO::PARAM_INT);
    if ($gongmuEnabled) $st->bindValue(':approval_can_be_gongmu_approver',$canGongmu,\PDO::PARAM_INT);
    if ($manageEnabled) $st->bindValue(':approval_can_be_manage_approver',$canManageApprover,\PDO::PARAM_INT);
    if ($chatEnabledCol) $st->bindValue(':google_chat_enabled',$googleChatEnabled,\PDO::PARAM_INT);
    if ($chatUserEnabled) { if($googleChatUserName==='') $st->bindValue(':google_chat_user_name',null,\PDO::PARAM_NULL); else $st->bindValue(':google_chat_user_name',$googleChatUserName); }
    if ($chatSpaceEnabled) { if($googleChatSpaceName==='') $st->bindValue(':google_chat_dm_space_name',null,\PDO::PARAM_NULL); else $st->bindValue(':google_chat_dm_space_name',$googleChatSpaceName); }    
        if ($id > 0) { // 직원 수정 id 바인딩 / HY093 오류 수정
        $st->bindValue(':id', $id, \PDO::PARAM_INT);
    }
    $st->execute();
    // 입사일 저장 확인 / 휴가잔여 저장 확인
    $savedId = ($id > 0) ? $id : (int)$pdo->lastInsertId();
    $msg = ($id > 0 ? '직원 정보가 수정되었습니다.' : '직원이 추가되었습니다.')
        . ' (id=' . $savedId . ', hire_date=' . ($hireDate === '' ? 'NULL' : $hireDate) . ', hire_date_column=' . ($hireDateEnabled ? 'yes' : 'no') . ')';
    flash_set('success', $msg);
    $currentUser = Auth::user();
    if (is_array($currentUser)) {
        $currentEmail = isset($currentUser['email']) ? strtolower(trim((string)$currentUser['email'])) : '';
        $targetEmail = strtolower(trim((string)$email));
        if ($currentEmail !== '' && $targetEmail !== '' && $currentEmail === $targetEmail && method_exists('App\\Core\\Auth','refreshCurrentUser')) {
            Auth::refreshCurrentUser(true);
        }
    }    
} catch (\Exception $e) { flash_set('error', '저장 실패: '.$e->getMessage()); }

header('Location: ?r=관리&tab=employees');
exit;