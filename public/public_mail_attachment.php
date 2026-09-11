<?php
/**
 * 파일 경로: C:\www\cpms\public\public_mail_attachment.php
 *
 * 네이버 일반/대용량 첨부파일을 CPMS 서버 디스크에 저장하지 않고
 * 브라우저로 바로 내려보냅니다.
 *
 * v1.7.23
 * - 국세청 NTS_eTaxInvoice.html은 중간 보기 화면 없이 바로 다운로드합니다.
 * - 국세청 메일의 실제 안내본문은 NTS 보안 HTML을 제외한 MIME 본문을 직접 찾아 표시합니다.
 * - 기존에 잘못 캐시된 국세청 메일도 재수집 없이 본문을 확인할 수 있습니다.
 * - 일반 첨부 다운로드와 대용량 첨부 동작은 기존 그대로 유지합니다.
 *
 * PHP 5.6 호환 코드입니다.
 * CPMS_PUBLIC_MAIL_VERSION: 1.7.23
 */
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/services/PublicMailService.php';
require_once __DIR__ . '/../app/services/PublicMailWebHelper.php';

use App\Services\PublicMailService;
use App\Services\PublicMailWebHelper;
use App\Services\PublicMailImapClient;

function pm_attachment_ascii_fallback($filename)
{
    $filename = (string)$filename;
    $extension = pathinfo($filename, PATHINFO_EXTENSION);
    $extension = preg_replace('/[^A-Za-z0-9]+/', '', (string)$extension);
    return 'download' . ($extension !== '' ? '.' . strtolower($extension) : '.bin');
}

/** 국세청 전자세금계산서 표준 HTML 파일명인지 확인합니다. */
function pm_attachment_is_nts_tax_invoice_html($filename)
{
    $filename = trim((string)$filename);
    if ($filename === '') return false;

    $extension = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
    if ($extension !== 'html' && $extension !== 'htm') return false;

    $lower = strtolower($filename);
    return strpos($lower, 'nts_etaxinvoice') !== false
        || strpos($lower, 'nts-etaxinvoice') !== false;
}

/**
 * 메일의 BODYSTRUCTURE를 읽어 파트 목록을 반환합니다.
 * PublicMailService가 이미 사용하는 해석기를 그대로 호출하므로 별도 MIME 규칙을 만들지 않습니다.
 */
function pm_attachment_read_mail_parts($service, $messageKey)
{
    if (!is_object($service)) throw new RuntimeException('메일 서비스를 준비하지 못했습니다.');

    $detail = $service->getMessageShell($messageKey);
    $mailbox = isset($detail['mailbox']) ? (string)$detail['mailbox'] : 'INBOX';
    $uid = isset($detail['uid']) ? (int)$detail['uid'] : 0;
    if ($uid <= 0) throw new RuntimeException('메일 UID를 확인할 수 없습니다.');

    $settings = $service->getSettings(true);
    $host = isset($settings['imap_host']) && trim((string)$settings['imap_host']) !== ''
        ? trim((string)$settings['imap_host']) : 'imap.naver.com';
    $port = isset($settings['imap_port']) ? (int)$settings['imap_port'] : 993;
    $username = isset($settings['username']) ? trim((string)$settings['username']) : '';
    $password = isset($settings['password']) ? (string)$settings['password'] : '';
    if ($username === '' || $password === '') {
        throw new RuntimeException('네이버 메일 연결정보를 확인할 수 없습니다.');
    }

    $client = new PublicMailImapClient($host, $port, 20);
    try {
        $client->connect();
        $client->login($username, $password);
        $client->selectMailbox($mailbox);
        $structureText = $client->fetchBodyStructure($uid);
    } finally {
        try { $client->logout(); } catch (Exception $ignored) {}
    }

    if (trim((string)$structureText) === '') {
        throw new RuntimeException('메일의 본문/첨부 구조를 읽지 못했습니다.');
    }

    $parseMethod = new ReflectionMethod(get_class($service), 'parseBodyStructure');
    $parseMethod->setAccessible(true);
    $structure = $parseMethod->invoke($service, $structureText);

    $parts = array();
    $flattenMethod = new ReflectionMethod(get_class($service), 'flattenBodyStructure');
    $flattenMethod->setAccessible(true);
    $arguments = array($structure, '', &$parts);
    $flattenMethod->invokeArgs($service, $arguments);

    return array(
        'mailbox' => $mailbox,
        'uid' => $uid,
        'settings' => $settings,
        'parts' => $parts
    );
}

/**
 * NTS_eTaxInvoice.html의 실제 MIME part_id를 찾습니다.
 * 현재 본문 캐시에 첨부로 잡혀 있지 않아도 네이버 원문 구조에서 직접 찾습니다.
 */
function pm_attachment_find_nts_tax_invoice_part($service, $messageKey)
{
    $context = pm_attachment_read_mail_parts($service, $messageKey);
    $parts = isset($context['parts']) && is_array($context['parts']) ? $context['parts'] : array();

    foreach ($parts as $partInfo) {
        if (!is_array($partInfo)) continue;
        $filename = isset($partInfo['filename']) ? (string)$partInfo['filename'] : '';
        $partId = isset($partInfo['part_id']) ? trim((string)$partInfo['part_id']) : '';
        if ($partId !== '' && pm_attachment_is_nts_tax_invoice_html($filename)) {
            return $partId;
        }
    }

    throw new RuntimeException('이 메일에서 NTS_eTaxInvoice.html 파일을 찾지 못했습니다.');
}

/**
 * 국세청 보안 HTML을 제외하고 실제 안내메일 본문만 다시 읽습니다.
 * 기존 캐시가 NTS_eTaxInvoice.html을 본문으로 잘못 잡았더라도 이 경로는 정상 본문을 직접 찾습니다.
 */
function pm_attachment_build_nts_mail_body_document($service, $messageKey)
{
    $context = pm_attachment_read_mail_parts($service, $messageKey);
    $mailbox = isset($context['mailbox']) ? (string)$context['mailbox'] : 'INBOX';
    $uid = isset($context['uid']) ? (int)$context['uid'] : 0;
    $settings = isset($context['settings']) && is_array($context['settings']) ? $context['settings'] : array();
    $parts = isset($context['parts']) && is_array($context['parts']) ? $context['parts'] : array();

    $host = isset($settings['imap_host']) && trim((string)$settings['imap_host']) !== ''
        ? trim((string)$settings['imap_host']) : 'imap.naver.com';
    $port = isset($settings['imap_port']) ? (int)$settings['imap_port'] : 993;
    $username = isset($settings['username']) ? trim((string)$settings['username']) : '';
    $password = isset($settings['password']) ? (string)$settings['password'] : '';
    if ($username === '' || $password === '' || $uid <= 0) {
        throw new RuntimeException('국세청 메일 본문을 읽기 위한 연결정보가 없습니다.');
    }

    $htmlParts = array();
    $textParts = array();
    $inlineMap = array();

    foreach ($parts as $partInfo) {
        if (!is_array($partInfo)) continue;

        $filename = isset($partInfo['filename']) ? (string)$partInfo['filename'] : '';
        if (pm_attachment_is_nts_tax_invoice_html($filename)) {
            /* 실제 세금계산서 보안파일은 메일 본문 후보에서 반드시 제외합니다. */
            continue;
        }

        if (!empty($partInfo['is_inline_image'])) {
            $contentId = isset($partInfo['content_id']) ? trim((string)$partInfo['content_id']) : '';
            $partId = isset($partInfo['part_id']) ? trim((string)$partInfo['part_id']) : '';
            if ($contentId !== '' && $partId !== '') {
                $inlineMap[$contentId] = array(
                    'part_id' => $partId,
                    'content_id' => $contentId,
                    'filename' => isset($partInfo['filename']) ? (string)$partInfo['filename'] : '',
                    'mime_type' => isset($partInfo['mime_type']) ? (string)$partInfo['mime_type'] : 'image/octet-stream',
                    'size' => isset($partInfo['size']) ? (int)$partInfo['size'] : 0,
                    'transfer_encoding' => isset($partInfo['transfer_encoding']) ? (string)$partInfo['transfer_encoding'] : ''
                );
            }
            continue;
        }

        if (!empty($partInfo['is_attachment'])) continue;

        $mime = isset($partInfo['mime_type']) ? strtolower(trim((string)$partInfo['mime_type'])) : '';
        if (in_array($mime, array('text/html', 'text/x-html', 'application/xhtml+xml'), true)) {
            $htmlParts[] = $partInfo;
        } elseif ($mime === 'text/plain') {
            $textParts[] = $partInfo;
        }
    }

    $fetchBodyMethod = new ReflectionMethod(get_class($service), 'fetchTextBodyPart');
    $fetchBodyMethod->setAccessible(true);
    $renderableMethod = new ReflectionMethod(get_class($service), 'mailHtmlHasRenderableContent');
    $renderableMethod->setAccessible(true);
    $buildDocumentMethod = new ReflectionMethod(get_class($service), 'buildMailBodyDocument');
    $buildDocumentMethod->setAccessible(true);

    $client = new PublicMailImapClient($host, $port, 20);
    $bodyHtml = '';
    $bodyText = '';

    try {
        $client->connect();
        $client->login($username, $password);
        $client->selectMailbox($mailbox);

        foreach (array_slice($htmlParts, 0, 5) as $htmlPart) {
            $candidateHtml = (string)$fetchBodyMethod->invoke($service, $client, $uid, $htmlPart);
            $isRenderable = (bool)$renderableMethod->invoke($service, $candidateHtml);
            if ($isRenderable) {
                $bodyHtml = $candidateHtml;
                break;
            }
        }

        if ($bodyHtml === '') {
            foreach (array_slice($textParts, 0, 5) as $textPart) {
                $candidateText = trim((string)$fetchBodyMethod->invoke($service, $client, $uid, $textPart));
                if ($candidateText !== '') {
                    $bodyText = $candidateText;
                    break;
                }
            }
        }
    } finally {
        try { $client->logout(); } catch (Exception $ignored) {}
    }

    if ($bodyHtml === '' && $bodyText === '') {
        $bodyText = '국세청 안내메일 본문을 찾지 못했습니다. 아래 세금계산서 다운로드 버튼으로 원본 파일을 내려받아 확인해 주세요.';
    }

    return (string)$buildDocumentMethod->invoke($service, $bodyHtml, $bodyText, $messageKey, $inlineMap);
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
    header('X-CPMS-Public-Mail-Version: 1.7.23');
}

/** 국세청 실제 안내메일 본문 iframe용 헤더입니다. */
function pm_attachment_send_mail_body_headers()
{
    while (ob_get_level() > 0) @ob_end_clean();
    if (function_exists('header_remove')) {
        @header_remove('Content-Length');
        @header_remove('Content-Encoding');
    }

    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Disposition: inline');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; img-src 'self' data: https: http:; style-src 'unsafe-inline' https: http:; font-src data: https: http:; script-src 'none'; connect-src 'none'; frame-src https: http:; object-src 'none'; base-uri 'none'; form-action 'none'");
    header('X-CPMS-Public-Mail-Version: 1.7.23');
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
    echo '<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>메일 파일 처리 실패</title></head>';
    echo '<body style="margin:0;background:#f5f7fa;font-family:Arial,\'Malgun Gothic\',sans-serif;color:#172033">';
    echo '<main style="max-width:620px;margin:10vh auto;padding:28px"><section style="background:#fff;border:1px solid #fecdca;border-radius:18px;padding:26px;box-shadow:0 12px 35px rgba(23,32,51,.08)">';
    echo '<h1 style="margin:0 0 12px;font-size:23px;color:#b42318">메일 파일을 처리하지 못했습니다.</h1>';
    echo '<p style="line-height:1.7;word-break:break-word">' . $safe . '</p>';
    echo '<p style="margin-top:20px;color:#667085">이전 화면으로 돌아가 다시 눌러 주세요.</p>';
    echo '<button type="button" onclick="history.back()" style="min-height:42px;padding:0 16px;border:0;border-radius:10px;background:#0f766e;color:#fff;font-weight:800;cursor:pointer">이전 화면으로</button>';
    echo '</section></main></body></html>';
}

PublicMailWebHelper::requireLogin();
$messageKey = isset($_GET['message']) ? trim((string)$_GET['message']) : '';
if ($messageKey === '' && isset($_GET['uid']) && (int)$_GET['uid'] > 0) $messageKey = (string)(int)$_GET['uid'];
$part = isset($_GET['part']) ? trim((string)$_GET['part']) : '';
$viewMode = isset($_GET['view']) ? trim((string)$_GET['view']) : '';

/*
 * v1.7.22에서 사용한 tax_invoice / tax_invoice_content 주소도
 * 브라우저 캐시에 남아 있을 수 있으므로 새 버전에서는 모두 '바로 다운로드'로 호환합니다.
 */
$isTaxInvoiceDownload = in_array($viewMode, array('tax_invoice', 'tax_invoice_download', 'tax_invoice_content'), true);
$isTaxInvoiceMailBody = $viewMode === 'tax_invoice_mail_body';

if ($messageKey === '') {
    pm_attachment_error_page('메일 위치값이 없습니다.', 400);
    exit;
}
if (!$isTaxInvoiceDownload && !$isTaxInvoiceMailBody && $part === '') {
    pm_attachment_error_page('첨부파일 위치값이 없습니다.', 400);
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

    /* 국세청 메일 본문은 NTS 보안 HTML을 제외하고 실제 안내본문만 직접 읽습니다. */
    if ($isTaxInvoiceMailBody) {
        $documentHtml = pm_attachment_build_nts_mail_body_document($service, $messageKey);
        pm_attachment_send_mail_body_headers();
        echo $documentHtml;
        exit;
    }

    /* 국세청 다운로드 버튼은 part 값 없이도 실제 NTS HTML을 원문에서 찾아냅니다. */
    if ($isTaxInvoiceDownload && $part === '') {
        $part = pm_attachment_find_nts_tax_invoice_part($service, $messageKey);
    }

    $attachment = $service->getAttachmentDescriptor($messageKey, $part, false);
    $filename = isset($attachment['filename']) ? (string)$attachment['filename'] : 'attachment.bin';
    $mime = isset($attachment['mime_type']) ? (string)$attachment['mime_type'] : 'application/octet-stream';

    /* 국세청 전용 다운로드 주소는 실제 NTS_eTaxInvoice.html에만 허용합니다. */
    if ($isTaxInvoiceDownload && !pm_attachment_is_nts_tax_invoice_html($filename)) {
        pm_attachment_error_page('국세청 NTS_eTaxInvoice.html 파일만 이 버튼으로 다운로드할 수 있습니다.', 403);
        exit;
    }

    /* 네이버 대용량 첨부는 기존처럼 브라우저가 네이버 파일서버에서 직접 받습니다. */
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
