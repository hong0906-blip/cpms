<?php
/**
 * C:\www\cpms\app\views\construction\issue_update.php
 * - 공사 기준 이슈 상태 변경 액션(POST)
 * - 임원 대시보드/공사 화면 공용
 *
 * 변경 지점 주석:
 * - 이슈 route construction 기준 통일
 * - 임원 이슈 상태변경 construction/issue_update
 *
 * PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

function cpms_issue_update_column_exists($pdo, $table, $column)
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

function cpms_issue_update_redirect_url($redirectKey, $projectId)
{
    if ($redirectKey === 'construction') {
        if ((int)$projectId > 0) return '?r=construction_home&pid=' . (int)$projectId . '&tab=issues';
        return '?r=construction_home&tab=issues';
    }
    return '?r=dashboard_executive';
}

if (!Auth::check()) { header('Location: ?r=login'); exit; }

$issueId = isset($_POST['issue_id']) ? (int)$_POST['issue_id'] : 0;
$statusRaw = isset($_POST['status']) ? trim((string)$_POST['status']) : '';
$redirectKey = isset($_POST['redirect']) ? trim((string)$_POST['redirect']) : 'dashboard_executive';
$token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';

$statusMap = array(
    'pending' => '접수',
    'in_progress' => '처리중',
    'done' => '처리완료',
    '접수' => '접수',
    '처리중' => '처리중',
    '처리완료' => '처리완료',
);
$resolvedStatus = isset($statusMap[$statusRaw]) ? $statusMap[$statusRaw] : '';

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error', '이슈 상태 변경 실패: DB 연결에 실패했습니다.');
    header('Location: ?r=dashboard_executive');
    exit;
}

$projectId = 0;
if ($issueId > 0) {
    try {
        $stIssue = $pdo->prepare("SELECT id, project_id, created_by_email FROM cpms_project_issues WHERE id=:id LIMIT 1");
        $stIssue->bindValue(':id', $issueId, PDO::PARAM_INT);
        $stIssue->execute();
        $issue = $stIssue->fetch();
        if (is_array($issue)) {
            $projectId = isset($issue['project_id']) ? (int)$issue['project_id'] : 0;
        }
    } catch (Exception $e) {
        // redirect 시 projectId 없이 이동
    }
}
$redirectUrl = cpms_issue_update_redirect_url($redirectKey, $projectId);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash_set('error', '이슈 상태 변경 실패: 잘못된 요청 방식입니다.');
    header('Location: ' . $redirectUrl);
    exit;
}
if (!csrf_check($token)) {
    flash_set('error', '이슈 상태 변경 실패: 보안 토큰이 유효하지 않습니다.');
    header('Location: ' . $redirectUrl);
    exit;
}
if ($issueId <= 0 || $resolvedStatus === '') {
    flash_set('error', '이슈 상태 변경 실패: 필수값이 누락되었거나 상태값이 올바르지 않습니다.');
    header('Location: ' . $redirectUrl);
    exit;
}

$userEmail = (string)Auth::userEmail();
$userRole = (string)Auth::userRole();
$can = false;
if ($userRole === 'master' || $userRole === 'executive') $can = true;
if (!$can && method_exists('App\\Core\\Auth', 'canManageConstruction')) $can = Auth::canManageConstruction();

try {
    $stIssue = $pdo->prepare("SELECT id, project_id, created_by_email FROM cpms_project_issues WHERE id=:id LIMIT 1");
    $stIssue->bindValue(':id', $issueId, PDO::PARAM_INT);
    $stIssue->execute();
    $issue = $stIssue->fetch();

    if (!is_array($issue)) {
        flash_set('error', '이슈 상태 변경 실패: 이슈를 찾을 수 없습니다.');
        header('Location: ' . $redirectUrl);
        exit;
    }

    $ownerEmail = isset($issue['created_by_email']) ? (string)$issue['created_by_email'] : '';
    if (!$can && $ownerEmail !== '' && $userEmail !== '' && $ownerEmail === $userEmail) $can = true;

    if (!$can) {
        flash_set('error', '이슈 상태 변경 실패: 권한이 없습니다.');
        header('Location: ' . $redirectUrl);
        exit;
    }

    $hasUpdatedAt = cpms_issue_update_column_exists($pdo, 'cpms_project_issues', 'updated_at');
    if ($hasUpdatedAt) {
        $up = $pdo->prepare("UPDATE cpms_project_issues SET status=:status, updated_at=NOW() WHERE id=:id");
    } else {
        $up = $pdo->prepare("UPDATE cpms_project_issues SET status=:status WHERE id=:id");
    }
    $up->bindValue(':status', $resolvedStatus, PDO::PARAM_STR);
    $up->bindValue(':id', $issueId, PDO::PARAM_INT);
    $up->execute();

    flash_set('success', '이슈 상태가 변경되었습니다.');
} catch (Exception $e) {
    flash_set('error', '이슈 상태 변경 실패: ' . $e->getMessage());
}

header('Location: ' . $redirectUrl);
exit;