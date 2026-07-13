<?php
/** Company Google Chat space test action. PHP 5.6 compatible. */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check() || !Auth::canManageEmployees()) {
    http_response_code(403);
    exit('403');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ?r=approval_google_chat_settings');
    exit;
}
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    flash_set('danger', '보안 토큰이 올바르지 않습니다.');
    header('Location: ?r=approval_google_chat_settings');
    exit;
}

$pdo = Db::pdo();
require_once __DIR__ . '/../common/chat_notification_helpers.php';

$message = "[CPMS 테스트 알림]\n회사 전체 Google Chat 방 알림 테스트입니다.";
$ok = cpms_google_chat_send_to_company_space($pdo, $message, 'COMPANY_SPACE_TEST', 0, 'GOOGLE_CHAT_SETTINGS');
if ($ok) {
    flash_set('success', '전체방 테스트 메시지 전송 완료');
} else {
    $errorMessage = function_exists('approval_google_chat_get_last_error') ? trim((string)approval_google_chat_get_last_error()) : '';
    if ($errorMessage === '') $errorMessage = 'Google Chat API 메시지 전송에 실패했습니다.';
    flash_set('danger', '전체방 테스트 메시지 전송 실패: ' . $errorMessage);
}

header('Location: ?r=approval_google_chat_settings');
exit;
?>
