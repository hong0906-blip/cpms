<?php
/**
 * 품목별 추천단가 조회(JSON)
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/helpers.php';

use App\Core\Db;

cpms_estimate_require_access(true);

$pdo = Db::pdo();
if (!$pdo || !cpms_estimate_tables_ready($pdo)) {
    cpms_estimate_json_exit(false, '견적관리 DB 설정이 필요합니다.', array(), 500);
}

$params = array(
    'work_type' => cpms_estimate_request_string('work_type', ''),
    'item_name' => cpms_estimate_request_string('item_name', ''),
    'spec' => cpms_estimate_request_string('spec', ''),
    'unit' => cpms_estimate_request_string('unit', ''),
    'client' => cpms_estimate_request_string('client', ''),
    'section_name' => cpms_estimate_request_string('section_name', ''),
    'contractor' => cpms_estimate_request_string('contractor', ''),
);

if ($params['item_name'] === '' || $params['unit'] === '') {
    cpms_estimate_json_exit(false, '품명과 단위가 필요합니다.', array(), 400);
}

try {
    $recommendation = cpms_estimate_recommend($pdo, $params);
    cpms_estimate_json_exit(true, 'OK', array('recommendation' => $recommendation), 200);
} catch (Exception $e) {
    cpms_estimate_json_exit(false, '추천단가 조회 실패: ' . $e->getMessage(), array(), 500);
}
