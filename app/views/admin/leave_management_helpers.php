<?php
/**
 * Leave management helpers
 * - PHP 5.6 compatible
 */

if (!function_exists('cpms_leave_parse_date')) {
function cpms_leave_parse_date($value)
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    $ts = strtotime($value);
    if (!$ts) {
        return '';
    }
    return date('Y-m-d', $ts);
}}

if (!function_exists('cpms_leave_column_exists')) {
function cpms_leave_column_exists($pdo, $table, $column)
{
    if (!$pdo || trim((string)$table) === '' || trim((string)$column) === '') {
        return false;
    }
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:tbl AND COLUMN_NAME=:col");
        $st->execute(array(':tbl' => $table, ':col' => $column));
        return ((int)$st->fetchColumn() > 0);
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_leave_table_exists')) {
function cpms_leave_table_exists($pdo, $table)
{
    if (!$pdo || trim((string)$table) === '') {
        return false;
    }
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE :tbl");
        $st->execute(array(':tbl' => $table));
        return (bool)$st->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_leave_schema_ready_for_accruals')) {
function cpms_leave_schema_ready_for_accruals($pdo)
{
    if (!$pdo) {
        return false;
    }
    if (!cpms_leave_table_exists($pdo, 'cpms_leave_accrual_logs')) {
        return false;
    }
    if (!cpms_leave_column_exists($pdo, 'employees', 'hire_date')) {
        return false;
    }
    if (!cpms_leave_column_exists($pdo, 'employees', 'leave_monthly_balance')) {
        return false;
    }
    if (!cpms_leave_column_exists($pdo, 'employees', 'leave_annual_balance')) {
        return false;
    }
    return true;
}}

if (!function_exists('cpms_leave_add_days')) {
function cpms_leave_add_days($date, $days)
{
    $date = cpms_leave_parse_date($date);
    if ($date === '') {
        return '';
    }
    $days = (int)$days;
    $modifier = ($days >= 0 ? '+' : '') . $days . ' day';
    $ts = strtotime($date . ' ' . $modifier);
    if (!$ts) {
        return '';
    }
    return date('Y-m-d', $ts);
}}

if (!function_exists('cpms_leave_add_months_clamped')) {
function cpms_leave_add_months_clamped($date, $months)
{
    $date = cpms_leave_parse_date($date);
    if ($date === '') {
        return '';
    }
    $ts = strtotime($date);
    if (!$ts) {
        return '';
    }
    $year = (int)date('Y', $ts);
    $month = (int)date('n', $ts);
    $day = (int)date('j', $ts);
    $total = ($month - 1) + (int)$months;
    $targetYear = $year + (int)floor($total / 12);
    $targetMonth = ($total % 12) + 1;
    if ($targetMonth <= 0) {
        $targetMonth += 12;
        $targetYear--;
    }
    $lastDay = (int)date('t', mktime(0, 0, 0, $targetMonth, 1, $targetYear));
    if ($day > $lastDay) {
        $day = $lastDay;
    }
    return date('Y-m-d', mktime(0, 0, 0, $targetMonth, $day, $targetYear));
}}

if (!function_exists('cpms_leave_add_years_clamped')) {
function cpms_leave_add_years_clamped($date, $years)
{
    $date = cpms_leave_parse_date($date);
    if ($date === '') {
        return '';
    }
    $ts = strtotime($date);
    if (!$ts) {
        return '';
    }
    $year = (int)date('Y', $ts) + (int)$years;
    $month = (int)date('n', $ts);
    $day = (int)date('j', $ts);
    $lastDay = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
    if ($day > $lastDay) {
        $day = $lastDay;
    }
    return date('Y-m-d', mktime(0, 0, 0, $month, $day, $year));
}}

if (!function_exists('cpms_leave_months_of_service')) {
function cpms_leave_months_of_service($hireDate, $baseDate)
{
    $hireDate = cpms_leave_parse_date($hireDate);
    $baseDate = cpms_leave_parse_date($baseDate);
    if ($hireDate === '' || $baseDate === '') {
        return 0;
    }
    $h = strtotime($hireDate);
    $b = strtotime($baseDate);
    if (!$h || !$b || $h > $b) {
        return 0;
    }
    $hy = (int)date('Y', $h);
    $hm = (int)date('n', $h);
    $hd = (int)date('j', $h);
    $by = (int)date('Y', $b);
    $bm = (int)date('n', $b);
    $bd = (int)date('j', $b);
    $months = (($by - $hy) * 12) + ($bm - $hm);
    if ($bd < $hd) {
        $months--;
    }
    return $months > 0 ? $months : 0;
}}

if (!function_exists('cpms_leave_annual_entitlement')) {
function cpms_leave_annual_entitlement($serviceYear)
{
    $serviceYear = (int)$serviceYear;
    if ($serviceYear < 1) {
        return 0.0;
    }
    return (float)(15 + floor(($serviceYear - 1) / 2));
}}

if (!function_exists('cpms_leave_monthly_accrual_dates')) {
function cpms_leave_monthly_accrual_dates($hireDate, $baseDate)
{
    $dates = array();
    $hireDate = cpms_leave_parse_date($hireDate);
    $baseDate = cpms_leave_parse_date($baseDate);
    if ($hireDate === '' || $baseDate === '' || strcmp($hireDate, $baseDate) > 0) {
        return $dates;
    }
    $oneYearDate = cpms_leave_add_years_clamped($hireDate, 1);
    for ($i = 1; $i <= 11; $i++) {
        $monthDate = cpms_leave_add_months_clamped($hireDate, $i);
        $accrualDate = cpms_leave_add_days($monthDate, 1);
        if ($accrualDate === '') {
            continue;
        }
        if ($oneYearDate !== '' && strcmp($accrualDate, $oneYearDate) >= 0) {
            continue;
        }
        if (strcmp($accrualDate, $baseDate) <= 0) {
            $dates[count($dates)] = $accrualDate;
        }
    }
    return $dates;
}}

if (!function_exists('cpms_leave_annual_accruals_until')) {
function cpms_leave_annual_accruals_until($hireDate, $baseDate)
{
    $rows = array();
    $hireDate = cpms_leave_parse_date($hireDate);
    $baseDate = cpms_leave_parse_date($baseDate);
    if ($hireDate === '' || $baseDate === '' || strcmp($hireDate, $baseDate) > 0) {
        return $rows;
    }
    for ($year = 1; $year <= 80; $year++) {
        $anniversary = cpms_leave_add_years_clamped($hireDate, $year);
        $accrualDate = cpms_leave_add_days($anniversary, 1);
        if ($accrualDate === '') {
            break;
        }
        if (strcmp($accrualDate, $baseDate) > 0) {
            break;
        }
        $rows[count($rows)] = array(
            'accrual_date' => $accrualDate,
            'service_year' => $year,
            'amount' => cpms_leave_annual_entitlement($year)
        );
    }
    return $rows;
}}

if (!function_exists('cpms_leave_annual_entitlement_for_year')) {
function cpms_leave_annual_entitlement_for_year($hireDate, $targetYear)
{
    $hireDate = cpms_leave_parse_date($hireDate);
    $targetYear = (int)$targetYear;
    if ($hireDate === '' || $targetYear <= 0) {
        return 0.0;
    }
    for ($year = 1; $year <= 80; $year++) {
        $anniversary = cpms_leave_add_years_clamped($hireDate, $year);
        $accrualDate = cpms_leave_add_days($anniversary, 1);
        if ($accrualDate === '') {
            return 0.0;
        }
        $ay = (int)date('Y', strtotime($accrualDate));
        if ($ay === $targetYear) {
            return cpms_leave_annual_entitlement($year);
        }
        if ($ay > $targetYear) {
            return 0.0;
        }
    }
    return 0.0;
}}

if (!function_exists('cpms_leave_count_accrual_logs')) {
function cpms_leave_count_accrual_logs($pdo, $employeeId, $leaveType)
{
    if (!$pdo || !cpms_leave_table_exists($pdo, 'cpms_leave_accrual_logs')) {
        return 0;
    }
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM cpms_leave_accrual_logs WHERE employee_id=:e AND leave_type=:t");
        $st->execute(array(':e' => (int)$employeeId, ':t' => (string)$leaveType));
        return (int)$st->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}}

if (!function_exists('cpms_leave_insert_accrual_log')) {
function cpms_leave_insert_accrual_log($pdo, $employeeId, $leaveType, $accrualDate, $amount, $reason)
{
    $accrualDate = cpms_leave_parse_date($accrualDate);
    if (!$pdo || (int)$employeeId <= 0 || $accrualDate === '') {
        return false;
    }
    try {
        $st = $pdo->prepare("INSERT IGNORE INTO cpms_leave_accrual_logs (employee_id,leave_type,accrual_date,accrual_year,accrual_month,amount,reason,created_at) VALUES (:e,:t,:d,:y,:m,:a,:r,NOW())");
        $st->execute(array(
            ':e' => (int)$employeeId,
            ':t' => (string)$leaveType,
            ':d' => $accrualDate,
            ':y' => (int)date('Y', strtotime($accrualDate)),
            ':m' => (int)date('n', strtotime($accrualDate)),
            ':a' => (float)$amount,
            ':r' => (string)$reason
        ));
        return ((int)$st->rowCount() > 0);
    } catch (Exception $e) {
        error_log('[cpms_leave_insert_accrual_log] ' . $e->getMessage());
        return false;
    }
}}

if (!function_exists('cpms_leave_apply_employee_row_accruals_until')) {
function cpms_leave_apply_employee_row_accruals_until($pdo, $employee, $baseDate)
{
    $stats = array('monthly' => 0, 'annual' => 0, 'skipped' => 0);
    if (!$pdo || !is_array($employee) || !isset($employee['id'])) {
        return $stats;
    }
    if (!cpms_leave_schema_ready_for_accruals($pdo)) {
        $stats['skipped']++;
        return $stats;
    }

    $employeeId = (int)$employee['id'];
    $hireDate = isset($employee['hire_date']) ? cpms_leave_parse_date($employee['hire_date']) : '';
    $baseDate = cpms_leave_parse_date($baseDate);
    if ($employeeId <= 0 || $hireDate === '' || $baseDate === '') {
        $stats['skipped']++;
        return $stats;
    }
    if (isset($employee['is_active']) && (int)$employee['is_active'] !== 1) {
        $stats['skipped']++;
        return $stats;
    }
    $resignDate = isset($employee['resign_date']) ? cpms_leave_parse_date($employee['resign_date']) : '';
    if ($resignDate !== '' && strcmp($resignDate, $baseDate) < 0) {
        $baseDate = $resignDate;
    }
    if (strcmp($hireDate, $baseDate) > 0) {
        $stats['skipped']++;
        return $stats;
    }

    $monthlyHadLogs = (cpms_leave_count_accrual_logs($pdo, $employeeId, 'MONTHLY') > 0);
    $annualHadLogs = (cpms_leave_count_accrual_logs($pdo, $employeeId, 'ANNUAL') > 0);
    $monthlyManualPresent = (isset($employee['leave_monthly_balance']) && $employee['leave_monthly_balance'] !== null && $employee['leave_monthly_balance'] !== '');
    $annualManualPresent = (isset($employee['leave_annual_balance']) && $employee['leave_annual_balance'] !== null && $employee['leave_annual_balance'] !== '');

    $monthlyDates = cpms_leave_monthly_accrual_dates($hireDate, $baseDate);
    for ($i = 0; $i < count($monthlyDates); $i++) {
        $accrualDate = $monthlyDates[$i];
        $applyBalance = true;
        $amount = 1.0;
        $reason = '입사일 기준 월차 자동 발생';
        if (!$monthlyHadLogs && $monthlyManualPresent && strcmp($accrualDate, $baseDate) < 0) {
            $applyBalance = false;
            $amount = 0.0;
            $reason = '기존 월차 발생일 확인(최초 잔여 유지)';
        }
        $inserted = cpms_leave_insert_accrual_log($pdo, $employeeId, 'MONTHLY', $accrualDate, $amount, $reason);
        if ($inserted && $applyBalance) {
            try {
                $st = $pdo->prepare("UPDATE employees SET leave_monthly_balance=COALESCE(leave_monthly_balance,0)+1 WHERE id=:id");
                $st->execute(array(':id' => $employeeId));
                $stats['monthly']++;
            } catch (Exception $e) {
                error_log('[cpms_leave_apply_monthly] ' . $e->getMessage());
            }
        }
    }

    $annualRows = cpms_leave_annual_accruals_until($hireDate, $baseDate);
    for ($i = 0; $i < count($annualRows); $i++) {
        $row = $annualRows[$i];
        $accrualDate = isset($row['accrual_date']) ? $row['accrual_date'] : '';
        $amount = isset($row['amount']) ? (float)$row['amount'] : 0.0;
        $applyBalance = true;
        $reason = '입사일 기준 연차 자동 발생';
        if (!$annualHadLogs && $annualManualPresent && strcmp($accrualDate, $baseDate) < 0) {
            $applyBalance = false;
            $amount = 0.0;
            $reason = '기존 연차 발생일 확인(최초 잔여 유지)';
        }
        $inserted = cpms_leave_insert_accrual_log($pdo, $employeeId, 'ANNUAL', $accrualDate, $amount, $reason);
        if ($inserted && $applyBalance) {
            try {
                $st = $pdo->prepare("UPDATE employees SET leave_annual_balance=:amount WHERE id=:id");
                $st->execute(array(':amount' => $amount, ':id' => $employeeId));
                $stats['annual']++;
            } catch (Exception $e) {
                error_log('[cpms_leave_apply_annual] ' . $e->getMessage());
            }
        }
    }

    return $stats;
}}

if (!function_exists('cpms_leave_apply_employee_accruals_until')) {
function cpms_leave_apply_employee_accruals_until($pdo, $employeeId, $baseDate)
{
    $stats = array('monthly' => 0, 'annual' => 0, 'skipped' => 0);
    if (!$pdo || (int)$employeeId <= 0 || !cpms_leave_schema_ready_for_accruals($pdo)) {
        $stats['skipped']++;
        return $stats;
    }
    try {
        $resignSelect = cpms_leave_column_exists($pdo, 'employees', 'resign_date') ? 'resign_date' : 'NULL AS resign_date';
        $st = $pdo->prepare("SELECT id,hire_date,is_active,leave_monthly_balance,leave_annual_balance," . $resignSelect . " FROM employees WHERE id=:id LIMIT 1");
        $st->execute(array(':id' => (int)$employeeId));
        $employee = $st->fetch(PDO::FETCH_ASSOC);
        if (!$employee) {
            $stats['skipped']++;
            return $stats;
        }
        return cpms_leave_apply_employee_row_accruals_until($pdo, $employee, $baseDate);
    } catch (Exception $e) {
        error_log('[cpms_leave_apply_employee_accruals_until] ' . $e->getMessage());
        $stats['skipped']++;
        return $stats;
    }
}}

if (!function_exists('cpms_leave_apply_accruals_until')) {
function cpms_leave_apply_accruals_until($pdo, $baseDate)
{
    $stats = array('employees' => 0, 'monthly' => 0, 'annual' => 0, 'skipped' => 0);
    if (!$pdo || !cpms_leave_schema_ready_for_accruals($pdo)) {
        $stats['skipped']++;
        return $stats;
    }
    $baseDate = cpms_leave_parse_date($baseDate);
    if ($baseDate === '') {
        $baseDate = date('Y-m-d');
    }
    try {
        $resignSelect = cpms_leave_column_exists($pdo, 'employees', 'resign_date') ? 'resign_date' : 'NULL AS resign_date';
        $rows = $pdo->query("SELECT id,hire_date,is_active,leave_monthly_balance,leave_annual_balance," . $resignSelect . " FROM employees WHERE hire_date IS NOT NULL AND hire_date<>'' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = array();
        }
        for ($i = 0; $i < count($rows); $i++) {
            $stats['employees']++;
            $one = cpms_leave_apply_employee_row_accruals_until($pdo, $rows[$i], $baseDate);
            $stats['monthly'] += isset($one['monthly']) ? (int)$one['monthly'] : 0;
            $stats['annual'] += isset($one['annual']) ? (int)$one['annual'] : 0;
            $stats['skipped'] += isset($one['skipped']) ? (int)$one['skipped'] : 0;
        }
    } catch (Exception $e) {
        error_log('[cpms_leave_apply_accruals_until] ' . $e->getMessage());
        $stats['skipped']++;
    }
    return $stats;
}}

if (!function_exists('cpms_leave_format_decimal')) {
function cpms_leave_format_decimal($value)
{
    if ($value === null || $value === '') {
        return '';
    }
    $num = (float)$value;
    if (abs($num - (int)$num) < 0.00001) {
        return (string)(int)$num;
    }
    return number_format($num, 2, '.', '');
}}

if (!function_exists('cpms_leave_normalize_department')) {
function cpms_leave_normalize_department($dept)
{
    $dept = trim((string)$dept);
    if ($dept === '관리부') {
        return '관리';
    }
    if ($dept === '관리팀') {
        return '관리';
    }
    return $dept;
}}

if (!function_exists('cpms_leave_is_management_dept_value')) {
function cpms_leave_is_management_dept_value($dept)
{
    return cpms_leave_normalize_department($dept) === '관리';
}}

if (!function_exists('cpms_leave_current_employee_row')) {
function cpms_leave_current_employee_row($pdo, $user)
{
    if (!$pdo || !is_array($user)) {
        return null;
    }
    try {
        if (isset($user['id']) && (int)$user['id'] > 0) {
            $st = $pdo->prepare("SELECT id,name,email,department,role FROM employees WHERE id=:id LIMIT 1");
            $st->execute(array(':id' => (int)$user['id']));
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        }
        if (isset($user['email']) && trim((string)$user['email']) !== '') {
            $st = $pdo->prepare("SELECT id,name,email,department,role FROM employees WHERE email=:email LIMIT 1");
            $st->execute(array(':email' => trim((string)$user['email'])));
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        }
    } catch (Exception $e) {
    }
    return null;
}}

if (!function_exists('cpms_leave_can_access_management')) {
function cpms_leave_can_access_management($pdo, $user)
{
    if (\App\Core\Auth::isMaster()) {
        return true;
    }
    if (\App\Core\Auth::canManageEmployees()) {
        return true;
    }
    if (is_array($user) && isset($user['department']) && cpms_leave_is_management_dept_value($user['department'])) {
        return true;
    }
    $row = cpms_leave_current_employee_row($pdo, $user);
    if (is_array($row) && isset($row['department']) && cpms_leave_is_management_dept_value($row['department'])) {
        return true;
    }
    return false;
}}

if (!function_exists('cpms_leave_use_amount_from_document')) {
function cpms_leave_use_amount_from_document($content, $deductAmount)
{
    if ($deductAmount !== null && $deductAmount !== '' && is_numeric($deductAmount)) {
        return (float)$deductAmount;
    }
    $requestType = isset($content['request_type']) ? trim((string)$content['request_type']) : '';
    if ($requestType === '반차 오전' || $requestType === '반차 오후') {
        return 0.5;
    }
    if ($requestType !== '연차' && $requestType !== '월차') {
        return 0.0;
    }
    if (isset($content['leave_days']) && is_numeric($content['leave_days'])) {
        return (float)$content['leave_days'];
    }
    return 0.0;
}}
