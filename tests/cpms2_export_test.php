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
    $zipPath=$root.'/fixture.zip'; $summary=$writer->finish($zipPath,array('format'=>'cpms1-company-export','format_version'=>1,'export_id'=>'fixture-export','source_system'=>'cpms1'),array('employees'=>array('exists'=>true,'columns'=>array('id','name'))));
    migration_export_assert($summary['exported_file_count']===1 && $summary['missing_file_count']===1 && $summary['deduplicated_file_count']===1,'File summary.');
    $zip=new ZipArchive(); $zip->open($zipPath); $manifest=json_decode($zip->getFromName('manifest.json'),true);
    migration_export_assert($manifest['format_version']===1 && $manifest['checksum_algorithm']==='sha256','Manifest.');
    migration_export_assert(json_decode($zip->getFromName('schema-report.json'),true)['employees']['exists'],'Schema report.');
    foreach (array('approval','contract','estimate','extra','progress_statements','health','quality','safety_document') as $excluded) for($i=0;$i<$zip->numFiles;$i++) migration_export_assert(strpos($zip->getNameIndex($i),$excluded)===false,'Excluded path exported.');
    foreach (Cpms2MigrationExportService::$fields as $table=>$fields) migration_export_assert(!preg_match('/resident|password|secret|token|cookie|session|credential/i',$fields),'Forbidden field whitelist.');
    $zip->close(); unlink($zipPath); unlink($file);
} catch (Exception $e) { $writer->cleanup(); throw $e; }
$writer->cleanup(); rmdir($root);
echo 'PASS: '.$checks." CPMS2 read-only export checks\n";
