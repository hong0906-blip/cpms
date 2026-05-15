<?php
use App\Core\Db;
require_once __DIR__.'/_common.php';
require_once __DIR__.'/template_helpers.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') exit;
require_once __DIR__.'/notification_helpers.php';
require_once __DIR__.'/leave_balance_helpers.php';
csrf_validate();
$pdo = Db::pdo(); $u = \App\Core\Auth::user(); if (!$pdo || !$u) exit;
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
$reason = isset($_POST['reject_reason']) ? trim((string)$_POST['reject_reason']) : '';
$uid = approval_current_employee_id($pdo, $u);
$userEmail = approval_current_user_email($u);
$actorName = isset($u['name']) ? (string)$u['name'] : '';
$actorEmail = isset($u['email']) ? (string)$u['email'] : '';
$st = $pdo->prepare("SELECT * FROM cpms_approval_lines WHERE document_id=:d AND line_status='PENDING' AND (approver_id=:u OR LOWER(TRIM(approver_email))=LOWER(TRIM(:email))) LIMIT 1");
$st->execute(array(':d'=>$id,':u'=>$uid,':email'=>$userEmail));
$l = $st->fetch(); if (!$l) { flash_set('danger','처리 권한이 없습니다.'); header('Location: ?r=approval_detail&id='.$id); exit; }
if ($action === 'reject' && $reason === '') { flash_set('danger','반려사유 필수'); header('Location: ?r=approval_detail&id='.$id); exit; }
$pdo->beginTransaction();
if ($action === 'approve') {
    $email = $actorEmail; $sign = approval_sign_path_by_email($email);
    $pdo->prepare("UPDATE cpms_approval_lines SET line_status='APPROVED',acted_at=NOW(),sign_path=:s WHERE id=:id AND line_status='PENDING'")->execute(array(':s'=>$sign,':id'=>$l['id']));
    $nextSt = $pdo->prepare("SELECT id,line_order,approver_id FROM cpms_approval_lines WHERE document_id=:d AND line_status='WAITING' ORDER BY line_order ASC LIMIT 1");
    $nextSt->execute(array(':d'=>$id));
    $nextLine = $nextSt->fetch();
    if($nextLine){
        $nx = $pdo->prepare("UPDATE cpms_approval_lines SET line_status='PENDING' WHERE id=:id");
        $nx->execute(array(':id'=>$nextLine['id']));
        $docStatus = 'PENDING';
        $step = (int)$nextLine['line_order'];
        try { $baseUrl = approval_setting_value($pdo,'google_chat_public_base_url','https://cmbuild.kr/cpms/public/'); $detailUrl = $baseUrl.'?r=approval_detail&id='.$id; approval_queue_notification($pdo,$id,'REQUEST',$nextLine['approver_id'],'[CPMS 전자결재 요청]\n확인하기: '.$detailUrl); } catch (Exception $e) {}
    } else {
        $docStatus = 'APPROVED';
        $step = (int)$l['line_order'];
        $dst=$pdo->prepare("SELECT created_by_id FROM cpms_approval_documents WHERE id=:id"); $dst->execute(array(':id'=>$id)); $creatorId=(int)$dst->fetchColumn(); if($creatorId>0){ try { $baseUrl = approval_setting_value($pdo,'google_chat_public_base_url','https://cmbuild.kr/cpms/public/'); $detailUrl = $baseUrl.'?r=approval_detail&id='.$id; approval_queue_notification($pdo,$id,'FINAL_APPROVED',$creatorId,'[CPMS 전자결재 최종승인]\n확인하기: '.$detailUrl); } catch (Exception $e) {} }
    }
    $pdo->prepare("UPDATE cpms_approval_documents SET doc_status=:s,current_step_order=:o,updated_at=NOW() WHERE id=:id")->execute(array(':s'=>$docStatus,':o'=>$step,':id'=>$id));
    $pdo->prepare("INSERT INTO cpms_approval_logs (document_id,line_id,actor_id,actor_name,actor_email,action_type,created_at) VALUES (:d,:l,:a,:n,:e,'APPROVE',NOW())")->execute(array(':d'=>$id,':l'=>$l['id'],':a'=>$uid,':n'=>$actorName,':e'=>$actorEmail));
    if($docStatus==='APPROVED'){ approval_deduct_leave_balance_on_final_approval($pdo, $id); }    
} elseif ($action === 'reject') {
    $pdo->prepare("UPDATE cpms_approval_lines SET line_status='REJECTED',acted_at=NOW(),reject_reason=:r WHERE id=:id AND line_status='PENDING'")->execute(array(':r'=>$reason,':id'=>$l['id']));
    $pdo->prepare("UPDATE cpms_approval_documents SET doc_status='REJECTED',reject_reason=:r,rejected_step=:s,updated_at=NOW() WHERE id=:id")->execute(array(':r'=>$reason,':s'=>$l['role_type'],':id'=>$id));
    $pdo->prepare("INSERT INTO cpms_approval_logs (document_id,line_id,actor_id,actor_name,actor_email,action_type,action_note,created_at) VALUES (:d,:l,:a,:n,:e,'REJECT',:r,NOW())")->execute(array(':d'=>$id,':l'=>$l['id'],':a'=>$uid,':n'=>$actorName,':e'=>$actorEmail,':r'=>$reason));
    $dst=$pdo->prepare("SELECT created_by_id FROM cpms_approval_documents WHERE id=:id"); $dst->execute(array(':id'=>$id)); $creatorId=(int)$dst->fetchColumn(); if($creatorId>0){ try { approval_queue_notification($pdo,$id,'REJECTED',$creatorId,'[CPMS 전자결재 반려]\n반려사유: '.$reason.'\n확인하기: '.approval_setting_value($pdo,'google_chat_public_base_url','https://cmbuild.kr/cpms/public/').'?r=approval_detail&id='.$id); } catch (Exception $e) {} }
}
$pdo->commit(); header('Location: ?r=approval_detail&id='.$id);