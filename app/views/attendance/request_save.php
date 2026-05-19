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
$d = trim((string)$_POST['request_date']);
$t = trim((string)$_POST['request_type']);
$ci = trim((string)$_POST['requested_check_in']);
$co = trim((string)$_POST['requested_check_out']);
$reason = trim((string)$_POST['reason']);
$now = attendance_now();

try {
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
        $requestDetailParts[] = '출근 ' . substr($ci, 0, 5);
    }
    if ($co !== '') {
        $requestDetailParts[] = '퇴근 ' . substr($co, 0, 5);
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
    flash_set('danger', '요청 저장 실패');
}

header('Location: ?r=대시보드');
exit;