<?php
/**
 * Save a manual fuel vehicle display name.
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
    echo '접근 권한이 없습니다.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    flash_set('danger', '보안 토큰이 올바르지 않습니다.');
    header('Location: ?r=' . urlencode('관리') . '&tab=company_overhead&oh=fuel');
    exit;
}

$year = isset($_POST['year']) ? (int)$_POST['year'] : (int)date('Y');
$month = isset($_POST['month']) ? (int)$_POST['month'] : (int)date('m');
$vehicleNumber = isset($_POST['vehicle_number']) ? trim((string)$_POST['vehicle_number']) : '';
$displayName = isset($_POST['display_name']) ? trim((string)$_POST['display_name']) : '';

$result = cpms_company_fuel_save_vehicle_match($year, $month, $vehicleNumber, $displayName, $user, $pdo);
if (empty($result['ok'])) {
    flash_set('danger', isset($result['message']) ? (string)$result['message'] : '주유비 차량번호 매칭 이름을 저장하지 못했습니다.');
} else {
    flash_set('success', isset($result['message']) ? (string)$result['message'] : '주유비 차량번호 매칭 이름이 저장되었습니다.');
}

header('Location: ?r=' . urlencode('관리') . '&tab=company_overhead&oh=fuel&year=' . urlencode((string)$year) . '&month=' . urlencode((string)$month));
exit;
