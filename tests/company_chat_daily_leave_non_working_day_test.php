<?php
/**
 * Daily leave Google Chat non-working-day regression test.
 * PHP 5.6 compatible and DB-independent.
 */

$root = dirname(__DIR__);
require_once $root . '/app/views/attendance/common.php';
require_once $root . '/app/views/common/chat_notification_helpers.php';

$failures = array();
$checks = 0;

function cpms_company_leave_day_guard($label, $condition)
{
    global $failures, $checks;
    $checks++;
    if (!$condition) $failures[] = $label;
}

cpms_company_leave_day_guard(
    'Saturday daily leave notification is blocked',
    cpms_google_chat_company_leave_block_reason(null, 'DAILY_LEAVE', 20260815) !== ''
);
cpms_company_leave_day_guard(
    'Sunday daily leave notification is blocked',
    cpms_google_chat_company_leave_block_reason(null, 'DAILY_LEAVE', 20260816) !== ''
);
cpms_company_leave_day_guard(
    'Substitute holiday daily leave notification is blocked',
    cpms_google_chat_company_leave_block_reason(null, 'DAILY_LEAVE_ADDITION', 20260817) !== ''
);
cpms_company_leave_day_guard(
    'Ordinary weekday daily leave notification is allowed',
    cpms_google_chat_company_leave_block_reason(null, 'DAILY_LEAVE', 20260818) === ''
);
cpms_company_leave_day_guard(
    'Invalid daily leave source date is blocked',
    cpms_google_chat_company_leave_block_reason(null, 'DAILY_LEAVE', 20260230) !== ''
);
cpms_company_leave_day_guard(
    'Unrelated company notifications are not blocked by the leave guard',
    cpms_google_chat_company_leave_block_reason(null, 'SAFETY_NOTICE', 20260815) === ''
);

$sendResult = cpms_google_chat_send_to_company_space(
    null,
    'weekend leave notification test',
    'DAILY_LEAVE_COMPANY_SPACE',
    20260815,
    'DAILY_LEAVE'
);
cpms_company_leave_day_guard(
    'Company-space sender rejects a weekend leave notification before API delivery',
    $sendResult === false
    && strpos(approval_google_chat_get_last_error(), '주말') !== false
);

$senderSource = file_get_contents($root . '/app/views/common/chat_notification_helpers.php');
$guardPosition = strpos($senderSource, '$leaveBlockReason = cpms_google_chat_company_leave_block_reason');
$apiPosition = strpos($senderSource, '$ok = approval_google_chat_send_message', $guardPosition);
cpms_company_leave_day_guard(
    'Non-working-day guard runs before the Google Chat API call',
    $guardPosition !== false && $apiPosition !== false && $guardPosition < $apiPosition
);

if (count($failures) > 0) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . ' / ' . $checks . "\n");
    foreach ($failures as $failure) fwrite(STDERR, '- ' . $failure . "\n");
    exit(1);
}

echo 'PASS: ' . $checks . " daily leave non-working-day checks\n";
