<?php
use App\Core\Db; use App\Core\Auth;
if (!Auth::check() || !Auth::canManageEmployees()) { http_response_code(403); echo '403'; return; }
$pdo = Db::pdo(); require_once __DIR__.'/google_chat_helpers.php';
$keys = array('google_chat_enabled','google_chat_service_account_json_path','google_chat_project_id','google_chat_bot_email','google_chat_oauth_scope','google_chat_public_base_url','google_chat_dm_auto_create_enabled');
$vals = array(); foreach($keys as $k){ $vals[$k]=approval_google_chat_setting($pdo,$k,''); }
?><h2>Google Chat 설정</h2><form method="post" action="?r=approval_google_chat_settings_save"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><?php foreach($keys as $k){ ?><div><label><?php echo h($k); ?></label><input name="<?php echo h($k); ?>" value="<?php echo h($vals[$k]); ?>"></div><?php } ?><button>저장</button></form><?php