<?php
/**
 * C:\www\cpms\app\views\project\progress_statement_drive_retry.php
 * 공무팀의 승인완료 기성내역서 Google Drive 재업로드 POST 처리.
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
$returnUrl = cpms_progress_statement_safe_return(isset($_POST['return_url']) ? $_POST['return_url'] : '', '?r=공무&tab=progress_statement_review&statement_id=' . $statementId);
$driveAttempted = false;
try {
    if (!cpms_progress_statement_can_review()) throw new Exception('공무팀 또는 마스터만 Drive 재업로드를 할 수 있습니다.');
    $row = cpms_progress_statement_find($pdo, $statementId, false);
    if (!is_array($row) || (string)$row['status'] !== 'approved') throw new Exception('승인완료 건을 찾을 수 없습니다.');
    if ((string)$row['drive_upload_status'] !== 'failed') throw new Exception('Drive 저장 실패 건만 다시 업로드할 수 있습니다.');
    $reserve = $pdo->prepare("UPDATE cpms_progress_statements SET drive_upload_status='pending', updated_at=:updated_at WHERE id=:id AND status='approved' AND drive_upload_status='failed'");
    $reserve->execute(array(':updated_at'=>date('Y-m-d H:i:s'), ':id'=>$statementId));
    if ($reserve->rowCount() !== 1) throw new Exception('이미 다른 사용자가 재업로드를 처리하고 있습니다.');
    $driveAttempted = true;
    flash_set('success', 'Drive 재업로드를 시작했습니다. 결과는 화면 상태와 알림으로 확인할 수 있습니다.');
    cpms_progress_statement_flush_redirect($returnUrl);
    $result = cpms_progress_statement_drive_upload($pdo, $statementId, $actor, true);
    if (empty($result['ok'])) throw new Exception($result['message']);
    cpms_progress_statement_notify($pdo, $statementId, 'drive_retry_success', $actor, array('drive_message'=>'완료'));
    exit;
} catch (Exception $e) {
    if ($driveAttempted) {
        cpms_progress_statement_notify($pdo, $statementId, 'drive_upload_failed', $actor, array('drive_message'=>$e->getMessage()));
        exit;
    }
    flash_set('error', 'Drive 재업로드 실패: ' . $e->getMessage());
}
header('Location: ' . $returnUrl); exit;
