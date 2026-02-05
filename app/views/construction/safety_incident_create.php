<?php
/**
 * C:\www\cpms\app\views\construction\safety_incident_create.php
 * - 공사: 안전사고 등록(POST)
 *
 * 요구사항:
 * - 공사 화면의 "안전사고" 버튼으로 등록
 * - 안전팀 + 임원이 안전사고 탭/대시보드에서 확인
 *
 * 권한:
 * - 로그인된 사용자면 등록 가능(공사팀 중심)
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
$title = isset($_POST['title']) ? trim((string)$_POST['title']) : '';
$occurredAt = isset($_POST['occurred_at']) ? trim((string)$_POST['occurred_at']) : '';
$desc = isset($_POST['description']) ? trim((string)$_POST['description']) : '';

if ($projectId <= 0) { flash_set('error','프로젝트 정보가 올바르지 않습니다.'); header('Location: ?r=공사'); exit; }
if ($title === '') { flash_set('error','제목을 입력해주세요.'); header('Location: ?r=공사&pid='.$projectId.'&tab=safety'); exit; }
if (mb_strlen($title,'UTF-8') > 255) { $title = mb_substr($title,0,255,'UTF-8'); }

// datetime-local → "YYYY-MM-DD HH:MM:SS" 형태로 저장(초는 00)
$occurredSql = null;
if ($occurredAt !== '') {
    // 예: 2026-02-02T10:30
    $occurredAt = str_replace('T', ' ', $occurredAt);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $occurredAt)) {
        $occurredSql = $occurredAt . ':00';
    }
}

$pdo = Db::pdo();
if (!$pdo) { flash_set('error','DB 연결 실패'); header('Location: ?r=공사&pid='.$projectId.'&tab=safety'); exit; }

$createdByName = (string)Auth::userName();
if ($createdByName === '') $createdByName = '사용자';
$createdByEmail = (string)Auth::userEmail();

try {
    $st = $pdo->prepare("INSERT INTO cpms_safety_incidents(project_id, title, description, occurred_at, created_by_name, created_by_email, status)
                         VALUES(:pid, :tt, :ds, :oc, :nm, :em, '접수')");
    $st->bindValue(':pid', $projectId, \PDO::PARAM_INT);
    $st->bindValue(':tt', $title);
    $st->bindValue(':ds', $desc !== '' ? $desc : null, $desc !== '' ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
    $st->bindValue(':oc', $occurredSql !== null ? $occurredSql : null, $occurredSql !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
    $st->bindValue(':nm', $createdByName);
    $st->bindValue(':em', $createdByEmail);
    $st->execute();

    flash_set('success','안전사고가 등록되었습니다.');
    header('Location: ?r=공사&pid='.$projectId.'&tab=safety');
    exit;

} catch (Exception $e) {
    flash_set('error','안전사고 등록 실패: '.$e->getMessage());
    header('Location: ?r=공사&pid='.$projectId.'&tab=safety');
    exit;
}
