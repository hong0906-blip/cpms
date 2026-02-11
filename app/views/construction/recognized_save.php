<?php
/** 공사 > 원가/공정 > 월별 인정기성 저장 */
require_once __DIR__ . '/../../bootstrap.php';
use App\Core\Auth; use App\Core\Db;
if (!Auth::check()) { header('Location:?r=login'); exit; }
if (!(Auth::userRole()==='executive' || Auth::userDepartment()==='공사')) { http_response_code(403); exit; }
if ($_SERVER['REQUEST_METHOD']!=='POST' || !csrf_check(isset($_POST['_csrf'])?(string)$_POST['_csrf']:'')) { header('Location:?r=공사'); exit; }
$pid=(int)$_POST['project_id']; $ym=trim((string)$_POST['ym']); $amt=(float)preg_replace('/[^0-9\.\-]/','',(string)$_POST['recognized_cum_amount']);
$pdo=Db::pdo();
try{ $st=$pdo->prepare("INSERT INTO cpms_monthly_recognized(project_id, ym, recognized_cum_amount) VALUES(:pid,:ym,:amt) ON DUPLICATE KEY UPDATE recognized_cum_amount=VALUES(recognized_cum_amount)"); $st->bindValue(':pid',$pid,PDO::PARAM_INT); $st->bindValue(':ym',$ym); $st->bindValue(':amt',$amt); $st->execute(); flash_set('success','인정기성 저장 완료'); }
catch(Exception $e){ flash_set('error','저장 실패: '.$e->getMessage()); }
header('Location:?r=공사&pid='.$pid.'&tab=cost_progress&sub=recogn'); exit;