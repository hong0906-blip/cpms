<?php
/**
 * CPMS old JSON data archive CLI.
 * PHP 5.6 compatible.
 *
 * Examples:
 * php tools/archive_old_data.php --year=2026 --type=company_overhead --dry-run=1
 * php tools/archive_old_data.php --year=2026 --type=company_overhead --dry-run=0 --confirm=YES
 */

if (php_sapi_name() !== 'cli') {
    echo "CLI only.\n";
    exit(1);
}

require_once dirname(__DIR__) . '/app/helpers.php';
require_once dirname(__DIR__) . '/app/services/DataArchiveService.php';

function cpms_archive_cli_args($argv) {
    $args = array();
    foreach ($argv as $idx => $arg) {
        if ($idx === 0) continue;
        if (strpos($arg, '--') !== 0) continue;
        $arg = substr($arg, 2);
        $parts = explode('=', $arg, 2);
        $key = trim($parts[0]);
        $value = count($parts) > 1 ? $parts[1] : '1';
        if ($key !== '') $args[$key] = $value;
    }
    return $args;
}

function cpms_archive_cli_print($data) {
    $json = cpms_archive_json_encode($data);
    echo ($json !== false ? $json : print_r($data, true)) . "\n";
}

$args = cpms_archive_cli_args($argv);
$year = isset($args['year']) ? (int)$args['year'] : cpms_archive_get_cutoff_year((int)date('Y'));
$type = isset($args['type']) ? trim((string)$args['type']) : 'company_overhead';
$dryRun = true;
if (isset($args['dry-run']) && (string)$args['dry-run'] === '0') $dryRun = false;
if (isset($args['dry_run']) && (string)$args['dry_run'] === '0') $dryRun = false;
$confirm = isset($args['confirm']) ? trim((string)$args['confirm']) : '';

$types = cpms_archive_type_definitions();
if (!isset($types[$type])) {
    echo "Invalid --type. Available types:\n";
    foreach ($types as $typeKey => $unused) echo "  - " . $typeKey . "\n";
    exit(1);
}

$user = array('name' => 'cli', 'email' => 'cli@localhost');
$result = cpms_archive_run($year, $type, $dryRun, $confirm, $user);
cpms_archive_cli_print($result);
exit(!empty($result['ok']) ? 0 : 2);
