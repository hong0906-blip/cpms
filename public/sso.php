<?php
/**
 * C:\www\cpms\public\sso.php
 * - 사내포탈(CMSESSID) 로그인 세션을 이용해서 CPMS 자동로그인 처리
 * - 포탈 세션에 $_SESSION['user_email'] 이 있으면 CPMS 세션에 사용자 생성 후 대시보드로 이동
 */

require_once __DIR__ . '/../app/bootstrap.php';

$returnUrl = isset($_GET['return']) ? (string)$_GET['return'] : '?r=대시보드';
$returnUrl = cpms_safe_internal_redirect_url($returnUrl, '?r=대시보드');

// 포탈 세션 기반 자동 로그인 시도
if (\App\Core\Auth::autoLoginFromPortal() === true) {
    header('Location: ' . $returnUrl);
    exit;
}

// 포탈 로그인 정보가 없으면(세션 만료/미로그인) -> 포탈 진입점으로 이동
cpms_redirect_to_portal_login(cpms_current_absolute_url());
