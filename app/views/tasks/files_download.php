<?php
use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/helpers.php';

if (!Auth::check()) {
    header('Location: ?r=login');
    exit;
}

function cpms_tasks_zip_error($message, $statusCode)
{
    http_response_code((int)$statusCode);
    header('Content-Type: text/plain; charset=UTF-8');
    echo (string)$message;
    exit;
}

function cpms_tasks_zip_entry_name($name, &$usedNames)
{
    $name = trim(str_replace(array('\\', '/'), '_', (string)$name));
    $name = preg_replace('/[\x00-\x1F\x7F]+/', '_', $name);
    if ($name === '') $name = 'task_file';

    $candidate = $name;
    $extension = pathinfo($name, PATHINFO_EXTENSION);
    $baseName = $extension !== '' ? substr($name, 0, -(strlen($extension) + 1)) : $name;
    $number = 2;
    while (isset($usedNames[$candidate])) {
        $candidate = $baseName . '_' . $number . ($extension !== '' ? '.' . $extension : '');
        $number++;
    }
    $usedNames[$candidate] = true;
    return $candidate;
}

$pdo = Db::pdo();
$taskId = isset($_REQUEST['task_id']) ? (int)$_REQUEST['task_id'] : 0;
$mode = isset($_GET['mode']) ? trim((string)$_GET['mode']) : '';
$currentEmployee = cpms_tasks_current_employee($pdo);
$currentEmployeeId = isset($currentEmployee['id']) ? (int)$currentEmployee['id'] : 0;

if (!$pdo || $taskId <= 0 || !cpms_tasks_table_exists($pdo, 'cpms_task_files')) {
    cpms_tasks_zip_error('다운로드할 업무를 찾을 수 없습니다.', 404);
}

$task = cpms_tasks_find_task($pdo, $taskId);
if (!$task || !cpms_tasks_can_view($task, $currentEmployeeId)) {
    cpms_tasks_zip_error('첨부파일을 다운로드할 권한이 없습니다.', 403);
}

$selectedIds = array();
if ($mode !== 'all') {
    $postedIds = isset($_POST['file_ids']) && is_array($_POST['file_ids']) ? $_POST['file_ids'] : array();
    for ($i = 0; $i < count($postedIds); $i++) {
        $fileId = (int)$postedIds[$i];
        if ($fileId > 0) $selectedIds[$fileId] = $fileId;
    }
    if (count($selectedIds) === 0) {
        cpms_tasks_zip_error('다운로드할 파일을 선택해주세요.', 400);
    }
}

try {
    if ($mode === 'all') {
        $statement = $pdo->prepare("SELECT * FROM cpms_task_files WHERE task_id = :task_id ORDER BY id ASC");
        $statement->execute(array(':task_id' => $taskId));
    } else {
        $placeholders = array();
        $params = array(':task_id' => $taskId);
        $selectedIds = array_values($selectedIds);
        for ($i = 0; $i < count($selectedIds); $i++) {
            $key = ':file_id_' . $i;
            $placeholders[count($placeholders)] = $key;
            $params[$key] = (int)$selectedIds[$i];
        }
        $statement = $pdo->prepare("SELECT * FROM cpms_task_files WHERE task_id = :task_id AND id IN (" . implode(',', $placeholders) . ") ORDER BY id ASC");
        $statement->execute($params);
    }
    $files = $statement->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $files = array();
}

if (!is_array($files) || count($files) === 0) {
    cpms_tasks_zip_error('다운로드할 첨부파일이 없습니다.', 404);
}
if (!class_exists('ZipArchive')) {
    cpms_tasks_zip_error('서버에 ZIP 다운로드 기능이 설치되어 있지 않습니다.', 500);
}

$zipPath = tempnam(sys_get_temp_dir(), 'cpms_task_zip_');
if ($zipPath === false) cpms_tasks_zip_error('임시 다운로드 파일을 만들 수 없습니다.', 500);

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    @unlink($zipPath);
    cpms_tasks_zip_error('ZIP 파일을 만들 수 없습니다.', 500);
}

$usedNames = array();
$addedCount = 0;
for ($i = 0; $i < count($files); $i++) {
    $file = $files[$i];
    $originalName = isset($file['original_name']) ? (string)$file['original_name'] : '';
    $entryName = cpms_tasks_zip_entry_name($originalName, $usedNames);
    $storageType = isset($file['storage_type']) ? strtolower(trim((string)$file['storage_type'])) : '';
    $driveFileId = isset($file['drive_file_id']) ? trim((string)$file['drive_file_id']) : '';

    if ($storageType === 'google_drive' && $driveFileId !== '' && cpms_tasks_drive_helper_loaded() && function_exists('cpms_drive_download_file')) {
        $driveDownload = cpms_drive_download_file($driveFileId);
        if (is_array($driveDownload) && !empty($driveDownload['ok'])) {
            $zip->addFromString($entryName, isset($driveDownload['content']) ? (string)$driveDownload['content'] : '');
            $addedCount++;
            continue;
        }
    }

    $localPath = cpms_tasks_local_file_path(isset($file['stored_path']) ? $file['stored_path'] : '');
    if ($localPath !== '' && is_file($localPath) && $zip->addFile($localPath, $entryName)) {
        $addedCount++;
    }
}
$zip->close();

if ($addedCount === 0 || !is_file($zipPath)) {
    @unlink($zipPath);
    cpms_tasks_zip_error('다운로드 가능한 첨부파일이 없습니다.', 404);
}

$downloadName = 'task_' . $taskId . '_attachments_' . date('Ymd_His') . '.zip';
header('Content-Type: application/zip');
header('Content-Length: ' . (string)filesize($zipPath));
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('X-Content-Type-Options: nosniff');
readfile($zipPath);
@unlink($zipPath);
exit;
