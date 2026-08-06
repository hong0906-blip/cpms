<?php
/**
 * 파일 경로: C:\www\cpms\public\public_mail_install.php
 *
 * 네이버 메일 메뉴 설치·업데이트 도우미입니다.
 * - 기존 sidebar.php를 백업한 후 네이버 메일 메뉴만 추가합니다.
 * - 기존 1분 브라우저 자동확인 코드를 제거하고 외부 예약서비스 방식으로 전환합니다.
 * - 설치 후 이 파일은 서버에서 삭제하세요.
 * PHP 5.6 호환 코드입니다.
 * CPMS_PUBLIC_MAIL_VERSION: 1.7.9
 */

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
        . "\$publicMailIcon = base_url() . '/assets/img/naver_n_icon.svg?v=20260806_79';\n"
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

PublicMailStorageService::ensureStorage();
$root = dirname(__DIR__);
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
            $result = pm_install_patch_sidebar($sidebarPath);
            $mailService = new PublicMailService();
            $rebuiltIndex = $mailService->rebuildIndex();
            $indexedCount = is_array($rebuiltIndex) && isset($rebuiltIndex['items']) && is_array($rebuiltIndex['items']) ? count($rebuiltIndex['items']) : 0;
            $result['message'] .= ' 메일 목록 색인도 ' . number_format($indexedCount) . '건으로 다시 만들었습니다.';
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
    'app/services/PublicMailStorageService.php' => "const VERSION = '1.7.9'",
    'app/services/PublicMailImapClient.php' => '',
    'app/services/PublicMailClassifierService.php' => '',
    'app/services/PublicMailLargeAttachmentService.php' => '',
    'app/services/PublicMailDriveService.php' => '',
    'app/services/PublicMailIndexService.php' => "const VERSION = '1.7.9'",
    'app/services/PublicMailService.php' => "const VERSION = '1.7.9'",
    'app/services/PublicMailWebHelper.php' => 'requireDevelopmentDepartment',
    'app/views/public_mail/index.php' => '20260806_79',
    'app/views/public_mail/detail_panel.php' => 'data-mail-detail-content',
    'app/views/public_mail/detail_fragment.php' => 'data-detail-fragment',
    'app/views/public_mail/settings.php' => '20260806_79',
    'public/public_mail.php' => 'CPMS_PUBLIC_MAIL_VERSION: 1.7.9',
    'public/public_mail_settings.php' => 'CPMS_PUBLIC_MAIL_VERSION: 1.7.9',
    'public/public_mail_action.php' => 'CPMS_PUBLIC_MAIL_VERSION: 1.7.9',
    'public/public_mail_attachment.php' => '',
    'public/assets/css/public_mail.css' => 'CPMS_PUBLIC_MAIL_VERSION: 1.7.9',
    'public/assets/img/naver_n_icon.svg' => '',
    'public/assets/js/public_mail.js' => 'CPMS_PUBLIC_MAIL_VERSION: 1.7.9',
    'public/cron/naver_mail_sync.php' => ''
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
$packageVersion = '1.7.9';
$indexStatus = array();
try { $indexStatus = (new PublicMailService())->getIndexStatus(); } catch (Exception $ignored) { $indexStatus = array(); }
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
        <p class="sub">v1.7.9 고속 자동복구 패치입니다. 모바일 메뉴를 유지하면서 깨진 제목 복구를 50~100건씩 자동 처리하고 통신 오류도 자동 재시도합니다.</p>

        <?php if ($message !== ''): ?>
            <div class="alert <?php echo $messageType === 'error' ? 'error' : 'success'; ?>"><?php echo pm_install_h($message); ?><?php echo $backupPath !== '' ? '<br>백업: ' . pm_install_h($backupPath) : ''; ?></div>
        <?php endif; ?>

        <div class="status"><span class="dot <?php echo ($latestInstalled&&$allReady) ? 'on' : ''; ?>"></span><strong>설치 상태: <?php echo ($latestInstalled&&$allReady) ? 'v1.7.9 전체 적용' : ($installed ? '일부 파일 업데이트 필요' : '설치 전'); ?></strong></div><div class="status" style="margin-top:10px"><strong>목록 색인: <?php echo !empty($indexStatus['updated_at']) ? pm_install_h($indexStatus['updated_at']).' · '.number_format(isset($indexStatus['item_count'])?(int)$indexStatus['item_count']:0).'건' : '아직 생성되지 않음'; ?></strong></div>

        <div class="files">
            <?php foreach ($checks as $check): ?>
                <?php $checkClass = $check['status']==='latest'?'ok':($check['status']==='old'?'old':'bad'); ?>
                <?php $checkLabel = $check['status']==='latest'?'v1.7.9 기반 확인':($check['status']==='old'?'이전 버전':'파일 없음'); ?>
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
