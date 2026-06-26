<?php
/**
 * Save a monthly manual payroll total.
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

$year = isset($_POST['year']) ? (int)$_POST['year'] : (int)date('Y');
$month = isset($_POST['month']) ? (int)$_POST['month'] : (int)date('m');
$amountRaw = isset($_POST['manual_total_amount']) ? trim((string)$_POST['manual_total_amount']) : '';

if ($amountRaw === '') {
    flash_set('danger', '월별 급여 총액을 입력해 주세요.');
    header('Location: ?r=' . urlencode('관리') . '&tab=company_overhead&oh=payroll&year=' . urlencode((string)$year) . '&month=' . urlencode((string)$month));
    exit;
}

$result = cpms_company_payroll_save_manual_total($year, $month, $amountRaw, $user);
if (empty($result['ok'])) {
    flash_set('danger', isset($result['message']) ? $result['message'] : '월별 급여 총액 저장에 실패했습니다.');
} else {
    if (isset($_SESSION['_company_profit_cache'])) unset($_SESSION['_company_profit_cache']);
    flash_set('success', isset($result['message']) ? $result['message'] : '월별 급여 총액이 저장되었습니다.');
}

header('Location: ?r=' . urlencode('관리') . '&tab=company_overhead&oh=payroll&year=' . urlencode((string)$year) . '&month=' . urlencode((string)$month));
exit;
