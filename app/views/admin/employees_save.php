<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/leave_management_helpers.php';
require_once __DIR__ . '/../../services/EmployeeVehicleService.php';

use App\Core\Auth;
use App\Core\Db;

if (!Auth::check()) { header('Location: ?r=login'); exit; }
$canManage = Auth::canManageEmployees();
$canSalary = (method_exists('App\\Core\\Auth', 'canManageSalary')) ? Auth::canManageSalary() : $canManage;
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ?r=관리&tab=employees'); exit; }
if (!csrf_check(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) { flash_set('error', '보안 토큰이 유효하지 않습니다.'); header('Location: ?r=관리&tab=employees'); exit; }
$pdo = Db::pdo();
if (!$pdo) { flash_set('error', 'DB 연결 실패'); header('Location: ?r=관리&tab=employees'); exit; }

if (!function_exists('cpms_column_exists')) {
function cpms_column_exists($pdo, $table, $column) {
    try {
        $db = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
        if ($db === '') return false;
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:tbl AND COLUMN_NAME=:col");
        $st->execute(array(':db'=>$db, ':tbl'=>$table, ':col'=>$column));
        return ((int)$st->fetchColumn() > 0);
    } catch (\Exception $e) { return false; }
}}

if (!function_exists('cpms_employee_save_ensure_column')) {
function cpms_employee_save_ensure_column($pdo, $table, $column, $typeSql) {
    if (!$pdo || trim((string)$table) === '' || trim((string)$column) === '' || trim((string)$typeSql) === '') return false;
    if (cpms_column_exists($pdo, $table, $column)) return true;
    try {
        $pdo->exec("ALTER TABLE " . $table . " ADD COLUMN " . $column . " " . $typeSql);
    } catch (\Exception $e) {
        return false;
    }
    return cpms_column_exists($pdo, $table, $column);
}}

if (!function_exists('cpms_employee_save_table_exists')) {
function cpms_employee_save_table_exists($pdo, $table) {
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE :tbl");
        $st->execute(array(':tbl' => $table));
        return (bool)$st->fetch(\PDO::FETCH_NUM);
    } catch (\Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_employee_save_sync_construction_pm')) {
function cpms_employee_save_sync_construction_pm($pdo, $employeeId, $enabled) {
    $employeeId = (int)$employeeId;
    if (!$pdo || $employeeId <= 0 || !cpms_employee_save_table_exists($pdo, 'cpms_approval_settings')) return;
    $keys = array('approval_construction_pm_employee_id', 'construction_pm_employee_id');
    if ((int)$enabled === 1) {
        for ($i = 0; $i < count($keys); $i++) {
            $st = $pdo->prepare("UPDATE cpms_approval_settings SET setting_value=:v, updated_at=NOW() WHERE setting_key=:k");
            $st->execute(array(':k' => $keys[$i], ':v' => (string)$employeeId));
            $exists = $pdo->prepare("SELECT COUNT(*) FROM cpms_approval_settings WHERE setting_key=:k");
            $exists->execute(array(':k' => $keys[$i]));
            if ((int)$exists->fetchColumn() < 1) {
                $ins = $pdo->prepare("INSERT INTO cpms_approval_settings (setting_key,setting_value,updated_at) VALUES (:k,:v,NOW())");
                $ins->execute(array(':k' => $keys[$i], ':v' => (string)$employeeId));
            }
        }
        return;
    }
    for ($i = 0; $i < count($keys); $i++) {
        $st = $pdo->prepare("UPDATE cpms_approval_settings SET setting_value='', updated_at=NOW() WHERE setting_key=:k AND setting_value=:v");
        $st->execute(array(':k' => $keys[$i], ':v' => (string)$employeeId));
    }
}}

$positionEnabled = cpms_column_exists($pdo, 'employees', 'position');
$hireDateEnabled = cpms_column_exists($pdo, 'employees', 'hire_date');
$resignDateEnabled = cpms_column_exists($pdo, 'employees', 'resign_date');
$monthlyRegularWageEnabled = cpms_column_exists($pdo, 'employees', 'monthly_regular_wage');
$leaveMonthlyEnabled = cpms_column_exists($pdo, 'employees', 'leave_monthly_balance');
$leaveAnnualEnabled = cpms_column_exists($pdo, 'employees', 'leave_annual_balance');
$leaveHalfEnabled = cpms_column_exists($pdo, 'employees', 'leave_half_balance');
$birthDateEnabled = cpms_column_exists($pdo, 'employees', 'birth_date');
$siteManagerEnabled = cpms_column_exists($pdo, 'employees', 'approval_can_be_site_manager');
$teamLeaderEnabled = cpms_column_exists($pdo, 'employees', 'approval_can_be_team_leader');
$gongmuEnabled = cpms_column_exists($pdo, 'employees', 'approval_can_be_gongmu_approver');
$manageEnabled = cpms_column_exists($pdo, 'employees', 'approval_can_be_manage_approver');
cpms_employee_save_ensure_column($pdo, 'employees', 'is_team_leader', 'TINYINT(1) NOT NULL DEFAULT 0');
cpms_employee_save_ensure_column($pdo, 'employees', 'team_leader_id', 'INT NULL');
$isTeamLeaderEnabled = cpms_column_exists($pdo, 'employees', 'is_team_leader');
$teamLeaderIdEnabled = cpms_column_exists($pdo, 'employees', 'team_leader_id');
$teamLeaderSettingPosted = (isset($_POST['is_team_leader']) || isset($_POST['team_leader_id']));
if ($teamLeaderSettingPosted && (!$isTeamLeaderEnabled || !$teamLeaderIdEnabled)) {
    flash_set('error', '팀장 설정 컬럼을 생성하지 못해 저장할 수 없습니다. DB 권한 또는 employees 테이블 상태를 확인해주세요.');
    header('Location: ?r=관리&tab=employees');
    exit;
}
$chatEnabledCol = cpms_column_exists($pdo, 'employees', 'google_chat_enabled');
$chatUserEnabled = cpms_column_exists($pdo, 'employees', 'google_chat_user_name');
$chatSpaceEnabled = cpms_column_exists($pdo, 'employees', 'google_chat_dm_space_name');
$photoPathEnabled = cpms_column_exists($pdo, 'employees', 'photo_path');
cpms_employee_save_ensure_column($pdo, 'employees', 'employee_no', 'VARCHAR(50) NULL');
cpms_employee_save_ensure_column($pdo, 'employees', 'phone', 'VARCHAR(50) NULL');
cpms_employee_save_ensure_column($pdo, 'employees', 'work_location', 'VARCHAR(100) NULL');
cpms_employee_save_ensure_column($pdo, 'employees', 'signature_path', 'VARCHAR(255) NULL');
$employeeNoEnabled = cpms_column_exists($pdo, 'employees', 'employee_no');
$phoneEnabled = cpms_column_exists($pdo, 'employees', 'phone');
$workLocationEnabled = cpms_column_exists($pdo, 'employees', 'work_location');
$signaturePathEnabled = cpms_column_exists($pdo, 'employees', 'signature_path');

if (!function_exists('cpms_employee_photo_delete_file')) {
function cpms_employee_photo_delete_file($photoPath) {
    if (!is_string($photoPath) || strpos($photoPath, '/cpms/public/uploads/employees/') !== 0) return;
    $projectRoot = realpath(__DIR__ . '/../../..');
    if ($projectRoot === false) return;
    $filePath = $projectRoot . '/public/uploads/employees/' . basename($photoPath);
    if (is_file($filePath)) @unlink($filePath);
}}

if (!function_exists('cpms_employee_signature_delete_file')) {
function cpms_employee_signature_delete_file($signaturePath) {
    $signaturePath = trim((string)$signaturePath);
    if ($signaturePath === '') return;
    $normalized = str_replace('\\', '/', $signaturePath);
    if (strpos($normalized, 'storage/signatures/') !== 0) return;
    $projectRoot = realpath(__DIR__ . '/../../..');
    if ($projectRoot === false) return;
    $filePath = $projectRoot . '/' . $normalized;
    if (is_file($filePath)) @unlink($filePath);
}}

if (!function_exists('cpms_employee_photo_repair_files')) {
function cpms_employee_photo_repair_files($pdo) {
    $result = array('checked'=>0,'moved'=>0,'copied'=>0,'missing'=>0,'skipped'=>0,'errors'=>0);
    $correctRoot = realpath(__DIR__ . '/../../..');
    $wrongRoot = realpath(__DIR__ . '/../../../..');
    if ($correctRoot === false) return $result;

    $correctDir = $correctRoot . '/public/uploads/employees';
    if (!is_dir($correctDir)) @mkdir($correctDir, 0775, true);
    if (!is_dir($correctDir)) return $result;

    $st = $pdo->prepare("SELECT id, photo_path FROM employees WHERE photo_path IS NOT NULL AND photo_path <> '' AND photo_path LIKE :path");
    $st->execute(array(':path'=>'/cpms/public/uploads/employees/%'));
    $rows = $st->fetchAll();
    if (!is_array($rows)) $rows = array();

    foreach ($rows as $row) {
        $photoPath = isset($row['photo_path']) ? (string)$row['photo_path'] : '';
        if ($photoPath === '') continue;
        $result['checked']++;
        $baseName = basename($photoPath);
        $correctPath = $correctDir . '/' . $baseName;
        if (is_file($correctPath)) {
            $result['skipped']++;
            continue;
        }
        if ($wrongRoot === false) {
            $result['missing']++;
            continue;
        }
        $wrongPath = $wrongRoot . '/public/uploads/employees/' . $baseName;
        if (!is_file($wrongPath)) {
            $result['missing']++;
            continue;
        }
        if (!@copy($wrongPath, $correctPath)) {
            $result['errors']++;
            error_log('[employee_photo_repair] copy failed: ' . $wrongPath . ' -> ' . $correctPath);
            continue;
        }
        $result['copied']++;
        if (is_file($correctPath)) {
            if (@unlink($wrongPath)) $result['moved']++;
        } else {
            $result['errors']++;
            error_log('[employee_photo_repair] destination missing after copy: ' . $correctPath);
        }
    }

    return $result;
}}

if (!function_exists('cpms_employee_photo_upload')) {
function cpms_employee_photo_upload($employeeId, $fileInfo) {
    if (!is_array($fileInfo) || !isset($fileInfo['tmp_name']) || !is_uploaded_file($fileInfo['tmp_name'])) return array('ok'=>false,'message'=>'업로드 파일이 없습니다.');
    if (!isset($fileInfo['error']) || (int)$fileInfo['error'] !== UPLOAD_ERR_OK) return array('ok'=>false,'message'=>'파일 업로드 중 오류가 발생했습니다.');
    if (!isset($fileInfo['size']) || (int)$fileInfo['size'] > 5242880) return array('ok'=>false,'message'=>'파일 크기는 5MB 이하만 가능합니다.');
    $imgInfo = @getimagesize($fileInfo['tmp_name']);
    if (!is_array($imgInfo) || !isset($imgInfo['mime'])) return array('ok'=>false,'message'=>'이미지 파일만 업로드할 수 있습니다.');
    $allowedMimeToExt = array('image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp');
    $mime = strtolower((string)$imgInfo['mime']);
    if (!isset($allowedMimeToExt[$mime])) return array('ok'=>false,'message'=>'JPG, PNG, WEBP 파일만 가능합니다.');
    $origName = isset($fileInfo['name']) ? strtolower((string)$fileInfo['name']) : '';
    $ext = pathinfo($origName, PATHINFO_EXTENSION);
    if (!in_array($ext, array('jpg','jpeg','png','webp'), true)) return array('ok'=>false,'message'=>'JPG, PNG, WEBP 파일만 가능합니다.');
    $projectRoot = realpath(__DIR__ . '/../../..');
    if ($projectRoot === false) return array('ok'=>false,'message'=>'프로젝트 경로를 확인할 수 없습니다.');
    $uploadDir = $projectRoot . '/public/uploads/employees';
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0775, true)) return array('ok'=>false,'message'=>'업로드 폴더를 생성할 수 없습니다.');
    if (!is_dir($uploadDir) || !is_writable($uploadDir)) return array('ok'=>false,'message'=>'업로드 폴더에 쓰기 권한이 없습니다.');
    $safeExt = $allowedMimeToExt[$mime];
    $fileName = 'employee_' . (int)$employeeId . '_' . date('YmdHis') . '_' . mt_rand(1000, 9999) . '.' . $safeExt;
    $destPath = $uploadDir . '/' . $fileName;
    if (!@move_uploaded_file($fileInfo['tmp_name'], $destPath)) return array('ok'=>false,'message'=>'업로드 파일 저장에 실패했습니다.');
    error_log('[employee_photo_upload] destPath=' . $destPath);
    if (!is_file($destPath)) return array('ok'=>false,'message'=>'파일 저장 후 실제 파일을 확인할 수 없습니다.');
    return array('ok'=>true,'db_path'=>'/cpms/public/uploads/employees/' . $fileName);
}}

if (!function_exists('cpms_employee_signature_upload')) {
function cpms_employee_signature_upload($employeeId, $email, $fileInfo) {
    if (!is_array($fileInfo) || !isset($fileInfo['tmp_name']) || !is_uploaded_file($fileInfo['tmp_name'])) return array('ok'=>false,'message'=>'업로드 파일이 없습니다.');
    if (!isset($fileInfo['error']) || (int)$fileInfo['error'] !== UPLOAD_ERR_OK) return array('ok'=>false,'message'=>'파일 업로드 중 오류가 발생했습니다.');
    if (!isset($fileInfo['size']) || (int)$fileInfo['size'] > 5242880) return array('ok'=>false,'message'=>'파일 크기는 5MB 이하만 가능합니다.');
    $imgInfo = @getimagesize($fileInfo['tmp_name']);
    if (!is_array($imgInfo) || !isset($imgInfo['mime'])) return array('ok'=>false,'message'=>'이미지 파일만 업로드할 수 있습니다.');
    $allowedMimeToExt = array('image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp');
    $mime = strtolower((string)$imgInfo['mime']);
    if (!isset($allowedMimeToExt[$mime])) return array('ok'=>false,'message'=>'JPG, PNG, WEBP 파일만 가능합니다.');
    $origName = isset($fileInfo['name']) ? strtolower((string)$fileInfo['name']) : '';
    $ext = pathinfo($origName, PATHINFO_EXTENSION);
    if (!in_array($ext, array('jpg','jpeg','png','webp'), true)) return array('ok'=>false,'message'=>'JPG, PNG, WEBP 파일만 가능합니다.');
    $projectRoot = realpath(__DIR__ . '/../../..');
    if ($projectRoot === false) return array('ok'=>false,'message'=>'프로젝트 경로를 확인할 수 없습니다.');
    $uploadDir = $projectRoot . '/storage/signatures';
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0775, true)) return array('ok'=>false,'message'=>'서명 저장 폴더를 생성할 수 없습니다.');
    if (!is_dir($uploadDir) || !is_writable($uploadDir)) return array('ok'=>false,'message'=>'서명 저장 폴더에 쓰기 권한이 없습니다.');
    $prefixParts = explode('@', (string)$email);
    $prefix = isset($prefixParts[0]) ? trim((string)$prefixParts[0]) : '';
    $prefix = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $prefix);
    if ($prefix === '') $prefix = 'employee_' . (int)$employeeId;
    $safeExt = $allowedMimeToExt[$mime];
    $fileName = $prefix . '_' . (int)$employeeId . '_' . date('YmdHis') . '_' . mt_rand(1000, 9999) . '.' . $safeExt;
    $destPath = $uploadDir . '/' . $fileName;
    if (!@move_uploaded_file($fileInfo['tmp_name'], $destPath)) return array('ok'=>false,'message'=>'서명 파일 저장에 실패했습니다.');
    if (!is_file($destPath)) return array('ok'=>false,'message'=>'서명 파일 저장 후 실제 파일을 확인할 수 없습니다.');
    @chmod($destPath, 0644);
    return array('ok'=>true,'db_path'=>'storage/signatures/' . $fileName);
}}

if (!function_exists('cpms_employee_save_normalize_department')) {
function cpms_employee_save_normalize_department($department) {
    $department = trim((string)$department);
    $map = array(
        '관리부' => '관리',
        '관리팀' => '관리',
        '공무부' => '공무',
        '공무팀' => '공무',
        '품질부' => '품질',
        '품질팀' => '품질',
        '안전부' => '안전',
        '안전팀' => '안전',
        '보건부' => '보건',
        '보건팀' => '보건',
        '공사부' => '공사',
        '공사팀' => '공사',
        '개발부' => '개발',
        '개발팀' => '개발',
    );
    if (isset($map[$department])) return $map[$department];
    return $department;
}}

if (!function_exists('cpms_employee_save_can_assign_development_department')) {
function cpms_employee_save_can_assign_development_department() {
    if (method_exists('App\\Core\\Auth', 'canAssignDevelopmentDepartment')) {
        return Auth::canAssignDevelopmentDepartment();
    }
    $dept = trim((string)Auth::userDepartment());
    if ($dept === '관리' || $dept === '관리부' || $dept === '관리팀') return true;
    $values = array(Auth::userRole(), Auth::userPosition(), Auth::userName());
    $words = array('대표', '대표이사', '대표님', '부사장');
    for ($i = 0; $i < count($values); $i++) {
        $value = trim((string)$values[$i]);
        if ($value === '') continue;
        for ($j = 0; $j < count($words); $j++) {
            if (strpos($value, $words[$j]) !== false) return true;
        }
    }
    return false;
}}

if (!function_exists('cpms_employee_save_can_assign_executive_role')) {
function cpms_employee_save_can_assign_executive_role() {
    if (method_exists('App\\Core\\Auth', 'canAssignExecutiveRole')) {
        return Auth::canAssignExecutiveRole();
    }
    return cpms_employee_save_can_assign_development_department();
}}

$action = isset($_POST['action']) ? (string)$_POST['action'] : 'save';
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($action === 'delete') {
    if (!$canManage) { http_response_code(403); echo '403 Forbidden'; exit; }
    if ($id <= 0) { flash_set('error', '삭제 대상이 올바르지 않습니다.'); header('Location: ?r=관리&tab=employees'); exit; }
    try {
        $photoPath = null;
        $signaturePath = null;
        $deleteSelectParts = array();
        if ($photoPathEnabled) $deleteSelectParts[] = 'photo_path';
        if ($signaturePathEnabled) $deleteSelectParts[] = 'signature_path';
        if (count($deleteSelectParts) < 1) $deleteSelectParts[] = 'id';
        $deleteSelect = implode(', ', $deleteSelectParts);
        $st0 = $pdo->prepare("SELECT " . $deleteSelect . " FROM employees WHERE id=:id LIMIT 1");
        $st0->bindValue(':id', $id, \PDO::PARAM_INT);
        $st0->execute();
        $row0 = $st0->fetch();
        if (is_array($row0)) $photoPath = isset($row0['photo_path']) ? $row0['photo_path'] : null;
        if (is_array($row0)) $signaturePath = isset($row0['signature_path']) ? $row0['signature_path'] : null;
        $st = $pdo->prepare("DELETE FROM employees WHERE id=:id");
        $st->bindValue(':id', $id, \PDO::PARAM_INT);
        $st->execute();
        cpms_employee_photo_delete_file($photoPath);
        cpms_employee_signature_delete_file($signaturePath);
        flash_set('success', '직원이 삭제되었습니다.');
    } catch (\Exception $e) { flash_set('error', '삭제 실패: ' . $e->getMessage()); }
    header('Location: ?r=관리&tab=employees'); exit;
}

if ($action === 'salary') {
    if (!$canSalary) { http_response_code(403); echo '403 Forbidden'; exit; }
    $salaryRaw = isset($_POST['monthly_salary']) ? trim((string)$_POST['monthly_salary']) : '';
    $salary = ($salaryRaw === '') ? null : max(0, (int)$salaryRaw);
    try {
        $st = $pdo->prepare("UPDATE employees SET monthly_salary=:salary WHERE id=:id");
        if ($salary === null) $st->bindValue(':salary', null, \PDO::PARAM_NULL); else $st->bindValue(':salary', $salary, \PDO::PARAM_INT);
        $st->bindValue(':id', $id, \PDO::PARAM_INT);
        $st->execute();
        flash_set('success', '월급이 저장되었습니다.');
    } catch (\Exception $e) { flash_set('error', '월급 저장 실패: ' . $e->getMessage()); }
    header('Location: ?r=관리&tab=employees'); exit;
}

if ($action === 'repair_photo_paths') {
    if (!$canManage) { http_response_code(403); echo '403 Forbidden'; exit; }
    try {
        $repair = cpms_employee_photo_repair_files($pdo);
        flash_set('success', '직원 사진 파일 위치 점검/복구 완료: 점검 ' . (int)$repair['checked'] . '건 / 이동 ' . (int)$repair['moved'] . '건 / 복사 ' . (int)$repair['copied'] . '건 / 누락 ' . (int)$repair['missing'] . '건 / 오류 ' . (int)$repair['errors'] . '건');
    } catch (\Exception $e) {
        flash_set('error', '직원 사진 파일 위치 점검/복구 실패: ' . $e->getMessage());
    }
    header('Location: ?r=관리&tab=employees'); exit;
}

if (!$canManage) { http_response_code(403); echo '403 Forbidden'; exit; }

$email = isset($_POST['email']) ? trim((string)$_POST['email']) : '';
$name = isset($_POST['name']) ? trim((string)$_POST['name']) : '';
$dept = isset($_POST['department']) ? cpms_employee_save_normalize_department($_POST['department']) : '';
$pos = isset($_POST['position']) ? trim((string)$_POST['position']) : '';
$role = isset($_POST['role']) ? (string)$_POST['role'] : 'employee';
$isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
$hireDate = isset($_POST['hire_date']) ? trim((string)$_POST['hire_date']) : '';
$resignDate = isset($_POST['resign_date']) ? trim((string)$_POST['resign_date']) : '';
$monthlyRegularWage = isset($_POST['monthly_regular_wage']) ? trim((string)$_POST['monthly_regular_wage']) : '';
$leaveMonthly = isset($_POST['leave_monthly_balance']) ? trim((string)$_POST['leave_monthly_balance']) : '';
$leaveAnnual = isset($_POST['leave_annual_balance']) ? trim((string)$_POST['leave_annual_balance']) : '';
$leaveHalf = isset($_POST['leave_half_balance']) ? trim((string)$_POST['leave_half_balance']) : '';
$birthDate = isset($_POST['birth_date']) ? trim((string)$_POST['birth_date']) : '';
$canSite = isset($_POST['approval_can_be_site_manager']) ? 1 : 0;
$canLead = isset($_POST['approval_can_be_team_leader']) ? 1 : 0;
$canConstructionPm = isset($_POST['approval_can_be_construction_pm']) ? 1 : 0;
$canGongmu = isset($_POST['approval_can_be_gongmu_approver']) ? 1 : 0;
$canManageApprover = isset($_POST['approval_can_be_manage_approver']) ? 1 : 0;
$isTeamLeader = isset($_POST['is_team_leader']) ? (int)$_POST['is_team_leader'] : 0;
$teamLeaderId = isset($_POST['team_leader_id']) ? (int)$_POST['team_leader_id'] : 0;
$googleChatEnabled = isset($_POST['google_chat_enabled']) ? 1 : 0;
$googleChatUserName = isset($_POST['google_chat_user_name']) ? trim((string)$_POST['google_chat_user_name']) : '';
$googleChatSpaceName = isset($_POST['google_chat_dm_space_name']) ? trim((string)$_POST['google_chat_dm_space_name']) : '';
$vehicleNumbersPosted = isset($_POST['vehicle_numbers']);
$vehicleNumbers = isset($_POST['vehicle_numbers']) ? trim((string)$_POST['vehicle_numbers']) : '';
$employeeNo = isset($_POST['employee_no']) ? trim((string)$_POST['employee_no']) : '';
$phone = isset($_POST['phone']) ? trim((string)$_POST['phone']) : '';
$workLocation = isset($_POST['work_location']) ? trim((string)$_POST['work_location']) : '';

if ($googleChatEnabled === 1 && $googleChatUserName === '' && $email !== '') {
    $googleChatUserName = 'users/' . $email;
}

if ($leaveMonthly !== '' && is_numeric($leaveMonthly)) {
    $leaveMonthly = (string)cpms_leave_normalize_half_step($leaveMonthly);
}
if ($leaveAnnual !== '' && is_numeric($leaveAnnual)) {
    $leaveAnnual = (string)cpms_leave_normalize_half_step($leaveAnnual);
}
if ($leaveHalf !== '' && is_numeric($leaveHalf)) {
    $leaveHalf = (string)cpms_leave_normalize_half_step($leaveHalf);
}

if ($email === '' || $name === '') { flash_set('error', '이메일/이름은 필수입니다.'); header('Location: ?r=관리&tab=employees'); exit; }

$existingDept = '';
$existingRole = '';
if ($id > 0) {
    try {
        $stExistingDept = $pdo->prepare("SELECT department, role FROM employees WHERE id = :id LIMIT 1");
        $stExistingDept->bindValue(':id', $id, \PDO::PARAM_INT);
        $stExistingDept->execute();
        $existingRow = $stExistingDept->fetch(\PDO::FETCH_ASSOC);
        if (is_array($existingRow)) {
            $existingDept = cpms_employee_save_normalize_department(isset($existingRow['department']) ? $existingRow['department'] : '');
            $existingRole = isset($existingRow['role']) ? (string)$existingRow['role'] : '';
        }
    } catch (\Exception $e) {
        $existingDept = '';
        $existingRole = '';
    }
}
$canAssignDevelopmentDept = cpms_employee_save_can_assign_development_department();
if (($dept === '개발' || $existingDept === '개발') && !$canAssignDevelopmentDept) {
    flash_set('error', '개발 부서 지정/변경은 대표, 부사장, 관리부만 가능합니다.');
    header('Location: ?r=관리&tab=employees');
    exit;
}

$allowedDepts = array('관리', '공무', '품질', '안전', '보건', '공사', '개발');
$allowedPositions = array('주임','대리','과장','차장','부장','전무','상무','이사','부사장','고문','대표');
if (!in_array($role, array('employee','executive'), true)) $role = 'employee';
$canAssignExecutiveRole = cpms_employee_save_can_assign_executive_role();
if (!$canAssignExecutiveRole) {
    if ($id > 0) {
        if ($role !== $existingRole && ($role === 'executive' || $existingRole === 'executive')) {
            flash_set('error', '임원 설정은 대표, 부사장, 관리부만 가능합니다.');
            header('Location: ?r=관리&tab=employees');
            exit;
        }
        $role = ($existingRole === 'executive') ? 'executive' : 'employee';
    } else if ($role === 'executive') {
        flash_set('error', '임원 설정은 대표, 부사장, 관리부만 가능합니다.');
        header('Location: ?r=관리&tab=employees');
        exit;
    }
}
if ($isActive !== 0 && $isActive !== 1) $isActive = 1;
if ($isActive === 0) {
    if ($resignDate === '') $resignDate = date('Y-m-d');
} else {
    $resignDate = '';
}
if ($isTeamLeader !== 1) $isTeamLeader = 0;
if ($id > 0 && $teamLeaderId === $id) $teamLeaderId = 0;
if ($dept !== '' && !in_array($dept, $allowedDepts, true)) $dept = '';
if ($pos !== '' && !in_array($pos, $allowedPositions, true)) $pos = '';

try {
    $uploadedPhoto = (isset($_FILES['employee_photo']) && is_array($_FILES['employee_photo'])) ? $_FILES['employee_photo'] : null;
    $hasNewPhoto = ($uploadedPhoto && isset($uploadedPhoto['error']) && (int)$uploadedPhoto['error'] === UPLOAD_ERR_OK);
    $uploadedSignature = (isset($_FILES['signature_file']) && is_array($_FILES['signature_file'])) ? $_FILES['signature_file'] : null;
    $hasNewSignature = ($uploadedSignature && isset($uploadedSignature['error']) && (int)$uploadedSignature['error'] === UPLOAD_ERR_OK);
    $removePhoto = (isset($_POST['remove_photo']) && (string)$_POST['remove_photo'] === '1') ? 1 : 0;
    $removeSignature = (isset($_POST['remove_signature']) && (string)$_POST['remove_signature'] === '1') ? 1 : 0;
    $oldPhotoPath = null;
    $oldSignaturePath = null;
    if ($id > 0 && $photoPathEnabled) {
        $stOld = $pdo->prepare("SELECT photo_path FROM employees WHERE id=:id LIMIT 1");
        $stOld->bindValue(':id', $id, \PDO::PARAM_INT);
        $stOld->execute();
        $oldRow = $stOld->fetch();
        if (is_array($oldRow) && isset($oldRow['photo_path'])) $oldPhotoPath = $oldRow['photo_path'];
    }
    if ($id > 0 && $signaturePathEnabled) {
        $stOldSign = $pdo->prepare("SELECT signature_path FROM employees WHERE id=:id LIMIT 1");
        $stOldSign->bindValue(':id', $id, \PDO::PARAM_INT);
        $stOldSign->execute();
        $oldSignRow = $stOldSign->fetch();
        if (is_array($oldSignRow) && isset($oldSignRow['signature_path'])) $oldSignaturePath = $oldSignRow['signature_path'];
    }

    $fields = array('email=:email','name=:name','department=:dept','role=:role','is_active=:active');
    if ($employeeNoEnabled) $fields[] = 'employee_no=:employee_no';
    if ($phoneEnabled) $fields[] = 'phone=:phone';
    if ($workLocationEnabled) $fields[] = 'work_location=:work_location';
    if ($positionEnabled) $fields[] = 'position=:pos';
    if ($hireDateEnabled) $fields[] = 'hire_date=:hire_date';
    if ($resignDateEnabled) $fields[] = 'resign_date=:resign_date';
    if ($monthlyRegularWageEnabled) $fields[] = 'monthly_regular_wage=:monthly_regular_wage';
    if ($leaveMonthlyEnabled) $fields[] = 'leave_monthly_balance=:leave_monthly_balance';
    if ($leaveAnnualEnabled) $fields[] = 'leave_annual_balance=:leave_annual_balance';
    if ($leaveHalfEnabled) $fields[] = 'leave_half_balance=:leave_half_balance';
    if ($birthDateEnabled) $fields[] = 'birth_date=:birth_date';
    if ($siteManagerEnabled) $fields[] = 'approval_can_be_site_manager=:approval_can_be_site_manager';
    if ($teamLeaderEnabled) $fields[] = 'approval_can_be_team_leader=:approval_can_be_team_leader';
    if ($gongmuEnabled) $fields[] = 'approval_can_be_gongmu_approver=:approval_can_be_gongmu_approver';
    if ($manageEnabled) $fields[] = 'approval_can_be_manage_approver=:approval_can_be_manage_approver';
    if ($isTeamLeaderEnabled) $fields[] = 'is_team_leader=:is_team_leader';
    if ($teamLeaderIdEnabled) $fields[] = 'team_leader_id=:team_leader_id';
    if ($chatEnabledCol) $fields[] = 'google_chat_enabled=:google_chat_enabled';
    if ($chatUserEnabled) $fields[] = 'google_chat_user_name=:google_chat_user_name';
    if ($chatSpaceEnabled) $fields[] = 'google_chat_dm_space_name=:google_chat_dm_space_name';

    if ($id > 0) {
        $sql = "UPDATE employees SET " . implode(',', $fields) . " WHERE id=:id";
        $st = $pdo->prepare($sql);
    } else {
        $cols = array('email','name','department','role','is_active');
        $vals = array(':email',':name',':dept',':role',':active');
        if ($employeeNoEnabled) { $cols[] = 'employee_no'; $vals[] = ':employee_no'; }
        if ($phoneEnabled) { $cols[] = 'phone'; $vals[] = ':phone'; }
        if ($workLocationEnabled) { $cols[] = 'work_location'; $vals[] = ':work_location'; }
        if ($positionEnabled) { $cols[] = 'position'; $vals[] = ':pos'; }
        if ($hireDateEnabled) { $cols[] = 'hire_date'; $vals[] = ':hire_date'; }
        if ($resignDateEnabled) { $cols[] = 'resign_date'; $vals[] = ':resign_date'; }
        if ($monthlyRegularWageEnabled) { $cols[] = 'monthly_regular_wage'; $vals[] = ':monthly_regular_wage'; }
        if ($leaveMonthlyEnabled) { $cols[] = 'leave_monthly_balance'; $vals[] = ':leave_monthly_balance'; }
        if ($leaveAnnualEnabled) { $cols[] = 'leave_annual_balance'; $vals[] = ':leave_annual_balance'; }
        if ($leaveHalfEnabled) { $cols[] = 'leave_half_balance'; $vals[] = ':leave_half_balance'; }
        if ($birthDateEnabled) { $cols[] = 'birth_date'; $vals[] = ':birth_date'; }
        if ($siteManagerEnabled) { $cols[] = 'approval_can_be_site_manager'; $vals[] = ':approval_can_be_site_manager'; }
        if ($teamLeaderEnabled) { $cols[] = 'approval_can_be_team_leader'; $vals[] = ':approval_can_be_team_leader'; }
        if ($gongmuEnabled) { $cols[] = 'approval_can_be_gongmu_approver'; $vals[] = ':approval_can_be_gongmu_approver'; }
        if ($manageEnabled) { $cols[] = 'approval_can_be_manage_approver'; $vals[] = ':approval_can_be_manage_approver'; }
        if ($isTeamLeaderEnabled) { $cols[] = 'is_team_leader'; $vals[] = ':is_team_leader'; }
        if ($teamLeaderIdEnabled) { $cols[] = 'team_leader_id'; $vals[] = ':team_leader_id'; }
        if ($chatEnabledCol) { $cols[] = 'google_chat_enabled'; $vals[] = ':google_chat_enabled'; }
        if ($chatUserEnabled) { $cols[] = 'google_chat_user_name'; $vals[] = ':google_chat_user_name'; }
        if ($chatSpaceEnabled) { $cols[] = 'google_chat_dm_space_name'; $vals[] = ':google_chat_dm_space_name'; }
        $sql = "INSERT INTO employees (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
        $st = $pdo->prepare($sql);
    }

    $st->bindValue(':email', $email);
    $st->bindValue(':name', $name);
    $st->bindValue(':dept', $dept);
    $st->bindValue(':role', $role);
    $st->bindValue(':active', $isActive, \PDO::PARAM_INT);
    if ($employeeNoEnabled) { if ($employeeNo === '') $st->bindValue(':employee_no', null, \PDO::PARAM_NULL); else $st->bindValue(':employee_no', $employeeNo); }
    if ($phoneEnabled) { if ($phone === '') $st->bindValue(':phone', null, \PDO::PARAM_NULL); else $st->bindValue(':phone', $phone); }
    if ($workLocationEnabled) { if ($workLocation === '') $st->bindValue(':work_location', null, \PDO::PARAM_NULL); else $st->bindValue(':work_location', $workLocation); }
    if ($positionEnabled) { if ($pos === '') $st->bindValue(':pos', null, \PDO::PARAM_NULL); else $st->bindValue(':pos', $pos); }
    if ($hireDateEnabled) { if ($hireDate === '') $st->bindValue(':hire_date', null, \PDO::PARAM_NULL); else $st->bindValue(':hire_date', $hireDate); }
    if ($resignDateEnabled) { if ($resignDate === '') $st->bindValue(':resign_date', null, \PDO::PARAM_NULL); else $st->bindValue(':resign_date', $resignDate); }
    if ($monthlyRegularWageEnabled) { if ($monthlyRegularWage === '') $st->bindValue(':monthly_regular_wage', null, \PDO::PARAM_NULL); else $st->bindValue(':monthly_regular_wage', (float)$monthlyRegularWage); }
    if ($leaveMonthlyEnabled) { if ($leaveMonthly === '') $st->bindValue(':leave_monthly_balance', null, \PDO::PARAM_NULL); else $st->bindValue(':leave_monthly_balance', (float)$leaveMonthly); }
    if ($leaveAnnualEnabled) { if ($leaveAnnual === '') $st->bindValue(':leave_annual_balance', null, \PDO::PARAM_NULL); else $st->bindValue(':leave_annual_balance', (float)$leaveAnnual); }
    if ($leaveHalfEnabled) { if ($leaveHalf === '') $st->bindValue(':leave_half_balance', null, \PDO::PARAM_NULL); else $st->bindValue(':leave_half_balance', (float)$leaveHalf); }
    if ($birthDateEnabled) { if ($birthDate === '') $st->bindValue(':birth_date', null, \PDO::PARAM_NULL); else $st->bindValue(':birth_date', $birthDate); }
    if ($siteManagerEnabled) $st->bindValue(':approval_can_be_site_manager', $canSite, \PDO::PARAM_INT);
    if ($teamLeaderEnabled) $st->bindValue(':approval_can_be_team_leader', $canLead, \PDO::PARAM_INT);
    if ($gongmuEnabled) $st->bindValue(':approval_can_be_gongmu_approver', $canGongmu, \PDO::PARAM_INT);
    if ($manageEnabled) $st->bindValue(':approval_can_be_manage_approver', $canManageApprover, \PDO::PARAM_INT);
    if ($isTeamLeaderEnabled) $st->bindValue(':is_team_leader', $isTeamLeader, \PDO::PARAM_INT);
    if ($teamLeaderIdEnabled) {
        if ($teamLeaderId > 0) $st->bindValue(':team_leader_id', $teamLeaderId, \PDO::PARAM_INT);
        else $st->bindValue(':team_leader_id', null, \PDO::PARAM_NULL);
    }
    if ($chatEnabledCol) $st->bindValue(':google_chat_enabled', $googleChatEnabled, \PDO::PARAM_INT);
    if ($chatUserEnabled) { if ($googleChatUserName === '') $st->bindValue(':google_chat_user_name', null, \PDO::PARAM_NULL); else $st->bindValue(':google_chat_user_name', $googleChatUserName); }
    if ($chatSpaceEnabled) { if ($googleChatSpaceName === '') $st->bindValue(':google_chat_dm_space_name', null, \PDO::PARAM_NULL); else $st->bindValue(':google_chat_dm_space_name', $googleChatSpaceName); }
    if ($id > 0) $st->bindValue(':id', $id, \PDO::PARAM_INT);
    $st->execute();

    $savedId = ($id > 0) ? $id : (int)$pdo->lastInsertId();
    cpms_employee_save_sync_construction_pm($pdo, $savedId, $canConstructionPm);
    $vehicleSaveOk = true;
    if ($vehicleNumbersPosted) $vehicleSaveOk = cpms_employee_vehicle_save($pdo, $savedId, $vehicleNumbers, Auth::user());
    $photoError = '';
    $signatureError = '';
    if ($photoPathEnabled) {
        if ($hasNewPhoto) {
            $up = cpms_employee_photo_upload($savedId, $uploadedPhoto);
            if (isset($up['ok']) && $up['ok'] && isset($up['db_path'])) {
                $newPath = (string)$up['db_path'];
                $stPhoto = $pdo->prepare("UPDATE employees SET photo_path=:photo_path WHERE id=:id");
                $stPhoto->bindValue(':photo_path', $newPath);
                $stPhoto->bindValue(':id', $savedId, \PDO::PARAM_INT);
                $stPhoto->execute();
                if ($oldPhotoPath !== null && $oldPhotoPath !== $newPath) cpms_employee_photo_delete_file($oldPhotoPath);
            } else {
                $photoError = isset($up['message']) ? (string)$up['message'] : '알 수 없는 오류';
            }
        } elseif ($id > 0 && $removePhoto === 1) {
            $stPhoto = $pdo->prepare("UPDATE employees SET photo_path=NULL WHERE id=:id");
            $stPhoto->bindValue(':id', $savedId, \PDO::PARAM_INT);
            $stPhoto->execute();
            cpms_employee_photo_delete_file($oldPhotoPath);
        }
    }
    if ($signaturePathEnabled) {
        if ($hasNewSignature) {
            $upSign = cpms_employee_signature_upload($savedId, $email, $uploadedSignature);
            if (isset($upSign['ok']) && $upSign['ok'] && isset($upSign['db_path'])) {
                $newSignaturePath = (string)$upSign['db_path'];
                $stSign = $pdo->prepare("UPDATE employees SET signature_path=:signature_path WHERE id=:id");
                $stSign->bindValue(':signature_path', $newSignaturePath);
                $stSign->bindValue(':id', $savedId, \PDO::PARAM_INT);
                $stSign->execute();
                if ($oldSignaturePath !== null && $oldSignaturePath !== $newSignaturePath) cpms_employee_signature_delete_file($oldSignaturePath);
            } else {
                $signatureError = isset($upSign['message']) ? (string)$upSign['message'] : '알 수 없는 오류';
            }
        } elseif ($id > 0 && $removeSignature === 1) {
            $stSign = $pdo->prepare("UPDATE employees SET signature_path=NULL WHERE id=:id");
            $stSign->bindValue(':id', $savedId, \PDO::PARAM_INT);
            $stSign->execute();
            cpms_employee_signature_delete_file($oldSignaturePath);
        }
    }

    $msg = ($id > 0 ? '직원 정보가 수정되었습니다.' : '직원이 추가되었습니다.')
        . ' (id=' . $savedId . ', 상태=' . ($isActive === 1 ? '재직' : '퇴직') . ', 퇴직일=' . ($resignDate === '' ? 'NULL' : $resignDate) . ')';
    if (!$vehicleSaveOk) {
        $vehicleError = function_exists('cpms_employee_vehicle_last_error') ? cpms_employee_vehicle_last_error() : '';
        $msg .= ' 다만 차량번호 저장은 실패했습니다.' . ($vehicleError !== '' ? ' (' . $vehicleError . ')' : '');
    }
    if ($photoError !== '' || $signatureError !== '') flash_set('error', '직원 정보는 저장되었지만 파일 업로드에 실패했습니다: ' . ($photoError !== '' ? '사진 - ' . $photoError . ' ' : '') . ($signatureError !== '' ? '서명 - ' . $signatureError : '') . (!$vehicleSaveOk ? ' 차량번호 저장도 실패했습니다.' : ''));
    else flash_set('success', $msg);

    $currentUser = Auth::user();
    if (is_array($currentUser)) {
        $currentEmail = isset($currentUser['email']) ? strtolower(trim((string)$currentUser['email'])) : '';
        $targetEmail = strtolower(trim((string)$email));
        if ($currentEmail !== '' && $targetEmail !== '' && $currentEmail === $targetEmail && method_exists('App\\Core\\Auth', 'refreshCurrentUser')) {
            Auth::refreshCurrentUser(true);
        }
    }
} catch (\Exception $e) { flash_set('error', '저장 실패: ' . $e->getMessage()); }

$redirectView = ($isActive === 0) ? 'retired' : 'active';
header('Location: ?r=관리&tab=employees&employee_view=' . $redirectView);
exit;
