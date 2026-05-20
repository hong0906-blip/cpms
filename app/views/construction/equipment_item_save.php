<?php
/**
 * 공사 > 장비 > 입력
 * - 장비 마스터 저장
 * - use_dates가 함께 오면 같은 월 사용일자까지 저장
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/partials/equipment_gongsu_approval_helper.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }

$role = Auth::userRole();
$dept = Auth::userDepartment();
if (!Auth::canManageConstruction()) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    flash_set('error', '보안 토큰이 유효하지 않습니다.');
    header('Location: ?r=공사');
    exit;
}

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$equipTab = isset($_POST['equip_tab']) ? trim((string)$_POST['equip_tab']) : 'input';
$ym = isset($_POST['ym']) ? trim((string)$_POST['ym']) : date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');

$redirect = '?r=공사&pid=' . $projectId . '&tab=equipment&equip_tab=' . urlencode($equipTab) . '&ym=' . urlencode($ym);
if ($projectId <= 0) {
    flash_set('error', '프로젝트 정보가 올바르지 않습니다.');
    header('Location: ' . $redirect);
    exit;
}

$category = trim((string)(isset($_POST['category']) ? $_POST['category'] : ''));
$vendorName = trim((string)(isset($_POST['vendor_name']) ? $_POST['vendor_name'] : ''));
$spec = trim((string)(isset($_POST['spec']) ? $_POST['spec'] : ''));
$representative = trim((string)(isset($_POST['representative']) ? $_POST['representative'] : ''));
$phone = trim((string)(isset($_POST['phone']) ? $_POST['phone'] : ''));
$bizNo = trim((string)(isset($_POST['biz_no']) ? $_POST['biz_no'] : ''));
$baseRate = isset($_POST['base_rate']) ? (float)$_POST['base_rate'] : 0;
$remark = trim((string)(isset($_POST['remark']) ? $_POST['remark'] : ''));
$usageDates = isset($_POST['usage_dates']) ? $_POST['usage_dates'] : array();
$useDatesText = trim((string)(isset($_POST['use_dates']) ? $_POST['use_dates'] : ''));

if ($category === '' || $vendorName === '') {
    flash_set('error', '구분, 업체명은 필수입니다.');
    header('Location: ' . $redirect);
    exit;
}

function equipment_parse_use_dates($text, $ym)
{
    $result = array();
    if ($text === '') return $result;

    $text = str_replace(array("\r\n", "\n", ';', '|'), ',', $text);
    $tokens = explode(',', $text);
    $range = equipment_month_range($ym);
    $rangeStart = strtotime($range['start']);
    $rangeEnd = strtotime($range['end']);

    foreach ($tokens as $tk) {
        $token = trim($tk);
        if ($token === '') continue;

        if (strpos($token, '~') !== false) {
            $parts = explode('~', $token, 2);
            $start = trim($parts[0]);
            $end = trim($parts[1]);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
                continue;
            }
            $sTs = strtotime($start);
            $eTs = strtotime($end);
            if ($sTs === false || $eTs === false) continue;
            if ($sTs > $eTs) { $tmp = $sTs; $sTs = $eTs; $eTs = $tmp; }
            for ($t = $sTs; $t <= $eTs; $t += 86400) {
                if ($rangeStart === false || $rangeEnd === false || $t < $rangeStart || $t > $rangeEnd) continue;
                $result[date('Y-m-d', $t)] = true;
            }
            continue;
        }

        if (preg_match('/^\d{1,2}$/', $token)) {
            $token = $ym . '-' . sprintf('%02d', (int)$token);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $token)) {
            $ts = strtotime($token);
            if ($ts !== false && $rangeStart !== false && $rangeEnd !== false && $ts >= $rangeStart && $ts <= $rangeEnd) {
                $result[date('Y-m-d', $ts)] = true;
            }
        }
    }

    return array_keys($result);
}

function equipment_month_range($ym)
{
    $prevYm = date('Y-m', strtotime($ym . '-01 -1 month'));
    return array(
        'start' => $prevYm . '-26',
        'end' => $ym . '-25',
    );
}

function equipment_is_in_month_range($date, $ym)
{
    $range = equipment_month_range($ym);
    $ts = strtotime($date);
    $startTs = strtotime($range['start']);
    $endTs = strtotime($range['end']);
    if ($ts === false || $startTs === false || $endTs === false) return false;
    return ($ts >= $startTs && $ts <= $endTs);
}

function equipment_collect_usage_dates($usageDates, $text, $ym)
{
    $result = array();

    if (is_array($usageDates)) {
        foreach ($usageDates as $d) {
            $date = trim((string)$d);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
            $ts = strtotime($date);
            if ($ts !== false && equipment_is_in_month_range($date, $ym)) {
                $result[date('Y-m-d', $ts)] = true;
            }
        }
    }

    $legacy = equipment_parse_use_dates($text, $ym);
    foreach ($legacy as $d) {
        $result[$d] = true;
    }

    return array_keys($result);
}
$pdo = Db::pdo();
if ($pdo) cpms_equipment_gongsu_ensure_schema($pdo);
if (!$pdo) {
    flash_set('error', 'DB 연결 실패');
    header('Location: ' . $redirect);
    exit;
}

try {
    $now = date('Y-m-d H:i:s');
    $st = $pdo->prepare("INSERT INTO cpms_equipment_items
        (project_id, category, vendor_name, spec, representative, phone, biz_no, base_rate, remark, is_deleted, created_at, updated_at)
        VALUES
        (:pid, :category, :vendor, :spec, :rep, :phone, :biz_no, :base_rate, :remark, 0, :now, :now)");
    $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $st->bindValue(':category', $category);
    $st->bindValue(':vendor', $vendorName);
    $st->bindValue(':spec', $spec);
    $st->bindValue(':rep', $representative);
    $st->bindValue(':phone', $phone);
    $st->bindValue(':biz_no', $bizNo);
    $st->bindValue(':base_rate', $baseRate);
    $st->bindValue(':remark', $remark);
    $st->bindValue(':now', $now);
    $st->execute();

        // 공용 업체 프리셋 저장
    $stPreset = $pdo->prepare("INSERT INTO cpms_equipment_vendor_presets (vendor_name, category, representative, phone, biz_no, base_rate, remark, created_at, updated_at) VALUES (:vendor, :category, :rep, :phone, :biz_no, :base_rate, :remark, :now, :now) ON DUPLICATE KEY UPDATE category=VALUES(category), representative=VALUES(representative), phone=VALUES(phone), biz_no=VALUES(biz_no), base_rate=VALUES(base_rate), remark=VALUES(remark), updated_at=VALUES(updated_at)");
    $stPreset->bindValue(':vendor', $vendorName);
    $stPreset->bindValue(':category', $category);
    $stPreset->bindValue(':rep', $representative);
    $stPreset->bindValue(':phone', $phone);
    $stPreset->bindValue(':biz_no', $bizNo);
    $stPreset->bindValue(':base_rate', $baseRate);
    $stPreset->bindValue(':remark', $remark);
    $stPreset->bindValue(':now', $now);
    $stPreset->execute();

    $equipmentId = (int)$pdo->lastInsertId();

    $dates = equipment_collect_usage_dates($usageDates, $useDatesText, $ym);
    foreach ($dates as $d) {
        if (!equipment_is_in_month_range($d, $ym)) {
            $range = equipment_month_range($ym);
            flash_set('error', '사용일자는 ' . $range['start'] . ' ~ ' . $range['end'] . ' 범위만 저장할 수 있습니다.');
            header('Location: ' . $redirect);
            exit;
        }
    }    
    if ($equipmentId > 0 && count($dates) > 0) {
        $stU = $pdo->prepare("INSERT INTO cpms_equipment_usage
            (project_id, equipment_id, use_date, work_unit, base_rate_snapshot, amount, is_manual_unit, memo, created_at)
            VALUES
            (:pid, :eid, :d, :work_unit, :base_rate, :amt, 0, :memo, :created_at)
            ON DUPLICATE KEY UPDATE
                work_unit = IF(is_manual_unit = 1, work_unit, VALUES(work_unit)),
                base_rate_snapshot = IF(is_manual_unit = 1, base_rate_snapshot, VALUES(base_rate_snapshot)),
                amount = IF(is_manual_unit = 1, amount, VALUES(amount)),
                memo = VALUES(memo)");
        foreach ($dates as $d) {
            $stU->bindValue(':pid', $projectId, PDO::PARAM_INT);
            $stU->bindValue(':eid', $equipmentId, PDO::PARAM_INT);
            $stU->bindValue(':d', $d);
            $stU->bindValue(':work_unit', 1.00);
            $stU->bindValue(':base_rate', $baseRate);
            $stU->bindValue(':amt', $baseRate);
            $stU->bindValue(':memo', '');
            $stU->bindValue(':created_at', $now);
            $stU->execute();
        }
    }

    flash_set('success', '장비를 저장했습니다.');
    header('Location: ' . $redirect);
    exit;
} catch (Exception $e) {
    flash_set('error', '저장 실패: ' . $e->getMessage());
    header('Location: ' . $redirect);
    exit;
}
