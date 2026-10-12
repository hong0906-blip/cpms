<?php
// app/services/Cpms2CostReconciliationExportService.php
// PHP 5.6. SELECT-only screen-calculation export; no bootstrap/ensure/sync/file attachment operations.
require_once __DIR__.'/Cpms2MigrationExportService.php';
require_once __DIR__.'/CostChangeService.php';
require_once __DIR__.'/Cpms2MigrationDecimal.php';
require_once __DIR__.'/../views/safety/safety_cost_helper.php';

class Cpms2CanonicalLaborCollector
{
    public $warnings=array(); public $excludedLaborForce=array(); public $rows=array();
    public function record($entity,$row) { $this->rows[$entity][]=$row; }
    public function amount($project,$category,$amount) {}
}

class Cpms2CostReconciliationExportService
{
    private $db; private $attendance; private $storage; private $rows=array(); private $projects=array(); private $through;
    public static $categories=array('labor','labor_force','subcontract','equipment','material','purchase','expense','safety','deduction');
    public function __construct($db,$attendance,$storage) { $this->db=$db; $this->attendance=$attendance; $this->storage=$storage; }
    public static function throughMonth($month)
    {
        if (!is_string($month) || !preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])$/D',$month) || $month>'2026-09') throw new RuntimeException('Canonical export is limited to a valid through-month no later than 2026-09.');
        return $month;
    }
    private function money($value) { return Cpms2MigrationDecimal::normalize($value); }
    private function add($project,$month,$category,$entity,$identity,$date,$amount,$detail=array())
    {
        if ($month>$this->through) return;
        if (!isset($this->projects[$project])) throw new RuntimeException('Canonical cost references a missing project.');
        $p=$this->projects[$project];
        if (empty($p['canonical_eligible'])) return;
        if (strpos($p['name'],'(가제)')===0 || $month<$p['first_month'] || $month>$p['last_month']) return;
        if (!preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])$/D',$month)) throw new RuntimeException('Canonical settlement month is invalid.');
        $this->rows[]=array_merge($detail,array('legacy_project_id'=>(int)$project,'project_name'=>$this->projects[$project]['name'],'settlement_month'=>$month,'category'=>$category,'source_entity'=>$entity,'source_legacy_id'=>(string)$identity,'source_date'=>$date,'effective_settlement_month'=>$month,'canonical_amount'=>$this->money($amount)));
    }
    private function bucket($type,$id,$date)
    {
        if (\App\Services\CostChangeService::isTargetDeleted($this->db,$type,(string)$id)) return null;
        $month=\App\Services\CostChangeService::effectiveSettlementYm($this->db,$type,(string)$id,$type,(string)$date);
        return $month!=='' && $month<=$this->through?$month:null;
    }
    public function collect($throughMonth)
    {
        $this->through=self::throughMonth($throughMonth);$this->rows=array();$this->projects=array();
        foreach ($this->db->rows('cpms_projects',array('id','name','start_date','end_date')) as $p) {
            // The monthly input screen uses the contract range, not the date range of raw cost rows.
            $first=isset($p['start_date'])?substr($p['start_date'],0,7):'';
            $last=isset($p['end_date'])?substr($p['end_date'],0,7):'';
            if (!preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])$/D',$first) || !preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])$/D',$last) || $first>$last) {
                // The screen falls back to today's month. Historical truth is unavailable;
                // retain the project as an explicit CONFLICT, never invent a date range.
                $p['canonical_eligible']=false;$p['canonical_reason']='CONTRACT_PERIOD_UNAVAILABLE';$this->projects[$p['id']]=$p;continue;
            }
            $p['canonical_eligible']=true;$p['first_month']=$first;$p['last_month']=min($last,$this->through);$this->projects[$p['id']]=$p;
        }
        if (!$this->projects) throw new RuntimeException('Canonical projects unavailable.');
        $collector=new Cpms2CanonicalLaborCollector();(new Cpms2LaborExportService($this->db,$this->attendance,$collector))->run($this->through);
        $workers=array(); foreach (isset($collector->rows['labor_workers'])?$collector->rows['labor_workers']:array() as $r) $workers[$r['legacy_id']]=$r;
        $months=array(); foreach (isset($collector->rows['labor_months'])?$collector->rows['labor_months']:array() as $r) $months[$r['legacy_id']]=$r;
        foreach (isset($collector->rows['labor_entries'])?$collector->rows['labor_entries']:array() as $r) {
            $month=substr($r['work_date'],0,7);if($month>$this->through)continue;
            $worker=$workers[$r['legacy_labor_worker_id']];$settings=$months[$r['legacy_labor_worker_id'].':'.$month];
            $details=array('legacy_labor_worker_id'=>$r['legacy_labor_worker_id'],'worker_name'=>isset($worker['worker_name_snapshot']) && $worker['worker_name_snapshot']!==''?$worker['worker_name_snapshot']:(isset($worker['name'])?$worker['name']:''),'work_date'=>$r['work_date'],'source_type'=>$r['source_type'],'source_reference'=>$r['source_reference'],'gongsu'=>$r['final_gongsu'],'source_gongsu'=>$r['source_gongsu'],'wage_rate'=>$settings['wage_rate'],'pay_type'=>$settings['pay_type'],'labor_ratio'=>$settings['labor_ratio'],'labor_outsourcing_ratio'=>$settings['labor_outsourcing_ratio'],'vendor_name'=>$settings['vendor_name'],'labor_amount'=>$r['labor_amount'],'labor_outsourcing_amount'=>$r['labor_outsourcing_amount'],'calculation_basis'=>'screen_labor_period_amounts','raw_amount'=>$r['labor_amount']);
            $this->add($r['legacy_project_id'],$month,'labor','labor_entries',$r['legacy_id'],$r['work_date'],$r['labor_amount'],$details);
            if ($this->money($r['labor_outsourcing_amount'])!=='0.00') $this->add($r['legacy_project_id'],$month,'subcontract','labor_derived_outsourcing',$r['legacy_id'],$r['work_date'],$r['labor_outsourcing_amount'],$details);
        }
        foreach ($this->db->rows('cpms_labor_force_adjustments',array('id','project_id','month','amount'),'month<=?',array($this->through)) as $r) {
            // Both CPMS1 monthly screens explicitly include only positive force amounts.
            $amount=(float)$r['amount']>0?$r['amount']:'0.00';
            $this->add($r['project_id'],$r['month'],'labor_force','cpms_labor_force_adjustments',$r['id'],$r['month'].'-01',$amount,array('raw_amount'=>$this->money($r['amount']),'calculation_basis'=>'screen_positive_force_amount'));
        }
        foreach (array('material','equipment') as $kind) {
            $masters=array();foreach($this->db->rows('cpms_'.$kind.'_items',array('id','project_id','category','vendor_id','vendor_name','biz_no','item_name','spec','base_rate','remark')) as $item)$masters[$item['id']]=$item;
            foreach($this->db->rows('cpms_'.$kind.'_usage',array('id','project_id',$kind.'_id','use_date','amount','work_unit','base_rate_snapshot','is_manual_unit','memo')) as $r){
                $month=$this->bucket($kind,$r['id'],$r['use_date']);if($month===null)continue;
                if(!isset($masters[$r[$kind.'_id']]))continue; // Same active-master INNER JOIN as the screen.
                $m=$masters[$r[$kind.'_id']];if((string)$m['project_id']!==(string)$r['project_id'])throw new RuntimeException('Canonical usage project mismatch.');
                $category=$kind==='equipment'?'equipment':(isset($m['category']) && $m['category']==='구매품'?'purchase':(isset($m['category']) && $m['category']==='기타경비'?'expense':'material'));
                if($kind==='material' && isset($m['category']) && $m['category']==='안전관리비')continue; // Screen uses the safety store, never counts mirrored DB rows again.
                $detail=array('vendor_id'=>isset($m['vendor_id'])?$m['vendor_id']:null,'vendor_name'=>isset($m['vendor_name'])?$m['vendor_name']:null,'business_no'=>isset($m['biz_no'])?$m['biz_no']:null,'description'=>isset($m['remark'])?$m['remark']:null,'raw_amount'=>$this->money($r['amount']),'calculation_basis'=>'screen_stored_amount');$amount=$r['amount'];
                if($kind==='equipment'){
                    $calc=Cpms2CostCalculationSupport::equipment($r,$m);
                    if($calc['calculation_basis']==='work_unit_x_rate' && abs((float)$calc['quantity']*(float)$calc['rate']-(float)$calc['canonical_amount'])>0.0000001)throw new RuntimeException('Canonical equipment sub-cent rounding needs screen verification: source #'.(int)$r['id']);
                    $amount=$calc['canonical_amount'];$detail=array_merge($detail,$calc,array('work_unit'=>isset($r['work_unit'])?$r['work_unit']:null,'base_rate_snapshot'=>isset($r['base_rate_snapshot'])?$r['base_rate_snapshot']:null,'master_base_rate'=>isset($m['base_rate'])?$m['base_rate']:null,'is_manual_unit'=>isset($r['is_manual_unit'])?$r['is_manual_unit']:null));
                }
                $this->add($r['project_id'],$month,$category,'cpms_'.$kind.'_usage',$r['id'],$r['use_date'],$amount,$detail);
            }
        }
        foreach($this->db->rows('cpms_outsourcing_costs',array('id','project_id','expense_date','amount','vendor_id','company_name','business_no','memo')) as $r){
            $month=$this->bucket('outsourcing',$r['id'],$r['expense_date']);if($month===null)continue;
            // monthly_input hides nonpositive vendor-group totals. Without the whole screen
            // contract range (including excluded future months), a negative group is not safe to infer.
            if (Cpms2ExportPackageWriter::moneyCents($r['amount'])<0) throw new RuntimeException('Canonical manual outsourcing needs screen group verification: source #'.(int)$r['id']);
            $this->add($r['project_id'],$month,'subcontract','cpms_outsourcing_costs',$r['id'],$r['expense_date'],$r['amount'],array('raw_amount'=>$this->money($r['amount']),'vendor_id'=>isset($r['vendor_id'])?$r['vendor_id']:null,'vendor_name'=>isset($r['company_name'])?$r['company_name']:null,'business_no'=>isset($r['business_no'])?$r['business_no']:null,'calculation_basis'=>'screen_manual_outsourcing'));
        }
        foreach($this->db->rows('cpms_project_monthly_deductions',array('id','project_id','ym','deduction_name','amount'),'ym<=?',array($this->through)) as $r)$this->add($r['project_id'],$r['ym'],'deduction','cpms_project_monthly_deductions',$r['id'],$r['ym'].'-01',$r['amount'],array('description'=>$r['deduction_name'],'raw_amount'=>$this->money($r['amount']),'calculation_basis'=>'screen_signed_deduction'));
        $path=$this->storage.'/safety_costs/usage.json';$safetyHash=null;
        if(is_file($path)){
            if(!is_readable($path) || is_link($path) || filesize($path)>33554432)throw new RuntimeException('Canonical safety store unavailable or too large.');
            $bytes=file_get_contents($path);$safetyHash=hash('sha256',$bytes);$store=json_decode($bytes,true);
            if(!is_array($store) || !isset($store['items']) || !is_array($store['items']))throw new RuntimeException('Canonical safety store invalid.');
            foreach($store['items'] as $r){if(!is_array($r) || !cpms_safety_cost_is_active($r))continue;$month=$this->bucket('safety',$r['id'],$r['use_date']);if($month===null)continue;
                $this->add($r['project_id'],$month,'safety','safety_json',$r['id'],$r['use_date'],cpms_safety_cost_row_amount($r),array('vendor_name'=>isset($r['vendor_name'])?$r['vendor_name']:null,'business_no'=>isset($r['biz_no'])?$r['biz_no']:null,'description'=>isset($r['item_name'])?$r['item_name']:null,'raw_amount'=>$this->money(cpms_safety_cost_row_amount($r)),'calculation_basis'=>'cpms_safety_cost_row_amount'));
            }
            if(hash_file('sha256',$path)!==$safetyHash)throw new RuntimeException('Safety source changed during export.');
        }
        $summary=array();$company=array_fill_keys(self::$categories,'0.00');$seen=array();
        foreach($this->projects as $pid=>$p) if(!empty($p['canonical_eligible']) && strpos($p['name'],'(가제)')!==0 && $p['first_month']<=$p['last_month']) {
            for($cursor=new DateTime($p['first_month'].'-01');$cursor->format('Y-m')<=$p['last_month'];$cursor->modify('+1 month'))$summary[$pid][$cursor->format('Y-m')]=array_fill_keys(self::$categories,'0.00');
        }
        foreach($this->rows as $r){$identity=$r['legacy_project_id'].'|'.$r['category'].'|'.$r['source_entity'].'|'.$r['source_legacy_id'];if(isset($seen[$identity]))throw new RuntimeException('Duplicate canonical identity.');$seen[$identity]=true;
            $p=$r['legacy_project_id'];$month=$r['settlement_month'];if(!isset($summary[$p][$month]))$summary[$p][$month]=array_fill_keys(self::$categories,'0.00');
            $amount=$this->money($r['canonical_amount']);$summary[$p][$month][$r['category']]=Cpms2MigrationDecimal::add($summary[$p][$month][$r['category']],$amount);$company[$r['category']]=Cpms2MigrationDecimal::add($company[$r['category']],$amount);
        }
        foreach($summary as &$months)foreach($months as &$amounts)$amounts['monthly_total']=array_reduce($amounts,array('Cpms2MigrationDecimal','add'),'0.00');unset($amounts,$months);
        $company['monthly_total']=array_reduce($company,array('Cpms2MigrationDecimal','add'),'0.00');
        return array('source_system'=>'cpms1','version'=>1,'canonical_basis'=>'monthly_input_final_including_deductions','through_month'=>$this->through,'generated_at'=>date('Y-m-d H:i:s'),'categories'=>self::$categories,'projects'=>array_values($this->projects),'rows'=>$this->rows,'summary'=>array('projects'=>$summary,'company'=>$company),'diagnostics'=>array('monthly_summary_subtotal_excludes_deduction'=>true),'safety_source_sha256'=>$safetyHash,'category_mapping'=>array('구매품'=>'purchase','자재비'=>'material','기타경비'=>'expense','안전관리비'=>'safety','노무성 외주'=>'subcontract','수동 외주'=>'subcontract'));
    }
}
