<?php
/**
 * Company vehicle driver update action.
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

csrf_validate();

$id = isset($_POST['id']) ? trim((string)$_POST['id']) : '';
$year = isset($_POST['effective_year']) ? (int)$_POST['effective_year'] : (int)date('Y');
$month = isset($_POST['effective_month']) ? (int)$_POST['effective_month'] : (int)date('m');
$driverName = isset($_POST['driver_name']) ? trim((string)$_POST['driver_name']) : '';
$result = cpms_company_vehicle_update_driver($id, $year, $month, $driverName, $user);
if (isset($_SESSION['_company_profit_cache'])) unset($_SESSION['_company_profit_cache']);
flash_set(!empty($result['ok']) ? 'success' : 'danger', isset($result['message']) ? (string)$result['message'] : '운전자를 수정하지 못했습니다.');

$redirectYear = isset($_POST['redirect_year']) ? (int)$_POST['redirect_year'] : $year;
if ($redirectYear < 2026) $redirectYear = 2026;
header('Location: ?r=' . urlencode('관리') . '&tab=company_overhead&oh=vehicles&year=' . urlencode((string)$redirectYear));
exit;
