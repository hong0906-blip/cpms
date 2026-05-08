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
    if (!Auth::check()) {
        cpms_gongsu_json_exit(false, '로그인이 필요합니다.', array(), 401);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        cpms_gongsu_json_exit(false, 'POST 요청만 허용됩니다.', array(), 405);
    }
    $csrf = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
    if (!csrf_check($csrf)) {
        cpms_gongsu_json_exit(false, 'CSRF 검증에 실패했습니다.', array(), 400);
    }

    // [변경] 마스터 공수 수정 권한 + Auth::canManageConstruction 권한 통일
    $isMaster = method_exists('Auth', 'isMaster') ? (bool)Auth::isMaster() : false;
    $canManageConstruction = method_exists('Auth', 'canManageConstruction') ? (bool)Auth::canManageConstruction() : false;
    if (!($isMaster || $canManageConstruction)) {
        // [변경] 권한 실패 진단 메시지
        $diagEmail = method_exists('Auth', 'userEmail') ? (string)Auth::userEmail() : '';
        $diagRole = method_exists('Auth', 'userRole') ? (string)Auth::userRole() : '';
        $diagDept = method_exists('Auth', 'userDepartment') ? (string)Auth::userDepartment() : '';
        cpms_gongsu_json_exit(
            false,
            '권한이 없습니다. email=' . $diagEmail . ', role=' . $diagRole . ', dept=' . $diagDept . ', master=' . ($isMaster ? 'Y' : 'N') . ', canManageConstruction=' . ($canManageConstruction ? 'Y' : 'N'),
            array(),
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

    $pdo = Db::pdo();
    if (!$pdo) cpms_gongsu_json_exit(false, 'DB 연결을 확인할 수 없습니다.', array(), 200);

    $role = method_exists('Auth', 'userRole') ? (string)Auth::userRole() : '';
    $email = method_exists('Auth', 'userEmail') ? (string)Auth::userEmail() : '';
    if (!$isMaster && !cpms_is_project_member_or_executive($pdo, $projectId, $role, $email)) {
        cpms_gongsu_json_exit(false, '담당 프로젝트만 수정할 수 있습니다. email=' . $email . ', role=' . $role . ', dept=' . (method_exists('Auth', 'userDepartment') ? (string)Auth::userDepartment() : '') . ', master=' . ($isMaster ? 'Y' : 'N') . ', canManageConstruction=' . ($canManageConstruction ? 'Y' : 'N'), array(), 403);
    }

    cpms_gongsu_ensure_override_table($pdo);

    $status = ($newValue >= 1.5) ? 'pending' : 'applied'; // 1.5 미만 즉시 반영 / 1.5 이상 승인대기
    $requestedBy = method_exists('Auth', 'id') ? (int)Auth::id() : 0;
    $requestedBy = ($requestedBy > 0) ? $requestedBy : null;
    $now = date('Y-m-d H:i:s');

    $sql = "INSERT INTO cpms_labor_gongsu_overrides
      (project_id, month, worker_key, worker_name, work_date, old_value, new_value, reason, status, requested_by, created_at, updated_at)
      VALUES
      (:project_id, :month, :worker_key, :worker_name, :work_date, :old_value, :new_value, :reason, :status, :requested_by, :created_at, :updated_at)
      ON DUPLICATE KEY UPDATE
        month = VALUES(month),
        worker_name = VALUES(worker_name),
        old_value = VALUES(old_value),
        new_value = VALUES(new_value),
        reason = VALUES(reason),
        status = VALUES(status),
        requested_by = VALUES(requested_by),
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
    $st->bindValue(':reason', $reason, PDO::PARAM_STR);
    $st->bindValue(':status', $status, PDO::PARAM_STR);
    if ($requestedBy === null) {
        $st->bindValue(':requested_by', null, PDO::PARAM_NULL);
    } else {
        $st->bindValue(':requested_by', $requestedBy, PDO::PARAM_INT);
    }
    $st->bindValue(':created_at', $now, PDO::PARAM_STR);
    $st->bindValue(':updated_at', $now, PDO::PARAM_STR);
    $st->execute();

    if ($status === 'applied') {
        cpms_gongsu_json_exit(true, '공수가 수정되었습니다.', array(
            'mode' => 'applied',
            'value' => number_format($newValue, 2, '.', '')
        ), 200);
    }

    $returnValue = ($oldValue === null) ? number_format($newValue, 2, '.', '') : number_format($oldValue, 2, '.', '');
    cpms_gongsu_json_exit(true, '1.5 이상 공수는 승인 요청으로 등록되었습니다.', array(
        'mode' => 'pending',
        'value' => $returnValue,
        'pending_value' => number_format($newValue, 2, '.', '')
    ), 200);
} catch (PDOException $e) {
    cpms_gongsu_json_exit(false, 'DB 처리 중 오류: ' . $e->getMessage(), array(), 200);
} catch (Exception $e) {
    cpms_gongsu_json_exit(false, '저장 처리 중 오류: ' . $e->getMessage(), array(), 200);
}