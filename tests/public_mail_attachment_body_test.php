<?php
/**
 * 네이버 첨부 메일의 BODYSTRUCTURE literal과 원문 MIME 복구 회귀 테스트.
 * PHP 5.6 호환 코드만 사용합니다.
 */

require_once dirname(__DIR__) . '/app/services/PublicMailImapClient.php';
require_once dirname(__DIR__) . '/app/services/PublicMailService.php';

use App\Services\PublicMailImapClient;
use App\Services\PublicMailService;
use App\Services\PublicMailStorageService;

function public_mail_attachment_assert($condition, $message)
{
    if ($condition) return;
    fwrite(STDERR, "FAIL: " . $message . PHP_EOL);
    exit(1);
}

function public_mail_private_method($className, $methodName)
{
    $method = new ReflectionMethod($className, $methodName);
    $method->setAccessible(true);
    return $method;
}

$filename = '현장 사진 (최종).pdf';
$literalLength = strlen($filename);
$response = array(
    'lines' => array(
        '* 1 FETCH (UID 77 BODYSTRUCTURE (("TEXT" "HTML" ("CHARSET" "UTF-8") NIL NIL "QUOTED-PRINTABLE" 128 4 NIL NIL NIL NIL)("APPLICATION" "PDF" ("NAME" {' . $literalLength . "}\r\n",
        ') NIL NIL "BASE64" 2048 NIL ("ATTACHMENT" ("FILENAME" {' . $literalLength . "}\r\n",
        ')) NIL NIL) "MIXED" ("BOUNDARY" "----=_Part_77") NIL NIL))' . "\r\n",
        'A0001 OK FETCH completed' . "\r\n"
    ),
    'literals' => array($filename, $filename)
);

$imapReflection = new ReflectionClass('App\\Services\\PublicMailImapClient');
$imap = $imapReflection->newInstanceWithoutConstructor();
$responseTextMethod = public_mail_private_method('App\\Services\\PublicMailImapClient', 'responseTextWithLiterals');
$responseText = $responseTextMethod->invoke($imap, $response);
public_mail_attachment_assert(substr_count($responseText, '"' . $filename . '"') === 2, 'BODYSTRUCTURE literal 파일명이 제자리에 복원되어야 합니다.');
public_mail_attachment_assert(strpos($responseText, '{' . $literalLength . '}') === false, 'literal 크기 표시가 MIME 항목으로 남으면 안 됩니다.');

$bodyPosition = stripos($responseText, 'BODYSTRUCTURE');
$openPosition = strpos($responseText, '(', $bodyPosition + strlen('BODYSTRUCTURE'));
$balancedMethod = public_mail_private_method('App\\Services\\PublicMailImapClient', 'extractBalancedParentheses');
$bodyStructure = $balancedMethod->invoke($imap, $responseText, $openPosition);
public_mail_attachment_assert($bodyStructure !== '', '복원한 BODYSTRUCTURE가 균형 잡힌 MIME 트리여야 합니다.');

$serviceReflection = new ReflectionClass('App\\Services\\PublicMailService');
$service = $serviceReflection->newInstanceWithoutConstructor();
$parseStructureMethod = public_mail_private_method('App\\Services\\PublicMailService', 'parseBodyStructure');
$flattenMethod = public_mail_private_method('App\\Services\\PublicMailService', 'flattenBodyStructure');
$structure = $parseStructureMethod->invoke($service, $bodyStructure);
$parts = array();
$flattenArguments = array($structure, '', &$parts);
$flattenMethod->invokeArgs($service, $flattenArguments);

$htmlFound = false;
$attachmentFound = false;
foreach ($parts as $part) {
    if (!is_array($part)) continue;
    if (isset($part['mime_type']) && $part['mime_type'] === 'text/html' && isset($part['part_id']) && $part['part_id'] === '1') $htmlFound = true;
    if (!empty($part['is_attachment']) && isset($part['filename']) && $part['filename'] === $filename && isset($part['part_id']) && $part['part_id'] === '2') $attachmentFound = true;
}
public_mail_attachment_assert($htmlFound, '첨부 메일의 HTML 본문 파트를 찾아야 합니다.');
public_mail_attachment_assert($attachmentFound, '한글 literal 파일명의 첨부 파트를 찾아야 합니다.');

$raw = "From: sender@example.com\r\n"
    . "To: receiver@example.com\r\n"
    . "Subject: attachment test\r\n"
    . "Content-Type: multipart/mixed; boundary=\"mail-boundary\"\r\n\r\n"
    . "--mail-boundary\r\n"
    . "Content-Type: text/html; charset=UTF-8\r\n"
    . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
    . "<html><body><p>=EC=B2=A8=EB=B6=80 =EB=A9=94=EC=9D=BC =EB=B3=B8=EB=AC=B8</p><img src=\"cid:mail-logo\"></body></html>\r\n"
    . "--mail-boundary\r\n"
    . "Content-Type: image/png; name=\"logo.png\"\r\n"
    . "Content-Disposition: inline; filename=\"logo.png\"\r\n"
    . "Content-ID: <mail-logo>\r\n"
    . "Content-Transfer-Encoding: base64\r\n\r\n"
    . "iVBORw0KGgo=\r\n"
    . "--mail-boundary\r\n"
    . "Content-Type: application/pdf\r\n"
    . "Content-Disposition: attachment; filename*=UTF-8''%ED%98%84%EC%9E%A5%EB%B3%B4%EA%B3%A0%EC%84%9C.pdf\r\n"
    . "Content-Transfer-Encoding: base64\r\n\r\n"
    . "JVBERi0xLjQK\r\n"
    . "--mail-boundary--\r\n";
$parsed = $service->parseRawMessage($raw, false);
public_mail_attachment_assert(strpos(isset($parsed['body_text']) ? $parsed['body_text'] : '', '첨부 메일 본문') !== false, '원문 보조 경로에서 HTML 본문을 복구해야 합니다.');
public_mail_attachment_assert(isset($parsed['attachments'][0]['filename']) && $parsed['attachments'][0]['filename'] === '현장보고서.pdf', 'RFC2231 첨부 파일명을 복구해야 합니다.');
public_mail_attachment_assert(isset($parsed['attachments'][0]['transfer_encoding']) && $parsed['attachments'][0]['transfer_encoding'] === 'base64', '보조 경로의 첨부 전송 인코딩을 보존해야 합니다.');
public_mail_attachment_assert(isset($parsed['inline_images'][0]['content_id']) && $parsed['inline_images'][0]['content_id'] === 'mail-logo', '원문 EML의 CID 인라인 이미지 위치를 보존해야 합니다.');
public_mail_attachment_assert(PublicMailStorageService::BODY_CACHE_VERSION >= 19, '잘못 저장된 기존 본문 캐시를 무효화해야 합니다.');

$serviceSource = (string)file_get_contents(dirname(__DIR__) . '/app/services/PublicMailService.php');
public_mail_attachment_assert(strpos($serviceSource, '$originalRaw = $client->fetchRawMessage') !== false, '본문 구조 복구가 필요할 때 EML 원문 전체 조회를 사용할 수 있어야 합니다.');
public_mail_attachment_assert(strpos($serviceSource, 'if (!is_array($cache)) $this->buildBodyCache($messageKey, false);') !== false, '일반 메일 열람은 전체 EML 대신 빠른 MIME 본문 경로를 사용해야 합니다.');
public_mail_attachment_assert(strpos($serviceSource, '$attachmentMetadataMissing = !empty($message[\'has_attachment\']) && empty($attachments);') !== false, '첨부 표시와 실제 첨부 목록이 다르면 원문 복구 경로로 전환해야 합니다.');
public_mail_attachment_assert(strpos($serviceSource, 'if (!$this->mailBodyHasContent($bodyHtmlRaw, $bodyText) || $attachmentMetadataMissing)') !== false, '본문 또는 첨부가 누락된 경우에만 원문 복구를 실행해야 합니다.');
public_mail_attachment_assert(strpos($serviceSource, '$previewIncomplete = !$previewHasBody || ($attachmentMetadataMissing && !$previewHasAttachments);') !== false, '앞부분에서 본문만 발견되고 첨부 헤더가 없으면 전체 EML 검증을 계속해야 합니다.');
public_mail_attachment_assert(strpos($serviceSource, "'raw_original_status'=>") !== false, '원문 EML 검증 상태를 캐시에 기록해야 합니다.');
$detailSource = (string)file_get_contents(dirname(__DIR__) . '/app/views/public_mail/detail_fragment.php');
$scriptSource = (string)file_get_contents(dirname(__DIR__) . '/public/assets/js/public_mail.js');
public_mail_attachment_assert(strpos($detailSource, 'data-mail-document') !== false && strpos($detailSource, '$bodyDocumentHtml') !== false, '안전하게 재구성한 원문 HTML 문서를 실제 상세화면에 표시해야 합니다.');
public_mail_attachment_assert(strpos($scriptSource, 'isPanel?12000:60000') !== false, '원문 EML 조회 시 상세 요청 시간을 충분히 보장해야 합니다.');

$detail = array(
    'message_key'=>'INBOX:77',
    'body_html'=>'<p>축약 본문</p>',
    'body_text'=>'축약 본문',
    'body_document_html'=>'<!doctype html><html><body><table><tr><td>원문 표</td></tr></table></body></html>',
    'body_cache_updated_at'=>'2026-08-18 18:00:00',
    'raw_original_status'=>'verified',
    'attachments'=>array(array('part_id'=>'2','filename'=>'현장보고서.pdf','mime_type'=>'application/pdf','size'=>1024)),
    'drive_records'=>array()
);
$baseUrl = '/cpms/public';
$selectedProjectId = '';
$esc = function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
ob_start();
include dirname(__DIR__) . '/app/views/public_mail/detail_fragment.php';
$detailHtml = ob_get_clean();
public_mail_attachment_assert(strpos($detailHtml, 'data-mail-document') !== false && strpos($detailHtml, 'srcdoc=') !== false, '상세화면은 축약 HTML이 아닌 원문 문서 프레임을 출력해야 합니다.');
public_mail_attachment_assert(strpos($detailHtml, '현장보고서.pdf') !== false && strpos($detailHtml, '원문 EML 전체') !== false, '원문 표시와 첨부파일 표시가 함께 나와야 합니다.');

echo "OK: public mail attachment/body regression tests passed" . PHP_EOL;
