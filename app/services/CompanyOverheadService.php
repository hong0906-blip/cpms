<?php
/**
 * Company overhead service.
 * PHP 5.6 compatible.
 */

require_once __DIR__ . '/GoogleDriveHelper.php';
require_once __DIR__ . '/CompanyOverheadDriveService.php';
require_once __DIR__ . '/CompanyPayrollService.php';
require_once __DIR__ . '/CompanyVehicleService.php';
require_once __DIR__ . '/DataArchiveSummaryService.php';
require_once __DIR__ . '/DataArchiveAccessService.php';
require_once __DIR__ . '/../core/SimpleXlsxReader.php';
require_once __DIR__ . '/../core/SimpleXlsReader.php';

if (!function_exists('cpms_company_overhead_categories')) {
function cpms_company_overhead_categories() {
    return array(
        'payroll' => array('label' => '임직원 월급', 'path' => 'payroll', 'drive_label' => '임직원월급'),
        'vehicles' => array('label' => '회사차량', 'path' => 'vehicles', 'drive_label' => '회사차량'),
        'lease' => array('label' => '임대차', 'path' => 'lease', 'drive_label' => '임대차'),
        'corporate_cards' => array('label' => '법인카드', 'path' => 'corporate_cards', 'drive_label' => '법인카드'),
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
        'vehicles' => array(
            array('key' => 'vehicle_name', 'label' => '차량명/차량번호', 'type' => 'text'),
            array('key' => 'expense_type', 'label' => '비용구분', 'type' => 'text'),
            array('key' => 'driver_name', 'label' => '운전자/담당자', 'type' => 'text'),
            array('key' => 'mileage', 'label' => '주행거리', 'type' => 'text'),
        ),
        'lease' => array(
            array('key' => 'address', 'label' => '주소', 'type' => 'text'),
            array('key' => 'manager_primary', 'label' => '정', 'type' => 'text'),
            array('key' => 'manager_secondary', 'label' => '부', 'type' => 'text'),
            array('key' => 'deposit', 'label' => '보증금', 'type' => 'money'),
            array('key' => 'payment_due', 'label' => '지급일', 'type' => 'text'),
            array('key' => 'maintenance_fee', 'label' => '관리비', 'type' => 'money'),
            array('key' => 'contract_period', 'label' => '계약기간', 'type' => 'text'),
            array('key' => 'restoration_obligation', 'label' => '사무실 복구의무', 'type' => 'text'),
            array('key' => 'landlord', 'label' => '임대인', 'type' => 'text'),
            array('key' => 'auto_transfer_day', 'label' => '자동이체일', 'type' => 'text'),
        ),
        'corporate_cards' => array(
            array('key' => 'card_number', 'label' => '카드번호', 'type' => 'text'),
            array('key' => 'card_user', 'label' => '사용자', 'type' => 'text'),
            array('key' => 'card_alias', 'label' => '카드별칭', 'type' => 'text'),
            array('key' => 'used_time', 'label' => '사용시간', 'type' => 'text'),
            array('key' => 'content', 'label' => '내용', 'type' => 'text'),
            array('key' => 'vendor_business_number', 'label' => '사용처사업자번호', 'type' => 'text'),
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

if (!function_exists('cpms_company_overhead_writable_data_root')) {
function cpms_company_overhead_writable_data_root($category, $year) {
    $meta = cpms_company_overhead_category_meta($category);
    if (!is_array($meta)) return cpms_company_overhead_data_root();
    $year = sprintf('%04d', (int)$year);
    $roots = cpms_company_overhead_base_dirs();
    foreach ($roots as $root) {
        $root = rtrim((string)$root, '/\\');
        if ($root === '') continue;
        $target = $root . '/' . $meta['path'] . '/' . $year;
        if (is_dir($target) && is_writable($target)) return $root;
        if (!is_dir($target) && @mkdir($target, 0777, true) && is_dir($target) && is_writable($target)) return $root;
    }
    return count($roots) > 0 ? rtrim((string)$roots[0], '/\\') : cpms_company_overhead_data_root();
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

if (!function_exists('cpms_company_overhead_set_last_write_error')) {
function cpms_company_overhead_set_last_write_error($message) {
    $GLOBALS['_cpms_company_overhead_last_write_error'] = (string)$message;
}}

if (!function_exists('cpms_company_overhead_last_write_error')) {
function cpms_company_overhead_last_write_error() {
    return isset($GLOBALS['_cpms_company_overhead_last_write_error']) ? (string)$GLOBALS['_cpms_company_overhead_last_write_error'] : '';
}}

if (!function_exists('cpms_company_overhead_read_json')) {
function cpms_company_overhead_read_json($path) {
    if (!is_file($path)) return null;
    $txt = @file_get_contents($path);
    if ($txt === false || trim($txt) === '') return null;
    if (substr($txt, 0, 3) === "\xEF\xBB\xBF") $txt = substr($txt, 3);
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

    $value = str_replace(array(
        "\xE2\x88\x92",
        "\xEF\xBC\x8D",
        "\xE2\x80\x90",
        "\xE2\x80\x91",
        "\xE2\x80\x92",
        "\xE2\x80\x93",
        "\xE2\x80\x94"
    ), '-', $value);

    $negative = false;
    $trimmed = trim($value);
    if ($trimmed !== '' && substr($trimmed, 0, 1) === '(' && substr($trimmed, -1) === ')') $negative = true;
    if ($trimmed !== '' && (substr($trimmed, 0, 1) === '-' || substr($trimmed, -1) === '-')) $negative = true;
    if (strpos($value, "\xE2\x96\xB3") !== false || strpos($value, "\xE2\x96\xB2") !== false) $negative = true;

    $value = str_replace(',', '', $value);
    $value = preg_replace('/[^0-9.]/', '', $value);
    if ($value === '' || !is_numeric($value)) return 0.0;

    $amount = (float)$value;
    if ($negative && $amount > 0) return -1 * $amount;
    return $amount;
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

if (!function_exists('cpms_company_overhead_normalize_upload_year')) {
function cpms_company_overhead_normalize_upload_year($value) {
    $text = trim((string)$value);
    if ($text === '') return (int)date('Y');
    $year = (int)$text;
    if ($year > 0 && $year < 100) $year = 2000 + $year;
    return $year;
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

if (!function_exists('cpms_company_overhead_current_month')) {
function cpms_company_overhead_current_month() {
    return date('Y-m');
}}

if (!function_exists('cpms_company_overhead_filter_months_until_current')) {
function cpms_company_overhead_filter_months_until_current($months) {
    $result = array();
    if (!is_array($months)) return $result;
    $currentMonth = cpms_company_overhead_current_month();
    foreach ($months as $ym) {
        $ym = trim((string)$ym);
        if (!cpms_company_overhead_month_valid($ym)) continue;
        if (strcmp($ym, $currentMonth) > 0) continue;
        $result[] = $ym;
    }
    return $result;
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
    return cpms_company_overhead_month_file(cpms_company_overhead_writable_data_root($category, $year), $meta['path'], $ym);
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
    $companyVehicleItems = array();
    if ($category === 'vehicles' && function_exists('cpms_company_vehicle_month_items')) {
        $companyVehicleItems = cpms_company_vehicle_month_items($year, $month);
    }
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
    if ($includeDeleted) return array_merge($companyVehicleItems, $items);

    $active = array();
    foreach ($companyVehicleItems as $companyVehicleItem) {
        if (is_array($companyVehicleItem)) $active[] = $companyVehicleItem;
    }
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
    cpms_company_overhead_set_last_write_error('');
    $path = cpms_company_overhead_writable_month_file($category, $year, $month);
    if ($path === '') {
        cpms_company_overhead_set_last_write_error('저장 경로를 만들 수 없습니다.');
        return false;
    }
    $dir = dirname($path);
    if (function_exists('cpms_ensure_dir')) {
        if (!cpms_ensure_dir($dir)) {
            cpms_company_overhead_set_last_write_error('저장 폴더를 만들 수 없습니다: ' . $dir);
            return false;
        }
    } else if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
        cpms_company_overhead_set_last_write_error('저장 폴더를 만들 수 없습니다: ' . $dir);
        return false;
    }
    if (!is_writable($dir)) {
        cpms_company_overhead_set_last_write_error('저장 폴더에 쓰기 권한이 없습니다: ' . $dir);
        return false;
    }
    if (is_file($path) && !is_writable($path)) {
        cpms_company_overhead_set_last_write_error('저장 파일에 쓰기 권한이 없습니다: ' . $path);
        return false;
    }
    $json = cpms_company_overhead_json_encode(is_array($items) ? $items : array());
    if (!is_string($json)) {
        $jsonError = function_exists('json_last_error_msg') ? json_last_error_msg() : 'json_encode failed';
        cpms_company_overhead_set_last_write_error('JSON 변환에 실패했습니다: ' . $jsonError);
        return false;
    }
    $written = @file_put_contents($path, $json, LOCK_EX);
    if ($written === false) {
        $err = error_get_last();
        cpms_company_overhead_set_last_write_error('파일 쓰기에 실패했습니다: ' . $path . (is_array($err) && isset($err['message']) ? ' / ' . $err['message'] : ''));
        return false;
    }
    return true;
}}

if (!function_exists('cpms_company_overhead_sum_record')) {
function cpms_company_overhead_sum_record($record) {
    if (!is_array($record)) return cpms_company_overhead_numeric_value($record);
    if (isset($record['deleted_at']) && trim((string)$record['deleted_at']) !== '') return 0.0;

    if (isset($record['category']) && (string)$record['category'] === 'lease') {
        $rent = isset($record['amount']) ? cpms_company_overhead_numeric_value($record['amount']) : 0.0;
        $maintenanceFee = isset($record['maintenance_fee']) ? cpms_company_overhead_numeric_value($record['maintenance_fee']) : 0.0;
        return $rent + $maintenanceFee;
    }

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
    if (!is_array($months)) $months = array();
    $months = cpms_company_overhead_filter_months_until_current($months);
    $summary = array(
        'total' => 0.0,
        'has_data' => false,
        'months' => $months,
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
    if (!cpms_company_overhead_month_valid($endMonth)) {
        $endMonth = sprintf('%04d-12', $year);
        if ($year === (int)date('Y')) $endMonth = cpms_company_overhead_current_month();
    }
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
    $months = cpms_company_overhead_filter_months_until_current($months);
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

    $allKeys = array('department', 'position', 'base_salary', 'allowance', 'deduction', 'net_pay', 'is_recurring', 'vehicle_name', 'expense_type', 'driver_name', 'mileage', 'dormitory_name', 'address', 'occupants', 'card_name', 'card_number', 'card_last4', 'card_alias', 'card_user', 'used_time', 'content', 'vendor_business_number', 'proof', 'note', 'purpose', 'manager_primary', 'manager_secondary', 'deposit', 'payment_due', 'maintenance_fee', 'contract_period', 'restoration_obligation', 'landlord', 'auto_transfer_day', 'lease_group_id', 'source_contract_period');
    foreach ($allKeys as $key) {
        if (!isset($data[$key])) {
            if (!isset($row[$key])) $row[$key] = '';
            continue;
        }
        if ($key === 'maintenance_fee' && trim((string)$data[$key]) === '') {
            $row[$key] = '';
        } else if ($key === 'base_salary' || $key === 'allowance' || $key === 'deduction' || $key === 'net_pay' || $key === 'deposit' || $key === 'maintenance_fee') {
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
    if ($category === 'corporate_cards' && isset($row['card_number'])) $row['card_last4'] = cpms_company_overhead_card_last4($row['card_number']);
    if ($category === 'corporate_cards' && isset($row['card_user']) && trim((string)$row['card_user']) !== '') $row['employee_name'] = trim((string)$row['card_user']);
    if ($category === 'lease' && isset($row['landlord'])) $row['vendor'] = trim((string)$row['landlord']);

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

if (!function_exists('cpms_company_overhead_card_text')) {
function cpms_company_overhead_card_text($value) {
    $value = trim((string)$value);
    if ($value === '') return '';
    return preg_replace('/\s+/u', ' ', $value);
}}

if (!function_exists('cpms_company_overhead_card_header_token')) {
function cpms_company_overhead_card_header_token($value) {
    $value = cpms_company_overhead_card_text($value);
    return preg_replace('/[\s\(\)\[\]\/\\\\_]+/u', '', $value);
}}

if (!function_exists('cpms_company_overhead_card_cell')) {
function cpms_company_overhead_card_cell($row, $index) {
    if (!is_array($row)) return '';
    $index = (int)$index;
    return isset($row[$index]) ? cpms_company_overhead_card_text($row[$index]) : '';
}}

if (!function_exists('cpms_company_overhead_card_number_key')) {
function cpms_company_overhead_card_number_key($value) {
    $digits = preg_replace('/\D+/', '', (string)$value);
    return $digits === '' ? cpms_company_overhead_card_text($value) : $digits;
}}

if (!function_exists('cpms_company_overhead_card_display_number')) {
function cpms_company_overhead_card_display_number($value) {
    $value = trim((string)$value);
    if ($value !== '') return $value;
    return '-';
}}

if (!function_exists('cpms_company_overhead_card_detect_columns')) {
function cpms_company_overhead_card_detect_columns($rows) {
    $fallback = array(
        'used_date' => 0,
        'used_time' => 1,
        'vendor' => 2,
        'vendor_business_number' => 3,
        'card_number' => 4,
        'card_alias' => 5,
        'proof' => 6,
        'amount' => 7,
        'content' => 8,
        'card_user' => 9,
        'note' => 10,
        'memo' => 11,
    );
    $best = array('header_index' => 1, 'columns' => $fallback);
    if (!is_array($rows)) return $best;
    foreach ($rows as $idx => $row) {
        if (!is_array($row)) continue;
        $columns = array();
        foreach ($row as $col => $cell) {
            $token = cpms_company_overhead_card_header_token($cell);
            if ($token === '사용일자') $columns['used_date'] = $col;
            else if ($token === '사용시간') $columns['used_time'] = $col;
            else if ($token === '사용처') $columns['vendor'] = $col;
            else if ($token === '사용처사업자번호') $columns['vendor_business_number'] = $col;
            else if ($token === '카드번호') $columns['card_number'] = $col;
            else if ($token === '카드별칭') $columns['card_alias'] = $col;
            else if ($token === '증빙') $columns['proof'] = $col;
            else if ($token === '사용금액') $columns['amount'] = $col;
            else if ($token === '내용') $columns['content'] = $col;
            else if ($token === '사용자') $columns['card_user'] = $col;
            else if ($token === '비고') $columns['note'] = $col;
            else if ($token === '메모') $columns['memo'] = $col;
        }
        if (isset($columns['used_date']) && (isset($columns['card_number']) || isset($columns['card_alias'])) && isset($columns['amount']) && isset($columns['card_user'])) {
            foreach ($fallback as $key => $fallbackIndex) {
                if (($key === 'card_number' || $key === 'card_alias') && !isset($columns[$key])) continue;
                if (!isset($columns[$key])) $columns[$key] = $fallbackIndex;
            }
            return array('header_index' => (int)$idx, 'columns' => $columns);
        }
    }
    return $best;
}}

if (!function_exists('cpms_company_overhead_card_normalize_date')) {
function cpms_company_overhead_card_normalize_date($value) {
    $value = cpms_company_overhead_card_text($value);
    if ($value === '') return '';
    if (preg_match('/^(\d{4})[-\.\/](\d{1,2})[-\.\/](\d{1,2})$/', $value, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    }
    return cpms_company_overhead_normalize_date($value);
}}

if (!function_exists('cpms_company_overhead_card_parse_rows')) {
function cpms_company_overhead_card_parse_rows($rows, $year, $month, $user = null) {
    $parsed = array('ok' => false, 'items' => array(), 'source_count' => 0, 'skipped_count' => 0, 'errors' => array());
    $year = cpms_company_overhead_normalize_upload_year($year);
    $month = cpms_company_overhead_normalize_upload_month($month);
    if (!is_array($rows) || count($rows) === 0) {
        $parsed['message'] = '엑셀에서 읽을 행이 없습니다.';
        return $parsed;
    }
    $detected = cpms_company_overhead_card_detect_columns($rows);
    $headerIndex = isset($detected['header_index']) ? (int)$detected['header_index'] : 1;
    $cols = isset($detected['columns']) && is_array($detected['columns']) ? $detected['columns'] : array();
    $now = date('Y-m-d H:i:s');
    $userLabel = cpms_company_overhead_user_label($user);

    for ($i = $headerIndex + 1; $i < count($rows); $i++) {
        $row = $rows[$i];
        if (!is_array($row)) continue;
        $usedDate = cpms_company_overhead_card_normalize_date(cpms_company_overhead_card_cell($row, isset($cols['used_date']) ? $cols['used_date'] : 0));
        $usedTime = cpms_company_overhead_card_cell($row, isset($cols['used_time']) ? $cols['used_time'] : 1);
        $vendor = cpms_company_overhead_card_cell($row, isset($cols['vendor']) ? $cols['vendor'] : 2);
        $cardNumber = isset($cols['card_number']) ? cpms_company_overhead_card_cell($row, $cols['card_number']) : '';
        $cardAlias = isset($cols['card_alias']) ? cpms_company_overhead_card_cell($row, $cols['card_alias']) : '';
        $cardUser = cpms_company_overhead_card_cell($row, isset($cols['card_user']) ? $cols['card_user'] : 9);
        $hasCardIdentity = ($cardNumber !== '' || ($cardAlias !== '' && $cardUser !== ''));
        $amount = cpms_company_overhead_numeric_value(cpms_company_overhead_card_cell($row, isset($cols['amount']) ? $cols['amount'] : 7));
        $amountEmpty = (abs($amount) < 0.000001);
        if ($usedDate === '' && $vendor === '' && $cardNumber === '' && $amountEmpty) continue;
        $parsed['source_count']++;
        if ($usedDate === '' || $vendor === '' || !$hasCardIdentity || $amountEmpty) {
            $parsed['skipped_count']++;
            $parsed['errors'][] = '행 ' . ($i + 1) . ': 필수값(사용일자/사용처/카드번호 또는 카드별칭+사용자/사용금액)이 부족합니다.';
            continue;
        }
        $content = cpms_company_overhead_card_cell($row, isset($cols['content']) ? $cols['content'] : 8);
        $memo = cpms_company_overhead_card_cell($row, isset($cols['memo']) ? $cols['memo'] : 11);
        $note = cpms_company_overhead_card_cell($row, isset($cols['note']) ? $cols['note'] : 10);
        $record = array(
            'id' => cpms_company_overhead_new_id(),
            'category' => 'corporate_cards',
            'category_name' => '법인카드',
            'year' => sprintf('%04d', $year),
            'month' => sprintf('%02d', $month),
            'title' => $vendor,
            'amount' => $amount,
            'occurred_at' => $usedDate,
            'paid_at' => $usedDate,
            'used_time' => $usedTime,
            'vendor' => $vendor,
            'vendor_business_number' => cpms_company_overhead_card_cell($row, isset($cols['vendor_business_number']) ? $cols['vendor_business_number'] : 3),
            'card_number' => $cardNumber,
            'card_number_key' => cpms_company_overhead_card_number_key($cardNumber),
            'card_last4' => cpms_company_overhead_card_last4($cardNumber),
            'card_alias' => $cardAlias,
            'proof' => cpms_company_overhead_card_cell($row, isset($cols['proof']) ? $cols['proof'] : 6),
            'content' => $content,
            'purpose' => $content,
            'card_user' => $cardUser,
            'employee_name' => $cardUser,
            'note' => $note,
            'memo' => trim($content . ($note !== '' ? ' / ' . $note : '') . ($memo !== '' ? ' / ' . $memo : '')),
            'payment_method' => '법인카드',
            'created_by' => $userLabel,
            'created_at' => $now,
            'updated_by' => $userLabel,
            'updated_at' => $now,
            'deleted_at' => '',
            'deleted_by' => '',
            'source_row' => $i + 1,
        );
        $parsed['items'][] = $record;
    }
    if (count($parsed['items']) === 0) {
        $parsed['message'] = '저장 가능한 법인카드 사용내역을 찾지 못했습니다.';
        return $parsed;
    }
    $parsed['ok'] = true;
    $parsed['message'] = '법인카드 엑셀을 읽었습니다.';
    return $parsed;
}}

if (!function_exists('cpms_company_overhead_read_card_excel')) {
function cpms_company_overhead_read_card_excel($path, $ext) {
    $ext = strtolower(trim((string)$ext));
    if ($ext === 'xls') return \App\Core\SimpleXlsReader::readFirstSheet($path, 5000);
    if ($ext === 'xlsx') return \App\Core\SimpleXlsxReader::readFirstSheet($path, 5000);
    return array('rows' => array(), 'error' => '.xls 또는 .xlsx 파일만 업로드할 수 있습니다.');
}}

if (!function_exists('cpms_company_overhead_card_preview_root')) {
function cpms_company_overhead_card_preview_root() {
    $root = function_exists('cpms_storage_root') ? cpms_storage_root() : dirname(dirname(__DIR__)) . '/storage';
    return $root . '/tmp/company_overhead_card_preview';
}}

if (!function_exists('cpms_company_overhead_card_ensure_dir')) {
function cpms_company_overhead_card_ensure_dir($dir) {
    if (function_exists('cpms_ensure_dir')) return cpms_ensure_dir($dir);
    if (is_dir($dir)) return true;
    return @mkdir($dir, 0777, true);
}}

if (!function_exists('cpms_company_overhead_card_new_token')) {
function cpms_company_overhead_card_new_token() {
    if (function_exists('openssl_random_pseudo_bytes')) {
        $bytes = openssl_random_pseudo_bytes(16);
        if ($bytes !== false) return bin2hex($bytes);
    }
    return md5(uniqid('', true) . mt_rand());
}}

if (!function_exists('cpms_company_overhead_validate_card_upload_file')) {
function cpms_company_overhead_validate_card_upload_file($file) {
    if (!is_array($file) || !isset($file['error']) || (int)$file['error'] === UPLOAD_ERR_NO_FILE) return array('ok' => false, 'message' => '업로드할 엑셀 파일을 선택해주세요.');
    if ((int)$file['error'] !== UPLOAD_ERR_OK) return array('ok' => false, 'message' => '엑셀 파일 업로드에 실패했습니다. 오류코드: ' . (int)$file['error']);
    $tmpPath = isset($file['tmp_name']) ? trim((string)$file['tmp_name']) : '';
    $originalName = isset($file['name']) ? trim((string)$file['name']) : '';
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($tmpPath === '' || !is_file($tmpPath)) return array('ok' => false, 'message' => '업로드 파일을 찾을 수 없습니다.');
    if ($ext !== 'xls' && $ext !== 'xlsx') return array('ok' => false, 'message' => '.xls 또는 .xlsx 파일만 업로드할 수 있습니다.');
    return array('ok' => true, 'tmp_path' => $tmpPath, 'original_name' => $originalName, 'ext' => $ext);
}}

if (!function_exists('cpms_company_overhead_card_user_name')) {
function cpms_company_overhead_card_user_name($item) {
    if (!is_array($item)) $item = array();
    $userName = isset($item['card_user']) ? trim((string)$item['card_user']) : '';
    if ($userName === '' && isset($item['employee_name'])) $userName = trim((string)$item['employee_name']);
    return $userName !== '' ? $userName : '-';
}}

if (!function_exists('cpms_company_overhead_card_text_key')) {
function cpms_company_overhead_card_text_key($value) {
    $value = trim((string)$value);
    if ($value === '') return '';
    if (function_exists('mb_strtolower')) $value = mb_strtolower($value, 'UTF-8');
    else $value = strtolower($value);
    return preg_replace('/\s+/u', ' ', $value);
}}

if (!function_exists('cpms_company_overhead_card_user_key')) {
function cpms_company_overhead_card_user_key($item) {
    $userName = cpms_company_overhead_card_user_name($item);
    $key = cpms_company_overhead_card_text_key($userName);
    return $key !== '' ? 'user:' . $key : 'user:unknown';
}}

if (!function_exists('cpms_company_overhead_card_bucket_key')) {
function cpms_company_overhead_card_bucket_key($item, $userName) {
    if (!is_array($item)) $item = array();
    $cardNumber = isset($item['card_number']) ? (string)$item['card_number'] : (isset($item['card_last4']) ? (string)$item['card_last4'] : '');
    $numberKey = isset($item['card_number_key']) && trim((string)$item['card_number_key']) !== '' ? trim((string)$item['card_number_key']) : cpms_company_overhead_card_number_key($cardNumber);
    if ($numberKey !== '') return 'number:' . $numberKey;
    $alias = isset($item['card_alias']) ? trim((string)$item['card_alias']) : '';
    if ($alias !== '') return 'alias:' . cpms_company_overhead_card_text_key($userName) . ':' . cpms_company_overhead_card_text_key($alias);
    return 'unknown:' . cpms_company_overhead_card_text_key($userName);
}}

if (!function_exists('cpms_company_overhead_card_bucket_sort')) {
function cpms_company_overhead_card_bucket_sort($a, $b) {
    $at = isset($a['total']) ? (float)$a['total'] : 0.0;
    $bt = isset($b['total']) ? (float)$b['total'] : 0.0;
    if ($at == $bt) {
        $al = isset($a['label']) ? (string)$a['label'] : '';
        $bl = isset($b['label']) ? (string)$b['label'] : '';
        if ($al === $bl) return 0;
        return ($al < $bl) ? -1 : 1;
    }
    return ($at < $bt) ? 1 : -1;
}}

if (!function_exists('cpms_company_overhead_group_card_items')) {
function cpms_company_overhead_group_card_items($items) {
    $groups = array();
    if (!is_array($items)) $items = array();
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        if (isset($item['deleted_at']) && trim((string)$item['deleted_at']) !== '') continue;
        $cardNumber = isset($item['card_number']) ? (string)$item['card_number'] : (isset($item['card_last4']) ? (string)$item['card_last4'] : '');
        $cardAlias = isset($item['card_alias']) ? trim((string)$item['card_alias']) : '';
        $userName = cpms_company_overhead_card_user_name($item);
        $key = cpms_company_overhead_card_user_key($item);
        $bucketKey = cpms_company_overhead_card_bucket_key($item, $userName);
        if (!isset($groups[$key])) {
            $groups[$key] = array(
                'key' => $key,
                'card_number' => '',
                'card_alias' => '',
                'user_name' => $userName,
                'total' => 0.0,
                'count' => 0,
                'items' => array(),
                'card_groups' => array(),
            );
        }
        if ($groups[$key]['user_name'] === '-' && $userName !== '-') $groups[$key]['user_name'] = $userName;
        if (!isset($groups[$key]['card_groups'][$bucketKey])) {
            $displayNumber = cpms_company_overhead_card_display_number($cardNumber);
            $bucketLabel = $displayNumber !== '-' ? $displayNumber : ($cardAlias !== '' ? $cardAlias : '-');
            $groups[$key]['card_groups'][$bucketKey] = array(
                'key' => $bucketKey,
                'label' => $bucketLabel,
                'card_number' => $cardNumber,
                'card_alias' => $cardAlias,
                'total' => 0.0,
                'count' => 0,
                'items' => array(),
            );
        }
        $amount = isset($item['amount']) ? cpms_company_overhead_numeric_value($item['amount']) : 0.0;
        $groups[$key]['total'] += $amount;
        $groups[$key]['count']++;
        $groups[$key]['items'][] = $item;
        $groups[$key]['card_groups'][$bucketKey]['total'] += $amount;
        $groups[$key]['card_groups'][$bucketKey]['count']++;
        $groups[$key]['card_groups'][$bucketKey]['items'][] = $item;
        if ($groups[$key]['card_groups'][$bucketKey]['card_number'] === '' && $cardNumber !== '') $groups[$key]['card_groups'][$bucketKey]['card_number'] = $cardNumber;
        if ($groups[$key]['card_groups'][$bucketKey]['card_alias'] === '' && $cardAlias !== '') $groups[$key]['card_groups'][$bucketKey]['card_alias'] = $cardAlias;
    }
    foreach ($groups as $key => $group) {
        if (isset($group['card_groups']) && is_array($group['card_groups'])) {
            uasort($group['card_groups'], 'cpms_company_overhead_card_bucket_sort');
            $groups[$key]['card_groups'] = $group['card_groups'];
        }
    }
    uasort($groups, 'cpms_company_overhead_card_group_sort');
    return $groups;
}}

if (!function_exists('cpms_company_overhead_card_group_sort')) {
function cpms_company_overhead_card_group_sort($a, $b) {
    $at = isset($a['total']) ? (float)$a['total'] : 0.0;
    $bt = isset($b['total']) ? (float)$b['total'] : 0.0;
    if ($at == $bt) {
        $an = isset($a['user_name']) ? (string)$a['user_name'] : '';
        $bn = isset($b['user_name']) ? (string)$b['user_name'] : '';
        if ($an === $bn) return 0;
        return ($an < $bn) ? -1 : 1;
    }
    return ($at < $bt) ? 1 : -1;
}}

if (!function_exists('cpms_company_overhead_create_card_preview')) {
function cpms_company_overhead_create_card_preview($year, $month, $file, $user = null) {
    $year = cpms_company_overhead_normalize_upload_year($year);
    $month = cpms_company_overhead_normalize_upload_month($month);
    if ($year < 2000 || $year > 2100) return array('ok' => false, 'message' => '적용연도가 올바르지 않습니다.');
    $valid = cpms_company_overhead_validate_card_upload_file($file);
    if (empty($valid['ok'])) return $valid;
    $read = cpms_company_overhead_read_card_excel($valid['tmp_path'], $valid['ext']);
    if (!empty($read['error'])) return array('ok' => false, 'message' => (string)$read['error']);
    $parsed = cpms_company_overhead_card_parse_rows(isset($read['rows']) ? $read['rows'] : array(), $year, $month, $user);
    if (empty($parsed['ok'])) return $parsed;

    $token = cpms_company_overhead_card_new_token();
    $tmpDir = cpms_company_overhead_card_preview_root();
    if (!cpms_company_overhead_card_ensure_dir($tmpDir)) return array('ok' => false, 'message' => '법인카드 업로드 임시 폴더를 만들 수 없습니다.');
    $localPath = rtrim($tmpDir, '/\\') . '/' . $token . '.' . $valid['ext'];
    $moved = false;
    if (function_exists('move_uploaded_file')) $moved = @move_uploaded_file($valid['tmp_path'], $localPath);
    if (!$moved) $moved = @copy($valid['tmp_path'], $localPath);
    if (!$moved) return array('ok' => false, 'message' => '업로드 파일을 임시 보관하지 못했습니다.');

    if (!isset($_SESSION['_company_overhead_card_preview']) || !is_array($_SESSION['_company_overhead_card_preview'])) $_SESSION['_company_overhead_card_preview'] = array();
    $_SESSION['_company_overhead_card_preview'][$token] = array(
        'token' => $token,
        'created_at' => time(),
        'year' => sprintf('%04d', $year),
        'month' => sprintf('%02d', $month),
        'uploaded_original_name' => $valid['original_name'],
        'temp_path' => $localPath,
        'parsed' => $parsed,
        'groups' => cpms_company_overhead_group_card_items($parsed['items']),
        'uploaded_by' => cpms_company_overhead_user_label($user),
    );
    return array('ok' => true, 'message' => '법인카드 업로드 미리보기가 생성되었습니다.', 'token' => $token, 'year' => sprintf('%04d', $year), 'month' => sprintf('%02d', $month));
}}

if (!function_exists('cpms_company_overhead_get_card_preview')) {
function cpms_company_overhead_get_card_preview($token) {
    $token = trim((string)$token);
    if ($token === '' || !isset($_SESSION['_company_overhead_card_preview'][$token]) || !is_array($_SESSION['_company_overhead_card_preview'][$token])) return null;
    $preview = $_SESSION['_company_overhead_card_preview'][$token];
    if (!isset($preview['created_at']) || (time() - (int)$preview['created_at']) > 7200) {
        if (isset($preview['temp_path']) && is_file($preview['temp_path'])) @unlink($preview['temp_path']);
        unset($_SESSION['_company_overhead_card_preview'][$token]);
        return null;
    }
    return $preview;
}}

if (!function_exists('cpms_company_overhead_clear_card_preview')) {
function cpms_company_overhead_clear_card_preview($token) {
    $preview = cpms_company_overhead_get_card_preview($token);
    if (is_array($preview) && isset($preview['temp_path']) && is_file($preview['temp_path'])) @unlink($preview['temp_path']);
    if (isset($_SESSION['_company_overhead_card_preview'][$token])) unset($_SESSION['_company_overhead_card_preview'][$token]);
}}

if (!function_exists('cpms_company_overhead_confirm_card_preview')) {
function cpms_company_overhead_confirm_card_preview($token, $user = null) {
    $preview = cpms_company_overhead_get_card_preview($token);
    if (!is_array($preview)) return array('ok' => false, 'message' => '확정할 법인카드 미리보기를 찾을 수 없습니다.');
    $year = isset($preview['year']) ? (string)$preview['year'] : date('Y');
    $month = isset($preview['month']) ? (string)$preview['month'] : date('m');
    $parsed = isset($preview['parsed']) && is_array($preview['parsed']) ? $preview['parsed'] : array();
    $items = isset($parsed['items']) && is_array($parsed['items']) ? $parsed['items'] : array();
    if (count($items) === 0) return array('ok' => false, 'message' => '저장할 법인카드 사용내역이 없습니다.');
    $userLabel = cpms_company_overhead_user_label($user);
    $now = date('Y-m-d H:i:s');
    foreach ($items as $idx => $item) {
        if (!is_array($item)) continue;
        $items[$idx]['updated_by'] = $userLabel;
        $items[$idx]['updated_at'] = $now;
    }
    if (!cpms_company_overhead_save_month('corporate_cards', $year, $month, $items)) {
        $writeError = cpms_company_overhead_last_write_error();
        return array('ok' => false, 'message' => '법인카드 월별 데이터를 저장하지 못했습니다.' . ($writeError !== '' ? ' 원인: ' . $writeError : ''));
    }
    cpms_company_overhead_clear_card_preview($token);
    return array(
        'ok' => true,
        'message' => '법인카드 엑셀 업로드가 반영되었습니다.',
        'year' => $year,
        'month' => $month,
        'source_count' => isset($parsed['source_count']) ? (int)$parsed['source_count'] : 0,
        'skipped_count' => isset($parsed['skipped_count']) ? (int)$parsed['skipped_count'] : 0,
        'inserted' => count($items),
        'groups' => cpms_company_overhead_group_card_items($items),
    );
}}

if (!function_exists('cpms_company_overhead_lease_upload_text')) {
function cpms_company_overhead_lease_upload_text($value) {
    $value = trim((string)$value);
    if ($value === '') return '';
    return preg_replace('/\s+/u', ' ', $value);
}}

if (!function_exists('cpms_company_overhead_lease_header_token')) {
function cpms_company_overhead_lease_header_token($value) {
    $value = cpms_company_overhead_lease_upload_text($value);
    $value = preg_replace('/[\s\(\)\[\]\/\\\\]+/u', '', $value);
    return $value;
}}

if (!function_exists('cpms_company_overhead_lease_cell')) {
function cpms_company_overhead_lease_cell($row, $index) {
    if (!is_array($row)) return '';
    $index = (int)$index;
    return isset($row[$index]) ? cpms_company_overhead_lease_upload_text($row[$index]) : '';
}}

if (!function_exists('cpms_company_overhead_lease_detect_columns')) {
function cpms_company_overhead_lease_detect_columns($rows) {
    $fallback = array(
        'title' => 1,
        'address' => 2,
        'manager_primary' => 3,
        'manager_secondary' => 4,
        'deposit' => 5,
        'payment_due' => 6,
        'amount' => 7,
        'maintenance_fee' => 8,
        'source_contract_period' => 9,
        'restoration_obligation' => 10,
        'landlord' => 11,
        'auto_transfer_day' => 12,
        'payment_method' => 13,
        'employee_name' => 14,
    );
    $best = array('header_index' => 0, 'columns' => $fallback);
    if (!is_array($rows)) return $best;

    foreach ($rows as $idx => $row) {
        if (!is_array($row)) continue;
        $columns = array();
        foreach ($row as $col => $cell) {
            $token = cpms_company_overhead_lease_header_token($cell);
            if ($token === '구분') $columns['title'] = $col;
            else if ($token === '주소') $columns['address'] = $col;
            else if ($token === '정') $columns['manager_primary'] = $col;
            else if ($token === '부') $columns['manager_secondary'] = $col;
            else if ($token === '보증금') $columns['deposit'] = $col;
            else if ($token === '지급일') $columns['payment_due'] = $col;
            else if (strpos($token, '월세') !== false) $columns['amount'] = $col;
            else if ($token === '관리비') $columns['maintenance_fee'] = $col;
            else if ($token === '계약기간') $columns['source_contract_period'] = $col;
            else if (strpos($token, '복구의무') !== false) $columns['restoration_obligation'] = $col;
            else if ($token === '임대인') $columns['landlord'] = $col;
            else if (strpos($token, '자동이체일') !== false) $columns['auto_transfer_day'] = $col;
            else if ($token === '입금방법') $columns['payment_method'] = $col;
            else if ($token === '사용직원') $columns['employee_name'] = $col;
        }
        if (isset($columns['title']) && isset($columns['address']) && isset($columns['amount'])) {
            foreach ($fallback as $key => $fallbackIndex) {
                if (!isset($columns[$key])) $columns[$key] = $fallbackIndex;
            }
            return array('header_index' => (int)$idx, 'columns' => $columns);
        }
    }
    return $best;
}}

if (!function_exists('cpms_company_overhead_lease_full_year')) {
function cpms_company_overhead_lease_full_year($yearText) {
    $year = (int)$yearText;
    if ($year > 0 && $year < 100) return 2000 + $year;
    return $year;
}}

if (!function_exists('cpms_company_overhead_lease_period_dates')) {
function cpms_company_overhead_lease_period_dates($value) {
    $result = array('start' => null, 'end' => null);
    $value = trim((string)$value);
    if ($value === '') return $result;
    if (!preg_match_all('/(\d{2,4})[.\-\/](\d{1,2})[.\-\/](\d{1,2})/u', $value, $matches, PREG_SET_ORDER)) return $result;
    $dates = array();
    foreach ($matches as $m) {
        $year = cpms_company_overhead_lease_full_year($m[1]);
        $month = (int)$m[2];
        $day = (int)$m[3];
        if ($year > 0 && $month >= 1 && $month <= 12 && $day >= 1 && $day <= 31) {
            $dates[] = array('year' => $year, 'month' => $month, 'day' => $day);
        }
    }
    if (isset($dates[0])) $result['start'] = $dates[0];
    if (isset($dates[1])) $result['end'] = $dates[1];
    return $result;
}}

if (!function_exists('cpms_company_overhead_lease_active_months')) {
function cpms_company_overhead_lease_active_months($contractPeriod, $year) {
    $year = (int)$year;
    if ($year < 2000 || $year > 2100) return array();
    $dates = cpms_company_overhead_lease_period_dates($contractPeriod);
    $startMonth = 1;
    $endMonth = 12;

    if (is_array($dates['start'])) {
        if ((int)$dates['start']['year'] > $year) return array();
        if ((int)$dates['start']['year'] === $year) $startMonth = max(1, (int)$dates['start']['month']);
    }
    if (is_array($dates['end'])) {
        if ((int)$dates['end']['year'] < $year) return array();
        if ((int)$dates['end']['year'] === $year) $endMonth = min(12, (int)$dates['end']['month']);
    }
    if ($endMonth < $startMonth) return array();

    $months = array();
    for ($m = $startMonth; $m <= $endMonth; $m++) $months[] = $m;
    return $months;
}}

if (!function_exists('cpms_company_overhead_lease_import_key')) {
function cpms_company_overhead_lease_import_key($row) {
    if (!is_array($row)) $row = array();
    if (isset($row['lease_import_key']) && trim((string)$row['lease_import_key']) !== '') return trim((string)$row['lease_import_key']);
    $parts = array(
        isset($row['title']) ? $row['title'] : '',
        isset($row['address']) ? $row['address'] : '',
        isset($row['landlord']) ? $row['landlord'] : (isset($row['vendor']) ? $row['vendor'] : ''),
        isset($row['payment_due']) ? $row['payment_due'] : '',
        isset($row['auto_transfer_day']) ? $row['auto_transfer_day'] : '',
        isset($row['amount']) ? (string)cpms_company_overhead_numeric_value($row['amount']) : '0',
    );
    $normalized = array();
    foreach ($parts as $part) {
        $normalized[] = cpms_company_overhead_lease_upload_text($part);
    }
    return 'LEASE-IMPORT-' . md5(implode('|', $normalized));
}}

if (!function_exists('cpms_company_overhead_lease_parse_xlsx_rows')) {
function cpms_company_overhead_lease_parse_xlsx_rows($rows) {
    $parsed = array('rows' => array(), 'source_count' => 0, 'skipped_count' => 0);
    if (!is_array($rows) || count($rows) === 0) return $parsed;
    $detected = cpms_company_overhead_lease_detect_columns($rows);
    $headerIndex = isset($detected['header_index']) ? (int)$detected['header_index'] : 0;
    $cols = isset($detected['columns']) && is_array($detected['columns']) ? $detected['columns'] : array();

    for ($i = $headerIndex + 1; $i < count($rows); $i++) {
        $row = $rows[$i];
        if (!is_array($row)) continue;
        $title = cpms_company_overhead_lease_cell($row, isset($cols['title']) ? $cols['title'] : 1);
        $address = cpms_company_overhead_lease_cell($row, isset($cols['address']) ? $cols['address'] : 2);
        $amount = cpms_company_overhead_money_value(cpms_company_overhead_lease_cell($row, isset($cols['amount']) ? $cols['amount'] : 7));
        if ($title === '' && $address === '') continue;
        $parsed['source_count']++;
        if ($title === '' || $address === '' || $amount <= 0) {
            $parsed['skipped_count']++;
            continue;
        }

        $landlord = cpms_company_overhead_lease_cell($row, isset($cols['landlord']) ? $cols['landlord'] : 11);
        $record = array(
            'category' => 'lease',
            'category_name' => '임대차',
            'title' => $title,
            'address' => $address,
            'manager_primary' => cpms_company_overhead_lease_cell($row, isset($cols['manager_primary']) ? $cols['manager_primary'] : 3),
            'manager_secondary' => cpms_company_overhead_lease_cell($row, isset($cols['manager_secondary']) ? $cols['manager_secondary'] : 4),
            'deposit' => cpms_company_overhead_money_value(cpms_company_overhead_lease_cell($row, isset($cols['deposit']) ? $cols['deposit'] : 5)),
            'payment_due' => cpms_company_overhead_lease_cell($row, isset($cols['payment_due']) ? $cols['payment_due'] : 6),
            'amount' => $amount,
            'maintenance_fee' => '',
            'contract_period' => '',
            'source_contract_period' => cpms_company_overhead_lease_cell($row, isset($cols['source_contract_period']) ? $cols['source_contract_period'] : 9),
            'restoration_obligation' => cpms_company_overhead_lease_cell($row, isset($cols['restoration_obligation']) ? $cols['restoration_obligation'] : 10),
            'landlord' => $landlord,
            'vendor' => $landlord,
            'auto_transfer_day' => cpms_company_overhead_lease_cell($row, isset($cols['auto_transfer_day']) ? $cols['auto_transfer_day'] : 12),
            'payment_method' => cpms_company_overhead_lease_cell($row, isset($cols['payment_method']) ? $cols['payment_method'] : 13),
            'employee_name' => cpms_company_overhead_lease_cell($row, isset($cols['employee_name']) ? $cols['employee_name'] : 14),
            'memo' => '',
            'occurred_at' => '',
            'paid_at' => '',
            'source_row' => $i + 1,
        );
        $record['lease_import_key'] = cpms_company_overhead_lease_import_key($record);
        $record['lease_group_id'] = $record['lease_import_key'];
        $parsed['rows'][] = $record;
    }
    return $parsed;
}}

if (!function_exists('cpms_company_overhead_lease_find_existing_index')) {
function cpms_company_overhead_lease_find_existing_index($items, $importKey, $usedIndexes) {
    if (!is_array($items)) return -1;
    $importKey = trim((string)$importKey);
    foreach ($items as $idx => $item) {
        if (isset($usedIndexes[$idx])) continue;
        if (!is_array($item)) continue;
        if (isset($item['deleted_at']) && trim((string)$item['deleted_at']) !== '') continue;
        $existingKey = cpms_company_overhead_lease_import_key($item);
        if ($existingKey === $importKey) return (int)$idx;
    }
    return -1;
}}

if (!function_exists('cpms_company_overhead_normalize_upload_month')) {
function cpms_company_overhead_normalize_upload_month($value) {
    $month = (int)$value;
    if ($month < 1 || $month > 12) $month = (int)date('m');
    return $month;
}}

if (!function_exists('cpms_company_overhead_lease_tmp_root')) {
function cpms_company_overhead_lease_tmp_root() {
    $root = function_exists('cpms_storage_root') ? cpms_storage_root() : dirname(dirname(__DIR__)) . '/storage';
    return $root . '/tmp/company_overhead_lease_preview';
}}

if (!function_exists('cpms_company_overhead_lease_new_token')) {
function cpms_company_overhead_lease_new_token() {
    if (function_exists('openssl_random_pseudo_bytes')) {
        $bytes = openssl_random_pseudo_bytes(16);
        if ($bytes !== false) return bin2hex($bytes);
    }
    return md5(uniqid('', true) . mt_rand());
}}

if (!function_exists('cpms_company_overhead_lease_ensure_dir')) {
function cpms_company_overhead_lease_ensure_dir($dir) {
    if (function_exists('cpms_ensure_dir')) return cpms_ensure_dir($dir);
    if (is_dir($dir)) return true;
    return @mkdir($dir, 0777, true);
}}

if (!function_exists('cpms_company_overhead_lease_parse_xlsx_file')) {
function cpms_company_overhead_lease_parse_xlsx_file($tmpPath) {
    $tmpPath = trim((string)$tmpPath);
    if ($tmpPath === '' || !is_file($tmpPath)) return array('ok' => false, 'message' => '업로드 파일을 찾을 수 없습니다.');
    $read = \App\Core\SimpleXlsxReader::readFirstSheet($tmpPath, 3000);
    if (!empty($read['error'])) return array('ok' => false, 'message' => (string)$read['error']);
    $parsed = cpms_company_overhead_lease_parse_xlsx_rows(isset($read['rows']) ? $read['rows'] : array());
    $rows = isset($parsed['rows']) && is_array($parsed['rows']) ? $parsed['rows'] : array();
    if (count($rows) === 0) return array('ok' => false, 'message' => '임대차 양식에서 가져올 행을 찾지 못했습니다.');
    $parsed['ok'] = true;
    return $parsed;
}}

if (!function_exists('cpms_company_overhead_lease_build_month_rows')) {
function cpms_company_overhead_lease_build_month_rows($rows, $year, $startMonth) {
    $year = cpms_company_overhead_normalize_upload_year($year);
    $startMonth = cpms_company_overhead_normalize_upload_month($startMonth);
    $monthRows = array();
    $activeSourceKeys = array();
    if (!is_array($rows)) $rows = array();

    foreach ($rows as $base) {
        if (!is_array($base)) continue;
        $months = cpms_company_overhead_lease_active_months(isset($base['source_contract_period']) ? $base['source_contract_period'] : '', $year);
        if (count($months) === 0) continue;
        $usedInYear = false;
        foreach ($months as $month) {
            $monthInt = (int)$month;
            if ($monthInt < $startMonth) continue;
            $monthText = sprintf('%02d', $monthInt);
            if (!isset($monthRows[$monthText])) $monthRows[$monthText] = array();
            $row = $base;
            $row['year'] = sprintf('%04d', $year);
            $row['month'] = $monthText;
            $monthRows[$monthText][] = $row;
            $usedInYear = true;
        }
        if ($usedInYear && isset($base['lease_import_key'])) $activeSourceKeys[$base['lease_import_key']] = true;
    }
    ksort($monthRows);
    return array(
        'month_rows' => $monthRows,
        'active_source_keys' => $activeSourceKeys,
        'active_count' => count($activeSourceKeys),
        'months' => array_keys($monthRows),
    );
}}

if (!function_exists('cpms_company_overhead_lease_apply_month_rows')) {
function cpms_company_overhead_lease_apply_month_rows($year, $monthRows, $parsed, $originalName, $user = null, $activeCount = 0) {
    $year = cpms_company_overhead_normalize_upload_year($year);
    if ($year < 2000 || $year > 2100) return array('ok' => false, 'message' => '적용연도가 올바르지 않습니다.');
    if (!is_array($monthRows) || count($monthRows) === 0) return array('ok' => false, 'message' => '적용 시작월 이후 유효한 임대차 계약이 없습니다.', 'year' => $year);
    $now = date('Y-m-d H:i:s');
    $userLabel = cpms_company_overhead_user_label($user);
    $inserted = 0;
    $updated = 0;
    $monthsTouched = array();
    foreach ($monthRows as $monthText => $records) {
        $items = cpms_company_overhead_load_month('lease', $year, $monthText, true);
        $usedIndexes = array();
        foreach ($records as $record) {
            $idx = cpms_company_overhead_lease_find_existing_index($items, $record['lease_import_key'], $usedIndexes);
            if ($idx >= 0 && isset($items[$idx]) && is_array($items[$idx])) {
                $existing = $items[$idx];
                $record['id'] = isset($existing['id']) && trim((string)$existing['id']) !== '' ? (string)$existing['id'] : cpms_company_overhead_new_id();
                $record['created_by'] = isset($existing['created_by']) ? (string)$existing['created_by'] : $userLabel;
                $record['created_at'] = isset($existing['created_at']) ? (string)$existing['created_at'] : $now;
                $record['maintenance_fee'] = isset($existing['maintenance_fee']) ? $existing['maintenance_fee'] : '';
                $record['contract_period'] = isset($existing['contract_period']) ? (string)$existing['contract_period'] : '';
                $record['memo'] = isset($existing['memo']) ? (string)$existing['memo'] : '';
                foreach (array('storage_type', 'drive_file_id', 'drive_folder_id', 'drive_web_view_link', 'drive_web_content_link', 'original_name', 'stored_name', 'mime_type', 'size', 'drive_year_folder_id', 'drive_type_folder_id', 'drive_month_folder_id', 'upload_status', 'drive_upload_error') as $fileKey) {
                    $record[$fileKey] = isset($existing[$fileKey]) ? $existing[$fileKey] : ($fileKey === 'size' ? 0 : '');
                }
                $items[$idx] = $record;
                $usedIndexes[$idx] = true;
                $updated++;
            } else {
                $record['id'] = cpms_company_overhead_new_id();
                $record['created_by'] = $userLabel;
                $record['created_at'] = $now;
                foreach (array('storage_type', 'drive_file_id', 'drive_folder_id', 'drive_web_view_link', 'drive_web_content_link', 'original_name', 'stored_name', 'mime_type', 'size', 'drive_year_folder_id', 'drive_type_folder_id', 'drive_month_folder_id', 'upload_status', 'drive_upload_error') as $fileKey2) {
                    $record[$fileKey2] = ($fileKey2 === 'size' ? 0 : '');
                }
                $items[] = $record;
                $inserted++;
            }
        }
        foreach ($items as $itemIdx => $item) {
            if (!is_array($item)) continue;
            if (isset($item['category']) && (string)$item['category'] === 'lease') {
                $items[$itemIdx]['category_name'] = '임대차';
                $items[$itemIdx]['updated_by'] = $userLabel;
                $items[$itemIdx]['updated_at'] = $now;
                if (!isset($items[$itemIdx]['deleted_at'])) $items[$itemIdx]['deleted_at'] = '';
                if (!isset($items[$itemIdx]['deleted_by'])) $items[$itemIdx]['deleted_by'] = '';
            }
        }
        if (!cpms_company_overhead_save_month('lease', $year, $monthText, $items)) {
            $writeError = cpms_company_overhead_last_write_error();
            cpms_company_overhead_log('Company overhead lease upload save failed.', array('year' => $year, 'month' => $monthText, 'error' => $writeError));
            $message = '임대차 엑셀은 읽었지만 월별 데이터 저장에 실패했습니다. 서버의 데이터 폴더 쓰기 권한을 확인해주세요. (' . $year . '년 ' . $monthText . '월)';
            if ($writeError !== '') $message .= ' 원인: ' . $writeError;
            return array('ok' => false, 'message' => $message, 'year' => $year);
        }
        $monthsTouched[] = $monthText;
    }

    return array(
        'ok' => true,
        'message' => '임대차 엑셀 업로드가 반영되었습니다.',
        'source_count' => isset($parsed['source_count']) ? (int)$parsed['source_count'] : 0,
        'active_count' => (int)$activeCount,
        'inserted' => $inserted,
        'updated' => $updated,
        'months' => $monthsTouched,
        'file_name' => $originalName,
        'year' => $year,
    );
}}

if (!function_exists('cpms_company_overhead_lease_validate_upload_file')) {
function cpms_company_overhead_lease_validate_upload_file($file) {
    if (!is_array($file) || !isset($file['error']) || (int)$file['error'] === UPLOAD_ERR_NO_FILE) return array('ok' => false, 'message' => '업로드할 엑셀 파일을 선택해주세요.');
    if ((int)$file['error'] !== UPLOAD_ERR_OK) return array('ok' => false, 'message' => '엑셀 파일 업로드에 실패했습니다. 오류코드: ' . (int)$file['error']);
    $tmpPath = isset($file['tmp_name']) ? trim((string)$file['tmp_name']) : '';
    $originalName = isset($file['name']) ? trim((string)$file['name']) : '';
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($tmpPath === '' || !is_file($tmpPath)) return array('ok' => false, 'message' => '업로드 파일을 찾을 수 없습니다.');
    if ($ext !== 'xlsx') return array('ok' => false, 'message' => '.xlsx 파일만 업로드할 수 있습니다.');
    return array('ok' => true, 'tmp_path' => $tmpPath, 'original_name' => $originalName);
}}

if (!function_exists('cpms_company_overhead_import_lease_xlsx')) {
function cpms_company_overhead_import_lease_xlsx($year, $file, $user = null, $startMonth = 1) {
    $year = cpms_company_overhead_normalize_upload_year($year);
    if ($year < 2000 || $year > 2100) return array('ok' => false, 'message' => '적용연도가 올바르지 않습니다.');
    $valid = cpms_company_overhead_lease_validate_upload_file($file);
    if (empty($valid['ok'])) return $valid;
    $parsed = cpms_company_overhead_lease_parse_xlsx_file($valid['tmp_path']);
    if (empty($parsed['ok'])) return $parsed;
    $rows = isset($parsed['rows']) && is_array($parsed['rows']) ? $parsed['rows'] : array();
    $build = cpms_company_overhead_lease_build_month_rows($rows, $year, $startMonth);
    return cpms_company_overhead_lease_apply_month_rows($year, $build['month_rows'], $parsed, $valid['original_name'], $user, $build['active_count']);
}}

if (!function_exists('cpms_company_overhead_create_lease_preview')) {
function cpms_company_overhead_create_lease_preview($year, $month, $file, $user = null) {
    $year = cpms_company_overhead_normalize_upload_year($year);
    $month = cpms_company_overhead_normalize_upload_month($month);
    if ($year < 2000 || $year > 2100) return array('ok' => false, 'message' => '적용연도가 올바르지 않습니다.');
    $valid = cpms_company_overhead_lease_validate_upload_file($file);
    if (empty($valid['ok'])) return $valid;
    $parsed = cpms_company_overhead_lease_parse_xlsx_file($valid['tmp_path']);
    if (empty($parsed['ok'])) return $parsed;
    $rows = isset($parsed['rows']) && is_array($parsed['rows']) ? $parsed['rows'] : array();
    $build = cpms_company_overhead_lease_build_month_rows($rows, $year, $month);
    if (!isset($build['month_rows']) || !is_array($build['month_rows']) || count($build['month_rows']) === 0) {
        return array('ok' => false, 'message' => '적용 시작월 이후 유효한 임대차 계약이 없습니다.');
    }

    $token = cpms_company_overhead_lease_new_token();
    $tmpDir = cpms_company_overhead_lease_tmp_root();
    if (!cpms_company_overhead_lease_ensure_dir($tmpDir)) return array('ok' => false, 'message' => '임대차 업로드 임시 폴더를 만들 수 없습니다.');
    $localPath = rtrim($tmpDir, '/\\') . '/' . $token . '.xlsx';
    $moved = false;
    if (function_exists('move_uploaded_file')) $moved = @move_uploaded_file($valid['tmp_path'], $localPath);
    if (!$moved) $moved = @copy($valid['tmp_path'], $localPath);
    if (!$moved) return array('ok' => false, 'message' => '업로드 파일을 임시 보관하지 못했습니다.');

    if (!isset($_SESSION['_company_overhead_lease_preview']) || !is_array($_SESSION['_company_overhead_lease_preview'])) $_SESSION['_company_overhead_lease_preview'] = array();
    $_SESSION['_company_overhead_lease_preview'][$token] = array(
        'token' => $token,
        'created_at' => time(),
        'year' => sprintf('%04d', $year),
        'month' => sprintf('%02d', $month),
        'uploaded_original_name' => $valid['original_name'],
        'temp_path' => $localPath,
        'uploaded_by' => cpms_company_overhead_user_label($user),
        'parsed' => $parsed,
        'month_rows' => $build['month_rows'],
        'months' => $build['months'],
        'active_count' => $build['active_count'],
    );

    return array('ok' => true, 'message' => '임대차 엑셀 업로드 미리보기가 생성되었습니다.', 'token' => $token, 'year' => sprintf('%04d', $year), 'month' => sprintf('%02d', $month), 'preview' => $_SESSION['_company_overhead_lease_preview'][$token]);
}}

if (!function_exists('cpms_company_overhead_get_lease_preview')) {
function cpms_company_overhead_get_lease_preview($token) {
    $token = trim((string)$token);
    if ($token === '' || !isset($_SESSION['_company_overhead_lease_preview'][$token]) || !is_array($_SESSION['_company_overhead_lease_preview'][$token])) return null;
    $preview = $_SESSION['_company_overhead_lease_preview'][$token];
    if (!isset($preview['created_at']) || (time() - (int)$preview['created_at']) > 7200) {
        if (isset($preview['temp_path']) && is_file($preview['temp_path'])) @unlink($preview['temp_path']);
        unset($_SESSION['_company_overhead_lease_preview'][$token]);
        return null;
    }
    return $preview;
}}

if (!function_exists('cpms_company_overhead_clear_lease_preview')) {
function cpms_company_overhead_clear_lease_preview($token) {
    $preview = cpms_company_overhead_get_lease_preview($token);
    if (is_array($preview) && isset($preview['temp_path']) && is_file($preview['temp_path'])) @unlink($preview['temp_path']);
    if (isset($_SESSION['_company_overhead_lease_preview'][$token])) unset($_SESSION['_company_overhead_lease_preview'][$token]);
}}

if (!function_exists('cpms_company_overhead_confirm_lease_preview')) {
function cpms_company_overhead_confirm_lease_preview($token, $user = null) {
    $preview = cpms_company_overhead_get_lease_preview($token);
    if (!is_array($preview)) return array('ok' => false, 'message' => '확정할 임대차 미리보기를 찾을 수 없습니다.');
    $year = isset($preview['year']) ? (string)$preview['year'] : date('Y');
    $parsed = isset($preview['parsed']) && is_array($preview['parsed']) ? $preview['parsed'] : array();
    $monthRows = isset($preview['month_rows']) && is_array($preview['month_rows']) ? $preview['month_rows'] : array();
    $originalName = isset($preview['uploaded_original_name']) ? (string)$preview['uploaded_original_name'] : '';
    $activeCount = isset($preview['active_count']) ? (int)$preview['active_count'] : 0;
    $result = cpms_company_overhead_lease_apply_month_rows($year, $monthRows, $parsed, $originalName, $user, $activeCount);
    if (!empty($result['ok'])) cpms_company_overhead_clear_lease_preview($token);
    return $result;
}}

if (!function_exists('cpms_company_overhead_lease_remove_group_from_month')) {
function cpms_company_overhead_lease_remove_group_from_month($year, $month, $groupId) {
    $groupId = trim((string)$groupId);
    if ($groupId === '') return;
    $items = cpms_company_overhead_load_month('lease', $year, $month, true);
    $clean = array();
    foreach ($items as $item) {
        if (is_array($item) && isset($item['lease_group_id']) && (string)$item['lease_group_id'] === $groupId) continue;
        $clean[] = $item;
    }
    cpms_company_overhead_save_month('lease', $year, $month, $clean);
}}

if (!function_exists('cpms_company_overhead_add_lease')) {
function cpms_company_overhead_add_lease($data, $file, $user = null) {
    $baseRecord = cpms_company_overhead_prepare_record('lease', $data, null, $user);
    if (!is_array($baseRecord)) return array('ok' => false, 'message' => '임대차 구분이 올바르지 않습니다.');

    $year = (int)$baseRecord['year'];
    $startMonth = (int)$baseRecord['month'];
    if ($year < 2000 || $year > 2100 || $startMonth < 1 || $startMonth > 12) {
        return array('ok' => false, 'message' => '임대차 시작 연월이 올바르지 않습니다.');
    }

    if (!isset($baseRecord['lease_group_id']) || trim((string)$baseRecord['lease_group_id']) === '') {
        $baseRecord['lease_group_id'] = $baseRecord['id'];
    }
    $groupId = (string)$baseRecord['lease_group_id'];

    $upload = cpms_company_overhead_upload_attachment('lease', $baseRecord, $file, $user);
    if (is_array($upload) && isset($upload['record'])) $baseRecord = cpms_company_overhead_apply_upload_record($baseRecord, $upload['record']);

    $savedYms = array();
    $startMaintenanceFee = isset($baseRecord['maintenance_fee']) ? $baseRecord['maintenance_fee'] : '';
    for ($m = $startMonth; $m <= 12; $m++) {
        $record = $baseRecord;
        $record['id'] = ($m === $startMonth) ? $baseRecord['id'] : cpms_company_overhead_new_id();
        $record['year'] = sprintf('%04d', $year);
        $record['month'] = sprintf('%02d', $m);
        $record['maintenance_fee'] = ($m === $startMonth) ? $startMaintenanceFee : '';
        if (!isset($record['contract_period'])) $record['contract_period'] = '';
        $record['lease_group_id'] = $groupId;

        $items = cpms_company_overhead_load_month('lease', $record['year'], $record['month'], true);
        $items[] = $record;
        $saved = cpms_company_overhead_save_month('lease', $record['year'], $record['month'], $items);
        if (!$saved) {
            foreach ($savedYms as $savedYm) {
                cpms_company_overhead_lease_remove_group_from_month(substr($savedYm, 0, 4), substr($savedYm, 5, 2), $groupId);
            }
            if (isset($baseRecord['drive_file_id']) && trim((string)$baseRecord['drive_file_id']) !== '') {
                cpms_drive_delete_file((string)$baseRecord['drive_file_id'], array('user' => $user, 'section' => 'company_overhead', 'message' => 'Lease JSON save failed after Drive upload.'));
            }
            return array('ok' => false, 'message' => '임대차 월별 데이터를 저장하지 못했습니다.');
        }
        $savedYms[] = $record['year'] . '-' . $record['month'];
    }

    return array('ok' => true, 'record' => $baseRecord, 'upload' => $upload, 'message' => '임대차가 시작월부터 12월까지 등록되었습니다.');
}}

if (!function_exists('cpms_company_overhead_add')) {
function cpms_company_overhead_add($category, $data, $file, $user = null) {
    if ($category === 'lease') return cpms_company_overhead_add_lease($data, $file, $user);
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
