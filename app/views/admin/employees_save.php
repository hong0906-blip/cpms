<?php
/**
 * C:\www\cpms\app\views\admin\employees_save.php
 * - 직원 추가/수정/삭제 + (월급 별도 설정)
 * - 직급(position): 고정 드롭다운 값만 저장
 *
 * PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }

$canManage = Auth::canManageEmployees();
$canSalary = (method_exists('App\\Core\\Auth', 'canManageSalary')) ? Auth::canManageSalary() : $canManage;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ?r=관리'); exit; }

$token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
if (!csrf_check($token)) { flash_set('error', '보안 토큰이 유효하지 않습니다.'); header('Location: ?r=관리'); exit; }

$pdo = Db::pdo();
if (!$pdo) { flash_set('error', 'DB 연결 실패'); header('Location: ?r=관리'); exit; }

$action = isset($_POST['action']) ? (string)$_POST['action'] : 'save';
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

$allowedDepts = array('관리', '공무', '품질', '안전', '공사');
$allowedPositions = array('주임','대리','과장','차장','부장','전무','상무','이사','부사장','고문','대표');

/* ==========================
   delete
========================== */
if ($action === 'delete') {
    if (!$canManage) { http_response_code(403); echo '403 Forbidden'; exit; }
    if ($id <= 0) { flash_set('error', '삭제 대상이 올바르지 않습니다.'); header('Location: ?r=관리'); exit; }

    try {
        $photoPath = null;
        $st0 = $pdo->prepare("SELECT photo_path FROM employees WHERE id = :id LIMIT 1");
        $st0->bindValue(':id', $id, \PDO::PARAM_INT);
        $st0->execute();
        $row0 = $st0->fetch();
        if (is_array($row0)) $photoPath = isset($row0['photo_path']) ? $row0['photo_path'] : null;

        $st = $pdo->prepare("DELETE FROM employees WHERE id = :id");
        $st->bindValue(':id', $id, \PDO::PARAM_INT);
        $st->execute();

        if (is_string($photoPath) && strpos($photoPath, '/cpms/public/uploads/employees/') === 0) {
            $projectRoot = realpath(__DIR__ . '/../../../..');
            if ($projectRoot !== false) {
                $baseDir = $projectRoot . '/public/uploads/employees';
                $baseName = basename($photoPath);
                $fs = $baseDir . '/' . $baseName;
                if (is_file($fs)) @unlink($fs);
            }
        }

        flash_set('success', '직원이 삭제되었습니다.');
    } catch (\Exception $e) {
        flash_set('error', '삭제 실패: ' . $e->getMessage());
    }

    header('Location: ?r=관리');
    exit;
}

/* ==========================
   salary
========================== */
if ($action === 'salary') {
    if (!$canSalary) { http_response_code(403); echo '403 Forbidden'; exit; }
    if ($id <= 0) { flash_set('error', '월급 설정 대상이 올바르지 않습니다.'); header('Location: ?r=관리'); exit; }

    $salaryRaw = isset($_POST['monthly_salary']) ? trim((string)$_POST['monthly_salary']) : '';
    $salary = ($salaryRaw === '') ? null : max(0, (int)$salaryRaw);

    try {
        $sql = "UPDATE employees SET monthly_salary = :salary WHERE id = :id";
        $st = $pdo->prepare($sql);

        if ($salary === null) $st->bindValue(':salary', null, \PDO::PARAM_NULL);
        else $st->bindValue(':salary', $salary, \PDO::PARAM_INT);

        $st->bindValue(':id', $id, \PDO::PARAM_INT);
        $st->execute();

        flash_set('success', '월급이 저장되었습니다.');
    } catch (\Exception $e) {
        flash_set('error', '월급 저장 실패: ' . $e->getMessage());
    }

    header('Location: ?r=관리');
    exit;
}

/* ==========================
   save (기본정보: 부서/직급 포함, 월급 제외)
========================== */
if (!$canManage) { http_response_code(403); echo '403 Forbidden'; exit; }

$email = isset($_POST['email']) ? trim((string)$_POST['email']) : '';
$name  = isset($_POST['name']) ? trim((string)$_POST['name']) : '';
$dept  = isset($_POST['department']) ? trim((string)$_POST['department']) : '';
$pos   = isset($_POST['position']) ? trim((string)$_POST['position']) : '';
$role  = isset($_POST['role']) ? (string)$_POST['role'] : 'employee';
$isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;

if ($email === '' || $name === '') {
    flash_set('error', '이메일/이름은 필수입니다.');
    header('Location: ?r=관리');
    exit;
}

if (!in_array($role, array('employee','executive'), true)) $role = 'employee';
if ($isActive !== 0 && $isActive !== 1) $isActive = 1;

if ($dept !== '' && !in_array($dept, $allowedDepts, true)) $dept = '';
if ($pos !== '' && !in_array($pos, $allowedPositions, true)) $pos = '';

try {
    if ($id > 0) {
        $sql = "UPDATE employees
                SET email = :email,
                    name = :name,
                    department = :dept,
                    position = :pos,
                    role = :role,
                    is_active = :active
                WHERE id = :id";
        $st = $pdo->prepare($sql);
        $st->bindValue(':email', $email);
        $st->bindValue(':name', $name);
        $st->bindValue(':dept', $dept);

        if ($pos === '') $st->bindValue(':pos', null, \PDO::PARAM_NULL);
        else $st->bindValue(':pos', $pos);

        $st->bindValue(':role', $role);
        $st->bindValue(':active', $isActive, \PDO::PARAM_INT);
        $st->bindValue(':id', $id, \PDO::PARAM_INT);
        $st->execute();

        flash_set('success', '직원 정보가 수정되었습니다.');
    } else {
        $sql = "INSERT INTO employees (email, name, department, position, role, is_active)
                VALUES (:email, :name, :dept, :pos, :role, :active)";
        $st = $pdo->prepare($sql);
        $st->bindValue(':email', $email);
        $st->bindValue(':name', $name);
        $st->bindValue(':dept', $dept);

        if ($pos === '') $st->bindValue(':pos', null, \PDO::PARAM_NULL);
        else $st->bindValue(':pos', $pos);

        $st->bindValue(':role', $role);
        $st->bindValue(':active', $isActive, \PDO::PARAM_INT);
        $st->execute();

        flash_set('success', '직원이 추가되었습니다.');
    }

    // 본인 수정 시 즉시 반영(이름/부서/직급/권한)
    if (Auth::userEmail() === $email && method_exists('App\\Core\\Auth', 'refreshCurrentUser')) {
        Auth::refreshCurrentUser(true);
    }
} catch (\Exception $e) {
    flash_set('error', '저장 실패: ' . $e->getMessage());
}

header('Location: ?r=관리');
exit;