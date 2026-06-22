<?php
/**
 * Lease overhead upload confirm action.
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

$token = isset($_POST['preview_token']) ? trim((string)$_POST['preview_token']) : '';
$preview = cpms_company_overhead_get_lease_preview($token);
$year = is_array($preview) && isset($preview['year']) ? (string)$preview['year'] : date('Y');
$month = is_array($preview) && isset($preview['month']) ? (string)$preview['month'] : '1';
$result = cpms_company_overhead_confirm_lease_preview($token, $user);
if (isset($_SESSION['_company_profit_cache'])) unset($_SESSION['_company_profit_cache']);

if (empty($result['ok'])) {
    flash_set('danger', isset($result['message']) ? (string)$result['message'] : '임대차 데이터를 확정 저장하지 못했습니다.');
    header('Location: ?r=' . urlencode('관리') . '&tab=company_overhead&oh=lease&year=' . urlencode($year) . '&month=' . urlencode((string)(int)$month) . ($token !== '' ? '&lease_preview_token=' . urlencode($token) : ''));
    exit;
}

$message = isset($result['message']) ? (string)$result['message'] : '임대차 엑셀 업로드가 반영되었습니다.';
$message .= ' / 유효 항목: ' . (isset($result['active_count']) ? (string)(int)$result['active_count'] : '0') . '건';
$message .= ' / 추가: ' . (isset($result['inserted']) ? (string)(int)$result['inserted'] : '0') . '건';
$message .= ' / 갱신: ' . (isset($result['updated']) ? (string)(int)$result['updated'] : '0') . '건';
flash_set('success', $message);
header('Location: ?r=' . urlencode('관리') . '&tab=company_overhead&oh=lease&year=' . urlencode($year) . '&month=' . urlencode((string)(int)$month));
exit;
