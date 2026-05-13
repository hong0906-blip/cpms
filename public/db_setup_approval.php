<?php
require_once __DIR__ . '/../app/bootstrap.php';
if (!(\App\Core\Auth::isMaster() || \App\Core\Auth::canManageEmployees() || \App\Core\Auth::userRole()==='executive')) { http_response_code(403); exit('403'); }
$pdo = \App\Core\Db::pdo(); $results=array();
$tables=array(
'cpms_approval_documents'=>"CREATE TABLE IF NOT EXISTS cpms_approval_documents (id INT AUTO_INCREMENT PRIMARY KEY, doc_type VARCHAR(20), title VARCHAR(255), content MEDIUMTEXT, doc_status VARCHAR(20), current_step_order INT DEFAULT 1, created_by_id INT, created_by_name VARCHAR(100), reject_reason TEXT NULL, rejected_step VARCHAR(20) NULL, created_at DATETIME, updated_at DATETIME)",
'cpms_approval_lines'=>"CREATE TABLE IF NOT EXISTS cpms_approval_lines (id INT AUTO_INCREMENT PRIMARY KEY, document_id INT, line_order INT, role_type VARCHAR(20), approver_id INT, approver_name VARCHAR(100), approver_email VARCHAR(190), line_status VARCHAR(20), acted_at DATETIME NULL, sign_path VARCHAR(255) NULL, reject_reason TEXT NULL)",
'cpms_approval_files'=>"CREATE TABLE IF NOT EXISTS cpms_approval_files (id INT AUTO_INCREMENT PRIMARY KEY, document_id INT, original_name VARCHAR(255), saved_name VARCHAR(255), file_path VARCHAR(255), created_at DATETIME)",
'cpms_approval_logs'=>"CREATE TABLE IF NOT EXISTS cpms_approval_logs (id INT AUTO_INCREMENT PRIMARY KEY, document_id INT, line_id INT NULL, actor_id INT NULL, actor_name VARCHAR(100), actor_email VARCHAR(190), action_type VARCHAR(30), action_note TEXT NULL, created_at DATETIME)",
'cpms_approval_notifications'=>"CREATE TABLE IF NOT EXISTS cpms_approval_notifications (id INT AUTO_INCREMENT PRIMARY KEY, document_id INT, event_type VARCHAR(40), receiver_employee_id INT, receiver_name VARCHAR(100), receiver_email VARCHAR(190), message_text TEXT, dm_space_name VARCHAR(255) NULL, send_status VARCHAR(20), sent_at DATETIME NULL, error_message TEXT NULL, created_at DATETIME)",
'cpms_approval_settings'=>"CREATE TABLE IF NOT EXISTS cpms_approval_settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(100) UNIQUE, setting_value TEXT NULL, updated_at DATETIME)"
);
foreach($tables as $name=>$sql){ try{$pdo->exec($sql);$results[]=array('name'=>$name,'type'=>'TABLE','ok'=>1,'msg'=>'확인/생성 완료');}catch(Exception $e){$results[]=array('name'=>$name,'type'=>'TABLE','ok'=>0,'msg'=>$e->getMessage());} }
$colSqls=array(
array('cpms_approval_lines','sign_path',"ALTER TABLE cpms_approval_lines ADD COLUMN sign_path VARCHAR(255) NULL"),
array('cpms_approval_lines','reject_reason',"ALTER TABLE cpms_approval_lines ADD COLUMN reject_reason TEXT NULL"),
array('cpms_approval_documents','reject_reason',"ALTER TABLE cpms_approval_documents ADD COLUMN reject_reason TEXT NULL"),
array('cpms_approval_documents','rejected_step',"ALTER TABLE cpms_approval_documents ADD COLUMN rejected_step VARCHAR(20) NULL"),
array('cpms_approval_files','file_label',"ALTER TABLE cpms_approval_files ADD COLUMN file_label VARCHAR(100) NULL"),
array('cpms_approval_files','file_type',"ALTER TABLE cpms_approval_files ADD COLUMN file_type VARCHAR(50) NULL")
);
foreach($colSqls as $c){ list($t,$col,$sql)=$c; try{$q=$pdo->query("SHOW COLUMNS FROM {$t} LIKE '".$col."'"); if(!$q->fetch()){ $pdo->exec($sql); $results[]=array('name'=>$t.'.'.$col,'type'=>'COLUMN','ok'=>1,'msg'=>'추가 완료'); } else { $results[]=array('name'=>$t.'.'.$col,'type'=>'COLUMN','ok'=>1,'msg'=>'이미 존재'); }}catch(Exception $e){$results[]=array('name'=>$t.'.'.$col,'type'=>'COLUMN','ok'=>0,'msg'=>$e->getMessage());}}
?><!doctype html><html><head><meta charset="utf-8"><title>전자결재 DB 설치/확인</title></head><body><h2>전자결재 DB 설치/확인</h2><table border="1" cellpadding="6" cellspacing="0"><tr><th>구분</th><th>대상</th><th>결과</th><th>메시지</th></tr><?php foreach($results as $r){?><tr><td><?php echo h($r['type']);?></td><td><?php echo h($r['name']);?></td><td><?php echo $r['ok']?'성공':'실패';?></td><td><?php echo h($r['msg']);?></td></tr><?php }?></table><p><a href="?r=approval_home">전자결재로 이동</a></p></body></html>
<?php
try{
$defaults=array('google_chat_dm_enabled'=>'0','google_chat_app_credentials_path'=>'','cpms_base_url'=>'');
foreach($defaults as $k=>$v){$pdo->prepare("INSERT IGNORE INTO cpms_approval_settings (setting_key,setting_value,updated_at) VALUES (:k,:v,NOW())")->execute(array(':k'=>$k,':v'=>$v));}
}catch(Exception $e){}
?>