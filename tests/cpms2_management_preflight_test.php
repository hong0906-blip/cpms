<?php
// tests/cpms2_management_preflight_test.php
// PHP 5.6, isolated SQLite/optional MySQL fixture and temporary files only.
require_once dirname(__DIR__).'/app/services/Cpms2ManagementMigrationPreflightService.php';
date_default_timezone_set('Asia/Seoul');
function cpms_archive_load_detail() { throw new RuntimeException('Write-capable archive reader called'); }
function cpms_company_payroll_data_root() { throw new RuntimeException('Write-capable payroll resolver called'); }
$checks=0;
function management_assert($ok,$message) { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
function management_json($root,$relative,$data) { $p=$root.'/'.$relative; if (!is_dir(dirname($p))) mkdir(dirname($p),0700,true); file_put_contents($p,json_encode($data)); }
function management_files($root) { $out=array(); $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)); foreach ($it as $f) if ($f->isFile()) $out[$f->getPathname()]=hash_file('sha256',$f->getPathname()); ksort($out); return $out; }
function management_remove($root) { $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST); foreach ($it as $f) { if ($f->isDir()) rmdir($f->getPathname()); else unlink($f->getPathname()); } rmdir($root); }
class ManagementFixtureSource extends Cpms2ReadOnlySource
{
    private $db; private $columns=array(); public $queries=array(); private $mysql;
    public function __construct($db,$mysql=false) { parent::__construct($db); $this->db=$db; $this->mysql=$mysql; }
    public function inspect($table,$mandatory=false,$requiredColumns=array('id'))
    {
        if ($this->mysql) return parent::inspect($table,$mandatory,$requiredColumns);
        $c=array(); foreach ($this->db->query('PRAGMA table_info(`'.$table.'`)') as $r) $c[]=$r['name']; $this->columns[$table]=$c; return $c;
    }
    public function columns($table) { return $this->mysql?parent::columns($table):(isset($this->columns[$table])?$this->columns[$table]:$this->inspect($table)); }
    public function query($sql,$params=array()) { Cpms2ReadOnlySource::assertReadOnly($sql); $this->queries[]=$sql; return parent::query($sql,$params); }
}
$dsn=getenv('CPMS2_MANAGEMENT_FIXTURE_DSN'); $mysql=(bool)$dsn;
if ($mysql && !preg_match('/^mysql:host=127\.0\.0\.1;port=33419;dbname=cmdata_legacy_management_fixture(?:_[a-z0-9_]+)?(?:;|$)/',$dsn)) throw new RuntimeException('Local dedicated management fixture DB required');
$db=$mysql?new PDO($dsn,'root',''):new PDO('sqlite::memory:'); $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
if (!$mysql) { $db->sqliteCreateFunction('CHAR_LENGTH',function($s) { return mb_strlen($s,'UTF-8'); }); $db->sqliteCreateFunction('TIME_FORMAT',function($s,$format) { return $s===null?null:substr($s,11,5); }); }
$tables=array(
    'employees'=>'id INT PRIMARY KEY,name VARCHAR(120),position VARCHAR(120),leave_monthly_balance DECIMAL(6,2),leave_annual_balance DECIMAL(6,2),leave_half_balance DECIMAL(6,2)',
    'cpms_attendance_records'=>'id INT PRIMARY KEY,employee_id INT,work_date DATE,check_in DATETIME,check_out DATETIME,status VARCHAR(50),raw_minutes INT,work_minutes INT,memo VARCHAR(255)',
    'cpms_attendance_requests'=>'id INT PRIMARY KEY,employee_id INT,request_date DATE,request_type VARCHAR(50),status VARCHAR(50),reviewed_by INT,reviewed_at DATETIME,reason TEXT,reject_reason TEXT,requested_check_in DATETIME,requested_check_out DATETIME',
    'cpms_leave_records'=>'id INT PRIMARY KEY,employee_id INT,leave_date DATE,leave_type VARCHAR(30),leave_amount DECIMAL(6,2)',
    'cpms_leave_adjustments'=>'id INT PRIMARY KEY,employee_id INT,leave_type VARCHAR(30),amount DECIMAL(6,2),created_at DATETIME',
    'cpms_leave_accrual_logs'=>'id INT PRIMARY KEY,employee_id INT,leave_type VARCHAR(20),accrual_date DATE,accrual_year INT,amount DECIMAL(6,2)',
    'cpms_approval_leave_deductions'=>'id INT PRIMARY KEY,employee_id INT,document_id INT,leave_bucket VARCHAR(20),deduct_amount DECIMAL(6,2)',
    'cpms_approval_documents'=>'id INT PRIMARY KEY,status VARCHAR(50)',
    'cpms_approval_logs'=>'id INT PRIMARY KEY,document_id INT,action_type VARCHAR(50)',
    'cpms_holiday_cache'=>'id INT PRIMARY KEY,holiday_date DATE,source VARCHAR(30),is_active INT'
);
foreach ($tables as $t=>$fields) { if ($mysql) $db->exec('DROP TABLE IF EXISTS `'.$t.'`'); $db->exec('CREATE TABLE `'.$t.'` ('.$fields.')'); }
$db->exec("INSERT INTO employees VALUES(1,'SECRET_EMPLOYEE_NAME','일반',7,15,0),(2,'SECRET_DRIVER','[부 사 장]',0,0,0)");
$db->exec("INSERT INTO cpms_attendance_records VALUES(1,1,'2026-01-02','2026-01-02 08:00:30','2026-01-02 17:00:00','퇴근완료',540,480,'SECRET_MEMO'),(2,2,'2026-01-02','2026-01-02 08:31:00',NULL,'출근중',0,0,NULL)");
$db->exec("INSERT INTO cpms_leave_accrual_logs VALUES(1,1,'MONTHLY','2026-01-01',2026,1)");
$db->exec("INSERT INTO cpms_leave_adjustments VALUES(1,1,'월차',1,'2026-01-01 00:00:00')");
$db->exec("INSERT INTO cpms_holiday_cache VALUES(1,'2026-01-01','GOOGLE_CALENDAR',1)");
$root=sys_get_temp_dir().'/cpms-management-fixture-'.uniqid(); mkdir($root,0700); mkdir($root.'/storage',0700);
$data=$root.'/data/company_overhead'; $storage=$root.'/storage/company_overhead';
try {
    $source=new ManagementFixtureSource($db,$mysql); $support=new Cpms2ManagementPreflightSupport(); $a=(new Cpms2AttendanceExportService($source,$support))->inspect();
    management_assert((int)$a['Attendance']['records']['total']===2,'Attendance total');
    management_assert($a['Attendance']['records']['duplicate_employee_dates']===0,'Normal attendance');
    management_assert($a['Attendance']['records']['late_preview']['late_count']===1,'Minute precision / current normalized vice position');
    management_assert($a['Attendance']['schema']['cpms_leave_adjustments']['variant']==='LEGACY_LEAVE_TYPE','Old adjustment schema');
    management_assert($a['Leave']['balances']['candidate_mismatches']===1,'Balance mismatch');
    management_assert($a['Leave']['balances']['reconstructable_employees']===0,'Incomplete ledger misrepresented as complete');
    $db->exec("INSERT INTO cpms_attendance_records VALUES(3,1,'2026-01-02','2026-01-02 10:00:00','2026-01-02 09:00:00','UNKNOWN',-1,1500,NULL),(4,99,'2026-01-03',NULL,NULL,'출근전',0,0,NULL)");
    $db->exec("INSERT INTO cpms_attendance_requests VALUES(1,99,'2026-01-02','both','approved',98,NULL,NULL,NULL,NULL,NULL)");
    $support=new Cpms2ManagementPreflightSupport(); $a=(new Cpms2AttendanceExportService($source,$support))->inspect();
    management_assert($a['Attendance']['records']['duplicate_employee_dates']===1,'Duplicate not detected');
    management_assert($a['Attendance']['records']['checkout_before_checkin']===1,'Reversed check-out');
    management_assert($a['Attendance']['records']['employee_orphans']===1,'Orphan employee');
    management_assert($a['Attendance']['requests']['reviewer_orphans']===1,'Orphan reviewer');
    $mapping=array(); foreach ($a['Attendance']['records']['statuses'] as $s) $mapping[$s['value']]=$s['mapping'];
    management_assert($mapping['UNKNOWN']==='BLOCKING_UNKNOWN','Unknown status'); management_assert($mapping['출근전']==='NEEDS_MAPPING','Before-checkin must not become absent');
    $db->exec('DROP TABLE cpms_leave_adjustments'); $db->exec('CREATE TABLE cpms_leave_adjustments(id INT PRIMARY KEY,employee_id INT,target_year INT,adjust_type VARCHAR(20),amount DECIMAL(6,2),created_at DATETIME)');
    $support=new Cpms2ManagementPreflightSupport(); $a=(new Cpms2AttendanceExportService(new ManagementFixtureSource($db,$mysql),$support))->inspect();
    management_assert($a['Attendance']['schema']['cpms_leave_adjustments']['variant']==='ADJUST_TYPE_TARGET_YEAR','New adjustment schema');
    management_json($data,'lease/2026/01.json',array('items'=>array(array('id'=>'LEASE-IMPORT-1','lease_group_id'=>'g1','amount'=>'100.00','maintenance_fee'=>'20.00','deposit'=>'5000.00'))));
    management_json($storage,'lease/2026/01.json',array('items'=>array(array('id'=>'LEASE-IMPORT-1','lease_group_id'=>'g1','amount'=>'100.00','maintenance_fee'=>'20.00','deposit'=>'5000.00'))));
    management_json($data,'etc/2026/01.json',array(array('id'=>'a','amount'=>'7.00','attachments'=>array(array('drive_file_id'=>'SECRET_TOKEN')))));
    management_json($storage,'etc/2026/01.json',array(array('id'=>'b','amount'=>'9.00')));
    management_json($data,'payroll_versions/2026/01.json',array('employees'=>array(array('employee_id'=>1,'resident_number'=>'SECRET_RESIDENT','resident_enc'=>'SECRET_CIPHER','bank_account'=>'SECRET_BANK')),'total_net_pay'=>'50.00'));
    management_json($data,'payroll_versions/2026/03.json',array('employees'=>array(),'total_net_pay'=>'0.00'));
    management_json($data,'payroll_manual_totals/2026/01.json',array('amount'=>'90.00'));
    management_json($data,'corporate_cards/2026/01.json',array(array('id'=>'c1','amount'=>'100.00','card_number'=>'1234567890123456'),array('id'=>'c2','amount'=>'-30.00'),array('id'=>'c3','amount'=>'99.00','deleted_at'=>'2026-01-02')));
    management_json($data,'fuel/2026/01.json',array('items'=>array(array('id'=>'f1','vehicle_number'=>'11가1234','supply_amount'=>'100.00','vat'=>'10.00','total_amount'=>'110.00','amount'=>'110.00','matched_employee_id'=>1))));
    management_json($data,'fuel_vehicle_matches/matches.json',array('matches'=>array('11가1234'=>array('display_name'=>'SECRET_DRIVER'))));
    management_json($root,'data/archive_summary/2025.json',array('company_overhead'=>array('category_monthly'=>array('etc'=>array('12'=>'12.00')))));
    management_json($root,'data/archive_index/2025.json',array('archives'=>array(array('archive_id'=>'fixture','archive_type'=>'company_overhead','status'=>'verified','drive_file_id'=>'SECRET_CREDENTIAL'))));
    $before=management_files($root); ob_start();
    $report=(new Cpms2ManagementMigrationPreflightService(new ManagementFixtureSource($db,$mysql),$root,$root.'/storage'))->inspect('2026-03');
    $console=ob_get_clean(); $o=$report['Overhead'];
    management_assert($console==='','Sensitive console output'); management_assert(isset($o['categories']),'Overhead failed');
    management_assert($o['categories']['lease']['recognized_amount']==='120.00','Lease deposit included');
    management_assert($o['categories']['corporate_cards']['recognized_amount']==='70.00','Card signed refund lost');
    management_assert($o['categories']['fuel']['recognized_amount']==='110.00','Fuel recognized total');
    $monthRows=array(); foreach ($o['months'] as $m) $monthRows[$m['category'].'/'.$m['month']]=$m;
    management_assert($monthRows['fuel/2026-01']['stats']['supply']==='100.00' && $monthRows['fuel/2026-01']['stats']['vat']==='10.00' && $monthRows['fuel/2026-01']['stats']['total']==='110.00','Fuel VAT totals');
    management_assert($monthRows['payroll/2026-01']['basis']==='MANUAL_TOTAL' && $monthRows['payroll/2026-01']['amount']==='90.00','Manual priority');
    management_assert($monthRows['payroll/2026-02']['basis']==='PAYROLL_VERSION' && $monthRows['payroll/2026-02']['amount']==='50.00','Payroll version fallback');
    management_assert($monthRows['payroll/2026-03']['amount']==='0.00' && $o['payroll']['empty_versions']===1,'Empty payroll version');
    $duplicates=array(); foreach ($o['duplicate_sources'] as $d) $duplicates[$d['category']]=$d;
    management_assert($duplicates['lease']['same_sha'] && !$duplicates['lease']['different_sha'],'Same SHA root');
    management_assert($duplicates['etc']['different_sha'] && $duplicates['etc']['selected_root']==='data','Different SHA / priority');
    management_assert($o['categories']['etc']['recognized_amount']==='19.00','Roots double-counted');
    management_assert($o['archive'][0]['detail_status']==='ARCHIVE_DETAIL_UNAVAILABLE','Archived-only detail absence');
    management_assert(!$o['total_is_final'],'Unavailable detail claimed final');
    management_assert($o['fuel_manual_mapping']['count']===1,'Manual fuel mapping');
    $json=json_encode($report);
    foreach (array('SECRET_EMPLOYEE_NAME','SECRET_DRIVER','SECRET_MEMO','SECRET_RESIDENT','SECRET_CIPHER','SECRET_BANK','SECRET_TOKEN','SECRET_CREDENTIAL','1234567890123456',$root) as $secret) management_assert(strpos($json,$secret)===false,'Diagnostic leaked sensitive value');
    management_assert(management_files($root)===$before,'Read-only source file invariant');
    foreach ($source->queries as $sql) Cpms2ReadOnlySource::assertReadOnly($sql);
    management_assert(count($source->queries)>20,'Read-only SQL spy');
    foreach (array('INSERT INTO x VALUES(1)','UPDATE x SET a=1','DELETE FROM x','REPLACE INTO x VALUES(1)','ALTER TABLE x ADD a INT','CREATE TABLE x(a INT)','DROP TABLE x') as $sql) { $rejected=false; try { Cpms2ReadOnlySource::assertReadOnly($sql); } catch (Exception $e) { $rejected=true; } management_assert($rejected,'Write SQL accepted'); }
    // Repeated diagnosis must not become ZIP export or persistence.
    $again=(new Cpms2ManagementMigrationPreflightService(new ManagementFixtureSource($db,$mysql),$root,$root.'/storage'))->inspect('2026-03');
    management_assert($again['Overhead']['grand_total']===$o['grand_total'] && management_files($root)===$before,'Repeated diagnostic modified source');
    // Vehicle schedule includes changes, but excludes insurance/deposit/residual principal.
    management_json($data,'company_vehicles/vehicles.json',array('vehicles'=>array(array('id'=>'v1','vehicle_number'=>'11가1234','finance_start'=>'2026-01-01','finance_end'=>'2026-03-31','monthly_payment'=>'10.00','insurance_premium'=>'9999.00','deposit'=>'9999.00','monthly_payment_changes'=>array(array('effective_ym'=>'2026-02','amount'=>'20.00')),'driver_changes'=>array(array('effective_ym'=>'2026-02','driver_name'=>'SECRET_DRIVER'))))));
    $v=(new Cpms2ManagementMigrationPreflightService(new ManagementFixtureSource($db,$mysql),$root,$root.'/storage'))->inspect('2026-03');
    management_assert($v['Overhead']['vehicles']['auto_payment_amount']==='50.00','Vehicle payment period / changes / insurance exclusion');
    management_assert($v['Overhead']['vehicles']['driver_changes']===1,'Vehicle driver changes');
    // Storage-root payroll selected when data root does not exist. No mkdir fallback.
    $storageOnly=$root.'/storage-only'; mkdir($storageOnly,0700);
    management_json($storageOnly,'company_overhead/payroll_versions/2026/01.json',array('employees'=>array(array('employee_id'=>2)),'total_net_pay'=>'8.00'));
    $s=(new Cpms2ManagementMigrationPreflightService(new ManagementFixtureSource($db,$mysql),$root.'/missing-project-root',$storageOnly))->inspect('2026-01');
    management_assert($s['Overhead']['payroll']['runtime_selected_root']==='storage' && $s['Overhead']['categories']['payroll']['recognized_amount']==='8.00','Storage payroll root');
    management_assert(!is_dir($root.'/missing-project-root'),'Missing payroll resolver created a directory');
    management_json($data,'fuel/2026/02.json',array()); file_put_contents($data.'/fuel/2026/02.json','invalid JSON SECRET_TOKEN');
    $bad=(new Cpms2ManagementMigrationPreflightService(new ManagementFixtureSource($db,$mysql),$root,$root.'/storage'))->inspect('2026-03');
    management_assert($bad['Overhead']['categories']['fuel']['parse_failure_months']===1 && !$bad['Overhead']['total_is_final'],'Parse failure hidden');
    management_assert(strpos(json_encode($bad),'SECRET_TOKEN')===false,'Parse failure leaked payload');
    $db->exec('DROP TABLE cpms_attendance_requests');
    $missing=(new Cpms2ManagementMigrationPreflightService(new ManagementFixtureSource($db,$mysql),$root,$root.'/storage'))->inspect('2026-03');
    management_assert($missing['Attendance']['schema']['cpms_attendance_requests']['status']==='TABLE_MISSING' && $missing['audit']['Attendance']['status']==='BLOCKING','Missing table not diagnosed');
    echo 'Management preflight '.($mysql?'MySQL':'SQLite').' fixture: PASS ('.$checks." checks)\n";
} finally { management_remove($root); if ($mysql) foreach ($tables as $t=>$fields) $db->exec('DROP TABLE IF EXISTS `'.$t.'`'); }
