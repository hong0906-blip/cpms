<?php
/**
 * Company payroll access rules.
 * PHP 5.6 compatible.
 */

require_once __DIR__ . '/CompanyProfitAccessService.php';

if (!function_exists('cpms_company_payroll_access_config')) {
function cpms_company_payroll_access_config() {
    return array(
        'allowed_names' => array('박지혜'),
        'allowed_emails' => array(),
        'allowed_login_ids' => array(),
        'allowed_user_ids' => array(),
        'view_positions' => array('대표', '대표이사', '부사장'),
        'master_roles' => array('master'),
    );
}}

if (!function_exists('cpms_company_payroll_access_normalize')) {
function cpms_company_payroll_access_normalize($value) {
    if (function_exists('cpms_company_profit_access_normalize_text')) {
        return cpms_company_profit_access_normalize_text($value);
    }
    $value = trim((string)$value);
    $value = str_replace(array(' ', "\t", "\r", "\n", '-', '_', '/', '\\'), '', $value);
    if (function_exists('mb_strtolower')) return mb_strtolower($value, 'UTF-8');
    return strtolower($value);
}}

if (!function_exists('cpms_company_payroll_access_user_value')) {
function cpms_company_payroll_access_user_value($user, $key) {
    if (function_exists('cpms_company_profit_access_user_value')) {
        return cpms_company_profit_access_user_value($user, $key);
    }
    if (is_array($user) && isset($user[$key])) return trim((string)$user[$key]);
    return '';
}}

if (!function_exists('cpms_company_payroll_access_employee_row')) {
function cpms_company_payroll_access_employee_row($pdo, $user) {
    if (function_exists('cpms_company_profit_access_employee_row')) {
        return cpms_company_profit_access_employee_row($pdo, $user);
    }
    return null;
}}

if (!function_exists('cpms_company_payroll_access_has_word')) {
function cpms_company_payroll_access_has_word($values, $words) {
    $haystack = '';
    foreach ($values as $value) {
        $haystack .= ' ' . cpms_company_payroll_access_normalize($value);
    }
    foreach ($words as $word) {
        $needle = cpms_company_payroll_access_normalize($word);
        if ($needle !== '' && strpos($haystack, $needle) !== false) return true;
    }
    return false;
}}

if (!function_exists('cpms_company_payroll_access_is_master_role')) {
function cpms_company_payroll_access_is_master_role($values) {
    $config = cpms_company_payroll_access_config();
    $roles = isset($config['master_roles']) && is_array($config['master_roles']) ? $config['master_roles'] : array('master');
    foreach ($values as $value) {
        $normalized = cpms_company_payroll_access_normalize($value);
        foreach ($roles as $role) {
            if ($normalized !== '' && $normalized === cpms_company_payroll_access_normalize($role)) return true;
        }
    }
    return false;
}}

if (!function_exists('cpms_company_payroll_access_is_named_allowed_user')) {
function cpms_company_payroll_access_is_named_allowed_user($user, $pdo) {
    $config = cpms_company_payroll_access_config();
    $allowedIds = isset($config['allowed_user_ids']) && is_array($config['allowed_user_ids']) ? $config['allowed_user_ids'] : array();
    $allowedEmails = isset($config['allowed_emails']) && is_array($config['allowed_emails']) ? $config['allowed_emails'] : array();
    $allowedLoginIds = isset($config['allowed_login_ids']) && is_array($config['allowed_login_ids']) ? $config['allowed_login_ids'] : array();
    $allowedNames = isset($config['allowed_names']) && is_array($config['allowed_names']) ? $config['allowed_names'] : array();

    $values = array();
    if (is_array($user)) $values[] = $user;
    $dbRow = cpms_company_payroll_access_employee_row($pdo, $user);
    if (is_array($dbRow)) $values[] = $dbRow;

    foreach ($values as $row) {
        $idCandidates = array();
        if (isset($row['id'])) $idCandidates[] = $row['id'];
        if (isset($row['user_id'])) $idCandidates[] = $row['user_id'];
        foreach ($idCandidates as $idValue) {
            $id = (int)$idValue;
            if ($id <= 0) continue;
            foreach ($allowedIds as $allowedId) {
                if ((int)$allowedId > 0 && $id === (int)$allowedId) return true;
            }
        }

        $loginCandidates = array();
        if (isset($row['login_id'])) $loginCandidates[] = $row['login_id'];
        if (isset($row['username'])) $loginCandidates[] = $row['username'];
        if (isset($row['account'])) $loginCandidates[] = $row['account'];
        foreach ($loginCandidates as $loginValue) {
            $login = cpms_company_payroll_access_normalize($loginValue);
            if ($login === '') continue;
            foreach ($allowedLoginIds as $allowedLoginId) {
                if ($login === cpms_company_payroll_access_normalize($allowedLoginId)) return true;
            }
        }

        $email = isset($row['email']) ? strtolower(trim((string)$row['email'])) : '';
        if ($email !== '') {
            foreach ($allowedEmails as $allowedEmail) {
                if ($email === strtolower(trim((string)$allowedEmail))) return true;
            }
        }

        $name = isset($row['name']) ? cpms_company_payroll_access_normalize($row['name']) : '';
        if ($name !== '') {
            foreach ($allowedNames as $allowedName) {
                if ($name !== cpms_company_payroll_access_normalize($allowedName)) continue;
                if (function_exists('cpms_company_profit_access_is_park_jihye_deputy') && cpms_company_payroll_access_normalize($allowedName) === cpms_company_payroll_access_normalize('박지혜')) {
                    $rowDept = isset($row['department']) ? (string)$row['department'] : '';
                    $rowPosition = isset($row['position']) ? (string)$row['position'] : '';
                    if (cpms_company_profit_access_is_park_jihye_deputy(isset($row['name']) ? (string)$row['name'] : '', $rowDept, $rowPosition)) return true;
                    continue;
                }
                return true;
            }
        }
    }

    return false;
}}

if (!function_exists('cpms_company_payroll_access_is_view_allowed_name')) {
function cpms_company_payroll_access_is_view_allowed_name($user, $pdo) {
    $config = cpms_company_payroll_access_config();
    $allowedNames = isset($config['allowed_names']) && is_array($config['allowed_names']) ? $config['allowed_names'] : array();

    $values = array();
    if (is_array($user)) $values[] = $user;
    $dbRow = cpms_company_payroll_access_employee_row($pdo, $user);
    if (is_array($dbRow)) $values[] = $dbRow;

    foreach ($values as $row) {
        $name = isset($row['name']) ? cpms_company_payroll_access_normalize($row['name']) : '';
        if ($name === '') continue;
        foreach ($allowedNames as $allowedName) {
            if ($name === cpms_company_payroll_access_normalize($allowedName)) return true;
        }
    }
    return false;
}}

if (!function_exists('cpms_company_payroll_access_is_executive_allowed')) {
function cpms_company_payroll_access_is_executive_allowed($user, $pdo) {
    $config = cpms_company_payroll_access_config();
    $words = isset($config['view_positions']) && is_array($config['view_positions']) ? $config['view_positions'] : array('대표', '부사장');
    $values = array(
        cpms_company_payroll_access_user_value($user, 'role'),
        cpms_company_payroll_access_user_value($user, 'position'),
        cpms_company_payroll_access_user_value($user, 'name'),
    );
    if (class_exists('App\\Core\\Auth')) {
        $values[] = \App\Core\Auth::userRole();
        $values[] = \App\Core\Auth::userPosition();
        $values[] = \App\Core\Auth::userName();
    }
    if (cpms_company_payroll_access_has_word($values, $words)) return true;

    $dbRow = cpms_company_payroll_access_employee_row($pdo, $user);
    if (is_array($dbRow)) {
        $dbValues = array(
            isset($dbRow['role']) ? $dbRow['role'] : '',
            isset($dbRow['position']) ? $dbRow['position'] : '',
            isset($dbRow['name']) ? $dbRow['name'] : '',
        );
        if (cpms_company_payroll_access_has_word($dbValues, $words)) return true;
    }

    return false;
}}

if (!function_exists('cpms_can_view_company_payroll')) {
function cpms_can_view_company_payroll($user = null, $pdo = null) {
    if (!class_exists('App\\Core\\Auth')) return false;
    if (!\App\Core\Auth::check()) return false;
    if (\App\Core\Auth::isMaster()) return true;
    if ($user === null) $user = \App\Core\Auth::user();
    if (!is_array($user)) return false;

    $roleValues = array(cpms_company_payroll_access_user_value($user, 'role'), \App\Core\Auth::userRole());
    if (cpms_company_payroll_access_is_master_role($roleValues)) return true;
    if (cpms_company_payroll_access_is_executive_allowed($user, $pdo)) return true;
    // 박지혜 사용자는 부서/직급 값과 무관하게 임직원 월급 탭을 조회할 수 있습니다.
    if (cpms_company_payroll_access_is_view_allowed_name($user, $pdo)) return true;
    if (cpms_company_payroll_access_is_named_allowed_user($user, $pdo)) return true;
    return false;
}}

if (!function_exists('cpms_can_edit_company_payroll')) {
function cpms_can_edit_company_payroll($user = null, $pdo = null) {
    if (!class_exists('App\\Core\\Auth')) return false;
    if (!\App\Core\Auth::check()) return false;
    if (\App\Core\Auth::isMaster()) return true;
    if ($user === null) $user = \App\Core\Auth::user();
    if (!is_array($user)) return false;

    $roleValues = array(cpms_company_payroll_access_user_value($user, 'role'), \App\Core\Auth::userRole());
    if (cpms_company_payroll_access_is_master_role($roleValues)) return true;

    // 박지혜 사용자는 부서/직급 저장값과 무관하게 임직원 월급을 등록·수정할 수 있습니다.
    // 이 권한은 총액 저장, 급여대장 업로드, 급여명세서 생성 등 기존 편집 기능에 동일하게 적용됩니다.
    if (cpms_company_payroll_access_is_view_allowed_name($user, $pdo)) return true;

    return cpms_company_payroll_access_is_named_allowed_user($user, $pdo);
}}

if (!function_exists('cpms_can_reveal_payroll_resident_number')) {
function cpms_can_reveal_payroll_resident_number($user = null, $pdo = null) {
    return cpms_can_view_company_payroll($user, $pdo);
}}

if (!function_exists('cpms_can_generate_payroll_statement_pdf')) {
function cpms_can_generate_payroll_statement_pdf($user = null, $pdo = null) {
    return cpms_can_edit_company_payroll($user, $pdo);
}}

if (!function_exists('cpms_can_download_payroll_statement_pdf')) {
function cpms_can_download_payroll_statement_pdf($user = null, $pdo = null) {
    return cpms_can_view_company_payroll($user, $pdo);
}}
