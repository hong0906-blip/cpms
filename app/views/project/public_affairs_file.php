<?php
/**
 * Protected public-affairs file view/download route.
 * PHP 5.6 compatible.
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../services/PublicAffairsDriveService.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }

$type = isset($_GET['type']) ? trim((string)$_GET['type']) : '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($type === '' || $id <= 0) { http_response_code(400); echo 'Bad Request'; exit; }

$defs = array(
    'contract_version' => array('table' => 'cpms_contract_versions', 'path' => 'stored_path', 'name' => 'original_name'),
    'contract_history' => array('table' => 'cpms_project_contract_change_files', 'path' => 'stored_path', 'name' => 'original_name'),
    'additional_work' => array('table' => 'cpms_contract_additional_works', 'path' => 'attachment_stored_path', 'name' => 'attachment_original_name'),
    'progress' => array('table' => 'cpms_progress_billings', 'path' => 'attachment_stored_path', 'name' => 'attachment_original_name')
);
if (!isset($defs[$type])) { http_response_code(400); echo 'Bad Request'; exit; }

$pdo = Db::pdo();
if (!$pdo) { http_response_code(500); echo 'DB Error'; exit; }

$def = $defs[$type];
$table = $def['table'];
if (!cpms_public_affairs_drive_table_exists($pdo, $table)) {
    http_response_code(404);
    echo 'Not Found';
    exit;
}

try {
    $st = $pdo->prepare("SELECT * FROM `" . $table . "` WHERE id = :id LIMIT 1");
    $st->bindValue(':id', $id, PDO::PARAM_INT);
    $st->execute();
    $row = $st->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $row = false;
}
if (!is_array($row)) { http_response_code(404); echo 'Not Found'; exit; }

$projectId = isset($row['project_id']) ? (int)$row['project_id'] : 0;
if (!cpms_public_affairs_user_can_view_project($pdo, $projectId)) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}

$storageType = isset($row['storage_type']) ? trim((string)$row['storage_type']) : '';
if ($storageType === 'google_drive') {
    $viewLink = isset($row['drive_web_view_link']) ? trim((string)$row['drive_web_view_link']) : '';
    $downloadLink = isset($row['drive_web_content_link']) ? trim((string)$row['drive_web_content_link']) : '';
    $wantDownload = isset($_GET['download']) && (string)$_GET['download'] === '1';
    $target = ($wantDownload && $downloadLink !== '') ? $downloadLink : $viewLink;
    if ($target === '' && $downloadLink !== '') $target = $downloadLink;
    if ($target !== '') {
        header('Location: ' . $target);
        exit;
    }
    http_response_code(404);
    echo 'File check required';
    exit;
}

$pathColumn = $def['path'];
$nameColumn = $def['name'];
$storedPath = isset($row[$pathColumn]) ? trim((string)$row[$pathColumn]) : '';
if ($storedPath === '') { http_response_code(404); echo 'Not Found'; exit; }

$real = realpath($storedPath);
$storageRoot = realpath(cpms_storage_root());
if ($real === false || $storageRoot === false) { http_response_code(404); echo 'Not Found'; exit; }
$realNorm = str_replace('\\', '/', $real);
$rootNorm = rtrim(str_replace('\\', '/', $storageRoot), '/');
if (stripos($realNorm, $rootNorm . '/') !== 0 && strcasecmp($realNorm, $rootNorm) !== 0) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}
if (!is_file($real)) { http_response_code(404); echo 'Not Found'; exit; }

$originalName = isset($row[$nameColumn]) ? basename(str_replace('\\', '/', (string)$row[$nameColumn])) : '';
if ($originalName === '') $originalName = 'public_affairs_file_' . $id;
$originalName = str_replace(array("\r", "\n", '"'), '', $originalName);
$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
$ctype = 'application/octet-stream';
if ($ext === 'pdf') $ctype = 'application/pdf';
if ($ext === 'jpg' || $ext === 'jpeg') $ctype = 'image/jpeg';
if ($ext === 'png') $ctype = 'image/png';
if ($ext === 'xlsx') $ctype = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
if ($ext === 'xls') $ctype = 'application/vnd.ms-excel';

while (ob_get_level() > 0) {
    @ob_end_clean();
}

$inline = isset($_GET['view']) && (string)$_GET['view'] === '1' && ($ctype === 'application/pdf' || $ctype === 'image/jpeg' || $ctype === 'image/png');
$encoded = rawurlencode($originalName);
header('Content-Type: ' . $ctype);
header('Content-Length: ' . filesize($real));
header('Cache-Control: private, max-age=0, no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header(($inline ? 'Content-Disposition: inline; ' : 'Content-Disposition: attachment; ') . "filename=\"" . $encoded . "\"; filename*=UTF-8''" . $encoded);
@readfile($real);
exit;
