<?php
/**
 * C:\www\cpms\app\views\construction\schedule_seed_from_template.php
 * - 공사: 템플릿을 공정표(간트) 태스크로 "초안 생성" (POST)
 *
 * 동작:
 * - cpms_process_templates의 공정들을 cpms_schedule_tasks로 생성
 * - 이미 같은 이름의 태스크가 있으면 건너뜀
 *
 * 권한:
 * - 공사팀(공사) + 임원(executive)만
 *
 * PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }

$role = Auth::userRole();
$dept = Auth::userDepartment();

if (!($role === 'executive' || $dept === '공사')) {
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
if ($projectId <= 0) {
    flash_set('error','프로젝트 정보가 올바르지 않습니다.');
    header('Location: ?r=공사');
    exit;
}

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error','DB 연결 실패');
    header('Location: ?r=공사&pid='.$projectId.'&tab=gantt');
    exit;
}

try {
    // 템플릿
    $st = $pdo->prepare("SELECT process_name, sort_order FROM cpms_process_templates WHERE project_id = :pid ORDER BY sort_order ASC, id ASC");
    $st->bindValue(':pid', $projectId, \PDO::PARAM_INT);
    $st->execute();
    $tpl = $st->fetchAll();

    if (!$tpl || count($tpl) === 0) {
        flash_set('error','템플릿이 없습니다. 먼저 템플릿 생성 버튼을 눌러주세요.');
        header('Location: ?r=공사&pid='.$projectId.'&tab=template');
        exit;
    }

    // 기존 태스크 이름 맵
    $st2 = $pdo->prepare("SELECT name FROM cpms_schedule_tasks WHERE project_id = :pid");
    $st2->bindValue(':pid', $projectId, \PDO::PARAM_INT);
    $st2->execute();
    $rows = $st2->fetchAll();

    $exists = array();
    foreach ($rows as $r) {
        $n = isset($r['name']) ? trim((string)$r['name']) : '';
        if ($n !== '') $exists[$n] = true;
    }

    $ins = $pdo->prepare("INSERT INTO cpms_schedule_tasks(project_id, name, sort_order) VALUES(:pid, :nm, :ord)");
    $added = 0;

    foreach ($tpl as $t) {
        $name = isset($t['process_name']) ? trim((string)$t['process_name']) : '';
        if ($name === '') continue;
        if (isset($exists[$name])) continue;

        $ord = isset($t['sort_order']) ? (int)$t['sort_order'] : 0;

        $ins->bindValue(':pid', $projectId, \PDO::PARAM_INT);
        $ins->bindValue(':nm', $name);
        $ins->bindValue(':ord', $ord, \PDO::PARAM_INT);
        $ins->execute();

        $added++;
    }

    if ($added > 0) flash_set('success',"공정표 초안 생성 완료: {$added}건 추가");
    else flash_set('success',"공정표가 이미 최신입니다. (추가된 항목 없음)");

    header('Location: ?r=공사&pid='.$projectId.'&tab=gantt');
    exit;

} catch (Exception $e) {
    flash_set('error','초안 생성 실패: '.$e->getMessage());
    header('Location: ?r=공사&pid='.$projectId.'&tab=gantt');
    exit;
}
