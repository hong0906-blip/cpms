<?php
/**
 * C:\www\cpms\app\views\admin\workforce_import_preview.php
 * - 인력관리 엑셀 업로드 파일 저장 후 미리보기 세션 생성
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../services/WorkerImportService.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }
if (!(Auth::isMaster() || Auth::canManageEmployees())) { http_response_code(403); echo '403 Forbidden'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }
csrf_validate();

if (!isset($_FILES['worker_excel']) || !is_array($_FILES['worker_excel'])) {
    flash_set('danger', '업로드할 엑셀 파일을 선택해주세요.');
    header('Location: ?r=admin/workforce_upload');
    exit;
}

$file = $_FILES['worker_excel'];
$errorCode = isset($file['error']) ? (int)$file['error'] : UPLOAD_ERR_NO_FILE;
$tmpName = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';
$originalName = isset($file['name']) ? basename((string)$file['name']) : '';
$fileSize = isset($file['size']) ? (int)$file['size'] : 0;
$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
$defaultAgencyName = isset($_POST['default_agency_name']) ? trim((string)$_POST['default_agency_name']) : '';
$targetMonth = isset($_POST['target_month']) ? trim((string)$_POST['target_month']) : '';

if ($errorCode !== UPLOAD_ERR_OK || $tmpName === '' || !is_uploaded_file($tmpName)) {
    flash_set('danger', '엑셀 파일 업로드에 실패했습니다.');
    header('Location: ?r=admin/workforce_upload');
    exit;
}

if ($ext !== 'xlsx' && $ext !== 'xls') {
    flash_set('danger', '.xlsx 또는 .xls 파일만 업로드할 수 있습니다.');
    header('Location: ?r=admin/workforce_upload');
    exit;
}

if ($fileSize <= 0 || $fileSize > (20 * 1024 * 1024)) {
    flash_set('danger', '엑셀 파일은 20MB 이하만 업로드할 수 있습니다.');
    header('Location: ?r=admin/workforce_upload');
    exit;
}

if (function_exists('finfo_open')) {
    $finfo = @finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? @finfo_file($finfo, $tmpName) : '';
    if ($finfo) @finfo_close($finfo);
    $allowed = array(
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel',
        'application/zip',
        'application/octet-stream',
    );
    if ($mime !== '' && !in_array($mime, $allowed, true)) {
        flash_set('danger', '엑셀 파일 형식이 올바르지 않습니다.');
        header('Location: ?r=admin/workforce_upload');
        exit;
    }
}

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('danger', 'DB 연결 실패');
    header('Location: ?r=admin/workforce_upload');
    exit;
}

$root = dirname(dirname(dirname(__DIR__)));
$uploadDir = $root . '/storage/workforce_imports/' . date('Ym');
if (!cpms_ensure_dir($uploadDir)) {
    flash_set('danger', '업로드 저장 폴더를 만들 수 없습니다.');
    header('Location: ?r=admin/workforce_upload');
    exit;
}

$storedName = 'workers_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 8) . '.' . $ext;
$storedPath = rtrim($uploadDir, '/\\') . '/' . $storedName;
if (!@move_uploaded_file($tmpName, $storedPath)) {
    flash_set('danger', '업로드 파일 저장에 실패했습니다.');
    header('Location: ?r=admin/workforce_upload');
    exit;
}

$importer = new ExcelWorkerImporter();
$mapping = $importer->defaultMapping();
$service = new WorkerImportService($pdo);
$preview = $service->preview($storedPath, $mapping, $defaultAgencyName);
if (!is_array($preview) || (isset($preview['error']) && $preview['error'] !== '')) {
    flash_set('danger', is_array($preview) && isset($preview['error']) ? $preview['error'] : '엑셀 미리보기를 만들 수 없습니다.');
    header('Location: ?r=admin/workforce_upload');
    exit;
}

$token = substr(md5(uniqid('', true)), 0, 20);
if (!isset($_SESSION['worker_import_preview']) || !is_array($_SESSION['worker_import_preview'])) {
    $_SESSION['worker_import_preview'] = array();
}
$_SESSION['worker_import_preview'][$token] = array(
    'file_path' => $storedPath,
    'original_filename' => $originalName,
    'stored_filename' => $storedName,
    'default_agency_name' => $defaultAgencyName,
    'target_month' => $targetMonth,
    'mapping' => $mapping,
    'sheet_name' => isset($preview['sheet_name']) ? (string)$preview['sheet_name'] : '',
    'summary' => isset($preview['summary']) ? $preview['summary'] : array(),
    'rows' => isset($preview['rows']) ? $preview['rows'] : array(),
    'created_at' => time(),
);

flash_set('success', '엑셀 미리보기를 만들었습니다.');
header('Location: ?r=관리&tab=workforce&import_token=' . urlencode($token));
exit;
