<?php
/**
 * Project detail > progress billing save/update/delete.
 * PHP 5.6 compatible.
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../services/PublicAffairsDriveService.php';

use App\Core\Auth;
use App\Core\Db;

if (!function_exists('cpms_progress_parse_money')) {
function cpms_progress_parse_money($value) {
    $value = trim((string)$value);
    if ($value === '') return 0.0;
    $value = str_replace(',', '', $value);
    $value = preg_replace('/[^0-9.\-]/', '', $value);
    return is_numeric($value) ? (float)$value : 0.0;
}}

if (!function_exists('cpms_progress_current_user_id')) {
function cpms_progress_current_user_id() {
    $user = Auth::user();
    return (is_array($user) && isset($user['id'])) ? (int)$user['id'] : 0;
}}

if (!function_exists('cpms_progress_table_exists')) {
function cpms_progress_table_exists($pdo, $table) {
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE :tbl");
        $st->bindValue(':tbl', (string)$table);
        $st->execute();
        return $st->fetch() ? true : false;
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_progress_column_exists')) {
function cpms_progress_column_exists($pdo, $table, $column) {
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `" . $table . "` LIKE :col");
        $st->bindValue(':col', (string)$column);
        $st->execute();
        return $st->fetch() ? true : false;
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_progress_ensure_schema')) {
function cpms_progress_ensure_schema($pdo) {
    if (!$pdo) return false;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_progress_billings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            round_label VARCHAR(100) NOT NULL,
            progress_date DATE NULL,
            requested_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            recognized_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            attachment_original_name VARCHAR(255) DEFAULT '',
            attachment_stored_name VARCHAR(255) DEFAULT '',
            attachment_stored_path VARCHAR(500) DEFAULT '',
            remark TEXT NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            KEY idx_project (project_id),
            KEY idx_progress_date (project_id, progress_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $columns = array(
            'project_id' => "ALTER TABLE cpms_progress_billings ADD COLUMN project_id INT NOT NULL AFTER id",
            'round_label' => "ALTER TABLE cpms_progress_billings ADD COLUMN round_label VARCHAR(100) NOT NULL DEFAULT '' AFTER project_id",
            'progress_date' => "ALTER TABLE cpms_progress_billings ADD COLUMN progress_date DATE NULL AFTER round_label",
            'requested_amount' => "ALTER TABLE cpms_progress_billings ADD COLUMN requested_amount DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER progress_date",
            'recognized_amount' => "ALTER TABLE cpms_progress_billings ADD COLUMN recognized_amount DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER requested_amount",
            'attachment_original_name' => "ALTER TABLE cpms_progress_billings ADD COLUMN attachment_original_name VARCHAR(255) DEFAULT '' AFTER recognized_amount",
            'attachment_stored_name' => "ALTER TABLE cpms_progress_billings ADD COLUMN attachment_stored_name VARCHAR(255) DEFAULT '' AFTER attachment_original_name",
            'attachment_stored_path' => "ALTER TABLE cpms_progress_billings ADD COLUMN attachment_stored_path VARCHAR(500) DEFAULT '' AFTER attachment_stored_name",
            'remark' => "ALTER TABLE cpms_progress_billings ADD COLUMN remark TEXT NULL AFTER attachment_stored_path",
            'created_by' => "ALTER TABLE cpms_progress_billings ADD COLUMN created_by INT NULL AFTER remark",
            'created_at' => "ALTER TABLE cpms_progress_billings ADD COLUMN created_at DATETIME NULL AFTER created_by",
            'updated_at' => "ALTER TABLE cpms_progress_billings ADD COLUMN updated_at DATETIME NULL AFTER created_at"
        );
        foreach ($columns as $column => $sql) {
            if (!cpms_progress_column_exists($pdo, 'cpms_progress_billings', $column)) {
                try { $pdo->exec($sql); } catch (Exception $e) {}
            }
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_monthly_recognized (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            ym VARCHAR(7) NOT NULL,
            recognized_cum_amount DECIMAL(18,2) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_project_ym (project_id, ym)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        cpms_public_affairs_drive_ensure_table_columns($pdo, 'cpms_progress_billings');
        return true;
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_progress_valid_date_or_today')) {
function cpms_progress_valid_date_or_today($date) {
    $date = trim((string)$date);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return date('Y-m-d');
    $parts = explode('-', $date);
    if (count($parts) !== 3) return date('Y-m-d');
    if (!checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) return date('Y-m-d');
    return $date;
}}

if (!function_exists('cpms_progress_store_file')) {
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

    $ym = substr(cpms_progress_valid_date_or_today($progressDate), 0, 7);
    $dir = cpms_storage_root() . '/progress/' . (int)$projectId . '/' . $ym;
    if (!cpms_ensure_dir($dir)) throw new Exception('기성 첨부파일 저장 폴더를 만들 수 없습니다.');
    $stored = 'progress_' . (int)$projectId . '_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 8) . '.' . $ext;
    $path = rtrim($dir, '/\\') . '/' . $stored;
    if (!@move_uploaded_file($tmp, $path)) throw new Exception('기성 첨부파일 저장에 실패했습니다.');
    return array('original'=>$name, 'stored'=>$stored, 'path'=>$path);
}}

if (!function_exists('cpms_progress_refresh_monthly_recognized')) {
function cpms_progress_refresh_monthly_recognized($pdo, $projectId) {
    if (!$pdo || (int)$projectId <= 0 || !cpms_progress_table_exists($pdo, 'cpms_monthly_recognized')) return;

    $del = $pdo->prepare("DELETE FROM cpms_monthly_recognized WHERE project_id = :pid");
    $del->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
    $del->execute();

    $st = $pdo->prepare("SELECT id, progress_date, created_at, requested_amount, recognized_amount
                           FROM cpms_progress_billings
                          WHERE project_id = :pid
                          ORDER BY COALESCE(progress_date, DATE(created_at)) ASC, id ASC");
    $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) $rows = array();

    $cum = 0.0;
    $monthCum = array();
    foreach ($rows as $row) {
        $amount = isset($row['recognized_amount']) ? (float)$row['recognized_amount'] : 0.0;
        if ($amount <= 0 && isset($row['requested_amount'])) $amount = (float)$row['requested_amount'];
        if ($amount <= 0) continue;
        $basisDate = isset($row['progress_date']) ? trim((string)$row['progress_date']) : '';
        if ($basisDate === '') {
            $basisDate = isset($row['created_at']) ? substr((string)$row['created_at'], 0, 10) : '';
        }
        $basisDate = cpms_progress_valid_date_or_today($basisDate);
        $ym = substr($basisDate, 0, 7);
        $cum += $amount;
        $monthCum[$ym] = $cum;
    }

    $ins = $pdo->prepare("INSERT INTO cpms_monthly_recognized(project_id, ym, recognized_cum_amount)
        VALUES(:pid, :ym, :amt)
        ON DUPLICATE KEY UPDATE recognized_cum_amount = VALUES(recognized_cum_amount)");
    foreach ($monthCum as $ym => $amount) {
        if (!preg_match('/^\d{4}-\d{2}$/', (string)$ym)) continue;
        $ins->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
        $ins->bindValue(':ym', (string)$ym);
        $ins->bindValue(':amt', (float)$amount);
        $ins->execute();
    }
}}

if (!Auth::check()) { header('Location: ?r=login'); exit; }
$role = Auth::userRole();
$dept = Auth::userDepartment();
$allowed = (method_exists('App\\Core\\Auth', 'isMaster') && Auth::isMaster()) || $role === 'executive' || $dept === '공무' || $dept === '관리' || $dept === '관리부';
if (!$allowed) { http_response_code(403); echo '403 Forbidden'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    flash_set('error', '보안 토큰이 유효하지 않습니다.');
    header('Location: ?r=공무');
    exit;
}

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$progressId = isset($_POST['progress_id']) ? (int)$_POST['progress_id'] : 0;
$action = isset($_POST['action']) ? trim((string)$_POST['action']) : 'create';
if ($action !== 'create' && $action !== 'update' && $action !== 'delete') $action = 'create';

$roundLabel = trim((string)(isset($_POST['round_label']) ? $_POST['round_label'] : ''));
$progressDate = cpms_progress_valid_date_or_today(isset($_POST['progress_date']) ? $_POST['progress_date'] : '');
$requestedAmount = cpms_progress_parse_money(isset($_POST['requested_amount']) ? $_POST['requested_amount'] : '');
$recognizedAmount = cpms_progress_parse_money(isset($_POST['recognized_amount']) ? $_POST['recognized_amount'] : '');
$remark = trim((string)(isset($_POST['remark']) ? $_POST['remark'] : ''));
$redirect = '?r=project/detail&id=' . $projectId;

if ($recognizedAmount <= 0 && $requestedAmount > 0) $recognizedAmount = $requestedAmount;
if ($requestedAmount <= 0 && $recognizedAmount > 0) $requestedAmount = $recognizedAmount;

if ($projectId <= 0 || ($action !== 'delete' && $roundLabel === '')) {
    flash_set('error', '기성 회차를 입력해주세요.');
    header('Location: ' . $redirect);
    exit;
}
if (($action === 'update' || $action === 'delete') && $progressId <= 0) {
    flash_set('error', '기성 정보가 올바르지 않습니다.');
    header('Location: ' . $redirect);
    exit;
}

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error', 'DB 연결에 실패했습니다.');
    header('Location: ' . $redirect);
    exit;
}

try {
    cpms_progress_ensure_schema($pdo);
    $fileInfo = cpms_progress_store_file($projectId, $progressDate);
    $now = date('Y-m-d H:i:s');
    $savedProgressId = 0;
    $pdo->beginTransaction();

    if ($action === 'delete') {
        $st = $pdo->prepare("DELETE FROM cpms_progress_billings WHERE id = :id AND project_id = :pid");
        $st->bindValue(':id', (int)$progressId, PDO::PARAM_INT);
        $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
        $st->execute();
    } else if ($action === 'update') {
        $sql = "UPDATE cpms_progress_billings
                   SET round_label = :round_label,
                       progress_date = :progress_date,
                       requested_amount = :requested_amount,
                       recognized_amount = :recognized_amount,
                       remark = :remark,
                       updated_at = :updated_at";
        if (isset($fileInfo['path']) && trim((string)$fileInfo['path']) !== '') {
            $sql .= ", attachment_original_name = :attachment_original_name,
                      attachment_stored_name = :attachment_stored_name,
                      attachment_stored_path = :attachment_stored_path";
        }
        $sql .= " WHERE id = :id AND project_id = :project_id";
        $st = $pdo->prepare($sql);
        $st->bindValue(':id', (int)$progressId, PDO::PARAM_INT);
        $st->bindValue(':project_id', (int)$projectId, PDO::PARAM_INT);
        $st->bindValue(':round_label', $roundLabel);
        $st->bindValue(':progress_date', $progressDate);
        $st->bindValue(':requested_amount', $requestedAmount);
        $st->bindValue(':recognized_amount', $recognizedAmount);
        $st->bindValue(':remark', $remark);
        $st->bindValue(':updated_at', $now);
        if (isset($fileInfo['path']) && trim((string)$fileInfo['path']) !== '') {
            $st->bindValue(':attachment_original_name', $fileInfo['original']);
            $st->bindValue(':attachment_stored_name', $fileInfo['stored']);
            $st->bindValue(':attachment_stored_path', $fileInfo['path']);
        }
        $st->execute();
        $savedProgressId = (int)$progressId;
    } else {
        $st = $pdo->prepare("INSERT INTO cpms_progress_billings
            (project_id, round_label, progress_date, requested_amount, recognized_amount, attachment_original_name, attachment_stored_name, attachment_stored_path, remark, created_by, created_at, updated_at)
            VALUES
            (:project_id, :round_label, :progress_date, :requested_amount, :recognized_amount, :attachment_original_name, :attachment_stored_name, :attachment_stored_path, :remark, :created_by, :created_at, :updated_at)");
        $st->bindValue(':project_id', (int)$projectId, PDO::PARAM_INT);
        $st->bindValue(':round_label', $roundLabel);
        $st->bindValue(':progress_date', $progressDate);
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
        $savedProgressId = (int)$pdo->lastInsertId();
    }

    cpms_progress_refresh_monthly_recognized($pdo, $projectId);
    $pdo->commit();
    $driveUpload = null;
    if ($action !== 'delete' && $savedProgressId > 0 && isset($fileInfo['path']) && trim((string)$fileInfo['path']) !== '') {
        $driveUpload = cpms_public_affairs_drive_upload_local_file($pdo, $projectId, $fileInfo['path'], $fileInfo['original'], 'progress_attachment', $progressDate, $progressDate, array('date' => $progressDate), Auth::user());
        $driveRecord = (is_array($driveUpload) && isset($driveUpload['record']) && is_array($driveUpload['record'])) ? $driveUpload['record'] : array();
        cpms_public_affairs_drive_apply_record_to_row($pdo, 'cpms_progress_billings', $savedProgressId, $driveRecord, cpms_progress_current_user_id(), array(
            'section' => 'public_affairs',
            'project_id' => $projectId
        ));
    }
    flash_set('success', cpms_public_affairs_drive_flash_message('기성관리가 반영되었습니다.', $driveUpload));
} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    flash_set('error', '기성관리 처리 실패: ' . $e->getMessage());
}

header('Location: ' . $redirect);
exit;
?>
