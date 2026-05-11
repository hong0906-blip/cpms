<?php
/**
 * C:\www\cpms\app\views\project\issue_update.php
 * - 이슈 상태 변경 액션(POST)
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

$redirect = isset($_POST['redirect']) && trim((string)$_POST['redirect']) !== '' ? trim((string)$_POST['redirect']) : '?r=대시보드&dv=executive';

if (!Auth::check()) { header('Location: ?r=login'); exit; }

// 이슈 상태 변경 Bad Request 방지: GET 진입 시에도 화면 에러 대신 플래시 + 리다이렉트
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash_set('error', '이슈 상태 변경 실패: 잘못된 요청 방식입니다.');
    header('Location: ' . $redirect);
    exit;
}

$token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
if (!csrf_check($token)) {
    flash_set('error', '이슈 상태 변경 실패: 보안 토큰 오류입니다.');
    header('Location: ' . $redirect);
    exit;
}

$issueId = 0;
if (isset($_POST['issue_id'])) $issueId = (int)$_POST['issue_id'];
if ($issueId <= 0 && isset($_POST['id'])) $issueId = (int)$_POST['id'];

$statusRaw = isset($_POST['status']) ? trim((string)$_POST['status']) : '';
if ($issueId <= 0 || $statusRaw === '') {
    flash_set('error', '이슈 상태 변경 실패: 필수값이 누락되었습니다.');
    header('Location: ' . $redirect);
    exit;
}

// 이슈 상태값 한글 허용 + 영문 호환 입력 정규화
$statusMap = array(
    'pending' => '접수',
    '접수' => '접수',
    'in_progress' => '처리중',
    '처리중' => '처리중',
    'done' => '처리완료',
    '처리완료' => '처리완료'
);
if (!isset($statusMap[$statusRaw])) {
    flash_set('error', '이슈 상태 변경 실패: 허용되지 않은 상태값입니다.');
    header('Location: ' . $redirect);
    exit;
}
$status = $statusMap[$statusRaw];

$role = (string)Auth::userRole();
$can = false;
if ($role === 'executive' || $role === 'master') $can = true;
if (method_exists('App\Core\Auth', 'canManageConstruction') && Auth::canManageConstruction()) $can = true;
if (!$can) {
    flash_set('error', '이슈 상태 변경 실패: 권한이 없습니다.');
    header('Location: ' . $redirect);
    exit;
}

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error', '이슈 상태 변경 실패: DB 연결에 실패했습니다.');
    header('Location: ' . $redirect);
    exit;
}

try {
    $chk = $pdo->query("SHOW TABLES LIKE 'cpms_project_issues'");
    if (!$chk || !$chk->fetch()) {
        flash_set('error', '이슈 상태 변경 실패: 이슈 테이블이 존재하지 않습니다.');
        header('Location: ' . $redirect);
        exit;
    }

    $needed = array('id','status','updated_at');
    foreach ($needed as $col) {
        $q = $pdo->query("SHOW COLUMNS FROM cpms_project_issues LIKE '".$col."'");
        if (!$q || !$q->fetch()) {
            flash_set('error', '이슈 상태 변경 실패: 필수 컬럼('.$col.')이 없습니다.');
            header('Location: ' . $redirect);
            exit;
        }
    }

    $up = $pdo->prepare("UPDATE cpms_project_issues SET status = :status, updated_at = NOW() WHERE id = :id");
    $up->bindValue(':status', $status, PDO::PARAM_STR);
    $up->bindValue(':id', $issueId, PDO::PARAM_INT);
    $up->execute();

    if ($up->rowCount() <= 0) {
        flash_set('error', '이슈 상태 변경 실패: 대상 이슈를 찾을 수 없습니다.');
        header('Location: ' . $redirect);
        exit;
    }

    flash_set('success', '이슈 상태가 변경되었습니다.');    
} catch (Exception $e) {
    flash_set('error', '이슈 상태 변경 실패: ' . $e->getMessage());
}

header('Location: ' . $redirect);
exit;