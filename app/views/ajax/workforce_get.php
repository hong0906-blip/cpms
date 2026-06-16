<?php
/**
 * C:\www\cpms\app\views\ajax\workforce_get.php
 * - worker_id로 인력 상세 조회
 * - 공사 섹션 기본 응답에는 민감정보를 포함하지 않음
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../services/WorkerRepository.php';
require_once __DIR__ . '/../../services/ResponseHelper.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) ResponseHelper::fail('로그인이 필요합니다.', 401);
if (!(Auth::canManageConstruction() || Auth::canManageEmployees() || Auth::isMaster())) {
    ResponseHelper::fail('권한이 없습니다.', 403);
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) ResponseHelper::fail('인력 정보가 올바르지 않습니다.', 400);

$includeSensitive = (Auth::isMaster() || Auth::canManageEmployees());
$repo = new WorkerRepository(Db::pdo());
$worker = $repo->getById($id, $includeSensitive);
if (!$worker) ResponseHelper::fail('인력 정보를 찾을 수 없습니다.', 404);

if (!$includeSensitive) {
    unset($worker['resident_no_plain']);
    unset($worker['bank_account_plain']);
    unset($worker['resident_no_masked']);
    unset($worker['bank_account_masked']);
}

ResponseHelper::ok(array('worker' => $worker));
