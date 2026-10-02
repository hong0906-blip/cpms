<?php
// app/services/Cpms2HistoricalProjectRecoveryService.php
// PHP 5.6: recover only observed project snapshots; never load write-capable helpers.
class Cpms2HistoricalProjectRecoveryService
{
    private $source;
    public function __construct($source) { $this->source=$source; }
    private static function text($value) { return trim(preg_replace('/\s+/u',' ',trim((string)$value))); }
    private static function date($value)
    {
        $value=(string)$value;
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$value,$m) && checkdate((int)$m[2],(int)$m[3],(int)$m[1])?$value:null;
    }
    public function recover($id)
    {
        $sources=array('cpms_ai_daily_snapshots'=>'ai_daily_snapshot','cpms_cost_data_events'=>'cost_data_event');
        $names=array(); $selected=null; $selectedSource=null; $observed=array();
        foreach ($sources as $table=>$label) {
            $columns=$this->source->columns($table);
            if (count(array_diff(array('project_id','project_name_snapshot'),$columns))) continue;
            $fields=array_values(array_intersect(explode(' ','id project_id project_name_snapshot project_status_snapshot project_start_date project_end_date contract_amount client contractor location snapshot_date captured_at actual_date event_at'),$columns));
            $order=array(); foreach (array('snapshot_date','captured_at','event_at','actual_date','id') as $field) if (in_array($field,$columns)) $order[]='`'.$field.'` DESC';
            $latest=null; $offset=0;
            do {
                $st=$this->source->query('SELECT `'.implode('`,`',$fields).'` FROM `'.$table.'` WHERE project_id=?'.($order?' ORDER BY '.implode(',',$order):'').' LIMIT 500 OFFSET '.$offset,array($id));
                $count=0;
                while ($row=$st->fetch(PDO::FETCH_ASSOC)) {
                    $count++; $name=self::text($row['project_name_snapshot']); if ($name==='') continue;
                    // Two distinct names already prove ambiguity; keep bounded state.
                    if (count($names)<2) $names[$name]=true;
                    $observed[$label]=true;
                    if ($latest===null) { $latest=$row; $latest['project_name_snapshot']=$name; }
                    elseif (!$order) foreach (array('project_status_snapshot','project_start_date','project_end_date','contract_amount','client','contractor','location') as $field) {
                        // Without chronology, disagreeing optional fields remain unknown.
                        if (isset($row[$field]) && (!isset($latest[$field]) || $latest[$field]!==$row[$field])) $latest[$field]=null;
                    }
                }
                $offset+=500;
            } while ($count===500);
            if ($selected===null && $latest!==null) { $selected=$latest; $selectedSource=$label; }
        }
        $diagnostic=array('legacy_id'=>(string)$id,'name_confirmed'=>count($names)===1,'status_confirmed'=>false,'physical_master_missing'=>true,'recovery_source'=>$selectedSource,'sources_checked'=>array_keys($observed),'recovered'=>false);
        if (count($names)!==1) {
            $diagnostic['code']=count($names)>1?'legacy_project_snapshot_ambiguous':'legacy_project_snapshot_unavailable';
            return array('row'=>null,'diagnostic'=>$diagnostic);
        }
        $status=isset($selected['project_status_snapshot'])?self::text($selected['project_status_snapshot']):'';
        $known=array('입찰 진행중','계약중','진행중','정산완료','대기중','입찰검토','가제','정식전환대기','bidding','contracting','active','settled');
        $diagnostic['status_confirmed']=in_array($status,$known,true); $diagnostic['recovered']=true;
        $row=array('id'=>$id,'name'=>$selected['project_name_snapshot'],'status'=>$status!==''?$status:null,'start_date'=>self::date(isset($selected['project_start_date'])?$selected['project_start_date']:null),'end_date'=>self::date(isset($selected['project_end_date'])?$selected['project_end_date']:null),'contract_amount'=>null,'client'=>null,'contractor'=>null,'location'=>null,'legacy_reference_only'=>1,'legacy_project_physically_deleted'=>1,'recovered_by'=>'historical_project_snapshot','recovery_source'=>$selectedSource);
        foreach (array('client','contractor','location') as $field) if (isset($selected[$field]) && self::text($selected[$field])!=='') $row[$field]=$selected[$field];
        if (isset($selected['contract_amount']) && preg_match('/^\d+(?:\.\d{1,2})?$/D',(string)$selected['contract_amount'])) $row['contract_amount']=$selected['contract_amount'];
        foreach (array('snapshot_date','event_at','actual_date','captured_at') as $field) if (!empty($selected[$field])) { $row['source_snapshot_date']=$selected[$field]; break; }
        if ($status!=='') $row['source_status_snapshot']=$status;
        return array('row'=>$row,'diagnostic'=>$diagnostic);
    }
}
