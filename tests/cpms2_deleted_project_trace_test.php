<?php
// tests/cpms2_deleted_project_trace_test.php
// PHP 5.6, isolated SQLite fixture with query_only; no real database/files.
require_once __DIR__.'/cpms2_reference_closure_test.php';
require_once dirname(__DIR__).'/app/services/Cpms2DeletedProjectTraceService.php';
require_once dirname(__DIR__).'/app/controllers/Cpms2ExportController.php';
$checks=0;
$db=new PDO('sqlite::memory:',null,null,array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION));
$db->exec("CREATE TABLE cpms_projects(id INTEGER PRIMARY KEY,name TEXT);
CREATE TABLE cpms_material_items(id INTEGER PRIMARY KEY,project_id INTEGER,vendor_name TEXT,category TEXT,item_name TEXT,spec TEXT,remark TEXT,base_rate TEXT,bank_account TEXT);
CREATE TABLE cpms_material_usage(id INTEGER PRIMARY KEY,project_id INTEGER,material_id INTEGER,use_date TEXT,amount TEXT,memo TEXT);
CREATE TABLE cpms_equipment_items(id INTEGER PRIMARY KEY,project_id INTEGER,vendor_name TEXT,category TEXT,item_name TEXT,equipment_name TEXT,spec TEXT,remark TEXT);
CREATE TABLE cpms_equipment_usage(id INTEGER PRIMARY KEY,project_id INTEGER,equipment_id INTEGER,use_date TEXT,amount TEXT,work_unit TEXT,memo TEXT);
CREATE TABLE cpms_material_statement_files(id INTEGER PRIMARY KEY,material_usage_id INTEGER,original_name TEXT,use_date TEXT,ym TEXT,uploaded_at TEXT,stored_path TEXT);
CREATE TABLE cpms_outsourcing_costs(id INTEGER PRIMARY KEY,project_id INTEGER,expense_date TEXT,company_name TEXT,content TEXT,memo TEXT,phone TEXT,account_number TEXT);
CREATE TABLE cpms_progress_billings(id INTEGER PRIMARY KEY,project_id INTEGER,round_label TEXT,progress_date TEXT,requested_amount TEXT,recognized_amount TEXT,remark TEXT);
INSERT INTO cpms_projects VALUES(9,'Another project');
INSERT INTO cpms_material_items VALUES(1,8,'Fixture supplier','자재비','Steel','H300','전화: 010-9876-5432; 계좌: 123-456-789012; 담당자: 민감담당자', '12000000.50','hidden-bank-field'),(2,9,'Excluded supplier','자재비','Excluded material',NULL,NULL,'1',NULL);
INSERT INTO cpms_material_usage VALUES(1,8,1,'2020-01-03','99000000.50','<script>alert(1)</script> reference@example.invalid 900101-1234567'),(2,NULL,1,'2020-02-04','100',NULL),(3,9,1,'2010-01-01','1','other explicit project'),(4,9,2,'2000-01-01','1','Excluded memo');
INSERT INTO cpms_equipment_items VALUES(1,8,'Fixture supplier','장비','', 'Excavator','20T',NULL),(2,9,'Excluded supplier','장비','Excluded equipment',NULL,NULL,NULL);
INSERT INTO cpms_equipment_usage VALUES(1,8,1,'2021-03-05','12300000','1.25','existing clue');
INSERT INTO cpms_material_statement_files VALUES(1,1,'C:/private/fixture-statement.pdf','2020-01-03','2020-01','2020-01-04 09:00:00','private-secret-path'),(2,4,'Excluded file.pdf','2000-01-01','2000-01',NULL,NULL);
INSERT INTO cpms_outsourcing_costs VALUES(1,8,'2019-12-02','Fixture subcontractor','Existing work','예금주: 민감예금주; bank account 987.654.321012','hidden-phone-field','hidden-account-field');
INSERT INTO cpms_progress_billings VALUES(1,8,'1차','2022-04-06','12500000.00','12000000.00','existing billing');");
$db->exec('PRAGMA query_only=ON'); $source=new ReferenceSqliteSource($db); $service=new Cpms2DeletedProjectTraceService($source);
$trace=$service->collect(8);
referenceAssert($trace['first_use_date']==='2019-12-02' && $trace['last_use_date']==='2022-04-06','Trace period aggregation incorrect.');
referenceAssert($trace['counts']['cpms_material_items']===1 && $trace['counts']['cpms_material_usage']===2 && $trace['counts']['cpms_equipment_items']===1 && $trace['counts']['cpms_equipment_usage']===1,'Scope or count failed.');
referenceAssert($trace['counts']['cpms_material_statement_files']===1 && $trace['counts']['cpms_outsourcing_costs']===1 && $trace['progress_billing_exists']===true,'Usage-only statement scope or optional costs lost.');
referenceAssert($trace['lists']['cpms_material_items_vendors']['values']===array('Fixture supplier') && $trace['lists']['cpms_equipment_items_names']['values']===array('Excavator','20T'),'Unique source names incorrect.');
referenceAssert($trace['lists']['statement_names']['values']===array('fixture-statement.pdf'),'Filename exposed directory.');
$encoded=json_encode($trace,JSON_UNESCAPED_UNICODE);
foreach (array('Excluded','other explicit project','private-secret-path','C:/private','hidden-bank-field','hidden-phone-field','hidden-account-field','010-9876-5432','123-456-789012','900101-1234567','987.654.321012','reference@example.invalid','민감담당자','민감예금주') as $secret) referenceAssert(strpos($encoded,$secret)===false,'Sensitive or unrelated trace information exposed.');
referenceAssert($trace['details']['cpms_material_items'][0]['base_rate']==='12000000.50' && $trace['details']['cpms_material_usage'][0]['amount']==='99000000.50','Financial amounts redacted or changed.');
referenceAssert($trace['details']['cpms_material_statement_files'][0]['ym']==='2020-01' && $trace['details']['cpms_material_statement_files'][0]['uploaded_at']==='2020-01-04 09:00:00','Date fields accidentally redacted.');
$closure=new Cpms2ReferencedMasterClosure($source);
referenceAssert($closure->summary()['projects']['physically_missing']===1 && $closure->failures()[0]['code']==='legacy_project_snapshot_unavailable','Trace implicitly recovered project or unblocked Export.');
referenceAssert($db->query('SELECT COUNT(*) FROM cpms_projects')->fetchColumn()==1,'Trace created missing master.');
$empty=$service->collect(9); referenceAssert($empty['progress_billing_exists']===false,'Empty billing confused with missing table.');
$bad=false; try { $service->collect('8 OR 1=1'); } catch (RuntimeException $e) { $bad=true; } referenceAssert($bad,'Unsafe identifier accepted.');
// Synthetic fixture writes are separate from the SELECT-only service.
$db->exec('PRAGMA query_only=OFF');
foreach (array('cpms_material_items','cpms_material_usage','cpms_equipment_items','cpms_equipment_usage','cpms_material_statement_files','cpms_outsourcing_costs','cpms_progress_billings') as $table) {
    $columns=$source->columns($table); $insertFields=array('id'); if (in_array('project_id',$columns)) $insertFields[]='project_id';
    if ($table==='cpms_material_statement_files') $insertFields[]='material_usage_id';
    if ($table==='cpms_material_items') $insertFields[]='vendor_name';
    $st=$db->prepare('INSERT INTO '.$table.'('.implode(',',$insertFields).') VALUES('.implode(',',array_fill(0,count($insertFields),'?')).')');
    for ($i=100;$i<250;$i++) { $values=array($i); if (in_array('project_id',$columns)) $values[]=8; if ($table==='cpms_material_statement_files') $values[]=1; if ($table==='cpms_material_items') $values[]='Fixture supplier '.$i; $st->execute($values); }
}
$db->exec('PRAGMA query_only=ON'); $bounded=$service->collect(8);
referenceAssert($bounded['detail_count']===100 && count($bounded['details']['cpms_outsourcing_costs'])===10,'Details exceeded overall bound or omitted later sources.');
referenceAssert($bounded['counts']['cpms_material_items']===151 && $bounded['counts']['cpms_progress_billings']===151,'Limited samples incorrectly used as totals.');
referenceAssert(count($bounded['lists']['cpms_material_items_vendors']['values'])===20 && $bounded['lists']['cpms_material_items_vendors']['truncated'],'Unique lists not bounded.');
// Two trace cards still have exactly one representative Guide target.
$exportView=array('preflight'=>array('counts'=>array(),'referenced_master_closure'=>array('deleted_project_traces'=>array($trace,$bounded)),'expected_file_count'=>0,'missing_file_count'=>0,'can_export'=>false,'warnings'=>array()),'package'=>null,'error'=>'','csrf'=>'fixture-csrf');
ob_start(); require dirname(__DIR__).'/app/views/admin/cpms2_export.php'; $html=ob_get_clean();
referenceAssert(substr_count($html,'data-guide="admin-cpms2-project-trace"')===1 && substr_count($html,'<details>')===2,'Guide duplicate or native disclosure missing.');
referenceAssert(strpos($html,'&lt;script&gt;alert(1)&lt;/script&gt;')!==false && strpos($html,'<script>alert(1)</script>')===false,'Trace HTML not escaped.');
referenceAssert(strpos($html,'최대 100건')!==false && strpos($html,'전체 Export ZIP 생성</button>')!==false && strpos($html,' disabled')!==false,'Preflight display or export gate regression.');
$guide=file_get_contents(dirname(__DIR__).'/public/assets/js/guide-tour.js');
referenceAssert(substr_count($guide,'data-guide="admin-cpms2-project-trace"')===1,'New interaction Guide missing/duplicated.');
if (getenv('CPMS2_TRACE_FIXTURE_HTML')) file_put_contents(getenv('CPMS2_TRACE_FIXTURE_HTML'),$html);
$db->exec('PRAGMA query_only=OFF'); $db->exec('DROP TABLE cpms_outsourcing_costs; DROP TABLE cpms_progress_billings'); $db->exec('PRAGMA query_only=ON');
$optional=$service->collect(8);
referenceAssert($optional['counts']['cpms_outsourcing_costs']===null && $optional['progress_billing_exists']===null && count($optional['unavailable_sources'])===2,'Missing optional schema blocked trace or claimed zero rows.');
echo 'PASS: '.$checks." deleted project trace / bounded details / privacy / READ ONLY / Guide checks\n";
