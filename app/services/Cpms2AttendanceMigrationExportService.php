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
            'employees'=>'id leave_monthly_balance leave_annual_balance leave_half_balance',
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
        foreach ($this->rows('employees','id leave_monthly_balance leave_annual_balance leave_half_balance') as $r) {
            $this->employees[(string)$r['id']]=true;
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
        if ((int)$this->source->query("SELECT COUNT(*) FROM cpms_approval_logs l WHERE l.action_type='LEAVE_RESTORE' AND NOT EXISTS (SELECT 1 FROM cpms_approval_leave_deductions d WHERE d.document_id=l.document_id)")->fetchColumn()) throw new RuntimeException('LEGACY_LEAVE_DOCUMENT_CONFLICT');
        foreach ($this->rows('cpms_approval_leave_deductions','id employee_id document_id leave_type leave_bucket deduct_amount balance_before balance_after deducted_at created_at') as $d) {
            $this->employee($d['employee_id']);
            $doc=$this->source->query('SELECT id,doc_status,content,created_by_id FROM cpms_approval_documents WHERE id=?',array($d['document_id']))->fetch(PDO::FETCH_ASSOC);
            if (!$doc || (string)$doc['created_by_id']!==(string)$d['employee_id']) throw new RuntimeException('LEGACY_LEAVE_DOCUMENT_CONFLICT');
            $content=self::parseContent($doc['content']);
            $type=isset($content['request_type'])?trim($content['request_type']):'';
            $types=array('월차'=>'monthly','연차'=>'annual','반차 오전'=>'morning_half','반차 오후'=>'afternoon_half');
            $bucket=strtolower($d['leave_bucket']);
            if (!isset($types[$type]) || $type!==$d['leave_type'] || !in_array($bucket,array('monthly','annual')) || (in_array($type,array('월차','연차')) && $types[$type]!==$bucket)) throw new RuntimeException('LEGACY_LEAVE_DOCUMENT_CONFLICT');
            $start=isset($content['leave_start_date'])?substr(trim($content['leave_start_date']),0,10):'';
            $end=isset($content['leave_end_date'])?substr(trim($content['leave_end_date']),0,10):'';
            if (!Cpms2ManagementPreflightSupport::safeDate($start) || !Cpms2ManagementPreflightSupport::safeDate($end) || $end<$start) throw new RuntimeException('LEGACY_LEAVE_DOCUMENT_CONFLICT');
            if (!in_array($doc['doc_status'],array('APPROVED','COMPLETED','CANCELLED','REJECTED'))) throw new RuntimeException('LEGACY_LEAVE_DOCUMENT_CONFLICT');
            $amount=Cpms2MigrationDecimal::normalize($d['deduct_amount']);
            if ($amount==='0.00' || substr($amount,0,1)==='-' || (strpos($types[$type],'half')!==false && $amount!=='0.50')) throw new RuntimeException('LEGACY_LEAVE_AMOUNT_INVALID');
            $restores=$this->source->query("SELECT id,created_at FROM cpms_approval_logs WHERE document_id=? AND action_type='LEAVE_RESTORE' ORDER BY id",array($d['document_id'])); $restore=null;
            while ($log=$restores->fetch(PDO::FETCH_ASSOC)) {
                if ($restore || $doc['doc_status']!=='CANCELLED') throw new RuntimeException('LEGACY_LEAVE_DOCUMENT_CONFLICT'); $restore=$log;
                $this->emit($writer,'leave_restore_events',array('legacy_id'=>$log['id'],'legacy_employee_id'=>$d['employee_id'],'legacy_document_id'=>$d['document_id'],'event_at'=>$log['created_at'],'event_type'=>'LEAVE_RESTORE'));
            }
            $record=array('legacy_id'=>$doc['id'],'legacy_employee_id'=>$d['employee_id'],'legacy_document_id'=>$doc['id'],'leave_type'=>$types[$type],'leave_bucket'=>$bucket,'start_date'=>$start,'end_date'=>$end,'amount'=>$amount,'doc_status'=>$doc['doc_status'],'restored_at'=>$restore?$restore['created_at']:null);
            $this->emit($writer,'leave_approval_records',$record);
            $d['amount']=$amount; unset($d['deduct_amount']); $d['restored_at']=$record['restored_at']; $d['doc_status']=$doc['doc_status'];
            $d['balance_before']=Cpms2MigrationDecimal::normalize($d['balance_before']);
            $d['balance_after']=Cpms2MigrationDecimal::normalize($d['balance_after']);
            $this->emit($writer,'leave_approval_deductions',Cpms2MigrationExportService::legacy($d));
        }
    }
    public static function parseContent($value)
    {
        // Exact JSON decoding rule from approval/_common.php; only whitelisted leave fields are emitted.
        if (is_array($value)) return $value;
        $decoded=json_decode((string)$value,true); if (!is_array($decoded)) throw new RuntimeException('LEGACY_LEAVE_DOCUMENT_CONFLICT'); return $decoded;
    }
}
