<?php
/**
 * 공사: 공정 항목별 완료수량 저장(POST)
 * - 공사팀(공사) + 임원(executive)만 저장 가능
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';

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

$now = date('Y-m-d H:i:s');

try {
    $stTask = $pdo->prepare('SELECT id, name FROM cpms_schedule_tasks WHERE id=:tid AND project_id=:pid LIMIT 1');
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
        $stUnit = $pdo->prepare("SELECT id, qty FROM cpms_project_unit_prices WHERE project_id = ? AND id IN ($ph)");
        $stUnit->bindValue(1, $projectId, \PDO::PARAM_INT);
        foreach ($unitIds as $idx => $uid) {
            $stUnit->bindValue($idx + 2, $uid, \PDO::PARAM_INT);
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
        (project_id, task_id, unit_price_id, done_qty, work_date, created_at, updated_at)
        VALUES (:pid, :tid, :uid, :dq, :wd, :cat, :uat)
        ON DUPLICATE KEY UPDATE done_qty = VALUES(done_qty), work_date = VALUES(work_date), updated_at = VALUES(updated_at)");

    foreach ($itemDone as $k => $v) {
        $uid = (int)$k;
        if ($uid <= 0 || !isset($validUnitMap[$uid])) continue;

        $raw = preg_replace('/[^0-9.\-]/', '', (string)$v);
        $doneQty = ($raw !== '' && is_numeric($raw)) ? (float)$raw : 0;
        if ($doneQty < 0) $doneQty = 0;

        $contractQty = (float)$validUnitMap[$uid];
        if ($contractQty > 0 && $doneQty > $contractQty) $doneQty = $contractQty;

        $upsert->bindValue(':pid', $projectId, \PDO::PARAM_INT);
        $upsert->bindValue(':tid', $taskId, \PDO::PARAM_INT);
        $upsert->bindValue(':uid', $uid, \PDO::PARAM_INT);
        $upsert->bindValue(':dq', $doneQty);
        $upsert->bindValue(':wd', ($taskDate !== '' ? $taskDate : null));        
        $upsert->bindValue(':cat', $now);
        $upsert->bindValue(':uat', $now);
        $upsert->execute();
    }

    // 항목별 완료수량 기준으로 공정 progress 반영
    $stAgg = $pdo->prepare("SELECT
            COALESCE(SUM(COALESCE(p.done_qty, 0)), 0) AS done_sum,
            COALESCE(SUM(COALESCE(u.qty, 0)), 0) AS total_sum
        FROM cpms_schedule_task_item_progress p
        INNER JOIN cpms_project_unit_prices u ON u.id = p.unit_price_id AND u.project_id = p.project_id
        WHERE p.project_id = :pid AND p.task_id = :tid");
    $stAgg->bindValue(':pid', $projectId, \PDO::PARAM_INT);
    $stAgg->bindValue(':tid', $taskId, \PDO::PARAM_INT);
    $stAgg->execute();
    $agg = $stAgg->fetch();

    $pct = 0;
    if (is_array($agg)) {
        $doneSum = isset($agg['done_sum']) ? (float)$agg['done_sum'] : 0;
        $totalSum = isset($agg['total_sum']) ? (float)$agg['total_sum'] : 0;
        if ($totalSum > 0) {
            $pct = (int)round(($doneSum / $totalSum) * 100);
            if ($pct < 0) $pct = 0;
            if ($pct > 100) $pct = 100;
        }
    }

    $stUpd = $pdo->prepare('UPDATE cpms_schedule_tasks SET progress=:pct WHERE id=:tid AND project_id=:pid');
    $stUpd->bindValue(':pct', $pct, \PDO::PARAM_INT);
    $stUpd->bindValue(':tid', $taskId, \PDO::PARAM_INT);
    $stUpd->bindValue(':pid', $projectId, \PDO::PARAM_INT);
    $stUpd->execute();

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