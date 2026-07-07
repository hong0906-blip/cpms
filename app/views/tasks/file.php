<?php
use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/helpers.php';

if (!Auth::check()) {
    header('Location: ?r=login');
    exit;
}

$pdo = Db::pdo();
$fileId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$download = isset($_GET['download']) && (string)$_GET['download'] === '1';
$currentEmployee = cpms_tasks_current_employee($pdo);

if (!$pdo || $fileId <= 0 || !cpms_tasks_table_exists($pdo, 'cpms_task_files')) {
    http_response_code(404);
    echo '파일을 찾을 수 없습니다.';
    exit;
}

try {
    $st = $pdo->prepare("SELECT * FROM cpms_task_files WHERE id = :id LIMIT 1");
    $st->execute(array(':id' => $fileId));
    $file = $st->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $file = false;
}

if (!$file || !isset($file['task_id'])) {
    http_response_code(404);
    echo '파일을 찾을 수 없습니다.';
    exit;
}

$task = cpms_tasks_find_task($pdo, (int)$file['task_id']);
if (!$task || !cpms_tasks_can_view($task, isset($currentEmployee['id']) ? (int)$currentEmployee['id'] : 0)) {
    http_response_code(403);
    echo '이 파일을 볼 권한이 없습니다.';
    exit;
}

$storageType = isset($file['storage_type']) ? strtolower(trim((string)$file['storage_type'])) : '';
if ($storageType === 'google_drive') {
    $viewUrl = isset($file['drive_web_view_link']) ? trim((string)$file['drive_web_view_link']) : '';
    $contentUrl = isset($file['drive_web_content_link']) ? trim((string)$file['drive_web_content_link']) : '';
    $target = ($download && $contentUrl !== '') ? $contentUrl : $viewUrl;
    if ($target === '' && $contentUrl !== '') $target = $contentUrl;
    if ($target !== '') {
        header('Location: ' . $target);
        exit;
    }
}

$path = cpms_tasks_local_file_path(isset($file['stored_path']) ? $file['stored_path'] : '');
if ($path === '') {
    http_response_code(404);
    echo '파일 확인이 필요합니다.';
    exit;
}

$name = isset($file['original_name']) && trim((string)$file['original_name']) !== '' ? trim((string)$file['original_name']) : basename($path);
$mime = isset($file['mime_type']) && trim((string)$file['mime_type']) !== '' ? trim((string)$file['mime_type']) : 'application/octet-stream';
if (cpms_tasks_drive_helper_loaded()) $mime = cpms_drive_detect_mime_type($path);
$asciiName = preg_replace('/[^A-Za-z0-9\.\_\-]+/', '_', $name);
if ($asciiName === '') $asciiName = 'task_file';
$disposition = $download ? 'attachment' : 'inline';

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: ' . $disposition . '; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
