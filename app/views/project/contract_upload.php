<?php
/**
 * C:\www\cpms\app\views\project\contract_upload.php
 * - 프로젝트 계약서 업로드(POST)
 *
 * ✅ 요구사항
 * - 프로젝트 상세보기에서 계약서 업로드 가능
 * - DB 변경 없이 "파일"로만 관리(메타 json)
 * - 업로드 권한: 임원 또는 공무/관리
 *
 * 저장 위치:
 *   {cpms_root}/storage/contracts/{projectId}/
 *   - meta.json : 원본 파일명/저장 파일명/업로드 시간 등
 *
 * PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }

$role = Auth::userRole();
$dept = Auth::userDepartment();
$allowed = ($role === 'executive' || $dept === '공무' || $dept === '관리' || $dept === '관리부');
if (!$allowed) { http_response_code(403); echo '403 Forbidden'; exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }

$csrf = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
if (!csrf_check($csrf)) {
    flash_set('error', '보안 토큰이 유효하지 않습니다.');
    header('Location: ?r=공무'); exit;
}

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
if ($projectId <= 0) {
    flash_set('error', '잘못된 프로젝트 ID');
    header('Location: ?r=공무'); exit;
}

// 프로젝트 존재 확인
$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error', 'DB 연결 실패');
    header('Location: ?r=project/detail&id=' . $projectId); exit;
}
try {
    $st = $pdo->prepare("SELECT id FROM cpms_projects WHERE id = :id LIMIT 1");
    $st->bindValue(':id', $projectId, PDO::PARAM_INT);
    $st->execute();
    $exists = (int)$st->fetchColumn();
    if ($exists <= 0) {
        flash_set('error', '프로젝트를 찾을 수 없습니다.');
        header('Location: ?r=공무'); exit;
    }
} catch (Exception $e) {
    flash_set('error', '프로젝트 확인 실패');
    header('Location: ?r=공무'); exit;
}

if (!isset($_FILES['contract_file']) || !is_array($_FILES['contract_file'])) {
    flash_set('error', '업로드할 계약서 파일이 없습니다.');
    header('Location: ?r=project/detail&id=' . $projectId); exit;
}

$f = $_FILES['contract_file'];
$err = isset($f['error']) ? (int)$f['error'] : 999;
$tmp = isset($f['tmp_name']) ? (string)$f['tmp_name'] : '';
$orig = isset($f['name']) ? (string)$f['name'] : '';

if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
    flash_set('error', '파일 업로드 실패(파일 상태 확인)');
    header('Location: ?r=project/detail&id=' . $projectId); exit;
}

// 확장자 제한(계약서: PDF/문서/이미지)
$ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
$allowedExt = array('pdf','hwp','doc','docx','xls','xlsx','ppt','pptx','jpg','jpeg','png');
if ($ext === '' || !in_array($ext, $allowedExt, true)) {
    flash_set('error', '허용되지 않는 파일 형식입니다. (pdf/hwp/doc/docx/xls/xlsx/ppt/pptx/jpg/png)');
    header('Location: ?r=project/detail&id=' . $projectId); exit;
}

// 용량 제한(기본 30MB)
$maxBytes = 30 * 1024 * 1024;
$size = isset($f['size']) ? (int)$f['size'] : 0;
if ($size <= 0 || $size > $maxBytes) {
    flash_set('error', '파일 용량이 너무 큽니다. (최대 30MB)');
    header('Location: ?r=project/detail&id=' . $projectId); exit;
}

// 저장 폴더(공개 경로 밖)
$cpmsRoot = dirname(dirname(dirname(__DIR__))); // cpms/
$dir = $cpmsRoot . '/storage/contracts/' . $projectId;

if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
}
if (!is_dir($dir)) {
    flash_set('error', '서버 저장 폴더 생성 실패: ' . $dir);
    header('Location: ?r=project/detail&id=' . $projectId); exit;
}

// 기존 파일/메타 삭제(교체)
$metaFile = $dir . '/meta.json';
if (is_file($metaFile)) {
    $oldJson = @file_get_contents($metaFile);
    $oldMeta = @json_decode($oldJson, true);
    if (is_array($oldMeta) && isset($oldMeta['stored_name'])) {
        $oldStored = basename((string)$oldMeta['stored_name']);
        $oldPath = $dir . '/' . $oldStored;
        if (is_file($oldPath)) @unlink($oldPath);
    }
    @unlink($metaFile);
}

// 새 파일명(충돌 방지)
$rand = bin2hex(openssl_random_pseudo_bytes(8));
$storedName = 'contract_' . date('Ymd_His') . '_' . $rand . '.' . $ext;
$dest = $dir . '/' . $storedName;

if (!@move_uploaded_file($tmp, $dest)) {
    flash_set('error', '파일 저장 실패(권한/디스크 확인)');
    header('Location: ?r=project/detail&id=' . $projectId); exit;
}

// 메타 저장
$meta = array(
    'project_id' => $projectId,
    'original_name' => $orig,
    'stored_name' => $storedName,
    'uploaded_at' => date('Y-m-d H:i:s'),
    'uploaded_by' => Auth::userEmail(),
);

@file_put_contents($metaFile, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

flash_set('success', '계약서가 업로드되었습니다.');
header('Location: ?r=project/detail&id=' . $projectId);
exit;
