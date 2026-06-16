<?php
use App\Core\Db;

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../services/ApprovalPdfService.php';

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
if (!$doc || !approval_can_view_document($pdo, $doc, $u)) {
    http_response_code(403);
    exit(approval_ko('%EC%9D%B4%20%EB%AC%B8%EC%84%9C%EB%A5%BC%20%EB%B3%BC%20%EA%B6%8C%ED%95%9C%EC%9D%B4%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
}

$storageType = isset($doc['completed_pdf_storage_type']) ? strtolower(trim((string)$doc['completed_pdf_storage_type'])) : '';
$fileId = isset($doc['completed_pdf_drive_file_id']) ? trim((string)$doc['completed_pdf_drive_file_id']) : '';
$viewLink = isset($doc['completed_pdf_drive_web_view_link']) ? trim((string)$doc['completed_pdf_drive_web_view_link']) : '';
$downloadLink = isset($doc['completed_pdf_drive_web_content_link']) ? trim((string)$doc['completed_pdf_drive_web_content_link']) : '';

if ($storageType !== 'google_drive' || $fileId === '') {
    http_response_code(404);
    exit(approval_ko('%50%44%46%20%EB%AF%B8%EC%83%9D%EC%84%B1'));
}

$target = $download ? $downloadLink : $viewLink;
if ($target === '') {
    http_response_code(404);
    exit(approval_ko('%ED%8C%8C%EC%9D%BC%20%ED%99%95%EC%9D%B8%20%ED%95%84%EC%9A%94'));
}

header('Location: ' . $target);
exit;
