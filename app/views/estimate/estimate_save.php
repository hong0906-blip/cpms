<?php
/**
 * 견적서 저장
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
    header('Location: ?r=estimate_home');
    exit;
}

$pdo = Db::pdo();
if (!$pdo || !cpms_estimate_tables_ready($pdo)) {
    flash_set('error', '견적관리 DB 설정이 필요합니다.');
    header('Location: ?r=estimate_home');
    exit;
}

function cpms_estimate_save_bind_decimal($st, $key, $value)
{
    if ($value === null) $st->bindValue($key, null, PDO::PARAM_NULL);
    else $st->bindValue($key, $value);
}

$estimateDate = cpms_estimate_post_string('estimate_date', date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $estimateDate)) $estimateDate = date('Y-m-d');

$projectName = cpms_estimate_post_string('project_name', '');
if ($projectName === '') {
    flash_set('error', '공사명은 필수입니다.');
    header('Location: ?r=estimate_home');
    exit;
}

$client = cpms_estimate_post_string('client', '');
$sectionName = cpms_estimate_post_string('section_name', '');
$contractor = cpms_estimate_post_string('contractor', '');
$workCharacter = cpms_estimate_post_string('work_character', '');
$workKind = cpms_estimate_post_string('work_kind', '');
$includeIndirect = isset($_POST['include_indirect']) ? (int)$_POST['include_indirect'] : 0;
$difficulty = cpms_estimate_post_string('difficulty', '');
$estimateType = cpms_estimate_post_string('estimate_type', '');
$remark = cpms_estimate_post_string('remark', '');
$user = cpms_estimate_user_meta();

$postedItems = (isset($_POST['items']) && is_array($_POST['items'])) ? $_POST['items'] : array();
$items = array();
$totalAmount = 0.0;

foreach ($postedItems as $row) {
    if (!is_array($row)) continue;
    $itemName = isset($row['item_name']) ? trim((string)$row['item_name']) : '';
    if ($itemName === '') continue;

    $workType = isset($row['work_type']) ? trim((string)$row['work_type']) : '';
    $spec = isset($row['spec']) ? trim((string)$row['spec']) : '';
    $unit = isset($row['unit']) ? trim((string)$row['unit']) : '';
    $qty = cpms_estimate_parse_number(isset($row['qty']) ? $row['qty'] : '');
    $recommended = cpms_estimate_parse_number(isset($row['recommended_unit_price']) ? $row['recommended_unit_price'] : '');
    $submitted = cpms_estimate_parse_number(isset($row['submitted_unit_price']) ? $row['submitted_unit_price'] : '');
    $amount = null;
    if ($qty !== null && $submitted !== null) {
        $amount = $qty * $submitted;
        $totalAmount += $amount;
    }
    $rowRemark = isset($row['remark']) ? trim((string)$row['remark']) : '';
    $recommendation = cpms_estimate_recommend($pdo, array(
        'work_type' => $workType,
        'item_name' => $itemName,
        'spec' => $spec,
        'unit' => $unit,
        'client' => $client,
        'section_name' => $sectionName,
        'contractor' => $contractor,
    ));
    unset($recommendation['rows']);

    $items[] = array(
        'work_type' => $workType,
        'item_name' => $itemName,
        'spec' => $spec,
        'unit' => $unit,
        'qty' => $qty,
        'recommended_unit_price' => $recommended,
        'submitted_unit_price' => $submitted,
        'amount' => $amount,
        'recommendation_json' => json_encode($recommendation, JSON_UNESCAPED_UNICODE),
        'remark' => $rowRemark,
    );
}

if (count($items) === 0) {
    flash_set('error', '저장할 품목을 1개 이상 입력해주세요.');
    header('Location: ?r=estimate_home');
    exit;
}

try {
    $pdo->beginTransaction();

    $st = $pdo->prepare("INSERT INTO cpms_estimates
        (estimate_date, project_name, client, section_name, contractor, work_character, work_kind, include_indirect, difficulty, estimate_type, remark, total_amount, created_by, created_by_name, created_by_email, created_at)
        VALUES
        (:estimate_date, :project_name, :client, :section_name, :contractor, :work_character, :work_kind, :include_indirect, :difficulty, :estimate_type, :remark, :total_amount, :created_by, :created_by_name, :created_by_email, :created_at)");
    $st->bindValue(':estimate_date', $estimateDate);
    $st->bindValue(':project_name', $projectName);
    $st->bindValue(':client', $client);
    $st->bindValue(':section_name', $sectionName);
    $st->bindValue(':contractor', $contractor);
    $st->bindValue(':work_character', $workCharacter);
    $st->bindValue(':work_kind', $workKind);
    $st->bindValue(':include_indirect', $includeIndirect ? 1 : 0, PDO::PARAM_INT);
    $st->bindValue(':difficulty', $difficulty);
    $st->bindValue(':estimate_type', $estimateType);
    $st->bindValue(':remark', $remark);
    cpms_estimate_save_bind_decimal($st, ':total_amount', $totalAmount);
    $st->bindValue(':created_by', (int)$user['id'], PDO::PARAM_INT);
    $st->bindValue(':created_by_name', $user['name']);
    $st->bindValue(':created_by_email', $user['email']);
    $st->bindValue(':created_at', date('Y-m-d H:i:s'));
    $st->execute();

    $estimateId = (int)$pdo->lastInsertId();

    $itemSt = $pdo->prepare("INSERT INTO cpms_estimate_items
        (estimate_id, line_no, work_type, item_name, spec, unit, qty, recommended_unit_price, submitted_unit_price, amount, recommendation_json, remark, created_at)
        VALUES
        (:estimate_id, :line_no, :work_type, :item_name, :spec, :unit, :qty, :recommended_unit_price, :submitted_unit_price, :amount, :recommendation_json, :remark, :created_at)");

    $lineNo = 1;
    foreach ($items as $item) {
        $itemSt->bindValue(':estimate_id', $estimateId, PDO::PARAM_INT);
        $itemSt->bindValue(':line_no', $lineNo, PDO::PARAM_INT);
        $itemSt->bindValue(':work_type', $item['work_type']);
        $itemSt->bindValue(':item_name', $item['item_name']);
        $itemSt->bindValue(':spec', $item['spec']);
        $itemSt->bindValue(':unit', $item['unit']);
        cpms_estimate_save_bind_decimal($itemSt, ':qty', $item['qty']);
        cpms_estimate_save_bind_decimal($itemSt, ':recommended_unit_price', $item['recommended_unit_price']);
        cpms_estimate_save_bind_decimal($itemSt, ':submitted_unit_price', $item['submitted_unit_price']);
        cpms_estimate_save_bind_decimal($itemSt, ':amount', $item['amount']);
        $itemSt->bindValue(':recommendation_json', $item['recommendation_json']);
        $itemSt->bindValue(':remark', $item['remark']);
        $itemSt->bindValue(':created_at', date('Y-m-d H:i:s'));
        $itemSt->execute();
        $lineNo++;
    }

    $pdo->commit();
    flash_set('success', '견적서가 저장되었습니다.');
    header('Location: ?r=estimate_home&tab=history&estimate_id=' . $estimateId);
    exit;
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash_set('error', '견적서 저장 실패: ' . $e->getMessage());
    header('Location: ?r=estimate_home');
    exit;
}
