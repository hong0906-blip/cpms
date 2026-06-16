<?php
/**
 * C:\www\cpms\app\views\ajax\workforce_match_shiftee.php
 * - 시프티에서 넘어온 이름/연락처/주민번호 기준 인력관리 매칭 API
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

$name = isset($_REQUEST['name']) ? trim((string)$_REQUEST['name']) : '';
$phone = isset($_REQUEST['phone']) ? trim((string)$_REQUEST['phone']) : '';
$residentNo = isset($_REQUEST['resident_no']) ? trim((string)$_REQUEST['resident_no']) : '';

if ($name === '' && $residentNo === '') {
    ResponseHelper::fail('이름 또는 주민등록번호가 필요합니다.', 400);
}

$repo = new WorkerRepository(Db::pdo());
$match = $repo->matchWorker($name, $phone, $residentNo);

ResponseHelper::ok(array(
    'status' => isset($match['status']) ? $match['status'] : 'not_found',
    'message' => isset($match['message']) ? $match['message'] : '',
    'worker' => isset($match['worker']) ? $match['worker'] : null,
    'workers' => isset($match['workers']) ? $match['workers'] : array(),
));
