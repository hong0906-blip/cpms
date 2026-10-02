<?php
// app/services/Cpms2SafetyCostExportService.php
// Read existing safety JSON/files only. Never load the source application's write helpers.
class Cpms2SafetyCostExportService
{
    private $root; private $storage;
    public function __construct($root,$storage) { $this->root=$root; $this->storage=$storage; }
    public static function pdf($path,$size=0)
    {
        if (!$path || !is_file($path) || !is_readable($path) || filesize($path)<=0 || ($size>0 && filesize($path)!=$size)) return false;
        $h=fopen($path,'rb'); $head=fread($h,5); fclose($h); return $head==='%PDF-';
    }
    private static function token($h)
    {
        do { $char=fread($h,1); } while ($char!=='' && strpos(" \t\r\n",$char)!==false); return $char;
    }
    private static function value($h)
    {
        $first=self::token($h); $raw=$first; $depth=($first==='{' || $first==='[')?1:0; $string=$first==='"'; $escape=false; $structured=$depth>0 || $string;
        if ($first==='') throw new RuntimeException('SAFETY_STORE_INVALID');
        while (true) {
            if ($structured && $depth===0 && !$string) break;
            $char=fread($h,1);
            if ($char==='') { if ($structured) throw new RuntimeException('SAFETY_STORE_INVALID'); break; }
            if (!$structured && (strpos(",]} \t\r\n",$char)!==false)) { fseek($h,-1,SEEK_CUR); break; }
            $raw.=$char; if (strlen($raw)>4194304) throw new RuntimeException('SAFETY_ROW_INVALID');
            if ($string) { if ($escape) $escape=false; elseif ($char==='\\') $escape=true; elseif ($char==='"') $string=false; }
            elseif ($char==='"') $string=true;
            elseif ($char==='{' || $char==='[') $depth++;
            elseif ($char==='}' || $char===']') $depth--;
        }
        $value=json_decode($raw); if (json_last_error()!==JSON_ERROR_NONE) throw new RuntimeException('SAFETY_STORE_INVALID'); return $value;
    }
    private static function items($path)
    {
        $h=fopen($path,'rb'); if (!$h) throw new RuntimeException('SAFETY_STORE_UNREADABLE'); $found=false;
        try {
            if (self::token($h)!=='{') throw new RuntimeException('SAFETY_STORE_INVALID');
            $next=self::token($h);
            while ($next!=='}') {
                fseek($h,-1,SEEK_CUR); $key=self::value($h);
                if (!is_string($key) || self::token($h)!==':') throw new RuntimeException('SAFETY_STORE_INVALID');
                if ($key==='items') {
                    if ($found || self::token($h)!=='[') throw new RuntimeException('SAFETY_STORE_INVALID'); $found=true;
                    $next=self::token($h);
                    while ($next!==']') {
                        if ($next==='') throw new RuntimeException('SAFETY_STORE_INVALID'); fseek($h,-1,SEEK_CUR); $item=self::value($h);
                        if (!is_object($item)) throw new RuntimeException('SAFETY_ROW_INVALID');
                        $row=(array)$item; if (isset($row['pdf']) && is_object($row['pdf'])) $row['pdf']=(array)$row['pdf']; yield $row;
                        $next=self::token($h); if ($next===']') break; if ($next!==',') throw new RuntimeException('SAFETY_STORE_INVALID');
                        $next=self::token($h); if ($next===']') throw new RuntimeException('SAFETY_STORE_INVALID');
                    }
                } else self::value($h);
                $next=self::token($h); if ($next==='}') break; if ($next!==',') throw new RuntimeException('SAFETY_STORE_INVALID');
                $next=self::token($h); if ($next==='}' || $next==='') throw new RuntimeException('SAFETY_STORE_INVALID');
            }
            if (!$found || self::token($h)!=='') throw new RuntimeException('SAFETY_STORE_INVALID');
        } finally { fclose($h); }
    }
    public function collect($db)
    {
        $s=array('store_found'=>false,'json_total'=>0,'json_active'=>0,'json_excluded'=>0,'bulk'=>0,'other'=>0,'db_candidates'=>0,'deduplicated'=>0,'possible_duplicates'=>0,'db_only'=>0,'final_count'=>0,'amount'=>'0.00','pdf_metadata'=>0,'pdf_available'=>0,'pdf_missing'=>0,'pdf_deduplicated'=>0);
        $rows=array(); $files=array(); $failures=array(); $warnings=array(); $ids=array(); $path=$this->storage.'/safety_costs/usage.json';
        if (file_exists($path)) {
            $s['store_found']=true;
            if (!is_file($path) || !is_readable($path)) throw new RuntimeException('SAFETY_STORE_UNREADABLE');
            foreach (self::items($path) as $item) {
                $s['json_total']++;
                if (!is_array($item)) throw new RuntimeException('SAFETY_ROW_INVALID');
                foreach (explode(' ','id project_id use_date amount supply_amount category status source vendor_id vendor_name biz_no item_name use_content remark representative phone project_name created_at updated_at') as $field) if (isset($item[$field]) && !is_scalar($item[$field])) throw new RuntimeException('SAFETY_ROW_INVALID');
                if (!empty($item['is_deleted']) || in_array(strtolower(isset($item['status'])?(string)$item['status']:''),array('deleted','cancelled','canceled','inactive','삭제','취소'))) { $s['json_excluded']++; continue; }
                $id=isset($item['id'])?(string)$item['id']:''; $project=isset($item['project_id'])?$item['project_id']:0; $date=isset($item['use_date'])?$item['use_date']:'';
                $amount=isset($item['amount']) && $item['amount']!==''?$item['amount']:(isset($item['supply_amount'])?$item['supply_amount']:null);
                if ($id==='' || isset($ids[$id]) || !ctype_digit((string)$project) || $project<1 || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',(string)$date,$m) || !checkdate((int)$m[2],(int)$m[3],(int)$m[1])) throw new RuntimeException('SAFETY_ROW_INVALID');
                try { $cents=Cpms2ExportPackageWriter::moneyCents($amount); }
                catch (Exception $e) { try { $cents=Cpms2ExportPackageWriter::moneyCents(isset($item['supply_amount'])?$item['supply_amount']:null); } catch (Exception $ignored) { throw new RuntimeException('SAFETY_AMOUNT_INVALID'); } }
                $ids[$id]=true; $s['json_active']++; $bulk=isset($item['source']) && $item['source']==='material_bulk_import'; $s[$bulk?'bulk':'other']++;
                $r=array('legacy_id'=>'safety-json:'.$id,'legacy_project_id'=>$project,'use_date'=>$date,'amount'=>Cpms2ExportPackageWriter::moneyDecimal($cents),'category'=>isset($item['category'])?$item['category']:'안전관리비','source_type'=>'safety_json','source_detail'=>$bulk?'material_bulk_import':'safety_cost','memo'=>implode(' / ',array_filter(array(isset($item['category'])?$item['category']:'',isset($item['use_content'])?$item['use_content']:'',isset($item['remark'])?$item['remark']:''))));
                foreach (array('project_name','vendor_name','biz_no','item_name','representative','phone','use_content','remark') as $field) if (isset($item[$field])) $r[$field]=$item[$field];
                if (isset($item['created_at'])) $r['source_created_at']=$item['created_at']; if (isset($item['updated_at'])) $r['source_updated_at']=$item['updated_at'];
                if (!empty($item['vendor_id'])) $r['legacy_vendor_id']=$item['vendor_id'];
                if (!empty($item['material_usage_id'])) $r['linked_usage_id']=(string)$item['material_usage_id'];
                $rows[]=$r;
                if (!empty($item['pdf']) && is_array($item['pdf'])) {
                    $s['pdf_metadata']++; $pdf=$item['pdf'];
                    foreach (array('stored_path','original_name','file_size') as $field) if (isset($pdf[$field]) && !is_scalar($pdf[$field])) throw new RuntimeException('SAFETY_ROW_INVALID');
                    $stored=isset($pdf['stored_path'])?(string)$pdf['stored_path']:'';
                    if (strpos(str_replace('\\','/',$stored),'../')!==false || strpos($stored,chr(0))!==false) throw new RuntimeException('SAFETY_FILE_PATH_INVALID');
                    $resolved=Cpms2MigrationExportService::statementPath($stored,$this->root,$this->storage.'/safety_costs/files');
                    if (!$resolved) $resolved=Cpms2MigrationExportService::statementPath($this->storage.'/'.$stored,$this->root,$this->storage.'/safety_costs/files');
                    if (!$resolved && ($stored!=='' && (file_exists($stored) || file_exists($this->root.'/'.$stored) || file_exists($this->storage.'/'.$stored)))) throw new RuntimeException('SAFETY_FILE_PATH_INVALID');
                    if ($resolved && !self::pdf($resolved,isset($pdf['file_size'])?(int)$pdf['file_size']:0)) throw new RuntimeException('SAFETY_PDF_INVALID');
                    $available=$resolved!==''; $s[$available?'pdf_available':'pdf_missing']++;
                    if (!$available) $warnings[]='SAFETY_FILE_UNAVAILABLE: '.$r['legacy_id'];
                    $name=!empty($pdf['original_name'])?basename(str_replace('\\','/',$pdf['original_name'])):'safety.pdf'; if (strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='pdf') $name.='.pdf';
                    $files[]=array('legacy_id'=>$r['legacy_id'].':pdf','legacy_safety_cost_id'=>$r['legacy_id'],'legacy_project_id'=>$project,'original_name'=>$name,'path'=>$resolved);
                }
            }
        }
        $db->inspect('cpms_material_items'); $db->inspect('cpms_material_usage');
        foreach ($db->rows('cpms_material_usage',array('id','project_id','material_id','use_date','amount','memo','advance_yn')) as $usage) {
            $columns=array_intersect(explode(' ','id project_id vendor_id vendor_name biz_no category item_name remark'),$db->columns('cpms_material_items'));
            if (!in_array('id',$columns) || !in_array('category',$columns)) continue;
            $master=$db->query('SELECT `'.implode('`,`',$columns).'` FROM cpms_material_items WHERE id=?',array($usage['material_id']))->fetch(PDO::FETCH_ASSOC);
            if (!$master || $master['category']!=='안전관리비') continue;
            $s['db_candidates']++; $duplicate=false; $possible=false;
            foreach ($rows as $json) {
                if ($json['source_type']!=='safety_json') continue;
                if ((string)$json['legacy_project_id']!==(string)$usage['project_id'] || $json['use_date']!==$usage['use_date'] || Cpms2ExportPackageWriter::moneyCents($json['amount'])!==Cpms2ExportPackageWriter::moneyCents($usage['amount'])) continue;
                if (isset($json['linked_usage_id']) && $json['linked_usage_id']===(string)$usage['id']) { $duplicate=$json['legacy_id']; break; }
                $possible=true;
            }
            if ($duplicate) { $s['deduplicated']++; $ids['db:'.$usage['id']]=$duplicate; continue; }
            if ($possible) { $s['possible_duplicates']++; $warnings[]='POSSIBLE_LEGACY_SAFETY_DUPLICATE: '.$usage['id']; }
            $s['db_only']++; $r=Cpms2MigrationExportService::legacy($usage); $r['source_type']='legacy_material_safety'; $rows[]=$r;
        }
        $total=0; $sha=array();
        foreach ($rows as &$r) { unset($r['linked_usage_id']); $total+=Cpms2ExportPackageWriter::moneyCents($r['amount']); } unset($r);
        foreach ($files as $f) if ($f['path']) { $hash=hash_file('sha256',$f['path']); if (isset($sha[$hash])) $s['pdf_deduplicated']++; $sha[$hash]=true; }
        $s['final_count']=count($rows); $s['amount']=Cpms2ExportPackageWriter::moneyDecimal($total);
        return array('summary'=>$s,'rows'=>$rows,'files'=>$files,'failures'=>$failures,'warnings'=>$warnings,'dedup_usage_map'=>array_filter($ids,'is_string'));
    }
}
