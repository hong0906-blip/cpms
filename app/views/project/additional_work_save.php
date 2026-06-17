<?php
/**
 * 공무 > 프로젝트 상세 > 추가공사 등록
 * PHP 5.6 compatible.
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../services/PublicAffairsDriveService.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }

$role = Auth::userRole();
$dept = Auth::userDepartment();
$allowed = ($role === 'executive' || $dept === '공무' || $dept === '관리' || $dept === '관리부');
if (!$allowed) { http_response_code(403); echo '403 Forbidden'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    flash_set('error', '보안 토큰이 유효하지 않습니다.');
    header('Location: ?r=공무');
    exit;
}

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$title = trim((string)(isset($_POST['title']) ? $_POST['title'] : ''));
$occurredOn = trim((string)(isset($_POST['occurred_on']) ? $_POST['occurred_on'] : ''));
$requestRef = trim((string)(isset($_POST['request_ref']) ? $_POST['request_ref'] : ''));
$status = trim((string)(isset($_POST['status']) ? $_POST['status'] : '승인 전'));
$remark = trim((string)(isset($_POST['remark']) ? $_POST['remark'] : ''));
$redirect = '?r=project/detail&id=' . $projectId;

if ($projectId <= 0 || $title === '') {
    flash_set('error', '추가공사명을 입력해주세요.');
    header('Location: ' . $redirect);
    exit;
}
if (!in_array($status, array('승인 전','승인 완료','계약 반영 완료','보류','반려'), true)) {
    $status = '승인 전';
}
if ($occurredOn !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $occurredOn)) {
    $occurredOn = '';
}

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error', 'DB 연결에 실패했습니다.');
    header('Location: ' . $redirect);
    exit;
}

function cpms_additional_current_user_id() {
    $user = Auth::user();
    return (is_array($user) && isset($user['id'])) ? (int)$user['id'] : 0;
}

function cpms_additional_store_file($projectId) {
    $empty = array('original'=>'', 'stored'=>'', 'path'=>'');
    if (!isset($_FILES['attachment_file']) || !is_array($_FILES['attachment_file'])) return $empty;
    $file = $_FILES['attachment_file'];
    $noFile = defined('UPLOAD_ERR_NO_FILE') ? UPLOAD_ERR_NO_FILE : 4;
    $error = isset($file['error']) ? (int)$file['error'] : $noFile;
    if ($error === $noFile) return $empty;
    if ($error !== UPLOAD_ERR_OK) throw new Exception('첨부파일 업로드에 실패했습니다.');

    $name = isset($file['name']) ? basename((string)$file['name']) : '';
    $tmp = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';
    $size = isset($file['size']) ? (int)$file['size'] : 0;
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed = array('pdf'=>true,'hwp'=>true,'doc'=>true,'docx'=>true,'xls'=>true,'xlsx'=>true,'jpg'=>true,'jpeg'=>true,'png'=>true);
    if ($ext === '' || !isset($allowed[$ext])) throw new Exception('허용되지 않는 첨부파일 형식입니다.');
    if ($size <= 0 || $size > (30 * 1024 * 1024)) throw new Exception('첨부파일은 30MB 이하만 업로드할 수 있습니다.');
    if ($tmp === '' || !is_uploaded_file($tmp)) throw new Exception('정상적인 업로드 파일이 아닙니다.');

    $dir = cpms_storage_root() . '/additional_works/' . (int)$projectId;
    if (!cpms_ensure_dir($dir)) throw new Exception('첨부파일 저장 폴더를 만들 수 없습니다.');
    $stored = 'additional_' . (int)$projectId . '_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 8) . '.' . $ext;
    $path = rtrim($dir, '/\\') . '/' . $stored;
    if (!@move_uploaded_file($tmp, $path)) throw new Exception('첨부파일 저장에 실패했습니다.');
    return array('original'=>$name, 'stored'=>$stored, 'path'=>$path);
}

try {
    $fileInfo = cpms_additional_store_file($projectId);
    $st = $pdo->prepare("INSERT INTO cpms_contract_additional_works
        (project_id, title, occurred_on, request_ref, remark, status, attachment_original_name, attachment_stored_name, attachment_stored_path, created_by, created_at, updated_at)
        VALUES
        (:project_id, :title, :occurred_on, :request_ref, :remark, :status, :attachment_original_name, :attachment_stored_name, :attachment_stored_path, :created_by, :created_at, :updated_at)");
    $st->bindValue(':project_id', $projectId, PDO::PARAM_INT);
    $st->bindValue(':title', $title);
    if ($occurredOn === '') $st->bindValue(':occurred_on', null, PDO::PARAM_NULL);
    else $st->bindValue(':occurred_on', $occurredOn);
    $st->bindValue(':request_ref', $requestRef);
    $st->bindValue(':remark', $remark);
    $st->bindValue(':status', $status);
    $st->bindValue(':attachment_original_name', $fileInfo['original']);
    $st->bindValue(':attachment_stored_name', $fileInfo['stored']);
    $st->bindValue(':attachment_stored_path', $fileInfo['path']);
    $userId = cpms_additional_current_user_id();
    if ($userId > 0) $st->bindValue(':created_by', $userId, PDO::PARAM_INT);
    else $st->bindValue(':created_by', null, PDO::PARAM_NULL);
    $now = date('Y-m-d H:i:s');
    $st->bindValue(':created_at', $now);
    $st->bindValue(':updated_at', $now);
    $st->execute();
    $additionalId = (int)$pdo->lastInsertId();
    $driveUpload = null;
    if ($additionalId > 0 && isset($fileInfo['path']) && trim((string)$fileInfo['path']) !== '') {
        $driveUpload = cpms_public_affairs_drive_upload_local_file($pdo, $projectId, $fileInfo['path'], $fileInfo['original'], 'additional_work_estimate', ($occurredOn !== '' ? $occurredOn : date('Y-m-d')), ($occurredOn !== '' ? $occurredOn : date('Y-m-d')), array('date' => ($occurredOn !== '' ? $occurredOn : date('Y-m-d'))), Auth::user());
        $driveRecord = (is_array($driveUpload) && isset($driveUpload['record']) && is_array($driveUpload['record'])) ? $driveUpload['record'] : array();
        cpms_public_affairs_drive_apply_record_to_row($pdo, 'cpms_contract_additional_works', $additionalId, $driveRecord, $userId, array(
            'section' => 'public_affairs',
            'project_id' => $projectId
        ));
    }
    flash_set('success', cpms_public_affairs_drive_flash_message('추가공사를 등록했습니다.', $driveUpload));
} catch (Exception $e) {
    flash_set('error', '추가공사 등록 실패: ' . $e->getMessage());
}

header('Location: ' . $redirect);
exit;
