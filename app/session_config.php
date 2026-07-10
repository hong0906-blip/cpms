<?php
/**
 * Shared CM-BUILD portal/CPMS session configuration.
 * PHP 5.6 compatible.
 */

if (!defined('CPMS_SESSION_KEEP_SECONDS')) {
    define('CPMS_SESSION_KEEP_SECONDS', 60 * 60 * 14);
}

if (!function_exists('cpms_shared_session_is_active')) {
function cpms_shared_session_is_active() {
    if (function_exists('session_status')) {
        return session_status() === PHP_SESSION_ACTIVE;
    }
    return session_id() !== '';
}
}

if (!function_exists('cpms_shared_session_is_https')) {
function cpms_shared_session_is_https() {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) return true;
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') return true;
    return false;
}
}

if (!function_exists('cpms_shared_session_cookie_domain')) {
function cpms_shared_session_cookie_domain() {
    $host = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : '';
    $host = preg_replace('/:\\d+$/', '', $host);
    $host = strtolower($host);
    $baseCookieDomain = 'cmbuild.kr';
    if ($host === $baseCookieDomain || substr($host, -1 * (strlen($baseCookieDomain) + 1)) === '.' . $baseCookieDomain) {
        return $baseCookieDomain;
    }
    return '';
}
}

if (!function_exists('cpms_shared_session_start')) {
function cpms_shared_session_start() {
    if (cpms_shared_session_is_active()) return;

    $keepSeconds = (int)CPMS_SESSION_KEEP_SECONDS;
    if ($keepSeconds <= 0) $keepSeconds = 60 * 60 * 14;

    session_name('CMSESSID');
    ini_set('session.gc_maxlifetime', (string)$keepSeconds);
    ini_set('session.cookie_lifetime', (string)$keepSeconds);
    ini_set('session.cookie_path', '/');

    $cookieDomain = cpms_shared_session_cookie_domain();
    if ($cookieDomain !== '') {
        ini_set('session.cookie_domain', $cookieDomain);
    }

    $isHttps = cpms_shared_session_is_https();
    ini_set('session.cookie_secure', $isHttps ? '1' : '0');
    ini_set('session.cookie_httponly', '1');

    session_start();

    if (!headers_sent() && session_id() !== '') {
        @setcookie(session_name(), session_id(), time() + $keepSeconds, '/', $cookieDomain, $isHttps, true);
    }
}
}

if (!function_exists('cpms_shared_safe_redirect_url')) {
function cpms_shared_safe_redirect_url($url, $fallback) {
    $url = trim((string)$url);
    $fallback = trim((string)$fallback);
    if ($fallback === '') $fallback = '/cpms/public/?r=dashboard_employee';
    if ($url === '' || preg_match('/[\r\n]/', $url)) return $fallback;

    if (preg_match('/^https?:\/\//i', $url)) {
        $host = isset($_SERVER['HTTP_HOST']) ? strtolower((string)$_SERVER['HTTP_HOST']) : 'cmbuild.kr';
        $host = preg_replace('/:\\d+$/', '', $host);
        $parts = @parse_url($url);
        $urlHost = is_array($parts) && isset($parts['host']) ? strtolower((string)$parts['host']) : '';
        if ($urlHost === '' || $urlHost !== $host) return $fallback;
        return $url;
    }

    if (substr($url, 0, 1) === '/' || substr($url, 0, 1) === '?') {
        return $url;
    }

    return $fallback;
}
}

if (!function_exists('cpms_shared_dashboard_url')) {
function cpms_shared_dashboard_url($role) {
    $route = ((string)$role === 'executive') ? 'dashboard_executive' : 'dashboard_employee';
    return 'https://cmbuild.kr/cpms/public/?r=' . $route;
}
}

if (!function_exists('cpms_shared_is_portal_entry_url')) {
function cpms_shared_is_portal_entry_url($url) {
    $url = trim((string)$url);
    if ($url === '') return false;

    $query = '';
    if (substr($url, 0, 1) === '?') {
        $query = substr($url, 1);
    } else {
        $parts = @parse_url($url);
        if (is_array($parts) && isset($parts['query'])) {
            $query = (string)$parts['query'];
        }
    }
    if ($query === '') return false;

    $params = array();
    parse_str($query, $params);
    return isset($params['r']) && (string)$params['r'] === 'portal_entry';
}
}

if (!function_exists('cpms_shared_random_hex')) {
function cpms_shared_random_hex($bytesLength) {
    $bytesLength = (int)$bytesLength;
    if ($bytesLength <= 0) $bytesLength = 16;

    $bytes = false;
    if (function_exists('openssl_random_pseudo_bytes')) {
        $strong = false;
        $bytes = @openssl_random_pseudo_bytes($bytesLength, $strong);
    }
    if ($bytes === false || strlen($bytes) < $bytesLength) {
        $bytes = uniqid('', true) . '|' . mt_rand() . '|' . microtime(true);
    }

    return substr(hash('sha256', $bytes), 0, $bytesLength * 2);
}
}
