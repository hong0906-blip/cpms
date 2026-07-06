<?php
// 공수 수정 HTTP 500 방지 + JSON 응답 안정화
require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

if (function_exists('ob_get_level')) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}

header('Content-Type: application/json; charset=utf-8');

if (!function_exists('cpms_gongsu_json_exit')) {
    function cpms_gongsu_json_exit($ok, $message, $extra, $statusCode) {
        if (!headers_sent()) {
            http_response_code((int)$statusCode);
        }
        $payload = array('ok' => (bool)$ok, 'message' => (string)$message);
        if (is_array($extra)) {
            foreach ($extra as $k => $v) {
                $payload[$k] = $v;
            }
        }
        echo json_encode($payload);
        exit;
    }
}

if (!function_exists('cpms_gongsu_normalize_worker_key')) {
    function cpms_gongsu_normalize_worker_key($name) {
        $name = trim((string)$name);
        if ($name === '') return '';
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($name, 'UTF-8');
        }
        return strtolower($name);
    }
}

if (!function_exists('cpms_gongsu_column_exists')) {
    function cpms_gongsu_column_exists($pdo, $table, $column) {
        $st = $pdo->prepare("SHOW COLUMNS FROM `" . $table . "` LIKE :col");
        $st->bindValue(':col', $column, PDO::PARAM_STR);
        $st->execute();
        return ($st->fetch(PDO::FETCH_ASSOC) !== false);
    }
}

if (!function_exists('cpms_gongsu_index_exists')) {
    function cpms_gongsu_index_exists($pdo, $table, $indexName) {
        $st = $pdo->prepare("SHOW INDEX FROM `" . $table . "` WHERE Key_name = :idx");
        $st->bindValue(':idx', $indexName, PDO::PARAM_STR);
        $st->execute();
        return ($st->fetch(PDO::FETCH_ASSOC) !== false);
    }
}

if (!function_exists('cpms_gongsu_ensure_override_table')) {
    function cpms_gongsu_ensure_override_table($pdo) {
        if (function_exists('cpms_ensure_labor_override_table')) return cpms_ensure_labor_override_table($pdo);        
        $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_labor_gongsu_overrides (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            month CHAR(7) NOT NULL,
            worker_key VARCHAR(120) NOT NULL,
            worker_name VARCHAR(120) NOT NULL,
            work_date DATE NOT NULL,
            old_value DECIMAL(5,2) NULL,
            new_value DECIMAL(5,2) NOT NULL,
            reason VARCHAR(255) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'applied',
            requested_by INT NULL,
            approved_by INT NULL,
            approved_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uk_labor_override(project_id, worker_key, work_date),
            KEY idx_labor_override_project_month(project_id, month),
            KEY idx_labor_override_status(status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

        $addColumns = array(
            'month' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN month CHAR(7) NOT NULL AFTER project_id",
            'worker_key' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN worker_key VARCHAR(120) NOT NULL AFTER month",
            'worker_name' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN worker_name VARCHAR(120) NOT NULL AFTER worker_key",
            'work_date' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN work_date DATE NOT NULL AFTER worker_name",
            'old_value' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN old_value DECIMAL(5,2) NULL AFTER work_date",
            'new_value' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN new_value DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER old_value",
            'reason' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN reason VARCHAR(255) NULL AFTER new_value",
            'status' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'applied' AFTER reason",
            'requested_by' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN requested_by INT NULL AFTER status",
            'requested_by_email' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN requested_by_email VARCHAR(120) NULL AFTER requested_by",
            'requested_by_name' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN requested_by_name VARCHAR(80) NULL AFTER requested_by_email",            
            'approved_by' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN approved_by INT NULL AFTER requested_by",
            'approved_at' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN approved_at DATETIME NULL AFTER approved_by",
            'created_at' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN created_at DATETIME NOT NULL AFTER approved_at",
            'updated_at' => "ALTER TABLE cpms_labor_gongsu_overrides ADD COLUMN updated_at DATETIME NOT NULL AFTER created_at"
        );
        foreach ($addColumns as $column => $sql) {
            if (!cpms_gongsu_column_exists($pdo, 'cpms_labor_gongsu_overrides', $column)) {
                $pdo->exec($sql);
            }
        }

        if (!cpms_gongsu_index_exists($pdo, 'cpms_labor_gongsu_overrides', 'uk_labor_override')) {
            $pdo->exec("ALTER TABLE cpms_labor_gongsu_overrides ADD UNIQUE KEY uk_labor_override(project_id, worker_key, work_date)");
        }
        if (!cpms_gongsu_index_exists($pdo, 'cpms_labor_gongsu_overrides', 'idx_labor_override_project_month')) {
            $pdo->exec("ALTER TABLE cpms_labor_gongsu_overrides ADD KEY idx_labor_override_project_month(project_id, month)");
        }
        if (!cpms_gongsu_index_exists($pdo, 'cpms_labor_gongsu_overrides', 'idx_labor_override_status')) {
            $pdo->exec("ALTER TABLE cpms_labor_gongsu_overrides ADD KEY idx_labor_override_status(status)");
        }
        return true;
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        cpms_gongsu_json_exit(false, 'POST 요청만 허용됩니다.', array(), 405);
    }

    $hasSessionArray = isset($_SESSION) && is_array($_SESSION);
    // [변경] raw 세션 기반 권한 fallback
    $rawUserEmail = $hasSessionArray && isset($_SESSION['user_email']) ? trim((string)$_SESSION['user_email']) : '';
    $rawCpmsUserEmail = $hasSessionArray && isset($_SESSION['cpms_user']) && is_array($_SESSION['cpms_user']) && isset($_SESSION['cpms_user']['email']) ? trim((string)$_SESSION['cpms_user']['email']) : '';
    $rawCpmsUserRole = $hasSessionArray && isset($_SESSION['cpms_user']) && is_array($_SESSION['cpms_user']) && isset($_SESSION['cpms_user']['role']) ? trim((string)$_SESSION['cpms_user']['role']) : '';
    $rawCpmsUserDepartment = $hasSessionArray && isset($_SESSION['cpms_user']) && is_array($_SESSION['cpms_user']) && isset($_SESSION['cpms_user']['department']) ? trim((string)$_SESSION['cpms_user']['department']) : '';
    $hasUserEmailSession = ($rawUserEmail !== '');

    $authChecked = Auth::check();
    if (!$authChecked && $hasUserEmailSession && method_exists('App\\Core\\Auth', 'autoLoginFromPortal')) {
        Auth::autoLoginFromPortal();
        $authChecked = Auth::check();
    }

    // [변경] Auth 복구 후 재계산
    $authEmailBeforeRepair = method_exists('App\\Core\\Auth', 'userEmail') ? trim((string)Auth::userEmail()) : '';
    if ($authEmailBeforeRepair === '') {
        $repairEmail = $rawCpmsUserEmail !== '' ? $rawCpmsUserEmail : $rawUserEmail;
        if ($repairEmail !== '') {
            if (!isset($_SESSION[Auth::CPMS_USER_KEY]) || !is_array($_SESSION[Auth::CPMS_USER_KEY])) {
                $_SESSION[Auth::CPMS_USER_KEY] = array();
            }
            $_SESSION[Auth::CPMS_USER_KEY]['email'] = $repairEmail;
            if (!isset($_SESSION[Auth::CPMS_USER_KEY]['name']) || trim((string)$_SESSION[Auth::CPMS_USER_KEY]['name']) === '') {
                $_SESSION[Auth::CPMS_USER_KEY]['name'] = $repairEmail;
            }
            if (!isset($_SESSION[Auth::CPMS_USER_KEY]['role']) || trim((string)$_SESSION[Auth::CPMS_USER_KEY]['role']) === '') {
                $_SESSION[Auth::CPMS_USER_KEY]['role'] = $rawCpmsUserRole !== '' ? $rawCpmsUserRole : 'employee';
            }
            if (!isset($_SESSION[Auth::CPMS_USER_KEY]['department'])) {
                $_SESSION[Auth::CPMS_USER_KEY]['department'] = $rawCpmsUserDepartment;
            }
        }
    }
    Auth::refreshCurrentUser(true);
    $authChecked = Auth::check();

    $authEmail = method_exists('App\\Core\\Auth', 'userEmail') ? trim((string)Auth::userEmail()) : '';
    $authRole = method_exists('App\\Core\\Auth', 'userRole') ? trim((string)Auth::userRole()) : '';
    $authDepartment = method_exists('App\\Core\\Auth', 'userDepartment') ? trim((string)Auth::userDepartment()) : '';
    $isMaster = method_exists('App\\Core\\Auth', 'isMaster') ? (bool)Auth::isMaster() : false;
    $canManageConstruction = method_exists('App\\Core\\Auth', 'canManageConstruction') ? (bool)Auth::canManageConstruction() : false;
    // [변경] effective_email 계산
    $effectiveEmail = strtolower(trim($authEmail !== '' ? $authEmail : ($rawCpmsUserEmail !== '' ? $rawCpmsUserEmail : $rawUserEmail)));
    $effectiveRole = trim($authRole !== '' ? $authRole : $rawCpmsUserRole);
    $effectiveDepartment = trim($authDepartment !== '' ? $authDepartment : $rawCpmsUserDepartment);
    $effectiveDepartmentNorm = $effectiveDepartment;
    if ($effectiveDepartmentNorm === '공사부' || $effectiveDepartmentNorm === '공사팀') $effectiveDepartmentNorm = '공사';
    if ($effectiveDepartmentNorm === '공무부' || $effectiveDepartmentNorm === '공무팀') $effectiveDepartmentNorm = '공무';
    // 이메일 기반 마스터 예외는 사용하지 않음
    $isMasterByRaw = false;
    $allowedByRaw = ($effectiveRole === 'executive' || $effectiveDepartmentNorm === '공사' || $effectiveDepartmentNorm === '공무');
    
    if (!$authChecked) {
        cpms_gongsu_json_exit(false, '로그인 세션을 읽지 못했습니다. auth_email=' . $authEmail . ', auth_role=' . $authRole . ', auth_department=' . $authDepartment . ', master_by_auth=' . ($isMaster ? 'Y' : 'N') . ', canManageConstruction=' . ($canManageConstruction ? 'Y' : 'N') . ', session_id=' . session_id() . ', raw_user_email=' . $rawUserEmail . ', raw_cpms_user_email=' . $rawCpmsUserEmail . ', raw_cpms_user_role=' . $rawCpmsUserRole . ', raw_cpms_user_department=' . $rawCpmsUserDepartment . ', effective_email=' . $effectiveEmail . ', effective_role=' . $effectiveRole . ', effective_department=' . $effectiveDepartment . ', master_by_raw=' . ($isMasterByRaw ? 'Y' : 'N') . ', allowed_by_raw=' . ($allowedByRaw ? 'Y' : 'N'), array(
            'session_id' => session_id(),
            'has_session' => $hasSessionArray ? 'Y' : 'N',
            'has_user_email' => ($rawUserEmail !== '') ? 'Y' : 'N',
            'has_cpms_user' => ($rawCpmsUserEmail !== '') ? 'Y' : 'N',
                'email' => $authEmail,
                'role' => $authRole,
                'dept' => $authDepartment,
                'master' => $isMaster ? 'Y' : 'N',
                'canManageConstruction' => $canManageConstruction ? 'Y' : 'N',
                'raw_user_email' => $rawUserEmail,
                'raw_cpms_user_email' => $rawCpmsUserEmail,
                'raw_cpms_user_role' => $rawCpmsUserRole,
                'raw_cpms_user_department' => $rawCpmsUserDepartment,
                'effective_email' => $effectiveEmail,
                'effective_role' => $effectiveRole,
                'effective_department' => $effectiveDepartment,
                'is_master_by_raw' => $isMasterByRaw ? 'Y' : 'N',
                'allowed_by_raw' => $allowedByRaw ? 'Y' : 'N'
        ), 401);
    }

    $csrf = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
    if (!csrf_check($csrf)) {
        cpms_gongsu_json_exit(false, 'CSRF 검증에 실패했습니다.', array(), 400);
    }

    if (!($isMaster || $isMasterByRaw || $canManageConstruction || $allowedByRaw)) {
        // [변경] 권한 실패 진단 메시지
        cpms_gongsu_json_exit(
            false,
            '권한이 없습니다. auth_email=' . $authEmail . ', auth_role=' . $authRole . ', auth_department=' . $authDepartment . ', raw_user_email=' . $rawUserEmail . ', raw_cpms_user_email=' . $rawCpmsUserEmail . ', raw_cpms_user_role=' . $rawCpmsUserRole . ', raw_cpms_user_department=' . $rawCpmsUserDepartment . ', effective_email=' . $effectiveEmail . ', effective_role=' . $effectiveRole . ', effective_department=' . $effectiveDepartment . ', master_by_auth=' . ($isMaster ? 'Y' : 'N') . ', master_by_raw=' . ($isMasterByRaw ? 'Y' : 'N') . ', canManageConstruction=' . ($canManageConstruction ? 'Y' : 'N') . ', allowed_by_raw=' . ($allowedByRaw ? 'Y' : 'N') . ', session_id=' . session_id(),
            array(            
                'session_id' => session_id(),
                'has_session' => (isset($_SESSION) && is_array($_SESSION)) ? 'Y' : 'N',
                'has_user_email' => ($rawUserEmail !== '') ? 'Y' : 'N',
                'has_cpms_user' => ($rawCpmsUserEmail !== '') ? 'Y' : 'N',
                'email' => $authEmail,
                'role' => $authRole,
                'dept' => $authDepartment,
                'master' => $isMaster ? 'Y' : 'N',
                'canManageConstruction' => $canManageConstruction ? 'Y' : 'N',
                'raw_user_email' => $rawUserEmail,
                'raw_cpms_user_email' => $rawCpmsUserEmail,
                'raw_cpms_user_role' => $rawCpmsUserRole,
                'raw_cpms_user_department' => $rawCpmsUserDepartment,
                'effective_email' => $effectiveEmail,
                'effective_role' => $effectiveRole,
                'effective_department' => $effectiveDepartment,
                'is_master_by_raw' => $isMasterByRaw ? 'Y' : 'N',
                'allowed_by_raw' => $allowedByRaw ? 'Y' : 'N'
            ),
            403
        );
    }

    $projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
    $month = isset($_POST['month']) ? trim((string)$_POST['month']) : '';
    $workerName = isset($_POST['worker_name']) ? trim((string)$_POST['worker_name']) : '';
    $workerKey = isset($_POST['worker_key']) ? trim((string)$_POST['worker_key']) : '';
    $workDate = isset($_POST['work_date']) ? trim((string)$_POST['work_date']) : '';
    $oldValueRaw = isset($_POST['old_value']) ? trim((string)$_POST['old_value']) : '';
    $newValueRaw = isset($_POST['new_value']) ? trim((string)$_POST['new_value']) : '';
    $reason = isset($_POST['reason']) ? trim((string)$_POST['reason']) : '';
    $deleteMode = (isset($_POST['delete_mode']) && (int)$_POST['delete_mode'] === 1);

    if ($projectId <= 0) cpms_gongsu_json_exit(false, 'project_id 값이 누락되었거나 올바르지 않습니다.', array(), 200);
    if (!preg_match('/^\d{4}\-\d{2}$/', $month)) cpms_gongsu_json_exit(false, 'month 형식이 올바르지 않습니다. (YYYY-MM)', array(), 200);
    if ($workerName === '') cpms_gongsu_json_exit(false, 'worker_name 값이 필요합니다.', array(), 200);
    if (!preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $workDate)) cpms_gongsu_json_exit(false, 'work_date 형식이 올바르지 않습니다. (YYYY-MM-DD)', array(), 200);
    if ($workerKey === '') $workerKey = cpms_gongsu_normalize_worker_key($workerName);
    if ($workerKey === '') cpms_gongsu_json_exit(false, 'worker_key 생성에 실패했습니다.', array(), 200);
    if ($newValueRaw === '' || !is_numeric($newValueRaw)) cpms_gongsu_json_exit(false, 'new_value는 숫자여야 합니다.', array(), 200);

    $newValue = (float)$newValueRaw;
    if ($newValue < 0) cpms_gongsu_json_exit(false, 'new_value는 0 이상이어야 합니다.', array(), 200);
    if ($newValue > 999.99) cpms_gongsu_json_exit(false, 'new_value는 DECIMAL(5,2) 범위를 초과했습니다.', array(), 200);

    $newValue = (float)number_format($newValue, 2, '.', '');
    $oldValue = null;
    if ($oldValueRaw !== '' && is_numeric($oldValueRaw)) $oldValue = (float)number_format((float)$oldValueRaw, 2, '.', '');
    if ($deleteMode) {
        $newValue = 0.0;
        $reason = '';
    }
    if (!$deleteMode && $newValue >= 1.2 && $reason === '') cpms_gongsu_json_exit(false, '1.2 이상 공수 수정은 승인 요청사유가 필요합니다.', array(), 200);

    $pdo = Db::pdo();
    if (!$pdo) cpms_gongsu_json_exit(false, 'DB 연결을 확인할 수 없습니다.', array(), 200);

    // [변경] 마스터 담당 프로젝트 제한 예외 + 진단값 강화
    if (!($isMaster || $isMasterByRaw || $effectiveRole === 'executive' || $canManageConstruction || $effectiveDepartmentNorm === '공무')) {
        if (!cpms_is_project_member_or_executive($pdo, $projectId, $effectiveRole, $effectiveEmail)) {
            cpms_gongsu_json_exit(false,
                '담당 프로젝트만 수정할 수 있습니다. auth_email=' . $authEmail . ', raw_user_email=' . $rawUserEmail . ', raw_cpms_user_email=' . $rawCpmsUserEmail . ', effective_email=' . $effectiveEmail . ', effective_role=' . $effectiveRole . ', effective_department=' . $effectiveDepartment . ', master_by_auth=' . ($isMaster ? 'Y' : 'N') . ', master_by_raw=' . ($isMasterByRaw ? 'Y' : 'N') . ', canManageConstruction=' . ($canManageConstruction ? 'Y' : 'N') . ', project_id=' . $projectId . ', route_file=labor_gongsu_override_save',
                array(
                    'auth_email' => $authEmail,
                    'raw_user_email' => $rawUserEmail,
                    'raw_cpms_user_email' => $rawCpmsUserEmail,
                    'effective_email' => $effectiveEmail,
                    'effective_role' => $effectiveRole,
                    'effective_department' => $effectiveDepartment,
                    'master_by_auth' => $isMaster ? 'Y' : 'N',
                    'master_by_raw' => $isMasterByRaw ? 'Y' : 'N',
                    'canManageConstruction' => $canManageConstruction ? 'Y' : 'N',
                    'project_id' => $projectId,
                    'route_file' => 'labor_gongsu_override_save'
                ),
                403
            );
        }
    }

    cpms_gongsu_ensure_override_table($pdo);

    $approvalRequiredLevel = 'NONE';
    $approvalStage = 'COMPLETED';
    $currentApprover = null;
    $directorApprover = null;
    $isDeletedEntry = $deleteMode ? 1 : 0;
    if (!$deleteMode && $newValue >= 1.2) {
        $status = 'pending';
        $approvalStage = 'DIRECTOR_PENDING';
        $directorApprover = cpms_labor_find_director_approver($pdo);
        if (!$directorApprover) cpms_gongsu_json_exit(false, '공사PM 승인자를 직원명부에서 찾을 수 없습니다.', array(), 200);
        $currentApprover = $directorApprover;
        $approvalRequiredLevel = ($newValue >= 1.4) ? 'DIRECTOR_THEN_VP' : 'DIRECTOR_ONLY';
    } else {
        $status = 'applied';
    }
    // [변경] Auth::id 안전 처리
    $userId = 0;
    if (method_exists('App\\Core\\Auth', 'id')) {
        $userId = (int)Auth::id();
    }
    if ($userId <= 0 && isset($_SESSION['cpms_user']) && is_array($_SESSION['cpms_user']) && isset($_SESSION['cpms_user']['id'])) {
        $userId = (int)$_SESSION['cpms_user']['id'];
    }
    $requestedBy = ($userId > 0) ? $userId : null;
    // [변경] 요청자 저장(requested_by_email, requested_by_name)
    $requestedByEmail = trim((string)($authEmail !== '' ? $authEmail : ($rawCpmsUserEmail !== '' ? $rawCpmsUserEmail : $rawUserEmail)));
    $requestedByName = '';
    if (method_exists('App\\Core\\Auth', 'user')) {
        $u = Auth::user();
        if (is_array($u) && isset($u['name'])) $requestedByName = trim((string)$u['name']);
    }
    if ($requestedByName === '' && isset($_SESSION['cpms_user']) && is_array($_SESSION['cpms_user']) && isset($_SESSION['cpms_user']['name'])) {
        $requestedByName = trim((string)$_SESSION['cpms_user']['name']);
    }    
    $now = date('Y-m-d H:i:s');

    $currentApproverId = ($currentApprover && isset($currentApprover['id'])) ? (int)$currentApprover['id'] : null;
    $currentApproverName = ($currentApprover && isset($currentApprover['name'])) ? (string)$currentApprover['name'] : null;
    $currentApproverEmail = ($currentApprover && isset($currentApprover['email'])) ? (string)$currentApprover['email'] : null;
    $directorId = ($directorApprover && isset($directorApprover['id'])) ? (int)$directorApprover['id'] : null;
    $directorName = ($directorApprover && isset($directorApprover['name'])) ? (string)$directorApprover['name'] : null;
    $directorEmail = ($directorApprover && isset($directorApprover['email'])) ? (string)$directorApprover['email'] : null;
    
    $sql = "INSERT INTO cpms_labor_gongsu_overrides
      (project_id, month, worker_key, worker_name, work_date, old_value, new_value, is_deleted_entry, reason, status, requested_by, requested_by_email, requested_by_name, approval_stage, approval_required_level, current_approver_employee_id, current_approver_name, current_approver_email, first_approver_employee_id, first_approver_name, first_approver_email, approved_by, approved_at, first_approved_at, second_approved_at, final_approved_at, rejected_by, rejected_by_name, rejected_by_email, rejected_at, reject_reason, created_at, updated_at)
      VALUES
      (:project_id, :month, :worker_key, :worker_name, :work_date, :old_value, :new_value, :is_deleted_entry, :reason, :status, :requested_by, :requested_by_email, :requested_by_name, :approval_stage, :approval_required_level, :current_approver_employee_id, :current_approver_name, :current_approver_email, :first_approver_employee_id, :first_approver_name, :first_approver_email, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, :created_at, :updated_at)
      ON DUPLICATE KEY UPDATE
        month = VALUES(month),
        worker_name = VALUES(worker_name),
        old_value = VALUES(old_value),
        new_value = VALUES(new_value),
        is_deleted_entry = VALUES(is_deleted_entry),
        reason = VALUES(reason),
        status = VALUES(status),
        requested_by = VALUES(requested_by),
        requested_by_email = VALUES(requested_by_email),
        requested_by_name = VALUES(requested_by_name),
        approval_stage = VALUES(approval_stage),
        approval_required_level = VALUES(approval_required_level),
        current_approver_employee_id = VALUES(current_approver_employee_id),
        current_approver_name = VALUES(current_approver_name),
        current_approver_email = VALUES(current_approver_email),
        first_approver_employee_id = VALUES(first_approver_employee_id),
        first_approver_name = VALUES(first_approver_name),
        first_approver_email = VALUES(first_approver_email),
        approved_by = NULL,
        approved_at = NULL,
        first_approved_at = NULL,
        second_approved_at = NULL,
        final_approved_at = NULL,
        rejected_by = NULL,
        rejected_by_name = NULL,
        rejected_by_email = NULL,
        rejected_at = NULL,
        reject_reason = NULL,
        updated_at = VALUES(updated_at)";

    $st = $pdo->prepare($sql);
    $st->bindValue(':project_id', $projectId, PDO::PARAM_INT);
    $st->bindValue(':month', $month, PDO::PARAM_STR);
    $st->bindValue(':worker_key', $workerKey, PDO::PARAM_STR);
    $st->bindValue(':worker_name', $workerName, PDO::PARAM_STR);
    $st->bindValue(':work_date', $workDate, PDO::PARAM_STR);
    if ($oldValue === null) {
        $st->bindValue(':old_value', null, PDO::PARAM_NULL);
    } else {
        $st->bindValue(':old_value', $oldValue);
    }
    $st->bindValue(':new_value', $newValue);
    $st->bindValue(':is_deleted_entry', $isDeletedEntry, PDO::PARAM_INT);
    $st->bindValue(':reason', $reason, PDO::PARAM_STR);
    $st->bindValue(':status', $status, PDO::PARAM_STR);
    if ($requestedBy === null) {
        $st->bindValue(':requested_by', null, PDO::PARAM_NULL);
    } else {
        $st->bindValue(':requested_by', $requestedBy, PDO::PARAM_INT);
    }
    $st->bindValue(':requested_by_email', $requestedByEmail !== '' ? $requestedByEmail : null, $requestedByEmail !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $st->bindValue(':requested_by_name', $requestedByName !== '' ? $requestedByName : null, $requestedByName !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $st->bindValue(':approval_stage', $approvalStage, PDO::PARAM_STR);
    $st->bindValue(':approval_required_level', $approvalRequiredLevel, PDO::PARAM_STR);
    $st->bindValue(':current_approver_employee_id', $currentApproverId, $currentApproverId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->bindValue(':current_approver_name', $currentApproverName, $currentApproverName === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $st->bindValue(':current_approver_email', $currentApproverEmail, $currentApproverEmail === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $st->bindValue(':first_approver_employee_id', $directorId, $directorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->bindValue(':first_approver_name', $directorName, $directorName === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $st->bindValue(':first_approver_email', $directorEmail, $directorEmail === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $st->bindValue(':created_at', $now, PDO::PARAM_STR);
    $st->bindValue(':updated_at', $now, PDO::PARAM_STR);
    $st->execute();

    if ($status === 'applied') {
        cpms_gongsu_json_exit(true, $deleteMode ? '공수가 삭제되었습니다.' : '공수가 수정되었습니다.', array(
            'mode' => 'applied',
            'value' => $deleteMode ? '' : number_format($newValue, 2, '.', ''),
            'deleted' => $deleteMode ? 'Y' : 'N'
        ), 200);
    }

    $overrideId = (int)$pdo->lastInsertId();
    if ($overrideId <= 0) {
        $stFind = $pdo->prepare("SELECT id FROM cpms_labor_gongsu_overrides WHERE project_id=:project_id AND worker_key=:worker_key AND work_date=:work_date LIMIT 1");
        $stFind->execute(array(':project_id'=>$projectId, ':worker_key'=>$workerKey, ':work_date'=>$workDate));
        $overrideId = (int)$stFind->fetchColumn();
    }
    cpms_labor_send_override_notification($pdo, $overrideId, 'DIRECTOR_REQUEST');

    $returnValue = ($oldValue === null) ? number_format($newValue, 2, '.', '') : number_format($oldValue, 2, '.', '');
    if ($approvalRequiredLevel === 'DIRECTOR_THEN_VP') {
        cpms_gongsu_json_exit(true, '공사PM에게 1차 공수 수정 승인 요청을 보냈습니다. 승인 후 부사장에게 2차 승인 요청이 전달됩니다.', array(
            'mode' => 'pending',
            'approval_stage' => 'DIRECTOR_PENDING',
            'approval_required_level' => 'DIRECTOR_THEN_VP',
            'approver_name' => $directorName !== null && trim((string)$directorName) !== '' ? $directorName : '공사PM',
            'value' => $returnValue,
            'pending_value' => number_format($newValue, 2, '.', '')
        ), 200);
    }
    cpms_gongsu_json_exit(true, '공사PM에게 공수 수정 승인 요청을 보냈습니다.', array(
        'mode' => 'pending',
        'approval_stage' => 'DIRECTOR_PENDING',
        'approver_name' => $directorName !== null && trim((string)$directorName) !== '' ? $directorName : '공사PM',
        'value' => $returnValue,
        'pending_value' => number_format($newValue, 2, '.', '')
    ), 200);
} catch (PDOException $e) {
    cpms_gongsu_json_exit(false, 'DB 처리 중 오류: ' . $e->getMessage(), array(), 200);
} catch (Exception $e) {
    cpms_gongsu_json_exit(false, '저장 처리 중 오류: ' . $e->getMessage(), array(), 200);
}
