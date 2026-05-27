<?php
use App\Core\Auth;
use App\Core\Db;

require_once __DIR__ . '/leave_management_helpers.php';

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
$yearStart = sprintf('%04d-01-01', $year);
$yearEnd = sprintf('%04d-12-31', $year);
$displayBaseDate = ($year === $currentYear) ? $today : $yearEnd;

$deptFilter = isset($_GET['department']) ? trim((string)$_GET['department']) : '';
$statusFilter = isset($_GET['status']) ? trim((string)$_GET['status']) : 'active';
if (!in_array($statusFilter, array('active', 'resigned', 'all'), true)) {
    $statusFilter = 'active';
}
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$accrualStats = cpms_leave_apply_accruals_until($pdo, $today);

$hasPosition = cpms_leave_column_exists($pdo, 'employees', 'position');
$hasHireDate = cpms_leave_column_exists($pdo, 'employees', 'hire_date');
$hasResignDate = cpms_leave_column_exists($pdo, 'employees', 'resign_date');
$hasMonthlyWage = cpms_leave_column_exists($pdo, 'employees', 'monthly_regular_wage');
$hasMonthlyBalance = cpms_leave_column_exists($pdo, 'employees', 'leave_monthly_balance');
$hasAnnualBalance = cpms_leave_column_exists($pdo, 'employees', 'leave_annual_balance');
$hasHalfBalance = cpms_leave_column_exists($pdo, 'employees', 'leave_half_balance');
$hasAccrualLogs = cpms_leave_table_exists($pdo, 'cpms_leave_accrual_logs');
$hasAdjustments = cpms_leave_table_exists($pdo, 'cpms_leave_adjustments')
    && cpms_leave_column_exists($pdo, 'cpms_leave_adjustments', 'target_year')
    && cpms_leave_column_exists($pdo, 'cpms_leave_adjustments', 'adjust_type');

$schemaWarnings = array();
if (!$hasHireDate) $schemaWarnings[count($schemaWarnings)] = 'employees.hire_date';
if (!$hasResignDate) $schemaWarnings[count($schemaWarnings)] = 'employees.resign_date';
if (!$hasMonthlyBalance) $schemaWarnings[count($schemaWarnings)] = 'employees.leave_monthly_balance';
if (!$hasAnnualBalance) $schemaWarnings[count($schemaWarnings)] = 'employees.leave_annual_balance';
if (!$hasHalfBalance) $schemaWarnings[count($schemaWarnings)] = 'employees.leave_half_balance';
if (!$hasMonthlyWage) $schemaWarnings[count($schemaWarnings)] = 'employees.monthly_regular_wage';
if (!$hasAccrualLogs) $schemaWarnings[count($schemaWarnings)] = 'cpms_leave_accrual_logs';
if (!$hasAdjustments) $schemaWarnings[count($schemaWarnings)] = 'cpms_leave_adjustments';

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
    $wageSelect = $hasMonthlyWage ? 'monthly_regular_wage' : 'NULL AS monthly_regular_wage';
    $monthlySelect = $hasMonthlyBalance ? 'leave_monthly_balance' : 'NULL AS leave_monthly_balance';
    $annualSelect = $hasAnnualBalance ? 'leave_annual_balance' : 'NULL AS leave_annual_balance';
    $halfSelect = $hasHalfBalance ? 'leave_half_balance' : 'NULL AS leave_half_balance';

    $sql = "SELECT id,name,department," . $positionSelect . "," . $hireSelect . "," . $resignSelect . "," . $wageSelect . "," . $monthlySelect . "," . $annualSelect . "," . $halfSelect . ",is_active FROM employees WHERE 1=1";
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
if (cpms_leave_table_exists($pdo, 'cpms_approval_documents')) {
    try {
        $deductionJoin = cpms_leave_table_exists($pdo, 'cpms_approval_leave_deductions')
            ? " LEFT JOIN cpms_approval_leave_deductions ld ON ld.document_id=d.id"
            : " LEFT JOIN (SELECT NULL AS document_id,NULL AS employee_id,NULL AS deduct_amount,NULL AS leave_type) ld ON 1=0";
        $sql = "SELECT d.id,d.created_by_id,d.created_by_name,d.content,d.doc_status,ld.employee_id AS deducted_employee_id,ld.deduct_amount,ld.leave_type AS deducted_leave_type
                  FROM cpms_approval_documents d
                  " . $deductionJoin . "
                 WHERE d.doc_type='leave'
                   AND UPPER(COALESCE(d.doc_status,'')) IN ('APPROVED','COMPLETED')";
        $docRows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($docRows)) {
            $docRows = array();
        }
        for ($i = 0; $i < count($docRows); $i++) {
            $doc = $docRows[$i];
            $content = array();
            $raw = isset($doc['content']) ? trim((string)$doc['content']) : '';
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $content = $decoded;
                }
            }
            $startDate = isset($content['leave_start_date']) ? cpms_leave_parse_date($content['leave_start_date']) : '';
            if ($startDate === '' || (int)date('Y', strtotime($startDate)) !== $year) {
                continue;
            }
            $employeeId = isset($doc['deducted_employee_id']) ? (int)$doc['deducted_employee_id'] : 0;
            if ($employeeId <= 0 && isset($doc['created_by_id'])) {
                $employeeId = (int)$doc['created_by_id'];
            }
            if ($employeeId <= 0) {
                continue;
            }
            $month = (int)date('n', strtotime($startDate));
            $amount = cpms_leave_use_amount_from_document($content, isset($doc['deduct_amount']) ? $doc['deduct_amount'] : null);
            if ($amount <= 0) {
                continue;
            }
            if (!isset($monthlyUsage[$employeeId])) {
                $monthlyUsage[$employeeId] = array();
            }
            if (!isset($monthlyUsage[$employeeId][$month])) {
                $monthlyUsage[$employeeId][$month] = 0.0;
            }
            $monthlyUsage[$employeeId][$month] += (float)$amount;
        }
    } catch (Exception $e) {
        $monthlyUsage = array();
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
            $eid = isset($rows[$i]['employee_id']) ? (int)$rows[$i]['employee_id'] : 0;
            $type = isset($rows[$i]['adjust_type']) ? strtoupper(trim((string)$rows[$i]['adjust_type'])) : '';
            if ($eid <= 0 || ($type !== 'ADD' && $type !== 'DEDUCT')) {
                continue;
            }
            if (!isset($adjustments[$eid])) {
                $adjustments[$eid] = array('ADD' => 0.0, 'DEDUCT' => 0.0);
            }
            $adjustments[$eid][$type] += (float)$rows[$i]['amount'];
        }
    } catch (Exception $e) {
        $adjustments = array();
    }
}
?>

<style>
.cpms-leave-filter{position:sticky;top:0;z-index:20;background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:14px;margin-bottom:14px}
.cpms-leave-table-wrap{overflow:auto;border:1px solid #d1d5db;background:#fff;max-height:calc(100vh - 240px)}
.cpms-leave-table{border-collapse:collapse;min-width:2300px;width:100%;font-size:12px;color:#111827}
.cpms-leave-table th,.cpms-leave-table td{border:1px solid #e5e7eb;padding:7px 8px;text-align:center;white-space:nowrap;line-height:1.35}
.cpms-leave-table thead th{position:sticky;top:0;background:#f8fafc;z-index:8;font-weight:800}
.cpms-leave-table .sticky-name{position:sticky;left:0;background:#fff;z-index:6;box-shadow:1px 0 0 #d1d5db;text-align:left;min-width:92px}
.cpms-leave-table thead .sticky-name{z-index:10;background:#f8fafc}
.cpms-leave-table .left{text-align:left}
.cpms-leave-table .annual-cell{background:#fff2bf;color:#c48a00;font-weight:800}
.cpms-leave-table .annual-generated{background:#eeeeee;color:#d49100;font-weight:800}
.cpms-leave-table .used-cell{color:#dc2626;font-weight:900}
.cpms-leave-table .remain-cell{background:#f5cbd3;font-weight:900}
.cpms-leave-table .month-cell{min-width:44px}
.cpms-leave-table .sum-cell{font-weight:900;border-left:2px solid #111827}
.cpms-leave-mini{display:block;margin-top:2px;color:#64748b;font-size:11px;font-weight:600}
</style>

<div class="mb-5">
    <div class="text-sm text-gray-500">관리 / 연차 관리</div>
    <h3 class="text-2xl font-extrabold text-gray-900">연차 관리</h3>
    <div class="text-sm text-gray-500 mt-1">직원명부 기준으로 연차/월차/반차 잔여와 승인 완료 휴가계 사용일수를 연도별로 확인합니다.</div>
</div>

<?php if (count($schemaWarnings) > 0) { ?>
    <div class="mb-4 rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 text-amber-900">
        <div class="font-extrabold">DB 설치/확인이 필요합니다.</div>
        <div class="text-sm mt-1"><?php echo h(implode(', ', $schemaWarnings)); ?> 항목이 없으면 일부 자동 발생/표시가 제한됩니다.</div>
        <a class="inline-block mt-2 px-3 py-2 rounded-xl bg-amber-600 text-white font-bold" href="?r=db_setup_approval">전자결재 DB 설치/확인</a>
    </div>
<?php } ?>

<?php if ($employeeLoadError !== '') { ?>
    <div class="mb-4 rounded-2xl border border-red-300 bg-red-50 px-4 py-3 text-red-700"><?php echo h($employeeLoadError); ?></div>
<?php } ?>

<form method="get" class="cpms-leave-filter">
    <input type="hidden" name="r" value="관리">
    <input type="hidden" name="tab" value="leave_management">
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
            <a href="?r=관리&tab=leave_management" class="px-4 py-2 rounded-xl bg-gray-100 text-gray-900 font-bold">초기화</a>
        </div>
    </div>
    <div class="mt-3 text-xs text-gray-500">
        자동 발생 반영: 월차 <?php echo (int)$accrualStats['monthly']; ?>건 / 연차 <?php echo (int)$accrualStats['annual']; ?>건
    </div>
</form>

<div class="cpms-leave-table-wrap">
    <table class="cpms-leave-table">
        <thead>
            <tr>
                <th class="sticky-name">성명</th>
                <th>부서명</th>
                <th>직위</th>
                <th>통상임금(월)</th>
                <th>입사일</th>
                <th>퇴사일</th>
                <th>구분</th>
                <th>근속개월수</th>
                <th>발생연차</th>
                <th>추가일수</th>
                <th>공제일수</th>
                <th>총 연차</th>
                <th>사용일수</th>
                <th>연차수당<br>차감일수</th>
                <th>잔여연차</th>
                <th>연차수당<br>지급액</th>
                <?php for ($m = 1; $m <= 12; $m++) { ?>
                    <th class="month-cell"><?php echo (int)$m; ?>월</th>
                <?php } ?>
                <th class="sum-cell">합계</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($employees) === 0) { ?>
                <tr>
                    <td colspan="29" class="left">표시할 직원이 없습니다.</td>
                </tr>
            <?php } ?>
            <?php for ($i = 0; $i < count($employees); $i++) {
                $emp = $employees[$i];
                $employeeId = isset($emp['id']) ? (int)$emp['id'] : 0;
                $hireDate = isset($emp['hire_date']) ? cpms_leave_parse_date($emp['hire_date']) : '';
                $resignDate = isset($emp['resign_date']) ? cpms_leave_parse_date($emp['resign_date']) : '';
                $rowBaseDate = $displayBaseDate;
                if ($resignDate !== '' && strcmp($resignDate, $rowBaseDate) < 0) {
                    $rowBaseDate = $resignDate;
                }
                $serviceMonths = ($hireDate !== '') ? cpms_leave_months_of_service($hireDate, $rowBaseDate) : 0;
                $annualGenerated = ($hireDate !== '') ? cpms_leave_annual_entitlement_for_year($hireDate, $year) : 0.0;
                $annualBalanceRaw = isset($emp['leave_annual_balance']) ? $emp['leave_annual_balance'] : null;
                if ($annualGenerated <= 0 && $year === $currentYear && $annualBalanceRaw !== null && $annualBalanceRaw !== '') {
                    $annualGenerated = (float)$annualBalanceRaw;
                }
                $addDays = (isset($adjustments[$employeeId]) && isset($adjustments[$employeeId]['ADD'])) ? (float)$adjustments[$employeeId]['ADD'] : 0.0;
                $deductDays = (isset($adjustments[$employeeId]) && isset($adjustments[$employeeId]['DEDUCT'])) ? (float)$adjustments[$employeeId]['DEDUCT'] : 0.0;
                $totalAnnual = $annualGenerated + $addDays - $deductDays;
                $monthSum = 0.0;
                $monthValues = array();
                for ($m = 1; $m <= 12; $m++) {
                    $mv = (isset($monthlyUsage[$employeeId]) && isset($monthlyUsage[$employeeId][$m])) ? (float)$monthlyUsage[$employeeId][$m] : 0.0;
                    $monthValues[$m] = $mv;
                    $monthSum += $mv;
                }
                $usedDays = $monthSum;
                $payDeductDays = 0.0;
                $remainAnnual = ($annualBalanceRaw !== null && $annualBalanceRaw !== '') ? (float)$annualBalanceRaw : ($totalAnnual - $usedDays);
                $monthlyBalance = isset($emp['leave_monthly_balance']) ? $emp['leave_monthly_balance'] : null;
                $halfBalance = isset($emp['leave_half_balance']) ? $emp['leave_half_balance'] : null;
                $wage = (isset($emp['monthly_regular_wage']) && $emp['monthly_regular_wage'] !== null && $emp['monthly_regular_wage'] !== '') ? (float)$emp['monthly_regular_wage'] : 0.0;
                $payAmount = ($wage > 0) ? (($wage / 30.0) * max(0.0, $remainAnnual - $payDeductDays)) : 0.0;
                $isActive = isset($emp['is_active']) ? (int)$emp['is_active'] : 1;
                $statusText = ($isActive === 1) ? '재직' : '퇴사';
            ?>
                <tr>
                    <td class="sticky-name"><strong><?php echo h(isset($emp['name']) ? $emp['name'] : ''); ?></strong></td>
                    <td><?php echo h(isset($emp['department']) ? $emp['department'] : ''); ?></td>
                    <td><?php echo h(isset($emp['position']) ? $emp['position'] : ''); ?></td>
                    <td><?php echo $wage > 0 ? h(number_format($wage)) : ''; ?></td>
                    <td><?php echo h($hireDate); ?></td>
                    <td><?php echo h($resignDate); ?></td>
                    <td>
                        <?php echo h($statusText); ?>
                        <span class="cpms-leave-mini">월차 <?php echo h(cpms_leave_format_decimal($monthlyBalance)); ?> / 반차 <?php echo h(cpms_leave_format_decimal($halfBalance)); ?></span>
                    </td>
                    <td><?php echo (int)$serviceMonths; ?></td>
                    <td class="annual-generated"><?php echo h(cpms_leave_format_decimal($annualGenerated)); ?></td>
                    <td><?php echo h(cpms_leave_format_decimal($addDays)); ?></td>
                    <td><?php echo h(cpms_leave_format_decimal($deductDays)); ?></td>
                    <td class="annual-cell"><?php echo h(cpms_leave_format_decimal($totalAnnual)); ?></td>
                    <td class="used-cell"><?php echo h(cpms_leave_format_decimal($usedDays)); ?></td>
                    <td><?php echo h(cpms_leave_format_decimal($payDeductDays)); ?></td>
                    <td class="remain-cell"><?php echo h(cpms_leave_format_decimal($remainAnnual)); ?></td>
                    <td><?php echo $payAmount > 0 ? h(number_format($payAmount)) : ''; ?></td>
                    <?php for ($m = 1; $m <= 12; $m++) { ?>
                        <td class="month-cell"><?php echo $monthValues[$m] > 0 ? h(cpms_leave_format_decimal($monthValues[$m])) : ''; ?></td>
                    <?php } ?>
                    <td class="sum-cell"><?php echo $monthSum > 0 ? h(cpms_leave_format_decimal($monthSum)) : ''; ?></td>
                </tr>
            <?php } ?>
        </tbody>
    </table>
</div>
