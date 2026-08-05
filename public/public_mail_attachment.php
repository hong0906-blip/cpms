<?php
/**
 * 파일 경로: C:\www\cpms\public\public_mail_attachment.php
 *
 * 네이버 원본메일에서 필요한 첨부파일만 읽어 내려보냅니다.
 * PHP 5.6 호환 코드입니다.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/services/PublicMailService.php';
require_once __DIR__ . '/../app/services/PublicMailWebHelper.php';

use App\Services\PublicMailService;
use App\Services\PublicMailWebHelper;

PublicMailWebHelper::requireLogin();

$uid = isset($_GET['uid']) ? (int)$_GET['uid'] : 0;
$partId = isset($_GET['part']) ? trim((string)$_GET['part']) : '';

try {
    $service = new PublicMailService();
    $attachment = $service->getAttachment($uid, $partId);

    $filename = isset($attachment['filename']) ? (string)$attachment['filename'] : 'attachment.bin';
    $mimeType = isset($attachment['mime_type']) && trim((string)$attachment['mime_type']) !== ''
        ? (string)$attachment['mime_type']
        : 'application/octet-stream';
    $content = isset($attachment['content']) ? $attachment['content'] : '';

    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . strlen($content));
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($filename));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, max-age=0');
    echo $content;
    exit;
} catch (Exception $e) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo '첨부파일을 내려받을 수 없습니다: ' . $e->getMessage();
    exit;
}
