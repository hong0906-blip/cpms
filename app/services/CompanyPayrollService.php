<?php
/**
 * Company payroll ledger version service.
 * PHP 5.6 compatible.
 */

require_once __DIR__ . '/GoogleDriveHelper.php';
require_once __DIR__ . '/CompanyOverheadDriveService.php';
require_once __DIR__ . '/DataArchiveSummaryService.php';

if (!function_exists('cpms_company_payroll_data_root')) {
function cpms_company_payroll_data_root() {
    $root = dirname(dirname(__DIR__));
    $dataRoot = $root . '/data/company_overhead';
    $storageRoot = function_exists('cpms_storage_root') ? cpms_storage_root() . '/company_overhead' : $root . '/storage/company_overhead';
    if (is_dir($dataRoot) && is_writable($dataRoot)) return $dataRoot;
    if (is_dir($storageRoot) || @mkdir($storageRoot, 0777, true)) return $storageRoot;
    return $dataRoot;
}}

if (!function_exists('cpms_company_payroll_versions_root')) {
function cpms_company_payroll_versions_root() {
    return cpms_company_payroll_data_root() . '/payroll_versions';
}}

if (!function_exists('cpms_company_payroll_sensitive_logs_root')) {
function cpms_company_payroll_sensitive_logs_root() {
    return cpms_company_payroll_data_root() . '/payroll_sensitive_logs';
}}

if (!function_exists('cpms_company_payroll_tmp_root')) {
function cpms_company_payroll_tmp_root() {
    $root = function_exists('cpms_storage_root') ? cpms_storage_root() : dirname(dirname(__DIR__)) . '/storage';
    return $root . '/tmp/company_payroll_preview';
}}

if (!function_exists('cpms_company_payroll_ensure_dir')) {
function cpms_company_payroll_ensure_dir($dir) {
    if (function_exists('cpms_ensure_dir')) return cpms_ensure_dir($dir);
    if (is_dir($dir)) return true;
    return @mkdir($dir, 0777, true);
}}

if (!function_exists('cpms_company_payroll_json_encode')) {
function cpms_company_payroll_json_encode($data) {
    $options = 0;
    if (defined('JSON_UNESCAPED_UNICODE')) $options = $options | JSON_UNESCAPED_UNICODE;
    if (defined('JSON_PRETTY_PRINT')) $options = $options | JSON_PRETTY_PRINT;
    if (defined('JSON_UNESCAPED_SLASHES')) $options = $options | JSON_UNESCAPED_SLASHES;
    return json_encode($data, $options);
}}

if (!function_exists('cpms_company_payroll_read_json')) {
function cpms_company_payroll_read_json($path) {
    if (!is_file($path)) return null;
    $txt = @file_get_contents($path);
    if ($txt === false || trim($txt) === '') return null;
    $data = @json_decode($txt, true);
    return is_array($data) ? $data : null;
}}

if (!function_exists('cpms_company_payroll_write_json')) {
function cpms_company_payroll_write_json($path, $data) {
    $dir = dirname($path);
    if (!cpms_company_payroll_ensure_dir($dir)) return false;
    return (@file_put_contents($path, cpms_company_payroll_json_encode($data), LOCK_EX) !== false);
}}

if (!function_exists('cpms_company_payroll_user_label')) {
function cpms_company_payroll_user_label($user) {
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

if (!function_exists('cpms_company_payroll_normalize_year_month')) {
function cpms_company_payroll_normalize_year_month($year, $month) {
    $y = (int)$year;
    $m = (int)$month;
    if ($y < 2000 || $y > 2100) $y = (int)date('Y');
    if ($m < 1 || $m > 12) $m = (int)date('m');
    return array('year' => sprintf('%04d', $y), 'month' => sprintf('%02d', $m), 'ym' => sprintf('%04d-%02d', $y, $m));
}}

if (!function_exists('cpms_company_payroll_money_value')) {
function cpms_company_payroll_money_value($value) {
    if (is_int($value) || is_float($value)) return (float)$value;
    $value = trim((string)$value);
    if ($value === '') return 0.0;
    $value = str_replace(',', '', $value);
    $value = preg_replace('/[^0-9.\-]/', '', $value);
    if ($value === '' || $value === '-' || !is_numeric($value)) return 0.0;
    return (float)$value;
}}

if (!function_exists('cpms_company_payroll_excel_serial_to_date')) {
function cpms_company_payroll_excel_serial_to_date($value) {
    if (!is_numeric($value)) return '';
    $days = (int)$value;
    if ($days <= 0) return '';
    $ts = strtotime('1899-12-30 +' . $days . ' days');
    return $ts === false ? '' : date('Y-m-d', $ts);
}}

if (!function_exists('cpms_company_payroll_normalize_date')) {
function cpms_company_payroll_normalize_date($value) {
    $value = trim((string)$value);
    if ($value === '') return '';
    if (is_numeric($value) && (float)$value > 20000 && (float)$value < 90000) {
        return cpms_company_payroll_excel_serial_to_date($value);
    }
    if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $m)) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    if (preg_match('/^(\d{4})[-\.\/년\s]*(\d{1,2})[-\.\/월\s]*(\d{1,2})/u', $value, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    }
    $ts = strtotime($value);
    if ($ts === false) return $value;
    return date('Y-m-d', $ts);
}}

if (!function_exists('cpms_company_payroll_mask_resident')) {
function cpms_company_payroll_mask_resident($resident) {
    $resident = trim((string)$resident);
    if ($resident === '') return '';
    $digits = preg_replace('/\D+/', '', $resident);
    if (strlen($digits) >= 13) {
        return substr($digits, 0, 6) . '-' . substr($digits, 6, 1) . '******';
    }
    if (strlen($digits) >= 6) {
        return substr($digits, 0, 6) . '-*******';
    }
    return '******-*******';
}}

if (!function_exists('cpms_company_payroll_mask_bank_account')) {
function cpms_company_payroll_mask_bank_account($account) {
    $account = trim((string)$account);
    if ($account === '') return '';
    $digits = preg_replace('/\D+/', '', $account);
    if ($digits === '') {
        $len = function_exists('mb_strlen') ? mb_strlen($account, 'UTF-8') : strlen($account);
        if ($len <= 4) return '****';
        if (function_exists('mb_substr')) return '****' . mb_substr($account, -4, 4, 'UTF-8');
        return '****' . substr($account, -4);
    }
    $last4 = substr($digits, -4);
    $first4 = strlen($digits) >= 8 ? substr($digits, 0, 4) : '';
    if ($first4 !== '' && strpos($account, '-') !== false) {
        return $first4 . '-****-' . $last4;
    }
    return '****' . $last4;
}}

if (!function_exists('cpms_company_payroll_secret_paths')) {
function cpms_company_payroll_secret_paths() {
    return array(
        '/home/cmbuild/www/cpms_private/keys/payroll_secret.key',
        '/home/cmbuild/www/cpms_private/payroll_secret.key',
    );
}}

if (!function_exists('cpms_company_payroll_secret_key_info')) {
function cpms_company_payroll_secret_key_info() {
    $paths = cpms_company_payroll_secret_paths();
    foreach ($paths as $path) {
        if (!is_file($path) || !is_readable($path)) continue;
        $key = trim((string)@file_get_contents($path));
        if ($key === '') continue;
        return array('ok' => true, 'key' => $key, 'path' => $path, 'message' => 'Payroll secret key loaded.');
    }
    return array('ok' => false, 'key' => '', 'path' => '', 'message' => 'Payroll secret key is not configured.');
}}

if (!function_exists('cpms_company_payroll_encrypt_resident')) {
function cpms_company_payroll_encrypt_resident($resident) {
    $resident = trim((string)$resident);
    if ($resident === '') return '';
    if (!function_exists('openssl_encrypt') || !function_exists('openssl_random_pseudo_bytes')) return '';
    $info = cpms_company_payroll_secret_key_info();
    if (empty($info['ok'])) return '';
    $iv = openssl_random_pseudo_bytes(16);
    if ($iv === false || strlen($iv) !== 16) return '';
    $key = hash('sha256', (string)$info['key'], true);
    $flags = defined('OPENSSL_RAW_DATA') ? OPENSSL_RAW_DATA : 1;
    $cipher = openssl_encrypt($resident, 'AES-256-CBC', $key, $flags, $iv);
    if ($cipher === false || $cipher === '') return '';
    return 'v1:' . base64_encode($iv) . ':' . base64_encode($cipher);
}}

if (!function_exists('cpms_company_payroll_decrypt_resident')) {
function cpms_company_payroll_decrypt_resident($encrypted) {
    $encrypted = trim((string)$encrypted);
    if ($encrypted === '' || !function_exists('openssl_decrypt')) return '';
    $parts = explode(':', $encrypted);
    if (count($parts) !== 3 || $parts[0] !== 'v1') return '';
    $info = cpms_company_payroll_secret_key_info();
    if (empty($info['ok'])) return '';
    $iv = base64_decode($parts[1], true);
    $cipher = base64_decode($parts[2], true);
    if ($iv === false || $cipher === false || strlen($iv) !== 16) return '';
    $key = hash('sha256', (string)$info['key'], true);
    $flags = defined('OPENSSL_RAW_DATA') ? OPENSSL_RAW_DATA : 1;
    $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, $flags, $iv);
    return $plain === false ? '' : (string)$plain;
}}

if (!function_exists('cpms_company_payroll_new_token')) {
function cpms_company_payroll_new_token() {
    if (function_exists('openssl_random_pseudo_bytes')) {
        $bytes = openssl_random_pseudo_bytes(16);
        if ($bytes !== false) return bin2hex($bytes);
    }
    return md5(uniqid('', true) . mt_rand());
}}

if (!function_exists('cpms_company_payroll_col_ref_to_index')) {
function cpms_company_payroll_col_ref_to_index($cellRef) {
    $letters = preg_replace('/[^A-Z]/', '', strtoupper((string)$cellRef));
    if ($letters === '') return 0;
    $num = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $num = $num * 26 + (ord($letters[$i]) - 64);
    }
    return (int)$num;
}}

if (!function_exists('cpms_company_payroll_xlsx_text_contains')) {
function cpms_company_payroll_xlsx_text_contains($haystack, $needle) {
    $haystack = (string)$haystack;
    $needle = (string)$needle;
    if ($needle === '') return false;
    if (function_exists('mb_strpos')) return mb_strpos($haystack, $needle, 0, 'UTF-8') !== false;
    return strpos($haystack, $needle) !== false;
}}

if (!function_exists('cpms_company_payroll_xlsx_read_shared_strings')) {
function cpms_company_payroll_xlsx_read_shared_strings($zip) {
    $shared = array();
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($xml === false) return $shared;
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

if (!function_exists('cpms_company_payroll_xlsx_first_sheet')) {
function cpms_company_payroll_xlsx_first_sheet($zip) {
    $result = array('name' => '', 'path' => 'xl/worksheets/sheet1.xml');
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbookXml === false || $relsXml === false) return $result;
    $workbook = @simplexml_load_string($workbookXml);
    $rels = @simplexml_load_string($relsXml);
    if (!$workbook || !$rels || !isset($workbook->sheets->sheet)) return $result;
    $sheet = null;
    foreach ($workbook->sheets->sheet as $one) {
        $sheet = $one;
        break;
    }
    if (!$sheet) return $result;
    $result['name'] = (string)$sheet['name'];
    $attrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
    $rid = isset($attrs['id']) ? (string)$attrs['id'] : '';
    if ($rid === '') return $result;
    foreach ($rels->Relationship as $rel) {
        if ((string)$rel['Id'] !== $rid) continue;
        $target = (string)$rel['Target'];
        if ($target !== '') {
            $target = str_replace('\\', '/', $target);
            if (strpos($target, '/') === 0) $target = substr($target, 1);
            if (strpos($target, 'xl/') !== 0) $target = 'xl/' . $target;
            $result['path'] = $target;
        }
        break;
    }
    return $result;
}}

if (!function_exists('cpms_company_payroll_xlsx_cell_value')) {
function cpms_company_payroll_xlsx_cell_value($cell, $sharedStrings) {
    $type = isset($cell['t']) ? (string)$cell['t'] : '';
    if ($type === 's') {
        $idx = isset($cell->v) ? (int)$cell->v : -1;
        return ($idx >= 0 && isset($sharedStrings[$idx])) ? trim((string)$sharedStrings[$idx]) : '';
    }
    if ($type === 'inlineStr') {
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
    if (isset($cell->v)) return trim((string)$cell->v);
    return '';
}}

if (!function_exists('cpms_company_payroll_xlsx_rows')) {
function cpms_company_payroll_xlsx_rows($path, $maxRows) {
    $result = array('ok' => false, 'rows' => array(), 'sheet_name' => '', 'message' => '');
    if (!is_file($path)) {
        $result['message'] = '파일을 찾을 수 없습니다.';
        return $result;
    }
    if (!class_exists('ZipArchive')) {
        $result['message'] = '서버에 ZipArchive 확장 모듈이 없습니다.';
        return $result;
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        $result['message'] = '엑셀 파일을 열 수 없습니다.';
        return $result;
    }
    $shared = cpms_company_payroll_xlsx_read_shared_strings($zip);
    $sheetInfo = cpms_company_payroll_xlsx_first_sheet($zip);
    $sheetXml = $zip->getFromName($sheetInfo['path']);
    if ($sheetXml === false) $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        $zip->close();
        $result['message'] = '엑셀 시트를 찾을 수 없습니다.';
        return $result;
    }
    $sheet = @simplexml_load_string($sheetXml);
    if (!$sheet || !isset($sheet->sheetData)) {
        $zip->close();
        $result['message'] = '엑셀 시트 파싱에 실패했습니다.';
        return $result;
    }
    $rows = array();
    $count = 0;
    foreach ($sheet->sheetData->row as $rowNode) {
        $count++;
        if ((int)$maxRows > 0 && $count > (int)$maxRows) break;
        $cells = array();
        $maxCol = 0;
        if (isset($rowNode->c)) {
            foreach ($rowNode->c as $cell) {
                $ref = isset($cell['r']) ? (string)$cell['r'] : '';
                $col = cpms_company_payroll_col_ref_to_index($ref);
                if ($col <= 0) continue;
                $cells[$col] = cpms_company_payroll_xlsx_cell_value($cell, $shared);
                if ($col > $maxCol) $maxCol = $col;
            }
        }
        $row = array();
        for ($i = 1; $i <= $maxCol; $i++) {
            $row[] = isset($cells[$i]) ? $cells[$i] : '';
        }
        $rows[] = $row;
    }
    $zip->close();
    $result['ok'] = true;
    $result['rows'] = $rows;
    $result['sheet_name'] = $sheetInfo['name'];
    $result['message'] = '엑셀 파일을 읽었습니다.';
    return $result;
}}

if (!function_exists('cpms_company_payroll_fallback_column_map')) {
function cpms_company_payroll_fallback_column_map() {
    return array(
        'no' => 0,
        'status' => 1,
        'after_tax_type' => 2,
        'tax_reduction' => 3,
        'name' => 4,
        'position' => 5,
        'resident_number' => 6,
        'bank_name' => 7,
        'bank_account' => 8,
        'birth_date' => 9,
        'joined_at' => 10,
        'base_pay' => 11,
        'overtime_pay' => 12,
        'annual_leave_pay' => 13,
        'employee_pension' => 14,
        'meal_allowance' => 15,
        'vehicle_allowance' => 16,
        'research_allowance' => 17,
        'childcare_allowance' => 18,
        'annual_leave_pay_2' => 19,
        'position_allowance' => 20,
        'absence_deduction' => 21,
        'advance_pay' => 22,
        'gross_pay' => 23,
        'income_tax' => 24,
        'local_income_tax' => 25,
        'employment_insurance' => 26,
        'national_pension' => 27,
        'health_insurance' => 28,
        'long_term_care' => 29,
        'income_tax_adjustment' => 30,
        'local_tax_adjustment' => 31,
        'health_insurance_adjustment' => 32,
        'long_term_care_adjustment' => 33,
        'other_deduction' => 34,
        'total_deduction' => 35,
        'net_pay' => 36,
        'etc' => 37,
        'income_tax_etc' => 38,
        'local_income_tax_etc' => 39,
        'social_insurance_etc' => 40,
        'annual_salary_before_tax' => 41,
        'annual_salary_after_tax' => 42,
        'resident_reference' => 43,
    );
}}

if (!function_exists('cpms_company_payroll_header_column_map')) {
function cpms_company_payroll_header_column_map($rows) {
    $map = array();
    $fallback = cpms_company_payroll_fallback_column_map();
    $maxCol = 0;
    for ($r = 0; $r < count($rows) && $r < 8; $r++) {
        if (is_array($rows[$r]) && count($rows[$r]) > $maxCol) $maxCol = count($rows[$r]);
    }
    $annualSeen = 0;
    for ($col = 0; $col < $maxCol; $col++) {
        $parts = array();
        for ($r2 = 0; $r2 < count($rows) && $r2 < 8; $r2++) {
            $cell = isset($rows[$r2][$col]) ? trim((string)$rows[$r2][$col]) : '';
            if ($cell !== '') $parts[] = $cell;
        }
        $text = implode(' ', $parts);
        if ($text === '') continue;
        if (cpms_company_payroll_xlsx_text_contains($text, '번호') && !isset($map['no'])) $map['no'] = $col;
        if (cpms_company_payroll_xlsx_text_contains($text, '재직') && !isset($map['status'])) $map['status'] = $col;
        if (cpms_company_payroll_xlsx_text_contains($text, '세후') && !isset($map['after_tax_type'])) $map['after_tax_type'] = $col;
        if (cpms_company_payroll_xlsx_text_contains($text, '소득세감면') && !isset($map['tax_reduction'])) $map['tax_reduction'] = $col;
        if (cpms_company_payroll_xlsx_text_contains($text, '사원명') && !isset($map['name'])) $map['name'] = $col;
        if (cpms_company_payroll_xlsx_text_contains($text, '직급') && !isset($map['position'])) $map['position'] = $col;
        if (cpms_company_payroll_xlsx_text_contains($text, '주민번호') && !isset($map['resident_number'])) $map['resident_number'] = $col;
        if ((cpms_company_payroll_xlsx_text_contains($text, '은행명') || cpms_company_payroll_xlsx_text_contains($text, '은행')) && !isset($map['bank_name'])) $map['bank_name'] = $col;
        if ((cpms_company_payroll_xlsx_text_contains($text, '계좌번호') || cpms_company_payroll_xlsx_text_contains($text, '계좌')) && !isset($map['bank_account'])) $map['bank_account'] = $col;
        if (cpms_company_payroll_xlsx_text_contains($text, '생년월일') && !isset($map['birth_date'])) $map['birth_date'] = $col;
        if (cpms_company_payroll_xlsx_text_contains($text, '입사일') && !isset($map['joined_at'])) $map['joined_at'] = $col;
        if (cpms_company_payroll_xlsx_text_contains($text, '기본급')) $map['base_pay'] = $col;
        if (cpms_company_payroll_xlsx_text_contains($text, '연장수당')) $map['overtime_pay'] = $col;
        if (cpms_company_payroll_xlsx_text_contains($text, '연차수당')) {
            $annualSeen++;
            if ($annualSeen === 1) $map['annual_leave_pay'] = $col;
            else $map['annual_leave_pay_2'] = $col;
        }
        if (cpms_company_payroll_xlsx_text_contains($text, '사원연금')) $map['employee_pension'] = $col;
        if (cpms_company_payroll_xlsx_text_contains($text, '식대')) $map['meal_allowance'] = $col;
        if (cpms_company_payroll_xlsx_text_contains($text, '차량유지비')) $map['vehicle_allowance'] = $col;
        if (cpms_company_payroll_xlsx_text_contains($text, '연구수당')) $map['research_allowance'] = $col;
        if (cpms_company_payroll_xlsx_text_contains($text, '육아수당')) $map['childcare_allowance'] = $col;
        if (cpms_company_payroll_xlsx_text_contains($text, '직책수당')) $map['position_allowance'] = $col;
        if (cpms_company_payroll_xlsx_text_contains($text, '결근')) $map['absence_deduction'] = $col;
        if (cpms_company_payroll_xlsx_text_contains($text, '선급급여')) $map['advance_pay'] = $col;
        if (cpms_company_payroll_xlsx_text_contains($text, '지급합계')) $map['gross_pay'] = $col;
        if (cpms_company_payroll_xlsx_text_contains($text, '고용보험')) $map['employment_insurance'] = $col;
        if (cpms_company_payroll_xlsx_text_contains($text, '국민연금')) $map['national_pension'] = $col;
        if (cpms_company_payroll_xlsx_text_contains($text, '건강보험 정산') || cpms_company_payroll_xlsx_text_contains($text, '건강보험정산')) $map['health_insurance_adjustment'] = $col;
        else if (cpms_company_payroll_xlsx_text_contains($text, '건강보험')) $map['health_insurance'] = $col;
        if (cpms_company_payroll_xlsx_text_contains($text, '노인장기요양')) $map['long_term_care'] = $col;
        if (cpms_company_payroll_xlsx_text_contains($text, '소득세정산')) $map['income_tax_adjustment'] = $col;
        else if (cpms_company_payroll_xlsx_text_contains($text, '소득세') && !isset($map['income_tax'])) $map['income_tax'] = $col;
        if (cpms_company_payroll_xlsx_text_contains($text, '지방세정산')) $map['local_tax_adjustment'] = $col;
        else if (cpms_company_payroll_xlsx_text_contains($text, '지방소득세') && !isset($map['local_income_tax'])) $map['local_income_tax'] = $col;
        if (cpms_company_payroll_xlsx_text_contains($text, '장기요양 정산') || cpms_company_payroll_xlsx_text_contains($text, '장기요양정산')) $map['long_term_care_adjustment'] = $col;
        if (cpms_company_payroll_xlsx_text_contains($text, '기타공제')) $map['other_deduction'] = $col;
        if (cpms_company_payroll_xlsx_text_contains($text, '공제총액')) $map['total_deduction'] = $col;
        if (cpms_company_payroll_xlsx_text_contains($text, '차인지급액')) $map['net_pay'] = $col;
        if (cpms_company_payroll_xlsx_text_contains($text, '기타') && !isset($map['etc'])) $map['etc'] = $col;
        if (cpms_company_payroll_xlsx_text_contains($text, '세전 연봉') || cpms_company_payroll_xlsx_text_contains($text, '세전연봉')) $map['annual_salary_before_tax'] = $col;
        if (cpms_company_payroll_xlsx_text_contains($text, '세후 연봉') || cpms_company_payroll_xlsx_text_contains($text, '세후연봉')) $map['annual_salary_after_tax'] = $col;
    }
    foreach ($fallback as $key => $colIndex) {
        if (!isset($map[$key])) $map[$key] = $colIndex;
    }
    return $map;
}}

if (!function_exists('cpms_company_payroll_row_value')) {
function cpms_company_payroll_row_value($row, $map, $key) {
    if (!is_array($row) || !isset($map[$key])) return '';
    $idx = (int)$map[$key];
    return isset($row[$idx]) ? trim((string)$row[$idx]) : '';
}}

if (!function_exists('cpms_company_payroll_parse_xlsx')) {
function cpms_company_payroll_parse_xlsx($path) {
    $read = cpms_company_payroll_xlsx_rows($path, 1000);
    if (empty($read['ok'])) return array('ok' => false, 'message' => isset($read['message']) ? $read['message'] : '엑셀 파일을 읽지 못했습니다.', 'employees' => array());
    $rows = isset($read['rows']) && is_array($read['rows']) ? $read['rows'] : array();
    $map = cpms_company_payroll_header_column_map($rows);
    $employees = array();
    $seenKeys = array();
    $moneyKeys = array('base_pay','overtime_pay','annual_leave_pay','employee_pension','meal_allowance','vehicle_allowance','research_allowance','childcare_allowance','annual_leave_pay_2','position_allowance','absence_deduction','advance_pay','gross_pay','income_tax','local_income_tax','employment_insurance','national_pension','health_insurance','long_term_care','income_tax_adjustment','local_tax_adjustment','health_insurance_adjustment','long_term_care_adjustment','other_deduction','total_deduction','net_pay','income_tax_etc','local_income_tax_etc','social_insurance_etc','annual_salary_before_tax','annual_salary_after_tax');
    $totalGross = 0.0;
    $totalDeduction = 0.0;
    $totalNet = 0.0;

    for ($i = 0; $i < count($rows); $i++) {
        $row = $rows[$i];
        $name = cpms_company_payroll_row_value($row, $map, 'name');
        if ($name === '') continue;
        if ($name === '사원명') continue;
        if (cpms_company_payroll_xlsx_text_contains($name, '총계') || cpms_company_payroll_xlsx_text_contains($name, '합계')) continue;

        $birth = cpms_company_payroll_normalize_date(cpms_company_payroll_row_value($row, $map, 'birth_date'));
        $joined = cpms_company_payroll_normalize_date(cpms_company_payroll_row_value($row, $map, 'joined_at'));
        $position = cpms_company_payroll_row_value($row, $map, 'position');
        $rawResident = cpms_company_payroll_row_value($row, $map, 'resident_number');
        if ($rawResident === '') $rawResident = cpms_company_payroll_row_value($row, $map, 'resident_reference');
        $baseKey = 'EMP-' . substr(sha1($name . '|' . $birth . '|' . $joined . '|' . $position), 0, 16);
        $employeeKey = $baseKey;
        $seq = 2;
        while (isset($seenKeys[$employeeKey])) {
            $employeeKey = $baseKey . '-' . $seq;
            $seq++;
        }
        $seenKeys[$employeeKey] = true;

        $employee = array(
            'employee_key' => $employeeKey,
            'employee_id' => '',
            'no' => cpms_company_payroll_row_value($row, $map, 'no'),
            'name' => $name,
            'status' => cpms_company_payroll_row_value($row, $map, 'status'),
            'after_tax_type' => cpms_company_payroll_row_value($row, $map, 'after_tax_type'),
            'tax_reduction' => cpms_company_payroll_row_value($row, $map, 'tax_reduction'),
            'department' => '',
            'position' => $position,
            'resident_masked' => cpms_company_payroll_mask_resident($rawResident),
            'resident_encrypted' => cpms_company_payroll_encrypt_resident($rawResident),
            'bank_name' => cpms_company_payroll_row_value($row, $map, 'bank_name'),
            'bank_account' => cpms_company_payroll_row_value($row, $map, 'bank_account'),
            'bank_account_masked' => cpms_company_payroll_mask_bank_account(cpms_company_payroll_row_value($row, $map, 'bank_account')),
            'birth_date' => $birth,
            'joined_at' => $joined,
            'etc' => cpms_company_payroll_row_value($row, $map, 'etc'),
        );
        foreach ($moneyKeys as $moneyKey) {
            $employee[$moneyKey] = cpms_company_payroll_money_value(cpms_company_payroll_row_value($row, $map, $moneyKey));
        }
        $totalGross += (float)$employee['gross_pay'];
        $totalDeduction += (float)$employee['total_deduction'];
        $totalNet += (float)$employee['net_pay'];
        $employees[] = $employee;
    }

    return array(
        'ok' => true,
        'message' => '급여대장 파싱이 완료되었습니다.',
        'sheet_name' => isset($read['sheet_name']) ? $read['sheet_name'] : '',
        'column_map' => $map,
        'employee_count' => count($employees),
        'total_gross_pay' => $totalGross,
        'total_deduction' => $totalDeduction,
        'total_net_pay' => $totalNet,
        'employees' => $employees,
    );
}}

if (!function_exists('cpms_company_payroll_public_employee')) {
function cpms_company_payroll_public_employee($employee, $includeBankAccount = false) {
    if (!is_array($employee)) return array();
    if (isset($employee['resident_encrypted'])) unset($employee['resident_encrypted']);
    $bankAccount = isset($employee['bank_account']) ? (string)$employee['bank_account'] : '';
    $employee['bank_account_masked'] = cpms_company_payroll_mask_bank_account($bankAccount);
    if (!$includeBankAccount && isset($employee['bank_account'])) unset($employee['bank_account']);
    return $employee;
}}

if (!function_exists('cpms_company_payroll_public_version')) {
function cpms_company_payroll_public_version($version) {
    if (!is_array($version)) return array();
    if (isset($version['employees']) && is_array($version['employees'])) {
        $public = array();
        foreach ($version['employees'] as $employee) {
            $public[] = cpms_company_payroll_public_employee($employee);
        }
        $version['employees'] = $public;
    }
    return $version;
}}

if (!function_exists('cpms_company_payroll_recalculate_employee_totals')) {
function cpms_company_payroll_recalculate_employee_totals($data, $employees) {
    if (!is_array($data)) $data = array();
    if (!is_array($employees)) $employees = array();
    $cleanEmployees = array();
    $totalGross = 0.0;
    $totalDeduction = 0.0;
    $totalNet = 0.0;
    foreach ($employees as $employee) {
        if (!is_array($employee)) continue;
        $cleanEmployees[] = $employee;
        $totalGross += isset($employee['gross_pay']) ? (float)$employee['gross_pay'] : 0.0;
        $totalDeduction += isset($employee['total_deduction']) ? (float)$employee['total_deduction'] : 0.0;
        $totalNet += isset($employee['net_pay']) ? (float)$employee['net_pay'] : 0.0;
    }
    $data['employees'] = array_values($cleanEmployees);
    $data['employee_count'] = count($cleanEmployees);
    $data['total_gross_pay'] = $totalGross;
    $data['total_deduction'] = $totalDeduction;
    $data['total_net_pay'] = $totalNet;
    return $data;
}}

if (!function_exists('cpms_company_payroll_filter_selected_employees')) {
function cpms_company_payroll_filter_selected_employees($parsed, $selectedEmployeeKeys) {
    if (!is_array($parsed)) $parsed = array();
    $employees = isset($parsed['employees']) && is_array($parsed['employees']) ? $parsed['employees'] : array();
    if ($selectedEmployeeKeys === null) {
        return cpms_company_payroll_recalculate_employee_totals($parsed, $employees);
    }
    if (!is_array($selectedEmployeeKeys)) $selectedEmployeeKeys = array();
    $selected = array();
    foreach ($selectedEmployeeKeys as $selectedKey) {
        $key = trim((string)$selectedKey);
        if ($key !== '') $selected[$key] = true;
    }
    $filtered = array();
    foreach ($employees as $employee) {
        if (!is_array($employee)) continue;
        $employeeKey = isset($employee['employee_key']) ? (string)$employee['employee_key'] : '';
        if ($employeeKey !== '' && isset($selected[$employeeKey])) $filtered[] = $employee;
    }
    return cpms_company_payroll_recalculate_employee_totals($parsed, $filtered);
}}

if (!function_exists('cpms_company_payroll_create_preview')) {
function cpms_company_payroll_create_preview($year, $month, $file, $user) {
    $ym = cpms_company_payroll_normalize_year_month($year, $month);
    if (!is_array($file) || !isset($file['error']) || (int)$file['error'] === UPLOAD_ERR_NO_FILE) {
        return array('ok' => false, 'message' => '급여대장 엑셀 파일을 선택해주세요.');
    }
    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        return array('ok' => false, 'message' => '파일 업로드 오류가 발생했습니다. 코드: ' . (int)$file['error']);
    }
    $originalName = isset($file['name']) ? trim((string)$file['name']) : 'payroll.xlsx';
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext !== 'xlsx') {
        return array('ok' => false, 'message' => '.xlsx 급여대장만 업로드할 수 있습니다.');
    }
    $tmpName = isset($file['tmp_name']) ? trim((string)$file['tmp_name']) : '';
    $parsed = cpms_company_payroll_parse_xlsx($tmpName);
    if (empty($parsed['ok'])) return $parsed;

    $token = cpms_company_payroll_new_token();
    $tmpDir = cpms_company_payroll_tmp_root();
    if (!cpms_company_payroll_ensure_dir($tmpDir)) return array('ok' => false, 'message' => '임시 저장 폴더를 만들지 못했습니다.');
    $localPath = rtrim($tmpDir, '/\\') . '/' . $token . '.xlsx';
    $moved = false;
    if (function_exists('move_uploaded_file')) $moved = @move_uploaded_file($tmpName, $localPath);
    if (!$moved) $moved = @copy($tmpName, $localPath);
    if (!$moved) return array('ok' => false, 'message' => '업로드 파일을 임시 보관하지 못했습니다.');

    if (!isset($_SESSION['_company_payroll_preview']) || !is_array($_SESSION['_company_payroll_preview'])) {
        $_SESSION['_company_payroll_preview'] = array();
    }
    $_SESSION['_company_payroll_preview'][$token] = array(
        'token' => $token,
        'created_at' => time(),
        'effective_year' => $ym['year'],
        'effective_month' => $ym['month'],
        'uploaded_original_name' => $originalName,
        'temp_path' => $localPath,
        'uploaded_by' => cpms_company_payroll_user_label($user),
        'parsed' => $parsed,
    );

    return array('ok' => true, 'message' => '미리보기가 생성되었습니다.', 'token' => $token, 'preview' => cpms_company_payroll_public_version($parsed), 'year' => $ym['year'], 'month' => $ym['month']);
}}

if (!function_exists('cpms_company_payroll_get_preview')) {
function cpms_company_payroll_get_preview($token) {
    $token = trim((string)$token);
    if ($token === '' || !isset($_SESSION['_company_payroll_preview'][$token]) || !is_array($_SESSION['_company_payroll_preview'][$token])) return null;
    $preview = $_SESSION['_company_payroll_preview'][$token];
    if (!isset($preview['created_at']) || (time() - (int)$preview['created_at']) > 7200) {
        if (isset($preview['temp_path']) && is_file($preview['temp_path'])) @unlink($preview['temp_path']);
        unset($_SESSION['_company_payroll_preview'][$token]);
        return null;
    }
    return $preview;
}}

if (!function_exists('cpms_company_payroll_clear_preview')) {
function cpms_company_payroll_clear_preview($token) {
    $preview = cpms_company_payroll_get_preview($token);
    if (is_array($preview) && isset($preview['temp_path']) && is_file($preview['temp_path'])) @unlink($preview['temp_path']);
    if (isset($_SESSION['_company_payroll_preview'][$token])) unset($_SESSION['_company_payroll_preview'][$token]);
}}

if (!function_exists('cpms_company_payroll_version_file')) {
function cpms_company_payroll_version_file($year, $month) {
    $ym = cpms_company_payroll_normalize_year_month($year, $month);
    return cpms_company_payroll_versions_root() . '/' . $ym['year'] . '/' . $ym['month'] . '.json';
}}

if (!function_exists('cpms_company_payroll_history_dir')) {
function cpms_company_payroll_history_dir($year, $month) {
    $ym = cpms_company_payroll_normalize_year_month($year, $month);
    return cpms_company_payroll_versions_root() . '/' . $ym['year'] . '/' . $ym['month'] . '_history';
}}

if (!function_exists('cpms_company_payroll_backup_existing_version')) {
function cpms_company_payroll_backup_existing_version($year, $month) {
    $file = cpms_company_payroll_version_file($year, $month);
    if (!is_file($file)) return true;
    $old = cpms_company_payroll_read_json($file);
    if (!is_array($old)) return true;
    $dir = cpms_company_payroll_history_dir($year, $month);
    if (!cpms_company_payroll_ensure_dir($dir)) return false;
    $versionId = isset($old['version_id']) ? preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)$old['version_id']) : 'previous';
    $backup = rtrim($dir, '/\\') . '/' . date('Ymd_His') . '_' . $versionId . '.json';
    return cpms_company_payroll_write_json($backup, $old);
}}

if (!function_exists('cpms_company_payroll_invalidate_statement_result')) {
function cpms_company_payroll_invalidate_statement_result($year, $month, $reason) {
    $ym = cpms_company_payroll_normalize_year_month($year, $month);
    $root = cpms_company_payroll_data_root() . '/payroll_statements';
    $file = $root . '/' . $ym['year'] . '/' . $ym['month'] . '.json';
    if (!is_file($file)) return true;
    $old = cpms_company_payroll_read_json($file);
    if (!is_array($old)) return @unlink($file);
    $dir = $root . '/' . $ym['year'] . '/' . $ym['month'] . '_history';
    if (!cpms_company_payroll_ensure_dir($dir)) return false;
    $old['invalidated_at'] = date('Y-m-d H:i:s');
    $old['invalidated_reason'] = (string)$reason;
    $backup = rtrim($dir, '/\\') . '/' . date('Ymd_His') . '_invalidated_payroll_statements.json';
    if (!cpms_company_payroll_write_json($backup, $old)) return false;
    return @unlink($file);
}}

if (!function_exists('cpms_company_payroll_build_drive_name')) {
function cpms_company_payroll_build_drive_name($year, $month, $originalName, $user) {
    $ym = cpms_company_payroll_normalize_year_month($year, $month);
    $name = date('Y-m-d') . '_총관리비_임직원월급_' . $ym['year'] . $ym['month'] . '_' . cpms_company_payroll_user_label($user) . '_' . date('His') . '_' . mt_rand(1000, 9999) . '_' . $originalName;
    return cpms_drive_sanitize_file_name($name, 180);
}}

if (!function_exists('cpms_company_payroll_confirm_preview')) {
function cpms_company_payroll_confirm_preview($token, $user, $selectedEmployeeKeys = null) {
    $preview = cpms_company_payroll_get_preview($token);
    if (!is_array($preview)) return array('ok' => false, 'message' => '확정할 미리보기 데이터를 찾지 못했습니다.');
    $year = isset($preview['effective_year']) ? (string)$preview['effective_year'] : date('Y');
    $month = isset($preview['effective_month']) ? (string)$preview['effective_month'] : date('m');
    $parsed = isset($preview['parsed']) && is_array($preview['parsed']) ? $preview['parsed'] : array();
    $parsed = cpms_company_payroll_filter_selected_employees($parsed, $selectedEmployeeKeys);
    if (!isset($parsed['employees']) || !is_array($parsed['employees']) || count($parsed['employees']) === 0) {
        return array('ok' => false, 'message' => '확정 저장할 직원을 1명 이상 선택해주세요.');
    }
    $tempPath = isset($preview['temp_path']) ? (string)$preview['temp_path'] : '';
    $originalName = isset($preview['uploaded_original_name']) ? (string)$preview['uploaded_original_name'] : 'payroll.xlsx';
    if ($tempPath === '' || !is_file($tempPath)) return array('ok' => false, 'message' => '원본 급여대장 임시 파일을 찾지 못했습니다.');

    $context = array(
        'user' => $user,
        'uploaded_by' => $user,
        'section' => 'company_payroll',
        'document_type' => '임직원월급',
        'document_year' => $year,
        'document_month' => $month,
        'original_name' => $originalName,
    );
    $driveRecord = array();
    $driveStatus = 'failed';
    $driveError = '';
    $target = cpms_company_overhead_drive_ensure_month_subfolder('payroll', '임직원월급', $year, $month, '원본급여대장', $context);
    if (empty($target['ok'])) {
        $driveError = isset($target['message']) ? (string)$target['message'] : 'Drive 폴더를 준비하지 못했습니다.';
    } else {
        $mimeType = cpms_drive_detect_mime_type($tempPath);
        $driveName = cpms_company_payroll_build_drive_name($year, $month, $originalName, $user);
        $context['target_folder_id'] = (string)$target['folder_id'];
        $context['drive_year_folder_id'] = (string)$target['year_folder_id'];
        $context['drive_type_folder_id'] = (string)$target['category_folder_id'];
        $context['drive_month_folder_id'] = (string)$target['month_folder_id'];
        $context['drive_payroll_original_folder_id'] = isset($target['sub_folder_id']) ? (string)$target['sub_folder_id'] : (string)$target['folder_id'];
        $upload = cpms_drive_upload_file($tempPath, $driveName, (string)$target['folder_id'], $mimeType, $context);
        if (empty($upload['ok']) || !isset($upload['file']) || !is_array($upload['file'])) {
            $driveError = isset($upload['message']) ? (string)$upload['message'] : 'Drive 업로드에 실패했습니다.';
        } else {
            $driveStatus = 'success';
            $driveRecord = cpms_drive_build_file_record($upload['file'], $context);
            $driveRecord['drive_year_folder_id'] = (string)$target['year_folder_id'];
            $driveRecord['drive_type_folder_id'] = (string)$target['category_folder_id'];
            $driveRecord['drive_month_folder_id'] = (string)$target['month_folder_id'];
        }
    }

    $versionId = 'payroll_' . $year . '_' . $month . '_' . substr(md5($token . microtime(true)), 0, 10);
    $version = array(
        'version_id' => $versionId,
        'effective_year' => $year,
        'effective_month' => $month,
        'uploaded_original_name' => $originalName,
        'uploaded_drive_file_id' => isset($driveRecord['drive_file_id']) ? $driveRecord['drive_file_id'] : '',
        'uploaded_drive_folder_id' => isset($driveRecord['drive_folder_id']) ? $driveRecord['drive_folder_id'] : '',
        'uploaded_drive_web_view_link' => isset($driveRecord['drive_web_view_link']) ? $driveRecord['drive_web_view_link'] : '',
        'uploaded_drive_web_content_link' => isset($driveRecord['drive_web_content_link']) ? $driveRecord['drive_web_content_link'] : '',
        'uploaded_stored_name' => isset($driveRecord['stored_name']) ? $driveRecord['stored_name'] : '',
        'uploaded_drive_status' => $driveStatus,
        'uploaded_drive_error' => $driveError,
        'uploaded_at' => date('Y-m-d H:i:s'),
        'uploaded_by' => cpms_company_payroll_user_label($user),
        'sheet_name' => isset($parsed['sheet_name']) ? $parsed['sheet_name'] : '',
        'employee_count' => isset($parsed['employee_count']) ? (int)$parsed['employee_count'] : 0,
        'total_gross_pay' => isset($parsed['total_gross_pay']) ? (float)$parsed['total_gross_pay'] : 0.0,
        'total_deduction' => isset($parsed['total_deduction']) ? (float)$parsed['total_deduction'] : 0.0,
        'total_net_pay' => isset($parsed['total_net_pay']) ? (float)$parsed['total_net_pay'] : 0.0,
        'employees' => isset($parsed['employees']) && is_array($parsed['employees']) ? $parsed['employees'] : array(),
    );

    if (!cpms_company_payroll_backup_existing_version($year, $month)) {
        if ((string)$version['uploaded_drive_file_id'] !== '') cpms_drive_delete_file((string)$version['uploaded_drive_file_id'], array('user' => $user, 'section' => 'company_payroll', 'message' => 'Payroll backup failed after Drive upload.'));
        return array('ok' => false, 'message' => '기존 급여 버전 백업에 실패했습니다.');
    }
    $saved = cpms_company_payroll_write_json(cpms_company_payroll_version_file($year, $month), $version);
    if (!$saved) {
        if ((string)$version['uploaded_drive_file_id'] !== '') cpms_drive_delete_file((string)$version['uploaded_drive_file_id'], array('user' => $user, 'section' => 'company_payroll', 'message' => 'Payroll JSON save failed after Drive upload.'));
        return array('ok' => false, 'message' => '급여 기준월 버전을 저장하지 못했습니다.');
    }
    cpms_company_payroll_invalidate_statement_result($year, $month, 'payroll_version_confirmed');
    cpms_company_payroll_clear_preview($token);
    $message = '급여 기준월 버전이 저장되었습니다.';
    if ($driveStatus !== 'success') $message .= ' 다만 원본 급여대장 Drive 저장은 실패했습니다. 관리자 Drive 점검을 확인해주세요.';
    return array('ok' => true, 'message' => $message, 'drive_status' => $driveStatus, 'drive_error' => $driveError, 'version' => cpms_company_payroll_public_version($version));
}}

if (!function_exists('cpms_company_payroll_load_version')) {
function cpms_company_payroll_load_version($year, $month) {
    return cpms_company_payroll_read_json(cpms_company_payroll_version_file($year, $month));
}}

if (!function_exists('cpms_company_payroll_effective_version')) {
function cpms_company_payroll_effective_version($year, $month) {
    $ym = cpms_company_payroll_normalize_year_month($year, $month);
    $target = $ym['year'] . '-' . $ym['month'];
    $root = cpms_company_payroll_versions_root();
    $bestYm = '';
    if (!is_dir($root)) return array('ok' => false, 'version' => null, 'effective_year' => '', 'effective_month' => '', 'message' => '급여 기준월 버전이 없습니다.');
    $yearDirs = @scandir($root);
    if (!is_array($yearDirs)) $yearDirs = array();
    foreach ($yearDirs as $yearDir) {
        if (!preg_match('/^\d{4}$/', $yearDir)) continue;
        $dir = rtrim($root, '/\\') . '/' . $yearDir;
        if (!is_dir($dir)) continue;
        $files = @scandir($dir);
        if (!is_array($files)) continue;
        foreach ($files as $file) {
            if (!preg_match('/^(\d{2})\.json$/', $file, $m)) continue;
            $oneYm = $yearDir . '-' . $m[1];
            if ($oneYm <= $target && ($bestYm === '' || $oneYm > $bestYm)) $bestYm = $oneYm;
        }
    }
    if ($bestYm === '') return array('ok' => false, 'version' => null, 'effective_year' => '', 'effective_month' => '', 'message' => '선택 월에 적용할 급여 기준월 버전이 없습니다.');
    $version = cpms_company_payroll_load_version(substr($bestYm, 0, 4), substr($bestYm, 5, 2));
    if (!is_array($version)) return array('ok' => false, 'version' => null, 'effective_year' => '', 'effective_month' => '', 'message' => '급여 기준월 버전을 읽지 못했습니다.');
    return array('ok' => true, 'version' => $version, 'effective_year' => substr($bestYm, 0, 4), 'effective_month' => substr($bestYm, 5, 2), 'message' => '급여 기준월 버전이 적용되었습니다.');
}}

if (!function_exists('cpms_company_payroll_month_summary')) {
function cpms_company_payroll_month_summary($year, $month) {
    $effective = cpms_company_payroll_effective_version($year, $month);
    if (empty($effective['ok']) || !isset($effective['version']) || !is_array($effective['version'])) {
        if (function_exists('cpms_archive_summary_month_category_amount')) {
            $archiveAmount = cpms_archive_summary_month_category_amount($year, $month, 'payroll');
            if (!empty($archiveAmount['has_data'])) {
                $amount = isset($archiveAmount['amount']) ? (float)$archiveAmount['amount'] : 0.0;
                return array('has_data' => true, 'amount' => $amount, 'total_net_pay' => $amount, 'total_gross_pay' => 0.0, 'total_deduction' => 0.0, 'employee_count' => 0, 'effective_year' => sprintf('%04d', (int)$year), 'effective_month' => sprintf('%02d', (int)$month), 'version' => null, 'archive_summary' => true);
            }
        }
        return array('has_data' => false, 'amount' => 0.0, 'total_net_pay' => 0.0, 'total_gross_pay' => 0.0, 'total_deduction' => 0.0, 'employee_count' => 0, 'effective_year' => '', 'effective_month' => '', 'version' => null);
    }
    $version = $effective['version'];
    return array(
        'has_data' => true,
        'amount' => isset($version['total_net_pay']) ? (float)$version['total_net_pay'] : 0.0,
        'total_net_pay' => isset($version['total_net_pay']) ? (float)$version['total_net_pay'] : 0.0,
        'total_gross_pay' => isset($version['total_gross_pay']) ? (float)$version['total_gross_pay'] : 0.0,
        'total_deduction' => isset($version['total_deduction']) ? (float)$version['total_deduction'] : 0.0,
        'employee_count' => isset($version['employee_count']) ? (int)$version['employee_count'] : (isset($version['employees']) && is_array($version['employees']) ? count($version['employees']) : 0),
        'effective_year' => isset($effective['effective_year']) ? $effective['effective_year'] : '',
        'effective_month' => isset($effective['effective_month']) ? $effective['effective_month'] : '',
        'version' => $version,
    );
}}

if (!function_exists('cpms_company_payroll_filter_employees')) {
function cpms_company_payroll_filter_employees($employees, $filters) {
    if (!is_array($employees)) return array();
    if (!is_array($filters)) $filters = array();
    $q = isset($filters['q']) ? trim((string)$filters['q']) : '';
    $status = isset($filters['status']) ? trim((string)$filters['status']) : '';
    $department = isset($filters['department']) ? trim((string)$filters['department']) : '';
    $position = isset($filters['position']) ? trim((string)$filters['position']) : '';
    $result = array();
    foreach ($employees as $employee) {
        if (!is_array($employee)) continue;
        if ($status !== '' && (!isset($employee['status']) || trim((string)$employee['status']) !== $status)) continue;
        if ($department !== '' && (!isset($employee['department']) || trim((string)$employee['department']) !== $department)) continue;
        if ($position !== '' && (!isset($employee['position']) || trim((string)$employee['position']) !== $position)) continue;
        if ($q !== '') {
            $haystack = '';
            foreach (array('name','position','department','status','etc') as $key) {
                if (isset($employee[$key])) $haystack .= ' ' . (string)$employee[$key];
            }
            if (function_exists('mb_strtolower')) {
                if (mb_strpos(mb_strtolower($haystack, 'UTF-8'), mb_strtolower($q, 'UTF-8'), 0, 'UTF-8') === false) continue;
            } else if (strpos(strtolower($haystack), strtolower($q)) === false) {
                continue;
            }
        }
        $result[] = cpms_company_payroll_public_employee($employee);
    }
    return $result;
}}

if (!function_exists('cpms_company_payroll_find_employee_in_version')) {
function cpms_company_payroll_find_employee_in_version($version, $employeeKey) {
    if (!is_array($version) || !isset($version['employees']) || !is_array($version['employees'])) return null;
    $employeeKey = trim((string)$employeeKey);
    if ($employeeKey === '') return null;
    foreach ($version['employees'] as $employee) {
        if (is_array($employee) && isset($employee['employee_key']) && (string)$employee['employee_key'] === $employeeKey) return $employee;
    }
    return null;
}}

if (!function_exists('cpms_company_payroll_delete_employee_for_month')) {
function cpms_company_payroll_delete_employee_for_month($year, $month, $employeeKey, $user) {
    $ym = cpms_company_payroll_normalize_year_month($year, $month);
    $employeeKey = trim((string)$employeeKey);
    if ($employeeKey === '') return array('ok' => false, 'message' => '삭제할 직원을 선택해주세요.');

    $effective = cpms_company_payroll_effective_version($ym['year'], $ym['month']);
    if (empty($effective['ok']) || !isset($effective['version']) || !is_array($effective['version'])) {
        return array('ok' => false, 'message' => isset($effective['message']) ? $effective['message'] : '적용 중인 급여 기준월 버전이 없습니다.');
    }

    $sourceVersion = $effective['version'];
    $employees = isset($sourceVersion['employees']) && is_array($sourceVersion['employees']) ? $sourceVersion['employees'] : array();
    $remaining = array();
    $deletedEmployee = null;
    foreach ($employees as $employee) {
        if (!is_array($employee)) continue;
        $key = isset($employee['employee_key']) ? (string)$employee['employee_key'] : '';
        if ($key === $employeeKey) {
            $deletedEmployee = $employee;
            continue;
        }
        $remaining[] = $employee;
    }
    if (!is_array($deletedEmployee)) return array('ok' => false, 'message' => '삭제할 직원 급여 데이터를 찾지 못했습니다.');

    $newVersion = $sourceVersion;
    $newVersion = cpms_company_payroll_recalculate_employee_totals($newVersion, $remaining);
    $newVersion['version_id'] = 'payroll_' . $ym['year'] . '_' . $ym['month'] . '_delete_' . substr(md5($employeeKey . microtime(true)), 0, 10);
    $newVersion['effective_year'] = $ym['year'];
    $newVersion['effective_month'] = $ym['month'];
    $newVersion['change_type'] = 'employee_delete';
    $newVersion['source_payroll_version_year'] = isset($effective['effective_year']) ? (string)$effective['effective_year'] : '';
    $newVersion['source_payroll_version_month'] = isset($effective['effective_month']) ? (string)$effective['effective_month'] : '';
    $newVersion['source_version_id'] = isset($sourceVersion['version_id']) ? (string)$sourceVersion['version_id'] : '';
    $newVersion['deleted_employee_key'] = $employeeKey;
    $newVersion['deleted_employee_name'] = isset($deletedEmployee['name']) ? (string)$deletedEmployee['name'] : '';
    $newVersion['updated_at'] = date('Y-m-d H:i:s');
    $newVersion['updated_by'] = cpms_company_payroll_user_label($user);

    if (!cpms_company_payroll_backup_existing_version($ym['year'], $ym['month'])) {
        return array('ok' => false, 'message' => '기존 급여 버전 백업에 실패했습니다.');
    }
    if (!cpms_company_payroll_write_json(cpms_company_payroll_version_file($ym['year'], $ym['month']), $newVersion)) {
        return array('ok' => false, 'message' => '직원 삭제 기준월 버전을 저장하지 못했습니다.');
    }
    cpms_company_payroll_invalidate_statement_result($ym['year'], $ym['month'], 'payroll_employee_deleted');
    if (isset($_SESSION['_company_profit_cache'])) unset($_SESSION['_company_profit_cache']);
    return array(
        'ok' => true,
        'message' => '선택한 월 기준으로 직원이 삭제되었습니다.',
        'deleted_employee_key' => $employeeKey,
        'deleted_employee_name' => isset($deletedEmployee['name']) ? (string)$deletedEmployee['name'] : '',
        'version' => cpms_company_payroll_public_version($newVersion)
    );
}}

if (!function_exists('cpms_company_payroll_sensitive_log_file')) {
function cpms_company_payroll_sensitive_log_file($year, $month) {
    $ym = cpms_company_payroll_normalize_year_month($year, $month);
    return cpms_company_payroll_sensitive_logs_root() . '/' . $ym['year'] . '/' . $ym['month'] . '.json';
}}

if (!function_exists('cpms_company_payroll_log_resident_reveal')) {
function cpms_company_payroll_log_resident_reveal($user, $employee, $year, $month, $effectiveYear, $effectiveMonth) {
    $path = cpms_company_payroll_sensitive_log_file($year, $month);
    $logs = cpms_company_payroll_read_json($path);
    if (!is_array($logs)) $logs = array();
    $logs[] = array(
        'viewed_at' => date('Y-m-d H:i:s'),
        'viewer' => cpms_company_payroll_user_label($user),
        'viewer_id' => is_array($user) && isset($user['id']) ? (string)$user['id'] : '',
        'viewer_email' => is_array($user) && isset($user['email']) ? (string)$user['email'] : '',
        'employee_name' => is_array($employee) && isset($employee['name']) ? (string)$employee['name'] : '',
        'employee_key' => is_array($employee) && isset($employee['employee_key']) ? (string)$employee['employee_key'] : '',
        'year' => sprintf('%04d', (int)$year),
        'month' => sprintf('%02d', (int)$month),
        'effective_year' => (string)$effectiveYear,
        'effective_month' => (string)$effectiveMonth,
        'ip' => isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '',
        'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 500) : '',
    );
    return cpms_company_payroll_write_json($path, $logs);
}}

if (!function_exists('cpms_company_payroll_log_bank_account_reveal')) {
function cpms_company_payroll_log_bank_account_reveal($user, $employee, $year, $month, $effectiveYear, $effectiveMonth) {
    $path = cpms_company_payroll_sensitive_log_file($year, $month);
    $logs = cpms_company_payroll_read_json($path);
    if (!is_array($logs)) $logs = array();
    $logs[] = array(
        'viewed_at' => date('Y-m-d H:i:s'),
        'action' => 'bank_account_reveal',
        'viewer' => cpms_company_payroll_user_label($user),
        'viewer_id' => is_array($user) && isset($user['id']) ? (string)$user['id'] : '',
        'viewer_email' => is_array($user) && isset($user['email']) ? (string)$user['email'] : '',
        'employee_name' => is_array($employee) && isset($employee['name']) ? (string)$employee['name'] : '',
        'employee_key' => is_array($employee) && isset($employee['employee_key']) ? (string)$employee['employee_key'] : '',
        'year' => sprintf('%04d', (int)$year),
        'month' => sprintf('%02d', (int)$month),
        'effective_year' => (string)$effectiveYear,
        'effective_month' => (string)$effectiveMonth,
        'ip' => isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '',
        'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 500) : '',
    );
    return cpms_company_payroll_write_json($path, $logs);
}}

if (!function_exists('cpms_company_payroll_reveal_resident')) {
function cpms_company_payroll_reveal_resident($year, $month, $employeeKey, $user) {
    $effective = cpms_company_payroll_effective_version($year, $month);
    if (empty($effective['ok']) || !isset($effective['version']) || !is_array($effective['version'])) {
        return array('ok' => false, 'message' => '적용 중인 급여 기준월 버전이 없습니다.');
    }
    $employee = cpms_company_payroll_find_employee_in_version($effective['version'], $employeeKey);
    if (!is_array($employee)) return array('ok' => false, 'message' => '직원 급여 데이터를 찾지 못했습니다.');
    $encrypted = isset($employee['resident_encrypted']) ? (string)$employee['resident_encrypted'] : '';
    if ($encrypted === '') return array('ok' => false, 'message' => '주민번호 원본 복호화 데이터가 없습니다. 키 설정 또는 업로드 파일을 확인해주세요.');
    $plain = cpms_company_payroll_decrypt_resident($encrypted);
    if ($plain === '') return array('ok' => false, 'message' => '주민번호 복호화 키가 없거나 복호화에 실패했습니다.');
    cpms_company_payroll_log_resident_reveal($user, $employee, $year, $month, $effective['effective_year'], $effective['effective_month']);
    return array('ok' => true, 'resident_number' => $plain, 'employee_key' => $employeeKey, 'message' => '주민번호를 조회했습니다.');
}}

if (!function_exists('cpms_company_payroll_reveal_bank_account')) {
function cpms_company_payroll_reveal_bank_account($year, $month, $employeeKey, $user) {
    $effective = cpms_company_payroll_effective_version($year, $month);
    if (empty($effective['ok']) || !isset($effective['version']) || !is_array($effective['version'])) {
        return array('ok' => false, 'message' => '적용 중인 급여 기준월 버전이 없습니다.');
    }
    $employee = cpms_company_payroll_find_employee_in_version($effective['version'], $employeeKey);
    if (!is_array($employee)) return array('ok' => false, 'message' => '직원 급여 데이터를 찾지 못했습니다.');
    $account = isset($employee['bank_account']) ? trim((string)$employee['bank_account']) : '';
    if ($account === '') return array('ok' => false, 'message' => '저장된 계좌번호가 없습니다.');
    cpms_company_payroll_log_bank_account_reveal($user, $employee, $year, $month, $effective['effective_year'], $effective['effective_month']);
    return array(
        'ok' => true,
        'bank_name' => isset($employee['bank_name']) ? (string)$employee['bank_name'] : '',
        'bank_account' => $account,
        'employee_key' => $employeeKey,
        'message' => '계좌번호를 조회했습니다.'
    );
}}
