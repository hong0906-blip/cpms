<?php
/**
 * 파일 경로: C:\www\cpms\public\public_mail_install.php
 *
 * 네이버 메일 메뉴 설치·업데이트 도우미입니다.
 * - 기존 sidebar.php를 백업한 후 네이버 메일 메뉴와 1분 자동확인을 추가합니다.
 * - 호스팅용 웹 자동동기화 주소와 네이버 N 아이콘 파일을 확인합니다.
 * - 설치 후 이 파일은 서버에서 삭제하세요.
 * PHP 5.6 호환 코드입니다.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/services/PublicMailStorageService.php';

use App\Services\PublicMailStorageService;

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
    $liveAnchor = '<div id="cpmsContentShell"';

    $variableBlock = "\n/* CPMS_PUBLIC_MAIL_VARIABLE_START */\n"
        . "\$publicMailMenu = '네이버 메일';\n"
        . "\$publicMailIcon = base_url() . '/assets/img/naver_n_icon.svg?v=20260805_5';\n"
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

    $liveSyncBlock = <<<'SIDEBAR'
<!-- CPMS_PUBLIC_MAIL_LIVE_SYNC_START -->
<script>
(function () {
    'use strict';

    if (!window.fetch || !window.FormData) return;

    var endpoint = <?php echo json_encode(base_url() . '/public_mail_action.php', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    var mailUrl = <?php echo json_encode(base_url() . '/public_mail.php', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    var csrfToken = <?php echo json_encode(csrf_token(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    var intervalMs = 60000;
    var localAttemptKey = 'cpms_public_mail_live_attempt_at';
    var localLastUidKey = 'cpms_public_mail_live_last_uid';
    var localBadgeKey = 'cpms_public_mail_live_badge_count';
    var busy = false;

    function storageGet(key) {
        try { return window.localStorage ? window.localStorage.getItem(key) : null; }
        catch (e) { return null; }
    }

    function storageSet(key, value) {
        try { if (window.localStorage) window.localStorage.setItem(key, String(value)); }
        catch (e) {}
    }

    function findMenuLink() {
        var links = document.querySelectorAll('a[href]');
        var i;
        for (i = 0; i < links.length; i++) {
            var href = links[i].getAttribute('href') || '';
            if (href === mailUrl || href.indexOf('/public_mail.php') !== -1) return links[i];
        }
        return null;
    }

    function renderBadge(count) {
        count = parseInt(count, 10) || 0;
        var link = findMenuLink();
        if (!link) return;

        var badge = link.querySelector('[data-public-mail-live-badge]');
        if (count <= 0) {
            if (badge && badge.parentNode) badge.parentNode.removeChild(badge);
            return;
        }

        if (!badge) {
            badge = document.createElement('span');
            badge.setAttribute('data-public-mail-live-badge', '1');
            badge.style.marginLeft = 'auto';
            badge.style.minWidth = '20px';
            badge.style.height = '20px';
            badge.style.padding = '0 6px';
            badge.style.borderRadius = '999px';
            badge.style.background = '#ef4444';
            badge.style.color = '#ffffff';
            badge.style.fontSize = '11px';
            badge.style.fontWeight = '800';
            badge.style.display = 'inline-flex';
            badge.style.alignItems = 'center';
            badge.style.justifyContent = 'center';
            link.appendChild(badge);
        }
        badge.textContent = count > 99 ? '99+' : String(count);
    }

    function clearBadge() {
        storageSet(localBadgeKey, 0);
        renderBadge(0);
    }

    function showToast(count) {
        var old = document.getElementById('cpmsPublicMailLiveToast');
        if (old && old.parentNode) old.parentNode.removeChild(old);

        var toast = document.createElement('button');
        toast.type = 'button';
        toast.id = 'cpmsPublicMailLiveToast';
        toast.textContent = '네이버 새 메일 ' + count + '건이 도착했습니다.';
        toast.style.position = 'fixed';
        toast.style.right = '24px';
        toast.style.top = '24px';
        toast.style.zIndex = '99999';
        toast.style.border = '0';
        toast.style.borderRadius = '12px';
        toast.style.background = '#03c75a';
        toast.style.color = '#ffffff';
        toast.style.padding = '13px 17px';
        toast.style.fontWeight = '800';
        toast.style.boxShadow = '0 12px 30px rgba(0,0,0,.18)';
        toast.style.cursor = 'pointer';
        toast.onclick = function () { window.location.href = mailUrl; };
        document.body.appendChild(toast);
        window.setTimeout(function () {
            if (toast && toast.parentNode) toast.parentNode.removeChild(toast);
        }, 8000);
    }

    function handleSyncResult(result) {
        if (!result || !result.ok) return;
        var state = result.state && typeof result.state === 'object' ? result.state : {};
        if (state.last_mode === 'full_import') return;
        var addedCount = parseInt(result.added_count, 10) || 0;
        if (addedCount > 0) {
            var badgeCount = (parseInt(storageGet(localBadgeKey), 10) || 0) + addedCount;
            storageSet(localBadgeKey, badgeCount);
            renderBadge(badgeCount);
            showToast(addedCount);
        }
    }

    function syncNow() {
        if (busy || document.hidden) return;

        var now = new Date().getTime();
        var previousAttempt = parseInt(storageGet(localAttemptKey), 10) || 0;
        if (previousAttempt > 0 && now - previousAttempt < 55000) return;
        storageSet(localAttemptKey, now);

        busy = true;
        var body = 'action=automation_tick'
            + '&background=1'
            + '&response_type=json'
            + '&limit=20'
            + '&csrf_token=' + encodeURIComponent(csrfToken);

        window.fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body
        }).then(function (response) {
            return response.json();
        }).then(function (result) {
            handleSyncResult(result);
        }).catch(function () {
            /* 백그라운드 확인 오류는 업무화면을 방해하지 않습니다. */
        }).then(function () {
            busy = false;
        });
    }

    var menuLink = findMenuLink();
    if (menuLink) menuLink.addEventListener('click', clearBadge);
    renderBadge(parseInt(storageGet(localBadgeKey), 10) || 0);

    window.setTimeout(syncNow, 5000);
    window.setInterval(syncNow, intervalMs);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) syncNow();
    });
})();
</script>
<!-- CPMS_PUBLIC_MAIL_LIVE_SYNC_END -->
SIDEBAR;

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
            throw new RuntimeException('sidebar.php에서 메뉴 항목 추가 위치를 찾지 못했습니다. 최신 코드인지 확인하세요.');
        }
        $content = str_replace($itemAnchor, $itemBlock . $itemAnchor, $content);
    }

    if (strpos($content, 'CPMS_PUBLIC_MAIL_LIVE_SYNC_START') !== false) {
        $content = preg_replace('#<!-- CPMS_PUBLIC_MAIL_LIVE_SYNC_START -->.*?<!-- CPMS_PUBLIC_MAIL_LIVE_SYNC_END -->\n?#s', $liveSyncBlock . "\n", $content, 1);
    } else {
        if (strpos($content, $liveAnchor) === false) {
            throw new RuntimeException('sidebar.php에서 실시간 확인 코드 추가 위치를 찾지 못했습니다.');
        }
        $content = str_replace($liveAnchor, $liveSyncBlock . "\n\n" . $liveAnchor, $content);
    }

    if ($content === $originalContent) {
        return array('changed' => false, 'message' => '네이버 메일 메뉴와 1분 자동확인이 이미 최신 상태입니다.', 'backup' => '');
    }

    $backup = pm_install_backup($sidebarPath);
    if (@file_put_contents($sidebarPath, $content, LOCK_EX) === false) {
        throw new RuntimeException('sidebar.php 수정에 실패했습니다.');
    }

    return array('changed' => true, 'message' => '왼쪽 메뉴를 서버 내 네이버 대표 N 아이콘으로 변경하고 1분 자동확인을 적용했습니다.', 'backup' => $backup);
}

function pm_install_unpatch_sidebar($sidebarPath)
{
    $content = @file_get_contents($sidebarPath);
    if ($content === false) {
        throw new RuntimeException('sidebar.php 파일을 읽을 수 없습니다.');
    }

    if (strpos($content, 'CPMS_PUBLIC_MAIL_VARIABLE_START') === false && strpos($content, 'CPMS_PUBLIC_MAIL_ITEM_START') === false && strpos($content, 'CPMS_PUBLIC_MAIL_LIVE_SYNC_START') === false) {
        return array('changed' => false, 'message' => '제거할 네이버 메일 메뉴가 없습니다.', 'backup' => '');
    }

    $backup = pm_install_backup($sidebarPath);
    $content = preg_replace('#\n?/\* CPMS_PUBLIC_MAIL_VARIABLE_START \*/.*?/\* CPMS_PUBLIC_MAIL_VARIABLE_END \*/#s', '', $content);
    $content = preg_replace('#/\* CPMS_PUBLIC_MAIL_ITEM_START \*/.*?/\* CPMS_PUBLIC_MAIL_ITEM_END \*/\n?#s', '', $content);
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
    'app/services/GoogleDriveHelper.php',
    'app/services/PublicMailStorageService.php',
    'app/services/PublicMailImapClient.php',
    'app/services/PublicMailClassifierService.php',
    'app/services/PublicMailLargeAttachmentService.php',
    'app/services/PublicMailDriveService.php',
    'app/services/PublicMailService.php',
    'app/services/PublicMailWebHelper.php',
    'app/views/public_mail/index.php',
    'app/views/public_mail/settings.php',
    'public/public_mail.php',
    'public/public_mail_settings.php',
    'public/public_mail_action.php',
    'public/public_mail_attachment.php',
    'public/assets/css/public_mail.css',
    'public/assets/img/naver_n_icon.svg',
    'public/assets/js/public_mail.js',
    'public/cron/naver_mail_sync.php'
);

$checks = array();
$allReady = true;
foreach ($requiredFiles as $relativePath) {
    $exists = is_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
    $checks[] = array('path' => $relativePath, 'exists' => $exists);
    if (!$exists) {
        $allReady = false;
    }
}

$sidebarContent = is_file($sidebarPath) ? (string)@file_get_contents($sidebarPath) : '';
$installed = strpos($sidebarContent, 'CPMS_PUBLIC_MAIL_ITEM_START') !== false;
$latestInstalled = $installed && strpos($sidebarContent, "\$publicMailMenu = '네이버 메일';") !== false && strpos($sidebarContent, 'CPMS_PUBLIC_MAIL_LIVE_SYNC_START') !== false && strpos($sidebarContent, 'assets/img/naver_n_icon.svg') !== false;
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CPMS 네이버 메일 설치</title>
    <style>
        *{box-sizing:border-box}body{margin:0;padding:30px 16px;background:#f3f6f8;color:#172033;font-family:Arial,"Malgun Gothic",sans-serif}.wrap{max-width:900px;margin:0 auto}.card{background:#fff;border:1px solid #dfe6ee;border-radius:18px;padding:26px;box-shadow:0 12px 35px rgba(23,32,51,.08)}h1{margin:0 0 8px;font-size:28px}.sub{margin:0 0 22px;color:#667085}.alert{margin:15px 0;padding:13px 15px;border-radius:11px;font-weight:700}.success{background:#ecfdf3;color:#067647;border:1px solid #abefc6}.error{background:#fef3f2;color:#b42318;border:1px solid #fecdca}.status{display:flex;gap:10px;align-items:center;padding:13px;border-radius:12px;background:#f8fafc}.dot{width:12px;height:12px;border-radius:50%;background:#98a2b3}.dot.on{background:#12b76a}.files{margin:18px 0;border:1px solid #e4e9ef;border-radius:12px;overflow:hidden}.row{display:flex;justify-content:space-between;gap:12px;padding:10px 13px;border-bottom:1px solid #edf1f5;font-size:13px}.row:last-child{border-bottom:0}.ok{color:#067647;font-weight:800}.bad{color:#b42318;font-weight:800}.buttons{display:flex;gap:10px;flex-wrap:wrap}.btn{min-height:44px;padding:0 17px;border:0;border-radius:11px;font-weight:800;cursor:pointer}.primary{background:#0f766e;color:#fff}.danger{background:#fff1f0;color:#b42318;border:1px solid #fecdca}.link{display:inline-flex;align-items:center;min-height:44px;padding:0 17px;border-radius:11px;background:#eef4ff;color:#3538cd;text-decoration:none;font-weight:800}.note{margin-top:20px;padding-top:16px;border-top:1px solid #e9edf2;color:#b42318;font-weight:800}
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>CPMS 네이버 메일 설치</h1>
        <p class="sub">파일 확인 후 네이버 대표 N 아이콘, 보낸메일함, 대용량 첨부, 서버 무저장 다운로드와 Google Drive 저장 기능을 설치합니다. 24시간 자동수집은 연동 설정 화면의 웹 자동동기화 주소를 호스팅업체 예약작업에 등록합니다.</p>

        <?php if ($message !== ''): ?>
            <div class="alert <?php echo $messageType === 'error' ? 'error' : 'success'; ?>"><?php echo pm_install_h($message); ?><?php echo $backupPath !== '' ? '<br>백업: ' . pm_install_h($backupPath) : ''; ?></div>
        <?php endif; ?>

        <div class="status"><span class="dot <?php echo $installed ? 'on' : ''; ?>"></span><strong>메뉴 상태: <?php echo $latestInstalled ? '최신 버전' : ($installed ? '업데이트 필요' : '설치 전'); ?></strong></div>

        <div class="files">
            <?php foreach ($checks as $check): ?>
                <div class="row"><span><?php echo pm_install_h($check['path']); ?></span><span class="<?php echo $check['exists'] ? 'ok' : 'bad'; ?>"><?php echo $check['exists'] ? '확인' : '없음'; ?></span></div>
            <?php endforeach; ?>
        </div>

        <div class="buttons">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo pm_install_h(pm_install_csrf()); ?>">
                <input type="hidden" name="action" value="install">
                <button class="btn primary" type="submit" <?php echo !$allReady ? 'disabled' : ''; ?>>네이버 메일 설치·업데이트</button>
            </form>
            <form method="post" onsubmit="return confirm('네이버 메일 메뉴를 제거하시겠습니까? 데이터는 삭제하지 않습니다.');">
                <input type="hidden" name="csrf_token" value="<?php echo pm_install_h(pm_install_csrf()); ?>">
                <input type="hidden" name="action" value="uninstall">
                <button class="btn danger" type="submit">메뉴만 제거</button>
            </form>
            <?php if ($installed): ?><a class="link" href="public_mail_settings.php">연동 설정 열기</a><?php endif; ?>
        </div>

        <div class="note">설치가 끝나면 보안을 위해 public_mail_install.php 파일을 서버에서 삭제하세요.<br>24시간 자동수집 등록: 네이버 메일 → 연동 설정 → 웹 자동동기화 주소 복사</div>
    </div>
</div>
</body>
</html>
