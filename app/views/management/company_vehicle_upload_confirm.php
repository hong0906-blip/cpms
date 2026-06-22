<?php
/**
 * Company vehicle upload confirm action.
 * PHP 5.6 compatible.
 */

use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/../../services/CompanyProfitAccessService.php';
require_once __DIR__ . '/../../services/CompanyVehicleService.php';

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
    header('Location: ?r=' . urlencode('관리') . '&tab=company_overhead&oh=vehicles');
    exit;
}

$token = isset($_POST['vehicle_preview_token']) ? trim((string)$_POST['vehicle_preview_token']) : '';
$preview = cpms_company_vehicle_get_preview($token);
$year = is_array($preview) && isset($preview['base_ym']) ? substr((string)$preview['base_ym'], 0, 4) : date('Y');
$result = cpms_company_vehicle_confirm_preview($token, $user);

if (empty($result['ok'])) {
    flash_set('danger', isset($result['message']) ? (string)$result['message'] : '회사차량 데이터를 확정 저장하지 못했습니다.');
    header('Location: ?r=' . urlencode('관리') . '&tab=company_overhead&oh=vehicles&year=' . urlencode($year) . ($token !== '' ? '&vehicle_preview_token=' . urlencode($token) : ''));
    exit;
}

if (isset($_SESSION['_company_profit_cache'])) unset($_SESSION['_company_profit_cache']);
flash_set('success', isset($result['message']) ? (string)$result['message'] : '회사차량 데이터가 저장되었습니다.');
header('Location: ?r=' . urlencode('관리') . '&tab=company_overhead&oh=vehicles&year=' . urlencode($year));
exit;
