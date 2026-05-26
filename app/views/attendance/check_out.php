<?php
use App\Core\Db;

require_once __DIR__ . '/common.php';

$dashboardRoute = '?r=' . attendance_text('%EB%8C%80%EC%8B%9C%EB%B3%B4%EB%93%9C');
$statusDone = attendance_text('%ED%87%B4%EA%B7%BC%EC%99%84%EB%A3%8C');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check(isset($_POST['_csrf']) ? $_POST['_csrf'] : '')) {
    flash_set('danger', attendance_text('%EC%9E%98%EB%AA%BB%EB%90%9C%20%EC%9A%94%EC%B2%AD%EC%9E%85%EB%8B%88%EB%8B%A4.'));
    header('Location: ' . $dashboardRoute);
    exit;
}

$pdo = Db::pdo();
$eid = attendance_employee_id($pdo);

if ($eid <= 0 || !$pdo) {
    flash_set('danger', attendance_text('%EC%A7%81%EC%9B%90%20%EC%A0%95%EB%B3%B4%EB%A5%BC%20%EC%B0%BE%EC%9D%84%20%EC%88%98%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
    header('Location: ' . $dashboardRoute);
    exit;
}

$geoValidation = attendance_geofence_validation_result($pdo, $_POST);
if (!$geoValidation['ok']) {
    flash_set('danger', $geoValidation['message']);
    header('Location: ' . $dashboardRoute);
    exit;
}

try {
    $r = attendance_today_record($pdo, $eid);
    if (!$r || !isset($r['check_in']) || !$r['check_in']) throw new Exception(attendance_text('%EC%98%A4%EB%8A%98%20%EC%B6%9C%EA%B7%BC%20%EA%B8%B0%EB%A1%9D%EC%9D%B4%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
    if (!attendance_record_datetime_matches_work_date($r)) throw new Exception(attendance_text('%EC%98%A4%EB%8A%98%20%EB%82%A0%EC%A7%9C%EC%97%90%20%EC%9E%98%EB%AA%BB%EB%90%9C%20%EC%B6%9C%ED%87%B4%EA%B7%BC%20%EA%B8%B0%EB%A1%9D%EC%9D%B4%20%EC%9E%88%EC%8A%B5%EB%8B%88%EB%8B%A4.%20%EA%B4%80%EB%A6%AC%EC%9E%90%EC%97%90%EA%B2%8C%20%EB%82%A0%EC%A7%9C%20%EB%B6%88%EC%9D%BC%EC%B9%98%20%EA%B8%B0%EB%A1%9D%20%EB%B3%B5%EA%B5%AC%EB%A5%BC%20%EC%9A%94%EC%B2%AD%ED%95%B4%EC%A3%BC%EC%84%B8%EC%9A%94.'));
    if (isset($r['check_out']) && $r['check_out']) throw new Exception(attendance_text('%EC%9D%B4%EB%AF%B8%20%ED%87%B4%EA%B7%BC%20%EC%B2%98%EB%A6%AC%EB%90%98%EC%97%88%EC%8A%B5%EB%8B%88%EB%8B%A4.'));

    $now = attendance_now();
    if (strtotime($now) <= strtotime($r['check_in'])) throw new Exception(attendance_text('%ED%87%B4%EA%B7%BC%EC%8B%9C%EA%B0%84%EC%9D%B4%20%EC%B6%9C%EA%B7%BC%EC%8B%9C%EA%B0%84%EB%B3%B4%EB%8B%A4%20%EB%B9%A0%EB%A5%BC%20%EC%88%98%20%EC%97%86%EC%8A%B5%EB%8B%88%EB%8B%A4.'));

    $raw = attendance_minutes($r['check_in'], $now);
    $deduct = attendance_break_minutes($pdo);
    $work = attendance_work_minutes($raw, $deduct);

    $u = $pdo->prepare("UPDATE cpms_attendance_records SET check_out=:co,status=:st,raw_minutes=:raw,work_minutes=:m,updated_at=:u WHERE id=:id");
    $u->execute(array(':co' => $now, ':st' => $statusDone, ':raw' => $raw, ':m' => $work, ':u' => $now, ':id' => $r['id']));

    flash_set('success', attendance_text('%ED%87%B4%EA%B7%BC%20%EC%B2%98%EB%A6%AC%EB%90%98%EC%97%88%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
} catch (Exception $e) {
    flash_set('danger', $e->getMessage());
}

header('Location: ' . $dashboardRoute);
exit;
