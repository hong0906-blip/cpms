<?php
// tests/cpms2_reference_closure_mysql_test.php
// Synthetic dedicated source database, temporary files and SELECT-only export.
require_once dirname(__DIR__).'/app/services/Cpms2WebExportService.php';
$dsn=getenv('CPMS2_REFERENCE_FIXTURE_DSN');
if (!$dsn) { echo "Reference export MySQL fixture: SKIP (dedicated DSN required)\n"; exit; }
if (!preg_match('/host=127\.0\.0\.1;port=33419;dbname=cmdata_legacy_source_fixture_reference_[a-z0-9_]+(?:;|$)/',$dsn)) throw new RuntimeException('Local dedicated source fixture required.');
$db=new PDO($dsn,'root','',array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC));
$schemas=array(
 'employees'=>'id INT PRIMARY KEY,name VARCHAR(120),email VARCHAR(191),department VARCHAR(100),role VARCHAR(20),is_active INT',
 'cpms_vendors'=>'id INT PRIMARY KEY,name VARCHAR(120),biz_no VARCHAR(30),is_deleted INT',
 'workers'=>'id INT PRIMARY KEY,name VARCHAR(120),daily_wage INT,is_deleted INT',
 'direct_team_members'=>'id INT PRIMARY KEY,name VARCHAR(120),daily_wage INT,monthly_salary INT,is_deleted INT',
 'cpms_projects'=>'id INT PRIMARY KEY,name VARCHAR(191),status VARCHAR(30),is_deleted INT',
 'cpms_material_items'=>'id INT PRIMARY KEY,project_id INT,vendor_id INT,category VARCHAR(30),item_name VARCHAR(120),base_rate DECIMAL(18,2),is_deleted INT',
 'cpms_material_usage'=>'id INT PRIMARY KEY,project_id INT,material_id INT,use_date DATE,amount DECIMAL(18,2),is_deleted INT',
 'cpms_equipment_items'=>'id INT PRIMARY KEY,project_id INT,vendor_id INT,category VARCHAR(30),item_name VARCHAR(120),base_rate DECIMAL(18,2),is_deleted INT',
 'cpms_equipment_usage'=>'id INT PRIMARY KEY,project_id INT,equipment_id INT,use_date DATE,work_unit DECIMAL(10,4),base_rate_snapshot DECIMAL(18,2),amount DECIMAL(18,2)',
 'cpms_material_statement_files'=>'id INT PRIMARY KEY,project_id INT,material_id INT,material_usage_id INT,stored_path TEXT,original_name VARCHAR(100),file_size INT',
 'cpms_project_labor_workers'=>'id INT PRIMARY KEY,project_id INT,worker_id INT,direct_member_id INT,name VARCHAR(120),worker_name_snapshot VARCHAR(120),phone VARCHAR(30),daily_wage_snapshot INT,deposit_rate INT,company_name VARCHAR(120),agency_name_snapshot VARCHAR(120),job_type_snapshot VARCHAR(120),source_type VARCHAR(30),matched_status VARCHAR(30),vendor_id INT,biz_no VARCHAR(30),is_outsourcing INT,legacy_outsourcing_ratio INT,is_deleted INT',
 'cpms_project_labor_worker_months'=>'id INT PRIMARY KEY,project_id INT,labor_worker_id INT,month VARCHAR(7),outsourcing_ratio INT,outsourcing_ratio_is_set INT,outsourcing_start_date DATE,outsourcing_end_date DATE,is_deleted INT',
 'cpms_project_members'=>'project_id INT,employee_id INT,role VARCHAR(20)',
 'cpms_construction_roles'=>'project_id INT PRIMARY KEY,site_employee_id INT,safety_employee_id INT,quality_employee_id INT',
 'sites'=>'id INT PRIMARY KEY,name VARCHAR(191),active INT',
 'attendance'=>'id INT PRIMARY KEY,site_id INT,name VARCHAR(120),start_time_phone DATETIME,stop_time_phone DATETIME,total_minutes INT,status VARCHAR(20),role VARCHAR(30)'
);
foreach ($schemas as $table=>$columns) $db->exec('CREATE TABLE '.$table.'('.$columns.')');
$db->exec("INSERT INTO employees VALUES(17,'Fixture employee','reference@example.invalid','관리팀','employee',1);
INSERT INTO cpms_vendors VALUES(31,'Duplicate vendor','1234567890',0),(32,'Duplicate vendor','1234567891',0);
INSERT INTO workers VALUES(120,'Referenced worker',100000,1),(121,'Unused deleted worker',100000,1);
INSERT INTO direct_team_members VALUES(25,'Referenced direct',100000,0,1),(26,'Unused deleted direct',100000,0,1);
INSERT INTO cpms_projects VALUES(22,'Current project','진행중',0),(8,'Referenced project','정산완료',1),(29,'JSON safety project','정산완료',1),(9,'Unused deleted project','정산완료',1);
INSERT INTO cpms_material_items VALUES(11,8,31,'자재비','Referenced material',-530.25,1),(12,9,31,'자재비','Unused material',0,1);
INSERT INTO cpms_material_usage VALUES(815,8,11,'2026-07-20',30000,0),(816,8,11,'2026-07-20',-1000,1);
INSERT INTO cpms_equipment_items VALUES(31,8,31,'장비','Referenced equipment',12345,1),(32,9,31,'장비','Unused equipment',0,1);
INSERT INTO cpms_equipment_usage VALUES(201,8,31,'2026-07-20',1,12345,12345);
INSERT INTO cpms_project_labor_workers VALUES(315,8,120,NULL,'Referenced worker','Referenced worker','01012345678',100000,100000,'Duplicate vendor','Duplicate vendor','Fixture occupation','legacy','matched',31,NULL,1,30,0),(316,8,NULL,NULL,'Old name','Orphan snapshot',NULL,90000,90000,'Duplicate vendor','Duplicate vendor','Fixture occupation','legacy','unmatched',NULL,NULL,1,30,0),(317,8,1475,NULL,'Physically missing worker','Missing master snapshot',NULL,80000,80000,'Duplicate vendor','Duplicate vendor','Fixture occupation','legacy','unmatched',NULL,'1234567891',1,30,0),(318,8,NULL,25,'Referenced direct','Referenced direct',NULL,100000,100000,'','',NULL,'legacy','matched',NULL,NULL,0,0,0);
INSERT INTO cpms_project_labor_worker_months VALUES(1,8,315,'2026-07',30,1,NULL,NULL,0),(2,8,316,'2026-07',30,1,NULL,NULL,0),(3,8,317,'2026-07',30,1,NULL,NULL,0),(4,8,318,'2026-07',0,1,NULL,NULL,0);
INSERT INTO cpms_project_members VALUES(8,17,'main'); INSERT INTO cpms_construction_roles VALUES(8,17,NULL,NULL);
INSERT INTO sites VALUES(1,'Referenced project',1);
INSERT INTO attendance VALUES(1,1,'Referenced worker','2026-07-20 08:00:00','2026-07-20 17:00:00',540,'done','근로자'),(2,1,'Orphan snapshot','2026-07-20 08:00:00','2026-07-20 17:00:00',540,'done','근로자'),(3,1,'Missing master snapshot','2026-07-20 08:00:00','2026-07-20 17:00:00',540,'done','근로자'),(4,1,'Referenced direct','2026-07-20 08:00:00','2026-07-20 17:00:00',540,'done','근로자')");
$temporary=sys_get_temp_dir().'/cpms-reference-native-'.uniqid(); mkdir($temporary,0700); mkdir($temporary.'/web',0700); mkdir($temporary.'/storage/safety_costs',0700,true);
$pdf=$temporary.'/fixture.pdf'; file_put_contents($pdf,"%PDF-1.7\nreference fixture\n%%EOF\n"); $before=hash_file('sha256',$pdf);
$st=$db->prepare('INSERT INTO cpms_material_statement_files VALUES(?,8,11,?,?,?,?)');
$st->execute(array(1,815,$pdf,'fixture.pdf',filesize($pdf))); $st->execute(array(2,816,$pdf,'fixture.pdf',filesize($pdf)));
file_put_contents($temporary.'/storage/safety_costs/usage.json',json_encode(array('items'=>array(array('id'=>'json-reference','project_id'=>29,'vendor_id'=>31,'vendor_name'=>'Duplicate vendor','use_date'=>'2026-07-20','amount'=>'1250.50','category'=>'보호구 구입비','item_name'=>'Fixture safety')))));
$output=getenv('CPMS2_REFERENCE_FIXTURE_OUTPUT'); if (!$output) $output=$temporary.'/fixture.zip';
$db->exec('SET TRANSACTION READ ONLY'); $db->beginTransaction(); $checks=0;
function nativeReferenceAssert($ok,$label) { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }
try {
    $source=new Cpms2ReadOnlySource($db); $web=new Cpms2WebExportService($source,$source,$temporary,$temporary,$temporary.'/private',$temporary.'/web');
    $employee=$web->authorize(array('cpms_user'=>array('id'=>17,'email'=>'reference@example.invalid'))); $preflight=$web->preflight();
    nativeReferenceAssert($preflight['can_export'],'Historical closure preflight blocked.');
    $closure=$preflight['referenced_master_closure'];
    nativeReferenceAssert($closure['projects']['normal']===1 && $closure['projects']['reference_recovered']===2,'DB/JSON project dependency closure.');
    foreach (array('material_items','equipment_items','workers','direct_team','material_usages') as $entity) nativeReferenceAssert($closure[$entity]['reference_recovered']===1,'Master closure count: '.$entity);
    nativeReferenceAssert($closure['workers']['snapshot_only_labor_workers']===2 && $closure['workers']['physically_missing']===1,'Orphan snapshot classification.');
    nativeReferenceAssert($closure['labor_vendor']['legacy_vendor_id']===1 && $closure['labor_vendor']['unique_business_identity']===1 && $closure['labor_vendor']['snapshot_only']===2 && $closure['labor_vendor']['ambiguous']===1,'Vendor preflight safe identity.');
    $package=$web->generate($employee); $generated=$web->downloadPath($package['id'],$package,$employee); copy($generated,$output);
    nativeReferenceAssert($package['summary']['record_counts']['labor_workers']===4 && $package['summary']['record_counts']['labor_months']===4 && $package['summary']['record_counts']['labor_entries']===4,'Recovered project labor omitted.');
    nativeReferenceAssert($package['summary']['record_counts']['material_usages']===2 && $package['summary']['record_counts']['equipment_usages']===1 && $package['summary']['record_counts']['material_statement_files']===2,'Recovered child cost/evidence relation omitted.');
    nativeReferenceAssert($package['summary']['referenced_master_closure']===$closure,'Summary/preflight mismatch.');
    $zip=new ZipArchive(); $zip->open($output); $labor=array(); foreach (explode("\n",trim($zip->getFromName('data/labor_workers.jsonl'))) as $line) { $row=json_decode($line,true); $labor[$row['legacy_id']]=$row; }
    nativeReferenceAssert($labor[316]['legacy_snapshot_only'] && $labor[317]['legacy_snapshot_only'] && $labor[317]['legacy_worker_master_id']==1475 && $labor[316]['worker_name_snapshot']==='Orphan snapshot' && $labor[316]['source_type']==='legacy' && $labor[316]['matched_status']==='unmatched','Assignment snapshot provenance lost.'); $zip->close();
    nativeReferenceAssert(hash_file('sha256',$pdf)===$before && $db->query('SELECT COUNT(*) FROM cpms_projects')->fetchColumn()==4 && !is_dir($temporary.'/storage/secrets'),'Source or keys changed.');
    $db->commit();
} catch (Exception $e) { $db->rollBack(); throw $e; }
// Fixture mutation belongs to the test harness, never the exporter.
$db->exec('INSERT INTO cpms_equipment_usage VALUES(202,8,9999,\'2026-07-20\',1,100,100)');
$db->exec('SET TRANSACTION READ ONLY'); $db->beginTransaction();
$bad=(new Cpms2WebExportService(new Cpms2ReadOnlySource($db),new Cpms2ReadOnlySource($db),$temporary,$temporary,$temporary.'/private',$temporary.'/web'))->preflight();
nativeReferenceAssert(!$bad['can_export'] && $bad['failures'][0]['code']==='legacy_referenced_master_physically_missing','Physical missing cost master was hidden.'); $db->commit();
unlink($generated); unlink($temporary.'/private/employee-17.lock'); rmdir($temporary.'/private'); unlink($pdf); unlink($temporary.'/storage/safety_costs/usage.json'); rmdir($temporary.'/storage/safety_costs'); rmdir($temporary.'/storage'); rmdir($temporary.'/web'); if (!getenv('CPMS2_REFERENCE_FIXTURE_OUTPUT')) unlink($output); rmdir($temporary);
echo 'PASS: '.$checks." native reference export checks (SELECT-only, JSON projects, snapshot provenance, downloaded ZIP)\n";
