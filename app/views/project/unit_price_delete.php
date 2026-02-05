<?php
/**
 * C:\www\cpms\app\views\project\unit_price_delete.php
 * - 단가표 행 삭제
 *
 * PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }

// 권한: 임원 또는 공무/관리
$role = Auth::userRole();
$dept = Auth::userDepartment();
$allowed = ($role === 'executive' || $dept === '공무' || $dept === '관리' || $dept === '관리부');
if (!$allowed) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

$token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
if (!csrf_check($token)) {
    flash_set('error', '보안 토큰이 유효하지 않습니다.');
    header('Location: ?r=공무');
    exit;
}

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($projectId <= 0 || $id <= 0) {
    flash_set('error', '삭제 파라미터가 잘못되었습니다.');
    header('Location: ?r=공무');
    exit;
}

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error', 'DB 연결 실패');
    header('Location: ?r=project/detail&id=' . $projectId);
    exit;
}

try {
    $st = $pdo->prepare("DELETE FROM cpms_project_unit_prices WHERE id = :id AND project_id = :pid");
    $st->bindValue(':id', $id, PDO::PARAM_INT);
    $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $st->execute();

    flash_set('success', '삭제되었습니다.');
    header('Location: ?r=project/detail&id=' . $projectId);
    exit;

} catch (Exception $e) {
    flash_set('error', '삭제 실패: ' . $e->getMessage());
    header('Location: ?r=project/detail&id=' . $projectId);
    exit;
}