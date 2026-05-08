<?php
require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

// 직원명부 컬럼추가 action 분리 / headers already sent 방지
if (!Auth::check()) { header('Location: ?r=login'); exit; }
if (!Auth::canManageEmployees() && !Auth::isMaster()) { http_response_code(403); echo '403 Forbidden'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ?r=관리&tab=employees'); exit; }
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) { flash_set('error', '보안 토큰이 유효하지 않습니다.'); header('Location: ?r=관리&tab=employees'); exit; }

$pdo = Db::pdo();
if (!$pdo) { flash_set('error', 'DB 연결 실패'); header('Location: ?r=관리&tab=employees'); exit; }

if (!function_exists('column_exists')) {
function column_exists($pdo, $table, $column) {
    try {
        $dbName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
        if ($dbName === '') return false;
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:tbl AND COLUMN_NAME=:col");
        $st->execute(array(':db'=>$dbName, ':tbl'=>$table, ':col'=>$column));
        return ((int)$st->fetchColumn() > 0);
    } catch (\Exception $e) { return false; }
}}

$action = isset($_POST['action']) ? (string)$_POST['action'] : '';

try {
    if ($action === 'add_position_column') {
        if (column_exists($pdo, 'employees', 'position')) flash_set('success', '이미 존재합니다: position');
        else { $pdo->exec("ALTER TABLE employees ADD COLUMN position VARCHAR(20) NULL"); flash_set('success', 'position 컬럼 추가 완료'); }
    } elseif ($action === 'add_hire_date_column') {
        if (column_exists($pdo, 'employees', 'hire_date')) flash_set('success', '이미 존재합니다: hire_date');
        else { $pdo->exec("ALTER TABLE employees ADD COLUMN hire_date DATE NULL"); flash_set('success', 'hire_date 컬럼 추가 완료'); }
    } elseif ($action === 'add_leave_balance_columns') {
        $added = array(); $exists = array();
        foreach (array('leave_monthly_balance','leave_annual_balance','leave_half_balance') as $c) {
            if (column_exists($pdo, 'employees', $c)) $exists[] = $c;
            else { $pdo->exec("ALTER TABLE employees ADD COLUMN {$c} DECIMAL(6,2) NULL"); $added[] = $c; }
        }
        flash_set('success', '휴가잔여 컬럼 처리: 추가('.(count($added)?implode(', ', $added):'없음').') / 이미존재('.(count($exists)?implode(', ', $exists):'없음').')');
    } elseif ($action === 'add_employee_attendance_columns') {
        $added = array(); $exists = array();
        $targets = array('position'=>'VARCHAR(20) NULL','hire_date'=>'DATE NULL','leave_monthly_balance'=>'DECIMAL(6,2) NULL','leave_annual_balance'=>'DECIMAL(6,2) NULL','leave_half_balance'=>'DECIMAL(6,2) NULL');
        foreach ($targets as $c => $typeSql) {
            if (column_exists($pdo, 'employees', $c)) { $exists[] = $c; continue; }
            $pdo->exec("ALTER TABLE employees ADD COLUMN {$c} {$typeSql}");
            $added[] = $c;
        }
        flash_set('success', '직원명부 추가 컬럼 처리: 추가('.(count($added)?implode(', ', $added):'없음').') / 이미존재('.(count($exists)?implode(', ', $exists):'없음').')');
    } else {
        flash_set('error', '지원하지 않는 action 입니다.');
    }
} catch (\Exception $e) {
    flash_set('error', '컬럼 처리 실패: '.$e->getMessage());
}

header('Location: ?r=관리&tab=employees');
exit;