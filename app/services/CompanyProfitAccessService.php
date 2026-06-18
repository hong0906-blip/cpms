<?php
/**
 * Company profit dashboard access rules.
 * PHP 5.6 compatible.
 */

if (!function_exists('cpms_company_profit_access_normalize_text')) {
function cpms_company_profit_access_normalize_text($value) {
    $value = trim((string)$value);
    $value = str_replace(array(' ', "\t", "\r", "\n", '-', '_', '/', '\\'), '', $value);
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($value, 'UTF-8');
    }
    return strtolower($value);
}}

if (!function_exists('cpms_company_profit_access_user_value')) {
function cpms_company_profit_access_user_value($user, $key) {
    if (is_array($user) && isset($user[$key])) return trim((string)$user[$key]);
    return '';
}}

if (!function_exists('cpms_company_profit_access_is_management_dept')) {
function cpms_company_profit_access_is_management_dept($dept) {
    $dept = trim((string)$dept);
    if (function_exists('cpms_is_management_department_value')) {
        return cpms_is_management_department_value($dept);
    }
    return ($dept === '관리' || $dept === '관리부' || $dept === '관리팀');
}}

if (!function_exists('cpms_company_profit_access_employee_row')) {
function cpms_company_profit_access_employee_row($pdo, $user) {
    if (!$pdo || !is_array($user)) return null;

    $employeeId = isset($user['id']) ? (int)$user['id'] : 0;
    $email = isset($user['email']) ? trim((string)$user['email']) : '';

    try {
        $hasPosition = false;
        try {
            $stCol = $pdo->prepare("SHOW COLUMNS FROM employees LIKE 'position'");
            $stCol->execute();
            $hasPosition = $stCol->fetch(PDO::FETCH_ASSOC) ? true : false;
        } catch (Exception $e) {
            $hasPosition = false;
        }
        $select = $hasPosition
            ? "id, name, email, role, department, position"
            : "id, name, email, role, department, '' AS position";

        if ($employeeId > 0) {
            $st = $pdo->prepare("SELECT " . $select . " FROM employees WHERE id = :id LIMIT 1");
            $st->bindValue(':id', $employeeId, PDO::PARAM_INT);
            $st->execute();
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) return $row;
        }

        if ($email !== '') {
            $st = $pdo->prepare("SELECT " . $select . " FROM employees WHERE LOWER(email) = LOWER(:email) LIMIT 1");
            $st->bindValue(':email', $email);
            $st->execute();
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) return $row;
        }
    } catch (Exception $e) {
        error_log('[company_profit_access] employee lookup failed: ' . $e->getMessage());
    }

    return null;
}}

if (!function_exists('cpms_company_profit_access_has_executive_word')) {
function cpms_company_profit_access_has_executive_word($values) {
    $words = array('대표', '대표이사', '대표님', '부사장', 'ceo', 'president', 'vicepresident', 'vp');
    $haystack = '';
    foreach ($values as $value) {
        $haystack .= ' ' . cpms_company_profit_access_normalize_text($value);
    }
    if ($haystack === '') return false;

    foreach ($words as $word) {
        $needle = cpms_company_profit_access_normalize_text($word);
        if ($needle !== '' && strpos($haystack, $needle) !== false) return true;
    }
    return false;
}}

if (!function_exists('cpms_can_view_company_profit')) {
function cpms_can_view_company_profit($user = null, $pdo = null) {
    if (!class_exists('App\\Core\\Auth')) return false;
    if (!\App\Core\Auth::check()) return false;
    if (\App\Core\Auth::isMaster()) return true;

    if ($user === null) $user = \App\Core\Auth::user();
    if (!is_array($user)) return false;
    if (!cpms_can_view_company_overhead($user, $pdo)) return false;

    $role = cpms_company_profit_access_user_value($user, 'role');
    if (cpms_company_profit_access_normalize_text($role) === 'master') return true;

    $dept = cpms_company_profit_access_user_value($user, 'department');
    if ($dept === '') $dept = (string)\App\Core\Auth::userDepartment();
    if (cpms_company_profit_access_is_management_dept($dept)) return true;

    $position = cpms_company_profit_access_user_value($user, 'position');
    if ($position === '') $position = (string)\App\Core\Auth::userPosition();
    $name = cpms_company_profit_access_user_value($user, 'name');

    if (cpms_company_profit_access_has_executive_word(array($role, $position, $name))) {
        return true;
    }

    $dbRow = cpms_company_profit_access_employee_row($pdo, $user);
    if (is_array($dbRow)) {
        $dbDept = isset($dbRow['department']) ? (string)$dbRow['department'] : '';
        if (cpms_company_profit_access_is_management_dept($dbDept)) return true;

        $dbRole = isset($dbRow['role']) ? (string)$dbRow['role'] : '';
        if (cpms_company_profit_access_normalize_text($dbRole) === 'master') return true;

        $dbPosition = isset($dbRow['position']) ? (string)$dbRow['position'] : '';
        $dbName = isset($dbRow['name']) ? (string)$dbRow['name'] : '';
        if (cpms_company_profit_access_has_executive_word(array($dbRole, $dbPosition, $dbName))) {
            return true;
        }
    }

    return false;
}}

if (!function_exists('cpms_can_view_company_overhead')) {
function cpms_can_view_company_overhead($user = null, $pdo = null) {
    return cpms_can_view_company_profit($user, $pdo);
}}

if (!function_exists('cpms_can_edit_company_overhead')) {
function cpms_can_edit_company_overhead($user = null, $pdo = null) {
    if (!class_exists('App\\Core\\Auth')) return false;
    if (!\App\Core\Auth::check()) return false;
    if (\App\Core\Auth::isMaster()) return true;

    if ($user === null) $user = \App\Core\Auth::user();
    if (!is_array($user)) return false;

    $role = cpms_company_profit_access_user_value($user, 'role');
    if (cpms_company_profit_access_normalize_text($role) === 'master') return true;

    $dept = cpms_company_profit_access_user_value($user, 'department');
    if ($dept === '') $dept = (string)\App\Core\Auth::userDepartment();
    if (cpms_company_profit_access_is_management_dept($dept) && \App\Core\Auth::canManageEmployees()) {
        return true;
    }

    $position = cpms_company_profit_access_user_value($user, 'position');
    if ($position === '') $position = (string)\App\Core\Auth::userPosition();
    $name = cpms_company_profit_access_user_value($user, 'name');
    $authRole = (string)\App\Core\Auth::userRole();
    if (\App\Core\Auth::canManageEmployees() && ($authRole === 'executive' || cpms_company_profit_access_has_executive_word(array($role, $position, $name)))) {
        return true;
    }

    $dbRow = cpms_company_profit_access_employee_row($pdo, $user);
    if (is_array($dbRow)) {
        $dbDept = isset($dbRow['department']) ? (string)$dbRow['department'] : '';
        if (cpms_company_profit_access_is_management_dept($dbDept) && \App\Core\Auth::canManageEmployees()) return true;

        $dbRole = isset($dbRow['role']) ? (string)$dbRow['role'] : '';
        $dbPosition = isset($dbRow['position']) ? (string)$dbRow['position'] : '';
        $dbName = isset($dbRow['name']) ? (string)$dbRow['name'] : '';
        if (\App\Core\Auth::canManageEmployees() && ($dbRole === 'executive' || cpms_company_profit_access_has_executive_word(array($dbRole, $dbPosition, $dbName)))) {
            return true;
        }
    }

    return false;
}}
