<?php
/**
 * 공무 > 프로젝트 상세 > 단가표 안전항목 토글 저장
 */
require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }
$role = Auth::userRole();
$dept = Auth::userDepartment();
$allowed = ($role === 'executive' || $dept === '공무' || $dept === '관리' || $dept === '관리부');
if (!$allowed) { http_response_code(403); echo '403 Forbidden'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) { flash_set('error', '보안 토큰 오류'); header('Location: ?r=공무'); exit; }

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($projectId <= 0 || $id <= 0) { flash_set('error', '파라미터 오류'); header('Location: ?r=공무'); exit; }
$pdo = Db::pdo();
if (!$pdo) { flash_set('error', 'DB 연결 실패'); header('Location: ?r=project/detail&id=' . $projectId); exit; }

try {
    $pdo->exec("UPDATE cpms_project_unit_prices SET is_safety = CASE WHEN is_safety=1 THEN 0 ELSE 1 END WHERE id=" . (int)$id . " AND project_id=" . (int)$projectId);
    flash_set('success', '안전항목 분류를 변경했습니다.');
} catch (Exception $e) {
    flash_set('error', '저장 실패: ' . $e->getMessage());
}
header('Location: ?r=project/detail&id=' . $projectId);
exit;