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
if (!Auth::canManageConstruction()) {
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

    if ($action === 'apply_latest_wage') {
        if (!cpms_labor_load_workforce_services()) {
            flash_set('error', '인력관리 서비스를 찾을 수 없습니다.');
            header('Location: ' . $redirect);
            exit;
        }

        $repo = new WorkerRepository($pdo);
        $stRows = $pdo->prepare("SELECT id, worker_id FROM cpms_project_labor_workers
                                 WHERE project_id = :pid
                                   AND is_deleted = 0
                                   AND worker_id IS NOT NULL
                                   AND worker_id > 0");
        $stRows->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $stRows->execute();
        $projectRows = $stRows->fetchAll(PDO::FETCH_ASSOC);
        $updated = 0;

        $stLatest = $pdo->prepare("UPDATE cpms_project_labor_workers
                                   SET worker_name_snapshot = :worker_name_snapshot,
                                       agency_name_snapshot = :agency_name_snapshot,
                                       job_type_snapshot = :job_type_snapshot,
                                       daily_wage_snapshot = :daily_wage_snapshot,
                                       deposit_rate = :deposit_rate,
                                       company_name = :company_name,
                                       source_type = 'workforce',
                                       matched_status = 'matched',
                                       updated_at = :now
                                   WHERE id = :id
                                     AND project_id = :pid");

        foreach ($projectRows as $projectRow) {
            $masterId = isset($projectRow['worker_id']) ? (int)$projectRow['worker_id'] : 0;
            $projectWorkerId = isset($projectRow['id']) ? (int)$projectRow['id'] : 0;
            if ($masterId <= 0 || $projectWorkerId <= 0) continue;
            $master = $repo->getById($masterId, false);
            if (!$master || !is_array($master)) continue;
            $payload = cpms_labor_worker_payload_from_workforce($master, 'workforce', 'matched');
            $stLatest->bindValue(':worker_name_snapshot', $payload['worker_name_snapshot']);
            $stLatest->bindValue(':agency_name_snapshot', $payload['agency_name_snapshot']);
            $stLatest->bindValue(':job_type_snapshot', $payload['job_type_snapshot']);
            $stLatest->bindValue(':daily_wage_snapshot', (int)$payload['daily_wage_snapshot'], PDO::PARAM_INT);
            $stLatest->bindValue(':deposit_rate', (int)$payload['deposit_rate'], PDO::PARAM_INT);
            $stLatest->bindValue(':company_name', $payload['company_name']);
            $stLatest->bindValue(':now', $now);
            $stLatest->bindValue(':id', $projectWorkerId, PDO::PARAM_INT);
            $stLatest->bindValue(':pid', $projectId, PDO::PARAM_INT);
            $stLatest->execute();
            $updated++;
        }

        flash_set('success', '최신 단가를 적용했습니다. 업데이트 ' . (int)$updated . '건');
        header('Location: ' . $redirect);
        exit;
    }

    if ($action === 'apply_workforce_by_name') {
        if (!cpms_labor_load_workforce_services()) {
            flash_set('error', '인력관리 서비스를 찾을 수 없습니다.');
            header('Location: ' . $redirect);
            exit;
        }

        $repo = new WorkerRepository($pdo);
        $stRows = $pdo->prepare("SELECT id, name, worker_name_snapshot
                                 FROM cpms_project_labor_workers
                                 WHERE project_id = :pid
                                   AND is_deleted = 0
                                 ORDER BY id ASC");
        $stRows->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $stRows->execute();
        $projectRows = $stRows->fetchAll(PDO::FETCH_ASSOC);

        $stMatched = $pdo->prepare("UPDATE cpms_project_labor_workers
                                    SET source = 'workforce',
                                        direct_member_id = NULL,
                                        worker_id = :worker_id,
                                        worker_name_snapshot = :worker_name_snapshot,
                                        agency_name_snapshot = :agency_name_snapshot,
                                        job_type_snapshot = :job_type_snapshot,
                                        daily_wage_snapshot = :daily_wage_snapshot,
                                        source_type = :source_type,
                                        matched_status = :matched_status,
                                        resident_no = :resident_no,
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
                                      AND is_deleted = 0");
        $stStatus = $pdo->prepare("UPDATE cpms_project_labor_workers
                                   SET matched_status = :matched_status,
                                       source_type = :source_type,
                                       updated_at = :now
                                   WHERE id = :id
                                     AND project_id = :pid
                                     AND is_deleted = 0");

        $matchedCount = 0;
        $duplicateCount = 0;
        $notFoundCount = 0;
        foreach ($projectRows as $projectRow) {
            $rowId = isset($projectRow['id']) ? (int)$projectRow['id'] : 0;
            $name = isset($projectRow['name']) ? trim((string)$projectRow['name']) : '';
            if ($name === '' && isset($projectRow['worker_name_snapshot'])) {
                $name = trim((string)$projectRow['worker_name_snapshot']);
            }
            if ($rowId <= 0 || $name === '') continue;

            $match = cpms_labor_match_workforce_by_name($pdo, $name);
            $status = isset($match['status']) ? trim((string)$match['status']) : 'not_found';
            if ($status === 'matched' && isset($match['worker']) && is_array($match['worker'])) {
                $masterId = isset($match['worker']['id']) ? (int)$match['worker']['id'] : 0;
                $master = $masterId > 0 ? $repo->getById($masterId, true) : $match['worker'];
                if (!$master || !is_array($master)) $master = $match['worker'];
                $payload = cpms_labor_worker_payload_from_workforce($master, 'workforce', 'matched');
                if (trim((string)$payload['name']) === '') $payload['name'] = $name;

                $stMatched->bindValue(':worker_id', (int)$payload['worker_id'], PDO::PARAM_INT);
                $stMatched->bindValue(':worker_name_snapshot', $payload['worker_name_snapshot']);
                $stMatched->bindValue(':agency_name_snapshot', $payload['agency_name_snapshot']);
                $stMatched->bindValue(':job_type_snapshot', $payload['job_type_snapshot']);
                $stMatched->bindValue(':daily_wage_snapshot', (int)$payload['daily_wage_snapshot'], PDO::PARAM_INT);
                $stMatched->bindValue(':source_type', $payload['source_type']);
                $stMatched->bindValue(':matched_status', $payload['matched_status']);
                $stMatched->bindValue(':resident_no', $payload['resident_no']);
                $stMatched->bindValue(':phone', $payload['phone']);
                $stMatched->bindValue(':address', $payload['address']);
                $stMatched->bindValue(':deposit_rate', (int)$payload['deposit_rate'], PDO::PARAM_INT);
                $stMatched->bindValue(':bank_account', $payload['bank_account']);
                $stMatched->bindValue(':bank_name', $payload['bank_name']);
                $stMatched->bindValue(':account_holder', $payload['account_holder']);
                $stMatched->bindValue(':company_name', $payload['company_name']);
                $stMatched->bindValue(':now', $now);
                $stMatched->bindValue(':id', $rowId, PDO::PARAM_INT);
                $stMatched->bindValue(':pid', $projectId, PDO::PARAM_INT);
                $stMatched->execute();
                $matchedCount++;
                continue;
            }

            $nextStatus = ($status === 'duplicate') ? 'duplicate' : 'not_found';
            $nextSourceType = ($status === 'duplicate') ? 'workforce' : 'shiftee';
            $stStatus->bindValue(':matched_status', $nextStatus);
            $stStatus->bindValue(':source_type', $nextSourceType);
            $stStatus->bindValue(':now', $now);
            $stStatus->bindValue(':id', $rowId, PDO::PARAM_INT);
            $stStatus->bindValue(':pid', $projectId, PDO::PARAM_INT);
            $stStatus->execute();
            if ($nextStatus === 'duplicate') $duplicateCount++;
            else $notFoundCount++;
        }

        flash_set('success', '인력관리 명단을 현재 현장 인원에 적용했습니다. 매칭 ' . (int)$matchedCount . '건 / 동명이인 ' . (int)$duplicateCount . '건 / 미등록 ' . (int)$notFoundCount . '건');
        header('Location: ' . $redirect);
        exit;
    }

    // 인원작성 저장 기능: 인원별 임금/계좌 정보 저장
    if ($action === 'save') {
        // 파일: app/views/construction/labor_workers_save.php
        // 모든 비율을 먼저 검증해 잘못된 값이 하나라도 있으면 인원정보도 부분 저장하지 않습니다.
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            flash_set('error', '비용 배분을 저장할 적용 월이 올바르지 않습니다.');
            header('Location: ' . $redirect);
            exit;
        }
        if (!cpms_ensure_project_labor_worker_months_table($pdo)) {
            flash_set('error', '월별 비용 배분 테이블을 확인할 수 없습니다.');
            header('Location: ' . $redirect);
            exit;
        }

        $validatedOutsourcingRatios = array();
        foreach ($workers as $workerIdRaw => $fields) {
            $workerId = (int)$workerIdRaw;
            if ($workerId <= 0 || !is_array($fields)) continue;
            if (array_key_exists('outsourcing_ratio', $fields)) {
                $ratioRaw = trim((string)$fields['outsourcing_ratio']);
            } else {
                // 이전 화면에서 넘어온 요청도 기존 체크박스 의미를 유지합니다.
                $ratioRaw = (isset($fields['is_outsourcing']) && (int)$fields['is_outsourcing'] === 1) ? '100' : '0';
            }
            if (!preg_match('/^\d{1,3}$/', $ratioRaw)) {
                flash_set('error', '외주비 비율은 0부터 100 사이의 정수로 입력해 주세요.');
                header('Location: ' . $redirect);
                exit;
            }
            $outsourcingRatio = (int)$ratioRaw;
            if ($outsourcingRatio < 0 || $outsourcingRatio > 100) {
                flash_set('error', '외주비 비율은 0보다 작거나 100보다 클 수 없습니다.');
                header('Location: ' . $redirect);
                exit;
            }
            $validatedOutsourcingRatios[$workerId] = $outsourcingRatio;
        }

        $pdo->beginTransaction();
        if (count($workers) > 0) {
            foreach ($workers as $workerIdRaw => $fields) {
                $workerId = (int)$workerIdRaw;
                if ($workerId <= 0 || !is_array($fields)) continue;

                $phone = isset($fields['phone']) ? trim((string)$fields['phone']) : '';
                $address = isset($fields['address']) ? trim((string)$fields['address']) : '';
                $companyName = isset($fields['company_name']) ? trim((string)$fields['company_name']) : '';
                $outsourcingRatio = isset($validatedOutsourcingRatios[$workerId]) ? (int)$validatedOutsourcingRatios[$workerId] : 0;
                $isOutsourcing = ($outsourcingRatio === 100) ? 1 : 0;
                $jobTypeSnapshot = isset($fields['job_type_snapshot']) ? trim((string)$fields['job_type_snapshot']) : '';
                $workerNameSnapshot = isset($fields['worker_name_snapshot']) ? trim((string)$fields['worker_name_snapshot']) : '';
                $sourceType = isset($fields['source_type']) ? trim((string)$fields['source_type']) : 'manual';
                $matchedStatus = isset($fields['matched_status']) ? trim((string)$fields['matched_status']) : 'manual';
                $masterWorkerId = isset($fields['worker_id']) ? (int)$fields['worker_id'] : 0;

                $depositRateRaw = isset($fields['deposit_rate']) ? trim((string)$fields['deposit_rate']) : '';
                $depositRateNormalized = preg_replace('/[^0-9\-]/', '', $depositRateRaw);
                if ($depositRateNormalized === '' || !is_numeric($depositRateNormalized)) {
                    $depositRate = 0;
                } else {
                    $depositRate = (int)$depositRateNormalized;
                    if ($depositRate < 0) $depositRate = 0;
                }

                if ($sourceType === '') $sourceType = 'manual';
                if ($matchedStatus === '') $matchedStatus = ($masterWorkerId > 0 ? 'matched' : 'manual');

                // 최초 월별 비율 저장 전에 기존 이진 외주값을 보존하여 다른 월의 금액이 바뀌지 않게 합니다.
                $stLegacyRatio = $pdo->prepare("UPDATE cpms_project_labor_workers
                                                SET legacy_outsourcing_ratio = IF(is_outsourcing = 1, 100, 0)
                                                WHERE id = :id
                                                  AND project_id = :pid
                                                  AND is_deleted = 0
                                                  AND legacy_outsourcing_ratio IS NULL");
                $stLegacyRatio->bindValue(':id', $workerId, PDO::PARAM_INT);
                $stLegacyRatio->bindValue(':pid', $projectId, PDO::PARAM_INT);
                $stLegacyRatio->execute();

                $set = array(
                    'phone = :phone',
                    'address = :address',
                    'deposit_rate = :deposit_rate',
                    'worker_id = :worker_id',
                    'worker_name_snapshot = :worker_name_snapshot',
                    'agency_name_snapshot = :agency_name_snapshot',
                    'job_type_snapshot = :job_type_snapshot',
                    'daily_wage_snapshot = :daily_wage_snapshot',
                    'source_type = :source_type',
                    'matched_status = :matched_status',
                    'company_name = :company_name',
                    'is_outsourcing = :is_outsourcing',
                    'updated_at = :now'
                );
                $params = array(
                    ':phone' => $phone === '' ? null : $phone,
                    ':address' => $address === '' ? null : $address,
                    ':deposit_rate' => $depositRate,
                    ':worker_id' => $masterWorkerId > 0 ? $masterWorkerId : null,
                    ':worker_name_snapshot' => $workerNameSnapshot === '' ? null : $workerNameSnapshot,
                    ':agency_name_snapshot' => $companyName === '' ? null : $companyName,
                    ':job_type_snapshot' => $jobTypeSnapshot === '' ? null : $jobTypeSnapshot,
                    ':daily_wage_snapshot' => $depositRate,
                    ':source_type' => $sourceType,
                    ':matched_status' => $matchedStatus,
                    ':company_name' => $companyName === '' ? null : $companyName,
                    ':is_outsourcing' => $isOutsourcing,
                    ':now' => $now,
                    ':id' => $workerId,
                    ':pid' => $projectId,
                );

                if (array_key_exists('bank_account', $fields)) {
                    $set[] = 'bank_account = :bank_account';
                    $bankAccount = trim((string)$fields['bank_account']);
                    $params[':bank_account'] = $bankAccount === '' ? null : $bankAccount;
                }
                if (array_key_exists('bank_name', $fields)) {
                    $set[] = 'bank_name = :bank_name';
                    $bankName = trim((string)$fields['bank_name']);
                    $params[':bank_name'] = $bankName === '' ? null : $bankName;
                }
                if (array_key_exists('account_holder', $fields)) {
                    $set[] = 'account_holder = :account_holder';
                    $accountHolder = trim((string)$fields['account_holder']);
                    $params[':account_holder'] = $accountHolder === '' ? null : $accountHolder;
                }

                $sql = "UPDATE cpms_project_labor_workers
                        SET " . implode(', ', $set) . "
                        WHERE id = :id
                          AND project_id = :pid
                          AND is_deleted = 0";
                $stUp = $pdo->prepare($sql);
                foreach ($params as $paramName => $paramValue) {
                    if ($paramValue === null) $stUp->bindValue($paramName, null, PDO::PARAM_NULL);
                    else if (is_int($paramValue)) $stUp->bindValue($paramName, $paramValue, PDO::PARAM_INT);
                    else $stUp->bindValue($paramName, $paramValue);
                }
                $stUp->execute();

                if (!cpms_save_project_labor_worker_month_ratio($pdo, $projectId, $workerId, $month, $outsourcingRatio)) {
                    throw new Exception('월별 외주비 비율을 저장하지 못했습니다.');
                }
            }
        }

        $pdo->commit();

        flash_set('success', '인원 정보와 ' . $month . ' 비용 배분을 저장했습니다.');
        header('Location: ' . $redirect);
        exit;
    }

    flash_set('error', '요청 동작이 올바르지 않습니다.');
    header('Location: ' . $redirect);
    exit;

} catch (Exception $e) {
    if (isset($pdo) && $pdo && $pdo->inTransaction()) $pdo->rollBack();
    flash_set('error', '저장 실패: ' . $e->getMessage());
    header('Location: ' . $redirect);
    exit;
}
