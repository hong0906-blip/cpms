<?php /** 출퇴근 요청 저장 */
use App\Core\Db;

require_once __DIR__ . '/common.php';

if (!function_exists('attendance_request_normalize_datetime_value')) {
function attendance_request_normalize_datetime_value($requestDate, $value) {
    $requestDate = trim((string)$requestDate);
    $value = trim((string)$value);
    if ($value === '') return '';
    $value = str_replace('T', ' ', $value);
    if (preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $value)) {
        return $requestDate . ' ' . $value . ':00';
    }
    if (preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/', $value)) {
        return $requestDate . ' ' . $value;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2} ([01][0-9]|2[0-3]):[0-5][0-9]$/', $value)) {
        return $value . ':00';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2} ([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/', $value)) {
        return $value;
    }
    return $value;
}}

if (!function_exists('attendance_request_time_part')) {
function attendance_request_time_part($value) {
    $value = trim((string)$value);
    if ($value === '') return '';
    if (strlen($value) >= 16 && preg_match('/^\d{4}-\d{2}-\d{2}[ T]/', $value)) return substr($value, 11, 5);
    if (strlen($value) >= 5) return substr($value, 0, 5);
    return '';
}}

if (!function_exists('attendance_request_is_late_check_in')) {
function attendance_request_is_late_check_in($checkIn) {
    $time = attendance_request_time_part($checkIn);
    if ($time === '' || strlen($time) < 5) return false;
    return (strcmp(substr($time, 0, 5), '08:00') > 0);
}}

if (!function_exists('attendance_request_expected_type_for_date')) {
function attendance_request_expected_type_for_date($pdo, $employeeId, $requestDate) {
    if (!$pdo || (int)$employeeId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestDate)) return '';
    try {
        $stRecord = $pdo->prepare("SELECT check_in, check_out FROM cpms_attendance_records WHERE employee_id=:e AND work_date=:d LIMIT 1");
        $stRecord->execute(array(':e' => $employeeId, ':d' => $requestDate));
        $recordRow = $stRecord->fetch(PDO::FETCH_ASSOC);
        if (!$recordRow) return 'both';

        $recordCheckIn = isset($recordRow['check_in']) ? trim((string)$recordRow['check_in']) : '';
        $recordCheckOut = isset($recordRow['check_out']) ? trim((string)$recordRow['check_out']) : '';
        $hasCheckIn = ($recordCheckIn !== '');
        $hasCheckOut = ($recordCheckOut !== '');
        $isLate = attendance_request_is_late_check_in($recordCheckIn);

        if (!$hasCheckIn && !$hasCheckOut) return 'both';
        if (!$hasCheckIn && $hasCheckOut) return 'check_in';
        if ($hasCheckIn && !$hasCheckOut) return $isLate ? 'both' : 'check_out';
        if ($isLate) return 'check_in';
    } catch (Exception $e) {
        return '';
    }
    return '';
}}

if (!function_exists('attendance_request_pending_overlap_types')) {
function attendance_request_pending_overlap_types($requestType) {
    $requestType = trim((string)$requestType);
    if ($requestType === 'both') {
        return array('both', 'check_in', 'check_out');
    }
    if ($requestType === 'check_in') {
        return array('check_in', 'both');
    }
    if ($requestType === 'check_out') {
        return array('check_out', 'both');
    }
    return array($requestType);
}}

$attendanceRequestReturnUrl = isset($_POST['return_url']) ? cpms_safe_internal_redirect_url((string)$_POST['return_url'], '?r=대시보드') : '?r=대시보드';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check(isset($_POST['_csrf']) ? $_POST['_csrf'] : '')) {
    flash_set('danger', '잘못된 요청입니다.');
    header('Location: ' . $attendanceRequestReturnUrl);
    exit;
}

$pdo = Db::pdo();
$eid = attendance_employee_id($pdo);
$d = isset($_POST['request_date']) ? trim((string)$_POST['request_date']) : '';
$t = isset($_POST['request_type']) ? trim((string)$_POST['request_type']) : '';
$ci = isset($_POST['requested_check_in']) ? trim((string)$_POST['requested_check_in']) : '';
$co = isset($_POST['requested_check_out']) ? trim((string)$_POST['requested_check_out']) : '';
$reason = isset($_POST['reason']) ? trim((string)$_POST['reason']) : '';
$now = attendance_now();
$ci = str_replace('T', ' ', $ci);
$co = str_replace('T', ' ', $co);

if (!$pdo || (int)$eid <= 0) {
    flash_set('danger', '직원 정보를 찾을 수 없습니다.');
    header('Location: ' . $attendanceRequestReturnUrl);
    exit;
}

if (!in_array($t, array('check_in', 'check_out', 'both'), true)) {
    flash_set('danger', '요청 종류가 올바르지 않습니다.');
    header('Location: ' . $attendanceRequestReturnUrl);
    exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
    flash_set('danger', '요청 날짜가 올바르지 않습니다.');
    header('Location: ' . $attendanceRequestReturnUrl);
    exit;
}
$ci = attendance_request_normalize_datetime_value($d, $ci);
$co = attendance_request_normalize_datetime_value($d, $co);
$expectedType = attendance_request_expected_type_for_date($pdo, $eid, $d);
if ($expectedType !== '' && $t !== $expectedType) {
    flash_set('danger', '해당 날짜는 ' . attendance_request_type_label($expectedType) . ' 요청만 등록할 수 있습니다.');
    header('Location: ' . $attendanceRequestReturnUrl);
    exit;
}
if ($t === 'check_in') {
    $co = '';
} else if ($t === 'check_out') {
    $ci = '';
}
if (($t === 'check_in' || $t === 'both') && $ci === '') {
    flash_set('danger', '요청 출근시간을 선택해주세요.');
    header('Location: ' . $attendanceRequestReturnUrl);
    exit;
}
if (($t === 'check_out' || $t === 'both') && $co === '') {
    flash_set('danger', '요청 퇴근시간을 선택해주세요.');
    header('Location: ' . $attendanceRequestReturnUrl);
    exit;
}

$ciDate = attendance_datetime_date_part($ci);
$coDate = attendance_datetime_date_part($co);
if (($t === 'check_in' || $t === 'both') && $ciDate !== '' && $ciDate !== $d) {
    flash_set('danger', '요청 날짜와 출근시간의 날짜가 서로 다릅니다.');
    header('Location: ' . $attendanceRequestReturnUrl);
    exit;
}
if (($t === 'check_out' || $t === 'both') && $coDate !== '' && $coDate !== $d) {
    flash_set('danger', '요청 날짜와 퇴근시간의 날짜가 서로 다릅니다.');
    header('Location: ' . $attendanceRequestReturnUrl);
    exit;
}
if ($t === 'both' && $ciDate !== '' && $coDate !== '' && $ciDate !== $coDate) {
    flash_set('danger', '요청 날짜와 출퇴근 시간의 날짜가 서로 다릅니다. 요청 날짜를 ' . $ciDate . '로 선택해주세요.');
    header('Location: ' . $attendanceRequestReturnUrl);
    exit;
}

try {
    $lockName = 'attendance_request_' . (int)$eid . '_' . $d;
    $locked = false;
    try {
        $stLock = $pdo->prepare("SELECT GET_LOCK(:lock_name, 3)");
        $stLock->execute(array(':lock_name' => $lockName));
        $locked = ((int)$stLock->fetchColumn() === 1);
    } catch (Exception $e) {
        $locked = true;
    }
    if (!$locked) {
        flash_set('danger', '요청 처리 중입니다. 잠시 후 다시 확인해주세요.');
        header('Location: ' . $attendanceRequestReturnUrl);
        exit;
    }

    $pendingTypes = attendance_request_pending_overlap_types($t);
    while (count($pendingTypes) < 3) {
        $pendingTypes[count($pendingTypes)] = '__none_' . count($pendingTypes);
    }
    $stExisting = $pdo->prepare("SELECT id FROM cpms_attendance_requests WHERE employee_id=:e AND request_date=:d AND request_type IN (:t0,:t1,:t2) AND status='pending' ORDER BY id DESC LIMIT 1");
    $stExisting->execute(array(':e' => $eid, ':d' => $d, ':t0' => $pendingTypes[0], ':t1' => $pendingTypes[1], ':t2' => $pendingTypes[2]));
    $existingId = (int)$stExisting->fetchColumn();
    if ($existingId > 0) {
        try {
            $stRelease = $pdo->prepare("SELECT RELEASE_LOCK(:lock_name)");
            $stRelease->execute(array(':lock_name' => $lockName));
        } catch (Exception $e) {
        }
        flash_set('success', '이미 승인대기 중인 같은 수정 요청이 있습니다. 기존 요청 1건만 유지됩니다.');
        header('Location: ' . $attendanceRequestReturnUrl);
        exit;
    }

    $st = $pdo->prepare("INSERT INTO cpms_attendance_requests(employee_id,request_date,request_type,requested_check_in,requested_check_out,reason,status,created_at,updated_at) VALUES(:e,:d,:t,:ci,:co,:r,'pending',:c,:u)");
    $st->execute(array(
        ':e' => $eid,
        ':d' => $d,
        ':t' => $t,
        ':ci' => $ci !== '' ? $ci : null,
        ':co' => $co !== '' ? $co : null,
        ':r' => $reason,
        ':c' => $now,
        ':u' => $now
    ));

    $requestId = (int)$pdo->lastInsertId();
    try {
        $stRelease = $pdo->prepare("SELECT RELEASE_LOCK(:lock_name)");
        $stRelease->execute(array(':lock_name' => $lockName));
    } catch (Exception $e) {
    }

    $requesterName = '';
    try {
        $stEmp = $pdo->prepare("SELECT name, department, position, email FROM employees WHERE id = :id LIMIT 1");
        $stEmp->execute(array(':id' => $eid));
        $empRow = $stEmp->fetch(PDO::FETCH_ASSOC);
        if ($empRow && isset($empRow['name'])) {
            $requesterName = trim((string)$empRow['name']);
        }
    } catch (Exception $e) {
    }
    if ($requesterName === '') {
        if (isset($_SESSION['user_name']) && trim((string)$_SESSION['user_name']) !== '') {
            $requesterName = trim((string)$_SESSION['user_name']);
        } else {
            $requesterName = '직원 #' . (int)$eid;
        }
    }

    $requestTypeLabel = '출퇴근 수정';
    if ($t === 'check_in') {
        $requestTypeLabel = '출근 수정';
    } elseif ($t === 'check_out') {
        $requestTypeLabel = '퇴근 수정';
    }

    $requestDetailParts = array();
    if ($ci !== '') {
        $requestDetailParts[] = '출근 ' . (strlen($ci) >= 16 ? substr($ci, 11, 5) : $ci);
    }
    if ($co !== '') {
        $requestDetailParts[] = '퇴근 ' . (strlen($co) >= 16 ? substr($co, 11, 5) : $co);
    }
    $requestDetailText = '-';
    if (count($requestDetailParts) > 0) {
        $requestDetailText = implode(' / ', $requestDetailParts);
    }

    $messageText = implode("\n", array(
        '[CPMS 출퇴근 수정 요청]',
        '',
        '요청자 : ' . $requesterName,
        '요청일자 : ' . $d,
        '요청구분 : ' . $requestTypeLabel,
        '요청 내용 : ' . $requestDetailText,
        '요청 사유 : ' . ($reason !== '' ? $reason : '-'),
        '',
        '출퇴근/근태관리에서 확인 바랍니다.'
    ));

    try {
        require_once __DIR__ . '/../common/chat_notification_helpers.php';
        cpms_google_chat_send_to_management_department(
            $pdo,
            $messageText,
            'ATTENDANCE_REQUEST_CREATED',
            $requestId,
            'ATTENDANCE_REQUEST'
        );
    } catch (Exception $e) {
        error_log('[attendance_chat_notify] send fail source_id=' . (int)$requestId . ' error=' . $e->getMessage());
    }

    flash_set('success', '출퇴근 수정 요청이 등록되었습니다.');
} catch (Exception $e) {
    if (isset($lockName) && isset($locked) && $locked) {
        try {
            $stRelease = $pdo->prepare("SELECT RELEASE_LOCK(:lock_name)");
            $stRelease->execute(array(':lock_name' => $lockName));
        } catch (Exception $releaseException) {
        }
    }
    flash_set('danger', '요청 저장 실패');
}

header('Location: ' . $attendanceRequestReturnUrl);
exit;
