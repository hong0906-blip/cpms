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
    'google_chat_impersonation_user',    
    'google_chat_public_base_url',
    'google_chat_dm_auto_create_enabled',
    'google_chat_dm_enabled',
    'google_chat_company_space_name'
);

$vals = array();
foreach ($keys as $k) {
    $vals[$k] = approval_google_chat_setting($pdo, $k, '');
}
$chatFlash = function_exists('flash_get') ? flash_get() : null;

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
$scopeValue = trim((string)$vals['google_chat_oauth_scope']);
$impersonationValue = trim((string)$vals['google_chat_impersonation_user']);
$scopeHasComma = (strpos($scopeValue, ',') !== false);
$impersonationHasUsersPrefix = ($impersonationValue !== '' && strpos($impersonationValue, 'users/') === 0);
?>
<h2>Google Chat 설정</h2>
<?php if (is_array($chatFlash) && isset($chatFlash['message']) && trim((string)$chatFlash['message']) !== '') { ?>
  <div style="margin:0 0 12px 0;padding:10px;border:1px solid <?php echo (isset($chatFlash['type']) && (string)$chatFlash['type'] === 'success') ? '#86efac' : '#fca5a5'; ?>;white-space:pre-wrap;color:<?php echo (isset($chatFlash['type']) && (string)$chatFlash['type'] === 'success') ? '#166534' : '#991b1b'; ?>;">
    <?php echo h($chatFlash['message']); ?>
  </div>
<?php } ?>
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

<div style="margin-top:16px;padding:12px;border:1px solid #ddd;">
  <h3 style="margin-top:0;">회사 전체 Google Chat 방 테스트</h3>
  <div style="margin-bottom:8px;">Space Name 예시: <code>spaces/AAQAUsipV8I</code></div>
  <form method="post" action="?r=approval_google_chat_company_test">
    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
    <button type="submit">전체방 테스트 메시지 보내기</button>
  </form>
</div>


<hr>
<h3>전자결재 알림 필수 조건 안내</h3>
<p><strong>google_chat_enabled = 1</strong> 이 되어야 실제 전자결재 알림이 전송됩니다.</p>
<p>직원명부에서 해당 직원의 <strong>Google Chat 개인 DM 사용(google_chat_enabled)</strong>이 체크되어 있어야 합니다.</p>
<p>직원별 <strong>Google Chat DM Space ID(google_chat_dm_space_name)</strong>가 있어야 합니다.</p>
<p>호환키 안내: <strong>legacy google_chat_dm_enabled</strong> 값이 0 또는 비어 있어도 <strong>google_chat_enabled=1</strong>이면 전송됩니다.</p>

<hr>
<h3>입력 형식 안내</h3>
<p>Google Chat User Name은 직원별 값이며 <strong>users/직원이메일</strong> 형식입니다. 예: users/hong0906@cmbuild.kr</p>
<p>google_chat_impersonation_user는 관리자 또는 실행 계정 이메일만 입력합니다. <strong>users/를 붙이면 안 됩니다.</strong> 예: admin@cmbuild.kr</p>

<hr>
<h3>권한 설정 안내</h3>
<p>403 권한 오류가 발생하면 <strong>google_chat_impersonation_user</strong>를 반드시 설정해야 합니다. 회사 Google Workspace 계정 이메일을 입력하세요. 예: admin@cmbuild.kr</p>
<p>Google Chat API를 회사 사용자 권한으로 실행할 계정입니다. Google Workspace 도메인 전체 위임 승인을 받은 서비스 계정이 이 사용자를 대신해 Chat API를 호출합니다.</p>
<div><strong>관리자 콘솔 OAuth 범위(쉼표 구분):</strong><br>
https://www.googleapis.com/auth/chat.bot,<br>
https://www.googleapis.com/auth/chat.spaces,<br>
https://www.googleapis.com/auth/chat.spaces.create,<br>
https://www.googleapis.com/auth/chat.messages,<br>
https://www.googleapis.com/auth/chat.messages.create,<br>
https://www.googleapis.com/auth/chat.memberships
</div>
<div style="margin-top:8px;"><strong>CPMS 설정 google_chat_oauth_scope(공백 구분):</strong><br>
https://www.googleapis.com/auth/chat.bot https://www.googleapis.com/auth/chat.spaces https://www.googleapis.com/auth/chat.spaces.create https://www.googleapis.com/auth/chat.messages https://www.googleapis.com/auth/chat.messages.create https://www.googleapis.com/auth/chat.memberships
</div>
<?php if ($impersonationHasUsersPrefix) { ?>
<div style="margin-top:8px;color:#c00;">google_chat_impersonation_user에는 users/를 붙이지 말고 회사 Google Workspace 이메일만 입력해주세요.</div>
<?php } ?>
<?php if ($scopeHasComma) { ?>
<div style="margin-top:8px;color:#c00;">CPMS의 google_chat_oauth_scope는 쉼표가 아니라 공백으로 구분해야 합니다.<br>예: https://www.googleapis.com/auth/chat.bot https://www.googleapis.com/auth/chat.spaces</div>
<?php } ?>

<hr>
<h3>서비스 계정 JSON 확인</h3>
<div>현재 PHP 기준 프로젝트 루트: <?php echo h($projectRootByPhp); ?></div>
<div>JSON 경로: <?php echo h($jsonPath); ?></div>
<?php if ($resolvedJsonPath !== '' && $resolvedJsonPath !== $jsonPath) { ?>
<div>실제 확인된 경로: <?php echo h($resolvedJsonPath); ?></div>
<?php } ?>
<div>JSON 파일 확인: <?php echo h($jsonStatusText); ?></div>
<div>JSON 읽기 확인: <?php echo h($jsonReadStatusText); ?></div>
<div>google_chat_oauth_scope 값: <?php echo h($scopeValue); ?></div>
<div>google_chat_impersonation_user 값: <?php echo h($impersonationValue); ?></div>

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

<?php
$notiRows = array();
try {
    $st = $pdo->query("SELECT created_at, document_id, event_type, receiver_name, receiver_email, dm_space_name, send_status, error_message FROM cpms_approval_notifications ORDER BY id DESC LIMIT 20");
    if ($st) {
        $notiRows = $st->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $notiRows = array();
}
?>

<hr>
<h3>최근 전자결재 알림 이력 (최근 20건)</h3>
<table border="1" cellpadding="6" cellspacing="0">
  <tr>
    <th>created_at</th><th>document_id</th><th>event_type</th><th>receiver_name</th><th>receiver_email</th><th>dm_space_name</th><th>send_status</th><th>error_message</th>
  </tr>
  <?php if (count($notiRows) === 0) { ?>
  <tr><td colspan="8">표시할 알림 이력이 없습니다.</td></tr>
  <?php } else { foreach ($notiRows as $row) { ?>
  <tr>
    <td><?php echo h(isset($row['created_at']) ? $row['created_at'] : ''); ?></td>
    <td><?php echo h(isset($row['document_id']) ? $row['document_id'] : ''); ?></td>
    <td><?php echo h(isset($row['event_type']) ? $row['event_type'] : ''); ?></td>
    <td><?php echo h(isset($row['receiver_name']) ? $row['receiver_name'] : ''); ?></td>
    <td><?php echo h(isset($row['receiver_email']) ? $row['receiver_email'] : ''); ?></td>
    <td><?php echo h(isset($row['dm_space_name']) ? $row['dm_space_name'] : ''); ?></td>
    <td><?php echo h(isset($row['send_status']) ? $row['send_status'] : ''); ?></td>
    <td><?php echo h(isset($row['error_message']) ? $row['error_message'] : ''); ?></td>
  </tr>
  <?php }} ?>
</table>

<?php
$companyNotiRows = array();
try {
    $st = $pdo->query("SELECT created_at, source_type, source_id, event_type, receiver_name, dm_space_name, send_status, error_message FROM cpms_google_chat_notifications ORDER BY id DESC LIMIT 30");
    if ($st) $companyNotiRows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $companyNotiRows = array();
}
?>
<hr>
<h3>최근 Google Chat 공통 알림 이력 (최근 30건)</h3>
<table border="1" cellpadding="6" cellspacing="0">
  <tr>
    <th>created_at</th><th>source_type</th><th>source_id</th><th>event_type</th><th>receiver_name</th><th>space_name</th><th>send_status</th><th>error_message</th>
  </tr>
  <?php if (count($companyNotiRows) === 0) { ?>
  <tr><td colspan="8">표시할 공통 알림 이력이 없습니다.</td></tr>
  <?php } else { foreach ($companyNotiRows as $row) { ?>
  <tr>
    <td><?php echo h(isset($row['created_at']) ? $row['created_at'] : ''); ?></td>
    <td><?php echo h(isset($row['source_type']) ? $row['source_type'] : ''); ?></td>
    <td><?php echo h(isset($row['source_id']) ? $row['source_id'] : ''); ?></td>
    <td><?php echo h(isset($row['event_type']) ? $row['event_type'] : ''); ?></td>
    <td><?php echo h(isset($row['receiver_name']) ? $row['receiver_name'] : ''); ?></td>
    <td><?php echo h(isset($row['dm_space_name']) ? $row['dm_space_name'] : ''); ?></td>
    <td><?php echo h(isset($row['send_status']) ? $row['send_status'] : ''); ?></td>
    <td style="white-space:pre-wrap;"><?php echo h(isset($row['error_message']) ? $row['error_message'] : ''); ?></td>
  </tr>
  <?php }} ?>
</table>
