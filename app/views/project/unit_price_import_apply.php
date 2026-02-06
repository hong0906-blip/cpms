<?php
/**
 * C:\www\cpms\app\views\project\unit_price_import_apply.php
 * - 미리보기에서 “적용(저장)” 눌렀을 때 DB 저장
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
$importToken = isset($_POST['token']) ? (string)$_POST['token'] : '';

if ($projectId <= 0 || $importToken === '') {
    flash_set('error', '적용 파라미터가 잘못되었습니다.');
    header('Location: ?r=공무');
    exit;
}

if (!isset($_SESSION['unit_price_import']) || !is_array($_SESSION['unit_price_import']) || !isset($_SESSION['unit_price_import'][$importToken])) {
    flash_set('error', '미리보기 데이터가 만료되었거나 없습니다. 다시 업로드하세요.');
    header('Location: ?r=project/detail&id=' . $projectId);
    exit;
}

$pack = $_SESSION['unit_price_import'][$importToken];
if (!isset($pack['project_id']) || (int)$pack['project_id'] !== $projectId) {
    flash_set('error', '미리보기 데이터가 프로젝트와 일치하지 않습니다.');
    header('Location: ?r=project/detail&id=' . $projectId);
    exit;
}

$rows = isset($pack['rows']) && is_array($pack['rows']) ? $pack['rows'] : array();
if (count($rows) === 0) {
    flash_set('error', '저장할 데이터가 없습니다.');
    header('Location: ?r=project/detail&id=' . $projectId);
    exit;
}

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error', 'DB 연결 실패');
    header('Location: ?r=project/detail&id=' . $projectId);
    exit;
}

try {
    $pdo->beginTransaction();

    $st = $pdo->prepare("INSERT INTO cpms_project_unit_prices(project_id, item_name, spec, unit, qty, unit_price, remark)
                         VALUES(:pid, :item, :spec, :unit, :qty, :up, :rm)");

    $count = 0;
    foreach ($rows as $r) {
        $item = isset($r['item_name']) ? (string)$r['item_name'] : '';
        if (trim($item) === '') continue;
        $spec = isset($r['spec']) ? trim((string)$r['spec']) : '';
        $unit = isset($r['unit']) ? trim((string)$r['unit']) : '';
        if ($spec === '' || $unit === '') continue;

        $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $st->bindValue(':item', $item);
        $st->bindValue(':spec', $spec);
        $st->bindValue(':unit', $unit);
        $st->bindValue(':qty', isset($r['qty']) ? $r['qty'] : null);
        $st->bindValue(':up', isset($r['unit_price']) ? $r['unit_price'] : null);
        $st->bindValue(':rm', isset($r['remark']) ? (string)$r['remark'] : '');
        $st->execute();
        $count++;
    }

    $pdo->commit();

    // 세션 정리
    unset($_SESSION['unit_price_import'][$importToken]);

    flash_set('success', '엑셀 단가표가 저장되었습니다. (저장된 행: ' . $count . ')');
    header('Location: ?r=project/detail&id=' . $projectId);
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash_set('error', '저장 실패: ' . $e->getMessage());
    header('Location: ?r=project/detail&id=' . $projectId);
    exit;
}