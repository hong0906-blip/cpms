<?php

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/labor_consultant_helpers.php';
require_once __DIR__ . '/../../services/ManagementDriveService.php';

if (!\App\Core\Auth::check()) {
    header('Location: ?r=login');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cpms_labor_consultant_flash_redirect('error', '잘못된 요청입니다.', 'all', cpms_labor_consultant_current_ym(), 'consultant');
}

if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    cpms_labor_consultant_flash_redirect('error', '보안 토큰이 올바르지 않습니다.', isset($_POST['project_id']) ? $_POST['project_id'] : 'all', isset($_POST['ym']) ? $_POST['ym'] : cpms_labor_consultant_current_ym(), 'consultant');
}

$pdo = \App\Core\Db::pdo();
$user = \App\Core\Auth::user();
$projectId = isset($_POST['project_id']) ? $_POST['project_id'] : 'all';
$ym = isset($_POST['ym']) ? $_POST['ym'] : cpms_labor_consultant_current_ym();

if (!cpms_labor_consultant_can_access($pdo, $user)) {
    cpms_labor_consultant_flash_redirect('error', '접근 권한이 없습니다. 관리부서 전용 화면입니다.', $projectId, $ym, 'consultant');
}

if (!cpms_labor_consultant_ensure_template_table($pdo) || !cpms_labor_consultant_ensure_storage_dir()) {
    cpms_labor_consultant_flash_redirect('error', '양식 저장소 준비에 실패했습니다.', $projectId, $ym, 'consultant');
}
cpms_management_drive_ensure_table_columns($pdo, 'cpms_labor_export_templates');

if (!isset($_FILES['template_file']) || !is_array($_FILES['template_file'])) {
    cpms_labor_consultant_flash_redirect('error', '업로드할 엑셀 양식을 선택해주세요.', $projectId, $ym, 'consultant');
}

$file = $_FILES['template_file'];
$errorCode = isset($file['error']) ? (int)$file['error'] : UPLOAD_ERR_NO_FILE;
$tmpName = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';
$originalName = isset($file['name']) ? (string)$file['name'] : '';
$fileSize = isset($file['size']) ? (int)$file['size'] : 0;

if ($errorCode !== UPLOAD_ERR_OK || $tmpName === '' || !is_uploaded_file($tmpName)) {
    cpms_labor_consultant_flash_redirect('error', '엑셀 양식 업로드에 실패했습니다.', $projectId, $ym, 'consultant');
}

$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
if ($ext !== 'xlsx') {
    cpms_labor_consultant_flash_redirect('error', '.xlsx 파일만 업로드할 수 있습니다.', $projectId, $ym, 'consultant');
}

$safeStoredName = date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 8) . '.xlsx';
$storedDir = cpms_labor_consultant_template_history_dir();
$storedPath = $storedDir . '/' . $safeStoredName;
$dbStoredPath = cpms_labor_consultant_template_public_path() . '/' . $safeStoredName;

if (!@move_uploaded_file($tmpName, $storedPath)) {
    cpms_labor_consultant_flash_redirect('error', '양식 파일 저장에 실패했습니다.', $projectId, $ym, 'consultant');
}

$uploadedBy = 0;
if (is_array($user) && isset($user['id'])) {
    $uploadedBy = (int)$user['id'];
}

$driveProjectId = is_numeric($projectId) ? (int)$projectId : 0;
$driveUploadResult = cpms_management_drive_upload_local_file(
    $pdo,
    $driveProjectId,
    $storedPath,
    $originalName,
    'labor',
    $ym,
    date('Y-m-d'),
    array('date' => date('Y-m-d')),
    $user
);
$driveRecord = (isset($driveUploadResult['record']) && is_array($driveUploadResult['record'])) ? $driveUploadResult['record'] : array();

try {
    $pdo->beginTransaction();
    $stOff = $pdo->prepare("UPDATE cpms_labor_export_templates SET is_active = 0 WHERE template_type = :type AND is_active = 1");
    $stOff->bindValue(':type', cpms_labor_consultant_template_type());
    $stOff->execute();

    $insertMap = array(
        'template_type' => cpms_labor_consultant_template_type(),
        'original_name' => $originalName,
        'stored_name' => $safeStoredName,
        'stored_path' => $dbStoredPath,
        'file_size' => $fileSize,
        'uploaded_by' => $uploadedBy > 0 ? $uploadedBy : null,
        'uploaded_at' => date('Y-m-d H:i:s'),
        'is_active' => 1
    );
    if (is_array($driveRecord) && count($driveRecord) > 0) {
        $driveValues = cpms_management_drive_record_values($driveRecord, $uploadedBy);
        foreach ($driveValues as $column => $value) {
            $insertMap[$column] = $value;
        }
    }
    $columns = array();
    $holders = array();
    $params = array();
    foreach ($insertMap as $column => $value) {
        if (!cpms_management_drive_column_exists($pdo, 'cpms_labor_export_templates', $column)) continue;
        $columns[] = '`' . $column . '`';
        $holders[] = ':' . $column;
        $params[':' . $column] = $value;
    }
    $stIns = $pdo->prepare("INSERT INTO cpms_labor_export_templates (" . implode(',', $columns) . ") VALUES (" . implode(',', $holders) . ")");
    foreach ($params as $key => $value) {
        if ($value === null) $stIns->bindValue($key, null, PDO::PARAM_NULL);
        else $stIns->bindValue($key, $value);
    }
    $stIns->execute();
    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (is_array($driveRecord) && isset($driveRecord['drive_file_id']) && trim((string)$driveRecord['drive_file_id']) !== '') {
        cpms_drive_delete_file((string)$driveRecord['drive_file_id'], array(
            'section' => 'management',
            'project_id' => $driveProjectId,
            'document_type' => 'labor',
            'original_name' => $originalName,
            'target_folder_id' => isset($driveRecord['drive_folder_id']) ? $driveRecord['drive_folder_id'] : '',
            'message' => 'Labor template DB save failed after Drive upload.'
        ));
    }
    error_log('[labor_consultant_template_upload] ' . $e->getMessage());
    @unlink($storedPath);
    cpms_labor_consultant_flash_redirect('error', '양식 정보 저장에 실패했습니다.', $projectId, $ym, 'consultant');
}

cpms_labor_consultant_flash_redirect('success', cpms_management_drive_flash_message('노무사 확인용 양식을 등록했습니다.', $driveUploadResult), $projectId, $ym, 'consultant');
