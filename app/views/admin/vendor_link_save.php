<?php
/** Manual confirmation for an ambiguous legacy vendor link. PHP 5.6 compatible. */

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
    header('Location: ?r=관리&tab=vendors&legacy=1');
    exit;
}

$vendorId = isset($_POST['vendor_id']) ? (int)$_POST['vendor_id'] : 0;
$token = isset($_POST['legacy_token']) ? (string)$_POST['legacy_token'] : '';
$count = VendorService::linkLegacyToken(Db::pdo(), $token, $vendorId);
if ($count > 0) flash_set('success', '기존 업체 자료 ' . (int)$count . '건을 선택한 업체 마스터에 연결했습니다. 거래내용은 변경하지 않았습니다.');
else flash_set('error', '연결할 기존 업체 자료를 찾지 못했거나 업체 선택이 올바르지 않습니다.');
header('Location: ?r=관리&tab=vendors&legacy=1');
exit;
