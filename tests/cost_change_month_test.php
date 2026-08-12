<?php
/**
 * 비용 변경 승인 기능의 귀속월/마감 경계일 회귀 테스트.
 * PHP 5.6 CLI에서 외부 테스트 프레임워크 없이 실행할 수 있다.
 */

require_once dirname(__DIR__) . '/app/services/CostChangeService.php';

use App\Services\CostChangeService;

date_default_timezone_set('Asia/Seoul');

$failures = array();
$checks = 0;

function cpms_cost_change_test_same($label, $expected, $actual)
{
    global $failures, $checks;
    $checks++;
    if ($expected !== $actual) {
        $failures[] = $label . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true);
    }
}

cpms_cost_change_test_same('labor 2026-07-31', '2026-07', CostChangeService::settlementYm('labor', '2026-07-31'));
cpms_cost_change_test_same('labor 2026-08-01', '2026-08', CostChangeService::settlementYm('labor', '2026-08-01'));

cpms_cost_change_test_same('other 2026-07-25', '2026-07', CostChangeService::settlementYm('material', '2026-07-25'));
cpms_cost_change_test_same('other 2026-07-26', '2026-08', CostChangeService::settlementYm('material', '2026-07-26'));
cpms_cost_change_test_same('other 2026-08-25', '2026-08', CostChangeService::settlementYm('equipment', '2026-08-25'));
cpms_cost_change_test_same('outsourcing 2026-07-26', '2026-07', CostChangeService::settlementYm('outsourcing', '2026-07-26'));
cpms_cost_change_test_same('outsourcing 2026-08-26', '2026-08', CostChangeService::settlementYm('outsourcing', '2026-08-26'));
cpms_cost_change_test_same('year boundary 2026-12-26', '2027-01', CostChangeService::settlementYm('safety', '2026-12-26'));
cpms_cost_change_test_same('january beginning', '2027-01', CostChangeService::settlementYm('daily_cost', '2027-01-01'));

cpms_cost_change_test_same('leap day after closing boundary', '2024-03', CostChangeService::settlementYm('material', '2024-02-29'));
cpms_cost_change_test_same('leap period move', '2024-03', CostChangeService::settlementYm('material', '2024-02-26'));
cpms_cost_change_test_same(
    'leap settlement period',
    array('start'=>'2024-01-26', 'end'=>'2024-02-25'),
    CostChangeService::periodForYm('material', '2024-02')
);
cpms_cost_change_test_same(
    'labor month period',
    array('start'=>'2024-02-01', 'end'=>'2024-02-29'),
    CostChangeService::periodForYm('labor', '2024-02')
);
cpms_cost_change_test_same(
    'outsourcing month period',
    array('start'=>'2024-02-01', 'end'=>'2024-02-29'),
    CostChangeService::periodForYm('outsourcing', '2024-02')
);
cpms_cost_change_test_same(
    'old automatic outsourcing month is recalculated',
    '2026-07',
    CostChangeService::resolveSettlementYm('outsourcing', '2026-07-31', '2026-08', 0)
);
cpms_cost_change_test_same(
    'manual outsourcing month move is preserved',
    '2026-08',
    CostChangeService::resolveSettlementYm('outsourcing', '2026-07-31', '2026-08', 1)
);
cpms_cost_change_test_same(
    'year crossing period',
    array('start'=>'2026-12-26', 'end'=>'2027-01-25'),
    CostChangeService::periodForYm('safety', '2027-01')
);

$lockedJuly25 = CostChangeService::lockInfo('material', '2026-07-25', '', '2026-07-30');
$openJuly26 = CostChangeService::lockInfo('material', '2026-07-26', '', '2026-07-30');
$lockedLaborJuly = CostChangeService::lockInfo('labor', '2026-07-31', '', '2026-08-01');
$openLaborAugust = CostChangeService::lockInfo('labor', '2026-08-01', '', '2026-08-01');

cpms_cost_change_test_same('7/25 locked on 7/30', true, $lockedJuly25['locked']);
cpms_cost_change_test_same('7/26 open on 7/30', false, $openJuly26['locked']);
cpms_cost_change_test_same('current other settlement on 7/30', '2026-08', $openJuly26['current_settlement_ym']);
cpms_cost_change_test_same('July labor locked on 8/1', true, $lockedLaborJuly['locked']);
cpms_cost_change_test_same('August labor open on 8/1', false, $openLaborAugust['locked']);

if (count($failures) > 0) {
    fwrite(STDERR, "FAIL: " . count($failures) . " / " . $checks . "\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "- " . $failure . "\n");
    }
    exit(1);
}

echo "PASS: " . $checks . " cost settlement/lock boundary checks\n";
