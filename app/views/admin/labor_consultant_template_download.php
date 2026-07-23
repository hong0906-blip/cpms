<?php

// 파일: app/views/admin/labor_consultant_template_download.php
// 관리부 권한을 확인한 뒤 과거에 업로드한 노무사 원본 양식을 내려보냅니다.
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/labor_consultant_helpers.php';

if (!\App\Core\Auth::check()) {
    header('Location: ?r=login');
    exit;
}

$pdo = \App\Core\Db::pdo();
$user = \App\Core\Auth::user();
if (!cpms_labor_consultant_can_access($pdo, $user)) {
    http_response_code(403);
    cpms_labor_consultant_render_message_page('이 양식을 내려받을 권한이 없습니다.');
}

$templateId = isset($_GET['template_id']) ? (int)$_GET['template_id'] : 0;
$templateRow = cpms_labor_consultant_find_template($pdo, $templateId);
if (!$templateRow) {
    http_response_code(404);
    cpms_labor_consultant_render_message_page('요청한 양식 이력을 찾을 수 없습니다.');
}

$filePath = cpms_labor_consultant_safe_template_path($templateRow);
if ($filePath === '') {
    http_response_code(404);
    cpms_labor_consultant_render_message_page('서버에 보관된 원본 양식 파일을 찾을 수 없습니다. 양식 이력의 Drive 다운로드를 이용해 주세요.');
}

// 파일: app/views/admin/labor_consultant_template_download.php
// 양식은 크기가 작은 엑셀 파일이므로 응답 전에 전체 읽기를 완료합니다.
// fread()가 빈 문자열을 계속 반환할 때 다운로드가 끝나지 않던 반복 스트리밍 문제를 방지합니다.
// 인증 확인이 끝난 뒤 세션 잠금을 풀어 다른 화면 요청과 다운로드가 서로 기다리지 않게 합니다.
if (session_id() !== '') {
    @session_write_close();
}
$fileContents = @file_get_contents($filePath);
if ($fileContents === false) {
    error_log('[labor_consultant_template_download] Failed to read template file: template_id=' . (int)$templateId);
    http_response_code(500);
    cpms_labor_consultant_render_message_page('원본 양식 파일을 읽지 못했습니다. 잠시 후 다시 시도해 주세요.');
}

$downloadName = isset($templateRow['original_name']) ? trim((string)$templateRow['original_name']) : '';
$downloadName = str_replace(array("\r", "\n", '"'), '', $downloadName);
if ($downloadName === '') $downloadName = '노무사_확인용_양식.xlsx';
if (strtolower(pathinfo($downloadName, PATHINFO_EXTENSION)) !== 'xlsx') $downloadName .= '.xlsx';

while (ob_get_level() > 0) {
    if (!@ob_end_clean()) break;
}
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . rawurlencode($downloadName) . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
header('Content-Length: ' . (string)strlen($fileContents));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
echo $fileContents;
exit;
