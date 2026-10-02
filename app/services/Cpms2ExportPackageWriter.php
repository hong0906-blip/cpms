<?php
// app/services/Cpms2ExportPackageWriter.php
class Cpms2ExportPackageWriter
{
    public $counts = array();
    public $warnings = array();
    public $missing = array();
    public $expectedFiles = 0;
    public $fileBytes = 0;
    public $phase = 'employees';
    public $accountCounts = array('vendors'=>0,'workers'=>0,'direct_team'=>0,'employees'=>0);
    public $excludedLaborForce = array('count'=>0,'amount'=>'0.00','projects'=>array());
    private $directory;
    private $streams = array();
    private $files = array();
    private $entries = array();
    private $totals = array();
    public function __construct($directory) { $this->directory=$directory; if (!mkdir($directory,0700,true)) throw new RuntimeException('Cannot create private package staging directory.'); mkdir($directory.'/data',0700); mkdir($directory.'/files',0700); }
    public function record($entity, $row)
    {
        if (isset($row['account_number']) && trim((string)$row['account_number'])!=='' && isset($this->accountCounts[$entity])) $this->accountCounts[$entity]++;
        if (!isset($this->streams[$entity])) { $this->streams[$entity]=fopen($this->directory.'/data/'.$entity.'.jsonl','wb'); $this->counts[$entity]=0; }
        $json=json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if ($json===false || fwrite($this->streams[$entity],$json."\n")!==strlen($json)+1) throw new RuntimeException('Cannot write package record.');
        $this->counts[$entity]++;
    }
    public function emptyEntity($entity) { if (!isset($this->counts[$entity])) { $this->streams[$entity]=fopen($this->directory.'/data/'.$entity.'.jsonl','wb'); $this->counts[$entity]=0; } }
    public static function moneyCents($value)
    {
        $value=(string)$value;
        if (!preg_match('/^-?\d+(?:\.\d{1,2})?$/D',$value)) throw new RuntimeException('Invalid summary amount.');
        // PHP 5.6 on Windows can have 32-bit integers. Whole cents stay exact
        // in a double up to 2^53; avoid integer overflow even for 23,500,000 won.
        $parts=explode('.',ltrim($value,'-')); $cents=(float)$parts[0]*100+(float)str_pad(isset($parts[1])?$parts[1]:'',2,'0');
        if ($cents>9007199254740991) throw new RuntimeException('Summary amount exceeds exact precision.');
        return substr($value,0,1)==='-'?-$cents:$cents;
    }
    public static function moneyDecimal($cents)
    {
        if (abs($cents)>9007199254740991) throw new RuntimeException('Summary amount exceeds exact precision.');
        $digits=str_pad(sprintf('%.0f',abs($cents)),3,'0',STR_PAD_LEFT);
        return ($cents<0?'-':'').substr($digits,0,-2).'.'.substr($digits,-2);
    }
    public function amount($project, $kind, $amount)
    {
        $cents=round((float)$amount*100);
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
        $this->phase='summary';
        foreach ($this->streams as $stream) fclose($stream);
        $this->streams=array();
        $total=array(); $projects=array();
        foreach ($this->totals as $project=>$amounts) foreach ($amounts as $kind=>$cents) { $projects[$project][$kind]=self::moneyDecimal($cents); if (!isset($total[$kind])) $total[$kind]=0; $total[$kind]+=$cents; }
        foreach ($total as $kind=>$cents) $total[$kind]=self::moneyDecimal($cents);
        $labor=array('projects'=>array(),'company'=>array());
        foreach (array_unique(array_merge(array_keys($projects),array_keys($this->excludedLaborForce['projects']))) as $project) {
            if (!isset($projects[$project]['labor'])) $projects[$project]['labor']='0.00';
            $eligible=self::moneyCents($projects[$project]['labor']); $excluded=isset($this->excludedLaborForce['projects'][$project])?self::moneyCents($this->excludedLaborForce['projects'][$project]['amount']):0;
            $labor['projects'][$project]=array('source_original_labor'=>self::moneyDecimal($eligible+$excluded),'excluded_force_amount'=>self::moneyDecimal($excluded),'source_migration_labor'=>self::moneyDecimal($eligible));
        }
        $eligible=isset($total['labor'])?self::moneyCents($total['labor']):0; $excluded=self::moneyCents($this->excludedLaborForce['amount']);
        $labor['company']=array('source_original_labor'=>self::moneyDecimal($eligible+$excluded),'excluded_force_amount'=>self::moneyDecimal($excluded),'source_migration_labor'=>self::moneyDecimal($eligible));
        $summary=array('record_counts'=>$this->counts,'amounts'=>array('projects'=>$projects,'company'=>$total),'expected_file_count'=>$this->expectedFiles,'exported_file_count'=>count($this->files),'missing_file_count'=>count($this->missing),'missing_file_rows'=>$this->missing,'deduplicated_file_count'=>$this->expectedFiles-count($this->missing)-count($this->files),'warnings'=>$this->warnings);
        $summary['excluded_labor_force_adjustments']=$this->excludedLaborForce;
        $summary['labor_reconciliation']=$labor;
        $summary['account_counts']=$this->accountCounts;
        $manifest['contains_plaintext_accounts']=true;
        $this->json('schema-report.json',$schema); $this->json('summary.json',$summary);
        $paths=array('schema-report.json','summary.json');
        foreach ($this->counts as $entity=>$count) $paths[]='data/'.$entity.'.jsonl';
        foreach ($this->files as $file) $paths[]=$file['package_path'];
        foreach ($paths as $path) $this->entries[$path]=array('sha256'=>hash_file('sha256',$this->directory.'/'.$path),'size'=>filesize($this->directory.'/'.$path));
        $manifest+=array('record_counts'=>$this->counts,'file_count'=>count($this->files),'file_total_bytes'=>$this->fileBytes,'checksum_algorithm'=>'sha256','entries'=>$this->entries);
        $this->json('manifest.json',$manifest);
        $this->phase='zip';
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
