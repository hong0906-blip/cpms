<?php
use App\Core\Db;
use App\Core\Auth;

if (!Auth::check() || !Auth::canManageEmployees()) {
    http_response_code(403);
    echo '403';
    return;
}

$pdo = Db::pdo();
require_once __DIR__ . '/google_chat_helpers.php';

$keys = array(
    'google_chat_enabled',
    'google_chat_service_account_json_path',
    'google_chat_project_id',
    'google_chat_bot_email',
    'google_chat_oauth_scope',
    'google_chat_public_base_url',
    'google_chat_dm_auto_create_enabled'
);

$vals = array();
foreach ($keys as $k) {
    $vals[$k] = approval_google_chat_setting($pdo, $k, '');
}

$defaultJsonPath = '/www/cpms/storage/secrets/google-chat-service-account.json';
$jsonPath = trim((string)$vals['google_chat_service_account_json_path']);
if ($jsonPath === '') {
    $jsonPath = $defaultJsonPath;
}

$jsonExists = is_file($jsonPath);
$jsonReadable = $jsonExists && is_readable($jsonPath);
$jsonStatusText = '없음';
if ($jsonExists && $jsonReadable) {
    $jsonStatusText = '있음';
} elseif ($jsonExists && !$jsonReadable) {
    $jsonStatusText = '있으나 읽기 불가';
}
?>
<h2>Google Chat 설정</h2>
<form method="post" action="?r=approval_google_chat_settings_save">
  <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
  <?php foreach($keys as $k){ ?>
    <div>
      <label><?php echo h($k); ?></label>
      <input name="<?php echo h($k); ?>" value="<?php echo h($vals[$k]); ?>">
    </div>
  <?php } ?>
  <button>저장</button>
</form>

<hr>
<h3>서비스 계정 JSON 확인</h3>
<div>JSON 경로: <?php echo h($jsonPath); ?></div>
<div>JSON 파일 확인: <?php echo h($jsonStatusText); ?></div>
<p style="color:#666;">보안상 JSON 내용, private_key, access_token은 화면에 표시하지 않습니다.</p>