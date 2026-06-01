<?php
/**
 * C:\www\cpms\app\core\Auth.php
 * - CPMS 인증/세션 관리
 * - 포탈 세션(user_email) 기반 자동 로그인
 * - employees 테이블 email 매칭 시: name/photo/role/department/position 적용
 *
 * PHP 5.6 호환
 */

namespace App\Core;

use App\Core\Db;

class Auth
{
    const CPMS_USER_KEY = 'cpms_user';

    // 마스터 계정 권한
    // 마스터 계정 이메일이 다르면 여기에 추가
    private static function masterEmails()
    {
        return array(
            'hong0906@cmbuild.kr',
        );
    }

    // 마스터 권한 대소문자 무시
    private static function normalizeEmail($email)
    {
        return strtolower(trim((string)$email));
    }

    public static function isMaster()
    {
        $emails = array(
            self::userEmail(),
            isset($_SESSION['user_email']) ? $_SESSION['user_email'] : '',
            (isset($_SESSION[self::CPMS_USER_KEY]) && is_array($_SESSION[self::CPMS_USER_KEY]) && isset($_SESSION[self::CPMS_USER_KEY]['email'])) ? $_SESSION[self::CPMS_USER_KEY]['email'] : '',
        );

        $masters = array();
        foreach (self::masterEmails() as $masterEmail) {
            $nm = self::normalizeEmail($masterEmail);
            if ($nm !== '') $masters[] = $nm;
        }

        foreach ($emails as $email) {
            $ne = self::normalizeEmail($email);
            if ($ne !== '' && in_array($ne, $masters, true)) return true;
        }

        return false;
    }

    // (기존 임원 이메일 fallback - DB role이 없을 때만)
    private static function executiveEmails()
    {
        return array(
            'chairman@cmbuild.kr',
            'ceo@cmbuild.kr',
            'shinbad@cmbuild.kr',
            'hcsong@cmbuild.kr',
            'ybkang@cmbuild.kr',
            'sjw5523@cmbuild.kr',
            'emaetal@cmbuild.kr',
            'shhong@cmbuild.kr',
            'hong0906@cmbuild.kr',
        );
    }

    public static function check()
    {
        // 세션 없으면 포탈 기반 자동로그인 시도 (요청한 자동로그인 유지)
        if (!isset($_SESSION[self::CPMS_USER_KEY]) || !is_array($_SESSION[self::CPMS_USER_KEY])) {
            self::autoLoginFromPortal();
        }
        return isset($_SESSION[self::CPMS_USER_KEY]) && is_array($_SESSION[self::CPMS_USER_KEY]);
    }

    public static function user()
    {
        return self::check() ? $_SESSION[self::CPMS_USER_KEY] : null;
    }

    public static function userEmail()
    {
        $u = self::user();
        return $u && isset($u['email']) ? $u['email'] : null;
    }

    public static function userName()
    {
        $u = self::user();
        return $u && isset($u['name']) ? $u['name'] : null;
    }

    public static function userRole()
    {
        $u = self::user();
        if (self::isMaster()) return 'executive'; // 마스터 계정 권한
        return $u && isset($u['role']) ? $u['role'] : 'employee';
    }

    // ★ 부서
    public static function userDepartment()
    {
        $u = self::user();
        return $u && isset($u['department']) ? (string)$u['department'] : '';
    }

    // ★ 직급
    public static function userPosition()
    {
        $u = self::user();
        return $u && isset($u['position']) ? (string)$u['position'] : '';
    }

    // ★ 직원명부 관리 가능 여부: 임원 OR 관리(관리부)
    public static function canManageEmployees()
    {
        if (!self::check()) return false;
        if (self::isMaster()) return true; // 마스터 계정 권한

        $role = self::userRole();
        if ($role === 'executive') return true;

        $dept = self::userDepartment();
        // 기존 데이터(관리부) + 신규 데이터(관리) 모두 허용
        return ($dept === '관리' || $dept === '관리부');
    }

    // ★ 월급 설정 가능: 임원 OR 관리(관리부)
    public static function canManageSalary()
    {
        if (self::isMaster()) return true;        
        return self::canManageEmployees();
    }

    
    // 마스터 전체 권한: 공사 섹션 접근
    public static function canAccessConstruction()
    {
        if (!self::check()) return false;
        if (self::isMaster()) return true; // 마스터 전체 권한

        $role = self::userRole();
        $dept = self::userDepartment();
        return ($role === 'executive' || $dept === '공사');
    }

    // 마스터 전체 권한: 공사 저장/수정/삭제
    public static function canManageConstruction()
    {
        return self::canAccessConstruction();
    }

    // 견적관리 접근 권한: 공무팀, 부사장/대표, 마스터 관리자
    public static function canAccessEstimate()
    {
        if (!self::check()) return false;
        if (self::isMaster()) return true;

        $dept = self::normalizeDept(self::userDepartment());
        if ($dept === '공무') return true;

        $role = self::normalizeText(self::userRole());
        $pos = self::normalizeText(self::userPosition());

        $allowedWords = array('부사장', '대표', '대표님', 'ceo', 'president', 'vicepresident', 'vp');
        foreach ($allowedWords as $word) {
            $wordNorm = self::normalizeText($word);
            if ($wordNorm !== '' && $role !== '' && strpos($role, $wordNorm) !== false) return true;
            if ($wordNorm !== '' && $pos !== '' && strpos($pos, $wordNorm) !== false) return true;
        }

        return false;
    }

    private static function normalizeText($value)
    {
        $value = trim((string)$value);
        $value = str_replace(array(' ', "\t", "\r", "\n", '-', '_'), '', $value);
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }
        return strtolower($value);
    }

    public static function logout()
    {
        unset($_SESSION[self::CPMS_USER_KEY]);
    }

    // 직원명부 변경 후 즉시 반영용(있으면 employees_save.php에서 호출함)
    public static function refreshCurrentUser($force)
    {
        if (!self::check()) return false;
        $email = self::userEmail();
        if (!$email) return false;
        return self::loadFromEmployeesByEmail($email, (bool)$force);
    }

    // ===== 포탈 세션 기반 자동로그인 =====
    public static function autoLoginFromPortal()
    {
        if (isset($_SESSION[self::CPMS_USER_KEY]) && is_array($_SESSION[self::CPMS_USER_KEY])) {
            return;
        }

        $portalEmail = isset($_SESSION['user_email']) ? trim((string)$_SESSION['user_email']) : '';
        if ($portalEmail === '') return;

        // 기본 세션 먼저 생성
        $_SESSION[self::CPMS_USER_KEY] = array(
            'email'      => $portalEmail,
            'name'       => $portalEmail,
            'role'       => 'employee',
            'photo_path' => null,
            'department' => '',
            'position'   => '',
            'id'         => 0,            
        );

        // DB에서 실제 값 로드
        self::loadFromEmployeesByEmail($portalEmail, true);
    }

    private static function normalizeDept($dept)
    {
        $dept = trim((string)$dept);
        $map = array(
            '관리부' => '관리',
            '공무부' => '공무',
            '품질부' => '품질',
            '안전부' => '안전',
            '공사부' => '공사',
            '안전/보건' => '안전',
            '안전보건' => '안전',
        );
        if (isset($map[$dept])) $dept = $map[$dept];
        if (substr($dept, -1) === '부') $dept = substr($dept, 0, -1);
        return trim($dept);
    }

    private static function positionColumnExists($pdo)
    {
        try {
            $dbName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
            if ($dbName === '') return false;

            $sql = "SELECT COUNT(*)
                    FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = :db
                      AND TABLE_NAME = 'employees'
                      AND COLUMN_NAME = 'position'";
            $st = $pdo->prepare($sql);
            $st->bindValue(':db', $dbName);
            $st->execute();
            return ((int)$st->fetchColumn() > 0);
        } catch (\Exception $e) {
            return false;
        }
    }

    private static function loadFromEmployeesByEmail($email, $force)
    {
        $email = self::normalizeEmail($email);
        if ($email === '') return false;

        $pdo = Db::pdo();

        $name = '';
        $role = 'employee';
        $photo = null;
        $dept = '';
        $pos = '';
        $employeeId = 0;        

        if ($pdo) {
            try {
                $hasPos = self::positionColumnExists($pdo);

                if ($hasPos) {
                    $st = $pdo->prepare("SELECT id, name, role, photo_path, is_active, department, position
                                         FROM employees WHERE email = :email LIMIT 1");
                } else {
                    $st = $pdo->prepare("SELECT id, name, role, photo_path, is_active, department
                                         FROM employees WHERE email = :email LIMIT 1");
                }

                $st->bindValue(':email', $email);
                $st->execute();
                $row = $st->fetch();

                if (is_array($row)) {
                    $employeeId = isset($row['id']) ? (int)$row['id'] : 0;                    
                    $name = isset($row['name']) ? (string)$row['name'] : '';
                    $role = isset($row['role']) ? (string)$row['role'] : 'employee';
                    $photo = isset($row['photo_path']) ? $row['photo_path'] : null;
                    $dept = isset($row['department']) ? (string)$row['department'] : '';
                    if ($hasPos) $pos = isset($row['position']) ? (string)$row['position'] : '';
                }
            } catch (\Exception $e) {
                // DB 오류여도 포탈 로그인을 막지 않음
            }
        }

        // 마스터 계정 권한: executive로 강제
        $normalizedMasterEmails = array();
        foreach (self::masterEmails() as $masterEmail) {
            $normalizedMasterEmails[] = self::normalizeEmail($masterEmail);
        }

        if (in_array($email, $normalizedMasterEmails, true)) {
            $role = 'executive';
        }

        // role fallback
        if ($role !== 'executive') {
            if (in_array($email, self::executiveEmails(), true)) $role = 'executive';
        }

        $dept = self::normalizeDept($dept);

        // 세션 반영
        if (!isset($_SESSION[self::CPMS_USER_KEY]) || !is_array($_SESSION[self::CPMS_USER_KEY])) {
            $_SESSION[self::CPMS_USER_KEY] = array();
        }

        $_SESSION[self::CPMS_USER_KEY]['email'] = $email;
        $_SESSION[self::CPMS_USER_KEY]['name'] = ($name !== '' ? $name : $email);
        $_SESSION[self::CPMS_USER_KEY]['role'] = $role;
        $_SESSION[self::CPMS_USER_KEY]['photo_path'] = $photo;
        $_SESSION[self::CPMS_USER_KEY]['department'] = $dept;
        $_SESSION[self::CPMS_USER_KEY]['position'] = $pos;
        $_SESSION[self::CPMS_USER_KEY]['id'] = $employeeId;
        
        return true;
    }
}
