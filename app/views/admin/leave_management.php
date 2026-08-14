<?php
use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/leave_management_helpers.php';
require_once __DIR__ . '/../../services/ApprovalPdfService.php';

if (!function_exists('cpms_leave_empty_usage_bucket')) {
function cpms_leave_empty_usage_bucket()
{
    return array(
        'annual' => 0.0,
        'monthly' => 0.0
    );
}}

if (!function_exists('cpms_leave_render_usage_html')) {
function cpms_leave_render_usage_html($usage)
{
    $annual = 0.0;
    $monthly = 0.0;
    if (is_array($usage)) {
        $annual = isset($usage['annual']) ? cpms_leave_normalize_half_step($usage['annual']) : 0.0;
        $monthly = isset($usage['monthly']) ? cpms_leave_normalize_half_step($usage['monthly']) : 0.0;
    }

    $parts = array();
    if ($monthly > 0) {
        $parts[count($parts)] = '<span class="leave-use-monthly">' . h(cpms_leave_format_decimal($monthly)) . '</span>';
    }
    if ($annual > 0) {
        $parts[count($parts)] = '<span class="leave-use-annual">' . h(cpms_leave_format_decimal($annual)) . '</span>';
    }
    if (count($parts) === 0) {
        return '';
    }
    return implode('<span class="leave-use-divider"> / </span>', $parts);
}}

if (!function_exists('cpms_leave_history_content_value')) {
function cpms_leave_history_content_value($content, $key)
{
    if (!is_array($content) || !isset($content[$key]) || !is_scalar($content[$key])) {
        return '';
    }
    return trim((string)$content[$key]);
}}

if (!function_exists('cpms_leave_history_type_label')) {
function cpms_leave_history_type_label($content)
{
    $requestType = cpms_leave_history_content_value($content, 'request_type');
    $requestTypeEtc = cpms_leave_history_content_value($content, 'request_type_etc');
    if ($requestType === '기타' && $requestTypeEtc !== '') {
        return '기타 (' . $requestTypeEtc . ')';
    }
    return $requestType !== '' ? $requestType : '휴가';
}}

if (!function_exists('cpms_leave_history_use_days')) {
function cpms_leave_history_use_days($content, $deductDays)
{
    if ($deductDays !== null && $deductDays !== '' && is_numeric($deductDays) && (float)$deductDays > 0) {
        return cpms_leave_normalize_half_step($deductDays);
    }
    $requestType = cpms_leave_history_content_value($content, 'request_type');
    if (strpos($requestType, '반차') !== false) {
        return 0.5;
    }
    $leaveDays = str_replace(',', '', cpms_leave_history_content_value($content, 'leave_days'));
    if ($leaveDays !== '' && is_numeric($leaveDays)) {
        return cpms_leave_normalize_half_step($leaveDays);
    }
    return 0.0;
}}

if (!function_exists('cpms_leave_history_sort_rows')) {
function cpms_leave_history_sort_rows($left, $right)
{
    $leftStart = isset($left['leave_start_date']) ? (string)$left['leave_start_date'] : '';
    $rightStart = isset($right['leave_start_date']) ? (string)$right['leave_start_date'] : '';
    if ($leftStart !== $rightStart) {
        return strcmp($rightStart, $leftStart);
    }
    $leftId = isset($left['document_id']) ? (int)$left['document_id'] : 0;
    $rightId = isset($right['document_id']) ? (int)$right['document_id'] : 0;
    if ($leftId === $rightId) {
        return 0;
    }
    return $leftId < $rightId ? 1 : -1;
}}

$pdo = Db::pdo();
$user = Auth::user();

if (!$pdo) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">DB 연결에 실패했습니다.</div>';
    return;
}

if (!cpms_leave_can_access_management($pdo, $user)) {
    echo '<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 font-bold">접근 권한이 없습니다. 관리팀 전용 화면입니다.</div>';
    return;
}

$today = date('Y-m-d');
$currentYear = (int)date('Y');
$year = isset($_GET['year']) ? (int)$_GET['year'] : $currentYear;
if ($year < 2000 || $year > 2100) {
    $year = $currentYear;
}
$yearEnd = sprintf('%04d-12-31', $year);
$displayBaseDate = ($year === $currentYear) ? $today : $yearEnd;

$deptFilter = isset($_GET['department']) ? trim((string)$_GET['department']) : '';
$statusFilter = isset($_GET['status']) ? trim((string)$_GET['status']) : 'active';
if (!in_array($statusFilter, array('active', 'resigned', 'all'), true)) {
    $statusFilter = 'active';
}
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$leaveView = isset($_GET['leave_view']) ? trim((string)$_GET['leave_view']) : 'status';
if (!in_array($leaveView, array('status', 'history'), true)) {
    $leaveView = 'status';
}
// 기존 PDF 복구가 다시 필요할 때 true로 변경하면 일괄 재생성 UI가 표시됩니다.
$showLeavePdfRebuildUi = false;
$usageMonth = isset($_GET['usage_month']) ? (int)$_GET['usage_month'] : (int)date('n');
if ($usageMonth < 1 || $usageMonth > 12) {
    $usageMonth = (int)date('n');
}
$usageMonthStart = sprintf('%04d-%02d-01', $year, $usageMonth);
$usageMonthEnd = date('Y-m-t', strtotime($usageMonthStart));

$accrualStats = cpms_leave_apply_accruals_until($pdo, $today);

$hasPosition = cpms_leave_column_exists($pdo, 'employees', 'position');
$hasHireDate = cpms_leave_column_exists($pdo, 'employees', 'hire_date');
$hasResignDate = cpms_leave_column_exists($pdo, 'employees', 'resign_date');
$hasMonthlyBalance = cpms_leave_column_exists($pdo, 'employees', 'leave_monthly_balance');
$hasAnnualBalance = cpms_leave_column_exists($pdo, 'employees', 'leave_annual_balance');
$hasHalfBalance = cpms_leave_column_exists($pdo, 'employees', 'leave_half_balance');
$hasAccrualLogs = cpms_leave_table_exists($pdo, 'cpms_leave_accrual_logs');
$hasLeaveDeductions = cpms_leave_table_exists($pdo, 'cpms_approval_leave_deductions')
    && cpms_leave_column_exists($pdo, 'cpms_approval_leave_deductions', 'employee_id')
    && cpms_leave_column_exists($pdo, 'cpms_approval_leave_deductions', 'leave_bucket')
    && cpms_leave_column_exists($pdo, 'cpms_approval_leave_deductions', 'deduct_amount')
    && cpms_leave_column_exists($pdo, 'cpms_approval_leave_deductions', 'deducted_at')
    && cpms_leave_column_exists($pdo, 'cpms_approval_leave_deductions', 'document_id');
$hasApprovalDocuments = cpms_leave_table_exists($pdo, 'cpms_approval_documents')
    && cpms_leave_column_exists($pdo, 'cpms_approval_documents', 'doc_type')
    && cpms_leave_column_exists($pdo, 'cpms_approval_documents', 'doc_status')
    && cpms_leave_column_exists($pdo, 'cpms_approval_documents', 'content');
$hasApprovalPdfFileId = $hasApprovalDocuments && cpms_leave_column_exists($pdo, 'cpms_approval_documents', 'completed_pdf_drive_file_id');
$hasApprovalPdfStatus = $hasApprovalDocuments && cpms_leave_column_exists($pdo, 'cpms_approval_documents', 'completed_pdf_upload_status');
$hasApprovalPdfVersion = $hasApprovalDocuments && cpms_leave_column_exists($pdo, 'cpms_approval_documents', 'completed_pdf_render_version');
$hasAdjustments = cpms_leave_table_exists($pdo, 'cpms_leave_adjustments')
    && cpms_leave_column_exists($pdo, 'cpms_leave_adjustments', 'target_year')
    && cpms_leave_column_exists($pdo, 'cpms_leave_adjustments', 'adjust_type');

$schemaWarnings = array();
if (!$hasHireDate) $schemaWarnings[count($schemaWarnings)] = 'employees.hire_date';
if (!$hasMonthlyBalance) $schemaWarnings[count($schemaWarnings)] = 'employees.leave_monthly_balance';
if (!$hasAnnualBalance) $schemaWarnings[count($schemaWarnings)] = 'employees.leave_annual_balance';
if (!$hasHalfBalance) $schemaWarnings[count($schemaWarnings)] = 'employees.leave_half_balance';
if (!$hasAccrualLogs) $schemaWarnings[count($schemaWarnings)] = 'cpms_leave_accrual_logs';
if (!$hasLeaveDeductions) $schemaWarnings[count($schemaWarnings)] = 'cpms_approval_leave_deductions';
if (!$hasApprovalDocuments) $schemaWarnings[count($schemaWarnings)] = 'cpms_approval_documents';

$departmentOptions = array();
try {
    $deptRows = $pdo->query("SELECT DISTINCT department FROM employees WHERE department IS NOT NULL AND department<>'' ORDER BY department ASC")->fetchAll(PDO::FETCH_ASSOC);
    if (is_array($deptRows)) {
        for ($i = 0; $i < count($deptRows); $i++) {
            if (isset($deptRows[$i]['department']) && trim((string)$deptRows[$i]['department']) !== '') {
                $departmentOptions[count($departmentOptions)] = trim((string)$deptRows[$i]['department']);
            }
        }
    }
} catch (Exception $e) {
    $departmentOptions = array();
}

$employees = array();
$employeeLoadError = '';
try {
    $positionSelect = $hasPosition ? 'position' : "'' AS position";
    $hireSelect = $hasHireDate ? 'hire_date' : 'NULL AS hire_date';
    $resignSelect = $hasResignDate ? 'resign_date' : 'NULL AS resign_date';
    $monthlySelect = $hasMonthlyBalance ? 'leave_monthly_balance' : 'NULL AS leave_monthly_balance';
    $annualSelect = $hasAnnualBalance ? 'leave_annual_balance' : 'NULL AS leave_annual_balance';
    $halfSelect = $hasHalfBalance ? 'leave_half_balance' : 'NULL AS leave_half_balance';

    $sql = "SELECT id,name,department," . $positionSelect . "," . $hireSelect . "," . $resignSelect . "," . $monthlySelect . "," . $annualSelect . "," . $halfSelect . ",is_active FROM employees WHERE 1=1";
    $params = array();
    if ($deptFilter !== '') {
        $sql .= " AND department=:department";
        $params[':department'] = $deptFilter;
    }
    if ($statusFilter === 'active') {
        $sql .= " AND is_active=1";
    } else if ($statusFilter === 'resigned') {
        if ($hasResignDate) {
            $sql .= " AND (is_active=0 OR (resign_date IS NOT NULL AND resign_date<>'' AND resign_date<=:year_end_status))";
            $params[':year_end_status'] = $yearEnd;
        } else {
            $sql .= " AND is_active=0";
        }
    }
    if ($q !== '') {
        $sql .= " AND (name LIKE :q OR department LIKE :q";
        if ($hasPosition) {
            $sql .= " OR position LIKE :q";
        }
        $sql .= ")";
        $params[':q'] = '%' . $q . '%';
    }
    $sql .= " ORDER BY is_active DESC, department ASC, name ASC, id ASC";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $employees = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($employees)) {
        $employees = array();
    }
} catch (Exception $e) {
    $employees = array();
    $employeeLoadError = $e->getMessage();
}

$monthlyUsage = array();
$usageTotals = array();
if ($hasLeaveDeductions && cpms_leave_table_exists($pdo, 'cpms_approval_documents')) {
    try {
        $sql = "SELECT ld.employee_id,ld.leave_type,ld.leave_bucket,ld.target_column,ld.deduct_amount,ld.deducted_at,ld.document_id,d.content
                  FROM cpms_approval_leave_deductions ld
                  INNER JOIN cpms_approval_documents d ON d.id=ld.document_id
                 WHERE d.doc_type='leave'
                   AND UPPER(COALESCE(d.doc_status,'')) IN ('APPROVED','COMPLETED')";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = array();
        }
        for ($i = 0; $i < count($rows); $i++) {
            $row = $rows[$i];
            $employeeId = isset($row['employee_id']) ? (int)$row['employee_id'] : 0;
            if ($employeeId <= 0) {
                continue;
            }

            $content = array();
            $raw = isset($row['content']) ? trim((string)$row['content']) : '';
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $content = $decoded;
                }
            }

            $usageDate = isset($content['leave_start_date']) ? cpms_leave_parse_date($content['leave_start_date']) : '';
            if ($usageDate === '') {
                $usageDate = isset($row['deducted_at']) ? cpms_leave_parse_date($row['deducted_at']) : '';
            }
            if ($usageDate === '' || (int)date('Y', strtotime($usageDate)) !== $year) {
                continue;
            }

            $bucket = isset($row['leave_bucket']) ? strtoupper(trim((string)$row['leave_bucket'])) : '';
            if ($bucket === '') {
                $targetColumn = isset($row['target_column']) ? trim((string)$row['target_column']) : '';
                if ($targetColumn === 'leave_annual_balance') {
                    $bucket = 'ANNUAL';
                } else if ($targetColumn === 'leave_monthly_balance') {
                    $bucket = 'MONTHLY';
                }
            }
            if ($bucket !== 'ANNUAL' && $bucket !== 'MONTHLY') {
                continue;
            }

            $amount = cpms_leave_use_amount_from_document($content, isset($row['deduct_amount']) ? $row['deduct_amount'] : null);
            if ($amount <= 0) {
                continue;
            }

            $month = (int)date('n', strtotime($usageDate));
            if (!isset($monthlyUsage[$employeeId])) {
                $monthlyUsage[$employeeId] = array();
            }
            if (!isset($monthlyUsage[$employeeId][$month])) {
                $monthlyUsage[$employeeId][$month] = cpms_leave_empty_usage_bucket();
            }
            if (!isset($usageTotals[$employeeId])) {
                $usageTotals[$employeeId] = array(
                    'annual' => 0.0,
                    'monthly' => 0.0,
                    'total' => 0.0
                );
            }

            $usageKey = ($bucket === 'ANNUAL') ? 'annual' : 'monthly';
            $monthlyUsage[$employeeId][$month][$usageKey] += (float)$amount;
            $usageTotals[$employeeId][$usageKey] += (float)$amount;
            $usageTotals[$employeeId]['total'] += (float)$amount;
        }
    } catch (Exception $e) {
        $monthlyUsage = array();
        $usageTotals = array();
    }
}

$adjustments = array();
if ($hasAdjustments) {
    try {
        $st = $pdo->prepare("SELECT employee_id,adjust_type,COALESCE(SUM(amount),0) AS amount FROM cpms_leave_adjustments WHERE target_year=:y GROUP BY employee_id,adjust_type");
        $st->execute(array(':y' => $year));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = array();
        }
        for ($i = 0; $i < count($rows); $i++) {
            $employeeId = isset($rows[$i]['employee_id']) ? (int)$rows[$i]['employee_id'] : 0;
            $adjustType = isset($rows[$i]['adjust_type']) ? strtoupper(trim((string)$rows[$i]['adjust_type'])) : '';
            if ($employeeId <= 0 || ($adjustType !== 'ADD' && $adjustType !== 'DEDUCT')) {
                continue;
            }
            if (!isset($adjustments[$employeeId])) {
                $adjustments[$employeeId] = array(
                    'ADD' => 0.0,
                    'DEDUCT' => 0.0
                );
            }
            $adjustments[$employeeId][$adjustType] += (float)$rows[$i]['amount'];
        }
    } catch (Exception $e) {
        $adjustments = array();
    }
}

$annualRows = array();
$monthlyRows = array();
for ($i = 0; $i < count($employees); $i++) {
    $emp = $employees[$i];
    $employeeId = isset($emp['id']) ? (int)$emp['id'] : 0;
    if ($employeeId > 0) {
        $normalized = cpms_leave_normalize_employee_balances($pdo, $employeeId);
        if ($normalized['leave_annual_balance'] !== null) {
            $emp['leave_annual_balance'] = $normalized['leave_annual_balance'];
        }
        if ($normalized['leave_monthly_balance'] !== null) {
            $emp['leave_monthly_balance'] = $normalized['leave_monthly_balance'];
        }
        if ($normalized['leave_half_balance'] !== null) {
            $emp['leave_half_balance'] = $normalized['leave_half_balance'];
        }
    }
    $hireDate = isset($emp['hire_date']) ? cpms_leave_parse_date($emp['hire_date']) : '';
    $resignDate = isset($emp['resign_date']) ? cpms_leave_parse_date($emp['resign_date']) : '';
    $rowBaseDate = $displayBaseDate;
    if ($resignDate !== '' && strcmp($resignDate, $rowBaseDate) < 0) {
        $rowBaseDate = $resignDate;
    }

    $serviceMonths = ($hireDate !== '') ? cpms_leave_months_of_service($hireDate, $rowBaseDate) : 0;
    $annualUsed = (isset($usageTotals[$employeeId]) && isset($usageTotals[$employeeId]['annual'])) ? (float)$usageTotals[$employeeId]['annual'] : 0.0;
    $monthlyUsed = (isset($usageTotals[$employeeId]) && isset($usageTotals[$employeeId]['monthly'])) ? (float)$usageTotals[$employeeId]['monthly'] : 0.0;
    $annualUsed = cpms_leave_normalize_half_step($annualUsed);
    $monthlyUsed = cpms_leave_normalize_half_step($monthlyUsed);
    $currentAnnualBalance = (isset($emp['leave_annual_balance']) && $emp['leave_annual_balance'] !== null && $emp['leave_annual_balance'] !== '') ? cpms_leave_normalize_half_step($emp['leave_annual_balance']) : 0.0;
    $currentMonthlyBalance = (isset($emp['leave_monthly_balance']) && $emp['leave_monthly_balance'] !== null && $emp['leave_monthly_balance'] !== '') ? cpms_leave_normalize_half_step($emp['leave_monthly_balance']) : 0.0;

    $annualTotal = 0.0;
    if ($year === $currentYear) {
        $annualTotal = $currentAnnualBalance + $annualUsed;
    } else if ($hireDate !== '') {
        $annualTotal = cpms_leave_annual_entitlement_for_year($hireDate, $year);
        if (isset($adjustments[$employeeId])) {
            $annualTotal += isset($adjustments[$employeeId]['ADD']) ? (float)$adjustments[$employeeId]['ADD'] : 0.0;
            $annualTotal -= isset($adjustments[$employeeId]['DEDUCT']) ? (float)$adjustments[$employeeId]['DEDUCT'] : 0.0;
        }
    }

    $monthlyTotal = 0.0;
    if ($year === $currentYear) {
        $monthlyTotal = $currentMonthlyBalance + $monthlyUsed;
    } else if ($hireDate !== '') {
        $monthlyTotal = cpms_leave_monthly_accrual_count_for_year($hireDate, $year, $rowBaseDate);
    }
    $annualTotal = cpms_leave_normalize_half_step($annualTotal);
    $monthlyTotal = cpms_leave_normalize_half_step($monthlyTotal);

    $row = array(
        'employee' => $emp,
        'hire_date' => $hireDate,
        'service_months' => $serviceMonths,
        'annual_total' => $annualTotal,
        'monthly_total' => $monthlyTotal,
        'annual_balance' => $currentAnnualBalance,
        'monthly_balance' => $currentMonthlyBalance,
        'annual_used' => $annualUsed,
        'monthly_used' => $monthlyUsed
    );

    if ($hireDate !== '' && cpms_leave_is_annual_employee($hireDate, $rowBaseDate)) {
        $annualRows[count($annualRows)] = $row;
    } else {
        $monthlyRows[count($monthlyRows)] = $row;
    }
}

$historyRows = array();
$historyLoadError = '';
$historyEmployeeMap = array();
for ($i = 0; $i < count($employees); $i++) {
    $historyEmployeeId = isset($employees[$i]['id']) ? (int)$employees[$i]['id'] : 0;
    if ($historyEmployeeId > 0) {
        $historyEmployeeMap[$historyEmployeeId] = $employees[$i];
    }
}

if ($leaveView === 'history' && $hasApprovalDocuments && count($historyEmployeeMap) > 0) {
    try {
        $historyHasCreatorId = cpms_leave_column_exists($pdo, 'cpms_approval_documents', 'created_by_id');
        $historyHasCreatorName = cpms_leave_column_exists($pdo, 'cpms_approval_documents', 'created_by_name');
        $historyCreatorIdSelect = $historyHasCreatorId ? 'd.created_by_id' : '0 AS created_by_id';
        $historyCreatorNameSelect = $historyHasCreatorName ? 'd.created_by_name' : "'' AS created_by_name";
        $historyPdfFileIdSelect = $hasApprovalPdfFileId ? 'd.completed_pdf_drive_file_id' : "'' AS completed_pdf_drive_file_id";
        $historyPdfStatusSelect = $hasApprovalPdfStatus ? 'd.completed_pdf_upload_status' : "'' AS completed_pdf_upload_status";
        $historyPdfVersionSelect = $hasApprovalPdfVersion ? 'd.completed_pdf_render_version' : '0 AS completed_pdf_render_version';
        $historyDeductionSelect = 'NULL AS deducted_days';
        $historyDeductionJoin = '';
        if ($hasLeaveDeductions) {
            $historyDeductionSelect = 'ld.deducted_days';
            $historyDeductionJoin = " LEFT JOIN (SELECT document_id,SUM(deduct_amount) AS deducted_days FROM cpms_approval_leave_deductions GROUP BY document_id) ld ON ld.document_id=d.id";
        }
        $historySql = "SELECT d.id," . $historyCreatorIdSelect . "," . $historyCreatorNameSelect . ",d.content," . $historyDeductionSelect
            . "," . $historyPdfFileIdSelect . "," . $historyPdfStatusSelect . "," . $historyPdfVersionSelect
            . " FROM cpms_approval_documents d" . $historyDeductionJoin
            . " WHERE d.doc_type='leave' AND UPPER(COALESCE(d.doc_status,'')) IN ('APPROVED','COMPLETED') ORDER BY d.id DESC";
        $historyResult = $pdo->query($historySql);
        $historyDocuments = $historyResult ? $historyResult->fetchAll(PDO::FETCH_ASSOC) : array();
        if (!is_array($historyDocuments)) {
            $historyDocuments = array();
        }
        for ($i = 0; $i < count($historyDocuments); $i++) {
            $historyDocument = $historyDocuments[$i];
            $employeeId = isset($historyDocument['created_by_id']) ? (int)$historyDocument['created_by_id'] : 0;
            if ($employeeId <= 0 || !isset($historyEmployeeMap[$employeeId])) {
                continue;
            }
            $content = array();
            $rawContent = isset($historyDocument['content']) ? trim((string)$historyDocument['content']) : '';
            if ($rawContent !== '') {
                $decodedContent = json_decode($rawContent, true);
                if (is_array($decodedContent)) {
                    $content = $decodedContent;
                }
            }
            $leaveStartDate = cpms_leave_parse_date(cpms_leave_history_content_value($content, 'leave_start_date'));
            $leaveEndDate = cpms_leave_parse_date(cpms_leave_history_content_value($content, 'leave_end_date'));
            if ($leaveStartDate === '') {
                continue;
            }
            if ($leaveEndDate === '') {
                $leaveEndDate = $leaveStartDate;
            }
            if ($leaveStartDate < $usageMonthStart || $leaveStartDate > $usageMonthEnd) {
                continue;
            }

            $historyEmployee = $historyEmployeeMap[$employeeId];
            $employeeName = cpms_leave_history_content_value($content, 'applicant_name');
            if ($employeeName === '') {
                $employeeName = isset($historyEmployee['name']) ? trim((string)$historyEmployee['name']) : '';
            }
            if ($employeeName === '' && isset($historyDocument['created_by_name'])) {
                $employeeName = trim((string)$historyDocument['created_by_name']);
            }
            $department = cpms_leave_history_content_value($content, 'department');
            if ($department === '') {
                $department = isset($historyEmployee['department']) ? trim((string)$historyEmployee['department']) : '';
            }
            $position = cpms_leave_history_content_value($content, 'position');
            if ($position === '') {
                $position = isset($historyEmployee['position']) ? trim((string)$historyEmployee['position']) : '';
            }
            $deductedDays = isset($historyDocument['deducted_days']) ? $historyDocument['deducted_days'] : null;
            $historyRows[count($historyRows)] = array(
                'document_id' => isset($historyDocument['id']) ? (int)$historyDocument['id'] : 0,
                'employee_name' => $employeeName,
                'department' => $department,
                'position' => $position,
                'leave_type' => cpms_leave_history_type_label($content),
                'leave_start_date' => $leaveStartDate,
                'leave_end_date' => $leaveEndDate,
                'used_days' => cpms_leave_history_use_days($content, $deductedDays),
                'pdf_file_id' => isset($historyDocument['completed_pdf_drive_file_id']) ? trim((string)$historyDocument['completed_pdf_drive_file_id']) : '',
                'pdf_status' => isset($historyDocument['completed_pdf_upload_status']) ? strtolower(trim((string)$historyDocument['completed_pdf_upload_status'])) : '',
                'pdf_render_version' => isset($historyDocument['completed_pdf_render_version']) ? (int)$historyDocument['completed_pdf_render_version'] : 0
            );
        }
        usort($historyRows, 'cpms_leave_history_sort_rows');
    } catch (Exception $e) {
        $historyRows = array();
        $historyLoadError = $e->getMessage();
    }
}

$leaveFilterParams = array(
    'r' => '관리',
    'tab' => 'leave_management',
    'year' => $year,
    'status' => $statusFilter
);
if ($deptFilter !== '') {
    $leaveFilterParams['department'] = $deptFilter;
}
if ($q !== '') {
    $leaveFilterParams['q'] = $q;
}
$leaveStatusParams = $leaveFilterParams;
$leaveStatusParams['leave_view'] = 'status';
$leaveHistoryParams = $leaveFilterParams;
$leaveHistoryParams['leave_view'] = 'history';
$leaveHistoryParams['usage_month'] = $usageMonth;
$leaveResetParams = array(
    'r' => '관리',
    'tab' => 'leave_management',
    'leave_view' => $leaveView
);
if ($leaveView === 'history') {
    $leaveResetParams['usage_month'] = (int)date('n');
}
?>

<style>
.cpms-leave-filter{position:sticky;top:0;z-index:20;background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:14px;margin-bottom:14px}
.cpms-leave-meta{display:flex;flex-wrap:wrap;gap:10px;margin-top:10px;color:#6b7280;font-size:12px}
.cpms-leave-section{margin-top:18px}
.cpms-leave-section-title{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px}
.cpms-leave-section-title h4{margin:0;font-size:20px;font-weight:900}
.cpms-leave-section-title .annual-title{color:#1d4ed8}
.cpms-leave-section-title .monthly-title{color:#dc2626}
.cpms-leave-section-note{font-size:12px;color:#64748b}
.cpms-leave-table-wrap{overflow:auto;border:1px solid #d1d5db;background:#fff;max-height:calc(100vh - 280px)}
.cpms-leave-table{border-collapse:collapse;min-width:1700px;width:100%;font-size:12px;color:#111827}
.cpms-leave-table th,.cpms-leave-table td{border:1px solid #e5e7eb;padding:7px 8px;text-align:center;white-space:nowrap;line-height:1.35;vertical-align:middle}
.cpms-leave-table thead th{position:sticky;top:0;background:#f8fafc;z-index:8;font-weight:800}
.cpms-leave-table .sticky-name{position:sticky;left:0;background:#fff;z-index:6;box-shadow:1px 0 0 #d1d5db;text-align:left;min-width:92px}
.cpms-leave-table thead .sticky-name{z-index:10;background:#f8fafc}
.cpms-leave-table .left{text-align:left}
.cpms-leave-table .total-cell{background:#fef3c7;color:#a16207;font-weight:800}
.cpms-leave-table .balance-cell{background:#fce7f3;font-weight:900}
.cpms-leave-table .month-cell{min-width:56px}
.cpms-leave-table .sum-cell{font-weight:900;border-left:2px solid #111827}
.leave-use-annual{color:#2563eb;font-weight:800}
.leave-use-monthly{color:#dc2626;font-weight:800}
.leave-use-divider{color:#94a3b8;font-weight:700}
.leave-use-stack{display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:4px;min-height:18px}
.leave-use-stack.inline{gap:4px}
.cpms-leave-view-tabs{display:flex;flex-wrap:wrap;gap:8px;border-bottom:1px solid #d1d5db;margin:4px 0 16px;padding:0 2px 10px}
.cpms-leave-view-tab{display:inline-flex;align-items:center;justify-content:center;padding:10px 18px;border:1px solid #d1d5db;border-radius:12px;background:#fff;color:#4b5563;font-weight:800;text-decoration:none}
.cpms-leave-view-tab.is-active{border-color:#1d4ed8;background:#1d4ed8;color:#fff}
.cpms-leave-month-tabs{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px}
.cpms-leave-month-tab{display:inline-flex;align-items:center;justify-content:center;min-width:52px;padding:8px 11px;border:1px solid #d1d5db;border-radius:10px;background:#fff;color:#4b5563;font-size:13px;font-weight:800;text-decoration:none}
.cpms-leave-month-tab.is-active{border-color:#0f766e;background:#0f766e;color:#fff}
.cpms-leave-history-summary{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:10px}
.cpms-leave-history-table{min-width:980px}
.cpms-leave-history-table th,.cpms-leave-history-table td{padding:10px 12px}
.cpms-leave-pdf-actions{display:flex;align-items:center;justify-content:center;gap:6px;flex-wrap:wrap}
.cpms-leave-pdf-action{display:inline-flex;align-items:center;justify-content:center;padding:6px 10px;border-radius:8px;font-size:12px;font-weight:800;text-decoration:none;white-space:nowrap}
.cpms-leave-pdf-view{background:#e0e7ff;color:#3730a3}
.cpms-leave-pdf-download{background:#f3f4f6;color:#374151}
.cpms-leave-pdf-status{font-size:11px;color:#6b7280}
.cpms-leave-pdf-rebuild{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;border:1px solid #c7d2fe;background:#eef2ff;border-radius:14px;padding:12px 14px;margin-bottom:14px}
.cpms-leave-pdf-rebuild button{border:0;border-radius:10px;background:#4338ca;color:#fff;padding:9px 14px;font-weight:800;cursor:pointer}
.cpms-leave-pdf-rebuild button:disabled{cursor:wait;opacity:.65}
</style>

<div class="mb-5">
    <h3 class="text-2xl font-extrabold text-gray-900">연차 관리</h3>
</div>

<?php if (count($schemaWarnings) > 0) { ?>
    <div class="mb-4 rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 text-amber-900">
        <div class="font-extrabold">DB 설치/확인이 필요합니다.</div>
        <div class="text-sm mt-1"><?php echo h(implode(', ', $schemaWarnings)); ?> 항목이 없으면 자동 발생 또는 집계가 제한됩니다.</div>
        <a class="inline-block mt-2 px-3 py-2 rounded-xl bg-amber-600 text-white font-bold" href="?r=db_setup_approval">전자결재 DB 설치/확인</a>
    </div>
<?php } ?>

<?php if ($employeeLoadError !== '') { ?>
    <div class="mb-4 rounded-2xl border border-red-300 bg-red-50 px-4 py-3 text-red-700"><?php echo h($employeeLoadError); ?></div>
<?php } ?>

<form method="get" class="cpms-leave-filter">
    <input type="hidden" name="r" value="관리">
    <input type="hidden" name="tab" value="leave_management">
    <input type="hidden" name="leave_view" value="<?php echo h($leaveView); ?>">
    <?php if ($leaveView === 'history') { ?>
        <input type="hidden" name="usage_month" value="<?php echo (int)$usageMonth; ?>">
    <?php } ?>
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-3">
        <div>
            <div class="text-xs font-bold text-gray-600 mb-1">연도</div>
            <select name="year" class="w-full border rounded-xl px-3 py-2">
                <?php for ($yy = $currentYear - 2; $yy <= $currentYear + 3; $yy++) { ?>
                    <option value="<?php echo (int)$yy; ?>" <?php echo ($yy === $year) ? 'selected' : ''; ?>><?php echo (int)$yy; ?>년</option>
                <?php } ?>
            </select>
        </div>
        <div>
            <div class="text-xs font-bold text-gray-600 mb-1">부서</div>
            <select name="department" class="w-full border rounded-xl px-3 py-2">
                <option value="">전체</option>
                <?php for ($i = 0; $i < count($departmentOptions); $i++) { ?>
                    <option value="<?php echo h($departmentOptions[$i]); ?>" <?php echo ($departmentOptions[$i] === $deptFilter) ? 'selected' : ''; ?>><?php echo h($departmentOptions[$i]); ?></option>
                <?php } ?>
            </select>
        </div>
        <div>
            <div class="text-xs font-bold text-gray-600 mb-1">재직/퇴사</div>
            <select name="status" class="w-full border rounded-xl px-3 py-2">
                <option value="active" <?php echo ($statusFilter === 'active') ? 'selected' : ''; ?>>재직</option>
                <option value="resigned" <?php echo ($statusFilter === 'resigned') ? 'selected' : ''; ?>>퇴사</option>
                <option value="all" <?php echo ($statusFilter === 'all') ? 'selected' : ''; ?>>전체</option>
            </select>
        </div>
        <div>
            <div class="text-xs font-bold text-gray-600 mb-1">직원명 검색</div>
            <input type="text" name="q" value="<?php echo h($q); ?>" class="w-full border rounded-xl px-3 py-2" placeholder="성명/부서/직위">
        </div>
        <div class="flex items-end gap-2">
            <button type="submit" class="px-4 py-2 rounded-xl bg-gray-900 text-white font-bold">검색</button>
            <a href="?<?php echo h(http_build_query($leaveResetParams)); ?>" class="px-4 py-2 rounded-xl bg-gray-100 text-gray-900 font-bold">초기화</a>
        </div>
    </div>
    <div class="cpms-leave-meta">
        <span>조회 기준일: <?php echo h($displayBaseDate); ?></span>
        <span>자동 발생 반영: 월차 <?php echo (int)$accrualStats['monthly']; ?>건 / 연차 <?php echo (int)$accrualStats['annual']; ?>건</span>
    </div>
</form>

<nav class="cpms-leave-view-tabs" aria-label="연차관리 상세 탭">
    <a class="cpms-leave-view-tab <?php echo $leaveView === 'status' ? 'is-active' : ''; ?>" href="?<?php echo h(http_build_query($leaveStatusParams)); ?>">연차현황</a>
    <a class="cpms-leave-view-tab <?php echo $leaveView === 'history' ? 'is-active' : ''; ?>" href="?<?php echo h(http_build_query($leaveHistoryParams)); ?>">연차관리 사용내역</a>
</nav>

<?php if ($leaveView === 'status') { ?>
<div class="cpms-leave-section">
    <div class="cpms-leave-section-title">
        <h4 class="annual-title">연차 현황</h4>
        <div class="cpms-leave-section-note">입사 1년 이상 직원</div>
    </div>
    <div class="cpms-leave-table-wrap">
        <table class="cpms-leave-table">
            <thead>
                <tr>
                    <th class="sticky-name">성명</th>
                    <th>부서명</th>
                    <th>직위</th>
                    <th>입사일</th>
                    <th>근속개월수</th>
                    <th>총 연차</th>
                    <th>사용일수</th>
                    <th>잔여연차</th>
                    <?php for ($m = 1; $m <= 12; $m++) { ?>
                        <th class="month-cell"><?php echo (int)$m; ?>월</th>
                    <?php } ?>
                    <th class="sum-cell">합계</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($annualRows) === 0) { ?>
                    <tr>
                        <td colspan="21" class="left">표시할 연차 대상 직원이 없습니다.</td>
                    </tr>
                <?php } ?>
                <?php for ($i = 0; $i < count($annualRows); $i++) {
                    $row = $annualRows[$i];
                    $emp = $row['employee'];
                    $employeeId = isset($emp['id']) ? (int)$emp['id'] : 0;
                    $usedHtml = cpms_leave_render_usage_html(array(
                        'annual' => $row['annual_used'],
                        'monthly' => $row['monthly_used']
                    ));
                ?>
                    <tr>
                        <td class="sticky-name"><strong><?php echo h(isset($emp['name']) ? $emp['name'] : ''); ?></strong></td>
                        <td><?php echo h(isset($emp['department']) ? $emp['department'] : ''); ?></td>
                        <td><?php echo h(isset($emp['position']) ? $emp['position'] : ''); ?></td>
                        <td><?php echo h($row['hire_date']); ?></td>
                        <td><?php echo (int)$row['service_months']; ?></td>
                        <td class="total-cell"><?php echo h(cpms_leave_format_decimal($row['annual_total'])); ?></td>
                        <td><?php echo $usedHtml !== '' ? '<div class="leave-use-stack inline">' . $usedHtml . '</div>' : ''; ?></td>
                        <td class="balance-cell"><?php echo h(cpms_leave_format_decimal($row['annual_balance'])); ?></td>
                        <?php for ($m = 1; $m <= 12; $m++) {
                            $monthData = (isset($monthlyUsage[$employeeId]) && isset($monthlyUsage[$employeeId][$m])) ? $monthlyUsage[$employeeId][$m] : cpms_leave_empty_usage_bucket();
                            $monthHtml = cpms_leave_render_usage_html($monthData);
                        ?>
                            <td class="month-cell"><?php echo $monthHtml !== '' ? '<div class="leave-use-stack">' . $monthHtml . '</div>' : ''; ?></td>
                        <?php } ?>
                        <td class="sum-cell"><?php echo $usedHtml !== '' ? '<div class="leave-use-stack">' . $usedHtml . '</div>' : ''; ?></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>
<?php } ?>
<?php if ($leaveView === 'history') { ?>
<div class="cpms-leave-section">
    <div class="cpms-leave-section-title">
        <h4 class="annual-title">연차관리 사용내역</h4>
        <div class="cpms-leave-section-note">최종 승인된 전자결재 휴가계 기준</div>
    </div>

    <?php if ($showLeavePdfRebuildUi) { ?>
    <div class="cpms-leave-pdf-rebuild">
        <div>
            <div class="font-extrabold text-indigo-950">기존 승인 휴가계 PDF 서명 갱신</div>
            <div class="text-xs text-indigo-700 mt-1">승인 완료된 휴가계 PDF를 전체 결재자 서명이 포함된 새 파일로 교체합니다.</div>
        </div>
        <div class="flex items-center gap-3 flex-wrap">
            <span id="leavePdfRebuildProgress" class="text-sm font-bold text-indigo-800"></span>
            <button type="button" id="leavePdfRebuildButton">휴가계 PDF 전체 다시 생성</button>
        </div>
    </div>
    <?php } ?>

    <div class="cpms-leave-month-tabs" aria-label="사용내역 조회 월">
        <?php for ($m = 1; $m <= 12; $m++) {
            $usageMonthParams = $leaveFilterParams;
            $usageMonthParams['leave_view'] = 'history';
            $usageMonthParams['usage_month'] = $m;
        ?>
            <a class="cpms-leave-month-tab <?php echo $usageMonth === $m ? 'is-active' : ''; ?>" href="?<?php echo h(http_build_query($usageMonthParams)); ?>"><?php echo (int)$m; ?>월</a>
        <?php } ?>
    </div>

    <div class="cpms-leave-history-summary">
        <div class="font-extrabold text-gray-900"><?php echo (int)$year; ?>년 <?php echo (int)$usageMonth; ?>월</div>
        <div class="text-sm text-gray-500">총 <?php echo count($historyRows); ?>건</div>
    </div>

    <?php if ($historyLoadError !== '') { ?>
        <div class="mb-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">사용내역을 불러오지 못했습니다. <?php echo h($historyLoadError); ?></div>
    <?php } ?>

    <div class="cpms-leave-table-wrap">
        <table class="cpms-leave-table cpms-leave-history-table">
            <thead>
                <tr>
                    <th class="sticky-name">성명</th>
                    <th>부서명</th>
                    <th>직위</th>
                    <th>휴가구분</th>
                    <th>휴가 시작일</th>
                    <th>휴가 종료일</th>
                    <th>사용일 수</th>
                    <th>휴가계 PDF</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($historyRows) === 0) { ?>
                    <tr>
                        <td colspan="8" class="left"><?php echo (int)$year; ?>년 <?php echo (int)$usageMonth; ?>월에 승인된 휴가 사용내역이 없습니다.</td>
                    </tr>
                <?php } ?>
                <?php for ($i = 0; $i < count($historyRows); $i++) {
                    $historyRow = $historyRows[$i];
                ?>
                    <tr>
                        <td class="sticky-name"><strong><?php echo h($historyRow['employee_name']); ?></strong></td>
                        <td><?php echo h($historyRow['department']); ?></td>
                        <td><?php echo h($historyRow['position']); ?></td>
                        <td><?php echo h($historyRow['leave_type']); ?></td>
                        <td><?php echo h($historyRow['leave_start_date']); ?></td>
                        <td><?php echo h($historyRow['leave_end_date']); ?></td>
                        <td class="sum-cell"><?php echo h(cpms_leave_format_decimal($historyRow['used_days'])); ?></td>
                        <td>
                            <?php if ($historyRow['pdf_file_id'] !== '') { ?>
                                <div class="cpms-leave-pdf-actions">
                                    <a class="cpms-leave-pdf-action cpms-leave-pdf-view" href="?r=approval_completed_pdf&amp;id=<?php echo (int)$historyRow['document_id']; ?>" target="_blank" rel="noopener">보기</a>
                                    <a class="cpms-leave-pdf-action cpms-leave-pdf-download" href="?r=approval_completed_pdf&amp;id=<?php echo (int)$historyRow['document_id']; ?>&amp;download=1">다운로드</a>
                                </div>
                                <?php if ($historyRow['pdf_render_version'] < cpms_approval_pdf_render_version()) { ?>
                                    <div class="cpms-leave-pdf-status mt-1">서명 갱신 필요</div>
                                <?php } ?>
                            <?php } else if ($historyRow['pdf_status'] === 'failed') { ?>
                                <span class="cpms-leave-pdf-status text-red-600">PDF 생성 실패</span>
                            <?php } else { ?>
                                <span class="cpms-leave-pdf-status">PDF 미생성</span>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>
<?php if ($showLeavePdfRebuildUi) { ?>
<script>
(function () {
    var button = document.getElementById('leavePdfRebuildButton');
    var progress = document.getElementById('leavePdfRebuildProgress');
    if (!button || !progress || !window.fetch) return;
    button.onclick = function () {
        if (!window.confirm('승인 완료된 휴가계 PDF 전체를 다시 생성하고 Google Drive 파일을 교체하시겠습니까?')) return;
        var cursor = 0;
        var succeeded = 0;
        var failed = 0;
        var lastFailureMessage = '';
        button.disabled = true;
        progress.textContent = '재생성 준비 중...';

        function runBatch() {
            var body = 'cursor=' + encodeURIComponent(cursor)
                + '&limit=1&_csrf=' + encodeURIComponent(<?php echo json_encode(csrf_token()); ?>);
            window.fetch('?r=management/leave_pdf_rebuild', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                body: body
            }).then(function (response) {
                return response.text().then(function (text) {
                    var data = null;
                    if (text !== '') {
                        try {
                            data = JSON.parse(text);
                        } catch (parseError) {
                            if (text.charAt(0) === '<' || response.redirected) {
                                throw new Error('로그인이 만료되었거나 서버 오류 페이지가 반환되었습니다. 화면을 새로고침한 후 다시 시도해주세요.');
                            }
                            throw new Error('서버 응답을 확인할 수 없습니다. HTTP ' + response.status);
                        }
                    }
                    if (!data) {
                        throw new Error('서버 응답이 비어 있습니다. PHP 오류 로그를 확인해주세요. HTTP ' + response.status);
                    }
                    if (!response.ok) {
                        throw new Error(data.message ? data.message : 'PDF 재생성 요청 실패 (HTTP ' + response.status + ')');
                    }
                    return data;
                });
            }).then(function (data) {
                if (!data || !data.ok) throw new Error(data && data.message ? data.message : 'PDF 재생성 요청 실패');
                cursor = parseInt(data.next_cursor, 10) || cursor;
                succeeded += parseInt(data.succeeded, 10) || 0;
                failed += parseInt(data.failed, 10) || 0;
                if (data.items && data.items.length) {
                    for (var itemIndex = 0; itemIndex < data.items.length; itemIndex++) {
                        if (!data.items[itemIndex].ok && data.items[itemIndex].message) {
                            lastFailureMessage = data.items[itemIndex].message;
                        }
                    }
                }
                progress.textContent = '완료 ' + succeeded + '건 / 실패 ' + failed + '건 / 전체 ' + (parseInt(data.total, 10) || 0) + '건';
                if ((parseInt(data.failed, 10) || 0) > 0) {
                    button.disabled = false;
                    button.textContent = '휴가계 PDF 전체 다시 생성';
                    progress.textContent += lastFailureMessage !== '' ? ' / 중단 원인: ' + lastFailureMessage : ' / 첫 실패에서 중단했습니다.';
                    return;
                }
                if (data.done) {
                    button.disabled = false;
                    button.textContent = '휴가계 PDF 전체 다시 생성';
                    if (failed === 0) {
                        progress.textContent = '전체 ' + succeeded + '건 재생성 완료';
                        window.setTimeout(function () { window.location.reload(); }, 800);
                    } else if (lastFailureMessage !== '') {
                        progress.textContent += ' / 마지막 실패: ' + lastFailureMessage;
                    }
                    return;
                }
                window.setTimeout(runBatch, 100);
            }).catch(function (error) {
                button.disabled = false;
                progress.textContent = error && error.message ? error.message : 'PDF 재생성 중 오류가 발생했습니다.';
            });
        }

        runBatch();
    };
}());
</script>
<?php } ?>
<?php } ?>

<?php if ($leaveView === 'status') { ?>
<div class="cpms-leave-section">
    <div class="cpms-leave-section-title">
        <h4 class="monthly-title">월차 현황</h4>
        <div class="cpms-leave-section-note">입사 1년 미만 직원</div>
    </div>
    <div class="cpms-leave-table-wrap">
        <table class="cpms-leave-table">
            <thead>
                <tr>
                    <th class="sticky-name">성명</th>
                    <th>부서명</th>
                    <th>직위</th>
                    <th>입사일</th>
                    <th>근속개월수</th>
                    <th>총 월차</th>
                    <th>사용일수</th>
                    <th>잔여월차</th>
                    <?php for ($m = 1; $m <= 12; $m++) { ?>
                        <th class="month-cell"><?php echo (int)$m; ?>월</th>
                    <?php } ?>
                    <th class="sum-cell">합계</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($monthlyRows) === 0) { ?>
                    <tr>
                        <td colspan="21" class="left">표시할 월차 대상 직원이 없습니다.</td>
                    </tr>
                <?php } ?>
                <?php for ($i = 0; $i < count($monthlyRows); $i++) {
                    $row = $monthlyRows[$i];
                    $emp = $row['employee'];
                    $employeeId = isset($emp['id']) ? (int)$emp['id'] : 0;
                    $usedHtml = cpms_leave_render_usage_html(array(
                        'annual' => $row['annual_used'],
                        'monthly' => $row['monthly_used']
                    ));
                ?>
                    <tr>
                        <td class="sticky-name"><strong><?php echo h(isset($emp['name']) ? $emp['name'] : ''); ?></strong></td>
                        <td><?php echo h(isset($emp['department']) ? $emp['department'] : ''); ?></td>
                        <td><?php echo h(isset($emp['position']) ? $emp['position'] : ''); ?></td>
                        <td><?php echo h($row['hire_date']); ?></td>
                        <td><?php echo (int)$row['service_months']; ?></td>
                        <td class="total-cell"><?php echo h(cpms_leave_format_decimal($row['monthly_total'])); ?></td>
                        <td><?php echo $usedHtml !== '' ? '<div class="leave-use-stack inline">' . $usedHtml . '</div>' : ''; ?></td>
                        <td class="balance-cell"><?php echo h(cpms_leave_format_decimal($row['monthly_balance'])); ?></td>
                        <?php for ($m = 1; $m <= 12; $m++) {
                            $monthData = (isset($monthlyUsage[$employeeId]) && isset($monthlyUsage[$employeeId][$m])) ? $monthlyUsage[$employeeId][$m] : cpms_leave_empty_usage_bucket();
                            $monthHtml = cpms_leave_render_usage_html($monthData);
                        ?>
                            <td class="month-cell"><?php echo $monthHtml !== '' ? '<div class="leave-use-stack">' . $monthHtml . '</div>' : ''; ?></td>
                        <?php } ?>
                        <td class="sum-cell"><?php echo $usedHtml !== '' ? '<div class="leave-use-stack">' . $usedHtml . '</div>' : ''; ?></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>
<?php } ?>
