<?php
/**
 * 자재구입비(장비 방식 복제)
 * 공사 > 자재구입비 > 입력
 * - 특정 자재구입비/사용일자 삭제
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/partials/material_statement_helper.php';
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
$materialId = isset($_POST['material_id']) ? (int)$_POST['material_id'] : 0;
$useDate = isset($_POST['use_date']) ? trim((string)$_POST['use_date']) : '';
$usageIds = isset($_POST['usage_ids']) ? $_POST['usage_ids'] : array();
$hasUsageIds = (is_array($usageIds) && count($usageIds) > 0);
$materialsTab = isset($_POST['materials_tab']) ? trim((string)$_POST['materials_tab']) : 'input';
$ym = isset($_POST['ym']) ? trim((string)$_POST['ym']) : date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');
$redirect = '?r=공사&pid=' . $projectId . '&tab=materials&materials_tab=' . urlencode($materialsTab) . '&ym=' . urlencode($ym);

if ($projectId <= 0 || (!$hasUsageIds && ($materialId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $useDate)))) {
    flash_set('error', '삭제 대상이 올바르지 않습니다.');
    header('Location: ' . $redirect);
    exit;
}

$pdo = Db::pdo();
if (!$pdo) { flash_set('error', 'DB 연결 실패'); header('Location: ' . $redirect); exit; }

try {
    if ($hasUsageIds) {
        $ids = array();
        foreach ($usageIds as $usageId) {
            $usageId = (int)$usageId;
            if ($usageId > 0) $ids[$usageId] = $usageId;
        }
        if (count($ids) <= 0) {
            flash_set('error', '삭제할 사용내역을 선택해주세요.');
            header('Location: ' . $redirect);
            exit;
        }

        $placeholders = array();
        $i = 0;
        foreach ($ids as $idValue) {
            $placeholders[count($placeholders)] = ':id' . $i;
            $i++;
        }
        $in = implode(',', $placeholders);

        $stFind = $pdo->prepare("SELECT u.id, u.project_id, u.material_id, u.use_date, u.amount, u.memo,
                                       i.category, i.vendor_name, i.spec, i.remark
                                  FROM cpms_material_usage u
                                  LEFT JOIN cpms_material_items i ON i.id = u.material_id AND i.project_id = u.project_id
                                 WHERE u.project_id = :pid AND u.id IN (" . $in . ")");
        $stFind->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $i = 0;
        foreach ($ids as $idValue) {
            $stFind->bindValue(':id' . $i, $idValue, PDO::PARAM_INT);
            $i++;
        }
        $stFind->execute();
        $deleteRows = $stFind->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($deleteRows) || count($deleteRows) <= 0) {
            flash_set('error', '삭제할 사용내역을 찾지 못했습니다.');
            header('Location: ' . $redirect);
            exit;
        }

        foreach ($deleteRows as $deleteRow) {
            $deleteId = isset($deleteRow['id']) ? (string)$deleteRow['id'] : '';
            $deleteDate = isset($deleteRow['use_date']) ? (string)$deleteRow['use_date'] : '';
            $deleteYm = CostChangeService::effectiveSettlementYm($pdo, 'material', $deleteId, 'material', $deleteDate);
            $deleteLock = CostChangeService::lockInfo('material', $deleteDate, $deleteYm, date('Y-m-d'));
            if (!empty($deleteLock['locked'])) {
                flash_set('error', '마감된 기간의 자료는 일반 삭제할 수 없습니다. 삭제 승인 요청을 이용해주세요.');
                header('Location: ' . $redirect);
                exit;
            }
        }

        $deleteUsageIds = array();
        $affectedMaterialIds = array();
        foreach ($deleteRows as $row) {
            $uid = isset($row['id']) ? (int)$row['id'] : 0;
            $mid = isset($row['material_id']) ? (int)$row['material_id'] : 0;
            if ($uid > 0) $deleteUsageIds[$uid] = $uid;
            if ($mid > 0) $affectedMaterialIds[$mid] = $mid;
        }

        $pdo->beginTransaction();
        if (cpms_material_statement_schema_ready($pdo) && count($deleteUsageIds) > 0) {
            $statementPlaceholders = array();
            $i = 0;
            foreach ($deleteUsageIds as $idValue) {
                $statementPlaceholders[count($statementPlaceholders)] = ':sid' . $i;
                $i++;
            }
            $stStatement = $pdo->prepare("UPDATE cpms_material_statement_files SET is_deleted = 1, deleted_at = :deleted_at WHERE project_id = :pid AND material_usage_id IN (" . implode(',', $statementPlaceholders) . ")");
            $stStatement->bindValue(':deleted_at', date('Y-m-d H:i:s'));
            $stStatement->bindValue(':pid', $projectId, PDO::PARAM_INT);
            $i = 0;
            foreach ($deleteUsageIds as $idValue) {
                $stStatement->bindValue(':sid' . $i, $idValue, PDO::PARAM_INT);
                $i++;
            }
            $stStatement->execute();
        }

        $deletePlaceholders = array();
        $i = 0;
        foreach ($deleteUsageIds as $idValue) {
            $deletePlaceholders[count($deletePlaceholders)] = ':did' . $i;
            $i++;
        }
        $stDelete = $pdo->prepare("DELETE FROM cpms_material_usage WHERE project_id = :pid AND id IN (" . implode(',', $deletePlaceholders) . ")");
        $stDelete->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $i = 0;
        foreach ($deleteUsageIds as $idValue) {
            $stDelete->bindValue(':did' . $i, $idValue, PDO::PARAM_INT);
            $i++;
        }
        $stDelete->execute();
        $deletedCount = (int)$stDelete->rowCount();

        if (count($affectedMaterialIds) > 0) {
            $stRemain = $pdo->prepare("SELECT COUNT(*) FROM cpms_material_usage WHERE project_id = :pid AND material_id = :mid");
            $stItemDelete = $pdo->prepare("UPDATE cpms_material_items SET is_deleted = 1, updated_at = :updated_at WHERE project_id = :pid AND id = :mid");
            foreach ($affectedMaterialIds as $mid) {
                $stRemain->bindValue(':pid', $projectId, PDO::PARAM_INT);
                $stRemain->bindValue(':mid', $mid, PDO::PARAM_INT);
                $stRemain->execute();
                if ((int)$stRemain->fetchColumn() <= 0) {
                    $stItemDelete->bindValue(':updated_at', date('Y-m-d H:i:s'));
                    $stItemDelete->bindValue(':pid', $projectId, PDO::PARAM_INT);
                    $stItemDelete->bindValue(':mid', $mid, PDO::PARAM_INT);
                    $stItemDelete->execute();
                }
            }
        }

        $pdo->commit();
        if ($deletedCount > 0 && $deletedCount === count($deleteRows)) {
            foreach ($deleteRows as $deleteRow) {
                CostDataEventService::recordChange($pdo, array(
                    'project_id' => $projectId,
                    'cost_type' => 'material',
                    'target_type' => 'material_usage',
                    'target_id' => isset($deleteRow['id']) ? (string)$deleteRow['id'] : '',
                    'event_action' => 'DELETE',
                    'source_type' => 'DIRECT',
                    'actual_date' => isset($deleteRow['use_date']) ? $deleteRow['use_date'] : '',
                    'settlement_ym' => CostChangeService::settlementYm('material', isset($deleteRow['use_date']) ? $deleteRow['use_date'] : ''),
                    'old_amount' => isset($deleteRow['amount']) ? $deleteRow['amount'] : null,
                    'new_amount' => null,
                    'old_data' => $deleteRow,
                    'new_data' => array(),
                    'source_file' => __FILE__,
                ));
            }
        }
        flash_set('success', '선택한 자재구입비 사용내역 ' . $deletedCount . '건을 삭제했습니다.');
        header('Location: ' . $redirect);
        exit;
    }

    $stFindSingle = $pdo->prepare("SELECT u.id, u.project_id, u.material_id, u.use_date, u.amount, u.memo,
                                         i.category, i.vendor_name, i.spec, i.remark
                                    FROM cpms_material_usage u
                                    LEFT JOIN cpms_material_items i ON i.id = u.material_id AND i.project_id = u.project_id
                                   WHERE u.project_id = :pid AND u.material_id = :eid AND u.use_date = :d LIMIT 1");
    $stFindSingle->execute(array(':pid'=>$projectId, ':eid'=>$materialId, ':d'=>$useDate));
    $singleRow = $stFindSingle->fetch(PDO::FETCH_ASSOC);
    if (is_array($singleRow)) {
        $singleYm = CostChangeService::effectiveSettlementYm($pdo, 'material', (string)$singleRow['id'], 'material', $singleRow['use_date']);
        $singleLock = CostChangeService::lockInfo('material', $singleRow['use_date'], $singleYm, date('Y-m-d'));
        if (!empty($singleLock['locked'])) {
            flash_set('error', '마감된 기간의 자료는 일반 삭제할 수 없습니다. 삭제 승인 요청을 이용해주세요.');
            header('Location: ' . $redirect);
            exit;
        }
    }
    $st = $pdo->prepare("DELETE FROM cpms_material_usage WHERE project_id = :pid AND material_id = :eid AND use_date = :d");
    $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $st->bindValue(':eid', $materialId, PDO::PARAM_INT);
    $st->bindValue(':d', $useDate);
    $st->execute();
    if (is_array($singleRow) && $st->rowCount() > 0) {
        CostDataEventService::recordChange($pdo, array(
            'project_id' => $projectId,
            'cost_type' => 'material',
            'target_type' => 'material_usage',
            'target_id' => isset($singleRow['id']) ? (string)$singleRow['id'] : '',
            'event_action' => 'DELETE',
            'source_type' => 'DIRECT',
            'actual_date' => isset($singleRow['use_date']) ? $singleRow['use_date'] : '',
            'settlement_ym' => CostChangeService::settlementYm('material', isset($singleRow['use_date']) ? $singleRow['use_date'] : ''),
            'old_amount' => isset($singleRow['amount']) ? $singleRow['amount'] : null,
            'new_amount' => null,
            'old_data' => $singleRow,
            'new_data' => array(),
            'source_file' => __FILE__,
        ));
    }
    flash_set('success', '사용일자를 삭제했습니다.');
} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    flash_set('error', '삭제 실패: ' . $e->getMessage());
}

header('Location: ' . $redirect);
exit;
