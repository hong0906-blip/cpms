<?php
/** Integrated vendor create/update action. PHP 5.6 compatible. */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../services/VendorService.php';

use App\Core\Auth;
use App\Core\Db;
use App\Services\VendorService;

if (!Auth::check()) { header('Location: ?r=login'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    flash_set('error', '보안 토큰이 유효하지 않습니다.');
    header('Location: ?r=공사');
    exit;
}

$context = isset($_POST['context']) ? trim((string)$_POST['context']) : 'construction';
$isManagement = ($context === 'management');
$isDevelopmentDepartment = Auth::isDevelopmentDepartment();
if ($isManagement) {
    if (!Auth::isMaster() && !Auth::canManageEmployees()) {
        http_response_code(403);
        echo '403 Forbidden';
        exit;
    }
} else if (!Auth::canManageConstruction()) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$vendorId = isset($_POST['vendor_id']) ? (int)$_POST['vendor_id'] : 0;
if (!$isManagement) $vendorId = 0;
$redirect = $isManagement
    ? '?r=관리&tab=vendors'
    : '?r=공사&pid=' . $projectId . '&tab=vendors';

if (!$isManagement && !$isDevelopmentDepartment) {
    $requiredFields = array(
        'business_no' => '사업자등록번호',
        'name' => '업체명',
        'description' => '내역',
        'representative' => '대표자명',
        'phone' => '전화번호',
        'bank_name' => '은행',
        'account_number' => '계좌번호',
        'account_holder' => '예금주'
    );
    $missingFields = array();
    foreach ($requiredFields as $field => $label) {
        if (!isset($_POST[$field]) || trim((string)$_POST[$field]) === '') $missingFields[] = $label;
    }
    if (count($missingFields) > 0) {
        flash_set('error', '업체 등록 필수항목을 모두 입력해주세요. 누락: ' . implode(', ', $missingFields));
        header('Location: ' . $redirect);
        exit;
    }
}

$pdo = Db::pdo();
$result = VendorService::saveVendor($pdo, $vendorId, array(
    'business_no' => isset($_POST['business_no']) ? $_POST['business_no'] : '',
    'name' => isset($_POST['name']) ? $_POST['name'] : '',
    'description' => isset($_POST['description']) ? $_POST['description'] : '',
    'representative' => isset($_POST['representative']) ? $_POST['representative'] : '',
    'phone' => isset($_POST['phone']) ? $_POST['phone'] : '',
    'bank_name' => isset($_POST['bank_name']) ? $_POST['bank_name'] : '',
    'account_number' => isset($_POST['account_number']) ? $_POST['account_number'] : '',
    'account_holder' => isset($_POST['account_holder']) ? $_POST['account_holder'] : ''
), array(
    'name' => (string)Auth::userName(),
    'email' => (string)Auth::userEmail()
), $isManagement ? 'management' : 'construction');

if (!empty($result['ok'])) {
    flash_set('success', isset($result['message']) ? $result['message'] : '업체정보를 저장했습니다.');
    if ($isManagement) $redirect .= '&edit=' . (int)$result['id'];
} else {
    flash_set('error', isset($result['message']) ? $result['message'] : '업체정보를 저장하지 못했습니다.');
    if ($isManagement && isset($result['duplicate_id']) && (int)$result['duplicate_id'] > 0) {
        $redirect .= '&edit=' . (int)$result['duplicate_id'];
    } else if (!$isManagement && isset($result['duplicate_id']) && (int)$result['duplicate_id'] > 0) {
        $duplicateVendor = VendorService::getById($pdo, (int)$result['duplicate_id']);
        if (is_array($duplicateVendor) && isset($duplicateVendor['name'])) $redirect .= '&vendor_q=' . urlencode((string)$duplicateVendor['name']);
    } else if ($isManagement && $vendorId > 0) {
        $redirect .= '&edit=' . $vendorId;
    } else if ($isManagement) {
        $redirect .= '&mode=create';
    }
}
header('Location: ' . $redirect);
exit;
