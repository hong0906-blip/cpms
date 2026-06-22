<?php /** 출퇴근 요청 저장 */
use App\Core\Db;

require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check(isset($_POST['_csrf']) ? $_POST['_csrf'] : '')) {
    flash_set('danger', '잘못된 요청입니다.');
    header('Location: ?r=대시보드');
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
    header('Location: ?r=대시보드');
    exit;
}

if (!in_array($t, array('check_in', 'check_out', 'both'), true)) {
    flash_set('danger', '요청 종류가 올바르지 않습니다.');
    header('Location: ?r=대시보드');
    exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
    flash_set('danger', '요청 날짜가 올바르지 않습니다.');
    header('Location: ?r=대시보드');
    exit;
}
if ($t === 'check_in') {
    $co = '';
} else if ($t === 'check_out') {
    $ci = '';
}
if (($t === 'check_in' || $t === 'both') && $ci === '') {
    flash_set('danger', '요청 출근시간을 선택해주세요.');
    header('Location: ?r=대시보드');
    exit;
}
if (($t === 'check_out' || $t === 'both') && $co === '') {
    flash_set('danger', '요청 퇴근시간을 선택해주세요.');
    header('Location: ?r=대시보드');
    exit;
}

$ciDate = attendance_datetime_date_part($ci);
$coDate = attendance_datetime_date_part($co);
if (($t === 'check_in' || $t === 'both') && $ciDate !== '' && $ciDate !== $d) {
    flash_set('danger', '요청 날짜와 출근시간의 날짜가 서로 다릅니다.');
    header('Location: ?r=대시보드');
    exit;
}
if (($t === 'check_out' || $t === 'both') && $coDate !== '' && $coDate !== $d) {
    flash_set('danger', '요청 날짜와 퇴근시간의 날짜가 서로 다릅니다.');
    header('Location: ?r=대시보드');
    exit;
}
if ($t === 'both' && $ciDate !== '' && $coDate !== '' && $ciDate !== $coDate) {
    flash_set('danger', '요청 날짜와 출퇴근 시간의 날짜가 서로 다릅니다. 요청 날짜를 ' . $ciDate . '로 선택해주세요.');
    header('Location: ?r=대시보드');
    exit;
}

try {
    $lockName = 'attendance_request_' . (int)$eid . '_' . $d . '_' . $t;
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
        header('Location: ?r=대시보드');
        exit;
    }

    $stExisting = $pdo->prepare("SELECT id FROM cpms_attendance_requests WHERE employee_id=:e AND request_date=:d AND request_type=:t AND status='pending' ORDER BY id DESC LIMIT 1");
    $stExisting->execute(array(':e' => $eid, ':d' => $d, ':t' => $t));
    $existingId = (int)$stExisting->fetchColumn();
    if ($existingId > 0) {
        try {
            $stRelease = $pdo->prepare("SELECT RELEASE_LOCK(:lock_name)");
            $stRelease->execute(array(':lock_name' => $lockName));
        } catch (Exception $e) {
        }
        flash_set('success', '이미 승인대기 중인 같은 수정 요청이 있습니다. 기존 요청 1건만 유지됩니다.');
        header('Location: ?r=대시보드');
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

header('Location: ?r=대시보드');
exit;
