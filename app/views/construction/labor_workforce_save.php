<?php
/**
 * 공사 > 노무비 > 인원 등록
 * - 일반 부서는 필수 정보를 완성해 등록하고 개발부서는 이름만으로 임시 등록할 수 있습니다.
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../services/WorkerRepository.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }
if (!Auth::canManageConstruction()) { http_response_code(403); echo '403 Forbidden'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }
csrf_validate();
$isDevelopmentDepartment = Auth::isDevelopmentDepartment();

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$month = isset($_POST['month']) ? trim((string)$_POST['month']) : '';
$redirect = '?r=공사&pid=' . $projectId . '&tab=labor&labor_tab=worker_register';
if ($month !== '') $redirect .= '&month=' . urlencode($month);

$data = array(
    'name' => isset($_POST['name']) ? trim((string)$_POST['name']) : '',
    'phone' => isset($_POST['phone']) ? trim((string)$_POST['phone']) : '',
    'resident_no' => isset($_POST['resident_no']) ? trim((string)$_POST['resident_no']) : '',
    'job_type' => isset($_POST['job_type']) ? trim((string)$_POST['job_type']) : '',
    'agency_name' => isset($_POST['agency_name']) ? trim((string)$_POST['agency_name']) : '',
    'daily_wage' => isset($_POST['daily_wage']) ? trim((string)$_POST['daily_wage']) : '',
    'bank_name' => isset($_POST['bank_name']) ? trim((string)$_POST['bank_name']) : '',
    'bank_account' => isset($_POST['bank_account']) ? trim((string)$_POST['bank_account']) : '',
    'account_holder' => isset($_POST['account_holder']) ? trim((string)$_POST['account_holder']) : '',
    'source_type' => 'construction',
    'is_active' => 1,
);

$labels = array(
    'name' => '이름', 'phone' => '연락처', 'resident_no' => '주민번호', 'job_type' => '구분/직종',
    'agency_name' => '인력사 업체명', 'daily_wage' => '임금단가', 'bank_name' => '은행명',
    'bank_account' => '계좌번호', 'account_holder' => '예금주'
);
$missing = array();
if ($isDevelopmentDepartment) {
    if ($data['name'] === '') $missing[] = '이름';
} else {
    foreach ($labels as $field => $label) {
        if (!isset($data[$field]) || trim((string)$data[$field]) === '') $missing[] = $label;
    }
}
if (count($missing) > 0) {
    flash_set('error', '다음 필수항목을 입력하세요: ' . implode(', ', $missing));
    header('Location: ' . $redirect);
    exit;
}

$residentDigits = CryptoHelper::normalizeDigits($data['resident_no']);
$phoneDigits = CryptoHelper::normalizePhoneDigits($data['phone']);
$wageDigits = str_replace(',', '', trim((string)$data['daily_wage']));
if ($residentDigits !== '' && strlen($residentDigits) !== 13) {
    flash_set('error', '주민번호 13자리를 확인하세요.');
    header('Location: ' . $redirect);
    exit;
}
if ($phoneDigits !== '' && strlen($phoneDigits) < 9) {
    flash_set('error', '연락처를 확인하세요.');
    header('Location: ' . $redirect);
    exit;
}
if ($wageDigits !== '' && (!preg_match('/^\d+$/', $wageDigits) || (!$isDevelopmentDepartment && (int)$wageDigits <= 0))) {
    flash_set('error', $isDevelopmentDepartment ? '임금단가는 0 이상의 정수 금액으로 입력하세요.' : '임금단가는 0보다 큰 정수 금액으로 입력하세요.');
    header('Location: ' . $redirect);
    exit;
}
$data['resident_no'] = $residentDigits !== '' ? substr($residentDigits, 0, 6) . '-' . substr($residentDigits, 6) : '';
$data['daily_wage'] = $wageDigits !== '' ? (int)$wageDigits : 0;

$pdo = Db::pdo();
$repo = new WorkerRepository($pdo);
$duplicate = $repo->findDuplicate($data['resident_no'] !== '' ? CryptoHelper::hashSensitive($data['resident_no']) : null, $data['name'], $phoneDigits, 0);
if ($duplicate) {
    $duplicateName = isset($duplicate['name']) ? trim((string)$duplicate['name']) : $data['name'];
    flash_set('error', '이미 등록된 인력입니다: ' . $duplicateName . '. 관리섹션 인력관리에서 확인하세요.');
    header('Location: ' . $redirect);
    exit;
}

try {
    $user = Auth::user();
    $userId = is_array($user) && isset($user['id']) ? (int)$user['id'] : 0;
    $savedId = $repo->save($data, $userId);
    if ($savedId <= 0) throw new Exception('인력 정보를 저장하지 못했습니다.');
    flash_set('success', '인력을 등록했습니다. 인원 작성 탭에서 이름을 검색해 추가하세요.');
    $redirect = '?r=공사&pid=' . $projectId . '&tab=labor&labor_tab=workers';
    if ($month !== '') $redirect .= '&month=' . urlencode($month);
} catch (Exception $e) {
    flash_set('error', '인력 등록 실패: ' . $e->getMessage());
}

header('Location: ' . $redirect);
exit;
