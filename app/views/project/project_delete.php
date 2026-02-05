<?php
/**
 * C:\www\cpms\app\views\project\project_delete.php
 * - 프로젝트 삭제(POST)
 *
 * ✅ 요구사항 해결:
 * - 공무에서 프로젝트 삭제 시 실제 삭제 처리
 * - 삭제 후 공사 섹션에서도 프로젝트가 안 보여야 함
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

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error', 'DB 연결 실패');
    header('Location: ?r=공무'); exit;
}

try {
    $pdo->beginTransaction();

    // 존재 확인
    $st = $pdo->prepare("SELECT id FROM cpms_projects WHERE id = :id LIMIT 1");
    $st->bindValue(':id', $projectId, PDO::PARAM_INT);
    $st->execute();
    $exists = (int)$st->fetchColumn();
    if ($exists <= 0) {
        $pdo->rollBack();
        flash_set('error', '프로젝트를 찾을 수 없습니다.');
        header('Location: ?r=공무'); exit;
    }

    // ==========================
    //  프로젝트 관련 데이터 삭제
    // ==========================

    // 1) 이슈 댓글/이슈 (cpms.zip 기준)
    try {
        $pdo->prepare("DELETE FROM cpms_project_issue_comments WHERE issue_id IN (SELECT id FROM cpms_project_issues WHERE project_id = :pid)")
            ->execute(array(':pid' => $projectId));
    } catch (Exception $e) {}
    try {
        $pdo->prepare("DELETE FROM cpms_project_issues WHERE project_id = :pid")
            ->execute(array(':pid' => $projectId));
    } catch (Exception $e) {}

    // 2) 단가표/멤버
    try { $pdo->prepare("DELETE FROM cpms_project_unit_prices WHERE project_id = :pid")->execute(array(':pid'=>$projectId)); } catch (Exception $e) {}
    try { $pdo->prepare("DELETE FROM cpms_project_members WHERE project_id = :pid")->execute(array(':pid'=>$projectId)); } catch (Exception $e) {}

    // 3) 공사 뼈대(MVP) 관련 테이블들(없어도 try/catch로 안전)
    try { $pdo->prepare("DELETE FROM cpms_construction_roles WHERE project_id = :pid")->execute(array(':pid'=>$projectId)); } catch (Exception $e) {}
    try { $pdo->prepare("DELETE FROM cpms_process_templates WHERE project_id = :pid")->execute(array(':pid'=>$projectId)); } catch (Exception $e) {}
    try { $pdo->prepare("DELETE FROM cpms_schedule_tasks WHERE project_id = :pid")->execute(array(':pid'=>$projectId)); } catch (Exception $e) {}
    try { $pdo->prepare("DELETE FROM cpms_safety_incidents WHERE project_id = :pid")->execute(array(':pid'=>$projectId)); } catch (Exception $e) {}

    // 4) 마지막: 프로젝트 삭제
    $del = $pdo->prepare("DELETE FROM cpms_projects WHERE id = :id");
    $del->bindValue(':id', $projectId, PDO::PARAM_INT);
    $del->execute();

    $pdo->commit();

    flash_set('success', '프로젝트가 삭제되었습니다.');
    header('Location: ?r=공무');
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash_set('error', '삭제 실패: ' . $e->getMessage());
    header('Location: ?r=공무');
    exit;
}
