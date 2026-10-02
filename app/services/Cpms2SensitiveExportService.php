<?php
// app/services/Cpms2SensitiveExportService.php
// Migration-only account access. No key, directory, DB or audit-log writes.
require_once __DIR__.'/CryptoHelper.php';
require_once __DIR__.'/Cpms2PayrollAccountExportService.php';
require_once __DIR__.'/Cpms2ExportDiagnostic.php';
class Cpms2SensitiveExportService
{
    private $root; private $storageRoot; private $keys=null;
    private $snapshotIndex=null; private $snapshotSource=null;
    public function __construct($root,$storageRoot=null) { $this->root=$root; $this->storageRoot=$storageRoot===null?$root.'/storage':$storageRoot; }
    public static function accountNumber($value)
    {
        $value=trim((string)$value);
        // Account fields may include bank labels or legacy punctuation. Reject
        // placeholders and masks; at least six digits are needed for a candidate.
        if ($value==='' || preg_match('/없음|미등록|현금|[*●]/u',$value)) return '';
        $number=CryptoHelper::normalizeDigits($value);
        return strlen($number)>=6 && strlen($number)<=30?$number:'';
    }
    private function keyMaterials()
    {
        if ($this->keys!==null) return $this->keys;
        $keys=array(); $environment=getenv('CPMS_WORKER_CRYPTO_KEY');
        if (is_string($environment) && trim($environment)!=='') { $keys[$environment]=false; $keys[trim($environment)]=false; }
        // This is CryptoHelper's existing file, not a newly configured storage path.
        $file=$this->root.'/storage/secrets/worker_crypto.key';
        $files=array($file,$this->root.'/storage/secrets/worker_crypto_legacy.key');
        $legacy=glob($this->root.'/storage/secrets/worker_crypto_legacy_*.key');
        if (is_array($legacy)) $files=array_merge($files,$legacy);
        foreach ($files as $index=>$file) if (is_file($file) && is_readable($file)) {
            $key=@file_get_contents($file);
            if (is_string($key) && trim($key)!=='' && !isset($keys[trim($key)])) $keys[trim($key)]=$index!==0;
        }
        // Exact existing fallback algorithm. Loading this config returns an array;
        // no CryptoHelper keyBytes/loadOrCreateKeyFile/decrypt call is made.
        $part=''; $file=$this->root.'/app/config/database.php';
        if (is_file($file) && is_readable($file)) { $config=include $file; if (is_array($config)) $part=(isset($config['host'])?$config['host']:'').'|'.(isset($config['dbname'])?$config['dbname']:'').'|'.(isset($config['user'])?$config['user']:''); }
        $keys['cpms-worker-crypto-v1|'.$this->root.'|'.$part]=false;
        // cf705bc used __FILE__ when no key material was available. Try both
        // native and slash paths, but accept historical candidates only by hash.
        $path=$this->root.'/app/services/CryptoHelper.php';
        foreach (array($path,str_replace('/',DIRECTORY_SEPARATOR,$path),str_replace('\\','/',$path)) as $path) if (!isset($keys[$path])) $keys[$path]=true;
        return $this->keys=$keys;
    }
    public static function counts()
    {
        return array('source'=>0,'verified'=>0,'failed'=>0,'missing_number'=>0,'partial_information'=>0,'decrypted'=>0,'snapshot_recovered'=>0,'decrypt_failed'=>0,'hash_mismatch'=>0,'recovery_source_missing'=>0,'snapshot_conflict'=>0,'recovery_failed'=>0);
    }
    public static function hasSource($row,$worker=false)
    {
        foreach (array('account_number','bank_account') as $field) if (isset($row[$field]) && self::accountNumber($row[$field])!=='') return true;
        if ($worker) foreach (array('bank_account_enc','bank_account_hash') as $field) if (isset($row[$field]) && trim((string)$row[$field])!=='') return true;
        return false;
    }
    private static function bankFields($row,$number)
    {
        return array('bank_name'=>isset($row['bank_name'])?$row['bank_name']:null,'account_number'=>$number,'account_holder'=>isset($row['account_holder'])?$row['account_holder']:null);
    }
    private function snapshotAccount($row,$source,$expected)
    {
        try { return $this->linkedSnapshotAccount($row,$source,$expected); }
        catch (RuntimeException $e) {
            if (!$source || $expected==='') throw $e;
            $index=$this->globalSnapshotIndex($source);
            if (!isset($index[$expected])) throw $e;
            if ($index[$expected]['conflict']) throw new RuntimeException('WORKER_ACCOUNT_SNAPSHOT_CONFLICT');
            return self::snapshotBankFields($row,$index[$expected]['snapshot'],$index[$expected]['number']);
        }
    }
    protected function snapshotHash($number) { return CryptoHelper::hashSensitive($number); }
    private function globalSnapshotIndex($source)
    {
        if ($this->snapshotSource===$source && $this->snapshotIndex!==null) return $this->snapshotIndex;
        $index=array(); $columns=$source->columns('cpms_project_labor_workers');
        if (in_array('bank_account',$columns)) {
            $fields=array_intersect(array('bank_account','bank_name','account_holder'),$columns);
            $statement=$source->query('SELECT `'.implode('`,`',$fields).'` FROM cpms_project_labor_workers WHERE bank_account IS NOT NULL AND TRIM(bank_account)<>\'\'');
            while ($snapshot=$statement->fetch(PDO::FETCH_ASSOC)) {
                $number=self::accountNumber($snapshot['bank_account']); if ($number==='') continue;
                $hash=$this->snapshotHash($number);
                if (!isset($index[$hash])) $index[$hash]=array('number'=>$number,'snapshot'=>$snapshot,'conflict'=>false);
                elseif ($index[$hash]['number']!==$number) $index[$hash]['conflict']=true;
            }
        }
        $this->snapshotSource=$source; return $this->snapshotIndex=$index;
    }
    private static function snapshotBankFields($row,$snapshot,$number)
    {
        foreach (array('bank_name','account_holder') as $field) if (empty($row[$field]) && isset($snapshot[$field])) $row[$field]=$snapshot[$field];
        return self::bankFields($row,$number);
    }
    private function linkedSnapshotAccount($row,$source,$expected)
    {
        if (!$source || !isset($row['id'])) throw new RuntimeException('WORKER_ACCOUNT_DECRYPT_FAILED');
        $columns=$source->columns('cpms_project_labor_workers');
        if (count(array_diff(array('worker_id','bank_account'),$columns))) throw new RuntimeException('WORKER_ACCOUNT_DECRYPT_FAILED');
        $fields=array_intersect(array('bank_account','bank_name','account_holder'),$columns);
        $statement=$source->query('SELECT `'.implode('`,`',$fields).'` FROM cpms_project_labor_workers WHERE worker_id=? AND bank_account IS NOT NULL AND TRIM(bank_account)<>\'\'',array($row['id']));
        $candidates=array();
        while ($snapshot=$statement->fetch(PDO::FETCH_ASSOC)) {
            $number=self::accountNumber($snapshot['bank_account']);
            if ($number!=='' && !isset($candidates[$number])) $candidates[$number]=$snapshot;
        }
        if (!$candidates) throw new RuntimeException('WORKER_ACCOUNT_DECRYPT_FAILED');
        $matches=array();
        foreach ($candidates as $number=>$snapshot) if ($expected!=='' && hash_equals($expected,(string)CryptoHelper::hashSensitive($number))) $matches[]=(string)$number;
        if (count($candidates)>1 && count($matches)!==1) throw new RuntimeException('WORKER_ACCOUNT_SNAPSHOT_CONFLICT');
        if ($expected!=='' && count($matches)!==1) throw new RuntimeException('WORKER_ACCOUNT_HASH_MISMATCH');
        $number=$matches?$matches[0]:(string)key($candidates);
        return self::snapshotBankFields($row,$candidates[$number],$number);
    }
    public function workerAccount($row,$source=null,&$method=null)
    {
        $method='plain';
        $encrypted=isset($row['bank_account_enc'])?trim((string)$row['bank_account_enc']):'';
        $expected=isset($row['bank_account_hash'])?trim((string)$row['bank_account_hash']):'';
        if ($encrypted==='') {
            $plain=self::plainAccount($row,'WORKER_ACCOUNT_MISSING');
            if ($expected!=='' && empty($plain['account_number'])) throw new RuntimeException('WORKER_ACCOUNT_RECOVERY_SOURCE_MISSING');
            if ($expected!=='' && !hash_equals($expected,(string)CryptoHelper::hashSensitive($plain['account_number']))) throw new RuntimeException('WORKER_ACCOUNT_HASH_MISMATCH');
            return $plain;
        }
        $candidates=array();
        if (strpos($encrypted,'plain64:')===0) { $value=base64_decode(substr($encrypted,8),true); if ($value!==false) $candidates[]=array($value,false); }
        elseif (strpos($encrypted,'aes256cbc:')===0 && function_exists('openssl_decrypt')) {
            $raw=base64_decode(substr($encrypted,10),true);
            if ($raw!==false && strlen($raw)>16 && (strlen($raw)-16)%16===0) foreach ($this->keyMaterials() as $material=>$requireHash) {
                $value=@openssl_decrypt(substr($raw,16),'AES-256-CBC',hash('sha256',$material,true),OPENSSL_RAW_DATA,substr($raw,0,16));
                if ($value!==false) $candidates[]=array($value,$requireHash);
            }
        }
        $mismatch=false;
        foreach ($candidates as $candidate) {
            if ($expected==='' && !preg_match('/^[0-9\s-]+$/D',trim($candidate[0]))) continue;
            $number=self::accountNumber($candidate[0]); if ($number==='' || ($candidate[1] && $expected==='')) continue;
            if ($expected!=='' && !hash_equals($expected,(string)CryptoHelper::hashSensitive($number))) { $mismatch=true; continue; }
            $method='decrypted'; return self::bankFields($row,$number);
        }
        if ($mismatch) throw new RuntimeException('WORKER_ACCOUNT_HASH_MISMATCH');
        $account=$this->snapshotAccount($row,$source,$expected); $method='snapshot_recovered'; return $account;
    }
    public static function plainAccount($row,$code)
    {
        $number=isset($row['account_number'])?self::accountNumber($row['account_number']):'';
        if ($number==='' && isset($row['bank_account'])) $number=self::accountNumber($row['bank_account']);
        return array('bank_name'=>isset($row['bank_name'])?$row['bank_name']:null,'account_number'=>$number!==''?$number:null,'account_holder'=>isset($row['account_holder'])?$row['account_holder']:null);
    }
    public function employeeAccounts($source) { return (new Cpms2PayrollAccountExportService($this->root,$this->storageRoot))->accounts($source); }
    public function preflight($source)
    {
        $report=array('counts'=>array(),'failures'=>array());
        $payroll=$this->employeeAccounts($source); $report['counts']['employees']=$payroll['counts']; $report['failures']=$payroll['failures']; $report['payroll']=$payroll['details'];
        foreach (array('cpms_vendors'=>'vendors','workers'=>'workers','direct_team_members'=>'direct_team') as $table=>$entity) {
            $report['counts'][$entity]=self::counts();
            $fields=explode(' ','id name bank_name account_number bank_account bank_account_enc bank_account_hash account_holder');
            foreach ($source->rows($table,$fields) as $row) {
                if (!self::hasSource($row,$entity==='workers')) {
                    $report['counts'][$entity]['missing_number']++;
                    if (!empty($row['bank_name']) || !empty($row['account_holder'])) $report['counts'][$entity]['partial_information']++;
                    continue;
                }
                $report['counts'][$entity]['source']++;
                try {
                    $method=null; $account=$entity==='workers'?$this->workerAccount($row,$source,$method):self::plainAccount($row,strtoupper($entity).'_ACCOUNT_MISSING'); $report['counts'][$entity]['verified']++;
                    if ($method==='decrypted' || $method==='snapshot_recovered') $report['counts'][$entity][$method]++;
                } catch (RuntimeException $e) {
                    $code=Cpms2ExportFailure::safe($entity,$e)->getMessage(); $report['counts'][$entity]['failed']++;
                    $kinds=array('WORKER_ACCOUNT_DECRYPT_FAILED'=>'decrypt_failed','WORKER_ACCOUNT_HASH_MISMATCH'=>'hash_mismatch','WORKER_ACCOUNT_RECOVERY_SOURCE_MISSING'=>'recovery_source_missing','WORKER_ACCOUNT_SNAPSHOT_CONFLICT'=>'snapshot_conflict');
                    if (isset($kinds[$code])) $report['counts'][$entity][$kinds[$code]]++;
                    if ($entity==='workers') $report['counts'][$entity]['recovery_failed']++;
                    $report['failures'][]=array('entity'=>$entity,'legacy_id'=>(string)$row['id'],'name'=>isset($row['name'])?(string)$row['name']:'','code'=>$code);
                }
            }
        }
        return $report;
    }
}
