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

$statusRows = cpms_labor_consultant_setup_status($pdo, true);
$hasError = false;
foreach ($statusRows as $statusRow) {
    if (isset($statusRow['status']) && $statusRow['status'] !== '성공') {
        $hasError = true;
        break;
    }
}

if ($hasError) {
    cpms_labor_consultant_flash_redirect('error', '관리 DB 설치/확인 중 오류가 발생했습니다.', $projectId, $ym, 'consultant');
}

cpms_labor_consultant_flash_redirect('success', '관리 DB 설치/확인이 완료되었습니다.', $projectId, $ym, 'consultant');
