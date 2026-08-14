<?php
/**
 * 관리 > 직영팀 명부 저장
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../services/DirectTeamConversionService.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }
if (!Auth::canManageEmployees()) { http_response_code(403); echo '403 Forbidden'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }

$redirectView = isset($_POST['direct_team_view']) && (string)$_POST['direct_team_view'] === 'retired' ? 'retired' : 'active';
$redirect = '?r=관리&tab=direct_team&direct_team_view=' . $redirectView;
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    flash_set('error', '보안 토큰이 유효하지 않습니다.');
    header('Location: ' . $redirect);
    exit;
}

$pdo = Db::pdo();
if (!$pdo) {
    flash_set('error', 'DB 연결 오류');
    header('Location: ' . $redirect);
    exit;
}

if (!function_exists('cpms_direct_team_save_table_exists')) {
function cpms_direct_team_save_table_exists($pdo, $table) {
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE :table_name");
        $st->bindValue(':table_name', (string)$table);
        $st->execute();
        return (bool)$st->fetch(PDO::FETCH_NUM);
    } catch (Exception $e) { return false; }
}}

if (!function_exists('cpms_direct_team_save_column_exists')) {
function cpms_direct_team_save_column_exists($pdo, $table, $column) {
    try {
        $dbName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
        if ($dbName === '') return false;
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:table_name AND COLUMN_NAME=:column_name");
        $st->execute(array(':db'=>$dbName, ':table_name'=>$table, ':column_name'=>$column));
        return ((int)$st->fetchColumn() > 0);
    } catch (Exception $e) { return false; }
}}

if (!function_exists('cpms_direct_team_ensure_schema')) {
function cpms_direct_team_ensure_schema($pdo) {
    if (!cpms_direct_team_save_table_exists($pdo, 'direct_team_members')) {
        $pdo->exec("CREATE TABLE direct_team_members (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            photo_path VARCHAR(255) NULL,
            phone VARCHAR(50) NULL,
            hire_date DATE NULL,
            resign_date DATE NULL,
            vehicle_number VARCHAR(50) NULL,
            resident_no VARCHAR(30) NULL,
            bank_account VARCHAR(80) NULL,
            bank_name VARCHAR(50) NULL,
            account_holder VARCHAR(100) NULL,
            monthly_salary INT UNSIGNED NOT NULL DEFAULT 0,
            address VARCHAR(255) NULL,
            note VARCHAR(120) NULL,
            deposit_rate INT NOT NULL DEFAULT 0,
            daily_wage INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_direct_team_active (is_active),
            KEY idx_direct_team_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    }
    $columns = array(
        'photo_path' => 'photo_path VARCHAR(255) NULL',
        'phone' => 'phone VARCHAR(50) NULL',
        'hire_date' => 'hire_date DATE NULL',
        'resign_date' => 'resign_date DATE NULL',
        'vehicle_number' => 'vehicle_number VARCHAR(50) NULL',
        'resident_no' => 'resident_no VARCHAR(30) NULL',
        'bank_account' => 'bank_account VARCHAR(80) NULL',
        'bank_name' => 'bank_name VARCHAR(50) NULL',
        'account_holder' => 'account_holder VARCHAR(100) NULL',
        'monthly_salary' => 'monthly_salary INT UNSIGNED NOT NULL DEFAULT 0',
        'is_active' => 'is_active TINYINT(1) NOT NULL DEFAULT 1',
        'created_at' => 'created_at DATETIME NULL',
        'updated_at' => 'updated_at DATETIME NULL'
    );
    foreach ($columns as $column => $definition) {
        if (!cpms_direct_team_save_column_exists($pdo, 'direct_team_members', $column)) {
            $pdo->exec("ALTER TABLE direct_team_members ADD COLUMN " . $definition);
        }
    }
    return true;
}}

if (!function_exists('cpms_direct_team_photo_delete')) {
function cpms_direct_team_photo_delete($photoPath) {
    $photoPath = trim((string)$photoPath);
    if (strpos($photoPath, '/cpms/public/uploads/direct_team/') !== 0) return;
    $root = realpath(__DIR__ . '/../../..');
    if ($root === false) return;
    $file = $root . '/public/uploads/direct_team/' . basename($photoPath);
    if (is_file($file)) @unlink($file);
}}

if (!function_exists('cpms_direct_team_photo_upload')) {
function cpms_direct_team_photo_upload($memberId, $fileInfo) {
    if (!is_array($fileInfo) || !isset($fileInfo['tmp_name']) || !is_uploaded_file($fileInfo['tmp_name'])) return array('ok'=>false, 'message'=>'업로드 파일이 없습니다.');
    if (!isset($fileInfo['error']) || (int)$fileInfo['error'] !== UPLOAD_ERR_OK) return array('ok'=>false, 'message'=>'사진 업로드 중 오류가 발생했습니다.');
    if (!isset($fileInfo['size']) || (int)$fileInfo['size'] > 5242880) return array('ok'=>false, 'message'=>'사진은 5MB 이하만 가능합니다.');
    $info = @getimagesize($fileInfo['tmp_name']);
    $types = array('image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp');
    $mime = is_array($info) && isset($info['mime']) ? strtolower((string)$info['mime']) : '';
    if (!isset($types[$mime])) return array('ok'=>false, 'message'=>'JPG, PNG, WEBP 사진만 가능합니다.');
    $root = realpath(__DIR__ . '/../../..');
    if ($root === false) return array('ok'=>false, 'message'=>'프로젝트 경로를 확인할 수 없습니다.');
    $dir = $root . '/public/uploads/direct_team';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return array('ok'=>false, 'message'=>'사진 폴더를 생성할 수 없습니다.');
    $name = 'direct_team_' . (int)$memberId . '_' . date('YmdHis') . '_' . mt_rand(1000, 9999) . '.' . $types[$mime];
    $dest = $dir . '/' . $name;
    if (!@move_uploaded_file($fileInfo['tmp_name'], $dest) || !is_file($dest)) return array('ok'=>false, 'message'=>'사진 파일 저장에 실패했습니다.');
    return array('ok'=>true, 'path'=>'/cpms/public/uploads/direct_team/' . $name);
}}

try {
    cpms_direct_team_ensure_schema($pdo);
} catch (Exception $e) {
    flash_set('error', '직영팀 명부 테이블 준비 실패: ' . $e->getMessage());
    header('Location: ' . $redirect);
    exit;
}

$action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($action === 'delete') {
    if ($id <= 0) {
        flash_set('error', '삭제 대상이 올바르지 않습니다.');
        header('Location: ' . $redirect);
        exit;
    }
    try {
        $authUser = Auth::user();
        $authUserId = is_array($authUser) && isset($authUser['id']) ? (int)$authUser['id'] : 0;
        $converter = new DirectTeamConversionService($pdo);
        $result = $converter->convertAndDelete($id, $authUserId);
        cpms_direct_team_photo_delete(isset($result['photo_path']) ? $result['photo_path'] : '');
        $convertedRows = isset($result['converted_rows']) ? (int)$result['converted_rows'] : 0;
        if ($convertedRows > 0) {
            flash_set('success', '기존 현장별 단가와 노무비 이력을 유지한 일용직으로 전환하고 직영팀 명부에서 삭제했습니다. 전환 ' . $convertedRows . '건');
        } else {
            flash_set('success', '직영팀 인원을 삭제했습니다.');
        }
    } catch (Exception $e) {
        flash_set('error', '삭제 실패: ' . $e->getMessage());
    }
    header('Location: ' . $redirect);
    exit;
}

if ($action !== 'save') {
    flash_set('error', '알 수 없는 요청입니다.');
    header('Location: ' . $redirect);
    exit;
}

$name = isset($_POST['name']) ? trim((string)$_POST['name']) : '';
$phone = isset($_POST['phone']) ? trim((string)$_POST['phone']) : '';
$hireDate = isset($_POST['hire_date']) ? trim((string)$_POST['hire_date']) : '';
$vehicleNumber = isset($_POST['vehicle_number']) ? trim((string)$_POST['vehicle_number']) : '';
$residentNo = isset($_POST['resident_no']) ? trim((string)$_POST['resident_no']) : '';
$bankAccount = isset($_POST['bank_account']) ? trim((string)$_POST['bank_account']) : '';
$bankName = isset($_POST['bank_name']) ? trim((string)$_POST['bank_name']) : '';
$accountHolder = isset($_POST['account_holder']) ? trim((string)$_POST['account_holder']) : '';
$salaryDigits = isset($_POST['monthly_salary']) ? preg_replace('/[^0-9]/', '', (string)$_POST['monthly_salary']) : '';
$monthlySalary = $salaryDigits === '' ? 0 : (int)$salaryDigits;
$isActive = $id > 0 && isset($_POST['is_active']) && (int)$_POST['is_active'] === 0 ? 0 : 1;
$resignDate = $isActive === 0 ? date('Y-m-d') : '';

if ($name === '') {
    flash_set('error', '이름은 필수입니다.');
    header('Location: ' . $redirect);
    exit;
}
if ($monthlySalary <= 0) {
    flash_set('error', '월급은 0원보다 큰 금액으로 입력해 주세요.');
    header('Location: ' . $redirect);
    exit;
}
if ($hireDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hireDate)) {
    flash_set('error', '입사일 형식이 올바르지 않습니다.');
    header('Location: ' . $redirect);
    exit;
}

try {
    $now = date('Y-m-d H:i:s');
    $oldPhoto = '';
    if ($id > 0) {
        $stOld = $pdo->prepare("SELECT photo_path, resign_date FROM direct_team_members WHERE id=:id LIMIT 1");
        $stOld->bindValue(':id', $id, PDO::PARAM_INT);
        $stOld->execute();
        $oldRow = $stOld->fetch(PDO::FETCH_ASSOC);
        if (!$oldRow) throw new Exception('수정할 직영팀 인원을 찾을 수 없습니다.');
        $oldPhoto = isset($oldRow['photo_path']) ? (string)$oldRow['photo_path'] : '';
        if ($isActive === 0 && isset($oldRow['resign_date']) && trim((string)$oldRow['resign_date']) !== '') $resignDate = (string)$oldRow['resign_date'];
        $st = $pdo->prepare("UPDATE direct_team_members SET name=:name, phone=:phone, hire_date=:hire_date, resign_date=:resign_date, vehicle_number=:vehicle_number, resident_no=:resident_no, bank_account=:bank_account, bank_name=:bank_name, account_holder=:account_holder, monthly_salary=:monthly_salary, is_active=:is_active, updated_at=:updated_at WHERE id=:id");
        $st->bindValue(':id', $id, PDO::PARAM_INT);
    } else {
        $st = $pdo->prepare("INSERT INTO direct_team_members (name, phone, hire_date, resign_date, vehicle_number, resident_no, bank_account, bank_name, account_holder, monthly_salary, deposit_rate, daily_wage, is_active, created_at, updated_at) VALUES (:name, :phone, :hire_date, NULL, :vehicle_number, :resident_no, :bank_account, :bank_name, :account_holder, :monthly_salary, 0, 0, 1, :created_at, :updated_at)");
        $st->bindValue(':created_at', $now);
    }
    $st->bindValue(':name', $name);
    $st->bindValue(':phone', $phone === '' ? null : $phone, $phone === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $st->bindValue(':hire_date', $hireDate === '' ? null : $hireDate, $hireDate === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
    if ($id > 0) $st->bindValue(':resign_date', $resignDate === '' ? null : $resignDate, $resignDate === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $st->bindValue(':vehicle_number', $vehicleNumber === '' ? null : $vehicleNumber, $vehicleNumber === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $st->bindValue(':resident_no', $residentNo === '' ? null : $residentNo, $residentNo === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $st->bindValue(':bank_account', $bankAccount === '' ? null : $bankAccount, $bankAccount === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $st->bindValue(':bank_name', $bankName === '' ? null : $bankName, $bankName === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $st->bindValue(':account_holder', $accountHolder === '' ? null : $accountHolder, $accountHolder === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $st->bindValue(':monthly_salary', $monthlySalary, PDO::PARAM_INT);
    if ($id > 0) $st->bindValue(':is_active', $isActive, PDO::PARAM_INT);
    $st->bindValue(':updated_at', $now);
    $st->execute();
    $savedId = $id > 0 ? $id : (int)$pdo->lastInsertId();

    $file = isset($_FILES['direct_team_photo']) && is_array($_FILES['direct_team_photo']) ? $_FILES['direct_team_photo'] : null;
    $hasPhoto = $file && isset($file['error']) && (int)$file['error'] === UPLOAD_ERR_OK;
    $photoError = '';
    if ($hasPhoto) {
        $upload = cpms_direct_team_photo_upload($savedId, $file);
        if (!empty($upload['ok'])) {
            $stPhoto = $pdo->prepare("UPDATE direct_team_members SET photo_path=:photo_path WHERE id=:id");
            $stPhoto->execute(array(':photo_path'=>$upload['path'], ':id'=>$savedId));
            if ($oldPhoto !== '' && $oldPhoto !== $upload['path']) cpms_direct_team_photo_delete($oldPhoto);
        } else {
            $photoError = isset($upload['message']) ? (string)$upload['message'] : '사진 저장 실패';
        }
    } else if ($id > 0 && isset($_POST['remove_photo']) && (string)$_POST['remove_photo'] === '1') {
        $stPhoto = $pdo->prepare("UPDATE direct_team_members SET photo_path=NULL WHERE id=:id");
        $stPhoto->execute(array(':id'=>$savedId));
        cpms_direct_team_photo_delete($oldPhoto);
    }

    if ($photoError !== '') flash_set('error', '직영팀 정보는 저장했지만 사진 저장에 실패했습니다: ' . $photoError);
    else flash_set('success', $id > 0 ? '직영팀 정보를 수정했습니다.' : '직영팀 인원을 추가했습니다.');
    $redirectView = $isActive === 0 ? 'retired' : 'active';
} catch (Exception $e) {
    flash_set('error', '저장 실패: ' . $e->getMessage());
}

header('Location: ?r=관리&tab=direct_team&direct_team_view=' . $redirectView);
exit;
