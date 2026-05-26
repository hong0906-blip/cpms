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
function attendance_is_manager(){
    return \App\Core\Auth::isMaster() || \App\Core\Auth::canManageEmployees();
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
function attendance_parse_coordinate($value){
    $value = trim((string)$value);
    if($value === '') return null;
    if(!is_numeric($value)) return null;
    return (float)$value;
}
function attendance_geofence_settings($pdo){
    $s = attendance_settings($pdo);
    $radius = isset($s['attendance_geofence_radius_m']) ? (float)$s['attendance_geofence_radius_m'] : 50.0;
    if($radius <= 0) $radius = 50.0;
    return array(
        'enabled' => (isset($s['attendance_geofence_enabled']) && (string)$s['attendance_geofence_enabled'] === '1'),
        'name' => isset($s['attendance_geofence_name']) ? trim((string)$s['attendance_geofence_name']) : '',
        'lat' => attendance_parse_coordinate(isset($s['attendance_geofence_lat']) ? $s['attendance_geofence_lat'] : ''),
        'lng' => attendance_parse_coordinate(isset($s['attendance_geofence_lng']) ? $s['attendance_geofence_lng'] : ''),
        'radius_m' => $radius
    );
}
function attendance_has_geofence($cfg){
    return is_array($cfg)
        && !empty($cfg['enabled'])
        && isset($cfg['lat']) && $cfg['lat'] !== null
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
        $result['message'] = attendance_text('%EA%B4%80%EB%A6%AC%ED%8C%80%EC%97%90%EC%84%9C%20%EC%B6%9C%ED%87%B4%EA%B7%BC%20%EC%9C%84%EC%B9%98%EA%B0%80%20%EC%95%84%EC%A7%81%20%EC%84%A4%EC%A0%95%EB%90%98%EC%A7%80%20%EC%95%8A%EC%95%98%EC%8A%B5%EB%8B%88%EB%8B%A4.');
        return $result;
    }
    if ($result['lat'] === null || $result['lng'] === null) {
        $result['ok'] = false;
        $result['message'] = attendance_text('%ED%98%84%EC%9E%AC%20%EC%9C%84%EC%B9%98%20%ED%99%95%EC%9D%B8%20%ED%9B%84%20%EB%8B%A4%EC%8B%9C%20%EC%8B%9C%EB%8F%84%ED%95%B4%EC%A3%BC%EC%84%B8%EC%9A%94.');
        return $result;
    }
    $distance = attendance_haversine_meters($cfg['lat'], $cfg['lng'], $result['lat'], $result['lng']);
    $result['distance_m'] = $distance;
    if ($distance > (float)$cfg['radius_m']) {
        $label = trim((string)$cfg['name']);
        if ($label === '') $label = attendance_text('%EA%B4%80%EB%A6%AC%ED%8C%80%20%EC%A7%80%EC%A0%95%20%EC%9C%84%EC%B9%98');
        $result['ok'] = false;
        $result['message'] = $label . ' ' . attendance_text('%EB%B0%98%EA%B2%BD%20') . number_format((float)$cfg['radius_m']) . 'm ' . attendance_text('%EC%95%88%EC%97%90%EC%84%9C%EB%A7%8C%20%EC%B6%9C%ED%87%B4%EA%B7%BC%ED%95%A0%20%EC%88%98%20%EC%9E%88%EC%8A%B5%EB%8B%88%EB%8B%A4.%20%ED%98%84%EC%9E%AC%20%EA%B1%B0%EB%A6%AC%3A%20%EC%95%BD%20') . number_format($distance) . 'm';
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
