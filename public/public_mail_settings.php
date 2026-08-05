<?php
/**
 * 파일 경로: C:\www\cpms\public\public_mail_settings.php
 *
 * 네이버 메일 관리자 설정 화면 진입 파일입니다.
 * PHP 5.6 호환 코드입니다.
 */

require_once __DIR__ . '/../app/bootstrap.php';
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
