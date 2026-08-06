<?php
/**
 * 파일 경로: C:\www\cpms\app\views\public_mail\index.php
 * 네이버 메일 목록 화면입니다. 메일 상세는 큰 모달로 비동기 표시합니다.
 */

use App\Services\PublicMailWebHelper;

$esc = array('App\\Services\\PublicMailWebHelper', 'h');
$items = isset($list['items']) && is_array($list['items']) ? $list['items'] : array();
$page = isset($list['page']) ? (int)$list['page'] : 1;
$pageCount = isset($list['page_count']) ? (int)$list['page_count'] : 1;
$total = isset($list['total']) ? (int)$list['total'] : 0;
$mailboxType = isset($filters['mailbox_type']) ? (string)$filters['mailbox_type'] : '';
$liveState = isset($list['live_state']) && is_array($list['live_state']) ? $list['live_state'] : array();
$liveRevision = isset($liveState['revision']) ? (string)$liveState['revision'] : '';
$liveHeadKeys = isset($liveState['head_keys']) && is_array($liveState['head_keys']) ? $liveState['head_keys'] : array();
$liveLatestTimestamp = isset($liveState['latest_timestamp']) ? (int)$liveState['latest_timestamp'] : 0;
$liveHeadJson = json_encode(array_values($liveHeadKeys));
if ($liveHeadJson === false) $liveHeadJson = '[]';

$scopeTitle = '전체메일';
$searchPlaceholder = '전체메일에서 제목 또는 발신자 검색';
if ($mailboxType === 'inbox') {
    $scopeTitle = '받은메일함';
    $searchPlaceholder = '받은메일함에서 제목 또는 발신자 검색';
} elseif ($mailboxType === 'sent') {
    $scopeTitle = '보낸메일함';
    $searchPlaceholder = '보낸메일함에서 제목 또는 수신자 검색';
}

$buildUrl = function ($changes) use ($filters, $page) {
    $query = array_merge($filters, array('page' => $page));
    foreach ($changes as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }
    return 'public_mail.php?' . http_build_query($query, '', '&');
};

$resetQuery = array('page'=>1);
if ($mailboxType !== '') {
    $resetQuery['mailbox_type'] = $mailboxType;
}
$resetUrl = 'public_mail.php' . (!empty($resetQuery) ? '?' . http_build_query($resetQuery, '', '&') : '');
?>
<link rel="stylesheet" href="<?php echo call_user_func($esc, base_url()); ?>/assets/css/public_mail.css?v=20260806_7192">

<div class="flex-1 min-w-0 overflow-auto bg-slate-50 public-mail-page"
     data-public-mail-page
     data-live-mail="1"
     data-live-revision="<?php echo call_user_func($esc, $liveRevision); ?>"
     data-live-head-keys="<?php echo call_user_func($esc, $liveHeadJson); ?>"
     data-live-latest-timestamp="<?php echo (int)$liveLatestTimestamp; ?>"
     data-live-page="<?php echo (int)$page; ?>"
     data-live-query="<?php echo call_user_func($esc, isset($filters['query']) ? $filters['query'] : ''); ?>"
     data-live-period="<?php echo call_user_func($esc, isset($filters['period']) ? $filters['period'] : '1y'); ?>"
     data-live-mailbox-type="<?php echo call_user_func($esc, $mailboxType); ?>"
     data-live-per-page="<?php echo isset($list['per_page']) ? (int)$list['per_page'] : 30; ?>"
     data-csrf-token="<?php echo call_user_func($esc, $csrfToken); ?>"
     data-selected-message-key="<?php echo call_user_func($esc, $selectedMessageKey); ?>">
    <div class="public-mail-shell">
        <section class="public-mail-hero">
            <div>
                <div class="public-mail-eyebrow">COMPANY MAIL HUB</div>
                <div class="pm-mobile-title-row">
                    <h1>네이버 메일</h1>
                    <button type="button" class="pm-mobile-search-toggle" data-mobile-search-toggle aria-expanded="<?php echo !empty($filters['query']) ? 'true' : 'false'; ?>" aria-controls="pmMobileSearchPanel" aria-label="메일 검색 열기">
                        <i data-lucide="search"></i><span>검색</span>
                    </button>
                </div>
                <p>전체메일·받은메일함·보낸메일함에서 필요한 메일을 빠르게 검색하고 확인합니다.</p>
            </div>
            <?php if (!empty($canManageMailSettings)): ?>
                <div class="public-mail-actions">
                    <a class="pm-btn pm-btn-light" href="public_mail_settings.php">
                        <i data-lucide="settings"></i> 연동 설정
                    </a>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($flash && isset($flash['message'])): ?>
            <div class="pm-alert <?php echo isset($flash['type']) && $flash['type'] === 'error' ? 'pm-alert-error' : 'pm-alert-success'; ?>">
                <?php echo call_user_func($esc, $flash['message']); ?>
            </div>
        <?php endif; ?>

        <?php if ($errorMessage !== ''): ?>
            <div class="pm-alert pm-alert-error"><?php echo call_user_func($esc, $errorMessage); ?></div>
        <?php endif; ?>

        <?php if (empty($settings['enabled'])): ?>
            <div class="pm-alert pm-alert-warning">
                네이버 메일 연동이 아직 켜져 있지 않습니다.
                <?php if (!empty($canManageMailSettings)): ?>
                    <a href="public_mail_settings.php">연동 설정에서 네이버 계정을 연결하세요.</a>
                <?php else: ?>
                    개발부서에 네이버 메일 연결을 요청하세요.
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <nav class="pm-mailbox-tabs" aria-label="네이버 메일 검색 범위">
            <a class="<?php echo $mailboxType === '' ? 'is-active' : ''; ?>"
               href="<?php echo call_user_func($esc, $buildUrl(array('mailbox_type'=>null, 'page'=>1))); ?>">
                <i data-lucide="mails"></i> 전체메일
            </a>
            <a class="<?php echo $mailboxType === 'inbox' ? 'is-active' : ''; ?>"
               href="<?php echo call_user_func($esc, $buildUrl(array('mailbox_type'=>'inbox', 'page'=>1))); ?>">
                <i data-lucide="inbox"></i> 받은메일함
            </a>
            <a class="<?php echo $mailboxType === 'sent' ? 'is-active' : ''; ?>"
               href="<?php echo call_user_func($esc, $buildUrl(array('mailbox_type'=>'sent', 'page'=>1))); ?>">
                <i data-lucide="send"></i> 보낸메일함
            </a>
        </nav>

        <section id="pmMobileSearchPanel" class="pm-filter-card pm-filter-card-simple<?php echo !empty($filters['query']) ? ' is-mobile-open' : ''; ?>" data-mobile-search-panel>
            <form method="get" action="public_mail.php" class="pm-filter-form pm-filter-form-simple">
                <?php if ($mailboxType !== ''): ?>
                    <input type="hidden" name="mailbox_type" value="<?php echo call_user_func($esc, $mailboxType); ?>">
                <?php endif; ?>
                <div class="pm-search-field">
                    <i data-lucide="search"></i>
                    <input type="text"
                           name="query"
                           value="<?php echo call_user_func($esc, isset($filters['query']) ? $filters['query'] : ''); ?>"
                           placeholder="<?php echo call_user_func($esc, $searchPlaceholder); ?>"
                           aria-label="<?php echo call_user_func($esc, $searchPlaceholder); ?>">
                </div>
                <select name="period" aria-label="조회 기간">
                    <option value="1m" <?php echo isset($filters['period']) && $filters['period'] === '1m' ? 'selected' : ''; ?>>최근 1개월</option>
                    <option value="3m" <?php echo isset($filters['period']) && $filters['period'] === '3m' ? 'selected' : ''; ?>>최근 3개월</option>
                    <option value="6m" <?php echo isset($filters['period']) && $filters['period'] === '6m' ? 'selected' : ''; ?>>최근 6개월</option>
                    <option value="1y" <?php echo !isset($filters['period']) || $filters['period'] === '1y' ? 'selected' : ''; ?>>최근 1년</option>
                    <option value="all" <?php echo isset($filters['period']) && $filters['period'] === 'all' ? 'selected' : ''; ?>>전체 수집기간</option>
                </select>
                <button class="pm-btn pm-btn-primary" type="submit"><i data-lucide="search"></i> 검색</button>
                <a class="pm-btn pm-btn-light" href="<?php echo call_user_func($esc, $resetUrl); ?>">초기화</a>
            </form>
            <div class="pm-sync-summary pm-search-scope-summary">
                <span><strong>검색 범위:</strong> <?php echo call_user_func($esc, $scopeTitle); ?></span>
            </div>
        </section>

        <section class="pm-workspace pm-workspace-list-only">
            <div class="pm-list-panel pm-list-panel-wide">
                <div class="pm-list-head">
                    <div><?php echo call_user_func($esc, $scopeTitle); ?> 메일 목록</div>
                    <small><span class="pm-live-indicator"><i></i> 새 메일 자동표시 중</span><span class="pm-live-head-help">메일을 누르면 큰 화면으로 내용을 확인합니다.</span></small>
                </div>

                <?php if (empty($items)): ?>
                    <div class="pm-empty" data-live-empty>
                        <i data-lucide="inbox"></i>
                        <strong>표시할 메일이 없습니다.</strong>
                        <span>검색어 또는 조회기간을 바꿔서 다시 확인하세요.</span>
                    </div>
                    <div class="pm-mail-list pm-mail-list-wide" data-live-mail-list></div>
                <?php else: ?>
                    <div class="pm-mail-list pm-mail-list-wide" data-live-mail-list>
                        <?php include __DIR__ . '/_mail_rows.php'; ?>
                    </div>
                <?php endif; ?>

                <?php if ($pageCount > 1): ?>
                    <div class="pm-pagination">
                        <a class="<?php echo $page <= 1 ? 'is-disabled' : ''; ?>" href="<?php echo call_user_func($esc, $buildUrl(array('page' => max(1, $page - 1), 'message'=>null))); ?>">이전</a>
                        <span><?php echo $page; ?> / <?php echo $pageCount; ?></span>
                        <a class="<?php echo $page >= $pageCount ? 'is-disabled' : ''; ?>" href="<?php echo call_user_func($esc, $buildUrl(array('page' => min($pageCount, $page + 1), 'message'=>null))); ?>">다음</a>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <div class="pm-mail-reader-modal" data-mail-reader-modal hidden>
        <div class="pm-mail-reader-backdrop" data-mail-reader-close></div>
        <section class="pm-mail-reader-dialog" role="dialog" aria-modal="true" aria-label="메일 상세내용">
            <div class="pm-mail-reader-host" data-mail-detail-host>
                <div class="pm-detail-panel pm-detail-panel-loading">
                    <div class="pm-detail-local-loading">
                        <div class="pm-spinner"></div>
                        <strong>메일 정보를 여는 중입니다.</strong>
                        <span>메일 목록은 그대로 유지됩니다.</span>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>

<script src="<?php echo call_user_func($esc, base_url()); ?>/assets/js/public_mail.js?v=20260806_7192"></script>
