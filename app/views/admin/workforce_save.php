<?php
/**
 * C:\www\cpms\app\views\admin\workforce_save.php
 * - 관리 > 인력관리 신규/수정 저장 처리
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../services/WorkerRepository.php';
require_once __DIR__ . '/../../services/ResponseHelper.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }
if (!(Auth::isMaster() || Auth::canManageEmployees())) { http_response_code(403); echo '403 Forbidden'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }
csrf_validate();
$isAjax = isset($_POST['ajax']) && (int)$_POST['ajax'] === 1;
$isDevelopmentDepartment = Auth::isDevelopmentDepartment();

$pdo = Db::pdo();
if (!$pdo) {
    if ($isAjax) ResponseHelper::fail('DB 연결 실패', 500);
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
    'source_type' => isset($_POST['source_type']) ? $_POST['source_type'] : 'manual',
    'is_active' => isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1,
);

if ($isAjax) {
    $required = array(
        'name'=>'이름', 'phone'=>'연락처', 'resident_no'=>'주민번호', 'job_type'=>'구분/직종',
        'agency_name'=>'인력사 업체명', 'daily_wage'=>'임금단가', 'bank_name'=>'은행명',
        'bank_account'=>'계좌번호', 'account_holder'=>'예금주'
    );
    $missing = array();
    if ($isDevelopmentDepartment) {
        if (trim((string)$data['name']) === '') $missing[] = '이름';
    } else {
        foreach ($required as $field => $label) {
            if (!isset($data[$field]) || trim((string)$data[$field]) === '') $missing[] = $label;
        }
    }
    if (count($missing) > 0) ResponseHelper::fail('다음 필수항목을 입력하세요: ' . implode(', ', $missing), 422);
    $residentDigits = CryptoHelper::normalizeDigits($data['resident_no']);
    if ($residentDigits !== '' && strlen($residentDigits) !== 13) ResponseHelper::fail('주민번호 13자리를 확인하세요.', 422);
    $phoneDigits = CryptoHelper::normalizePhoneDigits($data['phone']);
    if ($phoneDigits !== '' && strlen($phoneDigits) < 9) ResponseHelper::fail('연락처를 확인하세요.', 422);
    $wageDigits = str_replace(',', '', trim((string)$data['daily_wage']));
    if ($wageDigits !== '' && (!preg_match('/^\d+$/', $wageDigits) || (!$isDevelopmentDepartment && (int)$wageDigits <= 0))) {
        ResponseHelper::fail($isDevelopmentDepartment ? '임금단가는 0 이상의 정수 금액으로 입력하세요.' : '임금단가는 0보다 큰 정수 금액으로 입력하세요.', 422);
    }
    $data['resident_no'] = $residentDigits !== '' ? substr($residentDigits, 0, 6) . '-' . substr($residentDigits, 6) : '';
    $data['daily_wage'] = $wageDigits !== '' ? (int)$wageDigits : 0;
    $duplicate = $repo->findDuplicate($data['resident_no'] !== '' ? CryptoHelper::hashSensitive($data['resident_no']) : null, $data['name'], $phoneDigits, isset($data['id']) ? (int)$data['id'] : 0);
    if ($duplicate) ResponseHelper::fail('같은 주민번호 또는 이름·연락처의 인력이 이미 등록되어 있습니다.', 409);
}

try {
    $savedId = $repo->save($data, $userId);
    if ($savedId <= 0) {
        if ($isAjax) ResponseHelper::fail('저장할 수 없습니다. 이름을 확인해주세요.', 422);
        flash_set('danger', '저장할 수 없습니다. 이름을 확인해주세요.');
    } else {
        error_log('[workforce_save] worker_id=' . (int)$savedId . ' user=' . (string)Auth::userEmail());
        flash_set('success', '인력 정보를 저장했습니다.');
        if ($isAjax) ResponseHelper::ok(array('worker_id' => (int)$savedId, 'message' => '인력 정보를 저장했습니다.'));
    }
} catch (Exception $e) {
    if ($isAjax) ResponseHelper::fail('저장 실패: ' . $e->getMessage(), 500);
    flash_set('danger', '저장 실패: ' . $e->getMessage());
}

header('Location: ?r=관리&tab=workforce');
exit;
