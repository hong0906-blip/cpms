<?php
// tests/cpms2_reference_closure_test.php
// PHP 5.6, synthetic in-memory database only. Source access is SELECT-only.
require_once dirname(__DIR__).'/app/services/Cpms2MigrationExportService.php';
class ReferenceSqliteSource extends Cpms2ReadOnlySource
{
    private $fixture;
    public $reads=0;
    public function __construct($fixture) { parent::__construct($fixture); $this->fixture=$fixture; }
    public function inspect($table,$mandatory=false,$required=array('id')) { return $this->columns($table); }
    public function columns($table) {
        $st=$this->fixture->query('PRAGMA table_info('.$table.')'); $columns=array();
        while ($row=$st->fetch(PDO::FETCH_ASSOC)) $columns[]=$row['name']; return $columns;
    }
    public function query($sql,$params=array()) { $this->reads++; return parent::query($sql,$params); }
}
$db=new PDO('sqlite::memory:',null,null,array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION));
$db->exec("CREATE TABLE cpms_projects(id INTEGER PRIMARY KEY,name TEXT,is_deleted INTEGER,deleted_at TEXT);
CREATE TABLE cpms_vendors(id INTEGER PRIMARY KEY,name TEXT,biz_no TEXT,is_deleted INTEGER);
CREATE TABLE cpms_material_items(id INTEGER PRIMARY KEY,project_id INTEGER,vendor_id INTEGER,category TEXT,item_name TEXT,is_deleted INTEGER);
CREATE TABLE cpms_equipment_items(id INTEGER PRIMARY KEY,project_id INTEGER,vendor_id INTEGER,category TEXT,item_name TEXT,is_deleted INTEGER);
CREATE TABLE workers(id INTEGER PRIMARY KEY,name TEXT,is_deleted INTEGER);
CREATE TABLE direct_team_members(id INTEGER PRIMARY KEY,name TEXT,is_deleted INTEGER);
CREATE TABLE cpms_material_usage(id INTEGER PRIMARY KEY,project_id INTEGER,material_id INTEGER,is_deleted INTEGER);
CREATE TABLE cpms_equipment_usage(id INTEGER PRIMARY KEY,project_id INTEGER,equipment_id INTEGER,is_deleted INTEGER);
CREATE TABLE cpms_material_statement_files(id INTEGER PRIMARY KEY,project_id INTEGER,material_id INTEGER,material_usage_id INTEGER);
CREATE TABLE cpms_project_labor_workers(id INTEGER PRIMARY KEY,project_id INTEGER,worker_id INTEGER,direct_member_id INTEGER,name TEXT,worker_name_snapshot TEXT,is_deleted INTEGER);
CREATE TABLE cpms_project_members(project_id INTEGER,employee_id INTEGER,role TEXT);
CREATE TABLE cpms_construction_roles(project_id INTEGER PRIMARY KEY,site_employee_id INTEGER);
INSERT INTO cpms_projects VALUES(1,'current',0,NULL),(8,'referenced',1,'2020-01-01'),(9,'unreferenced',1,NULL);
INSERT INTO cpms_vendors VALUES(1,'same name','1234567890',0),(2,'same name','1234567891',0),(3,'unique name','1234567892',0);
INSERT INTO cpms_material_items VALUES(11,8,1,'자재비','historical material',1),(12,9,1,'자재비','unreferenced material',1);
INSERT INTO cpms_equipment_items VALUES(31,8,1,'장비','historical equipment',1),(32,9,1,'장비','unreferenced equipment',1);
INSERT INTO workers VALUES(21,'historical worker',1),(22,'unused worker',1);
INSERT INTO direct_team_members VALUES(25,'historical direct',1),(26,'unused direct',1);
INSERT INTO cpms_material_usage VALUES(101,8,11,0),(102,8,11,1);
INSERT INTO cpms_equipment_usage VALUES(201,8,31,0);
INSERT INTO cpms_material_statement_files VALUES(301,8,11,102);
INSERT INTO cpms_project_labor_workers VALUES(401,8,21,NULL,'worker',NULL,0),(402,8,NULL,25,'direct',NULL,0),(403,8,NULL,NULL,'orphan','snapshot name',0),(404,8,1475,NULL,'missing master',NULL,0);
INSERT INTO cpms_project_members VALUES(8,1,'main'); INSERT INTO cpms_construction_roles VALUES(8,1);");
$db->exec('PRAGMA query_only=ON'); $source=new ReferenceSqliteSource($db);
$checks=0; function referenceAssert($ok,$label) { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }
$closure=new Cpms2ReferencedMasterClosure($source); $report=$closure->summary();
foreach (array('projects','material_items','equipment_items','workers','direct_team','material_usages') as $entity) referenceAssert($report[$entity]['reference_recovered']===1,'Referenced closure cardinality: '.$entity);
referenceAssert(!$closure->failures(),'Snapshot-recoverable source blocked.');
referenceAssert($report['workers']['physically_missing']===1 && $report['workers']['snapshot_only_labor_workers']===2,'Missing worker or snapshot-only counts.');
$projects=iterator_to_array($closure->rows('cpms_projects',array('id','name')),false);
referenceAssert(count($projects)===2 && $projects[1]['id']==8,'Unreferenced deleted project exported.');
referenceAssert($projects[1]['legacy_reference_only']===1 && $projects[1]['source_is_deleted']==1 && $projects[1]['recovered_by']==='referenced_master_closure','Reference metadata absent.');
$usage=iterator_to_array($closure->rows('cpms_material_usage',array('id','project_id','material_id')),false);
referenceAssert(count($usage)===2 && count(array_unique(array_column($usage,'id')))===2,'Statement dependency lost or duplicate rows.');
$vendor=$closure->vendorSnapshot(array('company_name'=>'same name'));
referenceAssert(!isset($vendor['legacy_vendor_id']) && $vendor['legacy_vendor_ambiguous'],'Ambiguous vendor selected.');
$vendor=$closure->vendorSnapshot(array('company_name'=>'unique name'));
referenceAssert(!isset($vendor['legacy_vendor_id']) && $vendor['legacy_vendor_unresolved'],'Name alone proved vendor identity.');
$vendor=$closure->vendorSnapshot(array('company_name'=>'same name','biz_no'=>'123-45-67891'));
referenceAssert($vendor['legacy_vendor_id']==2 && $vendor['legacy_vendor_resolution']==='unique_business_identity','Strong business identity was not resolved.');
$vendor=$closure->vendorSnapshot(array('vendor_id'=>1,'company_name'=>'same name'));
referenceAssert($vendor['legacy_vendor_id']==1 && $vendor['legacy_vendor_resolution']==='legacy_vendor_id','Explicit PK was not resolved.');
$db->exec('PRAGMA query_only=OFF'); $db->exec('INSERT INTO cpms_equipment_usage VALUES(202,8,999,0)'); $db->exec('PRAGMA query_only=ON');
$bad=new Cpms2ReferencedMasterClosure($source); $failures=$bad->failures();
referenceAssert(count($failures)===1 && $failures[0]['code']==='legacy_referenced_master_physically_missing' && $failures[0]['legacy_id']==='999','Physical missing item was synthesized.');
$blocked=false; try { $source->query('UPDATE workers SET name=?',array('changed')); } catch (RuntimeException $e) { $blocked=true; }
referenceAssert($blocked,'Source write was permitted.');
referenceAssert($db->query('SELECT COUNT(*) FROM cpms_projects')->fetchColumn()==3,'Source masters were modified.');
echo 'PASS: '.$checks." reference closure / source READ ONLY checks\n";
