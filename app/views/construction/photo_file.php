<?php
/**
 * Construction photo file view/download gateway.
 * PHP 5.6 compatible.
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../services/ConstructionDriveService.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) {
    header('Location: ?r=login');
    exit;
}

$photoId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($photoId <= 0) {
    http_response_code(400);
    echo h(cpms_construction_drive_label('file_check_required'));
    exit;
}

$pdo = Db::pdo();
if (!$pdo || !cpms_construction_drive_table_exists($pdo, 'cpms_schedule_progress_photos')) {
    http_response_code(404);
    echo h(cpms_construction_drive_label('file_check_required'));
    exit;
}

try {
    $st = $pdo->prepare("
        SELECT ph.*, sp.project_id
        FROM cpms_schedule_progress_photos ph
        INNER JOIN cpms_schedule_progress sp ON sp.id = ph.progress_id
        WHERE ph.id = :id
        LIMIT 1
    ");
    $st->bindValue(':id', $photoId, PDO::PARAM_INT);
    $st->execute();
    $row = $st->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $row = false;
}

if (!is_array($row)) {
    http_response_code(404);
    echo h(cpms_construction_drive_label('file_check_required'));
    exit;
}

$projectId = isset($row['project_id']) ? (int)$row['project_id'] : 0;
if (!cpms_construction_drive_user_can_view_project($pdo, $projectId)) {
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
    echo h(cpms_construction_drive_label('file_check_required'));
    exit;
}

$localUrl = isset($row['file_path']) ? trim((string)$row['file_path']) : '';
if ($localUrl === '') {
    http_response_code(404);
    echo h(cpms_construction_drive_label('file_check_required'));
    exit;
}

header('Location: ' . $localUrl);
exit;
