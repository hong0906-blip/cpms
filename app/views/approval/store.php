<?php
use App\Core\Db;
require_once __DIR__.'/template_helpers.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { exit; }
csrf_validate();
$pdo = Db::pdo(); $user = \App\Core\Auth::user(); if (!$pdo || !$user) { exit; }
$docType = isset($_POST['doc_type']) ? trim((string)$_POST['doc_type']) : 'proposal';
$vp = $pdo->query("SELECT id,name,email FROM employees WHERE is_active=1 AND position='부사장' LIMIT 1")->fetch();
$ceo = $pdo->query("SELECT id,name,email FROM employees WHERE is_active=1 AND position IN ('대표','대표이사') LIMIT 1")->fetch();
if (!$vp || ($docType!=='leave' && !$ceo)) { flash_set('danger','직원명부에서 부사장 또는 대표이사가 등록되어 있지 않습니다. 관리 메뉴에서 먼저 등록해주세요.'); header('Location: ?r=approval_create&type='.$docType); exit; }
$contentData = array(); $title=''; $lines=array();
if ($docType === 'leave') {
    $leadId=(int)$_POST['team_lead_id'];
    $st=$pdo->prepare("SELECT id,name,email,position FROM employees WHERE id=:id AND is_active=1 AND position IN ('과장','차장','부장') LIMIT 1");
    $st->execute(array(':id'=>$leadId)); $lead=$st->fetch();
    if(!$lead){ flash_set('danger','팀장 결재자를 선택해주세요.'); header('Location: ?r=approval_create&type=leave'); exit; }
    $start=trim((string)$_POST['leave_start_date']); $end=trim((string)$_POST['leave_end_date']);
    if($start===''||$end===''){ flash_set('danger','휴가 시작일/종료일은 필수입니다.'); header('Location: ?r=approval_create&type=leave'); exit; }
    if(strtotime($start)>strtotime($end)){ flash_set('danger','휴가 시작일은 종료일보다 늦을 수 없습니다.'); header('Location: ?r=approval_create&type=leave'); exit; }
    $days=isset($_POST['leave_days'])?trim((string)$_POST['leave_days']):''; if($days===''){ $days=(string)(floor((strtotime($end)-strtotime($start))/86400)+1); }
    $contentData = array('request_type'=>trim((string)$_POST['request_type']),'request_type_etc'=>trim((string)$_POST['request_type_etc']),'department'=>trim((string)$_POST['department']),'position'=>trim((string)$_POST['position']),'applicant_name'=>trim((string)$_POST['applicant_name']),'birth_date'=>trim((string)$_POST['birth_date']),'leave_start_date'=>$start,'leave_end_date'=>$end,'leave_days'=>$days,'leave_period_text'=>trim((string)$_POST['leave_period_text']),'leave_reason'=>trim((string)$_POST['leave_reason']),'request_date'=>trim((string)$_POST['request_date']),'applicant_sign_name'=>trim((string)$_POST['applicant_sign_name']),'emergency_contact'=>trim((string)$_POST['emergency_contact']));
    $title='휴가계 - '.$contentData['applicant_name'];
    $lines=array(array('role'=>'팀장','emp'=>$lead),array('role'=>'부사장','emp'=>$vp));
} else {
    $sojangId=(int)$_POST['sojang_id']; $gongmuId=(int)$_POST['gongmu_id']; $manageId=(int)$_POST['manage_id'];
    $st=$pdo->prepare("SELECT id,name,email,position,department FROM employees WHERE id=:id AND is_active=1 LIMIT 1");
    $st->execute(array(':id'=>$sojangId)); $sojang=$st->fetch();
    $st->execute(array(':id'=>$gongmuId)); $gongmu=$st->fetch(); $st->execute(array(':id'=>$manageId)); $manage=$st->fetch();
    if(!$sojang || !in_array($sojang['position'],array('과장','차장','부장')) || !in_array(approval_norm_dept($sojang['department']),array('공사','공사팀'))){ flash_set('danger','소장 결재자를 선택해주세요.'); header('Location: ?r=approval_create&type=proposal'); exit; }
    if(!$gongmu || !in_array(approval_norm_dept($gongmu['department']),array('공무','공무팀')) || !$manage || !in_array(approval_norm_dept($manage['department']),array('관리','관리팀'))){ flash_set('danger','공무/관리 결재자를 선택해주세요.'); header('Location: ?r=approval_create&type=proposal'); exit; }
    $contentData = array('draft_date'=>trim((string)$_POST['draft_date']),'effective_date'=>trim((string)$_POST['effective_date']),'draft_department'=>trim((string)$_POST['draft_department']),'drafter_name'=>trim((string)$_POST['drafter_name']),'draft_type'=>trim((string)$_POST['draft_type']),'title'=>trim((string)$_POST['title']),'headline'=>trim((string)$_POST['headline']),'intro_text'=>trim((string)$_POST['intro_text']),'reason'=>trim((string)$_POST['reason']),'company_name'=>trim((string)$_POST['company_name']),'contract_amount'=>trim((string)$_POST['contract_amount']),'advance_amount'=>trim((string)$_POST['advance_amount']),'special_note_1'=>trim((string)$_POST['special_note_1']),'special_note_2'=>trim((string)$_POST['special_note_2']),'payment_request_date'=>trim((string)$_POST['payment_request_date']),'budget_status'=>trim((string)$_POST['budget_status']),'attached_doc_1'=>trim((string)$_POST['attached_doc_1']),'attached_doc_2'=>trim((string)$_POST['attached_doc_2']),'attached_doc_note'=>trim((string)$_POST['attached_doc_note']),'writer_name'=>trim((string)$_POST['drafter_name']));
    $title=$contentData['title']!==''?$contentData['title']:'기안서 / 품의서';
    $lines=array(array('role'=>'소장','emp'=>$sojang),array('role'=>'공무','emp'=>$gongmu),array('role'=>'관리','emp'=>$manage),array('role'=>'부사장','emp'=>$vp),array('role'=>'대표이사','emp'=>$ceo));
}
$pdo->beginTransaction();
$pdo->prepare("INSERT INTO cpms_approval_documents (doc_type,title,content,doc_status,current_step_order,created_by_id,created_by_name,created_at,updated_at) VALUES (:t,:ti,:c,'PENDING',1,:uid,:un,NOW(),NOW())")
    ->execute(array(':t'=>$docType,':ti'=>$title,':c'=>json_encode($contentData),':uid'=>(int)$user['id'],':un'=>$user['name']));
$did=(int)$pdo->lastInsertId();
for($i=0;$i<count($lines);$i++){
    $emp=$lines[$i]['emp'];
    $pdo->prepare("INSERT INTO cpms_approval_lines (document_id,line_order,role_type,approver_id,approver_name,approver_email,line_status) VALUES (?,?,?,?,?,?,?)")
    ->execute(array($did,$i+1,$lines[$i]['role'],$emp['id'],$emp['name'],$emp['email'],$i===0?'PENDING':'WAITING'));
}
$pdo->commit();
header('Location: ?r=approval_detail&id='.$did);