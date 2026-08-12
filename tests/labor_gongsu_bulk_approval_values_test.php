<?php
/**
 * Labor gongsu bulk approval value regression guards.
 * PHP 5.6 compatible and DB-independent.
 */

$root = dirname(__DIR__);
$failures = array();
$checks = 0;

function cpms_labor_gongsu_bulk_guard($label, $condition)
{
    global $failures, $checks;
    $checks++;
    if (!$condition) $failures[] = $label;
}

$tabSource = file_get_contents($root . '/app/views/construction/tabs/labor.php');
$saveSource = file_get_contents($root . '/app/views/construction/labor_gongsu_override_save.php');
$helperSource = file_get_contents($root . '/app/helpers.php');
$migrationSource = file_get_contents($root . '/database/migrations/2026_08_11_preserve_labor_gongsu_approval_history.sql');

foreach (array('1.3', '1.4', '1.5', '2') as $allowedValue) {
    cpms_labor_gongsu_bulk_guard(
        $allowedValue . ' gongsu bulk approval button is available',
        strpos($tabSource, 'data-labor-bulk-value="' . $allowedValue . '"') !== false
    );
}

foreach (array('1.3', '1.4', '1.5', '2.0') as $allowedValue) {
    cpms_labor_gongsu_bulk_guard(
        $allowedValue . ' gongsu is accepted by the server validator',
        strpos($saveSource, 'abs($newValue - ' . $allowedValue . ') > 0.0001') !== false
    );
}

cpms_labor_gongsu_bulk_guard(
    'server validation message lists every allowed bulk approval value',
    strpos($saveSource, '1.3공수, 1.4공수, 1.5공수 또는 2공수만 가능합니다.') !== false
);
cpms_labor_gongsu_bulk_guard(
    'approval requests are inserted as history rows instead of overwriting the applied row',
    strpos($saveSource, 'ON DUPLICATE KEY UPDATE') === false
        && strpos($saveSource, 'cpms_gongsu_has_pending_request') !== false
);
cpms_labor_gongsu_bulk_guard(
    'labor override schema allows separate applied and pending rows for one cell',
    strpos($helperSource, 'KEY idx_labor_override_cell(project_id, worker_key, work_date)') !== false
        && strpos($helperSource, 'ADD UNIQUE KEY uk_labor_override') === false
        && strpos($migrationSource, 'DROP INDEX uk_labor_override') !== false
);
cpms_labor_gongsu_bulk_guard(
    'latest applied row wins and legacy overwritten values fall back to old_value',
    strpos($helperSource, "status IN ('applied','approved','pending','rejected','cancelled')") !== false
        && strpos($helperSource, 'ORDER BY id ASC') !== false
        && strpos($helperSource, '$legacyValue = (float)$r[\'old_value\'];') !== false
);

if (count($failures) > 0) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . ' / ' . $checks . "\n");
    foreach ($failures as $failure) fwrite(STDERR, '- ' . $failure . "\n");
    exit(1);
}

echo 'PASS: ' . $checks . " labor gongsu bulk approval value guards\n";
