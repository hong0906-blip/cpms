<?php
/**
 * 공무 > 프로젝트 상세 > 기성관리 저장
 * PHP 5.6 compatible.
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
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    flash_set('error', '보안 토큰이 유효하지 않습니다.');
    header('Location: ?r=공무');
    exit;
}

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$roundLabel = trim((string)(isset($_POST['round_label']) ? $_POST['round_label'] : ''));
$progressDate = trim((string)(isset($_POST['progress_date']) ? $_POST['progress_date'] : ''));
$requestedAmount = cpms_progress_parse_money(isset($_POST['requested_amount']) ? $_POST['requested_amount'] : '');
$recognizedAmount = cpms_progress_parse_money(isset($_POST['recognized_amount']) ? $_POST['recognized_amount'] : '');
$remark = trim((string)(isset($_POST['remark']) ? $_POST['remark'] : ''));
$redirect = '?r=project/detail&id=' . $projectId;

if ($projectId <= 0 || $roundLabel === '') {
    flash_set('error', '기성 회차를 입력해주세요.');
    header('Location: ' . $redirect);
    exit;
}
if ($progressDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $progressDate)) {
    $progressDate = '';
}

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error', 'DB 연결에 실패했습니다.');
    header('Location: ' . $redirect);
    exit;
}

function cpms_progress_parse_money($value) {
    $value = trim((string)$value);
    if ($value === '') return 0.0;
    $value = preg_replace('/[^0-9\.\-]/', '', str_replace(',', '', $value));
    return is_numeric($value) ? (float)$value : 0.0;
}

function cpms_progress_current_user_id() {
    $user = Auth::user();
    return (is_array($user) && isset($user['id'])) ? (int)$user['id'] : 0;
}

function cpms_progress_store_file($projectId, $progressDate) {
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

    $ym = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$progressDate) ? substr((string)$progressDate, 0, 7) : date('Y-m');
    $dir = cpms_storage_root() . '/progress/' . (int)$projectId . '/' . $ym;
    if (!cpms_ensure_dir($dir)) throw new Exception('기성 첨부파일 저장 폴더를 만들 수 없습니다.');
    $stored = 'progress_' . (int)$projectId . '_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 8) . '.' . $ext;
    $path = rtrim($dir, '/\\') . '/' . $stored;
    if (!@move_uploaded_file($tmp, $path)) throw new Exception('기성 첨부파일 저장에 실패했습니다.');
    return array('original'=>$name, 'stored'=>$stored, 'path'=>$path);
}

function cpms_progress_table_exists($pdo, $table) {
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE :tbl");
        $st->bindValue(':tbl', (string)$table);
        $st->execute();
        return $st->fetch() ? true : false;
    } catch (Exception $e) {
        return false;
    }
}

try {
    $fileInfo = cpms_progress_store_file($projectId, $progressDate);
    $now = date('Y-m-d H:i:s');
    $pdo->beginTransaction();
    $st = $pdo->prepare("INSERT INTO cpms_progress_billings
        (project_id, round_label, progress_date, requested_amount, recognized_amount, attachment_original_name, attachment_stored_name, attachment_stored_path, remark, created_by, created_at, updated_at)
        VALUES
        (:project_id, :round_label, :progress_date, :requested_amount, :recognized_amount, :attachment_original_name, :attachment_stored_name, :attachment_stored_path, :remark, :created_by, :created_at, :updated_at)");
    $st->bindValue(':project_id', $projectId, PDO::PARAM_INT);
    $st->bindValue(':round_label', $roundLabel);
    if ($progressDate === '') $st->bindValue(':progress_date', null, PDO::PARAM_NULL);
    else $st->bindValue(':progress_date', $progressDate);
    $st->bindValue(':requested_amount', $requestedAmount);
    $st->bindValue(':recognized_amount', $recognizedAmount);
    $st->bindValue(':attachment_original_name', $fileInfo['original']);
    $st->bindValue(':attachment_stored_name', $fileInfo['stored']);
    $st->bindValue(':attachment_stored_path', $fileInfo['path']);
    $st->bindValue(':remark', $remark);
    $userId = cpms_progress_current_user_id();
    if ($userId > 0) $st->bindValue(':created_by', $userId, PDO::PARAM_INT);
    else $st->bindValue(':created_by', null, PDO::PARAM_NULL);
    $st->bindValue(':created_at', $now);
    $st->bindValue(':updated_at', $now);
    $st->execute();

    if ($progressDate !== '' && cpms_progress_table_exists($pdo, 'cpms_monthly_recognized')) {
        $ym = substr($progressDate, 0, 7);
        $stCum = $pdo->prepare("SELECT COALESCE(SUM(recognized_amount), 0) FROM cpms_progress_billings WHERE project_id = :pid AND progress_date IS NOT NULL AND progress_date <= :last_day");
        $stCum->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $stCum->bindValue(':last_day', date('Y-m-t', strtotime($ym . '-01')));
        $stCum->execute();
        $cumAmount = (float)$stCum->fetchColumn();
        $stMonthly = $pdo->prepare("INSERT INTO cpms_monthly_recognized(project_id, ym, recognized_cum_amount) VALUES(:pid, :ym, :amt) ON DUPLICATE KEY UPDATE recognized_cum_amount = VALUES(recognized_cum_amount)");
        $stMonthly->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $stMonthly->bindValue(':ym', $ym);
        $stMonthly->bindValue(':amt', $cumAmount);
        $stMonthly->execute();
    }

    $pdo->commit();
    flash_set('success', '기성 회차를 등록했습니다.');
} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    flash_set('error', '기성 등록 실패: ' . $e->getMessage());
}

header('Location: ' . $redirect);
exit;

