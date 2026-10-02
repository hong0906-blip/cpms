<?php
// app/services/Cpms2DeletedProjectTraceService.php
// PHP 5.6: bounded administrator clues only, never project identity or export rows.
class Cpms2DeletedProjectTraceService
{
    private $source;
    private static $fields=array(
        'cpms_material_items'=>'project_id vendor_name category item_name spec remark base_rate',
        'cpms_material_usage'=>'use_date amount memo material_id',
        'cpms_equipment_items'=>'vendor_name category item_name equipment_name spec remark',
        'cpms_equipment_usage'=>'use_date amount work_unit equipment_id memo',
        'cpms_material_statement_files'=>'original_name use_date ym uploaded_at',
        'cpms_outsourcing_costs'=>'expense_date vendor_name company_name content memo',
        'cpms_progress_billings'=>'round_label progress_date requested_amount recognized_amount remark'
    );
    private static $limits=array(15,15,15,15,15,10,15); // Total <= 100 across all seven sources.
    public function __construct($source) { $this->source=$source; }
    private static function safeText($value,$filename=false)
    {
        $value=(string)$value;
        if ($filename) $value=basename(str_replace('\\','/',$value));
        // Free text can contain contact/payment information despite the column whitelist.
        $value=preg_replace('/(?:주민(?:등록)?번호|계좌(?:번호)?|예금주|담당자|연락처|전화|휴대폰|이메일|비밀번호|password|secret|token)\s*[:=]?\s*[^\n,;]+/iu','[민감정보 숨김]',$value);
        $value=preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i','[연락처 숨김]',$value);
        $value=preg_replace('/(?<!\d)(?:\d[\s.\-\/]*){6,}\d(?!\d)/u','[번호 숨김]',$value);
        $value=trim(preg_replace('/[\x00-\x1f\x7f]+/u',' ',$value));
        return function_exists('mb_substr')?mb_substr($value,0,240,'UTF-8'):substr($value,0,240);
    }
    private function scope($table,$alias='t',$depth=0)
    {
        $columns=$this->source->columns($table); if (!$columns || $depth>2) return null;
        $direct=in_array('project_id',$columns)?$alias.'.`project_id` = ?':null;
        $relations=array(
            'cpms_material_usage'=>array('material_id'=>'cpms_material_items'),
            'cpms_equipment_usage'=>array('equipment_id'=>'cpms_equipment_items'),
            'cpms_material_statement_files'=>array('material_usage_id'=>'cpms_material_usage','material_id'=>'cpms_material_items')
        );
        $conditions=$direct?array($direct):array(); $params=$direct?1:0;
        if (isset($relations[$table])) foreach ($relations[$table] as $field=>$parent) {
            if (!in_array($field,$columns) || !in_array('id',$this->source->columns($parent))) continue;
            $parentAlias=$alias.'p'; $scope=$this->scope($parent,$parentAlias,$depth+1); if (!$scope) continue;
            $join='EXISTS (SELECT 1 FROM `'.$parent.'` '.$parentAlias.' WHERE '.$parentAlias.'.`id`='.$alias.'.`'.$field.'` AND ('.$scope['sql'].'))';
            // Do not cross into another explicit project through an inconsistent relation.
            if ($direct) $join='(('.$alias.'.`project_id` IS NULL OR '.$alias.'.`project_id`=0) AND '.$join.')';
            $conditions[]=$join; $params+=$scope['params'];
        }
        return $conditions?array('sql'=>implode(' OR ',$conditions),'params'=>$params):null;
    }
    private function uniqueValues($table,$scope,$params,$fields)
    {
        $columns=$this->source->columns($table); $values=array(); $truncated=false;
        foreach (array_intersect($fields,$columns) as $field) {
            $st=$this->source->query('SELECT DISTINCT TRIM(t.`'.$field.'`) AS value FROM `'.$table.'` t WHERE ('.$scope.') AND t.`'.$field.'` IS NOT NULL AND TRIM(t.`'.$field.'`)<>? ORDER BY value LIMIT 21',array_merge($params,array('')));
            while ($row=$st->fetch(PDO::FETCH_ASSOC)) {
                $value=self::safeText($row['value'],$field==='original_name'); if ($value==='') continue;
                if (isset($values[$value])) continue;
                if (count($values)>=20) { $truncated=true; continue; }
                $values[$value]=true;
            }
        }
        return array('values'=>array_keys($values),'truncated'=>$truncated);
    }
    private static function date($value)
    {
        $date=substr((string)$value,0,10);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$date,$m) || !checkdate((int)$m[2],(int)$m[3],(int)$m[1])) return null;
        return $date;
    }
    public function collect($projectId)
    {
        if (!ctype_digit((string)$projectId) || (int)$projectId<=0) throw new RuntimeException('Invalid trace project identifier.');
        $result=array('legacy_id'=>(string)$projectId,'first_use_date'=>null,'last_use_date'=>null,'counts'=>array(),'lists'=>array(),'details'=>array(),'detail_count'=>0,'detail_limit'=>100,'unavailable_sources'=>array());
        $index=0;
        foreach (self::$fields as $table=>$allowed) {
            $limit=self::$limits[$index++]; $scope=$this->scope($table);
            if (!$scope) { $result['counts'][$table]=null; $result['unavailable_sources'][]=$table; continue; }
            $params=array_fill(0,$scope['params'],(int)$projectId); $columns=$this->source->columns($table);
            $dateField=in_array('use_date',$columns)?'use_date':(in_array('expense_date',$columns)?'expense_date':(in_array('progress_date',$columns)?'progress_date':null));
            $dateValue=$dateField?'CASE WHEN SUBSTR(t.`'.$dateField.'`,1,4)>=\'1000\' AND SUBSTR(t.`'.$dateField.'`,5,1)=\'-\' AND SUBSTR(t.`'.$dateField.'`,8,1)=\'-\' THEN SUBSTR(t.`'.$dateField.'`,1,10) ELSE NULL END':null;
            $dateSql=$dateField?',MIN('.$dateValue.') AS first_date,MAX('.$dateValue.') AS last_date':'';
            $aggregate=$this->source->query('SELECT COUNT(*) AS total'.$dateSql.' FROM `'.$table.'` t WHERE ('.$scope['sql'].')',$params)->fetch(PDO::FETCH_ASSOC);
            $result['counts'][$table]=(int)$aggregate['total'];
            if ($dateField) foreach (array('first_date'=>'first_use_date','last_date'=>'last_use_date') as $key=>$target) {
                $date=self::date($aggregate[$key]);
                if ($date && ($result[$target]===null || ($key==='first_date'?$date<$result[$target]:$date>$result[$target]))) $result[$target]=$date;
            }
            if (in_array($table,array('cpms_material_items','cpms_equipment_items','cpms_outsourcing_costs'))) $result['lists'][$table.'_vendors']=$this->uniqueValues($table,$scope['sql'],$params,array('vendor_name','company_name'));
            if (in_array($table,array('cpms_material_items','cpms_equipment_items'))) $result['lists'][$table.'_names']=$this->uniqueValues($table,$scope['sql'],$params,array('item_name','equipment_name','spec'));
            if ($table==='cpms_material_statement_files') $result['lists']['statement_names']=$this->uniqueValues($table,$scope['sql'],$params,array('original_name'));
            $fields=array_values(array_intersect(explode(' ',$allowed),$columns)); if (!$fields) continue;
            $select=array(); foreach ($fields as $field) $select[]='t.`'.$field.'`';
            $order=in_array('id',$columns)?'t.`id`':implode(',',$select);
            $st=$this->source->query('SELECT '.implode(',',$select).' FROM `'.$table.'` t WHERE ('.$scope['sql'].') ORDER BY '.$order.' LIMIT '.$limit,$params);
            $rows=array();
            while ($row=$st->fetch(PDO::FETCH_ASSOC)) {
                foreach ($row as $field=>$value) {
                    if (in_array($field,array('project_id','material_id','equipment_id'))) $row[$field]=ctype_digit((string)$value)?(string)$value:null;
                    elseif (in_array($field,array('amount','base_rate','work_unit','requested_amount','recognized_amount'))) $row[$field]=is_numeric($value)?(string)$value:null;
                    elseif (in_array($field,array('use_date','expense_date','progress_date','uploaded_at'))) $row[$field]=self::date($value);
                    elseif ($field==='ym') $row[$field]=preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',(string)$value)?(string)$value:null;
                    else $row[$field]=self::safeText($value,$field==='original_name');
                    if ($field==='uploaded_at' && $row[$field]!==null && preg_match('/^\d{4}-\d{2}-\d{2} ([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/D',(string)$value)) $row[$field]=(string)$value;
                }
                $rows[]=$row; $result['detail_count']++;
            }
            $result['details'][$table]=$rows;
        }
        $result['progress_billing_exists']=isset($result['counts']['cpms_progress_billings'])?$result['counts']['cpms_progress_billings']>0:null;
        return $result;
    }
}
