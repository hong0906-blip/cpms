<?php
/**
 * 관리 > 투입비 상세 회귀 검사.
 * PHP 5.6 compatible and DB-independent.
 */

$root = dirname(__DIR__);
$failures = array();
$checks = 0;

function cpms_admin_monthly_input_guard($label, $condition)
{
    global $failures, $checks;
    $checks++;
    if (!$condition) $failures[] = $label;
}

$adminIndex = file_get_contents($root . '/app/views/admin/index.php');
$adminView = file_get_contents($root . '/app/views/admin/monthly_input.php');
$constructionView = file_get_contents($root . '/app/views/construction/tabs/monthly_input.php');
$projectView = file_get_contents($root . '/app/views/project/monthly_input.php');
$vendorHelper = file_get_contents($root . '/app/views/construction/tabs/partials/monthly_input_vendor_info_helper.php');
$detailHelper = file_get_contents($root . '/app/views/project/partials/monthly_cost_detail_helper.php');
$detailModal = file_get_contents($root . '/app/views/project/partials/monthly_cost_detail_modal.php');

cpms_admin_monthly_input_guard(
    'management menu exposes the monthly input detail tab with existing management access',
    strpos($adminIndex, "'monthly_input' => array('label' => '투입비 상세'") !== false
        && strpos($adminIndex, "elseif (\$tab === 'monthly_input' && \$canManage)") !== false
        && strpos($adminIndex, "require __DIR__ . '/monthly_input.php';") !== false
);

cpms_admin_monthly_input_guard(
    'management view reuses construction detail with a site dropdown and management-only mode',
    strpos($adminView, "\$cpmsMonthlyInputRoute = '관리';") !== false
        && strpos($adminView, "\$cpmsMonthlyInputShowProjectFilter = true;") !== false
        && strpos($adminView, "\$cpmsMonthlyInputProjectFilterLabel = '현장 선택 :';") !== false
        && strpos($adminView, "\$cpmsMonthlyInputManagementMode = true;") !== false
        && strpos($adminView, "require __DIR__ . '/../construction/tabs/monthly_input.php';") !== false
        && strpos($projectView, 'echo h($monthlyInputProjectFilterLabel)') !== false
);

cpms_admin_monthly_input_guard(
    'bank information is sent and rendered only when the management mode requests it',
    strpos($adminView, '$cpmsMonthlyInputIncludeBankInfo = true;') !== false
        && strpos($constructionView, "makeHeader('예금주'") !== false
        && strpos($constructionView, "makeHeader('은행명'") !== false
        && strpos($constructionView, "makeHeader('계좌번호'") !== false
        && strpos($vendorHelper, 'if ($includeBankInfo)') !== false
        && strpos($vendorHelper, "'account_holder'") !== false
        && strpos($vendorHelper, "'bank_name'") !== false
        && strpos($vendorHelper, "'account_number'") !== false
);

cpms_admin_monthly_input_guard(
    'management table removes total column without changing common transaction calculations',
    strpos($adminView, '$cpmsMonthlyInputShowTotalColumn = false;') !== false
        && strpos($projectView, 'if ($monthlyInputShowTotalColumn)') !== false
        && strpos($constructionView, '<?php if ($cpmsMonthlyInputShowTotalColumn): ?>') !== false
);

cpms_admin_monthly_input_guard(
    'management view shows only non-zero rows from the selected settlement month',
    strpos($adminView, '$cpmsMonthlyInputSingleMonthOnly = true;') !== false
        && strpos($adminView, '$cpmsMonthlyInputHideEmptySections = true;') !== false
        && strpos($projectView, '!$monthlyInputSingleMonthOnly && $viewMonthParam ===') !== false
        && strpos($projectView, 'if (!$monthlyInputSingleMonthOnly):') !== false
        && strpos($projectView, 'abs($selectedAmount) < 0.0001') !== false
        && strpos($projectView, 'unset($labels[$labelSection])') !== false
);

cpms_admin_monthly_input_guard(
    'month selector always spans the inclusive project contract period',
    strpos($projectView, 'function project_monthly_contract_months') !== false
        && strpos($projectView, "preg_match('/^(\\\\d{4})-(\\\\d{2})/'") !== false
        && strpos($projectView, '$allMonths = project_monthly_contract_months($startDate, $endDate);') !== false
        && strpos($projectView, '$cursorMonth <= $endMonth') !== false
);

cpms_admin_monthly_input_guard(
    'deduction entry form is hidden only by the management wrapper',
    strpos($adminView, '$cpmsMonthlyInputShowDeductionEntry = false;') !== false
        && strpos($projectView, '$monthlyInputShowDeductionEntry = isset($cpmsMonthlyInputShowDeductionEntry)') !== false
        && strpos($projectView, '$canManageMonthlyDeductions && $monthlyInputShowDeductionEntry') !== false
);

cpms_admin_monthly_input_guard(
    'management hides revenue and uses a compact single-page table',
    strpos($adminView, '$cpmsMonthlyInputShowRevenueRow = false;') !== false
        && strpos($adminView, '$cpmsMonthlyInputCompactTable = true;') !== false
        && strpos($projectView, 'if ($monthlyInputShowRevenueRow):') !== false
        && strpos($constructionView, 'table-layout: fixed;') !== false
        && strpos($constructionView, 'text-overflow: clip;') !== false
);

cpms_admin_monthly_input_guard(
    'management table is centered, larger, and gives the account holder more room without ellipses',
    strpos($constructionView, 'font-size: 14px;') !== false
        && strpos($constructionView, 'text-align: center !important;') !== false
        && strpos($constructionView, 'margin-left: auto;') !== false
        && strpos($constructionView, 'th:nth-child(6) { width: 108px; }') !== false
        && strpos($constructionView, 'th:nth-child(7) { width: 58px; }') !== false
        && strpos($constructionView, 'th:nth-child(9) { width: 130px; }') !== false
        && strpos($constructionView, 'text-overflow: ellipsis;') === false
);

cpms_admin_monthly_input_guard(
    'profit summary and final profit row are hidden only in management',
    strpos($adminView, '$cpmsMonthlyInputShowProfitRow = false;') !== false
        && strpos($projectView, '$monthlyInputShowProfitRow = isset($cpmsMonthlyInputShowProfitRow)') !== false
        && substr_count($projectView, 'if ($monthlyInputShowProfitRow):') === 2
);

cpms_admin_monthly_input_guard(
    'monthly amount buttons open shared detail data including statement attachments',
    strpos($constructionView, 'cpms-monthly-input-detail-trigger') !== false
        && strpos($constructionView, "require __DIR__ . '/../../project/partials/monthly_cost_detail_modal.php';") !== false
        && strpos($detailHelper, "cpms_monthly_cost_detail_file_payload('material'") !== false
        && strpos($detailHelper, "cpms_monthly_cost_detail_file_payload('equipment'") !== false
        && strpos($detailHelper, "cpms_monthly_cost_detail_file_payload('outsourcing'") !== false
        && strpos($detailHelper, 'cpms_monthly_cost_detail_safety_file_payload') !== false
        && strpos($constructionView, "'7. 안전관리비': {type:'safety'") !== false
        && strpos($detailModal, "{key:'files', label:'증빙파일', format:'files'}") !== false
        && strpos($detailModal, "{key:'files', label:'첨부파일', format:'files'}") !== false
        && strpos($detailModal, "{key:'files', label:'명세표', format:'files'}") !== false
);

cpms_admin_monthly_input_guard(
    'labor detail reuses the construction labor job type snapshot',
    strpos($detailHelper, "isset(\$worker['job_type_snapshot'])") !== false
        && strpos($detailHelper, "'job_type' => \$jobType") !== false
        && strpos($detailModal, "{key:'job_type', label:'직종'}") !== false
);

cpms_admin_monthly_input_guard(
    'PHP 7-only null coalescing syntax was not introduced',
    strpos($adminView, '??') === false
        && strpos($constructionView, '??') === false
        && strpos($vendorHelper, '??') === false
        && strpos($projectView, '??') === false
);

if (count($failures) > 0) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . ' / ' . $checks . "\n");
    foreach ($failures as $failure) fwrite(STDERR, '- ' . $failure . "\n");
    exit(1);
}

echo 'PASS: ' . $checks . " admin monthly input guards\n";
