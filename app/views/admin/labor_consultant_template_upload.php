<?php

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/labor_consultant_helpers.php';

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

try {
    $pdo->beginTransaction();
    $stOff = $pdo->prepare("UPDATE cpms_labor_export_templates SET is_active = 0 WHERE template_type = :type AND is_active = 1");
    $stOff->bindValue(':type', cpms_labor_consultant_template_type());
    $stOff->execute();

    $stIns = $pdo->prepare("INSERT INTO cpms_labor_export_templates
        (template_type, original_name, stored_name, stored_path, file_size, uploaded_by, uploaded_at, is_active)
        VALUES (:type, :original_name, :stored_name, :stored_path, :file_size, :uploaded_by, :uploaded_at, 1)");
    $stIns->bindValue(':type', cpms_labor_consultant_template_type());
    $stIns->bindValue(':original_name', $originalName);
    $stIns->bindValue(':stored_name', $safeStoredName);
    $stIns->bindValue(':stored_path', $dbStoredPath);
    $stIns->bindValue(':file_size', $fileSize, PDO::PARAM_INT);
    if ($uploadedBy > 0) {
        $stIns->bindValue(':uploaded_by', $uploadedBy, PDO::PARAM_INT);
    } else {
        $stIns->bindValue(':uploaded_by', null, PDO::PARAM_NULL);
    }
    $stIns->bindValue(':uploaded_at', date('Y-m-d H:i:s'));
    $stIns->execute();
    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[labor_consultant_template_upload] ' . $e->getMessage());
    @unlink($storedPath);
    cpms_labor_consultant_flash_redirect('error', '양식 정보 저장에 실패했습니다.', $projectId, $ym, 'consultant');
}

cpms_labor_consultant_flash_redirect('success', '노무사 확인용 양식을 등록했습니다.', $projectId, $ym, 'consultant');
