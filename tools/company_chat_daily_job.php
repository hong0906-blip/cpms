<?php
/**
 * Company Google Chat daily CLI job. PHP 5.6 compatible.
 *
 * Windows Task Scheduler examples:
 * 08:00 php C:\www\cpms\tools\company_chat_daily_job.php --type=leave
 * 19:00 php C:\www\cpms\tools\company_chat_daily_job.php --type=missing_checkout
 *
 * Linux cron examples:
 * 0 8 * * * php /www/cpms/tools/company_chat_daily_job.php --type=leave
 * 0 19 * * * php /www/cpms/tools/company_chat_daily_job.php --type=missing_checkout
 *
 * Use --force=1 to resend an already successful notification.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}
if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set('Asia/Seoul');
}

$type = 'all';
$force = false;
$limit = 500;
if (isset($argv) && is_array($argv)) {
    for ($i = 1; $i < count($argv); $i++) {
        $arg = trim((string)$argv[$i]);
        if (strpos($arg, '--type=') === 0) $type = strtolower(trim(substr($arg, 7)));
        else if (strpos($arg, '--force=') === 0) $force = ((int)substr($arg, 8) === 1);
        else if (strpos($arg, '--limit=') === 0) $limit = (int)substr($arg, 8);
    }
}
if (!in_array($type, array('leave', 'missing_checkout', 'all'), true)) {
    echo "Invalid --type. Use leave, missing_checkout, or all.\n";
    exit(2);
}

$lockPath = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'cpms_company_chat_daily_job.lock';
$lockHandle = @fopen($lockPath, 'c');
if (!$lockHandle) {
    echo '[' . date('Y-m-d H:i:s') . "] failed: job lock file could not be opened.\n";
    exit(1);
}
if (!@flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo '[' . date('Y-m-d H:i:s') . "] skipped: another company chat daily job is running.\n";
    fclose($lockHandle);
    exit(0);
}

$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';
require_once $root . '/app/views/approval/_common.php';
require_once $root . '/app/views/attendance/common.php';
require_once $root . '/app/views/common/chat_notification_helpers.php';
require_once $root . '/app/views/common/company_chat_daily_helpers.php';

$exitCode = 0;
try {
    $pdo = \App\Core\Db::pdo();
    $results = array();
    if ($type === 'leave' || $type === 'all') {
        $results[count($results)] = cpms_company_chat_process_daily_leave($pdo, $force);
    }
    if ($type === 'missing_checkout' || $type === 'all') {
        $results[count($results)] = cpms_company_chat_process_missing_checkout($pdo, $force, $limit);
    }
    for ($i = 0; $i < count($results); $i++) {
        $row = $results[$i];
        echo '[' . date('Y-m-d H:i:s') . '] type=' . (isset($row['type']) ? $row['type'] : '');
        echo ' checked=' . (isset($row['checked']) ? (int)$row['checked'] : 0);
        echo ' sent=' . (isset($row['sent']) ? (int)$row['sent'] : 0);
        echo ' failed=' . (isset($row['failed']) ? (int)$row['failed'] : 0);
        echo ' skipped=' . (isset($row['skipped']) ? (int)$row['skipped'] : 0);
        if (isset($row['reason']) && trim((string)$row['reason']) !== '') echo ' reason=' . str_replace(array("\r", "\n"), ' ', (string)$row['reason']);
        echo PHP_EOL;
        if (isset($row['failed']) && (int)$row['failed'] > 0) $exitCode = 1;
    }
} catch (Exception $e) {
    echo '[' . date('Y-m-d H:i:s') . '] failed: ' . $e->getMessage() . PHP_EOL;
    $exitCode = 1;
}

if ($lockHandle) {
    @flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}
exit($exitCode);
?>
