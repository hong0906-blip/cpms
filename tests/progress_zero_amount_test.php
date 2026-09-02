<?php
/**
 * 공무 프로젝트 기성 0원 입력 회귀 테스트.
 * PHP 5.6 CLI에서 외부 테스트 프레임워크 없이 실행할 수 있다.
 */

$root = dirname(__DIR__);
$saveView = file_get_contents($root . '/app/views/project/progress_save.php');
$detailView = file_get_contents($root . '/app/views/project/detail.php');
$companyProfitService = file_get_contents($root . '/app/services/CompanyProfitSummaryService.php');
$constructionStatusView = file_get_contents($root . '/app/views/construction/tabs/status.php');

$failures = array();
$checks = 0;

function cpms_progress_zero_guard($label, $condition)
{
    global $failures, $checks;
    $checks++;
    if (!$condition) $failures[] = $label;
}

cpms_progress_zero_guard(
    '0원 금액을 유효값으로 검사해야 함',
    strpos($saveView, 'function cpms_progress_money_is_valid') !== false
        && strpos($saveView, '(float)$value >= 0') !== false
);
cpms_progress_zero_guard(
    '0원 기성을 월 누계에서 건너뛰면 안 됨',
    strpos($saveView, 'if ($amount < 0) continue;') !== false
        && strpos($saveView, 'if ($amount <= 0) continue;') === false
);
cpms_progress_zero_guard(
    '신규 기성 입력란에서 0원 입력을 명확히 안내해야 함',
    strpos($detailView, 'name="recognized_amount" required inputmode="decimal"') !== false
        && strpos($detailView, '경영현황에는 확정매출 0원으로 반영되고') !== false
        && strpos($detailView, '상황에서는 기성 없음으로 표시됩니다.') !== false
);
cpms_progress_zero_guard(
    '경영현황은 금액이 0원이어도 해당 월 입력 행을 확정매출로 판단해야 함',
    strpos($companyProfitService, 'if (is_array($row) && (int)$row[\'row_count\'] > 0)') !== false
        && strpos($companyProfitService, "'has_input' => true") !== false
);
cpms_progress_zero_guard(
    '기성 저장 후 경영현황 공용 캐시를 비워야 함',
    strpos($saveView, "/cache/company_profit") !== false
        && strpos($saveView, 'unlink($companyProfitCacheFile)') !== false
);
cpms_progress_zero_guard(
    '공사 상황 탭은 0원 기성을 기성 없음으로 표시해야 함',
    strpos($constructionStatusView, "return ((float)\$amount > 0) ? cpms_status_money(\$amount) : '기성 없음';") !== false
        && strpos($constructionStatusView, '$hasConfirmedSales = ($confirmedSales > 0);') !== false
        && strpos($constructionStatusView, '$sales = $hasConfirmedSales ? $confirmedSales : $expectedSales;') !== false
);
cpms_progress_zero_guard(
    '공사 상황 탭은 매출 0원을 과도한 원가율 숫자로 치환하면 안 됨',
    strpos($constructionStatusView, "'cost_rate' => 999.0") === false
        && strpos($constructionStatusView, "'cost_rate_label' => '매출 없음'") !== false
);

if (count($failures) > 0) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . ' / ' . $checks . "\n");
    foreach ($failures as $failure) fwrite(STDERR, '- ' . $failure . "\n");
    exit(1);
}

echo 'PASS: ' . $checks . " progress zero amount checks\n";
