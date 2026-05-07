<?php
/** 출퇴근 시스템 공통 함수 */
function attendance_now(){return date('Y-m-d H:i:s');}
function attendance_today(){return date('Y-m-d');}
function attendance_employee_id($pdo){$email=(string)\App\Core\Auth::userEmail(); if(!$pdo||$email==='')return 0; try{$st=$pdo->prepare("SELECT id FROM employees WHERE email=:em LIMIT 1");$st->bindValue(':em',$email);$st->execute();return (int)$st->fetchColumn();}catch(Exception $e){return 0;}}
function attendance_is_manager(){return \App\Core\Auth::canManageEmployees();}
function attendance_settings($pdo){$d=array('standard_weekly_hours'=>'40','max_weekly_hours'=>'52','under_one_year_monthly_leave'=>'1','half_day_amount'=>'0.5','leave_rule_after_one_year'=>'half_year','week_start'=>'monday','show_substitute_holiday'=>'1'); if(!$pdo)return $d; try{$rows=$pdo->query("SELECT setting_key,setting_value FROM cpms_attendance_settings")->fetchAll();foreach($rows as $r){$d[$r['setting_key']]=$r['setting_value'];}}catch(Exception $e){} return $d;}
function attendance_week_range($date){$ts=strtotime($date);$w=(int)date('N',$ts);$start=date('Y-m-d',strtotime('-'.($w-1).' day',$ts));$end=date('Y-m-d',strtotime('+'.(7-$w).' day',$ts));return array($start,$end);}
function attendance_minutes($in,$out){if(!$in||!$out)return 0;$m=(int)((strtotime($out)-strtotime($in))/60);return $m>0?$m:0;}