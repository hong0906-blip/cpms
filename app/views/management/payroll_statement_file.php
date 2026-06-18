<?php
/**
 * Serve stored payroll statement PDF/ZIP after permission check.
 * PHP 5.6 compatible.
 */

use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/../../services/CompanyPayrollAccessService.php';
require_once __DIR__ . '/../../services/PayrollStatementService.php';

if (!Auth::check()) {
    header('Location: ?r=login');
    exit;
}

$pdo = Db::pdo();
$user = Auth::user();
if (!cpms_can_download_payroll_statement_pdf($user, $pdo)) {
    http_response_code(403);
    echo '접근 권한이 없습니다.';
    exit;
}

$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$type = isset($_GET['type']) ? trim((string)$_GET['type']) : 'pdf';
$action = isset($_GET['action']) ? trim((string)$_GET['action']) : 'download';
$employeeKey = isset($_GET['employee_key']) ? trim((string)$_GET['employee_key']) : '';
$record = cpms_payroll_statement_load_month($year, $month);
if (!is_array($record)) {
    http_response_code(404);
    echo '생성된 급여명세서 파일이 없습니다.';
    exit;
}

$fileId = '';
$fileName = '';
$mimeType = 'application/pdf';
$viewLink = '';
if ($type === 'zip') {
    $fileId = isset($record['zip_drive_file_id']) ? trim((string)$record['zip_drive_file_id']) : '';
    $fileName = isset($record['zip_name']) ? trim((string)$record['zip_name']) : sprintf('%04d%02d_급여명세서_전체.zip', (int)$year, (int)$month);
    $mimeType = 'application/zip';
    $viewLink = isset($record['zip_drive_web_view_link']) ? trim((string)$record['zip_drive_web_view_link']) : '';
} else {
    $item = cpms_payroll_statement_find_item($record, $employeeKey);
    if (!is_array($item) || !isset($item['status']) || (string)$item['status'] !== 'success') {
        http_response_code(404);
        echo '생성된 직원별 급여명세서 PDF가 없습니다.';
        exit;
    }
    $fileId = isset($item['drive_file_id']) ? trim((string)$item['drive_file_id']) : '';
    $fileName = isset($item['pdf_name']) ? trim((string)$item['pdf_name']) : 'payroll_statement.pdf';
    $mimeType = 'application/pdf';
    $viewLink = isset($item['drive_web_view_link']) ? trim((string)$item['drive_web_view_link']) : '';
}

if ($action === 'view') {
    if ($viewLink !== '') {
        header('Location: ' . $viewLink);
        exit;
    }
    $action = 'download';
}

if ($fileId === '') {
    http_response_code(404);
    echo 'Drive 파일 ID가 없습니다.';
    exit;
}

$download = cpms_payroll_statement_stream_drive_file($fileId, $fileName, $mimeType);
if (empty($download['ok'])) {
    http_response_code(500);
    echo 'Drive 파일 다운로드에 실패했습니다.';
    exit;
}

$content = isset($download['content']) ? (string)$download['content'] : '';
header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . rawurlencode($fileName) . '"');
header('Content-Length: ' . (string)strlen($content));
echo $content;
exit;
