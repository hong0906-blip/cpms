<?php
/**
 * C:\www\cpms\app\views\construction\template_generate.php
 * - 공사: 단가표(공정) 기반 공정 템플릿 자동 생성(POST)
 *
 * 규칙(현재 뼈대):
 * - cpms_project_unit_prices.item_name 을 "공정"으로 보고, 프로젝트별로 중복 제거 후 템플릿 생성
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

if (!($role === 'executive' || $dept === '공사')) {
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
if ($projectId <= 0) {
    flash_set('error','프로젝트 정보가 올바르지 않습니다.');
    header('Location: ?r=공사');
    exit;
}

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error','DB 연결 실패');
    header('Location: ?r=공사&pid='.$projectId.'&tab=template');
    exit;
}

try {
    // 단가표에서 공정 추출(현재: item_name + 규격)
    $st = $pdo->prepare("SELECT DISTINCT
                            TRIM(COALESCE(NULLIF(process_name, ''), item_name)) AS base_name,
                            TRIM(spec) AS spec
                         FROM cpms_project_unit_prices
                         WHERE project_id = :pid
                         AND COALESCE(NULLIF(process_name, ''), item_name) IS NOT NULL
                         AND TRIM(COALESCE(NULLIF(process_name, ''), item_name)) <> ''
                         AND spec IS NOT NULL
                         AND TRIM(spec) <> ''
                         ORDER BY base_name ASC, spec ASC");
    $st->bindValue(':pid', $projectId, \PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll();

    if (!$rows || count($rows) === 0) {
        flash_set('error','단가표에 공정(현재: item_name)이 없습니다. 공무에서 단가표 업로드 확인 필요.');
        header('Location: ?r=공사&pid='.$projectId.'&tab=template');
        exit;
    }

    // 이미 있는 템플릿 확인
    $st2 = $pdo->prepare("SELECT process_name FROM cpms_process_templates WHERE project_id = :pid");
    $st2->bindValue(':pid', $projectId, \PDO::PARAM_INT);
    $st2->execute();
    $existing = $st2->fetchAll();

    $existsMap = array();
    foreach ($existing as $e) {
        $n = isset($e['process_name']) ? trim((string)$e['process_name']) : '';
        if ($n !== '') $existsMap[$n] = true;
    }

    $ins = $pdo->prepare("INSERT INTO cpms_process_templates(project_id, process_name, sort_order) VALUES(:pid, :name, :ord)");

    $added = 0;
    $ord = 0;
    foreach ($rows as $r) {
        $base = isset($r['base_name']) ? trim((string)$r['base_name']) : '';
        $spec = isset($r['spec']) ? trim((string)$r['spec']) : '';
        if ($base === '' || $spec === '') continue;
        $name = $base . ' (' . $spec . ')';
        if (isset($existsMap[$name])) continue;

        $ins->bindValue(':pid', $projectId, \PDO::PARAM_INT);
        $ins->bindValue(':name', $name);
        $ins->bindValue(':ord', $ord, \PDO::PARAM_INT);
        $ins->execute();

        $added++;
        $ord++;
    }

    if ($added > 0) {
        flash_set('success',"템플릿 생성 완료: {$added}건 추가");
    } else {
        flash_set('success',"템플릿이 이미 최신입니다. (추가된 항목 없음)");
    }

    header('Location: ?r=공사&pid='.$projectId.'&tab=template');
    exit;

} catch (Exception $e) {
    flash_set('error','템플릿 생성 실패: '.$e->getMessage());
    header('Location: ?r=공사&pid='.$projectId.'&tab=template');
    exit;
}
