<?php
/**
 * Delayed task and morning attendance Google Chat notification job.
 * PHP 5.6 compatible.
 *
 * Example:
 * php C:\www\cpms\tools\task_delay_notification_job.php
 * php C:\www\cpms\tools\task_delay_notification_job.php --limit=300
 */

if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set('Asia/Seoul');
}

$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';
require_once $root . '/app/views/tasks/helpers.php';
require_once $root . '/app/views/attendance/common.php';
require_once $root . '/app/views/common/chat_notification_helpers.php';
require_once $root . '/app/views/common/company_chat_daily_helpers.php';

$limit = 200;
if (isset($argv) && is_array($argv)) {
    for ($i = 1; $i < count($argv); $i++) {
        $arg = trim((string)$argv[$i]);
        if (strpos($arg, '--limit=') === 0) {
            $limit = (int)substr($arg, 8);
        }
    }
}
if ($limit <= 0) $limit = 200;

try {
    $pdo = \App\Core\Db::pdo();
    $result = cpms_tasks_process_delayed_notifications($pdo, $limit);
    $attendanceResult = array('checked' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0);
    if (function_exists('attendance_process_morning_missing_checkin_notifications')) {
        $attendanceResult = attendance_process_morning_missing_checkin_notifications($pdo, $limit);
    }
    $companyLeaveResult = array('checked' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'reason' => '');
    if (function_exists('cpms_company_chat_process_daily_leave')) {
        $companyLeaveResult = cpms_company_chat_process_daily_leave($pdo, false);
    }
    $companyLeaveAdditionResult = array('checked' => 0, 'queued' => 0, 'pending' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'reason' => '');
    if (function_exists('cpms_company_chat_process_daily_leave_additions')) {
        $companyLeaveAdditionResult = cpms_company_chat_process_daily_leave_additions($pdo);
    }
    echo '[' . date('Y-m-d H:i:s') . '] done';
    echo ' checked=' . (isset($result['checked']) ? (int)$result['checked'] : 0);
    echo ' reserved=' . (isset($result['reserved']) ? (int)$result['reserved'] : 0);
    echo ' sent=' . (isset($result['sent']) ? (int)$result['sent'] : 0);
    echo ' failed=' . (isset($result['failed']) ? (int)$result['failed'] : 0);
    echo ' skipped=' . (isset($result['skipped']) ? (int)$result['skipped'] : 0);
    echo ' attendance_checked=' . (isset($attendanceResult['checked']) ? (int)$attendanceResult['checked'] : 0);
    echo ' attendance_sent=' . (isset($attendanceResult['sent']) ? (int)$attendanceResult['sent'] : 0);
    echo ' attendance_failed=' . (isset($attendanceResult['failed']) ? (int)$attendanceResult['failed'] : 0);
    echo ' attendance_skipped=' . (isset($attendanceResult['skipped']) ? (int)$attendanceResult['skipped'] : 0);
    echo ' company_leave_checked=' . (isset($companyLeaveResult['checked']) ? (int)$companyLeaveResult['checked'] : 0);
    echo ' company_leave_sent=' . (isset($companyLeaveResult['sent']) ? (int)$companyLeaveResult['sent'] : 0);
    echo ' company_leave_failed=' . (isset($companyLeaveResult['failed']) ? (int)$companyLeaveResult['failed'] : 0);
    echo ' company_leave_skipped=' . (isset($companyLeaveResult['skipped']) ? (int)$companyLeaveResult['skipped'] : 0);
    if (isset($companyLeaveResult['reason']) && trim((string)$companyLeaveResult['reason']) !== '') {
        echo ' company_leave_reason=' . str_replace(array("\r", "\n"), ' ', (string)$companyLeaveResult['reason']);
    }
    echo ' company_leave_addition_queued=' . (isset($companyLeaveAdditionResult['queued']) ? (int)$companyLeaveAdditionResult['queued'] : 0);
    echo ' company_leave_addition_pending=' . (isset($companyLeaveAdditionResult['pending']) ? (int)$companyLeaveAdditionResult['pending'] : 0);
    echo ' company_leave_addition_sent=' . (isset($companyLeaveAdditionResult['sent']) ? (int)$companyLeaveAdditionResult['sent'] : 0);
    echo ' company_leave_addition_failed=' . (isset($companyLeaveAdditionResult['failed']) ? (int)$companyLeaveAdditionResult['failed'] : 0);
    if (isset($companyLeaveAdditionResult['reason']) && trim((string)$companyLeaveAdditionResult['reason']) !== '') {
        echo ' company_leave_addition_reason=' . str_replace(array("\r", "\n"), ' ', (string)$companyLeaveAdditionResult['reason']);
    }
    $monthlySummaryResult = isset($result['monthly_summary']) && is_array($result['monthly_summary'])
        ? $result['monthly_summary']
        : array('ok' => true, 'skipped' => true, 'message' => 'not due');
    echo ' monthly_summary=' . (!empty($monthlySummaryResult['skipped']) ? 'skipped' : (!empty($monthlySummaryResult['ok']) ? 'success' : 'failed'));
    if (empty($monthlySummaryResult['ok']) && isset($monthlySummaryResult['message'])) {
        echo ' monthly_summary_reason=' . str_replace(array("\r", "\n"), ' ', (string)$monthlySummaryResult['message']);
    }
    echo PHP_EOL;
    exit(0);
} catch (Exception $e) {
    echo '[' . date('Y-m-d H:i:s') . '] failed: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
