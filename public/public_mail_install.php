<?php
/**
 * 파일 경로: C:\www\cpms\public\public_mail_install.php
 *
 * 네이버 메일 메뉴 설치·업데이트 도우미입니다.
 * - 기존 sidebar.php를 백업한 후 네이버 메일 메뉴만 추가합니다.
 * - 기존 1분 브라우저 자동확인 코드를 제거하고 외부 예약서비스 방식으로 전환합니다.
 * - 설치 후 이 파일은 서버에서 삭제하세요.
 * PHP 5.6 호환 코드입니다.
 * CPMS_PUBLIC_MAIL_VERSION: 1.7.19.2
 */

@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
if (ob_get_level() === 0) @ob_start();

/*
 * 일부 파일만 덮어쓴 상태에서 PHP 치명적 오류가 발생해도 빈 500 화면 대신
 * 사용자가 다시 올려야 할 파일을 알 수 있는 안내 화면을 출력합니다.
 */
register_shutdown_function(function () {
    $error = error_get_last();
    if (!is_array($error)) return;
    $fatalTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
    if (!in_array(isset($error['type']) ? (int)$error['type'] : 0, $fatalTypes, true)) return;
    while (ob_get_level() > 0) @ob_end_clean();
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }
    $detail = isset($error['message']) ? (string)$error['message'] : '알 수 없는 PHP 오류';
    $detail = htmlspecialchars($detail, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>CPMS 네이버 메일 설치 오류</title><style>body{margin:0;padding:24px;background:#f3f6f8;font-family:Arial,"Malgun Gothic",sans-serif;color:#172033}.box{max-width:760px;margin:40px auto;background:#fff;border:1px solid #dfe6ee;border-radius:16px;padding:24px;box-shadow:0 10px 30px rgba(0,0,0,.08)}h1{font-size:24px;margin:0 0 12px}.warn{background:#fff4ed;border:1px solid #ffd6ae;color:#b54708;padding:14px;border-radius:10px;font-weight:700}.detail{margin-top:14px;padding:12px;background:#f8fafc;border-radius:10px;word-break:break-all;color:#475467}.path{margin-top:14px;font-weight:700}</style></head><body><div class="box">';
    echo '<h1>설치 파일이 일부만 적용되었습니다.</h1><div class="warn">패치 ZIP의 app, public 폴더 전체를 C:\\www\\cpms\\에 다시 덮어쓴 뒤 이 페이지를 새로고침하세요.</div>';
    echo '<div class="detail">서버 확인 내용: ' . $detail . '</div><div class="path">기존 메일, 복구된 제목, cron 설정은 삭제되지 않습니다.</div></div></body></html>';
});

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/services/PublicMailStorageService.php';
require_once __DIR__ . '/../app/services/PublicMailService.php';
require_once __DIR__ . '/../app/services/PublicMailIndexService.php';

use App\Services\PublicMailStorageService;
use App\Services\PublicMailService;
use App\Services\PublicMailIndexService;

if (!class_exists('\\App\\Core\\Auth') || !\App\Core\Auth::check()) {
    header('Location: index.php?r=login');
    exit;
}

$allowed = false;
try {
    $allowed = \App\Core\Auth::isMaster();
} catch (Exception $e) {
    $allowed = false;
}

if (!$allowed) {
    http_response_code(403);
    echo '마스터 관리자만 설치할 수 있습니다.';
    exit;
}

function pm_install_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function pm_install_csrf()
{
    if (empty($_SESSION['public_mail_install_csrf'])) {
        $strong = false;
        $bytes = openssl_random_pseudo_bytes(32, $strong);
        $_SESSION['public_mail_install_csrf'] = hash('sha256', $bytes . session_id());
    }
    return (string)$_SESSION['public_mail_install_csrf'];
}

function pm_install_verify_csrf($token)
{
    $expected = isset($_SESSION['public_mail_install_csrf']) ? (string)$_SESSION['public_mail_install_csrf'] : '';
    return $expected !== '' && (function_exists('hash_equals') ? hash_equals($expected, (string)$token) : $expected === (string)$token);
}

function pm_install_backup($path)
{
    $backupDir = PublicMailStorageService::rootPath() . DIRECTORY_SEPARATOR . 'backups';
    if (!is_dir($backupDir) && !@mkdir($backupDir, 0770, true) && !is_dir($backupDir)) {
        throw new RuntimeException('백업 폴더를 만들 수 없습니다.');
    }

    $backupPath = $backupDir . DIRECTORY_SEPARATOR . basename($path) . '.' . date('Ymd_His') . '.bak';
    if (!@copy($path, $backupPath)) {
        throw new RuntimeException('기존 파일 백업에 실패했습니다.');
    }
    return $backupPath;
}

function pm_install_patch_sidebar($sidebarPath)
{
    $content = @file_get_contents($sidebarPath);
    if ($content === false) {
        throw new RuntimeException('sidebar.php 파일을 읽을 수 없습니다.');
    }

    $originalContent = $content;
    $variableAnchor = '$usageAnalyticsMenu = \'사용현황 분석\';';
    $itemAnchor = 'foreach ($googleShortcutMenuItems as $googleShortcutMenuItem) {';
    $mobileItemAnchor = "    if (\$canAccessCeoIndex) {\n      array_splice(\$mobileNavItems";
    $mobileIconAnchor = '        <i data-lucide="<?php echo h($mobileItem[\'icon\']); ?>"></i>';

    $variableBlock = "\n/* CPMS_PUBLIC_MAIL_VARIABLE_START */\n"
        . "\$publicMailMenu = '네이버 메일';\n"
        . "\$publicMailIcon = base_url() . '/assets/img/naver_n_icon.svg?v=20260806_7192';\n"
        . "/* CPMS_PUBLIC_MAIL_VARIABLE_END */";

    $itemBlock = "/* CPMS_PUBLIC_MAIL_ITEM_START */\n"
        . "\$menuItems[] = array(\n"
        . "  'id'=>\$publicMailMenu,\n"
        . "  'label'=>\$publicMailMenu,\n"
        . "  'href'=>base_url() . '/public_mail.php',\n"
        . "  'iconImg'=>\$publicMailIcon,\n"
        . "  'iconAlt'=>'네이버 메일',\n"
        . "  'gradient'=>'from-green-500 to-emerald-600',\n"
        . "  'itemBg'=>'bg-green-50/60',\n"
        . "  'iconBg'=>'bg-white',\n"
        . "  'iconColor'=>'text-green-700',\n"
        . "  'hoverShadow'=>'hover:shadow-green-200'\n"
        . ");\n"
        . "/* CPMS_PUBLIC_MAIL_ITEM_END */\n";

    $mobileItemBlock = "    /* CPMS_PUBLIC_MAIL_MOBILE_ITEM_START */\n"
        . "    \$mobileNavItems[] = array(\n"
        . "      'menu' => 'public_mail',\n"
        . "      'label' => \$publicMailMenu,\n"
        . "      'icon' => 'mail',\n"
        . "      'iconImg' => \$publicMailIcon,\n"
        . "      'iconAlt' => '네이버 메일',\n"
        . "      'href' => base_url() . '/public_mail.php'\n"
        . "    );\n"
        . "    /* CPMS_PUBLIC_MAIL_MOBILE_ITEM_END */\n";

    $mobileIconBlock = <<<'HTML'
        <!-- CPMS_PUBLIC_MAIL_MOBILE_ICON_RENDER_START -->
        <?php if (isset($mobileItem['iconImg']) && trim((string)$mobileItem['iconImg']) !== ''): ?>
          <img src="<?php echo h((string)$mobileItem['iconImg']); ?>" alt="<?php echo h(isset($mobileItem['iconAlt']) ? (string)$mobileItem['iconAlt'] : (string)$mobileItem['label']); ?>" class="w-5 h-5 object-contain">
        <?php else: ?>
          <i data-lucide="<?php echo h($mobileItem['icon']); ?>"></i>
        <?php endif; ?>
        <!-- CPMS_PUBLIC_MAIL_MOBILE_ICON_RENDER_END -->
HTML;



    if (strpos($content, 'CPMS_PUBLIC_MAIL_VARIABLE_START') !== false) {
        $content = preg_replace('#\n?/\* CPMS_PUBLIC_MAIL_VARIABLE_START \*/.*?/\* CPMS_PUBLIC_MAIL_VARIABLE_END \*/#s', $variableBlock, $content, 1);
    } else {
        if (strpos($content, $variableAnchor) === false) {
            throw new RuntimeException('sidebar.php에서 메뉴 변수 추가 위치를 찾지 못했습니다. 최신 코드인지 확인하세요.');
        }
        $content = str_replace($variableAnchor, $variableAnchor . $variableBlock, $content);
    }

    if (strpos($content, 'CPMS_PUBLIC_MAIL_ITEM_START') !== false) {
        $content = preg_replace('#/\* CPMS_PUBLIC_MAIL_ITEM_START \*/.*?/\* CPMS_PUBLIC_MAIL_ITEM_END \*/\n?#s', $itemBlock, $content, 1);
    } else {
        if (strpos($content, $itemAnchor) === false) {
            throw new RuntimeException('sidebar.php에서 PC 메뉴 항목 추가 위치를 찾지 못했습니다. 최신 코드인지 확인하세요.');
        }
        $content = str_replace($itemAnchor, $itemBlock . $itemAnchor, $content);
    }

    if (strpos($content, 'CPMS_PUBLIC_MAIL_MOBILE_ITEM_START') !== false) {
        $content = preg_replace('#\s*/\* CPMS_PUBLIC_MAIL_MOBILE_ITEM_START \*/.*?/\* CPMS_PUBLIC_MAIL_MOBILE_ITEM_END \*/\n?#s', "\n" . $mobileItemBlock, $content, 1);
    } else {
        if (strpos($content, $mobileItemAnchor) === false) {
            throw new RuntimeException('sidebar.php에서 모바일 메뉴 항목 추가 위치를 찾지 못했습니다. 최신 코드인지 확인하세요.');
        }
        $content = str_replace($mobileItemAnchor, $mobileItemBlock . $mobileItemAnchor, $content);
    }

    if (strpos($content, 'CPMS_PUBLIC_MAIL_MOBILE_ICON_RENDER_START') !== false) {
        $content = preg_replace('#\s*<!-- CPMS_PUBLIC_MAIL_MOBILE_ICON_RENDER_START -->.*?<!-- CPMS_PUBLIC_MAIL_MOBILE_ICON_RENDER_END -->#s', "\n" . $mobileIconBlock, $content, 1);
    } else {
        if (strpos($content, $mobileIconAnchor) === false) {
            throw new RuntimeException('sidebar.php에서 모바일 메뉴 아이콘 출력 위치를 찾지 못했습니다. 최신 코드인지 확인하세요.');
        }
        $content = str_replace($mobileIconAnchor, $mobileIconBlock, $content);
    }

    /* v1.6부터 직원 브라우저의 1분 자동수집 코드를 완전히 제거합니다. */
    if (strpos($content, 'CPMS_PUBLIC_MAIL_LIVE_SYNC_START') !== false) {
        $content = preg_replace('#<!-- CPMS_PUBLIC_MAIL_LIVE_SYNC_START -->.*?<!-- CPMS_PUBLIC_MAIL_LIVE_SYNC_END -->\n?#s', '', $content, 1);
    }

    if ($content === $originalContent) {
        return array('changed' => false, 'message' => 'PC와 모바일 메뉴의 네이버 메일 항목이 최신 상태입니다.', 'backup' => '');
    }

    $backup = pm_install_backup($sidebarPath);
    if (@file_put_contents($sidebarPath, $content, LOCK_EX) === false) {
        throw new RuntimeException('sidebar.php 수정에 실패했습니다.');
    }

    return array('changed' => true, 'message' => 'PC 메뉴와 모바일 하단 메뉴에 네이버 메일을 추가했습니다. 모바일에서도 N 아이콘을 눌러 바로 들어갈 수 있습니다.', 'backup' => $backup);
}

function pm_install_unpatch_sidebar($sidebarPath)
{
    $content = @file_get_contents($sidebarPath);
    if ($content === false) {
        throw new RuntimeException('sidebar.php 파일을 읽을 수 없습니다.');
    }

    if (strpos($content, 'CPMS_PUBLIC_MAIL_VARIABLE_START') === false
        && strpos($content, 'CPMS_PUBLIC_MAIL_ITEM_START') === false
        && strpos($content, 'CPMS_PUBLIC_MAIL_MOBILE_ITEM_START') === false
        && strpos($content, 'CPMS_PUBLIC_MAIL_LIVE_SYNC_START') === false) {
        return array('changed' => false, 'message' => '제거할 네이버 메일 메뉴가 없습니다.', 'backup' => '');
    }

    $backup = pm_install_backup($sidebarPath);
    $content = preg_replace('#\n?/\* CPMS_PUBLIC_MAIL_VARIABLE_START \*/.*?/\* CPMS_PUBLIC_MAIL_VARIABLE_END \*/#s', '', $content);
    $content = preg_replace('#/\* CPMS_PUBLIC_MAIL_ITEM_START \*/.*?/\* CPMS_PUBLIC_MAIL_ITEM_END \*/\n?#s', '', $content);
    $content = preg_replace('#\s*/\* CPMS_PUBLIC_MAIL_MOBILE_ITEM_START \*/.*?/\* CPMS_PUBLIC_MAIL_MOBILE_ITEM_END \*/\n?#s', "\n", $content);
    $content = preg_replace('#<!-- CPMS_PUBLIC_MAIL_LIVE_SYNC_START -->.*?<!-- CPMS_PUBLIC_MAIL_LIVE_SYNC_END -->\n?#s', '', $content);

    if (@file_put_contents($sidebarPath, $content, LOCK_EX) === false) {
        throw new RuntimeException('sidebar.php 메뉴 제거에 실패했습니다.');
    }

    return array('changed' => true, 'message' => '왼쪽 메뉴에서 네이버 메일을 제거했습니다.', 'backup' => $backup);
}

function pm_install_critical_requirements()
{
    return array(
        'app/services/PublicMailStorageService.php' => array('getTitleRefreshSubjectMap', 'saveTitleRefreshQueue', 'saveSyncState'),
        'app/services/PublicMailIndexService.php' => array('function getLiveState', 'function getLiveUpdates'),
        'app/views/public_mail/index.php' => array('data-live-mail="1"', '20260806_7192'),
        'app/views/public_mail/_mail_rows.php' => array('data-live-mail-row'),
        'public/public_mail_live.php' => array('getLiveUpdates'),
        'public/assets/js/public_mail.js' => array('CPMS_PUBLIC_MAIL_VERSION: 1.7.19.2', 'bindLiveMailUpdates', "'r=ping'"),
        'public/assets/css/public_mail.css' => array('CPMS_PUBLIC_MAIL_VERSION: 1.7.19.2', 'pm-live-mail-toast', '.pm-mail-row.is-live-new'),
        'public/public_mail_install.php' => array('CPMS_PUBLIC_MAIL_VERSION: 1.7.19.2')
    );
}

function pm_install_check_requirements($root, $requirements)
{
    $failed = array();
    foreach ($requirements as $relativePath => $markers) {
        $absolutePath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (!is_file($absolutePath)) {
            $failed[] = $relativePath . ' (파일 없음)';
            continue;
        }
        $content = (string)@file_get_contents($absolutePath);
        foreach ($markers as $marker) {
            if (strpos($content, (string)$marker) === false) {
                $failed[] = $relativePath . ' (이전 파일)';
                break;
            }
        }
    }
    return array('ok'=>empty($failed), 'failed'=>$failed);
}

$root = dirname(__DIR__);
if (!class_exists('App\\Services\\PublicMailStorageService')
    || !method_exists('App\\Services\\PublicMailStorageService', 'ensureStorage')) {
    throw new RuntimeException('PublicMailStorageService.php가 완전히 덮어써지지 않았습니다.');
}
PublicMailStorageService::ensureStorage();
$sidebarPath = $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'sidebar.php';
$message = '';
$messageType = 'success';
$backupPath = '';

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!pm_install_verify_csrf(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
            throw new RuntimeException('보안 확인값이 올바르지 않습니다.');
        }

        $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
        if ($action === 'install') {
            $preflightResult = pm_install_check_requirements($root, pm_install_critical_requirements());
            if (empty($preflightResult['ok'])) {
                throw new RuntimeException('필수 파일이 완전히 덮어써지지 않았습니다. 패치 ZIP의 app, public 폴더 전체를 다시 덮어쓴 뒤 새로고침하세요. 미적용: ' . implode(', ', $preflightResult['failed']));
            }
            if (!method_exists('App\\Services\\PublicMailIndexService', 'getLiveState')
                || !method_exists('App\\Services\\PublicMailIndexService', 'getLiveUpdates')) {
                throw new RuntimeException('PublicMailIndexService.php가 이전 버전입니다. 패치 ZIP을 다시 덮어쓰세요.');
            }
            $result = pm_install_patch_sidebar($sidebarPath);
            @set_time_limit(60);
            /* 수집된 정상 제목 후보는 절대 지우지 않고 작은 보정 파일에서 즉시 사용합니다. */
            $oldUpdates = PublicMailStorageService::getTitleRefreshUpdates();
            $oldUpdateItems = isset($oldUpdates['items']) && is_array($oldUpdates['items']) ? $oldUpdates['items'] : array();
            $appliedTitleCount = 0;
            foreach ($oldUpdateItems as $oldUpdate) {
                if (is_array($oldUpdate) && isset($oldUpdate['subject']) && trim((string)$oldUpdate['subject']) !== '') $appliedTitleCount++;
            }
            $existingState = PublicMailStorageService::getSyncState();
            $existingTitleRefresh = isset($existingState['title_refresh']) && is_array($existingState['title_refresh']) ? $existingState['title_refresh'] : array();
            /* 오래된 작업 대기열만 비우고 정상 제목 업데이트 파일은 보존합니다. */
            PublicMailStorageService::saveTitleRefreshQueue(array());
            PublicMailStorageService::saveSyncState(array(
                'metadata_repair'=>array(
                    'active'=>false,'paused'=>false,'cancelled'=>true,
                    'status'=>'replaced_by_businesson_refresh','remaining_count'=>0,'last_error'=>''
                ),
                'title_normalization'=>array(
                    'enabled'=>false,'status'=>'disabled_for_speed',
                    'last_message'=>'평상시 메일 화면의 전체 제목 자동보정을 중지했습니다.'
                ),
                'title_refresh'=>array(
                    'active'=>false,'paused'=>false,'cancelled'=>false,
                    'status'=>'businesson_ready','phase'=>'idle','mode'=>'businesson_broken_only',
                    'target_name'=>'비즈니스온·스마트빌 깨진 제목','sender_domain'=>'businesson.co.kr',
                    'related_count'=>0,'broken_count'=>0,'normal_count'=>0,'skipped_count'=>0,
                    'cursor'=>0,'merge_cursor'=>0,
                    'retry_cursor'=>-1,'retry_count'=>0,'consecutive_errors'=>0,
                    'total_count'=>isset($existingTitleRefresh['total_count'])?(int)$existingTitleRefresh['total_count']:0,
                    'processed_count'=>isset($existingTitleRefresh['processed_count'])?(int)$existingTitleRefresh['processed_count']:0,
                    'updated_count'=>$appliedTitleCount,'merged_count'=>$appliedTitleCount,'applied_count'=>$appliedTitleCount,
                    'failed_count'=>isset($existingTitleRefresh['failed_count'])?(int)$existingTitleRefresh['failed_count']:0,'remaining_count'=>0,
                    'last_batch_count'=>0,'current_position'=>-1,'current_mailbox'=>'','current_uid'=>0,
                    'inflight'=>array(),'skipped_items'=>array(),'last_error_code'=>'',
                    'last_result_reason'=>'즉시 적용 준비 완료','last_old_subject_preview'=>'','last_candidate_subject_preview'=>'','last_error'=>'',
                    'last_message'=>'기존에 수집한 정상 제목 ' . number_format($appliedTitleCount) . '건을 목록과 상세화면에 즉시 적용합니다.'
                ),
                'recent_mail_recovery'=>array(
                    'active'=>true,'since_timestamp'=>time()-172800,'started_at'=>date('Y-m-d H:i:s'),'finished_at'=>'',
                    'last_run_at'=>'','checked_count'=>0,'added_count'=>0,'failed_count'=>0,'remaining_count'=>0,
                    'last_message'=>'최근 48시간 누락 메일 재확인을 예약했습니다. 다음 자동동기화에서 시작합니다.'
                ),
                'new_message_inflight'=>array()
            ));
            /* 설치 요청에서는 5천여 건 색인을 읽지 않습니다. 첫 메일 화면이 가볍게 상태파일을 자동 준비합니다. */
            $result['message'] .= ' 기존 정상 제목 ' . number_format($appliedTitleCount) . '건을 보존했습니다. 새 메일 자동표시는 첫 메일 화면 접속 시 자동 준비됩니다.';
        } elseif ($action === 'uninstall') {
            $result = pm_install_unpatch_sidebar($sidebarPath);
        } else {
            throw new RuntimeException('지원하지 않는 요청입니다.');
        }

        $message = $result['message'];
        $backupPath = $result['backup'];
    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

$requiredFiles = array(
    'app/services/GoogleDriveHelper.php' => '',
    'app/services/PublicMailStorageService.php' => 'getTitleRefreshSubjectMap',
    'app/services/PublicMailImapClient.php' => 'fetchHeaderPrefix',
    'app/services/PublicMailClassifierService.php' => '',
    'app/services/PublicMailLargeAttachmentService.php' => '',
    'app/services/PublicMailDriveService.php' => '',
    'app/services/PublicMailIndexService.php' => 'function getLiveUpdates',
    'app/services/PublicMailService.php' => 'runAutomationTick',
    'app/services/PublicMailWebHelper.php' => 'requireDevelopmentDepartment',
    'app/views/public_mail/index.php' => '20260806_7192',
    'app/views/public_mail/_mail_rows.php' => 'data-live-mail-row',
    'app/views/public_mail/detail_panel.php' => 'data-mail-detail-content',
    'app/views/public_mail/detail_fragment.php' => 'CPMS_PUBLIC_MAIL_VERSION:',
    'app/views/public_mail/settings.php' => '24시간 외부 자동동기화',
    'public/public_mail.php' => 'CPMS_PUBLIC_MAIL_VERSION:',
    'public/public_mail_live.php' => 'getLiveUpdates',
    'public/public_mail_settings.php' => 'CPMS_PUBLIC_MAIL_VERSION:',
    'public/public_mail_action.php' => 'CPMS_PUBLIC_MAIL_VERSION:',
    'public/public_mail_title_refresh_worker.php' => 'CPMS_PUBLIC_MAIL_VERSION:',
    'public/public_mail_attachment.php' => 'CPMS_PUBLIC_MAIL_VERSION:',
    'public/assets/css/public_mail.css' => 'pm-live-mail-toast',
    'public/assets/img/naver_n_icon.svg' => '',
    'public/assets/js/public_mail.js' => 'CPMS_PUBLIC_MAIL_VERSION: 1.7.19.2',
    'public/cron/naver_mail_sync.php' => 'X-CPMS-Mail-Key'
);

$checks = array();
$allReady = true;
foreach ($requiredFiles as $relativePath => $requiredMarker) {
    $absolutePath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $exists = is_file($absolutePath);
    $markerOk = $exists;
    if ($exists && $requiredMarker !== '') {
        $contentCheck = (string)@file_get_contents($absolutePath);
        $markerOk = strpos($contentCheck, $requiredMarker) !== false;
    }
    $status = !$exists ? 'missing' : ($markerOk ? 'latest' : 'old');
    $checks[] = array('path'=>$relativePath,'exists'=>$exists,'marker_ok'=>$markerOk,'status'=>$status);
    if (!$exists || !$markerOk) $allReady = false;
}

$sidebarContent = is_file($sidebarPath) ? (string)@file_get_contents($sidebarPath) : '';
$installed = strpos($sidebarContent, 'CPMS_PUBLIC_MAIL_ITEM_START') !== false;
$mobileInstalled = strpos($sidebarContent, 'CPMS_PUBLIC_MAIL_MOBILE_ITEM_START') !== false
    && strpos($sidebarContent, 'CPMS_PUBLIC_MAIL_MOBILE_ICON_RENDER_START') !== false;
$latestInstalled = $installed && $mobileInstalled
    && strpos($sidebarContent, "\$publicMailMenu = '네이버 메일';") !== false
    && strpos($sidebarContent, 'CPMS_PUBLIC_MAIL_LIVE_SYNC_START') === false
    && strpos($sidebarContent, 'assets/img/naver_n_icon.svg') !== false;
$packageVersion = '1.7.19.2';
$indexStatus = array();
try {
    if (class_exists('App\\Services\\PublicMailService')
        && method_exists('App\\Services\\PublicMailService', 'getIndexStatus')) {
        $indexStatus = (new PublicMailService())->getIndexStatus();
    }
} catch (Exception $ignored) { $indexStatus = array(); }
$canOpenSettings = false;
try {
    $canOpenSettings = method_exists('\\App\\Core\\Auth', 'isDevelopmentDepartment')
        ? (bool)\App\Core\Auth::isDevelopmentDepartment()
        : false;
} catch (Exception $ignored) { $canOpenSettings = false; }
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CPMS 네이버 메일 설치</title>
    <style>
        *{box-sizing:border-box}body{margin:0;padding:30px 16px;background:#f3f6f8;color:#172033;font-family:Arial,"Malgun Gothic",sans-serif}.wrap{max-width:900px;margin:0 auto}.card{background:#fff;border:1px solid #dfe6ee;border-radius:18px;padding:26px;box-shadow:0 12px 35px rgba(23,32,51,.08)}h1{margin:0 0 8px;font-size:28px}.sub{margin:0 0 22px;color:#667085}.alert{margin:15px 0;padding:13px 15px;border-radius:11px;font-weight:700}.success{background:#ecfdf3;color:#067647;border:1px solid #abefc6}.error{background:#fef3f2;color:#b42318;border:1px solid #fecdca}.status{display:flex;gap:10px;align-items:center;padding:13px;border-radius:12px;background:#f8fafc}.dot{width:12px;height:12px;border-radius:50%;background:#98a2b3}.dot.on{background:#12b76a}.files{margin:18px 0;border:1px solid #e4e9ef;border-radius:12px;overflow:hidden}.row{display:flex;justify-content:space-between;gap:12px;padding:10px 13px;border-bottom:1px solid #edf1f5;font-size:13px}.row:last-child{border-bottom:0}.ok{color:#067647;font-weight:800}.old{color:#b54708;font-weight:800}.bad{color:#b42318;font-weight:800}.buttons{display:flex;gap:10px;flex-wrap:wrap}.btn{min-height:44px;padding:0 17px;border:0;border-radius:11px;font-weight:800;cursor:pointer}.primary{background:#0f766e;color:#fff}.danger{background:#fff1f0;color:#b42318;border:1px solid #fecdca}.link{display:inline-flex;align-items:center;min-height:44px;padding:0 17px;border-radius:11px;background:#eef4ff;color:#3538cd;text-decoration:none;font-weight:800}.note{margin-top:20px;padding-top:16px;border-top:1px solid #e9edf2;color:#b42318;font-weight:800}
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>CPMS 네이버 메일 설치</h1>
        <p class="sub">v1.7.19.2 설치검사·반복로딩 수정 패치입니다. 실제 CSS 기능을 정확히 확인하고, 새 메일 백그라운드 확인은 전체 로딩창을 띄우지 않습니다.</p>

        <?php if ($message !== ''): ?>
            <div class="alert <?php echo $messageType === 'error' ? 'error' : 'success'; ?>"><?php echo pm_install_h($message); ?><?php echo $backupPath !== '' ? '<br>백업: ' . pm_install_h($backupPath) : ''; ?></div>
        <?php endif; ?>

        <div class="status"><span class="dot <?php echo ($latestInstalled&&$allReady) ? 'on' : ''; ?>"></span><strong>설치 상태: <?php echo ($latestInstalled&&$allReady) ? 'v1.7.19.2 전체 적용' : ($installed ? '일부 파일 업데이트 필요' : '설치 전'); ?></strong></div><div class="status" style="margin-top:10px"><strong>목록 색인: <?php echo !empty($indexStatus['updated_at']) ? pm_install_h($indexStatus['updated_at']).' · '.number_format(isset($indexStatus['item_count'])?(int)$indexStatus['item_count']:0).'건' : '아직 생성되지 않음'; ?></strong></div>

        <div class="files">
            <?php foreach ($checks as $check): ?>
                <?php $checkClass = $check['status']==='latest'?'ok':($check['status']==='old'?'old':'bad'); ?>
                <?php $checkLabel = $check['status']==='latest'?'필수 기능 확인':($check['status']==='old'?'다시 덮어쓰기 필요':'파일 없음'); ?>
                <div class="row"><span><?php echo pm_install_h($check['path']); ?></span><span class="<?php echo $checkClass; ?>"><?php echo $checkLabel; ?></span></div>
            <?php endforeach; ?>
        </div>

        <div class="buttons">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo pm_install_h(pm_install_csrf()); ?>">
                <input type="hidden" name="action" value="install">
                <button class="btn primary" type="submit">네이버 메일 설치·업데이트</button>
            </form>
            <form method="post" onsubmit="return confirm('네이버 메일 메뉴를 제거하시겠습니까? 데이터는 삭제하지 않습니다.');">
                <input type="hidden" name="csrf_token" value="<?php echo pm_install_h(pm_install_csrf()); ?>">
                <input type="hidden" name="action" value="uninstall">
                <button class="btn danger" type="submit">메뉴만 제거</button>
            </form>
            <?php if ($installed && $canOpenSettings): ?><a class="link" href="public_mail_settings.php">연동 설정 열기</a><?php endif; ?>
        </div>

        <div class="note">설치가 끝나면 보안을 위해 public_mail_install.php 파일을 서버에서 삭제하세요.<br>설치 후 PC는 Ctrl+F5를 누르고, 모바일은 브라우저 탭을 완전히 닫았다가 다시 접속하세요.</div>
    </div>
</div>
</body>
</html>
