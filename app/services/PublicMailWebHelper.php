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
    private static $jsonRequestActive = false;
    private static $jsonResponseSent = false;
    private static $jsonShutdownRegistered = false;
    public static function requireLogin()
    {
        if (!class_exists('\\App\\Core\\Auth') || !\App\Core\Auth::check()) {
            header('Location: index.php?r=login');
            exit;
        }
    }

    /**
     * 기존 호환용 최고관리자 권한 확인입니다.
     * 네이버 메일 연동 설정은 v1.7.8부터 requireDevelopmentDepartment()를 사용합니다.
     */
    public static function requireAdmin()
    {
        self::requireLogin();

        $allowed = false;
        try {
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

    /**
     * 로그인 사용자가 개발부서 소속인지 확인합니다.
     * CPMS Auth의 공식 부서 판정 메서드를 우선 사용하고,
     * 오래된 서버에서는 사용자 부서명으로 안전하게 보완 판정합니다.
     */
    public static function isDevelopmentDepartment()
    {
        self::requireLogin();

        try {
            if (method_exists('\\App\\Core\\Auth', 'isDevelopmentDepartment')) {
                return (bool)\App\Core\Auth::isDevelopmentDepartment();
            }

            $user = \App\Core\Auth::user();
            $department = is_array($user) && isset($user['department'])
                ? trim((string)$user['department'])
                : '';
            $normalized = preg_replace('/\s+/u', '', $department);
            return in_array($normalized, array('개발부서', '개발부', '개발팀', '개발'), true);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 네이버 메일 연동정보와 자동수집 설정은 개발부서만 접근할 수 있습니다.
     */
    public static function requireDevelopmentDepartment()
    {
        self::requireLogin();
        if (!self::isDevelopmentDepartment()) {
            http_response_code(403);
            echo '네이버 메일 연동 설정은 개발부서만 접근할 수 있습니다.';
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

    /**
     * AJAX 요청이 PHP 경고문이나 치명적 오류 HTML로 오염되지 않도록 JSON 전용 종료 처리를 등록합니다.
     */
    public static function beginJsonRequest()
    {
        self::$jsonRequestActive = true;
        if (!self::$jsonShutdownRegistered) {
            self::$jsonShutdownRegistered = true;
            register_shutdown_function(array(__CLASS__, 'handleJsonShutdown'));
        }
    }

    /**
     * PHP 5.6에서 처리 중 치명적 오류가 발생해도 브라우저에는 항상 JSON을 반환합니다.
     */
    public static function handleJsonShutdown()
    {
        if (!self::$jsonRequestActive || self::$jsonResponseSent) return;

        $error = error_get_last();
        $fatalTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
        if (!is_array($error) || !in_array(isset($error['type']) ? (int)$error['type'] : 0, $fatalTypes, true)) return;

        self::$jsonResponseSent = true;
        $logMessage = '[CPMS Public Mail] fatal JSON request error';
        if (isset($error['message'])) $logMessage .= ': ' . self::cleanUtf8String((string)$error['message']);
        if (isset($error['file'])) $logMessage .= ' in ' . (string)$error['file'];
        if (isset($error['line'])) $logMessage .= ':' . (int)$error['line'];
        @error_log($logMessage);

        while (ob_get_level() > 0) @ob_end_clean();
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('X-Content-Type-Options: nosniff');
        }

        echo self::encodeJsonSafely(array(
            'ok' => false,
            'retryable' => true,
            'error_code' => 'server_fatal',
            'message' => '서버 처리 중 오류가 발생했습니다. 저장된 위치부터 자동으로 다시 시도합니다.'
        ));
    }

    /**
     * 대량 복구 상태에서 브라우저에 필요 없는 대상키와 재시도 목록을 제거합니다.
     */
    public static function compactSyncState($state)
    {
        if (!is_array($state)) return array();
        $state = self::sanitizeUtf8Value($state);
        if (isset($state['mailboxes'])) unset($state['mailboxes']);
        if (isset($state['metadata_repair']) && is_array($state['metadata_repair'])) {
            unset($state['metadata_repair']['target_keys']);
            unset($state['metadata_repair']['message_attempts']);
        }
        return $state;
    }

    public static function sanitizeUtf8Value($value)
    {
        if (is_string($value)) return self::cleanUtf8String($value);
        if (is_array($value)) {
            $clean = array();
            foreach ($value as $key => $item) {
                $cleanKey = is_string($key) ? self::cleanUtf8String($key) : $key;
                $clean[$cleanKey] = self::sanitizeUtf8Value($item);
            }
            return $clean;
        }
        if (is_object($value)) return self::sanitizeUtf8Value(get_object_vars($value));
        return $value;
    }

    private static function cleanUtf8String($value)
    {
        $value = (string)$value;
        if ($value === '') return '';
        if (function_exists('mb_check_encoding') && @mb_check_encoding($value, 'UTF-8')) return $value;
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
            if ($converted !== false && $converted !== '') return $converted;
        }

        $result = '';
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $b1 = ord($value[$i]);
            if ($b1 <= 0x7F) {
                if ($b1 === 9 || $b1 === 10 || $b1 === 13 || $b1 >= 32) $result .= $value[$i];
                continue;
            }
            $seqLength = 0;
            if ($b1 >= 0xC2 && $b1 <= 0xDF) $seqLength = 2;
            elseif ($b1 >= 0xE0 && $b1 <= 0xEF) $seqLength = 3;
            elseif ($b1 >= 0xF0 && $b1 <= 0xF4) $seqLength = 4;
            if ($seqLength === 0 || ($i + $seqLength) > $length) continue;

            $valid = true;
            for ($j = 1; $j < $seqLength; $j++) {
                $bj = ord($value[$i + $j]);
                if ($bj < 0x80 || $bj > 0xBF) { $valid = false; break; }
            }
            if ($valid && $seqLength === 3) {
                $b2 = ord($value[$i + 1]);
                if (($b1 === 0xE0 && $b2 < 0xA0) || ($b1 === 0xED && $b2 > 0x9F)) $valid = false;
            }
            if ($valid && $seqLength === 4) {
                $b2 = ord($value[$i + 1]);
                if (($b1 === 0xF0 && $b2 < 0x90) || ($b1 === 0xF4 && $b2 > 0x8F)) $valid = false;
            }
            if ($valid) {
                $result .= substr($value, $i, $seqLength);
                $i += $seqLength - 1;
            }
        }
        return $result;
    }

    private static function encodeJsonSafely($data)
    {
        $clean = self::sanitizeUtf8Value($data);
        $json = json_encode($clean, JSON_UNESCAPED_UNICODE);
        if ($json === false) $json = json_encode($clean);
        if ($json === false) {
            $json = '{"ok":false,"retryable":true,"error_code":"json_encode_failed","message":"서버 응답을 안전하게 만들지 못했습니다. 자동으로 다시 시도합니다."}';
        }
        return $json;
    }

    public static function jsonResponse($data, $statusCode)
    {
        self::beginJsonRequest();
        self::$jsonResponseSent = true;

        if (is_array($data) && isset($data['state'])) {
            $data['state'] = self::compactSyncState($data['state']);
        }

        while (ob_get_level() > 0) @ob_end_clean();
        http_response_code((int)$statusCode);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('X-Content-Type-Options: nosniff');
        echo self::encodeJsonSafely($data);
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
