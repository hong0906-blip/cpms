<?php
use App\Core\Db;
require_once __DIR__.'/template_helpers.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { exit; }
csrf_validate();
$pdo = Db::pdo(); $user = \App\Core\Auth::user(); if (!$pdo || !$user) { exit; }
$docType = isset($_POST['doc_type']) ? trim((string)$_POST['doc_type']) : 'proposal';
$vp = $pdo->query("SELECT id,name,email FROM employees WHERE is_active=1 AND position='부사장' LIMIT 1")->fetch();
$ceo = $pdo->query("SELECT id,name,email FROM employees WHERE is_active=1 AND position IN ('대표','대표이사') LIMIT 1")->fetch();
if (!$vp || !$ceo) { flash_set('danger','직원명부에서 부사장 또는 대표가 등록되어 있지 않습니다. 관리 메뉴에서 먼저 등록해주세요.'); header('Location: ?r=approval_create&type='.$docType); exit; }
$contentData = array(); $title=''; $lines=array();
if ($docType === 'leave') {
    $leadId=(int)$_POST['team_lead_id']; $pmId=(int)$_POST['pm_id'];
    $st=$pdo->prepare("SELECT id,name,email FROM employees WHERE id=:id AND is_active=1 LIMIT 1");
    $st->execute(array(':id'=>$leadId)); $lead=$st->fetch(); $st->execute(array(':id'=>$pmId)); $pm=$st->fetch();
    if(!$lead || !$pm){ flash_set('danger','팀장/PM 결재자를 선택해주세요.'); header('Location: ?r=approval_create&type=leave'); exit; }
    $contentData = array('request_type'=>trim((string)$_POST['request_type']),'request_type_etc'=>trim((string)$_POST['request_type_etc']),'department'=>trim((string)$_POST['department']),'position'=>trim((string)$_POST['position']),'applicant_name'=>trim((string)$_POST['applicant_name']),'birth_date'=>trim((string)$_POST['birth_date']),'leave_start_date'=>trim((string)$_POST['leave_start_date']),'leave_end_date'=>trim((string)$_POST['leave_end_date']),'leave_period_text'=>trim((string)$_POST['leave_period_text']),'leave_reason'=>trim((string)$_POST['leave_reason']),'request_date'=>trim((string)$_POST['request_date']),'applicant_sign_name'=>trim((string)$_POST['applicant_sign_name']),'emergency_contact'=>trim((string)$_POST['emergency_contact']));
    $title='휴가계 - '.$contentData['applicant_name'];
    $lines=array(array('role'=>'팀장','emp'=>$lead),array('role'=>'PM','emp'=>$pm),array('role'=>'부사장','emp'=>$vp),array('role'=>'대표이사','emp'=>$ceo));
} else {
    $gongmuId=(int)$_POST['gongmu_id']; $manageId=(int)$_POST['manage_id'];
    $st=$pdo->prepare("SELECT id,name,email FROM employees WHERE id=:id AND is_active=1 AND department='공무' LIMIT 1"); $st->execute(array(':id'=>$gongmuId)); $gongmu=$st->fetch();
    $st=$pdo->prepare("SELECT id,name,email FROM employees WHERE id=:id AND is_active=1 AND department='관리' LIMIT 1"); $st->execute(array(':id'=>$manageId)); $manage=$st->fetch();
    if(!$gongmu || !$manage){ flash_set('danger','공무/관리 결재자를 선택해주세요.'); header('Location: ?r=approval_create&type='.$docType); exit; }
    $contentData = array('doc_no'=>trim((string)$_POST['doc_no']),'draft_date'=>trim((string)$_POST['draft_date']),'effective_date'=>trim((string)$_POST['effective_date']),'draft_department'=>trim((string)$_POST['draft_department']),'drafter_name'=>trim((string)$_POST['drafter_name']),'draft_type'=>trim((string)$_POST['draft_type']),'title'=>trim((string)$_POST['title']),'headline'=>trim((string)$_POST['headline']),'intro_text'=>trim((string)$_POST['intro_text']),'reason'=>trim((string)$_POST['reason']),'company_name'=>trim((string)$_POST['company_name']),'contract_amount'=>trim((string)$_POST['contract_amount']),'advance_amount'=>trim((string)$_POST['advance_amount']),'special_note_1'=>trim((string)$_POST['special_note_1']),'special_note_2'=>trim((string)$_POST['special_note_2']),'payment_request_date'=>trim((string)$_POST['payment_request_date']),'budget_status'=>trim((string)$_POST['budget_status']),'attached_doc_1'=>trim((string)$_POST['attached_doc_1']),'attached_doc_2'=>trim((string)$_POST['attached_doc_2']),'attached_doc_note'=>trim((string)$_POST['attached_doc_note']));
    $title=$contentData['title']!==''?$contentData['title']:'기안서';
    $lines=array(array('role'=>'공무','emp'=>$gongmu),array('role'=>'관리','emp'=>$manage),array('role'=>'부사장','emp'=>$vp),array('role'=>'대표이사','emp'=>$ceo));
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