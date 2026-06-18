<?php
/**
 * Quality file upload action.
 * PHP 5.6 compatible.
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/quality_file_helper.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    flash_set('error', '보안 토큰이 올바르지 않습니다.');
    header('Location: ?r=quality_home');
    exit;
}

$pdo = Db::pdo();
$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$documentType = isset($_POST['document_type']) ? trim((string)$_POST['document_type']) : 'etc';
$options = cpms_quality_file_document_options();
if (!isset($options[$documentType])) $documentType = 'etc';
$basisMonth = isset($_POST['basis_month']) ? trim((string)$_POST['basis_month']) : '';
$basisDate = cpms_quality_file_valid_date(isset($_POST['basis_date']) ? (string)$_POST['basis_date'] : '');
$monthValue = $basisMonth !== '' ? $basisMonth : ($basisDate !== '' ? $basisDate : date('Y-m-d'));
$title = trim((string)(isset($_POST['title']) ? $_POST['title'] : ''));
$description = trim((string)(isset($_POST['description']) ? $_POST['description'] : ''));
$redirect = '?r=quality_home';

if (!$pdo) {
    flash_set('error', 'DB 연결에 실패했습니다.');
    header('Location: ' . $redirect);
    exit;
}
if (!cpms_quality_file_can_upload($pdo, $projectId)) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}
$projectName = $projectId > 0 ? cpms_quality_file_project_name($pdo, $projectId) : cpms_quality_drive_label('common');
if ($projectId > 0 && $projectName === '') {
    flash_set('error', '프로젝트 정보를 찾을 수 없습니다.');
    header('Location: ' . $redirect);
    exit;
}
if (!isset($_FILES['quality_files']) || !is_array($_FILES['quality_files'])) {
    flash_set('error', cpms_quality_file_label('no_file'));
    header('Location: ' . $redirect);
    exit;
}

$files = cpms_quality_file_normalize_uploads($_FILES['quality_files']);
$store = cpms_quality_file_read_store();
if (!isset($store['items']) || !is_array($store['items'])) $store['items'] = array();
$createdRows = array();
$driveRecords = array();
$localPaths = array();
$errors = array();
$driveFailed = false;
$userId = cpms_quality_file_user_id();
$userName = (string)Auth::userName();
$now = date('Y-m-d H:i:s');

foreach ($files as $file) {
    if (!is_array($file) || (isset($file['error']) && (int)$file['error'] === UPLOAD_ERR_NO_FILE)) continue;
    $recordId = cpms_quality_file_new_id();
    $message = '';
    $local = cpms_quality_file_store_uploaded($file, $projectId, $recordId, $monthValue, $message);
    if (!is_array($local)) {
        $errors[] = $message !== '' ? $message : cpms_quality_file_label('upload_failed');
        continue;
    }
    $localPaths[] = isset($local['stored_path']) ? (string)$local['stored_path'] : '';
    $docInfo = cpms_quality_drive_document_info($documentType, isset($local['original_name']) ? (string)$local['original_name'] : '');
    $monthInfo = cpms_quality_drive_parse_month($monthValue, $now);
    $row = array(
        'id' => $recordId,
        'project_id' => $projectId,
        'project_name' => $projectName,
        'is_common_file' => $projectId > 0 ? '0' : '1',
        'title' => $title !== '' ? $title : (isset($local['original_name']) ? (string)$local['original_name'] : ''),
        'description' => $description,
        'document_type' => isset($docInfo['document_type']) ? (string)$docInfo['document_type'] : $documentType,
        'document_label' => isset($docInfo['document_label']) ? (string)$docInfo['document_label'] : cpms_quality_file_document_label($documentType),
        'document_year' => isset($monthInfo['year']) ? (string)$monthInfo['year'] : '',
        'document_month' => isset($monthInfo['month']) ? (string)$monthInfo['month'] : '',
        'basis_month' => $basisMonth,
        'basis_date' => $basisDate,
        'original_name' => isset($local['original_name']) ? (string)$local['original_name'] : '',
        'stored_name' => isset($local['stored_name']) ? (string)$local['stored_name'] : '',
        'stored_path' => isset($local['stored_path']) ? (string)$local['stored_path'] : '',
        'local_path' => isset($local['local_path']) ? (string)$local['local_path'] : '',
        'file_size' => isset($local['file_size']) ? (int)$local['file_size'] : 0,
        'mime_type' => isset($local['mime_type']) ? (string)$local['mime_type'] : '',
        'section' => 'quality',
        'storage_type' => 'local',
        'upload_status' => 'local_saved',
        'uploaded_by' => $userId,
        'uploaded_by_name' => $userName,
        'uploaded_at' => $now,
        'status' => 'active',
        'is_deleted' => 0
    );

    $driveResult = cpms_quality_drive_upload_local_file(
        $pdo,
        $projectId,
        cpms_quality_file_resolve_path(isset($local['stored_path']) ? (string)$local['stored_path'] : ''),
        isset($local['original_name']) ? (string)$local['original_name'] : '',
        $documentType,
        $monthValue,
        $now,
        array('date' => $basisDate !== '' ? $basisDate : $now, 'project_name' => $projectName),
        Auth::user()
    );
    if (is_array($driveResult) && isset($driveResult['record']) && is_array($driveResult['record'])) {
        $row = array_merge($row, cpms_quality_drive_record_values($driveResult['record'], $userId));
        if (!empty($driveResult['ok'])) $driveRecords[] = $driveResult['record'];
    }
    if (!is_array($driveResult) || empty($driveResult['ok'])) $driveFailed = true;
    $createdRows[] = $row;
}

if (count($createdRows) === 0) {
    flash_set('error', count($errors) > 0 ? implode(' ', $errors) : cpms_quality_file_label('no_file'));
    header('Location: ' . $redirect);
    exit;
}

foreach ($createdRows as $row) {
    $store['items'][] = $row;
}
if (!cpms_quality_file_write_store($store)) {
    foreach ($driveRecords as $record) {
        cpms_quality_drive_delete_uploaded_record($record, array(
            'section' => 'quality',
            'project_id' => isset($record['project_id']) ? $record['project_id'] : '',
            'is_common_file' => isset($record['is_common_file']) ? $record['is_common_file'] : '',
            'original_name' => isset($record['original_name']) ? $record['original_name'] : '',
            'message' => 'Quality file metadata save failed after Drive upload.'
        ));
    }
    foreach ($localPaths as $storedPath) {
        $localPath = cpms_quality_file_resolve_path($storedPath);
        if ($localPath !== '') @unlink($localPath);
    }
    flash_set('error', cpms_quality_file_label('save_failed'));
    header('Location: ' . $redirect);
    exit;
}

$success = cpms_quality_file_label('saved') . ' (' . count($createdRows) . ')';
if ($driveFailed) $success = cpms_quality_drive_flash_message($success, array('ok' => false));
if (count($errors) > 0) $success .= ' ' . implode(' ', $errors);
flash_set('success', $success);
header('Location: ' . $redirect);
exit;
