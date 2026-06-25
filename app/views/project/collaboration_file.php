<?php
/**
 * 공무 협업툴 첨부파일 다운로드
 * - storage/public_affairs_collab/files 하위 파일만 권한 확인 후 내려준다.
 */

use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/../../services/PublicAffairsCollaborationService.php';

if (!Auth::check()) { header('Location: ?r=login'); exit; }

$fileId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$file = cpms_public_affairs_collab_find_file($fileId);
if (!is_array($file)) { http_response_code(404); echo 'Not Found'; exit; }

$task = cpms_public_affairs_collab_find_task(isset($file['task_id']) ? (int)$file['task_id'] : 0);
if (!is_array($task)) { http_response_code(404); echo 'Not Found'; exit; }

$pdo = Db::pdo();
$actor = cpms_public_affairs_collab_current_employee($pdo);
if (!cpms_public_affairs_collab_user_can_view_task($task, $actor)) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}

$storedPath = isset($file['stored_path']) ? trim((string)$file['stored_path']) : '';
$real = realpath($storedPath);
$root = realpath(cpms_public_affairs_collab_root_dir());
if ($real === false || $root === false) { http_response_code(404); echo 'Not Found'; exit; }

$realNorm = str_replace('\\', '/', $real);
$rootNorm = rtrim(str_replace('\\', '/', $root), '/');
if (stripos($realNorm, $rootNorm . '/') !== 0 && strcasecmp($realNorm, $rootNorm) !== 0) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}
if (!is_file($real)) { http_response_code(404); echo 'Not Found'; exit; }

$originalName = isset($file['original_name']) ? basename(str_replace('\\', '/', (string)$file['original_name'])) : '';
if ($originalName === '') $originalName = 'public_affairs_collab_file_' . $fileId;
$originalName = str_replace(array("\r", "\n", '"'), '', $originalName);
$encoded = rawurlencode($originalName);
$mime = isset($file['mime_type']) && trim((string)$file['mime_type']) !== '' ? trim((string)$file['mime_type']) : 'application/octet-stream';
if (function_exists('finfo_open')) {
    $finfo = @finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
        $detected = @finfo_file($finfo, $real);
        if (is_string($detected) && $detected !== '') $mime = $detected;
        @finfo_close($finfo);
    }
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($real));
header('Cache-Control: private, max-age=0, no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="' . $encoded . '"; filename*=UTF-8\'\'' . $encoded);
@readfile($real);
exit;
