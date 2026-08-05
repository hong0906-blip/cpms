<?php
/**
 * 파일 경로: C:\www\cpms\public\public_mail.php
 *
 * CPMS 네이버 메일 메인 진입 파일입니다.
 * PHP 5.6 호환 코드입니다.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/services/PublicMailService.php';
require_once __DIR__ . '/../app/services/PublicMailWebHelper.php';

use App\Services\PublicMailService;
use App\Services\PublicMailWebHelper;

PublicMailWebHelper::requireLogin();

$service = new PublicMailService();
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 30;
$selectedUid = isset($_GET['uid']) ? (int)$_GET['uid'] : 0;

$filters = array(
    'query' => isset($_GET['query']) ? trim((string)$_GET['query']) : '',
    'period' => isset($_GET['period']) ? trim((string)$_GET['period']) : '1y',
    'department' => isset($_GET['department']) ? trim((string)$_GET['department']) : '',
    'status' => isset($_GET['status']) ? trim((string)$_GET['status']) : '',
    'priority' => isset($_GET['priority']) ? trim((string)$_GET['priority']) : '',
    'project_id' => isset($_GET['project_id']) ? trim((string)$_GET['project_id']) : '',
    'assignee_id' => isset($_GET['assignee_id']) ? trim((string)$_GET['assignee_id']) : '',
    'quick' => isset($_GET['quick']) ? trim((string)$_GET['quick']) : ''
);

$errorMessage = '';
$detail = null;

try {
    $list = $service->getMessageList($filters, $page, $perPage);
    $counts = $service->getDashboardCounts();
    $settings = $service->getSettings(false);
    $syncState = $service->getSyncState();
    $employees = $service->getEmployees();
    $projects = $service->getProjects();
} catch (Exception $e) {
    $errorMessage = $e->getMessage();
    $list = array('items' => array(), 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'page_count' => 1);
    $counts = array('all' => 0, 'unread' => 0, 'urgent' => 0, 'unclassified' => 0, 'unassigned' => 0, 'unfinished' => 0);
    $settings = array('enabled' => false, 'username' => '');
    $syncState = array();
    $employees = array();
    $projects = array();
}

// 본문 또는 첨부파일을 네이버에서 읽는 중 오류가 나도
// 이미 저장된 메일 목록과 분류 현황은 그대로 보여줍니다.
if ($selectedUid > 0) {
    try {
        $detail = $service->getMessageDetail($selectedUid);
    } catch (Exception $e) {
        $detail = null;
        $detailError = '메일 상세내용을 불러오지 못했습니다: ' . $e->getMessage();
        $errorMessage = $errorMessage !== '' ? ($errorMessage . ' / ' . $detailError) : $detailError;
    }
}

$currentEmail = PublicMailWebHelper::currentUserEmail();
$newMailUrl = $service->buildGmailComposeUrl(array(), $currentEmail, 'new');
$replyMailUrl = $detail ? $service->buildGmailComposeUrl($detail, $currentEmail, 'reply') : '';
$flash = PublicMailWebHelper::pullFlash();
$csrfToken = PublicMailWebHelper::csrfToken();
$taskCsrfToken = function_exists('csrf_token') ? csrf_token() : '';
$taskRequestToken = md5(uniqid('', true) . session_id());
$isMailAdmin = false;
try {
    $isMailAdmin = \App\Core\Auth::isMaster();
} catch (Exception $e) {
    $isMailAdmin = false;
}

PublicMailWebHelper::render('public_mail/index', array(
    'selectedMenu' => '네이버 메일',
    'pageTitle' => '네이버 메일',
    'list' => $list,
    'counts' => $counts,
    'filters' => $filters,
    'settings' => $settings,
    'syncState' => $syncState,
    'employees' => $employees,
    'projects' => $projects,
    'detail' => $detail,
    'selectedUid' => $selectedUid,
    'newMailUrl' => $newMailUrl,
    'replyMailUrl' => $replyMailUrl,
    'csrfToken' => $csrfToken,
    'flash' => $flash,
    'errorMessage' => $errorMessage,
    'currentUserName' => PublicMailWebHelper::currentUserName(),
    'currentUserEmail' => $currentEmail,
    'isMailAdmin' => $isMailAdmin,
    'taskCsrfToken' => $taskCsrfToken,
    'taskRequestToken' => $taskRequestToken
));
