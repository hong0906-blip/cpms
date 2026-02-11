<?php
/**
 * 공무 > 단가표 미리보기 적용 저장
 * - 노무/자재/안전 단가 컬럼 포함 저장
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }
$role = Auth::userRole();
$dept = Auth::userDepartment();
$allowed = ($role === 'executive' || $dept === '공무' || $dept === '관리' || $dept === '관리부');
if (!$allowed) { http_response_code(403); echo '403 Forbidden'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }

$token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
if (!csrf_check($token)) { flash_set('error', '보안 토큰이 유효하지 않습니다.'); header('Location: ?r=공무'); exit; }

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$importToken = isset($_POST['token']) ? (string)$_POST['token'] : '';
if ($projectId <= 0 || $importToken === '') { flash_set('error', '적용 파라미터가 잘못되었습니다.'); header('Location: ?r=공무'); exit; }

if (!isset($_SESSION['unit_price_import'][$importToken])) { flash_set('error', '미리보기 데이터가 만료되었습니다.'); header('Location: ?r=project/detail&id=' . $projectId); exit; }
$pack = $_SESSION['unit_price_import'][$importToken];
$rows = isset($pack['rows']) && is_array($pack['rows']) ? $pack['rows'] : array();
if (count($rows) === 0) { flash_set('error', '저장할 데이터가 없습니다.'); header('Location: ?r=project/detail&id=' . $projectId); exit; }

$pdo = Db::pdo();
if (!$pdo) { flash_set('error', 'DB 연결 실패'); header('Location: ?r=project/detail&id=' . $projectId); exit; }

try {
    $pdo->beginTransaction();
    $st = $pdo->prepare("INSERT INTO cpms_project_unit_prices(project_id, item_name, spec, unit, qty, unit_price, labor_unit_price, material_unit_price, safety_unit_price, is_safety, remark)
                         VALUES(:pid, :item, :spec, :unit, :qty, :up, :lup, :mup, :sup, :is_safety, :rm)");

    $count = 0;
    foreach ($rows as $r) {
        $item = isset($r['item_name']) ? trim((string)$r['item_name']) : '';
        if ($item === '') continue;
        $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $st->bindValue(':item', $item);
        $st->bindValue(':spec', isset($r['spec']) ? trim((string)$r['spec']) : '');
        $st->bindValue(':unit', isset($r['unit']) ? trim((string)$r['unit']) : '');
        $st->bindValue(':qty', isset($r['qty']) ? $r['qty'] : null);
        $st->bindValue(':up', isset($r['unit_price']) ? $r['unit_price'] : null);
        $st->bindValue(':lup', isset($r['labor_unit_price']) ? $r['labor_unit_price'] : null);
        $st->bindValue(':mup', isset($r['material_unit_price']) ? $r['material_unit_price'] : null);
        $st->bindValue(':sup', isset($r['safety_unit_price']) ? $r['safety_unit_price'] : null);
        $st->bindValue(':is_safety', isset($r['is_safety']) ? (int)$r['is_safety'] : 0, PDO::PARAM_INT);
        $st->bindValue(':rm', isset($r['remark']) ? (string)$r['remark'] : '');
        $st->execute();
        $count++;
    }

    $pdo->commit();
    unset($_SESSION['unit_price_import'][$importToken]);
    flash_set('success', '엑셀 단가표가 저장되었습니다. (저장된 행: ' . $count . ')');
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash_set('error', '저장 실패: ' . $e->getMessage());
}

header('Location: ?r=project/detail&id=' . $projectId);
exit;