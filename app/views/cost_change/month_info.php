<?php
/**
 * 비용 귀속월/잠금 즉시 표시 JSON API.
 * PHP 5.6 호환.
 */

require_once __DIR__ . '/_common.php';
cpms_cost_change_require_login();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$costType = isset($_GET['cost_type']) ? trim((string)$_GET['cost_type']) : '';
$date = isset($_GET['use_date']) ? trim((string)$_GET['use_date']) : '';
$ym = CostChangeService::settlementYm($costType, $date);
if ($ym === '') {
    http_response_code(400);
    echo CostChangeService::jsonEncode(array('ok'=>false,'message'=>'날짜가 올바르지 않습니다.'));
    exit;
}
$info = CostChangeService::lockInfo($costType, $date, $ym, date('Y-m-d'));
$info['ok'] = true;
echo CostChangeService::jsonEncode($info);
exit;

