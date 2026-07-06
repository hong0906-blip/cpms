<?php
/**
 * 공사 > 장비 > 입력내역 수정
 * - 사용일자/금액 수정
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/partials/project_month_options_helper.php';
require_once __DIR__ . '/partials/equipment_gongsu_approval_helper.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }
if (!Auth::canManageConstruction()) { http_response_code(403); echo '403 Forbidden'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) { flash_set('error', '보안 토큰 오류'); header('Location: ?r=construction_home'); exit; }

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$usageId = isset($_POST['usage_id']) ? (int)$_POST['usage_id'] : 0;
$useDate = isset($_POST['use_date']) ? trim((string)$_POST['use_date']) : '';
$amountRaw = isset($_POST['amount']) ? (string)$_POST['amount'] : '';
$amountSign = isset($_POST['amount_sign']) ? trim((string)$_POST['amount_sign']) : '';
$equipTab = isset($_POST['equip_tab']) ? trim((string)$_POST['equip_tab']) : 'input';
$defaultYm = cpms_construction_current_business_ym();
$ym = isset($_POST['ym']) ? trim((string)$_POST['ym']) : $defaultYm;
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = $defaultYm;
$redirect = '?r=construction_home&pid=' . $projectId . '&tab=equipment&equip_tab=' . urlencode($equipTab) . '&ym=' . urlencode($ym);

function cpms_equipment_usage_update_money($value, $signValue)
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

function cpms_equipment_usage_update_month_range($ym)
{
    $prevYm = date('Y-m', strtotime($ym . '-01 -1 month'));
    return array('start' => $prevYm . '-26', 'end' => $ym . '-25');
}

function cpms_equipment_usage_update_in_range($date, $ym)
{
    $range = cpms_equipment_usage_update_month_range($ym);
    $ts = strtotime($date);
    $startTs = strtotime($range['start']);
    $endTs = strtotime($range['end']);
    if ($ts === false || $startTs === false || $endTs === false) return false;
    return ($ts >= $startTs && $ts <= $endTs);
}

$amount = cpms_equipment_usage_update_money($amountRaw, $amountSign);
if ($projectId <= 0 || $usageId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $useDate) || $amount === null) {
    flash_set('error', '수정값이 올바르지 않습니다.');
    header('Location: ' . $redirect);
    exit;
}
if (!cpms_equipment_usage_update_in_range($useDate, $ym)) {
    $range = cpms_equipment_usage_update_month_range($ym);
    flash_set('error', '사용일자는 ' . $range['start'] . ' ~ ' . $range['end'] . ' 범위만 저장할 수 있습니다.');
    header('Location: ' . $redirect);
    exit;
}

$pdo = Db::pdo();
if (!$pdo) { flash_set('error', 'DB 연결 실패'); header('Location: ' . $redirect); exit; }
cpms_equipment_gongsu_ensure_schema($pdo);

try {
    $st = $pdo->prepare("SELECT u.*, e.is_deleted
        FROM cpms_equipment_usage u
        JOIN cpms_equipment_items e ON e.id = u.equipment_id AND e.project_id = u.project_id
        WHERE u.id = :id AND u.project_id = :pid
        LIMIT 1");
    $st->bindValue(':id', $usageId, PDO::PARAM_INT);
    $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $st->execute();
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || (isset($row['is_deleted']) && (int)$row['is_deleted'] === 1)) {
        flash_set('error', '수정할 장비 사용내역을 찾을 수 없습니다.');
        header('Location: ' . $redirect);
        exit;
    }

    $equipmentId = isset($row['equipment_id']) ? (int)$row['equipment_id'] : 0;
    $stDup = $pdo->prepare("SELECT id FROM cpms_equipment_usage WHERE project_id = :pid AND equipment_id = :eid AND use_date = :use_date AND id <> :id LIMIT 1");
    $stDup->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $stDup->bindValue(':eid', $equipmentId, PDO::PARAM_INT);
    $stDup->bindValue(':use_date', $useDate);
    $stDup->bindValue(':id', $usageId, PDO::PARAM_INT);
    $stDup->execute();
    if ($stDup->fetch()) {
        flash_set('error', '같은 장비에 이미 등록된 사용일자입니다.');
        header('Location: ' . $redirect);
        exit;
    }

    $workUnit = isset($row['work_unit']) ? (float)$row['work_unit'] : 1.0;
    if ($workUnit <= 0) $workUnit = 1.0;
    $baseRateSnapshot = $amount / $workUnit;

    $fields = array('use_date = :use_date', 'amount = :amount');
    if (cpms_equipment_gongsu_column_exists($pdo, 'cpms_equipment_usage', 'base_rate_snapshot')) {
        $fields[] = 'base_rate_snapshot = :base_rate_snapshot';
    }
    $up = $pdo->prepare("UPDATE cpms_equipment_usage SET " . implode(', ', $fields) . " WHERE id = :id AND project_id = :pid");
    $up->bindValue(':use_date', $useDate);
    $up->bindValue(':amount', $amount);
    if (in_array('base_rate_snapshot = :base_rate_snapshot', $fields, true)) {
        $up->bindValue(':base_rate_snapshot', $baseRateSnapshot);
    }
    $up->bindValue(':id', $usageId, PDO::PARAM_INT);
    $up->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $up->execute();

    if (cpms_equipment_gongsu_column_exists($pdo, 'cpms_equipment_gongsu_overrides', 'use_date')) {
        $stOverride = $pdo->prepare("UPDATE cpms_equipment_gongsu_overrides SET use_date = :use_date, updated_at = :updated_at WHERE equipment_usage_id = :usage_id");
        $stOverride->bindValue(':use_date', $useDate);
        $stOverride->bindValue(':updated_at', date('Y-m-d H:i:s'));
        $stOverride->bindValue(':usage_id', $usageId, PDO::PARAM_INT);
        $stOverride->execute();
    }

    flash_set('success', '장비 사용내역을 수정했습니다.');
} catch (Exception $e) {
    flash_set('error', '수정 실패: ' . $e->getMessage());
}

header('Location: ' . $redirect);
exit;
