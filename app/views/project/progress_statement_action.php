<?php
/**
 * C:\www\cpms\app\views\project\progress_statement_action.php
 * 공무팀 기성내역서 승인·반려 POST 처리와 승인 후 Drive 저장.
 * PHP 5.6 compatible.
 */

use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/../../services/ProgressStatementService.php';
require_once __DIR__ . '/../../services/ProgressStatementDriveService.php';
require_once __DIR__ . '/../../services/ProgressStatementNotificationService.php';

if (!Auth::check()) { header('Location: ?r=login'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    flash_set('error', '보안 토큰이 유효하지 않습니다.'); header('Location: ?r=공무&tab=progress_statement_review'); exit;
}
$pdo = Db::pdo();
$actor = cpms_progress_statement_actor($pdo);
$statementId = isset($_POST['statement_id']) ? (int)$_POST['statement_id'] : 0;
$action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
$returnUrl = cpms_progress_statement_safe_return(isset($_POST['return_url']) ? $_POST['return_url'] : '', '?r=공무&tab=progress_statement_review&statement_id=' . $statementId);
try {
    if (!$pdo || !cpms_progress_statement_schema_ready($pdo)) throw new Exception('기성내역서 DB 설치·점검을 먼저 실행해주세요.');
    if (!cpms_progress_statement_can_review()) throw new Exception('공무팀 또는 마스터만 승인·반려할 수 있습니다.');
    if ($action !== 'approve' && $action !== 'reject') throw new Exception('처리 종류가 올바르지 않습니다.');
    $reason = trim(isset($_POST['reject_reason']) ? (string)$_POST['reject_reason'] : '');
    if ($action === 'reject' && $reason === '') throw new Exception('반려사유를 입력해주세요.');
    $pdo->beginTransaction();
    $row = cpms_progress_statement_find($pdo, $statementId, true);
    if (!is_array($row)) throw new Exception('기성내역서를 찾을 수 없습니다.');
    if (!in_array((string)$row['status'], array('pending','resubmitted'), true)) throw new Exception('이미 처리된 건은 다시 승인하거나 반려할 수 없습니다.');
    $oldStatus = (string)$row['status'];
    $newStatus = $action === 'approve' ? 'approved' : 'rejected';
    $eventType = $action === 'approve' ? 'approved' : 'rejected';
    $now = date('Y-m-d H:i:s');
    $st = $pdo->prepare("UPDATE cpms_progress_statements SET status=:status, reviewed_by=:reviewed_by,
        reviewed_by_name=:reviewed_by_name, reviewed_by_email=:reviewed_by_email, reviewed_at=:reviewed_at,
        reject_reason=:reject_reason, approved_at=:approved_at,
        drive_upload_status=:drive_upload_status, updated_at=:updated_at
        WHERE id=:id AND status=:old_status");
    $st->execute(array(':status'=>$newStatus, ':reviewed_by'=>$actor['id'] > 0 ? $actor['id'] : null,
        ':reviewed_by_name'=>$actor['name'], ':reviewed_by_email'=>$actor['email'], ':reviewed_at'=>$now,
        ':reject_reason'=>$action === 'reject' ? $reason : null, ':approved_at'=>$action === 'approve' ? $now : null,
        ':drive_upload_status'=>$action === 'approve' ? 'pending' : 'not_started', ':updated_at'=>$now,
        ':id'=>$statementId, ':old_status'=>$oldStatus));
    if ($st->rowCount() !== 1) throw new Exception('중복 처리 요청이 감지되었습니다.');
    cpms_progress_statement_add_history($pdo, $statementId, $eventType, $oldStatus, $newStatus, $actor, $action === 'reject' ? $reason : '공무 검토 승인');
    $pdo->commit();
    if ($action === 'approve') {
        flash_set('success', '승인되었습니다. Drive 저장과 알림은 백그라운드에서 처리됩니다.');
        cpms_progress_statement_flush_redirect($returnUrl);
        $driveResult = cpms_progress_statement_drive_upload($pdo, $statementId, $actor, false);
        cpms_progress_statement_notify($pdo, $statementId, 'approved', $actor, array('drive_message'=>empty($driveResult['ok']) ? '실패' : '완료'));
        if (empty($driveResult['ok'])) {
            cpms_progress_statement_notify($pdo, $statementId, 'drive_upload_failed', $actor, array('drive_message'=>$driveResult['message']));
        }
        exit;
    } else {
        flash_set('success', '반려 처리되었습니다.');
        cpms_progress_statement_flush_redirect($returnUrl);
        cpms_progress_statement_notify($pdo, $statementId, 'rejected', $actor, array('reject_reason'=>$reason));
        exit;
    }
} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    flash_set('error', $e->getMessage());
}
header('Location: ' . $returnUrl); exit;
