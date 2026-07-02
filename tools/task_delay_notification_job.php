<?php
/**
 * Delayed task Google Chat notification job.
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
    echo '[' . date('Y-m-d H:i:s') . '] done';
    echo ' checked=' . (isset($result['checked']) ? (int)$result['checked'] : 0);
    echo ' reserved=' . (isset($result['reserved']) ? (int)$result['reserved'] : 0);
    echo ' sent=' . (isset($result['sent']) ? (int)$result['sent'] : 0);
    echo ' failed=' . (isset($result['failed']) ? (int)$result['failed'] : 0);
    echo ' skipped=' . (isset($result['skipped']) ? (int)$result['skipped'] : 0);
    echo PHP_EOL;
    exit(0);
} catch (Exception $e) {
    echo '[' . date('Y-m-d H:i:s') . '] failed: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
