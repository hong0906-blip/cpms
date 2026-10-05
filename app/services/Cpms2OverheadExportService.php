<?php
// app/services/Cpms2OverheadExportService.php
// Read-only parsers reproduce CompanyOverheadService precedence without loading write-capable services.
require_once __DIR__.'/Cpms2ManagementPreflightSupport.php';
class Cpms2OverheadExportService
{
    private $source; private $support; private $root; private $storage; private $roots; private $cutoff;
    private $files=array(); private $employees=array(); private $employeeNames=array(); private $vehicles=array();
    private $archives=array(); private $archiveIndex=array(); private $versions=array(); private $manual=array();
    private $duplicates=array(); private $matches=array(); private $fuelNumbers=array(); private $inventoryLimit=50000;
    private $exportRows=array(); private $sourceHashes=array(); private $inspection=null;
    public function __construct($source,$root,$storage,$support,$cutoff=null)
    {
        $this->source=$source; $this->support=$support; $this->root=rtrim($root,'/\\'); $this->storage=rtrim($storage,'/\\');
        $this->roots=array('data'=>$this->root.'/data/company_overhead','storage'=>$this->storage.'/company_overhead');
        $this->cutoff=$cutoff===null?date('Y-m'):$cutoff;
        if (!Cpms2ManagementPreflightSupport::month($this->cutoff)) throw new RuntimeException('INVALID_PREFLIGHT_CUTOFF');
    }
    private function inventory($root)
    {
        $r=array('exists'=>is_dir($root),'readable'=>is_readable($root),'file_count'=>0,'directory_count'=>0,'history_file_count'=>0,'fuel_history_files'=>0,'fuel_log_files'=>0);
        if (!$r['exists']) return $r;
        if (!$r['readable']) { $this->support->issue('Overhead','SOURCE_ROOT_UNREADABLE','BLOCKING'); return $r; }
        try {
            $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
            foreach ($it as $f) {
                if ($f->isLink()) { $this->support->issue('Overhead','SOURCE_SYMLINK_SKIPPED'); continue; }
                if ($f->isDir()) $r['directory_count']++; elseif ($f->isFile()) {
                    $r['file_count']++; $relative=substr(str_replace('\\','/',$f->getPathname()),strlen(str_replace('\\','/',$root))+1);
                    if (strpos($relative,'_history/')!==false) $r['history_file_count']++;
                    if (strpos($relative,'fuel/')===0 && strpos($relative,'_history/')!==false) $r['fuel_history_files']++;
                    if (strpos($relative,'fuel/')===0 && strpos($relative,'_logs.json')!==false) $r['fuel_log_files']++;
                }
                if ($r['file_count']+$r['directory_count']>$this->inventoryLimit) { $this->support->issue('Overhead','SOURCE_INVENTORY_LIMIT','BLOCKING'); break; }
            }
        } catch (Exception $e) { $this->support->issue('Overhead','SOURCE_DIRECTORY_READ_FAILED','BLOCKING'); }
        return $r;
    }
    private function readJson($path)
    {
        if (!is_file($path)) return null;
        $before=@hash_file('sha256',$path); $data=$this->support->json($path); $after=@hash_file('sha256',$path);
        if ($before===false || $after===false || $before!==$after) $this->support->issue('Overhead','SOURCE_CHANGED_DURING_READ','BLOCKING');
        else $this->sourceHashes[$path]=$after;
        return $data;
    }
    private function months($category)
    {
        $out=array(); foreach ($this->roots as $label=>$root) {
            $years=glob($root.'/'.$category.'/[0-9][0-9][0-9][0-9]',GLOB_ONLYDIR); if (!$years) continue;
            foreach ($years as $year) {
                $paths=glob($year.'/[0-9][0-9].json'); if (!$paths) continue;
                foreach ($paths as $path) {
                    $ym=basename($year).'-'.substr(basename($path),0,2);
                    if (!Cpms2ManagementPreflightSupport::month($ym)) { $this->support->issue('Overhead','SOURCE_MONTH_INVALID'); continue; }
                    if (!isset($out[$ym])) $out[$ym]=array(); $out[$ym][$label]=$path;
                }
            }
        } ksort($out); return $out;
    }
    private function select($category,$ym,$paths,$firstValid=false,$preferred=null)
    {
        $selected=null; $data=null;
        foreach ($paths as $label=>$path) {
            if ($preferred!==null && $preferred!==$label) continue;
            $candidate=$this->readJson($path);
            if (!$firstValid || $candidate!==null) { $selected=$label; $data=$candidate; break; }
        }
        if (count($paths)>1) {
            $a=@hash_file('sha256',reset($paths)); $b=@hash_file('sha256',end($paths));
            $same=$a!==false && $b!==false && $a===$b;
            if ($a===false || $b===false) $this->support->issue('Overhead','SOURCE_HASH_READ_FAILED','BLOCKING');
            $this->duplicates[]=array('category'=>$category,'month'=>$ym,'selected_root'=>$selected,'other_root_exists'=>true,'same_sha'=>$same,'different_sha'=>!$same);
            if (!$same) $this->support->issue('Overhead','DUPLICATE_SOURCE_DIFFERENT_CONTENT');
        }
        return array('data'=>$data,'selected_root'=>$selected);
    }
    private function amount($row,$category)
    {
        if (!is_array($row) || !empty($row['deleted_at'])) return '0.00';
        $actualCategory=isset($row['category'])?$row['category']:$category;
        if ($actualCategory==='lease') return Cpms2ManagementPreflightSupport::add($this->support->money(isset($row['amount'])?$row['amount']:0),$this->support->money(isset($row['maintenance_fee'])?$row['maintenance_fee']:0));
        foreach (array('amount','net_pay','total_amount','cost_amount','salary_amount','pay_amount','price','cost','total','value') as $key) if (isset($row[$key]) && !is_array($row[$key])) return $this->support->money($row[$key]);
        $total='0.00'; foreach ($row as $value) if (is_array($value)) {
            if (isset($value['amount']) || isset($value['id'])) $total=Cpms2ManagementPreflightSupport::add($total,$this->amount($value,$category));
            else foreach ($value as $child) if (is_array($child)) $total=Cpms2ManagementPreflightSupport::add($total,$this->amount($child,$category));
        } return $total;
    }
    private function employees()
    {
        $columns=$this->source->columns('employees'); if (!in_array('id',$columns)) return;
        $fields=array_intersect(array('id','name'),$columns); $last=0;
        do {
            $st=$this->source->query('SELECT `'.implode('`,`',$fields).'` FROM employees WHERE id>? ORDER BY id LIMIT 500',array($last)); $n=0;
            while ($r=$st->fetch(PDO::FETCH_ASSOC)) {
                $last=(int)$r['id']; $this->employees[$last]=true; $n++;
                if (isset($r['name'])) { $name=trim($r['name']); if (!isset($this->employeeNames[$name])) $this->employeeNames[$name]=0; $this->employeeNames[$name]++; }
            }
        } while ($n===500);
    }
    private function vehicleNumber($v) { return preg_replace('/[^0-9가-힣]/u','',trim((string)$v)); }
    public static function changeSort($a,$b) { return strcmp($a['effective_ym'],$b['effective_ym']); }
    private function vehicleData()
    {
        $paths=array(); foreach ($this->roots as $l=>$r) if (is_file($r.'/company_vehicles/vehicles.json')) $paths[$l]=$r.'/company_vehicles/vehicles.json';
        $selected=$this->select('company_vehicles','MASTER',$paths,true);
        $data=$selected['data']; $this->vehicles=is_array($data) && isset($data['vehicles']) && is_array($data['vehicles'])?$data['vehicles']:array();
        $r=array('vehicle_count'=>count($this->vehicles),'active_vehicles'=>0,'deleted_vehicles'=>0,'duplicate_vehicle_numbers'=>0,'monthly_payment_changes'=>0,'driver_changes'=>0,'unmapped_employee_drivers'=>0,'first_effective_month'=>null,'last_effective_month'=>null,'selected_root'=>$selected['selected_root'],'auto_payment_amount'=>'0.00','monthly_rows_amount'=>'0.00','excluded_from_auto_amount'=>array('insurance_premium','deposit','remaining_amount','principal'));
        $numbers=array();
        foreach ($this->vehicles as &$v) {
            if (!is_array($v)) { $this->support->issue('Overhead','VEHICLE_SCHEMA_INVALID','BLOCKING'); $v=array(); continue; }
            if (!empty($v['deleted_at'])) $r['deleted_vehicles']++; else $r['active_vehicles']++;
            $number=$this->vehicleNumber(isset($v['vehicle_number'])?$v['vehicle_number']:'');
            if ($number!=='') { if (isset($numbers[$number])) $r['duplicate_vehicle_numbers']++; $numbers[$number]=true; }
            foreach (array('monthly_payment_changes','driver_changes') as $key) {
                $clean=array(); if (isset($v[$key]) && is_array($v[$key])) foreach ($v[$key] as $change) if (is_array($change) && isset($change['effective_ym']) && Cpms2ManagementPreflightSupport::month($change['effective_ym'])) {
                    $clean[]=$change; $ym=$change['effective_ym']; $r[$key]++;
                    if ($r['first_effective_month']===null || $ym<$r['first_effective_month']) $r['first_effective_month']=$ym;
                    if ($r['last_effective_month']===null || $ym>$r['last_effective_month']) $r['last_effective_month']=$ym;
                    if ($key==='driver_changes' && !empty($change['driver_name']) && (!isset($this->employeeNames[trim($change['driver_name'])]) || $this->employeeNames[trim($change['driver_name'])]!==1)) $r['unmapped_employee_drivers']++;
                }
                usort($clean,array(__CLASS__,'changeSort')); $v[$key]=$clean;
            }
            if (!empty($v['driver_name']) && (!isset($this->employeeNames[trim($v['driver_name'])]) || $this->employeeNames[trim($v['driver_name'])]!==1)) $r['unmapped_employee_drivers']++;
        } unset($v);
        $this->support->issue('Overhead','VEHICLE_NUMBER_DUPLICATE','WARNING',$r['duplicate_vehicle_numbers']);
        $this->support->issue('Overhead','DRIVER_IDENTITY_NEEDS_MAPPING','WARNING',$r['unmapped_employee_drivers']);
        return $r;
    }
    private function vehicleMonth($ym)
    {
        $amount='0.00'; $count=0; $payments=array();
        foreach ($this->vehicles as $vehicleIndex=>$v) {
            if (!empty($v['deleted_at']) || (isset($v['active']) && (string)$v['active']==='0')) continue;
            $pay=$this->support->money(isset($v['monthly_payment'])?$v['monthly_payment']:0);
            foreach ($v['monthly_payment_changes'] as $c) if ($c['effective_ym']<=$ym) $pay=$this->support->money(isset($c['amount'])?$c['amount']:0);
            if ($pay==='0.00' || $pay[0]==='-') continue;
            $start=''; $end=''; foreach (array('finance_start','schedule_start') as $key) if (!empty($v[$key])) { $start=substr($v[$key],0,7); break; }
            foreach (array('finance_end','schedule_end') as $key) if (!empty($v[$key])) { $end=substr($v[$key],0,7); break; }
            if ($start!=='' && !Cpms2ManagementPreflightSupport::month($start)) { $this->support->issue('Overhead','VEHICLE_PERIOD_INVALID','BLOCKING'); continue; }
            if ($end!=='' && !Cpms2ManagementPreflightSupport::month($end)) { $this->support->issue('Overhead','VEHICLE_PERIOD_INVALID','BLOCKING'); continue; }
            if ($end==='' && $start!=='' && !empty($v['total_count'])) $end=$this->addMonths($start,(int)$v['total_count']-1);
            if ($end==='') {
                $remaining=$this->support->money(isset($v['remaining_amount'])?$v['remaining_amount']:0); $basePay=$this->support->money(isset($v['monthly_payment'])?$v['monthly_payment']:0);
                if ($remaining!=='0.00' && $remaining[0]!=='-' && $basePay!=='0.00' && $basePay[0]!=='-') {
                    // Decimal repeated subtraction is bounded; no floating-point schedule division.
                    $n=0; while ($remaining[0]!=='-' && $remaining!=='0.00' && $n<2400) { $remaining=Cpms2ManagementPreflightSupport::add($remaining,Cpms2ManagementPreflightSupport::negate($basePay)); $n++; }
                    if ($n===2400) { $this->support->issue('Overhead','VEHICLE_PAYMENT_PERIOD_UNBOUNDED','BLOCKING'); continue; }
                    $base=isset($v['baseline_ym']) && Cpms2ManagementPreflightSupport::month($v['baseline_ym'])?$v['baseline_ym']:$this->cutoff;
                    $end=$this->addMonths($base,$n);
                }
            }
            if ($start==='' || $start<'2026-01') $start='2026-01'; if ($end==='') $end=substr($ym,0,4).'-12';
            if ($ym<$start || $ym>$end) continue;
            $driverName=isset($v['driver_name'])?trim((string)$v['driver_name']):''; $driverId=isset($v['driver_employee_id'])?(int)$v['driver_employee_id']:0;
            foreach ($v['driver_changes'] as $change) if ($change['effective_ym']<=$ym) {
                if (isset($change['driver_name'])) $driverName=trim((string)$change['driver_name']);
                if (isset($change['driver_employee_id'])) $driverId=(int)$change['driver_employee_id'];
            }
            if (!$driverId || !isset($this->employees[$driverId])) $driverId=null;
            $payments[]=array('source_index'=>$vehicleIndex,'legacy_vehicle_id'=>isset($v['id']) && is_scalar($v['id'])?(string)$v['id']:null,
                'amount'=>$pay,'vehicle_name'=>$this->field($v,array('vehicle_name','name','model')),'vehicle_number'=>$this->field($v,array('vehicle_number')),
                'driver_legacy_employee_id'=>$driverId,'driver_name_snapshot'=>$driverName===''?null:$driverName,'payment_type'=>'monthly_payment');
            $amount=Cpms2ManagementPreflightSupport::add($amount,$pay); $count++;
        }
        return array('amount'=>$amount,'count'=>$count,'payments'=>$payments);
    }
    private function addMonths($ym,$n) { return date('Y-m',strtotime($ym.'-01 '.$n.' months')); }
    private function field($row,$keys)
    {
        foreach ($keys as $key) if (isset($row[$key]) && is_scalar($row[$key]) && trim((string)$row[$key])!=='') return trim((string)$row[$key]);
        return null;
    }
    private function targetCategory($category)
    {
        $map=self::categoryMapping(); return isset($map[$category])?$map[$category]:null;
    }
    public static function categoryMapping()
    {
        return array('payroll'=>'payroll','vehicles'=>'vehicle','lease'=>'lease','corporate_cards'=>'corporate_card','fuel'=>'fuel','etc'=>'other');
    }
    private function occurredDate($row,$ym)
    {
        foreach (array('occurred_date','use_date','expense_date','payment_date','date','created_at') as $key) if (!empty($row[$key])) {
            $date=substr((string)$row[$key],0,10); if (Cpms2ManagementPreflightSupport::safeDate($date)) return $date;
        }
        return $ym.'-01';
    }
    private function reference($category,$ym,$row,$index,$root,$suffix)
    {
        $target=$this->targetCategory($category); if ($category==='payroll') return 'cpms1:payroll:'.$ym;
        $legacy=$this->field($row,array('id','legacy_id','vehicle_id','lease_group_id','lease_import_key'));
        $token=hash('sha256',$category.'|'.$ym.'|'.(string)$root.'|'.(string)$index.'|'.(string)$legacy.'|'.$suffix);
        return 'cpms1:'.$target.':'.substr($token,0,24);
    }
    private function description($category)
    {
        $labels=array('payroll'=>'CPMS1 임직원 급여','vehicles'=>'CPMS1 차량비','lease'=>'CPMS1 임대/관리비','corporate_cards'=>'CPMS1 법인카드','fuel'=>'CPMS1 유류비','etc'=>'CPMS1 기타 관리비');
        return $labels[$category];
    }
    private function explicitEmployee($row,$key)
    {
        $id=isset($row[$key])?(int)$row[$key]:0; return $id && isset($this->employees[$id])?$id:null;
    }
    private function appendExportRow($category,$ym,$amount,$basis,$row,$index,$selectedRoot,$suffix,$detail)
    {
        $reference=$this->reference($category,$ym,$row,$index,$selectedRoot,$suffix); $target=$this->targetCategory($category);
        $entry=array('legacy_id'=>$reference,'category'=>$target,'attribution_month'=>$ym.'-01','occurred_date'=>$this->occurredDate($row,$ym),
            'description'=>$this->description($category),'amount'=>$this->support->money($amount),'source_type'=>'cpms1_legacy','source_reference'=>$reference,
            'source_basis'=>$basis,'memo'=>null,'metadata'=>array('source_category'=>$category,'source_month'=>$ym,'source_row_index'=>(int)$index,'source_root'=>$selectedRoot));
        if ($category==='etc') $entry['metadata']['attachment_exists']=!empty($row['attachments']) || !empty($row['attachment']) || !empty($row['original_name']) || !empty($row['stored_name']) || !empty($row['drive_file_id']);
        $this->exportRows['overhead_entries'][]=$entry;
        if ($detail!==null) { $detail['legacy_entry_id']=$reference; $this->exportRows[$detail['_entity']][]=array_diff_key($detail,array('_entity'=>true)); }
    }
    private function appendLocalRows($category,$ym,$rows,$basis,$selectedRoot)
    {
        foreach ($rows as $index=>$row) {
            if (!is_array($row) || !empty($row['deleted_at'])) continue;
            $amount=$this->amount($row,$category); $detail=null;
            if ($category==='vehicles') $detail=array('_entity'=>'overhead_vehicle_details','vehicle_name'=>$this->field($row,array('vehicle_name','name','model')),
                'vehicle_number'=>$this->field($row,array('vehicle_number')),'driver_legacy_employee_id'=>$this->explicitEmployee($row,'driver_employee_id'),
                'driver_name_snapshot'=>$this->field($row,array('driver_name')),'payment_type'=>$this->field($row,array('payment_type')));
            elseif ($category==='lease') $detail=array('_entity'=>'overhead_lease_details','lease_name'=>$this->field($row,array('lease_name','name','description')),
                'rent_amount'=>$this->support->money(isset($row['amount'])?$row['amount']:0),'maintenance_amount'=>$this->support->money(isset($row['maintenance_fee'])?$row['maintenance_fee']:0),
                'deposit_amount'=>$this->support->money(isset($row['deposit'])?$row['deposit']:0));
            elseif ($category==='corporate_cards') {
                $number=$this->field($row,array('card_last4','card_number')); $digits=$number===null?'':preg_replace('/\D/','',$number); $last4=strlen($digits)>=4?substr($digits,-4):null;
                $detail=array('_entity'=>'overhead_card_details','card_last4'=>$last4,'card_holder_name'=>$this->field($row,array('card_holder_name','holder_name','user_name')),
                    'merchant_name'=>$this->field($row,array('merchant_name','merchant','store_name')),'supply_amount'=>$this->support->money($this->field($row,array('supply_amount','supply'))),
                    'tax_amount'=>$this->support->money($this->field($row,array('tax_amount','vat','vat_amount'))));
            } elseif ($category==='fuel') $detail=array('_entity'=>'overhead_fuel_details','vehicle_number'=>$this->field($row,array('vehicle_number_normalized','vehicle_number')),
                'supply_amount'=>$this->support->money($this->field($row,array('supply_amount','supply'))),'tax_amount'=>$this->support->money($this->field($row,array('tax_amount','vat','vat_amount'))));
            $this->appendExportRow($category,$ym,$amount,$basis,$row,$index,$selectedRoot,'row',$detail);
        }
    }
    private function appendVehiclePayments($ym,$vehicle,$basis,$selectedRoot)
    {
        foreach ($vehicle['payments'] as $payment) {
            $detail=$payment; unset($detail['source_index'],$detail['legacy_vehicle_id'],$detail['amount']); $detail['_entity']='overhead_vehicle_details';
            $source=array('id'=>$payment['legacy_vehicle_id'],'vehicle_number'=>$payment['vehicle_number']);
            $this->appendExportRow('vehicles',$ym,$payment['amount'],$basis,$source,$payment['source_index'],$selectedRoot,'auto',$detail);
        }
    }
    private function reconciliation($report)
    {
        $sourceMonths=array(); $exportedMonths=array(); $sourceCategories=array(); $exportedCategories=array(); $sourceGrand='0.00'; $exportedGrand='0.00';
        foreach ($report['months'] as $month) {
            $category=$this->targetCategory($month['category']); $key=$category.'/'.$month['month'];
            if (!isset($sourceMonths[$key])) $sourceMonths[$key]='0.00';
            $sourceMonths[$key]=Cpms2ManagementPreflightSupport::add($sourceMonths[$key],$month['amount']);
        }
        foreach ($report['categories'] as $category=>$stats) { $target=$this->targetCategory($category); $sourceCategories[$target]=$stats['recognized_amount']; $sourceGrand=Cpms2ManagementPreflightSupport::add($sourceGrand,$stats['recognized_amount']); }
        foreach ($this->exportRows['overhead_entries'] as $entry) {
            $month=substr($entry['attribution_month'],0,7); $key=$entry['category'].'/'.$month;
            if (!isset($exportedMonths[$key])) $exportedMonths[$key]='0.00'; if (!isset($exportedCategories[$entry['category']])) $exportedCategories[$entry['category']]='0.00';
            $exportedMonths[$key]=Cpms2ManagementPreflightSupport::add($exportedMonths[$key],$entry['amount']);
            $exportedCategories[$entry['category']]=Cpms2ManagementPreflightSupport::add($exportedCategories[$entry['category']],$entry['amount']);
            $exportedGrand=Cpms2ManagementPreflightSupport::add($exportedGrand,$entry['amount']);
        }
        $months=array(); $keys=array_unique(array_merge(array_keys($sourceMonths),array_keys($exportedMonths))); sort($keys);
        foreach ($keys as $key) { list($category,$month)=explode('/',$key,2); $source=isset($sourceMonths[$key])?$sourceMonths[$key]:'0.00'; $exported=isset($exportedMonths[$key])?$exportedMonths[$key]:'0.00'; $months[]=array('month'=>$month,'category'=>$category,'preflight_recognized_amount'=>$source,'exported_amount'=>$exported,'difference'=>Cpms2ManagementPreflightSupport::add($exported,Cpms2ManagementPreflightSupport::negate($source))); }
        $categories=array(); foreach (self::categoryMapping() as $target) { $source=isset($sourceCategories[$target])?$sourceCategories[$target]:'0.00'; $exported=isset($exportedCategories[$target])?$exportedCategories[$target]:'0.00'; $categories[$target]=array('source_recognized'=>$source,'exported'=>$exported,'difference'=>Cpms2ManagementPreflightSupport::add($exported,Cpms2ManagementPreflightSupport::negate($source))); }
        return array('months'=>$months,'categories'=>$categories,'grand_total'=>array('source'=>$sourceGrand,'exported'=>$exportedGrand,'difference'=>Cpms2ManagementPreflightSupport::add($exportedGrand,Cpms2ManagementPreflightSupport::negate($sourceGrand))));
    }
    private function payroll($preferred)
    {
        $r=array('version_count'=>0,'versions'=>array(),'empty_versions'=>0,'employee_rows'=>0,'employee_id_present'=>0,'employee_id_missing'=>0,'employee_mapping_possible'=>0,'employee_mapping_unresolved'=>0,'manual_months'=>0,'manual_total'=>'0.00','monthly_row_months'=>count($this->files['payroll']),'archive_months'=>0,'runtime_selected_root'=>$preferred);
        $r['mapping_basis']='EXPLICIT_LEGACY_EMPLOYEE_ID_ONLY; OTHER_IDENTITY_REQUIRES_PHASE_2_REVIEW';
        foreach ($this->months('payroll_versions') as $ym=>$paths) {
            // Inspect both readable roots, but recognize only the root used by the existing payroll service.
            $selected=$this->select('payroll_versions',$ym,$paths,false,$preferred);
            foreach ($paths as $l=>$path) {
                $data=$l===$selected['selected_root']?$selected['data']:$this->readJson($path);
                if (!is_array($data)) continue;
                $employees=isset($data['employees']) && is_array($data['employees'])?$data['employees']:array();
                $r['version_count']++; $r['employee_rows']+=count($employees); if (!$employees) $r['empty_versions']++;
                $r['versions'][]=array('month'=>$ym,'root'=>$l,'employee_rows'=>count($employees),'selected'=>$l===$preferred);
                foreach ($employees as $e) {
                    if (!is_array($e)) { $this->support->issue('Overhead','PAYROLL_EMPLOYEE_SCHEMA_INVALID','BLOCKING'); continue; }
                    if (!empty($e['employee_id'])) { $r['employee_id_present']++; if (isset($this->employees[(int)$e['employee_id']])) $r['employee_mapping_possible']++; else $r['employee_mapping_unresolved']++; }
                    else { $r['employee_id_missing']++; $r['employee_mapping_unresolved']++; }
                }
                if ($l===$preferred) $this->versions[$ym]=array('amount'=>$this->support->money(isset($data['total_net_pay'])?$data['total_net_pay']:0),'employee_rows'=>count($employees));
            }
        }
        ksort($this->versions);
        foreach ($this->months('payroll_manual_totals') as $ym=>$paths) {
            $s=$this->select('payroll_manual_totals',$ym,$paths,false,$preferred);
            foreach ($paths as $l=>$path) { $d=$l===$s['selected_root']?$s['data']:$this->readJson($path); if (!is_array($d)) continue;
                $a=$this->support->money(isset($d['amount'])?$d['amount']:0);
                if ($l===$preferred) { $r['manual_months']++; $r['manual_total']=Cpms2ManagementPreflightSupport::add($r['manual_total'],$a); $this->manual[$ym]=$a; }
            }
        }
        if ($r['employee_mapping_unresolved']) $this->support->issue('Overhead','PAYROLL_EMPLOYEE_IDENTITY_NEEDS_MAPPING','WARNING',$r['employee_mapping_unresolved']);
        if (!$r['version_count']) $this->support->issue('Overhead','PAYROLL_VERSION_NOT_FOUND');
        return $r;
    }
    private function archives()
    {
        $paths=glob($this->root.'/data/archive_summary/[0-9][0-9][0-9][0-9].json'); if (!$paths) $paths=array();
        foreach ($paths as $path) {
            $year=substr(basename($path),0,4); $d=$this->readJson($path);
            if (is_array($d) && isset($d['company_overhead']) && is_array($d['company_overhead'])) $this->archives[$year]=$d['company_overhead'];
        }
        $paths=glob($this->root.'/data/archive_index/[0-9][0-9][0-9][0-9].json'); if (!$paths) $paths=array();
        foreach ($paths as $path) {
            $year=substr(basename($path),0,4); $d=$this->readJson($path); $this->archiveIndex[$year]=array('verified_references'=>0,'existing_cache_packages'=>0);
            if (!is_array($d) || !isset($d['archives']) || !is_array($d['archives'])) continue;
            foreach ($d['archives'] as $a) if (is_array($a) && isset($a['status']) && $a['status']==='verified' && isset($a['archive_type']) && in_array($a['archive_type'],array('company_overhead','company_payroll','payroll','fuel'))) {
                $this->archiveIndex[$year]['verified_references']++;
                if (!empty($a['archive_id'])) {
                    $id=preg_replace('/[^a-zA-Z0-9_-]/','_', (string)$a['archive_id']);
                    if (is_file($this->storage.'/tmp/archive_cache/'.$year.'/'.$id.'/package.json')) $this->archiveIndex[$year]['existing_cache_packages']++;
                }
            }
        }
    }
    private function archiveAmount($ym,$category)
    {
        $year=substr($ym,0,4); $month=substr($ym,5,2); $out=array('exists'=>false,'amount'=>'0.00','annual_fallback'=>false);
        if (!isset($this->archives[$year])) return $out; $oh=$this->archives[$year];
        $aliases=$category==='payroll'?array('payroll','payroll_versions','company_payroll'):array($category);
        foreach ($aliases as $alias) if (isset($oh['category_monthly'][$alias][$month])) { $out['exists']=true; $out['amount']=Cpms2ManagementPreflightSupport::add($out['amount'],$this->support->money($oh['category_monthly'][$alias][$month])); }
        if ($out['exists']) return $out;
        foreach ($aliases as $alias) if (isset($oh['categories'][$alias])) { $out['exists']=true; $out['annual_fallback']=true; $out['amount']=Cpms2ManagementPreflightSupport::add($out['amount'],$this->support->money($oh['categories'][$alias])); }
        return $out;
    }
    private function rowStats($category,$rows)
    {
        $r=array('active'=>0,'deleted'=>0,'amount'=>'0.00');
        if ($category==='lease') $r+=array('contract_groups'=>0,'excel_import_keys'=>0,'rent_amount'=>'0.00','maintenance_fee'=>'0.00','deposit'=>'0.00','deposit_in_recognized_amount'=>false);
        if ($category==='corporate_cards') $r+=array('positive_count'=>0,'positive_amount'=>'0.00','negative_count'=>0,'negative_amount'=>'0.00','zero_count'=>0,'invalid_business_numbers'=>0,'last4_present'=>0);
        if ($category==='fuel') $r+=array('supply'=>'0.00','vat'=>'0.00','total'=>'0.00','company_vehicle_mapping_success'=>0,'company_vehicle_mapping_failed'=>0,'employee_mapping_success'=>0,'employee_mapping_failed'=>0,'manual_mapping_success'=>0,'manual_mapping_failed'=>0);
        if ($category==='etc') $r['attachment_metadata_rows']=0;
        $groups=array(); $keys=array();
        foreach ($rows as $row) {
            if (!is_array($row)) { $this->support->issue('Overhead','SOURCE_ROW_SCHEMA_INVALID','BLOCKING'); continue; }
            if (!empty($row['deleted_at'])) { $r['deleted']++; continue; } $r['active']++;
            $a=$this->amount($row,$category); $r['amount']=Cpms2ManagementPreflightSupport::add($r['amount'],$a);
            if ($category==='lease') {
                foreach (array('rent_amount'=>'amount','maintenance_fee'=>'maintenance_fee','deposit'=>'deposit') as $out=>$key) $r[$out]=Cpms2ManagementPreflightSupport::add($r[$out],$this->support->money(isset($row[$key])?$row[$key]:0));
                if (!empty($row['lease_group_id'])) $groups[(string)$row['lease_group_id']]=true;
                if (!empty($row['lease_import_key'])) $keys[(string)$row['lease_import_key']]=true;
                if (isset($row['id']) && strpos((string)$row['id'],'LEASE-IMPORT')===0) $keys[(string)$row['id']]=true;
            }
            if ($category==='corporate_cards') {
                $kind=$a==='0.00'?'zero':($a[0]==='-'?'negative':'positive'); $r[$kind.'_count']++;
                if ($kind!=='zero') $r[$kind.'_amount']=Cpms2ManagementPreflightSupport::add($r[$kind.'_amount'],$a);
                $biz=isset($row['vendor_business_number'])?$row['vendor_business_number']:(isset($row['business_number'])?$row['business_number']:(isset($row['biz_no'])?$row['biz_no']:''));
                if (trim((string)$biz)!=='' && strlen(preg_replace('/\D/','',(string)$biz))!==10) $r['invalid_business_numbers']++;
                if (!empty($row['card_last4']) || (!empty($row['card_number']) && strlen(preg_replace('/\D/','',(string)$row['card_number']))>=4)) $r['last4_present']++;
            }
            if ($category==='fuel') {
                foreach (array('supply','vat','total') as $key) {
                    $v=isset($row[$key])?$row[$key]:(isset($row[$key.'_amount'])?$row[$key.'_amount']:($key==='total'?(isset($row['amount'])?$row['amount']:0):0));
                    $r[$key]=Cpms2ManagementPreflightSupport::add($r[$key],$this->support->money($v));
                }
                $num=$this->vehicleNumber(isset($row['vehicle_number_normalized'])?$row['vehicle_number_normalized']:(isset($row['vehicle_number'])?$row['vehicle_number']:'')); if ($num!=='') $this->fuelNumbers[$num]=true;
                $vehicle=false; foreach ($this->vehicles as $v) if ((isset($row['matched_company_vehicle_id'],$v['id']) && (string)$row['matched_company_vehicle_id']===(string)$v['id']) || ($num!=='' && isset($v['vehicle_number']) && $num===$this->vehicleNumber($v['vehicle_number']))) { $vehicle=true; break; }
                $r[$vehicle?'company_vehicle_mapping_success':'company_vehicle_mapping_failed']++;
                $emp=isset($row['matched_employee_id'])?(int)$row['matched_employee_id']:0; $r[$emp && isset($this->employees[$emp])?'employee_mapping_success':'employee_mapping_failed']++;
                $r[$num!=='' && isset($this->matches[$num]) && !empty($this->matches[$num]['display_name'])?'manual_mapping_success':'manual_mapping_failed']++;
            }
            if ($category==='etc' && (!empty($row['attachments']) || !empty($row['drive_file_id']) || !empty($row['attachment']) || !empty($row['original_name']) || !empty($row['stored_name']))) $r['attachment_metadata_rows']++;
        }
        if ($category==='lease') { $r['contract_groups']=count($groups); $r['excel_import_keys']=count($keys); }
        return $r;
    }
    public function inspect()
    {
        if ($this->inspection!==null) return $this->inspection;
        $this->exportRows=array('overhead_entries'=>array(),'overhead_vehicle_details'=>array(),'overhead_lease_details'=>array(),'overhead_card_details'=>array(),'overhead_fuel_details'=>array());
        $out=array('cutoff_month'=>$this->cutoff,'roots'=>array(),'categories'=>array(),'months'=>array(),'archive'=>array(),'grand_total'=>'0.00','total_is_final'=>true);
        foreach ($this->roots as $l=>$root) $out['roots'][$l]=$this->inventory($root);
        $this->employees(); $out['vehicles']=$this->vehicleData(); $this->archives();
        foreach (array('payroll','vehicles','lease','corporate_cards','fuel','etc') as $c) $this->files[$c]=$this->months($c);
        // Inspect permission metadata only. Never invoke the legacy resolver that can mkdir.
        $payrollRoot=is_dir($this->roots['data']) && is_writable($this->roots['data'])?'data':(is_dir($this->roots['storage'])?'storage':'data');
        $out['payroll']=$this->payroll($payrollRoot);
        $matchPaths=array(); foreach ($this->roots as $l=>$root) if (is_file($root.'/fuel_vehicle_matches/matches.json')) $matchPaths[$l]=$root.'/fuel_vehicle_matches/matches.json';
        $match=$this->select('fuel_vehicle_matches','MASTER',$matchPaths,true); $matchData=$match['data'];
        $this->matches=is_array($matchData) && isset($matchData['matches']) && is_array($matchData['matches'])?$matchData['matches']:array();
        $out['fuel_manual_mapping']=array('exists'=>count($matchPaths)>0,'count'=>count($this->matches),'selected_root'=>$match['selected_root'],'history_files'=>0,'log_files'=>0);
        foreach ($out['roots'] as $r) { $out['fuel_manual_mapping']['history_files']+=$r['fuel_history_files']; $out['fuel_manual_mapping']['log_files']+=$r['fuel_log_files']; }
        $out['archive_index']=$this->archiveIndex;
        foreach ($this->archiveIndex as $year=>$index) if ($index['verified_references'] && !isset($this->archives[$year])) { $this->support->issue('Overhead','ARCHIVE_SUMMARY_UNAVAILABLE','BLOCKING'); $out['total_is_final']=false; }
        $all=array(); foreach ($this->files as $fileSet) foreach ($fileSet as $ym=>$paths) $all[$ym]=true;
        foreach ($this->versions as $ym=>$v) $all[$ym]=true; foreach ($this->manual as $ym=>$a) $all[$ym]=true;
        foreach ($this->archives as $year=>$d) $all[$year.'-01']=true;
        if ($this->vehicles) $all['2026-01']=true;
        ksort($all); $start=count($all)?key($all):$this->cutoff; $month=$start; $guard=0;
        foreach ($this->files as $c=>$set) $out['categories'][$c]=array('logical_months'=>0,'first_month'=>null,'last_month'=>null,'record_count'=>0,'deleted_records'=>0,'parse_failure_months'=>0,'recognized_amount'=>'0.00','selected_roots'=>array());
        $out['future_source_months']=count(array_filter(array_keys($all),function($ym) { return $ym>$this->cutoff; }));
        while ($month<=$this->cutoff && $guard++<2400) {
            foreach ($this->files as $c=>$set) {
                $paths=isset($set[$month])?$set[$month]:array(); $selected=$this->select($c,$month,$paths);
                $rows=Cpms2ManagementPreflightSupport::items($selected['data']); $stats=$this->rowStats($c,$rows);
                if (is_array($selected['data']) && count($selected['data']) && !$rows && !isset($selected['data']['items'])) $this->support->issue('Overhead','SOURCE_MONTH_SCHEMA_UNKNOWN','BLOCKING');
                if ($paths && $selected['data']===null) $out['categories'][$c]['parse_failure_months']++;
                $amount=$stats['amount']; $basis=$stats['active']?'MONTH_ROWS':'ZERO'; $records=$stats['active']; $extra='0.00';
                if ($c==='vehicles') { $vehicle=$this->vehicleMonth($month); $extra=$vehicle['amount']; $amount=Cpms2ManagementPreflightSupport::add($amount,$extra); $records+=$vehicle['count']; if ($vehicle['count']) $basis='MONTH_ROWS_AND_VEHICLE_PAYMENTS'; }
                $archive=$this->archiveAmount($month,$c);
                if ($c==='payroll' && isset($this->manual[$month])) { $amount=$this->manual[$month]; $basis='MANUAL_TOTAL'; }
                elseif ($archive['exists']) {
                    $amount=$archive['amount']; $basis='ARCHIVE'; if ($c==='payroll') $out['payroll']['archive_months']++;
                    // Metadata proves an archive exists; it does not prove normalized details are available.
                    $detail=$records>0 && $stats['amount']===$amount;
                    $out['archive'][]=array('month'=>$month,'category'=>$c,'summary_exists'=>true,'local_json_exists'=>count($paths)>0,'local_detail_matches_summary'=>$detail,'index'=>isset($this->archiveIndex[substr($month,0,4)])?$this->archiveIndex[substr($month,0,4)]:array(),'detail_status'=>$detail?'LOCAL_DETAIL_AMOUNT_MATCH_ONLY':'ARCHIVE_DETAIL_UNAVAILABLE','recognized_amount'=>$amount,'annual_fallback'=>$archive['annual_fallback']);
                    if (!$detail) { $this->support->issue('Overhead','ARCHIVE_DETAIL_UNAVAILABLE','BLOCKING'); $out['total_is_final']=false; }
                    if ($archive['annual_fallback']) { $this->support->issue('Overhead','ARCHIVE_ANNUAL_FALLBACK_REPEATED','BLOCKING'); $out['total_is_final']=false; }
                } elseif ($c==='payroll' && !$records) {
                    $v=null; foreach ($this->versions as $ym=>$one) if ($ym<=$month) $v=$one;
                    if ($v!==null) { $amount=$v['amount']; $records=$v['employee_rows']; $basis='PAYROLL_VERSION'; }
                }
                if (count($paths) || $basis!=='ZERO') {
                    if ($c==='payroll') $this->appendExportRow($c,$month,$amount,$basis,array(),0,$selected['selected_root'],'monthly_total',null);
                    else {
                        $this->appendLocalRows($c,$month,$rows,$basis,$selected['selected_root']);
                        if ($c==='vehicles' && $basis==='MONTH_ROWS_AND_VEHICLE_PAYMENTS') $this->appendVehiclePayments($month,$vehicle,$basis,$selected['selected_root']);
                    }
                    $cat=&$out['categories'][$c]; $cat['logical_months']++; if ($cat['first_month']===null) $cat['first_month']=$month; $cat['last_month']=$month;
                    $cat['record_count']+=$records; $cat['deleted_records']+=$stats['deleted']; $cat['recognized_amount']=Cpms2ManagementPreflightSupport::add($cat['recognized_amount'],$amount);
                    if ($selected['selected_root']!==null) $cat['selected_roots'][$selected['selected_root']]=true;
                    $out['months'][]=array('month'=>$month,'category'=>$c,'basis'=>$basis,'amount'=>$amount,'selected_root'=>$selected['selected_root'],'stats'=>$stats); unset($cat);
                }
                if ($c==='vehicles') { $out['vehicles']['auto_payment_amount']=Cpms2ManagementPreflightSupport::add($out['vehicles']['auto_payment_amount'],$extra); $out['vehicles']['monthly_rows_amount']=Cpms2ManagementPreflightSupport::add($out['vehicles']['monthly_rows_amount'],$stats['amount']); }
            }
            $month=$this->addMonths($month,1);
        }
        if ($guard>=2400) { $this->support->issue('Overhead','SOURCE_PERIOD_LIMIT','BLOCKING'); $out['total_is_final']=false; }
        foreach ($out['categories'] as &$c) { $c['selected_roots']=array_keys($c['selected_roots']); $out['grand_total']=Cpms2ManagementPreflightSupport::add($out['grand_total'],$c['recognized_amount']); } unset($c);
        $out['fuel_distinct_vehicle_numbers']=count($this->fuelNumbers); $out['duplicate_sources']=$this->duplicates;
        $out['export_reconciliation']=$this->reconciliation($out);
        $mismatch=$out['export_reconciliation']['grand_total']['difference']!=='0.00';
        foreach ($out['export_reconciliation']['months'] as $row) if ($row['difference']!=='0.00') $mismatch=true;
        foreach ($out['export_reconciliation']['categories'] as $row) if ($row['difference']!=='0.00') $mismatch=true;
        if ($mismatch) { $this->support->issue('Overhead','OVERHEAD_RECONCILIATION_FAILED','BLOCKING'); $out['total_is_final']=false; }
        $counts=array(); foreach ($this->exportRows as $entity=>$rows) $counts[$entity]=count($rows); $out['export_record_counts']=$counts;
        // No remote reads, archive reader invocation, PDF/Excel parsing or cache generation.
        $out['archive_access']='LOCAL_SUMMARY_AND_INDEX_ONLY; NO_DRIVE_OR_CACHE_WRITES';
        $this->inspection=$out; return $out;
    }
    public function assertSourceUnchanged()
    {
        foreach ($this->sourceHashes as $path=>$hash) if (!is_file($path) || @hash_file('sha256',$path)!==$hash) throw new RuntimeException('OVERHEAD_SOURCE_CHANGED');
        return true;
    }
    public function exportPlan()
    {
        $report=$this->inspect(); $blocking=0;
        foreach ($this->support->issues as $issue) if ($issue['domain']==='Overhead' && $issue['severity']==='BLOCKING') $blocking++;
        if ($blocking || empty($report['total_is_final'])) throw new RuntimeException('OVERHEAD_PREFLIGHT_BLOCKED');
        $this->assertSourceUnchanged();
        return array('report'=>$report,'rows'=>$this->exportRows,'issues'=>array_values($this->support->issues),'source_hash_count'=>count($this->sourceHashes));
    }
}
