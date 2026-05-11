<?php
/**
 * C:\www\cpms\app\views\project\issue_update.php
 * - 이슈 상태 변경 액션(POST)
 * - PHP 5.6 호환
 * - 이슈 redirect 한글 URL 제거
 * - ISSUE_UPDATE_LOADED * 
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

$defaultRedirect = '?r=dashboard_executive';
$redirectInput = isset($_POST['redirect']) ? trim((string)$_POST['redirect']) : '';
$redirect = $defaultRedirect;
$base = rtrim((string)base_url(), '/');
$allowedRedirects = array(
    '?r=dashboard_executive',
    '/?r=dashboard_executive',
    $base . '/?r=dashboard_executive',
    '?r=dashboard_employee',
    '/?r=dashboard_employee'
);
if ($redirectInput !== '' && in_array($redirectInput, $allowedRedirects, true)) {
    $redirect = $redirectInput;
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
$statusRaw = isset($_POST['status']) ? trim((string)$_POST['status']) : '';
if ($issueId <= 0 || $statusRaw === '') {
    flash_set('error', 'ISSUE_UPDATE_LOADED=Y 이슈 상태 변경 실패: 필수값이 누락되었습니다.');
    header('Location: ' . $defaultRedirect);
    exit;
}

$statusMap = array(
    '접수' => '접수',
    '처리중' => '처리중',
    '처리완료' => '처리완료',
    'pending' => '접수',
    'in_progress' => '처리중',
    'done' => '처리완료'
);
if (!isset($statusMap[$statusRaw])) {
    flash_set('error', 'ISSUE_UPDATE_LOADED=Y 이슈 상태 변경 실패: 허용되지 않은 상태값입니다.');
    header('Location: ' . $defaultRedirect);
    exit;
}
$status = $statusMap[$statusRaw];

$role = (string)Auth::userRole();
$can = false;
if ($role === 'executive' || $role === 'master') $can = true;
if (!$can && method_exists('App\\Core\\Auth', 'canManageConstruction')) $can = Auth::canManageConstruction();
if (!$can) {
    flash_set('error', 'ISSUE_UPDATE_LOADED=Y 이슈 상태 변경 실패: 권한이 없습니다.');
    header('Location: ' . $defaultRedirect);
    exit;
}

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error', 'ISSUE_UPDATE_LOADED=Y 이슈 상태 변경 실패: DB 연결 실패');
    header('Location: ' . $defaultRedirect);
    exit;
}

try {
    $up = $pdo->prepare("UPDATE cpms_project_issues SET status = :status, updated_at = NOW() WHERE id = :id");
    $up->bindValue(':status', $status, PDO::PARAM_STR);
    $up->bindValue(':id', $issueId, PDO::PARAM_INT);
    $up->execute();
    if ($up->rowCount() <= 0) {
        flash_set('error', 'ISSUE_UPDATE_LOADED=Y 이슈 상태 변경 실패: 대상 이슈를 찾을 수 없습니다.');
    } else {
        flash_set('success', 'ISSUE_UPDATE_LOADED=Y 이슈 상태가 변경되었습니다.');
    }  
} catch (Exception $e) {
    flash_set('error', 'ISSUE_UPDATE_LOADED=Y 이슈 상태 변경 실패: ' . $e->getMessage());
}

header('Location: ' . $redirect);
exit;