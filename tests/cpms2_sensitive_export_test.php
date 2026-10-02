<?php
// tests/cpms2_sensitive_export_test.php
// PHP 5.6. Fixture keys, JSON and accounts only; no application bootstrap.
require_once dirname(__DIR__).'/app/services/Cpms2SensitiveExportService.php';
require_once dirname(__DIR__).'/app/services/Cpms2ExportDiagnostic.php';
require_once dirname(__DIR__).'/app/services/Cpms2ReadOnlySource.php';
date_default_timezone_set('Asia/Seoul');
$checks=0;
function account_assert($ok,$label) { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }
function account_reject($call,$code) { try { $call(); } catch (RuntimeException $e) { account_assert($e->getMessage()===$code,'Unsafe or incorrect failure code.'); return; } throw new RuntimeException('Invalid account accepted.'); }
function account_cipher($number,$material) { $iv=str_repeat('i',16); return 'aes256cbc:'.base64_encode($iv.openssl_encrypt($number,'AES-256-CBC',hash('sha256',$material,true),OPENSSL_RAW_DATA,$iv)); }
class Cpms2AccountFixtureSource
{
    public $tables=array(); public $queries=array();
    public function rows($table,$fields) { if (isset($this->tables[$table])) foreach ($this->tables[$table] as $row) yield array_intersect_key($row,array_flip($fields)); }
    public function columns($table) { return !empty($this->tables[$table])?array_keys($this->tables[$table][0]):array(); }
    public function query($sql,$params=array()) {
        Cpms2ReadOnlySource::assertReadOnly($sql); $this->queries[]=$sql;
        if ($params) account_assert(count($params)===1 && strpos($sql,'worker_id=?')!==false,'Snapshot query was not scoped to legacy worker ID.');
        $rows=array(); foreach ($this->tables['cpms_project_labor_workers'] as $row) if ((!$params || (isset($row['worker_id']) && $row['worker_id']===$params[0])) && trim($row['bank_account'])!=='') $rows[]=$row;
        return new Cpms2AccountFixtureStatement($rows);
    }
}
class Cpms2AccountFixtureStatement
{
    private $rows; public function __construct($rows) { $this->rows=$rows; }
    public function fetch($mode=null) { return array_shift($this->rows); }
}
// A synthetic hash collision exercises the defensive branch; production always
// uses CryptoHelper::hashSensitive and never accepts a hash supplied by a snapshot.
class Cpms2CollisionFixtureService extends Cpms2SensitiveExportService
{
    protected function snapshotHash($number) { return CryptoHelper::hashSensitive('000000000003'); }
}
class Cpms2UnavailableAccountFixtureSource extends Cpms2AccountFixtureSource
{
    public function columns($table) { throw new RuntimeException('fixture-source-unavailable'); }
}
// A real temporary file tree exposed with read-only permissions on every OS.
class Cpms2ReadonlyPayrollFixture
{
    public $context; private $stream; private $directory;
    public function url_stat($path,$flags) { $stat=@stat(substr($path,11)); if (!$stat) return false; $stat['mode']=$stat[2]=$stat[2]&~0222; return $stat; }
    public function stream_open($path,$mode,$options,&$opened) { if ($mode!=='rb' && $mode!=='r') throw new RuntimeException('Payroll attempted a write.'); $this->stream=fopen(substr($path,11),'rb'); return (bool)$this->stream; }
    public function stream_read($count) { return fread($this->stream,$count); }
    public function stream_eof() { return feof($this->stream); }
    public function stream_stat() { return fstat($this->stream); }
    public function stream_close() { fclose($this->stream); }
    public function dir_opendir($path,$options) { $this->directory=opendir(substr($path,11)); return (bool)$this->directory; }
    public function dir_readdir() { return readdir($this->directory); }
    public function dir_closedir() { closedir($this->directory); return true; }
    public function dir_rewinddir() { rewinddir($this->directory); return true; }
}
function account_remove_fixture($directory,$boundary=null) {
    $resolved=realpath($directory); if ($boundary===null) $boundary=$resolved;
    if (!$resolved || ($resolved!==$boundary && strpos($resolved,$boundary.DIRECTORY_SEPARATOR)!==0)) throw new RuntimeException('Fixture cleanup boundary violated.');
    foreach (scandir($directory) as $name) if ($name!=='.' && $name!=='..') { $path=$directory.'/'.$name; if (is_dir($path)) account_remove_fixture($path,$boundary); else unlink($path); } rmdir($directory);
}
$root=sys_get_temp_dir().'/cpms-account-fixture-'.uniqid(); mkdir($root,0700);
$originalEnvironment=getenv('CPMS_WORKER_CRYPTO_KEY'); $number='000000000003';
try {
    if (!function_exists('openssl_encrypt')) throw new RuntimeException('Fixture OpenSSL is required.');
    $row=array('id'=>120,'name'=>'Fixture worker','bank_name'=>'Fixture bank','account_holder'=>'Fixture worker','bank_account_hash'=>CryptoHelper::hashSensitive($number));
    putenv('CPMS_WORKER_CRYPTO_KEY=fixture-environment-material');
    $row['bank_account_enc']=account_cipher($number,'fixture-environment-material');
    account_assert((new Cpms2SensitiveExportService($root))->workerAccount($row)['account_number']===$number,'Environment-key decrypt failed.');
    account_assert(!file_exists($root.'/storage'),'Decrypt created a key directory.');
    $bad=$row; $bad['bank_account_hash']=hash('sha256','wrong-fixture');
    account_reject(function() use($root,$bad){ (new Cpms2SensitiveExportService($root))->workerAccount($bad); },'WORKER_ACCOUNT_HASH_MISMATCH');
    mkdir($root.'/storage/secrets',0700,true); $key=$root.'/storage/secrets/worker_crypto.key'; file_put_contents($key,'fixture-file-material');
    $before=array(hash_file('sha256',$key),filesize($key),filemtime($key));
    putenv('CPMS_WORKER_CRYPTO_KEY=wrong-fixture-material'); $row['bank_account_enc']=account_cipher($number,'fixture-file-material');
    account_assert((new Cpms2SensitiveExportService($root))->workerAccount($row)['account_number']===$number,'Existing key file was not tried after environment key.');
    clearstatcache(true,$key); account_assert($before===array(hash_file('sha256',$key),filesize($key),filemtime($key)),'Existing key file changed.');
    mkdir($root.'/app/config',0700,true); file_put_contents($root.'/app/config/database.php',"<?php return array('host'=>'fixture','dbname'=>'fixture_source','user'=>'fixture_user','password'=>'fixture-password-must-not-leak');");
    $fallback='cpms-worker-crypto-v1|'.$root.'|fixture|fixture_source|fixture_user';
    $row['bank_account_enc']=account_cipher($number,$fallback);
    account_assert((new Cpms2SensitiveExportService($root))->workerAccount($row)['account_number']===$number,'Existing fallback algorithm incompatible.');
    account_assert(hash_file('sha256',$key)===$before[0],'Fallback replaced existing key file.');
    unlink($key); rmdir($root.'/storage/secrets'); rmdir($root.'/storage');
    putenv('CPMS_WORKER_CRYPTO_KEY');
    account_assert((new Cpms2SensitiveExportService($root))->workerAccount($row)['account_number']===$number,'Fallback without key file failed.');
    account_assert(!is_dir($root.'/storage'),'Fallback generated key storage.');
    $row['bank_account_enc']='plain64:'.base64_encode('000-000-000003');
    account_assert((new Cpms2SensitiveExportService($root))->workerAccount($row)['account_number']===$number,'Plain64 or normalized hash support failed.');
    $bad=$row; $bad['bank_account_hash']=hash('sha256','another-fixture-account');
    account_reject(function() use($root,$bad){ (new Cpms2SensitiveExportService($root))->workerAccount($bad); },'WORKER_ACCOUNT_HASH_MISMATCH');
    $bad=$row; $bad['bank_account_enc']='aes256cbc:invalid';
    account_reject(function() use($root,$bad){ (new Cpms2SensitiveExportService($root))->workerAccount($bad); },'WORKER_ACCOUNT_DECRYPT_FAILED');
    $bad['bank_account_enc']='plain64:'.base64_encode('***003');
    account_reject(function() use($root,$bad){ (new Cpms2SensitiveExportService($root))->workerAccount($bad); },'WORKER_ACCOUNT_DECRYPT_FAILED');
    unset($bad['bank_account_enc']);
    account_reject(function() use($root,$bad){ (new Cpms2SensitiveExportService($root))->workerAccount($bad); },'WORKER_ACCOUNT_RECOVERY_SOURCE_MISSING');
    $plain=Cpms2SensitiveExportService::plainAccount(array('bank_name'=>'Fixture bank','account_number'=>'','bank_account'=>'000-000-000001','account_holder'=>'Fixture vendor'),'VENDORS_ACCOUNT_MISSING');
    account_assert($plain['account_number']==='000000000001','Vendor schema alias not exported.');
    account_assert(Cpms2SensitiveExportService::plainAccount(array('bank_name'=>'Fixture bank'),'DIRECT_TEAM_ACCOUNT_MISSING')['account_number']===null,'Partial direct-team metadata blocked.');
    $source=new Cpms2AccountFixtureSource();
    $source->tables['employees']=array(array('id'=>17,'employee_no'=>'FIX17','name'=>'Fixture employee','birth_date'=>'1980-01-01','hire_date'=>'2020-01-01','position'=>'과장'));
    $directory=$root.'/data/company_overhead/payroll_versions'; mkdir($directory.'/2020',0700,true); mkdir($directory.'/'.date('Y'),0700,true);
    $old=$directory.'/2020/01.json'; file_put_contents($old,json_encode(array('employees'=>array(array('employee_id'=>17,'name'=>'Fixture employee','bank_account'=>'000000000009')))));
    $path=$directory.'/'.date('Y').'/'.date('m').'.json';
    $payroll=array('employee_id'=>17,'name'=>'Fixture employee','bank_name'=>'Fixture bank','bank_account'=>'000000000004');
    file_put_contents($path,json_encode(array('employees'=>array($payroll),'resident_encrypted'=>'fixture-resident-must-not-leak')));
    $payrollHash=hash_file('sha256',$path); $service=new Cpms2SensitiveExportService($root);
    $mapped=$service->employeeAccounts($source);
    account_assert($mapped['accounts'][17]['account_number']==='000000000004' && $mapped['counts']['verified']===1,'Latest effective payroll employee ID mapping failed.');
    account_assert(hash_file('sha256',$path)===$payrollHash,'Payroll source file changed.');
    unset($payroll['employee_id']); $payroll['employee_no']='FIX17'; file_put_contents($path,json_encode(array('employees'=>array($payroll))));
    account_assert($service->employeeAccounts($source)['counts']['verified']===1,'Payroll employee number mapping failed.');
    unset($payroll['employee_no']); $payroll['employee_key']='EMP-'.substr(sha1('Fixture employee|1980-01-01|2020-01-01|과장'),0,16); file_put_contents($path,json_encode(array('employees'=>array($payroll))));
    account_assert($service->employeeAccounts($source)['counts']['verified']===1,'Existing parser employee key mapping failed.');
    $source->tables['employees'][]=$source->tables['employees'][0]; $source->tables['employees'][1]['id']=18;
    $conflict=$service->employeeAccounts($source); account_assert($conflict['failures'][0]['code']==='EMPLOYEE_PAYROLL_CONFLICT','Ambiguous payroll identity was merged.');
    array_pop($source->tables['employees']); unset($payroll['employee_key']); $payroll['no']='17'; file_put_contents($path,json_encode(array('employees'=>array($payroll))));
    account_assert($service->employeeAccounts($source)['failures'][0]['code']==='EMPLOYEE_PAYROLL_UNMAPPED','Name or worksheet row number used as employee identity.');
    $payroll['employee_id']=17; file_put_contents($path,json_encode(array('employees'=>array($payroll,$payroll))));
    account_assert($service->employeeAccounts($source)['counts']['failed']===1,'Duplicate payroll account accepted.');
    file_put_contents($path,json_encode(array('employees'=>array($payroll))));
    $source->tables['workers']=array($row,$bad); $source->tables['cpms_vendors']=array(array('id'=>31,'name'=>'Fixture vendor','bank_account'=>'000000000001')); $source->tables['direct_team_members']=array(array('id'=>125,'name'=>'Fixture direct','bank_account'=>'000000000002'));
    $report=$service->preflight($source);
    account_assert($report['counts']['workers']['source']===1 && $report['counts']['workers']['verified']===1 && $report['counts']['workers']['failed']===0 && $report['counts']['workers']['legacy_residue']===1 && $report['counts']['workers']['missing_number']===1,'Legacy residue counted as an account source or failure.');
    $safe=json_encode($report); foreach (array($number,'000000000004','fixture-password-must-not-leak','fixture-resident-must-not-leak',$fallback) as $secret) account_assert(strpos($safe,$secret)===false,'Sensitive value entered account preflight report.');
    $diagnostic=Cpms2ExportFailure::safe('workers',new RuntimeException('untrusted-secret-'.$number));
    account_assert($diagnostic->phase==='workers' && $diagnostic->getMessage()==='EXPORT_STAGE_FAILED','Untrusted diagnostic message exposed.');
    file_put_contents($path,'not-json'); account_assert($service->employeeAccounts($source)['failures'][0]['code']==='EMPLOYEE_PAYROLL_READ_FAILED','Unreadable payroll version silently skipped.');
    account_assert(!is_dir($root.'/storage'),'Account preflight created secret storage.');
    // Metadata alone is not an account source and remains available for export.
    foreach (array('bank_name','account_holder') as $field) {
        foreach (array('cpms_vendors'=>'vendors','workers'=>'workers','direct_team_members'=>'direct_team') as $table=>$entity) {
            $partial=array('id'=>120,'name'=>'Fixture partial',$field=>'Fixture metadata'); $partialSource=new Cpms2AccountFixtureSource(); $partialSource->tables[$table]=array($partial);
            $partialReport=(new Cpms2SensitiveExportService($root.'/absent'))->preflight($partialSource);
            account_assert($partialReport['counts'][$entity]['source']===0 && $partialReport['counts'][$entity]['failed']===0 && $partialReport['counts'][$entity]['partial_information']===1,'Metadata incorrectly blocked export.');
            $exported=$entity==='workers'?(new Cpms2SensitiveExportService($root))->workerAccount($partial):Cpms2SensitiveExportService::plainAccount($partial,'PARTIAL');
            account_assert($exported[$field]==='Fixture metadata' && $exported['account_number']===null,'Partial metadata lost.');
        }
    }
    $missing=(new Cpms2SensitiveExportService($root.'/absent'))->employeeAccounts(new Cpms2AccountFixtureSource());
    account_assert($missing['details']['status']==='EMPLOYEE_PAYROLL_SOURCE_NOT_FOUND' && !$missing['failures'],'Missing payroll source hidden or blocking.');
    file_put_contents($path,json_encode(array('employees'=>array(array('employee_id'=>17,'name'=>'Fixture employee')))));
    $zero=$service->employeeAccounts($source);
    account_assert($zero['details']['source_found'] && $zero['details']['employee_rows']===1 && $zero['details']['account_rows']===0 && $zero['details']['status']==='EMPLOYEE_PAYROLL_VERSION_FOUND','Payroll zero accounts confused with absent source.');
    stream_wrapper_register('readonly','Cpms2ReadonlyPayrollFixture');
    account_assert(is_readable('readonly://'.$directory) && !is_writable('readonly://'.$directory),'Read-only payroll fixture permissions incorrect.');
    file_put_contents($path,json_encode(array('employees'=>array($payroll))));
    $readonly=(new Cpms2SensitiveExportService('readonly://'.$root))->employeeAccounts($source);
    account_assert($readonly['counts']['verified']===1,'Readable non-writable payroll root skipped.');
    stream_wrapper_unregister('readonly');
    $storage=$root.'/fixture-storage'; $storageDirectory=$storage.'/company_overhead/payroll_versions/'.date('Y'); mkdir($storageDirectory,0700,true);
    $storagePath=$storageDirectory.'/'.date('m').'.json'; file_put_contents($storagePath,json_encode(array('employees'=>array($payroll))));
    $storageOnly=(new Cpms2SensitiveExportService($root.'/absent',$storage))->employeeAccounts($source);
    account_assert($storageOnly['counts']['verified']===1,'Storage payroll root skipped.');
    unlink($path);
    $both=(new Cpms2SensitiveExportService($root,$storage))->employeeAccounts($source);
    account_assert($both['details']['selected_month']===date('Y-m') && $both['accounts'][17]['account_number']==='000000000004','Latest storage version not chosen across both roots.');
    file_put_contents($path,json_encode(array('employees'=>array($payroll))));
    unlink($storagePath);
    account_assert((new Cpms2SensitiveExportService($root,$storage))->employeeAccounts($source)['details']['selected_month']===date('Y-m'),'Latest data version not chosen across both roots.');
    // Historical CryptoHelper __FILE__ and optional legacy files require hash validation.
    $row['bank_account_enc']=account_cipher($number,$root.'/app/services/CryptoHelper.php');
    account_assert((new Cpms2SensitiveExportService($root))->workerAccount($row)['account_number']===$number,'Historical __FILE__ fallback failed.');
    $noHash=$row; unset($noHash['bank_account_hash']);
    account_reject(function() use($root,$noHash){ (new Cpms2SensitiveExportService($root))->workerAccount($noHash); },'WORKER_ACCOUNT_DECRYPT_FAILED');
    mkdir($root.'/storage/secrets',0700,true);
    foreach (array('worker_crypto_legacy.key','worker_crypto_legacy_2020.key') as $name) {
        $legacyFile=$root.'/storage/secrets/'.$name; file_put_contents($legacyFile,'fixture-legacy-'.$name); $legacyHash=hash_file('sha256',$legacyFile);
        $row['bank_account_enc']=account_cipher($number,'fixture-legacy-'.$name);
        account_assert((new Cpms2SensitiveExportService($root))->workerAccount($row)['account_number']===$number,'Legacy file candidate failed.');
        account_assert(hash_file('sha256',$legacyFile)===$legacyHash && !is_file($root.'/storage/secrets/worker_crypto.key'),'Legacy key changed or current key created.');
    }
    $row['bank_account_enc']=account_cipher($number,'lost-fixture-key');
    $snapshots=new Cpms2AccountFixtureSource(); $snapshot=array('worker_id'=>120,'bank_account'=>'000-000-000003','bank_name'=>'Snapshot bank','account_holder'=>'Snapshot holder');
    $snapshots->tables['cpms_project_labor_workers']=array($snapshot); $method=null;
    account_assert($service->workerAccount($row,$snapshots,$method)['account_number']===$number && $method==='snapshot_recovered','Single snapshot with matching hash not recovered.');
    $snapshots->tables['cpms_project_labor_workers'][]=$snapshot;
    account_assert($service->workerAccount($row,$snapshots)['account_number']===$number,'Repeated project snapshots became conflict.');
    $other=$snapshot; $other['bank_account']='000000000099'; $snapshots->tables['cpms_project_labor_workers'][]=$other;
    account_assert($service->workerAccount($row,$snapshots)['account_number']===$number,'Unique hash-matching snapshot not selected.');
    $wrongHash=$row; $wrongHash['bank_account_hash']=hash('sha256','unmatched-fixture');
    account_reject(function() use($service,$wrongHash,$snapshots){ $service->workerAccount($wrongHash,$snapshots); },'WORKER_ACCOUNT_SNAPSHOT_CONFLICT');
    $noHash=$row; unset($noHash['bank_account_hash']);
    account_reject(function() use($service,$noHash,$snapshots){ $service->workerAccount($noHash,$snapshots); },'WORKER_ACCOUNT_SNAPSHOT_CONFLICT');
    $snapshots->tables['cpms_project_labor_workers']=array($other);
    account_reject(function() use($root,$row,$snapshots){ (new Cpms2SensitiveExportService($root))->workerAccount($row,$snapshots); },'WORKER_ACCOUNT_HASH_MISMATCH');
    $snapshots->tables['cpms_project_labor_workers']=array($snapshot); $snapshots->tables['cpms_project_labor_workers'][0]['worker_id']=999;
    account_assert((new Cpms2SensitiveExportService($root))->workerAccount($row,$snapshots)['account_number']===$number,'Unlinked global hash recovery failed.');
    $snapshots->tables['cpms_project_labor_workers']=array($snapshot); $snapshots->tables['workers']=array($row);
    $recoveryReport=$service->preflight($snapshots);
    account_assert($recoveryReport['counts']['workers']['snapshot_recovered']===1 && $recoveryReport['counts']['workers']['failed']===0,'Snapshot summary wrong.');
    ob_start(); $service->workerAccount($row,$snapshots); $console=ob_get_clean();
    $safe=json_encode($recoveryReport).$console.implode(' ',$snapshots->queries);
    foreach (array($number,$row['bank_account_enc'],$row['bank_account_hash'],'lost-fixture-key') as $secret) account_assert(strpos($safe,$secret)===false,'Snapshot account, cipher, hash or key leaked.');
    foreach (array('000-000-000001','000.000.000001','000/000/000001','Fixture bank (000000000001)') as $legacyNumber) {
        account_assert(Cpms2SensitiveExportService::accountNumber($legacyNumber)==='000000000001','Legacy vendor punctuation/label normalization failed.');
    }
    foreach (array('없음','미등록','현금','Fixture bank','123','***000003') as $unregistered) {
        $vendor=array('id'=>31,'name'=>'Fixture vendor','bank_name'=>'Fixture bank','account_number'=>$unregistered);
        account_assert(!Cpms2SensitiveExportService::hasSource($vendor) && Cpms2SensitiveExportService::plainAccount($vendor,'VENDORS_ACCOUNT_MISSING')['account_number']===null,'Unregistered vendor blocked or fabricated an account.');
    }
    $alias=array('account_number'=>'없음','bank_account'=>'Fixture bank 000.000.000001');
    account_assert(Cpms2SensitiveExportService::plainAccount($alias,'VENDORS_ACCOUNT_MISSING')['account_number']==='000000000001','Valid secondary account alias lost.');
    $global=new Cpms2AccountFixtureSource(); $unlinked=$snapshot; $unlinked['worker_id']=null;
    $global->tables['cpms_project_labor_workers']=array($unlinked,$unlinked); $globalService=new Cpms2SensitiveExportService($root);
    $method=null; account_assert($globalService->workerAccount($row,$global,$method)['account_number']===$number && $method==='snapshot_recovered','Global repeated hash match not recovered.');
    $another=$row; $another['id']=121;
    account_assert($globalService->workerAccount($another,$global)['account_number']===$number,'Global index not shared between workers.');
    $scans=0; foreach ($global->queries as $sql) if (strpos($sql,'WHERE bank_account IS NOT NULL')!==false) $scans++;
    account_assert($scans===1,'Global snapshots scanned once per worker instead of once per source.');
    $noHash=$row; unset($noHash['bank_account_hash']); $queryCount=count($global->queries);
    account_assert($globalService->workerAccount($noHash,$global)['account_number']===null,'Hashless orphan residue blocked.');
    account_assert(count($global->queries)===$queryCount+1,'Hashless worker used global snapshot search.');
    $missingGlobal=new Cpms2AccountFixtureSource(); $missingGlobal->tables['cpms_project_labor_workers']=array($other);
    $missingGlobal->tables['cpms_project_labor_workers'][0]['worker_id']=null;
    $residueMethod=null;
    account_assert((new Cpms2SensitiveExportService($root))->workerAccount($row,$missingGlobal,$residueMethod)['account_number']===null && $residueMethod==='legacy_residue','No-candidate encrypted residue blocked.');
    $global->tables['cpms_project_labor_workers'][]=array('bank_account'=>$other['bank_account'],'worker_id'=>null);
    account_reject(function() use($root,$row,$global){ (new Cpms2CollisionFixtureService($root))->workerAccount($row,$global); },'WORKER_ACCOUNT_SNAPSHOT_CONFLICT');
    $safe=json_encode($globalService->preflight($global)).implode(' ',$global->queries);
    foreach (array($number,$row['bank_account_enc'],$row['bank_account_hash'],'lost-fixture-key') as $secret) account_assert(strpos($safe,$secret)===false,'Global recovery leaked sensitive material.');
    $orphanSource=new Cpms2AccountFixtureSource(); $orphanSource->tables['cpms_project_labor_workers']=array(array('worker_id'=>120,'bank_account'=>'미등록'));
    foreach (array(array('bank_account_enc'=>'aes256cbc:orphan-fixture','bank_account_hash'=>hash('sha256','orphan-fixture')),array('bank_account_enc'=>'aes256cbc:orphan-fixture'),array('bank_account_hash'=>hash('sha256','orphan-fixture'))) as $trace) {
        $orphan=array_merge(array('id'=>120,'name'=>'Fixture orphan','bank_name'=>'Fixture bank','account_holder'=>'Fixture holder'),$trace);
        $method=null; $account=(new Cpms2SensitiveExportService($root))->workerAccount($orphan,$orphanSource,$method);
        account_assert($account['account_number']===null && $account['bank_name']==='Fixture bank' && $account['account_holder']==='Fixture holder' && $method==='legacy_residue','Residue lost partial metadata or generated a number.');
    }
    $orphanSource->tables['workers']=array($orphan,array('id'=>444,'name'=>'Fixture unregistered'),array('id'=>555,'name'=>'Fixture partial','bank_name'=>'Fixture bank'));
    $orphanReport=(new Cpms2SensitiveExportService($root.'/absent'))->preflight($orphanSource);
    account_assert(!$orphanReport['failures'] && $orphanReport['counts']['workers']['source']===0 && $orphanReport['counts']['workers']['missing_number']===3 && $orphanReport['counts']['workers']['legacy_residue']===1 && $orphanReport['counts']['workers']['partial_information']===2,'Residue versus normal missing summary incorrect.');
    $plainMismatch=$row; $plainMismatch['account_number']='000000000099';
    account_reject(function() use($root,$plainMismatch,$orphanSource){ (new Cpms2SensitiveExportService($root))->workerAccount($plainMismatch,$orphanSource); },'WORKER_ACCOUNT_HASH_MISMATCH');
    $plainMatch=$row; $plainMatch['bank_account']='000-000-000003';
    account_assert((new Cpms2SensitiveExportService($root))->workerAccount($plainMatch,$orphanSource)['account_number']===$number,'Existing valid plaintext candidate discarded as residue.');
    $hashOnly=$row; unset($hashOnly['bank_account_enc']);
    account_assert((new Cpms2SensitiveExportService($root))->workerAccount($hashOnly,$global)['account_number']===$number,'Hash-only trace did not recover its real global candidate.');
    $orphanSource->tables['cpms_project_labor_workers']=array($other);
    account_reject(function() use($root,$orphan,$orphanSource){ (new Cpms2SensitiveExportService($root))->workerAccount($orphan,$orphanSource); },'WORKER_ACCOUNT_HASH_MISMATCH');
    $orphanSource->tables['cpms_project_labor_workers'][]=$snapshot;
    account_reject(function() use($root,$orphan,$orphanSource){ (new Cpms2SensitiveExportService($root))->workerAccount($orphan,$orphanSource); },'WORKER_ACCOUNT_SNAPSHOT_CONFLICT');
    account_reject(function() use($root,$row){ (new Cpms2SensitiveExportService($root))->workerAccount($row,new Cpms2UnavailableAccountFixtureSource()); },'fixture-source-unavailable');
    $legacyUnverified=$row; unset($legacyUnverified['bank_account_hash']); $legacyUnverified['bank_account_enc']=account_cipher($number,$root.'/app/services/CryptoHelper.php');
    account_reject(function() use($root,$legacyUnverified,$missingGlobal){ (new Cpms2SensitiveExportService($root))->workerAccount($legacyUnverified,$missingGlobal); },'WORKER_ACCOUNT_DECRYPT_FAILED');
    $safe=json_encode($orphanReport); foreach (array($number,'orphan-fixture',$orphan['bank_account_hash']) as $secret) account_assert(strpos($safe,$secret)===false,'Residue report leaked account/key/hash data.');
    // Latest file is empty: select the latest populated version from both roots.
    file_put_contents($path,json_encode(array('employees'=>array())));
    $previous=$storage.'/company_overhead/payroll_versions/2021'; mkdir($previous,0700,true);
    file_put_contents($previous.'/02.json',json_encode(array('employees'=>array($payroll))));
    $fallbackPayroll=(new Cpms2SensitiveExportService($root,$storage))->employeeAccounts($source);
    account_assert($fallbackPayroll['details']['latest_month']===date('Y-m') && $fallbackPayroll['details']['latest_employee_rows']===0 && $fallbackPayroll['details']['selected_month']==='2021-02' && $fallbackPayroll['accounts'][17]['account_number']==='000000000004','Empty latest payroll lost populated earlier version.');
    $newAccount=$payroll; $newAccount['bank_account']='000000000005';
    file_put_contents($path,json_encode(array('employees'=>array($newAccount))));
    account_assert((new Cpms2SensitiveExportService($root,$storage))->employeeAccounts($source)['accounts'][17]['account_number']==='000000000005','Older account overrode current populated payroll version.');
    unset($newAccount['bank_account']); file_put_contents($path,json_encode(array('employees'=>array($newAccount))));
    $noCurrentAccount=(new Cpms2SensitiveExportService($root,$storage))->employeeAccounts($source);
    account_assert($noCurrentAccount['details']['selected_month']===date('Y-m') && $noCurrentAccount['details']['account_rows']===0 && $noCurrentAccount['accounts'][17]['account_number']===null,'Current zero-account payroll incorrectly resurrected an older account.');
    $onlyEmpty=$root.'/empty-payroll'; mkdir($onlyEmpty.'/data/company_overhead/payroll_versions/2022',0700,true);
    file_put_contents($onlyEmpty.'/data/company_overhead/payroll_versions/2022/01.json',json_encode(array('employees'=>array())));
    $empty=(new Cpms2SensitiveExportService($onlyEmpty))->employeeAccounts($source);
    account_assert($empty['details']['status']==='EMPLOYEE_PAYROLL_EMPTY_VERSIONS' && $empty['details']['selected_month']==='' && !$empty['failures'],'All-empty payroll versions confused with missing source.');
} finally { putenv($originalEnvironment===false?'CPMS_WORKER_CRYPTO_KEY':'CPMS_WORKER_CRYPTO_KEY='.$originalEnvironment); account_remove_fixture($root); }
echo 'PASS: '.$checks." read-only account/key/payroll/conflict/privacy checks\n";
