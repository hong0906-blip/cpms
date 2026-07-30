<?php
/**
 * 공사 > 외주비 첨부파일 다운로드
 * - PHP 5.6 호환
 */
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/tabs/partials/outsourcing_file_helper.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }

$fileId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$pdo = Db::pdo();
if ($fileId <= 0 || !$pdo || !cpms_outsourcing_file_ensure_schema($pdo)) {
    http_response_code(404);
    echo '파일을 찾을 수 없습니다.';
    exit;
}

try {
    $st = $pdo->prepare("SELECT f.*
        FROM cpms_outsourcing_cost_files f
        JOIN cpms_outsourcing_costs c ON c.id = f.outsourcing_cost_id AND c.project_id = f.project_id
        WHERE f.id = :id AND f.is_deleted = 0 AND c.is_deleted = 0
        LIMIT 1");
    $st->bindValue(':id', $fileId, PDO::PARAM_INT);
    $st->execute();
    $row = $st->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $row = false;
}
if (!is_array($row)) {
    http_response_code(404);
    echo '파일을 찾을 수 없습니다.';
    exit;
}

$projectId = isset($row['project_id']) ? (int)$row['project_id'] : 0;
$canDownload = Auth::isMaster() || Auth::canAccessConstruction();
if (!$canDownload && function_exists('cpms_is_project_member_or_executive')) {
    $canDownload = cpms_is_project_member_or_executive($pdo, $projectId, Auth::userRole(), Auth::userEmail());
}
if (!$canDownload) {
    http_response_code(403);
    echo '다운로드 권한이 없습니다.';
    exit;
}

$path = cpms_outsourcing_file_resolve_path(isset($row['stored_path']) ? $row['stored_path'] : '');
if ($path === '' || !is_file($path)) {
    http_response_code(404);
    echo '파일을 찾을 수 없습니다.';
    exit;
}
$originalName = basename(str_replace('\\', '/', isset($row['original_name']) ? (string)$row['original_name'] : ''));
$originalName = str_replace(array("\r", "\n", '"'), '', $originalName);
if ($originalName === '') $originalName = 'outsourcing_file_' . $fileId;
$mime = isset($row['mime_type']) && trim((string)$row['mime_type']) !== '' ? trim((string)$row['mime_type']) : 'application/octet-stream';

while (ob_get_level() > 0) @ob_end_clean();
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Transfer-Encoding: binary');
header('Cache-Control: private, max-age=0, no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
$encodedName = rawurlencode($originalName);
header("Content-Disposition: attachment; filename=\"" . $encodedName . "\"; filename*=UTF-8''" . $encodedName);
@readfile($path);
exit;
