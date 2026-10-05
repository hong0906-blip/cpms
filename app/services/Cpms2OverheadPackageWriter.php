<?php
// app/services/Cpms2OverheadPackageWriter.php
// PHP 5.6. Writes only normalized company-overhead data to a private staging directory.
require_once __DIR__.'/Cpms2ManagementPreflightSupport.php';
class Cpms2OverheadPackageWriter
{
    private $directory; private $paths=array();
    private static $entities=array('overhead_entries','overhead_vehicle_details','overhead_lease_details','overhead_card_details','overhead_fuel_details');
    public function __construct($directory)
    {
        $this->directory=$directory;
        if (file_exists($directory) || !mkdir($directory,0700,true) || !mkdir($directory.'/data',0700)) throw new RuntimeException('OVERHEAD_PRIVATE_STAGE_FAILED');
    }
    private function json($path,$data)
    {
        $json=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if ($json===false || file_put_contents($this->directory.'/'.$path,$json)===false) throw new RuntimeException('OVERHEAD_PACKAGE_WRITE_FAILED');
        $this->paths[]=$path;
    }
    private function jsonl($entity,$rows)
    {
        if (!in_array($entity,self::$entities,true)) throw new RuntimeException('OVERHEAD_ENTITY_NOT_ALLOWED');
        $path='data/'.$entity.'.jsonl'; $stream=fopen($this->directory.'/'.$path,'wb'); if (!$stream) throw new RuntimeException('OVERHEAD_PACKAGE_WRITE_FAILED');
        foreach ($rows as $row) {
            $json=json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            if ($json===false || fwrite($stream,$json."\n")!==strlen($json)+1) { fclose($stream); throw new RuntimeException('OVERHEAD_PACKAGE_WRITE_FAILED'); }
        }
        fclose($stream); $this->paths[]=$path;
    }
    private function assertReconciled($reconciliation)
    {
        if (!isset($reconciliation['grand_total']['difference']) || $reconciliation['grand_total']['difference']!=='0.00') throw new RuntimeException('OVERHEAD_RECONCILIATION_FAILED');
        foreach ($reconciliation['months'] as $row) if ($row['difference']!=='0.00') throw new RuntimeException('OVERHEAD_RECONCILIATION_FAILED');
        foreach ($reconciliation['categories'] as $row) if ($row['difference']!=='0.00') throw new RuntimeException('OVERHEAD_RECONCILIATION_FAILED');
    }
    public function finish($output,$plan,$manifest)
    {
        if (!class_exists('ZipArchive')) throw new RuntimeException('ZIP_EXTENSION_REQUIRED');
        $report=$plan['report']; $this->assertReconciled($report['export_reconciliation']);
        foreach (self::$entities as $entity) $this->jsonl($entity,isset($plan['rows'][$entity])?$plan['rows'][$entity]:array());
        $recognized=array(); foreach ($report['categories'] as $category=>$stats) $recognized[Cpms2OverheadExportService::categoryMapping()[$category]]=$stats['recognized_amount'];
        $warnings=array(); foreach ($plan['issues'] as $issue) if ($issue['severity']!=='BLOCKING') $warnings[]=array('code'=>$issue['code'],'count'=>$issue['count']);
        $summary=array('package_type'=>'cpms1_overhead_only','version'=>1,'cutoff_month'=>$report['cutoff_month'],'record_counts'=>$report['export_record_counts'],
            'recognized_amounts'=>$recognized,'grand_total'=>$report['grand_total'],'reconciliation'=>$report['export_reconciliation'],'warnings'=>$warnings,
            'source_hash_count'=>(int)$plan['source_hash_count']);
        $this->json('summary.json',$summary);
        $packagePaths=$this->paths; $entries=array(); foreach ($packagePaths as $path) $entries[$path]=array('sha256'=>hash_file('sha256',$this->directory.'/'.$path),'size'=>filesize($this->directory.'/'.$path));
        $manifest=array_merge($manifest,array('package_type'=>'cpms1_overhead_only','version'=>1,'cutoff_month'=>$report['cutoff_month'],
            'category_mapping'=>Cpms2OverheadExportService::categoryMapping(),'record_counts'=>$report['export_record_counts'],'recognized_amounts'=>$recognized,
            'grand_total'=>$report['grand_total'],'warnings'=>$warnings,'checksum_algorithm'=>'sha256','entries'=>$entries));
        $this->json('manifest.json',$manifest);
        if (file_exists($output)) throw new RuntimeException('OVERHEAD_OUTPUT_EXISTS');
        $zip=new ZipArchive(); if ($zip->open($output,ZipArchive::CREATE|ZipArchive::EXCL)!==true) throw new RuntimeException('OVERHEAD_ZIP_CREATE_FAILED');
        foreach (array_merge(array('manifest.json'),$packagePaths) as $path) if (!$zip->addFile($this->directory.'/'.$path,$path)) { $zip->close(); throw new RuntimeException('OVERHEAD_ZIP_ENTRY_FAILED'); }
        if (!$zip->close()) throw new RuntimeException('OVERHEAD_ZIP_FINALIZE_FAILED');
        chmod($output,0600); return $summary;
    }
    public function cleanup()
    {
        foreach (glob($this->directory.'/data/*') as $path) if (is_file($path)) unlink($path);
        if (is_dir($this->directory.'/data')) rmdir($this->directory.'/data');
        foreach (glob($this->directory.'/*.json') as $path) if (is_file($path)) unlink($path);
        if (is_dir($this->directory)) rmdir($this->directory);
    }
}
