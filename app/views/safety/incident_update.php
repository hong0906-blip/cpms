<?php
/**
 * C:\www\cpms\app\views\safety\incident_update.php
 * - 안전: 안전사고 상태 변경(POST)
 *
 * 권한:
 * - 안전부서(안전) 또는 임원(executive)
 *
 * PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }

$role = Auth::userRole();
$dept = Auth::userDepartment();

if (!($role === 'executive' || $dept === '안전')) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }

$token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
if (!csrf_check($token)) {
    flash_set('error','보안 토큰이 유효하지 않습니다.');
    header('Location: ?r=안전/보건');
    exit;
}

$id = isset($_POST['incident_id']) ? (int)$_POST['incident_id'] : 0;
$status = isset($_POST['status']) ? trim((string)$_POST['status']) : '';

$allowed = array('접수', '처리중', '처리완료');
if ($id <= 0 || !in_array($status, $allowed, true)) {
    flash_set('error','요청 값이 올바르지 않습니다.');
    header('Location: ?r=안전/보건');
    exit;
}

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error','DB 연결 실패');
    header('Location: ?r=안전/보건');
    exit;
}

try {
    $st = $pdo->prepare("UPDATE cpms_safety_incidents SET status = :st WHERE id = :id");
    $st->bindValue(':st', $status);
    $st->bindValue(':id', $id, \PDO::PARAM_INT);
    $st->execute();

    flash_set('success','상태가 변경되었습니다.');
    header('Location: ?r=안전/보건');
    exit;

} catch (Exception $e) {
    flash_set('error','상태 변경 실패: '.$e->getMessage());
    header('Location: ?r=안전/보건');
    exit;
}
