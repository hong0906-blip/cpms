<?php
use App\Core\Db;
require_once __DIR__.'/template_helpers.php';
require_once __DIR__.'/notification_helpers.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { exit; }
csrf_validate();
$pdo = Db::pdo(); $user = \App\Core\Auth::user(); if (!$pdo || !$user) { exit; }
if (!function_exists('approval_store_column_exists')) {
function approval_store_column_exists($pdo, $table, $column) {
    try {
        $db = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
        if ($db === '') return false;
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:tbl AND COLUMN_NAME=:col");
        $st->execute(array(':db'=>$db, ':tbl'=>$table, ':col'=>$column));
        return ((int)$st->fetchColumn() > 0);
    } catch (\Exception $e) { return false; }
}}
$docType = isset($_POST['doc_type']) ? trim((string)$_POST['doc_type']) : 'proposal';
$vp = $pdo->query("SELECT id,name,email FROM employees WHERE is_active=1 AND position='부사장' LIMIT 1")->fetch();
$ceo = $pdo->query("SELECT id,name,email FROM employees WHERE is_active=1 AND position IN ('대표','대표이사') LIMIT 1")->fetch();
if (!$vp || ($docType!=='leave' && !$ceo)) { flash_set('danger','직원명부에서 부사장 또는 대표이사가 등록되어 있지 않습니다. 관리 메뉴에서 먼저 등록해주세요.'); header('Location: ?r=approval_create&type='.$docType); exit; }
$contentData = array(); $title=''; $lines=array();
if ($docType === 'leave') {
    $leadId = isset($_POST['team_lead_id']) ? (int)$_POST['team_lead_id'] : 0;
    if ($leadId <= 0) { flash_set('danger','팀장 결재자를 선택해주세요.'); header('Location: ?r=approval_create&type=leave'); exit; }
    $st=$pdo->prepare("SELECT id,name,email,position FROM employees WHERE id=:id AND is_active=1 AND position IN ('과장','차장','부장') LIMIT 1");
    $st->execute(array(':id'=>$leadId)); $lead=$st->fetch();
    if(!$lead){ flash_set('danger','팀장 결재자를 선택해주세요. 관리 > 직원명부에서 전자결재 역할을 설정해주세요.'); header('Location: ?r=approval_create&type=leave'); exit; }
    $start=isset($_POST['leave_start_date']) ? trim((string)$_POST['leave_start_date']) : '';
    $end=isset($_POST['leave_end_date']) ? trim((string)$_POST['leave_end_date']) : '';
    if($start===''||$end===''){ flash_set('danger','휴가 시작일/종료일은 필수입니다.'); header('Location: ?r=approval_create&type=leave'); exit; }
    if(strtotime($start)>strtotime($end)){ flash_set('danger','휴가 시작일은 종료일보다 늦을 수 없습니다.'); header('Location: ?r=approval_create&type=leave'); exit; }
    $days=(string)(floor((strtotime($end)-strtotime($start))/86400)+1);
    if((int)$days<1){ flash_set('danger','휴가 사용일수 계산값이 올바르지 않습니다.'); header('Location: ?r=approval_create&type=leave'); exit; }
    $requestType=isset($_POST['request_type']) ? trim((string)$_POST['request_type']) : '';
    $allowedRequestTypes=array('연차','월차','결근','반차 오전','반차 오후','경조휴가','공가','기타');
    if(!in_array($requestType,$allowedRequestTypes,true)){ flash_set('danger','신청구분이 올바르지 않습니다.'); header('Location: ?r=approval_create&type=leave'); exit; }
    $leavePeriodText = isset($_POST['leave_period_text']) ? trim((string)$_POST['leave_period_text']) : '';
    $emergencyContact = isset($_POST['emergency_contact']) ? trim((string)$_POST['emergency_contact']) : '';
    $contentData = array('request_type'=>$requestType,'request_type_etc'=>isset($_POST['request_type_etc']) ? trim((string)$_POST['request_type_etc']) : '','department'=>isset($_POST['department']) ? trim((string)$_POST['department']) : '','position'=>isset($_POST['position']) ? trim((string)$_POST['position']) : '','applicant_name'=>isset($_POST['applicant_name']) ? trim((string)$_POST['applicant_name']) : '','birth_date'=>isset($_POST['birth_date']) ? trim((string)$_POST['birth_date']) : '','leave_start_date'=>$start,'leave_end_date'=>$end,'leave_days'=>$days,'leave_period_text'=>$leavePeriodText,'leave_reason'=>isset($_POST['leave_reason']) ? trim((string)$_POST['leave_reason']) : '','request_date'=>date('Y-m-d'),'applicant_sign_name'=>isset($_POST['applicant_sign_name']) ? trim((string)$_POST['applicant_sign_name']) : '','emergency_contact'=>$emergencyContact);
    $contentData['applicant_email']=isset($user['email'])?(string)$user['email']:''; $contentData['writer_email']=$contentData['applicant_email'];
    $title='휴가계 - '.$contentData['applicant_name'];
    $isVpWriter=((int)$user['id']===(int)$vp['id']);
    if($isVpWriter){ $lines=array(array('role'=>'팀장','emp'=>$lead),array('role'=>'대표이사','emp'=>$ceo)); }
    else { $lines=array(array('role'=>'팀장','emp'=>$lead),array('role'=>'부사장','emp'=>$vp)); }
} else {
    $siteRoleCol = approval_store_column_exists($pdo, 'employees', 'approval_can_be_site_manager');
    $gongmuRoleCol = approval_store_column_exists($pdo, 'employees', 'approval_can_be_gongmu_approver');
    $manageRoleCol = approval_store_column_exists($pdo, 'employees', 'approval_can_be_manage_approver');    
    $sojangId=isset($_POST['sojang_id']) ? (int)$_POST['sojang_id'] : 0;
    $gongmuId=isset($_POST['gongmu_id']) ? (int)$_POST['gongmu_id'] : 0;
    $manageId=isset($_POST['manage_id']) ? (int)$_POST['manage_id'] : 0;
    if ($sojangId <= 0) { flash_set('danger','소장 결재자를 선택해주세요.'); header('Location: ?r=approval_create&type=proposal'); exit; }
    if ($gongmuId <= 0 || $manageId <= 0) { flash_set('danger','공무/관리 결재자를 선택해주세요.'); header('Location: ?r=approval_create&type=proposal'); exit; }
    $st=$pdo->prepare("SELECT id,name,email,position,department FROM employees WHERE id=:id AND is_active=1 LIMIT 1");
    $st->execute(array(':id'=>$sojangId)); $sojang=$st->fetch();
    $st->execute(array(':id'=>$gongmuId)); $gongmu=$st->fetch(); $st->execute(array(':id'=>$manageId)); $manage=$st->fetch();
    $sojangRoleOk = false; $gongmuRoleOk = false; $manageRoleOk = false;
    if ($siteRoleCol && $sojang) { $q=$pdo->prepare("SELECT approval_can_be_site_manager FROM employees WHERE id=:id LIMIT 1"); $q->execute(array(':id'=>(int)$sojang['id'])); $sojangRoleOk = ((int)$q->fetchColumn()===1); }
    if ($gongmuRoleCol && $gongmu) { $q=$pdo->prepare("SELECT approval_can_be_gongmu_approver FROM employees WHERE id=:id LIMIT 1"); $q->execute(array(':id'=>(int)$gongmu['id'])); $gongmuRoleOk = ((int)$q->fetchColumn()===1); }
    if ($manageRoleCol && $manage) { $q=$pdo->prepare("SELECT approval_can_be_manage_approver FROM employees WHERE id=:id LIMIT 1"); $q->execute(array(':id'=>(int)$manage['id'])); $manageRoleOk = ((int)$q->fetchColumn()===1); }
    if(!$sojang || (($siteRoleCol && !$sojangRoleOk) || (!$siteRoleCol && (!in_array($sojang['position'],array('과장','차장','부장')) || !in_array(approval_norm_dept($sojang['department']),array('공사','공사팀')))))){ flash_set('danger','소장 결재자를 선택해주세요. 관리 > 직원명부에서 전자결재 역할을 설정해주세요.'); header('Location: ?r=approval_create&type=proposal'); exit; }
    if(!$gongmu || (($gongmuRoleCol && !$gongmuRoleOk) || (!$gongmuRoleCol && !in_array(approval_norm_dept($gongmu['department']),array('공무','공무팀')))) || !$manage || (($manageRoleCol && !$manageRoleOk) || (!$manageRoleCol && !in_array(approval_norm_dept($manage['department']),array('관리','관리팀'))))){ flash_set('danger','공무/관리 결재자를 선택해주세요. 관리 > 직원명부에서 전자결재 역할을 설정해주세요.'); header('Location: ?r=approval_create&type=proposal'); exit; }
    $contentData = array('draft_date'=>isset($_POST['draft_date']) ? trim((string)$_POST['draft_date']) : '','effective_date'=>isset($_POST['effective_date']) ? trim((string)$_POST['effective_date']) : '','draft_department'=>isset($_POST['draft_department']) ? trim((string)$_POST['draft_department']) : '','drafter_name'=>isset($_POST['drafter_name']) ? trim((string)$_POST['drafter_name']) : '','draft_type'=>isset($_POST['draft_type']) ? trim((string)$_POST['draft_type']) : '','title'=>isset($_POST['title']) ? trim((string)$_POST['title']) : '','headline'=>isset($_POST['headline']) ? trim((string)$_POST['headline']) : '','intro_text'=>isset($_POST['intro_text']) ? trim((string)$_POST['intro_text']) : '','reason'=>isset($_POST['reason']) ? trim((string)$_POST['reason']) : '','company_name'=>isset($_POST['company_name']) ? trim((string)$_POST['company_name']) : '','contract_amount'=>isset($_POST['contract_amount']) ? trim((string)$_POST['contract_amount']) : '','advance_amount'=>isset($_POST['advance_amount']) ? trim((string)$_POST['advance_amount']) : '','special_note_1'=>isset($_POST['special_note_1']) ? trim((string)$_POST['special_note_1']) : '','special_note_2'=>isset($_POST['special_note_2']) ? trim((string)$_POST['special_note_2']) : '','payment_request_date'=>isset($_POST['payment_request_date']) ? trim((string)$_POST['payment_request_date']) : '','budget_status'=>isset($_POST['budget_status']) ? trim((string)$_POST['budget_status']) : '','attached_doc_1'=>isset($_POST['attached_doc_1']) ? trim((string)$_POST['attached_doc_1']) : '','attached_doc_2'=>isset($_POST['attached_doc_2']) ? trim((string)$_POST['attached_doc_2']) : '','attached_doc_note'=>isset($_POST['attached_doc_note']) ? trim((string)$_POST['attached_doc_note']) : '','writer_name'=>isset($_POST['drafter_name']) ? trim((string)$_POST['drafter_name']) : '','writer_email'=>isset($user['email'])?(string)$user['email']:'');
    $title=$contentData['title']!==''?$contentData['title']:'기안서';
    $lines=array(array('role'=>'소장','emp'=>$sojang),array('role'=>'공무','emp'=>$gongmu),array('role'=>'관리','emp'=>$manage),array('role'=>'부사장','emp'=>$vp),array('role'=>'대표이사','emp'=>$ceo));
}
$pdo->beginTransaction();
$pdo->prepare("INSERT INTO cpms_approval_documents (doc_type,title,content,doc_status,current_step_order,created_by_id,created_by_name,created_at,updated_at) VALUES (:t,:ti,:c,'PENDING',1,:uid,:un,NOW(),NOW())")
    ->execute(array(':t'=>$docType,':ti'=>$title,':c'=>json_encode($contentData),':uid'=>(int)$user['id'],':un'=>$user['name']));
$did=(int)$pdo->lastInsertId();
$prepared=array();
for($i=0;$i<count($lines);$i++){
    $emp=$lines[$i]['emp'];
    $st=((int)$emp['id']===(int)$user['id'])?'SKIPPED':'WAITING';
    $prepared[]=array('order'=>$i+1,'role'=>$lines[$i]['role'],'emp'=>$emp,'status'=>$st);
}
$first=0; for($i=0;$i<count($prepared);$i++){ if($prepared[$i]['status']!=='SKIPPED'){ $first=$i; break; } }
$allSkipped=true; for($i=0;$i<count($prepared);$i++){ if($prepared[$i]['status']!=='SKIPPED'){ $allSkipped=false; break; } }
if($allSkipped){ $pdo->rollBack(); flash_set('danger','모든 결재자가 작성자 본인으로 설정되어 결재 요청을 만들 수 없습니다. 결재라인을 다시 선택해주세요.'); header('Location: ?r=approval_create&type='.$docType); exit; }
for($i=0;$i<count($prepared);$i++){ if($prepared[$i]['status']!=='SKIPPED'){$prepared[$i]['status']=($i===$first)?'PENDING':'WAITING';} $emp=$prepared[$i]['emp'];    
    $pdo->prepare("INSERT INTO cpms_approval_lines (document_id,line_order,role_type,approver_id,approver_name,approver_email,line_status) VALUES (?,?,?,?,?,?,?)")
    ->execute(array($did,$prepared[$i]['order'],$prepared[$i]['role'],$emp['id'],$emp['name'],$emp['email'],$prepared[$i]['status']));
    if($prepared[$i]['status']==='SKIPPED'){
      $pdo->prepare("INSERT INTO cpms_approval_logs (document_id,line_id,actor_id,actor_name,actor_email,action_type,action_note,created_at) VALUES (:d,NULL,:a,:n,:e,'SKIPPED',:m,NOW())")->execute(array(':d'=>$did,':a'=>$user['id'],':n'=>$user['name'],':e'=>$user['email'],':m'=>'작성자 본인 결재단계로 자동 건너뜀'));
    }
}
for($i=0;$i<count($prepared);$i++){ if($prepared[$i]['status']==='PENDING'){ try { approval_queue_notification($pdo,$did,'REQUEST',$prepared[$i]['emp']['id'],'[전자결재 요청]\n확인: ?r=approval_detail&id='.$did); } catch (Exception $e) {} break; } }

$uploadWarn=array();
if($docType==='proposal'){
    $allow=array('jpg','jpeg','png','gif','webp','pdf');
    $labels=array('order_doc'=>array('발주서','order_doc_file'),'business_license'=>array('사업자 등록증','business_license_file'),'etc'=>array('기타','etc_file'));
    $base=dirname(dirname(dirname(__DIR__))).'/storage/approvals/'.$did.'/files';
    if(!is_dir($base)){ @mkdir($base,0777,true); }
    foreach($labels as $ft=>$meta){
        $fname=$meta[1];
        if(!isset($_FILES[$fname])||!isset($_FILES[$fname]['tmp_name'])||$_FILES[$fname]['tmp_name']===''){ continue; }
        if((int)$_FILES[$fname]['error']!==UPLOAD_ERR_OK){ $uploadWarn[]=$meta[0].' 업로드 실패'; continue; }
        $orig=(string)$_FILES[$fname]['name'];
        $ext=strtolower(pathinfo($orig,PATHINFO_EXTENSION));
        if(!in_array($ext,$allow)){ $uploadWarn[]=$meta[0].' 확장자 제한'; continue; }
        $saved=$ft.'_'.date('YmdHis').'_'.mt_rand(1000,9999).'.'.$ext;
        $dest=$base.'/'.$saved;
        if(!@move_uploaded_file($_FILES[$fname]['tmp_name'],$dest)){ $uploadWarn[]=$meta[0].' 저장 실패'; continue; }
        $rel='storage/approvals/'.$did.'/files/'.$saved;
        $pdo->prepare("INSERT INTO cpms_approval_files (document_id,original_name,saved_name,file_path,file_label,file_type,created_at) VALUES (?,?,?,?,?,?,NOW())")
            ->execute(array($did,$orig,$saved,$rel,$meta[0],$ft));
    }
}
$pdo->commit();
if(count($uploadWarn)>0){ flash_set('danger', implode(', ',$uploadWarn)); }
header('Location: ?r=approval_detail&id='.$did);