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
$previousWorkerIdsRaw = isset($_POST['previous_worker_ids']) && is_array($_POST['previous_worker_ids']) ? $_POST['previous_worker_ids'] : array();
if ($laborTab === '') $laborTab = 'workers';
$workerSort = isset($_POST['worker_sort']) ? trim((string)$_POST['worker_sort']) : 'company';
$workerSortAllowed = array('company', 'name', 'allocation', 'phone', 'address', 'job_type', 'wage', 'remark');
if (!in_array($workerSort, $workerSortAllowed, true)) $workerSort = 'company';
$workerSortDir = isset($_POST['worker_sort_dir']) && (string)$_POST['worker_sort_dir'] === 'desc' ? 'desc' : 'asc';

$redirect = '?r=공사&pid=' . $projectId . '&tab=labor&labor_tab=' . urlencode($laborTab);
if ($month !== '') $redirect .= '&month=' . urlencode($month);
$redirect .= '&worker_sort=' . urlencode($workerSort) . '&worker_sort_dir=' . urlencode($workerSortDir);

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

    if ($action === 'import_previous') {
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            flash_set('error', '가져올 대상 월이 올바르지 않습니다.');
            header('Location: ' . $redirect);
            exit;
        }
        if (!cpms_ensure_project_labor_worker_months_table($pdo)) {
            flash_set('error', '월별 인원 테이블을 확인할 수 없습니다.');
            header('Location: ' . $redirect);
            exit;
        }

        $selectedWorkerIds = array();
        foreach ($previousWorkerIdsRaw as $previousWorkerIdRaw) {
            $previousWorkerId = (int)$previousWorkerIdRaw;
            if ($previousWorkerId > 0) $selectedWorkerIds[$previousWorkerId] = $previousWorkerId;
            if (count($selectedWorkerIds) >= 500) break;
        }
        if (count($selectedWorkerIds) === 0) {
            flash_set('error', '전달에서 가져올 인원을 선택해주세요.');
            header('Location: ' . $redirect);
            exit;
        }

        $previousMonth = date('Y-m', strtotime($month . '-01 -1 month'));
        $previousRows = cpms_load_project_labor_workers_for_month($pdo, $projectId, $previousMonth);
        $previousAvailableMap = array();
        $activeDirectMemberMap = array();
        if (cpms_table_exists_labor($pdo, 'direct_team_members')) {
            $stActiveDirect = $pdo->query("SELECT id FROM direct_team_members WHERE is_active = 1");
            $activeDirectRows = $stActiveDirect ? $stActiveDirect->fetchAll(PDO::FETCH_ASSOC) : array();
            foreach ($activeDirectRows as $activeDirectRow) {
                $activeDirectId = isset($activeDirectRow['id']) ? (int)$activeDirectRow['id'] : 0;
                if ($activeDirectId > 0) $activeDirectMemberMap[$activeDirectId] = true;
            }
        }
        $retiredDirectCount = 0;
        foreach ($previousRows as $previousRow) {
            $previousRowId = isset($previousRow['id']) ? (int)$previousRow['id'] : 0;
            $previousDirectId = isset($previousRow['direct_member_id']) ? (int)$previousRow['direct_member_id'] : 0;
            if ($previousDirectId > 0 && !isset($activeDirectMemberMap[$previousDirectId])) {
                if ($previousRowId > 0 && isset($selectedWorkerIds[$previousRowId])) $retiredDirectCount++;
                continue;
            }
            if ($previousRowId > 0) $previousAvailableMap[$previousRowId] = true;
        }
        $currentMonthMap = cpms_load_project_labor_worker_month_map($pdo, $projectId, $month);
        $importWorkerIds = array();
        $alreadyCurrentCount = 0;
        $matchedPreviousCount = 0;
        foreach ($selectedWorkerIds as $selectedWorkerId) {
            if (!isset($previousAvailableMap[$selectedWorkerId])) continue;
            $matchedPreviousCount++;
            if (isset($currentMonthMap[$selectedWorkerId])) {
                $alreadyCurrentCount++;
                continue;
            }
            $importWorkerIds[] = $selectedWorkerId;
        }
        if (count($importWorkerIds) === 0) {
            if ($matchedPreviousCount > 0 && $alreadyCurrentCount === $matchedPreviousCount) {
                flash_set('success', '선택한 전달 인원은 이미 ' . $month . '에 등록되어 있습니다.');
            } else {
                flash_set('error', $retiredDirectCount > 0 ? '퇴직한 직영팀 인원은 노무비에 가져올 수 없습니다.' : '선택한 인원이 ' . $previousMonth . ' 전달 명단에 없습니다. 다시 확인해주세요.');
            }
            header('Location: ' . $redirect);
            exit;
        }

        $pdo->beginTransaction();
        foreach ($importWorkerIds as $importWorkerId) {
            // 전달의 인원 기본정보는 그대로 사용하고, 당월 비용배분만 전액 노무비로 시작합니다.
            if (!cpms_save_project_labor_worker_month_ratio($pdo, $projectId, $importWorkerId, $month, 0, '', '')) {
                throw new Exception('선택한 전달 인원을 저장하지 못했습니다.');
            }
        }
        $pdo->commit();

        $message = $previousMonth . ' 전달 인원 ' . count($importWorkerIds) . '명을 가져왔습니다. 비용 배분은 전액 노무비로 적용했습니다.';
        if ($alreadyCurrentCount > 0) $message .= ' 이미 등록된 ' . (int)$alreadyCurrentCount . '명은 제외했습니다.';
        flash_set('success', $message);
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
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            flash_set('error', '최신 단가를 적용할 월이 올바르지 않습니다.');
            header('Location: ' . $redirect);
            exit;
        }
        $selectedWageMap = cpms_load_project_labor_worker_wage_map($pdo, $projectId, $month);
        $stRows = $pdo->prepare("SELECT id, worker_id, deposit_rate, daily_wage_snapshot FROM cpms_project_labor_workers
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
            $previousWage = isset($selectedWageMap[$projectWorkerId]) ? (int)$selectedWageMap[$projectWorkerId] : 0;
            if ($previousWage <= 0 && isset($projectRow['daily_wage_snapshot'])) $previousWage = (int)$projectRow['daily_wage_snapshot'];
            if ($previousWage <= 0 && isset($projectRow['deposit_rate'])) $previousWage = (int)$projectRow['deposit_rate'];
            $wageResult = cpms_save_project_labor_worker_month_wage($pdo, $projectId, $projectWorkerId, $month, (int)$payload['daily_wage_snapshot'], $previousWage);
            if (!isset($wageResult['saved']) || !$wageResult['saved']) continue;
            if (!isset($wageResult['is_latest']) || !$wageResult['is_latest']) {
                $updated++;
                continue;
            }
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

        flash_set('success', $month . '에 인력관리 최신 단가를 적용했습니다. 이전 월 단가는 유지됩니다. 업데이트 ' . (int)$updated . '건');
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
        $validatedAllocationDates = array();
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
            $outsourcingStartDate = isset($fields['outsourcing_start_date']) ? trim((string)$fields['outsourcing_start_date']) : '';
            $outsourcingEndDate = isset($fields['outsourcing_end_date']) ? trim((string)$fields['outsourcing_end_date']) : '';
            if (($outsourcingStartDate === '') !== ($outsourcingEndDate === '')) {
                flash_set('error', '외주비 적용 시작일과 종료일을 모두 입력해 주세요.');
                header('Location: ' . $redirect);
                exit;
            }
            if ($outsourcingStartDate !== '') {
                $startParts = explode('-', $outsourcingStartDate);
                $endParts = explode('-', $outsourcingEndDate);
                $validStart = count($startParts) === 3 && checkdate((int)$startParts[1], (int)$startParts[2], (int)$startParts[0]);
                $validEnd = count($endParts) === 3 && checkdate((int)$endParts[1], (int)$endParts[2], (int)$endParts[0]);
                if (!$validStart || !$validEnd || strpos($outsourcingStartDate, $month . '-') !== 0 || strpos($outsourcingEndDate, $month . '-') !== 0) {
                    flash_set('error', '외주비 적용기간은 선택한 월 안의 날짜만 입력할 수 있습니다.');
                    header('Location: ' . $redirect);
                    exit;
                }
                if ($outsourcingStartDate > $outsourcingEndDate) {
                    flash_set('error', '외주비 적용 시작일은 종료일보다 늦을 수 없습니다.');
                    header('Location: ' . $redirect);
                    exit;
                }
            }
            $validatedAllocationDates[$workerId] = array('start'=>$outsourcingStartDate, 'end'=>$outsourcingEndDate);
        }

        if (!cpms_labor_load_workforce_services()) {
            flash_set('error', '인력관리 서비스를 찾을 수 없습니다.');
            header('Location: ' . $redirect);
            exit;
        }
        $selectedWageMap = cpms_load_project_labor_worker_wage_map($pdo, $projectId, $month);
        $repo = new WorkerRepository($pdo);
        $authUser = Auth::user();
        $authUserId = is_array($authUser) && isset($authUser['id']) ? (int)$authUser['id'] : 0;

        $pdo->beginTransaction();
        if (count($workers) > 0) {
            foreach ($workers as $workerIdRaw => $fields) {
                $workerId = (int)$workerIdRaw;
                if ($workerId <= 0 || !is_array($fields)) continue;

                $companyName = isset($fields['company_name']) ? trim((string)$fields['company_name']) : '';
                $jobType = isset($fields['job_type']) ? trim((string)$fields['job_type']) : '';
                $remark = isset($fields['remark']) ? trim((string)$fields['remark']) : '';
                if (function_exists('mb_strlen')) {
                    $jobTypeLength = mb_strlen($jobType, 'UTF-8');
                    $remarkLength = mb_strlen($remark, 'UTF-8');
                } else {
                    $jobTypeMatches = array();
                    $remarkMatches = array();
                    $jobTypeLength = preg_match_all('/./us', $jobType, $jobTypeMatches);
                    $remarkLength = preg_match_all('/./us', $remark, $remarkMatches);
                    if ($jobTypeLength === false) $jobTypeLength = strlen($jobType);
                    if ($remarkLength === false) $remarkLength = strlen($remark);
                }
                if ($jobTypeLength > 100 || $remarkLength > 255) {
                    throw new Exception('구분/직종 또는 비고 입력 길이를 확인해 주세요.');
                }
                $outsourcingRatio = isset($validatedOutsourcingRatios[$workerId]) ? (int)$validatedOutsourcingRatios[$workerId] : 0;
                $isOutsourcing = ($outsourcingRatio === 100) ? 1 : 0;

                $depositRateRaw = isset($fields['deposit_rate']) ? trim((string)$fields['deposit_rate']) : '';
                $depositRateNormalized = preg_replace('/[^0-9]/', '', $depositRateRaw);
                if ($depositRateNormalized === '' || !is_numeric($depositRateNormalized)) {
                    $depositRate = 0;
                } else {
                    $depositRate = (int)$depositRateNormalized;
                    if ($depositRate < 0) $depositRate = 0;
                }

                $stCurrent = $pdo->prepare("SELECT plw.worker_id, plw.direct_member_id, plw.deposit_rate,
                                                   plw.daily_wage_snapshot,
                                                   COALESCE(dtm.monthly_salary, 0) AS direct_monthly_salary
                                            FROM cpms_project_labor_workers plw
                                            LEFT JOIN direct_team_members dtm ON dtm.id = plw.direct_member_id
                                            WHERE plw.id = :id AND plw.project_id = :pid AND plw.is_deleted = 0 LIMIT 1");
                $stCurrent->bindValue(':id', $workerId, PDO::PARAM_INT);
                $stCurrent->bindValue(':pid', $projectId, PDO::PARAM_INT);
                $stCurrent->execute();
                $currentRow = $stCurrent->fetch(PDO::FETCH_ASSOC);
                if (!$currentRow) continue;
                $masterWorkerId = isset($currentRow['worker_id']) ? (int)$currentRow['worker_id'] : 0;
                $isDirectSalaryWorker = isset($currentRow['direct_member_id'])
                    && (int)$currentRow['direct_member_id'] > 0
                    && isset($currentRow['direct_monthly_salary'])
                    && (int)$currentRow['direct_monthly_salary'] > 0;
                if ($isDirectSalaryWorker) {
                    $outsourcingRatio = 0;
                    $isOutsourcing = 0;
                    $validatedAllocationDates[$workerId] = array('start'=>'', 'end'=>'');
                }
                $previousWage = isset($selectedWageMap[$workerId]) ? (int)$selectedWageMap[$workerId] : 0;
                if ($previousWage <= 0 && isset($currentRow['daily_wage_snapshot'])) $previousWage = (int)$currentRow['daily_wage_snapshot'];
                if ($previousWage <= 0 && isset($currentRow['deposit_rate'])) $previousWage = (int)$currentRow['deposit_rate'];

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

                $wageResult = $isDirectSalaryWorker
                    ? array('saved'=>true, 'is_latest'=>false)
                    : cpms_save_project_labor_worker_month_wage($pdo, $projectId, $workerId, $month, $depositRate, $previousWage);
                if (!isset($wageResult['saved']) || !$wageResult['saved']) {
                    throw new Exception('월별 임금단가를 저장하지 못했습니다.');
                }

                $set = array(
                    'agency_name_snapshot = :agency_name_snapshot',
                    'job_type_snapshot = :job_type_snapshot',
                    'company_name = :company_name',
                    'remark = :remark',
                    'is_outsourcing = :is_outsourcing',
                    'updated_at = :now'
                );
                $params = array(
                    ':agency_name_snapshot' => $companyName === '' ? null : $companyName,
                    ':job_type_snapshot' => $jobType === '' ? null : $jobType,
                    ':company_name' => $companyName === '' ? null : $companyName,
                    ':remark' => $remark === '' ? null : $remark,
                    ':is_outsourcing' => $isOutsourcing,
                    ':now' => $now,
                    ':id' => $workerId,
                    ':pid' => $projectId,
                );

                if (isset($wageResult['is_latest']) && $wageResult['is_latest']) {
                    $set[] = 'deposit_rate = :deposit_rate';
                    $set[] = 'daily_wage_snapshot = :daily_wage_snapshot';
                    $params[':deposit_rate'] = $depositRate;
                    $params[':daily_wage_snapshot'] = $depositRate;
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

                if ($masterWorkerId > 0) {
                    if (!$repo->updateProjectEditableFields($masterWorkerId, $companyName, $depositRate, $authUserId, isset($wageResult['is_latest']) && $wageResult['is_latest'], $jobType, $remark)) {
                        throw new Exception('관리 인력정보를 업데이트하지 못했습니다.');
                    }
                }

                $allocationDates = isset($validatedAllocationDates[$workerId]) ? $validatedAllocationDates[$workerId] : array('start'=>'', 'end'=>'');
                if (!cpms_save_project_labor_worker_month_ratio($pdo, $projectId, $workerId, $month, $outsourcingRatio, $allocationDates['start'], $allocationDates['end'])) {
                    throw new Exception('월별 외주비 비율을 저장하지 못했습니다.');
                }
            }
        }

        $pdo->commit();

        flash_set('success', $month . ' 임금단가와 비용 배분을 저장하고 인력사·구분/직종·비고를 관리 인력에 반영했습니다. 이전 월 단가는 유지됩니다.');
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
