<?php
// app/services/Cpms2MigrationExportService.php
require_once __DIR__.'/Cpms2ReadOnlySource.php';
require_once __DIR__.'/Cpms2ExportPackageWriter.php';
require_once __DIR__.'/Cpms2LaborExportService.php';
require_once __DIR__.'/Cpms2SensitiveExportService.php';
require_once __DIR__.'/Cpms2ExportDiagnostic.php';

class Cpms2MigrationExportService
{
    public static $fields = array(
        'employees'=>'id employee_no name email phone department position role is_active birth_date hire_date resign_date work_location is_team_leader team_leader_id',
        'cpms_vendors'=>'id business_no biz_no name vendor_name description representative representative_name phone bank_name account_number bank_account account_holder is_active',
        'workers'=>'id name phone birth_date job_type agency_name daily_wage bank_name bank_account_enc bank_account_hash account_number bank_account account_holder is_active',
        'direct_team_members'=>'id name phone hire_date resign_date bank_name bank_account account_holder monthly_salary daily_wage is_active',
        'cpms_projects'=>'id name client contractor location start_date end_date contract_amount status',
        'cpms_project_members'=>'id project_id employee_id role',
        'cpms_construction_roles'=>'id project_id site_employee_id safety_employee_id quality_employee_id',
        'cpms_material_items'=>'id project_id vendor_id vendor_name biz_no category item_name spec base_rate remark',
        'cpms_material_usage'=>'id project_id material_id use_date amount memo advance_yn',
        'cpms_equipment_items'=>'id project_id vendor_id vendor_name biz_no category item_name equipment_name spec base_rate remark',
        'cpms_equipment_usage'=>'id project_id equipment_id use_date work_unit base_rate_snapshot amount is_manual_unit memo advance_yn',
        'cpms_outsourcing_costs'=>'id project_id expense_date vendor_id vendor_name biz_no company_name amount memo content advance_payment_yn',
        'cpms_progress_billings'=>'id project_id round_label progress_date requested_amount recognized_amount remark created_at',
        'cpms_material_statement_files'=>'id project_id material_id material_usage_id use_date ym original_name stored_name stored_path mime_type file_size uploaded_by uploaded_by_name uploaded_at'
    );
    private $db;
    private $writer;
    private $root;
    private $fileRoot;
    private $sensitive;
    public function __construct($db,$writer,$root,$fileRoot,$sensitive=null) { $this->db=$db; $this->writer=$writer; $this->root=$root; $this->fileRoot=$fileRoot; $this->sensitive=$sensitive?$sensitive:new Cpms2SensitiveExportService($root); }
    public static function tablePhase($table)
    {
        $phases=array('employees'=>'employees','cpms_vendors'=>'vendors','workers'=>'workers','direct_team_members'=>'direct_team','cpms_material_items'=>'material','cpms_material_usage'=>'material','cpms_equipment_items'=>'equipment','cpms_equipment_usage'=>'equipment','cpms_outsourcing_costs'=>'subcontract','cpms_progress_billings'=>'billing','cpms_material_statement_files'=>'statement_files');
        return isset($phases[$table])?$phases[$table]:'projects';
    }
    public static function department($name)
    {
        $name=trim((string)$name);
        $map=array('관리부'=>'관리','관리팀'=>'관리','공무부'=>'공무','공무팀'=>'공무','공사부'=>'공사','공사팀'=>'공사','안전부'=>'안전','안전팀'=>'안전','품질부'=>'품질','품질팀'=>'품질','보건부'=>'보건','보건팀'=>'보건','개발부'=>'개발','개발팀'=>'개발');
        return isset($map[$name])?$map[$name]:$name;
    }
    public static function legacy($row)
    {
        $result=array();
        foreach ($row as $field=>$value) {
            if ($field==='id') $field='legacy_id';
            elseif (preg_match('/_id$/',$field)) $field='legacy_'.$field;
            $result[$field]=$value;
        }
        return $result;
    }
    public static function statementPath($storedPath,$projectRoot,$storageRoot)
    {
        $allowed=realpath($storageRoot); if (!$allowed || !is_string($storedPath) || strpos($storedPath,chr(0))!==false) return '';
        $boundary=str_replace('\\','/',rtrim($allowed,'/\\')).'/';
        if (DIRECTORY_SEPARATOR==='\\') $boundary=strtolower($boundary);
        foreach (array($storedPath,$projectRoot.'/'.$storedPath,$storageRoot.'/'.$storedPath) as $candidate) {
            $path=realpath($candidate); if (!$path || !is_file($path)) continue;
            $normalized=str_replace('\\','/',$path); if (DIRECTORY_SEPARATOR==='\\') $normalized=strtolower($normalized);
            if (strpos($normalized,$boundary)===0) return $path;
        }
        return '';
    }
    private function rows($table) { return $this->db->rows($table,explode(' ',self::$fields[$table])); }
    public function run($attendance)
    {
        $this->writer->phase='employees';
        foreach (self::$fields as $table=>$fields) {
            $this->writer->phase=self::tablePhase($table);
            $mandatory=in_array($table,array('employees','cpms_vendors','workers','direct_team_members','cpms_projects'));
            $columns=$this->db->inspect($table,$mandatory,array('id','name'));
            if (!count($columns)) $this->writer->warnings[]='Optional source table missing: '.$table;
        }
        foreach (array('departments','positions','employees','vendors','workers','direct_team','projects','project_members','project_roles','labor_workers','labor_months','labor_entries','material_items','material_usages','equipment_items','equipment_usages','subcontract_costs','safety_costs','progress_billings','material_statement_files') as $entity) $this->writer->emptyEntity($entity);
        $this->writer->phase='employees'; $departments=array(); $positions=array();
        $payroll=$this->sensitive->employeeAccounts($this->db);
        if ($payroll['failures']) throw new RuntimeException($payroll['failures'][0]['code']);
        foreach ($this->rows('employees') as $row) {
            if (isset($payroll['accounts'][(string)$row['id']])) $row=array_merge($row,$payroll['accounts'][(string)$row['id']]);
            $row['department']=self::department(isset($row['department'])?$row['department']:'');
            if ($row['department']!=='') $departments[$row['department']]=true;
            if (isset($row['position']) && trim($row['position'])!=='') $positions[trim($row['position'])]=true;
            $this->writer->record('employees',self::legacy($row));
        }
        foreach ($departments as $name=>$unused) $this->writer->record('departments',array('legacy_id'=>$name,'name'=>$name));
        $ordered=array('주임','대리','과장','차장','부장','이사','전무','상무','부사장','고문','대표');
        $index=0;
        foreach (array_unique(array_merge($ordered,array_keys($positions))) as $name) if (isset($positions[$name])) $this->writer->record('positions',array('legacy_id'=>$name,'name'=>$name,'sort_order'=>$index++));
        foreach (array('cpms_vendors'=>'vendors','workers'=>'workers','direct_team_members'=>'direct_team','cpms_projects'=>'projects','cpms_project_members'=>'project_members','cpms_construction_roles'=>'project_roles') as $table=>$entity) {
            $this->writer->phase=in_array($entity,array('project_members','project_roles'))?'projects':$entity;
            if ($table==='cpms_project_members' && !in_array('id',$this->db->columns($table)) && in_array('employee_id',$this->db->columns($table))) {
                $st=$this->db->query('SELECT project_id,employee_id,role FROM cpms_project_members ORDER BY project_id,employee_id');
                while ($row=$st->fetch(PDO::FETCH_ASSOC)) { $row['id']=$row['project_id'].':'.$row['employee_id']; $this->writer->record($entity,self::legacy($row)); }
                continue;
            }
            foreach ($this->rows($table) as $row) {
                if ($entity==='workers') $row=array_merge($row,$this->sensitive->workerAccount($row,$this->db));
                elseif (in_array($entity,array('vendors','direct_team'))) $row=array_merge($row,Cpms2SensitiveExportService::plainAccount($row,strtoupper($entity).'_ACCOUNT_MISSING'));
                unset($row['bank_account_enc'],$row['bank_account_hash'],$row['bank_account']);
                $this->writer->record($entity,self::legacy($row));
            }
        }
        $this->writer->phase='labor'; (new Cpms2LaborExportService($this->db,$attendance,$this->writer))->run();
        foreach (array('material','equipment') as $kind) {
            $this->writer->phase=$kind;
            $table='cpms_'.$kind.'_items';
            foreach ($this->rows($table) as $row) $this->writer->record($kind.'_items',self::legacy($row));
            $table='cpms_'.$kind.'_usage';
            foreach ($this->rows($table) as $row) {
                $masterTable='cpms_'.$kind.'_items';
                $fields=array_intersect(explode(' ',self::$fields[$masterTable]),$this->db->columns($masterTable));
                $master=$this->db->query('SELECT `'.implode('`,`',$fields).'` FROM '.$masterTable.' WHERE id=?',array($row[$kind.'_id']))->fetch(PDO::FETCH_ASSOC);
                if (!$master) throw new RuntimeException('Cost source item missing, legacy_id='.$row['id']);
                $entity=$kind.'_usages'; $amount=isset($row['amount'])?(float)$row['amount']:0;
                if ($kind==='equipment') {
                    $unit=isset($row['work_unit']) && (float)$row['work_unit']>0?(float)$row['work_unit']:1;
                    $rate=isset($row['base_rate_snapshot']) && (float)$row['base_rate_snapshot']>0?(float)$row['base_rate_snapshot']:(isset($master['base_rate'])?(float)$master['base_rate']:0);
                    if (abs($amount)<=0.0001) $amount=$unit*$rate;
                    $row['quantity']=sprintf('%.4f',$unit); $row['rate']=sprintf('%.2f',$rate); $row['final_amount']=sprintf('%.2f',$amount);
                } elseif (isset($master['category']) && $master['category']==='안전관리비') { $entity='safety_costs'; $row['source_table']='cpms_material_usage'; }
                $this->writer->record($entity,self::legacy($row));
                $this->writer->amount($row['project_id'],$entity==='safety_costs'?'safety':$kind,$amount);
            }
        }
        $this->writer->phase='subcontract';
        foreach ($this->rows('cpms_outsourcing_costs') as $row) { $this->writer->record('subcontract_costs',self::legacy($row)); $this->writer->amount($row['project_id'],'subcontract',$row['amount']); }
        $this->writer->phase='billing';
        foreach ($this->rows('cpms_progress_billings') as $row) {
            $recognized=isset($row['recognized_amount'])?(float)$row['recognized_amount']:0; $requested=isset($row['requested_amount'])?(float)$row['requested_amount']:0;
            $row['effective_amount']=sprintf('%.2f',$recognized!=0?$recognized:($requested>0?$requested:0));
            $this->writer->record('progress_billings',self::legacy($row)); $this->writer->amount($row['project_id'],'billing',$row['effective_amount']);
        }
        $this->writer->phase='statement_files';
        foreach ($this->rows('cpms_material_statement_files') as $row) {
            $path=self::statementPath(isset($row['stored_path'])?$row['stored_path']:'',$this->root,$this->fileRoot);
            unset($row['stored_path']);
            $file=$this->writer->addFile($path,$row['original_name'],$row['id']);
            $this->writer->record('material_statement_files',array_merge(self::legacy($row),$file));
        }
    }
}
