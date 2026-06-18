<?php
/**
 * Company fuel overhead service.
 * PHP 5.6 compatible.
 */

require_once __DIR__ . '/GoogleDriveHelper.php';
require_once __DIR__ . '/CompanyOverheadService.php';
require_once __DIR__ . '/CompanyOverheadDriveService.php';
require_once __DIR__ . '/EmployeeVehicleService.php';
require_once __DIR__ . '/DataArchiveAccessService.php';

if (!function_exists('cpms_company_fuel_label')) {
function cpms_company_fuel_label() {
    return '주유비';
}}

if (!function_exists('cpms_company_fuel_original_folder_name')) {
function cpms_company_fuel_original_folder_name() {
    return '원본주유비엑셀';
}}

if (!function_exists('cpms_company_fuel_normalize_year_month')) {
function cpms_company_fuel_normalize_year_month($year, $month) {
    $y = (int)$year;
    $m = (int)$month;
    if ($y < 2000 || $y > 2100) $y = (int)date('Y');
    if ($m < 1 || $m > 12) $m = (int)date('m');
    return array('year' => sprintf('%04d', $y), 'month' => sprintf('%02d', $m), 'ym' => sprintf('%04d-%02d', $y, $m));
}}

if (!function_exists('cpms_company_fuel_tmp_root')) {
function cpms_company_fuel_tmp_root() {
    $root = function_exists('cpms_storage_root') ? cpms_storage_root() : dirname(dirname(__DIR__)) . '/storage';
    return $root . '/tmp/company_fuel_preview';
}}

if (!function_exists('cpms_company_fuel_ensure_dir')) {
function cpms_company_fuel_ensure_dir($dir) {
    if (function_exists('cpms_ensure_dir')) return cpms_ensure_dir($dir);
    if (is_dir($dir)) return true;
    return @mkdir($dir, 0777, true);
}}

if (!function_exists('cpms_company_fuel_data_roots')) {
function cpms_company_fuel_data_roots() {
    if (function_exists('cpms_company_overhead_base_dirs')) return cpms_company_overhead_base_dirs();
    $root = dirname(dirname(__DIR__));
    $dirs = array($root . '/data/company_overhead');
    if (function_exists('cpms_storage_root')) $dirs[] = cpms_storage_root() . '/company_overhead';
    else $dirs[] = $root . '/storage/company_overhead';
    return $dirs;
}}

if (!function_exists('cpms_company_fuel_writable_data_root')) {
function cpms_company_fuel_writable_data_root() {
    $roots = cpms_company_fuel_data_roots();
    foreach ($roots as $root) {
        $root = rtrim((string)$root, '/\\');
        if ($root === '') continue;
        if (is_dir($root) && is_writable($root)) return $root;
        if (!is_dir($root) && @mkdir($root, 0777, true) && is_dir($root) && is_writable($root)) return $root;
    }
    return count($roots) > 0 ? rtrim((string)$roots[0], '/\\') : dirname(dirname(__DIR__)) . '/data/company_overhead';
}}

if (!function_exists('cpms_company_fuel_json_encode')) {
function cpms_company_fuel_json_encode($data) {
    if (function_exists('cpms_company_overhead_json_encode')) return cpms_company_overhead_json_encode($data);
    $options = 0;
    if (defined('JSON_UNESCAPED_UNICODE')) $options = $options | JSON_UNESCAPED_UNICODE;
    if (defined('JSON_PRETTY_PRINT')) $options = $options | JSON_PRETTY_PRINT;
    if (defined('JSON_UNESCAPED_SLASHES')) $options = $options | JSON_UNESCAPED_SLASHES;
    return json_encode($data, $options);
}}

if (!function_exists('cpms_company_fuel_set_last_write_error')) {
function cpms_company_fuel_set_last_write_error($message) {
    $GLOBALS['_cpms_company_fuel_last_write_error'] = (string)$message;
}}

if (!function_exists('cpms_company_fuel_last_write_error')) {
function cpms_company_fuel_last_write_error() {
    return isset($GLOBALS['_cpms_company_fuel_last_write_error']) ? (string)$GLOBALS['_cpms_company_fuel_last_write_error'] : '';
}}

if (!function_exists('cpms_company_fuel_read_json')) {
function cpms_company_fuel_read_json($path) {
    if (!is_file($path)) return null;
    $txt = @file_get_contents($path);
    if ($txt === false || trim($txt) === '') return null;
    $data = @json_decode($txt, true);
    return is_array($data) ? $data : null;
}}

if (!function_exists('cpms_company_fuel_write_json')) {
function cpms_company_fuel_write_json($path, $data) {
    cpms_company_fuel_set_last_write_error('');
    $dir = dirname($path);
    if (!cpms_company_fuel_ensure_dir($dir)) {
        cpms_company_fuel_set_last_write_error('저장 폴더를 만들 수 없습니다: ' . $dir);
        return false;
    }
    if (!is_writable($dir)) {
        cpms_company_fuel_set_last_write_error('저장 폴더에 쓰기 권한이 없습니다: ' . $dir);
        return false;
    }
    $json = cpms_company_fuel_json_encode($data);
    if (!is_string($json)) {
        $jsonError = function_exists('json_last_error_msg') ? json_last_error_msg() : 'json_encode failed';
        cpms_company_fuel_set_last_write_error('JSON 변환에 실패했습니다: ' . $jsonError);
        return false;
    }
    $result = @file_put_contents($path, $json, LOCK_EX);
    if ($result === false) {
        $err = error_get_last();
        cpms_company_fuel_set_last_write_error('파일 쓰기에 실패했습니다: ' . $path . (is_array($err) && isset($err['message']) ? ' / ' . $err['message'] : ''));
        return false;
    }
    return true;
}}

if (!function_exists('cpms_company_fuel_month_file')) {
function cpms_company_fuel_month_file($year, $month) {
    $ym = cpms_company_fuel_normalize_year_month($year, $month);
    return cpms_company_fuel_writable_data_root() . '/fuel/' . $ym['year'] . '/' . $ym['month'] . '.json';
}}

if (!function_exists('cpms_company_fuel_month_file_candidates')) {
function cpms_company_fuel_month_file_candidates($year, $month) {
    $ym = cpms_company_fuel_normalize_year_month($year, $month);
    $paths = array();
    foreach (cpms_company_fuel_data_roots() as $root) {
        $root = rtrim((string)$root, '/\\');
        if ($root === '') continue;
        $paths[] = $root . '/fuel/' . $ym['year'] . '/' . $ym['month'] . '.json';
    }
    return $paths;
}}

if (!function_exists('cpms_company_fuel_history_dir')) {
function cpms_company_fuel_history_dir($year, $month) {
    $ym = cpms_company_fuel_normalize_year_month($year, $month);
    return cpms_company_fuel_writable_data_root() . '/fuel/' . $ym['year'] . '/' . $ym['month'] . '_history';
}}

if (!function_exists('cpms_company_fuel_logs_file')) {
function cpms_company_fuel_logs_file($year, $month) {
    $ym = cpms_company_fuel_normalize_year_month($year, $month);
    return cpms_company_fuel_writable_data_root() . '/fuel_logs/' . $ym['year'] . '/' . $ym['month'] . '.json';
}}

if (!function_exists('cpms_company_fuel_user_label')) {
function cpms_company_fuel_user_label($user) {
    if (function_exists('cpms_company_overhead_user_label')) return cpms_company_overhead_user_label($user);
    if (is_array($user)) {
        if (isset($user['name']) && trim((string)$user['name']) !== '') return trim((string)$user['name']);
        if (isset($user['email']) && trim((string)$user['email']) !== '') return trim((string)$user['email']);
        if (isset($user['id'])) return 'user#' . (int)$user['id'];
    }
    $txt = trim((string)$user);
    return $txt !== '' ? $txt : '-';
}}

if (!function_exists('cpms_company_fuel_money_value')) {
function cpms_company_fuel_money_value($value) {
    $amount = function_exists('cpms_company_overhead_numeric_value') ? cpms_company_overhead_numeric_value($value) : (float)str_replace(',', '', (string)$value);
    return round((float)$amount, 2);
}}

if (!function_exists('cpms_company_fuel_excel_serial_to_date')) {
function cpms_company_fuel_excel_serial_to_date($value) {
    if (!is_numeric($value)) return '';
    $days = (int)floor((float)$value);
    if ($days <= 0) return '';
    $ts = strtotime('1899-12-30 +' . $days . ' days');
    return $ts === false ? '' : date('Y-m-d', $ts);
}}

if (!function_exists('cpms_company_fuel_normalize_date')) {
function cpms_company_fuel_normalize_date($value, $year, $month, &$errors, $rowLabel) {
    $raw = trim((string)$value);
    if ($raw !== '' && is_numeric($raw) && (float)$raw > 20000 && (float)$raw < 90000) {
        $date = cpms_company_fuel_excel_serial_to_date($raw);
        if ($date !== '') return $date;
    }
    if ($raw !== '' && preg_match('/^(\d{4})(\d{2})(\d{2})$/', $raw, $m)) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    if ($raw !== '' && preg_match('/^(\d{4})[-\.\/\s년]*(\d{1,2})[-\.\/\s월]*(\d{1,2})/u', $raw, $m2)) {
        return sprintf('%04d-%02d-%02d', (int)$m2[1], (int)$m2[2], (int)$m2[3]);
    }
    if ($raw !== '') {
        $ts = strtotime($raw);
        if ($ts !== false) return date('Y-m-d', $ts);
    }
    $fallback = sprintf('%04d-%02d-01', (int)$year, (int)$month);
    $errors[] = $rowLabel . ' 날짜를 해석하지 못해 적용월 기준일(' . $fallback . ')로 처리했습니다.';
    return $fallback;
}}

if (!function_exists('cpms_company_fuel_new_token')) {
function cpms_company_fuel_new_token() {
    if (function_exists('openssl_random_pseudo_bytes')) {
        $bytes = openssl_random_pseudo_bytes(16);
        if ($bytes !== false) return bin2hex($bytes);
    }
    return md5(uniqid('', true) . mt_rand());
}}

if (!function_exists('cpms_company_fuel_new_item_id')) {
function cpms_company_fuel_new_item_id($year, $month, $area, $rowNumber) {
    return 'FUEL-' . sprintf('%04d%02d', (int)$year, (int)$month) . '-' . preg_replace('/[^A-Za-z0-9]/', '', (string)$area) . '-' . (int)$rowNumber . '-' . substr(md5(uniqid('', true)), 0, 8);
}}

if (!function_exists('cpms_company_fuel_zip_read')) {
function cpms_company_fuel_zip_read($zip, $name) {
    $idx = $zip->locateName($name);
    if ($idx === false) return '';
    $data = $zip->getFromIndex($idx);
    return ($data !== false) ? $data : '';
}}

if (!function_exists('cpms_company_fuel_xlsx_shared_strings')) {
function cpms_company_fuel_xlsx_shared_strings($zip) {
    $shared = array();
    $xml = cpms_company_fuel_zip_read($zip, 'xl/sharedStrings.xml');
    if ($xml === '') return $shared;
    $sx = @simplexml_load_string($xml);
    if (!$sx) return $shared;
    foreach ($sx->si as $si) {
        $text = '';
        if (isset($si->t)) {
            $text = (string)$si->t;
        } else if (isset($si->r)) {
            foreach ($si->r as $run) {
                if (isset($run->t)) $text .= (string)$run->t;
            }
        }
        $shared[] = $text;
    }
    return $shared;
}}

if (!function_exists('cpms_company_fuel_xlsx_sheet_path')) {
function cpms_company_fuel_xlsx_sheet_path($zip, $preferredName) {
    $workbookXml = cpms_company_fuel_zip_read($zip, 'xl/workbook.xml');
    $relsXml = cpms_company_fuel_zip_read($zip, 'xl/_rels/workbook.xml.rels');
    if ($workbookXml === '' || $relsXml === '') return array('path' => 'xl/worksheets/sheet1.xml', 'name' => '');
    $workbook = @simplexml_load_string($workbookXml);
    $rels = @simplexml_load_string($relsXml);
    if (!$workbook || !$rels) return array('path' => 'xl/worksheets/sheet1.xml', 'name' => '');

    $workbook->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $rels->registerXPathNamespace('rel', 'http://schemas.openxmlformats.org/package/2006/relationships');
    $sheetNodes = $workbook->xpath('//m:sheets/m:sheet');
    $relNodes = $rels->xpath('//rel:Relationship');
    if (!is_array($sheetNodes) || count($sheetNodes) === 0) $sheetNodes = isset($workbook->sheets->sheet) ? $workbook->sheets->sheet : array();
    if (!is_array($relNodes) || count($relNodes) === 0) $relNodes = isset($rels->Relationship) ? $rels->Relationship : array();

    $ridMap = array();
    foreach ($relNodes as $rel) {
        $rid = isset($rel['Id']) ? (string)$rel['Id'] : '';
        $target = isset($rel['Target']) ? (string)$rel['Target'] : '';
        if ($rid !== '') $ridMap[$rid] = $target;
    }

    $first = null;
    $preferred = null;
    foreach ($sheetNodes as $sheet) {
        $name = isset($sheet['name']) ? (string)$sheet['name'] : '';
        $attrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $rid2 = isset($attrs['id']) ? (string)$attrs['id'] : '';
        $target2 = isset($ridMap[$rid2]) ? $ridMap[$rid2] : '';
        if ($target2 === '') continue;
        $row = array('name' => $name, 'target' => $target2);
        if ($first === null) $first = $row;
        if ($name === $preferredName) $preferred = $row;
    }

    $chosen = $preferred !== null ? $preferred : $first;
    if ($chosen === null) return array('path' => 'xl/worksheets/sheet1.xml', 'name' => '');
    $target3 = str_replace('\\', '/', (string)$chosen['target']);
    if (substr($target3, 0, 1) === '/') $target3 = ltrim($target3, '/');
    if (strpos($target3, 'xl/') !== 0) $target3 = 'xl/' . $target3;
    return array('path' => $target3, 'name' => (string)$chosen['name']);
}}

if (!function_exists('cpms_company_fuel_xlsx_col_index')) {
function cpms_company_fuel_xlsx_col_index($cellRef) {
    $letters = preg_replace('/[^A-Z]/', '', strtoupper((string)$cellRef));
    if ($letters === '') return 0;
    $num = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $num = $num * 26 + (ord($letters[$i]) - 64);
    }
    return (int)$num;
}}

if (!function_exists('cpms_company_fuel_xlsx_cell_value')) {
function cpms_company_fuel_xlsx_cell_value($cell, $sharedStrings) {
    $t = isset($cell['t']) ? (string)$cell['t'] : '';
    if ($t === 's') {
        $idx = isset($cell->v) ? (int)$cell->v : -1;
        return ($idx >= 0 && isset($sharedStrings[$idx])) ? trim((string)$sharedStrings[$idx]) : '';
    }
    if ($t === 'inlineStr' && isset($cell->is)) {
        $text = '';
        if (isset($cell->is->t)) {
            $text = (string)$cell->is->t;
        } else if (isset($cell->is->r)) {
            foreach ($cell->is->r as $run) {
                if (isset($run->t)) $text .= (string)$run->t;
            }
        }
        return trim($text);
    }
    return isset($cell->v) ? trim((string)$cell->v) : '';
}}

if (!function_exists('cpms_company_fuel_xlsx_rows')) {
function cpms_company_fuel_xlsx_rows($path, $preferredSheetName, $maxRows) {
    if (!is_file($path)) return array('ok' => false, 'message' => '엑셀 파일을 찾을 수 없습니다.', 'rows' => array());
    if (!class_exists('ZipArchive')) return array('ok' => false, 'message' => '서버에 ZipArchive 확장이 없어 .xlsx를 읽을 수 없습니다.', 'rows' => array());
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return array('ok' => false, 'message' => '엑셀 파일을 열 수 없습니다.', 'rows' => array());

    $shared = cpms_company_fuel_xlsx_shared_strings($zip);
    $sheetInfo = cpms_company_fuel_xlsx_sheet_path($zip, $preferredSheetName);
    $sheetXml = cpms_company_fuel_zip_read($zip, $sheetInfo['path']);
    if ($sheetXml === '') {
        $zip->close();
        return array('ok' => false, 'message' => '엑셀 시트 데이터를 찾을 수 없습니다.', 'rows' => array());
    }
    $sheet = @simplexml_load_string($sheetXml);
    if (!$sheet || !isset($sheet->sheetData)) {
        $zip->close();
        return array('ok' => false, 'message' => '엑셀 시트를 해석할 수 없습니다.', 'rows' => array());
    }

    $records = array();
    $count = 0;
    foreach ($sheet->sheetData->row as $rowNode) {
        $count++;
        if ($count > (int)$maxRows) break;
        $rowNumber = isset($rowNode['r']) ? (int)$rowNode['r'] : $count;
        $cells = array();
        for ($i = 0; $i < 19; $i++) $cells[$i] = '';
        if (isset($rowNode->c)) {
            foreach ($rowNode->c as $cell) {
                $ref = isset($cell['r']) ? (string)$cell['r'] : '';
                $col = cpms_company_fuel_xlsx_col_index($ref);
                if ($col >= 1 && $col <= 19) $cells[$col - 1] = cpms_company_fuel_xlsx_cell_value($cell, $shared);
            }
        }
        $records[] = array('row_number' => $rowNumber, 'cells' => $cells);
    }
    $zip->close();
    return array('ok' => true, 'message' => '엑셀을 읽었습니다.', 'sheet_name' => isset($sheetInfo['name']) ? $sheetInfo['name'] : '', 'rows' => $records, 'source_row_count' => count($records));
}}

if (!function_exists('cpms_company_fuel_record_cell')) {
function cpms_company_fuel_record_cell($record, $index) {
    if (!is_array($record) || !isset($record['cells']) || !is_array($record['cells'])) return '';
    return isset($record['cells'][(int)$index]) ? trim((string)$record['cells'][(int)$index]) : '';
}}

if (!function_exists('cpms_company_fuel_header_key')) {
function cpms_company_fuel_header_key($value) {
    $text = trim((string)$value);
    if ($text === '') return '';
    $text = preg_replace('/\s+/u', '', $text);
    if (function_exists('mb_strtolower')) $text = mb_strtolower($text, 'UTF-8');
    else $text = strtolower($text);
    if ($text === '차량번호') return 'vehicle_number';
    if ($text === '날짜' || $text === '일자') return 'date';
    if ($text === '상품명' || $text === '품명') return 'product_name';
    if ($text === '단위') return 'unit';
    if ($text === '수량') return 'quantity';
    if ($text === '단가') return 'unit_price';
    if ($text === '공급가액') return 'supply_amount';
    if ($text === '부가세' || $text === '세액') return 'vat';
    if ($text === '합계금액' || $text === '합계') return 'total_amount';
    return '';
}}

if (!function_exists('cpms_company_fuel_default_table_map')) {
function cpms_company_fuel_default_table_map($startCol) {
    return array(
        'vehicle_number' => $startCol,
        'date' => $startCol + 1,
        'product_name' => $startCol + 2,
        'unit' => $startCol + 3,
        'quantity' => $startCol + 4,
        'unit_price' => $startCol + 5,
        'supply_amount' => $startCol + 6,
        'vat' => $startCol + 7,
        'total_amount' => $startCol + 8,
    );
}}

if (!function_exists('cpms_company_fuel_detect_table')) {
function cpms_company_fuel_detect_table($records, $startCol, $endCol) {
    $default = cpms_company_fuel_default_table_map($startCol);
    if (!is_array($records)) return array('map' => $default, 'header_index' => -1);
    for ($i = 0; $i < count($records); $i++) {
        if ($i > 120) break;
        $map = array();
        $matchCount = 0;
        for ($col = $startCol; $col <= $endCol; $col++) {
            $key = cpms_company_fuel_header_key(cpms_company_fuel_record_cell($records[$i], $col));
            if ($key !== '') {
                $map[$key] = $col;
                $matchCount++;
            }
        }
        if ($matchCount >= 5 && isset($map['vehicle_number']) && isset($map['date']) && isset($map['product_name']) && isset($map['total_amount'])) {
            foreach ($default as $key2 => $col2) {
                if (!isset($map[$key2])) $map[$key2] = $col2;
            }
            return array('map' => $map, 'header_index' => $i);
        }
    }
    return array('map' => $default, 'header_index' => -1);
}}

if (!function_exists('cpms_company_fuel_is_vehicle_total')) {
function cpms_company_fuel_is_vehicle_total($value) {
    $value = trim((string)$value);
    if ($value === '') return false;
    $compact = preg_replace('/\s+/u', '', $value);
    if ($compact === '차량계' || strpos($compact, '차량계') !== false) return true;
    foreach (array('유종계', '합계', '총합계', '총계') as $label) {
        if ($compact === $label || strpos($compact, $label) !== false) return true;
    }
    return false;
}}

if (!function_exists('cpms_company_fuel_company_vehicle_files')) {
function cpms_company_fuel_company_vehicle_files() {
    $root = dirname(dirname(__DIR__));
    return array(
        $root . '/data/company_overhead/company_vehicles/vehicles.json',
        $root . '/data/company_vehicles/vehicles.json',
    );
}}

if (!function_exists('cpms_find_company_vehicle_by_number')) {
function cpms_find_company_vehicle_by_number($vehicleNumber) {
    $norm = cpms_normalize_vehicle_number($vehicleNumber);
    if ($norm === '') return null;
    $files = cpms_company_fuel_company_vehicle_files();
    foreach ($files as $file) {
        $data = cpms_company_fuel_read_json($file);
        if (!is_array($data)) continue;
        if (isset($data['vehicles']) && is_array($data['vehicles'])) $rows = $data['vehicles'];
        else if (isset($data['items']) && is_array($data['items'])) $rows = $data['items'];
        else $rows = $data;
        if (!is_array($rows)) continue;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $numbers = array();
            foreach (array('vehicle_number', 'vehicle_numbers', 'car_number', 'number') as $key) {
                if (isset($row[$key])) {
                    foreach (cpms_employee_vehicle_numbers_from_value($row[$key]) as $num) $numbers[] = $num;
                }
            }
            foreach ($numbers as $number) {
                if (cpms_normalize_vehicle_number($number) !== $norm) continue;
                $owner = '';
                foreach (array('owner_name', 'manager_name', 'driver_name', 'name', 'department') as $nameKey) {
                    if (isset($row[$nameKey]) && trim((string)$row[$nameKey]) !== '') {
                        $owner = trim((string)$row[$nameKey]);
                        break;
                    }
                }
                $row['_display_name'] = $owner;
                return $row;
            }
        }
    }
    return null;
}}

if (!function_exists('cpms_company_fuel_resolve_display')) {
function cpms_company_fuel_resolve_display($pdo, $vehicleNumber) {
    $vehicleNumber = trim((string)$vehicleNumber);
    $norm = cpms_normalize_vehicle_number($vehicleNumber);
    if ($norm === '') {
        return array('display_name' => '', 'matched_type' => 'unknown', 'matched_employee_id' => '', 'matched_employee_name' => '', 'matched_company_vehicle_id' => '');
    }
    $employee = cpms_find_employee_by_vehicle_number($pdo, $vehicleNumber);
    if (is_array($employee)) {
        return array(
            'display_name' => isset($employee['employee_name']) ? (string)$employee['employee_name'] : $vehicleNumber,
            'matched_type' => 'employee',
            'matched_employee_id' => isset($employee['employee_id']) ? (string)$employee['employee_id'] : '',
            'matched_employee_name' => isset($employee['employee_name']) ? (string)$employee['employee_name'] : '',
            'matched_company_vehicle_id' => '',
        );
    }
    $companyVehicle = cpms_find_company_vehicle_by_number($vehicleNumber);
    if (is_array($companyVehicle)) {
        $display = isset($companyVehicle['_display_name']) ? trim((string)$companyVehicle['_display_name']) : '';
        if ($display === '') $display = $vehicleNumber;
        return array(
            'display_name' => $display,
            'matched_type' => 'company_vehicle',
            'matched_employee_id' => '',
            'matched_employee_name' => '',
            'matched_company_vehicle_id' => isset($companyVehicle['id']) ? (string)$companyVehicle['id'] : '',
        );
    }
    return array(
        'display_name' => $vehicleNumber,
        'matched_type' => 'vehicle_number',
        'matched_employee_id' => '',
        'matched_employee_name' => '',
        'matched_company_vehicle_id' => '',
    );
}}

if (!function_exists('cpms_company_fuel_resolve_display_with_employee_map')) {
function cpms_company_fuel_resolve_display_with_employee_map($employeeMap, $vehicleNumber) {
    $vehicleNumber = trim((string)$vehicleNumber);
    $norm = cpms_normalize_vehicle_number($vehicleNumber);
    if ($norm === '') {
        return array('display_name' => '', 'matched_type' => 'unknown', 'matched_employee_id' => '', 'matched_employee_name' => '', 'matched_company_vehicle_id' => '');
    }
    if (is_array($employeeMap) && isset($employeeMap[$norm]) && is_array($employeeMap[$norm])) {
        $employee = $employeeMap[$norm];
        return array(
            'display_name' => isset($employee['employee_name']) ? (string)$employee['employee_name'] : $vehicleNumber,
            'matched_type' => 'employee',
            'matched_employee_id' => isset($employee['employee_id']) ? (string)$employee['employee_id'] : '',
            'matched_employee_name' => isset($employee['employee_name']) ? (string)$employee['employee_name'] : '',
            'matched_company_vehicle_id' => '',
        );
    }
    $companyVehicle = cpms_find_company_vehicle_by_number($vehicleNumber);
    if (is_array($companyVehicle)) {
        $display = isset($companyVehicle['_display_name']) ? trim((string)$companyVehicle['_display_name']) : '';
        if ($display === '') $display = $vehicleNumber;
        return array(
            'display_name' => $display,
            'matched_type' => 'company_vehicle',
            'matched_employee_id' => '',
            'matched_employee_name' => '',
            'matched_company_vehicle_id' => isset($companyVehicle['id']) ? (string)$companyVehicle['id'] : '',
        );
    }
    return array(
        'display_name' => $vehicleNumber,
        'matched_type' => 'vehicle_number',
        'matched_employee_id' => '',
        'matched_employee_name' => '',
        'matched_company_vehicle_id' => '',
    );
}}

if (!function_exists('cpms_company_fuel_refresh_matches')) {
function cpms_company_fuel_refresh_matches($items, $pdo) {
    if (!is_array($items)) return array();
    $employeeMap = $pdo ? cpms_employee_vehicle_map($pdo) : array();
    $result = array();
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $vehicleNumber = isset($item['vehicle_number']) ? trim((string)$item['vehicle_number']) : '';
        $resolve = cpms_company_fuel_resolve_display_with_employee_map($employeeMap, $vehicleNumber);
        $item['vehicle_number_normalized'] = cpms_normalize_vehicle_number($vehicleNumber);
        $item['display_name'] = isset($resolve['display_name']) ? (string)$resolve['display_name'] : $vehicleNumber;
        $item['matched_type'] = isset($resolve['matched_type']) ? (string)$resolve['matched_type'] : 'vehicle_number';
        $item['matched_employee_id'] = isset($resolve['matched_employee_id']) ? (string)$resolve['matched_employee_id'] : '';
        $item['matched_employee_name'] = isset($resolve['matched_employee_name']) ? (string)$resolve['matched_employee_name'] : '';
        $item['matched_company_vehicle_id'] = isset($resolve['matched_company_vehicle_id']) ? (string)$resolve['matched_company_vehicle_id'] : '';
        $result[] = $item;
    }
    usort($result, 'cpms_company_fuel_sort_items');
    return $result;
}}

if (!function_exists('cpms_company_fuel_parse_area')) {
function cpms_company_fuel_parse_area($records, $table, $year, $month, $areaName, $pdo) {
    $items = array();
    $errors = array();
    $excluded = 0;
    $currentVehicle = '';
    $map = isset($table['map']) && is_array($table['map']) ? $table['map'] : array();
    $headerIndex = isset($table['header_index']) ? (int)$table['header_index'] : -1;

    for ($i = 0; $i < count($records); $i++) {
        if ($headerIndex >= 0 && $i <= $headerIndex) continue;
        $record = $records[$i];
        $rowNumber = isset($record['row_number']) ? (int)$record['row_number'] : ($i + 1);
        $rowLabel = $areaName . ' ' . $rowNumber . '행';
        $vehicleRaw = cpms_company_fuel_record_cell($record, isset($map['vehicle_number']) ? $map['vehicle_number'] : 0);
        $dateRaw = cpms_company_fuel_record_cell($record, isset($map['date']) ? $map['date'] : 1);
        $product = cpms_company_fuel_record_cell($record, isset($map['product_name']) ? $map['product_name'] : 2);
        $unit = cpms_company_fuel_record_cell($record, isset($map['unit']) ? $map['unit'] : 3);
        $quantityRaw = cpms_company_fuel_record_cell($record, isset($map['quantity']) ? $map['quantity'] : 4);
        $unitPriceRaw = cpms_company_fuel_record_cell($record, isset($map['unit_price']) ? $map['unit_price'] : 5);
        $supplyRaw = cpms_company_fuel_record_cell($record, isset($map['supply_amount']) ? $map['supply_amount'] : 6);
        $vatRaw = cpms_company_fuel_record_cell($record, isset($map['vat']) ? $map['vat'] : 7);
        $totalRaw = cpms_company_fuel_record_cell($record, isset($map['total_amount']) ? $map['total_amount'] : 8);

        if ($vehicleRaw === '' && $dateRaw === '' && $product === '' && $quantityRaw === '' && $totalRaw === '') continue;
        if (cpms_company_fuel_header_key($vehicleRaw) === 'vehicle_number' || cpms_company_fuel_header_key($product) === 'product_name') continue;
        if (cpms_company_fuel_is_vehicle_total($vehicleRaw) || cpms_company_fuel_is_vehicle_total($product)) {
            $currentVehicle = '';
            continue;
        }

        if ($vehicleRaw !== '') $currentVehicle = $vehicleRaw;
        $vehicleNumber = $vehicleRaw !== '' ? $vehicleRaw : $currentVehicle;
        $totalAmount = cpms_company_fuel_money_value($totalRaw);
        $isLikelyData = ($product !== '' || $dateRaw !== '' || $totalAmount > 0);

        if ($product === '') {
            if ($isLikelyData && $totalAmount > 0 && trim((string)$dateRaw) !== '' && trim((string)$vehicleNumber) !== '') {
                $errors[] = $rowLabel . ' 상품명이 없어 제외했습니다.';
            }
            if ($isLikelyData && $totalAmount > 0) $excluded++;
            continue;
        }
        if ($totalAmount <= 0) {
            if ($dateRaw !== '' || $quantityRaw !== '') $excluded++;
            continue;
        }
        if (trim((string)$dateRaw) === '') {
            $errors[] = $rowLabel . ' 날짜가 없어 제외했습니다.';
            $excluded++;
            continue;
        }
        if (trim((string)$vehicleNumber) === '') {
            $errors[] = $rowLabel . ' 차량번호가 없고 직전 차량번호도 없어 제외했습니다.';
            $excluded++;
            continue;
        }

        $date = cpms_company_fuel_normalize_date($dateRaw, $year, $month, $errors, $rowLabel);
        $resolve = cpms_company_fuel_resolve_display($pdo, $vehicleNumber);
        $item = array(
            'id' => cpms_company_fuel_new_item_id($year, $month, $areaName, $rowNumber),
            'category' => 'fuel',
            'category_name' => cpms_company_fuel_label(),
            'year' => sprintf('%04d', (int)$year),
            'month' => sprintf('%02d', (int)$month),
            'title' => cpms_company_fuel_label() . ' ' . sprintf('%04d-%02d', (int)$year, (int)$month),
            'vehicle_number' => trim((string)$vehicleNumber),
            'vehicle_number_normalized' => cpms_normalize_vehicle_number($vehicleNumber),
            'display_name' => isset($resolve['display_name']) ? (string)$resolve['display_name'] : trim((string)$vehicleNumber),
            'matched_type' => isset($resolve['matched_type']) ? (string)$resolve['matched_type'] : 'vehicle_number',
            'matched_employee_id' => isset($resolve['matched_employee_id']) ? (string)$resolve['matched_employee_id'] : '',
            'matched_employee_name' => isset($resolve['matched_employee_name']) ? (string)$resolve['matched_employee_name'] : '',
            'matched_company_vehicle_id' => isset($resolve['matched_company_vehicle_id']) ? (string)$resolve['matched_company_vehicle_id'] : '',
            'date' => $date,
            'occurred_at' => $date,
            'product_name' => $product,
            'unit' => $unit,
            'quantity' => cpms_company_fuel_money_value($quantityRaw),
            'unit_price' => cpms_company_fuel_money_value($unitPriceRaw),
            'supply_amount' => cpms_company_fuel_money_value($supplyRaw),
            'vat' => cpms_company_fuel_money_value($vatRaw),
            'total_amount' => $totalAmount,
            'amount' => $totalAmount,
            'source_area' => $areaName,
            'source_row' => $rowNumber,
        );
        $items[] = $item;
    }

    return array('items' => $items, 'errors' => $errors, 'excluded_count' => $excluded);
}}

if (!function_exists('cpms_company_fuel_sort_items')) {
function cpms_company_fuel_sort_items($a, $b) {
    $ad = isset($a['date']) ? (string)$a['date'] : '';
    $bd = isset($b['date']) ? (string)$b['date'] : '';
    if ($ad !== $bd) return ($ad < $bd) ? -1 : 1;
    $an = isset($a['display_name']) ? (string)$a['display_name'] : '';
    $bn = isset($b['display_name']) ? (string)$b['display_name'] : '';
    if ($an === $bn) return 0;
    return ($an < $bn) ? -1 : 1;
}}

if (!function_exists('cpms_company_fuel_summary_from_items')) {
function cpms_company_fuel_summary_from_items($items) {
    if (!is_array($items)) $items = array();
    $vehicleMap = array();
    $employeeMatchMap = array();
    $unmatchedMap = array();
    $totalSupply = 0.0;
    $totalVat = 0.0;
    $totalAmount = 0.0;
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $totalSupply += isset($item['supply_amount']) ? (float)$item['supply_amount'] : 0.0;
        $totalVat += isset($item['vat']) ? (float)$item['vat'] : 0.0;
        $totalAmount += isset($item['total_amount']) ? (float)$item['total_amount'] : 0.0;
        $norm = isset($item['vehicle_number_normalized']) ? (string)$item['vehicle_number_normalized'] : cpms_normalize_vehicle_number(isset($item['vehicle_number']) ? $item['vehicle_number'] : '');
        if ($norm !== '') $vehicleMap[$norm] = isset($item['vehicle_number']) ? (string)$item['vehicle_number'] : $norm;
        $matchedType = isset($item['matched_type']) ? (string)$item['matched_type'] : '';
        if ($matchedType === 'employee' && $norm !== '') $employeeMatchMap[$norm] = true;
        if ($matchedType !== 'employee' && $matchedType !== 'company_vehicle' && $norm !== '') $unmatchedMap[$norm] = isset($item['vehicle_number']) ? (string)$item['vehicle_number'] : $norm;
    }
    return array(
        'total_supply_amount' => round($totalSupply, 2),
        'total_vat' => round($totalVat, 2),
        'total_amount' => round($totalAmount, 2),
        'row_count' => count($items),
        'vehicle_count' => count($vehicleMap),
        'employee_matched_vehicle_count' => count($employeeMatchMap),
        'unmatched_vehicle_count' => count($unmatchedMap),
        'unmatched_vehicle_numbers' => array_values($unmatchedMap),
    );
}}

if (!function_exists('cpms_company_fuel_parse_xlsx')) {
function cpms_company_fuel_parse_xlsx($path, $year, $month, $pdo) {
    $ym = cpms_company_fuel_normalize_year_month($year, $month);
    $read = cpms_company_fuel_xlsx_rows($path, '마감', 3000);
    if (empty($read['ok'])) return $read;
    $records = isset($read['rows']) && is_array($read['rows']) ? $read['rows'] : array();
    $left = cpms_company_fuel_detect_table($records, 0, 8);
    $right = cpms_company_fuel_detect_table($records, 10, 18);
    $leftParsed = cpms_company_fuel_parse_area($records, $left, $ym['year'], $ym['month'], 'A:I', $pdo);
    $rightParsed = cpms_company_fuel_parse_area($records, $right, $ym['year'], $ym['month'], 'K:S', $pdo);
    $items = array_merge(isset($leftParsed['items']) ? $leftParsed['items'] : array(), isset($rightParsed['items']) ? $rightParsed['items'] : array());
    usort($items, 'cpms_company_fuel_sort_items');
    $summary = cpms_company_fuel_summary_from_items($items);
    $errors = array_merge(isset($leftParsed['errors']) ? $leftParsed['errors'] : array(), isset($rightParsed['errors']) ? $rightParsed['errors'] : array());
    $excluded = (isset($leftParsed['excluded_count']) ? (int)$leftParsed['excluded_count'] : 0) + (isset($rightParsed['excluded_count']) ? (int)$rightParsed['excluded_count'] : 0);

    if (count($items) === 0) {
        return array('ok' => false, 'message' => '저장 가능한 주유비 거래 행을 찾지 못했습니다.', 'items' => array(), 'errors' => $errors);
    }

    return array_merge(array(
        'ok' => true,
        'message' => '주유비 엑셀 파싱이 완료되었습니다.',
        'sheet_name' => isset($read['sheet_name']) ? (string)$read['sheet_name'] : '',
        'source_row_count' => isset($read['source_row_count']) ? (int)$read['source_row_count'] : count($records),
        'excluded_count' => $excluded,
        'items' => $items,
        'errors' => $errors,
        'left_header_row' => isset($left['header_index']) && (int)$left['header_index'] >= 0 && isset($records[(int)$left['header_index']]['row_number']) ? (int)$records[(int)$left['header_index']]['row_number'] : 0,
        'right_header_row' => isset($right['header_index']) && (int)$right['header_index'] >= 0 && isset($records[(int)$right['header_index']]['row_number']) ? (int)$records[(int)$right['header_index']]['row_number'] : 0,
    ), $summary);
}}

if (!function_exists('cpms_company_fuel_create_preview')) {
function cpms_company_fuel_create_preview($year, $month, $file, $user, $pdo) {
    $ym = cpms_company_fuel_normalize_year_month($year, $month);
    if (!is_array($file) || !isset($file['error']) || (int)$file['error'] === UPLOAD_ERR_NO_FILE) {
        return array('ok' => false, 'message' => '주유비 엑셀 파일을 선택해주세요.');
    }
    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        return array('ok' => false, 'message' => '파일 업로드 오류가 발생했습니다. 코드: ' . (int)$file['error']);
    }
    $originalName = isset($file['name']) ? trim((string)$file['name']) : 'fuel.xlsx';
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext !== 'xlsx') return array('ok' => false, 'message' => '.xlsx 파일만 업로드할 수 있습니다.');
    $tmpName = isset($file['tmp_name']) ? trim((string)$file['tmp_name']) : '';
    if ($tmpName === '' || !is_file($tmpName)) return array('ok' => false, 'message' => '업로드 임시 파일을 찾을 수 없습니다.');

    $parsed = cpms_company_fuel_parse_xlsx($tmpName, $ym['year'], $ym['month'], $pdo);
    if (empty($parsed['ok'])) return $parsed;

    $token = cpms_company_fuel_new_token();
    $tmpDir = cpms_company_fuel_tmp_root();
    if (!cpms_company_fuel_ensure_dir($tmpDir)) return array('ok' => false, 'message' => '주유비 업로드 임시 폴더를 만들 수 없습니다.');
    $localPath = rtrim($tmpDir, '/\\') . '/' . $token . '.xlsx';
    $moved = false;
    if (function_exists('move_uploaded_file')) $moved = @move_uploaded_file($tmpName, $localPath);
    if (!$moved) $moved = @copy($tmpName, $localPath);
    if (!$moved) return array('ok' => false, 'message' => '업로드 파일을 임시 보관하지 못했습니다.');

    if (!isset($_SESSION['_company_fuel_preview']) || !is_array($_SESSION['_company_fuel_preview'])) $_SESSION['_company_fuel_preview'] = array();
    $_SESSION['_company_fuel_preview'][$token] = array(
        'token' => $token,
        'created_at' => time(),
        'year' => $ym['year'],
        'month' => $ym['month'],
        'uploaded_original_name' => $originalName,
        'temp_path' => $localPath,
        'uploaded_by' => cpms_company_fuel_user_label($user),
        'parsed' => $parsed,
    );

    return array('ok' => true, 'message' => '주유비 업로드 미리보기가 생성되었습니다.', 'token' => $token, 'year' => $ym['year'], 'month' => $ym['month'], 'preview' => $parsed);
}}

if (!function_exists('cpms_company_fuel_get_preview')) {
function cpms_company_fuel_get_preview($token) {
    $token = trim((string)$token);
    if ($token === '' || !isset($_SESSION['_company_fuel_preview'][$token]) || !is_array($_SESSION['_company_fuel_preview'][$token])) return null;
    $preview = $_SESSION['_company_fuel_preview'][$token];
    if (!isset($preview['created_at']) || (time() - (int)$preview['created_at']) > 7200) {
        if (isset($preview['temp_path']) && is_file($preview['temp_path'])) @unlink($preview['temp_path']);
        unset($_SESSION['_company_fuel_preview'][$token]);
        return null;
    }
    return $preview;
}}

if (!function_exists('cpms_company_fuel_clear_preview')) {
function cpms_company_fuel_clear_preview($token) {
    $preview = cpms_company_fuel_get_preview($token);
    if (is_array($preview) && isset($preview['temp_path']) && is_file($preview['temp_path'])) @unlink($preview['temp_path']);
    if (isset($_SESSION['_company_fuel_preview'][$token])) unset($_SESSION['_company_fuel_preview'][$token]);
}}

if (!function_exists('cpms_company_fuel_load_month')) {
function cpms_company_fuel_load_month($year, $month) {
    $paths = cpms_company_fuel_month_file_candidates($year, $month);
    foreach ($paths as $path) {
        if (!is_file($path)) continue;
        $data = cpms_company_fuel_read_json($path);
        if (is_array($data)) return $data;
    }
    if (function_exists('cpms_archive_load_detail')) {
        $archive = cpms_archive_load_detail($year, 'fuel', array('category' => 'fuel', 'month' => $month));
        if (!empty($archive['ok']) && isset($archive['items']) && is_array($archive['items'])) {
            $summary = cpms_company_fuel_summary_from_items($archive['items']);
            return array_merge(array(
                'year' => sprintf('%04d', (int)$year),
                'month' => sprintf('%02d', (int)$month),
                'category' => 'fuel',
                'category_name' => cpms_company_fuel_label(),
                'archive_source' => true,
                'archive_id' => isset($archive['archive']['archive_id']) ? (string)$archive['archive']['archive_id'] : '',
                'items' => $archive['items'],
            ), $summary);
        }
    }
    return null;
}}

if (!function_exists('cpms_company_fuel_backup_existing')) {
function cpms_company_fuel_backup_existing($year, $month) {
    $old = null;
    $paths = cpms_company_fuel_month_file_candidates($year, $month);
    foreach ($paths as $file) {
        if (!is_file($file)) continue;
        $old = cpms_company_fuel_read_json($file);
        if (is_array($old)) break;
    }
    if (!is_array($old)) return true;
    $dir = cpms_company_fuel_history_dir($year, $month);
    if (!cpms_company_fuel_ensure_dir($dir)) {
        cpms_company_fuel_set_last_write_error('history 폴더를 만들 수 없습니다: ' . $dir);
        return false;
    }
    $backup = rtrim($dir, '/\\') . '/' . date('Ymd_His') . '_previous.json';
    return cpms_company_fuel_write_json($backup, $old);
}}

if (!function_exists('cpms_company_fuel_append_log')) {
function cpms_company_fuel_append_log($year, $month, $entry) {
    if (!is_array($entry)) $entry = array();
    $file = cpms_company_fuel_logs_file($year, $month);
    $logs = cpms_company_fuel_read_json($file);
    if (!is_array($logs)) $logs = array();
    $logs[] = $entry;
    return cpms_company_fuel_write_json($file, $logs);
}}

if (!function_exists('cpms_company_fuel_build_drive_name')) {
function cpms_company_fuel_build_drive_name($year, $month, $originalName, $user) {
    $ym = cpms_company_fuel_normalize_year_month($year, $month);
    $name = date('Y-m-d') . '_총관리비_주유비_' . $ym['year'] . $ym['month'] . '_' . cpms_company_fuel_user_label($user) . '_' . $originalName;
    return cpms_drive_sanitize_file_name($name, 180);
}}

if (!function_exists('cpms_company_fuel_confirm_preview')) {
function cpms_company_fuel_confirm_preview($token, $user) {
    $preview = cpms_company_fuel_get_preview($token);
    if (!is_array($preview)) return array('ok' => false, 'message' => '확정할 주유비 미리보기를 찾을 수 없습니다.');
    $year = isset($preview['year']) ? (string)$preview['year'] : date('Y');
    $month = isset($preview['month']) ? (string)$preview['month'] : date('m');
    $parsed = isset($preview['parsed']) && is_array($preview['parsed']) ? $preview['parsed'] : array();
    $items = isset($parsed['items']) && is_array($parsed['items']) ? $parsed['items'] : array();
    if (count($items) === 0) return array('ok' => false, 'message' => '저장할 주유비 거래가 없습니다.');
    $tempPath = isset($preview['temp_path']) ? (string)$preview['temp_path'] : '';
    $originalName = isset($preview['uploaded_original_name']) ? (string)$preview['uploaded_original_name'] : 'fuel.xlsx';
    $hasOriginalFile = ($tempPath !== '' && is_file($tempPath));

    $context = array(
        'user' => $user,
        'uploaded_by' => $user,
        'section' => 'company_overhead_fuel',
        'document_type' => cpms_company_fuel_label(),
        'document_year' => $year,
        'document_month' => $month,
        'original_name' => $originalName,
    );
    $driveRecord = array();
    $driveStatus = 'not_attempted';
    $driveError = '';

    $summary = cpms_company_fuel_summary_from_items($items);
    $version = array_merge(array(
        'year' => $year,
        'month' => $month,
        'category' => 'fuel',
        'category_name' => cpms_company_fuel_label(),
        'uploaded_original_name' => $originalName,
        'uploaded_drive_file_id' => isset($driveRecord['drive_file_id']) ? $driveRecord['drive_file_id'] : '',
        'uploaded_drive_folder_id' => isset($driveRecord['drive_folder_id']) ? $driveRecord['drive_folder_id'] : '',
        'uploaded_drive_web_view_link' => isset($driveRecord['drive_web_view_link']) ? $driveRecord['drive_web_view_link'] : '',
        'uploaded_stored_name' => isset($driveRecord['stored_name']) ? $driveRecord['stored_name'] : '',
        'uploaded_drive_status' => $driveStatus,
        'uploaded_drive_error' => $driveError,
        'uploaded_at' => date('Y-m-d H:i:s'),
        'uploaded_by' => cpms_company_fuel_user_label($user),
        'sheet_name' => isset($parsed['sheet_name']) ? (string)$parsed['sheet_name'] : '',
        'source_row_count' => isset($parsed['source_row_count']) ? (int)$parsed['source_row_count'] : 0,
        'excluded_count' => isset($parsed['excluded_count']) ? (int)$parsed['excluded_count'] : 0,
        'parse_errors' => isset($parsed['errors']) && is_array($parsed['errors']) ? $parsed['errors'] : array(),
        'items' => $items,
    ), $summary);

    if (!cpms_company_fuel_backup_existing($year, $month)) {
        $writeError = cpms_company_fuel_last_write_error();
        return array('ok' => false, 'message' => '기존 주유비 데이터 백업에 실패했습니다.' . ($writeError !== '' ? ' (' . $writeError . ')' : ''));
    }

    $saved = cpms_company_fuel_write_json(cpms_company_fuel_month_file($year, $month), $version);
    if (!$saved) {
        $writeError2 = cpms_company_fuel_last_write_error();
        return array('ok' => false, 'message' => '주유비 데이터를 저장하지 못했습니다.' . ($writeError2 !== '' ? ' (' . $writeError2 . ')' : ''));
    }

    $driveStatus = 'failed';
    if (!$hasOriginalFile) {
        $driveError = '원본 주유비 엑셀 임시 파일을 찾을 수 없어 Drive 업로드를 건너뛰었습니다.';
    } else {
        try {
            $target = cpms_company_overhead_drive_ensure_month_subfolder('fuel', cpms_company_fuel_label(), $year, $month, cpms_company_fuel_original_folder_name(), $context);
            if (empty($target['ok'])) {
                $driveError = isset($target['message']) ? (string)$target['message'] : 'Drive 폴더를 준비하지 못했습니다.';
            } else {
                $mimeType = cpms_drive_detect_mime_type($tempPath);
                $driveName = cpms_company_fuel_build_drive_name($year, $month, $originalName, $user);
                $context['target_folder_id'] = (string)$target['folder_id'];
                $context['drive_year_folder_id'] = (string)$target['year_folder_id'];
                $context['drive_type_folder_id'] = (string)$target['category_folder_id'];
                $context['drive_month_folder_id'] = (string)$target['month_folder_id'];
                $context['drive_fuel_original_folder_id'] = isset($target['sub_folder_id']) ? (string)$target['sub_folder_id'] : (string)$target['folder_id'];
                $upload = cpms_drive_upload_file($tempPath, $driveName, (string)$target['folder_id'], $mimeType, $context);
                if (empty($upload['ok']) || !isset($upload['file']) || !is_array($upload['file'])) {
                    $driveError = isset($upload['message']) ? (string)$upload['message'] : 'Drive 업로드에 실패했습니다.';
                } else {
                    $driveStatus = 'success';
                    $driveRecord = cpms_drive_build_file_record($upload['file'], $context);
                    $driveRecord['drive_year_folder_id'] = (string)$target['year_folder_id'];
                    $driveRecord['drive_type_folder_id'] = (string)$target['category_folder_id'];
                    $driveRecord['drive_month_folder_id'] = (string)$target['month_folder_id'];
                    $driveRecord['drive_fuel_original_folder_id'] = isset($target['sub_folder_id']) ? (string)$target['sub_folder_id'] : (string)$target['folder_id'];
                }
            }
        } catch (Exception $e) {
            $driveError = 'Drive 처리 중 오류: ' . $e->getMessage();
        }
    }

    $version['uploaded_drive_file_id'] = isset($driveRecord['drive_file_id']) ? $driveRecord['drive_file_id'] : '';
    $version['uploaded_drive_folder_id'] = isset($driveRecord['drive_folder_id']) ? $driveRecord['drive_folder_id'] : '';
    $version['uploaded_drive_web_view_link'] = isset($driveRecord['drive_web_view_link']) ? $driveRecord['drive_web_view_link'] : '';
    $version['uploaded_stored_name'] = isset($driveRecord['stored_name']) ? $driveRecord['stored_name'] : '';
    $version['uploaded_drive_status'] = $driveStatus;
    $version['uploaded_drive_error'] = $driveError;

    if (!cpms_company_fuel_write_json(cpms_company_fuel_month_file($year, $month), $version)) {
        if (isset($version['uploaded_drive_file_id']) && trim((string)$version['uploaded_drive_file_id']) !== '') {
            cpms_drive_delete_file((string)$version['uploaded_drive_file_id'], array('user' => $user, 'section' => 'company_overhead_fuel', 'message' => 'Fuel JSON update failed after Drive upload.'));
        }
        $version['uploaded_drive_file_id'] = '';
        $version['uploaded_drive_folder_id'] = '';
        $version['uploaded_drive_web_view_link'] = '';
        $version['uploaded_stored_name'] = '';
        $version['uploaded_drive_status'] = 'failed';
        $version['uploaded_drive_error'] = trim($driveError . ' JSON Drive 정보 갱신 실패');
        cpms_company_fuel_write_json(cpms_company_fuel_month_file($year, $month), $version);
        $driveStatus = 'failed';
        $driveError = $version['uploaded_drive_error'];
    }

    cpms_company_fuel_append_log($year, $month, array(
        'uploaded_at' => date('Y-m-d H:i:s'),
        'uploaded_by' => cpms_company_fuel_user_label($user),
        'year' => $year,
        'month' => $month,
        'original_name' => $originalName,
        'source_row_count' => isset($version['source_row_count']) ? (int)$version['source_row_count'] : 0,
        'saved_row_count' => isset($version['row_count']) ? (int)$version['row_count'] : 0,
        'excluded_count' => isset($version['excluded_count']) ? (int)$version['excluded_count'] : 0,
        'total_amount' => isset($version['total_amount']) ? (float)$version['total_amount'] : 0.0,
        'employee_matched_vehicle_count' => isset($version['employee_matched_vehicle_count']) ? (int)$version['employee_matched_vehicle_count'] : 0,
        'unmatched_vehicle_count' => isset($version['unmatched_vehicle_count']) ? (int)$version['unmatched_vehicle_count'] : 0,
        'unmatched_vehicle_numbers' => isset($version['unmatched_vehicle_numbers']) ? $version['unmatched_vehicle_numbers'] : array(),
        'error_summary' => isset($version['parse_errors']) ? $version['parse_errors'] : array(),
        'drive_upload_status' => $driveStatus,
        'drive_upload_error' => $driveError,
        'drive_file_id' => isset($version['uploaded_drive_file_id']) ? (string)$version['uploaded_drive_file_id'] : '',
    ));

    cpms_company_fuel_clear_preview($token);
    if (isset($_SESSION['_company_profit_cache'])) unset($_SESSION['_company_profit_cache']);
    $message = '주유비 데이터가 저장되었습니다.';
    if ($driveStatus !== 'success') $message .= ' 다만 원본 엑셀 Drive 저장은 실패했습니다. 관리자 Drive 점검을 확인해주세요.';
    return array('ok' => true, 'message' => $message, 'drive_status' => $driveStatus, 'version' => $version);
}}

if (!function_exists('cpms_company_fuel_delete_month')) {
function cpms_company_fuel_delete_month($year, $month, $user) {
    $file = '';
    foreach (cpms_company_fuel_month_file_candidates($year, $month) as $candidate) {
        if (is_file($candidate)) {
            $file = $candidate;
            break;
        }
    }
    if (!is_file($file)) return array('ok' => false, 'message' => '삭제할 주유비 데이터가 없습니다.');
    if (!cpms_company_fuel_backup_existing($year, $month)) {
        $writeError = cpms_company_fuel_last_write_error();
        return array('ok' => false, 'message' => '삭제 전 주유비 데이터 백업에 실패했습니다.' . ($writeError !== '' ? ' (' . $writeError . ')' : ''));
    }
    if (!@unlink($file)) return array('ok' => false, 'message' => '주유비 데이터 파일을 삭제하지 못했습니다.');
    cpms_company_fuel_append_log($year, $month, array(
        'uploaded_at' => date('Y-m-d H:i:s'),
        'uploaded_by' => cpms_company_fuel_user_label($user),
        'year' => sprintf('%04d', (int)$year),
        'month' => sprintf('%02d', (int)$month),
        'action' => 'delete_month',
        'drive_upload_status' => 'not_applicable',
    ));
    if (isset($_SESSION['_company_profit_cache'])) unset($_SESSION['_company_profit_cache']);
    return array('ok' => true, 'message' => '선택 월 주유비 데이터가 삭제되었습니다. 기존 데이터는 history에 백업되었습니다.');
}}

if (!function_exists('cpms_company_fuel_text_contains')) {
function cpms_company_fuel_text_contains($haystack, $needle) {
    $haystack = (string)$haystack;
    $needle = (string)$needle;
    if ($needle === '') return true;
    if (function_exists('mb_strtolower')) {
        return (mb_strpos(mb_strtolower($haystack, 'UTF-8'), mb_strtolower($needle, 'UTF-8'), 0, 'UTF-8') !== false);
    }
    return (strpos(strtolower($haystack), strtolower($needle)) !== false);
}}

if (!function_exists('cpms_company_fuel_filter_items')) {
function cpms_company_fuel_filter_items($items, $filters) {
    if (!is_array($items)) $items = array();
    if (!is_array($filters)) $filters = array();
    $name = isset($filters['name']) ? trim((string)$filters['name']) : '';
    $vehicle = isset($filters['vehicle_number']) ? trim((string)$filters['vehicle_number']) : '';
    $vehicleNorm = cpms_normalize_vehicle_number($vehicle);
    $product = isset($filters['product_name']) ? trim((string)$filters['product_name']) : '';
    $matchedType = isset($filters['matched_type']) ? trim((string)$filters['matched_type']) : '';
    $q = isset($filters['q']) ? trim((string)$filters['q']) : '';
    $result = array();
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        if ($name !== '' && !cpms_company_fuel_text_contains(isset($item['display_name']) ? $item['display_name'] : '', $name)) continue;
        if ($vehicleNorm !== '') {
            $rowNorm = isset($item['vehicle_number_normalized']) ? (string)$item['vehicle_number_normalized'] : cpms_normalize_vehicle_number(isset($item['vehicle_number']) ? $item['vehicle_number'] : '');
            if (strpos($rowNorm, $vehicleNorm) === false) continue;
        }
        if ($product !== '' && !cpms_company_fuel_text_contains(isset($item['product_name']) ? $item['product_name'] : '', $product)) continue;
        if ($matchedType !== '' && (!isset($item['matched_type']) || (string)$item['matched_type'] !== $matchedType)) continue;
        if ($q !== '') {
            $haystack = '';
            foreach (array('display_name','vehicle_number','date','product_name','unit','matched_type') as $key) {
                if (isset($item[$key])) $haystack .= ' ' . (string)$item[$key];
            }
            if (!cpms_company_fuel_text_contains($haystack, $q)) continue;
        }
        $result[] = $item;
    }
    usort($result, 'cpms_company_fuel_sort_items');
    return $result;
}}
