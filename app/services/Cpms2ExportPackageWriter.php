<?php
// app/services/Cpms2ExportPackageWriter.php
class Cpms2ExportPackageWriter
{
    public $counts = array();
    public $warnings = array();
    public $missing = array();
    public $expectedFiles = 0;
    public $fileBytes = 0;
    private $directory;
    private $streams = array();
    private $files = array();
    private $entries = array();
    private $totals = array();
    public function __construct($directory) { $this->directory=$directory; if (!mkdir($directory,0700,true)) throw new RuntimeException('Cannot create private package staging directory.'); mkdir($directory.'/data',0700); mkdir($directory.'/files',0700); }
    public function record($entity, $row)
    {
        if (!isset($this->streams[$entity])) { $this->streams[$entity]=fopen($this->directory.'/data/'.$entity.'.jsonl','wb'); $this->counts[$entity]=0; }
        $json=json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if ($json===false || fwrite($this->streams[$entity],$json."\n")!==strlen($json)+1) throw new RuntimeException('Cannot write package record.');
        $this->counts[$entity]++;
    }
    public function emptyEntity($entity) { if (!isset($this->counts[$entity])) { $this->streams[$entity]=fopen($this->directory.'/data/'.$entity.'.jsonl','wb'); $this->counts[$entity]=0; } }
    public function amount($project, $kind, $amount)
    {
        $cents=(int)round((float)$amount*100);
        if (!isset($this->totals[$project])) $this->totals[$project]=array();
        if (!isset($this->totals[$project][$kind])) $this->totals[$project][$kind]=0;
        $this->totals[$project][$kind]+=$cents;
    }
    public function addFile($path, $original, $legacyId)
    {
        $this->expectedFiles++;
        if (!is_file($path) || !is_readable($path)) { $this->missing[]=$legacyId; $this->warnings[]='Missing or unreadable statement file, legacy_id='.$legacyId; return array('missing'=>true); }
        $extension=strtolower(pathinfo($original,PATHINFO_EXTENSION));
        if (!in_array($extension,array('pdf','jpg','jpeg','png','webp','heic','heif','xls','xlsx'))) { $this->warnings[]='Unsupported statement extension, legacy_id='.$legacyId; $this->missing[]=$legacyId; return array('missing'=>true); }
        $sha=hash_file('sha256',$path);
        if (!isset($this->files[$sha])) {
            $entry='files/'.$sha.'.'.$extension;
            if (!copy($path,$this->directory.'/'.$entry)) throw new RuntimeException('Cannot stage statement file.');
            $size=filesize($this->directory.'/'.$entry);
            if (hash_file('sha256',$this->directory.'/'.$entry)!==$sha) throw new RuntimeException('Source file changed during export.');
            $this->files[$sha]=array('package_path'=>$entry,'sha256'=>$sha,'file_size'=>$size);
            $this->fileBytes+=$size;
        }
        return $this->files[$sha]+array('missing'=>false);
    }
    private function json($path,$data)
    {
        $json=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if ($json===false || file_put_contents($this->directory.'/'.$path,$json)===false) throw new RuntimeException('Cannot write package metadata.');
    }
    public function finish($output,$manifest,$schema)
    {
        foreach ($this->streams as $stream) fclose($stream);
        $this->streams=array();
        $total=array(); $projects=array();
        foreach ($this->totals as $project=>$amounts) foreach ($amounts as $kind=>$cents) { $projects[$project][$kind]=sprintf('%.2f',$cents/100); if (!isset($total[$kind])) $total[$kind]=0; $total[$kind]+=$cents; }
        foreach ($total as $kind=>$cents) $total[$kind]=sprintf('%.2f',$cents/100);
        $summary=array('record_counts'=>$this->counts,'amounts'=>array('projects'=>$projects,'company'=>$total),'expected_file_count'=>$this->expectedFiles,'exported_file_count'=>count($this->files),'missing_file_count'=>count($this->missing),'missing_file_rows'=>$this->missing,'deduplicated_file_count'=>$this->expectedFiles-count($this->missing)-count($this->files),'warnings'=>$this->warnings);
        $this->json('schema-report.json',$schema); $this->json('summary.json',$summary);
        $paths=array('schema-report.json','summary.json');
        foreach ($this->counts as $entity=>$count) $paths[]='data/'.$entity.'.jsonl';
        foreach ($this->files as $file) $paths[]=$file['package_path'];
        foreach ($paths as $path) $this->entries[$path]=array('sha256'=>hash_file('sha256',$this->directory.'/'.$path),'size'=>filesize($this->directory.'/'.$path));
        $manifest+=array('record_counts'=>$this->counts,'file_count'=>count($this->files),'file_total_bytes'=>$this->fileBytes,'checksum_algorithm'=>'sha256','entries'=>$this->entries);
        $this->json('manifest.json',$manifest);
        if (file_exists($output)) throw new RuntimeException('Output already exists; refusing overwrite.');
        $zip=new ZipArchive();
        if ($zip->open($output,ZipArchive::CREATE|ZipArchive::EXCL)!==true) throw new RuntimeException('Cannot create output ZIP.');
        foreach (array_merge(array('manifest.json'),$paths) as $path) if (!$zip->addFile($this->directory.'/'.$path,$path)) throw new RuntimeException('Cannot add ZIP entry.');
        if (!$zip->close()) throw new RuntimeException('Cannot finalize ZIP.');
        chmod($output,0600);
        return $summary;
    }
    public function cleanup()
    {
        foreach ($this->streams as $stream) fclose($stream);
        foreach (array('data','files') as $sub) { foreach (glob($this->directory.'/'.$sub.'/*') as $path) unlink($path); rmdir($this->directory.'/'.$sub); }
        foreach (glob($this->directory.'/*.json') as $path) unlink($path);
        rmdir($this->directory);
    }
}
