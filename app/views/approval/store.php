<?php
use App\Core\Db;

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/template_helpers.php';
require_once __DIR__ . '/notification_helpers.php';
require_once __DIR__ . '/../admin/leave_management_helpers.php';
require_once __DIR__ . '/../../services/ApprovalDriveService.php';
require_once __DIR__ . '/line_rules.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit;
}
csrf_validate();

$pdo = Db::pdo();
$user = \App\Core\Auth::user();
if (!$pdo || !$user) {
    exit;
}

if (!function_exists('approval_store_column_exists')) {
    function approval_store_column_exists($pdo, $table, $column)
    {
        return approval_table_column_exists($pdo, $table, $column);
    }
}

if (!function_exists('approval_store_employee')) {
    function approval_store_employee($pdo, $id)
    {
        if ((int)$id <= 0) {
            return null;
        }
        $hireColumn = approval_store_column_exists($pdo, 'employees', 'hire_date') ? 'hire_date' : "NULL AS hire_date";
        $roleColumn = approval_store_column_exists($pdo, 'employees', 'role') ? 'role' : "'' AS role";
        $isTeamLeaderColumn = approval_store_column_exists($pdo, 'employees', 'is_team_leader') ? 'is_team_leader' : "0 AS is_team_leader";
        $teamLeaderIdColumn = approval_store_column_exists($pdo, 'employees', 'team_leader_id') ? 'team_leader_id' : "0 AS team_leader_id";
        $approvalLeadColumn = approval_store_column_exists($pdo, 'employees', 'approval_can_be_team_leader') ? 'approval_can_be_team_leader' : "0 AS approval_can_be_team_leader";
        $approvalGongmuColumn = approval_store_column_exists($pdo, 'employees', 'approval_can_be_gongmu_approver') ? 'approval_can_be_gongmu_approver' : "0 AS approval_can_be_gongmu_approver";
        $approvalManageColumn = approval_store_column_exists($pdo, 'employees', 'approval_can_be_manage_approver') ? 'approval_can_be_manage_approver' : "0 AS approval_can_be_manage_approver";
        $st = $pdo->prepare("SELECT id,name,email,department,position," . $roleColumn . "," . $hireColumn . "," . $isTeamLeaderColumn . "," . $teamLeaderIdColumn . "," . $approvalLeadColumn . "," . $approvalGongmuColumn . "," . $approvalManageColumn . " FROM employees WHERE id=:id AND is_active=1 LIMIT 1");
        $st->execute(array(':id' => (int)$id));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? $row : null;
    }
}

if (!function_exists('approval_store_employee_by_name')) {
    function approval_store_employee_by_name($pdo, $name)
    {
        $roleColumn = approval_store_column_exists($pdo, 'employees', 'role') ? 'role' : "'' AS role";
        $isTeamLeaderColumn = approval_store_column_exists($pdo, 'employees', 'is_team_leader') ? 'is_team_leader' : "0 AS is_team_leader";
        $teamLeaderIdColumn = approval_store_column_exists($pdo, 'employees', 'team_leader_id') ? 'team_leader_id' : "0 AS team_leader_id";
        $approvalLeadColumn = approval_store_column_exists($pdo, 'employees', 'approval_can_be_team_leader') ? 'approval_can_be_team_leader' : "0 AS approval_can_be_team_leader";
        $approvalGongmuColumn = approval_store_column_exists($pdo, 'employees', 'approval_can_be_gongmu_approver') ? 'approval_can_be_gongmu_approver' : "0 AS approval_can_be_gongmu_approver";
        $approvalManageColumn = approval_store_column_exists($pdo, 'employees', 'approval_can_be_manage_approver') ? 'approval_can_be_manage_approver' : "0 AS approval_can_be_manage_approver";
        $st = $pdo->prepare("SELECT id,name,email,department,position," . $roleColumn . "," . $isTeamLeaderColumn . "," . $teamLeaderIdColumn . "," . $approvalLeadColumn . "," . $approvalGongmuColumn . "," . $approvalManageColumn . " FROM employees WHERE name=:name AND is_active=1 LIMIT 1");
        $st->execute(array(':name' => $name));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? $row : null;
    }
}

if (!function_exists('approval_store_default_approver_by_role')) {
    function approval_store_default_approver_by_role($pdo, $roleType)
    {
        if (!$pdo) {
            return null;
        }
        $roleType = trim((string)$roleType);
        $roleColumn = approval_store_column_exists($pdo, 'employees', 'role') ? 'role' : "'' AS role";
        $select = "id,name,email,department,position," . $roleColumn;
        $flagColumn = '';
        $dept = '';
        if ($roleType === approval_ko('%EA%B3%B5%EB%AC%B4')) {
            $flagColumn = 'approval_can_be_gongmu_approver';
            $dept = approval_ko('%EA%B3%B5%EB%AC%B4');
        } else if ($roleType === approval_ko('%EA%B4%80%EB%A6%AC')) {
            $flagColumn = 'approval_can_be_manage_approver';
            $dept = approval_ko('%EA%B4%80%EB%A6%AC');
        }
        if ($flagColumn !== '' && approval_store_column_exists($pdo, 'employees', $flagColumn)) {
            try {
                $st = $pdo->prepare("SELECT " . $select . " FROM employees WHERE is_active=1 AND " . $flagColumn . "=1 ORDER BY name ASC LIMIT 1");
                $st->execute();
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    return $row;
                }
            } catch (Exception $e) {
            }
        }
        if ($dept !== '') {
            try {
                $deptBu = $dept . approval_ko('%EB%B6%80');
                $deptTeam = $dept . approval_ko('%ED%8C%80');
                $st = $pdo->prepare("SELECT " . $select . " FROM employees WHERE is_active=1 AND (department=:dept OR department=:dept_bu OR department=:dept_team) ORDER BY name ASC LIMIT 1");
                $st->execute(array(':dept' => $dept, ':dept_bu' => $deptBu, ':dept_team' => $deptTeam));
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    return $row;
                }
            } catch (Exception $e) {
            }
        }
        return null;
    }
}

if (!function_exists('approval_store_line_emp_id')) {
    function approval_store_line_emp_id($line)
    {
        if (!is_array($line) || !isset($line['emp']) || !is_array($line['emp']) || !isset($line['emp']['id'])) {
            return 0;
        }
        return (int)$line['emp']['id'];
    }
}

if (!function_exists('approval_store_mark_line_delegated')) {
    function approval_store_mark_line_delegated(&$line, $reason, $delegatedByRole)
    {
        if (!is_array($line)) {
            return;
        }
        if (isset($line['status']) && in_array(strtoupper((string)$line['status']), array('APPROVED', 'REJECTED'), true)) {
            return;
        }
        $line['status'] = 'DELEGATED';
        $line['delegated'] = 1;
        $line['auto_reason'] = trim((string)$reason);
        $line['acted_at'] = date('Y-m-d H:i:s');
        if ($delegatedByRole !== null) {
            $line['delegated_by_role'] = $delegatedByRole;
        }
    }
}

if (!function_exists('approval_store_force_line_actual_waiting')) {
    function approval_store_force_line_actual_waiting(&$line)
    {
        if (!is_array($line)) {
            return;
        }
        if (isset($line['status']) && in_array(strtoupper((string)$line['status']), array('APPROVED', 'REJECTED'), true)) {
            return;
        }
        $line['delegated'] = 0;
        if (isset($line['status']) && strtoupper((string)$line['status']) === 'DELEGATED') {
            $line['status'] = 'WAITING';
        }
        if (isset($line['auto_reason'])) {
            unset($line['auto_reason']);
        }
        if (isset($line['delegated_by_role'])) {
            unset($line['delegated_by_role']);
        }
    }
}

if (!function_exists('approval_store_auto_role_rank')) {
    function approval_store_auto_role_rank($role)
    {
        $roleRawNorm = approval_normalize_compare_text($role);
        $roleNorm = approval_normalize_compare_text(approval_role_label($role));
        $manageNorm = approval_normalize_compare_text(approval_ko('%EA%B4%80%EB%A6%AC'));
        $gongmuNorm = approval_normalize_compare_text(approval_ko('%EA%B3%B5%EB%AC%B4'));
        $teamNorm = approval_normalize_compare_text(approval_ko('%ED%8C%80%EC%9E%A5'));
        $siteNorm = approval_normalize_compare_text(approval_ko('%EC%86%8C%EC%9E%A5'));
        $constructionPmNorm = approval_normalize_compare_text(approval_ko('%EA%B3%B5%EC%82%AC%50%4D'));
        if ($roleNorm === $manageNorm) {
            return 1;
        }
        if ($roleNorm === $gongmuNorm) {
            return 2;
        }
        if ($roleNorm === $teamNorm || $roleNorm === $siteNorm) {
            return 3;
        }
        if ($roleRawNorm === 'pm' || $roleNorm === 'pm' || $roleRawNorm === $constructionPmNorm || $roleNorm === $constructionPmNorm) {
            return 4;
        }
        if (approval_role_is_vp($role)) {
            return 5;
        }
        if (approval_role_is_ceo($role)) {
            return 6;
        }
        return 0;
    }
}

if (!function_exists('approval_store_apply_auto_delegation_rules')) {
    function approval_store_apply_auto_delegation_rules($pdo, $docType, &$lines, $creatorEmployee, $creatorEmployeeId, $creatorEmail, $creatorName)
    {
        if (!approval_auto_delegate_target_doc_type($docType) || !is_array($lines)) {
            return;
        }
        $baseDate = date('Y-m-d');
        $vpRole = approval_ko('%EB%B6%80%EC%82%AC%EC%9E%A5');
        $ceoRole = approval_ko('%EB%8C%80%ED%91%9C%EC%9D%B4%EC%82%AC');
        $creator = is_array($creatorEmployee) ? $creatorEmployee : array('id' => $creatorEmployeeId, 'name' => $creatorName, 'email' => $creatorEmail);
        $creatorIndex = -1;
        $vpLeaveRequiresCeo = false;
        $forceCeoActual = false;

        for ($i = 0; $i < count($lines); $i++) {
            if (!empty($lines[$i]['force_actual'])) {
                $forceCeoActual = true;
            }
        }

        for ($i = 0; $i < count($lines); $i++) {
            if (isset($lines[$i]['emp']) && approval_employee_identity_matches($lines[$i]['emp'], $creatorEmployeeId, $creatorEmail, $creatorName)) {
                $creatorIndex = $i;
                break;
            }
        }

        if ($creatorIndex >= 0) {
            $creatorRole = isset($lines[$creatorIndex]['role']) ? (string)$lines[$creatorIndex]['role'] : '';
            $creatorRank = approval_store_auto_role_rank($creatorRole);
            for ($i = 0; $i < count($lines); $i++) {
                $rank = approval_store_auto_role_rank(isset($lines[$i]['role']) ? $lines[$i]['role'] : '');
                if ($creatorRank > 0 && $rank > 0 && $rank < $creatorRank) {
                    approval_store_mark_line_delegated($lines[$i], approval_auto_delegate_reason_label('previous_step'), null);
                } else if ($creatorRank <= 0 && $i < $creatorIndex) {
                    approval_store_mark_line_delegated($lines[$i], approval_auto_delegate_reason_label('previous_step'), null);
                }
            }
            if (empty($lines[$creatorIndex]['allow_self_approval'])) {
                approval_store_mark_line_delegated($lines[$creatorIndex], approval_auto_delegate_reason_label('self'), null);
            }
        }

        if ($docType === 'leave' && approval_employee_is_executive($creator)) {
            for ($i = 0; $i < count($lines); $i++) {
                $role = isset($lines[$i]['role']) ? (string)$lines[$i]['role'] : '';
                if (approval_role_is_team_or_pm($role) && (!isset($lines[$i]['status']) || strtoupper((string)$lines[$i]['status']) !== 'DELEGATED')) {
                    approval_store_mark_line_delegated($lines[$i], approval_auto_delegate_reason_label('higher_position'), null);
                }
            }
        }

        if (approval_employee_is_vp($creator)) {
            $forceCeoActual = true;
        }

        for ($i = 0; $i < count($lines); $i++) {
            $role = isset($lines[$i]['role']) ? (string)$lines[$i]['role'] : '';
            $empId = approval_store_line_emp_id($lines[$i]);
            if (!empty($lines[$i]['skip_auto_delegate'])) {
                continue;
            }
            if (approval_role_is_vp($role) && approval_is_employee_on_leave($pdo, $empId, $baseDate)) {
                approval_store_mark_line_delegated($lines[$i], approval_auto_delegate_reason_label('vp_leave_ceo_proxy'), $ceoRole);
                $vpLeaveRequiresCeo = true;
            }
        }

        if ($vpLeaveRequiresCeo) {
            $forceCeoActual = true;
        }

        if ($forceCeoActual) {
            for ($i = 0; $i < count($lines); $i++) {
                $role = isset($lines[$i]['role']) ? (string)$lines[$i]['role'] : '';
                if (approval_role_is_ceo($role)) {
                    approval_store_force_line_actual_waiting($lines[$i]);
                }
            }
        }

        for ($i = 0; $i < count($lines); $i++) {
            $role = isset($lines[$i]['role']) ? (string)$lines[$i]['role'] : '';
            $empId = approval_store_line_emp_id($lines[$i]);
            $isAlreadyDelegated = (isset($lines[$i]['delegated']) && (int)$lines[$i]['delegated'] === 1) || (isset($lines[$i]['status']) && strtoupper((string)$lines[$i]['status']) === 'DELEGATED');
            if (!empty($lines[$i]['skip_auto_delegate'])) {
                continue;
            }
            if (!$isAlreadyDelegated && approval_is_employee_on_leave($pdo, $empId, $baseDate)) {
                approval_store_mark_line_delegated($lines[$i], approval_auto_delegate_reason_label('on_leave'), null);
                continue;
            }
            if (!$isAlreadyDelegated && $docType === 'leave' && approval_role_is_ceo($role) && !$forceCeoActual) {
                approval_store_mark_line_delegated($lines[$i], approval_auto_delegate_reason_label('leave_ceo_default'), $vpRole);
                continue;
            }
            if ($isAlreadyDelegated && !isset($lines[$i]['status'])) {
                $reason = isset($lines[$i]['auto_reason']) && trim((string)$lines[$i]['auto_reason']) !== '' ? $lines[$i]['auto_reason'] : approval_auto_delegate_reason_label($docType === 'leave' && approval_role_is_ceo($role) ? 'leave_ceo_default' : 'auto');
                approval_store_mark_line_delegated($lines[$i], $reason, isset($lines[$i]['delegated_by_role']) ? $lines[$i]['delegated_by_role'] : null);
            }
        }
    }
}

if (!function_exists('approval_store_project_root')) {
    function approval_store_project_root()
    {
        $root = realpath(__DIR__ . '/../../..');
        if ($root && is_dir($root . '/app') && is_dir($root . '/public')) {
            return $root;
        }
        return dirname(dirname(dirname(__DIR__)));
    }
}

if (!function_exists('approval_store_upload_error_message')) {
    function approval_store_upload_error_message($code)
    {
        switch ((int)$code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return approval_ko('%EC%97%85%EB%A1%9C%EB%93%9C%20%ED%97%88%EC%9A%A9%20%EC%9A%A9%EB%9F%89%EC%9D%84%20%EC%B4%88%EA%B3%BC%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.');
            case UPLOAD_ERR_PARTIAL:
                return approval_ko('%ED%8C%8C%EC%9D%BC%EC%9D%B4%20%EC%9D%BC%EB%B6%80%EB%A7%8C%20%EC%97%85%EB%A1%9C%EB%93%9C%EB%90%98%EC%97%88%EC%8A%B5%EB%8B%88%EB%8B%A4.');
            case UPLOAD_ERR_NO_FILE:
                return approval_ko('%ED%8C%8C%EC%9D%BC%EC%9D%B4%20%EC%84%A0%ED%83%9D%EB%90%98%EC%A7%80%20%EC%95%8A%EC%95%98%EC%8A%B5%EB%8B%88%EB%8B%A4.');
            default:
                return approval_ko('%ED%8C%8C%EC%9D%BC%20%EC%97%85%EB%A1%9C%EB%93%9C%20%EC%98%A4%EB%A5%98%EA%B0%80%20%EB%B0%9C%EC%83%9D%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.');
        }
    }
}

if (!function_exists('approval_store_latest_annual_leave_snapshot')) {
    function approval_store_latest_annual_leave_snapshot($pdo, $employeeId)
    {
        $result = array(
            'grant_date' => '',
            'grant_days' => 0.0,
            'used_days' => 0.0,
            'unused_days' => 0.0,
            'usable_period' => '',
            'occurrence_label' => ''
        );
        if (!$pdo || (int)$employeeId <= 0) {
            return $result;
        }

        try {
            $st = $pdo->prepare("SELECT accrual_date, amount FROM cpms_leave_accrual_logs WHERE employee_id=:employee_id AND leave_type='ANNUAL' AND accrual_date<=CURDATE() ORDER BY accrual_date DESC, id DESC LIMIT 1");
            $st->execute(array(':employee_id' => (int)$employeeId));
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return $result;
            }
            $grantDate = cpms_leave_parse_date(isset($row['accrual_date']) ? $row['accrual_date'] : '');
            $grantDays = isset($row['amount']) ? cpms_leave_normalize_half_step($row['amount']) : 0.0;
            $result['grant_date'] = $grantDate;
            $result['grant_days'] = $grantDays;
            if ($grantDate !== '') {
                $usableEnd = cpms_leave_add_days(cpms_leave_add_years_clamped($grantDate, 1), -1);
                $result['usable_period'] = $grantDate . ' ~ ' . $usableEnd;
            }

            $hireSt = $pdo->prepare("SELECT hire_date, leave_annual_balance FROM employees WHERE id=:id LIMIT 1");
            $hireSt->execute(array(':id' => (int)$employeeId));
            $employee = $hireSt->fetch(PDO::FETCH_ASSOC);
            if ($employee) {
                $unusedDays = isset($employee['leave_annual_balance']) ? cpms_leave_normalize_half_step($employee['leave_annual_balance']) : 0.0;
                $usedDays = cpms_leave_normalize_half_step(max(0, $grantDays - $unusedDays));
                $result['unused_days'] = $unusedDays;
                $result['used_days'] = $usedDays;
                $hireDate = cpms_leave_parse_date(isset($employee['hire_date']) ? $employee['hire_date'] : '');
                if ($hireDate !== '' && $grantDate !== '') {
                    $year = 0;
                    for ($i = 1; $i <= 50; $i++) {
                        $anniversary = cpms_leave_add_years_clamped($hireDate, $i);
                        $accrual = cpms_leave_add_days($anniversary, 1);
                        if ($accrual === $grantDate) {
                            $year = $i;
                            break;
                        }
                    }
                    if ($year > 0) {
                        $result['occurrence_label'] = urldecode('%EC%9E%85%EC%82%AC%EC%9D%BC%20%ED%9B%84%20') . $year . urldecode('%EB%85%84%201%EC%9D%BC%20%EB%92%A4');
                    }
                }
            }
        } catch (Exception $e) {
            return $result;
        }

        return $result;
    }
}

if (!function_exists('approval_store_build_notice_message')) {
    function approval_store_build_notice_message($snapshot)
    {
        $grantDays = isset($snapshot['grant_days']) ? cpms_leave_format_decimal($snapshot['grant_days']) : '0';
        $usedDays = isset($snapshot['used_days']) ? cpms_leave_format_decimal($snapshot['used_days']) : '0';
        $unusedDays = isset($snapshot['unused_days']) ? cpms_leave_format_decimal($snapshot['unused_days']) : '0';
        return '상기인은 현재 ' . $grantDays . '일의 연차 중 [ ' . $usedDays . ' ]일의 연차휴가를 사용하여, 사용기간까지 [ ' . $unusedDays . ' ]일의 연차휴가를 추가로 사용할 수 있습니다.'
            . "\n\n"
            . '상기인은 10일 이내에 향후 6개월 간 연차 사용 시기를 정하여 회사로 통보해주시기 바랍니다.'
            . "\n\n"
            . '만약, 연차휴가 사용 시기를 통보하지 않는다면, 회사는 근로기준법에 근거하여 연차휴가 사용기간 마지막 2개월 사이의 일자를 임의로 연차휴가 사용일로 지정하여 연차 사용기간 종료일 2개월 전까지 통보하도록 하겠습니다.'
            . "\n\n"
            . '연차휴가일을 지정하지 않고, 회사가 지정한 연차휴가일에 연차휴가를 사용하지 않는 경우, 근로기준법에 따라 해당 연차휴가는 소멸하며, 수당도 지급되지 않음에 유의하시기 바랍니다.'
            . "\n\n"
            . '위와 같이 연차사용촉진제도에 의거하여 연차휴가 사용을 촉구합니다.';
    }
}

if (!function_exists('approval_store_build_notice_message_safe')) {
    function approval_store_build_notice_message_safe($snapshot)
    {
        $grantDays = isset($snapshot['grant_days']) ? cpms_leave_format_decimal($snapshot['grant_days']) : '0';
        $usedDays = isset($snapshot['used_days']) ? cpms_leave_format_decimal($snapshot['used_days']) : '0';
        $unusedDays = isset($snapshot['unused_days']) ? cpms_leave_format_decimal($snapshot['unused_days']) : '0';

        return implode("\n\n", array(
            approval_ko('%EC%83%81%EA%B8%B0%EC%9D%B8%EC%9D%80%20%ED%98%84%EC%9E%AC%20') . $grantDays . approval_ko('%EC%9D%BC%EC%9D%98%20%EC%97%B0%EC%B0%A8%20%EC%A4%91%20%5B%20') . $usedDays . approval_ko('%20%5D%EC%9D%BC%EC%9D%98%20%EC%97%B0%EC%B0%A8%ED%9C%B4%EA%B0%80%EB%A5%BC%20%EC%82%AC%EC%9A%A9%ED%95%98%EC%97%AC%2C%20%EC%82%AC%EC%9A%A9%EA%B8%B0%EA%B0%84%EA%B9%8C%EC%A7%80%20%5B%20') . $unusedDays . approval_ko('%20%5D%EC%9D%BC%EC%9D%98%20%EC%97%B0%EC%B0%A8%ED%9C%B4%EA%B0%80%EB%A5%BC%20%EC%B6%94%EA%B0%80%20%EC%82%AC%EC%9A%A9%ED%95%A0%20%EC%88%98%20%EC%9E%88%EC%8A%B5%EB%8B%88%EB%8B%A4.'),
            approval_ko('%EC%83%81%EA%B8%B0%EC%9D%B8%EC%9D%80%2010%EC%9D%BC%20%EC%9D%B4%EB%82%B4%EC%97%90%20%ED%96%A5%ED%9B%84%206%EA%B0%9C%EC%9B%94%20%EA%B0%84%20%EC%97%B0%EC%B0%A8%20%EC%82%AC%EC%9A%A9%20%EC%8B%9C%EA%B8%B0%EB%A5%BC%20%EC%A0%95%ED%95%98%EC%97%AC%20%ED%9A%8C%EC%82%AC%EB%A1%9C%20%ED%86%B5%EB%B3%B4%ED%95%B4%EC%A3%BC%EC%8B%9C%EA%B8%B0%20%EB%B0%94%EB%9E%8D%EB%8B%88%EB%8B%A4.'),
            approval_ko('%EB%A7%8C%EC%95%BD%2C%20%EC%97%B0%EC%B0%A8%ED%9C%B4%EA%B0%80%20%EC%82%AC%EC%9A%A9%20%EC%8B%9C%EA%B8%B0%EB%A5%BC%20%ED%86%B5%EB%B3%B4%ED%95%98%EC%A7%80%20%EC%95%8A%EB%8A%94%EB%8B%A4%EB%A9%B4%2C%20%ED%9A%8C%EC%82%AC%EB%8A%94%20%EA%B7%BC%EB%A1%9C%EA%B8%B0%EC%A4%80%EB%B2%95%EC%97%90%20%EA%B7%BC%EA%B1%B0%ED%95%98%EC%97%AC%20%EC%97%B0%EC%B0%A8%ED%9C%B4%EA%B0%80%20%EC%82%AC%EC%9A%A9%EA%B8%B0%EA%B0%84%20%EB%A7%88%EC%A7%80%EB%A7%89%202%EA%B0%9C%EC%9B%94%20%EC%82%AC%EC%9D%B4%EC%9D%98%20%EC%9D%BC%EC%9E%90%EB%A5%BC%20%EC%9E%84%EC%9D%98%EB%A1%9C%20%EC%97%B0%EC%B0%A8%ED%9C%B4%EA%B0%80%20%EC%82%AC%EC%9A%A9%EC%9D%BC%EB%A1%9C%20%EC%A7%80%EC%A0%95%ED%95%98%EC%97%AC%20%EC%97%B0%EC%B0%A8%20%EC%82%AC%EC%9A%A9%EA%B8%B0%EA%B0%84%20%EC%A2%85%EB%A3%8C%EC%9D%BC%202%EA%B0%9C%EC%9B%94%20%EC%A0%84%EA%B9%8C%EC%A7%80%20%ED%86%B5%EB%B3%B4%ED%95%98%EB%8F%84%EB%A1%9D%20%ED%95%98%EA%B2%A0%EC%8A%B5%EB%8B%88%EB%8B%A4.'),
            approval_ko('%EC%97%B0%EC%B0%A8%ED%9C%B4%EA%B0%80%EC%9D%BC%EC%9D%84%20%EC%A7%80%EC%A0%95%ED%95%98%EC%A7%80%20%EC%95%8A%EA%B3%A0%2C%20%ED%9A%8C%EC%82%AC%EA%B0%80%20%EC%A7%80%EC%A0%95%ED%95%9C%20%EC%97%B0%EC%B0%A8%ED%9C%B4%EA%B0%80%EC%9D%BC%EC%97%90%20%EC%97%B0%EC%B0%A8%ED%9C%B4%EA%B0%80%EB%A5%BC%20%EC%82%AC%EC%9A%A9%ED%95%98%EC%A7%80%20%EC%95%8A%EB%8A%94%20%EA%B2%BD%EC%9A%B0%2C%20%EA%B7%BC%EB%A1%9C%EA%B8%B0%EC%A4%80%EB%B2%95%EC%97%90%20%EB%94%B0%EB%9D%BC%20%ED%95%B4%EB%8B%B9%20%EC%97%B0%EC%B0%A8%ED%9C%B4%EA%B0%80%EB%8A%94%20%EC%86%8C%EB%A9%B8%ED%95%98%EB%A9%B0%2C%20%EC%88%98%EB%8B%B9%EB%8F%84%20%EC%A7%80%EA%B8%89%EB%90%98%EC%A7%80%20%EC%95%8A%EC%9D%8C%EC%97%90%20%EC%9C%A0%EC%9D%98%ED%95%98%EC%8B%9C%EA%B8%B0%20%EB%B0%94%EB%9E%8D%EB%8B%88%EB%8B%A4.'),
            approval_ko('%EC%9C%84%EC%99%80%20%EA%B0%99%EC%9D%B4%20%EC%97%B0%EC%B0%A8%EC%82%AC%EC%9A%A9%EC%B4%89%EC%A7%84%EC%A0%9C%EB%8F%84%EC%97%90%20%EC%9D%98%EA%B1%B0%ED%95%98%EC%97%AC%20%EC%97%B0%EC%B0%A8%ED%9C%B4%EA%B0%80%20%EC%82%AC%EC%9A%A9%EC%9D%84%20%EC%B4%89%EA%B5%AC%ED%95%A9%EB%8B%88%EB%8B%A4.')
        ));
    }
}

$creatorEmployeeId = approval_current_employee_id($pdo, $user);
$creatorName = approval_current_user_name($user);
$creatorEmail = approval_current_user_email($user);
$creatorEmployee = null;
if ($creatorEmployeeId > 0) {
    try {
        $creatorEmployee = approval_store_employee($pdo, $creatorEmployeeId);
    } catch (Exception $e) {
        $creatorEmployee = null;
    }
}
if (!is_array($creatorEmployee)) {
    $creatorEmployee = array('id' => $creatorEmployeeId, 'name' => $creatorName, 'email' => $creatorEmail);
}
if (is_array($user)) {
    if (!isset($creatorEmployee['role']) && isset($user['role'])) {
        $creatorEmployee['role'] = $user['role'];
    }
    if (!isset($creatorEmployee['position']) && isset($user['position'])) {
        $creatorEmployee['position'] = $user['position'];
    }
    if (!isset($creatorEmployee['department']) && isset($user['department'])) {
        $creatorEmployee['department'] = $user['department'];
    }
}
$docType = isset($_POST['doc_type']) ? trim((string)$_POST['doc_type']) : 'proposal';
if (!in_array($docType, array('proposal', 'small_proposal', 'leave', 'unused_leave_notice', 'unused_leave_plan'), true)) {
    $docType = 'proposal';
}
$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$isManagementOnlyDoc = approval_is_management_only_doc_type($docType);
if ($isManagementOnlyDoc && !approval_is_management_department_user($pdo, $user)) {
    flash_set('danger', approval_ko('%EA%B4%80%EB%A6%AC%EB%B6%80%EB%A7%8C%20%EC%9E%91%EC%84%B1%ED%95%A0%20%EC%88%98%20%EC%9E%88%EB%8A%94%20%EB%AC%B8%EC%84%9C%EC%9E%85%EB%8B%88%EB%8B%A4.'));
    header('Location: ?r=approval_home&view=active');
    exit;
}

$postedConstructionTeamLeaderId = isset($_POST['construction_team_leader_id']) ? (int)$_POST['construction_team_leader_id'] : 0;
if ($postedConstructionTeamLeaderId <= 0 && isset($_POST['team_leader_id'])) {
    $postedConstructionTeamLeaderId = (int)$_POST['team_leader_id'];
}
if (!$isManagementOnlyDoc && ($docType === 'leave' || approval_is_proposal_doc_type($docType)) && approval_line_rules_requires_manual_team_leader_for_doc($creatorEmployee, $docType)) {
    if ($postedConstructionTeamLeaderId > 0) {
        $creatorEmployee['team_leader_id'] = $postedConstructionTeamLeaderId;
    }
    if (!isset($creatorEmployee['team_leader_id']) || (int)$creatorEmployee['team_leader_id'] <= 0) {
        flash_set('danger', approval_ko('%EA%B3%B5%EC%82%AC%20%EC%9D%B8%EC%9B%90%EC%9D%80%20%ED%98%84%EC%9E%A5%20%ED%8C%80%EC%9E%A5%EC%9D%84%20%EC%84%A0%ED%83%9D%ED%95%B4%20%EC%A3%BC%EC%84%B8%EC%9A%94.'));
        header('Location: ?r=approval_create&type=' . $docType);
        exit;
    }
}

$vpRole = approval_ko('%EB%B6%80%EC%82%AC%EC%9E%A5');
$ceoRole = approval_ko('%EB%8C%80%ED%91%9C%EC%9D%B4%EC%82%AC');
$siteRole = approval_ko('%EC%86%8C%EC%9E%A5');
$teamRole = approval_ko('%ED%8C%80%EC%9E%A5');
$gongmuRole = approval_ko('%EA%B3%B5%EB%AC%B4');
$manageRole = approval_ko('%EA%B4%80%EB%A6%AC');
$pmRole = 'PM';
$goName = approval_ko('%EA%B3%A0%EC%98%81%EC%84%B1');

$vp = approval_line_rules_find_vp($pdo);
$ceo = approval_line_rules_find_ceo($pdo);

$contentData = array();
$title = '';
$lines = array();
$delegateLevel = 'none';

if ($isManagementOnlyDoc) {
    $targetEmployee = approval_store_employee($pdo, isset($_POST['target_employee_id']) ? (int)$_POST['target_employee_id'] : 0);
    if (!$targetEmployee) {
        flash_set('danger', approval_ko('%EB%8C%80%EC%83%81%EC%9E%90%EB%A5%BC%20%EC%84%A0%ED%83%9D%ED%95%B4%20%EC%A3%BC%EC%84%B8%EC%9A%94.'));
        header('Location: ?r=approval_create&type=' . $docType);
        exit;
    }

    $targetDepartment = isset($_POST['target_department']) ? trim((string)$_POST['target_department']) : '';
    $targetPosition = isset($_POST['target_position']) ? trim((string)$_POST['target_position']) : '';
    if ($targetDepartment === '' && isset($targetEmployee['department'])) {
        $targetDepartment = trim((string)$targetEmployee['department']);
    }
    if ($targetPosition === '' && isset($targetEmployee['position'])) {
        $targetPosition = trim((string)$targetEmployee['position']);
    }

    $sentAt = date('Y-m-d H:i:s');
    $snapshot = approval_store_latest_annual_leave_snapshot($pdo, (int)$targetEmployee['id']);
    $contentData = array(
        'writer_email' => $creatorEmail,
        'sender_name' => $creatorName,
        'sent_at' => $sentAt,
        'target_employee_id' => (int)$targetEmployee['id'],
        'target_name' => isset($targetEmployee['name']) ? trim((string)$targetEmployee['name']) : '',
        'target_department' => $targetDepartment,
        'target_position' => $targetPosition,
        'target_hire_date' => isset($targetEmployee['hire_date']) ? trim((string)$targetEmployee['hire_date']) : '',
        'unused_leave_days' => cpms_leave_format_decimal(isset($snapshot['unused_days']) ? $snapshot['unused_days'] : 0),
        'used_leave_days' => cpms_leave_format_decimal(isset($snapshot['used_days']) ? $snapshot['used_days'] : 0),
        'annual_grant_date' => isset($snapshot['grant_date']) ? $snapshot['grant_date'] : '',
        'annual_expiry_date' => isset($snapshot['grant_date']) && $snapshot['grant_date'] !== '' ? cpms_leave_add_days(cpms_leave_add_years_clamped($snapshot['grant_date'], 1), -1) : '',
        'annual_occurrence_label' => isset($snapshot['occurrence_label']) ? $snapshot['occurrence_label'] : '',
        'annual_granted_days' => cpms_leave_format_decimal(isset($snapshot['grant_days']) ? $snapshot['grant_days'] : 0),
        'annual_usable_period' => isset($snapshot['usable_period']) ? $snapshot['usable_period'] : ''
    );
    if ($docType === 'unused_leave_notice') {
        $contentData['notice_message'] = approval_store_build_notice_message_safe($snapshot);
    } else {
        $contentData['plan_notice_date'] = isset($_POST['plan_notice_date']) ? trim((string)$_POST['plan_notice_date']) : '';
        $contentData['plan_period_1'] = '';
        $contentData['plan_period_2'] = '';
        $contentData['plan_period_3'] = '';
        $contentData['plan_days_1'] = '';
        $contentData['plan_days_2'] = '';
        $contentData['plan_days_3'] = '';
        $contentData['plan_total_days'] = '';
        $contentData['receiver_signed_name'] = '';
    }

    $title = approval_doc_label($docType) . ' - ' . $contentData['target_name'];
    $lines[] = array(
        'role' => $manageRole,
        'emp' => array(
            'id' => $creatorEmployeeId,
            'name' => $creatorName,
            'email' => $creatorEmail
        ),
        'status' => 'APPROVED',
        'acted_at' => $sentAt,
        'sign_path' => approval_sign_path_by_email($creatorEmail),
        'delegated' => 0
    );
    $lines[] = array(
        'role' => approval_ko('%EB%B3%B8%EC%9D%B8'),
        'emp' => array(
            'id' => isset($targetEmployee['id']) ? (int)$targetEmployee['id'] : 0,
            'name' => isset($targetEmployee['name']) ? (string)$targetEmployee['name'] : '',
            'email' => isset($targetEmployee['email']) ? (string)$targetEmployee['email'] : ''
        ),
        'delegated' => 0
    );
} else if ($docType === 'leave') {
    $start = isset($_POST['leave_start_date']) ? trim((string)$_POST['leave_start_date']) : '';
    $end = isset($_POST['leave_end_date']) ? trim((string)$_POST['leave_end_date']) : '';
    if ($start === '' || $end === '' || strtotime($start) === false || strtotime($end) === false || strtotime($start) > strtotime($end)) {
        flash_set('danger', approval_ko('%ED%9C%B4%EA%B0%80%20%EA%B8%B0%EA%B0%84%EC%9D%84%20%EB%8B%A4%EC%8B%9C%20%ED%99%95%EC%9D%B8%ED%95%B4%EC%A3%BC%EC%84%B8%EC%9A%94.'));
        header('Location: ?r=approval_create&type=leave');
        exit;
    }
    $days = isset($_POST['leave_days']) ? trim((string)$_POST['leave_days']) : '';
    if ($days === '' || (float)$days <= 0) {
        $days = (string)(floor((strtotime($end) - strtotime($start)) / 86400) + 1);
    }
    $department = isset($_POST['department']) ? trim((string)$_POST['department']) : '';
    if ($creatorEmployeeId > 0) {
        try {
            $me = approval_store_employee($pdo, $creatorEmployeeId);
            if ($me) {
                if (isset($me['department'])) {
                    $department = trim((string)$me['department']);
                }
                if (isset($me['position'])) {
                    $_POST['position'] = trim((string)$me['position']);
                }
                if (isset($me['name']) && trim((string)$me['name']) !== '') {
                    $_POST['applicant_name'] = trim((string)$me['name']);
                }
            }
        } catch (Exception $e) {
        }
    }
    $normDept = approval_norm_dept($department);
    $contentData = array(
        'request_type' => isset($_POST['request_type']) ? trim((string)$_POST['request_type']) : '',
        'request_type_etc' => isset($_POST['request_type_etc']) ? trim((string)$_POST['request_type_etc']) : '',
        'department' => $department,
        'position' => isset($_POST['position']) ? trim((string)$_POST['position']) : '',
        'applicant_name' => isset($_POST['applicant_name']) ? trim((string)$_POST['applicant_name']) : $creatorName,
        'birth_date' => isset($_POST['birth_date']) ? trim((string)$_POST['birth_date']) : '',
        'leave_start_date' => $start,
        'leave_end_date' => $end,
        'leave_days' => $days,
        'leave_reason' => isset($_POST['leave_reason']) ? trim((string)$_POST['leave_reason']) : '',
        'request_date' => isset($_POST['request_date']) ? trim((string)$_POST['request_date']) : date('Y-m-d'),
        'applicant_sign_name' => isset($_POST['applicant_sign_name']) ? trim((string)$_POST['applicant_sign_name']) : $creatorName,
        'applicant_email' => $creatorEmail,
        'writer_email' => $creatorEmail,
        'ceo_name' => isset($ceo['name']) ? $ceo['name'] : '',
        'delegate_level' => 'none'
    );
    $title = approval_doc_label('leave') . ' - ' . $contentData['applicant_name'];
    $ruleResult = approval_line_rules_build($pdo, $docType, $creatorEmployee, $contentData);
    if (approval_line_rules_requires_manual_team_leader($creatorEmployee) && (!isset($ruleResult['team_lead']) || !is_array($ruleResult['team_lead']))) {
        flash_set('danger', approval_ko('%EA%B3%B5%EC%82%AC%20%EC%9D%B8%EC%9B%90%EC%9D%80%20%ED%98%84%EC%9E%A5%20%ED%8C%80%EC%9E%A5%EC%9D%84%20%EC%84%A0%ED%83%9D%ED%95%B4%20%EC%A3%BC%EC%84%B8%EC%9A%94.'));
        header('Location: ?r=approval_create&type=leave');
        exit;
    }
    $lines = isset($ruleResult['lines']) && is_array($ruleResult['lines']) ? $ruleResult['lines'] : array();
    $contentData['approval_line_messages'] = isset($ruleResult['messages']) && is_array($ruleResult['messages']) ? $ruleResult['messages'] : array();
    $contentData['approval_line_warnings'] = isset($ruleResult['warnings']) && is_array($ruleResult['warnings']) ? $ruleResult['warnings'] : array();
    $contentData['approval_force_ceo_actual'] = isset($ruleResult['force_ceo_actual']) ? (int)$ruleResult['force_ceo_actual'] : 0;
    $contentData['approval_line_preview'] = approval_line_rules_line_names($lines);
    $delegateLevel = 'none';
} else {
    $delegateLevel = 'none';
    $draftDepartment = isset($_POST['draft_department']) ? trim((string)$_POST['draft_department']) : '';
    $drafterName = isset($_POST['drafter_name']) ? trim((string)$_POST['drafter_name']) : $creatorName;
    if (isset($creatorEmployee['department']) && trim((string)$creatorEmployee['department']) !== '') {
        $draftDepartment = trim((string)$creatorEmployee['department']);
    }
    if (isset($creatorEmployee['name']) && trim((string)$creatorEmployee['name']) !== '') {
        $drafterName = trim((string)$creatorEmployee['name']);
    }
    $contentData = array(
        'draft_date' => isset($_POST['draft_date']) ? trim((string)$_POST['draft_date']) : '',
        'effective_date' => isset($_POST['effective_date']) ? trim((string)$_POST['effective_date']) : '',
        'draft_department' => $draftDepartment,
        'drafter_name' => $drafterName,
        'draft_type' => isset($_POST['draft_type']) ? trim((string)$_POST['draft_type']) : '',
        'title' => isset($_POST['title']) ? trim((string)$_POST['title']) : '',
        'headline' => isset($_POST['headline']) ? trim((string)$_POST['headline']) : '',
        'intro_text' => isset($_POST['intro_text']) ? trim((string)$_POST['intro_text']) : '',
        'reason' => isset($_POST['reason']) ? trim((string)$_POST['reason']) : '',
        'company_name' => isset($_POST['company_name']) ? trim((string)$_POST['company_name']) : '',
        'contract_amount' => isset($_POST['contract_amount']) ? trim((string)$_POST['contract_amount']) : '',
        'advance_amount' => isset($_POST['advance_amount']) ? trim((string)$_POST['advance_amount']) : '',
        'special_note_1' => isset($_POST['special_note_1']) ? trim((string)$_POST['special_note_1']) : '',
        'special_note_2' => isset($_POST['special_note_2']) ? trim((string)$_POST['special_note_2']) : '',
        'payment_request_date' => isset($_POST['payment_request_date']) ? trim((string)$_POST['payment_request_date']) : '',
        'budget_status' => isset($_POST['budget_status']) ? trim((string)$_POST['budget_status']) : '',
        'writer_name' => $drafterName,
        'writer_email' => $creatorEmail,
        'delegate_level' => $delegateLevel
    );
    $title = $contentData['title'] !== '' ? $contentData['title'] : approval_doc_label($docType);
    $ruleResult = approval_line_rules_build($pdo, $docType, $creatorEmployee, $contentData);
    if (approval_line_rules_requires_manual_team_leader_for_doc($creatorEmployee, $docType) && (!isset($ruleResult['team_lead']) || !is_array($ruleResult['team_lead']))) {
        flash_set('danger', approval_ko('%EA%B3%B5%EC%82%AC%20%EC%9D%B8%EC%9B%90%EC%9D%80%20%ED%98%84%EC%9E%A5%20%ED%8C%80%EC%9E%A5%EC%9D%84%20%EC%84%A0%ED%83%9D%ED%95%B4%20%EC%A3%BC%EC%84%B8%EC%9A%94.'));
        header('Location: ?r=approval_create&type=' . $docType);
        exit;
    }
    $lines = isset($ruleResult['lines']) && is_array($ruleResult['lines']) ? $ruleResult['lines'] : array();
    $contentData['approval_line_messages'] = isset($ruleResult['messages']) && is_array($ruleResult['messages']) ? $ruleResult['messages'] : array();
    $contentData['approval_line_warnings'] = isset($ruleResult['warnings']) && is_array($ruleResult['warnings']) ? $ruleResult['warnings'] : array();
    $contentData['approval_force_ceo_actual'] = isset($ruleResult['force_ceo_actual']) ? (int)$ruleResult['force_ceo_actual'] : 0;
    $contentData['approval_line_preview'] = approval_line_rules_line_names($lines);
}

if ($projectId > 0 && is_array($contentData)) {
    $contentData['project_id'] = $projectId;
}

approval_store_apply_auto_delegation_rules($pdo, $docType, $lines, $creatorEmployee, $creatorEmployeeId, $creatorEmail, $creatorName);

$hasProjectId = cpms_approval_drive_ensure_document_columns($pdo) && approval_store_column_exists($pdo, 'cpms_approval_documents', 'project_id');
$hasCreatorEmail = approval_store_column_exists($pdo, 'cpms_approval_documents', 'created_by_email');
$hasDelegateLevel = approval_store_column_exists($pdo, 'cpms_approval_documents', 'delegate_level');
$hasLineDelegated = approval_store_column_exists($pdo, 'cpms_approval_lines', 'is_delegated');
$hasLineDelegatedBy = approval_store_column_exists($pdo, 'cpms_approval_lines', 'delegated_by_role');
$hasLineReason = approval_store_column_exists($pdo, 'cpms_approval_lines', 'reject_reason');
$hasFileDriveColumns = approval_is_proposal_doc_type($docType) ? cpms_approval_drive_ensure_file_columns($pdo) : false;
$approvalDriveUploadedFiles = array();

try {
    $pdo->beginTransaction();

    $initialDocStatus = 'PENDING';
    $docColumns = array('doc_type', 'title', 'content', 'doc_status', 'current_step_order', 'created_by_id', 'created_by_name', 'created_at', 'updated_at');
    $docValues = array(':t', ':ti', ':c', ':doc_status', '1', ':uid', ':un', 'NOW()', 'NOW()');
    $docParams = array(':t' => $docType, ':ti' => $title, ':c' => json_encode($contentData), ':uid' => $creatorEmployeeId, ':un' => $creatorName);
    $docParams[':doc_status'] = $initialDocStatus;
    if ($hasCreatorEmail) {
        $docColumns[] = 'created_by_email';
        $docValues[] = ':ue';
        $docParams[':ue'] = $creatorEmail;
    }
    if ($hasDelegateLevel) {
        $docColumns[] = 'delegate_level';
        $docValues[] = ':delegate_level';
        $docParams[':delegate_level'] = $delegateLevel;
    }
    if ($hasProjectId && $projectId > 0) {
        $docColumns[] = 'project_id';
        $docValues[] = ':project_id';
        $docParams[':project_id'] = $projectId;
    }
    $sql = "INSERT INTO cpms_approval_documents (" . implode(',', $docColumns) . ") VALUES (" . implode(',', $docValues) . ")";
    $pdo->prepare($sql)->execute($docParams);
    $did = (int)$pdo->lastInsertId();

    $prepared = array();
    for ($i = 0; $i < count($lines); $i++) {
        $line = $lines[$i];
        $emp = $line['emp'];
        $isDelegated = isset($line['delegated']) && (int)$line['delegated'] === 1;
        $isSelfApprover = false;
        $allowSelfApproval = isset($line['allow_self_approval']) && (int)$line['allow_self_approval'] === 1;
        if (!$isDelegated && !$allowSelfApproval) {
            if ($creatorEmployeeId > 0 && (int)$emp['id'] === (int)$creatorEmployeeId) {
                $isSelfApprover = true;
            } else if ($creatorEmail !== '' && isset($emp['email']) && strtolower(trim((string)$emp['email'])) === strtolower($creatorEmail)) {
                $isSelfApprover = true;
            }
        }
        $status = isset($line['status']) ? (string)$line['status'] : ($isDelegated ? 'DELEGATED' : ($isSelfApprover ? 'SKIPPED' : 'WAITING'));
        $prepared[] = array(
            'order' => $i + 1,
            'role' => $line['role'],
            'emp' => $emp,
            'status' => $status,
            'delegated' => $isDelegated ? 1 : 0,
            'delegated_by_role' => isset($line['delegated_by_role']) ? $line['delegated_by_role'] : null,
            'acted_at' => isset($line['acted_at']) ? $line['acted_at'] : null,
            'sign_path' => isset($line['sign_path']) ? $line['sign_path'] : null,
            'auto_reason' => isset($line['auto_reason']) ? $line['auto_reason'] : null
        );
    }

    $first = -1;
    for ($i = 0; $i < count($prepared); $i++) {
        if ($prepared[$i]['status'] === 'WAITING') {
            $first = $i;
            break;
        }
    }
    if ($first >= 0) {
        $prepared[$first]['status'] = 'PENDING';
    }

    for ($i = 0; $i < count($prepared); $i++) {
        $emp = $prepared[$i]['emp'];
        $cols = array('document_id', 'line_order', 'role_type', 'approver_id', 'approver_name', 'approver_email', 'line_status');
        $marks = array(':d', ':o', ':r', ':aid', ':an', ':ae', ':st');
        $params = array(':d' => $did, ':o' => $prepared[$i]['order'], ':r' => $prepared[$i]['role'], ':aid' => $emp['id'], ':an' => $emp['name'], ':ae' => $emp['email'], ':st' => $prepared[$i]['status']);
        if (approval_store_column_exists($pdo, 'cpms_approval_lines', 'acted_at') && !empty($prepared[$i]['acted_at'])) {
            $cols[] = 'acted_at';
            $marks[] = ':acted_at';
            $params[':acted_at'] = $prepared[$i]['acted_at'];
        }
        if (approval_store_column_exists($pdo, 'cpms_approval_lines', 'sign_path') && !empty($prepared[$i]['sign_path'])) {
            $cols[] = 'sign_path';
            $marks[] = ':sign_path';
            $params[':sign_path'] = $prepared[$i]['sign_path'];
        }
        if ($hasLineDelegated) {
            $cols[] = 'is_delegated';
            $marks[] = ':is_delegated';
            $params[':is_delegated'] = $prepared[$i]['delegated'];
        }
        if ($hasLineDelegatedBy) {
            $cols[] = 'delegated_by_role';
            $marks[] = ':delegated_by_role';
            $params[':delegated_by_role'] = $prepared[$i]['delegated_by_role'];
        }
        if ($hasLineReason && trim((string)$prepared[$i]['auto_reason']) !== '') {
            $cols[] = 'reject_reason';
            $marks[] = ':line_reason';
            $params[':line_reason'] = trim((string)$prepared[$i]['auto_reason']);
        }
        $pdo->prepare("INSERT INTO cpms_approval_lines (" . implode(',', $cols) . ") VALUES (" . implode(',', $marks) . ")")->execute($params);
        $insertedLineId = (int)$pdo->lastInsertId();
        if (in_array($prepared[$i]['status'], array('SKIPPED', 'DELEGATED', 'APPROVED'), true)) {
            $logLine = array(
                'role_type' => $prepared[$i]['role'],
                'approver_name' => isset($emp['name']) ? $emp['name'] : '',
                'approver_email' => isset($emp['email']) ? $emp['email'] : ''
            );
            $logNote = trim((string)$prepared[$i]['auto_reason']) !== '' ? approval_auto_delegate_note($logLine, $prepared[$i]['auto_reason']) : approval_auto_delegate_note($logLine, approval_line_status_label($prepared[$i]['status']));
            $pdo->prepare("INSERT INTO cpms_approval_logs (document_id,line_id,actor_id,actor_name,actor_email,action_type,action_note,created_at) VALUES (:d,:l,:a,:n,:e,:type,:m,NOW())")
                ->execute(array(':d' => $did, ':l' => $insertedLineId, ':a' => $creatorEmployeeId, ':n' => $creatorName, ':e' => $creatorEmail, ':type' => $prepared[$i]['status'], ':m' => $logNote));
        }
    }

    if ($first < 0) {
        $pdo->prepare("UPDATE cpms_approval_documents SET doc_status='APPROVED', updated_at=NOW() WHERE id=:id")->execute(array(':id' => $did));
    } else {
        for ($i = 0; $i < count($prepared); $i++) {
            if ($prepared[$i]['status'] === 'PENDING') {
                try {
                    $msg = approval_build_request_message($docType, $title, $creatorName);
                    approval_queue_notification($pdo, $did, 'REQUEST', $prepared[$i]['emp']['id'], $msg);
                } catch (Exception $e) {
                }
                break;
            }
        }
    }

    if (approval_table_exists($pdo, 'cpms_approval_references')) {
        $selectedRefs = isset($_POST['reference_employee_ids']) && is_array($_POST['reference_employee_ids']) ? $_POST['reference_employee_ids'] : array();
        $lineEmployeeIds = array();
        for ($i = 0; $i < count($prepared); $i++) {
            $lineEmployeeIds[] = (int)$prepared[$i]['emp']['id'];
        }
        $seen = array();
        for ($i = 0; $i < count($selectedRefs); $i++) {
            $rid = (int)$selectedRefs[$i];
            if ($rid <= 0 || isset($seen[$rid])) {
                continue;
            }
            $seen[$rid] = 1;
            if ($creatorEmployeeId > 0 && $rid === (int)$creatorEmployeeId) {
                continue;
            }
            if (in_array($rid, $lineEmployeeIds, true)) {
                continue;
            }
            $refEmp = approval_store_employee($pdo, $rid);
            if (!$refEmp) {
                continue;
            }
            $pdo->prepare("INSERT INTO cpms_approval_references (document_id,employee_id,employee_name,employee_email,employee_department,created_at) VALUES (:d,:eid,:en,:ee,:ed,NOW())")
                ->execute(array(':d' => $did, ':eid' => $rid, ':en' => $refEmp['name'], ':ee' => $refEmp['email'], ':ed' => isset($refEmp['department']) ? $refEmp['department'] : null));
        }
    }

    $uploadWarn = array();
    if (approval_is_proposal_doc_type($docType)) {
        $allow = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf');
        $labels = array(
            'order_doc' => array(approval_ko('%EB%B0%9C%EC%A3%BC%EC%84%9C'), 'order_doc_file'),
            'business_license' => array(approval_ko('%EC%82%AC%EC%97%85%EC%9E%90%EB%93%B1%EB%A1%9D%EC%A6%9D'), 'business_license_file'),
            'etc' => array(approval_ko('%EA%B8%B0%ED%83%80'), 'etc_file')
        );
        $root = approval_store_project_root();
        $base = rtrim($root, '/\\') . '/storage/approvals/' . $did . '/files';
        if (!is_dir($base)) {
            @mkdir($base, 0777, true);
        }
        $driveColumnsReady = $hasFileDriveColumns;
        $docForDrive = array(
            'id' => $did,
            'doc_type' => $docType,
            'title' => $title,
            'created_at' => date('Y-m-d H:i:s'),
            'created_by_name' => $creatorName,
            'created_by_email' => $creatorEmail,
            'project_id' => $projectId
        );
        $driveFailUserMessage = approval_ko('%EC%B2%A8%EB%B6%80%ED%8C%8C%EC%9D%BC%20Drive%20%EC%97%85%EB%A1%9C%EB%93%9C%EC%97%90%20%EC%8B%A4%ED%8C%A8%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.%20%EA%B4%80%EB%A6%AC%EC%9E%90%EC%97%90%EA%B2%8C%20%EB%AC%B8%EC%9D%98%ED%95%B4%EC%A3%BC%EC%84%B8%EC%9A%94.');
        foreach ($labels as $ft => $meta) {
            $fname = $meta[1];
            if (!isset($_FILES[$fname]) || !isset($_FILES[$fname]['tmp_name']) || $_FILES[$fname]['tmp_name'] === '') {
                continue;
            }
            if ((int)$_FILES[$fname]['error'] !== UPLOAD_ERR_OK) {
                $uploadWarn[] = $meta[0] . ' ' . approval_store_upload_error_message($_FILES[$fname]['error']);
                continue;
            }
            $orig = (string)$_FILES[$fname]['name'];
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if (!in_array($ext, $allow, true)) {
                $uploadWarn[] = $meta[0] . ' ' . approval_ko('%ED%97%88%EC%9A%A9%EB%90%98%EC%A7%80%20%EC%95%8A%EB%8A%94%20%ED%99%95%EC%9E%A5%EC%9E%90%EC%9E%85%EB%8B%88%EB%8B%A4.');
                continue;
            }
            $saved = $ft . '_' . date('YmdHis') . '_' . mt_rand(1000, 9999) . '.' . $ext;
            $dest = $base . '/' . $saved;
            if (!@move_uploaded_file($_FILES[$fname]['tmp_name'], $dest)) {
                $uploadWarn[] = $meta[0] . ' ' . approval_ko('%EC%A0%80%EC%9E%A5%EC%97%90%20%EC%8B%A4%ED%8C%A8%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.');
                continue;
            }
            $rel = 'storage/approvals/' . $did . '/files/' . $saved;
            $mimeType = cpms_drive_detect_mime_type($dest);
            $fileSize = is_file($dest) ? (int)@filesize($dest) : 0;
            $driveUploadOk = false;
            $driveRecord = cpms_approval_drive_failed_record($orig, $rel, $mimeType, $fileSize, $user, 'Drive upload was not attempted.');
            if ($driveColumnsReady) {
                $driveUpload = cpms_approval_drive_upload_local_file($dest, $orig, $docForDrive, $contentData, array('file_type' => $ft, 'file_label' => $meta[0], 'local_path' => $rel), $user);
                $driveRecord = isset($driveUpload['record']) && is_array($driveUpload['record']) ? $driveUpload['record'] : $driveRecord;
                $driveUploadOk = !empty($driveUpload['ok']);
                if (!$driveUploadOk || !isset($driveRecord['drive_file_id']) || trim((string)$driveRecord['drive_file_id']) === '') {
                    $uploadWarn[] = $meta[0] . ' ' . $driveFailUserMessage;
                }
            } else {
                cpms_drive_log_upload_failure(array(
                    'user' => $user,
                    'section' => 'approval',
                    'approval_document_id' => $did,
                    'document_type' => approval_doc_label($docType),
                    'project_id' => $projectId > 0 ? $projectId : '',
                    'original_name' => $orig,
                    'target_folder_id' => cpms_drive_folder_id('approval'),
                    'message' => 'Approval file Drive columns are not ready.'
                ));
                $uploadWarn[] = $meta[0] . ' ' . $driveFailUserMessage;
            }
            $fileRow = array_merge($driveRecord, array(
                'document_id' => $did,
                'original_name' => $orig,
                'saved_name' => $saved,
                'file_path' => $rel,
                'file_label' => $meta[0],
                'file_type' => $ft
            ));
            $saveFile = cpms_approval_drive_save_file_row($pdo, $fileRow);
            if (empty($saveFile['ok'])) {
                if ($driveUploadOk && isset($driveRecord['drive_file_id']) && trim((string)$driveRecord['drive_file_id']) !== '') {
                    cpms_drive_delete_file((string)$driveRecord['drive_file_id'], array(
                        'user' => $user,
                        'section' => 'approval_db_save_cleanup',
                        'approval_document_id' => $did,
                        'document_type' => isset($driveRecord['document_type']) ? (string)$driveRecord['document_type'] : approval_doc_label($docType),
                        'project_id' => $projectId > 0 ? $projectId : '',
                        'original_name' => $orig,
                        'target_folder_id' => isset($driveRecord['drive_folder_id']) ? (string)$driveRecord['drive_folder_id'] : ''
                    ));
                }
                $uploadWarn[] = $meta[0] . ' ' . approval_ko('%ED%8C%8C%EC%9D%BC%20%EC%A0%95%EB%B3%B4%20%EC%A0%80%EC%9E%A5%20%EC%8B%A4%ED%8C%A8');
            } else if ($driveUploadOk && isset($driveRecord['drive_file_id']) && trim((string)$driveRecord['drive_file_id']) !== '') {
                $approvalDriveUploadedFiles[] = array(
                    'id' => (string)$driveRecord['drive_file_id'],
                    'original_name' => $orig,
                    'target_folder_id' => isset($driveRecord['drive_folder_id']) ? (string)$driveRecord['drive_folder_id'] : '',
                    'document_type' => isset($driveRecord['document_type']) ? (string)$driveRecord['document_type'] : ''
                );
            }
        }
    }

    $pdo->commit();
    if (count($uploadWarn) > 0) {
        flash_set('danger', implode(', ', $uploadWarn));
    }
    header('Location: ?r=approval_detail&id=' . $did);
    exit;
} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (is_array($approvalDriveUploadedFiles)) {
        for ($i = 0; $i < count($approvalDriveUploadedFiles); $i++) {
            $driveFile = $approvalDriveUploadedFiles[$i];
            if (!is_array($driveFile) || !isset($driveFile['id']) || trim((string)$driveFile['id']) === '') {
                continue;
            }
            cpms_drive_delete_file((string)$driveFile['id'], array(
                'user' => $user,
                'section' => 'approval_store_rollback_cleanup',
                'approval_document_id' => isset($did) ? (int)$did : 0,
                'document_type' => isset($driveFile['document_type']) ? (string)$driveFile['document_type'] : approval_doc_label($docType),
                'project_id' => $projectId > 0 ? $projectId : '',
                'original_name' => isset($driveFile['original_name']) ? (string)$driveFile['original_name'] : '',
                'target_folder_id' => isset($driveFile['target_folder_id']) ? (string)$driveFile['target_folder_id'] : '',
                'message' => 'Approval DB save failed after Drive upload; cleanup attempted.'
            ));
        }
    }
    error_log('[approval_store] ' . $e->getMessage());
    flash_set('danger', approval_ko('%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%20%EC%A0%80%EC%9E%A5%20%EC%A4%91%20%EC%98%A4%EB%A5%98%EA%B0%80%20%EB%B0%9C%EC%83%9D%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.%20%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%20DB%20%EC%84%A4%EC%B9%98%2F%ED%99%95%EC%9D%B8%EC%9D%84%20%EB%A8%BC%EC%A0%80%20%EC%8B%A4%ED%96%89%ED%95%B4%EC%A3%BC%EC%84%B8%EC%9A%94.'));
    header('Location: ?r=approval_create&type=' . $docType);
    exit;
}
