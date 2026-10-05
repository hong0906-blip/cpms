<?php
// tests/cpms2_web_export_test.php
// No application bootstrap, production connection or source files.
require_once dirname(__DIR__).'/app/controllers/Cpms2ExportController.php';
class Cpms2WebFixtureStatement
{
    private $sql; private $rows=array(); private $index=0;
    public function __construct($sql) { $this->sql=$sql; }
    public function execute($params)
    {
        if (strpos($this->sql,'information_schema.TABLES')!==false) $this->rows=array(array(1));
        elseif (strpos($this->sql,'SHOW COLUMNS')===0) foreach (array('id','email','name','department','role','is_active') as $column) $this->rows[]=array('Field'=>$column);
        else {
            $people=array(
                17=>array('id'=>17,'name'=>'Fixture manager','email'=>'manager@example.invalid','department'=>'관리팀','role'=>'employee','is_active'=>1),
                18=>array('id'=>18,'name'=>'Fixture reader','email'=>'reader@example.invalid','department'=>'공사','role'=>'employee','is_active'=>1),
                19=>array('id'=>19,'name'=>'Fixture retired','email'=>'retired@example.invalid','department'=>'개발','role'=>'executive','is_active'=>0),
                20=>array('id'=>20,'name'=>'Fixture master','email'=>'master@example.invalid','department'=>'개발부','role'=>'employee','is_active'=>1)
            );
            if (isset($people[$params[0]]) && strtolower($people[$params[0]]['email'])===strtolower($params[1])) $this->rows=array($people[$params[0]]);
        }
    }
    public function fetch($mode=null) { return isset($this->rows[$this->index])?$this->rows[$this->index++]:false; }
    public function fetchColumn() { $row=$this->fetch(); return $row?reset($row):false; }
}
class Cpms2WebFixturePdo
{
    public $queries=array();
    public function prepare($sql) { $this->queries[]=$sql; return new Cpms2WebFixtureStatement($sql); }
}
class Cpms2WebDiagnosticFixture extends Cpms2WebExportService
{
    public function generate($employee) { throw new Cpms2ExportFailure('workers','WORKER_ACCOUNT_DECRYPT_FAILED'); }
}
class Cpms2WebManagementFixture extends Cpms2WebExportService
{
    public $managementCalls=0;
    public function managementPreflight()
    {
        $this->managementCalls++;
        return array('audit'=>array('Attendance'=>array('status'=>'BLOCKING','blocking_count'=>1,'warning_count'=>0)),'blocking_count'=>1,'warning_count'=>0,'issues'=>array(),'Attendance'=>array(),'Leave'=>array(),'Overhead'=>array());
    }
}
$checks=0;
function cpms_web_assert($ok,$message) { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
function cpms_web_reject($call,$message) { try { $call(); } catch (Exception $e) { cpms_web_assert(true,$message); return; } throw new RuntimeException($message); }
$root=sys_get_temp_dir().'/cpms-web-fixture-'.uniqid(); mkdir($root,0700); mkdir($root.'/public',0700); mkdir($root.'/private',0700);
$pdo=new Cpms2WebFixturePdo(); $source=new Cpms2ReadOnlySource($pdo);
$storage=$root.'/public/storage/exports/cpms2';
$web=new Cpms2WebExportService($source,null,$root,$root,$storage,$root.'/public');
$manager=array('cpms_user'=>array('id'=>17,'email'=>'manager@example.invalid'),'_csrf'=>'fixture-csrf');
try {
    cpms_web_reject(function() use($web){ $web->authorize(array()); },'Unauthenticated export accepted.');
    cpms_web_reject(function() use($web){ $web->authorize(array('cpms_user'=>array('id'=>18,'email'=>'reader@example.invalid','role'=>'executive','department'=>'개발'))); },'Stale elevated session accepted.');
    cpms_web_reject(function() use($web){ $web->authorize(array('cpms_user'=>array('id'=>19,'email'=>'retired@example.invalid'))); },'Inactive employee accepted.');
    cpms_web_reject(function() use($web){ $web->authorize(array('cpms_user'=>array('id'=>17,'email'=>'wrong@example.invalid'))); },'Session employee/email mismatch accepted.');
    $employee=$web->authorize($manager); cpms_web_assert($employee['id']===17,'Current management permission denied.');
    cpms_web_assert($web->authorize(array('cpms_user'=>array('id'=>20,'email'=>'master@example.invalid')))['id']===20,'Current master permission denied.');
    cpms_web_assert($web->checkedPrivateRoot()===realpath($root.'/public').'/storage/exports/cpms2','Storage inside document root rejected.');
    cpms_web_assert(!is_dir($storage),'Storage validation created a directory.');
    cpms_web_assert($web->checkedPrivateRoot(true)===realpath($storage),'Nested export storage not created.');
    $unknown=new Cpms2WebExportService($source,null,$root,$root,$storage,'');
    cpms_web_assert($unknown->checkedPrivateRoot()===realpath($storage),'Storage requires a document root.');
    $empty=new Cpms2WebExportService($source,null,$root,$root,'',$root.'/public');
    cpms_web_reject(function() use($empty){ $empty->checkedPrivateRoot(true); },'Empty storage path accepted.');
    $id=str_repeat('a',48); $file=$storage.'/'.$id.'.zip'; file_put_contents($file,'fixture-package');
    $notDirectory=new Cpms2WebExportService($source,null,$root,$root,$file,$root.'/public');
    cpms_web_reject(function() use($notDirectory){ $notDirectory->checkedPrivateRoot(true); },'File accepted as storage directory.');
    $package=array('id'=>$id,'owner_employee_id'=>17,'size'=>filesize($file),'sha256'=>hash_file('sha256',$file),'summary'=>array('exported_file_count'=>1,'missing_file_count'=>0,'deduplicated_file_count'=>1,'record_counts'=>array('employees'=>1),'warnings'=>array('<script>fixture</script>')));
    cpms_web_assert($web->downloadPath($id,$package,$employee)===realpath($file),'Owned ZIP download inside document root denied.');
    cpms_web_reject(function() use($web,$id,$employee){ $web->downloadPath($id,null,$employee); },'Unregistered ZIP download accepted.');
    cpms_web_reject(function() use($web,$id,$package){ $web->downloadPath($id,$package,array('id'=>20)); },'Another administrator downloaded an unowned ZIP.');
    cpms_web_reject(function() use($web,$package,$employee){ $web->downloadPath('../package',$package,$employee); },'Download path traversal accepted.');
    cpms_shared_session_start(); $_SESSION=$manager; $_SERVER['REQUEST_METHOD']='GET'; $_GET['r']='admin/cpms2_export'; $_SERVER['SCRIPT_NAME']='/public/index.php';
    $_SESSION['_cpms2_export_latest']=$id; $_SESSION['_cpms2_export_packages']=array($id=>$package);
    ob_start(); (new Cpms2ExportController($web))->handle(); $html=ob_get_clean();
    cpms_web_assert(strpos($html,'ZIP 다운로드')!==false && strpos($html,'disabled')!==false,'Web ready state or preflight gate missing.');
    cpms_web_assert(strpos($html,'<script>fixture</script>')===false && strpos($html,'&lt;script&gt;fixture&lt;/script&gt;')!==false,'Report HTML not escaped.');
    $excluded=array('count'=>8,'amount'=>'23500000.00','projects'=>array(22=>array('count'=>8,'amount'=>'23500000.00','months'=>array('2026-07'=>array('count'=>8,'amount'=>'23500000.00')))));
    $exclusionWarnings=Cpms2LaborExportService::exclusionWarnings($excluded);
    $_SESSION['_cpms2_export_preflight']=array('owner_employee_id'=>17,'checked_at'=>time(),'report'=>array('counts'=>array(),'expected_file_count'=>0,'missing_file_count'=>0,'can_export'=>true,'excluded_labor_force_adjustments'=>$excluded,'warnings'=>$exclusionWarnings));
    $_SESSION['_cpms2_export_packages'][$id]['summary']['warnings']=$exclusionWarnings;
    ob_start(); (new Cpms2ExportController($web))->handle(); $html=ob_get_clean();
    cpms_web_assert(substr_count($html,'노무비 강제입력 8건 / 총 23,500,000.00원')===2,'Preflight and generated summary exclusion total missing.');
    cpms_web_assert(substr_count($html,'프로젝트 #22 / 2026-07 / 8건')===2 && strpos($html,' disabled')===false,'Project/month warning missing or exclusions disabled Export.');
    $_SESSION['_cpms2_export_preflight']['report']['can_export']=false;
    $_SESSION['_cpms2_export_preflight']['report']['accounts']=array('counts'=>array('workers'=>array('source'=>183,'verified'=>181,'failed'=>2)),'failures'=>array(array('entity'=>'workers','legacy_id'=>'120','name'=>'Fixture worker','code'=>'WORKER_ACCOUNT_HASH_MISMATCH')));
    ob_start(); (new Cpms2ExportController($web))->handle(); $html=ob_get_clean();
    cpms_web_assert(strpos($html,'근로자 계좌번호 183건 중 181건 확인 / 2건 실패')!==false && strpos($html,' disabled')!==false,'Account failure did not disable Export or show safe counts.');
    cpms_web_assert(strpos($html,'WORKER_ACCOUNT_HASH_MISMATCH')!==false && strpos($html,'bank_account_enc')===false,'Safe account failure reason missing.');
    $_SESSION['_cpms2_export_preflight']['report']['attendance_leave']=array('cutoff_date'=>'2026-10-05','record_counts'=>array('attendance_records'=>1,'attendance_requests'=>1,'leave_balance_snapshots'=>1,'leave_approval_deductions'=>89),'attendance'=>array('missing_checkout_original'=>0,'reversed_normalized'=>0),'accrual'=>array('original'=>1,'explicit_orphan_excluded'=>0,'excluded_amount'=>'0.00','zero_confirmation'=>0),'leave_document_conflicts'=>array(array('deduction_id'=>80,'document_id'=>123,'employee_id'=>17,'reason_code'=>'LEAVE_BUCKET_HIRE_DATE_MISMATCH','safe_detail'=>array('deduction_leave_bucket'=>'monthly','expected_leave_bucket'=>'annual','hire_date'=>'2020-10-04','leave_start_date'=>'2026-07-01','unsafe_name'=>'PRIVATE_CONFLICT_NAME'))));
    $_SESSION['_cpms2_export_preflight']['report']['failures']=array(array('entity'=>'attendance','legacy_id'=>null,'code'=>'LEGACY_LEAVE_DOCUMENT_CONFLICT'));
    ob_start(); (new Cpms2ExportController($web))->handle(); $html=ob_get_clean();
    cpms_web_assert(strpos($html,'근태·연차 휴가문서 충돌 1건')!==false && strpos($html,'문서 #123 / 차감 #80 / 직원 #17')!==false && strpos($html,'LEAVE_BUCKET_HIRE_DATE_MISMATCH')!==false && strpos($html,'예상 버킷=annual')!==false,'Leave document conflict details missing.');
    cpms_web_assert(strpos($html,'PRIVATE_CONFLICT_NAME')===false && strpos($html,'attendance #')===false && strpos($html,' disabled')!==false,'Leave conflict leaked unsafe detail or failed to block Export.');
    unset($_SESSION['_cpms2_export_preflight']['report']['attendance_leave']); $_SESSION['_cpms2_export_preflight']['report']['failures']=array();
    $accountReport=array('counts'=>array('workers'=>array_merge(Cpms2SensitiveExportService::counts(),array('source'=>452,'verified'=>452,'decrypted'=>440,'snapshot_recovered'=>12,'missing_number'=>40,'partial_information'=>5))),'failures'=>array(),'payroll'=>array('source_found'=>false,'selected_month'=>'','employee_rows'=>0,'account_rows'=>0,'mapping_success'=>0,'mapping_failed'=>0,'status'=>'EMPLOYEE_PAYROLL_SOURCE_NOT_FOUND'));
    $_SESSION['_cpms2_export_preflight']['report']['accounts']=$accountReport; $_SESSION['_cpms2_export_preflight']['report']['can_export']=true;
    $_SESSION['_cpms2_export_packages'][$id]['summary']['account_preflight']=$accountReport;
    ob_start(); (new Cpms2ExportController($web))->handle(); $html=ob_get_clean();
    cpms_web_assert(substr_count($html,'암호화 직접 복호화 440건 · 과거 노무 Snapshot 복구 12건')===2,'Preflight or Export summary omitted recovery counts.');
    cpms_web_assert(substr_count($html,'EMPLOYEE_PAYROLL_SOURCE_NOT_FOUND')===2 && strpos($html,' disabled')===false,'Missing payroll source hidden or blocking.');
    cpms_web_assert(strpos($html,'계좌번호 미등록 40건 · 그중 은행명/예금주만 있음 5건 (Non-blocking)')!==false,'Partial account summary missing.');
    $_SESSION['_cpms2_export_preflight']['report']['accounts']['payroll']=array('source_found'=>true,'selected_month'=>'2026-09','employee_rows'=>8,'account_rows'=>0,'mapping_success'=>0,'mapping_failed'=>0,'status'=>'EMPLOYEE_PAYROLL_VERSION_FOUND');
    ob_start(); (new Cpms2ExportController($web))->handle(); $html=ob_get_clean();
    cpms_web_assert(strpos($html,'계좌 Migration Source: 2026-09 / 직원 8명 / 계좌 0건')!==false,'Payroll version with zero accounts hidden.');
    $payrollVersion=array('source_found'=>true,'latest_month'=>'2026-06','latest_employee_rows'=>0,'latest_account_rows'=>0,'selected_month'=>'2026-05','employee_rows'=>41,'account_rows'=>41,'mapping_success'=>41,'mapping_failed'=>0,'status'=>'EMPLOYEE_PAYROLL_VERSION_FOUND');
    $_SESSION['_cpms2_export_preflight']['report']['accounts']['payroll']=$payrollVersion;
    $_SESSION['_cpms2_export_packages'][$id]['summary']['account_preflight']['payroll']=$payrollVersion;
    ob_start(); (new Cpms2ExportController($web))->handle(); $html=ob_get_clean();
    cpms_web_assert(substr_count($html,'최신 Payroll Version: 2026-06 / 직원 0명')===2 && substr_count($html,'계좌 Migration Source: 2026-05 / 직원 41명 / 계좌 41건')===2,'Preflight or summary hid empty latest versus selected payroll version.');
    $_SESSION['_cpms2_export_preflight']['report']['accounts']['counts']['workers']['missing_number']=259;
    $_SESSION['_cpms2_export_preflight']['report']['accounts']['counts']['workers']['legacy_residue']=12;
    $_SESSION['_cpms2_export_packages'][$id]['summary']['account_preflight']['counts']['workers']=$_SESSION['_cpms2_export_preflight']['report']['accounts']['counts']['workers'];
    ob_start(); (new Cpms2ExportController($web))->handle(); $html=ob_get_clean();
    cpms_web_assert(substr_count($html,'계좌번호 미등록 259건')===2 && substr_count($html,'일반 미등록 247건 · 암호화 흔적만 존재 / 실제 계좌 미등록 12건')===2 && strpos($html,' disabled')===false,'Residue summary hidden or disabled Export.');
    $_SESSION['_cpms2_export_preflight']['report']['can_export']=false;
    $_SERVER['REQUEST_METHOD']='POST'; $_POST=array('action'=>'generate','_csrf'=>'fixture-csrf');
    $previousLog=ini_get('error_log'); $log=$root.'/private/diagnostic.log'; ini_set('error_log',$log);
    ob_start(); (new Cpms2ExportController($web))->handle(); $html=ob_get_clean();
    cpms_web_assert(http_response_code()===422 && strpos($html,'ACCOUNT_PREFLIGHT_FAILED')!==false,'Direct generation bypassed failed account preflight.');
    $_SESSION['_cpms2_export_preflight']['report']['can_export']=true;
    $diagnostic=new Cpms2WebDiagnosticFixture($source,null,$root,$root,$storage,$root.'/public');
    ob_start(); (new Cpms2ExportController($diagnostic))->handle(); $html=ob_get_clean();
    cpms_web_assert(strpos($html,'실패 단계: workers')!==false && strpos($html,'오류코드: WORKER_ACCOUNT_DECRYPT_FAILED')!==false,'Safe worker failure phase/code not displayed.');
    $logged=file_get_contents($log);
    cpms_web_assert(strpos($logged,'phase=workers code=WORKER_ACCOUNT_DECRYPT_FAILED')!==false && strpos($logged,'account_number')===false && strpos($logged,'bank_account_enc')===false,'Safe diagnostic log missing or leaked account payload.');
    ini_set('error_log',$previousLog); unlink($log);
    $managementWeb=new Cpms2WebManagementFixture($source,null,$root,$root,$storage,$root.'/public');
    $prior=$_SESSION['_cpms2_export_preflight'];
    $_SERVER['REQUEST_METHOD']='POST'; $_POST=array('action'=>'management_preflight','_csrf'=>'bad');
    ob_start(); (new Cpms2ExportController($managementWeb))->handle(); ob_end_clean();
    cpms_web_assert(http_response_code()===403 && $managementWeb->managementCalls===0,'Management CSRF bypass.');
    $_SESSION=$manager; $_SESSION['cpms_user']=array('id'=>18,'email'=>'reader@example.invalid'); $_POST['_csrf']='fixture-csrf';
    ob_start(); (new Cpms2ExportController($managementWeb))->handle(); ob_end_clean();
    cpms_web_assert(http_response_code()===403 && $managementWeb->managementCalls===0,'Management unauthorized access.');
    $_SESSION=$manager; $_SESSION['_cpms2_export_preflight']=$prior; $_SESSION['_cpms2_export_packages'][$id]=$package; $_SESSION['_cpms2_export_latest']=$id;
    ob_start(); (new Cpms2ExportController($managementWeb))->handle(); $html=ob_get_clean();
    cpms_web_assert($managementWeb->managementCalls===1 && strpos($html,'관리부 Migration 사전검사')!==false && strpos($html,'진단 상세 · 전달용 결과')!==false,'Management report not rendered.');
    cpms_web_assert($_SESSION['_cpms2_export_preflight']===$prior && !isset($_SESSION['_cpms2_management_report']),'Management changed Export eligibility or persisted source report.');
    cpms_web_assert(strpos($html,' disabled')===false,'Diagnostic blocked legacy Export button.');
    $partial=file_get_contents(dirname(__DIR__).'/app/views/admin/partials/cpms2_management_preflight.php'); $managementGuide=file_get_contents(dirname(__DIR__).'/public/assets/js/guide-tour.js');
    foreach (array('management-preflight','management-results') as $key) cpms_web_assert(substr_count($partial,'data-guide="admin-cpms2-'.$key.'"')===1 && substr_count($managementGuide,'data-guide="admin-cpms2-'.$key.'"')===1,'Management Guide missing or duplicate.');
    $_SERVER['REQUEST_METHOD']='POST'; $_POST=array('action'=>'download','package'=>$id,'_csrf'=>'bad');
    http_response_code(200); ob_start(); (new Cpms2ExportController($web))->handle(); $response=ob_get_clean();
    cpms_web_assert(http_response_code()===403 && strpos($response,'fixture-package')===false,'Invalid CSRF downloaded data.');
    $_POST['_csrf']='fixture-csrf'; http_response_code(200); ob_start(); (new Cpms2ExportController($web))->handle(); $response=ob_get_clean();
    cpms_web_assert($response==='fixture-package','Authenticated CSRF-protected streamed download failed.');
    file_put_contents($file,'changed-package'); clearstatcache(true,$file);
    cpms_web_assert(filesize($file)===$package['size'],'Tamper fixture must preserve ZIP size.');
    cpms_web_reject(function() use($web,$id,$package,$employee){ $web->downloadPath($id,$package,$employee); },'Changed ZIP downloaded.');
    foreach ($pdo->queries as $sql) { Cpms2ReadOnlySource::assertReadOnly($sql); cpms_web_assert(!preg_match('/INSERT|UPDATE|DELETE|CREATE|ALTER|DROP/i',$sql),'Web source write detected.'); }
    $entry=file_get_contents(dirname(__DIR__).'/public/index.php');
    cpms_web_assert(strpos($entry,'Cpms2ExportController::dispatch()')<strpos($entry,"require_once __DIR__ . '/../app/bootstrap.php'"),'Export entered writing bootstrap.');
    $view=file_get_contents(dirname(__DIR__).'/app/views/admin/cpms2_export.php'); $guide=file_get_contents(dirname(__DIR__).'/public/assets/js/guide-tour.js');
    foreach (array('preflight','generate','download') as $key) {
        cpms_web_assert(substr_count($view,'data-guide="admin-cpms2-'.$key.'"')===1 && substr_count($guide,'data-guide="admin-cpms2-'.$key.'"')===1,'Guide target missing or duplicated.');
    }
    cpms_web_assert(substr_count($guide,'data-guide="admin-cpms2-open"')===1,'Admin Export link Guide missing.');
} finally {
    if (isset($file) && is_file($file)) unlink($file);
    if (isset($log) && is_file($log)) unlink($log);
    foreach (array($storage,$root.'/public/storage/exports',$root.'/public/storage',$root.'/private',$root.'/public',$root) as $directory) if (is_dir($directory)) rmdir($directory);
}
echo 'PASS: '.$checks." CPMS2 Web Export auth/CSRF/private storage/streaming/Guide checks\n";
