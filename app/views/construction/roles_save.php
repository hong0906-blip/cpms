<?php
/**
 * C:\www\cpms\app\views\construction\roles_save.php
 * - 공사: 프로젝트 담당(공사/안전/품질) 저장(POST)
 * - 공사: 메인 1 + 서브 최대 4
 * - 안전: 메인 1 + 서브 최대 2
 * - 품질: 메인 1 + 서브 최대 2
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

// 공사 서브: 기존과 동일하게 최대 4명.
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
    flash_set('error', '공사 서브 담당자는 최대 4명까지 지정할 수 있습니다.');
    header('Location: ?r=공사&pid='.$projectId.'&tab=roles');
    exit;
}

// 안전 서브: 메인 제외, 중복 제거, 최대 2명.
$postedSafetySubIds = isset($_POST['safety_sub_employee_ids']) && is_array($_POST['safety_sub_employee_ids']) ? $_POST['safety_sub_employee_ids'] : array();
$safetySubIds = array();
$seenSafetySubIds = array();
foreach ($postedSafetySubIds as $postedSafetySubId) {
    $safetySubId = (int)$postedSafetySubId;
    if ($safetySubId <= 0 || $safetySubId === $safetyId || isset($seenSafetySubIds[$safetySubId])) continue;
    $seenSafetySubIds[$safetySubId] = true;
    $safetySubIds[] = $safetySubId;
}
if (count($safetySubIds) > 2) {
    flash_set('error', '안전 서브 담당자는 최대 2명까지 지정할 수 있습니다.');
    header('Location: ?r=공사&pid='.$projectId.'&tab=roles');
    exit;
}

// 품질 서브: 메인 제외, 중복 제거, 최대 2명.
$postedQualitySubIds = isset($_POST['quality_sub_employee_ids']) && is_array($_POST['quality_sub_employee_ids']) ? $_POST['quality_sub_employee_ids'] : array();
$qualitySubIds = array();
$seenQualitySubIds = array();
foreach ($postedQualitySubIds as $postedQualitySubId) {
    $qualitySubId = (int)$postedQualitySubId;
    if ($qualitySubId <= 0 || $qualitySubId === $qualityId || isset($seenQualitySubIds[$qualitySubId])) continue;
    $seenQualitySubIds[$qualitySubId] = true;
    $qualitySubIds[] = $qualitySubId;
}
if (count($qualitySubIds) > 2) {
    flash_set('error', '품질 서브 담당자는 최대 2명까지 지정할 수 있습니다.');
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

// 지정 대상 전체가 재직자인지 확인하고, 안전/품질 서브는 해당 부서 직원만 허용한다.
$roleEmployeeIds = array();
foreach (array_merge(array($siteId, $safetyId, $qualityId), $subManagerIds, $safetySubIds, $qualitySubIds) as $roleEmployeeId) {
    $roleEmployeeId = (int)$roleEmployeeId;
    if ($roleEmployeeId > 0) $roleEmployeeIds[$roleEmployeeId] = $roleEmployeeId;
}

$activeRoleEmployeeMap = array();
if (count($roleEmployeeIds) > 0) {
    $roleEmployeeIdList = implode(',', array_map('intval', array_values($roleEmployeeIds)));
    $stActiveRoleEmployees = $pdo->query("SELECT id, department FROM employees WHERE is_active = 1 AND id IN (" . $roleEmployeeIdList . ")");
    $activeRoleEmployeeRows = $stActiveRoleEmployees ? $stActiveRoleEmployees->fetchAll(PDO::FETCH_ASSOC) : array();
    foreach ($activeRoleEmployeeRows as $activeRoleEmployeeRow) {
        $activeRoleEmployeeMap[(int)$activeRoleEmployeeRow['id']] = isset($activeRoleEmployeeRow['department']) ? (string)$activeRoleEmployeeRow['department'] : '';
    }
    foreach ($roleEmployeeIds as $roleEmployeeId) {
        if (!isset($activeRoleEmployeeMap[(int)$roleEmployeeId])) {
            flash_set('error', '퇴직한 임직원은 담당자로 지정할 수 없습니다.');
            header('Location: ?r=공사&pid='.$projectId.'&tab=roles');
            exit;
        }
    }
}

foreach ($safetySubIds as $safetySubId) {
    $safetySubDept = isset($activeRoleEmployeeMap[$safetySubId]) ? $activeRoleEmployeeMap[$safetySubId] : '';
    $safetySubDept = method_exists('App\\Core\\Auth', 'normalizeDepartmentValue') ? Auth::normalizeDepartmentValue($safetySubDept) : $safetySubDept;
    if ($safetySubDept !== '안전') {
        flash_set('error', '안전 서브 담당자는 안전부서 재직자만 지정할 수 있습니다.');
        header('Location: ?r=공사&pid='.$projectId.'&tab=roles');
        exit;
    }
}

foreach ($qualitySubIds as $qualitySubId) {
    $qualitySubDept = isset($activeRoleEmployeeMap[$qualitySubId]) ? $activeRoleEmployeeMap[$qualitySubId] : '';
    $qualitySubDept = method_exists('App\\Core\\Auth', 'normalizeDepartmentValue') ? Auth::normalizeDepartmentValue($qualitySubDept) : $qualitySubDept;
    if ($qualitySubDept !== '품질') {
        flash_set('error', '품질 서브 담당자는 품질부서 재직자만 지정할 수 있습니다.');
        header('Location: ?r=공사&pid='.$projectId.'&tab=roles');
        exit;
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

    // 공무 섹션과 같은 프로젝트 멤버 데이터를 사용하여 공사 메인/서브 담당자를 양방향 연동한다.
    $deleteMain = $pdo->prepare("DELETE FROM cpms_project_members WHERE project_id = :pid AND LOWER(TRIM(role)) = 'main'");
    $deleteMain->bindValue(':pid', $projectId, \PDO::PARAM_INT);
    $deleteMain->execute();

    $insertMember = $pdo->prepare("INSERT INTO cpms_project_members(project_id, employee_id, role) VALUES(:pid, :eid, :role)");

    if ($siteId > 0) {
        $insertMember->bindValue(':pid', $projectId, \PDO::PARAM_INT);
        $insertMember->bindValue(':eid', $siteId, \PDO::PARAM_INT);
        $insertMember->bindValue(':role', 'main');
        $insertMember->execute();
    }

    $deleteSubs = $pdo->prepare("DELETE FROM cpms_project_members WHERE project_id = :pid AND LOWER(TRIM(role)) = 'sub'");
    $deleteSubs->bindValue(':pid', $projectId, \PDO::PARAM_INT);
    $deleteSubs->execute();
    foreach ($subManagerIds as $subManagerId) {
        $insertMember->bindValue(':pid', $projectId, \PDO::PARAM_INT);
        $insertMember->bindValue(':eid', $subManagerId, \PDO::PARAM_INT);
        $insertMember->bindValue(':role', 'sub');
        $insertMember->execute();
    }

    /*
     * 안전/품질 서브는 기존 cpms_project_members에 별도 role로 저장한다.
     * 기존 role 컬럼이 VARCHAR(10)이므로 safety_sub(10), qual_sub(8)를 사용한다.
     * 이 방식은 DB 컬럼 추가 없이 기존 데이터와 완전히 분리된다.
     */
    $deleteSpecialSubs = $pdo->prepare("DELETE FROM cpms_project_members WHERE project_id = :pid AND LOWER(TRIM(role)) IN ('safety_sub', 'qual_sub')");
    $deleteSpecialSubs->bindValue(':pid', $projectId, \PDO::PARAM_INT);
    $deleteSpecialSubs->execute();

    foreach ($safetySubIds as $safetySubId) {
        $insertMember->bindValue(':pid', $projectId, \PDO::PARAM_INT);
        $insertMember->bindValue(':eid', $safetySubId, \PDO::PARAM_INT);
        $insertMember->bindValue(':role', 'safety_sub');
        $insertMember->execute();
    }

    foreach ($qualitySubIds as $qualitySubId) {
        $insertMember->bindValue(':pid', $projectId, \PDO::PARAM_INT);
        $insertMember->bindValue(':eid', $qualitySubId, \PDO::PARAM_INT);
        $insertMember->bindValue(':role', 'qual_sub');
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
