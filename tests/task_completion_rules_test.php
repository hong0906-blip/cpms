<?php
/**
 * Task completion regression guards.
 * PHP 5.6 compatible and DB-free.
 */

$root = dirname(__DIR__);
$failures = array();
$checks = 0;

function cpms_task_completion_guard($label, $condition)
{
    global $failures, $checks;
    $checks++;
    if (!$condition) $failures[] = $label;
}

$helpers = file_get_contents($root . '/app/views/tasks/helpers.php');
$complete = file_get_contents($root . '/app/views/tasks/complete.php');
$statusUpdate = file_get_contents($root . '/app/views/tasks/update_status.php');
$taskFeed = file_get_contents($root . '/app/views/tasks/task_feed_helper.php');
$dashboard = file_get_contents($root . '/app/views/tasks/dashboard_sections.php');
$detail = file_get_contents($root . '/app/views/tasks/detail.php');

cpms_task_completion_guard(
    'bulk request completion ignores inactive assignees',
    strpos($helpers, 'task_assignee.is_active = 1') !== false
    && strpos($taskFeed, 'sibling_assignee.is_active = 1') !== false
    && strpos($taskFeed, "task_main.group_key NOT LIKE 'task_request:%'") !== false
);

cpms_task_completion_guard(
    'system requests are identified without a requester or creator',
    strpos($helpers, 'function cpms_tasks_is_system_request') !== false
    && strpos($helpers, '$requesterId <= 0 && $createdBy <= 0') !== false
);

cpms_task_completion_guard(
    'system request recipients can complete directly through both endpoints',
    strpos($helpers, 'function cpms_tasks_can_complete_directly') !== false
    && strpos($complete, 'cpms_tasks_can_complete_directly($task, $currentEmployeeId)') !== false
    && strpos($statusUpdate, 'cpms_tasks_can_complete_directly($task, $currentEmployeeId)') !== false
);

cpms_task_completion_guard(
    'direct completion bypasses requester approval flow',
    strpos($complete, 'if (!$isMeetingTask && !$canCompleteDirectly)') !== false
);

cpms_task_completion_guard(
    'existing system completion requests can be finished by the recipient',
    strpos($complete, "if (\$currentStatus === 'completion_pending' && !\$canCompleteDirectly)") !== false
    && strpos($dashboard, "\$currentStatus !== 'completion_pending' || \$isSystemRequest") !== false
    && strpos($detail, "!== 'completion_pending' || \$isSystemRequest") !== false
);

if (count($failures) > 0) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . ' / ' . $checks . "\n");
    foreach ($failures as $failure) fwrite(STDERR, '- ' . $failure . "\n");
    exit(1);
}

echo 'PASS: ' . $checks . " task completion guards\n";
