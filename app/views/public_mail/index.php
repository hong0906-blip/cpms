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
<link rel="stylesheet" href="<?php echo call_user_func($esc, base_url()); ?>/assets/css/public_mail.css?v=20260806_77">

<div class="flex-1 min-w-0 overflow-auto bg-slate-50 public-mail-page"
     data-public-mail-page
     data-csrf-token="<?php echo call_user_func($esc, $csrfToken); ?>"
     data-selected-message-key="<?php echo call_user_func($esc, $selectedMessageKey); ?>">
    <div class="public-mail-shell">
        <section class="public-mail-hero">
            <div>
                <div class="public-mail-eyebrow">COMPANY MAIL HUB</div>
                <h1>네이버 메일</h1>
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

        <section class="pm-filter-card pm-filter-card-simple">
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
                    <small>메일을 누르면 큰 화면으로 내용을 확인합니다.</small>
                </div>

                <?php if (empty($items)): ?>
                    <div class="pm-empty">
                        <i data-lucide="inbox"></i>
                        <strong>표시할 메일이 없습니다.</strong>
                        <span>검색어 또는 조회기간을 바꿔서 다시 확인하세요.</span>
                    </div>
                <?php else: ?>
                    <div class="pm-mail-list pm-mail-list-wide">
                        <?php foreach ($items as $message): ?>
                            <?php
                            $classification = isset($message['classification']) && is_array($message['classification']) ? $message['classification'] : array();
                            $workflow = isset($message['workflow']) && is_array($message['workflow']) ? $message['workflow'] : array();
                            $department = !empty($workflow['department']) ? $workflow['department'] : (isset($classification['department']) ? $classification['department'] : '미분류');
                            $priority = !empty($workflow['priority']) ? $workflow['priority'] : (isset($classification['priority']) ? $classification['priority'] : '보통');
                            $projectName = !empty($workflow['project_name']) ? $workflow['project_name'] : (isset($classification['project_name']) ? $classification['project_name'] : '');
                            $messageMailboxType = isset($message['mailbox_type']) ? (string)$message['mailbox_type'] : '';
                            $addressText = $messageMailboxType === 'sent'
                                ? (isset($message['to_text']) && trim((string)$message['to_text']) !== '' ? (string)$message['to_text'] : '수신자 정보 없음')
                                : (isset($message['from_text']) ? (string)$message['from_text'] : '발신자 정보 없음');
                            $addressLabel = $messageMailboxType === 'sent' ? '받는 사람' : '보낸 사람';
                            $rowUrl = $buildUrl(array('message' => $message['message_key']));
                            ?>
                            <a data-no-loading="1"
                               data-mail-open
                               data-message-key="<?php echo call_user_func($esc, $message['message_key']); ?>"
                               class="pm-mail-row pm-mail-row-wide <?php echo (string)$selectedMessageKey === (string)$message['message_key'] ? 'is-selected' : ''; ?> <?php echo empty($message['is_seen']) ? 'is-unread' : ''; ?>"
                               href="<?php echo call_user_func($esc, $rowUrl); ?>">
                                <div class="pm-mail-row-top">
                                    <span class="pm-badge pm-badge-<?php echo $priority === '긴급' ? 'danger' : ($priority === '높음' ? 'warning' : 'neutral'); ?>"><?php echo call_user_func($esc, $priority); ?></span>
                                    <span class="pm-department"><?php echo call_user_func($esc, $department); ?></span>
                                    <time><?php echo call_user_func($esc, substr(isset($message['date_text']) ? $message['date_text'] : '', 5, 11)); ?></time>
                                </div>
                                <div class="pm-subject"><?php echo call_user_func($esc, isset($message['subject']) ? $message['subject'] : '(제목 없음)'); ?></div>
                                <div class="pm-mail-meta">
                                    <span class="pm-address-label"><?php echo call_user_func($esc, $addressLabel); ?></span>
                                    <span><?php echo call_user_func($esc, $addressText); ?></span>
                                    <span class="pm-mailbox-chip"><?php echo call_user_func($esc, isset($message['mailbox_name']) ? $message['mailbox_name'] : '받은메일함'); ?></span>
                                    <?php if ($projectName !== ''): ?><span class="pm-project-chip"><?php echo call_user_func($esc, $projectName); ?></span><?php endif; ?>
                                </div>
                                <div class="pm-preview">
                                    <?php echo call_user_func($esc, !empty($message['preview']) ? $message['preview'] : '본문 미리보기를 준비 중입니다.'); ?>
                                </div>
                                <div class="pm-mail-status">
                                    <span><?php echo call_user_func($esc, isset($workflow['status']) ? $workflow['status'] : '미확인'); ?></span>
                                    <span><?php echo call_user_func($esc, !empty($workflow['assignee_name']) ? $workflow['assignee_name'] : '담당자 없음'); ?></span>
                                </div>
                            </a>
                        <?php endforeach; ?>
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

<iframe name="pmMailDownloadFrame" title="첨부파일 다운로드" class="pm-download-frame" aria-hidden="true"></iframe>
<script src="<?php echo call_user_func($esc, base_url()); ?>/assets/js/public_mail.js?v=20260806_77"></script>
