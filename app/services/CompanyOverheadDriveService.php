<?php
/**
 * Company overhead Google Drive folder helpers.
 * PHP 5.6 compatible.
 */

require_once __DIR__ . '/GoogleDriveHelper.php';

if (!function_exists('cpms_company_overhead_drive_category_labels')) {
function cpms_company_overhead_drive_category_labels() {
    return array(
        'payroll' => '임직원월급',
        'fixed_cost' => '고정비',
        'vehicles' => '회사차량',
        'dormitories' => '숙소',
        'corporate_cards' => '법인카드',
        'offices' => '사무실',
        'etc' => '기타',
    );
}}

if (!function_exists('cpms_company_overhead_drive_category_label')) {
function cpms_company_overhead_drive_category_label($category, $fallback) {
    $labels = cpms_company_overhead_drive_category_labels();
    $category = trim((string)$category);
    if (isset($labels[$category])) return $labels[$category];
    $fallback = trim((string)$fallback);
    return $fallback !== '' ? $fallback : $category;
}}

if (!function_exists('cpms_company_overhead_drive_ensure_month_folder')) {
function cpms_company_overhead_drive_ensure_month_folder($category, $categoryLabel, $year, $month, $context) {
    $category = trim((string)$category);
    $categoryLabel = cpms_company_overhead_drive_category_label($category, $categoryLabel);
    $year = sprintf('%04d', (int)$year);
    $month = sprintf('%02d', (int)$month);
    if (!is_array($context)) $context = array();
    $context['section'] = isset($context['section']) ? $context['section'] : 'company_overhead';
    $context['document_year'] = $year;
    $context['document_month'] = $month;
    $context['document_type'] = $categoryLabel;

    $sharedDriveId = cpms_drive_shared_drive_id();
    if ($sharedDriveId === '') {
        return array('ok' => false, 'message' => 'Shared drive ID is not configured.', 'http_code' => 0);
    }

    $management = cpms_drive_find_or_create_folder('04_관리부', $sharedDriveId, $context);
    if (empty($management['ok']) || !isset($management['file']['id'])) {
        return array('ok' => false, 'message' => isset($management['message']) ? $management['message'] : '04_관리부 folder failed.', 'http_code' => isset($management['http_code']) ? (int)$management['http_code'] : 0);
    }
    $managementId = (string)$management['file']['id'];

    $overhead = cpms_drive_find_or_create_folder('총관리비', $managementId, $context);
    if (empty($overhead['ok']) || !isset($overhead['file']['id'])) {
        return array('ok' => false, 'message' => isset($overhead['message']) ? $overhead['message'] : 'Company overhead folder failed.', 'http_code' => isset($overhead['http_code']) ? (int)$overhead['http_code'] : 0);
    }
    $overheadId = (string)$overhead['file']['id'];

    $categoryFolder = cpms_drive_find_or_create_folder($categoryLabel, $overheadId, $context);
    if (empty($categoryFolder['ok']) || !isset($categoryFolder['file']['id'])) {
        return array('ok' => false, 'message' => isset($categoryFolder['message']) ? $categoryFolder['message'] : 'Company overhead category folder failed.', 'http_code' => isset($categoryFolder['http_code']) ? (int)$categoryFolder['http_code'] : 0);
    }
    $categoryId = (string)$categoryFolder['file']['id'];

    $yearFolder = cpms_drive_find_or_create_folder($year, $categoryId, $context);
    if (empty($yearFolder['ok']) || !isset($yearFolder['file']['id'])) {
        return array('ok' => false, 'message' => isset($yearFolder['message']) ? $yearFolder['message'] : 'Company overhead year folder failed.', 'http_code' => isset($yearFolder['http_code']) ? (int)$yearFolder['http_code'] : 0);
    }
    $yearId = (string)$yearFolder['file']['id'];

    $monthFolder = cpms_drive_find_or_create_folder($month, $yearId, $context);
    if (empty($monthFolder['ok']) || !isset($monthFolder['file']['id'])) {
        return array('ok' => false, 'message' => isset($monthFolder['message']) ? $monthFolder['message'] : 'Company overhead month folder failed.', 'http_code' => isset($monthFolder['http_code']) ? (int)$monthFolder['http_code'] : 0);
    }

    return array(
        'ok' => true,
        'folder_id' => (string)$monthFolder['file']['id'],
        'shared_drive_id' => $sharedDriveId,
        'management_folder_id' => $managementId,
        'company_management_folder_id' => $managementId,
        'overhead_folder_id' => $overheadId,
        'category_folder_id' => $categoryId,
        'year_folder_id' => $yearId,
        'month_folder_id' => (string)$monthFolder['file']['id'],
        'message' => 'Company overhead Drive folder is ready.',
        'http_code' => isset($monthFolder['http_code']) ? (int)$monthFolder['http_code'] : 0,
    );
}}
