<?php
/**
 * 파일 경로: C:\www\cpms\public\public_mail_settings.php
 * 네이버 메일 관리자 설정 화면 진입 파일입니다. PHP 5.6 호환 코드입니다.
 * CPMS_PUBLIC_MAIL_VERSION: 1.7.3
 */
require_once __DIR__ . '/../app/bootstrap.php';
if (isset($_GET['r']) && trim((string)$_GET['r'])!=='') { header('Location: index.php?'.http_build_query($_GET,'','&')); exit; }
require_once __DIR__ . '/../app/services/PublicMailService.php';
require_once __DIR__ . '/../app/services/PublicMailWebHelper.php';
use App\Services\PublicMailService;
use App\Services\PublicMailWebHelper;
PublicMailWebHelper::requireAdmin();
$service=new PublicMailService(); $errorMessage='';
try {
    $settings=$service->getSettings(false); $syncState=$service->getSyncState(); $cacheStats=$service->getBodyCacheStats(); $indexStatus=$service->getIndexStatus();
    $scheme=function_exists('cpms_is_https_request')&&cpms_is_https_request()?'https':'http';
    $host=isset($_SERVER['HTTP_HOST'])?trim((string)$_SERVER['HTTP_HOST']):'cmbuild.kr';
    $base=$scheme.'://'.$host.rtrim((string)base_url(),'/');
    $cronInfo=$service->getCronInfo($base);
} catch (Exception $e) {
    $errorMessage=$e->getMessage(); $settings=array('enabled'=>false,'username'=>'','batch_size'=>100,'imap_host'=>'imap.naver.com','imap_port'=>993); $syncState=array(); $cacheStats=array('storage_writable'=>false,'total_messages'=>0,'cached_messages'=>0,'missing_messages'=>0,'legacy_messages'=>0,'cache_version'=>8); $indexStatus=array('version'=>0,'package_version'=>'','updated_at'=>'','item_count'=>0,'file_exists'=>false,'file_size'=>0,'writable'=>false); $cronInfo=array('url'=>'','header_name'=>'X-CPMS-Mail-Key','header_value'=>'');
}
PublicMailWebHelper::render('public_mail/settings',array(
    'selectedMenu'=>'네이버 메일','pageTitle'=>'네이버 메일 설정','settings'=>$settings,'syncState'=>$syncState,
    'cronInfo'=>$cronInfo,'cacheStats'=>$cacheStats,'indexStatus'=>$indexStatus,'packageVersion'=>'1.7.3','csrfToken'=>PublicMailWebHelper::csrfToken(),'flash'=>PublicMailWebHelper::pullFlash(),'errorMessage'=>$errorMessage
));
