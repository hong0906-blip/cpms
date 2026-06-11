<?php
/**
 * 자재구입비 거래명세표 다운로드
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
    echo '거래명세표 다운로드 권한이 없습니다.';
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

while (ob_get_level() > 0) {
    @ob_end_clean();
}

header('Content-Type: ' . $contentType);
header('Content-Length: ' . $fileSize);
header('Content-Transfer-Encoding: binary');
header('Cache-Control: private, max-age=0, no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$encodedName = rawurlencode($originalName);
header("Content-Disposition: attachment; filename=\"" . $encodedName . "\"; filename*=UTF-8''" . $encodedName);

@readfile($path);
exit;
