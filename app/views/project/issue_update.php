<?php
/**
 * C:\www\cpms\app\views\dashboard\issue_update.php
 * - 이슈 상태 변경(POST)
 * - 권한: (등록자) 또는 (임원)
 * - 상태: 처리중 / 처리완료
 *
 * PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }

$token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
if (!csrf_check($token)) { flash_set('error','보안 토큰이 유효하지 않습니다.'); header('Location: ?r=대시보드'); exit; }

$issueId = isset($_POST['issue_id']) ? (int)$_POST['issue_id'] : 0;
$status  = isset($_POST['status']) ? trim((string)$_POST['status']) : '';

$allowedStatus = array('처리중', '처리완료');
if ($issueId <= 0) { flash_set('error','이슈 정보가 올바르지 않습니다.'); header('Location: ?r=대시보드'); exit; }
if (!in_array($status, $allowedStatus, true)) { flash_set('error','상태 값이 올바르지 않습니다.'); header('Location: ?r=대시보드'); exit; }

$pdo = Db::pdo();
if (!$pdo) { flash_set('error','DB 연결 실패'); header('Location: ?r=대시보드'); exit; }

$userEmail = (string)Auth::userEmail();
$userRole  = (string)Auth::userRole();

try {
    $st = $pdo->prepare("SELECT id, created_by_email FROM cpms_project_issues WHERE id = :id LIMIT 1");
    $st->bindValue(':id', $issueId, PDO::PARAM_INT);
    $st->execute();
    $row = $st->fetch();

    if (!is_array($row)) {
        flash_set('error','이슈를 찾을 수 없습니다.');
        header('Location: ?r=대시보드');
        exit;
    }

    $ownerEmail = isset($row['created_by_email']) ? (string)$row['created_by_email'] : '';

    $can = false;
    if ($userRole === 'executive') $can = true;
    if ($ownerEmail !== '' && $userEmail !== '' && $ownerEmail === $userEmail) $can = true;

    if (!$can) { http_response_code(403); echo '403 Forbidden'; exit; }

    $up = $pdo->prepare("UPDATE cpms_project_issues SET status = :st WHERE id = :id");
    $up->bindValue(':st', $status);
    $up->bindValue(':id', $issueId, PDO::PARAM_INT);
    $up->execute();

    flash_set('success','이슈 상태가 변경되었습니다.');
    header('Location: ?r=대시보드');
    exit;

} catch (Exception $e) {
    flash_set('error','상태 변경 실패: '.$e->getMessage());
    header('Location: ?r=대시보드');
    exit;
}