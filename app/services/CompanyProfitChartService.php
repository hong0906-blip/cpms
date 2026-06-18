<?php
/**
 * Shared display helpers for company profit dashboard charts.
 * PHP 5.6 compatible.
 */

if (!function_exists('cpms_company_profit_money')) {
function cpms_company_profit_money($amount) {
    return number_format((float)$amount) . '원';
}}

if (!function_exists('cpms_company_profit_rate_label')) {
function cpms_company_profit_rate_label($rate, $noSales) {
    if ((int)$noSales === 1) return '매출 없음';
    return number_format((float)$rate, 1) . '%';
}}

if (!function_exists('cpms_company_profit_rate_state')) {
function cpms_company_profit_rate_state($rate, $noSales) {
    if ((int)$noSales === 1) {
        return array('key' => 'loss', 'label' => '적자', 'class' => 'cp-company-rate-loss');
    }
    $rate = (float)$rate;
    if ($rate >= 100) return array('key' => 'loss', 'label' => '적자', 'class' => 'cp-company-rate-loss');
    if ($rate >= 90) return array('key' => 'danger', 'label' => '위험', 'class' => 'cp-company-rate-danger');
    if ($rate >= 80) return array('key' => 'warn', 'label' => '주의', 'class' => 'cp-company-rate-warn');
    return array('key' => 'normal', 'label' => '정상', 'class' => 'cp-company-rate-normal');
}}

if (!function_exists('cpms_company_profit_max_value')) {
function cpms_company_profit_max_value($rows, $keys) {
    $max = 0.0;
    if (!is_array($rows)) return 1.0;
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        foreach ($keys as $key) {
            $value = isset($row[$key]) ? abs((float)$row[$key]) : 0.0;
            if ($value > $max) $max = $value;
        }
    }
    return $max > 0 ? $max : 1.0;
}}

if (!function_exists('cpms_company_profit_sales_basis_label')) {
function cpms_company_profit_sales_basis_label($basis) {
    $basis = trim((string)$basis);
    if ($basis === 'confirmed') return '기성반영';
    if ($basis === 'expected') return '예상매출포함';
    if ($basis === 'mixed') return '기성+예상혼합';
    return '매출 없음';
}}

if (!function_exists('cpms_company_profit_safe_percent')) {
function cpms_company_profit_safe_percent($value, $max) {
    $value = (float)$value;
    $max = (float)$max;
    if ($max <= 0) return 0;
    $pct = ($value / $max) * 100;
    if ($pct < 0) $pct = 0;
    if ($pct > 100) $pct = 100;
    return $pct;
}}
