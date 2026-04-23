<?php
/**
 * - 공사: 노무비 인원작성 저장/삭제
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
    flash_set('error', '보안 토큰이 유효하지 않습니다.');
    header('Location: ?r=공사');
    exit;
}

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$month = isset($_POST['month']) ? trim((string)$_POST['month']) : '';
$laborTab = isset($_POST['labor_tab']) ? trim((string)$_POST['labor_tab']) : 'workers';
$action = isset($_POST['action']) ? trim((string)$_POST['action']) : 'save';
$deleteWorkerId = isset($_POST['delete_worker_id']) ? (int)$_POST['delete_worker_id'] : 0;
$workers = isset($_POST['workers']) && is_array($_POST['workers']) ? $_POST['workers'] : array();
if ($laborTab === '') $laborTab = 'workers';

$redirect = '?r=공사&pid=' . $projectId . '&tab=labor&labor_tab=' . urlencode($laborTab);
if ($month !== '') $redirect .= '&month=' . urlencode($month);

if ($projectId <= 0) {
    flash_set('error', '프로젝트 정보가 올바르지 않습니다.');
    header('Location: ' . $redirect);
    exit;
}

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error', 'DB 연결 실패');
    header('Location: ' . $redirect);
    exit;
}

if (!cpms_ensure_project_labor_workers_table($pdo)) {
    flash_set('error', '인원 목록 테이블을 확인할 수 없습니다.');
    header('Location: ' . $redirect);
    exit;
}

try {
    $now = date('Y-m-d H:i:s');

    // 인원작성 저장 기능: 동일 엔드포인트에서 삭제 처리
    if ($action === 'delete' && $deleteWorkerId > 0) {
        $stDel = $pdo->prepare("UPDATE cpms_project_labor_workers
                                SET is_deleted = 1,
                                    updated_at = :now
                                WHERE id = :id
                                  AND project_id = :pid");
        $stDel->bindValue(':now', $now);
        $stDel->bindValue(':id', $deleteWorkerId, PDO::PARAM_INT);
        $stDel->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $stDel->execute();

        flash_set('success', '인원을 삭제했습니다.');
        header('Location: ' . $redirect);
        exit;
    }

    // 인원작성 저장 기능: 인원별 임금/계좌 정보 저장
    if ($action === 'save') {
        if (count($workers) > 0) {
            $sql = "UPDATE cpms_project_labor_workers
                    SET resident_no = :resident_no,
                        phone = :phone,
                        address = :address,
                        deposit_rate = :deposit_rate,
                        bank_account = :bank_account,
                        bank_name = :bank_name,
                        account_holder = :account_holder,
                        company_name = :company_name,
                        updated_at = :now
                    WHERE id = :id
                      AND project_id = :pid
                      AND is_deleted = 0";
            $stUp = $pdo->prepare($sql);

            foreach ($workers as $workerIdRaw => $fields) {
                $workerId = (int)$workerIdRaw;
                if ($workerId <= 0 || !is_array($fields)) continue;

                $residentNo = isset($fields['resident_no']) ? trim((string)$fields['resident_no']) : '';
                $phone = isset($fields['phone']) ? trim((string)$fields['phone']) : '';
                $address = isset($fields['address']) ? trim((string)$fields['address']) : '';
                $bankAccount = isset($fields['bank_account']) ? trim((string)$fields['bank_account']) : '';
                $bankName = isset($fields['bank_name']) ? trim((string)$fields['bank_name']) : '';
                $accountHolder = isset($fields['account_holder']) ? trim((string)$fields['account_holder']) : '';
                $companyName = isset($fields['company_name']) ? trim((string)$fields['company_name']) : '';

                $depositRateRaw = isset($fields['deposit_rate']) ? trim((string)$fields['deposit_rate']) : '';
                $depositRateNormalized = preg_replace('/[^0-9\-]/', '', $depositRateRaw);
                if ($depositRateNormalized === '' || !is_numeric($depositRateNormalized)) {
                    $depositRate = 0;
                } else {
                    $depositRate = (int)$depositRateNormalized;
                    if ($depositRate < 0) $depositRate = 0;
                }

                $stUp->bindValue(':resident_no', $residentNo === '' ? null : $residentNo, $residentNo === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $stUp->bindValue(':phone', $phone === '' ? null : $phone, $phone === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $stUp->bindValue(':address', $address === '' ? null : $address, $address === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $stUp->bindValue(':deposit_rate', $depositRate, PDO::PARAM_INT);
                $stUp->bindValue(':bank_account', $bankAccount === '' ? null : $bankAccount, $bankAccount === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $stUp->bindValue(':bank_name', $bankName === '' ? null : $bankName, $bankName === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $stUp->bindValue(':account_holder', $accountHolder === '' ? null : $accountHolder, $accountHolder === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $stUp->bindValue(':company_name', $companyName === '' ? null : $companyName, $companyName === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $stUp->bindValue(':now', $now);
                $stUp->bindValue(':id', $workerId, PDO::PARAM_INT);
                $stUp->bindValue(':pid', $projectId, PDO::PARAM_INT);
                $stUp->execute();
            }
        }

        flash_set('success', '인원 정보를 저장했습니다.');
        header('Location: ' . $redirect);
        exit;
    }

    flash_set('error', '요청 동작이 올바르지 않습니다.');
    header('Location: ' . $redirect);
    exit;

} catch (Exception $e) {
    flash_set('error', '저장 실패: ' . $e->getMessage());
    header('Location: ' . $redirect);
    exit;
}