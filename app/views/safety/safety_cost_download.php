<?php
/**
 * Safety cost PDF view/download.
 * PHP 5.6 compatible.
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/safety_cost_helper.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }

$pdo = Db::pdo();
$id = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
$row = cpms_safety_cost_find_item($id);
if (!is_array($row) || !cpms_safety_cost_is_active($row)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'PDF 파일을 찾을 수 없습니다.';
    exit;
}

$projectId = isset($row['project_id']) ? (int)$row['project_id'] : 0;
if (!cpms_safety_cost_user_can_view_project($pdo, $projectId)) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}

$pdf = isset($row['pdf']) && is_array($row['pdf']) ? $row['pdf'] : array();
if (function_exists('cpms_safety_health_drive_is_drive_file') && cpms_safety_health_drive_is_drive_file($pdf)) {
    $url = cpms_safety_health_drive_link($pdf, (isset($_GET['download']) && (string)$_GET['download'] === '1'));
    if ($url === '') {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo cpms_safety_health_drive_label('file_check_required');
        exit;
    }
    header('Location: ' . $url);
    exit;
}
$storedPath = isset($pdf['stored_path']) ? (string)$pdf['stored_path'] : '';
$path = cpms_safety_cost_resolve_path($storedPath);
if ($path === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'PDF 파일 경로가 없거나 파일을 찾을 수 없습니다.';
    exit;
}

$originalName = isset($pdf['original_name']) && trim((string)$pdf['original_name']) !== '' ? (string)$pdf['original_name'] : ('safety_cost_' . $id . '.pdf');
$disposition = (isset($_GET['download']) && (string)$_GET['download'] === '1') ? 'attachment' : 'inline';

header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', basename($originalName)) . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($path);
exit;
