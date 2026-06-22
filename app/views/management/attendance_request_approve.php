<?php
/** 출퇴근 요청 승인 */
use App\Core\Db;

require_once __DIR__ . '/../attendance/common.php';

if (!attendance_is_manager()) exit;
if (!csrf_check(isset($_POST['_csrf']) ? $_POST['_csrf'] : '')) exit;

if (!function_exists('attendance_request_return_url')) {
function attendance_request_return_url() {
    $url = isset($_POST['return_url']) ? trim((string)$_POST['return_url']) : '';
    if ($url === '' || strpos($url, 'javascript:') === 0) {
        $url = '?r=관리&tab=attendance&atab=requests';
    }
    return $url;
}}

$pdo = Db::pdo();
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$now = attendance_now();
$returnUrl = attendance_request_return_url();

$st = $pdo->prepare("SELECT * FROM cpms_attendance_requests WHERE id=:id");
$st->execute(array(':id' => $id));
$r = $st->fetch();

if ($r) {
    $d = (string)$r['request_date'];
    $ciReq = isset($r['requested_check_in']) ? (string)$r['requested_check_in'] : '';
    $coReq = isset($r['requested_check_out']) ? (string)$r['requested_check_out'] : '';
    $ciDate = attendance_datetime_date_part($ciReq);
    $coDate = attendance_datetime_date_part($coReq);
    $mismatch = false;

    if (($r['request_type'] === 'check_in' || $r['request_type'] === 'both') && $ciDate !== '' && $ciDate !== $d) $mismatch = true;
    if (($r['request_type'] === 'check_out' || $r['request_type'] === 'both') && $coDate !== '' && $coDate !== $d) $mismatch = true;

    if ($mismatch) {
        flash_set('danger', '요청 날짜와 출퇴근 시간 날짜가 서로 달라 승인할 수 없습니다.');
        header('Location: ' . $returnUrl);
        exit;
    }

    error_log('[attendance_request_approve] request_id=' . $id . ' employee_id=' . $r['employee_id'] . ' request_date=' . $r['request_date']);

    $s = $pdo->prepare("SELECT * FROM cpms_attendance_records WHERE employee_id=:e AND work_date=:d LIMIT 1");
    $s->execute(array(':e' => $r['employee_id'], ':d' => $r['request_date']));
    $a = $s->fetch();

    $ci = $a ? $a['check_in'] : null;
    $co = $a ? $a['check_out'] : null;
    if ($r['request_type'] === 'check_in') {
        $ci = $r['requested_check_in'];
        $co = null;
    } else if ($r['request_type'] === 'check_out') {
        $co = $r['requested_check_out'];
    } else if ($r['request_type'] === 'both') {
        $ci = $r['requested_check_in'];
        $co = $r['requested_check_out'];
    }

    $raw = attendance_minutes($ci, $co);
    $deduct = attendance_break_minutes($pdo);
    $m = attendance_work_minutes($raw, $deduct);
    $status = $co ? '퇴근완료' : '출근중';

    if ($a) {
        $u = $pdo->prepare("UPDATE cpms_attendance_records SET check_in=:ci,check_out=:co,status=:st,raw_minutes=:raw,work_minutes=:m,updated_at=:u WHERE id=:id");
        $u->execute(array(':ci' => $ci, ':co' => $co, ':st' => $status, ':raw' => $raw, ':m' => $m, ':u' => $now, ':id' => $a['id']));
    } else {
        $i = $pdo->prepare("INSERT INTO cpms_attendance_records(employee_id,work_date,check_in,check_out,status,raw_minutes,work_minutes,created_at,updated_at) VALUES(:e,:d,:ci,:co,:st,:raw,:m,:c,:u)");
        $i->execute(array(':e' => $r['employee_id'], ':d' => $r['request_date'], ':ci' => $ci, ':co' => $co, ':st' => $status, ':raw' => $raw, ':m' => $m, ':c' => $now, ':u' => $now));
    }

    $rr = $pdo->prepare("UPDATE cpms_attendance_requests SET status='approved',reviewed_by=:rb,reviewed_at=:ra,updated_at=:u WHERE id=:id");
    $rr->execute(array(':rb' => attendance_employee_id($pdo), ':ra' => $now, ':u' => $now, ':id' => $id));
}

header('Location: ' . $returnUrl);
exit;
