<?php
/**
 * C:\www\cpms\app\views\admin\workforce_delete.php
 * - 관리 > 인력관리 soft delete 처리
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../services/WorkerRepository.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }
if (!(Auth::isMaster() || Auth::canManageEmployees())) { http_response_code(403); echo '403 Forbidden'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }
csrf_validate();

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$ids = array();
if (isset($_POST['ids']) && is_array($_POST['ids'])) {
    foreach ($_POST['ids'] as $rawId) {
        $oneId = (int)$rawId;
        if ($oneId > 0) $ids[$oneId] = $oneId;
    }
}
if ($id > 0) $ids[$id] = $id;
$pdo = Db::pdo();
$repo = new WorkerRepository($pdo);
$user = Auth::user();
$userId = (is_array($user) && isset($user['id'])) ? (int)$user['id'] : 0;

if (count($ids) <= 0) {
    flash_set('danger', '삭제 대상이 올바르지 않습니다.');
    header('Location: ?r=관리&tab=workforce');
    exit;
}

$deleted = 0;
foreach ($ids as $oneId) {
    if ($repo->softDelete($oneId, $userId)) {
        $deleted++;
        error_log('[workforce_delete] worker_id=' . (int)$oneId . ' user=' . (string)Auth::userEmail());
    }
}

if ($deleted > 0) flash_set('success', '인력을 삭제 처리했습니다. (' . (int)$deleted . '건)');
else flash_set('danger', '삭제 처리에 실패했습니다.');

header('Location: ?r=관리&tab=workforce');
exit;
