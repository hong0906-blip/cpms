<?php
/**
 * C:\www\cpms\app\views\project\project_save.php
 * - 프로젝트 생성(공사 담당자 1명 + 서브담당자 여러명 저장)
 *
 * ✅ 수정사항:
 * 1) 시공사(contractor) 저장
 * 2) 계약금액(contract_amount) 저장 (예산→계약금액 변경)
 * 3) ✅ 공사 섹션 담당자 미지정 방지:
 *    - cpms_construction_roles.site_employee_id = 메인 담당자 자동 반영(있으면 업데이트, 없으면 생성)
 *
 * PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }

// 권한: 임원 또는 공무/관리
$role = Auth::userRole();
$dept = Auth::userDepartment();
$allowed = ($role === 'executive' || $dept === '공무' || $dept === '관리' || $dept === '관리부');
if (!$allowed) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

$token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
if (!csrf_check($token)) {
    flash_set('error', '보안 토큰이 유효하지 않습니다.');
    header('Location: ?r=공무');
    exit;
}

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error', 'DB 연결 실패');
    header('Location: ?r=공무');
    exit;
}

$action = isset($_POST['action']) ? (string)$_POST['action'] : 'create';
if ($action !== 'create') $action = 'create';

$name = isset($_POST['name']) ? trim((string)$_POST['name']) : '';
$client = isset($_POST['client']) ? trim((string)$_POST['client']) : '';
$contractor = isset($_POST['contractor']) ? trim((string)$_POST['contractor']) : '';
$location = isset($_POST['location']) ? trim((string)$_POST['location']) : '';
$start_date = isset($_POST['start_date']) ? trim((string)$_POST['start_date']) : '';
$end_date = isset($_POST['end_date']) ? trim((string)$_POST['end_date']) : '';
$status = isset($_POST['status']) ? trim((string)$_POST['status']) : '진행 중';
$contract_amount = isset($_POST['contract_amount']) ? trim((string)$_POST['contract_amount']) : '';

$mainManagerId = isset($_POST['main_manager_id']) ? (int)$_POST['main_manager_id'] : 0;
$subManagerIds = isset($_POST['sub_manager_ids']) && is_array($_POST['sub_manager_ids']) ? $_POST['sub_manager_ids'] : array();

if ($name === '' || $mainManagerId <= 0) {
    flash_set('error', '프로젝트명/공사 담당자는 필수입니다.');
    header('Location: ?r=공무');
    exit;
}

// 계약금액 숫자만
$contractAmountVal = null;
if ($contract_amount !== '') {
    $clean = preg_replace('/[^0-9]/', '', $contract_amount);
    if ($clean !== '') $contractAmountVal = (int)$clean;
}

// 날짜 값 검증(간단)
$startVal = ($start_date !== '') ? $start_date : null;
$endVal = ($end_date !== '') ? $end_date : null;

try {
    $pdo->beginTransaction();

    $sql = "INSERT INTO cpms_projects(name, client, contractor, location, start_date, end_date, contract_amount, status)
            VALUES(:name, :client, :contractor, :loc, :sd, :ed, :ca, :status)";
    $st = $pdo->prepare($sql);
    $st->bindValue(':name', $name);
    $st->bindValue(':client', $client);
    $st->bindValue(':contractor', $contractor);
    $st->bindValue(':loc', $location);
    $st->bindValue(':sd', $startVal);
    $st->bindValue(':ed', $endVal);
    $st->bindValue(':ca', $contractAmountVal);
    $st->bindValue(':status', $status);
    $st->execute();

    $projectId = (int)$pdo->lastInsertId();

    // 담당자 저장
    $stMem = $pdo->prepare("INSERT INTO cpms_project_members(project_id, employee_id, role) VALUES(:pid, :eid, :role)");

    // main 1명
    $stMem->bindValue(':pid', $projectId, PDO::PARAM_INT);
    $stMem->bindValue(':eid', $mainManagerId, PDO::PARAM_INT);
    $stMem->bindValue(':role', 'main');
    $stMem->execute();

    // sub 여러명(중복/메인과 동일이면 제외)
    $seen = array();
    $seen[$mainManagerId] = true;

    foreach ($subManagerIds as $sid) {
        $eid = (int)$sid;
        if ($eid <= 0) continue;
        if (isset($seen[$eid])) continue;
        $seen[$eid] = true;

        $stMem->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $stMem->bindValue(':eid', $eid, PDO::PARAM_INT);
        $stMem->bindValue(':role', 'sub');
        $stMem->execute();
    }

    /**
     * ✅ 공사 담당(현장) 자동 반영
     * - 공사 뼈대(MVP)에서 담당자 미지정으로 보이는 문제 방지
     * - cpms_construction_roles 테이블이 있을 때만 반영
     */
    try {
        $chk = $pdo->prepare("SELECT project_id FROM cpms_construction_roles WHERE project_id = :pid LIMIT 1");
        $chk->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $chk->execute();
        $exists = $chk->fetchColumn() ? true : false;

        if ($exists) {
            $up = $pdo->prepare("UPDATE cpms_construction_roles SET site_employee_id = :sid WHERE project_id = :pid");
            $up->bindValue(':sid', $mainManagerId, PDO::PARAM_INT);
            $up->bindValue(':pid', $projectId, PDO::PARAM_INT);
            $up->execute();
        } else {
            $ins = $pdo->prepare("INSERT INTO cpms_construction_roles(project_id, site_employee_id) VALUES(:pid, :sid)");
            $ins->bindValue(':pid', $projectId, PDO::PARAM_INT);
            $ins->bindValue(':sid', $mainManagerId, PDO::PARAM_INT);
            $ins->execute();
        }
    } catch (Exception $e) {
        // 공사 테이블이 아직 없으면 무시(프로젝트 생성 자체는 성공해야 함)
    }

    $pdo->commit();

    flash_set('success', '프로젝트가 생성되었습니다.');
    header('Location: ?r=project/detail&id=' . $projectId);
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash_set('error', '저장 실패: ' . $e->getMessage());
    header('Location: ?r=공무');
    exit;
}
