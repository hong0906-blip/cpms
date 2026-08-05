<?php
/**
 * 파일 경로: C:\www\cpms\app\services\PublicMailWebHelper.php
 *
 * 공용메일 화면의 로그인, 권한, CSRF, 응답 처리를 돕습니다.
 * PHP 5.6 호환 코드입니다.
 */

namespace App\Services;

class PublicMailWebHelper
{
    public static function requireLogin()
    {
        if (!class_exists('\\App\\Core\\Auth') || !\App\Core\Auth::check()) {
            header('Location: index.php?r=login');
            exit;
        }
    }

    public static function requireAdmin()
    {
        self::requireLogin();

        $allowed = false;
        try {
            // 공용메일 계정정보와 애플리케이션 비밀번호는
            // 최고관리자만 변경할 수 있도록 제한합니다.
            $allowed = \App\Core\Auth::isMaster();
        } catch (\Exception $e) {
            $allowed = false;
        }

        if (!$allowed) {
            http_response_code(403);
            echo '공용메일 설정 권한이 없습니다.';
            exit;
        }
    }

    public static function currentUserName()
    {
        try {
            if (method_exists('\\App\\Core\\Auth', 'userName')) {
                return (string)\App\Core\Auth::userName();
            }
            $user = \App\Core\Auth::user();
            return is_array($user) && isset($user['name']) ? (string)$user['name'] : '사용자';
        } catch (\Exception $e) {
            return '사용자';
        }
    }

    public static function currentUserEmail()
    {
        try {
            if (method_exists('\\App\\Core\\Auth', 'userEmail')) {
                return (string)\App\Core\Auth::userEmail();
            }
            $user = \App\Core\Auth::user();
            return is_array($user) && isset($user['email']) ? (string)$user['email'] : '';
        } catch (\Exception $e) {
            return '';
        }
    }

    public static function csrfToken()
    {
        if (!isset($_SESSION['public_mail_csrf']) || strlen((string)$_SESSION['public_mail_csrf']) < 40) {
            $strong = false;
            $bytes = openssl_random_pseudo_bytes(32, $strong);
            if ($bytes === false) {
                $bytes = uniqid('', true) . mt_rand();
            }
            $_SESSION['public_mail_csrf'] = hash('sha256', $bytes . session_id());
        }

        return (string)$_SESSION['public_mail_csrf'];
    }

    public static function verifyCsrf($token)
    {
        $expected = isset($_SESSION['public_mail_csrf']) ? (string)$_SESSION['public_mail_csrf'] : '';
        $token = (string)$token;

        if ($expected === '' || $token === '') {
            return false;
        }

        return function_exists('hash_equals') ? hash_equals($expected, $token) : ($expected === $token);
    }

    public static function isAjax()
    {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            return true;
        }
        return isset($_POST['response_type']) && (string)$_POST['response_type'] === 'json';
    }

    public static function jsonResponse($data, $statusCode)
    {
        http_response_code((int)$statusCode);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function redirectWithMessage($url, $type, $message)
    {
        $_SESSION['public_mail_flash'] = array(
            'type' => (string)$type,
            'message' => (string)$message
        );
        header('Location: ' . $url);
        exit;
    }

    public static function pullFlash()
    {
        $flash = isset($_SESSION['public_mail_flash']) && is_array($_SESSION['public_mail_flash'])
            ? $_SESSION['public_mail_flash']
            : null;
        unset($_SESSION['public_mail_flash']);
        return $flash;
    }

    public static function render($view, $data)
    {
        if (class_exists('\\App\\Core\\View')) {
            \App\Core\View::render($view, $data);
            return;
        }

        extract($data, EXTR_SKIP);
        $viewPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $view) . '.php';
        require $viewPath;
    }

    public static function h($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}
