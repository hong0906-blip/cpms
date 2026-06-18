<?php
/**
 * Monthly payroll statement PDF generation job.
 * PHP 5.6 compatible.
 *
 * Example:
 * php /home/cmbuild/www/cpms/tools/payroll_statement_monthly_job.php
 * php /home/cmbuild/www/cpms/tools/payroll_statement_monthly_job.php --force --year=2026 --month=01
 */

if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set('Asia/Seoul');
}

$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';
require_once $root . '/app/services/PayrollStatementService.php';

$force = false;
$year = (int)date('Y');
$month = (int)date('m');

if (isset($argv) && is_array($argv)) {
    for ($i = 1; $i < count($argv); $i++) {
        $arg = trim((string)$argv[$i]);
        if ($arg === '--force') {
            $force = true;
        } else if (strpos($arg, '--year=') === 0) {
            $year = (int)substr($arg, 7);
        } else if (strpos($arg, '--month=') === 0) {
            $month = (int)substr($arg, 8);
        }
    }
}

$ym = cpms_company_payroll_normalize_year_month($year, $month);
$year = (int)$ym['year'];
$month = (int)$ym['month'];

if (!$force) {
    if ((int)date('d') !== 15) {
        echo '[' . date('Y-m-d H:i:s') . '] skipped: today is not the 15th.' . PHP_EOL;
        exit(0);
    }
    if ((int)date('H') !== 8) {
        echo '[' . date('Y-m-d H:i:s') . '] skipped: current hour is not 08.' . PHP_EOL;
        exit(0);
    }
}

$result = cpms_payroll_statement_generate_month($year, $month, 'system', array(
    'force' => $force,
    'mode' => 'cron'
));

if (empty($result['ok'])) {
    echo '[' . date('Y-m-d H:i:s') . '] failed: ' . (isset($result['message']) ? $result['message'] : 'unknown error') . PHP_EOL;
    exit(1);
}

if (!empty($result['skipped'])) {
    echo '[' . date('Y-m-d H:i:s') . '] skipped: ' . (isset($result['message']) ? $result['message'] : 'already generated') . PHP_EOL;
    exit(0);
}

$data = isset($result['result']) && is_array($result['result']) ? $result['result'] : array();
$success = isset($data['success_count']) ? (int)$data['success_count'] : 0;
$failed = isset($data['failed_count']) ? (int)$data['failed_count'] : 0;
echo '[' . date('Y-m-d H:i:s') . '] done: ' . sprintf('%04d/%02d', $year, $month) . ' success=' . $success . ' failed=' . $failed . PHP_EOL;
exit(0);
