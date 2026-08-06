<?php
/**
 * 파일 경로: C:\www\cpms\app\views\public_mail\index.php
 * 공용메일 목록 및 상세 화면입니다.
 */

use App\Services\PublicMailWebHelper;

$esc = array('App\\Services\\PublicMailWebHelper', 'h');
$items = isset($list['items']) && is_array($list['items']) ? $list['items'] : array();
$page = isset($list['page']) ? (int)$list['page'] : 1;
$pageCount = isset($list['page_count']) ? (int)$list['page_count'] : 1;
$total = isset($list['total']) ? (int)$list['total'] : 0;

$departmentOptions = array('공무', '공사', '안전/보건', '품질', '관리', '일반', '미분류');
$statusOptions = array('미확인', '확인', '담당자 지정', '처리중', '회신대기', '발송완료', '처리완료', '보류');
$priorityOptions = array('긴급', '높음', '보통', '낮음');
$mailboxOptions = array();
if (isset($syncState['mailboxes']) && is_array($syncState['mailboxes'])) {
    foreach ($syncState['mailboxes'] as $mailboxState) {
        if (!is_array($mailboxState) || empty($mailboxState['raw_name'])) continue;
        $mailboxOptions[] = array('raw_name'=>(string)$mailboxState['raw_name'], 'display_name'=>isset($mailboxState['display_name'])?(string)$mailboxState['display_name']:(string)$mailboxState['raw_name']);
    }
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
?>
<link rel="stylesheet" href="<?php echo call_user_func($esc, base_url()); ?>/assets/css/public_mail.css?v=20260806_73">

<div class="flex-1 min-w-0 overflow-auto bg-slate-50 public-mail-page" data-public-mail-page data-csrf-token="<?php echo call_user_func($esc, $csrfToken); ?>">
    <div class="public-mail-shell">
        <section class="public-mail-hero">
            <div>
                <div class="public-mail-eyebrow">COMPANY MAIL HUB</div>
                <h1>네이버 메일</h1>
                <p>네이버 원본메일을 보존하면서 현장·부서·담당자 기준으로 분류하고 처리합니다.</p>
            </div>
            <div class="public-mail-actions">
                <?php if (!empty($isMailAdmin)): ?>
                    <a class="pm-btn pm-btn-light" href="public_mail_settings.php">
                        <i data-lucide="settings"></i> 연동 설정
                    </a>
                <?php endif; ?>
                <a class="pm-btn pm-btn-gmail" href="<?php echo call_user_func($esc, $newMailUrl); ?>" target="_blank" rel="noopener noreferrer">
                    <i data-lucide="send"></i> Gmail로 새 메일
                </a>
                <button type="button" class="pm-btn pm-btn-primary" data-sync-mail="new">
                    <i data-lucide="refresh-cw"></i> 새 메일 확인
                </button>
            </div>
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
                <?php if (!empty($isMailAdmin)): ?>
                    <a href="public_mail_settings.php">관리자 설정에서 네이버 계정을 연결하세요.</a>
                <?php else: ?>
                    최고관리자에게 네이버 메일 연결을 요청하세요.
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <section class="pm-stat-grid">
            <a class="pm-stat-card" href="<?php echo call_user_func($esc, $buildUrl(array('quick' => ''))); ?>">
                <span>전체메일</span><strong><?php echo (int)$counts['all']; ?></strong><small>CPMS에 분류된 메일</small>
            </a>
            <a class="pm-stat-card" href="<?php echo call_user_func($esc, $buildUrl(array('quick' => 'unread'))); ?>">
                <span>네이버 미열람</span><strong><?php echo (int)$counts['unread']; ?></strong><small>동기화 시점 기준</small>
            </a>
            <a class="pm-stat-card pm-stat-danger" href="<?php echo call_user_func($esc, $buildUrl(array('quick' => 'urgent'))); ?>">
                <span>긴급</span><strong><?php echo (int)$counts['urgent']; ?></strong><small>즉시 확인 필요</small>
            </a>
            <a class="pm-stat-card pm-stat-warning" href="<?php echo call_user_func($esc, $buildUrl(array('quick' => 'unclassified'))); ?>">
                <span>미분류</span><strong><?php echo (int)$counts['unclassified']; ?></strong><small>사람 확인 필요</small>
            </a>
            <a class="pm-stat-card" href="<?php echo call_user_func($esc, $buildUrl(array('quick' => 'unassigned'))); ?>">
                <span>담당자 없음</span><strong><?php echo (int)$counts['unassigned']; ?></strong><small>업무 배정 필요</small>
            </a>
            <a class="pm-stat-card" href="<?php echo call_user_func($esc, $buildUrl(array('quick' => 'unfinished'))); ?>">
                <span>미처리</span><strong><?php echo (int)$counts['unfinished']; ?></strong><small>완료 전 메일</small>
            </a>
        </section>

        <nav class="pm-mailbox-tabs" aria-label="네이버 메일함 구분">
            <a class="<?php echo empty($filters['mailbox_type']) ? 'is-active' : ''; ?>" href="<?php echo call_user_func($esc,$buildUrl(array('mailbox_type'=>null,'mailbox'=>null,'page'=>1))); ?>"><i data-lucide="mails"></i> 전체메일</a>
            <a class="<?php echo isset($filters['mailbox_type'])&&$filters['mailbox_type']==='inbox' ? 'is-active' : ''; ?>" href="<?php echo call_user_func($esc,$buildUrl(array('mailbox_type'=>'inbox','mailbox'=>null,'page'=>1))); ?>"><i data-lucide="inbox"></i> 받은메일함</a>
            <a class="<?php echo isset($filters['mailbox_type'])&&$filters['mailbox_type']==='sent' ? 'is-active' : ''; ?>" href="<?php echo call_user_func($esc,$buildUrl(array('mailbox_type'=>'sent','mailbox'=>null,'page'=>1))); ?>"><i data-lucide="send"></i> 보낸메일함</a>
            <a class="<?php echo isset($filters['mailbox_type'])&&$filters['mailbox_type']==='custom' ? 'is-active' : ''; ?>" href="<?php echo call_user_func($esc,$buildUrl(array('mailbox_type'=>'custom','mailbox'=>null,'page'=>1))); ?>"><i data-lucide="folder"></i> 사용자 메일함</a>
        </nav>

        <section class="pm-filter-card">
            <form method="get" action="public_mail.php" class="pm-filter-form">
                <div class="pm-search-field">
                    <i data-lucide="search"></i>
                    <input type="text" name="query" value="<?php echo call_user_func($esc, $filters['query']); ?>" placeholder="메일 제목 또는 발신자 검색">
                </div>
                <select name="period" aria-label="조회 기간">
                    <option value="1m" <?php echo $filters['period'] === '1m' ? 'selected' : ''; ?>>최근 1개월</option>
                    <option value="3m" <?php echo $filters['period'] === '3m' ? 'selected' : ''; ?>>최근 3개월</option>
                    <option value="6m" <?php echo $filters['period'] === '6m' ? 'selected' : ''; ?>>최근 6개월</option>
                    <option value="1y" <?php echo $filters['period'] === '1y' ? 'selected' : ''; ?>>최근 1년</option>
                    <option value="all" <?php echo $filters['period'] === 'all' ? 'selected' : ''; ?>>전체 수집기간</option>
                </select>
                <select name="mailbox" aria-label="메일함">
                    <option value="">전체 메일함</option>
                    <?php foreach ($mailboxOptions as $mailboxOption): ?>
                        <option value="<?php echo call_user_func($esc, $mailboxOption['raw_name']); ?>" <?php echo isset($filters['mailbox']) && $filters['mailbox'] === $mailboxOption['raw_name'] ? 'selected' : ''; ?>><?php echo call_user_func($esc, $mailboxOption['display_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="department">
                    <option value="">전체 부서</option>
                    <?php foreach ($departmentOptions as $option): ?>
                        <option value="<?php echo call_user_func($esc, $option); ?>" <?php echo $filters['department'] === $option ? 'selected' : ''; ?>><?php echo call_user_func($esc, $option); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="project_id">
                    <option value="">전체 현장</option>
                    <?php foreach ($projects as $project): ?>
                        <option value="<?php echo call_user_func($esc, $project['id']); ?>" <?php echo $filters['project_id'] === (string)$project['id'] ? 'selected' : ''; ?>><?php echo call_user_func($esc, $project['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="status">
                    <option value="">전체 상태</option>
                    <?php foreach ($statusOptions as $option): ?>
                        <option value="<?php echo call_user_func($esc, $option); ?>" <?php echo $filters['status'] === $option ? 'selected' : ''; ?>><?php echo call_user_func($esc, $option); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="priority">
                    <option value="">전체 중요도</option>
                    <?php foreach ($priorityOptions as $option): ?>
                        <option value="<?php echo call_user_func($esc, $option); ?>" <?php echo $filters['priority'] === $option ? 'selected' : ''; ?>><?php echo call_user_func($esc, $option); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="assignee_id">
                    <option value="">전체 담당자</option>
                    <?php foreach ($employees as $employee): ?>
                        <option value="<?php echo call_user_func($esc, $employee['id']); ?>" <?php echo $filters['assignee_id'] === (string)$employee['id'] ? 'selected' : ''; ?>><?php echo call_user_func($esc, $employee['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="pm-btn pm-btn-primary" type="submit">검색</button>
                <a class="pm-btn pm-btn-light" href="public_mail.php">초기화</a>
            </form>
            <div class="pm-sync-summary">
                <span>마지막 성공: <?php echo call_user_func($esc, isset($syncState['last_success_at']) && $syncState['last_success_at'] !== '' ? $syncState['last_success_at'] : '아직 없음'); ?></span>
                <span>남은 초기수집: <?php echo isset($syncState['remaining_count']) ? (int)$syncState['remaining_count'] : 0; ?>건</span>
                <span>목록 결과: <?php echo $total; ?>건</span>
            </div>
        </section>

        <section class="pm-workspace <?php echo $detail ? 'has-detail' : ''; ?>">
            <div class="pm-list-panel">
                <div class="pm-list-head">
                    <div>메일 목록</div>
                    <small>원본 삭제·이동 없음</small>
                </div>

                <?php if (empty($items)): ?>
                    <div class="pm-empty">
                        <i data-lucide="inbox"></i>
                        <strong>표시할 메일이 없습니다.</strong>
                        <span>처음이라면 연동 설정에서 전체 메일 가져오기를 한 번 실행하세요.</span>
                    </div>
                <?php else: ?>
                    <div class="pm-mail-list">
                        <?php foreach ($items as $message): ?>
                            <?php
                            $classification = isset($message['classification']) && is_array($message['classification']) ? $message['classification'] : array();
                            $workflow = isset($message['workflow']) && is_array($message['workflow']) ? $message['workflow'] : array();
                            $department = !empty($workflow['department']) ? $workflow['department'] : (isset($classification['department']) ? $classification['department'] : '미분류');
                            $priority = !empty($workflow['priority']) ? $workflow['priority'] : (isset($classification['priority']) ? $classification['priority'] : '보통');
                            $projectName = !empty($workflow['project_name']) ? $workflow['project_name'] : (isset($classification['project_name']) ? $classification['project_name'] : '');
                            $rowUrl = $buildUrl(array('message' => $message['message_key']));
                            ?>
                            <a data-no-loading="1" data-mail-open data-message-key="<?php echo call_user_func($esc, $message['message_key']); ?>" class="pm-mail-row <?php echo (string)$selectedMessageKey === (string)$message['message_key'] ? 'is-selected' : ''; ?> <?php echo empty($message['is_seen']) ? 'is-unread' : ''; ?>" href="<?php echo call_user_func($esc, $rowUrl); ?>">
                                <div class="pm-mail-row-top">
                                    <span class="pm-badge pm-badge-<?php echo $priority === '긴급' ? 'danger' : ($priority === '높음' ? 'warning' : 'neutral'); ?>"><?php echo call_user_func($esc, $priority); ?></span>
                                    <span class="pm-department"><?php echo call_user_func($esc, $department); ?></span>
                                    <time><?php echo call_user_func($esc, substr($message['date_text'], 5, 11)); ?></time>
                                </div>
                                <div class="pm-subject"><?php echo call_user_func($esc, $message['subject']); ?></div>
                                <div class="pm-mail-meta">
                                    <span><?php echo call_user_func($esc, $message['from_text']); ?></span>
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
                        <a class="<?php echo $page <= 1 ? 'is-disabled' : ''; ?>" href="<?php echo call_user_func($esc, $buildUrl(array('page' => max(1, $page - 1)))); ?>">이전</a>
                        <span><?php echo $page; ?> / <?php echo $pageCount; ?></span>
                        <a class="<?php echo $page >= $pageCount ? 'is-disabled' : ''; ?>" href="<?php echo call_user_func($esc, $buildUrl(array('page' => min($pageCount, $page + 1)))); ?>">다음</a>
                    </div>
                <?php endif; ?>
            </div>

            <div class="pm-detail-host" data-mail-detail-host>
                <?php if ($detail): ?>
                    <?php include __DIR__ . '/detail_panel.php'; ?>
                <?php else: ?>
                    <div class="pm-detail-placeholder">
                        <i data-lucide="mail-open"></i>
                        <strong>메일을 선택하세요.</strong>
                        <span>본문, 첨부파일, 자동분류와 담당자 정보를 한 화면에서 확인할 수 있습니다.</span>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>

<iframe name="pmMailDownloadFrame" title="첨부파일 다운로드" class="pm-download-frame" aria-hidden="true"></iframe>
<script src="<?php echo call_user_func($esc, base_url()); ?>/assets/js/public_mail.js?v=20260806_73"></script>
