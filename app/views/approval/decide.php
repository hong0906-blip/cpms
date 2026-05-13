<?php
use App\Core\Db;
require_once __DIR__.'/template_helpers.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') exit;
csrf_validate();
$pdo = Db::pdo(); $u = \App\Core\Auth::user(); if (!$pdo || !$u) exit;
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
$reason = isset($_POST['reject_reason']) ? trim((string)$_POST['reject_reason']) : '';
$st = $pdo->prepare("SELECT * FROM cpms_approval_lines WHERE document_id=:d AND approver_id=:u AND line_status='PENDING' LIMIT 1");
$st->execute(array(':d'=>$id,':u'=>(int)$u['id']));
$l = $st->fetch(); if (!$l) { flash_set('danger','처리 권한이 없습니다.'); header('Location: ?r=approval_detail&id='.$id); exit; }
if ($action === 'reject' && $reason === '') { flash_set('danger','반려사유 필수'); header('Location: ?r=approval_detail&id='.$id); exit; }
$pdo->beginTransaction();
if ($action === 'approve') {
    $email = (string)$u['email']; $parts = explode('@',$email); $sign = approval_sign_path_by_email($email); if($sign===''){ $sign = 'storage/signatures/'.$parts[0].'.png'; }
    $pdo->prepare("UPDATE cpms_approval_lines SET line_status='APPROVED',acted_at=NOW(),sign_path=:s WHERE id=:id AND line_status='PENDING'")->execute(array(':s'=>$sign,':id'=>$l['id']));
    $nextSt = $pdo->prepare("SELECT id,line_order FROM cpms_approval_lines WHERE document_id=:d AND line_status='WAITING' ORDER BY line_order ASC LIMIT 1");
    $nextSt->execute(array(':d'=>$id));
    $nextLine = $nextSt->fetch();
    if($nextLine){
        $nx = $pdo->prepare("UPDATE cpms_approval_lines SET line_status='PENDING' WHERE id=:id");
        $nx->execute(array(':id'=>$nextLine['id']));
        $docStatus = 'PENDING';
        $step = (int)$nextLine['line_order'];
    } else {
        $docStatus = 'APPROVED';
        $step = (int)$l['line_order'];
    }
    $pdo->prepare("UPDATE cpms_approval_documents SET doc_status=:s,current_step_order=:o,updated_at=NOW() WHERE id=:id")->execute(array(':s'=>$docStatus,':o'=>$step,':id'=>$id));
    $pdo->prepare("INSERT INTO cpms_approval_logs (document_id,line_id,actor_id,actor_name,actor_email,action_type,created_at) VALUES (:d,:l,:a,:n,:e,'APPROVE',NOW())")->execute(array(':d'=>$id,':l'=>$l['id'],':a'=>$u['id'],':n'=>$u['name'],':e'=>$u['email']));
} elseif ($action === 'reject') {
    $pdo->prepare("UPDATE cpms_approval_lines SET line_status='REJECTED',acted_at=NOW(),reject_reason=:r WHERE id=:id AND line_status='PENDING'")->execute(array(':r'=>$reason,':id'=>$l['id']));
    $pdo->prepare("UPDATE cpms_approval_documents SET doc_status='REJECTED',reject_reason=:r,rejected_step=:s,updated_at=NOW() WHERE id=:id")->execute(array(':r'=>$reason,':s'=>$l['role_type'],':id'=>$id));
    $pdo->prepare("INSERT INTO cpms_approval_logs (document_id,line_id,actor_id,actor_name,actor_email,action_type,action_note,created_at) VALUES (:d,:l,:a,:n,:e,'REJECT',:r,NOW())")->execute(array(':d'=>$id,':l'=>$l['id'],':a'=>$u['id'],':n'=>$u['name'],':e'=>$u['email'],':r'=>$reason));
}
$pdo->commit(); header('Location: ?r=approval_detail&id='.$id);