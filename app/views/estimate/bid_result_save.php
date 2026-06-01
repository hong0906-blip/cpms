<?php
/**
 * 입찰 결과 저장
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/helpers.php';

use App\Core\Db;

cpms_estimate_require_access(false);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    flash_set('error', '보안 토큰이 유효하지 않습니다.');
    header('Location: ?r=estimate_home&tab=bid_result');
    exit;
}

function cpms_estimate_bid_bind_decimal($st, $key, $value)
{
    if ($value === null) $st->bindValue($key, null, PDO::PARAM_NULL);
    else $st->bindValue($key, $value);
}

$pdo = Db::pdo();
if (!$pdo || !cpms_estimate_tables_ready($pdo)) {
    flash_set('error', '견적관리 DB 설정이 필요합니다.');
    header('Location: ?r=estimate_home&tab=bid_result');
    exit;
}

$estimateId = isset($_POST['estimate_id']) ? (int)$_POST['estimate_id'] : 0;
$estimate = cpms_estimate_get_estimate($pdo, $estimateId);
if (!$estimate) {
    flash_set('error', '견적서를 찾을 수 없습니다.');
    header('Location: ?r=estimate_home&tab=bid_result');
    exit;
}

$bidResult = isset($_POST['bid_result']) ? trim((string)$_POST['bid_result']) : '보류';
$options = cpms_estimate_bid_result_options();
if (!in_array($bidResult, $options, true)) $bidResult = '보류';

$finalContractAmount = cpms_estimate_parse_number(isset($_POST['final_contract_amount']) ? $_POST['final_contract_amount'] : '');
$failureReason = isset($_POST['failure_reason']) ? trim((string)$_POST['failure_reason']) : '';
$specialNote = isset($_POST['special_note']) ? trim((string)$_POST['special_note']) : '';
$reflectYn = isset($_POST['reflect_yn']) ? (int)$_POST['reflect_yn'] : 1;
$reflectYn = $reflectYn === 1 ? 1 : 0;
$postedItems = (isset($_POST['items']) && is_array($_POST['items'])) ? $_POST['items'] : array();
$estimateItems = cpms_estimate_get_items($pdo, $estimateId);
$user = cpms_estimate_user_meta();

try {
    $pdo->beginTransaction();
    $now = date('Y-m-d H:i:s');

    $st = $pdo->prepare("INSERT INTO cpms_estimate_bid_results
        (estimate_id, bid_result, final_contract_amount, failure_reason, special_note, reflect_yn, created_by, created_by_name, created_by_email, created_at)
        VALUES
        (:estimate_id, :bid_result, :final_contract_amount, :failure_reason, :special_note, :reflect_yn, :created_by, :created_by_name, :created_by_email, :created_at)");
    $st->bindValue(':estimate_id', $estimateId, PDO::PARAM_INT);
    $st->bindValue(':bid_result', $bidResult);
    cpms_estimate_bid_bind_decimal($st, ':final_contract_amount', $finalContractAmount);
    $st->bindValue(':failure_reason', $failureReason);
    $st->bindValue(':special_note', $specialNote);
    $st->bindValue(':reflect_yn', $reflectYn, PDO::PARAM_INT);
    $st->bindValue(':created_by', (int)$user['id'], PDO::PARAM_INT);
    $st->bindValue(':created_by_name', $user['name']);
    $st->bindValue(':created_by_email', $user['email']);
    $st->bindValue(':created_at', $now);
    $st->execute();

    $bidResultId = (int)$pdo->lastInsertId();

    $itemSt = $pdo->prepare("INSERT INTO cpms_estimate_bid_result_items
        (bid_result_id, estimate_item_id, work_type, item_name, spec, unit, qty, program_recommended_unit_price, submitted_unit_price, final_contract_unit_price, reflect_yn, created_at)
        VALUES
        (:bid_result_id, :estimate_item_id, :work_type, :item_name, :spec, :unit, :qty, :program_recommended_unit_price, :submitted_unit_price, :final_contract_unit_price, :reflect_yn, :created_at)");

    $historySt = $pdo->prepare("INSERT INTO cpms_estimate_price_history
        (work_type, item_name, spec, unit, client, section_name, contractor, price_type, source_type, source_name, unit_price, contract_date, bid_result, reflect_yn, created_by, created_by_name, created_by_email, created_at, remark)
        VALUES
        (:work_type, :item_name, :spec, :unit, :client, :section_name, :contractor, 'contract', 'bid_result', :source_name, :unit_price, :contract_date, :bid_result, 1, :created_by, :created_by_name, :created_by_email, :created_at, :remark)");

    $reflectedCount = 0;
    foreach ($estimateItems as $item) {
        $itemId = (int)$item['id'];
        $finalUnitPrice = null;
        if (isset($postedItems[$itemId]) && is_array($postedItems[$itemId])) {
            $finalUnitPrice = cpms_estimate_parse_number(isset($postedItems[$itemId]['final_contract_unit_price']) ? $postedItems[$itemId]['final_contract_unit_price'] : '');
        }

        $itemSt->bindValue(':bid_result_id', $bidResultId, PDO::PARAM_INT);
        $itemSt->bindValue(':estimate_item_id', $itemId, PDO::PARAM_INT);
        $itemSt->bindValue(':work_type', isset($item['work_type']) ? (string)$item['work_type'] : '');
        $itemSt->bindValue(':item_name', isset($item['item_name']) ? (string)$item['item_name'] : '');
        $itemSt->bindValue(':spec', isset($item['spec']) ? (string)$item['spec'] : '');
        $itemSt->bindValue(':unit', isset($item['unit']) ? (string)$item['unit'] : '');
        cpms_estimate_bid_bind_decimal($itemSt, ':qty', isset($item['qty']) ? $item['qty'] : null);
        cpms_estimate_bid_bind_decimal($itemSt, ':program_recommended_unit_price', isset($item['recommended_unit_price']) ? $item['recommended_unit_price'] : null);
        cpms_estimate_bid_bind_decimal($itemSt, ':submitted_unit_price', isset($item['submitted_unit_price']) ? $item['submitted_unit_price'] : null);
        cpms_estimate_bid_bind_decimal($itemSt, ':final_contract_unit_price', $finalUnitPrice);
        $itemSt->bindValue(':reflect_yn', $reflectYn, PDO::PARAM_INT);
        $itemSt->bindValue(':created_at', $now);
        $itemSt->execute();

        if ($reflectYn === 1 && $finalUnitPrice !== null && $finalUnitPrice > 0 && trim((string)$item['item_name']) !== '' && trim((string)$item['unit']) !== '') {
            $historySt->bindValue(':work_type', isset($item['work_type']) ? (string)$item['work_type'] : '');
            $historySt->bindValue(':item_name', (string)$item['item_name']);
            $historySt->bindValue(':spec', isset($item['spec']) ? (string)$item['spec'] : '');
            $historySt->bindValue(':unit', (string)$item['unit']);
            $historySt->bindValue(':client', isset($estimate['client']) ? (string)$estimate['client'] : '');
            $historySt->bindValue(':section_name', isset($estimate['section_name']) ? (string)$estimate['section_name'] : '');
            $historySt->bindValue(':contractor', isset($estimate['contractor']) ? (string)$estimate['contractor'] : '');
            $historySt->bindValue(':source_name', '견적#' . $estimateId . ' ' . (isset($estimate['project_name']) ? (string)$estimate['project_name'] : ''));
            $historySt->bindValue(':unit_price', $finalUnitPrice);
            $historySt->bindValue(':contract_date', date('Y-m-d'));
            $historySt->bindValue(':bid_result', $bidResult);
            $historySt->bindValue(':created_by', (int)$user['id'], PDO::PARAM_INT);
            $historySt->bindValue(':created_by_name', $user['name']);
            $historySt->bindValue(':created_by_email', $user['email']);
            $historySt->bindValue(':created_at', $now);
            $historySt->bindValue(':remark', $specialNote);
            $historySt->execute();
            $reflectedCount++;
        }
    }

    $up = $pdo->prepare("UPDATE cpms_estimates SET bid_result = :bid_result, final_contract_amount = :final_contract_amount, updated_at = :updated_at WHERE id = :id");
    $up->bindValue(':bid_result', $bidResult);
    cpms_estimate_bid_bind_decimal($up, ':final_contract_amount', $finalContractAmount);
    $up->bindValue(':updated_at', $now);
    $up->bindValue(':id', $estimateId, PDO::PARAM_INT);
    $up->execute();

    $pdo->commit();
    $msg = '입찰 결과가 저장되었습니다.';
    if ($reflectYn === 1) $msg .= ' 추천 반영 단가: ' . $reflectedCount . '건';
    flash_set('success', $msg);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash_set('error', '입찰 결과 저장 실패: ' . $e->getMessage());
}

header('Location: ?r=estimate_home&tab=history&estimate_id=' . $estimateId);
exit;
