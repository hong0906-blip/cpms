<?php
/** 출퇴근 요청 반려 */
use App\Core\Db;

require_once __DIR__.'/../attendance/common.php';

if (!attendance_is_manager()) exit;
if (!csrf_check(isset($_POST['_csrf']) ? $_POST['_csrf'] : '')) exit;

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$rejectReason = isset($_POST['reject_reason']) ? trim((string)$_POST['reject_reason']) : '';
if ($rejectReason === '') {
    header('Location: ?r=관리&tab=attendance&atab=requests&msg=reject_reason_required');
    exit;
}

$pdo = Db::pdo();
$st = $pdo->prepare("UPDATE cpms_attendance_requests SET status='rejected',reject_reason=:rr,reviewed_by=:rb,reviewed_at=:ra,updated_at=:u WHERE id=:id");
$st->execute(array(
    ':rr' => $rejectReason,
    ':rb' => attendance_employee_id($pdo),
    ':ra' => attendance_now(),
    ':u' => attendance_now(),
    ':id' => $id
));

header('Location: ?r=관리&tab=attendance&atab=requests');
exit;