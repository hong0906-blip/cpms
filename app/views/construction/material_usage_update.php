<?php
/**
 * 공사 > 자재구입비 > 입력내역 수정
 * - 사용일자/금액 수정
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/partials/project_month_options_helper.php';
require_once __DIR__ . '/partials/material_statement_helper.php';
require_once __DIR__ . '/partials/material_usage_helper.php';
require_once __DIR__ . '/../../services/CostChangeService.php';

use App\Core\Auth;
use App\Core\Db;
use App\Services\CostChangeService;

if (!Auth::check()) { header('Location: ?r=login'); exit; }
if (!Auth::canManageConstruction()) { http_response_code(403); echo '403 Forbidden'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) { flash_set('error', '보안 토큰 오류'); header('Location: ?r=construction_home'); exit; }

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$usageId = isset($_POST['usage_id']) ? (int)$_POST['usage_id'] : 0;
$useDate = isset($_POST['use_date']) ? trim((string)$_POST['use_date']) : '';
$amountRaw = isset($_POST['amount']) ? (string)$_POST['amount'] : '';
$amountSign = isset($_POST['amount_sign']) ? trim((string)$_POST['amount_sign']) : '';
$memoWasPosted = isset($_POST['memo']);
$memo = $memoWasPosted ? trim((string)$_POST['memo']) : '';
$remarkWasPosted = isset($_POST['remark']);
$remark = $remarkWasPosted ? trim((string)$_POST['remark']) : '';
$materialsTab = isset($_POST['materials_tab']) ? trim((string)$_POST['materials_tab']) : 'input';
$defaultYm = cpms_construction_current_business_ym();
$ym = isset($_POST['ym']) ? trim((string)$_POST['ym']) : $defaultYm;
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = $defaultYm;
$redirect = '?r=construction_home&pid=' . $projectId . '&tab=materials&materials_tab=' . urlencode($materialsTab) . '&ym=' . urlencode($ym);

function cpms_material_usage_update_money($value, $signValue)
{
    $raw = trim((string)$value);
    if (!preg_match('/\d/', $raw)) return null;
    $isNegative = (strpos($raw, '-') !== false);
    $raw = str_replace(array(',', ' ', "\t", '원'), '', $raw);
    $raw = preg_replace('/[^0-9.]/', '', $raw);
    if ($raw === '' || $raw === '-' || $raw === '.') return null;
    $signValue = trim((string)$signValue);
    if ($signValue === '-' || $signValue === 'minus' || $signValue === 'deduct' || $signValue === 'negative') {
        $isNegative = true;
    } else if ($signValue === '+' || $signValue === 'plus' || $signValue === 'normal') {
        $isNegative = false;
    }
    $amount = (float)$raw;
    return $isNegative ? ($amount * -1) : $amount;
}

function cpms_material_usage_update_month_range($ym)
{
    $prevYm = date('Y-m', strtotime($ym . '-01 -1 month'));
    return array('start' => $prevYm . '-26', 'end' => $ym . '-25');
}

function cpms_material_usage_update_in_range($date, $ym)
{
    $range = cpms_material_usage_update_month_range($ym);
    $ts = strtotime($date);
    $startTs = strtotime($range['start']);
    $endTs = strtotime($range['end']);
    if ($ts === false || $startTs === false || $endTs === false) return false;
    return ($ts >= $startTs && $ts <= $endTs);
}

$amount = cpms_material_usage_update_money($amountRaw, $amountSign);
if ($projectId <= 0 || $usageId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $useDate) || $amount === null) {
    flash_set('error', '수정값이 올바르지 않습니다.');
    header('Location: ' . $redirect);
    exit;
}
if (!cpms_material_usage_update_in_range($useDate, $ym)) {
    $range = cpms_material_usage_update_month_range($ym);
    flash_set('error', '사용일자는 ' . $range['start'] . ' ~ ' . $range['end'] . ' 범위만 저장할 수 있습니다.');
    header('Location: ' . $redirect);
    exit;
}

$pdo = Db::pdo();
if (!$pdo) { flash_set('error', 'DB 연결 실패'); header('Location: ' . $redirect); exit; }
cpms_material_usage_ensure_schema($pdo);

try {
    $st = $pdo->prepare("SELECT u.*, i.is_deleted, i.category, i.vendor_name, i.representative, i.phone, i.biz_no, i.base_rate, i.remark
        FROM cpms_material_usage u
        JOIN cpms_material_items i ON i.id = u.material_id AND i.project_id = u.project_id
        WHERE u.id = :id AND u.project_id = :pid
        LIMIT 1");
    $st->bindValue(':id', $usageId, PDO::PARAM_INT);
    $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $st->execute();
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || (isset($row['is_deleted']) && (int)$row['is_deleted'] === 1)) {
        flash_set('error', '수정할 자재구입비 사용내역을 찾을 수 없습니다.');
        header('Location: ' . $redirect);
        exit;
    }
    if (isset($row['category']) && trim((string)$row['category']) === '안전관리비') {
        flash_set('error', '안전관리비 사용내역은 안전섹션에서 수정해주세요.');
        header('Location: ' . $redirect);
        exit;
    }

    $sourceYm = CostChangeService::effectiveSettlementYm($pdo, 'material', (string)$usageId, 'material', isset($row['use_date']) ? $row['use_date'] : '');
    $sourceLock = CostChangeService::lockInfo('material', isset($row['use_date']) ? $row['use_date'] : '', $sourceYm, date('Y-m-d'));
    $destinationLock = CostChangeService::lockInfo('material', $useDate, '', date('Y-m-d'));
    if (!empty($sourceLock['locked']) || !empty($destinationLock['locked'])) {
        flash_set('error', '마감된 기간의 자료입니다. 수정하려면 비용 변경 승인이 필요합니다.');
        header('Location: ' . $redirect);
        exit;
    }

    if (!$memoWasPosted) {
        $memo = isset($row['memo']) ? (string)$row['memo'] : '';
    }
    if (!$remarkWasPosted) {
        $remark = isset($row['remark']) ? (string)$row['remark'] : '';
    }

    $materialId = isset($row['material_id']) ? (int)$row['material_id'] : 0;
    $stDup = $pdo->prepare("SELECT id FROM cpms_material_usage WHERE project_id = :pid AND material_id = :mid AND use_date = :use_date AND id <> :id LIMIT 1");
    $stDup->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $stDup->bindValue(':mid', $materialId, PDO::PARAM_INT);
    $stDup->bindValue(':use_date', $useDate);
    $stDup->bindValue(':id', $usageId, PDO::PARAM_INT);
    $stDup->execute();
    if ($stDup->fetch()) {
        flash_set('error', '같은 자재구입비에 이미 등록된 사용일자입니다.');
        header('Location: ' . $redirect);
        exit;
    }

    $up = $pdo->prepare("UPDATE cpms_material_usage SET use_date = :use_date, amount = :amount, memo = :memo WHERE id = :id AND project_id = :pid");
    $up->bindValue(':use_date', $useDate);
    $up->bindValue(':amount', $amount);
    $up->bindValue(':memo', $memo);
    $up->bindValue(':id', $usageId, PDO::PARAM_INT);
    $up->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $up->execute();

    $upItem = $pdo->prepare("UPDATE cpms_material_items SET remark = :remark, updated_at = :updated_at WHERE id = :id AND project_id = :pid");
    $upItem->bindValue(':remark', $remark);
    $upItem->bindValue(':updated_at', date('Y-m-d H:i:s'));
    $upItem->bindValue(':id', $materialId, PDO::PARAM_INT);
    $upItem->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $upItem->execute();

    if (cpms_material_statement_ensure_schema($pdo) && cpms_material_statement_schema_ready($pdo)) {
        $stStatement = $pdo->prepare("UPDATE cpms_material_statement_files
            SET use_date = :use_date, ym = :ym
            WHERE project_id = :pid AND material_usage_id = :usage_id AND is_deleted = 0");
        $stStatement->bindValue(':use_date', $useDate);
        $stStatement->bindValue(':ym', $ym);
        $stStatement->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $stStatement->bindValue(':usage_id', $usageId, PDO::PARAM_INT);
        $stStatement->execute();
    }

    $successMessage = '자재구입비 사용내역을 수정했습니다.';
    $uploadResult = cpms_material_statement_store_uploaded_file_for_usage_rows($pdo, 'statement_file', $projectId, $materialId, array(array('id'=>$usageId, 'use_date'=>$useDate)), $ym);
    if (isset($uploadResult['has_file']) && $uploadResult['has_file']) {
        if (isset($uploadResult['ok']) && $uploadResult['ok']) {
            $successMessage .= ' ' . ((isset($uploadResult['message']) && trim((string)$uploadResult['message']) !== '') ? (string)$uploadResult['message'] : '거래명세표를 첨부했습니다.');
            flash_set('success', $successMessage);
        } else {
            flash_set('error', $successMessage . ' 다만 거래명세표 첨부 실패: ' . (isset($uploadResult['message']) ? $uploadResult['message'] : '알 수 없는 오류'));
        }
    } else {
        flash_set('success', $successMessage);
    }
} catch (Exception $e) {
    flash_set('error', '수정 실패: ' . $e->getMessage());
}

header('Location: ' . $redirect);
exit;
