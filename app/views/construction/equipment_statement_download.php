<?php
/**
 * 장비 거래명세표 다운로드/보기
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/partials/equipment_statement_helper.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }

$usageId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($usageId <= 0) {
    http_response_code(400);
    echo '파일을 찾을 수 없습니다.';
    exit;
}

$pdo = Db::pdo();
if (!$pdo || !cpms_equipment_statement_ensure_usage_columns($pdo)) {
    http_response_code(404);
    echo '파일을 찾을 수 없습니다.';
    exit;
}

try {
    $st = $pdo->prepare("SELECT u.*
        FROM cpms_equipment_usage u
        WHERE u.id = :id
          AND u.statement_stored_path <> ''
        LIMIT 1");
    $st->bindValue(':id', $usageId, PDO::PARAM_INT);
    $st->execute();
    $usageRow = $st->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $usageRow = false;
}

if (!is_array($usageRow)) {
    http_response_code(404);
    echo '파일을 찾을 수 없습니다.';
    exit;
}

$projectId = isset($usageRow['project_id']) ? (int)$usageRow['project_id'] : 0;
if (!cpms_equipment_statement_user_can_download($pdo, $projectId)) {
    http_response_code(403);
    echo '거래명세표 확인 권한이 없습니다.';
    exit;
}

$path = cpms_equipment_statement_resolve_path(isset($usageRow['statement_stored_path']) ? $usageRow['statement_stored_path'] : '');
if ($path === '' || !is_file($path)) {
    http_response_code(404);
    echo '파일을 찾을 수 없습니다.';
    exit;
}

$originalName = isset($usageRow['statement_original_name']) ? (string)$usageRow['statement_original_name'] : '';
$originalName = basename(str_replace('\\', '/', $originalName));
$originalName = str_replace(array("\r", "\n", '"'), '', $originalName);
if ($originalName === '') {
    $storedName = isset($usageRow['statement_stored_name']) ? (string)$usageRow['statement_stored_name'] : '';
    $originalName = basename(str_replace('\\', '/', $storedName));
}
if ($originalName === '') $originalName = 'equipment_statement_' . $usageId;

$contentType = cpms_equipment_statement_content_type($originalName, isset($usageRow['statement_mime_type']) ? $usageRow['statement_mime_type'] : '');
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
$disposition = (isset($_GET['download']) && (string)$_GET['download'] === '1') ? 'attachment' : 'inline';
header("Content-Disposition: " . $disposition . "; filename=\"" . $encodedName . "\"; filename*=UTF-8''" . $encodedName);

@readfile($path);
exit;
