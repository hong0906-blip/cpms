<?php
/**
 * C:\www\cpms\app\views\construction\issue_status_save.php
 * - 이슈 상태 변경 전용 AJAX 저장 액션(JSON only)
 *
 * 변경 지점 주석:
 * - 공사 이슈 route 통일
 * - issue status DB 전후 상태 반환
 * - before_status / after_status 표시
 *
 * PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

function cpms_issue_status_save_json($payload)
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

function cpms_issue_status_save_response($data)
{
    $base = array(
        'ok' => false,
        'message' => '',
        'issue_id' => 0,
        'requested_status' => '',
        'before_status' => '',
        'after_status' => '',
        'row_count' => 0,
        'received_post' => array(),
    );
    foreach ($data as $k => $v) {
        $base[$k] = $v;
    }
    cpms_issue_status_save_json($base);
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

$issueIdRaw = isset($_POST['issue_id']) ? (string)$_POST['issue_id'] : '';
$statusRaw = isset($_POST['status']) ? trim((string)$_POST['status']) : '';
$receivedPost = array(
    'issue_id' => $issueIdRaw,
    'status' => $statusRaw,
);

if (!Auth::check()) {
    cpms_issue_status_save_response(array('ok' => false, 'message' => '로그인이 필요합니다.', 'received_post' => $receivedPost));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cpms_issue_status_save_response(array('ok' => false, 'message' => 'POST 요청만 허용됩니다.', 'received_post' => $receivedPost));
}

$token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
if (!csrf_check($token)) {
    cpms_issue_status_save_response(array('ok' => false, 'message' => '보안토큰 오류', 'received_post' => $receivedPost));
}

$issueId = (int)$issueIdRaw;
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
    cpms_issue_status_save_response(array('ok' => false, 'message' => '요청값 오류', 'issue_id' => $issueId, 'requested_status' => $resolvedStatus, 'received_post' => $receivedPost));
}

$pdo = Db::pdo();
if (!$pdo) {
    cpms_issue_status_save_response(array('ok' => false, 'message' => 'DB 연결에 실패했습니다.', 'issue_id' => $issueId, 'requested_status' => $resolvedStatus, 'received_post' => $receivedPost));
}

try {
    if (!cpms_issue_status_save_column_exists($pdo, 'cpms_project_issues', 'status')) {
        cpms_issue_status_save_response(array('ok' => false, 'message' => 'status 컬럼을 찾을 수 없습니다.', 'issue_id' => $issueId, 'requested_status' => $resolvedStatus, 'received_post' => $receivedPost));
    }

    $stIssue = $pdo->prepare("SELECT id, created_by_email, status FROM cpms_project_issues WHERE id=:id LIMIT 1");
    $stIssue->bindValue(':id', $issueId, PDO::PARAM_INT);
    $stIssue->execute();
    $issue = $stIssue->fetch();

    if (!is_array($issue)) {
        cpms_issue_status_save_response(array('ok' => false, 'message' => '이슈없음', 'issue_id' => $issueId, 'requested_status' => $resolvedStatus, 'received_post' => $receivedPost));
    }

    $beforeStatus = isset($issue['status']) ? (string)$issue['status'] : '';

    $userEmail = (string)Auth::userEmail();
    $userRole = (string)Auth::userRole();

    $can = false;
    if ($userRole === 'master' || $userRole === 'executive') $can = true;
    if (!$can && method_exists('App\\Core\\Auth', 'canManageConstruction')) $can = Auth::canManageConstruction();

    $ownerEmail = isset($issue['created_by_email']) ? (string)$issue['created_by_email'] : '';
    if (!$can && $ownerEmail !== '' && $userEmail !== '' && $ownerEmail === $userEmail) $can = true;

    if (!$can) {
        cpms_issue_status_save_response(array('ok' => false, 'message' => '권한없음', 'issue_id' => $issueId, 'requested_status' => $resolvedStatus, 'before_status' => $beforeStatus, 'after_status' => $beforeStatus, 'row_count' => 0, 'received_post' => $receivedPost));
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
    $rowCount = (int)$up->rowCount();

    $stAfter = $pdo->prepare("SELECT status FROM cpms_project_issues WHERE id=:id LIMIT 1");
    $stAfter->bindValue(':id', $issueId, PDO::PARAM_INT);
    $stAfter->execute();
    $afterRow = $stAfter->fetch();
    $afterStatus = is_array($afterRow) && isset($afterRow['status']) ? (string)$afterRow['status'] : '';

    if ($afterStatus !== $resolvedStatus) {
        cpms_issue_status_save_response(array(
            'ok' => false,
            'message' => 'UPDATE 후에도 상태가 변경되지 않았습니다.',
            'issue_id' => $issueId,
            'requested_status' => $resolvedStatus,
            'before_status' => $beforeStatus,
            'after_status' => $afterStatus,
            'row_count' => $rowCount,
            'received_post' => $receivedPost,
        ));
    }

    cpms_issue_status_save_response(array(
        'ok' => true,
        'message' => '이슈 상태가 변경되었습니다.',
        'issue_id' => $issueId,
        'requested_status' => $resolvedStatus,
        'before_status' => $beforeStatus,
        'after_status' => $afterStatus,
        'row_count' => $rowCount,
        'received_post' => $receivedPost,
    ));
} catch (Exception $e) {
    cpms_issue_status_save_response(array('ok' => false, 'message' => '기타사유: ' . $e->getMessage(), 'issue_id' => $issueId, 'requested_status' => $resolvedStatus, 'received_post' => $receivedPost));
}