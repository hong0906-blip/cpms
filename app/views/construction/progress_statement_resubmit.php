<?php
/**
 * C:\www\cpms\app\views\construction\progress_statement_resubmit.php
 * 반려 기성내역서의 새 Excel 파일 버전 재제출 POST 처리.
 * PHP 5.6 compatible.
 */

use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/../../services/ProgressStatementService.php';
require_once __DIR__ . '/../../services/ProgressStatementNotificationService.php';

if (!Auth::check()) { header('Location: ?r=login'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    flash_set('error', '보안 토큰이 유효하지 않습니다.'); header('Location: ?r=공사'); exit;
}
$pdo = Db::pdo();
$actor = cpms_progress_statement_actor($pdo);
$statementId = isset($_POST['statement_id']) ? (int)$_POST['statement_id'] : 0;
$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$returnUrl = '?r=공사&pid=' . $projectId . '&tab=progress_statement';
$fileInfo = null;
try {
    if (!$pdo || !cpms_progress_statement_schema_ready($pdo)) throw new Exception('기성내역서 DB 설치·점검을 먼저 실행해주세요.');
    $row = cpms_progress_statement_find($pdo, $statementId, false);
    if (!is_array($row)) throw new Exception('기성내역서를 찾을 수 없습니다.');
    $projectId = (int)$row['project_id'];
    $returnUrl = '?r=공사&pid=' . $projectId . '&tab=progress_statement';
    if (!cpms_progress_statement_can_submit($pdo, $projectId, $actor)) throw new Exception('해당 프로젝트 재제출 권한이 없습니다.');
    if ((string)$row['status'] !== 'rejected') throw new Exception('반려 상태의 건만 재제출할 수 있습니다.');
    $message = trim(isset($_POST['submit_message']) ? (string)$_POST['submit_message'] : '');
    $fileInfo = cpms_progress_statement_store_upload($projectId, 'statement_file');
    $pdo->beginTransaction();
    $locked = cpms_progress_statement_find($pdo, $statementId, true);
    if (!is_array($locked) || (string)$locked['status'] !== 'rejected') throw new Exception('이미 다른 사용자가 상태를 변경했습니다.');
    $versionNo = isset($locked['current_version_no']) ? ((int)$locked['current_version_no'] + 1) : 2;
    $fileId = cpms_progress_statement_insert_file($pdo, $statementId, $versionNo, $fileInfo, $actor, 'resubmission');
    $now = date('Y-m-d H:i:s');
    $st = $pdo->prepare("UPDATE cpms_progress_statements SET status='resubmitted', latest_file_id=:file_id,
        submit_message=:submit_message, submitted_by=:submitted_by, submitted_by_name=:submitted_by_name,
        submitted_by_email=:submitted_by_email, submitted_at=:submitted_at, reject_reason=NULL,
        reviewed_by=NULL, reviewed_by_name=NULL, reviewed_by_email=NULL, reviewed_at=NULL,
        drive_upload_status='not_started', drive_error_message=NULL, updated_at=:updated_at WHERE id=:id AND status='rejected'");
    $st->execute(array(':file_id'=>$fileId, ':submit_message'=>$message, ':submitted_by'=>$actor['id'] > 0 ? $actor['id'] : null,
        ':submitted_by_name'=>$actor['name'], ':submitted_by_email'=>$actor['email'], ':submitted_at'=>$now, ':updated_at'=>$now, ':id'=>$statementId));
    if ($st->rowCount() !== 1) throw new Exception('재제출 상태 변경에 실패했습니다.');
    cpms_progress_statement_add_history($pdo, $statementId, 'resubmitted', 'rejected', 'resubmitted', $actor, $message);
    $pdo->commit();
    flash_set('success', '새 파일 버전으로 재검토 요청했습니다.');
    cpms_progress_statement_flush_redirect($returnUrl);
    cpms_progress_statement_notify($pdo, $statementId, 'resubmitted', $actor, array());
    exit;
} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    cpms_progress_statement_remove_uncommitted_file($fileInfo);
    flash_set('error', $e->getMessage());
}
header('Location: ' . $returnUrl); exit;
