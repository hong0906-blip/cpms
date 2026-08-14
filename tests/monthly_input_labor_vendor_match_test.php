<?php
/**
 * 투입비 상세 노무비 업체표기 회귀 검사.
 * PHP 5.6 compatible and DB-independent.
 */

$root = dirname(__DIR__);
$failures = array();
$checks = 0;

function cpms_monthly_labor_vendor_guard($label, $condition)
{
    global $failures, $checks;
    $checks++;
    if (!$condition) $failures[] = $label;
}

$monthlyInput = file_get_contents($root . '/app/views/project/monthly_input.php');
$constructionView = file_get_contents($root . '/app/views/construction/tabs/monthly_input.php');
$vendorHelper = file_get_contents($root . '/app/views/construction/tabs/partials/monthly_input_vendor_info_helper.php');

cpms_monthly_labor_vendor_guard(
    'labor-derived outsourcing has a distinct detail label and storage key',
    strpos($monthlyInput, "'내역' => '외주비성 노무비 합계'") !== false
        && strpos($monthlyInput, "'labor:' . \$outsourcingKey") !== false
        && strpos($monthlyInput, "'manual:' . \$manualOutsourcingKey") !== false
        && strpos($monthlyInput, "'내역' => '외주비 합계'") !== false
);

cpms_monthly_labor_vendor_guard(
    'corporate-prefix matching is limited to labor-tagged rows',
    strpos($monthlyInput, "'vendor_match_scope' => 'labor'") !== false
        && strpos($monthlyInput, 'data-vendor-match-scope="labor"') !== false
        && strpos($constructionView, "row.getAttribute('data-vendor-match-scope') === 'labor'") !== false
);

cpms_monthly_labor_vendor_guard(
    'labor lookup accepts common leading corporate markers',
    strpos($vendorHelper, "preg_replace('/^(?:주식회사|\\(주\\)|㈜)+/u'") !== false
        && strpos($constructionView, "replace(/^(?:주식회사|\\(주\\)|㈜)+/") !== false
);

cpms_monthly_labor_vendor_guard(
    'only one active vendor candidate may provide the relaxed labor alias',
    strpos($vendorHelper, 'FROM cpms_vendors WHERE is_active=1') !== false
        && strpos($vendorHelper, 'count($rows) !== 1') !== false
        && strpos($vendorHelper, "'__labor_corp__' . \$aliasKey") !== false
);

cpms_monthly_labor_vendor_guard(
    'exact vendor names use the integrated master before project transaction fallbacks',
    strpos($vendorHelper, 'function cpms_monthly_input_vendor_load_master') !== false
        && strpos($vendorHelper, 'SELECT name,representative,phone,business_no,bank_name,account_number,account_holder FROM cpms_vendors WHERE is_active=1') !== false
        && strpos($vendorHelper, 'cpms_monthly_input_vendor_load_master($pdo, $map, $includeBankInfo);') !== false
        && strpos($vendorHelper, 'cpms_monthly_input_vendor_load_master($pdo, $map, $includeBankInfo);') < strpos($vendorHelper, 'cpms_monthly_input_vendor_load_outsourcing($pdo, $projectId, $map);')
);

cpms_monthly_labor_vendor_guard(
    'PHP 7-only null coalescing syntax was not introduced',
    strpos($monthlyInput, '??') === false
        && strpos($constructionView, '??') === false
        && strpos($vendorHelper, '??') === false
);

if (count($failures) > 0) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . ' / ' . $checks . "\n");
    foreach ($failures as $failure) fwrite(STDERR, '- ' . $failure . "\n");
    exit(1);
}

echo 'PASS: ' . $checks . " monthly input labor vendor guards\n";
