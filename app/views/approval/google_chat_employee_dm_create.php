<?php
require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/google_chat_helpers.php';

if (!Auth::check()) {
    header('Location: ?r=login');
    exit;
}
if (!Auth::canManageEmployees()) {
    http_response_code(403);
    exit('403');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ?r=관리&tab=employees');
    exit;
}
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    flash_set('danger', '보안 토큰이 유효하지 않습니다.');
    header('Location: ?r=관리&tab=employees');
    exit;
}

$employeeId = isset($_POST['employee_id']) ? (int)$_POST['employee_id'] : 0;
if ($employeeId <= 0) {
    flash_set('danger', '직원 정보가 올바르지 않습니다.');
    header('Location: ?r=관리&tab=employees');
    exit;
}

$pdo = Db::pdo();
$employee = approval_google_chat_get_employee_for_dm($pdo, $employeeId);
if (!is_array($employee)) {
    flash_set('danger', '직원 정보를 찾을 수 없습니다.');
    header('Location: ?r=관리&tab=employees');
    exit;
}

$email = isset($employee['email']) ? trim((string)$employee['email']) : '';
if ($email === '') {
    flash_set('danger', '직원 이메일이 없어 Google Chat DM Space를 생성할 수 없습니다.');
    header('Location: ?r=관리&tab=employees');
    exit;
}

$userName = isset($employee['google_chat_user_name']) ? trim((string)$employee['google_chat_user_name']) : '';
if ($userName === '') {
    $userName = 'users/' . $email;
}

$saveOk = approval_google_chat_save_employee_chat_fields($pdo, $employeeId, array(
    'google_chat_enabled' => 1,
    'google_chat_user_name' => $userName,
));
if (!$saveOk) {
    flash_set('danger', 'Google Chat 사용자 정보 저장에 실패했습니다. 잠시 후 다시 시도해주세요.');
    header('Location: ?r=관리&tab=employees');
    exit;
}

$spaceName = approval_google_chat_setup_dm_space($pdo, $userName);
if ($spaceName === false || trim((string)$spaceName) === '') {
    $safeReason = trim((string)approval_google_chat_get_last_error());
    if ($safeReason === '') {
        $safeReason = 'Google Chat DM Space 자동생성에 실패했습니다.';
    }
    flash_set('danger', $safeReason);
    header('Location: ?r=관리&tab=employees');
    exit;
}

$saveSpaceOk = approval_google_chat_save_employee_chat_fields($pdo, $employeeId, array(
    'google_chat_dm_space_name' => (string)$spaceName,
));
if (!$saveSpaceOk) {
    flash_set('danger', 'DM Space 생성은 되었지만 직원 정보 저장에 실패했습니다. 관리자에게 문의해주세요.');
    header('Location: ?r=관리&tab=employees');
    exit;
}

flash_set('success', 'Google Chat DM Space 자동생성이 완료되었습니다.');
header('Location: ?r=관리&tab=employees');
exit;