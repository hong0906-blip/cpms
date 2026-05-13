<?php
use App\Core\Db; require_once __DIR__.'/_common.php'; require_once __DIR__.'/document_templates.php'; require_once __DIR__.'/template_style.php'; require_once __DIR__.'/template_proposal.php'; require_once __DIR__.'/template_leave.php';
$pdo=Db::pdo(); $id=isset($_GET['id'])?(int)$_GET['id']:0;
$st=$pdo->prepare("SELECT * FROM cpms_approval_documents WHERE id=:id");$st->execute(array(':id'=>$id));$d=$st->fetch();
$st=$pdo->prepare("SELECT * FROM cpms_approval_lines WHERE document_id=:id ORDER BY line_order");$st->execute(array(':id'=>$id));$lines=$st->fetchAll();
$content=approval_parse_content($d['content']);
$filesByType=array(); if($d && $d['doc_type']==='proposal'){ $fs=$pdo->prepare("SELECT * FROM cpms_approval_files WHERE document_id=:id ORDER BY id DESC"); $fs->execute(array(':id'=>$id)); foreach($fs->fetchAll() as $f){ $k=isset($f['file_type'])?$f['file_type']:''; if($k!=='' && !isset($filesByType[$k])){ $filesByType[$k]=$f; } } }

?><!doctype html><html><head><meta charset="utf-8"><title>전자결재 출력</title><?php require __DIR__.'/template_style.php'; ?></head><body><div class="no-print" style="padding:10px"><button onclick="window.print()">인쇄</button></div><?php if($d['doc_type']==='leave'){ render_approval_leave_document($content,$lines,'print'); } else { render_approval_proposal_document($content,$lines,'print',$filesByType); } ?></body></html>