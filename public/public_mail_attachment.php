<?php
/**
 * 파일 경로: C:\www\cpms\public\public_mail_attachment.php
 *
 * 네이버 일반/대용량 첨부파일을 CPMS 서버 디스크에 저장하지 않고
 * 브라우저로 바로 내려보냅니다. PHP 5.6 호환 코드입니다.
 * CPMS_PUBLIC_MAIL_VERSION: 1.7.14
 */
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/services/PublicMailService.php';
require_once __DIR__ . '/../app/services/PublicMailWebHelper.php';

use App\Services\PublicMailService;
use App\Services\PublicMailWebHelper;

function pm_attachment_ascii_fallback($filename)
{
    $filename = (string)$filename;
    $extension = pathinfo($filename, PATHINFO_EXTENSION);
    $extension = preg_replace('/[^A-Za-z0-9]+/', '', (string)$extension);
    return 'download' . ($extension !== '' ? '.' . strtolower($extension) : '.bin');
}

function pm_attachment_send_download_headers($filename, $mime)
{
    $filename = trim((string)$filename);
    if ($filename === '') $filename = 'attachment.bin';
    $mime = trim((string)$mime);
    if ($mime === '' || preg_match('/[\r\n]/', $mime)) $mime = 'application/octet-stream';

    while (ob_get_level() > 0) @ob_end_clean();
    if (function_exists('header_remove')) {
        @header_remove('Content-Length');
        @header_remove('Content-Encoding');
    }

    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . pm_attachment_ascii_fallback($filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('Content-Transfer-Encoding: binary');
    header('X-Content-Type-Options: nosniff');
    header('X-Download-Options: noopen');
    header('X-Accel-Buffering: no');
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-CPMS-Public-Mail-Version: 1.7.14');
}

function pm_attachment_error_page($message, $status)
{
    while (ob_get_level() > 0) @ob_end_clean();
    if (!headers_sent()) {
        http_response_code((int)$status);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    $safe = htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>첨부파일 다운로드 실패</title></head>';
    echo '<body style="margin:0;background:#f5f7fa;font-family:Arial,\'Malgun Gothic\',sans-serif;color:#172033">';
    echo '<main style="max-width:620px;margin:10vh auto;padding:28px"><section style="background:#fff;border:1px solid #fecdca;border-radius:18px;padding:26px;box-shadow:0 12px 35px rgba(23,32,51,.08)">';
    echo '<h1 style="margin:0 0 12px;font-size:23px;color:#b42318">첨부파일을 내려받지 못했습니다.</h1>';
    echo '<p style="line-height:1.7;word-break:break-word">' . $safe . '</p>';
    echo '<p style="margin-top:20px;color:#667085">이전 화면으로 돌아가 다시 눌러 주세요. 대용량 첨부라면 네이버 보관기간이 끝났을 수도 있습니다.</p>';
    echo '<button type="button" onclick="history.back()" style="min-height:42px;padding:0 16px;border:0;border-radius:10px;background:#0f766e;color:#fff;font-weight:800;cursor:pointer">이전 화면으로</button>';
    echo '</section></main></body></html>';
}

PublicMailWebHelper::requireLogin();
$messageKey = isset($_GET['message']) ? trim((string)$_GET['message']) : '';
if ($messageKey === '' && isset($_GET['uid']) && (int)$_GET['uid'] > 0) $messageKey = (string)(int)$_GET['uid'];
$part = isset($_GET['part']) ? trim((string)$_GET['part']) : '';

if ($messageKey === '' || $part === '') {
    pm_attachment_error_page('메일 또는 첨부파일 위치값이 없습니다.', 400);
    exit;
}

if (function_exists('session_write_close')) @session_write_close();
@set_time_limit(0);
@ignore_user_abort(false);
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
@ini_set('zlib.output_compression', '0');
if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');

$downloadStarted = false;
try {
    $service = new PublicMailService();
    $attachment = $service->getAttachmentDescriptor($messageKey, $part, false);
    $filename = isset($attachment['filename']) ? (string)$attachment['filename'] : 'attachment.bin';
    $mime = isset($attachment['mime_type']) ? (string)$attachment['mime_type'] : 'application/octet-stream';

    /* 네이버 대용량 첨부는 브라우저가 네이버 파일서버에서 직접 받습니다. */
    if (!empty($attachment['is_large']) && !empty($attachment['source_url'])) {
        while (ob_get_level() > 0) @ob_end_clean();
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('Location: ' . (string)$attachment['source_url'], true, 302);
        exit;
    }

    $service->streamAttachment($messageKey, $part, function ($chunk) use (&$downloadStarted, $filename, $mime) {
        if (!$downloadStarted) {
            pm_attachment_send_download_headers($filename, $mime);
            $downloadStarted = true;
        }
        echo $chunk;
        if (function_exists('ob_flush')) @ob_flush();
        @flush();
    }, $attachment);

    if (!$downloadStarted) throw new RuntimeException('첨부파일 내용이 비어 있습니다.');
    exit;
} catch (Exception $e) {
    @error_log('[CPMS public mail attachment] ' . $e->getMessage());
    if ($downloadStarted || headers_sent()) {
        exit;
    }
    pm_attachment_error_page($e->getMessage(), 404);
    exit;
}
