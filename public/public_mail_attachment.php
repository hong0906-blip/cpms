<?php
/**
 * 파일 경로: C:\www\cpms\public\public_mail_attachment.php
 *
 * 네이버 일반/대용량 첨부파일을 CPMS 서버 디스크에 저장하지 않고
 * 브라우저로 바로 스트리밍합니다. PHP 5.6 호환 코드입니다.
 */
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/services/PublicMailService.php';
require_once __DIR__ . '/../app/services/PublicMailWebHelper.php';

use App\Services\PublicMailService;
use App\Services\PublicMailWebHelper;

PublicMailWebHelper::requireLogin();
$messageKey=isset($_GET['message'])?trim((string)$_GET['message']):'';
if ($messageKey==='' && isset($_GET['uid']) && (int)$_GET['uid']>0) $messageKey=(string)(int)$_GET['uid'];
$part=isset($_GET['part'])?trim((string)$_GET['part']):'';
if (function_exists('session_write_close')) @session_write_close();
@set_time_limit(0);
@ignore_user_abort(true);

try {
    $service=new PublicMailService();
    $attachment=$service->getAttachmentDescriptor($messageKey,$part,false);
    $filename=isset($attachment['filename'])?(string)$attachment['filename']:'attachment.bin';
    $mime=isset($attachment['mime_type'])?(string)$attachment['mime_type']:'application/octet-stream';
    $size=isset($attachment['size'])?(int)$attachment['size']:0;

    // 네이버 대용량 첨부는 브라우저가 네이버 파일서버에서 직접 받게 합니다.
    // CPMS 서버의 디스크, 메모리, 전송시간 제한을 거치지 않으므로 대용량에 가장 안전합니다.
    if (!empty($attachment['is_large']) && !empty($attachment['source_url'])) {
        while (ob_get_level()>0) @ob_end_clean();
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('Location: '.(string)$attachment['source_url'], true, 302);
        exit;
    }

    while (ob_get_level()>0) @ob_end_clean();
    header('Content-Type: '.$mime);
    if ($size>0) header('Content-Length: '.$size);
    header('Content-Disposition: attachment; filename="download.bin"; filename*=UTF-8\'\''.rawurlencode($filename));
    header('X-Content-Type-Options: nosniff');
    header('X-Accel-Buffering: no');
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $service->streamAttachment($messageKey,$part,function($chunk){
        echo $chunk;
        if (function_exists('ob_flush')) @ob_flush();
        @flush();
    });
    exit;
} catch (Exception $e) {
    while (ob_get_level()>0) @ob_end_clean();
    if (!headers_sent()) {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo '<!doctype html><html lang="ko"><meta charset="utf-8"><body style="font-family:sans-serif;padding:20px;color:#b91c1c">첨부파일 다운로드 실패: '.htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8').'</body></html>';
    }
    exit;
}
