<?php
/**
 * 스케줄러 안전·품질·공사 통합 조회 회귀 검사.
 * PHP 5.6 호환, DB 독립 실행.
 */

$root = dirname(__DIR__);
$failures = array();
$checks = 0;

function cpms_scheduler_cross_department_guard($label, $condition)
{
    global $failures, $checks;
    $checks++;
    if (!$condition) $failures[] = $label;
}

$scheduler = file_get_contents($root . '/app/views/scheduler/index.php');

cpms_scheduler_cross_department_guard(
    'shared field department helper contains safety quality and construction',
    strpos($scheduler, "function cpms_scheduler_is_shared_field_department") !== false
        && strpos($scheduler, "array('안전', '품질', '공사')") !== false
);

cpms_scheduler_cross_department_guard(
    'members of the three departments receive the complete shared roster',
    strpos($scheduler, 'if (cpms_scheduler_is_shared_field_department($currentEmployee))') !== false
        && strpos($scheduler, 'if (cpms_scheduler_is_shared_field_department($employees[$i]))') !== false
        && strpos($scheduler, 'cpms_scheduler_add_visible_employee($visible, $seen, $employees[$i]);') !== false
);

cpms_scheduler_cross_department_guard(
    'executives and other departments keep their existing visibility paths',
    strpos($scheduler, 'if ($canViewAll) return $employees;') !== false
        && strpos($scheduler, '$teamAnchorId = cpms_scheduler_team_anchor_id($currentEmployee, $publicAffairsAnchorId);') !== false
);

cpms_scheduler_cross_department_guard(
    'shared view is identified in the scheduler interface',
    strpos($scheduler, "'안전·품질·공사 전체'") !== false
        && strpos($scheduler, "'안전·품질·공사 통합 조회'") !== false
);

if (count($failures) > 0) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . ' / ' . $checks . "\n");
    foreach ($failures as $failure) fwrite(STDERR, '- ' . $failure . "\n");
    exit(1);
}

echo 'PASS: ' . $checks . " scheduler cross-department guards\n";
