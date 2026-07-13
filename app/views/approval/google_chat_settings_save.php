<?php
require_once __DIR__ . '/../../bootstrap.php';
use App\Core\Auth; use App\Core\Db;
if (!Auth::check() || !Auth::canManageEmployees()) { http_response_code(403); exit('403'); }
if ($_SERVER['REQUEST_METHOD']!=='POST') { header('Location: ?r=approval_google_chat_settings'); exit; }
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) { flash_set('error','보안 토큰 오류'); header('Location: ?r=approval_google_chat_settings'); exit; }
$pdo = Db::pdo();
$keys = array('google_chat_enabled','google_chat_service_account_json_path','google_chat_project_id','google_chat_bot_email','google_chat_oauth_scope','google_chat_impersonation_user','google_chat_public_base_url','google_chat_dm_auto_create_enabled','google_chat_company_space_name');
foreach($keys as $k){ $v=isset($_POST[$k])?trim((string)$_POST[$k]):''; $pdo->prepare("INSERT INTO cpms_approval_settings (setting_key,setting_value,updated_at) VALUES (:k,:v,NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=NOW()")->execute(array(':k'=>$k,':v'=>$v)); }
flash_set('success','Google Chat 설정이 저장되었습니다.');
header('Location: ?r=approval_google_chat_settings'); exit;
