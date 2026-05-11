<?php
/**
 * C:\www\cpms\app\views\construction\issue_status_save.php
 * - 이슈 상태 변경 전용 AJAX 저장 액션(JSON only)
 *
 * 변경 지점 주석:
 * - AJAX 이슈 상태 저장
 * - Bad Request 방지
 * - JSON 응답
 *
 * PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

function cpms_issue_status_save_json($ok, $message, $status)
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array(
        'ok' => (bool)$ok,
        'message' => (string)$message,
        'status' => (string)$status,
    ));
    exit;
}

function cpms_issue_status_save_column_exists($pdo, $table, $column)
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

if (!Auth::check()) {
    cpms_issue_status_save_json(false, '로그인이 필요합니다.', '');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cpms_issue_status_save_json(false, 'POST 요청만 허용됩니다.', '');
}

$token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
$issueId = isset($_POST['issue_id']) ? (int)$_POST['issue_id'] : 0;
$statusRaw = isset($_POST['status']) ? trim((string)$_POST['status']) : '';

if (!csrf_check($token)) {
    cpms_issue_status_save_json(false, '보안 토큰이 유효하지 않습니다.', '');
}

$statusMap = array(
    'pending' => '접수',
    'in_progress' => '처리중',
    'done' => '처리완료',
    '접수' => '접수',
    '처리중' => '처리중',
    '처리완료' => '처리완료',
);
$resolvedStatus = isset($statusMap[$statusRaw]) ? $statusMap[$statusRaw] : '';
if ($issueId <= 0 || $resolvedStatus === '') {
    cpms_issue_status_save_json(false, '필수값이 누락되었거나 상태값이 올바르지 않습니다.', '');
}

$pdo = Db::pdo();
if (!$pdo) {
    cpms_issue_status_save_json(false, 'DB 연결에 실패했습니다.', '');
}

try {
    $stIssue = $pdo->prepare("SELECT id, created_by_email FROM cpms_project_issues WHERE id=:id LIMIT 1");
    $stIssue->bindValue(':id', $issueId, PDO::PARAM_INT);
    $stIssue->execute();
    $issue = $stIssue->fetch();

    if (!is_array($issue)) {
        cpms_issue_status_save_json(false, '이슈를 찾을 수 없습니다.', '');
    }

    $userEmail = (string)Auth::userEmail();
    $userRole = (string)Auth::userRole();

    $can = false;
    if ($userRole === 'master' || $userRole === 'executive') $can = true;
    if (!$can && method_exists('App\\Core\\Auth', 'canManageConstruction')) $can = Auth::canManageConstruction();

    $ownerEmail = isset($issue['created_by_email']) ? (string)$issue['created_by_email'] : '';
    if (!$can && $ownerEmail !== '' && $userEmail !== '' && $ownerEmail === $userEmail) $can = true;

    if (!$can) {
        cpms_issue_status_save_json(false, '권한이 없습니다.', '');
    }

    $hasUpdatedAt = cpms_issue_status_save_column_exists($pdo, 'cpms_project_issues', 'updated_at');
    if ($hasUpdatedAt) {
        $up = $pdo->prepare("UPDATE cpms_project_issues SET status=:status, updated_at=NOW() WHERE id=:id");
    } else {
        $up = $pdo->prepare("UPDATE cpms_project_issues SET status=:status WHERE id=:id");
    }
    $up->bindValue(':status', $resolvedStatus, PDO::PARAM_STR);
    $up->bindValue(':id', $issueId, PDO::PARAM_INT);
    $up->execute();

    cpms_issue_status_save_json(true, '이슈 상태가 변경되었습니다.', $resolvedStatus);
} catch (Exception $e) {
    cpms_issue_status_save_json(false, '이슈 상태 변경 실패: ' . $e->getMessage(), '');
}