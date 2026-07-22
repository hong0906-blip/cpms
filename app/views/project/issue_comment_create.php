<?php
/**
 * C:\www\cpms\app\views\project\issue_comment_create.php
 * - 임원/공사 이슈 댓글 통합 저장 액션(POST)
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../services/ConstructionIssueCommentNotificationService.php';

use App\Core\Auth;
use App\Core\Db;

// Bad Request 방지 + dashboard_executive redirect
$defaultRedirect = '?r=dashboard_executive';
function cpms_issue_comment_redirect($key, $default)
{
    if ($key === 'dashboard_executive') return '?r=dashboard_executive';
    if ($key === 'construction_issue') return '?r=공사&tab=issues';
    if ($key === 'dashboard_employee') return '?r=dashboard_employee';
    return $default;
}

if (!Auth::check()) { header('Location: ?r=login'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash_set('error', 'ISSUE_COMMENT_LOADED=Y 댓글 등록 실패: 잘못된 요청 방식입니다.');
    header('Location: ' . $defaultRedirect);
    exit;
}

$token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
if (!csrf_check($token)) {
    flash_set('error', 'ISSUE_COMMENT_LOADED=Y 댓글 등록 실패: 보안 토큰 오류입니다.');
    header('Location: ' . $defaultRedirect);
    exit;
}

$issueId = isset($_POST['issue_id']) ? (int)$_POST['issue_id'] : 0;
$comment = isset($_POST['comment']) ? trim((string)$_POST['comment']) : '';
if ($comment === '' && isset($_POST['content'])) $comment = trim((string)$_POST['content']);
if ($comment === '' && isset($_POST['body'])) $comment = trim((string)$_POST['body']);
if ($comment === '' && isset($_POST['comment_text'])) $comment = trim((string)$_POST['comment_text']);
$redirect = cpms_issue_comment_redirect(isset($_POST['redirect']) ? trim((string)$_POST['redirect']) : '', $defaultRedirect);

if ($issueId <= 0 || $comment === '') {
    flash_set('error', 'ISSUE_COMMENT_LOADED=Y 댓글 등록 실패: 필수값이 누락되었습니다.');
    header('Location: ' . $redirect);
    exit;
}

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error', 'ISSUE_COMMENT_LOADED=Y 댓글 등록 실패: DB 연결 실패');
    header('Location: ' . $redirect);
    exit;
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_project_issue_comments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        issue_id INT NOT NULL,
        comment TEXT NOT NULL,
        created_by INT NULL,
        created_by_name VARCHAR(80) NULL,
        created_by_email VARCHAR(120) NULL,
        created_at DATETIME NOT NULL,
        KEY idx_issue_comments_issue(issue_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $user = Auth::user();
    $createdBy = null;
    if (is_array($user) && isset($user['id'])) $createdBy = (int)$user['id'];
    else if (method_exists('App\\Core\\Auth', 'id')) $createdBy = (int)Auth::id();
    $createdByName = (string)Auth::userName();
    $createdByEmail = (string)Auth::userEmail();

    $ins = $pdo->prepare("INSERT INTO cpms_project_issue_comments(issue_id, comment, created_by, created_by_name, created_by_email, created_at)
                          VALUES(:issue_id, :comment, :created_by, :created_by_name, :created_by_email, NOW())");
    $ins->bindValue(':issue_id', $issueId, PDO::PARAM_INT);
    $ins->bindValue(':comment', $comment, PDO::PARAM_STR);
    if ($createdBy === null || $createdBy <= 0) $ins->bindValue(':created_by', null, PDO::PARAM_NULL);
    else $ins->bindValue(':created_by', $createdBy, PDO::PARAM_INT);
    $ins->bindValue(':created_by_name', $createdByName, PDO::PARAM_STR);
    $ins->bindValue(':created_by_email', $createdByEmail, PDO::PARAM_STR);
    $ins->execute();
    $commentId = (int)$pdo->lastInsertId();

    cpms_construction_issue_comment_send_dm($pdo, $commentId, true);

    flash_set('success', 'ISSUE_COMMENT_LOADED=Y 댓글을 등록했습니다.');
} catch (Exception $e) {
    flash_set('error', 'ISSUE_COMMENT_LOADED=Y 댓글 등록 실패: ' . $e->getMessage());
}

header('Location: ' . $redirect);
exit;
