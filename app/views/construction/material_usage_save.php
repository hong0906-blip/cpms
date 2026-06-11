<?php
/**
 * 자재구입비(장비 방식 복제)
 * 공사 > 자재구입비 > 입력
 * - 자재구입비 사용일자 저장(여러 날짜/범위 파싱)
 * - 같은 자재구입비/같은 날짜는 UNIQUE로 upsert
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/partials/material_statement_helper.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }
$role = Auth::userRole();
$dept = Auth::userDepartment();
if (!Auth::canManageConstruction()) { http_response_code(403); echo '403 Forbidden'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) { flash_set('error', '보안 토큰 오류'); header('Location: ?r=공사'); exit; }

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$materialId = isset($_POST['material_id']) ? (int)$_POST['material_id'] : 0;
$materialsTab = isset($_POST['materials_tab']) ? trim((string)$_POST['materials_tab']) : 'input';
$ym = isset($_POST['ym']) ? trim((string)$_POST['ym']) : date('Y-m');
$usageDates = isset($_POST['usage_dates']) ? $_POST['usage_dates'] : array();
$useDatesText = trim((string)(isset($_POST['use_dates']) ? $_POST['use_dates'] : ''));
$memo = trim((string)(isset($_POST['memo']) ? $_POST['memo'] : ''));
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');
$redirect = '?r=공사&pid=' . $projectId . '&tab=materials&materials_tab=' . urlencode($materialsTab) . '&ym=' . urlencode($ym);

if ($projectId <= 0 || $materialId <= 0) {
    flash_set('error', '입력값이 올바르지 않습니다.');
    header('Location: ' . $redirect);
    exit;
}

function material_parse_use_dates2($text, $ym)
{
    $result = array();
    $text = str_replace(array("\r\n", "\n", ';', '|'), ',', $text);
    $tokens = explode(',', $text);
    $range = material_month_range2($ym);
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

        if (preg_match('/^\d{1,2}$/', $token)) $token = $ym . '-' . sprintf('%02d', (int)$token);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $token)) {
            $ts = strtotime($token);
            if ($ts !== false && $rangeStart !== false && $rangeEnd !== false && $ts >= $rangeStart && $ts <= $rangeEnd) $result[date('Y-m-d', $ts)] = true;
        }
    }

    return array_keys($result);
}

function material_month_range2($ym)
{
    $prevYm = date('Y-m', strtotime($ym . '-01 -1 month'));
    return array(
        'start' => $prevYm . '-26',
        'end' => $ym . '-25',
    );
}

function material_is_in_month_range2($date, $ym)
{
    $range = material_month_range2($ym);
    $ts = strtotime($date);
    $startTs = strtotime($range['start']);
    $endTs = strtotime($range['end']);
    if ($ts === false || $startTs === false || $endTs === false) return false;
    return ($ts >= $startTs && $ts <= $endTs);
}

function material_collect_usage_dates2($usageDates, $text, $ym)
{
    $result = array();

    if (is_array($usageDates)) {
        foreach ($usageDates as $d) {
            $date = trim((string)$d);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
            $ts = strtotime($date);
            if ($ts !== false && material_is_in_month_range2($date, $ym)) {
                $result[date('Y-m-d', $ts)] = true;
            }
        }
    }

    $legacy = material_parse_use_dates2($text, $ym);
    foreach ($legacy as $d) {
        $result[$d] = true;
    }

    return array_keys($result);
}
$pdo = Db::pdo();
if (!$pdo) { flash_set('error', 'DB 연결 실패'); header('Location: ' . $redirect); exit; }

try {
    $stE = $pdo->prepare("SELECT base_rate FROM cpms_material_items WHERE id = :id AND project_id = :pid AND is_deleted = 0 LIMIT 1");
    $stE->bindValue(':id', $materialId, PDO::PARAM_INT);
    $stE->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $stE->execute();
    $item = $stE->fetch(PDO::FETCH_ASSOC);
    if (!$item) {
        flash_set('error', '자재구입비 정보를 찾을 수 없습니다.');
        header('Location: ' . $redirect);
        exit;
    }

    $amount = isset($_POST['amount']) && $_POST['amount'] !== '' ? (float)$_POST['amount'] : (float)$item['base_rate'];
    $dates = material_collect_usage_dates2($usageDates, $useDatesText, $ym);
    if (count($dates) <= 0) {
        flash_set('error', '유효한 사용일자가 없습니다.');
        header('Location: ' . $redirect);
        exit;
    }
    foreach ($dates as $d) {
        if (!material_is_in_month_range2($d, $ym)) {
            $range = material_month_range2($ym);
            flash_set('error', '사용일자는 ' . $range['start'] . ' ~ ' . $range['end'] . ' 범위만 저장할 수 있습니다.');
            header('Location: ' . $redirect);
            exit;
        }
    }
    
    $st = $pdo->prepare("INSERT INTO cpms_material_usage
        (project_id, material_id, use_date, amount, memo, created_at)
        VALUES
        (:pid, :eid, :d, :amt, :memo, :created_at)
        ON DUPLICATE KEY UPDATE amount = VALUES(amount), memo = VALUES(memo)");
    $stFindUsage = $pdo->prepare("SELECT id, use_date FROM cpms_material_usage WHERE project_id = :pid AND material_id = :mid AND use_date = :d LIMIT 1");
    $now = date('Y-m-d H:i:s');
    $savedUsageRows = array();
    foreach ($dates as $d) {
        $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $st->bindValue(':eid', $materialId, PDO::PARAM_INT);
        $st->bindValue(':d', $d);
        $st->bindValue(':amt', $amount);
        $st->bindValue(':memo', $memo);
        $st->bindValue(':created_at', $now);
        $st->execute();

        $stFindUsage->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $stFindUsage->bindValue(':mid', $materialId, PDO::PARAM_INT);
        $stFindUsage->bindValue(':d', $d);
        $stFindUsage->execute();
        $usageRow = $stFindUsage->fetch(PDO::FETCH_ASSOC);
        if (is_array($usageRow) && isset($usageRow['id'])) {
            $savedUsageRows[count($savedUsageRows)] = $usageRow;
        }
    }

    $baseMessage = '사용일자를 저장했습니다.';
    $uploadResult = cpms_material_statement_store_uploaded_file_for_usage_rows($pdo, 'statement_file', $projectId, $materialId, $savedUsageRows, $ym);
    if (isset($uploadResult['has_file']) && $uploadResult['has_file']) {
        if (isset($uploadResult['ok']) && $uploadResult['ok']) {
            flash_set('success', $baseMessage . ' 거래명세표를 첨부했습니다.');
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
