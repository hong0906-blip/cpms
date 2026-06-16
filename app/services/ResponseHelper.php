<?php
/**
 * C:\www\cpms\app\services\ResponseHelper.php
 * - AJAX JSON 응답을 한 곳에서 처리하는 작은 도우미
 * - PHP 5.6 호환
 */

if (!class_exists('ResponseHelper')) {
class ResponseHelper
{
    public static function json($data, $statusCode)
    {
        if (!headers_sent()) {
            http_response_code((int)$statusCode);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        }

        $options = 0;
        if (defined('JSON_UNESCAPED_UNICODE')) {
            $options = $options | JSON_UNESCAPED_UNICODE;
        }
        echo json_encode($data, $options);
        exit;
    }

    public static function ok($data)
    {
        if (!is_array($data)) $data = array('data' => $data);
        $data['ok'] = 1;
        self::json($data, 200);
    }

    public static function fail($message, $statusCode)
    {
        self::json(array(
            'ok' => 0,
            'message' => (string)$message,
        ), (int)$statusCode);
    }
}
}
