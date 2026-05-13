<?php
use App\Core\Db;
$pdo=Db::pdo(); $id=isset($_GET['id'])?(int)$_GET['id']:0; $u=\App\Core\Auth::user();
$st=$pdo->prepare("SELECT * FROM cpms_approval_documents WHERE id=:id"); $st->execute(array(':id'=>$id)); $d=$st->fetch();
if(!$d){ exit('문서 없음'); }
$st=$pdo->prepare("SELECT 1 FROM cpms_approval_lines WHERE document_id=:d AND approver_id=:u LIMIT 1"); $st->execute(array(':d'=>$id,':u'=>$u['id']));
if((int)$d['created_by_id']!==(int)$u['id'] && !$st->fetch()){ http_response_code(403); exit('권한없음'); }
if (!class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) { exit('PhpSpreadsheet 미설치'); }
exit('TODO');