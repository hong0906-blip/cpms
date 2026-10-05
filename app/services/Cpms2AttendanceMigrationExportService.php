<?php
// app/services/Cpms2AttendanceMigrationExportService.php
// PHP 5.6: SELECT/SHOW only. No payroll, workflow, settings or credential export.
require_once __DIR__.'/Cpms2AttendanceExportService.php';
require_once __DIR__.'/Cpms2MigrationDecimal.php';
class Cpms2AttendanceMigrationExportService
{
    public static $entities=array('attendance_records','attendance_requests','leave_accrual_logs','leave_approval_records','leave_approval_deductions','leave_balance_snapshots','leave_restore_events','leave_holidays');
    private $source; private $schema=array(); private $employees=array(); private $cutoff; private $summary;
    // Only these employee scopes were explicitly approved; no general orphan exclusion.
    private static $approvedOrphans=array(39=>array('count'=>2,'amount'=>'1.00'),44=>array('count'=>47,'amount'=>'857.00'));
    public function __construct($source) { $this->source=$source; }
    private function rows($table,$fields,$where='',$params=array())
    {
        $fields=array_intersect(explode(' ',$fields),$this->schema[$table]);
        $last=0;
        do {
            $st=$this->source->query('SELECT `'.implode('`,`',$fields).'` FROM `'.$table.'` WHERE id>?'.($where!==''?' AND ('.$where.')':'').' ORDER BY id LIMIT 500',array_merge(array($last),$params));
            $count=0; while ($r=$st->fetch(PDO::FETCH_ASSOC)) { $last=(int)$r['id']; $count++; yield $r; }
        } while ($count===500);
    }
    private function employee($id) { if (!isset($this->employees[(string)$id])) throw new RuntimeException('LEGACY_ATTENDANCE_EMPLOYEE_UNMAPPED'); }
    public static function reasonType($reason)
    {
        $map=array('입사일 기준 월차 자동 발생'=>'AUTO_MONTHLY','입사일 기준 연차 자동 발생'=>'AUTO_ANNUAL','기존 월차 발생일 확인(최초 잔여 유지)'=>'LEGACY_BALANCE_CONFIRMATION','기존 연차 발생일 확인(최초 잔여 유지)'=>'LEGACY_BALANCE_CONFIRMATION');
        return isset($map[trim((string)$reason)])?$map[trim((string)$reason)]:'UNKNOWN';
    }
    private function emit($writer,$entity,$row)
    {
        $this->summary['record_counts'][$entity]++;
        foreach (array('amount','monthly_balance','annual_balance','half_balance') as $field) if (isset($row[$field])) {
            if (!isset($this->summary['amounts'][$entity][$field])) $this->summary['amounts'][$entity][$field]='0.00';
            $this->summary['amounts'][$entity][$field]=Cpms2MigrationDecimal::add($this->summary['amounts'][$entity][$field],$row[$field]);
        }
        if ($writer) $writer->record($entity,$row);
    }
    public function run($writer=null,$cutoff=null)
    {
        $this->cutoff=$cutoff===null?date('Y-m-d'):$cutoff;
        if (!Cpms2ManagementPreflightSupport::safeDate($this->cutoff)) throw new RuntimeException('LEGACY_ATTENDANCE_DATE_INVALID');
        if (!$this->source->inspect('cpms_attendance_records',false,array())) return array(); // Old installations/packages remain supported.
        $required=array(
            'employees'=>'id hire_date leave_monthly_balance leave_annual_balance leave_half_balance',
            'cpms_attendance_records'=>'id employee_id work_date check_in check_out status raw_minutes work_minutes',
            'cpms_attendance_requests'=>'id employee_id request_date request_type requested_check_in requested_check_out status reviewed_by reviewed_at reason reject_reason created_at',
            'cpms_leave_accrual_logs'=>'id employee_id leave_type accrual_date accrual_year amount',
            'cpms_approval_leave_deductions'=>'id employee_id document_id leave_type leave_bucket deduct_amount balance_before balance_after deducted_at',
            'cpms_approval_documents'=>'id doc_status content created_by_id',
            'cpms_approval_logs'=>'id document_id action_type created_at',
            'cpms_leave_records'=>'id employee_id', 'cpms_leave_adjustments'=>'id employee_id',
            'cpms_holiday_cache'=>'holiday_date source is_active'
        );
        foreach ($required as $table=>$fields) {
            $this->schema[$table]=$this->source->inspect($table,false,array());
            if (!$this->schema[$table] || array_diff(explode(' ',$fields),$this->schema[$table])) throw new RuntimeException('LEGACY_ATTENDANCE_SOURCE_SCHEMA_NOT_READY');
        }
        // This phase's approved source contains neither direct leaves nor manual adjustments.
        foreach (array('cpms_leave_records','cpms_leave_adjustments') as $table) if ((int)$this->source->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()) throw new RuntimeException('LEGACY_LEAVE_DIRECT_SCOPE_CHANGED');
        $this->summary=array('version'=>1,'cutoff_date'=>$this->cutoff,'record_counts'=>array_fill_keys(self::$entities,0),'amounts'=>array(),'attendance'=>array('normal_source'=>0,'missing_checkout_original'=>0,'reversed_normalized'=>0),'requests'=>array('pending'=>0,'approved'=>0,'rejected'=>0),'accrual'=>array('original'=>0,'original_amount'=>'0.00','explicit_orphan_excluded'=>0,'excluded_amount'=>'0.00','zero_confirmation'=>0,'migration_effective'=>0),'excluded_orphans'=>array());
        if ($writer) foreach (self::$entities as $entity) $writer->emptyEntity($entity);
        foreach ($this->rows('employees','id hire_date leave_monthly_balance leave_annual_balance leave_half_balance') as $r) {
            $this->employees[(string)$r['id']]=$r;
            $row=array('legacy_id'=>$r['id'],'legacy_employee_id'=>$r['id'],'cutoff_date'=>$this->cutoff,'source_system'=>'cpms1');
            foreach (array('monthly','annual','half') as $bucket) $row[$bucket.'_balance']=$r['leave_'.$bucket.'_balance']===null?null:Cpms2MigrationDecimal::normalize($r['leave_'.$bucket.'_balance']);
            $this->emit($writer,'leave_balance_snapshots',$row);
        }
        $this->orphanScope();
        $diagnostic=(new Cpms2AttendanceExportService($this->source,new Cpms2ManagementPreflightSupport()))->inspect($this->cutoff);
        $classes=array(); foreach ($diagnostic['Attendance']['records']['reversed_diagnostic']['records'] as $r) $classes[(string)$r['legacy_id']]=$r['classification'];
        $dates=array();
        foreach ($this->rows('cpms_attendance_records','id employee_id work_date check_in check_out status raw_minutes work_minutes memo created_at updated_at') as $r) {
            $this->employee($r['employee_id']); $identity=$r['employee_id'].':'.$r['work_date'];
            if (!in_array($r['status'],array('퇴근완료','출근중')) || !Cpms2ManagementPreflightSupport::safeDate($r['work_date'])) throw new RuntimeException('LEGACY_ATTENDANCE_ROW_INVALID');
            foreach (array('raw_minutes','work_minutes') as $field) if (!preg_match('/^\d{1,6}$/D',(string)$r[$field])) throw new RuntimeException('LEGACY_ATTENDANCE_ROW_INVALID');
            foreach (array('check_in','check_out') as $field) if ($r[$field]!==null && $r[$field]!=='') {
                $time=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$r[$field]);
                if (!$time || $time->format('Y-m-d H:i:s')!==$r[$field] || substr($r[$field],0,10)!==$r['work_date']) throw new RuntimeException('LEGACY_ATTENDANCE_ROW_INVALID');
            }
            if (isset($dates[$identity])) throw new RuntimeException('LEGACY_ATTENDANCE_DUPLICATE_DATE'); $dates[$identity]=true;
            $row=Cpms2MigrationExportService::legacy($r); $row['source_status']=$r['status'];
            if ($r['check_in'] && $r['check_out'] && $r['check_out']<$r['check_in']) {
                $row['legacy_anomaly']='CHECKOUT_BEFORE_CHECKIN'; $row['classification']=isset($classes[(string)$r['id']])?$classes[(string)$r['id']]:'AMBIGUOUS';
                $this->summary['attendance']['reversed_normalized']++;
            } elseif ($r['status']==='출근중' && $r['check_in'] && !$r['check_out'] && $r['work_date']<=$this->cutoff) $this->summary['attendance']['missing_checkout_original']++;
            elseif ($r['status']==='퇴근완료' && $r['check_in'] && $r['check_out']) $this->summary['attendance']['normal_source']++;
            else throw new RuntimeException('LEGACY_ATTENDANCE_STATUS_UNKNOWN');
            $this->emit($writer,'attendance_records',$row);
        }
        foreach ($this->rows('cpms_attendance_requests','id employee_id request_date request_type requested_check_in requested_check_out reason status reviewed_by reviewed_at reject_reason created_at updated_at') as $r) {
            $this->employee($r['employee_id']); if (!empty($r['reviewed_by'])) $this->employee($r['reviewed_by']);
            if (!isset($this->summary['requests'][$r['status']]) || !in_array($r['request_type'],array('check_in','check_out','both'))) throw new RuntimeException('LEGACY_ATTENDANCE_REQUEST_INVALID');
            $r['reviewed_employee_id']=$r['reviewed_by']; unset($r['reviewed_by']);
            $this->summary['requests'][$r['status']]++; $this->emit($writer,'attendance_requests',Cpms2MigrationExportService::legacy($r));
        }
        foreach ($this->rows('cpms_leave_accrual_logs','id employee_id leave_type accrual_date accrual_year amount reason created_at') as $r) {
            $a=&$this->summary['accrual']; $amount=Cpms2MigrationDecimal::normalize($r['amount']); $a['original']++; $a['original_amount']=Cpms2MigrationDecimal::add($a['original_amount'],$amount);
            if (isset($this->summary['excluded_orphans'][(string)$r['employee_id']])) { $a['explicit_orphan_excluded']++; $a['excluded_amount']=Cpms2MigrationDecimal::add($a['excluded_amount'],$amount); continue; }
            $this->employee($r['employee_id']); if (substr($amount,0,1)==='-') throw new RuntimeException('LEGACY_LEAVE_AMOUNT_INVALID');
            $r['amount']=$amount; $r['source_reason_type']=self::reasonType(isset($r['reason'])?$r['reason']:''); unset($r['reason']);
            if ($amount==='0.00') $a['zero_confirmation']++; else $a['migration_effective']++;
            if (!in_array($r['leave_type'],array('MONTHLY','ANNUAL'))) throw new RuntimeException('LEGACY_LEAVE_TYPE_INVALID');
            $this->emit($writer,'leave_accrual_logs',Cpms2MigrationExportService::legacy($r));
        }
        $this->approvals($writer);
        $st=$this->source->query('SELECT holiday_date,source FROM cpms_holiday_cache WHERE is_active=1 ORDER BY holiday_date,source');
        while ($r=$st->fetch(PDO::FETCH_ASSOC)) $this->emit($writer,'leave_holidays',array('legacy_id'=>$r['holiday_date'].':'.$r['source'],'holiday_date'=>$r['holiday_date'],'source'=>$r['source']));
        if ($writer) $writer->managementSummary=$this->summary;
        return $this->summary;
    }
    private function orphanScope()
    {
        foreach (self::$approvedOrphans as $id=>$scope) {
            $r=$this->source->query('SELECT COUNT(*) AS row_count,COALESCE(SUM(amount),0) AS amount FROM cpms_leave_accrual_logs WHERE employee_id=?',array($id))->fetch(PDO::FETCH_ASSOC);
            if (isset($this->employees[(string)$id]) || (int)$r['row_count']!==$scope['count'] || Cpms2MigrationDecimal::normalize($r['amount'])!==$scope['amount']) throw new RuntimeException('ORPHAN_LEAVE_EXCLUSION_SCOPE_CHANGED');
        }
        $st=$this->source->query('SELECT employee_id,COUNT(*) AS row_count,COALESCE(SUM(amount),0) AS amount FROM cpms_leave_accrual_logs a WHERE NOT EXISTS (SELECT 1 FROM employees e WHERE e.id=a.employee_id) GROUP BY employee_id');
        while ($r=$st->fetch(PDO::FETCH_ASSOC)) {
            $id=(int)$r['employee_id']; $scope=isset(self::$approvedOrphans[$id])?self::$approvedOrphans[$id]:null;
            if (!$scope || (int)$r['row_count']!==$scope['count'] || Cpms2MigrationDecimal::normalize($r['amount'])!==$scope['amount']) throw new RuntimeException('ORPHAN_LEAVE_EXCLUSION_SCOPE_CHANGED');
            foreach (array('cpms_attendance_records','cpms_attendance_requests','cpms_approval_leave_deductions','cpms_leave_adjustments','cpms_leave_records') as $table) if ((int)$this->source->query('SELECT COUNT(*) FROM '.$table.' WHERE employee_id=?',array($id))->fetchColumn()) throw new RuntimeException('ORPHAN_LEAVE_EXCLUSION_SCOPE_CHANGED');
            $this->summary['excluded_orphans'][(string)$id]=array('reason_code'=>'EXPLICIT_LEGACY_LEAVE_ORPHAN_EXCLUSION','count'=>$scope['count'],'amount'=>$scope['amount'],'employee_master_present'=>false,'other_relation_count'=>0);
        }
        // Once either approved orphan remains in source, both fixed scopes must remain intact.
        if ($this->summary['excluded_orphans'] && count($this->summary['excluded_orphans'])!==count(self::$approvedOrphans)) throw new RuntimeException('ORPHAN_LEAVE_EXCLUSION_SCOPE_CHANGED');
    }
    private function approvals($writer)
    {
        $conflicts=array();
        $orphanRestores=$this->source->query("SELECT l.document_id,COUNT(*) AS restore_count,MAX(doc.created_by_id) AS employee_id FROM cpms_approval_logs l LEFT JOIN cpms_approval_documents doc ON doc.id=l.document_id WHERE l.action_type='LEAVE_RESTORE' AND NOT EXISTS (SELECT 1 FROM cpms_approval_leave_deductions d WHERE d.document_id=l.document_id) GROUP BY l.document_id ORDER BY l.document_id");
        while ($orphan=$orphanRestores->fetch(PDO::FETCH_ASSOC)) {
            $conflicts[]=self::leaveDocumentConflict(null,$orphan['document_id'],$orphan['employee_id'],'LEAVE_RESTORE_WITHOUT_DEDUCTION',array('restore_count'=>(int)$orphan['restore_count']));
        }
        foreach ($this->rows('cpms_approval_leave_deductions','id employee_id document_id leave_type leave_bucket deduct_amount balance_before balance_after deducted_at created_at') as $d) {
            $this->employee($d['employee_id']);
            $doc=$this->source->query('SELECT id,doc_status,content,created_by_id FROM cpms_approval_documents WHERE id=?',array($d['document_id']))->fetch(PDO::FETCH_ASSOC);
            if (!$doc) { $conflicts[]=self::leaveDocumentConflict($d['id'],$d['document_id'],$d['employee_id'],'LEAVE_DOCUMENT_NOT_FOUND'); continue; }
            $rowConflicts=array();
            if ((string)$doc['created_by_id']!==(string)$d['employee_id']) {
                $rowConflicts[]=self::leaveDocumentConflict($d['id'],$d['document_id'],$d['employee_id'],'LEAVE_DOCUMENT_EMPLOYEE_MISMATCH',array('deduction_employee_id'=>(int)$d['employee_id'],'document_created_by_id'=>(int)$doc['created_by_id']));
            }
            $content=self::decodeContent($doc['content']);
            if ($content===null) { $rowConflicts[]=self::leaveDocumentConflict($d['id'],$d['document_id'],$d['employee_id'],'LEAVE_DOCUMENT_CONTENT_INVALID'); $conflicts=array_merge($conflicts,$rowConflicts); continue; }
            $type=isset($content['request_type'])?trim((string)$content['request_type']):'';
            $types=array('월차'=>'monthly','연차'=>'annual','반차 오전'=>'morning_half','반차 오후'=>'afternoon_half');
            $bucket=strtolower(trim((string)$d['leave_bucket']));
            if ($type==='') $rowConflicts[]=self::leaveDocumentConflict($d['id'],$d['document_id'],$d['employee_id'],'LEAVE_REQUEST_TYPE_MISSING');
            elseif (!isset($types[$type])) $rowConflicts[]=self::leaveDocumentConflict($d['id'],$d['document_id'],$d['employee_id'],'LEAVE_REQUEST_TYPE_UNKNOWN',array('document_request_type'=>'UNKNOWN'));
            elseif ($type!==$d['leave_type']) $rowConflicts[]=self::leaveDocumentConflict($d['id'],$d['document_id'],$d['employee_id'],'LEAVE_TYPE_MISMATCH',array('deduction_leave_type'=>self::safeLeaveType($d['leave_type']),'document_request_type'=>$type));
            if (!in_array($bucket,array('monthly','annual'),true)) $rowConflicts[]=self::leaveDocumentConflict($d['id'],$d['document_id'],$d['employee_id'],'LEAVE_BUCKET_INVALID',array('deduction_leave_bucket'=>'UNKNOWN'));
            $start=isset($content['leave_start_date'])?substr(trim($content['leave_start_date']),0,10):'';
            $end=isset($content['leave_end_date'])?substr(trim($content['leave_end_date']),0,10):'';
            $startValid=Cpms2ManagementPreflightSupport::safeDate($start); $endValid=Cpms2ManagementPreflightSupport::safeDate($end);
            if (!$startValid) $rowConflicts[]=self::leaveDocumentConflict($d['id'],$d['document_id'],$d['employee_id'],'LEAVE_START_DATE_INVALID',array('start_date'=>'INVALID'));
            if (!$endValid) $rowConflicts[]=self::leaveDocumentConflict($d['id'],$d['document_id'],$d['employee_id'],'LEAVE_END_DATE_INVALID',array('end_date'=>'INVALID'));
            if ($startValid && $endValid && $end<$start) $rowConflicts[]=self::leaveDocumentConflict($d['id'],$d['document_id'],$d['employee_id'],'LEAVE_DATE_RANGE_INVALID',array('start_date'=>$start,'end_date'=>$end));
            $hireDate=trim((string)$this->employees[(string)$d['employee_id']]['hire_date']);
            if ($hireDate==='') $rowConflicts[]=self::leaveDocumentConflict($d['id'],$d['document_id'],$d['employee_id'],'LEAVE_HIRE_DATE_MISSING');
            elseif (!Cpms2ManagementPreflightSupport::safeDate($hireDate)) $rowConflicts[]=self::leaveDocumentConflict($d['id'],$d['document_id'],$d['employee_id'],'LEAVE_HIRE_DATE_INVALID',array('hire_date'=>'INVALID'));
            elseif ($startValid && in_array($bucket,array('monthly','annual'),true)) {
                $expectedBucket=self::expectedLeaveBucket($hireDate,$start);
                if ($bucket!==$expectedBucket) $rowConflicts[]=self::leaveDocumentConflict($d['id'],$d['document_id'],$d['employee_id'],'LEAVE_BUCKET_HIRE_DATE_MISMATCH',array('deduction_leave_bucket'=>$bucket,'expected_leave_bucket'=>$expectedBucket,'hire_date'=>$hireDate,'leave_start_date'=>$start));
            }
            if (!in_array($doc['doc_status'],array('APPROVED','COMPLETED','CANCELLED','REJECTED'),true)) $rowConflicts[]=self::leaveDocumentConflict($d['id'],$d['document_id'],$d['employee_id'],'LEAVE_DOCUMENT_STATUS_INVALID',array('doc_status'=>self::safeDocumentStatus($doc['doc_status'])));
            $amount=Cpms2MigrationDecimal::normalize($d['deduct_amount']);
            if ($amount==='0.00' || substr($amount,0,1)==='-' || (isset($types[$type]) && strpos($types[$type],'half')!==false && $amount!=='0.50')) throw new RuntimeException('LEGACY_LEAVE_AMOUNT_INVALID');
            $restoreRows=array(); $restores=$this->source->query("SELECT id,created_at FROM cpms_approval_logs WHERE document_id=? AND action_type='LEAVE_RESTORE' ORDER BY id",array($d['document_id']));
            while ($log=$restores->fetch(PDO::FETCH_ASSOC)) $restoreRows[]=$log;
            $restoreCount=count($restoreRows);
            if ($restoreCount>1) $rowConflicts[]=self::leaveDocumentConflict($d['id'],$d['document_id'],$d['employee_id'],'LEAVE_MULTIPLE_RESTORE_LOGS',array('restore_count'=>$restoreCount,'doc_status'=>self::safeDocumentStatus($doc['doc_status'])));
            if ($restoreCount>0 && $doc['doc_status']!=='CANCELLED') $rowConflicts[]=self::leaveDocumentConflict($d['id'],$d['document_id'],$d['employee_id'],'LEAVE_RESTORE_ON_NON_CANCELLED_DOCUMENT',array('restore_count'=>$restoreCount,'doc_status'=>self::safeDocumentStatus($doc['doc_status'])));
            if ($rowConflicts) { $conflicts=array_merge($conflicts,$rowConflicts); continue; }
            $restore=$restoreCount===1?$restoreRows[0]:null;
            if ($restore) $this->emit($writer,'leave_restore_events',array('legacy_id'=>$restore['id'],'legacy_employee_id'=>$d['employee_id'],'legacy_document_id'=>$d['document_id'],'event_at'=>$restore['created_at'],'event_type'=>'LEAVE_RESTORE'));
            $record=array('legacy_id'=>$doc['id'],'legacy_employee_id'=>$d['employee_id'],'legacy_document_id'=>$doc['id'],'leave_type'=>$types[$type],'leave_bucket'=>$bucket,'start_date'=>$start,'end_date'=>$end,'amount'=>$amount,'doc_status'=>$doc['doc_status'],'restored_at'=>$restore?$restore['created_at']:null);
            $this->emit($writer,'leave_approval_records',$record);
            $d['amount']=$amount; unset($d['deduct_amount']); $d['restored_at']=$record['restored_at']; $d['doc_status']=$doc['doc_status'];
            $d['balance_before']=Cpms2MigrationDecimal::normalize($d['balance_before']);
            $d['balance_after']=Cpms2MigrationDecimal::normalize($d['balance_after']);
            $this->emit($writer,'leave_approval_deductions',Cpms2MigrationExportService::legacy($d));
        }
        if ($conflicts) { $this->summary['leave_document_conflicts']=$conflicts; if ($writer!==null) throw new RuntimeException('LEGACY_LEAVE_DOCUMENT_CONFLICT'); }
    }
    private static function leaveDocumentConflict($deductionId,$documentId,$employeeId,$reasonCode,$safeDetail=array())
    {
        return array('deduction_id'=>$deductionId===null?null:(int)$deductionId,'document_id'=>$documentId===null?null:(int)$documentId,'employee_id'=>$employeeId===null?null:(int)$employeeId,'reason_code'=>$reasonCode,'safe_detail'=>$safeDetail);
    }
    private static function decodeContent($value)
    {
        if (is_array($value)) return $value;
        $decoded=json_decode((string)$value,true); return is_array($decoded)?$decoded:null;
    }
    private static function safeLeaveType($value)
    {
        return in_array($value,array('월차','연차','반차 오전','반차 오후'),true)?$value:'UNKNOWN';
    }
    private static function safeDocumentStatus($value)
    {
        $value=strtoupper(trim((string)$value)); return preg_match('/^[A-Z0-9_]{1,30}$/',$value)?$value:'UNKNOWN';
    }
    private static function expectedLeaveBucket($hireDate,$leaveStartDate)
    {
        $ts=strtotime($hireDate); $year=(int)date('Y',$ts)+1; $month=(int)date('n',$ts); $day=(int)date('j',$ts);
        $lastDay=(int)date('t',mktime(0,0,0,$month,1,$year)); if ($day>$lastDay) $day=$lastDay;
        $oneYearDate=date('Y-m-d',mktime(0,0,0,$month,$day,$year));
        return strcmp($leaveStartDate,$oneYearDate)<0?'monthly':'annual';
    }
    public static function parseContent($value)
    {
        // Exact JSON decoding rule from approval/_common.php; only whitelisted leave fields are emitted.
        $decoded=self::decodeContent($value); if ($decoded===null) throw new RuntimeException('LEGACY_LEAVE_DOCUMENT_CONFLICT'); return $decoded;
    }
}
