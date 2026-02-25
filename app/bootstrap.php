<?php
/**
 * C:\www\cpms\app\bootstrap.php
 * - CPMS 공통 부트스트랩
 * - 포탈과 세션 공유를 위해 session_name('CMSESSID') 및 쿠키 옵션 통일
 *
 * PHP 5.6 호환
 */

// ===== 포탈과 공통 세션 설정 (반드시 session_start() 전에!) =====
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('CMSESSID');

    // ==========================
    //  세션 만료(자동로그아웃) 방지 설정
    //  - 세션 파일이 너무 빨리 정리(gc)되면 "조금 안 쓰면 로그인으로 돌아감" 현상이 발생
    // ==========================
    $keepSeconds = 60 * 60 * 24 * 30; // 30일
    ini_set('session.gc_maxlifetime', (string)$keepSeconds);
    ini_set('session.cookie_lifetime', '0'); // 브라우저 닫기 전까지 유지(포탈과 맞춤)

    // ==========================
    //  쿠키 옵션(환경별 안전 처리)
    //  - 로컬(HTTP)에서 cookie_secure=1 이면 쿠키가 안 잡혀서 로그인/세션이 깨질 수 있음
    //  - cmbuild.kr 도메인에서만 cookie_domain을 고정
    // ==========================
    $host = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : '';
    $host = preg_replace('/:\\d+$/', '', $host); // 포트 제거

    if ($host !== '' && (substr($host, -9) === 'cmbuild.kr')) {
        ini_set('session.cookie_domain', 'cmbuild.kr');
    }

    ini_set('session.cookie_path', '/');

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    ini_set('session.cookie_secure', $isHttps ? '1' : '0');
    ini_set('session.cookie_httponly', '1');

    session_start();
}


// ===== 공통 헬퍼 =====
require_once __DIR__ . '/helpers.php';

// ===== 간단 유틸 =====
if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/**
 * ✅ base_url()
 * 예) /cpms/public
 * - 공무 페이지에서 링크 만들 때 필요
 */
if (!function_exists('base_url')) {
    function base_url() {
        $scriptName = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
        $dir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
        return $dir === '' ? '' : $dir;
    }
}

if (!function_exists('asset_url')) {
    function asset_url($path) {
        $path = ltrim((string)$path, '/');
        // 기존 동작 유지 (기존 코드 영향 최소화)
        return '/cpms/public/' . $path;
    }
}

// ===== CSRF =====
if (!function_exists('csrf_token')) {
    function csrf_token() {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(openssl_random_pseudo_bytes(16));
        }
        return $_SESSION['_csrf'];
    }
}
if (!function_exists('csrf_check')) {
    function csrf_check($token) {
        return !empty($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], (string)$token);
    }
}

// ===== Flash =====
if (!function_exists('flash_set')) {
    function flash_set($type, $message) {
        $_SESSION['_flash'] = array('type' => (string)$type, 'message' => (string)$message);
    }
}
if (!function_exists('flash_get')) {
    function flash_get() {
        if (empty($_SESSION['_flash'])) return null;
        $v = $_SESSION['_flash'];
        unset($_SESSION['_flash']);
        return $v;
    }
}

// ===== 클래스 로드(간단 수동 로드) =====
// ※ 중요: Auth.php 안에서 Db 클래스를 사용하므로, Db.php를 먼저 로드해야 500이 안 뜸
require_once __DIR__ . '/core/Db.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/View.php';

// ===== 포탈 세션 기반 자동로그인 =====
\App\Core\Auth::autoLoginFromPortal();