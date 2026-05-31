<?php
use App\Core\Db;

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/template_helpers.php';
require_once __DIR__ . '/notification_helpers.php';

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
        $st = $pdo->prepare("SELECT id,name,email,department,position," . $hireColumn . " FROM employees WHERE id=:id AND is_active=1 LIMIT 1");
        $st->execute(array(':id' => (int)$id));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? $row : null;
    }
}

if (!function_exists('approval_store_employee_by_name')) {
    function approval_store_employee_by_name($pdo, $name)
    {
        $st = $pdo->prepare("SELECT id,name,email,department,position FROM employees WHERE name=:name AND is_active=1 LIMIT 1");
        $st->execute(array(':name' => $name));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? $row : null;
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

$creatorEmployeeId = approval_current_employee_id($pdo, $user);
$creatorName = approval_current_user_name($user);
$creatorEmail = approval_current_user_email($user);
$docType = isset($_POST['doc_type']) ? trim((string)$_POST['doc_type']) : 'proposal';
if (!in_array($docType, array('proposal', 'leave', 'unused_leave_notice', 'unused_leave_plan'), true)) {
    $docType = 'proposal';
}
$isManagementOnlyDoc = approval_is_management_only_doc_type($docType);
if ($isManagementOnlyDoc && !approval_is_management_department_user($pdo, $user)) {
    flash_set('danger', approval_ko('%EA%B4%80%EB%A6%AC%EB%B6%80%EB%A7%8C%20%EC%9E%91%EC%84%B1%ED%95%A0%20%EC%88%98%20%EC%9E%88%EB%8A%94%20%EB%AC%B8%EC%84%9C%EC%9E%85%EB%8B%88%EB%8B%A4.'));
    header('Location: ?r=approval_home&view=active');
    exit;
}

$vpRole = approval_ko('%EB%B6%80%EC%82%AC%EC%9E%A5');
$ceoRole = approval_ko('%EB%8C%80%ED%91%9C%EC%9D%B4%EC%82%AC');
$siteRole = approval_ko('%EC%86%8C%EC%9E%A5');
$teamRole = approval_ko('%ED%8C%80%EC%9E%A5');
$gongmuRole = approval_ko('%EA%B3%B5%EB%AC%B4');
$manageRole = approval_ko('%EA%B4%80%EB%A6%AC');
$pmRole = 'PM';
$parkName = approval_ko('%EB%B0%95%EC%9B%90%EB%8D%95');
$goName = approval_ko('%EA%B3%A0%EC%98%81%EC%84%B1');

$vp = approval_store_employee_by_name($pdo, $vpRole);
if (!$vp) {
    $st = $pdo->prepare("SELECT id,name,email,department,position FROM employees WHERE is_active=1 AND position=:pos LIMIT 1");
    $st->execute(array(':pos' => $vpRole));
    $vp = $st->fetch(PDO::FETCH_ASSOC);
}
$ceo = null;
$st = $pdo->prepare("SELECT id,name,email,department,position FROM employees WHERE is_active=1 AND (position=:p1 OR position=:p2) LIMIT 1");
$st->execute(array(':p1' => $ceoRole, ':p2' => approval_ko('%EB%8C%80%ED%91%9C')));
$ceo = $st->fetch(PDO::FETCH_ASSOC);
if (!$isManagementOnlyDoc && (!$vp || !$ceo)) {
    flash_set('danger', approval_ko('%EC%A7%81%EC%9B%90%EB%AA%85%EB%B6%80%EC%97%90%EC%84%9C%20%EB%B6%80%EC%82%AC%EC%9E%A5%20%EB%98%90%EB%8A%94%20%EB%8C%80%ED%91%9C%EC%9D%B4%EC%82%AC%EA%B0%80%20%ED%99%95%EC%9D%B8%EB%90%98%EC%A7%80%20%EC%95%8A%EC%8A%B5%EB%8B%88%EB%8B%A4.'));
    header('Location: ?r=approval_create&type=' . $docType);
    exit;
}

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
        $contentData['notice_message'] = approval_store_build_notice_message($snapshot);
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
    $leadId = isset($_POST['team_lead_id']) ? (int)$_POST['team_lead_id'] : 0;
    $lead = approval_store_employee($pdo, $leadId);
    if (!$lead) {
        flash_set('danger', approval_ko('%ED%8C%80%EC%9E%A5%20%EA%B2%B0%EC%9E%AC%EC%9E%90%EB%A5%BC%20%EC%84%A0%ED%83%9D%ED%95%B4%EC%A3%BC%EC%84%B8%EC%9A%94.'));
        header('Location: ?r=approval_create&type=leave');
        exit;
    }
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
    $leavePm = null;
    if ($normDept === approval_ko('%EA%B3%B5%EC%82%AC') || $normDept === approval_ko('%EC%95%88%EC%A0%84')) {
        $leavePm = approval_store_employee_by_name($pdo, $parkName);
    } else if ($normDept === approval_ko('%EA%B3%B5%EB%AC%B4')) {
        $leavePm = approval_store_employee_by_name($pdo, $goName);
    }
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
        'delegate_level' => 'ceo'
    );
    $title = approval_doc_label('leave') . ' - ' . $contentData['applicant_name'];
    $lines[] = array('role' => $teamRole, 'emp' => $lead, 'delegated' => 0);
    if ($leavePm) {
        $lines[] = array('role' => $pmRole, 'emp' => $leavePm, 'delegated' => 0);
    }
    $lines[] = array('role' => $vpRole, 'emp' => $vp, 'delegated' => 0);
    $lines[] = array('role' => $ceoRole, 'emp' => $ceo, 'delegated' => 1, 'delegated_by_role' => $vpRole);
    $delegateLevel = 'ceo';
} else {
    $sojang = approval_store_employee($pdo, isset($_POST['sojang_id']) ? (int)$_POST['sojang_id'] : 0);
    $pm = approval_store_employee($pdo, isset($_POST['pm_id']) ? (int)$_POST['pm_id'] : 0);
    $gongmu = approval_store_employee($pdo, isset($_POST['gongmu_id']) ? (int)$_POST['gongmu_id'] : 0);
    $manage = approval_store_employee($pdo, isset($_POST['manage_id']) ? (int)$_POST['manage_id'] : 0);
    if (!$sojang || !$gongmu || !$manage) {
        flash_set('danger', approval_ko('%EC%86%8C%EC%9E%A5%2F%EA%B3%B5%EB%AC%B4%2F%EA%B4%80%EB%A6%AC%20%EA%B2%B0%EC%9E%AC%EC%9E%90%EB%A5%BC%20%EC%84%A0%ED%83%9D%ED%95%B4%EC%A3%BC%EC%84%B8%EC%9A%94.'));
        header('Location: ?r=approval_create&type=proposal');
        exit;
    }
    $delegateLevel = isset($_POST['delegate_level']) ? trim((string)$_POST['delegate_level']) : 'none';
    if (!in_array($delegateLevel, array('none', 'vp', 'ceo'), true)) {
        $delegateLevel = 'none';
    }
    $contentData = array(
        'draft_date' => isset($_POST['draft_date']) ? trim((string)$_POST['draft_date']) : '',
        'effective_date' => isset($_POST['effective_date']) ? trim((string)$_POST['effective_date']) : '',
        'draft_department' => isset($_POST['draft_department']) ? trim((string)$_POST['draft_department']) : '',
        'drafter_name' => isset($_POST['drafter_name']) ? trim((string)$_POST['drafter_name']) : $creatorName,
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
        'writer_name' => isset($_POST['drafter_name']) ? trim((string)$_POST['drafter_name']) : $creatorName,
        'writer_email' => $creatorEmail,
        'delegate_level' => $delegateLevel
    );
    $title = $contentData['title'] !== '' ? $contentData['title'] : approval_doc_label('proposal');
    $lines[] = array('role' => $siteRole, 'emp' => $sojang, 'delegated' => 0);
    if ($pm) {
        $lines[] = array('role' => $pmRole, 'emp' => $pm, 'delegated' => 0);
    }
    $lines[] = array('role' => $gongmuRole, 'emp' => $gongmu, 'delegated' => 0);
    $lines[] = array('role' => $manageRole, 'emp' => $manage, 'delegated' => 0);
    $lines[] = array('role' => $vpRole, 'emp' => $vp, 'delegated' => ($delegateLevel === 'vp') ? 1 : 0, 'delegated_by_role' => $manageRole);
    $lines[] = array('role' => $ceoRole, 'emp' => $ceo, 'delegated' => ($delegateLevel === 'vp' || $delegateLevel === 'ceo') ? 1 : 0, 'delegated_by_role' => ($delegateLevel === 'vp' ? $manageRole : $vpRole));
}

$hasCreatorEmail = approval_store_column_exists($pdo, 'cpms_approval_documents', 'created_by_email');
$hasDelegateLevel = approval_store_column_exists($pdo, 'cpms_approval_documents', 'delegate_level');
$hasLineDelegated = approval_store_column_exists($pdo, 'cpms_approval_lines', 'is_delegated');
$hasLineDelegatedBy = approval_store_column_exists($pdo, 'cpms_approval_lines', 'delegated_by_role');

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
    $sql = "INSERT INTO cpms_approval_documents (" . implode(',', $docColumns) . ") VALUES (" . implode(',', $docValues) . ")";
    $pdo->prepare($sql)->execute($docParams);
    $did = (int)$pdo->lastInsertId();

    $prepared = array();
    for ($i = 0; $i < count($lines); $i++) {
        $line = $lines[$i];
        $emp = $line['emp'];
        $isDelegated = isset($line['delegated']) && (int)$line['delegated'] === 1;
        if ($docType === 'leave' && isset($line['role']) && (string)$line['role'] === $ceoRole) {
            $isDelegated = true;
        }
        $isSelfApprover = false;
        if (!$isDelegated) {
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
            'sign_path' => isset($line['sign_path']) ? $line['sign_path'] : null
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
        $pdo->prepare("INSERT INTO cpms_approval_lines (" . implode(',', $cols) . ") VALUES (" . implode(',', $marks) . ")")->execute($params);
        if (in_array($prepared[$i]['status'], array('SKIPPED', 'DELEGATED', 'APPROVED'), true)) {
            $pdo->prepare("INSERT INTO cpms_approval_logs (document_id,line_id,actor_id,actor_name,actor_email,action_type,action_note,created_at) VALUES (:d,NULL,:a,:n,:e,:type,:m,NOW())")
                ->execute(array(':d' => $did, ':a' => $creatorEmployeeId, ':n' => $creatorName, ':e' => $creatorEmail, ':type' => $prepared[$i]['status'], ':m' => approval_line_status_label($prepared[$i]['status'])));
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
    if ($docType === 'proposal') {
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
            $pdo->prepare("INSERT INTO cpms_approval_files (document_id,original_name,saved_name,file_path,file_label,file_type,created_at) VALUES (?,?,?,?,?,?,NOW())")
                ->execute(array($did, $orig, $saved, $rel, $meta[0], $ft));
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
    error_log('[approval_store] ' . $e->getMessage());
    flash_set('danger', approval_ko('%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%20%EC%A0%80%EC%9E%A5%20%EC%A4%91%20%EC%98%A4%EB%A5%98%EA%B0%80%20%EB%B0%9C%EC%83%9D%ED%96%88%EC%8A%B5%EB%8B%88%EB%8B%A4.%20%EC%A0%84%EC%9E%90%EA%B2%B0%EC%9E%AC%20DB%20%EC%84%A4%EC%B9%98%2F%ED%99%95%EC%9D%B8%EC%9D%84%20%EB%A8%BC%EC%A0%80%20%EC%8B%A4%ED%96%89%ED%95%B4%EC%A3%BC%EC%84%B8%EC%9A%94.'));
    header('Location: ?r=approval_create&type=' . $docType);
    exit;
}
