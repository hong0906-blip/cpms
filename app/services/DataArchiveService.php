<?php
/**
 * CPMS JSON data archive service.
 * PHP 5.6 compatible, no external dependencies.
 */

require_once __DIR__ . '/GoogleDriveHelper.php';

if (!function_exists('cpms_archive_app_root')) {
function cpms_archive_app_root() {
    return dirname(dirname(__DIR__));
}}

if (!function_exists('cpms_archive_data_root')) {
function cpms_archive_data_root() {
    return cpms_archive_app_root() . '/data';
}}

if (!function_exists('cpms_archive_storage_root')) {
function cpms_archive_storage_root() {
    if (function_exists('cpms_storage_root')) return cpms_storage_root();
    return cpms_archive_app_root() . '/storage';
}}

if (!function_exists('cpms_archive_ensure_dir')) {
function cpms_archive_ensure_dir($dir) {
    if (function_exists('cpms_ensure_dir')) return cpms_ensure_dir($dir);
    if (is_dir($dir)) return true;
    return @mkdir($dir, 0777, true);
}}

if (!function_exists('cpms_archive_json_encode')) {
function cpms_archive_json_encode($data) {
    $options = 0;
    if (defined('JSON_UNESCAPED_UNICODE')) $options = $options | JSON_UNESCAPED_UNICODE;
    if (defined('JSON_PRETTY_PRINT')) $options = $options | JSON_PRETTY_PRINT;
    if (defined('JSON_UNESCAPED_SLASHES')) $options = $options | JSON_UNESCAPED_SLASHES;
    return json_encode($data, $options);
}}

if (!function_exists('cpms_archive_read_json')) {
function cpms_archive_read_json($path, $defaultValue) {
    if (!is_file($path)) return $defaultValue;
    $txt = @file_get_contents($path);
    if ($txt === false || trim($txt) === '') return $defaultValue;
    $data = @json_decode($txt, true);
    return is_array($data) ? $data : $defaultValue;
}}

if (!function_exists('cpms_archive_write_json')) {
function cpms_archive_write_json($path, $data) {
    $dir = dirname($path);
    if (!cpms_archive_ensure_dir($dir)) return false;
    $json = cpms_archive_json_encode($data);
    if (!is_string($json)) return false;
    return (@file_put_contents($path, $json, LOCK_EX) !== false);
}}

if (!function_exists('cpms_archive_policy')) {
function cpms_archive_policy() {
    static $policy = null;
    if ($policy !== null) return $policy;
    $file = dirname(__DIR__) . '/config/archive_policy.php';
    $loaded = is_file($file) ? require $file : array();
    if (!is_array($loaded)) $loaded = array();
    $defaults = array(
        'archive_enabled' => true,
        'keep_recent_years' => 2,
        'archive_drive_root' => urldecode('%EC%8B%9C%EC%8A%A4%ED%85%9C%EB%8D%B0%EC%9D%B4%ED%84%B0%EC%95%84%EC%B9%B4%EC%9D%B4%EB%B8%8C'),
        'archive_dry_run_default' => true,
        'archive_compression' => 'gz',
        'archive_restore_cache_hours' => 24,
        'archive_backup_root_folder_id' => cpms_drive_folder_id('system_backup'),
        'archive_backup_root_name' => urldecode('00_%EC%8B%9C%EC%8A%A4%ED%85%9C%EB%B0%B1%EC%97%85'),
        'archive_management_root_name' => urldecode('04_%EA%B4%80%EB%A6%AC%EB%B6%80'),
        'archive_pending_delete_days' => 30,
    );
    foreach ($defaults as $key => $value) {
        if (!isset($loaded[$key])) $loaded[$key] = $value;
    }
    $policy = $loaded;
    return $policy;
}}

if (!function_exists('cpms_archive_get_cutoff_year')) {
function cpms_archive_get_cutoff_year($currentYear) {
    $policy = cpms_archive_policy();
    $year = (int)$currentYear;
    if ($year <= 0) $year = (int)date('Y');
    $keep = isset($policy['keep_recent_years']) ? (int)$policy['keep_recent_years'] : 2;
    if ($keep < 1) $keep = 1;
    return $year - $keep;
}}

if (!function_exists('cpms_archive_is_year_archivable')) {
function cpms_archive_is_year_archivable($year) {
    $year = (int)$year;
    if ($year < 2000 || $year > 2100) return false;
    return $year <= cpms_archive_get_cutoff_year((int)date('Y'));
}}

if (!function_exists('cpms_archive_type_definitions')) {
function cpms_archive_type_definitions() {
    return array(
        'company_overhead' => array(
            'label' => 'Company overhead general',
            'sensitivity' => 'general',
            'paths' => array(
                'company_overhead/fuel',
                'company_overhead/fixed_cost',
                'company_overhead/vehicles',
                'company_overhead/dormitories',
                'company_overhead/corporate_cards',
                'company_overhead/offices',
                'company_overhead/etc'
            )
        ),
        'fuel' => array(
            'label' => 'Fuel overhead',
            'sensitivity' => 'general',
            'paths' => array('company_overhead/fuel')
        ),
        'company_payroll' => array(
            'label' => 'Company payroll sensitive',
            'sensitivity' => 'management',
            'paths' => array(
                'company_overhead/payroll',
                'company_overhead/payroll_versions',
                'company_overhead/payroll_statements',
                'company_overhead/payroll_sensitive_logs'
            )
        ),
        'payroll' => array(
            'label' => 'Payroll versions sensitive',
            'sensitivity' => 'management',
            'paths' => array('company_overhead/payroll_versions')
        ),
        'payroll_statements' => array(
            'label' => 'Payroll statements sensitive',
            'sensitivity' => 'management',
            'paths' => array('company_overhead/payroll_statements')
        ),
        'projects' => array('label' => 'Projects', 'sensitivity' => 'general', 'paths' => array('projects')),
        'approval' => array('label' => 'Approval', 'sensitivity' => 'general', 'paths' => array('approval')),
        'construction' => array('label' => 'Construction', 'sensitivity' => 'general', 'paths' => array('construction')),
        'safety' => array('label' => 'Safety', 'sensitivity' => 'general', 'paths' => array('safety')),
        'quality' => array('label' => 'Quality', 'sensitivity' => 'general', 'paths' => array('quality')),
        'materials' => array('label' => 'Materials', 'sensitivity' => 'general', 'paths' => array('materials')),
        'equipment' => array('label' => 'Equipment', 'sensitivity' => 'general', 'paths' => array('equipment')),
        'labor' => array('label' => 'Labor', 'sensitivity' => 'general', 'paths' => array('labor')),
        'daily_reports' => array('label' => 'Daily reports', 'sensitivity' => 'general', 'paths' => array('daily_reports')),
    );
}}

if (!function_exists('cpms_archive_type_definition')) {
function cpms_archive_type_definition($type) {
    $types = cpms_archive_type_definitions();
    $type = trim((string)$type);
    return isset($types[$type]) ? $types[$type] : null;
}}

if (!function_exists('cpms_archive_relative_path')) {
function cpms_archive_relative_path($path) {
    $root = str_replace('\\', '/', realpath(cpms_archive_app_root()));
    $real = realpath($path);
    $path2 = str_replace('\\', '/', $real !== false ? $real : $path);
    if ($root !== '' && strpos($path2, $root . '/') === 0) return substr($path2, strlen($root) + 1);
    return ltrim(str_replace('\\', '/', $path), '/');
}}

if (!function_exists('cpms_archive_path_has_year')) {
function cpms_archive_path_has_year($path, $year) {
    $path = str_replace('\\', '/', (string)$path);
    $year = sprintf('%04d', (int)$year);
    if (preg_match('/(^|\/)' . preg_quote($year, '/') . '(\/|$)/', $path)) return true;
    if (preg_match('/(^|[_\-\.])' . preg_quote($year, '/') . '([_\-\.]|$)/', basename($path))) return true;
    return false;
}}

if (!function_exists('cpms_archive_is_list')) {
function cpms_archive_is_list($value) {
    if (!is_array($value)) return false;
    $i = 0;
    foreach ($value as $key => $unused) {
        if ((int)$key !== $i) return false;
        $i++;
    }
    return true;
}}

if (!function_exists('cpms_archive_record_year_matches')) {
function cpms_archive_record_year_matches($row, $year) {
    if (!is_array($row)) return false;
    $yearText = sprintf('%04d', (int)$year);
    foreach (array('year', 'document_year', 'apply_year', 'effective_year') as $key) {
        if (isset($row[$key]) && sprintf('%04d', (int)$row[$key]) === $yearText) return true;
    }
    foreach (array('ym', 'month_key', 'month', 'date', 'occurred_at', 'created_at', 'updated_at', 'work_date', 'use_date') as $key2) {
        if (!isset($row[$key2]) || is_array($row[$key2])) continue;
        $value = trim((string)$row[$key2]);
        if (strpos($value, $yearText . '-') === 0 || strpos($value, $yearText . '/') === 0 || strpos($value, $yearText . '.') === 0) return true;
        if ($key2 === 'month' && preg_match('/^\d{1,2}$/', $value) && isset($row['year']) && sprintf('%04d', (int)$row['year']) === $yearText) return true;
    }
    return false;
}}

if (!function_exists('cpms_archive_json_has_year')) {
function cpms_archive_json_has_year($data, $year, $depth) {
    if ($depth > 5) return false;
    if (is_array($data)) {
        if (cpms_archive_record_year_matches($data, $year)) return true;
        foreach ($data as $value) {
            if (is_array($value) && cpms_archive_json_has_year($value, $year, $depth + 1)) return true;
        }
    }
    return false;
}}

if (!function_exists('cpms_archive_filter_json_by_year')) {
function cpms_archive_filter_json_by_year($data, $year) {
    if (!is_array($data)) return $data;
    if (isset($data['items']) && is_array($data['items'])) {
        $copy = $data;
        $items = array();
        foreach ($data['items'] as $row) {
            if (!is_array($row) || cpms_archive_record_year_matches($row, $year) || cpms_archive_json_has_year($row, $year, 0)) $items[] = $row;
        }
        $copy['items'] = $items;
        return $copy;
    }
    if (cpms_archive_is_list($data)) {
        $rows = array();
        foreach ($data as $row2) {
            if (!is_array($row2) || cpms_archive_record_year_matches($row2, $year) || cpms_archive_json_has_year($row2, $year, 0)) $rows[] = $row2;
        }
        return $rows;
    }
    if (cpms_archive_record_year_matches($data, $year)) return $data;
    $copy2 = array();
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $filtered = cpms_archive_filter_json_by_year($value, $year);
            if (is_array($filtered) && count($filtered) === 0) continue;
            $copy2[$key] = $filtered;
        } else {
            $copy2[$key] = $value;
        }
    }
    return $copy2;
}}

if (!function_exists('cpms_archive_count_records')) {
function cpms_archive_count_records($data) {
    if (!is_array($data)) return 0;
    if (isset($data['items']) && is_array($data['items'])) return count($data['items']);
    if (cpms_archive_is_list($data)) return count($data);
    foreach (array('employees', 'rows', 'records') as $key) {
        if (isset($data[$key]) && is_array($data[$key])) return count($data[$key]);
    }
    return count($data) > 0 ? 1 : 0;
}}

if (!function_exists('cpms_archive_numeric_value')) {
function cpms_archive_numeric_value($value) {
    if (is_int($value) || is_float($value)) return (float)$value;
    $text = trim((string)$value);
    if ($text === '') return 0.0;
    $text = str_replace(',', '', $text);
    $text = preg_replace('/[^0-9.\-]/', '', $text);
    if ($text === '' || $text === '-' || !is_numeric($text)) return 0.0;
    return (float)$text;
}}

if (!function_exists('cpms_archive_record_amount')) {
function cpms_archive_record_amount($row) {
    if (!is_array($row)) return cpms_archive_numeric_value($row);
    foreach (array('amount', 'total_amount', 'net_pay', 'total_net_pay', 'gross_pay', 'cost_amount', 'salary_amount', 'pay_amount', 'recognized_amount', 'input_cost', 'total') as $key) {
        if (isset($row[$key]) && !is_array($row[$key])) return cpms_archive_numeric_value($row[$key]);
    }
    $sum = 0.0;
    foreach ($row as $key2 => $value) {
        if (is_array($value)) $sum += cpms_archive_record_amount($value);
    }
    return $sum;
}}

if (!function_exists('cpms_archive_record_month')) {
function cpms_archive_record_month($row, $fallbackMonth) {
    if (is_array($row)) {
        if (isset($row['month']) && preg_match('/^\d{1,2}$/', (string)$row['month'])) return sprintf('%02d', (int)$row['month']);
        foreach (array('ym', 'month_key', 'date', 'occurred_at', 'created_at', 'updated_at', 'work_date', 'use_date') as $key) {
            if (!isset($row[$key]) || is_array($row[$key])) continue;
            if (preg_match('/^\d{4}[-\/\.](\d{1,2})/', (string)$row[$key], $m)) return sprintf('%02d', (int)$m[1]);
        }
    }
    $fallbackMonth = trim((string)$fallbackMonth);
    if (preg_match('/^\d{2}$/', $fallbackMonth)) return $fallbackMonth;
    return '00';
}}

if (!function_exists('cpms_archive_collect_records_for_summary')) {
function cpms_archive_collect_records_for_summary($data, &$records, $limitDepth) {
    if (!is_array($data) || $limitDepth > 6) return;
    if (isset($data['items']) && is_array($data['items'])) {
        foreach ($data['items'] as $row) if (is_array($row)) $records[] = $row;
        return;
    }
    if (cpms_archive_is_list($data)) {
        foreach ($data as $row2) if (is_array($row2)) $records[] = $row2;
        return;
    }
    if (cpms_archive_record_year_matches($data, isset($data['year']) ? (int)$data['year'] : 0) || isset($data['amount']) || isset($data['total_amount']) || isset($data['net_pay'])) {
        $records[] = $data;
        return;
    }
    foreach ($data as $value) {
        if (is_array($value)) cpms_archive_collect_records_for_summary($value, $records, $limitDepth + 1);
    }
}}

if (!function_exists('cpms_archive_source_bucket')) {
function cpms_archive_source_bucket($relativePath, $type) {
    $relativePath = str_replace('\\', '/', (string)$relativePath);
    $parts = explode('/', $relativePath);
    if (count($parts) >= 3 && $parts[0] === 'data' && $parts[1] === 'company_overhead') return $parts[2];
    if (count($parts) >= 2 && $parts[0] === 'data') return $parts[1];
    return trim((string)$type) !== '' ? trim((string)$type) : 'records';
}}

if (!function_exists('cpms_archive_scan_json_files')) {
function cpms_archive_scan_json_files($path) {
    $files = array();
    if (is_file($path) && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'json') {
        $files[] = $path;
        return $files;
    }
    if (!is_dir($path)) return $files;
    try {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) continue;
            $name = $fileInfo->getFilename();
            if ($name === '.' || $name === '..') continue;
            if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'json') continue;
            $files[] = $fileInfo->getPathname();
        }
    } catch (Exception $e) {
    }
    sort($files);
    return $files;
}}

if (!function_exists('cpms_archive_find_targets')) {
function cpms_archive_find_targets($year, $type) {
    $year = (int)$year;
    $type = trim((string)$type);
    $def = cpms_archive_type_definition($type);
    $policy = cpms_archive_policy();
    $result = array(
        'ok' => false,
        'year' => sprintf('%04d', $year),
        'type' => $type,
        'type_label' => '',
        'sensitivity' => 'general',
        'cutoff_year' => cpms_archive_get_cutoff_year((int)date('Y')),
        'archive_allowed' => false,
        'files' => array(),
        'invalid_json' => array(),
        'file_count' => 0,
        'record_count' => 0,
        'original_size' => 0,
        'removable_files' => array(),
        'non_removable_files' => array(),
        'expected_file_name' => '',
        'expected_drive_path' => '',
        'message' => ''
    );
    if ($year < 2000 || $year > 2100) {
        $result['message'] = 'Invalid archive year.';
        return $result;
    }
    if (!is_array($def)) {
        $result['message'] = 'Invalid archive type.';
        return $result;
    }
    $result['type_label'] = isset($def['label']) ? (string)$def['label'] : $type;
    $result['sensitivity'] = isset($def['sensitivity']) ? (string)$def['sensitivity'] : 'general';
    $result['archive_allowed'] = cpms_archive_is_year_archivable($year);
    $result['expected_file_name'] = $type . '_' . sprintf('%04d', $year) . ((isset($policy['archive_compression']) && $policy['archive_compression'] === 'gz' && function_exists('gzencode')) ? '.json.gz' : '.json');
    $rootName = ($result['sensitivity'] === 'management') ? (string)$policy['archive_management_root_name'] : (string)$policy['archive_backup_root_name'];
    $result['expected_drive_path'] = $rootName . ' / ' . (string)$policy['archive_drive_root'] . ' / ' . sprintf('%04d', $year) . ' / ' . $result['expected_file_name'];
    if (!$result['archive_allowed']) {
        $result['ok'] = true;
        $result['message'] = 'Current year and previous year are retained on the server.';
        return $result;
    }
    $paths = isset($def['paths']) && is_array($def['paths']) ? $def['paths'] : array();
    foreach ($paths as $rel) {
        $full = cpms_archive_data_root() . '/' . trim((string)$rel, '/\\');
        $files = cpms_archive_scan_json_files($full);
        foreach ($files as $file) {
            $relative = cpms_archive_relative_path($file);
            $pathYear = cpms_archive_path_has_year($file, $year);
            $txt = @file_get_contents($file);
            if ($txt === false || trim($txt) === '') {
                $result['invalid_json'][] = array('path' => $relative, 'message' => 'Empty or unreadable JSON.');
                continue;
            }
            $data = @json_decode($txt, true);
            if (!is_array($data)) {
                $result['invalid_json'][] = array('path' => $relative, 'message' => 'JSON parse failed.');
                continue;
            }
            if (!$pathYear && !cpms_archive_json_has_year($data, $year, 0)) continue;
            $archiveData = $pathYear ? $data : cpms_archive_filter_json_by_year($data, $year);
            $recordCount = cpms_archive_count_records($archiveData);
            if ($recordCount <= 0 && is_array($archiveData) && count($archiveData) === 0) continue;
            $size = (int)@filesize($file);
            $removable = $pathYear ? true : false;
            $row = array(
                'path' => $file,
                'relative_path' => $relative,
                'source_path' => dirname($relative),
                'bucket' => cpms_archive_source_bucket($relative, $type),
                'size' => $size,
                'record_count' => $recordCount,
                'removable' => $removable,
                'path_has_year' => $pathYear,
                'data' => $archiveData
            );
            $result['files'][] = $row;
            $result['file_count']++;
            $result['record_count'] += $recordCount;
            $result['original_size'] += $size;
            if ($removable) $result['removable_files'][] = $relative;
            else $result['non_removable_files'][] = $relative;
        }
    }
    $result['ok'] = true;
    $result['message'] = $result['file_count'] > 0 ? 'Archive targets loaded.' : 'No archive target files found.';
    return $result;
}}

if (!function_exists('cpms_archive_build_summary_from_targets')) {
function cpms_archive_build_summary_from_targets($targets) {
    $year = isset($targets['year']) ? (string)$targets['year'] : '';
    $type = isset($targets['type']) ? (string)$targets['type'] : '';
    $summary = array(
        'year' => $year,
        'archive_type' => $type,
        'total' => 0.0,
        'monthly' => array(),
        'categories' => array(),
        'category_monthly' => array(),
        'record_count' => isset($targets['record_count']) ? (int)$targets['record_count'] : 0,
        'file_count' => isset($targets['file_count']) ? (int)$targets['file_count'] : 0,
        'source_paths' => array(),
        'has_data' => false
    );
    for ($i = 1; $i <= 12; $i++) $summary['monthly'][sprintf('%02d', $i)] = 0.0;
    $files = isset($targets['files']) && is_array($targets['files']) ? $targets['files'] : array();
    foreach ($files as $file) {
        if (!is_array($file)) continue;
        $bucket = isset($file['bucket']) ? (string)$file['bucket'] : $type;
        if (!isset($summary['categories'][$bucket])) $summary['categories'][$bucket] = 0.0;
        if (!isset($summary['category_monthly'][$bucket])) {
            $summary['category_monthly'][$bucket] = array();
            for ($cm = 1; $cm <= 12; $cm++) $summary['category_monthly'][$bucket][sprintf('%02d', $cm)] = 0.0;
        }
        $sourcePath = isset($file['source_path']) ? (string)$file['source_path'] : '';
        if ($sourcePath !== '') $summary['source_paths'][$sourcePath] = true;
        $records = array();
        if (isset($file['data'])) cpms_archive_collect_records_for_summary($file['data'], $records, 0);
        if (count($records) === 0 && isset($file['data']) && is_array($file['data'])) $records[] = $file['data'];
        $fallbackMonth = '';
        if (isset($file['relative_path']) && preg_match('/\/(\d{2})\.json$/', str_replace('\\', '/', $file['relative_path']), $m)) $fallbackMonth = $m[1];
        foreach ($records as $row) {
            $amount = cpms_archive_record_amount($row);
            $month = cpms_archive_record_month($row, $fallbackMonth);
            if ($month !== '00' && isset($summary['monthly'][$month])) $summary['monthly'][$month] += $amount;
            if ($month !== '00' && isset($summary['category_monthly'][$bucket][$month])) $summary['category_monthly'][$bucket][$month] += $amount;
            $summary['categories'][$bucket] += $amount;
            $summary['total'] += $amount;
            $summary['has_data'] = true;
        }
    }
    $summary['source_paths'] = array_keys($summary['source_paths']);
    return $summary;
}}

if (!function_exists('cpms_archive_public_targets')) {
function cpms_archive_public_targets($targets) {
    if (!is_array($targets)) return $targets;
    $copy = $targets;
    if (isset($copy['files']) && is_array($copy['files'])) {
        foreach ($copy['files'] as $idx => $file) {
            if (is_array($file) && isset($file['data'])) unset($file['data']);
            $copy['files'][$idx] = $file;
        }
    }
    return $copy;
}}

if (!function_exists('cpms_archive_public_package_result')) {
function cpms_archive_public_package_result($packageResult) {
    if (!is_array($packageResult)) return $packageResult;
    $copy = $packageResult;
    if (isset($copy['package'])) unset($copy['package']);
    if (isset($copy['targets'])) $copy['targets'] = cpms_archive_public_targets($copy['targets']);
    if (isset($copy['local_path']) && function_exists('cpms_drive_mask_path')) $copy['local_path'] = cpms_drive_mask_path($copy['local_path']);
    return $copy;
}}

if (!function_exists('cpms_archive_public_verify_result')) {
function cpms_archive_public_verify_result($verify) {
    if (!is_array($verify)) return $verify;
    $copy = $verify;
    if (isset($copy['package'])) unset($copy['package']);
    return $copy;
}}

if (!function_exists('cpms_archive_create_package')) {
function cpms_archive_create_package($year, $type, $createdBy) {
    $targets = cpms_archive_find_targets($year, $type);
    if (empty($targets['ok'])) return array('ok' => false, 'message' => isset($targets['message']) ? $targets['message'] : 'Target scan failed.', 'targets' => $targets);
    if (empty($targets['archive_allowed'])) return array('ok' => false, 'message' => 'Selected year is retained on the server by policy.', 'targets' => $targets);
    if (!empty($targets['invalid_json'])) return array('ok' => false, 'message' => 'Invalid JSON source exists. Archive stopped.', 'targets' => $targets);
    if ((int)$targets['file_count'] <= 0) return array('ok' => false, 'message' => 'No archive target files found.', 'targets' => $targets);
    $summary = cpms_archive_build_summary_from_targets($targets);
    $files = array();
    $records = array();
    foreach ($targets['files'] as $file) {
        if (!is_array($file)) continue;
        $bucket = isset($file['bucket']) ? (string)$file['bucket'] : 'records';
        if (!isset($records[$bucket])) $records[$bucket] = array();
        $records[$bucket][] = array(
            'relative_path' => isset($file['relative_path']) ? (string)$file['relative_path'] : '',
            'record_count' => isset($file['record_count']) ? (int)$file['record_count'] : 0,
            'data' => isset($file['data']) ? $file['data'] : array()
        );
        $files[] = array(
            'relative_path' => isset($file['relative_path']) ? (string)$file['relative_path'] : '',
            'source_path' => isset($file['source_path']) ? (string)$file['source_path'] : '',
            'bucket' => $bucket,
            'size' => isset($file['size']) ? (int)$file['size'] : 0,
            'record_count' => isset($file['record_count']) ? (int)$file['record_count'] : 0,
            'removable' => !empty($file['removable']),
            'data' => isset($file['data']) ? $file['data'] : array()
        );
    }
    $package = array(
        'archive_type' => (string)$type,
        'year' => sprintf('%04d', (int)$year),
        'created_at' => date('Y-m-d H:i:s'),
        'created_by' => (string)$createdBy,
        'source_paths' => isset($summary['source_paths']) ? $summary['source_paths'] : array(),
        'summary' => $summary,
        'files' => $files,
        'records' => $records
    );
    $json = cpms_archive_json_encode($package);
    if (!is_string($json)) return array('ok' => false, 'message' => 'Archive JSON encode failed.', 'targets' => $targets);
    $policy = cpms_archive_policy();
    $compressed = false;
    $body = $json;
    $fileName = $type . '_' . sprintf('%04d', (int)$year) . '.json';
    if (isset($policy['archive_compression']) && $policy['archive_compression'] === 'gz' && function_exists('gzencode')) {
        $gz = @gzencode($json, 9);
        if ($gz !== false) {
            $body = $gz;
            $compressed = true;
            $fileName .= '.gz';
        }
    }
    $tmpDir = cpms_archive_storage_root() . '/tmp/archive_work/' . sprintf('%04d', (int)$year);
    if (!cpms_archive_ensure_dir($tmpDir)) return array('ok' => false, 'message' => 'Archive temp directory could not be created.', 'targets' => $targets);
    $localPath = rtrim($tmpDir, '/\\') . '/' . $fileName;
    if (@file_put_contents($localPath, $body, LOCK_EX) === false) return array('ok' => false, 'message' => 'Archive package file could not be written.', 'targets' => $targets);
    $checksum = function_exists('hash_file') ? hash_file('sha256', $localPath) : md5_file($localPath);
    return array(
        'ok' => true,
        'message' => 'Archive package created.',
        'targets' => $targets,
        'package' => $package,
        'summary' => $summary,
        'local_path' => $localPath,
        'file_name' => $fileName,
        'compressed' => $compressed,
        'checksum' => $checksum,
        'original_size' => strlen($json),
        'archive_size' => (int)@filesize($localPath)
    );
}}

if (!function_exists('cpms_archive_decode_package_content')) {
function cpms_archive_decode_package_content($content, $compressed) {
    $json = (string)$content;
    if ($compressed) {
        if (!function_exists('gzdecode')) return array('ok' => false, 'message' => 'gzdecode is not available.', 'package' => null);
        $decoded = @gzdecode($json);
        if ($decoded === false) return array('ok' => false, 'message' => 'Archive gzip decode failed.', 'package' => null);
        $json = $decoded;
    }
    $data = @json_decode($json, true);
    if (!is_array($data)) return array('ok' => false, 'message' => 'Archive JSON parse failed.', 'package' => null);
    if (!isset($data['archive_type']) || !isset($data['year']) || !isset($data['files'])) return array('ok' => false, 'message' => 'Archive package structure is invalid.', 'package' => null);
    return array('ok' => true, 'message' => 'Archive package decoded.', 'package' => $data);
}}

if (!function_exists('cpms_archive_ensure_drive_year_folder')) {
function cpms_archive_ensure_drive_year_folder($year, $sensitivity, $context) {
    if (!is_array($context)) $context = array();
    $policy = cpms_archive_policy();
    $yearText = sprintf('%04d', (int)$year);
    $sensitivity = trim((string)$sensitivity);
    $rootFolderId = '';
    $rootName = '';
    if ($sensitivity === 'management') {
        $sharedDriveId = cpms_drive_shared_drive_id();
        if ($sharedDriveId === '') return array('ok' => false, 'message' => 'Shared drive ID is not configured.', 'folder_id' => '', 'http_code' => 0);
        $rootName = (string)$policy['archive_management_root_name'];
        $root = cpms_drive_find_or_create_folder($rootName, $sharedDriveId, $context);
        if (empty($root['ok']) || !isset($root['file']['id'])) return array('ok' => false, 'message' => isset($root['message']) ? $root['message'] : 'Management root folder failed.', 'folder_id' => '', 'http_code' => isset($root['http_code']) ? (int)$root['http_code'] : 0);
        $rootFolderId = (string)$root['file']['id'];
    } else {
        $rootFolderId = trim((string)$policy['archive_backup_root_folder_id']);
        $rootName = (string)$policy['archive_backup_root_name'];
        if ($rootFolderId === '') {
            $sharedDriveId2 = cpms_drive_shared_drive_id();
            if ($sharedDriveId2 === '') return array('ok' => false, 'message' => 'System backup folder ID and shared drive ID are not configured.', 'folder_id' => '', 'http_code' => 0);
            $root2 = cpms_drive_find_or_create_folder($rootName, $sharedDriveId2, $context);
            if (empty($root2['ok']) || !isset($root2['file']['id'])) return array('ok' => false, 'message' => isset($root2['message']) ? $root2['message'] : 'System backup root folder failed.', 'folder_id' => '', 'http_code' => isset($root2['http_code']) ? (int)$root2['http_code'] : 0);
            $rootFolderId = (string)$root2['file']['id'];
        }
    }
    $context['target_folder_id'] = $rootFolderId;
    $archiveRoot = cpms_drive_find_or_create_folder((string)$policy['archive_drive_root'], $rootFolderId, $context);
    if (empty($archiveRoot['ok']) || !isset($archiveRoot['file']['id'])) return array('ok' => false, 'message' => isset($archiveRoot['message']) ? $archiveRoot['message'] : 'Archive root folder failed.', 'folder_id' => '', 'http_code' => isset($archiveRoot['http_code']) ? (int)$archiveRoot['http_code'] : 0);
    $archiveRootId = (string)$archiveRoot['file']['id'];
    $context['target_folder_id'] = $archiveRootId;
    $yearFolder = cpms_drive_find_or_create_folder($yearText, $archiveRootId, $context);
    if (empty($yearFolder['ok']) || !isset($yearFolder['file']['id'])) return array('ok' => false, 'message' => isset($yearFolder['message']) ? $yearFolder['message'] : 'Archive year folder failed.', 'folder_id' => '', 'http_code' => isset($yearFolder['http_code']) ? (int)$yearFolder['http_code'] : 0);
    return array(
        'ok' => true,
        'folder_id' => (string)$yearFolder['file']['id'],
        'root_folder_id' => $rootFolderId,
        'archive_root_folder_id' => $archiveRootId,
        'root_name' => $rootName,
        'archive_root_name' => (string)$policy['archive_drive_root'],
        'year' => $yearText,
        'webViewLink' => isset($yearFolder['file']['webViewLink']) ? (string)$yearFolder['file']['webViewLink'] : '',
        'message' => 'Archive Drive folder is ready.',
        'http_code' => isset($yearFolder['http_code']) ? (int)$yearFolder['http_code'] : 0
    );
}}

if (!function_exists('cpms_archive_verify_drive_file')) {
function cpms_archive_verify_drive_file($driveFileId, $checksum, $compressed, $archiveSize) {
    $driveFileId = trim((string)$driveFileId);
    $info = cpms_drive_get_file_info($driveFileId);
    if (empty($info['ok']) || !isset($info['file']) || !is_array($info['file'])) return array('ok' => false, 'message' => isset($info['message']) ? $info['message'] : 'Drive file info failed.', 'download_size' => 0, 'http_code' => isset($info['http_code']) ? (int)$info['http_code'] : 0);
    $driveSize = (isset($info['file']['size']) && is_numeric($info['file']['size'])) ? (int)$info['file']['size'] : 0;
    if ($driveSize > 0 && (int)$archiveSize > 0 && $driveSize !== (int)$archiveSize) {
        return array('ok' => false, 'message' => 'Drive file size does not match local archive size.', 'drive_size' => $driveSize, 'download_size' => 0, 'http_code' => isset($info['http_code']) ? (int)$info['http_code'] : 0);
    }
    $download = cpms_drive_download_file($driveFileId);
    if (empty($download['ok'])) return array('ok' => false, 'message' => isset($download['message']) ? $download['message'] : 'Drive file download failed.', 'drive_size' => $driveSize, 'download_size' => 0, 'http_code' => isset($download['http_code']) ? (int)$download['http_code'] : 0);
    $content = (string)$download['content'];
    if ((int)$archiveSize > 0 && strlen($content) !== (int)$archiveSize) {
        return array('ok' => false, 'message' => 'Downloaded archive size does not match local archive size.', 'drive_size' => $driveSize, 'download_size' => strlen($content), 'http_code' => isset($download['http_code']) ? (int)$download['http_code'] : 0);
    }
    $downloadChecksum = function_exists('hash') ? hash('sha256', $content) : md5($content);
    if (trim((string)$checksum) !== '' && $downloadChecksum !== (string)$checksum) {
        return array('ok' => false, 'message' => 'Downloaded archive checksum does not match local checksum.', 'drive_size' => $driveSize, 'download_size' => strlen($content), 'http_code' => isset($download['http_code']) ? (int)$download['http_code'] : 0);
    }
    $decoded = cpms_archive_decode_package_content($content, $compressed);
    if (empty($decoded['ok'])) return array('ok' => false, 'message' => isset($decoded['message']) ? $decoded['message'] : 'Downloaded archive validation failed.', 'drive_size' => $driveSize, 'download_size' => strlen($content), 'http_code' => isset($download['http_code']) ? (int)$download['http_code'] : 0);
    return array('ok' => true, 'message' => 'Drive archive download and JSON validation succeeded.', 'drive_size' => $driveSize, 'download_size' => strlen($content), 'http_code' => isset($download['http_code']) ? (int)$download['http_code'] : 0, 'package' => $decoded['package']);
}}

if (!function_exists('cpms_archive_index_path')) {
function cpms_archive_index_path($year) {
    return cpms_archive_data_root() . '/archive_index/' . sprintf('%04d', (int)$year) . '.json';
}}

if (!function_exists('cpms_archive_summary_path')) {
function cpms_archive_summary_path($year) {
    return cpms_archive_data_root() . '/archive_summary/' . sprintf('%04d', (int)$year) . '.json';
}}

if (!function_exists('cpms_archive_write_index')) {
function cpms_archive_write_index($year, $archiveMeta) {
    $path = cpms_archive_index_path($year);
    $data = cpms_archive_read_json($path, array());
    if (!isset($data['year'])) $data['year'] = sprintf('%04d', (int)$year);
    if (!isset($data['archives']) || !is_array($data['archives'])) $data['archives'] = array();
    $archiveId = isset($archiveMeta['archive_id']) ? (string)$archiveMeta['archive_id'] : '';
    $found = false;
    foreach ($data['archives'] as $idx => $row) {
        if (is_array($row) && isset($row['archive_id']) && (string)$row['archive_id'] === $archiveId) {
            $data['archives'][$idx] = $archiveMeta;
            $found = true;
            break;
        }
    }
    if (!$found) $data['archives'][] = $archiveMeta;
    $data['updated_at'] = date('Y-m-d H:i:s');
    return cpms_archive_write_json($path, $data);
}}

if (!function_exists('cpms_archive_write_summary')) {
function cpms_archive_write_summary($year, $summary) {
    $path = cpms_archive_summary_path($year);
    $data = cpms_archive_read_json($path, array());
    $yearText = sprintf('%04d', (int)$year);
    $type = isset($summary['archive_type']) ? (string)$summary['archive_type'] : 'archive';
    $data['year'] = $yearText;
    $data[$type] = $summary;
    if ($type === 'company_overhead' || $type === 'fuel' || $type === 'company_payroll' || $type === 'payroll') {
        if (!isset($data['company_overhead']) || !is_array($data['company_overhead'])) {
            $data['company_overhead'] = array('total' => 0.0, 'monthly' => array(), 'categories' => array(), 'category_monthly' => array(), 'has_data' => false);
        }
        if (!isset($data['company_overhead']['monthly']) || !is_array($data['company_overhead']['monthly'])) $data['company_overhead']['monthly'] = array();
        if (!isset($data['company_overhead']['categories']) || !is_array($data['company_overhead']['categories'])) $data['company_overhead']['categories'] = array();
        if (!isset($data['company_overhead']['category_monthly']) || !is_array($data['company_overhead']['category_monthly'])) $data['company_overhead']['category_monthly'] = array();
        $cats = isset($summary['categories']) && is_array($summary['categories']) ? $summary['categories'] : array();
        foreach ($cats as $cat => $amount) $data['company_overhead']['categories'][$cat] = (float)$amount;
        $monthly = isset($summary['monthly']) && is_array($summary['monthly']) ? $summary['monthly'] : array();
        foreach ($monthly as $month => $amount2) {
            if (!isset($data['company_overhead']['monthly'][$month])) $data['company_overhead']['monthly'][$month] = 0.0;
            $data['company_overhead']['monthly'][$month] += (float)$amount2;
        }
        $categoryMonthly = isset($summary['category_monthly']) && is_array($summary['category_monthly']) ? $summary['category_monthly'] : array();
        foreach ($categoryMonthly as $cat2 => $monthRows) {
            if (!isset($data['company_overhead']['category_monthly'][$cat2]) || !is_array($data['company_overhead']['category_monthly'][$cat2])) $data['company_overhead']['category_monthly'][$cat2] = array();
            if (!is_array($monthRows)) continue;
            foreach ($monthRows as $month2 => $amount3) {
                if (!isset($data['company_overhead']['category_monthly'][$cat2][$month2])) $data['company_overhead']['category_monthly'][$cat2][$month2] = 0.0;
                $data['company_overhead']['category_monthly'][$cat2][$month2] += (float)$amount3;
            }
        }
        $total = 0.0;
        foreach ($data['company_overhead']['categories'] as $unused => $catAmount) $total += (float)$catAmount;
        $data['company_overhead']['total'] = $total;
        $data['company_overhead']['has_data'] = ($total > 0 || (isset($summary['record_count']) && (int)$summary['record_count'] > 0));
        if (!isset($data['company_profit']) || !is_array($data['company_profit'])) {
            $data['company_profit'] = array('confirmed_revenue' => 0, 'total_cost' => 0, 'overhead' => 0, 'profit' => 0, 'cost_rate' => 0);
        }
        $data['company_profit']['overhead'] = $data['company_overhead']['total'];
    }
    $data['updated_at'] = date('Y-m-d H:i:s');
    return cpms_archive_write_json($path, $data);
}}

if (!function_exists('cpms_archive_append_log')) {
function cpms_archive_append_log($entry) {
    if (!is_array($entry)) $entry = array();
    $year = isset($entry['year']) ? sprintf('%04d', (int)$entry['year']) : date('Y');
    $month = date('m');
    $path = cpms_archive_data_root() . '/archive_logs/' . $year . '/' . $month . '.json';
    $logs = cpms_archive_read_json($path, array());
    if (!is_array($logs)) $logs = array();
    $safe = array();
    foreach ($entry as $key => $value) {
        if (in_array($key, array('private_key', 'access_token', 'service_account_json'), true)) continue;
        if (is_string($value) && function_exists('cpms_drive_redact_text')) $value = cpms_drive_redact_text($value);
        $safe[$key] = $value;
    }
    $safe['logged_at'] = date('Y-m-d H:i:s');
    $logs[] = $safe;
    return cpms_archive_write_json($path, $logs);
}}

if (!function_exists('cpms_archive_user_label')) {
function cpms_archive_user_label($user) {
    if (function_exists('cpms_drive_user_label')) return cpms_drive_user_label($user);
    if (is_array($user)) {
        if (isset($user['name']) && trim((string)$user['name']) !== '') return trim((string)$user['name']);
        if (isset($user['email']) && trim((string)$user['email']) !== '') return trim((string)$user['email']);
    }
    $txt = trim((string)$user);
    return $txt !== '' ? $txt : '-';
}}

if (!function_exists('cpms_archive_run')) {
function cpms_archive_run($year, $type, $dryRun, $confirm, $user) {
    $year = (int)$year;
    $type = trim((string)$type);
    $dryRun = (bool)$dryRun;
    $createdBy = cpms_archive_user_label($user);
    $targets = cpms_archive_find_targets($year, $type);
    $summary = !empty($targets['ok']) ? cpms_archive_build_summary_from_targets($targets) : array();
    if ($dryRun) {
        return array('ok' => !empty($targets['ok']), 'dry_run' => true, 'message' => isset($targets['message']) ? $targets['message'] : '', 'targets' => cpms_archive_public_targets($targets), 'summary' => $summary);
    }
    $policy = cpms_archive_policy();
    if (empty($policy['archive_enabled'])) return array('ok' => false, 'dry_run' => false, 'message' => 'Archive is disabled by policy.', 'targets' => cpms_archive_public_targets($targets));
    if ((string)$confirm !== 'YES') return array('ok' => false, 'dry_run' => false, 'message' => 'Actual archive requires confirm=YES.', 'targets' => cpms_archive_public_targets($targets));
    $created = cpms_archive_create_package($year, $type, $createdBy);
    if (empty($created['ok'])) {
        cpms_archive_append_log(array('mode' => 'archive', 'year' => $year, 'archive_type' => $type, 'user' => $createdBy, 'result' => 'failed', 'error_summary' => isset($created['message']) ? $created['message'] : 'Package failed.'));
        $created['targets'] = isset($created['targets']) ? cpms_archive_public_targets($created['targets']) : array();
        return cpms_archive_public_package_result($created);
    }
    $sensitivity = isset($targets['sensitivity']) ? (string)$targets['sensitivity'] : 'general';
    $context = array(
        'user' => $user,
        'uploaded_by' => $user,
        'section' => 'data_archive',
        'document_type' => $type,
        'document_year' => sprintf('%04d', $year),
        'original_name' => $created['file_name']
    );
    $folder = cpms_archive_ensure_drive_year_folder($year, $sensitivity, $context);
    if (empty($folder['ok']) || trim((string)$folder['folder_id']) === '') {
        if (isset($created['local_path']) && is_file($created['local_path'])) @unlink($created['local_path']);
        return array('ok' => false, 'message' => isset($folder['message']) ? $folder['message'] : 'Drive folder failed.', 'package_result' => cpms_archive_public_package_result($created));
    }
    $context['target_folder_id'] = (string)$folder['folder_id'];
    $mime = !empty($created['compressed']) ? 'application/gzip' : 'application/json';
    $upload = cpms_drive_upload_file($created['local_path'], $created['file_name'], (string)$folder['folder_id'], $mime, $context);
    if (empty($upload['ok']) || !isset($upload['file']) || !is_array($upload['file'])) {
        cpms_archive_append_log(array('mode' => 'archive', 'year' => $year, 'archive_type' => $type, 'user' => $createdBy, 'result' => 'failed', 'error_summary' => isset($upload['message']) ? $upload['message'] : 'Upload failed.'));
        if (isset($created['local_path']) && is_file($created['local_path'])) @unlink($created['local_path']);
        return array('ok' => false, 'message' => isset($upload['message']) ? $upload['message'] : 'Drive upload failed.', 'package_result' => cpms_archive_public_package_result($created), 'upload' => $upload);
    }
    $fileInfo = $upload['file'];
    $driveFileId = isset($fileInfo['id']) ? (string)$fileInfo['id'] : '';
    $verify = cpms_archive_verify_drive_file($driveFileId, $created['checksum'], !empty($created['compressed']), (int)$created['archive_size']);
    if (empty($verify['ok'])) {
        cpms_archive_append_log(array('mode' => 'verify', 'year' => $year, 'archive_type' => $type, 'user' => $createdBy, 'drive_file_id' => $driveFileId, 'result' => 'failed', 'error_summary' => isset($verify['message']) ? $verify['message'] : 'Verify failed.'));
        if (isset($created['local_path']) && is_file($created['local_path'])) @unlink($created['local_path']);
        return array('ok' => false, 'message' => isset($verify['message']) ? $verify['message'] : 'Drive verification failed.', 'package_result' => cpms_archive_public_package_result($created), 'upload' => $upload, 'verify' => cpms_archive_public_verify_result($verify));
    }
    $archiveId = $type . '_' . sprintf('%04d', $year);
    $archiveMeta = array(
        'archive_id' => $archiveId,
        'archive_type' => $type,
        'sensitivity' => $sensitivity,
        'drive_file_id' => $driveFileId,
        'drive_folder_id' => (string)$folder['folder_id'],
        'drive_web_view_link' => isset($fileInfo['webViewLink']) ? (string)$fileInfo['webViewLink'] : '',
        'file_name' => (string)$created['file_name'],
        'compressed' => !empty($created['compressed']),
        'checksum' => (string)$created['checksum'],
        'original_size' => (int)$created['original_size'],
        'archive_size' => (int)$created['archive_size'],
        'record_count' => isset($targets['record_count']) ? (int)$targets['record_count'] : 0,
        'summary_total' => isset($summary['total']) ? (float)$summary['total'] : 0.0,
        'created_at' => date('Y-m-d H:i:s'),
        'created_by' => $createdBy,
        'verified_at' => date('Y-m-d H:i:s'),
        'status' => 'verified',
        'local_detail_removed' => false,
        'local_removed_at' => '',
        'source_paths' => isset($summary['source_paths']) ? $summary['source_paths'] : array(),
        'removable_files' => isset($targets['removable_files']) ? $targets['removable_files'] : array(),
        'non_removable_files' => isset($targets['non_removable_files']) ? $targets['non_removable_files'] : array()
    );
    $indexOk = cpms_archive_write_index($year, $archiveMeta);
    $summaryOk = cpms_archive_write_summary($year, $summary);
    cpms_archive_append_log(array(
        'mode' => 'archive',
        'year' => $year,
        'archive_type' => $type,
        'file_count' => isset($targets['file_count']) ? (int)$targets['file_count'] : 0,
        'original_size' => isset($targets['original_size']) ? (int)$targets['original_size'] : 0,
        'archive_size' => (int)$created['archive_size'],
        'drive_file_id' => $driveFileId,
        'verify_result' => 'ok',
        'local_removed' => false,
        'user' => $createdBy,
        'result' => ($indexOk && $summaryOk) ? 'ok' : 'partial'
    ));
    if (isset($created['local_path']) && is_file($created['local_path'])) @unlink($created['local_path']);
    return array('ok' => ($indexOk && $summaryOk), 'dry_run' => false, 'message' => ($indexOk && $summaryOk) ? 'Archive uploaded and verified.' : 'Archive verified, but index or summary write failed.', 'archive' => $archiveMeta, 'summary' => $summary, 'package_result' => cpms_archive_public_package_result($created), 'upload' => $upload, 'verify' => cpms_archive_public_verify_result($verify));
}}

if (!function_exists('cpms_archive_load_index')) {
function cpms_archive_load_index($year) {
    $data = cpms_archive_read_json(cpms_archive_index_path($year), array());
    if (!isset($data['archives']) || !is_array($data['archives'])) $data['archives'] = array();
    if (!isset($data['year'])) $data['year'] = sprintf('%04d', (int)$year);
    return $data;
}}

if (!function_exists('cpms_archive_find_index_entry')) {
function cpms_archive_find_index_entry($year, $archiveIdOrType) {
    $index = cpms_archive_load_index($year);
    $needle = trim((string)$archiveIdOrType);
    foreach ($index['archives'] as $row) {
        if (!is_array($row)) continue;
        if ((isset($row['archive_id']) && (string)$row['archive_id'] === $needle) || (isset($row['archive_type']) && (string)$row['archive_type'] === $needle)) return $row;
    }
    return null;
}}

if (!function_exists('cpms_archive_cache_root')) {
function cpms_archive_cache_root() {
    return cpms_archive_storage_root() . '/tmp/archive_cache';
}}

if (!function_exists('cpms_archive_cached_package_path')) {
function cpms_archive_cached_package_path($year, $archiveId) {
    return cpms_archive_cache_root() . '/' . sprintf('%04d', (int)$year) . '/' . preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)$archiveId) . '/package.json';
}}

if (!function_exists('cpms_archive_get_package_from_drive')) {
function cpms_archive_get_package_from_drive($year, $archive) {
    if (!is_array($archive)) return array('ok' => false, 'message' => 'Archive index entry is invalid.', 'package' => null, 'cache_path' => '');
    $archiveId = isset($archive['archive_id']) ? (string)$archive['archive_id'] : '';
    $cachePath = cpms_archive_cached_package_path($year, $archiveId);
    $policy = cpms_archive_policy();
    $maxAge = isset($policy['archive_restore_cache_hours']) ? (int)$policy['archive_restore_cache_hours'] * 3600 : 86400;
    if (is_file($cachePath) && (time() - (int)@filemtime($cachePath)) <= $maxAge) {
        $cached = cpms_archive_read_json($cachePath, null);
        if (is_array($cached)) return array('ok' => true, 'message' => 'Archive package loaded from cache.', 'package' => $cached, 'cache_path' => $cachePath, 'from_cache' => true);
    }
    $fileId = isset($archive['drive_file_id']) ? trim((string)$archive['drive_file_id']) : '';
    if ($fileId === '') return array('ok' => false, 'message' => 'Archive Drive file ID is empty.', 'package' => null, 'cache_path' => $cachePath);
    $download = cpms_drive_download_file($fileId);
    if (empty($download['ok'])) return array('ok' => false, 'message' => isset($download['message']) ? $download['message'] : 'Drive download failed.', 'package' => null, 'cache_path' => $cachePath);
    $content = (string)$download['content'];
    $checksum = isset($archive['checksum']) ? (string)$archive['checksum'] : '';
    if ($checksum !== '') {
        $downloadChecksum = function_exists('hash') ? hash('sha256', $content) : md5($content);
        if ($downloadChecksum !== $checksum) return array('ok' => false, 'message' => 'Archive cache download checksum mismatch.', 'package' => null, 'cache_path' => $cachePath);
    }
    $decoded = cpms_archive_decode_package_content($content, !empty($archive['compressed']));
    if (empty($decoded['ok'])) return array('ok' => false, 'message' => isset($decoded['message']) ? $decoded['message'] : 'Archive decode failed.', 'package' => null, 'cache_path' => $cachePath);
    if (!cpms_archive_ensure_dir(dirname($cachePath))) return array('ok' => false, 'message' => 'Archive cache directory could not be created.', 'package' => null, 'cache_path' => $cachePath);
    cpms_archive_write_json($cachePath, $decoded['package']);
    return array('ok' => true, 'message' => 'Archive package downloaded and cached.', 'package' => $decoded['package'], 'cache_path' => $cachePath, 'from_cache' => false);
}}

if (!function_exists('cpms_archive_load_detail')) {
function cpms_archive_load_detail($year, $type, $filters) {
    if (!is_array($filters)) $filters = array();
    $type = trim((string)$type);
    $candidates = array($type);
    if ($type === 'fuel') $candidates[] = 'company_overhead';
    if ($type === 'payroll') $candidates[] = 'company_payroll';
    $archive = null;
    foreach ($candidates as $candidateType) {
        $archive = cpms_archive_find_index_entry($year, $candidateType);
        if (is_array($archive) && isset($archive['status']) && (string)$archive['status'] === 'verified') break;
        $archive = null;
    }
    if (!is_array($archive)) return array('ok' => false, 'message' => 'Verified archive index entry was not found.', 'items' => array(), 'package' => null);
    $pkg = cpms_archive_get_package_from_drive($year, $archive);
    if (empty($pkg['ok']) || !isset($pkg['package']) || !is_array($pkg['package'])) return array('ok' => false, 'message' => isset($pkg['message']) ? $pkg['message'] : 'Archive package load failed.', 'items' => array(), 'package' => null);
    $package = $pkg['package'];
    $category = isset($filters['category']) ? trim((string)$filters['category']) : '';
    if ($category === '' && $type === 'fuel') $category = 'fuel';
    $month = isset($filters['month']) ? sprintf('%02d', (int)$filters['month']) : '';
    $categoryAliases = array($category);
    if ($category === 'payroll') {
        $categoryAliases[] = 'payroll_versions';
        $categoryAliases[] = 'company_payroll';
    }
    $items = array();
    $files = isset($package['files']) && is_array($package['files']) ? $package['files'] : array();
    foreach ($files as $file) {
        if (!is_array($file)) continue;
        $bucket = isset($file['bucket']) ? (string)$file['bucket'] : '';
        if ($category !== '' && !in_array($bucket, $categoryAliases, true)) continue;
        $data = isset($file['data']) ? $file['data'] : array();
        $records = array();
        cpms_archive_collect_records_for_summary($data, $records, 0);
        if (count($records) === 0 && is_array($data)) {
            if (isset($data['items']) && is_array($data['items'])) $records = $data['items'];
            else if (cpms_archive_is_list($data)) $records = $data;
        }
        foreach ($records as $row) {
            if (!is_array($row)) continue;
            if ($month !== '' && cpms_archive_record_month($row, '') !== $month) continue;
            $items[] = $row;
        }
    }
    return array('ok' => true, 'message' => 'Archive detail loaded.', 'items' => $items, 'package' => $package, 'archive' => $archive, 'cache_path' => isset($pkg['cache_path']) ? $pkg['cache_path'] : '');
}}

if (!function_exists('cpms_archive_summary_years')) {
function cpms_archive_summary_years() {
    $dir = cpms_archive_data_root() . '/archive_summary';
    $years = array();
    if (!is_dir($dir)) return $years;
    $files = @scandir($dir);
    if (!is_array($files)) return $years;
    foreach ($files as $file) {
        if (preg_match('/^(\d{4})\.json$/', $file, $m)) $years[] = (int)$m[1];
    }
    sort($years);
    return $years;
}}

if (!function_exists('cpms_archive_summary_month_category_amount')) {
function cpms_archive_summary_month_category_amount($year, $month, $category) {
    $data = cpms_archive_read_json(cpms_archive_summary_path($year), array());
    $month = sprintf('%02d', (int)$month);
    $category = trim((string)$category);
    $result = array('has_data' => false, 'amount' => 0.0);
    if (!isset($data['company_overhead']) || !is_array($data['company_overhead'])) return $result;
    $oh = $data['company_overhead'];
    $aliases = array($category);
    if ($category === 'payroll') {
        $aliases[] = 'payroll_versions';
        $aliases[] = 'company_payroll';
    }
    foreach ($aliases as $alias) {
        if ($alias === '') continue;
        if (isset($oh['category_monthly']) && is_array($oh['category_monthly']) && isset($oh['category_monthly'][$alias]) && is_array($oh['category_monthly'][$alias]) && isset($oh['category_monthly'][$alias][$month])) {
            $result['has_data'] = true;
            $result['amount'] += (float)$oh['category_monthly'][$alias][$month];
        }
    }
    if (!empty($result['has_data'])) return $result;
    foreach ($aliases as $alias2) {
        if ($alias2 === '') continue;
        if (!isset($oh['categories']) || !is_array($oh['categories']) || !isset($oh['categories'][$alias2])) continue;
        $catTotal = (float)$oh['categories'][$alias2];
        $result['has_data'] = true;
        $result['amount'] += $catTotal;
    }
    if (!empty($result['has_data'])) return $result;
    if ($category === '' && isset($oh['monthly']) && is_array($oh['monthly']) && isset($oh['monthly'][$month])) {
        $result['has_data'] = true;
        $result['amount'] = (float)$oh['monthly'][$month];
        return $result;
    }
    return $result;
}}

if (!function_exists('cpms_archive_remove_local_details')) {
function cpms_archive_remove_local_details($year, $archiveId, $mode, $confirm, $user) {
    $archive = cpms_archive_find_index_entry($year, $archiveId);
    if (!is_array($archive)) return array('ok' => false, 'message' => 'Archive index entry was not found.', 'moved' => array(), 'deleted' => array(), 'errors' => array());
    if (!isset($archive['status']) || (string)$archive['status'] !== 'verified') return array('ok' => false, 'message' => 'Only verified archives can remove local details.', 'moved' => array(), 'deleted' => array(), 'errors' => array());
    $mode = trim((string)$mode);
    if ($mode !== 'delete') $mode = 'move';
    if ($mode === 'delete' && (string)$confirm !== 'DELETE') return array('ok' => false, 'message' => 'Final delete requires confirm=DELETE.', 'moved' => array(), 'deleted' => array(), 'errors' => array());
    if ($mode === 'move' && (string)$confirm !== 'YES') return array('ok' => false, 'message' => 'Move to pending delete requires confirm=YES.', 'moved' => array(), 'deleted' => array(), 'errors' => array());
    $files = isset($archive['removable_files']) && is_array($archive['removable_files']) ? $archive['removable_files'] : array();
    $moved = array();
    $deleted = array();
    $errors = array();
    foreach ($files as $rel) {
        $rel = ltrim(str_replace('\\', '/', (string)$rel), '/');
        if ($rel === '' || strpos($rel, 'data/') !== 0) continue;
        $source = cpms_archive_app_root() . '/' . $rel;
        if (!is_file($source)) continue;
        if ($mode === 'delete') {
            if (@unlink($source)) $deleted[] = $rel;
            else $errors[] = $rel;
        } else {
            $dest = cpms_archive_storage_root() . '/archive_pending_delete/' . sprintf('%04d', (int)$year) . '/' . substr($rel, strlen('data/'));
            if (is_file($dest)) $dest .= '.' . date('YmdHis') . '.bak';
            if (!cpms_archive_ensure_dir(dirname($dest))) {
                $errors[] = $rel;
                continue;
            }
            if (@rename($source, $dest)) $moved[] = array('from' => $rel, 'to' => cpms_archive_relative_path($dest));
            else $errors[] = $rel;
        }
    }
    if (count($errors) === 0) {
        $archive['local_detail_removed'] = true;
        $archive['local_removed_at'] = date('Y-m-d H:i:s');
        $archive['local_remove_mode'] = $mode;
        cpms_archive_write_index($year, $archive);
    }
    cpms_archive_append_log(array('mode' => 'remove_local', 'year' => $year, 'archive_type' => isset($archive['archive_type']) ? $archive['archive_type'] : '', 'drive_file_id' => isset($archive['drive_file_id']) ? $archive['drive_file_id'] : '', 'local_removed' => count($errors) === 0, 'file_count' => count($files), 'user' => cpms_archive_user_label($user), 'result' => count($errors) === 0 ? 'ok' : 'partial'));
    return array('ok' => count($errors) === 0, 'message' => count($errors) === 0 ? 'Local detail files were processed.' : 'Some local detail files could not be processed.', 'moved' => $moved, 'deleted' => $deleted, 'errors' => $errors);
}}

if (!function_exists('cpms_archive_restore_from_drive')) {
function cpms_archive_restore_from_drive($year, $archiveId, $confirm, $overwrite, $user) {
    $archive = cpms_archive_find_index_entry($year, $archiveId);
    if (!is_array($archive)) return array('ok' => false, 'message' => 'Archive index entry was not found.', 'restored' => array(), 'conflicts' => array(), 'errors' => array());
    if ((string)$confirm !== 'YES') return array('ok' => false, 'message' => 'Restore requires confirm=YES.', 'restored' => array(), 'conflicts' => array(), 'errors' => array());
    $pkg = cpms_archive_get_package_from_drive($year, $archive);
    if (empty($pkg['ok']) || !isset($pkg['package']) || !is_array($pkg['package'])) return array('ok' => false, 'message' => isset($pkg['message']) ? $pkg['message'] : 'Archive package load failed.', 'restored' => array(), 'conflicts' => array(), 'errors' => array());
    $files = isset($pkg['package']['files']) && is_array($pkg['package']['files']) ? $pkg['package']['files'] : array();
    $conflicts = array();
    foreach ($files as $file) {
        if (!is_array($file) || empty($file['removable'])) continue;
        $rel = isset($file['relative_path']) ? ltrim(str_replace('\\', '/', (string)$file['relative_path']), '/') : '';
        if ($rel === '' || strpos($rel, 'data/') !== 0) continue;
        $dest = cpms_archive_app_root() . '/' . $rel;
        if (is_file($dest) && !$overwrite) $conflicts[] = $rel;
    }
    if (count($conflicts) > 0) return array('ok' => false, 'message' => 'Restore conflicts found. Existing files are not overwritten by default.', 'restored' => array(), 'conflicts' => $conflicts, 'errors' => array());
    $restored = array();
    $errors = array();
    foreach ($files as $file2) {
        if (!is_array($file2) || empty($file2['removable'])) continue;
        $rel2 = isset($file2['relative_path']) ? ltrim(str_replace('\\', '/', (string)$file2['relative_path']), '/') : '';
        if ($rel2 === '' || strpos($rel2, 'data/') !== 0) continue;
        $dest2 = cpms_archive_app_root() . '/' . $rel2;
        if (!cpms_archive_ensure_dir(dirname($dest2))) {
            $errors[] = $rel2;
            continue;
        }
        $data = isset($file2['data']) ? $file2['data'] : array();
        if (cpms_archive_write_json($dest2, $data)) $restored[] = $rel2;
        else $errors[] = $rel2;
    }
    cpms_archive_append_log(array('mode' => 'restore', 'year' => $year, 'archive_type' => isset($archive['archive_type']) ? $archive['archive_type'] : '', 'drive_file_id' => isset($archive['drive_file_id']) ? $archive['drive_file_id'] : '', 'file_count' => count($restored), 'user' => cpms_archive_user_label($user), 'result' => count($errors) === 0 ? 'ok' : 'partial'));
    return array('ok' => count($errors) === 0, 'message' => count($errors) === 0 ? 'Archive restored.' : 'Archive restore finished with errors.', 'restored' => $restored, 'conflicts' => array(), 'errors' => $errors);
}}

if (!function_exists('cpms_archive_recursive_cleanup')) {
function cpms_archive_recursive_cleanup($path, $root) {
    $realRoot = realpath($root);
    $realPath = realpath($path);
    if ($realRoot === false || $realPath === false) return false;
    $realRoot = str_replace('\\', '/', $realRoot);
    $realPath = str_replace('\\', '/', $realPath);
    if ($realPath === $realRoot || strpos($realPath, $realRoot . '/') !== 0) return false;
    if (is_file($realPath)) return @unlink($realPath);
    if (is_dir($realPath)) {
        $items = @scandir($realPath);
        if (is_array($items)) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                cpms_archive_recursive_cleanup($realPath . '/' . $item, $realRoot);
            }
        }
        return @rmdir($realPath);
    }
    return false;
}}

if (!function_exists('cpms_archive_cleanup_cache')) {
function cpms_archive_cleanup_cache() {
    $policy = cpms_archive_policy();
    $maxAge = isset($policy['archive_restore_cache_hours']) ? (int)$policy['archive_restore_cache_hours'] * 3600 : 86400;
    $root = cpms_archive_cache_root();
    $removed = array();
    if (!is_dir($root)) return array('ok' => true, 'removed' => $removed, 'message' => 'Archive cache directory does not exist.');
    $years = @scandir($root);
    if (!is_array($years)) return array('ok' => false, 'removed' => $removed, 'message' => 'Archive cache directory could not be read.');
    foreach ($years as $yearDir) {
        if ($yearDir === '.' || $yearDir === '..') continue;
        $yearPath = rtrim($root, '/\\') . '/' . $yearDir;
        if (!is_dir($yearPath)) continue;
        $archives = @scandir($yearPath);
        if (!is_array($archives)) continue;
        foreach ($archives as $archiveDir) {
            if ($archiveDir === '.' || $archiveDir === '..') continue;
            $archivePath = $yearPath . '/' . $archiveDir;
            if (!is_dir($archivePath)) continue;
            if ((time() - (int)@filemtime($archivePath)) > $maxAge) {
                if (cpms_archive_recursive_cleanup($archivePath, $root)) $removed[] = cpms_archive_relative_path($archivePath);
            }
        }
    }
    return array('ok' => true, 'removed' => $removed, 'message' => 'Archive cache cleanup completed.');
}}

if (!function_exists('cpms_archive_usage_report')) {
function cpms_archive_usage_report() {
    $root = cpms_archive_data_root();
    $report = array('total_size' => 0, 'by_year' => array(), 'file_count' => 0);
    if (!is_dir($root)) return $report;
    $files = cpms_archive_scan_json_files($root);
    foreach ($files as $file) {
        $size = (int)@filesize($file);
        $report['total_size'] += $size;
        $report['file_count']++;
        $year = 'unknown';
        $rel = cpms_archive_relative_path($file);
        if (preg_match('/\/(\d{4})(\/|[_\-\.])/', str_replace('\\', '/', $rel), $m)) $year = $m[1];
        if (!isset($report['by_year'][$year])) $report['by_year'][$year] = array('size' => 0, 'file_count' => 0);
        $report['by_year'][$year]['size'] += $size;
        $report['by_year'][$year]['file_count']++;
    }
    ksort($report['by_year']);
    return $report;
}}

if (!function_exists('cpms_archive_drive_run_admin_check')) {
function cpms_archive_drive_run_admin_check($userContext, $year) {
    $year = (int)$year > 0 ? (int)$year : (int)date('Y');
    $result = array(
        'system_backup_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'general_archive_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'general_year_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'management_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'management_archive_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'management_year_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'upload' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'download' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'json_validate' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'delete' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'supports_all_drives_delete' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'test_file' => array()
    );
    $context = array('user' => $userContext, 'section' => 'data_archive_drive_check', 'document_type' => 'data_archive', 'document_year' => sprintf('%04d', $year), 'original_name' => 'cpms_archive_drive_check.json');
    $general = cpms_archive_ensure_drive_year_folder($year, 'general', $context);
    $result['system_backup_folder'] = array('ok' => !empty($general['root_folder_id']), 'http_code' => isset($general['http_code']) ? (int)$general['http_code'] : 0, 'message' => !empty($general['root_folder_id']) ? '00 system backup folder is configured.' : (isset($general['message']) ? $general['message'] : 'System backup folder failed.'));
    $result['general_archive_folder'] = array('ok' => !empty($general['archive_root_folder_id']), 'http_code' => isset($general['http_code']) ? (int)$general['http_code'] : 0, 'message' => !empty($general['archive_root_folder_id']) ? 'General archive root folder is ready.' : (isset($general['message']) ? $general['message'] : 'General archive root failed.'));
    $result['general_year_folder'] = array('ok' => !empty($general['folder_id']), 'http_code' => isset($general['http_code']) ? (int)$general['http_code'] : 0, 'message' => !empty($general['folder_id']) ? 'General archive year folder is ready.' : (isset($general['message']) ? $general['message'] : 'General archive year failed.'));
    $management = cpms_archive_ensure_drive_year_folder($year, 'management', $context);
    $result['management_folder'] = array('ok' => !empty($management['root_folder_id']), 'http_code' => isset($management['http_code']) ? (int)$management['http_code'] : 0, 'message' => !empty($management['root_folder_id']) ? '04 management folder is ready.' : (isset($management['message']) ? $management['message'] : 'Management folder failed.'));
    $result['management_archive_folder'] = array('ok' => !empty($management['archive_root_folder_id']), 'http_code' => isset($management['http_code']) ? (int)$management['http_code'] : 0, 'message' => !empty($management['archive_root_folder_id']) ? 'Management archive root folder is ready.' : (isset($management['message']) ? $management['message'] : 'Management archive root failed.'));
    $result['management_year_folder'] = array('ok' => !empty($management['folder_id']), 'http_code' => isset($management['http_code']) ? (int)$management['http_code'] : 0, 'message' => !empty($management['folder_id']) ? 'Management archive year folder is ready.' : (isset($management['message']) ? $management['message'] : 'Management archive year failed.'));
    if (empty($general['ok']) || empty($general['folder_id'])) return $result;
    $tmpDir = cpms_archive_storage_root() . '/tmp/archive_drive_check';
    if (!cpms_archive_ensure_dir($tmpDir)) {
        $result['upload']['message'] = 'Temporary directory could not be created.';
        return $result;
    }
    $payload = array('archive_check' => true, 'created_at' => date('Y-m-d H:i:s'), 'year' => sprintf('%04d', $year), 'records' => array(array('ok' => true)));
    $json = cpms_archive_json_encode($payload);
    $body = (function_exists('gzencode') ? gzencode($json, 9) : $json);
    $compressed = function_exists('gzencode') ? true : false;
    $tmpPath = rtrim($tmpDir, '/\\') . '/cpms_archive_drive_check_' . date('Ymd_His') . ($compressed ? '.json.gz' : '.json');
    if (@file_put_contents($tmpPath, $body, LOCK_EX) === false) {
        $result['upload']['message'] = 'Temporary test file could not be written.';
        return $result;
    }
    $upload = cpms_drive_upload_file($tmpPath, basename($tmpPath), (string)$general['folder_id'], $compressed ? 'application/gzip' : 'application/json', $context);
    $result['upload'] = array('ok' => !empty($upload['ok']), 'http_code' => isset($upload['http_code']) ? (int)$upload['http_code'] : 0, 'message' => isset($upload['message']) ? (string)$upload['message'] : '');
    if (!empty($upload['ok']) && isset($upload['file']) && is_array($upload['file']) && isset($upload['file']['id'])) {
        $fileId = (string)$upload['file']['id'];
        $result['test_file'] = array('id' => $fileId, 'name' => isset($upload['file']['name']) ? (string)$upload['file']['name'] : '', 'webViewLink' => isset($upload['file']['webViewLink']) ? (string)$upload['file']['webViewLink'] : '');
        $download = cpms_drive_download_file($fileId);
        $result['download'] = array('ok' => !empty($download['ok']), 'http_code' => isset($download['http_code']) ? (int)$download['http_code'] : 0, 'message' => isset($download['message']) ? (string)$download['message'] : '');
        if (!empty($download['ok'])) {
            $decoded = cpms_archive_decode_package_content((string)$download['content'], $compressed);
            if (empty($decoded['ok'])) {
                $raw = $compressed && function_exists('gzdecode') ? @gzdecode((string)$download['content']) : (string)$download['content'];
                $parsed = @json_decode($raw, true);
                $result['json_validate'] = array('ok' => is_array($parsed), 'http_code' => 0, 'message' => is_array($parsed) ? 'Downloaded test JSON is valid.' : 'Downloaded test JSON parse failed.');
            } else {
                $result['json_validate'] = array('ok' => true, 'http_code' => 0, 'message' => 'Downloaded test archive JSON is valid.');
            }
        }
        $delete = cpms_drive_delete_file($fileId, $context);
        $result['delete'] = array('ok' => !empty($delete['ok']), 'http_code' => isset($delete['http_code']) ? (int)$delete['http_code'] : 0, 'message' => isset($delete['message']) ? (string)$delete['message'] : '');
        $result['supports_all_drives_delete'] = array('ok' => (!empty($delete['ok']) && isset($delete['http_code']) && (int)$delete['http_code'] === 204), 'http_code' => isset($delete['http_code']) ? (int)$delete['http_code'] : 0, 'message' => (isset($delete['http_code']) && (int)$delete['http_code'] === 204) ? 'Delete returned HTTP 204 with supportsAllDrives=true.' : 'Delete did not return HTTP 204.');
    }
    @unlink($tmpPath);
    return $result;
}}
