<?php
/**
 * Payroll ledger upload confirm action.
 * PHP 5.6 compatible.
 */

use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/../../services/CompanyPayrollAccessService.php';
require_once __DIR__ . '/../../services/CompanyPayrollService.php';

if (!Auth::check()) {
    header('Location: ?r=login');
    exit;
}

$pdo = Db::pdo();
$user = Auth::user();
if (!cpms_can_edit_company_payroll($user, $pdo)) {
    http_response_code(403);
    echo '접근 권한이 없습니다.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    flash_set('danger', '보안 토큰이 올바르지 않습니다.');
    header('Location: ?r=' . urlencode('관리') . '&tab=company_overhead&oh=payroll');
    exit;
}

$token = isset($_POST['preview_token']) ? trim((string)$_POST['preview_token']) : '';
$preview = cpms_company_payroll_get_preview($token);
$year = is_array($preview) && isset($preview['effective_year']) ? (string)$preview['effective_year'] : date('Y');
$month = is_array($preview) && isset($preview['effective_month']) ? (string)$preview['effective_month'] : date('m');
$result = cpms_company_payroll_confirm_preview($token, $user);

if (empty($result['ok'])) {
    flash_set('danger', isset($result['message']) ? $result['message'] : '급여대장을 확정 저장하지 못했습니다.');
    header('Location: ?r=' . urlencode('관리') . '&tab=company_overhead&oh=payroll&year=' . urlencode($year) . '&month=' . urlencode((string)(int)$month) . ($token !== '' ? '&preview_token=' . urlencode($token) : ''));
    exit;
}

if (isset($_SESSION['_company_profit_cache'])) unset($_SESSION['_company_profit_cache']);
flash_set('success', '급여 기준월 버전이 확정 저장되었습니다.');
header('Location: ?r=' . urlencode('관리') . '&tab=company_overhead&oh=payroll&year=' . urlencode($year) . '&month=' . urlencode((string)(int)$month));
exit;
