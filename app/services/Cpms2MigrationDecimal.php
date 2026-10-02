<?php
// app/services/Cpms2MigrationDecimal.php
// Exact signed decimal strings, including 32-bit PHP 5.6 Windows; no float/bcmath.
class Cpms2MigrationDecimal
{
    public static function normalize($value)
    {
        $value=(string)$value;
        if (!preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/D',$value,$m)) throw new RuntimeException('legacy_exclusion_amount_invalid');
        $whole=ltrim($m[2],'0'); if ($whole==='') $whole='0';
        $fraction=str_pad(isset($m[3])?$m[3]:'',2,'0');
        return ($m[1]==='-' && ($whole!=='0' || $fraction!=='00')?'-':'').$whole.'.'.$fraction;
    }
    public static function add($a,$b)
    {
        $a=self::normalize($a); $b=self::normalize($b); $an=$a[0]==='-'; $bn=$b[0]==='-';
        $a=ltrim(str_replace('.','',ltrim($a,'-')),'0'); $b=ltrim(str_replace('.','',ltrim($b,'-')),'0');
        if ($a==='') $a='0'; if ($b==='') $b='0';
        $length=max(strlen($a),strlen($b)); $a=str_pad($a,$length,'0',STR_PAD_LEFT); $b=str_pad($b,$length,'0',STR_PAD_LEFT);
        $subtract=$an!==$bn; $negative=$an;
        if ($subtract && strcmp($a,$b)<0) { $tmp=$a; $a=$b; $b=$tmp; $negative=$bn; }
        $result=''; $carry=0;
        for ($i=$length-1;$i>=0;$i--) {
            $digit=(int)$a[$i]+($subtract?-(int)$b[$i]:(int)$b[$i])+$carry;
            if ($subtract) { $carry=$digit<0?-1:0; if ($digit<0) $digit+=10; }
            else { $carry=$digit>=10?1:0; $digit%=10; }
            $result=(string)$digit.$result;
        }
        if ($carry>0) $result='1'.$result;
        $result=ltrim($result,'0'); if ($result==='') $result='0'; $result=str_pad($result,3,'0',STR_PAD_LEFT);
        return ($negative && $result!=='000'?'-':'').substr($result,0,-2).'.'.substr($result,-2);
    }
    public static function format($value)
    {
        $value=self::normalize($value); $parts=explode('.',$value);
        return preg_replace('/\B(?=(\d{3})+(?!\d))/',',',$parts[0]).($parts[1]==='00'?'':'.'.$parts[1]);
    }
}
