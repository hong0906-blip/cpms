<?php
/**
 * 공사: 공정 항목별 완료수량 저장(POST)
 * - 공사팀(공사) + 임원(executive)만 저장 가능
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/partials/schedule_auto_progress_helper.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) {
    header('Location: ?r=login');
    exit;
}

$role = Auth::userRole();
$dept = Auth::userDepartment();
if (!Auth::canManageConstruction()) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ?r=공사');
    exit;
}

$token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
if (!csrf_check($token)) {
    flash_set('error', '보안 토큰이 유효하지 않습니다.');
    header('Location: ?r=공사');
    exit;
}

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$taskId = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
$taskDate = isset($_POST['work_date']) ? trim((string)$_POST['work_date']) : '';
$itemDone = isset($_POST['item_done_qty']) && is_array($_POST['item_done_qty']) ? $_POST['item_done_qty'] : array();

if ($projectId <= 0 || $taskId <= 0) {
    flash_set('error', '프로젝트/공정 정보가 올바르지 않습니다.');
    header('Location: ?r=공사');
    exit;
}

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error', 'DB 연결 실패');
    header('Location: ?r=공사&pid=' . $projectId . '&tab=gantt');
    exit;
}
cpms_schedule_auto_ensure_schema($pdo);

$now = date('Y-m-d H:i:s');

try {
    $stTask = $pdo->prepare('SELECT id, name, work_id FROM cpms_schedule_tasks WHERE id=:tid AND project_id=:pid LIMIT 1');
    $stTask->bindValue(':tid', $taskId, \PDO::PARAM_INT);
    $stTask->bindValue(':pid', $projectId, \PDO::PARAM_INT);
    $stTask->execute();
    $taskRow = $stTask->fetch();
    if (!is_array($taskRow)) {
        throw new Exception('공정 정보를 찾을 수 없습니다.');
    }

    $unitIds = array();
    foreach ($itemDone as $k => $v) {
        $uid = (int)$k;
        if ($uid > 0) $unitIds[] = $uid;
    }
    $unitIds = array_values(array_unique($unitIds));

    $validUnitMap = array();
    if (count($unitIds) > 0) {
        $ph = implode(',', array_fill(0, count($unitIds), '?'));
        $taskWorkId = isset($taskRow['work_id']) ? (int)$taskRow['work_id'] : 0;
        if ($taskWorkId > 0) {
            $stUnit = $pdo->prepare("SELECT u.id, CASE WHEN l.planned_qty IS NULL OR l.planned_qty = '' THEN COALESCE(u.qty, 0) ELSE l.planned_qty END AS qty FROM cpms_project_unit_prices u LEFT JOIN cpms_work_item_lines l ON l.unit_price_id = u.id AND l.work_id = ? WHERE u.project_id = ? AND u.id IN ($ph)");
            $stUnit->bindValue(1, $taskWorkId, \PDO::PARAM_INT);
            $stUnit->bindValue(2, $projectId, \PDO::PARAM_INT);
            foreach ($unitIds as $idx => $uid) {
                $stUnit->bindValue($idx + 3, $uid, \PDO::PARAM_INT);
            }
        } else {
            $stUnit = $pdo->prepare("SELECT id, qty FROM cpms_project_unit_prices WHERE project_id = ? AND id IN ($ph)");
            $stUnit->bindValue(1, $projectId, \PDO::PARAM_INT);
            foreach ($unitIds as $idx => $uid) {
                $stUnit->bindValue($idx + 2, $uid, \PDO::PARAM_INT);
            }
        }
        $stUnit->execute();
        $rows = $stUnit->fetchAll();
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $uid = isset($r['id']) ? (int)$r['id'] : 0;
                if ($uid <= 0) continue;
                $validUnitMap[$uid] = isset($r['qty']) ? (float)$r['qty'] : 0;
            }
        }
    }

    $pdo->beginTransaction();

    $upsert = $pdo->prepare("INSERT INTO cpms_schedule_task_item_progress
        (project_id, task_id, unit_price_id, work_date, total_qty, done_qty, is_auto, is_manual, created_at, updated_at)
        VALUES (:pid, :tid, :uid, :wd, :tq, :dq, 0, 1, :cat, :uat)
        ON DUPLICATE KEY UPDATE total_qty = VALUES(total_qty), done_qty = VALUES(done_qty), is_auto = 0, is_manual = 1, updated_at = VALUES(updated_at)");

    $totalQtySum = 0.0;
    $doneQtySum = 0.0;
    foreach ($itemDone as $k => $v) {
        $uid = (int)$k;
        if ($uid <= 0 || !isset($validUnitMap[$uid])) continue;

        $raw = preg_replace('/[^0-9.\-]/', '', (string)$v);
        $doneQty = ($raw !== '' && is_numeric($raw)) ? (float)$raw : 0;
        if ($doneQty < 0) $doneQty = 0;

        $contractQty = (float)$validUnitMap[$uid];
        if ($contractQty > 0 && $doneQty > $contractQty) $doneQty = $contractQty;
        $totalQtySum += $contractQty;
        $doneQtySum += $doneQty;

        $upsert->bindValue(':pid', $projectId, \PDO::PARAM_INT);
        $upsert->bindValue(':tid', $taskId, \PDO::PARAM_INT);
        $upsert->bindValue(':uid', $uid, \PDO::PARAM_INT);
        $upsert->bindValue(':wd', ($taskDate !== '' ? $taskDate : null));        
        $upsert->bindValue(':tq', $contractQty);
        $upsert->bindValue(':dq', $doneQty);
        $upsert->bindValue(':cat', $now);
        $upsert->bindValue(':uat', $now);
        $upsert->execute();
    }

    if ($taskDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $taskDate)) {
        $upTaskProgress = $pdo->prepare("INSERT INTO cpms_schedule_progress
            (project_id, task_id, work_date, total_qty, done_qty, is_auto, is_manual, created_at, updated_at)
            VALUES (:pid, :tid, :wd, :tq, :dq, 0, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE total_qty=VALUES(total_qty), done_qty=VALUES(done_qty), is_auto=0, is_manual=1, updated_at=CURRENT_TIMESTAMP");
        $upTaskProgress->bindValue(':pid', $projectId, \PDO::PARAM_INT);
        $upTaskProgress->bindValue(':tid', $taskId, \PDO::PARAM_INT);
        $upTaskProgress->bindValue(':wd', $taskDate);
        $upTaskProgress->bindValue(':tq', $totalQtySum);
        $upTaskProgress->bindValue(':dq', $doneQtySum);
        $upTaskProgress->execute();
    }

    // 항목별 완료수량 기준으로 공정 progress 반영
    cpms_schedule_recalculate_task_progress($pdo, $projectId, $taskId, $totalQtySum);

    $pdo->commit();

    $suffix = '';
    if ($taskDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $taskDate)) {
        $suffix = '&month=' . substr($taskDate, 0, 7);
    }
    flash_set('success', '항목별 완료수량을 저장했습니다.');
    header('Location: ?r=공사&pid=' . $projectId . '&tab=gantt' . $suffix);
    exit;
} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    flash_set('error', '저장 실패: ' . $e->getMessage());
    header('Location: ?r=공사&pid=' . $projectId . '&tab=gantt');
    exit;
}
