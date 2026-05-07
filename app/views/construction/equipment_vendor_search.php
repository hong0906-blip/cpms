<?php
/** 업체명 자동완성 수정 */
require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { http_response_code(401); exit; }

if (function_exists('ob_get_level')) {
    while (ob_get_level() > 0) { ob_end_clean(); }
}
header('Content-Type: application/json; charset=utf-8');

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
if (mb_strlen($q, 'UTF-8') < 2) {
    echo json_encode(array('ok' => true, 'items' => array()));
    exit;
}

$pdo = Db::pdo();
if (!$pdo) { echo json_encode(array('ok' => true, 'items' => array())); exit; }

try {
    $exists = $pdo->query("SHOW TABLES LIKE 'cpms_equipment_vendor_presets'");
    if (!$exists || !$exists->fetch()) {
        echo json_encode(array('ok' => true, 'items' => array()));
        exit;
    }

    $st = $pdo->prepare("SELECT vendor_name, category, representative, phone, biz_no, base_rate, remark FROM cpms_equipment_vendor_presets WHERE vendor_name LIKE :q ORDER BY vendor_name ASC LIMIT 20");
    $st->bindValue(':q', '%' . $q . '%');
    $st->execute();
    echo json_encode(array('ok' => true, 'items' => $st->fetchAll(PDO::FETCH_ASSOC)));
} catch (Exception $e) {
    echo json_encode(array('ok' => true, 'items' => array()));
}