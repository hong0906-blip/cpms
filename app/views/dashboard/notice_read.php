<?php
/** 공지사항 읽음 처리 - PHP 5.6 호환 */
require_once __DIR__ . '/notice_board.php';

header('Content-Type: application/json; charset=UTF-8');

if (!\App\Core\Auth::check()) {
    http_response_code(401);
    echo json_encode(array('ok' => false, 'message' => '로그인이 필요합니다.'));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('ok' => false, 'message' => '잘못된 요청입니다.'));
    exit;
}
$token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
if (!csrf_check($token)) {
    http_response_code(403);
    echo json_encode(array('ok' => false, 'message' => 'CSRF'));
    exit;
}

$pdo = \App\Core\Db::pdo();
$noticeId = isset($_POST['notice_id']) ? trim((string)$_POST['notice_id']) : '';
$employeeId = cpms_dashboard_notice_current_employee_id($pdo);
$ok = cpms_dashboard_notice_mark_read($pdo, $noticeId, $employeeId);
if (!$ok) http_response_code(400);
echo json_encode(array('ok' => $ok ? true : false));
exit;
?>
