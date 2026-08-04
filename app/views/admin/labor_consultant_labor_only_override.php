<?php
/**
 * C:\www\cpms\app\views\admin\labor_consultant_labor_only_override.php
 *
 * 관리 > 노무비 계산 > 노무사 확인용 전용 데이터 조립
 * - 공사 > 노무비 > 노무비 탭에 표시되는 날짜와 공수만 사용
 * - 날짜로 선택한 외주비 기간은 일별 공수, 출력일수, 총공수에서 제외
 * - 전액 외주비 인원은 제외
 * - 비율 배분 인원은 공사 노무비 탭과 동일하게 전체 공수를 표시하고
 *   노무비 반영금액만 설정 비율로 계산
 * - PHP 5.6 호환
 */

if (!function_exists('cpms_labor_consultant_is_date_mode')) {
    function cpms_labor_consultant_is_date_mode($worker) {
        if (!is_array($worker)) return false;

        $ratio = function_exists('cpms_resolve_worker_outsourcing_ratio')
            ? (int)cpms_resolve_worker_outsourcing_ratio($worker)
            : (isset($worker['outsourcing_ratio']) ? (int)$worker['outsourcing_ratio'] : 0);

        $start = isset($worker['outsourcing_start_date'])
            ? trim((string)$worker['outsourcing_start_date'])
            : '';
        $end = isset($worker['outsourcing_end_date'])
            ? trim((string)$worker['outsourcing_end_date'])
            : '';

        if ($ratio !== 100) return false;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) return false;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) return false;
        return $start <= $end;
    }
}

if (!function_exists('cpms_labor_consultant_show_labor_date')) {
    function cpms_labor_consultant_show_labor_date($worker, $dateKey) {
        $dateKey = trim((string)$dateKey);
        if ($dateKey === '') return false;
        if (!cpms_labor_consultant_is_date_mode($worker)) return true;

        $start = trim((string)$worker['outsourcing_start_date']);
        $end = trim((string)$worker['outsourcing_end_date']);
        return !($dateKey >= $start && $dateKey <= $end);
    }
}

/**
 * 이 함수는 labor_consultant_helpers.php보다 먼저 선언된다.
 * 기존 helper는 function_exists 검사 후 같은 함수를 다시 선언하지 않는다.
 */
if (!function_exists('cpms_labor_consultant_load_project_month_rows')) {
    function cpms_labor_consultant_load_project_month_rows($pdo, $projectRow, $ym) {
        require_once __DIR__ . '/../construction/tabs/partials/labor_data_loader.php';

        $rows = array();
        if (!$pdo || !is_array($projectRow)) return $rows;

        $projectId = isset($projectRow['id']) ? (int)$projectRow['id'] : 0;
        $projectName = isset($projectRow['name']) ? trim((string)$projectRow['name']) : '';
        if ($projectId <= 0 || $projectName === '') return $rows;
        $projectManagerName = cpms_labor_consultant_project_manager_name($pdo, $projectId);

        $daysInMonth = cpms_labor_consultant_days_in_month($ym);
        $gongsuData = cpms_load_gongsu_data($pdo, $projectName, $ym);
        $dataset = cpms_apply_labor_overrides_to_dataset(
            isset($gongsuData['gongsu_map']) ? $gongsuData['gongsu_map'] : array(),
            isset($gongsuData['output_days']) ? $gongsuData['output_days'] : array(),
            isset($gongsuData['gongsu_unit']) ? $gongsuData['gongsu_unit'] : array(),
            $projectId,
            $ym
        );

        $attendanceWorkers = array();
        if (isset($gongsuData['all_workers']) && is_array($gongsuData['all_workers']) && count($gongsuData['all_workers']) > 0) {
            $attendanceWorkers = $gongsuData['all_workers'];
        } else if (isset($gongsuData['workers']) && is_array($gongsuData['workers'])) {
            $attendanceWorkers = $gongsuData['workers'];
        }

        $excludedWorkers = isset($gongsuData['excluded_workers']) && is_array($gongsuData['excluded_workers'])
            ? $gongsuData['excluded_workers']
            : array();
        cpms_cleanup_project_labor_workers($pdo, $projectId, $excludedWorkers);
        cpms_sync_project_labor_workers_from_attendance($pdo, $projectId, $attendanceWorkers);

        $projectWorkers = cpms_load_project_labor_workers($pdo, $projectId);
        $laborWorkerRatioMap = cpms_load_project_labor_worker_month_ratio_map($pdo, $projectId, $ym, $projectWorkers);
        $projectWorkers = cpms_apply_project_labor_worker_month_ratios($projectWorkers, $laborWorkerRatioMap);
        $projectWorkers = cpms_labor_consultant_unique_project_workers($projectWorkers);

        $directTeamMembers = cpms_load_direct_team_members($pdo);
        $directMemberMap = cpms_labor_consultant_direct_member_map($directTeamMembers);
        $workerRows = cpms_build_project_worker_rows($projectWorkers, $directTeamMembers);
        $timesheetWorkers = cpms_build_timesheet_workers($workerRows);
        $roleMap = isset($gongsuData['role_map']) && is_array($gongsuData['role_map'])
            ? $gongsuData['role_map']
            : array();
        $gongsuMap = isset($dataset['gongsu_map']) && is_array($dataset['gongsu_map'])
            ? $dataset['gongsu_map']
            : array();
        $processedWorkerKeys = array();

        foreach ($timesheetWorkers as $worker) {
            $workerName = isset($worker['name']) ? trim((string)$worker['name']) : '';
            if ($workerName === '') continue;

            $workerKey = function_exists('cpms_normalize_worker_key')
                ? cpms_normalize_worker_key($workerName)
                : strtolower($workerName);
            if ($workerKey === '') continue;
            if (isset($processedWorkerKeys[$workerKey])) continue;
            $processedWorkerKeys[$workerKey] = true;

            $dailyMap = isset($gongsuMap[$workerKey]) && is_array($gongsuMap[$workerKey])
                ? $gongsuMap[$workerKey]
                : array();
            $days = array();
            $totalGongsu = 0.0;
            $outputDays = 0;

            $d = 1;
            while ($d <= $daysInMonth) {
                $dateKey = $ym . '-' . str_pad((string)$d, 2, '0', STR_PAD_LEFT);

                // 공사 > 노무비 > 노무비 탭에서 숨기는 외주비 날짜는 관리 화면에서도 제외한다.
                if (!cpms_labor_consultant_show_labor_date($worker, $dateKey)) {
                    $days[$d] = '';
                    $d++;
                    continue;
                }

                $value = isset($dailyMap[$dateKey]) && is_numeric($dailyMap[$dateKey])
                    ? (float)$dailyMap[$dateKey]
                    : 0.0;
                if ($value > 0) {
                    $days[$d] = round($value, 2);
                    $totalGongsu += (float)$days[$d];
                    $outputDays++;
                } else {
                    $days[$d] = '';
                }
                $d++;
            }

            if ($totalGongsu <= 0) continue;

            $wageRate = function_exists('cpms_resolve_labor_wage_rate')
                ? (float)cpms_resolve_labor_wage_rate($worker)
                : 0.0;
            $outsourcingRatio = function_exists('cpms_resolve_worker_outsourcing_ratio')
                ? (int)cpms_resolve_worker_outsourcing_ratio($worker)
                : ((isset($worker['is_outsourcing']) && (int)$worker['is_outsourcing'] === 1) ? 100 : 0);

            $laborAmounts = function_exists('cpms_labor_calculate_worker_month_amounts')
                ? cpms_labor_calculate_worker_month_amounts($worker, $gongsuMap, $ym)
                : array(
                    'total_amount' => round($totalGongsu * $wageRate),
                    'outsourcing_ratio' => $outsourcingRatio,
                    'labor_ratio' => 100 - $outsourcingRatio,
                    'outsourcing_amount' => round(round($totalGongsu * $wageRate) * $outsourcingRatio / 100),
                    'labor_amount' => round($totalGongsu * $wageRate) - round(round($totalGongsu * $wageRate) * $outsourcingRatio / 100),
                );

            $laborAmount = isset($laborAmounts['labor_amount']) ? (float)$laborAmounts['labor_amount'] : 0.0;
            if ($laborAmount <= 0) continue;

            // 지급총액은 현재 노무비 탭에서 실제 표시되는 공수 기준이다.
            $visibleTotalAmount = round($totalGongsu * $wageRate);

            $rows[count($rows)] = array(
                'project_id' => $projectId,
                'project_name' => $projectName,
                'project_start_date' => isset($projectRow['start_date']) ? (string)$projectRow['start_date'] : '',
                'project_end_date' => isset($projectRow['end_date']) ? (string)$projectRow['end_date'] : '',
                'project_manager_name' => $projectManagerName,
                'worker_name' => $workerName,
                'role' => isset($roleMap[$workerKey]) ? (string)$roleMap[$workerKey] : '',
                'phone' => cpms_labor_consultant_worker_detail_value($worker, $directMemberMap, $workerKey, 'phone', ''),
                'address' => cpms_labor_consultant_worker_detail_value($worker, $directMemberMap, $workerKey, 'address', ''),
                'resident_no' => cpms_labor_consultant_worker_detail_value($worker, $directMemberMap, $workerKey, 'resident_no', ''),
                'account_holder' => cpms_labor_consultant_worker_detail_value($worker, $directMemberMap, $workerKey, 'account_holder', ''),
                'bank_name' => cpms_labor_consultant_worker_detail_value($worker, $directMemberMap, $workerKey, 'bank_name', ''),
                'bank_account' => cpms_labor_consultant_worker_detail_value($worker, $directMemberMap, $workerKey, 'bank_account', ''),
                'company_name' => cpms_labor_consultant_worker_detail_value($worker, $directMemberMap, $workerKey, 'company_name', '창명건설'),
                'subcontract_type' => '',
                'foreigner' => '',
                'wage_rate' => $wageRate,
                'work_days_count' => $outputDays,
                'output_days' => $outputDays,
                'total_gongsu' => round($totalGongsu, 2),
                'total_amount' => (float)$visibleTotalAmount,
                'labor_ratio' => isset($laborAmounts['labor_ratio']) ? (int)$laborAmounts['labor_ratio'] : (100 - $outsourcingRatio),
                'labor_amount' => $laborAmount,
                'outsourcing_ratio' => isset($laborAmounts['outsourcing_ratio']) ? (int)$laborAmounts['outsourcing_ratio'] : $outsourcingRatio,
                'outsourcing_amount' => isset($laborAmounts['outsourcing_amount']) ? (float)$laborAmounts['outsourcing_amount'] : 0.0,
                'amount' => $laborAmount,
                'days' => $days,
            );
        }

        return $rows;
    }
}
