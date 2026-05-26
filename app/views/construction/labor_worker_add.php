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
$manualName = isset($_POST['manual_name']) ? trim((string)$_POST['manual_name']) : '';
$manualCompanyName = isset($_POST['manual_company_name']) ? trim((string)$_POST['manual_company_name']) : '';
$month = isset($_POST['month']) ? trim((string)$_POST['month']) : '';
$laborTab = isset($_POST['labor_tab']) ? trim((string)$_POST['labor_tab']) : 'workers';
if ($laborTab === '') $laborTab = 'workers';

$redirect = '?r=공사&pid=' . $projectId . '&tab=labor&labor_tab=' . urlencode($laborTab);
if ($month !== '') {
    $redirect .= '&month=' . urlencode($month);
}

if ($projectId <= 0) {
    flash_set('error', '프로젝트 정보가 올바르지 않습니다.');
    header('Location: ' . $redirect);
    exit;
}

if ($directMemberId <= 0 && $manualName === '') {
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
        } else {
            $stIns = $pdo->prepare("INSERT INTO cpms_project_labor_workers
                                    (project_id, name, source, direct_member_id, company_name, is_deleted, created_at, updated_at)
                                    VALUES (:pid, :name, 'manual', NULL, :company_name, 0, :now, :now)");
            $stIns->bindValue(':pid', $projectId, PDO::PARAM_INT);
            $stIns->bindValue(':name', $name);
            $stIns->bindValue(':company_name', $companyName, PDO::PARAM_STR);
            $stIns->bindValue(':now', $now);
            $stIns->execute();
        }
    }

    flash_set('success', $source === 'direct' ? '직영팀 인원이 추가되었습니다.' : '인원이 추가되었습니다.');
    header('Location: ' . $redirect);
    exit;

} catch (Exception $e) {
    flash_set('error', '추가 실패: ' . $e->getMessage());
    header('Location: ' . $redirect);
    exit;
}
