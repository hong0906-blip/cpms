<?php
/**
 * 비용 변경 승인 기능의 보안/워크플로 구조 회귀 테스트.
 * DB를 변경하지 않고 저장·승인·첨부·라우팅의 필수 방어 코드가 유지되는지 확인한다.
 */

$root = dirname(__DIR__);
$checks = 0;
$failures = array();

function cpms_guard_contains($label, $file, $needle)
{
    global $checks, $failures;
    $checks++;
    $source = is_file($file) ? file_get_contents($file) : false;
    if ($source === false || strpos($source, $needle) === false) {
        $failures[] = $label . ' (' . $file . ')';
    }
}

$service = $root . '/app/services/CostChangeService.php';
$store = $root . '/app/views/cost_change/store.php';
$decide = $root . '/app/views/cost_change/decide.php';
$cancel = $root . '/app/views/cost_change/cancel.php';
$router = $root . '/public/index.php';

cpms_guard_contains('active target unique key', $service, 'UNIQUE KEY uk_cost_change_active_target');
cpms_guard_contains('employee-linked current approver check', $service, "current_approver_employee_id");
cpms_guard_contains('first approver employee setting', $service, 'cost_change_first_approver_employee_id');
cpms_guard_contains('final approver employee setting', $service, 'cost_change_final_approver_employee_id');
cpms_guard_contains('request project permission guard', $store, 'CostChangeService::canManageProject');
cpms_guard_contains('server-side source lock check', $store, 'CostChangeService::lockInfo');
cpms_guard_contains('request transaction', $store, '$pdo->beginTransaction()');
cpms_guard_contains('multi-file validation', $store, "validateUploads('evidence_files')");
cpms_guard_contains('resubmit parent linkage', $store, 'parent_request_id');
cpms_guard_contains('selective inherited files', $store, 'inherit_file_ids');
cpms_guard_contains('decision row lock', $decide, 'FOR UPDATE');
cpms_guard_contains('decision permission guard', $decide, 'CostChangeService::canActRequest');
cpms_guard_contains('rejection reason required', $decide, "\$decision === 'reject' && \$opinion === ''");
cpms_guard_contains('first approval state condition', $decide, 'STATUS_FIRST_PENDING');
cpms_guard_contains('final approval state condition', $decide, 'STATUS_FINAL_PENDING');
cpms_guard_contains('conditional single-row decision', $decide, '$up->rowCount() !== 1');
cpms_guard_contains('automatic apply', $decide, 'CostChangeService::applyRequest');
cpms_guard_contains('apply failure state', $decide, 'STATUS_FAILED');
cpms_guard_contains('requester-only cancellation', $cancel, 'CostChangeService::isRequester');
cpms_guard_contains('cost request route', $router, "'cost_change/request'");
cpms_guard_contains('cost decision route', $router, "'cost_change/decide'");
cpms_guard_contains('protected file route', $router, "'cost_change/file'");
cpms_guard_contains('admin export route', $router, "'cost_change/export'");

if (count($failures) > 0) {
    fwrite(STDERR, "FAIL: " . count($failures) . " / " . $checks . "\n");
    foreach ($failures as $failure) fwrite(STDERR, "- " . $failure . "\n");
    exit(1);
}

echo "PASS: " . $checks . " workflow/security guard checks\n";
