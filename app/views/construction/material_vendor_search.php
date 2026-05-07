<?php
/** 업체 검색 자동완성/공용프리셋 */
require_once __DIR__ . '/../../bootstrap.php';
use App\Core\Auth; use App\Core\Db;
if (!Auth::check()) { http_response_code(401); exit; }
header('Content-Type: application/json; charset=utf-8');
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
if (mb_strlen($q, 'UTF-8') < 2) { echo '[]'; exit; }
$pdo = Db::pdo(); if (!$pdo) { echo '[]'; exit; }
$st = $pdo->prepare("SELECT vendor_name, category, representative, phone, biz_no, base_rate, remark FROM cpms_material_vendor_presets WHERE vendor_name LIKE :q ORDER BY vendor_name ASC LIMIT 20");
$st->bindValue(':q', '%' . $q . '%'); $st->execute();
echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));