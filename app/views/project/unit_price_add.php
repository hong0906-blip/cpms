<?php
/**
 * C:\www\cpms\app\views\project\unit_price_add.php
 * - 단가표 수기 추가(행 1개)
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
$item_name = isset($_POST['item_name']) ? trim((string)$_POST['item_name']) : '';
$spec = isset($_POST['spec']) ? trim((string)$_POST['spec']) : '';
$unit = isset($_POST['unit']) ? trim((string)$_POST['unit']) : '';
$qty = isset($_POST['qty']) ? trim((string)$_POST['qty']) : '';
$unit_price = isset($_POST['unit_price']) ? trim((string)$_POST['unit_price']) : '';
$remark = isset($_POST['remark']) ? trim((string)$_POST['remark']) : '';

if ($projectId <= 0) {
    flash_set('error', '잘못된 프로젝트 ID');
    header('Location: ?r=공무');
    exit;
}
if ($item_name === '') {
    flash_set('error', '품명은 필수입니다.');
    header('Location: ?r=project/detail&id=' . $projectId);
    exit;
}

// 숫자 정리
$qtyVal = null;
if ($qty !== '') {
    $q = str_replace(array(',', ' '), '', $qty);
    $q = preg_replace('/[^0-9\.\-]/', '', $q);
    if ($q !== '') $qtyVal = (float)$q;
}

$unitPriceVal = null;
if ($unit_price !== '') {
    $p = str_replace(array(',', ' '), '', $unit_price);
    $p = preg_replace('/[^0-9\.\-]/', '', $p);
    if ($p !== '') $unitPriceVal = (float)$p;
}

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error', 'DB 연결 실패');
    header('Location: ?r=project/detail&id=' . $projectId);
    exit;
}

try {
    $st = $pdo->prepare("INSERT INTO cpms_project_unit_prices(project_id, item_name, spec, unit, qty, unit_price, remark)
                         VALUES(:pid, :item, :spec, :unit, :qty, :up, :rm)");
    $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $st->bindValue(':item', $item_name);
    $st->bindValue(':spec', $spec);
    $st->bindValue(':unit', $unit);
    $st->bindValue(':qty', $qtyVal);
    $st->bindValue(':up', $unitPriceVal);
    $st->bindValue(':rm', $remark);
    $st->execute();

    flash_set('success', '단가표가 추가되었습니다.');
    header('Location: ?r=project/detail&id=' . $projectId);
    exit;

} catch (Exception $e) {
    flash_set('error', '추가 실패: ' . $e->getMessage());
    header('Location: ?r=project/detail&id=' . $projectId);
    exit;
}