<?php
/**
 * C:\www\cpms\app\views\ajax\workforce_search.php
 * - 인력관리 이름 검색 자동완성 API
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

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
if ($q === '') ResponseHelper::ok(array('items' => array()));

$repo = new WorkerRepository(Db::pdo());
$items = $repo->searchByName($q, 20);

ResponseHelper::ok(array('items' => $items));
