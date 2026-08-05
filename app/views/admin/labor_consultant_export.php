<?php

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/labor_consultant_labor_only_override.php';
require_once __DIR__ . '/labor_consultant_helpers.php';

if (!\App\Core\Auth::check()) {
    header('Location: ?r=login');
    exit;
}

$pdo = \App\Core\Db::pdo();
$user = \App\Core\Auth::user();

if (!cpms_labor_consultant_can_access($pdo, $user)) {
    error_log('[labor_consultant_export] permission denied');
    cpms_labor_consultant_render_message_page('접근 권한이 없습니다.');
}

$projectId = isset($_GET['project_id']) ? $_GET['project_id'] : 'all';
$ym = isset($_GET['ym']) ? $_GET['ym'] : cpms_labor_consultant_current_ym();
$projectId = cpms_labor_consultant_normalize_project_filter($projectId);
$ym = cpms_labor_consultant_normalize_ym($ym);
$debugLaborExport = isset($_GET['debug_labor_export']) && (string)$_GET['debug_labor_export'] === '1';
$checkLaborExport = isset($_GET['check_labor_export']) && (string)$_GET['check_labor_export'] === '1';

// 파일: app/views/admin/labor_consultant_export.php
// 엑셀 검증·생성 중에는 인증 세션을 더 이상 변경하지 않으므로 잠금을 먼저 해제합니다.
if (session_id() !== '') {
    @session_write_close();
}

register_shutdown_function(function() use ($checkLaborExport) {
    $err = error_get_last();
    if (!$err || !isset($err['type'])) return;
    $fatalTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
    if (defined('E_RECOVERABLE_ERROR')) $fatalTypes[count($fatalTypes)] = E_RECOVERABLE_ERROR;
    if (!in_array((int)$err['type'], $fatalTypes, true)) return;

    $message = isset($err['message']) ? (string)$err['message'] : 'unknown fatal error';
    $file = isset($err['file']) ? (string)$err['file'] : '';
    $line = isset($err['line']) ? (string)$err['line'] : '';
    error_log('[labor_consultant_export] fatal: ' . $message . ' in ' . $file . ':' . $line);

    if (!headers_sent()) {
        while (ob_get_level() > 0) {
            if (!@ob_end_clean()) break;
        }
        header('Content-Type: text/plain; charset=utf-8', true, 500);
        echo '엑셀 다운로드 처리 중 서버 오류가 발생했습니다: ' . $message;
    }
});

$templateRow = cpms_labor_consultant_get_active_template($pdo);
$templatePath = $templateRow ? cpms_labor_consultant_resolve_stored_path(isset($templateRow['stored_path']) ? $templateRow['stored_path'] : '') : '';

$viewData = cpms_labor_consultant_load_view_data($pdo, $projectId, $ym);
$rows = isset($viewData['rows']) && is_array($viewData['rows']) ? $viewData['rows'] : array();

if ($debugLaborExport) {
    $debugData = cpms_labor_consultant_debug_export_detection_v2($templatePath, $rows, array(
        'project_id' => $projectId,
        'ym' => $ym
    ));
    cpms_labor_consultant_render_debug_page_v2($debugData);
}

if ($checkLaborExport) {
    header('Content-Type: text/plain; charset=utf-8');
    if (!$templateRow || $templatePath === '' || !is_file($templatePath)) {
        echo '등록된 양식 파일이 없습니다.';
        exit;
    }
    if (count($rows) < 1) {
        echo '선택한 현장/기간에 노무비 데이터가 없습니다.';
        exit;
    }

    $projects = isset($viewData['projects']) ? $viewData['projects'] : array();
    $projectLabel = cpms_labor_consultant_project_label($projectId, $projects);
    try {
        $checkResult = cpms_labor_consultant_validate_export_template_v3($templatePath, $rows, array(
            'ym' => $ym,
            'project_label' => $projectLabel
        ));
    } catch (Exception $e) {
        error_log('[labor_consultant_export] check exception: ' . $e->getMessage());
        echo '엑셀 다운로드 처리 중 오류가 발생했습니다: ' . $e->getMessage();
        exit;
    }

    if (isset($checkResult['ok']) && $checkResult['ok']) {
        if (isset($checkResult['path']) && is_file($checkResult['path'])) {
            @unlink($checkResult['path']);
        }
        echo 'OK';
        exit;
    }

    echo isset($checkResult['message']) ? $checkResult['message'] : '엑셀 양식을 읽을 수 없습니다.';
    exit;
}

if (!$templateRow || $templatePath === '' || !is_file($templatePath)) {
    error_log('[labor_consultant_export] template missing');
    cpms_labor_consultant_render_message_page('등록된 양식 파일이 없습니다.');
}

if (count($rows) < 1) {
    error_log('[labor_consultant_export] no data');
    cpms_labor_consultant_render_message_page('선택한 현장/기간에 노무비 데이터가 없습니다.');
}

$projects = isset($viewData['projects']) ? $viewData['projects'] : array();
$projectLabel = cpms_labor_consultant_project_label($projectId, $projects);
try {
    $result = cpms_labor_consultant_create_export_file_v3($templatePath, $rows, array(
        'ym' => $ym,
        'project_label' => $projectLabel
    ));
} catch (Exception $e) {
    error_log('[labor_consultant_export] exception: ' . $e->getMessage());
    cpms_labor_consultant_render_message_page('엑셀 다운로드 처리 중 오류가 발생했습니다: ' . $e->getMessage());
}

if (!isset($result['ok']) || !$result['ok']) {
    $message = isset($result['message']) ? $result['message'] : '엑셀 양식을 읽을 수 없습니다.';
    error_log('[labor_consultant_export] export failed: ' . $message);
    cpms_labor_consultant_render_message_page($message);
}

$downloadName = cpms_labor_consultant_download_name($projectLabel, $ym);
$filePath = isset($result['path']) ? $result['path'] : '';

if ($filePath === '' || !is_file($filePath)) {
    error_log('[labor_consultant_export] export file missing');
    cpms_labor_consultant_render_message_page('엑셀 양식을 읽을 수 없습니다.');
}

// 파일: app/views/admin/labor_consultant_export.php
// 일별 공수 셀은 1, 1.5처럼 동일하게 표시하고, 복제된 행의 병합 상태를 최종 정리합니다.
if (function_exists('cpms_labor_consultant_fix_export_workbook')) {
    $workbookFixResult = cpms_labor_consultant_fix_export_workbook($filePath, $templatePath, $rows);
    if (!isset($workbookFixResult['ok']) || !$workbookFixResult['ok']) {
        error_log('[labor_consultant_export] workbook format fix skipped: '
            . (isset($workbookFixResult['message']) ? $workbookFixResult['message'] : 'unknown'));
    }
}

// 파일: app/views/admin/labor_consultant_export.php
// fread() 반복 중 빈 값이 반환되면 응답이 끝나지 않을 수 있어 완성 파일을 먼저 읽습니다.
$fileContents = @file_get_contents($filePath);
if ($fileContents === false) {
    error_log('[labor_consultant_export] export file read failed');
    @unlink($filePath);
    cpms_labor_consultant_render_message_page('생성된 엑셀 파일을 읽을 수 없습니다.');
}
@unlink($filePath);

if (headers_sent($sentFile, $sentLine)) {
    error_log('[labor_consultant_export] headers already sent: ' . $sentFile . ':' . $sentLine);
    cpms_labor_consultant_render_message_page('다운로드 헤더를 보낼 수 없습니다. 출력이 먼저 발생했습니다.');
}
$obLevel = ob_get_level();
while ($obLevel > 0) {
    if (!@ob_end_clean()) break;
    $obLevel = ob_get_level();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . rawurlencode($downloadName) . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
header('Content-Length: ' . (string)strlen($fileContents));
header('Cache-Control: private, max-age=0, no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
echo $fileContents;
exit;
