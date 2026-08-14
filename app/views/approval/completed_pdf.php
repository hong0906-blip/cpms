<?php
use App\Core\Db;

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../services/ApprovalPdfService.php';
require_once __DIR__ . '/../admin/leave_management_helpers.php';

$pdo = Db::pdo();
$u = \App\Core\Auth::user();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$download = isset($_GET['download']) && (string)$_GET['download'] === '1';

if (!$pdo || $id <= 0 || !$u) {
    http_response_code(404);
    exit(approval_ko('%ED%8C%8C%EC%9D%BC%20%ED%99%95%EC%9D%B8%20%ED%95%84%EC%9A%94'));
}

$st = $pdo->prepare("SELECT * FROM cpms_approval_documents WHERE id=:id LIMIT 1");
$st->execute(array(':id' => $id));
$doc = $st->fetch(PDO::FETCH_ASSOC);
$canViewPdf = $doc ? approval_can_view_document($pdo, $doc, $u) : false;
if (!$canViewPdf && $doc && isset($doc['doc_type']) && (string)$doc['doc_type'] === 'leave') {
    $canViewPdf = cpms_leave_can_access_management($pdo, $u);
}
if (!$doc || !$canViewPdf) {
    http_response_code(403);
    exit(approval_ko('%EC%9D%B4%20%EB%AC%B8%EC%84%9C%EB%A5%BC%20%EB%B3%BC%20%EA%B6%8C%ED%95%9C%EC%9D%B4%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
}

$storageType = isset($doc['completed_pdf_storage_type']) ? strtolower(trim((string)$doc['completed_pdf_storage_type'])) : '';
$fileId = isset($doc['completed_pdf_drive_file_id']) ? trim((string)$doc['completed_pdf_drive_file_id']) : '';
if ($storageType !== 'google_drive' || $fileId === '') {
    http_response_code(404);
    exit(approval_ko('%50%44%46%20%EB%AF%B8%EC%83%9D%EC%84%B1'));
}

$etag = '"cpms-approval-pdf-' . sha1($fileId) . '"';
header('Cache-Control: private, max-age=0, must-revalidate');
header('ETag: ' . $etag);
if (!$download && isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string)$_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) @session_write_close();
    http_response_code(304);
    exit;
}

$expectedSize = isset($doc['completed_pdf_size']) ? (int)$doc['completed_pdf_size'] : 0;
$cachedPath = cpms_approval_pdf_cache_get_path($fileId, $expectedSize);
$pdfContent = '';
$driveDownload = null;
if ($cachedPath === '') {
    // Release the PHP session lock before the only potentially slow operation.
    // Other tabs from the same user can continue while Drive fills this cache.
    if (session_id() !== '') @session_write_close();
    $driveDownload = cpms_drive_download_file($fileId);
    $pdfContent = (is_array($driveDownload) && !empty($driveDownload['ok']) && isset($driveDownload['content']))
        ? (string)$driveDownload['content']
        : '';
    if ($pdfContent === '' || substr($pdfContent, 0, 4) !== '%PDF') {
        cpms_approval_pdf_log_failure(array(
            'user' => $u,
            'section' => 'approval_completed_pdf_download',
            'approval_document_id' => $id,
            'message' => is_array($driveDownload) && isset($driveDownload['message'])
                ? (string)$driveDownload['message']
                : 'Completed PDF could not be downloaded from Drive.'
        ));
        http_response_code(502);
        exit(approval_ko('%ED%8C%8C%EC%9D%BC%20%ED%99%95%EC%9D%B8%20%ED%95%84%EC%9A%94'));
    }
    $cachedPath = cpms_approval_pdf_cache_store_content($fileId, $pdfContent);
}

$pdfName = isset($doc['completed_pdf_name']) ? trim((string)$doc['completed_pdf_name']) : '';
if ($pdfName === '') $pdfName = 'approval_completed_' . $id . '.pdf';
$pdfName = str_replace(array("\r", "\n"), '', $pdfName);
if (strtolower(substr($pdfName, -4)) !== '.pdf') $pdfName .= '.pdf';
$asciiName = preg_replace('/[^A-Za-z0-9\.\_\-]+/', '_', $pdfName);
if ($asciiName === '') $asciiName = 'approval_completed.pdf';
$disposition = $download ? 'attachment' : 'inline';
$pdfSize = $cachedPath !== '' ? (int)@filesize($cachedPath) : strlen($pdfContent);

if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) @session_write_close();
header('Content-Type: application/pdf');
header('Content-Length: ' . (string)$pdfSize);
header('Content-Disposition: ' . $disposition . '; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($pdfName));
header('X-Content-Type-Options: nosniff');
if ($cachedPath !== '') {
    @readfile($cachedPath);
} else {
    echo $pdfContent;
}
exit;
