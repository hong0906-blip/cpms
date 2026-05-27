<?php

require_once __DIR__ . '/../../bootstrap.php';
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

$templateRow = cpms_labor_consultant_get_active_template($pdo);
if (!$templateRow) {
    error_log('[labor_consultant_export] template missing');
    cpms_labor_consultant_render_message_page('등록된 노무사 확인용 양식이 없습니다.');
}

$templatePath = cpms_labor_consultant_resolve_stored_path(isset($templateRow['stored_path']) ? $templateRow['stored_path'] : '');
if ($templatePath === '' || !is_file($templatePath)) {
    error_log('[labor_consultant_export] template missing');
    cpms_labor_consultant_render_message_page('등록된 노무사 확인용 양식이 없습니다.');
}

$viewData = cpms_labor_consultant_load_view_data($pdo, $projectId, $ym);
$rows = isset($viewData['rows']) && is_array($viewData['rows']) ? $viewData['rows'] : array();

if ($debugLaborExport) {
    $debugData = cpms_labor_consultant_debug_export_detection($templatePath, $rows);
    cpms_labor_consultant_render_debug_page($debugData);
}

if (count($rows) < 1) {
    error_log('[labor_consultant_export] no data');
    cpms_labor_consultant_render_message_page('선택한 현장/기간에 노무비 데이터가 없습니다.');
}

$projects = isset($viewData['projects']) ? $viewData['projects'] : array();
$projectLabel = cpms_labor_consultant_project_label($projectId, $projects);
$result = cpms_labor_consultant_create_export_file_v2($templatePath, $rows, array(
    'ym' => $ym,
    'project_label' => $projectLabel
));

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

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . rawurlencode($downloadName) . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, max-age=0, no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

$fp = fopen($filePath, 'rb');
if ($fp) {
    while (!feof($fp)) {
        echo fread($fp, 8192);
    }
    fclose($fp);
}
@unlink($filePath);
exit;
