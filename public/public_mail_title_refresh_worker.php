<?php
/**
 * 파일 경로: C:\www\cpms\public\public_mail_title_refresh_worker.php
 *
 * mailing@businesson.co.kr 메일 중 깨진 제목만 한 번에 1건 읽고 이중 인코딩 후보까지 비교하는 전용 작업자입니다.
 * 설정 화면은 그대로 유지하고, 요청이 끊겨도 저장된 위치부터 다시 시도합니다.
 * PHP 5.6 호환 코드입니다.
 * CPMS_PUBLIC_MAIL_VERSION: 1.7.17
 */

@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
@ini_set('memory_limit', '128M');
@set_time_limit(12);
@ignore_user_abort(true);
if (ob_get_level() === 0) @ob_start();

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/services/PublicMailService.php';
require_once __DIR__ . '/../app/services/PublicMailWebHelper.php';

use App\Services\PublicMailService;
use App\Services\PublicMailWebHelper;

PublicMailWebHelper::beginJsonRequest();

try {
    if (!class_exists('\\App\\Core\\Auth') || !\App\Core\Auth::check()) {
        PublicMailWebHelper::jsonResponse(array(
            'ok'=>false,
            'retryable'=>false,
            'error_code'=>'session_expired',
            'message'=>'로그인이 만료되었습니다. 페이지를 새로고침해 다시 로그인해 주세요.'
        ), 401);
    }

    if (!PublicMailWebHelper::isDevelopmentDepartment()) {
        PublicMailWebHelper::jsonResponse(array(
            'ok'=>false,
            'retryable'=>false,
            'error_code'=>'forbidden',
            'message'=>'네이버 메일 연동 설정은 개발부서만 사용할 수 있습니다.'
        ), 403);
    }

    $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : 'GET';
    if ($method !== 'POST') {
        PublicMailWebHelper::jsonResponse(array(
            'ok'=>false,
            'retryable'=>false,
            'error_code'=>'method_not_allowed',
            'message'=>'허용되지 않은 요청입니다.'
        ), 405);
    }

    $csrf = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
    $csrfValid = PublicMailWebHelper::verifyCsrf($csrf);
    if (!$csrfValid && function_exists('csrf_check')) $csrfValid = csrf_check($csrf);
    if (!$csrfValid) {
        PublicMailWebHelper::jsonResponse(array(
            'ok'=>false,
            'retryable'=>false,
            'error_code'=>'csrf_failed',
            'message'=>'보안 확인값이 올바르지 않습니다. 페이지를 새로고침하세요.'
        ), 419);
    }

    /*
     * 네이버 연결 동안 PHP 세션 잠금을 풀어 다른 CPMS 화면 사용을 막지 않습니다.
     */
    if (function_exists('session_write_close')) @session_write_close();

    $limit = 1;
    $service = new PublicMailService();
    $result = $service->processOriginalTitleRefreshWorkerStep($limit);
    $status = !empty($result['ok']) ? 200 : 503;
    PublicMailWebHelper::jsonResponse($result, $status);
} catch (Exception $e) {
    @error_log('[CPMS Public Mail title worker] ' . $e->getMessage());
    PublicMailWebHelper::jsonResponse(array(
        'ok'=>false,
        'retryable'=>true,
        'retry_after'=>10,
        'error_code'=>'worker_exception',
        'message'=>'비즈니스온 깨진 제목 1건 처리 중 오류가 발생했습니다. 다음 요청에서 해당 1건만 건너뛰고 계속합니다.'
    ), 503);
}
