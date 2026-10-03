<?php
// app/services/Cpms2ManagementPreflightSupport.php
// PHP 5.6. Pure helpers: no bootstrap, source writes, logging or remote access.
require_once __DIR__.'/Cpms2MigrationDecimal.php';
class Cpms2ManagementPreflightSupport
{
    public $issues=array();
    public function issue($domain,$code,$severity='WARNING',$count=1,$ids=array())
    {
        if (!$count) return;
        $key=$domain.'|'.$code;
        if (!isset($this->issues[$key])) $this->issues[$key]=array('domain'=>$domain,'code'=>$code,'severity'=>$severity,'count'=>0,'legacy_ids'=>array());
        $this->issues[$key]['count']+=(int)$count;
        $this->issues[$key]['legacy_ids']=array_slice(array_unique(array_merge($this->issues[$key]['legacy_ids'],$ids)),0,20);
    }
    public function json($path,$domain='Overhead')
    {
        if (!is_file($path)) return null;
        // Bound a single JSON allocation. Oversized/unreadable sources are explicit blockers.
        $limit=4194304; $configured=trim(ini_get('memory_limit'));
        if ($configured!=='' && $configured!=='-1') {
            $bytes=(int)$configured; $suffix=strtolower(substr($configured,-1));
            if ($suffix==='g') $bytes*=1073741824; elseif ($suffix==='m') $bytes*=1048576; elseif ($suffix==='k') $bytes*=1024;
            $limit=min($limit,max(0,(int)(($bytes-memory_get_usage(true)-8388608)/20)));
        }
        if (!is_readable($path) || filesize($path)>$limit) { $this->issue($domain,'SOURCE_FILE_UNREADABLE_OR_TOO_LARGE','BLOCKING'); return null; }
        $raw=@file_get_contents($path);
        if ($raw===false) { $this->issue($domain,'SOURCE_FILE_READ_FAILED','BLOCKING'); return null; }
        if (substr($raw,0,3)==="\xEF\xBB\xBF") $raw=substr($raw,3);
        $data=json_decode($raw,true); unset($raw);
        if (!is_array($data)) { $this->issue($domain,'SOURCE_JSON_PARSE_FAILED','BLOCKING'); return null; }
        return $data;
    }
    public static function month($value) { return is_string($value) && preg_match('/^(19|20|21)\d{2}-(0[1-9]|1[0-2])$/D',$value); }
    public static function items($data)
    {
        if (!is_array($data)) return array();
        if (isset($data['items']) && is_array($data['items'])) return $data['items'];
        if (isset($data['id']) || isset($data['amount'])) return array($data);
        return array_keys($data)===range(0,count($data)-1)?$data:array();
    }
    public function money($value)
    {
        if ($value===null || $value==='') return '0.00';
        $s=trim((string)$value);
        $s=str_replace(array(',', '원', ' ', "\t", "\xE2\x88\x92", "\xEF\xBC\x8D", "\xE2\x80\x90", "\xE2\x80\x91", "\xE2\x80\x92", "\xE2\x80\x93", "\xE2\x80\x94"),array('','','','','-','-','-','-','-','-','-'),$s);
        if (preg_match('/^\((\d+(?:\.\d{1,2})?)\)$/D',$s,$m)) $s='-'.$m[1];
        if (preg_match('/^(\d+(?:\.\d{1,2})?)-$/D',$s,$m)) $s='-'.$m[1];
        if (preg_match('/^[△▲](\d+(?:\.\d{1,2})?)$/uD',$s,$m)) $s='-'.$m[1];
        try { return Cpms2MigrationDecimal::normalize($s); }
        catch (Exception $e) { $this->issue('Overhead','SOURCE_AMOUNT_INVALID','BLOCKING'); return '0.00'; }
    }
    public static function add($a,$b) { return Cpms2MigrationDecimal::add($a,$b); }
    public static function negate($a) { $a=Cpms2MigrationDecimal::normalize($a); return $a==='0.00'?$a:($a[0]==='-'?substr($a,1):'-'.$a); }
    public static function statusLabel($s)
    {
        // A malformed status must not become a path, account, free-text payload or secret in the report.
        return is_string($s) && preg_match('/^[a-zA-Z가-힣_ ]{1,40}$/uD',$s)?$s:'[UNRECOGNIZED_VALUE]';
    }
    public static function safeDate($s) { return is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/D',$s)?$s:null; }
}
