<?php
require_once __DIR__ . '/../../bootstrap.php';
use App\Core\Auth; use App\Core\Db;
if (!Auth::check()) { header('Location: ?r=login'); exit; }
$id=isset($_GET['id'])?(int)$_GET['id']:0; $pid=isset($_GET['pid'])?(int)$_GET['pid']:0;
if ($id>0) { $pdo=Db::pdo(); $st=$pdo->prepare('DELETE FROM cpms_project_monthly_deductions WHERE id=:id'); $st->bindValue(':id',$id,\PDO::PARAM_INT); $st->execute(); flash_set('success','공제분을 삭제했습니다.'); }
header('Location: ?r=공무&tab=monthly_input&pid='.$pid); exit;