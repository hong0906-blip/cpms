<?php
// app/services/Cpms2AttendanceExportService.php
// Phase 1 diagnostics only. No export records, recalculation writes or calendar calls.
require_once __DIR__.'/Cpms2ManagementPreflightSupport.php';
class Cpms2AttendanceExportService
{
    private $source; private $support; private $schema=array(); private $today;
    public function __construct($source,$support) { $this->source=$source; $this->support=$support; }
    private function has($table,$column) { return isset($this->schema[$table]) && in_array($column,$this->schema[$table]['columns']); }
    private function scalar($sql,$params=array()) { return $this->source->query($sql,$params)->fetchColumn(); }
    private function aggregate($table,$expressions)
    {
        $parts=array(); foreach ($expressions as $key=>$sql) $parts[]=$sql.' AS `'.$key.'`';
        return $this->source->query('SELECT '.implode(',',$parts).' FROM `'.$table.'`')->fetch(PDO::FETCH_ASSOC);
    }
    private function groups($table,$column,$domain,$amount=null)
    {
        if (!$this->has($table,$column)) return array();
        $sum=$amount!==null && $this->has($table,$amount)?',COALESCE(SUM(`'.$amount.'`),0) AS amount_sum':'';
        $st=$this->source->query('SELECT `'.$column.'` AS value,COUNT(*) AS count'.$sum.' FROM `'.$table.'` GROUP BY `'.$column.'` ORDER BY count DESC LIMIT 101');
        $out=array(); while ($row=$st->fetch(PDO::FETCH_ASSOC)) {
            if (count($out)===100) { $this->support->issue($domain,'DISTINCT_VALUES_TRUNCATED'); break; }
            $value=in_array($column,array('target_year','accrual_year'))?(is_numeric($row['value'])?(int)$row['value']:null):Cpms2ManagementPreflightSupport::statusLabel($row['value']);
            $g=array('value'=>$value,'count'=>(int)$row['count']); if (isset($row['amount_sum'])) $g['amount_sum']=(string)$row['amount_sum']; $out[]=$g;
        } return $out;
    }
    private function anomaly($table,$condition,$code,$domain='Attendance',$severity='WARNING')
    {
        $count=(int)$this->scalar('SELECT COUNT(*) FROM `'.$table.'` t WHERE '.$condition);
        $ids=array(); if ($count && $this->has($table,'id')) {
            $st=$this->source->query('SELECT t.id FROM `'.$table.'` t WHERE '.$condition.' ORDER BY t.id LIMIT 20');
            while ($row=$st->fetch(PDO::FETCH_ASSOC)) $ids[]=(int)$row['id'];
        }
        $this->support->issue($domain,$code,$severity,$count,$ids); return $count;
    }
    private function orphan($table,$column,$domain,$target='employees')
    {
        if (!$this->has($table,$column) || !$this->has($target,'id')) return null;
        return $this->anomaly($table,'t.`'.$column.'` IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `'.$target.'` e WHERE e.id=t.`'.$column.'`)','ORPHAN_'.$column,$domain,'BLOCKING');
    }
    public function inspect($today=null)
    {
        $this->today=$today===null?date('Y-m-d'):$today;
        if (Cpms2ManagementPreflightSupport::safeDate($this->today)===null) throw new RuntimeException('INVALID_DIAGNOSTIC_DATE');
        $definitions=array(
            'cpms_attendance_records'=>array('id','employee_id','work_date','check_in','check_out','status','raw_minutes','work_minutes','memo'),
            'cpms_attendance_requests'=>array('id','employee_id','request_date','request_type','status','reviewed_by','reviewed_at','reason','reject_reason','requested_check_in','requested_check_out'),
            'cpms_leave_records'=>array('id','employee_id','leave_date','leave_type','leave_amount'),
            'cpms_leave_adjustments'=>array('id','employee_id','amount'),
            'cpms_leave_accrual_logs'=>array('id','employee_id','leave_type','accrual_date','accrual_year','amount'),
            'cpms_approval_leave_deductions'=>array('id','employee_id','document_id','leave_bucket','deduct_amount'),
            'cpms_approval_documents'=>array('id','doc_status'),
            'cpms_approval_logs'=>array('id','document_id','action_type'),
            'cpms_holiday_cache'=>array('holiday_date','source','is_active'),
            'employees'=>array('id','leave_monthly_balance','leave_annual_balance','leave_half_balance')
        );
        foreach ($definitions as $table=>$required) {
            $columns=$this->source->inspect($table,false,array());
            $missing=array_values(array_diff($required,$columns));
            $variant='STANDARD';
            if ($table==='cpms_leave_adjustments') {
                $variant=in_array('adjust_type',$columns)?'ADJUST_TYPE_TARGET_YEAR':(in_array('leave_type',$columns)?'LEGACY_LEAVE_TYPE':'UNKNOWN');
                if ($variant==='ADJUST_TYPE_TARGET_YEAR' && !in_array('target_year',$columns)) $missing[]='target_year';
                if ($variant==='UNKNOWN' && $columns) $missing[]='leave_type_or_adjust_type';
            }
            $this->schema[$table]=array('status'=>$columns?'TABLE_EXISTS':'TABLE_MISSING','column_status'=>$missing?'COLUMN_MISSING':'COLUMNS_PRESENT','variant_status'=>'COLUMN_VARIANT','variant'=>$variant,'columns'=>$columns,'missing_columns'=>$missing);
            $domain=strpos($table,'attendance')!==false?'Attendance':'Leave';
            if (!$columns) $this->support->issue($domain,'TABLE_MISSING_'.$table,'BLOCKING');
            elseif ($missing) $this->support->issue($domain,'COLUMN_MISSING_'.$table,'BLOCKING',count($missing));
        }
        $attendance=array('schema'=>$this->schema,'records'=>$this->records(),'requests'=>$this->requests());
        $leave=$this->leave();
        return array('Attendance'=>$attendance,'Leave'=>$leave);
    }
    private function records()
    {
        $t='cpms_attendance_records'; if (!$this->has($t,'id')) return array();
        $expr=array('total'=>'COUNT(*)');
        if ($this->has($t,'work_date')) { $expr['first_date']='MIN(work_date)'; $expr['last_date']='MAX(work_date)'; }
        if ($this->has($t,'employee_id')) $expr['employee_count']='COUNT(DISTINCT employee_id)';
        foreach (array('check_in','check_out','memo') as $c) if ($this->has($t,$c)) $expr[$c.'_present']="SUM(CASE WHEN `$c` IS NOT NULL".($c==='memo'?" AND `$c`<>''":'')." THEN 1 ELSE 0 END)";
        $r=$this->aggregate($t,$expr); $r['employee_orphans']=$this->orphan($t,'employee_id','Attendance');
        if ($this->has($t,'employee_id') && $this->has($t,'work_date')) {
            $r['duplicate_employee_dates']=(int)$this->scalar('SELECT COUNT(*) FROM (SELECT employee_id,work_date FROM cpms_attendance_records GROUP BY employee_id,work_date HAVING COUNT(*)>1) d');
            $this->support->issue('Attendance','DUPLICATE_EMPLOYEE_DATE','BLOCKING',$r['duplicate_employee_dates']);
        }
        if ($this->has($t,'check_in') && $this->has($t,'check_out')) {
            $r['missing_checkout']=$this->anomaly($t,'t.check_in IS NOT NULL AND t.check_out IS NULL','MISSING_CHECKOUT');
            $r['checkout_only']=$this->anomaly($t,'t.check_in IS NULL AND t.check_out IS NOT NULL','CHECKOUT_ONLY');
            $r['checkout_before_checkin']=$this->anomaly($t,'t.check_out<t.check_in','CHECKOUT_BEFORE_CHECKIN','Attendance','BLOCKING');
            if ($this->has($t,'work_date')) {
                $r['missing_checkout_preview']=array('as_of_date'=>$this->today,'past_missing_checkout'=>(int)$this->scalar('SELECT COUNT(*) FROM cpms_attendance_records WHERE check_in IS NOT NULL AND check_out IS NULL AND work_date<?',array($this->today)),'today_in_progress'=>(int)$this->scalar('SELECT COUNT(*) FROM cpms_attendance_records WHERE check_in IS NOT NULL AND check_out IS NULL AND work_date=?',array($this->today)),'future_records'=>(int)$this->scalar('SELECT COUNT(*) FROM cpms_attendance_records WHERE check_in IS NOT NULL AND check_out IS NULL AND work_date>?',array($this->today)),'past_target_status'=>'missing_checkout','diagnostic_only'=>true);
            }
            $r['reversed_diagnostic']=$this->reversedAttendance($r['checkout_before_checkin']);
        }
        foreach (array('check_in','check_out') as $c) if ($this->has($t,$c) && $this->has($t,'work_date')) $r[$c.'_date_mismatch']=$this->anomaly($t,'DATE(t.`'.$c.'`)<>t.work_date',strtoupper($c).'_DATE_MISMATCH');
        foreach (array('raw_minutes','work_minutes') as $c) if ($this->has($t,$c)) {
            $r[$c.'_negative']=$this->anomaly($t,'t.`'.$c.'`<0',strtoupper($c).'_NEGATIVE','Attendance','BLOCKING');
            $r[$c.'_over_1440']=$this->anomaly($t,'t.`'.$c.'`>1440',strtoupper($c).'_OVER_1440');
        }
        $r['statuses']=$this->groups($t,'status','Attendance');
        foreach ($r['statuses'] as &$s) {
            $s['mapping']=in_array($s['value'],array('출근중','퇴근완료'))?'KNOWN':($s['value']==='출근전'?'NEEDS_MAPPING':'BLOCKING_UNKNOWN');
            if ($s['mapping']!=='KNOWN') $this->support->issue('Attendance',$s['mapping']==='NEEDS_MAPPING'?'STATUS_NEEDS_MAPPING':'STATUS_UNKNOWN',$s['mapping']==='NEEDS_MAPPING'?'WARNING':'BLOCKING',$s['count']);
        } unset($s);
        $r['late_preview']=array('rule'=>'CURRENT_POSITION_ONLY; REGULAR=08:00; VICE_PRESIDENT=08:30','historical_status_confirmed'=>false,'late_count'=>null);
        if ($this->has('employees','position') && $this->has($t,'check_in') && $this->has($t,'employee_id')) {
            $position='TRIM(e.position)'; foreach (array(' ',"\t","\r","\n",'[',']','(',')','{','}') as $char) $position="REPLACE(".$position.",'".$char."','')";
            $r['late_preview']['late_count']=(int)$this->scalar("SELECT COUNT(*) FROM cpms_attendance_records t JOIN employees e ON e.id=t.employee_id WHERE TIME_FORMAT(t.check_in,'%H:%i')>CASE WHEN ".$position."='부사장' THEN '08:30' ELSE '08:00' END");
            $r['late_preview']['vice_president_records']=(int)$this->scalar("SELECT COUNT(*) FROM cpms_attendance_records t JOIN employees e ON e.id=t.employee_id WHERE t.check_in IS NOT NULL AND ".$position."='부사장'");
        }
        return $r;
    }
    private static function safeDatetime($value)
    {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?$/D',$value,$m)) return null;
        if (!checkdate((int)$m[2],(int)$m[3],(int)$m[1]) || (int)$m[4]>23 || (int)$m[5]>59 || (int)$m[6]>59) return null;
        return str_replace('T',' ',$value);
    }
    private function reversedAttendance($total)
    {
        $out=array('total'=>(int)$total,'display_limit'=>100,'truncated'=>(int)$total>100,'classifications'=>array(),'records'=>array(),'diagnostic_only'=>true);
        $t='cpms_attendance_records';
        if (!$total || !$this->has($t,'employee_id') || !$this->has($t,'work_date')) return $out;
        $fields=array('id','employee_id','work_date','check_in','check_out','status','raw_minutes','work_minutes','created_at','updated_at');
        $fields=array_intersect($fields,$this->schema[$t]['columns']);
        $st=$this->source->query('SELECT `'.implode('`,`',$fields).'` FROM cpms_attendance_records WHERE check_out<check_in ORDER BY id LIMIT 100');
        while ($row=$st->fetch(PDO::FETCH_ASSOC)) {
            $record=array('legacy_id'=>(int)$row['id'],'employee_id'=>(int)$row['employee_id'],'work_date'=>Cpms2ManagementPreflightSupport::safeDate($row['work_date']),'status'=>isset($row['status'])?Cpms2ManagementPreflightSupport::statusLabel($row['status']):null);
            foreach (array('check_in','check_out','created_at','updated_at') as $key) $record[$key]=isset($row[$key])?self::safeDatetime($row[$key]):null;
            foreach (array('raw_minutes','work_minutes') as $key) $record[$key]=isset($row[$key])?(int)$row[$key]:null;
            $record['same_day_record_count']=(int)$this->scalar('SELECT COUNT(*) FROM cpms_attendance_records WHERE employee_id=? AND work_date=?',array($record['employee_id'],$row['work_date']));
            $record['related_requests']=$this->relatedRequests($record['employee_id'],$row['work_date']);
            $record['classification']=$this->reversedClassification($record);
            $class=$record['classification']; if (!isset($out['classifications'][$class])) $out['classifications'][$class]=0; $out['classifications'][$class]++;
            $out['records'][]=$record;
        }
        if ($out['truncated']) $this->support->issue('Attendance','REVERSED_DIAGNOSTIC_TRUNCATED');
        return $out;
    }
    private function relatedRequests($employeeId,$date)
    {
        $t='cpms_attendance_requests'; $required=array('id','employee_id','request_date','request_type','status','requested_check_in','requested_check_out','reviewed_at');
        $out=array('available'=>false,'count'=>0,'approved_count'=>0,'display_limit'=>20,'truncated'=>false,'requests'=>array());
        if (count(array_diff($required,isset($this->schema[$t])?$this->schema[$t]['columns']:array()))) return $out;
        $out['available']=true; $params=array($employeeId,$date);
        $out['count']=(int)$this->scalar('SELECT COUNT(*) FROM cpms_attendance_requests WHERE employee_id=? AND request_date=?',$params);
        $out['approved_count']=(int)$this->scalar("SELECT COUNT(*) FROM cpms_attendance_requests WHERE employee_id=? AND request_date=? AND status='approved'",$params);
        $out['truncated']=$out['count']>20;
        $reason=$this->has($t,'reason')?"CASE WHEN reason IS NOT NULL AND TRIM(reason)<>'' THEN 1 ELSE 0 END":"0";
        $st=$this->source->query('SELECT id,request_type,requested_check_in,requested_check_out,status,reviewed_at,'.$reason.' AS reason_present FROM cpms_attendance_requests WHERE employee_id=? AND request_date=? ORDER BY id DESC LIMIT 20',$params);
        while ($r=$st->fetch(PDO::FETCH_ASSOC)) {
            $out['requests'][]=array('legacy_id'=>(int)$r['id'],'request_type'=>Cpms2ManagementPreflightSupport::statusLabel($r['request_type']),'requested_check_in'=>self::safeDatetime($r['requested_check_in']),'requested_check_out'=>self::safeDatetime($r['requested_check_out']),'status'=>Cpms2ManagementPreflightSupport::statusLabel($r['status']),'reviewed_at'=>self::safeDatetime($r['reviewed_at']),'reason_present'=>(bool)$r['reason_present']);
        }
        return $out;
    }
    private function reversedClassification(&$record)
    {
        $related=$record['related_requests'];
        // No audit identifies a manual edit versus an original bad row. Never invent an origin.
        $record['origin_evidence']='ORIGIN_NOT_RECORDED'; $record['reconstruction_candidate']=null;
        if (!$related['available'] || $related['truncated'] || $record['same_day_record_count']!==1 || $related['approved_count']>1) return 'AMBIGUOUS';
        if (!$related['approved_count']) return 'SOURCE_RECORD_REVERSED';
        $request=null; foreach ($related['requests'] as $r) if ($r['status']==='approved') $request=$r;
        if (!$request || !$request['reviewed_at'] || !$record['work_date']) return 'AMBIGUOUS';
        $record['origin_evidence']=$record['updated_at']!==null && $record['updated_at']===$request['reviewed_at']?'FINAL_UPDATE_MATCHES_APPROVAL_TIME':'SAME_EMPLOYEE_DATE_SINGLE_APPROVAL; FINAL_EDIT_ORIGIN_UNCONFIRMED';
        $type=$request['request_type']; if (!in_array($type,array('check_in','check_out','both'),true)) return 'AMBIGUOUS';
        $ci=$type==='check_out'?$record['check_in']:$request['requested_check_in'];
        $co=$type==='check_in'?$record['check_out']:$request['requested_check_out'];
        if ($ci===null || $co===null || substr($ci,0,10)!==$record['work_date'] || substr($co,0,10)!==$record['work_date']) return 'AMBIGUOUS';
        if ($co<$ci) return $type==='both'?'REQUEST_ALSO_REVERSED':'SOURCE_RECORD_REVERSED';
        $record['reconstruction_candidate']=array('request_legacy_id'=>$request['legacy_id'],'check_in'=>$ci,'check_out'=>$co,'evidence'=>'UNIQUE_EMPLOYEE_DATE_RECORD_AND_APPROVED_REQUEST','automatic_apply'=>false);
        return 'VALID_REQUEST_CAN_RECONSTRUCT';
    }
    private function orphanAccruals()
    {
        $t='cpms_leave_accrual_logs'; $out=array('employee_count'=>0,'row_count'=>0,'display_limit'=>100,'truncated'=>false,'employees'=>array());
        if (!$this->has($t,'employee_id') || !$this->has('employees','id')) return $out;
        $where='a.employee_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM employees e WHERE e.id=a.employee_id)';
        $out['row_count']=(int)$this->scalar('SELECT COUNT(*) FROM cpms_leave_accrual_logs a WHERE '.$where);
        $out['employee_count']=(int)$this->scalar('SELECT COUNT(DISTINCT a.employee_id) FROM cpms_leave_accrual_logs a WHERE '.$where);
        $out['truncated']=$out['employee_count']>100;
        $expr=array('a.employee_id','COUNT(*) AS row_count');
        foreach (array('MONTHLY','ANNUAL') as $type) $expr[]=$this->has($t,'leave_type')?"SUM(CASE WHEN a.leave_type='$type' THEN 1 ELSE 0 END) AS ".strtolower($type).'_count':'NULL AS '.strtolower($type).'_count';
        foreach (array('accrual_date','created_at') as $key) foreach (array('first'=>'MIN','last'=>'MAX') as $name=>$fn) $expr[]=($this->has($t,$key)?$fn.'(a.`'.$key.'`)':'NULL').' AS '.$name.'_'.$key;
        $expr[]=($this->has($t,'amount')?'COALESCE(SUM(a.amount),0)':'NULL').' AS amount_sum';
        // Exact messages verified in leave_management_helpers.php. Free-text reason never leaves SQL.
        $reasonSql=$this->has($t,'reason')?"CASE WHEN TRIM(a.reason)=? THEN 'AUTO_MONTHLY' WHEN TRIM(a.reason)=? THEN 'AUTO_ANNUAL' WHEN TRIM(a.reason) IN (?,?) THEN 'LEGACY_BALANCE_CONFIRMATION' ELSE 'UNKNOWN' END":"'UNKNOWN'";
        $reasonParams=$this->has($t,'reason')?array('입사일 기준 월차 자동 발생','입사일 기준 연차 자동 발생','기존 월차 발생일 확인(최초 잔여 유지)','기존 연차 발생일 확인(최초 잔여 유지)'):array();
        $st=$this->source->query('SELECT '.implode(',',$expr).' FROM cpms_leave_accrual_logs a WHERE '.$where.' GROUP BY a.employee_id ORDER BY a.employee_id LIMIT 100');
        while ($r=$st->fetch(PDO::FETCH_ASSOC)) {
            foreach (array('employee_id','row_count','monthly_count','annual_count') as $key) $r[$key]=$r[$key]===null?null:(int)$r[$key];
            foreach (array('first_created_at','last_created_at') as $key) $r[$key]=self::safeDatetime($r[$key]);
            foreach (array('first_accrual_date','last_accrual_date') as $key) $r[$key]=Cpms2ManagementPreflightSupport::safeDate($r[$key]);
            $r['amount_sum']=$r['amount_sum']===null?null:Cpms2MigrationDecimal::normalize($r['amount_sum']);
            $r['reason_types']=array();
            $reasons=$this->source->query('SELECT '.$reasonSql.' AS reason_type,COUNT(*) AS count FROM cpms_leave_accrual_logs a WHERE a.employee_id=? GROUP BY reason_type',array_merge($reasonParams,array($r['employee_id'])));
            while ($reason=$reasons->fetch(PDO::FETCH_ASSOC)) $r['reason_types'][]=array('type'=>$reason['reason_type'],'count'=>(int)$reason['count']);
            $r['reason_type']=count($r['reason_types'])===1?$r['reason_types'][0]['type']:'MIXED';
            $r['relations']=array();
            foreach (array('cpms_attendance_records','cpms_attendance_requests','cpms_approval_leave_deductions','cpms_leave_adjustments','cpms_leave_records') as $related) $r['relations'][$related]=$this->has($related,'employee_id')?(int)$this->scalar('SELECT COUNT(*) FROM `'.$related.'` WHERE employee_id=?',array($r['employee_id'])):null;
            $rel=$r['relations'];
            if ($rel['cpms_attendance_records']>0) $r['classification']='ORPHAN_WITH_ATTENDANCE';
            elseif ($rel['cpms_approval_leave_deductions']>0 || $rel['cpms_leave_records']>0) $r['classification']='ORPHAN_WITH_LEAVE_USAGE';
            elseif ($rel['cpms_attendance_requests']>0 || $rel['cpms_leave_adjustments']>0) $r['classification']='ORPHAN_WITH_OTHER_RELATION';
            else $r['classification']=in_array(null,$rel,true)?'ORPHAN_RELATION_UNAVAILABLE':'ORPHAN_ACCRUAL_ONLY';
            $out['employees'][]=$r;
        }
        if ($out['truncated']) $this->support->issue('Leave','ORPHAN_ACCRUAL_DIAGNOSTIC_TRUNCATED');
        return $out;
    }
    private function requests()
    {
        $t='cpms_attendance_requests'; if (!$this->has($t,'id')) return array();
        $e=array('total'=>'COUNT(*)'); if ($this->has($t,'request_date')) { $e['first_date']='MIN(request_date)'; $e['last_date']='MAX(request_date)'; }
        $r=$this->aggregate($t,$e); $r['requester']='employee_id (no separate requester field)';
        $r['types']=$this->groups($t,'request_type','Attendance'); $r['statuses']=$this->groups($t,'status','Attendance');
        if ($this->has($t,'status')) {
            foreach (array('pending','approved','rejected') as $status) $r[$status]=(int)$this->scalar('SELECT COUNT(*) FROM cpms_attendance_requests WHERE status=?',array($status));
            $r['unknown_status']=$this->anomaly($t,"t.status IS NULL OR t.status NOT IN ('pending','approved','rejected')",'REQUEST_STATUS_UNKNOWN','Attendance','BLOCKING');
        }
        $r['employee_orphans']=$this->orphan($t,'employee_id','Attendance'); $r['reviewer_orphans']=$this->orphan($t,'reviewed_by','Attendance');
        if ($this->has($t,'status') && $this->has($t,'reviewed_at')) $r['completed_without_review_time']=$this->anomaly($t,"t.status IN ('approved','rejected') AND t.reviewed_at IS NULL",'COMPLETED_WITHOUT_REVIEW_TIME');
        foreach (array('reason','reject_reason') as $c) if ($this->has($t,$c)) $r[$c.'_over_500']=$this->anomaly($t,'CHAR_LENGTH(t.`'.$c.'`)>500',strtoupper($c).'_OVER_500');
        return $r;
    }
    private function leave()
    {
        $out=array('approval_document_statuses'=>$this->groups('cpms_approval_documents','doc_status','Leave'));
        foreach (array('cpms_leave_records','cpms_leave_adjustments','cpms_leave_accrual_logs','cpms_approval_leave_deductions') as $t) {
            if (!$this->has($t,'id')) { $out[$t]=array(); continue; }
            $amount=$t==='cpms_leave_records'?'leave_amount':($t==='cpms_approval_leave_deductions'?'deduct_amount':'amount');
            $expr=array('total'=>'COUNT(*)'); if ($this->has($t,$amount)) $expr['amount_sum']='COALESCE(SUM(`'.$amount.'`),0)';
            $date=$t==='cpms_leave_records'?'leave_date':($t==='cpms_leave_accrual_logs'?'accrual_date':'created_at');
            if ($this->has($t,$date)) { $expr['first_date']='MIN(`'.$date.'`)'; $expr['last_date']='MAX(`'.$date.'`)'; }
            $r=$this->aggregate($t,$expr); $r['employee_orphans']=$this->orphan($t,'employee_id','Leave');
            foreach (array('leave_type','adjust_type','leave_bucket','target_year','accrual_year') as $c) $r[$c.'_groups']=$this->groups($t,$c,'Leave',$amount);
            // Numeric years and decimal amounts are returned by dedicated aggregates, never free text.
            if ($this->has($t,'target_year')) $r['invalid_target_year']=$this->anomaly($t,'t.target_year IS NULL OR t.target_year<1900 OR t.target_year>2200','INVALID_TARGET_YEAR','Leave');
            if ($t==='cpms_leave_adjustments') $r['target_year_status']=$this->has($t,'target_year')?'PRESENT':'NOT_AVAILABLE_LEGACY_SCHEMA';
            if ($t==='cpms_leave_records' && $this->has($t,$amount)) {
                $r['nonpositive_amount']=$this->anomaly($t,'t.leave_amount<=0','NONPOSITIVE_LEAVE','Leave','BLOCKING');
                $r['half_day_or_less']=(int)$this->scalar('SELECT COUNT(*) FROM cpms_leave_records WHERE leave_amount>0 AND leave_amount<=0.5');
                $r['half_day_types']=array();
                if ($this->has($t,'leave_type')) {
                    $st=$this->source->query('SELECT leave_type,COUNT(*) AS count FROM cpms_leave_records WHERE leave_amount>0 AND leave_amount<=0.5 GROUP BY leave_type LIMIT 100');
                    while ($a=$st->fetch(PDO::FETCH_ASSOC)) $r['half_day_types'][]=array('type'=>Cpms2ManagementPreflightSupport::statusLabel($a['leave_type']),'count'=>(int)$a['count']);
                }
                $r['amount_groups']=array(); $st=$this->source->query('SELECT leave_amount,COUNT(*) AS count FROM cpms_leave_records GROUP BY leave_amount LIMIT 100');
                while ($a=$st->fetch(PDO::FETCH_ASSOC)) $r['amount_groups'][]=array('amount'=>(string)$a['leave_amount'],'count'=>(int)$a['count']);
                if ($this->has($t,'leave_date') && $this->has($t,'leave_type') && $this->has($t,'employee_id')) {
                    $r['duplicate_candidates']=(int)$this->scalar('SELECT COUNT(*) FROM (SELECT employee_id,leave_date,leave_type,leave_amount FROM cpms_leave_records GROUP BY employee_id,leave_date,leave_type,leave_amount HAVING COUNT(*)>1) d');
                    $this->support->issue('Leave','DUPLICATE_LEAVE_CANDIDATE','WARNING',$r['duplicate_candidates']);
                }
            }
            if ($t==='cpms_approval_leave_deductions') $r['document_orphans']=$this->orphan($t,'document_id','Leave','cpms_approval_documents');
            if ($t==='cpms_leave_accrual_logs') $r['orphan_diagnostic']=$this->orphanAccruals();
            $out[$t]=$r;
        }
        $out['restore_logs']=$this->has('cpms_approval_logs','action_type')?(int)$this->scalar("SELECT COUNT(*) FROM cpms_approval_logs WHERE action_type='LEAVE_RESTORE'"):null;
        $out['balances']=$this->balances();
        $out['holidays']=array(); $t='cpms_holiday_cache';
        if ($this->has($t,'holiday_date') && $this->has($t,'is_active')) {
            $out['holidays']=$this->source->query('SELECT COUNT(*) AS active_count,MIN(holiday_date) AS first_date,MAX(holiday_date) AS last_date FROM cpms_holiday_cache WHERE is_active=1')->fetch(PDO::FETCH_ASSOC);
            if ($this->has($t,'source')) {
                $out['holidays']['sources']=array(); $st=$this->source->query('SELECT source,COUNT(*) AS count FROM cpms_holiday_cache WHERE is_active=1 GROUP BY source LIMIT 100');
                while ($a=$st->fetch(PDO::FETCH_ASSOC)) $out['holidays']['sources'][]=array('source'=>Cpms2ManagementPreflightSupport::statusLabel($a['source']),'count'=>(int)$a['count']);
            }
            if ($this->has('cpms_attendance_records','work_date')) $out['holidays']['within_attendance_period']=(int)$this->scalar('SELECT COUNT(*) FROM cpms_holiday_cache WHERE is_active=1 AND holiday_date BETWEEN (SELECT MIN(work_date) FROM cpms_attendance_records) AND (SELECT MAX(work_date) FROM cpms_attendance_records)');
        }
        return $out;
    }
    private function balances()
    {
        $r=array('policy'=>'CUTOFF_CURRENT_BALANCE_SNAPSHOT','reconstructable_employees'=>0,'candidate_mismatches'=>0,'unreconstructable_employees'=>0);
        $expr=array('employee_count'=>'COUNT(*)');
        foreach (array('monthly','annual','half') as $b) if ($this->has('employees','leave_'.$b.'_balance')) {
            $c='leave_'.$b.'_balance'; $expr[$b.'_values']='COUNT(`'.$c.'`)'; $expr[$b.'_sum']='COALESCE(SUM(`'.$c.'`),0)';
        }
        if (!$this->has('employees','id')) return $r;
        $r=array_merge($r,$this->aggregate('employees',$expr));
        // Annual accrual resets the balance. Restore notes do not form a typed ledger.
        // Without an opening balance/full history, a sum is a candidate, not an authoritative reconstruction.
        $r['unreconstructable_employees']=(int)$r['employee_count'];
        $r['limitations']=array('OPENING_BALANCE_NOT_RECORDED','ANNUAL_ACCRUAL_RESETS_BALANCE','RESTORE_AMOUNT_NOT_TYPED','DIRECT_LEAVE_NOT_ALWAYS_DEDUCTED');
        $this->support->issue('Leave','BALANCE_LEDGER_INCOMPLETE','WARNING',(int)$r['employee_count']);
        if ($this->has('employees','leave_monthly_balance') && $this->has('cpms_leave_accrual_logs','employee_id') && $this->has('cpms_leave_accrual_logs','amount') && $this->has('cpms_leave_accrual_logs','leave_type')) {
            $candidate="COALESCE((SELECT SUM(a.amount) FROM cpms_leave_accrual_logs a WHERE a.employee_id=e.id AND a.leave_type='MONTHLY'),0)";
            if ($this->has('cpms_approval_leave_deductions','employee_id') && $this->has('cpms_approval_leave_deductions','leave_bucket') && $this->has('cpms_approval_leave_deductions','deduct_amount') && $this->has('cpms_approval_leave_deductions','document_id') && $this->has('cpms_approval_documents','id') && $this->has('cpms_approval_documents','doc_status')) {
                $candidate.="-COALESCE((SELECT SUM(d.deduct_amount) FROM cpms_approval_leave_deductions d JOIN cpms_approval_documents p ON p.id=d.document_id WHERE d.employee_id=e.id AND d.leave_bucket='MONTHLY' AND p.doc_status IN ('APPROVED','COMPLETED')),0)";
            }
            if ($this->has('cpms_leave_adjustments','leave_type') && $this->has('cpms_leave_adjustments','employee_id') && $this->has('cpms_leave_adjustments','amount')) $candidate.="+COALESCE((SELECT SUM(j.amount) FROM cpms_leave_adjustments j WHERE j.employee_id=e.id AND j.leave_type IN ('MONTHLY','월차')),0)";
            $conditions=array('e.leave_monthly_balance IS NOT NULL AND e.leave_monthly_balance<>('.$candidate.')');
            if ($this->has('employees','leave_annual_balance') && $this->has('cpms_leave_accrual_logs','accrual_date')) {
                $anchor="(SELECT MAX(a.accrual_date) FROM cpms_leave_accrual_logs a WHERE a.employee_id=e.id AND a.leave_type='ANNUAL')";
                $annual="COALESCE((SELECT SUM(a.amount) FROM cpms_leave_accrual_logs a WHERE a.employee_id=e.id AND a.leave_type='ANNUAL' AND a.accrual_date=".$anchor."),0)";
                if ($this->has('cpms_approval_leave_deductions','employee_id') && $this->has('cpms_approval_leave_deductions','deducted_at') && $this->has('cpms_approval_leave_deductions','deduct_amount') && $this->has('cpms_approval_leave_deductions','leave_bucket') && $this->has('cpms_approval_leave_deductions','document_id') && $this->has('cpms_approval_documents','id') && $this->has('cpms_approval_documents','doc_status')) $annual.="-COALESCE((SELECT SUM(d.deduct_amount) FROM cpms_approval_leave_deductions d JOIN cpms_approval_documents p ON p.id=d.document_id WHERE d.employee_id=e.id AND d.leave_bucket='ANNUAL' AND p.doc_status IN ('APPROVED','COMPLETED') AND DATE(d.deducted_at)>=COALESCE(".$anchor.",'1900-01-01')),0)";
                if ($this->has('cpms_leave_adjustments','adjust_type') && $this->has('cpms_leave_adjustments','target_year') && $this->has('cpms_leave_adjustments','amount') && $this->has('cpms_leave_adjustments','employee_id')) $annual.="+COALESCE((SELECT SUM(CASE WHEN j.adjust_type='DEDUCT' THEN -j.amount WHEN j.adjust_type='ADD' THEN j.amount ELSE 0 END) FROM cpms_leave_adjustments j WHERE j.employee_id=e.id AND j.target_year=".(int)date('Y')."),0)";
                $conditions[]='e.leave_annual_balance IS NOT NULL AND e.leave_annual_balance<>('.$annual.')';
            }
            $sql='SELECT e.id FROM employees e WHERE '.implode(' OR ',$conditions);
            $r['candidate_mismatches']=(int)$this->scalar('SELECT COUNT(*) FROM ('.$sql.') d');
            $r['balance_mismatch_employees']=$r['candidate_mismatches'];
            $ids=array(); $st=$this->source->query($sql.' ORDER BY e.id LIMIT 20'); while ($a=$st->fetch(PDO::FETCH_ASSOC)) $ids[]=(int)$a['id'];
            $this->support->issue('Leave','BALANCE_MISMATCH','WARNING',$r['candidate_mismatches'],$ids);
            $r['comparison_basis']='PARTIAL_LEDGER: MONTHLY_ACCRUAL - ACTIVE_DEDUCTION + LEGACY_MONTHLY_ADJUSTMENT; ANNUAL_LATEST_RESET - LATER_ACTIVE_DEDUCTION + CURRENT_YEAR_ADJUSTMENT; HALF_LEDGER_UNAVAILABLE';
            $r['restore_handling']='CANCELLED/REJECTED DOCUMENT DEDUCTIONS EXCLUDED; LEAVE_RESTORE NOTES NOT PARSED';
        }
        return $r;
    }
}
