<?php
// app/services/Cpms2SensitiveExportService.php
// Migration-only account access. No key, directory, DB or audit-log writes.
require_once __DIR__.'/CryptoHelper.php';
require_once __DIR__.'/Cpms2PayrollAccountExportService.php';
require_once __DIR__.'/Cpms2ExportDiagnostic.php';
class Cpms2SensitiveExportService
{
    private $root; private $storageRoot; private $keys=null;
    public function __construct($root,$storageRoot=null) { $this->root=$root; $this->storageRoot=$storageRoot===null?$root.'/storage':$storageRoot; }
    public static function accountNumber($value)
    {
        $value=trim((string)$value);
        return $value!=='' && preg_match('/^[0-9\s-]+$/D',$value)?CryptoHelper::normalizeDigits($value):'';
    }
    private function keyMaterials()
    {
        if ($this->keys!==null) return $this->keys;
        $keys=array(); $environment=getenv('CPMS_WORKER_CRYPTO_KEY');
        if (is_string($environment) && trim($environment)!=='') { $keys[]=$environment; if (trim($environment)!==$environment) $keys[]=trim($environment); }
        // This is CryptoHelper's existing file, not a newly configured storage path.
        $file=$this->root.'/storage/secrets/worker_crypto.key';
        if (is_file($file) && is_readable($file)) { $key=@file_get_contents($file); if (is_string($key) && trim($key)!=='') $keys[]=trim($key); }
        // Exact existing fallback algorithm. Loading this config returns an array;
        // no CryptoHelper keyBytes/loadOrCreateKeyFile/decrypt call is made.
        $part=''; $file=$this->root.'/app/config/database.php';
        if (is_file($file) && is_readable($file)) { $config=include $file; if (is_array($config)) $part=(isset($config['host'])?$config['host']:'').'|'.(isset($config['dbname'])?$config['dbname']:'').'|'.(isset($config['user'])?$config['user']:''); }
        $keys[]='cpms-worker-crypto-v1|'.$this->root.'|'.$part;
        return $this->keys=array_unique($keys);
    }
    public function workerAccount($row)
    {
        $encrypted=isset($row['bank_account_enc'])?trim((string)$row['bank_account_enc']):'';
        $expected=isset($row['bank_account_hash'])?trim((string)$row['bank_account_hash']):'';
        if ($encrypted==='') {
            $plain=self::plainAccount($row,'WORKER_ACCOUNT_MISSING');
            if ($expected!=='' && (!isset($plain['account_number']) || $plain['account_number']==='')) throw new RuntimeException('WORKER_ACCOUNT_MISSING');
            if ($expected!=='' && !hash_equals($expected,(string)CryptoHelper::hashSensitive($plain['account_number']))) throw new RuntimeException('WORKER_ACCOUNT_HASH_MISMATCH');
            return $plain;
        }
        $candidates=array();
        if (strpos($encrypted,'plain64:')===0) { $value=base64_decode(substr($encrypted,8),true); if ($value!==false) $candidates[]=$value; }
        elseif (strpos($encrypted,'aes256cbc:')===0 && function_exists('openssl_decrypt')) {
            $raw=base64_decode(substr($encrypted,10),true);
            if ($raw!==false && strlen($raw)>16 && (strlen($raw)-16)%16===0) foreach ($this->keyMaterials() as $material) {
                $value=@openssl_decrypt(substr($raw,16),'AES-256-CBC',hash('sha256',$material,true),OPENSSL_RAW_DATA,substr($raw,0,16));
                if ($value!==false) $candidates[]=$value;
            }
        }
        $mismatch=false;
        foreach ($candidates as $value) {
            $number=self::accountNumber($value); if ($number==='') continue;
            if ($expected!=='' && !hash_equals($expected,(string)CryptoHelper::hashSensitive($number))) { $mismatch=true; continue; }
            return array('bank_name'=>isset($row['bank_name'])?$row['bank_name']:null,'account_number'=>$number,'account_holder'=>isset($row['account_holder'])?$row['account_holder']:null);
        }
        throw new RuntimeException($mismatch?'WORKER_ACCOUNT_HASH_MISMATCH':'WORKER_ACCOUNT_DECRYPT_FAILED');
    }
    public static function plainAccount($row,$code)
    {
        $value=isset($row['account_number']) && trim((string)$row['account_number'])!==''?$row['account_number']:(isset($row['bank_account'])?$row['bank_account']:'');
        $number=self::accountNumber($value);
        if ($number==='' && (trim((string)$value)!=='' || !empty($row['bank_name']) || !empty($row['account_holder']))) throw new RuntimeException($code);
        return array('bank_name'=>isset($row['bank_name'])?$row['bank_name']:null,'account_number'=>$number!==''?$number:null,'account_holder'=>isset($row['account_holder'])?$row['account_holder']:null);
    }
    public function employeeAccounts($source) { return (new Cpms2PayrollAccountExportService($this->root,$this->storageRoot))->accounts($source); }
    public function preflight($source)
    {
        $report=array('counts'=>array(),'failures'=>array());
        $payroll=$this->employeeAccounts($source); $report['counts']['employees']=$payroll['counts']; $report['failures']=$payroll['failures'];
        foreach (array('cpms_vendors'=>'vendors','workers'=>'workers','direct_team_members'=>'direct_team') as $table=>$entity) {
            $report['counts'][$entity]=array('source'=>0,'verified'=>0,'failed'=>0);
            $fields=explode(' ','id name bank_name account_number bank_account bank_account_enc bank_account_hash account_holder');
            foreach ($source->rows($table,$fields) as $row) {
                $present=false; foreach (array_slice($fields,2) as $field) if (isset($row[$field]) && trim((string)$row[$field])!=='') $present=true;
                if (!$present) continue;
                $report['counts'][$entity]['source']++;
                try { $account=$entity==='workers'?$this->workerAccount($row):self::plainAccount($row,strtoupper($entity).'_ACCOUNT_MISSING'); $report['counts'][$entity]['verified']++; }
                catch (RuntimeException $e) { $report['counts'][$entity]['failed']++; $report['failures'][]=array('entity'=>$entity,'legacy_id'=>(string)$row['id'],'name'=>isset($row['name'])?(string)$row['name']:'','code'=>Cpms2ExportFailure::safe($entity,$e)->getMessage()); }
            }
        }
        return $report;
    }
}
