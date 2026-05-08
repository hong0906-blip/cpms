<?php
/** 연차/월차/반차 관리 저장 */
use App\Core\Db;
require_once __DIR__.'/../attendance/common.php';

if(!attendance_is_manager()) exit;
if(!csrf_check(isset($_POST['_csrf'])?$_POST['_csrf']:'')) exit;

$pdo = Db::pdo();
$leaveType = isset($_POST['leave_type']) ? trim((string)$_POST['leave_type']) : '';
$inputAmount = isset($_POST['leave_amount']) ? (float)$_POST['leave_amount'] : 0;

// 관리부 반차 차감 기준: leave_type 값 기반으로 0.5 자동 반영
$normalizedAmount = $inputAmount;
if ($leaveType === '월차반차' || $leaveType === '연차반차' || $leaveType === '오전월차반차' || $leaveType === '오후월차반차' || $leaveType === '오전연차반차' || $leaveType === '오후연차반차') {
    $normalizedAmount = 0.5;
} else if ($normalizedAmount <= 0) {
    $normalizedAmount = 1.0;
}

$st=$pdo->prepare("INSERT INTO cpms_leave_records(employee_id,leave_date,leave_type,leave_amount,reason,created_by,created_at,updated_at) VALUES(:e,:d,:t,:a,:r,:c,:ca,:ua)");
$st->execute(array(
    ':e'=>(int)$_POST['employee_id'],
    ':d'=>$_POST['leave_date'],
    ':t'=>$leaveType,
    ':a'=>$normalizedAmount,
    ':r'=>isset($_POST['reason'])?$_POST['reason']:'',
    ':c'=>attendance_employee_id($pdo),
    ':ca'=>attendance_now(),
    ':ua'=>attendance_now()
));

header('Location: ?r=관리&tab=attendance');