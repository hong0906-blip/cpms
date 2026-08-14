<?php
/** Soft-delete a vendor master without deleting transactions. PHP 5.6 compatible. */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../services/VendorService.php';

use App\Core\Auth;
use App\Core\Db;
use App\Services\VendorService;

if (!Auth::check()) { header('Location: ?r=login'); exit; }
if (!Auth::isMaster() && !Auth::canManageEmployees()) { http_response_code(403); echo '403 Forbidden'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    flash_set('error', '보안 토큰이 유효하지 않습니다.');
    header('Location: ?r=관리&tab=vendors');
    exit;
}
$result = VendorService::softDeleteVendor(
    Db::pdo(),
    isset($_POST['vendor_id']) ? (int)$_POST['vendor_id'] : 0,
    array('name'=>(string)Auth::userName(),'email'=>(string)Auth::userEmail())
);
flash_set(!empty($result['ok']) ? 'success' : 'error', isset($result['message']) ? $result['message'] : '업체 삭제에 실패했습니다.');
header('Location: ?r=관리&tab=vendors');
exit;

