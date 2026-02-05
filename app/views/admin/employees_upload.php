<?php
/**
 * C:\www\cpms\app\views\admin\employees_upload.php
 * - 직원 사진 업로드
 * - ✅ 저장 경로 버그 수정: cpms 루트 기준으로 public/uploads/employees 에 저장
 * - MIME 검사 + filesize 검사 + chmod(0644)
 *
 * PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) {
    header('Location: ?r=login');
    exit;
}

if (!Auth::canManageEmployees()) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ?r=관리');
    exit;
}

// CSRF
$token = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
if (!csrf_check($token)) {
    flash_set('error', '보안 토큰이 유효하지 않습니다.');
    header('Location: ?r=관리');
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
    flash_set('error', '잘못된 요청입니다.');
    header('Location: ?r=관리');
    exit;
}

if (!isset($_FILES['photo']) || !is_array($_FILES['photo'])) {
    flash_set('error', '파일이 없습니다.');
    header('Location: ?r=관리');
    exit;
}

$f = $_FILES['photo'];
if ((int)$f['error'] !== UPLOAD_ERR_OK) {
    flash_set('error', '업로드 실패 (error=' . (int)$f['error'] . ')');
    header('Location: ?r=관리');
    exit;
}

// 파일 크기 제한(2MB)
if ((int)$f['size'] > 2 * 1024 * 1024) {
    flash_set('error', '파일이 너무 큽니다. (최대 2MB)');
    header('Location: ?r=관리');
    exit;
}

// 확장자 제한(1차)
$ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
$allow = array('jpg','jpeg','png','webp');
if (!in_array($ext, $allow, true)) {
    flash_set('error', '허용되지 않는 파일 형식입니다. (JPG/PNG/WEBP)');
    header('Location: ?r=관리');
    exit;
}

// ==========================
// MIME 검사(중요)
// ==========================
$mime = '';
if (function_exists('finfo_open')) {
    $fi = @finfo_open(FILEINFO_MIME_TYPE);
    if ($fi) {
        $mime = @finfo_file($fi, $f['tmp_name']);
        @finfo_close($fi);
    }
}
$mimeAllow = array('image/jpeg', 'image/png', 'image/webp');
if ($mime !== '' && !in_array($mime, $mimeAllow, true)) {
    flash_set('error', '이미지 파일이 아닙니다. (MIME: ' . $mime . ')');
    header('Location: ?r=관리');
    exit;
}

// ==========================
// ✅ 저장 경로 (중요 수정)
// 현재 파일 위치: cpms/app/views/admin
// cpms 루트로 가려면 ../../.. 가 맞음
// ==========================
$projectRoot = realpath(__DIR__ . '/../../..'); // ✅ cpms 루트
if ($projectRoot === false) {
    flash_set('error', '프로젝트 경로 확인 실패');
    header('Location: ?r=관리');
    exit;
}

$baseDir = $projectRoot . '/public/uploads/employees';

if (!is_dir($baseDir)) {
    @mkdir($baseDir, 0755, true);
}
if (!is_dir($baseDir) || !is_writable($baseDir)) {
    flash_set('error', '업로드 폴더 권한/경로 문제: ' . $baseDir);
    header('Location: ?r=관리');
    exit;
}

$filename = 'emp_' . $id . '_' . date('Ymd_His') . '_' . mt_rand(1000,9999) . '.' . $ext;
$target = $baseDir . '/' . $filename;

if (!move_uploaded_file($f['tmp_name'], $target)) {
    flash_set('error', '파일 저장에 실패했습니다. (경로/권한 확인 필요)');
    header('Location: ?r=관리');
    exit;
}

// 저장됐는데 0바이트면 깨진 파일(즉시 차단)
if (!is_file($target) || (int)@filesize($target) <= 0) {
    @unlink($target);
    flash_set('error', '업로드된 파일이 손상되었습니다. (0바이트)');
    header('Location: ?r=관리');
    exit;
}

// 웹서버가 읽을 수 있게 권한 고정
@chmod($target, 0644);

// ==========================
// DB 업데이트 (웹에서 접근 가능한 경로)
// asset_url('uploads/...') 는 /cpms/public/uploads/... 로 만들어줌
// ==========================
$publicPath = asset_url('uploads/employees/' . $filename);

$pdo = Db::pdo();
if (!$pdo) {
    @unlink($target);
    flash_set('error', 'DB 연결 실패');
    header('Location: ?r=관리');
    exit;
}

try {
    $oldPhoto = null;
    $empEmail = '';

    $st0 = $pdo->prepare("SELECT email, photo_path FROM employees WHERE id = :id LIMIT 1");
    $st0->bindValue(':id', $id, \PDO::PARAM_INT);
    $st0->execute();
    $row0 = $st0->fetch();
    if (is_array($row0)) {
        $empEmail = isset($row0['email']) ? (string)$row0['email'] : '';
        $oldPhoto = isset($row0['photo_path']) ? $row0['photo_path'] : null;
    }

    $st = $pdo->prepare("UPDATE employees SET photo_path = :p WHERE id = :id");
    $st->bindValue(':p', $publicPath);
    $st->bindValue(':id', $id, \PDO::PARAM_INT);
    $st->execute();

    // 기존 파일 삭제(uploads/employees 내부만)
    if (is_string($oldPhoto) && strpos($oldPhoto, '/cpms/public/uploads/employees/') === 0) {
        $oldBase = basename($oldPhoto);
        $oldFs = $baseDir . '/' . $oldBase;
        if (is_file($oldFs)) @unlink($oldFs);
    }

    // 본인 사진이면 즉시 반영
    if ($empEmail !== '' && Auth::userEmail() === $empEmail) {
        if (method_exists('App\\Core\\Auth', 'refreshCurrentUser')) {
            Auth::refreshCurrentUser(true);
        }
    }

    flash_set('success', '사진이 업로드되었습니다.');
} catch (\Exception $e) {
    @unlink($target);
    flash_set('error', 'DB 업데이트 실패: ' . $e->getMessage());
}

header('Location: ?r=관리');
exit;