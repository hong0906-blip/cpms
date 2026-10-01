<?php
// tests/cpms2_export_mysql_test.php
// Dedicated fixture DB and temporary statement files only. PHP 5.6 compatible.
require_once dirname(__DIR__).'/app/services/Cpms2MigrationExportService.php';
$dsn=getenv('CPMS2_EXPORT_FIXTURE_DSN');
if (!$dsn) { echo "Export MySQL fixture: SKIP (dedicated DSN required)\n"; exit; }
if (!preg_match('/dbname=cmdata_legacy_source_fixture(?:_[a-z0-9_]+)?(?:;|$)/',$dsn)) throw new RuntimeException('Dedicated source fixture DB required.');
$db=new PDO($dsn,getenv('CPMS2_EXPORT_FIXTURE_USER')?getenv('CPMS2_EXPORT_FIXTURE_USER'):'root',getenv('CPMS2_EXPORT_FIXTURE_PASSWORD')?getenv('CPMS2_EXPORT_FIXTURE_PASSWORD'):'',array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC));
// The test harness seeds its own mock source. The exporter receives SELECT-only access.
$tables=array(
 'employees'=>'id INT PRIMARY KEY,employee_no VARCHAR(30),name VARCHAR(120),email VARCHAR(191),department VARCHAR(100),position VARCHAR(100),is_active INT,is_team_leader INT,team_leader_id INT,hire_date DATE,password_hash TEXT,role VARCHAR(20)',
 'cpms_vendors'=>'id INT PRIMARY KEY,name VARCHAR(120),business_no VARCHAR(30),is_active INT',
 'workers'=>'id INT PRIMARY KEY,name VARCHAR(120),phone VARCHAR(30),daily_wage INT,agency_name VARCHAR(100),bank_account_enc TEXT,resident_no_enc TEXT,is_active INT',
 'direct_team_members'=>'id INT PRIMARY KEY,name VARCHAR(120),phone VARCHAR(30),monthly_salary INT,daily_wage INT,is_active INT',
 'cpms_projects'=>'id INT PRIMARY KEY,name VARCHAR(191),status VARCHAR(30),contract_amount DECIMAL(18,2)',
 'cpms_project_members'=>'project_id INT,employee_id INT,role VARCHAR(10)',
 'cpms_construction_roles'=>'id INT PRIMARY KEY,project_id INT,site_employee_id INT,safety_employee_id INT,quality_employee_id INT',
 'cpms_project_labor_workers'=>'id INT PRIMARY KEY,project_id INT,worker_id INT,direct_member_id INT,name VARCHAR(120),daily_wage_snapshot INT,deposit_rate INT,company_name VARCHAR(100),is_outsourcing INT,legacy_outsourcing_ratio INT,is_deleted INT',
 'cpms_project_labor_worker_months'=>'id INT PRIMARY KEY,project_id INT,labor_worker_id INT,month VARCHAR(7),outsourcing_ratio INT,outsourcing_ratio_is_set INT,outsourcing_start_date DATE,outsourcing_end_date DATE,is_deleted INT',
 'cpms_project_labor_worker_wages'=>'id INT PRIMARY KEY,project_id INT,labor_worker_id INT,effective_month VARCHAR(7),daily_wage INT',
 'cpms_labor_gongsu_overrides'=>'id INT PRIMARY KEY,project_id INT,worker_key VARCHAR(120),worker_name VARCHAR(120),work_date DATE,status VARCHAR(20),old_value DECIMAL(10,4),new_value DECIMAL(10,4),is_deleted_entry INT',
 'cpms_material_items'=>'id INT PRIMARY KEY,project_id INT,vendor_id INT,category VARCHAR(30),item_name VARCHAR(100),base_rate DECIMAL(18,2)',
 'cpms_material_usage'=>'id INT PRIMARY KEY,project_id INT,material_id INT,use_date DATE,amount DECIMAL(18,2),is_deleted INT',
 'cpms_equipment_items'=>'id INT PRIMARY KEY,project_id INT,vendor_id INT,category VARCHAR(30),item_name VARCHAR(100),base_rate DECIMAL(18,2)',
 'cpms_equipment_usage'=>'id INT PRIMARY KEY,project_id INT,equipment_id INT,use_date DATE,work_unit DECIMAL(10,4),base_rate_snapshot DECIMAL(18,2),amount DECIMAL(18,2)',
 'cpms_outsourcing_costs'=>'id INT PRIMARY KEY,project_id INT,expense_date DATE,vendor_name VARCHAR(100),amount DECIMAL(18,2),memo TEXT,is_deleted INT',
 'cpms_progress_billings'=>'id INT PRIMARY KEY,project_id INT,round_label VARCHAR(30),progress_date DATE,requested_amount DECIMAL(18,2),recognized_amount DECIMAL(18,2)',
 'cpms_material_statement_files'=>'id INT PRIMARY KEY,project_id INT,material_id INT,material_usage_id INT,original_name VARCHAR(100),stored_path TEXT,file_size INT,is_deleted INT',
 'sites'=>'id INT PRIMARY KEY,name VARCHAR(120),active INT',
 'attendance'=>'id INT PRIMARY KEY,site_id INT,name VARCHAR(120),start_time_phone DATETIME,stop_time_phone DATETIME,total_minutes INT,status VARCHAR(20),role VARCHAR(30)'
);
foreach ($tables as $name=>$columns) $db->exec('CREATE TABLE '.$name.'('.$columns.')');
$db->exec("INSERT INTO employees VALUES(17,'FIX17','Fixture employee','source-fixture@example.invalid','관리팀','과장',1,1,NULL,'2020-01-01','forbidden-password-hash','employee');
 INSERT INTO cpms_vendors VALUES(31,'Fixture vendor','123-45-67890',1);
 INSERT INTO workers VALUES(120,'Fixture worker','01012345678',100000,'Fixture vendor','forbidden-encrypted-account','forbidden-resident-number',1);
 INSERT INTO direct_team_members VALUES(125,'Fixture direct','01012345679',4500000,0,1);
 INSERT INTO cpms_projects VALUES(22,'Fixture project A','진행중',10000000),(23,'Fixture project B','정산완료',20000000);
 INSERT INTO cpms_project_members VALUES(22,17,'main');
 INSERT INTO cpms_construction_roles VALUES(1,22,17,NULL,NULL);
 INSERT INTO cpms_project_labor_workers VALUES(315,22,120,NULL,'Fixture worker',100000,100000,'Fixture vendor',1,30,0),(316,22,NULL,125,'Fixture direct',0,0,'',0,0,0),(317,23,NULL,125,'Fixture direct',0,0,'',0,0,0);
 INSERT INTO cpms_project_labor_worker_months VALUES(1,22,315,'2026-07',30,1,NULL,NULL,0),(2,22,316,'2026-07',0,1,NULL,NULL,0),(3,23,317,'2026-07',0,1,NULL,NULL,0);
 INSERT INTO cpms_project_labor_worker_wages VALUES(1,22,315,'2026-06',90000),(2,22,315,'2026-07',100000),(3,22,315,'2026-08',200000);
 INSERT INTO cpms_labor_gongsu_overrides VALUES(1,22,'fixture worker','Fixture worker','2026-07-20','applied',1,1.5,0),(2,22,'fixture worker','Fixture worker','2026-07-20','pending',1.5,2,0);
 INSERT INTO cpms_material_items VALUES(5,22,31,'자재비','Fixture material',0),(6,22,31,'안전관리비','Fixture helmet',0);
 INSERT INTO cpms_material_usage VALUES(815,22,5,'2026-07-20',30000,0),(816,22,5,'2026-07-20',-1000,0),(817,22,6,'2026-07-20',2000,0),(818,22,5,'2026-07-20',999,1);
 INSERT INTO cpms_equipment_items VALUES(8,22,31,'장비','Fixture equipment',10000);
 INSERT INTO cpms_equipment_usage VALUES(9,22,8,'2026-07-20',1.5,10000,14999),(10,22,8,'2026-07-21',0,NULL,0);
 INSERT INTO cpms_outsourcing_costs VALUES(10,22,'2026-07-20','Fixture vendor',5000,'Fixture subcontract',0);
 INSERT INTO cpms_progress_billings VALUES(11,22,'1차','2026-07-31',100000,0);
 INSERT INTO sites VALUES(1,'Fixture project A',1),(2,'Fixture project B',1);
 INSERT INTO attendance VALUES(1,1,'Fixture worker','2026-07-20 08:00:00','2026-07-20 17:00:00',540,'done','근로자'),(2,1,'Fixture direct','2026-07-20 08:00:00','2026-07-20 17:00:00',540,'done','근로자'),(3,2,'Fixture direct','2026-07-20 08:00:00','2026-07-20 17:00:00',540,'done','근로자'),(4,2,'Fixture direct','2026-07-21 08:00:00','2026-07-21 17:00:00',540,'done','근로자'),(5,1,'Fixture excluded','2026-07-20 08:00:00','2026-07-20 17:00:00',540,'done','장비기사')");
$temporary=sys_get_temp_dir().'/cpms-export-mysql-'.uniqid(); mkdir($temporary,0700);
$statement=$temporary.'/fixture.pdf'; file_put_contents($statement,"%PDF-1.7\n1 0 obj\n<<>>\nendobj\n%%EOF\n");
$st=$db->prepare('INSERT INTO cpms_material_statement_files VALUES(?,22,5,?,?,?,?,0)');
$st->execute(array(1,815,'fixture.pdf',$statement,filesize($statement))); $st->execute(array(2,816,'fixture.pdf',$statement,filesize($statement))); $st->execute(array(3,815,'missing.pdf',$temporary.'/absent.pdf',10));
$output=getenv('CPMS2_EXPORT_FIXTURE_OUTPUT'); if (!$output) $output=$temporary.'/fixture.zip';
require_once dirname(__DIR__).'/app/services/Cpms2WebExportService.php';
mkdir($temporary.'/web',0700);
$db->exec('SET TRANSACTION READ ONLY'); $db->beginTransaction();
try {
    $source=new Cpms2ReadOnlySource($db); $attendance=new Cpms2ReadOnlySource($db);
    $web=new Cpms2WebExportService($source,$attendance,dirname(__DIR__),$temporary,$temporary.'/private',$temporary.'/web');
    $employee=$web->authorize(array('cpms_user'=>array('id'=>17,'email'=>'source-fixture@example.invalid')));
    $preflight=$web->preflight();
    if ($preflight['expected_file_count']!==3 || $preflight['missing_file_count']!==1) throw new RuntimeException('Web preflight file counts mismatch.');
    $beforeHash=hash_file('sha256',$statement);
    $package=$web->generate($employee); $summary=$package['summary'];
    $generated=$web->downloadPath($package['id'],$package,$employee);
    if (!copy($generated,$output) || hash_file('sha256',$statement)!==$beforeHash) throw new RuntimeException('Source file was changed by web export.');
    if ($summary['record_counts']['labor_entries']!==4 || $summary['record_counts']['material_usages']!==2 || $summary['record_counts']['safety_costs']!==1 || $summary['exported_file_count']!==1 || $summary['missing_file_count']!==1) throw new RuntimeException('Fixture counts mismatch.');
    if ($summary['amounts']['projects'][22]['labor']!=='1605000.00' || $summary['amounts']['projects'][23]['labor']!=='3000000.00' || $summary['amounts']['company']['material']!=='29000.00' || $summary['amounts']['company']['equipment']!=='24999.00') throw new RuntimeException('Fixture source money mismatch.');
    $zip=new ZipArchive(); $zip->open($output); $employee=$zip->getFromName('data/employees.jsonl'); $workers=$zip->getFromName('data/workers.jsonl');
    if (strpos($employee,'forbidden-password')!==false || strpos($workers,'forbidden-')!==false) throw new RuntimeException('Sensitive field leaked.'); $zip->close();
    $db->commit();
} catch (Exception $e) { $db->rollBack(); throw $e; }
unlink($generated); unlink($temporary.'/private/employee-17.lock'); rmdir($temporary.'/private'); rmdir($temporary.'/web');
unlink($statement); if (!getenv('CPMS2_EXPORT_FIXTURE_OUTPUT')) unlink($output); rmdir($temporary);
echo "Export MySQL fixture: PASS (read-only transaction, schemas, wage history, attendance/override, salary allocation, safety split, equipment fallback, dedup/missing, exclusions)\n";
