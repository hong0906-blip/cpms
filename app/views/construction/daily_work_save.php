<?php
/** 공사 > 원가/공정 > 실적수량 저장 */
require_once __DIR__ . '/../../bootstrap.php';
use App\Core\Auth; use App\Core\Db;
if (!Auth::check()) { header('Location:?r=login'); exit; }
if (!(Auth::userRole()==='executive' || Auth::userDepartment()==='공사')) { http_response_code(403); exit; }
if ($_SERVER['REQUEST_METHOD']!=='POST' || !csrf_check(isset($_POST['_csrf'])?(string)$_POST['_csrf']:'')) { header('Location:?r=공사'); exit; }
$pid=(int)$_POST['project_id']; $uid=(int)$_POST['unit_price_id']; $d=trim((string)$_POST['work_date']); $qty=(float)preg_replace('/[^0-9\.\-]/','',(string)$_POST['done_qty']); $memo=trim((string)$_POST['memo']);
$pdo=Db::pdo(); if(!$pdo){ flash_set('error','DB 연결 실패'); header('Location:?r=공사&pid='.$pid.'&tab=cost_progress&sub=work'); exit; }
try{
 $st=$pdo->prepare("SELECT COALESCE(qty,0) cqty FROM cpms_project_unit_prices WHERE id=:id AND project_id=:pid"); $st->bindValue(':id',$uid,PDO::PARAM_INT); $st->bindValue(':pid',$pid,PDO::PARAM_INT); $st->execute(); $cqty=(float)$st->fetchColumn();
 $st=$pdo->prepare("SELECT COALESCE(SUM(done_qty),0) FROM cpms_daily_work_qty WHERE project_id=:pid AND unit_price_id=:uid"); $st->bindValue(':pid',$pid,PDO::PARAM_INT); $st->bindValue(':uid',$uid,PDO::PARAM_INT); $st->execute(); $sum=(float)$st->fetchColumn();
 if (($sum+$qty) > $cqty && $cqty > 0) { flash_set('error','계약 수량을 초과하여 저장할 수 없습니다.'); }
 else {
  $sql="INSERT INTO cpms_daily_work_qty(project_id, unit_price_id, work_date, done_qty, memo) VALUES(:pid,:uid,:wd,:dq,:memo) ON DUPLICATE KEY UPDATE done_qty=VALUES(done_qty), memo=VALUES(memo)";
  $st=$pdo->prepare($sql); $st->bindValue(':pid',$pid,PDO::PARAM_INT); $st->bindValue(':uid',$uid,PDO::PARAM_INT); $st->bindValue(':wd',$d); $st->bindValue(':dq',$qty); $st->bindValue(':memo',$memo); $st->execute();
  flash_set('success','실적수량 저장 완료');
 }
}catch(Exception $e){ flash_set('error','저장 실패: '.$e->getMessage()); }
header('Location:?r=공사&pid='.$pid.'&tab=cost_progress&sub=work'); exit;