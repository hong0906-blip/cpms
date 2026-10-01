<?php
// tests/cpms2_export_test.php
// PHP 5.6 compatible, no operating DB connection.
require_once dirname(__DIR__).'/app/services/Cpms2MigrationExportService.php';
$checks=0;
function migration_export_assert($ok,$label) { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }
foreach (array('INSERT INTO x VALUES (1)','UPDATE x SET y=1','DELETE FROM x','ALTER TABLE x ADD y INT','DROP TABLE x','CREATE TABLE x(id INT)','SELECT * FROM x INTO OUTFILE \'x\'','SELECT 1; DELETE FROM x','SELECT GET_LOCK(\'x\',1)') as $sql) {
    $rejected=false; try { Cpms2ReadOnlySource::assertReadOnly($sql); } catch (RuntimeException $e) { $rejected=true; } migration_export_assert($rejected,'Write SQL accepted.');
}
Cpms2ReadOnlySource::assertReadOnly('SELECT id FROM employees WHERE id > ?');
migration_export_assert(Cpms2MigrationExportService::department('관리팀')==='관리','Department normalization.');
$legacy=Cpms2MigrationExportService::legacy(array('id'=>17,'project_id'=>22,'name'=>'Fixture'));
migration_export_assert($legacy['legacy_id']===17 && $legacy['legacy_project_id']===22 && !isset($legacy['id']),'Legacy IDs.');
$rows=array(array('id'=>1,'worker_key'=>'fixture','work_date'=>'2026-07-20','status'=>'applied','new_value'=>1.5,'old_value'=>1,'is_deleted_entry'=>0),array('id'=>2,'worker_key'=>'fixture','work_date'=>'2026-07-20','status'=>'pending','new_value'=>2,'old_value'=>1.5,'is_deleted_entry'=>0));
$override=Cpms2LaborExportService::resolveOverrides($rows);
migration_export_assert($override['fixture']['2026-07-20']['value']===1.5,'Pending override replaced applied value.');
$rows[0]['status']='rejected'; $rows=array($rows[0]); $override=Cpms2LaborExportService::resolveOverrides($rows);
migration_export_assert($override['fixture']['2026-07-20']['value']===1.0,'Legacy old value restoration.');
migration_export_assert(cpms_att_calc_gongsu(600,120)===1.2,'Attendance gongsu.');
migration_export_assert(array_sum(Cpms2LaborExportService::allocate(100,array('a'=>1,'b'=>2,'c'=>3)))===100,'Monthly allocation conservation.');
class Cpms2ForceFixtureStatement
{
    private $rows;
    public function __construct($rows) { $this->rows=$rows; }
    public function fetch($mode=null) { return array_shift($this->rows); }
}
class Cpms2ForceFixtureSource
{
    public $exists=true; public $rows=array();
    public function inspect($table) { migration_export_assert($table==='cpms_labor_force_adjustments','Unexpected exclusion source.'); return $this->exists?array('id','project_id','month','amount'):array(); }
    public function query($sql) { Cpms2ReadOnlySource::assertReadOnly($sql); migration_export_assert(strpos($sql,'WHERE amount<>0 GROUP BY project_id,month')!==false,'Force exclusion query must aggregate nonzero project/month amounts.'); return new Cpms2ForceFixtureStatement($this->rows); }
}
$forceSource=new Cpms2ForceFixtureSource();
$forceSource->exists=false; migration_export_assert(Cpms2LaborExportService::excludedForceAdjustments($forceSource)['count']===0,'Missing force table not optional.');
$forceSource->exists=true; migration_export_assert(Cpms2LaborExportService::excludedForceAdjustments($forceSource)['amount']==='0.00','Empty force table not zero.');
$forceSource->rows=array(array('project_id'=>22,'month'=>'2026-07','excluded_count'=>3,'excluded_amount'=>'15000000.25'),array('project_id'=>22,'month'=>'2026-08','excluded_count'=>2,'excluded_amount'=>'-500000.25'),array('project_id'=>23,'month'=>'2026-07','excluded_count'=>3,'excluded_amount'=>'9000000.00'));
$excluded=Cpms2LaborExportService::excludedForceAdjustments($forceSource);
migration_export_assert($excluded['count']===8 && $excluded['amount']==='23500000.00','Excluded count or signed total changed.');
migration_export_assert($excluded['projects'][22]['amount']==='14500000.00' && $excluded['projects'][22]['months']['2026-08']['amount']==='-500000.25','Project/month exclusion totals changed.');
$warnings=Cpms2LaborExportService::exclusionWarnings($excluded);
migration_export_assert(count($warnings)===4 && strpos($warnings[0],'8건 / 총 23,500,000.00원')!==false && strpos($warnings[2],'2026-08')!==false,'Exclusion warning omitted.');
migration_export_assert(Cpms2ExportPackageWriter::moneyDecimal(Cpms2ExportPackageWriter::moneyCents('9999999999999.99'))==='9999999999999.99','Exclusion DECIMAL precision lost.');
$root=sys_get_temp_dir().'/cpms2-export-test-'.uniqid(); mkdir($root,0700);
$writer=new Cpms2ExportPackageWriter($root.'/package');
try {
    $file=$root.'/source.pdf'; file_put_contents($file,"%PDF-1.7\n%%EOF\n");
    $a=$writer->addFile($file,'first.pdf',1); $b=$writer->addFile($file,'second.pdf',2); $missing=$writer->addFile($root.'/absent.pdf','absent.pdf',3);
    migration_export_assert(Cpms2MigrationExportService::statementPath($file,$root,$root)===realpath($file),'Absolute statement path.');
    migration_export_assert(Cpms2MigrationExportService::statementPath('source.pdf',$root.'/unused',$root)===realpath($file),'Storage-relative statement path.');
    migration_export_assert(Cpms2MigrationExportService::statementPath($file,$root,$root.'/package')==='','Out-of-root statement path.');
    migration_export_assert($a['package_path']===$b['package_path'] && $missing['missing'],'SHA dedup/missing file.');
    $writer->record('employees',array('legacy_id'=>17,'name'=>'Fixture')); $writer->emptyEntity('workers');
    $writer->excludedLaborForce=$excluded; $writer->warnings=array_merge($writer->warnings,$warnings);
    $writer->amount(22,'labor',105000); $writer->amount(23,'labor',2250000);
    $writer->amount(24,'labor','30000000.01');
    $zipPath=$root.'/fixture.zip'; $summary=$writer->finish($zipPath,array('format'=>'cpms1-company-export','format_version'=>1,'export_id'=>'fixture-export','source_system'=>'cpms1'),array('employees'=>array('exists'=>true,'columns'=>array('id','name'))));
    migration_export_assert($summary['exported_file_count']===1 && $summary['missing_file_count']===1 && $summary['deduplicated_file_count']===1,'File summary.');
    migration_export_assert($summary['excluded_labor_force_adjustments']===$excluded && $summary['amounts']['company']['labor']==='32355000.01','Exclusions altered eligible labor or 32-bit integer overflow occurred.');
    migration_export_assert($summary['labor_reconciliation']['company']===array('source_original_labor'=>'55855000.01','excluded_force_amount'=>'23500000.00','source_migration_labor'=>'32355000.01'),'Original/excluded/migration labor reconciliation incorrect.');
    migration_export_assert($summary['labor_reconciliation']['projects'][22]['source_original_labor']==='14605000.00','Project labor reconciliation incorrect.');
    $zip=new ZipArchive(); $zip->open($zipPath); $manifest=json_decode($zip->getFromName('manifest.json'),true);
    migration_export_assert($manifest['format_version']===1 && $manifest['checksum_algorithm']==='sha256','Manifest.');
    migration_export_assert(json_decode($zip->getFromName('schema-report.json'),true)['employees']['exists'],'Schema report.');
    migration_export_assert($zip->getFromName('data/cpms_labor_force_adjustments.jsonl')===false && $zip->getFromName('data/labor_force_adjustments.jsonl')===false,'Excluded force rows packaged.');
    migration_export_assert(json_decode($zip->getFromName('summary.json'),true)['excluded_labor_force_adjustments']===$excluded,'Exclusion metadata missing from ZIP.');
    foreach (array('approval','contract','estimate','extra','progress_statements','health','quality','safety_document') as $excluded) for($i=0;$i<$zip->numFiles;$i++) migration_export_assert(strpos($zip->getNameIndex($i),$excluded)===false,'Excluded path exported.');
    foreach (Cpms2MigrationExportService::$fields as $table=>$fields) migration_export_assert(!preg_match('/resident|password|secret|token|cookie|session|credential/i',$fields),'Forbidden field whitelist.');
    $zip->close(); unlink($zipPath); unlink($file);
} catch (Exception $e) { $writer->cleanup(); throw $e; }
$writer->cleanup(); rmdir($root);
echo 'PASS: '.$checks." CPMS2 read-only export checks\n";
