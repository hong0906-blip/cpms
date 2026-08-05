<?php
/**
 * 파일 경로: C:\www\cpms\public\public_mail_action.php
 * 네이버 메일 설정, 전체수집, 동기화, 처리상태 변경 요청을 처리합니다.
 * PHP 5.6 호환 코드입니다.
 */
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/services/PublicMailService.php';
require_once __DIR__ . '/../app/services/PublicMailWebHelper.php';

use App\Services\PublicMailService;
use App\Services\PublicMailWebHelper;

PublicMailWebHelper::requireLogin();
if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'POST 요청만 허용됩니다.'; exit; }
$csrf=isset($_POST['csrf_token'])?(string)$_POST['csrf_token']:'';
$csrfValid=PublicMailWebHelper::verifyCsrf($csrf); if (!$csrfValid && function_exists('csrf_check')) $csrfValid=csrf_check($csrf);
if (!$csrfValid) {
    if (PublicMailWebHelper::isAjax()) PublicMailWebHelper::jsonResponse(array('ok'=>false,'message'=>'보안 확인값이 올바르지 않습니다. 페이지를 새로고침하세요.'),419);
    PublicMailWebHelper::redirectWithMessage('public_mail.php','error','보안 확인값이 올바르지 않습니다.');
}
$action=isset($_POST['action'])?trim((string)$_POST['action']):''; $service=new PublicMailService();
$currentUser=PublicMailWebHelper::currentUserName(); $isAjax=PublicMailWebHelper::isAjax();

try {
    if ($action==='get_sync_status') {
        $state=$service->getSyncState();
        $result=array('ok'=>true,'message'=>'동기화 상태를 확인했습니다.','state'=>$state);
        if ($isAjax) PublicMailWebHelper::jsonResponse($result,200);
        PublicMailWebHelper::redirectWithMessage('public_mail_settings.php','success',$result['message']);
    }

    if ($action==='sync_new' || $action==='sync_initial' || $action==='automation_tick') {
        $limit=isset($_POST['limit'])?(int)$_POST['limit']:0;
        if ($action==='sync_initial') $result=$service->syncBatch($limit,'initial');
        elseif ($action==='automation_tick') $result=$service->runAutomationTick($limit);
        else {
            $isBackground=!empty($_POST['background']);
            if ($isBackground) {
                $state=$service->getSyncState(); $last=isset($state['last_success_at'])?strtotime((string)$state['last_success_at']):false;
                if ($last!==false && $last>0 && time()-$last<45) $result=array('ok'=>true,'skipped'=>true,'message'=>'최근 확인 후 45초가 지나지 않아 중복 연결을 생략했습니다.','added_count'=>0,'state'=>$state);
                else $result=$service->runAutomationTick($limit);
            } else $result=$service->syncNewBatch($limit);
        }
        try { $result['repaired_count']=$service->repairBrokenMetadataBatch(!empty($_POST['background'])?2:5); } catch (Exception $ignored) { $result['repaired_count']=0; }
        if ($isAjax) PublicMailWebHelper::jsonResponse($result,200);
        PublicMailWebHelper::redirectWithMessage('public_mail.php','success',$result['message']);
    }

    if ($action==='start_full_import') {
        PublicMailWebHelper::requireAdmin(); $result=$service->startFullImport();
        if ($isAjax) PublicMailWebHelper::jsonResponse($result,200);
        PublicMailWebHelper::redirectWithMessage('public_mail_settings.php','success',$result['message']);
    }
    if ($action==='pause_full_import' || $action==='resume_full_import' || $action==='cancel_full_import') {
        PublicMailWebHelper::requireAdmin();
        $command=$action==='pause_full_import'?'pause':($action==='resume_full_import'?'resume':'cancel'); $result=$service->controlFullImport($command);
        if ($isAjax) PublicMailWebHelper::jsonResponse($result,200);
        PublicMailWebHelper::redirectWithMessage('public_mail_settings.php','success',$result['message']);
    }

    if ($action==='update_workflow' || $action==='reply_completed' || $action==='reclassify') {
        $messageKey=isset($_POST['message_key'])?trim((string)$_POST['message_key']):'';
        if ($messageKey==='' && isset($_POST['uid']) && (int)$_POST['uid']>0) $messageKey=(string)(int)$_POST['uid'];
        if ($action==='update_workflow') {
            $changes=array(
                'department'=>isset($_POST['department'])?trim((string)$_POST['department']):'',
                'project_id'=>isset($_POST['project_id'])?trim((string)$_POST['project_id']):'',
                'project_name'=>isset($_POST['project_name'])?trim((string)$_POST['project_name']):'',
                'assignee_id'=>isset($_POST['assignee_id'])?trim((string)$_POST['assignee_id']):'',
                'assignee_name'=>isset($_POST['assignee_name'])?trim((string)$_POST['assignee_name']):'',
                'status'=>isset($_POST['status'])?trim((string)$_POST['status']):'미확인',
                'priority'=>isset($_POST['priority'])?trim((string)$_POST['priority']):'보통',
                'important'=>!empty($_POST['important']),'memo'=>isset($_POST['memo'])?trim((string)$_POST['memo']):''
            );
            $workflow=$service->updateWorkflow($messageKey,$changes,$currentUser); $result=array('ok'=>true,'message'=>'메일 처리정보를 저장했습니다.','workflow'=>$workflow);
        } elseif ($action==='reply_completed') {
            $workflow=$service->updateWorkflow($messageKey,array('reply_completed'=>true,'reply_completed_at'=>date('Y-m-d H:i:s'),'reply_completed_by'=>$currentUser,'status'=>'발송완료'),$currentUser);
            $result=array('ok'=>true,'message'=>'Gmail 발송완료로 처리했습니다.','workflow'=>$workflow);
        } else {
            $classification=$service->reclassify($messageKey); $result=array('ok'=>true,'message'=>'메일을 다시 자동분류했습니다.','classification'=>$classification);
        }
        if ($isAjax) PublicMailWebHelper::jsonResponse($result,200);
        PublicMailWebHelper::redirectWithMessage('public_mail.php?message='.rawurlencode($messageKey),'success',$result['message']);
    }

    if ($action==='save_attachment_drive') {
        $messageKey=isset($_POST['message_key'])?trim((string)$_POST['message_key']):'';
        $partId=isset($_POST['part_id'])?trim((string)$_POST['part_id']):'';
        $projectId=isset($_POST['project_id'])?(int)$_POST['project_id']:0;
        if ($messageKey===''||$partId==='') throw new RuntimeException('Google Drive에 저장할 첨부파일을 확인할 수 없습니다.');
        if (function_exists('session_write_close')) @session_write_close();
        @set_time_limit(0);
        @ignore_user_abort(true);
        $record=$service->saveAttachmentToDrive($messageKey,$partId,$projectId,$currentUser);
        $result=array('ok'=>true,'message'=>'첨부파일을 Google Drive에 저장했습니다.','record'=>$record);
        if ($isAjax) PublicMailWebHelper::jsonResponse($result,200);
        PublicMailWebHelper::redirectWithMessage('public_mail.php?message='.rawurlencode($messageKey),'success',$result['message']);
    }

    if ($action==='repair_metadata') {
        PublicMailWebHelper::requireAdmin(); $repaired=$service->repairBrokenMetadataBatch(isset($_POST['limit'])?(int)$_POST['limit']:20);
        $result=array('ok'=>true,'repaired_count'=>$repaired,'message'=>$repaired>0?$repaired.'건의 제목과 본문 미리보기를 복구했습니다.':'추가로 복구할 메일이 없습니다.');
        if ($isAjax) PublicMailWebHelper::jsonResponse($result,200);
        PublicMailWebHelper::redirectWithMessage('public_mail_settings.php','success',$result['message']);
    }

    if ($action==='save_settings') {
        PublicMailWebHelper::requireAdmin();
        $service->saveSettings(array(
            'enabled'=>!empty($_POST['enabled']),'username'=>isset($_POST['username'])?trim((string)$_POST['username']):'',
            'password'=>isset($_POST['password'])?trim((string)$_POST['password']):'',
            'batch_size'=>isset($_POST['batch_size'])?(int)$_POST['batch_size']:100,
            'use_gpt_classifier'=>!empty($_POST['use_gpt_classifier']),
            'include_spam'=>!empty($_POST['include_spam']),'include_trash'=>!empty($_POST['include_trash'])
        ),$currentUser);
        PublicMailWebHelper::redirectWithMessage('public_mail_settings.php','success','네이버 메일 연동 설정을 저장했습니다.');
    }

    if ($action==='test_connection') {
        PublicMailWebHelper::requireAdmin(); $result=$service->testConnection(array('username'=>isset($_POST['username'])?trim((string)$_POST['username']):'','password'=>isset($_POST['password'])?trim((string)$_POST['password']):''));
        if ($isAjax) PublicMailWebHelper::jsonResponse($result,200);
        PublicMailWebHelper::redirectWithMessage('public_mail_settings.php','success',$result['message']);
    }

    if ($action==='regenerate_cron_token') {
        PublicMailWebHelper::requireAdmin(); $service->regenerateCronToken($currentUser);
        $result=array('ok'=>true,'message'=>'자동동기화 요청 헤더의 비밀키를 새로 만들었습니다. 기존 비밀키는 더 이상 작동하지 않습니다.');
        if ($isAjax) PublicMailWebHelper::jsonResponse($result,200);
        PublicMailWebHelper::redirectWithMessage('public_mail_settings.php','success',$result['message']);
    }

    if ($action==='reset_mail_data') {
        PublicMailWebHelper::requireAdmin(); $confirmation=isset($_POST['confirmation'])?trim((string)$_POST['confirmation']):'';
        if ($confirmation!=='초기화') throw new RuntimeException('초기화를 실행하려면 확인칸에 초기화라고 입력하세요.');
        $service->resetMailData(); PublicMailWebHelper::redirectWithMessage('public_mail_settings.php','success','CPMS에 저장된 메일 목록과 처리정보를 초기화했습니다. 네이버 원본메일은 변경되지 않았습니다.');
    }
    throw new RuntimeException('지원하지 않는 요청입니다.');
} catch (Exception $e) {
    if ($isAjax) PublicMailWebHelper::jsonResponse(array('ok'=>false,'message'=>$e->getMessage()),400);
    $settingsActions=array('save_settings','test_connection','reset_mail_data','start_full_import','pause_full_import','resume_full_import','cancel_full_import','regenerate_cron_token','repair_metadata','get_sync_status');
    $redirect=in_array($action,$settingsActions,true)?'public_mail_settings.php':'public_mail.php';
    if (!empty($_POST['message_key']) && $redirect==='public_mail.php') $redirect.='?message='.rawurlencode((string)$_POST['message_key']);
    PublicMailWebHelper::redirectWithMessage($redirect,'error',$e->getMessage());
}
