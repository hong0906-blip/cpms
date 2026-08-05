<?php
/**
 * 파일 경로: C:\www\cpms\public\public_mail_action.php
 *
 * 공용메일 설정, 동기화, 분류 및 처리상태 변경 요청을 처리합니다.
 * PHP 5.6 호환 코드입니다.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/services/PublicMailService.php';
require_once __DIR__ . '/../app/services/PublicMailWebHelper.php';

use App\Services\PublicMailService;
use App\Services\PublicMailWebHelper;

PublicMailWebHelper::requireLogin();

if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'POST 요청만 허용됩니다.';
    exit;
}

$csrf = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
$csrfValid = PublicMailWebHelper::verifyCsrf($csrf);
if (!$csrfValid && function_exists('csrf_check')) {
    $csrfValid = csrf_check($csrf);
}
if (!$csrfValid) {
    if (PublicMailWebHelper::isAjax()) {
        PublicMailWebHelper::jsonResponse(array('ok' => false, 'message' => '보안 확인값이 올바르지 않습니다. 페이지를 새로고침하세요.'), 419);
    }
    PublicMailWebHelper::redirectWithMessage('public_mail.php', 'error', '보안 확인값이 올바르지 않습니다.');
}

$action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
$service = new PublicMailService();
$currentUser = PublicMailWebHelper::currentUserName();
$isAjax = PublicMailWebHelper::isAjax();

try {
    if ($action === 'sync_initial' || $action === 'sync_new') {
        $mode = $action === 'sync_new' ? 'new' : 'initial';
        $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 0;
        $isBackground = $action === 'sync_new' && !empty($_POST['background']);

        if ($isBackground) {
            $state = $service->getSyncState();
            $lastSuccessAt = isset($state['last_success_at']) ? strtotime((string)$state['last_success_at']) : false;
            if ($lastSuccessAt !== false && $lastSuccessAt > 0 && (time() - $lastSuccessAt) < 45) {
                $result = array(
                    'ok' => true,
                    'skipped' => true,
                    'message' => '최근 확인 후 45초가 지나지 않아 중복 연결을 생략했습니다.',
                    'added_count' => 0,
                    'state' => $state
                );
            } else {
                try {
                    $result = $service->syncBatch($limit, $mode);
                } catch (Exception $syncException) {
                    if (strpos($syncException->getMessage(), '다른 사용자가 메일을 가져오는 중') !== false) {
                        $result = array(
                            'ok' => true,
                            'skipped' => true,
                            'message' => '다른 화면에서 메일을 확인 중입니다.',
                            'added_count' => 0,
                            'state' => $service->getSyncState()
                        );
                    } else {
                        throw $syncException;
                    }
                }
            }
        } else {
            $result = $service->syncBatch($limit, $mode);
        }

        // 기존 버전에서 깨진 제목/본문 미리보기도 화면과 별개로 조금씩 복구합니다.
        $repairLimit = $isBackground ? 2 : 5;
        try {
            $result['repaired_count'] = $service->repairBrokenMetadataBatch($repairLimit);
        } catch (Exception $repairException) {
            $result['repaired_count'] = 0;
        }

        if ($isAjax) {
            PublicMailWebHelper::jsonResponse($result, 200);
        }
        PublicMailWebHelper::redirectWithMessage('public_mail.php', 'success', $result['message']);
    }

    if ($action === 'update_workflow') {
        $uid = isset($_POST['uid']) ? (int)$_POST['uid'] : 0;
        $changes = array(
            'department' => isset($_POST['department']) ? trim((string)$_POST['department']) : '',
            'project_id' => isset($_POST['project_id']) ? trim((string)$_POST['project_id']) : '',
            'project_name' => isset($_POST['project_name']) ? trim((string)$_POST['project_name']) : '',
            'assignee_id' => isset($_POST['assignee_id']) ? trim((string)$_POST['assignee_id']) : '',
            'assignee_name' => isset($_POST['assignee_name']) ? trim((string)$_POST['assignee_name']) : '',
            'status' => isset($_POST['status']) ? trim((string)$_POST['status']) : '미확인',
            'priority' => isset($_POST['priority']) ? trim((string)$_POST['priority']) : '보통',
            'important' => !empty($_POST['important']),
            'memo' => isset($_POST['memo']) ? trim((string)$_POST['memo']) : ''
        );

        $workflow = $service->updateWorkflow($uid, $changes, $currentUser);
        $result = array('ok' => true, 'message' => '메일 처리정보를 저장했습니다.', 'workflow' => $workflow);

        if ($isAjax) {
            PublicMailWebHelper::jsonResponse($result, 200);
        }
        PublicMailWebHelper::redirectWithMessage('public_mail.php?uid=' . $uid, 'success', $result['message']);
    }

    if ($action === 'reply_completed') {
        $uid = isset($_POST['uid']) ? (int)$_POST['uid'] : 0;
        $workflow = $service->updateWorkflow($uid, array(
            'reply_completed' => true,
            'reply_completed_at' => date('Y-m-d H:i:s'),
            'reply_completed_by' => $currentUser,
            'status' => '발송완료'
        ), $currentUser);

        $result = array('ok' => true, 'message' => 'Gmail 발송완료로 처리했습니다.', 'workflow' => $workflow);
        if ($isAjax) {
            PublicMailWebHelper::jsonResponse($result, 200);
        }
        PublicMailWebHelper::redirectWithMessage('public_mail.php?uid=' . $uid, 'success', $result['message']);
    }

    if ($action === 'reclassify') {
        $uid = isset($_POST['uid']) ? (int)$_POST['uid'] : 0;
        $classification = $service->reclassify($uid);
        $result = array('ok' => true, 'message' => '메일을 다시 자동분류했습니다.', 'classification' => $classification);

        if ($isAjax) {
            PublicMailWebHelper::jsonResponse($result, 200);
        }
        PublicMailWebHelper::redirectWithMessage('public_mail.php?uid=' . $uid, 'success', $result['message']);
    }

    if ($action === 'repair_metadata') {
        PublicMailWebHelper::requireAdmin();
        $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 20;
        $repaired = $service->repairBrokenMetadataBatch($limit);
        $result = array(
            'ok' => true,
            'repaired_count' => $repaired,
            'message' => $repaired > 0
                ? $repaired . '건의 제목과 본문 미리보기를 복구했습니다.'
                : '추가로 복구할 메일이 없습니다.'
        );
        if ($isAjax) PublicMailWebHelper::jsonResponse($result, 200);
        PublicMailWebHelper::redirectWithMessage('public_mail_settings.php', 'success', $result['message']);
    }

    if ($action === 'save_settings') {
        PublicMailWebHelper::requireAdmin();
        $settings = $service->saveSettings(array(
            'enabled' => !empty($_POST['enabled']),
            'username' => isset($_POST['username']) ? trim((string)$_POST['username']) : '',
            'password' => isset($_POST['password']) ? trim((string)$_POST['password']) : '',
            'initial_years' => isset($_POST['initial_years']) ? (int)$_POST['initial_years'] : 1,
            'batch_size' => isset($_POST['batch_size']) ? (int)$_POST['batch_size'] : 50,
            'use_gpt_classifier' => !empty($_POST['use_gpt_classifier'])
        ), $currentUser);

        PublicMailWebHelper::redirectWithMessage('public_mail_settings.php', 'success', '네이버 메일 연동 설정을 저장했습니다.');
    }

    if ($action === 'test_connection') {
        PublicMailWebHelper::requireAdmin();
        $result = $service->testConnection(array(
            'username' => isset($_POST['username']) ? trim((string)$_POST['username']) : '',
            'password' => isset($_POST['password']) ? trim((string)$_POST['password']) : ''
        ));

        if ($isAjax) {
            PublicMailWebHelper::jsonResponse($result, 200);
        }
        PublicMailWebHelper::redirectWithMessage('public_mail_settings.php', 'success', $result['message'] . ' 현재 메일 수: ' . (int)$result['mail_count']);
    }

    if ($action === 'reset_mail_data') {
        PublicMailWebHelper::requireAdmin();
        $confirmation = isset($_POST['confirmation']) ? trim((string)$_POST['confirmation']) : '';
        if ($confirmation !== '초기화') {
            throw new RuntimeException('초기화를 실행하려면 확인칸에 초기화라고 입력하세요.');
        }
        $service->resetMailData();
        PublicMailWebHelper::redirectWithMessage('public_mail_settings.php', 'success', 'CPMS에 저장된 메일 목록과 처리정보를 초기화했습니다. 네이버 원본메일은 변경되지 않았습니다.');
    }

    throw new RuntimeException('지원하지 않는 요청입니다.');
} catch (Exception $e) {
    if ($isAjax) {
        PublicMailWebHelper::jsonResponse(array('ok' => false, 'message' => $e->getMessage()), 400);
    }

    $redirect = strpos($action, 'settings') !== false || in_array($action, array('save_settings', 'test_connection', 'reset_mail_data'), true)
        ? 'public_mail_settings.php'
        : 'public_mail.php';

    if (isset($_POST['uid']) && (int)$_POST['uid'] > 0 && $redirect === 'public_mail.php') {
        $redirect .= '?uid=' . (int)$_POST['uid'];
    }

    PublicMailWebHelper::redirectWithMessage($redirect, 'error', $e->getMessage());
}
