<?php
/** Shared integrated vendor autocomplete. PHP 5.6 compatible. */
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../services/VendorService.php';

use App\Core\Auth;
use App\Core\Db;
use App\Services\VendorService;

if (!Auth::check()) { http_response_code(401); exit; }
if (function_exists('ob_get_level')) while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$length = 0;
if (function_exists('mb_strlen')) {
    $length = mb_strlen($q, 'UTF-8');
} else {
    $characters = array();
    $matched = preg_match_all('/./us', $q, $characters);
    $length = $matched !== false ? (int)$matched : strlen($q);
}
if ($length < 2) { echo json_encode(array('ok'=>true,'items'=>array())); exit; }

$pdo = Db::pdo();
$bootstrap = VendorService::bootstrap($pdo, true);
if (empty($bootstrap['ok'])) { echo json_encode(array('ok'=>true,'items'=>array())); exit; }
$presetType = isset($vendorSearchPresetType) && (string)$vendorSearchPresetType === 'equipment' ? 'equipment' : 'material';
echo json_encode(array('ok'=>true,'items'=>VendorService::search($pdo, $q, 20, $presetType)));
