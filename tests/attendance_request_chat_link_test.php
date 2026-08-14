<?php
/**
 * Attendance request Google Chat link regression guards.
 * PHP 5.6 compatible and DB-independent.
 */

$root = dirname(__DIR__);
$failures = array();
$checks = 0;

function cpms_attendance_chat_link_guard($label, $condition)
{
    global $failures, $checks;
    $checks++;
    if (!$condition) $failures[] = $label;
}

$requestSave = file_get_contents($root . '/app/views/attendance/request_save.php');
$attendanceView = file_get_contents($root . '/app/views/admin/attendance.php');
$chatHelpers = file_get_contents($root . '/app/views/common/chat_notification_helpers.php');

cpms_attendance_chat_link_guard(
    'attendance request chat message links to request management',
    strpos($requestSave, "cpms_app_route_url(\$pdo, 'dashboard_executive'") !== false
    && strpos($requestSave, "'exec_tab' => 'attendanceManagement'") !== false
    && strpos($requestSave, "'atab' => 'requests'") !== false
    && strpos($requestSave, "\$messageLines[] = 'URL : '") !== false
);

cpms_attendance_chat_link_guard(
    'chat link receives the approver login token',
    strpos($chatHelpers, 'cpms_chat_login_append_missing_tokens($messageText, (int)$receiverId)') !== false
);

cpms_attendance_chat_link_guard(
    'attendance request card exposes a direct anchor',
    strpos($attendanceView, "id='attendance-request-<?php echo") !== false
    && strpos($requestSave, "#attendance-request-' . (int)\$requestId") !== false
);

if (count($failures) > 0) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . ' / ' . $checks . "\n");
    foreach ($failures as $failure) fwrite(STDERR, '- ' . $failure . "\n");
    exit(1);
}

echo 'PASS: ' . $checks . " attendance request chat link guards\n";
