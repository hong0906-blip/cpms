<?php
/**
 * C:\www\cpms\app\views\project\progress_statement_comment_save.php
 * 권한 있는 사용자의 기성내역서 댓글 저장 POST 처리.
 * PHP 5.6 compatible.
 */

use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/../../services/ProgressStatementService.php';
require_once __DIR__ . '/../../services/ProgressStatementNotificationService.php';

if (!Auth::check()) { header('Location: ?r=login'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    flash_set('error', '보안 토큰이 유효하지 않습니다.'); header('Location: ?r=대시보드'); exit;
}
$pdo = Db::pdo();
$actor = cpms_progress_statement_actor($pdo);
$statementId = isset($_POST['statement_id']) ? (int)$_POST['statement_id'] : 0;
$returnUrl = cpms_progress_statement_safe_return(isset($_POST['return_url']) ? $_POST['return_url'] : '', '?r=공무&tab=progress_statement_review&statement_id=' . $statementId);
try {
    if (!$pdo || !cpms_progress_statement_schema_ready($pdo)) throw new Exception('기성내역서 DB 설치·점검을 먼저 실행해주세요.');
    $row = cpms_progress_statement_find($pdo, $statementId, false);
    if (!is_array($row)) throw new Exception('기성내역서를 찾을 수 없습니다.');
    if (!cpms_progress_statement_can_comment($pdo, (int)$row['project_id'], $actor)) throw new Exception('댓글 작성 권한이 없습니다.');
    $comment = trim(isset($_POST['comment_text']) ? (string)$_POST['comment_text'] : '');
    $parentCommentId = isset($_POST['parent_comment_id']) ? (int)$_POST['parent_comment_id'] : 0;
    if ($comment === '') throw new Exception('댓글 내용을 입력해주세요.');
    if (function_exists('mb_strlen') && mb_strlen($comment, 'UTF-8') > 2000) throw new Exception('댓글은 2,000자 이하로 입력해주세요.');
    if ($parentCommentId > 0) {
        $parentSt = $pdo->prepare("SELECT id FROM cpms_progress_statement_comments WHERE id=:id AND statement_id=:statement_id LIMIT 1");
        $parentSt->execute(array(':id'=>$parentCommentId, ':statement_id'=>$statementId));
        if (!$parentSt->fetchColumn()) throw new Exception('답글 대상 댓글을 찾을 수 없습니다.');
    }
    $pdo->beginTransaction();
    $st = $pdo->prepare("INSERT INTO cpms_progress_statement_comments
        (statement_id,parent_comment_id,author_employee_id,author_name,author_email,author_photo_path,comment_text,created_at)
        VALUES (:statement_id,:parent_comment_id,:author_employee_id,:author_name,:author_email,:author_photo_path,:comment_text,:created_at)");
    $st->execute(array(':statement_id'=>$statementId, ':author_employee_id'=>$actor['id'] > 0 ? $actor['id'] : null,
        ':parent_comment_id'=>$parentCommentId > 0 ? $parentCommentId : null,
        ':author_name'=>$actor['name'], ':author_email'=>$actor['email'], ':author_photo_path'=>isset($actor['photo_path']) ? $actor['photo_path'] : '',
        ':comment_text'=>$comment, ':created_at'=>date('Y-m-d H:i:s')));
    cpms_progress_statement_add_history($pdo, $statementId, 'commented', $row['status'], $row['status'], $actor, ($parentCommentId > 0 ? '[대댓글] ' : '') . $comment);
    $pdo->commit();
    flash_set('success', $parentCommentId > 0 ? '대댓글이 등록되었습니다.' : '댓글이 등록되었습니다.');
    cpms_progress_statement_flush_redirect($returnUrl);
    cpms_progress_statement_notify($pdo, $statementId, 'commented', $actor, array('comment'=>$comment, 'is_reply'=>$parentCommentId > 0));
    exit;
} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    flash_set('error', $e->getMessage());
}
header('Location: ' . $returnUrl); exit;
