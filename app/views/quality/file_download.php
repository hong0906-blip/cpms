<?php
/**
 * Quality file view/download action.
 * PHP 5.6 compatible.
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/quality_file_helper.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) {
    header('Location: ?r=login');
    exit;
}

$pdo = Db::pdo();
$id = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
$download = isset($_GET['download']) && (string)$_GET['download'] === '1';
$row = cpms_quality_file_find_item($id);

if (!is_array($row)) {
    http_response_code(404);
    echo cpms_quality_drive_h(cpms_quality_file_label('file_missing'));
    exit;
}

$projectId = isset($row['project_id']) ? (int)$row['project_id'] : 0;
if (!cpms_quality_file_can_view($pdo, $projectId)) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}

if (cpms_quality_drive_is_drive_file($row)) {
    $link = cpms_quality_drive_link($row, $download);
    if ($link === '') {
        http_response_code(404);
        echo cpms_quality_drive_h(cpms_quality_file_label('file_missing'));
        exit;
    }
    header('Location: ' . $link);
    exit;
}

$storedPath = isset($row['stored_path']) ? (string)$row['stored_path'] : (isset($row['local_path']) ? (string)$row['local_path'] : '');
$path = cpms_quality_file_resolve_path($storedPath);
if ($path === '') {
    http_response_code(404);
    echo cpms_quality_drive_h(cpms_quality_file_label('file_missing'));
    exit;
}

$name = isset($row['original_name']) && trim((string)$row['original_name']) !== '' ? trim((string)$row['original_name']) : basename($path);
$name = str_replace(array("\r", "\n", '"'), array('', '', ''), $name);
$fallbackName = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
if ($fallbackName === '') $fallbackName = 'quality_file';
$mime = isset($row['mime_type']) && trim((string)$row['mime_type']) !== '' ? trim((string)$row['mime_type']) : 'application/octet-stream';
$disposition = $download ? 'attachment' : 'inline';

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)@filesize($path));
header('Content-Disposition: ' . $disposition . '; filename="' . $fallbackName . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
