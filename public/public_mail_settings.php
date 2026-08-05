<?php
/**
 * 파일 경로: C:\www\cpms\public\public_mail_settings.php
 *
 * 네이버 메일 관리자 설정 화면 진입 파일입니다.
 * PHP 5.6 호환 코드입니다.
 */

require_once __DIR__ . '/../app/bootstrap.php';

// 이 전용 진입 파일에서 사이드바의 ?r=... 링크를 누르면
// CPMS 메인 라우터(index.php)로 돌려보내 다른 메뉴가 정상 이동하도록 합니다.
if (isset($_GET['r']) && trim((string)$_GET['r']) !== '') {
    $routerQuery = $_GET;
    header('Location: index.php?' . http_build_query($routerQuery, '', '&'));
    exit;
}
require_once __DIR__ . '/../app/services/PublicMailService.php';
require_once __DIR__ . '/../app/services/PublicMailWebHelper.php';

use App\Services\PublicMailService;
use App\Services\PublicMailWebHelper;

PublicMailWebHelper::requireAdmin();

$service = new PublicMailService();
$errorMessage = '';

try {
    $settings = $service->getSettings(false);
    $syncState = $service->getSyncState();
} catch (Exception $e) {
    $errorMessage = $e->getMessage();
    $settings = array(
        'enabled' => false,
        'username' => '',
        'initial_years' => 1,
        'batch_size' => 50,
        'imap_host' => 'imap.naver.com',
        'imap_port' => 993
    );
    $syncState = array();
}

PublicMailWebHelper::render('public_mail/settings', array(
    'selectedMenu' => '네이버 메일',
    'pageTitle' => '네이버 메일 설정',
    'settings' => $settings,
    'syncState' => $syncState,
    'csrfToken' => PublicMailWebHelper::csrfToken(),
    'flash' => PublicMailWebHelper::pullFlash(),
    'errorMessage' => $errorMessage
));
