<?php
/**
 * C:\www\cpms\app\views\admin\workforce_save.php
 * - 관리 > 인력관리 신규/수정 저장 처리
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

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('danger', 'DB 연결 실패');
    header('Location: ?r=관리&tab=workforce');
    exit;
}

$repo = new WorkerRepository($pdo);
$user = Auth::user();
$userId = (is_array($user) && isset($user['id'])) ? (int)$user['id'] : 0;

$data = array(
    'id' => isset($_POST['id']) ? (int)$_POST['id'] : 0,
    'import_no' => isset($_POST['import_no']) ? $_POST['import_no'] : '',
    'name' => isset($_POST['name']) ? $_POST['name'] : '',
    'resident_no' => isset($_POST['resident_no']) ? $_POST['resident_no'] : '',
    'birth_date' => isset($_POST['birth_date']) ? $_POST['birth_date'] : '',
    'phone' => isset($_POST['phone']) ? $_POST['phone'] : '',
    'address' => isset($_POST['address']) ? $_POST['address'] : '',
    'job_type' => isset($_POST['job_type']) ? $_POST['job_type'] : '',
    'agency_name' => isset($_POST['agency_name']) ? $_POST['agency_name'] : '',
    'daily_wage' => isset($_POST['daily_wage']) ? $_POST['daily_wage'] : '',
    'account_holder' => isset($_POST['account_holder']) ? $_POST['account_holder'] : '',
    'bank_name' => isset($_POST['bank_name']) ? $_POST['bank_name'] : '',
    'bank_account' => isset($_POST['bank_account']) ? $_POST['bank_account'] : '',
    'memo' => isset($_POST['memo']) ? $_POST['memo'] : '',
    'source_type' => 'manual',
    'is_active' => isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1,
);

try {
    $savedId = $repo->save($data, $userId);
    if ($savedId <= 0) {
        flash_set('danger', '저장할 수 없습니다. 이름을 확인해주세요.');
    } else {
        error_log('[workforce_save] worker_id=' . (int)$savedId . ' user=' . (string)Auth::userEmail());
        flash_set('success', '인력 정보를 저장했습니다.');
    }
} catch (Exception $e) {
    flash_set('danger', '저장 실패: ' . $e->getMessage());
}

header('Location: ?r=관리&tab=workforce');
exit;
