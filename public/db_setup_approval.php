<?php
require_once __DIR__ . '/../app/bootstrap.php';
if (!(\App\Core\Auth::isMaster() || \App\Core\Auth::canManageEmployees() || \App\Core\Auth::userRole()==='executive')) { http_response_code(403); exit('403'); }
$pdo = \App\Core\Db::pdo(); $results=array();
if (!function_exists('approval_setup_column_exists')) {
function approval_setup_column_exists($pdo, $table, $column) {
    try {
        $db = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
        if ($db === '') { return false; }
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:tbl AND COLUMN_NAME=:col");
        $st->execute(array(':db'=>$db, ':tbl'=>$table, ':col'=>$column));
        return ((int)$st->fetchColumn() > 0);
    } catch (Exception $e) {
        return false;
    }
}}
if (!function_exists('approval_setup_add_column_if_missing')) {
function approval_setup_add_column_if_missing($pdo, $table, $column, $alterSql, &$results) {
    try {
        if (approval_setup_column_exists($pdo, $table, $column)) {
            $results[] = array('name'=>$table.'.'.$column, 'type'=>'COLUMN', 'ok'=>1, 'msg'=>'이미 존재');
            return;
        }
        $pdo->exec($alterSql);
        $results[] = array('name'=>$table.'.'.$column, 'type'=>'COLUMN', 'ok'=>1, 'msg'=>'추가 완료');
    } catch (Exception $e) {
        $results[] = array('name'=>$table.'.'.$column, 'type'=>'COLUMN', 'ok'=>0, 'msg'=>$e->getMessage());
    }
}}
if (!function_exists('approval_setup_index_exists')) {
function approval_setup_index_exists($pdo, $table, $indexName) {
    try {
        $st = $pdo->query("SHOW INDEX FROM ".$table);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if (isset($row['Key_name']) && (string)$row['Key_name'] === (string)$indexName) {
                return true;
            }
        }
        return false;
    } catch (Exception $e) {
        return false;
    }
}}
$tables=array(
'cpms_approval_documents'=>"CREATE TABLE IF NOT EXISTS cpms_approval_documents (id INT AUTO_INCREMENT PRIMARY KEY, doc_type VARCHAR(20), title VARCHAR(255), content MEDIUMTEXT, doc_status VARCHAR(20), current_step_order INT DEFAULT 1, created_by_id INT, created_by_name VARCHAR(100), reject_reason TEXT NULL, rejected_step VARCHAR(20) NULL, created_at DATETIME, updated_at DATETIME)",
'cpms_approval_lines'=>"CREATE TABLE IF NOT EXISTS cpms_approval_lines (id INT AUTO_INCREMENT PRIMARY KEY, document_id INT, line_order INT, role_type VARCHAR(20), approver_id INT, approver_name VARCHAR(100), approver_email VARCHAR(190), line_status VARCHAR(20), acted_at DATETIME NULL, sign_path VARCHAR(255) NULL, reject_reason TEXT NULL)",
'cpms_approval_files'=>"CREATE TABLE IF NOT EXISTS cpms_approval_files (id INT AUTO_INCREMENT PRIMARY KEY, document_id INT, original_name VARCHAR(255), saved_name VARCHAR(255), file_path VARCHAR(255), created_at DATETIME)",
'cpms_approval_logs'=>"CREATE TABLE IF NOT EXISTS cpms_approval_logs (id INT AUTO_INCREMENT PRIMARY KEY, document_id INT, line_id INT NULL, actor_id INT NULL, actor_name VARCHAR(100), actor_email VARCHAR(190), action_type VARCHAR(30), action_note TEXT NULL, created_at DATETIME)",
'cpms_approval_notifications'=>"CREATE TABLE IF NOT EXISTS cpms_approval_notifications (id INT AUTO_INCREMENT PRIMARY KEY, document_id INT, event_type VARCHAR(40), receiver_employee_id INT, receiver_name VARCHAR(100), receiver_email VARCHAR(190), message_text TEXT, dm_space_name VARCHAR(255) NULL, send_status VARCHAR(20), sent_at DATETIME NULL, error_message TEXT NULL, created_at DATETIME)",
'cpms_approval_settings'=>"CREATE TABLE IF NOT EXISTS cpms_approval_settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(100) UNIQUE, setting_value TEXT NULL, updated_at DATETIME)",
'cpms_holiday_cache'=>"CREATE TABLE IF NOT EXISTS cpms_holiday_cache (id INT AUTO_INCREMENT PRIMARY KEY, holiday_date DATE NOT NULL, holiday_name VARCHAR(190) NULL, source VARCHAR(40) NOT NULL DEFAULT 'GOOGLE_CALENDAR', source_calendar_id VARCHAR(190) NULL, source_event_id VARCHAR(190) NULL, year_no INT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, synced_at DATETIME NULL, created_at DATETIME, updated_at DATETIME, UNIQUE KEY uniq_holiday_date_source (holiday_date, source))",
'cpms_approval_leave_deductions'=>"CREATE TABLE IF NOT EXISTS cpms_approval_leave_deductions (id INT AUTO_INCREMENT PRIMARY KEY, document_id INT NOT NULL, employee_id INT NOT NULL, leave_type VARCHAR(50) NULL, leave_bucket VARCHAR(20) NULL, target_column VARCHAR(50) NULL, deduct_amount DECIMAL(6,2) NOT NULL DEFAULT 0, balance_before DECIMAL(6,2) NULL, balance_after DECIMAL(6,2) NULL, deducted_at DATETIME NULL, created_at DATETIME, note TEXT NULL, UNIQUE KEY uniq_document_id (document_id))"
);
foreach($tables as $name=>$sql){ try{$pdo->exec($sql);$results[]=array('name'=>$name,'type'=>'TABLE','ok'=>1,'msg'=>'확인/생성 완료');}catch(Exception $e){$results[]=array('name'=>$name,'type'=>'TABLE','ok'=>0,'msg'=>$e->getMessage());} }
$requiredColumns = array(
    'cpms_approval_documents' => array(
        'doc_type' => "ALTER TABLE cpms_approval_documents ADD COLUMN doc_type VARCHAR(20) NULL",
        'title' => "ALTER TABLE cpms_approval_documents ADD COLUMN title VARCHAR(255) NULL",
        'content' => "ALTER TABLE cpms_approval_documents ADD COLUMN content MEDIUMTEXT NULL",
        'doc_status' => "ALTER TABLE cpms_approval_documents ADD COLUMN doc_status VARCHAR(20) NULL",
        'current_step_order' => "ALTER TABLE cpms_approval_documents ADD COLUMN current_step_order INT DEFAULT 1",
        'created_by_id' => "ALTER TABLE cpms_approval_documents ADD COLUMN created_by_id INT NULL",
        'created_by_name' => "ALTER TABLE cpms_approval_documents ADD COLUMN created_by_name VARCHAR(100) NULL",
        'reject_reason' => "ALTER TABLE cpms_approval_documents ADD COLUMN reject_reason TEXT NULL",
        'rejected_step' => "ALTER TABLE cpms_approval_documents ADD COLUMN rejected_step VARCHAR(20) NULL",
        'created_at' => "ALTER TABLE cpms_approval_documents ADD COLUMN created_at DATETIME NULL",
        'updated_at' => "ALTER TABLE cpms_approval_documents ADD COLUMN updated_at DATETIME NULL"
    ),
    'cpms_approval_lines' => array(
        'document_id' => "ALTER TABLE cpms_approval_lines ADD COLUMN document_id INT NULL",
        'line_order' => "ALTER TABLE cpms_approval_lines ADD COLUMN line_order INT NULL",
        'role_type' => "ALTER TABLE cpms_approval_lines ADD COLUMN role_type VARCHAR(20) NULL",
        'approver_id' => "ALTER TABLE cpms_approval_lines ADD COLUMN approver_id INT NULL",
        'approver_name' => "ALTER TABLE cpms_approval_lines ADD COLUMN approver_name VARCHAR(100) NULL",
        'approver_email' => "ALTER TABLE cpms_approval_lines ADD COLUMN approver_email VARCHAR(190) NULL",
        'line_status' => "ALTER TABLE cpms_approval_lines ADD COLUMN line_status VARCHAR(20) NULL",
        'acted_at' => "ALTER TABLE cpms_approval_lines ADD COLUMN acted_at DATETIME NULL",
        'sign_path' => "ALTER TABLE cpms_approval_lines ADD COLUMN sign_path VARCHAR(255) NULL",
        'reject_reason' => "ALTER TABLE cpms_approval_lines ADD COLUMN reject_reason TEXT NULL"
    ),
    'cpms_approval_logs' => array(
        'document_id' => "ALTER TABLE cpms_approval_logs ADD COLUMN document_id INT NULL",
        'line_id' => "ALTER TABLE cpms_approval_logs ADD COLUMN line_id INT NULL",
        'actor_id' => "ALTER TABLE cpms_approval_logs ADD COLUMN actor_id INT NULL",
        'actor_name' => "ALTER TABLE cpms_approval_logs ADD COLUMN actor_name VARCHAR(100) NULL",
        'actor_email' => "ALTER TABLE cpms_approval_logs ADD COLUMN actor_email VARCHAR(190) NULL",
        'action_type' => "ALTER TABLE cpms_approval_logs ADD COLUMN action_type VARCHAR(30) NULL",
        'action_note' => "ALTER TABLE cpms_approval_logs ADD COLUMN action_note TEXT NULL",
        'created_at' => "ALTER TABLE cpms_approval_logs ADD COLUMN created_at DATETIME NULL"
    ),
    'cpms_approval_files' => array(
        'document_id' => "ALTER TABLE cpms_approval_files ADD COLUMN document_id INT NULL",
        'original_name' => "ALTER TABLE cpms_approval_files ADD COLUMN original_name VARCHAR(255) NULL",
        'saved_name' => "ALTER TABLE cpms_approval_files ADD COLUMN saved_name VARCHAR(255) NULL",
        'file_path' => "ALTER TABLE cpms_approval_files ADD COLUMN file_path VARCHAR(255) NULL",
        'file_label' => "ALTER TABLE cpms_approval_files ADD COLUMN file_label VARCHAR(100) NULL",
        'file_type' => "ALTER TABLE cpms_approval_files ADD COLUMN file_type VARCHAR(50) NULL",
        'created_at' => "ALTER TABLE cpms_approval_files ADD COLUMN created_at DATETIME NULL"
    ),
    'cpms_approval_notifications' => array(
        'document_id' => "ALTER TABLE cpms_approval_notifications ADD COLUMN document_id INT NULL",
        'event_type' => "ALTER TABLE cpms_approval_notifications ADD COLUMN event_type VARCHAR(40) NULL",
        'receiver_employee_id' => "ALTER TABLE cpms_approval_notifications ADD COLUMN receiver_employee_id INT NULL",
        'receiver_name' => "ALTER TABLE cpms_approval_notifications ADD COLUMN receiver_name VARCHAR(100) NULL",
        'receiver_email' => "ALTER TABLE cpms_approval_notifications ADD COLUMN receiver_email VARCHAR(190) NULL",
        'message_text' => "ALTER TABLE cpms_approval_notifications ADD COLUMN message_text TEXT NULL",
        'dm_space_name' => "ALTER TABLE cpms_approval_notifications ADD COLUMN dm_space_name VARCHAR(255) NULL",
        'send_status' => "ALTER TABLE cpms_approval_notifications ADD COLUMN send_status VARCHAR(20) NULL",
        'sent_at' => "ALTER TABLE cpms_approval_notifications ADD COLUMN sent_at DATETIME NULL",
        'error_message' => "ALTER TABLE cpms_approval_notifications ADD COLUMN error_message TEXT NULL",
        'created_at' => "ALTER TABLE cpms_approval_notifications ADD COLUMN created_at DATETIME NULL"
    ),
    'cpms_approval_settings' => array(
        'setting_key' => "ALTER TABLE cpms_approval_settings ADD COLUMN setting_key VARCHAR(100) NULL",
        'setting_value' => "ALTER TABLE cpms_approval_settings ADD COLUMN setting_value TEXT NULL",
        'updated_at' => "ALTER TABLE cpms_approval_settings ADD COLUMN updated_at DATETIME NULL"
    ),
    'cpms_approval_leave_deductions' => array(
        'document_id' => "ALTER TABLE cpms_approval_leave_deductions ADD COLUMN document_id INT NOT NULL",
        'employee_id' => "ALTER TABLE cpms_approval_leave_deductions ADD COLUMN employee_id INT NOT NULL",
        'leave_type' => "ALTER TABLE cpms_approval_leave_deductions ADD COLUMN leave_type VARCHAR(50) NULL",
        'leave_bucket' => "ALTER TABLE cpms_approval_leave_deductions ADD COLUMN leave_bucket VARCHAR(20) NULL",
        'target_column' => "ALTER TABLE cpms_approval_leave_deductions ADD COLUMN target_column VARCHAR(50) NULL",
        'deduct_amount' => "ALTER TABLE cpms_approval_leave_deductions ADD COLUMN deduct_amount DECIMAL(6,2) NOT NULL DEFAULT 0",
        'balance_before' => "ALTER TABLE cpms_approval_leave_deductions ADD COLUMN balance_before DECIMAL(6,2) NULL",
        'balance_after' => "ALTER TABLE cpms_approval_leave_deductions ADD COLUMN balance_after DECIMAL(6,2) NULL",
        'deducted_at' => "ALTER TABLE cpms_approval_leave_deductions ADD COLUMN deducted_at DATETIME NULL",
        'created_at' => "ALTER TABLE cpms_approval_leave_deductions ADD COLUMN created_at DATETIME NULL",
        'note' => "ALTER TABLE cpms_approval_leave_deductions ADD COLUMN note TEXT NULL"
    )
);
foreach ($requiredColumns as $tableName => $columns) {
    foreach ($columns as $columnName => $alterSql) {
        approval_setup_add_column_if_missing($pdo, $tableName, $columnName, $alterSql, $results);
    }
}
if (!approval_setup_index_exists($pdo, 'cpms_approval_settings', 'uniq_setting_key')) {
    try {
        $pdo->exec("ALTER TABLE cpms_approval_settings ADD UNIQUE KEY uniq_setting_key (setting_key)");
        $results[] = array('name'=>'cpms_approval_settings.uniq_setting_key', 'type'=>'INDEX', 'ok'=>1, 'msg'=>'추가 완료');
    } catch (Exception $e) {
        $results[] = array('name'=>'cpms_approval_settings.uniq_setting_key', 'type'=>'INDEX', 'ok'=>0, 'msg'=>'경고: '.$e->getMessage());
    }
} else {
    $results[] = array('name'=>'cpms_approval_settings.uniq_setting_key', 'type'=>'INDEX', 'ok'=>1, 'msg'=>'이미 존재');
}
if (!approval_setup_index_exists($pdo, 'cpms_approval_leave_deductions', 'uniq_document_id')) {
    try {
        $pdo->exec("ALTER TABLE cpms_approval_leave_deductions ADD UNIQUE KEY uniq_document_id (document_id)");
        $results[] = array('name'=>'cpms_approval_leave_deductions.uniq_document_id', 'type'=>'INDEX', 'ok'=>1, 'msg'=>'추가 완료');
    } catch (Exception $e) {
        $results[] = array('name'=>'cpms_approval_leave_deductions.uniq_document_id', 'type'=>'INDEX', 'ok'=>0, 'msg'=>'경고: '.$e->getMessage());
    }
} else {
    $results[] = array('name'=>'cpms_approval_leave_deductions.uniq_document_id', 'type'=>'INDEX', 'ok'=>1, 'msg'=>'이미 존재');
}
?><!doctype html><html><head><meta charset="utf-8"><title>전자결재 DB 설치/확인</title></head><body><h2>전자결재 DB 설치/확인</h2><p><strong>전자결재 저장 중 500 오류가 발생하면 이 화면에서 DB 설치/확인을 먼저 실행해주세요.</strong></p><p>직원별 Google Chat 컬럼은 관리 &gt; 직원명부에서 생성합니다. 단, Google Chat 컬럼이 없어도 전자결재 저장은 실패하지 않아야 합니다.</p><p>직원별 생년월일, 전자결재 역할, Google Chat DM Space ID는 관리 &gt; 직원명부에서 컬럼 생성 및 설정합니다.</p><p><a href="?r=관리&tab=employees">관리 &gt; 직원명부로 이동</a></p><table border="1" cellpadding="6" cellspacing="0"><tr><th>구분</th><th>대상</th><th>결과</th><th>메시지</th></tr><?php foreach($results as $r){?><tr><td><?php echo h($r['type']);?></td><td><?php echo h($r['name']);?></td><td><?php echo $r['ok']?'성공':'실패';?></td><td><?php echo h($r['msg']);?></td></tr><?php }?></table><p><a href="?r=approval_home">전자결재로 이동</a></p></body></html>
<?php
try{
$defaults=array('google_chat_dm_enabled'=>'1','google_chat_app_credentials_path'=>'','cpms_base_url'=>'','google_holiday_calendar_enabled'=>'0','google_holiday_calendar_id'=>'ko.south_korea#holiday@group.v.calendar.google.com','google_holiday_calendar_api_key'=>'','google_holiday_sync_years'=>'2','google_chat_enabled'=>'1','google_chat_service_account_json_path'=>'/www/cpms/storage/secrets/google-chat-service-account.json','google_chat_project_id'=>'cpms-approval-chat-bot','google_chat_bot_email'=>'cpms-chat-bot@cpms-approval-chat-bot.iam.gserviceaccount.com','google_chat_oauth_scope'=>'https://www.googleapis.com/auth/chat.bot','google_chat_impersonation_user'=>'','google_chat_public_base_url'=>'https://cmbuild.kr/cpms/public/','google_chat_dm_auto_create_enabled'=>'1');
foreach($defaults as $k=>$v){$pdo->prepare("INSERT IGNORE INTO cpms_approval_settings (setting_key,setting_value,updated_at) VALUES (:k,:v,NOW())")->execute(array(':k'=>$k,':v'=>$v));}
$results[] = array('name'=>'cpms_approval_settings.google_chat_impersonation_user','type'=>'SETTING','ok'=>1,'msg'=>'확인/생성 완료');
$stPath = $pdo->prepare("SELECT setting_value FROM cpms_approval_settings WHERE setting_key='google_chat_service_account_json_path' LIMIT 1");
$stPath->execute();
$curPath = $stPath->fetchColumn();
$curPath = ($curPath === false || $curPath === null) ? '' : trim((string)$curPath);
if ($curPath === '' || strpos($curPath, 'C:/www/') === 0 || strpos($curPath, 'c:/www/') === 0) {
    $pdo->prepare("UPDATE cpms_approval_settings SET setting_value=:v, updated_at=NOW() WHERE setting_key='google_chat_service_account_json_path'")
        ->execute(array(':v'=>'/www/cpms/storage/secrets/google-chat-service-account.json'));
}
}catch(Exception $e){}
?>