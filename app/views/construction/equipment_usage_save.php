<?php
/**
 * 공사 > 장비 > 입력
 * - 장비 사용일자 저장(여러 날짜/범위 파싱)
 * - 같은 장비/같은 날짜는 UNIQUE로 upsert
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }
$role = Auth::userRole();
$dept = Auth::userDepartment();
if (!($role === 'executive' || $dept === '공사')) { http_response_code(403); echo '403 Forbidden'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) { flash_set('error', '보안 토큰 오류'); header('Location: ?r=공사'); exit; }

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$equipmentId = isset($_POST['equipment_id']) ? (int)$_POST['equipment_id'] : 0;
$equipTab = isset($_POST['equip_tab']) ? trim((string)$_POST['equip_tab']) : 'input';
$ym = isset($_POST['ym']) ? trim((string)$_POST['ym']) : date('Y-m');
$useDatesText = trim((string)(isset($_POST['use_dates']) ? $_POST['use_dates'] : ''));
$memo = trim((string)(isset($_POST['memo']) ? $_POST['memo'] : ''));
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');
$redirect = '?r=공사&pid=' . $projectId . '&tab=equipment&equip_tab=' . urlencode($equipTab) . '&ym=' . urlencode($ym);

if ($projectId <= 0 || $equipmentId <= 0 || $useDatesText === '') {
    flash_set('error', '입력값이 올바르지 않습니다.');
    header('Location: ' . $redirect);
    exit;
}

function equipment_parse_use_dates2($text, $ym)
{
    $result = array();
    $text = str_replace(array("\r\n", "\n", ';', '|'), ',', $text);
    $tokens = explode(',', $text);
    $monthStart = strtotime($ym . '-01');
    $monthEnd = strtotime(date('Y-m-t', $monthStart));

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
                if ($t < $monthStart || $t > $monthEnd) continue;
                $result[date('Y-m-d', $t)] = true;
            }
            continue;
        }

        if (preg_match('/^\d{1,2}$/', $token)) $token = $ym . '-' . sprintf('%02d', (int)$token);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $token)) {
            $ts = strtotime($token);
            if ($ts !== false && $ts >= $monthStart && $ts <= $monthEnd) $result[date('Y-m-d', $ts)] = true;
        }
    }

    return array_keys($result);
}

$pdo = Db::pdo();
if (!$pdo) { flash_set('error', 'DB 연결 실패'); header('Location: ' . $redirect); exit; }

try {
    $stE = $pdo->prepare("SELECT base_rate FROM cpms_equipment_items WHERE id = :id AND project_id = :pid AND is_deleted = 0 LIMIT 1");
    $stE->bindValue(':id', $equipmentId, PDO::PARAM_INT);
    $stE->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $stE->execute();
    $item = $stE->fetch(PDO::FETCH_ASSOC);
    if (!$item) {
        flash_set('error', '장비 정보를 찾을 수 없습니다.');
        header('Location: ' . $redirect);
        exit;
    }

    $amount = isset($_POST['amount']) && $_POST['amount'] !== '' ? (float)$_POST['amount'] : (float)$item['base_rate'];
    $dates = equipment_parse_use_dates2($useDatesText, $ym);
    if (count($dates) <= 0) {
        flash_set('error', '유효한 사용일자가 없습니다.');
        header('Location: ' . $redirect);
        exit;
    }

    $st = $pdo->prepare("INSERT INTO cpms_equipment_usage
        (project_id, equipment_id, use_date, amount, memo, created_at)
        VALUES
        (:pid, :eid, :d, :amt, :memo, :created_at)
        ON DUPLICATE KEY UPDATE amount = VALUES(amount), memo = VALUES(memo)");
    $now = date('Y-m-d H:i:s');
    foreach ($dates as $d) {
        $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $st->bindValue(':eid', $equipmentId, PDO::PARAM_INT);
        $st->bindValue(':d', $d);
        $st->bindValue(':amt', $amount);
        $st->bindValue(':memo', $memo);
        $st->bindValue(':created_at', $now);
        $st->execute();
    }

    flash_set('success', '사용일자를 저장했습니다.');
} catch (Exception $e) {
    flash_set('error', '저장 실패: ' . $e->getMessage());
}

header('Location: ' . $redirect);
exit;