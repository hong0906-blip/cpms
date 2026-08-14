<?php
/**
 * C:\www\cpms\app\views\construction\roles_save.php
 * - 공사: 프로젝트 담당(안전/품질/현장) 저장(POST)
 *
 * 권한:
 * - 임원(executive) 또는 공사부서(공사)만
 *
 * PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';

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
    flash_set('error','보안 토큰이 유효하지 않습니다.');
    header('Location: ?r=공사');
    exit;
}

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$siteId    = isset($_POST['site_employee_id']) ? (int)$_POST['site_employee_id'] : 0;
$safetyId  = isset($_POST['safety_employee_id']) ? (int)$_POST['safety_employee_id'] : 0;
$qualityId = isset($_POST['quality_employee_id']) ? (int)$_POST['quality_employee_id'] : 0;
$postedSubManagerIds = isset($_POST['sub_manager_ids']) && is_array($_POST['sub_manager_ids']) ? $_POST['sub_manager_ids'] : array();
$subManagerIds = array();
$seenSubManagerIds = array();
foreach ($postedSubManagerIds as $postedSubManagerId) {
    $subManagerId = (int)$postedSubManagerId;
    if ($subManagerId <= 0 || $subManagerId === $siteId || isset($seenSubManagerIds[$subManagerId])) continue;
    $seenSubManagerIds[$subManagerId] = true;
    $subManagerIds[] = $subManagerId;
}

if (count($subManagerIds) > 4) {
    flash_set('error', '서브 담당자는 최대 4명까지 지정할 수 있습니다.');
    header('Location: ?r=공사&pid='.$projectId.'&tab=roles');
    exit;
}

if ($projectId <= 0) {
    flash_set('error','프로젝트 정보가 올바르지 않습니다.');
    header('Location: ?r=공사');
    exit;
}

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error','DB 연결 실패');
    header('Location: ?r=공사&pid='.$projectId.'&tab=roles');
    exit;
}

$roleEmployeeIds = array();
foreach (array_merge(array($siteId, $safetyId, $qualityId), $subManagerIds) as $roleEmployeeId) {
    $roleEmployeeId = (int)$roleEmployeeId;
    if ($roleEmployeeId > 0) $roleEmployeeIds[$roleEmployeeId] = $roleEmployeeId;
}
if (count($roleEmployeeIds) > 0) {
    $roleEmployeeIdList = implode(',', array_map('intval', array_values($roleEmployeeIds)));
    $stActiveRoleEmployees = $pdo->query("SELECT id FROM employees WHERE is_active = 1 AND id IN (" . $roleEmployeeIdList . ")");
    $activeRoleEmployeeRows = $stActiveRoleEmployees ? $stActiveRoleEmployees->fetchAll(PDO::FETCH_ASSOC) : array();
    $activeRoleEmployeeMap = array();
    foreach ($activeRoleEmployeeRows as $activeRoleEmployeeRow) $activeRoleEmployeeMap[(int)$activeRoleEmployeeRow['id']] = true;
    foreach ($roleEmployeeIds as $roleEmployeeId) {
        if (!isset($activeRoleEmployeeMap[(int)$roleEmployeeId])) {
            flash_set('error', '퇴직한 임직원은 공사 담당자로 지정할 수 없습니다.');
            header('Location: ?r=공사&pid='.$projectId.'&tab=roles');
            exit;
        }
    }
}

try {
    $pdo->beginTransaction();
    $st = $pdo->prepare("SELECT project_id FROM cpms_construction_roles WHERE project_id = :pid LIMIT 1");
    $st->bindValue(':pid', $projectId, \PDO::PARAM_INT);
    $st->execute();
    $exists = $st->fetchColumn() ? true : false;

    if ($exists) {
        $up = $pdo->prepare("UPDATE cpms_construction_roles
                             SET site_employee_id = :site,
                                 safety_employee_id = :safety,
                                 quality_employee_id = :quality
                             WHERE project_id = :pid");
        $up->bindValue(':site', $siteId > 0 ? $siteId : null, $siteId > 0 ? \PDO::PARAM_INT : \PDO::PARAM_NULL);
        $up->bindValue(':safety', $safetyId > 0 ? $safetyId : null, $safetyId > 0 ? \PDO::PARAM_INT : \PDO::PARAM_NULL);
        $up->bindValue(':quality', $qualityId > 0 ? $qualityId : null, $qualityId > 0 ? \PDO::PARAM_INT : \PDO::PARAM_NULL);
        $up->bindValue(':pid', $projectId, \PDO::PARAM_INT);
        $up->execute();
    } else {
        $ins = $pdo->prepare("INSERT INTO cpms_construction_roles(project_id, site_employee_id, safety_employee_id, quality_employee_id)
                              VALUES(:pid, :site, :safety, :quality)");
        $ins->bindValue(':pid', $projectId, \PDO::PARAM_INT);
        $ins->bindValue(':site', $siteId > 0 ? $siteId : null, $siteId > 0 ? \PDO::PARAM_INT : \PDO::PARAM_NULL);
        $ins->bindValue(':safety', $safetyId > 0 ? $safetyId : null, $safetyId > 0 ? \PDO::PARAM_INT : \PDO::PARAM_NULL);
        $ins->bindValue(':quality', $qualityId > 0 ? $qualityId : null, $qualityId > 0 ? \PDO::PARAM_INT : \PDO::PARAM_NULL);
        $ins->execute();
    }

    // 공무 섹션과 같은 프로젝트 멤버 데이터를 사용하여 메인/서브 담당자를 양방향 연동한다.
    $deleteMain = $pdo->prepare("DELETE FROM cpms_project_members WHERE project_id = :pid AND LOWER(TRIM(role)) = 'main'");
    $deleteMain->bindValue(':pid', $projectId, \PDO::PARAM_INT);
    $deleteMain->execute();
    if ($siteId > 0) {
        $insertMember = $pdo->prepare("INSERT INTO cpms_project_members(project_id, employee_id, role) VALUES(:pid, :eid, :role)");
        $insertMember->bindValue(':pid', $projectId, \PDO::PARAM_INT);
        $insertMember->bindValue(':eid', $siteId, \PDO::PARAM_INT);
        $insertMember->bindValue(':role', 'main');
        $insertMember->execute();
    } else {
        $insertMember = null;
    }

    $deleteSubs = $pdo->prepare("DELETE FROM cpms_project_members WHERE project_id = :pid AND LOWER(TRIM(role)) = 'sub'");
    $deleteSubs->bindValue(':pid', $projectId, \PDO::PARAM_INT);
    $deleteSubs->execute();
    if ($insertMember === null) {
        $insertMember = $pdo->prepare("INSERT INTO cpms_project_members(project_id, employee_id, role) VALUES(:pid, :eid, :role)");
    }
    foreach ($subManagerIds as $subManagerId) {
        $insertMember->bindValue(':pid', $projectId, \PDO::PARAM_INT);
        $insertMember->bindValue(':eid', $subManagerId, \PDO::PARAM_INT);
        $insertMember->bindValue(':role', 'sub');
        $insertMember->execute();
    }

    $pdo->commit();

    flash_set('success','담당 지정이 저장되었습니다.');
    header('Location: ?r=공사&pid='.$projectId.'&tab=roles');
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash_set('error','저장 실패: '.$e->getMessage());
    header('Location: ?r=공사&pid='.$projectId.'&tab=roles');
    exit;
}
