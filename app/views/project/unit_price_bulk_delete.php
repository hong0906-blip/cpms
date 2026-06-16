<?php
/**
 * C:\www\cpms\app\views\project\unit_price_bulk_delete.php
 * - 단가표 선택/전체 삭제
 *
 * PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }

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
$mode = isset($_POST['mode']) ? trim((string)$_POST['mode']) : 'selected';
$redirect = ($projectId > 0) ? ('?r=project/detail&id=' . $projectId) : '?r=공무';

if ($projectId <= 0) {
    flash_set('error', '삭제 파라미터가 잘못되었습니다.');
    header('Location: ' . $redirect);
    exit;
}

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error', 'DB 연결 실패');
    header('Location: ' . $redirect);
    exit;
}

function cpms_unit_price_bulk_column_exists($pdo, $column) {
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
    if ($mode === 'all') {
        $sql = "DELETE FROM cpms_project_unit_prices WHERE project_id = :pid";
        if (cpms_unit_price_bulk_column_exists($pdo, 'is_active')) {
            $sql .= " AND (is_active = 1 OR is_active IS NULL)";
        }
        if (cpms_unit_price_bulk_column_exists($pdo, 'is_current')) {
            $sql .= " AND (is_current = 1 OR is_current IS NULL)";
        }
        $st = $pdo->prepare($sql);
        $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $st->execute();
        flash_set('success', '단가 내역표 전체를 삭제했습니다. (삭제 ' . (int)$st->rowCount() . '건)');
        header('Location: ' . $redirect);
        exit;
    }

    $idsRaw = isset($_POST['ids']) && is_array($_POST['ids']) ? $_POST['ids'] : array();
    $ids = array();
    foreach ($idsRaw as $idRaw) {
        $id = (int)$idRaw;
        if ($id > 0) $ids[$id] = $id;
    }
    $ids = array_values($ids);

    if (count($ids) === 0) {
        flash_set('error', '선택된 단가 내역이 없습니다.');
        header('Location: ' . $redirect);
        exit;
    }

    $holders = array();
    foreach ($ids as $idx => $id) {
        $holders[] = ':id' . $idx;
    }

    $sql = "DELETE FROM cpms_project_unit_prices WHERE project_id = :pid AND id IN (" . implode(',', $holders) . ")";
    $st = $pdo->prepare($sql);
    $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
    foreach ($ids as $idx => $id) {
        $st->bindValue(':id' . $idx, (int)$id, PDO::PARAM_INT);
    }
    $st->execute();

    flash_set('success', '선택한 단가 내역을 삭제했습니다. (삭제 ' . (int)$st->rowCount() . '건)');
    header('Location: ' . $redirect);
    exit;
} catch (Exception $e) {
    flash_set('error', '단가 내역 삭제 실패: ' . $e->getMessage());
    header('Location: ' . $redirect);
    exit;
}
