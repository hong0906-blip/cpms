<?php
require_once __DIR__ . '/../app/bootstrap.php';
if (!(\App\Core\Auth::isMaster() || \App\Core\Auth::canManageEmployees())) { http_response_code(403); exit('403'); }
$pdo = \App\Core\Db::pdo();
$results = array();
$sqls = array(
'cpms_approval_documents' => "CREATE TABLE IF NOT EXISTS cpms_approval_documents (id INT AUTO_INCREMENT PRIMARY KEY, doc_type VARCHAR(20), title VARCHAR(255), content MEDIUMTEXT, doc_status VARCHAR(20), current_step_order INT DEFAULT 1, created_by_id INT, created_by_name VARCHAR(100), reject_reason TEXT NULL, rejected_step VARCHAR(20) NULL, created_at DATETIME, updated_at DATETIME)",
'cpms_approval_lines' => "CREATE TABLE IF NOT EXISTS cpms_approval_lines (id INT AUTO_INCREMENT PRIMARY KEY, document_id INT, line_order INT, role_type VARCHAR(20), approver_id INT, approver_name VARCHAR(100), approver_email VARCHAR(190), line_status VARCHAR(20), acted_at DATETIME NULL, sign_path VARCHAR(255) NULL, reject_reason TEXT NULL)",
'cpms_approval_files' => "CREATE TABLE IF NOT EXISTS cpms_approval_files (id INT AUTO_INCREMENT PRIMARY KEY, document_id INT, original_name VARCHAR(255), saved_name VARCHAR(255), file_path VARCHAR(255), created_at DATETIME)",
'cpms_approval_logs' => "CREATE TABLE IF NOT EXISTS cpms_approval_logs (id INT AUTO_INCREMENT PRIMARY KEY, document_id INT, line_id INT NULL, actor_id INT NULL, actor_name VARCHAR(100), actor_email VARCHAR(190), action_type VARCHAR(30), action_note TEXT NULL, created_at DATETIME)"
);
foreach($sqls as $name=>$sql){try{$pdo->exec($sql);$results[] = array('name'=>$name,'ok'=>true,'msg'=>'확인/생성 완료');}catch(Exception $e){$results[] = array('name'=>$name,'ok'=>false,'msg'=>$e->getMessage());}}
?><!doctype html><html><head><meta charset="utf-8"><title>전자결재 DB 설치/확인</title></head><body><h2>전자결재 DB 설치/확인</h2><ul><?php foreach($results as $r){?><li><?php echo h($r['name']);?> - <?php echo $r['ok']?'성공':'실패';?> (<?php echo h($r['msg']);?>)</li><?php }?></ul><a href="?r=approval_home">전자결재로 이동</a></body></html>