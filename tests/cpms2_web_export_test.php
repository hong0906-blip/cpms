<?php
// tests/cpms2_web_export_test.php
// No application bootstrap, production connection or source files.
require_once dirname(__DIR__).'/app/controllers/Cpms2ExportController.php';
class Cpms2WebFixtureStatement
{
    private $sql; private $rows=array(); private $index=0;
    public function __construct($sql) { $this->sql=$sql; }
    public function execute($params)
    {
        if (strpos($this->sql,'information_schema.TABLES')!==false) $this->rows=array(array(1));
        elseif (strpos($this->sql,'SHOW COLUMNS')===0) foreach (array('id','email','name','department','role','is_active') as $column) $this->rows[]=array('Field'=>$column);
        else {
            $people=array(
                17=>array('id'=>17,'name'=>'Fixture manager','email'=>'manager@example.invalid','department'=>'관리팀','role'=>'employee','is_active'=>1),
                18=>array('id'=>18,'name'=>'Fixture reader','email'=>'reader@example.invalid','department'=>'공사','role'=>'employee','is_active'=>1),
                19=>array('id'=>19,'name'=>'Fixture retired','email'=>'retired@example.invalid','department'=>'개발','role'=>'executive','is_active'=>0),
                20=>array('id'=>20,'name'=>'Fixture master','email'=>'master@example.invalid','department'=>'개발부','role'=>'employee','is_active'=>1)
            );
            if (isset($people[$params[0]]) && strtolower($people[$params[0]]['email'])===strtolower($params[1])) $this->rows=array($people[$params[0]]);
        }
    }
    public function fetch($mode=null) { return isset($this->rows[$this->index])?$this->rows[$this->index++]:false; }
    public function fetchColumn() { $row=$this->fetch(); return $row?reset($row):false; }
}
class Cpms2WebFixturePdo
{
    public $queries=array();
    public function prepare($sql) { $this->queries[]=$sql; return new Cpms2WebFixtureStatement($sql); }
}
$checks=0;
function cpms_web_assert($ok,$message) { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
function cpms_web_reject($call,$message) { try { $call(); } catch (Exception $e) { cpms_web_assert(true,$message); return; } throw new RuntimeException($message); }
$root=sys_get_temp_dir().'/cpms-web-fixture-'.uniqid(); mkdir($root,0700); mkdir($root.'/public',0700); mkdir($root.'/private',0700);
$pdo=new Cpms2WebFixturePdo(); $source=new Cpms2ReadOnlySource($pdo);
$storage=$root.'/public/storage/exports/cpms2';
$web=new Cpms2WebExportService($source,null,$root,$root,$storage,$root.'/public');
$manager=array('cpms_user'=>array('id'=>17,'email'=>'manager@example.invalid'),'_csrf'=>'fixture-csrf');
try {
    cpms_web_reject(function() use($web){ $web->authorize(array()); },'Unauthenticated export accepted.');
    cpms_web_reject(function() use($web){ $web->authorize(array('cpms_user'=>array('id'=>18,'email'=>'reader@example.invalid','role'=>'executive','department'=>'개발'))); },'Stale elevated session accepted.');
    cpms_web_reject(function() use($web){ $web->authorize(array('cpms_user'=>array('id'=>19,'email'=>'retired@example.invalid'))); },'Inactive employee accepted.');
    cpms_web_reject(function() use($web){ $web->authorize(array('cpms_user'=>array('id'=>17,'email'=>'wrong@example.invalid'))); },'Session employee/email mismatch accepted.');
    $employee=$web->authorize($manager); cpms_web_assert($employee['id']===17,'Current management permission denied.');
    cpms_web_assert($web->authorize(array('cpms_user'=>array('id'=>20,'email'=>'master@example.invalid')))['id']===20,'Current master permission denied.');
    cpms_web_assert($web->checkedPrivateRoot()===realpath($root.'/public').'/storage/exports/cpms2','Storage inside document root rejected.');
    cpms_web_assert(!is_dir($storage),'Storage validation created a directory.');
    cpms_web_assert($web->checkedPrivateRoot(true)===realpath($storage),'Nested export storage not created.');
    $unknown=new Cpms2WebExportService($source,null,$root,$root,$storage,'');
    cpms_web_assert($unknown->checkedPrivateRoot()===realpath($storage),'Storage requires a document root.');
    $empty=new Cpms2WebExportService($source,null,$root,$root,'',$root.'/public');
    cpms_web_reject(function() use($empty){ $empty->checkedPrivateRoot(true); },'Empty storage path accepted.');
    $id=str_repeat('a',48); $file=$storage.'/'.$id.'.zip'; file_put_contents($file,'fixture-package');
    $notDirectory=new Cpms2WebExportService($source,null,$root,$root,$file,$root.'/public');
    cpms_web_reject(function() use($notDirectory){ $notDirectory->checkedPrivateRoot(true); },'File accepted as storage directory.');
    $package=array('id'=>$id,'owner_employee_id'=>17,'size'=>filesize($file),'sha256'=>hash_file('sha256',$file),'summary'=>array('exported_file_count'=>1,'missing_file_count'=>0,'deduplicated_file_count'=>1,'record_counts'=>array('employees'=>1),'warnings'=>array('<script>fixture</script>')));
    cpms_web_assert($web->downloadPath($id,$package,$employee)===realpath($file),'Owned ZIP download inside document root denied.');
    cpms_web_reject(function() use($web,$id,$employee){ $web->downloadPath($id,null,$employee); },'Unregistered ZIP download accepted.');
    cpms_web_reject(function() use($web,$id,$package){ $web->downloadPath($id,$package,array('id'=>20)); },'Another administrator downloaded an unowned ZIP.');
    cpms_web_reject(function() use($web,$package,$employee){ $web->downloadPath('../package',$package,$employee); },'Download path traversal accepted.');
    $_SESSION=$manager; $_SERVER['REQUEST_METHOD']='GET'; $_GET['r']='admin/cpms2_export'; $_SERVER['SCRIPT_NAME']='/public/index.php';
    $_SESSION['_cpms2_export_latest']=$id; $_SESSION['_cpms2_export_packages']=array($id=>$package);
    ob_start(); (new Cpms2ExportController($web))->handle(); $html=ob_get_clean();
    cpms_web_assert(strpos($html,'ZIP 다운로드')!==false && strpos($html,'disabled')!==false,'Web ready state or preflight gate missing.');
    cpms_web_assert(strpos($html,'<script>fixture</script>')===false && strpos($html,'&lt;script&gt;fixture&lt;/script&gt;')!==false,'Report HTML not escaped.');
    $_SERVER['REQUEST_METHOD']='POST'; $_POST=array('action'=>'download','package'=>$id,'_csrf'=>'bad');
    http_response_code(200); ob_start(); (new Cpms2ExportController($web))->handle(); $response=ob_get_clean();
    cpms_web_assert(http_response_code()===403 && strpos($response,'fixture-package')===false,'Invalid CSRF downloaded data.');
    $_POST['_csrf']='fixture-csrf'; http_response_code(200); ob_start(); (new Cpms2ExportController($web))->handle(); $response=ob_get_clean();
    cpms_web_assert($response==='fixture-package','Authenticated CSRF-protected streamed download failed.');
    file_put_contents($file,'changed-package'); clearstatcache(true,$file);
    cpms_web_assert(filesize($file)===$package['size'],'Tamper fixture must preserve ZIP size.');
    cpms_web_reject(function() use($web,$id,$package,$employee){ $web->downloadPath($id,$package,$employee); },'Changed ZIP downloaded.');
    foreach ($pdo->queries as $sql) { Cpms2ReadOnlySource::assertReadOnly($sql); cpms_web_assert(!preg_match('/INSERT|UPDATE|DELETE|CREATE|ALTER|DROP/i',$sql),'Web source write detected.'); }
    $entry=file_get_contents(dirname(__DIR__).'/public/index.php');
    cpms_web_assert(strpos($entry,'Cpms2ExportController::dispatch()')<strpos($entry,"require_once __DIR__ . '/../app/bootstrap.php'"),'Export entered writing bootstrap.');
    $view=file_get_contents(dirname(__DIR__).'/app/views/admin/cpms2_export.php'); $guide=file_get_contents(dirname(__DIR__).'/public/assets/js/guide-tour.js');
    foreach (array('preflight','generate','download') as $key) {
        cpms_web_assert(substr_count($view,'data-guide="admin-cpms2-'.$key.'"')===1 && substr_count($guide,'data-guide="admin-cpms2-'.$key.'"')===1,'Guide target missing or duplicated.');
    }
    cpms_web_assert(substr_count($guide,'data-guide="admin-cpms2-open"')===1,'Admin Export link Guide missing.');
} finally {
    if (isset($file) && is_file($file)) unlink($file);
    foreach (array($storage,$root.'/public/storage/exports',$root.'/public/storage',$root.'/private',$root.'/public',$root) as $directory) if (is_dir($directory)) rmdir($directory);
}
echo 'PASS: '.$checks." CPMS2 Web Export auth/CSRF/private storage/streaming/Guide checks\n";
