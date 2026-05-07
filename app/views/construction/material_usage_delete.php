<?php
/**
 * 자재구입비(장비 방식 복제)
 * 공사 > 자재구입비 > 입력
 * - 특정 자재구입비/사용일자 삭제
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }
$role = Auth::userRole();
$dept = Auth::userDepartment();
if (!Auth::canManageConstruction()) { http_response_code(403); echo '403 Forbidden'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) { flash_set('error', '보안 토큰 오류'); header('Location: ?r=공사'); exit; }

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$materialId = isset($_POST['material_id']) ? (int)$_POST['material_id'] : 0;
$useDate = isset($_POST['use_date']) ? trim((string)$_POST['use_date']) : '';
$materialsTab = isset($_POST['materials_tab']) ? trim((string)$_POST['materials_tab']) : 'input';
$ym = isset($_POST['ym']) ? trim((string)$_POST['ym']) : date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');
$redirect = '?r=공사&pid=' . $projectId . '&tab=materials&materials_tab=' . urlencode($materialsTab) . '&ym=' . urlencode($ym);

if ($projectId <= 0 || $materialId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $useDate)) {
    flash_set('error', '삭제 대상이 올바르지 않습니다.');
    header('Location: ' . $redirect);
    exit;
}

$pdo = Db::pdo();
if (!$pdo) { flash_set('error', 'DB 연결 실패'); header('Location: ' . $redirect); exit; }

try {
    $st = $pdo->prepare("DELETE FROM cpms_material_usage WHERE project_id = :pid AND material_id = :eid AND use_date = :d");
    $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $st->bindValue(':eid', $materialId, PDO::PARAM_INT);
    $st->bindValue(':d', $useDate);
    $st->execute();
    flash_set('success', '사용일자를 삭제했습니다.');
} catch (Exception $e) {
    flash_set('error', '삭제 실패: ' . $e->getMessage());
}

header('Location: ' . $redirect);
exit;