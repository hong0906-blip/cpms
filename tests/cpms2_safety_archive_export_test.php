<?php
// tests/cpms2_safety_archive_export_test.php
// PHP 5.6 fixtures only; no application bootstrap or production config.
require_once dirname(__DIR__).'/app/services/Cpms2MigrationExportService.php';
$checks=0;
function archive_assert($ok,$message) { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
function archive_reject($call,$code) { try { call_user_func($call); } catch (RuntimeException $e) { archive_assert($e->getMessage()===$code,'Unexpected safe diagnostic.'); return; } throw new RuntimeException('Expected blocking diagnostic.'); }
class ArchiveFixtureStatement { private $rows; public function __construct($rows) { $this->rows=$rows; } public function fetch($mode=null) { return array_shift($this->rows); } }
class ArchiveFixtureSource
{
    public $data=array(); public $queries=array();
    public function inspect($table) { return isset($this->data[$table]) && $this->data[$table]?array_keys($this->data[$table][0]):array(); }
    public function columns($table) { return $this->inspect($table); }
    public function rows($table,$fields,$where='') {
        foreach (isset($this->data[$table])?$this->data[$table]:array() as $r) {
            if ($where && !in_array($r['doc_status'],array('APPROVED','COMPLETED'))) continue;
            yield array_intersect_key($r,array_flip($fields));
        }
    }
    public function query($sql,$params=array()) {
        Cpms2ReadOnlySource::assertReadOnly($sql); $this->queries[]=$sql;
        foreach ($this->data['cpms_material_items'] as $r) if ($r['id']===$params[0]) return new ArchiveFixtureStatement(array($r));
        return new ArchiveFixtureStatement(array());
    }
}
function archive_clean($path) { if (is_dir($path)) { foreach (scandir($path) as $n) if ($n!=='.' && $n!=='..') archive_clean($path.'/'.$n); rmdir($path); } else unlink($path); }
$root=sys_get_temp_dir().'/cpms2-safety-archive-'.uniqid(); mkdir($root,0700);
try {
    $db=new ArchiveFixtureSource(); $service=new Cpms2SafetyCostExportService($root,$root.'/storage');
    $result=$service->collect($db); archive_assert(!$result['summary']['store_found'] && $result['summary']['final_count']===0,'Missing JSON must be normal zero.');
    mkdir($root.'/storage/safety_costs/files/22/2026-07',0700,true);
    $path=$root.'/storage/safety_costs/usage.json'; $pdf=$root.'/storage/safety_costs/files/22/2026-07/fixture.pdf'; $bytes="%PDF-1.7\nfixture evidence\n%%EOF\n"; file_put_contents($pdf,$bytes);
    $item=array('id'=>'a','project_id'=>22,'use_date'=>'2026-07-20','vendor_id'=>31,'vendor_name'=>'Fixture vendor','item_name'=>'Helmet','category'=>'보호구 구입비','use_content'=>'Fixture safety','amount'=>'1000.25','status'=>'active','pdf'=>array('stored_path'=>'safety_costs/files/22/2026-07/fixture.pdf','original_name'=>'evidence.pdf','file_size'=>strlen($bytes)));
    $items=array($item,array_merge($item,array('id'=>'b','amount'=>null,'supply_amount'=>'2000.50','source'=>'material_bulk_import')),array_merge($item,array('id'=>'c','amount'=>'0','pdf'=>array('stored_path'=>'safety_costs/files/22/2026-07/missing.pdf'))),array_merge($item,array('id'=>'d','is_deleted'=>1)),array_merge($item,array('id'=>'e','status'=>'cancelled')));
    file_put_contents($path,json_encode(array('items'=>$items))); $result=$service->collect($db);
    archive_assert($result['summary']['json_total']===5 && $result['summary']['json_active']===3 && $result['summary']['json_excluded']===2,'Active/deleted safety classification.');
    archive_assert($result['summary']['amount']==='3000.75' && $result['summary']['bulk']===1 && $result['summary']['other']===2,'Decimal/fallback/zero/bulk source preservation.');
    archive_assert($result['summary']['pdf_available']===2 && $result['summary']['pdf_missing']===1 && $result['summary']['pdf_deduplicated']===1,'Safety PDF counts.');
    archive_assert($result['rows'][0]['legacy_id']==='safety-json:a' && $result['rows'][0]['category']==='보호구 구입비' && strpos($result['rows'][0]['memo'],'Fixture safety')!==false,'JSON namespace/category/content missing.');
    foreach (array('not json','{"items":null}','{"items":"bad"}','{"items":{}}','{"items":[],}','{"items":[]} trailing','{"items":[],"items":[]}') as $bad) { file_put_contents($path,$bad); archive_reject(function() use($service,$db) { $service->collect($db); },'SAFETY_STORE_INVALID'); }
    file_put_contents($path,json_encode(array('items'=>array(array_merge($item,array('amount'=>'bad','supply_amount'=>'7.25')))))); archive_assert($service->collect($db)['summary']['amount']==='7.25','Valid supply amount fallback lost.');
    file_put_contents($path,json_encode(array('items'=>array(array_merge($item,array('amount'=>'-7.25')))))); archive_assert($service->collect($db)['summary']['amount']==='-7.25','Historical negative adjustment lost.');
    foreach (array(array('project_id'=>0),array('use_date'=>'2026-02-31'),array('amount'=>'bad')) as $change) { file_put_contents($path,json_encode(array('items'=>array(array_merge($item,$change))))); archive_reject(function() use($service,$db) { $service->collect($db); },isset($change['amount'])?'SAFETY_AMOUNT_INVALID':'SAFETY_ROW_INVALID'); }
    file_put_contents($path,json_encode(array('items'=>array(array_merge($item,array('pdf'=>array('stored_path'=>'../outside.pdf'))))))); archive_reject(function() use($service,$db) { $service->collect($db); },'SAFETY_FILE_PATH_INVALID');
    file_put_contents($pdf,'bad PDF'); file_put_contents($path,json_encode(array('items'=>array($item)))); archive_reject(function() use($service,$db) { $service->collect($db); },'SAFETY_PDF_INVALID'); file_put_contents($pdf,$bytes);
    $db->data['cpms_material_items']=array(array('id'=>6,'project_id'=>22,'vendor_id'=>31,'vendor_name'=>'Fixture vendor','category'=>'안전관리비','item_name'=>'Helmet'));
    $db->data['cpms_material_usage']=array(array('id'=>10,'project_id'=>22,'material_id'=>6,'use_date'=>'2026-07-20','amount'=>'1000.25'));
    file_put_contents($path,json_encode(array('items'=>array($item)))); $result=$service->collect($db);
    archive_assert($result['summary']['possible_duplicates']===1 && $result['summary']['final_count']===2 && $result['summary']['amount']==='2000.50','Possible duplicate was lost.');
    file_put_contents($path,json_encode(array('items'=>array(array_merge($item,array('material_usage_id'=>10)))))); $result=$service->collect($db);
    archive_assert($result['summary']['deduplicated']===1 && $result['summary']['final_count']===1 && $result['dedup_usage_map']['db:10']==='safety-json:a','Confirmed link dedup mapping.');
    unlink($path); $result=$service->collect($db); archive_assert($result['summary']['db_only']===1 && $result['summary']['final_count']===1,'DB-only safety lost.');
    mkdir($root.'/storage/cache/approval_completed_pdf',0700,true);
    $cache=$root.'/storage/cache/approval_completed_pdf/'.sha1('fixture-local').'.pdf'; file_put_contents($cache,$bytes);
    $doc=array('id'=>1,'doc_type'=>'expense','title'=>'Fixture archive','doc_status'=>'APPROVED','project_id'=>22,'created_by_name'=>'Retired author','created_by_email'=>'retired@example.invalid','created_at'=>'2020-01-01 00:00:00','updated_at'=>'2020-01-02 00:00:00','completed_pdf_drive_file_id'=>'fixture-local','completed_pdf_name'=>'complete.pdf','completed_pdf_mime_type'=>'application/pdf','completed_pdf_size'=>strlen($bytes),'completed_pdf_upload_status'=>'uploaded','completed_pdf_storage_type'=>'google_drive');
    $db->data['cpms_approval_documents']=array($doc,array_merge($doc,array('id'=>2,'doc_status'=>'COMPLETED','completed_pdf_drive_file_id'=>'fixture-drive')),array_merge($doc,array('id'=>3,'doc_status'=>'PENDING')),array_merge($doc,array('id'=>4,'doc_status'=>'REJECTED')),array_merge($doc,array('id'=>5,'completed_pdf_drive_file_id'=>'','completed_pdf_upload_status'=>'')));
    $downloads=0; $drive=function($id,$dest) use($bytes,&$downloads) { $downloads++; archive_assert($id==='fixture-drive','Unexpected Drive ID.'); file_put_contents($dest,$bytes); return true; };
    $before=hash_file('sha256',$cache); $writer=new Cpms2ExportPackageWriter($root.'/package'); $archive=new Cpms2CompletedApprovalExportService($root.'/storage',$drive); $result=$archive->collect($db,$writer);
    archive_assert($result['summary']['completed_documents']===3 && $result['summary']['pdf_available']===2 && $result['summary']['not_generated']===1 && !$result['failures'],'Completed classification.');
    archive_assert($result['summary']['local_cache']===1 && $result['summary']['drive_download']===1 && $downloads===1 && hash_file('sha256',$cache)===$before,'Read-only local cache/Drive fallback.');
    $writer->approvalSummary=$result['summary']; $summary=$writer->finish($root.'/archive.zip',array(),array());
    archive_assert($summary['exported_file_count']===1 && $summary['deduplicated_file_count']===1,'Archive PDF SHA dedup.');
    $zip=new ZipArchive(); $zip->open($root.'/archive.zip'); $r=json_decode(strtok($zip->getFromName('data/legacy_completed_approvals.jsonl'),"\n"),true);
    archive_assert($r['sha256']===hash('sha256',$bytes) && $r['author_name']==='Retired author' && !isset($r['completed_pdf_drive_file_id']),'Minimal archive metadata/hash.'); $zip->close(); $writer->cleanup();
    $db->data['cpms_approval_documents']=array(array_merge($doc,array('completed_pdf_drive_file_id'=>'missing'))); $archive=new Cpms2CompletedApprovalExportService($root.'/storage',function($id,$dest) { return false; }); $result=$archive->collect($db);
    archive_assert($result['summary']['unavailable']===1 && $result['failures'][0]['code']==='COMPLETED_APPROVAL_PDF_UNAVAILABLE','Uploaded missing PDF not blocking.');
    file_put_contents($cache,'invalid cache'); $db->data['cpms_approval_documents']=array($doc); $result=$archive->collect($db); archive_assert(count($result['failures'])===1 && file_get_contents($cache)==='invalid cache','Invalid cache changed/generated a PDF.');
    $archive=new Cpms2CompletedApprovalExportService($root.'/storage',function($id,$dest) { file_put_contents($dest,'not a PDF'); return true; }); $result=$archive->collect($db); archive_assert($result['failures'][0]['code']==='COMPLETED_APPROVAL_PDF_INVALID','Invalid Drive PDF header not blocking.');
    foreach ($db->queries as $sql) archive_assert(preg_match('/^SELECT /',$sql),'Source DB write.');
    $source=file_get_contents(dirname(__DIR__).'/app/services/Cpms2ReadOnlyDriveDownload.php');
    foreach (array('cpms_drive_cache_write','cpms_drive_upload','cpms_drive_delete','cpms_approval_pdf_cache_store','cpms_approval_generate') as $forbidden) archive_assert(strpos($source,$forbidden)===false,'Forbidden Drive/cache/PDF side effect.');
    echo 'PASS: '.$checks." safety/archive read-only fixture checks\n";
} finally { archive_clean($root); }
