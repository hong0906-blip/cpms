<?php
/** 출퇴근 시스템 공통 함수 */
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
function attendance_today_record($pdo, $employeeId){
    $employeeId = (int)$employeeId;
    if(!$pdo || $employeeId <= 0) return null;
    try{
        $today = attendance_today();
        $st = $pdo->prepare("SELECT * FROM cpms_attendance_records WHERE employee_id = :employee_id AND work_date = :today LIMIT 1");
        $st->execute(array(':employee_id'=>$employeeId, ':today'=>$today));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? $row : null;
    }catch(Exception $e){
        error_log('[attendance_today_record] '.$e->getMessage());        
        return null;
    }
}
function attendance_employee_id($pdo){$email=(string)\App\Core\Auth::userEmail(); if(!$pdo||$email==='')return 0; try{$st=$pdo->prepare("SELECT id FROM employees WHERE email=:em LIMIT 1");$st->bindValue(':em',$email);$st->execute();return (int)$st->fetchColumn();}catch(Exception $e){return 0;}}
function attendance_is_manager(){return \App\Core\Auth::isMaster() || \App\Core\Auth::canManageEmployees();} // 직접 권한 체크 마스터 통과
function attendance_settings($pdo){$d=array('standard_weekly_hours'=>'40','max_weekly_hours'=>'52','daily_break_deduct_minutes'=>'120','under_one_year_monthly_leave'=>'1','half_day_amount'=>'0.5','leave_rule_after_one_year'=>'half_year','week_start'=>'monday','show_substitute_holiday'=>'1'); if(!$pdo)return $d; try{$rows=$pdo->query("SELECT setting_key,setting_value FROM cpms_attendance_settings")->fetchAll();foreach($rows as $r){$d[$r['setting_key']]=$r['setting_value'];}}catch(Exception $e){} return $d;}
function attendance_break_minutes($pdo){$s=attendance_settings($pdo);$m=isset($s['daily_break_deduct_minutes'])?(int)$s['daily_break_deduct_minutes']:120;return $m>0?$m:0;}
function attendance_week_range($date){$ts=strtotime($date);$w=(int)date('N',$ts);$start=date('Y-m-d',strtotime('-'.($w-1).' day',$ts));$end=date('Y-m-d',strtotime('+'.(7-$w).' day',$ts));return array($start,$end);}
function attendance_minutes($in,$out){if(!$in||!$out)return 0;$m=(int)((strtotime($out)-strtotime($in))/60);return $m>0?$m:0;}
function attendance_work_minutes($raw,$break){$w=(int)$raw-(int)$break;return $w>0?$w:0;}
function attendance_hm($minutes){$minutes=(int)$minutes;$h=floor($minutes/60);$m=$minutes%60;return $h.'시간 '.$m.'분';}

/* 휴가 계산 공통: 입사일 기준 월차/연차 발생 계산 (PHP 5.6) */
function attendance_months_of_service($hireDate, $today){
    if(!$hireDate) return 0;
    $h=strtotime($hireDate); $t=strtotime($today);
    if(!$h||!$t||$h>$t) return 0;
    $hy=(int)date('Y',$h); $hm=(int)date('n',$h); $hd=(int)date('j',$h);
    $ty=(int)date('Y',$t); $tm=(int)date('n',$t); $td=(int)date('j',$t);
    $m=($ty-$hy)*12+($tm-$hm);
    if($td<$hd) $m--;
    return $m>0?$m:0;
}
function attendance_is_under_one_year($hireDate, $today){
    if(!$hireDate) return false;
    $h=strtotime($hireDate); $t=strtotime($today);
    if(!$h||!$t||$h>$t) return false;
    return attendance_months_of_service($hireDate,$today)<12;
}
function attendance_auto_leave_granted($hireDate, $today){
    $ret=array('monthly'=>0.0,'annual'=>0.0,'under_one_year'=>false,'hire_missing'=>false);
    if(!$hireDate){$ret['hire_missing']=true; return $ret;}
    $months=attendance_months_of_service($hireDate,$today);
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