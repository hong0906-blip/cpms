<?php
/**
 * 공사 > 상황: 목표원가율 저장/승인 helper
 * - PHP 5.6 호환
 */

if (!function_exists('cpms_target_cost_rate_column_exists')) {
function cpms_target_cost_rate_column_exists($pdo, $table, $column) {
    if (!$pdo) return false;
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `" . $table . "` LIKE :col");
        $st->bindValue(':col', (string)$column);
        $st->execute();
        return $st->fetch() ? true : false;
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_target_cost_rate_index_exists')) {
function cpms_target_cost_rate_index_exists($pdo, $table, $indexName) {
    if (!$pdo) return false;
    try {
        $st = $pdo->prepare("SHOW INDEX FROM `" . $table . "` WHERE Key_name = :idx");
        $st->bindValue(':idx', (string)$indexName);
        $st->execute();
        return $st->fetch() ? true : false;
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_target_cost_rate_ensure_schema')) {
function cpms_target_cost_rate_ensure_schema($pdo) {
    if (!$pdo) return false;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_project_target_cost_rates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            target_rate DECIMAL(8,3) NOT NULL DEFAULT 0,
            created_by INT NULL,
            created_by_name VARCHAR(100) NULL,
            created_at DATETIME NULL,
            updated_by INT NULL,
            updated_by_name VARCHAR(100) NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uk_project_target_rate (project_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_project_target_cost_rate_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            old_rate DECIMAL(8,3) NOT NULL DEFAULT 0,
            new_rate DECIMAL(8,3) NOT NULL DEFAULT 0,
            reason TEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            requested_by INT NULL,
            requested_by_name VARCHAR(100) NULL,
            requested_by_email VARCHAR(190) NULL,
            approver_employee_id INT NULL,
            approver_name VARCHAR(100) NULL,
            approver_email VARCHAR(190) NULL,
            decided_by INT NULL,
            decided_by_name VARCHAR(100) NULL,
            decided_by_email VARCHAR(190) NULL,
            decided_at DATETIME NULL,
            decision_memo TEXT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            KEY idx_target_rate_project_status (project_id, status),
            KEY idx_target_rate_approver_status (approver_employee_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}

    $rateColumns = array(
        'project_id' => "ALTER TABLE cpms_project_target_cost_rates ADD COLUMN project_id INT NOT NULL AFTER id",
        'target_rate' => "ALTER TABLE cpms_project_target_cost_rates ADD COLUMN target_rate DECIMAL(8,3) NOT NULL DEFAULT 0 AFTER project_id",
        'created_by' => "ALTER TABLE cpms_project_target_cost_rates ADD COLUMN created_by INT NULL AFTER target_rate",
        'created_by_name' => "ALTER TABLE cpms_project_target_cost_rates ADD COLUMN created_by_name VARCHAR(100) NULL AFTER created_by",
        'created_at' => "ALTER TABLE cpms_project_target_cost_rates ADD COLUMN created_at DATETIME NULL AFTER created_by_name",
        'updated_by' => "ALTER TABLE cpms_project_target_cost_rates ADD COLUMN updated_by INT NULL AFTER created_at",
        'updated_by_name' => "ALTER TABLE cpms_project_target_cost_rates ADD COLUMN updated_by_name VARCHAR(100) NULL AFTER updated_by",
        'updated_at' => "ALTER TABLE cpms_project_target_cost_rates ADD COLUMN updated_at DATETIME NULL AFTER updated_by_name"
    );
    foreach ($rateColumns as $column => $sql) {
        if (!cpms_target_cost_rate_column_exists($pdo, 'cpms_project_target_cost_rates', $column)) {
            try { $pdo->exec($sql); } catch (Exception $e) {}
        }
    }
    if (!cpms_target_cost_rate_index_exists($pdo, 'cpms_project_target_cost_rates', 'uk_project_target_rate')) {
        try { $pdo->exec("ALTER TABLE cpms_project_target_cost_rates ADD UNIQUE KEY uk_project_target_rate(project_id)"); } catch (Exception $e) {}
    }

    $requestColumns = array(
        'project_id' => "ALTER TABLE cpms_project_target_cost_rate_requests ADD COLUMN project_id INT NOT NULL AFTER id",
        'old_rate' => "ALTER TABLE cpms_project_target_cost_rate_requests ADD COLUMN old_rate DECIMAL(8,3) NOT NULL DEFAULT 0 AFTER project_id",
        'new_rate' => "ALTER TABLE cpms_project_target_cost_rate_requests ADD COLUMN new_rate DECIMAL(8,3) NOT NULL DEFAULT 0 AFTER old_rate",
        'reason' => "ALTER TABLE cpms_project_target_cost_rate_requests ADD COLUMN reason TEXT NULL AFTER new_rate",
        'status' => "ALTER TABLE cpms_project_target_cost_rate_requests ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'pending' AFTER reason",
        'requested_by' => "ALTER TABLE cpms_project_target_cost_rate_requests ADD COLUMN requested_by INT NULL AFTER status",
        'requested_by_name' => "ALTER TABLE cpms_project_target_cost_rate_requests ADD COLUMN requested_by_name VARCHAR(100) NULL AFTER requested_by",
        'requested_by_email' => "ALTER TABLE cpms_project_target_cost_rate_requests ADD COLUMN requested_by_email VARCHAR(190) NULL AFTER requested_by_name",
        'approver_employee_id' => "ALTER TABLE cpms_project_target_cost_rate_requests ADD COLUMN approver_employee_id INT NULL AFTER requested_by_email",
        'approver_name' => "ALTER TABLE cpms_project_target_cost_rate_requests ADD COLUMN approver_name VARCHAR(100) NULL AFTER approver_employee_id",
        'approver_email' => "ALTER TABLE cpms_project_target_cost_rate_requests ADD COLUMN approver_email VARCHAR(190) NULL AFTER approver_name",
        'decided_by' => "ALTER TABLE cpms_project_target_cost_rate_requests ADD COLUMN decided_by INT NULL AFTER approver_email",
        'decided_by_name' => "ALTER TABLE cpms_project_target_cost_rate_requests ADD COLUMN decided_by_name VARCHAR(100) NULL AFTER decided_by",
        'decided_by_email' => "ALTER TABLE cpms_project_target_cost_rate_requests ADD COLUMN decided_by_email VARCHAR(190) NULL AFTER decided_by_name",
        'decided_at' => "ALTER TABLE cpms_project_target_cost_rate_requests ADD COLUMN decided_at DATETIME NULL AFTER decided_by_email",
        'decision_memo' => "ALTER TABLE cpms_project_target_cost_rate_requests ADD COLUMN decision_memo TEXT NULL AFTER decided_at",
        'created_at' => "ALTER TABLE cpms_project_target_cost_rate_requests ADD COLUMN created_at DATETIME NULL AFTER decision_memo",
        'updated_at' => "ALTER TABLE cpms_project_target_cost_rate_requests ADD COLUMN updated_at DATETIME NULL AFTER created_at"
    );
    foreach ($requestColumns as $column => $sql) {
        if (!cpms_target_cost_rate_column_exists($pdo, 'cpms_project_target_cost_rate_requests', $column)) {
            try { $pdo->exec($sql); } catch (Exception $e) {}
        }
    }
    if (!cpms_target_cost_rate_index_exists($pdo, 'cpms_project_target_cost_rate_requests', 'idx_target_rate_project_status')) {
        try { $pdo->exec("ALTER TABLE cpms_project_target_cost_rate_requests ADD KEY idx_target_rate_project_status(project_id, status)"); } catch (Exception $e) {}
    }
    if (!cpms_target_cost_rate_index_exists($pdo, 'cpms_project_target_cost_rate_requests', 'idx_target_rate_approver_status')) {
        try { $pdo->exec("ALTER TABLE cpms_project_target_cost_rate_requests ADD KEY idx_target_rate_approver_status(approver_employee_id, status)"); } catch (Exception $e) {}
    }
    return true;
}}

if (!function_exists('cpms_target_cost_rate_current')) {
function cpms_target_cost_rate_current($pdo, $projectId) {
    if (!$pdo || (int)$projectId <= 0) return null;
    try {
        cpms_target_cost_rate_ensure_schema($pdo);
        $st = $pdo->prepare("SELECT * FROM cpms_project_target_cost_rates WHERE project_id=:pid LIMIT 1");
        $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? $row : null;
    } catch (Exception $e) {
        return null;
    }
}}

if (!function_exists('cpms_target_cost_rate_pending')) {
function cpms_target_cost_rate_pending($pdo, $projectId) {
    if (!$pdo || (int)$projectId <= 0) return null;
    try {
        cpms_target_cost_rate_ensure_schema($pdo);
        $st = $pdo->prepare("SELECT * FROM cpms_project_target_cost_rate_requests WHERE project_id=:pid AND status='pending' ORDER BY id DESC LIMIT 1");
        $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? $row : null;
    } catch (Exception $e) {
        return null;
    }
}}

if (!function_exists('cpms_target_cost_rate_parse')) {
function cpms_target_cost_rate_parse($value) {
    $value = trim((string)$value);
    $value = str_replace(array('%', ',', ' '), '', $value);
    if ($value === '' || !is_numeric($value)) return null;
    $rate = (float)$value;
    if ($rate < 0) return null;
    if ($rate > 999) return null;
    return round($rate, 3);
}}

if (!function_exists('cpms_target_cost_rate_format')) {
function cpms_target_cost_rate_format($rate) {
    $rate = (float)$rate;
    if ($rate <= 0) return '-';
    $label = number_format($rate, 1);
    $label = rtrim(rtrim($label, '0'), '.');
    return $label . '%';
}}

if (!function_exists('cpms_target_cost_rate_approved_history')) {
function cpms_target_cost_rate_approved_history($pdo, $projectId) {
    $history = array();
    if (!$pdo || (int)$projectId <= 0) return $history;
    static $cache = array();
    $pdoKey = function_exists('spl_object_hash') ? spl_object_hash($pdo) : 'pdo';
    $cacheKey = $pdoKey . ':' . (int)$projectId;
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];

    try {
        cpms_target_cost_rate_ensure_schema($pdo);
        $st = $pdo->prepare("SELECT id, old_rate, new_rate, decided_at, updated_at, created_at
            FROM cpms_project_target_cost_rate_requests
            WHERE project_id=:pid AND status='approved'
            ORDER BY COALESCE(decided_at, updated_at, created_at), id");
        $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) $rows = array();

        foreach ($rows as $row) {
            $effectiveYm = '';
            foreach (array('decided_at', 'updated_at', 'created_at') as $dateKey) {
                $rawDate = isset($row[$dateKey]) ? trim((string)$row[$dateKey]) : '';
                if (preg_match('/^\d{4}-\d{2}/', $rawDate)) {
                    $effectiveYm = substr($rawDate, 0, 7);
                    break;
                }
            }
            if ($effectiveYm === '') $effectiveYm = date('Y-m');

            $history[] = array(
                'id' => isset($row['id']) ? (int)$row['id'] : 0,
                'old_rate' => isset($row['old_rate']) ? (float)$row['old_rate'] : 0.0,
                'new_rate' => isset($row['new_rate']) ? (float)$row['new_rate'] : 0.0,
                'effective_ym' => $effectiveYm,
            );
        }
    } catch (Exception $e) {
        $history = array();
    }

    $cache[$cacheKey] = $history;
    return $history;
}}

if (!function_exists('cpms_target_cost_rate_effective_map')) {
function cpms_target_cost_rate_effective_map($pdo, $projectId, $months, $fallbackRate) {
    $map = array();
    if (!is_array($months) || count($months) === 0) return $map;

    $monthSet = array();
    foreach ($months as $ym) {
        $ym = trim((string)$ym);
        if (preg_match('/^\d{4}-\d{2}$/', $ym)) $monthSet[$ym] = true;
    }
    $sortedMonths = array_keys($monthSet);
    sort($sortedMonths, SORT_STRING);
    if (count($sortedMonths) === 0) return $map;

    $history = cpms_target_cost_rate_approved_history($pdo, (int)$projectId);
    $baseRate = (float)$fallbackRate;
    if (is_array($history) && count($history) > 0) {
        foreach ($history as $event) {
            $oldRate = isset($event['old_rate']) ? (float)$event['old_rate'] : 0.0;
            if ($oldRate > 0) {
                $baseRate = $oldRate;
                break;
            }
        }
    }

    $currentRate = $baseRate;
    $eventIndex = 0;
    $eventCount = is_array($history) ? count($history) : 0;

    foreach ($sortedMonths as $ym) {
        while ($eventIndex < $eventCount) {
            $eventYm = isset($history[$eventIndex]['effective_ym']) ? (string)$history[$eventIndex]['effective_ym'] : '';
            if ($eventYm === '' || $eventYm > $ym) break;
            $newRate = isset($history[$eventIndex]['new_rate']) ? (float)$history[$eventIndex]['new_rate'] : 0.0;
            if ($newRate > 0) $currentRate = $newRate;
            $eventIndex++;
        }
        $map[$ym] = $currentRate;
    }

    foreach ($months as $ym) {
        $ym = trim((string)$ym);
        if ($ym !== '' && !isset($map[$ym])) $map[$ym] = $baseRate;
    }
    return $map;
}}

if (!function_exists('cpms_target_cost_rate_user_employee')) {
function cpms_target_cost_rate_user_employee($pdo) {
    if (!$pdo || !class_exists('App\\Core\\Auth')) return null;
    $email = method_exists('App\\Core\\Auth', 'userEmail') ? trim((string)\App\Core\Auth::userEmail()) : '';
    if ($email !== '' && function_exists('cpms_labor_find_employee_by_email')) {
        $employee = cpms_labor_find_employee_by_email($pdo, $email);
        if ($employee) return $employee;
    }
    return null;
}}

if (!function_exists('cpms_target_cost_rate_vp_approver')) {
function cpms_target_cost_rate_vp_approver($pdo) {
    if ($pdo && function_exists('cpms_labor_find_vp_approver')) {
        $vp = cpms_labor_find_vp_approver($pdo);
        if ($vp) return $vp;
    }
    return null;
}}

if (!function_exists('cpms_target_cost_rate_is_vp_user')) {
function cpms_target_cost_rate_is_vp_user($pdo) {
    if (!class_exists('App\\Core\\Auth') || !\App\Core\Auth::check()) return false;
    if (\App\Core\Auth::isMaster()) return true;

    $role = trim((string)\App\Core\Auth::userRole());
    $position = trim((string)\App\Core\Auth::userPosition());
    $haystack = $role . ' ' . $position;
    if (function_exists('mb_strtolower')) {
        $haystack = mb_strtolower($haystack, 'UTF-8');
    } else {
        $haystack = strtolower($haystack);
    }

    if (strpos($haystack, '부사장') !== false) return true;
    if (strpos($haystack, 'vicepresident') !== false) return true;
    if (strpos($haystack, 'vp') !== false) return true;

    $employee = cpms_target_cost_rate_user_employee($pdo);
    if ($employee && isset($employee['position'])) {
        $employeePosition = (string)$employee['position'];
        if (strpos($employeePosition, '부사장') !== false) return true;
    }
    return false;
}}

if (!function_exists('cpms_target_cost_rate_send_notification')) {
function cpms_target_cost_rate_send_notification($pdo, $requestId) {
    if (!$pdo || (int)$requestId <= 0 || !function_exists('cpms_send_google_chat_to_employee')) return false;
    try {
        $st = $pdo->prepare("SELECT r.*, p.name AS project_name FROM cpms_project_target_cost_rate_requests r LEFT JOIN cpms_projects p ON p.id=r.project_id WHERE r.id=:id LIMIT 1");
        $st->bindValue(':id', (int)$requestId, PDO::PARAM_INT);
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;
        $employeeId = isset($row['approver_employee_id']) ? (int)$row['approver_employee_id'] : 0;
        if ($employeeId <= 0) return false;

        $lines = array();
        $projectName = isset($row['project_name']) ? trim((string)$row['project_name']) : '';
        $requester = isset($row['requested_by_name']) ? trim((string)$row['requested_by_name']) : '';
        if ($requester === '') $requester = isset($row['requested_by_email']) ? trim((string)$row['requested_by_email']) : '-';
        array_push($lines, '[CPMS 목표원가율 변경 승인 요청]');
        array_push($lines, '');
        array_push($lines, '현장명 : ' . ($projectName !== '' ? $projectName : '-'));
        array_push($lines, '요청자 : ' . $requester);
        array_push($lines, '변경내용 : ' . cpms_target_cost_rate_format(isset($row['old_rate']) ? $row['old_rate'] : 0) . ' -> ' . cpms_target_cost_rate_format(isset($row['new_rate']) ? $row['new_rate'] : 0));
        array_push($lines, '사유 : ' . (isset($row['reason']) && trim((string)$row['reason']) !== '' ? trim((string)$row['reason']) : '-'));
        array_push($lines, '');
        array_push($lines, '공사 > 상황 탭에서 승인 처리 바랍니다.');
        return cpms_send_google_chat_to_employee($pdo, $employeeId, implode("\n", $lines), (int)$requestId, 'TARGET_COST_RATE_REQUEST', 'TARGET_COST_RATE');
    } catch (Exception $e) {
        error_log('[target_cost_rate_chat] ' . $e->getMessage());
        return false;
    }
}}
?>
