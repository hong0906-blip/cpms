<?php
/**
 * - 공사: 노무비 인원작성 인원 삭제
 * - 프로젝트별 인원 목록에서 삭제 표시
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/tabs/partials/labor_data_loader.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }

$role = Auth::userRole();
$dept = Auth::userDepartment();

if (!($role === 'executive' || $dept === '공사')) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }

$token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
if (!csrf_check($token)) {
    flash_set('error','보안 토큰이 유효하지 않습니다.');
    header('Location: ?r=공사');
    exit;
}

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$workerId = isset($_POST['worker_id']) ? (int)$_POST['worker_id'] : 0;
$month = isset($_POST['month']) ? trim((string)$_POST['month']) : '';
$laborTab = isset($_POST['labor_tab']) ? trim((string)$_POST['labor_tab']) : 'workers';
if ($laborTab === '') $laborTab = 'workers';

$redirect = '?r=공사&pid=' . $projectId . '&tab=labor&labor_tab=' . urlencode($laborTab);
if ($month !== '') {
    $redirect .= '&month=' . urlencode($month);
}

if ($projectId <= 0 || $workerId <= 0) {
    flash_set('error','삭제 대상이 올바르지 않습니다.');
    header('Location: ' . $redirect);
    exit;
}

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error','DB 연결 실패');
    header('Location: ' . $redirect);
    exit;
}

try {
    if (!cpms_ensure_project_labor_workers_table($pdo)) {
        flash_set('error','인원 목록 테이블을 확인할 수 없습니다.');
        header('Location: ' . $redirect);
        exit;
    }

    $now = date('Y-m-d H:i:s');
    $st = $pdo->prepare("UPDATE cpms_project_labor_workers
                         SET is_deleted = 1,
                             updated_at = :now
                         WHERE id = :id AND project_id = :pid");
    $st->bindValue(':now', $now);
    $st->bindValue(':id', $workerId, PDO::PARAM_INT);
    $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $st->execute();

    flash_set('success','인원을 삭제했습니다.');
    header('Location: ' . $redirect);
    exit;

} catch (Exception $e) {
    flash_set('error','삭제 실패: ' . $e->getMessage());
    header('Location: ' . $redirect);
    exit;
}