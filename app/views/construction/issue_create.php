<?php
/**
 * C:\www\cpms\app\views\construction\issue_create.php
 * - 공사: 이슈 등록 저장(POST)
 * - project/issue_create 로직을 그대로 사용하되, 리다이렉트를 공사 화면으로
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

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$reason = isset($_POST['reason']) ? trim((string)$_POST['reason']) : '';

if ($projectId <= 0) { flash_set('error','프로젝트 정보가 올바르지 않습니다.'); header('Location: ?r=공사'); exit; }
if ($reason === '') { flash_set('error','사유를 입력해주세요.'); header('Location: ?r=공사&pid='.$projectId.'&tab=issues'); exit; }
if (mb_strlen($reason,'UTF-8') > 255) { $reason = mb_substr($reason,0,255,'UTF-8'); }

$pdo = Db::pdo();
if (!$pdo) { flash_set('error','DB 연결 실패'); header('Location: ?r=공사&pid='.$projectId.'&tab=issues'); exit; }

$createdByName = (string)Auth::userName();
if ($createdByName === '') $createdByName = '사용자';
$createdByEmail = (string)Auth::userEmail();

try {
    $st = $pdo->prepare("INSERT INTO cpms_project_issues(project_id, reason, created_by_name, created_by_email, status)
                         VALUES(:pid, :rs, :nm, :em, '처리중')");
    $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $st->bindValue(':rs', $reason);
    $st->bindValue(':nm', $createdByName);
    $st->bindValue(':em', $createdByEmail);
    $st->execute();

    flash_set('success','이슈가 등록되었습니다.');
    header('Location: ?r=공사&pid='.$projectId.'&tab=issues');
    exit;

} catch (Exception $e) {
    flash_set('error','이슈 등록 실패: '.$e->getMessage());
    header('Location: ?r=공사&pid='.$projectId.'&tab=issues');
    exit;
}
