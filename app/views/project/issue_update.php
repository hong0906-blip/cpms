<?php
/**
 * C:\www\cpms\app\views\project\issue_update.php
 * - 이슈 상태 변경 액션(POST)
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

// Bad Request 방지 + dashboard_executive redirect
$defaultRedirect = '?r=dashboard_executive';
function cpms_issue_update_redirect($key, $default)
{
    if ($key === 'dashboard_executive') return '?r=dashboard_executive';
    if ($key === 'dashboard_employee') return '?r=dashboard_employee';
    return $default;
}

if (!Auth::check()) { header('Location: ?r=login'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash_set('error', 'ISSUE_UPDATE_LOADED=Y 이슈 상태 변경 실패: 잘못된 요청 방식입니다.');
    header('Location: ' . $defaultRedirect);
    exit;
}

$token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
if (!csrf_check($token)) {
    flash_set('error', 'ISSUE_UPDATE_LOADED=Y 이슈 상태 변경 실패: 보안 토큰 오류입니다.');
    header('Location: ' . $defaultRedirect);
    exit;
}

$issueId = isset($_POST['issue_id']) ? (int)$_POST['issue_id'] : 0;
if ($issueId <= 0 && isset($_POST['id'])) $issueId = (int)$_POST['id'];

$statusCode = isset($_POST['status_code']) ? trim((string)$_POST['status_code']) : '';
$statusRaw = isset($_POST['status']) ? trim((string)$_POST['status']) : '';
$redirectKey = isset($_POST['redirect']) ? trim((string)$_POST['redirect']) : '';
$redirect = cpms_issue_update_redirect($redirectKey, $defaultRedirect);

$statusMap = array(
    'pending' => '접수',
    'in_progress' => '처리중',
    'done' => '처리완료',
    '접수' => '접수',
    '처리중' => '처리중',
    '처리완료' => '처리완료'
);

$resolvedStatus = '';
if ($statusCode !== '' && isset($statusMap[$statusCode])) {
    $resolvedStatus = $statusMap[$statusCode];
} else if ($statusRaw !== '' && isset($statusMap[$statusRaw])) {
    $resolvedStatus = $statusMap[$statusRaw];
}

if ($issueId <= 0 || $resolvedStatus === '') {
    flash_set('error', 'ISSUE_UPDATE_LOADED=Y 이슈 상태 변경 실패: 필수값이 누락되었거나 상태값이 올바르지 않습니다.');
    header('Location: ' . $redirect);
    exit;
}

$role = (string)Auth::userRole();
$can = false;
if ($role === 'master' || $role === 'executive') $can = true;
if (!$can && method_exists('App\\Core\\Auth', 'canManageConstruction')) $can = Auth::canManageConstruction();
if (!$can) {
    flash_set('error', 'ISSUE_UPDATE_LOADED=Y 이슈 상태 변경 실패: 권한이 없습니다.');
    header('Location: ' . $redirect);
    exit;
}

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error', 'ISSUE_UPDATE_LOADED=Y 이슈 상태 변경 실패: DB 연결 실패');
    header('Location: ' . $redirect);
    exit;
}

try {
    $up = $pdo->prepare("UPDATE cpms_project_issues SET status = :status, updated_at = NOW() WHERE id = :id");
    $up->bindValue(':status', $resolvedStatus, PDO::PARAM_STR);
    $up->bindValue(':id', $issueId, PDO::PARAM_INT);
    $up->execute();
    flash_set('success', 'ISSUE_UPDATE_LOADED=Y 이슈 상태가 변경되었습니다.');
} catch (Exception $e) {
    flash_set('error', 'ISSUE_UPDATE_LOADED=Y 이슈 상태 변경 실패: ' . $e->getMessage());
}

header('Location: ' . $redirect);
exit;