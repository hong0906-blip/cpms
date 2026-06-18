<?php
/**
 * Company overhead soft-delete action.
 * PHP 5.6 compatible.
 */

use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/../../services/CompanyProfitAccessService.php';
require_once __DIR__ . '/../../services/CompanyOverheadService.php';

if (!Auth::check()) {
    header('Location: ?r=login');
    exit;
}

$pdo = Db::pdo();
$user = Auth::user();
if (!cpms_can_edit_company_overhead($user, $pdo)) {
    http_response_code(403);
    echo '접근 권한이 없습니다.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

csrf_validate();

$category = isset($_POST['category']) ? trim((string)$_POST['category']) : '';
$id = isset($_POST['id']) ? trim((string)$_POST['id']) : '';
$year = isset($_POST['year']) ? (int)$_POST['year'] : 0;
$month = isset($_POST['month']) ? (int)$_POST['month'] : 0;

if ($category === 'payroll') {
    flash_set('danger', '임직원 월급은 급여대장 기준월 버전으로 관리됩니다.');
    header('Location: ?r=' . urlencode('관리') . '&tab=company_overhead&oh=payroll');
    exit;
}

$result = cpms_company_overhead_delete($category, $id, $year, $month, $user);
if (isset($_SESSION['_company_profit_cache'])) unset($_SESSION['_company_profit_cache']);
if (!empty($result['ok'])) {
    flash_set('success', isset($result['message']) ? (string)$result['message'] : '삭제되었습니다.');
} else {
    flash_set('danger', isset($result['message']) ? (string)$result['message'] : '삭제하지 못했습니다.');
}

$url = '?r=' . urlencode('관리') . '&tab=company_overhead&oh=' . urlencode($category);
if ($year > 0) $url .= '&year=' . urlencode((string)$year);
if ($month > 0) $url .= '&month=' . urlencode((string)$month);
header('Location: ' . $url);
exit;
