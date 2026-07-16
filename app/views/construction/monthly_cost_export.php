<?php
/**
 * 공사 > 월별 투입비 통합 엑셀 다운로드
 * - 월별 투입비 상세내역 / 월별 자재구입비 / 장비월별 / 월별 외주비
 * - PHP 5.6 호환
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../services/ConstructionMonthlyCostXlsx.php';

use App\Core\Auth;
use App\Core\Db;
use App\Services\ConstructionMonthlyCostXlsx;

function cpms_construction_cost_export_error($status, $message)
{
    while (ob_get_level() > 0) {
        if (!@ob_end_clean()) break;
    }
    http_response_code((int)$status);
    header('Content-Type: text/plain; charset=utf-8');
    echo (string)$message;
    exit;
}

function cpms_construction_cost_export_department($department)
{
    $department = trim((string)$department);
    if ($department === '관리부' || $department === '관리팀') return '관리';
    if ($department === '공무부' || $department === '공무팀') return '공무';
    if ($department === '공사부' || $department === '공사팀') return '공사';
    return $department;
}

function cpms_construction_cost_export_column($index)
{
    $index = max(1, (int)$index);
    $name = '';
    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $name = chr(65 + $mod) . $name;
        $index = (int)(($index - 1) / 26);
    }
    return $name;
}

function cpms_construction_cost_export_cells($count, $style)
{
    $cells = array();
    for ($index = 0; $index < (int)$count; $index++) {
        $cells[] = ConstructionMonthlyCostXlsx::cell('', $style);
    }
    return $cells;
}

function cpms_construction_cost_export_set(&$cells, $index, $value, $style)
{
    $cells[(int)$index] = ConstructionMonthlyCostXlsx::cell($value, $style);
}

function cpms_construction_cost_export_row($cells, $height)
{
    return array('cells' => $cells, 'height' => (float)$height);
}

function cpms_construction_cost_export_row_total($row, $months)
{
    $total = 0.0;
    foreach ($months as $month) {
        $total += isset($row['months'][$month]) ? (float)$row['months'][$month] : 0.0;
    }
    return $total;
}

function cpms_construction_cost_export_sheet_input($data)
{
    $displayMonths = isset($data['display_months']) && is_array($data['display_months']) ? $data['display_months'] : array();
    $allMonths = isset($data['all_months']) && is_array($data['all_months']) ? $data['all_months'] : $displayMonths;
    $rowsBySection = isset($data['rows_by_section']) && is_array($data['rows_by_section']) ? $data['rows_by_section'] : array();
    $labels = isset($data['labels']) && is_array($data['labels']) ? $data['labels'] : array();
    $sumBySection = isset($data['sum_by_section']) && is_array($data['sum_by_section']) ? $data['sum_by_section'] : array();
    $monthlyRevenue = isset($data['monthly_revenue']) && is_array($data['monthly_revenue']) ? $data['monthly_revenue'] : array();
    $subtotal1 = isset($data['subtotal1']) && is_array($data['subtotal1']) ? $data['subtotal1'] : array();
    $finalTotal = isset($data['final_total']) && is_array($data['final_total']) ? $data['final_total'] : array();
    $profit = isset($data['profit']) && is_array($data['profit']) ? $data['profit'] : array();
    $columnCount = 4 + count($displayMonths) - 0;
    $lastColumn = cpms_construction_cost_export_column($columnCount);
    $rows = array();
    $merges = array();

    $cells = cpms_construction_cost_export_cells($columnCount, 1);
    cpms_construction_cost_export_set($cells, 0, '월별 투입비 상세내역', 1);
    $rows[] = cpms_construction_cost_export_row($cells, 30);
    $merges[] = 'A1:' . $lastColumn . '1';

    $cells = cpms_construction_cost_export_cells($columnCount, 3);
    cpms_construction_cost_export_set($cells, 0, '현장', 2);
    cpms_construction_cost_export_set($cells, 1, isset($data['project_name']) ? $data['project_name'] : '-', 3);
    $rows[] = cpms_construction_cost_export_row($cells, 22);
    $merges[] = 'B2:' . $lastColumn . '2';

    $periodLabel = isset($data['selected_view_month']) && $data['selected_view_month'] === 'all' ? '전체보기' : (isset($data['selected_view_month']) ? str_replace('-', '.', $data['selected_view_month']) : '-');
    $cells = cpms_construction_cost_export_cells($columnCount, 3);
    cpms_construction_cost_export_set($cells, 0, '조회 기준', 2);
    cpms_construction_cost_export_set($cells, 1, $periodLabel, 3);
    $rows[] = cpms_construction_cost_export_row($cells, 22);
    $merges[] = 'B3:' . $lastColumn . '3';

    $rows[] = cpms_construction_cost_export_row(cpms_construction_cost_export_cells($columnCount, 0), 8);

    $cells = cpms_construction_cost_export_cells($columnCount, 4);
    cpms_construction_cost_export_set($cells, 0, '구분', 4);
    cpms_construction_cost_export_set($cells, 1, '업체명', 4);
    cpms_construction_cost_export_set($cells, 2, '내역', 4);
    foreach ($displayMonths as $index => $month) {
        cpms_construction_cost_export_set($cells, 3 + $index, str_replace('-', '.', $month), 4);
    }
    cpms_construction_cost_export_set($cells, $columnCount - 1, "총합계\n프로젝트 계약기간 전체 합계", 4);
    $rows[] = cpms_construction_cost_export_row($cells, 34);

    $cells = cpms_construction_cost_export_cells($columnCount, 10);
    cpms_construction_cost_export_set($cells, 0, '매출금액(기성관리 인정금액 우선)', 9);
    foreach ($displayMonths as $index => $month) {
        cpms_construction_cost_export_set($cells, 3 + $index, isset($monthlyRevenue[$month]) ? (float)$monthlyRevenue[$month] : 0.0, 10);
    }
    $revenueTotal = 0.0;
    foreach ($allMonths as $month) $revenueTotal += isset($monthlyRevenue[$month]) ? (float)$monthlyRevenue[$month] : 0.0;
    cpms_construction_cost_export_set($cells, $columnCount - 1, $revenueTotal, 10);
    $rows[] = cpms_construction_cost_export_row($cells, 23);

    foreach ($labels as $section => $title) {
        $cells = cpms_construction_cost_export_cells($columnCount, 5);
        cpms_construction_cost_export_set($cells, 0, $title, 5);
        $rows[] = cpms_construction_cost_export_row($cells, 22);

        $sectionRows = isset($rowsBySection[$section]) && is_array($rowsBySection[$section]) ? $rowsBySection[$section] : array();
        if (count($sectionRows) === 0) {
            $cells = cpms_construction_cost_export_cells($columnCount, 23);
            cpms_construction_cost_export_set($cells, 1, '데이터 없음', 6);
            foreach ($displayMonths as $index => $month) cpms_construction_cost_export_set($cells, 3 + $index, 0.0, 8);
            cpms_construction_cost_export_set($cells, $columnCount - 1, 0.0, 8);
            $rowNumber = count($rows) + 1;
            $rows[] = cpms_construction_cost_export_row($cells, 22);
            $merges[] = 'B' . $rowNumber . ':C' . $rowNumber;
        } else {
            foreach ($sectionRows as $detailRow) {
                $detailText = isset($detailRow['내역']) ? (string)$detailRow['내역'] : '';
                if (count($displayMonths) === 1 && isset($detailRow['details_by_month']) && is_array($detailRow['details_by_month'])) {
                    $detailMonth = $displayMonths[0];
                    if (isset($detailRow['details_by_month'][$detailMonth]) && trim((string)$detailRow['details_by_month'][$detailMonth]) !== '') {
                        $detailText = (string)$detailRow['details_by_month'][$detailMonth];
                    }
                }
                if (isset($detailRow['내역_html'])) {
                    $htmlText = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$detailRow['내역_html'])));
                    if ($htmlText !== '') $detailText = $htmlText;
                }
                $cells = cpms_construction_cost_export_cells($columnCount, 6);
                cpms_construction_cost_export_set($cells, 1, isset($detailRow['업체명']) ? (string)$detailRow['업체명'] : '', 6);
                cpms_construction_cost_export_set($cells, 2, $detailText, 6);
                foreach ($displayMonths as $index => $month) {
                    cpms_construction_cost_export_set($cells, 3 + $index, isset($detailRow['months'][$month]) ? (float)$detailRow['months'][$month] : 0.0, 8);
                }
                cpms_construction_cost_export_set($cells, $columnCount - 1, cpms_construction_cost_export_row_total($detailRow, $allMonths), 8);
                $rows[] = cpms_construction_cost_export_row($cells, 23);
            }
        }

        $cells = cpms_construction_cost_export_cells($columnCount, 12);
        cpms_construction_cost_export_set($cells, 0, $title . ' 소계', 11);
        $sectionTotal = 0.0;
        foreach ($allMonths as $month) $sectionTotal += isset($sumBySection[$section][$month]) ? (float)$sumBySection[$section][$month] : 0.0;
        foreach ($displayMonths as $index => $month) {
            cpms_construction_cost_export_set($cells, 3 + $index, isset($sumBySection[$section][$month]) ? (float)$sumBySection[$section][$month] : 0.0, 12);
        }
        cpms_construction_cost_export_set($cells, $columnCount - 1, $sectionTotal, 12);
        $rows[] = cpms_construction_cost_export_row($cells, 23);
    }

    $totals = array(
        array('1차 합계', $subtotal1, 13, 14),
        array('최종 합계', $finalTotal, 15, 16)
    );
    foreach ($totals as $totalDefinition) {
        $cells = cpms_construction_cost_export_cells($columnCount, $totalDefinition[3]);
        cpms_construction_cost_export_set($cells, 0, $totalDefinition[0], $totalDefinition[2]);
        $grandTotal = 0.0;
        foreach ($allMonths as $month) $grandTotal += isset($totalDefinition[1][$month]) ? (float)$totalDefinition[1][$month] : 0.0;
        foreach ($displayMonths as $index => $month) cpms_construction_cost_export_set($cells, 3 + $index, isset($totalDefinition[1][$month]) ? (float)$totalDefinition[1][$month] : 0.0, $totalDefinition[3]);
        cpms_construction_cost_export_set($cells, $columnCount - 1, $grandTotal, $totalDefinition[3]);
        $rows[] = cpms_construction_cost_export_row($cells, 24);
    }

    $cells = cpms_construction_cost_export_cells($columnCount, 17);
    cpms_construction_cost_export_set($cells, 0, '손익', 17);
    $profitTotal = 0.0;
    foreach ($allMonths as $month) $profitTotal += isset($profit[$month]) ? (float)$profit[$month] : 0.0;
    foreach ($displayMonths as $index => $month) {
        $value = isset($profit[$month]) ? (float)$profit[$month] : 0.0;
        cpms_construction_cost_export_set($cells, 3 + $index, $value, $value < 0 ? 19 : 18);
    }
    cpms_construction_cost_export_set($cells, $columnCount - 1, $profitTotal, $profitTotal < 0 ? 19 : 18);
    $rows[] = cpms_construction_cost_export_row($cells, 24);

    $widths = array(32, 24, 34);
    foreach ($displayMonths as $month) $widths[] = 15;
    $widths[] = 20;

    return array('name'=>'월별 투입비 상세내역', 'rows'=>$rows, 'merges'=>$merges, 'widths'=>$widths, 'column_count'=>$columnCount, 'freeze'=>array('x'=>3, 'y'=>5), 'orientation'=>'landscape');
}

function cpms_construction_cost_export_sheet_materials($data)
{
    $columnCount = 10;
    $rows = array();
    $merges = array('A1:J1', 'B2:J2', 'B3:J3');
    $cells = cpms_construction_cost_export_cells($columnCount, 1);
    cpms_construction_cost_export_set($cells, 0, '월별 자재구입비', 1);
    $rows[] = cpms_construction_cost_export_row($cells, 30);
    $cells = cpms_construction_cost_export_cells($columnCount, 3);
    cpms_construction_cost_export_set($cells, 0, '현장', 2);
    cpms_construction_cost_export_set($cells, 1, isset($data['project_name']) ? $data['project_name'] : '-', 3);
    $rows[] = cpms_construction_cost_export_row($cells, 22);
    $cells = cpms_construction_cost_export_cells($columnCount, 3);
    cpms_construction_cost_export_set($cells, 0, '사용기간', 2);
    cpms_construction_cost_export_set($cells, 1, (isset($data['start']) ? $data['start'] : '') . ' ~ ' . (isset($data['end']) ? $data['end'] : ''), 3);
    $rows[] = cpms_construction_cost_export_row($cells, 22);
    $rows[] = cpms_construction_cost_export_row(cpms_construction_cost_export_cells($columnCount, 0), 8);

    $groups = array('구매품', '자재비', '안전관리비', '기타경비', '합계');
    $totals = isset($data['totals']) && is_array($data['totals']) ? $data['totals'] : array();
    $overallTotal = isset($data['overall_total']) ? (float)$data['overall_total'] : 0.0;
    $cells = cpms_construction_cost_export_cells($columnCount, 20);
    foreach ($groups as $index => $group) cpms_construction_cost_export_set($cells, $index * 2, $group, 20);
    $rows[] = cpms_construction_cost_export_row($cells, 23);
    $cells = cpms_construction_cost_export_cells($columnCount, 21);
    foreach ($groups as $index => $group) {
        $value = $group === '합계' ? $overallTotal : (isset($totals[$group]) ? (float)$totals[$group] : 0.0);
        cpms_construction_cost_export_set($cells, $index * 2, $value, 21);
    }
    $rows[] = cpms_construction_cost_export_row($cells, 23);
    for ($groupIndex = 0; $groupIndex < 5; $groupIndex++) {
        $startColumn = cpms_construction_cost_export_column(($groupIndex * 2) + 1);
        $endColumn = cpms_construction_cost_export_column(($groupIndex * 2) + 2);
        $merges[] = $startColumn . '5:' . $endColumn . '5';
        $merges[] = $startColumn . '6:' . $endColumn . '6';
    }

    $headers = array('일', '구분', '선급여부', '업체명', '내역', '대표자명', '전화번호', '사업자등록번호', '공급가액', '비고');
    $cells = cpms_construction_cost_export_cells($columnCount, 22);
    foreach ($headers as $index => $header) cpms_construction_cost_export_set($cells, $index, $header, 22);
    $rows[] = cpms_construction_cost_export_row($cells, 25);

    $monthlyRows = isset($data['rows']) && is_array($data['rows']) ? $data['rows'] : array();
    foreach ($monthlyRows as $monthlyRow) {
        $amount = isset($monthlyRow['amount']) ? (float)$monthlyRow['amount'] : 0.0;
        $cells = cpms_construction_cost_export_cells($columnCount, 6);
        cpms_construction_cost_export_set($cells, 0, isset($monthlyRow['date']) ? (string)$monthlyRow['date'] : '', 7);
        cpms_construction_cost_export_set($cells, 1, isset($monthlyRow['category']) ? (string)$monthlyRow['category'] : '', 7);
        cpms_construction_cost_export_set($cells, 2, isset($monthlyRow['advance_yn']) ? (string)$monthlyRow['advance_yn'] : 'N', 7);
        cpms_construction_cost_export_set($cells, 3, isset($monthlyRow['vendor_name']) ? (string)$monthlyRow['vendor_name'] : '', 6);
        cpms_construction_cost_export_set($cells, 4, isset($monthlyRow['detail']) ? (string)$monthlyRow['detail'] : '', 6);
        cpms_construction_cost_export_set($cells, 5, isset($monthlyRow['representative']) ? (string)$monthlyRow['representative'] : '', 6);
        cpms_construction_cost_export_set($cells, 6, isset($monthlyRow['phone']) ? (string)$monthlyRow['phone'] : '', 6);
        cpms_construction_cost_export_set($cells, 7, isset($monthlyRow['biz_no']) ? (string)$monthlyRow['biz_no'] : '', 6);
        cpms_construction_cost_export_set($cells, 8, $amount, $amount < 0 ? 24 : 42);
        cpms_construction_cost_export_set($cells, 9, isset($monthlyRow['remark']) ? (string)$monthlyRow['remark'] : '', 6);
        $rows[] = cpms_construction_cost_export_row($cells, 23);
    }
    $blankCount = 32 - count($monthlyRows);
    if ($blankCount < 4) $blankCount = 4;
    for ($index = 0; $index < $blankCount; $index++) $rows[] = cpms_construction_cost_export_row(cpms_construction_cost_export_cells($columnCount, 23), 22);

    return array('name'=>'월별 자재구입비', 'rows'=>$rows, 'merges'=>$merges, 'widths'=>array(16,17,12,19,22,15,17,20,18,22), 'column_count'=>$columnCount, 'freeze'=>array('x'=>0, 'y'=>7), 'orientation'=>'landscape');
}

function cpms_construction_cost_export_equipment_slot($slot, $item, $pendingByUsage)
{
    if (!isset($slot['valid']) || !$slot['valid']) return array('value'=>'X', 'style'=>25);
    $date = isset($slot['date']) ? (string)$slot['date'] : '';
    $bundle = isset($item['slot_usage'][$date]) && is_array($item['slot_usage'][$date]) ? $item['slot_usage'][$date] : array();
    $unit = isset($bundle['total_unit']) ? (float)$bundle['total_unit'] : 0.0;
    $usageRows = isset($bundle['rows']) && is_array($bundle['rows']) ? $bundle['rows'] : array();
    if ($unit <= 0) return array('value'=>0.0, 'style'=>26);
    if (count($usageRows) > 1) return array('value'=>equipment_gongsu($unit) . "\n중복 묶음", 'style'=>7);
    if (count($usageRows) === 1) {
        $usageId = isset($usageRows[0]['id']) ? (int)$usageRows[0]['id'] : 0;
        if ($usageId > 0 && isset($pendingByUsage[$usageId]) && is_array($pendingByUsage[$usageId])) {
            $newValue = isset($pendingByUsage[$usageId]['new_value']) ? (float)$pendingByUsage[$usageId]['new_value'] : 0.0;
            return array('value'=>equipment_gongsu($unit) . "\n" . equipment_gongsu($newValue) . ' 승인대기', 'style'=>7);
        }
    }
    return array('value'=>$unit, 'style'=>26);
}

function cpms_construction_cost_export_sheet_equipment($data)
{
    $dateRow1 = isset($data['date_row1']) && is_array($data['date_row1']) ? $data['date_row1'] : array();
    $dateRow2 = isset($data['date_row2']) && is_array($data['date_row2']) ? $data['date_row2'] : array();
    $dateSlots = isset($data['date_slots']) && is_array($data['date_slots']) ? $data['date_slots'] : array();
    $displayItems = isset($data['items']) && is_array($data['items']) ? $data['items'] : array();
    $pendingByUsage = isset($data['pending']) && is_array($data['pending']) ? $data['pending'] : array();
    $dateColumnCount = max(count($dateRow1), count($dateRow2));
    if ($dateColumnCount <= 0) $dateColumnCount = 1;
    $columnCount = 7 + $dateColumnCount + 3;
    $totalStart = 7 + $dateColumnCount;
    $lastColumn = cpms_construction_cost_export_column($columnCount);
    $rows = array();
    $merges = array('A1:' . $lastColumn . '1', 'B2:' . $lastColumn . '2', 'B3:' . $lastColumn . '3');

    $cells = cpms_construction_cost_export_cells($columnCount, 1);
    cpms_construction_cost_export_set($cells, 0, '장비월별', 1);
    $rows[] = cpms_construction_cost_export_row($cells, 30);
    $cells = cpms_construction_cost_export_cells($columnCount, 3);
    cpms_construction_cost_export_set($cells, 0, '현장', 2);
    cpms_construction_cost_export_set($cells, 1, isset($data['project_name']) ? $data['project_name'] : '-', 3);
    $rows[] = cpms_construction_cost_export_row($cells, 22);
    $cells = cpms_construction_cost_export_cells($columnCount, 3);
    cpms_construction_cost_export_set($cells, 0, '사용기간', 2);
    cpms_construction_cost_export_set($cells, 1, (isset($data['start']) ? $data['start'] : '') . ' ~ ' . (isset($data['end']) ? $data['end'] : ''), 3);
    $rows[] = cpms_construction_cost_export_row($cells, 22);
    $rows[] = cpms_construction_cost_export_row(cpms_construction_cost_export_cells($columnCount, 0), 8);

    $headers = array('구분','업체명','규격','대표자명','전화번호','사업자등록번호','기본단가');
    $cells1 = cpms_construction_cost_export_cells($columnCount, 22);
    $cells2 = cpms_construction_cost_export_cells($columnCount, 22);
    foreach ($headers as $index => $header) {
        cpms_construction_cost_export_set($cells1, $index, $header, 22);
        $merges[] = cpms_construction_cost_export_column($index + 1) . '5:' . cpms_construction_cost_export_column($index + 1) . '6';
    }
    foreach ($dateRow1 as $index => $slot) cpms_construction_cost_export_set($cells1, 7 + $index, isset($slot['label']) ? $slot['label'] : '', isset($slot['valid']) && !$slot['valid'] ? 25 : 22);
    foreach ($dateRow2 as $index => $slot) cpms_construction_cost_export_set($cells2, 7 + $index, isset($slot['label']) ? $slot['label'] : '', isset($slot['valid']) && !$slot['valid'] ? 25 : 22);
    $totalHeaders = array('총 장비공수','총 장비비','비고');
    foreach ($totalHeaders as $index => $header) {
        cpms_construction_cost_export_set($cells1, $totalStart + $index, $header, 22);
        $column = cpms_construction_cost_export_column($totalStart + $index + 1);
        $merges[] = $column . '5:' . $column . '6';
    }
    $rows[] = cpms_construction_cost_export_row($cells1, 26);
    $rows[] = cpms_construction_cost_export_row($cells2, 26);

    $sumWorkUnit = 0.0;
    $sumAmount = 0.0;
    $sumByDate = array();
    foreach ($dateSlots as $slot) if (isset($slot['date'])) $sumByDate[(string)$slot['date']] = 0.0;

    if (count($displayItems) === 0) {
        $cells = cpms_construction_cost_export_cells($columnCount, 23);
        cpms_construction_cost_export_set($cells, 0, '선택한 월에 사용된 장비가 없습니다.', 7);
        $rowNumber = count($rows) + 1;
        $rows[] = cpms_construction_cost_export_row($cells, 36);
        $merges[] = 'A' . $rowNumber . ':' . $lastColumn . $rowNumber;
    } else {
        foreach ($displayItems as $item) {
            $firstRowNumber = count($rows) + 1;
            $secondRowNumber = $firstRowNumber + 1;
            $cells1 = cpms_construction_cost_export_cells($columnCount, 23);
            $cells2 = cpms_construction_cost_export_cells($columnCount, 23);
            $fixed = array(
                isset($item['category']) ? $item['category'] : '',
                isset($item['vendor_name']) ? $item['vendor_name'] : '',
                isset($item['spec']) ? $item['spec'] : '',
                isset($item['representative']) ? $item['representative'] : '',
                isset($item['phone']) ? $item['phone'] : '',
                isset($item['biz_no']) ? $item['biz_no'] : '',
                isset($item['base_rate']) ? (float)$item['base_rate'] : 0.0
            );
            foreach ($fixed as $index => $value) {
                cpms_construction_cost_export_set($cells1, $index, $value, $index === 0 ? 7 : ($index === 6 ? 42 : 6));
                $column = cpms_construction_cost_export_column($index + 1);
                $merges[] = $column . $firstRowNumber . ':' . $column . $secondRowNumber;
            }

            $rowWorkUnit = 0.0;
            $rowAmount = 0.0;
            foreach ($dateSlots as $slot) {
                if (!isset($slot['valid']) || !$slot['valid']) continue;
                $date = isset($slot['date']) ? (string)$slot['date'] : '';
                $bundle = isset($item['slot_usage'][$date]) && is_array($item['slot_usage'][$date]) ? $item['slot_usage'][$date] : array();
                $unit = isset($bundle['total_unit']) ? (float)$bundle['total_unit'] : 0.0;
                $amount = isset($bundle['total_amount']) ? (float)$bundle['total_amount'] : 0.0;
                if ($unit > 0) {
                    $rowWorkUnit += $unit;
                    $rowAmount += $amount;
                    if (isset($sumByDate[$date])) $sumByDate[$date] += $unit;
                }
            }
            foreach ($dateRow1 as $index => $slot) {
                $display = cpms_construction_cost_export_equipment_slot($slot, $item, $pendingByUsage);
                cpms_construction_cost_export_set($cells1, 7 + $index, $display['value'], $display['style']);
            }
            foreach ($dateRow2 as $index => $slot) {
                $display = cpms_construction_cost_export_equipment_slot($slot, $item, $pendingByUsage);
                cpms_construction_cost_export_set($cells2, 7 + $index, $display['value'], $display['style']);
            }
            cpms_construction_cost_export_set($cells1, $totalStart, $rowWorkUnit, 26);
            cpms_construction_cost_export_set($cells1, $totalStart + 1, $rowAmount, 42);
            cpms_construction_cost_export_set($cells1, $totalStart + 2, isset($item['remark']) ? $item['remark'] : '', 6);
            for ($index = 0; $index < 3; $index++) {
                $column = cpms_construction_cost_export_column($totalStart + $index + 1);
                $merges[] = $column . $firstRowNumber . ':' . $column . $secondRowNumber;
            }
            $rows[] = cpms_construction_cost_export_row($cells1, 30);
            $rows[] = cpms_construction_cost_export_row($cells2, 30);
            $sumWorkUnit += $rowWorkUnit;
            $sumAmount += $rowAmount;
        }

        $firstRowNumber = count($rows) + 1;
        $secondRowNumber = $firstRowNumber + 1;
        $cells1 = cpms_construction_cost_export_cells($columnCount, 27);
        $cells2 = cpms_construction_cost_export_cells($columnCount, 27);
        cpms_construction_cost_export_set($cells1, 0, '합계', 27);
        $merges[] = 'A' . $firstRowNumber . ':G' . $secondRowNumber;
        foreach ($dateRow1 as $index => $slot) {
            $value = isset($slot['valid']) && !$slot['valid'] ? 'X' : (isset($sumByDate[$slot['date']]) ? (float)$sumByDate[$slot['date']] : 0.0);
            cpms_construction_cost_export_set($cells1, 7 + $index, $value, isset($slot['valid']) && !$slot['valid'] ? 25 : 28);
        }
        foreach ($dateRow2 as $index => $slot) {
            $value = isset($slot['valid']) && !$slot['valid'] ? 'X' : (isset($sumByDate[$slot['date']]) ? (float)$sumByDate[$slot['date']] : 0.0);
            cpms_construction_cost_export_set($cells2, 7 + $index, $value, isset($slot['valid']) && !$slot['valid'] ? 25 : 28);
        }
        cpms_construction_cost_export_set($cells1, $totalStart, $sumWorkUnit, 28);
        cpms_construction_cost_export_set($cells1, $totalStart + 1, $sumAmount, 29);
        cpms_construction_cost_export_set($cells1, $totalStart + 2, '', 27);
        for ($index = 0; $index < 3; $index++) {
            $column = cpms_construction_cost_export_column($totalStart + $index + 1);
            $merges[] = $column . $firstRowNumber . ':' . $column . $secondRowNumber;
        }
        $rows[] = cpms_construction_cost_export_row($cells1, 28);
        $rows[] = cpms_construction_cost_export_row($cells2, 28);
    }

    $widths = array(13,18,18,14,16,19,14);
    for ($index = 0; $index < $dateColumnCount; $index++) $widths[] = 9;
    $widths[] = 14;
    $widths[] = 16;
    $widths[] = 20;
    return array('name'=>'장비월별', 'rows'=>$rows, 'merges'=>$merges, 'widths'=>$widths, 'column_count'=>$columnCount, 'freeze'=>array('x'=>7, 'y'=>6), 'orientation'=>'landscape');
}

function cpms_construction_cost_export_sheet_outsourcing($data)
{
    $columnCount = 7;
    $rows = array();
    $merges = array('A1:G1', 'B2:G2', 'B3:G3', 'A5:B5', 'C5:D5', 'E5:G5', 'A6:B6', 'C6:D6', 'E6:G6');
    $cells = cpms_construction_cost_export_cells($columnCount, 1);
    cpms_construction_cost_export_set($cells, 0, '월별 외주비', 1);
    $rows[] = cpms_construction_cost_export_row($cells, 30);
    $cells = cpms_construction_cost_export_cells($columnCount, 3);
    cpms_construction_cost_export_set($cells, 0, '현장', 2);
    cpms_construction_cost_export_set($cells, 1, isset($data['project_name']) ? $data['project_name'] : '-', 3);
    $rows[] = cpms_construction_cost_export_row($cells, 22);
    $cells = cpms_construction_cost_export_cells($columnCount, 3);
    cpms_construction_cost_export_set($cells, 0, '조회월', 2);
    cpms_construction_cost_export_set($cells, 1, isset($data['month']) ? str_replace('-', '.', $data['month']) : '-', 3);
    $rows[] = cpms_construction_cost_export_row($cells, 22);
    $rows[] = cpms_construction_cost_export_row(cpms_construction_cost_export_cells($columnCount, 0), 8);

    $cells = cpms_construction_cost_export_cells($columnCount, 38);
    cpms_construction_cost_export_set($cells, 0, '노무비 연동 외주비', 38);
    cpms_construction_cost_export_set($cells, 2, '직접 입력 외주비', 40);
    cpms_construction_cost_export_set($cells, 4, '월 외주비 합계', 36);
    $rows[] = cpms_construction_cost_export_row($cells, 23);
    $cells = cpms_construction_cost_export_cells($columnCount, 39);
    cpms_construction_cost_export_set($cells, 0, isset($data['labor_total']) ? (float)$data['labor_total'] : 0.0, 39);
    cpms_construction_cost_export_set($cells, 2, isset($data['manual_total']) ? (float)$data['manual_total'] : 0.0, 41);
    cpms_construction_cost_export_set($cells, 4, isset($data['total']) ? (float)$data['total'] : 0.0, 37);
    $rows[] = cpms_construction_cost_export_row($cells, 25);
    $rows[] = cpms_construction_cost_export_row(cpms_construction_cost_export_cells($columnCount, 0), 8);

    $headers = array('일자','구분','업체명','대표자명','사업자번호','연락처','금액');
    $cells = cpms_construction_cost_export_cells($columnCount, 22);
    foreach ($headers as $index => $header) cpms_construction_cost_export_set($cells, $index, $header, 22);
    $rows[] = cpms_construction_cost_export_row($cells, 25);

    $laborRows = isset($data['labor_rows']) && is_array($data['labor_rows']) ? $data['labor_rows'] : array();
    $manualRows = isset($data['manual_rows']) && is_array($data['manual_rows']) ? $data['manual_rows'] : array();
    foreach ($laborRows as $row) {
        $cells = cpms_construction_cost_export_cells($columnCount, 30);
        cpms_construction_cost_export_set($cells, 0, isset($row['expense_date']) ? $row['expense_date'] : '', 30);
        cpms_construction_cost_export_set($cells, 1, '노무비', 31);
        cpms_construction_cost_export_set($cells, 2, isset($row['company_name']) ? $row['company_name'] : '', 30);
        cpms_construction_cost_export_set($cells, 3, '-', 30);
        cpms_construction_cost_export_set($cells, 4, '-', 30);
        cpms_construction_cost_export_set($cells, 5, isset($row['contact']) && trim((string)$row['contact']) !== '' ? $row['contact'] : '-', 30);
        cpms_construction_cost_export_set($cells, 6, isset($row['amount']) ? (float)$row['amount'] : 0.0, 32);
        $rows[] = cpms_construction_cost_export_row($cells, 23);
    }
    foreach ($manualRows as $row) {
        $cells = cpms_construction_cost_export_cells($columnCount, 33);
        cpms_construction_cost_export_set($cells, 0, isset($row['expense_date']) ? $row['expense_date'] : '', 33);
        cpms_construction_cost_export_set($cells, 1, '외주비', 34);
        cpms_construction_cost_export_set($cells, 2, isset($row['company_name']) ? $row['company_name'] : '', 33);
        cpms_construction_cost_export_set($cells, 3, isset($row['representative_name']) && trim((string)$row['representative_name']) !== '' ? $row['representative_name'] : '-', 33);
        cpms_construction_cost_export_set($cells, 4, isset($row['business_no']) && trim((string)$row['business_no']) !== '' ? $row['business_no'] : '-', 33);
        cpms_construction_cost_export_set($cells, 5, isset($row['contact']) && trim((string)$row['contact']) !== '' ? $row['contact'] : '-', 33);
        cpms_construction_cost_export_set($cells, 6, isset($row['amount']) ? (float)$row['amount'] : 0.0, 35);
        $rows[] = cpms_construction_cost_export_row($cells, 23);
    }
    if (count($laborRows) === 0 && count($manualRows) === 0) {
        $cells = cpms_construction_cost_export_cells($columnCount, 23);
        cpms_construction_cost_export_set($cells, 0, '선택한 월의 외주비 내역이 없습니다.', 7);
        $rowNumber = count($rows) + 1;
        $rows[] = cpms_construction_cost_export_row($cells, 36);
        $merges[] = 'A' . $rowNumber . ':G' . $rowNumber;
    }
    $cells = cpms_construction_cost_export_cells($columnCount, 36);
    cpms_construction_cost_export_set($cells, 0, '합계', 36);
    cpms_construction_cost_export_set($cells, 6, isset($data['total']) ? (float)$data['total'] : 0.0, 37);
    $rowNumber = count($rows) + 1;
    $rows[] = cpms_construction_cost_export_row($cells, 24);
    $merges[] = 'A' . $rowNumber . ':F' . $rowNumber;

    return array('name'=>'월별 외주비', 'rows'=>$rows, 'merges'=>$merges, 'widths'=>array(14,13,23,16,19,18,16), 'column_count'=>$columnCount, 'freeze'=>array('x'=>0, 'y'=>8), 'orientation'=>'landscape');
}

if (!Auth::check()) cpms_construction_cost_export_error(401, '로그인이 필요합니다.');
if (!Auth::canAccessConstruction()) cpms_construction_cost_export_error(403, '접근 권한이 없습니다.');

$projectId = isset($_GET['pid']) ? (int)$_GET['pid'] : 0;
if ($projectId <= 0) cpms_construction_cost_export_error(400, '프로젝트가 올바르지 않습니다.');

$pdo = Db::pdo();
if (!$pdo) cpms_construction_cost_export_error(500, 'DB 연결에 실패했습니다.');

try {
    $projectStatement = $pdo->prepare("SELECT * FROM cpms_projects WHERE id = :id AND name NOT LIKE '(가제)%' LIMIT 1");
    $projectStatement->bindValue(':id', $projectId, \PDO::PARAM_INT);
    $projectStatement->execute();
    $projectRecord = $projectStatement->fetch(\PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $projectRecord = false;
}
if (!$projectRecord) cpms_construction_cost_export_error(404, '프로젝트를 찾을 수 없습니다.');

$department = cpms_construction_cost_export_department(Auth::userDepartment());
$canViewAllProjects = Auth::isMaster() || Auth::userRole() === 'executive' || $department === '공무' || $department === '관리';
if (!$canViewAllProjects) {
    try {
        $accessStatement = $pdo->prepare("SELECT COUNT(*) FROM cpms_project_members pm INNER JOIN employees e ON e.id = pm.employee_id WHERE pm.project_id = :pid AND e.email = :email AND LOWER(TRIM(pm.role)) IN ('main','sub')");
        $accessStatement->bindValue(':pid', $projectId, \PDO::PARAM_INT);
        $accessStatement->bindValue(':email', (string)Auth::userEmail());
        $accessStatement->execute();
        $hasProjectAccess = ((int)$accessStatement->fetchColumn() > 0);
    } catch (Exception $e) {
        $hasProjectAccess = false;
    }
    if (!$hasProjectAccess) cpms_construction_cost_export_error(403, '이 프로젝트를 조회할 권한이 없습니다.');
}

$requestedViewMonth = isset($_GET['view_month']) ? trim((string)$_GET['view_month']) : '';
$requestedMonth = isset($_GET['month']) ? trim((string)$_GET['month']) : '';
$pid = $projectId;
$canEdit = Auth::canManageConstruction();
$projectRow = $projectRecord;

$_GET['pid'] = $projectId;
$_GET['view_month'] = $requestedViewMonth;
$cpmsMonthlyInputRoute = '공사';
$cpmsMonthlyInputTab = 'monthly_input';
$cpmsMonthlyInputSelectedProjectId = $projectId;
$cpmsMonthlyInputShowProjectFilter = false;
ob_start();
require __DIR__ . '/../project/monthly_input.php';
ob_end_clean();

$inputExport = array(
    'project_name' => isset($projectRecord['name']) ? (string)$projectRecord['name'] : '',
    'selected_view_month' => isset($selectedViewMonth) ? (string)$selectedViewMonth : '',
    'display_months' => isset($displayMonths) && is_array($displayMonths) ? $displayMonths : array(),
    'all_months' => isset($allMonths) && is_array($allMonths) ? $allMonths : array(),
    'monthly_revenue' => isset($monthlyRevenue) && is_array($monthlyRevenue) ? $monthlyRevenue : array(),
    'rows_by_section' => isset($rowsBySection) && is_array($rowsBySection) ? $rowsBySection : array(),
    'labels' => isset($labels) && is_array($labels) ? $labels : array(),
    'sum_by_section' => isset($sumBySection) && is_array($sumBySection) ? $sumBySection : array(),
    'subtotal1' => isset($subtotal1) && is_array($subtotal1) ? $subtotal1 : array(),
    'final_total' => isset($finalTotal) && is_array($finalTotal) ? $finalTotal : array(),
    'profit' => isset($profit) && is_array($profit) ? $profit : array()
);

$exportMonth = '';
if (preg_match('/^\d{4}-\d{2}$/', $requestedMonth) && in_array($requestedMonth, $inputExport['all_months'], true)) {
    $exportMonth = $requestedMonth;
} else if ($inputExport['selected_view_month'] !== 'all' && preg_match('/^\d{4}-\d{2}$/', $inputExport['selected_view_month'])) {
    $exportMonth = $inputExport['selected_view_month'];
} else if (count($inputExport['all_months']) > 0) {
    $exportMonth = $inputExport['all_months'][count($inputExport['all_months']) - 1];
} else {
    $exportMonth = date('Y-m');
}

$pid = $projectId;
$canEdit = Auth::canManageConstruction();
$_GET['ym'] = $exportMonth;
$_GET['materials_tab'] = 'monthly';
ob_start();
require __DIR__ . '/tabs/materials.php';
ob_end_clean();
$materialsExport = array(
    'project_name' => isset($projectRecord['name']) ? (string)$projectRecord['name'] : '',
    'start' => isset($monthlyStart) ? (string)$monthlyStart : '',
    'end' => isset($monthlyEnd) ? (string)$monthlyEnd : '',
    'rows' => isset($monthlyRows) && is_array($monthlyRows) ? $monthlyRows : array(),
    'totals' => isset($monthlyTotals) && is_array($monthlyTotals) ? $monthlyTotals : array(),
    'overall_total' => isset($monthlyOverallTotal) ? (float)$monthlyOverallTotal : 0.0
);

$pid = $projectId;
$canEdit = Auth::canManageConstruction();
$_GET['ym'] = $exportMonth;
$_GET['equip_tab'] = 'monthly';
ob_start();
require __DIR__ . '/tabs/equipment.php';
ob_end_clean();
$equipmentExport = array(
    'project_name' => isset($projectRecord['name']) ? (string)$projectRecord['name'] : '',
    'start' => isset($monthlyStart) ? (string)$monthlyStart : '',
    'end' => isset($monthlyEnd) ? (string)$monthlyEnd : '',
    'items' => isset($displayItems) && is_array($displayItems) ? $displayItems : array(),
    'date_slots' => isset($dateSlots) && is_array($dateSlots) ? $dateSlots : array(),
    'date_row1' => isset($dateSlotsRow1) && is_array($dateSlotsRow1) ? $dateSlotsRow1 : array(),
    'date_row2' => isset($dateSlotsRow2) && is_array($dateSlotsRow2) ? $dateSlotsRow2 : array(),
    'pending' => isset($pendingByUsage) && is_array($pendingByUsage) ? $pendingByUsage : array()
);

$pid = $projectId;
$canEdit = Auth::canManageConstruction();
$projectRow = $projectRecord;
$_GET['month'] = $exportMonth;
$_GET['outsourcing_tab'] = 'monthly';
ob_start();
require __DIR__ . '/tabs/outsourcing.php';
ob_end_clean();
$outsourcingExport = array(
    'project_name' => isset($projectRecord['name']) ? (string)$projectRecord['name'] : '',
    'month' => isset($selectedMonth) ? (string)$selectedMonth : $exportMonth,
    'labor_rows' => isset($laborOutsourcingRows) && is_array($laborOutsourcingRows) ? $laborOutsourcingRows : array(),
    'manual_rows' => isset($manualMonthlyRows) && is_array($manualMonthlyRows) ? $manualMonthlyRows : array(),
    'labor_total' => isset($laborOutsourcingTotal) ? (float)$laborOutsourcingTotal : 0.0,
    'manual_total' => isset($manualMonthlyTotal) ? (float)$manualMonthlyTotal : 0.0,
    'total' => isset($monthlyTotal) ? (float)$monthlyTotal : 0.0
);

$sheets = array(
    cpms_construction_cost_export_sheet_input($inputExport),
    cpms_construction_cost_export_sheet_materials($materialsExport),
    cpms_construction_cost_export_sheet_equipment($equipmentExport),
    cpms_construction_cost_export_sheet_outsourcing($outsourcingExport)
);

$tempFile = tempnam(sys_get_temp_dir(), 'cpms_cost_');
if ($tempFile === false || $tempFile === '') cpms_construction_cost_export_error(500, '엑셀 임시 파일을 만들 수 없습니다.');
try {
    ConstructionMonthlyCostXlsx::build($sheets, $tempFile);
} catch (Exception $e) {
    @unlink($tempFile);
    cpms_construction_cost_export_error(500, '엑셀 파일 생성에 실패했습니다. ' . $e->getMessage());
}

$safeProjectName = preg_replace('/[\\\\\/:*?"<>|]+/u', '_', isset($projectRecord['name']) ? (string)$projectRecord['name'] : '공사');
if ($safeProjectName === null || trim($safeProjectName) === '') $safeProjectName = '공사';
$periodName = $inputExport['selected_view_month'] === 'all' ? '전체' : $exportMonth;
$downloadName = $safeProjectName . '_' . $periodName . '_월별투입비_통합.xlsx';

while (ob_get_level() > 0) {
    if (!@ob_end_clean()) break;
}
$fileSize = @filesize($tempFile);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($downloadName));
header('Cache-Control: private, max-age=0, no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
if ($fileSize !== false) header('Content-Length: ' . $fileSize);
readfile($tempFile);
@unlink($tempFile);
exit;
