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
    'cpms_attendance_records'=>'id INT PRIMARY KEY,employee_id INT,work_date DATE,check_in DATETIME,check_out DATETIME,status VARCHAR(50),raw_minutes INT,work_minutes INT,memo VARCHAR(255),created_at DATETIME,updated_at DATETIME',
    'cpms_attendance_requests'=>'id INT PRIMARY KEY,employee_id INT,request_date DATE,request_type VARCHAR(50),status VARCHAR(50),reviewed_by INT,reviewed_at DATETIME,reason TEXT,reject_reason TEXT,requested_check_in DATETIME,requested_check_out DATETIME',
    'cpms_leave_records'=>'id INT PRIMARY KEY,employee_id INT,leave_date DATE,leave_type VARCHAR(30),leave_amount DECIMAL(6,2)',
    'cpms_leave_adjustments'=>'id INT PRIMARY KEY,employee_id INT,leave_type VARCHAR(30),amount DECIMAL(6,2),created_at DATETIME',
    'cpms_leave_accrual_logs'=>'id INT PRIMARY KEY,employee_id INT,leave_type VARCHAR(20),accrual_date DATE,accrual_year INT,amount DECIMAL(6,2),reason TEXT,created_at DATETIME',
    'cpms_approval_leave_deductions'=>'id INT PRIMARY KEY,employee_id INT,document_id INT,leave_bucket VARCHAR(20),deduct_amount DECIMAL(6,2),deducted_at DATETIME',
    'cpms_approval_documents'=>'id INT PRIMARY KEY,doc_status VARCHAR(50)',
    'cpms_approval_logs'=>'id INT PRIMARY KEY,document_id INT,action_type VARCHAR(50)',
    'cpms_holiday_cache'=>'id INT PRIMARY KEY,holiday_date DATE,source VARCHAR(30),is_active INT'
);
foreach ($tables as $t=>$fields) { if ($mysql) $db->exec('DROP TABLE IF EXISTS `'.$t.'`'); $db->exec('CREATE TABLE `'.$t.'` ('.$fields.')'); }
$db->exec("INSERT INTO employees VALUES(1,'SECRET_EMPLOYEE_NAME','일반',7,15,0),(2,'SECRET_DRIVER','[부 사 장]',0,0,0)");
$db->exec("INSERT INTO cpms_attendance_records VALUES(1,1,'2026-01-02','2026-01-02 08:00:30','2026-01-02 17:00:00','퇴근완료',540,480,'SECRET_MEMO','2026-01-02 08:00:30','2026-01-02 17:00:00'),(2,2,'2026-01-02','2026-01-02 08:31:00',NULL,'출근중',0,0,NULL,'2026-01-02 08:31:00','2026-01-02 08:31:00')");
$db->exec("INSERT INTO cpms_leave_accrual_logs VALUES(1,1,'MONTHLY','2026-01-01',2026,1,'입사일 기준 월차 자동 발생','2026-01-01 00:00:00')");
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
    $db->exec("INSERT INTO cpms_attendance_records VALUES(3,1,'2026-01-02','2026-01-02 10:00:00','2026-01-02 09:00:00','UNKNOWN',-1,1500,NULL,'2026-01-02 10:00:00','2026-01-02 11:00:00'),(4,99,'2026-01-03',NULL,NULL,'출근전',0,0,NULL,NULL,NULL)");
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
    // Production approval schema: doc_status, never status. Exclude cancelled/rejected deductions.
    $db->exec("INSERT INTO cpms_approval_documents VALUES(1,'APPROVED'),(2,'COMPLETED'),(3,'CANCELLED'),(4,'REJECTED')");
    $db->exec("INSERT INTO employees VALUES(3,'SECRET_APPROVAL_EMPLOYEE','일반',1,13,0)");
    $db->exec("INSERT INTO cpms_leave_accrual_logs VALUES(10,3,'MONTHLY','2026-01-01',2026,2,'입사일 기준 월차 자동 발생','2026-01-01 00:00:00'),(11,3,'ANNUAL','2026-01-01',2026,15,'입사일 기준 연차 자동 발생','2026-01-01 00:00:00')");
    $db->exec("INSERT INTO cpms_approval_leave_deductions VALUES(10,3,1,'MONTHLY',1,'2026-01-02 00:00:00'),(11,3,3,'MONTHLY',5,'2026-01-02 00:00:00'),(12,3,4,'MONTHLY',10,'2026-01-02 00:00:00'),(13,3,2,'ANNUAL',2,'2026-01-02 00:00:00')");
    $db->exec("INSERT INTO cpms_attendance_records VALUES
        (10,1,'2026-02-01','2026-02-01 10:00:00','2026-02-01 09:00:00','퇴근완료',0,0,'SECRET_RECORD_MEMO','2026-02-01 10:00:00','2026-02-01 18:30:00'),
        (11,1,'2026-02-02','2026-02-02 10:00:00','2026-02-02 09:00:00','퇴근완료',0,0,NULL,'2026-02-02 10:00:00','2026-02-02 10:00:00'),
        (12,1,'2026-02-03','2026-02-03 10:00:00','2026-02-03 09:00:00','퇴근완료',0,0,NULL,NULL,NULL),
        (13,1,'2026-02-04','2026-02-04 10:00:00','2026-02-04 09:00:00','퇴근완료',0,0,NULL,NULL,NULL),
        (14,1,'2026-02-05','2026-02-05 10:00:00','2026-02-05 09:00:00','퇴근완료',0,0,NULL,NULL,NULL),
        (15,1,'2026-02-06','2026-02-06 10:00:00','2026-02-06 09:00:00','퇴근완료',0,0,NULL,NULL,NULL),
        (16,1,'2026-02-07','2026-02-07 10:00:00','2026-02-07 09:00:00','퇴근완료',0,0,NULL,NULL,NULL),
        (17,1,'2026-02-08','2026-02-08 10:00:00','2026-02-08 09:00:00','퇴근완료',0,0,NULL,NULL,NULL),
        (18,1,'2026-02-09','2026-02-09 10:00:00','2026-02-09 09:00:00','퇴근완료',0,0,NULL,NULL,NULL),
        (20,902,'2026-02-01','2026-02-01 08:00:00','2026-02-01 17:00:00','퇴근완료',540,480,NULL,NULL,NULL),
        (30,1,'2026-01-03','2026-01-03 08:00:00',NULL,'출근중',0,0,NULL,NULL,NULL),
        (31,1,'2026-01-04','2026-01-04 08:00:00',NULL,'출근중',0,0,NULL,NULL,NULL)");
    $db->exec("INSERT INTO cpms_attendance_requests VALUES
        (10,1,'2026-02-01','both','approved',1,'2026-02-01 18:30:00','SECRET_REQUEST_REASON',NULL,'2026-02-01 08:00:00','2026-02-01 18:00:00'),
        (12,1,'2026-02-03','both','approved',1,'2026-02-03 18:30:00',NULL,NULL,'2026-02-03 11:00:00','2026-02-03 10:00:00'),
        (13,1,'2026-02-04','both','approved',1,'2026-02-04 18:30:00',NULL,NULL,'2026-02-04 08:00:00','2026-02-04 18:00:00'),
        (14,1,'2026-02-04','both','approved',1,'2026-02-04 19:30:00',NULL,NULL,'2026-02-04 08:00:00','2026-02-04 19:00:00'),
        (15,1,'2026-02-05','check_in','approved',1,'2026-02-05 18:30:00',NULL,NULL,'2026-02-05 08:00:00',NULL),
        (16,1,'2026-02-06','both','approved',1,'2026-02-06 18:30:00',NULL,NULL,'2026-02-07 08:00:00','2026-02-07 18:00:00'),
        (17,1,'2026-02-07','both','approved',1,NULL,NULL,NULL,'2026-02-07 08:00:00','2026-02-07 18:00:00'),
        (18,1,'2026-02-08','check_out','approved',1,'2026-02-08 18:30:00',NULL,NULL,NULL,'2026-02-08 11:00:00'),
        (19,1,'2026-02-09','check_in','approved',1,'2026-02-09 18:30:00',NULL,NULL,'2026-02-09 11:00:00',NULL),
        (20,904,'2026-02-01','check_in','pending',NULL,NULL,NULL,NULL,'2026-02-01 08:00:00',NULL)");
    $db->exec("INSERT INTO cpms_leave_accrual_logs VALUES
        (100,901,'MONTHLY','2025-12-01',2025,1,'입사일 기준 월차 자동 발생','2025-12-01 01:00:00'),
        (101,901,'ANNUAL','2026-02-01',2026,10,'입사일 기준 연차 자동 발생','2026-02-01 01:00:00'),
        (102,902,'MONTHLY','2026-02-01',2026,1,'입사일 기준 월차 자동 발생','2026-02-01 01:00:00'),
        (103,903,'ANNUAL','2026-02-01',2026,15,'입사일 기준 연차 자동 발생','2026-02-01 01:00:00'),
        (104,904,'MONTHLY','2026-02-01',2026,1,NULL,'2026-02-01 01:00:00'),
        (105,905,'MONTHLY','2026-02-01',2026,0,'기존 월차 발생일 확인(최초 잔여 유지)','2026-02-01 01:00:00'),
        (106,906,'MONTHLY','2026-02-01',2026,1,'SECRET_ORPHAN_NAME 01099998888 private@example.invalid','2026-02-01 01:00:00'),
        (107,907,'ANNUAL','2026-02-01',2026,0,'기존 연차 발생일 확인(최초 잔여 유지)','2026-02-01 01:00:00')");
    $db->exec("INSERT INTO cpms_approval_leave_deductions VALUES(20,903,1,'ANNUAL',1,'2026-02-01 01:00:00')");
    $db->exec("INSERT INTO cpms_leave_adjustments VALUES(20,905,2026,'ADD',1,'2026-02-01 01:00:00')");
    $diagSource=new ManagementFixtureSource($db,$mysql); $diagSupport=new Cpms2ManagementPreflightSupport();
    $beforeAttendance=(int)$db->query('SELECT COUNT(*) FROM cpms_attendance_records')->fetchColumn();
    $beforeWrites=$mysql?null:(int)$db->query('SELECT total_changes()')->fetchColumn();
    $d=(new Cpms2AttendanceExportService($diagSource,$diagSupport))->inspect('2026-01-03');
    management_assert($d['Attendance']['schema']['cpms_approval_documents']['missing_columns']===array(),'doc_status false Blocking');
    management_assert(!$mysql || !in_array('status',$d['Attendance']['schema']['cpms_approval_documents']['columns']),'Fixture must use production schema');
    foreach ($diagSupport->issues as $issue) management_assert($issue['code']!=='COLUMN_MISSING_cpms_approval_documents','False approval schema issue');
    $statuses=array(); foreach ($d['Leave']['approval_document_statuses'] as $s) $statuses[$s['value']]=$s['count'];
    management_assert(isset($statuses['APPROVED'],$statuses['COMPLETED'],$statuses['CANCELLED'],$statuses['REJECTED']),'Approval DISTINCT status');
    management_assert($d['Leave']['balances']['candidate_mismatches']===1,'Cancelled/rejected deductions included or active deduction omitted');
    foreach ($diagSupport->issues as $issue) if ($issue['code']==='BALANCE_MISMATCH') management_assert($issue['severity']==='WARNING','Balance mismatch elevated to Blocking');
    $reversed=array(); foreach ($d['Attendance']['records']['reversed_diagnostic']['records'] as $r) $reversed[$r['legacy_id']]=$r;
    management_assert($reversed[10]['classification']==='VALID_REQUEST_CAN_RECONSTRUCT' && $reversed[10]['check_in']==='2026-02-01 10:00:00' && $reversed[10]['updated_at']==='2026-02-01 18:30:00','Reversed details or normal request candidate');
    management_assert($reversed[10]['related_requests']['requests'][0]['reason_present'] && !isset($reversed[10]['related_requests']['requests'][0]['reason']),'Request reason leaked');
    management_assert($reversed[10]['reconstruction_candidate']['automatic_apply']===false,'Diagnostic applied reconstruction');
    management_assert($reversed[11]['classification']==='SOURCE_RECORD_REVERSED','No-request classification');
    management_assert($reversed[12]['classification']==='REQUEST_ALSO_REVERSED','Reversed request classification');
    management_assert($reversed[13]['classification']==='AMBIGUOUS','Multiple approvals classification');
    management_assert($reversed[14]['classification']==='VALID_REQUEST_CAN_RECONSTRUCT' && $reversed[17]['classification']==='VALID_REQUEST_CAN_RECONSTRUCT','Partial request semantics');
    management_assert($reversed[15]['classification']==='AMBIGUOUS' && $reversed[16]['classification']==='AMBIGUOUS','Date/review uncertainty not protected');
    management_assert($reversed[18]['classification']==='SOURCE_RECORD_REVERSED','Partial reversed request treated as independently reversed pair');
    management_assert($reversed[3]['classification']==='AMBIGUOUS','Duplicate date relation auto-resolved');
    $missing=$d['Attendance']['records']['missing_checkout_preview'];
    management_assert($missing['past_missing_checkout']===1 && $missing['today_in_progress']===1 && $missing['future_records']===1,'Missing-checkout date partitions');
    foreach ($diagSupport->issues as $issue) if ($issue['code']==='MISSING_CHECKOUT') management_assert($issue['severity']==='WARNING','Missing checkout Blocking');
    $orphan=array(); $orphanReport=$d['Leave']['cpms_leave_accrual_logs']['orphan_diagnostic']; foreach ($orphanReport['employees'] as $r) $orphan[$r['employee_id']]=$r;
    management_assert($orphanReport['row_count']===8 && $orphanReport['employee_count']===7,'Orphan grouping totals');
    management_assert($orphan[901]['row_count']===2 && $orphan[901]['monthly_count']===1 && $orphan[901]['annual_count']===1 && $orphan[901]['amount_sum']==='11.00','Orphan type/amount aggregation');
    management_assert($orphan[901]['first_accrual_date']==='2025-12-01' && $orphan[901]['last_created_at']==='2026-02-01 01:00:00','Orphan date ranges');
    management_assert($orphan[901]['classification']==='ORPHAN_ACCRUAL_ONLY' && $orphan[902]['classification']==='ORPHAN_WITH_ATTENDANCE','Accrual-only/attendance relation');
    management_assert($orphan[903]['classification']==='ORPHAN_WITH_LEAVE_USAGE' && $orphan[904]['classification']==='ORPHAN_WITH_OTHER_RELATION' && $orphan[905]['classification']==='ORPHAN_WITH_OTHER_RELATION','Usage/request/adjustment relation');
    management_assert($orphan[907]['reason_type']==='LEGACY_BALANCE_CONFIRMATION' && $orphan[906]['reason_type']==='UNKNOWN','System reason classification');
    $diagnosticJson=json_encode($d);
    foreach (array('SECRET_EMPLOYEE_NAME','SECRET_REQUEST_REASON','SECRET_RECORD_MEMO','SECRET_ORPHAN_NAME','01099998888','private@example.invalid','reason":"') as $secret) management_assert(strpos($diagnosticJson,$secret)===false,'Detailed diagnostic leaked PII');
    foreach ($diagSource->queries as $sql) Cpms2ReadOnlySource::assertReadOnly($sql);
    management_assert((int)$db->query('SELECT COUNT(*) FROM cpms_attendance_records')->fetchColumn()===$beforeAttendance && (!$mysql?(int)$db->query('SELECT total_changes()')->fetchColumn()===$beforeWrites:true),'Diagnostic DB writes');
    management_assert($db->query('SELECT check_in FROM cpms_attendance_records WHERE id=10')->fetchColumn()==='2026-02-01 10:00:00','Source record changed');
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
