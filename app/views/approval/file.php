<?php
use App\Core\Db;

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../services/ApprovalDriveService.php';

$pdo = Db::pdo();
$u = \App\Core\Auth::user();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$download = (isset($_GET['download']) && (string)$_GET['download'] === '1');

if (!$pdo || !$u || $id <= 0 || !approval_table_exists($pdo, 'cpms_approval_files')) {
    http_response_code(404);
    exit(approval_ko('%ED%8C%8C%EC%9D%BC%EC%9D%84%20%EC%B0%BE%EC%9D%84%20%EC%88%98%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
}

$st = $pdo->prepare("SELECT * FROM cpms_approval_files WHERE id=:id LIMIT 1");
$st->execute(array(':id' => $id));
$file = $st->fetch(PDO::FETCH_ASSOC);
if (!$file || !isset($file['document_id']) || (int)$file['document_id'] <= 0) {
    http_response_code(404);
    exit(approval_ko('%ED%8C%8C%EC%9D%BC%EC%9D%84%20%EC%B0%BE%EC%9D%84%20%EC%88%98%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
}

$docSt = $pdo->prepare("SELECT * FROM cpms_approval_documents WHERE id=:id LIMIT 1");
$docSt->execute(array(':id' => (int)$file['document_id']));
$doc = $docSt->fetch(PDO::FETCH_ASSOC);
if (!$doc || !approval_can_view_document($pdo, $doc, $u)) {
    http_response_code(403);
    exit(approval_ko('%EC%9D%B4%20%ED%8C%8C%EC%9D%BC%EC%9D%84%20%EB%B3%BC%20%EA%B6%8C%ED%95%9C%EC%9D%B4%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
}

$storageType = cpms_approval_drive_file_storage_type($file);
if ($storageType === 'google_drive') {
    $viewUrl = isset($file['drive_web_view_link']) ? trim((string)$file['drive_web_view_link']) : '';
    $contentUrl = isset($file['drive_web_content_link']) ? trim((string)$file['drive_web_content_link']) : '';
    $target = ($download && $contentUrl !== '') ? $contentUrl : $viewUrl;
    if ($target === '') {
        http_response_code(404);
        exit(approval_ko('%ED%8C%8C%EC%9D%BC%20%ED%99%95%EC%9D%B8%EC%9D%B4%20%ED%95%84%EC%9A%94%ED%95%A9%EB%8B%88%EB%8B%A4.'));
    }
    header('Location: ' . $target);
    exit;
}

$path = cpms_approval_drive_resolve_local_path(isset($file['file_path']) ? $file['file_path'] : '');
if ($path === '') {
    http_response_code(404);
    exit(approval_ko('%ED%8C%8C%EC%9D%BC%20%ED%99%95%EC%9D%B8%EC%9D%B4%20%ED%95%84%EC%9A%94%ED%95%A9%EB%8B%88%EB%8B%A4.'));
}

$name = isset($file['original_name']) && trim((string)$file['original_name']) !== '' ? trim((string)$file['original_name']) : basename($path);
$mime = isset($file['mime_type']) && trim((string)$file['mime_type']) !== '' ? trim((string)$file['mime_type']) : cpms_drive_detect_mime_type($path);
$asciiName = preg_replace('/[^A-Za-z0-9\.\_\-]+/', '_', $name);
if ($asciiName === '') $asciiName = 'approval_file';
$disposition = $download ? 'attachment' : 'inline';

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: ' . $disposition . '; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
