<?php
/**
 * C:\www\cpms\app\views\project\contract_download.php
 * - 프로젝트 계약서 다운로드(GET)
 *
 * ✅ 요구사항
 * - 공사 섹션에서: 담당자로 지정된 사람(main/sub) + 임원만 열람 가능
 * - 공무/관리 섹션은 프로젝트 관리 권한으로 열람 가능
 * - 다운로드는 라우트에서 권한 체크 후 파일 스트리밍(직접 경로 노출 X)
 *
 * 저장 위치:
 *   {cpms_root}/storage/contracts/{projectId}/meta.json
 *
 * PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }

$projectId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($projectId <= 0) { http_response_code(400); echo 'Bad Request'; exit; }

$role = Auth::userRole();
$dept = Auth::userDepartment();

$pdo = Db::pdo();
if (!$pdo) { http_response_code(500); echo 'DB Error'; exit; }

// 프로젝트 존재 확인
try {
    $st = $pdo->prepare("SELECT id FROM cpms_projects WHERE id = :id LIMIT 1");
    $st->bindValue(':id', $projectId, PDO::PARAM_INT);
    $st->execute();
    $exists = (int)$st->fetchColumn();
    if ($exists <= 0) { http_response_code(404); echo 'Not Found'; exit; }
} catch (Exception $e) {
    http_response_code(500); echo 'DB Error'; exit;
}

// 권한 체크
$allowed = false;

// 1) 임원은 모두 허용
if ($role === 'executive') {
    $allowed = true;
}

// 2) 공무/관리(프로젝트 관리 권한)는 허용
if (!$allowed && ($dept === '공무' || $dept === '관리' || $dept === '관리부')) {
    $allowed = true;
}

// 3) 공사 담당(main/sub)만 허용 (다른 공사팀은 불가)
if (!$allowed) {
    $userEmail = Auth::userEmail();
    $employeeId = 0;

    try {
        $stE = $pdo->prepare("SELECT id FROM employees WHERE email = :em LIMIT 1");
        $stE->bindValue(':em', (string)$userEmail);
        $stE->execute();
        $employeeId = (int)$stE->fetchColumn();
    } catch (Exception $e) {
        $employeeId = 0;
    }

    if ($employeeId > 0) {
        try {
            $stM = $pdo->prepare("
                SELECT COUNT(*) 
                FROM cpms_project_members
                WHERE project_id = :pid
                  AND employee_id = :eid
                  AND role IN ('main','sub')
            ");
            $stM->bindValue(':pid', $projectId, PDO::PARAM_INT);
            $stM->bindValue(':eid', $employeeId, PDO::PARAM_INT);
            $stM->execute();
            $cnt = (int)$stM->fetchColumn();
            if ($cnt > 0) $allowed = true;
        } catch (Exception $e) {}
    }
}

if (!$allowed) { http_response_code(403); echo '403 Forbidden'; exit; }

// 파일 로드
$cpmsRoot = dirname(dirname(dirname(__DIR__))); // cpms/
$dir = $cpmsRoot . '/storage/contracts/' . $projectId;
$metaFile = $dir . '/meta.json';

if (!is_file($metaFile)) { http_response_code(404); echo 'No Contract'; exit; }

$metaJson = @file_get_contents($metaFile);
$meta = @json_decode($metaJson, true);
if (!is_array($meta) || !isset($meta['stored_name'])) { http_response_code(404); echo 'No Contract'; exit; }

$stored = basename((string)$meta['stored_name']);
$path = $dir . '/' . $stored;
if (!is_file($path)) { http_response_code(404); echo 'No Contract'; exit; }

$origName = isset($meta['original_name']) ? (string)$meta['original_name'] : ('contract_' . $projectId);
if ($origName === '') $origName = 'contract_' . $projectId;

// 헤더
$size = filesize($path);
$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

// 기본 Content-Type
$ctype = 'application/octet-stream';
if ($ext === 'pdf') $ctype = 'application/pdf';
if ($ext === 'jpg' || $ext === 'jpeg') $ctype = 'image/jpeg';
if ($ext === 'png') $ctype = 'image/png';

header('Content-Type: ' . $ctype);
header('Content-Length: ' . $size);
header('Cache-Control: private, max-age=0, no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

// 파일명(한글 호환용)
$encoded = rawurlencode($origName);
header("Content-Disposition: attachment; filename=\"{$encoded}\"; filename*=UTF-8''{$encoded}");

@readfile($path);
exit;
