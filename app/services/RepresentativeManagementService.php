<?php
/**
 * 대표 경영현황 조회 서비스.
 * PHP 5.6 compatible.
 */

require_once __DIR__ . '/CompanyProfitSummaryService.php';
require_once __DIR__ . '/../views/approval/_common.php';

if (!function_exists('cpms_representative_normalize_name')) {
function cpms_representative_normalize_name($name) {
    $name = trim((string)$name);
    return preg_replace('/\s+/u', ' ', $name);
}}

if (!function_exists('cpms_representative_is_development_user')) {
function cpms_representative_is_development_user($user, $pdo = null) {
    if (!is_array($user) || !$pdo) return false;
    try {
        $employeeId = function_exists('approval_current_employee_id')
            ? (int)approval_current_employee_id($pdo, $user)
            : (isset($user['employee_id']) ? (int)$user['employee_id'] : 0);
        $email = isset($user['email']) ? trim((string)$user['email']) : '';
        if ($employeeId > 0) {
            $st = $pdo->prepare('SELECT department FROM employees WHERE id = :id LIMIT 1');
            $st->execute(array(':id' => $employeeId));
            $department = $st->fetchColumn();
            if ($department !== false) return trim((string)$department) === '개발';
        }
        if ($email !== '') {
            $st = $pdo->prepare('SELECT department FROM employees WHERE LOWER(TRIM(email)) = LOWER(TRIM(:email)) LIMIT 1');
            $st->execute(array(':email' => $email));
            $department = $st->fetchColumn();
            return $department !== false && trim((string)$department) === '개발';
        }
        return false;
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_can_view_representative_management')) {
function cpms_can_view_representative_management($pdo, $user) {
    // 대표 경영현황 임시 비활성화:
    // PC/모바일 메뉴를 숨기고 화면 및 상세 JSON 직접 접근도 모두 차단합니다.
    return false;
}}

if (!function_exists('cpms_representative_period')) {
function cpms_representative_period($request) {
    if (!is_array($request)) $request = array();
    $preset = isset($request['period']) ? trim((string)$request['period']) : 'month';
    $allowed = array('month', 'last_month', 'year', 'all', 'custom');
    if (!in_array($preset, $allowed, true)) $preset = 'month';
    $today = date('Y-m-d');
    if ($preset === 'last_month') {
        $base = strtotime('first day of last month');
        $start = date('Y-m-01', $base);
        $end = date('Y-m-t', $base);
    } else if ($preset === 'year') {
        $start = date('Y-01-01');
        $end = $today;
    } else if ($preset === 'all') {
        $start = '2000-01-01';
        $end = $today;
    } else if ($preset === 'custom') {
        $start = isset($request['start_date']) ? trim((string)$request['start_date']) : date('Y-m-01');
        $end = isset($request['end_date']) ? trim((string)$request['end_date']) : $today;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = date('Y-m-01');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) $end = $today;
    } else {
        $start = date('Y-m-01');
        $end = date('Y-m-t');
    }
    if ($start > $end) {
        $tmp = $start;
        $start = $end;
        $end = $tmp;
    }
    return array(
        'preset' => $preset,
        'start_date' => $start,
        'end_date' => $end,
        'start_month' => substr($start, 0, 7),
        'end_month' => substr($end, 0, 7),
    );
}}

if (!function_exists('cpms_representative_load_employees')) {
function cpms_representative_load_employees($pdo) {
    $result = array('by_id' => array(), 'by_name' => array(), 'ambiguous_names' => array());
    if (!$pdo) return $result;
    try {
        $rows = $pdo->query("SELECT id, name, email, department, position, role FROM employees ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) $rows = array();
        foreach ($rows as $row) {
            $id = isset($row['id']) ? (int)$row['id'] : 0;
            $nameKey = cpms_representative_normalize_name(isset($row['name']) ? $row['name'] : '');
            if ($id > 0) $result['by_id'][$id] = $row;
            if ($nameKey === '') continue;
            if (isset($result['by_name'][$nameKey])) {
                $result['ambiguous_names'][$nameKey] = true;
                unset($result['by_name'][$nameKey]);
            } else if (!isset($result['ambiguous_names'][$nameKey])) {
                $result['by_name'][$nameKey] = $row;
            }
        }
    } catch (Exception $e) {
    }
    return $result;
}}

if (!function_exists('cpms_representative_load_assignments')) {
function cpms_representative_load_assignments($pdo) {
    $result = array('by_employee' => array(), 'by_project' => array());
    if (!$pdo) return $result;
    try {
        $sql = "SELECT pm.project_id, pm.employee_id, LOWER(TRIM(pm.role)) AS member_role,
                       p.name AS project_name, p.start_date, p.end_date, p.status,
                       e.name AS employee_name, e.position AS employee_position
                  FROM cpms_project_members pm
                  INNER JOIN cpms_projects p ON p.id = pm.project_id
                  INNER JOIN employees e ON e.id = pm.employee_id
                 WHERE LOWER(TRIM(pm.role)) IN ('main','sub')
                   AND p.name NOT LIKE '(가제)%'
                 ORDER BY pm.employee_id ASC, pm.project_id ASC, pm.role ASC";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) $rows = array();
        $seen = array();
        foreach ($rows as $row) {
            $employeeId = isset($row['employee_id']) ? (int)$row['employee_id'] : 0;
            $projectId = isset($row['project_id']) ? (int)$row['project_id'] : 0;
            if ($employeeId <= 0 || $projectId <= 0) continue;
            $key = $employeeId . ':' . $projectId;
            if (isset($seen[$key])) {
                if ($row['member_role'] === 'main') {
                    $result['by_employee'][$employeeId][$projectId]['member_role'] = 'main';
                    $result['by_project'][$projectId][$employeeId]['member_role'] = 'main';
                }
                continue;
            }
            $seen[$key] = true;
            $result['by_employee'][$employeeId][$projectId] = $row;
            $result['by_project'][$projectId][$employeeId] = $row;
        }
    } catch (Exception $e) {
    }
    return $result;
}}

if (!function_exists('cpms_representative_project_overlaps')) {
function cpms_representative_project_overlaps($assignment, $startDate, $endDate) {
    $projectStart = isset($assignment['start_date']) ? trim((string)$assignment['start_date']) : '';
    $projectEnd = isset($assignment['end_date']) ? trim((string)$assignment['end_date']) : '';
    if ($projectStart !== '' && $projectStart !== '0000-00-00' && $projectStart > $endDate) return false;
    if ($projectEnd !== '' && $projectEnd !== '0000-00-00' && $projectEnd < $startDate) return false;
    return true;
}}

if (!function_exists('cpms_representative_valid_projects')) {
function cpms_representative_valid_projects($assignments, $employeeId, $startDate, $endDate) {
    $rows = array();
    if (!isset($assignments['by_employee'][$employeeId]) || !is_array($assignments['by_employee'][$employeeId])) return $rows;
    foreach ($assignments['by_employee'][$employeeId] as $projectId => $assignment) {
        if (cpms_representative_project_overlaps($assignment, $startDate, $endDate)) $rows[(int)$projectId] = $assignment;
    }
    ksort($rows, SORT_NUMERIC);
    return $rows;
}}

if (!function_exists('cpms_representative_split_amount')) {
function cpms_representative_split_amount($amount, $projectIds) {
    $result = array();
    $amount = (int)round((float)$amount);
    $projectIds = array_values(array_unique(array_map('intval', is_array($projectIds) ? $projectIds : array())));
    sort($projectIds, SORT_NUMERIC);
    $count = count($projectIds);
    if ($count === 0) return $result;
    $base = (int)($amount / $count);
    $remainder = $amount - ($base * $count);
    foreach ($projectIds as $projectId) $result[$projectId] = $base;
    $step = $remainder >= 0 ? 1 : -1;
    for ($i = 0; $i < abs($remainder); $i++) {
        $projectId = $projectIds[$i];
        $result[$projectId] += $step;
    }
    return $result;
}}

if (!function_exists('cpms_representative_resolve_employee')) {
function cpms_representative_resolve_employee($employees, $row, $nameKeys) {
    $employeeId = isset($row['employee_id']) ? (int)$row['employee_id'] : 0;
    if ($employeeId > 0 && isset($employees['by_id'][$employeeId])) return $employees['by_id'][$employeeId];
    foreach ($nameKeys as $nameKey) {
        $name = isset($row[$nameKey]) ? cpms_representative_normalize_name($row[$nameKey]) : '';
        if ($name === '') continue;
        if (isset($employees['ambiguous_names'][$name])) return null;
        if (isset($employees['by_name'][$name])) return $employees['by_name'][$name];
    }
    return null;
}}

if (!function_exists('cpms_representative_add_allocation')) {
function cpms_representative_add_allocation(&$allocation, $projectId, $employee, $assignment, $type, $originalAmount, $allocatedAmount, $validCount) {
    if (!isset($allocation['projects'][$projectId])) $allocation['projects'][$projectId] = array('payroll' => 0, 'cards' => 0, 'people' => array());
    $employeeId = isset($employee['id']) ? (int)$employee['id'] : 0;
    $personKey = $employeeId > 0 ? 'id:' . $employeeId : 'name:' . cpms_representative_normalize_name(isset($employee['name']) ? $employee['name'] : '');
    if (!isset($allocation['projects'][$projectId]['people'][$personKey])) {
        $allocation['projects'][$projectId]['people'][$personKey] = array(
            'employee_id' => $employeeId,
            'name' => isset($employee['name']) ? (string)$employee['name'] : '',
            'role' => isset($assignment['member_role']) && $assignment['member_role'] === 'main' ? '담당자' : '부담당자',
            'valid_project_count' => $validCount,
            'payroll_original' => 0,
            'payroll_allocated' => 0,
            'card_original' => 0,
            'card_allocated' => 0,
        );
    }
    if ($type === 'payroll') {
        $allocation['projects'][$projectId]['payroll'] += $allocatedAmount;
        $allocation['projects'][$projectId]['people'][$personKey]['payroll_original'] += $originalAmount;
        $allocation['projects'][$projectId]['people'][$personKey]['payroll_allocated'] += $allocatedAmount;
    } else {
        $allocation['projects'][$projectId]['cards'] += $allocatedAmount;
        $allocation['projects'][$projectId]['people'][$personKey]['card_original'] += $originalAmount;
        $allocation['projects'][$projectId]['people'][$personKey]['card_allocated'] += $allocatedAmount;
    }
}}

if (!function_exists('cpms_representative_allocate_overhead')) {
function cpms_representative_allocate_overhead($pdo, $period, $employees, $assignments, $overheadSummary) {
    $allocation = array(
        'projects' => array(),
        'payroll_original' => 0,
        'payroll_allocated' => 0,
        'payroll_common' => 0,
        'card_original' => 0,
        'card_allocated' => 0,
        'card_common' => 0,
        'other_common' => 0,
        'common_total' => 0,
        'site_total' => 0,
        'warnings' => array(),
    );
    $months = cpms_company_overhead_months_between($period['start_month'], $period['end_month']);
    foreach ($months as $ym) {
        $year = substr($ym, 0, 4);
        $month = substr($ym, 5, 2);
        $monthStart = $ym . '-01';
        $monthEnd = date('Y-m-t', strtotime($monthStart));
        if ($monthStart < $period['start_date']) $monthStart = $period['start_date'];
        if ($monthEnd > $period['end_date']) $monthEnd = $period['end_date'];

        $payrollSummary = cpms_company_payroll_month_summary($year, $month);
        $payrollTotal = !empty($payrollSummary['has_data']) && isset($payrollSummary['amount']) ? (int)round((float)$payrollSummary['amount']) : 0;
        $version = isset($payrollSummary['version']) && is_array($payrollSummary['version']) ? $payrollSummary['version'] : null;
        $payrollRows = is_array($version) && isset($version['employees']) && is_array($version['employees']) ? $version['employees'] : array();
        $payrollRowsTotal = 0;
        foreach ($payrollRows as $payrollRow) $payrollRowsTotal += isset($payrollRow['net_pay']) ? (int)round((float)$payrollRow['net_pay']) : 0;
        $allocation['payroll_original'] += $payrollTotal;
        if ($payrollTotal !== $payrollRowsTotal || count($payrollRows) === 0) {
            $allocation['payroll_common'] += $payrollTotal;
            if ($payrollTotal !== 0) $allocation['warnings'][] = $ym . ' 급여는 직원별 합계와 총관리비 기준액이 달라 공통관리비로 처리했습니다.';
        } else {
            foreach ($payrollRows as $payrollRow) {
                $amount = isset($payrollRow['net_pay']) ? (int)round((float)$payrollRow['net_pay']) : 0;
                if ($amount === 0) continue;
                $employee = cpms_representative_resolve_employee($employees, $payrollRow, array('name'));
                if (!is_array($employee)) {
                    $allocation['payroll_common'] += $amount;
                    continue;
                }
                $employeeId = isset($employee['id']) ? (int)$employee['id'] : 0;
                $valid = cpms_representative_valid_projects($assignments, $employeeId, $monthStart, $monthEnd);
                if (count($valid) === 0) {
                    $allocation['payroll_common'] += $amount;
                    continue;
                }
                $split = cpms_representative_split_amount($amount, array_keys($valid));
                foreach ($split as $projectId => $part) {
                    cpms_representative_add_allocation($allocation, $projectId, $employee, $valid[$projectId], 'payroll', $amount, $part, count($valid));
                    $allocation['payroll_allocated'] += $part;
                }
            }
        }

        $cardRows = cpms_company_overhead_load_month('corporate_cards', $year, $month, false);
        foreach ($cardRows as $cardRow) {
            if (!is_array($cardRow)) continue;
            $usedDate = isset($cardRow['occurred_at']) ? trim((string)$cardRow['occurred_at']) : '';
            if ($usedDate === '') $usedDate = isset($cardRow['paid_at']) ? trim((string)$cardRow['paid_at']) : '';
            if ($usedDate < $period['start_date'] || $usedDate > $period['end_date']) continue;
            $amount = isset($cardRow['amount']) ? (int)round((float)$cardRow['amount']) : 0;
            if ($amount === 0) continue;
            $allocation['card_original'] += $amount;
            $employee = cpms_representative_resolve_employee($employees, $cardRow, array('card_user', 'employee_name'));
            if (!is_array($employee)) {
                $allocation['card_common'] += $amount;
                continue;
            }
            $employeeId = isset($employee['id']) ? (int)$employee['id'] : 0;
            $valid = cpms_representative_valid_projects($assignments, $employeeId, $usedDate, $usedDate);
            if (count($valid) === 0) {
                $allocation['card_common'] += $amount;
                continue;
            }
            $split = cpms_representative_split_amount($amount, array_keys($valid));
            foreach ($split as $projectId => $part) {
                cpms_representative_add_allocation($allocation, $projectId, $employee, $valid[$projectId], 'card', $amount, $part, count($valid));
                $allocation['card_allocated'] += $part;
            }
        }
    }
    $categoryAmounts = isset($overheadSummary['categories']) && is_array($overheadSummary['categories']) ? $overheadSummary['categories'] : array();
    $officialPayroll = isset($categoryAmounts['payroll']['amount']) ? (int)round((float)$categoryAmounts['payroll']['amount']) : 0;
    $officialCards = isset($categoryAmounts['corporate_cards']['amount']) ? (int)round((float)$categoryAmounts['corporate_cards']['amount']) : 0;
    if ($allocation['payroll_original'] < $officialPayroll) $allocation['payroll_common'] += $officialPayroll - $allocation['payroll_original'];
    if ($allocation['card_original'] < $officialCards) $allocation['card_common'] += $officialCards - $allocation['card_original'];
    foreach ($categoryAmounts as $categoryKey => $categoryRow) {
        if ($categoryKey === 'payroll' || $categoryKey === 'corporate_cards') continue;
        $allocation['other_common'] += isset($categoryRow['amount']) ? (int)round((float)$categoryRow['amount']) : 0;
    }
    $allocation['site_total'] = $allocation['payroll_allocated'] + $allocation['card_allocated'];
    $officialTotal = isset($overheadSummary['total']) ? (int)round((float)$overheadSummary['total']) : 0;
    $allocation['common_total'] = $officialTotal - $allocation['site_total'];
    if ($allocation['common_total'] < 0) {
        $allocation['warnings'][] = '상세 배분 합계가 총관리비를 초과하여 배분 데이터를 확인해야 합니다.';
        $allocation['common_total'] = 0;
    }
    $allocation['payroll_reconciled'] = ($allocation['payroll_allocated'] + $allocation['payroll_common'] === $officialPayroll);
    $allocation['card_reconciled'] = ($allocation['card_allocated'] + $allocation['card_common'] === $officialCards);
    $allocation['overhead_reconciled'] = ($allocation['site_total'] + $allocation['common_total'] === $officialTotal);
    return $allocation;
}}

if (!function_exists('cpms_representative_risk')) {
function cpms_representative_risk($project) {
    $profit = isset($project['actual_profit']) ? (float)$project['actual_profit'] : 0.0;
    $rate = isset($project['actual_cost_rate']) ? (float)$project['actual_cost_rate'] : 0.0;
    $input = isset($project['actual_input_cost']) ? (float)$project['actual_input_cost'] : 0.0;
    $target = isset($project['target_amount']) ? (float)$project['target_amount'] : 0.0;
    $sales = isset($project['sales']) ? (float)$project['sales'] : 0.0;
    if ($profit < 0) return array('rank' => 1, 'label' => '손실', 'class' => 'danger');
    if ($rate >= 100) return array('rank' => 2, 'label' => '위험', 'class' => 'danger');
    if ($target > 0 && $input > $target) return array('rank' => 3, 'label' => '주의', 'class' => 'warning');
    if ($rate >= 80) return array('rank' => 4, 'label' => '주의', 'class' => 'warning');
    if ($sales <= 0) return array('rank' => 6, 'label' => '매출 미등록', 'class' => 'muted');
    return array('rank' => 5, 'label' => '정상', 'class' => 'normal');
}}

if (!function_exists('cpms_representative_project_sort')) {
function cpms_representative_project_sort($a, $b) {
    $ar = isset($a['risk']['rank']) ? (int)$a['risk']['rank'] : 99;
    $br = isset($b['risk']['rank']) ? (int)$b['risk']['rank'] : 99;
    if ($ar !== $br) return $ar < $br ? -1 : 1;
    $ap = isset($a['actual_profit']) ? (float)$a['actual_profit'] : 0.0;
    $bp = isset($b['actual_profit']) ? (float)$b['actual_profit'] : 0.0;
    if ($ap == $bp) return strcmp(isset($a['name']) ? (string)$a['name'] : '', isset($b['name']) ? (string)$b['name'] : '');
    return $ap < $bp ? -1 : 1;
}}

if (!function_exists('cpms_representative_build_dashboard')) {
function cpms_representative_build_dashboard($pdo, $request) {
    $period = cpms_representative_period($request);
    if ($period['preset'] === 'all') {
        $availableYears = cpms_company_profit_available_years($pdo);
        if (is_array($availableYears) && count($availableYears) > 0) {
            $period['start_date'] = sprintf('%04d-01-01', (int)$availableYears[0]);
            $period['start_month'] = substr($period['start_date'], 0, 7);
        }
    }
    $profitRequest = array(
        'scope' => 'custom',
        'start_month' => $period['start_month'],
        'end_month' => $period['end_month'],
        'view_mode' => 'monthly',
    );
    $requestedProjectId = isset($request['project_id']) ? (int)$request['project_id'] : 0;
    if ($requestedProjectId > 0) $profitRequest['project_id'] = $requestedProjectId;
    $profit = cpms_company_profit_build_dashboard($pdo, $profitRequest);
    $employees = cpms_representative_load_employees($pdo);
    $assignments = cpms_representative_load_assignments($pdo);
    $allocation = cpms_representative_allocate_overhead($pdo, $period, $employees, $assignments, isset($profit['overhead']) ? $profit['overhead'] : array());
    $projects = array();
    $siteOverheadTotal = 0;
    foreach (isset($profit['projects']) && is_array($profit['projects']) ? $profit['projects'] : array() as $project) {
        $projectId = isset($project['id']) ? (int)$project['id'] : 0;
        $projectAllocation = isset($allocation['projects'][$projectId]) ? $allocation['projects'][$projectId] : array('payroll' => 0, 'cards' => 0, 'people' => array());
        $project['payroll_allocated'] = isset($projectAllocation['payroll']) ? (int)$projectAllocation['payroll'] : 0;
        $project['card_allocated'] = isset($projectAllocation['cards']) ? (int)$projectAllocation['cards'] : 0;
        $project['site_overhead'] = $project['payroll_allocated'] + $project['card_allocated'];
        $project['actual_input_cost'] = (float)$project['input_cost'] + (float)$project['site_overhead'];
        $project['actual_profit'] = (float)$project['sales'] - (float)$project['actual_input_cost'];
        $rate = cpms_company_profit_cost_rate_info($project['sales'], $project['actual_input_cost']);
        $project['actual_cost_rate'] = $rate['cost_rate'];
        $project['actual_cost_rate_label'] = $rate['cost_rate_label'];
        $project['target_exceeded'] = ((float)$project['target_amount'] > 0 && (float)$project['actual_input_cost'] > (float)$project['target_amount']) ? 1 : 0;
        $project['people'] = isset($projectAllocation['people']) ? array_values($projectAllocation['people']) : array();
        $project['assignments'] = isset($assignments['by_project'][$projectId]) ? array_values($assignments['by_project'][$projectId]) : array();
        $project['risk'] = cpms_representative_risk($project);
        $siteOverheadTotal += $project['site_overhead'];
        $projects[] = $project;
    }
    usort($projects, 'cpms_representative_project_sort');
    $sales = isset($profit['totals']['sales']) ? (float)$profit['totals']['sales'] : 0.0;
    $direct = isset($profit['totals']['project_input_cost']) ? (float)$profit['totals']['project_input_cost'] : 0.0;
    $common = isset($allocation['common_total']) ? (float)$allocation['common_total'] : 0.0;
    $finalCost = $direct + $siteOverheadTotal + $common;
    $rate = cpms_company_profit_cost_rate_info($sales, $finalCost);
    return array(
        'period' => $period,
        'projects' => $projects,
        'top_projects' => array_slice($projects, 0, 5),
        'overhead_allocation' => $allocation,
        'totals' => array(
            'sales' => $sales,
            'direct_cost' => $direct,
            'site_overhead' => $siteOverheadTotal,
            'common_overhead' => $common,
            'final_cost' => $finalCost,
            'net_profit' => $sales - $finalCost,
            'cost_rate' => $rate['cost_rate'],
            'cost_rate_label' => $rate['cost_rate_label'],
            'target_amount' => isset($profit['totals']['target_amount']) ? (float)$profit['totals']['target_amount'] : 0.0,
        ),
        'errors' => isset($profit['errors']) ? $profit['errors'] : array(),
    );
}}
