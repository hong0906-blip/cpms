<?php
/**
 * 파일 경로: C:\www\cpms\public\public_mail.php
 * 네이버 메일 메인 화면입니다. PHP 5.6 호환 코드입니다.
 * CPMS_PUBLIC_MAIL_VERSION: 1.7.7
 */
require_once __DIR__ . '/../app/bootstrap.php';
if (isset($_GET['r']) && trim((string)$_GET['r']) !== '') {
    header('Location: index.php?' . http_build_query($_GET, '', '&'));
    exit;
}
require_once __DIR__ . '/../app/services/PublicMailService.php';
require_once __DIR__ . '/../app/services/PublicMailWebHelper.php';

use App\Services\PublicMailService;
use App\Services\PublicMailWebHelper;

PublicMailWebHelper::requireLogin();
$service = new PublicMailService();
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 30;
$selectedMessageKey = isset($_GET['message']) ? trim((string)$_GET['message']) : '';
if ($selectedMessageKey === '' && isset($_GET['uid']) && (int)$_GET['uid'] > 0) {
    $selectedMessageKey = (string)(int)$_GET['uid'];
}

$mailboxType = isset($_GET['mailbox_type']) ? trim((string)$_GET['mailbox_type']) : '';
if (!in_array($mailboxType, array('', 'inbox', 'sent'), true)) {
    $mailboxType = '';
}

/*
 * v1.7.7부터 목록 화면 검색조건은 검색어·기간·현재 메일함 탭만 사용합니다.
 * 부서·현장·상태·중요도·담당자 필터는 화면과 처리에서 모두 제외합니다.
 */
$filters = array(
    'query' => isset($_GET['query']) ? trim((string)$_GET['query']) : '',
    'period' => isset($_GET['period']) ? trim((string)$_GET['period']) : '1y',
    'mailbox_type' => $mailboxType
);

$errorMessage = '';
try {
    $list = $service->getMessageList($filters, $page, $perPage);
    $settings = $service->getSettings(false);
    $syncState = $service->getSyncState();
} catch (Exception $e) {
    $errorMessage = $e->getMessage();
    $list = array('items'=>array(), 'total'=>0, 'page'=>1, 'per_page'=>$perPage, 'page_count'=>1);
    $settings = array('enabled'=>false, 'username'=>'');
    $syncState = array();
}

$canManageMailSettings = PublicMailWebHelper::isDevelopmentDepartment();

PublicMailWebHelper::render('public_mail/index', array(
    'selectedMenu' => '네이버 메일',
    'pageTitle' => '네이버 메일',
    'list' => $list,
    'filters' => $filters,
    'settings' => $settings,
    'syncState' => $syncState,
    'selectedMessageKey' => $selectedMessageKey,
    'csrfToken' => PublicMailWebHelper::csrfToken(),
    'flash' => PublicMailWebHelper::pullFlash(),
    'errorMessage' => $errorMessage,
    'currentUserName' => PublicMailWebHelper::currentUserName(),
    'currentUserEmail' => PublicMailWebHelper::currentUserEmail(),
    'canManageMailSettings' => $canManageMailSettings,
    'packageVersion' => '1.7.7'
));
