<?php
use App\Core\Db;

$pdo = Db::pdo();
$selectedProjectId = isset($_GET['pid']) ? (int)$_GET['pid'] : 0;
$viewMonthParam = isset($_GET['view_month']) ? trim((string)$_GET['view_month']) : '';
$monthlyProjects = array();
$allMonths = array();
$displayMonths = array();
$selectedViewMonth = '';
$months = array();
$monthlyRevenue = array();
$rowsBySection = array('구매품'=>array(),'자재비'=>array(),'장비비'=>array(),'노무비'=>array(),'기타경비'=>array(),'안전관리비'=>array(),'공제분'=>array());
$errors = array();
$notices = array();
$deductionTableMissing = false;
$periodMissing = false;
$workDateFallbackUsed = false;
$salesDiagnostics = array();
$laborDiagnostics = array();
$debugMode = isset($_GET['debug']) && (string)$_GET['debug'] === '1';
require_once __DIR__ . '/../construction/tabs/partials/sales_data_loader.php';

function monthly_zero_map($months) { $m = array(); foreach ($months as $ym) { $m[$ym] = 0; } return $m; }
function amount_fmt($n){ if ((float)$n == 0) { return '-'; } return number_format((float)$n); }
function row_total($row, $months){ $sum = 0; foreach($months as $ym){ $sum += isset($row['months'][$ym]) ? (float)$row['months'][$ym] : 0; } return $sum; }
function ym_valid($ym){ return preg_match('/^\\d{4}-\\d{2}$/', (string)$ym); }

function cpms_cost_period_range($ym, $type) {
    $ym = trim((string)$ym);
    $type = trim((string)$type);
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) { $ym = date('Y-m'); }
    if ($type === 'labor' || $type === 'sales') {
        $start = $ym . '-01';
        $ts = strtotime($start);
        $end = date('Y-m-t', $ts);
        return array('start' => $start, 'end' => $end);
    }
    $currentStartTs = strtotime($ym . '-01');
    $prevMonthTs = strtotime('-1 month', $currentStartTs);
    $start = date('Y-m', $prevMonthTs) . '-26';
    $end = $ym . '-25';
    return array('start' => $start, 'end' => $end);
}
function cpms_material_equipment_cost_ym($useDate) {
    $useDate = trim((string)$useDate);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $useDate)) { return ''; }
    $day = (int)substr($useDate, 8, 2);
    $baseYm = substr($useDate, 0, 7);
    if ($day >= 26) {
        $ts = strtotime($baseYm . '-01 +1 month');
        return date('Y-m', $ts);
    }
    return $baseYm;
}
function project_monthly_table_exists($pdo, $table) {
    try { $st = $pdo->prepare('SHOW TABLES LIKE :t'); $st->bindValue(':t', $table); $st->execute(); return is_array($st->fetch()); }
    catch (Exception $e) { return false; }
}
function project_monthly_column_exists($pdo, $table, $column) {
    try { $st = $pdo->prepare('SHOW COLUMNS FROM ' . $table . ' LIKE :c'); $st->bindValue(':c', $column); $st->execute(); return is_array($st->fetch()); }
    catch (Exception $e) { return false; }
}
function project_monthly_parse_money($value) {
    if (function_exists('cpms_parse_money_value')) {
        return (float)cpms_parse_money_value($value);
    }
    $raw = preg_replace('/[^0-9.\-]/', '', (string)$value);
    if ($raw === '' || !is_numeric($raw)) { return 0.0; }
    return (float)$raw;
}
function project_monthly_table_columns($pdo, $table) {
    try {
        $st = $pdo->query("SHOW COLUMNS FROM `" . $table . "`");
        $rows = $st->fetchAll();
        $cols = array();
        if (is_array($rows)) {
            foreach ($rows as $r) {
                if (isset($r['Field'])) { $cols[] = (string)$r['Field']; }
            }
        }
        return $cols;
    } catch (Exception $e) {
        return array();
    }
}
function project_monthly_labor_breakdown($pdo, $projectId, $projectName, $ym) {
    $result = array('amount'=>0.0, 'worker_rows'=>0, 'workers_considered'=>0, 'company_amounts'=>array());
    if (!function_exists('cpms_load_gongsu_data') || !function_exists('cpms_build_timesheet_workers')) { return $result; }
    $directTeamMembers = cpms_load_direct_team_members($pdo);
    $projectLaborWorkers = cpms_load_project_labor_workers($pdo, $projectId);
    $workerRows = cpms_build_project_worker_rows($projectLaborWorkers, $directTeamMembers);
    $timesheetWorkers = cpms_build_timesheet_workers($workerRows);
    $gongsuData = cpms_load_gongsu_data($pdo, $projectName, $ym);
    $attendanceGongsuMap = isset($gongsuData['gongsu_map']) && is_array($gongsuData['gongsu_map']) ? $gongsuData['gongsu_map'] : array();
    $attendanceGongsuUnit = isset($gongsuData['gongsu_unit']) && is_array($gongsuData['gongsu_unit']) ? $gongsuData['gongsu_unit'] : array();
    $attendanceOutputDays = isset($gongsuData['output_days']) && is_array($gongsuData['output_days']) ? $gongsuData['output_days'] : array();
    if (function_exists('cpms_apply_labor_overrides_to_dataset')) {
        $overrideDataset = cpms_apply_labor_overrides_to_dataset($attendanceGongsuMap, $attendanceOutputDays, $attendanceGongsuUnit, $projectId, $ym);
        $attendanceGongsuMap = isset($overrideDataset['gongsu_map']) && is_array($overrideDataset['gongsu_map']) ? $overrideDataset['gongsu_map'] : array();
        $attendanceOutputDays = isset($overrideDataset['output_days']) && is_array($overrideDataset['output_days']) ? $overrideDataset['output_days'] : array();
    } else if (function_exists('cpms_apply_labor_overrides_to_map')) {
        $attendanceGongsuMap = cpms_apply_labor_overrides_to_map($attendanceGongsuMap, $projectId, $ym);
    }
    $sum = 0.0;
    foreach ($timesheetWorkers as $worker) {
        $workerName = isset($worker['name']) ? (string)$worker['name'] : '';
        $workerKey = function_exists('cpms_normalize_worker_key') ? cpms_normalize_worker_key($workerName) : trim((string)$workerName);
        if ($workerKey === '') { continue; }
        $outputDays = isset($attendanceOutputDays[$workerKey]) ? (int)$attendanceOutputDays[$workerKey] : 0;
        if ($outputDays <= 0) { continue; }
        $dailyMap = isset($attendanceGongsuMap[$workerKey]) && is_array($attendanceGongsuMap[$workerKey]) ? $attendanceGongsuMap[$workerKey] : array();
        $totalGongsu = 0.0;
        foreach ($dailyMap as $dateKey => $gongsuValue) {
            if (!is_numeric($gongsuValue)) { continue; }
            if (strpos((string)$dateKey, $ym) !== 0) { continue; }
            $totalGongsu += (float)$gongsuValue;
        }
        if ($totalGongsu <= 0) { continue; }
        if (function_exists('cpms_resolve_labor_wage_rate')) {
            $wageRate = (float)cpms_resolve_labor_wage_rate($worker);
        } else {
            $wageRateRaw = isset($worker['deposit_rate']) ? (string)$worker['deposit_rate'] : '';
            $wageRate = project_monthly_parse_money($wageRateRaw);
        }
        $amount = $totalGongsu * $wageRate;
        if ($amount <= 0) { continue; }
        $companyName = isset($worker['company_name']) ? trim((string)$worker['company_name']) : '';
        if ($companyName === '') $companyName = '창명건설';
        if (!isset($result['company_amounts'][$companyName])) $result['company_amounts'][$companyName] = 0.0;
        $result['company_amounts'][$companyName] += $amount;
        $sum += $amount;
        $result['workers_considered']++;
    }
    $result['amount'] = $sum;
    $result['worker_rows'] = is_array($timesheetWorkers) ? count($timesheetWorkers) : 0;
    return $result;
}
function project_monthly_labor_amount($pdo, $projectId, $projectName, $ym) {
    $breakdown = project_monthly_labor_breakdown($pdo, $projectId, $projectName, $ym);
    return array(
        'amount' => isset($breakdown['amount']) ? (float)$breakdown['amount'] : 0.0,
        'worker_rows' => isset($breakdown['worker_rows']) ? (int)$breakdown['worker_rows'] : 0,
        'workers_considered' => isset($breakdown['workers_considered']) ? (int)$breakdown['workers_considered'] : 0,
        'company_amounts' => isset($breakdown['company_amounts']) && is_array($breakdown['company_amounts']) ? $breakdown['company_amounts'] : array(),
    );
}
function project_monthly_load_revenue($pdo, $projectId, $allMonths) {
    $result = array('months'=>monthly_zero_map($allMonths), 'basis'=>'항목별 완료수량', 'row_count'=>0, 'warnings'=>array(), 'stats'=>array('item_rows'=>0,'unit_link_rows'=>0,'item_amount'=>0.0,'progress_rows'=>0,'progress_link_rows'=>0,'schedule_task_rows'=>0,'work_item_line_rows'=>0,'unit_price_rows'=>0,'completed_task_rows'=>0,'sales_sum'=>0.0));
    if (function_exists('cpms_sales_monthly_map')) {
        $primary = cpms_sales_monthly_map($pdo, $projectId, $allMonths);
        if (isset($primary['months']) && is_array($primary['months'])) {
            $result['months'] = $primary['months'];
        }
        $result['basis'] = isset($primary['basis']) ? (string)$primary['basis'] : '공사 상황 탭 매출 기준';
        if (isset($primary['stats']) && is_array($primary['stats'])) {
            $result['stats'] = array_merge($result['stats'], $primary['stats']);
        }
        $result['row_count'] = isset($result['stats']['completed_task_rows']) ? (int)$result['stats']['completed_task_rows'] : 0;
        if (array_sum($result['months']) > 0) {
            return $result;
        }
        $result['warnings'][] = '상황 탭 기준 매출 데이터가 없어 fallback 집계를 사용합니다.';
    }
    $hasWorkDate = project_monthly_column_exists($pdo, 'cpms_schedule_task_item_progress', 'work_date');
    try {
        $sql = 'SELECT p.done_qty,p.unit_price_id,' . ($hasWorkDate ? 'p.work_date,' : '') . ' t.start_date AS task_start_date, u.unit_price FROM cpms_schedule_task_item_progress p LEFT JOIN cpms_project_unit_prices u ON u.id=p.unit_price_id AND u.project_id=p.project_id LEFT JOIN cpms_schedule_tasks t ON t.id=p.task_id AND t.project_id=p.project_id WHERE p.project_id=:pid';
        $st = $pdo->prepare($sql);
        $st->bindValue(':pid', $projectId, \PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll();
        if (!is_array($rows)) { $rows = array(); }
        $result['stats']['item_rows'] = count($rows);
        foreach ($rows as $r) {
            $dateBase = '';
            if ($hasWorkDate && isset($r['work_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$r['work_date'])) { $dateBase = (string)$r['work_date']; }
            if ($dateBase === '' && isset($r['task_start_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$r['task_start_date'])) { $dateBase = (string)$r['task_start_date']; }
            if ($dateBase === '') { continue; }
            $ym = substr($dateBase, 0, 7);
            if (!isset($result['months'][$ym])) { continue; }
            $done = isset($r['done_qty']) ? (float)$r['done_qty'] : 0;
            $unit = isset($r['unit_price']) ? (float)$r['unit_price'] : 0;
            if ($unit > 0) { $result['stats']['unit_link_rows']++; }
            $amt = $done * $unit;
            $result['months'][$ym] += $amt;
            $result['stats']['item_amount'] += $amt;
        }
        $result['row_count'] = $result['stats']['item_rows'];
    } catch (Exception $e) {
        $result['warnings'][] = '항목별 완료수량 집계 오류: ' . $e->getMessage();
    }
    if (array_sum($result['months']) > 0) { return $result; }
    $taskCols = project_monthly_table_columns($pdo, 'cpms_schedule_tasks');
    $priceCol = '';
    foreach (array('amount','contract_amount','total_amount','price') as $col) { if (in_array($col, $taskCols, true)) { $priceCol = $col; break; } }
    if ($priceCol !== '' && project_monthly_table_exists($pdo, 'cpms_schedule_progress')) {
        try {
            $sql2 = 'SELECT sp.done_qty,sp.total_qty,sp.work_date,t.' . $priceCol . ' AS task_amount FROM cpms_schedule_progress sp LEFT JOIN cpms_schedule_tasks t ON t.id=sp.task_id AND t.project_id=sp.project_id WHERE sp.project_id=:pid';
            $st2 = $pdo->prepare($sql2);
            $st2->bindValue(':pid', $projectId, \PDO::PARAM_INT);
            $st2->execute();
            $rows2 = $st2->fetchAll();
            if (!is_array($rows2)) { $rows2 = array(); }
            $result['stats']['progress_rows'] = count($rows2);
            foreach ($rows2 as $r2) {
                $d = isset($r2['work_date']) ? (string)$r2['work_date'] : '';
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { continue; }
                $ym = substr($d, 0, 7);
                if (!isset($result['months'][$ym])) { continue; }
                $totalQty = isset($r2['total_qty']) ? (float)$r2['total_qty'] : 0;
                $doneQty = isset($r2['done_qty']) ? (float)$r2['done_qty'] : 0;
                $taskAmount = isset($r2['task_amount']) ? (float)$r2['task_amount'] : 0;
                if ($totalQty <= 0 || $taskAmount <= 0) { continue; }
                $result['months'][$ym] += ($doneQty / $totalQty) * $taskAmount;
                $result['stats']['progress_link_rows']++;
            }
            if (array_sum($result['months']) > 0) { $result['basis'] = '공정 진행 완료수량 비율'; $result['row_count'] = $result['stats']['progress_rows']; return $result; }
        } catch (Exception $e) {
            $result['warnings'][] = '공정 진행 집계 오류: ' . $e->getMessage();
        }
    }
    if ($priceCol !== '' && in_array('progress', $taskCols, true)) {
        try {
            $st3 = $pdo->prepare('SELECT start_date,end_date,progress,' . $priceCol . ' AS task_amount FROM cpms_schedule_tasks WHERE project_id=:pid');
            $st3->bindValue(':pid', $projectId, \PDO::PARAM_INT);
            $st3->execute();
            $rows3 = $st3->fetchAll();
            if (!is_array($rows3)) { $rows3 = array(); }
            foreach ($rows3 as $r3) {
                $baseDate = isset($r3['end_date']) ? (string)$r3['end_date'] : '';
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $baseDate)) { $baseDate = isset($r3['start_date']) ? (string)$r3['start_date'] : ''; }
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $baseDate)) { continue; }
                $ym = substr($baseDate, 0, 7);
                if (!isset($result['months'][$ym])) { continue; }
                $progress = isset($r3['progress']) ? (float)$r3['progress'] : 0;
                $taskAmount = isset($r3['task_amount']) ? (float)$r3['task_amount'] : 0;
                if ($taskAmount <= 0 || $progress <= 0) { continue; }
                $result['months'][$ym] += $taskAmount * ($progress / 100);
            }
            if (array_sum($result['months']) > 0) {
                $result['basis'] = '공정률 기준 임시 집계';
                $result['warnings'][] = '완료수량 기준 매출 데이터가 없어 공정률 기준으로 임시 집계했습니다.';
            }
        } catch (Exception $e) {
            $result['warnings'][] = '공정률 임시 집계 오류: ' . $e->getMessage();
        }
    }
    return $result;
}

if ($pdo) {
    try {
        $st = $pdo->query('SELECT id,name,start_date,end_date,contract_amount FROM cpms_projects ORDER BY id DESC');
        $monthlyProjects = $st->fetchAll();
        if (!is_array($monthlyProjects)) { $monthlyProjects = array(); }
    } catch (Exception $e) {
        $errors[] = '프로젝트 목록을 불러오지 못했습니다. 오류: ' . $e->getMessage();
    }

    if ($selectedProjectId <= 0 && count($monthlyProjects) > 0) {
        $selectedProjectId = (int)$monthlyProjects[0]['id'];
    }
    foreach ($monthlyProjects as $pp) {
        if ((int)$pp['id'] === $selectedProjectId) { $selectedProject = $pp; break; }
    }

    if (is_array($selectedProject)) {
        $startDate = (string)$selectedProject['start_date'];
        $endDate = (string)$selectedProject['end_date'];
        if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $startDate) && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $endDate)) {
            $s = substr($startDate, 0, 7) . '-01';
            $e = substr($endDate, 0, 7) . '-01';
            $cur = strtotime($s);
            $end = strtotime($e);
            while ($cur <= $end) {
                $allMonths[] = date('Y-m', $cur);
                $cur = strtotime('+1 month', $cur);
            }
        } else {
            $periodMissing = true;
        }
    }
}

if (count($allMonths) === 0) { $allMonths[] = date('Y-m'); }
$monthlyRevenue = monthly_zero_map($allMonths);

$currentMonth = date('Y-m');
if ($viewMonthParam === 'all') {
    $selectedViewMonth = 'all';
} else if (ym_valid($viewMonthParam) && in_array($viewMonthParam, $allMonths, true)) {
    $selectedViewMonth = $viewMonthParam;
} else if (in_array($currentMonth, $allMonths, true)) {
    $selectedViewMonth = $currentMonth;
} else if (count($allMonths) > 0) {
    $selectedViewMonth = $allMonths[count($allMonths)-1];
}
if ($selectedViewMonth === '' && count($allMonths) > 0) { $selectedViewMonth = $allMonths[0]; }
$displayMonths = ($selectedViewMonth === 'all') ? $allMonths : array($selectedViewMonth);

if ($pdo && is_array($selectedProject)) {
    $revenueResult = project_monthly_load_revenue($pdo, $selectedProjectId, $allMonths);
    $monthlyRevenue = isset($revenueResult['months']) ? $revenueResult['months'] : $monthlyRevenue;
    $salesDiagnostics[] = '매출 집계 기준: ' . (isset($revenueResult['basis']) ? $revenueResult['basis'] : '항목별 완료수량');
    foreach (isset($revenueResult['warnings']) && is_array($revenueResult['warnings']) ? $revenueResult['warnings'] : array() as $w) { $notices[] = $w; }

    try {
        $stMat = $pdo->prepare('SELECT m.id,m.category,m.vendor_name,m.representative,m.phone,m.biz_no,m.remark,u.use_date,u.amount FROM cpms_material_items m INNER JOIN cpms_material_usage u ON u.material_id=m.id AND u.project_id=m.project_id WHERE m.project_id=:pid');
        $stMat->bindValue(':pid', $selectedProjectId, \PDO::PARAM_INT);
        $stMat->execute();
        $mat = $stMat->fetchAll();
        if (!is_array($mat)) { $mat = array(); }
        $map = array('구매품'=>'구매품','자재비'=>'자재비','기타경비'=>'기타경비','안전관리비'=>'안전관리비');
        $tmp = array();
        foreach ($mat as $r) {
            $cat = trim((string)$r['category']);
            if (!isset($map[$cat])) { $cat = '자재비'; }
            $sec = $map[$cat];
            $id = 'm' . (int)$r['id'];
            if (!isset($tmp[$sec . '_' . $id])) {
                $tmp[$sec . '_' . $id] = array('section'=>$sec,'업체명'=>$r['vendor_name'],'내역'=>($r['remark'] !== '' ? $r['remark'] : $cat),'months'=>monthly_zero_map($allMonths));
            }
            $ym = cpms_material_equipment_cost_ym($r['use_date']);
            if (isset($tmp[$sec . '_' . $id]['months'][$ym])) { $tmp[$sec . '_' . $id]['months'][$ym] += (float)$r['amount']; }
        }
        foreach ($tmp as $one) { $rowsBySection[$one['section']][] = $one; }
    } catch (Exception $e) { $errors[] = '자재구입비 데이터를 불러오지 못했습니다. 오류: ' . $e->getMessage(); }

    try {
        $hasEqWorkUnit = project_monthly_column_exists($pdo, 'cpms_equipment_usage', 'work_unit');
        $hasEqBaseRate = project_monthly_column_exists($pdo, 'cpms_equipment_usage', 'base_rate_snapshot');
        $eqSelectExtra = '';
        if ($hasEqWorkUnit) $eqSelectExtra .= ',u.work_unit';
        if ($hasEqBaseRate) $eqSelectExtra .= ',u.base_rate_snapshot';
        $stEq = $pdo->prepare('SELECT e.id,e.vendor_name,e.spec,e.category,u.use_date,u.amount' . $eqSelectExtra . ' FROM cpms_equipment_items e INNER JOIN cpms_equipment_usage u ON u.equipment_id=e.id AND u.project_id=e.project_id WHERE e.project_id=:pid');
        $stEq->bindValue(':pid', $selectedProjectId, \PDO::PARAM_INT);
        $stEq->execute();
        $eq = $stEq->fetchAll();
        if (!is_array($eq)) { $eq = array(); }
        $tmpEq = array();
        foreach ($eq as $r) {
            $id = 'e' . (int)$r['id'];
            if (!isset($tmpEq[$id])) { $tmpEq[$id] = array('section'=>'장비비','업체명'=>$r['vendor_name'],'내역'=>($r['spec'] !== '' ? $r['spec'] : $r['category']),'months'=>monthly_zero_map($allMonths)); }
            $ym = cpms_material_equipment_cost_ym($r['use_date']);
            $eqAmount = (float)$r['amount'];
            if ($hasEqWorkUnit && $hasEqBaseRate) {
                $workUnit = isset($r['work_unit']) ? (float)$r['work_unit'] : 1.0;
                if ($workUnit <= 0) $workUnit = 1.0;
                $rateSnapshot = isset($r['base_rate_snapshot']) ? (float)$r['base_rate_snapshot'] : 0.0;
                if ($rateSnapshot <= 0) $rateSnapshot = (float)$r['amount'];
                $eqAmount = $workUnit * $rateSnapshot;
            }
            if (isset($tmpEq[$id]['months'][$ym])) { $tmpEq[$id]['months'][$ym] += $eqAmount; }
        }
        foreach ($tmpEq as $one) { $rowsBySection['장비비'][] = $one; }
    } catch (Exception $e) { $errors[] = '장비비 데이터를 불러오지 못했습니다. 오류: ' . $e->getMessage(); }

    // 노무비: 공사 > 노무비 계산 로더 재사용
    require_once __DIR__ . '/../construction/tabs/partials/labor_data_loader.php';
    if (function_exists('cpms_load_gongsu_data') && is_array($selectedProject) && isset($selectedProject['name'])) {
        $projectName = (string)$selectedProject['name'];
        $laborMonths = monthly_zero_map($allMonths);
        $laborWorkerRows = 0;
        $otherCompanyRows = array();
        foreach ($allMonths as $ym) {
            $laborResult = project_monthly_labor_amount($pdo, $selectedProjectId, $projectName, $ym);
            $companyAmounts = isset($laborResult['company_amounts']) && is_array($laborResult['company_amounts']) ? $laborResult['company_amounts'] : array();
            $laborMonths[$ym] = isset($companyAmounts['창명건설']) ? (float)$companyAmounts['창명건설'] : 0;
            foreach ($companyAmounts as $companyName => $amount) {
                $companyName = trim((string)$companyName);
                if ($companyName === '' || $companyName === '창명건설') continue;
                if (!isset($otherCompanyRows[$companyName])) {
                    $otherCompanyRows[$companyName] = array(
                        'section' => '노무비',
                        '업체명' => $companyName,
                        '내역' => '노무비 합계',
                        'months' => monthly_zero_map($allMonths)
                    );
                }
                if (isset($otherCompanyRows[$companyName]['months'][$ym])) {
                    $otherCompanyRows[$companyName]['months'][$ym] += (float)$amount;
                }
            }
            $laborWorkerRows = isset($laborResult['worker_rows']) ? (int)$laborResult['worker_rows'] : $laborWorkerRows;
        }
        $rowsBySection['노무비'][] = array('section'=>'노무비','업체명'=>'-','내역'=>'노무비 합계','months'=>$laborMonths);
        if (count($otherCompanyRows) > 0) {
            ksort($otherCompanyRows);
            foreach ($otherCompanyRows as $companyRow) {
                if (row_total($companyRow, $allMonths) <= 0) continue;
                $rowsBySection['노무비'][] = $companyRow;
            }
        }
        $laborDiagnostics[] = '노무비 집계 기준: 공사 > 노무비 지급총액 기준';
        if ($debugMode) { $laborDiagnostics[] = '노무비 근로자 rows: ' . number_format($laborWorkerRows); }
    }

    try {
        if (project_monthly_table_exists($pdo, 'cpms_project_monthly_deductions')) {
            $stDed = $pdo->prepare('SELECT id,ym,deduction_name,amount,memo FROM cpms_project_monthly_deductions WHERE project_id=:pid ORDER BY ym ASC,id ASC');
            $stDed->bindValue(':pid', $selectedProjectId, \PDO::PARAM_INT);
            $stDed->execute();
            $dd = $stDed->fetchAll();
            if (!is_array($dd)) { $dd = array(); }
            foreach ($dd as $r) {
                $row = array('section'=>'공제분','업체명'=>'','내역'=>$r['deduction_name'],'memo'=>$r['memo'],'months'=>monthly_zero_map($allMonths),'id'=>(int)$r['id']);
                if (isset($row['months'][$r['ym']])) { $row['months'][$r['ym']] = (float)$r['amount']; }
                $rowsBySection['공제분'][] = $row;
            }
        } else {
            $deductionTableMissing = true;
        }
    } catch (Exception $e) {
        $deductionTableMissing = true;
        $errors[] = '공제분 데이터를 불러오지 못했습니다. 오류: ' . $e->getMessage();
    }
}

if ($workDateFallbackUsed) { $notices[] = 'work_date가 없거나 비어 있으면 공정 시작일 기준으로 임시 집계했습니다.'; }

$labels = array('구매품'=>'1. 구매품','자재비'=>'2. 자재비','장비비'=>'3. 장비비','노무비'=>'4. 노무비','기타경비'=>'5. 기타경비','안전관리비'=>'6. 안전관리비','공제분'=>'7. 공제분');
$sumBySection = array();
foreach ($labels as $k=>$v) { $sumBySection[$k] = monthly_zero_map($allMonths); }
foreach ($rowsBySection as $sec=>$rows) {
    foreach ($rows as $row) {
        foreach ($allMonths as $ym) { $sumBySection[$sec][$ym] += isset($row['months'][$ym]) ? (float)$row['months'][$ym] : 0; }
    }
}
$subtotal1 = monthly_zero_map($allMonths);
$finalTotal = monthly_zero_map($allMonths);
$profit = monthly_zero_map($allMonths);
foreach ($allMonths as $ym) {
    $subtotal1[$ym] = $sumBySection['구매품'][$ym] + $sumBySection['자재비'][$ym] + $sumBySection['장비비'][$ym] + $sumBySection['노무비'][$ym] + $sumBySection['기타경비'][$ym];
    $finalTotal[$ym] = $subtotal1[$ym] + $sumBySection['안전관리비'][$ym] + $sumBySection['공제분'][$ym];
    $profit[$ym] = (isset($monthlyRevenue[$ym]) ? (float)$monthlyRevenue[$ym] : 0) - $finalTotal[$ym];
}
if ($debugMode && isset($revenueResult['stats']) && is_array($revenueResult['stats'])) {
    $salesDiagnostics[] = 'cpms_schedule_tasks rows: ' . number_format((int)$revenueResult['stats']['schedule_task_rows']);
    $salesDiagnostics[] = 'cpms_work_item_lines 연결 rows: ' . number_format((int)$revenueResult['stats']['work_item_line_rows']);
    $salesDiagnostics[] = 'cpms_project_unit_prices 연결 rows: ' . number_format((int)$revenueResult['stats']['unit_price_rows']);
    $salesDiagnostics[] = '완료공정 rows: ' . number_format((int)$revenueResult['stats']['completed_task_rows']);
    $salesDiagnostics[] = '계산된 매출 합계: ' . number_format((float)$revenueResult['stats']['sales_sum']);
}
if ($debugMode) {
    $salesDiagnostics[] = '프로젝트 계약기간 월 목록: ' . implode(', ', $allMonths);
    $salesDiagnostics[] = '선택월: ' . $selectedViewMonth;
    foreach ($allMonths as $diagYm) {
        $diagSalesRange = cpms_cost_period_range($diagYm, 'sales');
        $diagLaborRange = cpms_cost_period_range($diagYm, 'labor');
        $diagCostRange = cpms_cost_period_range($diagYm, 'material');
        $salesDiagnostics[] = $diagYm . ' 매출 기간: ' . $diagSalesRange['start'] . ' ~ ' . $diagSalesRange['end'];
        $salesDiagnostics[] = $diagYm . ' 노무비 기간: ' . $diagLaborRange['start'] . ' ~ ' . $diagLaborRange['end'];
        $salesDiagnostics[] = $diagYm . ' 자재/장비 기간: ' . $diagCostRange['start'] . ' ~ ' . $diagCostRange['end'];
    }
    $salesDiagnostics[] = '매출 총합계: ' . number_format((float)array_sum($monthlyRevenue));
    $salesDiagnostics[] = '최종 합계 총합계: ' . number_format((float)array_sum($finalTotal));
    $salesDiagnostics[] = '손익 총합계: ' . number_format((float)array_sum($profit));
    $laborDiagnostics[] = '노무비 월별 금액:';
    foreach ($allMonths as $diagYm) {
        $laborDiagnostics[] = $diagYm . ': ' . number_format(isset($sumBySection['노무비'][$diagYm]) ? (float)$sumBySection['노무비'][$diagYm] : 0);
    }
}
if (array_sum($monthlyRevenue) <= 0) {
    $salesDiagnostics[] = '매출 집계 결과가 없습니다.';
    $salesDiagnostics[] = 'cpms_schedule_tasks rows: ' . number_format((int)$revenueResult['stats']['schedule_task_rows']) . '건';
    $salesDiagnostics[] = 'cpms_work_item_lines 연결 rows: ' . number_format((int)$revenueResult['stats']['work_item_line_rows']) . '건';
    $salesDiagnostics[] = 'cpms_project_unit_prices 연결 rows: ' . number_format((int)$revenueResult['stats']['unit_price_rows']) . '건';
    $salesDiagnostics[] = '완료공정 rows: ' . number_format((int)$revenueResult['stats']['completed_task_rows']) . '건';
    $salesDiagnostics[] = '계산된 매출 합계: ' . number_format((float)$revenueResult['stats']['sales_sum']);
} else {
    $salesDiagnostics[] = '매출 데이터 건수: ' . number_format((int)$revenueResult['row_count']) . '건';
}
if (isset($rowsBySection['노무비'][0]) && row_total($rowsBySection['노무비'][0], $allMonths) <= 0) {
    $laborDiagnostics[] = '노무비 집계 결과가 없습니다.';
    $laborDiagnostics[] = '확인 필요:';
    $laborDiagnostics[] = '1. 공사 > 노무비에서 해당 월 노무비가 표시되는지 확인';
    $laborDiagnostics[] = '2. 프로젝트명과 출퇴근 현장명이 일치하는지 확인';
    $laborDiagnostics[] = '3. 근로자 임금단가가 입력되어 있는지 확인';
    $laborDiagnostics[] = '4. 출력일수가 0인지 확인';
}
?>
<div class="bg-white rounded-3xl border border-gray-100 p-5">
<h3 class="text-xl font-extrabold mb-3">월별 투입비 상세내역</h3>
<?php $guideYm = ($selectedViewMonth === 'all' && count($displayMonths) > 0) ? $displayMonths[count($displayMonths)-1] : $selectedViewMonth; if (!ym_valid($guideYm) && count($allMonths) > 0) { $guideYm = $allMonths[count($allMonths)-1]; } $laborRange = cpms_cost_period_range($guideYm, 'labor'); $salesRange = cpms_cost_period_range($guideYm, 'sales'); $meRange = cpms_cost_period_range($guideYm, 'material'); ?>
<div class="mb-3 rounded-xl border border-blue-100 bg-blue-50 text-blue-900 p-3 text-xs" style="display:none;">
<div class="font-bold mb-1">계산 기준</div>
<div>- 매출·노무비: 매월 1일 ~ 말일</div>
<div>- 자재비/장비비/안전관리비: 전월 26일 ~ 현월 25일</div>
<?php if (ym_valid($guideYm)): ?><div class="mt-1"><?php echo h(str_replace('-', '.', $guideYm)); ?> 기준 / 매출: <?php echo h($salesRange['start']); ?> ~ <?php echo h($salesRange['end']); ?> / 노무비: <?php echo h($laborRange['start']); ?> ~ <?php echo h($laborRange['end']); ?> / 자재·장비: <?php echo h($meRange['start']); ?> ~ <?php echo h($meRange['end']); ?></div><?php endif; ?>
</div>
<?php if (count($errors)>0): ?><div class="mb-3 rounded-xl border border-red-200 bg-red-50 text-red-800 p-3 text-sm"><?php foreach($errors as $em): ?><div><?php echo h($em); ?></div><?php endforeach; ?></div><?php endif; ?>
<?php if ($deductionTableMissing): ?><div class="mb-3 rounded-xl border border-amber-200 bg-amber-50 text-amber-800 p-3 text-sm">공제분 테이블이 없습니다. 공무 DB 설치/확인을 실행해주세요.</div><?php endif; ?>

<?php if (count($monthlyProjects) === 0): ?>
<div class="text-sm text-gray-700">등록된 프로젝트가 없습니다. [프로젝트 관리] 탭에서 신규 프로젝트를 먼저 생성해주세요.</div>
<?php else: ?>
<form method="get" class="mb-4 flex flex-wrap items-center gap-2 bg-amber-50 border border-amber-200 rounded-2xl p-3">
<input type="hidden" name="r" value="공무"><input type="hidden" name="tab" value="monthly_input">
<div class="font-bold text-base">프로젝트 선택 :</div>
<select name="pid" class="px-3 py-2 border rounded-xl min-w-[260px]"><?php foreach($monthlyProjects as $pp): ?><option value="<?php echo (int)$pp['id']; ?>" <?php echo ((int)$pp['id']===$selectedProjectId)?'selected':''; ?>><?php echo h($pp['name']); ?></option><?php endforeach; ?></select>
<button type="submit" class="px-4 py-2 rounded-xl bg-amber-700 text-white">조회</button>
<div class="font-bold text-base ml-3">월 선택 :</div>
<select name="view_month" class="px-3 py-2 border rounded-xl min-w-[160px]">
<option value="all" <?php echo ($selectedViewMonth==='all')?'selected':''; ?>>전체보기</option>
<?php foreach($allMonths as $ymOpt): ?><option value="<?php echo h($ymOpt); ?>" <?php echo ($selectedViewMonth===$ymOpt)?'selected':''; ?>><?php echo h(str_replace('-', '.', $ymOpt)); ?></option><?php endforeach; ?>
</select>
</form>
<?php if (false): ?>
<?php foreach($salesDiagnostics as $diag): ?><div class="mb-1 text-xs text-gray-700"><?php echo h($diag); ?></div><?php endforeach; ?>
<?php foreach($laborDiagnostics as $diag): ?><div class="mb-1 text-xs text-gray-700"><?php echo h($diag); ?></div><?php endforeach; ?>
<?php endif; ?>

<?php if(false && $selectedProject): ?>
<div class="text-sm mb-3 space-y-1">
<div><span class="font-semibold">공사명 :</span> <?php echo h($selectedProject['name']); ?></div>
<div><span class="font-semibold">계약기간 :</span> <?php echo h($selectedProject['start_date']); ?> ~ <?php echo h($selectedProject['end_date']); ?></div>
<div><span class="font-semibold">계약금액 :</span> <?php echo number_format((float)$selectedProject['contract_amount']); ?> <span class="text-xs text-gray-600">VAT 제외</span></div>
</div>
<?php endif; ?>

<?php if ($periodMissing): ?><div class="mb-3 rounded-xl border border-amber-200 bg-amber-50 text-amber-800 p-3 text-sm">프로젝트 계약기간이 없어 월별 투입비를 계산할 수 없습니다. 프로젝트 관리 탭에서 공사 시작일/종료일을 입력해주세요.</div><?php endif; ?>

<div class="overflow-x-auto">
<table class="min-w-[1100px] w-full text-sm border">
<thead><tr class="bg-[#d7aa8a]"><th class="border p-2">구분</th><th class="border p-2">업체명</th><th class="border p-2">내역</th><?php foreach($displayMonths as $ym): ?><th class="border p-2 text-right"><?php echo h(str_replace('-', '.', $ym)); ?></th><?php endforeach; ?><th class="border p-2 text-right">총합계<br><span class="text-[10px] font-normal">프로젝트 계약기간 전체 합계</span></th></tr></thead>
<tbody>
<tr class="bg-amber-100 font-bold"><td class="border p-2">매출금액(공정표 완료 기준)<br><span class="text-[10px] font-normal">공사 상황 탭 매출 기준과 동일</span></td><td class="border p-2"></td><td class="border p-2"></td><?php $revSum=0; foreach($allMonths as $ymAll){ $revSum+=(float)$monthlyRevenue[$ymAll]; } foreach($displayMonths as $ym){ $v=(float)$monthlyRevenue[$ym]; ?><td class="border p-2 text-right"><?php echo amount_fmt($v); ?></td><?php } ?><td class="border p-2 text-right"><?php echo amount_fmt($revSum); ?></td></tr>
<?php foreach($labels as $sec=>$title): ?>
<tr class="bg-[#f2dfcf] font-semibold"><td class="border p-2"><?php echo h($title); ?></td><td class="border p-2"></td><td class="border p-2"></td><?php foreach($displayMonths as $ym): ?><td class="border p-2"></td><?php endforeach; ?><td class="border p-2"></td></tr>
<?php if (count($rowsBySection[$sec]) === 0): ?>
<tr><td class="border p-2"></td><td class="border p-2 text-gray-500" colspan="2">데이터 없음</td><?php foreach($displayMonths as $ym): ?><td class="border p-2 text-right">-</td><?php endforeach; ?><td class="border p-2 text-right">-</td></tr>
<?php else: foreach($rowsBySection[$sec] as $row): ?>
<tr><td class="border p-2"></td><td class="border p-2"><?php echo h(isset($row['업체명'])?$row['업체명']:''); ?></td><td class="border p-2"><?php echo h(isset($row['내역'])?$row['내역']:''); ?></td><?php foreach($displayMonths as $ym): $v = isset($row['months'][$ym]) ? (float)$row['months'][$ym] : 0; ?><td class="border p-2 text-right"><?php echo amount_fmt($v); ?></td><?php endforeach; ?><td class="border p-2 text-right"><?php echo amount_fmt(row_total($row,$allMonths)); ?></td></tr>
<?php endforeach; endif; ?>
<tr class="bg-amber-50 font-semibold"><td class="border p-2"><?php echo h($title); ?> 소계</td><td class="border p-2"></td><td class="border p-2"></td><?php $secSum=0; foreach($allMonths as $ymAll){ $secSum += $sumBySection[$sec][$ymAll]; } foreach($displayMonths as $ym){ ?><td class="border p-2 text-right"><?php echo amount_fmt($sumBySection[$sec][$ym]); ?></td><?php } ?><td class="border p-2 text-right"><?php echo amount_fmt($secSum); ?></td></tr>
<?php endforeach; ?>
<tr class="bg-yellow-100 font-bold"><td class="border p-2">1차 합계</td><td class="border p-2"></td><td class="border p-2"></td><?php $s1=0; foreach($allMonths as $ymAll){ $s1 += $subtotal1[$ymAll]; } foreach($displayMonths as $ym){ ?><td class="border p-2 text-right"><?php echo amount_fmt($subtotal1[$ym]); ?></td><?php } ?><td class="border p-2 text-right"><?php echo amount_fmt($s1); ?></td></tr>
<tr class="bg-orange-100 font-bold"><td class="border p-2">최종 합계</td><td class="border p-2"></td><td class="border p-2"></td><?php $sf=0; foreach($allMonths as $ymAll){ $sf += $finalTotal[$ymAll]; } foreach($displayMonths as $ym){ ?><td class="border p-2 text-right"><?php echo amount_fmt($finalTotal[$ym]); ?></td><?php } ?><td class="border p-2 text-right"><?php echo amount_fmt($sf); ?></td></tr>
<tr class="font-bold"><td class="border p-2">손익</td><td class="border p-2"></td><td class="border p-2"></td><?php $sp=0; foreach($allMonths as $ymAll){ $sp += $profit[$ymAll]; } foreach($displayMonths as $ym){ $cls=$profit[$ym]<0?'text-red-600':'text-blue-700'; ?><td class="border p-2 text-right <?php echo $cls; ?>"><?php echo amount_fmt($profit[$ym]); ?></td><?php } $clsAll=$sp<0?'text-red-600':'text-blue-700'; ?><td class="border p-2 text-right <?php echo $clsAll; ?>"><?php echo amount_fmt($sp); ?></td></tr>
</tbody></table>
</div>

<div class="mt-4 p-3 border rounded-xl bg-gray-50">
<div class="font-semibold mb-2">공제분 입력</div>
<form method="post" action="?r=project/monthly_deduction_save" class="flex flex-wrap gap-2 items-center">
<input type="hidden" name="project_id" value="<?php echo (int)$selectedProjectId; ?>">
<input type="text" name="ym" placeholder="월(YYYY-MM)" class="px-2 py-1 border rounded w-32">
<input type="text" name="deduction_name" placeholder="공제항목" class="px-2 py-1 border rounded w-40">
<input type="text" name="amount" placeholder="금액" class="px-2 py-1 border rounded w-32 text-right">
<input type="text" name="memo" placeholder="메모" class="px-2 py-1 border rounded w-56">
<button type="submit" class="px-3 py-1 rounded bg-amber-700 text-white">공제분 저장</button>
</form>
<?php if (count($rowsBySection['공제분'])>0): ?><div class="mt-2 text-sm space-y-1"><?php foreach($rowsBySection['공제분'] as $d): ?>
<div><?php echo h($d['내역']); ?> / <?php echo amount_fmt(row_total($d,$allMonths)); ?>
<?php if (isset($d['id'])): ?><a class="text-red-600 ml-2" href="?r=project/monthly_deduction_delete&id=<?php echo (int)$d['id']; ?>&project_id=<?php echo (int)$selectedProjectId; ?>">삭제</a><?php endif; ?></div>
<?php endforeach; ?></div><?php endif; ?>
</div>

<?php endif; ?>
</div>
