<?php
// app/services/Cpms2MigrationExclusionPolicy.php
// Explicit human approval only; source verification and filtering are migration-only.
require_once __DIR__.'/Cpms2MigrationDecimal.php';
require_once __DIR__.'/Cpms2DeletedProjectTraceService.php';
require_once __DIR__.'/Cpms2HistoricalProjectRecoveryService.php';
class Cpms2MigrationExclusionPolicy
{
    private $source; private $approved=array(); private $diagnostics=array(); private $failures=array(); private $countCache=array();
    public static $entities=array('cpms_projects'=>'projects','cpms_project_members'=>'project_members','cpms_construction_roles'=>'project_roles','cpms_project_labor_workers'=>'labor_workers','cpms_project_labor_worker_months'=>'labor_months','cpms_material_items'=>'material_items','cpms_material_usage'=>'material_usages','cpms_equipment_items'=>'equipment_items','cpms_equipment_usage'=>'equipment_usages','cpms_outsourcing_costs'=>'subcontract_costs','cpms_progress_billings'=>'progress_billings','cpms_material_statement_files'=>'material_statement_files');
    private static function approvals()
    {
        // The sole approved production project ID is managed here, never inferred from data.
        return array(8=>array('reason_code'=>'user_approved_deleted_project_orphan_exclusion','approved_scope'=>'migration_only','material_amount'=>'7269825.00','equipment_amount'=>'140000.00','material_item_count'=>10,'material_usage_count'=>9,'equipment_usage_count'=>2));
    }
    public function __construct($source,$root=null,$storage=null)
    {
        $this->source=$source;
        $safety=null;
        foreach (self::approvals() as $id=>$approval) {
            $traceCounts=array();
            foreach (array('cpms_material_items','cpms_material_usage','cpms_equipment_items','cpms_equipment_usage','cpms_material_statement_files','cpms_outsourcing_costs','cpms_progress_billings') as $table) $traceCounts[$table]=$this->count($table,$id,false);
            $master=in_array('id',$source->columns('cpms_projects'))?$source->query('SELECT COUNT(*) FROM cpms_projects WHERE id=?',array($id))->fetchColumn():0;
            $recovery=(new Cpms2HistoricalProjectRecoveryService($source))->recover($id);
            $r=array('legacy_id'=>(string)$id,'reason_code'=>$approval['reason_code'],'approved_scope'=>$approval['approved_scope'],'project_master_present'=>(bool)$master,'project_identity_available'=>$recovery['row']!==null || $recovery['diagnostic']['code']!=='legacy_project_snapshot_unavailable','condition_verified'=>false,'material_item_count'=>$traceCounts['cpms_material_items'],'equipment_item_count'=>$traceCounts['cpms_equipment_items'],'record_counts'=>array());
            $changed=$r['project_master_present'] || $r['project_identity_available'];
            foreach (self::$entities as $table=>$entity) $r['record_counts'][$entity]=$this->count($table,$id,true);
            foreach (array('labor_entries','safety_costs','safety_evidence_files','legacy_completed_approvals') as $entity) $r['record_counts'][$entity]=0;
            foreach (array('material','equipment') as $kind) {
                $r[$kind.'_usage_count']=$this->count('cpms_'.$kind.'_usage',$id,false);
                $r[$kind.'_amount']=$this->sum('cpms_'.$kind.'_usage',$id,'amount');
                $r[$kind.'_exported_item_count']=$this->masterCount($kind,$id);
                $r['record_counts'][$kind.'_items']=$r[$kind.'_exported_item_count'];
                $r[$kind.'_reference_only_item_count']=$r[$kind.'_exported_item_count']-$this->count('cpms_'.$kind.'_items',$id,true);
                if ($r[$kind.'_usage_count']!==$approval[$kind.'_usage_count'] || $r[$kind.'_amount']!==$approval[$kind.'_amount'] || $r['record_counts'][$kind.'_usages']!==$r[$kind.'_usage_count']) $changed=true;
            }
            $r['material_negative_usage_count']=in_array('amount',$source->columns('cpms_material_usage'))?$this->count('cpms_material_usage',$id,false,'amount<0'):0;
            $r['material_negative_amount']=$this->sum('cpms_material_usage',$id,'amount','amount<0');
            $r['material_amount_changed']=$r['material_amount']!==$approval['material_amount']; $r['equipment_amount_changed']=$r['equipment_amount']!==$approval['equipment_amount'];
            if ($r['material_item_count']!==$approval['material_item_count']) $changed=true;
            // Even zero-value rows in a newly discovered protected domain require new approval.
            $laborCount=0;
            foreach (array('cpms_project_labor_workers','cpms_project_labor_worker_months','cpms_project_labor_worker_wages','cpms_labor_gongsu_overrides','cpms_labor_force_adjustments') as $table) $laborCount+=$this->count($table,$id,false);
            $r['labor_row_count']=$laborCount; $r['labor_amount']='0.00';
            $r['subcontract_row_count']=$this->count('cpms_outsourcing_costs',$id,false); $r['subcontract_amount']=$this->sum('cpms_outsourcing_costs',$id,'amount');
            $r['billing_row_count']=$this->count('cpms_progress_billings',$id,false); $r['billing_amount']=$this->billingAmount($id);
            $r['statement_file_count']=$this->count('cpms_material_statement_files',$id,false);
            $r['approval_row_count']=$this->count('cpms_approval_documents',$id,false,in_array('doc_status',$source->columns('cpms_approval_documents'))?"UPPER(doc_status) IN ('APPROVED','COMPLETED')":'');
            $r['safety_row_count']=0; $r['safety_amount']='0.00';
            if ($root!==null && $storage!==null) {
                if ($safety===null) $safety=(new Cpms2SafetyCostExportService($root,$storage))->collect($source);
                foreach ($safety['rows'] as $row) if ((string)$row['legacy_project_id']===(string)$id) { $r['safety_row_count']++; $r['safety_amount']=Cpms2MigrationDecimal::add($r['safety_amount'],$row['amount']); }
            } elseif ($this->safetyUsageCount($id)>0) $r['safety_row_count']=$this->safetyUsageCount($id);
            // A fixture/source with no reference to the approved ID needs no exclusion.
            if (!$master && !array_sum($traceCounts) && !$laborCount && !$r['approval_row_count'] && !$r['safety_row_count'] && !$this->count('cpms_project_members',$id,false) && !$this->count('cpms_construction_roles',$id,false)) continue;
            if ($laborCount || $r['subcontract_row_count'] || $r['billing_row_count'] || $r['statement_file_count'] || $r['approval_row_count'] || $r['safety_row_count']) $changed=true;
            $r['total_cost_amount']=Cpms2MigrationDecimal::add($r['material_amount'],$r['equipment_amount']);
            $r['condition_verified']=!$changed;
            if ($changed) { $r['code']='legacy_exclusion_scope_changed'; $this->failures[]=array('entity'=>'projects','legacy_id'=>(string)$id,'code'=>$r['code']); }
            else $this->approved[(string)$id]=$r;
            $this->diagnostics[(string)$id]=$r;
        }
    }
    private function selection($table,$id,$normal=false,$extra='')
    {
        $columns=$this->source->columns($table);
        if ($table==='cpms_projects' && in_array('id',$columns)) $scope=array('sql'=>$table.'.id=?','params'=>1);
        else $scope=(new Cpms2DeletedProjectTraceService($this->source))->scope($table,$table);
        if (!$scope) return null;
        $where='('.$scope['sql'].')';
        if ($normal && in_array('is_deleted',$columns)) $where.=' AND COALESCE(is_deleted,0)=0';
        if ($normal && in_array('deleted_at',$columns)) $where.=' AND deleted_at IS NULL';
        if ($extra!=='') $where.=' AND ('.$extra.')';
        return array('where'=>$where,'params'=>array_fill(0,$scope['params'],$id));
    }
    private function count($table,$id,$normal,$extra='')
    {
        $key=$table.':'.$id.':'.($normal?'normal':'all').':'.$extra; if (isset($this->countCache[$key])) return $this->countCache[$key];
        $selection=$this->selection($table,$id,$normal,$extra); if (!$selection) return 0;
        return $this->countCache[$key]=(int)$this->source->query('SELECT COUNT(*) FROM `'.$table.'` WHERE '.$selection['where'],$selection['params'])->fetchColumn();
    }
    private function sum($table,$id,$field,$extra='')
    {
        if (!in_array($field,$this->source->columns($table))) return '0.00';
        $selection=$this->selection($table,$id,false,$extra); if (!$selection) return '0.00';
        $value=$this->source->query('SELECT COALESCE(SUM(CAST(`'.$field.'` AS DECIMAL(30,2))),0) FROM `'.$table.'` WHERE '.$selection['where'],$selection['params'])->fetchColumn();
        return Cpms2MigrationDecimal::normalize($value);
    }
    private function masterCount($kind,$id)
    {
        $table='cpms_'.$kind.'_items'; $usage='cpms_'.$kind.'_usage'; $columns=$this->source->columns($table);
        if (!in_array('id',$columns) || !in_array('project_id',$columns)) return 0;
        $normal=array(); if (in_array('is_deleted',$columns)) $normal[]='COALESCE(m.is_deleted,0)=0'; if (in_array('deleted_at',$columns)) $normal[]='m.deleted_at IS NULL';
        $where=$normal?implode(' AND ',$normal):'1=1'; $uc=$this->source->columns($usage);
        if (in_array($kind.'_id',$uc) && in_array('project_id',$uc)) {
            $active=''; if (in_array('is_deleted',$uc)) $active.=' AND COALESCE(u.is_deleted,0)=0'; if (in_array('deleted_at',$uc)) $active.=' AND u.deleted_at IS NULL';
            $where='('.$where.') OR EXISTS (SELECT 1 FROM `'.$usage.'` u WHERE u.`'.$kind.'_id`=m.id AND u.project_id=m.project_id'.$active.')';
        }
        return (int)$this->source->query('SELECT COUNT(*) FROM `'.$table.'` m WHERE m.project_id=? AND ('.$where.')',array($id))->fetchColumn();
    }
    private function safetyUsageCount($id)
    {
        $mc=$this->source->columns('cpms_material_items'); $uc=$this->source->columns('cpms_material_usage');
        if (!in_array('category',$mc) || !in_array('id',$mc) || !in_array('material_id',$uc) || !in_array('project_id',$uc)) return 0;
        return (int)$this->source->query('SELECT COUNT(*) FROM cpms_material_usage u JOIN cpms_material_items m ON m.id=u.material_id WHERE u.project_id=? AND m.category=?',array($id,'안전관리비'))->fetchColumn();
    }
    private function billingAmount($id)
    {
        $table='cpms_progress_billings'; $selection=$this->selection($table,$id); $columns=$this->source->columns($table); if (!$selection) return '0.00';
        $recognized=in_array('recognized_amount',$columns)?'COALESCE(recognized_amount,0)':'0'; $requested=in_array('requested_amount',$columns)?'COALESCE(requested_amount,0)':'0';
        return Cpms2MigrationDecimal::normalize($this->source->query('SELECT COALESCE(SUM(CAST(CASE WHEN '.$recognized.'<>0 THEN '.$recognized.' WHEN '.$requested.'>0 THEN '.$requested.' ELSE 0 END AS DECIMAL(30,2))),0) FROM '.$table.' WHERE '.$selection['where'],$selection['params'])->fetchColumn());
    }
    public function filter($table,$where,$params)
    {
        foreach ($this->approved as $id=>$unused) {
            $selection=$this->selection($table,$id); if (!$selection) continue;
            // NULL project IDs without a recoverable parent are retained for ordinary validation.
            $where=($where!==''?'('.$where.') AND ':'').'COALESCE(NOT ('.$selection['where'].'),1)=1'; $params=array_merge($params,$selection['params']);
        }
        return array($where,$params);
    }
    public function excludes($id) { return isset($this->approved[(string)$id]); }
    public function excludesRecord($entity,$row) { return $this->excludes(isset($row['legacy_project_id'])?$row['legacy_project_id']:(isset($row['project_id'])?$row['project_id']:($entity==='projects' && isset($row['legacy_id'])?$row['legacy_id']:null))); }
    public function failures() { return $this->failures; }
    public function diagnostics() { return array('approved_count'=>count($this->approved),'blocked_count'=>count($this->failures),'projects'=>$this->diagnostics); }
    public function summary()
    {
        $counts=array(); $amounts=array('material'=>'0.00','equipment'=>'0.00');
        foreach ($this->approved as $row) {
            foreach ($row['record_counts'] as $entity=>$count) $counts[$entity]=isset($counts[$entity])?$counts[$entity]+$count:$count;
            foreach ($amounts as $kind=>$total) $amounts[$kind]=Cpms2MigrationDecimal::add($total,$row[$kind.'_amount']);
        }
        return array('count'=>count($this->approved),'projects'=>$this->approved,'record_counts'=>$counts,'company_amounts'=>$amounts);
    }
}
