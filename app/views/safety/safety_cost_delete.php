<?php
/**
 * Safety cost soft delete action.
 * PHP 5.6 compatible.
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/safety_cost_helper.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    flash_set('error', '보안 토큰이 유효하지 않습니다.');
    header('Location: ?r=safety_home#safety-cost-section');
    exit;
}

$pdo = Db::pdo();
$id = isset($_POST['safety_cost_id']) ? trim((string)$_POST['safety_cost_id']) : '';
$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$redirect = '?r=safety_home&safety_pid=' . (int)$projectId . '#safety-cost-section';

if ($id === '') {
    flash_set('error', '삭제할 안전관리비 사용내역이 올바르지 않습니다.');
    header('Location: ' . $redirect);
    exit;
}

$store = cpms_safety_cost_read_store();
if (!isset($store['items']) || !is_array($store['items'])) $store['items'] = array();
$found = false;
foreach ($store['items'] as $idx => $row) {
    if (!is_array($row) || !isset($row['id']) || (string)$row['id'] !== $id) continue;
    $rowProjectId = isset($row['project_id']) ? (int)$row['project_id'] : 0;
    if ($rowProjectId > 0) $projectId = $rowProjectId;
    $redirect = '?r=safety_home&safety_pid=' . (int)$projectId . '#safety-cost-section';
    if (!cpms_safety_cost_user_can_manage_project($pdo, $projectId)) {
        http_response_code(403);
        echo '403 Forbidden';
        exit;
    }
    $store['items'][$idx]['status'] = 'deleted';
    $store['items'][$idx]['is_deleted'] = 1;
    $store['items'][$idx]['deleted_at'] = date('Y-m-d H:i:s');
    $store['items'][$idx]['deleted_by'] = cpms_safety_cost_user_id();
    $store['items'][$idx]['deleted_by_name'] = (string)Auth::userName();
    $found = true;
    break;
}

if (!$found) {
    flash_set('error', '삭제할 안전관리비 사용내역을 찾을 수 없습니다.');
    header('Location: ' . $redirect);
    exit;
}

if (!cpms_safety_cost_write_store($store)) {
    flash_set('error', '안전관리비 사용내역 삭제 처리에 실패했습니다.');
    header('Location: ' . $redirect);
    exit;
}

flash_set('success', '안전관리비 사용내역을 삭제 처리했습니다.');
header('Location: ' . $redirect);
exit;

