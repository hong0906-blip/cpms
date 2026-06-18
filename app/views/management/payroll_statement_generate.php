<?php
/**
 * Generate payroll statement PDFs for a month or one employee.
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
if (!cpms_can_generate_payroll_statement_pdf($user, $pdo)) {
    http_response_code(403);
    echo '접근 권한이 없습니다.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    flash_set('danger', '보안 토큰이 올바르지 않습니다.');
    header('Location: ?r=' . urlencode('관리') . '&tab=company_overhead&oh=payroll');
    exit;
}

$year = isset($_POST['year']) ? (int)$_POST['year'] : (int)date('Y');
$month = isset($_POST['month']) ? (int)$_POST['month'] : (int)date('m');
$employeeKey = isset($_POST['employee_key']) ? trim((string)$_POST['employee_key']) : '';
$force = isset($_POST['force']) && (string)$_POST['force'] === '1';
$result = cpms_payroll_statement_generate_month($year, $month, $user, array(
    'force' => $force,
    'employee_key' => $employeeKey,
    'mode' => 'manual'
));

if (empty($result['ok'])) {
    flash_set('danger', isset($result['message']) ? $result['message'] : '급여명세서 PDF 생성에 실패했습니다.');
} else if (!empty($result['skipped'])) {
    flash_set('success', isset($result['message']) ? $result['message'] : '이미 생성된 급여명세서가 있습니다.');
} else {
    flash_set('success', isset($result['message']) ? $result['message'] : '급여명세서 PDF 생성이 완료되었습니다.');
}

header('Location: ?r=' . urlencode('관리') . '&tab=company_overhead&oh=payroll&year=' . urlencode((string)$year) . '&month=' . urlencode((string)$month));
exit;
