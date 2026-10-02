<?php
// app/services/Cpms2ReferencedMasterClosure.php
// PHP 5.6: filtered source rows plus only masters referenced by exported children.
class Cpms2ReferencedMasterClosure
{
    private $source;
    private $normal=array(); private $references=array(); private $recovered=array(); private $missing=array();
    private $assignments=array(); private $vendors=array(); private $report=array(); private $failures=array();
    private static $entities=array('cpms_projects'=>'projects','cpms_material_items'=>'material_items','cpms_equipment_items'=>'equipment_items','workers'=>'workers','direct_team_members'=>'direct_team','cpms_vendors'=>'vendors','cpms_material_usage'=>'material_usages');
    public function __construct($source,$root=null,$storage=null)
    {
        $this->source=$source;
        foreach (self::$entities as $table=>$entity) {
            $this->normal[$table]=array(); $this->references[$table]=array(); $this->recovered[$table]=array(); $this->missing[$table]=array();
            if (!in_array('id',$source->columns($table))) continue;
            foreach ($source->rows($table,array('id')) as $row) $this->normal[$table][(string)$row['id']]=true;
        }
        $children=array('cpms_material_items','cpms_equipment_items','cpms_material_usage','cpms_equipment_usage','cpms_outsourcing_costs','cpms_progress_billings','cpms_material_statement_files','cpms_project_labor_workers','cpms_project_members','cpms_construction_roles');
        foreach ($children as $table) {
            $columns=$source->columns($table); if (!$columns) continue;
            $fields=array_values(array_intersect(explode(' ','id project_id material_id equipment_id material_usage_id vendor_id worker_id direct_member_id name worker_name_snapshot'),$columns));
            if (!$fields) continue;
            if (in_array('id',$fields)) $rows=$source->rows($table,$fields);
            else {
                $filter=array(); if (in_array('is_deleted',$columns)) $filter[]='COALESCE(is_deleted,0)=0'; if (in_array('deleted_at',$columns)) $filter[]='deleted_at IS NULL';
                $rows=$source->query('SELECT `'.implode('`,`',$fields).'` FROM `'.$table.'`'.($filter?' WHERE '.implode(' AND ',$filter):''))->fetchAll(PDO::FETCH_ASSOC);
            }
            foreach ($rows as $row) {
                $this->referencesFor($table,$row);
                if ($table==='cpms_project_labor_workers') $this->assignments[]=$row;
            }
        }
        // Safety JSON rows are exported children too, even without a database cost row.
        if ($root!==null && $storage!==null) {
            $safety=(new Cpms2SafetyCostExportService($root,$storage))->collect($source);
            foreach ($safety['rows'] as $row) {
                $this->reference('cpms_projects',$row['legacy_project_id']);
                if (!empty($row['legacy_vendor_id'])) $this->reference('cpms_vendors',$row['legacy_vendor_id']);
            }
        }
        $archiveColumns=$source->columns('cpms_approval_documents');
        if (in_array('project_id',$archiveColumns) && in_array('doc_status',$archiveColumns)) {
            $st=$source->query("SELECT DISTINCT project_id FROM cpms_approval_documents WHERE UPPER(doc_status) IN ('APPROVED','COMPLETED') AND project_id IS NOT NULL");
            while ($row=$st->fetch(PDO::FETCH_ASSOC)) $this->reference('cpms_projects',$row['project_id']);
        }
        do {
            $changed=false;
            foreach (self::$entities as $table=>$entity) {
                $pending=array_diff_key($this->references[$table],$this->normal[$table],$this->recovered[$table],$this->missing[$table]);
                if (!$pending) continue;
                $found=array();
                if (in_array('id',$source->columns($table))) {
                    $fields=$this->fields($table);
                    foreach ($source->rowsByIds($table,$fields,array_keys($pending)) as $row) {
                        $id=(string)$row['id']; $found[$id]=true; $this->recovered[$table][$id]=true;
                        $this->referencesFor($table,$row); $changed=true;
                    }
                }
                foreach (array_diff_key($pending,$found) as $id=>$unused) $this->missing[$table][$id]=true;
            }
        } while ($changed);
        foreach (self::$entities as $table=>$entity) {
            $this->report[$entity]=array('normal'=>count($this->normal[$table]),'reference_recovered'=>count($this->recovered[$table]),'physically_missing'=>count($this->missing[$table]));
            if (in_array($table,array('workers','direct_team_members'),true)) continue;
            foreach ($this->missing[$table] as $id=>$unused) $this->failures[]=array('entity'=>$entity,'legacy_id'=>(string)$id,'code'=>'legacy_referenced_master_physically_missing');
        }
        $snapshot=0;
        foreach ($this->assignments as $row) if ($this->snapshotOnly($row)) {
            $name=!empty($row['worker_name_snapshot'])?trim($row['worker_name_snapshot']):(isset($row['name'])?trim($row['name']):'');
            if ($name==='') $this->failures[]=array('entity'=>'labor_workers','legacy_id'=>(string)$row['id'],'code'=>'legacy_labor_snapshot_insufficient');
            else $snapshot++;
        }
        $this->report['workers']['snapshot_only_labor_workers']=$snapshot;
        foreach ($this->rows('cpms_vendors',array('id','name','vendor_name','business_no','biz_no')) as $row) $this->vendors[(string)$row['id']]=$row;
    }
    private function fields($table)
    {
        $fields=isset(Cpms2MigrationExportService::$fields[$table])?explode(' ',Cpms2MigrationExportService::$fields[$table]):array('id');
        return array_merge($fields,array('project_id','is_deleted','deleted_at'));
    }
    private function reference($table,$id) { if ($id!==null && (string)$id!=='' && (int)$id>0) $this->references[$table][(string)$id]=true; }
    private function referencesFor($table,$row)
    {
        if (isset($row['project_id'])) $this->reference('cpms_projects',$row['project_id']);
        if ($table==='cpms_project_labor_workers') {
            $this->reference('workers',isset($row['worker_id'])?$row['worker_id']:null);
            $this->reference('direct_team_members',isset($row['direct_member_id'])?$row['direct_member_id']:null);
        }
        if (isset($row['material_id'])) $this->reference('cpms_material_items',$row['material_id']);
        if (isset($row['equipment_id'])) $this->reference('cpms_equipment_items',$row['equipment_id']);
        if ($table==='cpms_material_statement_files' && isset($row['material_usage_id'])) $this->reference('cpms_material_usage',$row['material_usage_id']);
        if (isset($row['vendor_id'])) $this->reference('cpms_vendors',$row['vendor_id']);
    }
    public function snapshotOnly($row)
    {
        if (!empty($row['direct_member_id'])) return isset($this->missing['direct_team_members'][(string)$row['direct_member_id']]);
        if (!empty($row['worker_id'])) return isset($this->missing['workers'][(string)$row['worker_id']]);
        return true;
    }
    public function rows($table,$fields,$where='',$params=array())
    {
        foreach ($this->source->rows($table,$fields,$where,$params) as $row) yield $row;
        if (empty($this->recovered[$table])) return;
        foreach ($this->source->rowsByIds($table,array_merge($fields,array('is_deleted','deleted_at')),array_keys($this->recovered[$table]),$where,$params) as $row) {
            $row['legacy_reference_only']=1; $row['recovered_by']='referenced_master_closure';
            if (array_key_exists('is_deleted',$row)) { $row['source_is_deleted']=$row['is_deleted']; unset($row['is_deleted']); }
            if (array_key_exists('deleted_at',$row)) { $row['source_deleted_at']=$row['deleted_at']; unset($row['deleted_at']); }
            yield $row;
        }
    }
    public static function businessNumber($row)
    {
        foreach (array('biz_no','business_no') as $field) if (!empty($row[$field])) {
            $raw=trim((string)$row[$field]); if (!preg_match('/^[0-9\s-]+$/D',$raw)) return '';
            $value=preg_replace('/\D/','',$raw); return strlen($value)===10?$value:'';
        }
        return '';
    }
    public function vendorSnapshot($row)
    {
        $name=!empty($row['agency_name_snapshot'])?$row['agency_name_snapshot']:(isset($row['company_name'])?$row['company_name']:'');
        $result=array('vendor_name'=>$name,'legacy_vendor_resolution'=>'snapshot_only');
        $number=self::businessNumber($row);
        $explicit=!empty($row['vendor_id']) && isset($this->vendors[(string)$row['vendor_id']]);
        if ($explicit) $matches=array($this->vendors[(string)$row['vendor_id']]);
        else {
            $matches=array();
            foreach ($this->vendors as $vendor) if ($number!==''?self::businessNumber($vendor)===$number:($name!=='' && trim(isset($vendor['name'])?$vendor['name']:$vendor['vendor_name'])===trim($name))) $matches[]=$vendor;
        }
        if (count($matches)===1 && ($explicit || $number!=='')) {
            $result['legacy_vendor_id']=$matches[0]['id']; $result['biz_no']=self::businessNumber($matches[0]);
            $result['legacy_vendor_resolution']=$explicit?'legacy_vendor_id':'unique_business_identity';
        } else { $result['legacy_vendor_unresolved']=true; $result['legacy_vendor_ambiguous']=count($matches)>1; }
        return $result;
    }
    public function summary() { return $this->report; }
    public function failures() { return $this->failures; }
    public function columns($table) { return $this->source->columns($table); }
    public function inspect($table,$mandatory=false,$required=array('id')) { return $this->source->inspect($table,$mandatory,$required); }
    public function query($sql,$params=array()) { return $this->source->query($sql,$params); }
    public function prepare($sql) { return $this->source->prepare($sql); }
    public function report() { return $this->source->report(); }
    public function databaseName() { return $this->source->databaseName(); }
}

class Cpms2LaborReferencePreflightWriter
{
    public $warnings=array(); public $excludedLaborForce=array();
    public $vendors=array('legacy_vendor_id'=>0,'unique_business_identity'=>0,'snapshot_only'=>0,'ambiguous'=>0);
    public function amount($project,$kind,$amount) {}
    public function record($entity,$row)
    {
        if ($entity!=='labor_months') return;
        $resolution=isset($row['legacy_vendor_resolution'])?$row['legacy_vendor_resolution']:'snapshot_only';
        $this->vendors[$resolution]++;
        if (!empty($row['legacy_vendor_ambiguous'])) $this->vendors['ambiguous']++;
    }
}
