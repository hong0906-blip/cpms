<?php
/**
 * 파일 경로: C:\www\cpms\public\public_mail_attachment.php
 * 필요한 MIME 첨부파일 부분만 가져와 다운로드합니다. PHP 5.6 호환 코드입니다.
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
@set_time_limit(120); @ignore_user_abort(true);

try {
    $service=new PublicMailService(); $attachment=$service->getAttachment($messageKey,$part);
    $filename=isset($attachment['filename'])?(string)$attachment['filename']:'attachment.bin';
    $mime=isset($attachment['mime_type'])?(string)$attachment['mime_type']:'application/octet-stream';
    $content=isset($attachment['content'])?(string)$attachment['content']:'';
    if ($content==='') throw new RuntimeException('첨부파일 내용이 비어 있습니다.');
    while (ob_get_level()>0) @ob_end_clean();
    header('Content-Type: '.$mime);
    header('Content-Length: '.strlen($content));
    header('Content-Disposition: attachment; filename="download.bin"; filename*=UTF-8\'\''.rawurlencode($filename));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, no-store');
    header('Pragma: public');
    echo $content; exit;
} catch (Exception $e) {
    while (ob_get_level()>0) @ob_end_clean();
    http_response_code(404); header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ko"><meta charset="utf-8"><body style="font-family:sans-serif;padding:20px;color:#b91c1c">첨부파일 다운로드 실패: '.htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8').'</body></html>'; exit;
}
