<?php
// tests/cpms2_sensitive_export_test.php
// PHP 5.6. Fixture keys, JSON and accounts only; no application bootstrap.
require_once dirname(__DIR__).'/app/services/Cpms2SensitiveExportService.php';
require_once dirname(__DIR__).'/app/services/Cpms2ExportDiagnostic.php';
date_default_timezone_set('Asia/Seoul');
$checks=0;
function account_assert($ok,$label) { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }
function account_reject($call,$code) { try { $call(); } catch (RuntimeException $e) { account_assert($e->getMessage()===$code,'Unsafe or incorrect failure code.'); return; } throw new RuntimeException('Invalid account accepted.'); }
function account_cipher($number,$material) { $iv=str_repeat('i',16); return 'aes256cbc:'.base64_encode($iv.openssl_encrypt($number,'AES-256-CBC',hash('sha256',$material,true),OPENSSL_RAW_DATA,$iv)); }
class Cpms2AccountFixtureSource
{
    public $tables=array();
    public function rows($table,$fields) { if (isset($this->tables[$table])) foreach ($this->tables[$table] as $row) yield array_intersect_key($row,array_flip($fields)); }
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
    account_reject(function() use($root,$bad){ (new Cpms2SensitiveExportService($root))->workerAccount($bad); },'WORKER_ACCOUNT_MISSING');
    $plain=Cpms2SensitiveExportService::plainAccount(array('bank_name'=>'Fixture bank','account_number'=>'','bank_account'=>'000-000-000001','account_holder'=>'Fixture vendor'),'VENDORS_ACCOUNT_MISSING');
    account_assert($plain['account_number']==='000000000001','Vendor schema alias not exported.');
    account_reject(function(){ Cpms2SensitiveExportService::plainAccount(array('bank_name'=>'Fixture bank'),'DIRECT_TEAM_ACCOUNT_MISSING'); },'DIRECT_TEAM_ACCOUNT_MISSING');
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
    account_assert($report['counts']['workers']===array('source'=>2,'verified'=>1,'failed'=>1),'Blocking worker count summary incorrect.');
    $safe=json_encode($report); foreach (array($number,'000000000004','fixture-password-must-not-leak','fixture-resident-must-not-leak',$fallback) as $secret) account_assert(strpos($safe,$secret)===false,'Sensitive value entered account preflight report.');
    $diagnostic=Cpms2ExportFailure::safe('workers',new RuntimeException('untrusted-secret-'.$number));
    account_assert($diagnostic->phase==='workers' && $diagnostic->getMessage()==='EXPORT_STAGE_FAILED','Untrusted diagnostic message exposed.');
    file_put_contents($path,'not-json'); account_assert($service->employeeAccounts($source)['failures'][0]['code']==='EMPLOYEE_PAYROLL_READ_FAILED','Unreadable payroll version silently skipped.');
    account_assert(!is_dir($root.'/storage'),'Account preflight created secret storage.');
} finally { putenv($originalEnvironment===false?'CPMS_WORKER_CRYPTO_KEY':'CPMS_WORKER_CRYPTO_KEY='.$originalEnvironment); account_remove_fixture($root); }
echo 'PASS: '.$checks." read-only account/key/payroll/conflict/privacy checks\n";
