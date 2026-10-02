<?php
// tests/cpms2_approved_exclusion_test.php
// PHP 5.6 synthetic source, SELECT-only policy/closure and temporary package.
require_once __DIR__.'/cpms2_reference_closure_test.php';
$checks=0;
$db=new PDO('sqlite::memory:',null,null,array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION));
$db->exec("CREATE TABLE cpms_projects(id INTEGER PRIMARY KEY,name TEXT,is_deleted INTEGER);
CREATE TABLE cpms_material_items(id INTEGER PRIMARY KEY,project_id INTEGER,category TEXT,item_name TEXT,is_deleted INTEGER);
CREATE TABLE cpms_material_usage(id INTEGER PRIMARY KEY,project_id INTEGER,material_id INTEGER,amount TEXT,is_deleted INTEGER);
CREATE TABLE cpms_equipment_items(id INTEGER PRIMARY KEY,project_id INTEGER,item_name TEXT,is_deleted INTEGER);
CREATE TABLE cpms_equipment_usage(id INTEGER PRIMARY KEY,project_id INTEGER,equipment_id INTEGER,amount TEXT,is_deleted INTEGER);
CREATE TABLE cpms_project_members(project_id INTEGER,employee_id INTEGER);
CREATE TABLE cpms_construction_roles(project_id INTEGER PRIMARY KEY,site_employee_id INTEGER);
CREATE TABLE cpms_progress_billings(id INTEGER PRIMARY KEY,project_id INTEGER,requested_amount TEXT,recognized_amount TEXT);
CREATE TABLE cpms_material_statement_files(id INTEGER PRIMARY KEY,project_id INTEGER,material_usage_id INTEGER);
CREATE TABLE cpms_project_labor_workers(id INTEGER PRIMARY KEY,project_id INTEGER,name TEXT,worker_name_snapshot TEXT);
CREATE TABLE cpms_outsourcing_costs(id INTEGER PRIMARY KEY,project_id INTEGER,amount TEXT);
CREATE TABLE cpms_ai_daily_snapshots(id INTEGER PRIMARY KEY,project_id INTEGER,project_name_snapshot TEXT);
INSERT INTO cpms_projects VALUES(22,'Current source project',0);
INSERT INTO cpms_material_items VALUES(11,22,'자재비','retained material',0);
INSERT INTO cpms_material_usage VALUES(20,22,11,'1000.00',0);
INSERT INTO cpms_equipment_items VALUES(100,8,'excluded referenced equipment',1),(200,22,'retained equipment',0);
INSERT INTO cpms_equipment_usage VALUES(1,8,100,'100000.00',0),(2,8,100,'40000.00',0),(20,22,200,'2000.00',0);
INSERT INTO cpms_project_members VALUES(8,1); INSERT INTO cpms_construction_roles VALUES(8,1);");
for ($i=1;$i<=10;$i++) $db->exec("INSERT INTO cpms_material_items VALUES($i,8,'자재비','excluded source item',0)");
for ($i=1;$i<=9;$i++) $db->exec("INSERT INTO cpms_material_usage VALUES($i,8,$i,'".($i===9?'-730175.00':'1000000.00')."',0)");
$db->exec('PRAGMA query_only=ON'); $source=new ReferenceSqliteSource($db);
$closure=new Cpms2ReferencedMasterClosure($source); $policy=$closure->exclusionPolicy(); $summary=$policy->summary(); $excluded=$summary['projects'][8];
referenceAssert(!$closure->failures() && $closure->summary()['projects']['approved_excluded']===1 && $closure->summary()['projects']['physically_missing']===0 && $closure->summary()['projects']['reference_recovered']===0,'Approved project not removed from required closure.');
referenceAssert($excluded['material_usage_count']===9 && $excluded['material_amount']==='7269825.00' && $excluded['material_negative_usage_count']===1 && $excluded['material_negative_amount']==='-730175.00','Signed material amounts/deductions incorrectly aggregated.');
referenceAssert($excluded['equipment_usage_count']===2 && $excluded['equipment_amount']==='140000.00' && $excluded['equipment_item_count']===1 && $excluded['equipment_reference_only_item_count']===1 && $excluded['total_cost_amount']==='7409825.00','Equipment physical/reference-only totals incorrect.');
foreach (array('labor','subcontract','safety','billing') as $kind) referenceAssert($excluded[$kind.'_amount']==='0.00' && $excluded[$kind.'_row_count']===0,'Protected domain not zero.');
foreach (array('cpms_projects','cpms_material_items','cpms_material_usage','cpms_equipment_items','cpms_equipment_usage') as $table) {
    $fields=array_values(array_intersect(array('id','project_id','amount'),$source->columns($table)));
    $rows=iterator_to_array($closure->rows($table,$fields),false);
    referenceAssert(count($rows)===1 && !isset($rows[0]['project_id']) || count($rows)===1 && $rows[0]['project_id']==22,'Excluded child/master leaked through central source filter.');
}
referenceAssert($db->query('SELECT COUNT(*) FROM cpms_material_usage WHERE project_id=8')->fetchColumn()==9 && $db->query('SELECT COUNT(*) FROM cpms_projects WHERE id=8')->fetchColumn()==0,'Source rows written.');
referenceAssert(Cpms2MigrationDecimal::add('2147483648.00','7409825.00')==='2154893473.00' && Cpms2MigrationDecimal::add('-10.05','0.06')==='-9.99' && Cpms2MigrationDecimal::add('-1.00','1.00')==='0.00','Exact signed / 32-bit-safe decimal addition failed.');
$temporary=sys_get_temp_dir().'/approved-exclusion-'.uniqid(); mkdir($temporary,0700);
$writer=new Cpms2ExportPackageWriter($temporary.'/stage'); $writer->exclusionPolicy=$policy;
foreach (array('material_usages'=>'cpms_material_usage','equipment_usages'=>'cpms_equipment_usage') as $entity=>$table) foreach ($closure->rows($table,array('id','project_id','amount')) as $row) { $writer->record($entity,Cpms2MigrationExportService::legacy($row)); $writer->amount($row['project_id'],$entity==='material_usages'?'material':'equipment',$row['amount']); }
$writer->record('project_members',array('legacy_id'=>1,'legacy_project_id'=>8)); $writer->emptyEntity('project_members');
$writer->record('project_roles',array('legacy_id'=>8,'legacy_project_id'=>8)); $writer->emptyEntity('project_roles');
$package=$writer->finish($temporary.'/fixture.zip',array(),array());
referenceAssert($package['record_counts']['project_members']===0 && $package['record_counts']['project_roles']===0,'Id-less relations escaped writer exclusion guard.');
referenceAssert($package['excluded_deleted_projects']['projects'][8]['condition_verified'] && $package['record_reconciliation']['material_usages']===array('original'=>10,'excluded_deleted_project'=>9,'migration_source'=>1),'Exclusion provenance or original counts missing.');
referenceAssert($package['deleted_project_reconciliation']['company']['material']===array('source_original'=>'7270825.00','excluded_deleted_project'=>'7269825.00','source_migration'=>'1000.00') && $package['deleted_project_reconciliation']['company']['equipment']['source_original']==='142000.00','Original/excluded/migration amounts not separated.');
require_once dirname(__DIR__).'/app/controllers/Cpms2ExportController.php';
$exportView=array('preflight'=>array('counts'=>array(),'referenced_master_closure'=>$closure->summary(),'expected_file_count'=>0,'missing_file_count'=>0,'can_export'=>true,'warnings'=>array()),'package'=>null,'error'=>'','csrf'=>'fixture-csrf');
ob_start(); require dirname(__DIR__).'/app/views/admin/cpms2_export.php'; $html=ob_get_clean();
referenceAssert(strpos($html,'사용자 승인 제외')!==false && strpos($html,'9건 / 7,269,825원')!==false && strpos($html,'2건 / 140,000원')!==false && strpos($html,'7,409,825원')!==false && strpos($html,' disabled')===false,'Approved preflight view missing or generation disabled.');
if (getenv('CPMS2_EXCLUSION_FIXTURE_HTML')) file_put_contents(getenv('CPMS2_EXCLUSION_FIXTURE_HTML'),$html);
$writer->cleanup(); unlink($temporary.'/fixture.zip'); rmdir($temporary);
$mutate=function($sql) use($db) { $db->exec('PRAGMA query_only=OFF'); $db->exec($sql); $db->exec('PRAGMA query_only=ON'); };
foreach (array("INSERT INTO cpms_progress_billings VALUES(1,8,'100.00','0.00')"=>'DELETE FROM cpms_progress_billings',"INSERT INTO cpms_material_statement_files VALUES(1,8,1)"=>'DELETE FROM cpms_material_statement_files',"INSERT INTO cpms_project_labor_workers VALUES(1,8,'synthetic worker','synthetic worker')"=>'DELETE FROM cpms_project_labor_workers',"INSERT INTO cpms_outsourcing_costs VALUES(1,8,'0.00')"=>'DELETE FROM cpms_outsourcing_costs',"UPDATE cpms_material_usage SET amount='999999.00' WHERE id=1"=>"UPDATE cpms_material_usage SET amount='1000000.00' WHERE id=1", "INSERT INTO cpms_projects VALUES(8,'New identity',0)"=>'DELETE FROM cpms_projects WHERE id=8',"INSERT INTO cpms_ai_daily_snapshots VALUES(1,8,'Recovered identity')"=>'DELETE FROM cpms_ai_daily_snapshots') as $insert=>$cleanup) {
    $mutate($insert); $changed=new Cpms2ReferencedMasterClosure($source);
    referenceAssert($changed->failures()[0]['code']==='legacy_exclusion_scope_changed' && $changed->exclusionPolicy()->summary()['count']===0,'Changed approval scope automatically excluded.'); $mutate($cleanup);
}
$exportView['preflight']['referenced_master_closure']=$changed->summary(); $exportView['preflight']['can_export']=false;
ob_start(); require dirname(__DIR__).'/app/views/admin/cpms2_export.php'; $html=ob_get_clean();
referenceAssert(strpos($html,'승인 제외 조건 변경 · Export 차단')!==false && strpos($html,' disabled')!==false,'Scope change view hid blocking.');
$mutate("INSERT INTO cpms_material_items VALUES(99,9,'자재비','unapproved orphan',0); INSERT INTO cpms_material_usage VALUES(99,9,99,'10.00',0)");
$unapproved=new Cpms2ReferencedMasterClosure($source);
referenceAssert($unapproved->failures()[0]['legacy_id']==='9' && $unapproved->failures()[0]['code']==='legacy_project_snapshot_unavailable' && $unapproved->summary()['projects']['approved_excluded']===1,'Unapproved identical missing project automatically excluded.');
$diagnostic=Cpms2ExportFailure::safe('projects',new RuntimeException('legacy_exclusion_scope_changed'));
referenceAssert($diagnostic->getMessage()==='legacy_exclusion_scope_changed','Safe scope change diagnostic lost.');
echo 'PASS: '.$checks." explicit exclusion / amounts / scope changes / no automatic exclusion / READ ONLY checks\n";
