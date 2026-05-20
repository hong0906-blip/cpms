<?php
/**
 * C:\www\cpms\app\views\construction\schedule_save.php
 * - 공사: 공정표(간트) 태스크 저장(추가/수정) (POST)
 *
 * 권한:
 * - 공사팀(공사) + 임원(executive)만 수정/추가 가능
 *
 * PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/partials/schedule_auto_progress_helper.php';

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

$token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
if (!csrf_check($token)) {
    flash_set('error','보안 토큰이 유효하지 않습니다.');
    header('Location: ?r=공사');
    exit;
}

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$taskId    = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
$name      = isset($_POST['name']) ? trim((string)$_POST['name']) : '';
$startDate = isset($_POST['start_date']) ? trim((string)$_POST['start_date']) : '';
$endDate   = isset($_POST['end_date']) ? trim((string)$_POST['end_date']) : '';
$progress  = isset($_POST['progress']) ? (int)$_POST['progress'] : 0;
$workId    = isset($_POST['work_id']) ? (int)$_POST['work_id'] : 0; // 공정표-작업 연결
$month     = isset($_POST['month']) ? trim((string)$_POST['month']) : '';
$mode      = isset($_POST['mode']) ? trim((string)$_POST['mode']) : '';
$redirectSuffix = '';
if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
    $redirectSuffix .= '&month=' . urlencode($month);
}
/* 공정 추가 후 수정탭 유지: 저장 후에도 수정 모드(mode=edit)를 유지 */
if ($mode === 'edit') {
    $redirectSuffix .= '&mode=edit';
}

if ($projectId <= 0) { flash_set('error','프로젝트 정보가 올바르지 않습니다.'); header('Location: ?r=공사'); exit; }
if ($name === '') { flash_set('error','공정명을 입력해주세요.'); header('Location: ?r=공사&pid='.$projectId.'&tab=gantt'.$redirectSuffix); exit; }
if (mb_strlen($name,'UTF-8') > 255) { $name = mb_substr($name,0,255,'UTF-8'); }

if ($progress < 0) $progress = 0;
if ($progress > 100) $progress = 100;

// 날짜 형식 체크(빈 값 허용)
function valid_date($ymd) {
    if ($ymd === '') return true;
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd) ? true : false;
}
if (!valid_date($startDate) || !valid_date($endDate)) {
    flash_set('error','날짜 형식이 올바르지 않습니다.');
    header('Location: ?r=공사&pid='.$projectId.'&tab=gantt'.$redirectSuffix);
    exit;
}

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error','DB 연결 실패');
    header('Location: ?r=공사&pid='.$projectId.'&tab=gantt'.$redirectSuffix);
    exit;
}

try {
    if ($taskId > 0) {
        $st = $pdo->prepare("UPDATE cpms_schedule_tasks
                             SET name = :nm, start_date = :sd, end_date = :ed, progress = :pr, work_id = :wid
                             WHERE id = :id AND project_id = :pid");
        $st->bindValue(':nm', $name);
        $st->bindValue(':sd', $startDate !== '' ? $startDate : null, $startDate !== '' ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        $st->bindValue(':ed', $endDate !== '' ? $endDate : null, $endDate !== '' ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        $st->bindValue(':pr', $progress, \PDO::PARAM_INT);
        $st->bindValue(':id', $taskId, \PDO::PARAM_INT);
        $st->bindValue(':wid', $workId > 0 ? $workId : null, $workId > 0 ? \PDO::PARAM_INT : \PDO::PARAM_NULL);        
        $st->bindValue(':pid', $projectId, \PDO::PARAM_INT);
        $st->execute();
        cpms_schedule_apply_auto_progress($pdo, $projectId);
        flash_set('success','공정표가 저장되었습니다.');
    } else {
        // sort_order는 맨 뒤로
        $ord = 0;
        try {
            $stO = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM cpms_schedule_tasks WHERE project_id = :pid");
            $stO->bindValue(':pid', $projectId, \PDO::PARAM_INT);
            $stO->execute();
            $ord = (int)$stO->fetchColumn();
        } catch (Exception $e) { $ord = 0; }

        $ins = $pdo->prepare("INSERT INTO cpms_schedule_tasks(project_id, name, start_date, end_date, progress, sort_order, work_id)
                              VALUES(:pid, :nm, :sd, :ed, :pr, :ord, :wid)");
        $ins->bindValue(':pid', $projectId, \PDO::PARAM_INT);
        $ins->bindValue(':nm', $name);
        $ins->bindValue(':sd', $startDate !== '' ? $startDate : null, $startDate !== '' ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        $ins->bindValue(':ed', $endDate !== '' ? $endDate : null, $endDate !== '' ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        $ins->bindValue(':pr', $progress, \PDO::PARAM_INT);
        $ins->bindValue(':ord', $ord, \PDO::PARAM_INT);
        $ins->bindValue(':wid', $workId > 0 ? $workId : null, $workId > 0 ? \PDO::PARAM_INT : \PDO::PARAM_NULL);
        $ins->execute();
        cpms_schedule_apply_auto_progress($pdo, $projectId);
        flash_set('success','공정이 추가되었습니다.');
    }

    header('Location: ?r=공사&pid='.$projectId.'&tab=gantt'.$redirectSuffix);
    exit;

} catch (Exception $e) {
    flash_set('error','저장 실패: '.$e->getMessage());
    header('Location: ?r=공사&pid='.$projectId.'&tab=gantt'.$redirectSuffix);
    exit;
}
