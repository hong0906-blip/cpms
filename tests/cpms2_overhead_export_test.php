<?php
// tests/cpms2_overhead_export_test.php
// PHP 5.6, isolated files and a read-only source spy only.
require_once dirname(__DIR__).'/app/services/Cpms2ReadOnlySource.php';
require_once dirname(__DIR__).'/app/services/Cpms2OverheadMigrationExportService.php';
date_default_timezone_set('Asia/Seoul');
class OverheadFixtureStatement
{
    private $rows; private $index=0;
    public function __construct($rows) { $this->rows=$rows; }
    public function fetch($mode=null) { return isset($this->rows[$this->index])?$this->rows[$this->index++]:false; }
}
class OverheadFixtureSource extends Cpms2ReadOnlySource
{
    public $queries=array();
    public function __construct() {}
    public function columns($table) { return $table==='employees'?array('id','name'):array(); }
    public function query($sql,$params=array()) { Cpms2ReadOnlySource::assertReadOnly($sql); $this->queries[]=$sql; return new OverheadFixtureStatement(array(array('id'=>1,'name'=>'Fixture Driver'))); }
}
$checks=0;
function overhead_assert($ok,$message) { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
function overhead_json($root,$relative,$data) { $path=$root.'/'.$relative; if (!is_dir(dirname($path))) mkdir(dirname($path),0700,true); file_put_contents($path,json_encode($data)); }
function overhead_files($root) { $out=array(); $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)); foreach ($it as $file) if ($file->isFile()) $out[$file->getPathname()]=hash_file('sha256',$file->getPathname()); ksort($out); return $out; }
function overhead_remove($root) { if (!is_dir($root)) return; $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST); foreach ($it as $file) { if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname()); } rmdir($root); }
function overhead_reject($call,$message) { try { $call(); } catch (Exception $e) { overhead_assert(true,$message); return; } throw new RuntimeException($message); }
$root=sys_get_temp_dir().'/cpms-overhead-export-'.uniqid(); $private=$root.'/private'; mkdir($private,0700,true); $data=$root.'/data/company_overhead';
try {
    overhead_json($data,'payroll_versions/2026/01.json',array('employees'=>array(array('employee_id'=>1,'resident_number'=>'SECRET_RESIDENT','resident_enc'=>'SECRET_CIPHER','account_number'=>'SECRET_ACCOUNT')),'total_net_pay'=>'50.00'));
    overhead_json($data,'payroll_manual_totals/2026/01.json',array('amount'=>'90.00'));
    overhead_json($data,'payroll/2026/02.json',array('items'=>array(array('id'=>'p2','amount'=>'80.00'))));
    overhead_json($root,'data/archive_summary/2026.json',array('company_overhead'=>array('category_monthly'=>array('payroll'=>array('02'=>'80.00')))));
    overhead_json($data,'company_vehicles/vehicles.json',array('vehicles'=>array(array('id'=>'v1','vehicle_name'=>'업무차량','vehicle_number'=>'11가1234','driver_employee_id'=>1,'driver_name'=>'Fixture Driver','finance_start'=>'2026-01-01','finance_end'=>'2026-03-31','monthly_payment'=>'10.00','monthly_payment_changes'=>array(array('effective_ym'=>'2026-02','amount'=>'20.00')),'driver_changes'=>array()))));
    overhead_json($data,'vehicles/2026/01.json',array('items'=>array(array('id'=>'vm1','vehicle_name'=>'추가차량','vehicle_number'=>'22나2345','amount'=>'5.00'))));
    overhead_json($data,'lease/2026/01.json',array('items'=>array(array('id'=>'l1','lease_name'=>'본사','amount'=>'100.00','maintenance_fee'=>'20.00','deposit'=>'5000.00'))));
    overhead_json($data,'corporate_cards/2026/01.json',array(array('id'=>'c1','amount'=>'100.00','card_number'=>'1234567890123456','supply_amount'=>'90.00','tax_amount'=>'10.00'),array('id'=>'c2','amount'=>'-30.00','card_number'=>'9999888877776666','supply_amount'=>'-27.00','tax_amount'=>'-3.00')));
    overhead_json($data,'fuel/2026/01.json',array('items'=>array(array('id'=>'f1','vehicle_number'=>'11가1234','supply_amount'=>'100.00','vat'=>'10.00','total_amount'=>'110.00','amount'=>'110.00'))));
    overhead_json($data,'etc/2026/01.json',array(array('id'=>'e1','amount'=>'7.00','drive_file_id'=>'SECRET_DRIVE_TOKEN','private_url'=>'SECRET_PRIVATE_URL')));
    $before=overhead_files($root); $source=new OverheadFixtureSource(); $support=new Cpms2ManagementPreflightSupport();
    $exporter=new Cpms2OverheadExportService($source,$root,$root.'/storage',$support,'2026-03'); $plan=$exporter->exportPlan(); $report=$plan['report'];
    overhead_assert($report['categories']['payroll']['recognized_amount']==='220.00','Payroll manual/archive/version precedence mismatch');
    overhead_assert($report['categories']['vehicles']['recognized_amount']==='55.00' && $report['vehicles']['auto_payment_amount']==='50.00' && $report['vehicles']['monthly_rows_amount']==='5.00','Vehicle auto/month rows mismatch');
    overhead_assert($report['categories']['lease']['recognized_amount']==='120.00','Lease rent plus maintenance mismatch');
    overhead_assert($report['months'][0]['month']<='2026-03','Cutoff not applied');
    overhead_assert($report['categories']['corporate_cards']['recognized_amount']==='70.00','Card refund sign lost');
    overhead_assert($report['categories']['fuel']['recognized_amount']==='110.00' && $report['categories']['etc']['recognized_amount']==='7.00','Fuel or other mismatch');
    overhead_assert($report['grand_total']==='582.00' && $report['export_reconciliation']['grand_total']['difference']==='0.00','Grand reconciliation mismatch');
    foreach ($report['export_reconciliation']['months'] as $row) overhead_assert($row['difference']==='0.00','Monthly reconciliation mismatch');
    foreach ($report['export_reconciliation']['categories'] as $row) overhead_assert($row['difference']==='0.00','Category reconciliation mismatch');
    overhead_assert(Cpms2OverheadExportService::categoryMapping()===array('payroll'=>'payroll','vehicles'=>'vehicle','lease'=>'lease','corporate_cards'=>'corporate_card','fuel'=>'fuel','etc'=>'other'),'Category mapping mismatch');
    $entries=$plan['rows']['overhead_entries']; $references=array(); foreach ($entries as $entry) $references[]=$entry['source_reference'];
    $again=(new Cpms2OverheadExportService(new OverheadFixtureSource(),$root,$root.'/storage',new Cpms2ManagementPreflightSupport(),'2026-03'))->exportPlan();
    overhead_assert($references===array_map(function($row) { return $row['source_reference']; },$again['rows']['overhead_entries']),'Source references are not deterministic');
    $lease=$plan['rows']['overhead_lease_details'][0]; overhead_assert($lease['rent_amount']==='100.00' && $lease['maintenance_amount']==='20.00' && $lease['deposit_amount']==='5000.00','Lease detail or deposit preservation mismatch');
    $cards=$plan['rows']['overhead_card_details']; overhead_assert($cards[0]['card_last4']==='3456' && $cards[1]['card_last4']==='6666','Card last4 mismatch');
    $encoded=json_encode($plan['rows']); foreach (array('1234567890123456','9999888877776666','SECRET_RESIDENT','SECRET_CIPHER','SECRET_ACCOUNT','SECRET_DRIVE_TOKEN','SECRET_PRIVATE_URL') as $secret) overhead_assert(strpos($encoded,$secret)===false,'Sensitive value exported: '.$secret);
    $fuel=$plan['rows']['overhead_fuel_details'][0]; overhead_assert($fuel['supply_amount']==='100.00' && $fuel['tax_amount']==='10.00','Fuel supply/VAT mismatch');
    overhead_assert(overhead_files($root)===$before,'Planning changed a source file');
    foreach ($source->queries as $sql) overhead_assert(!preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP)\b/i',$sql),'Source DB write detected');
    $output=$private.'/fixture.zip'; $summary=(new Cpms2OverheadMigrationExportService(new OverheadFixtureSource(),$root,$root.'/storage'))->build($output,$private.'/stage',array('export_id'=>'fixture','generated_at'=>'2026-10-05T00:00:00+09:00','source_commit'=>null,'source_code_sha256'=>str_repeat('a',64)),'2026-03');
    overhead_assert(is_file($output) && substr(realpath($output),0,strlen(realpath($private)))===realpath($private),'Package not created in private storage');
    $zip=new ZipArchive(); overhead_assert($zip->open($output)===true,'ZIP open failed'); $names=array(); for ($i=0;$i<$zip->numFiles;$i++) $names[]=$zip->getNameIndex($i);
    $expected=array('manifest.json','data/overhead_entries.jsonl','data/overhead_vehicle_details.jsonl','data/overhead_lease_details.jsonl','data/overhead_card_details.jsonl','data/overhead_fuel_details.jsonl','summary.json'); sort($names); sort($expected);
    overhead_assert($names===$expected,'Overhead-only ZIP file list mismatch');
    foreach (array('departments','positions','employees','vendors','workers','direct_team','projects','labor','material','equipment','subcontract','safety','progress','attendance','leave','approval') as $forbidden) foreach ($names as $name) overhead_assert(strpos($name,$forbidden)===false,'Base entity included: '.$forbidden);
    $manifest=json_decode($zip->getFromName('manifest.json'),true); $zipSummary=json_decode($zip->getFromName('summary.json'),true); $zip->close();
    overhead_assert($manifest['package_type']==='cpms1_overhead_only' && $manifest['version']===1 && $manifest['source_commit']===null,'Manifest identity mismatch');
    overhead_assert($summary['grand_total']==='582.00' && $zipSummary['reconciliation']['grand_total']['difference']==='0.00','ZIP summary reconciliation mismatch');
    $after=overhead_files($root); foreach ($before as $path=>$hash) overhead_assert(isset($after[$path]) && $after[$path]===$hash,'Source hash changed during package generation');
    overhead_assert(!is_dir($private.'/stage'),'Private staging directory was not cleaned');
    overhead_json($root,'data/archive_summary/2026.json',array('company_overhead'=>array('categories'=>array('lease'=>'120.00'))));
    $blocked=new Cpms2OverheadExportService(new OverheadFixtureSource(),$root,$root.'/storage',new Cpms2ManagementPreflightSupport(),'2026-03');
    overhead_reject(function() use($blocked) { $blocked->exportPlan(); },'Annual archive fallback did not block export');
    file_put_contents($data.'/fuel/2026/01.json','invalid json');
    $invalid=new Cpms2OverheadExportService(new OverheadFixtureSource(),$root,$root.'/storage',new Cpms2ManagementPreflightSupport(),'2026-03');
    overhead_reject(function() use($invalid) { $invalid->exportPlan(); },'Invalid source did not block export');
    echo 'Overhead-only export fixture: PASS ('.$checks." checks)\n";
} finally { overhead_remove($root); }
