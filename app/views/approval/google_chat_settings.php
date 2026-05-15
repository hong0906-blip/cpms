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
$configuredJsonPath = trim((string)$vals['google_chat_service_account_json_path']);
$jsonPath = $configuredJsonPath;
if ($jsonPath === '') {
    $jsonPath = $defaultJsonPath;
}

if ($jsonPath !== '' && substr($jsonPath, -5) !== '.json') {
    $jsonPath = rtrim($jsonPath, '/\\') . '/google-chat-service-account.json';
}

$projectRootByPhp = dirname(dirname(dirname(__DIR__)));
$candidatePaths = array(
    $jsonPath,
    realpath(__DIR__ . '/../../../storage/secrets/google-chat-service-account.json'),
    dirname(dirname(dirname(__DIR__))) . '/storage/secrets/google-chat-service-account.json',
    '/www/cpms/storage/secrets/google-chat-service-account.json'
);

$checkedPaths = array();
$resolvedJsonPath = '';
$jsonExists = false;
$jsonReadable = false;
$jsonRaw = '';
$jsonData = null;

foreach ($candidatePaths as $candidatePath) {
    if (!is_string($candidatePath) || $candidatePath === '') {
        continue;
    }
    if (isset($checkedPaths[$candidatePath])) {
        continue;
    }
    $checkedPaths[$candidatePath] = true;

    if (is_file($candidatePath)) {
        $jsonExists = true;
        $resolvedJsonPath = $candidatePath;

        if (is_readable($candidatePath)) {
            $jsonReadable = true;
            $jsonRaw = @file_get_contents($candidatePath);
            if ($jsonRaw !== false && $jsonRaw !== '') {
                $decoded = json_decode($jsonRaw, true);
                if (is_array($decoded)) {
                    $jsonData = $decoded;
                }
            }
        }
        break;
    }
}

$jsonConfigured = ($configuredJsonPath !== '');
$jsonParsable = is_array($jsonData);
$hasClientEmail = false;
$hasPrivateKey = false;

if ($jsonParsable) {
    $hasClientEmail = isset($jsonData['client_email']) && trim((string)$jsonData['client_email']) !== '';
    $hasPrivateKey = isset($jsonData['private_key']) && trim((string)$jsonData['private_key']) !== '';
}

$jsonStatusText = $jsonExists ? '있음' : '없음';
$jsonReadStatusText = ($jsonExists && $jsonReadable) ? '가능' : '불가';
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
<div>현재 PHP 기준 프로젝트 루트: <?php echo h($projectRootByPhp); ?></div>
<div>JSON 경로: <?php echo h($jsonPath); ?></div>
<?php if ($resolvedJsonPath !== '' && $resolvedJsonPath !== $jsonPath) { ?>
<div>실제 확인된 경로: <?php echo h($resolvedJsonPath); ?></div>
<?php } ?>
<div>JSON 파일 확인: <?php echo h($jsonStatusText); ?></div>
<div>JSON 읽기 확인: <?php echo h($jsonReadStatusText); ?></div>

<ul>
  <li>경로 설정됨: <?php echo $jsonConfigured ? '예' : '아니오'; ?></li>
  <li>파일 존재함: <?php echo $jsonExists ? '예' : '아니오'; ?></li>
  <li>파일 읽기 가능함: <?php echo $jsonReadable ? '예' : '아니오'; ?></li>
  <li>JSON 파싱 가능함: <?php echo $jsonParsable ? '예' : '아니오'; ?></li>
  <li>client_email 있음: <?php echo $hasClientEmail ? '예' : '아니오'; ?></li>
  <li>private_key 있음: <?php echo $hasPrivateKey ? '예' : '아니오'; ?></li>
</ul>

<?php if ($jsonExists && !$jsonReadable) { ?>
<div style="color:#c00;">파일 권한 문제입니다. JSON 파일 권한 644, secrets 폴더 권한 755를 확인해주세요.</div>
<?php } ?>

<p style="color:#666;">보안상 JSON 내용, private_key, access_token은 화면에 표시하지 않습니다.</p>