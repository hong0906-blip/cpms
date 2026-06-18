<?php
/**
 * Company overhead service.
 * PHP 5.6 compatible.
 */

require_once __DIR__ . '/GoogleDriveHelper.php';
require_once __DIR__ . '/CompanyOverheadDriveService.php';
require_once __DIR__ . '/CompanyPayrollService.php';
require_once __DIR__ . '/DataArchiveSummaryService.php';
require_once __DIR__ . '/DataArchiveAccessService.php';

if (!function_exists('cpms_company_overhead_categories')) {
function cpms_company_overhead_categories() {
    return array(
        'payroll' => array('label' => '임직원 월급', 'path' => 'payroll', 'drive_label' => '임직원월급'),
        'fixed_cost' => array('label' => '고정비', 'path' => 'fixed_cost', 'drive_label' => '고정비'),
        'vehicles' => array('label' => '회사차량', 'path' => 'vehicles', 'drive_label' => '회사차량'),
        'dormitories' => array('label' => '숙소', 'path' => 'dormitories', 'drive_label' => '숙소'),
        'corporate_cards' => array('label' => '법인카드', 'path' => 'corporate_cards', 'drive_label' => '법인카드'),
        'offices' => array('label' => '사무실', 'path' => 'offices', 'drive_label' => '사무실'),
        'fuel' => array('label' => '주유비', 'path' => 'fuel', 'drive_label' => '주유비'),
        'etc' => array('label' => '기타 회사관리비', 'path' => 'etc', 'drive_label' => '기타'),
    );
}}

if (!function_exists('cpms_company_overhead_category_fields')) {
function cpms_company_overhead_category_fields($category) {
    $fields = array(
        'payroll' => array(
            array('key' => 'department', 'label' => '부서', 'type' => 'text'),
            array('key' => 'position', 'label' => '직급', 'type' => 'text'),
            array('key' => 'base_salary', 'label' => '기본급', 'type' => 'money'),
            array('key' => 'allowance', 'label' => '수당', 'type' => 'money'),
            array('key' => 'deduction', 'label' => '공제', 'type' => 'money'),
            array('key' => 'net_pay', 'label' => '실지급액', 'type' => 'money'),
        ),
        'fixed_cost' => array(
            array('key' => 'is_recurring', 'label' => '반복 여부', 'type' => 'checkbox'),
        ),
        'vehicles' => array(
            array('key' => 'vehicle_name', 'label' => '차량명/차량번호', 'type' => 'text'),
            array('key' => 'expense_type', 'label' => '비용구분', 'type' => 'text'),
            array('key' => 'driver_name', 'label' => '운전자/담당자', 'type' => 'text'),
            array('key' => 'mileage', 'label' => '주행거리', 'type' => 'text'),
        ),
        'dormitories' => array(
            array('key' => 'dormitory_name', 'label' => '숙소명', 'type' => 'text'),
            array('key' => 'address', 'label' => '주소', 'type' => 'text'),
            array('key' => 'expense_type', 'label' => '비용구분', 'type' => 'text'),
            array('key' => 'occupants', 'label' => '사용 인원/담당자', 'type' => 'text'),
        ),
        'corporate_cards' => array(
            array('key' => 'card_name', 'label' => '카드명', 'type' => 'text'),
            array('key' => 'card_last4', 'label' => '카드번호 뒷자리 4자리', 'type' => 'text'),
            array('key' => 'purpose', 'label' => '사용목적', 'type' => 'text'),
            array('key' => 'expense_type', 'label' => '비용구분', 'type' => 'text'),
        ),
        'offices' => array(
            array('key' => 'expense_type', 'label' => '비용구분', 'type' => 'text'),
        ),
        'fuel' => array(),
        'etc' => array(),
    );
    $category = trim((string)$category);
    return isset($fields[$category]) ? $fields[$category] : array();
}}

if (!function_exists('cpms_company_overhead_category_meta')) {
function cpms_company_overhead_category_meta($category) {
    $categories = cpms_company_overhead_categories();
    $category = trim((string)$category);
    return isset($categories[$category]) ? $categories[$category] : null;
}}

if (!function_exists('cpms_company_overhead_data_root')) {
function cpms_company_overhead_data_root() {
    $root = dirname(dirname(__DIR__));
    return $root . '/data/company_overhead';
}}

if (!function_exists('cpms_company_overhead_base_dirs')) {
function cpms_company_overhead_base_dirs() {
    $root = dirname(dirname(__DIR__));
    $dirs = array(cpms_company_overhead_data_root());
    if (function_exists('cpms_storage_root')) {
        $dirs[] = cpms_storage_root() . '/company_overhead';
    } else {
        $dirs[] = $root . '/storage/company_overhead';
    }
    return $dirs;
}}

if (!function_exists('cpms_company_overhead_log')) {
function cpms_company_overhead_log($message, $context) {
    if (!is_array($context)) $context = array();
    $root = function_exists('cpms_storage_root') ? cpms_storage_root() : dirname(dirname(__DIR__)) . '/storage';
    $path = $root . '/logs/company_overhead.log';
    if (function_exists('cpms_ensure_dir')) {
        cpms_ensure_dir(dirname($path));
    } else if (!is_dir(dirname($path))) {
        @mkdir(dirname($path), 0777, true);
    }
    if (function_exists('cpms_drive_redact_text')) $message = cpms_drive_redact_text($message);
    $row = array(
        'occurred_at' => date('Y-m-d H:i:s'),
        'message' => (string)$message,
        'context' => $context,
    );
    @file_put_contents($path, cpms_company_overhead_json_encode($row) . "\n", FILE_APPEND | LOCK_EX);
}}

if (!function_exists('cpms_company_overhead_json_encode')) {
function cpms_company_overhead_json_encode($data) {
    $options = 0;
    if (defined('JSON_UNESCAPED_UNICODE')) $options = $options | JSON_UNESCAPED_UNICODE;
    if (defined('JSON_PRETTY_PRINT')) $options = $options | JSON_PRETTY_PRINT;
    if (defined('JSON_UNESCAPED_SLASHES')) $options = $options | JSON_UNESCAPED_SLASHES;
    return json_encode($data, $options);
}}

if (!function_exists('cpms_company_overhead_read_json')) {
function cpms_company_overhead_read_json($path) {
    if (!is_file($path)) return null;
    $txt = @file_get_contents($path);
    if ($txt === false || trim($txt) === '') return null;
    $data = @json_decode($txt, true);
    if (!is_array($data)) {
        cpms_company_overhead_log('Company overhead JSON parse failed.', array('path' => $path));
        return null;
    }
    return $data;
}}

if (!function_exists('cpms_company_overhead_is_list')) {
function cpms_company_overhead_is_list($value) {
    if (!is_array($value)) return false;
    $i = 0;
    foreach ($value as $key => $unused) {
        if ((int)$key !== $i) return false;
        $i++;
    }
    return true;
}}

if (!function_exists('cpms_company_overhead_numeric_value')) {
function cpms_company_overhead_numeric_value($value) {
    if (is_int($value) || is_float($value)) return (float)$value;
    $value = trim((string)$value);
    if ($value === '') return 0.0;
    $value = str_replace(',', '', $value);
    $value = preg_replace('/[^0-9.\-]/', '', $value);
    if ($value === '' || $value === '-' || !is_numeric($value)) return 0.0;
    return (float)$value;
}}

if (!function_exists('cpms_company_overhead_money_value')) {
function cpms_company_overhead_money_value($value) {
    $amount = cpms_company_overhead_numeric_value($value);
    return $amount < 0 ? 0.0 : $amount;
}}

if (!function_exists('cpms_company_overhead_normalize_date')) {
function cpms_company_overhead_normalize_date($value) {
    $value = trim((string)$value);
    if ($value === '') return '';
    if (preg_match('/^(\d{4})[-\.\/년\s]*(\d{1,2})[-\.\/월\s]*(\d{1,2})/u', $value, $m)) {
        $month = (int)$m[2];
        $day = (int)$m[3];
        if ($month >= 1 && $month <= 12 && $day >= 1 && $day <= 31) {
            return sprintf('%04d-%02d-%02d', (int)$m[1], $month, $day);
        }
    }
    $ts = strtotime($value);
    if ($ts === false) return '';
    return date('Y-m-d', $ts);
}}

if (!function_exists('cpms_company_overhead_parse_year_month_text')) {
function cpms_company_overhead_parse_year_month_text($value, $defaultYear) {
    $raw = trim((string)$value);
    $defaultYear = (int)$defaultYear > 0 ? (int)$defaultYear : (int)date('Y');
    if ($raw === '') return null;

    if (preg_match('/(\d{4})\D{0,10}(\d{1,2})/u', $raw, $m)) {
        $year = (int)$m[1];
        $month = (int)$m[2];
        if ($year > 0 && $month >= 1 && $month <= 12) {
            return array('year' => sprintf('%04d', $year), 'month' => sprintf('%02d', $month));
        }
    }
    if (preg_match('/^\d{1,2}$/', $raw)) {
        $monthOnly = (int)$raw;
        if ($monthOnly >= 1 && $monthOnly <= 12) {
            return array('year' => sprintf('%04d', $defaultYear), 'month' => sprintf('%02d', $monthOnly));
        }
    }
    $ts = strtotime($raw);
    if ($ts !== false) return array('year' => date('Y', $ts), 'month' => date('m', $ts));
    return null;
}}

if (!function_exists('cpms_company_overhead_normalize_year_month')) {
function cpms_company_overhead_normalize_year_month($year, $month, $date) {
    $yearText = trim((string)$year);
    $monthText = trim((string)$month);
    $fallbackDate = cpms_company_overhead_normalize_date($date);
    $fallbackTs = $fallbackDate !== '' ? strtotime($fallbackDate) : false;
    if ($fallbackTs === false) $fallbackTs = time();
    $defaultYear = (int)date('Y', $fallbackTs);

    if (preg_match('/^\d{4}$/', $yearText) && preg_match('/^\d{1,2}$/', $monthText)) {
        $m = (int)$monthText;
        if ($m >= 1 && $m <= 12) {
            return array('year' => $yearText, 'month' => sprintf('%02d', $m), 'ym' => $yearText . '-' . sprintf('%02d', $m), 'used_fallback' => false);
        }
    }

    $parsed = cpms_company_overhead_parse_year_month_text(trim($yearText . ' ' . $monthText), $defaultYear);
    if (is_array($parsed)) {
        return array('year' => $parsed['year'], 'month' => $parsed['month'], 'ym' => $parsed['year'] . '-' . $parsed['month'], 'used_fallback' => false);
    }

    $message = 'Invalid company overhead year/month. Fallback date was used.';
    cpms_company_overhead_log($message, array('year' => $yearText, 'month' => $monthText, 'date' => $date));
    $y = date('Y', $fallbackTs);
    $m2 = date('m', $fallbackTs);
    return array('year' => $y, 'month' => $m2, 'ym' => $y . '-' . $m2, 'used_fallback' => true);
}}

if (!function_exists('cpms_company_overhead_month_valid')) {
function cpms_company_overhead_month_valid($ym) {
    return preg_match('/^\d{4}-\d{2}$/', (string)$ym) ? true : false;
}}

if (!function_exists('cpms_company_overhead_months_between')) {
function cpms_company_overhead_months_between($startYm, $endYm) {
    $months = array();
    if (!cpms_company_overhead_month_valid($startYm) || !cpms_company_overhead_month_valid($endYm)) return $months;
    $startTs = strtotime($startYm . '-01');
    $endTs = strtotime($endYm . '-01');
    if ($startTs === false || $endTs === false) return $months;
    if ($startTs > $endTs) {
        $tmp = $startTs;
        $startTs = $endTs;
        $endTs = $tmp;
    }
    $cursor = $startTs;
    while ($cursor <= $endTs) {
        $months[] = date('Y-m', $cursor);
        $cursor = strtotime('+1 month', $cursor);
    }
    return $months;
}}

if (!function_exists('cpms_company_overhead_month_file')) {
function cpms_company_overhead_month_file($baseDir, $categoryPath, $ym) {
    $ym = trim((string)$ym);
    if (!cpms_company_overhead_month_valid($ym)) return '';
    $year = substr($ym, 0, 4);
    $month = substr($ym, 5, 2);
    return rtrim($baseDir, '/\\') . '/' . $categoryPath . '/' . $year . '/' . $month . '.json';
}}

if (!function_exists('cpms_company_overhead_writable_month_file')) {
function cpms_company_overhead_writable_month_file($category, $year, $month) {
    $meta = cpms_company_overhead_category_meta($category);
    if (!is_array($meta)) return '';
    $ym = sprintf('%04d-%02d', (int)$year, (int)$month);
    return cpms_company_overhead_month_file(cpms_company_overhead_data_root(), $meta['path'], $ym);
}}

if (!function_exists('cpms_company_overhead_normalize_items')) {
function cpms_company_overhead_normalize_items($data) {
    if (!is_array($data)) return array();
    if (isset($data['items']) && is_array($data['items'])) $data = $data['items'];
    if (cpms_company_overhead_is_list($data)) return $data;
    if (isset($data['id']) || isset($data['amount'])) return array($data);
    return array();
}}

if (!function_exists('cpms_company_overhead_load_month')) {
function cpms_company_overhead_load_month($category, $year, $month, $includeDeleted = false) {
    $meta = cpms_company_overhead_category_meta($category);
    if (!is_array($meta)) return array();
    $ym = sprintf('%04d-%02d', (int)$year, (int)$month);
    $baseDirs = cpms_company_overhead_base_dirs();
    $items = array();
    foreach ($baseDirs as $baseDir) {
        $path = cpms_company_overhead_month_file($baseDir, $meta['path'], $ym);
        if ($path === '' || !is_file($path)) continue;
        $items = cpms_company_overhead_normalize_items(cpms_company_overhead_read_json($path));
        break;
    }
    if (count($items) === 0 && !$includeDeleted && function_exists('cpms_archive_load_detail')) {
        $archiveType = ($category === 'payroll') ? 'payroll' : (($category === 'fuel') ? 'fuel' : 'company_overhead');
        $archive = cpms_archive_load_detail($year, $archiveType, array('category' => $category, 'month' => $month));
        if (!empty($archive['ok']) && isset($archive['items']) && is_array($archive['items'])) {
            $items = $archive['items'];
        }
    }
    if ($includeDeleted) return $items;

    $active = array();
    foreach ($items as $item) {
        if (is_array($item) && isset($item['deleted_at']) && trim((string)$item['deleted_at']) !== '') continue;
        $active[] = $item;
    }
    return $active;
}}

if (!function_exists('cpms_company_overhead_load_month_active')) {
function cpms_company_overhead_load_month_active($category, $year, $month) {
    return cpms_company_overhead_load_month($category, $year, $month, false);
}}

if (!function_exists('cpms_company_overhead_save_month')) {
function cpms_company_overhead_save_month($category, $year, $month, $items) {
    $path = cpms_company_overhead_writable_month_file($category, $year, $month);
    if ($path === '') return false;
    $dir = dirname($path);
    if (function_exists('cpms_ensure_dir')) {
        if (!cpms_ensure_dir($dir)) return false;
    } else if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
        return false;
    }
    $json = cpms_company_overhead_json_encode(is_array($items) ? $items : array());
    return (@file_put_contents($path, $json, LOCK_EX) !== false);
}}

if (!function_exists('cpms_company_overhead_sum_record')) {
function cpms_company_overhead_sum_record($record) {
    if (!is_array($record)) return cpms_company_overhead_numeric_value($record);
    if (isset($record['deleted_at']) && trim((string)$record['deleted_at']) !== '') return 0.0;

    if (cpms_company_overhead_is_list($record)) {
        $sum = 0.0;
        foreach ($record as $row) $sum += cpms_company_overhead_sum_record($row);
        return $sum;
    }

    $amountKeys = array('amount', 'net_pay', 'total_amount', 'cost_amount', 'salary_amount', 'pay_amount', 'price', 'cost', 'total', 'value');
    foreach ($amountKeys as $key) {
        if (isset($record[$key]) && !is_array($record[$key])) return cpms_company_overhead_numeric_value($record[$key]);
    }

    if (isset($record['items']) && is_array($record['items'])) return cpms_company_overhead_sum_record($record['items']);

    $sum = 0.0;
    foreach ($record as $key => $value) {
        $keyText = strtolower((string)$key);
        if ($keyText === 'id' || $keyText === 'year' || $keyText === 'month' || $keyText === 'created_at' || $keyText === 'updated_at') continue;
        if (is_array($value)) $sum += cpms_company_overhead_sum_record($value);
    }
    return $sum;
}}

if (!function_exists('cpms_company_overhead_summary')) {
function cpms_company_overhead_summary($months) {
    $categories = cpms_company_overhead_categories();
    $summary = array(
        'total' => 0.0,
        'has_data' => false,
        'months' => is_array($months) ? $months : array(),
        'categories' => array(),
        'missing_notice' => '총관리비 데이터 미등록',
    );

    foreach ($categories as $key => $meta) {
        $summary['categories'][$key] = array(
            'label' => isset($meta['label']) ? (string)$meta['label'] : (string)$key,
            'amount' => 0.0,
            'has_data' => false,
        );
    }

    if (!is_array($months)) $months = array();
    foreach ($months as $ym) {
        if (!cpms_company_overhead_month_valid($ym)) continue;
        $year = substr($ym, 0, 4);
        $month = substr($ym, 5, 2);
        foreach ($categories as $key => $meta) {
            if (function_exists('cpms_archive_summary_month_category_amount')) {
                $archiveAmount = cpms_archive_summary_month_category_amount($year, $month, $key);
                if (!empty($archiveAmount['has_data'])) {
                    $amount = isset($archiveAmount['amount']) ? (float)$archiveAmount['amount'] : 0.0;
                    $summary['categories'][$key]['amount'] += $amount;
                    $summary['categories'][$key]['has_data'] = true;
                    $summary['has_data'] = true;
                    continue;
                }
            }
            $items = cpms_company_overhead_load_month($key, $year, $month, false);
            if (count($items) === 0 && $key === 'payroll' && function_exists('cpms_company_payroll_month_summary')) {
                $payrollSummary = cpms_company_payroll_month_summary($year, $month);
                if (empty($payrollSummary['has_data'])) continue;
                $amount = isset($payrollSummary['total_net_pay']) ? (float)$payrollSummary['total_net_pay'] : 0.0;
            } else {
                if (count($items) === 0) continue;
                $amount = cpms_company_overhead_sum_record($items);
            }
            $summary['categories'][$key]['amount'] += $amount;
            $summary['categories'][$key]['has_data'] = true;
            $summary['has_data'] = true;
        }
    }

    foreach ($summary['categories'] as $row) $summary['total'] += isset($row['amount']) ? (float)$row['amount'] : 0.0;
    return $summary;
}}

if (!function_exists('cpms_company_overhead_normalize_filters')) {
function cpms_company_overhead_normalize_filters($request) {
    if (!is_array($request)) $request = array();
    $year = isset($request['year']) ? (int)$request['year'] : (int)date('Y');
    if ($year < 2000 || $year > 2100) $year = (int)date('Y');
    $month = isset($request['month']) ? (int)$request['month'] : 0;
    if ($month < 1 || $month > 12) $month = 0;

    $startMonth = isset($request['start_month']) ? trim((string)$request['start_month']) : '';
    $endMonth = isset($request['end_month']) ? trim((string)$request['end_month']) : '';
    if (!cpms_company_overhead_month_valid($startMonth)) $startMonth = sprintf('%04d-01', $year);
    if (!cpms_company_overhead_month_valid($endMonth)) $endMonth = sprintf('%04d-12', $year);
    if ($month > 0) {
        $startMonth = sprintf('%04d-%02d', $year, $month);
        $endMonth = $startMonth;
    }

    $category = isset($request['category']) ? trim((string)$request['category']) : '';
    if ($category !== '' && !is_array(cpms_company_overhead_category_meta($category))) $category = '';
    $q = isset($request['q']) ? trim((string)$request['q']) : '';
    return array(
        'year' => $year,
        'month' => $month,
        'start_month' => $startMonth,
        'end_month' => $endMonth,
        'category' => $category,
        'q' => $q,
    );
}}

if (!function_exists('cpms_company_overhead_record_matches')) {
function cpms_company_overhead_record_matches($row, $filters) {
    if (!is_array($row)) return false;
    if (isset($row['deleted_at']) && trim((string)$row['deleted_at']) !== '') return false;
    $category = isset($filters['category']) ? trim((string)$filters['category']) : '';
    if ($category !== '' && (!isset($row['category']) || (string)$row['category'] !== $category)) return false;
    $q = isset($filters['q']) ? trim((string)$filters['q']) : '';
    if ($q === '') return true;
    $haystack = '';
    foreach ($row as $key => $value) {
        if (is_array($value)) continue;
        $haystack .= ' ' . (string)$value;
    }
    if (function_exists('mb_strtolower')) {
        return (mb_strpos(mb_strtolower($haystack, 'UTF-8'), mb_strtolower($q, 'UTF-8'), 0, 'UTF-8') !== false);
    }
    return (strpos(strtolower($haystack), strtolower($q)) !== false);
}}

if (!function_exists('cpms_company_overhead_list')) {
function cpms_company_overhead_list($filters) {
    $filters = cpms_company_overhead_normalize_filters($filters);
    $months = cpms_company_overhead_months_between($filters['start_month'], $filters['end_month']);
    $categories = cpms_company_overhead_categories();
    if ($filters['category'] !== '') $categories = array($filters['category'] => $categories[$filters['category']]);
    $rows = array();
    foreach ($months as $ym) {
        $year = substr($ym, 0, 4);
        $month = substr($ym, 5, 2);
        foreach ($categories as $category => $meta) {
            $items = cpms_company_overhead_load_month($category, $year, $month, false);
            foreach ($items as $item) {
                if (!is_array($item)) continue;
                if (!isset($item['category']) || trim((string)$item['category']) === '') $item['category'] = $category;
                if (!isset($item['category_name'])) $item['category_name'] = $meta['label'];
                if (cpms_company_overhead_record_matches($item, $filters)) $rows[] = $item;
            }
        }
    }
    usort($rows, 'cpms_company_overhead_sort_rows');
    return $rows;
}}

if (!function_exists('cpms_company_overhead_sort_rows')) {
function cpms_company_overhead_sort_rows($a, $b) {
    $aym = (isset($a['year']) ? (string)$a['year'] : '') . (isset($a['month']) ? (string)$a['month'] : '');
    $bym = (isset($b['year']) ? (string)$b['year'] : '') . (isset($b['month']) ? (string)$b['month'] : '');
    if ($aym === $bym) {
        $ad = isset($a['occurred_at']) ? (string)$a['occurred_at'] : '';
        $bd = isset($b['occurred_at']) ? (string)$b['occurred_at'] : '';
        if ($ad === $bd) return 0;
        return ($ad < $bd) ? 1 : -1;
    }
    return ($aym < $bym) ? 1 : -1;
}}

if (!function_exists('cpms_company_overhead_monthly_summary')) {
function cpms_company_overhead_monthly_summary($filters) {
    $filters = cpms_company_overhead_normalize_filters($filters);
    $months = cpms_company_overhead_months_between($filters['start_month'], $filters['end_month']);
    $categories = cpms_company_overhead_categories();
    $result = array(
        'total' => 0.0,
        'categories' => array(),
        'months' => array(),
    );
    foreach ($categories as $category => $meta) {
        $result['categories'][$category] = array('label' => $meta['label'], 'amount' => 0.0);
    }
    foreach ($months as $ym) {
        $row = array('ym' => $ym, 'year' => substr($ym, 0, 4), 'month' => substr($ym, 5, 2), 'categories' => array(), 'total' => 0.0);
        foreach ($categories as $category => $meta) {
            $amount = 0.0;
            if ($filters['category'] !== '' && $filters['category'] !== $category) {
                $row['categories'][$category] = 0.0;
                continue;
            }
            $monthFilters = $filters;
            $monthFilters['category'] = $category;
            if (trim((string)$filters['q']) === '' && function_exists('cpms_archive_summary_month_category_amount')) {
                $archiveAmount = cpms_archive_summary_month_category_amount($row['year'], $row['month'], $category);
                if (!empty($archiveAmount['has_data'])) {
                    $amount += isset($archiveAmount['amount']) ? (float)$archiveAmount['amount'] : 0.0;
                    $row['categories'][$category] = $amount;
                    $row['total'] += $amount;
                    $result['categories'][$category]['amount'] += $amount;
                    continue;
                }
            }
            $items = cpms_company_overhead_load_month($category, $row['year'], $row['month'], false);
            if (count($items) === 0 && $category === 'payroll' && trim((string)$filters['q']) === '' && function_exists('cpms_company_payroll_month_summary')) {
                $payrollSummary = cpms_company_payroll_month_summary($row['year'], $row['month']);
                if (!empty($payrollSummary['has_data'])) $amount += isset($payrollSummary['total_net_pay']) ? (float)$payrollSummary['total_net_pay'] : 0.0;
            } else {
                foreach ($items as $item) {
                    if (!is_array($item)) continue;
                    if (!isset($item['category'])) $item['category'] = $category;
                    if (cpms_company_overhead_record_matches($item, $monthFilters)) $amount += cpms_company_overhead_sum_record($item);
                }
            }
            $row['categories'][$category] = $amount;
            $row['total'] += $amount;
            $result['categories'][$category]['amount'] += $amount;
        }
        $result['months'][] = $row;
        $result['total'] += $row['total'];
    }
    return $result;
}}

if (!function_exists('cpms_company_overhead_sum')) {
function cpms_company_overhead_sum($filters) {
    $summary = cpms_company_overhead_monthly_summary($filters);
    return isset($summary['total']) ? (float)$summary['total'] : 0.0;
}}

if (!function_exists('cpms_company_overhead_user_label')) {
function cpms_company_overhead_user_label($user) {
    if (is_array($user)) {
        $name = isset($user['name']) ? trim((string)$user['name']) : '';
        $email = isset($user['email']) ? trim((string)$user['email']) : '';
        if ($name !== '') return $name;
        if ($email !== '') return $email;
        if (isset($user['id'])) return 'user#' . (int)$user['id'];
    }
    $txt = trim((string)$user);
    return $txt !== '' ? $txt : '-';
}}

if (!function_exists('cpms_company_overhead_new_id')) {
function cpms_company_overhead_new_id() {
    $rand = '';
    if (function_exists('openssl_random_pseudo_bytes')) $rand = bin2hex(openssl_random_pseudo_bytes(4));
    if ($rand === '') $rand = substr(md5(uniqid('', true)), 0, 8);
    return 'OH-' . date('YmdHis') . '-' . $rand;
}}

if (!function_exists('cpms_company_overhead_card_last4')) {
function cpms_company_overhead_card_last4($value) {
    $digits = preg_replace('/\D+/', '', (string)$value);
    if ($digits === '') return '';
    if (strlen($digits) > 4) $digits = substr($digits, -4);
    return $digits;
}}

if (!function_exists('cpms_company_overhead_amount_from_data')) {
function cpms_company_overhead_amount_from_data($category, $data) {
    if (!is_array($data)) $data = array();
    if ($category === 'payroll') {
        if (isset($data['net_pay']) && trim((string)$data['net_pay']) !== '') return cpms_company_overhead_money_value($data['net_pay']);
        $base = isset($data['base_salary']) ? cpms_company_overhead_money_value($data['base_salary']) : 0.0;
        $allowance = isset($data['allowance']) ? cpms_company_overhead_money_value($data['allowance']) : 0.0;
        $deduction = isset($data['deduction']) ? cpms_company_overhead_money_value($data['deduction']) : 0.0;
        $net = $base + $allowance - $deduction;
        return $net < 0 ? 0.0 : $net;
    }
    return isset($data['amount']) ? cpms_company_overhead_money_value($data['amount']) : 0.0;
}}

if (!function_exists('cpms_company_overhead_prepare_record')) {
function cpms_company_overhead_prepare_record($category, $data, $existing, $user) {
    $meta = cpms_company_overhead_category_meta($category);
    if (!is_array($meta)) return null;
    if (!is_array($data)) $data = array();
    $row = is_array($existing) ? $existing : array();
    $now = date('Y-m-d H:i:s');
    $fallbackDate = '';
    foreach (array('occurred_at', 'paid_at', 'created_at') as $dateKey) {
        if (isset($data[$dateKey]) && trim((string)$data[$dateKey]) !== '') {
            $fallbackDate = (string)$data[$dateKey];
            break;
        }
    }
    if ($fallbackDate === '' && isset($row['created_at'])) $fallbackDate = (string)$row['created_at'];
    if ($fallbackDate === '') $fallbackDate = $now;
    $ym = cpms_company_overhead_normalize_year_month(isset($data['year']) ? $data['year'] : '', isset($data['month']) ? $data['month'] : '', $fallbackDate);
    $userLabel = cpms_company_overhead_user_label($user);

    if (!isset($row['id']) || trim((string)$row['id']) === '') {
        $row['id'] = cpms_company_overhead_new_id();
        $row['created_by'] = $userLabel;
        $row['created_at'] = $now;
    }
    if (!isset($row['created_by']) || trim((string)$row['created_by']) === '') $row['created_by'] = $userLabel;
    if (!isset($row['created_at']) || trim((string)$row['created_at']) === '') $row['created_at'] = $now;
    $row['category'] = $category;
    $row['category_name'] = $meta['label'];
    $row['year'] = $ym['year'];
    $row['month'] = $ym['month'];
    $row['title'] = isset($data['title']) ? trim((string)$data['title']) : (isset($row['title']) ? (string)$row['title'] : '');
    if ($row['title'] === '') $row['title'] = $meta['label'] . ' ' . $row['year'] . '-' . $row['month'];
    $row['amount'] = cpms_company_overhead_amount_from_data($category, $data);
    $row['occurred_at'] = isset($data['occurred_at']) ? cpms_company_overhead_normalize_date($data['occurred_at']) : (isset($row['occurred_at']) ? (string)$row['occurred_at'] : '');
    $row['paid_at'] = isset($data['paid_at']) ? cpms_company_overhead_normalize_date($data['paid_at']) : (isset($row['paid_at']) ? (string)$row['paid_at'] : '');
    $row['payment_method'] = isset($data['payment_method']) ? trim((string)$data['payment_method']) : (isset($row['payment_method']) ? (string)$row['payment_method'] : '');
    $row['vendor'] = isset($data['vendor']) ? trim((string)$data['vendor']) : (isset($row['vendor']) ? (string)$row['vendor'] : '');
    $row['employee_name'] = isset($data['employee_name']) ? trim((string)$data['employee_name']) : (isset($row['employee_name']) ? (string)$row['employee_name'] : '');
    $row['memo'] = isset($data['memo']) ? trim((string)$data['memo']) : (isset($row['memo']) ? (string)$row['memo'] : '');

    $allKeys = array('department', 'position', 'base_salary', 'allowance', 'deduction', 'net_pay', 'is_recurring', 'vehicle_name', 'expense_type', 'driver_name', 'mileage', 'dormitory_name', 'address', 'occupants', 'card_name', 'card_last4', 'purpose');
    foreach ($allKeys as $key) {
        if (!isset($data[$key])) {
            if (!isset($row[$key])) $row[$key] = '';
            continue;
        }
        if ($key === 'base_salary' || $key === 'allowance' || $key === 'deduction' || $key === 'net_pay') {
            $row[$key] = cpms_company_overhead_money_value($data[$key]);
        } else if ($key === 'is_recurring') {
            $row[$key] = ((string)$data[$key] === '1' || (string)$data[$key] === 'on') ? 1 : 0;
        } else if ($key === 'card_last4') {
            $row[$key] = cpms_company_overhead_card_last4($data[$key]);
        } else {
            $row[$key] = trim((string)$data[$key]);
        }
    }
    if ($category === 'payroll') $row['net_pay'] = $row['amount'];
    if ($category === 'corporate_cards') $row['card_last4'] = cpms_company_overhead_card_last4(isset($row['card_last4']) ? $row['card_last4'] : '');

    foreach (array('storage_type', 'drive_file_id', 'drive_folder_id', 'drive_web_view_link', 'drive_web_content_link', 'original_name', 'stored_name', 'mime_type', 'size', 'drive_year_folder_id', 'drive_type_folder_id', 'drive_month_folder_id', 'upload_status', 'drive_upload_error') as $fileKey) {
        if (!isset($row[$fileKey])) $row[$fileKey] = ($fileKey === 'size' ? 0 : '');
    }
    $row['updated_by'] = $userLabel;
    $row['updated_at'] = $now;
    if (!isset($row['deleted_at'])) $row['deleted_at'] = '';
    if (!isset($row['deleted_by'])) $row['deleted_by'] = '';
    return $row;
}}

if (!function_exists('cpms_company_overhead_has_upload')) {
function cpms_company_overhead_has_upload($file) {
    return is_array($file) && isset($file['error']) && (int)$file['error'] !== UPLOAD_ERR_NO_FILE && isset($file['tmp_name']) && trim((string)$file['tmp_name']) !== '';
}}

if (!function_exists('cpms_company_overhead_ensure_drive_month_folder')) {
function cpms_company_overhead_ensure_drive_month_folder($category, $year, $month, $context) {
    $meta = cpms_company_overhead_category_meta($category);
    if (!is_array($meta)) return array('ok' => false, 'message' => 'Invalid company overhead category.', 'http_code' => 0);
    if (!is_array($context)) $context = array();
    $context['section'] = 'company_overhead';
    $context['document_year'] = (string)$year;
    $context['document_month'] = (string)$month;
    $context['document_type'] = $meta['label'];
    return cpms_company_overhead_drive_ensure_month_folder($category, $meta['drive_label'], $year, $month, $context);
}}

if (!function_exists('cpms_company_overhead_build_drive_name')) {
function cpms_company_overhead_build_drive_name($record, $originalName, $user) {
    $date = isset($record['occurred_at']) && trim((string)$record['occurred_at']) !== '' ? (string)$record['occurred_at'] : date('Y-m-d');
    $categoryName = isset($record['category_name']) ? (string)$record['category_name'] : '';
    $title = isset($record['title']) ? (string)$record['title'] : '';
    $userName = cpms_company_overhead_user_label($user);
    $name = $date . '_총관리비_' . $categoryName . '_' . $title . '_' . $userName . '_' . date('His') . '_' . mt_rand(1000, 9999) . '_' . $originalName;
    return cpms_drive_sanitize_file_name($name, 180);
}}

if (!function_exists('cpms_company_overhead_upload_attachment')) {
function cpms_company_overhead_upload_attachment($category, $record, $file, $user) {
    if (!cpms_company_overhead_has_upload($file)) return array('ok' => true, 'no_file' => true, 'record' => array(), 'message' => '');
    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        $message = 'Company overhead attachment upload error: ' . (int)$file['error'];
        cpms_company_overhead_log($message, array('category' => $category));
        return array('ok' => false, 'record' => array('upload_status' => 'failed', 'drive_upload_error' => $message), 'message' => $message, 'http_code' => 0);
    }
    $originalName = isset($file['name']) ? trim((string)$file['name']) : 'attachment';
    $tmpPath = isset($file['tmp_name']) ? trim((string)$file['tmp_name']) : '';
    $mimeType = cpms_drive_detect_mime_type($tmpPath);
    $size = is_file($tmpPath) ? (int)@filesize($tmpPath) : 0;
    $context = array(
        'user' => $user,
        'uploaded_by' => $user,
        'section' => 'company_overhead',
        'document_type' => isset($record['category_name']) ? (string)$record['category_name'] : $category,
        'document_year' => isset($record['year']) ? (string)$record['year'] : '',
        'document_month' => isset($record['month']) ? (string)$record['month'] : '',
        'original_name' => $originalName,
        'mime_type' => $mimeType,
        'size' => $size
    );
    $target = cpms_company_overhead_ensure_drive_month_folder($category, $record['year'], $record['month'], $context);
    if (empty($target['ok'])) {
        $message = isset($target['message']) ? (string)$target['message'] : 'Company overhead Drive folder preparation failed.';
        cpms_drive_log_upload_failure(array_merge($context, array('message' => $message, 'http_status' => isset($target['http_code']) ? (int)$target['http_code'] : 0)));
        return array('ok' => false, 'record' => array('original_name' => $originalName, 'mime_type' => $mimeType, 'size' => $size, 'storage_type' => 'local', 'upload_status' => 'failed', 'drive_upload_error' => cpms_drive_redact_text($message)), 'message' => $message, 'http_code' => isset($target['http_code']) ? (int)$target['http_code'] : 0);
    }
    $context['target_folder_id'] = (string)$target['folder_id'];
    $context['drive_year_folder_id'] = (string)$target['year_folder_id'];
    $context['drive_type_folder_id'] = (string)$target['category_folder_id'];
    $context['drive_month_folder_id'] = (string)$target['month_folder_id'];
    $driveName = cpms_company_overhead_build_drive_name($record, $originalName, $user);
    $upload = cpms_drive_upload_file($tmpPath, $driveName, (string)$target['folder_id'], $mimeType, $context);
    if (empty($upload['ok']) || !isset($upload['file']) || !is_array($upload['file'])) {
        $message = isset($upload['message']) ? (string)$upload['message'] : 'Company overhead Drive upload failed.';
        return array('ok' => false, 'record' => array('original_name' => $originalName, 'mime_type' => $mimeType, 'size' => $size, 'storage_type' => 'local', 'upload_status' => 'failed', 'drive_upload_error' => cpms_drive_redact_text($message)), 'message' => $message, 'http_code' => isset($upload['http_code']) ? (int)$upload['http_code'] : 0);
    }
    $driveRecord = cpms_drive_build_file_record($upload['file'], $context);
    $driveRecord['drive_year_folder_id'] = (string)$target['year_folder_id'];
    $driveRecord['drive_type_folder_id'] = (string)$target['category_folder_id'];
    $driveRecord['drive_month_folder_id'] = (string)$target['month_folder_id'];
    $driveRecord['upload_status'] = 'uploaded';
    $driveRecord['drive_upload_error'] = '';
    return array('ok' => true, 'record' => $driveRecord, 'message' => isset($upload['message']) ? (string)$upload['message'] : '', 'http_code' => isset($upload['http_code']) ? (int)$upload['http_code'] : 0);
}}

if (!function_exists('cpms_company_overhead_apply_upload_record')) {
function cpms_company_overhead_apply_upload_record($row, $uploadRecord) {
    if (!is_array($uploadRecord) || count($uploadRecord) === 0) return $row;
    foreach (array('storage_type', 'drive_file_id', 'drive_folder_id', 'drive_web_view_link', 'drive_web_content_link', 'original_name', 'stored_name', 'mime_type', 'size', 'drive_year_folder_id', 'drive_type_folder_id', 'drive_month_folder_id', 'upload_status', 'drive_upload_error') as $key) {
        if (isset($uploadRecord[$key])) $row[$key] = $uploadRecord[$key];
    }
    return $row;
}}

if (!function_exists('cpms_company_overhead_add')) {
function cpms_company_overhead_add($category, $data, $file, $user = null) {
    $record = cpms_company_overhead_prepare_record($category, $data, null, $user);
    if (!is_array($record)) return array('ok' => false, 'message' => '총관리비 구분이 올바르지 않습니다.');
    $upload = cpms_company_overhead_upload_attachment($category, $record, $file, $user);
    if (is_array($upload) && isset($upload['record'])) $record = cpms_company_overhead_apply_upload_record($record, $upload['record']);
    $items = cpms_company_overhead_load_month($category, $record['year'], $record['month'], true);
    $items[] = $record;
    $saved = cpms_company_overhead_save_month($category, $record['year'], $record['month'], $items);
    if (!$saved) {
        if (isset($record['drive_file_id']) && trim((string)$record['drive_file_id']) !== '') {
            cpms_drive_delete_file((string)$record['drive_file_id'], array('user' => $user, 'section' => 'company_overhead', 'message' => 'JSON save failed after Drive upload.'));
        }
        return array('ok' => false, 'message' => '총관리비 데이터를 저장하지 못했습니다.');
    }
    return array('ok' => true, 'record' => $record, 'upload' => $upload, 'message' => empty($upload['ok']) ? '저장은 완료되었지만 첨부 업로드는 실패했습니다.' : '총관리비가 저장되었습니다.');
}}

if (!function_exists('cpms_company_overhead_find_location')) {
function cpms_company_overhead_find_location($category, $id, $year, $month) {
    $meta = cpms_company_overhead_category_meta($category);
    if (!is_array($meta)) return null;
    $id = trim((string)$id);
    if ($id === '') return null;
    $months = array();
    if ((int)$year > 0 && (int)$month > 0) $months[] = sprintf('%04d-%02d', (int)$year, (int)$month);
    if (count($months) === 0) {
        $base = cpms_company_overhead_data_root() . '/' . $meta['path'];
        if (is_dir($base)) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base));
            foreach ($files as $fileInfo) {
                if (!$fileInfo->isFile()) continue;
                $path = str_replace('\\', '/', $fileInfo->getPathname());
                if (!preg_match('/\/(\d{4})\/(\d{2})\.json$/', $path, $m)) continue;
                $months[] = $m[1] . '-' . $m[2];
            }
        }
    }
    foreach ($months as $ym) {
        $items = cpms_company_overhead_load_month($category, substr($ym, 0, 4), substr($ym, 5, 2), true);
        foreach ($items as $idx => $item) {
            if (is_array($item) && isset($item['id']) && (string)$item['id'] === $id) {
                return array('ym' => $ym, 'year' => substr($ym, 0, 4), 'month' => substr($ym, 5, 2), 'index' => $idx, 'items' => $items, 'record' => $item);
            }
        }
    }
    return null;
}}

if (!function_exists('cpms_company_overhead_find')) {
function cpms_company_overhead_find($category, $id, $year, $month) {
    $loc = cpms_company_overhead_find_location($category, $id, $year, $month);
    return is_array($loc) && isset($loc['record']) ? $loc['record'] : null;
}}

if (!function_exists('cpms_company_overhead_update')) {
function cpms_company_overhead_update($category, $id, $data, $file, $user = null) {
    $loc = cpms_company_overhead_find_location($category, $id, isset($data['original_year']) ? $data['original_year'] : 0, isset($data['original_month']) ? $data['original_month'] : 0);
    if (!is_array($loc)) return array('ok' => false, 'message' => '수정할 총관리비 데이터를 찾지 못했습니다.');
    $old = $loc['record'];
    $record = cpms_company_overhead_prepare_record($category, $data, $old, $user);
    if (!is_array($record)) return array('ok' => false, 'message' => '총관리비 구분이 올바르지 않습니다.');
    $oldDriveFileId = isset($old['drive_file_id']) ? trim((string)$old['drive_file_id']) : '';
    $upload = cpms_company_overhead_upload_attachment($category, $record, $file, $user);
    if (is_array($upload) && isset($upload['record']) && empty($upload['no_file'])) {
        $record = cpms_company_overhead_apply_upload_record($record, $upload['record']);
    }

    $oldYm = $loc['ym'];
    $newYm = $record['year'] . '-' . $record['month'];
    if ($oldYm === $newYm) {
        $items = $loc['items'];
        $items[$loc['index']] = $record;
        $saved = cpms_company_overhead_save_month($category, $record['year'], $record['month'], $items);
    } else {
        $oldItems = $loc['items'];
        unset($oldItems[$loc['index']]);
        $oldItems = array_values($oldItems);
        $newItems = cpms_company_overhead_load_month($category, $record['year'], $record['month'], true);
        $newItems[] = $record;
        $saved = cpms_company_overhead_save_month($category, $record['year'], $record['month'], $newItems);
        if ($saved) {
            $saved = cpms_company_overhead_save_month($category, substr($oldYm, 0, 4), substr($oldYm, 5, 2), $oldItems);
            if (!$saved) {
                $rollbackItems = cpms_company_overhead_load_month($category, $record['year'], $record['month'], true);
                $cleanRollback = array();
                foreach ($rollbackItems as $rollbackRow) {
                    if (is_array($rollbackRow) && isset($rollbackRow['id']) && (string)$rollbackRow['id'] === (string)$record['id']) continue;
                    $cleanRollback[] = $rollbackRow;
                }
                cpms_company_overhead_save_month($category, $record['year'], $record['month'], $cleanRollback);
            }
        }
    }
    if (!$saved) {
        if (isset($record['drive_file_id']) && trim((string)$record['drive_file_id']) !== '' && trim((string)$record['drive_file_id']) !== $oldDriveFileId) {
            cpms_drive_delete_file((string)$record['drive_file_id'], array('user' => $user, 'section' => 'company_overhead', 'message' => 'JSON save failed after Drive upload.'));
        }
        return array('ok' => false, 'message' => '총관리비 데이터를 수정하지 못했습니다.');
    }
    if ($oldDriveFileId !== '' && isset($record['drive_file_id']) && trim((string)$record['drive_file_id']) !== '' && trim((string)$record['drive_file_id']) !== $oldDriveFileId) {
        cpms_drive_delete_file($oldDriveFileId, array('user' => $user, 'section' => 'company_overhead', 'message' => 'Old overhead attachment replaced.'));
    }
    return array('ok' => true, 'record' => $record, 'upload' => $upload, 'message' => empty($upload['ok']) ? '수정은 완료되었지만 첨부 업로드는 실패했습니다.' : '총관리비가 수정되었습니다.');
}}

if (!function_exists('cpms_company_overhead_delete')) {
function cpms_company_overhead_delete($category, $id, $year, $month, $user = null) {
    $loc = cpms_company_overhead_find_location($category, $id, $year, $month);
    if (!is_array($loc)) return array('ok' => false, 'message' => '삭제할 총관리비 데이터를 찾지 못했습니다.');
    $record = $loc['record'];
    $record['deleted_at'] = date('Y-m-d H:i:s');
    $record['deleted_by'] = cpms_company_overhead_user_label($user);
    $record['updated_at'] = $record['deleted_at'];
    $record['updated_by'] = $record['deleted_by'];
    $items = $loc['items'];
    $items[$loc['index']] = $record;
    $saved = cpms_company_overhead_save_month($category, $loc['year'], $loc['month'], $items);
    if (!$saved) return array('ok' => false, 'message' => '총관리비 데이터를 삭제하지 못했습니다.');
    if (isset($record['drive_file_id']) && trim((string)$record['drive_file_id']) !== '') {
        $delete = cpms_drive_delete_file((string)$record['drive_file_id'], array('user' => $user, 'section' => 'company_overhead', 'message' => 'Overhead item soft-deleted.'));
        if (empty($delete['ok'])) cpms_company_overhead_log('Company overhead Drive delete failed.', array('id' => $id, 'category' => $category));
    }
    return array('ok' => true, 'record' => $record, 'message' => '총관리비가 삭제되었습니다.');
}}

if (!function_exists('cpms_company_overhead_drive_run_admin_check')) {
function cpms_company_overhead_drive_run_admin_check($userContext) {
    $year = date('Y');
    $month = date('m');
    $categories = cpms_company_overhead_categories();
    $result = array(
        'shared_drive' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'management_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'company_management_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'overhead_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'category_folders' => array(),
        'year_folders' => array(),
        'month_folders' => array(),
        'fuel_original_folder' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'upload' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'delete' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'supports_all_drives_delete' => array('ok' => false, 'http_code' => 0, 'message' => ''),
        'test_file' => array(),
    );

    $sharedDriveId = cpms_drive_shared_drive_id();
    if ($sharedDriveId !== '') {
        $result['shared_drive'] = array('ok' => true, 'http_code' => 0, 'message' => 'CPMS_협업툴 shared drive ID is configured.');
    } else {
        $result['shared_drive']['message'] = 'Shared drive ID is not configured.';
    }

    foreach ($categories as $category => $meta) {
        $context = array('user' => $userContext, 'section' => 'admin_drive_check_company_overhead', 'document_type' => $meta['label'], 'document_year' => $year, 'document_month' => $month, 'original_name' => 'company_overhead_drive_check.txt');
        $target = cpms_company_overhead_ensure_drive_month_folder($category, $year, $month, $context);
        $result['category_folders'][$category] = array('label' => $meta['drive_label'], 'ok' => !empty($target['category_folder_id']), 'http_code' => isset($target['http_code']) ? (int)$target['http_code'] : 0, 'message' => !empty($target['category_folder_id']) ? $meta['drive_label'] . ' folder is ready.' : (isset($target['message']) ? (string)$target['message'] : 'Folder check failed.'));
        $result['year_folders'][$category] = array('label' => $meta['drive_label'], 'ok' => !empty($target['year_folder_id']), 'http_code' => isset($target['http_code']) ? (int)$target['http_code'] : 0, 'message' => !empty($target['year_folder_id']) ? $year . ' folder is ready.' : (isset($target['message']) ? (string)$target['message'] : 'Year folder check failed.'));
        $result['month_folders'][$category] = array('label' => $meta['drive_label'], 'ok' => !empty($target['month_folder_id']), 'http_code' => isset($target['http_code']) ? (int)$target['http_code'] : 0, 'message' => !empty($target['month_folder_id']) ? $month . ' folder is ready.' : (isset($target['message']) ? (string)$target['message'] : 'Month folder check failed.'));
        if (!empty($target['management_folder_id'])) {
            $result['management_folder'] = array('ok' => true, 'http_code' => isset($target['http_code']) ? (int)$target['http_code'] : 0, 'message' => '04_관리부 folder is ready.');
            $result['company_management_folder'] = $result['management_folder'];
        }
        if (!empty($target['overhead_folder_id'])) $result['overhead_folder'] = array('ok' => true, 'http_code' => isset($target['http_code']) ? (int)$target['http_code'] : 0, 'message' => '총관리비 folder is ready.');
    }

    $fuelContext = array('user' => $userContext, 'section' => 'admin_drive_check_company_overhead_fuel', 'document_type' => '주유비', 'document_year' => $year, 'document_month' => $month, 'original_name' => 'fuel_drive_check.xlsx');
    $uploadTarget = cpms_company_overhead_drive_ensure_month_subfolder('fuel', '주유비', $year, $month, '원본주유비엑셀', $fuelContext);
    $result['fuel_original_folder'] = array(
        'ok' => !empty($uploadTarget['ok']) && !empty($uploadTarget['sub_folder_id']),
        'http_code' => isset($uploadTarget['http_code']) ? (int)$uploadTarget['http_code'] : 0,
        'message' => !empty($uploadTarget['sub_folder_id']) ? '원본주유비엑셀 folder is ready.' : (isset($uploadTarget['message']) ? (string)$uploadTarget['message'] : 'Fuel original folder check failed.')
    );
    if (!empty($uploadTarget['ok'])) {
        $tmpDir = cpms_drive_storage_root() . '/tmp/company_overhead_drive_check';
        if (cpms_drive_ensure_dir($tmpDir)) {
            $tmpPath = @tempnam($tmpDir, 'oh_drive_');
            if ($tmpPath !== false && @file_put_contents($tmpPath, "CPMS fuel overhead Drive check\n" . date('Y-m-d H:i:s') . "\n") !== false) {
                $fileName = 'CPMS_Fuel_Overhead_Check_' . date('Ymd_His') . '.xlsx';
                $context2 = array('user' => $userContext, 'section' => 'admin_drive_check_company_overhead_fuel', 'document_type' => '주유비', 'document_year' => $year, 'document_month' => $month, 'original_name' => $fileName, 'target_folder_id' => (string)$uploadTarget['folder_id']);
                $upload = cpms_drive_upload_file($tmpPath, $fileName, (string)$uploadTarget['folder_id'], 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $context2);
                $result['upload'] = array('ok' => !empty($upload['ok']), 'http_code' => isset($upload['http_code']) ? (int)$upload['http_code'] : 0, 'message' => isset($upload['message']) ? (string)$upload['message'] : '');
                if (!empty($upload['ok']) && isset($upload['file']) && is_array($upload['file'])) {
                    $fileId = isset($upload['file']['id']) ? (string)$upload['file']['id'] : '';
                    $result['test_file'] = array('id' => $fileId, 'name' => isset($upload['file']['name']) ? (string)$upload['file']['name'] : '', 'webViewLink' => isset($upload['file']['webViewLink']) ? (string)$upload['file']['webViewLink'] : '');
                    $delete = cpms_drive_delete_file($fileId, $context2);
                    $result['delete'] = array('ok' => !empty($delete['ok']), 'http_code' => isset($delete['http_code']) ? (int)$delete['http_code'] : 0, 'message' => isset($delete['message']) ? (string)$delete['message'] : '');
                    $result['supports_all_drives_delete'] = array('ok' => (!empty($delete['ok']) && (int)$result['delete']['http_code'] === 204), 'http_code' => $result['delete']['http_code'], 'message' => ((int)$result['delete']['http_code'] === 204 ? 'Delete API returned HTTP 204 with supportsAllDrives=true.' : 'Delete API did not return HTTP 204.'));
                }
                @unlink($tmpPath);
            }
        }
    }
    return $result;
}}
