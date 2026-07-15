<?php
/** Attendance common helpers */
function attendance_text($encoded){
    return urldecode($encoded);
}
function attendance_timezone(){
    $tz = 'Asia/Seoul';
    if (function_exists('date_default_timezone_get')) {
        $cur = (string)date_default_timezone_get();
        if ($cur !== $tz && function_exists('date_default_timezone_set')) {
            @date_default_timezone_set($tz);
        }
    }
    return new DateTimeZone($tz);
}
function attendance_now(){
    $dt = new DateTime('now', new DateTimeZone('Asia/Seoul'));
    return $dt->format('Y-m-d H:i:s');
}
function attendance_today(){
    $dt = new DateTime('now', new DateTimeZone('Asia/Seoul'));
    return $dt->format('Y-m-d');
}
function attendance_datetime_date_part($value){
    $value = trim((string)$value);
    if($value === '') return '';
    return substr($value, 0, 10);
}
function attendance_normalize_position_name($value){
    $value = trim((string)$value);
    if($value === '') return '';
    return str_replace(array(' ', "\t", "\r", "\n", '[', ']', '(', ')', '{', '}'), '', $value);
}
function attendance_late_cutoff_time($position){
    $position = attendance_normalize_position_name($position);
    if($position === attendance_text('%EB%B6%80%EC%82%AC%EC%9E%A5')) return '08:30';
    return '08:00';
}
function attendance_is_late_check_in_value($checkIn, $position){
    $checkIn = trim((string)$checkIn);
    if($checkIn === '') return false;
    if(strlen($checkIn) >= 16 && preg_match('/^\d{4}-\d{2}-\d{2}[ T]/', $checkIn)){
        $time = substr($checkIn, 11, 5);
    }else if(strlen($checkIn) >= 5){
        $time = substr($checkIn, 0, 5);
    }else{
        return false;
    }
    return (strcmp($time, attendance_late_cutoff_time($position)) > 0);
}
if (!function_exists('attendance_request_status_label')) {
function attendance_request_status_label($status){
    if ($status === 'pending') return attendance_text('%EC%8A%B9%EC%9D%B8%EB%8C%80%EA%B8%B0');
    if ($status === 'approved') return attendance_text('%EC%8A%B9%EC%9D%B8%EC%99%84%EB%A3%8C');
    if ($status === 'rejected') return attendance_text('%EB%B0%98%EB%A0%A4');
    return (string)$status;
}}
if (!function_exists('attendance_request_type_label')) {
function attendance_request_type_label($type){
    if ($type === 'check_in') return attendance_text('%EC%B6%9C%EA%B7%BC%EC%8B%9C%EA%B0%84%20%EC%88%98%EC%A0%95');
    if ($type === 'check_out') return attendance_text('%ED%87%B4%EA%B7%BC%EC%8B%9C%EA%B0%84%20%EC%88%98%EC%A0%95');
    if ($type === 'both') return attendance_text('%EC%B6%9C%ED%87%B4%EA%B7%BC%20%EC%88%98%EC%A0%95');
    return (string)$type;
}}
function attendance_record_datetime_matches_work_date($row){
    if(!$row || !isset($row['work_date'])) return true;
    $wd = (string)$row['work_date'];
    if(isset($row['check_in']) && trim((string)$row['check_in']) !== ''){
        if(attendance_datetime_date_part($row['check_in']) !== $wd) return false;
    }
    if(isset($row['check_out']) && trim((string)$row['check_out']) !== ''){
        if(attendance_datetime_date_part($row['check_out']) !== $wd) return false;
    }
    return true;
}
function attendance_today_record($pdo, $employeeId){
    $employeeId = (int)$employeeId;
    if(!$pdo || $employeeId <= 0) return null;
    try{
        $today = attendance_today();
        $st = $pdo->prepare("SELECT * FROM cpms_attendance_records WHERE employee_id = :employee_id AND work_date = :today LIMIT 1");
        $st->execute(array(':employee_id'=>$employeeId, ':today'=>$today));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if(!$row) return null;
        if(!attendance_record_datetime_matches_work_date($row)){
            error_log('[attendance_today_record] date mismatch record_id='.(isset($row['id'])?$row['id']:'0').' work_date='.(isset($row['work_date'])?$row['work_date']:'').' check_in='.(isset($row['check_in'])?$row['check_in']:'').' check_out='.(isset($row['check_out'])?$row['check_out']:''));
            return null;
        }
        return $row;
    }catch(Exception $e){
        error_log('[attendance_today_record] '.$e->getMessage());
        return null;
    }
}
function attendance_employee_id($pdo){
    $email = (string)\App\Core\Auth::userEmail();
    if(!$pdo || $email === '') return 0;
    try{
        $st = $pdo->prepare("SELECT id FROM employees WHERE email=:em LIMIT 1");
        $st->bindValue(':em', $email);
        $st->execute();
        return (int)$st->fetchColumn();
    }catch(Exception $e){
        return 0;
    }
}
function attendance_employee_position($pdo, $employeeId){
    $employeeId = (int)$employeeId;
    if(!$pdo || $employeeId <= 0) return '';
    if(!attendance_table_exists($pdo, 'employees')) return '';
    if(!attendance_table_column_exists_for_settings($pdo, 'employees', 'position')) return '';
    try{
        $st = $pdo->prepare("SELECT position FROM employees WHERE id=:employee_id LIMIT 1");
        $st->execute(array(':employee_id'=>$employeeId));
        return trim((string)$st->fetchColumn());
    }catch(Exception $e){
        return '';
    }
}
function attendance_is_manager(){
    return \App\Core\Auth::isMaster() || \App\Core\Auth::canManageEmployees();
}
function attendance_normalize_department_name($value){
    $value = trim((string)$value);
    if ($value === '') return '';
    return str_replace(array(' ', "\t", "\r", "\n", '[', ']', '(', ')', '{', '}', '-', '_', '/', '\\'), '', $value);
}
function attendance_is_settings_department_value($value){
    $dept = attendance_normalize_department_name($value);
    if ($dept === '') return false;
    $allowed = array(
        attendance_normalize_department_name(attendance_text('%EA%B4%80%EB%A6%AC')),
        attendance_normalize_department_name(attendance_text('%EA%B4%80%EB%A6%AC%EB%B6%80')),
        attendance_normalize_department_name(attendance_text('%EA%B4%80%EB%A6%AC%ED%8C%80')),
        attendance_normalize_department_name(attendance_text('%EA%B0%9C%EB%B0%9C')),
        attendance_normalize_department_name(attendance_text('%EA%B0%9C%EB%B0%9C%EB%B6%80')),
        attendance_normalize_department_name(attendance_text('%EA%B0%9C%EB%B0%9C%ED%8C%80'))
    );
    return in_array($dept, $allowed, true);
}
if (!function_exists('attendance_is_development_department_value')) {
function attendance_is_development_department_value($value){
    $dept = attendance_normalize_department_name($value);
    if ($dept === '') return false;
    $allowed = array(
        attendance_normalize_department_name(attendance_text('%EA%B0%9C%EB%B0%9C')),
        attendance_normalize_department_name(attendance_text('%EA%B0%9C%EB%B0%9C%EB%B6%80')),
        attendance_normalize_department_name(attendance_text('%EA%B0%9C%EB%B0%9C%ED%8C%80')),
        attendance_normalize_department_name(attendance_text('%EA%B0%9C%EB%B0%9C%EB%B6%80%EC%84%9C'))
    );
    return in_array($dept, $allowed, true);
}}
if (!function_exists('attendance_is_representative_value')) {
function attendance_is_representative_value($role, $position, $name){
    $role = strtolower(attendance_normalize_department_name($role));
    if ($role === 'ceo') return true;
    $values = array((string)$role, (string)$position, (string)$name);
    $needle = attendance_text('%EB%8C%80%ED%91%9C');
    for ($i = 0; $i < count($values); $i++) {
        if ($needle !== '' && strpos($values[$i], $needle) !== false) return true;
    }
    return false;
}}
function attendance_is_blocked_executive_value($role, $position, $name){
    $role = strtolower(trim((string)$role));
    if ($role === 'executive') return true;
    $values = array((string)$position, (string)$name);
    $blocked = array(
        attendance_text('%EB%8C%80%ED%91%9C'),
        attendance_text('%EB%B6%80%EC%82%AC%EC%9E%A5')
    );
    for ($i = 0; $i < count($values); $i++) {
        for ($j = 0; $j < count($blocked); $j++) {
            if ($blocked[$j] !== '' && strpos($values[$i], $blocked[$j]) !== false) return true;
        }
    }
    return false;
}
function attendance_can_manage_settings($pdo){
    if (!\App\Core\Auth::check()) return false;

    $email = (string)\App\Core\Auth::userEmail();
    if ($pdo && $email !== '') {
        try {
            $positionSelect = attendance_table_exists($pdo, 'employees') && attendance_table_column_exists_for_settings($pdo, 'employees', 'position') ? 'position' : "'' AS position";
            $roleSelect = attendance_table_exists($pdo, 'employees') && attendance_table_column_exists_for_settings($pdo, 'employees', 'role') ? 'role' : "'' AS role";
            $st = $pdo->prepare("SELECT name, department, " . $positionSelect . ", " . $roleSelect . " FROM employees WHERE email=:email LIMIT 1");
            $st->execute(array(':email' => $email));
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $role = isset($row['role']) ? $row['role'] : '';
                $position = isset($row['position']) ? $row['position'] : '';
                $name = isset($row['name']) ? $row['name'] : '';
                $department = isset($row['department']) ? $row['department'] : '';
                if (attendance_is_blocked_executive_value($role, $position, $name)) return false;
                return attendance_is_settings_department_value($department);
            }
        } catch (Exception $e) {
        }
    }

    $user = \App\Core\Auth::user();
    $department = (is_array($user) && isset($user['department'])) ? $user['department'] : \App\Core\Auth::userDepartment();
    $position = (is_array($user) && isset($user['position'])) ? $user['position'] : \App\Core\Auth::userPosition();
    $name = (is_array($user) && isset($user['name'])) ? $user['name'] : \App\Core\Auth::userName();
    if (method_exists('App\\Core\\Auth', 'userStoredRole')) {
        $role = \App\Core\Auth::userStoredRole();
    } else {
        $role = (is_array($user) && isset($user['role'])) ? $user['role'] : '';
    }
    if (attendance_is_blocked_executive_value($role, $position, $name)) return false;
    return attendance_is_settings_department_value($department);
}
if (!function_exists('attendance_can_edit_monthly_records')) {
function attendance_can_edit_monthly_records($pdo){
    if (!\App\Core\Auth::check()) return false;

    $row = null;
    $email = (string)\App\Core\Auth::userEmail();
    if ($pdo && $email !== '' && attendance_table_exists($pdo, 'employees')) {
        try {
            $positionSelect = attendance_table_column_exists_for_settings($pdo, 'employees', 'position') ? 'position' : "'' AS position";
            $roleSelect = attendance_table_column_exists_for_settings($pdo, 'employees', 'role') ? 'role' : "'' AS role";
            $st = $pdo->prepare("SELECT name, department, " . $positionSelect . ", " . $roleSelect . " FROM employees WHERE email=:email LIMIT 1");
            $st->execute(array(':email' => $email));
            $found = $st->fetch(PDO::FETCH_ASSOC);
            if (is_array($found)) $row = $found;
        } catch (Exception $e) {
        }
    }

    if (!is_array($row)) {
        $user = \App\Core\Auth::user();
        $row = array(
            'name' => (is_array($user) && isset($user['name'])) ? $user['name'] : \App\Core\Auth::userName(),
            'department' => (is_array($user) && isset($user['department'])) ? $user['department'] : \App\Core\Auth::userDepartment(),
            'position' => (is_array($user) && isset($user['position'])) ? $user['position'] : \App\Core\Auth::userPosition(),
            'role' => (is_array($user) && isset($user['role'])) ? $user['role'] : \App\Core\Auth::userRole()
        );
    }

    $role = isset($row['role']) ? $row['role'] : '';
    $position = isset($row['position']) ? $row['position'] : '';
    $name = isset($row['name']) ? $row['name'] : '';
    $department = isset($row['department']) ? $row['department'] : '';
    if (attendance_is_representative_value($role, $position, $name)) return true;
    return attendance_is_development_department_value($department);
}}
if (!function_exists('attendance_is_request_management_user_value')) {
function attendance_is_request_management_user_value($role, $position, $name, $department){
    if (attendance_is_development_department_value($department)) return true;

    $values = array((string)$role, (string)$position, (string)$name);
    $allowedWords = array(
        attendance_text('%EB%8C%80%ED%91%9C'),
        attendance_text('%EB%B6%80%EC%82%AC%EC%9E%A5'),
        'ceo',
        'president',
        'vicepresident',
        'vp'
    );
    for ($i = 0; $i < count($values); $i++) {
        $value = strtolower(attendance_normalize_department_name($values[$i]));
        if ($value === '') continue;
        for ($j = 0; $j < count($allowedWords); $j++) {
            $word = strtolower(attendance_normalize_department_name($allowedWords[$j]));
            if ($word !== '' && strpos($value, $word) !== false) return true;
        }
    }
    return false;
}}
if (!function_exists('attendance_can_manage_requests')) {
function attendance_can_manage_requests($pdo){
    if (!\App\Core\Auth::check()) return false;

    $row = null;
    $email = (string)\App\Core\Auth::userEmail();
    if ($pdo && $email !== '' && attendance_table_exists($pdo, 'employees')) {
        try {
            $positionSelect = attendance_table_column_exists_for_settings($pdo, 'employees', 'position') ? 'position' : "'' AS position";
            $roleSelect = attendance_table_column_exists_for_settings($pdo, 'employees', 'role') ? 'role' : "'' AS role";
            $st = $pdo->prepare("SELECT name, department, " . $positionSelect . ", " . $roleSelect . " FROM employees WHERE email=:email LIMIT 1");
            $st->execute(array(':email' => $email));
            $found = $st->fetch(PDO::FETCH_ASSOC);
            if (is_array($found)) $row = $found;
        } catch (Exception $e) {
        }
    }

    if (!is_array($row)) {
        $user = \App\Core\Auth::user();
        $row = array(
            'name' => (is_array($user) && isset($user['name'])) ? $user['name'] : \App\Core\Auth::userName(),
            'department' => (is_array($user) && isset($user['department'])) ? $user['department'] : \App\Core\Auth::userDepartment(),
            'position' => (is_array($user) && isset($user['position'])) ? $user['position'] : \App\Core\Auth::userPosition(),
            'role' => (is_array($user) && isset($user['role'])) ? $user['role'] : \App\Core\Auth::userRole()
        );
    }

    return attendance_is_request_management_user_value(
        isset($row['role']) ? $row['role'] : '',
        isset($row['position']) ? $row['position'] : '',
        isset($row['name']) ? $row['name'] : '',
        isset($row['department']) ? $row['department'] : ''
    );
}}
function attendance_table_column_exists_for_settings($pdo, $table, $column){
    if (!$pdo) return false;
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:tbl AND COLUMN_NAME=:col");
        $st->execute(array(':tbl' => $table, ':col' => $column));
        return ((int)$st->fetchColumn() > 0);
    } catch (Exception $e) {
        return false;
    }
}
function attendance_settings($pdo){
    $d = array(
        'standard_weekly_hours'=>'40',
        'max_weekly_hours'=>'52',
        'daily_break_deduct_minutes'=>'120',
        'under_one_year_monthly_leave'=>'1',
        'half_day_amount'=>'0.5',
        'leave_rule_after_one_year'=>'half_year',
        'week_start'=>'monday',
        'show_substitute_holiday'=>'1',
        'attendance_geofence_enabled'=>'0',
        'attendance_geofence_name'=>'',
        'attendance_geofence_lat'=>'',
        'attendance_geofence_lng'=>'',
        'attendance_geofence_radius_m'=>'50'
    );
    if(!$pdo) return $d;
    try{
        $rows = $pdo->query("SELECT setting_key,setting_value FROM cpms_attendance_settings")->fetchAll();
        foreach($rows as $r){
            if (isset($r['setting_key'])) {
                $d[$r['setting_key']] = isset($r['setting_value']) ? $r['setting_value'] : '';
            }
        }
    }catch(Exception $e){}
    return $d;
}
function attendance_break_minutes($pdo){
    $s = attendance_settings($pdo);
    $m = isset($s['daily_break_deduct_minutes']) ? (int)$s['daily_break_deduct_minutes'] : 120;
    return $m > 0 ? $m : 0;
}
function attendance_week_range($date){
    $ts = strtotime($date);
    $w = (int)date('N', $ts);
    $start = date('Y-m-d', strtotime('-'.($w-1).' day', $ts));
    $end = date('Y-m-d', strtotime('+'.(7-$w).' day', $ts));
    return array($start, $end);
}
if (!function_exists('attendance_position_rank')) {
function attendance_position_rank($position){
    $position = trim((string)$position);
    if ($position === '') return 999;
    $position = str_replace(array(' ', "\t", "\r", "\n"), '', $position);
    $rankWords = array(
        attendance_text('%EB%8C%80%ED%91%9C'),
        attendance_text('%EB%B6%80%EC%82%AC%EC%9E%A5'),
        attendance_text('%EC%A0%84%EB%AC%B4'),
        attendance_text('%EC%83%81%EB%AC%B4'),
        attendance_text('%EB%B6%80%EC%9E%A5'),
        attendance_text('%EC%B0%A8%EC%9E%A5'),
        attendance_text('%EA%B3%BC%EC%9E%A5'),
        attendance_text('%EB%8C%80%EB%A6%AC'),
        attendance_text('%EC%A3%BC%EC%9E%84')
    );
    for ($i = 0; $i < count($rankWords); $i++) {
        $word = str_replace(array(' ', "\t", "\r", "\n"), '', $rankWords[$i]);
        if ($word !== '' && ($position === $word || strpos($position, $word) === 0)) return $i + 1;
    }
    return 999;
}}
if (!function_exists('attendance_compare_employee_position')) {
function attendance_compare_employee_position($a, $b){
    $ar = attendance_position_rank(isset($a['position']) ? $a['position'] : '');
    $br = attendance_position_rank(isset($b['position']) ? $b['position'] : '');
    if ($ar !== $br) return ($ar < $br) ? -1 : 1;

    $ah = isset($a['hire_date']) ? trim((string)$a['hire_date']) : '';
    $bh = isset($b['hire_date']) ? trim((string)$b['hire_date']) : '';
    if ($ah === '' && $bh !== '') return 1;
    if ($ah !== '' && $bh === '') return -1;
    if ($ah !== $bh) return ($ah < $bh) ? -1 : 1;

    $an = isset($a['name']) ? trim((string)$a['name']) : '';
    $bn = isset($b['name']) ? trim((string)$b['name']) : '';
    if ($an !== $bn) return strcmp($an, $bn);

    $aid = isset($a['id']) ? (int)$a['id'] : 0;
    $bid = isset($b['id']) ? (int)$b['id'] : 0;
    if ($aid === $bid) return 0;
    return ($aid < $bid) ? -1 : 1;
}}
if (!function_exists('attendance_is_representative_employee')) {
function attendance_is_representative_employee($row){
    if (!is_array($row)) return false;
    $name = isset($row['name']) ? trim((string)$row['name']) : '';
    $position = isset($row['position']) ? trim((string)$row['position']) : '';
    $needle = attendance_text('%EB%8C%80%ED%91%9C');
    return (($position !== '' && strpos($position, $needle) !== false) || ($name !== '' && strpos($name, $needle) !== false));
}}
if (!function_exists('attendance_filter_representative_rows')) {
function attendance_filter_representative_rows($rows){
    if (!is_array($rows)) return array();
    $filtered = array();
    foreach ($rows as $row) {
        if (attendance_is_representative_employee($row)) continue;
        $filtered[count($filtered)] = $row;
    }
    return $filtered;
}}
if (!function_exists('attendance_month_week_options')) {
function attendance_month_week_options($month){
    $month = trim((string)$month);
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
    $monthStart = $month . '-01';
    $monthStartTs = strtotime($monthStart);
    if ($monthStartTs === false) {
        $month = date('Y-m');
        $monthStart = $month . '-01';
        $monthStartTs = strtotime($monthStart);
    }
    $monthEnd = date('Y-m-t', $monthStartTs);
    $monthEndTs = strtotime($monthEnd);
    list($firstWeekStart, $firstWeekEnd) = attendance_week_range($monthStart);
    $weekStartTs = strtotime($firstWeekStart);
    $options = array();
    $weekNo = 1;
    while ($weekStartTs !== false && $monthEndTs !== false && $weekStartTs <= $monthEndTs) {
        $weekStart = date('Y-m-d', $weekStartTs);
        $weekEndTs = strtotime('+6 day', $weekStartTs);
        $weekEnd = date('Y-m-d', $weekEndTs);
        $options[count($options)] = array(
            'value' => $weekStart,
            'start' => $weekStart,
            'end' => $weekEnd,
            'week_no' => $weekNo,
            'label' => date('n', $monthStartTs) . attendance_text('%EC%9B%94%20') . $weekNo . attendance_text('%EC%A3%BC%EC%B0%A8'),
            'range_label' => $weekStart . ' ~ ' . $weekEnd
        );
        $weekStartTs = strtotime('+7 day', $weekStartTs);
        $weekNo++;
        if ($weekNo > 7) break;
    }
    return $options;
}}
if (!function_exists('attendance_month_week_selection')) {
function attendance_month_week_selection($month, $weekStart, $defaultDate){
    $defaultDate = trim((string)$defaultDate);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $defaultDate)) $defaultDate = attendance_today();
    $month = trim((string)$month);
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = substr($defaultDate, 0, 7);
    $options = attendance_month_week_options($month);
    $weekStart = trim((string)$weekStart);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStart)) $weekStart = '';

    $selected = null;
    for ($i = 0; $i < count($options); $i++) {
        if ($weekStart !== '' && $options[$i]['start'] === $weekStart) {
            $selected = $options[$i];
            break;
        }
    }
    if ($selected === null && substr($defaultDate, 0, 7) === $month) {
        list($defaultWeekStart, $defaultWeekEnd) = attendance_week_range($defaultDate);
        for ($i = 0; $i < count($options); $i++) {
            if ($options[$i]['start'] === $defaultWeekStart) {
                $selected = $options[$i];
                break;
            }
        }
    }
    if ($selected === null && count($options) > 0) $selected = $options[0];
    if ($selected === null) {
        list($fallbackStart, $fallbackEnd) = attendance_week_range($defaultDate);
        $selected = array(
            'value' => $fallbackStart,
            'start' => $fallbackStart,
            'end' => $fallbackEnd,
            'week_no' => 1,
            'label' => '',
            'range_label' => $fallbackStart . ' ~ ' . $fallbackEnd
        );
    }
    $selected['month'] = $month;
    $selected['options'] = $options;
    return $selected;
}}
function attendance_minutes($in,$out){
    if(!$in || !$out) return 0;
    $m = (int)((strtotime($out)-strtotime($in))/60);
    return $m > 0 ? $m : 0;
}
function attendance_work_minutes($raw,$break){
    $w = (int)$raw - (int)$break;
    return $w > 0 ? $w : 0;
}
function attendance_hm($minutes){
    $minutes = (int)$minutes;
    $h = floor($minutes/60);
    $m = $minutes%60;
    return $h . attendance_text('%EC%8B%9C%EA%B0%84%20') . $m . attendance_text('%EB%B6%84');
}
if (!function_exists('attendance_morning_checkin_leave_excludes')) {
function attendance_morning_checkin_leave_excludes($label, $amount) {
    $label = trim((string)$label);
    $compact = str_replace(array(' ', "\t", "\r", "\n"), '', $label);
    $amount = is_numeric($amount) ? (float)$amount : 0.0;
    $morning = attendance_text('%EC%98%A4%EC%A0%84');
    $afternoon = attendance_text('%EC%98%A4%ED%9B%84');
    if (strpos($compact, $afternoon) !== false && strpos($compact, $morning) === false) return false;
    if ($amount > 0 && $amount <= 0.5 && strpos($compact, $afternoon) !== false) return false;
    return true;
}}
if (!function_exists('attendance_morning_checkin_leave_map')) {
function attendance_morning_checkin_leave_map($pdo, $date) {
    $map = array();
    $date = trim((string)$date);
    if (!$pdo || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return $map;
    if (attendance_table_exists($pdo, 'cpms_leave_records')) {
        try {
            $st = $pdo->prepare("SELECT employee_id, leave_type, leave_amount FROM cpms_leave_records WHERE leave_date=:d");
            $st->execute(array(':d' => $date));
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $employeeId = isset($row['employee_id']) ? (int)$row['employee_id'] : 0;
                    if ($employeeId <= 0) continue;
                    $label = isset($row['leave_type']) ? (string)$row['leave_type'] : '';
                    $amount = isset($row['leave_amount']) ? $row['leave_amount'] : 0;
                    if (attendance_morning_checkin_leave_excludes($label, $amount)) $map[$employeeId] = true;
                }
            }
        } catch (Exception $e) {
        }
    }
    if (attendance_table_exists($pdo, 'cpms_approval_documents') && attendance_table_column_exists_for_settings($pdo, 'cpms_approval_documents', 'created_by_id') && attendance_table_column_exists_for_settings($pdo, 'cpms_approval_documents', 'content')) {
        try {
            $st = $pdo->query("SELECT created_by_id, content FROM cpms_approval_documents WHERE doc_type='leave' AND UPPER(COALESCE(doc_status,'')) IN ('APPROVED','COMPLETED') ORDER BY id DESC");
            $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $employeeId = isset($row['created_by_id']) ? (int)$row['created_by_id'] : 0;
                    if ($employeeId <= 0) continue;
                    $content = array();
                    $raw = isset($row['content']) ? trim((string)$row['content']) : '';
                    if ($raw !== '') {
                        $decoded = json_decode($raw, true);
                        if (is_array($decoded)) $content = $decoded;
                    }
                    $start = isset($content['leave_start_date']) ? substr(trim((string)$content['leave_start_date']), 0, 10) : '';
                    $end = isset($content['leave_end_date']) ? substr(trim((string)$content['leave_end_date']), 0, 10) : '';
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) continue;
                    if ($date < $start || $date > $end) continue;
                    $label = isset($content['request_type']) ? (string)$content['request_type'] : '';
                    $amount = isset($content['leave_days']) ? str_replace(',', '', (string)$content['leave_days']) : 0;
                    if (attendance_morning_checkin_leave_excludes($label, $amount)) $map[$employeeId] = true;
                }
            }
        } catch (Exception $e) {
        }
    }
    return $map;
}}
if (!function_exists('attendance_morning_checkin_notification_exists')) {
function attendance_morning_checkin_notification_exists($pdo, $date, $employeeId) {
    if (!$pdo || (int)$employeeId <= 0) return false;
    $sourceId = (int)str_replace('-', '', (string)$date);
    if (attendance_table_exists($pdo, 'cpms_attendance_morning_checkin_notifications')) {
        try {
            $stReserved = $pdo->prepare("SELECT id FROM cpms_attendance_morning_checkin_notifications WHERE work_date=:work_date AND employee_id=:employee_id LIMIT 1");
            $stReserved->execute(array(':work_date' => (string)$date, ':employee_id' => (int)$employeeId));
            if ($stReserved->fetchColumn()) return true;
        } catch (Exception $e) {
        }
    }
    if (!attendance_table_exists($pdo, 'cpms_google_chat_notifications')) return false;
    try {
        $st = $pdo->prepare("SELECT id FROM cpms_google_chat_notifications WHERE source_type='ATTENDANCE_MISSING_CHECKIN' AND event_type='MORNING_CHECKIN_REMINDER' AND source_id=:source_id AND receiver_employee_id=:employee_id LIMIT 1");
        $st->execute(array(':source_id' => $sourceId, ':employee_id' => (int)$employeeId));
        return (bool)$st->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}}
if (!function_exists('attendance_morning_checkin_ensure_schema')) {
function attendance_morning_checkin_ensure_schema($pdo) {
    if (!$pdo) return false;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cpms_attendance_morning_checkin_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            work_date DATE NOT NULL,
            employee_id INT NOT NULL,
            source_id INT NOT NULL,
            send_status VARCHAR(20) NULL,
            created_at DATETIME NOT NULL,
            sent_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uniq_work_employee (work_date, employee_id),
            KEY idx_work_date (work_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
        return true;
    } catch (Exception $e) {
        return false;
    }
}}
if (!function_exists('attendance_morning_checkin_reserve_notification')) {
function attendance_morning_checkin_reserve_notification($pdo, $date, $employeeId, $sourceId) {
    if (!$pdo || (int)$employeeId <= 0) return false;
    if (!attendance_morning_checkin_ensure_schema($pdo)) return !attendance_morning_checkin_notification_exists($pdo, $date, $employeeId);
    try {
        $now = attendance_now();
        $st = $pdo->prepare("INSERT IGNORE INTO cpms_attendance_morning_checkin_notifications
            (work_date, employee_id, source_id, send_status, created_at, updated_at)
            VALUES (:work_date, :employee_id, :source_id, 'RESERVED', :created_at, :updated_at)");
        $st->execute(array(
            ':work_date' => (string)$date,
            ':employee_id' => (int)$employeeId,
            ':source_id' => (int)$sourceId,
            ':created_at' => $now,
            ':updated_at' => $now
        ));
        return ((int)$st->rowCount() > 0);
    } catch (Exception $e) {
        return false;
    }
}}
if (!function_exists('attendance_morning_checkin_mark_notification')) {
function attendance_morning_checkin_mark_notification($pdo, $date, $employeeId, $ok) {
    if (!$pdo || (int)$employeeId <= 0 || !attendance_table_exists($pdo, 'cpms_attendance_morning_checkin_notifications')) return false;
    try {
        $now = attendance_now();
        $st = $pdo->prepare("UPDATE cpms_attendance_morning_checkin_notifications
            SET send_status=:send_status, sent_at=:sent_at, updated_at=:updated_at
            WHERE work_date=:work_date AND employee_id=:employee_id");
        $st->execute(array(
            ':send_status' => $ok ? 'SUCCESS' : 'FAILED',
            ':sent_at' => $ok ? $now : null,
            ':updated_at' => $now,
            ':work_date' => (string)$date,
            ':employee_id' => (int)$employeeId
        ));
        return true;
    } catch (Exception $e) {
        return false;
    }
}}
if (!function_exists('attendance_process_morning_missing_checkin_notifications')) {
function attendance_process_morning_missing_checkin_notifications($pdo, $limit) {
    $result = array('checked' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0);
    if (!$pdo) return $result;
    $today = attendance_today();
    $now = attendance_now();
    $nowTime = strlen($now) >= 19 ? substr($now, 11, 8) : date('H:i:s');
    if (strcmp($nowTime, '08:00:00') < 0) return $result;
    $weekNo = (int)date('N', strtotime($today));
    if ($weekNo >= 6) return $result;
    if (!function_exists('cpms_send_google_chat_to_employee')) {
        require_once dirname(dirname(__DIR__)) . '/helpers.php';
    }
    if (!function_exists('cpms_send_google_chat_to_employee')) return $result;

    $limit = (int)$limit;
    if ($limit < 200) $limit = 200;
    if ($limit > 500) $limit = 500;
    $sourceId = (int)str_replace('-', '', $today);
    $leaveMap = attendance_morning_checkin_leave_map($pdo, $today);
    $presentMap = array();
    try {
        $stPresent = $pdo->prepare("SELECT employee_id FROM cpms_attendance_records WHERE work_date=:d AND check_in IS NOT NULL AND TRIM(CAST(check_in AS CHAR)) <> ''");
        $stPresent->execute(array(':d' => $today));
        $presentRows = $stPresent->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($presentRows)) {
            foreach ($presentRows as $presentRow) {
                $presentMap[(int)$presentRow['employee_id']] = true;
            }
        }
    } catch (Exception $e) {
        return $result;
    }
    try {
        $positionSelect = attendance_table_column_exists_for_settings($pdo, 'employees', 'position') ? 'position' : "'' AS position";
        $activeWhere = attendance_table_column_exists_for_settings($pdo, 'employees', 'is_active') ? " WHERE (is_active IS NULL OR is_active=1)" : "";
        $sql = "SELECT id, name, email, department, " . $positionSelect . " FROM employees" . $activeWhere . " ORDER BY id ASC LIMIT " . (int)$limit;
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) $rows = array();
        $rows = attendance_filter_representative_rows($rows);
    } catch (Exception $e) {
        return $result;
    }
    foreach ($rows as $row) {
        $employeeId = isset($row['id']) ? (int)$row['id'] : 0;
        if ($employeeId <= 0) continue;
        $result['checked']++;
        if (isset($presentMap[$employeeId]) || isset($leaveMap[$employeeId])) {
            $result['skipped']++;
            continue;
        }
        if (attendance_morning_checkin_notification_exists($pdo, $today, $employeeId)) {
            $result['skipped']++;
            continue;
        }
        if (!attendance_morning_checkin_reserve_notification($pdo, $today, $employeeId, $sourceId)) {
            $result['skipped']++;
            continue;
        }
        $ok = cpms_send_google_chat_to_employee($pdo, $employeeId, '현재 미출근 중입니다. 출근 바랍니다.', $sourceId, 'MORNING_CHECKIN_REMINDER', 'ATTENDANCE_MISSING_CHECKIN');
        attendance_morning_checkin_mark_notification($pdo, $today, $employeeId, $ok);
        if ($ok) $result['sent']++;
        else $result['failed']++;
    }
    return $result;
}}
function attendance_parse_coordinate($value){
    $value = trim((string)$value);
    if($value === '') return null;
    if(!is_numeric($value)) return null;
    return (float)$value;
}
function attendance_table_exists($pdo, $table){
    if(!$pdo) return false;
    try{
        $st = $pdo->prepare('SHOW TABLES LIKE :table');
        $st->execute(array(':table' => $table));
        return (bool)$st->fetchColumn();
    }catch(Exception $e){
        return false;
    }
}
function attendance_geofence_locations($pdo, $includeInactive){
    $rows = array();
    if(!$pdo) return $rows;
    if(!attendance_table_exists($pdo, 'cpms_attendance_geofences')) return $rows;
    try{
        $sql = "SELECT id,name,location_type,project_id,project_name,lat,lng,radius_m,is_active,created_at,updated_at
                FROM cpms_attendance_geofences";
        if(!$includeInactive){
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY is_active DESC, location_type ASC, name ASC, id ASC";
        $st = $pdo->query($sql);
        $fetched = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
        if(!is_array($fetched)) return $rows;
        foreach($fetched as $row){
            $lat = attendance_parse_coordinate(isset($row['lat']) ? $row['lat'] : '');
            $lng = attendance_parse_coordinate(isset($row['lng']) ? $row['lng'] : '');
            $radius = isset($row['radius_m']) ? (float)$row['radius_m'] : 50.0;
            if($radius <= 0) $radius = 50.0;
            if($lat === null || $lng === null) continue;
            $rows[] = array(
                'id' => isset($row['id']) ? (int)$row['id'] : 0,
                'name' => isset($row['name']) ? trim((string)$row['name']) : '',
                'location_type' => isset($row['location_type']) ? trim((string)$row['location_type']) : 'office',
                'project_id' => isset($row['project_id']) ? (int)$row['project_id'] : 0,
                'project_name' => isset($row['project_name']) ? trim((string)$row['project_name']) : '',
                'lat' => $lat,
                'lng' => $lng,
                'radius_m' => $radius,
                'is_active' => isset($row['is_active']) ? (int)$row['is_active'] : 0,
                'created_at' => isset($row['created_at']) ? $row['created_at'] : '',
                'updated_at' => isset($row['updated_at']) ? $row['updated_at'] : ''
            );
        }
    }catch(Exception $e){
        error_log('[attendance_geofence_locations] ' . $e->getMessage());
    }
    return $rows;
}
function attendance_geofence_type_label($type){
    $type = trim((string)$type);
    if($type === 'field') return attendance_text('%ED%98%84%EC%9E%A5');
    if($type === 'other') return attendance_text('%EA%B8%B0%ED%83%80');
    return attendance_text('%EC%82%AC%EB%AC%B4%EC%8B%A4');
}
function attendance_geofence_settings($pdo){
    $s = attendance_settings($pdo);
    $radius = isset($s['attendance_geofence_radius_m']) ? (float)$s['attendance_geofence_radius_m'] : 50.0;
    if($radius <= 0) $radius = 50.0;
    $locations = attendance_geofence_locations($pdo, false);
    $fallbackLat = attendance_parse_coordinate(isset($s['attendance_geofence_lat']) ? $s['attendance_geofence_lat'] : '');
    $fallbackLng = attendance_parse_coordinate(isset($s['attendance_geofence_lng']) ? $s['attendance_geofence_lng'] : '');
    if(count($locations) === 0 && $fallbackLat !== null && $fallbackLng !== null){
        $locations[] = array(
            'id' => 0,
            'name' => isset($s['attendance_geofence_name']) ? trim((string)$s['attendance_geofence_name']) : '',
            'location_type' => 'office',
            'project_id' => 0,
            'project_name' => '',
            'lat' => $fallbackLat,
            'lng' => $fallbackLng,
            'radius_m' => $radius,
            'is_active' => 1,
            'created_at' => '',
            'updated_at' => ''
        );
    }
    $enabledBySetting = (isset($s['attendance_geofence_enabled']) && (string)$s['attendance_geofence_enabled'] === '1');
    $enabledByLocations = (count($locations) > 0);
    return array(
        // 활성 허용위치가 있으면 실제 출퇴근 검증도 함께 적용한다.
        'enabled' => ($enabledBySetting || $enabledByLocations),
        'enabled_setting' => $enabledBySetting,
        'name' => isset($s['attendance_geofence_name']) ? trim((string)$s['attendance_geofence_name']) : '',
        'lat' => $fallbackLat,
        'lng' => $fallbackLng,
        'radius_m' => $radius,
        'locations' => $locations,
        'location_count' => count($locations)
    );
}
function attendance_has_geofence($cfg){
    if(!is_array($cfg) || empty($cfg['enabled'])) return false;
    if(isset($cfg['locations']) && is_array($cfg['locations']) && count($cfg['locations']) > 0) return true;
    return isset($cfg['lat']) && $cfg['lat'] !== null
        && isset($cfg['lng']) && $cfg['lng'] !== null
        && isset($cfg['radius_m']) && (float)$cfg['radius_m'] > 0;
}
function attendance_haversine_meters($lat1, $lng1, $lat2, $lng2){
    $earthRadius = 6371000.0;
    $latFrom = deg2rad((float)$lat1);
    $lngFrom = deg2rad((float)$lng1);
    $latTo = deg2rad((float)$lat2);
    $lngTo = deg2rad((float)$lng2);
    $latDelta = $latTo - $latFrom;
    $lngDelta = $lngTo - $lngFrom;
    $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) + cos($latFrom) * cos($latTo) * pow(sin($lngDelta / 2), 2)));
    return $angle * $earthRadius;
}
function attendance_geofence_validation_result($pdo, $post){
    $cfg = attendance_geofence_settings($pdo);
    $result = array(
        'ok' => true,
        'message' => '',
        'distance_m' => null,
        'matched_location' => null,
        'nearest_location' => null,
        'config' => $cfg,
        'lat' => attendance_parse_coordinate(isset($post['geo_lat']) ? $post['geo_lat'] : ''),
        'lng' => attendance_parse_coordinate(isset($post['geo_lng']) ? $post['geo_lng'] : ''),
        'accuracy_m' => attendance_parse_coordinate(isset($post['geo_accuracy']) ? $post['geo_accuracy'] : '')
    );
    if (empty($cfg['enabled'])) {
        return $result;
    }
    if (!attendance_has_geofence($cfg)) {
        $result['ok'] = false;
        $result['message'] = attendance_text('%EA%B4%80%EB%A6%AC%ED%8C%80%EC%97%90%EC%84%9C%20%EC%B6%9C%ED%87%B4%EA%B7%BC%20%ED%97%88%EC%9A%A9%20%EC%9C%84%EC%B9%98%EA%B0%80%20%EC%95%84%EC%A7%81%20%EC%84%A4%EC%A0%95%EB%90%98%EC%A7%80%20%EC%95%8A%EC%95%98%EC%8A%B5%EB%8B%88%EB%8B%A4.');
        return $result;
    }
    if ($result['lat'] === null || $result['lng'] === null) {
        $result['ok'] = false;
        $result['message'] = attendance_text('%ED%98%84%EC%9E%AC%20%EC%9C%84%EC%B9%98%20%ED%99%95%EC%9D%B8%20%ED%9B%84%20%EB%8B%A4%EC%8B%9C%20%EC%8B%9C%EB%8F%84%ED%95%B4%EC%A3%BC%EC%84%B8%EC%9A%94.');
        return $result;
    }
    $locations = isset($cfg['locations']) && is_array($cfg['locations']) ? $cfg['locations'] : array();
    if(count($locations) === 0 && isset($cfg['lat']) && $cfg['lat'] !== null && isset($cfg['lng']) && $cfg['lng'] !== null){
        $locations[] = array(
            'id' => 0,
            'name' => isset($cfg['name']) ? trim((string)$cfg['name']) : '',
            'location_type' => 'office',
            'project_id' => 0,
            'project_name' => '',
            'lat' => $cfg['lat'],
            'lng' => $cfg['lng'],
            'radius_m' => isset($cfg['radius_m']) ? (float)$cfg['radius_m'] : 50.0,
            'is_active' => 1
        );
    }
    $matched = null;
    $nearest = null;
    foreach($locations as $location){
        if(!isset($location['lat']) || !isset($location['lng'])) continue;
        $distance = attendance_haversine_meters($location['lat'], $location['lng'], $result['lat'], $result['lng']);
        if($nearest === null || $distance < $nearest['distance_m']){
            $nearest = $location;
            $nearest['distance_m'] = $distance;
        }
        if($distance <= (float)$location['radius_m']){
            $matched = $location;
            $matched['distance_m'] = $distance;
            break;
        }
    }
    if($matched !== null){
        $result['matched_location'] = $matched;
        $result['nearest_location'] = $matched;
        $result['distance_m'] = isset($matched['distance_m']) ? $matched['distance_m'] : null;
        return $result;
    }
    if($nearest !== null){
        $result['nearest_location'] = $nearest;
        $result['distance_m'] = isset($nearest['distance_m']) ? $nearest['distance_m'] : null;
        $label = trim((string)$nearest['name']);
        if($label === '') $label = attendance_text('%EA%B4%80%EB%A6%AC%ED%8C%80%20%EC%A7%80%EC%A0%95%20%EC%9C%84%EC%B9%98');
        $result['ok'] = false;
        $result['message'] = attendance_text('%EB%93%B1%EB%A1%9D%EB%90%9C%20%EC%B6%9C%ED%87%B4%EA%B7%BC%20%ED%97%88%EC%9A%A9%20%EC%9C%84%EC%B9%98%20%EB%B0%98%EA%B2%BD%20%EC%95%88%EC%97%90%EC%84%9C%EB%A7%8C%20%EC%B6%9C%ED%87%B4%EA%B7%BC%ED%95%A0%20%EC%88%98%20%EC%9E%88%EC%8A%B5%EB%8B%88%EB%8B%A4.%20%EA%B0%80%EC%9E%A5%20%EA%B0%80%EA%B9%8C%EC%9A%B4%20%EC%9C%84%EC%B9%98%3A%20') . $label . ' / ' . attendance_text('%ED%97%88%EC%9A%A9%20%EB%B0%98%EA%B2%BD%20') . number_format((float)$nearest['radius_m']) . 'm / ' . attendance_text('%ED%98%84%EC%9E%AC%20%EA%B1%B0%EB%A6%AC%3A%20%EC%95%BD%20') . number_format((float)$nearest['distance_m']) . 'm';
        return $result;
    }
    return $result;
}
function attendance_months_of_service($hireDate, $today){
    if(!$hireDate) return 0;
    $h = strtotime($hireDate);
    $t = strtotime($today);
    if(!$h || !$t || $h > $t) return 0;
    $hy = (int)date('Y',$h);
    $hm = (int)date('n',$h);
    $hd = (int)date('j',$h);
    $ty = (int)date('Y',$t);
    $tm = (int)date('n',$t);
    $td = (int)date('j',$t);
    $m = ($ty-$hy)*12+($tm-$hm);
    if($td<$hd) $m--;
    return $m>0?$m:0;
}
function attendance_is_under_one_year($hireDate, $today){
    if(!$hireDate) return false;
    $h = strtotime($hireDate);
    $t = strtotime($today);
    if(!$h || !$t || $h > $t) return false;
    return attendance_months_of_service($hireDate,$today)<12;
}
function attendance_auto_leave_granted($hireDate, $today){
    $ret = array('monthly'=>0.0,'annual'=>0.0,'under_one_year'=>false,'hire_missing'=>false);
    if(!$hireDate){
        $ret['hire_missing']=true;
        return $ret;
    }
    $months = attendance_months_of_service($hireDate,$today);
    if($months<12){
        $ret['under_one_year']=true;
        $ret['monthly']=(float)$months;
        $ret['annual']=0.0;
    }else{
        $ret['under_one_year']=false;
        $ret['monthly']=0.0;
        $ret['annual']=15.0;
    }
    return $ret;
}
function attendance_float_fmt($v){
    if($v==='-'||$v===null) return '-';
    if((float)$v==(int)$v) return (string)(int)$v;
    return number_format((float)$v,1);
}
