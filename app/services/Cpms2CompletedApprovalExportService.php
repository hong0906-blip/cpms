<?php
// app/services/Cpms2CompletedApprovalExportService.php
require_once __DIR__.'/Cpms2SafetyCostExportService.php';
class Cpms2CompletedApprovalExportService
{
    private $storage; private $download;
    public function __construct($storage,$download=null) { $this->storage=$storage; $this->download=$download; }
    public function collect($db,$writer=null)
    {
        $s=array('completed_documents'=>0,'approved'=>0,'completed'=>0,'pdf_metadata'=>0,'pdf_available'=>0,'not_generated'=>0,'unavailable'=>0,'local_cache'=>0,'drive_download'=>0,'pdf_bytes'=>0);
        $failures=array(); $warnings=array();
        if (!$db->inspect('cpms_approval_documents')) return array('summary'=>$s,'failures'=>$failures,'warnings'=>array('COMPLETED_APPROVAL_SOURCE_NOT_FOUND'));
        $fields=explode(' ','id doc_type title doc_status project_id created_by_name created_by_email created_at updated_at completed_pdf_storage_type completed_pdf_drive_file_id completed_pdf_name completed_pdf_mime_type completed_pdf_size completed_pdf_uploaded_at completed_pdf_upload_status');
        foreach ($db->rows('cpms_approval_documents',$fields,"doc_status IN ('APPROVED','COMPLETED')") as $doc) {
            $s['completed_documents']++; $s[$doc['doc_status']==='APPROVED'?'approved':'completed']++;
            $fileId=isset($doc['completed_pdf_drive_file_id'])?trim((string)$doc['completed_pdf_drive_file_id']):'';
            $uploaded=isset($doc['completed_pdf_upload_status']) && in_array(strtolower($doc['completed_pdf_upload_status']),array('uploaded','success','completed'));
            if ($fileId==='' && !$uploaded) { $s['not_generated']++; continue; }
            $s['pdf_metadata']++; $expected=isset($doc['completed_pdf_size'])?(int)$doc['completed_pdf_size']:0;
            $cache=$this->storage.'/cache/approval_completed_pdf';
            $path=Cpms2MigrationExportService::statementPath($cache.'/'.sha1($fileId).'.pdf',$this->storage,$cache); $temp=''; $via='local_cache';
            try {
                if (!Cpms2SafetyCostExportService::pdf($path,$expected)) {
                    $path=''; $via='drive_download';
                    if ($fileId!=='') {
                        $temp=tempnam(sys_get_temp_dir(),'cpms2-approval-'); if (!$temp) throw new RuntimeException('COMPLETED_APPROVAL_PDF_UNAVAILABLE'); chmod($temp,0600);
                        if ($this->download===null) { require_once __DIR__.'/Cpms2ReadOnlyDriveDownload.php'; $adapter=new Cpms2ReadOnlyDriveDownload(); $this->download=array($adapter,'download'); }
                        if (call_user_func($this->download,$fileId,$temp)) {
                            if (!Cpms2SafetyCostExportService::pdf($temp,$expected)) { $s['unavailable']++; $failures[]=array('legacy_id'=>$doc['id'],'code'=>'COMPLETED_APPROVAL_PDF_INVALID'); continue; }
                            $path=$temp;
                        }
                    }
                }
                if ($path==='') { $s['unavailable']++; $failures[]=array('legacy_id'=>$doc['id'],'code'=>'COMPLETED_APPROVAL_PDF_UNAVAILABLE'); continue; }
                if (!empty($doc['completed_pdf_mime_type']) && strtolower($doc['completed_pdf_mime_type'])!=='application/pdf') { $s['unavailable']++; $failures[]=array('legacy_id'=>$doc['id'],'code'=>'COMPLETED_APPROVAL_PDF_INVALID'); continue; }
                if (empty($doc['completed_pdf_mime_type'])) $warnings[]='COMPLETED_APPROVAL_PDF_MIME_MISSING: '.$doc['id'];
                $s['pdf_available']++; $s[$via]++; $s['pdf_bytes']+=filesize($path);
                if ($writer) {
                    $name=!empty($doc['completed_pdf_name'])?basename(str_replace('\\','/',$doc['completed_pdf_name'])):'approval_'.$doc['id'].'.pdf'; if (strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='pdf') $name.='.pdf';
                    $r=array('legacy_id'=>(string)$doc['id'],'legacy_document_id'=>(string)$doc['id'],'doc_type'=>isset($doc['doc_type'])?$doc['doc_type']:'','title'=>$doc['title'],'legacy_project_id'=>!empty($doc['project_id'])?$doc['project_id']:null,'author_name'=>isset($doc['created_by_name'])?$doc['created_by_name']:null,'author_email'=>isset($doc['created_by_email'])?$doc['created_by_email']:null,'source_created_at'=>isset($doc['created_at'])?$doc['created_at']:null,'source_completed_at'=>!empty($doc['completed_pdf_uploaded_at'])?$doc['completed_pdf_uploaded_at']:(isset($doc['updated_at'])?$doc['updated_at']:null),'original_name'=>$name,'mime_type'=>'application/pdf');
                    $writer->record('legacy_completed_approvals',array_merge($r,$writer->addFile($path,$name,'approval:'.$doc['id'])));
                }
            } finally { if ($temp!=='' && is_file($temp)) unlink($temp); }
        }
        return array('summary'=>$s,'failures'=>$failures,'warnings'=>$warnings);
    }
}
