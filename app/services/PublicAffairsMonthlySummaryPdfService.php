<?php
/**
 * 공무 > 월별 투입비 집계 PDF 생성 및 Google Drive 저장 서비스.
 * PHP 5.6 compatible.
 */

require_once __DIR__ . '/GoogleDriveHelper.php';
require_once __DIR__ . '/ApprovalPdfService.php';

if (!function_exists('cpms_public_affairs_monthly_summary_valid_ym')) {
function cpms_public_affairs_monthly_summary_valid_ym($ym) {
    $ym = trim((string)$ym);
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) return false;
    $month = (int)substr($ym, 5, 2);
    return ($month >= 1 && $month <= 12);
}}

if (!function_exists('cpms_public_affairs_monthly_summary_valid_date')) {
function cpms_public_affairs_monthly_summary_valid_date($date) {
    $date = trim((string)$date);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
    $ts = strtotime($date . ' 00:00:00');
    return ($ts !== false && date('Y-m-d', $ts) === $date);
}}

if (!function_exists('cpms_public_affairs_monthly_summary_storage_dir')) {
function cpms_public_affairs_monthly_summary_storage_dir() {
    return cpms_drive_storage_root() . '/public_affairs_monthly_summary';
}}

if (!function_exists('cpms_public_affairs_monthly_summary_history_path')) {
function cpms_public_affairs_monthly_summary_history_path() {
    return cpms_public_affairs_monthly_summary_storage_dir() . '/upload_history.json';
}}

if (!function_exists('cpms_public_affairs_monthly_summary_read_history')) {
function cpms_public_affairs_monthly_summary_read_history() {
    $path = cpms_public_affairs_monthly_summary_history_path();
    if (!is_file($path)) return array('records' => array());
    $text = @file_get_contents($path);
    if ($text === false || trim($text) === '') return array('records' => array());
    $data = @json_decode($text, true);
    if (!is_array($data)) return array('records' => array());
    if (!isset($data['records']) || !is_array($data['records'])) $data['records'] = array();
    return $data;
}}

if (!function_exists('cpms_public_affairs_monthly_summary_write_history')) {
function cpms_public_affairs_monthly_summary_write_history($history) {
    if (!is_array($history)) $history = array();
    if (!isset($history['records']) || !is_array($history['records'])) $history['records'] = array();
    if (count($history['records']) > 200) {
        $history['records'] = array_slice($history['records'], -200);
    }
    $history['updated_at'] = date('Y-m-d H:i:s');
    $dir = cpms_public_affairs_monthly_summary_storage_dir();
    if (!cpms_drive_ensure_dir($dir)) return false;
    $options = 0;
    if (defined('JSON_UNESCAPED_UNICODE')) $options = $options | JSON_UNESCAPED_UNICODE;
    if (defined('JSON_UNESCAPED_SLASHES')) $options = $options | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_PRETTY_PRINT')) $options = $options | JSON_PRETTY_PRINT;
    $json = json_encode($history, $options);
    if ($json === false) return false;
    return (@file_put_contents(cpms_public_affairs_monthly_summary_history_path(), $json, LOCK_EX) !== false);
}}

if (!function_exists('cpms_public_affairs_monthly_summary_success_record')) {
function cpms_public_affairs_monthly_summary_success_record($history, $ym, $runDate, $mode) {
    $records = (is_array($history) && isset($history['records']) && is_array($history['records'])) ? $history['records'] : array();
    $mode = trim((string)$mode);
    for ($i = count($records) - 1; $i >= 0; $i--) {
        $row = is_array($records[$i]) ? $records[$i] : array();
        if (isset($row['status']) && $row['status'] === 'success'
            && isset($row['ym']) && (string)$row['ym'] === (string)$ym
            && isset($row['run_date']) && (string)$row['run_date'] === (string)$runDate
            && ($mode === '' || (isset($row['mode']) && (string)$row['mode'] === $mode))) {
            return $row;
        }
    }
    return null;
}}

if (!function_exists('cpms_public_affairs_monthly_summary_append_history')) {
function cpms_public_affairs_monthly_summary_append_history($record) {
    $history = cpms_public_affairs_monthly_summary_read_history();
    $history['records'][] = is_array($record) ? $record : array();
    return cpms_public_affairs_monthly_summary_write_history($history);
}}

if (!function_exists('cpms_public_affairs_monthly_summary_load_data')) {
function cpms_public_affairs_monthly_summary_load_data($pdo, $ym) {
    static $cache = array();
    $ym = trim((string)$ym);
    if (!cpms_public_affairs_monthly_summary_valid_ym($ym)) {
        return array('ok' => false, 'message' => '집계 기준월이 올바르지 않습니다.');
    }
    if (isset($cache[$ym])) return $cache[$ym];

    $viewPath = dirname(__DIR__) . '/views/project/monthly_summary.php';
    if (!is_file($viewPath)) {
        return array('ok' => false, 'message' => '월별 투입비 집계 화면 파일을 찾을 수 없습니다.');
    }

    $hadYm = array_key_exists('ym', $_GET);
    $oldYm = $hadYm ? $_GET['ym'] : null;
    $_GET['ym'] = $ym;
    $cpmsMonthlySummaryDataOnly = true;
    $bufferLevel = ob_get_level();
    ob_start();
    try {
        include $viewPath;
        while (ob_get_level() > $bufferLevel) ob_end_clean();
    } catch (Exception $e) {
        while (ob_get_level() > $bufferLevel) ob_end_clean();
        if ($hadYm) $_GET['ym'] = $oldYm;
        else unset($_GET['ym']);
        return array('ok' => false, 'message' => '월별 투입비 집계 생성 실패: ' . $e->getMessage());
    }
    if ($hadYm) $_GET['ym'] = $oldYm;
    else unset($_GET['ym']);

    $loadedErrors = isset($errors) && is_array($errors) ? $errors : array();
    if (count($loadedErrors) > 0) {
        return array('ok' => false, 'message' => implode(' / ', $loadedErrors));
    }
    $rows = isset($summaryRows) && is_array($summaryRows) ? $summaryRows : array();
    $totals = isset($summaryTotals) && is_array($summaryTotals) ? $summaryTotals : array();
    if (count($rows) === 0) {
        return array('ok' => false, 'message' => 'PDF에 표시할 프로젝트가 없습니다.');
    }

    $cache[$ym] = array(
        'ok' => true,
        'ym' => $ym,
        'month_title' => isset($monthTitle) ? (string)$monthTitle : (substr($ym, 5, 2) . '월'),
        'rows' => $rows,
        'totals' => $totals,
        'message' => ''
    );
    return $cache[$ym];
}}

if (!function_exists('cpms_public_affairs_monthly_summary_h')) {
function cpms_public_affairs_monthly_summary_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}}

if (!function_exists('cpms_public_affairs_monthly_summary_money')) {
function cpms_public_affairs_monthly_summary_money($value) {
    if (function_exists('cpms_monthly_summary_money')) return cpms_monthly_summary_money($value);
    return ((float)$value == 0.0) ? '-' : number_format((float)$value, 0);
}}

if (!function_exists('cpms_public_affairs_monthly_summary_count')) {
function cpms_public_affairs_monthly_summary_count($value) {
    if (function_exists('cpms_monthly_summary_count')) return cpms_monthly_summary_count($value);
    $number = (float)$value;
    if ($number == 0.0) return '-';
    if (abs($number - round($number)) < 0.001) return number_format($number, 0);
    return rtrim(rtrim(number_format($number, 2, '.', ','), '0'), '.');
}}

if (!function_exists('cpms_public_affairs_monthly_summary_ratio')) {
function cpms_public_affairs_monthly_summary_ratio($a, $b) {
    if (function_exists('cpms_monthly_summary_ratio')) return cpms_monthly_summary_ratio($a, $b);
    $b = (float)$b;
    if ($b <= 0) return '-';
    return number_format(((float)$a / $b) * 100, 1) . '%';
}}

if (!function_exists('cpms_public_affairs_monthly_summary_pdf_cell')) {
function cpms_public_affairs_monthly_summary_pdf_cell($value, $className) {
    return '<td class="' . cpms_public_affairs_monthly_summary_h($className) . '">'
        . cpms_public_affairs_monthly_summary_h($value) . '</td>';
}}

if (!function_exists('cpms_public_affairs_monthly_summary_page_dimensions')) {
function cpms_public_affairs_monthly_summary_page_dimensions($data) {
    return array('width_mm' => 297, 'height_mm' => 210);
}}

if (!function_exists('cpms_public_affairs_monthly_summary_build_pdf_html')) {
function cpms_public_affairs_monthly_summary_build_pdf_html($data, $runDate) {
    $ym = isset($data['ym']) ? (string)$data['ym'] : date('Y-m');
    $rows = isset($data['rows']) && is_array($data['rows']) ? $data['rows'] : array();
    $totals = isset($data['totals']) && is_array($data['totals']) ? $data['totals'] : array();
    $monthTitle = isset($data['month_title']) ? (string)$data['month_title'] : (substr($ym, 5, 2) . '월');
    $rowCount = count($rows);
    $tableFontSize = 9;
    $cellPadding = 1.3;
    if ($rowCount > 20) {
        $tableFontSize = 8;
        $cellPadding = 0.9;
    }
    if ($rowCount > 30) {
        $tableFontSize = 7;
        $cellPadding = 0.5;
    }
    if ($rowCount > 42) {
        $tableFontSize = 6;
        $cellPadding = 0.3;
    }

    $html = '<!doctype html><html><head><meta charset="utf-8">';
    $html .= '<title>월별 투입비 집계</title>';
    $html .= '<style>';
    $html .= '@page{margin:5mm;}';
    $html .= 'html,body{margin:0;padding:0;background:#fff;color:#111;font-family:"Malgun Gothic","Noto Sans CJK KR","NanumGothic",sans-serif;}';
    $html .= '.head{margin-bottom:2.5mm}.title{font-size:16px;font-weight:bold}.meta{font-size:8px;color:#555;margin-top:1mm}';
    $html .= 'table{width:100%;border-collapse:collapse;table-layout:fixed;font-size:' . $tableFontSize . 'px;line-height:1.15}';
    $html .= 'th,td{border:0.2mm solid #777;padding:' . number_format($cellPadding, 1, '.', '') . 'mm 0.8mm;vertical-align:middle;word-wrap:break-word}';
    $html .= 'tr{page-break-inside:avoid}thead th{background:#d7aa8a;font-weight:bold;text-align:center}thead tr.subhead th{background:#efd6c2}';
    $html .= 'tbody tr:nth-child(even) td{background:#f8fafc}tfoot td{background:#e5e7eb;font-weight:bold}';
    $html .= '.left{text-align:left}.right{text-align:right}.center{text-align:center}.bold{font-weight:bold}';
    $html .= '</style></head><body>';
    $html .= '<div class="head"><div class="title">월별 투입비 집계</div>';
    $html .= '<div class="meta">' . cpms_public_affairs_monthly_summary_h(str_replace('-', '.', $ym))
        . ' 기준 · 오늘 날짜 ' . cpms_public_affairs_monthly_summary_h(str_replace('-', '.', $runDate)) . '</div></div>';
    $html .= '<table><colgroup>';
    $html .= '<col style="width:18%"><col style="width:10%"><col style="width:10%"><col style="width:12%">';
    $html .= '<col style="width:10%"><col style="width:12%"><col style="width:11%"><col style="width:11%">';
    $html .= '<col style="width:6%">';
    $html .= '</colgroup><thead><tr>';
    $html .= '<th rowspan="2">현장명</th>';
    $html .= '<th colspan="5">' . cpms_public_affairs_monthly_summary_h($monthTitle) . ' 투입금액</th>';
    $html .= '<th rowspan="2">누적투입금액<br>(A)</th>';
    $html .= '<th rowspan="2">누적기성금액<br>(B)</th>';
    $html .= '<th rowspan="2">A/B</th>';
    $html .= '</tr><tr class="subhead">';
    $html .= '<th>노무비</th><th>장비비</th><th>자재구입비</th><th>외주비</th><th>합계</th>';
    $html .= '</tr></thead><tbody>';

    for ($i = 0; $i < count($rows); $i++) {
        $row = is_array($rows[$i]) ? $rows[$i] : array();
        $html .= '<tr>';
        $html .= cpms_public_affairs_monthly_summary_pdf_cell(isset($row['project_name']) ? $row['project_name'] : '', 'left bold');
        $html .= cpms_public_affairs_monthly_summary_pdf_cell(cpms_public_affairs_monthly_summary_money(isset($row['labor_amount']) ? $row['labor_amount'] : 0), 'right');
        $html .= cpms_public_affairs_monthly_summary_pdf_cell(cpms_public_affairs_monthly_summary_money(isset($row['equipment_amount']) ? $row['equipment_amount'] : 0), 'right');
        $html .= cpms_public_affairs_monthly_summary_pdf_cell(cpms_public_affairs_monthly_summary_money(isset($row['material_purchase_amount']) ? $row['material_purchase_amount'] : 0), 'right');
        $html .= cpms_public_affairs_monthly_summary_pdf_cell(cpms_public_affairs_monthly_summary_money(isset($row['outsourcing_amount']) ? $row['outsourcing_amount'] : 0), 'right');
        $html .= cpms_public_affairs_monthly_summary_pdf_cell(cpms_public_affairs_monthly_summary_money(isset($row['monthly_cost_total']) ? $row['monthly_cost_total'] : 0), 'right bold');
        $html .= cpms_public_affairs_monthly_summary_pdf_cell(cpms_public_affairs_monthly_summary_money(isset($row['cumulative_input']) ? $row['cumulative_input'] : 0), 'right bold');
        $html .= cpms_public_affairs_monthly_summary_pdf_cell(cpms_public_affairs_monthly_summary_money(isset($row['cumulative_revenue']) ? $row['cumulative_revenue'] : 0), 'right bold');
        $html .= cpms_public_affairs_monthly_summary_pdf_cell(cpms_public_affairs_monthly_summary_ratio(isset($row['cumulative_input']) ? $row['cumulative_input'] : 0, isset($row['cumulative_revenue']) ? $row['cumulative_revenue'] : 0), 'right');
        $html .= '</tr>';
    }
    $html .= '</tbody><tfoot><tr>';
    $html .= cpms_public_affairs_monthly_summary_pdf_cell('합계', 'left');
    $html .= cpms_public_affairs_monthly_summary_pdf_cell(cpms_public_affairs_monthly_summary_money(isset($totals['labor_amount']) ? $totals['labor_amount'] : 0), 'right');
    $html .= cpms_public_affairs_monthly_summary_pdf_cell(cpms_public_affairs_monthly_summary_money(isset($totals['equipment_amount']) ? $totals['equipment_amount'] : 0), 'right');
    $html .= cpms_public_affairs_monthly_summary_pdf_cell(cpms_public_affairs_monthly_summary_money(isset($totals['material_purchase_amount']) ? $totals['material_purchase_amount'] : 0), 'right');
    $html .= cpms_public_affairs_monthly_summary_pdf_cell(cpms_public_affairs_monthly_summary_money(isset($totals['outsourcing_amount']) ? $totals['outsourcing_amount'] : 0), 'right');
    $html .= cpms_public_affairs_monthly_summary_pdf_cell(cpms_public_affairs_monthly_summary_money(isset($totals['monthly_cost_total']) ? $totals['monthly_cost_total'] : 0), 'right');
    $html .= cpms_public_affairs_monthly_summary_pdf_cell(cpms_public_affairs_monthly_summary_money(isset($totals['cumulative_input']) ? $totals['cumulative_input'] : 0), 'right');
    $html .= cpms_public_affairs_monthly_summary_pdf_cell(cpms_public_affairs_monthly_summary_money(isset($totals['cumulative_revenue']) ? $totals['cumulative_revenue'] : 0), 'right');
    $html .= cpms_public_affairs_monthly_summary_pdf_cell(cpms_public_affairs_monthly_summary_ratio(isset($totals['cumulative_input']) ? $totals['cumulative_input'] : 0, isset($totals['cumulative_revenue']) ? $totals['cumulative_revenue'] : 0), 'right');
    $html .= '</tr></tfoot></table></body></html>';
    return $html;
}}

if (!function_exists('cpms_public_affairs_monthly_summary_folder_name')) {
function cpms_public_affairs_monthly_summary_folder_name($ym) {
    $year = substr((string)$ym, 2, 2);
    $month = (int)substr((string)$ym, 5, 2);
    return $year . '년 ' . $month . '월';
}}

if (!function_exists('cpms_public_affairs_monthly_summary_file_name')) {
function cpms_public_affairs_monthly_summary_file_name($runDate) {
    return ((int)substr((string)$runDate, 8, 2)) . '일 월별투입비 집계.pdf';
}}

if (!function_exists('cpms_public_affairs_monthly_summary_ensure_drive_folders')) {
function cpms_public_affairs_monthly_summary_ensure_drive_folders($ym, $user) {
    $parentId = cpms_drive_shared_drive_id();
    if ($parentId === '') {
        return array('ok' => false, 'message' => 'Google 공유드라이브 ID가 설정되지 않았습니다.');
    }
    $context = array(
        'user' => $user,
        'section' => 'public_affairs_monthly_summary_pdf',
        'document_type' => '월별 투입비 집계',
        'document_year' => substr($ym, 0, 4),
        'document_month' => substr($ym, 5, 2),
        'target_folder_id' => $parentId
    );
    $root = cpms_drive_find_or_create_folder('06_월별집계', $parentId, $context);
    if (empty($root['ok']) || !isset($root['file']['id'])) {
        return array('ok' => false, 'message' => isset($root['message']) ? $root['message'] : '06_월별집계 폴더를 준비하지 못했습니다.');
    }
    $rootId = (string)$root['file']['id'];
    $context['target_folder_id'] = $rootId;
    $monthName = cpms_public_affairs_monthly_summary_folder_name($ym);
    $month = cpms_drive_find_or_create_folder($monthName, $rootId, $context);
    if (empty($month['ok']) || !isset($month['file']['id'])) {
        return array('ok' => false, 'message' => isset($month['message']) ? $month['message'] : ($monthName . ' 폴더를 준비하지 못했습니다.'));
    }
    return array(
        'ok' => true,
        'root_folder_id' => $rootId,
        'month_folder_id' => (string)$month['file']['id'],
        'root_folder_name' => '06_월별집계',
        'month_folder_name' => $monthName,
        'root_folder_web_view_link' => isset($root['file']['webViewLink']) ? (string)$root['file']['webViewLink'] : '',
        'month_folder_web_view_link' => isset($month['file']['webViewLink']) ? (string)$month['file']['webViewLink'] : '',
        'message' => ''
    );
}}

if (!function_exists('cpms_public_affairs_monthly_summary_find_drive_file')) {
function cpms_public_affairs_monthly_summary_find_drive_file($name, $folderId) {
    $q = "'" . cpms_drive_query_escape($folderId) . "' in parents"
        . " and name = '" . cpms_drive_query_escape($name) . "'"
        . " and mimeType = 'application/pdf' and trashed = false";
    $params = array(
        'q' => $q,
        'fields' => 'files(' . cpms_drive_file_fields() . ')',
        'supportsAllDrives' => 'true',
        'includeItemsFromAllDrives' => 'true',
        'corpora' => 'drive',
        'driveId' => cpms_drive_shared_drive_id(),
        'pageSize' => '10'
    );
    $response = cpms_drive_authorized_request('GET', 'files', $params, null, array('Accept: application/json'), false, 30);
    if (empty($response['ok'])) {
        return array(
            'ok' => false,
            'found' => false,
            'file' => null,
            'message' => '기존 월별 집계 PDF 확인 실패: ' . (isset($response['error']) ? $response['error'] : ''),
            'http_code' => isset($response['http_code']) ? (int)$response['http_code'] : 0
        );
    }
    $files = (isset($response['json']['files']) && is_array($response['json']['files'])) ? $response['json']['files'] : array();
    return array(
        'ok' => true,
        'found' => count($files) > 0,
        'file' => count($files) > 0 ? $files[0] : null,
        'message' => '',
        'http_code' => isset($response['http_code']) ? (int)$response['http_code'] : 0
    );
}}

if (!function_exists('cpms_public_affairs_monthly_summary_update_drive_file')) {
function cpms_public_affairs_monthly_summary_update_drive_file($fileId, $localPath, $driveName, $context) {
    $content = @file_get_contents($localPath);
    if ($content === false) {
        return array('ok' => false, 'file' => null, 'message' => '생성된 PDF 파일을 읽지 못했습니다.', 'http_code' => 0);
    }
    $metadata = array('name' => $driveName, 'mimeType' => 'application/pdf');
    $boundary = 'cpms_monthly_summary_' . md5(uniqid('', true));
    $body = '--' . $boundary . "\r\n";
    $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
    $body .= cpms_drive_json_encode($metadata) . "\r\n";
    $body .= '--' . $boundary . "\r\n";
    $body .= "Content-Type: application/pdf\r\n\r\n";
    $body .= $content . "\r\n";
    $body .= '--' . $boundary . "--\r\n";
    $params = array(
        'uploadType' => 'multipart',
        'supportsAllDrives' => 'true',
        'fields' => cpms_drive_file_fields()
    );
    $headers = array(
        'Content-Type: multipart/related; boundary=' . $boundary,
        'Content-Length: ' . strlen($body),
        'Accept: application/json'
    );
    $response = cpms_drive_authorized_request('PATCH', 'files/' . rawurlencode($fileId), $params, $body, $headers, true, 120);
    if (empty($response['ok']) || !isset($response['json']['id'])) {
        $message = '기존 월별 집계 PDF 갱신 실패: ' . (isset($response['error']) ? $response['error'] : '');
        $logContext = is_array($context) ? $context : array();
        $logContext['message'] = $message;
        $logContext['http_status'] = isset($response['http_code']) ? (int)$response['http_code'] : 0;
        $logContext['google_response_excerpt'] = isset($response['body']) ? $response['body'] : '';
        cpms_drive_log_upload_failure($logContext);
        return array('ok' => false, 'file' => null, 'message' => $message, 'http_code' => isset($response['http_code']) ? (int)$response['http_code'] : 0);
    }
    return array('ok' => true, 'file' => $response['json'], 'message' => '기존 파일을 최신 PDF로 갱신했습니다.', 'http_code' => isset($response['http_code']) ? (int)$response['http_code'] : 0);
}}

if (!function_exists('cpms_public_affairs_monthly_summary_upload_or_replace')) {
function cpms_public_affairs_monthly_summary_upload_or_replace($localPath, $driveName, $folderId, $context) {
    $found = cpms_public_affairs_monthly_summary_find_drive_file($driveName, $folderId);
    if (empty($found['ok'])) return $found;
    if (!empty($found['found']) && isset($found['file']['id'])) {
        $updated = cpms_public_affairs_monthly_summary_update_drive_file((string)$found['file']['id'], $localPath, $driveName, $context);
        $updated['operation'] = 'updated';
        return $updated;
    }
    $uploaded = cpms_drive_upload_file($localPath, $driveName, $folderId, 'application/pdf', $context);
    $uploaded['operation'] = 'created';
    return $uploaded;
}}

if (!function_exists('cpms_public_affairs_monthly_summary_generate_unlocked')) {
function cpms_public_affairs_monthly_summary_generate_unlocked($pdo, $ym, $runDate, $user, $options) {
    $options = is_array($options) ? $options : array();
    $mode = isset($options['mode']) ? trim((string)$options['mode']) : 'manual';
    if ($mode === '') $mode = 'manual';
    $force = !empty($options['force']);
    $ym = trim((string)$ym);
    $runDate = trim((string)$runDate);
    if (!cpms_public_affairs_monthly_summary_valid_ym($ym)) {
        return array('ok' => false, 'message' => '집계 기준월이 올바르지 않습니다.');
    }
    if (!cpms_public_affairs_monthly_summary_valid_date($runDate)) {
        return array('ok' => false, 'message' => 'PDF 저장일이 올바르지 않습니다.');
    }

    $history = cpms_public_affairs_monthly_summary_read_history();
    $existing = cpms_public_affairs_monthly_summary_success_record($history, $ym, $runDate, 'cron');
    if ($mode === 'cron' && !$force && is_array($existing)) {
        return array(
            'ok' => true,
            'skipped' => true,
            'message' => '같은 날짜의 자동 저장이 이미 완료되어 중복 실행을 건너뛰었습니다.',
            'record' => $existing
        );
    }

    $data = cpms_public_affairs_monthly_summary_load_data($pdo, $ym);
    if (empty($data['ok'])) return $data;
    $fileName = cpms_public_affairs_monthly_summary_file_name($runDate);
    $pageDimensions = cpms_public_affairs_monthly_summary_page_dimensions($data);
    $html = cpms_public_affairs_monthly_summary_build_pdf_html($data, $runDate);
    $context = array(
        'user' => $user,
        'uploaded_by' => $user,
        'section' => 'public_affairs_monthly_summary_pdf',
        'document_type' => '월별 투입비 집계',
        'document_year' => substr($ym, 0, 4),
        'document_month' => substr($ym, 5, 2),
        'original_name' => $fileName,
        'page_format' => 'A4-L',
        'page_width_mm' => $pageDimensions['width_mm'],
        'page_height_mm' => $pageDimensions['height_mm'],
        'single_page' => true,
        'pdf_title' => '월별 투입비 집계'
    );
    $pdf = cpms_approval_pdf_create_from_html($html, $fileName, $context);
    if (empty($pdf['ok']) || !isset($pdf['path']) || !is_file($pdf['path'])) {
        $message = isset($pdf['message']) ? $pdf['message'] : '월별 투입비 집계 PDF 생성에 실패했습니다.';
        cpms_public_affairs_monthly_summary_append_history(array(
            'executed_at' => date('Y-m-d H:i:s'),
            'mode' => $mode,
            'ym' => $ym,
            'run_date' => $runDate,
            'file_name' => $fileName,
            'status' => 'failed',
            'error' => cpms_drive_redact_text($message)
        ));
        return array('ok' => false, 'message' => $message);
    }

    $folders = cpms_public_affairs_monthly_summary_ensure_drive_folders($ym, $user);
    if (empty($folders['ok'])) {
        cpms_approval_pdf_cleanup_temp_file($pdf['path']);
        return $folders;
    }
    $context['target_folder_id'] = $folders['month_folder_id'];
    $context['local_temp_path'] = $pdf['path'];
    $upload = cpms_public_affairs_monthly_summary_upload_or_replace($pdf['path'], $fileName, $folders['month_folder_id'], $context);
    cpms_approval_pdf_cleanup_temp_file($pdf['path']);
    if (empty($upload['ok']) || !isset($upload['file']) || !is_array($upload['file'])) {
        $message2 = isset($upload['message']) ? $upload['message'] : 'Google Drive 업로드에 실패했습니다.';
        cpms_public_affairs_monthly_summary_append_history(array(
            'executed_at' => date('Y-m-d H:i:s'),
            'mode' => $mode,
            'ym' => $ym,
            'run_date' => $runDate,
            'folder_name' => $folders['root_folder_name'] . '/' . $folders['month_folder_name'],
            'file_name' => $fileName,
            'status' => 'failed',
            'error' => cpms_drive_redact_text($message2)
        ));
        return array('ok' => false, 'message' => $message2);
    }

    $file = $upload['file'];
    $fileId = isset($file['id']) ? trim((string)$file['id']) : '';
    $verify = $fileId !== '' ? cpms_drive_get_file_info($fileId) : array('ok' => false, 'message' => 'Drive 파일 ID가 비어 있습니다.');
    $verifiedFile = (!empty($verify['ok']) && isset($verify['file']) && is_array($verify['file'])) ? $verify['file'] : array();
    $verifiedParents = isset($verifiedFile['parents']) && is_array($verifiedFile['parents']) ? $verifiedFile['parents'] : array();
    $verifiedName = isset($verifiedFile['name']) ? (string)$verifiedFile['name'] : '';
    $verifiedMimeType = isset($verifiedFile['mimeType']) ? (string)$verifiedFile['mimeType'] : '';
    $verified = !empty($verify['ok'])
        && isset($verifiedFile['id'])
        && (string)$verifiedFile['id'] === $fileId
        && $verifiedName === $fileName
        && $verifiedMimeType === 'application/pdf'
        && in_array((string)$folders['month_folder_id'], $verifiedParents, true);
    if (!$verified) {
        $verifyMessage = isset($verify['message']) ? (string)$verify['message'] : 'Drive 업로드 후 파일을 확인하지 못했습니다.';
        cpms_public_affairs_monthly_summary_append_history(array(
            'executed_at' => date('Y-m-d H:i:s'),
            'mode' => $mode,
            'ym' => $ym,
            'run_date' => $runDate,
            'folder_name' => $folders['root_folder_name'] . '/' . $folders['month_folder_name'],
            'file_name' => $fileName,
            'drive_file_id' => $fileId,
            'status' => 'failed',
            'error' => cpms_drive_redact_text('Drive 업로드 검증 실패: ' . $verifyMessage)
        ));
        return array('ok' => false, 'message' => 'Google Drive 업로드 결과를 다시 확인하지 못했습니다. 파일 ID: ' . $fileId);
    }
    $file = $verifiedFile;
    $record = array(
        'executed_at' => date('Y-m-d H:i:s'),
        'mode' => $mode,
        'ym' => $ym,
        'run_date' => $runDate,
        'folder_name' => $folders['root_folder_name'] . '/' . $folders['month_folder_name'],
        'file_name' => $fileName,
        'status' => 'success',
        'operation' => isset($upload['operation']) ? (string)$upload['operation'] : 'created',
        'drive_file_id' => isset($file['id']) ? (string)$file['id'] : '',
        'drive_folder_id' => $folders['month_folder_id'],
        'drive_web_view_link' => isset($file['webViewLink']) ? (string)$file['webViewLink'] : '',
        'drive_month_folder_web_view_link' => isset($folders['month_folder_web_view_link']) ? (string)$folders['month_folder_web_view_link'] : '',
        'size' => isset($file['size']) ? (string)$file['size'] : ''
    );
    $historySaved = cpms_public_affairs_monthly_summary_append_history($record);
    $pathLabel = $record['folder_name'] . '/' . $fileName;
    $message3 = 'Google Drive에 저장했습니다: ' . $pathLabel;
    if (!$historySaved) $message3 .= ' (업로드 기록 파일 저장은 실패했습니다.)';
    return array('ok' => true, 'skipped' => false, 'message' => $message3, 'record' => $record);
}}

if (!function_exists('cpms_public_affairs_monthly_summary_generate')) {
function cpms_public_affairs_monthly_summary_generate($pdo, $ym, $runDate, $user, $options) {
    $dir = cpms_public_affairs_monthly_summary_storage_dir();
    if (!cpms_drive_ensure_dir($dir)) {
        return array('ok' => false, 'message' => '월별 집계 작업 폴더를 만들 수 없습니다.');
    }
    $lockPath = $dir . '/generate.lock';
    $lock = @fopen($lockPath, 'c');
    if (!$lock) return array('ok' => false, 'message' => '월별 집계 작업 잠금 파일을 열 수 없습니다.');
    if (!@flock($lock, LOCK_EX)) {
        fclose($lock);
        return array('ok' => false, 'message' => '월별 집계 작업 잠금을 얻지 못했습니다.');
    }
    $result = cpms_public_affairs_monthly_summary_generate_unlocked($pdo, $ym, $runDate, $user, $options);
    @flock($lock, LOCK_UN);
    fclose($lock);
    return $result;
}}

if (!function_exists('cpms_public_affairs_monthly_summary_is_due_date')) {
function cpms_public_affairs_monthly_summary_is_due_date($date) {
    if (!cpms_public_affairs_monthly_summary_valid_date($date)) return false;
    $day = (int)substr($date, 8, 2);
    $lastDay = (int)date('t', strtotime($date . ' 00:00:00'));
    return in_array($day, array(10, 20, 25, $lastDay), true);
}}
