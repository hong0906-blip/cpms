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

function cpms_unit_price_delete_column_exists($pdo, $column) {
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM cpms_project_unit_prices LIKE :col");
        $st->bindValue(':col', (string)$column);
        $st->execute();
        return is_array($st->fetch(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        return false;
    }
}

try {
    if (!cpms_unit_price_delete_column_exists($pdo, 'is_active') || !cpms_unit_price_delete_column_exists($pdo, 'is_current')) {
        flash_set('error', '내역서 이력 보존 컬럼이 없습니다. db_setup_estimate_versions 페이지에서 DB 생성/확인을 먼저 실행해주세요.');
        header('Location: ?r=project/detail&id=' . $projectId);
        exit;
    }

    $st = $pdo->prepare("UPDATE cpms_project_unit_prices SET is_active = 0, is_current = 0, updated_at = NOW() WHERE id = :id AND project_id = :pid");
    $st->bindValue(':id', $id, PDO::PARAM_INT);
    $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $st->execute();

    flash_set('success', '내역서 항목을 현재 적용 내역에서 제외했습니다. 기존 이력은 보존됩니다.');
    header('Location: ?r=project/detail&id=' . $projectId);
    exit;

} catch (Exception $e) {
    flash_set('error', '삭제 실패: ' . $e->getMessage());
    header('Location: ?r=project/detail&id=' . $projectId);
    exit;
}
