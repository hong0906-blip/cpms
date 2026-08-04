<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../services/PublicAffairsCollaborationService.php';
require_once __DIR__ . '/../../services/AiProjectTypeService.php';

use App\Core\Auth;
use App\Core\Db;

if (!function_exists('cpms_project_update_fail_redirect')) {
function cpms_project_update_fail_redirect($projectId, $message) {
    $projectId = (int)$projectId;
    flash_set('error', '수정 실패: ' . $message);
    if ($projectId > 0) {
        header('Location: ?r=project/detail&id=' . $projectId . '&edit=1');
    } else {
        header('Location: ?r=공무');
    }
    exit;
}
}

if (!function_exists('cpms_project_update_find_existing_main_manager_id')) {
function cpms_project_update_find_existing_main_manager_id($pdo, $projectId) {
    if (!$pdo || (int)$projectId <= 0) return 0;
    try {
        $st = $pdo->prepare("SELECT employee_id FROM cpms_project_members WHERE project_id = :pid AND role = 'main' ORDER BY employee_id ASC LIMIT 1");
        $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
        $st->execute();
        return (int)$st->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}
}

if (!function_exists('cpms_project_update_normalize_status')) {
function cpms_project_update_normalize_status($status) {
    $status = trim((string)$status);
    if ($status === '' || $status === '진행 중') return '진행중';
    if ($status === '대기중' || $status === '입찰검토' || $status === '가제' || $status === '정식전환대기') return '입찰 진행중';
    if (in_array($status, array('입찰 진행중', '계약중', '진행중', '정산완료'), true)) return $status;
    return '진행중';
}}

if (!function_exists('cpms_project_update_date_or_null')) {
function cpms_project_update_date_or_null($date) {
    $date = trim((string)$date);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return $date;
    return null;
}}

if (!function_exists('cpms_project_update_column_exists')) {
function cpms_project_update_column_exists($pdo, $column) {
    if (!$pdo) return false;
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `cpms_projects` LIKE :col");
        $st->bindValue(':col', (string)$column);
        $st->execute();
        return $st->fetch(PDO::FETCH_ASSOC) ? true : false;
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_project_update_ensure_settlement_column')) {
function cpms_project_update_ensure_settlement_column($pdo) {
    if (!$pdo) return false;
    if (cpms_project_update_column_exists($pdo, 'settlement_completed_at')) return true;
    try {
        $pdo->exec("ALTER TABLE `cpms_projects` ADD COLUMN `settlement_completed_at` DATE NULL AFTER `status`");
    } catch (Exception $e) {
        try {
            $pdo->exec("ALTER TABLE `cpms_projects` ADD COLUMN `settlement_completed_at` DATE NULL");
        } catch (Exception $e2) {
        }
    }
    return cpms_project_update_column_exists($pdo, 'settlement_completed_at');
}}

if (!Auth::check()) { header('Location: ?r=login'); exit; }

$role = Auth::userRole();
$dept = Auth::userDepartment();
$allowed = ($role === 'executive' || $dept === '공무' || $dept === '관리' || $dept === '관리부');
if (!$allowed) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $redirectProjectId = 0;
    if (isset($_REQUEST['project_id'])) $redirectProjectId = (int)$_REQUEST['project_id'];
    if ($redirectProjectId <= 0 && isset($_GET['id'])) $redirectProjectId = (int)$_GET['id'];
    if ($redirectProjectId > 0) {
        header('Location: ?r=project/detail&id=' . $redirectProjectId . '&edit=1');
        exit;
    }
    cpms_project_update_fail_redirect(0, '잘못된 요청입니다.');
}

$csrf = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
if (!csrf_check($csrf)) {
    cpms_project_update_fail_redirect(isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0, '보안 토큰이 유효하지 않습니다.');
}

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
if ($projectId <= 0) {
    cpms_project_update_fail_redirect(0, '프로젝트 ID가 없습니다.');
}
error_log('[project_update] project_id=' . $projectId);

$name = isset($_POST['name']) ? trim((string)$_POST['name']) : '';
$client = isset($_POST['client']) ? trim((string)$_POST['client']) : '';
$contractor = isset($_POST['contractor']) ? trim((string)$_POST['contractor']) : '';
$status = isset($_POST['status']) ? trim((string)$_POST['status']) : '';
$status = cpms_project_update_normalize_status($status);
$location = isset($_POST['location']) ? trim((string)$_POST['location']) : '';
$startDate = isset($_POST['start_date']) ? trim((string)$_POST['start_date']) : '';
$endDate = isset($_POST['end_date']) ? trim((string)$_POST['end_date']) : '';
$settlementCompletedAt = isset($_POST['settlement_completed_at']) ? trim((string)$_POST['settlement_completed_at']) : '';
$contractAmount = isset($_POST['contract_amount']) ? trim((string)$_POST['contract_amount']) : '';
$hasProjectTypeInput = array_key_exists('project_type_id', $_POST);
$projectTypeId = $hasProjectTypeInput ? (int)$_POST['project_type_id'] : 0;
$mainManagerId = isset($_POST['main_manager_id']) ? (int)$_POST['main_manager_id'] : 0;
$postedSubManagerIds = isset($_POST['sub_manager_ids']) && is_array($_POST['sub_manager_ids']) ? $_POST['sub_manager_ids'] : array();
$subManagerIds = array();
$seenSubManagerIds = array();
foreach ($postedSubManagerIds as $postedSubManagerId) {
    $subManagerId = (int)$postedSubManagerId;
    if ($subManagerId <= 0 || $subManagerId === $mainManagerId || isset($seenSubManagerIds[$subManagerId])) continue;
    $seenSubManagerIds[$subManagerId] = true;
    $subManagerIds[] = $subManagerId;
}
if ($name === '') {
    cpms_project_update_fail_redirect($projectId, '프로젝트명이 없습니다.');
}

$pdo = Db::pdo();
if (!$pdo) {
    cpms_project_update_fail_redirect($projectId, 'DB 연결에 실패했습니다.');
}
$hasSettlementColumn = cpms_project_update_ensure_settlement_column($pdo);

if ($mainManagerId <= 0) {
    $existingMainManagerId = cpms_project_update_find_existing_main_manager_id($pdo, $projectId);
    if ($existingMainManagerId > 0) {
        $mainManagerId = $existingMainManagerId;
        error_log('[project_update] main_manager_id missing, fallback to existing main manager: ' . $mainManagerId);
    }
}

// 메인 담당자가 기존 값으로 보완된 경우에도 메인/서브 중복을 제거한다.
$normalizedSubManagerIds = array();
foreach ($subManagerIds as $subManagerId) {
    if ((int)$subManagerId === $mainManagerId) continue;
    $normalizedSubManagerIds[] = (int)$subManagerId;
}
$subManagerIds = $normalizedSubManagerIds;
if (count($subManagerIds) > 4) {
    cpms_project_update_fail_redirect($projectId, '서브 담당자는 최대 4명까지 지정할 수 있습니다.');
}

$contractAmountVal = null;
if ($contractAmount !== '') {
    $cleanAmount = preg_replace('/[^0-9]/', '', $contractAmount);
    if ($cleanAmount !== '') $contractAmountVal = (int)$cleanAmount;
}
$startDateVal = ($startDate !== '') ? $startDate : null;
$endDateVal = ($endDate !== '') ? $endDate : null;

try {
    $stExists = $pdo->prepare("SELECT * FROM cpms_projects WHERE id = :id LIMIT 1");
    $stExists->bindValue(':id', $projectId, PDO::PARAM_INT);
    $stExists->execute();
    $existingProject = $stExists->fetch(PDO::FETCH_ASSOC);
    if (!$existingProject) {
        cpms_project_update_fail_redirect($projectId, '프로젝트를 찾을 수 없습니다.');
    }
    $collabMetaStore = cpms_public_affairs_collab_load_project_meta();
    if (cpms_public_affairs_collab_is_draft_project($existingProject, $collabMetaStore)) {
        // 공무 협업툴 가제 프로젝트: 일반 수정에서는 "(가제)" 상태를 유지하고, 정식 전환 액션에서만 prefix를 제거한다.
        $name = cpms_public_affairs_collab_draft_project_name($name);
    }
    if ($mainManagerId <= 0) {
        cpms_project_update_fail_redirect($projectId, '공사 담당자가 선택되지 않았고 기존 담당자도 없습니다.');
    }

    $settlementCompletedAtVal = null;
    if ($hasSettlementColumn && $status === '정산완료') {
        $settlementCompletedAtVal = cpms_project_update_date_or_null($settlementCompletedAt);
        if ($settlementCompletedAtVal === null && isset($existingProject['status']) && cpms_project_update_normalize_status($existingProject['status']) === '정산완료') {
            $settlementCompletedAtVal = cpms_project_update_date_or_null(isset($existingProject['settlement_completed_at']) ? $existingProject['settlement_completed_at'] : '');
        }
        if ($settlementCompletedAtVal === null) $settlementCompletedAtVal = cpms_project_update_date_or_null($endDateVal);
        if ($settlementCompletedAtVal === null) $settlementCompletedAtVal = date('Y-m-d');
    }

    $pdo->beginTransaction();

    $settlementSetSql = $hasSettlementColumn ? ",
               settlement_completed_at = :settlement_completed_at" : "";
    $stProject = $pdo->prepare("
        UPDATE cpms_projects
           SET name = :name,
               client = :client,
               contractor = :contractor,
               status = :status,
               location = :location,
               start_date = :start_date,
               end_date = :end_date,
               contract_amount = :contract_amount" . $settlementSetSql . "
         WHERE id = :id
    ");
    $stProject->bindValue(':name', $name);
    $stProject->bindValue(':client', $client);
    $stProject->bindValue(':contractor', $contractor);
    $stProject->bindValue(':status', $status);
    $stProject->bindValue(':location', $location);
    $stProject->bindValue(':start_date', $startDateVal);
    $stProject->bindValue(':end_date', $endDateVal);
    $stProject->bindValue(':contract_amount', $contractAmountVal);
    if ($hasSettlementColumn) {
        if ($settlementCompletedAtVal === null) $stProject->bindValue(':settlement_completed_at', null, PDO::PARAM_NULL);
        else $stProject->bindValue(':settlement_completed_at', $settlementCompletedAtVal);
    }
    $stProject->bindValue(':id', $projectId, PDO::PARAM_INT);
    $stProject->execute();

    $stDelete = $pdo->prepare("DELETE FROM cpms_project_members WHERE project_id = :pid");
    $stDelete->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $stDelete->execute();

    $stMember = $pdo->prepare("INSERT INTO cpms_project_members(project_id, employee_id, role) VALUES(:pid, :eid, :role)");
    $stMember->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $stMember->bindValue(':eid', $mainManagerId, PDO::PARAM_INT);
    $stMember->bindValue(':role', 'main');
    $stMember->execute();

    $seen = array();
    $seen[$mainManagerId] = 1;
    foreach ($subManagerIds as $sid) {
        $employeeId = (int)$sid;
        if ($employeeId <= 0) continue;
        if (isset($seen[$employeeId])) continue;
        $seen[$employeeId] = 1;

        $stMember->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $stMember->bindValue(':eid', $employeeId, PDO::PARAM_INT);
        $stMember->bindValue(':role', 'sub');
        $stMember->execute();
    }

    try {
        $stRole = $pdo->prepare("SELECT project_id FROM cpms_construction_roles WHERE project_id = :pid LIMIT 1");
        $stRole->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $stRole->execute();
        if ($stRole->fetch()) {
            $stRoleUpdate = $pdo->prepare("UPDATE cpms_construction_roles SET site_employee_id = :sid WHERE project_id = :pid");
            $stRoleUpdate->bindValue(':sid', $mainManagerId, PDO::PARAM_INT);
            $stRoleUpdate->bindValue(':pid', $projectId, PDO::PARAM_INT);
            $stRoleUpdate->execute();
        } else {
            $stRoleInsert = $pdo->prepare("INSERT INTO cpms_construction_roles(project_id, site_employee_id) VALUES(:pid, :sid)");
            $stRoleInsert->bindValue(':pid', $projectId, PDO::PARAM_INT);
            $stRoleInsert->bindValue(':sid', $mainManagerId, PDO::PARAM_INT);
            $stRoleInsert->execute();
        }
    } catch (Exception $eRole) {
    }

    if ($hasProjectTypeInput && \App\Services\AiProjectTypeService::isInstalled($pdo)) {
        $typeResult = \App\Services\AiProjectTypeService::assignProject($pdo, $projectId, $projectTypeId, '프로젝트 수정 화면에서 지정');
        if (empty($typeResult['ok'])) throw new Exception(isset($typeResult['message']) ? $typeResult['message'] : '현장유형 저장 실패');
    }
    $pdo->commit();
    if (isset($_SESSION['_company_profit_cache'])) unset($_SESSION['_company_profit_cache']);

    flash_set('success', '프로젝트 정보가 수정되었습니다.');
    header('Location: ?r=project/detail&id=' . $projectId);
    exit;
} catch (Exception $e) {
    error_log('[project_update] error project_id=' . $projectId . ' main_manager_id=' . $mainManagerId . ' message=' . $e->getMessage());
    if ($pdo->inTransaction()) $pdo->rollBack();
    cpms_project_update_fail_redirect($projectId, $e->getMessage());
}
