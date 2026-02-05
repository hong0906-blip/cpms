<?php
/**
 * C:\www\cpms\app\views\project\project_update.php
 * - 프로젝트 수정 저장(POST)
 *
 * ✅ 요구사항 해결:
 * - 공무에서 수정한 담당자/내용이 공사 섹션에서도 바로 반영되게
 * - 메인 담당자 변경 시 cpms_construction_roles.site_employee_id 자동 반영
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
if (!$allowed) { http_response_code(403); echo '403 Forbidden'; exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }

$token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
if (!csrf_check($token)) {
    flash_set('error', '보안 토큰이 유효하지 않습니다.');
    header('Location: ?r=공무'); exit;
}

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
if ($projectId <= 0) {
    flash_set('error', '프로젝트 정보가 올바르지 않습니다.');
    header('Location: ?r=공무'); exit;
}

$name = isset($_POST['name']) ? trim((string)$_POST['name']) : '';
$client = isset($_POST['client']) ? trim((string)$_POST['client']) : '';
$contractor = isset($_POST['contractor']) ? trim((string)$_POST['contractor']) : '';
$location = isset($_POST['location']) ? trim((string)$_POST['location']) : '';
$start_date = isset($_POST['start_date']) ? trim((string)$_POST['start_date']) : '';
$end_date = isset($_POST['end_date']) ? trim((string)$_POST['end_date']) : '';
$status = isset($_POST['status']) ? trim((string)$_POST['status']) : '';
$contract_amount = isset($_POST['contract_amount']) ? trim((string)$_POST['contract_amount']) : '';

$mainManagerId = isset($_POST['main_manager_id']) ? (int)$_POST['main_manager_id'] : 0;
$subManagerIds = isset($_POST['sub_manager_ids']) && is_array($_POST['sub_manager_ids']) ? $_POST['sub_manager_ids'] : array();

if ($name === '' || $mainManagerId <= 0) {
    flash_set('error', '프로젝트명/공사 담당자는 필수입니다.');
    header('Location: ?r=project/detail&id=' . $projectId); exit;
}

// 계약금액 숫자만
$contractAmountVal = null;
if ($contract_amount !== '') {
    $clean = preg_replace('/[^0-9]/', '', $contract_amount);
    if ($clean !== '') $contractAmountVal = (int)$clean;
}

$startVal = ($start_date !== '') ? $start_date : null;
$endVal = ($end_date !== '') ? $end_date : null;

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error', 'DB 연결 실패');
    header('Location: ?r=project/detail&id=' . $projectId); exit;
}

try {
    $pdo->beginTransaction();

    // 프로젝트 존재 확인
    $st = $pdo->prepare("SELECT id FROM cpms_projects WHERE id = :id LIMIT 1");
    $st->bindValue(':id', $projectId, PDO::PARAM_INT);
    $st->execute();
    $exists = (int)$st->fetchColumn();
    if ($exists <= 0) {
        $pdo->rollBack();
        flash_set('error', '프로젝트를 찾을 수 없습니다.');
        header('Location: ?r=공무'); exit;
    }

    // 프로젝트 업데이트
    $up = $pdo->prepare("UPDATE cpms_projects
                         SET name=:name, client=:client, contractor=:contractor, location=:loc,
                             start_date=:sd, end_date=:ed, contract_amount=:ca, status=:st
                         WHERE id=:id");
    $up->bindValue(':name', $name);
    $up->bindValue(':client', $client);
    $up->bindValue(':contractor', $contractor);
    $up->bindValue(':loc', $location);
    $up->bindValue(':sd', $startVal);
    $up->bindValue(':ed', $endVal);
    $up->bindValue(':ca', $contractAmountVal);
    $up->bindValue(':st', $status);
    $up->bindValue(':id', $projectId, PDO::PARAM_INT);
    $up->execute();

    // 담당자 재저장(기존 삭제 후 다시)
    $pdo->prepare("DELETE FROM cpms_project_members WHERE project_id = :pid")
        ->execute(array(':pid' => $projectId));

    $stMem = $pdo->prepare("INSERT INTO cpms_project_members(project_id, employee_id, role) VALUES(:pid, :eid, :role)");

    // main
    $stMem->execute(array(':pid'=>$projectId, ':eid'=>$mainManagerId, ':role'=>'main'));

    // sub
    $seen = array();
    $seen[$mainManagerId] = true;
    foreach ($subManagerIds as $sid) {
        $eid = (int)$sid;
        if ($eid <= 0) continue;
        if (isset($seen[$eid])) continue;
        $seen[$eid] = true;
        $stMem->execute(array(':pid'=>$projectId, ':eid'=>$eid, ':role'=>'sub'));
    }

    // ✅ 공사 담당(현장) 자동 반영(site_employee_id = main)
    try {
        $chk = $pdo->prepare("SELECT project_id FROM cpms_construction_roles WHERE project_id = :pid LIMIT 1");
        $chk->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $chk->execute();
        $has = $chk->fetchColumn() ? true : false;

        if ($has) {
            $pdo->prepare("UPDATE cpms_construction_roles SET site_employee_id = :sid WHERE project_id = :pid")
                ->execute(array(':sid'=>$mainManagerId, ':pid'=>$projectId));
        } else {
            $pdo->prepare("INSERT INTO cpms_construction_roles(project_id, site_employee_id) VALUES(:pid, :sid)")
                ->execute(array(':pid'=>$projectId, ':sid'=>$mainManagerId));
        }
    } catch (Exception $e) {
        // 공사 테이블 없으면 무시
    }

    $pdo->commit();

    flash_set('success', '프로젝트가 수정되었습니다.');
    header('Location: ?r=project/detail&id=' . $projectId);
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash_set('error', '수정 실패: ' . $e->getMessage());
    header('Location: ?r=project/detail&id=' . $projectId);
    exit;
}
