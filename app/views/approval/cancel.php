<?php
use App\Core\Db;
if ($_SERVER['REQUEST_METHOD'] !== 'POST') exit;
csrf_validate();
$pdo = Db::pdo(); $u=\App\Core\Auth::user(); if(!$pdo||!$u) exit;
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$st = $pdo->prepare("SELECT id,created_by_id,doc_status FROM cpms_approval_documents WHERE id=:id LIMIT 1");
$st->execute(array(':id'=>$id)); $d=$st->fetch();
if(!$d || (int)$d['created_by_id']!==(int)$u['id']){ flash_set('danger','취소 권한이 없습니다.'); header('Location: ?r=approval_home'); exit; }
if (!in_array($d['doc_status'], array('PENDING','DRAFT'))) { flash_set('danger','현재 상태에서는 취소할 수 없습니다.'); header('Location: ?r=approval_detail&id='.$id); exit; }
$pdo->prepare("UPDATE cpms_approval_documents SET doc_status='CANCELLED',updated_at=NOW() WHERE id=:id")->execute(array(':id'=>$id));
$pdo->prepare("INSERT INTO cpms_approval_logs (document_id,actor_id,actor_name,actor_email,action_type,created_at) VALUES (:d,:a,:n,:e,'CANCEL',NOW())")->execute(array(':d'=>$id,':a'=>$u['id'],':n'=>$u['name'],':e'=>$u['email']));
flash_set('success','요청이 취소되었습니다.'); header('Location: ?r=approval_home');