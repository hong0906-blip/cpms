<?php
/**
 * Fuel overhead upload confirm action.
 * PHP 5.6 compatible.
 */

use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/../../services/CompanyProfitAccessService.php';
require_once __DIR__ . '/../../services/CompanyFuelService.php';

if (!Auth::check()) {
    header('Location: ?r=login');
    exit;
}

$pdo = Db::pdo();
$user = Auth::user();
if (!cpms_can_edit_company_overhead($user, $pdo)) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    flash_set('danger', '보안 토큰이 올바르지 않습니다.');
    header('Location: ?r=' . urlencode('관리') . '&tab=company_overhead&oh=fuel');
    exit;
}

$token = isset($_POST['preview_token']) ? trim((string)$_POST['preview_token']) : '';
$preview = cpms_company_fuel_get_preview($token);
$year = is_array($preview) && isset($preview['year']) ? (string)$preview['year'] : date('Y');
$month = is_array($preview) && isset($preview['month']) ? (string)$preview['month'] : date('m');
$result = cpms_company_fuel_confirm_preview($token, $user);

if (empty($result['ok'])) {
    flash_set('danger', isset($result['message']) ? (string)$result['message'] : '주유비 데이터를 확정 저장하지 못했습니다.');
    header('Location: ?r=' . urlencode('관리') . '&tab=company_overhead&oh=fuel&year=' . urlencode($year) . '&month=' . urlencode((string)(int)$month) . ($token !== '' ? '&preview_token=' . urlencode($token) : ''));
    exit;
}

flash_set('success', isset($result['message']) ? (string)$result['message'] : '주유비 데이터가 저장되었습니다.');
header('Location: ?r=' . urlencode('관리') . '&tab=company_overhead&oh=fuel&year=' . urlencode($year) . '&month=' . urlencode((string)(int)$month));
exit;
