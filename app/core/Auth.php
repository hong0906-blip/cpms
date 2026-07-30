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
    const CPMS_REMEMBER_COOKIE = 'CPMSAUTH';
    private static $sessionRefreshed = false;
    private static $rememberCookieRefreshed = false;

    // 이메일 기반 마스터는 사용하지 않습니다.
    // 마스터 권한은 직원명부의 부서가 "개발"일 때만 부여합니다.
    private static function masterEmails()
    {
        return array();
    }

    // 마스터 권한 대소문자 무시
    private static function normalizeEmail($email)
    {
        return strtolower(trim((string)$email));
    }

    private static function keepSeconds()
    {
        if (defined('CPMS_SESSION_KEEP_SECONDS')) {
            return (int)constant('CPMS_SESSION_KEEP_SECONDS');
        }
        return 60 * 60 * 14;
    }

    private static function cookieDomain()
    {
        $host = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : '';
        $host = preg_replace('/:\\d+$/', '', $host);
        $host = strtolower($host);
        $baseCookieDomain = 'cmbuild.kr';
        if ($host === $baseCookieDomain || substr($host, -1 * (strlen($baseCookieDomain) + 1)) === '.' . $baseCookieDomain) {
            return $baseCookieDomain;
        }
        return '';
    }

    private static function isHttps()
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    }

    private static function rememberSecret()
    {
        $rootDir = dirname(dirname(__DIR__));
        $secretDir = $rootDir . '/storage/secrets';
        $secretFile = $secretDir . '/cpms_auth_cookie_secret.php';

        if (is_file($secretFile)) {
            $loaded = @include $secretFile;
            if (is_string($loaded) && trim($loaded) !== '') {
                return trim($loaded);
            }
        }

        if (!is_dir($secretDir)) {
            @mkdir($secretDir, 0777, true);
        }
        if (!is_dir($secretDir) || !is_writable($secretDir)) {
            return '';
        }

        $bytes = false;
        if (function_exists('openssl_random_pseudo_bytes')) {
            $bytes = @openssl_random_pseudo_bytes(32);
        }
        if ($bytes === false || strlen($bytes) < 32) {
            $bytes = uniqid('', true) . '|' . mt_rand() . '|' . microtime(true) . '|' . __FILE__;
        }
        $secret = hash('sha256', $bytes);
        $content = "<?php\nreturn '" . $secret . "';\n";
        $tempFile = $secretFile . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tempFile, $content, LOCK_EX) === false) {
            return '';
        }
        if (!@rename($tempFile, $secretFile)) {
            @unlink($tempFile);
            if (is_file($secretFile)) {
                $loaded = @include $secretFile;
                if (is_string($loaded) && trim($loaded) !== '') {
                    return trim($loaded);
                }
            }
            return '';
        }

        return $secret;
    }

    private static function hashEquals($known, $user)
    {
        if (function_exists('hash_equals')) {
            return hash_equals((string)$known, (string)$user);
        }
        $known = (string)$known;
        $user = (string)$user;
        if (strlen($known) !== strlen($user)) return false;
        $result = 0;
        for ($i = 0; $i < strlen($known); $i++) {
            $result |= ord($known[$i]) ^ ord($user[$i]);
        }
        return $result === 0;
    }

    private static function issueRememberCookie($email)
    {
        if (self::$rememberCookieRefreshed || headers_sent()) return;

        $email = self::normalizeEmail($email);
        if ($email === '') return;

        $secret = self::rememberSecret();
        if ($secret === '') return;

        $expiresAt = time() + self::keepSeconds();
        // 세션 ID와 분리된 서명 쿠키를 사용한다.
        // 모바일 브라우저/앱 내 웹뷰가 백그라운드 복귀 시 세션 ID를
        // 새로 발급해도 PC와 같은 유지시간 동안 로그인을 복구할 수 있다.
        $data = $email . '|' . $expiresAt;
        $signature = hash_hmac('sha256', $data, $secret);
        $value = base64_encode($data . '|' . $signature);

        @setcookie(self::CPMS_REMEMBER_COOKIE, $value, $expiresAt, '/', self::cookieDomain(), self::isHttps(), true);
        $_COOKIE[self::CPMS_REMEMBER_COOKIE] = $value;
        self::$rememberCookieRefreshed = true;
    }

    private static function clearRememberCookie()
    {
        if (!headers_sent()) {
            @setcookie(self::CPMS_REMEMBER_COOKIE, '', time() - 3600, '/', self::cookieDomain(), self::isHttps(), true);
        }
        if (isset($_COOKIE[self::CPMS_REMEMBER_COOKIE])) {
            unset($_COOKIE[self::CPMS_REMEMBER_COOKIE]);
        }
        self::$rememberCookieRefreshed = false;
    }

    private static function refreshRememberCookieFromSession()
    {
        if (!isset($_SESSION[self::CPMS_USER_KEY]) || !is_array($_SESSION[self::CPMS_USER_KEY])) return;
        $email = isset($_SESSION[self::CPMS_USER_KEY]['email']) ? trim((string)$_SESSION[self::CPMS_USER_KEY]['email']) : '';
        if ($email !== '') self::issueRememberCookie($email);
    }

    private static function autoLoginFromRememberCookie()
    {
        $raw = isset($_COOKIE[self::CPMS_REMEMBER_COOKIE]) ? (string)$_COOKIE[self::CPMS_REMEMBER_COOKIE] : '';
        if ($raw === '') return false;

        $decoded = base64_decode($raw, true);
        if (!is_string($decoded) || $decoded === '') {
            self::clearRememberCookie();
            return false;
        }

        $parts = explode('|', $decoded);
        if (count($parts) !== 3 && count($parts) !== 4) {
            self::clearRememberCookie();
            return false;
        }

        $email = self::normalizeEmail($parts[0]);
        $isLegacyCookie = count($parts) === 4;
        $savedSessionId = $isLegacyCookie ? (string)$parts[1] : '';
        $expiresAt = (int)($isLegacyCookie ? $parts[2] : $parts[1]);
        $signature = (string)($isLegacyCookie ? $parts[3] : $parts[2]);
        if ($email === '' || $expiresAt < time()) {
            self::clearRememberCookie();
            return false;
        }

        $secret = self::rememberSecret();
        if ($secret === '') return false;

        // 기존 PC 로그인 쿠키도 만료 전까지 계속 허용하고, 복구 성공 시
        // 세션 ID에 종속되지 않는 새 형식으로 즉시 갱신한다.
        $data = $isLegacyCookie
            ? $email . '|' . $savedSessionId . '|' . $expiresAt
            : $email . '|' . $expiresAt;
        $expected = hash_hmac('sha256', $data, $secret);
        if (!self::hashEquals($expected, $signature)) {
            self::clearRememberCookie();
            return false;
        }

        $_SESSION['user_email'] = $email;
        if (self::loadFromEmployeesByEmail($email, true)) {
            self::issueRememberCookie($email);
            return true;
        }

        self::clearRememberCookie();
        return false;
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

        $dept = '';
        if (isset($_SESSION[self::CPMS_USER_KEY]) && is_array($_SESSION[self::CPMS_USER_KEY]) && isset($_SESSION[self::CPMS_USER_KEY]['department'])) {
            $dept = self::normalizeDept($_SESSION[self::CPMS_USER_KEY]['department']);
        }
        if ($dept === '개발') return true;

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
        );
    }

    // Accounts granted construction-section view access without changing employee dept.
    private static function constructionAccessEmails()
    {
        return array(
            'kimyounggi@cmbuild.kr',
        );
    }

    public static function check()
    {
        // 세션 없으면 포탈 기반 자동로그인 시도 (요청한 자동로그인 유지)
        if (!isset($_SESSION[self::CPMS_USER_KEY]) || !is_array($_SESSION[self::CPMS_USER_KEY])) {
            self::autoLoginFromPortal();
        }
        if (!isset($_SESSION[self::CPMS_USER_KEY]) || !is_array($_SESSION[self::CPMS_USER_KEY])) {
            self::autoLoginFromRememberCookie();
        }
        if (!self::$sessionRefreshed && isset($_SESSION[self::CPMS_USER_KEY]) && is_array($_SESSION[self::CPMS_USER_KEY])) {
            self::$sessionRefreshed = true;
            $email = isset($_SESSION[self::CPMS_USER_KEY]['email']) ? trim((string)$_SESSION[self::CPMS_USER_KEY]['email']) : '';
            if ($email !== '') self::loadFromEmployeesByEmail($email, false);
        }
        if (isset($_SESSION[self::CPMS_USER_KEY]) && is_array($_SESSION[self::CPMS_USER_KEY])) {
            self::refreshRememberCookieFromSession();
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

    public static function userStoredRole()
    {
        $u = self::user();
        if ($u && isset($u['employee_role']) && trim((string)$u['employee_role']) !== '') {
            return (string)$u['employee_role'];
        }
        return $u && isset($u['role']) ? (string)$u['role'] : 'employee';
    }

    // ★ 부서
    public static function userDepartment()
    {
        $u = self::user();
        return $u && isset($u['department']) ? (string)$u['department'] : '';
    }

    public static function isDevelopmentDepartment()
    {
        if (!self::check()) return false;
        return self::normalizeDept(self::userDepartment()) === '개발';
    }

    public static function normalizeDepartmentValue($department)
    {
        return self::normalizeDept($department);
    }

    public static function canSwitchDashboardViews()
    {
        return self::isDevelopmentDepartment();
    }

    /**
     * 사용현황 분석 전용 접근 권한.
     *
     * 기존 executive/마스터/직원명부 관리 권한을 재사용하지 않고,
     * 대표·대표이사·부사장 또는 개발부서만 정확하게 허용합니다.
     */
    public static function canAccessUsageAnalytics()
    {
        if (!self::check()) return false;
        if (self::isDevelopmentDepartment()) return true;

        $allowedValues = array('대표', '대표이사', '부사장');
        $userValues = array(self::userPosition(), self::userStoredRole());

        foreach ($userValues as $userValue) {
            $normalizedValue = self::normalizeText($userValue);
            if ($normalizedValue === '') continue;

            foreach ($allowedValues as $allowedValue) {
                if ($normalizedValue === self::normalizeText($allowedValue)) return true;
            }
        }

        return false;
    }

    /**
     * 모바일 공무 메뉴 접근 권한.
     *
     * 공무/개발 부서와 대표·대표이사·부사장만 허용합니다.
     */
    public static function canAccessPublicAffairsMobile()
    {
        if (!self::check()) return false;

        $dept = self::normalizeDept(self::userDepartment());
        if ($dept === '공무' || $dept === '개발') return true;

        $allowedValues = array('대표', '대표이사', '대표님', '부사장');
        $userValues = array(self::userPosition(), self::userStoredRole());

        foreach ($userValues as $userValue) {
            $normalizedValue = self::normalizeText($userValue);
            if ($normalizedValue === '') continue;

            foreach ($allowedValues as $allowedValue) {
                if ($normalizedValue === self::normalizeText($allowedValue)) return true;
            }
        }

        return false;
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

        $dept = self::normalizeDept(self::userDepartment());
        // 기존 데이터(관리부) + 신규 데이터(관리) 모두 허용
        return ($dept === '관리');
    }

    // ★ 월급 설정 가능: 임원 OR 관리(관리부)
    public static function canManageSalary()
    {
        if (self::isMaster()) return true;        
        return self::canManageEmployees();
    }

    public static function canAssignDevelopmentDepartment()
    {
        if (!self::check()) return false;

        $dept = self::normalizeDept(self::userDepartment());
        if ($dept === '관리') return true;

        $values = array(self::userRole(), self::userPosition(), self::userName());
        $words = array('대표', '대표이사', '대표님', '부사장');
        foreach ($values as $value) {
            $valueNorm = self::normalizeText($value);
            if ($valueNorm === '') continue;
            foreach ($words as $word) {
                $wordNorm = self::normalizeText($word);
                if ($wordNorm !== '' && strpos($valueNorm, $wordNorm) !== false) return true;
            }
        }

        return false;
    }

    public static function canAssignExecutiveRole()
    {
        if (!self::check()) return false;
        if (self::isMaster()) return true;
        $dept = self::normalizeDept(self::userDepartment());
        if ($dept === '관리') return true;
        return self::canAssignDevelopmentDepartment();
    }

    
    // 마스터 전체 권한: 공사 섹션 접근
    public static function canAccessConstruction()
    {
        if (!self::check()) return false;
        if (self::isMaster()) return true; // 마스터 전체 권한

        $email = self::normalizeEmail(self::userEmail());
        foreach (self::constructionAccessEmails() as $allowedEmail) {
            if ($email !== '' && $email === self::normalizeEmail($allowedEmail)) return true;
        }

        $role = self::userRole();
        $dept = self::normalizeDept(self::userDepartment());
        return ($role === 'executive' || $dept === '공사' || $dept === '공무' || $dept === '관리');
    }

    // 마스터 전체 권한: 공사 저장/수정/삭제
    public static function canManageConstruction()
    {
        if (!self::check()) return false;
        if (self::isMaster()) return true;

        $role = self::userRole();
        $dept = self::normalizeDept(self::userDepartment());
        return ($role === 'executive' || $dept === '공사' || $dept === '공무');
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
        self::clearRememberCookie();
    }

    public static function loginFromEmployeeId($employeeId)
    {
        $employeeId = (int)$employeeId;
        if ($employeeId <= 0) return false;

        $pdo = Db::pdo();
        if (!$pdo) return false;

        try {
            $st = $pdo->prepare("SELECT email, is_active FROM employees WHERE id = :id LIMIT 1");
            $st->bindValue(':id', $employeeId);
            $st->execute();
            $row = $st->fetch();
            if (!is_array($row)) return false;
            if (isset($row['is_active']) && (int)$row['is_active'] !== 1) return false;

            $email = isset($row['email']) ? self::normalizeEmail($row['email']) : '';
            if ($email === '') return false;

            $_SESSION['user_email'] = $email;
            $_SESSION[self::CPMS_USER_KEY] = array(
                'email'      => $email,
                'name'       => $email,
                'role'       => 'employee',
                'photo_path' => null,
                'department' => '',
                'position'   => '',
                'id'         => $employeeId,
            );

            if (!self::loadFromEmployeesByEmail($email, true)) return false;
            self::refreshRememberCookieFromSession();
            return true;
        } catch (\Exception $e) {
            return false;
        }
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
            self::refreshRememberCookieFromSession();
            return true;
        }

        $portalEmail = isset($_SESSION['user_email']) ? trim((string)$_SESSION['user_email']) : '';
        if ($portalEmail === '') return self::autoLoginFromRememberCookie();

        // 기본 세션 먼저 생성
        $_SESSION['user_email'] = $portalEmail;
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
        self::refreshRememberCookieFromSession();
        return true;
    }

    private static function normalizeDept($dept)
    {
        $dept = trim((string)$dept);
        $map = array(
            '관리부' => '관리',
            '관리팀' => '관리',
            '관리부서' => '관리',
            '공무부' => '공무',
            '공무팀' => '공무',
            '공무부서' => '공무',
            '품질부' => '품질',
            '안전부' => '안전',
            '공사부' => '공사',
            '공사팀' => '공사',
            '개발부' => '개발',
            '개발팀' => '개발',
            '개발부서' => '개발',
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
        $storedRole = 'employee';
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

        $dept = self::normalizeDept($dept);
        $storedRole = $role;

        // 개발부서 권한: executive로 강제
        $normalizedMasterEmails = array();
        foreach (self::masterEmails() as $masterEmail) {
            $normalizedMasterEmails[] = self::normalizeEmail($masterEmail);
        }

        if (in_array($email, $normalizedMasterEmails, true) || $dept === '개발') {
            $role = 'executive';
        }

        // role fallback
        if ($role !== 'executive') {
            if (in_array($email, self::executiveEmails(), true)) $role = 'executive';
        }

        // 세션 반영
        if (!isset($_SESSION[self::CPMS_USER_KEY]) || !is_array($_SESSION[self::CPMS_USER_KEY])) {
            $_SESSION[self::CPMS_USER_KEY] = array();
        }

        $_SESSION[self::CPMS_USER_KEY]['email'] = $email;
        $_SESSION[self::CPMS_USER_KEY]['name'] = ($name !== '' ? $name : $email);
        $_SESSION[self::CPMS_USER_KEY]['role'] = $role;
        $_SESSION[self::CPMS_USER_KEY]['employee_role'] = $storedRole;
        $_SESSION[self::CPMS_USER_KEY]['photo_path'] = $photo;
        $_SESSION[self::CPMS_USER_KEY]['department'] = $dept;
        $_SESSION[self::CPMS_USER_KEY]['position'] = $pos;
        $_SESSION[self::CPMS_USER_KEY]['id'] = $employeeId;
        
        return true;
    }
}
