<?php
/**
 * Previous-month labor worker import regression guards.
 * PHP 5.6 compatible and DB-independent.
 */

$root = dirname(__DIR__);
$failures = array();
$checks = 0;

function cpms_previous_workers_guard($label, $condition)
{
    global $failures, $checks;
    $checks++;
    if (!$condition) $failures[] = $label;
}

$tabSource = file_get_contents($root . '/app/views/construction/tabs/labor.php');
$saveSource = file_get_contents($root . '/app/views/construction/labor_workers_save.php');
$loaderSource = file_get_contents($root . '/app/views/construction/tabs/partials/labor_data_loader.php');

cpms_previous_workers_guard(
    'workers tab has previous-month import button and modal',
    strpos($tabSource, '전달인원 가져오기') !== false
        && strpos($tabSource, 'id="previousWorkersImportModal"') !== false
);
cpms_previous_workers_guard(
    'modal exposes requested worker information and checkboxes',
    strpos($tabSource, '>인력사 업체명<') !== false
        && strpos($tabSource, '>성명<') !== false
        && strpos($tabSource, '>핸드폰 번호<') !== false
        && strpos($tabSource, '>임금 단가<') !== false
        && strpos($tabSource, 'name="previous_worker_ids[]"') !== false
);
cpms_previous_workers_guard(
    'candidate loader is restricted to the selected project and previous month',
    strpos($loaderSource, 'function cpms_load_project_labor_workers_for_month') !== false
        && strpos($loaderSource, 'pwm.project_id = :pid') !== false
        && strpos($loaderSource, 'pwm.month = :month') !== false
);
cpms_previous_workers_guard(
    'server rejects non-previous and already-current workers',
    strpos($saveSource, 'isset($previousAvailableMap[$selectedWorkerId])') !== false
        && strpos($saveSource, 'isset($currentMonthMap[$selectedWorkerId])') !== false
);
cpms_previous_workers_guard(
    'imported workers default to full labor allocation',
    strpos($saveSource, "cpms_save_project_labor_worker_month_ratio(\$pdo, \$projectId, \$importWorkerId, \$month, 0, '', '')") !== false
);

if (count($failures) > 0) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . ' / ' . $checks . "\n");
    foreach ($failures as $failure) fwrite(STDERR, '- ' . $failure . "\n");
    exit(1);
}

echo 'PASS: ' . $checks . " previous-month worker import guards\n";
