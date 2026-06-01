<?php
/**
 * 견적 품목 검색(JSON)
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

$q = cpms_estimate_request_string('q', '');
$workType = cpms_estimate_request_string('work_type', '');
$unit = cpms_estimate_request_string('unit', '');
$client = cpms_estimate_request_string('client', '');
$sectionName = cpms_estimate_request_string('section_name', '');
$contractor = cpms_estimate_request_string('contractor', '');

$where = array('reflect_yn = 1', 'unit_price > 0', "TRIM(item_name) <> ''", "TRIM(unit) <> ''");
$bind = array();

if ($q !== '') {
    $where[] = "(item_name LIKE :q OR spec LIKE :q OR work_type LIKE :q)";
    $bind[':q'] = '%' . $q . '%';
}
if ($workType !== '') {
    $where[] = "work_type LIKE :work_type";
    $bind[':work_type'] = '%' . $workType . '%';
}
if ($unit !== '') {
    $where[] = "unit LIKE :unit";
    $bind[':unit'] = '%' . $unit . '%';
}
if ($client !== '') {
    $where[] = "client LIKE :client";
    $bind[':client'] = '%' . $client . '%';
}
if ($sectionName !== '') {
    $where[] = "section_name LIKE :section_name";
    $bind[':section_name'] = '%' . $sectionName . '%';
}
if ($contractor !== '') {
    $where[] = "contractor LIKE :contractor";
    $bind[':contractor'] = '%' . $contractor . '%';
}

try {
    $sql = "SELECT work_type, item_name, spec, unit,
                   COUNT(*) AS history_count,
                   MAX(id) AS latest_id,
                   MAX(contract_date) AS latest_date
              FROM cpms_estimate_price_history
             WHERE " . implode(' AND ', $where) . "
             GROUP BY work_type, item_name, spec, unit
             ORDER BY latest_id DESC
             LIMIT 80";
    $st = $pdo->prepare($sql);
    foreach ($bind as $k => $v) $st->bindValue($k, $v);
    $st->execute();
    $groups = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($groups)) $groups = array();

    $items = array();
    foreach ($groups as $g) {
        $params = array(
            'work_type' => isset($g['work_type']) ? (string)$g['work_type'] : '',
            'item_name' => isset($g['item_name']) ? (string)$g['item_name'] : '',
            'spec' => isset($g['spec']) ? (string)$g['spec'] : '',
            'unit' => isset($g['unit']) ? (string)$g['unit'] : '',
        );
        $recommend = cpms_estimate_recommend($pdo, $params);
        $items[] = array(
            'work_type' => $params['work_type'],
            'item_name' => $params['item_name'],
            'spec' => $params['spec'],
            'unit' => $params['unit'],
            'history_count' => (int)$g['history_count'],
            'latest_date' => isset($g['latest_date']) ? (string)$g['latest_date'] : '',
            'recommendation' => $recommend,
        );
    }

    cpms_estimate_json_exit(true, 'OK', array('items' => $items), 200);
} catch (Exception $e) {
    cpms_estimate_json_exit(false, '품목 검색 실패: ' . $e->getMessage(), array(), 500);
}
