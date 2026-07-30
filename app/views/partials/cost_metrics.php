<?php
/**
 * 공사/대시보드 공통: 원가/공정 지표 계산 함수
 * PHP 5.6 호환
 */

require_once __DIR__ . '/../../services/CostChangeService.php';

if (!function_exists('cpms_period_range')) {
    function cpms_period_range($period) {
        $today = date('Y-m-d');
        if ($period === 'month') {
            return array(date('Y-m-01'), date('Y-m-t'));
        }
        $n = (int)date('N');
        $start = date('Y-m-d', strtotime($today . ' -' . ($n - 1) . ' days'));
        $end = date('Y-m-d', strtotime($start . ' +6 days'));
        return array($start, $end);
    }
}

if (!function_exists('cpms_project_cost_metrics')) {
    function cpms_project_cost_metrics($pdo, $projectId, $period) {
        $ret = array('period' => $period, 'cost_rate' => null, 'cost_rate_label' => '-', 'cost_rate_note' => '', 'progress_rate' => 0,
            'internal_progress_amount' => 0, 'actual_total_cost' => 0, 'planned_labor' => 0, 'planned_material' => 0, 'planned_safety' => 0,
            'actual_labor' => 0, 'actual_material' => 0, 'actual_safety' => 0, 'variance_labor' => 0, 'variance_material' => 0, 'variance_safety' => 0,
            'cum_internal' => 0, 'cum_recognized' => 0, 'cum_gap' => 0, 'total_contract_amount' => 0, 'month_recognized' => 0);
        list($startDate, $endDate) = cpms_period_range($period);

        $st = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(qty,0)*COALESCE(unit_price,0)),0) FROM cpms_project_unit_prices WHERE project_id=:pid");
        $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT); $st->execute();
        $ret['total_contract_amount'] = (float)$st->fetchColumn();

        $sqlP = "SELECT
            COALESCE(SUM(dw.done_qty * COALESCE(up.unit_price,0)),0) AS internal_amt,
            COALESCE(SUM(dw.done_qty * COALESCE(up.labor_unit_price,0)),0) AS planned_labor,
            COALESCE(SUM(dw.done_qty * COALESCE(up.material_unit_price,0)),0) AS planned_material,
            COALESCE(SUM(dw.done_qty * (CASE WHEN up.is_safety=1 THEN COALESCE(up.safety_unit_price, up.labor_unit_price, 0) ELSE 0 END)),0) AS planned_safety
            FROM cpms_daily_work_qty dw JOIN cpms_project_unit_prices up ON up.id = dw.unit_price_id
            WHERE dw.project_id=:pid AND dw.work_date BETWEEN :sd AND :ed";
        $st = $pdo->prepare($sqlP);
        $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT); $st->bindValue(':sd', $startDate); $st->bindValue(':ed', $endDate); $st->execute();
        $pr = $st->fetch();
        if (is_array($pr)) {
            $ret['internal_progress_amount'] = (float)$pr['internal_amt'];
            $ret['planned_labor'] = (float)$pr['planned_labor'];
            $ret['planned_material'] = (float)$pr['planned_material'];
            $ret['planned_safety'] = (float)$pr['planned_safety'];
        }

        if ($period === 'month') {
            /*
             * 월간 지표는 실제 날짜의 월 숫자가 아니라 공통 귀속월(노무 달력월,
             * 그 외 26일~25일)과 승인으로 수동 이동된 귀속월을 사용한다.
             */
            $st = $pdo->prepare("SELECT id,cost_date,cost_type,amount FROM cpms_daily_cost_entries WHERE project_id=:pid");
            $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
            $st->execute();
            $actualRows = array();
            $wantedYm = substr($startDate, 0, 7);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $actualRow) {
                $targetId = isset($actualRow['id']) ? (string)$actualRow['id'] : '';
                if (\App\Services\CostChangeService::isTargetDeleted($pdo, 'daily_cost', $targetId)) continue;
                $typeForMonth = isset($actualRow['cost_type']) && (string)$actualRow['cost_type'] === '노무' ? 'labor' : 'daily_cost';
                $rowYm = \App\Services\CostChangeService::effectiveSettlementYm(
                    $pdo,
                    'daily_cost',
                    $targetId,
                    $typeForMonth,
                    isset($actualRow['cost_date']) ? (string)$actualRow['cost_date'] : ''
                );
                if ($rowYm !== $wantedYm) continue;
                $typeKey = isset($actualRow['cost_type']) ? (string)$actualRow['cost_type'] : '';
                if (!isset($actualRows[$typeKey])) $actualRows[$typeKey] = array('cost_type'=>$typeKey, 'amt'=>0.0);
                $actualRows[$typeKey]['amt'] += isset($actualRow['amount']) ? (float)$actualRow['amount'] : 0.0;
            }
            $actualRows = array_values($actualRows);
        } else {
            $sqlA = "SELECT cost_type, COALESCE(SUM(amount),0) amt FROM cpms_daily_cost_entries WHERE project_id=:pid AND cost_date BETWEEN :sd AND :ed GROUP BY cost_type";
            $st = $pdo->prepare($sqlA);
            $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT);
            $st->bindValue(':sd', $startDate);
            $st->bindValue(':ed', $endDate);
            $st->execute();
            $actualRows = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        $actualTotal = 0;
        foreach ($actualRows as $r) {
            $t = trim((string)$r['cost_type']); $amt = (float)$r['amt'];
            $actualTotal += $amt;
            if ($t === '노무') $ret['actual_labor'] = $amt;
            if ($t === '자재') $ret['actual_material'] = $amt;
            if ($t === '안전') $ret['actual_safety'] = $amt;
        }
        $ret['actual_total_cost'] = $actualTotal;
        $ret['variance_labor'] = $ret['actual_labor'] - $ret['planned_labor'];
        $ret['variance_material'] = $ret['actual_material'] - $ret['planned_material'];
        $ret['variance_safety'] = $ret['actual_safety'] - $ret['planned_safety'];

        $st = $pdo->prepare("SELECT COALESCE(SUM(dw.done_qty * COALESCE(up.unit_price,0)),0) FROM cpms_daily_work_qty dw JOIN cpms_project_unit_prices up ON up.id=dw.unit_price_id WHERE dw.project_id=:pid AND dw.work_date<=:ed");
        $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT); $st->bindValue(':ed', $endDate); $st->execute();
        $ret['cum_internal'] = (float)$st->fetchColumn();

        $curYm = date('Y-m', strtotime($endDate));
        $prevYm = date('Y-m', strtotime($curYm . '-01 -1 month'));
        $st = $pdo->prepare("SELECT COALESCE(MAX(recognized_cum_amount),0) FROM cpms_monthly_recognized WHERE project_id=:pid AND ym<=:ym");
        $st->bindValue(':pid', (int)$projectId, PDO::PARAM_INT); $st->bindValue(':ym', $curYm); $st->execute();
        $ret['cum_recognized'] = (float)$st->fetchColumn();

        if ($period === 'month') {
            $st1 = $pdo->prepare("SELECT recognized_cum_amount FROM cpms_monthly_recognized WHERE project_id=:pid AND ym=:ym LIMIT 1");
            $st1->bindValue(':pid', (int)$projectId, PDO::PARAM_INT); $st1->bindValue(':ym', $curYm); $st1->execute();
            $thisCum = (float)$st1->fetchColumn();
            $st2 = $pdo->prepare("SELECT recognized_cum_amount FROM cpms_monthly_recognized WHERE project_id=:pid AND ym=:ym LIMIT 1");
            $st2->bindValue(':pid', (int)$projectId, PDO::PARAM_INT); $st2->bindValue(':ym', $prevYm); $st2->execute();
            $prevCum = (float)$st2->fetchColumn();
            $ret['month_recognized'] = $thisCum - $prevCum;
            if ($ret['month_recognized'] > 0) {
                $ret['cost_rate'] = ($actualTotal / $ret['month_recognized']) * 100;
                $ret['cost_rate_label'] = number_format($ret['cost_rate'], 2) . '%';
            } else {
                $ret['cost_rate_note'] = '인정기성 없음';
            }
        } else {
            if ($ret['internal_progress_amount'] > 0) {
                $ret['cost_rate'] = ($actualTotal / $ret['internal_progress_amount']) * 100;
                $ret['cost_rate_label'] = number_format($ret['cost_rate'], 2) . '%';
            } else {
                $ret['cost_rate_note'] = '기성 없음';
            }
        }

        if ($ret['total_contract_amount'] > 0) {
            $ret['progress_rate'] = ($ret['internal_progress_amount'] / $ret['total_contract_amount']) * 100;
            $ret['official_progress_rate'] = ($ret['cum_recognized'] / $ret['total_contract_amount']) * 100;
        } else {
            $ret['official_progress_rate'] = 0;
        }
        $ret['cum_gap'] = $ret['cum_internal'] - $ret['cum_recognized'];
        return $ret;
    }
}
