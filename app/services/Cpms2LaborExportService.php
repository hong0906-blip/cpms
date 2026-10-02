<?php
// app/services/Cpms2LaborExportService.php
// Only the audited pure/SELECT helpers are called; ensure/sync/save helpers are never called.
require_once __DIR__.'/../views/construction/tabs/partials/labor_data_loader.php';
class Cpms2LaborExportService
{
    private $db;
    private $attendance;
    private $writer;
    private $gongsuSource;
    private $gongsuInfo;
    public function __construct($db,$attendance,$writer) { $this->db=$db; $this->attendance=$attendance; $this->writer=$writer; }
    public static function excludedForceAdjustments($db)
    {
        $report=array('count'=>0,'amount'=>'0.00','projects'=>array()); $total=0;
        $columns=$db->inspect('cpms_labor_force_adjustments');
        if (!$columns) return $report;
        if (count(array_diff(array('project_id','month','amount'),$columns))) throw new RuntimeException('Labor force adjustment summary columns missing.');
        // Aggregate only: no row IDs, memo, author or adjustment entity in the package.
        $st=$db->query('SELECT project_id,month,COUNT(*) AS excluded_count,SUM(amount) AS excluded_amount FROM cpms_labor_force_adjustments WHERE amount<>0 GROUP BY project_id,month ORDER BY project_id,month');
        $projectTotals=array();
        while ($r=$st->fetch(PDO::FETCH_ASSOC)) {
            $project=(int)$r['project_id']; $month=(string)$r['month']; $count=(int)$r['excluded_count']; $cents=Cpms2ExportPackageWriter::moneyCents($r['excluded_amount']);
            if (!isset($report['projects'][$project])) { $report['projects'][$project]=array('count'=>0,'amount'=>'0.00','months'=>array()); $projectTotals[$project]=0; }
            $report['projects'][$project]['months'][$month]=array('count'=>$count,'amount'=>Cpms2ExportPackageWriter::moneyDecimal($cents));
            $report['projects'][$project]['count']+=$count; $projectTotals[$project]+=$cents;
            $report['count']+=$count; $total+=$cents;
        }
        foreach ($projectTotals as $project=>$cents) $report['projects'][$project]['amount']=Cpms2ExportPackageWriter::moneyDecimal($cents);
        $report['amount']=Cpms2ExportPackageWriter::moneyDecimal($total);
        return $report;
    }
    public static function exclusionWarnings($report)
    {
        $warnings=array('노무비 강제입력 '.number_format($report['count']).'건 / 총 '.number_format((float)$report['amount'],2).'원은 이번 CPMS2 이관에서 제외됩니다.');
        foreach ($report['projects'] as $project=>$group) foreach ($group['months'] as $month=>$r) $warnings[]='노무비 강제입력 제외: 프로젝트 #'.$project.' / '.$month.' / '.number_format($r['count']).'건 / '.number_format((float)$r['amount'],2).'원';
        return $warnings;
    }
    public static function resolveOverrides($rows)
    {
        $result=array(); $applied=array();
        foreach ($rows as $r) {
            $key=$r['worker_key']; $date=$r['work_date']; $cell=$key.'|'.$date;
            if (in_array($r['status'],array('applied','approved'))) {
                $result[$key][$date]=array('value'=>!empty($r['is_deleted_entry'])?0:(float)$r['new_value'],'id'=>$r['id']); $applied[$cell]=true;
            } elseif (!isset($applied[$cell]) && isset($r['old_value']) && is_numeric($r['old_value'])) {
                $result[$key][$date]=array('value'=>max(0,(float)$r['old_value']),'id'=>$r['id']);
            }
        }
        return $result;
    }
    private function daily($project,$month)
    {
        $map=array();
        if ($this->gongsuInfo) {
            $cols=$this->gongsuInfo['columns']; $table=$this->gongsuInfo['table'];
            $site=cpms_resolve_gongsu_site_name($this->gongsuSource,$table,$cols['site'],$project['name']);
            $select=array(); foreach ($cols as $alias=>$field) $select[]='`'.$field.'` AS `'.$alias.'`';
            $st=$this->gongsuSource->query('SELECT '.implode(',',$select).' FROM `'.$table.'` WHERE `'.$cols['site'].'`=? AND `'.$cols['date'].'`>=? AND `'.$cols['date'].'`<? ORDER BY `'.$cols['date'].'`',array($site,$month.'-01',date('Y-m-d',strtotime($month.'-01 +1 month'))));
            while ($r=$st->fetch(PDO::FETCH_ASSOC)) {
                if (isset($r['printed']) && !cpms_is_printed_value($r['printed'])) continue;
                if (isset($r['role']) && cpms_is_excluded_equipment_driver_role($r['role'])) continue;
                $value=cpms_parse_gongsu_value($r['gongsu']); if ($value===null) continue;
                $map[cpms_normalize_worker_key($r['name'])][substr($r['date'],0,10)]=(float)$value;
            }
        } elseif ($this->attendance && count($this->attendance->columns('attendance'))) {
            $site=cpms_find_attendance_site_match($this->attendance,$project['name']);
            if ($site['id']>0) {
                $role=cpms_find_role_column($this->attendance->columns('attendance'));
                $sql='SELECT name,start_time_phone,stop_time_phone,total_minutes'.($role!==''?',`'.$role.'` AS role_value':'').' FROM attendance WHERE site_id=? AND status=? AND start_time_phone>=? AND start_time_phone<? ORDER BY start_time_phone,id';
                $st=$this->attendance->query($sql,array($site['id'],'done',$month.'-01 00:00:00',date('Y-m-d',strtotime($month.'-01 +1 month')).' 00:00:00'));
                while ($r=$st->fetch(PDO::FETCH_ASSOC)) {
                    if ($role!=='' && cpms_is_excluded_equipment_driver_role($r['role_value'])) continue;
                    $minutes=$r['total_minutes'];
                    if ($minutes===null || $minutes==='') $minutes=cpms_att_calc_total_minutes_fallback($r['start_time_phone'],$r['stop_time_phone']);
                    if ($minutes===null) continue;
                    $value=cpms_att_calc_gongsu($minutes,cpms_att_overtime_minutes_after_17($r['start_time_phone'],$r['stop_time_phone']));
                    $key=cpms_normalize_worker_key($r['name']); $date=substr($r['start_time_phone'],0,10);
                    if (!isset($map[$key][$date])) $map[$key][$date]=0;
                    $map[$key][$date]=round($map[$key][$date]+$value,2);
                }
            }
        }
        $base=$map; $overrides=array();
        if (count($this->db->columns('cpms_labor_gongsu_overrides'))) {
            $cols=array_intersect(explode(' ','id worker_key worker_name work_date status old_value new_value is_deleted_entry'),$this->db->columns('cpms_labor_gongsu_overrides'));
            $st=$this->db->query('SELECT `'.implode('`,`',$cols).'` FROM cpms_labor_gongsu_overrides WHERE project_id=? AND work_date>=? AND work_date<? ORDER BY id',array($project['id'],$month.'-01',date('Y-m-d',strtotime($month.'-01 +1 month'))));
            $overrides=self::resolveOverrides($st->fetchAll(PDO::FETCH_ASSOC));
            foreach ($overrides as $key=>$dates) foreach ($dates as $date=>$r) $map[$key][$date]=$r['value'];
        }
        return array('base'=>$base,'final'=>$map,'overrides'=>$overrides);
    }
    private function assignments($project,$month)
    {
        $fields=explode(' ','id project_id worker_id direct_member_id vendor_id biz_no business_no name worker_name_snapshot phone daily_wage_snapshot deposit_rate daily_wage company_name agency_name_snapshot job_type_snapshot legacy_outsourcing_ratio is_outsourcing source_type matched_status');
        $result=array();
        foreach ($this->db->rows('cpms_project_labor_workers',$fields,'project_id=?',array($project)) as $r) {
            if (count($this->db->columns('cpms_project_labor_worker_months'))) {
                $cols=array_intersect(explode(' ','outsourcing_ratio outsourcing_ratio_is_set outsourcing_start_date outsourcing_end_date'),$this->db->columns('cpms_project_labor_worker_months'));
                $st=$this->db->query('SELECT '.(count($cols)?'`'.implode('`,`',$cols).'`':'labor_worker_id').' FROM cpms_project_labor_worker_months WHERE project_id=? AND labor_worker_id=? AND month=? AND is_deleted=0',array($project,$r['id'],$month));
                $settings=$st->fetch(PDO::FETCH_ASSOC); if (!$settings) continue;
                if (!empty($settings['outsourcing_ratio_is_set'])) $r=array_merge($r,$settings);
            }
            if (count($this->db->columns('cpms_project_labor_worker_wages'))) {
                $w=$this->db->query('SELECT daily_wage FROM cpms_project_labor_worker_wages WHERE project_id=? AND labor_worker_id=? AND effective_month<=? ORDER BY effective_month DESC,id DESC LIMIT 1',array($project,$r['id'],$month))->fetchColumn();
                if ($w!==false) { $r['daily_wage_snapshot']=$w; $r['deposit_rate']=$w; }
            }
            if (!empty($r['daily_wage_snapshot'])) { $r['deposit_rate']=$r['daily_wage_snapshot']; $r['daily_wage']=$r['daily_wage_snapshot']; }
            $r['name']=!empty($r['worker_name_snapshot'])?$r['worker_name_snapshot']:$r['name'];
            $result[]=$r;
        }
        return $result;
    }
    public function run()
    {
        $this->writer->excludedLaborForce=self::excludedForceAdjustments($this->db);
        $this->writer->warnings=array_merge($this->writer->warnings,self::exclusionWarnings($this->writer->excludedLaborForce));
        foreach (array('cpms_project_labor_workers','cpms_project_labor_worker_months','cpms_project_labor_worker_wages','cpms_labor_gongsu_overrides') as $table) $this->db->inspect($table);
        if (!count($this->db->columns('cpms_project_labor_workers'))) { $this->writer->warnings[]='Labor assignments unavailable.'; return; }
        $this->gongsuSource=$this->db; $this->gongsuInfo=cpms_find_gongsu_table($this->db);
        if (!$this->gongsuInfo && $this->attendance) { $this->gongsuSource=$this->attendance; $this->gongsuInfo=cpms_find_gongsu_table($this->attendance); }
        if ($this->gongsuInfo) $this->gongsuSource->inspect($this->gongsuInfo['table']);
        if ($this->attendance) { $this->attendance->inspect('attendance'); $this->attendance->inspect('sites'); }
        elseif (!$this->gongsuInfo) throw new RuntimeException('Attendance source unavailable; cannot silently export incomplete labor.');
        $months=array();
        foreach (array('cpms_project_labor_worker_months'=>'month','cpms_project_labor_worker_wages'=>'effective_month','cpms_labor_gongsu_overrides'=>'work_date') as $table=>$field) if (in_array($field,$this->db->columns($table))) {
            $st=$this->db->query('SELECT DISTINCT LEFT(`'.$field.'`,7) FROM '.$table); while ($value=$st->fetchColumn()) if (preg_match('/^\d{4}-\d{2}$/',$value)) $months[$value]=true;
        }
        if ($this->gongsuInfo) { $info=$this->gongsuInfo; $st=$this->gongsuSource->query('SELECT DISTINCT LEFT(`'.$info['columns']['date'].'`,7) FROM `'.$info['table'].'`'); }
        elseif ($this->attendance) $st=$this->attendance->query('SELECT DISTINCT LEFT(start_time_phone,7) FROM attendance');
        if (isset($st)) while ($value=$st->fetchColumn()) if (preg_match('/^\d{4}-\d{2}$/',$value)) $months[$value]=true;
        ksort($months);
        $vendorCounts=array('legacy_vendor_id'=>0,'unique_business_identity'=>0,'snapshot_only'=>0,'ambiguous'=>0);
        foreach ($this->db->rows('cpms_project_labor_workers',explode(' ','id project_id worker_id direct_member_id name worker_name_snapshot phone daily_wage_snapshot deposit_rate company_name agency_name_snapshot job_type_snapshot source_type matched_status')) as $r) {
            $snapshot=array_intersect_key($r,array_flip(explode(' ','worker_name_snapshot daily_wage_snapshot deposit_rate company_name agency_name_snapshot job_type_snapshot source_type matched_status')));
            if ($this->db instanceof Cpms2ReferencedMasterClosure) $snapshot['legacy_snapshot_only']=$this->db->snapshotOnly($r);
            $this->writer->record('labor_workers',array_merge(array('legacy_id'=>$r['id'],'legacy_project_id'=>$r['project_id'],'legacy_worker_master_id'=>!empty($r['worker_id'])?$r['worker_id']:null,'legacy_direct_member_id'=>!empty($r['direct_member_id'])?$r['direct_member_id']:null,'name'=>isset($r['name'])?$r['name']:null,'phone'=>isset($r['phone'])?$r['phone']:null),$snapshot));
        }
        $direct=array(); foreach ($this->db->rows('direct_team_members',array('id','name','monthly_salary','daily_wage')) as $r) $direct[$r['id']]=$r;
        $projects=array(); foreach ($this->db->rows('cpms_projects',array('id','name')) as $p) $projects[]=$p;
        foreach ($months as $month=>$unused) {
            // Bounded by one month, not all historical attendance data.
            $datasets=array(); $assignments=array(); $salaryDays=array();
            foreach ($projects as $p) {
                $datasets[$p['id']]=$this->daily($p,$month); $assignments[$p['id']]=$this->assignments($p['id'],$month);
                foreach ($assignments[$p['id']] as $r) if (!empty($r['direct_member_id']) && isset($direct[$r['direct_member_id']]) && (float)$direct[$r['direct_member_id']]['monthly_salary']>0) {
                    $key=cpms_normalize_worker_key($r['name']); $days=isset($datasets[$p['id']]['final'][$key])?count(array_filter($datasets[$p['id']]['final'][$key],function($v){return $v>0;})):0;
                    if (!isset($salaryDays[$r['direct_member_id']])) $salaryDays[$r['direct_member_id']]=0;
                    $salaryDays[$r['direct_member_id']]+=$days;
                }
            }
            foreach ($projects as $p) foreach ($assignments[$p['id']] as $r) {
                $id=$r['id']; $directId=!empty($r['direct_member_id'])?$r['direct_member_id']:null;
                $key=cpms_normalize_worker_key($r['name']); $dataset=$datasets[$p['id']]; $daily=isset($dataset['final'][$key])?$dataset['final'][$key]:array(); ksort($daily);
                if ($directId && isset($direct[$directId]) && (float)$direct[$directId]['monthly_salary']>0) {
                    $r['salary_allocation_mode']=1; $r['salary_daily_rate']=!empty($salaryDays[$directId])?$direct[$directId]['monthly_salary']/$salaryDays[$directId]:0; $r['outsourcing_ratio']=0;
                }
                $amounts=cpms_labor_calculate_worker_period_amounts($r,$daily,$month.'-01',date('Y-m-t',strtotime($month.'-01')));
                $ratio=cpms_resolve_worker_outsourcing_ratio($r); $rate=cpms_resolve_labor_wage_rate($r);
                $vendor=$this->db instanceof Cpms2ReferencedMasterClosure?$this->db->vendorSnapshot($r):array();
                if ($ratio>0) {
                    $resolution=isset($vendor['legacy_vendor_resolution'])?$vendor['legacy_vendor_resolution']:'snapshot_only'; $vendorCounts[$resolution]++;
                    if (!empty($vendor['legacy_vendor_ambiguous'])) $vendorCounts['ambiguous']++;
                }
                $this->writer->record('labor_months',array_merge(array('legacy_id'=>$id.':'.$month,'legacy_project_id'=>$p['id'],'legacy_labor_worker_id'=>$id,'target_month'=>$month.'-01','pay_type'=>!empty($r['salary_allocation_mode'])?'monthly':'unit','wage_rate'=>sprintf('%.2f',!empty($r['salary_allocation_mode'])?$direct[$directId]['monthly_salary']:$rate),'source_daily_rate'=>sprintf('%.8f',$rate),'labor_ratio'=>100-$ratio,'labor_outsourcing_ratio'=>$ratio,'vendor_name'=>!empty($r['agency_name_snapshot'])?$r['agency_name_snapshot']:(isset($r['company_name'])?$r['company_name']:''),'source_labor_amount'=>sprintf('%.2f',$amounts['labor_amount']),'source_outsourcing_amount'=>sprintf('%.2f',$amounts['outsourcing_amount'])),$vendor));
                // Preserve CPMS1 month rounding; allocate its rounded totals without inventing money.
                $weights=array(); $outWeights=array();
                foreach ($daily as $date=>$gongsu) { $units=!empty($r['salary_allocation_mode'])?($gongsu>0?1:0):max(0,$gongsu); $weights[$date]=$units; $inRange=empty($r['outsourcing_start_date']) || empty($r['outsourcing_end_date']) || ($date>=$r['outsourcing_start_date'] && $date<=$r['outsourcing_end_date']); $outWeights[$date]=$inRange?$units:0; }
                $gross=self::allocate((int)$amounts['total_amount'],$weights); $out=self::allocate((int)$amounts['outsourcing_amount'],$outWeights);
                foreach ($daily as $date=>$value) {
                    $base=isset($dataset['base'][$key][$date])?$dataset['base'][$key][$date]:0;
                    $override=isset($dataset['overrides'][$key][$date])?$dataset['overrides'][$key][$date]:null;
                    $this->writer->record('labor_entries',array('legacy_id'=>$id.':'.$date,'legacy_project_id'=>$p['id'],'legacy_labor_worker_id'=>$id,'legacy_worker_master_id'=>!empty($r['worker_id'])?$r['worker_id']:null,'work_date'=>$date,'source_type'=>$override?'approval':'attendance','source_gongsu'=>sprintf('%.4f',$base),'final_gongsu'=>sprintf('%.4f',$value),'source_reference'=>$override?'override:'.$override['id']:null,'labor_amount'=>sprintf('%.2f',$gross[$date]-$out[$date]),'labor_outsourcing_amount'=>sprintf('%.2f',$out[$date])));
                }
                $this->writer->amount($p['id'],'labor',$amounts['labor_amount']); $this->writer->amount($p['id'],'subcontract',$amounts['outsourcing_amount']);
            }
        }
        if ($this->writer instanceof Cpms2ExportPackageWriter) $this->writer->referenceClosure['labor_vendor']=$vendorCounts;
    }
    public static function allocate($total,$weights)
    {
        $result=array(); $sum=array_sum($weights); $assigned=0; $remainders=array();
        foreach ($weights as $key=>$weight) { $exact=$sum>0?$total*$weight/$sum:0; $result[$key]=(int)floor($exact); $assigned+=$result[$key]; $remainders[$key]=$exact-$result[$key]; }
        arsort($remainders,SORT_NUMERIC); foreach ($remainders as $key=>$unused) if ($assigned<$total) { $result[$key]++; $assigned++; }
        return $result;
    }
}
