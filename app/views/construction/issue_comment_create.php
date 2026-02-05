<?php
/**
 * C:\www\cpms\app\views\construction\issue_comment_create.php
 * - 공사: 이슈 댓글 등록(POST)
 * - project/issue_comment_create 로직과 동일(권한: 등록자 또는 임원)
 * - 리다이렉트는 공사 화면으로
 *
 * PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }

$token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
if (!csrf_check($token)) {
    flash_set('error','보안 토큰이 유효하지 않습니다.');
    header('Location: ?r=공사');
    exit;
}

$issueId = isset($_POST['issue_id']) ? (int)$_POST['issue_id'] : 0;
$comment = isset($_POST['comment_text']) ? trim((string)$_POST['comment_text']) : '';

if ($issueId <= 0) { flash_set('error','이슈 정보가 올바르지 않습니다.'); header('Location: ?r=공사'); exit; }
if ($comment === '') { flash_set('error','댓글을 입력해주세요.'); header('Location: ?r=공사'); exit; }
if (mb_strlen($comment,'UTF-8') > 255) { $comment = mb_substr($comment,0,255,'UTF-8'); }

$pdo = Db::pdo();
if (!$pdo) { flash_set('error','DB 연결 실패'); header('Location: ?r=공사'); exit; }

$userEmail = (string)Auth::userEmail();
$userRole  = (string)Auth::userRole();
$userName  = (string)Auth::userName();
if ($userName === '') $userName = '사용자';

try {
    $st = $pdo->prepare("SELECT id, project_id, created_by_email FROM cpms_project_issues WHERE id = :id LIMIT 1");
    $st->bindValue(':id', $issueId, \PDO::PARAM_INT);
    $st->execute();
    $issue = $st->fetch();

    if (!is_array($issue)) {
        flash_set('error','이슈를 찾을 수 없습니다.');
        header('Location: ?r=공사');
        exit;
    }

    $ownerEmail = isset($issue['created_by_email']) ? (string)$issue['created_by_email'] : '';
    $projectId  = isset($issue['project_id']) ? (int)$issue['project_id'] : 0;

    $can = false;
    if ($userRole === 'executive') $can = true;
    if ($ownerEmail !== '' && $userEmail !== '' && $ownerEmail === $userEmail) $can = true;

    if (!$can) { http_response_code(403); echo '403 Forbidden'; exit; }

    $ins = $pdo->prepare("INSERT INTO cpms_project_issue_comments(issue_id, comment_text, created_by_name, created_by_email)
                          VALUES(:iid, :ct, :nm, :em)");
    $ins->bindValue(':iid', $issueId, \PDO::PARAM_INT);
    $ins->bindValue(':ct', $comment);
    $ins->bindValue(':nm', $userName);
    $ins->bindValue(':em', $userEmail);
    $ins->execute();

    flash_set('success','댓글이 등록되었습니다.');
    if ($projectId > 0) {
        header('Location: ?r=공사&pid='.$projectId.'&tab=issues');
    } else {
        header('Location: ?r=공사');
    }
    exit;

} catch (Exception $e) {
    flash_set('error','댓글 등록 실패: '.$e->getMessage());
    header('Location: ?r=공사');
    exit;
}
