<?php
/** Attendance monthly cell save */
use App\Core\Db;

require_once __DIR__ . '/../attendance/common.php';

if (!function_exists('attendance_record_save_return_url')) {
function attendance_record_save_return_url() {
    $url = isset($_POST['return_url']) ? trim((string)$_POST['return_url']) : '';
    if ($url === '' || stripos($url, 'javascript:') === 0 || preg_match('/^https?:\/\//i', $url)) {
        $url = '?r=' . attendance_text('%EA%B4%80%EB%A6%AC') . '&tab=attendance&atab=monthly';
    }
    return $url;
}}

if (!function_exists('attendance_record_save_time_value')) {
function attendance_record_save_time_value($value, &$ok) {
    $value = trim((string)$value);
    if ($value === '') return '';
    if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $value)) {
        $ok = false;
        return '';
    }
    return $value;
}}

$returnUrl = attendance_record_save_return_url();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check(isset($_POST['_csrf']) ? $_POST['_csrf'] : '')) {
    flash_set('danger', attendance_text('%EB%B3%B4%EC%95%88%20%ED%86%A0%ED%81%B0%EC%9D%B4%20%EC%9C%A0%ED%9A%A8%ED%95%98%EC%A7%80%20%EC%95%8A%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
    header('Location: ' . $returnUrl);
    exit;
}

$pdo = Db::pdo();
if (!$pdo || !attendance_can_manage_settings($pdo)) {
    header('Location: ' . $returnUrl);
    exit;
}

$employeeId = isset($_POST['employee_id']) ? (int)$_POST['employee_id'] : 0;
$workDate = isset($_POST['work_date']) ? trim((string)$_POST['work_date']) : '';
$validTime = true;
$checkInTime = attendance_record_save_time_value(isset($_POST['check_in_time']) ? $_POST['check_in_time'] : '', $validTime);
$checkOutTime = attendance_record_save_time_value(isset($_POST['check_out_time']) ? $_POST['check_out_time'] : '', $validTime);

if ($employeeId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate) || !$validTime) {
    flash_set('danger', attendance_text('%EC%A0%80%EC%9E%A5%ED%95%A0%20%EA%B7%BC%ED%83%9C%20%EC%8B%9C%EA%B0%84%EC%9D%B4%20%EC%98%AC%EB%B0%94%EB%A5%B4%EC%A7%80%20%EC%95%8A%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
    header('Location: ' . $returnUrl);
    exit;
}

if ($checkInTime === '') {
    flash_set('danger', attendance_text('%EC%B6%9C%EA%B7%BC%EC%8B%9C%EA%B0%84%EC%9D%84%20%EC%84%A0%ED%83%9D%ED%95%B4%EC%A3%BC%EC%84%B8%EC%9A%94.'));
    header('Location: ' . $returnUrl);
    exit;
}

try {
    $activeSelect = (attendance_table_exists($pdo, 'employees') && attendance_table_column_exists_for_settings($pdo, 'employees', 'is_active')) ? 'is_active' : '1 AS is_active';
    $stEmp = $pdo->prepare("SELECT id," . $activeSelect . " FROM employees WHERE id=:id LIMIT 1");
    $stEmp->execute(array(':id' => $employeeId));
    $emp = $stEmp->fetch(PDO::FETCH_ASSOC);
    if (!$emp || (isset($emp['is_active']) && (string)$emp['is_active'] === '0')) {
        flash_set('danger', attendance_text('%EC%9E%AC%EC%A7%81%20%EC%A7%81%EC%9B%90%EC%9D%98%20%EA%B7%BC%ED%83%9C%EB%A7%8C%20%EC%88%98%EC%A0%95%ED%95%A0%20%EC%88%98%20%EC%9E%88%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
        header('Location: ' . $returnUrl);
        exit;
    }

    $checkIn = $workDate . ' ' . $checkInTime . ':00';
    $checkOut = $checkOutTime !== '' ? $workDate . ' ' . $checkOutTime . ':00' : null;
    if ($checkOut !== null && strtotime($checkOut) <= strtotime($checkIn)) {
        flash_set('danger', attendance_text('%ED%87%B4%EA%B7%BC%EC%8B%9C%EA%B0%84%EC%9D%80%20%EC%B6%9C%EA%B7%BC%EC%8B%9C%EA%B0%84%EB%B3%B4%EB%8B%A4%20%EB%8A%A6%EC%96%B4%EC%95%BC%20%ED%95%A9%EB%8B%88%EB%8B%A4.'));
        header('Location: ' . $returnUrl);
        exit;
    }

    $now = attendance_now();
    $raw = attendance_minutes($checkIn, $checkOut);
    $work = attendance_work_minutes($raw, attendance_break_minutes($pdo));
    $status = $checkOut !== null ? attendance_text('%ED%87%B4%EA%B7%BC%EC%99%84%EB%A3%8C') : attendance_text('%EC%B6%9C%EA%B7%BC%EC%A4%91');

    $st = $pdo->prepare("SELECT id FROM cpms_attendance_records WHERE employee_id=:e AND work_date=:d LIMIT 1");
    $st->execute(array(':e' => $employeeId, ':d' => $workDate));
    $recordId = (int)$st->fetchColumn();
    if ($recordId > 0) {
        $up = $pdo->prepare("UPDATE cpms_attendance_records SET check_in=:ci,check_out=:co,status=:st,raw_minutes=:raw,work_minutes=:work,updated_at=:u WHERE id=:id");
        $up->execute(array(':ci' => $checkIn, ':co' => $checkOut, ':st' => $status, ':raw' => $raw, ':work' => $work, ':u' => $now, ':id' => $recordId));
    } else {
        $ins = $pdo->prepare("INSERT INTO cpms_attendance_records(employee_id,work_date,check_in,check_out,status,raw_minutes,work_minutes,created_at,updated_at) VALUES(:e,:d,:ci,:co,:st,:raw,:work,:c,:u)");
        $ins->execute(array(':e' => $employeeId, ':d' => $workDate, ':ci' => $checkIn, ':co' => $checkOut, ':st' => $status, ':raw' => $raw, ':work' => $work, ':c' => $now, ':u' => $now));
    }

    flash_set('success', attendance_text('%EA%B7%BC%ED%83%9C%20%EC%8B%9C%EA%B0%84%EC%9D%84%20%EC%A0%80%EC%9E%A5%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
} catch (Exception $e) {
    flash_set('danger', attendance_text('%EA%B7%BC%ED%83%9C%20%EC%8B%9C%EA%B0%84%EC%9D%84%20%EC%A0%80%EC%9E%A5%ED%95%98%EC%A7%80%20%EB%AA%BB%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.') . ' ' . $e->getMessage());
}

header('Location: ' . $returnUrl);
exit;
