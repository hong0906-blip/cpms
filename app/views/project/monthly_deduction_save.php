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
$pid=isset($_POST['project_id'])?(int)$_POST['project_id']:0; $ym=isset($_POST['ym'])?trim((string)$_POST['ym']):''; $name=isset($_POST['deduction_name'])?trim((string)$_POST['deduction_name']):''; $amount=isset($_POST['amount'])?(float)preg_replace('/[^0-9.\-]/','',(string)$_POST['amount']):0; $memo=isset($_POST['memo'])?trim((string)$_POST['memo']):'';
if ($pid<=0 || !preg_match('/^\d{4}-\d{2}$/',$ym) || $name==='') { flash_set('error','입력값을 확인하세요.'); header('Location: ?r=공무&tab=monthly_input&pid='.$pid); exit; }
$pdo=Db::pdo();
try {
    $st=$pdo->prepare('INSERT INTO cpms_project_monthly_deductions (project_id, ym, deduction_name, amount, memo, created_at, updated_at) VALUES (:pid,:ym,:nm,:am,:mm,NOW(),NOW())');
    $st->bindValue(':pid',$pid,\PDO::PARAM_INT); $st->bindValue(':ym',$ym); $st->bindValue(':nm',$name); $st->bindValue(':am',$amount); $st->bindValue(':mm',$memo); $st->execute();
    flash_set('success','공제분을 저장했습니다.');
} catch (Exception $e) {
    flash_set('error','공제분 저장 실패: '.$e->getMessage());
}
header('Location: ?r=공무&tab=monthly_input&pid='.$pid); exit;