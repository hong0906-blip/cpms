<?php
require_once __DIR__ . '/../../bootstrap.php';
use App\Core\Auth; use App\Core\Db;
if (!Auth::check()) { header('Location: ?r=login'); exit; }
$dept = (string)Auth::userDepartment();
$role = (string)Auth::userRole();
$ok = Auth::isMaster() || $role === 'executive' || $dept === '공무' || $dept === '관리' || $dept === '관리부';
if (!$ok) { http_response_code(403); echo '403 Forbidden'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ?r=공무&tab=monthly_input'); exit; }
if (!csrf_check(isset($_POST['_csrf'])?$_POST['_csrf']:'')) { flash_set('error','보안 토큰 오류'); header('Location: ?r=공무&tab=monthly_input'); exit; }
$id=isset($_POST['id'])?(int)$_POST['id']:0; $pid=isset($_POST['pid'])?(int)$_POST['pid']:0;
if ($id>0) {
    try {
        $pdo=Db::pdo(); $st=$pdo->prepare('DELETE FROM cpms_project_monthly_deductions WHERE id=:id'); $st->bindValue(':id',$id,\PDO::PARAM_INT); $st->execute();
        flash_set('success','공제분을 삭제했습니다.');
    } catch (Exception $e) {
        flash_set('error','공제분 삭제 실패: '.$e->getMessage());
    }
}
header('Location: ?r=공무&tab=monthly_input&pid='.$pid); exit;