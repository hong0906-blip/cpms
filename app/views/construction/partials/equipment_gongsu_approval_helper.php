<?php
if (!function_exists('cpms_equipment_gongsu_column_exists')) {
function cpms_equipment_gongsu_column_exists($pdo, $table, $column) {
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

if (!function_exists('cpms_equipment_gongsu_index_exists')) {
function cpms_equipment_gongsu_index_exists($pdo, $table, $indexName) {
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

if (!function_exists('cpms_equipment_gongsu_ensure_schema')) {
function cpms_equipment_gongsu_ensure_schema($pdo) {
    if (!$pdo) return false;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_equipment_gongsu_overrides (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            equipment_usage_id INT NOT NULL,
            equipment_id INT NOT NULL,
            use_date DATE NOT NULL,
            old_value DECIMAL(6,2) NULL,
            new_value DECIMAL(6,2) NOT NULL,
            reason TEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            approval_required_level VARCHAR(30) NULL,
            approval_stage VARCHAR(30) NULL,
            current_approver_employee_id INT NULL,
            current_approver_name VARCHAR(100) NULL,
            current_approver_email VARCHAR(190) NULL,
            first_approver_employee_id INT NULL,
            first_approver_name VARCHAR(100) NULL,
            first_approver_email VARCHAR(190) NULL,
            first_approved_at DATETIME NULL,
            second_approver_employee_id INT NULL,
            second_approver_name VARCHAR(100) NULL,
            second_approver_email VARCHAR(190) NULL,
            second_approved_at DATETIME NULL,
            requested_by INT NULL,
            requested_by_name VARCHAR(100) NULL,
            requested_by_email VARCHAR(190) NULL,
            approved_by INT NULL,
            approved_at DATETIME NULL,
            rejected_by INT NULL,
            rejected_at DATETIME NULL,
            reject_reason TEXT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            KEY idx_equipment_gongsu_status (status),
            KEY idx_equipment_gongsu_current (current_approver_employee_id, status),
            KEY idx_equipment_usage (equipment_usage_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}

    $usageColumns = array(
        'work_unit' => "ALTER TABLE cpms_equipment_usage ADD COLUMN work_unit DECIMAL(6,2) NOT NULL DEFAULT 1.00",
        'base_rate_snapshot' => "ALTER TABLE cpms_equipment_usage ADD COLUMN base_rate_snapshot DECIMAL(15,2) NULL",
        'amount' => "ALTER TABLE cpms_equipment_usage ADD COLUMN amount DECIMAL(15,2) NULL",
        'is_manual_unit' => "ALTER TABLE cpms_equipment_usage ADD COLUMN is_manual_unit TINYINT(1) NOT NULL DEFAULT 0"
    );
    foreach ($usageColumns as $column => $sql) {
        if (!cpms_equipment_gongsu_column_exists($pdo, 'cpms_equipment_usage', $column)) {
            try { $pdo->exec($sql); } catch (Exception $e) {}
        }
    }

    $overrideColumns = array(
        'project_id' => "ALTER TABLE cpms_equipment_gongsu_overrides ADD COLUMN project_id INT NOT NULL AFTER id",
        'equipment_usage_id' => "ALTER TABLE cpms_equipment_gongsu_overrides ADD COLUMN equipment_usage_id INT NOT NULL AFTER project_id",
        'equipment_id' => "ALTER TABLE cpms_equipment_gongsu_overrides ADD COLUMN equipment_id INT NOT NULL AFTER equipment_usage_id",
        'use_date' => "ALTER TABLE cpms_equipment_gongsu_overrides ADD COLUMN use_date DATE NOT NULL AFTER equipment_id",
        'old_value' => "ALTER TABLE cpms_equipment_gongsu_overrides ADD COLUMN old_value DECIMAL(6,2) NULL AFTER use_date",
        'new_value' => "ALTER TABLE cpms_equipment_gongsu_overrides ADD COLUMN new_value DECIMAL(6,2) NOT NULL DEFAULT 0.00 AFTER old_value",
        'reason' => "ALTER TABLE cpms_equipment_gongsu_overrides ADD COLUMN reason TEXT NULL AFTER new_value",
        'status' => "ALTER TABLE cpms_equipment_gongsu_overrides ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'pending' AFTER reason",
        'approval_required_level' => "ALTER TABLE cpms_equipment_gongsu_overrides ADD COLUMN approval_required_level VARCHAR(30) NULL AFTER status",
        'approval_stage' => "ALTER TABLE cpms_equipment_gongsu_overrides ADD COLUMN approval_stage VARCHAR(30) NULL AFTER approval_required_level",
        'current_approver_employee_id' => "ALTER TABLE cpms_equipment_gongsu_overrides ADD COLUMN current_approver_employee_id INT NULL AFTER approval_stage",
        'current_approver_name' => "ALTER TABLE cpms_equipment_gongsu_overrides ADD COLUMN current_approver_name VARCHAR(100) NULL AFTER current_approver_employee_id",
        'current_approver_email' => "ALTER TABLE cpms_equipment_gongsu_overrides ADD COLUMN current_approver_email VARCHAR(190) NULL AFTER current_approver_name",
        'first_approver_employee_id' => "ALTER TABLE cpms_equipment_gongsu_overrides ADD COLUMN first_approver_employee_id INT NULL AFTER current_approver_email",
        'first_approver_name' => "ALTER TABLE cpms_equipment_gongsu_overrides ADD COLUMN first_approver_name VARCHAR(100) NULL AFTER first_approver_employee_id",
        'first_approver_email' => "ALTER TABLE cpms_equipment_gongsu_overrides ADD COLUMN first_approver_email VARCHAR(190) NULL AFTER first_approver_name",
        'first_approved_at' => "ALTER TABLE cpms_equipment_gongsu_overrides ADD COLUMN first_approved_at DATETIME NULL AFTER first_approver_email",
        'second_approver_employee_id' => "ALTER TABLE cpms_equipment_gongsu_overrides ADD COLUMN second_approver_employee_id INT NULL AFTER first_approved_at",
        'second_approver_name' => "ALTER TABLE cpms_equipment_gongsu_overrides ADD COLUMN second_approver_name VARCHAR(100) NULL AFTER second_approver_employee_id",
        'second_approver_email' => "ALTER TABLE cpms_equipment_gongsu_overrides ADD COLUMN second_approver_email VARCHAR(190) NULL AFTER second_approver_name",
        'second_approved_at' => "ALTER TABLE cpms_equipment_gongsu_overrides ADD COLUMN second_approved_at DATETIME NULL AFTER second_approver_email",
        'requested_by' => "ALTER TABLE cpms_equipment_gongsu_overrides ADD COLUMN requested_by INT NULL AFTER second_approved_at",
        'requested_by_name' => "ALTER TABLE cpms_equipment_gongsu_overrides ADD COLUMN requested_by_name VARCHAR(100) NULL AFTER requested_by",
        'requested_by_email' => "ALTER TABLE cpms_equipment_gongsu_overrides ADD COLUMN requested_by_email VARCHAR(190) NULL AFTER requested_by_name",
        'approved_by' => "ALTER TABLE cpms_equipment_gongsu_overrides ADD COLUMN approved_by INT NULL AFTER requested_by_email",
        'approved_at' => "ALTER TABLE cpms_equipment_gongsu_overrides ADD COLUMN approved_at DATETIME NULL AFTER approved_by",
        'rejected_by' => "ALTER TABLE cpms_equipment_gongsu_overrides ADD COLUMN rejected_by INT NULL AFTER approved_at",
        'rejected_at' => "ALTER TABLE cpms_equipment_gongsu_overrides ADD COLUMN rejected_at DATETIME NULL AFTER rejected_by",
        'reject_reason' => "ALTER TABLE cpms_equipment_gongsu_overrides ADD COLUMN reject_reason TEXT NULL AFTER rejected_at",
        'created_at' => "ALTER TABLE cpms_equipment_gongsu_overrides ADD COLUMN created_at DATETIME NULL AFTER reject_reason",
        'updated_at' => "ALTER TABLE cpms_equipment_gongsu_overrides ADD COLUMN updated_at DATETIME NULL AFTER created_at"
    );
    foreach ($overrideColumns as $column => $sql) {
        if (!cpms_equipment_gongsu_column_exists($pdo, 'cpms_equipment_gongsu_overrides', $column)) {
            try { $pdo->exec($sql); } catch (Exception $e) {}
        }
    }
    if (!cpms_equipment_gongsu_index_exists($pdo, 'cpms_equipment_gongsu_overrides', 'idx_equipment_gongsu_current')) {
        try { $pdo->exec("ALTER TABLE cpms_equipment_gongsu_overrides ADD KEY idx_equipment_gongsu_current(current_approver_employee_id, status)"); } catch (Exception $e) {}
    }
    try {
        $pdo->exec("UPDATE cpms_equipment_usage u INNER JOIN cpms_equipment_items e ON e.id = u.equipment_id SET u.base_rate_snapshot = e.base_rate WHERE (u.base_rate_snapshot IS NULL OR u.base_rate_snapshot = 0)");
        $pdo->exec("UPDATE cpms_equipment_usage SET work_unit = 1.00 WHERE work_unit IS NULL OR work_unit <= 0");
        $pdo->exec("UPDATE cpms_equipment_usage SET amount = work_unit * COALESCE(base_rate_snapshot, 0) WHERE amount IS NULL OR amount = 0");
    } catch (Exception $e) {}
    return true;
}}

if (!function_exists('cpms_equipment_gongsu_format')) {
function cpms_equipment_gongsu_format($value) {
    $s = number_format((float)$value, 2, '.', '');
    $s = rtrim(rtrim($s, '0'), '.');
    return $s === '' ? '0' : $s;
}}

if (!function_exists('cpms_equipment_gongsu_apply_usage')) {
function cpms_equipment_gongsu_apply_usage($pdo, $usageId, $newValue) {
    $st = $pdo->prepare("UPDATE cpms_equipment_usage SET work_unit=:wu, amount=:amt, is_manual_unit=1 WHERE id=:id");
    $rateSt = $pdo->prepare("SELECT COALESCE(base_rate_snapshot, 0) FROM cpms_equipment_usage WHERE id=:id LIMIT 1");
    $rateSt->execute(array(':id'=>(int)$usageId));
    $rate = (float)$rateSt->fetchColumn();
    $st->execute(array(':wu'=>(float)$newValue, ':amt'=>((float)$newValue * $rate), ':id'=>(int)$usageId));
}}

if (!function_exists('cpms_equipment_gongsu_build_message')) {
function cpms_equipment_gongsu_build_message($pdo, $row, $secondStage) {
    $projectName = function_exists('cpms_labor_project_name') ? cpms_labor_project_name($pdo, isset($row['project_id']) ? (int)$row['project_id'] : 0) : '';
    $requester = isset($row['requested_by_name']) && trim((string)$row['requested_by_name']) !== '' ? trim((string)$row['requested_by_name']) : (isset($row['requested_by_email']) ? trim((string)$row['requested_by_email']) : '');
    if ($requester === '') $requester = '-';
    $equipmentName = isset($row['equipment_name']) ? trim((string)$row['equipment_name']) : '';
    if ($equipmentName === '') $equipmentName = isset($row['vendor_name']) ? trim((string)$row['vendor_name']) : '';
    $reason = isset($row['reason']) ? trim((string)$row['reason']) : '';
    $lines = array();
    array_push($lines, $secondStage ? '[CPMS 장비공수 2차 승인 요청]' : '[CPMS 장비공수 수정 요청]');
    array_push($lines, '');
    array_push($lines, '현장명 : ' . $projectName);
    array_push($lines, '요청자 : ' . $requester);
    array_push($lines, '장비명 : ' . ($equipmentName !== '' ? $equipmentName : '-'));
    array_push($lines, '사용일자 : ' . (isset($row['use_date']) ? (string)$row['use_date'] : '-'));
    array_push($lines, '요청 내용 : 장비공수 ' . cpms_equipment_gongsu_format(isset($row['old_value']) ? $row['old_value'] : 0) . ' -> ' . cpms_equipment_gongsu_format(isset($row['new_value']) ? $row['new_value'] : 0) . ' 변경');
    array_push($lines, '요청 사유 : ' . ($reason !== '' ? $reason : '-'));
    if ($secondStage) array_push($lines, '1차 승인자 : ' . (isset($row['first_approver_name']) && trim((string)$row['first_approver_name']) !== '' ? trim((string)$row['first_approver_name']) : '박원덕'));
    array_push($lines, '');
    array_push($lines, '요청내용 확인 바랍니다.');
    return implode("\n", $lines);
}}

if (!function_exists('cpms_equipment_gongsu_send_notification')) {
function cpms_equipment_gongsu_send_notification($pdo, $overrideId, $eventType) {
    try {
        if (!$pdo || (int)$overrideId <= 0 || !function_exists('cpms_send_google_chat_to_employee')) return false;
        $st = $pdo->prepare("SELECT o.*, e.vendor_name, e.spec, CONCAT(e.vendor_name, ' ', e.spec) AS equipment_name FROM cpms_equipment_gongsu_overrides o LEFT JOIN cpms_equipment_items e ON e.id=o.equipment_id WHERE o.id=:id LIMIT 1");
        $st->execute(array(':id'=>(int)$overrideId));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;
        $employeeId = isset($row['current_approver_employee_id']) ? (int)$row['current_approver_employee_id'] : 0;
        return cpms_send_google_chat_to_employee($pdo, $employeeId, cpms_equipment_gongsu_build_message($pdo, $row, $eventType === 'VP_REQUEST'), (int)$overrideId, $eventType, 'EQUIPMENT_GONGSU_OVERRIDE');
    } catch (Exception $e) {
        error_log('[equipment_gongsu_chat] ' . $e->getMessage());
        return false;
    }
}}
?>
