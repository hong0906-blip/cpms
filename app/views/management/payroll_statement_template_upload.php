<?php
/**
 * Payroll statement XLSX template upload action.
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

$year = isset($_POST['year']) ? (int)$_POST['year'] : (int)date('Y');
$month = isset($_POST['month']) ? (int)$_POST['month'] : (int)date('m');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    flash_set('danger', '보안 토큰이 올바르지 않습니다.');
    header('Location: ?r=' . urlencode('관리') . '&tab=company_overhead&oh=payroll&year=' . urlencode((string)$year) . '&month=' . urlencode((string)$month));
    exit;
}

$file = isset($_FILES['statement_template_file']) ? $_FILES['statement_template_file'] : null;
$result = cpms_payroll_statement_template_save_upload($file, $user);

if (empty($result['ok'])) {
    flash_set('danger', isset($result['message']) ? $result['message'] : '급여명세서 양식을 저장하지 못했습니다.');
} else {
    cpms_company_payroll_invalidate_statement_result($year, $month, 'payroll_statement_template_changed');
    flash_set('success', isset($result['message']) ? $result['message'] : '급여명세서 양식을 저장했습니다.');
}

header('Location: ?r=' . urlencode('관리') . '&tab=company_overhead&oh=payroll&year=' . urlencode((string)$year) . '&month=' . urlencode((string)$month));
exit;
