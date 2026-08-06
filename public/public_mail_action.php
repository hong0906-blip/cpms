<?php
/**
 * 파일 경로: C:\www\cpms\public\public_mail_action.php
 *
 * 네이버 메일의 상세본문 비동기 조회, 인라인 이미지 출력, 설정, 동기화,
 * 처리상태 변경을 담당합니다. PHP 5.6 호환 코드입니다.
 * CPMS_PUBLIC_MAIL_VERSION: 1.7.8
 */
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/services/PublicMailService.php';
require_once __DIR__ . '/../app/services/PublicMailWebHelper.php';

use App\Services\PublicMailService;
use App\Services\PublicMailWebHelper;

PublicMailWebHelper::requireLogin();
$service = new PublicMailService();
$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : 'GET';
$getAction = isset($_GET['action']) ? trim((string)$_GET['action']) : '';

/*
 * 읽기 전용 요청은 GET으로 처리합니다.
 * 메일 본문을 읽는 동안 PHP 세션 잠금을 풀어 다른 CPMS 화면 사용을 방해하지 않습니다.
 */
if ($method === 'GET' && ($getAction === 'detail_panel' || $getAction === 'detail_fragment' || $getAction === 'inline_image' || $getAction === 'inline_image_bundle')) {
    try {
        $messageKey = isset($_GET['message_key']) ? trim((string)$_GET['message_key']) : '';
        $partId = isset($_GET['part_id']) ? trim((string)$_GET['part_id']) : '';
        if ($messageKey === '') throw new RuntimeException('메일 식별값을 확인할 수 없습니다.');

        if ($getAction === 'detail_panel') {
            $currentEmail = PublicMailWebHelper::currentUserEmail();
            $csrfToken = PublicMailWebHelper::csrfToken();
            $taskCsrfToken = function_exists('csrf_token') ? csrf_token() : '';
            $taskRequestToken = md5(uniqid('', true) . session_id());
            if (function_exists('session_write_close')) @session_write_close();

            $detail = $service->getMessageShell($messageKey);
            $employees = $service->getEmployees();
            $projects = $service->getProjects();
            $settings = $service->getSettings(false);
            $replyMailUrl = $service->buildGmailComposeUrl($detail, $currentEmail, 'reply');
            $departmentOptions = array('공무', '공사', '안전/보건', '품질', '관리', '일반', '미분류');
            $statusOptions = array('미확인', '확인', '담당자 지정', '처리중', '회신대기', '발송완료', '처리완료', '보류');
            $priorityOptions = array('긴급', '높음', '보통', '낮음');
            $esc = function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
            header('Content-Type: text/html; charset=UTF-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            include __DIR__ . '/../app/views/public_mail/detail_panel.php';
            exit;
        }

        if (function_exists('session_write_close')) @session_write_close();

        if ($getAction === 'inline_image_bundle') {
            $partIds = isset($_GET['part_ids']) ? explode(',', (string)$_GET['part_ids']) : array();
            $bundle = $service->getInlineImageBundle($messageKey, $partIds);
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: private, max-age=3600');
            header('X-Content-Type-Options: nosniff');
            echo json_encode(array('ok'=>true,'items'=>isset($bundle['items'])?$bundle['items']:array(),'failed'=>isset($bundle['failed'])?$bundle['failed']:array()));
            exit;
        }

        if ($getAction === 'inline_image') {
            if ($partId === '') throw new RuntimeException('메일 이미지 위치값을 확인할 수 없습니다.');
            $descriptor = $service->getInlineImageDescriptor($messageKey, $partId);
            $mime = isset($descriptor['mime_type']) ? strtolower((string)$descriptor['mime_type']) : 'image/octet-stream';
            if (strpos($mime, 'image/') !== 0) throw new RuntimeException('이미지 형식이 올바르지 않습니다.');
            while (ob_get_level() > 0) @ob_end_clean();
            header('Content-Type: ' . $mime);
            header('Content-Disposition: inline');
            header('Cache-Control: private, max-age=3600');
            header('X-Content-Type-Options: nosniff');
            $service->streamInlineImage($messageKey, $partId, function ($chunk) {
                echo $chunk;
                if (function_exists('flush')) @flush();
            });
            exit;
        }

        $detail = $service->getMessageDetail($messageKey);
        $esc = function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
        $baseUrl = function_exists('base_url') ? rtrim((string)base_url(), '/') : '';
        $detailClass = isset($detail['classification']) && is_array($detail['classification']) ? $detail['classification'] : array();
        $detailWorkflow = isset($detail['workflow']) && is_array($detail['workflow']) ? $detail['workflow'] : array();
        $selectedProjectId = !empty($detailWorkflow['project_id']) ? (string)$detailWorkflow['project_id'] : (isset($detailClass['project_id']) ? (string)$detailClass['project_id'] : '');
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        include __DIR__ . '/../app/views/public_mail/detail_fragment.php';
        exit;
    } catch (Exception $e) {
        if ($getAction === 'detail_panel') {
            http_response_code(503);
            header('Content-Type: text/html; charset=UTF-8');
            echo '<div class="pm-detail-load-error"><strong>메일 정보를 열지 못했습니다.</strong><p>'
                . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
                . '</p><button type="button" class="pm-btn pm-btn-light" data-retry-mail-panel data-message-key="'
                . htmlspecialchars(isset($messageKey)?$messageKey:'', ENT_QUOTES, 'UTF-8')
                . '">다시 시도</button></div>';
            exit;
        }
        if ($getAction === 'inline_image') {
            http_response_code(404);
            header('Content-Type: text/plain; charset=UTF-8');
            echo $e->getMessage();
            exit;
        }
        http_response_code(503);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<div class="pm-detail-load-error"><strong>메일 원문을 불러오지 못했습니다.</strong><p>'
            . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
            . '</p><button type="button" class="pm-btn pm-btn-light" data-retry-mail-detail>다시 시도</button></div>';
        exit;
    }
}

if ($method !== 'POST') {
    http_response_code(405);
    echo '허용되지 않은 요청입니다.';
    exit;
}

$csrf = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
$csrfValid = PublicMailWebHelper::verifyCsrf($csrf);
if (!$csrfValid && function_exists('csrf_check')) $csrfValid = csrf_check($csrf);
if (!$csrfValid) {
    if (PublicMailWebHelper::isAjax()) PublicMailWebHelper::jsonResponse(array('ok'=>false,'message'=>'보안 확인값이 올바르지 않습니다. 페이지를 새로고침하세요.'),419);
    PublicMailWebHelper::redirectWithMessage('public_mail.php','error','보안 확인값이 올바르지 않습니다.');
}

$action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
$currentUser = PublicMailWebHelper::currentUserName();
$isAjax = PublicMailWebHelper::isAjax();

try {
    if ($action === 'get_sync_status') {
        PublicMailWebHelper::requireDevelopmentDepartment();
        $state = $service->getSyncState();
        $result = array('ok'=>true,'message'=>'동기화 상태를 확인했습니다.','state'=>$state);
        if ($isAjax) PublicMailWebHelper::jsonResponse($result,200);
        PublicMailWebHelper::redirectWithMessage('public_mail_settings.php','success',$result['message']);
    }

    if ($action === 'sync_new' || $action === 'sync_initial' || $action === 'automation_tick') {
        PublicMailWebHelper::requireDevelopmentDepartment();
        $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 0;
        if ($action === 'sync_initial') $result = $service->syncBatch($limit,'initial');
        elseif ($action === 'automation_tick') $result = $service->runAutomationTick($limit);
        else $result = $service->syncNewBatch($limit);
        if (!isset($result['repaired_count'])) $result['repaired_count'] = 0;
        if ($isAjax) PublicMailWebHelper::jsonResponse($result,200);
        PublicMailWebHelper::redirectWithMessage('public_mail.php','success',$result['message']);
    }

    if ($action === 'start_full_import') {
        PublicMailWebHelper::requireDevelopmentDepartment();
        $result = $service->startFullImport();
        if ($isAjax) PublicMailWebHelper::jsonResponse($result,200);
        PublicMailWebHelper::redirectWithMessage('public_mail_settings.php','success',$result['message']);
    }

    if ($action === 'pause_full_import' || $action === 'resume_full_import' || $action === 'cancel_full_import') {
        PublicMailWebHelper::requireDevelopmentDepartment();
        $command = $action === 'pause_full_import' ? 'pause' : ($action === 'resume_full_import' ? 'resume' : 'cancel');
        $result = $service->controlFullImport($command);
        if ($isAjax) PublicMailWebHelper::jsonResponse($result,200);
        PublicMailWebHelper::redirectWithMessage('public_mail_settings.php','success',$result['message']);
    }

    if ($action === 'start_metadata_repair') {
        PublicMailWebHelper::requireDevelopmentDepartment();
        $result = $service->startMetadataRepair();
        if ($isAjax) PublicMailWebHelper::jsonResponse($result,200);
        PublicMailWebHelper::redirectWithMessage('public_mail_settings.php','success',$result['message']);
    }

    if ($action === 'run_metadata_repair_once') {
        PublicMailWebHelper::requireDevelopmentDepartment();
        if (function_exists('session_write_close')) @session_write_close();
        @set_time_limit(40);
        $state = $service->getSyncState();
        if (empty($state['metadata_repair']['active'])) $service->startMetadataRepair();
        $result = $service->runMetadataRepairBatch(20,20);
        if ($isAjax) PublicMailWebHelper::jsonResponse($result,200);
        PublicMailWebHelper::redirectWithMessage('public_mail_settings.php','success',$result['message']);
    }

    if ($action === 'pause_metadata_repair' || $action === 'resume_metadata_repair' || $action === 'cancel_metadata_repair') {
        PublicMailWebHelper::requireDevelopmentDepartment();
        $command = $action === 'pause_metadata_repair' ? 'pause' : ($action === 'resume_metadata_repair' ? 'resume' : 'cancel');
        $result = $service->controlMetadataRepair($command);
        if ($isAjax) PublicMailWebHelper::jsonResponse($result,200);
        PublicMailWebHelper::redirectWithMessage('public_mail_settings.php','success',$result['message']);
    }

    if ($action === 'update_workflow' || $action === 'reply_completed' || $action === 'reclassify' || $action === 'rebuild_body_cache') {
        $messageKey = isset($_POST['message_key']) ? trim((string)$_POST['message_key']) : '';
        if ($messageKey === '' && isset($_POST['uid']) && (int)$_POST['uid'] > 0) $messageKey = (string)(int)$_POST['uid'];
        if ($messageKey === '') throw new RuntimeException('메일 식별값을 확인할 수 없습니다.');

        if ($action === 'update_workflow') {
            $changes = array(
                'department'=>isset($_POST['department'])?trim((string)$_POST['department']):'',
                'project_id'=>isset($_POST['project_id'])?trim((string)$_POST['project_id']):'',
                'project_name'=>isset($_POST['project_name'])?trim((string)$_POST['project_name']):'',
                'assignee_id'=>isset($_POST['assignee_id'])?trim((string)$_POST['assignee_id']):'',
                'assignee_name'=>isset($_POST['assignee_name'])?trim((string)$_POST['assignee_name']):'',
                'status'=>isset($_POST['status'])?trim((string)$_POST['status']):'미확인',
                'priority'=>isset($_POST['priority'])?trim((string)$_POST['priority']):'보통',
                'important'=>!empty($_POST['important']),
                'memo'=>isset($_POST['memo'])?trim((string)$_POST['memo']):''
            );
            $workflow = $service->updateWorkflow($messageKey,$changes,$currentUser);
            $result = array('ok'=>true,'message'=>'메일 처리정보를 저장했습니다.','workflow'=>$workflow);
        } elseif ($action === 'reply_completed') {
            $workflow = $service->updateWorkflow($messageKey,array('reply_completed'=>true,'reply_completed_at'=>date('Y-m-d H:i:s'),'reply_completed_by'=>$currentUser,'status'=>'발송완료'),$currentUser);
            $result = array('ok'=>true,'message'=>'Gmail 발송완료로 처리했습니다.','workflow'=>$workflow);
        } elseif ($action === 'reclassify') {
            $classification = $service->reclassify($messageKey);
            $result = array('ok'=>true,'message'=>'메일을 다시 자동분류했습니다.','classification'=>$classification);
        } else {
            if (function_exists('session_write_close')) @session_write_close();
            $service->rebuildBodyCache($messageKey);
            $result = array('ok'=>true,'message'=>'메일 본문 표시정보를 네이버 원본에서 다시 만들었습니다.');
        }
        if ($isAjax) PublicMailWebHelper::jsonResponse($result,200);
        PublicMailWebHelper::redirectWithMessage('public_mail.php?message='.rawurlencode($messageKey),'success',$result['message']);
    }

    if ($action === 'save_attachment_drive') {
        $messageKey = isset($_POST['message_key']) ? trim((string)$_POST['message_key']) : '';
        $partId = isset($_POST['part_id']) ? trim((string)$_POST['part_id']) : '';
        $projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
        if ($messageKey === '' || $partId === '') throw new RuntimeException('Google Drive에 저장할 첨부파일을 확인할 수 없습니다.');
        if (function_exists('session_write_close')) @session_write_close();
        @set_time_limit(0); @ignore_user_abort(true);
        $record = $service->saveAttachmentToDrive($messageKey,$partId,$projectId,$currentUser);
        $result = array('ok'=>true,'message'=>'첨부파일을 Google Drive에 저장했습니다.','record'=>$record);
        if ($isAjax) PublicMailWebHelper::jsonResponse($result,200);
        PublicMailWebHelper::redirectWithMessage('public_mail.php?message='.rawurlencode($messageKey),'success',$result['message']);
    }

    if ($action === 'repair_metadata') {
        PublicMailWebHelper::requireDevelopmentDepartment();
        $result = $service->startMetadataRepair();
        if ($isAjax) PublicMailWebHelper::jsonResponse($result,200);
        PublicMailWebHelper::redirectWithMessage('public_mail_settings.php','success',$result['message']);
    }

    if ($action === 'save_settings') {
        PublicMailWebHelper::requireDevelopmentDepartment();
        $service->saveSettings(array(
            'enabled'=>!empty($_POST['enabled']),
            'username'=>isset($_POST['username'])?trim((string)$_POST['username']):'',
            'password'=>isset($_POST['password'])?trim((string)$_POST['password']):'',
            'batch_size'=>isset($_POST['batch_size'])?(int)$_POST['batch_size']:100,
            'use_gpt_classifier'=>!empty($_POST['use_gpt_classifier']),
            'include_spam'=>!empty($_POST['include_spam']),
            'include_trash'=>!empty($_POST['include_trash'])
        ),$currentUser);
        PublicMailWebHelper::redirectWithMessage('public_mail_settings.php','success','네이버 메일 연동 설정을 저장했습니다.');
    }

    if ($action === 'test_connection') {
        PublicMailWebHelper::requireDevelopmentDepartment();
        $result = $service->testConnection(array('username'=>isset($_POST['username'])?trim((string)$_POST['username']):'','password'=>isset($_POST['password'])?trim((string)$_POST['password']):''));
        if ($isAjax) PublicMailWebHelper::jsonResponse($result,200);
        PublicMailWebHelper::redirectWithMessage('public_mail_settings.php','success',$result['message']);
    }

    if ($action === 'regenerate_cron_token') {
        PublicMailWebHelper::requireDevelopmentDepartment();
        $service->regenerateCronToken($currentUser);
        $result = array('ok'=>true,'message'=>'자동동기화 요청 헤더의 비밀키를 새로 만들었습니다. 기존 비밀키는 더 이상 작동하지 않습니다.');
        if ($isAjax) PublicMailWebHelper::jsonResponse($result,200);
        PublicMailWebHelper::redirectWithMessage('public_mail_settings.php','success',$result['message']);
    }

    if ($action === 'reset_mail_data') {
        PublicMailWebHelper::requireDevelopmentDepartment();
        $confirmation = isset($_POST['confirmation']) ? trim((string)$_POST['confirmation']) : '';
        if ($confirmation !== '초기화') throw new RuntimeException('초기화를 실행하려면 확인칸에 초기화라고 입력하세요.');
        $service->resetMailData();
        PublicMailWebHelper::redirectWithMessage('public_mail_settings.php','success','CPMS에 저장된 메일 목록과 처리정보를 초기화했습니다. 네이버 원본메일은 변경되지 않았습니다.');
    }

    throw new RuntimeException('지원하지 않는 요청입니다.');
} catch (Exception $e) {
    if ($isAjax) PublicMailWebHelper::jsonResponse(array('ok'=>false,'message'=>$e->getMessage()),400);
    $settingsActions = array('save_settings','test_connection','reset_mail_data','start_full_import','pause_full_import','resume_full_import','cancel_full_import','start_metadata_repair','run_metadata_repair_once','pause_metadata_repair','resume_metadata_repair','cancel_metadata_repair','regenerate_cron_token','repair_metadata','get_sync_status');
    $redirect = in_array($action,$settingsActions,true) ? 'public_mail_settings.php' : 'public_mail.php';
    if (!empty($_POST['message_key']) && $redirect === 'public_mail.php') $redirect .= '?message=' . rawurlencode((string)$_POST['message_key']);
    PublicMailWebHelper::redirectWithMessage($redirect,'error',$e->getMessage());
}
