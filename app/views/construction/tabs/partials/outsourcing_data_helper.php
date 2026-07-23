<?php
/**
 * 공사 > 외주비 공통 데이터 도우미
 * - 노무비 인원작성에서 설정한 월별 외주비 비율만큼의 공수 지급액
 * - 공사 > 외주비 입력 금액
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/labor_data_loader.php';

if (!function_exists('cpms_outsourcing_cost_ensure_table')) {
    function cpms_outsourcing_cost_ensure_table($pdo) {
        if (!$pdo) return false;
        static $ensured = array();
        $cacheKey = function_exists('spl_object_hash') ? spl_object_hash($pdo) : 'default';
        if (isset($ensured[$cacheKey])) return $ensured[$cacheKey];
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_outsourcing_costs (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                project_id INT UNSIGNED NOT NULL,
                expense_date DATE NOT NULL,
                category VARCHAR(20) NOT NULL DEFAULT '외주비',
                company_name VARCHAR(120) NOT NULL,
                representative_name VARCHAR(100) NULL,
                business_no VARCHAR(30) NULL,
                contact VARCHAR(50) NULL,
                amount DECIMAL(15,2) NOT NULL DEFAULT 0,
                created_by_name VARCHAR(100) NULL,
                created_by_email VARCHAR(190) NULL,
                is_deleted TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                KEY idx_outsourcing_project_date (project_id, expense_date, is_deleted)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
            $ensured[$cacheKey] = true;
            return true;
        } catch (Exception $e) {
            $ensured[$cacheKey] = false;
            return false;
        }
    }
}

if (!function_exists('cpms_outsourcing_money')) {
    function cpms_outsourcing_money($value) {
        $raw = preg_replace('/[^0-9.\-]/', '', trim((string)$value));
        if ($raw === '' || !is_numeric($raw)) return 0.0;
        return (float)$raw;
    }
}

if (!function_exists('cpms_outsourcing_valid_date')) {
    function cpms_outsourcing_valid_date($value) {
        $value = trim((string)$value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return '';
        $parts = explode('-', $value);
        if (count($parts) !== 3 || !checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) return '';
        return $value;
    }
}

if (!function_exists('cpms_outsourcing_manual_rows')) {
    function cpms_outsourcing_manual_rows($pdo, $projectId, $startDate, $endDate) {
        $rows = array();
        if (!$pdo || (int)$projectId <= 0) return $rows;
        if (!cpms_outsourcing_cost_ensure_table($pdo)) return $rows;
        try {
            $sql = "SELECT * FROM cpms_outsourcing_costs
                    WHERE project_id = :pid
                      AND is_deleted = 0";
            if ($startDate !== '' && $endDate !== '') {
                $sql .= " AND expense_date BETWEEN :start_date AND :end_date";
            }
            $sql .= " ORDER BY expense_date DESC, id DESC";
            $st = $pdo->prepare($sql);
            $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
            if ($startDate !== '' && $endDate !== '') {
                $st->bindValue(':start_date', (string)$startDate);
                $st->bindValue(':end_date', (string)$endDate);
            }
            $st->execute();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows)) $rows = array();
        } catch (Exception $e) {
            $rows = array();
        }
        return $rows;
    }
}

if (!function_exists('cpms_outsourcing_manual_total_between')) {
    function cpms_outsourcing_manual_total_between($pdo, $projectId, $startDate, $endDate) {
        if (!$pdo || (int)$projectId <= 0) return 0.0;
        if (!cpms_outsourcing_cost_ensure_table($pdo)) return 0.0;
        static $cache = array();
        $pdoKey = function_exists('spl_object_hash') ? spl_object_hash($pdo) : 'default';
        $cacheKey = $pdoKey . ':' . (int)$projectId . ':' . (string)$startDate . ':' . (string)$endDate;
        if (isset($cache[$cacheKey])) return $cache[$cacheKey];
        try {
            $st = $pdo->prepare("SELECT COALESCE(SUM(amount), 0)
                                 FROM cpms_outsourcing_costs
                                 WHERE project_id = :pid
                                   AND is_deleted = 0
                                   AND expense_date BETWEEN :start_date AND :end_date");
            $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
            $st->bindValue(':start_date', (string)$startDate);
            $st->bindValue(':end_date', (string)$endDate);
            $st->execute();
            $cache[$cacheKey] = (float)$st->fetchColumn();
            return $cache[$cacheKey];
        } catch (Exception $e) {
            $cache[$cacheKey] = 0.0;
            return 0.0;
        }
    }
}

if (!function_exists('cpms_outsourcing_labor_company_rows_for_month')) {
    function cpms_outsourcing_labor_company_rows_for_month($pdo, $projectId, $projectName, $ym) {
        $result = array('rows' => array(), 'total' => 0.0, 'worker_count' => 0);
        if (!$pdo || (int)$projectId <= 0 || trim((string)$projectName) === '') return $result;
        if (!preg_match('/^\d{4}-\d{2}$/', (string)$ym)) return $result;

        $directMembers = cpms_load_direct_team_members($pdo);
        $projectWorkers = cpms_load_project_labor_workers($pdo, (int)$projectId);
        $ratioMap = cpms_load_project_labor_worker_month_ratio_map($pdo, (int)$projectId, (string)$ym, $projectWorkers);
        $projectWorkers = cpms_apply_project_labor_worker_month_ratios($projectWorkers, $ratioMap);
        $workerRows = cpms_build_project_worker_rows($projectWorkers, $directMembers);
        $workers = cpms_build_timesheet_workers($workerRows);
        $gongsuData = cpms_load_gongsu_data($pdo, (string)$projectName, (string)$ym);
        $gongsuMap = isset($gongsuData['gongsu_map']) && is_array($gongsuData['gongsu_map']) ? $gongsuData['gongsu_map'] : array();
        $outputDays = isset($gongsuData['output_days']) && is_array($gongsuData['output_days']) ? $gongsuData['output_days'] : array();
        $gongsuUnit = isset($gongsuData['gongsu_unit']) && is_array($gongsuData['gongsu_unit']) ? $gongsuData['gongsu_unit'] : array();
        if (function_exists('cpms_apply_labor_overrides_to_dataset')) {
            $overrideData = cpms_apply_labor_overrides_to_dataset($gongsuMap, $outputDays, $gongsuUnit, (int)$projectId, (string)$ym);
            $gongsuMap = isset($overrideData['gongsu_map']) && is_array($overrideData['gongsu_map']) ? $overrideData['gongsu_map'] : array();
        } else if (function_exists('cpms_apply_labor_overrides_to_map')) {
            $gongsuMap = cpms_apply_labor_overrides_to_map($gongsuMap, (int)$projectId, (string)$ym);
        }

        $companyMap = array();
        foreach ($workers as $worker) {
            $outsourcingRatio = cpms_resolve_worker_outsourcing_ratio($worker);
            if ($outsourcingRatio <= 0) continue;
            $workerName = isset($worker['name']) ? trim((string)$worker['name']) : '';
            $workerKey = cpms_normalize_worker_key($workerName);
            if ($workerKey === '') continue;
            $dailyMap = isset($gongsuMap[$workerKey]) && is_array($gongsuMap[$workerKey]) ? $gongsuMap[$workerKey] : array();
            $totalGongsu = 0.0;
            $lastWorkDate = '';
            foreach ($dailyMap as $dateKey => $gongsuValue) {
                if (strpos((string)$dateKey, (string)$ym) !== 0 || !is_numeric($gongsuValue)) continue;
                $gongsu = (float)$gongsuValue;
                if ($gongsu <= 0) continue;
                $totalGongsu += $gongsu;
                if ($lastWorkDate === '' || (string)$dateKey > $lastWorkDate) $lastWorkDate = (string)$dateKey;
            }
            if ($totalGongsu <= 0) continue;
            $wageRate = function_exists('cpms_resolve_labor_wage_rate') ? (float)cpms_resolve_labor_wage_rate($worker) : cpms_outsourcing_money(isset($worker['deposit_rate']) ? $worker['deposit_rate'] : 0);
            // 파일: app/views/construction/tabs/partials/outsourcing_data_helper.php
            // 분할 인원은 공수를 나누지 않고 전체 지급총액에서 외주비 반영금액만 가져옵니다.
            $amounts = cpms_labor_calculate_amounts($totalGongsu, $wageRate, $outsourcingRatio);
            $amount = isset($amounts['outsourcing_amount']) ? (float)$amounts['outsourcing_amount'] : 0.0;
            if ($amount <= 0) continue;
            $companyName = isset($worker['company_name']) ? trim((string)$worker['company_name']) : '';
            if ($companyName === '') $companyName = '창명건설';
            $companyKey = function_exists('mb_strtolower') ? mb_strtolower($companyName, 'UTF-8') : strtolower($companyName);
            if (!isset($companyMap[$companyKey])) {
                $companyMap[$companyKey] = array(
                    'expense_date' => $lastWorkDate !== '' ? $lastWorkDate : ($ym . '-01'),
                    'category' => '인원 외주비',
                    'company_name' => $companyName,
                    'representative_name' => '',
                    'business_no' => '',
                    'contact' => '',
                    'amount' => 0.0,
                    'worker_names' => array(),
                    'contacts' => array(),
                );
            }
            if ($lastWorkDate > $companyMap[$companyKey]['expense_date']) $companyMap[$companyKey]['expense_date'] = $lastWorkDate;
            $companyMap[$companyKey]['amount'] += $amount;
            if ($workerName !== '') $companyMap[$companyKey]['worker_names'][$workerKey] = $workerName;
            $contact = isset($worker['phone']) ? trim((string)$worker['phone']) : '';
            if ($contact !== '') $companyMap[$companyKey]['contacts'][$contact] = $contact;
            $result['total'] += $amount;
            $result['worker_count']++;
        }

        foreach ($companyMap as $companyRow) {
            $contacts = isset($companyRow['contacts']) && is_array($companyRow['contacts']) ? array_values($companyRow['contacts']) : array();
            $companyRow['contact'] = count($contacts) === 1 ? $contacts[0] : '';
            $companyRow['worker_names'] = isset($companyRow['worker_names']) && is_array($companyRow['worker_names']) ? array_values($companyRow['worker_names']) : array();
            unset($companyRow['contacts']);
            $result['rows'][] = $companyRow;
        }
        usort($result['rows'], function($a, $b) {
            return strcmp((string)$a['company_name'], (string)$b['company_name']);
        });
        return $result;
    }
}
