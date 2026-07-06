<?php
/**
 * C:\www\cpms\app\views\construction\issue_comment_create.php
 * - 공사 기준 이슈 댓글 등록(POST)
 * - 임원 대시보드/공사 화면 공용
 *
 * 변경 지점 주석:
 * - 임원 이슈 댓글 construction/issue_comment_create
 * - comment_text 기준 통일
 *
 * PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

function cpms_issue_comment_column_exists($pdo, $table, $column)
{
    try {
        $sql = "SHOW COLUMNS FROM `" . str_replace('`', '``', $table) . "` LIKE :col";
        $st = $pdo->prepare($sql);
        $st->bindValue(':col', $column, PDO::PARAM_STR);
        $st->execute();
        return (bool)$st->fetch();
    } catch (Exception $e) {
        return false;
    }
}

function cpms_issue_comment_ensure_columns($pdo)
{
    if (!cpms_issue_comment_column_exists($pdo, 'cpms_project_issue_comments', 'comment_text')) {
        $pdo->exec("ALTER TABLE cpms_project_issue_comments ADD COLUMN comment_text TEXT NULL AFTER issue_id");
    }
    if (!cpms_issue_comment_column_exists($pdo, 'cpms_project_issue_comments', 'created_at')) {
        $pdo->exec("ALTER TABLE cpms_project_issue_comments ADD COLUMN created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP");
    }
}

function cpms_issue_comment_redirect_url($redirectKey, $projectId)
{
    if ($redirectKey === 'dashboard_executive') return '?r=dashboard_executive&exec_tab=siteIssues';
    if ($redirectKey === 'construction_security' || $redirectKey === 'security') {
        if ((int)$projectId > 0) return '?r=공사&pid=' . (int)$projectId . '&tab=security';
        return '?r=공사&tab=security';
    }
    if ($redirectKey === 'construction') {
        if ((int)$projectId > 0) return '?r=공사&pid=' . (int)$projectId . '&tab=issues';
        return '?r=공사&tab=issues';
    }
    if ((int)$projectId > 0) return '?r=공사&pid=' . (int)$projectId . '&tab=issues';
    return '?r=공사';
}

if (!Auth::check()) { header('Location: ?r=login'); exit; }

$issueId = isset($_POST['issue_id']) ? (int)$_POST['issue_id'] : 0;
$comment = isset($_POST['comment_text']) ? trim((string)$_POST['comment_text']) : '';
$redirectKey = isset($_POST['redirect']) ? trim((string)$_POST['redirect']) : '';
$token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error', '댓글 등록 실패: DB 연결에 실패했습니다.');
    header('Location: ?r=공사');
    exit;
}

$projectId = 0;
if ($issueId > 0) {
    try {
        $st = $pdo->prepare("SELECT project_id FROM cpms_project_issues WHERE id=:id LIMIT 1");
        $st->bindValue(':id', $issueId, PDO::PARAM_INT);
        $st->execute();
        $row = $st->fetch();
        if (is_array($row)) $projectId = (int)$row['project_id'];
    } catch (Exception $e) {}
}
$redirectUrl = cpms_issue_comment_redirect_url($redirectKey, $projectId);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash_set('error', '댓글 등록 실패: 잘못된 요청 방식입니다.');
    header('Location: ' . $redirectUrl);
    exit;
}
if (!csrf_check($token)) {
    flash_set('error', '댓글 등록 실패: 보안 토큰이 유효하지 않습니다.');
    header('Location: ' . $redirectUrl);
    exit;
}
if ($issueId <= 0) {
    flash_set('error', '댓글 등록 실패: 이슈 정보가 올바르지 않습니다.');
    header('Location: ' . $redirectUrl);
    exit;
}
if ($comment === '') {
    flash_set('error', '댓글을 입력해주세요.');
    header('Location: ' . $redirectUrl);
    exit;
}

$userEmail = (string)Auth::userEmail();
$userRole  = (string)Auth::userRole();
$userName  = (string)Auth::userName();
if ($userName === '') $userName = '사용자';

try {
    cpms_issue_comment_ensure_columns($pdo);

    $st = $pdo->prepare("SELECT id, project_id, created_by_email FROM cpms_project_issues WHERE id = :id LIMIT 1");
    $st->bindValue(':id', $issueId, PDO::PARAM_INT);
    $st->execute();
    $issue = $st->fetch();

    if (!is_array($issue)) {
        flash_set('error', '댓글 등록 실패: 이슈를 찾을 수 없습니다.');
        header('Location: ' . $redirectUrl);
        exit;
    }

    $projectId = isset($issue['project_id']) ? (int)$issue['project_id'] : 0;
    $redirectUrl = cpms_issue_comment_redirect_url($redirectKey, $projectId);    
    $ownerEmail = isset($issue['created_by_email']) ? (string)$issue['created_by_email'] : '';

    $can = false;
    if ($userRole === 'executive' || $userRole === 'master') $can = true;
    if (!$can && method_exists('App\\Core\\Auth', 'canManageConstruction')) $can = Auth::canManageConstruction();
    if (!$can && $ownerEmail !== '' && $userEmail !== '' && $ownerEmail === $userEmail) $can = true;

    if (!$can) {
        flash_set('error', '댓글 등록 실패: 권한이 없습니다.');
        header('Location: ' . $redirectUrl);
        exit;
    }

    $ins = $pdo->prepare("INSERT INTO cpms_project_issue_comments(issue_id, comment_text, created_by_name, created_by_email, created_at)
                          VALUES(:iid, :ct, :nm, :em, NOW())");
    $ins->bindValue(':iid', $issueId, PDO::PARAM_INT);
    $ins->bindValue(':ct', $comment);
    $ins->bindValue(':nm', $userName);
    $ins->bindValue(':em', $userEmail);
    $ins->execute();

    flash_set('success', '댓글이 등록되었습니다.');
} catch (Exception $e) {
    flash_set('error', '댓글 등록 실패: ' . $e->getMessage());
}

header('Location: ' . $redirectUrl);
exit;
