<?php
/**
 * Lease overhead Excel upload action.
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    flash_set('danger', '보안 토큰이 올바르지 않습니다.');
    header('Location: ?r=' . urlencode('관리') . '&tab=company_overhead&oh=lease');
    exit;
}

$year = cpms_company_overhead_normalize_upload_year(isset($_POST['apply_year']) ? $_POST['apply_year'] : date('Y'));
$month = cpms_company_overhead_normalize_upload_month(isset($_POST['apply_month']) ? $_POST['apply_month'] : 1);
$file = isset($_FILES['lease_file']) ? $_FILES['lease_file'] : null;
$result = cpms_company_overhead_import_lease_xlsx($year, $file, $user, $month);
if (isset($_SESSION['_company_profit_cache'])) unset($_SESSION['_company_profit_cache']);
$redirectYear = isset($result['year']) ? (int)$result['year'] : (int)$year;

if (!empty($result['ok'])) {
    $message = isset($result['message']) ? (string)$result['message'] : '임대차 엑셀 업로드가 반영되었습니다.';
    $message .= ' 적용연도: ' . (isset($result['year']) ? (string)$result['year'] : (string)$year);
    $message .= ' / 유효 항목: ' . (isset($result['active_count']) ? (string)(int)$result['active_count'] : '0') . '건';
    $message .= ' / 추가: ' . (isset($result['inserted']) ? (string)(int)$result['inserted'] : '0') . '건';
    $message .= ' / 갱신: ' . (isset($result['updated']) ? (string)(int)$result['updated'] : '0') . '건';
    flash_set('success', $message);
} else {
    flash_set('danger', isset($result['message']) ? (string)$result['message'] : '임대차 엑셀 업로드에 실패했습니다.');
}

header('Location: ?r=' . urlencode('관리') . '&tab=company_overhead&oh=lease&year=' . urlencode((string)$redirectYear) . '&month=' . urlencode((string)$month));
exit;
