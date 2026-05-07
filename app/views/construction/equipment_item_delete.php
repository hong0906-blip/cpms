<?php
/**
 * 공사 > 장비 > 입력
 * - 등록장비 삭제
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
$equipmentId = isset($_POST['equipment_id']) ? (int)$_POST['equipment_id'] : 0;
$equipTab = isset($_POST['equip_tab']) ? trim((string)$_POST['equip_tab']) : 'input';
$ym = isset($_POST['ym']) ? trim((string)$_POST['ym']) : date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');
$redirect = '?r=공사&pid=' . $projectId . '&tab=equipment&equip_tab=' . urlencode($equipTab) . '&ym=' . urlencode($ym);

if ($projectId <= 0 || $equipmentId <= 0) { flash_set('error', '삭제 대상이 올바르지 않습니다.'); header('Location: ' . $redirect); exit; }
$pdo = Db::pdo();
if (!$pdo) { flash_set('error', 'DB 연결 실패'); header('Location: ' . $redirect); exit; }

try {
    $st = $pdo->prepare("UPDATE cpms_equipment_items SET is_deleted = 1, updated_at = :u WHERE id = :id AND project_id = :pid");
    $st->bindValue(':u', date('Y-m-d H:i:s'));
    $st->bindValue(':id', $equipmentId, PDO::PARAM_INT);
    $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $st->execute();
    flash_set('success', '장비를 삭제했습니다.');
} catch (Exception $e) {
    flash_set('error', '삭제 실패: ' . $e->getMessage());
}

header('Location: ' . $redirect);
exit;