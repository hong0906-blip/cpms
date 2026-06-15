<?php
/**
 * 자재구입비 사용내역 helper
 * - PHP 5.6 호환
 */

if (!function_exists('cpms_material_usage_table_exists')) {
function cpms_material_usage_table_exists($pdo)
{
    if (!$pdo) return false;
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE :tbl");
        $st->bindValue(':tbl', 'cpms_material_usage');
        $st->execute();
        return $st->fetch() ? true : false;
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_material_usage_column_exists')) {
function cpms_material_usage_column_exists($pdo, $column)
{
    if (!$pdo || $column === '') return false;
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM cpms_material_usage LIKE :col");
        $st->bindValue(':col', $column);
        $st->execute();
        return $st->fetch() ? true : false;
    } catch (Exception $e) {
        return false;
    }
}}

if (!function_exists('cpms_material_usage_ensure_schema')) {
function cpms_material_usage_ensure_schema($pdo)
{
    if (!$pdo || !cpms_material_usage_table_exists($pdo)) return false;
    if (!cpms_material_usage_column_exists($pdo, 'advance_yn')) {
        try {
            $pdo->exec("ALTER TABLE cpms_material_usage ADD COLUMN advance_yn CHAR(1) NOT NULL DEFAULT 'N' AFTER amount");
        } catch (Exception $e) {}
    }
    return cpms_material_usage_column_exists($pdo, 'advance_yn');
}}

if (!function_exists('cpms_material_advance_yn')) {
function cpms_material_advance_yn($value)
{
    $value = strtoupper(trim((string)$value));
    return ($value === 'Y') ? 'Y' : 'N';
}}
