<?php
/**
 * - 공사: 노무비 인원작성(직영팀/수동 추가)
 * - 프로젝트별 인원 목록에 작업자를 추가
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/tabs/partials/labor_data_loader.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }

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
$directMemberId = isset($_POST['direct_member_id']) ? (int)$_POST['direct_member_id'] : 0;
$workforceWorkerId = isset($_POST['workforce_worker_id']) ? (int)$_POST['workforce_worker_id'] : 0;
$manualName = isset($_POST['manual_name']) ? trim((string)$_POST['manual_name']) : '';
$manualCompanyName = isset($_POST['manual_company_name']) ? trim((string)$_POST['manual_company_name']) : '';
$month = isset($_POST['month']) ? trim((string)$_POST['month']) : '';
$laborTab = isset($_POST['labor_tab']) ? trim((string)$_POST['labor_tab']) : 'workers';
if ($laborTab === '') $laborTab = 'workers';
$workerSort = isset($_POST['worker_sort']) ? trim((string)$_POST['worker_sort']) : 'company';
$workerSortAllowed = array('company', 'name', 'allocation', 'phone', 'address', 'job_type', 'wage', 'bank_account', 'bank_name', 'account_holder', 'remark');
if (!in_array($workerSort, $workerSortAllowed, true)) $workerSort = 'company';
$workerSortDir = isset($_POST['worker_sort_dir']) && (string)$_POST['worker_sort_dir'] === 'desc' ? 'desc' : 'asc';

$redirect = '?r=공사&pid=' . $projectId . '&tab=labor&labor_tab=' . urlencode($laborTab);
if ($month !== '') {
    $redirect .= '&month=' . urlencode($month);
}
$redirect .= '&worker_sort=' . urlencode($workerSort) . '&worker_sort_dir=' . urlencode($workerSortDir);

if ($projectId <= 0) {
    flash_set('error', '프로젝트 정보가 올바르지 않습니다.');
    header('Location: ' . $redirect);
    exit;
}

if ($directMemberId <= 0 && $workforceWorkerId <= 0 && $manualName === '') {
    flash_set('error', '추가할 인원 정보를 입력하세요.');
    header('Location: ' . $redirect);
    exit;
}

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error', 'DB 연결 실패');
    header('Location: ' . $redirect);
    exit;
}

try {
    if (!cpms_ensure_project_labor_workers_table($pdo)) {
        flash_set('error', '인원 목록 테이블을 생성할 수 없습니다.');
        header('Location: ' . $redirect);
        exit;
    }

    $now = date('Y-m-d H:i:s');
    $source = 'manual';
    $name = $manualName;
    $companyName = $manualCompanyName === '' ? '창명건설' : $manualCompanyName;
    $workforcePayload = null;
    $savedWorkerId = 0;

    if ($workforceWorkerId > 0) {
        if (!cpms_labor_load_workforce_services()) {
            flash_set('error', '인력관리 서비스를 찾을 수 없습니다.');
            header('Location: ' . $redirect);
            exit;
        }

        $workerRepo = new WorkerRepository($pdo);
        $masterWorker = $workerRepo->getById($workforceWorkerId, true);
        if (!$masterWorker || !is_array($masterWorker)) {
            flash_set('error', '인력관리 등록자를 찾을 수 없습니다.');
            header('Location: ' . $redirect);
            exit;
        }

        $source = 'workforce';
        $workforcePayload = cpms_labor_worker_payload_from_workforce($masterWorker, 'workforce', 'matched');
        $name = isset($workforcePayload['name']) ? trim((string)$workforcePayload['name']) : '';
        $companyName = isset($workforcePayload['company_name']) ? trim((string)$workforcePayload['company_name']) : '';
    }

    if ($directMemberId > 0) {
        if (!cpms_table_exists_labor($pdo, 'direct_team_members')) {
            flash_set('error', '직영팀 명부 테이블이 없습니다.');
            header('Location: ' . $redirect);
            exit;
        }

        $st = $pdo->prepare("SELECT * FROM direct_team_members WHERE id = :id LIMIT 1");
        $st->bindValue(':id', $directMemberId, PDO::PARAM_INT);
        $st->execute();
        $member = $st->fetch();

        if (!$member || !isset($member['name']) || trim((string)$member['name']) === '') {
            flash_set('error', '직영팀 인원을 찾을 수 없습니다.');
            header('Location: ' . $redirect);
            exit;
        }

        $source = 'direct';
        $name = trim((string)$member['name']);
    }

    if ($name === '') {
        flash_set('error', '이름을 입력하세요.');
        header('Location: ' . $redirect);
        exit;
    }

    $stCheck = $pdo->prepare("SELECT id FROM cpms_project_labor_workers WHERE project_id = :pid AND name = :name LIMIT 1");
    $stCheck->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $stCheck->bindValue(':name', $name);
    $stCheck->execute();
    $existingId = (int)$stCheck->fetchColumn();

    if ($existingId > 0) {
        if ($source === 'direct') {
            $stUp = $pdo->prepare("UPDATE cpms_project_labor_workers
                                   SET direct_member_id = :mid,
                                       source = 'direct',
                                       is_deleted = 0,
                                       updated_at = :now
                                   WHERE id = :id");
            $stUp->bindValue(':mid', $directMemberId, PDO::PARAM_INT);
            $stUp->bindValue(':now', $now);
            $stUp->bindValue(':id', $existingId, PDO::PARAM_INT);
            $stUp->execute();
            $savedWorkerId = $existingId;
        } else if ($source === 'workforce' && is_array($workforcePayload)) {
            $stUp = $pdo->prepare("UPDATE cpms_project_labor_workers
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
                                       is_deleted = 0,
                                       updated_at = :now
                                   WHERE id = :id");
            $stUp->bindValue(':worker_id', (int)$workforcePayload['worker_id'], PDO::PARAM_INT);
            $stUp->bindValue(':worker_name_snapshot', $workforcePayload['worker_name_snapshot']);
            $stUp->bindValue(':agency_name_snapshot', $workforcePayload['agency_name_snapshot']);
            $stUp->bindValue(':job_type_snapshot', $workforcePayload['job_type_snapshot']);
            $stUp->bindValue(':daily_wage_snapshot', (int)$workforcePayload['daily_wage_snapshot'], PDO::PARAM_INT);
            $stUp->bindValue(':source_type', $workforcePayload['source_type']);
            $stUp->bindValue(':matched_status', $workforcePayload['matched_status']);
            $stUp->bindValue(':resident_no', $workforcePayload['resident_no']);
            $stUp->bindValue(':phone', $workforcePayload['phone']);
            $stUp->bindValue(':address', $workforcePayload['address']);
            $stUp->bindValue(':deposit_rate', (int)$workforcePayload['deposit_rate'], PDO::PARAM_INT);
            $stUp->bindValue(':bank_account', $workforcePayload['bank_account']);
            $stUp->bindValue(':bank_name', $workforcePayload['bank_name']);
            $stUp->bindValue(':account_holder', $workforcePayload['account_holder']);
            $stUp->bindValue(':company_name', $workforcePayload['company_name']);
            $stUp->bindValue(':now', $now);
            $stUp->bindValue(':id', $existingId, PDO::PARAM_INT);
            $stUp->execute();
            $savedWorkerId = $existingId;
        } else {
            $stUp = $pdo->prepare("UPDATE cpms_project_labor_workers
                                   SET source = 'manual',
                                       direct_member_id = NULL,
                                       company_name = :company_name,
                                       is_deleted = 0,
                                       updated_at = :now
                                   WHERE id = :id");
            $stUp->bindValue(':company_name', $companyName, PDO::PARAM_STR);
            $stUp->bindValue(':now', $now);
            $stUp->bindValue(':id', $existingId, PDO::PARAM_INT);
            $stUp->execute();
            $savedWorkerId = $existingId;
        }
    } else {
        if ($source === 'direct') {
            $stIns = $pdo->prepare("INSERT INTO cpms_project_labor_workers
                                    (project_id, name, source, direct_member_id, is_deleted, created_at, updated_at)
                                    VALUES (:pid, :name, 'direct', :mid, 0, :now, :now)");
            $stIns->bindValue(':pid', $projectId, PDO::PARAM_INT);
            $stIns->bindValue(':name', $name);
            $stIns->bindValue(':mid', $directMemberId, PDO::PARAM_INT);
            $stIns->bindValue(':now', $now);
            $stIns->execute();
            $savedWorkerId = (int)$pdo->lastInsertId();
        } else if ($source === 'workforce' && is_array($workforcePayload)) {
            $stIns = $pdo->prepare("INSERT INTO cpms_project_labor_workers
                                    (project_id, name, source, direct_member_id, worker_id,
                                     worker_name_snapshot, agency_name_snapshot, job_type_snapshot, daily_wage_snapshot,
                                     source_type, matched_status, resident_no, phone, address, deposit_rate,
                                     bank_account, bank_name, account_holder, company_name,
                                     is_deleted, created_at, updated_at)
                                    VALUES (:pid, :name, 'workforce', NULL, :worker_id,
                                     :worker_name_snapshot, :agency_name_snapshot, :job_type_snapshot, :daily_wage_snapshot,
                                     :source_type, :matched_status, :resident_no, :phone, :address, :deposit_rate,
                                     :bank_account, :bank_name, :account_holder, :company_name,
                                     0, :now, :now)");
            $stIns->bindValue(':pid', $projectId, PDO::PARAM_INT);
            $stIns->bindValue(':name', $name);
            $stIns->bindValue(':worker_id', (int)$workforcePayload['worker_id'], PDO::PARAM_INT);
            $stIns->bindValue(':worker_name_snapshot', $workforcePayload['worker_name_snapshot']);
            $stIns->bindValue(':agency_name_snapshot', $workforcePayload['agency_name_snapshot']);
            $stIns->bindValue(':job_type_snapshot', $workforcePayload['job_type_snapshot']);
            $stIns->bindValue(':daily_wage_snapshot', (int)$workforcePayload['daily_wage_snapshot'], PDO::PARAM_INT);
            $stIns->bindValue(':source_type', $workforcePayload['source_type']);
            $stIns->bindValue(':matched_status', $workforcePayload['matched_status']);
            $stIns->bindValue(':resident_no', $workforcePayload['resident_no']);
            $stIns->bindValue(':phone', $workforcePayload['phone']);
            $stIns->bindValue(':address', $workforcePayload['address']);
            $stIns->bindValue(':deposit_rate', (int)$workforcePayload['deposit_rate'], PDO::PARAM_INT);
            $stIns->bindValue(':bank_account', $workforcePayload['bank_account']);
            $stIns->bindValue(':bank_name', $workforcePayload['bank_name']);
            $stIns->bindValue(':account_holder', $workforcePayload['account_holder']);
            $stIns->bindValue(':company_name', $workforcePayload['company_name']);
            $stIns->bindValue(':now', $now);
            $stIns->execute();
            $savedWorkerId = (int)$pdo->lastInsertId();
        } else {
            $stIns = $pdo->prepare("INSERT INTO cpms_project_labor_workers
                                    (project_id, name, source, direct_member_id, company_name, is_deleted, created_at, updated_at)
                                    VALUES (:pid, :name, 'manual', NULL, :company_name, 0, :now, :now)");
            $stIns->bindValue(':pid', $projectId, PDO::PARAM_INT);
            $stIns->bindValue(':name', $name);
            $stIns->bindValue(':company_name', $companyName, PDO::PARAM_STR);
            $stIns->bindValue(':now', $now);
            $stIns->execute();
            $savedWorkerId = (int)$pdo->lastInsertId();
        }
    }

    if ($savedWorkerId > 0 && preg_match('/^\d{4}-\d{2}$/', $month)) {
        cpms_assign_project_labor_worker_month($pdo, $projectId, $savedWorkerId, $month);
    }

    if ($source === 'direct') {
        flash_set('success', '직영팀 인원이 추가되었습니다.');
    } else if ($source === 'workforce') {
        flash_set('success', '인력관리 등록자를 가져왔습니다.');
    } else {
        flash_set('success', '인원이 추가되었습니다.');
    }
    header('Location: ' . $redirect);
    exit;

} catch (Exception $e) {
    flash_set('error', '추가 실패: ' . $e->getMessage());
    header('Location: ' . $redirect);
    exit;
}
