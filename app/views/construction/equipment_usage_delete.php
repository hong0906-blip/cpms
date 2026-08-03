<?php
/**
 * 공사 > 장비 > 입력
 * - 특정 장비/사용일자 삭제
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';
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
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) { flash_set('error', '보안 토큰 오류'); header('Location: ?r=공사'); exit; }

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$equipmentId = isset($_POST['equipment_id']) ? (int)$_POST['equipment_id'] : 0;
$useDate = isset($_POST['use_date']) ? trim((string)$_POST['use_date']) : '';
$equipTab = isset($_POST['equip_tab']) ? trim((string)$_POST['equip_tab']) : 'input';
$ym = isset($_POST['ym']) ? trim((string)$_POST['ym']) : date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');
$redirect = '?r=공사&pid=' . $projectId . '&tab=equipment&equip_tab=' . urlencode($equipTab) . '&ym=' . urlencode($ym);

if ($projectId <= 0 || $equipmentId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $useDate)) {
    flash_set('error', '삭제 대상이 올바르지 않습니다.');
    header('Location: ' . $redirect);
    exit;
}

$pdo = Db::pdo();
if (!$pdo) { flash_set('error', 'DB 연결 실패'); header('Location: ' . $redirect); exit; }

try {
    $stFind = $pdo->prepare("SELECT u.id, u.project_id, u.equipment_id, u.use_date, u.amount, u.memo,
                                   i.category, i.vendor_name, i.spec, i.remark
                              FROM cpms_equipment_usage u
                              LEFT JOIN cpms_equipment_items i ON i.id = u.equipment_id AND i.project_id = u.project_id
                             WHERE u.project_id = :pid AND u.equipment_id = :eid AND u.use_date = :d LIMIT 1");
    $stFind->execute(array(':pid'=>$projectId, ':eid'=>$equipmentId, ':d'=>$useDate));
    $row = $stFind->fetch(PDO::FETCH_ASSOC);
    if (is_array($row)) {
        $settlementYm = CostChangeService::effectiveSettlementYm($pdo, 'equipment', (string)$row['id'], 'equipment', $row['use_date']);
        $lockInfo = CostChangeService::lockInfo('equipment', $row['use_date'], $settlementYm, date('Y-m-d'));
        if (!empty($lockInfo['locked'])) {
            flash_set('error', '마감된 기간의 자료는 일반 삭제할 수 없습니다. 삭제 승인 요청을 이용해주세요.');
            header('Location: ' . $redirect);
            exit;
        }
    }
    $st = $pdo->prepare("DELETE FROM cpms_equipment_usage WHERE project_id = :pid AND equipment_id = :eid AND use_date = :d");
    $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $st->bindValue(':eid', $equipmentId, PDO::PARAM_INT);
    $st->bindValue(':d', $useDate);
    $st->execute();
    if (is_array($row) && $st->rowCount() > 0) {
        CostDataEventService::recordChange($pdo, array(
            'project_id' => $projectId,
            'cost_type' => 'equipment',
            'target_type' => 'equipment_usage',
            'target_id' => isset($row['id']) ? (string)$row['id'] : '',
            'event_action' => 'DELETE',
            'source_type' => 'DIRECT',
            'actual_date' => isset($row['use_date']) ? $row['use_date'] : '',
            'settlement_ym' => CostChangeService::settlementYm('equipment', isset($row['use_date']) ? $row['use_date'] : ''),
            'old_amount' => isset($row['amount']) ? $row['amount'] : null,
            'new_amount' => null,
            'old_data' => $row,
            'new_data' => array(),
            'source_file' => __FILE__,
        ));
    }
    flash_set('success', '사용일자를 삭제했습니다.');
} catch (Exception $e) {
    flash_set('error', '삭제 실패: ' . $e->getMessage());
}

header('Location: ' . $redirect);
exit;
