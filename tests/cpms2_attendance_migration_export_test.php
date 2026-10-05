<?php
// tests/cpms2_attendance_migration_export_test.php
// Dedicated SQLite / localhost MySQL fixtures only. No operating DB or files.
require_once dirname(__DIR__).'/app/services/Cpms2MigrationExportService.php';
date_default_timezone_set('Asia/Seoul');
$dsn=getenv('CPMS2_ATTENDANCE_FIXTURE_DSN');
if ($dsn && !preg_match('/^mysql:host=127\.0\.0\.1;port=33419;dbname=cmdata_legacy_attendance_source_fixture_[a-z0-9]+;charset=utf8mb4$/D',$dsn)) throw new RuntimeException('Dedicated fixture DSN required');
$db=new PDO($dsn?$dsn:'sqlite::memory:','root','',array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC));
if (!$dsn) { $db->sqliteCreateFunction('CHAR_LENGTH',function($s) { return mb_strlen($s,'UTF-8'); }); $db->sqliteCreateFunction('TIME_FORMAT',function($s,$format) { return $s===null?null:substr($s,11,5); }); }
class AttendanceMigrationFixtureSource extends Cpms2ReadOnlySource
{
    private $db; private $native; public $sql=array();
    public function __construct($db,$native) { parent::__construct($db); $this->db=$db; $this->native=$native; }
    public function inspect($table,$mandatory=false,$required=array('id')) {
        if ($this->native) return parent::inspect($table,$mandatory,$required);
        return array_column($this->db->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC),'name');
    }
    public function query($sql,$params=array()) { $this->sql[]=$sql; return parent::query($sql,$params); }
}
class AttendanceMigrationCollector
{
    public $rows=array(); public $managementSummary;
    public function emptyEntity($e) { $this->rows[$e]=array(); }
    public function record($e,$r) { $this->rows[$e][]=$r; }
}
$tables=array(
 'employees'=>'id INT PRIMARY KEY,name VARCHAR(100),position VARCHAR(30),leave_monthly_balance DECIMAL(7,2),leave_annual_balance DECIMAL(7,2),leave_half_balance DECIMAL(7,2)',
 'cpms_attendance_records'=>'id INT PRIMARY KEY,employee_id INT,work_date DATE,check_in DATETIME,check_out DATETIME,status VARCHAR(40),raw_minutes INT,work_minutes INT,memo TEXT,created_at DATETIME,updated_at DATETIME',
 'cpms_attendance_requests'=>'id INT PRIMARY KEY,employee_id INT,request_date DATE,request_type VARCHAR(20),requested_check_in DATETIME,requested_check_out DATETIME,status VARCHAR(20),reviewed_by INT,reviewed_at DATETIME,reason TEXT,reject_reason TEXT,created_at DATETIME',
 'cpms_leave_accrual_logs'=>'id INT PRIMARY KEY,employee_id INT,leave_type VARCHAR(20),accrual_date DATE,accrual_year INT,amount DECIMAL(7,2),reason TEXT,created_at DATETIME',
 'cpms_approval_leave_deductions'=>'id INT PRIMARY KEY,employee_id INT,document_id INT,leave_type VARCHAR(40),leave_bucket VARCHAR(20),deduct_amount DECIMAL(7,2),balance_before DECIMAL(7,2),balance_after DECIMAL(7,2),deducted_at DATETIME',
 'cpms_approval_documents'=>'id INT PRIMARY KEY,doc_status VARCHAR(30),content TEXT,created_by_id INT',
 'cpms_approval_logs'=>'id INT PRIMARY KEY,document_id INT,action_type VARCHAR(40),created_at DATETIME,actor_name VARCHAR(100),action_note TEXT',
 'cpms_leave_records'=>'id INT PRIMARY KEY,employee_id INT,leave_date DATE,leave_type VARCHAR(40),leave_amount DECIMAL(7,2)',
 'cpms_leave_adjustments'=>'id INT PRIMARY KEY,employee_id INT,leave_type VARCHAR(20),amount DECIMAL(7,2)',
 'cpms_holiday_cache'=>'holiday_date DATE,source VARCHAR(30),is_active INT'
);
foreach ($tables as $table=>$fields) $db->exec('CREATE TABLE '.$table.'('.$fields.')');
function attendance_fixture_insert($db,$table,$r) { $db->prepare('INSERT INTO '.$table.'('.implode(',',array_keys($r)).') VALUES('.implode(',',array_fill(0,count($r),'?')).')')->execute(array_values($r)); }
$ids=array_merge(array(17),range(100,140));
foreach ($ids as $id) attendance_fixture_insert($db,'employees',array('id'=>$id,'name'=>'PRIVATE_NAME','position'=>'일반','leave_monthly_balance'=>$id===17?'10.00':null,'leave_annual_balance'=>$id===17?'206.50':null,'leave_half_balance'=>'0.00'));
for ($i=1;$i<=1892;$i++) {
    $reverse=$i>1878; $date=$reverse?date('Y-m-d',strtotime('2026-09-19 +'.($i-1879).' days')):date('Y-m-d',strtotime('2026-05-08 +'.(int)(($i-1)/38).' days'));
    $emp=$reverse?17:$ids[($i-1)%38]; $missing=$i<=110;
    attendance_fixture_insert($db,'cpms_attendance_records',array('id'=>$i,'employee_id'=>$emp,'work_date'=>$date,'check_in'=>$date.' 08:00:00','check_out'=>$missing?null:$date.($reverse?' 06:15:00':' 18:15:00'),'status'=>$missing?'출근중':'퇴근완료','raw_minutes'=>$reverse||$missing?0:615,'work_minutes'=>$reverse||$missing?0:495,'created_at'=>$date.' 08:00:00','updated_at'=>$date.' 18:15:00'));
}
for ($i=1;$i<=605;$i++) {
    $status=$i<=584?'approved':($i<=598?'rejected':'pending');
    attendance_fixture_insert($db,'cpms_attendance_requests',array('id'=>$i,'employee_id'=>17,'request_date'=>$i===583||$i===584?'2026-10-02':'2026-06-01','request_type'=>$i<=304?'check_out':($i<=482?'check_in':'both'),'requested_check_in'=>'2026-06-01 08:00:00','requested_check_out'=>'2026-06-01 18:00:00','status'=>$status,'reviewed_by'=>$status==='pending'?null:100,'reviewed_at'=>$status==='pending'?null:'2026-06-02 10:00:00','reason'=>'Fixture request','reject_reason'=>$status==='rejected'?'Fixture rejection':null,'created_at'=>'2026-06-01 10:00:00'));
}
for ($i=1;$i<=328;$i++) attendance_fixture_insert($db,'cpms_leave_accrual_logs',array('id'=>$i,'employee_id'=>17,'leave_type'=>'ANNUAL','accrual_date'=>date('Y-m-d',strtotime('2025-01-01 +'.($i-1).' days')),'accrual_year'=>2025,'amount'=>$i===1?'0.00':($i===2?'131.00':'1.00'),'reason'=>$i===1?'기존 연차 발생일 확인(최초 잔여 유지)':'입사일 기준 연차 자동 발생','created_at'=>'2025-01-01 00:00:00'));
$id=329; foreach (array(39=>2,44=>47) as $emp=>$count) for ($i=0;$i<$count;$i++) attendance_fixture_insert($db,'cpms_leave_accrual_logs',array('id'=>$id++,'employee_id'=>$emp,'leave_type'=>'ANNUAL','accrual_date'=>'2026-01-01','accrual_year'=>2026,'amount'=>$emp===39?'0.50':($i===0?'811.00':'1.00'),'reason'=>'PRIVATE_ORPHAN_REASON','created_at'=>'2026-01-01 00:00:00'));
for ($i=1;$i<=90;$i++) {
    $amount=$i<=70?'1.00':'2.75';
    attendance_fixture_insert($db,'cpms_approval_documents',array('id'=>$i,'doc_status'=>$i===1?'CANCELLED':'COMPLETED','content'=>json_encode(array('request_type'=>'연차','leave_start_date'=>'2026-07-01','leave_end_date'=>'2026-07-03','resident_number'=>'NEVER_EXPORT_RESIDENT','bank_account'=>'NEVER_EXPORT_ACCOUNT')),'created_by_id'=>17));
    attendance_fixture_insert($db,'cpms_approval_leave_deductions',array('id'=>$i,'employee_id'=>17,'document_id'=>$i,'leave_type'=>'연차','leave_bucket'=>'ANNUAL','deduct_amount'=>$amount,'balance_before'=>'20.00','balance_after'=>$i<=70?'19.00':'17.25','deducted_at'=>'2026-06-30 12:00:00'));
}
attendance_fixture_insert($db,'cpms_approval_logs',array('id'=>1,'document_id'=>1,'action_type'=>'LEAVE_RESTORE','created_at'=>'2026-07-04 12:00:00','actor_name'=>'PRIVATE_ACTOR','action_note'=>'PRIVATE_RESTORE_NOTE'));
attendance_fixture_insert($db,'cpms_holiday_cache',array('holiday_date'=>'2026-05-05','source'=>'google','is_active'=>1));
$source=new AttendanceMigrationFixtureSource($db,(bool)$dsn); $collector=new AttendanceMigrationCollector();
$checks=0; function attendance_export_assert($ok,$label) { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }
$before=$dsn?null:$db->query('SELECT total_changes()')->fetchColumn();
$s=(new Cpms2AttendanceMigrationExportService($source))->run($collector,'2026-10-05');
attendance_export_assert($s['record_counts']['attendance_records']===1892 && $s['record_counts']['attendance_requests']===605,'Attendance/request count');
attendance_export_assert($s['attendance']===array('normal_source'=>1768,'missing_checkout_original'=>110,'reversed_normalized'=>14),'Status/reversed counts');
attendance_export_assert($s['requests']===array('pending'=>7,'approved'=>584,'rejected'=>14),'Request statuses');
attendance_export_assert($s['accrual']['original']===377 && $s['accrual']['original_amount']==='1315.00','Original accrual');
attendance_export_assert($s['accrual']['explicit_orphan_excluded']===49 && $s['accrual']['excluded_amount']==='858.00','Explicit scope');
attendance_export_assert($s['accrual']['zero_confirmation']===1 && $s['accrual']['migration_effective']===327 && $s['amounts']['leave_accrual_logs']['amount']==='457.00','Zero confirmation');
attendance_export_assert($s['record_counts']['leave_approval_deductions']===90 && $s['amounts']['leave_approval_deductions']['amount']==='125.00','Deductions');
attendance_export_assert($s['record_counts']['leave_restore_events']===1 && $collector->rows['leave_approval_records'][0]['doc_status']==='CANCELLED','Cancellation/restore');
attendance_export_assert($s['record_counts']['leave_balance_snapshots']===42 && $s['amounts']['leave_balance_snapshots']['annual_balance']==='206.50','Balance snapshot');
$last=$collector->rows['attendance_records'][1891]; attendance_export_assert($last['check_out']==='2026-10-02 06:15:00' && $last['classification']==='AMBIGUOUS','Original reversed time / classification');
$json=json_encode(array('summary'=>$s,'rows'=>$collector->rows));
foreach (array('PRIVATE_NAME','PRIVATE_ORPHAN_REASON','PRIVATE_ACTOR','PRIVATE_RESTORE_NOTE','NEVER_EXPORT_RESIDENT','NEVER_EXPORT_ACCOUNT') as $secret) attendance_export_assert(strpos($json,$secret)===false,'Sensitive source exclusion');
foreach ($source->sql as $sql) Cpms2ReadOnlySource::assertReadOnly($sql);
if (!$dsn) attendance_export_assert($before==$db->query('SELECT total_changes()')->fetchColumn(),'Runtime wrote Source DB');
$output=getenv('CPMS2_ATTENDANCE_FIXTURE_OUTPUT'); if ($output) {
    if (strpos(str_replace('\\','/',$output),'C:/Users/Public/Documents/ESTsoft/CreatorTemp/cpms-migration-mysql/')!==0) throw new RuntimeException('Fixture output boundary');
    file_put_contents($output,$json);
}
$zipOutput=getenv('CPMS2_ATTENDANCE_FIXTURE_ZIP'); if ($zipOutput) {
    if (str_replace('\\','/',$zipOutput)!=='C:/Users/Public/Documents/ESTsoft/CreatorTemp/cpms-migration-mysql/attendance-fixture.zip') throw new RuntimeException('Fixture ZIP boundary');
    if (file_exists($zipOutput)) unlink($zipOutput); // Named synthetic fixture only.
    $stage=dirname($zipOutput).'/.attendance-stage-'.uniqid(); $writer=new Cpms2ExportPackageWriter($stage);
    foreach (array('departments','positions','employees','vendors','workers','direct_team','projects','project_members','project_roles','labor_workers','labor_months','labor_entries','material_items','material_usages','equipment_items','equipment_usages','subcontract_costs','safety_costs','progress_billings','material_statement_files','safety_evidence_files','legacy_completed_approvals') as $e) $writer->emptyEntity($e);
    foreach ($ids as $id) $writer->record('employees',array('legacy_id'=>$id,'employee_no'=>'ATT'.$id,'name'=>'Fixture employee '.$id,'hire_date'=>'2020-10-04','is_active'=>1));
    (new Cpms2AttendanceMigrationExportService($source))->run($writer,'2026-10-05');
    $packageSummary=$writer->finish($zipOutput,array('format'=>'cpms1-company-export','format_version'=>1,'source_system'=>'cpms1','export_id'=>'attendance-source-fixture','created_at'=>'2026-10-05T00:00:00+09:00'),array());
    $writer->cleanup(); attendance_export_assert($packageSummary['attendance_leave']===$s,'Actual ZIP summary / records');
}
$zipOutput=getenv('CPMS2_ATTENDANCE_FIXTURE_ZIP'); if ($zipOutput) {
    if (str_replace('\\','/',$zipOutput)!=='C:/Users/Public/Documents/ESTsoft/CreatorTemp/cpms-migration-mysql/attendance-fixture.zip') throw new RuntimeException('Fixture ZIP boundary');
    if (file_exists($zipOutput)) unlink($zipOutput); // Named synthetic fixture only.
    $stage=dirname($zipOutput).'/.attendance-stage-'.uniqid(); $writer=new Cpms2ExportPackageWriter($stage);
    foreach (array('departments','positions','employees','vendors','workers','direct_team','projects','project_members','project_roles','labor_workers','labor_months','labor_entries','material_items','material_usages','equipment_items','equipment_usages','subcontract_costs','safety_costs','progress_billings','material_statement_files','safety_evidence_files','legacy_completed_approvals') as $e) $writer->emptyEntity($e);
    foreach ($ids as $id) $writer->record('employees',array('legacy_id'=>$id,'employee_no'=>'ATT'.$id,'name'=>'Fixture employee '.$id,'hire_date'=>'2020-10-04','is_active'=>1));
    (new Cpms2AttendanceMigrationExportService($source))->run($writer,'2026-10-05');
    $packageSummary=$writer->finish($zipOutput,array('format'=>'cpms1-company-export','format_version'=>1,'source_system'=>'cpms1','export_id'=>'attendance-source-fixture','created_at'=>'2026-10-05T00:00:00+09:00'),array());
    $writer->cleanup(); attendance_export_assert($packageSummary['attendance_leave']===$s,'Actual ZIP summary / records');
}
$reject=function($label) use($source) { $failed=false; try { (new Cpms2AttendanceMigrationExportService($source))->run(null,'2026-10-05'); } catch (RuntimeException $e) { $failed=$e->getMessage()==='ORPHAN_LEAVE_EXCLUSION_SCOPE_CHANGED'; } attendance_export_assert($failed,$label); };
$db->exec('UPDATE cpms_leave_accrual_logs SET amount=2 WHERE id=329'); $reject('Changed amount blocked'); $db->exec('UPDATE cpms_leave_accrual_logs SET amount=0.5 WHERE id=329');
attendance_fixture_insert($db,'cpms_attendance_requests',array('id'=>999,'employee_id'=>39)); $reject('New orphan relation blocked'); $db->exec('DELETE FROM cpms_attendance_requests WHERE id=999');
$db->exec('DELETE FROM cpms_leave_accrual_logs WHERE id=329'); $reject('Changed count blocked');
attendance_fixture_insert($db,'cpms_leave_accrual_logs',array('id'=>329,'employee_id'=>39,'leave_type'=>'ANNUAL','accrual_date'=>'2026-01-01','accrual_year'=>2026,'amount'=>'0.50'));
$rejectCode=function($code,$label) use($source) { $failed=false; try { (new Cpms2AttendanceMigrationExportService($source))->run(null,'2026-10-05'); } catch (RuntimeException $e) { $failed=$e->getMessage()===$code; } attendance_export_assert($failed,$label); };
$db->exec("UPDATE cpms_approval_documents SET doc_status='REJECTED' WHERE id=2"); $c=new AttendanceMigrationCollector(); (new Cpms2AttendanceMigrationExportService($source))->run($c,'2026-10-05'); attendance_export_assert($c->rows['leave_approval_records'][1]['doc_status']==='REJECTED','Rejected result preserved'); $db->exec("UPDATE cpms_approval_documents SET doc_status='COMPLETED' WHERE id=2");
$db->exec("UPDATE cpms_approval_leave_deductions SET leave_bucket='MONTHLY' WHERE id=2"); $rejectCode('LEGACY_LEAVE_DOCUMENT_CONFLICT','Contradictory bucket blocked'); $db->exec("UPDATE cpms_approval_leave_deductions SET leave_bucket='ANNUAL' WHERE id=2");
$db->prepare('UPDATE cpms_approval_documents SET content=? WHERE id=2')->execute(array(json_encode(array('request_type'=>'반차 오전','leave_start_date'=>'2026-07-01','leave_end_date'=>'2026-07-01'))));
$db->exec("UPDATE cpms_approval_leave_deductions SET leave_type='반차 오전',deduct_amount=0.5 WHERE id=2"); $c=new AttendanceMigrationCollector(); (new Cpms2AttendanceMigrationExportService($source))->run($c,'2026-10-05'); attendance_export_assert($c->rows['leave_approval_records'][1]['leave_type']==='morning_half' && $c->rows['leave_approval_records'][1]['leave_bucket']==='annual','Half-day uses deduction bucket');
$db->exec('UPDATE cpms_attendance_records SET raw_minutes=-1 WHERE id=1'); $rejectCode('LEGACY_ATTENDANCE_ROW_INVALID','Negative minutes blocked'); $db->exec('UPDATE cpms_attendance_records SET raw_minutes=0 WHERE id=1');
$db->exec("UPDATE cpms_attendance_records SET status='UNKNOWN' WHERE id=1892"); $rejectCode('LEGACY_ATTENDANCE_ROW_INVALID','Unknown reversed status blocked');
echo 'Attendance migration export: OK ('.$checks.' checks, '.($dsn?'MySQL':'SQLite').', '.PHP_VERSION.")\n";
