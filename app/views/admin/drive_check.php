<?php
/**
 * Master-only Google Drive connection check.
 * PHP 5.6 compatible.
 */

use App\Core\Auth;

require_once __DIR__ . '/../../services/GoogleDriveHelper.php';

if (!Auth::isMaster()) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">접근 권한이 없습니다. 마스터 관리자만 사용할 수 있습니다.</div>';
    return;
}

if (!function_exists('cpms_admin_drive_check_badge')) {
function cpms_admin_drive_check_badge($ok) {
    if ($ok) {
        return '<span class="inline-flex px-2 py-1 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs font-bold">정상</span>';
    }
    return '<span class="inline-flex px-2 py-1 rounded-lg bg-red-50 border border-red-200 text-red-700 text-xs font-bold">확인필요</span>';
}}

if (!function_exists('cpms_admin_drive_check_row')) {
function cpms_admin_drive_check_row($label, $ok, $message, $httpCode) {
    echo '<tr>';
    echo '<th class="text-left p-3 border-b border-gray-100 bg-gray-50 w-52">' . h($label) . '</th>';
    echo '<td class="p-3 border-b border-gray-100">' . cpms_admin_drive_check_badge($ok) . '</td>';
    echo '<td class="p-3 border-b border-gray-100 text-sm text-gray-700">' . h($message) . '</td>';
    echo '<td class="p-3 border-b border-gray-100 text-sm text-gray-500">' . ((int)$httpCode > 0 ? h((string)(int)$httpCode) : '-') . '</td>';
    echo '</tr>';
}}

$config = cpms_drive_config();
$jsonInfo = cpms_drive_read_service_account();
$checkResult = null;
$checkError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
    if (!csrf_check($token)) {
        $checkError = '보안 토큰이 올바르지 않습니다. 새로고침 후 다시 시도해주세요.';
    } else {
        $checkResult = cpms_drive_run_connection_check(Auth::user());
    }
}

$folders = isset($config['folders']) && is_array($config['folders']) ? $config['folders'] : array();
?>

<div class="space-y-5">
  <div>
    <div class="text-sm text-gray-500">관리 / Google Drive</div>
    <h3 class="text-xl font-extrabold text-gray-900">Drive 연결 점검</h3>
  </div>

  <?php if ($checkError !== ''): ?>
    <div class="p-4 rounded-2xl border border-red-200 bg-red-50 text-red-700 font-bold">
      <?php echo h($checkError); ?>
    </div>
  <?php endif; ?>

  <div class="bg-white border border-gray-200 rounded-2xl p-4">
    <div class="font-extrabold text-gray-900 mb-3">설정</div>
    <table class="w-full border-collapse text-sm">
      <tr>
        <th class="text-left p-3 border-b border-gray-100 bg-gray-50 w-56">서비스 계정 JSON</th>
        <td class="p-3 border-b border-gray-100"><code><?php echo h(cpms_drive_mask_path(isset($config['service_account_json_path']) ? $config['service_account_json_path'] : '')); ?></code></td>
      </tr>
      <tr>
        <th class="text-left p-3 border-b border-gray-100 bg-gray-50">서비스 계정 이메일</th>
        <td class="p-3 border-b border-gray-100"><code><?php echo h(isset($config['service_account_email']) ? $config['service_account_email'] : ''); ?></code></td>
      </tr>
      <tr>
        <th class="text-left p-3 border-b border-gray-100 bg-gray-50">공유드라이브 ID</th>
        <td class="p-3 border-b border-gray-100"><code><?php echo h(cpms_drive_shared_drive_id()); ?></code></td>
      </tr>
      <tr>
        <th class="text-left p-3 border-b border-gray-100 bg-gray-50">공통문서 폴더 ID</th>
        <td class="p-3 border-b border-gray-100"><code><?php echo h(isset($folders['common_documents']) ? $folders['common_documents'] : ''); ?></code></td>
      </tr>
      <tr>
        <th class="text-left p-3 bg-gray-50">실패 로그</th>
        <td class="p-3"><code><?php echo h(cpms_drive_log_path()); ?></code></td>
      </tr>
    </table>
  </div>

  <div class="bg-white border border-gray-200 rounded-2xl p-4">
    <div class="flex items-center justify-between gap-3 mb-3">
      <div>
        <div class="font-extrabold text-gray-900">현재 JSON 확인</div>
        <div class="text-xs text-gray-500 mt-1">private_key 내용은 표시하지 않습니다.</div>
      </div>
      <?php echo cpms_admin_drive_check_badge(!empty($jsonInfo['ok'])); ?>
    </div>
    <div class="text-sm text-gray-700"><?php echo h(isset($jsonInfo['message']) ? $jsonInfo['message'] : ''); ?></div>
    <?php if (!empty($jsonInfo['service_account_email'])): ?>
      <div class="text-xs text-gray-500 mt-2">읽은 계정: <code><?php echo h($jsonInfo['service_account_email']); ?></code></div>
    <?php endif; ?>
  </div>

  <form method="post" action="?r=<?php echo urlencode('관리'); ?>&tab=drive_check" class="bg-white border border-gray-200 rounded-2xl p-4">
    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
    <button type="submit" class="px-4 py-3 rounded-xl bg-gray-900 text-white font-extrabold">연결 점검 실행</button>
  </form>

  <?php if (is_array($checkResult)): ?>
    <div class="bg-white border border-gray-200 rounded-2xl p-4">
      <div class="font-extrabold text-gray-900 mb-3">점검 결과</div>
      <table class="w-full border-collapse">
        <thead>
          <tr>
            <th class="text-left p-3 border-b border-gray-200 bg-gray-50">항목</th>
            <th class="text-left p-3 border-b border-gray-200 bg-gray-50">결과</th>
            <th class="text-left p-3 border-b border-gray-200 bg-gray-50">메시지</th>
            <th class="text-left p-3 border-b border-gray-200 bg-gray-50">HTTP</th>
          </tr>
        </thead>
        <tbody>
          <?php
            cpms_admin_drive_check_row('JSON 파일 읽기', !empty($checkResult['json']['ok']), isset($checkResult['json']['message']) ? $checkResult['json']['message'] : '', 0);
            cpms_admin_drive_check_row('Access Token 발급', !empty($checkResult['token']['ok']), isset($checkResult['token']['message']) ? $checkResult['token']['message'] : '', isset($checkResult['token']['http_code']) ? $checkResult['token']['http_code'] : 0);
            cpms_admin_drive_check_row('03_공통문서 업로드', !empty($checkResult['upload']['ok']), isset($checkResult['upload']['message']) ? $checkResult['upload']['message'] : '', isset($checkResult['upload']['http_code']) ? $checkResult['upload']['http_code'] : 0);
            cpms_admin_drive_check_row('테스트 파일 삭제', !empty($checkResult['delete']['ok']), isset($checkResult['delete']['message']) ? $checkResult['delete']['message'] : '', isset($checkResult['delete']['http_code']) ? $checkResult['delete']['http_code'] : 0);
          ?>
        </tbody>
      </table>
      <?php if (!empty($checkResult['test_file']['id'])): ?>
        <div class="mt-3 text-xs text-gray-500">
          테스트 파일 ID: <code><?php echo h($checkResult['test_file']['id']); ?></code>
          / 파일명: <code><?php echo h(isset($checkResult['test_file']['name']) ? $checkResult['test_file']['name'] : ''); ?></code>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
