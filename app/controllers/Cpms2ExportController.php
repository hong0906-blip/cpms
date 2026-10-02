<?php
// app/controllers/Cpms2ExportController.php
require_once __DIR__.'/../session_config.php';
require_once __DIR__.'/../helpers.php';
require_once __DIR__.'/../services/Cpms2WebExportService.php';
class Cpms2ExportController
{
    private $service;
    public function __construct($service) { $this->service=$service; }
    public static function dispatch()
    {
        // Do not include bootstrap.php or invoke Auth::check(): both can write.
        cpms_shared_session_start();
        require_once __DIR__.'/../core/Db.php';
        $pdo=\App\Core\Db::pdo();
        if (!$pdo) { http_response_code(503); echo 'CPMS2 Export 원본 연결을 확인할 수 없습니다.'; return; }
        $root=dirname(dirname(__DIR__)); $private=getenv('CPMS2_EXPORT_STORAGE_ROOT');
        $document=isset($_SERVER['DOCUMENT_ROOT'])?$_SERVER['DOCUMENT_ROOT']:'';
        if ($document==='' && isset($_SERVER['APPL_PHYSICAL_PATH'])) $document=$_SERVER['APPL_PHYSICAL_PATH'];
        $attendance=cpms_load_attendance_pdo();
        $service=new Cpms2WebExportService(new Cpms2ReadOnlySource($pdo),$attendance?new Cpms2ReadOnlySource($attendance):null,$root,cpms_storage_root().'/materials/statements',$private?$private:cpms_storage_root().'/exports/cpms2',$document,cpms_storage_root());
        (new self($service))->handle();
    }
    public function handle()
    {
        date_default_timezone_set('Asia/Seoul');
        ini_set('display_errors','0');
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        $error=''; $employee=null;
        try { $employee=$this->service->authorize($_SESSION); }
        catch (Exception $e) {
            http_response_code($e->getMessage()==='LOGIN_REQUIRED'?401:403);
            echo '<p>로그인한 관리자만 CPMS2 Export에 접근할 수 있습니다.</p><a href="?r=login">로그인</a>'; return;
        }
        $method=isset($_SERVER['REQUEST_METHOD'])?$_SERVER['REQUEST_METHOD']:'GET';
        if (!in_array($method,array('GET','POST'))) { http_response_code(405); header('Allow: GET, POST'); return; }
        if ($method==='POST') {
            if (!csrf_check(isset($_POST['_csrf'])?$_POST['_csrf']:null)) { http_response_code(403); echo '보안 토큰이 올바르지 않습니다.'; return; }
            $action=isset($_POST['action']) && is_string($_POST['action'])?$_POST['action']:'';
            try {
                if ($action==='preflight') {
                    $_SESSION['_cpms2_export_preflight']=array('owner_employee_id'=>(int)$employee['id'],'checked_at'=>time(),'report'=>$this->service->preflight());
                } elseif ($action==='generate') {
                    $check=isset($_SESSION['_cpms2_export_preflight'])?$_SESSION['_cpms2_export_preflight']:array();
                    if (empty($check['owner_employee_id']) || (int)$check['owner_employee_id']!==(int)$employee['id'] || time()-$check['checked_at']>900) throw new RuntimeException('PREFLIGHT_REQUIRED');
                    if (empty($check['report']['can_export'])) throw new RuntimeException('ACCOUNT_PREFLIGHT_FAILED');
                    // Release the session lock during bounded source reads and ZIP assembly.
                    session_write_close(); @set_time_limit(0);
                    $package=$this->service->generate($employee);
                    cpms_shared_session_start();
                    $_SESSION['_cpms2_export_packages'][$package['id']]=$package;
                    $_SESSION['_cpms2_export_latest']=$package['id'];
                    header('Location: ?r=admin%2Fcpms2_export',true,303); return;
                } elseif ($action==='download') {
                    $id=isset($_POST['package']) && is_string($_POST['package'])?$_POST['package']:'';
                    $package=isset($_SESSION['_cpms2_export_packages'][$id])?$_SESSION['_cpms2_export_packages'][$id]:null;
                    $path=$this->service->downloadPath($id,$package,$employee);
                    $stream=fopen($path,'rb'); if (!$stream) throw new RuntimeException('PACKAGE_INTEGRITY_FAILED');
                    session_write_close();
                    header('Content-Type: application/zip');
                    header('Content-Disposition: attachment; filename="cpms1-export-'.substr($id,0,12).'.zip"');
                    header('Content-Length: '.filesize($path));
                    while (!feof($stream) && !connection_aborted()) { echo fread($stream,1048576); flush(); }
                    fclose($stream); return;
                } else { http_response_code(400); $error='지원하지 않는 요청입니다.'; }
            } catch (Exception $e) {
                if (!cpms_shared_session_is_active()) cpms_shared_session_start();
                $messages=array(
                    'PRIVATE_STORAGE_REQUIRED'=>'Export 저장 경로가 쓰기 가능한 폴더인지 확인하세요.',
                    'ZIP_EXTENSION_REQUIRED'=>'서버 PHP ZIP 확장 기능이 필요합니다.',
                    'ATTENDANCE_SOURCE_REQUIRED'=>'노무 원본 연결을 확인할 수 없습니다.',
                    'PREFLIGHT_REQUIRED'=>'먼저 사전검사를 실행하세요. 검사 결과는 15분 동안 유효합니다.',
                    'EXPORT_ALREADY_RUNNING'=>'이 계정의 Export가 이미 진행 중입니다.',
                    'PACKAGE_ACCESS_DENIED'=>'이 계정에서 생성한 ZIP만 다운로드할 수 있습니다.',
                    'PACKAGE_INTEGRITY_FAILED'=>'ZIP 무결성 검사를 통과하지 못했습니다. 다시 생성하세요.'
                );
                $error=isset($messages[$e->getMessage()])?$messages[$e->getMessage()]:'Export를 완료하지 못했습니다. 원본 Schema와 서버 저장 권한을 확인하세요.';
                $request=bin2hex(openssl_random_pseudo_bytes(6));
                $diagnostic=Cpms2ExportFailure::safe($this->service->phase(),$e);
                error_log('[CPMS2 export] request='.$request.' action='.$action.' phase='.$diagnostic->phase.' code='.$diagnostic->getMessage());
                $error.=' 실패 단계: '.$diagnostic->phase.' / 오류코드: '.$diagnostic->getMessage().' (요청 ID: '.$request.')';
                $latest=$this->service->lastPreflight();
                if ($action==='generate' && $latest && !$latest['can_export']) $_SESSION['_cpms2_export_preflight']=array('owner_employee_id'=>(int)$employee['id'],'checked_at'=>time(),'report'=>$latest);
                if ($action==='preflight') unset($_SESSION['_cpms2_export_preflight']);
                http_response_code(422);
            }
        }
        $check=isset($_SESSION['_cpms2_export_preflight'])?$_SESSION['_cpms2_export_preflight']:null;
        if ($check && ((int)$check['owner_employee_id']!==(int)$employee['id'] || time()-$check['checked_at']>900)) $check=null;
        $id=isset($_SESSION['_cpms2_export_latest'])?$_SESSION['_cpms2_export_latest']:'';
        $package=isset($_SESSION['_cpms2_export_packages'][$id])?$_SESSION['_cpms2_export_packages'][$id]:null;
        if ($package && (int)$package['owner_employee_id']!==(int)$employee['id']) $package=null;
        $exportView=array('preflight'=>$check?$check['report']:null,'package'=>$package,'error'=>$error,'csrf'=>csrf_token());
        require __DIR__.'/../views/admin/cpms2_export.php';
    }
}
