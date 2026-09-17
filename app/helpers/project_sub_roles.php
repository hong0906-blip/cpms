<?php
/**
 * C:\www\cpms\app\helpers\project_sub_roles.php
 * - 공사 > 담당지정의 안전/품질 서브 담당 호환 처리
 * - 기존 cpms_project_members 테이블을 사용하므로 DB 마이그레이션 불필요
 * - role VARCHAR(10) 제약에 맞춰 safety_sub / qual_sub 사용
 * - PHP 5.6 호환
 */

if (!function_exists('cpms_project_sub_role_table_exists')) {
function cpms_project_sub_role_table_exists($pdo, $table)
{
    if (!$pdo || trim((string)$table) === '') return false;
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name");
        $st->bindValue(':table_name', (string)$table);
        $st->execute();
        return ((int)$st->fetchColumn() > 0);
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_project_sub_role_current_employee_id')) {
function cpms_project_sub_role_current_employee_id($pdo)
{
    if (!class_exists('App\\Core\\Auth')) return 0;

    $user = \App\Core\Auth::user();
    if (is_array($user) && isset($user['id']) && (int)$user['id'] > 0) {
        return (int)$user['id'];
    }

    if (!$pdo) return 0;
    $email = trim((string)\App\Core\Auth::userEmail());
    if ($email === '') return 0;

    try {
        $st = $pdo->prepare("SELECT id FROM employees WHERE LOWER(TRIM(email)) = LOWER(TRIM(:email)) AND is_active = 1 LIMIT 1");
        $st->bindValue(':email', $email);
        $st->execute();
        return (int)$st->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}}

if (!function_exists('cpms_project_sub_role_normalize_department')) {
function cpms_project_sub_role_normalize_department($department)
{
    if (class_exists('App\\Core\\Auth') && method_exists('App\\Core\\Auth', 'normalizeDepartmentValue')) {
        return (string)\App\Core\Auth::normalizeDepartmentValue($department);
    }

    $department = trim((string)$department);
    $map = array(
        '안전부' => '안전', '안전팀' => '안전', '안전/보건' => '안전', '안전보건' => '안전',
        '품질부' => '품질', '품질팀' => '품질', '품질관리' => '품질', '품질관리부' => '품질', '품질관리팀' => '품질',
        '공사부' => '공사', '공사팀' => '공사',
        '공무부' => '공무', '공무팀' => '공무',
        '관리부' => '관리', '관리팀' => '관리'
    );
    return isset($map[$department]) ? $map[$department] : $department;
}}

if (!function_exists('cpms_project_sub_role_has_member_role')) {
function cpms_project_sub_role_has_member_role($pdo, $projectId, $employeeId, $roles)
{
    $projectId = (int)$projectId;
    $employeeId = (int)$employeeId;
    if (!$pdo || $projectId <= 0 || $employeeId <= 0 || !cpms_project_sub_role_table_exists($pdo, 'cpms_project_members')) return false;

    if (!is_array($roles)) $roles = array($roles);
    $normalizedRoles = array();
    foreach ($roles as $role) {
        $role = strtolower(trim((string)$role));
        if ($role !== '') $normalizedRoles[$role] = $role;
    }
    if (count($normalizedRoles) === 0) return false;

    $quoted = array();
    foreach ($normalizedRoles as $role) {
        $quoted[] = $pdo->quote($role);
    }

    try {
        $sql = "SELECT COUNT(*) FROM cpms_project_members
                WHERE project_id = :project_id
                  AND employee_id = :employee_id
                  AND LOWER(TRIM(role)) IN (" . implode(',', $quoted) . ")";
        $st = $pdo->prepare($sql);
        $st->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        $st->bindValue(':employee_id', $employeeId, PDO::PARAM_INT);
        $st->execute();
        return ((int)$st->fetchColumn() > 0);
    } catch (Exception $e) {
        return false;
    }
}}

/*
 * safety_cost_helper.php가 같은 함수를 정의하기 전에 먼저 로드됩니다.
 * 기존 권한을 보존하면서 safety_sub 담당자도 메인 안전담당자와 동일하게
 * 해당 현장의 안전관리비/안전사고 관리 권한을 갖게 합니다.
 */
if (!function_exists('cpms_safety_cost_user_can_manage_project')) {
function cpms_safety_cost_user_can_manage_project($pdo, $projectId)
{
    $projectId = (int)$projectId;
    if (!$pdo || $projectId <= 0 || !class_exists('App\\Core\\Auth') || !\App\Core\Auth::check()) return false;

    if (\App\Core\Auth::isMaster() || \App\Core\Auth::userRole() === 'executive') return true;

    $department = cpms_project_sub_role_normalize_department(\App\Core\Auth::userDepartment());
    $employeeId = cpms_project_sub_role_current_employee_id($pdo);

    // 기존 공무/관리부는 전체 현장 안전관리비 관리 흐름을 유지한다.
    if ($department === '공무' || $department === '관리') return true;

    if ($employeeId <= 0) return false;

    // 공사 담당자는 기존 메인/서브 현장 범위에서 관리 가능.
    if ($department === '공사') {
        try {
            if (cpms_project_sub_role_table_exists($pdo, 'cpms_construction_roles')) {
                $stSite = $pdo->prepare("SELECT COUNT(*) FROM cpms_construction_roles WHERE project_id = :project_id AND site_employee_id = :employee_id");
                $stSite->execute(array(':project_id' => $projectId, ':employee_id' => $employeeId));
                if ((int)$stSite->fetchColumn() > 0) return true;
            }
        } catch (Exception $e) {
        }

        return cpms_project_sub_role_has_member_role($pdo, $projectId, $employeeId, array('main', 'sub'));
    }

    if ($department !== '안전') return false;

    // 구형 설치에서 담당 테이블이 아직 없다면 기존 안전부 fallback을 유지한다.
    if (!cpms_project_sub_role_table_exists($pdo, 'cpms_construction_roles')) return true;

    try {
        $stSafety = $pdo->prepare("SELECT safety_employee_id FROM cpms_construction_roles WHERE project_id = :project_id LIMIT 1");
        $stSafety->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        $stSafety->execute();
        $mainSafetyId = (int)$stSafety->fetchColumn();
        if ($mainSafetyId > 0 && $mainSafetyId === $employeeId) return true;
    } catch (Exception $e) {
    }

    return cpms_project_sub_role_has_member_role($pdo, $projectId, $employeeId, 'safety_sub');
}}

/*
 * 공사 이슈 댓글 알림 대상에도 안전/품질 서브 담당자를 포함한다.
 * 실제 서비스 파일에서 function_exists()로 감싸져 있어 이 정의가 우선 사용됩니다.
 */
if (!function_exists('cpms_construction_issue_comment_recipient_ids')) {
function cpms_construction_issue_comment_recipient_ids($pdo, $issueId, $projectId)
{
    $recipientIds = array();
    if (!$pdo || (int)$issueId <= 0 || (int)$projectId <= 0) return array();

    try {
        if (cpms_project_sub_role_table_exists($pdo, 'cpms_project_members')) {
            $st = $pdo->prepare("SELECT DISTINCT e.id
                FROM cpms_project_members pm
                JOIN employees e ON e.id = pm.employee_id
                WHERE pm.project_id = :project_id
                  AND LOWER(TRIM(pm.role)) IN ('main', 'sub', 'safety_sub', 'qual_sub')
                  AND e.is_active = 1");
            $st->execute(array(':project_id' => (int)$projectId));
            while ($employeeId = $st->fetchColumn()) {
                if ((int)$employeeId > 0) $recipientIds[(int)$employeeId] = (int)$employeeId;
            }
        }
    } catch (Exception $e) {
    }

    try {
        if (cpms_project_sub_role_table_exists($pdo, 'cpms_construction_roles')) {
            $st = $pdo->prepare("SELECT site_employee_id, safety_employee_id, quality_employee_id
                FROM cpms_construction_roles WHERE project_id = :project_id LIMIT 1");
            $st->execute(array(':project_id' => (int)$projectId));
            $roleRow = $st->fetch(PDO::FETCH_ASSOC);
            if (is_array($roleRow)) {
                $roleColumns = array('site_employee_id', 'safety_employee_id', 'quality_employee_id');
                foreach ($roleColumns as $roleColumn) {
                    $employeeId = isset($roleRow[$roleColumn]) ? (int)$roleRow[$roleColumn] : 0;
                    if ($employeeId > 0) $recipientIds[$employeeId] = $employeeId;
                }
            }
        }
    } catch (Exception $e) {
    }

    // 댓글 작성자(created_by) 포함
    try {
        $hasCreatedBy = false;
        $stCol = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cpms_project_issue_comments' AND COLUMN_NAME = 'created_by'");
        $stCol->execute();
        $hasCreatedBy = ((int)$stCol->fetchColumn() > 0);
        if ($hasCreatedBy) {
            $st = $pdo->prepare("SELECT DISTINCT e.id
                FROM cpms_project_issue_comments c
                JOIN employees e ON e.id = c.created_by
                WHERE c.issue_id = :issue_id AND e.is_active = 1");
            $st->execute(array(':issue_id' => (int)$issueId));
            while ($employeeId = $st->fetchColumn()) {
                if ((int)$employeeId > 0) $recipientIds[(int)$employeeId] = (int)$employeeId;
            }
        }
    } catch (Exception $e) {
    }

    // 이메일 기반 댓글 작성자 포함
    try {
        $hasCreatedByEmail = false;
        $stCol = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cpms_project_issue_comments' AND COLUMN_NAME = 'created_by_email'");
        $stCol->execute();
        $hasCreatedByEmail = ((int)$stCol->fetchColumn() > 0);
        if ($hasCreatedByEmail) {
            $st = $pdo->prepare("SELECT DISTINCT e.id
                FROM cpms_project_issue_comments c
                JOIN employees e ON LOWER(TRIM(e.email)) = LOWER(TRIM(c.created_by_email))
                WHERE c.issue_id = :issue_id
                  AND c.created_by_email IS NOT NULL
                  AND TRIM(c.created_by_email) <> ''
                  AND e.is_active = 1");
            $st->execute(array(':issue_id' => (int)$issueId));
            while ($employeeId = $st->fetchColumn()) {
                if ((int)$employeeId > 0) $recipientIds[(int)$employeeId] = (int)$employeeId;
            }
        }
    } catch (Exception $e) {
    }

    if (count($recipientIds) > 0) {
        try {
            $activeIds = array();
            $idList = implode(',', array_map('intval', array_values($recipientIds)));
            $st = $pdo->query("SELECT id FROM employees WHERE is_active = 1 AND id IN (" . $idList . ")");
            while ($employeeId = $st->fetchColumn()) {
                if ((int)$employeeId > 0) $activeIds[(int)$employeeId] = (int)$employeeId;
            }
            $recipientIds = $activeIds;
        } catch (Exception $e) {
        }
    }

    ksort($recipientIds, SORT_NUMERIC);
    return array_values($recipientIds);
}}
