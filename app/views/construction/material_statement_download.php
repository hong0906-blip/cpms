<?php
/**
 * 파일: app/views/construction/material_statement_download.php
 * 자재구입비 거래명세표 보기/다운로드
 * - view=1: 브라우저에서 바로보기
 * - download=1: 다운로드
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/partials/material_statement_helper.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }

$fileId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($fileId <= 0) {
    http_response_code(400);
    echo '파일을 찾을 수 없습니다.';
    exit;
}

$pdo = Db::pdo();
if (!$pdo || !cpms_material_statement_schema_ready($pdo)) {
    http_response_code(404);
    echo '파일을 찾을 수 없습니다.';
    exit;
}

try {
    $st = $pdo->prepare("SELECT * FROM cpms_material_statement_files WHERE id = :id AND is_deleted = 0 LIMIT 1");
    $st->bindValue(':id', $fileId, PDO::PARAM_INT);
    $st->execute();
    $fileRow = $st->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $fileRow = false;
}

if (!is_array($fileRow)) {
    http_response_code(404);
    echo '파일을 찾을 수 없습니다.';
    exit;
}

$projectId = isset($fileRow['project_id']) ? (int)$fileRow['project_id'] : 0;
if (!cpms_material_statement_user_can_download($pdo, $projectId)) {
    http_response_code(403);
    echo '거래명세표 확인 권한이 없습니다.';
    exit;
}

$wantDownload = isset($_GET['download']) && (string)$_GET['download'] === '1';
$wantView = isset($_GET['view']) && (string)$_GET['view'] === '1';
$storageType = isset($fileRow['storage_type']) ? trim((string)$fileRow['storage_type']) : '';
if ($storageType === 'google_drive') {
    $viewLink = isset($fileRow['drive_web_view_link']) ? trim((string)$fileRow['drive_web_view_link']) : '';
    $downloadLink = isset($fileRow['drive_web_content_link']) ? trim((string)$fileRow['drive_web_content_link']) : '';
    $target = ($wantDownload && $downloadLink !== '') ? $downloadLink : $viewLink;
    if ($target === '' && $downloadLink !== '') $target = $downloadLink;
    if ($target !== '') {
        header('Location: ' . $target);
        exit;
    }
    http_response_code(404);
    echo '파일 확인 필요';
    exit;
}

$path = cpms_material_statement_resolve_path(isset($fileRow['stored_path']) ? $fileRow['stored_path'] : '');
if ($path === '' || !is_file($path)) {
    http_response_code(404);
    echo '파일을 찾을 수 없습니다.';
    exit;
}

$originalName = isset($fileRow['original_name']) ? (string)$fileRow['original_name'] : '';
$originalName = basename(str_replace('\\', '/', $originalName));
$originalName = str_replace(array("\r", "\n", '"'), '', $originalName);
if ($originalName === '') {
    $storedName = isset($fileRow['stored_name']) ? (string)$fileRow['stored_name'] : '';
    $originalName = basename(str_replace('\\', '/', $storedName));
}
if ($originalName === '') $originalName = 'material_statement_' . $fileId;

$contentType = cpms_material_statement_content_type($originalName, isset($fileRow['mime_type']) ? $fileRow['mime_type'] : '');
$fileSize = filesize($path);

while (ob_get_level() > 0) @ob_end_clean();

header('Content-Type: ' . $contentType);
header('Content-Length: ' . $fileSize);
header('Content-Transfer-Encoding: binary');
header('Cache-Control: private, max-age=0, no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$encodedName = rawurlencode($originalName);
$disposition = ($wantView && !$wantDownload) ? 'inline' : 'attachment';
header("Content-Disposition: " . $disposition . "; filename=\"" . $encodedName . "\"; filename*=UTF-8''" . $encodedName);

@readfile($path);
exit;
