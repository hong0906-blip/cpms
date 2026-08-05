<?php
/**
 * 파일 경로: C:\www\cpms\public\cron\naver_mail_sync.php
 *
 * 호스팅업체 CRON/예약 URL 또는 외부 웹 스케줄러가 호출하는 보안 동기화 주소입니다.
 * 브라우저 로그인과 Windows 작업 스케줄러가 필요하지 않습니다.
 * PHP 5.6 호환 코드입니다.
 */
require_once dirname(dirname(__DIR__)) . '/app/bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/app/services/PublicMailService.php';
require_once dirname(dirname(__DIR__)) . '/app/services/PublicMailStorageService.php';

use App\Services\PublicMailService;
use App\Services\PublicMailStorageService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
if (function_exists('session_write_close')) @session_write_close();
@set_time_limit(180);

$key = isset($_GET['key']) ? trim((string)$_GET['key']) : '';
$service = new PublicMailService();
if (!$service->verifyCronToken($key)) {
    http_response_code(403);
    echo json_encode(array('ok'=>false,'message'=>'Forbidden'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $result = $service->runAutomationTick(50);
    $message = isset($result['message']) ? (string)$result['message'] : '완료';
    PublicMailStorageService::saveSyncState(array(
        'last_cron_at' => date('Y-m-d H:i:s'),
        'last_cron_result' => $message
    ));
    $result['cron_at'] = date('Y-m-d H:i:s');
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Exception $e) {
    PublicMailStorageService::saveSyncState(array(
        'last_cron_at' => date('Y-m-d H:i:s'),
        'last_cron_result' => '오류: ' . $e->getMessage()
    ));
    http_response_code(500);
    echo json_encode(array('ok'=>false,'message'=>$e->getMessage()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
