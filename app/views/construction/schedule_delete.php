<?php
/**
 * C:\www\cpms\app\views\construction\schedule_delete.php
 * - 공사: 공정표(간트) 태스크 삭제(POST)
 *
 * 권한:
 * - 공사팀(공사) + 임원(executive)만 삭제 가능
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
$taskId    = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;

if ($projectId <= 0 || $taskId <= 0) {
    flash_set('error','삭제 정보가 올바르지 않습니다.');
    header('Location: ?r=공사&pid='.$projectId.'&tab=gantt');
    exit;
}

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error','DB 연결 실패');
    header('Location: ?r=공사&pid='.$projectId.'&tab=gantt');
    exit;
}

try {
    $st = $pdo->prepare("DELETE FROM cpms_schedule_tasks WHERE id = :id AND project_id = :pid");
    $st->bindValue(':id', $taskId, \PDO::PARAM_INT);
    $st->bindValue(':pid', $projectId, \PDO::PARAM_INT);
    $st->execute();

    flash_set('success','삭제되었습니다.');
    header('Location: ?r=공사&pid='.$projectId.'&tab=gantt');
    exit;

} catch (Exception $e) {
    flash_set('error','삭제 실패: '.$e->getMessage());
    header('Location: ?r=공사&pid='.$projectId.'&tab=gantt');
    exit;
}
