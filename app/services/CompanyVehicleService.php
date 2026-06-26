<?php
/**
 * Company vehicle ledger service.
 * PHP 5.6 compatible.
 */

require_once __DIR__ . '/EmployeeVehicleService.php';

if (!function_exists('cpms_company_vehicle_storage_roots')) {
function cpms_company_vehicle_storage_roots() {
    $root = dirname(dirname(__DIR__));
    $roots = array($root . '/data/company_overhead/company_vehicles');
    if (function_exists('cpms_storage_root')) $roots[] = cpms_storage_root() . '/company_overhead/company_vehicles';
    else $roots[] = $root . '/storage/company_overhead/company_vehicles';
    return $roots;
}}

if (!function_exists('cpms_company_vehicle_writable_root')) {
function cpms_company_vehicle_writable_root() {
    $roots = cpms_company_vehicle_storage_roots();
    foreach ($roots as $root) {
        $root = rtrim((string)$root, '/\\');
        if ($root === '') continue;
        if (is_dir($root) && is_writable($root)) return $root;
        if (!is_dir($root) && @mkdir($root, 0777, true) && is_dir($root) && is_writable($root)) return $root;
    }
    return count($roots) > 0 ? rtrim((string)$roots[0], '/\\') : dirname(dirname(__DIR__)) . '/data/company_overhead/company_vehicles';
}}

if (!function_exists('cpms_company_vehicle_data_file')) {
function cpms_company_vehicle_data_file() {
    return cpms_company_vehicle_writable_root() . '/vehicles.json';
}}

if (!function_exists('cpms_company_vehicle_data_file_candidates')) {
function cpms_company_vehicle_data_file_candidates() {
    $files = array();
    foreach (cpms_company_vehicle_storage_roots() as $root) {
        $root = rtrim((string)$root, '/\\');
        if ($root !== '') $files[] = $root . '/vehicles.json';
    }
    return $files;
}}

if (!function_exists('cpms_company_vehicle_json_encode')) {
function cpms_company_vehicle_json_encode($data) {
    $options = 0;
    if (defined('JSON_UNESCAPED_UNICODE')) $options = $options | JSON_UNESCAPED_UNICODE;
    if (defined('JSON_PRETTY_PRINT')) $options = $options | JSON_PRETTY_PRINT;
    if (defined('JSON_UNESCAPED_SLASHES')) $options = $options | JSON_UNESCAPED_SLASHES;
    return json_encode($data, $options);
}}

if (!function_exists('cpms_company_vehicle_read_json')) {
function cpms_company_vehicle_read_json($path) {
    if (!is_file($path)) return null;
    $txt = @file_get_contents($path);
    if ($txt === false || trim($txt) === '') return null;
    $data = @json_decode($txt, true);
    return is_array($data) ? $data : null;
}}

if (!function_exists('cpms_company_vehicle_set_last_error')) {
function cpms_company_vehicle_set_last_error($message) {
    $GLOBALS['_cpms_company_vehicle_last_error'] = (string)$message;
}}

if (!function_exists('cpms_company_vehicle_last_error')) {
function cpms_company_vehicle_last_error() {
    return isset($GLOBALS['_cpms_company_vehicle_last_error']) ? (string)$GLOBALS['_cpms_company_vehicle_last_error'] : '';
}}

if (!function_exists('cpms_company_vehicle_write_json')) {
function cpms_company_vehicle_write_json($path, $data) {
    cpms_company_vehicle_set_last_error('');
    $dir = dirname($path);
    if (function_exists('cpms_ensure_dir')) {
        if (!cpms_ensure_dir($dir)) {
            cpms_company_vehicle_set_last_error('회사차량 저장 폴더를 만들 수 없습니다: ' . $dir);
            return false;
        }
    } else if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
        cpms_company_vehicle_set_last_error('회사차량 저장 폴더를 만들 수 없습니다: ' . $dir);
        return false;
    }
    if (!is_writable($dir)) {
        cpms_company_vehicle_set_last_error('회사차량 저장 폴더에 쓰기 권한이 없습니다: ' . $dir);
        return false;
    }
    $json = cpms_company_vehicle_json_encode($data);
    if (!is_string($json)) {
        $jsonError = function_exists('json_last_error_msg') ? json_last_error_msg() : 'json_encode failed';
        cpms_company_vehicle_set_last_error('회사차량 JSON 변환 실패: ' . $jsonError);
        return false;
    }
    $ok = @file_put_contents($path, $json, LOCK_EX);
    if ($ok === false) {
        $err = error_get_last();
        cpms_company_vehicle_set_last_error('회사차량 파일 저장 실패: ' . $path . (is_array($err) && isset($err['message']) ? ' / ' . $err['message'] : ''));
        return false;
    }
    return true;
}}

if (!function_exists('cpms_company_vehicle_fields')) {
function cpms_company_vehicle_fields() {
    return array(
        'sequence' => array('label' => '순서', 'type' => 'text'),
        'vehicle_name' => array('label' => '차량명', 'type' => 'text'),
        'vehicle_number' => array('label' => '차량번호', 'type' => 'text'),
        'acquired_at' => array('label' => '취득일', 'type' => 'date_text'),
        'primary_manager' => array('label' => '정', 'type' => 'text'),
        'secondary_manager' => array('label' => '부', 'type' => 'text'),
        'driver_name' => array('label' => '운전자', 'type' => 'text'),
        'site_name' => array('label' => '현장', 'type' => 'text'),
        'inspection_period' => array('label' => '검사유효기간', 'type' => 'period'),
        'corporate_number' => array('label' => '법인번호(검사)', 'type' => 'text'),
        'insurer' => array('label' => '보험사', 'type' => 'text'),
        'insurance_premium' => array('label' => '보험료', 'type' => 'money'),
        'insurance_period' => array('label' => '보험기간', 'type' => 'period'),
        'finance_period' => array('label' => '할부기간', 'type' => 'period'),
        'age_limit' => array('label' => '연령한정', 'type' => 'text'),
        'driver_limit' => array('label' => '운전자한정', 'type' => 'text'),
        'vehicle_type' => array('label' => '차량 구분', 'type' => 'text'),
        'note' => array('label' => '비고', 'type' => 'text'),
        'schedule_period' => array('label' => '스케줄표기간', 'type' => 'period'),
        'interest_rate' => array('label' => '이자율', 'type' => 'number'),
        'paid_count' => array('label' => '납입횟수', 'type' => 'int'),
        'total_count' => array('label' => '총 횟수', 'type' => 'int'),
        'payment_day' => array('label' => '납입일', 'type' => 'int'),
        'principal_amount' => array('label' => '금액(X열)', 'type' => 'money'),
        'total_amount' => array('label' => '총액', 'type' => 'money'),
        'remaining_amount' => array('label' => '잔여 금액', 'type' => 'money'),
        'monthly_payment' => array('label' => '월 납입금액', 'type' => 'money'),
        'previous_insurance_premium' => array('label' => '보험료(이전)', 'type' => 'money'),
        'extra_note' => array('label' => '추가메모(AC열)', 'type' => 'text'),
        'toll_device_card' => array('label' => '하이패스단말기/카드 여부', 'type' => 'text'),
        'sales_person' => array('label' => '영업사원', 'type' => 'text'),
        'cancellation_penalty' => array('label' => '해지시 위약금', 'type' => 'money'),
    );
}}

if (!function_exists('cpms_company_vehicle_excel_columns')) {
function cpms_company_vehicle_excel_columns() {
    return array(
        0 => 'sequence',
        1 => 'vehicle_name',
        2 => 'vehicle_number',
        3 => 'acquired_at',
        4 => 'primary_manager',
        5 => 'secondary_manager',
        6 => 'driver_name',
        7 => 'site_name',
        8 => 'inspection_period',
        9 => 'corporate_number',
        10 => 'insurer',
        11 => 'insurance_premium',
        12 => 'insurance_period',
        13 => 'finance_period',
        14 => 'age_limit',
        15 => 'driver_limit',
        16 => 'vehicle_type',
        17 => 'note',
        18 => 'schedule_period',
        19 => 'interest_rate',
        20 => 'paid_count',
        21 => 'total_count',
        22 => 'payment_day',
        23 => 'principal_amount',
        24 => 'total_amount',
        25 => 'remaining_amount',
        26 => 'monthly_payment',
        27 => 'previous_insurance_premium',
        28 => 'extra_note',
        29 => 'toll_device_card',
        30 => 'sales_person',
        31 => 'cancellation_penalty',
    );
}}

if (!function_exists('cpms_company_vehicle_user_label')) {
function cpms_company_vehicle_user_label($user) {
    if (is_array($user)) {
        if (isset($user['name']) && trim((string)$user['name']) !== '') return trim((string)$user['name']);
        if (isset($user['email']) && trim((string)$user['email']) !== '') return trim((string)$user['email']);
        if (isset($user['id'])) return 'user#' . (int)$user['id'];
    }
    $txt = trim((string)$user);
    return $txt !== '' ? $txt : '-';
}}

if (!function_exists('cpms_company_vehicle_new_id')) {
function cpms_company_vehicle_new_id() {
    $rand = '';
    if (function_exists('openssl_random_pseudo_bytes')) {
        $bytes = openssl_random_pseudo_bytes(4);
        if ($bytes !== false) $rand = bin2hex($bytes);
    }
    if ($rand === '') $rand = substr(md5(uniqid('', true)), 0, 8);
    return 'CV-' . date('YmdHis') . '-' . $rand;
}}

if (!function_exists('cpms_company_vehicle_numeric_value')) {
function cpms_company_vehicle_numeric_value($value) {
    if (is_int($value) || is_float($value)) return (float)$value;
    $value = trim((string)$value);
    if ($value === '' || $value === '-') return 0.0;
    $value = str_replace(',', '', $value);
    if (is_numeric($value)) return (float)$value;
    $value = preg_replace('/[^0-9eE\.\+\-]/', '', $value);
    if ($value === '' || $value === '-' || $value === '+' || !is_numeric($value)) return 0.0;
    return (float)$value;
}}

if (!function_exists('cpms_company_vehicle_money_value')) {
function cpms_company_vehicle_money_value($value) {
    $amount = cpms_company_vehicle_numeric_value($value);
    return $amount < 0 ? 0.0 : round($amount, 2);
}}

if (!function_exists('cpms_company_vehicle_excel_serial_to_date')) {
function cpms_company_vehicle_excel_serial_to_date($value) {
    if (!is_numeric($value)) return '';
    $days = (int)floor((float)$value);
    if ($days <= 0) return '';
    $ts = strtotime('1899-12-30 +' . $days . ' days');
    return $ts === false ? '' : date('Y-m-d', $ts);
}}

if (!function_exists('cpms_company_vehicle_date_from_parts')) {
function cpms_company_vehicle_date_from_parts($year, $month, $day) {
    $year = (int)$year;
    $month = (int)$month;
    $day = (int)$day;
    if ($year < 100) $year = ($year >= 70) ? (1900 + $year) : (2000 + $year);
    if ($year < 1900 || $year > 2200 || $month < 1 || $month > 12 || $day < 1 || $day > 31) return '';
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}}

if (!function_exists('cpms_company_vehicle_normalize_date')) {
function cpms_company_vehicle_normalize_date($value) {
    $raw = trim((string)$value);
    if ($raw === '') return '';
    if (is_numeric($raw) && (float)$raw > 20000 && (float)$raw < 90000) {
        $date = cpms_company_vehicle_excel_serial_to_date($raw);
        if ($date !== '') return $date;
    }
    if (preg_match('/^(\d{4}|\d{2})\s*[\.\-\/]\s*(\d{1,2})\s*[\.\-\/]\s*(\d{1,2})/u', $raw, $m)) {
        return cpms_company_vehicle_date_from_parts($m[1], $m[2], $m[3]);
    }
    $ts = strtotime($raw);
    if ($ts === false) return '';
    return date('Y-m-d', $ts);
}}

if (!function_exists('cpms_company_vehicle_period_dates')) {
function cpms_company_vehicle_period_dates($value) {
    $raw = trim((string)$value);
    $result = array('start' => '', 'end' => '');
    if ($raw === '') return $result;
    if (preg_match_all('/(\d{4}|\d{2})\s*[\.\-\/]\s*(\d{1,2})\s*[\.\-\/]\s*(\d{1,2})/u', $raw, $matches, PREG_SET_ORDER)) {
        if (isset($matches[0])) $result['start'] = cpms_company_vehicle_date_from_parts($matches[0][1], $matches[0][2], $matches[0][3]);
        if (isset($matches[1])) $result['end'] = cpms_company_vehicle_date_from_parts($matches[1][1], $matches[1][2], $matches[1][3]);
    }
    return $result;
}}

if (!function_exists('cpms_company_vehicle_format_period')) {
function cpms_company_vehicle_format_period($start, $end) {
    $start = trim((string)$start);
    $end = trim((string)$end);
    if ($start !== '' && $end !== '') return $start . ' ~ ' . $end;
    if ($start !== '') return $start;
    if ($end !== '') return $end;
    return '';
}}

if (!function_exists('cpms_company_vehicle_normalize_ym')) {
function cpms_company_vehicle_normalize_ym($year, $month) {
    $y = (int)$year;
    $m = (int)$month;
    if ($y < 2026 || $y > 2200) $y = (int)date('Y');
    if ($y < 2026) $y = 2026;
    if ($m < 1 || $m > 12) $m = (int)date('m');
    if ($m < 1 || $m > 12) $m = 1;
    return sprintf('%04d-%02d', $y, $m);
}}

if (!function_exists('cpms_company_vehicle_ym_valid')) {
function cpms_company_vehicle_ym_valid($ym) {
    return preg_match('/^\d{4}-\d{2}$/', (string)$ym) ? true : false;
}}

if (!function_exists('cpms_company_vehicle_ym_from_date')) {
function cpms_company_vehicle_ym_from_date($date) {
    $date = trim((string)$date);
    if (!preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $date, $m)) return '';
    return $m[1] . '-' . $m[2];
}}

if (!function_exists('cpms_company_vehicle_compare_ym')) {
function cpms_company_vehicle_compare_ym($a, $b) {
    $a = (string)$a;
    $b = (string)$b;
    if ($a === $b) return 0;
    return ($a < $b) ? -1 : 1;
}}

if (!function_exists('cpms_company_vehicle_add_months')) {
function cpms_company_vehicle_add_months($ym, $delta) {
    if (!cpms_company_vehicle_ym_valid($ym)) return '';
    $ts = strtotime($ym . '-01');
    if ($ts === false) return '';
    $delta = (int)$delta;
    $txt = ($delta >= 0 ? '+' . $delta : (string)$delta) . ' months';
    $newTs = strtotime($txt, $ts);
    return $newTs === false ? '' : date('Y-m', $newTs);
}}

if (!function_exists('cpms_company_vehicle_date_add_years')) {
function cpms_company_vehicle_date_add_years($date, $years) {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string)$date, $m)) return '';
    $newYear = (int)$m[1] + (int)$years;
    $month = (int)$m[2];
    $day = (int)$m[3];
    $lastDay = (int)date('t', mktime(0, 0, 0, $month, 1, $newYear));
    if ($day > $lastDay) $day = $lastDay;
    return sprintf('%04d-%02d-%02d', $newYear, $month, $day);
}}

if (!function_exists('cpms_company_vehicle_read_data')) {
function cpms_company_vehicle_read_data() {
    foreach (cpms_company_vehicle_data_file_candidates() as $path) {
        $data = cpms_company_vehicle_read_json($path);
        if (!is_array($data)) continue;
        if (!isset($data['vehicles']) || !is_array($data['vehicles'])) $data['vehicles'] = array();
        if (!isset($data['version'])) $data['version'] = 1;
        return $data;
    }
    return array('version' => 1, 'vehicles' => array());
}}

if (!function_exists('cpms_company_vehicle_save_data')) {
function cpms_company_vehicle_save_data($data, $user) {
    if (!is_array($data)) $data = array();
    if (!isset($data['vehicles']) || !is_array($data['vehicles'])) $data['vehicles'] = array();
    $data['version'] = isset($data['version']) ? (int)$data['version'] : 1;
    $data['updated_at'] = date('Y-m-d H:i:s');
    $data['updated_by'] = cpms_company_vehicle_user_label($user);
    return cpms_company_vehicle_write_json(cpms_company_vehicle_data_file(), $data);
}}

if (!function_exists('cpms_company_vehicle_load_all')) {
function cpms_company_vehicle_load_all($includeDeleted = false) {
    $data = cpms_company_vehicle_read_data();
    $vehicles = isset($data['vehicles']) && is_array($data['vehicles']) ? $data['vehicles'] : array();
    $result = array();
    foreach ($vehicles as $vehicle) {
        if (!is_array($vehicle)) continue;
        if (!$includeDeleted && isset($vehicle['deleted_at']) && trim((string)$vehicle['deleted_at']) !== '') continue;
        $result[] = cpms_company_vehicle_normalize_record_runtime($vehicle);
    }
    usort($result, 'cpms_company_vehicle_sort');
    return $result;
}}

if (!function_exists('cpms_company_vehicle_sort')) {
function cpms_company_vehicle_sort($a, $b) {
    $as = isset($a['sequence']) ? cpms_company_vehicle_numeric_value($a['sequence']) : 0;
    $bs = isset($b['sequence']) ? cpms_company_vehicle_numeric_value($b['sequence']) : 0;
    if ($as > 0 || $bs > 0) {
        if ($as == $bs) return 0;
        if ($as <= 0) return 1;
        if ($bs <= 0) return -1;
        return ($as < $bs) ? -1 : 1;
    }
    $an = isset($a['vehicle_number']) ? (string)$a['vehicle_number'] : '';
    $bn = isset($b['vehicle_number']) ? (string)$b['vehicle_number'] : '';
    if ($an === $bn) return 0;
    return ($an < $bn) ? -1 : 1;
}}

if (!function_exists('cpms_company_vehicle_find_index')) {
function cpms_company_vehicle_find_index($vehicles, $id) {
    $id = trim((string)$id);
    if ($id === '' || !is_array($vehicles)) return -1;
    foreach ($vehicles as $idx => $vehicle) {
        if (is_array($vehicle) && isset($vehicle['id']) && (string)$vehicle['id'] === $id) return (int)$idx;
    }
    return -1;
}}

if (!function_exists('cpms_company_vehicle_find')) {
function cpms_company_vehicle_find($id) {
    $data = cpms_company_vehicle_read_data();
    $vehicles = isset($data['vehicles']) && is_array($data['vehicles']) ? $data['vehicles'] : array();
    $idx = cpms_company_vehicle_find_index($vehicles, $id);
    if ($idx < 0) return null;
    return cpms_company_vehicle_normalize_record_runtime($vehicles[$idx]);
}}

if (!function_exists('cpms_company_vehicle_change_list_clean')) {
function cpms_company_vehicle_change_list_clean($changes, $valueKey) {
    $result = array();
    if (!is_array($changes)) return $result;
    foreach ($changes as $change) {
        if (!is_array($change)) continue;
        $ym = isset($change['effective_ym']) ? trim((string)$change['effective_ym']) : '';
        if (!cpms_company_vehicle_ym_valid($ym)) continue;
        $row = array('effective_ym' => $ym);
        if ($valueKey === 'amount') $row['amount'] = isset($change['amount']) ? cpms_company_vehicle_money_value($change['amount']) : 0.0;
        else $row[$valueKey] = isset($change[$valueKey]) ? trim((string)$change[$valueKey]) : '';
        if (isset($change['updated_at'])) $row['updated_at'] = (string)$change['updated_at'];
        if (isset($change['updated_by'])) $row['updated_by'] = (string)$change['updated_by'];
        $result[] = $row;
    }
    usort($result, 'cpms_company_vehicle_change_sort');
    return $result;
}}

if (!function_exists('cpms_company_vehicle_change_sort')) {
function cpms_company_vehicle_change_sort($a, $b) {
    $aym = isset($a['effective_ym']) ? (string)$a['effective_ym'] : '';
    $bym = isset($b['effective_ym']) ? (string)$b['effective_ym'] : '';
    if ($aym === $bym) return 0;
    return ($aym < $bym) ? -1 : 1;
}}

if (!function_exists('cpms_company_vehicle_effective_change_value')) {
function cpms_company_vehicle_effective_change_value($changes, $ym, $valueKey, $default) {
    $value = $default;
    if (!is_array($changes)) return $value;
    foreach ($changes as $change) {
        if (!is_array($change)) continue;
        $changeYm = isset($change['effective_ym']) ? (string)$change['effective_ym'] : '';
        if (!cpms_company_vehicle_ym_valid($changeYm)) continue;
        if (cpms_company_vehicle_compare_ym($changeYm, $ym) > 0) continue;
        if ($valueKey === 'amount' && isset($change['amount'])) $value = cpms_company_vehicle_money_value($change['amount']);
        else if (isset($change[$valueKey])) $value = (string)$change[$valueKey];
    }
    return $value;
}}

if (!function_exists('cpms_company_vehicle_normalize_record_runtime')) {
function cpms_company_vehicle_normalize_record_runtime($row) {
    if (!is_array($row)) $row = array();
    $fields = cpms_company_vehicle_fields();
    foreach ($fields as $key => $field) {
        if (!isset($row[$key])) $row[$key] = '';
    }
    if (!isset($row['vehicle_number_normalized']) || trim((string)$row['vehicle_number_normalized']) === '') {
        $row['vehicle_number_normalized'] = cpms_normalize_vehicle_number(isset($row['vehicle_number']) ? $row['vehicle_number'] : '');
    }
    if (!isset($row['baseline_ym']) || !cpms_company_vehicle_ym_valid($row['baseline_ym'])) {
        $row['baseline_ym'] = cpms_company_vehicle_normalize_ym(date('Y'), date('m'));
    }
    $row['monthly_payment_changes'] = cpms_company_vehicle_change_list_clean(isset($row['monthly_payment_changes']) ? $row['monthly_payment_changes'] : array(), 'amount');
    $row['driver_changes'] = cpms_company_vehicle_change_list_clean(isset($row['driver_changes']) ? $row['driver_changes'] : array(), 'driver_name');
    return $row;
}}

if (!function_exists('cpms_company_vehicle_prepare_record')) {
function cpms_company_vehicle_prepare_record($data, $existing, $user) {
    if (!is_array($data)) $data = array();
    $row = is_array($existing) ? $existing : array();
    $fields = cpms_company_vehicle_fields();
    $now = date('Y-m-d H:i:s');
    $userLabel = cpms_company_vehicle_user_label($user);

    if (!isset($row['id']) || trim((string)$row['id']) === '') {
        $row['id'] = cpms_company_vehicle_new_id();
        $row['created_at'] = $now;
        $row['created_by'] = $userLabel;
    }
    foreach ($fields as $key => $field) {
        if (!array_key_exists($key, $data)) {
            if (!isset($row[$key])) $row[$key] = '';
            continue;
        }
        $type = isset($field['type']) ? (string)$field['type'] : 'text';
        if ($type === 'money') {
            $row[$key] = cpms_company_vehicle_money_value($data[$key]);
        } else if ($type === 'number') {
            $row[$key] = cpms_company_vehicle_numeric_value($data[$key]);
        } else if ($type === 'int') {
            $txt = trim((string)$data[$key]);
            $row[$key] = ($txt === '') ? '' : (int)cpms_company_vehicle_numeric_value($txt);
        } else if ($type === 'date_text') {
            $date = cpms_company_vehicle_normalize_date($data[$key]);
            $row[$key] = $date !== '' ? $date : trim((string)$data[$key]);
        } else {
            $row[$key] = trim((string)$data[$key]);
        }
    }

    $inspection = cpms_company_vehicle_period_dates(isset($row['inspection_period']) ? $row['inspection_period'] : '');
    $row['inspection_start'] = $inspection['start'];
    $row['inspection_end'] = $inspection['end'];
    $insurance = cpms_company_vehicle_period_dates(isset($row['insurance_period']) ? $row['insurance_period'] : '');
    $row['insurance_start'] = $insurance['start'];
    $row['insurance_end'] = $insurance['end'];
    $finance = cpms_company_vehicle_period_dates(isset($row['finance_period']) ? $row['finance_period'] : '');
    $row['finance_start'] = $finance['start'];
    $row['finance_end'] = $finance['end'];
    $schedule = cpms_company_vehicle_period_dates(isset($row['schedule_period']) ? $row['schedule_period'] : '');
    $row['schedule_start'] = $schedule['start'];
    $row['schedule_end'] = $schedule['end'];
    $row['vehicle_number_normalized'] = cpms_normalize_vehicle_number(isset($row['vehicle_number']) ? $row['vehicle_number'] : '');

    $baseYear = isset($data['base_year']) ? (int)$data['base_year'] : (isset($data['baseline_year']) ? (int)$data['baseline_year'] : 0);
    $baseMonth = isset($data['base_month']) ? (int)$data['base_month'] : (isset($data['baseline_month']) ? (int)$data['baseline_month'] : 0);
    if ($baseYear <= 0 || $baseMonth <= 0) {
        if (isset($row['baseline_ym']) && cpms_company_vehicle_ym_valid($row['baseline_ym'])) {
            $baseYear = (int)substr($row['baseline_ym'], 0, 4);
            $baseMonth = (int)substr($row['baseline_ym'], 5, 2);
        } else {
            $baseYear = (int)date('Y');
            $baseMonth = (int)date('m');
        }
    }
    $row['baseline_ym'] = cpms_company_vehicle_normalize_ym($baseYear, $baseMonth);
    $row['baseline_remaining_amount'] = isset($row['remaining_amount']) ? cpms_company_vehicle_money_value($row['remaining_amount']) : 0.0;
    $row['baseline_monthly_payment'] = isset($row['monthly_payment']) ? cpms_company_vehicle_money_value($row['monthly_payment']) : 0.0;

    $paymentChanges = isset($row['monthly_payment_changes']) ? cpms_company_vehicle_change_list_clean($row['monthly_payment_changes'], 'amount') : array();
    if (count($paymentChanges) === 0) {
        $paymentStart = cpms_company_vehicle_default_change_ym($row);
        $paymentChanges[] = array('effective_ym' => $paymentStart, 'amount' => $row['baseline_monthly_payment'], 'updated_at' => $now, 'updated_by' => $userLabel);
    }
    $row['monthly_payment_changes'] = $paymentChanges;

    $driverChanges = isset($row['driver_changes']) ? cpms_company_vehicle_change_list_clean($row['driver_changes'], 'driver_name') : array();
    if (count($driverChanges) === 0 && trim((string)$row['driver_name']) !== '') {
        $driverStart = cpms_company_vehicle_default_change_ym($row);
        $driverChanges[] = array('effective_ym' => $driverStart, 'driver_name' => (string)$row['driver_name'], 'updated_at' => $now, 'updated_by' => $userLabel);
    }
    $row['driver_changes'] = $driverChanges;

    if (!isset($row['active']) || (string)$row['active'] === '') $row['active'] = 1;
    if (!isset($row['deleted_at'])) $row['deleted_at'] = '';
    if (!isset($row['deleted_by'])) $row['deleted_by'] = '';
    $row['updated_at'] = $now;
    $row['updated_by'] = $userLabel;
    return $row;
}}

if (!function_exists('cpms_company_vehicle_default_change_ym')) {
function cpms_company_vehicle_default_change_ym($vehicle) {
    $startYm = cpms_company_vehicle_finance_start_ym($vehicle);
    if ($startYm === '' || cpms_company_vehicle_compare_ym($startYm, '2026-01') < 0) $startYm = '2026-01';
    return $startYm;
}}

if (!function_exists('cpms_company_vehicle_save_vehicle')) {
function cpms_company_vehicle_save_vehicle($data, $user) {
    $all = cpms_company_vehicle_read_data();
    $vehicles = isset($all['vehicles']) && is_array($all['vehicles']) ? $all['vehicles'] : array();
    $id = isset($data['id']) ? trim((string)$data['id']) : '';
    $idx = cpms_company_vehicle_find_index($vehicles, $id);
    $existing = ($idx >= 0 && isset($vehicles[$idx]) && is_array($vehicles[$idx])) ? $vehicles[$idx] : null;
    $record = cpms_company_vehicle_prepare_record($data, $existing, $user);
    if (trim((string)$record['vehicle_number']) === '' && trim((string)$record['vehicle_name']) === '') {
        return array('ok' => false, 'message' => '차량명 또는 차량번호를 입력해주세요.');
    }
    if ($idx >= 0) $vehicles[$idx] = $record;
    else $vehicles[] = $record;
    $all['vehicles'] = array_values($vehicles);
    if (!cpms_company_vehicle_save_data($all, $user)) {
        return array('ok' => false, 'message' => cpms_company_vehicle_last_error() !== '' ? cpms_company_vehicle_last_error() : '회사차량을 저장하지 못했습니다.');
    }
    return array('ok' => true, 'record' => $record, 'message' => $idx >= 0 ? '회사차량이 수정되었습니다.' : '회사차량이 추가되었습니다.');
}}

if (!function_exists('cpms_company_vehicle_delete')) {
function cpms_company_vehicle_delete($id, $user) {
    $all = cpms_company_vehicle_read_data();
    $vehicles = isset($all['vehicles']) && is_array($all['vehicles']) ? $all['vehicles'] : array();
    $idx = cpms_company_vehicle_find_index($vehicles, $id);
    if ($idx < 0) return array('ok' => false, 'message' => '삭제할 회사차량을 찾지 못했습니다.');
    $vehicles[$idx]['deleted_at'] = date('Y-m-d H:i:s');
    $vehicles[$idx]['deleted_by'] = cpms_company_vehicle_user_label($user);
    $vehicles[$idx]['updated_at'] = $vehicles[$idx]['deleted_at'];
    $vehicles[$idx]['updated_by'] = $vehicles[$idx]['deleted_by'];
    $all['vehicles'] = $vehicles;
    if (!cpms_company_vehicle_save_data($all, $user)) return array('ok' => false, 'message' => '회사차량을 삭제하지 못했습니다.');
    return array('ok' => true, 'message' => '회사차량이 삭제되었습니다.');
}}

if (!function_exists('cpms_company_vehicle_update_payment')) {
function cpms_company_vehicle_update_payment($id, $year, $month, $amount, $user) {
    $all = cpms_company_vehicle_read_data();
    $vehicles = isset($all['vehicles']) && is_array($all['vehicles']) ? $all['vehicles'] : array();
    $idx = cpms_company_vehicle_find_index($vehicles, $id);
    if ($idx < 0) return array('ok' => false, 'message' => '월 납입금액을 수정할 차량을 찾지 못했습니다.');
    $ym = cpms_company_vehicle_normalize_ym($year, $month);
    $amount = cpms_company_vehicle_money_value($amount);
    $vehicle = cpms_company_vehicle_normalize_record_runtime($vehicles[$idx]);
    $changes = isset($vehicle['monthly_payment_changes']) ? $vehicle['monthly_payment_changes'] : array();
    $clean = array();
    foreach ($changes as $change) {
        if (!is_array($change) || !isset($change['effective_ym']) || (string)$change['effective_ym'] === $ym) continue;
        $clean[] = $change;
    }
    $clean[] = array('effective_ym' => $ym, 'amount' => $amount, 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => cpms_company_vehicle_user_label($user));
    usort($clean, 'cpms_company_vehicle_change_sort');
    $vehicle['monthly_payment_changes'] = $clean;
    if (cpms_company_vehicle_compare_ym($ym, isset($vehicle['baseline_ym']) ? $vehicle['baseline_ym'] : '2026-01') <= 0) {
        $vehicle['monthly_payment'] = $amount;
        $vehicle['baseline_monthly_payment'] = $amount;
    }
    $vehicle['updated_at'] = date('Y-m-d H:i:s');
    $vehicle['updated_by'] = cpms_company_vehicle_user_label($user);
    $vehicles[$idx] = $vehicle;
    $all['vehicles'] = $vehicles;
    if (!cpms_company_vehicle_save_data($all, $user)) return array('ok' => false, 'message' => '월 납입금액을 저장하지 못했습니다.');
    return array('ok' => true, 'message' => '월 납입금액이 적용월부터 수정되었습니다.');
}}

if (!function_exists('cpms_company_vehicle_update_driver')) {
function cpms_company_vehicle_update_driver($id, $year, $month, $driverName, $user) {
    $all = cpms_company_vehicle_read_data();
    $vehicles = isset($all['vehicles']) && is_array($all['vehicles']) ? $all['vehicles'] : array();
    $idx = cpms_company_vehicle_find_index($vehicles, $id);
    if ($idx < 0) return array('ok' => false, 'message' => '운전자를 수정할 차량을 찾지 못했습니다.');
    $ym = cpms_company_vehicle_normalize_ym($year, $month);
    $driverName = trim((string)$driverName);
    $vehicle = cpms_company_vehicle_normalize_record_runtime($vehicles[$idx]);
    $changes = isset($vehicle['driver_changes']) ? $vehicle['driver_changes'] : array();
    $clean = array();
    foreach ($changes as $change) {
        if (!is_array($change) || !isset($change['effective_ym']) || (string)$change['effective_ym'] === $ym) continue;
        $clean[] = $change;
    }
    $clean[] = array('effective_ym' => $ym, 'driver_name' => $driverName, 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => cpms_company_vehicle_user_label($user));
    usort($clean, 'cpms_company_vehicle_change_sort');
    $vehicle['driver_changes'] = $clean;
    $vehicle['driver_name'] = $driverName;
    if (cpms_company_vehicle_compare_ym($ym, isset($vehicle['baseline_ym']) ? $vehicle['baseline_ym'] : '2026-01') <= 0) {
        $vehicle['driver_name'] = $driverName;
    }
    $vehicle['updated_at'] = date('Y-m-d H:i:s');
    $vehicle['updated_by'] = cpms_company_vehicle_user_label($user);
    $vehicles[$idx] = $vehicle;
    $all['vehicles'] = $vehicles;
    if (!cpms_company_vehicle_save_data($all, $user)) return array('ok' => false, 'message' => '운전자를 저장하지 못했습니다.');
    return array('ok' => true, 'message' => '운전자가 적용월부터 수정되었습니다.');
}}

if (!function_exists('cpms_company_vehicle_inspection_interval_years')) {
function cpms_company_vehicle_inspection_interval_years($vehicle) {
    $start = isset($vehicle['inspection_start']) ? (string)$vehicle['inspection_start'] : '';
    $end = isset($vehicle['inspection_end']) ? (string)$vehicle['inspection_end'] : '';
    if ($start === '' || $end === '') return 1;
    $startTs = strtotime($start);
    $endTs = strtotime($end);
    if ($startTs === false || $endTs === false || $endTs <= $startTs) return 1;
    $days = (int)round(($endTs - $startTs) / 86400);
    return ($days >= 540) ? 2 : 1;
}}

if (!function_exists('cpms_company_vehicle_advance_inspection')) {
function cpms_company_vehicle_advance_inspection($id, $user) {
    $all = cpms_company_vehicle_read_data();
    $vehicles = isset($all['vehicles']) && is_array($all['vehicles']) ? $all['vehicles'] : array();
    $idx = cpms_company_vehicle_find_index($vehicles, $id);
    if ($idx < 0) return array('ok' => false, 'message' => '검사기간을 수정할 차량을 찾지 못했습니다.');
    $vehicle = cpms_company_vehicle_normalize_record_runtime($vehicles[$idx]);
    $interval = cpms_company_vehicle_inspection_interval_years($vehicle);
    $start = isset($vehicle['inspection_start']) ? (string)$vehicle['inspection_start'] : '';
    $end = isset($vehicle['inspection_end']) ? (string)$vehicle['inspection_end'] : '';
    if ($start === '' || $end === '') return array('ok' => false, 'message' => '검사유효기간 시작일과 종료일을 먼저 입력해주세요.');
    $newStart = cpms_company_vehicle_date_add_years($start, $interval);
    $newEnd = cpms_company_vehicle_date_add_years($end, $interval);
    if ($newStart === '' || $newEnd === '') return array('ok' => false, 'message' => '검사유효기간 날짜를 해석하지 못했습니다.');
    $vehicle['inspection_start'] = $newStart;
    $vehicle['inspection_end'] = $newEnd;
    $vehicle['inspection_period'] = cpms_company_vehicle_format_period($newStart, $newEnd);
    $vehicle['inspection_interval_years'] = $interval;
    $vehicle['updated_at'] = date('Y-m-d H:i:s');
    $vehicle['updated_by'] = cpms_company_vehicle_user_label($user);
    $vehicles[$idx] = $vehicle;
    $all['vehicles'] = $vehicles;
    if (!cpms_company_vehicle_save_data($all, $user)) return array('ok' => false, 'message' => '검사유효기간을 저장하지 못했습니다.');
    return array('ok' => true, 'message' => '검사유효기간이 ' . $interval . '년 뒤로 변경되었습니다.');
}}

if (!function_exists('cpms_company_vehicle_finance_start_ym')) {
function cpms_company_vehicle_finance_start_ym($vehicle) {
    $date = '';
    foreach (array('finance_start', 'schedule_start') as $key) {
        if (isset($vehicle[$key]) && trim((string)$vehicle[$key]) !== '') {
            $date = trim((string)$vehicle[$key]);
            break;
        }
    }
    return cpms_company_vehicle_ym_from_date($date);
}}

if (!function_exists('cpms_company_vehicle_finance_end_ym')) {
function cpms_company_vehicle_finance_end_ym($vehicle) {
    $date = '';
    foreach (array('finance_end', 'schedule_end') as $key) {
        if (isset($vehicle[$key]) && trim((string)$vehicle[$key]) !== '') {
            $date = trim((string)$vehicle[$key]);
            break;
        }
    }
    $ym = cpms_company_vehicle_ym_from_date($date);
    if ($ym !== '') return $ym;
    $startYm = cpms_company_vehicle_finance_start_ym($vehicle);
    $totalCount = isset($vehicle['total_count']) ? (int)$vehicle['total_count'] : 0;
    if ($startYm !== '' && $totalCount > 0) return cpms_company_vehicle_add_months($startYm, $totalCount - 1);
    $baseYm = isset($vehicle['baseline_ym']) ? (string)$vehicle['baseline_ym'] : '2026-01';
    $remaining = isset($vehicle['remaining_amount']) ? cpms_company_vehicle_money_value($vehicle['remaining_amount']) : 0.0;
    $payment = isset($vehicle['monthly_payment']) ? cpms_company_vehicle_money_value($vehicle['monthly_payment']) : 0.0;
    if ($remaining > 0 && $payment > 0) return cpms_company_vehicle_add_months($baseYm, (int)ceil($remaining / $payment));
    return '';
}}

if (!function_exists('cpms_company_vehicle_payment_for_month')) {
function cpms_company_vehicle_payment_for_month($vehicle, $ym) {
    $vehicle = cpms_company_vehicle_normalize_record_runtime($vehicle);
    $default = isset($vehicle['monthly_payment']) ? cpms_company_vehicle_money_value($vehicle['monthly_payment']) : 0.0;
    return cpms_company_vehicle_effective_change_value(isset($vehicle['monthly_payment_changes']) ? $vehicle['monthly_payment_changes'] : array(), $ym, 'amount', $default);
}}

if (!function_exists('cpms_company_vehicle_driver_for_month')) {
function cpms_company_vehicle_driver_for_month($vehicle, $ym) {
    $vehicle = cpms_company_vehicle_normalize_record_runtime($vehicle);
    $default = isset($vehicle['driver_name']) ? (string)$vehicle['driver_name'] : '';
    return cpms_company_vehicle_effective_change_value(isset($vehicle['driver_changes']) ? $vehicle['driver_changes'] : array(), $ym, 'driver_name', $default);
}}

if (!function_exists('cpms_company_vehicle_latest_driver_name')) {
function cpms_company_vehicle_latest_driver_name($vehicle) {
    $vehicle = cpms_company_vehicle_normalize_record_runtime($vehicle);
    $rowDriver = isset($vehicle['driver_name']) ? trim((string)$vehicle['driver_name']) : '';
    $rowUpdatedAt = isset($vehicle['updated_at']) ? trim((string)$vehicle['updated_at']) : '';
    $changes = isset($vehicle['driver_changes']) && is_array($vehicle['driver_changes']) ? $vehicle['driver_changes'] : array();
    $latestYm = '';
    $latestDriver = '';
    $latestUpdatedAt = '';
    foreach ($changes as $change) {
        if (!is_array($change)) continue;
        $changeYm = isset($change['effective_ym']) ? trim((string)$change['effective_ym']) : '';
        $changeDriver = isset($change['driver_name']) ? trim((string)$change['driver_name']) : '';
        $changeUpdatedAt = isset($change['updated_at']) ? trim((string)$change['updated_at']) : '';
        if ($changeDriver === '') continue;
        if ($changeYm === '') $changeYm = '0000-00';
        if ($latestDriver === '' || $changeYm >= $latestYm) {
            $latestYm = $changeYm;
            $latestDriver = $changeDriver;
            $latestUpdatedAt = $changeUpdatedAt;
        }
    }
    if ($rowDriver !== '' && ($latestDriver === '' || ($rowUpdatedAt !== '' && $latestUpdatedAt !== '' && $rowUpdatedAt > $latestUpdatedAt) || ($rowUpdatedAt !== '' && $latestUpdatedAt === ''))) {
        return $rowDriver;
    }
    if ($latestDriver !== '') return $latestDriver;
    return $rowDriver;
}}

if (!function_exists('cpms_company_vehicle_is_payment_month')) {
function cpms_company_vehicle_is_payment_month($vehicle, $ym) {
    $vehicle = cpms_company_vehicle_normalize_record_runtime($vehicle);
    if (isset($vehicle['deleted_at']) && trim((string)$vehicle['deleted_at']) !== '') return false;
    if (isset($vehicle['active']) && (string)$vehicle['active'] === '0') return false;
    $payment = cpms_company_vehicle_payment_for_month($vehicle, $ym);
    if ($payment <= 0) return false;
    $startYm = cpms_company_vehicle_finance_start_ym($vehicle);
    $endYm = cpms_company_vehicle_finance_end_ym($vehicle);
    if ($startYm === '') $startYm = '2026-01';
    if (cpms_company_vehicle_compare_ym($startYm, '2026-01') < 0) $startYm = '2026-01';
    if ($endYm === '') $endYm = substr($ym, 0, 4) . '-12';
    if (cpms_company_vehicle_compare_ym($ym, $startYm) < 0) return false;
    if (cpms_company_vehicle_compare_ym($ym, $endYm) > 0) return false;
    return true;
}}

if (!function_exists('cpms_company_vehicle_remaining_for_month')) {
function cpms_company_vehicle_remaining_for_month($vehicle, $ym) {
    $vehicle = cpms_company_vehicle_normalize_record_runtime($vehicle);
    $baseYm = isset($vehicle['baseline_ym']) ? (string)$vehicle['baseline_ym'] : '2026-01';
    if (!cpms_company_vehicle_ym_valid($baseYm)) $baseYm = '2026-01';
    $balance = isset($vehicle['remaining_amount']) ? cpms_company_vehicle_money_value($vehicle['remaining_amount']) : 0.0;
    $cmp = cpms_company_vehicle_compare_ym($ym, $baseYm);
    if ($cmp > 0) {
        $cursor = cpms_company_vehicle_add_months($baseYm, 1);
        while ($cursor !== '' && cpms_company_vehicle_compare_ym($cursor, $ym) <= 0) {
            $balance -= cpms_company_vehicle_payment_for_month($vehicle, $cursor);
            $cursor = cpms_company_vehicle_add_months($cursor, 1);
        }
    } else if ($cmp < 0) {
        $cursor2 = cpms_company_vehicle_add_months($ym, 1);
        while ($cursor2 !== '' && cpms_company_vehicle_compare_ym($cursor2, $baseYm) <= 0) {
            $balance += cpms_company_vehicle_payment_for_month($vehicle, $cursor2);
            $cursor2 = cpms_company_vehicle_add_months($cursor2, 1);
        }
    }
    if ($balance < 0) $balance = 0.0;
    return round($balance, 0);
}}

if (!function_exists('cpms_company_vehicle_schedule_for_year')) {
function cpms_company_vehicle_schedule_for_year($vehicle, $year) {
    $rows = array();
    $year = (int)$year;
    if ($year < 2026) $year = 2026;
    for ($m = 1; $m <= 12; $m++) {
        $ym = sprintf('%04d-%02d', $year, $m);
        if (!cpms_company_vehicle_is_payment_month($vehicle, $ym)) continue;
        $rows[] = array(
            'ym' => $ym,
            'year' => sprintf('%04d', $year),
            'month' => sprintf('%02d', $m),
            'payment_amount' => cpms_company_vehicle_payment_for_month($vehicle, $ym),
            'remaining_amount' => cpms_company_vehicle_remaining_for_month($vehicle, $ym),
            'driver_name' => cpms_company_vehicle_driver_for_month($vehicle, $ym),
        );
    }
    return $rows;
}}

if (!function_exists('cpms_company_vehicle_month_items')) {
function cpms_company_vehicle_month_items($year, $month) {
    $ym = cpms_company_vehicle_normalize_ym($year, $month);
    $vehicles = cpms_company_vehicle_load_all(false);
    $items = array();
    foreach ($vehicles as $vehicle) {
        if (!cpms_company_vehicle_is_payment_month($vehicle, $ym)) continue;
        $amount = cpms_company_vehicle_payment_for_month($vehicle, $ym);
        if ($amount <= 0) continue;
        $vehicleName = isset($vehicle['vehicle_name']) ? trim((string)$vehicle['vehicle_name']) : '';
        $vehicleNumber = isset($vehicle['vehicle_number']) ? trim((string)$vehicle['vehicle_number']) : '';
        $title = trim($vehicleName . ' ' . $vehicleNumber);
        if ($title === '') $title = '회사차량';
        $items[] = array(
            'id' => 'CVM-' . (isset($vehicle['id']) ? (string)$vehicle['id'] : '') . '-' . $ym,
            'company_vehicle_id' => isset($vehicle['id']) ? (string)$vehicle['id'] : '',
            'category' => 'vehicles',
            'category_name' => '회사차량',
            'year' => substr($ym, 0, 4),
            'month' => substr($ym, 5, 2),
            'title' => $title,
            'amount' => $amount,
            'employee_name' => cpms_company_vehicle_driver_for_month($vehicle, $ym),
            'vehicle_name' => $vehicleName,
            'vehicle_number' => $vehicleNumber,
            'driver_name' => cpms_company_vehicle_driver_for_month($vehicle, $ym),
            'remaining_amount' => cpms_company_vehicle_remaining_for_month($vehicle, $ym),
            'source' => 'company_vehicle_schedule',
            'deleted_at' => '',
        );
    }
    return $items;
}}

if (!function_exists('cpms_company_vehicle_year_summary')) {
function cpms_company_vehicle_year_summary($year) {
    $year = (int)$year;
    if ($year < 2026) $year = 2026;
    $vehicles = cpms_company_vehicle_load_all(false);
    $months = array();
    $total = 0.0;
    for ($m = 1; $m <= 12; $m++) {
        $ym = sprintf('%04d-%02d', $year, $m);
        $amount = 0.0;
        foreach ($vehicles as $vehicle) {
            if (cpms_company_vehicle_is_payment_month($vehicle, $ym)) $amount += cpms_company_vehicle_payment_for_month($vehicle, $ym);
        }
        $months[] = array('ym' => $ym, 'month' => sprintf('%02d', $m), 'amount' => $amount);
        $total += $amount;
    }
    return array('year' => $year, 'total' => $total, 'months' => $months, 'vehicle_count' => count($vehicles));
}}

if (!function_exists('cpms_company_vehicle_year_options')) {
function cpms_company_vehicle_year_options($selectedYear) {
    $selectedYear = (int)$selectedYear;
    if ($selectedYear < 2026) $selectedYear = 2026;
    $maxYear = max(2026, (int)date('Y'), $selectedYear);
    foreach (cpms_company_vehicle_load_all(false) as $vehicle) {
        $endYm = cpms_company_vehicle_finance_end_ym($vehicle);
        if ($endYm !== '') $maxYear = max($maxYear, (int)substr($endYm, 0, 4));
    }
    $years = array();
    for ($y = 2026; $y <= $maxYear; $y++) $years[] = $y;
    return $years;
}}

if (!function_exists('cpms_company_vehicle_zip_read')) {
function cpms_company_vehicle_zip_read($zip, $name) {
    $idx = $zip->locateName($name);
    if ($idx === false) return '';
    $data = $zip->getFromIndex($idx);
    return ($data !== false) ? $data : '';
}}

if (!function_exists('cpms_company_vehicle_xlsx_shared_strings')) {
function cpms_company_vehicle_xlsx_shared_strings($zip) {
    $shared = array();
    $xml = cpms_company_vehicle_zip_read($zip, 'xl/sharedStrings.xml');
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

if (!function_exists('cpms_company_vehicle_xlsx_sheet_path')) {
function cpms_company_vehicle_xlsx_sheet_path($zip, $preferredName) {
    $workbookXml = cpms_company_vehicle_zip_read($zip, 'xl/workbook.xml');
    $relsXml = cpms_company_vehicle_zip_read($zip, 'xl/_rels/workbook.xml.rels');
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
        if ($name === $preferredName || trim($preferredName) !== '' && strpos($name, $preferredName) !== false) $preferred = $row;
    }
    $chosen = $preferred !== null ? $preferred : $first;
    if ($chosen === null) return array('path' => 'xl/worksheets/sheet1.xml', 'name' => '');
    $target3 = str_replace('\\', '/', (string)$chosen['target']);
    if (substr($target3, 0, 1) === '/') $target3 = ltrim($target3, '/');
    if (strpos($target3, 'xl/') !== 0) $target3 = 'xl/' . $target3;
    return array('path' => $target3, 'name' => (string)$chosen['name']);
}}

if (!function_exists('cpms_company_vehicle_xlsx_col_index')) {
function cpms_company_vehicle_xlsx_col_index($cellRef) {
    $letters = preg_replace('/[^A-Z]/', '', strtoupper((string)$cellRef));
    if ($letters === '') return 0;
    $num = 0;
    for ($i = 0; $i < strlen($letters); $i++) $num = $num * 26 + (ord($letters[$i]) - 64);
    return (int)$num;
}}

if (!function_exists('cpms_company_vehicle_xlsx_cell_value')) {
function cpms_company_vehicle_xlsx_cell_value($cell, $sharedStrings) {
    $t = isset($cell['t']) ? (string)$cell['t'] : '';
    if ($t === 's') {
        $idx = isset($cell->v) ? (int)$cell->v : -1;
        return ($idx >= 0 && isset($sharedStrings[$idx])) ? trim((string)$sharedStrings[$idx]) : '';
    }
    if ($t === 'inlineStr' && isset($cell->is)) {
        $text = '';
        if (isset($cell->is->t)) $text = (string)$cell->is->t;
        else if (isset($cell->is->r)) foreach ($cell->is->r as $run) if (isset($run->t)) $text .= (string)$run->t;
        return trim($text);
    }
    return isset($cell->v) ? trim((string)$cell->v) : '';
}}

if (!function_exists('cpms_company_vehicle_xlsx_rows')) {
function cpms_company_vehicle_xlsx_rows($path, $preferredSheetName, $maxRows) {
    if (!is_file($path)) return array('ok' => false, 'message' => '업로드 파일을 찾을 수 없습니다.', 'rows' => array());
    if (!class_exists('ZipArchive')) return array('ok' => false, 'message' => '서버에 ZipArchive 확장이 없어 .xlsx를 읽을 수 없습니다.', 'rows' => array());
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return array('ok' => false, 'message' => '엑셀 파일을 열 수 없습니다.', 'rows' => array());
    $shared = cpms_company_vehicle_xlsx_shared_strings($zip);
    $sheetInfo = cpms_company_vehicle_xlsx_sheet_path($zip, $preferredSheetName);
    $sheetXml = cpms_company_vehicle_zip_read($zip, $sheetInfo['path']);
    if ($sheetXml === '') {
        $zip->close();
        return array('ok' => false, 'message' => '법인차량 시트 데이터를 찾지 못했습니다.', 'rows' => array());
    }
    $sheet = @simplexml_load_string($sheetXml);
    if (!$sheet || !isset($sheet->sheetData)) {
        $zip->close();
        return array('ok' => false, 'message' => '법인차량 시트를 해석하지 못했습니다.', 'rows' => array());
    }
    $records = array();
    $count = 0;
    foreach ($sheet->sheetData->row as $rowNode) {
        $count++;
        if ($count > (int)$maxRows) break;
        $rowNumber = isset($rowNode['r']) ? (int)$rowNode['r'] : $count;
        $cells = array();
        for ($i = 0; $i < 40; $i++) $cells[$i] = '';
        if (isset($rowNode->c)) {
            foreach ($rowNode->c as $cell) {
                $ref = isset($cell['r']) ? (string)$cell['r'] : '';
                $col = cpms_company_vehicle_xlsx_col_index($ref);
                if ($col >= 1 && $col <= 40) $cells[$col - 1] = cpms_company_vehicle_xlsx_cell_value($cell, $shared);
            }
        }
        $records[] = array('row_number' => $rowNumber, 'cells' => $cells);
    }
    $zip->close();
    return array('ok' => true, 'message' => '엑셀을 읽었습니다.', 'sheet_name' => isset($sheetInfo['name']) ? $sheetInfo['name'] : '', 'rows' => $records, 'source_row_count' => count($records));
}}

if (!function_exists('cpms_company_vehicle_row_cell')) {
function cpms_company_vehicle_row_cell($row, $idx) {
    if (!is_array($row) || !isset($row['cells']) || !is_array($row['cells'])) return '';
    return isset($row['cells'][(int)$idx]) ? trim((string)$row['cells'][(int)$idx]) : '';
}}

if (!function_exists('cpms_company_vehicle_header_key')) {
function cpms_company_vehicle_header_key($value) {
    $text = trim((string)$value);
    if ($text === '') return '';
    $text = preg_replace('/\s+/u', '', $text);
    if ($text === '순서') return 'sequence';
    if ($text === '차량명') return 'vehicle_name';
    if ($text === '차량번호') return 'vehicle_number';
    if ($text === '취득일') return 'acquired_at';
    if ($text === '정') return 'primary_manager';
    if ($text === '부') return 'secondary_manager';
    if ($text === '운전자') return 'driver_name';
    if ($text === '현장') return 'site_name';
    if ($text === '검사유효기간') return 'inspection_period';
    if ($text === '법인번호(검사)' || $text === '법인번호검사') return 'corporate_number';
    if ($text === '보험사') return 'insurer';
    if ($text === '보험료') return 'insurance_premium';
    if ($text === '보험기간') return 'insurance_period';
    if ($text === '할부기간') return 'finance_period';
    if ($text === '연령한정') return 'age_limit';
    if ($text === '운전자한정') return 'driver_limit';
    if ($text === '차량구분') return 'vehicle_type';
    if ($text === '비고') return 'note';
    if ($text === '스케줄표기간') return 'schedule_period';
    if ($text === '이자율') return 'interest_rate';
    if ($text === '납입횟수') return 'paid_count';
    if ($text === '총횟수') return 'total_count';
    if ($text === '납입일') return 'payment_day';
    if ($text === '총액') return 'total_amount';
    if ($text === '잔여금액') return 'remaining_amount';
    if ($text === '월납입금액') return 'monthly_payment';
    if ($text === '보험료(이전)' || $text === '보험료이전') return 'previous_insurance_premium';
    if ($text === '하이패스단말기/카드여부') return 'toll_device_card';
    if ($text === '영업사원') return 'sales_person';
    if ($text === '해지시위약금') return 'cancellation_penalty';
    return '';
}}

if (!function_exists('cpms_company_vehicle_detect_header_map')) {
function cpms_company_vehicle_detect_header_map($rows) {
    $default = cpms_company_vehicle_excel_columns();
    if (!is_array($rows)) return array('map' => $default, 'header_index' => 1);
    for ($i = 0; $i < count($rows); $i++) {
        if ($i > 20) break;
        $map = array();
        $matches = 0;
        for ($c = 0; $c < 40; $c++) {
            $key = cpms_company_vehicle_header_key(cpms_company_vehicle_row_cell($rows[$i], $c));
            if ($key !== '') {
                $map[$c] = $key;
                $matches++;
            }
        }
        if ($matches >= 10) {
            if (!isset($map[23])) $map[23] = 'principal_amount';
            if (!isset($map[28])) $map[28] = 'extra_note';
            return array('map' => $map, 'header_index' => $i);
        }
    }
    return array('map' => $default, 'header_index' => 1);
}}

if (!function_exists('cpms_company_vehicle_parse_xlsx')) {
function cpms_company_vehicle_parse_xlsx($path, $baseYear, $baseMonth) {
    $baseYm = cpms_company_vehicle_normalize_ym($baseYear, $baseMonth);
    $read = cpms_company_vehicle_xlsx_rows($path, '법인차량', 1000);
    if (empty($read['ok'])) return $read;
    $rows = isset($read['rows']) && is_array($read['rows']) ? $read['rows'] : array();
    $header = cpms_company_vehicle_detect_header_map($rows);
    $map = isset($header['map']) && is_array($header['map']) ? $header['map'] : cpms_company_vehicle_excel_columns();
    $headerIndex = isset($header['header_index']) ? (int)$header['header_index'] : 1;
    $vehicles = array();
    $errors = array();
    for ($i = $headerIndex + 1; $i < count($rows); $i++) {
        $row = $rows[$i];
        $data = array('base_year' => substr($baseYm, 0, 4), 'base_month' => substr($baseYm, 5, 2));
        foreach ($map as $col => $key) {
            $data[$key] = cpms_company_vehicle_row_cell($row, $col);
        }
        $vehicleName = isset($data['vehicle_name']) ? trim((string)$data['vehicle_name']) : '';
        $vehicleNumber = isset($data['vehicle_number']) ? trim((string)$data['vehicle_number']) : '';
        if ($vehicleName === '' && $vehicleNumber === '') continue;
        if (strpos($vehicleName, '합계') !== false || strpos($vehicleName, '합계 금액') !== false) continue;
        $record = cpms_company_vehicle_prepare_record($data, null, 'excel');
        $record['source_row_number'] = isset($row['row_number']) ? (int)$row['row_number'] : ($i + 1);
        $record['imported_from_excel'] = 1;
        $vehicles[] = $record;
    }
    if (count($vehicles) === 0) $errors[] = '저장할 회사차량 행을 찾지 못했습니다.';
    return array(
        'ok' => count($vehicles) > 0,
        'message' => count($vehicles) > 0 ? '회사차량 엑셀을 읽었습니다.' : '회사차량 엑셀에서 차량 데이터를 찾지 못했습니다.',
        'sheet_name' => isset($read['sheet_name']) ? (string)$read['sheet_name'] : '',
        'source_row_count' => isset($read['source_row_count']) ? (int)$read['source_row_count'] : 0,
        'base_ym' => $baseYm,
        'vehicles' => $vehicles,
        'errors' => $errors,
    );
}}

if (!function_exists('cpms_company_vehicle_tmp_root')) {
function cpms_company_vehicle_tmp_root() {
    $root = function_exists('cpms_storage_root') ? cpms_storage_root() : dirname(dirname(__DIR__)) . '/storage';
    return $root . '/tmp/company_vehicle_preview';
}}

if (!function_exists('cpms_company_vehicle_new_token')) {
function cpms_company_vehicle_new_token() {
    if (function_exists('openssl_random_pseudo_bytes')) {
        $bytes = openssl_random_pseudo_bytes(16);
        if ($bytes !== false) return bin2hex($bytes);
    }
    return md5(uniqid('', true) . mt_rand());
}}

if (!function_exists('cpms_company_vehicle_ensure_dir')) {
function cpms_company_vehicle_ensure_dir($dir) {
    if (function_exists('cpms_ensure_dir')) return cpms_ensure_dir($dir);
    if (is_dir($dir)) return true;
    return @mkdir($dir, 0777, true);
}}

if (!function_exists('cpms_company_vehicle_create_preview')) {
function cpms_company_vehicle_create_preview($baseYear, $baseMonth, $file, $user) {
    $baseYm = cpms_company_vehicle_normalize_ym($baseYear, $baseMonth);
    if (!is_array($file) || !isset($file['error']) || (int)$file['error'] === UPLOAD_ERR_NO_FILE) {
        return array('ok' => false, 'message' => '회사차량 엑셀 파일을 선택해주세요.');
    }
    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        return array('ok' => false, 'message' => '파일 업로드 오류가 발생했습니다. 코드: ' . (int)$file['error']);
    }
    $originalName = isset($file['name']) ? trim((string)$file['name']) : 'company_vehicle.xlsx';
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext !== 'xlsx') return array('ok' => false, 'message' => '.xlsx 파일만 업로드할 수 있습니다.');
    $tmpName = isset($file['tmp_name']) ? trim((string)$file['tmp_name']) : '';
    if ($tmpName === '' || !is_file($tmpName)) return array('ok' => false, 'message' => '업로드 임시 파일을 찾을 수 없습니다.');
    $parsed = cpms_company_vehicle_parse_xlsx($tmpName, substr($baseYm, 0, 4), substr($baseYm, 5, 2));
    if (empty($parsed['ok'])) return $parsed;
    $token = cpms_company_vehicle_new_token();
    $tmpDir = cpms_company_vehicle_tmp_root();
    if (!cpms_company_vehicle_ensure_dir($tmpDir)) return array('ok' => false, 'message' => '회사차량 업로드 임시 폴더를 만들 수 없습니다.');
    $localPath = rtrim($tmpDir, '/\\') . '/' . $token . '.xlsx';
    $moved = false;
    if (function_exists('move_uploaded_file')) $moved = @move_uploaded_file($tmpName, $localPath);
    if (!$moved) $moved = @copy($tmpName, $localPath);
    if (!$moved) return array('ok' => false, 'message' => '업로드 파일을 임시 보관하지 못했습니다.');
    if (!isset($_SESSION['_company_vehicle_preview']) || !is_array($_SESSION['_company_vehicle_preview'])) $_SESSION['_company_vehicle_preview'] = array();
    $_SESSION['_company_vehicle_preview'][$token] = array(
        'token' => $token,
        'created_at' => time(),
        'base_ym' => $baseYm,
        'uploaded_original_name' => $originalName,
        'temp_path' => $localPath,
        'uploaded_by' => cpms_company_vehicle_user_label($user),
        'parsed' => $parsed,
    );
    return array('ok' => true, 'message' => '회사차량 미리보기가 생성되었습니다.', 'token' => $token, 'base_ym' => $baseYm, 'preview' => $parsed);
}}

if (!function_exists('cpms_company_vehicle_get_preview')) {
function cpms_company_vehicle_get_preview($token) {
    $token = trim((string)$token);
    if ($token === '' || !isset($_SESSION['_company_vehicle_preview'][$token]) || !is_array($_SESSION['_company_vehicle_preview'][$token])) return null;
    $preview = $_SESSION['_company_vehicle_preview'][$token];
    if (!isset($preview['created_at']) || (time() - (int)$preview['created_at']) > 7200) {
        if (isset($preview['temp_path']) && is_file($preview['temp_path'])) @unlink($preview['temp_path']);
        unset($_SESSION['_company_vehicle_preview'][$token]);
        return null;
    }
    return $preview;
}}

if (!function_exists('cpms_company_vehicle_clear_preview')) {
function cpms_company_vehicle_clear_preview($token) {
    $preview = cpms_company_vehicle_get_preview($token);
    if (is_array($preview) && isset($preview['temp_path']) && is_file($preview['temp_path'])) @unlink($preview['temp_path']);
    if (isset($_SESSION['_company_vehicle_preview'][$token])) unset($_SESSION['_company_vehicle_preview'][$token]);
}}

if (!function_exists('cpms_company_vehicle_save_uploaded_snapshot')) {
function cpms_company_vehicle_save_uploaded_snapshot($preview, $user) {
    if (!is_array($preview) || !isset($preview['parsed']) || !is_array($preview['parsed'])) {
        return array('ok' => false, 'message' => '회사차량 미리보기 데이터를 찾지 못했습니다.');
    }
    $parsed = $preview['parsed'];
    $newVehicles = isset($parsed['vehicles']) && is_array($parsed['vehicles']) ? $parsed['vehicles'] : array();
    if (count($newVehicles) === 0) return array('ok' => false, 'message' => '저장할 회사차량 데이터가 없습니다.');
    $oldData = cpms_company_vehicle_read_data();
    $oldVehicles = isset($oldData['vehicles']) && is_array($oldData['vehicles']) ? $oldData['vehicles'] : array();
    $oldByNumber = array();
    foreach ($oldVehicles as $oldVehicle) {
        if (!is_array($oldVehicle)) continue;
        $norm = isset($oldVehicle['vehicle_number_normalized']) ? (string)$oldVehicle['vehicle_number_normalized'] : cpms_normalize_vehicle_number(isset($oldVehicle['vehicle_number']) ? $oldVehicle['vehicle_number'] : '');
        if ($norm !== '' && !isset($oldByNumber[$norm])) $oldByNumber[$norm] = $oldVehicle;
    }
    $savedVehicles = array();
    foreach ($newVehicles as $vehicle) {
        if (!is_array($vehicle)) continue;
        $norm2 = isset($vehicle['vehicle_number_normalized']) ? (string)$vehicle['vehicle_number_normalized'] : cpms_normalize_vehicle_number(isset($vehicle['vehicle_number']) ? $vehicle['vehicle_number'] : '');
        if ($norm2 !== '' && isset($oldByNumber[$norm2]) && is_array($oldByNumber[$norm2])) {
            $old = $oldByNumber[$norm2];
            foreach (array('id', 'created_at', 'created_by') as $keepKey) {
                if (isset($old[$keepKey])) $vehicle[$keepKey] = $old[$keepKey];
            }
        }
        $vehicle['imported_at'] = date('Y-m-d H:i:s');
        $vehicle['imported_by'] = cpms_company_vehicle_user_label($user);
        $vehicle['uploaded_original_name'] = isset($preview['uploaded_original_name']) ? (string)$preview['uploaded_original_name'] : '';
        $savedVehicles[] = cpms_company_vehicle_prepare_record($vehicle, $vehicle, $user);
    }
    $data = array(
        'version' => 1,
        'base_ym' => isset($preview['base_ym']) ? (string)$preview['base_ym'] : (isset($parsed['base_ym']) ? (string)$parsed['base_ym'] : ''),
        'uploaded_original_name' => isset($preview['uploaded_original_name']) ? (string)$preview['uploaded_original_name'] : '',
        'uploaded_at' => date('Y-m-d H:i:s'),
        'uploaded_by' => cpms_company_vehicle_user_label($user),
        'sheet_name' => isset($parsed['sheet_name']) ? (string)$parsed['sheet_name'] : '',
        'source_row_count' => isset($parsed['source_row_count']) ? (int)$parsed['source_row_count'] : 0,
        'vehicles' => $savedVehicles,
    );
    if (!cpms_company_vehicle_save_data($data, $user)) {
        return array('ok' => false, 'message' => cpms_company_vehicle_last_error() !== '' ? cpms_company_vehicle_last_error() : '회사차량 데이터를 저장하지 못했습니다.');
    }
    return array('ok' => true, 'message' => '회사차량 데이터가 저장되었습니다.', 'data' => $data);
}}

if (!function_exists('cpms_company_vehicle_confirm_preview')) {
function cpms_company_vehicle_confirm_preview($token, $user) {
    $preview = cpms_company_vehicle_get_preview($token);
    if (!is_array($preview)) return array('ok' => false, 'message' => '확정할 회사차량 미리보기를 찾지 못했습니다.');
    $result = cpms_company_vehicle_save_uploaded_snapshot($preview, $user);
    if (!empty($result['ok'])) cpms_company_vehicle_clear_preview($token);
    return $result;
}}
