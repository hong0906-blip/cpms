<?php
/**
 * Labor gongsu current-value resolution regression tests.
 * PHP 5.6 compatible and DB-independent.
 */

require_once dirname(__DIR__) . '/app/helpers.php';

$failures = array();
$checks = 0;

function cpms_labor_resolution_guard($label, $condition)
{
    global $failures, $checks;
    $checks++;
    if (!$condition) $failures[] = $label;
}

function cpms_labor_resolution_row($id, $status, $oldValue, $newValue, $isDeleted)
{
    return array(
        'id'=>$id,
        'worker_key'=>'worker-a',
        'worker_name'=>'테스트 근로자',
        'work_date'=>'2026-08-11',
        'old_value'=>$oldValue,
        'new_value'=>$newValue,
        'is_deleted_entry'=>$isDeleted,
        'status'=>$status
    );
}

$withPending = cpms_resolve_labor_override_rows(array(
    cpms_labor_resolution_row(1, 'applied', 0, 1.0, 0),
    cpms_labor_resolution_row(2, 'pending', 1.0, 1.4, 0)
));
cpms_labor_resolution_guard(
    'pending request keeps the previous applied value',
    isset($withPending['worker-a']['2026-08-11'])
        && abs((float)$withPending['worker-a']['2026-08-11']['value'] - 1.0) < 0.0001
        && (int)$withPending['worker-a']['2026-08-11']['meta']['id'] === 1
);

$afterApproval = cpms_resolve_labor_override_rows(array(
    cpms_labor_resolution_row(1, 'applied', 0, 1.0, 0),
    cpms_labor_resolution_row(2, 'applied', 1.0, 1.4, 0)
));
cpms_labor_resolution_guard(
    'latest applied history row becomes the current value',
    abs((float)$afterApproval['worker-a']['2026-08-11']['value'] - 1.4) < 0.0001
        && (int)$afterApproval['worker-a']['2026-08-11']['meta']['id'] === 2
);

$legacyPending = cpms_resolve_labor_override_rows(array(
    cpms_labor_resolution_row(9, 'pending', 0.5, 1.5, 0)
));
cpms_labor_resolution_guard(
    'legacy overwritten pending row restores old_value',
    abs((float)$legacyPending['worker-a']['2026-08-11']['value'] - 0.5) < 0.0001
);

$legacyCancelledDeletion = cpms_resolve_labor_override_rows(array(
    cpms_labor_resolution_row(10, 'cancelled', 0, 1.3, 0)
));
cpms_labor_resolution_guard(
    'legacy zero old_value continues to suppress the attendance base value',
    $legacyCancelledDeletion['worker-a']['2026-08-11']['is_deleted'] === true
);

if (count($failures) > 0) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . ' / ' . $checks . "\n");
    foreach ($failures as $failure) fwrite(STDERR, '- ' . $failure . "\n");
    exit(1);
}

echo 'PASS: ' . $checks . " labor gongsu override resolution checks\n";
