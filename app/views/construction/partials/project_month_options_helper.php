<?php
/**
 * Project based month option helper for construction tabs.
 * PHP 5.6 compatible.
 */

if (!function_exists('cpms_construction_column_exists')) {
function cpms_construction_column_exists($pdo, $table, $column)
{
    static $cache = array();

    $table = trim((string)$table);
    $column = trim((string)$column);
    if (!$pdo || $table === '' || $column === '') return false;
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) return false;

    $cacheKey = $table . '.' . $column;
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];

    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `" . str_replace('`', '``', $table) . "` LIKE :col");
        $st->bindValue(':col', $column);
        $st->execute();
        $cache[$cacheKey] = ($st->fetch(PDO::FETCH_ASSOC) ? true : false);
    } catch (Exception $e) {
        $cache[$cacheKey] = false;
    }

    return $cache[$cacheKey];
}}

if (!function_exists('cpms_construction_normalize_date')) {
function cpms_construction_normalize_date($value)
{
    $value = trim((string)$value);
    if ($value === '' || $value === '0000-00-00' || $value === '0000-00') return '';

    if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/', $value, $m)) {
        $year = (int)$m[1];
        $month = (int)$m[2];
        $day = (int)$m[3];
        if ($year >= 1900 && $year <= 2100 && checkdate($month, $day, $year)) {
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
        return '';
    }

    if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $m)) {
        $year = (int)$m[1];
        $month = (int)$m[2];
        $day = (int)$m[3];
        if ($year >= 1900 && $year <= 2100 && checkdate($month, $day, $year)) {
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
        return '';
    }

    if (preg_match('/^(\d{4})[-\/.](\d{1,2})$/', $value, $m)) {
        $year = (int)$m[1];
        $month = (int)$m[2];
        if ($year >= 1900 && $year <= 2100 && checkdate($month, 1, $year)) {
            return sprintf('%04d-%02d-01', $year, $month);
        }
        return '';
    }

    if (preg_match('/^(\d{4})(\d{2})$/', $value, $m)) {
        $year = (int)$m[1];
        $month = (int)$m[2];
        if ($year >= 1900 && $year <= 2100 && checkdate($month, 1, $year)) {
            return sprintf('%04d-%02d-01', $year, $month);
        }
        return '';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) return '';

    $year = (int)date('Y', $timestamp);
    $month = (int)date('m', $timestamp);
    $day = (int)date('d', $timestamp);
    if ($year < 1900 || $year > 2100 || !checkdate($month, $day, $year)) return '';

    return date('Y-m-d', $timestamp);
}}

if (!function_exists('cpms_construction_normalize_ym')) {
function cpms_construction_normalize_ym($value)
{
    $value = trim((string)$value);
    if (preg_match('/^(\d{4})-(\d{2})$/', $value, $m)) {
        $year = (int)$m[1];
        $month = (int)$m[2];
        if ($year >= 1900 && $year <= 2100 && $month >= 1 && $month <= 12) {
            return sprintf('%04d-%02d', $year, $month);
        }
    }

    $date = cpms_construction_normalize_date($value);
    if ($date !== '') return substr($date, 0, 7);

    return '';
}}

if (!function_exists('cpms_construction_settlement_ym_from_date')) {
function cpms_construction_settlement_ym_from_date($dateValue)
{
    $date = cpms_construction_normalize_date($dateValue);
    if ($date === '') return '';

    $ym = substr($date, 0, 7);
    $day = (int)substr($date, 8, 2);
    if ($day >= 26) {
        $nextTs = strtotime($ym . '-01 +1 month');
        if ($nextTs !== false) return date('Y-m', $nextTs);
    }

    return $ym;
}}

if (!function_exists('cpms_construction_period_from_row')) {
function cpms_construction_period_from_row($row, $startColumn, $endColumn)
{
    if (!is_array($row)) return null;
    if (!isset($row[$startColumn]) || !isset($row[$endColumn])) return null;

    $startDate = cpms_construction_normalize_date($row[$startColumn]);
    $endDate = cpms_construction_normalize_date($row[$endColumn]);
    if ($startDate === '' || $endDate === '') return null;

    $startYm = cpms_construction_settlement_ym_from_date($startDate);
    $endYm = cpms_construction_settlement_ym_from_date($endDate);
    if ($startYm === '' || $endYm === '' || strcmp($startYm, $endYm) > 0) return null;

    return array(
        'start_date' => $startDate,
        'end_date' => $endDate,
        'start_ym' => $startYm,
        'end_ym' => $endYm,
        'source_columns' => $startColumn . ',' . $endColumn
    );
}}

if (!function_exists('cpms_construction_shift_ym')) {
function cpms_construction_shift_ym($ym, $delta)
{
    $ym = cpms_construction_normalize_ym($ym);
    if ($ym === '') return '';

    $year = (int)substr($ym, 0, 4);
    $month = (int)substr($ym, 5, 2);
    $total = ($year * 12) + ($month - 1) + (int)$delta;
    if ($total < 0) return '';

    $newYear = (int)floor($total / 12);
    $newMonth = ($total % 12) + 1;
    if ($newYear < 1900 || $newYear > 2100) return '';

    return sprintf('%04d-%02d', $newYear, $newMonth);
}}

if (!function_exists('cpms_construction_current_business_ym')) {
function cpms_construction_current_business_ym($dateValue = '')
{
    $dateValue = trim((string)$dateValue);
    if ($dateValue === '') $dateValue = date('Y-m-d');

    $date = cpms_construction_normalize_date($dateValue);
    if ($date === '') $date = date('Y-m-d');

    $ym = substr($date, 0, 7);
    $day = (int)substr($date, 8, 2);
    if ($day >= 26) {
        $nextYm = cpms_construction_shift_ym($ym, 1);
        if ($nextYm !== '') return $nextYm;
    }

    return $ym;
}}

if (!function_exists('cpms_construction_project_period')) {
function cpms_construction_project_period($pdo, $projectId)
{
    $projectId = (int)$projectId;

    global $projectRow;
    if (is_array($projectRow)) {
        $rowProjectId = isset($projectRow['id']) ? (int)$projectRow['id'] : 0;
        if ($rowProjectId <= 0 || $rowProjectId === $projectId) {
            $period = cpms_construction_period_from_row($projectRow, 'start_date', 'end_date');
            if (is_array($period)) return $period;
        }
    }

    if (!$pdo || $projectId <= 0) return null;

    $pairs = array(
        array('start_date', 'end_date'),
        array('contract_start_date', 'contract_end_date'),
        array('begin_date', 'finish_date')
    );

    $select = array('id');
    $selectedColumns = array('id' => true);
    for ($i = 0; $i < count($pairs); $i++) {
        for ($j = 0; $j < 2; $j++) {
            $column = $pairs[$i][$j];
            if (isset($selectedColumns[$column])) continue;
            if (cpms_construction_column_exists($pdo, 'cpms_projects', $column)) {
                $select[count($select)] = '`' . str_replace('`', '``', $column) . '`';
                $selectedColumns[$column] = true;
            }
        }
    }

    if (count($select) <= 1) return null;

    try {
        $sql = 'SELECT ' . implode(', ', $select) . ' FROM cpms_projects WHERE id = :pid LIMIT 1';
        $st = $pdo->prepare($sql);
        $st->bindValue(':pid', $projectId, PDO::PARAM_INT);
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;

        for ($i = 0; $i < count($pairs); $i++) {
            $startColumn = $pairs[$i][0];
            $endColumn = $pairs[$i][1];
            if (!isset($selectedColumns[$startColumn]) || !isset($selectedColumns[$endColumn])) continue;

            $period = cpms_construction_period_from_row($row, $startColumn, $endColumn);
            if (is_array($period)) return $period;
        }
    } catch (Exception $e) {
        return null;
    }

    return null;
}}

if (!function_exists('cpms_construction_build_month_range')) {
function cpms_construction_build_month_range($startYm, $endYm)
{
    $months = array();
    $startYm = cpms_construction_normalize_ym($startYm);
    $endYm = cpms_construction_normalize_ym($endYm);

    if ($startYm === '' || $endYm === '' || strcmp($startYm, $endYm) > 0) return $months;

    $cursor = $startYm;
    $guard = 0;
    while (strcmp($cursor, $endYm) <= 0 && $guard < 3000) {
        $months[count($months)] = $cursor;
        $next = cpms_construction_shift_ym($cursor, 1);
        if ($next === '' || $next === $cursor) break;
        $cursor = $next;
        $guard++;
    }

    return $months;
}}

if (!function_exists('cpms_construction_project_month_options')) {
function cpms_construction_project_month_options($pdo, $projectId, $selectedYm)
{
    $selectedYm = cpms_construction_normalize_ym($selectedYm);
    if ($selectedYm === '') $selectedYm = cpms_construction_current_business_ym();

    $months = array();
    $startYm = '';
    $endYm = '';
    $hasProjectPeriod = false;
    $message = '공사기간이 등록되지 않아 기본 월 범위로 표시됩니다.';

    $period = cpms_construction_project_period($pdo, (int)$projectId);
    if (is_array($period)) {
        $startYm = isset($period['start_ym']) ? (string)$period['start_ym'] : '';
        $endYm = isset($period['end_ym']) ? (string)$period['end_ym'] : '';
        $months = cpms_construction_build_month_range($startYm, $endYm);
        if (count($months) > 0) {
            $hasProjectPeriod = true;
            $message = '공사기간: ' . $startYm . ' ~ ' . $endYm . ' (현장 공사기간 기준)';

            if (!in_array($selectedYm, $months, true)) {
                $currentYm = cpms_construction_current_business_ym();
                if (in_array($currentYm, $months, true)) {
                    $selectedYm = $currentYm;
                } else {
                    $selectedYm = $endYm;
                }
            }
        }
    }

    if (!$hasProjectPeriod) {
        $baseYm = $selectedYm;
        for ($i = -12; $i <= 12; $i++) {
            $optYm = cpms_construction_shift_ym($baseYm, $i);
            if ($optYm !== '') $months[count($months)] = $optYm;
        }
    }

    return array(
        'months' => $months,
        'selected_ym' => $selectedYm,
        'start_ym' => $startYm,
        'end_ym' => $endYm,
        'has_project_period' => $hasProjectPeriod,
        'message' => $message
    );
}}
