<?php
/**
 * 공사 > 장비 > 입력
 * - 장비 사용일자 저장(여러 날짜/범위 파싱)
 * - 같은 장비/같은 날짜는 UNIQUE로 upsert
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/partials/project_month_options_helper.php';
require_once __DIR__ . '/partials/equipment_gongsu_approval_helper.php';
require_once __DIR__ . '/partials/equipment_statement_helper.php';
require_once __DIR__ . '/../../services/CostChangeService.php';
require_once __DIR__ . '/../../services/CostDataEventService.php';

use App\Core\Auth;
use App\Core\Db;
use App\Services\CostChangeService;
use App\Services\CostDataEventService;

if (!Auth::check()) { header('Location: ?r=login'); exit; }
$role = Auth::userRole();
$dept = Auth::userDepartment();
if (!Auth::canManageConstruction()) { http_response_code(403); echo '403 Forbidden'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) { flash_set('error', '보안 토큰 오류'); header('Location: ?r=construction_home'); exit; }

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$equipmentId = isset($_POST['equipment_id']) ? (int)$_POST['equipment_id'] : 0;
$equipTab = isset($_POST['equip_tab']) ? trim((string)$_POST['equip_tab']) : 'input';
$defaultYm = cpms_construction_current_business_ym();
$ym = isset($_POST['ym']) ? trim((string)$_POST['ym']) : $defaultYm;
$usageDates = isset($_POST['usage_dates']) ? $_POST['usage_dates'] : array();
$useDatesText = trim((string)(isset($_POST['use_dates']) ? $_POST['use_dates'] : ''));
$memo = trim((string)(isset($_POST['memo']) ? $_POST['memo'] : ''));
$amountSign = isset($_POST['amount_sign']) ? trim((string)$_POST['amount_sign']) : '';
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = $defaultYm;
$redirect = '?r=construction_home&pid=' . $projectId . '&tab=equipment&equip_tab=' . urlencode($equipTab) . '&ym=' . urlencode($ym);

if ($projectId <= 0 || $equipmentId <= 0) {
    flash_set('error', '입력값이 올바르지 않습니다.');
    header('Location: ' . $redirect);
    exit;
}

function equipment_parse_use_dates2($text, $ym)
{
    $result = array();
    $text = str_replace(array("\r\n", "\n", ';', '|'), ',', $text);
    $tokens = explode(',', $text);
    $range = equipment_month_range2($ym);
    $rangeStart = strtotime($range['start']);
    $rangeEnd = strtotime($range['end']);

    foreach ($tokens as $tk) {
        $token = trim($tk);
        if ($token === '') continue;

        if (strpos($token, '~') !== false) {
            $parts = explode('~', $token, 2);
            $start = trim($parts[0]);
            $end = trim($parts[1]);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) continue;
            $s = strtotime($start); $e = strtotime($end);
            if ($s === false || $e === false) continue;
            if ($s > $e) { $tmp = $s; $s = $e; $e = $tmp; }
            for ($t = $s; $t <= $e; $t += 86400) {
                if ($rangeStart === false || $rangeEnd === false || $t < $rangeStart || $t > $rangeEnd) continue;
                $result[date('Y-m-d', $t)] = true;
            }
            continue;
        }

        if (preg_match('/^\d{1,2}$/', $token)) {
            $dayNumber = (int)$token;
            $targetYm = ($dayNumber >= 26) ? date('Y-m', strtotime($ym . '-01 -1 month')) : $ym;
            $targetYear = (int)substr($targetYm, 0, 4);
            $targetMonth = (int)substr($targetYm, 5, 2);
            if (!checkdate($targetMonth, $dayNumber, $targetYear)) continue;
            $token = $targetYm . '-' . sprintf('%02d', $dayNumber);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $token)) {
            $ts = strtotime($token);
            if ($ts !== false && $rangeStart !== false && $rangeEnd !== false && $ts >= $rangeStart && $ts <= $rangeEnd) $result[date('Y-m-d', $ts)] = true;
        }
    }

    return array_keys($result);
}

function equipment_month_range2($ym)
{
    $prevYm = date('Y-m', strtotime($ym . '-01 -1 month'));
    return array(
        'start' => $prevYm . '-26',
        'end' => $ym . '-25',
    );
}

function equipment_is_in_month_range2($date, $ym)
{
    $range = equipment_month_range2($ym);
    $ts = strtotime($date);
    $startTs = strtotime($range['start']);
    $endTs = strtotime($range['end']);
    if ($ts === false || $startTs === false || $endTs === false) return false;
    return ($ts >= $startTs && $ts <= $endTs);
}

function equipment_collect_usage_dates2($usageDates, $text, $ym)
{
    $result = array();

    if (is_array($usageDates)) {
        foreach ($usageDates as $d) {
            $date = trim((string)$d);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
            $ts = strtotime($date);
            if ($ts !== false && equipment_is_in_month_range2($date, $ym)) {
                $result[date('Y-m-d', $ts)] = true;
            }
        }
    }

    $legacy = equipment_parse_use_dates2($text, $ym);
    foreach ($legacy as $d) {
        $result[$d] = true;
    }

    return array_keys($result);
}

function cpms_equipment_usage_save_apply_sign($amount, $signValue)
{
    $raw = trim((string)$amount);
    $isNegative = (strpos($raw, '-') !== false);
    $raw = str_replace(array(',', ' ', "\t", '원'), '', $raw);
    $raw = preg_replace('/[^0-9.\-]/', '', $raw);
    $amount = abs((float)$raw);
    $signValue = trim((string)$signValue);
    if ($signValue === '-' || $signValue === 'minus' || $signValue === 'deduct' || $signValue === 'negative') {
        $isNegative = true;
    } else if ($signValue === '+' || $signValue === 'plus' || $signValue === 'normal') {
        $isNegative = false;
    }
    return $isNegative ? ($amount * -1) : $amount;
}
$pdo = Db::pdo();
if ($pdo) {
    cpms_equipment_gongsu_ensure_schema($pdo);
    cpms_equipment_statement_ensure_usage_columns($pdo);
}
if (!$pdo) { flash_set('error', 'DB 연결 실패'); header('Location: ' . $redirect); exit; }

try {
    $stE = $pdo->prepare("SELECT base_rate, category, vendor_name, spec, remark FROM cpms_equipment_items WHERE id = :id AND project_id = :pid AND is_deleted = 0 LIMIT 1");
    $stE->bindValue(':id', $equipmentId, PDO::PARAM_INT);
    $stE->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $stE->execute();
    $item = $stE->fetch(PDO::FETCH_ASSOC);
    if (!$item) {
        flash_set('error', '장비 정보를 찾을 수 없습니다.');
        header('Location: ' . $redirect);
        exit;
    }

    $baseRate = (float)$item['base_rate'];
    $workUnit = 1.00;
    $amount = cpms_equipment_usage_save_apply_sign($workUnit * $baseRate, $amountSign);
    $dates = equipment_collect_usage_dates2($usageDates, $useDatesText, $ym);
    if (count($dates) <= 0) {
        flash_set('error', '유효한 사용일자가 없습니다.');
        header('Location: ' . $redirect);
        exit;
    }
    foreach ($dates as $d) {
        if (!equipment_is_in_month_range2($d, $ym)) {
            $range = equipment_month_range2($ym);
            flash_set('error', '사용일자는 ' . $range['start'] . ' ~ ' . $range['end'] . ' 범위만 저장할 수 있습니다.');
            header('Location: ' . $redirect);
            exit;
        }
    }
    foreach ($dates as $lockDate) {
        $lockInfo = CostChangeService::lockInfo('equipment', $lockDate, '', date('Y-m-d'));
        if (!empty($lockInfo['locked'])) {
            flash_set('error', '마감된 기간의 자료입니다. 추가하려면 비용 변경 승인이 필요합니다.');
            header('Location: ' . $redirect);
            exit;
        }
    }

    $st = $pdo->prepare("INSERT INTO cpms_equipment_usage
        (project_id, equipment_id, use_date, work_unit, base_rate_snapshot, amount, is_manual_unit, memo, created_at)
        VALUES
        (:pid, :eid, :d, :work_unit, :base_rate, :amt, 0, :memo, :created_at)
        ON DUPLICATE KEY UPDATE
            work_unit = IF(is_manual_unit = 1, work_unit, VALUES(work_unit)),
            base_rate_snapshot = IF(is_manual_unit = 1, base_rate_snapshot, VALUES(base_rate_snapshot)),
            amount = IF(is_manual_unit = 1, amount, VALUES(amount)),
            memo = VALUES(memo)");
    $now = date('Y-m-d H:i:s');
    $stFindUsage = $pdo->prepare("SELECT id, project_id, equipment_id, use_date, work_unit, base_rate_snapshot, amount, is_manual_unit, memo FROM cpms_equipment_usage WHERE project_id = :pid AND equipment_id = :eid AND use_date = :d LIMIT 1");
    $savedUsageRows = array();
    foreach ($dates as $d) {
        $oldUsageRow = false;
        $eventBeforeCaptured = false;
        try {
            $stFindUsage->execute(array(':pid' => $projectId, ':eid' => $equipmentId, ':d' => $d));
            $oldUsageRow = $stFindUsage->fetch(PDO::FETCH_ASSOC);
            $eventBeforeCaptured = true;
        } catch (Exception $costEventException) {
            error_log('[CostDataEvent] event capture failed');
        }
        $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $st->bindValue(':eid', $equipmentId, PDO::PARAM_INT);
        $st->bindValue(':d', $d);
        $st->bindValue(':work_unit', $workUnit);
        $st->bindValue(':base_rate', $amount);
        $st->bindValue(':amt', $amount);
        $st->bindValue(':memo', $memo);
        $st->bindValue(':created_at', $now);
        $st->execute();

        $stFindUsage->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $stFindUsage->bindValue(':eid', $equipmentId, PDO::PARAM_INT);
        $stFindUsage->bindValue(':d', $d);
        $stFindUsage->execute();
        $usageRow = $stFindUsage->fetch(PDO::FETCH_ASSOC);
        if (is_array($usageRow) && isset($usageRow['id'])) {
            $savedUsageRows[count($savedUsageRows)] = $usageRow;
            $itemSnapshot = array(
                'category' => isset($item['category']) ? $item['category'] : '',
                'vendor_name' => isset($item['vendor_name']) ? $item['vendor_name'] : '',
                'spec' => isset($item['spec']) ? $item['spec'] : '',
                'remark' => isset($item['remark']) ? $item['remark'] : '',
            );
            if ($eventBeforeCaptured) CostDataEventService::recordChange($pdo, array(
                'project_id' => $projectId,
                'cost_type' => 'equipment',
                'target_type' => 'equipment_usage',
                'target_id' => (string)$usageRow['id'],
                'event_action' => is_array($oldUsageRow) ? 'UPDATE' : 'CREATE',
                'source_type' => 'DIRECT',
                'actual_date' => $d,
                'settlement_ym' => CostChangeService::settlementYm('equipment', $d),
                'old_amount' => is_array($oldUsageRow) && isset($oldUsageRow['amount']) ? $oldUsageRow['amount'] : null,
                'new_amount' => isset($usageRow['amount']) ? $usageRow['amount'] : null,
                'old_data' => is_array($oldUsageRow) ? array_merge($oldUsageRow, $itemSnapshot) : array(),
                'new_data' => array_merge($usageRow, $itemSnapshot),
                'reason' => $memo,
                'source_file' => __FILE__,
            ));
        }
    }

    $baseMessage = '사용일자를 저장했습니다.';
    $uploadResult = cpms_equipment_statement_store_uploaded_file_for_usage_rows($pdo, 'statement_file', $projectId, $equipmentId, $savedUsageRows, $ym);
    if (isset($uploadResult['has_file']) && $uploadResult['has_file']) {
        if (isset($uploadResult['ok']) && $uploadResult['ok']) {
            $statementMessage = (isset($uploadResult['message']) && trim((string)$uploadResult['message']) !== '') ? (string)$uploadResult['message'] : '거래명세표를 첨부했습니다.';
            flash_set('success', $baseMessage . ' ' . $statementMessage);
        } else {
            flash_set('error', $baseMessage . ' 다만 거래명세표 첨부 실패: ' . (isset($uploadResult['message']) ? $uploadResult['message'] : '알 수 없는 오류'));
        }
    } else {
        flash_set('success', $baseMessage);
    }
} catch (Exception $e) {
    flash_set('error', '저장 실패: ' . $e->getMessage());
}

header('Location: ' . $redirect);
exit;
