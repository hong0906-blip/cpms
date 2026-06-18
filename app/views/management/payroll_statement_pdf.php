<?php
/**
 * Payroll statement PDF download.
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
if (!cpms_can_view_company_payroll($user, $pdo)) {
    http_response_code(403);
    echo '접근 권한이 없습니다.';
    exit;
}

$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$employeeKey = isset($_GET['employee_key']) ? trim((string)$_GET['employee_key']) : '';
$data = cpms_payroll_statement_data($year, $month, $employeeKey);
if (empty($data['ok'])) {
    http_response_code(404);
    echo h(isset($data['message']) ? $data['message'] : '급여명세서를 찾지 못했습니다.');
    exit;
}

$pdf = cpms_payroll_statement_create_pdf($data, $user);
if (empty($pdf['ok']) || !isset($pdf['path']) || !is_file($pdf['path'])) {
    http_response_code(500);
    echo 'PDF 생성에 실패했습니다. 인쇄 화면을 이용해주세요.';
    exit;
}

$fileName = isset($pdf['name']) ? (string)$pdf['name'] : basename($pdf['path']);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . rawurlencode($fileName) . '"');
header('Content-Length: ' . (string)filesize($pdf['path']));
readfile($pdf['path']);
if (function_exists('cpms_approval_pdf_cleanup_temp_file')) {
    cpms_approval_pdf_cleanup_temp_file($pdf['path']);
} else {
    @unlink($pdf['path']);
}
exit;
