<?php
/**
 * 파일: C:\www\cpms\app\views\construction\outsourcing_file_download.php
 * 외주비 Google Drive 파일 업로드/삭제/보기/다운로드
 * PHP 5.6 호환
 */
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/tabs/partials/outsourcing_file_helper.php';

use App\Core\Auth;
use App\Core\Db;

function cpms_outsourcing_file_json($payload, $status)
{
    http_response_code((int)$status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

if (!Auth::check()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') cpms_outsourcing_file_json(array('ok'=>false,'message'=>'로그인이 필요합니다.'),401);
    header('Location: ?r=login'); exit;
}
$pdo=Db::pdo();
if (!$pdo || !cpms_outsourcing_file_ensure_schema($pdo)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') cpms_outsourcing_file_json(array('ok'=>false,'message'=>'첨부파일 정보를 준비하지 못했습니다.'),500);
    http_response_code(500); echo '첨부파일 정보를 준비하지 못했습니다.'; exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::canManageConstruction()) cpms_outsourcing_file_json(array('ok'=>false,'message'=>'파일 변경 권한이 없습니다.'),403);
    $token=isset($_POST['_csrf'])?(string)$_POST['_csrf']:'';
    if (!csrf_check($token)) cpms_outsourcing_file_json(array('ok'=>false,'message'=>'보안 토큰이 유효하지 않습니다.'),403);
    $action=isset($_GET['action'])?trim((string)$_GET['action']):'';

    if ($action==='upload') {
        $projectId=isset($_POST['project_id'])?(int)$_POST['project_id']:0;
        $costId=isset($_POST['cost_id'])?(int)$_POST['cost_id']:0;
        $ym=isset($_POST['ym'])?trim((string)$_POST['ym']):date('Y-m');
        if ($projectId<=0 || $costId<=0) cpms_outsourcing_file_json(array('ok'=>false,'message'=>'외주비 정보를 확인할 수 없습니다.'),400);
        try {
            $st=$pdo->prepare("SELECT id FROM cpms_outsourcing_costs WHERE id=:id AND project_id=:pid AND is_deleted=0 LIMIT 1");
            $st->execute(array(':id'=>$costId,':pid'=>$projectId));
            if (!$st->fetch(PDO::FETCH_ASSOC)) cpms_outsourcing_file_json(array('ok'=>false,'message'=>'외주비 내역을 찾을 수 없습니다.'),404);
        } catch (Exception $e) { cpms_outsourcing_file_json(array('ok'=>false,'message'=>'외주비 조회에 실패했습니다.'),500); }
        if (!isset($_FILES['file'])) cpms_outsourcing_file_json(array('ok'=>false,'message'=>'업로드할 파일이 없습니다.'),400);
        $result=cpms_outsourcing_file_store_one_drive($pdo,$_FILES['file'],$projectId,$costId,$ym);
        cpms_outsourcing_file_json($result,!empty($result['ok'])?200:400);
    }

    if ($action==='delete') {
        $fileId=isset($_POST['file_id'])?(int)$_POST['file_id']:0;
        if ($fileId<=0) cpms_outsourcing_file_json(array('ok'=>false,'message'=>'삭제할 파일이 없습니다.'),400);
        try {
            $st=$pdo->prepare("SELECT f.* FROM cpms_outsourcing_cost_files f JOIN cpms_outsourcing_costs c ON c.id=f.outsourcing_cost_id AND c.project_id=f.project_id WHERE f.id=:id AND f.is_deleted=0 AND c.is_deleted=0 LIMIT 1");
            $st->bindValue(':id',$fileId,PDO::PARAM_INT); $st->execute(); $row=$st->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $row=false; }
        if (!is_array($row)) cpms_outsourcing_file_json(array('ok'=>false,'message'=>'파일을 찾을 수 없습니다.'),404);
        $result=cpms_outsourcing_file_delete_record($pdo,$row);
        cpms_outsourcing_file_json($result,!empty($result['ok'])?200:400);
    }
    cpms_outsourcing_file_json(array('ok'=>false,'message'=>'지원하지 않는 요청입니다.'),400);
}

$fileId=isset($_GET['id'])?(int)$_GET['id']:0;
if ($fileId<=0) { http_response_code(404); echo '파일을 찾을 수 없습니다.'; exit; }
try {
    $st=$pdo->prepare("SELECT f.* FROM cpms_outsourcing_cost_files f JOIN cpms_outsourcing_costs c ON c.id=f.outsourcing_cost_id AND c.project_id=f.project_id WHERE f.id=:id AND f.is_deleted=0 AND c.is_deleted=0 LIMIT 1");
    $st->bindValue(':id',$fileId,PDO::PARAM_INT); $st->execute(); $row=$st->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) { $row=false; }
if (!is_array($row)) { http_response_code(404); echo '파일을 찾을 수 없습니다.'; exit; }
$projectId=isset($row['project_id'])?(int)$row['project_id']:0;
$canDownload=Auth::isMaster() || Auth::canAccessConstruction();
if (!$canDownload && function_exists('cpms_is_project_member_or_executive')) $canDownload=cpms_is_project_member_or_executive($pdo,$projectId,Auth::userRole(),Auth::userEmail());
if (!$canDownload) { http_response_code(403); echo '파일 확인 권한이 없습니다.'; exit; }

$storageType=isset($row['storage_type'])?trim((string)$row['storage_type']):'';
$wantDownload=isset($_GET['download']) && (string)$_GET['download']==='1';
if ($storageType==='google_drive') {
    $url=$wantDownload ? (isset($row['drive_web_content_link'])?trim((string)$row['drive_web_content_link']):'') : (isset($row['drive_web_view_link'])?trim((string)$row['drive_web_view_link']):'');
    if ($url==='' && !empty($row['drive_file_id'])) {
        $id=rawurlencode((string)$row['drive_file_id']);
        $url=$wantDownload ? ('https://drive.google.com/uc?export=download&id='.$id) : ('https://drive.google.com/file/d/'.$id.'/view');
    }
    if ($url!=='') { header('Location: '.$url, true, 302); exit; }
    http_response_code(404); echo 'Google Drive 링크를 찾을 수 없습니다.'; exit;
}

$path=cpms_outsourcing_file_resolve_path(isset($row['stored_path'])?$row['stored_path']:'');
if ($path==='' || !is_file($path)) { http_response_code(404); echo '파일을 찾을 수 없습니다.'; exit; }
$originalName=basename(str_replace('\\','/',isset($row['original_name'])?(string)$row['original_name']:''));
$originalName=str_replace(array("\r","\n",'"'),'',$originalName);
if ($originalName==='') $originalName='outsourcing_file_'.$fileId;
$mime=isset($row['mime_type']) && trim((string)$row['mime_type'])!==''?trim((string)$row['mime_type']):'application/octet-stream';
while (ob_get_level()>0) @ob_end_clean();
header('Content-Type: '.$mime);
header('Content-Length: '.filesize($path));
header('X-Content-Type-Options: nosniff');
$encodedName=rawurlencode($originalName);
$disposition=$wantDownload?'attachment':'inline';
header("Content-Disposition: ".$disposition."; filename=\"".$encodedName."\"; filename*=UTF-8''".$encodedName);
@readfile($path); exit;
