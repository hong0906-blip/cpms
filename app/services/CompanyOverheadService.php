<?php
/**
 * Company overhead reader.
 * The input UI is intentionally not implemented here.
 * PHP 5.6 compatible.
 */

if (!function_exists('cpms_company_overhead_categories')) {
function cpms_company_overhead_categories() {
    return array(
        'payroll' => array('label' => '임직원 월급', 'path' => 'payroll'),
        'fixed_cost' => array('label' => '매달 고정금액', 'path' => 'fixed_cost'),
        'company_management' => array('label' => '회사 관리비', 'path' => 'company_management'),
        'vehicles' => array('label' => '회사차량 관리비', 'path' => 'vehicles'),
        'dormitories' => array('label' => '숙소 관리비', 'path' => 'dormitories'),
        'corporate_cards' => array('label' => '법인카드 사용금액', 'path' => 'corporate_cards'),
        'offices' => array('label' => '사무실 관리비', 'path' => 'offices'),
        'etc' => array('label' => '기타 운영비', 'path' => 'etc'),
    );
}}

if (!function_exists('cpms_company_overhead_base_dirs')) {
function cpms_company_overhead_base_dirs() {
    $root = dirname(dirname(__DIR__));
    $dirs = array($root . '/data/company_overhead');
    if (function_exists('cpms_storage_root')) {
        $dirs[] = cpms_storage_root() . '/company_overhead';
    } else {
        $dirs[] = $root . '/storage/company_overhead';
    }
    return $dirs;
}}

if (!function_exists('cpms_company_overhead_read_json')) {
function cpms_company_overhead_read_json($path) {
    if (!is_file($path)) return null;
    $txt = @file_get_contents($path);
    if ($txt === false || trim($txt) === '') return null;
    $data = @json_decode($txt, true);
    return is_array($data) ? $data : null;
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

if (!function_exists('cpms_company_overhead_sum_record')) {
function cpms_company_overhead_sum_record($record) {
    if (!is_array($record)) return cpms_company_overhead_numeric_value($record);

    if (cpms_company_overhead_is_list($record)) {
        $sum = 0.0;
        foreach ($record as $row) {
            $sum += cpms_company_overhead_sum_record($row);
        }
        return $sum;
    }

    $amountKeys = array('amount', 'total_amount', 'cost_amount', 'salary_amount', 'pay_amount', 'price', 'cost', 'total', 'value');
    foreach ($amountKeys as $key) {
        if (isset($record[$key]) && !is_array($record[$key])) {
            return cpms_company_overhead_numeric_value($record[$key]);
        }
    }

    if (isset($record['items']) && is_array($record['items'])) {
        return cpms_company_overhead_sum_record($record['items']);
    }

    $sum = 0.0;
    foreach ($record as $key => $value) {
        $keyText = strtolower((string)$key);
        if ($keyText === 'id' || $keyText === 'year' || $keyText === 'month' || $keyText === 'created_at' || $keyText === 'updated_at') {
            continue;
        }
        if (is_array($value)) {
            $sum += cpms_company_overhead_sum_record($value);
        }
    }
    return $sum;
}}

if (!function_exists('cpms_company_overhead_month_file')) {
function cpms_company_overhead_month_file($baseDir, $categoryPath, $ym) {
    $ym = trim((string)$ym);
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) return '';
    $year = substr($ym, 0, 4);
    $month = substr($ym, 5, 2);
    return rtrim($baseDir, '/\\') . '/' . $categoryPath . '/' . $year . '/' . $month . '.json';
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
    $baseDirs = cpms_company_overhead_base_dirs();

    foreach ($months as $ym) {
        if (!preg_match('/^\d{4}-\d{2}$/', (string)$ym)) continue;
        foreach ($categories as $key => $meta) {
            $categoryPath = isset($meta['path']) ? (string)$meta['path'] : (string)$key;
            foreach ($baseDirs as $baseDir) {
                $path = cpms_company_overhead_month_file($baseDir, $categoryPath, $ym);
                if ($path === '' || !is_file($path)) continue;

                $data = cpms_company_overhead_read_json($path);
                if (!is_array($data)) continue;

                $amount = cpms_company_overhead_sum_record($data);
                $summary['categories'][$key]['amount'] += $amount;
                $summary['categories'][$key]['has_data'] = true;
                $summary['has_data'] = true;
                break;
            }
        }
    }

    foreach ($summary['categories'] as $row) {
        $summary['total'] += isset($row['amount']) ? (float)$row['amount'] : 0.0;
    }

    return $summary;
}}
