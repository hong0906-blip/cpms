<?php
/**
 * C:\www\cpms\app\views\construction\schedule_progress_save.php
 * - 공사: 공정 진행(수량/사진) 저장(POST)
 * - 공사팀(공사) + 임원(executive)만 저장 가능
 *
 * PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/partials/schedule_auto_progress_helper.php';
require_once __DIR__ . '/../../services/ConstructionDriveService.php';

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

if (!function_exists('cpms_schedule_progress_insert_photo_row')) {
function cpms_schedule_progress_insert_photo_row($pdo, $values) {
    if (!$pdo || !is_array($values)) throw new Exception('사진 저장 데이터가 올바르지 않습니다.');
    $columns = array();
    $holders = array();
    $params = array();
    foreach ($values as $column => $value) {
        $column = trim((string)$column);
        if ($column === '') continue;
        if (!cpms_construction_drive_column_exists($pdo, 'cpms_schedule_progress_photos', $column)) continue;
        $columns[] = '`' . $column . '`';
        $holders[] = ':' . $column;
        $params[':' . $column] = $value;
    }
    if (count($columns) === 0) throw new Exception('사진 저장 테이블을 확인할 수 없습니다.');
    $sql = "INSERT INTO cpms_schedule_progress_photos (" . implode(',', $columns) . ") VALUES (" . implode(',', $holders) . ")";
    $st = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        if ($value === null) $st->bindValue($key, null, \PDO::PARAM_NULL);
        else $st->bindValue($key, $value);
    }
    $st->execute();
    return (int)$pdo->lastInsertId();
}}

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$taskId = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
$workDate = isset($_POST['work_date']) ? trim((string)$_POST['work_date']) : '';
$totalQtyRaw = isset($_POST['total_qty']) ? (string)$_POST['total_qty'] : '';
$doneQtyRaw = isset($_POST['done_qty']) ? (string)$_POST['done_qty'] : '';
$action = isset($_POST['action']) ? trim((string)$_POST['action']) : 'save';
$shiftDays = isset($_POST['shift_days']) ? (int)$_POST['shift_days'] : 0;
$shiftFrom = isset($_POST['shift_from']) ? trim((string)$_POST['shift_from']) : cpms_schedule_auto_today();

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
    $shiftFrom = cpms_schedule_auto_today();
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
cpms_schedule_auto_ensure_schema($pdo);
cpms_construction_drive_ensure_table_columns($pdo, 'cpms_schedule_progress_photos');

$now = date('Y-m-d H:i:s');
$currentUser = Auth::user();
$currentUserId = (is_array($currentUser) && isset($currentUser['id'])) ? (int)$currentUser['id'] : 0;
$uploadedDriveRecords = array();
$photoDriveUploadFailed = false;

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

    /* 완료수량→진행률 반영: 완료수량 저장/이동 이후 task 진행률을 자동 계산해 cpms_schedule_tasks.progress 갱신 */
    $recalculateTaskProgress = function($pdoConn, $projectIdVal, $taskIdVal) {
        $stCalc = $pdoConn->prepare("
            SELECT
                MAX(CASE WHEN total_qty IS NOT NULL THEN total_qty END) AS total_qty_ref,
                COALESCE(SUM(COALESCE(done_qty, 0)), 0) AS done_qty_sum
            FROM cpms_schedule_progress
            WHERE project_id = :pid AND task_id = :tid
        ");
        $stCalc->bindValue(':pid', (int)$projectIdVal, \PDO::PARAM_INT);
        $stCalc->bindValue(':tid', (int)$taskIdVal, \PDO::PARAM_INT);
        $stCalc->execute();
        $calcRow = $stCalc->fetch();
        if (!is_array($calcRow)) return;

        $totalQtyRef = isset($calcRow['total_qty_ref']) ? (float)$calcRow['total_qty_ref'] : 0;
        $doneQtySum = isset($calcRow['done_qty_sum']) ? (float)$calcRow['done_qty_sum'] : 0;
        if ($totalQtyRef <= 0) return; // 전체수량 정보가 없으면 기존 progress 유지

        $pct = 0;
        if ($doneQtySum > 0) {
            $pct = (int)round(min(100, ($doneQtySum / $totalQtyRef) * 100));
        }
        if ($pct < 0) $pct = 0;
        if ($pct > 100) $pct = 100;

        $stUpd = $pdoConn->prepare("UPDATE cpms_schedule_tasks SET progress = :pct WHERE id = :tid AND project_id = :pid");
        $stUpd->bindValue(':pct', $pct, \PDO::PARAM_INT);
        $stUpd->bindValue(':tid', (int)$taskIdVal, \PDO::PARAM_INT);
        $stUpd->bindValue(':pid', (int)$projectIdVal, \PDO::PARAM_INT);
        $stUpd->execute();
    };

    if ($action === 'shift') {
        if ($shiftDays === 0) {
            throw new Exception('이동 일수는 0일이 될 수 없습니다.');
        }
        $today = cpms_schedule_auto_today();
        if ($shiftFrom < $today) $shiftFrom = $today;

        $stRows = $pdo->prepare("SELECT id, work_date, total_qty, done_qty, is_auto, is_manual FROM cpms_schedule_progress WHERE project_id=:pid AND task_id=:tid AND work_date >= :wf AND is_manual=1 ORDER BY work_date ASC, id ASC");
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

                $up = $pdo->prepare("UPDATE cpms_schedule_progress SET total_qty=:tq, done_qty=:dq, is_auto=0, is_manual=1, updated_at=CURRENT_TIMESTAMP WHERE id=:id");
                if ($mergedTotal === null) $up->bindValue(':tq', null, \PDO::PARAM_NULL);
                else $up->bindValue(':tq', $mergedTotal);
                if ($mergedDone === null) $up->bindValue(':dq', null, \PDO::PARAM_NULL);
                else $up->bindValue(':dq', $mergedDone);
                $up->bindValue(':id', $targetId, \PDO::PARAM_INT);
                $up->execute();
            } else {
                $insT = $pdo->prepare("INSERT INTO cpms_schedule_progress(project_id, task_id, work_date, total_qty, done_qty, is_auto, is_manual, created_at, updated_at) VALUES(:pid, :tid, :wd, :tq, :dq, 0, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
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

        cpms_schedule_apply_auto_progress($pdo, $projectId);
        $recalculateTaskProgress($pdo, $projectId, $taskId);        
        $pdo->commit();
        flash_set('success', '일정 이동이 적용되었습니다. (오늘~미래 수동 입력값 이동, 충돌 시 합치기)');
        header('Location: ?r=공사&pid=' . $projectId . '&tab=gantt' . $redirectSuffix);
        exit;
    }

    $sql = "INSERT INTO cpms_schedule_progress (project_id, task_id, work_date, total_qty, done_qty, is_auto, is_manual, created_at, updated_at)
            VALUES (:pid, :tid, :wd, :tq, :dq, 0, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE total_qty = VALUES(total_qty), done_qty = VALUES(done_qty), is_auto = 0, is_manual = 1, updated_at = CURRENT_TIMESTAMP";
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
    $stp = $pdo->prepare("SELECT id FROM cpms_schedule_progress WHERE project_id = :pid AND task_id = :tid AND work_date = :wd LIMIT 1");
    $stp->bindValue(':pid', $projectId, \PDO::PARAM_INT);
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
                $driveUploadResult = cpms_construction_drive_upload_local_file(
                    $pdo,
                    (int)$projectId,
                    $target,
                    $name,
                    'photo',
                    $workDate,
                    $now,
                    array('date' => $workDate),
                    $currentUser
                );
                $driveRecord = (isset($driveUploadResult['record']) && is_array($driveUploadResult['record'])) ? $driveUploadResult['record'] : array();
                if (!is_array($driveUploadResult) || empty($driveUploadResult['ok'])) {
                    $photoDriveUploadFailed = true;
                }
                $photoValues = array(
                    'progress_id' => (int)$progressId,
                    'file_path' => $publicPath,
                    'file_name' => $name,
                    'file_size' => (int)$size
                );
                if (is_array($driveRecord) && count($driveRecord) > 0) {
                    $driveValues = cpms_construction_drive_record_values($driveRecord, $currentUserId);
                    foreach ($driveValues as $driveColumn => $driveValue) {
                        $photoValues[$driveColumn] = $driveValue;
                    }
                }
                try {
                    cpms_schedule_progress_insert_photo_row($pdo, $photoValues);
                    if (is_array($driveUploadResult) && !empty($driveUploadResult['ok']) && isset($driveRecord['drive_file_id']) && trim((string)$driveRecord['drive_file_id']) !== '') {
                        $uploadedDriveRecords[] = $driveRecord;
                    }
                } catch (Exception $photoInsertException) {
                    if (is_array($driveUploadResult) && !empty($driveUploadResult['ok']) && isset($driveRecord['drive_file_id']) && trim((string)$driveRecord['drive_file_id']) !== '') {
                        cpms_drive_delete_file((string)$driveRecord['drive_file_id'], array(
                            'section' => 'construction',
                            'project_id' => (int)$projectId,
                            'document_type' => 'photo',
                            'document_year' => isset($driveRecord['document_year']) ? (string)$driveRecord['document_year'] : '',
                            'document_month' => isset($driveRecord['document_month']) ? (string)$driveRecord['document_month'] : '',
                            'original_name' => $name,
                            'target_folder_id' => isset($driveRecord['drive_folder_id']) ? (string)$driveRecord['drive_folder_id'] : '',
                            'message' => 'Construction photo metadata save failed: ' . $photoInsertException->getMessage()
                        ));
                    }
                    throw $photoInsertException;
                }
            }
        }
    }

    $recalculateTaskProgress($pdo, $projectId, $taskId);    
    $pdo->commit();
    $successMessage = '공정 진행 정보가 저장되었습니다.';
    if ($photoDriveUploadFailed) {
        $successMessage = cpms_construction_drive_flash_message($successMessage, array('ok' => false));
    }
    flash_set('success', $successMessage);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($uploadedDriveRecords as $uploadedDriveRecord) {
        if (!is_array($uploadedDriveRecord) || !isset($uploadedDriveRecord['drive_file_id']) || trim((string)$uploadedDriveRecord['drive_file_id']) === '') continue;
        cpms_drive_delete_file((string)$uploadedDriveRecord['drive_file_id'], array(
            'section' => 'construction',
            'project_id' => (int)$projectId,
            'document_type' => isset($uploadedDriveRecord['document_type']) ? (string)$uploadedDriveRecord['document_type'] : 'photo',
            'document_year' => isset($uploadedDriveRecord['document_year']) ? (string)$uploadedDriveRecord['document_year'] : '',
            'document_month' => isset($uploadedDriveRecord['document_month']) ? (string)$uploadedDriveRecord['document_month'] : '',
            'original_name' => isset($uploadedDriveRecord['original_name']) ? (string)$uploadedDriveRecord['original_name'] : '',
            'target_folder_id' => isset($uploadedDriveRecord['drive_folder_id']) ? (string)$uploadedDriveRecord['drive_folder_id'] : '',
            'message' => 'Construction photo CPMS transaction failed: ' . $e->getMessage()
        ));
    }
    flash_set('error', '저장 실패: ' . $e->getMessage());
}

header('Location: ?r=공사&pid=' . $projectId . '&tab=gantt' . $redirectSuffix);
exit;
