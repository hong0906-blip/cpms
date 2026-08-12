<?php
/**
 * 경영현황 현장별 월 상세 UI 회귀 테스트.
 * PHP 5.6 CLI에서 외부 테스트 프레임워크 없이 실행할 수 있다.
 */

require_once dirname(__DIR__) . '/app/services/CompanyProfitChartService.php';

if (!function_exists('h')) {
    function h($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

$failures = array();
$checks = 0;

function cpms_company_profit_view_contains($label, $needle, $haystack)
{
    global $failures, $checks;
    $checks++;
    if (strpos((string)$haystack, (string)$needle) === false) {
        $failures[] = $label . ' missing=' . $needle;
    }
}

$projects = array(
    array(
        'id' => 77,
        'name' => '원가초과 테스트 현장',
        'status' => '진행중',
        'sales' => 15000000,
        'input_cost' => 18000000,
        'target_amount' => 10500000,
        'net_profit' => -3000000,
        'cost_rate' => 120.0,
        'cost_rate_label' => '120.0%',
        'no_sales' => 0,
        'basis' => 'mixed',
        'error' => '',
        'monthly' => array(
            '2026-01' => array(
                'has_confirmed' => 1,
                'confirmed_sales' => 10000000,
                'expected_sales' => 0,
                'confirmed_rows' => 1,
                'input_cost' => 12000000,
                'labor' => 3000000,
                'material_cost' => 2000000,
                'purchase_cost' => 1000000,
                'other_cost' => 500000,
                'outsourcing' => 3500000,
                'equipment' => 1500000,
                'safety_cost' => 500000,
                'deduction' => 0,
            ),
            '2026-02' => array(
                'has_confirmed' => 0,
                'confirmed_sales' => 0,
                'expected_sales' => 5000000,
                'confirmed_rows' => 0,
                'input_cost' => 6000000,
                'labor' => 2000000,
                'material_cost' => 1000000,
                'purchase_cost' => 0,
                'other_cost' => 0,
                'outsourcing' => 2000000,
                'equipment' => 1000000,
                'safety_cost' => 0,
                'deduction' => 0,
            ),
        ),
    ),
);
$totals = array('sales' => 15000000, 'project_input_cost' => 18000000, 'target_amount' => 10500000);

ob_start();
require dirname(__DIR__) . '/app/views/company_profit/_project_profit_rows.php';
$html = ob_get_clean();

cpms_company_profit_view_contains('construction link', '?r=construction_home&amp;pid=77&amp;tab=status', $html);
cpms_company_profit_view_contains('over cost project style', 'cp-project-over-cost', $html);
cpms_company_profit_view_contains('confirmed month', '26년 1월', $html);
cpms_company_profit_view_contains('confirmed badge', 'cp-detail-badge--confirmed', $html);
cpms_company_profit_view_contains('expected badge', 'cp-detail-badge--expected', $html);
cpms_company_profit_view_contains('compact detail payload', 'id="cpDetailData"', $html);
cpms_company_profit_view_contains('lazy sales trigger', 'data-cp-detail-type="sales"', $html);
cpms_company_profit_view_contains('lazy cost trigger', 'data-cp-detail-type="cost"', $html);
cpms_company_profit_view_contains('labor component', '"label":"노무비","amount":3000000', $html);
cpms_company_profit_view_contains('material component', '"label":"자재비","amount":2000000', $html);
cpms_company_profit_view_contains('outsourcing component', '"label":"외주비","amount":3500000', $html);
cpms_company_profit_view_contains('equipment component', '"label":"장비비","amount":1500000', $html);

if (count($failures) > 0) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . ' / ' . $checks . "\n");
    foreach ($failures as $failure) fwrite(STDERR, '- ' . $failure . "\n");
    exit(1);
}

echo 'PASS: ' . $checks . " company profit detail view checks\n";
