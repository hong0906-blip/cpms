<?php
/** 업체명 자동완성 수정 */
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../safety/safety_cost_helper.php';

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

$items = array();
$seen = array();

try {
    $exists = $pdo->query("SHOW TABLES LIKE 'cpms_material_vendor_presets'");
    if ($exists && $exists->fetch()) {
        $st = $pdo->prepare("SELECT vendor_name, category, representative, phone, biz_no, base_rate, remark FROM cpms_material_vendor_presets WHERE vendor_name LIKE :q ORDER BY vendor_name ASC LIMIT 20");
        $st->bindValue(':q', '%' . $q . '%');
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) $rows = array();
        foreach ($rows as $row) {
            $vendor = isset($row['vendor_name']) ? trim((string)$row['vendor_name']) : '';
            if ($vendor === '') continue;
            $keyText = $vendor . '|' . (isset($row['phone']) ? (string)$row['phone'] : '');
            $key = function_exists('mb_strtolower') ? mb_strtolower($keyText, 'UTF-8') : strtolower($keyText);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $items[count($items)] = $row;
        }
    }
} catch (Exception $e) {
    $items = array();
    $seen = array();
}

try {
    $safetyRows = cpms_safety_cost_all_items();
    foreach ($safetyRows as $row) {
        if (count($items) >= 20) break;
        if (!is_array($row) || !cpms_safety_cost_is_active($row)) continue;
        $projectId = isset($row['project_id']) ? (int)$row['project_id'] : 0;
        if ($projectId > 0 && !cpms_safety_cost_user_can_view_project($pdo, $projectId)) continue;
        $vendor = isset($row['vendor_name']) ? trim((string)$row['vendor_name']) : '';
        if ($vendor === '') continue;
        $haystack = function_exists('mb_strtolower') ? mb_strtolower($vendor, 'UTF-8') : strtolower($vendor);
        $needle = function_exists('mb_strtolower') ? mb_strtolower($q, 'UTF-8') : strtolower($q);
        if ($needle !== '' && strpos($haystack, $needle) === false) continue;
        $phone = isset($row['phone']) ? (string)$row['phone'] : '';
        $key = (function_exists('mb_strtolower') ? mb_strtolower($vendor . '|' . $phone, 'UTF-8') : strtolower($vendor . '|' . $phone));
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $items[count($items)] = array(
            'vendor_name' => $vendor,
            'category' => '안전관리비',
            'representative' => isset($row['representative']) ? (string)$row['representative'] : '',
            'phone' => $phone,
            'biz_no' => isset($row['biz_no']) ? (string)$row['biz_no'] : '',
            'base_rate' => '',
            'remark' => isset($row['remark']) ? (string)$row['remark'] : ''
        );
    }
} catch (Exception $e) {}

echo json_encode(array('ok' => true, 'items' => $items));
