<?php
/**
 * Company vehicle upload preview action.
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

$baseYear = isset($_POST['base_year']) ? (int)$_POST['base_year'] : (int)date('Y');
$baseMonth = isset($_POST['base_month']) ? (int)$_POST['base_month'] : (int)date('m');
$file = isset($_FILES['vehicle_file']) ? $_FILES['vehicle_file'] : null;
$result = cpms_company_vehicle_create_preview($baseYear, $baseMonth, $file, $user);

if (empty($result['ok'])) {
    flash_set('danger', isset($result['message']) ? (string)$result['message'] : '회사차량 미리보기를 생성하지 못했습니다.');
    header('Location: ?r=' . urlencode('관리') . '&tab=company_overhead&oh=vehicles&year=' . urlencode((string)$baseYear));
    exit;
}

flash_set('success', '회사차량 미리보기가 생성되었습니다. 확인 후 확정 저장해주세요.');
header('Location: ?r=' . urlencode('관리') . '&tab=company_overhead&oh=vehicles&year=' . urlencode(substr($result['base_ym'], 0, 4)) . '&vehicle_preview_token=' . urlencode($result['token']));
exit;
