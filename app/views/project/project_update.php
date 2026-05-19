<?php
require_once __DIR__ . '/../../bootstrap.php';
use App\Core\Auth; use App\Core\Db;
if (!Auth::check()) { header('Location: ?r=login'); exit; }
$role = Auth::userRole(); $dept = Auth::userDepartment();
$allowed = ($role === 'executive' || $dept === '공무' || $dept === '관리' || $dept === '관리부');
if (!$allowed) { http_response_code(403); echo '403 Forbidden'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) { flash_set('error','보안 토큰이 유효하지 않습니다.'); header('Location: ?r=공무'); exit; }
$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
if ($projectId <= 0) { flash_set('error','프로젝트 정보가 올바르지 않습니다.'); header('Location: ?r=공무'); exit; }
$name = isset($_POST['name']) ? trim((string)$_POST['name']) : '';
$client = isset($_POST['client']) ? trim((string)$_POST['client']) : '';
$contractor = isset($_POST['contractor']) ? trim((string)$_POST['contractor']) : '';
$location = isset($_POST['location']) ? trim((string)$_POST['location']) : '';
$start_date = isset($_POST['start_date']) ? trim((string)$_POST['start_date']) : '';
$end_date = isset($_POST['end_date']) ? trim((string)$_POST['end_date']) : '';
$status = isset($_POST['status']) ? trim((string)$_POST['status']) : '';
$contract_amount = isset($_POST['contract_amount']) ? trim((string)$_POST['contract_amount']) : '';
$mainManagerId = isset($_POST['main_manager_id']) ? (int)$_POST['main_manager_id'] : 0;
$subManagerIds = isset($_POST['sub_manager_ids']) && is_array($_POST['sub_manager_ids']) ? $_POST['sub_manager_ids'] : array();
$updateToken = isset($_POST['unit_price_update_token']) ? trim((string)$_POST['unit_price_update_token']) : '';
if ($name === '' || $mainManagerId <= 0) { flash_set('error','프로젝트명/공사 담당자는 필수입니다.'); header('Location: ?r=project/detail&id=' . $projectId); exit; }
$contractAmountVal = null; if ($contract_amount !== '') { $clean = preg_replace('/[^0-9]/', '', $contract_amount); if ($clean !== '') $contractAmountVal = (int)$clean; }
$startVal = ($start_date !== '') ? $start_date : null; $endVal = ($end_date !== '') ? $end_date : null;
$pdo = Db::pdo(); if (!$pdo) { flash_set('error','DB 연결 실패'); header('Location: ?r=project/detail&id='.$projectId); exit; }
try {
$pdo->beginTransaction();
$pdo->prepare("UPDATE cpms_projects SET name=:name, client=:client, contractor=:contractor, location=:loc,start_date=:sd, end_date=:ed, contract_amount=:ca, status=:st WHERE id=:id")
->execute(array(':name'=>$name,':client'=>$client,':contractor'=>$contractor,':loc'=>$location,':sd'=>$startVal,':ed'=>$endVal,':ca'=>$contractAmountVal,':st'=>$status,':id'=>$projectId));
$pdo->prepare("DELETE FROM cpms_project_members WHERE project_id = :pid")->execute(array(':pid'=>$projectId));
$stMem=$pdo->prepare("INSERT INTO cpms_project_members(project_id, employee_id, role) VALUES(:pid, :eid, :role)");
$stMem->execute(array(':pid'=>$projectId, ':eid'=>$mainManagerId, ':role'=>'main'));
$seen=array($mainManagerId=>1); foreach ($subManagerIds as $sid){$eid=(int)$sid;if($eid<=0||isset($seen[$eid]))continue;$seen[$eid]=1;$stMem->execute(array(':pid'=>$projectId,':eid'=>$eid,':role'=>'sub'));}
if ($updateToken !== '' && isset($_SESSION['unit_price_update'][$updateToken])) {
 $pack = $_SESSION['unit_price_update'][$updateToken];
 if ((int)$pack['project_id'] !== $projectId) throw new Exception('단가 변경 토큰 프로젝트 불일치');
 $rows = isset($pack['rows']) && is_array($pack['rows']) ? $pack['rows'] : array();
 $oldRows = array(); $st=$pdo->prepare("SELECT * FROM cpms_project_unit_prices WHERE project_id=:pid"); $st->execute(array(':pid'=>$projectId)); foreach($st->fetchAll() as $r){$oldRows[]=$r;}
 $oldMap=array(); foreach($oldRows as $r){$oldMap[trim((string)$r['item_name']).'|'.trim((string)$r['spec']).'|'.trim((string)$r['unit'])]=$r;}
 $seenKeys=array();
 $upSt=$pdo->prepare("UPDATE cpms_project_unit_prices SET item_name=:item_name,spec=:spec,unit=:unit,qty=:qty,unit_price=:unit_price,labor_unit_price=:labor_unit_price,material_unit_price=:material_unit_price,safety_unit_price=:safety_unit_price,is_safety=:is_safety,remark=:remark,is_active=1,updated_at=NOW() WHERE id=:id AND project_id=:pid");
 $inSt=$pdo->prepare("INSERT INTO cpms_project_unit_prices(project_id,item_name,spec,unit,qty,unit_price,labor_unit_price,material_unit_price,safety_unit_price,is_safety,remark,is_active,updated_at) VALUES(:pid,:item_name,:spec,:unit,:qty,:unit_price,:labor_unit_price,:material_unit_price,:safety_unit_price,:is_safety,:remark,1,NOW())");
 foreach($rows as $nr){$k=trim((string)$nr['item_name']).'|'.trim((string)$nr['spec']).'|'.trim((string)$nr['unit']);$seenKeys[$k]=1; if(isset($oldMap[$k])){$or=$oldMap[$k];$upSt->execute(array(':id'=>(int)$or['id'],':pid'=>$projectId,':item_name'=>$nr['item_name'],':spec'=>$nr['spec'],':unit'=>$nr['unit'],':qty'=>$nr['qty'],':unit_price'=>$nr['unit_price'],':labor_unit_price'=>$nr['labor_unit_price'],':material_unit_price'=>$nr['material_unit_price'],':safety_unit_price'=>$nr['safety_unit_price'],':is_safety'=>(int)$nr['is_safety'],':remark'=>$nr['remark']));} else {$inSt->execute(array(':pid'=>$projectId,':item_name'=>$nr['item_name'],':spec'=>$nr['spec'],':unit'=>$nr['unit'],':qty'=>$nr['qty'],':unit_price'=>$nr['unit_price'],':labor_unit_price'=>$nr['labor_unit_price'],':material_unit_price'=>$nr['material_unit_price'],':safety_unit_price'=>$nr['safety_unit_price'],':is_safety'=>(int)$nr['is_safety'],':remark'=>$nr['remark']));}}
 foreach($oldRows as $or){$k=trim((string)$or['item_name']).'|'.trim((string)$or['spec']).'|'.trim((string)$or['unit']); if(!isset($seenKeys[$k])){$pdo->prepare("UPDATE cpms_project_unit_prices SET is_active=0, updated_at=NOW() WHERE id=:id AND project_id=:pid")->execute(array(':id'=>(int)$or['id'],':pid'=>$projectId));}}
 unset($_SESSION['unit_price_update'][$updateToken]);
 }
 $pdo->commit();
 flash_set('success','프로젝트가 수정되었습니다.'); header('Location: ?r=project/detail&id='.$projectId); exit;
} catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); flash_set('error','수정 실패: '.$e->getMessage()); header('Location: ?r=project/detail&id='.$projectId); exit; }