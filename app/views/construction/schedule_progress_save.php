<?php
/**
 * C:\www\cpms\app\views\construction\schedule_progress_save.php
 * - 공사: 공정 진행(수량/사진) 저장(POST)
 * - 공사팀(공사) + 임원(executive)만 저장 가능
 *
 * PHP 5.6 호환
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
if (!($role === 'executive' || $dept === '공사')) {
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
$workDate = isset($_POST['work_date']) ? trim((string)$_POST['work_date']) : '';
$totalQtyRaw = isset($_POST['total_qty']) ? (string)$_POST['total_qty'] : '';
$doneQtyRaw = isset($_POST['done_qty']) ? (string)$_POST['done_qty'] : '';
$action = isset($_POST['action']) ? trim((string)$_POST['action']) : 'save';
$shiftDays = isset($_POST['shift_days']) ? (int)$_POST['shift_days'] : 0;
$shiftFrom = isset($_POST['shift_from']) ? trim((string)$_POST['shift_from']) : date('Y-m-d');

if ($projectId <= 0 || $taskId <= 0) {
    flash_set('error', '프로젝트/공정 정보가 올바르지 않습니다.');
    header('Location: ?r=공사');
    exit;
}
if ($workDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
    flash_set('error', '작업 날짜가 올바르지 않습니다.');
    header('Location: ?r=공사&pid=' . $projectId . '&tab=gantt');
    exit;
}
if ($shiftFrom === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $shiftFrom)) {
    $shiftFrom = date('Y-m-d');
}

$redirectMonth = substr($workDate, 0, 7);
$redirectSuffix = '';
if (preg_match('/^\d{4}-\d{2}$/', $redirectMonth)) {
    $redirectSuffix = '&month=' . $redirectMonth;
}

$toNumber = function($raw) {
    $clean = preg_replace('/[^0-9.\-]/', '', (string)$raw);
    if ($clean === '' || !is_numeric($clean)) return null;
    return (float)$clean;
};
$ymdAddDays = function($ymd, $days) {
    $ts = strtotime($ymd . ' 00:00:00');
    if ($ts === false || $ts <= 0) return '';
    return date('Y-m-d', $ts + (86400 * (int)$days));
};

$totalQty = $toNumber($totalQtyRaw);
$doneQty = $toNumber($doneQtyRaw);
if ($totalQty !== null && $totalQty < 0) $totalQty = 0;
if ($doneQty !== null && $doneQty < 0) $doneQty = 0;

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error', 'DB 연결 실패');
    header('Location: ?r=공사&pid=' . $projectId . '&tab=gantt' . $redirectSuffix);
    exit;
}

try {
    $st = $pdo->prepare("SELECT id, start_date, end_date FROM cpms_schedule_tasks WHERE id = :tid AND project_id = :pid LIMIT 1");
    $st->bindValue(':tid', $taskId, \PDO::PARAM_INT);
    $st->bindValue(':pid', $projectId, \PDO::PARAM_INT);
    $st->execute();
    $taskRow = $st->fetch();
    if (!is_array($taskRow)) {
        flash_set('error', '공정 정보를 찾을 수 없습니다.');
        header('Location: ?r=공사&pid=' . $projectId . '&tab=gantt' . $redirectSuffix);
        exit;
    }

    $pdo->beginTransaction();
    
    if ($action === 'shift') {
        if ($shiftDays === 0) {
            throw new Exception('이동 일수는 0일이 될 수 없습니다.');
        }
        $today = date('Y-m-d');
        if ($shiftFrom < $today) $shiftFrom = $today;

        $stRows = $pdo->prepare("SELECT id, work_date, total_qty, done_qty FROM cpms_schedule_progress WHERE project_id=:pid AND task_id=:tid AND work_date >= :wf ORDER BY work_date ASC, id ASC");
        $stRows->bindValue(':pid', $projectId, \PDO::PARAM_INT);
        $stRows->bindValue(':tid', $taskId, \PDO::PARAM_INT);
        $stRows->bindValue(':wf', $shiftFrom);
        $stRows->execute();
        $moveRows = $stRows->fetchAll();

        $group = array();
        if (is_array($moveRows)) {
            foreach ($moveRows as $mr) {
                $srcId = isset($mr['id']) ? (int)$mr['id'] : 0;
                $srcDate = isset($mr['work_date']) ? (string)$mr['work_date'] : '';
                if ($srcId <= 0 || $srcDate === '') continue;
                $targetDate = $ymdAddDays($srcDate, $shiftDays);
                if ($targetDate === '' || $targetDate < $today) $targetDate = $today;

                if (!isset($group[$targetDate])) {
                    $group[$targetDate] = array(
                        'source_ids' => array(),
                        'done_sum' => 0,
                        'has_done' => false,
                        'total_qty' => null
                    );
                }
                $group[$targetDate]['source_ids'][] = $srcId;

                $d = $toNumber(isset($mr['done_qty']) ? $mr['done_qty'] : null);
                if ($d !== null) {
                    $group[$targetDate]['done_sum'] += $d;
                    $group[$targetDate]['has_done'] = true;
                }
                if ($group[$targetDate]['total_qty'] === null) {
                    $tq = $toNumber(isset($mr['total_qty']) ? $mr['total_qty'] : null);
                    if ($tq !== null) $group[$targetDate]['total_qty'] = $tq;
                }
            }
        }

        $deleteIds = array();
        foreach ($group as $targetDate => $bundle) {
            $stTarget = $pdo->prepare("SELECT id, done_qty, total_qty FROM cpms_schedule_progress WHERE project_id=:pid AND task_id=:tid AND work_date=:wd LIMIT 1");
            $stTarget->bindValue(':pid', $projectId, \PDO::PARAM_INT);
            $stTarget->bindValue(':tid', $taskId, \PDO::PARAM_INT);
            $stTarget->bindValue(':wd', $targetDate);
            $stTarget->execute();
            $targetRow = $stTarget->fetch();

            $targetId = 0;
            $mergedDone = $bundle['has_done'] ? $bundle['done_sum'] : null;
            $mergedTotal = $bundle['total_qty'];
            if (is_array($targetRow)) {
                $targetId = (int)$targetRow['id'];
                $oldDone = $toNumber(isset($targetRow['done_qty']) ? $targetRow['done_qty'] : null);
                if ($oldDone !== null) {
                    if ($mergedDone === null) $mergedDone = 0;
                    $mergedDone += $oldDone;
                }
                if ($mergedTotal === null) {
                    $oldTotal = $toNumber(isset($targetRow['total_qty']) ? $targetRow['total_qty'] : null);
                    if ($oldTotal !== null) $mergedTotal = $oldTotal;
                }

                $up = $pdo->prepare("UPDATE cpms_schedule_progress SET total_qty=:tq, done_qty=:dq, updated_at=CURRENT_TIMESTAMP WHERE id=:id");
                if ($mergedTotal === null) $up->bindValue(':tq', null, \PDO::PARAM_NULL);
                else $up->bindValue(':tq', $mergedTotal);
                if ($mergedDone === null) $up->bindValue(':dq', null, \PDO::PARAM_NULL);
                else $up->bindValue(':dq', $mergedDone);
                $up->bindValue(':id', $targetId, \PDO::PARAM_INT);
                $up->execute();
            } else {
                $insT = $pdo->prepare("INSERT INTO cpms_schedule_progress(project_id, task_id, work_date, total_qty, done_qty, created_at, updated_at) VALUES(:pid, :tid, :wd, :tq, :dq, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
                $insT->bindValue(':pid', $projectId, \PDO::PARAM_INT);
                $insT->bindValue(':tid', $taskId, \PDO::PARAM_INT);
                $insT->bindValue(':wd', $targetDate);
                if ($mergedTotal === null) $insT->bindValue(':tq', null, \PDO::PARAM_NULL);
                else $insT->bindValue(':tq', $mergedTotal);
                if ($mergedDone === null) $insT->bindValue(':dq', null, \PDO::PARAM_NULL);
                else $insT->bindValue(':dq', $mergedDone);
                $insT->execute();
                $targetId = (int)$pdo->lastInsertId();
            }

            if ($targetId > 0) {
                foreach ($bundle['source_ids'] as $sid) {
                    if ($sid === $targetId) continue;
                    $mvPhoto = $pdo->prepare("UPDATE cpms_schedule_progress_photos SET progress_id=:toid WHERE progress_id=:fromid");
                    $mvPhoto->bindValue(':toid', $targetId, \PDO::PARAM_INT);
                    $mvPhoto->bindValue(':fromid', $sid, \PDO::PARAM_INT);
                    $mvPhoto->execute();
                    $deleteIds[] = $sid;
                }
            }
        }

        if (count($deleteIds) > 0) {
            $deleteIds = array_values(array_unique($deleteIds));
            $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
            $del = $pdo->prepare("DELETE FROM cpms_schedule_progress WHERE id IN ($placeholders)");
            foreach ($deleteIds as $idx => $did) {
                $del->bindValue($idx + 1, (int)$did, \PDO::PARAM_INT);
            }
            $del->execute();
        }

        $startDate = isset($taskRow['start_date']) ? trim((string)$taskRow['start_date']) : '';
        $endDate = isset($taskRow['end_date']) ? trim((string)$taskRow['end_date']) : '';
        $newStart = ($startDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) ? $ymdAddDays($startDate, $shiftDays) : '';
        $newEnd = ($endDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) ? $ymdAddDays($endDate, $shiftDays) : '';

        $upTask = $pdo->prepare("UPDATE cpms_schedule_tasks SET start_date=:sd, end_date=:ed WHERE id=:tid AND project_id=:pid");
        if ($newStart === '') $upTask->bindValue(':sd', null, \PDO::PARAM_NULL);
        else $upTask->bindValue(':sd', $newStart);
        if ($newEnd === '') $upTask->bindValue(':ed', null, \PDO::PARAM_NULL);
        else $upTask->bindValue(':ed', $newEnd);
        $upTask->bindValue(':tid', $taskId, \PDO::PARAM_INT);
        $upTask->bindValue(':pid', $projectId, \PDO::PARAM_INT);
        $upTask->execute();

        $pdo->commit();
        flash_set('success', '일정 이동이 적용되었습니다. (오늘~미래 수동 입력값 이동, 충돌 시 합치기)');
        header('Location: ?r=공사&pid=' . $projectId . '&tab=gantt' . $redirectSuffix);
        exit;
    }

    $sql = "INSERT INTO cpms_schedule_progress (project_id, task_id, work_date, total_qty, done_qty, created_at)
            VALUES (:pid, :tid, :wd, :tq, :dq, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE total_qty = VALUES(total_qty), done_qty = VALUES(done_qty), updated_at = CURRENT_TIMESTAMP";
    $ins = $pdo->prepare($sql);
    $ins->bindValue(':pid', $projectId, \PDO::PARAM_INT);
    $ins->bindValue(':tid', $taskId, \PDO::PARAM_INT);
    $ins->bindValue(':wd', $workDate);
    if ($totalQty === null) $ins->bindValue(':tq', null, \PDO::PARAM_NULL);
    else $ins->bindValue(':tq', $totalQty);
    if ($doneQty === null) $ins->bindValue(':dq', null, \PDO::PARAM_NULL);
    else $ins->bindValue(':dq', $doneQty);
    $ins->execute();

    $progressId = 0;
    $stp = $pdo->prepare("SELECT id FROM cpms_schedule_progress WHERE task_id = :tid AND work_date = :wd LIMIT 1");
    $stp->bindValue(':tid', $taskId, \PDO::PARAM_INT);
    $stp->bindValue(':wd', $workDate);
    $stp->execute();
    $rowp = $stp->fetch();
    if (is_array($rowp)) $progressId = (int)$rowp['id'];

    if ($progressId > 0 && isset($_FILES['photos']) && is_array($_FILES['photos']['name'])) {
        $projectRoot = realpath(__DIR__ . '/../../..');
        if ($projectRoot === false) $projectRoot = __DIR__ . '/../../..';
        $baseDir = $projectRoot . '/public/uploads/construction';
        if (!is_dir($baseDir)) @mkdir($baseDir, 0755, true);
        if (is_dir($baseDir) && is_writable($baseDir)) {
            $allowExt = array('jpg','jpeg','png','webp');
            $allowMime = array('image/jpeg', 'image/png', 'image/webp');
            $count = count($_FILES['photos']['name']);
            for ($i = 0; $i < $count; $i++) {
                $err = isset($_FILES['photos']['error'][$i]) ? (int)$_FILES['photos']['error'][$i] : UPLOAD_ERR_NO_FILE;
                if ($err !== UPLOAD_ERR_OK) continue;
                $size = isset($_FILES['photos']['size'][$i]) ? (int)$_FILES['photos']['size'][$i] : 0;
                if ($size <= 0 || $size > 5 * 1024 * 1024) continue;
                $name = isset($_FILES['photos']['name'][$i]) ? (string)$_FILES['photos']['name'][$i] : '';
                $tmp = isset($_FILES['photos']['tmp_name'][$i]) ? (string)$_FILES['photos']['tmp_name'][$i] : '';
                if ($tmp === '' || !is_uploaded_file($tmp)) continue;
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowExt, true)) continue;
                $mime = '';
                if (function_exists('finfo_open')) {
                    $fi = @finfo_open(FILEINFO_MIME_TYPE);
                    if ($fi) {
                        $mime = @finfo_file($fi, $tmp);
                        @finfo_close($fi);
                    }
                }
                if ($mime !== '' && !in_array($mime, $allowMime, true)) continue;

                $filename = 'gantt_' . $progressId . '_' . date('Ymd_His') . '_' . mt_rand(1000, 9999) . '.' . $ext;
                $target = $baseDir . '/' . $filename;
                if (!@move_uploaded_file($tmp, $target)) continue;
                if (!is_file($target) || (int)@filesize($target) <= 0) {
                    @unlink($target);
                    continue;
                }
                @chmod($target, 0644);

                $publicPath = asset_url('uploads/construction/' . $filename);
                $insPhoto = $pdo->prepare("INSERT INTO cpms_schedule_progress_photos (progress_id, file_path, file_name, file_size)
                                           VALUES (:pid, :path, :name, :size)");
                $insPhoto->bindValue(':pid', $progressId, \PDO::PARAM_INT);
                $insPhoto->bindValue(':path', $publicPath);
                $insPhoto->bindValue(':name', $name);
                $insPhoto->bindValue(':size', $size, \PDO::PARAM_INT);
                $insPhoto->execute();
            }
        }
    }

    $pdo->commit();
    flash_set('success', '공정 진행 정보가 저장되었습니다.');
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash_set('error', '저장 실패: ' . $e->getMessage());
}

header('Location: ?r=공사&pid=' . $projectId . '&tab=gantt' . $redirectSuffix);
exit;