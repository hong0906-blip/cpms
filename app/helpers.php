<?php
function h($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

/**
 * base_url()
 * 예) /cpms/public
 */
function base_url() {
    $scriptName = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
    $dir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    return $dir === '' ? '' : $dir;
}

function asset_url($path) {
    $path = ltrim($path, '/');
    return base_url() . '/' . $path;
}

function csrf_token() {
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(openssl_random_pseudo_bytes(16));
    }
    return $_SESSION['_csrf'];
}

function csrf_check($token) {
    return isset($_SESSION['_csrf']) && is_string($token) && hash_equals($_SESSION['_csrf'], $token);
}

function flash_set($type, $message) {
    $_SESSION['_flash'] = array('type' => $type, 'message' => $message);
}

function flash_get() {
    if (!empty($_SESSION['_flash'])) {
        $f = $_SESSION['_flash'];
        unset($_SESSION['_flash']);
        return $f;
    }
    return null;
}