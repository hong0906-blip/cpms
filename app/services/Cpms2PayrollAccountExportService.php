<?php
// app/services/Cpms2PayrollAccountExportService.php
// Reads the effective monthly JSON version without write-capable payroll helpers.
class Cpms2PayrollAccountExportService
{
    private $root; private $storage;
    public function __construct($root,$storage) { $this->root=$root; $this->storage=$storage; }
    private function effectiveVersion(&$details)
    {
        $best=''; $path=''; $current=date('Y-m'); $unreadable=false;
        $directories=array_unique(array($this->root.'/data/company_overhead/payroll_versions',$this->storage.'/company_overhead/payroll_versions'));
        foreach ($directories as $directory) {
            if (!is_dir($directory)) continue;
            if (!is_readable($directory)) { $unreadable=true; continue; }
            $years=@scandir($directory); if (!is_array($years)) throw new RuntimeException('EMPLOYEE_PAYROLL_READ_FAILED');
            foreach ($years as $year) if (preg_match('/^\d{4}$/D',$year) && is_dir($directory.'/'.$year)) {
                $files=@scandir($directory.'/'.$year); if (!is_array($files)) throw new RuntimeException('EMPLOYEE_PAYROLL_READ_FAILED');
                foreach ($files as $file) if (preg_match('/^(0[1-9]|1[0-2])\.json$/D',$file,$m) && is_file($directory.'/'.$year.'/'.$file)) {
                    $details['source_found']=true;
                    $month=$year.'-'.$m[1]; if ($month<=$current && $month>$best) { $best=$month; $path=$directory.'/'.$year.'/'.$file; }
                }
            }
        }
        if ($path==='') {
            if ($unreadable) throw new RuntimeException('EMPLOYEE_PAYROLL_READ_FAILED');
            $details['status']=$details['source_found']?'EMPLOYEE_PAYROLL_EFFECTIVE_VERSION_NOT_FOUND':'EMPLOYEE_PAYROLL_SOURCE_NOT_FOUND'; return array();
        }
        $details['selected_month']=$best; $details['status']='EMPLOYEE_PAYROLL_VERSION_FOUND';
        $json=@file_get_contents($path); $version=is_string($json)?json_decode($json,true):null;
        if (!is_array($version) || !isset($version['employees']) || !is_array($version['employees'])) throw new RuntimeException('EMPLOYEE_PAYROLL_READ_FAILED');
        $details['employee_rows']=count($version['employees']);
        return $version['employees'];
    }
    public function accounts($source)
    {
        $result=array('accounts'=>array(),'counts'=>Cpms2SensitiveExportService::counts(),'failures'=>array(),'details'=>array('source_found'=>false,'selected_month'=>'','employee_rows'=>0,'account_rows'=>0,'mapping_success'=>0,'mapping_failed'=>0,'status'=>'EMPLOYEE_PAYROLL_SOURCE_NOT_FOUND'));
        try { $payroll=$this->effectiveVersion($result['details']); }
        catch (RuntimeException $e) { $result['details']['status']='EMPLOYEE_PAYROLL_READ_FAILED'; $result['counts']['failed']=1; $result['failures'][]=array('entity'=>'employees','legacy_id'=>'','name'=>'','code'=>'EMPLOYEE_PAYROLL_READ_FAILED'); return $result; }
        if (!$payroll) return $result;
        $employees=array(); $indices=array('id'=>array(),'number'=>array(),'key'=>array());
        foreach ($source->rows('employees',explode(' ','id name employee_no employee_key payroll_employee_key birth_date hire_date position')) as $employee) {
            $id=(string)$employee['id']; $employees[$id]=$employee; $indices['id'][$id]=array($id);
            if (!empty($employee['employee_no'])) $indices['number'][(string)$employee['employee_no']][]=$id;
            foreach (array('employee_key','payroll_employee_key') as $field) if (!empty($employee[$field])) $indices['key'][(string)$employee[$field]][]=$id;
            // The parser's EMP hash is an identity only with an additional date.
            $birth=isset($employee['birth_date'])?(string)$employee['birth_date']:''; $joined=isset($employee['hire_date'])?(string)$employee['hire_date']:'';
            if (($birth!=='' && $birth!=='0000-00-00') || ($joined!=='' && $joined!=='0000-00-00')) {
                $key='EMP-'.substr(sha1($employee['name'].'|'.$birth.'|'.$joined.'|'.(isset($employee['position'])?$employee['position']:'')),0,16);
                $indices['key'][$key][]=$id;
            }
        }
        foreach ($payroll as $row) {
            if (!is_array($row)) { $result['counts']['failed']++; $result['failures'][]=array('entity'=>'employees','legacy_id'=>'','name'=>'','code'=>'EMPLOYEE_PAYROLL_READ_FAILED'); continue; }
            $hasSource=Cpms2SensitiveExportService::hasSource($row);
            if ($hasSource) { $result['counts']['source']++; $result['details']['account_rows']++; }
            else { $result['counts']['missing_number']++; if (!empty($row['bank_name']) || !empty($row['account_holder'])) $result['counts']['partial_information']++; else continue; }
            $matches=array(); $code='EMPLOYEE_PAYROLL_UNMAPPED';
            foreach (array('employee_id'=>'id','employee_no'=>'number','employee_key'=>'key') as $field=>$index) {
                if (!isset($row[$field]) || trim((string)$row[$field])==='') continue;
                $value=trim((string)$row[$field]);
                if (isset($indices[$index][$value])) $matches=array_values(array_unique($indices[$index][$value]));
                // Explicit ID/no must not fall through to a weaker key when invalid.
                if ($matches || $field!=='employee_key') break;
            }
            $id=count($matches)===1?$matches[0]:'';
            if (count($matches)>1) $code='EMPLOYEE_PAYROLL_CONFLICT';
            if ($id!=='' && (trim((string)$row['name'])!==trim((string)$employees[$id]['name']) || isset($result['accounts'][$id]))) { $id=''; $code='EMPLOYEE_PAYROLL_CONFLICT'; }
            if ($id!=='') {
                try { $result['accounts'][$id]=Cpms2SensitiveExportService::plainAccount($row,'EMPLOYEE_ACCOUNT_MISSING'); if ($hasSource) { $result['counts']['verified']++; $result['details']['mapping_success']++; } continue; }
                catch (RuntimeException $e) { $code=$e->getMessage(); }
            }
            if (!$hasSource) continue; // Unmapped partial metadata cannot lose an account number.
            $result['counts']['failed']++; $result['details']['mapping_failed']++;
            // No account/hash/key/JSON payload is exposed in the failure report.
            $result['failures'][]=array('entity'=>'employees','legacy_id'=>$id,'name'=>isset($row['name'])?(string)$row['name']:'','code'=>$code);
        }
        return $result;
    }
}
