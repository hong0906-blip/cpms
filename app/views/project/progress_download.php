<?php
/**
 * 공무 > 기성관리 첨부파일 다운로드
 * PHP 5.6 compatible.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }

$fileId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($fileId <= 0) { http_response_code(400); echo 'Bad Request'; exit; }

$pdo = Db::pdo();
if (!$pdo) { http_response_code(500); echo 'DB Error'; exit; }

function cpms_progress_download_table_exists($pdo) {
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE :tbl");
        $st->bindValue(':tbl', 'cpms_progress_billings');
        $st->execute();
        return $st->fetch() ? true : false;
    } catch (Exception $e) {
        return false;
    }
}

function cpms_progress_download_dept($dept) {
    $dept = trim((string)$dept);
    $map = array('관리부'=>'관리','관리팀'=>'관리','공무부'=>'공무','공무팀'=>'공무','공사부'=>'공사','공사팀'=>'공사');
    return isset($map[$dept]) ? $map[$dept] : $dept;
}

function cpms_progress_download_allowed($pdo, $projectId) {
    if (Auth::isMaster()) return true;
    $role = Auth::userRole();
    if ($role === 'executive') return true;
    $dept = cpms_progress_download_dept(Auth::userDepartment());
    if ($dept === '공무' || $dept === '관리') return true;
    if (function_exists('cpms_is_project_member_or_executive')) {
        return cpms_is_project_member_or_executive($pdo, (int)$projectId, $role, (string)Auth::userEmail());
    }
    return false;
}

function cpms_progress_download_resolve($storedPath) {
    $storedPath = trim((string)$storedPath);
    if ($storedPath === '') return '';
    $real = realpath($storedPath);
    $root = realpath(cpms_storage_root() . '/progress');
    if ($real === false || $root === false) return '';
    $realNorm = str_replace('\\', '/', $real);
    $rootNorm = rtrim(str_replace('\\', '/', $root), '/');
    if (strcasecmp($realNorm, $rootNorm) === 0) return $real;
    if (stripos($realNorm, $rootNorm . '/') !== 0) return '';
    return $real;
}

if (!cpms_progress_download_table_exists($pdo)) {
    http_response_code(404);
    echo 'Not Found';
    exit;
}

try {
    $st = $pdo->prepare("SELECT * FROM cpms_progress_billings WHERE id = :id LIMIT 1");
    $st->bindValue(':id', $fileId, PDO::PARAM_INT);
    $st->execute();
    $row = $st->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $row = false;
}

if (!is_array($row)) { http_response_code(404); echo 'Not Found'; exit; }

$projectId = isset($row['project_id']) ? (int)$row['project_id'] : 0;
if (!cpms_progress_download_allowed($pdo, $projectId)) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}

$path = cpms_progress_download_resolve(isset($row['attachment_stored_path']) ? $row['attachment_stored_path'] : '');
if ($path === '' || !is_file($path)) { http_response_code(404); echo 'Not Found'; exit; }

$originalName = isset($row['attachment_original_name']) ? basename(str_replace('\\', '/', (string)$row['attachment_original_name'])) : '';
if ($originalName === '') $originalName = 'progress_attachment_' . $fileId;
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

$encoded = rawurlencode($originalName);
header('Content-Type: ' . $ctype);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=0, no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header("Content-Disposition: attachment; filename=\"" . $encoded . "\"; filename*=UTF-8''" . $encoded);
@readfile($path);
exit;

