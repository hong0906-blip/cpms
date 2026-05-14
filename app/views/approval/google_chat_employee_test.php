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

$spaceName = isset($employee['google_chat_dm_space_name']) ? trim((string)$employee['google_chat_dm_space_name']) : '';
if ($spaceName === '') {
    flash_set('danger', '먼저 DM Space 자동생성을 실행해주세요.');
    header('Location: ?r=관리&tab=employees');
    exit;
}

$message = "CPMS 전자결재 Google Chat 알림 테스트입니다.\n이 메시지가 보이면 개인 DM 알림 설정이 완료된 것입니다.";
if (approval_google_chat_send_message($pdo, $spaceName, $message)) {
    flash_set('success', '테스트 메시지를 전송했습니다.');
} else {
    flash_set('danger', '테스트 메시지 전송에 실패했습니다. Google Chat 권한과 설정을 확인해주세요.');
}

header('Location: ?r=관리&tab=employees');
exit;