<?php
// app/services/Cpms2WebExportService.php
// Web-only orchestration. All database access goes through the SELECT/SHOW guard.
require_once __DIR__.'/Cpms2MigrationExportService.php';
class Cpms2WebExportService
{
    private $source; private $storage;
    private $attendance;
    private $root;
    private $fileRoot;
    private $privateRoot;
    private $documentRoot;
    private $sensitive; private $phase='employees'; private $lastReport=null;
    public function __construct($source,$attendance,$root,$fileRoot,$privateRoot,$documentRoot,$storageRoot=null)
    {
        $this->storage=$storageRoot===null?(function_exists('cpms_storage_root')?cpms_storage_root():$root.'/storage'):$storageRoot;
        $this->source=$source; $this->attendance=$attendance; $this->root=$root;
        $this->fileRoot=$fileRoot; $this->privateRoot=$privateRoot; $this->documentRoot=$documentRoot;
        $this->sensitive=new Cpms2SensitiveExportService($root,$storageRoot);
    }
    public function phase() { return $this->phase; }
    public function lastPreflight() { return $this->lastReport; }
    public function authorize($session)
    {
        $user=isset($session['cpms_user']) && is_array($session['cpms_user'])?$session['cpms_user']:array();
        if (empty($user['id']) || empty($user['email'])) throw new RuntimeException('LOGIN_REQUIRED');
        $this->source->inspect('employees',true,array('id','email','name','department','role','is_active'));
        $employee=$this->source->query('SELECT id,name,email,department,role,is_active FROM employees WHERE id=? AND LOWER(email)=LOWER(?)',array((int)$user['id'],(string)$user['email']))->fetch(PDO::FETCH_ASSOC);
        // Same employee-management policy as Auth::canManageEmployees, using fresh
        // employee data instead of Auth's cookie/key-writing auto-login lifecycle.
        $department=$employee?Cpms2MigrationExportService::department($employee['department']):'';
        if (!$employee || !(int)$employee['is_active'] || (!in_array($department,array('개발','관리')) && $employee['role']!=='executive')) throw new RuntimeException('ACCESS_DENIED');
        return $employee;
    }
    private static function normalized($path)
    {
        $path=rtrim(str_replace('\\','/',$path),'/'); return DIRECTORY_SEPARATOR==='\\'?strtolower($path):$path;
    }
    public function checkedPrivateRoot($create=false)
    {
        if ($this->privateRoot==='') throw new RuntimeException('PRIVATE_STORAGE_REQUIRED');
        $candidate=$this->privateRoot; $tail=array();
        while (!file_exists($candidate)) {
            $parent=dirname($candidate); if ($parent===$candidate) throw new RuntimeException('PRIVATE_STORAGE_REQUIRED');
            array_unshift($tail,basename($candidate)); $candidate=$parent;
        }
        $ancestor=realpath($candidate); if (!$ancestor || !is_dir($ancestor)) throw new RuntimeException('PRIVATE_STORAGE_REQUIRED');
        $resolved=rtrim($ancestor,'/\\').(count($tail)?'/'.implode('/',$tail):'');
        if (!is_writable($ancestor)) throw new RuntimeException('PRIVATE_STORAGE_REQUIRED');
        if ($create && !is_dir($resolved) && !mkdir($resolved,0700,true)) throw new RuntimeException('PRIVATE_STORAGE_REQUIRED');
        return $create?realpath($resolved):$resolved;
    }
    public function preflight()
    {
        if (!class_exists('ZipArchive')) throw new RuntimeException('ZIP_EXTENSION_REQUIRED');
        $this->checkedPrivateRoot();
        $counts=array(); $warnings=array();
        foreach (Cpms2MigrationExportService::$fields as $table=>$fields) {
            $this->phase=Cpms2MigrationExportService::tablePhase($table);
            $required=in_array($table,array('employees','cpms_vendors','workers','direct_team_members','cpms_projects'));
            $columns=$this->source->inspect($table,$required,array('id','name'));
            if (!$columns) { $counts[$table]=0; $warnings[]='Optional source table missing: '.$table; continue; }
            $filter=array(); if (in_array('is_deleted',$columns)) $filter[]='COALESCE(is_deleted,0)=0';
            if (in_array('deleted_at',$columns)) $filter[]='deleted_at IS NULL';
            $counts[$table]=(int)$this->source->query('SELECT COUNT(*) FROM '.$table.(count($filter)?' WHERE '.implode(' AND ',$filter):''))->fetchColumn();
        }
        $expected=0; $missing=array();
        foreach ($this->source->rows('cpms_material_statement_files',array('id','stored_path','original_name')) as $r) {
            $expected++;
            if (!Cpms2MigrationExportService::statementPath(isset($r['stored_path'])?$r['stored_path']:'',$this->root,$this->fileRoot)) $missing[]=$r['id'];
        }
        $this->phase='labor'; $gongsu=cpms_find_gongsu_table($this->source);
        if (!$gongsu && $this->attendance) $gongsu=cpms_find_gongsu_table($this->attendance);
        if (!$gongsu && (!$this->attendance || !count($this->attendance->inspect('attendance')))) throw new RuntimeException('ATTENDANCE_SOURCE_REQUIRED');
        $excluded=Cpms2LaborExportService::excludedForceAdjustments($this->source);
        $warnings=array_merge($warnings,Cpms2LaborExportService::exclusionWarnings($excluded));
        if ($missing) $warnings[]='Missing statement files: '.count($missing);
        $this->phase='employees'; $accounts=$this->sensitive->preflight($this->source);
        $this->phase='safety'; $safety=(new Cpms2SafetyCostExportService($this->root,$this->storage))->collect($this->source);
        $this->phase='completed_approvals'; $archive=(new Cpms2CompletedApprovalExportService($this->storage))->collect($this->source);
        $warnings=array_merge($warnings,$safety['warnings'],$archive['warnings']);
        $failures=array_merge($accounts['failures'],$archive['failures']);
        if ($failures) $this->phase=isset($failures[0]['entity'])?$failures[0]['entity']:'completed_approvals';
        return $this->lastReport=array('counts'=>$counts,'expected_file_count'=>$expected,'missing_file_count'=>count($missing),'missing_file_rows'=>$missing,'excluded_labor_force_adjustments'=>$excluded,'accounts'=>$accounts,'safety_costs'=>$safety['summary'],'completed_approvals'=>$archive['summary'],'failures'=>$failures,'can_export'=>!count($failures),'warnings'=>$warnings);
    }
    public function generate($employee)
    {
        $report=$this->preflight();
        if (!$report['can_export']) throw new Cpms2ExportFailure($this->phase,$report['failures'][0]['code']);
        $private=$this->checkedPrivateRoot(true);
        $lock=fopen($private.'/employee-'.(int)$employee['id'].'.lock','c');
        if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { if ($lock) fclose($lock); throw new RuntimeException('EXPORT_ALREADY_RUNNING'); }
        $writer=null; $output='';
        try {
            $strong=false; $random=openssl_random_pseudo_bytes(24,$strong);
            if ($random===false || !$strong) throw new RuntimeException('SECURE_RANDOM_REQUIRED');
            $id=bin2hex($random); $output=$private.'/'.$id.'.zip';
            $writer=new Cpms2ExportPackageWriter($private.'/.stage-'.$id);
            $writer->accountPreflight=$report['accounts'];
            (new Cpms2MigrationExportService($this->source,$writer,$this->root,$this->fileRoot,$this->sensitive,$this->storage))->run($this->attendance);
            $commit=getenv('CPMS2_EXPORT_SOURCE_COMMIT'); if (!$commit || !preg_match('/^[a-f0-9]{40}$/D',$commit)) { $commit=null; $writer->warnings[]='Source commit unavailable in FileZilla deployment; source_code_sha256 is recorded.'; }
            $writer->phase='summary';
            $fingerprint=''; foreach (array('Cpms2ReadOnlySource','Cpms2ExportPackageWriter','Cpms2MigrationExportService','Cpms2LaborExportService','Cpms2WebExportService','Cpms2SensitiveExportService','Cpms2PayrollAccountExportService','Cpms2ExportDiagnostic','Cpms2SafetyCostExportService','Cpms2CompletedApprovalExportService','Cpms2ReadOnlyDriveDownload') as $file) $fingerprint.=hash_file('sha256',__DIR__.'/'.$file.'.php');
            $schema=$this->source->report(); if ($this->attendance) $schema['attendance_database']=$this->attendance->report();
            $summary=$writer->finish($output,array('format'=>'cpms1-company-export','format_version'=>1,'export_id'=>'cpms1-'.$id,'created_at'=>date('c'),'source_system'=>'cpms1','source_repository'=>'hong0906-blip/cpms','source_commit'=>$commit,'source_code_sha256'=>hash('sha256',$fingerprint),'php_version'=>PHP_VERSION,'database_name'=>$this->source->databaseName()),$schema);
            return array('id'=>$id,'owner_employee_id'=>(int)$employee['id'],'created_at'=>date('c'),'size'=>filesize($output),'sha256'=>hash_file('sha256',$output),'summary'=>$summary);
        } catch (Exception $e) {
            if ($output!=='' && is_file($output)) unlink($output); // Only this newly created package.
            throw Cpms2ExportFailure::safe($writer?$writer->phase:$this->phase,$e);
        } finally {
            if ($writer) $writer->cleanup();
            flock($lock,LOCK_UN); fclose($lock);
        }
    }
    public function downloadPath($id,$package,$employee)
    {
        if (!is_string($id) || !preg_match('/^[a-f0-9]{48}$/D',$id) || !is_array($package) || $package['id']!==$id || (int)$package['owner_employee_id']!==(int)$employee['id']) throw new RuntimeException('PACKAGE_ACCESS_DENIED');
        $private=$this->checkedPrivateRoot(); $path=realpath($private.'/'.$id.'.zip');
        if (!$path || self::normalized(dirname($path))!==self::normalized($private) || !is_file($path) || filesize($path)!==$package['size'] || !hash_equals($package['sha256'],hash_file('sha256',$path))) throw new RuntimeException('PACKAGE_INTEGRITY_FAILED');
        return $path;
    }
}
